<?php
/** The portal dashboard: every number real, every link somewhere real. */

use TDH\Views;

function ok( string $label = '', bool $cond = false, string $detail = '' ): array {
	static $p = 0, $f = 0;
	if ( '' === $label ) { return [ $p, $f ]; }
	if ( $cond ) { ++$p; printf( "  ok    %s\n", $label ); }
	else { ++$f; printf( "  FAIL  %s%s\n", $label, '' !== $detail ? "  --> {$detail}" : '' ); }
	return [ $p, $f ];
}

echo "\n=== the view counter ===\n";

/* Own fixtures, like every other suite: the local sample listings are
   orphaned (author 0) after earlier cleanups, so borrowing them means
   borrowing whatever state the last run left behind. */
$landlord_id = wp_insert_user(
	[
		'user_login' => 'tdh_portal_probe',
		'user_email' => 'portal-probe@example.com',
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => 'tdh_landlord',
	]
);

$landlord = is_wp_error( $landlord_id ) ? null : get_userdata( (int) $landlord_id );

if ( $landlord ) {
	update_user_meta( $landlord->ID, TDH\Membership::META_STATUS, TDH\Membership::ACTIVE );
	update_user_meta( $landlord->ID, TDH\Membership::META_PLAN, 'three-home' );
	update_user_meta( $landlord->ID, TDH\Membership::META_QUOTA, 3 );
	update_user_meta( $landlord->ID, TDH\Membership::META_EXPIRES, strtotime( '+30 days' ) );
}

$listing_id = $landlord ? wp_insert_post(
	[
		'post_type'   => 'tdh_listing',
		'post_status' => 'publish',
		'post_title'  => 'Portal probe listing',
		'post_author' => $landlord->ID,
	]
) : 0;

$listing = $listing_id && ! is_wp_error( $listing_id ) ? get_post( (int) $listing_id ) : null;

ok( 'a published listing and its owner exist to test with', $listing && $landlord );

if ( $listing ) {
	$views  = new Views();
	$before = Views::for_listing( $listing->ID );

	// A logged-out renter's view counts.
	wp_set_current_user( 0 );
	$req = new WP_REST_Request( 'POST', '/tdh/v1/listing-view' );
	$req->set_param( 'id', $listing->ID );
	$views->record( $req );
	ok( 'a visitor view is counted', Views::for_listing( $listing->ID ) === $before + 1 );

	$views->record( $req );
	ok( 'a second view is counted too', Views::for_listing( $listing->ID ) === $before + 2, 'dedupe is per browser session, on the client' );

	// The owner refreshing their own page does not.
	wp_set_current_user( $landlord->ID );
	$views->record( $req );
	ok( 'the owner viewing their own home is NOT counted', Views::for_listing( $listing->ID ) === $before + 2 );

	// Nor does staff.
	$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];
	wp_set_current_user( $admin->ID );
	$views->record( $req );
	ok( 'staff review is NOT counted', Views::for_listing( $listing->ID ) === $before + 2 );

	// Probing an unpublished id counts nothing. The suite makes its own
	// home in review: this check used to run only when the site happened to
	// have one, and skipped in silence when it did not.
	wp_set_current_user( 0 );
	$hidden = (int) wp_insert_post( [ 'post_type' => 'tdh_listing', 'post_status' => 'pending', 'post_title' => 'Portal probe pending (delete me)', 'post_author' => $landlord->ID ] );
	$h      = Views::for_listing( $hidden );
	$r2     = new WP_REST_Request( 'POST', '/tdh/v1/listing-view' );
	$r2->set_param( 'id', $hidden );
	$views->record( $r2 );
	ok( 'a pending listing cannot be counted by probing ids', Views::for_listing( $hidden ) === $h );
	wp_delete_post( $hidden, true );

	ok( 'the author total includes it', Views::total_for_author( $landlord->ID ) >= $before + 2 );

}

