<?php
/**
 * Google's Geocoding API.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Asks Google where an address is.
 *
 * The key is the SERVER key from wp-config.php (TDH_MAPS_SERVER_KEY). It is
 * only ever sent to Google from this server — never printed, logged or
 * stored — and Google's own error text is not kept either, only a short
 * code the portal turns into words.
 *
 * ─── WHEN A RESULT COUNTS ──────────────────────────────────────────────────
 *
 * A distance to a hospital is only as good as the point it is measured
 * from. Google returns SOMETHING for almost any text — type half an address
 * and it answers with the middle of the ZIP code — so a result counts only
 * when it is the building:
 *
 *   ROOFTOP, RANGE_INTERPOLATED     the house (or its place on the block)
 *   GEOMETRIC_CENTER                only for a building or a campus
 *                                   (premise, establishment, hospital…) —
 *                                   for a street it is the street's middle
 *   APPROXIMATE                     never: a town, a ZIP, a county
 *
 * ─── AND WHEN THE OPPOSITE COUNTS ──────────────────────────────────────────
 *
 * area() turns that rule upside down, because it is a different question.
 * A renter typing "15226" is not naming a building; they are naming a part
 * of the city. APPROXIMATE — the middle of the postcode — is the only
 * honest answer there, and a house number is the one thing that must NOT
 * come back, so the two methods accept exactly what the other refuses.
 */
final class Geocode_Google implements Geocode_Provider {

	private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

	/** Result types that name one building or campus. */
	private const PLACE_TYPES = [ 'street_address', 'premise', 'subpremise', 'establishment', 'point_of_interest', 'hospital', 'health' ];

	/**
	 * Result types that name a part of the map a renter can search inside.
	 *
	 * A state or a country is deliberately absent: "PA" has a centre point,
	 * but ordering homes by distance from the middle of Pennsylvania answers
	 * a question nobody asked.
	 */
	private const AREA_TYPES = [
		'postal_code',
		'postal_code_prefix',
		'locality',
		'sublocality',
		'sublocality_level_1',
		'neighborhood',
		'administrative_area_level_3',
		'administrative_area_level_2',
	];

	public function __construct( private string $key ) {}

	public function lookup( string $address ): array {

		$body = $this->ask(
			[
				'address'    => $address,
				'components' => 'country:US',
			]
		);

		return isset( $body['status'] ) && 'error' === $body['status'] ? $body : self::read( $body );
	}

	public function area( string $term ): array {

		/*
		 * A five-digit term is asked for as a postcode component rather than
		 * as free text. Google matches a component exactly, so "15226" can
		 * only ever come back as that postcode — never as a house number on
		 * a road that happens to contain the digits.
		 */
		$params = preg_match( '/^\d{5}$/', $term )
			? [ 'components' => 'postal_code:' . $term . '|country:US' ]
			: [
				'address'    => $term,
				'components' => 'country:US',
				// Prefer the service area: "Oakland" is the Pittsburgh one.
				'bounds'     => self::bounds_param( Area::service_bounds() ),
			];

		$body = $this->ask( $params );

		return isset( $body['status'] ) && 'error' === $body['status'] ? $body : self::read_area( $body );
	}

	/** Google's "south,west|north,east". */
	private static function bounds_param( array $b ): string {
		return sprintf( '%.4f,%.4f|%.4f,%.4f', $b['south'], $b['west'], $b['north'], $b['east'] );
	}

