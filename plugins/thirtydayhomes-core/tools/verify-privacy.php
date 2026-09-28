<?php
/** The username-enumeration doors: closed to visitors, open where staff need them. */

function ok( string $label = '', bool $cond = false, string $detail = '' ): array {
	static $p = 0, $f = 0;
	if ( '' === $label ) { return [ $p, $f ]; }
	if ( $cond ) { ++$p; printf( "  ok    %s\n", $label ); }
	else { ++$f; printf( "  FAIL  %s%s\n", $label, '' !== $detail ? "  --> {$detail}" : '' ); }
	return [ $p, $f ];
}

$privacy = new TDH\User_Privacy();
$admin   = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0] ?? null;

echo "\n=== the REST users route ===\n";

/*
 * The REST server builds its route table ONCE per process, with the filter
 * applied for whoever is logged in at that moment. So the end-to-end
 * dispatch is tested logged-out (the attacker's position), and the staff
 * case is asserted against the filter directly — rebuilding the server for
 * a second dispatch is not something a request does either.
 */
wp_set_current_user( 0 );

$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/users' ) );
ok( 'a visitor probing /wp/v2/users gets 404 — not a list, not a 401', 404 === $response->get_status(), (string) $response->get_status() );

$routes = [
	'/wp/v2/users'                 => [ 'x' ],
	'/wp/v2/users/(?P<id>[\d]+)'   => [ 'x' ],
	'/wp/v2/posts'                 => [ 'x' ],
];

$out = $privacy->hide_user_routes( $routes );
ok( 'both users routes are gone for a visitor', ! isset( $out['/wp/v2/users'] ) && ! isset( $out['/wp/v2/users/(?P<id>[\d]+)'] ) );
ok( 'every other route is untouched', isset( $out['/wp/v2/posts'] ) );

if ( $admin ) {
	wp_set_current_user( $admin->ID );
	$out = $privacy->hide_user_routes( $routes );
	ok( 'staff keep the endpoint — wp-admin itself uses it', isset( $out['/wp/v2/users'] ) );
}

echo "\n=== author archives ===\n";

wp_set_current_user( 0 );

$real = $admin ? (int) $admin->ID : 1;

$GLOBALS['wp_query']->query( [ 'author' => $real ] );
$privacy->block_author_archives();
ok( '?author={real id} answers 404 for a visitor', is_404() );

$GLOBALS['wp_query']->query( [ 'author' => 999999 ] );
$privacy->block_author_archives();
ok( '?author={fake id} answers the same 404 — no oracle', is_404() );

if ( $admin ) {
	wp_set_current_user( $admin->ID );
	$GLOBALS['wp_query']->query( [ 'author' => $real ] );
	$privacy->block_author_archives();
	ok( 'staff are not blocked', ! is_404() );
}

wp_set_current_user( 0 );
$GLOBALS['wp_query']->query( [] );

echo "\n=== the sitemap ===\n";

/*
 * Two worlds: in client-review mode the persona bar zeroes blog_public,
 * and WordPress then ships NO sitemap at all — which closes this door by
 * itself. On live, sitemaps are on and the users provider must be the
 * only one missing. The suite reports whichever world it is in.
 */
$sitemaps  = wp_sitemaps_get_server();
$providers = $sitemaps->registry->get_providers();

if ( ! $sitemaps->sitemaps_enabled() ) {
	ok( 'sitemaps are off entirely here (review mode hides the site from search)', [] === $providers );
} else {
	ok( 'no authors file in the sitemap', ! isset( $providers['users'] ) );
	ok( 'the pages and posts sitemaps stay', isset( $providers['posts'] ) );
}

ok( 'the filter answers only for the users provider', false === $privacy->drop_users_sitemap( 'keep', 'users' ) && 'keep' === $privacy->drop_users_sitemap( 'keep', 'posts' ) );

echo "\n=== where a home is: the address never leaves the server ===\n";

/*
 * The release blocker. A furnished home stands empty between stays, so
 * publishing its address says which door is unlocked. These checks read
 * the actual rendered output, not the intention.
 */
$probe_lat = 40.4406;
$probe_lng = -79.9959;

$probe = (int) wp_insert_post(
	[
		'post_type'    => TDH\Post_Types::LISTING,
		'post_status'  => 'publish',
		'post_title'   => 'Privacy Probe Home',
		'post_content' => 'A home used only by the privacy suite.',
	]
);

update_post_meta( $probe, '_tdh_lat', (string) $probe_lat );
update_post_meta( $probe, '_tdh_lng', (string) $probe_lng );
update_post_meta( $probe, '_tdh_geocode_status', 'manual' );
update_post_meta( $probe, '_tdh_street_address', '4200 Fifth Avenue' );
update_post_meta( $probe, '_tdh_zip', '15213' );
update_post_meta( $probe, '_tdh_price_monthly', 2100 );

$exact = TDH\Geocoder::coordinates( $probe );
ok( 'the suite is testing a home that really does have an exact point', null !== $exact && abs( $exact['lat'] - $probe_lat ) < 0.00001 );

$shown = TDH\Maps::approximate( $probe );
ok( 'the published point is not the real one', null !== $shown && ( abs( $shown['lat'] - $probe_lat ) > 0.0001 || abs( $shown['lng'] - $probe_lng ) > 0.0001 ) );

/* How far the published point sits from the real one. */
$metres = null;

if ( null !== $shown ) {
	$metres = TDH\Proximity::miles( $probe_lat, $probe_lng, $shown['lat'], $shown['lng'] ) * 1609.344;
}