echo "\n=== the portal renders, with true numbers ===\n";

wp_set_current_user( $landlord ? $landlord->ID : 0 );

$html = TDH\Account_Render::dashboard();

ok( 'it renders', '' !== trim( $html ) );

foreach ( [ 'portal-side', 'portal-nav', 'portal-top', 'portal-heading', 'portal-alert', 'portal-metrics', 'portal-metric', 'portal-columns', 'portal-return' ] as $hook ) {
	ok( sprintf( '.%-16s present', $hook ), str_contains( $html, $hook ) );
}

ok( 'the dashboard sidebar has an accessible collapse control', str_contains( $html, 'portal-menu-toggle' ) && str_contains( $html, 'aria-controls="portal-sidebar"' ) && str_contains( $html, 'aria-expanded="true"' ) );
ok( 'the sidebar uses directional collapse and expand controls, not a menu icon', str_contains( $html, 'portal-toggle-collapse' ) && str_contains( $html, 'portal-toggle-expand' ) && ! str_contains( $html, '>Menu</span>' ) );

foreach ( [ 'Landlord portal', 'Overview', 'My listings', 'Inquiries', 'Membership', 'Account details', 'Public website', 'Add property', 'Live listings', 'Pending review', 'New inquiries', 'Listing views', 'Your listings', 'Recent inquiries' ] as $label ) {
	ok( sprintf( '%-18s present', '"' . $label . '"' ), str_contains( $html, $label ) );
}

// The numbers on screen equal the database's answers.
if ( $landlord ) {
	$live = new WP_Query( [ 'post_type' => 'tdh_listing', 'author' => $landlord->ID, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'tdh_bypass_visibility' => true ] );
	ok(
		sprintf( 'the live count on screen is the real count (%d)', $live->found_posts ),
		str_contains( preg_replace( '/\s+/', '', $html ), '>' . number_format_i18n( $live->found_posts ) . '<em>' )
	);
}

// The prototype's invented view count, as a metric VALUE — not "284" as a
// substring, which any id in a link, a nonce or a price can carry.
ok( 'no invented 284 as a number on screen', ! preg_match( '/>\s*284\s*</', $html ) );
ok( 'the active membership tier is prominent on the overview', str_contains( $html, 'Current plan' ) && str_contains( $html, 'Three Home' ) && str_contains( $html, 'Renews' ) );

echo "\n=== links go somewhere real ===\n";

ok( 'Add property links out', (bool) preg_match( '/Add property/', $html ) && (bool) preg_match( '/class="primary" href="http/', $html ) );
ok( 'sign out is present', str_contains( $html, 'Sign out' ) );

$menu = ( new TDH\Accounts() )->account_aware_menu(
	[
		(object) [ 'url' => TDH\Accounts::url( 'login' ), 'title' => 'Sign in', 'classes' => [] ],
		(object) [ 'url' => TDH\Accounts::url( 'register' ), 'title' => 'List your home', 'classes' => [ 'nav-cta' ] ],
	]
);
ok( 'signed-in navigation promotes Dashboard as the single CTA', 1 === count( $menu ) && 'Dashboard' === $menu[0]->title && in_array( 'nav-cta', $menu[0]->classes, true ) );
ok( 'signed-in navigation removes the registration CTA', ! str_contains( wp_json_encode( $menu ), 'List your home' ) );

echo "\n=== the sidebar items are real screens, not anchors ===\n";

wp_set_current_user( $landlord ? $landlord->ID : 0 );

ok( 'the nav links carry the view, not a #fragment', str_contains( $html, 'view=listings' ) && ! str_contains( $html, 'href="#listings"' ) );

$_GET = [ 'view' => 'listings' ];
ok( 'My listings is its own screen', str_contains( TDH\Account_Render::dashboard(), 'Every home on your account' ) );

$_GET = [ 'view' => 'inquiries' ];
ok( 'Inquiries is its own screen', str_contains( TDH\Account_Render::dashboard(), 'Renters who asked about your homes' ) );

