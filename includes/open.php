<?php

/**
 * Open WordPress in the browser.
 *
 * ## OPTIONS
 *
 * [<args>...]
 * : See examples.
 *
 * ## EXAMPLES
 *
 *     # Open post by ID
 *     $ wp open <id>
 *
 *     # Open post by ID, slug or title
 *     $ wp open <post_type> <id|slug|title>
 *
 *     # Open post type archive
 *     $ wp open <post_type>
 *
 *     # Open term archive
 *     $ wp open term <id|slug|title>
 *
 *     # Open taxonomy term archive
 *     $ wp open <taxonomy> <id|slug|name>
 *
 *     # Open WordPress URL relative to home
 *     $ wp open <path>
 *
 *     # Open admin edit for post ID
 *     $ wp open admin <id>
 *
 *     # Open admin edit for term
 *     $ wp open admin term <id|slug|title>
 *
 *     # Open admin edit screen for post type
 *     $ wp open admin <post_type>
 *
 *     # Open admin edit screen for post
 *     $ wp open admin <post_type> <id|slug|title>
 *
 *     # Open admin edit screen for taxonomy
 *     $ wp open admin <taxonomy>
 *
 *     # Open admin edit screen for taxonomy term
 *     $ wp open admin <taxonomy> <id|slug|name>
 *
 *     # Open admin general settings
 *     $ wp open admin settings
 *
 *     # Open admin page (auto-appends .php)
 *     $ wp open admin themes
 */
WP_CLI::add_command( 'open', function ( $args ) {
	$args = array_pad( $args, 3, null );

	$is_admin = $args[0] === 'admin';

	if ( $is_admin ) {
		array_shift( $args );
	}

	list ( $resource, $object ) = $args;

	if ( ctype_digit( $resource ) || $resource === 'post' || post_type_exists( $resource ) ) {
		if ( ! ctype_digit( $resource ) && empty( $object ) ) {
			if ( $is_admin ) {
				$url = admin_url( $resource === 'post' ? 'edit.php' : "edit.php?post_type=$resource" );
			} else {
				$url = get_post_type_archive_link( $resource ) ?: home_url( "?post_type=$resource" );
			}

		} else {
			if ( ctype_digit( $resource ) ) {
				$post = get_post( $resource );

			} elseif ( empty( $object ) ) {
				WP_CLI::error( 'Post ID, slug or title required.' );

			} elseif ( ctype_digit( $object ) ) {
				$post = get_post( $object );

			} else {
				foreach ( [ 'name', 'title' ] as $query_var ) {
					$posts = get_posts([
						$query_var => $object,
						'post_type' => $resource,
						'post_status' => 'any',
						'posts_per_page' => 1,
						'orderby' => 'ID',
						'order' => 'DESC',
					]);

					if ( $posts ) {
						$post = current( $posts );
						break;
					}
				}
			}

			if ( empty( $post ) ) {
				WP_CLI::error( 'Post not found.' );
			}

			if ( $is_admin ) {
				add_filter( 'map_meta_cap', '__return_empty_array' );

				$url = get_edit_post_link( $post, 'raw' );

			} else {
				$url = get_permalink( $post );
			}
		}

	} elseif ( $resource === 'term' || taxonomy_exists( $resource ) ) {
		if ( empty( $object ) ) {
			if ( ! $is_admin || $resource === 'term' ) {
				WP_CLI::error( 'Term ID, slug or name required.' );

			} elseif ( $is_admin ) {
				$url = admin_url( "edit-tags.php?taxonomy=$resource" );
			}

		} else {
			if ( $resource === 'term' ) {
				$resource = get_taxonomies();
			}

			foreach ( (array) $resource as $taxonomy ) {
				if ( ctype_digit( $object ) ) {
					$term = get_term( (int) $object, $taxonomy );
				} else {
					$term = get_term_by( 'slug', $object, $taxonomy );

					if ( ! $term instanceof WP_Term ) {
						$term = get_term_by( 'name', $object, $taxonomy );
					}
				}

				if ( $term instanceof WP_Term ) {
					break;
				}
			}

			if ( empty( $term ) || ! $term instanceof WP_Term ) {
				WP_CLI::error( 'Term not found.' );
			}

			if ( $is_admin ) {
				add_filter( 'map_meta_cap', '__return_empty_array' );

				$url = get_edit_term_link( $term );
			} else {
				$url = get_term_link( $term );
			}
		}

	} elseif ( $is_admin ) {
		if ( $resource === 'settings' ) {
			$url = admin_url( 'options-general.php' );

		} elseif ( $resource && ! preg_match( '/[?.]/', $resource ) ) {
			$url = admin_url( "$resource.php" );

		} elseif ( $resource ) {
			$url = admin_url( $resource );

		} else {
			$url = admin_url( '/' );
		}

	} elseif ( $resource ) {
		$url = home_url( $resource );

	} else {
		$url = home_url( '/' );
	}

	passthru( WP_CLI\Utils\esc_cmd( 'open %s', $url ) );
});
