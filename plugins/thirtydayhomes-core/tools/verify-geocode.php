<?php
/**
 * Addresses into coordinates: reading Google's answer, when a lookup runs,
 * what each outcome stores, staff setting a location by hand, approval
 * waiting for a location, facilities, the no-key state, and privacy.
 *
 * A fake provider answers every lookup — nothing here reaches Google.
 *
 * Run through verify.bat, or directly:
 *     wp eval-file wp-content/plugins/thirtydayhomes-core/tools/verify-geocode.php
 */

use TDH\Account_Render;
use TDH\Geocode_Google;
use TDH\Geocode_Provider;
use TDH\Geocoder;
use TDH\Listing_Form;
use TDH\Listing_Form_Render;
use TDH\Listing_Preview;
use TDH\Membership;
use TDH\Moderation;
use TDH\Proximity;

function ok( string $label = '', bool $cond = false, string $detail = '' ): array {
	static $p = 0, $f = 0;
	if ( '' === $label ) { return [ $p, $f ]; }
	if ( $cond ) { ++$p; printf( "  ok    %s\n", $label ); }
	else { ++$f; printf( "  FAIL  %s%s\n", $label, '' !== $detail ? "  --> {$detail}" : '' ); }
	return [ $p, $f ];
}

/** Run a handler and return where it redirected. */
function redirect_of( callable $handler ): string {
	$catch = static function ( $location ) { throw new RuntimeException( (string) $location ); };
	add_filter( 'wp_redirect', $catch, 1 );
	try {
		$handler();
		$where = '(no redirect)';
	} catch ( RuntimeException $e ) {
		$where = $e->getMessage();
	} finally {
		remove_filter( 'wp_redirect', $catch, 1 );
	}
	return $where;
}

/**
 * The stand-in for Google: answers by what the address contains, and
 * counts every question.
 */
final class Fake_Geocoder implements Geocode_Provider {

	public array $asked = [];

	/** Places asked about, separately from addresses. */
	public array $areas = [];

	public function area( string $term ): array {

		$this->areas[] = $term;

		if ( '15226' === $term ) {
			return [ 'status' => 'ok', 'lat' => 40.3998, 'lng' => -80.0234, 'label' => '15226' ];
		}
		if ( 'Nowhere' === $term ) {
			return [ 'status' => 'not_found' ];
		}
		if ( 'Flaky' === $term ) {
			return [ 'status' => 'error', 'error' => 'network' ];
		}

		return [ 'status' => 'not_found' ];
	}

	/** When true, every address is found — Google having a better day. */
	public bool $find_everything = false;

	public function lookup( string $address ): array {

		$this->asked[] = $address;

		if ( $this->find_everything ) {
			return [ 'status' => 'ok', 'lat' => 40.4406, 'lng' => -79.9959 ];
		}

		if ( str_contains( $address, 'Nowhere' ) ) {
			return [ 'status' => 'not_found' ];
		}
		if ( str_contains( $address, 'Vague' ) ) {
			return [ 'status' => 'imprecise' ];
		}
		if ( str_contains( $address, 'Flaky' ) ) {
			return [ 'status' => 'error', 'error' => 'network' ];
		}
		if ( str_contains( $address, 'Mercy' ) ) {
			return [ 'status' => 'ok', 'lat' => 40.4362, 'lng' => -79.9850 ];
		}

		return [ 'status' => 'ok', 'lat' => 40.4406, 'lng' => -79.9959 ];
	}
}

$fake     = new Fake_Geocoder();
$offline  = false; // true = as if no key is set up.
$provider = static function () use ( $fake, &$offline ) {
	return $offline ? null : $fake;
};
add_filter( 'tdh_geocode_provider', $provider, 99 );
delete_transient( 'tdh_geocode_pause' );
delete_option( 'tdh_geocode_problem' );

echo "\n=== reading Google's answer ===\n";

$result = static fn( string $type, array $types, float $lat = 40.44, float $lng = -79.99 ): array => [
	'status'  => 'OK',
	'results' => [ [ 'types' => $types, 'geometry' => [ 'location_type' => $type, 'location' => [ 'lat' => $lat, 'lng' => $lng ] ] ] ],
];

