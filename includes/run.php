<?php

/**
 * Better eval-file.
 *
 * Looks for file working up from the current directory.
 *
 * Auto-imports global variables.
 *
 * ## OPTIONS
 *
 * [<name>]
 * : File name to execute.
 */
WP_CLI::add_command( 'run', function ( $args ) {
	$name = $args[0];

	$path = getcwd();

	while ( true ) {
		foreach ( [ "$path/$name", "$path/$name.php" ] as $file ) {
			if ( is_file( $file ) ) {
				break 2;
			}

			$file = null;
		}

		if ( ! $path || $path === dirname( $path ) ) {
			break;
		}

		$path = dirname( $path );
	}

	if ( ! isset( $file ) ) {
		WP_CLI::error( "$name not found." );
	}

	foreach ( $GLOBALS as $var => $value ) {
		if ( ! isset( $$var ) ) {
			$$var = $value;
		}
	}

	include $file;
});
