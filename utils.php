<?php

namespace WP_CLI\Utils;

use WP_CLI\Exception;
use WP_CLI\Extractor;
use WP_CLI\Process;

/**
 * Open a system file or URL.
 */
function open( string $uri ) : bool {
	$cmd = match ( true ) {
		\PHP_OS_FAMILY === 'Darwin' => 'open',
		is_windows() => 'start ""',
		default => 'xdg-open',
	};

	return Process::create( esc_cmd( "$cmd %s", $uri ) )->run()->return_code === 0;
}

/**
 * Sanitize a command argument.
 */
function esc_arg( mixed $arg ) : ?string {
	if ( \is_string( $arg ) || \is_float( $arg ) ) {
		return \escapeshellarg( (string) $arg );
	}

	if ( \is_int( $arg ) ) {
		return (string) $arg;
	}

	if ( \is_array( $arg ) ) {
		$args = \array_map( fn ( $arg ) => esc_arg( $arg ), $arg );

		$args = \array_filter( $args, static fn ( mixed $arg ) => $arg !== null );

		if ( $args ) {
			return \implode( ' ', $args );
		}
	}

	return null;
}

/**
 * Generate command string.
 */
function args_to_cmd( array $args, bool $negate = true ) : string {
	$cmd = '';

	foreach ( $args as $key => $arg ) {
		if ( \is_string( $key ) ) {
			$is_flag = \str_starts_with( $key, '-' );

			if ( $arg === true ) {
				$cmd .= $is_flag ? " $key" : " --$key";

			} elseif ( $arg === false ) {
				if ( $negate && ! $is_flag ) $cmd .= " --no-$key";

			} elseif ( \is_scalar( $arg ) ) {
				$cmd .= ' ' . \sprintf( $is_flag ? '%s %s' : '--%s=%s', $key, esc_arg( $arg ) );

			} elseif ( \is_array( $arg ) ) {
				foreach ( $arg as $value ) {
					$cmd .= args_to_cmd( [ $key => $value ], $negate );
				}
			}

		} elseif ( \is_array( $arg ) ) {
			$cmd .= args_to_cmd( $arg, $negate );

		} else {
			$arg = esc_arg( $arg );

			if ( $arg !== null ) {
				$cmd .= " $arg";
			}
		}
	}

	return $cmd;
}

/**
 * Get array of strings.
 *
 * Disable string splitting with an empty or non-string $separator.
 *
 * @return string[]
 */
function parse_list( mixed $list, mixed $separator = ',' ) : array {
	if ( \is_string( $list ) ) {
		if ( \is_string( $separator ) && $separator !== '' ) {
			$list = \explode( $separator, $list );
		} else {
			$list = (array) $list;
		}

	} else {
		if ( \is_object( $list ) ) {
			$list = \is_iterable( $list ) ? \iterator_to_array( $list ) : \get_object_vars( $list );

		} elseif ( ! \is_array( $list ) ) {
			return [];
		}

		$list = \array_filter( $list, 'is_scalar' );
	}

	$list = \array_map( static function ( $item ) {
		if ( \is_bool( $item ) ) {
			return null;
		}

		$item = \trim( (string) $item );

		if ( ! \strlen( $item ) ) {
			return null;
		}

		return $item;
	}, $list );

	$list = \array_filter( $list, static fn ( mixed $arg ) => $arg !== null );

	$list = \array_values( $list );

	return $list;
}

/**
 * Removes trailing forward slashes and backslashes if they exist.
 */
function untrailingslashit( mixed $string ) : string {
	if ( ! \is_string( $string ) ) {
		return '';
	}

	return \rtrim( $string, '/\\' );
}

/**
 * Normalise and canonise a path.
 */
function canonical_path( mixed $path, bool $resolve = true ) : string {
	if ( ! \is_string( $path ) ) {
		return '';
	}

	if ( $resolve ) {
		$realpath = \realpath( $path );

		if ( $realpath !== false ) {
			$path = $realpath;
		}
	}

	$path = normalize_path( $path );

	$path = untrailingslashit( $path );

	return $path;
}

/**
 * Joins two filesystem paths together.
 *
 * For example, 'give me $path relative to $base'. If the $path is absolute,
 * then it the full path is returned.
 */
function path_join( string $base, string $path ) : string {
	if ( is_path_absolute( $path ) ) {
		return $path;
	}

	return untrailingslashit( $base ) . '/' . \ltrim( $path, '/\\' );
}

/**
 * Create a new temporary file that self-removes on shutdown.
 *
 * @throws Exception if fails to create file.
 */
function temp_file( ?string $prefix = null ) : string {
	$temp_dir = get_temp_dir();

	if ( ! \is_writable( $temp_dir ) ) {
		throw new Exception();
	}

	$file = \tempnam( $temp_dir, $prefix ?? 'wp-cli-' );

	if ( ! \is_string( $file ) ) {
		throw new Exception( "Failed to create temporary file in '$temp_dir'." );
	}

	\register_shutdown_function( static function () use ( $file ) {
		if ( \is_file( $file ) ) {
			@\unlink( $file );
		}
	});

	return $file;
}

/**
 * Create a new temporary directory that self-removes on shutdown.
 *
 * @throws Exception if fails to create directory.
 */
function temp_dir( ?string $prefix = null ) : string {
	$temp_dir = get_temp_dir();

	if ( ! \is_writable( $temp_dir ) ) {
		throw new Exception();
	}

	do {
		$dir = $temp_dir . \uniqid( $prefix ?? 'wp-cli-' );
	} while (
		\file_exists( $dir )
	);

	if ( ! \mkdir( $dir, recursive: true ) ) {
		throw new Exception( "Failed to create temporary directory '$dir'." );
	}

	\register_shutdown_function( static function () use ( $dir ) {
		if ( \is_dir( $dir ) ) {
			Extractor::rmdir( $dir );
		}
	});

	return $dir;
}
