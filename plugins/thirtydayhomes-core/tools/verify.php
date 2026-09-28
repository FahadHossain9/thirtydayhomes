<?php
/**
 * Local verification — checks the marketplace rules that a page cannot show you.
 *
 * Run it:
 *
 *   cd D:\xampp\htdocs\thirtydayhomes
 *   php D:\xampp\wp-cli.phar eval-file wp-content/plugins/thirtydayhomes-core/tools/verify.php
 *
 * It creates two throwaway landlords and a few posts, asserts the rules, and
 * deletes everything it made. Safe to run against the local demo database.
 * It changes no setting permanently — the one option it touches, blog_public,
 * is restored before it exits.
 *
 * What this covers that clicking around cannot: capability checks are invisible
 * in a browser. A page that hides a link still serves the data if the
 * capability is wrong, so ownership has to be asserted, not eyeballed.
 *
 * @package ThirtyDayHomes
 */

// No declare( strict_types = 1 ) here on purpose: wp-cli runs this through
// eval(), where a strict_types declaration is a fatal error because it is
// not the first statement of the script it ends up inside.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "Run this through WP-CLI.\n" );
}

$GLOBALS['tdh_verify_fail'] = 0;
$GLOBALS['tdh_verify_ran']  = 0;

/**
 * Assert one expectation.
 */
function tdh_check( string $label, bool $got, bool $want = true ): void {

	++$GLOBALS['tdh_verify_ran'];

	$ok = ( $got === $want );

	if ( ! $ok ) {
		++$GLOBALS['tdh_verify_fail'];
	}

	printf( "  %-52s %-9s %s\n", $label, $got ? 'yes' : 'no', $ok ? 'ok' : '<<< WRONG' );
}

function tdh_section( string $title ): void {
	echo "\n" . $title . "\n" . str_repeat( '-', strlen( $title ) ) . "\n";
}

/* ========================================================================
   Fixtures
   ===================================================================== */

$pass = wp_generate_password( 24 );

$landlord_a = (int) wp_insert_user(
	[ 'user_login' => 'tdh_verify_a', 'user_email' => 'verify_a@example.test', 'user_pass' => $pass, 'role' => \TDH\Roles::LANDLORD ]
);
$landlord_b = (int) wp_insert_user(
	[ 'user_login' => 'tdh_verify_b', 'user_email' => 'verify_b@example.test', 'user_pass' => $pass, 'role' => \TDH\Roles::LANDLORD ]
);
$admin = (int) ( get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] ?? 0 );

$live    = (int) wp_insert_post( [ 'post_type' => \TDH\Post_Types::LISTING, 'post_title' => 'Verify live',    'post_status' => 'publish', 'post_author' => $landlord_a ] );
$pending = (int) wp_insert_post( [ 'post_type' => \TDH\Post_Types::LISTING, 'post_title' => 'Verify pending', 'post_status' => 'pending', 'post_author' => $landlord_a ] );
$held    = (int) wp_insert_post( [ 'post_type' => \TDH\Post_Types::LISTING, 'post_title' => 'Verify held',    'post_status' => \TDH\Statuses::BILLING_HOLD, 'post_author' => $landlord_a ] );
$inquiry = (int) wp_insert_post( [ 'post_type' => \TDH\Post_Types::INQUIRY, 'post_title' => 'Verify inquiry', 'post_status' => 'publish' ] );
update_post_meta( $inquiry, '_tdh_listing_id', $live );

/* ========================================================================
   Ownership
   ===================================================================== */

tdh_section( 'Ownership — a landlord reaches only their own records' );

tdh_check( 'owner can edit their live listing',        user_can( $landlord_a, 'edit_tdh_listing', $live ) );
tdh_check( 'owner can edit their pending listing',     user_can( $landlord_a, 'edit_tdh_listing', $pending ) );
tdh_check( 'owner can delete their live listing',      user_can( $landlord_a, 'delete_tdh_listing', $live ) );
tdh_check( 'owner can read an inquiry sent to them',   user_can( $landlord_a, 'read_tdh_inquiry', $inquiry ) );
tdh_check( 'owner CANNOT self-publish',                user_can( $landlord_a, 'publish_tdh_listings' ), false );

tdh_check( 'stranger CANNOT edit it',                  user_can( $landlord_b, 'edit_tdh_listing', $live ), false );
tdh_check( 'stranger CANNOT delete it',                user_can( $landlord_b, 'delete_tdh_listing', $live ), false );
tdh_check( 'stranger CANNOT read the inquiry',         user_can( $landlord_b, 'read_tdh_inquiry', $inquiry ), false );
tdh_check( 'stranger CANNOT moderate',                 user_can( $landlord_b, 'tdh_moderate_listings' ), false );
tdh_check( 'stranger CANNOT manage facilities',        user_can( $landlord_b, 'tdh_manage_facilities' ), false );
tdh_check( 'stranger CANNOT reach site settings',      user_can( $landlord_b, 'manage_options' ), false );

if ( $admin ) {
	tdh_check( 'administrator can edit any listing',   user_can( $admin, 'edit_tdh_listing', $live ) );
	tdh_check( 'administrator can read any inquiry',   user_can( $admin, 'read_tdh_inquiry', $inquiry ) );
}

