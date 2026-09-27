<?php

namespace WP_CLI\Remote;

use WP_CLI\Exception;
use WP_CLI\Location;
use WP_CLI\Remote;
use WP_CLI\Shell;
use WP_CLI\Utils;

class Ssh extends Remote {

	public function __construct(
		public string  $host,
		public ?string $user = null,
		public ?int    $port = null,
		public ?string $key = null,
		public ?string $proxyjump = null,
		public ?string $socket = null,
		public ?string $name = null,
		public ?string $path = null,
		public ?string $url = null,
		public ?string $debug = null,
		public ?array  $wp = null
	) {}

	public function __toString() : string {
		return $this->name ?? $this->destination();
	}

	public function destination() : string {
		return $this->user ? "$this->user@$this->host" : $this->host;
	}

	public function uri( ?string $path = null ) : string {
		$uri = 'ssh://' . $this->destination();

		$path ??= $this->path;

		if ( $path !== null ) {
			$uri .= "/$path";
		}

		return $uri;
	}

	public function get_rsh() : Shell {
		return parent::sh( 'ssh', [
			'-p' => $this->port,
			'-i' => $this->key,
			'-J' => $this->proxyjump,
			'-S' => $this->socket, // Control socket for connection sharing - see sh()
		]);
	}

	public function rsh() : Shell {
		return $this->get_rsh()
		->add([ '-t' => true ])
		->add( $this->destination() )
		->add( \sprintf( 'PS1="\[\e[32m\]%s\[\e[0m\]:\[\e[34m\]\w\[\e[0m\]\$ " bash --noprofile --norc -i', "$this" ) );
	}

	public function sh( string $cmd, mixed ...$args ) : Shell {
		$cmd .= Utils\args_to_cmd( $args, negate: false );

		if ( ! $this->socket ) {
			$this->socket = Utils\get_home_dir() . '/.ssh/wp-cli-' . $this->destination();
		}

		if ( ! \file_exists( $this->socket ) ) {
			$socket_dir = \dirname( $this->socket );
			if ( ! \is_dir( $socket_dir ) && ! \mkdir( $socket_dir, recursive: true ) ) {
				$this->socket = null;
			} else {
				$this->get_rsh()
				->add([
					'-M' => true, // Places the ssh client into "master" mode for connection sharing
					'-N' => true, // Do not execute a remote command.
					'-f' => true, // Requests ssh to go to background just before command execution
					'-o' => 'ControlPersist=30', // Set connection sharing timeout
				])
				->add( $this->destination() )
				->run()
				->check();
			}
 		}

 		return $this->get_rsh()->add( $this->destination(), $cmd );
	}

	public function wp( string $cmd, mixed ...$args ) : Shell {
		$wp = \getenv( 'WP_CLI_SSH_BINARY' ) ?: 'wp';

		$wp_args = $this->get_wp_args();

		if ( isset( $wp_args['path'] ) && \str_starts_with( $wp_args['path'], '~' ) ) {
			$wp_args['path'] = \ltrim( $wp_args['path'], '~/\\' );
		}

		$args = \array_merge( $wp_args, $args );

		$cmd .= Utils\args_to_cmd( $args );

		return $this->sh( "$wp $cmd" );
	}

	public function copy( string $from, string | Location | Remote $to, array $options = [] ) : Location {
		$from = $this->locate( $from );

		$to = Location::from( $to, $this );

		return $this->copy_from_to( $from, $to, $options );
	}

	public function copy_from( string | Location $from, ?string $to = null, array $options = [] ) : Location {
		$from = Location::from( $from );

		$to = $this->locate( $to );

		return $this->copy_from_to( $from, $to, $options );
	}

	public function copy_from_to( Location $from, Location $to, array $options = [] ) : Location {
		$is_from = $from->is( $this );
		$is_to = $to->is( $this );

		if ( ! $is_from && ! $is_to ) {
			return $from->copy_to( $to, $options );
		}

		if ( ! \strlen( "$from->path$to->path" ) ) {
			throw new Exception( 'Source or destination path required.' );
		}

		$is_self = $is_from && $is_to;

		$from->path ??= ( $is_self ? "$to->path" : \basename( "$to->path" ) );
		$to->path ??= ( $is_self ? "$from->path" : \basename( "$from->path" ) );

		if ( $from->is( $to ) ) {
			return $to;
		}

		$delete = ! empty( $options['delete'] );

		$exclude = Utils\parse_list( $options['exclude'] ?? [], false );

		if ( $is_self && ! $exclude ) {
			$this->sh( 'cp -R', $from->path, $to->path )->check();

			if ( $delete ) {
				$this->sh( 'rm -R', $from->path )->check();
			}

			return $to;
		}

		if ( ! $is_to && ! $to->is_local() ) {
			return $from
				->copy_to( $from->is_dir() ? Utils\temp_dir() : Utils\temp_file(), $options )
				->copy_to( $to );
		}

		$from_path = Utils\canonical_path( $from->path, false );

		$exclude = \array_map(
			static function ( string $path ) use ( $from_path ) {
				$path = Utils\normalize_path( $path );

				if ( Utils\is_path_absolute( $path ) && \str_starts_with( $path, "$from_path/" ) ) {
					$path = \substr( $path, \strlen( $from_path ) );
				}

				return $path;
			},
			$exclude
		);

		$options = [
			'recursive' => true,
			'times' => true,
			'progress' => true,
			'remove-source-files' => $delete,
			'rsh' => (string) $this->get_rsh(),
			'exclude' => $exclude,
		];

		$src = Utils\normalize_path( $from->path );
		$dest = Utils\normalize_path( $to->path );

		if ( \str_ends_with( $src, '/' ) || $from->is_dir() ) {
			$src = Utils\trailingslashit( $src );
			$dest = Utils\trailingslashit( $dest );
		}

		if ( $is_self ) {
			$rsync = $this->sh( 'rsync' );
		} else {
			$rsync = parent::sh( 'rsync' );

			if ( $is_from ) {
				$src = $this->destination() . ":$src";
			} elseif ( $is_to ) {
				$dest = $this->destination() . ":$dest";
			}
		}

		$rsync->add( $options, $src, $dest )->stream();

		return $to;
	}

	public function file_put_contents( string $filename, mixed $data ) : bool {
		$temp_file = Utils\temp_file( $filename );

		if ( ! $temp_file ) {
			return false;
		}

		if ( false === \file_put_contents( $temp_file, $data ) ) {
			return false;
		}

		$this->move_from( $temp_file, $filename );

		return true;
	}

	public function file_exists( string $filename ) : bool {
		return $this->sh( 'test -e', $filename )->success();
	}

	public function is_dir( string $filename ) : bool {
		return $this->sh( 'test -d', $filename )->success();
	}

	public function is_file( string $filename ) : bool {
		return $this->sh( 'test -f', $filename )->success();
	}

	public function mkdir( string $pathname ) : bool {
		return $this->sh( 'mkdir -p', $pathname )->success();
	}

	public function rmdir( string $pathname ) : bool {
		return $this->sh( 'rm -R', $pathname )->success();
	}

	public function unlink( string $filename ) : bool {
		return $this->sh( 'rm', $filename )->success();
	}
}
