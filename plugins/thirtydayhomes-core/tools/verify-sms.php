<?php
/**
 * Text messages to landlords — verification suite.
 *
 * Run it through verify.bat, which finds the binaries for you, or directly:
 *
 *   D:\xampp\php\php.exe D:\xampp\wp-cli.phar eval-file `
 *     "wp-content/plugins/thirtydayhomes-core/tools/verify-sms.php"
 *
 * Safe on a populated site. NOTHING IS TEXTED: a fake provider is hooked
 * in before the first send and every message ends in an array. Every home,
 * user, inquiry, notification row and log line it creates is removed at
 * the end, and the log's row count is checked either side.
 *
 * @package ThirtyDayHomes
 */

use TDH\Accounts;
use TDH\Inquiry;
use TDH\Notifications;
use TDH\Phone;
use TDH\Post_Types;
use TDH\Sms;

/*
 * The feature switch. A runner without it in wp-config.php would otherwise
 * see every check skip for want of a constant, which proves nothing. PHP
 * lets a constant be defined at runtime; register() already ran with it
 * off, so no hook is bound — every method here is called directly, which
 * is what a unit-level suite should do anyway.
 */
if ( ! defined( 'TDH_SMS_ENABLED' ) ) {
	define( 'TDH_SMS_ENABLED', true );
}

/**
 * Record one assertion and hand back the running tally.
 *
 * Static, not global: under `wp eval-file` the script body is not the
 * global scope, so `global $pass` inside a function reaches a different
 * variable entirely and every tally comes out zero.
 *
 * @return array{0:int,1:int} passed, failed
 */
function ok( string $label = '', bool $condition = false, string $detail = '' ): array {

	static $pass = 0;
	static $fail = 0;

	if ( '' === $label ) {
		return [ $pass, $fail ];
	}

	if ( $condition ) {
		++$pass;
		printf( "  ok    %s\n", $label );
	} else {
		++$fail;
		printf( "  FAIL  %s%s\n", $label, '' !== $detail ? "  --> {$detail}" : '' );
	}

	return [ $pass, $fail ];
}

/* -------------------------------------------------------------------------
 * The fake gateway
 * ---------------------------------------------------------------------- */

/**
 * Every "text" ends up in $sent. `$mode` decides whether it "worked".
 */
final class Fake_Sms_Provider implements TDH\Sms\Provider {

	public string $mode = 'accept';

	/** @var array<int,array{to:string,body:string}> */
	public array $sent = [];

	private int $n = 0;

	public function name(): string {
		return 'fake';
	}

	public function send( string $to, string $body ): array {

		$this->sent[] = [
			'to'   => $to,
			'body' => $body,
		];

		if ( 'refuse' === $this->mode ) {
			return [
				'ok'    => false,
				'id'    => '',
				'error' => 'Carrier rejected the message (30007)',
			];
		}

		// What Twilio answers once the recipient has texted STOP to it.
		if ( 'unsubscribed' === $this->mode ) {
			return [
				'ok'    => false,
				'id'    => '',
				'error' => 'Attempt to send to unsubscribed recipient (error 21610)',
			];
		}

		return [
			'ok'    => true,
			'id'    => 'SMfake' . ++$this->n,
			'error' => '',
		];
	}

	public function last(): array {
		return $this->sent ? $this->sent[ count( $this->sent ) - 1 ] : [ 'to' => '', 'body' => '' ];
	}

	public function reset(): void {
		$this->sent = [];
	}
}

$fake = new Fake_Sms_Provider();
add_filter( 'tdh_sms_provider', static fn() => $fake );

/* Test mode, switched by the suite rather than by a constant. */
$test_numbers = [];
add_filter( 'tdh_sms_test_recipients', static function () use ( &$test_numbers ) { return $test_numbers; } );

/* Webhooks: trusted or not, decided by the suite. */
$trust_webhooks = true;
add_filter( 'tdh_sms_webhook_trusted', static function () use ( &$trust_webhooks ) { return $trust_webhooks; } );

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** Run a handler that ends in exit, and report where it redirected. */
function ran( callable $handler ): string {

	$catch = static function ( $location ) {
		throw new RuntimeException( (string) $location );
	};

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

/** The `sms` flag a redirect carried. */
function flag( string $url ): string {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );

	return (string) ( $args[ Sms::PARAM_DONE ] ?? '' );
}

/** Post one of the card's actions as the current user. */
function post_sms( Sms $sms, string $action, array $fields = [] ): string {

	$_POST = array_merge(
		[
			'tdh_action' => $action,
			'tdh_nonce'  => wp_create_nonce( $action ),
		],
		$fields
	);

	$where = ran( static fn() => $sms->handle() );

	$_POST = [];

	return flag( $where );
}

/** The six digits inside the last text the fake received. */
function code_in( string $body ): string {
	return preg_match( '/\b(\d{6})\b/', $body, $m ) ? $m[1] : '';
}

/** The sms row for an inquiry. */
function sms_row( int $inquiry_id ): ?array {

	foreach ( Notifications::for_inquiry( $inquiry_id ) as $row ) {
		if ( Sms::CHANNEL === $row['channel'] ) {
			return $row;
		}
	}

	return null;
}

/** Do what cron would: run the booked send for this row. */
function run_sms_send( int $row_id ): int {

	$ran = 0;

	foreach ( (array) _get_cron_array() as $at => $events ) {

		if ( $at > time() ) {
			continue;
		}

		foreach ( (array) ( $events['tdh_sms_send'] ?? [] ) as $event ) {

			$args = (array) ( $event['args'] ?? [] );

			if ( $row_id !== (int) ( $args[0] ?? 0 ) ) {
				continue;
			}

			wp_unschedule_event( (int) $at, 'tdh_sms_send', $args );
			Sms::send( $row_id );
			++$ran;
		}
	}

	return $ran;
}

