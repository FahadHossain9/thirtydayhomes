<?php
/**
 * A listing's life from the dashboard: pause, resume, delete, changes
 * requested with a reason, resubmit, the state of every row, pagination,
 * and the preview bar's actions for each role.
 *
 * Run through verify.bat, or directly:
 *     wp eval-file wp-content/plugins/thirtydayhomes-core/tools/verify-listing-actions.php
 */

use TDH\Account_Render;
use TDH\Listing_Actions;
use TDH\Listing_Form;
use TDH\Listing_Manage_Render;
use TDH\Listing_Preview;
use TDH\Membership;
use TDH\Moderation;
use TDH\Statuses;
use TDH\Visibility;

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

$actions = new Listing_Actions();

/** Post one dashboard action as the current user. */
function act( Listing_Actions $actions, string $action, int $id, array $extra = [] ): string {
	$_POST = array_merge(
		[
			'tdh_action'  => $action,
			'tdh_listing' => (string) $id,
			'tdh_nonce'   => wp_create_nonce( Listing_Actions::NONCE ),
			'tdh_return'  => 'listings',
		],
		$extra
	);
	$where = redirect_of( [ $actions, 'handle' ] );
	$_POST = [];
	return $where;
}

$make_user = static function ( string $login, string $role ): int {
	$existing = get_user_by( 'login', $login );
	if ( $existing ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $existing->ID );
	}
	$id = wp_insert_user( [ 'user_login' => $login, 'user_email' => $login . '@example.com', 'user_pass' => wp_generate_password( 20 ), 'role' => $role, 'display_name' => 'Dana Probe' ] );
	return is_wp_error( $id ) ? 0 : (int) $id;
};

$owner    = $make_user( 'tdh_actions_owner', 'tdh_landlord' );
$stranger = $make_user( 'tdh_actions_stranger', 'tdh_landlord' );
$admin    = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0] ?? null;

foreach ( [ $owner, $stranger ] as $uid ) {
	update_user_meta( $uid, Membership::META_STATUS, Membership::ACTIVE );
	update_user_meta( $uid, Membership::META_QUOTA, 20 );
	update_user_meta( $uid, Membership::META_EXPIRES, strtotime( '+30 days' ) );
}

$created = [];
$listing = static function ( string $status, string $title, int $author ) use ( &$created ): int {
	$id = (int) wp_insert_post( [ 'post_type' => 'tdh_listing', 'post_status' => $status, 'post_title' => $title, 'post_author' => $author ] );
	update_post_meta( $id, '_tdh_price_monthly', 2100 );
	$created[] = $id;
	return $id;
};

$live    = $listing( 'publish', 'Actions probe live', $owner );
$pending = $listing( 'pending', 'Actions probe pending', $owner );

echo "\n=== pause ===\n";

wp_set_current_user( $owner );

$where = act( $actions, 'listing_pause', $live );
ok( 'Pause takes a live home down', Statuses::PAUSED === get_post_status( $live ), $where );
ok( '...records when', '' !== (string) get_post_meta( $live, '_tdh_paused_at', true ) );
ok( '...and lands on My listings, flagged, with the home named by id', str_contains( $where, 'view=listings' ) && str_contains( $where, 'tdh_done=paused' ) && str_contains( $where, 'tdh_home=' . $live ) && ! str_contains( $where, 'tdh_listing=' ) );
ok( 'a paused home is not public', ! Visibility::is_public( $live ) );

$public = new WP_Query( [ 'post_type' => 'tdh_listing', 'post_status' => 'publish', 'post__in' => [ $live ], 'fields' => 'ids' ] );
ok( 'and search cannot find it', ! in_array( $live, array_map( 'intval', $public->posts ), true ) );

$where = act( $actions, 'listing_pause', $live );
ok( 'pausing twice is harmless — reported done, no error', Statuses::PAUSED === get_post_status( $live ) && str_contains( $where, 'tdh_done=paused' ) );

$where = act( $actions, 'listing_pause', $pending );
ok( 'a home in review cannot be paused', 'pending' === get_post_status( $pending ) && str_contains( $where, 'tdh_done=not_live' ) );
$note = Listing_Actions::notice( 'not_live', $pending );
ok( '...and the reason is explained in words', $note && str_contains( $note['text'], 'still in review' ) );

echo "\n=== resume ===\n";

update_user_meta( $owner, Membership::META_STATUS, Membership::PAST_DUE );
$where = act( $actions, 'listing_resume', $live );
ok( 'resume is refused while the membership is not active', Statuses::PAUSED === get_post_status( $live ) && str_contains( $where, 'tdh_done=billing' ) );
$note = Listing_Actions::notice( 'billing', $live );
ok( '...pointing at billing', $note && 'error' === $note['type'] && str_contains( $note['text'], 'Membership' ) );

wp_set_current_user( $owner );
$_GET = [ 'view' => 'listings' ];
$rows = Account_Render::dashboard();
ok( 'the paused row says so before anyone presses Resume, and offers Manage billing', str_contains( $rows, 'can’t go live again yet' ) && str_contains( $rows, 'Manage billing' ) && ! str_contains( $rows, 'value="listing_resume"' ) );

update_user_meta( $owner, Membership::META_STATUS, Membership::CANCELLED );
ok( 'a cancelled membership still inside its paid period may resume', Listing_Actions::owner_can_publish( $owner ) );

update_user_meta( $owner, Membership::META_STATUS, Membership::ACTIVE );
$where = act( $actions, 'listing_resume', $live );
ok( 'Resume puts it straight back live — no second review', 'publish' === get_post_status( $live ), $where );
ok( '...stamps live-since and forgets the pause', '' !== (string) get_post_meta( $live, '_tdh_live_since', true ) && '' === (string) get_post_meta( $live, '_tdh_paused_at', true ) );
ok( 'resuming twice is harmless', str_contains( act( $actions, 'listing_resume', $live ), 'tdh_done=resumed' ) );
ok( 'resuming a home that was never paused is explained, not done', str_contains( act( $actions, 'listing_resume', $pending ), 'tdh_done=not_paused' ) && 'pending' === get_post_status( $pending ) );

if ( $admin ) {
	$staff_home = $listing( 'tdh_paused', 'Actions probe staff home', $admin->ID );
	ok( 'a home owned by staff is never held back by a membership', Listing_Actions::owner_can_publish( $admin->ID ) );
}

echo "\n=== someone else's listing ===\n";