$read = Geocode_Google::read( $result( 'ROOFTOP', [ 'street_address' ] ) );
ok( 'the building (ROOFTOP) is a location', 'ok' === $read['status'] && 40.44 === $read['lat'] );
ok( 'a place on the block (RANGE_INTERPOLATED) is a location', 'ok' === Geocode_Google::read( $result( 'RANGE_INTERPOLATED', [ 'street_address' ] ) )['status'] );
ok( 'a hospital campus centre is a location', 'ok' === Geocode_Google::read( $result( 'GEOMETRIC_CENTER', [ 'hospital', 'establishment' ] ) )['status'] );
ok( 'the middle of a street is not', 'imprecise' === Geocode_Google::read( $result( 'GEOMETRIC_CENTER', [ 'route' ] ) )['status'] );
ok( 'a town or ZIP (APPROXIMATE) is not', 'imprecise' === Geocode_Google::read( $result( 'APPROXIMATE', [ 'postal_code' ] ) )['status'] );
ok( 'no results is "not found"', 'not_found' === Geocode_Google::read( [ 'status' => 'ZERO_RESULTS' ] )['status'] );
ok( '"slow down" is a limit error, retried later', 'limit' === ( Geocode_Google::read( [ 'status' => 'OVER_QUERY_LIMIT' ] )['error'] ?? '' ) );
ok( 'a refused key is said as "denied"', 'denied' === ( Geocode_Google::read( [ 'status' => 'REQUEST_DENIED', 'error_message' => 'The provided API key is invalid.' ] )['error'] ?? '' ) );
ok( 'anything else is an unknown error, never a location', 'error' === Geocode_Google::read( [ 'status' => 'WHAT' ] )['status'] );

echo "\n=== reading a place, where the rule is the other way round (R44) ===\n";

/*
 * A home's location and a renter's search term are opposite questions:
 * read() refuses the middle of a postcode, read_area() wants exactly that
 * and refuses the house. Both readings are tested against the same shapes
 * so the pair cannot drift apart.
 */
$area_result = static fn( array $types, array $components = [], string $formatted = '' ): array => [
	'types'              => $types,
	'formatted_address'  => $formatted,
	'address_components' => $components,
	'geometry'           => [ 'location_type' => 'APPROXIMATE', 'location' => [ 'lat' => 40.3998, 'lng' => -80.0234 ] ],
];

$zip = Geocode_Google::read_area(
	[
		'status'  => 'OK',
		'results' => [
			$area_result(
				[ 'postal_code' ],
				[
					[ 'long_name' => '15226', 'short_name' => '15226', 'types' => [ 'postal_code' ] ],
					[ 'long_name' => 'Pennsylvania', 'short_name' => 'PA', 'types' => [ 'administrative_area_level_1' ] ],
				],
				'Pittsburgh, PA 15226, USA'
			),
		],
	]
);
ok( 'a postcode is a place, and keeps its point', 'ok' === $zip['status'] && 40.3998 === $zip['lat'] );
ok( '...and is named the way a renter says it, not "Pittsburgh, PA 15226, USA"', '15226' === $zip['label'], $zip['label'] ?? '' );

$town = Geocode_Google::read_area(
	[
		'status'  => 'OK',
		'results' => [
			$area_result( [ 'administrative_area_level_2' ], [ [ 'long_name' => 'Allegheny County', 'short_name' => 'Allegheny County', 'types' => [ 'administrative_area_level_2' ] ] ] ),
			$area_result(
				[ 'locality' ],
				[
					[ 'long_name' => 'Greensburg', 'short_name' => 'Greensburg', 'types' => [ 'locality' ] ],
					[ 'long_name' => 'Pennsylvania', 'short_name' => 'PA', 'types' => [ 'administrative_area_level_1' ] ],
				]
			),
		],
	]
);
ok( 'the town wins over the county that contains it, however Google ordered them', 'ok' === $town['status'] && 'Greensburg, PA' === $town['label'], $town['label'] ?? '' );

$house = Geocode_Google::read_area(
	[
		'status'  => 'OK',
		'results' => [ $area_result( [ 'street_address' ], [], '123 Probe Street, Pittsburgh, PA, USA' ) ],
	]
);
ok( 'a house number is NOT a place to search around', 'not_found' === $house['status'] );