function booked_for( int $row_id ): int {
	return (int) wp_next_scheduled( 'tdh_sms_send', [ $row_id ] );
}

function probe_inquiry( int $home, string $email = 'renter@example.com' ): int {

	return Inquiry::store(
		$home,
		$email,
		[
			'name'    => 'Nora Blake',
			'phone'   => '412-555-0186',
			'move_in' => '2026-12-01',
			'stay'    => '30',
			'message' => 'Is there parking?',
		]
	);
}

/** Render the current user's dashboard at a view. */
function screen( string $view, array $extra = [] ): string {
	$_GET = array_merge( [ 'view' => $view ], $extra );
	$html = TDH\Account_Render::dashboard();
	$_GET = [];

	return $html;
}

/* -------------------------------------------------------------------------
 * Set-up, and everything this run must put back
 * ---------------------------------------------------------------------- */

global $wpdb;

$sms       = new Sms();
$log_table = TDH\Log::table();
$log_floor = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$log_table}" ); // phpcs:ignore WordPress.DB
$log_was   = getenv( 'TDH_LOG_SILENT' );

/* Logging on for the whole run, so the log checks mean something; every
   line this run writes is deleted at the end by id. */
putenv( 'TDH_LOG_SILENT' );

require_once ABSPATH . 'wp-admin/includes/user.php';

echo "\n=== fixtures ===\n";

foreach ( [ 'tdh_sms_probe', 'tdh_sms_other', 'tdh_sms_staff' ] as $login ) {
	$stale = get_user_by( 'login', $login );
	if ( $stale ) {
		wp_delete_user( (int) $stale->ID );
	}
}

$every_status = array_merge( array_keys( (array) get_post_stati( [ 'internal' => false ] ) ), [ 'trash' ] );

/* What landlords actually type: an apostrophe, quotation marks, a dash and
   far too many words. */
$wordy_title = 'SMS Probe Landlord\'s "Garden" Flat — Children\'s Hospital Annex, 2 Bedrooms, Fully Furnished, All Utilities Included';

foreach ( [ 'SMS Probe Cottage', $wordy_title ] as $title ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title LIKE %s", Post_Types::INQUIRY, '%' . $wpdb->esc_like( $title ) ) ) as $stale ) {
		foreach ( Notifications::for_inquiry( (int) $stale ) as $row ) {
			$wpdb->delete( Notifications::table(), [ 'id' => (int) $row['id'] ], [ '%d' ] );
		}
		wp_delete_post( (int) $stale, true );
	}
	foreach ( (array) get_posts( [ 'post_type' => Post_Types::LISTING, 'post_status' => $every_status, 'posts_per_page' => -1, 'fields' => 'ids', 'title' => $title ] ) as $stale ) {
		wp_delete_post( (int) $stale, true );
	}
}

$wpdb->query( 'DELETE FROM ' . Notifications::table() . " WHERE inquiry_id NOT IN ( SELECT ID FROM {$wpdb->posts} )" ); // phpcs:ignore WordPress.DB

$enquiries_before = count( (array) get_posts( [ 'post_type' => Post_Types::INQUIRY, 'post_status' => $every_status, 'posts_per_page' => -1, 'fields' => 'ids' ] ) );
$rows_before      = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Notifications::table() ); // phpcs:ignore WordPress.DB

$landlord = (int) wp_insert_user( [ 'user_login' => 'tdh_sms_probe', 'user_email' => 'sms-probe@example.com', 'user_pass' => wp_generate_password( 20 ), 'role' => 'tdh_landlord' ] );
$other    = (int) wp_insert_user( [ 'user_login' => 'tdh_sms_other', 'user_email' => 'sms-other@example.com', 'user_pass' => wp_generate_password( 20 ), 'role' => 'tdh_landlord' ] );
$staff    = (int) wp_insert_user( [ 'user_login' => 'tdh_sms_staff', 'user_email' => 'sms-staff@example.com', 'user_pass' => wp_generate_password( 20 ), 'role' => 'administrator' ] );
$home     = (int) wp_insert_post( [ 'post_type' => Post_Types::LISTING, 'post_status' => 'publish', 'post_title' => 'SMS Probe Cottage', 'post_author' => $landlord ] );

ok( 'a landlord, another landlord, a staff account and a home exist', $landlord > 0 && $other > 0 && $staff > 0 && $home > 0 );

$made = [];

echo "\n=== the switch and the gateway ===\n";

ok( 'texting is switched on for this run', Sms::is_enabled() );
ok( 'the fake gateway is the one in use', Sms::provider() instanceof Fake_Sms_Provider );
ok( '...so nothing stands in the way of a send', '' === Sms::unavailable_reason(), Sms::unavailable_reason() );
ok( 'test mode is off until the suite turns it on', ! Sms::is_test_mode() );

$test_numbers = [ '+14125550100' ];
ok( 'with a test number set, test mode is on', Sms::is_test_mode() );
ok( '...and only that number may be texted', Sms::may_send_to( '+14125550100' ) && ! Sms::may_send_to( '+14125550199' ) );
$test_numbers = [];

echo "\n=== a landlord who never set it up ===\n";

wp_set_current_user( $landlord );

$state = Sms::state_for( $landlord );
ok( 'starts as "Not set up"', 'off' === $state['state'] && 'Not set up' === $state['label'], $state['label'] );
ok( '...and cannot be texted', ! $state['can_text'] );

$made[] = $quiet = probe_inquiry( $home );
$sms->on_inquiry( $quiet, $home );
ok( 'an inquiry writes NO sms row for them — "not texted" is not news', null === sms_row( $quiet ) );
ok( '...and the fake was not asked to send anything', 0 === count( $fake->sent ) );