$_GET = [ 'view' => 'membership' ];
$mv   = TDH\Account_Render::dashboard();
ok( 'Membership is its own screen, with the tier and plan facts', str_contains( $mv, 'Plan details' ) && str_contains( $mv, 'Listings used' ) && str_contains( $mv, 'Three Home' ) );

$_GET = [ 'view' => 'profile' ];
$profile = TDH\Account_Render::dashboard();
ok( 'Account details is a dashboard screen with the same portal shell', str_contains( $profile, 'portal-side' ) && str_contains( $profile, 'Personal information' ) && str_contains( $profile, 'Password and security' ) );
ok( 'Account details keeps the working profile form', str_contains( $profile, 'value="profile"' ) && str_contains( $profile, 'Save account details' ) );

$_GET = [ 'view' => 'nonsense' ];
ok( 'an unknown view is the overview, not an error', str_contains( TDH\Account_Render::dashboard(), 'happening with your properties' ) );

echo "\n=== G4a: the portal screens ===\n";

$_GET = [];
$over = TDH\Account_Render::dashboard();
ok( 'the overview is headed "Overview", the name of its nav item', (bool) preg_match( '/<h1>\s*Overview\s*<\/h1>/', $over ) );
preg_match( '/<nav class="portal-tabs".*?<\/nav>/s', $over, $tabs );
$tabs = $tabs[0] ?? '';
ok( 'a phone gets a tab bar of five labelled sections, the current one marked', 5 === substr_count( $tabs, '<a ' ) && 5 === substr_count( $tabs, '<span>' ) && 1 === substr_count( $tabs, 'aria-current="page"' ) && str_contains( $tabs, '<span>Plan</span>' ) );
ok( '...and the top bar carries the logo there', str_contains( $over, 'class="portal-top-brand"' ) );
ok( 'the live tile reads "N of M", not "N/M"', (bool) preg_match( '/<em>\s*of\s+\d/', $over ) );

$_GET = [ 'view' => 'membership' ];
$mv   = TDH\Account_Render::dashboard();
ok( 'Membership says "Manage billing" and what the Stripe page does', str_contains( $mv, '>Manage billing<' ) && ( str_contains( $mv, 'Stripe’s secure page' ) || str_contains( $mv, 'Online billing isn’t available' ) ) );
ok( '...the facts are a definition list with no overprinting line height', str_contains( $mv, '<dl class="portal-health">' ) && ! preg_match( '/\.portal-health > div > dd \{[^}]*line-height:\s*0\./s', (string) file_get_contents( get_template_directory() . '/style.css' ) ) );

$_GET = [ 'view' => 'listings' ];
$lv   = TDH\Account_Render::dashboard();
ok( 'My listings has status chips with counts and no second "Your listings" heading', str_contains( $lv, 'class="manage-chips"' ) && (bool) preg_match( '/All\s*<b>\d+<\/b>/', $lv ) && ! str_contains( $lv, '<h3>Your listings</h3>' ) );

/*
 * A live home of the fixture landlord's own, so the card checks run
 * against something real rather than passing on an empty list.
 */
$g4a_home = $landlord ? (int) wp_insert_post( [ 'post_type' => 'tdh_listing', 'post_status' => 'publish', 'post_title' => 'G4a Probe Home', 'post_author' => $landlord->ID ] ) : 0;
$lv       = TDH\Account_Render::dashboard();
ok( '...a live home says how many views and inquiries it has had, in words', (bool) preg_match( '/class="manage-stats">\s*\d[\d,]* views?\s*·\s*\d[\d,]* inquir(y|ies)/', $lv ) );
ok( '...Pause and Delete sit in a labelled More, printed open for a desktop or no script', str_contains( $lv, '<details class="manage-overflow" open' ) && (bool) preg_match( '/<summary>\s*More/', $lv ) );
ok( '...and the Live chip counts it', (bool) preg_match( '/Live\s*<b>\d+<\/b>/', $lv ) );
$_GET = [ 'view' => 'listings', 'show' => 'draft' ];
$dv   = TDH\Account_Render::dashboard();
ok( 'a chip filters the list: Draft shows no live home', ! str_contains( $dv, 'G4a Probe Home' ) );
if ( $g4a_home ) {
	wp_delete_post( $g4a_home, true );
}

