<?php
/**
 * The nearest hospitals on a property page: ordering, the radius cut-off,
 * how many are listed, inactive and un-located facilities, the staff
 * setting, and what a home with no location shows.
 *
 * Distances are computed from fixed coordinates — nothing here reaches
 * Google, and no lookup runs: every fixture is given its point by hand.
 *
 * Run through verify.bat, or directly:
 *     wp eval-file wp-content/plugins/thirtydayhomes-core/tools/verify-proximity.php
 */

use TDH\Accounts;
use TDH\Account_Render;
use TDH\Post_Types;
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

echo "\n=== distance ===\n";

// Downtown Pittsburgh to UPMC Mercy: about 0.6 miles on the map.
$downtown = [ 40.4406, -79.9959 ];
$mercy    = [ 40.4360, -79.9856 ];
$miles    = Proximity::miles( $downtown[0], $downtown[1], $mercy[0], $mercy[1] );

ok( 'a real pair of Pittsburgh points measures about 0.6 mi', $miles > 0.5 && $miles < 0.8, (string) $miles );
ok( 'the same point twice is zero', 0.0 === Proximity::miles( 40.44, -79.99, 40.44, -79.99 ) );
ok( 'distance does not depend on the order of the two points', abs( Proximity::miles( 40.44, -79.99, 40.46, -79.95 ) - Proximity::miles( 40.46, -79.95, 40.44, -79.99 ) ) < 0.0001 );
ok( 'under ten miles keeps one decimal', '0.6 mi' === Proximity::format_miles( 0.64 ) );
ok( 'ten miles and over drops it — the lookup is not that precise', '12 mi' === Proximity::format_miles( 12.3 ) );

echo "\n=== fixtures ===\n";

/*
 * The staff setting is theirs. The checks below are written for the
 * defaults — 3 within 15 — so those are pinned for the run, and exactly
 * what was there is put back at the end, "nothing set" included. This
 * suite used to assume the defaults and then DELETE both options in its
 * cleanup, wiping whatever staff had typed on Listing setup.
 */
$setting_was = [
	'tdh_proximity_count'  => get_option( 'tdh_proximity_count', null ),
	'tdh_proximity_radius' => get_option( 'tdh_proximity_radius', null ),
];
delete_option( 'tdh_proximity_count' );
delete_option( 'tdh_proximity_radius' );

$created = [];

/** A facility at a point, with a type and an active flag. */
$make_facility = static function ( string $title, float $lat, float $lng, string $type = 'hospital', bool $active = true ) use ( &$created ): int {

	$id = wp_insert_post(
		[
			'post_type'   => Post_Types::FACILITY,
			'post_status' => 'publish',
			'post_title'  => $title,
		]
	);

	$created[] = (int) $id;

	update_post_meta( (int) $id, '_tdh_facility_type', $type );
	update_post_meta( (int) $id, '_tdh_active', $active ? '1' : '0' );
	update_post_meta( (int) $id, '_tdh_lat', (string) $lat );
	update_post_meta( (int) $id, '_tdh_lng', (string) $lng );

	return (int) $id;
};

/** A home at a point, or with no point at all. */
$make_home = static function ( string $title, ?float $lat = null, ?float $lng = null ) use ( &$created ): int {

	$id = wp_insert_post(
		[
			'post_type'   => Post_Types::LISTING,
			'post_status' => 'publish',
			'post_title'  => $title,
		]
	);

	$created[] = (int) $id;

	if ( null !== $lat && null !== $lng ) {
		update_post_meta( (int) $id, '_tdh_lat', (string) $lat );
		update_post_meta( (int) $id, '_tdh_lng', (string) $lng );
		update_post_meta( (int) $id, '_tdh_geocode_status', 'manual' );
	}

	return (int) $id;
};

// Every real facility on the site is hidden for the length of this suite, so
// the numbers below are about the fixtures and nothing else.
$hide_real = static function ( array $args ): array {
	$args['post__in'] = get_posts(
		[
			'post_type'      => Post_Types::FACILITY,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			's'              => 'Prox Probe',
		]
	) ?: [ 0 ];

	return $args;
};

$home = $make_home( 'Prox Probe home', 40.4406, -79.9959 );

