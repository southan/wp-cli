<?php

/**
 * Replace the current wp-config.php with a "clean" version using the ~/.wp-cli/wp-config.mustache template.
 *
 * IMPORTANT: Will drop anything custom from the current wp-config.php
 *
 * @when before_wp_load
 */
function config_clean() {
	if ( ! is_file( ABSPATH . 'wp-config.php' ) ) {
		WP_CLI::error( "'wp-config.php' not found." );
	}

	$wp_settings = file_get_contents( ABSPATH . 'wp-settings.php' );

	file_put_contents( ABSPATH . 'wp-settings.php', '' );

	require_once ABSPATH . 'wp-config.php';

	file_put_contents( ABSPATH . 'wp-settings.php', $wp_settings );

	$vars = get_defined_constants() + get_defined_vars();

	$wp_config = WP_CLI\Utils\mustache_render( dirname( __DIR__ ) . '/wp-config.mustache', $vars );

	file_put_contents( ABSPATH . 'wp-config.php', $wp_config );

	WP_CLI::success( "Cleaned 'wp-config.php' file." );
}

WP_CLI::add_command( 'config clean', 'config_clean' );

WP_CLI::add_hook( 'after_invoke:config create', 'config_clean' );