$_GET = [ 'view' => 'listings', 'show' => 'nonsense' ];
ok( 'an unknown status filter is All, not an error', str_contains( TDH\Account_Render::dashboard(), 'class="manage-chips"' ) );

$_GET = [ 'view' => 'profile' ];
$pv   = TDH\Account_Render::dashboard();
ok( 'Account details offers Show on both password fields', str_contains( $pv, 'data-tdh-pw="tdh-p-new"' ) && str_contains( $pv, 'data-tdh-pw="tdh-p-current"' ) );

$_GET = [];

echo "\n=== logged out, the wall still shows with site chrome ===\n";

wp_set_current_user( 0 );
$wall = TDH\Account_Render::dashboard();
ok( 'a visitor gets the sign-in wall, not the portal', ! str_contains( $wall, 'portal-side' ) && '' !== trim( $wall ) );

echo "\n=== staff get the marketplace, not a landlord cockpit ===\n";

$staff = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0] ?? null;

if ( $staff && $landlord ) {
	wp_set_current_user( $staff->ID );

	// A submitted listing to sit in the approval queue.
	$queued = (int) wp_insert_post(
		[
			'post_type'   => 'tdh_listing',
			'post_status' => 'pending',
			'post_title'  => 'Marketplace queue probe',
			'post_author' => $landlord->ID,
		]
	);
	update_post_meta( $queued, '_tdh_price_monthly', 2275 );

	$mk = TDH\Account_Render::dashboard();

	ok( 'the administrator sees the marketplace overview', str_contains( $mk, 'Marketplace overview' ) );
	ok( 'and is never told to choose a plan', ! str_contains( $mk, 'No active plan' ) && ! str_contains( $mk, 'Choose plan' ) );

	foreach ( [ 'Active members', 'Live listings', 'Pending approval', 'Recent inquiries', 'Approval queue', 'Membership health', 'Past due', 'Site content', 'Public website' ] as $label ) {
		ok( sprintf( '%-20s present', '"' . $label . '"' ), str_contains( $mk, $label ) );
	}

	ok( 'the submitted home waits in the queue, priced', str_contains( $mk, 'Marketplace queue probe' ) && str_contains( $mk, '2,275' ) );
	ok( 'View all opens the portal\'s own listings view', str_contains( $mk, 'view=listings' ) );
	ok( 'the queue row previews the home as a renter sees it', str_contains( $mk, 'preview=true' ) );
	ok( 'routine administration does not expose a WordPress dashboard door', ! str_contains( $mk, 'WordPress dashboard' ) );

	echo "\n=== the portal's working views ===\n";

	$_GET     = [ 'view' => 'listings' ];
	$listings = TDH\Account_Render::dashboard();
	ok( 'Listings: the approval desk renders', str_contains( $listings, 'Waiting for approval' ) && str_contains( $listings, 'All listings' ) );
	ok( 'Listings: Approve and Request changes stand ready', str_contains( $listings, 'listing_approve' ) && str_contains( $listings, 'request_changes=' ) );
	ok( 'Listings: the fixture waits with its landlord explicitly named', str_contains( $listings, 'Marketplace queue probe' ) && str_contains( $listings, 'Landlord:' ) );
	ok( 'Listings: every lifecycle status has a filter', str_contains( $listings, 'listing_status=' ) && str_contains( $listings, 'Pending review' ) && str_contains( $listings, 'Live' ) && str_contains( $listings, 'Paused' ) && str_contains( $listings, 'Rejected' ) && str_contains( $listings, 'Hidden — membership' ) );
	ok( 'Listings: editing stays in the front-end wizard', str_contains( $listings, 'listing=' ) && ! str_contains( $listings, 'post.php?action=edit' ) && ! str_contains( $listings, 'Open in WordPress' ) );

	$_GET = [ 'view' => 'listing-setup' ];
	$setup = TDH\Account_Render::dashboard();
	ok( 'Listing setup: all supporting property menus are visible', str_contains( $setup, 'Property types' ) && str_contains( $setup, 'Neighborhoods' ) && str_contains( $setup, 'Amenities' ) && str_contains( $setup, 'Cities' ) );
	ok( 'Listing setup: each menu says how many values it holds', str_contains( $setup, 'configured' ) );

	$_GET = [ 'view' => 'members' ];
	$mem  = TDH\Account_Render::dashboard();
	ok( 'Members: the landlord roster renders', str_contains( $mem, 'Members' ) && str_contains( $mem, 'portal-probe@example.com' ) );
	ok( 'Members: native create, edit, reset and delete controls render', str_contains( $mem, 'member_create' ) && str_contains( $mem, 'member_update' ) && str_contains( $mem, 'member_reset' ) && str_contains( $mem, 'delete_member=' ) );
	ok( 'Members: Delete never asks through a browser pop-up', ! str_contains( $mem, 'confirm(' ) && ! str_contains( $mem, 'value="member_delete"' ) );
	$probe_member = get_user_by( 'email', 'portal-probe@example.com' );
	$_GET         = [ 'view' => 'members', 'delete_member' => (string) ( $probe_member ? $probe_member->ID : 0 ) ];
	$ask          = TDH\Account_Render::dashboard();
	ok( 'Members: Delete asks inside the page, names the member, and offers Keep them', str_contains( $ask, 'class="portal-confirm"' ) && str_contains( $ask, 'value="member_delete"' ) && str_contains( $ask, 'Keep them' ) && str_contains( $ask, 'Their account is removed' ) );
	ok( '...with that member opened, and nothing deleted by merely asking', (bool) preg_match( '/id="member-' . ( $probe_member ? $probe_member->ID : 0 ) . '" open/', $ask ) && (bool) get_user_by( 'email', 'portal-probe@example.com' ) );
	ok( 'Members: no WordPress user-editor escape remains', ! str_contains( $mem, 'users.php' ) && ! str_contains( $mem, 'user-edit.php' ) );

	$_GET = [ 'view' => 'inquiries' ];
	ok( 'Inquiries view renders', str_contains( TDH\Account_Render::dashboard(), 'Inquiries' ) );

	$_GET       = [ 'view' => 'facilities' ];
	$facilities = TDH\Account_Render::dashboard();
	ok( 'Facilities view renders with native management', str_contains( $facilities, 'Facilities' ) && str_contains( $facilities, 'facility_save' ) && ! str_contains( $facilities, 'Open in WordPress' ) );

	$_GET = [ 'view' => 'nonsense' ];
	ok( 'an unknown view is the overview, not an error', str_contains( TDH\Account_Render::dashboard(), 'Marketplace overview' ) );
	$_GET = [];

	echo "\n=== G5a: the administrator's screens, design review ===\n";

	$mk = TDH\Account_Render::dashboard();
	ok( 'G5a: the overview tiles are links; the waiting one comes first, tinted, and says what to do', (bool) preg_match( '/<div class="portal-metrics">\s*<a class="portal-metric portal-metric--action is-waiting"/', $mk ) && str_contains( $mk, 'waiting · Review now' ) && str_contains( $mk, 'member_status=active' ) );
	ok( 'G5a: the overview queue row carries Approve and Request changes', (bool) preg_match( '/id="queue-' . $queued . '"[\s\S]{0,3000}?listing_approve[\s\S]{0,1500}?Request changes/', $mk ) );

	$_GET     = [ 'view' => 'listings' ];
	$listings = TDH\Account_Render::dashboard();
	ok( 'G5a: every status chip carries its count', (bool) preg_match( '/>All <b>\d+<\/b><\/a>/', $listings ) && (bool) preg_match( '/Pending review <b>\d+<\/b>/', $listings ) );
	ok( 'G5a: a waiting home is not listed twice', 1 === substr_count( $listings, '>Marketplace queue probe</a>' ) );
	ok( 'G5a: Edit and View at the end of every row; the queue Approve is the outlined one', str_contains( $listings, 'portal-row-links' ) && str_contains( $listings, 'class="primary approve"' ) );
	ok( 'G5a: homes without a map point are one banner and one chip, not a sentence per row', str_contains( $listings, 'Needs location <b>' ) && str_contains( $listings, 'no map point yet' ) && ! str_contains( $listings, 'Open “Set location” to look it up now' ) );
	ok( 'G5a: "No landlord yet", never "Unassigned"', ! str_contains( $listings, 'Unassigned' ) );

	$_GET  = [ 'view' => 'listings', 'needs' => 'location' ];
	$needs = TDH\Account_Render::dashboard();
	ok( 'G5a: the Needs location view lists the waiting home and offers the way back', str_contains( $needs, 'Marketplace queue probe' ) && str_contains( $needs, 'Showing the' ) && str_contains( $needs, 'Show all homes' ) );

	$_GET = [ 'view' => 'listings', 'locate' => (string) $queued ];
	$loc  = TDH\Account_Render::dashboard();
	// Without a maps key the top notice says why once, so no row or panel repeats it.
	ok( 'G5a: the reason waits inside the Set-location panel, and the example reads as one', str_contains( $loc, 'class="portal-locate"' ) && ( ! TDH\Geocoder::configured() || str_contains( $loc, 'portal-locate-why' ) ) && str_contains( $loc, 'placeholder="e.g. 40.4406, -79.9959"' ) );

	$_GET = [ 'view' => 'members' ];
	$mem  = TDH\Account_Render::dashboard();
	ok( 'G5a: Add member is the header button; the form panel waits hidden', str_contains( $mem, 'data-tdh-add="add-member"' ) && str_contains( $mem, 'id="add-member" hidden' ) && ! str_contains( $mem, 'Add a member' ) );
	ok( 'G5a: search and status chips with counts', str_contains( $mem, 'name="q"' ) && (bool) preg_match( '/>All <b>\d+<\/b><\/a>/', $mem ) );
	ok( 'G5a: no "No active plan" pill on a row; the email on its own line', ! (bool) preg_match( '/<span class="status[^"]*">\s*No active plan\s*<\/span>/', $mem ) && str_contains( $mem, 'portal-member-email' ) );
	$_GET = [ 'view' => 'members', 'q' => 'portal-probe' ];
	$hit  = TDH\Account_Render::dashboard();
	ok( 'G5a: search finds a member by email and says how many matched', str_contains( $hit, 'portal-probe@example.com' ) && str_contains( $hit, 'match' ) && str_contains( $hit, 'Clear search' ) );
	$_GET = [ 'view' => 'members', 'q' => 'zzqx-nobody' ];
	$miss = TDH\Account_Render::dashboard();
	ok( 'G5a: a search with no match says so and never shows everyone', str_contains( $miss, 'No members match' ) && ! str_contains( $miss, 'portal-probe@example.com' ) );
	$_GET = [ 'view' => 'members', 'add' => '1' ];
	ok( 'G5a: ?add=1 opens the panel without a script', (bool) preg_match( '/id="add-member">/', TDH\Account_Render::dashboard() ) );

	$_GET       = [ 'view' => 'facilities' ];
	$facilities = TDH\Account_Render::dashboard();
	ok( 'G5a: Add facility is the header button; no "Active" pill on every row', str_contains( $facilities, 'data-tdh-add="add-facility"' ) && ! str_contains( $facilities, 'Add a facility' ) && ! (bool) preg_match( '/<span class="status live">Active<\/span>/', $facilities ) );

	$_GET = [ 'view' => 'inquiries' ];
	$inq  = TDH\Account_Render::dashboard();
	ok( 'G5a: an inquiry row is one link with a time, never a bare dot', ! str_contains( $inq, 'aria-label="Unread"' ) && ( ! str_contains( $inq, 'portal-inquiry' ) || ( str_contains( $inq, '<a class="portal-inquiry' ) && str_contains( $inq, 'class="portal-when"' ) ) ) );

	$_GET  = [ 'view' => 'listing-setup' ];
	$setup = TDH\Account_Render::dashboard();
	ok( 'G5a: the menus are one list with Manage for an administrator, no milestone wording', str_contains( $setup, 'portal-setup-rows' ) && str_contains( $setup, 'edit-tags.php?taxonomy=' ) && ! str_contains( $setup, 'Milestone 2' ) );
	ok( 'G5a: the hospitals form carries its units and says what Save does', str_contains( $setup, '>facilities</em>' ) && str_contains( $setup, '>miles</em>' ) && str_contains( $setup, 'Save hospital settings' ) );
	$_GET = [];

	echo "\n=== moderation from the portal ===\n";

	$moderation = new TDH\Moderation();

	$catch = static function ( $location ) {
		throw new RuntimeException( (string) $location );
	};

	$run = static function () use ( $moderation, $catch ): string {
		add_filter( 'wp_redirect', $catch, 1 );
		try {
			$moderation->handle();
			$where = '(no redirect)';
		} catch ( RuntimeException $e ) {
			$where = $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $catch, 1 );
		}
		return $where;
	};

	// A landlord cannot moderate, valid nonce or not.
	wp_set_current_user( $landlord->ID );
	$_POST = [
		'tdh_action'  => 'listing_approve',
		'tdh_listing' => (string) $queued,
		'tdh_nonce'   => wp_create_nonce( TDH\Moderation::NONCE ),
	];
	$run();
	ok( 'a landlord cannot approve — still pending', 'pending' === get_post_status( $queued ) );

	// Staff approve: live, and the hook hears it.
	$approved_id = 0;
	add_action( 'tdh_listing_approved', static function ( int $id ) use ( &$approved_id ): void { $approved_id = $id; } );

	wp_set_current_user( $staff->ID );
	$_POST['tdh_nonce'] = wp_create_nonce( TDH\Moderation::NONCE );
	$where              = $run();
	ok( 'staff approve: the listing is live', 'publish' === get_post_status( $queued ), $where );
	ok( 'the approval lands back on the listings view, flagged', str_contains( $where, 'view=listings' ) && str_contains( $where, 'tdh_moderated=approved' ) );
	ok( 'the tdh_listing_approved hook heard it', $approved_id === $queued );

	// Request changes on a second submission.
	$second_q = (int) wp_insert_post(
		[
			'post_type'   => 'tdh_listing',
			'post_status' => 'pending',
			'post_title'  => 'Marketplace changes probe',
			'post_author' => $landlord->ID,
		]
	);
	$_POST = [
		'tdh_action'  => 'listing_changes',
		'tdh_listing' => (string) $second_q,
		'tdh_nonce'   => wp_create_nonce( TDH\Moderation::NONCE ),
		'tdh_reason'  => 'Please add photos of the kitchen.',
	];
	$run();
	ok( 'Request changes: sent back to the landlord, with the note', TDH\Statuses::REJECTED === get_post_status( $second_q ) && 'Please add photos of the kitchen.' === get_post_meta( $second_q, '_tdh_rejection_reason', true ) );

	echo "\n=== the review persona: marketplace caps, no manage_options ===\n";

	/*
	 * The client-review "Administrator" runs the marketplace WITHOUT
	 * manage_options. Everything staff-gated must key on the marketplace
	 * capability — this fixture is a user holding exactly that and no more.
	 */
	$reviewer_id = wp_insert_user(
		[
			'user_login' => 'tdh_review_probe',
			'user_email' => 'review-probe@example.com',
			'user_pass'  => wp_generate_password( 20 ),
			'role'       => '',
		]
	);
	$reviewer    = is_wp_error( $reviewer_id ) ? null : get_userdata( (int) $reviewer_id );

	if ( $reviewer ) {
		$reviewer->add_cap( 'edit_others_tdh_listings' );

		wp_set_current_user( $reviewer->ID );
		$_GET = [];

		ok( 'is_staff without manage_options', TDH\Accounts::is_staff() && ! user_can( $reviewer->ID, 'manage_options' ) );
		ok( 'the review persona sees the marketplace portal', str_contains( TDH\Account_Render::dashboard(), 'Marketplace overview' ) );

		$third_q = (int) wp_insert_post(
			[
				'post_type'   => 'tdh_listing',
				'post_status' => 'pending',
				'post_title'  => 'Review persona probe',
				'post_author' => $landlord->ID,
			]
		);

		$_POST = [
			'tdh_action'  => 'listing_approve',
			'tdh_listing' => (string) $third_q,
			'tdh_nonce'   => wp_create_nonce( TDH\Moderation::NONCE ),
		];
		$run();
		ok( 'and can approve a listing', 'publish' === get_post_status( $third_q ) );

		$_POST = [];
		wp_delete_post( $third_q, true );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $reviewer->ID );
	}

	$_POST = [];
	wp_delete_post( $second_q, true );
	wp_delete_post( $queued, true );
}

