<?php
/**
 * Email verification at sign-up (F1) — verification suite.
 *
 * Signs up through the real registration handler, reads the link out of the
 * captured email (no mail leaves the machine: pre_wp_mail is trapped), and
 * follows it signed out, twice, expired, forged and superseded. Every user,
 * home and log line it makes is removed at the end.
 *
 * @package ThirtyDayHomes
 */

use TDH\Account_Render;
use TDH\Accounts;
use TDH\Billing\Checkout;
use TDH\Email_Verification as EV;
use TDH\Listing_Form;
use TDH\Membership;
use TDH\Post_Types;

function ok( string $label = '', bool $cond = false, string $detail = '' ): array {
	static $p = 0, $f = 0;
	if ( '' === $label ) { return [ $p, $f ]; }
	if ( $cond ) { ++$p; printf( "  ok    %s\n", $label ); }
	else { ++$f; printf( "  FAIL  %s%s\n", $label, '' !== $detail ? "  --> {$detail}" : '' ); }
	return [ $p, $f ];
}

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

global $wpdb;
require_once ABSPATH . 'wp-admin/includes/user.php';

$log_table = TDH\Log::table();
$log_floor = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$log_table}" ); // phpcs:ignore WordPress.DB
$log_was   = getenv( 'TDH_LOG_SILENT' );
putenv( 'TDH_LOG_SILENT' );

// No cookies from the command line, and no real email.
add_filter( 'send_auth_cookies', '__return_false' );
$mail = [];
$trap = static function ( $short, $atts ) use ( &$mail ) { $mail[] = $atts; return true; };
add_filter( 'pre_wp_mail', $trap, 1, 2 );
$link_in = static function ( array $m ): string {
	return preg_match( '#(https?://\S+tdh_verify=[0-9]+\.[A-Za-z0-9]+)#', (string) ( $m['message'] ?? '' ), $x ) ? $x[1] : '';
};
$ours = static fn( array $all ): array => array_values( array_filter( $all, static fn( $m ) => 'Confirm your email for ThirtyDayHomes' === ( $m['subject'] ?? '' ) ) );
$arg_of = static fn( string $link ): string => (string) ( wp_parse_args( (string) wp_parse_url( $link, PHP_URL_QUERY ) )[ EV::ARG ] ?? '' );

$accounts = new Accounts();
$ev       = new EV();
$made     = [];

foreach ( [ 'f1-probe@example.com', 'f1-probe-new@example.com', 'f1-probe-staffmade@example.com', 'f1-probe-short@example.com', 'f1-probe-eight@example.com' ] as $stale ) {
	$u = get_user_by( 'email', $stale );
	if ( $u ) { wp_delete_user( (int) $u->ID ); }
}

echo "\n=== signing up ===\n";

wp_set_current_user( 0 );
$_POST = [ 'tdh_action' => 'register', 'tdh_nonce' => wp_create_nonce( 'tdh_register' ), 'tdh_name' => 'Probe Landlord', 'tdh_email' => 'f1-probe@example.com', 'tdh_password' => 'correct horse battery 9', 'tdh_terms' => '1' ];
$where = landed( [ $accounts, 'handle_forms' ] );
$_POST = [];
$user  = get_user_by( 'email', 'f1-probe@example.com' );
$uid   = $user ? (int) $user->ID : 0;
$note  = Accounts::take_notice();
$mail  = $ours( $mail );

