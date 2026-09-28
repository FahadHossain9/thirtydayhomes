<?php
/**
 * Something that turns an address into coordinates.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Two questions, so the provider can change (decision 1 chose Google;
 * Mapbox is the named alternative) and the test suite can stand in a fake
 * without touching the network.
 *
 * They are deliberately separate because they want opposite things.
 * lookup() is asked where a **home** is, and a ZIP-level answer is refused:
 * placing a listing at the middle of its postcode would put a home on the
 * map streets away from where it is. area() is asked where a **postcode or
 * a town** is, and that same middle is exactly the right answer.
 *
 * Neither ever throws.
 *
 * lookup() answers with one of:
 *
 *   [ 'status' => 'ok',        'lat' => float, 'lng' => float ]
 *   [ 'status' => 'not_found' ]   no result for this address
 *   [ 'status' => 'imprecise' ]   a result, but only for the street, the ZIP
 *                                 or the town — not the house
 *   [ 'status' => 'error', 'error' => 'denied' | 'limit' | 'network' | 'unknown' ]
 *                                 the service could not answer; try again later
 *
 * area() answers with one of:
 *
 *   [ 'status' => 'ok', 'lat' => float, 'lng' => float, 'label' => string ]
 *   [ 'status' => 'not_found' ]   not a postcode, town, neighbourhood or
 *                                 county — a house number or a person's
 *                                 name lands here too, on purpose
 *   [ 'status' => 'error', 'error' => … ]
 */
interface Geocode_Provider {

	/**
	 * Where is this building?
	 *
	 * @return array{status:string,lat?:float,lng?:float,error?:string}
	 */
	public function lookup( string $address ): array;

	/**
	 * Where is this postcode, town or neighbourhood?
	 *
	 * @param string $term What the renter typed, already trimmed.
	 *
	 * @return array{status:string,lat?:float,lng?:float,label?:string,error?:string}
	 */
	public function area( string $term ): array;
}