$state = Geocode_Google::read_area(
	[
		'status'  => 'OK',
		'results' => [ $area_result( [ 'administrative_area_level_1' ], [], 'Pennsylvania, USA' ) ],
	]
);
ok( '...and neither is a whole state', 'not_found' === $state['status'] );

ok( 'nothing found is "not found", not an error', 'not_found' === Geocode_Google::read_area( [ 'status' => 'ZERO_RESULTS' ] )['status'] );
ok( 'a refused key is said as "denied" here too', 'denied' === ( Geocode_Google::read_area( [ 'status' => 'REQUEST_DENIED' ] )['error'] ?? '' ) );

$fallback = Geocode_Google::read_area(
	[
		'status'  => 'OK',
		'results' => [ $area_result( [ 'neighborhood' ], [], 'Shadyside, Pittsburgh, PA, USA' ) ],
	]
);
ok( 'with no components to build from, Google\'s own words are used, minus the country', 'Shadyside, Pittsburgh, PA' === ( $fallback['label'] ?? '' ), $fallback['label'] ?? '' );

echo "\n=== coordinates as people paste them ===\n";

ok( '"40.4406, -79.9959"', [ 'lat' => 40.4406, 'lng' => -79.9959 ] === Geocoder::parse_point( '40.4406, -79.9959' ) );
ok( '"40.4406 -79.9959" (a space)', null !== Geocoder::parse_point( '40.4406 -79.9959' ) );
ok( '"(40.4406°, -79.9959°)" (degrees and brackets)', null !== Geocoder::parse_point( '(40.4406°, -79.9959°)' ) );
ok( 'words are refused', null === Geocoder::parse_point( 'near the hospital' ) );
ok( 'a latitude past 90 is refused', null === Geocoder::parse_point( '91, -79' ) );
ok( '0, 0 is refused — it is the ocean, not a place', null === Geocoder::parse_point( '0, 0' ) );

echo "\n=== fixtures ===\n";

$make_user = static function ( string $login, string $role ): int {
	$existing = get_user_by( 'login', $login );
	if ( $existing ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $existing->ID );
	}
	$id = wp_insert_user( [ 'user_login' => $login, 'user_email' => $login . '@example.com', 'user_pass' => wp_generate_password( 20 ), 'role' => $role, 'display_name' => 'Geo Probe' ] );
	return is_wp_error( $id ) ? 0 : (int) $id;
};

$landlord = $make_user( 'tdh_geo_landlord', 'tdh_landlord' );
update_user_meta( $landlord, Membership::META_STATUS, Membership::ACTIVE );
update_user_meta( $landlord, Membership::META_QUOTA, 20 );
update_user_meta( $landlord, Membership::META_EXPIRES, strtotime( '+30 days' ) );
$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0] ?? null;

$city = wp_insert_term( 'Geo Probe City', 'tdh_city' );
$city = is_wp_error( $city ) ? (int) ( $city->get_error_data()['term_id'] ?? 0 ) : (int) $city['term_id'];

$created = [];
$form    = new Listing_Form();

$basics = static function ( int $id, string $street, array $extra = [] ) use ( $form, $city, &$created ): int {
	$_GET  = $id ? [ 'listing' => (string) $id ] : [];
	$_POST = array_merge(
		[
			'tdh_action'  => 'listing_basics',
			'tdh_nonce'   => wp_create_nonce( Listing_Form::NONCE ),
			'tdh_title'   => 'Geo probe home',
			'tdh_address' => $street,
			'tdh_zip'     => '15213',
			'tdh_city'    => (string) $city,
			'tdh_rent'    => '1900',
			'tdh_beds'    => '1',
			'tdh_baths'   => '1',
		],
		$extra
	);
	$where = redirect_of( [ $form, 'handle' ] );
	$_POST = [];
	Listing_Form::take_errors();
	Geocoder::flush(); // What the end of the request would do.

	parse_str( (string) wp_parse_url( $where, PHP_URL_QUERY ), $q );
	$new = (int) ( $q['listing'] ?? $id );
	if ( $new && ! in_array( $new, $created, true ) ) {
		$created[] = $new;
	}
	return $new;
};

wp_set_current_user( $landlord );
echo "  fixtures ready\n";

echo "\n=== a landlord's address becomes a location ===\n";

