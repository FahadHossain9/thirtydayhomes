<?php
/**
 * Maps: the browser key, and the only coordinates a browser is ever given.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * What the map is allowed to know.
 *
 * ─── THE RULE THIS CLASS EXISTS TO KEEP ────────────────────────────────────
 *
 * A furnished home stands empty between stays. Publishing its address tells
 * anyone reading which door is unlocked for the next three weeks, so the
 * exact point never leaves the server — not in the page source, not in a
 * data attribute, not in a REST response, not inside a hidden element.
 *
 * What a browser gets instead is {@see approximate()}: the real point moved
 * by up to {@see OFFSET_MAX} metres in a direction nobody can work out, then
 * rounded. The page draws a circle {@see CIRCLE_METRES} metres wide around
 * that, which is wider than the error, so the home is plausibly anywhere
 * inside it and the circle tells a reader nothing the neighbourhood name
 * did not already tell them.
 *
 * ─── WHY THE OFFSET IS SALTED ──────────────────────────────────────────────
 *
 * The task card asked for an offset derived from the listing id. On its own
 * that is reversible: anyone who can read this file can work out the shift
 * for id 412 and subtract it, which would hand back the exact address the
 * feature exists to hide. The direction and distance therefore come from
 * `wp_hash()`, which mixes in the site's own salts — unknowable from
 * outside, identical on every request, so a home's circle never wanders
 * between page loads.
 *
 * Facilities are different and deliberately so: a hospital is a public
 * building, its coordinates are public meta already, and a renter choosing
 * on distance needs to see exactly where it is.
 */
final class Maps {

	/** The browser key. Referrer-restricted, and printed into the page. */
	private const KEY_CONST = 'TDH_MAPS_BROWSER_KEY';

	/** How far a published point may sit from the real one, in metres. */
	public const OFFSET_MIN = 80;
	public const OFFSET_MAX = 250;

	/**
	 * The circle drawn around it, in metres — about a quarter of a mile,
	 * which is the figure the client signed off (plan §4, criterion 9).
	 * It must stay larger than OFFSET_MAX: a circle smaller than the error
	 * would be a promise the data cannot keep.
	 */
	public const CIRCLE_METRES = 400;

	/**
	 * Decimal places kept after the offset. Three is about 110 metres, so
	 * even the offset's own precision is thrown away before publishing.
	 */
	private const ROUND_TO = 3;

	/**
	 * The most circles drawn at once. Past this the map stops being a map
	 * and starts being a smear, and the page says so instead of drawing it.
	 */
	public const MARKER_CAP = 100;

	/** Metres in one degree of latitude. Near enough anywhere on land. */
	private const METRES_PER_DEGREE = 111320.0;

	/* ---------------------------------------------------------------------
	 * The key
	 * ------------------------------------------------------------------ */

	/**
	 * The browser key, or ''.
	 *
	 * Unlike the server key this one is meant to be seen — it is restricted
	 * to this site's own pages at Google's end, which is the only thing
	 * that protects it. It still comes from wp-config and never from the
	 * database, so it cannot be changed by anyone with a login.
	 */
	public static function key(): string {
		return defined( self::KEY_CONST ) ? trim( (string) constant( self::KEY_CONST ) ) : '';
	}

	/** Is there a key to draw maps with? */
	public static function configured(): bool {
		return '' !== self::key();
	}

	/**
	 * The Maps JavaScript loader, or ''.
	 *
	 * `loading=async` is Google's own recommendation and silences the
	 * console warning; the callback is defined by the theme's map.js, which
	 * is enqueued first.
	 */
	public static function script_url(): string {

		if ( ! self::configured() ) {
			return '';
		}

		return add_query_arg(
			[
				'key'      => rawurlencode( self::key() ),
				'loading'  => 'async',
				'callback' => 'tdhMapsReady',
				'v'        => 'weekly',
			],
			'https://maps.googleapis.com/maps/api/js'
		);
	}

	/**
	 * What places.js needs for address suggestions, or [] without a key.
	 *
	 * The loader calls tdhPlacesReady rather than the map's callback: the
	 * forms that suggest addresses never draw a map. Suggestions lean
	 * towards the service area (the box round the client's hospitals) but
	 * still answer for anywhere in the US.
	 *
	 * Requires "Places API (New)" switched on for the browser key's Google
	 * project; until it is, the fields stay plain text boxes.
	 *
	 * @return array<string,mixed>
	 */
	public static function places_settings(): array {

		if ( ! self::configured() ) {
			return [];
		}

		return [
			'src'    => add_query_arg(
				[
					'key'      => rawurlencode( self::key() ),
					'loading'  => 'async',
					'callback' => 'tdhPlacesReady',
					'v'        => 'weekly',
				],
				'https://maps.googleapis.com/maps/api/js'
			),
			'bounds' => Area::service_bounds(),
			'words'  => [
				'list'      => __( 'Suggested addresses', 'thirtydayhomes' ),
				'credit'    => __( 'Suggestions by Google', 'thirtydayhomes' ),
				'found'     => __( 'Address found. It will be placed on the map when you save.', 'thirtydayhomes' ),
				'noNumber'  => __( 'That suggestion has no house number. Pick the exact address, with its number.', 'thirtydayhomes' ),
				/* translators: %s: town name */
				'noCity'    => __( '%s isn’t on our city list yet. Choose the nearest city — our team can add it.', 'thirtydayhomes' ),
				'thisTown'  => __( 'This town', 'thirtydayhomes' ),
				'pickHint'  => __( 'Pick your address from the list so we can place it on the map.', 'thirtydayhomes' ),
				'failed'    => __( 'Google didn’t answer. Type the address in full instead.', 'thirtydayhomes' ),
				'pointSet'  => __( 'Coordinates filled in from Google. Press Save location.', 'thirtydayhomes' ),
			],
		];
	}