wp_set_current_user( $stranger );
$where = act( $actions, 'listing_pause', $live );
ok( 'another landlord cannot pause it', 'publish' === get_post_status( $live ) );
$missing = act( $actions, 'listing_pause', 999999999 );
ok( '...and gets exactly the answer a missing id gets', str_contains( $where, 'tdh_done=missing' ) && str_contains( $missing, 'tdh_done=missing' ) && ! str_contains( $where, (string) $live ) );
ok( 'another landlord cannot delete it', ! str_contains( act( $actions, 'listing_delete', $live ), 'tdh_done=deleted' ) && 'publish' === get_post_status( $live ) );
ok( 'the notice never reads out another landlord’s home name', ! str_contains( (string) ( Listing_Actions::notice( 'paused', $live )['text'] ?? '' ), 'Actions probe live' ) );

wp_set_current_user( $owner );
$_POST = [ 'tdh_action' => 'listing_pause', 'tdh_listing' => (string) $live, 'tdh_nonce' => 'stale' ];
$where = redirect_of( [ $actions, 'handle' ] );
$_POST = [];
ok( 'an expired form changes nothing and says so', 'publish' === get_post_status( $live ) && str_contains( $where, 'tdh_done=expired' ) );

wp_set_current_user( 0 );
ok( 'a signed-out request is sent to sign in', str_contains( act( $actions, 'listing_pause', $live ), 'login' ) && 'publish' === get_post_status( $live ) );

echo "\n=== delete ===\n";

wp_set_current_user( $owner );
$doomed = $listing( 'publish', 'Actions probe doomed', $owner );
$before = Membership::listing_count( $owner );

$inquiry = (int) wp_insert_post( [ 'post_type' => 'tdh_inquiry', 'post_status' => 'publish', 'post_title' => 'Probe inquiry' ] );
update_post_meta( $inquiry, '_tdh_listing_id', $doomed );
update_post_meta( $inquiry, '_tdh_renter_name', 'Riley Renter' );
update_post_meta( $inquiry, '_tdh_message', 'Is it free in March?' );

$_GET  = [ 'view' => 'listings', 'delete' => (string) $doomed ];
$panel = Account_Render::dashboard();
ok( 'Delete first asks, in the page, naming the home', str_contains( $panel, 'manage-confirm' ) && str_contains( $panel, 'Delete “Actions probe doomed”?' ) );
ok( '...saying renters lose it and the inquiry is kept', str_contains( $panel, 'from renters straight away' ) && str_contains( $panel, '1 inquiry' ) );
ok( '...with a danger button and Keep it, and no browser confirm()', str_contains( $panel, 'class="danger"' ) && str_contains( $panel, 'Keep it' ) && ! str_contains( $panel, 'confirm(' ) );
ok( '...focus moves into it and Escape cancels', str_contains( $panel, 'focus(' ) && str_contains( $panel, "'Escape'" ) );

$_GET  = [ 'view' => 'listings', 'delete' => (string) $pending ];
$other = Account_Render::dashboard();
ok( 'a home that is not live is not described as leaving renters', str_contains( $other, 'Delete “Actions probe pending”?' ) && ! str_contains( $other, 'from renters straight away' ) );
$_GET = [];

$where = act( $actions, 'listing_delete', $doomed );
ok( 'Delete moves it to the trash, not a hard delete', 'trash' === get_post_status( $doomed ), $where );
ok( '...frees one of the allowance', Membership::listing_count( $owner ) === $before - 1 );
ok( '...and says so by name', str_contains( $where, 'tdh_done=deleted' ) && str_contains( (string) ( Listing_Actions::notice( 'deleted', $doomed )['text'] ?? '' ), 'Actions probe doomed' ) );
ok( 'a double click finds nothing to do and reports it done', str_contains( act( $actions, 'listing_delete', $doomed ), 'tdh_done=deleted' ) );

$_GET = [ 'view' => 'listings' ];
ok( 'the deleted home is gone from My listings', ! str_contains( Account_Render::dashboard(), 'Actions probe doomed' ) );
$_GET = [ 'view' => 'inquiries' ];
ok( '...but its inquiry is still in the inbox', str_contains( Account_Render::dashboard(), 'Riley Renter' ) );
$_GET = [];
wp_delete_post( $inquiry, true );

echo "\n=== changes requested, with a reason, and resubmit ===\n";

$sent_back = $listing( 'pending', 'Actions probe sent back', $owner );

if ( $admin ) {
	$moderation = new Moderation();
	wp_set_current_user( $admin->ID );

	$_GET  = [ 'view' => 'listings' ];
	$desk  = Account_Render::dashboard();
	ok( 'the queue offers Request changes as a link that opens a note', str_contains( $desk, 'request_changes=' . $sent_back ) );

	$_GET  = [ 'view' => 'listings', 'request_changes' => (string) $sent_back ];
	$desk  = Account_Render::dashboard();
	ok( 'the note form opens under that home, required, with who reads it', str_contains( $desk, 'name="tdh_reason"' ) && str_contains( $desk, 'required' ) && str_contains( $desk, 'word for word' ) && str_contains( $desk, 'Send to landlord' ) );
	$_GET  = [];

	$_POST = [ 'tdh_action' => 'listing_changes', 'tdh_listing' => (string) $sent_back, 'tdh_nonce' => wp_create_nonce( Moderation::NONCE ), 'tdh_reason' => "   \n  " ];
	$where = redirect_of( [ $moderation, 'handle' ] );
	ok( 'an empty note is refused — nothing is sent back', 'pending' === get_post_status( $sent_back ) && str_contains( $where, 'tdh_moderated=reason' ) && str_contains( $where, 'request_changes=' . $sent_back ) );

	$_GET = [ 'view' => 'listings', 'tdh_moderated' => 'reason' ];
	ok( '...and the refusal is visible (it used to be silent)', str_contains( Account_Render::dashboard(), 'can’t be empty' ) );
	$_GET = [];

	$_POST['tdh_reason'] = "Please add a photo of each bedroom.\nThe rent looks like a typo.";
	redirect_of( [ $moderation, 'handle' ] );
	$_POST = [];
	ok( 'with a note, the home is sent back', Statuses::REJECTED === get_post_status( $sent_back ) );
	ok( '...and the note is stored for the landlord', str_contains( (string) get_post_meta( $sent_back, '_tdh_rejection_reason', true ), 'each bedroom' ) );

	$bar = Listing_Preview::bar( $pending );
	ok( 'the staff preview bar sends Request changes to the same note form', str_contains( $bar, 'request_changes=' . $pending ) && str_contains( $bar, 'value="listing_approve"' ) );
}

