<?php

/**
 * Create new local WordPress site.
 *
 * ## OPTIONS
 *
 * [<path>]
 * : Defaults to current directory.
 *
 * [--title=<title>]
 * : Site title. Defaults to <path> basename.
 *
 * [--url=<url>]
 * : Site URL. Defaults to https://<basename>.local
 *
 * [--version=<version>]
 * : WordPress version. Defaults to latest.
 *
 * [--prefix=<prefix>]
 * : Table prefix. Defaults to wp_
 *
 * @when before_wp_load
 */
WP_CLI::add_command( 'make', function ( $args, $assoc_args ) {
	$path = $args[0] ?? null;

	if ( $path ) {
		if ( ! is_dir( $path ) ) {
			exec( WP_CLI\Utils\esc_cmd( 'mkdir -p %s', $path ) );
		}

		chdir( $path );
	}

	if ( ( new \FilesystemIterator( '.' ) )->valid() ) {
		WP_CLI::error( 'Target directory is not empty.' );
	}

	$name = basename( getcwd() );

	$title = $assoc_args['title'] ?? ucwords( str_replace( [ '-', '_' ], ' ', $name ) );

	$url = $assoc_args['url'] ?? "https://$name.local";

	$version = $assoc_args['version'] ?? 'latest';

	$prefix = $assoc_args['prefix'] ?? 'wp_';

	if ( ! is_file( './wp-load.php' ) ) {
		WP_CLI::runcommand( WP_CLI\Utils\esc_cmd( 'core download --version=%s', $version ) );
	}

	if ( ! is_file( './wp-config.php' ) ) {
		WP_CLI::runcommand( WP_CLI\Utils\esc_cmd( 'config create --dbname=%s --dbprefix=%s', $prefix . $name, $prefix ) );
	}

	exec( 'wp db check &> /dev/null', $output, $db_check );

	if ( $db_check !== 0 ) {
		WP_CLI::runcommand( 'db create' );
	}

	WP_CLI::runcommand( WP_CLI\Utils\esc_cmd( 'core install --url=%s --title=%s', $url, $title ) );

	if ( $local = exec( 'which local' )  ) {
		exec( WP_CLI\Utils\esc_cmd( '%s host --domain=%s', $local, parse_url( $url, PHP_URL_HOST ) ) );
	}

	WP_CLI::runcommand( 'login' );
});
