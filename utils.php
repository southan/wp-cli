<?php

namespace WP_CLI\Utils;

use WP_CLI;
use WP_CLI\Process;
use WP_CLI\Runner;

/**
 * Get WP-CLI runner instance.
 */
function get_runner() : Runner {
	$runner = WP_CLI::get_runner();
	if ( ! $runner instanceof Runner ) {
		$runner = new Runner();
	}
	return $runner;
}

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
 * Filter or sanitize a command argument (accepts int, float, or string).
 */
function esc_arg( mixed $arg ) : ?string {
	if ( \is_int( $arg ) || \is_float( $arg ) ) {
		return (string) $arg;
	}

	if ( \is_string( $arg ) ) {
		return \escapeshellarg( $arg );
	}

	return null;
}

/**
 * Generate command string.
 *
 * Nested arrays & values with associative keys are parsed with options_to_str().
 */
function args_to_cmd( array $args, bool $negate = true ) : string {
	$cmd = '';

	foreach ( $args as $key => $arg ) {
		if ( \is_string( $key ) ) {
			$cmd .= options_to_str( [ $key => $arg ], $negate );
		} elseif ( \is_array( $arg ) ) {
			$cmd .= options_to_str( $arg, $negate );
		} elseif ( \is_scalar( $arg ) ) {
			$cmd .= ' ' . \escapeshellarg( (string) $arg );
		}
	}

	return $cmd;
}

/**
 * Generate command options string from associative array.
 *
 * Unlike assoc_args_to_str() this function filters out non-scalar values and
 * generates flags syntax for keys prefixed with '-'.
 */
function options_to_str( array $options, bool $negate = true ) : string {
	$str = '';

	foreach ( $options as $key => $value ) {
		if ( ! \is_string( $key ) ) {
			$arg = esc_arg( $value );
			if ( $arg !== null ) {
				$str .= " $arg";
			}
			continue;
		}

		$is_flag = $key[0] === '-';

		if ( $value === true ) {
			$str .= $is_flag ? " $key" : " --$key";

		} elseif ( $value === false ) {
			if ( $negate && ! $is_flag ) $str .= " --no-$key";

		} elseif ( \is_string( $value ) ) {
			$str .= ' ' . \sprintf( $is_flag ? '%s %s' : '--%s=%s', $key, \escapeshellarg( $value ) );

		} elseif ( \is_int( $value ) || \is_float( $value ) ) {
			$str .= ' ' . \sprintf( $is_flag ? '%s %s' : '--%s=%s', $key, $value );

		} elseif ( \is_array( $value ) ) {
			foreach ( $value as $v ) {
				$str .= options_to_str( [ $key => $v ], $negate );
			}
		}
	}

	return $str;
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
			$list = \is_iterable( $list ) ? \iterator_to_array( $list, false ) : \get_object_vars( $list );

		} elseif ( ! \is_array( $list ) ) {
			return [];
		}

		$list = \array_filter( $list, 'is_string' );
	}

	$list = \array_map( 'trim', $list );
	$list = \array_filter( $list, fn ( $line ) => \strlen( $line ) > 0 );
	$list = \array_values( $list );

	return $list;
}