/* ========================================================================
   Public visibility
   ===================================================================== */

tdh_section( 'Visibility — only approved listings reach the public' );

$public_ids = get_posts(
	[
		'post_type'      => \TDH\Post_Types::LISTING,
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'suppress_filters' => false,
	]
);

tdh_check( 'a live listing is public',                 in_array( $live, $public_ids, true ) );
tdh_check( 'a pending listing is NOT public',          in_array( $pending, $public_ids, true ), false );
tdh_check( 'a billing-held listing is NOT public',     in_array( $held, $public_ids, true ), false );

/* ========================================================================
   Membership
   ===================================================================== */

tdh_section( 'Membership — a new landlord can publish nothing' );

tdh_check( 'new landlord has no plan',                 \TDH\Membership::NONE === \TDH\Membership::status( $landlord_b ) );
tdh_check( 'new landlord is not active',               \TDH\Membership::is_active( $landlord_b ), false );
tdh_check( 'quota is zero, not the marketing figure',  0 === \TDH\Membership::quota( $landlord_b ) );
tdh_check( 'cannot add a listing without a plan',      \TDH\Membership::can_add_listing( $landlord_b ), false );
tdh_check( 'listing count sees non-public statuses',   3 === \TDH\Membership::listing_count( $landlord_a ) );

// An expiry in the past must beat a stale "active" left by a missed webhook.
update_user_meta( $landlord_b, \TDH\Membership::META_STATUS, \TDH\Membership::ACTIVE );
update_user_meta( $landlord_b, \TDH\Membership::META_EXPIRES, time() - DAY_IN_SECONDS );
tdh_check( 'a lapsed "active" reads as expired',       \TDH\Membership::EXPIRED === \TDH\Membership::status( $landlord_b ) );
delete_user_meta( $landlord_b, \TDH\Membership::META_EXPIRES );
update_user_meta( $landlord_b, \TDH\Membership::META_STATUS, \TDH\Membership::NONE );

/* ========================================================================
   Proximity
   ===================================================================== */

tdh_section( 'Proximity — distances are computed, not typed' );

// Known answer: UPMC Shadyside to the Shadyside listing, ~0.28 miles.
$miles = \TDH\Proximity::miles( 40.4523, -79.9340, 40.4557, -79.9370 );
tdh_check( 'haversine agrees with a hand calculation', abs( $miles - 0.283 ) < 0.02 );

$shadyside = get_page_by_path( 'sunlit-shadyside-retreat', OBJECT, \TDH\Post_Types::LISTING );

if ( $shadyside ) {
	$near = \TDH\Proximity::nearest( $shadyside->ID );
	tdh_check( 'the seeded listing finds a facility',  is_array( $near ) );
	if ( $near ) {
		printf( "  %-52s %s (%s)\n", '  -> nearest', $near['title'], \TDH\Proximity::format_miles( $near['miles'] ) );
	}
}

// A listing with no coordinates must print no band rather than "0.0 mi".
tdh_check( 'an un-geocoded listing gets no distance',  null === \TDH\Proximity::nearest( $pending ), true );

/* ========================================================================
   Account pages and indexing
   ===================================================================== */

tdh_section( 'Account pages exist and stay out of search results' );

$was_public = get_option( 'blog_public' );
remove_all_filters( 'pre_option_blog_public' );
update_option( 'blog_public', 1 );

$expect = [
	'home' => false, 'pricing' => false, 'about' => false,
	'register' => true, 'login' => true, 'lost-password' => true,
	'reset-password' => true, 'account' => true, 'profile' => true,
];

foreach ( $expect as $slug => $should_be_noindex ) {

	$page = get_page_by_path( $slug );

	if ( ! $page ) {
		printf( "  %-52s %s\n", $slug, 'MISSING PAGE' );
		++$GLOBALS['tdh_verify_fail'];
		continue;
	}

	global $wp_query, $post;
	$wp_query = new WP_Query( [ 'page_id' => $page->ID ] );
	$post     = $page;
	setup_postdata( $post );

	$robots = apply_filters( 'wp_robots', [] );

	tdh_check(
		sprintf( '/%s/ is hidden from search engines', $slug ),
		! empty( $robots['noindex'] ),
		$should_be_noindex
	);

	wp_reset_postdata();
}

update_option( 'blog_public', $was_public );

/* ========================================================================
   Clean up
   ===================================================================== */

foreach ( [ $live, $pending, $held, $inquiry ] as $id ) {
	wp_delete_post( $id, true );
}

require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $landlord_a );
wp_delete_user( $landlord_b );

$fail = (int) $GLOBALS['tdh_verify_fail'];
$ran  = (int) $GLOBALS['tdh_verify_ran'];

echo "\n" . str_repeat( '=', 66 ) . "\n";
echo $fail
	? "  {$fail} of {$ran} CHECKS FAILED\n"
	: "  all {$ran} checks passed\n";
echo str_repeat( '=', 66 ) . "\n";
echo "  fixtures removed; blog_public restored to " . get_option( 'blog_public' ) . "\n";
