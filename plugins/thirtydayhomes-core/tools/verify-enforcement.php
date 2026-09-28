<?php
/**
 * Grace, hide, restore (E1) — verification suite.
 *
 * Drives Membership::apply() the way the Stripe webhook does, and the daily
 * job the way cron does, but ONLY over its own fixtures: the job is called
 * with an explicit list, so no real account on the site is ever swept.
 * Every user, home, term and log line it creates is removed at the end.
 *
 * @package ThirtyDayHomes
 */

use TDH\Account_Render;
use TDH\Enforcement;
use TDH\Listing_Form;
use TDH\Listing_Preview;
use TDH\Membership;
use TDH\Moderation;
use TDH\Post_Types;
use TDH\Statuses;
use TDH\Visibility;

function ok( string $label = '', bool $cond = false, string $detail = '' ): array {
	static $p = 0, $f = 0;
	if ( '' === $label ) { return [ $p, $f ]; }
	if ( $cond ) { ++$p; printf( "  ok    %s\n", $label ); }
	else { ++$f; printf( "  FAIL  %s%s\n", $label, '' !== $detail ? "  --> {$detail}" : '' ); }
	return [ $p, $f ];
}

/** Run a handler that ends in exit; report where it redirected. */
function landed( callable $handler ): string {
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

function status_of( int $id ): string {
	clean_post_cache( $id );
	return (string) get_post_status( $id );
}

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/user.php';

$log_table = TDH\Log::table();
$log_floor = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$log_table}" ); // phpcs:ignore WordPress.DB
$log_was   = getenv( 'TDH_LOG_SILENT' );
putenv( 'TDH_LOG_SILENT' );

$last_run_was = get_option( 'tdh_enforce_last_run', null );
$job          = new Enforcement();

echo "\n=== fixtures ===\n";

