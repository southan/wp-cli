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
 * : Either "from" or "to".
 *
 * <target>
 * : SSH or alias. If direction is "from" only 1 target is ever used.
 *
 * [<target_path>]
 * : Defaults to the same relative path to ABSPATH as local.
 *
 * [--existing]
 * : Only sync files to/from the target if they already exist.
 *
 * [--preview]
 * : Just output the rsync command(s).
 *
 * [--test]
 * : Dry run.
 *
 * [--yes]
 * : Answer yes to the confirmation message.
 *
 * @when before_wp_load
*/
WP_CLI::add_command( 'sync', function ( $args, $assoc_args ) {
	$sync = $args[0];

	$direction = $args[1];

	$is_from = $direction === 'from';

	if ( ! $is_from && $direction !== 'to' ) {
		WP_CLI::error( 'Action must be either "to" or "from".' );
	}

	$targets = Utils\get_targets( $args[2] );

	if ( ! $targets ) {
		WP_CLI::error( 'Valid target required.' );
	}

	$is_test = isset( $assoc_args['test'] );

	$is_preview = isset( $assoc_args['preview'] );

	if ( ! $is_test && ! $is_preview ) {
		$target_names = implode( ', ', array_map( fn ( $target ) => $target->name, $targets ) );

		WP_CLI::confirm( "Are you sure you wish to sync $sync $direction $target_names?", $assoc_args );
	}

	$rel_abspath = trim( str_replace( realpath( ABSPATH ), '', getcwd() ), '/' );

	$sync_is_rel = ! in_array( $sync[0], [ '~', '/' ], true );

	$target_path = $args[3] ?? null;

	if ( $target_path ) {
		$target_path = Utils\trailingslashit( $target_path );
	}

	$base_sync_cmd = 'rsync --archive --compress --progress';

	if ( isset( $assoc_args['existing'] ) ) {
		$base_sync_cmd .= ' --existing';
	}

	if ( $is_test ) {
		$base_sync_cmd .= ' --dry-run';
	}

	if ( $is_from ) {
		$targets = [ reset( $targets ) ];
	}

	foreach ( $targets as $target ) {
		$path = $target_path;

		if ( ! $path ) {
			$path = $target->get_abspath();

			if ( ! $path ) {
				WP_CLI::warning( "Could not resolve ABSPATH for '$target->name', skipping..." );
				continue;
			}

			if ( $sync_is_rel && $rel_abspath ) {
				$path = Utils\trailingslashit( $path ) . $rel_abspath;
			}

			if ( $is_from && $sync_is_rel ) {
				$path = Utils\trailingslashit( $path ) . $sync;
			}

			$path = escapeshellarg( $path );
		}

		$sync_cmd = $base_sync_cmd;

		if ( $ssh_args = $target->get_ssh_args() ) {
			$sync_cmd .= Utils\esc_cmd( ' -e %s', "ssh$ssh_args" );
		}

		$target_ssh = $target->get_ssh() . ":$path";

		$from = $is_from ? $target_ssh : $sync;

		$to = $is_from ? '.' : $target_ssh;

		$sync_cmd .= " $from $to";

		WP_CLI::log( "\n> $sync_cmd\n" );

		if ( ! $is_preview ) {
			passthru( $sync_cmd );
		}
	}
});