wp_set_current_user( $owner );
$_GET = [ 'view' => 'listings' ];
$rows = Account_Render::dashboard();
ok( 'the landlord sees the note under the home', str_contains( $rows, 'What to change' ) && str_contains( $rows, 'each bedroom' ) );
ok( '...with Edit and resubmit as its main action', (bool) preg_match( '/class="primary" href="[^"]*step=4[^"]*listing=' . $sent_back . '[^"]*">\s*Edit and resubmit/', $rows ) );

$_GET = [ 'step' => '4', 'listing' => (string) $sent_back ];
ok( 'the listing form opens a home sent back to its landlord', Listing_Form::current_listing() instanceof WP_Post );
$form_html = TDH\Listing_Form_Render::form();
ok( '...carrying the note on the step', str_contains( $form_html, 'Changes requested' ) && str_contains( $form_html, 'each bedroom' ) );
ok( '...and the button says Resubmit', str_contains( $form_html, 'Resubmit for review' ) );

wp_set_current_user( $stranger );
ok( 'another landlord still cannot open it', null === Listing_Form::current_listing() );
wp_set_current_user( $owner );

// Everything the submit check needs, so the resubmission goes through.
update_post_meta( $sent_back, '_tdh_street_address', '1 Probe Way' );
update_post_meta( $sent_back, '_tdh_zip', '15232' );
update_post_meta( $sent_back, '_tdh_utilities_included', 'partial' );
update_post_meta( $sent_back, '_tdh_pet_policy', 'considered' );
update_post_meta( $sent_back, '_tdh_contact_method', 'email' );
$city = get_terms( [ 'taxonomy' => 'tdh_city', 'hide_empty' => false, 'number' => 1, 'fields' => 'ids' ] );
if ( $city ) {
	wp_set_object_terms( $sent_back, [ (int) $city[0] ], 'tdh_city' );
}

$mail = [];
$trap = static function ( $short, $atts ) use ( &$mail ) { $mail[] = $atts; return true; };
add_filter( 'pre_wp_mail', $trap, 1, 2 );

$heard = null;
$listen = static function ( int $id, bool $again = false ) use ( &$heard ): void { $heard = [ $id, $again ]; };
add_action( 'tdh_listing_submitted', $listen, 5, 2 );

$_POST = [ 'tdh_action' => 'listing_submit', 'tdh_nonce' => wp_create_nonce( Listing_Form::NONCE ), 'tdh_fair_housing' => '1' ];
$where = redirect_of( [ new Listing_Form(), 'handle' ] );
$_POST = [];

remove_filter( 'pre_wp_mail', $trap, 1 );
remove_action( 'tdh_listing_submitted', $listen, 5 );

ok( 'Resubmit puts it back in review', 'pending' === get_post_status( $sent_back ), $where . ' / missing: ' . wp_json_encode( Listing_Form::missing_for_submit( $sent_back ) ) );
ok( '...stamps when', '' !== (string) get_post_meta( $sent_back, '_tdh_submitted_at', true ) );
ok( '...and the hook knows it is a resubmission', is_array( $heard ) && $heard[0] === $sent_back && true === $heard[1] );
$staff_mail = $mail[0] ?? [];
ok( 'staff get an email about it', ! empty( $staff_mail ) && get_option( 'admin_email' ) === ( is_array( $staff_mail['to'] ) ? $staff_mail['to'][0] : $staff_mail['to'] ) );
ok( '...saying resubmitted, naming the home, linking to the queue', str_contains( (string) ( $staff_mail['subject'] ?? '' ), 'Resubmitted' ) && str_contains( (string) ( $staff_mail['subject'] ?? '' ), 'Actions probe sent back' ) && str_contains( (string) ( $staff_mail['message'] ?? '' ), 'view=listings' ) );

$_GET = [ 'view' => 'listings' ];
ok( 'the row now reads In review, with the date it was submitted', (bool) preg_match( '/Actions probe sent back.*?Submitted \d/s', Account_Render::dashboard() ) );
$_GET = [];

if ( $admin ) {
	wp_set_current_user( $admin->ID );
	$_POST = [ 'tdh_action' => 'listing_approve', 'tdh_listing' => (string) $sent_back, 'tdh_nonce' => wp_create_nonce( Moderation::NONCE ) ];
	redirect_of( [ new Moderation(), 'handle' ] );
	$_POST = [];
	ok( 'approval records when and by whom, and clears the old note', '' !== (string) get_post_meta( $sent_back, '_tdh_approved_at', true ) && (int) get_post_meta( $sent_back, '_tdh_approved_by', true ) === $admin->ID && '' === (string) get_post_meta( $sent_back, '_tdh_rejection_reason', true ) );

	// Stale queue pages: a home that is no longer waiting must not change.
	$live_since = (string) get_post_meta( $sent_back, '_tdh_live_since', true );
	$_POST      = [ 'tdh_action' => 'listing_approve', 'tdh_listing' => (string) $sent_back, 'tdh_nonce' => wp_create_nonce( Moderation::NONCE ) ];
	$where      = redirect_of( [ new Moderation(), 'handle' ] );
	ok( 'approving a home that is already live is refused, and says so', str_contains( $where, 'tdh_moderated=not_pending' ) && 'publish' === get_post_status( $sent_back ), $where );
	ok( '...without re-stamping "Live since"', $live_since === (string) get_post_meta( $sent_back, '_tdh_live_since', true ) );

	$_POST = [ 'tdh_action' => 'listing_changes', 'tdh_listing' => (string) $sent_back, 'tdh_nonce' => wp_create_nonce( Moderation::NONCE ), 'tdh_reason' => 'From a page opened an hour ago.' ];
	$where = redirect_of( [ new Moderation(), 'handle' ] );
	ok( 'Request changes from a stale page cannot take a live home down', str_contains( $where, 'tdh_moderated=not_pending' ) && 'publish' === get_post_status( $sent_back ) && '' === (string) get_post_meta( $sent_back, '_tdh_rejection_reason', true ), $where );

	$withdrawn = $listing( 'pending', 'Actions probe withdrawn', $owner );
	wp_trash_post( $withdrawn );
	$_POST = [ 'tdh_action' => 'listing_approve', 'tdh_listing' => (string) $withdrawn, 'tdh_nonce' => wp_create_nonce( Moderation::NONCE ) ];
	$where = redirect_of( [ new Moderation(), 'handle' ] );
	ok( 'approving a home the landlord deleted meanwhile does not bring it back live', 'trash' === get_post_status( $withdrawn ) && str_contains( $where, 'tdh_moderated=not_pending' ), $where . ' / ' . get_post_status( $withdrawn ) );

	$_GET = [ 'view' => 'listings', 'tdh_moderated' => 'not_pending' ];
	ok( '...and the screen explains it', str_contains( Account_Render::dashboard(), 'no longer waiting for review' ) );
	$_GET  = [];
	$_POST = [];

	wp_set_current_user( $owner );
}

