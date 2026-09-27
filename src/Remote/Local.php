<?php

namespace WP_CLI\Remote;

use WP_CLI;
use WP_CLI\Exception;
use WP_CLI\Location;
use WP_CLI\Remote;
use WP_CLI\Utils;

class Local extends Remote {

	public function uri( ?string $path = null ) : string {
		if ( $path ) {
			$path = Utils\canonical_path( $path );
		}

		$path ??= $this->get_path();

		return "local://$path";
	}

	public function is_self() : bool {
		return $this->path === null || $this->path === \ABSPATH || Utils\canonical_path( \ABSPATH ) === $this->get_path();
	}

	public function get_path() : string {
		return Utils\canonical_path( $this->path ?? \ABSPATH );
	}

	public function get_wp_args() : array {
		return $this->wp ?? $this->is_self() ? WP_CLI::get_runner()->runtime_config : \array_filter([
			'path' => $this->path,
			'url' => $this->url,
		]);
	}

	public function get_const( string $name ) : mixed {
		if ( \defined( $name ) && $this->is_self() ) {
			return \constant( $name );
		}

		return parent::get_const( $name );
	}

	public function get_option( string $name ) : mixed {
		if ( \function_exists( 'get_option' ) && $this->is_self() ) {
			return \get_option( $name );
		}

		return parent::get_option( $name );
	}

	public function get_content_dir() : string {
		if ( \defined( 'WP_CONTENT_DIR' ) && $this->is_self() ) {
			return Utils\canonical_path( \WP_CONTENT_DIR );
		}

		return parent::get_content_dir();
	}

	public function get_plugins_dir() : string {
		if ( \defined( 'WP_PLUGIN_DIR' ) && $this->is_self() ) {
			return Utils\canonical_path( \WP_PLUGIN_DIR );
		}

		return parent::get_plugins_dir();
	}

	public function get_mu_plugins_dir() : string {
		if ( \defined( 'WPMU_PLUGIN_DIR' ) && $this->is_self() ) {
			return Utils\canonical_path( \WPMU_PLUGIN_DIR );
		}

		return parent::get_mu_plugins_dir();;
	}

	public function get_themes_dir() : string {
		if ( \defined( 'WP_CONTENT_DIR' ) && $this->is_self() ) {
			return Utils\canonical_path( \WP_CONTENT_DIR . '/themes' );
		}

		return parent::get_themes_dir();
	}

	public function get_uploads_dir() : string {
		if ( \function_exists( 'wp_get_upload_dir' ) && $this->is_self() ) {
			return Utils\canonical_path( \wp_upload_dir( null, false )['basedir'] );
		}

		return parent::get_uploads_dir();
	}

	public function copy( string $from, string | Location | Remote $to, array $options = [] ) : Location {
		if ( ! $this->file_exists( $from ) ) {
			throw new Exception( "'$from' does not exist." );
		}

		$from = $this->locate( $from );

		$to = Location::from( $to, $this );

		if ( ! $to->is_local() ) {
			return $to->copy_from( $from, $options );
		}

		$to->path ??= $from->path;

		$from_path = Utils\canonical_path( $from->path );

		$to_path = Utils\canonical_path( $to->path );

		if ( $from_path === $to_path ) {
			return $to;
		}

		$delete = ! empty( $options['delete'] );

		if ( \is_file( $from_path ) ) {
			if ( ! \copy( $from_path, $to_path ) ) {
				throw new Exception( "Failed to copy '$from' to '$to'." );
			}

			if ( $delete ) {
				$this->unlink( $from_path );
			}

			return $to;
		}

		if ( ! \is_dir( $from_path ) ) {
			throw new Exception( "Cannot copy '$from' - not a file or directory." );
		}

		if ( ! $this->mkdir( $to_path ) ) {
			throw new Exception( "Failed to create directory '$to'." );
		}

		$exclude = \array_map(
			static function ( string $path ) use ( $from_path ) {
				$path = Utils\normalize_path( $path );

				if ( \str_starts_with( $path, "$from_path/" ) ) {
					$path = \substr( $path, \strlen( $from_path ) );
				}

				$pattern = \strtr( \preg_quote( $path, '!' ), [
					'\*\*' => '.*',
					'\*' => '[^/]*',
					'\?' => '[^/]',
					'\[' => '[',
					'\]' => ']',
				]);

				if ( \str_starts_with( $pattern, '/' ) ) {
					$pattern = "^$pattern";
				} else {
					$pattern = "/$pattern";
				}

				return "!$pattern(/|$)!";
			},
			Utils\parse_list( $options['exclude'] ?? [], false )
		);

		$dir = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator(
					$from_path,
					\RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::CURRENT_AS_SELF
				),
				static function ( \RecursiveDirectoryIterator $file ) use ( $exclude ) : bool {
					$path = Utils\normalize_path( '/' . $file->getSubPathname() );

					foreach ( $exclude as $pattern ) {
						if ( \preg_match( $pattern, $path ) ) {
							return false;
						}
					}

					return true;
				}
			),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		$to_delete = [];

		/** @var \RecursiveDirectoryIterator $file */
		foreach ( $dir as $file ) {
			$rel_path = $file->getSubPathname();

			$src_path = $file->getPathname();

			$dest_path = "$to_path/$rel_path";

			if ( $file->isDir() ) {
				if ( ! $this->mkdir( $dest_path ) ) {
					throw new Exception( "Failed to create '$dest_path'." );
				}

			} elseif ( $file->isFile() ) {
				if ( ! \copy( $src_path, $dest_path ) ) {
					throw new Exception( "Failed to copy '$src_path' to '$dest_path'." );
				}

			} else {
				continue;
			}

			if ( $delete ) {
				$to_delete[] = $src_path;
			}
		}

		foreach ( $to_delete as $path ) {
			if ( \is_file( $path ) ) {
				$this->unlink( $path );

			} elseif ( \is_dir( $path ) && ! ( new \FilesystemIterator( $path ) )->valid() ) {
				$this->rmdir( $path );
			}
		}

		return $to;
	}

	public function copy_from( string | Location $from, ?string $to = null, array $options = [] ) : Location {
		$from = Location::from( $from );

		$to = $this->locate( $to );

		return $from->copy_to( $to, $options );
	}

	public function rename( string $from, string $to ) : bool {
		return \rename( $from, $to );
	}

	public function file_put_contents( string $filename, mixed $data ) : bool {
		return (bool) \file_put_contents( $filename, $data );
	}

	public function file_exists( string $filename ) : bool {
		return \file_exists( $filename );
	}

	public function is_file( string $filename ) : bool {
		return \is_file( $filename );
	}

	public function is_dir( string $filename ) : bool {
		return \is_dir( $filename );
	}

	public function mkdir( string $pathname ) : bool {
		if ( \file_exists( $pathname ) ) {
			return \is_dir( $pathname );
		}

		return \mkdir( $pathname, recursive: true );
	}

	public function rmdir( string $pathname ) : bool {
		return \rmdir( $pathname );
	}

	public function unlink( string $filename ) : bool {
		return \unlink( $filename );
	}
}
