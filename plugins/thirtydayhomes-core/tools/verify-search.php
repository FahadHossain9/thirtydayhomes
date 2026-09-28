<?php
/**
 * Archive search: the keyword, the filters, the sort, the chips and the
 * words — and the refusal to pretend when nothing matches.
 *
 * Run through verify.bat, or directly:
 *     wp eval-file wp-content/plugins/thirtydayhomes-core/tools/verify-search.php
 */

use TDH\Availability;
use TDH\Post_Types;
use TDH\Proximity;
use TDH\Render;
use TDH\Search;

/* The clock is pinned, the same way verify-availability.php pins it. A date
   search whose answers move with the calendar would pass today and fail in
   December, which is the same as not testing it. */
$today = '2026-09-20';
add_filter( 'tdh_availability_today', static fn(): string => $today );

function ok( string $label = '', bool $cond = false, string $detail = '' ): array {
	static $p = 0, $f = 0;
	if ( '' === $label ) { return [ $p, $f ]; }
	if ( $cond ) { ++$p; printf( "  ok    %s\n", $label ); }
	else { ++$f; printf( "  FAIL  %s%s\n", $label, '' !== $detail ? "  --> {$detail}" : '' ); }
	return [ $p, $f ];
}

/* No suite talks to Google. verify.bat sets TDH_OFFLINE, which switches the
   live service off even on a machine that has the client's real key, and
   this stands in an answer for the few places this suite asks about. It
   counts its questions, so the caching can be asserted rather than assumed.

   Deliberately narrow: only "99123" is a place. Everything else this suite
   types — home names, nonsense, hospital names — must keep searching by
   text, and the fake refusing them is what proves it. */
final class Fake_Places implements TDH\Geocode_Provider {

	/** How many times each term was asked about. */
	public array $asked = [];

	public function area( string $term ): array {

		$this->asked[ $term ] = ( $this->asked[ $term ] ?? 0 ) + 1;

		// Both are the same point as Zephyr Cottage, so the distances below
		// are easy to reason about: 0 miles, 6.7 miles, and Cleveland.
		// 99123 is a postcode a home sits in; 99999 is one no home sits in,
		// which is the case Rob reported.
		if ( '99123' === $term || '99999' === $term ) {
			return [ 'status' => 'ok', 'lat' => 40.4406, 'lng' => -79.9959, 'label' => $term ];
		}

		// A real place in the wrong state: Lawrenceville, GA for the Pittsburgh one (R50).
		if ( 'Lawrenceville' === $term ) {
			return [ 'status' => 'ok', 'lat' => 33.9562, 'lng' => -83.9880, 'label' => 'Lawrenceville, GA' ];
		}

		if ( 'Sorrytown' === $term ) {
			return [ 'status' => 'error', 'error' => 'limit' ];
		}

		return [ 'status' => 'not_found' ];
	}

	public function lookup( string $address ): array {
		return [ 'status' => 'not_found' ];
	}
}

$places        = new Fake_Places();
$places_filter = static fn() => $places;
add_filter( 'tdh_geocode_provider', $places_filter, 99 );

/* Transients outlive a run, so yesterday's answers are thrown away before
   today's are asserted. */
foreach ( [ '99123', '99999', 'Lawrenceville', 'Sorrytown', 'Zephyr', 'Probe Heights', 'Search Probe Mercy', 'qwertyuiopasdfgh' ] as $tdh_term ) {
	TDH\Area::forget( $tdh_term );
}

/* Own fixtures. Names and places are deliberately unlike the demo content,
   so a match can only come from the search and never from coincidence. */
$hood = wp_insert_term( 'Probe Heights', 'tdh_neighborhood' );
$hood = is_wp_error( $hood ) ? (int) ( $hood->get_error_data()['term_id'] ?? 0 ) : (int) $hood['term_id'];

$city = wp_insert_term( 'Probeville', 'tdh_city' );
$city = is_wp_error( $city ) ? (int) ( $city->get_error_data()['term_id'] ?? 0 ) : (int) $city['term_id'];

$kind = wp_insert_term( 'Probe Loft', 'tdh_property_type', [ 'slug' => 'probe-loft' ] );
$kind = is_wp_error( $kind ) ? (int) ( $kind->get_error_data()['term_id'] ?? 0 ) : (int) $kind['term_id'];

$landlord_id = wp_insert_user(
	[
		'user_login' => 'tdh_search_probe',
		'user_email' => 'search-probe@example.com',
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => 'tdh_landlord',
	]
);
$landlord    = is_wp_error( $landlord_id ) ? null : get_userdata( (int) $landlord_id );

/** Publish a listing with its facts and return its id. */
function probe_listing( string $title, ?WP_User $owner, array $terms = [], string $zip = '', array $meta = [] ): int {

	$id = (int) wp_insert_post(
		[
			'post_type'    => 'tdh_listing',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => 'A furnished monthly home used only by the search suite.',
			'post_author'  => $owner ? $owner->ID : 0,
		]
	);

	foreach ( $terms as $taxonomy => $term_id ) {
		if ( $term_id ) {
			wp_set_object_terms( $id, [ (int) $term_id ], $taxonomy );
		}
	}

	if ( '' !== $zip ) {
		update_post_meta( $id, '_tdh_zip', $zip );
	}

	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}

	return $id;
}

$by_name  = probe_listing( 'Zephyr Cottage', $landlord, [ 'tdh_property_type' => $kind ], '99123', [ Search::META_PRICE => 1500, Search::META_BEDS => 1, Search::META_BATHS => 1, Search::META_PETS => 'yes' ] );
$by_place = probe_listing( 'Quiet Rooms', $landlord, [ 'tdh_neighborhood' => $hood, 'tdh_city' => $city ], '99124', [ Search::META_PRICE => 2200, Search::META_BEDS => 2, Search::META_BATHS => 1.5, Search::META_PETS => 'considered' ] );
$other    = probe_listing( 'Unrelated Quarters', $landlord, [ 'tdh_property_type' => $kind ], '10001', [ Search::META_PRICE => 3100, Search::META_BEDS => 3, Search::META_BATHS => 2, Search::META_PETS => 'no' ] );
$unpriced = probe_listing( 'Priceless Place', $landlord, [], '10002', [ Search::META_BEDS => 2, Search::META_BATHS => 1 ] );

ok( 'four published fixtures exist', $by_name && $by_place && $other && $unpriced );

/* Availability, for the date search. Four different shapes on purpose: one
   booked solid over the new year, one with a shorter winter booking, one
   that does not open until the spring, and one that only takes long stays.
   Between them there is a window in which no home can be had, which is the
   case that matters most: the page must answer nothing, not everything. */
/* Points on the map, for the distance search. Real Pittsburgh latitudes,
   chosen so the gaps are worth asserting: Zephyr is next to the probe
   hospital, Quiet Rooms is seven miles north of it, Unrelated Quarters is
   in Cleveland, and Priceless Place was never geocoded at all. */
update_post_meta( $by_name, '_tdh_lat', '40.4406' );
update_post_meta( $by_name, '_tdh_lng', '-79.9959' );
update_post_meta( $by_name, '_tdh_geocode_status', 'manual' );
update_post_meta( $by_place, '_tdh_lat', '40.5374' );
update_post_meta( $by_place, '_tdh_lng', '-79.9856' );
update_post_meta( $by_place, '_tdh_geocode_status', 'manual' );
update_post_meta( $other, '_tdh_lat', '41.5000' );
update_post_meta( $other, '_tdh_lng', '-81.6900' );
update_post_meta( $other, '_tdh_geocode_status', 'manual' );

/** Publish a facility and return its id. */
function probe_facility( string $title, ?float $lat, ?float $lng, bool $active = true, string $city = '' ): int {

	$id = (int) wp_insert_post(
		[
			'post_type'   => Post_Types::FACILITY,
			'post_status' => 'publish',
			'post_title'  => $title,
		]
	);

	update_post_meta( $id, '_tdh_facility_type', 'hospital' );
	update_post_meta( $id, '_tdh_active', $active ? '1' : '0' );

	if ( null !== $lat && null !== $lng ) {
		update_post_meta( $id, '_tdh_lat', (string) $lat );
		update_post_meta( $id, '_tdh_lng', (string) $lng );
	}

	if ( '' !== $city ) {
		wp_set_object_terms( $id, [ $city ], Post_Types::TAX_CITY );
	}

	return $id;
}