	/* ---------------------------------------------------------------------
	 * The only coordinates a browser sees
	 * ------------------------------------------------------------------ */

	/**
	 * A listing's published point: near the home, never the home.
	 *
	 * @return array{lat:float,lng:float}|null Null when the home has no
	 *                                         point at all — which is not
	 *                                         the same as a point we will
	 *                                         not publish, and the caller
	 *                                         must say so differently.
	 */
	public static function approximate( int $listing_id ): ?array {

		$exact = Geocoder::coordinates( $listing_id );

		if ( null === $exact ) {
			return null;
		}

		/*
		 * Two independent numbers from one salted hash: a bearing and a
		 * distance. The square root spreads the points evenly over the
		 * disc instead of bunching them at the centre, which matters —
		 * a cluster of homes all nudged the same tiny amount would leave
		 * their true positions obvious from the pattern.
		 */
		$seed     = wp_hash( 'tdh-approximate-location-' . $listing_id );
		$bearing  = ( (float) hexdec( substr( $seed, 0, 8 ) ) / 4294967295.0 ) * 2 * M_PI;
		$fraction = (float) hexdec( substr( $seed, 8, 8 ) ) / 4294967295.0;
		$metres   = self::OFFSET_MIN + sqrt( $fraction ) * ( self::OFFSET_MAX - self::OFFSET_MIN );

		$delta_lat = ( $metres * cos( $bearing ) ) / self::METRES_PER_DEGREE;

		/*
		 * Degrees of longitude shrink towards the poles. Past 85° the
		 * correction runs away, so the shift goes north–south only; no
		 * home on this marketplace is anywhere near, and a silent
		 * division by almost nothing is worse than a slightly duller
		 * offset.
		 */
		$cos_lat   = cos( deg2rad( $exact['lat'] ) );
		$delta_lng = abs( $exact['lat'] ) > 85 || $cos_lat < 0.01
			? 0.0
			: ( $metres * sin( $bearing ) ) / ( self::METRES_PER_DEGREE * $cos_lat );

		return [
			'lat' => round( $exact['lat'] + $delta_lat, self::ROUND_TO ),
			'lng' => round( $exact['lng'] + $delta_lng, self::ROUND_TO ),
		];
	}

	/**
	 * The homes to draw, in the order given, with nothing they should not
	 * carry: no address, no exact point, no owner.
	 *
	 * @param int[] $listing_ids Listing ids, in the order the list shows.
	 *
	 * @return array{homes:array<int,array<string,mixed>>,shown:int,placed:int,missing:int,capped:bool}
	 */
	public static function homes( array $listing_ids ): array {

		$homes   = [];
		$missing = 0;

		foreach ( $listing_ids as $id ) {

			$id    = (int) $id;
			$point = self::approximate( $id );

			if ( null === $point ) {
				++$missing;
				continue;
			}

			if ( count( $homes ) >= self::MARKER_CAP ) {
				continue;
			}

			$price = (float) get_post_meta( $id, Search::META_PRICE, true );

			$homes[] = [
				'id'    => $id,
				'lat'   => $point['lat'],
				'lng'   => $point['lng'],
				'title' => get_the_title( $id ),
				'where' => function_exists( 'tdh_listing_location' ) ? (string) tdh_listing_location( $id, ', ' ) : '',
				'price' => $price > 0 ? Search::dollars( (int) round( $price ) ) : '',
				'url'   => (string) get_permalink( $id ),
			];
		}

		$placed = count( $listing_ids ) - $missing;

		return [
			'homes'   => $homes,
			'shown'   => count( $homes ),
			'placed'  => $placed,
			'missing' => $missing,
			'capped'  => $placed > self::MARKER_CAP,
		];
	}

	/**
	 * The line under the map about homes it could not draw, or ''.
	 *
	 * Two different facts, never merged: some homes are missing because
	 * there are too many to draw, and some because we do not know where
	 * they are. A renter comparing the list with the map deserves to know
	 * which.
	 */
	public static function note( array $summary ): string {

		$lines = [];

		if ( ! empty( $summary['capped'] ) ) {
			$lines[] = sprintf(
				/* translators: 1: number drawn, 2: number found */
				__( 'Showing %1$s of %2$s homes on the map — narrow your search to see them all.', 'thirtydayhomes' ),
				number_format_i18n( (int) $summary['shown'] ),
				number_format_i18n( (int) $summary['placed'] )
			);
		}

		if ( ! empty( $summary['missing'] ) ) {
			$missing = (int) $summary['missing'];

			$lines[] = sprintf(
				/* translators: %s: number of homes */
				_n(
					'%s home has no location yet, so it is in the list but not on the map.',
					'%s homes have no location yet, so they are in the list but not on the map.',
					$missing,
					'thirtydayhomes'
				),
				number_format_i18n( $missing )
			);
		}

		return implode( ' ', $lines );
	}
}
