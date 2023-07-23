<?php

/**
 * Import WooCommerce products CSV.
 *
 * ## OPTIONS
 *
 * <file>
 * : The CSV file.
 *
 * [--update]
 * : Run the import as an update.
 *
 * [--mapping]
 * : Prompt for mappings. Default false.
 *
 * [--verbose]
 * : Log all messages. Default false.
 *
 * [--delimiter]
 * : Default ,
 *
 * [--enclosure]
 * : Default "
 *
 * [--encoding]
 * : Default UTF-8
 */
WP_CLI::add_command( 'wc import', function ( $args, $assoc_args ) {
	list ( $file ) = $args;

	$file = realpath( $file );

	$mapping = WP_CLI\Utils\get_flag_value( $assoc_args, 'mapping', false );

	$verbose = WP_CLI\Utils\get_flag_value( $assoc_args, 'verbose', false );

	$importer_args = [
		'update_existing'    => WP_CLI\Utils\get_flag_value( $assoc_args, 'update',    false ),
		'delimiter'          => WP_CLI\Utils\get_flag_value( $assoc_args, 'delimiter', ',' ),
		'enclosure'          => WP_CLI\Utils\get_flag_value( $assoc_args, 'enclosure', '"' ),
		'character_encoding' => WP_CLI\Utils\get_flag_value( $assoc_args, 'encoding',  'UTF-8' ),
	];

	if ( ! class_exists( 'WC_Product_CSV_Importer' ) ) {
		if ( ! defined( 'WC_PLUGIN_FILE' ) ) {
			WP_CLI::error( 'WooCommerce not detected.' );
		}

		$class_file = dirname( WC_PLUGIN_FILE ) . '/includes/import/class-wc-product-csv-importer.php';

		if ( ! is_file( $class_file ) ) {
			WP_CLI::error( 'WooCommerce importer not found.' );
		}

		require_once $class_file;
	}

	if ( ! WC_Product_CSV_Importer_Controller::is_file_valid_csv( $file ) ) {
		WP_CLI::error( 'File is not a valid CSV.' );
	}

	if ( ! current_user_can( 'manage_product_terms' ) ) {
		$users = get_users([
			'capability' => 'manage_product_terms',
			'number' => 1,
		]);

		if ( ! $users ) {
			WP_CLI::error( 'Requires a user with "manage_product_terms" capability.' );
		}

		wp_set_current_user( reset( $users )->ID );
	}

	$importer = new WC_Product_CSV_Importer( $file, $importer_args + [
		'lines' => 1,
	]);

	$controller = new class( $importer ) extends WC_Product_CSV_Importer_Controller {

		public $columns = [];

		public function __construct( $importer ) {
			parent::__construct();

			$keys = $importer->get_raw_keys();
			$mapped = $this->auto_map_columns( $keys );
			$sample_data = current( $importer->get_raw_data() );

			if ( empty( $sample_data ) ) {
				WP_CLI::error( 'The file is empty or using a different encoding than UTF-8, please try again with a new file.' );
			}

			foreach ( $keys as $index => $key ) {
				$this->columns[] = [
					$key,
					$mapped[ $index ],
					$sample_data[ $index ] ?? '',
				];
			}
		}

	};

	foreach ( $controller->columns as $column ) {
		list ( $key, $mapped, $sample_data ) = $column;

		if ( $mapping ) {
			$mapped = readline( "$key (e.g. $sample_data) [$mapped] : " ) ?: $mapped;
		}

		$importer_args['mapping'][ $key ] = $mapped;
	}

	$importer_args += [
		'parse' => true,
		'verbose' => $verbose,
		'prevent_timeouts' => false,
	];

	$importer = new class ( $file, $importer_args ) extends WC_Product_CSV_Importer {

		protected $attachment_errors;

		public function import() {
			if ( ! empty( $this->params['verbose'] ) ) {
				add_action( 'wp_error_added', [ $this, 'wp_error_added' ], 10, 3 );

			} else {
				$progress_bar = WP_CLI\Utils\make_progress_bar(
					'Importing products',
					count( $this->parsed_data )
				);

				add_action( 'woocommerce_product_import_before_import', fn () => $progress_bar->tick() );
			}

			$import = parent::import();

			if ( isset( $progress_bar ) ) {
				$progress_bar->finish();
			}

			return $import;
		}

		public function wp_error_added( $code, $message, $data ) {
			if ( $code === 'woocommerce_product_importer_error' && isset( $data['row'] ) ) {
				WP_CLI::log( WP_CLI::colorize( '%CSkipped:%n ' ) . $data['row'] . ". $message" );
			}
		}

		// Only soft-fail attachment downloads, don't let them stop a product being imported
		public function get_attachment_id_from_url( $url, $product_id ) {
			add_filter( 'wp_handle_sideload_prefilter', [ $this, 'sideload_prefilter' ] );

			try {
				$id = parent::get_attachment_id_from_url( $url, $product_id );

			} catch ( Exception $error ) {
				$id = 0;

				$this->attachment_errors[] = [ $url, $error->getMessage() ];
			}

			remove_filter( 'wp_handle_sideload_prefilter', [ $this, 'sideload_prefilter' ] );

			return $id;
		}

		// Not all attachment URLs have an extension, but WordPress needs one to pass a mime check
		public function sideload_prefilter( $file ) {
			if ( ! pathinfo( $file['name'], PATHINFO_EXTENSION ) ) {
				$mime = wp_get_image_mime( $file['tmp_name'] );

				if ( $mime ) {
					$mime_to_ext = [
						'image/jpeg'   => 'jpg',
						'image/gif'    => 'gif',
						'image/png'    => 'png',
						'image/bmp'    => 'bmp',
						'image/tiff'   => 'tiff',
						'image/webp'   => 'webp',
						'image/x-icon' => 'ico',
						'image/heic'   => 'heic',
					];

					$ext = $mime_to_ext[ $mime ] ?? null;

					if ( $ext ) {
						$file['name'] .= ".$ext";
					}
				}
			}

			return $file;
		}

		protected function process_item( $data ) {
			$this->attachment_errors = [];

			$result = parent::process_item( $data );

			if ( empty( $this->params['verbose'] ) ) {
				return $result;
			}

			if ( is_wp_error( $result ) ) {
				$row_id = $this->get_row_id( $data );

				$error_message = $result->get_error_message();

				WP_CLI::error( "$row_id. $error_message", false );

			} else {
				WP_CLI::success(
					sprintf(
						'%s %s #%s (%s)',
						$result['updated'] ? 'Updated' : 'Imported',
						$result['is_variation'] ? 'variation' : 'product',
						$result['id'],
						html_entity_decode( get_the_title( $result['id'] ) )
					)
				);
			}

			foreach ( $this->attachment_errors as $error ) {
				list ( $url, $error ) = $error;

				if ( ! str_contains( $error, $url ) ) {
					$error .= " ($url)";
				}

				WP_CLI::warning( $error );
			}

			return $result;
		}
	};

	$import = $importer->import();

	$counts = array_map( 'count', $import );

	WP_CLI::log(
		sprintf(
			'%d imported, %d updated, %d skipped, %d failed.',
			$counts['imported'] + $counts['imported_variations'],
			$counts['updated'],
			$counts['skipped'],
			$counts['failed']
		)
	);
});
