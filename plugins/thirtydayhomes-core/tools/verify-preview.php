<?php
/**
 * Listing preview: who may see a home that is not public, and what they see.
 *
 * Run through verify.bat, or directly:
 *     wp eval-file wp-content/plugins/thirtydayhomes-core/tools/verify-preview.php
 */

use TDH\Listing_Preview;
use TDH\Visibility;

function ok( string $label = '', bool $cond = false, string $detail = '' ): array {
	static $p = 0, $f = 0;
	if ( '' === $label ) { return [ $p, $f ]; }
	if ( $cond ) { ++$p; printf( "  ok    %s\n", $label ); }
	else { ++$f; printf( "  FAIL  %s%s\n", $label, '' !== $detail ? "  --> {$detail}" : '' ); }
	return [ $p, $f ];
}

/**
 * The decision the main query would make for this request, as the current
 * user. Visibility skips WP-CLI entirely, so the rule is asked directly
 * with a query parsed the way a browser request would parse it.
 */
function preview_allowed( int $id ): bool {
	$previous = $GLOBALS['wp_the_query'] ?? null;
	$q        = new WP_Query();
	$GLOBALS['wp_the_query'] = $q; // is_main_query() compares against this.
	$q->parse_query( [ 'post_type' => 'tdh_listing', 'p' => $id, 'preview' => 'true' ] );
	$allowed = Visibility::permits_preview( $q );
	$GLOBALS['wp_the_query'] = $previous;
	return $allowed;
}

$make_user = static function ( string $login, string $role ): int {
	$existing = get_user_by( 'login', $login );
	if ( $existing ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $existing->ID );
	}
	$id = wp_insert_user( [ 'user_login' => $login, 'user_email' => $login . '@example.com', 'user_pass' => wp_generate_password( 20 ), 'role' => $role ] );
	return is_wp_error( $id ) ? 0 : (int) $id;
};

$owner    = $make_user( 'tdh_preview_owner', 'tdh_landlord' );
$stranger = $make_user( 'tdh_preview_stranger', 'tdh_landlord' );
$admin    = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0] ?? null;

// A paying landlord: an unpaid one's home cannot be approved (E1).
TDH\Membership::apply( $owner, [ 'status' => TDH\Membership::ACTIVE, 'quota' => 5, 'expires' => strtotime( '+30 days' ) ] );

$pending = (int) wp_insert_post( [ 'post_type' => 'tdh_listing', 'post_status' => 'pending', 'post_title' => 'Preview probe pending', 'post_author' => $owner ] );
$draft   = (int) wp_insert_post( [ 'post_type' => 'tdh_listing', 'post_status' => 'draft', 'post_title' => 'Preview probe draft', 'post_author' => $owner ] );
$live    = (int) wp_insert_post( [ 'post_type' => 'tdh_listing', 'post_status' => 'publish', 'post_title' => 'Preview probe live', 'post_author' => $owner ] );

echo "\n=== who may preview a home that is not public ===\n";

wp_set_current_user( 0 );
ok( 'a visitor may not', ! preview_allowed( $pending ) );

wp_set_current_user( $stranger );
ok( 'another landlord may not', ! preview_allowed( $pending ) );

wp_set_current_user( $owner );
ok( 'its landlord may', preview_allowed( $pending ) );
ok( 'its landlord may preview a draft too', preview_allowed( $draft ) );

if ( $admin ) {
	wp_set_current_user( $admin->ID );
	ok( 'staff may', preview_allowed( $pending ) );
}

// Only a preview of the MAIN query is excused, never a listing grid.
wp_set_current_user( $owner );
$secondary = new WP_Query();
$secondary->parse_query( [ 'post_type' => 'tdh_listing', 'p' => $pending, 'preview' => 'true' ] );
ok( 'a secondary query is never excused', ! Visibility::permits_preview( $secondary ) );