	/**
	 * One call to Google.
	 *
	 * Returns the decoded body, or an `error` answer ready to hand back —
	 * the caller tells them apart by the `status` key, which Google itself
	 * never sets to "error".
	 *
	 * @param array<string,string> $params Everything but the key.
	 *
	 * @return array<string,mixed>
	 */
	private function ask( array $params ): array {

		$url = self::ENDPOINT . '?' . http_build_query(
			$params + [ 'key' => $this->key ],
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		$response = wp_remote_get( $url, [ 'timeout' => 8 ] );

		if ( is_wp_error( $response ) ) {
			return [ 'status' => 'error', 'error' => 'network' ];
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return [ 'status' => 'error', 'error' => 'unknown' ];
		}

		return $body;
	}

	/**
	 * Google's answer, read. Public so the test suite can check the reading
	 * without a network.
	 *
	 * @param array<string,mixed> $body The decoded JSON.
	 * @return array{status:string,lat?:float,lng?:float,error?:string}
	 */
	public static function read( array $body ): array {

		$refused = self::refusal( $body );

		if ( null !== $refused ) {
			return $refused;
		}

		$result = $body['results'][0] ?? null;

		if ( ! is_array( $result ) || ! isset( $result['geometry']['location']['lat'], $result['geometry']['location']['lng'] ) ) {
			return [ 'status' => 'not_found' ];
		}

		if ( ! self::precise( $result ) ) {
			return [ 'status' => 'imprecise' ];
		}

		return [
			'status' => 'ok',
			'lat'    => (float) $result['geometry']['location']['lat'],
			'lng'    => (float) $result['geometry']['location']['lng'],
		];
	}

	/**
	 * Is this the building, not the street or the ZIP?
	 *
	 * @param array<string,mixed> $result One result from Google.
	 */
	public static function precise( array $result ): bool {

		$type  = (string) ( $result['geometry']['location_type'] ?? '' );
		$types = array_map( 'strval', (array) ( $result['types'] ?? [] ) );

		if ( in_array( $type, [ 'ROOFTOP', 'RANGE_INTERPOLATED' ], true ) ) {
			return true;
		}

		if ( 'GEOMETRIC_CENTER' === $type ) {
			return [] !== array_intersect( $types, self::PLACE_TYPES );
		}

		return false;
	}

	/**
	 * Google's answer to "where is this part of the map?", read.
	 *
	 * Public for the same reason {@see read()} is: the reading is the part
	 * worth testing, and it can be tested without a network.
	 *
	 * @param array<string,mixed> $body The decoded JSON.
	 *
	 * @return array{status:string,lat?:float,lng?:float,label?:string,error?:string}
	 */
	public static function read_area( array $body ): array {

		$refused = self::refusal( $body );

		if ( null !== $refused ) {
			return $refused;
		}

		/*
		 * Every result is considered, and the SMALLEST place wins rather
		 * than the first one listed. Asked for a town, Google often leads
		 * with the county that contains it; measuring from the middle of a
		 * county would put the wrong homes at the top of the list and name
		 * the wrong place while doing it. AREA_TYPES is in that order —
		 * postcode, neighbourhood, town, county — so the first type with a
		 * result is the tightest answer available.
		 */
		$results = array_filter( (array) ( $body['results'] ?? [] ), 'is_array' );

		foreach ( self::AREA_TYPES as $wanted ) {
			foreach ( $results as $result ) {

				$types = array_map( 'strval', (array) ( $result['types'] ?? [] ) );

				if ( ! in_array( $wanted, $types, true ) ) {
					continue;
				}

				if ( ! isset( $result['geometry']['location']['lat'], $result['geometry']['location']['lng'] ) ) {
					continue;
				}

				return [
					'status' => 'ok',
					'lat'    => (float) $result['geometry']['location']['lat'],
					'lng'    => (float) $result['geometry']['location']['lng'],
					'label'  => self::label( $result ),
				];
			}
		}

		return [ 'status' => 'not_found' ];
	}

	/**
	 * Google's top-level status, turned into an answer — or null when the
	 * body is worth reading on.
	 *
	 * @param array<string,mixed> $body The decoded JSON.
	 *
	 * @return array{status:string,error?:string}|null
	 */
	private static function refusal( array $body ): ?array {

		switch ( (string) ( $body['status'] ?? '' ) ) {
			case 'OK':
				return null;
			case 'ZERO_RESULTS':
			case 'INVALID_REQUEST':
				return [ 'status' => 'not_found' ];
			case 'OVER_QUERY_LIMIT':
			case 'OVER_DAILY_LIMIT':
				return [ 'status' => 'error', 'error' => 'limit' ];
			case 'REQUEST_DENIED':
				return [ 'status' => 'error', 'error' => 'denied' ];
			default:
				return [ 'status' => 'error', 'error' => 'unknown' ];
		}
	}

	/**
	 * A short name for a place, in the form a renter would say it.
	 *
	 * "Pittsburgh, PA 15226, USA" is what Google calls the postcode; the
	 * renter calls it "15226". So the name is built from the components —
	 * the area's own name, plus the state to tell two towns apart — and
	 * Google's long sentence is only the fallback.
	 *
	 * @param array<string,mixed> $result One result from Google.
	 */
	private static function label( array $result ): string {

		$name  = '';
		$state = '';

		foreach ( (array) ( $result['address_components'] ?? [] ) as $part ) {

			if ( ! is_array( $part ) ) {
				continue;
			}

			$types = array_map( 'strval', (array) ( $part['types'] ?? [] ) );

			if ( '' === $name && [] !== array_intersect( $types, self::AREA_TYPES ) ) {
				$name = (string) ( $part['long_name'] ?? '' );
			}

			if ( '' === $state && in_array( 'administrative_area_level_1', $types, true ) ) {
				$state = (string) ( $part['short_name'] ?? '' );
			}
		}

		if ( '' === $name ) {
			// Google's own sentence, without the country nobody says out loud.
			$formatted = trim( (string) ( $result['formatted_address'] ?? '' ) );

			return '' === $formatted ? '' : (string) preg_replace( '/,\s*USA$/', '', $formatted );
		}

		/*
		 * A postcode is already unique across the country, so adding the
		 * state to it ("15226, PA") reads as though it might not be.
		 */
		$types = array_map( 'strval', (array) ( $result['types'] ?? [] ) );

		if ( in_array( 'postal_code', $types, true ) || '' === $state ) {
			return $name;
		}

		return $name . ', ' . $state;
	}
}