ok( '...and it is far enough away to hide a street', null !== $metres && $metres > 50, (string) $metres );
ok( '...but near enough that the neighbourhood is still true', null !== $metres && $metres < TDH\Maps::OFFSET_MAX + 120, (string) $metres );
ok( 'the circle drawn is wider than the error, so the home is plausibly anywhere in it', TDH\Maps::CIRCLE_METRES > TDH\Maps::OFFSET_MAX );

$again = TDH\Maps::approximate( $probe );
ok( 'the same home lands in the same place every time — a circle that wandered would average out to the truth', $shown == $again ); // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- comparing two float pairs by value.

/*
 * The shift must be per home, not one constant nudge applied to
 * everything — a constant would be undone the moment anyone compared a
 * published point with a known address. Six homes at one address: their
 * published points must not all land together. Some pairs WILL match,
 * because rounding to about 110 metres is coarse on purpose, and two
 * homes sharing a point is a help rather than a leak.
 */
$neighbours = [];
$places     = [];

for ( $n = 0; $n < 6; $n++ ) {
	$id = (int) wp_insert_post( [ 'post_type' => TDH\Post_Types::LISTING, 'post_status' => 'publish', 'post_title' => 'Privacy Probe Neighbour ' . $n ] );
	update_post_meta( $id, '_tdh_lat', (string) $probe_lat );
	update_post_meta( $id, '_tdh_lng', (string) $probe_lng );
	update_post_meta( $id, '_tdh_geocode_status', 'manual' );

	$neighbours[] = $id;
	$at           = TDH\Maps::approximate( $id );
	$places[]     = $at['lat'] . ',' . $at['lng'];
}

ok( 'homes at one address are moved by different amounts, not one constant nudge', count( array_unique( $places ) ) > 1, wp_json_encode( $places ) );

/* What actually reaches a browser. */
$payload = wp_json_encode( TDH\Maps::homes( [ $probe ] ) );

ok( 'the map payload carries no exact latitude', ! str_contains( (string) $payload, '40.4406' ), (string) $payload );
ok( '...no exact longitude', ! str_contains( (string) $payload, '-79.9959' ) );
ok( '...no street address', ! str_contains( (string) $payload, 'Fifth Avenue' ) );

$panel = TDH\Render::map_panel( [ $probe ] );
$area  = TDH\Render::map_area( $probe );

if ( TDH\Maps::configured() ) {
	ok( 'the results map is drawn when a key is set', str_contains( $panel, 'data-tdh-map' ) );
	ok( '...and its markup holds no exact coordinate', ! str_contains( $panel, '40.4406' ) && ! str_contains( $panel, '-79.9959' ) );
	ok( '...and no street address', ! str_contains( $panel, 'Fifth Avenue' ) );
	ok( 'the property page circle holds no exact coordinate either', ! str_contains( $area, '40.4406' ) && ! str_contains( $area, '-79.9959' ) );
	ok( '...and says what the circle means', str_contains( $area, 'Approximate area' ) );
} else {
	ok( 'with no key there is no results map at all', '' === $panel );
	ok( '...and no circle on the property page', '' === $area );
}

/* The whole property page, as a visitor receives it. */
wp_set_current_user( 0 );

$previous     = $GLOBALS['wp_query'];
$previous_the = $GLOBALS['wp_the_query'];

$page = new WP_Query( [ 'p' => $probe, 'post_type' => TDH\Post_Types::LISTING ] );

/*
 * The template runs its own `while ( have_posts() )`, so the post pointer
 * must NOT be advanced here. Calling the_post() first leaves have_posts()
 * false, the loop never runs, and every assertion below passes against a
 * page with no body in it — a privacy test that proves nothing.
 */
$page->is_singular    = true;
$page->is_single      = true;
$GLOBALS['wp_query']  = $page;
$GLOBALS['wp_the_query'] = $page;

ob_start();
include get_template_directory() . '/single-tdh_listing.php';
$html = (string) ob_get_clean();

wp_reset_postdata();
$GLOBALS['wp_query']     = $previous;
$GLOBALS['wp_the_query'] = $previous_the;

ok( 'the property page really rendered the home, not just a header and a footer', str_contains( $html, 'Privacy Probe Home' ) && str_contains( $html, 'Close to care' ), (string) strlen( $html ) );
ok( 'THE RELEASE BLOCKER: no exact latitude anywhere in the page source', ! str_contains( $html, '40.4406' ) );
ok( '...no exact longitude', ! str_contains( $html, '-79.9959' ) );
ok( '...no street address, not even inside a hidden element', ! str_contains( $html, 'Fifth Avenue' ) );
ok( '...and not the meta key either, which would name the field to look for', ! str_contains( $html, '_tdh_lat' ) && ! str_contains( $html, '_tdh_street_address' ) );
ok( 'the ZIP is still shown, because that is the part renters are promised', str_contains( $html, '15213' ) );

$registered = get_registered_meta_keys( 'post', TDH\Post_Types::LISTING );
ok( 'a home\'s coordinates are still closed to the REST API', empty( $registered['_tdh_lat']['show_in_rest'] ) && empty( $registered['_tdh_lng']['show_in_rest'] ) );
ok( '...and so is the street address', empty( $registered['_tdh_street_address']['show_in_rest'] ) );

wp_delete_post( $probe, true );

foreach ( $neighbours as $id ) {
	wp_delete_post( $id, true );
}

echo "\n  fixtures removed\n";

[ $p, $f ] = ok();
printf( "\n%s  %d passed, %d failed\n\n", $f ? 'FAILED' : 'PASSED', $p, $f );
