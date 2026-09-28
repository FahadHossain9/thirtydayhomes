<?php
/**
 * The place a renter typed into the search box.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Turns "15226", "Squirrel Hill" or "Greensburg" into a point on the map.
 *
 * ─── WHY THIS EXISTS ───────────────────────────────────────────────────────
 *
 * The search box used to answer a postcode by matching the digits against
 * each home's stored ZIP. Every home is in a different postcode, so almost
 * any real postcode a renter typed answered "No homes matching 15226" —
 * while the site's own Renter FAQ promised "when you search by location or
 * ZIP code, homes appear closest to farthest". The page contradicted the
 * page. Rob found it himself on 22 September 2026 (register R44/R18).
 *
 * A postcode is a *place*, not a label to match. Once it is a point, the
 * question "which homes are nearest?" has an answer even when the postcode
 * itself holds none — which is the whole of what was asked for.
 *
 * ─── WHAT IT WILL AND WILL NOT ANSWER ──────────────────────────────────────
 *
 * Only postcodes, towns, neighbourhoods and counties come back. A street
 * address does not: a renter typing one is describing a building, and
 * ordering the whole marketplace around somebody's front door is both a
 * stranger answer and a needless thing to send to Google. A home's own name
 * does not either, so searching for a home by name keeps finding the home.
 *
 * ─── COST ──────────────────────────────────────────────────────────────────
 *
 * One lookup per distinct search term, then nothing for a month. Repeats,
 * paging and the other filters all read the cache, so a busy day of "15226"
 * costs a single request. Misses are remembered too — briefly — so a
 * mistyped term cannot be turned into a bill by reloading.
 */
final class Area {

	/** How long a found place is kept. Postcodes do not move. */
	private const KEEP_FOUND = MONTH_IN_SECONDS;

	/** How long "that is not a place" is kept. */
	private const KEEP_MISSING = 12 * HOUR_IN_SECONDS;

	/**
	 * How long a service failure is kept.
	 *
	 * Short on purpose: an expired card or a rate limit is fixed within the
	 * hour, and nobody should have to wait out a month of cached silence.
	 */
	private const KEEP_ERROR = 10 * MINUTE_IN_SECONDS;

	/** Below this, a term is too vague to be a place. */
	private const MIN_LENGTH = 2;

	/*
	 * "_2": answers cached before the service area existed (Oakland, CA for
	 * the Pittsburgh neighbourhood) are never read again.
	 */
	private const PREFIX = 'tdh_area_2_';

	/** Degrees around the facilities that still count as "here" (about 25 miles). */
	private const AREA_PAD = 0.35;

	/** Degrees further out that a found place may lie before it is refused (about 35 miles). */
	private const ACCEPT_PAD = 0.5;

	/**
	 * The service area: a box around the active facilities, padded.
	 *
	 * Built from the client's own hospital list, so it grows by itself the
	 * day a new city's hospitals are added. Before any exist, Pittsburgh.
	 * Google is asked to prefer places inside it, and an answer far outside
	 * it is refused — "Oakland" is a Pittsburgh neighbourhood before it is a
	 * city in California (register R50).
	 *
	 * @return array{south:float,west:float,north:float,east:float}
	 */
	public static function service_bounds(): array {

		static $bounds = null;

		if ( null !== $bounds ) {
			return $bounds;
		}

		$lats = [];
		$lngs = [];

		foreach ( get_posts(
			[
				'post_type'      => Post_Types::FACILITY,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					[ 'key' => '_tdh_active', 'value' => '1' ],
					[ 'key' => '_tdh_active', 'compare' => 'NOT EXISTS' ],
				],
			]
		) as $id ) {
			$lat = get_post_meta( (int) $id, '_tdh_lat', true );
			$lng = get_post_meta( (int) $id, '_tdh_lng', true );
			if ( is_numeric( $lat ) && is_numeric( $lng ) && ( 0.0 !== (float) $lat || 0.0 !== (float) $lng ) ) {
				$lats[] = (float) $lat;
				$lngs[] = (float) $lng;
			}
		}

		$bounds = $lats
			? [ 'south' => min( $lats ) - self::AREA_PAD, 'west' => min( $lngs ) - self::AREA_PAD, 'north' => max( $lats ) + self::AREA_PAD, 'east' => max( $lngs ) + self::AREA_PAD ]
			: [ 'south' => 40.20, 'west' => -80.30, 'north' => 40.70, 'east' => -79.60 ];

