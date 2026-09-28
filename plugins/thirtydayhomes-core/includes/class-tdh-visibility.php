<?php
/**
 * The public visibility rule.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * ONE definition of "is this listing public", enforced server-side.
 *
 * A listing is public when:
 *   - its status is 'publish', AND
 *   - its author holds an active membership, or is inside the grace window.
 *
 * Nothing in a template, a widget or an Elementor query is allowed to
 * reimplement this. The prototype's client-side `status === 'live'` check
 * is exactly what this replaces.
 *
 * The membership half is filterable because the subscription layer is a
 * kickoff decision (Paid Memberships Pro vs WooCommerce Subscriptions —
 * see DEVELOPMENT_PLAN.md §2.4). Whichever is chosen wires itself into
 * `tdh_inactive_member_ids` and nothing else in the codebase changes.
 */
final class Visibility {

	/** Failed payment keeps listings public for this many days. */
	public const GRACE_DAYS = 7;

	public function register(): void {
		add_action( 'pre_get_posts', [ $this, 'filter_public_queries' ] );
		add_filter( 'rest_prepare_' . Post_Types::LISTING, [ $this, 'strip_private_meta' ], 10, 3 );
	}

	/**
	 * Constrain every public-facing listing query.
	 */
	public function filter_public_queries( \WP_Query $query ): void {

		// wp-admin screens must see every status — that is the whole point
		// of the moderation queue. Front-end AJAX is still filtered, since
		// faceted search runs through admin-ajax.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		// WP-CLI is an administrative context by definition. Filtering it
		// makes `wp post list --post_status=any` silently lie, which would
		// mislead whoever maintains this next.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		// REST requests are authenticated and capability-checked in their
		// own right; private meta is stripped separately below.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		if ( ! $this->is_listing_query( $query ) ) {
			return;
		}

		// Secondary queries opt out explicitly — a landlord dashboard needs
		// to see its own drafts, and the admin queue needs to see pending.
		if ( true === $query->get( 'tdh_bypass_visibility' ) ) {
			return;
		}

		if ( self::permits_preview( $query ) ) {
			return;
		}

		$query->set( 'post_status', Statuses::public_status() );

		$inactive = self::inactive_member_ids();
		if ( $inactive ) {
			$existing = (array) $query->get( 'author__not_in' );
			$query->set( 'author__not_in', array_values( array_unique( array_merge( $existing, $inactive ) ) ) );
		}
	}

	/**
	 * May this request preview a listing that is not public?
	 *
	 * The approval queue links every waiting home to its preview, and the
	 * link opened a bare "Page not found": this rule forced every front-end
	 * listing query to `publish`, staff included, so the one person who
	 * needed to see an unapproved home before deciding could not.
	 *
	 * Narrow on purpose. Only the MAIN query of a single-listing PREVIEW
	 * request, and only for someone WordPress already lets edit that exact
	 * listing — its landlord, or staff. Everyone else still gets not-found,
	 * and nothing about who owns what is revealed. WordPress core then
	 * applies its own protected-status check on top.
	 */
	public static function permits_preview( \WP_Query $query ): bool {

		if ( ! $query->is_main_query() || ! $query->is_preview() || ! is_user_logged_in() ) {
			return false;
		}

		$id   = (int) $query->get( 'p' );
		$post = $id > 0 ? get_post( $id ) : null;

		/*
		 * A home that was live once — paused, sent back, held for billing —
		 * keeps its pretty address, so WordPress builds its preview link as
		 * /homes/its-name/?preview=true, with no id in it. Found by name.
		 */
		$name = (string) $query->get( 'name' );

		if ( ! $post && '' !== $name && Post_Types::LISTING === $query->get( 'post_type' ) ) {
			$post = get_page_by_path( $name, OBJECT, Post_Types::LISTING );
			$id   = $post instanceof \WP_Post ? (int) $post->ID : 0;
		}

		return $post instanceof \WP_Post
			&& Post_Types::LISTING === $post->post_type
			&& current_user_can( 'edit_post', $id );
	}

	/**
	 * Is this query asking for listings?
	 */
	private function is_listing_query( \WP_Query $query ): bool {
		$post_type = $query->get( 'post_type' );

		if ( is_array( $post_type ) ) {
			return in_array( Post_Types::LISTING, $post_type, true );
		}

		if ( Post_Types::LISTING === $post_type ) {
			return true;
		}

		// Archive and taxonomy requests do not set post_type explicitly.
		return $query->is_main_query()
			&& ( $query->is_post_type_archive( Post_Types::LISTING )
				|| $query->is_tax( [ Post_Types::TAX_TYPE, Post_Types::TAX_NEIGHBORHOOD, Post_Types::TAX_AMENITY, Post_Types::TAX_CITY ] ) );
	}

	/**
	 * Users whose listings must not appear publicly right now.
	 *
	 * Returns an empty array until the subscription layer is wired in
	 * Milestone 1, which is the correct fail-open behaviour for a site
	 * with no members yet. Once wired, it fails closed by listing every
	 * lapsed member.
	 *
	 * @return int[]
	 */
	public static function inactive_member_ids(): array {
		/**
		 * Filter the set of users whose listings are hidden for billing.
		 *
		 * The membership module hooks this. Nothing else should.
		 *
		 * @param int[] $ids User IDs with no active membership.
		 */
		$ids = apply_filters( 'tdh_inactive_member_ids', [] );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Is one listing publicly visible?
	 *
	 * Use this anywhere a single listing is about to be rendered.
	 */
	public static function is_public( int $listing_id ): bool {
		$post = get_post( $listing_id );

		if ( ! $post || Post_Types::LISTING !== $post->post_type ) {
			return false;
		}

		if ( Statuses::public_status() !== $post->post_status ) {
			return false;
		}

		return ! in_array( (int) $post->post_author, self::inactive_member_ids(), true );
	}

	/**
	 * Belt and braces: strip private meta from REST responses.
	 *
	 * Fields are already registered with show_in_rest => false, so nothing
	 * should get this far. This exists because the cost of the street
	 * address leaking once is far higher than the cost of checking twice.
	 *
	 * @param \WP_REST_Response $response The response object.
	 * @param \WP_Post          $post     The post.
	 * @param \WP_REST_Request  $request  The request.
	 */
	public function strip_private_meta( $response, $post, $request ) {
		$data = $response->get_data();

		if ( empty( $data['meta'] ) || ! is_array( $data['meta'] ) ) {
			return $response;
		}

		foreach ( Fields::private_keys() as $key ) {
			unset( $data['meta'][ $key ] );
		}

		$response->set_data( $data );

		return $response;
	}
}