echo "\n=== the sign-in form ===\n";

// Email OR username — WordPress signs in with either, so the box must not
// turn a username away before the server sees it (reviewer, 19 Sep 2026).
wp_set_current_user( 0 );
$_GET  = [];
$login = TDH\Account_Render::login();
ok( 'the sign-in box asks for "Email or username"', str_contains( $login, 'Email or username' ) );
ok( '...and accepts a username (not an email-only field)', (bool) preg_match( '/id="tdh-login-email"[^>]*type="text"/', $login ) && ! (bool) preg_match( '/id="tdh-login-email"[^>]*type="email"/', $login ) );

/*
 * G3b design review: a renter who tapped Sign in in the header could not
 * tell whether they needed an account. The heading says who the form is
 * for and a line under it sends the renter on.
 */
ok( 'the heading says who it is for, and a renter is told they need no account', str_contains( $login, 'Landlord sign in' ) && str_contains( $login, 'You don’t need an account' ) && str_contains( $login, '>Find a home<' ) );
ok( '...the password field carries a Show / Hide control the theme reveals, and the breadcrumb is gone from the form column', str_contains( $login, 'data-tdh-pw="tdh-login-password"' ) && str_contains( $login, 'aria-pressed="false"' ) && ! str_contains( $login, 'class="breadcrumb"' ) );

$register = TDH\Account_Render::register();
ok( 'Create account names the price before anyone types', str_contains( $register, 'Plans from $' . number_format_i18n( (float) TDH\Render::plans()[0]['price'] ) . ' a month' ) );
ok( '...says why it asks for a phone, beside the field', str_contains( $register, 'text alerts about inquiries' ) );
ok( '...and its password field has the same control', str_contains( $register, 'data-tdh-pw="tdh-password"' ) );

/* Fixtures away. */
if ( $listing ) {
	wp_delete_post( $listing->ID, true );
}
if ( $landlord ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $landlord->ID );
}
echo "\n  fixtures removed\n";

[ $p, $f ] = ok();
printf( "\n%s  %d passed, %d failed\n\n", $f ? 'FAILED' : 'PASSED', $p, $f );
