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

	// Probing an unpublished id counts nothing.
	wp_set_current_user( 0 );
	$hidden = get_posts( [ 'post_type' => 'tdh_listing', 'post_status' => 'pending', 'posts_per_page' => 1, 'tdh_bypass_visibility' => true ] );
	if ( $hidden ) {
		$h  = Views::for_listing( $hidden[0]->ID );
		$r2 = new WP_REST_Request( 'POST', '/tdh/v1/listing-view' );
		$r2->set_param( 'id', $hidden[0]->ID );
		$views->record( $r2 );
		ok( 'a pending listing cannot be counted by probing ids', Views::for_listing( $hidden[0]->ID ) === $h );
	}

	ok( 'the author total includes it', Views::total_for_author( $landlord->ID ) >= $before + 2 );

}

echo "\n=== the portal renders, with true numbers ===\n";

wp_set_current_user( $landlord ? $landlord->ID : 0 );

$html = TDH\Account_Render::dashboard();

ok( 'it renders', '' !== trim( $html ) );

foreach ( [ 'portal-side', 'portal-nav', 'portal-top', 'portal-heading', 'portal-alert', 'portal-metrics', 'portal-metric', 'portal-columns', 'portal-return' ] as $hook ) {
	ok( sprintf( '.%-16s present', $hook ), str_contains( $html, $hook ) );
}

foreach ( [ 'Landlord portal', 'Overview', 'My listings', 'Inquiries', 'Membership', 'Profile', 'Public website', 'Add property', 'Live listings', 'Pending review', 'New inquiries', 'Listing views', 'Your listings', 'Recent inquiries' ] as $label ) {
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

ok( 'no invented 284 anywhere', ! str_contains( $html, '284' ) );

echo "\n=== links go somewhere real ===\n";

ok( 'Add property links out', (bool) preg_match( '/Add property/', $html ) && (bool) preg_match( '/class="primary" href="http/', $html ) );
ok( 'sign out is present', str_contains( $html, 'Sign out' ) );

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
	ok( 'the developer keeps a separate WordPress dashboard door', str_contains( $mk, 'WordPress dashboard' ) && str_contains( $mk, 'wp-admin' ) );

	echo "\n=== the portal's working views ===\n";

	$_GET     = [ 'view' => 'listings' ];
	$listings = TDH\Account_Render::dashboard();
	ok( 'Listings: the approval desk renders', str_contains( $listings, 'Waiting for approval' ) && str_contains( $listings, 'All listings' ) );
	ok( 'Listings: Approve and Request changes stand ready', str_contains( $listings, 'listing_approve' ) && str_contains( $listings, 'listing_changes' ) );
	ok( 'Listings: the fixture waits with its owner named', str_contains( $listings, 'Marketplace queue probe' ) );

	$_GET = [ 'view' => 'members' ];
	$mem  = TDH\Account_Render::dashboard();
	ok( 'Members: the landlord roster renders', str_contains( $mem, 'Members' ) && str_contains( $mem, 'portal-probe@example.com' ) );

	$_GET = [ 'view' => 'inquiries' ];
	ok( 'Inquiries view renders', str_contains( TDH\Account_Render::dashboard(), 'Inquiries' ) );

	$_GET = [ 'view' => 'facilities' ];
	ok( 'Facilities view renders', str_contains( TDH\Account_Render::dashboard(), 'Facilities' ) );

	$_GET = [ 'view' => 'nonsense' ];
	ok( 'an unknown view is the overview, not an error', str_contains( TDH\Account_Render::dashboard(), 'Marketplace overview' ) );
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
	];
	$run();
	ok( 'Request changes: sent back to the landlord', TDH\Statuses::REJECTED === get_post_status( $second_q ) );

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
