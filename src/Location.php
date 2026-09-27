<?php

namespace WP_CLI;

class Location {

	public function __construct(
		public Remote $remote,
		public ?string $path = null
	) {}

	public function __toString() : string {
		return $this->uri();
	}

	public static function from( string | Location | Remote | null $location, ?Remote $remote = null ) : self {
		if ( ! $location instanceof self ) {
			list ( $remote, $path ) = match ( true ) {
				$location instanceof Remote => [ $location, null ],
				\is_string( $location ) => [ $remote, $location ],
				default => [ $remote, null ]
			};

			$location = new self( $remote ?? Remote::create(), $path );
		}

		return $location;
	}

	public function uri() : string {
		return $this->remote->uri( $this->path );
	}

	public function is( string | Location | Remote | null $location ) : bool {
		if ( $location === null ) {
			return false;
		}

		if ( $location === $this ) {
			return true;
		}

		if ( $location instanceof Remote ) {
			return $this->remote->is( $location );
		}

		return $this->uri() === self::from( $location )->uri();
	}

	public function is_local() : bool {
		return \str_starts_with( $this->uri(), 'local://' );
	}

	public function has_path() : bool {
		return $this->path !== null && \strlen( $this->path ) > 0;
	}

	public function require_path() : string {
		if ( ! $this->has_path() ) {
			throw new Exception( 'Path is required.' );
		}

		/** @var string */
		return $this->path;
	}

	public function exists() : bool {
		$path = $this->require_path();

		return $this->remote->file_exists( $path );
	}

	public function is_file() : bool {
		$path = $this->require_path();

		return $this->remote->is_file( $path );
	}

	public function is_dir() : bool {
		$path = $this->require_path();

		return $this->remote->is_dir( $path );
	}

	public function delete() : bool {
		$path = $this->require_path();

		if ( $this->is_dir() ) {
			return $this->remote->rmdir( $path );
		}

		return $this->remote->unlink( $path );
	}

	public function copy_to( string | Location | Remote $to, array $options = [] ) : self {
		$path = $this->require_path();

		return $this->remote->copy( $path, self::from( $to ), $options );
	}

	public function move_to( string | Location | Remote $to, array $options = [] ) : self {
		$path = $this->require_path();

		return $this->remote->move( $path, self::from( $to ), $options );
	}

	public function copy_from( string | Location $from, array $options = [] ) : self {
		return $this->remote->copy_from( $from, $this->path, $options );
	}

	public function move_from( string | Location $from, array $options = [] ) : self {
		return $this->remote->move_from( $from, $this->path, $options );
	}
}
