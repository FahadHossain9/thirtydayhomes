<?php
/**
 * Email delivery — verification suite.
 *
 * Run it through verify.bat, or directly:
 *
 *   D:\xampp\php\php.exe D:\xampp\wp-cli.phar eval-file `
 *     "wp-content/plugins/thirtydayhomes-core/tools/verify-delivery.php"
 *
 * Never opens a socket and never sends a real message: PHPMailer is driven
 * directly with a stub, and wp_mail is intercepted. Every setting it touches
 * is restored at the end.
 *
 * @package ThirtyDayHomes
 */

use TDH\Mail;
use TDH\Smtp;

/**
 * Record one assertion and hand back the running tally.
 *
 * Static, not global: under `wp eval-file` the script body is not the global
 * scope, so `global $pass` inside a function reaches a different variable
 * entirely and every tally comes out zero.
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

/** A stand-in for PHPMailer that records what was set on it. */
function fake_mailer(): object {
	return new class() {
		public $Host = '';        // phpcs:ignore
		public $Port = 0;         // phpcs:ignore
		public $Username = '';    // phpcs:ignore
		public $Password = '';    // phpcs:ignore
		public $SMTPAuth = null;  // phpcs:ignore
		public $SMTPSecure = null;// phpcs:ignore
		public $SMTPAutoTLS = null; // phpcs:ignore
		public $Timeout = 0;      // phpcs:ignore
		public bool $is_smtp = false;

		public function isSMTP(): void { // phpcs:ignore
			$this->is_smtp = true;
		}
	};
}

/* Everything this suite writes, so it can be put back. */
$restore = [];
foreach ( [ 'enabled', 'host', 'port', 'username', 'password', 'encryption' ] as $f ) {
	$restore[ $f ] = get_option( Smtp::option_name( $f ), null );
}
$restore_from      = get_option( Mail::OPTION_FROM, null );
$restore_from_name = get_option( Mail::OPTION_FROM_NAME, null );
$restore_error     = get_option( Smtp::OPTION_LAST_ERROR, null );
$restore_sent      = get_option( Smtp::OPTION_LAST_SENT, null );

/** Put one setting in place. */
function put( string $field, string $value ): void {
	Smtp::save( $field, $value );
}

/** A complete, valid configuration. */
function configure(): void {
	put( 'enabled', '1' );
	put( 'host', 'smtp.example.com' );
	put( 'port', '' );
	put( 'username', 'noreply@thirtydayhomes.test' );
	put( 'password', 'hunter2' );
	put( 'encryption', 'tls' );
}

$smtp = new Smtp();

/* -------------------------------------------------------------------------
 * 1. Wiring
 * ---------------------------------------------------------------------- */

echo "\n=== wiring ===\n";

ok( 'Smtp autoloads', class_exists( Smtp::class ) );
ok( 'Smtp is a loaded module', TDH\Core::instance()->module( 'smtp' ) instanceof Smtp );
ok( 'the settings screen class autoloads', class_exists( TDH\Admin\Mail_Settings::class ) );

configure();
$smtp->register();

ok( 'it hooks phpmailer_init', has_action( 'phpmailer_init' ) !== false );
ok( 'it listens for failures', has_action( 'wp_mail_failed' ) !== false );
ok( 'it listens for successes', has_action( 'wp_mail_succeeded' ) !== false );

/* -------------------------------------------------------------------------
 * 2. Reading settings
 * ---------------------------------------------------------------------- */

echo "\n=== reading settings ===\n";

ok( 'a stored value is read back', 'smtp.example.com' === Smtp::setting( 'host' ) );
ok( 'a missing value is an empty string, not null', '' === Smtp::setting( 'nonexistent' ) );

put( 'host', '   spaced.example.com   ' );
ok( 'values are trimmed', 'spaced.example.com' === Smtp::setting( 'host' ) );
put( 'host', 'smtp.example.com' );

ok( 'the constant name is predictable', 'TDH_SMTP_PASSWORD' === Smtp::constant_name( 'password' ) );
ok( 'nothing is locked without a constant', ! Smtp::is_locked( 'password' ) );

