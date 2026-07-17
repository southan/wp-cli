<?php

namespace WP_CLI;

use WP_CLI;
use WP_CLI\Iterators\Exception;

/**
 * Remote control for WordPress.
 */
abstract class Remote extends Commander {

	/**
	 * Get remote control for a WP-CLI alias, path, or SSH connection.
	 */
	public static function create( ?string $name = null, array | Commander | null $defaults = null ) : self {
		$name = (string) $name;

		if ( ! \strlen( $name ) ) {
			$args = [];

		} elseif ( \preg_match( '#' . Configurator::ALIAS_REGEX . '#', $name ) ) {
			$args = WP_CLI::get_runner()->aliases[ $name ] ?? null;

			if ( ! \is_array( $args ) ) {
				throw new Exception( "Alias '$name' not found." );
			}

			if ( isset( $args[0] ) ) {
				throw new Exception( "Alias '$name' is a group." );
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
			// Accept user@host:path syntax without path needing to be absolute (WP-CLI only matches /)
			if ( \preg_match( '/:([^\d:][^:]*)/', $ssh, $matches ) ) {
				$ssh = \str_replace( $matches[0], '', $ssh );

				$args['path'] = $matches[1];
			}

			$args += Utils\parse_ssh_url( $ssh );

			$args['scheme'] ??= 'ssh';
		}

		$scheme = $args['scheme'] ?? 'local';

		$class = self::class . '\\' . \ucwords( \strtolower( \str_replace( '-', '_', $scheme ) ), '_' );

		if ( ! \class_exists( $class ) || ! \is_subclass_of( $class, self::class ) ) {
			throw new Exception( "Unsupported scheme '$scheme'." );
		}

		$args['name'] = $name;

		if ( $defaults instanceof Commander ) {
			$defaults = \get_object_vars( $defaults );
		}

		if ( \is_array( $defaults ) ) {
			$args += $defaults;
		}

		$props = [];

		$class = new \ReflectionClass( $class );

		foreach ( $class->getConstructor()->getParameters() as $param ) {
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

	public function __construct(
		public ?string $name = null,
		public ?string $path = null,
		public ?string $home = null,
		public ?string $url = null,
		public int     $mode = Shell::THROW,
		public ?string $debug = null,
		public ?array  $wp_config = null
	) {
		$this->wp_config ??= $this->is_self() ? null : \array_filter([
			'path' => $this->path,
			'url' => $this->url,
		]);
	}

	public function __toString() : string {
		return "$this->name";
	}

	/**
	 * Is this WordPress on the local filesystem?
	 */
	public function is_local() : bool {
		return false;
	}

	/**
	 * Is this a remote for the current WordPress context?
	 */
	public function is_self() : bool {
		return false;
	}

	/**
	 * Get the home directory of the WordPress system.
	 */
	public function home() : ?string {
		return $this->home ??= $this->get_home();
	}

	/**
	 * Get WordPress path.
	 */
	public function path() : ?string {
		return $this->path ??= $this->get_path();
	}

	/**
	 * Get WordPress URL.
	 */
	public function url() : ?string {
		return $this->url ??= $this->get_url();
	}

	/**
	 * Run arbitrary PHP code.
	 */
	public function eval( string $code, array $options = [] ) : Shell {
		return $this->wp( 'eval', $code, $options );
	}

	/**
	 * Get WordPress option value.
	 */
	public function get_option( string $name ) : mixed {
		return $this->wp( 'option get', $name, [ 'format' => 'json' ] )->parse_json();
	}

	/**
	 * Get WordPress constant value.
	 */
	public function get_const( string $name ) : mixed {
		$code = \sprintf(
			'echo json_encode( defined( %1$s ) ? constant( %1$s ) : null );',
			\var_export( $name, true )
		);

		return $this->eval( $code )->parse_json();
	}

	public function get_url() : ?string {
		$url = $this->get_option( 'home' );
		if ( ! \is_string( $url ) ) {
			$url = null;
		}
		return $url;
	}

	public function get_home() : ?string {
		return $this->eval( 'echo WP_CLI\Utils\get_home_dir();', [ 'skip-wordpress' => true ] )->get();
	}

	public function get_path() : ?string {
		return $this->eval( 'echo realpath( ABSPATH );', [ 'skip-wordpress' => true ] )->get();
	}

	public function get_content_dir() : ?string {
		return $this->eval( 'echo realpath( WP_CONTENT_DIR );' )->get();
	}

	public function get_plugins_dir() : ?string {
		return $this->eval( 'echo realpath( WP_PLUGIN_DIR );' )->get();
	}

	public function get_themes_dir() : ?string {
		return $this->eval( 'echo realpath( WP_CONTENT_DIR . "/themes" );' )->get();
	}

	public function get_uploads_dir() : ?string {
		return $this->eval( 'echo realpath( wp_upload_dir()["basedir"] );' )->get();
	}

	public function get_mu_plugins_dir() : ?string {
		return $this->eval( 'echo realpath( WPMU_PLUGIN_DIR );' )->get();
	}

	public function get_uri( string $path ) : string {
		return $path;
	}

	public function parse_uri( string $uri ) : string {
		if ( \str_starts_with( $uri, "$this:" ) ) {
			$uri = $this->get_uri( \substr( $uri, \strlen( "$this:" ) ) );
		}

		return $uri;
	}

	public function rsync( string $src, string $dest, array $options = [] ) : Shell {
		return parent::rsync(
			$this->parse_uri( $src ),
			$this->parse_uri( $dest ),
			$options
		);
	}

	public function rsh() : Shell {
		throw new Exception( "$this does not support remote shell." );
	}

	abstract public function copy( string $src, string $dest ) : bool;

	abstract public function file_put_contents( string $filename, mixed $data ) : bool;

	abstract public function unlink( string $filename ) : bool;

	abstract public function mkdir( string $pathname ) : bool;

	abstract public function is_dir( string $filename ) : bool;

	abstract public function is_file( string $filename ) : bool;
}
