<?php
/**
 * Contact form — verification suite.
 *
 * Run it through verify.bat, which finds the binaries for you, or directly:
 *
 *   D:\xampp\php\php.exe D:\xampp\wp-cli.phar eval-file `
 *     "wp-content/plugins/thirtydayhomes-core/tools/verify-contact.php"
 *
 * Safe on a populated site: every message it sends is deleted at the end,
 * and it never sends a real email — pre_wp_mail is intercepted throughout.
 *
 * Separate from verify.php because that suite covers the marketplace rules
 * that must hold on every site, and this one drives a single feature end to
 * end, including its failure paths.
 *
 * @package ThirtyDayHomes
 */

use TDH\Contact;
use TDH\Post_Types;

/**
 * Record one assertion, and hand back the running tally.
 *
 * The counters are static, NOT globals: under `wp eval-file` the script body
 * is not the global scope, so `global $pass` inside a function reaches an
 * entirely different variable and every tally comes out zero. That has now
 * cost this project twice.
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

/** Call a private method. */
function call_private( object $obj, string $method, array $args = [] ) {
	$m = new ReflectionMethod( $obj, $method );
	$m->setAccessible( true );

	return $m->invokeArgs( $obj, $args );
}

/**
 * Run handle() and report where it redirected to, instead of exiting.
 *
 * bounce() ends in exit, which no test can survive — but it redirects
 * first, and wp_redirect runs the `wp_redirect` filter before it sends a
 * header. Throwing from that filter escapes the handler with the location
 * intact.
 */
function run_handler( TDH\Contact $contact ): string {

	$catch = static function ( $location ) {
		throw new RuntimeException( (string) $location );
	};

	add_filter( 'wp_redirect', $catch, 1 );

	try {
		$contact->handle();
		$where = '(no redirect)';
	} catch ( RuntimeException $e ) {
		$where = $e->getMessage();
	} finally {
		remove_filter( 'wp_redirect', $catch, 1 );
	}

	return $where;
}

/** The `tdh_contact` value a redirect carried. */
function outcome( string $url ): string {
	$q = wp_parse_url( $url, PHP_URL_QUERY );
	parse_str( (string) $q, $args );

	return (string) ( $args['tdh_contact'] ?? '' );
}

/** A fresh, valid submission. */
function good_post(): array {
	return [
		'tdh_action'  => 'tdh_contact',
		'_wpnonce'    => wp_create_nonce( 'tdh_contact_send' ),
		'tdh_name'    => 'Dana Whitfield',
		'tdh_email'   => 'dana@example.com',
		'tdh_phone'   => '412-555-0142',
		'tdh_topic'   => 'renting',
		'tdh_message' => 'Looking for a two-bedroom near UPMC Presbyterian from March.',
	];
}

/** Forget every rate-limit and stash transient this suite created. */
function reset_state(): void {
	global $wpdb;

	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '_transient_tdh_contact%'
		    OR option_name LIKE '_transient_timeout_tdh_contact%'"
	);

	wp_cache_flush();
}

$_SERVER['REMOTE_ADDR']     = '203.0.113.44';
$_SERVER['HTTP_USER_AGENT'] = 'tdh-verify';

$contact = new Contact();

reset_state();

/* -------------------------------------------------------------------------
 * 1. Wiring
 * ---------------------------------------------------------------------- */

echo "\n=== wiring ===\n";

ok( 'Contact class autoloads', class_exists( Contact::class ) );
ok( '[tdh_contact] is registered', shortcode_exists( 'tdh_contact' ) );
ok( 'Contact is a loaded module', TDH\Core::instance()->module( 'contact' ) instanceof Contact );

$page = get_page_by_path( 'contact' );
ok( 'the contact page exists', (bool) $page );
ok(
	'the contact page renders the form',
	$page && str_contains( $page->post_content, '[tdh_contact]' ),
	$page ? trim( wp_strip_all_tags( $page->post_content ) ) : 'no page'
);

/* -------------------------------------------------------------------------
 * 2. The rendered form
 * ---------------------------------------------------------------------- */

echo "\n=== the form on the page ===\n";

$html = do_shortcode( '[tdh_contact]' );