/* -------------------------------------------------------------------------
 * 3. The port
 * ---------------------------------------------------------------------- */

echo "\n=== the port ===\n";

put( 'port', '' );

put( 'encryption', 'tls' );
ok( 'STARTTLS defaults to 587', 587 === Smtp::port() );

put( 'encryption', 'ssl' );
ok( 'SSL defaults to 465', 465 === Smtp::port() );

put( 'encryption', 'none' );
ok( 'no encryption defaults to 25', 25 === Smtp::port() );

put( 'encryption', 'tls' );
put( 'port', '2525' );
ok( 'an explicit port wins', 2525 === Smtp::port() );

put( 'port', '0' );
ok( 'port 0 falls back rather than being used', 587 === Smtp::port() );

put( 'port', '99999' );
ok( 'a port outside the valid range falls back', 587 === Smtp::port(), (string) Smtp::port() );

put( 'port', 'not-a-number' );
ok( 'a non-numeric port falls back', 587 === Smtp::port() );

put( 'port', '' );

put( 'encryption', 'wildly-wrong' );
ok( 'an unknown encryption falls back to STARTTLS', 'tls' === Smtp::encryption() );
put( 'encryption', 'tls' );

/* -------------------------------------------------------------------------
 * 4. Readiness
 * ---------------------------------------------------------------------- */

echo "\n=== readiness ===\n";

configure();
ok( 'a complete configuration has no problems', [] === Smtp::problems(), implode( ' ', Smtp::problems() ) );
ok( '...and is ready', Smtp::is_ready() );

put( 'enabled', '' );
ok( 'switched off is not ready', ! Smtp::is_ready() );
ok( '...even though nothing is wrong with it', [] === Smtp::problems() );

configure();
put( 'host', '' );
ok( 'no host is not ready', ! Smtp::is_ready() );
ok( '...and says so', 1 === count( Smtp::problems() ) );

configure();
put( 'password', '' );
ok( 'a username with no password is refused', ! Smtp::is_ready() );
ok(
	'...and names which half is missing',
	str_contains( implode( ' ', Smtp::problems() ), 'no password' ),
	implode( ' ', Smtp::problems() )
);

configure();
put( 'username', '' );
ok( 'a password with no username is refused', ! Smtp::is_ready() );

configure();
put( 'username', '' );
put( 'password', '' );
ok( 'BOTH blank is a valid unauthenticated relay', [] === Smtp::problems(), implode( ' ', Smtp::problems() ) );
ok( '...and is ready', Smtp::is_ready() );

/*
 * The transport and the sender are reported separately, and they have to be.
 * A mistyped From is a real failure, but folding it into is_ready() would make
 * a site with a perfectly good mailbox abandon SMTP and fall back to PHP's
 * mail() — which fails too, differently, with a worse error, while the screen
 * blames a configuration that is fine.
 *
 * This is also the only way the suite runs at all on a localhost install:
 * is_email() rejects any address on a host with no dot in it, so the From
 * address here is never valid and every transport assertion below would
 * inherit that failure.
 */
configure();
update_option( Mail::OPTION_FROM, 'clearly not an address' );

ok( 'a broken sender is not a transport problem', [] === Smtp::problems(), implode( ' ', Smtp::problems() ) );
ok(
	'...so SMTP is still used rather than silently falling back',
	Smtp::is_ready(),
	'falling back to mail() here fails too, with a worse error, and blames the wrong thing'
);
ok( '...but it IS reported', 1 === count( Smtp::sender_problems() ) );

delete_option( Mail::OPTION_FROM );

/* -------------------------------------------------------------------------
 * 5. Configuring PHPMailer
 * ---------------------------------------------------------------------- */

echo "\n=== configuring PHPMailer ===\n";

configure();

$m = fake_mailer();
$smtp->configure( $m );

