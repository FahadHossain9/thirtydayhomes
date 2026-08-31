<?php
/**
 * The security baseline, as data.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Every check the site makes about its own safety, in one list.
 *
 * ─── WHY THE CHECKS ARE DATA AND NOT PRINT STATEMENTS ──────────────────────
 *
 * This began as a CLI script that printed as it went. That made it something
 * only a developer with SSH could run — and the one instruction it gives is
 * "run this again after any change", which is exactly the thing a person
 * without a terminal will therefore never do.
 *
 * So the checks return results and two thin things render them: the CLI
 * script for a deploy log, and an admin screen for everyone else. Same
 * reasoning as Render/shortcode/widget elsewhere in this plugin — one
 * implementation, several presentations, so they cannot disagree.
 *
 * ─── EVERY CHECK KNOWS WHERE IT APPLIES ────────────────────────────────────
 *
 * The first version reported "not https", "not production" and "mail is
 * captured" as failures on a laptop, where all three are correct. A report
 * that cries wolf on every run is one people scroll past, and the real
 * finding goes with it. A check that does not apply here is SKIPPED, with
 * the reason, so a FAIL always means something.
 */
final class Security_Baseline {

	public const PASS = 'pass';
	public const FAIL = 'fail';
	public const SKIP = 'skip';
	public const NOTE = 'note';

	/** Applies everywhere. */
	private const ANY = 'any';

	/** Only meaningful on the live site. */
	private const PRODUCTION = 'production';

	/** @var array<int,array<string,string>> */
	private array $results = [];

	private string $group = '';

	/**
	 * Run every check.
	 *
	 * @return array<int,array{group:string,label:string,status:string,detail:string,fix:string}>
	 */
	public function run(): array {

		$this->results = [];

		$this->versions();
		$this->transport();
		$this->hardening();
		$this->accounts();
		$this->protections();
		$this->secrets();
		$this->mail();
		$this->backups();

		return $this->results;
	}

	/**
	 * @param array<int,array<string,string>> $results
	 *
	 * @return array{pass:int,fail:int,skip:int,note:int}
	 */
	public static function summary( array $results ): array {

		$counts = [ self::PASS => 0, self::FAIL => 0, self::SKIP => 0, self::NOTE => 0 ];

		foreach ( $results as $r ) {
			$status = (string) ( $r['status'] ?? '' );

			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}

		return [
			'pass' => $counts[ self::PASS ],
			'fail' => $counts[ self::FAIL ],
			'skip' => $counts[ self::SKIP ],
			'note' => $counts[ self::NOTE ],
		];
	}

	public static function is_production(): bool {
		return 'production' === wp_get_environment_type();
	}

	/* ---------------------------------------------------------------------
	 * Collecting
	 * ------------------------------------------------------------------ */

	private function group( string $name ): void {
		$this->group = $name;
	}

	private function check( string $label, bool $ok, string $detail = '', string $applies = self::ANY, string $fix = '' ): void {

		if ( self::PRODUCTION === $applies && ! self::is_production() ) {
			$this->add( $label, self::SKIP, __( 'only meaningful on the live site', 'thirtydayhomes' ) );
			return;
		}

		$this->add( $label, $ok ? self::PASS : self::FAIL, $detail, $ok ? '' : $fix );
	}

	private function note( string $label, string $detail ): void {
		$this->add( $label, self::NOTE, $detail );
	}

	private function add( string $label, string $status, string $detail = '', string $fix = '' ): void {
		$this->results[] = [
			'group'  => $this->group,
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
			'fix'    => $fix,
		];
	}

	/* ---------------------------------------------------------------------
	 * The checks
	 * ------------------------------------------------------------------ */

	private function versions(): void {

		$this->group( __( 'Versions', 'thirtydayhomes' ) );

		global $wp_version;

		$this->check( __( 'PHP is 8.1 or newer', 'thirtydayhomes' ), version_compare( PHP_VERSION, '8.1', '>=' ), PHP_VERSION );

		/*
		 * get_core_updates() needs the update check to have run. On a fresh
		 * CLI call it can be empty, which is not the same as "up to date" —
		 * so an unknown answer is not reported as a failure.
		 */
		wp_version_check();
		$core   = function_exists( 'get_core_updates' ) ? get_core_updates() : [];
		$status = $core[0]->response ?? 'unknown';

		$this->check(
			__( 'WordPress core is current', 'thirtydayhomes' ),
			'latest' === $status || 'unknown' === $status,
			$wp_version . ( 'unknown' === $status ? __( ' (update check unavailable)', 'thirtydayhomes' ) : '' ),
			self::ANY,
			__( 'Dashboard → Updates.', 'thirtydayhomes' )
		);

		wp_update_plugins();
		$updates = get_site_transient( 'update_plugins' );
		$stale   = isset( $updates->response ) ? count( (array) $updates->response ) : 0;

		$this->check(
			__( 'no plugin is waiting on a security update', 'thirtydayhomes' ),
			0 === $stale,
			$stale
				/* translators: %d: number of plugins */
				? sprintf( __( '%d out of date', 'thirtydayhomes' ), $stale )
				: __( 'all current', 'thirtydayhomes' ),
			self::ANY,
			__( 'Plugins → Installed Plugins. An out-of-date plugin is the most common way a WordPress site is taken over.', 'thirtydayhomes' )
		);
	}

