<?php

use WP_CLI\Utils;

/**
 * Wrapper for rsync.
 *
 * ## OPTIONS
 *
 * <sync>
 * : Make sure to quote globs so they're not expanded by your shell.
 *
 * <direction>
 * : The sync direction.
 * ---
 * options:
 *   - from
 *   - to
 * ---
 *
 * <target>
 * : Target SSH or alias. Use <target>:<path> syntax to specify base path, else relative ABSPATH is used.
 *
 * [--yes]
 * : Do not prompt for confirmation.
 *
 * [--<field>=<value>]
 * : Any additional rsync options.
 *
 * @when before_wp_load
*/
WP_CLI::add_command( 'sync', function ( $args, $assoc_args ) {
	list ( $sync, $direction, $target ) = $args;

	list ( $target, $path ) = array_pad( explode( ':', $target ), 2, null );

	$targets = Utils\get_targets( $target );

	$target_names = implode( ', ', array_map( fn ( $target ) => $target->name, $targets ) );

	if ( ! $targets ) {
		WP_CLI::error( 'Valid target(s) required.' );

	} elseif ( $direction === 'from' && count( $targets ) !== 1 ) {
		WP_CLI::error( 'You cannot sync from multiple targets.' );
	}

	if ( ! Utils\get_flag_value( $assoc_args, 'dry-run', false ) ) {
		WP_CLI::confirm( "Are you sure you wish to sync '$sync' $direction $target_names?", $assoc_args );
	}

	$assoc_args += [
		'archive'  => true,
		'compress' => true,
	];

	unset( $assoc_args['yes'] );

	$local_rel = trim( str_replace( realpath( ABSPATH ), '', getcwd() ), '/' );

	$rsync_cmd = 'rsync' . Utils\assoc_args_to_str( $assoc_args );

	foreach ( $targets as $target ) {
		$target_path = $path ?: $target->get_abspath();

		if ( ! $target_path ) {
			WP_CLI::warning( "Could not resolve path for '$target->name', skipping..." );
			continue;
		}

		$target_path = Utils\trailingslashit( $target_path );

		if ( $local_rel && ! $path ) {
			$target_path .= "$local_rel/";
		}

		$target_ssh = $target->get_ssh();

		$target_cmd = $rsync_cmd;

		if ( $ssh_args = $target->get_ssh_args() ) {
			$target_cmd .= Utils\esc_cmd( ' -e %s', "ssh$ssh_args" );
		}

		if ( $direction === 'from' ) {
			$target_cmd .= " $target_ssh:$target_path$sync .";
		} else {
			$target_cmd .= " $sync $target_ssh:$target_path";
		}

		WP_CLI::log( "\n> $target_cmd\n" );

		passthru( $target_cmd );
	}
});
