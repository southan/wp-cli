<?php

/**
 * Update the WP CLI binary.
 *
 * @when before_wp_load
 */
WP_CLI::add_command( 'update-self', function () {
	$wp_binary = escapeshellarg( exec( 'which wp' ) );

	echo `sudo curl https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar --output $wp_binary`;
	echo `sudo chmod +x $wp_binary`;
});