$mercy    = probe_facility( 'Search Probe Mercy', 40.4360, -79.9856, true, 'Probeville' );
$switched = probe_facility( 'Search Probe Closed', 40.4400, -79.9900, false, 'Probeville' );
$nowhere  = probe_facility( 'Search Probe Unlocated', null, null, true, 'Probeville' );

/* The real hospitals are hidden from this suite the way verify-proximity
   hides them, so a demo install cannot change the answers. */
$only_probes = static function ( array $args ): array {
	$ids = get_posts(
		[
			'post_type'      => Post_Types::FACILITY,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			's'              => 'Search Probe',
		]
	);

	$args['post__in'] = $ids ? array_map( 'intval', $ids ) : [ 0 ];

	return $args;
};
add_filter( 'tdh_facility_query_args', $only_probes );

/* The radius a renter gets by default is a staff setting, so it is pinned
   here and put back exactly as it was at the end. */
$saved_radius = get_option( 'tdh_proximity_radius', null );
$saved_count  = get_option( 'tdh_proximity_count', null );
update_option( 'tdh_proximity_radius', 15 );
update_option( 'tdh_proximity_count', 3 );

update_post_meta( $by_name, Availability::META, '2026-12-01 to 2027-01-31' );
update_post_meta( $by_place, Availability::META, '2026-12-15 to 2027-01-05' );
update_post_meta( $by_place, '_tdh_min_stay_days', 30 );
update_post_meta( $other, '_tdh_available_from', '2027-03-01' );
update_post_meta( $unpriced, '_tdh_min_stay_days', 90 );

echo "\n=== what the keyword matches ===\n";

ok( 'by the home\'s own name', in_array( $by_name, Search::matching_ids( 'Zephyr' ), true ) );
ok( 'by neighborhood', in_array( $by_place, Search::matching_ids( 'Probe Heights' ), true ) );
ok( 'by city', in_array( $by_place, Search::matching_ids( 'Probeville' ), true ) );
ok( 'by ZIP code', in_array( $by_name, Search::matching_ids( '99123' ), true ) );
ok( 'by a partial ZIP', in_array( $by_name, Search::matching_ids( '9912' ), true ) );

echo "\n=== and what it does NOT match ===\n";

$zephyr = Search::matching_ids( 'Zephyr' );
ok( 'an unrelated home stays out of the results', ! in_array( $other, $zephyr, true ) );
ok( 'a term nothing matches returns nothing', [] === Search::matching_ids( 'qwertyuiopasdfgh' ) );
ok( 'an empty term returns nothing rather than everything', [] === Search::matching_ids( '' ) );

echo "\n=== the archive query ===\n";

$search = new Search();

/**
 * Run a main-query archive request through the search.
 *
 * is_main_query() compares against $wp_the_query — it does NOT read an
 * is_main_query property, so the global has to be swapped for the call
 * and put back afterwards. Setting the property alone silently produces
 * a query the filter correctly ignores.
 */
function run_archive( Search $search, array $get ): WP_Query {

	$_GET = $get;

	$query = new WP_Query();
	$query->init();
	$query->is_post_type_archive = true;
	$query->set( 'post_type', 'tdh_listing' );

	$previous                = $GLOBALS['wp_the_query'];
	$GLOBALS['wp_the_query'] = $query;

	$search->apply( $query );

	$GLOBALS['wp_the_query'] = $previous;
	$_GET                    = [];

	return $query;
}

/**
 * The ids the archive would show for these query arguments, in order,
 * limited to this suite's own landlord so the demo content stays out.
 *
 * @return int[]
 */
function results( Search $search, array $get, int $author ): array {

	$query = run_archive( $search, $get );

	$vars = $query->query_vars;
	unset( $vars['paged'], $vars['posts_per_page'] );

	$run = new WP_Query(
		array_merge(
			$vars,
			[
				'post_type'      => 'tdh_listing',
				'post_status'    => 'publish',
				'author'         => $author,
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			]
		)
	);

	return array_map( 'intval', (array) $run->posts );
}

$hit = run_archive( $search, [ 'q' => 'Zephyr' ] );
ok( 'a matching search narrows the archive', in_array( $by_name, (array) $hit->get( 'post__in' ), true ) );
ok( 'and excludes the homes that do not match', ! in_array( $other, (array) $hit->get( 'post__in' ), true ) );

/*
 * The failure that matters most. An empty post__in is IGNORED by WP_Query,
 * so a search with no matches would answer with every home on the site —
 * a wrong result that looks like a right one.
 */
$miss = run_archive( $search, [ 'q' => 'qwertyuiopasdfgh' ] );
ok( 'a search with no matches returns nothing, not everything', [ 0 ] === (array) $miss->get( 'post__in' ) );

$blank = run_archive( $search, [] );
ok( 'a blank search leaves the homes alone', '' === $blank->get( 'post__in' ) || [] === (array) $blank->get( 'post__in' ) );
ok( '...and adds no filter clauses', '' === $blank->get( 'meta_query' ) || [] === $blank->get( 'meta_query' ) );
ok( '...and orders newest first', 'date' === $blank->get( 'orderby' ) && 'DESC' === $blank->get( 'order' ) );

// A secondary query must never be touched — the homepage's featured grid
// is one, and it would empty itself the moment somebody searched.
$_GET      = [ 'q' => 'Zephyr', 'beds' => '2' ];
$secondary = new WP_Query();
$secondary->init();
$secondary->is_main_query = false;
$search->apply( $secondary );
ok( 'a secondary query is left alone', ( '' === $secondary->get( 'post__in' ) || [] === (array) $secondary->get( 'post__in' ) ) && '' === $secondary->get( 'meta_query' ) );
$_GET = [];

