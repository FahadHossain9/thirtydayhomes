<?php
/**
 * Security baseline — measured, not assumed.
 *
 * Run it anywhere:
 *
 *   D:\xampp\php\php.exe D:\xampp\wp-cli.phar eval-file `
 *     "wp-content/plugins/thirtydayhomes-core/tools/baseline.php"
 *
 *   wp eval-file wp-content/plugins/thirtydayhomes-core/tools/baseline.php
 *
 * Read-only. It changes nothing and sends nothing.
 *
 * ─── WHY IT KNOWS WHICH ENVIRONMENT IT IS ON ──────────────────────────────
 *
 * The first version of this reported "site URL is not https", "environment
 * is not production" and "mail is being captured" as failures — on a laptop,
 * where all three are correct. A check that fires on a developer machine
 * every single run is a check people learn to scroll past, and the one real
 * finding underneath it goes with them.
 *
 * So every check declares where it APPLIES. On a machine it does not apply
 * to it prints as skipped, with the reason. A FAIL therefore always means
 * something, which is the only way anybody keeps reading these.
 *
 * @package ThirtyDayHomes
 */

use TDH\Mail;
use TDH\Smtp;

const TDH_ANY        = 'any';
const TDH_PRODUCTION = 'production';

/**
 * Record one check.
 *
 * Static counters, not globals: under `wp eval-file` the script body is not
 * the global scope, so `global $x` inside a function reaches a different
 * variable and every tally comes out zero.
 *
 * @param string $applies TDH_ANY, or TDH_PRODUCTION for production-only.
 *
 * @return array{0:int,1:int,2:int} passed, failed, skipped
 */
function check( string $label = '', ?bool $ok = null, string $detail = '', string $applies = TDH_ANY, string $fix = '' ): array {

	static $pass = 0;
	static $fail = 0;
	static $skip = 0;

	if ( '' === $label ) {
		return [ $pass, $fail, $skip ];
	}

	$is_production = 'production' === wp_get_environment_type();

	if ( TDH_PRODUCTION === $applies && ! $is_production ) {
		++$skip;
		printf( "  --    %-46s (production only)\n", $label );

		return [ $pass, $fail, $skip ];
	}

	if ( $ok ) {
		++$pass;
		printf( "  ok    %-46s %s\n", $label, $detail );

		return [ $pass, $fail, $skip ];
	}

	++$fail;
	printf( "  FAIL  %-46s %s\n", $label, $detail );

	if ( '' !== $fix ) {
		printf( "        %s\n", $fix );
	}

	return [ $pass, $fail, $skip ];
}

function heading( string $text ): void {
	printf( "\n%s\n", $text );
}

$env           = wp_get_environment_type();
$is_production = 'production' === $env;

printf( "\n  ThirtyDayHomes security baseline\n" );
printf( "  %s\n", str_repeat( '=', 68 ) );
printf( "  site        %s\n", home_url() );
printf( "  environment %s\n", $env );
printf( "  checked     %s UTC\n", gmdate( 'Y-m-d H:i' ) );
printf( "  %s\n", str_repeat( '=', 68 ) );

/* -------------------------------------------------------------------------
 * Versions
 * ---------------------------------------------------------------------- */

heading( '=== versions ===' );

global $wp_version;

check( 'PHP is 8.1 or newer', version_compare( PHP_VERSION, '8.1', '>=' ), PHP_VERSION );

/*
 * Core updates. get_core_updates() needs the update check to have run; on a
 * fresh CLI call it may be empty, which is not the same as "up to date".
 */
wp_version_check();
$core = function_exists( 'get_core_updates' ) ? get_core_updates() : [];
$core_status = $core[0]->response ?? 'unknown';

check(
	'WordPress core is current',
	'latest' === $core_status || 'unknown' === $core_status,
	$wp_version . ( 'unknown' === $core_status ? ' (update check unavailable)' : '' ),
	TDH_ANY,
	'Dashboard → Updates.'
);

wp_update_plugins();
$plugin_updates = get_site_transient( 'update_plugins' );
$stale          = isset( $plugin_updates->response ) ? count( (array) $plugin_updates->response ) : 0;