$home = $basics( 0, '412 Good Street' );
ok( 'saving the address looks it up once, with the city and ZIP', 1 === count( $fake->asked ) && str_contains( $fake->asked[0], '412 Good Street, Geo Probe City, PA 15213' ), implode( ' | ', $fake->asked ) );
ok( '...and stores the point, "found"', 'found' === Geocoder::state( $home ) && [ 'lat' => 40.4406, 'lng' => -79.9959 ] === Geocoder::coordinates( $home ) );

$basics( $home, '412 Good Street' );
ok( 'saving again with the same address asks nothing', 1 === count( $fake->asked ) );

$basics( $home, '999 Nowhere Road' );
ok( 'an address Google cannot find: "not found", and the old point is gone', 'failed' === Geocoder::state( $home ) && null === Geocoder::coordinates( $home ) );
ok( '...with a reason staff can act on', str_contains( Geocoder::reason( $home ), 'couldn’t find this address' ) );
ok( '...and it holds approval back', Geocoder::blocks_approval( $home ) );

$_GET = [ 'step' => '2', 'listing' => (string) $home ];
$page = Listing_Form_Render::form();
ok( 'the landlord is told on the next step, with a way back to the address', str_contains( $page, 'couldn’t find this address on the map' ) && str_contains( $page, 'Check the address' ) );

$vague = $basics( 0, '1 Vague Lane' );
ok( 'a street or ZIP match only is "not found" too, and says so', 'failed' === Geocoder::state( $vague ) && str_contains( Geocoder::reason( $vague ), 'only matched the street or the ZIP' ) );

echo "\n=== approval waits for a location ===\n";

wp_update_post( [ 'ID' => $home, 'post_status' => 'pending' ] );