echo "\n=== the card, before anything is entered ===\n";

$html = screen( 'profile' );
ok( 'the profile shows the Text message alerts card', str_contains( $html, 'Text message alerts' ) );
ok( '...with a mobile number field', str_contains( $html, 'name="tdh_sms_phone"' ) );
ok( '...the consent sentence', str_contains( $html, 'Text me when I receive an inquiry' ) );
ok( '...the legal bits in the same sentence', str_contains( $html, 'message and data rates may apply' ) && str_contains( $html, 'reply STOP to opt out' ) );
/* Within the card. The details form above it has its own primary, which
   is right: they are two forms on one screen, each with one next step. */
$card = preg_match( '/<section class="panel portal-profile portal-sms".*?<\/section>/s', $html, $m ) ? $m[0] : '';
ok( 'the card is its own labelled section', '' !== $card && str_contains( $card, 'aria-labelledby="tdh-sms-title"' ) );
ok( '...with one primary button, and it says Send code', 1 === substr_count( $card, 'class="primary"' ) && str_contains( $card, 'Send code' ), (string) substr_count( $card, 'class="primary"' ) );
ok( '...a real label on the phone field', str_contains( $html, 'for="tdh-sms-phone"' ) );
ok( 'the phone field is no longer in the details grid', ! str_contains( $html, 'id="tdh-p-phone"' ) );
ok( 'the Send code form says "Sending…" while the gateway is asked', str_contains( $card, 'data-sending=' ) && str_contains( $card, 'onsubmit=' ) );

echo "\n=== entering a number ===\n";

$f = post_sms( $sms, Sms::ACTION_START, [ 'tdh_sms_phone' => '+44 20 7946 0958', 'tdh_sms_consent' => '1' ] );
ok( 'a UK number is refused, with the reason', Sms::BAD_NUMBER === $f, $f );

$f = post_sms( $sms, Sms::ACTION_START, [ 'tdh_sms_phone' => '123 456 7890', 'tdh_sms_consent' => '1' ] );
ok( 'a number no US phone can have is refused', Sms::BAD_NUMBER === $f, $f );

$f = post_sms( $sms, Sms::ACTION_START, [ 'tdh_sms_phone' => '(412) 555-0142' ] );
ok( 'a good number without the tick is refused', Sms::NO_CONSENT === $f, $f );
ok( '...and nothing was texted for it', 0 === count( $fake->sent ) );

$f = post_sms( $sms, Sms::ACTION_START, [ 'tdh_sms_phone' => '(412) 555-0142', 'tdh_sms_consent' => '1' ] );
ok( 'number plus tick sends the code', Sms::CODE_SENT === $f, $f );
ok( '...stored as E.164', '+14125550142' === (string) get_user_meta( $landlord, Sms::META_PHONE, true ) );
ok( '...to that number', '+14125550142' === $fake->last()['to'] );

$code = code_in( $fake->last()['body'] );
ok( '...and the text carries a six-digit code', 6 === strlen( $code ), $fake->last()['body'] );
ok( '...with an expiry and STOP in the words', str_contains( $fake->last()['body'], '10 minutes' ) && str_contains( $fake->last()['body'], 'STOP' ) );
ok( 'the code itself is not stored, only a hash', '' !== (string) get_user_meta( $landlord, Sms::META_CODE_HASH, true ) && $code !== (string) get_user_meta( $landlord, Sms::META_CODE_HASH, true ) );

$state = Sms::state_for( $landlord );
ok( 'the state is now pending', 'pending' === $state['state'], $state['state'] );
ok( '...worded as "Code sent — check your phone"', str_contains( $state['label'], 'Code sent' ), $state['label'] );
ok( '...consent is recorded', $state['consented'] );
ok( '...but nothing can be texted until the code is confirmed', ! $state['can_text'] && ! $state['verified'] );

$html = screen( 'profile' );
ok( 'the card now asks for the code', str_contains( $html, 'name="tdh_sms_code"' ) && str_contains( $html, 'Verify' ) );
ok( '...with one-time-code autocomplete so phones offer it', str_contains( $html, 'autocomplete="one-time-code"' ) );
ok( '...names the number it went to', str_contains( $html, '(412) 555-0142' ) );
ok( '...offers a new code and a corrected number', str_contains( $html, 'Send a new code' ) && str_contains( $html, 'name="tdh_sms_phone"' ) );
$card = preg_match( '/<section class="panel portal-profile portal-sms".*?<\/section>/s', $html, $m ) ? $m[0] : '';
ok( '...both of which wait visibly on the gateway; Verify, which asks nobody, does not', 2 === substr_count( $card, 'data-sending=' ) && ! preg_match( '/sms-verify"[^>]*data-sending/', $card ), (string) substr_count( $card, 'data-sending=' ) );

echo "\n=== the code ===\n";

$f = post_sms( $sms, Sms::ACTION_RESEND );
ok( 'asking for another code straight away is told to wait', Sms::WAIT === $f, $f );
ok( '...and no second text went out', 1 === count( $fake->sent ) );

$f = post_sms( $sms, Sms::ACTION_VERIFY, [ 'tdh_sms_code' => '000000' ] );
ok( 'a wrong code is refused', Sms::WRONG_CODE === $f, $f );
ok( '...and counted', 1 === (int) get_user_meta( $landlord, Sms::META_CODE_TRIES, true ) );

$f = post_sms( $sms, Sms::ACTION_VERIFY, [ 'tdh_sms_code' => $code ] );
ok( 'the right code verifies', Sms::VERIFIED === $f, $f );

