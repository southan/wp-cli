<?php

/**
 * Quickly bulk delete all posts or terms.
 *
 * ## OPTIONS
 *
 * <type>...
 * : Post types, statuses or taxonomies.
 *
 * ## EXAMPLES
 *
 *     # Delete all revisions
 *     $ wp clean revision
 *
 *     # Delete all revisions, drafts and post tags
 *     $ wp clean revision draft post_tag
 */
WP_CLI::add_command( 'clean', function ( $args ) {
	global $wpdb;

	$placeholders = implode( ',', array_fill( 0, count( $args ), '%s' ) );

	$post_ids = $wpdb->get_col( $wpdb->prepare(
		<<<SQL
		SELECT ID FROM $wpdb->posts
		WHERE post_type IN($placeholders)
		OR post_status IN($placeholders)
		SQL,
		...$args,
		...$args
	) );

	if ( $post_ids ) {
		$total = count( $post_ids );

		$progress = $total > 100
			? WP_CLI\Utils\make_progress_bar( 'Deleting posts', $total )
			: null;

		$deleted_count = 0;

		foreach ( $post_ids as $post_id ) {
			$result = wp_delete_post( $post_id, true );

			$progress && $progress->tick();

			if ( ! $result ) {
				WP_CLI::warning( "Could not delete post $post_id." );
			} else {
				$deleted_count++;
			}
		}

		$progress && $progress->finish();

		if ( $deleted_count ) {
			WP_CLI::success( "Deleted $deleted_count posts." );
		}
	}

	$terms = $wpdb->get_results( $wpdb->prepare(
		<<<SQL
		SELECT term_id, taxonomy FROM $wpdb->term_taxonomy
		WHERE taxonomy IN($placeholders)
		SQL,
		...$args
	) );

	if ( $terms ) {
		$total = count( $terms );

		$progress = $total > 100
			? WP_CLI\Utils\make_progress_bar( 'Deleting terms', $total )
			: null;

		$deleted_count = 0;

		foreach ( $terms as $term ) {
			$result = wp_delete_term( $term->term_id, $term->taxonomy );

			$progress && $progress->tick();

			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( "Term $term->term_id: " . WP_CLI::error_to_string( $result ) );
			} elseif ( ! $result ) {
				WP_CLI::warning( "Could not delete term $term->term_id." );
			} else {
				$deleted_count++;
			}
		}

		$progress && $progress->finish();

		if ( $deleted_count ) {
			WP_CLI::success( "Deleted $count terms." );
		}
	}
});