// Distances from the home, roughly: 0.6, 1.6, 2.8, 5.5 and 24 miles.
$near_a  = $make_facility( 'Prox Probe Mercy', 40.4360, -79.9856 );
$near_b  = $make_facility( 'Prox Probe General', 40.4574, -80.0033, 'clinic' );
$near_c  = $make_facility( 'Prox Probe Presbyterian', 40.4421, -79.9448, 'rehab' );
$near_d  = $make_facility( 'Prox Probe Shadyside', 40.4544, -79.9099 );
$far     = $make_facility( 'Prox Probe Greensburg', 40.3015, -79.5389 );
$closed  = $make_facility( 'Prox Probe Closed', 40.4410, -79.9960, 'hospital', false );
$nowhere = $make_facility( 'Prox Probe Unlocated', 0.0, 0.0 );

// The un-located one must really have no point: 0,0 is stored, and the
// geocoder treats it as no place, but an empty pair is the honest fixture.
delete_post_meta( $nowhere, '_tdh_lat' );
delete_post_meta( $nowhere, '_tdh_lng' );

add_filter( 'tdh_facility_query_args', $hide_real );
echo "  fixtures ready\n";

echo "\n=== which facilities count ===\n";

$all = Proximity::nearest_n( $home, 5, 100 );
$ids = array_column( $all, 'id' );

ok( 'a facility switched off in renter search is left out', ! in_array( $closed, $ids, true ) );
ok( 'a facility with no coordinates is left out, never at "0.0 mi"', ! in_array( $nowhere, $ids, true ) );
ok( 'the five located, active facilities are the list', 5 === count( $all ) );

echo "\n=== order and distance ===\n";

ok( 'the nearest comes first', $near_a === ( $all[0]['id'] ?? 0 ) );
ok( 'then the next nearest, and so on', [ $near_a, $near_b, $near_c, $near_d, $far ] === $ids, implode( ',', $ids ) );
ok( 'each row carries its distance', ( $all[0]['miles'] ?? 0 ) > 0.5 && ( $all[0]['miles'] ?? 0 ) < 0.8 );
ok( 'and its type, for the words under the name', 'clinic' === ( $all[1]['type'] ?? '' ) );
ok( 'the card band still shows the single nearest', $near_a === ( Proximity::nearest( $home )['id'] ?? 0 ) );

echo "\n=== the radius ===\n";

ok( 'a facility beyond the radius is not listed', ! in_array( $far, array_column( Proximity::nearest_n( $home, 5, 15 ), 'id' ), true ) );
ok( 'one just inside it is', in_array( $near_d, array_column( Proximity::nearest_n( $home, 5, 15 ), 'id' ), true ) );

$edge = Proximity::nearest_n( $home, 5, (float) round( $all[2]['miles'], 4 ) );
ok( 'exactly at the radius counts as within it', in_array( $near_c, array_column( $edge, 'id' ), true ) );
ok( 'a one-mile radius keeps only what is inside it', [ $near_a ] === array_column( Proximity::nearest_n( $home, 5, 1 ), 'id' ) );

echo "\n=== how many ===\n";

ok( 'three by default', 3 === count( Proximity::nearest_n( $home, null, 100 ) ) && 3 === Proximity::count_setting() );
ok( 'one when one is asked for', 1 === count( Proximity::nearest_n( $home, 1, 100 ) ) );
ok( 'asking for more than there are gives what there is', 5 === count( Proximity::nearest_n( $home, 5, 100 ) ) );
ok( 'a count outside the allowed range falls back to the default', 3 === count( Proximity::nearest_n( $home, 99, 100 ) ) && 3 === count( Proximity::nearest_n( $home, 0, 100 ) ) );
ok( 'a radius outside the allowed range falls back to the default', Proximity::nearest_n( $home, 5, 15 ) === Proximity::nearest_n( $home, 5, 9999 ) );

echo "\n=== a home with no location ===\n";

$lost = $make_home( 'Prox Probe home with no address' );

ok( 'no coordinates means no distances at all', [] === Proximity::nearest_n( $lost ) );
ok( '...and no card band', null === Proximity::nearest( $lost ) );

ob_start();
do_action( 'tdh_listing_proximity', $lost );
$printed = trim( (string) ob_get_clean() );

ok( '...and the property page prints nothing rather than an empty list', '' === $printed, $printed );

echo "\n=== what the property page says ===\n";