ok( 'it switches PHPMailer to SMTP', $m->is_smtp );
ok( 'the host is passed through', 'smtp.example.com' === $m->Host );
ok( 'the port is passed through', 587 === $m->Port );
ok( 'authentication is on', true === $m->SMTPAuth );
ok( 'the username is passed through', 'noreply@thirtydayhomes.test' === $m->Username );
ok( 'the password is passed through', 'hunter2' === $m->Password );
ok( 'STARTTLS is requested', 'tls' === $m->SMTPSecure );
ok( 'automatic TLS is left on', true === $m->SMTPAutoTLS );
ok(
	'the timeout is short enough that a dead server costs a page, not a session',
	$m->Timeout > 0 && $m->Timeout <= 30,
	(string) $m->Timeout
);

put( 'encryption', 'ssl' );
$m = fake_mailer();
$smtp->configure( $m );
ok( 'SSL is requested when chosen', 'ssl' === $m->SMTPSecure );
ok( '...on port 465', 465 === $m->Port );

put( 'encryption', 'none' );
$m = fake_mailer();
$smtp->configure( $m );
ok( 'no encryption asks for none', '' === $m->SMTPSecure );
ok(
	'...and stops PHPMailer upgrading behind your back',
	false === $m->SMTPAutoTLS,
	'a silent STARTTLS upgrade breaks a relay whose certificate does not validate'
);

configure();
put( 'username', '' );
put( 'password', '' );
$m = fake_mailer();
$smtp->configure( $m );
ok( 'an unauthenticated relay turns auth off', false === $m->SMTPAuth );
ok( '...and sends no credentials', '' === $m->Username && '' === $m->Password );

/* A half-configured or switched-off setup must not touch PHPMailer at all. */
configure();
put( 'enabled', '' );
$m = fake_mailer();
$smtp->configure( $m );
ok( 'switched off, PHPMailer is left completely alone', ! $m->is_smtp && '' === $m->Host );

configure();
put( 'host', '' );
$m = fake_mailer();
$smtp->configure( $m );
ok(
	'half-configured, PHPMailer is left completely alone',
	! $m->is_smtp && '' === $m->Host,
	'pointing PHPMailer at nothing turns unauthenticated-but-working into not-working'
);

configure();
$smtp->configure( null );
ok( 'a non-object is ignored rather than fatal', true );

/* -------------------------------------------------------------------------
 * 6. The sender
 * ---------------------------------------------------------------------- */

echo "\n=== the sender ===\n";

delete_option( Mail::OPTION_FROM );
$mail = new Mail();

$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$host = preg_replace( '/^www\./i', '', $host );

ok( 'with nothing set it is noreply@ on the site domain', 'noreply@' . $host === $mail->from_address( '' ), $mail->from_address( '' ) );
ok( '...which matches the site', Smtp::from_matches_site() );

update_option( Mail::OPTION_FROM, 'hello@somewhere-else.test' );
ok( 'a configured address is used', 'hello@somewhere-else.test' === $mail->from_address( '' ) );
ok(
	'...and the mismatch with the site domain is detected',
	! Smtp::from_matches_site(),
	'SPF and DKIM authenticate the From domain, so this is the usual reason authenticated mail still lands in spam'
);

update_option( Mail::OPTION_FROM, 'not an address' );
ok( 'an invalid stored address falls back rather than being sent', 'noreply@' . $host === $mail->from_address( '' ) );

delete_option( Mail::OPTION_FROM );

/* -------------------------------------------------------------------------
 * 7. Recording what happened
 * ---------------------------------------------------------------------- */

echo "\n=== recording what happened ===\n";

delete_option( Smtp::OPTION_LAST_ERROR );
delete_option( Smtp::OPTION_LAST_SENT );

$smtp->record_failure( new WP_Error( 'smtp', 'SMTP Error: 535 Authentication failed' ) );
$err = Smtp::last_error();

ok( 'a failure is kept', is_array( $err ) );
ok( '...with what the server actually said', $err && str_contains( $err['message'], '535' ) );
ok( '...and when', $err && $err['at'] > 0 );

$smtp->record_success();

ok( 'a success is kept', Smtp::last_sent() > 0 );
ok(
	'...and clears the previous failure',
	null === Smtp::last_error(),
	'leaving a fixed problem on screen is how somebody re-solves it'
);

