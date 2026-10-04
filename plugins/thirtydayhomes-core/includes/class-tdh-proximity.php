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

	/** Cached nearest-facility result (one row). Replaced by META_LIST. */
	private const META_CACHE = '_tdh_nearest_facility';

	/** Cached nearest facilities, sorted, with their distances. */
	private const META_LIST = '_tdh_nearest_facilities';

	/**
	 * How many facilities the cache holds.
	 *
	 * Never fewer than the largest list a page may show (MAX_COUNT), so the
	 * cached rows are always the candidates for any count and any radius the
	 * administrator sets. Changing a setting then costs no rebuild.
	 */
	private const CACHE_ROWS = 10;

	/** Settings, and the bounds a stored value is read back through. */
	private const OPTION_COUNT  = 'tdh_proximity_count';
	private const OPTION_RADIUS = 'tdh_proximity_radius';
	public const DEFAULT_COUNT  = 3;
	public const DEFAULT_RADIUS = 15;
	public const MAX_COUNT      = 5;
	public const MAX_RADIUS     = 100;

	/** Mean radius of the Earth in miles. */
	private const EARTH_RADIUS_MILES = 3958.7613;

	public function register(): void {
		// Before the hospital band, because when both are on a card the
		// renter's own question ("how far from where I searched?") is the
		// one they are scanning for.
		add_action( 'tdh_listing_card_proximity', [ $this, 'render_area_band' ], 9 );
		add_action( 'tdh_listing_card_proximity', [ $this, 'render_band' ] );
		add_action( 'tdh_listing_proximity', [ $this, 'render_list' ] );

		// A listing that moves, or a facility that opens, closes or moves,
		// invalidates every cached answer that could depend on it.
		add_action( 'save_post_' . Post_Types::LISTING, [ $this, 'clear_listing_cache' ] );
		add_action( 'save_post_' . Post_Types::FACILITY, [ $this, 'clear_all_caches' ] );
		add_action( 'deleted_post', [ $this, 'clear_on_delete' ], 10, 2 );

		// A point can also arrive without a save: the geocoder writes it at
		// the end of a request, and `wp tdh geocode` writes it with no post
		// save at all. Without this, distances stay stale on live until
		// somebody happens to re-save the facility.
		add_action( 'added_post_meta', [ $this, 'clear_on_point' ], 10, 3 );
		add_action( 'updated_post_meta', [ $this, 'clear_on_point' ], 10, 3 );
		add_action( 'deleted_post_meta', [ $this, 'clear_on_point' ], 10, 3 );
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
	 * No radius: the card band answers "what is the nearest hospital", and a
	 * home twenty miles out still has one. The property page's list is the
	 * place where "within 15 miles" applies.
	 *
	 * @return array{id:int,title:string,type:string,miles:float}|null Null when
	 *         the listing has no coordinates or no facility is reachable.
	 */
	public static function nearest( int $listing_id ): ?array {
		return self::ranked( $listing_id )[0] ?? null;
	}

	/**
	 * The nearest facilities, closest first, within a radius.
	 *
	 * @param int|null   $count  How many at most; the setting when null.
	 * @param float|null $radius Miles; the setting when null.
	 * @return array<int,array{id:int,title:string,type:string,miles:float}>
	 *         Empty when the listing has no location or nothing is in range.
	 */
	public static function nearest_n( int $listing_id, ?int $count = null, ?float $radius = null ): array {

		$count  = null === $count ? self::count_setting() : self::clamp_count( $count );
		$radius = null === $radius ? self::radius_setting() : self::clamp_radius( $radius );

		$within = array_filter(
			self::ranked( $listing_id ),
			// Exactly at the radius is within it: "within 15 miles" includes 15.
			static fn( array $row ): bool => $row['miles'] <= $radius
		);

		return array_slice( array_values( $within ), 0, $count );
	}

	/**
	 * Every candidate facility for one listing, closest first.
	 *
	 * Cached in post meta: a grid of twelve cards would otherwise run twelve
	 * facility queries per page load. The cache holds CACHE_ROWS rows, which
	 * is more than any page shows, so the count and radius settings are
	 * applied when reading and changing them rebuilds nothing.
	 *
	 * @return array<int,array{id:int,title:string,type:string,miles:float}>
	 */
	private static function ranked( int $listing_id ): array {

		$cached = get_post_meta( $listing_id, self::META_LIST, true );

		if ( is_array( $cached ) ) {
			return array_map(
				static fn( $row ): array => [
					'id'    => (int) ( $row['id'] ?? 0 ),
					'title' => (string) ( $row['title'] ?? '' ),
					'type'  => (string) ( $row['type'] ?? '' ),
					'miles' => (float) ( $row['miles'] ?? 0 ),
				],
				array_filter( $cached, 'is_array' )
			);
		}

		// An un-geocoded listing gets no distances at all. Showing "0.0 mi"
		// because a coordinate is missing would be worse than showing nothing
		// — and an empty box once saved as 0,0, which is not a place
		// (Geocoder decides).
		$home = Geocoder::coordinates( $listing_id );

		if ( null === $home ) {
			return [];
		}

		$rows = [];

		foreach ( self::active_facilities() as $facility ) {

			$place = Geocoder::coordinates( (int) $facility->ID );

			if ( null === $place ) {
				continue;
			}

			$rows[] = [
				'id'    => (int) $facility->ID,
				'title' => (string) get_the_title( $facility ),
				'type'  => (string) get_post_meta( (int) $facility->ID, '_tdh_facility_type', true ),
				'miles' => self::miles( $home['lat'], $home['lng'], $place['lat'], $place['lng'] ),
			];
		}

		usort( $rows, static fn( array $a, array $b ): int => $a['miles'] <=> $b['miles'] );

		$rows = array_slice( $rows, 0, self::CACHE_ROWS );

		update_post_meta( $listing_id, self::META_LIST, $rows );

		return $rows;
	}

	/** How many facilities a property page lists. */
	public static function count_setting(): int {
		return self::clamp_count( (int) get_option( self::OPTION_COUNT, self::DEFAULT_COUNT ) );
	}

	/** How far out a property page looks, in miles. */
	public static function radius_setting(): float {
		return self::clamp_radius( (float) get_option( self::OPTION_RADIUS, self::DEFAULT_RADIUS ) );
	}

	/**
	 * Store both settings.
	 *
	 * Clamped on the way in as well as on the way out, so a value written by
	 * anything else — a migration, wp-cli, a plugin — can never make a page
	 * list fifty hospitals or none.
	 */
	public static function save_settings( int $count, float $radius ): void {
		update_option( self::OPTION_COUNT, self::clamp_count( $count ) );
		update_option( self::OPTION_RADIUS, self::clamp_radius( $radius ) );
	}

	/** 1 to MAX_COUNT; anything unusable becomes the default. */
	private static function clamp_count( int $count ): int {
		return $count < 1 || $count > self::MAX_COUNT ? self::DEFAULT_COUNT : $count;
	}

	/** 1 to MAX_RADIUS miles; anything unusable becomes the default. */
	private static function clamp_radius( float $radius ): float {
		return $radius < 1 || $radius > self::MAX_RADIUS ? (float) self::DEFAULT_RADIUS : $radius;
	}

	/**
	 * How many live homes list each facility on their property page:
	 * facility id => count. The same answer the pages give (nearest_n(),
	 * with the count and radius settings), so a home whose distances were
	 * never drawn is worked out now and cached, as a page view would.
	 * For the Facilities screen (G5a).
	 *
	 * @return array<int,int>
	 */
	public static function usage_counts(): array {

		$ids = get_posts(
			[
				'post_type'             => Post_Types::LISTING,
				'post_status'           => 'publish',
				'posts_per_page'        => 300,
				'fields'                => 'ids',
				'no_found_rows'         => true,
				'tdh_bypass_visibility' => true,
			]
		);

		$totals = [];

		foreach ( $ids as $listing_id ) {
			foreach ( self::nearest_n( (int) $listing_id ) as $row ) {
				$totals[ $row['id'] ] = ( $totals[ $row['id'] ] ?? 0 ) + 1;
			}
		}

		return $totals;
	}

	/**
	 * Every active facility.
	 *
	 * @return \WP_Post[]
	 */
	public static function facilities(): array {
		return self::active_facilities();
	}

	/**
	 * A radius as a bare number for a URL: 15, 2.5.
	 *
	 * Not `miles_phrase()`, which adds the word, and not `format_miles()`,
	 * which abbreviates it. A query string wants the number alone.
	 */
	public static function miles_number( float $miles ): string {
		return fmod( $miles, 1.0 ) > 0 ? rtrim( rtrim( number_format( $miles, 1, '.', '' ), '0' ), '.' ) : (string) (int) $miles;
	}

	/**
	 * @return \WP_Post[]
	 */
	private static function active_facilities(): array {

		/**
		 * The query behind every distance on the site.
		 *
		 * @param array<string,mixed> $args WP_Query arguments.
		 */
		$args = apply_filters(
			'tdh_facility_query_args',
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

		$facilities = get_posts( is_array( $args ) ? $args : [] );

		return is_array( $facilities ) ? $facilities : [];
	}

	/**
	 * The sand-coloured band on a listing card.
	 *
	 * Prints nothing when there is no answer. An empty band is a layout
	 * hole; a fabricated distance is a lie. Silence is the third option
	 * and the correct one.
	 *
	 * When the renter has chosen a hospital (task C3) the band measures to
	 * THAT one instead of to this home's own nearest. Reading "0.4 mi from
	 * Shadyside" down a list you asked to sort by distance from Mercy is
	 * the defect the search was built to remove, so the two must agree.
	 */
	/**
	 * How far this home is from the place the renter typed.
	 *
	 * Only when they typed one. A list ordered by distance has to say what
	 * the distance is, or the order looks arbitrary and the renter has no
	 * way to judge whether the third home is a mile or an hour further out
	 * than the first.
	 *
	 * Kept apart from the hospital band on purpose: a postcode is not a
	 * hospital, and the two answer different questions, so a card in a
	 * postcode search shows both — how far from where they searched, and
	 * how far from care.
	 */
	public function render_area_band( int $listing_id ): void {

		if ( ! class_exists( '\TDH\Search' ) ) {
			return;
		}

		$f = Search::filters();

		// A chosen hospital already puts a distance on the card, measured
		// from the hospital. Two distances on one card is a puzzle.
		if ( null === ( $f['area'] ?? null ) || null !== $f['facility'] ) {
			return;
		}

		$miles = Search::miles_to_choice( $listing_id );

		// Here for some other reason, or never geocoded: no invented number.
		if ( null === $miles ) {
			return;
		}

		?>
		<p class="from-area">
			<?php
			if ( function_exists( 'tdh_the_icon' ) ) {
				tdh_the_icon( 'map-pin', 16 );
			}
			printf(
				/* translators: 1: distance in miles, 2: the postcode or town the renter typed */
				esc_html__( '%1$s from %2$s', 'thirtydayhomes' ),
				'<b>' . esc_html( self::format_miles( $miles ) ) . '</b>', // phpcs:ignore WordPress.Security.EscapeOutput
				esc_html( Search::anchor_label() )
			);
			?>
		</p>
		<?php
	}

	public function render_band( int $listing_id ): void {

		$title = '';
		$miles = null;

		if ( class_exists( '\TDH\Search' ) ) {

			$chosen = Search::filters()['facility'] ?? null;

			if ( $chosen instanceof \WP_Post ) {
				$title = $chosen->post_title;
				$miles = Search::miles_to_choice( $listing_id );
			}
		}

		if ( '' === $title ) {

			$nearest = self::nearest( $listing_id );

			if ( null === $nearest ) {
				return;
			}

			$title = (string) $nearest['title'];
			$miles = (float) $nearest['miles'];
		}

		// A chosen hospital and a home with no point: the home is here for
		// some other reason, and we do not invent a distance for it.
		if ( null === $miles ) {
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
				'<b>' . esc_html( self::format_miles( $miles ) ) . '</b>', // phpcs:ignore WordPress.Security.EscapeOutput
				esc_html( $title )
			);
			?>
		</p>
		<?php
	}

	/**
	 * The nearest facilities on the property page, under "Close to care".
	 *
	 * A home with no location prints nothing: the section still has its map
	 * note, and an empty list would only raise a question it cannot answer.
	 * A home with a location but nothing in range says so — hiding it would
	 * read as "we forgot", and a renter choosing on hospital distance needs
	 * to know the answer is "none this close", not silence.
	 */
	public function render_list( int $listing_id ): void {
		echo self::list_html( $listing_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}

	/**
	 * The same list, returned rather than printed.
	 *
	 * The action callback above echoes, because that is what a `do_action`
	 * hook wants; the Elementor widget and the shortcode want a string, and
	 * one of them wants a different number of rows. Splitting it this way
	 * keeps a single copy of the markup: the day a row changes, it changes
	 * in one place and the property page and the widget cannot disagree.
	 *
	 * @param int|null $count How many to list, or null for the staff
	 *                        setting. Clamped to the same 1–5 staff are
	 *                        held to, so a widget cannot quietly promise
	 *                        more than the product supports.
	 */
	public static function list_html( int $listing_id, ?int $count = null ): string {

		if ( null === Geocoder::coordinates( $listing_id ) ) {
			return '';
		}

		$near  = self::nearest_n( $listing_id, $count );
		$miles = self::miles_phrase( self::radius_setting() );

		ob_start();

		if ( ! $near ) {
			// Nothing in range: the radius is the honest number here.
			?>
			<p class="facility-none">
				<?php
				printf(
					/* translators: %s: a distance, already written with its unit ("15 miles") */
					esc_html__( 'No medical facilities within %s of this home.', 'thirtydayhomes' ),
					esc_html( $miles )
				);
				?>
			</p>
			<?php
			return (string) ob_get_clean();
		}

		$types = self::type_labels();

		/*
		 * The heading states the real distance, not the staff radius (G3a
		 * design review): "3 medical facilities within 1.7 miles" tells a
		 * renter something "within 15 miles" did not, because the three
		 * listed ARE within 1.7 miles. The farthest listed one sets it.
		 */
		$far = max( array_map( static fn( array $row ): float => (float) $row['miles'], $near ) );
		?>
		<div class="facility-list">
			<h3 class="facility-lead">
				<?php
				printf(
					/* translators: 1: number of facilities, 2: a distance with its unit ("1.7 miles") */
					esc_html( _n( '%1$s medical facility within %2$s', '%1$s medical facilities within %2$s', count( $near ), 'thirtydayhomes' ) ),
					esc_html( number_format_i18n( count( $near ) ) ),
					esc_html( self::within_phrase( $far ) )
				);
				?>
			</h3>

			<ul>
				<?php foreach ( $near as $row ) : ?>
					<li>
						<?php
						/*
						 * Decorative, and hidden from a screen reader by the
						 * helper: the facility's name and type are already
						 * beside it in words.
						 */
						if ( function_exists( 'tdh_the_icon' ) ) {
							tdh_the_icon( 'stethoscope', 18 );
						}
						?>
						<span>
							<b><?php echo esc_html( $row['title'] ); ?></b>
							<?php if ( isset( $types[ $row['type'] ] ) ) : ?>
								<small><?php echo esc_html( $types[ $row['type'] ] ); ?></small>
							<?php endif; ?>
						</span>
						<b class="facility-miles"><?php echo esc_html( self::format_miles( $row['miles'] ) ); ?></b>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Facility types in words, from the one schema that defines them.
	 *
	 * @return array<string,string>
	 */
	private static function type_labels(): array {
		$options = Fields::facility_schema()['_tdh_facility_type']['options'] ?? [];

		return is_array( $options ) ? $options : [];
	}

	/**
	 * A radius written the way it is read: "15 miles", "2.5 miles", "1 mile".
	 *
	 * One mile is a setting a staff member can choose, so the singular is not
	 * hypothetical — and "within 1 miles" on every property page of a live
	 * marketplace is the kind of detail that makes the rest look careless.
	 */
	public static function miles_phrase( float $miles ): string {

		$written = number_format_i18n( $miles, fmod( $miles, 1.0 ) > 0 ? 1 : 0 );

		return sprintf(
			/* translators: %s: a distance in miles */
			_n( '%s mile', '%s miles', 1.0 === $miles ? 1 : 2, 'thirtydayhomes' ),
			$written
		);
	}

	/**
	 * "1.7 miles": the smallest round distance a real one is within.
	 *
	 * Rounded UP, never down — a facility 1.63 miles away is not "within
	 * 1.6 miles". One decimal under ten miles, whole miles above, the same
	 * precision format_miles() shows beside each name, so the heading and
	 * the rows can never disagree. Never less than a tenth of a mile, so a
	 * facility on the doorstep does not read as "within 0 miles".
	 */
	public static function within_phrase( float $far ): string {

		$rounded = $far < 10 ? ceil( $far * 10 ) / 10 : ceil( $far );

		return self::miles_phrase( max( 0.1, $rounded ) );
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
		delete_post_meta( $post_id, self::META_LIST );
		delete_post_meta( $post_id, self::META_CACHE );
	}

	/**
	 * A coordinate was written or removed outside a post save.
	 *
	 * @param int|int[] $meta_id   Ignored; deleted_post_meta passes an array.
	 * @param int       $object_id The post the meta belongs to.
	 * @param string    $meta_key  The meta key that changed.
	 */
	public function clear_on_point( $meta_id, int $object_id, string $meta_key ): void {

		if ( ! in_array( $meta_key, [ '_tdh_lat', '_tdh_lng' ], true ) ) {
			return;
		}

		$type = (string) get_post_type( $object_id );

		if ( Post_Types::LISTING === $type ) {
			$this->clear_listing_cache( $object_id );
			return;
		}

		// One facility's move changes an unknowable set of listings, so every
		// cached answer goes. A run of `wp tdh geocode` writes two keys per
		// facility and so calls this many times; after the first, the query
		// below finds nothing left to delete, which is the cheap case.
		if ( Post_Types::FACILITY === $type ) {
			$this->clear_all_caches();
		}
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

		// Found by query, then deleted one at a time through the API. A
		// single DELETE would be one statement instead of a handful, but it
		// leaves the object cache holding rows the database no longer has —
		// and this runs in the same request as the geocoder's writes, so the
		// next read would answer from that stale cache. Facilities change a
		// few times a year; this is not a hot path.
		$listing_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s )",
				self::META_LIST,
				self::META_CACHE
			)
		);

		foreach ( (array) $listing_ids as $listing_id ) {
			$this->clear_listing_cache( (int) $listing_id );
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