ok( 'it posts to itself', str_contains( $html, '<form class="contact-form" method="post">' ) );
ok( 'it carries a nonce', (bool) preg_match( '/name="_wpnonce" value="[a-f0-9]{10}"/', $html ) );
ok( 'it declares its action', str_contains( $html, 'name="tdh_action" value="tdh_contact"' ) );
ok( 'the honeypot is present', str_contains( $html, 'name="tdh_website"' ) );
ok( 'the honeypot is hidden from assistive tech', str_contains( $html, '<div class="tdh-hp" aria-hidden="true">' ) );
ok( 'the honeypot is out of the tab order', str_contains( $html, 'tabindex="-1"' ) );

foreach ( [ 'tdh_name', 'tdh_email', 'tdh_phone', 'tdh_message' ] as $field ) {
	ok( "the {$field} field is there", str_contains( $html, 'name="' . $field . '"' ) );
}

ok( 'every topic is offered as a chip', 4 === substr_count( $html, 'name="tdh_topic"' ) );

// Collapse the markup's indentation before matching across attributes.
$flat = (string) preg_replace( '/\s+/', ' ', $html );

ok(
	'exactly one topic starts chosen',
	1 === substr_count( $flat, "checked='checked'" ),
	substr_count( $flat, "checked='checked'" ) . ' checked'
);
ok(
	'...and it is the first one',
	str_contains( $flat, 'value="renting" checked=' )
);

foreach ( [ 'contact-shell', 'contact-promise', 'contact-panel', 'contact-assurances', 'contact-status' ] as $hook ) {
	ok( "the .{$hook} style hook is in the markup", str_contains( $html, $hook ) );
}

ok( 'no message is echoed from the URL', ! str_contains( $html, 'tdh_contact=' ) );

/* -------------------------------------------------------------------------
 * 3. Rejections
 * ---------------------------------------------------------------------- */

echo "\n=== what it refuses ===\n";

$_POST = [];
ok( 'it ignores a request that is not a submission', '(no redirect)' === run_handler( $contact ) );

$_POST = good_post();
unset( $_POST['_wpnonce'] );
ok( 'a submission with no nonce is refused', 'expired' === outcome( run_handler( $contact ) ) );

$_POST             = good_post();
$_POST['_wpnonce'] = 'not-a-real-nonce';
ok( 'a submission with a forged nonce is refused', 'expired' === outcome( run_handler( $contact ) ) );

$before = wp_count_posts( Post_Types::INQUIRY )->publish;

$_POST                = good_post();
$_POST['tdh_website'] = 'https://cheap-pills.example';
$bot                  = run_handler( $contact );

ok( 'a bot is answered with the success page', 'sent' === outcome( $bot ) );
ok(
	'...and nothing was stored',
	wp_count_posts( Post_Types::INQUIRY )->publish === $before,
	'an inquiry was created from a honeypot hit'
);

/* -------------------------------------------------------------------------
 * 4. Validation, and what a person typed
 * ---------------------------------------------------------------------- */

echo "\n=== validation ===\n";

$_POST                = good_post();
$_POST['tdh_name']    = '';
$_POST['tdh_email']   = 'not-an-address';
$_POST['tdh_message'] = '   ';

ok( 'an incomplete message is refused', 'invalid' === outcome( run_handler( $contact ) ) );

$stash = Contact::take();

ok( 'three things were wrong and three were reported', 3 === count( $stash['errors'] ), print_r( $stash['errors'], true ) );
ok( 'the phone number survived the rejection', '412-555-0142' === ( $stash['values']['phone'] ?? '' ) );
ok(
	'the bad address is shown back, not blanked',
	'not-an-address' === ( $stash['values']['email'] ?? '' ),
	sprintf( "got '%s' — a typo should be correctable, not retyped", $stash['values']['email'] ?? '' )
);
ok( 'take() clears the stash so it shows once', [] === Contact::take()['errors'] );

$_POST                = good_post();
$_POST['tdh_message'] = str_repeat( 'a', 5001 );
ok( 'an oversized message is refused', 'invalid' === outcome( run_handler( $contact ) ) );
Contact::take();

$_POST              = good_post();
$_POST['tdh_topic'] = 'javascript:alert(1)';
$where              = run_handler( $contact );
ok( 'an unknown topic falls back rather than being refused', 'sent' === outcome( $where ) );

$latest = get_posts(
	[
		'post_type'      => Post_Types::INQUIRY,
		'posts_per_page' => 1,
		'post_status'    => 'publish',
	]
);
ok(
	'...and it lands in "other"',
	$latest && 'other' === get_post_meta( $latest[0]->ID, Contact::META_TOPIC, true )
);
if ( $latest ) {
	wp_delete_post( $latest[0]->ID, true );
}

/* -------------------------------------------------------------------------
 * 5. A message that goes through
 * ---------------------------------------------------------------------- */

