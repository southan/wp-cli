<?php

/*
 * Replaces WordPress' cURL CA bundle with system cURL's CA bundle.
 *
 * @when before_wp_load
 */
WP_CLI::add_command( 'core ca', $core_ca = function () {
	$curl_ca = exec( 'curl-config --ca' );

	if ( ! is_file( $curl_ca ) ) {
		return WP_CLI::warning( 'Could not find system cURL CA.' );
	}

	$wp_ca = ABSPATH . 'wp-includes/certificates/ca-bundle.crt';

	if ( file_exists( $wp_ca ) ) {
		unlink( $wp_ca );
	}

	file_put_contents( $wp_ca, file_get_contents( $curl_ca ) );

	WP_CLI::success( 'CA bundle replaced with system cURL\'s' );
});

WP_CLI::add_hook( 'after_invoke:core download', $core_ca );
WP_CLI::add_hook( 'after_invoke:core update', $core_ca );
