<?php

/**
 * Cleanup after standard WordPress download.
 *
 * @when before_wp_load
 */
WP_CLI::add_command( 'core clean', $core_clean = function () {
	$files = [
		'wp-config-sample.php',
		'license.txt',
		'readme.html',
	];

	foreach ( $files as $file ) {
		if ( is_file( ABSPATH . $file ) ) {
			unlink( ABSPATH . $file );
		}
	}

	$ensure_dirs = [
		ABSPATH . 'wp-content/themes',
		ABSPATH . 'wp-content/plugins',
	];

	foreach ( $ensure_dirs as $dir ) {
		if ( ! is_dir( $dir ) ) {
			exec( WP_CLI\Utils\esc_cmd( 'mkdir -p %s', $dir ) );
		}
	}
});

WP_CLI::add_hook( 'after_invoke:core download', $core_clean );
WP_CLI::add_hook( 'after_invoke:core update', $core_clean );