	private function transport(): void {

		$this->group( __( 'Transport', 'thirtydayhomes' ) );

		$this->check(
			__( 'the site is served over https', 'thirtydayhomes' ),
			str_starts_with( home_url(), 'https://' ),
			home_url(),
			self::PRODUCTION,
			__( 'Settings → General, and a redirect at the host. Passwords and Stripe keys cross this connection.', 'thirtydayhomes' )
		);

		$this->check(
			__( 'the admin is forced over https', 'thirtydayhomes' ),
			defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN,
			defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN ? __( 'yes', 'thirtydayhomes' ) : __( 'not set', 'thirtydayhomes' ),
			self::PRODUCTION,
			"define( 'FORCE_SSL_ADMIN', true );"
		);
	}

	private function hardening(): void {

		$this->group( __( 'Hardening', 'thirtydayhomes' ) );

		$this->check(
			__( 'the file editor is disabled in wp-admin', 'thirtydayhomes' ),
			defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
			defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ? __( 'yes', 'thirtydayhomes' ) : __( 'NOT SET', 'thirtydayhomes' ),
			self::ANY,
			__( "define( 'DISALLOW_FILE_EDIT', true );  — without it, anyone who reaches an admin session can edit plugin PHP in the browser and take the server.", 'thirtydayhomes' )
		);

		$this->check(
			__( 'debug output is not shown to visitors', 'thirtydayhomes' ),
			! ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ),
			defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY
				? __( 'ON — errors leak file paths and queries to visitors', 'thirtydayhomes' )
				: __( 'off', 'thirtydayhomes' ),
			self::PRODUCTION,
			"define( 'WP_DEBUG_DISPLAY', false );"
		);

		$env = wp_get_environment_type();

		$this->check(
			__( 'the environment is declared', 'thirtydayhomes' ),
			in_array( $env, [ 'production', 'staging', 'local', 'development' ], true ),
			$env,
			self::ANY,
			__( 'WP_ENVIRONMENT_TYPE in wp-config.php. Mail capture and the demo bar both read it, and both fail open if it is wrong.', 'thirtydayhomes' )
		);

		$this->check(
			__( 'demo mode is off', 'thirtydayhomes' ),
			! ( defined( 'TDH_DEMO_MODE' ) && TDH_DEMO_MODE ),
			defined( 'TDH_DEMO_MODE' ) && TDH_DEMO_MODE ? __( 'ON — the persona switcher is showing', 'thirtydayhomes' ) : __( 'off', 'thirtydayhomes' ),
			self::PRODUCTION,
			__( 'Remove TDH_DEMO_MODE from wp-config.php.', 'thirtydayhomes' )
		);