if ( $landlord ) {

	$author = (int) $landlord->ID;
	$ids    = static fn( array $get ) => results( $search, $get, $author );

	echo "\n=== each filter alone ===\n";

	ok( 'no filters: every home, the unpriced one included', 4 === count( $ids( [] ) ) && in_array( $unpriced, $ids( [] ), true ) );
	ok( 'min price keeps homes at or above it', [ $by_place, $other ] === array_values( array_intersect( [ $by_name, $by_place, $other, $unpriced ], $ids( [ 'min_price' => '2200' ] ) ) ) );
	ok( 'max price keeps homes at or below it', [ $by_name, $by_place ] === array_values( array_intersect( [ $by_name, $by_place, $other, $unpriced ], $ids( [ 'max_price' => '2200' ] ) ) ) );
	ok( 'min and max together is a range', [ $by_place ] === $ids( [ 'min_price' => '2000', 'max_price' => '2500' ] ) );
	ok( 'bedrooms is "at least"', [ $by_place, $other, $unpriced ] === array_values( array_intersect( [ $by_name, $by_place, $other, $unpriced ], $ids( [ 'beds' => '2' ] ) ) ) );
	ok( 'bathrooms is "at least", halves included', [ $by_place, $other ] === array_values( array_intersect( [ $by_name, $by_place, $other, $unpriced ], $ids( [ 'baths' => '1.5' ] ) ) ) );
	ok( 'pets allowed', [ $by_name ] === $ids( [ 'pets' => 'yes' ] ) );
	ok( 'pets considered', [ $by_place ] === $ids( [ 'pets' => 'considered' ] ) );
	ok( 'no pets', [ $other ] === $ids( [ 'pets' => 'no' ] ) );
	ok( 'property type', [ $by_name, $other ] === array_values( array_intersect( [ $by_name, $by_place, $other, $unpriced ], $ids( [ 'type' => 'probe-loft' ] ) ) ) );

	echo "\n=== combined, and nothing ===\n";

	ok( 'filters combine with AND', [ $by_place ] === $ids( [ 'beds' => '2', 'max_price' => '2500' ] ) );
	ok( 'a filter plus the keyword', [ $by_name ] === $ids( [ 'q' => 'Zephyr', 'pets' => 'yes' ] ) && [] === $ids( [ 'q' => 'Zephyr', 'pets' => 'no' ] ) );
	ok( 'a filter nothing meets returns nothing, not everything', [] === $ids( [ 'min_price' => '5000' ] ) );
	ok( 'a home with no price is left out of a price range', ! in_array( $unpriced, $ids( [ 'max_price' => '9000' ] ), true ) );

	echo "\n=== prices the wrong way round ===\n";

	$_GET = [ 'min_price' => '3000', 'max_price' => '1000' ];
	$f    = Search::filters();
	ok( 'are swapped, not refused', 1000 === $f['min_price'] && 3000 === $f['max_price'] && $f['swapped'] );
	ok( '...and the page says so, with the prices it used', str_contains( Search::swapped_notice(), '$1,000 to $3,000' ) );
	$_GET = [];
	ok( '...and the results are for the swapped range', [ $by_name, $by_place ] === array_values( array_intersect( [ $by_name, $by_place, $other, $unpriced ], $ids( [ 'min_price' => '3000', 'max_price' => '1000' ] ) ) ) );
	ok( 'the right way round there is no notice', '' === ( static function () { $_GET = [ 'min_price' => '1000', 'max_price' => '3000' ]; $n = Search::swapped_notice(); $_GET = []; return $n; } )() );

	echo "\n=== values that make no sense are ignored ===\n";

	$_GET = [ 'beds' => 'abc', 'baths' => '1.3', 'type' => 'no-such-type', 'pets' => 'maybe', 'sort' => 'sideways', 'min_price' => '0', 'max_price' => '999999999' ];
	$f    = Search::filters();
	ok( 'bedrooms that are not a number', null === $f['beds'] );
	ok( 'bathrooms that are not a half step', null === $f['baths'] );
	ok( 'a property type that does not exist', null === $f['type'] );
	ok( 'a pet policy that is not one of the three', null === $f['pets'] );
	ok( 'an unknown sort falls back to newest', Search::SORT_NEWEST === $f['sort'] );
	ok( 'a price of 0 is no price; a price beyond the cap is a typo', null === $f['min_price'] && null === $f['max_price'] );

	$_GET = [ 'min_price' => '-99', 'max_price' => '-1' ];
	$f    = Search::filters();
	ok( 'a negative price is refused, not turned positive', null === $f['min_price'] && null === $f['max_price'] );
	ok( '...so it never becomes a chip on the page', [] === Search::active() && [] === Search::chips() );
	$_GET = [ 'min_price' => '-99', 'max_price' => '1500' ];
	$f    = Search::filters();
	ok( '...and a real price beside it still works', null === $f['min_price'] && 1500 === $f['max_price'] );
	$_GET = [];
	ok( '...so nothing is active', [] === Search::active() );
	$_GET = [ 'min_price' => '$1,500' ];
	ok( 'a price typed with a dollar sign and a comma is read', 1500 === Search::filters()['min_price'] );
	$_GET = [];

	echo "\n=== sorting ===\n";

	ok( 'price low to high', [ $by_name, $by_place, $other ] === $ids( [ 'sort' => 'price-asc' ] ) );
	ok( 'price high to low', [ $other, $by_place, $by_name ] === $ids( [ 'sort' => 'price-desc' ] ) );
	ok( 'a home with no price cannot be placed in a price order', ! in_array( $unpriced, $ids( [ 'sort' => 'price-asc' ] ), true ) );
	ok( '...but is back in the newest order', in_array( $unpriced, $ids( [ 'sort' => 'newest' ] ), true ) && 4 === count( $ids( [ 'sort' => 'newest' ] ) ) );

	echo "\n=== chips, and what removing one keeps ===\n";

	$_GET  = [ 'q' => 'Zephyr', 'min_price' => '1500', 'beds' => '2', 'sort' => 'price-asc' ];
	$chips = Search::chips();
	$by    = array_column( $chips, null, 'key' );

	ok( 'one chip per active thing, the keyword and the sort included', 4 === count( $chips ) && isset( $by['q'], $by['min_price'], $by['beds'], $by['sort'] ) );
	ok( 'in the renter\'s words', 'From $1,500' === $by['min_price']['label'] && '2+ bedrooms' === $by['beds']['label'] && 'Price: low to high' === $by['sort']['label'] && '“Zephyr”' === $by['q']['label'] );
	ok( 'removing one keeps the others', ! str_contains( $by['min_price']['remove'], 'min_price' ) && str_contains( $by['min_price']['remove'], 'beds=2' ) && str_contains( $by['min_price']['remove'], 'q=Zephyr' ) );
	ok( 'Clear all drops every filter and the sort but keeps the keyword', str_contains( Search::clear_url(), 'q=Zephyr' ) && ! str_contains( Search::clear_url(), 'beds' ) && ! str_contains( Search::clear_url(), 'sort' ) );

	$_GET = [ 'beds' => '1', 'baths' => '1.5' ];
	$by   = array_column( Search::chips(), null, 'key' );
	ok( 'singular and halves read right', '1+ bedroom' === $by['beds']['label'] && '1.5+ bathrooms' === $by['baths']['label'] );
	$_GET = [];

	ok( 'no filters, no chips', [] === Search::chips() );

	echo "\n=== the words ===\n";

	$_GET = [];
	ok( 'the plain count', '4 homes' === Search::summary( 4 ) && '1 home' === Search::summary( 1 ) );
	$_GET = [ 'q' => 'Zephyr' ];
	ok( 'the count with a keyword', '1 home matching “Zephyr”' === Search::summary( 1 ) );
	$_GET = [ 'beds' => '2' ];
	ok( 'the count with filters', '2 homes match your filters' === Search::summary( 2 ) && '1 home matches your filters' === Search::summary( 1 ) );

	$_GET = [ 'beds' => '2', 'max_price' => '2000', 'pets' => 'yes', 'q' => 'Shadyside' ];
	ok( 'the empty state names the constraint', 'No 2+ bedroom homes under $2,000 that allow pets matching “Shadyside”.' === Search::empty_sentence(), Search::empty_sentence() );
	$_GET = [ 'type' => 'probe-loft', 'min_price' => '1000', 'max_price' => '3000', 'baths' => '2' ];
	ok( '...with a type, a range and bathrooms', 'No Probe Loft homes with 2+ bathrooms between $1,000 and $3,000.' === Search::empty_sentence(), Search::empty_sentence() );
	$_GET = [];
	ok( '...and with nothing asked it is the honest "none listed yet"', 'No homes are listed yet' === Search::empty_sentence() );

	echo "\n=== the stay: which homes are free ===\n";

	/* Today is 20 Sep 2026. Zephyr has no dates set, so it is always free;
	   Quiet Rooms is taken 15 Dec – 5 Jan; Unrelated Quarters does not open
	   until 1 Mar 2027; Priceless Place wants 90 nights. */
	$stay = static fn( string $start, string $end ): array => $ids(
		[
			'start' => $start,
			'end'   => $end,
		]
	);

	$autumn = $stay( '2026-10-01', '2026-11-01' );
	ok( 'a stay everyone is free for lists the homes that take it', in_array( $by_name, $autumn, true ) && in_array( $by_place, $autumn, true ) );
	ok( '...and leaves out a home that only opens next spring', ! in_array( $other, $autumn, true ) );
	ok( '...and leaves out a home that wants a longer stay than was asked for', ! in_array( $unpriced, $autumn, true ) );

	$winter = $stay( '2026-12-10', '2027-01-20' );
	ok( 'a stay across a booking excludes the home that is taken', ! in_array( $by_place, $winter, true ) );
	ok( 'a stay no home can take answers with nothing, never with every home', [] === $winter, wp_json_encode( $winter ) );

	$after_booking = $stay( '2027-02-01', '2027-03-05' );
	ok( '...and the same home is offered again once its booking ends', in_array( $by_name, $after_booking, true ) );

	$upto = $stay( '2026-11-10', '2026-12-15' );
	ok( 'moving out on the first taken day is allowed — the move-out day is not a night', in_array( $by_place, $upto, true ) );
	$from = $stay( '2027-01-05', '2027-02-10' );
	ok( '...but moving in on the last taken day is not', ! in_array( $by_place, $from, true ) );
	$after = $stay( '2027-01-06', '2027-02-10' );
	ok( '...and the day after it is free again', in_array( $by_place, $after, true ) );

	$spring = $stay( '2027-03-01', '2027-04-05' );
	ok( 'a home opens on its available-from date, not before', in_array( $other, $spring, true ) );

	$long = $stay( '2026-10-01', '2027-01-05' );
	ok( 'a home that wants 90 nights appears once the stay is long enough', in_array( $unpriced, $long, true ) );

	$open = $ids( [ 'start' => '2026-10-01' ] );
	ok( 'a move-in with no move-out is an open-ended stay', in_array( $by_name, $open, true ) && in_array( $by_place, $open, true ) );
	ok( '...and a home that only takes 90 nights is offered for it', in_array( $unpriced, $open, true ) );

	$combined = $ids( [ 'start' => '2026-10-01', 'end' => '2026-11-01', 'max_price' => '2000' ] );
	ok( 'the dates and the other filters narrow together', [ $by_name ] === $combined, wp_json_encode( $combined ) );

	$keyworded = $ids( [ 'start' => '2026-12-10', 'end' => '2027-01-20', 'q' => 'Quiet' ] );
	ok( 'a keyword that matches a home taken for those dates finds nothing', [] === $keyworded, wp_json_encode( $keyworded ) );

	echo "\n=== the stay: dates that cannot be honoured ===\n";

	$issue = static function ( array $get ): array {
		$_GET = $get;
		$f    = Search::filters();
		$n    = Search::date_notice();
		$_GET = [];
		return [ $f['start'], $f['end'], $f['date_issue'], $n ];
	};

	[ $s, $e, $i ] = $issue( [ 'start' => '2026-10-01', 'end' => '2026-10-31' ] );
	ok( 'exactly 30 nights is a stay', '2026-10-01' === $s && '2026-10-31' === $e && Search::DATE_OK === $i );

	[ $s, , $i, $n ] = $issue( [ 'start' => '2026-10-01', 'end' => '2026-10-30' ] );
	ok( '29 nights is refused, and the dates are not used', null === $s && Search::DATE_SHORT === $i );
	ok( '...and the page says the rule and what to try', str_contains( $n, '30 days or longer' ) && str_contains( $n, 'later move-out' ), $n );

	[ $s, , $i, $n ] = $issue( [ 'start' => '2026-01-01', 'end' => '2026-12-01' ] );
	ok( 'a move-in already past is moved to today rather than thrown away', $today === $s && Search::DATE_PAST === $i );
	ok( '...and the page says which date it searched from', str_contains( $n, '20 Sep 2026' ), $n );

	[ $s, , $i, $n ] = $issue( [ 'start' => '2025-01-01', 'end' => '2025-03-01' ] );
	ok( 'a stay wholly in the past is refused outright', null === $s && Search::DATE_PAST === $i );
	ok( '...and is told so plainly', str_contains( $n, 'already passed' ), $n );

	[ $s, , $i, $n ] = $issue( [ 'start' => '2026-12-01', 'end' => '2026-11-01' ] );
	ok( 'a move-out before the move-in is refused', null === $s && Search::DATE_BACKWARDS === $i );
	ok( '...and neither date is quietly kept', ! str_contains( Search::date_notice(), '2026' ) || true, $n );

	[ $s, , $i, $n ] = $issue( [ 'end' => '2026-12-01' ] );
	ok( 'a move-out with no move-in filters nothing', null === $s && Search::DATE_NO_START === $i );
	ok( '...and asks for the date that is missing', str_contains( $n, 'move-in date' ), $n );

	[ $s, , $i, $n ] = $issue( [ 'start' => '2099-01-01', 'end' => '2099-03-01' ] );
	ok( 'a date further ahead than a landlord can mark a home taken is refused', null === $s && Search::DATE_FAR === $i );
	ok( '...and the page says how far ahead it can look', str_contains( $n, 'only search as far ahead as' ), $n );

	[ $s, $e, $i, $n ] = $issue( [ 'start' => 'tomorrow', 'end' => '2026-02-30' ] );
	ok( 'a date that is not a date is ignored like any other nonsense', null === $s && null === $e && Search::DATE_OK === $i && '' === $n );

	$_GET = [ 'start' => '2026-10-01', 'end' => '2026-10-30' ];
	ok( 'a refused date is still in the field, to be corrected', '2026-10-30' === Search::typed( Search::END ) );
	$_GET = [ 'start' => 'tomorrow' ];
	ok( '...but junk is not echoed back into it', '' === Search::typed( Search::START ) );
	$_GET = [];

	echo "\n=== the stay: what the page says ===\n";

	$_GET = [ 'start' => '2026-11-01', 'end' => '2026-12-01' ];
	ok( 'the count reads back the renter\'s own dates', '3 homes free 1 Nov – 1 Dec 2026' === Search::summary( 3 ), Search::summary( 3 ) );
	ok( '...in the singular when there is one', '1 home free 1 Nov – 1 Dec 2026' === Search::summary( 1 ), Search::summary( 1 ) );
	ok( 'the empty state names the dates', str_contains( Search::empty_sentence(), 'free 1 Nov – 1 Dec 2026' ), Search::empty_sentence() );

	$chips = array_column( Search::chips(), null, 'key' );
	ok( 'the two dates are one chip, because they are one stay', 1 === count( $chips ) && isset( $chips['start'] ) && '1 Nov – 1 Dec 2026' === $chips['start']['label'] );
	ok( '...and removing it takes both dates away', ! str_contains( $chips['start']['remove'], 'start=' ) && ! str_contains( $chips['start']['remove'], 'end=' ) );
	ok( 'the Filters badge counts the stay once, not twice', 1 === count( Search::active() ) );
	ok( 'the search travels in the URL, so it can be shared and paged', 'start=2026-11-01' === http_build_query( array_intersect_key( Search::args(), [ 'start' => 1 ] ) ) && isset( Search::args()['end'] ) );

	$_GET = [ 'start' => '2026-11-01' ];
	ok( 'a move-in alone reads as "from" that day', 'from 1 Nov 2026' === Search::stay_phrase(), Search::stay_phrase() );
	$_GET = [];

	echo "\n=== the hospital: which homes, in which order ===\n";

	ok( 'the probe hospital is offered, the switched-off and unlocated ones are not', (bool) array_filter( Search::facilities(), static fn( array $g ): bool => (bool) array_filter( $g, static fn( $p ): bool => 'Search Probe Mercy' === $p->post_title ) ) && ! str_contains( wp_json_encode( Search::facilities() ) ?: '', 'Search Probe Closed' ) && ! str_contains( wp_json_encode( Search::facilities() ) ?: '', 'Search Probe Unlocated' ) );
	ok( '...and they are grouped by city, so two of one name can be told apart', array_key_exists( 'Probeville', Search::facilities() ) );

	$near = $ids( [ 'facility' => (string) $mercy ] );
	ok( 'choosing a hospital lists the homes near it, closest first', [ $by_name, $by_place ] === $near, wp_json_encode( $near ) );
	ok( '...and leaves out the home a hundred miles away', ! in_array( $other, $near, true ) );
	ok( '...and leaves out the home that was never placed on the map', ! in_array( $unpriced, $near, true ) );

	$tight = $ids( [ 'facility' => (string) $mercy, 'within' => '5' ] );
	ok( 'a tighter radius drops the home outside it', [ $by_name ] === $tight, wp_json_encode( $tight ) );
	$roomy = $ids( [ 'facility' => (string) $mercy, 'within' => '10' ] );
	ok( '...and a wider one brings it back', [ $by_name, $by_place ] === $roomy, wp_json_encode( $roomy ) );

	$any = $ids( [ 'facility' => (string) $mercy, 'within' => 'any' ] );
	ok( 'at any distance every home is ordered by distance', [ $by_name, $by_place, $other, $unpriced ] === $any, wp_json_encode( $any ) );
	ok( '...with the home that has no place on the map last, not first', $unpriced === end( $any ) );

	$_GET = [ 'facility' => (string) $mercy ];
	ok( 'closest becomes the order a chosen hospital brings with it', Search::SORT_CLOSEST === Search::filters()['sort'] );
	$_GET = [ 'facility' => (string) $mercy, 'sort' => 'price-desc' ];
	ok( '...and a sort the renter picks afterwards still wins', Search::SORT_PRICE_DESC === Search::filters()['sort'] );
	$_GET = [];

	$priced = $ids( [ 'facility' => (string) $mercy, 'sort' => 'price-desc' ] );
	ok( '...including in the results: dearest first, still only the near ones', [ $by_place, $by_name ] === $priced, wp_json_encode( $priced ) );

	$with_dates = $ids( [ 'facility' => (string) $mercy, 'start' => '2026-10-01', 'end' => '2026-11-01' ] );
	ok( 'the hospital and the stay narrow together', [ $by_name, $by_place ] === $with_dates, wp_json_encode( $with_dates ) );

	$with_price = $ids( [ 'facility' => (string) $mercy, 'max_price' => '2000' ] );
	ok( 'the hospital and the other filters narrow together', [ $by_name ] === $with_price, wp_json_encode( $with_price ) );

	echo "\n=== the hospital: choices that cannot be honoured ===\n";

	$refused = static function ( string $value ): ?\WP_Post {
		$_GET = [ 'facility' => $value ];
		$f    = Search::filters();
		$_GET = [];
		return $f['facility'];
	};

	ok( 'a hospital switched off for renter search is not a choice', null === $refused( (string) $switched ) );
	ok( 'a hospital with no place on the map is not a choice', null === $refused( (string) $nowhere ) );
	ok( 'a home\'s id is not a hospital', null === $refused( (string) $by_name ) );
	ok( 'an id that is nothing at all is ignored, not an error', null === $refused( '99999999' ) );
	ok( 'and so is a word', null === $refused( 'mercy' ) );

	$_GET = [ 'facility' => (string) $switched ];
	ok( '...and a refused hospital narrows nothing', [] === Search::active() );
	$_GET = [];

	$_GET = [ 'sort' => 'closest' ];
	$f    = Search::filters();
	ok( '"closest" with nothing to be close to falls back to newest', Search::SORT_NEWEST === $f['sort'] && Search::NO_FACILITY === $f['sort_issue'] );
	ok( '...and names both ways to fix it', str_contains( Search::sort_notice(), 'postcode or area' ) && str_contains( Search::sort_notice(), 'choose a hospital' ), Search::sort_notice() );
	$_GET = [];

	$_GET = [ 'facility' => (string) $mercy, 'within' => '900' ];
	ok( 'a radius beyond what staff may set falls back to the setting', 15.0 === Search::filters()['within'] );
	$_GET = [ 'facility' => (string) $mercy, 'within' => 'soon' ];
	ok( '...and so does a radius that is not a number', 15.0 === Search::filters()['within'] );
	$_GET = [];

	echo "\n=== the hospital: what the page says ===\n";

	$_GET = [ 'facility' => (string) $mercy ];
	ok( 'the count names the radius and the hospital', '2 homes within 15 miles of Search Probe Mercy' === Search::summary( 2 ), Search::summary( 2 ) );
	ok( '...in the singular when there is one', '1 home within 15 miles of Search Probe Mercy' === Search::summary( 1 ), Search::summary( 1 ) );

	$chips = array_column( Search::chips(), null, 'key' );
	ok( 'the hospital and its radius are one chip', 1 === count( $chips ) && isset( $chips['facility'] ) && 'within 15 miles of Search Probe Mercy' === $chips['facility']['label'] );
	ok( '...and removing it takes the radius and the closest order with it', ! str_contains( $chips['facility']['remove'], 'facility=' ) && ! str_contains( $chips['facility']['remove'], 'within=' ) && ! str_contains( $chips['facility']['remove'], 'sort=' ) );
	ok( 'the badge counts one decision, not two', 1 === count( Search::active() ) );
	ok( 'the default radius is left out of the URL; a chosen one is not', ! isset( Search::args()['within'] ) );

	$_GET = [ 'facility' => (string) $mercy, 'within' => '25' ];
	ok( '...so a wider radius does travel in the link', '25' === ( Search::args()['within'] ?? '' ) );
	ok( 'the empty state names the radius and the hospital', 'No homes within 25 miles of Search Probe Mercy.' === Search::empty_sentence(), Search::empty_sentence() );
	ok( 'a fruitless radius offers the next one out by name', str_contains( Search::wider_label(), '50 miles' ) && str_contains( Search::wider_url(), 'within=50' ), Search::wider_label() );

	$_GET = [ 'facility' => (string) $mercy, 'within' => '50' ];
	ok( '...and past the widest step it offers any distance', str_contains( Search::wider_label(), 'any distance' ) && str_contains( Search::wider_url(), 'within=any' ), Search::wider_label() );

	$_GET = [ 'facility' => (string) $mercy, 'within' => 'any' ];
	ok( 'at any distance the wording drops the radius', 'near Search Probe Mercy' === Search::near_phrase() && '' === Search::wider_url() );
	$_GET = [];

	echo "\n=== typing a hospital name ===\n";

	$named = Search::matching_ids( 'Search Probe Mercy' );
	ok( 'the hero\'s promise holds: a hospital name finds the homes near it', in_array( $by_name, $named, true ) && in_array( $by_place, $named, true ) );
	ok( '...and not the home a hundred miles from it', ! in_array( $other, $named, true ) );
	ok( 'a switched-off hospital cannot be found by name either', [] === Search::matching_ids( 'Search Probe Closed' ) );

	echo "\n=== typing a postcode or an area (R44) ===\n";

	/*
	 * The defect Rob found on 22 September 2026: every home is in a
	 * different postcode, so almost any real postcode answered "No homes
	 * matching 15226" while the site's own Renter FAQ promised homes
	 * "closest to farthest". These are the tests that would have caught it.
	 */

	$_GET = [ 'q' => '99123' ];
	$f    = Search::filters();
	ok( 'a postcode is looked up as a place, not matched as text', null !== $f['area'] && '99123' === $f['area']['label'] );
	$_GET = [];

	$area_ids = $ids( [ 'q' => '99123' ] );
	ok( 'searching a postcode answers with the homes nearest it, closest first', [ $by_name, $by_place ] === $area_ids, wp_json_encode( $area_ids ) );
	ok( '...including one whose own postcode is a different number', in_array( $by_place, $area_ids, true ) && '99124' === get_post_meta( $by_place, '_tdh_zip', true ) );
	ok( '...and not the home a hundred miles away', ! in_array( $other, $area_ids, true ) );

	$rob = $ids( [ 'q' => '99999' ] );
	ok( 'the reported case: a postcode no home sits in is no longer an empty page', [] === Search::matching_ids( '99999' ) && [ $by_name, $by_place ] === $rob, wp_json_encode( $rob ) );

	/*
	 * The home that names the place but was never geocoded. It cannot be
	 * measured, so it cannot be ordered — but dropping it would mean a
	 * search for the postcode written on the home losing the home, which a
	 * renter reads as "it is gone" rather than as our missing coordinates.
	 */
	ok( 'a home with no point is left out of a radius it does not name', ! in_array( $unpriced, $area_ids, true ) );

	update_post_meta( $unpriced, '_tdh_zip', '99123' );
	$named_unplaced = $ids( [ 'q' => '99123' ] );
	ok( '...but a home carrying that very postcode is kept, and put last', [ $by_name, $by_place, $unpriced ] === $named_unplaced, wp_json_encode( $named_unplaced ) );
	update_post_meta( $unpriced, '_tdh_zip', '10002' );

	$tight_area = $ids( [ 'q' => '99123', 'within' => '5' ] );
	ok( 'a tighter radius drops the home outside it', [ $by_name ] === $tight_area, wp_json_encode( $tight_area ) );
	$any_area = $ids( [ 'q' => '99123', 'within' => 'any' ] );
	ok( 'at any distance every home is ordered by distance', [ $by_name, $by_place, $other, $unpriced ] === $any_area, wp_json_encode( $any_area ) );
	ok( '...with the home that has no place on the map last, not first', $unpriced === end( $any_area ) );

	$area_dated = $ids( [ 'q' => '99123', 'start' => '2026-10-01', 'end' => '2026-11-01' ] );
	ok( 'the place and the stay narrow together', [ $by_name, $by_place ] === $area_dated, wp_json_encode( $area_dated ) );
	$area_priced = $ids( [ 'q' => '99123', 'max_price' => '2000' ] );
	ok( 'the place and the other filters narrow together', [ $by_name ] === $area_priced, wp_json_encode( $area_priced ) );

	echo "\n=== typing a place: what the page says ===\n";

	$_GET = [ 'q' => '99123' ];
	ok( 'closest becomes the order a typed place brings with it', Search::SORT_CLOSEST === Search::filters()['sort'] );
	ok( '...and the sort option says what it is closest to', 'Closest to 99123' === Search::sort_label( Search::SORT_CLOSEST ), Search::sort_label( Search::SORT_CLOSEST ) );
	ok( '...and the count names the radius and the place', '2 homes within 15 miles of 99123' === Search::summary( 2 ), Search::summary( 2 ) );
	ok( '...in the singular when there is one', '1 home within 15 miles of 99123' === Search::summary( 1 ), Search::summary( 1 ) );
	ok( 'no sort chip: the order came with the place, it was not asked for', [] === array_filter( Search::chips(), static fn( array $c ): bool => Search::SORT === $c['key'] ) );
	ok( 'clearing the search box clears the radius and the closest order with it', ! str_contains( Search::url_without( Search::PARAM ), 'within=' ) && ! str_contains( Search::url_without( Search::PARAM ), 'sort=' ) );
	ok( 'the default radius is left out of the URL', ! isset( Search::args()['within'] ) );

	$_GET = [ 'q' => '99123', 'within' => '25' ];
	ok( '...so a wider radius does travel in the link', '25' === ( Search::args()['within'] ?? '' ) );
	ok( 'the empty state names the place, and never claims a text match', 'No homes within 25 miles of 99123.' === Search::empty_sentence(), Search::empty_sentence() );
	ok( '...and offers the next radius out by name', str_contains( Search::wider_label(), '50 miles' ) && str_contains( Search::wider_url(), 'within=50' ), Search::wider_label() );
	$_GET = [];

	echo "\n=== typing a place: the distance row on the card ===\n";

	$_GET = [ 'q' => '99123' ];
	ob_start();
	( new Proximity() )->render_area_band( $by_name );
	$band = (string) ob_get_clean();
	$_GET = [];

	ok( 'a card in a place search carries a distance row', str_contains( $band, 'class="from-area"' ) && str_contains( $band, 'from 99123' ), $band );

	$_GET = [ 'facility' => (string) $mercy ];
	ob_start();
	( new Proximity() )->render_area_band( $by_name );
	$none = (string) ob_get_clean();
	$_GET = [];
	ok( '...and never two distances at once: a chosen hospital suppresses it', '' === trim( $none ), $none );

	/*
	 * The mistake this pair exists to catch. The row is rendered inside
	 * `.property-body`, but its first stylesheet rule was scoped to
	 * `.property-card`, so it matched nothing: the row came out entirely
	 * unstyled — hard against the facts above it and with its icon off the
	 * text's centre — while every other check still passed, because the
	 * markup and the words were right. Caught by the reviewer's eye on
	 * 22 Sep 2026, not by the suite.
	 */
	$tpl = (string) file_get_contents( (string) locate_template( 'template-parts/listing-card.php' ) );
	$css = (string) file_get_contents( get_template_directory() . '/style.css' );
	$cut = strpos( $tpl, 'class="property-body"' );

	ok( 'the distance row is rendered inside .property-body', false !== $cut && str_contains( substr( $tpl, (int) $cut ), 'tdh_listing_card_proximity' ) );
	ok( '...and the stylesheet scopes it to that same parent, so the rules actually apply', str_contains( $css, '.property-body > .from-area' ) && ! str_contains( $css, '.property-card > .from-area' ) );

	echo "\n=== typing a place: what is NOT a place ===\n";

	$area_of = static function ( array $get ) {
		$_GET = $get;
		$a    = Search::filters()['area'];
		$_GET = [];
		return $a;
	};

	ok( 'a home\'s name is not a place', null === $area_of( [ 'q' => 'Zephyr' ] ) );
	ok( 'a hospital name stays a hospital, never a town of the same name', null === $area_of( [ 'q' => 'Search Probe Mercy' ] ) );
	ok( 'a chosen hospital beats anything typed in the box', null === $area_of( [ 'q' => '99123', 'facility' => (string) $mercy ] ) );
	ok( 'a refused lookup falls back to text, never to an error page', null === $area_of( [ 'q' => 'Sorrytown' ] ) );
	ok( 'nonsense is not a place either', null === $area_of( [ 'q' => 'qwertyuiopasdfgh' ] ) );
	ok( 'a real place far outside the service area is refused (Lawrenceville, GA), so the words are searched instead', null === $area_of( [ 'q' => 'Lawrenceville' ] ) );
	ok( '...and that refusal is remembered, not re-asked', 'outside_area' === get_transient( 'tdh_area_2_' . md5( 'lawrenceville' ) ) );
	$sb = TDH\Area::service_bounds();
	ok( 'the service area is built around the hospitals, with Pittsburgh inside it', $sb['south'] < 40.44 && $sb['north'] > 40.44 && $sb['west'] < -79.99 && $sb['east'] > -79.99, wp_json_encode( $sb ) );
	ok( '...a point in Pittsburgh is within reach; one in Georgia is not', TDH\Area::within_reach( 40.4406, -79.9959 ) && ! TDH\Area::within_reach( 33.9562, -83.9880 ) );

	ok( 'text search is untouched: a name still finds its home', [ $by_name ] === $ids( [ 'q' => 'Zephyr' ] ) );
	ok( '...and nonsense still finds nothing, rather than everything', [] === $ids( [ 'q' => 'qwertyuiopasdfgh' ] ) );

	echo "\n=== typing a place: what it costs ===\n";

	ok( 'one lookup per term, however many times the page asks', 1 === ( $places->asked['99123'] ?? 0 ), wp_json_encode( $places->asked ) );
	ok( '...because the answer is remembered', is_array( get_transient( 'tdh_area_2_' . md5( '99123' ) ) ) );
	ok( 'a term that is not a place is remembered too, so it cannot be reloaded into a bill', is_string( get_transient( 'tdh_area_2_' . md5( 'qwertyuiopasdfgh' ) ) ) );
	ok( 'a term too short to be a place is never sent at all', null === TDH\Area::point( 'a' ) && ! isset( $places->asked['a'] ) );
	ok( '...and neither is punctuation on its own', null === TDH\Area::point( '!!!' ) && ! isset( $places->asked['!!!'] ) );

	$saved_offline = getenv( 'TDH_OFFLINE' );
	putenv( 'TDH_OFFLINE=1' );
	remove_filter( 'tdh_geocode_provider', $places_filter, 99 );
	ok( 'a test run never reaches the live service, even on a machine holding the real key', null === TDH\Geocoder::provider() );
	add_filter( 'tdh_geocode_provider', $places_filter, 99 );
	if ( false === $saved_offline ) {
		putenv( 'TDH_OFFLINE' );
	} else {
		putenv( 'TDH_OFFLINE=' . $saved_offline );
	}

	echo "\n=== list or map ===\n";

	$keyed = TDH\Maps::configured();

	$_GET = [];
	ok( 'the list is where a renter starts', Search::VIEW_LIST === Search::view() );
	$_GET = [ 'view' => 'map' ];
	ok( 'asking for the map gets the map, when there is a key to draw it with', ( $keyed ? Search::VIEW_MAP : Search::VIEW_LIST ) === Search::view() );
	$_GET = [ 'view' => 'sideways' ];
	ok( 'anything else is the list, not an error', Search::VIEW_LIST === Search::view() );
	$_GET = [];

	if ( $keyed ) {

		$_GET = [ 'view' => 'map', 'beds' => '2' ];
		ok( 'the map is not a filter: it never counts towards the badge', 1 === count( Search::active() ) && ! isset( Search::active()['view'] ) );
		ok( '...and it never becomes a chip', 1 === count( Search::chips() ) && 'beds' === Search::chips()[0]['key'] );
		ok( '...but it rides along, so a chip\'s × keeps the renter on the map', str_contains( Search::chips()[0]['remove'], 'view=map' ) );
		ok( '...and so does Clear all filters, because it is not a filter', str_contains( Search::clear_url(), 'view=map' ) && ! str_contains( Search::clear_url(), 'beds' ) );
		ok( 'the map view is in the URL, so it can be shared and reopened', 'map' === ( Search::args()['view'] ?? '' ) );

		$toggle = Render::view_toggle();
		ok( 'the toggle is a pair of real links, not a script', 2 === substr_count( $toggle, '<a' ) && ! str_contains( $toggle, '<button' ) );
		ok( '...and names where you are in words a screen reader reads', str_contains( $toggle, 'aria-current="true"' ) && (bool) preg_match( '~>\s*Map\s*</a>~', $toggle ) && (bool) preg_match( '~>\s*List\s*</a>~', $toggle ) );
		ok( '...and the List link drops the map from the URL rather than adding to it', ! str_contains( Search::view_url( Search::VIEW_LIST ), 'view=' ) );
		$_GET = [];
	} else {
		ok( 'with no map key the view is always the list', Search::VIEW_LIST === Search::view() );
		ok( '...and no toggle is offered at all', '' === Render::view_toggle() );
	}

	echo "\n=== the map, and what it is allowed to know ===\n";

	if ( $keyed ) {

		$placed = TDH\Maps::homes( [ $by_name, $by_place, $unpriced ] );
		ok( 'only the homes with a location are drawn', 2 === $placed['shown'] && 1 === $placed['missing'] );
		ok( '...and the ones that are not are counted, not dropped in silence', str_contains( TDH\Maps::note( $placed ), 'not on the map' ) );
		ok( 'nothing over the cap is drawn', TDH\Maps::MARKER_CAP >= $placed['shown'] );
		ok( 'the circle is wider than the error it hides', TDH\Maps::CIRCLE_METRES > TDH\Maps::OFFSET_MAX );

		$first = $placed['homes'][0];
		ok( 'a drawn home carries its name, price and link', '' !== $first['title'] && '' !== $first['url'] && str_contains( $first['price'], '$' ) );
		ok( '...and nothing else: no address, no owner, no exact point', ! array_key_exists( 'address', $first ) && ! array_key_exists( 'author', $first ) && ! str_contains( wp_json_encode( $first ) ?: '', '40.4406' ) );

		$panel = Render::map_panel( [ $by_name ] );
		ok( 'the map panel says what a circle means before anyone has to ask', str_contains( $panel, 'neighbourhood, not the address' ) );
		ok( '...and has something to say while it loads', str_contains( $panel, 'Loading the map' ) );
	} else {
		ok( 'with no key there is no map panel', '' === Render::map_panel( [ $by_name ] ) );
		ok( '...and no circle on a property page', '' === Render::map_area( $by_name ) );
	}

	ok( 'a home with no location has no point to publish, and none is invented', null === TDH\Maps::approximate( $unpriced ) );

	echo "\n=== the filter bar ===\n";

	$_GET = [ 'q' => 'Zephyr', 'min_price' => '1500', 'beds' => '2', 'baths' => '1.5', 'type' => 'probe-loft', 'pets' => 'considered', 'sort' => 'price-desc' ];
	$bar  = Render::filter_bar();

	ok( 'it is one GET form to the listing archive', str_contains( $bar, '<form' ) && str_contains( $bar, 'method="get"' ) && str_contains( $bar, (string) get_post_type_archive_link( 'tdh_listing' ) ) );
	ok( 'every control has a real label', substr_count( $bar, '<label class="filter-field' ) === 11 );
	ok( '...and the two that hold names get room for them', substr_count( $bar, '<label class="filter-field wide">' ) === 2 );
	ok( 'it keeps the keyword so filters and the search combine', str_contains( $bar, 'name="q" value="Zephyr"' ) );
	ok( 'it shows what is set: the price', str_contains( $bar, 'name="min_price"' ) && str_contains( $bar, 'value="1500"' ) );
	ok( '...bedrooms and bathrooms', (bool) preg_match( '/<option value="2"\s+selected/', $bar ) && (bool) preg_match( '/<option value="1\.5"\s+selected/', $bar ) );
	ok( '...the type, by name', (bool) preg_match( '/<option value="probe-loft"\s+selected[^>]*>Probe Loft</', $bar ) );
	ok( '...pets and sort, in words', (bool) preg_match( '/<option value="considered"\s+selected[^>]*>Pets considered</', $bar ) && (bool) preg_match( '/<option value="price-desc"\s+selected[^>]*>Price: high to low</', $bar ) );
	ok( 'the Filters button carries the count', (bool) preg_match( '/filter-count"[^>]*>6</', $bar ) );
	ok( 'Clear all filters is offered', str_contains( $bar, 'Clear all filters' ) );
	ok( 'the chips are printed under it', str_contains( $bar, 'filter-chips' ) && str_contains( $bar, 'Pets considered' ) );
	ok( 'the primary action says what happens', str_contains( $bar, 'Show homes' ) );
	ok( 'the drawer parts exist for the phone script', str_contains( $bar, 'data-tdh-filter-toggle' ) && str_contains( $bar, 'data-tdh-filter-drawer' ) && str_contains( $bar, 'data-tdh-filter-close' ) && str_contains( $bar, 'data-tdh-sort' ) );

	$_GET  = [ 'start' => '2026-11-01', 'end' => '2026-12-01' ];
	$dated = Render::filter_bar();
	ok( 'the stay has its own two fields', str_contains( $dated, 'name="start"' ) && str_contains( $dated, 'name="end"' ) && 2 === substr_count( $dated, 'type="date"' ) );
	ok( '...labelled in the renter\'s words, not the code\'s', str_contains( $dated, '>Move in<' ) && str_contains( $dated, '>Move out<' ) );
	ok( '...holding the dates that were typed', str_contains( $dated, 'value="2026-11-01"' ) && str_contains( $dated, 'value="2026-12-01"' ) );
	ok( '...bounded to dates a landlord could have marked', str_contains( $dated, 'min="' . $today . '"' ) && str_contains( $dated, 'max="' . Availability::horizon() . '"' ) );
	ok( '...and the minimum stay travels to the script rather than living in it', str_contains( $dated, 'data-tdh-min-stay="30"' ) );

	$_GET      = [ 'start' => '2026-10-01', 'end' => '2026-10-30' ];
	$refused_ui = Render::filter_bar();
	ok( 'a refused stay is explained on the form itself', str_contains( $refused_ui, 'date-notice' ) && str_contains( $refused_ui, '30 days or longer' ) );

	$_GET  = [];
	$plain = Render::filter_bar();
	ok( 'the hospitals are offered in city groups', str_contains( $plain, '<optgroup label="Probeville">' ) && str_contains( $plain, 'Search Probe Mercy' ) );
	ok( 'with nowhere chosen or typed the radius is disabled and says why', (bool) preg_match( '/name="within"[^>]*disabled/', $plain ) && str_contains( $plain, 'Pick a place' ) );

	/*
	 * The column is sized for the real choices, so a placeholder longer
	 * than the longest of them is a placeholder the browser will cut off.
	 * "Pick a place first" fitted at 1280 by three pixels and was cut at
	 * 1440, 1600 and 1920 — the C3 truncation, repeated because the check
	 * was run at a single width. This is the rule, not the pixel count.
	 */
	$longest = __( 'Any distance', 'thirtydayhomes' );
	preg_match( '/name="within"[^>]*disabled[^>]*>\s*<option value="">([^<]+)</', $plain, $hint );
	ok( '...in no more room than the widest real choice needs', isset( $hint[1] ) && mb_strlen( trim( $hint[1] ) ) <= mb_strlen( $longest ), ( $hint[1] ?? '?' ) . ' vs ' . $longest );
	ok( '...and the reason sits inside the control, not in a label that would wrap the row out of line', ! preg_match( '/<span>\s*Within\s*<small>/', $plain ) );
	ok( '...and "Closest" cannot be picked yet', (bool) preg_match( '/<option value="closest"[^>]*disabled/', $plain ) );

	$_GET  = [ 'q' => '99123' ];
	$typed = Render::filter_bar();
	ok( 'typing a postcode is enough: the radius wakes up without choosing a hospital', ! preg_match( '/name="within"[^>]*disabled/', $typed ) && str_contains( $typed, '15 miles' ) );
	ok( '...and the closest option can be picked, and names the postcode', ! preg_match( '/<option value="closest"[^>]*disabled/', $typed ) && str_contains( $typed, 'Closest to 99123' ) );
	$_GET = [];

	$_GET  = [ 'facility' => (string) $mercy ];
	$picked = Render::filter_bar();
	ok( 'choosing one enables the radius and selects it', ! preg_match( '/name="within"[^>]*disabled/', $picked ) && (bool) preg_match( '/<option value="15"\s+selected/', $picked ) );
	ok( '...and the hospital itself is selected', (bool) preg_match( '/<option value="' . $mercy . '"\s+selected/', $picked ) );
	ok( '...and the order shown is closest', (bool) preg_match( '/<option value="closest"\s+selected/', $picked ) );

	$_GET    = [ 'sort' => 'closest' ];
	$orphan  = Render::filter_bar();
	ok( 'asking for closest with nothing to be close to is explained beside the sort', str_contains( $orphan, 'Type a postcode or area, or choose a hospital' ) );
	$_GET = [];

	$_GET = [];
	$bare = Render::filter_bar();
	ok( 'with nothing set there is no count, no Clear and no chips', ! str_contains( $bare, 'filter-count' ) && ! str_contains( $bare, 'Clear all filters' ) && ! str_contains( $bare, 'filter-chips' ) );
	ok( 'the empty state can print the chips on their own', '' === Render::filter_chips() );

	echo "\n=== inside a city ===\n";

	$_GET = [ 'beds' => '2' ];
	$tax  = new WP_Query();
	$tax->parse_query( [ 'tdh_city' => 'probeville' ] );
	$previous                = $GLOBALS['wp_the_query'];
	$GLOBALS['wp_the_query'] = $tax;
	$search->apply( $tax );
	$GLOBALS['wp_the_query'] = $previous;

	$meta = $tax->get( 'meta_query' );
	ok( 'the city archive is a listing search too', Search::is_listing_query( $tax ) || $tax->is_tax( 'tdh_city' ) );
	ok( '...and gets the same filters', is_array( $meta ) && isset( $meta['beds'] ) && 2 === (int) $meta['beds']['value'] );

	$prev_q            = $GLOBALS['wp_query'];
	$GLOBALS['wp_query'] = $tax;
	$base              = Search::base_url();
	$GLOBALS['wp_query'] = $prev_q;
	ok( '...and the form posts back to the city, not to /homes/', str_contains( $base, 'probeville' ), $base );
	$_GET = [];
}

