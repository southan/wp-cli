<?php

namespace WP_CLI;

use WP_CLI;

/**
 * Remote control for WordPress.
 */
abstract class Remote extends Commander {

	protected array $cache = [];

	public function __construct(
		public ?string $name = null,
		public ?string $path = null,
		public ?string $url = null,
		public ?string $debug = null,
		public ?array  $wp = null
	) {}

	public function __toString() : string {
		return $this->name ?? $this->path ?? 'this WordPress';
	}

	/**
	 * Get remote control for a WP-CLI alias, path, or SSH connection.
	 */
	public static function create( ?string $name = null, array | Commander | null $defaults = null ) : self {
		if ( $name === null || ! \strlen( $name ) ) {
			$args = [];

		} elseif ( \preg_match( '#' . Configurator::ALIAS_REGEX . '#', $name ) ) {
			$args = WP_CLI::get_runner()->aliases[ $name ] ?? null;

			if ( ! \is_array( $args ) ) {
				throw new Exception( "Alias '$name' not found." );
			}

			if ( isset( $args[0] ) ) {
				throw new Exception( "Alias '$name' is a group." );
			}

		} elseif ( \str_contains( $name, '://' ) ) {
			list ( $scheme, $path ) = \array_pad( \explode( '://', $name, 2 ), 2, null );

			$args = [
				'scheme' => $scheme,
			];

			if ( $scheme === 'local' ) {
				$args['path'] = $path;
			} else {
				$args['ssh'] = "$scheme:$path";
			}

		} elseif ( \is_dir( $name ) || \str_starts_with( $name, '.' ) || Utils\is_path_absolute( $name ) ) {
			$args = [
				'path' => $name,
			];

		} else {
			$args = [
				'ssh' => $name,
			];
		}

		$ssh = $args['ssh'] ?? null;

		if ( \is_string( $ssh ) ) {
			// Accept colon separator for passing relative path
			if ( \preg_match( '/:([^:]+)$/', $ssh, $matches ) && ( ! \ctype_digit( $matches[1] ) || \preg_match( '/:\d+:[^:]+$/', $ssh ) ) ) {
				$ssh = \substr( $ssh, 0, -\strlen( $matches[0] ) );

				$args['path'] = $matches[1];
			}

			/** @var array */
			$ssh = Utils\parse_ssh_url( $ssh );

			$args += $ssh;

			$args['scheme'] ??= 'ssh';
		}

		$scheme = $args['scheme'] ?? 'local';

		$class = self::class . '\\' . \ucwords( \str_replace( '-', '_', $scheme ), '_' );

		if ( ! \class_exists( $class ) || ! \is_subclass_of( $class, self::class ) ) {
			throw new Exception( "Unsupported scheme '$scheme'." );
		}

		if ( $defaults instanceof Commander ) {
			$defaults = [ 'debug' => $defaults->debug ];
		}

		if ( \is_array( $defaults ) ) {
			$args += $defaults;
		}

		$props = [];

		$class = new \ReflectionClass( $class );

		$args['name'] = $name;

		foreach ( $class->getConstructor()?->getParameters() ?? [] as $param ) {
			if ( isset( $args[ $param->name ] ) ) {
				$props[ $param->name ] = $args[ $param->name ];
			} elseif ( ! $param->isOptional() ) {
				throw new Exception( "$$param->name is required." );
			}
		}

		return $class->newInstance( ...$props );
	}

	/**
	 * Get remote controls for WP-CLI alias groups, aliases, paths, or SSH connections.
	 *
	 * @return Remote[]
	 */
	public static function resolve( string | array | null $name, array | Commander | null $defaults = null ) : array {
		$aliases = WP_CLI::get_runner()->aliases;

		unset( $aliases['@all'] );

		$aliases['@all'] = \array_keys( $aliases );

		$resolve = static function ( $name ) use ( $aliases, &$resolve ) : array {
			if ( ! \is_array( $name ) ) {
				$alias = $aliases[ $name ] ?? null;

				$is_alias_group = \is_array( $alias ) && isset( $alias[0] );

				if ( ! $is_alias_group ) {
					return [ $name ];
				}

				$name = $alias;
			}

			return \array_merge( ...\array_map( $resolve, \array_values( $name ) ) );
		};

		$names = \array_values( \array_unique( $resolve( $name ) ) );

		$remotes = \array_map( static fn ( $name ) => self::create( $name, $defaults ), $names );

		return $remotes;
	}

