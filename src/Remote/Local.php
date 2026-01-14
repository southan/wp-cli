<?php

namespace WP_CLI\Remote;

use WP_CLI;
use WP_CLI\Remote;
use WP_CLI\Utils;

class Local extends Remote {

	public function __toString() : string {
		return $this->is_self() ? 'this site' : "$this->name";
	}

	public function is_local() : bool {
		return true;
	}

	public function is_self() : bool {
		return \realpath( \ABSPATH ) === $this->get_path();
	}

	public function get_home() : string {
		return Utils\get_home_dir();
	}

	public function get_path() : string {
		$path = \realpath( $this->path ?? \ABSPATH );
		if ( $path === false ) {
			$path = $this->path ?? \ABSPATH;
		}
		return $path;
	}

	public function get_const( string $name ) : mixed {
		if ( ! $this->is_self() || ! \defined( $name ) ) {
			return parent::get_const( $name );
		}

		return \constant( $name );
	}

	public function get_option( string $name ) : mixed {
		if ( ! $this->is_self() || ! \function_exists( 'get_option' ) ) {
			return parent::get_option( $name );
		}

		return \get_option( $name );
	}

	public function get_content_dir() : ?string {
		if ( ! $this->is_self() || ! \defined( 'WP_CONTENT_DIR' ) ) {
			return parent::get_content_dir();
		}

		return \realpath( \WP_CONTENT_DIR ) ?: null;
	}

	public function get_plugins_dir() : ?string {
		if ( ! $this->is_self() || ! \defined( 'WP_PLUGIN_DIR' ) ) {
			return parent::get_plugins_dir();
		}

		return \realpath( \WP_PLUGIN_DIR ) ?: null;
	}

	public function get_mu_plugins_dir() : ?string {
		if ( ! $this->is_self() || ! \defined( 'WPMU_PLUGIN_DIR' ) ) {
			return parent::get_mu_plugins_dir();
		}

		return \realpath( \WPMU_PLUGIN_DIR ) ?: null;
	}

	public function get_themes_dir() : ?string {
		if ( ! $this->is_self() || ! \defined( 'WP_CONTENT_DIR' ) ) {
			return parent::get_themes_dir();
		}

		return \realpath( \WP_CONTENT_DIR . '/themes' ) ?: null;
	}

	public function get_uploads_dir() : ?string {
		if ( ! $this->is_self() || ! \function_exists( 'wp_upload_dir' ) ) {
			return parent::get_uploads_dir();
		}

		$uploads = \wp_upload_dir();

		if ( ! empty( $uploads['error']  ) ) {
			WP_CLI::warning( $uploads['error'] );
		}

		$dir = $uploads['basedir'] ?? null;

		if ( \is_string( $dir ) ) {
			$dir = \realpath( $dir );

			if ( $dir ) {
				return $dir;
			}
		}

		return null;
	}

	public function copy( string $src, string $dest ) : bool {
		return \copy( $this->parse_uri( $src ), $this->parse_uri( $dest ) );
	}

	public function file_put_contents( string $filename, mixed $data ) : bool {
		return (bool) \file_put_contents( $filename, $data );
	}

	public function mkdir( string $pathname ) : bool {
		if ( \file_exists( $pathname ) ) {
			return \is_dir( $pathname );
		}

		return \mkdir( $pathname, recursive: true );
	}

	public function is_dir( string $filename ) : bool {
		return \is_dir( $filename );
	}

	public function is_file( string $filename ) : bool {
		return \is_file( $filename );
	}

	public function unlink( string $filename ) : bool {
		return \unlink( $filename );
	}
}