echo "\n=== every row: a line in words, one main action ===\n";

$draft   = $listing( 'draft', 'Actions probe draft', $owner );
$held    = $listing( 'tdh_billing_hold', 'Actions probe held', $owner );
$paused2 = $listing( 'tdh_paused', 'Actions probe paused', $owner );

$expect = [
	$live    => [ 'Live since', 'View live page' ],
	$pending => [ 'usually within one business day', 'Preview' ],
	$draft   => [ 'Not submitted yet', 'Continue editing' ],
	$paused2 => [ 'Hidden from renters until you resume it', 'Resume' ],
	$held    => [ 'the plan has ended', 'Manage billing' ], // Owner has no plan, so not "a payment failed" (E1).
];

foreach ( $expect as $id => [ $line, $primary ] ) {
	$state = Listing_Manage_Render::state( get_post( $id ) );
	ok( sprintf( '%-22s → "%s"', get_the_title( $id ), $primary ), str_contains( $state['line'], $line ) && $state['primary'][1] === $primary, wp_json_encode( $state ) );
}

$_GET = [ 'view' => 'listings' ];
$rows = Account_Render::dashboard();
ok( 'a live row posts Pause with the actions nonce', str_contains( $rows, 'value="listing_pause"' ) && str_contains( $rows, 'name="tdh_nonce"' ) );
ok( 'every row offers Delete, named for screen readers', substr_count( $rows, 'class="manage-delete"' ) >= 5 );
ok( 'no stored status key reaches the screen', ! str_contains( $rows, '>tdh_paused<' ) && ! str_contains( $rows, '>tdh_billing_hold<' ) );

echo "\n=== many homes: pages ===\n";

for ( $i = 1; $i <= 7; $i++ ) {
	$listing( 'draft', 'Actions probe filler ' . $i, $owner );
}
$total = count( get_posts( [ 'post_type' => 'tdh_listing', 'author' => $owner, 'post_status' => Listing_Manage_Render::statuses(), 'posts_per_page' => -1, 'fields' => 'ids', 'tdh_bypass_visibility' => true ] ) );

$_GET  = [ 'view' => 'listings' ];
$page1 = Account_Render::dashboard();
ok( sprintf( 'page one shows ten of %d homes, and says which page', $total ), 10 === substr_count( $page1, 'class="manage-item' ) && str_contains( $page1, 'Page 1 of 2' ) && str_contains( $page1, 'listings_page=2' ) );

$_GET  = [ 'view' => 'listings', 'listings_page' => '2' ];
$page2 = Account_Render::dashboard();
ok( 'page two shows the rest', ( $total - 10 ) === substr_count( $page2, 'class="manage-item' ) && str_contains( $page2, 'Page 2 of 2' ) );

$_GET  = [ 'view' => 'listings', 'listings_page' => '99' ];
ok( 'a page past the end shows the last page, not "no homes"', str_contains( Account_Render::dashboard(), 'Page 2 of 2' ) );

$_GET     = [];
$overview = Account_Render::dashboard();
ok( 'the overview shows five and links to all of them', 5 === substr_count( $overview, 'class="manage-item' ) && str_contains( $overview, sprintf( 'View all %d homes', $total ) ) );

echo "\n=== the preview bar, per role ===\n";

ok( 'the landlord’s paused preview offers Resume', str_contains( Listing_Preview::bar( $paused2 ), 'value="listing_resume"' ) );
ok( 'the landlord’s payment-held preview offers Manage billing', str_contains( Listing_Preview::bar( $held ), 'Manage billing' ) );

wp_update_post( [ 'ID' => $draft, 'post_status' => 'tdh_rejected' ] );
update_post_meta( $draft, '_tdh_rejection_reason', 'Add the square footage.' );
$bar = Listing_Preview::bar( $draft );
ok( 'a sent-back preview shows the note and Edit and resubmit', str_contains( $bar, 'What to change: Add the square footage.' ) && str_contains( $bar, 'Edit and resubmit' ) );

// A home that was live keeps its pretty address; its preview has no id in it.
$previous = $GLOBALS['wp_the_query'] ?? null;
$q        = new WP_Query();
$GLOBALS['wp_the_query'] = $q;
$q->parse_query( [ 'post_type' => 'tdh_listing', 'tdh_listing' => 'actions-probe-paused', 'name' => 'actions-probe-paused', 'preview' => 'true' ] );
ok( 'its landlord may preview a paused home by its pretty address', Visibility::permits_preview( $q ) );
wp_set_current_user( $stranger );
ok( '...another landlord may not', ! Visibility::permits_preview( $q ) );
wp_set_current_user( 0 );
ok( '...nor a visitor', ! Visibility::permits_preview( $q ) );
$GLOBALS['wp_the_query'] = $previous;

ok( 'Listing_Actions is a loaded module', TDH\Core::instance()->module( 'listing_actions' ) instanceof Listing_Actions );

echo "\n=== editing a live home (A4): small changes go live, big ones ask first ===\n";

wp_set_current_user( $owner );

$mail2 = [];
$trap2 = static function ( $short, $atts ) use ( &$mail2 ) { $mail2[] = $atts; return true; };
add_filter( 'pre_wp_mail', $trap2, 1, 2 );

/** A complete home, so step 1 re-posts cleanly. */
$home = static function ( string $status, string $title ) use ( $listing, $owner, $city ): int {
	$id = $listing( $status, $title, $owner );
	update_post_meta( $id, '_tdh_street_address', '1 Probe Way' );
	update_post_meta( $id, '_tdh_zip', '15232' );
	update_post_meta( $id, '_tdh_beds', 2 );
	update_post_meta( $id, '_tdh_baths', 1 );
	update_post_meta( $id, '_tdh_utilities_included', 'partial' );
	update_post_meta( $id, '_tdh_pet_policy', 'considered' );
	update_post_meta( $id, '_tdh_contact_method', 'email' );
	if ( $city ) {
		wp_set_object_terms( $id, [ (int) $city[0] ], 'tdh_city' );
	}
	return $id;
};