// A preview flag on something that is not a listing excuses nothing.
$page = get_posts( [ 'post_type' => 'page', 'posts_per_page' => 1, 'fields' => 'ids' ] )[0] ?? 0;
if ( $page && $admin ) {
	wp_set_current_user( $admin->ID );
	ok( 'a page id with preview=true is not treated as a listing preview', ! preview_allowed( (int) $page ) );
}

echo "\n=== the bar ===\n";

wp_set_current_user( $owner );
$bar = Listing_Preview::bar( $pending );
ok( 'the landlord sees the bar on their pending home', str_contains( $bar, 'preview-bar' ) );
ok( '...saying it is in review and only they can see it', str_contains( $bar, 'In review' ) && str_contains( $bar, 'Only you can see this page' ) );
ok( '...with Edit listing, and no Approve', str_contains( $bar, 'Edit listing' ) && ! str_contains( $bar, 'listing_approve' ) );

$bar = Listing_Preview::bar( $draft );
ok( 'a draft says so and offers Continue editing', str_contains( $bar, 'Draft' ) && str_contains( $bar, 'Continue editing' ) );

ok( 'no bar on a live home — that page is what renters see', '' === Listing_Preview::bar( $live ) );

wp_set_current_user( $stranger );
ok( 'no bar for someone who cannot edit the home', '' === Listing_Preview::bar( $pending ) );

wp_set_current_user( 0 );
ok( 'no bar for a visitor', '' === Listing_Preview::bar( $pending ) );

if ( $admin ) {
	wp_set_current_user( $admin->ID );
	$bar = Listing_Preview::bar( $pending );
	ok( 'staff see Approve and Request changes on a pending home', str_contains( $bar, 'value="listing_approve"' ) && str_contains( $bar, 'request_changes=' . $pending ) );
	ok( '...Approve with the moderation nonce and the home id', 1 === substr_count( $bar, 'name="tdh_nonce"' ) && 1 === substr_count( $bar, 'name="tdh_listing" value="' . $pending . '"' ) );
	ok( '...posting to the staff listings screen', str_contains( $bar, 'view=listings' ) );
	ok( '...and wording written for staff', str_contains( $bar, 'waiting for your decision' ) );

	$bar = Listing_Preview::bar( $draft );
	ok( 'staff cannot approve a draft from the bar', ! str_contains( $bar, 'listing_approve' ) && str_contains( $bar, 'not submitted' ) );

	wp_update_post( [ 'ID' => $draft, 'post_status' => 'tdh_rejected' ] );
	update_post_meta( $draft, '_tdh_rejection_reason', 'Please add photos of the bedroom.' );
	wp_set_current_user( $owner );
	ok( 'changes requested shows the reason to the landlord', str_contains( Listing_Preview::bar( $draft ), 'Please add photos of the bedroom.' ) );
}

echo "\n=== not found, and the property page ===\n";

ok( 'the theme has a designed not-found template', '' !== locate_template( '404.php' ) );

$single = (string) file_get_contents( get_template_directory() . '/single-tdh_listing.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
ok( 'the property page places the preview bar', str_contains( $single, "do_action( 'tdh_listing_preview_bar'" ) );
ok( 'the property page hides "About this home" when there is no description', str_contains( $single, "trim( wp_strip_all_tags( (string) get_the_content() ) )" ) );

ok( 'Listing_Preview is a loaded module', TDH\Core::instance()->module( 'listing_preview' ) instanceof Listing_Preview );

/* Fixtures away. */
wp_set_current_user( 0 );
foreach ( [ $pending, $draft, $live ] as $id ) {
	wp_delete_post( $id, true );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( [ $owner, $stranger ] as $id ) {
	if ( $id ) {
		wp_delete_user( $id );
	}
}
echo "\n  fixtures removed\n";

[ $p, $f ] = ok();
printf( "\n%s  %d passed, %d failed\n\n", $f ? 'FAILED' : 'PASSED', $p, $f );

if ( $f ) {
	WP_CLI::halt( 1 );
}