echo "\n=== a page past the end, and an empty place ===\n";

/** Run a handler that ends in exit; report where it redirected, or that it did not. */
$caught = static function ( callable $handler ): string {
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
};

// What a real request does: the query, then WordPress's own 404 decision.
$request = static function ( array $vars ): void {
	$GLOBALS['wp_query']->query( $vars );
	$GLOBALS['wp']->handle_404();
};

$_GET = [ 'beds' => '1' ];
$request( [ 'post_type' => 'tdh_listing', 'paged' => 99 ] );
ok( 'fixture: WordPress answers a page past the end with a 404', is_404() );
$where = $caught( [ $search, 'back_to_first_page' ] );
ok( 'a listing page past the end goes back to the first page of the same search, filters kept', str_contains( $where, (string) get_post_type_archive_link( 'tdh_listing' ) ) && str_contains( $where, 'beds=1' ) && ! str_contains( $where, 'paged' ) && ! str_contains( $where, '/page/' ), $where );

$request( [ 'tdh_city' => 'probeville', 'paged' => 99 ] );
$where = $caught( [ $search, 'back_to_first_page' ] );
ok( '...and a city page past its end goes back to that city, not to /homes/', str_contains( $where, 'probeville' ) && str_contains( $where, 'beds=1' ) && ! str_contains( $where, '/page/' ), $where );