ob_start();
do_action( 'tdh_listing_proximity', $home );
$page = (string) ob_get_clean();

ok( 'the three nearest are named', str_contains( $page, 'Prox Probe Mercy' ) && str_contains( $page, 'Prox Probe General' ) && str_contains( $page, 'Prox Probe Presbyterian' ) );
ok( 'the fourth is not', ! str_contains( $page, 'Prox Probe Shadyside' ) );
ok( 'each name has its distance', str_contains( $page, 'mi' ) && (bool) preg_match( '/\d+(\.\d)? mi/', $page ) );
ok( 'the type is named in words, not as a stored key', str_contains( $page, 'Clinic' ) && ! str_contains( $page, '>clinic<' ) );
ok( 'the count and the radius are stated in plain words', str_contains( $page, '3 medical facilities within 15 miles' ), $page );

/*
 * Every row carries its icon, and the icon is decorative. The plugin
 * borrows the theme's helper, so a theme without it must degrade to the
 * plain row rather than fatal — which is why the call is guarded and why
 * this asserts the count matches the rows rather than a fixed number.
 */
ok( 'each facility row carries a mark, one per row', 3 === substr_count( $page, 'tdh-icon--stethoscope' ) && 3 === substr_count( $page, '<li>' ), (string) substr_count( $page, 'tdh-icon--stethoscope' ) );
ok( '...and it is hidden from a screen reader, which already hears the name', 3 === substr_count( $page, 'aria-hidden="true"' ) );

$one = Proximity::nearest_n( $home, 1, 100 );
update_option( 'tdh_proximity_count', 1 );
ob_start();
do_action( 'tdh_listing_proximity', $home );
$single = (string) ob_get_clean();
delete_option( 'tdh_proximity_count' ); // back to the default the run is pinned to

ok( 'one facility is written in the singular', str_contains( $single, '1 medical facility within' ) && ! str_contains( $single, '1 medical facilities' ), $single );

$out_of_range = $make_home( 'Prox Probe home far away', 41.5000, -81.6900 );
ob_start();
do_action( 'tdh_listing_proximity', $out_of_range );
$none = (string) ob_get_clean();

ok( 'nothing in range says so, and the section is not hidden', str_contains( $none, 'No medical facilities within 15 miles of this home.' ), $none );
ok( '...and never falls back to listing every facility', ! str_contains( $none, 'Prox Probe Mercy' ) );

update_option( 'tdh_proximity_radius', 1 );
ob_start();
do_action( 'tdh_listing_proximity', $home );
$mile = (string) ob_get_clean();
delete_option( 'tdh_proximity_radius' ); // back to the default the run is pinned to

ok( 'a one-mile setting reads "1 mile", not "1 miles"', str_contains( $mile, 'within 1 mile' ) && ! str_contains( $mile, 'within 1 miles' ), $mile );
ok( 'and half miles keep their decimal', '2.5 miles' === Proximity::miles_phrase( 2.5 ) );

echo "\n=== the cache ===\n";

ok( 'the answer is cached on the home', is_array( get_post_meta( $home, '_tdh_nearest_facilities', true ) ) );

update_post_meta( $near_a, '_tdh_lat', '40.9000' );

ok( 'a facility that moves drops every cached answer', '' === get_post_meta( $home, '_tdh_nearest_facilities', true ) );
ok( '...so the order is worked out again', $near_b === ( Proximity::nearest( $home )['id'] ?? 0 ) );

update_post_meta( $near_a, '_tdh_lat', '40.4360' );
Proximity::nearest( $home );
update_post_meta( $home, '_tdh_lat', '40.4500' );

ok( 'a home that moves drops its own answer', '' === get_post_meta( $home, '_tdh_nearest_facilities', true ) );

echo "\n=== the staff setting ===\n";

$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0] ?? null;