if ( $admin ) {
	wp_set_current_user( $admin->ID );
	$moderation = new Moderation();

	$_POST = [ 'tdh_action' => 'listing_approve', 'tdh_listing' => (string) $home, 'tdh_nonce' => wp_create_nonce( Moderation::NONCE ) ];
	$where = redirect_of( [ $moderation, 'handle' ] );
	$_POST = [];
	ok( 'Approve is refused by the server while the address is not found', 'pending' === get_post_status( $home ) && str_contains( $where, 'tdh_moderated=location' ) && str_contains( $where, 'locate=' . $home ), $where );

	$_GET = [ 'view' => 'listings', 'tdh_moderated' => 'location' ];
	$page = Account_Render::dashboard();
	ok( '...and says why', str_contains( $page, 'Not approved yet: Google couldn’t find this home’s address' ) );
	ok( 'the queue row says "Location not found", with Set location', str_contains( $page, 'Location not found' ) && str_contains( $page, 'locate=' . $home ) );
	ok( '...and its Approve button is greyed out, pointing at the reason', (bool) preg_match( '/type="submit" disabled aria-describedby="loc-' . $home . '"/', $page ) );

	$bar = Listing_Preview::bar( $home );
	ok( 'the preview bar has no Approve for it — Set location instead', str_contains( $bar, 'Can’t be approved yet' ) && str_contains( $bar, 'Set location' ) && ! str_contains( $bar, 'value="listing_approve"' ) );

	$_GET = [ 'view' => 'listings', 'locate' => (string) $home ];
	$page = Account_Render::dashboard();
	ok( 'Set location opens the form under the row: coordinates, the address, Google Maps', str_contains( $page, 'name="tdh_point"' ) && str_contains( $page, 'Save location' ) && str_contains( $page, 'google.com/maps/search' ) && str_contains( $page, '999 Nowhere Road' ) );

	$geocoder = new Geocoder();
	$locate   = static function ( int $id, string $typed, bool $good_nonce = true ) use ( $geocoder ): string {
		$_POST = [ 'tdh_action' => 'listing_location', 'tdh_listing' => (string) $id, 'tdh_point' => $typed, 'tdh_nonce' => $good_nonce ? wp_create_nonce( Geocoder::NONCE ) : 'stale' ];
		$where = redirect_of( [ $geocoder, 'handle' ] );
		$_POST = [];
		return $where;
	};

	$where = $locate( $home, 'somewhere near UPMC' );
	ok( 'words instead of coordinates are refused, and the form opens again', str_contains( $where, 'tdh_located=invalid' ) && str_contains( $where, 'locate=' . $home ) && null === Geocoder::coordinates( $home ), $where );
	ok( '...with what was typed kept', 'somewhere near UPMC' === Geocoder::take_typed( $home ) );

	ok( 'an expired page saves nothing', str_contains( $locate( $home, '40.1, -79.1', false ), 'tdh_located=expired' ) && null === Geocoder::coordinates( $home ) );

	$where = $locate( $home, '40.4441, -79.9532' );
	ok( 'pasted coordinates are saved, "set by hand"', 'manual' === Geocoder::state( $home ) && [ 'lat' => 40.4441, 'lng' => -79.9532 ] === Geocoder::coordinates( $home ) && str_contains( $where, 'tdh_located=saved' ), $where );
	ok( '...which frees the approval', ! Geocoder::blocks_approval( $home ) );

	$_POST = [ 'tdh_action' => 'listing_approve', 'tdh_listing' => (string) $home, 'tdh_nonce' => wp_create_nonce( Moderation::NONCE ) ];
	redirect_of( [ $moderation, 'handle' ] );
	$_POST = [];
	ok( 'now Approve works', 'publish' === get_post_status( $home ) );

	$_GET = [ 'view' => 'listings' ];
	$page = Account_Render::dashboard();
	ok( 'a home with a location shows no location line at all (quiet when fine)', ! str_contains( $page, 'id="loc-' . $home . '"' ) );

	wp_set_current_user( 0 );
	ok( 'someone who is not staff cannot set a location', '(no redirect)' === $locate( $vague, '40.1, -79.1' ) && null === Geocoder::coordinates( $vague ) );
	wp_set_current_user( $landlord );
	ok( '...not even the landlord of the home', '(no redirect)' === $locate( $vague, '40.1, -79.1' ) && null === Geocoder::coordinates( $vague ) );
	wp_set_current_user( $admin->ID );

	ok( 'a location for a home that does not exist is answered "missing", nothing written', str_contains( $locate( 999999999, '40.1, -79.1' ), 'tdh_located=missing' ) );

	// "Look up the address again" asks even though the address is unchanged:
	// the fingerprint is for saves, not for a person who just pressed it.
	// And a refused-key notice goes away on the next successful lookup.
	update_option( 'tdh_geocode_problem', [ 'error' => 'denied', 'at' => time() ], false );
	ok( 'fixture: a refused-key notice is showing', str_contains( Geocoder::service_notice(), 'refused the maps key' ) );
	$asked_before          = count( $fake->asked );
	$fake->find_everything = true;
	$_POST                 = [ 'tdh_action' => 'listing_geocode', 'tdh_listing' => (string) $vague, 'tdh_nonce' => wp_create_nonce( Geocoder::NONCE ) ];
	$where                 = redirect_of( [ $geocoder, 'handle' ] );
	$_POST                 = [];
	$fake->find_everything = false;
	ok( '"Look up the address again" asks Google for an unchanged address, and takes the answer', count( $fake->asked ) === $asked_before + 1 && 'found' === Geocoder::state( $vague ) && str_contains( $where, 'tdh_located=found' ), $where . ' / ' . Geocoder::state( $vague ) );
	ok( '...and one successful lookup clears the refused-key notice', '' === Geocoder::service_notice(), Geocoder::service_notice() );
}

echo "\n=== points typed by hand ===\n";

wp_set_current_user( $admin ? $admin->ID : $landlord );
$asked = count( $fake->asked );
$basics( $home, '999 Nowhere Road' );
ok( 'a hand-set point survives a save with the same address', 'manual' === Geocoder::state( $home ) && count( $fake->asked ) === $asked );

$basics( $home, '77 Good Avenue' );
ok( 'a new address replaces it with a fresh lookup', 'found' === Geocoder::state( $home ) && [ 'lat' => 40.4406, 'lng' => -79.9959 ] === Geocoder::coordinates( $home ) );

update_post_meta( $home, '_tdh_lat', 40.5 );
update_post_meta( $home, '_tdh_lng', -80.0 );
Geocoder::flush();
ok( 'coordinates typed in wp-admin count as "set by hand"', 'manual' === Geocoder::state( $home ) );