ok( 'a new account is created and lands on the dashboard', $uid > 0 && str_contains( $where, '/account/' ), $where );
ok( '...not yet confirmed', ! EV::is_verified( $uid ) && 'f1-probe@example.com' === EV::pending( $uid ) );
ok( 'one email goes to the address typed, with the link in it', 1 === count( $mail ) && 'f1-probe@example.com' === ( is_array( $mail[0]['to'] ) ? $mail[0]['to'][0] : $mail[0]['to'] ) && '' !== $link_in( $mail[0] ) );
ok( '...its subject says what it is', 'Confirm your email for ThirtyDayHomes' === ( $mail[0]['subject'] ?? '' ) );
ok( '...and it says the link lasts 24 hours and works once', str_contains( (string) ( $mail[0]['message'] ?? '' ), 'works once and for 24 hours' ) );
ok( 'the welcome says one step is left and names the address', str_contains( $note['success'], 'click the link we sent to f1-probe@example.com' ), $note['success'] );
ok( 'only a hash of the token is stored', '' !== (string) get_user_meta( $uid, EV::META_TOKEN, true ) && ! str_contains( $link_in( $mail[0] ), (string) get_user_meta( $uid, EV::META_TOKEN, true ) ) );
$first_link = $link_in( $mail[0] );

echo "\n=== the shortest password (8, the client's choice) ===\n";

$sign_up = static function ( string $email, string $pass ) use ( $accounts ): array {
	wp_set_current_user( 0 );
	$_POST = [ 'tdh_action' => 'register', 'tdh_nonce' => wp_create_nonce( 'tdh_register' ), 'tdh_name' => 'Probe Landlord', 'tdh_email' => $email, 'tdh_password' => $pass, 'tdh_terms' => '1' ];
	landed( [ $accounts, 'handle_forms' ] );
	$_POST = [];
	$u = get_user_by( 'email', $email );
	return [ $u ? (int) $u->ID : 0, Accounts::take_notice() ];
};
ok( 'the minimum is 8', 8 === Accounts::MIN_PASSWORD );
[ $short_id, $short_note ] = $sign_up( 'f1-probe-short@example.com', 'Abcd123' );
ok( 'a 7-character password is refused, no account made', 0 === $short_id );
ok( '...and the reason says 8 characters', in_array( 'Passwords must be at least 8 characters.', $short_note['errors'], true ), implode( ' | ', $short_note['errors'] ) );
[ $eight_id ] = $sign_up( 'f1-probe-eight@example.com', 'Abcd1234' );
ok( 'an 8-character password is accepted', $eight_id > 0 );
if ( $eight_id ) { wp_delete_user( $eight_id ); }
$mail = [];

echo "\n=== what the landlord sees ===\n";

wp_set_current_user( $uid );
foreach ( [ 'overview', 'listings', 'profile' ] as $view ) {
	$_GET = [ 'view' => $view ];
	$page = Account_Render::dashboard();
	ok( "the band is on the {$view} view", str_contains( $page, 'id="verify-email"' ) && str_contains( $page, 'Confirm your email address' ) );
}
$_GET = [];
ok( '...naming the address and what waits on it', str_contains( $page, 'We sent a link to f1-probe@example.com' ) && str_contains( $page, 'can’t start a plan or submit a home' ) );
ok( '...with one button, Send a new link, carrying its nonce', str_contains( $page, '<button type="submit">Send a new link</button>' ) && str_contains( $page, 'value="verify_resend"' ) && str_contains( $page, 'name="tdh_nonce"' ) );
ok( '...and says where to fix a wrong address', str_contains( $page, 'Change it in Account details' ) );

echo "\n=== what waits on it ===\n";

$_POST = [ 'tdh_action' => 'tdh_checkout', '_wpnonce' => wp_create_nonce( 'tdh_start_checkout' ), 'tdh_listings' => '1' ];
$where = landed( [ new Checkout(), 'maybe_start' ] );
$_POST = [];
ok( 'starting a plan is refused, back to pricing with the reason', str_contains( $where, 'tdh_checkout_error=verify_email' ), $where );
ok( '...and the reason is a sentence', str_contains( Checkout::messages()['verify_email'], 'Confirm your email address first' ) );