/** Step 1's payload as the home stands, with overrides. */
$basics = static function ( int $id, array $over = [] ): array {
	$post = get_post( $id );
	return array_merge(
		[
			'tdh_action'         => 'listing_basics',
			'tdh_nonce'          => wp_create_nonce( Listing_Form::NONCE ),
			'tdh_title'          => (string) $post->post_title,
			'tdh_address'        => (string) get_post_meta( $id, '_tdh_street_address', true ),
			'tdh_zip'            => (string) get_post_meta( $id, '_tdh_zip', true ),
			'tdh_city'           => (string) ( wp_get_object_terms( $id, 'tdh_city', [ 'fields' => 'ids' ] )[0] ?? 0 ),
			'tdh_rent'           => (string) get_post_meta( $id, '_tdh_price_monthly', true ),
			'tdh_beds'           => (string) get_post_meta( $id, '_tdh_beds', true ),
			'tdh_baths'          => (string) get_post_meta( $id, '_tdh_baths', true ),
			'tdh_contact_method' => 'email',
		],
		$over
	);
};

/** Post one wizard step as the current user; where did it go? */
$post_form = static function ( array $post, int $id ): string {
	$_GET  = [ 'listing' => (string) $id ];
	$_POST = $post;
	$where = redirect_of( [ new Listing_Form(), 'handle' ] );
	$_POST  = [];
	$_FILES = [];
	return $where;
};

$live_edit = $home( 'publish', 'Actions probe live edit' );

$_GET = [ 'listing' => (string) $live_edit ];
ok( 'its landlord can now open a live home in the wizard', (int) ( Listing_Form::current_listing()->ID ?? 0 ) === $live_edit );
wp_set_current_user( $stranger );
ok( '...another landlord still cannot', null === Listing_Form::current_listing() );
wp_set_current_user( $owner );

$_GET = [ 'step' => '1', 'listing' => (string) $live_edit ];
$page = TDH\Listing_Form_Render::form();
ok( 'the wizard says Edit listing and explains what goes back to review', str_contains( $page, 'Edit listing' ) && str_contains( $page, 'This home is live.' ) && str_contains( $page, 'go back to review first' ) );
ok( '...and offers "Save", not "Save draft"', (bool) preg_match( '/>\s*Save\s*<\/button>/', $page ) && ! str_contains( $page, 'Save draft' ) );

$where = $post_form( $basics( $live_edit, [ 'tdh_rent' => '2600' ] ), $live_edit );
ok( 'a rent change saves at once and the home stays live', 'publish' === get_post_status( $live_edit ) && 2600.0 === (float) get_post_meta( $live_edit, '_tdh_price_monthly', true ) && str_contains( $where, 'updated=1' ), $where );

$_GET = [ 'step' => '2', 'listing' => (string) $live_edit, 'updated' => '1' ];
ok( '...and the next step says the change is live now', str_contains( TDH\Listing_Form_Render::form(), 'Saved. Anything you changed here is live now.' ) );

$where = $post_form( $basics( $live_edit, [ 'tdh_title' => 'Actions probe renamed', 'tdh_rent' => '2700' ] ), $live_edit );
ok( 'a title change is held — nothing on the step is saved until confirmed', 'Actions probe live edit' === get_the_title( $live_edit ) && 2600.0 === (float) get_post_meta( $live_edit, '_tdh_price_monthly', true ) && 'publish' === get_post_status( $live_edit ) && str_contains( $where, 'confirm=1' ), $where );

$_GET = [ 'step' => '1', 'listing' => (string) $live_edit, 'confirm' => '1' ];
$page = TDH\Listing_Form_Render::form();
ok( 'the confirmation names the home and the field, with both ways out', str_contains( $page, 'These changes go back to review' ) && str_contains( $page, '<b>Title</b>' ) && str_contains( $page, 'Actions probe live edit' ) && str_contains( $page, 'Save and submit for review' ) && str_contains( $page, 'Go back and discard changes' ) );
ok( '...carrying the typed answers and the flag', str_contains( $page, 'name="tdh_title" value="Actions probe renamed"' ) && str_contains( $page, 'name="tdh_rent" value="2700"' ) && str_contains( $page, 'name="tdh_confirm_review" value="1"' ) );
$_GET = [ 'step' => '1', 'listing' => (string) $live_edit, 'confirm' => '1' ];
$again = TDH\Listing_Form_Render::form();
ok( 'refreshing the confirmation page keeps it — nothing lost, nothing saved', str_contains( $again, 'Save and submit for review' ) && str_contains( $again, 'name="tdh_title" value="Actions probe renamed"' ) && 'Actions probe live edit' === get_the_title( $live_edit ) );
ok( '...and its header arrow is a plain link back, not a Save button with no form', (bool) preg_match( '/<a class="lform-back" href="[^"]*step=1[^"]*" aria-label="Back"/', $again ) && ! str_contains( $again, 'Save and go back' ) );

update_user_meta( $owner, Membership::META_QUOTA, 0 );
$where = $post_form( $basics( $live_edit, [ 'tdh_title' => 'Actions probe renamed', 'tdh_confirm_review' => '1' ] ), $live_edit );
ok( 'a lapsed member cannot push a change through', 'Actions probe live edit' === get_the_title( $live_edit ) && 'publish' === get_post_status( $live_edit ), $where );
update_user_meta( $owner, Membership::META_QUOTA, 20 );

$where = $post_form( $basics( $live_edit, [ 'tdh_title' => 'Actions probe renamed', 'tdh_rent' => '2700', 'tdh_confirm_review' => '1' ] ), $live_edit );
ok( 'confirmed: the change is saved and the home goes to review', 'Actions probe renamed' === get_the_title( $live_edit ) && 2700.0 === (float) get_post_meta( $live_edit, '_tdh_price_monthly', true ) && 'pending' === get_post_status( $live_edit ) && str_contains( $where, 'tdh_done=in_review' ), $where );
ok( '...remembering what changed and when', str_contains( (string) get_post_meta( $live_edit, '_tdh_reviewing_edits', true ), 'Title' ) && '' !== (string) get_post_meta( $live_edit, '_tdh_submitted_at', true ) );
$note = $mail2[0] ?? [];
ok( 'staff are emailed about changes to a live home, naming the field', str_contains( (string) ( $note['subject'] ?? '' ), 'Edited home waiting for review' ) && str_contains( (string) ( $note['subject'] ?? '' ), 'Actions probe renamed' ) && str_contains( (string) ( $note['message'] ?? '' ), 'changed the title of' ) && str_contains( (string) ( $note['message'] ?? '' ), 'which was live' ) );

$sent  = count( $mail2 );
$where = $post_form( $basics( $live_edit, [ 'tdh_title' => 'Actions probe renamed', 'tdh_rent' => '2700', 'tdh_confirm_review' => '1' ] ), $live_edit );
ok( 'sending the confirmation twice changes nothing more', 'pending' === get_post_status( $live_edit ) && count( $mail2 ) === $sent );