check(
	'no plugin is waiting on a security update',
	0 === $stale,
	$stale ? $stale . ' plugin(s) out of date' : 'all current',
	TDH_ANY,
	'Plugins → Installed Plugins. An out-of-date plugin is the most common way a WordPress site is taken over.'
);

/* -------------------------------------------------------------------------
 * Transport
 * ---------------------------------------------------------------------- */

heading( '=== transport ===' );

check(
	'the site is served over https',
	str_starts_with( home_url(), 'https://' ),
	home_url(),
	TDH_PRODUCTION,
	'Settings → General, and a redirect in .htaccess. Passwords and Stripe keys cross this connection.'
);

check(
	'the admin is forced over https',
	defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN,
	defined( 'FORCE_SSL_ADMIN' ) ? 'yes' : 'not set',
	TDH_PRODUCTION,
	"Add to wp-config.php:  define( 'FORCE_SSL_ADMIN', true );"
);

/* -------------------------------------------------------------------------
 * Hardening
 * ---------------------------------------------------------------------- */

heading( '=== hardening ===' );

check(
	'the file editor is disabled in wp-admin',
	defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
	defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ? 'yes' : 'NOT SET',
	TDH_ANY,
	"Add to wp-config.php:  define( 'DISALLOW_FILE_EDIT', true );  — without it, anyone who reaches an admin session can edit plugin PHP in the browser and own the server."
);

check(
	'debug output is not shown to visitors',
	! ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ),
	defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ? 'ON — errors leak paths and queries' : 'off',
	TDH_PRODUCTION,
	"define( 'WP_DEBUG_DISPLAY', false );"
);

check(
	'the environment is declared',
	'production' === $env || 'local' === $env || 'staging' === $env,
	$env,
	TDH_ANY,
	"Set WP_ENVIRONMENT_TYPE in wp-config.php. TDH\\Mail refuses to capture mail in production, and the demo bar refuses to render there — both depend on this being right."
);

check(
	'demo mode is off',
	! ( defined( 'TDH_DEMO_MODE' ) && TDH_DEMO_MODE ),
	defined( 'TDH_DEMO_MODE' ) && TDH_DEMO_MODE ? 'ON — the persona switcher is showing' : 'off',
	TDH_PRODUCTION,
	'Remove TDH_DEMO_MODE from wp-config.php.'
);

check(
	'XML-RPC is not left wide open',
	! apply_filters( 'xmlrpc_enabled', true ) || has_filter( 'xmlrpc_enabled' ),
	apply_filters( 'xmlrpc_enabled', true ) ? 'enabled' : 'disabled',
	TDH_PRODUCTION,
	"xmlrpc.php allows password guessing at hundreds of attempts per request, past any login throttle. Disable it at the host or with: add_filter( 'xmlrpc_enabled', '__return_false' );"
);

/* -------------------------------------------------------------------------
 * Accounts
 * ---------------------------------------------------------------------- */

heading( '=== accounts ===' );

$admins = get_users( [ 'role' => 'administrator' ] );

check(
	'the administrator list is short',
	count( $admins ) <= 3,
	count( $admins ) . ' account(s)',
	TDH_ANY,
	'Every administrator is a full compromise of the site. Demote anyone who does not need it.'
);

foreach ( $admins as $a ) {
	printf( "          %s <%s>\n", $a->user_login, $a->user_email );
}

$named_admin = get_user_by( 'login', 'admin' );

check(
	'there is no account literally named "admin"',
	! $named_admin,
	$named_admin ? 'there is one — half the password guessing on the internet starts here' : 'good',
	TDH_ANY,
	'Create a new administrator under a different name, sign in as it, then delete this one and reassign its content. Renaming is not enough on its own, but it removes the free half of every guess.'
);

/*
 * Anyone can register would let a stranger take a role on the site. The
 * marketplace has its own registration, which assigns tdh_landlord
 * deliberately — this option is WordPress's own, and it is not needed.
 */