Membership::apply( $uid, [ 'status' => Membership::ACTIVE, 'quota' => 3, 'expires' => strtotime( '+30 days' ) ] );
$city  = get_term_by( 'slug', 'pittsburgh', 'tdh_city' );
$draft = (int) wp_insert_post( [ 'post_type' => Post_Types::LISTING, 'post_status' => 'draft', 'post_title' => 'F1 probe draft', 'post_author' => $uid, 'post_content' => 'A probe home.' ] );
$made[] = $draft;
foreach ( [ '_tdh_price_monthly' => 2000, '_tdh_street_address' => '9 Probe Way', '_tdh_zip' => '15232', '_tdh_utilities_included' => 'yes', '_tdh_pet_policy' => 'no', '_tdh_contact_method' => 'email', '_tdh_beds' => 1, '_tdh_baths' => 1, '_tdh_lat' => 40.45, '_tdh_lng' => -79.93, '_tdh_geocode_status' => 'manual' ] as $k => $v ) {
	update_post_meta( $draft, $k, $v );
}
if ( $city ) { wp_set_object_terms( $draft, [ (int) $city->term_id ], 'tdh_city' ); }
$submit = static function () use ( $draft ): string {
	$_GET  = [ 'listing' => (string) $draft, 'step' => '4' ];
	$_POST = [ 'tdh_action' => 'listing_submit', 'tdh_nonce' => wp_create_nonce( Listing_Form::NONCE ), 'tdh_fair_housing' => '1' ];
	landed( [ new Listing_Form(), 'handle' ] );
	$_POST = [];
	$_GET  = [];
	return implode( ' ', Listing_Form::take_errors() );
};
$errs = $submit();
ok( 'even with a plan, submitting a home is refused and the draft is kept', 'draft' === get_post_status( $draft ) && str_contains( $errs, 'Confirm your email address first' ), $errs );

echo "\n=== Send a new link ===\n";

ok( 'pressing it straight away is refused: a link went less than a minute ago', str_contains( EV::resend_block( $uid ), 'less than a minute ago' ) );
update_user_meta( $uid, EV::META_SENDS, [ time() - 120 ] );
$mail  = [];
$_POST = [ 'tdh_action' => 'verify_resend', 'tdh_nonce' => wp_create_nonce( EV::NONCE ) ];
$where = landed( [ $ev, 'maybe_resend' ] );
$_POST = [];
$note  = Accounts::take_notice();
ok( 'after a minute it sends a fresh link and says where', 1 === count( $mail ) && str_contains( $note['success'], 'A new link is on its way to f1-probe@example.com' ), $note['success'] );
$second_link = $link_in( $mail[0] ?? [] );
ok( '...and the first link no longer works', 'invalid' === EV::follow( $arg_of( $first_link ) )[0] );

$_POST = [ 'tdh_action' => 'verify_resend', 'tdh_nonce' => 'stale' ];
landed( [ $ev, 'maybe_resend' ] );
$_POST = [];
ok( 'an expired page is refused and says so', str_contains( implode( ' ', Accounts::take_notice()['errors'] ), 'That page expired' ) );

update_user_meta( $uid, EV::META_SENDS, array_fill( 0, 5, time() - 600 ) );
ok( 'five links in an hour is the limit, said plainly', str_contains( EV::resend_block( $uid ), 'That is 5 links in an hour' ) );
update_user_meta( $uid, EV::META_SENDS, [ time() - 600 ] );

echo "\n=== following the link ===\n";

ok( 'a forged token is refused', 'invalid' === EV::follow( $uid . '.notTheRealToken123' )[0] );
ok( 'a user who does not exist reads exactly the same', [ 'invalid', 0 ] === EV::follow( '999999999.' . substr( $arg_of( $second_link ), strpos( $arg_of( $second_link ), '.' ) + 1 ) ) );
ok( 'rubbish reads the same', [ 'invalid', 0 ] === EV::follow( 'abc' ) );

$keep = (int) get_user_meta( $uid, EV::META_EXPIRES, true );
update_user_meta( $uid, EV::META_EXPIRES, time() - 1 );
wp_set_current_user( 0 );
$_GET  = [ EV::ARG => $arg_of( $second_link ) ];
$where = landed( [ $ev, 'maybe_follow_link' ] );
$_GET  = [];
ok( 'an expired link signs nobody in and sends them to sign in', 0 === get_current_user_id() && str_contains( $where, '/login/' ), $where );
ok( '...still unconfirmed', ! EV::is_verified( $uid ) );
update_user_meta( $uid, EV::META_EXPIRES, $keep );