$state = Sms::state_for( $landlord );
ok( 'the state is now on', 'on' === $state['state'] && 'On' === $state['label'], $state['label'] );
ok( '...verified for exactly this number', '+14125550142' === (string) get_user_meta( $landlord, Sms::META_VERIFIED, true ) );
ok( '...the code is forgotten', '' === (string) get_user_meta( $landlord, Sms::META_CODE_HASH, true ) );
ok( '...and the landlord can be texted', $state['can_text'] );

$f = post_sms( $sms, Sms::ACTION_VERIFY, [ 'tdh_sms_code' => $code ] );
ok( 'Verify pressed again after success just says it is confirmed — not "expired"', Sms::VERIFIED === $f, $f );
ok( '...and the landlord is still on', Sms::state_for( $landlord )['can_text'] );

$html = screen( 'profile' );
ok( 'the card says On, names the number, marks it verified', str_contains( $html, 'is-on' ) && str_contains( $html, '(412) 555-0142' ) && str_contains( $html, 'verified' ) );
ok( '...offers Turn texts off as a secondary action, not primary', str_contains( $html, 'Turn texts off' ) && ! preg_match( '/class="primary"[^>]*>[^<]*Turn texts off/', $html ) );
ok( '...and a way to change the number', str_contains( $html, 'name="tdh_sms_phone"' ) );

echo "\n=== the code, the hard way ===\n";

update_user_meta( $landlord, Sms::META_CODE_SENT, [] );
delete_user_meta( $landlord, Sms::META_VERIFIED );
$fake->reset();

post_sms( $sms, Sms::ACTION_RESEND );
$code2 = code_in( $fake->last()['body'] );

for ( $i = 1; $i <= 4; $i++ ) {
	$f = post_sms( $sms, Sms::ACTION_VERIFY, [ 'tdh_sms_code' => '111111' ] );
}
ok( 'four wrong codes are each refused as wrong', Sms::WRONG_CODE === $f, $f );

$f = post_sms( $sms, Sms::ACTION_VERIFY, [ 'tdh_sms_code' => '111111' ] );
ok( 'the fifth locks it — "too many"', Sms::TOO_MANY === $f, $f );

$f = post_sms( $sms, Sms::ACTION_VERIFY, [ 'tdh_sms_code' => $code2 ] );
ok( '...and even the right code no longer works', Sms::VERIFIED !== $f, $f );

update_user_meta( $landlord, Sms::META_CODE_SENT, [] );
post_sms( $sms, Sms::ACTION_RESEND );
update_user_meta( $landlord, Sms::META_CODE_EXPIRES, time() - 1 );
$f = post_sms( $sms, Sms::ACTION_VERIFY, [ 'tdh_sms_code' => code_in( $fake->last()['body'] ) ] );
ok( 'a code older than ten minutes is expired, however right it is', Sms::CODE_EXPIRED === $f, $f );

update_user_meta( $landlord, Sms::META_CODE_SENT, [ time() - 3000, time() - 2500, time() - 2000, time() - 1500, time() - 1000 ] );
$f = post_sms( $sms, Sms::ACTION_RESEND );
ok( 'a sixth code in an hour is refused', Sms::TOO_MANY === $f, $f );

update_user_meta( $landlord, Sms::META_CODE_SENT, [] );
post_sms( $sms, Sms::ACTION_RESEND );
post_sms( $sms, Sms::ACTION_VERIFY, [ 'tdh_sms_code' => code_in( $fake->last()['body'] ) ] );
ok( 'verified again for the rest of the run', Sms::state_for( $landlord )['verified'] );

echo "\n=== changing the number ===\n";

update_user_meta( $landlord, Sms::META_PHONE, '+14125550185' );
$state = Sms::state_for( $landlord );
ok( 'editing the number anywhere resets the verification', ! $state['verified'] );
ok( '...so texts stop until the new one is confirmed', ! $state['can_text'] );

update_user_meta( $landlord, Sms::META_PHONE, '+14125550142' );
ok( 'putting the old number back does NOT restore it — it was reset', ! Sms::state_for( $landlord )['verified'] );

update_user_meta( $landlord, Sms::META_CODE_SENT, [] );
$fake->reset();
post_sms( $sms, Sms::ACTION_START, [ 'tdh_sms_phone' => '412-555-0142', 'tdh_sms_consent' => '1' ] );
post_sms( $sms, Sms::ACTION_VERIFY, [ 'tdh_sms_code' => code_in( $fake->last()['body'] ) ] );
ok( 'verified once more', Sms::state_for( $landlord )['can_text'] );

$fake->reset();
$f = post_sms( $sms, Sms::ACTION_START, [ 'tdh_sms_phone' => '412-555-0142', 'tdh_sms_consent' => '1' ] );
ok( 'starting again with the SAME verified number sends no code', Sms::VERIFIED === $f && 0 === count( $fake->sent ), $f );

echo "\n=== saving the details form does not lose the number ===\n";

$accounts = new Accounts();
$_POST    = [
	'tdh_action'  => 'profile',
	'tdh_nonce'   => wp_create_nonce( 'tdh_profile' ),
	'tdh_name'    => 'Probe Landlord',
	'tdh_email'   => 'sms-probe@example.com',
	'tdh_company' => 'Probe Co',
];
ran( static fn() => $accounts->handle_forms() );
$_POST = [];

ok( 'the number survives a details save that carries no phone field', '+14125550142' === (string) get_user_meta( $landlord, Sms::META_PHONE, true ), (string) get_user_meta( $landlord, Sms::META_PHONE, true ) );
ok( '...and so does the verification', Sms::state_for( $landlord )['verified'] );

echo "\n=== an inquiry, texted ===\n";

$fake->reset();
$made[] = $texted = probe_inquiry( $home );
$sms->on_inquiry( $texted, $home );