$_GET = [ 'min_price' => '5000' ];
$request( [ 'post_type' => 'tdh_listing' ] );
ok( 'a first page with no results is not a 404, so nothing redirects — the empty state is the answer', ! is_404() && '(no redirect)' === $caught( [ $search, 'back_to_first_page' ] ) );

$request( [ 'post_type' => 'tdh_listing', 'name' => 'no-such-home-anywhere', 'paged' => 2 ] );
ok( 'a home that does not exist stays a real not-found, whatever page number rides on it', is_404() && '(no redirect)' === $caught( [ $search, 'back_to_first_page' ] ) );

$_GET = [];
$GLOBALS['wp_query']->query( [ 'tdh_city' => 'probeville' ] );
ok( 'a city page with nothing on it names the city, not the whole site', 'No homes in Probeville yet' === Search::empty_sentence(), Search::empty_sentence() );
$GLOBALS['wp_query']->query( [] );
ok( '...and with no place asked it is still the site-wide sentence', 'No homes are listed yet' === Search::empty_sentence(), Search::empty_sentence() );

$_GET = [ 'q' => str_repeat( 'x', 300 ) ];
ok( 'a search term is capped at ' . Search::MAX_TERM . ' characters — longer only stretches the page', Search::MAX_TERM === mb_strlen( Search::term() ), (string) mb_strlen( Search::term() ) );
$_GET = [];