check(
	'open WordPress registration is off',
	! get_option( 'users_can_register' ),
	get_option( 'users_can_register' ) ? 'ON — anyone can create an account at wp-login.php' : 'off',
	TDH_ANY,
	'Settings → General → Membership. The site has its own landlord registration; this one bypasses it.'
);

$default_role = (string) get_option( 'default_role' );

check(
	'the default role is not privileged',
	in_array( $default_role, [ 'subscriber', 'tdh_landlord' ], true ),
	$default_role,
	TDH_ANY,
	'Settings → General → New User Default Role.'
);

/* -------------------------------------------------------------------------
 * Our own protections
 * ---------------------------------------------------------------------- */

heading( '=== the marketplace’s own protections ===' );

check( 'login throttling is loaded', class_exists( 'TDH\\Accounts' ), 'TDH\\Accounts' );
check( 'listing visibility rules are loaded', class_exists( 'TDH\\Visibility' ), 'TDH\\Visibility' );
check( 'the contact form spam controls are loaded', class_exists( 'TDH\\Contact' ), 'TDH\\Contact' );

$login = get_page_by_path( 'login' );

check(
	'account pages are kept out of search results',
	$login && get_post_meta( $login->ID, '_tdh_noindex', true ),
	$login ? 'noindex set' : 'no login page found'
);

check(
	'search engines are discouraged before launch',
	! get_option( 'blog_public' ),
	get_option( 'blog_public' ) ? 'INDEXABLE' : 'discouraged',
	TDH_ANY,
	'Settings → Reading. Right for now: the site serves draft copy and placeholder legal pages, and an indexed placeholder outlives the fix. Turn this OFF on launch day.'
);

/*
 * The inquiry post type holds names, email addresses, phone numbers and
 * messages. It must never be public or in REST.
 */
$inquiry = get_post_type_object( 'tdh_inquiry' );

check(
	'inquiries are not publicly queryable',
	$inquiry && ! $inquiry->public && ! $inquiry->publicly_queryable,
	$inquiry ? 'private' : 'post type missing',
	TDH_ANY,
	'These records hold personal data.'
);

check(
	'inquiries are not exposed over REST',
	$inquiry && ! $inquiry->show_in_rest,
	$inquiry && $inquiry->show_in_rest ? 'EXPOSED' : 'not in REST'
);

/* -------------------------------------------------------------------------
 * Secrets
 * ---------------------------------------------------------------------- */

heading( '=== secrets ===' );

if ( class_exists( 'TDH\\Billing\\Stripe' ) ) {

	$mode = TDH\Billing\Stripe::mode();

	check(
		'Stripe is in test mode until launch',
		'test' === $mode,
		$mode . ' mode',
		TDH_ANY,
		'Live mode charges real cards. Switch it on the Payments screen only when the client says so.'
	);

	if ( $is_production ) {
		check(
			'the live secret key is in wp-config, not the database',
			TDH\Billing\Stripe::is_locked( 'live', 'secret' ),
			TDH\Billing\Stripe::is_locked( 'live', 'secret' ) ? 'wp-config constant' : 'stored in the database',
			TDH_PRODUCTION,
			"define( 'TDH_STRIPE_LIVE_SECRET', 'sk_live_…' ); — a database row travels in every backup and every export."
		);
	}
}

check(
	'the security keys in wp-config are not the defaults',
	defined( 'AUTH_KEY' ) && ! str_contains( (string) AUTH_KEY, 'put your unique phrase here' ),
	defined( 'AUTH_KEY' ) ? 'set' : 'MISSING',
	TDH_ANY,
	'Regenerate at https://api.wordpress.org/secret-key/1.1/salt/ — these sign every session cookie.'
);

/*
 * A NOTE, not a check, and deliberately so.
 *
 * The default `wp_` prefix is on every hardening listicle, but changing it on
 * a live site means rewriting serialised option values and user meta keys and
 * risks breaking the site for a benefit that stops at "an attacker has to
 * read one extra error message". Failing a check on something this file then
 * tells you not to fix is exactly the crying-wolf that makes people stop
 * reading these reports.
 */