$row = sms_row( $texted );
ok( 'an sms row is written', null !== $row );
ok( '...queued, not sent, on the renter\'s request', $row && Notifications::QUEUED === $row['status'], $row ? $row['status'] : '' );
ok( '...to the verified number', $row && '+14125550142' === $row['recipient'] );
ok( '...and the send is booked', $row && booked_for( (int) $row['id'] ) > 0 );
ok( 'nothing has gone out yet', 0 === count( $fake->sent ) );

ok( 'the booked send runs', 1 === run_sms_send( (int) $row['id'] ) );
$row = sms_row( $texted );
ok( 'the row says sent', Notifications::SENT === $row['status'] );
ok( '...with the provider\'s message id', str_starts_with( (string) $row['provider_message_id'], 'SMfake' ) );
ok( 'exactly one text went out', 1 === count( $fake->sent ) );

$body = $fake->last()['body'];
ok( 'the text is the approved template, word for word', 'New ThirtyDayHomes inquiry for SMS Probe Cottage. View it: ' . add_query_arg( [ 'view' => 'inquiries', 'msg' => (string) $texted ], Accounts::url( 'account' ) ) . ' Reply STOP to opt out.' === $body, $body );
ok( '...with nothing about the renter in it', ! str_contains( $body, 'Nora' ) && ! str_contains( $body, '0186' ) && ! str_contains( $body, 'parking' ) );
ok( '...under 160 characters', strlen( $body ) <= 160, (string) strlen( $body ) );

ok( 'staff see "Texted"', 'Texted' === Sms::state_of( $texted )['label'] );

echo "\n=== the words of the text, with a real-world title ===\n";

/*
 * WordPress's title filter turns the apostrophe and the quotes into HTML
 * entities. Left alone, those would be texted — and emailed — as "&#8217;".
 */
$home2  = (int) wp_insert_post( [ 'post_type' => Post_Types::LISTING, 'post_status' => 'publish', 'post_title' => $wordy_title, 'post_author' => $landlord ] );
$made[] = $wordy = probe_inquiry( $home2 );

$about = Inquiry::about( $wordy );
ok( 'the home\'s name comes back as text, not HTML', ! str_contains( $about['name'], '&#' ) && str_contains( $about['name'], 'Landlord' ), $about['name'] );
ok( '...and so does the email subject', ! str_contains( Notifications::subject( $wordy ), '&#' ), Notifications::subject( $wordy ) );

$body = Sms::body( $wordy );
ok( 'the text has plain punctuation — no entities, no curly quotes, no em dash', ! str_contains( $body, '&#' ) && ! str_contains( $body, '’' ) && ! str_contains( $body, '“' ) && ! str_contains( $body, '—' ) && str_contains( $body, "Landlord's" ), $body );
ok( '...is cut to one segment, with an ellipsis', mb_strlen( $body ) <= Sms::SEGMENT && str_contains( $body, '... View it:' ), (string) mb_strlen( $body ) );
ok( '...in the 160-character alphabet, so it IS one segment', strlen( $body ) === mb_strlen( $body ) );
ok( '...and still ends with the link and STOP', str_contains( $body, 'msg=' . $wordy ) && str_ends_with( $body, 'Reply STOP to opt out.' ) );

echo "\n=== an inquiry when the gateway refuses ===\n";

$fake->mode = 'refuse';
$fake->reset();
$made[] = $refused = probe_inquiry( $home );
$sms->on_inquiry( $refused, $home );
$row = sms_row( $refused );
run_sms_send( (int) $row['id'] );

$row = sms_row( $refused );
ok( 'the first refusal is failed, not given up', Notifications::FAILED === $row['status'] );
ok( '...with what the carrier said', str_contains( (string) $row['provider_response'], '30007' ), $row['provider_response'] );
$at = booked_for( (int) $row['id'] );
ok( '...and one retry booked ten minutes out', $at > 0 && abs( ( $at - time() ) - 600 ) <= 5, (string) ( $at - time() ) );
ok( 'staff see "Text failed — trying again"', str_contains( Sms::state_of( $refused )['label'], 'trying again' ) );

Sms::send( (int) $row['id'] );
$row = sms_row( $refused );
ok( 'the second refusal gives up — one retry, as the card says', Notifications::GIVEN_UP === $row['status'] && 2 === (int) $row['attempts'] );
ok( '...with nothing further booked', 0 === booked_for( (int) $row['id'] ) );
ok( 'staff see "Text failed"', 'Text failed' === Sms::state_of( $refused )['label'] );

$before = (int) $row['attempts'];
Sms::send( (int) $row['id'] );
ok( 'a further send on a given-up row changes nothing', $before === (int) sms_row( $refused )['attempts'] );

echo "\n=== the catch-up sweep keeps to the promised waits ===\n";

$made[] = $swept = probe_inquiry( $home );
$sms->on_inquiry( $swept, $home );
run_sms_send( (int) sms_row( $swept )['id'] );
$row = sms_row( $swept );
ok( 'a fresh failure sits at one attempt', Notifications::FAILED === $row['status'] && 1 === (int) $row['attempts'] );

$sms->sweep();
ok( 'the sweep leaves it alone — its retry is ten minutes out, not two', 1 === (int) sms_row( $swept )['attempts'] );

$wpdb->update( Notifications::table(), [ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - Sms::RETRY_DELAY - 1 ) ], [ 'id' => (int) $row['id'] ], [ '%s' ], [ '%d' ] );
$sms->sweep();
$row = sms_row( $swept );
ok( '...and picks it up once the ten minutes have passed', 2 === (int) $row['attempts'] && Notifications::GIVEN_UP === $row['status'], $row['status'] . ' after ' . $row['attempts'] );

$fake->mode = 'accept';