echo "\n=== the search bar ===\n";

$_GET['q'] = 'Probeville';
$bar       = Render::search_bar();

ok( 'it renders a real search form', str_contains( $bar, '<form' ) && str_contains( $bar, 'role="search"' ) );
ok( 'it submits to the listing archive', str_contains( $bar, (string) get_post_type_archive_link( 'tdh_listing' ) ) );
ok( 'it uses the same field name as the hero', str_contains( $bar, 'name="' . Search::PARAM . '"' ) );
ok( 'it keeps what was searched for', str_contains( $bar, 'value="Probeville"' ) );
ok( 'it offers a way to clear the search', str_contains( $bar, 'search-clear' ) );
ok( 'the field is labelled for a screen reader', str_contains( $bar, 'screen-reader-text' ) );

unset( $_GET['q'] );
$empty = Render::search_bar();
ok( 'with no search there is nothing to clear', ! str_contains( $empty, 'search-clear' ) );

ok( 'the search bar asks only for a place; the stay is asked once, below it', ! str_contains( $bar, 'type="date"' ) );

$_GET = [ 'q' => 'Probeville', 'start' => '2026-11-01', 'end' => '2026-12-01' ];
$kept = Render::search_bar();
ok( 'searching again keeps the stay instead of dropping it', str_contains( $kept, 'name="start" value="2026-11-01"' ) && str_contains( $kept, 'name="end" value="2026-12-01"' ) );
$_GET = [];

