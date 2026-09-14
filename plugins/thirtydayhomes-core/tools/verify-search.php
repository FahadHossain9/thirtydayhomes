<?php
/** Archive search: it finds what matches, and refuses to pretend when nothing does. */

use TDH\Search;

function ok( string $label = '', bool $cond = false, string $detail = '' ): array {
	static $p = 0, $f = 0;
	if ( '' === $label ) { return [ $p, $f ]; }
	if ( $cond ) { ++$p; printf( "  ok    %s\n", $label ); }
	else { ++$f; printf( "  FAIL  %s%s\n", $label, '' !== $detail ? "  --> {$detail}" : '' ); }
	return [ $p, $f ];
}

/* Own fixtures. Names and places are deliberately unlike the demo content,
   so a match can only come from the search and never from coincidence. */
$hood = wp_insert_term( 'Probe Heights', 'tdh_neighborhood' );
$hood = is_wp_error( $hood ) ? 0 : (int) $hood['term_id'];

$city = wp_insert_term( 'Probeville', 'tdh_city' );
$city = is_wp_error( $city ) ? 0 : (int) $city['term_id'];

$landlord_id = wp_insert_user(
	[
		'user_login' => 'tdh_search_probe',
		'user_email' => 'search-probe@example.com',
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => 'tdh_landlord',
	]
);
$landlord    = is_wp_error( $landlord_id ) ? null : get_userdata( (int) $landlord_id );

/** Publish a listing and return its id. */
function probe_listing( string $title, ?WP_User $owner, array $terms = [], string $zip = '' ): int {

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

	return $id;
}

$by_name  = probe_listing( 'Zephyr Cottage', $landlord, [], '99123' );
$by_place = probe_listing( 'Quiet Rooms', $landlord, [ 'tdh_neighborhood' => $hood, 'tdh_city' => $city ], '99124' );
$other    = probe_listing( 'Unrelated Quarters', $landlord, [], '10001' );

ok( 'three published fixtures exist', $by_name && $by_place && $other );

echo "\n=== what the search matches ===\n";

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
 * Run a main-query archive request through the search filter.
 *
 * is_main_query() compares against $wp_the_query — it does NOT read an
 * is_main_query property, so the global has to be swapped for the call
 * and put back afterwards. Setting the property alone silently produces
 * a query the filter correctly ignores.
 */
function run_archive( Search $search, string $term ): WP_Query {

	$_GET['q'] = $term;

	$query = new WP_Query();
	$query->init();
	$query->is_post_type_archive = true;
	$query->set( 'post_type', 'tdh_listing' );

	$previous                = $GLOBALS['wp_the_query'];
	$GLOBALS['wp_the_query'] = $query;

	$search->apply( $query );

	$GLOBALS['wp_the_query'] = $previous;
	unset( $_GET['q'] );

	return $query;
}

$hit = run_archive( $search, 'Zephyr' );
ok( 'a matching search narrows the archive', in_array( $by_name, (array) $hit->get( 'post__in' ), true ) );
ok( 'and excludes the homes that do not match', ! in_array( $other, (array) $hit->get( 'post__in' ), true ) );

/*
 * The failure that matters most. An empty post__in is IGNORED by WP_Query,
 * so a search with no matches would answer with every home on the site —
 * a wrong result that looks like a right one.
 */
$miss = run_archive( $search, 'qwertyuiopasdfgh' );
ok( 'a search with no matches returns nothing, not everything', [ 0 ] === (array) $miss->get( 'post__in' ) );

$blank = run_archive( $search, '' );
ok( 'a blank search leaves the archive alone', '' === $blank->get( 'post__in' ) || [] === (array) $blank->get( 'post__in' ) );

// A secondary query must never be touched — the homepage's featured grid
// is one, and it would empty itself the moment somebody searched.
$_GET['q'] = 'Zephyr';
$secondary = new WP_Query();
$secondary->init();
$secondary->is_main_query = false;
$search->apply( $secondary );
ok( 'a secondary query is left alone', '' === $secondary->get( 'post__in' ) || [] === (array) $secondary->get( 'post__in' ) );
unset( $_GET['q'] );

echo "\n=== the search bar ===\n";

$_GET['q'] = 'Probeville';
$bar       = TDH\Render::search_bar();

ok( 'it renders a real search form', str_contains( $bar, '<form' ) && str_contains( $bar, 'role="search"' ) );
ok( 'it submits to the listing archive', str_contains( $bar, (string) get_post_type_archive_link( 'tdh_listing' ) ) );
ok( 'it uses the same field name as the hero', str_contains( $bar, 'name="' . Search::PARAM . '"' ) );
ok( 'it keeps what was searched for', str_contains( $bar, 'value="Probeville"' ) );
ok( 'it offers a way to clear the search', str_contains( $bar, 'search-clear' ) );
ok( 'the field is labelled for a screen reader', str_contains( $bar, 'screen-reader-text' ) );

unset( $_GET['q'] );
$empty = TDH\Render::search_bar();
ok( 'with no search there is nothing to clear', ! str_contains( $empty, 'search-clear' ) );

ok( 'no date fields — availability filtering is Milestone 2', ! str_contains( $bar, 'type="date"' ) );

/* Fixtures away. */
foreach ( [ $by_name, $by_place, $other ] as $id ) {
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
if ( $landlord ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $landlord->ID );
}
echo "\n  fixtures removed\n";

[ $p, $f ] = ok();
printf( "\n%s  %d passed, %d failed\n\n", $f ? 'FAILED' : 'PASSED', $p, $f );