$made[] = $stuck = probe_inquiry( $home );
$sms->on_inquiry( $stuck, $home );
$row = sms_row( $stuck );
wp_clear_scheduled_hook( 'tdh_sms_send', [ (int) $row['id'] ] ); // cron never comes
$sms->sweep();
ok( 'a queued row is not swept in its first two minutes — cron may still be coming', Notifications::QUEUED === sms_row( $stuck )['status'] );

$wpdb->update( Notifications::table(), [ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - Sms::QUEUED_GRACE - 1 ) ], [ 'id' => (int) $row['id'] ], [ '%s' ], [ '%d' ] );
$sms->sweep();
ok( '...and is sent by the sweep after that', Notifications::SENT === sms_row( $stuck )['status'], sms_row( $stuck )['status'] );

echo "\n=== test mode ===\n";

$test_numbers = [ '+14125550100' ];
$fake->reset();
$made[] = $tested = probe_inquiry( $home );
$sms->on_inquiry( $tested, $home );
run_sms_send( (int) sms_row( $tested )['id'] );

$row = sms_row( $tested );
ok( 'in test mode a landlord not on the list is skipped', Sms::SKIPPED === $row['status'], $row['status'] );
ok( '...saying so in words', str_contains( (string) $row['provider_response'], 'Test mode' ), $row['provider_response'] );
ok( '...and the fake was never asked', 0 === count( $fake->sent ) );
ok( 'staff see "Not texted"', 'Not texted' === Sms::state_of( $tested )['label'] );

$test_numbers = [ '+14125550142' ];
$fake->reset();
$made[] = $allowed = probe_inquiry( $home );
$sms->on_inquiry( $allowed, $home );
run_sms_send( (int) sms_row( $allowed )['id'] );
ok( 'a landlord ON the list is texted for real', Notifications::SENT === sms_row( $allowed )['status'] && 1 === count( $fake->sent ) );

$test_numbers = [];

echo "\n=== STOP and START ===\n";

$inbound = static function ( string $from, string $body ) use ( $sms ) {
	$request = new WP_REST_Request( 'POST', '/tdh/v1' . Sms::ROUTE_INBOUND );
	$request->set_body_params( [ 'From' => $from, 'Body' => $body, 'MessageSid' => 'SMin' . wp_rand() ] );

	return $sms->rest_inbound( $request );
};

$r = $inbound( '+14125550142', 'STOP' );
ok( 'STOP is answered with empty TwiML', 200 === $r->get_status() && str_contains( (string) $r->get_data(), '<Response></Response>' ) );
ok( '...and the landlord is paused', Sms::state_for( $landlord )['opted_out'] );
ok( '...worded as paused', str_contains( Sms::state_for( $landlord )['label'], 'Paused' ), Sms::state_for( $landlord )['label'] );

$fake->reset();
$made[] = $paused = probe_inquiry( $home );
$sms->on_inquiry( $paused, $home );
$row = sms_row( $paused );
ok( 'an inquiry while paused is skipped, with the reason', $row && Sms::SKIPPED === $row['status'] && str_contains( (string) $row['provider_response'], 'STOP' ), $row ? $row['provider_response'] : 'no row' );
ok( '...and no send was booked', $row && 0 === booked_for( (int) $row['id'] ) );

$html = screen( 'profile' );
ok( 'the card says texts are paused and how to resume', str_contains( $html, 'is-paused' ) && str_contains( $html, 'START' ) );

$inbound( '+14125550142', 'start' );
ok( 'START (any case) resumes', ! Sms::state_for( $landlord )['opted_out'] && Sms::state_for( $landlord )['can_text'] );

$inbound( '+14125550142', 'Hello, is the flat free?' );
ok( 'any other reply changes nothing', Sms::state_for( $landlord )['can_text'] );

$inbound( '+15555550199', 'STOP' );
ok( 'STOP from a number nobody has is ignored without error', Sms::state_for( $landlord )['can_text'] );

$trust_webhooks = false;
$r = $inbound( '+14125550142', 'STOP' );
ok( 'an unsigned webhook is refused with 403', 403 === $r->get_status() );
ok( '...and changes nothing', Sms::state_for( $landlord )['can_text'] );
$trust_webhooks = true;

echo "\n=== the carrier says the landlord already replied STOP ===\n";

/*
 * Twilio handles STOP at the carrier edge and then refuses our sends with
 * error 21610. If the STOP itself never reached the webhook — not
 * configured, or one lost request — that refusal is the first we hear.
 */
$fake->mode = 'unsubscribed';
$fake->reset();
$made[] = $blocked = probe_inquiry( $home );
$sms->on_inquiry( $blocked, $home );
run_sms_send( (int) sms_row( $blocked )['id'] );
$row = sms_row( $blocked );
ok( 'a 21610 refusal is recorded as "not texted: STOP", not as a failure to retry', Sms::SKIPPED === $row['status'] && str_contains( (string) $row['provider_response'], 'STOP' ), $row['status'] . ': ' . $row['provider_response'] );
ok( '...nothing further is booked', 0 === booked_for( (int) $row['id'] ) );
ok( '...and the landlord is now paused', Sms::state_for( $landlord )['opted_out'] );
$fake->mode = 'accept';

$fake->reset();
$f = post_sms( $sms, Sms::ACTION_START, [ 'tdh_sms_phone' => '(412) 555-0142', 'tdh_sms_consent' => '1' ] );
ok( 'setting up the SAME number again is refused — only START lifts the carrier\'s block', Sms::STILL_PAUSED === $f && Sms::state_for( $landlord )['opted_out'], $f );
ok( '...and no code was sent', 0 === count( $fake->sent ) );

update_user_meta( $landlord, Sms::META_CODE_SENT, [] );
$f = post_sms( $sms, Sms::ACTION_START, [ 'tdh_sms_phone' => '(412) 555-0190', 'tdh_sms_consent' => '1' ] );
ok( 'a DIFFERENT number was never blocked: the pause lifts and a code goes out', Sms::CODE_SENT === $f && ! Sms::state_for( $landlord )['opted_out'], $f );
$html = screen( 'profile' );
ok( '...and the card no longer calls it paused', ! str_contains( $html, 'is-paused' ) );

