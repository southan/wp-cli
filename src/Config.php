<?php

namespace WP_CLI;

use Mustangostang\Spyc;
use WP_CLI;

/**
 * @extends \ArrayObject<string, mixed>
 */
class Config extends \ArrayObject {

	public string $file;

	public function __construct( ?string $type = null ) {
		$type ??= 'global';

		$runner = WP_CLI::get_runner();

		if ( $type === 'global' ) {
			$file = $runner->global_config_path;
			if ( ! $file ) {
				throw new Exception( $runner->global_config_path_debug );
			}
		} elseif ( $type === 'project' ) {
			$file = $runner->project_config_path;
			if ( ! $file ) {
				throw new Exception( $runner->project_config_path_debug );
			}
		} else {
			throw new Exception( "Invalid config type '$type'." );
		}

		$config = \is_file( $file ) ? Spyc::YAMLLoad( $file ) : [];

		parent::__construct( $config );

		$this->file = $file;
	}

	public function save() : void {
		$yaml = Spyc::YAMLDump( $this->getArrayCopy() );

		if ( ! \file_put_contents( $this->file, $yaml ) ) {
			throw new Exception( "Failed to save config to '$this->file'." );
		}
	}
}
