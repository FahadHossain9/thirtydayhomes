<?php
/**
 * The bot check (Cloudflare Turnstile) on the public forms.
 *
 *     wp eval-file wp-content/plugins/thirtydayhomes-core/tools/verify-bot-check.php
 *
 * Cloudflare is never called: its answer is faked with pre_http_request.
 *
 * @package ThirtyDayHomes
 */

use TDH\Bot_Check;

defined( 'ABSPATH' ) || exit;

function ok( string $label = '', bool $cond = false, string $detail = '' ): array {
	static $p = 0, $f = 0;
	if ( '' === $label ) { return [ $p, $f ]; }
	if ( $cond ) { ++$p; printf( "  ok    %s\n", $label ); }
	else { ++$f; printf( "  FAIL  %s%s\n", $label, '' !== $detail ? "  --> {$detail}" : '' ); }
	return [ $p, $f ];
}

echo "\n=== off until the keys exist ===\n";

/*
 * This suite passes whether or not the site already has keys: with them
 * (a developer's wp-config, or live) the "off" checks have nothing to
 * prove and say so; without them it borrows Cloudflare's published test
 * keys for the rest of the run.
 */
$was_on = Bot_Check::enabled();

// Every widget printed in this run, to count the script once at the end.
$printed = Bot_Check::field();

ok( 'without keys the check is off (keys already present here: nothing to prove)', $was_on || ( '' === $printed && Bot_Check::passes( 'login' ) && Bot_Check::judge( '', 'login' ) ) );
$login    = do_shortcode( '[tdh_login]' );
$printed .= $login;
ok( '...and the sign-in page prints no widget', $was_on || ! str_contains( $login, 'cf-turnstile' ) );

if ( ! $was_on ) {
	// Cloudflare's own published test keys.
	define( 'TDH_TURNSTILE_SITE_KEY', '1x00000000000000000000AA' );
	define( 'TDH_TURNSTILE_SECRET', '1x0000000000000000000000000000000AA' );
}

echo "\n=== on ===\n";

ok( 'with both keys the check is on', Bot_Check::enabled() );
$field    = Bot_Check::field();
$printed .= $field . Bot_Check::field();
ok( 'the widget carries the site key, never the secret', str_contains( $field, 'data-sitekey="' . Bot_Check::site_key() . '"' ) && ! str_contains( $printed, (string) constant( 'TDH_TURNSTILE_SECRET' ) ) );
ok( '...the script is loaded once a page, however many forms it holds', 1 === substr_count( $printed, 'turnstile/v0/api.js' ), (string) substr_count( $printed, 'turnstile/v0/api.js' ) );

foreach ( [ 'tdh_login' => 'sign-in', 'tdh_register' => 'sign-up', 'tdh_lost_password' => 'password reset' ] as $code => $name ) {
	wp_set_current_user( 0 );
	ok( "the {$name} form shows the check", str_contains( do_shortcode( "[{$code}]" ), 'cf-turnstile' ) );
}
ok( 'the contact form shows the check', str_contains( TDH\Render::contact(), 'cf-turnstile' ) );

echo "\n=== the answer ===\n";

$answer = null;
$fake   = static function ( $pre, $args, $url ) use ( &$answer ) {
	return str_contains( (string) $url, 'challenges.cloudflare.com' ) ? $answer : $pre;
};
add_filter( 'pre_http_request', $fake, 10, 3 );

// judge() is the verdict a browser's form post gets; passes() wraps it.
ok( 'no answer from the widget is refused', ! Bot_Check::judge( '', 'login' ) );

$answer = [ 'response' => [ 'code' => 200 ], 'body' => '{"success":true}' ];
ok( 'a person (success) passes', Bot_Check::judge( 'token-abc', 'login' ) );

$answer = [ 'response' => [ 'code' => 200 ], 'body' => '{"success":false,"error-codes":["invalid-input-response"]}' ];
ok( 'a bot (success false) is refused', ! Bot_Check::judge( 'token-abc', 'login' ) );

$answer = new WP_Error( 'http_request_failed', 'timed out' );
ok( 'Cloudflare unreachable: the person is let through, never locked out', Bot_Check::judge( 'token-abc', 'login' ) );

$answer = [ 'response' => [ 'code' => 500 ], 'body' => '' ];
ok( '...the same for a Cloudflare error page', Bot_Check::judge( 'token-abc', 'login' ) );

ok( 'the refusal says what to do', str_contains( Bot_Check::message(), 'press the button again' ) );

// This suite runs through WP-CLI: no browser, so nobody to challenge. It is
// why every other suite's form posts work on a site that has keys.
$_POST = [];
ok( 'a command-line run is never challenged, keys or not', defined( 'WP_CLI' ) && WP_CLI && Bot_Check::passes( 'login' ) );

remove_filter( 'pre_http_request', $fake, 10 );
$_POST = [];

[ $pass, $fail ] = ok();
printf( "\n%s  %d passed, %d failed\n", $fail ? 'FAILED' : 'PASSED', $pass, $fail );
if ( $fail ) {
	exit( 1 );
}
