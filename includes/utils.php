<?php

namespace WP_CLI\Utils;

use WP_CLI;

/**
 * Resolve alias to SSH configuration(s).
 *
 * @link https://make.wordpress.org/cli/handbook/guides/running-commands-remotely/#aliases
 *
 * @param string $name
 *
 * @return array[]
 */
function resolve_aliases( $name ) {
	if ( ! strlen( $name ) ) {
		return [];
	}

	if ( $name[0] !== '@' ) {
		return [
			$name => [
				'name' => $name,
				'ssh' => $name,
			],
		];
	}

	if ( $name === '@all' ) {
		$aliases = array_diff( array_keys( WP_CLI::get_runner()->aliases ), [ $name ] );

	} else {
		$aliases = WP_CLI::get_runner()->aliases[ $name ] ?? [];

		if ( $aliases && ! isset( $aliases[0] ) ) {
			return [
				$name => [ 'name' => $name ] + $aliases,
			];
		}
	}

	$resolved = [];

	foreach ( $aliases as $alias ) {
		if ( ! isset( $resolved[ $alias ] ) ) {
			$resolved += resolve_aliases( $alias );
		}
	}

	return $resolved;
}

/**
 * Get resolved SSH targets.
 *
 * @param string $name SSH target or alias.
 *
 * @return Target[]
 */
function get_targets( $name ) {
	return array_map( fn ( $args ) => new Target( $args ), resolve_aliases( $name ) );
}

/**
 * Get resolved SSH target.
 *
 * @param string $name SSH target or alias.
 *
 * @return Target|null
 */
function get_target( $name ) {
	return current( get_targets( $name ) );
}

/**
 * SSH target.
 */
class Target {

	public $name,
	       $host,
	       $user,
	       $port,
	       $path,
	       $key;

	public function __construct( array $args ) {
		if ( isset( $args['ssh'] ) ) {
			$args += parse_ssh_url( $args['ssh'] );
		}

		$props = array_intersect_key( $args, get_object_vars( $this ) );

		foreach ( $props as $prop => $value ) {
			$this->$prop = $value;
		}
	}

	public function is_alias() {
		return strpos( $this->name, '@' ) === 0;
	}

	public function get_ssh( $options = [] ) {
		$options += [
			'port' => false,
			'path' => false,
		];

		$ssh = $this->user ? "$this->user@$this->host" : $this->host;

		if ( $options['port'] && $this->port ) {
			$ssh .= ":$this->port";
		}

		if ( $options['path'] && $this->path ) {
			$ssh .= "$this->path";
		}

		return $ssh;
	}

	public function get_ssh_args() {
		$ssh_args = '';

		if ( $this->key ) {
			$ssh_args .= esc_cmd( ' -i %s', $this->key );
		}

		if ( $this->port ) {
			$ssh_args .= sprintf( ' -p %d', $this->port );
		}

		return $ssh_args ?: null;
	}

	public function get_wp_command( $cmd ) {
		return $this->is_alias()
			? "$this->name $cmd"
			: $cmd . assoc_args_to_str([
				'ssh' => $this->get_ssh([
					'port' => true,
					'path' => true,
				]),
			]);
	}

	public function run_wp_command( $cmd, $options = [] ) {
		return WP_CLI::runcommand( $this->get_wp_command( $cmd ), $options );
	}

	public function get_abspath() {
		if ( ! isset( $this->path ) ) {
			$this->path = $this->run_wp_command( 'eval "echo realpath( ABSPATH );"', [
				'exit_error' => false,
				'return' => true,
			]);

			if ( ! $this->path ) {
				$this->path = false;
			}
		}

		return $this->path;
	}
}