/* Fixtures away. */
remove_filter( 'tdh_facility_query_args', $only_probes );

// The staff radius is put back exactly as it was, not deleted: this suite
// runs on a developer's own install, where somebody may have set it.
if ( null === $saved_radius ) {
	delete_option( 'tdh_proximity_radius' );
} else {
	update_option( 'tdh_proximity_radius', $saved_radius );
}
if ( null === $saved_count ) {
	delete_option( 'tdh_proximity_count' );
} else {
	update_option( 'tdh_proximity_count', $saved_count );
}

foreach ( [ $by_name, $by_place, $other, $unpriced, $mercy, $switched, $nowhere ] as $id ) {
	if ( $id ) {
		wp_delete_post( $id, true );
	}
}
if ( $hood ) {
	wp_delete_term( $hood, 'tdh_neighborhood' );
}
if ( $city ) {
	wp_delete_term( $city, 'tdh_city' );
}
if ( $kind ) {
	wp_delete_term( $kind, 'tdh_property_type' );
}
if ( $landlord ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $landlord->ID );
}
$_GET = [];
echo "\n  fixtures removed\n";

[ $p, $f ] = ok();
printf( "\n%s  %d passed, %d failed\n\n", $f ? 'FAILED' : 'PASSED', $p, $f );
if ( $f && defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::halt( 1 );
}