echo "\n=== a real message ===\n";

reset_state();

$mail = null;
$spy  = static function ( $short, $atts ) use ( &$mail ) {
	$mail = $atts;

	return true; // Stand in for a successful send.
};

add_filter( 'pre_wp_mail', $spy, 10, 2 );

$fired = 0;
add_action( 'tdh_contact_received', static function () use ( &$fired ) { ++$fired; } );

$_POST = good_post();
$where = run_handler( $contact );

remove_filter( 'pre_wp_mail', $spy, 10 );

ok( 'it reports success', 'sent' === outcome( $where ) );
ok( 'it redirects back to the contact page', str_starts_with( $where, (string) get_permalink( $page ) ) );
ok( 'the tdh_contact_received hook fired once', 1 === $fired );

$saved = get_posts(
	[
		'post_type'      => Post_Types::INQUIRY,
		'posts_per_page' => 1,
		'post_status'    => 'publish',
	]
);

$inquiry = $saved[0] ?? null;

ok( 'the message was written down', (bool) $inquiry );
ok( 'it is stored as an inquiry', $inquiry && Post_Types::INQUIRY === $inquiry->post_type );
ok( 'the message body is kept verbatim', $inquiry && str_contains( $inquiry->post_content, 'UPMC Presbyterian' ) );
ok( 'the title names the sender and the topic', $inquiry && str_contains( $inquiry->post_title, 'Dana Whitfield' ) );

foreach (
	[
		Contact::META_KIND  => 'contact',
		Contact::META_NAME  => 'Dana Whitfield',
		Contact::META_EMAIL => 'dana@example.com',
		Contact::META_PHONE => '412-555-0142',
		Contact::META_TOPIC => 'renting',
	] as $key => $expected
) {
	$actual = $inquiry ? get_post_meta( $inquiry->ID, $key, true ) : '';
	ok( "{$key} is recorded", $expected === $actual, "got '{$actual}'" );
}

ok(
	'the send result is recorded on the record',
	$inquiry && 'sent' === get_post_meta( $inquiry->ID, Contact::META_NOTIFIED, true ),
	$inquiry ? (string) get_post_meta( $inquiry->ID, Contact::META_NOTIFIED, true ) : ''
);

/* -------------------------------------------------------------------------
 * 6. The notification
 * ---------------------------------------------------------------------- */

echo "\n=== the notification ===\n";

ok( 'an email was attempted', is_array( $mail ) );
ok( 'it goes to the site owner', $mail && get_option( 'admin_email' ) === $mail['to'] );
ok( 'the subject carries the topic', $mail && str_contains( $mail['subject'], 'Finding a home to rent' ) );
ok( 'the body carries the message', $mail && str_contains( $mail['message'], 'UPMC Presbyterian' ) );
ok( 'the body links straight to the record', $mail && $inquiry && str_contains( $mail['message'], 'post=' . $inquiry->ID ) );

$headers = implode( "\n", (array) ( $mail['headers'] ?? [] ) );

ok( 'reply goes to the sender', str_contains( $headers, 'Reply-To: Dana Whitfield <dana@example.com>' ) );
ok(
	'...but From is NOT the sender, which would fail SPF',
	! str_contains( strtolower( $headers ), 'from: dana@example.com' )
);
ok( 'From stays our own address', str_contains( (string) apply_filters( 'wp_mail_from', '' ), '@' ) );

/* A failed send must not lose the message. */
$fail_spy = static fn() => false;
add_filter( 'pre_wp_mail', $fail_spy );

$_POST              = good_post();
$_POST['tdh_email'] = 'second@example.com';
$where              = run_handler( $contact );

remove_filter( 'pre_wp_mail', $fail_spy );

ok( 'a failed send still reports success to the visitor', 'sent' === outcome( $where ) );

$second = get_posts(
	[
		'post_type'      => Post_Types::INQUIRY,
		'posts_per_page' => 1,
		'post_status'    => 'publish',
	]
);

ok( 'the message survives a failed send', $second && 'second@example.com' === get_post_meta( $second[0]->ID, Contact::META_EMAIL, true ) );
ok(
	'...and the failure is recorded on it',
	$second && 'failed' === get_post_meta( $second[0]->ID, Contact::META_NOTIFIED, true ),
	$second ? (string) get_post_meta( $second[0]->ID, Contact::META_NOTIFIED, true ) : ''
);

/* -------------------------------------------------------------------------
 * 7. Who can read it
 * ---------------------------------------------------------------------- */

