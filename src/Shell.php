<?php

namespace WP_CLI;

use WP_CLI;

/**
 * Fluent API for running system commands.
 */
class Shell {

	public ?string $debug = null;
	public bool    $negate = false;
	public array   $allow = [];

	private ProcessRun $result;

	public function __construct( string | ProcessRun $command ) {
		if ( ! $command instanceof ProcessRun ) {
			$command = new ProcessRun([
				'command' => $command,
			]);
		}

		$this->result = $command;
	}

	public function __toString() : string {
		return $this->result->command;
	}

	public static function create(
		string | ProcessRun $command,
		string | array $args = [],
		?string $debug = null,
		bool    $negate = false,
	) : self {
		$shell = new self( $command );

		$shell->debug = $debug;
		$shell->negate = $negate;

		if ( $args !== [] ) {
			$shell->add( $args );
		}

		return $shell;
	}

	/**
	 * Add arguments to the current command.
	 *
	 * @see Utils\args_to_cmd()
	 */
	public function add( mixed ...$args ) : self {
		$this->result->command .= Utils\args_to_cmd( $args, $this->negate );

		return $this;
	}

	/**
	 * Add unescaped arguments to the current command.
	 */
	public function add_raw( mixed ...$args ) : self {
		$args = implode( ' ', Utils\parse_list( $args ) );

		if ( \strlen( $args ) ) {
			$this->result->command .= " $args";
		}

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
		if ( $env === null ) {
			unset( $this->result->env );
		} else {
			$this->result->env = $env;
		}

		return $this;
	}

	/**
	 * Set exit codes that will not be considered failure.
	 */
	public function allow( int ...$codes ) : self {
		$this->allow = \array_values( \array_unique( \array_merge( $this->allow, $codes ) ) );

		return $this;
	}

	/**
	 * Check command result.
	 *
	 * @throws Exception on exit code outside allow boundaries.
	 */
	public function check() : self  {
		$code = $this->get_code();

		if ( $code && ! \in_array( $code, $this->allow, true ) ) {
			throw new Exception( (string) $this->get_error(), $code );
		}

		return $this;
	}

	/**
	 * Run the command.
	 */
	public function run( bool $stream = false ) : self {
		Utils\check_proc_available();

		$result = $this->result;

		$debug = "$ {$result->command}";
		if ( $result->cwd ) $debug .= " (cwd: {$result->cwd})";
		if ( $result->env ) $debug .= \sprintf( ' (env: %s)', \json_encode( $result->env ) );

		WP_CLI::debug( $debug, $this->debug ?? false );

		$descriptors = [
			0 => \STDIN,
			1 => $stream ? \STDOUT : [ 'pipe', 'w' ],
			2 => $stream ? \STDERR : [ 'pipe', 'w' ],
		];

		$pipes = [];

		$start_time = \microtime( true );

		$proc = Utils\proc_open_compat( $result->command, $descriptors, $pipes, $result->cwd, $result->env );

		if ( ! $stream ) {
			$result->stdout = \trim( (string) \stream_get_contents( $pipes[1] ) );
			\fclose( $pipes[1] );

			$result->stderr = \trim( (string) \stream_get_contents( $pipes[2] ) );
			\fclose( $pipes[2] );

			if ( \str_starts_with( $result->stderr, 'Error: ' ) ) {
				$result->stderr = \substr( $result->stderr, 7 );
			}
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

		return $this;
	}

	/**
	 * Stream the command instead of capturing the output.
	 */
	public function stream() : self {
		return $this->run( true )->check();
	}

	/**
	 * Check command exit code.
	 */
	public function is( int ...$codes ) : bool {
		return \in_array( $this->get_code(), $codes, true );
	}

	public function success() : bool {
		return $this->get_code() === 0;
	}

	public function failed() : bool {
		return ! $this->success();
	}
	
	/**
	 * Get command stdout.
	 *
	 * @throws Exception on exit code outside allow boundaries.
	 */
	public function get( bool $check = true ) : ?string {
		$result = $this->get_result();

		$check && $this->check();

		return $result->stdout;
	}

	/**
	 * Get command stderr.
	 */
	public function get_error() : ?string {
		$error = $this->get_result()->stderr;

		if ( \str_starts_with( "$error", 'Error: ' ) ) {
			$error = \substr( $error, 7 );
		}

		return $error;
	}

	/**
	 * Get command exit code.
	 */
	public function get_code() : int {
		return $this->get_result()->return_code;
	}

	/**
	 * Get command result as ProcessRun.
	 */
	public function get_result() : ProcessRun {
		$result = $this->result;

		if ( $result->return_code === null ) {
			$result->return_code = -1;

			$this->run();
		}

		return clone $result;
	}

	/**
	 * Parse command stdout as JSON.
	 *
	 * @throws Exception on exit code outside allow boundaries or if stdout is malformed/not JSON.
	 */
	public function parse_json() : mixed {
		$out = $this->get();

		if ( $out === null || $out === '' ) {
			return null;
		}

		$result = \json_decode( $out, true );

		if ( \json_last_error() !== \JSON_ERROR_NONE ) {
			throw new Exception( json_last_error_msg(), \json_last_error() );
		}

		return $result;
	}

	/**
	 * Parse command stdout as list.
	 *
	 * @throws Exception on exit code outside allow boundaries.
	 *
	 * @return string[]
	 */
	public function parse_list( string $separator = "\n" ) : array {
		return Utils\parse_list( $this->get(), $separator );
	}
}