$_GET = [ 'view' => 'listings' ];
ok( 'the row says the home is in review because of its changes, in words', str_contains( Account_Render::dashboard(), 'Your changes to the title are being checked' ) );
$_GET = [ 'step' => '1', 'listing' => (string) $live_edit, 'confirm' => '1' ];
ok( 'Back to the used confirmation page says the change is already in', str_contains( TDH\Listing_Form_Render::form(), 'already saved and the home is in review' ) );
$_GET = [ 'view' => 'listings' ];
ok( 'the preview bar says so too', str_contains( Listing_Preview::bar( $live_edit ), 'Your changes are being checked' ) );
$_GET = [];

if ( $admin ) {
	wp_set_current_user( $admin->ID );
	$_POST = [ 'tdh_action' => 'listing_approve', 'tdh_listing' => (string) $live_edit, 'tdh_nonce' => wp_create_nonce( Moderation::NONCE ) ];
	redirect_of( [ new Moderation(), 'handle' ] );
	$_POST = [];
	ok( 'approval puts it back live and forgets the note', 'publish' === get_post_status( $live_edit ) && '' === (string) get_post_meta( $live_edit, '_tdh_reviewing_edits', true ) );
	wp_set_current_user( $owner );
}

$live_two = $home( 'publish', 'Actions probe live two' );
$_GET     = [ 'step' => '4', 'listing' => (string) $live_two ];
$page     = TDH\Listing_Form_Render::form();
ok( 'step 4 of a live home has no Submit, only the way out', ! str_contains( $page, 'Submit for approval' ) && ! str_contains( $page, 'tdh_fair_housing' ) && str_contains( $page, 'Everything is saved' ) && str_contains( $page, 'View live page' ) && str_contains( $page, 'Check your listing' ) );
$where = $post_form( [ 'tdh_action' => 'listing_submit', 'tdh_nonce' => wp_create_nonce( Listing_Form::NONCE ), 'tdh_fair_housing' => '1' ], $live_two );
ok( 'a stale Submit posted anyway does not unpublish it', 'publish' === get_post_status( $live_two ) && str_contains( $where, 'view=listings' ) );

if ( $admin ) {
	wp_set_current_user( $admin->ID );
	$where = $post_form( $basics( $live_two, [ 'tdh_title' => 'Actions probe live two, fixed' ] ), $live_two );
	ok( 'a staff title change publishes at once — no confirmation, status kept', 'publish' === get_post_status( $live_two ) && 'Actions probe live two, fixed' === get_the_title( $live_two ) && ! str_contains( $where, 'confirm=1' ), $where );
	wp_set_current_user( $owner );
}

$where = $post_form( [ 'tdh_action' => 'listing_features', 'tdh_nonce' => wp_create_nonce( Listing_Form::NONCE ), 'tdh_utilities_included' => 'partial', 'tdh_pets' => 'no', 'tdh_parking' => 'Driveway' ], $live_two );
ok( 'a features change on a live home saves at once', 'publish' === get_post_status( $live_two ) && 'no' === get_post_meta( $live_two, '_tdh_pet_policy', true ) && str_contains( $where, 'updated=1' ), $where );

$live_photos = $home( 'publish', 'Actions probe live photos' );
$pa          = (int) wp_insert_attachment( [ 'post_title' => 'a', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ], '', $live_photos );
$pb          = (int) wp_insert_attachment( [ 'post_title' => 'b', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ], '', $live_photos );
TDH\Listing_Photos::save_order( $live_photos, [ $pa, $pb ] );

$_GET = [ 'step' => '3', 'listing' => (string) $live_photos ];
ok( 'the photo step of a live home carries the review tick', str_contains( TDH\Listing_Form_Render::form(), 'data-tdh-unlock' ) );

$where = $post_form( [ 'tdh_action' => 'listing_photos', 'tdh_nonce' => wp_create_nonce( Listing_Form::NONCE ), 'tdh_description' => '', 'tdh_photo_move' => $pa . ':down' ], $live_photos );
$held  = Listing_Form::held_confirmation();
ok( 'arranging photos without the tick is held for confirmation, naming Photos', [ $pa, $pb ] === TDH\Listing_Photos::ordered( $live_photos ) && str_contains( $where, 'confirm=1' ) && in_array( 'Photos', (array) ( $held['changes'] ?? [] ), true ), $where );

$where = $post_form( [ 'tdh_action' => 'listing_photos', 'tdh_nonce' => wp_create_nonce( Listing_Form::NONCE ), 'tdh_description' => '', 'tdh_photo_move' => $pa . ':down', 'tdh_confirm_review' => '1' ], $live_photos );
ok( 'with the tick, the new order is saved and the home goes to review', [ $pb, $pa ] === TDH\Listing_Photos::ordered( $live_photos ) && 'pending' === get_post_status( $live_photos ) && str_contains( $where, 'tdh_done=in_review' ), $where );