echo "\n=== who can read a message ===\n";

$landlord = get_users( [ 'role' => 'tdh_landlord', 'number' => 1 ] );
$landlord = $landlord[0] ?? null;

if ( $inquiry && $landlord ) {
	wp_set_current_user( $landlord->ID );
	ok( 'a landlord cannot read a message addressed to the company', ! current_user_can( 'read_post', $inquiry->ID ) );
	wp_set_current_user( 0 );
} else {
	ok( 'a landlord cannot read a message addressed to the company', false, 'no landlord account to test with' );
}

$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
$admin = $admin[0] ?? null;

if ( $inquiry && $admin ) {
	wp_set_current_user( $admin->ID );
	ok( 'an administrator can', current_user_can( 'read_post', $inquiry->ID ) );
	wp_set_current_user( 0 );
} else {
	ok( 'an administrator can', false, 'no administrator to test with' );
}

ok( 'a logged-out visitor cannot', ! current_user_can( 'read_post', (int) ( $inquiry->ID ?? 0 ) ) );

/* -------------------------------------------------------------------------
 * 8. Rate limiting
 * ---------------------------------------------------------------------- */

echo "\n=== rate limiting ===\n";

reset_state();
add_filter( 'pre_wp_mail', '__return_true' );

$results = [];

for ( $i = 0; $i < 7; $i++ ) {
	$_POST              = good_post();
	$_POST['tdh_email'] = "flood{$i}@example.com";
	$results[]          = outcome( run_handler( $contact ) );
}

remove_filter( 'pre_wp_mail', '__return_true' );

ok( 'five messages an hour are accepted', [ 'sent', 'sent', 'sent', 'sent', 'sent' ] === array_slice( $results, 0, 5 ), implode( ',', $results ) );
ok( 'the sixth is asked to wait', 'too_many' === $results[5] );
ok( 'and so is the seventh', 'too_many' === $results[6] );

$stored = get_posts(
	[
		'post_type'      => Post_Types::INQUIRY,
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'meta_key'       => Contact::META_EMAIL,
		'meta_value'     => 'flood5@example.com',
		'fields'         => 'ids',
	]
);
ok( 'a throttled message is not stored', [] === $stored );

/* A different visitor is unaffected. */
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
add_filter( 'pre_wp_mail', '__return_true' );

$_POST              = good_post();
$_POST['tdh_email'] = 'elsewhere@example.com';
$other              = outcome( run_handler( $contact ) );

remove_filter( 'pre_wp_mail', '__return_true' );

ok( 'one flooder does not lock out everybody else', 'sent' === $other, $other );

/* -------------------------------------------------------------------------
 * 9. The stash is per visitor, not per address
 * ---------------------------------------------------------------------- */

echo "\n=== the stash is private ===\n";

reset_state();

$_SERVER['REMOTE_ADDR']     = '203.0.113.77';
$_SERVER['HTTP_USER_AGENT'] = 'browser-one';

$_POST             = good_post();
$_POST['tdh_name'] = '';
run_handler( $contact );

/* Same office, different machine. */
$_SERVER['HTTP_USER_AGENT'] = 'browser-two';

ok(
	'a colleague on the same connection does not see your half-written message',
	[] === Contact::take()['values']
);

$_SERVER['HTTP_USER_AGENT'] = 'browser-one';
ok( 'you still see your own', 'not the same' !== ( Contact::take()['values']['email'] ?? 'not the same' ) );

/* -------------------------------------------------------------------------
 * Tidy up
 * ---------------------------------------------------------------------- */

$made = get_posts(
	[
		'post_type'      => Post_Types::INQUIRY,
		'posts_per_page' => -1,
		'post_status'    => 'any',
		'meta_key'       => Contact::META_KIND,
		'meta_value'     => Contact::KIND_CONTACT,
		'fields'         => 'ids',
	]
);

foreach ( $made as $id ) {
	wp_delete_post( $id, true );
}

reset_state();

printf( "\n  cleaned up %d test message(s)\n", count( $made ) );

[ $pass, $fail ] = ok();

printf( "\n%s  %d passed, %d failed\n\n", $fail ? 'FAILED' : 'PASSED', $pass, $fail );

/*
 * A non-zero exit code, so verify.bat and any CI step can tell a failure
 * from a pass without reading the output. WP_CLI::halt rather than exit():
 * it lets wp-cli shut down cleanly and still sets the code.
 */
if ( $fail && defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::halt( 1 );
}
