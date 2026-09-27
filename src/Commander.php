<?php

namespace WP_CLI;

use WP_CLI;
use WP_CLI\Utils;

/**
 * Universal API for system and WP-CLI commands.
 */
class Commander {

	public function __construct(
		/**
		 * Group debug messages (default none).
		 */
		public ?string $debug = null,

		/**
		 * Runtime arguments for WP-CLI (default inherited).
		 */
		public ?array $wp = null
	) {}

	public function get_wp_args() : array {
		return $this->wp ?? WP_CLI::get_runner()->runtime_config;
	}

	public function wp( string $cmd, mixed ...$args ) : Shell {
		global $argv;

		$wp = Utils\esc_cmd( '%s %s', Utils\get_php_binary(), $argv[0] );

		$cmd = "$wp $cmd";

		$args = $this->get_wp_args() + $args;

		return Shell::create(
			command: $cmd,
			args: $args,
			debug: $this->debug,
			negate: true
		);
	}

	public function sh( string $cmd, mixed ...$args ) : Shell {
		return Shell::create(
			command: $cmd,
			args: $args,
			debug: $this->debug
		);
	}

	public function debug( string | \Throwable | \WP_Error | \Stringable $message ) : void {
		if ( $message instanceof \Stringable ) {
			$message = (string) $message;
		}

		WP_CLI::debug( $message, $this->debug ?? false );
	}
}