/* Back on the verified number for the rest of the run. */
update_user_meta( $landlord, Sms::META_PHONE, '+14125550142' );
update_user_meta( $landlord, Sms::META_VERIFIED, '+14125550142' );
update_user_meta( $landlord, Sms::META_CONSENT, '1' );
delete_user_meta( $landlord, Sms::META_CODE_HASH );
delete_user_meta( $landlord, Sms::META_CODE_EXPIRES );
ok( 'restored: on, for the verified number', Sms::state_for( $landlord )['can_text'] );

echo "\n=== the carrier's delivery report ===\n";

$row = sms_row( $texted );
$status_request = new WP_REST_Request( 'POST', '/tdh/v1' . Sms::ROUTE_STATUS );
$status_request->set_body_params( [ 'MessageSid' => (string) $row['provider_message_id'], 'MessageStatus' => 'undelivered', 'ErrorCode' => '30003' ] );
$r = $sms->rest_status( $status_request );
ok( 'a delivery report is acknowledged', 204 === $r->get_status() );
ok( '..."undelivered" turns the sent row into a failure staff can see', Notifications::GIVEN_UP === sms_row( $texted )['status'] );
ok( '...with the carrier\'s error code', str_contains( (string) sms_row( $texted )['provider_response'], '30003' ) );

$status_request->set_body_params( [ 'MessageSid' => 'SMnobody', 'MessageStatus' => 'delivered' ] );
ok( 'a report for a message we never sent is ignored', 204 === $sms->rest_status( $status_request )->get_status() );

$status_lines = static fn(): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log_table} WHERE id > %d AND event = %s", $log_floor, 'sms_status' ) ); // phpcs:ignore WordPress.DB
$n            = $status_lines();
$status_request->set_body_params( [ 'MessageSid' => (string) sms_row( $allowed )['provider_message_id'], 'MessageStatus' => 'sent' ] );
$sms->rest_status( $status_request );
ok( 'Twilio\'s in-between reports (queued, sent) write nothing to the log', $n === $status_lines() );
$status_request->set_body_params( [ 'MessageSid' => (string) sms_row( $allowed )['provider_message_id'], 'MessageStatus' => 'delivered' ] );
$sms->rest_status( $status_request );
ok( '..."delivered" writes one line', $n + 1 === $status_lines() );
ok( '...and leaves the row sent', Notifications::SENT === sms_row( $allowed )['status'] );

echo "\n=== the hour's limit ===\n";

set_transient( 'tdh_sms_rate_' . $landlord, Sms::RATE_LIMIT, HOUR_IN_SECONDS );
$fake->reset();
$made[] = $limited = probe_inquiry( $home );
$sms->on_inquiry( $limited, $home );
$row = sms_row( $limited );
ok( 'past the limit the inquiry goes by email only, and the row says so', $row && Sms::SKIPPED === $row['status'] && str_contains( (string) $row['provider_response'], 'limit' ), $row ? $row['provider_response'] : 'no row' );
delete_transient( 'tdh_sms_rate_' . $landlord );

echo "\n=== turning it off from the card ===\n";

$f = post_sms( $sms, Sms::ACTION_STOP );
ok( 'Turn texts off answers plainly', Sms::STOPPED === $f, $f );
$state = Sms::state_for( $landlord );
ok( '...consent is withdrawn but the number stays verified', ! $state['consented'] && $state['verified'] );
ok( '...worded as verified, alerts off', str_contains( $state['label'], 'alerts off' ), $state['label'] );

$made[] = $off = probe_inquiry( $home );
$fake->reset();
$sms->on_inquiry( $off, $home );
ok( 'an inquiry now writes no sms row — they turned it off, that is not news', null === sms_row( $off ) && 0 === count( $fake->sent ) );

$html = screen( 'profile' );
ok( 'the card offers Turn texts on with the consent box, no code needed', str_contains( $html, 'Turn texts on' ) && str_contains( $html, 'name="tdh_sms_consent"' ) && ! str_contains( $html, 'name="tdh_sms_code"' ) );

echo "\n=== who may press the buttons ===\n";

wp_set_current_user( 0 );
$_POST = [ 'tdh_action' => Sms::ACTION_START, 'tdh_nonce' => wp_create_nonce( Sms::ACTION_START ), 'tdh_sms_phone' => '4125550142', 'tdh_sms_consent' => '1' ];
$where = ran( static fn() => $sms->handle() );
$_POST = [];
ok( 'a logged-out visitor is sent to sign in', str_contains( $where, Accounts::url( 'login' ) ), $where );

/* The link in the text, opened on a phone that is not signed in. The
   server variables are put back afterwards: WordPress reads REQUEST_URI
   again at shutdown, and an unset one is a warning in the run's output. */
$server_was             = [ $_SERVER['HTTP_HOST'] ?? null, $_SERVER['REQUEST_URI'] ?? null ];
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/somewhere/account/?view=inquiries&msg=' . $texted;
$html = screen( 'inquiries', [ 'msg' => (string) $texted ] );
$back = preg_match( '/redirect_to=[^"&]*/', $html, $m ) ? $m[0] : 'no return link';
ok( 'a logged-out landlord opening the text\'s link is asked to sign in', str_contains( $html, 'Please sign in' ) );
ok( '...with a return link to exactly that page, not a doubled site path', 'redirect_to=' . rawurlencode( 'http://localhost/somewhere/account/?view=inquiries&msg=' . $texted ) === $back, $back );
[ $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] ] = $server_was;