$live_up = $home( 'publish', 'Actions probe live upload' );
$dir     = wp_upload_dir()['basedir'] . '/tdh-actions-probe';
wp_mkdir_p( $dir );
file_put_contents( "$dir/a.png", base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
$_FILES['tdh_photos'] = [ 'name' => [ 'a.png' ], 'type' => [ 'image/png' ], 'tmp_name' => [ "$dir/a.png" ], 'error' => [ 0 ], 'size' => [ filesize( "$dir/a.png" ) ] ];
$_GET  = [ 'listing' => (string) $live_up ];
$_POST = [ 'tdh_action' => 'listing_photos', 'tdh_nonce' => wp_create_nonce( Listing_Form::NONCE ), 'tdh_description' => 'Typed while uploading' ];
$where = redirect_of( [ new Listing_Form(), 'handle' ] );
$_POST  = [];
$_FILES = [];
ok( 'adding photos without the tick is refused — nothing stored, home still live', [] === TDH\Listing_Photos::ordered( $live_up ) && 'publish' === get_post_status( $live_up ) && '' === (string) get_post( $live_up )->post_content && str_contains( $where, 'step=3' ), $where );
ok( '...with the way to do it named', str_contains( implode( ' ', Listing_Form::take_errors() ), 'Tick the box' ) );
ok( '...and the typed description kept — for another home, nothing', [] === Listing_Form::take_values( $live_two ) && 'Typed while uploading' === ( Listing_Form::take_values( $live_up )['tdh_description'] ?? '' ) );
unlink( "$dir/a.png" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions

$paused_edit = $home( 'tdh_paused', 'Actions probe paused edit' );
$where       = $post_form( $basics( $paused_edit, [ 'tdh_title' => 'Actions probe paused renamed' ] ), $paused_edit );
ok( 'a paused home takes the change and stays paused', 'Actions probe paused renamed' === get_the_title( $paused_edit ) && Statuses::PAUSED === get_post_status( $paused_edit ) && str_contains( (string) get_post_meta( $paused_edit, '_tdh_edited_while_paused', true ), 'Title' ) && str_contains( $where, 'updated=1' ), $where );
$_GET = [ 'view' => 'listings' ];
ok( 'the row warns that Resume goes through review first', str_contains( Account_Render::dashboard(), 'You changed the title while it was paused, so Resume sends it for a quick review' ) );
$_GET  = [];
$sent  = count( $mail2 );
$where = act( $actions, 'listing_resume', $paused_edit );
ok( 'Resume then sends it to review instead of straight live', 'pending' === get_post_status( $paused_edit ) && str_contains( $where, 'tdh_done=resume_review' ) && str_contains( (string) get_post_meta( $paused_edit, '_tdh_reviewing_edits', true ), 'Title' ) && '' === (string) get_post_meta( $paused_edit, '_tdh_edited_while_paused', true ), $where );
ok( '...and the notice says why it is not live yet', str_contains( (string) ( Listing_Actions::notice( 'resume_review', $paused_edit )['text'] ?? '' ), 'isn’t live yet' ) );
ok( '...and staff hear it was paused, not live', count( $mail2 ) === $sent + 1 && str_contains( (string) ( $mail2[ $sent ]['message'] ?? '' ), 'while it was paused' ) && ! str_contains( (string) ( $mail2[ $sent ]['message'] ?? '' ), 'which was live' ) );

echo "\n=== A4 review findings: what decides is what was stored ===\n";

// A form posted without the bedrooms field would have saved 0 bedrooms live.
$live_raw  = $home( 'publish', 'Actions probe missing field' );
$crafted   = $basics( $live_raw, [ 'tdh_rent' => '2300' ] );
unset( $crafted['tdh_beds'] );
$where     = $post_form( $crafted, $live_raw );
ok( 'a form missing the bedrooms field is asked about, not saved live', 2 === (int) get_post_meta( $live_raw, '_tdh_beds', true ) && 'publish' === get_post_status( $live_raw ) && str_contains( $where, 'confirm=1' ), $where );
Listing_Form::discard_confirmation();

// A material change that reaches the save without the confirmation still goes to review.
$sneaky = $home( 'publish', 'Actions probe unconfirmed' );
// Simulate a question that misses the change: bedrooms are hidden from the
// confirmation check only, not from the before/after comparison.
add_filter(
	'tdh_material_fields',
	$hide_beds = static function ( array $f ): array {
		$asking = in_array( 'material_changes', array_column( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), 'function' ), true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		return $asking ? array_diff_key( $f, [ '_tdh_beds' => 1 ] ) : $f;
	}
);
$where = $post_form( $basics( $sneaky, [ 'tdh_beds' => '5' ] ), $sneaky );
remove_filter( 'tdh_material_fields', $hide_beds );
ok( 'if a material change is saved without confirmation anyway, the home still goes to review', 5 === (int) get_post_meta( $sneaky, '_tdh_beds', true ) && 'pending' === get_post_status( $sneaky ), $where . ' / ' . get_post_status( $sneaky ) );

// A title with "&" is stored as "&amp;"; it must not read as a change every time.
$amp = $home( 'publish', 'Actions probe' );
wp_update_post( [ 'ID' => $amp, 'post_title' => 'Bed & Breakfast probe' ] );
$where = $post_form( $basics( $amp, [ 'tdh_title' => 'Bed & Breakfast probe', 'tdh_rent' => '2250' ] ), $amp );
ok( 'a title with "&" is not treated as changed — the rent saves live', 'publish' === get_post_status( $amp ) && 2250.0 === (float) get_post_meta( $amp, '_tdh_price_monthly', true ) && ! str_contains( $where, 'confirm=1' ), $where . ' / stored: ' . get_post_field( 'post_title', $amp ) );

// A description written in the block editor, posted back untouched.
$blocks = $home( 'publish', 'Actions probe block description' );
$markup = "<!-- wp:paragraph -->\n<p>Bright loft near the hospital.</p>\n<!-- /wp:paragraph -->";
global $wpdb;
$wpdb->update( $wpdb->posts, [ 'post_content' => $markup ], [ 'ID' => $blocks ] );
clean_post_cache( $blocks );
$where = $post_form( [ 'tdh_action' => 'listing_photos', 'tdh_nonce' => wp_create_nonce( Listing_Form::NONCE ), 'tdh_description' => $markup ], $blocks );
ok( 'an untouched block-editor description is not a change, and its markup is kept', 'publish' === get_post_status( $blocks ) && $markup === (string) get_post_field( 'post_content', $blocks ) && ! str_contains( $where, 'confirm=1' ), $where );

// A refused upload with the tick must not take an unchanged live home offline.
$dir2 = wp_upload_dir()['basedir'] . '/tdh-actions-probe-2';
wp_mkdir_p( $dir2 );
file_put_contents( "$dir2/bad.jpg", 'not a photo' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
$refuse = static function ( int $id, array $extra = [] ) use ( $dir2 ): string {
	$_FILES['tdh_photos'] = [ 'name' => [ 'bad.jpg' ], 'type' => [ 'image/jpeg' ], 'tmp_name' => [ "$dir2/bad.jpg" ], 'error' => [ 0 ], 'size' => [ filesize( "$dir2/bad.jpg" ) ] ];
	$_GET  = [ 'listing' => (string) $id ];
	$_POST = array_merge( [ 'tdh_action' => 'listing_photos', 'tdh_nonce' => wp_create_nonce( Listing_Form::NONCE ), 'tdh_description' => '' ], $extra );
	$where = redirect_of( [ new Listing_Form(), 'handle' ] );
	$_POST  = [];
	$_FILES = [];
	Listing_Form::take_errors();
	return $where;
};
$live_bad = $home( 'publish', 'Actions probe refused upload' );
$sent     = count( $mail2 );
$where    = $refuse( $live_bad, [ 'tdh_confirm_review' => '1' ] );
ok( 'a refused upload with the tick leaves an unchanged live home live, and nobody is emailed', 'publish' === get_post_status( $live_bad ) && '' === (string) get_post_meta( $live_bad, '_tdh_reviewing_edits', true ) && count( $mail2 ) === $sent, $where );

$paused_bad = $home( 'tdh_paused', 'Actions probe paused refused' );
$refuse( $paused_bad );
ok( 'a refused upload on a paused home records nothing for Resume to review', '' === (string) get_post_meta( $paused_bad, '_tdh_edited_while_paused', true ) );
unlink( "$dir2/bad.jpg" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
rmdir( $dir2 ); // phpcs:ignore WordPress.WP.AlternativeFunctions

$paused_invalid = $home( 'tdh_paused', 'Actions probe paused invalid' );
$post_form( $basics( $paused_invalid, [ 'tdh_title' => 'Actions probe never saved', 'tdh_zip' => 'next week' ] ), $paused_invalid );
Listing_Form::take_errors();
ok( 'a paused home\'s title change that fails validation is neither saved nor remembered', 'Actions probe paused invalid' === get_the_title( $paused_invalid ) && '' === (string) get_post_meta( $paused_invalid, '_tdh_edited_while_paused', true ) );

// A step that is about to be refused is not sent for confirmation.
$live_invalid = $home( 'publish', 'Actions probe live invalid' );
$where        = $post_form( $basics( $live_invalid, [ 'tdh_title' => 'Actions probe renamed invalid', 'tdh_rent' => '' ] ), $live_invalid );
ok( 'a title change with the rent left blank gets the error, not a confirmation', ! str_contains( $where, 'confirm=1' ) && str_contains( implode( ' ', Listing_Form::take_errors() ), 'monthly rent' ) && 'publish' === get_post_status( $live_invalid ) );

// A home with no city gets the only city preselected; that is filling it in, not a change.
$no_city = $home( 'publish', 'Actions probe no city' );
wp_delete_object_term_relationships( $no_city, 'tdh_city' );
if ( $city ) {
	$where = $post_form( $basics( $no_city, [ 'tdh_city' => (string) $city[0], 'tdh_rent' => '1999' ] ), $no_city );
	ok( 'a preselected city on a home that had none does not force a review', 'publish' === get_post_status( $no_city ) && 1999.0 === (float) get_post_meta( $no_city, '_tdh_price_monthly', true ) && ! str_contains( $where, 'confirm=1' ), $where );
}

if ( $admin ) {
	wp_set_current_user( $admin->ID );
	$_GET = [ 'step' => '4', 'listing' => (string) $live_two ];
	$page = TDH\Listing_Form_Render::form();
	ok( 'staff still get the ordinary step 4, with Submit, on a live home', str_contains( $page, 'Submit for approval' ) && ! str_contains( $page, 'Everything is saved' ) );

	$reviewed = $home( 'pending', 'Actions probe request after edit' );
	update_post_meta( $reviewed, '_tdh_reviewing_edits', 'Title' );
	$_POST = [ 'tdh_action' => 'listing_changes', 'tdh_listing' => (string) $reviewed, 'tdh_nonce' => wp_create_nonce( Moderation::NONCE ), 'tdh_reason' => 'Please add photos.' ];
	redirect_of( [ new Moderation(), 'handle' ] );
	$_POST = [];
	ok( 'Request changes forgets the old edit, so a resubmission reads as one', '' === (string) get_post_meta( $reviewed, '_tdh_reviewing_edits', true ) );
	wp_set_current_user( $owner );
}

// A refused arrow keeps the landlord on the photo step (a regression A4 introduced).
$draft_photos = $home( 'draft', 'Actions probe draft arrow' );
$only         = (int) wp_insert_attachment( [ 'post_title' => 'only', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ], '', $draft_photos );
TDH\Listing_Photos::save_order( $draft_photos, [ $only ] );
$where = $post_form( [ 'tdh_action' => 'listing_photos', 'tdh_nonce' => wp_create_nonce( Listing_Form::NONCE ), 'tdh_description' => '', 'tdh_photo_move' => $only . ':down' ], $draft_photos );
ok( 'an arrow that cannot move keeps the landlord on the photo step', str_contains( $where, 'step=3' ) && str_contains( $where, 'arranged=1' ), $where );

ok( 'field lists read as a sentence', 'the title, photos and ZIP code' === 'the ' . Listing_Form::changes_phrase( 'Title, Photos, ZIP code' ) );

remove_filter( 'pre_wp_mail', $trap2, 1 );
$_GET  = [];
$_POST = [];

echo "\n=== the review personas ===\n";

$persona = $make_user( 'tdh_actions_persona', 'tdh_landlord' );
TDH\Demo\Demo_Mode::ensure_membership( $persona, 'landlord' );
ok( '"Active landlord" really has an active plan, so Resume is offered', Membership::ACTIVE === Membership::status( $persona ) && Listing_Actions::owner_can_publish( $persona ) );

update_user_meta( $persona, Membership::META_STATUS, Membership::PAST_DUE );
TDH\Demo\Demo_Mode::ensure_membership( $persona, 'landlord' );
ok( '...but a membership a reviewer changed on purpose is left alone', Membership::PAST_DUE === Membership::status( $persona ) );

$new_persona = $make_user( 'tdh_actions_persona_new', 'tdh_landlord' );
TDH\Demo\Demo_Mode::ensure_membership( $new_persona, 'new' );
ok( '"New landlord" still has no plan', Membership::NONE === Membership::status( $new_persona ) );

$failed_persona = $make_user( 'tdh_actions_persona_failed', 'tdh_landlord' );
TDH\Demo\Demo_Mode::ensure_membership( $failed_persona, 'failed' );
ok( '"Failed payment" really is past due, inside its grace period (E1)', Membership::PAST_DUE === Membership::status( $failed_persona ) && TDH\Enforcement::GRACE === TDH\Enforcement::state( $failed_persona ) );
$owner_list = [ $persona, $new_persona, $failed_persona ];

/* Fixtures away. */
wp_set_current_user( 0 );
$_GET  = [];
$_POST = [];
foreach ( $created as $id ) {
	foreach ( get_children( [ 'post_parent' => $id, 'post_type' => 'attachment' ] ) as $att ) {
		wp_delete_attachment( $att->ID, true );
	}
	wp_delete_post( $id, true );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array_merge( [ $owner, $stranger ], $owner_list ) as $uid ) {
	if ( $uid ) {
		wp_delete_user( $uid );
	}
}
echo "\n  fixtures removed\n";

[ $p, $f ] = ok();
printf( "\n%s  %d passed, %d failed\n\n", $f ? 'FAILED' : 'PASSED', $p, $f );

if ( $f ) {
	WP_CLI::halt( 1 );
}