		$this->check(
			__( 'XML-RPC is not left wide open', 'thirtydayhomes' ),
			! apply_filters( 'xmlrpc_enabled', true ),
			apply_filters( 'xmlrpc_enabled', true ) ? __( 'enabled', 'thirtydayhomes' ) : __( 'disabled', 'thirtydayhomes' ),
			self::PRODUCTION,
			__( 'xmlrpc.php allows hundreds of password guesses in a single request, straight past our own login throttle.', 'thirtydayhomes' )
		);
	}

	private function accounts(): void {

		$this->group( __( 'Accounts', 'thirtydayhomes' ) );

		$admins = get_users( [ 'role' => 'administrator' ] );

		$this->check(
			__( 'the administrator list is short', 'thirtydayhomes' ),
			count( $admins ) <= 3,
			implode( ', ', array_map( static fn( $u ) => $u->user_login, $admins ) ),
			self::ANY,
			__( 'Every administrator is a complete compromise of the site. Demote anyone who does not need it.', 'thirtydayhomes' )
		);

		$named = get_user_by( 'login', 'admin' );

		$this->check(
			__( 'there is no account literally named "admin"', 'thirtydayhomes' ),
			! $named,
			$named ? __( 'there is one', 'thirtydayhomes' ) : __( 'good', 'thirtydayhomes' ),
			self::ANY,
			__( 'Half the password guessing on the internet starts with that username. Create a replacement administrator, sign in as it, then delete this one and reassign its content.', 'thirtydayhomes' )
		);

		$this->check(
			__( 'open WordPress registration is off', 'thirtydayhomes' ),
			! get_option( 'users_can_register' ),
			get_option( 'users_can_register' ) ? __( 'ON — anyone can create an account at wp-login.php', 'thirtydayhomes' ) : __( 'off', 'thirtydayhomes' ),
			self::ANY,
			__( 'Settings → General → Membership. The site has its own landlord registration; this one goes around it.', 'thirtydayhomes' )
		);

		$role = (string) get_option( 'default_role' );

		$this->check(
			__( 'the default role is not privileged', 'thirtydayhomes' ),
			in_array( $role, [ 'subscriber', 'tdh_landlord' ], true ),
			$role,
			self::ANY,
			__( 'Settings → General → New User Default Role.', 'thirtydayhomes' )
		);
	}

	private function protections(): void {

		$this->group( __( 'The marketplace’s own protections', 'thirtydayhomes' ) );

		$this->check( __( 'login throttling is loaded', 'thirtydayhomes' ), class_exists( Accounts::class ) );
		$this->check( __( 'listing visibility rules are loaded', 'thirtydayhomes' ), class_exists( Visibility::class ) );
		$this->check( __( 'contact form spam controls are loaded', 'thirtydayhomes' ), class_exists( Contact::class ) );
		$this->check(
			__( 'account pages are kept out of the page cache', 'thirtydayhomes' ),
			class_exists( No_Cache::class ),
			'',
			self::ANY,
			__( 'Without this a cached /register/ swallows every validation error, and a cached /account/ can serve one landlord’s dashboard to another.', 'thirtydayhomes' )
		);

		$login = get_page_by_path( 'login' );

		$this->check(
			__( 'account pages are kept out of search results', 'thirtydayhomes' ),
			$login && get_post_meta( $login->ID, '_tdh_noindex', true ),
			$login ? __( 'noindex set', 'thirtydayhomes' ) : __( 'no login page found', 'thirtydayhomes' )
		);

		$this->check(
			__( 'search engines are discouraged before launch', 'thirtydayhomes' ),
			! get_option( 'blog_public' ),
			get_option( 'blog_public' ) ? __( 'INDEXABLE', 'thirtydayhomes' ) : __( 'discouraged', 'thirtydayhomes' ),
			self::ANY,
			__( 'Settings → Reading. Right for now — the site serves draft copy and placeholder legal pages, and an indexed placeholder outlives the fix. Turn it off on launch day.', 'thirtydayhomes' )
		);

		$inquiry = get_post_type_object( Post_Types::INQUIRY );

		$this->check(
			__( 'inquiries are not publicly queryable', 'thirtydayhomes' ),
			$inquiry && ! $inquiry->public && ! $inquiry->publicly_queryable,
			__( 'these records hold personal data', 'thirtydayhomes' )
		);

		$this->check(
			__( 'inquiries are not exposed over REST', 'thirtydayhomes' ),
			$inquiry && ! $inquiry->show_in_rest,
			$inquiry && $inquiry->show_in_rest ? __( 'EXPOSED', 'thirtydayhomes' ) : __( 'not in REST', 'thirtydayhomes' )
		);
	}

	private function secrets(): void {

		$this->group( __( 'Secrets', 'thirtydayhomes' ) );

		if ( class_exists( Billing\Stripe::class ) ) {

			$mode = Billing\Stripe::mode();

			$this->check(
				__( 'Stripe is in test mode until launch', 'thirtydayhomes' ),
				'test' === $mode,
				/* translators: %s: test or live */
				sprintf( __( '%s mode', 'thirtydayhomes' ), $mode ),
				self::ANY,
				__( 'Live mode charges real cards. Switch it on the Payments screen only when the client says so.', 'thirtydayhomes' )
			);

			$stored = '' !== Billing\Stripe::credential( 'secret', 'live' );

			if ( $stored ) {
				$this->check(
					__( 'the live secret key is in wp-config, not the database', 'thirtydayhomes' ),
					Billing\Stripe::is_locked( 'live', 'secret' ),
					Billing\Stripe::is_locked( 'live', 'secret' )
						? __( 'wp-config constant', 'thirtydayhomes' )
						: __( 'stored in the database', 'thirtydayhomes' ),
					self::PRODUCTION,
					__( "define( 'TDH_STRIPE_LIVE_SECRET', 'sk_live_…' ); — a database row travels in every backup and every export. Before launch, clearing it is just as good: Payments → Live, or delete the tdh_stripe_live_secret option.", 'thirtydayhomes' )
				);
			}
		}

		$this->check(
			__( 'the wp-config security keys are not the defaults', 'thirtydayhomes' ),
			defined( 'AUTH_KEY' ) && ! str_contains( (string) AUTH_KEY, 'put your unique phrase here' ),
			defined( 'AUTH_KEY' ) ? __( 'set', 'thirtydayhomes' ) : __( 'MISSING', 'thirtydayhomes' ),
			self::ANY,
			__( 'Regenerate at api.wordpress.org/secret-key/1.1/salt/ — these sign every session cookie.', 'thirtydayhomes' )
		);

		/*
		 * A note, not a check. The default wp_ prefix is on every hardening
		 * listicle, but changing it on a live site means rewriting serialised
		 * option values and user meta keys for a benefit that stops at "an
		 * attacker reads one extra error message". Failing on something this
		 * file then says not to fix is the crying-wolf it exists to avoid.
		 */
		$prefix = (string) ( $GLOBALS['table_prefix'] ?? '?' );

		$this->note(
			__( 'database table prefix', 'thirtydayhomes' ),
			'wp_' === $prefix
				? __( 'wp_ — the default. Obscurity only, and not worth changing on a live site.', 'thirtydayhomes' )
				: $prefix
		);
	}

	private function mail(): void {

		$this->group( __( 'Mail', 'thirtydayhomes' ) );

		$this->check(
			__( 'the sender is branded, not "WordPress"', 'thirtydayhomes' ),
			'WordPress' !== apply_filters( 'wp_mail_from_name', 'WordPress' ),
			(string) apply_filters( 'wp_mail_from_name', 'WordPress' )
		);

		$this->check(
			__( 'mail is actually sent, not captured', 'thirtydayhomes' ),
			! Mail::capturing(),
			Mail::capturing() ? __( 'CAPTURING — nothing is leaving this machine', 'thirtydayhomes' ) : __( 'sending', 'thirtydayhomes' ),
			self::PRODUCTION
		);

		if ( ! class_exists( Smtp::class ) ) {
			return;
		}

		$this->check(
			__( 'outgoing mail is authenticated', 'thirtydayhomes' ),
			Smtp::is_ready(),
			Smtp::is_ready() ? Smtp::setting( 'host' ) : __( 'not configured — mail goes out unauthenticated', 'thirtydayhomes' ),
			self::PRODUCTION,
			__( 'Listings → Email delivery. Unauthenticated mail from a shared host is usually filed as spam, and password resets are the first casualty.', 'thirtydayhomes' )
		);

		foreach ( Smtp::sender_problems() as $problem ) {
			$this->check( __( 'the From address is deliverable', 'thirtydayhomes' ), false, $problem, self::PRODUCTION );
		}
	}

	private function backups(): void {

		$this->group( __( 'Backups', 'thirtydayhomes' ) );

		/*
		 * Read the receipt tools/backup.sh leaves behind. Asking the
		 * filesystem rather than asking a person is the point: "yes, we have
		 * backups" is the most commonly believed untrue thing about any
		 * website.
		 */
		$receipt = WP_CONTENT_DIR . '/uploads/.tdh-last-backup';
		$last    = is_readable( $receipt ) ? (int) trim( (string) file_get_contents( $receipt ) ) : 0;

		$this->check(
			__( 'a backup has run', 'thirtydayhomes' ),
			$last > 0,
			$last
				/* translators: %s: date and time */
				? sprintf( __( 'last at %s UTC', 'thirtydayhomes' ), gmdate( 'Y-m-d H:i', $last ) )
				: __( 'no record of one ever running', 'thirtydayhomes' ),
			self::PRODUCTION,
			__( 'Install the cron job — see tools/BACKUPS.md.', 'thirtydayhomes' )
		);

		if ( ! $last ) {
			return;
		}

		$age = time() - $last;

		/*
		 * A timestamp in the FUTURE is a clock problem, not a fresh backup —
		 * and a wrong clock also skews cron scheduling and transient expiry.
		 * Without this the negative age reads as comfortably recent.
		 */
		$this->check(
			__( 'the last backup is recent', 'thirtydayhomes' ),
			$age >= 0 && $age < 2 * DAY_IN_SECONDS,
			$age < 0
				/* translators: %d: hours */
				? sprintf( __( 'dated %d hours in the FUTURE — the server clock is wrong', 'thirtydayhomes' ), (int) round( abs( $age ) / HOUR_IN_SECONDS ) )
				/* translators: %d: hours */
				: sprintf( __( '%d hours ago', 'thirtydayhomes' ), (int) round( $age / HOUR_IN_SECONDS ) ),
			self::PRODUCTION,
			__( 'The cron job has stopped, or the clock is wrong. A backup nobody checks is a backup nobody has.', 'thirtydayhomes' )
		);
	}
}