wp_set_current_user( $other );
$_POST = [ 'tdh_action' => Sms::ACTION_VERIFY, 'tdh_nonce' => 'stale', 'tdh_sms_code' => '123456' ];
$where = ran( static fn() => $sms->handle() );
$_POST = [];
ok( 'a stale nonce is refused as expired', Sms::EXPIRED === flag( $where ), $where );

$_POST = [ 'tdh_action' => 'tdh_something_else' ];
ok( 'a post that is not ours is ignored', '(no redirect)' === ran( static fn() => $sms->handle() ) );
$_POST = [];

wp_set_current_user( $landlord );

echo "\n=== the staff view ===\n";

wp_set_current_user( $staff );
$html = screen( 'inquiries', [ 'msg' => (string) $refused ] );
ok( 'the staff message shows the text\'s state under the email\'s', str_contains( $html, 'delivery-state--sms' ) && str_contains( $html, 'Text failed' ) );
ok( '...with what the carrier said', str_contains( $html, '30007' ) );

$html = screen( 'inquiries', [ 'msg' => (string) $paused ] );
ok( 'a skipped text says Not texted and why', str_contains( $html, 'Not texted' ) && str_contains( $html, 'STOP' ) );

$html = screen( 'inquiries', [ 'msg' => (string) $quiet ] );
ok( 'an inquiry with no sms row shows no text line at all', ! str_contains( $html, 'delivery-state--sms' ) );

wp_set_current_user( 0 );

echo "\n=== the words ===\n";

$labels = [];
foreach ( [ 'off', 'pending', 'verified', 'on', 'paused' ] as $s ) {
	$labels[ $s ] = true;
}
ok( 'every state has its own words', count( array_unique( [ 'Not set up', 'Code sent — check your phone', 'Number verified, alerts off', 'On', 'Paused — you replied STOP' ] ) ) === 5 );

foreach ( Sms::messages() as $key => $pair ) {
	ok( "the message for '{$key}' is a sentence, not a key", in_array( $pair[0], [ 'success', 'error' ], true ) && strlen( $pair[1] ) > 20 && ! str_contains( $pair[1], '_' ) );
}

ok( 'a US number displays as people write it', '(412) 555-0142' === Phone::display( '+14125550142' ) );

echo "\n=== what the log was told ===\n";

$lines = TDH\Log::query( [ 'feature' => 'sms', 'per_page' => 200 ] );
$rows  = array_filter( (array) ( $lines['rows'] ?? [] ), static fn( array $r ): bool => (int) $r['id'] > $log_floor );
$codes = array_column( $rows, 'event' );
$seen  = implode( ', ', array_unique( $codes ) );

foreach ( [ 'sms_opted_in', 'sms_code_sent', 'sms_verify_failed', 'sms_verified', 'sms_verification_reset', 'sms_sent', 'sms_failed', 'sms_given_up', 'sms_skipped_test_mode', 'sms_opted_out', 'sms_resumed', 'sms_skipped_opted_out', 'sms_status', 'sms_rate_limited', 'sms_consent_withdrawn', 'sms_webhook_rejected' ] as $event ) {
	ok( "'{$event}' was logged", in_array( $event, $codes, true ), $seen );
}

$leak = false;
foreach ( $rows as $line ) {
	$ctx = wp_json_encode( $line['context'] );
	if ( str_contains( (string) $ctx, '4125550142' ) || str_contains( (string) $ctx, '555-0142' ) ) {
		$leak = true;
	}
}
ok( 'no full phone number reached the log — masked to the last four', ! $leak );

echo "\n=== cleanup ===\n";

foreach ( $made as $inq ) {
	foreach ( Notifications::for_inquiry( (int) $inq ) as $row ) {
		$wpdb->delete( Notifications::table(), [ 'id' => (int) $row['id'] ], [ '%d' ] );
		wp_clear_scheduled_hook( 'tdh_sms_send', [ (int) $row['id'] ] );
		wp_clear_scheduled_hook( 'tdh_notification_retry', [ (int) $row['id'] ] );
	}
	wp_delete_post( (int) $inq, true );
}

wp_delete_post( $home, true );
wp_delete_post( $home2, true );

foreach ( [ $landlord, $other, $staff ] as $person ) {
	wp_delete_user( (int) $person );
}

$_POST = [];
$_GET  = [];
wp_set_current_user( 0 );

if ( false === $log_was ) {
	putenv( 'TDH_LOG_SILENT' );
} else {
	putenv( 'TDH_LOG_SILENT=' . $log_was );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( $wpdb->prepare( "DELETE FROM {$log_table} WHERE id > %d", $log_floor ) );
delete_transient( TDH\Log::TRANSIENT_FAILED );

$enquiries_after = count( (array) get_posts( [ 'post_type' => Post_Types::INQUIRY, 'post_status' => $every_status, 'posts_per_page' => -1, 'fields' => 'ids' ] ) );
$rows_after      = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Notifications::table() ); // phpcs:ignore WordPress.DB
$log_left        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$log_table} WHERE id > {$log_floor}" ); // phpcs:ignore WordPress.DB

ok( 'every fixture is gone', ! get_post( $home ) && ! get_post( $home2 ) && ! get_user_by( 'login', 'tdh_sms_probe' ) );
ok( '...leaving no inquiry behind', $enquiries_after === $enquiries_before, "{$enquiries_before} before, {$enquiries_after} after" );
ok( '...no notification row behind', $rows_after === $rows_before, "{$rows_before} before, {$rows_after} after" );
ok( '...and not one line in the staff log', 0 === $log_left, "{$log_left} left" );

[ $pass, $fail ] = ok();

printf( "\n%s  %d passed, %d failed\n", $fail ? 'FAILED' : 'PASSED', $pass, $fail );

if ( $fail ) {
	exit( 1 );
}
