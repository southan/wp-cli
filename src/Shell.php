<?php

namespace WP_CLI;

use WP_CLI;
use WP_CLI\Iterators\Exception;

/**
 * Fluent shell API for running system commands.
 */
class Shell {

	const EXIT  = 1;
	const THROW = 2;
	const WARN  = 3;

	private ProcessRun $result;

	public function __construct(
		string | ProcessRun $command,
		array $args = [],
		public  ?string $debug = null,
		private int     $mode = 0,
		private array   $accept = [],
		private bool    $stream = false
	) {
		if ( ! $command instanceof ProcessRun ) {
			$command = new ProcessRun([
				'command' => $command,
			]);
		}

		$this->result = $command;

		if ( $args ) {
			$this->result->command .= Utils\args_to_cmd( $args, negate: false );
		}
	}

	public function __toString() : string {
		return $this->result->command;
	}

	/**
	 * Add arguments to the current command.
	 *
	 * @see Utils\args_to_cmd()
	 */
	public function add( string | array ...$args ) : self {
		$this->result->command .= Utils\args_to_cmd( $args, negate: false );

		return $this;
	}

	/**
	 * Set working directory.
	 */
	public function cwd( ?string $cwd ) : self {
		$this->result->cwd = $cwd;

		return $this;
	}

	/**
	 * Set environment variables.
	 */
	public function env( ?array $env ) : self {
		$this->result->env = $env;

		return $this;
	}

	/**
	 * Set exit codes that will not be considered failure errors.
	 */
	public function accept( int ...$codes ) : self {
		$this->accept = \array_values( \array_unique( \array_merge( $this->accept, $codes ) ) );

		return $this;
	}

	/**
	 * Set failure mode & check result (if command has run).
	 */
	public function mode( int $mode ) : self {
		$this->mode = $mode;

		return $this->check();
	}

	/**
	 * Exit if command fails.
	 */
	public function fail() : self {
		return $this->mode( self::EXIT );
	}

	/**
	 * Throw exception if command fails.
	 */
	public function throw() : self {
		return $this->mode( self::THROW );
	}

	/**
	 * Print warning if command fails.
	 */
	public function warn() : self {
		return $this->mode( self::WARN );
	}

	/**
	 * Check command result (if command has run).
	 */
	public function check( ?int $mode = null ) : self  {
		$code = $this->result->return_code;

		if ( ! $code || \in_array( $code, $this->accept, true ) ) {
			return $this;
		}

		$error = (string) $this->result->stderr;

		if ( \str_starts_with( $error, 'Error: ' ) ) {
			$error = \substr( $error, \strlen( 'Error: ' ) );
		}

		$mode ??= $this->mode;

		if ( $mode === self::EXIT ) {
			WP_CLI::error( $error, $code >= 1 ? $code : true );
		}

		if ( $mode === self::THROW ) {
			throw new Exception( $error, $code );
		}

		if ( $mode === self::WARN ) {
			WP_CLI::error( $error, false );
		}

		return $this;
	}

	/**
	 * Run the command.
	 */
	public function run() : self {
		Utils\check_proc_available();

		$result = $this->result;

		$debug = "$ {$result->command}";
		if ( $result->cwd ) $debug .= " (cwd: {$result->cwd})";
		if ( $result->env ) $debug .= \sprintf( ' (env: %s)', \json_encode( $result->env ) );

		WP_CLI::debug( $debug, $this->debug ?? false );

		$descriptors = [
			0 => \STDIN,
			1 => $this->stream ? \STDOUT : [ 'pipe', 'w' ],
			2 => $this->stream ? \STDERR : [ 'pipe', 'w' ],
		];

		$pipes = [];

		$start_time = \microtime( true );

		$proc = Utils\proc_open_compat( $result->command, $descriptors, $pipes, $result->cwd, $result->env );

		if ( ! $proc ) {
			WP_CLI::error( 'Failed to open process.' );
		}

		if ( ! $this->stream ) {
			$result->stdout = \trim( (string) \stream_get_contents( $pipes[1] ) );
			\fclose( $pipes[1] );

			$result->stderr = \trim( (string) \stream_get_contents( $pipes[2] ) );
			\fclose( $pipes[2] );
		}

		$result->return_code = \proc_close( $proc );

		$result->run_time = \microtime( true ) - $start_time;

		if ( -1 === $result->return_code ) {
			WP_CLI::warning( 'Spawned process returned exit code -1, which could be caused by a custom compiled version of PHP that uses the --enable-sigchild option.' );
		}

		WP_CLI::debug(
			\sprintf(
				'run time: %.03fs, exit status: %s',
				$result->run_time,
				$result->return_code
			),
			$this->debug ?? false
		);

		return $this->check();
	}

	/**
	 * Stream the command instead of capturing the output.
	 *
	 * Note that get() will return empty for streamed commands.
	 */
	public function stream() : self {
		$this->stream = true;

		return $this->run();
	}

	/**
	 * Check command exit code (runs command if not already).
	 */
	public function is( int ...$codes ) : bool {
		return \in_array( $this->get_code(), $codes, true );
	}

	/**
	 * Check command exit code is zero (runs command if not already)
	 */
	public function is_ok() : bool {
		return $this->get_code() === 0;
	}
	
	/**
	 * Get command result as ProcessRun (runs command if not already).
	 */
	public function get_result() : ProcessRun {
		$result = $this->result;

		if ( $result->return_code === null ) {
			$this->run();
		}

		return clone $result;
	}

	/**
	 * Get command exit code (runs command if not already).
	 */
	public function get_code() : int {
		return $this->get_result()->return_code;
	}

	/**
	 * Get command stderr (runs command if not already).
	 */
	public function get_error() : ?string {
		return $this->get_result()->stderr;
	}

	/**
	 * Get command stdout (runs command if not already).
	 */
	public function get() : ?string {
		return $this->get_result()->stdout;
	}

	/**
	 * Parse command stdout as JSON (runs command if not already).
	 */
	public function parse_json() : mixed {
		$out = $this->get();
		if ( $out === null ) {
			return null;
		}
		if ( $out === '' ) {
			return false;
		}
		return \json_decode( $out, true, flags: \JSON_THROW_ON_ERROR );
	}

	/**
	 * Parse command stdout as list (runs command if not already).
	 *
	 * @return string[]
	 */
	public function parse_list( string $separator = "\n" ) : array {
		return Utils\parse_list( $this->get(), $separator );
	}
}