$_GET  = [ EV::ARG => $arg_of( $second_link ) ];
$where = landed( [ $ev, 'maybe_follow_link' ] );
$_GET  = [];
$note  = Accounts::take_notice();
ok( 'the newest link, signed out, confirms the address', EV::is_verified( $uid ) && '' === EV::pending( $uid ) );
ok( '...records when', (int) get_user_meta( $uid, EV::META_VERIFIED, true ) > time() - 60 );
ok( '...signs them in and lands on the dashboard saying so', $uid === get_current_user_id() && str_contains( $where, '/account/' ) && str_contains( $note['success'], 'Email confirmed' ), $where . ' / ' . $note['success'] );

$_GET = [ 'view' => 'overview' ];
ok( 'the band is gone', ! str_contains( Account_Render::dashboard(), 'id="verify-email"' ) );
$_GET = [];

ok( 'clicking it again says already confirmed', 'already' === EV::follow( $arg_of( $second_link ) )[0] );
wp_set_current_user( 0 );
$_GET  = [ EV::ARG => $arg_of( $second_link ) ];
$where = landed( [ $ev, 'maybe_follow_link' ] );
$_GET  = [];
ok( '...and a spent link signs nobody in', 0 === get_current_user_id() && str_contains( $where, '/login/' ) && str_contains( Accounts::take_notice()['success'], 'already confirmed' ) );
$someone = (int) wp_insert_user( [ 'user_login' => 'tdh_f1_someone', 'user_email' => 'f1-probe-someone@example.com', 'user_pass' => wp_generate_password( 20 ), 'role' => 'tdh_landlord' ] );
wp_set_current_user( $someone );
$_GET = [ EV::ARG => $arg_of( $second_link ) ];
landed( [ $ev, 'maybe_follow_link' ] );
$_GET = [];
ok( 'someone else signed in is told "that" address is confirmed, not "your"', str_contains( Accounts::take_notice()['success'], 'That email address is already confirmed' ) );
wp_delete_user( $someone );
wp_set_current_user( 0 );
ok( 'a forged token on a confirmed account does not reveal that it is confirmed', 'invalid' === EV::follow( $uid . '.WrongWrongWrong000' )[0] );

wp_set_current_user( $uid );
$_POST = [ 'tdh_action' => 'tdh_checkout', '_wpnonce' => wp_create_nonce( 'tdh_start_checkout' ), 'tdh_listings' => '1' ];
$where = landed( [ new Checkout(), 'maybe_start' ] );
$_POST = [];
ok( 'confirmed, the email check no longer stops a plan', ! str_contains( $where, 'verify_email' ), $where );
$errs = $submit();
ok( '...nor a home going to review', 'pending' === get_post_status( $draft ), $errs );

echo "\n=== changing the address ===\n";

$mail  = [];
$_POST = [ 'tdh_action' => 'profile', 'tdh_nonce' => wp_create_nonce( 'tdh_profile' ), 'tdh_name' => 'Probe Landlord', 'tdh_email' => 'f1-probe-new@example.com', 'tdh_password_current' => 'correct horse battery 9' ];
landed( [ $accounts, 'handle_forms' ] );
$_POST = [];
$note  = Accounts::take_notice();
$mail  = $ours( $mail );
ok( 'a new address in Account details must be confirmed too', ! EV::is_verified( $uid ) && 'f1-probe-new@example.com' === EV::pending( $uid ) );
ok( '...the link goes to the NEW address', 1 === count( $mail ) && 'f1-probe-new@example.com' === ( is_array( $mail[0]['to'] ) ? $mail[0]['to'][0] : $mail[0]['to'] ) );
ok( '...and the saved message says so', str_contains( $note['success'], 'confirm your new address with the link we sent to f1-probe-new@example.com' ), $note['success'] );
$new_link = $link_in( $mail[0] ?? [] );