$lat_before = get_post_meta( $home, '_tdh_lat', true );
update_post_meta( $home, '_tdh_lat', (float) $lat_before ); // re-saved, same number
Geocoder::flush();
ok( 'the same number saved again is not "typed"', 'manual' === Geocoder::state( $home ) && 40.5 === Geocoder::coordinates( $home )['lat'] );

echo "\n=== when the map service does not answer ===\n";

$flaky = $basics( 0, '5 Flaky Street' );
ok( 'no answer: "not checked yet", never "not found"', 'pending' === Geocoder::state( $flaky ) && ! Geocoder::blocks_approval( $flaky ) );
ok( '...the reason says it is tried again', str_contains( Geocoder::reason( $flaky ), 'tried again' ) );
ok( '...naming the control on screen, not a button that is not there', str_contains( Geocoder::reason( $flaky ), 'Set location' ) && ! str_contains( Geocoder::reason( $flaky ), 'Try again' ) );
ok( '...and the service is left alone for a minute', (bool) get_transient( 'tdh_geocode_pause' ) );

$asked = count( $fake->asked );
$basics( 0, '9 Good Street' );
ok( 'during that minute nothing is asked — the home simply waits', count( $fake->asked ) === $asked );
$waiting = end( $created );

delete_transient( 'tdh_geocode_pause' );
wp_update_post( [ 'ID' => $waiting, 'post_title' => 'Geo probe home, saved again' ] );
Geocoder::flush();
ok( 'the next save of a waiting home tries again, and finds it', 'found' === Geocoder::state( $waiting ) );

$moved = $basics( 0, '10 Good Street' );
ok( 'fixture: a found home', 'found' === Geocoder::state( $moved ) );
$basics( $moved, '10 Flaky Street' );
ok( 'its address changes and the service is down: the old point is dropped, not kept for the wrong address', null === Geocoder::coordinates( $moved ) && 'pending' === Geocoder::state( $moved ) );
delete_transient( 'tdh_geocode_pause' );

echo "\n=== a home saved before the key existed ===\n";

// Every home on the site the day the key arrives is in this state: waiting,
// with nothing ever asked about it. It must not be told a lookup failed.
$never = wp_insert_post(
	[
		'post_type'   => 'tdh_listing',
		'post_status' => 'draft',
		'post_title'  => 'Geo probe home, never looked up',
		'post_author' => $landlord,
	]
);
$created[] = $never;
update_post_meta( $never, '_tdh_street_address', '412 Good Street' );
update_post_meta( $never, '_tdh_zip', '15213' );
wp_set_object_terms( $never, [ $city ], 'tdh_city' );

$never_reason = Geocoder::reason( $never );
ok( 'never looked up: "not checked yet", and approval is not held', 'pending' === Geocoder::state( $never ) && ! Geocoder::blocks_approval( $never ) );
ok( '...it does not claim a lookup failed', str_contains( $never_reason, 'look it up' ) && ! str_contains( $never_reason, 'didn’t answer' ), $never_reason );
ok( '...it names a control that is on the screen', str_contains( $never_reason, 'Set location' ) && ! str_contains( $never_reason, 'Try again' ), $never_reason );
ok( '...and it does not repeat the chip beside it', ! str_contains( $never_reason, 'not checked' ) && ! str_contains( $never_reason, 'Not looked up yet' ), $never_reason );

// With the key installed, pressing Approve on such a home looks it up first,
// so it never goes live without a location (found in the live walk, 26 Sep).
if ( $admin ) {
	$was_user = get_current_user_id();
	wp_set_current_user( $admin->ID );
	wp_update_post( [ 'ID' => $never, 'post_status' => 'pending' ] );
	$_POST = [ 'tdh_action' => 'listing_approve', 'tdh_listing' => (string) $never, 'tdh_nonce' => wp_create_nonce( Moderation::NONCE ) ];
	redirect_of( [ new Moderation(), 'handle' ] );
	$_POST = [];
	ok( 'Approve on a never-looked-up home looks it up, and a found address goes live', 'found' === Geocoder::state( $never ) && 'publish' === get_post_status( $never ) );

	$lost = wp_insert_post( [ 'post_type' => 'tdh_listing', 'post_status' => 'pending', 'post_title' => 'Geo probe home, never looked up, lost', 'post_author' => $landlord ] );
	$created[] = $lost;
	update_post_meta( $lost, '_tdh_street_address', '1 Nowhere Road' );
	update_post_meta( $lost, '_tdh_zip', '15213' );
	wp_set_object_terms( $lost, [ $city ], 'tdh_city' );
	$_POST = [ 'tdh_action' => 'listing_approve', 'tdh_listing' => (string) $lost, 'tdh_nonce' => wp_create_nonce( Moderation::NONCE ) ];
	$where = redirect_of( [ new Moderation(), 'handle' ] );
	$_POST = [];
	ok( '...and one Google cannot find is held back with the reason, not published', 'pending' === get_post_status( $lost ) && 'failed' === Geocoder::state( $lost ) && str_contains( $where, 'tdh_moderated=location' ), $where );
	wp_set_current_user( $was_user );
}

