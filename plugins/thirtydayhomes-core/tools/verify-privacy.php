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

[ $p, $f ] = ok();
printf( "\n%s  %d passed, %d failed\n\n", $f ? 'FAILED' : 'PASSED', $p, $f );
