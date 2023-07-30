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
WP_CLI::add_command( 'clean', function ( $types ) {
	global $wpdb;

	$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

	$actions = [
		'posts' => [
			'count' => <<<SQL
				SELECT COUNT(*) FROM $wpdb->posts
				WHERE post_type IN( $placeholders )
				SQL,
			'delete' => <<<SQL
				DELETE a,b,c FROM $wpdb->posts a
				LEFT JOIN $wpdb->term_relationships b ON (a.ID = b.object_id)
				LEFT JOIN $wpdb->postmeta c ON (a.ID = c.post_id)
				WHERE a.post_type IN( $placeholders )
				SQL,
		],

		'terms' => [
			'count' => <<<SQL
				SELECT COUNT(*) FROM $wpdb->term_taxonomy
				WHERE taxonomy IN( $placeholders )
				SQL,
			'delete' => <<<SQL
				DELETE a,b,c,d FROM $wpdb->term_taxonomy a
				LEFT JOIN $wpdb->terms b ON (a.term_id = b.term_id)
				LEFT JOIN $wpdb->term_relationships c ON (a.term_taxonomy_id = c.term_taxonomy_id)
				LEFT JOIN $wpdb->termmeta d ON (a.term_id = d.term_id)
				WHERE a.taxonomy IN( $placeholders )
				SQL,
		],
	];

	foreach ( $actions as $type => $query ) {
		$count = $wpdb->get_var( $wpdb->prepare( $query['count'], ...$types ) );

		if ( $count ) {
			$wpdb->query( $wpdb->prepare( $query['delete'], ...$types ) );

			WP_CLI::success( "Deleted $count $type." );
		}
	}

	foreach ( $types as $type ) {
		delete_option( $type . '_children' );
	}

	wp_cache_flush();
});