echo "\n=== no maps key ===\n";

$offline = true;
ok( 'with no key the site knows it', ! Geocoder::configured() );
$nokey = $basics( 0, '11 Good Street' );
ok( 'a home still saves, "not checked yet", and can be approved', 'pending' === Geocoder::state( $nokey ) && ! Geocoder::blocks_approval( $nokey ) && 'Geo probe home' === get_the_title( $nokey ) );
ok( 'the reason names the missing key', str_contains( Geocoder::reason( $nokey ), 'Google Maps key isn’t set up' ) );
ok( 'staff see one notice about it at the top', str_contains( Geocoder::service_notice(), 'Map locations are off' ) );

if ( $admin ) {
	$_POST = [ 'tdh_action' => 'listing_geocode', 'tdh_listing' => (string) $nokey, 'tdh_nonce' => wp_create_nonce( Geocoder::NONCE ) ];
	$where = redirect_of( [ new Geocoder(), 'handle' ] );
	$_POST = [];
	ok( '"Look up again" without a key says so instead of failing silently', str_contains( $where, 'tdh_located=no_key' ) );

	$_GET = [ 'view' => 'listings', 'locate' => (string) $nokey ];
	$page = Account_Render::dashboard();
	ok( 'without a key the form offers no "Look up the address again"', str_contains( $page, 'Save location' ) && ! str_contains( $page, 'Look up the address again' ) );
}
$offline = false;

echo "\n=== distances never come from nowhere ===\n";

// What an empty wp-admin box used to store. Checked before any lookup runs.
update_post_meta( $nokey, '_tdh_lat', 0 );
update_post_meta( $nokey, '_tdh_lng', 0 );
delete_post_meta( $nokey, '_tdh_nearest_facility' );
ok( '0, 0 is not a location', null === Geocoder::coordinates( $nokey ) && 'pending' === Geocoder::state( $nokey ) );
ok( '...so the home shows no hospital distance at all', null === Proximity::nearest( $nokey ) );
Geocoder::flush();
ok( '...and it is looked up from its address instead', 'found' === Geocoder::state( $nokey ) );

echo "\n=== facilities ===\n";