	/**
	 * Check if another remote resolves to the same as this.
	 */
	public function is( Remote $remote ) : bool {
		return $this === $remote || $this->uri() === $remote->uri();
	}

	/**
	 * Get WP-CLI runtime arguments for this remote.
	 */
	public function get_wp_args() : array {
		return $this->wp ?? \array_filter([
			'path' => $this->path,
			'url' => $this->url,
		]);
	}

	public function get_option( string $name ) : mixed {
		return $this->wp( 'option get', $name, [ 'format' => 'json' ] )->parse_json();
	}

	public function get_const( string $name ) : mixed {
		$code = \sprintf(
			'echo json_encode( defined( %1$s ) ? constant( %1$s ) : null );',
			\var_export( $name, true )
		);

		return $this->eval( $code )->parse_json();
	}

	public function get_path() : string {
		return $this->cache['path'] ??= (string) $this->eval( 'echo realpath( ABSPATH );', [ 'skip-wordpress' => true ] )->get();
	}

	public function get_url() : string {
		return $this->cache['url'] ??= (string) $this->eval( 'echo home_url();' )->get();
	}

	public function get_content_dir() : string {
		return $this->cache['content_dir'] ??= (string) $this->eval( 'echo realpath( WP_CONTENT_DIR ) ?: WP_CONTENT_DIR;' )->get();
	}

	public function get_plugins_dir() : string {
		return $this->cache['plugins_dir'] ??= (string) $this->eval( 'echo realpath( WP_PLUGIN_DIR ) ?: WP_PLUGIN_DIR;' )->get();
	}

	public function get_themes_dir() : string {
		return $this->cache['themes_dir'] ??= (string) $this->eval( 'echo realpath( $dir = WP_CONTENT_DIR . "/themes" ) ?: $dir;' )->get();
	}

	public function get_uploads_dir() : string {
		return $this->cache['uploads_dir'] ??= (string) $this->eval( 'echo realpath( $dir = wp_upload_dir()["basedir"] ) ?: $dir;' )->get();
	}

	public function get_mu_plugins_dir() : string {
		return $this->cache['mu_plugins_dir'] ??= (string) $this->eval( 'echo realpath( WPMU_PLUGIN_DIR ) ?: WPMU_PLUGIN_DIR;' )->get();
	}

	public function exists() : bool {
		return $this->cache['exists'] ??= $this->is_file( Utils\path_join( $this->get_path(), 'wp-load.php' ) );
	}

	public function eval( string $code, array $options = [] ) : Shell {
		return $this->wp( 'eval', $code, $options );
	}

	public function locate( ?string $path = null ) : Location {
		return new Location( $this, $path );
	}

	public function rsh() : Shell {
		throw new Exception( "$this does not support remote shell." );
	}

	public function move( string $from, string | Location | Remote $to, array $options = [] ) : Location {
		$options['delete'] = true;

		return $this->copy( $from, $to, $options );
	}

	public function move_from( string | Location $from, ?string $to = null, array $options = [] ) : Location {
		$options['delete'] = true;

		return $this->copy_from( $from, $to, $options );
	}

	public function copy_to( string $from, string | Location $to, array $options = [] ) : Location {
		return $this->copy( $from, Location::from( $to ), $options );
	}

	public function move_to( string $from, string | Location $to, array $options = [] ) : Location {
		return $this->move( $from, Location::from( $to ), $options );
	}

	abstract public function uri( ?string $path = null ) : string;

	abstract public function copy( string $from, string | Location | Remote $to, array $options = [] ) : Location;

	abstract public function copy_from( string | Location $from, ?string $to = null, array $options = [] ) : Location;

	abstract public function file_put_contents( string $filename, mixed $data ) : bool;

	abstract public function file_exists( string $filename ) : bool;

	abstract public function is_file( string $filename ) : bool;

	abstract public function is_dir( string $filename ) : bool;

	abstract public function mkdir( string $pathname ) : bool;

	abstract public function rmdir( string $pathname ) : bool;

	abstract public function unlink( string $filename ) : bool;
}
