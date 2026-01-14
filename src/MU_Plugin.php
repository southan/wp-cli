<?php

namespace WP_CLI;

use WP_CLI;
use WP_CLI\Remote;

class MU_Plugin {

	public string $name;
	public string $slug;
	public string $description;

	public function __construct( string $name, string $description = '', string $slug = '' ) {
		$this->name        = $name;
		$this->slug        = $slug ?: (string) \preg_replace( '/[^a-z_]+/', '-', \strtolower( $name ) );
		$this->description = $description;
	}
	
	public function generate( string $code ) : string {
		return <<<PHP
		<?php

		/**
		 * Plugin Name: $this->name
		 * Description: $this->description
		 * Author:      WP-CLI
		 */

		$code

		PHP;
	}

	public function install( string $code, ?Remote $remote = null ) : bool {
		$remote ??= Remote::create();

		$mu_dir = $remote->eval( 'echo wp_mkdir_p( WPMU_PLUGIN_DIR ) ? realpath( WPMU_PLUGIN_DIR ) : null;' )->get();

		if ( ! $mu_dir ) {
			return false;
		}

		$contents = $this->generate( $code );

		return $remote->file_put_contents( "$mu_dir/$this->slug.php", $contents );
	}

	public function delete( ?Remote $remote = null ) : bool {
		$remote ??= Remote::create();

		$mu_dir = $remote->get_mu_plugins_dir();

		if ( ! $mu_dir ) {
			return false;
		}

		$file = \var_export( "/$this->slug.php", true );

		$result = $remote->eval( "echo is_file( WPMU_PLUGIN_DIR . $file ) ? @unlink( WPMU_PLUGIN_DIR . $file ) : 1" )->get();

		return $result === '1';
	}
}