		return $bounds;
	}

	/** Is this point close enough to the service area to search around? */
	public static function within_reach( float $lat, float $lng ): bool {
		$b = self::service_bounds();
		return $lat >= $b['south'] - self::ACCEPT_PAD && $lat <= $b['north'] + self::ACCEPT_PAD
			&& $lng >= $b['west'] - self::ACCEPT_PAD && $lng <= $b['east'] + self::ACCEPT_PAD;
	}

	/**
	 * Where the typed term is, or null if it is not a place we can search.
	 *
	 * Null is returned for every unhappy ending — not a place, no key
	 * installed, Google refused, the network failed — because the caller
	 * does the same thing in all of them: search the way it always did.
	 * A renter is never shown an empty page *because* a lookup failed.
	 *
	 * @return array{lat:float,lng:float,label:string}|null
	 */
	public static function point( string $term ): ?array {

		$term = self::normalise( $term );

		if ( '' === $term ) {
			return null;
		}

		$cached = get_transient( self::key( $term ) );

		if ( is_array( $cached ) ) {
			return isset( $cached['lat'], $cached['lng'] ) ? $cached : null;
		}

		// A string in the cache is a remembered "no" — the reason, kept for
		// the log rather than for the renter, who is never shown it.
		if ( is_string( $cached ) && '' !== $cached ) {
			return null;
		}

		return self::ask( $term );
	}

	/**
	 * Ask the provider, remember the answer, hand back the point.
	 *
	 * @return array{lat:float,lng:float,label:string}|null
	 */
	private static function ask( string $term ): ?array {

		$provider = Geocoder::provider();

		if ( ! $provider instanceof Geocode_Provider ) {
			/*
			 * No key installed. Nothing is cached: the moment somebody adds
			 * one, search should start working without a wait.
			 */
			return null;
		}

		$answer = $provider->area( $term );
		$status = (string) ( $answer['status'] ?? '' );

		if ( 'ok' === $status && isset( $answer['lat'], $answer['lng'] ) && ! self::within_reach( (float) $answer['lat'], (float) $answer['lng'] ) ) {
			/*
			 * A real place, but somewhere else — Lawrenceville, GA for the
			 * Pittsburgh neighbourhood. "No homes within 9 miles of
			 * Lawrenceville, GA" helps nobody; matching the words does.
			 */
			set_transient( self::key( $term ), 'outside_area', self::KEEP_MISSING );
			return null;
		}

		if ( 'ok' === $status && isset( $answer['lat'], $answer['lng'] ) ) {

			$point = [
				'lat'   => (float) $answer['lat'],
				'lng'   => (float) $answer['lng'],
				'label' => '' !== (string) ( $answer['label'] ?? '' ) ? (string) $answer['label'] : $term,
			];

			set_transient( self::key( $term ), $point, self::KEEP_FOUND );

			return $point;
		}

		if ( 'error' === $status ) {

			$error = (string) ( $answer['error'] ?? 'unknown' );

			set_transient( self::key( $term ), $error, self::KEEP_ERROR );

			/*
			 * Worth a line in the log: a denied key or a spent quota turns
			 * every location search back into the old behaviour silently,
			 * and silence is exactly how R44 survived five weeks. The term
			 * is what a stranger typed into a public box, so it is not kept.
			 */
			if ( class_exists( '\TDH\Log' ) ) {
				Log::warning(
					'listings',
					'area_lookup_failed',
					'A location search could not be looked up, so it fell back to matching text. Check the Geocoding key and its quota.',
					[ 'reason' => $error ]
				);
			}

			return null;
		}

		set_transient( self::key( $term ), 'not_found', self::KEEP_MISSING );

		return null;
	}

	/**
	 * The term, reduced to the form two renters typing the same place share.
	 *
	 * Case and inner spacing are levelled so "squirrel  hill" and
	 * "Squirrel Hill" are one cached lookup rather than two.
	 */
	private static function normalise( string $term ): string {

		$term = trim( (string) preg_replace( '/\s+/', ' ', $term ) );

		if ( mb_strlen( $term ) < self::MIN_LENGTH ) {
			return '';
		}

		// Nothing but punctuation is not a place.
		return '' === (string) preg_replace( '/[^\p{L}\p{N}]/u', '', $term ) ? '' : $term;
	}

	/** The cache key. Hashed, so a long or odd term cannot overflow it. */
	private static function key( string $term ): string {
		return self::PREFIX . md5( mb_strtolower( $term ) );
	}

	/**
	 * Forget one remembered term.
	 *
	 * For the test suite, and for the day a staff member needs a wrong
	 * answer re-asked without waiting a month.
	 */
	public static function forget( string $term ): void {

		$term = self::normalise( $term );

		if ( '' !== $term ) {
			delete_transient( self::key( $term ) );
		}
	}
}
