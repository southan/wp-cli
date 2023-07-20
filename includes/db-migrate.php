<?php

/**
 * Migrate database URLs.
 *
 * ## OPTIONS
 *
 * [<new_host>]
 * : Defaults to $BASENAME.local
 *
 * @when after_wp_load
 */
WP_CLI::add_command( 'db migrate', function ( $args ) {
	$new_host = $args[0] ?? basename( ABSPATH ) . '.local';

	$old_host = wp_parse_url( get_option( 'home' ), PHP_URL_HOST );

	if ( $old_host === $new_host ) {
		WP_CLI::error( 'Nothing to migrate.' );
	}

	$old_host = preg_replace( '/^www\./', '', $old_host );

	$from_to = [
		 "http://www.$old_host" => "https://$new_host",
		 "http://$old_host" => "https://$new_host",
		 "//www.$old_host" => "//$new_host",
		 "//$old_host" => "//$new_host",
	];

	foreach ( $from_to as $from => $to ) {
		if ( $from !== $to ) {
			WP_CLI::runcommand( WP_CLI\Utils\esc_cmd( 'search-replace %s %s', $from, $to ) );
		}
	}
});
