<?php

namespace WP_CLI\Remote;

use WP_CLI;
use WP_CLI\Remote;
use WP_CLI\Shell;
use WP_CLI\Utils;

class Ssh extends Remote {

	public string $host;
	public ?string $user = null;
	public ?int $port = null;
	public ?string $key = null;
	public ?string $proxyjump = null;
	public ?string $socket = null;

	public function __construct( array $props = [] ) {
		parent::__construct( $props );

		if ( ! isset( $this->host ) ) {
			WP_CLI::error( 'Host is required.' );
		}
	}

	public function get_destination() : string {
		return $this->user ? "$this->user@$this->host" : $this->host;
	}

	public function get_uri( string $path ) : string {
		return $this->get_destination() . ":$path";
	}

	public function get_rsh() : Shell {
		return parent::sh( 'ssh', [
			'-p' => $this->port,
			'-i' => $this->key,
			'-J' => $this->proxyjump,
			'-S' => $this->socket,
		]);
	}

	public function rsh() : Shell {
		return $this->get_rsh()
		->add([ '-t' => true ])
		->add( $this->get_destination() )
		->add( \sprintf( 'PS1="\[\e[32m\]%s\[\e[0m\]:\[\e[34m\]\w\[\e[0m\]\$ " bash --noprofile --norc -i', "$this" ) )
		->accept( 1 );
	}

	public function sh( string $cmd, string | array ...$args ) : Shell {
		$cmd .= Utils\args_to_cmd( $args, negate: false );

		if ( ! $this->socket ) {
			$this->socket = Utils\get_home_dir() . '/.ssh/wp-cli-' . $this->get_destination();
			if ( $this->port ) {
				$this->socket .= "_$this->port";
			}
		}

		if ( ! \file_exists( $this->socket ) ) {
			$socket_dir = \dirname( $this->socket );
			if ( ! \is_dir( $socket_dir ) ) {
				\mkdir( $socket_dir, recursive: true );
			}

			$this->get_rsh()
			->add([
				'-M' => true,
				'-N' => true,
				'-f' => true,
				'-o' => 'ControlPersist=30',
			])
			->add( $this->get_destination() )
			->run();
 		}

 		return $this->get_rsh()->add( $this->get_destination(), $cmd );
	}

	public function wp( string $cmd, string | array ...$args ) : Shell {
		$wp = \getenv( 'WP_CLI_SSH_BINARY' ) ?: 'wp';
		$wp .= Utils\options_to_str( (array) $this->wp_config );
		$cmd .= Utils\args_to_cmd( $args );

		return $this->sh( "$wp $cmd" );
	}

	public function rsync( string $src, string $dest, array $options = [] ) : Shell {
		$options['rsh'] = (string) $this->get_rsh();

		$options['compress'] = true;

		return parent::rsync( $src, $dest, $options );
	}

	public function copy( string $src, string $dest ) : bool {
		$rsync = $this->rsync( $src, $dest )->accept( ...\range( 1, 255 ) );

		$rsync->stream();

		if ( $rsync->is_ok() || ! $rsync->is( 127 ) ) {
			return $rsync->is_ok();
		}

		$scp = parent::sh( 'scp', [
			'-P' => $this->port,
			'-i' => $this->key,
			'-J' => $this->proxyjump,
			'-o' => $this->socket ? "ControlPath=$this->socket" : null,
			$this->parse_uri( $src ),
			$this->parse_uri( $dest ),
		]);

		return $scp->stream()->is_ok();
	}

	public function file_put_contents( string $filename, mixed $data ) : bool {
		$ext = \pathinfo( $filename, \PATHINFO_EXTENSION );
		$name = \pathinfo( $filename, \PATHINFO_FILENAME );
		$temp_file = Utils\get_temp_dir() . \uniqid( "wp-remote-$name-" ) . ( $ext ? ".$ext" : '' );

		if ( false === \file_put_contents( $temp_file, $data ) ) {
			return false;
		}

		$result = $this->copy( $temp_file, "$this:$filename" );

		\unlink( $temp_file );

		return $result;
	}

	public function is_dir( string $filename ) : bool {
		return $this->sh( 'test -d', $filename )->accept( 0, 1 )->is_ok();
	}

	public function is_file( string $filename ) : bool {
		return $this->sh( 'test -f', $filename )->accept( 0, 1 )->is_ok();
	}

	public function mkdir( string $pathname ) : bool {
		return $this->sh( 'mkdir -p', $pathname )->accept( 0, 1 )->is_ok();
	}

	public function unlink( string $filename ) : bool {
		return $this->sh( 'rm', $filename )->accept( 0, 1 )->is_ok();
	}
}