printf(
	"  note  %-46s %s\n",
	'database table prefix',
	(string) ( $GLOBALS['table_prefix'] ?? '?' ) . ( 'wp_' === ( $GLOBALS['table_prefix'] ?? '' ) ? ' (the default — obscurity only, not worth changing on a live site)' : '' )
);

/* -------------------------------------------------------------------------
 * Mail
 * ---------------------------------------------------------------------- */

heading( '=== mail ===' );

check(
	'the sender is branded, not "WordPress"',
	'WordPress' !== apply_filters( 'wp_mail_from_name', 'WordPress' ),
	(string) apply_filters( 'wp_mail_from_name', 'WordPress' )
);

check(
	'mail is actually sent in production',
	! Mail::capturing(),
	Mail::capturing() ? 'CAPTURING — nothing is leaving this machine' : 'sending',
	TDH_PRODUCTION,
	'TDH\\Mail refuses to capture in production, so this failing means the environment is not declared as production.'
);

if ( class_exists( 'TDH\\Smtp' ) ) {
	check(
		'outgoing mail is authenticated',
		Smtp::is_ready(),
		Smtp::is_ready() ? Smtp::setting( 'host' ) : 'not configured — mail goes out unauthenticated',
		TDH_PRODUCTION,
		'Listings → Email delivery. Unauthenticated mail from a shared host is usually filed as spam, and password resets are the first casualty.'
	);

	foreach ( Smtp::sender_problems() as $problem ) {
		check( 'the From address is deliverable', false, $problem, TDH_PRODUCTION );
	}
}

/* -------------------------------------------------------------------------
 * Backups
 * ---------------------------------------------------------------------- */

heading( '=== backups ===' );

/*
 * Read the receipt tools/backup.sh leaves behind. Asking the filesystem
 * rather than asking a person is the point: "yes we have backups" is the
 * single most commonly believed untrue thing about any website.
 */
$receipt = WP_CONTENT_DIR . '/uploads/.tdh-last-backup';
$last    = is_readable( $receipt ) ? (int) trim( (string) file_get_contents( $receipt ) ) : 0;
$age     = $last ? ( time() - $last ) : 0;

check(
	'a backup has run',
	$last > 0,
	$last ? 'last at ' . gmdate( 'Y-m-d H:i', $last ) . ' UTC' : 'no record of one ever running',
	TDH_PRODUCTION,
	'Install the cron job — see tools/BACKUPS.md.'
);

if ( $last ) {

	/*
	 * A timestamp in the FUTURE is not a recent backup, it is a clock
	 * problem — and a clock problem is worth knowing about on its own,
	 * because it also skews cron scheduling, transient expiry and every
	 * "how long ago" on the site. Without this the check reads the negative
	 * age as comfortably under the threshold and reports all clear.
	 */
	check(
		'the last backup is recent',
		$age >= 0 && $age < 2 * DAY_IN_SECONDS,
		$age < 0
			? 'dated ' . round( abs( $age ) / HOUR_IN_SECONDS ) . ' hours in the FUTURE — the server clock or timezone is wrong'
			: round( $age / HOUR_IN_SECONDS ) . ' hours ago',
		TDH_PRODUCTION,
		'The cron job has stopped running, or the clock is wrong. A backup nobody checks is a backup nobody has.'
	);
}

/* -------------------------------------------------------------------------
 * Result
 * ---------------------------------------------------------------------- */

[ $pass, $fail, $skip ] = check();

printf( "\n  %s\n", str_repeat( '=', 68 ) );
printf(
	"  %s   %d passed, %d failed, %d not applicable here\n",
	$fail ? 'ATTENTION' : 'CLEAN    ',
	$pass,
	$fail,
	$skip
);
printf( "  %s\n\n", str_repeat( '=', 68 ) );

if ( ! $is_production ) {
	printf( "  This is a %s environment. The production-only checks above were\n", $env );
	printf( "  skipped, so a clean run here does NOT mean the live site is clean.\n" );
	printf( "  Run this again on the server before launch.\n\n" );
}

if ( $fail && defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::halt( 1 );
}
