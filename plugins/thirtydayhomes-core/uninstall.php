<?php
/**
 * Uninstall routine.
 *
 * ─── THIS DELETES NOTHING BY DEFAULT ───────────────────────────────────
 *
 * Deleting a plugin in WordPress is two clicks away from deactivating one,
 * and the difference between them is a client's entire marketplace. So
 * removal here is opt-in: the listings, members, inquiries, facilities and
 * delivery logs survive an uninstall unless someone has deliberately said
 * otherwise.
 *
 * To actually erase everything, set this in wp-config.php first:
 *
 *     define( 'TDH_REMOVE_ALL_DATA_ON_UNINSTALL', true );
 *
 * That constant exists so the destructive path has to be typed out by a
 * person who knows what they are doing, on purpose, in a file that is not
 * the plugin.
 *
 * What always goes: the rewrite-rule cache and our own options. Those are
 * derived state, rebuildable, and worth nothing.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

// Only ever runs from WordPress's own uninstall flow.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/* -------------------------------------------------------------------------
 * Always safe: derived state only.
 * ---------------------------------------------------------------------- */

delete_option( 'tdh_db_version' );
delete_transient( 'tdh_demo_reset_notice' );
flush_rewrite_rules();

/* -------------------------------------------------------------------------
 * Everything below is destructive and opt-in.
 * ---------------------------------------------------------------------- */

if ( ! defined( 'TDH_REMOVE_ALL_DATA_ON_UNINSTALL' ) || ! TDH_REMOVE_ALL_DATA_ON_UNINSTALL ) {
	return;
}

// Content, including attachments owned by listings.
foreach ( [ 'tdh_listing', 'tdh_facility', 'tdh_inquiry' ] as $post_type ) {

	$ids = get_posts(
		[
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		]
	);

	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}
}

// Terms in our taxonomies.
foreach ( [ 'tdh_property_type', 'tdh_neighborhood', 'tdh_amenity', 'tdh_city' ] as $taxonomy ) {

	$terms = get_terms(
		[
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'fields'     => 'ids',
		]
	);

	if ( is_wp_error( $terms ) ) {
		continue;
	}

	foreach ( $terms as $term_id ) {
		wp_delete_term( (int) $term_id, $taxonomy );
	}
}

// Custom tables.
foreach ( [ 'tdh_distances', 'tdh_notifications' ] as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $table );
}

// Roles we created. Administrator keeps its own capabilities — stripping
// those could lock the owner out of their own site.
remove_role( 'tdh_landlord' );
remove_role( 'tdh_demo_admin' );