if ( $admin ) {
	wp_set_current_user( $admin->ID );

	$post = static function ( string $count, string $radius ): array {
		$_POST = [
			'tdh_action' => 'proximity_save',
			'tdh_count'  => $count,
			'tdh_radius' => $radius,
			'tdh_nonce'  => wp_create_nonce( 'tdh_proximity_save' ),
		];
		$where  = redirect_of( [ new Accounts(), 'handle_forms' ] );
		$_POST  = [];
		$notice = Accounts::take_notice();

		return [ $where, $notice ];
	};

	[ $where, $notice ] = $post( '2', '8' );

	ok( 'saving lands back on Listing setup', str_contains( $where, 'view=listing-setup' ), $where );
	ok( '...and the confirmation says what a property page will now do', str_contains( (string) ( $notice['success'] ?? '' ), 'nearest 2 facilities within 8 miles' ), (string) ( $notice['success'] ?? '' ) );
	ok( '...and the numbers are stored', 2 === Proximity::count_setting() && 8.0 === Proximity::radius_setting() );

	[ , $notice ] = $post( '12', '8' );

	ok( 'a count above the limit is refused, and names the limit', str_contains( implode( ' ', (array) ( $notice['errors'] ?? [] ) ), 'between 1 and 5 facilities' ), wp_json_encode( $notice['errors'] ?? [] ) );
	ok( '...and nothing is changed', 2 === Proximity::count_setting() );

	[ , $notice ] = $post( '2', '0' );

	ok( 'a distance below the limit is refused the same way', str_contains( implode( ' ', (array) ( $notice['errors'] ?? [] ) ), 'between 1 and 100 miles' ) );
	ok( '...and nothing is changed', 8.0 === Proximity::radius_setting() );

	$_POST = [ 'tdh_action' => 'proximity_save', 'tdh_count' => '4', 'tdh_radius' => '20', 'tdh_nonce' => 'not-a-nonce' ];
	redirect_of( [ new Accounts(), 'handle_forms' ] );
	$_POST = [];
	Accounts::take_notice();

	ok( 'an expired form changes nothing', 2 === Proximity::count_setting() );

	$_GET = [ 'view' => 'listing-setup' ];
	$page = Account_Render::dashboard();
	$_GET = [];

	ok( 'Listing setup shows the two fields with their limits', str_contains( $page, 'Hospitals on a property page' ) && str_contains( $page, 'max="5"' ) && str_contains( $page, 'max="100"' ) );
	ok( '...filled in with what is stored', str_contains( $page, 'value="2"' ) && str_contains( $page, 'value="8"' ) );

	// A landlord must not be able to change a site-wide setting. The suite
	// makes its own landlord: on a clean install none exists yet, and this
	// check silently not running is exactly the gap a refusal test must not
	// have.
	$existing = get_user_by( 'login', 'tdh_prox_landlord' );
	if ( $existing ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $existing->ID );
	}
	$landlord_id = wp_insert_user(
		[
			'user_login'   => 'tdh_prox_landlord',
			'user_email'   => 'tdh_prox_landlord@example.com',
			'user_pass'    => wp_generate_password( 20 ),
			'role'         => 'tdh_landlord',
			'display_name' => 'Prox Probe Landlord',
		]
	);

	if ( ! is_wp_error( $landlord_id ) ) {
		wp_set_current_user( (int) $landlord_id );
		$post( '5', '50' );
		ok( 'a landlord cannot change it', 2 === Proximity::count_setting(), (string) Proximity::count_setting() );
		wp_set_current_user( $admin->ID );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( (int) $landlord_id );
	} else {
		ok( 'a landlord cannot change it', false, 'could not create the landlord: ' . $landlord_id->get_error_message() );
	}

	Proximity::save_settings( Proximity::DEFAULT_COUNT, (float) Proximity::DEFAULT_RADIUS );
} else {
	echo "  (no administrator on this site — staff checks skipped)\n";
}

echo "\n=== cleanup ===\n";

remove_filter( 'tdh_facility_query_args', $hide_real );

foreach ( $created as $id ) {
	wp_delete_post( $id, true );
}

// The staff setting, exactly as it was found.
foreach ( $setting_was as $name => $value ) {
	if ( null === $value ) {
		delete_option( $name );
	} else {
		update_option( $name, $value );
	}
}
ok( 'the staff setting is left as it was found', $setting_was['tdh_proximity_radius'] === get_option( 'tdh_proximity_radius', null ) && $setting_was['tdh_proximity_count'] === get_option( 'tdh_proximity_count', null ) );
$_GET = [];
$_POST = [];
wp_set_current_user( 0 );
echo "  fixtures removed\n";

[ $passed, $failed ] = ok();
printf( "\n%s  %d passed, %d failed\n", $failed ? 'FAILED' : 'PASSED', $passed, $failed );
if ( $failed && defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::halt( 1 );
}