if ( $admin ) {
	wp_set_current_user( $admin->ID );
	$accounts = TDH\Core::instance()->module( 'accounts' );

	$save_facility = static function ( int $id, array $meta, string $title = 'Geo Probe Mercy Hospital' ) use ( $accounts ): string {
		$_POST = [
			'tdh_action'   => 'facility_save',
			'tdh_nonce'    => wp_create_nonce( 'tdh_facility_save' ),
			'tdh_facility' => (string) $id,
			'tdh_title'    => $title,
			'tdh_meta'     => $meta,
		];
		$where = redirect_of( [ $accounts, 'handle_forms' ] );
		$_POST = [];
		return $where;
	};

	$meta = [ '_tdh_facility_type' => 'hospital', '_tdh_street_address' => '1400 Locust St Mercy', '_tdh_state' => 'PA', '_tdh_zip' => '15219', '_tdh_sort_order' => '0', '_tdh_lat' => '', '_tdh_lng' => '', '_tdh_active' => '1' ];
	$save_facility( 0, $meta );
	$facility = (int) ( get_posts( [ 'post_type' => 'tdh_facility', 'title' => 'Geo Probe Mercy Hospital', 'fields' => 'ids', 'post_status' => 'any', 'numberposts' => 1 ] )[0] ?? 0 );
	$notice   = TDH\Accounts::take_notice();

	ok( 'a facility added by address only gets its coordinates', $facility && 'found' === Geocoder::state( $facility ) && [ 'lat' => 40.4362, 'lng' => -79.985 ] === Geocoder::coordinates( $facility ) );
	ok( '...and the message says the location was found', str_contains( $notice['success'], 'Its location was found from the address' ), $notice['success'] );
	ok( 'an empty coordinate box is no longer stored as 0', '' === (string) get_post_meta( $facility, '_tdh_geocode_reason', true ) && null !== Geocoder::coordinates( $facility ) );

	$asked = count( $fake->asked );
	$save_facility( $facility, array_merge( $meta, [ '_tdh_lat' => '40.4362', '_tdh_lng' => '-79.985' ] ) );
	TDH\Accounts::take_notice();
	ok( 'saving it again unchanged asks nothing and keeps it "found"', count( $fake->asked ) === $asked && 'found' === Geocoder::state( $facility ) );

	$save_facility( $facility, array_merge( $meta, [ '_tdh_lat' => '40.4400', '_tdh_lng' => '-79.9800' ] ) );
	TDH\Accounts::take_notice();
	ok( 'typed coordinates override the lookup: "set by hand"', 'manual' === Geocoder::state( $facility ) && 40.44 === Geocoder::coordinates( $facility )['lat'] );

	$save_facility( $facility, $meta );
	TDH\Accounts::take_notice();
	ok( 'clearing both boxes looks the address up again', 'found' === Geocoder::state( $facility ) );

	$save_facility( $facility, array_merge( $meta, [ '_tdh_street_address' => '1 Nowhere Road', '_tdh_lat' => '40.4362', '_tdh_lng' => '-79.985' ] ) );
	$notice = TDH\Accounts::take_notice();
	ok( 'a new address that cannot be found is said in the message', 'failed' === Geocoder::state( $facility ) && str_contains( $notice['success'], 'wasn’t found on the map' ), $notice['success'] );

	$_GET = [ 'view' => 'facilities' ];
	$page = Account_Render::dashboard();
	ok( 'the facility row shows its location state in words', str_contains( $page, 'Location not found' ) );
	ok( 'no browser pop-up confirms a delete any more', ! str_contains( $page, 'confirm(' ) );

	$_GET = [ 'view' => 'facilities', 'delete_facility' => (string) $facility ];
	$page = Account_Render::dashboard();
	ok( 'Delete asks in the page: the name, what it takes, Delete facility and Keep it', str_contains( $page, 'Delete “Geo Probe Mercy Hospital”?' ) && str_contains( $page, 'homes stop showing their distance to it' ) && str_contains( $page, 'Keep it' ) && str_contains( $page, 'value="facility_delete"' ) );
	ok( '...with that facility opened', (bool) preg_match( '/id="facility-' . $facility . '" open/', $page ) );

	wp_delete_post( $facility, true );
}

echo "\n=== privacy ===\n";

$registered = get_registered_meta_keys( 'post', 'tdh_listing' );
ok( 'a home\'s coordinates and location notes never go to the REST API', empty( $registered['_tdh_lat']['show_in_rest'] ) && empty( $registered['_tdh_lng']['show_in_rest'] ) && empty( $registered['_tdh_geocode_status']['show_in_rest'] ) && empty( $registered['_tdh_geocode_reason']['show_in_rest'] ) );
ok( 'the maps key is never part of any message', ! str_contains( Geocoder::service_notice() . Geocoder::reason( $flaky ), 'key=' ) );

echo "\n=== cleanup ===\n";

foreach ( $created as $id ) {
	wp_delete_post( $id, true );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $landlord );
if ( $city ) {
	wp_delete_term( $city, 'tdh_city' );
}
remove_filter( 'tdh_geocode_provider', $provider, 99 );
delete_transient( 'tdh_geocode_pause' );
delete_option( 'tdh_geocode_problem' );
$_GET = [];
wp_set_current_user( 0 );
echo "  fixtures removed\n";

[ $passed, $failed ] = ok();
printf( "\n%s  %d passed, %d failed\n", $failed ? 'FAILED' : 'PASSED', $passed, $failed );
if ( $failed && defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::halt( 1 );
}
