<?php
/**
 * Distance from a listing to the nearest medical facility.
 *
 * PLUGIN, not theme. "How far is this home from a hospital" is the single
 * claim this marketplace is built to make — travel nurses choose on it. It
 * is derived from stored coordinates and it must survive a theme change,
 * so it lives here. The theme only decides what the band looks like.
 *
 * The result is cached in post meta rather than recomputed per render: a
 * grid of 12 cards would otherwise run 12 facility queries per page load.
 * The cache is invalidated whenever a listing moves or a facility changes.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Nearest-facility lookup and the card band that displays it.
 */
final class Proximity {

	/** Cached nearest-facility result. */
	private const META_CACHE = '_tdh_nearest_facility';

	/** Mean radius of the Earth in miles. */
	private const EARTH_RADIUS_MILES = 3958.7613;

	public function register(): void {
		add_action( 'tdh_listing_card_proximity', [ $this, 'render_band' ] );

		// A listing that moves, or a facility that opens, closes or moves,
		// invalidates every cached answer that could depend on it.
		add_action( 'save_post_' . Post_Types::LISTING, [ $this, 'clear_listing_cache' ] );
		add_action( 'save_post_' . Post_Types::FACILITY, [ $this, 'clear_all_caches' ] );
		add_action( 'deleted_post', [ $this, 'clear_on_delete' ], 10, 2 );
	}

	/**
	 * Great-circle distance in miles.
	 *
	 * Haversine, not the flat-Earth Pythagorean shortcut. Over Pittsburgh
	 * the difference is small, but the shortcut needs a cos(latitude)
	 * correction that is easy to omit and silently wrong — and this number
	 * is the one a nurse picks a home on.
	 */
	public static function miles( float $lat1, float $lng1, float $lat2, float $lng2 ): float {

		$d_lat = deg2rad( $lat2 - $lat1 );
		$d_lng = deg2rad( $lng2 - $lng1 );

		$a = sin( $d_lat / 2 ) ** 2
			+ cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $d_lng / 2 ) ** 2;

		return self::EARTH_RADIUS_MILES * 2 * asin( min( 1.0, sqrt( $a ) ) );
	}

	/**
	 * The nearest active facility to a listing.
	 *
	 * @return array{id:int,title:string,miles:float}|null Null when the
	 *         listing has no coordinates or no facility is reachable.
	 */
	public static function nearest( int $listing_id ): ?array {

		$cached = get_post_meta( $listing_id, self::META_CACHE, true );
		if ( is_array( $cached ) && isset( $cached['id'], $cached['title'], $cached['miles'] ) ) {
			return [
				'id'    => (int) $cached['id'],
				'title' => (string) $cached['title'],
				'miles' => (float) $cached['miles'],
			];
		}

		$lat = get_post_meta( $listing_id, '_tdh_lat', true );
		$lng = get_post_meta( $listing_id, '_tdh_lng', true );

		// An un-geocoded listing gets no band. Showing "0.0 mi" because a
		// coordinate is missing would be worse than showing nothing.
		if ( '' === $lat || '' === $lng || null === $lat || null === $lng ) {
			return null;
		}

		$best = null;

		foreach ( self::active_facilities() as $facility ) {

			$f_lat = get_post_meta( $facility->ID, '_tdh_lat', true );
			$f_lng = get_post_meta( $facility->ID, '_tdh_lng', true );

			if ( '' === $f_lat || '' === $f_lng ) {
				continue;
			}

			$miles = self::miles( (float) $lat, (float) $lng, (float) $f_lat, (float) $f_lng );

			if ( null === $best || $miles < $best['miles'] ) {
				$best = [
					'id'    => (int) $facility->ID,
					'title' => (string) get_the_title( $facility ),
					'miles' => $miles,
				];
			}
		}

		if ( null === $best ) {
			return null;
		}

		update_post_meta( $listing_id, self::META_CACHE, $best );

		return $best;
	}

	/**
	 * Every active facility.
	 *
	 * @return \WP_Post[]
	 */
	private static function active_facilities(): array {

		$facilities = get_posts(
			[
				'post_type'        => Post_Types::FACILITY,
				'post_status'      => 'publish',
				'posts_per_page'   => 200,
				'orderby'          => 'menu_order',
				'order'            => 'ASC',
				'suppress_filters' => false,
				'meta_query'       => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					[
						'key'     => '_tdh_active',
						'value'   => '1',
						'compare' => '=',
					],
					[
						'key'     => '_tdh_active',
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);

		return is_array( $facilities ) ? $facilities : [];
	}

	/**
	 * The sand-coloured band on a listing card.
	 *
	 * Prints nothing when there is no answer. An empty band is a layout
	 * hole; a fabricated distance is a lie. Silence is the third option
	 * and the correct one.
	 */
	public function render_band( int $listing_id ): void {

		$nearest = self::nearest( $listing_id );

		if ( null === $nearest ) {
			return;
		}

		?>
		<p class="hospital">
			<?php
			if ( function_exists( 'tdh_the_icon' ) ) {
				tdh_the_icon( 'stethoscope', 16 );
			}
			printf(
				/* translators: 1: distance in miles, 2: facility name */
				esc_html__( '%1$s from %2$s', 'thirtydayhomes' ),
				'<b>' . esc_html( self::format_miles( $nearest['miles'] ) ) . '</b>', // phpcs:ignore WordPress.Security.EscapeOutput
				esc_html( $nearest['title'] )
			);
			?>
		</p>
		<?php
	}

	/**
	 * "0.4 mi".
	 *
	 * One decimal under ten miles, none above: "12.3 mi from UPMC Mercy"
	 * implies a precision the geocoding does not have, and nobody choosing
	 * a home twelve miles out cares about the tenth.
	 */
	public static function format_miles( float $miles ): string {

		$decimals = $miles < 10 ? 1 : 0;

		return sprintf(
			/* translators: %s: distance in miles */
			__( '%s mi', 'thirtydayhomes' ),
			number_format_i18n( $miles, $decimals )
		);
	}

	/**
	 * Drop one listing's cached answer.
	 */
	public function clear_listing_cache( int $post_id ): void {
		delete_post_meta( $post_id, self::META_CACHE );
	}

	/**
	 * Drop every cached answer.
	 *
	 * A facility moving or closing changes the nearest facility for an
	 * unknowable subset of listings, so the whole cache goes. Facilities
	 * change a handful of times a year; this is not a hot path.
	 */
	public function clear_all_caches(): void {
		global $wpdb;

		$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => self::META_CACHE ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.DirectDatabaseQuery

		// A direct DELETE leaves the object cache holding rows that no
		// longer exist. Group flushing is WP 6.1+ and optional even then,
		// so fall back to invalidating all post meta.
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'post_meta' );
		} else {
			wp_cache_set_posts_last_changed();
		}
	}

	/**
	 * A deleted facility invalidates the cache the same way an edited one does.
	 */
	public function clear_on_delete( int $post_id, $post = null ): void {
		if ( $post instanceof \WP_Post && Post_Types::FACILITY === $post->post_type ) {
			$this->clear_all_caches();
		}
	}
}