$smtp->record_failure( 'not a WP_Error' );
ok( 'a malformed failure does not crash and is still recorded', null !== Smtp::last_error() );

delete_option( Smtp::OPTION_LAST_ERROR );
delete_option( Smtp::OPTION_LAST_SENT );

/* -------------------------------------------------------------------------
 * 8. The test button
 * ---------------------------------------------------------------------- */

echo "\n=== the test button ===\n";

$result = Smtp::send_test( 'not-an-address' );
ok( 'it refuses an invalid address', ! $result['sent'] );
ok( '...and says why', str_contains( $result['message'], 'valid' ) );

if ( Mail::capturing() ) {
	$result = Smtp::send_test( 'someone@example.com' );
	ok(
		'in a capturing environment it reports that nothing was sent',
		! $result['sent'] && str_contains( $result['message'], Mail::capture_dir() ),
		$result['message']
	);
	ok(
		'...rather than claiming success',
		! $result['sent'],
		'a green tick here would send somebody to production believing mail works'
	);
} else {
	ok( 'in a capturing environment it reports that nothing was sent', false, 'not a capturing environment; skipped' );
	ok( '...rather than claiming success', false, 'not a capturing environment; skipped' );
}

/* Drive the send path with capture disabled but wp_mail intercepted, so the
   success and failure branches are both exercised without a socket. */
$sent_ok = static fn() => true;
add_filter( 'pre_wp_mail', $sent_ok, 1 );
add_filter( 'tdh_test_force_send', '__return_true' );

$was = Mail::capturing();

if ( ! $was ) {
	$result = Smtp::send_test( 'someone@example.com' );
	ok( 'a successful send is reported as sent', $result['sent'], $result['message'] );
	ok( '...naming the address', str_contains( $result['message'], 'someone@example.com' ) );

	remove_filter( 'pre_wp_mail', $sent_ok, 1 );

	$bad = static function () {
		return false;
	};
	add_filter( 'pre_wp_mail', $bad, 1 );

	$result = Smtp::send_test( 'someone@example.com' );
	ok( 'a refused send is reported as not sent', ! $result['sent'] );

	remove_filter( 'pre_wp_mail', $bad, 1 );
} else {
	remove_filter( 'pre_wp_mail', $sent_ok, 1 );
	echo "  ..    live send branches skipped: this environment captures mail\n";
}

remove_filter( 'tdh_test_force_send', '__return_true' );

/* -------------------------------------------------------------------------
 * 9. Capture still wins outside production
 * ---------------------------------------------------------------------- */

echo "\n=== capture still wins outside production ===\n";

ok( 'production never captures', ! Mail::should_capture( 'production' ) );
ok( '...not even when the constant asks it to', ! Mail::should_capture( 'production', true ) );
ok( 'local captures by default', Mail::should_capture( 'local' ) );
ok( 'staging does not capture by default', ! Mail::should_capture( 'staging' ) );
ok( '...unless asked', Mail::should_capture( 'staging', true ) );

/* -------------------------------------------------------------------------
 * Restore
 * ---------------------------------------------------------------------- */

foreach ( $restore as $field => $value ) {
	if ( null === $value ) {
		delete_option( Smtp::option_name( $field ) );
	} else {
		update_option( Smtp::option_name( $field ), $value );
	}
}

foreach (
	[
		Mail::OPTION_FROM       => $restore_from,
		Mail::OPTION_FROM_NAME  => $restore_from_name,
		Smtp::OPTION_LAST_ERROR => $restore_error,
		Smtp::OPTION_LAST_SENT  => $restore_sent,
	] as $option => $value
) {
	if ( null === $value ) {
		delete_option( $option );
	} else {
		update_option( $option, $value );
	}
}

echo "\n  settings restored\n";

[ $pass, $fail ] = ok();

printf( "\n%s  %d passed, %d failed\n\n", $fail ? 'FAILED' : 'PASSED', $pass, $fail );

if ( $fail && defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::halt( 1 );
}
