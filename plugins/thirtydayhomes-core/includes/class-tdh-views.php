<?php
/**
 * Listing view counts.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Counts how often each listing is looked at.
 *
 * ─── WHY THIS EXISTS ───────────────────────────────────────────────────────
 *
 * The approved dashboard design carries a "Listing views" tile. The demo it
 * comes from shows 284 — an invented number, because a prototype is allowed
 * to invent. A live dashboard is not: a landlord makes pricing and pausing
 * decisions off that figure, and a made-up one is worse than none. So before
 * the tile could ship, the number had to become true. This class is that.
 *
 * ─── WHY A BEACON AND NOT THE PAGE LOAD ────────────────────────────────────
 *
 * The obvious implementation — increment on template_redirect — silently
 * undercounts to almost zero, because listing pages are exactly the pages
 * the LiteSpeed cache serves without running PHP. A cache hit is a view
 * that PHP never hears about.
 *
 * So the page carries a tiny script that reports the view to a REST route,
 * which is never page-cached. The script also refuses to re-count the same
 * listing in the same browser session, so one renter flicking back and
 * forth between photos is one view, not nine.
 *
 * ─── WHAT THIS NUMBER IS, AND IS NOT ───────────────────────────────────────
 *
 * It is a count of real page opens by people who are not the owner or the
 * staff. It is not analytics: no visitor identity, no IPs stored, nothing
 * personal — one integer per listing, incremented. Anything more belongs in
 * a real analytics tool, deliberately (§9: minimise personal data).
 */
final class Views {

	public const META = '_tdh_views';

	private const ROUTE_NS = 'tdh/v1';
	private const ROUTE    = '/listing-view';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_beacon' ] );
	}

	public function register_route(): void {
		register_rest_route(
			self::ROUTE_NS,
			self::ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'record' ],
				// Public on purpose: the viewers being counted are logged-out
				// renters. The handler validates everything it is sent.
				'permission_callback' => '__return_true',
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function record( $request ) {

		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		// Only a published listing counts. A pending or paused one is not
		// publicly reachable, so a "view" of it is somebody probing ids.
		if ( ! $post || Post_Types::LISTING !== $post->post_type || 'publish' !== $post->post_status ) {
			return new \WP_REST_Response( null, 204 );
		}

		// The owner checking their own page is not a renter looking at it,
		// and neither is the staff reviewing it. Counting either would let a
		// landlord inflate their own number by refreshing.
		if ( is_user_logged_in() ) {
			$viewer = get_current_user_id();

			if ( (int) $post->post_author === $viewer || user_can( $viewer, 'edit_others_posts' ) ) {
				return new \WP_REST_Response( null, 204 );
			}
		}

		/*
		 * Atomic-ish increment. get + update races under concurrent views and
		 * loses counts; a direct SQL increment does not. An uncounted view on
		 * a listing that has never had one falls through to add.
		 */
		global $wpdb;

		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s",
				$id,
				self::META
			)
		);

		if ( ! $updated ) {
			add_post_meta( $id, self::META, 1, true );
		}

		wp_cache_delete( $id, 'post_meta' );

		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * The beacon, printed only on a single listing.
	 */
	public function enqueue_beacon(): void {

		if ( ! is_singular( Post_Types::LISTING ) ) {
			return;
		}

		$id = (int) get_queried_object_id();

		if ( ! $id ) {
			return;
		}

		$url = rest_url( self::ROUTE_NS . self::ROUTE );

		/*
		 * sendBeacon over fetch: it survives the visitor navigating away
		 * mid-request, and it cannot be awaited, which is the point — the
		 * count must never make the page feel slower.
		 *
		 * sessionStorage guards the per-session dedupe. In a browser that
		 * refuses storage the view counts every load, which errs on the
		 * side the analytics tools err on.
		 */
		$js = sprintf(
			'(function(){try{var k="tdh-viewed-%1$d";if(window.sessionStorage&&sessionStorage.getItem(k)){return}
var d=new Blob([JSON.stringify({id:%1$d})],{type:"application/json"});
if(navigator.sendBeacon&&navigator.sendBeacon(%2$s,d)){if(window.sessionStorage){sessionStorage.setItem(k,"1")}return}
fetch(%2$s,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({id:%1$d}),keepalive:true})
.then(function(){if(window.sessionStorage){sessionStorage.setItem(k,"1")}}).catch(function(){})}catch(e){}})();',
			$id,
			(string) wp_json_encode( $url )
		);

		wp_register_script( 'tdh-view-beacon', '', [], defined( 'TDH_VERSION' ) ? TDH_VERSION : '1.0', true );
		wp_enqueue_script( 'tdh-view-beacon' );
		wp_add_inline_script( 'tdh-view-beacon', $js );
	}

	/**
	 * One listing's count.
	 */
	public static function for_listing( int $listing_id ): int {
		return (int) get_post_meta( $listing_id, self::META, true );
	}

	/**
	 * Every view across one landlord's listings, whatever their status —
	 * a paused home's history did not stop having happened.
	 */
	public static function total_for_author( int $user_id ): int {

		global $wpdb;

		$total = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COALESCE(SUM(m.meta_value+0),0)
				 FROM {$wpdb->postmeta} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_key = %s AND p.post_author = %d AND p.post_type = %s",
				self::META,
				$user_id,
				Post_Types::LISTING
			)
		);

		return (int) $total;
	}
}