// Staff correct the address before the landlord clicks.
wp_update_user( [ 'ID' => $uid, 'user_email' => 'f1-probe-staffmade@example.com' ] );
ok( 'a link sent to an address the account no longer has does not work', 'invalid' === EV::follow( $arg_of( $new_link ) )[0] );
update_user_meta( $uid, EV::META_SENDS, [ time() - 600 ] );
$mail  = [];
$_POST = [ 'tdh_action' => 'verify_resend', 'tdh_nonce' => wp_create_nonce( EV::NONCE ) ];
landed( [ $ev, 'maybe_resend' ] );
$_POST = [];
Accounts::take_notice();
ok( '...and Send a new link goes to the address the account has now', 1 === count( $mail ) && 'f1-probe-staffmade@example.com' === ( is_array( $mail[0]['to'] ) ? $mail[0]['to'][0] : $mail[0]['to'] ) );
ok( '...which confirms it', 'confirmed' === EV::follow( $arg_of( $link_in( $mail[0] ?? [] ) ) )[0] && EV::is_verified( $uid ) );

echo "\n=== who is never asked ===\n";

$old = (int) wp_insert_user( [ 'user_login' => 'tdh_f1_old', 'user_email' => 'f1-probe-old@example.com', 'user_pass' => wp_generate_password( 20 ), 'role' => 'tdh_landlord' ] );
ok( 'an account from before this feature counts as confirmed', EV::is_verified( $old ) );
$staff = (int) wp_insert_user( [ 'user_login' => 'tdh_f1_staff', 'user_email' => 'f1-probe-staff@example.com', 'user_pass' => wp_generate_password( 20 ), 'role' => 'administrator' ] );
update_user_meta( $staff, EV::META_PENDING, 'f1-probe-staff@example.com' );
ok( 'staff are never asked, even with a pending address', EV::is_verified( $staff ) && '' === EV::band( $staff ) );
ok( 'starting verification for staff does nothing', false === EV::start( $staff ) );

echo "\n=== the log ===\n";

$events = (array) $wpdb->get_col( $wpdb->prepare( "SELECT event FROM {$log_table} WHERE id > %d", $log_floor ) ); // phpcs:ignore WordPress.DB
foreach ( [ 'verify_sent', 'verified' ] as $event ) {
	ok( "'{$event}' was logged", in_array( $event, $events, true ), implode( ', ', array_unique( $events ) ) );
}

echo "\n=== cleanup ===\n";

remove_filter( 'pre_wp_mail', $trap, 1 );
remove_filter( 'send_auth_cookies', '__return_false' );
foreach ( $made as $id ) { wp_delete_post( $id, true ); }
foreach ( [ $uid, $old, $staff ] as $u ) { if ( $u ) { wp_delete_user( $u ); } }
wp_set_current_user( 0 );
$_GET  = [];
$_POST = [];
$wpdb->query( $wpdb->prepare( "DELETE FROM {$log_table} WHERE id > %d", $log_floor ) ); // phpcs:ignore WordPress.DB
delete_transient( TDH\Log::TRANSIENT_FAILED );
if ( false === $log_was ) { putenv( 'TDH_LOG_SILENT' ); } else { putenv( 'TDH_LOG_SILENT=' . $log_was ); }

ok( 'every fixture is gone', ! get_user_by( 'email', 'f1-probe-staffmade@example.com' ) && ! get_user_by( 'login', 'tdh_f1_old' ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = 'F1 probe draft'" ) ); // phpcs:ignore WordPress.DB
ok( '...and not one line left in the staff log', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log_table} WHERE id > %d", $log_floor ) ) ); // phpcs:ignore WordPress.DB

[ $pass, $fail ] = ok();
printf( "\n%s  %d passed, %d failed\n", $fail ? 'FAILED' : 'PASSED', $pass, $fail );
if ( $fail ) {
	exit( 1 );
}
