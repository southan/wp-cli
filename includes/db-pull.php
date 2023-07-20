<?php

use WP_CLI\Utils;

/**
 * Pull and import a database from a remote WordPress installation.
 *
 * ## OPTIONS
 *
 * <target>
 * : Target SSH or alias.
 */
WP_CLI::add_command( 'db pull', function ( $args ) {
	$target = Utils\get_target( $args[0] );

	if ( ! $target ) {
		WP_CLI::error( 'Valid target required.' );
	}

	$db = 'db-' . time() . '.sql';

	$target->run_wp_command( "db export $db" );

	$ssh = $target->get_ssh() ;

	$pull_cmd = 'rsync --archive --compress --progress';

	if ( $ssh_args = $target->get_ssh_args() ) {
		$pull_cmd .= Utils\esc_cmd( ' -e %s', "ssh$ssh_args" );
	}

	$pull_cmd .= " $ssh:$db .";

	passthru( $pull_cmd );

	$target->run_wp_command( "eval \"unlink( '$db' );\"" );

	WP_CLI::runcommand( "db import $db" );

	unlink( $db );

	WP_CLI::runcommand( "db migrate" );
});
