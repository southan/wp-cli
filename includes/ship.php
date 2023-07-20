<?php

use WP_CLI\Utils;

/**
 * Package and deploy installed themes & plugins to other targets.
 */
class WP_CLI_Ship {

	/**
	 * Package type.
	 * 
	 * @var string theme|plugin
	 */
	protected $type;

	/**
	 * Absolute path to package contents.
	 * 
	 * @var string
	 */
	protected $path;

	/**
	 * Package file (ZIP).
	 * 
	 * @var string
	 */
	protected $file;

	/**
	 * Package and ship an installed theme.
	 *
	 * ## OPTIONS
	 *
	 * [<theme>]
	 * : Defaults to active theme.
	 *
	 * [<to>]
	 * : SSH or alias. Defaults to @all.
	 *
	 * [--to]
	 * : SSH or alias. Defaults to @all.
	 *
	 * [--preview]
	 * : Log commands without running them.
	 *
	 * [--yes]
	 * : Answer yes to the confirmation message.
	 */
	public function theme( $args, $assoc_args ) {
		$theme = wp_get_theme( $args[0] ?? '' );

		if ( $theme->errors() ) {
			WP_CLI::error( $theme->errors() );
		}

		$this->type = 'theme';

		$this->path = $theme->get_stylesheet_directory();

		$this->run( $assoc_args + [ 'to' => $args[2] ?? null ] );
	}

	/**
	 * Package and ship an installed plugin.
	 *
	 * ## OPTIONS
	 *
	 * <plugin>
	 * : Plugin slug.
	 *
	 * [<to>]
	 * : SSH or alias. Defaults to @all.
	 *
	 * [--to]
	 * : SSH or alias. Defaults to @all.
	 *
	 * [--preview]
	 * : Log commands without running them.
	 *
	 * [--yes]
	 * : Answer yes to the confirmation message.
	 */
	public function plugin( $args, $assoc_args ) {
		$plugin = $args[0];

		$exists = array_filter( array_keys( get_plugins() ), fn ( $file ) => strpos( $file, "$plugin/" ) === 0 );

		if ( ! $exists ) {
			WP_CLI::error( "Plugin '$plugin' does not exist." );
		}

		$this->type = 'plugin';

		$this->path = WP_PLUGIN_DIR . "/$plugin";

		$this->run( $assoc_args + [ 'to' => $args[1] ?? null ] );
	}

	/**
	 * Run the deployment process.
	 *
	 * @param array $options {
	 *     @type string $to
	 *     @type bool $preview
	 * }
	 */
	protected function run( $options ) {
		$targets = Utils\get_targets( $options['to'] ?? '@all' );

		if ( ! $targets ) {
			WP_CLI::error( 'Invalid destination.' );
		}

		$name = basename( $this->path );

		$is_preview = isset( $options['preview'] );

		if ( ! $is_preview ) {
			$to = implode( ', ', wp_list_pluck( $targets, 'name' ) );

			WP_CLI::confirm( "Are you sure you wish to ship $this->type $name to $to?", $options );
		}

		$config = WP_CLI::get_runner()->extra_config;

		$config = $config[ "ship $this->type $name" ] ?? $config[ "ship $this->type" ] ?? $config['ship'] ?? [];

		$build = $config['build'] ?? [];

		$ignore = $config['package-ignore'] ?? [];

		$ignore = array_merge( [ 'node_modules/*', '.git/*', '*/.DS_Store' ], (array) $ignore );

		$ignore = array_map( fn ( $ignore ) => "$name/$ignore", $ignore );

		WP_CLI::log( "\n> $this->path" );

		chdir( $this->path );

		foreach ( (array) $build as $cmd ) {
			WP_CLI::log( "\n> $cmd" );

			if ( ! $is_preview ) {
				passthru( $cmd, $exit_code );

				if ( $exit_code !== 0 ) {
					WP_CLI::halt( $exit_code );
				}
			}
		}

		chdir( '..' );

		$time = current_time( 'YmdHis' );

		$this->file = "$name-$time.zip";

		$zip_command = Utils\esc_cmd(
			'zip %s %s -r -x' . str_repeat( ' %s', count( $ignore ) ),
			$this->file,
			"$name/",
			...$ignore
		);

		WP_CLI::log( "\n> $zip_command" );

		if ( ! $is_preview ) {
			passthru( "$zip_command > /dev/null", $exit_code );

			if ( $exit_code !== 0 ) {
				WP_CLI::halt( $exit_code );
			}
		}

		foreach ( $targets as $target ) {
			$this->to_target( $target, $is_preview );
		}

		if ( ! $is_preview ) {
			unlink( $this->file );
		}
	}

	/**
	 * Deploy package to target.
	 */
	protected function to_target( Utils\Target $target, $is_preview ) {
		$ssh = $target->get_ssh();

		$ssh_args = $target->get_ssh_args();

		$target_file = Utils\trailingslashit( $target->path ?: './' ) . $this->file;

		$copy_file_cmd = 'scp';

		if ( $ssh_args ) {
			$copy_file_cmd .= str_replace( ' -p ', ' -P ', $ssh_args );
		}

		$copy_file_cmd = "$copy_file_cmd $this->file $ssh:$target_file";

		$install_cmd = $target->get_wp_command( "$this->type install $target_file --force" );

		$remove_cmd = "ssh$ssh_args $ssh \"rm $target_file\"";

		WP_CLI::log( "\n> $copy_file_cmd" );

		if ( ! $is_preview ) {
			passthru( $copy_file_cmd, $exit_code );

			if ( $exit_code !== 0 ) {
				return WP_CLI::warning( "Failed to copy package to '$target->name'" );
			}
		}

		WP_CLI::log( "\n> wp $install_cmd" );

		if ( ! $is_preview ) {
			WP_CLI::runcommand( $install_cmd );
		}

		WP_CLI::log( "\n> $remove_cmd" );

		if ( ! $is_preview ) {
			passthru( $remove_cmd, $exit_code );

			if ( $exit_code !== 0 ) {
				return WP_CLI::warning( "Failed to remove package from '$target->name'" );
			}
		}
	}

}

WP_CLI::add_command( 'ship', WP_CLI_Ship::class );
