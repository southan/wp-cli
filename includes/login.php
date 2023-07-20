<?php

/**
 * Automatically open & log into WordPress.
 *
 * ## OPTIONS
 *
 * [<user>]
 * : Log in as user. Defaults to first available administrator.
 */
WP_CLI::add_command( 'login', function ( $args ) {
	$user = reset( $args );

	if ( $user ) {
		$wp_user = false;

		foreach ( [ 'id', 'login', 'email' ] as $field ) {
			$wp_user = get_user_by( $field, $user );

			if ( $wp_user ) {
				break;
			}
		}
	} else {
		$wp_user = current( get_users([
			'role' => 'administrator',
			'number' => 1,
			'orderby' => 'ID',
		]));
	}

	if ( ! $wp_user ) {
		WP_CLI::error( 'User not found.' );
	}

	if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
		wp_mkdir_p( WPMU_PLUGIN_DIR );
	}

	$key = uniqid();

	$login_url = add_query_arg( 'login-key', $key, home_url() );

	file_put_contents(
		WPMU_PLUGIN_DIR . "/login-$key.php",
		<<<PHP
		<?php

		add_action( 'parse_request', function () {
			if ( filter_input( INPUT_GET, 'login-key' ) === '$key' ) {
				unlink( __FILE__ );

				wp_set_auth_cookie( $wp_user->ID, true );
				wp_redirect( admin_url() );
				exit;
			}
		});

		PHP
	);

	passthru( WP_CLI\Utils\esc_cmd( 'open %s', $login_url ) );
});