foreach ( [ 'tdh_enf_landlord', 'tdh_enf_other', 'tdh_enf_staff' ] as $login ) {
	$stale = get_user_by( 'login', $login );
	if ( $stale ) {
		wp_delete_user( (int) $stale->ID );
	}
}
foreach ( (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tdh_listing' AND post_title LIKE 'Enf probe %'" ) as $stale ) { // phpcs:ignore WordPress.DB
	wp_delete_post( (int) $stale, true );
}

$make_user = static function ( string $login, string $role ): int {
	return (int) wp_insert_user( [ 'user_login' => $login, 'user_email' => $login . '@example.com', 'user_pass' => wp_generate_password( 20 ), 'role' => $role ] );
};

$landlord = $make_user( 'tdh_enf_landlord', 'tdh_landlord' );
$other    = $make_user( 'tdh_enf_other', 'tdh_landlord' );
$staff    = $make_user( 'tdh_enf_staff', 'administrator' );
$made     = [];

$home = static function ( string $title, string $status, int $author, array $meta = [] ) use ( &$made ): int {
	$id = (int) wp_insert_post( [ 'post_type' => Post_Types::LISTING, 'post_status' => $status, 'post_title' => 'Enf probe ' . $title, 'post_author' => $author, 'post_content' => '<!-- wp:paragraph --><p>Bright & <em>quiet</em>.</p><!-- /wp:paragraph -->' ] );
	foreach ( $meta + [ '_tdh_price_monthly' => 2000 ] as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	$made[] = $id;
	return $id;
};

$live1   = $home( 'live one', 'publish', $landlord, [ '_tdh_beds' => 2, '_tdh_street_address' => '1 Probe Way' ] );
$live2   = $home( 'live two', 'publish', $landlord );
$paused  = $home( 'paused', Statuses::PAUSED, $landlord );
$pending = $home( 'pending', 'pending', $landlord, [ '_tdh_lat' => 40.44, '_tdh_lng' => -79.99, '_tdh_geocode_status' => 'manual' ] );
$draft   = $home( 'draft', 'draft', $landlord );
$others  = $home( 'someone else', 'publish', $other );
$staffs  = $home( 'staff owned', 'publish', $staff );

$city = wp_insert_term( 'Enf Probe City', 'tdh_city' );
$city = is_wp_error( $city ) ? (int) ( $city->get_error_data() ?? 0 ) : (int) $city['term_id'];
wp_set_object_terms( $live1, [ $city ], 'tdh_city' );

$content_before = (string) get_post_field( 'post_content', $live1 );

$active = static function ( int $user ): void {
	Membership::apply( $user, [ 'status' => Membership::ACTIVE, 'quota' => 5, 'plan' => 'Five', 'expires' => strtotime( '+30 days' ) ] );
};
$active( $landlord );
$active( $other );

ok( 'a landlord, another landlord, staff, seven homes and a city exist', $landlord && $other && $staff && 7 === count( $made ) && $city > 0 );
ok( 'an active landlord is "ok"', Enforcement::OK === Enforcement::state( $landlord ) );

echo "\n=== a payment fails: grace ===\n";

Membership::apply( $landlord, [ 'status' => Membership::PAST_DUE ] );
$stamp = (int) get_user_meta( $landlord, Enforcement::META_GRACE, true );

ok( 'past due starts the grace clock', $stamp > time() - 5 );
ok( '...and the state is grace', Enforcement::GRACE === Enforcement::state( $landlord ) );
ok( 'nothing is hidden during grace', 'publish' === status_of( $live1 ) && 'publish' === status_of( $live2 ) );
ok( '...and renters still see the homes', Visibility::is_public( $live1 ) );
ok( 'grace ends seven days after the first failure', Enforcement::grace_ends( $landlord ) === $stamp + Visibility::GRACE_DAYS * DAY_IN_SECONDS );

update_user_meta( $landlord, Enforcement::META_GRACE, $stamp - 100 );
Membership::apply( $landlord, [ 'status' => Membership::PAST_DUE ] );
ok( 'a second failed retry does not restart the clock', $stamp - 100 === (int) get_user_meta( $landlord, Enforcement::META_GRACE, true ) );

$band = Enforcement::band( $landlord );
ok( 'the landlord is told their homes stay visible, with the date', null !== $band && Enforcement::GRACE === $band['state'] && str_contains( $band['text'], '2 homes stay visible until' ) && str_contains( $band['text'], wp_date( 'j M Y', Enforcement::grace_ends( $landlord ) ) ), $band['text'] ?? '' );
ok( '...and the one next step is Update your card, on the membership screen', null !== $band && 'Update your card' === $band['action'] && str_contains( $band['url'], 'view=membership' ) );

wp_set_current_user( $landlord );
$_GET = [ 'view' => 'listings' ];
$page = Account_Render::dashboard();
$_GET = [];
ok( 'the band shows on My listings too, not only the overview', str_contains( $page, 'stay visible until' ) && str_contains( $page, 'Update your card' ) );
wp_set_current_user( 0 );

echo "\n=== renewal before the daily job: nothing was ever hidden ===\n";

$active( $landlord );
ok( 'paying inside grace clears the clock', '' === (string) get_user_meta( $landlord, Enforcement::META_GRACE, true ) );
ok( '...hides nothing and restores nothing', 'publish' === status_of( $live1 ) && '' === Enforcement::take_restored( $landlord ) );

echo "\n=== the grace period runs out ===\n";

Membership::apply( $landlord, [ 'status' => Membership::PAST_DUE ] );
update_user_meta( $landlord, Enforcement::META_GRACE, time() - Visibility::GRACE_DAYS * DAY_IN_SECONDS - 60 );
$job->run( [ $landlord, $other, $staff ] );

ok( 'the daily job hides every live home', Statuses::BILLING_HOLD === status_of( $live1 ) && Statuses::BILLING_HOLD === status_of( $live2 ) );
ok( '...and records when', '' !== (string) get_post_meta( $live1, Enforcement::META_HELD_AT, true ) );
ok( 'a paused home stays paused', Statuses::PAUSED === status_of( $paused ) );
ok( 'a home in review stays in review', 'pending' === status_of( $pending ) );
ok( 'a draft stays a draft', 'draft' === status_of( $draft ) );
ok( 'another landlord’s home is untouched', 'publish' === status_of( $others ) );
ok( 'a staff-owned home is never held', 'publish' === status_of( $staffs ) );
ok( 'the hidden homes are not public', ! Visibility::is_public( $live1 ) );
ok( 'the description is exactly as it was', $content_before === (string) get_post_field( 'post_content', $live1 ), (string) get_post_field( 'post_content', $live1 ) );
ok( '...and the meta and the city', 2 === (int) get_post_meta( $live1, '_tdh_beds', true ) && '1 Probe Way' === get_post_meta( $live1, '_tdh_street_address', true ) && in_array( $city, wp_get_object_terms( $live1, 'tdh_city', [ 'fields' => 'ids' ] ), true ) );

$held_at = (string) get_post_meta( $live1, Enforcement::META_HELD_AT, true );
$again   = Enforcement::enforce( $landlord );
ok( 'running it again holds nothing more (a duplicate webhook, a second run)', 0 === $again['held'] && $held_at === (string) get_post_meta( $live1, Enforcement::META_HELD_AT, true ) );

$band = Enforcement::band( $landlord );
ok( 'the landlord is told how many homes are hidden and that nothing is deleted', null !== $band && Enforcement::HOLD === $band['state'] && str_contains( $band['text'], 'Your 2 homes are hidden' ) && str_contains( $band['text'], 'Nothing is deleted' ), $band['text'] ?? '' );
ok( '...with Update your card while past due', null !== $band && 'Update your card' === $band['action'] );

echo "\n=== while hidden: no new home goes to review, none is approved ===\n";

foreach ( [ '_tdh_street_address' => '9 Probe Way', '_tdh_zip' => '15232', '_tdh_utilities_included' => 'yes', '_tdh_pet_policy' => 'no', '_tdh_contact_method' => 'email', '_tdh_beds' => 1, '_tdh_baths' => 1 ] as $k => $v ) {
	update_post_meta( $draft, $k, $v );
}
wp_set_object_terms( $draft, [ $city ], 'tdh_city' );
wp_set_current_user( $landlord );
$_GET  = [ 'listing' => (string) $draft, 'step' => '4' ];
$_POST = [ 'tdh_action' => 'listing_submit', 'tdh_nonce' => wp_create_nonce( Listing_Form::NONCE ), 'tdh_fair_housing' => '1' ];
landed( [ new Listing_Form(), 'handle' ] );
$_POST = [];
$errs  = implode( ' ', Listing_Form::take_errors() );
$_GET  = [];
ok( 'submitting a draft while past due is refused — the draft is kept', 'draft' === status_of( $draft ) && str_contains( $errs, 'membership is not active' ), $errs );

wp_set_current_user( $staff );
$_POST = [ 'tdh_action' => 'listing_approve', 'tdh_listing' => (string) $pending, 'tdh_nonce' => wp_create_nonce( Moderation::NONCE ) ];
$where = landed( [ new Moderation(), 'handle' ] );
$_POST = [];
ok( 'staff cannot approve a home whose landlord is unpaid', 'pending' === status_of( $pending ) && str_contains( $where, 'tdh_moderated=owner_inactive' ), $where );

$_GET = [ 'view' => 'listings', 'tdh_moderated' => 'owner_inactive' ];
$desk = Account_Render::dashboard();
$_GET = [];
ok( '...the screen says why', str_contains( $desk, 'membership isn’t active' ) );
ok( '...the queue row says so, and its Approve is greyed out pointing at the reason', str_contains( $desk, 'id="owner-' . $pending . '"' ) && (bool) preg_match( '/type="submit" disabled aria-describedby="[^"]*owner-' . $pending . '/', $desk ) );

$bar = Listing_Preview::bar( $pending );
ok( 'the preview bar offers no Approve either, and says why', ! str_contains( $bar, 'value="listing_approve"' ) && str_contains( $bar, 'membership isn’t active' ) );
wp_set_current_user( 0 );

echo "\n=== a stray live home of a lapsed landlord is still hidden ===\n";

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->update( $wpdb->posts, [ 'post_status' => 'publish' ], [ 'ID' => $live2 ], [ '%s' ], [ '%d' ] );
clean_post_cache( $live2 );
ok( 'fixture: one home put back to live by hand', 'publish' === status_of( $live2 ) );
ok( 'the visibility rule lists the lapsed landlord', in_array( $landlord, Visibility::inactive_member_ids(), true ) );
ok( '...so the live row is still not public', ! Visibility::is_public( $live2 ) );
ok( '...and an active landlord is never listed', ! in_array( $other, Visibility::inactive_member_ids(), true ) );
$job->run( [ $landlord ] );
ok( 'the next daily job puts it back on hold', Statuses::BILLING_HOLD === status_of( $live2 ) );

echo "\n=== paying brings back exactly the hidden homes ===\n";

wp_set_current_user( $landlord );
$paused_pause = (string) get_post_meta( $paused, '_tdh_paused_at', true );
$active( $landlord );

ok( 'both hidden homes are live again', 'publish' === status_of( $live1 ) && 'publish' === status_of( $live2 ) );
ok( '...with no held stamp left', '' === (string) get_post_meta( $live1, Enforcement::META_HELD_AT, true ) );
ok( 'the home the landlord paused stays paused', Statuses::PAUSED === status_of( $paused ) );
ok( 'the grace clock is cleared', '' === (string) get_user_meta( $landlord, Enforcement::META_GRACE, true ) );
ok( 'renters see them again', Visibility::is_public( $live1 ) );
ok( 'the description survived the round trip', $content_before === (string) get_post_field( 'post_content', $live1 ) );

$_GET = [ 'view' => 'overview' ];
$page = Account_Render::dashboard();
$_GET = [];
ok( 'the next dashboard view says they are back', str_contains( $page, 'Payment received — your 2 homes are back online.' ) );
ok( '...once', '' === Enforcement::take_restored( $landlord ) );
ok( 'with everything fine there is no band text of its own', null === Enforcement::band( $landlord ) );

$again = Enforcement::enforce( $landlord );
ok( 'a duplicate "active" restores nothing more', 0 === $again['restored'] );

echo "\n=== one home, in the singular ===\n";

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->update( $wpdb->posts, [ 'post_status' => Statuses::PAUSED ], [ 'ID' => $live2 ], [ '%s' ], [ '%d' ] );
clean_post_cache( $live2 );
Membership::apply( $landlord, [ 'status' => Membership::PAST_DUE ] );
ok( '"Your 1 home stays visible"', str_starts_with( (string) ( Enforcement::band( $landlord )['text'] ?? '' ), 'Your 1 home stays visible' ), (string) ( Enforcement::band( $landlord )['text'] ?? '' ) );
Membership::apply( $landlord, [ 'status' => Membership::EXPIRED, 'quota' => 0 ] );
$band = Enforcement::band( $landlord );
ok( '"Your 1 home is hidden", immediately on expiry — no grace for an ended plan', Statuses::BILLING_HOLD === status_of( $live1 ) && str_contains( (string) ( $band['text'] ?? '' ), 'Your 1 home is hidden' ), (string) ( $band['text'] ?? '' ) );
ok( '...and an ended plan is offered Restart a plan', 'Restart a plan' === ( $band['action'] ?? '' ) && str_contains( (string) ( $band['url'] ?? '' ), 'pricing' ) );
wp_set_current_user( $landlord );
$_GET = [ 'view' => 'listings' ];
$page = Account_Render::dashboard();
$_GET = [];
wp_set_current_user( 0 );
ok( '...and the hidden row says the plan ended, not that a payment failed', str_contains( $page, 'Hidden because the plan has ended. Restart a plan' ) && ! str_contains( $page, 'Hidden because a payment failed' ) );
$active( $landlord );
ok( '"your 1 home is back online"', str_contains( Enforcement::take_restored( $landlord ), 'your 1 home is back online' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->update( $wpdb->posts, [ 'post_status' => 'publish' ], [ 'ID' => $live2 ], [ '%s' ], [ '%d' ] );
clean_post_cache( $live2 );

echo "\n=== a cancellation runs to the end of the paid period ===\n";

Membership::apply( $landlord, [ 'status' => Membership::CANCELLED, 'expires' => strtotime( '+10 days' ) ] );
$band = Enforcement::band( $landlord );
ok( 'cancelling hides nothing yet', 'publish' === status_of( $live1 ) );
ok( '...and says when the homes come down', null !== $band && str_contains( $band['text'], 'Your membership ends ' . wp_date( 'j M Y', strtotime( '+10 days' ) ) ), $band['text'] ?? '' );

// The period ends and no webhook comes: Membership::status() reads it as expired.
update_user_meta( $landlord, Membership::META_EXPIRES, time() - 60 );
$job->run( [ $landlord ] );
ok( 'the daily job hides them once the paid period is over, webhook or not', Statuses::BILLING_HOLD === status_of( $live1 ) && Statuses::BILLING_HOLD === status_of( $live2 ) );
$active( $landlord );
Enforcement::take_restored( $landlord );

echo "\n=== the plan's limit, said plainly (R40) ===\n";

ok( 'within the plan: "3 of 5 used"', '3 of 5 used' === Membership::usage( 3, 5 ) );
ok( 'at the limit: "5 of 5 used"', '5 of 5 used' === Membership::usage( 5, 5 ) );
ok( 'over it: "7 homes · plan covers 2", never "7 of 2 used"', '7 homes · plan covers 2' === Membership::usage( 7, 2 ) );

Membership::apply( $landlord, [ 'quota' => 2 ] );
wp_set_current_user( $landlord );
$_GET = [ 'view' => 'listings' ];
$page = Account_Render::dashboard();
$_GET = [ 'view' => 'membership' ];
$plan = Account_Render::dashboard();
$_GET = [];
ok( 'My listings says how many homes and what the plan covers', str_contains( $page, '5 homes · plan covers 2' ) && ! str_contains( $page, '5 of 2 used' ) );
ok( '...and what to do: delete 4 or move to a larger plan', str_contains( $page, 'class="portal-over-plan"' ) && str_contains( $page, 'Your plan covers 2 homes. To add another, delete 4 or move to a larger plan.' ) );
ok( 'the membership screen says it the same way', str_contains( $plan, '5 homes · plan covers 2' ) );
Membership::apply( $landlord, [ 'quota' => 5 ] );
$page = Account_Render::dashboard();
ok( 'back within the plan, the extra line is gone', ! str_contains( $page, 'portal-over-plan' ) );

echo "\n=== Update your card goes straight to Stripe when it can ===\n";

update_user_meta( $landlord, Membership::META_CUSTOMER, 'cus_enf_probe' );
Membership::apply( $landlord, [ 'status' => Membership::PAST_DUE ] );
$_GET = [ 'view' => 'overview' ];
$page = Account_Render::dashboard();
$_GET = [];
preg_match( '#<section class="portal-alert[^>]*id="membership".*?</section>#s', $page, $band_html );
$band_html = $band_html[0] ?? '';
if ( TDH\Billing\Customer_Portal::is_ready( $landlord ) ) {
	ok( 'with Stripe ready, the band is a form that opens the billing portal', str_contains( $band_html, 'value="tdh_customer_portal"' ) && str_contains( $band_html, '<button type="submit">Update your card</button>' ) );
} else {
	ok( 'without a Stripe key, the band links to the membership screen instead', str_contains( $band_html, 'view=membership' ) && str_contains( $band_html, '>Update your card</a>' ) );
}
delete_user_meta( $landlord, Membership::META_CUSTOMER );
$page = Account_Render::dashboard();
preg_match( '#<section class="portal-alert[^>]*id="membership".*?</section>#s', $page, $band_html );
ok( 'with no Stripe customer, it is a link to the membership screen', str_contains( $band_html[0] ?? '', '>Update your card</a>' ) && ! str_contains( $band_html[0] ?? '', 'tdh_customer_portal' ) );
$active( $landlord );
Enforcement::take_restored( $landlord );
wp_set_current_user( 0 );

echo "\n=== a home with no landlord never waits on a plan ===\n";

$orphan = $home( 'unassigned', 'pending', 0, [ '_tdh_lat' => 40.44, '_tdh_lng' => -79.99, '_tdh_geocode_status' => 'manual' ] );
ok( 'an unassigned home is never "blocked by the owner’s plan"', ! TDH\Listing_Actions::approval_blocked( 0 ) );
wp_set_current_user( $staff );
$_GET = [ 'view' => 'listings' ];
$desk = Account_Render::dashboard();
$_GET = [];
ok( '...its queue row carries no "Membership inactive" line', ! str_contains( $desk, 'id="owner-' . $orphan . '"' ) );
ok( '...and its preview bar offers Approve', str_contains( Listing_Preview::bar( $orphan ), 'value="listing_approve"' ) );
$_POST = [ 'tdh_action' => 'listing_approve', 'tdh_listing' => (string) $orphan, 'tdh_nonce' => wp_create_nonce( Moderation::NONCE ) ];
$where = landed( [ new Moderation(), 'handle' ] );
$_POST = [];
ok( '...and staff can approve it', 'publish' === status_of( $orphan ), $where );
wp_set_current_user( 0 );

echo "\n=== staff are never billed ===\n";

Membership::apply( $staff, [ 'status' => Membership::EXPIRED ] );
ok( 'an administrator with an ended plan is still "ok"', Enforcement::OK === Enforcement::state( $staff ) && 'publish' === status_of( $staffs ) );

echo "\n=== the city archive is guarded too ===\n";

$q = new WP_Query();
$q->parse_query( [ 'tdh_city' => 'enf-probe-city' ] );
$was_main               = $GLOBALS['wp_the_query'];
$GLOBALS['wp_the_query'] = $q;
$m                      = new ReflectionMethod( Visibility::class, 'is_listing_query' );
$m->setAccessible( true );
$guarded                = $m->invoke( new Visibility(), $q );
$GLOBALS['wp_the_query'] = $was_main;
ok( 'a city archive is a listing query for the visibility rule', true === $guarded );

echo "\n=== what the log was told ===\n";

$events = (array) $wpdb->get_col( $wpdb->prepare( "SELECT event FROM {$log_table} WHERE id > %d", $log_floor ) ); // phpcs:ignore WordPress.DB
foreach ( [ 'grace_started', 'billing_hold', 'billing_restored' ] as $event ) {
	ok( "'{$event}' was logged", in_array( $event, $events, true ), implode( ', ', array_unique( $events ) ) );
}

echo "\n=== cleanup ===\n";

foreach ( $made as $id ) {
	wp_delete_post( $id, true );
}
wp_delete_term( $city, 'tdh_city' );
foreach ( [ $landlord, $other, $staff ] as $u ) {
	delete_transient( 'tdh_homes_restored_' . $u );
	wp_delete_user( $u );
}
$_GET  = [];
$_POST = [];
wp_set_current_user( 0 );

if ( null === $last_run_was ) {
	delete_option( 'tdh_enforce_last_run' );
} else {
	update_option( 'tdh_enforce_last_run', $last_run_was, false );
}

$wpdb->query( $wpdb->prepare( "DELETE FROM {$log_table} WHERE id > %d", $log_floor ) ); // phpcs:ignore WordPress.DB
delete_transient( TDH\Log::TRANSIENT_FAILED );
if ( false === $log_was ) {
	putenv( 'TDH_LOG_SILENT' );
} else {
	putenv( 'TDH_LOG_SILENT=' . $log_was );
}

$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'Enf probe %'" ); // phpcs:ignore WordPress.DB
ok( 'every fixture is gone', 0 === $left && ! get_user_by( 'login', 'tdh_enf_landlord' ) && ! term_exists( 'Enf Probe City', 'tdh_city' ) );
ok( '...and not one line left in the staff log', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log_table} WHERE id > %d", $log_floor ) ) ); // phpcs:ignore WordPress.DB

[ $pass, $fail ] = ok();
printf( "\n%s  %d passed, %d failed\n", $fail ? 'FAILED' : 'PASSED', $pass, $fail );
if ( $fail ) {
	exit( 1 );
}
