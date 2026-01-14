<?php

namespace WP_CLI;

use WP_CLI;
use WP_CLI\Utils;

/**
 * Universal API for both system and WP-CLI commands.
 */
class Commander {

	public int $mode = Shell::THROW;
	public ?string $debug = null;
	public ?array $wp_config = null;

	public function __construct( array $props = [] ) {
		foreach ( $props as $prop => $value ) {
			if ( \property_exists( $this, $prop ) ) {
				$this->$prop = $value;
			}
		}
	}

	public function sh( string $cmd, string | array ...$args ) : Shell {
		return new Shell(
			command: $cmd,
			args: $args,
			debug: $this->debug,
			mode: $this->mode,
		);
	}

	public function wp( string $cmd, string | array ...$args ) : Shell {
		global $argv;

		$wp = Utils\esc_cmd( '%s %s', Utils\get_php_binary(), $argv[0] );
		$wp .= Utils\options_to_str( $this->wp_config ?? Utils\get_runner()->runtime_config );

		$cmd .= Utils\args_to_cmd( $args );

		return $this->sh( "$wp $cmd" );
	}

	public function rsync( string $src, string $dest, array $options = [] ) : Shell {
		$options += [
			'perms' => true,
			'times' => true,
			'recursive' => true,
		];

		return new Shell(
			command: 'rsync',
			args: [ $options, $src, $dest ],
			debug: $this->debug,
			mode: $this->mode,
		);
	}

	public function debug( string | \Throwable | \WP_Error | \Stringable $message ) : void {
		if ( $message instanceof \Stringable ) {
			$message = (string) $message;
		}

		WP_CLI::debug( $message, $this->debug ?? false );
	}
}
