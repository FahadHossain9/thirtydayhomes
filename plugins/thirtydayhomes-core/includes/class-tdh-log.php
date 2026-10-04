<?php
/**
 * The event and failure log.
 *
 * PLUGIN, not theme. "What happened, to whom, and what came back" is the
 * one record that turns a bug report into a fix without a reproduction
 * session, and it has to survive a theme change.
 *
 * ─── WHAT IT IS ──────────────────────────────────────────────────────────
 *
 * One table (`{prefix}tdh_log`) and one writer. Every important step of
 * every flow writes a line: sign-ins refused, homes submitted and approved,
 * addresses looked up, emails sent or failed, Stripe events received or
 * refused. Staff read it on the portal's Logs screen, one tab per feature,
 * with the masked context behind "View".
 *
 * ─── HOW LINES GET HERE ──────────────────────────────────────────────────
 *
 * Mostly by listening. The features already fire actions at their turning
 * points (`tdh_listing_approved`, `tdh_membership_changed`, WordPress's own
 * `wp_login_failed` …), so this class subscribes to those and owns the
 * wording. A feature only fires an event it already fires; the sentence
 * lives here. Anything can also call `Log::info()` and friends directly.
 *
 * ─── WHAT NEVER GOES IN ──────────────────────────────────────────────────
 *
 * Secrets, and unmasked personal data. Context keys that look like a key,
 * token or password are replaced with "[hidden]"; values shaped like an
 * API key are too; emails and phone numbers are masked. Whatever is passed
 * in, the row cannot leak it — the caller does not get to be careless.
 *
 * ─── IT NEVER GETS IN THE WAY ────────────────────────────────────────────
 *
 * A write that fails is swallowed and counted. The log describing an
 * action must never be the reason the action failed.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Writer, reader, retention and the subscribers that feed it.
 */
final class Log {

	public const DEBUG    = 'debug';
	public const INFO     = 'info';
	public const WARNING  = 'warning';
	public const ERROR    = 'error';
	public const CRITICAL = 'critical';

	/** Rows older than this are deleted by the daily job. */
	public const RETENTION_DAYS = 90;

	/** The scheduled purge. */
	public const CRON = 'tdh_log_purge';

	/** When the purge last ran, and how many rows it removed. */
	public const OPTION_PURGED = 'tdh_log_purged';

	/** Writes that failed, so System status can say so. */
	public const TRANSIENT_FAILED = 'tdh_log_failed_writes';

	public const PER_PAGE    = 50;
	private const PURGE_BATCH = 1000;
	private const MESSAGE_MAX = 255;

	/** Levels in order of seriousness. */
	private const LEVELS = [ self::DEBUG, self::INFO, self::WARNING, self::ERROR, self::CRITICAL ];

	/** One id per request, so its lines can be read together. */
	private static string $request_id = '';

	/* ---------------------------------------------------------------------
	 * Hooks
	 * ------------------------------------------------------------------ */

	public function register(): void {

		// Retention.
		add_action( 'init', [ $this, 'schedule' ] );
		add_action( self::CRON, [ $this, 'purge' ] );

		// Sign-in.
		add_action( 'wp_login_failed', [ $this, 'on_login_failed' ], 10, 2 );
		add_action( 'tdh_login_throttled', [ $this, 'on_login_throttled' ] );
		add_action( 'wp_login', [ $this, 'on_login' ], 10, 2 );

		// Members.
		add_action( 'user_register', [ $this, 'on_user_registered' ] );
		add_action( 'profile_update', [ $this, 'on_user_updated' ], 10, 2 );
		add_action( 'deleted_user', [ $this, 'on_user_deleted' ], 10, 3 );

		// Listings.
		add_action( 'tdh_listing_submitted', [ $this, 'on_listing_submitted' ], 10, 2 );
		add_action( 'tdh_listing_changes_submitted', [ $this, 'on_listing_changes_submitted' ], 10, 3 );
		add_action( 'tdh_listing_approved', [ $this, 'on_listing_approved' ] );
		add_action( 'tdh_listing_changes_requested', [ $this, 'on_listing_changes_requested' ] );
		add_action( 'tdh_listing_paused', [ $this, 'on_listing_paused' ] );
		add_action( 'tdh_listing_resumed', [ $this, 'on_listing_resumed' ] );
		add_action( 'tdh_listing_deleted', [ $this, 'on_listing_deleted' ] );

		// Locations and facilities.
		add_action( 'tdh_geocoded', [ $this, 'on_geocoded' ], 10, 5 );
		add_action( 'save_post_' . Post_Types::FACILITY, [ $this, 'on_facility_saved' ], 20, 3 );
		add_action( 'deleted_post', [ $this, 'on_post_deleted' ], 10, 2 );

		// Email.
		add_action( 'wp_mail_failed', [ $this, 'on_mail_failed' ] );
		add_action( 'wp_mail_succeeded', [ $this, 'on_mail_sent' ] );
		add_action( 'tdh_mail_captured', [ $this, 'on_mail_captured' ], 10, 2 );

		// Inquiries.
		add_action( 'tdh_contact_received', [ $this, 'on_contact_received' ] );
		add_action( 'tdh_inquiry_received', [ $this, 'on_inquiry_received' ], 10, 2 );
		add_action( 'tdh_inquiry_refused', [ $this, 'on_inquiry_refused' ], 10, 2 );
		add_action( 'tdh_inquiry_filed', [ $this, 'on_inquiry_filed' ], 10, 2 );

		// Texts (D4). Sms writes its own send/skip/verify lines directly,
		// the way Notifications does for email; this one is fired as an
		// action because it comes from a meta hook that runs whether or not
		// texting is switched on.
		add_action( 'tdh_sms_verification_reset', [ $this, 'on_sms_verification_reset' ] );

		// Payments.
		add_action( 'tdh_stripe_received', [ $this, 'on_stripe_received' ], 10, 3 );
		add_action( 'tdh_stripe_rejected', [ $this, 'on_stripe_rejected' ], 10, 2 );
		add_action( 'tdh_membership_changed', [ $this, 'on_membership_changed' ], 10, 3 );

		// Settings.
		add_action( 'tdh_proximity_settings_saved', [ $this, 'on_proximity_saved' ], 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------ */

	/**
	 * Write one line.
	 *
	 * @param string              $level       One of the level constants.
	 * @param string              $feature     A key from features().
	 * @param string              $event       A short stable code ("approved").
	 * @param string              $message     One sentence a person reads.
	 * @param array<string,mixed> $context     What was attempted and what came
	 *                                         back; scrubbed and masked here.
	 * @param int|null            $user_id     Who; the current user when null.
	 * @param int                 $object_id   The post or user it is about.
	 * @param string              $object_type 'listing', 'facility', 'user' …
	 * @return int The row id, or 0 when the write failed (never an exception).
	 */
	public static function write( string $level, string $feature, string $event, string $message, array $context = [], ?int $user_id = null, int $object_id = 0, string $object_type = '' ): int {

		// A verification run creates and deletes hundreds of fixtures; those
		// are not events on the site. verify.bat and CI set TDH_LOG_SILENT
		// for the suites; the log's own suite clears it for itself.
		if ( getenv( 'TDH_LOG_SILENT' ) ) {
			return 0;
		}

		try {
			global $wpdb;

			$level   = in_array( $level, self::LEVELS, true ) ? $level : self::INFO;
			$feature = array_key_exists( $feature, self::features() ) ? $feature : 'system';
			$message = trim( wp_strip_all_tags( $message ) );

			if ( mb_strlen( $message ) > self::MESSAGE_MAX ) {
				$context['message_full'] = $message;
				$message                 = mb_substr( $message, 0, self::MESSAGE_MAX - 1 ) . '…';
			}

			$row = [
				'created_at'  => current_time( 'mysql', true ),
				'level'       => $level,
				'feature'     => $feature,
				'event'       => mb_substr( sanitize_key( $event ), 0, 60 ),
				'message'     => $message,
				'context'     => $context ? (string) wp_json_encode( self::clean( $context ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : null,
				'user_id'     => null === $user_id ? get_current_user_id() : max( 0, $user_id ),
				'object_type' => mb_substr( sanitize_key( $object_type ), 0, 20 ),
				'object_id'   => max( 0, $object_id ),
				'source'      => self::source(),
				'request_id'  => self::request_id(),
			];

			$ok = $wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			if ( false === $ok ) {
				self::count_failure();
				return 0;
			}

			return (int) $wpdb->insert_id;

		} catch ( \Throwable $e ) {
			self::count_failure();
			return 0;
		}
	}

	/** @param array<string,mixed> $context */
	public static function debug( string $feature, string $event, string $message, array $context = [], int $object_id = 0, string $object_type = '' ): int {
		return self::write( self::DEBUG, $feature, $event, $message, $context, null, $object_id, $object_type );
	}

	/** @param array<string,mixed> $context */
	public static function info( string $feature, string $event, string $message, array $context = [], int $object_id = 0, string $object_type = '' ): int {
		return self::write( self::INFO, $feature, $event, $message, $context, null, $object_id, $object_type );
	}

	/** @param array<string,mixed> $context */
	public static function warning( string $feature, string $event, string $message, array $context = [], int $object_id = 0, string $object_type = '' ): int {
		return self::write( self::WARNING, $feature, $event, $message, $context, null, $object_id, $object_type );
	}

	/** @param array<string,mixed> $context */
	public static function error( string $feature, string $event, string $message, array $context = [], int $object_id = 0, string $object_type = '' ): int {
		return self::write( self::ERROR, $feature, $event, $message, $context, null, $object_id, $object_type );
	}

	/** @param array<string,mixed> $context */
	public static function critical( string $feature, string $event, string $message, array $context = [], int $object_id = 0, string $object_type = '' ): int {
		return self::write( self::CRITICAL, $feature, $event, $message, $context, null, $object_id, $object_type );
	}

	/**
	 * The features, in the order staff look for them. Keys are stable; the
	 * labels are the tabs.
	 *
	 * @return array<string,string>
	 */
	public static function features(): array {

		$features = [
			'listings'   => __( 'Listings', 'thirtydayhomes' ),
			'locations'  => __( 'Locations', 'thirtydayhomes' ),
			'facilities' => __( 'Facilities', 'thirtydayhomes' ),
			'inquiries'  => __( 'Inquiries', 'thirtydayhomes' ),
			'email'      => __( 'Email', 'thirtydayhomes' ),
			'sms'        => __( 'SMS', 'thirtydayhomes' ),
			'payments'   => __( 'Payments', 'thirtydayhomes' ),
			'members'    => __( 'Members', 'thirtydayhomes' ),
			'signin'     => __( 'Sign-in', 'thirtydayhomes' ),
			'settings'   => __( 'Settings', 'thirtydayhomes' ),
			'system'     => __( 'System', 'thirtydayhomes' ),
		];

		/**
		 * The log's features and their labels.
		 *
		 * @param array<string,string> $features key => label.
		 */
		$filtered = apply_filters( 'tdh_log_features', $features );

		return is_array( $filtered ) && $filtered ? $filtered : $features;
	}

	/**
	 * Level labels, as words.
	 *
	 * @return array<string,string>
	 */
	public static function levels(): array {
		return [
			self::DEBUG    => __( 'Debug', 'thirtydayhomes' ),
			self::INFO     => __( 'Info', 'thirtydayhomes' ),
			self::WARNING  => __( 'Warning', 'thirtydayhomes' ),
			self::ERROR    => __( 'Error', 'thirtydayhomes' ),
			self::CRITICAL => __( 'Critical', 'thirtydayhomes' ),
		];
	}

	/** Where a level ranks; higher is worse. */
	public static function rank( string $level ): int {
		$rank = array_search( $level, self::LEVELS, true );

		return false === $rank ? 1 : (int) $rank;
	}

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'tdh_log';
	}

	/* ---------------------------------------------------------------------
	 * Masking — what a row may never carry
	 * ------------------------------------------------------------------ */

	/** "fahad@example.com" → "f***@example.com". */
	public static function mask_email( string $email ): string {

		$email = trim( $email );
		$at    = strrpos( $email, '@' );

		if ( false === $at || 0 === $at ) {
			return '' === $email ? '' : '***';
		}

		return mb_substr( $email, 0, 1 ) . '***' . mb_substr( $email, $at );
	}

	/** "+1 412 555 1234" → "***1234". */
	public static function mask_phone( string $phone ): string {

		$digits = preg_replace( '/\D+/', '', $phone ) ?? '';

		if ( '' === $digits ) {
			return '' === trim( $phone ) ? '' : '***';
		}

		return '***' . substr( $digits, -4 );
	}

	/**
	 * Scrub and mask a context array, recursively, and make it storable.
	 *
	 * @param array<mixed> $context
	 * @return array<mixed>
	 */
	public static function clean( array $context, int $depth = 0 ): array {

		$clean = [];

		foreach ( $context as $key => $value ) {

			$name = (string) $key;

			if ( preg_match( '/(key|secret|token|password|passwd|pass\b|authorization|signature|cookie|nonce)/i', $name ) ) {
				$clean[ $name ] = '[hidden]';
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $name ] = $depth < 4 ? self::clean( $value, $depth + 1 ) : '[…]';
				continue;
			}

			if ( is_object( $value ) ) {
				$value = $value instanceof \WP_Error
					? $value->get_error_message()
					: ( method_exists( $value, '__toString' ) ? (string) $value : '[' . get_class( $value ) . ']' );
			} elseif ( is_resource( $value ) ) {
				$value = '[resource]';
			} elseif ( is_bool( $value ) || null === $value || is_int( $value ) || is_float( $value ) ) {
				$clean[ $name ] = $value;
				continue;
			}

			$value = (string) $value;

			if ( preg_match( '/^(sk|pk|rk|whsec)_(live|test)_[A-Za-z0-9]+|^AIza[0-9A-Za-z_\-]{30,}|^AC[a-f0-9]{32}$/', $value ) ) {
				$value = '[hidden]';
			} elseif ( preg_match( '/(email|mail|recipient|^to$|sender|from)/i', $name ) && is_email( $value ) ) {
				$value = self::mask_email( $value );
			} elseif ( preg_match( '/(phone|mobile|tel)/i', $name ) ) {
				$value = self::mask_phone( $value );
			} elseif ( preg_match( '/(login|username|user)/i', $name ) && is_email( $value ) ) {
				$value = self::mask_email( $value );
			}

			$clean[ $name ] = mb_strlen( $value ) > 2000 ? mb_substr( $value, 0, 2000 ) . '…' : $value;
		}

		return $clean;
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------ */

	/**
	 * Rows, newest first, with the filters the screen offers.
	 *
	 * @param array<string,mixed> $args feature, min_level, search, since
	 *                                  (unix time), user_id, object_id,
	 *                                  object_type, page, per_page.
	 * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int}
	 */
	public static function query( array $args = [] ): array {

		global $wpdb;

		$where  = [ '1=1' ];
		$values = [];

		// One feature, or several at once: the Logs screen's grouped tabs
		// (G5b) read "Messages" as inquiries, email and SMS together.
		if ( ! empty( $args['feature'] ) ) {
			$features = array_values( array_filter( array_map( 'strval', (array) $args['feature'] ), static fn( string $f ): bool => '' !== $f ) );

			if ( $features ) {
				$where[] = 'feature IN (' . implode( ',', array_fill( 0, count( $features ), '%s' ) ) . ')';
				array_push( $values, ...$features );
			}
		}

		if ( ! empty( $args['min_level'] ) ) {
			$allowed = array_slice( self::LEVELS, self::rank( (string) $args['min_level'] ) );
			$where[] = 'level IN (' . implode( ',', array_fill( 0, count( $allowed ), '%s' ) ) . ')';
			array_push( $values, ...$allowed );
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(message LIKE %s OR event LIKE %s)';
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$values[] = $like;
			$values[] = $like;
		}

		if ( ! empty( $args['since'] ) ) {
			$where[]  = 'created_at >= %s';
			$values[] = gmdate( 'Y-m-d H:i:s', (int) $args['since'] );
		}

		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$values[] = (int) $args['user_id'];
		}

		if ( ! empty( $args['object_id'] ) ) {
			$where[]  = 'object_id = %d';
			$values[] = (int) $args['object_id'];
		}

		if ( ! empty( $args['object_type'] ) ) {
			$where[]  = 'object_type = %s';
			$values[] = (string) $args['object_type'];
		}

		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? self::PER_PAGE ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$table    = self::table();
		$sql      = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql}";
		$total     = (int) $wpdb->get_var( $values ? $wpdb->prepare( $count_sql, $values ) : $count_sql );

		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$page  = min( $page, $pages );

		$rows_sql = "SELECT * FROM {$table} WHERE {$sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $values, [ $per_page, ( $page - 1 ) * $per_page ] ) ), ARRAY_A );
		// phpcs:enable

		return [
			'rows'  => array_map( [ self::class, 'row' ], is_array( $rows ) ? $rows : [] ),
			'total' => $total,
			'page'  => $page,
			'pages' => $pages,
		];
	}

	/**
	 * How many rows at or above a level, per feature, since a time.
	 *
	 * @return array<string,int> feature => count
	 */
	public static function counts( string $min_level = self::ERROR, int $since = 0 ): array {

		global $wpdb;

		$allowed = array_slice( self::LEVELS, self::rank( $min_level ) );
		$values  = $allowed;
		$where   = 'level IN (' . implode( ',', array_fill( 0, count( $allowed ), '%s' ) ) . ')';

		if ( $since > 0 ) {
			$where   .= ' AND created_at >= %s';
			$values[] = gmdate( 'Y-m-d H:i:s', $since );
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT feature, COUNT(*) AS n FROM {$table} WHERE {$where} GROUP BY feature", $values ), ARRAY_A );

		$counts = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$counts[ (string) $row['feature'] ] = (int) $row['n'];
		}

		return $counts;
	}

	/** Errors and worse in the last week — the number on the sidebar. */
	public static function recent_errors(): int {
		return array_sum( self::counts( self::ERROR, time() - WEEK_IN_SECONDS ) );
	}

	/**
	 * The newest row for a feature (and optionally an event).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function latest( string $feature, string $event = '' ): ?array {

		$args = [ 'feature' => $feature, 'per_page' => 1 ];
		$rows = self::query( $args )['rows'];

		if ( '' === $event ) {
			return $rows[0] ?? null;
		}

		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE feature = %s AND event = %s ORDER BY id DESC LIMIT 1", $feature, $event ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? self::row( $row ) : null;
	}

	public static function total(): int {
		global $wpdb;
		$table = self::table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * A stored row, typed, with its context decoded and its time as a
	 * timestamp.
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private static function row( array $raw ): array {

		$context = json_decode( (string) ( $raw['context'] ?? '' ), true );

		return [
			'id'          => (int) $raw['id'],
			'time'        => (int) strtotime( (string) $raw['created_at'] . ' UTC' ),
			'level'       => (string) $raw['level'],
			'feature'     => (string) $raw['feature'],
			'event'       => (string) $raw['event'],
			'message'     => (string) $raw['message'],
			'context'     => is_array( $context ) ? $context : [],
			'user_id'     => (int) $raw['user_id'],
			'object_type' => (string) $raw['object_type'],
			'object_id'   => (int) $raw['object_id'],
			'source'      => (string) $raw['source'],
			'request_id'  => (string) $raw['request_id'],
		];
	}

	/* ---------------------------------------------------------------------
	 * Retention
	 * ------------------------------------------------------------------ */

	public function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Delete rows older than the retention period, in batches, and say so.
	 *
	 * @return int Rows removed.
	 */
	public function purge(): int {

		global $wpdb;

		$table   = self::table();
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS );
		$removed = 0;

		// Bounded: a table that grew for a year is cleared over a few days
		// rather than in one statement that locks it.
		for ( $i = 0; $i < 20; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s ORDER BY id ASC LIMIT %d", $cutoff, self::PURGE_BATCH ) );
			$removed += max( 0, $n );

			if ( $n < self::PURGE_BATCH ) {
				break;
			}
		}

		update_option( self::OPTION_PURGED, [ 'at' => time(), 'removed' => $removed ], false );

		if ( $removed > 0 ) {
			self::info(
				'system',
				'purged',
				sprintf(
					/* translators: 1: number of rows, 2: days */
					_n( 'Removed %1$s log line older than %2$s days.', 'Removed %1$s log lines older than %2$s days.', $removed, 'thirtydayhomes' ),
					number_format_i18n( $removed ),
					number_format_i18n( self::RETENTION_DAYS )
				)
			);
		}

		return $removed;
	}

	/* ---------------------------------------------------------------------
	 * System status — "is everything connected?"
	 * ------------------------------------------------------------------ */

	/**
	 * One row per dependency: what it is, how it is, and the detail.
	 *
	 * @return array<int,array{label:string,state:string,detail:string}>
	 *         state: ok | attention | off | pending
	 */
	public static function status(): array {

		$rows = [];
		$when = static fn( int $ts ): string => $ts > 0 ? wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $ts ) : '';

		// Environment.
		$env  = wp_get_environment_type();
		$demo = defined( 'TDH_DEMO_MODE' ) && TDH_DEMO_MODE && 'production' !== $env;

		// A local or staging site is not a problem, so it is not red: it is
		// "not yet" production, and the detail says what that changes.
		$rows[] = [
			'label'  => __( 'Environment', 'thirtydayhomes' ),
			'state'  => 'production' === $env ? 'ok' : 'pending',
			'detail' => 'production' === $env
				? __( 'Production. Mail is sent for real.', 'thirtydayhomes' )
				/* translators: %s: environment type */
				: sprintf( __( '%s — mail is captured to a file, not sent.', 'thirtydayhomes' ), ucfirst( $env ) ) . ( $demo ? ' ' . __( 'Client review mode ("Viewing as") is on.', 'thirtydayhomes' ) : '' ),
		];

		// Map locations.
		$problem   = get_option( 'tdh_geocode_problem' );
		$last_ok   = self::latest( 'locations', 'found' );
		$last_fail = self::latest( 'locations', 'no_answer' );

		if ( ! Geocoder::configured() ) {
			$rows[] = [ 'label' => __( 'Google Maps key', 'thirtydayhomes' ), 'state' => 'off', 'detail' => __( 'Not set up. Addresses are not looked up; staff set locations by hand.', 'thirtydayhomes' ) ];
		} elseif ( is_array( $problem ) && ! empty( $problem['error'] ) ) {
			$rows[] = [
				'label'  => __( 'Google Maps key', 'thirtydayhomes' ),
				'state'  => 'attention',
				'detail' => 'denied' === $problem['error']
					/* translators: %s: date and time */
					? sprintf( __( 'Google refused the key on %s. Check the Geocoding API and the key restrictions.', 'thirtydayhomes' ), $when( (int) ( $problem['at'] ?? 0 ) ) )
					/* translators: %s: date and time */
					: sprintf( __( 'Google asked us to slow down on %s. Waiting homes are retried on their next save.', 'thirtydayhomes' ), $when( (int) ( $problem['at'] ?? 0 ) ) ),
			];
		} else {
			$rows[] = [
				'label'  => __( 'Google Maps key', 'thirtydayhomes' ),
				'state'  => 'ok',
				'detail' => $last_ok
					/* translators: %s: date and time */
					? sprintf( __( 'Working. Last address found %s.', 'thirtydayhomes' ), $when( (int) $last_ok['time'] ) )
					: __( 'Set up. No lookup recorded yet.', 'thirtydayhomes' ),
			];
		}

		if ( $last_fail && ( ! $last_ok || $last_fail['time'] > $last_ok['time'] ) ) {
			$rows[] = [
				'label'  => __( 'Map lookups', 'thirtydayhomes' ),
				'state'  => 'attention',
				/* translators: %s: date and time */
				'detail' => sprintf( __( 'The last lookup got no answer (%s). It is retried on the next save.', 'thirtydayhomes' ), $when( (int) $last_fail['time'] ) ),
			];
		}

		// Email.
		$sent  = Smtp::last_sent();
		$error = Smtp::last_error();

		if ( Mail::capturing() ) {
			$rows[] = [ 'label' => __( 'Email', 'thirtydayhomes' ), 'state' => 'pending', 'detail' => __( 'Captured to a file on this environment; nothing is sent.', 'thirtydayhomes' ) ];
		} elseif ( $error ) {
			$rows[] = [
				'label'  => __( 'Email', 'thirtydayhomes' ),
				'state'  => 'attention',
				/* translators: 1: date and time, 2: error message */
				'detail' => sprintf( __( 'The last send failed on %1$s: %2$s', 'thirtydayhomes' ), $when( (int) $error['at'] ), $error['message'] ),
			];
		} else {
			$rows[] = [
				'label'  => __( 'Email', 'thirtydayhomes' ),
				'state'  => $sent > 0 ? 'ok' : 'pending',
				'detail' => $sent > 0
					/* translators: %s: date and time */
					? sprintf( __( 'Working. Last email sent %s.', 'thirtydayhomes' ), $when( $sent ) )
					: __( 'No email sent yet.', 'thirtydayhomes' ),
			];
		}

		// SMS.
		$rows[] = self::sms_status( $when );

		// Payments.
		$mode     = class_exists( Billing\Stripe::class ) ? Billing\Stripe::mode() : '';
		$secret   = '' !== $mode && class_exists( Billing\Stripe::class ) ? Billing\Stripe::webhook_secret( $mode ) : '';
		$last_evt = self::latest( 'payments', 'received' );
		$last_rej = self::latest( 'payments', 'rejected' );

		if ( '' === $secret ) {
			$rows[] = [ 'label' => __( 'Stripe webhook', 'thirtydayhomes' ), 'state' => 'off', 'detail' => __( 'No webhook secret is set, so payment events are not received.', 'thirtydayhomes' ) ];
		} else {
			$rows[] = [
				'label'  => __( 'Stripe webhook', 'thirtydayhomes' ),
				'state'  => $last_rej && ( ! $last_evt || $last_rej['time'] > $last_evt['time'] ) ? 'attention' : 'ok',
				'detail' => trim(
					/* translators: %s: test or live */
					sprintf( __( '%s mode.', 'thirtydayhomes' ), ucfirst( $mode ) ) . ' ' .
					/* translators: %s: date and time */
					( $last_evt ? sprintf( __( 'Last event received %s.', 'thirtydayhomes' ), $when( (int) $last_evt['time'] ) ) : __( 'No event received yet.', 'thirtydayhomes' ) ) .
					/* translators: %s: date and time */
					( $last_rej ? ' ' . sprintf( __( 'Last refused %s.', 'thirtydayhomes' ), $when( (int) $last_rej['time'] ) ) : '' )
				),
			];
		}

		// This log.
		$purged = get_option( self::OPTION_PURGED );
		$next   = (int) wp_next_scheduled( self::CRON );
		$failed = (int) get_transient( self::TRANSIENT_FAILED );

		$rows[] = [
			'label'  => __( 'This log', 'thirtydayhomes' ),
			'state'  => $failed > 0 ? 'attention' : 'ok',
			'detail' => trim(
				/* translators: 1: number of lines, 2: days */
				sprintf( __( '%1$s lines kept for %2$s days.', 'thirtydayhomes' ), number_format_i18n( self::total() ), number_format_i18n( self::RETENTION_DAYS ) ) . ' ' .
				/* translators: %s: date and time */
				( is_array( $purged ) && ! empty( $purged['at'] ) ? sprintf( __( 'Last clean-up %s.', 'thirtydayhomes' ), $when( (int) $purged['at'] ) ) : __( 'Not cleaned up yet.', 'thirtydayhomes' ) ) . ' ' .
				/* translators: %s: date and time */
				( $next > 0 ? sprintf( __( 'Next %s.', 'thirtydayhomes' ), $when( $next ) ) : __( 'No clean-up scheduled — WordPress cron may be off.', 'thirtydayhomes' ) ) .
				/* translators: %s: number of failed writes */
				( $failed > 0 ? ' ' . sprintf( _n( '%s line could not be written recently.', '%s lines could not be written recently.', $failed, 'thirtydayhomes' ), number_format_i18n( $failed ) ) : '' )
			),
		];

		return $rows;
	}

	/**
	 * The SMS row, read from the same settings the landlord's card uses, so
	 * this screen and "Not set up" on the card can never disagree.
	 *
	 * @param callable(int):string $when
	 * @return array{label:string,state:string,detail:string}
	 */
	private static function sms_status( callable $when ): array {

		$label = __( 'Text messages (SMS)', 'thirtydayhomes' );

		if ( ! Sms::is_enabled() ) {
			return [ 'label' => $label, 'state' => 'off', 'detail' => __( 'Switched off. Landlords are not offered text alerts. TDH_SMS_ENABLED is not set in wp-config.php.', 'thirtydayhomes' ) ];
		}

		$provider = Sms::provider();

		if ( null === $provider ) {
			return [ 'label' => $label, 'state' => 'off', 'detail' => __( 'Switched on, but the Twilio details are missing from wp-config.php (TDH_TWILIO_SID, TDH_TWILIO_TOKEN, TDH_TWILIO_FROM). Nothing is sent, and landlords see "Not set up".', 'thirtydayhomes' ) ];
		}

		if ( ! $provider instanceof Sms\Twilio ) {
			return [ 'label' => $label, 'state' => 'pending', 'detail' => __( 'Written to a file on this environment; nothing is sent.', 'thirtydayhomes' ) ];
		}

		// The newest text that left, and the newest one that did not.
		$sent = 0;
		foreach ( [ 'sms_sent', 'sms_code_sent' ] as $event ) {
			$row  = self::latest( 'sms', $event );
			$sent = max( $sent, $row ? (int) $row['time'] : 0 );
		}
		$failed = self::query( [ 'feature' => 'sms', 'min_level' => self::ERROR, 'per_page' => 1 ] )['rows'][0] ?? null;

		$mode = Sms::is_test_mode()
			/* translators: %s: a list of phone numbers */
			? sprintf( __( 'Test mode: only %s is texted.', 'thirtydayhomes' ), implode( ', ', array_map( [ Phone::class, 'display' ], Sms::test_recipients() ) ) )
			: __( 'Live: every landlord who turned alerts on is texted.', 'thirtydayhomes' );

		if ( $failed && (int) $failed['time'] > $sent ) {
			return [
				'label'  => $label,
				'state'  => 'attention',
				/* translators: 1: test or live sentence, 2: date and time, 3: what went wrong */
				'detail' => sprintf( __( '%1$s The last text failed on %2$s: %3$s', 'thirtydayhomes' ), $mode, $when( (int) $failed['time'] ), $failed['message'] ),
			];
		}

		return [
			'label'  => $label,
			'state'  => 'ok',
			'detail' => $mode . ' ' . ( $sent > 0
				/* translators: %s: date and time */
				? sprintf( __( 'Last text sent %s.', 'thirtydayhomes' ), $when( $sent ) )
				: __( 'Set up. No text sent yet.', 'thirtydayhomes' ) ),
		];
	}

	/* ---------------------------------------------------------------------
	 * Subscribers — the features fire, this class writes the sentence
	 * ------------------------------------------------------------------ */

	/** @param \WP_Error|mixed $error */
	public function on_login_failed( $login, $error = null ): void {
		$reason = $error instanceof \WP_Error ? $error->get_error_code() : '';
		self::write( self::WARNING, 'signin', 'refused', __( 'Sign-in refused: the email or username and password did not match.', 'thirtydayhomes' ), [ 'login' => (string) $login, 'reason' => $reason, 'ip' => self::ip() ], 0 );
	}

	public function on_login_throttled( $login ): void {
		self::write( self::ERROR, 'signin', 'throttled', __( 'Sign-in blocked for fifteen minutes after too many failed attempts.', 'thirtydayhomes' ), [ 'login' => (string) $login, 'ip' => self::ip() ], 0 );
	}

	/** @param \WP_User|mixed $user */
	public function on_login( $login, $user = null ): void {
		$id = $user instanceof \WP_User ? (int) $user->ID : 0;
		self::write( self::INFO, 'signin', 'signed_in', __( 'Signed in.', 'thirtydayhomes' ), [ 'login' => (string) $login, 'ip' => self::ip() ], $id, $id, 'user' );
	}

	public function on_user_registered( $user_id ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! in_array( Roles::LANDLORD, (array) $user->roles, true ) ) {
			return;
		}
		$by_staff = get_current_user_id() && get_current_user_id() !== (int) $user_id;
		self::info( 'members', 'created', $by_staff ? __( 'Landlord account created by staff.', 'thirtydayhomes' ) : __( 'Landlord registered.', 'thirtydayhomes' ), [ 'email' => $user->user_email ], (int) $user_id, 'user' );
	}

	/** @param \WP_User|mixed $before */
	public function on_user_updated( $user_id, $before = null ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! in_array( Roles::LANDLORD, (array) $user->roles, true ) || ! Accounts::is_staff() || get_current_user_id() === (int) $user_id ) {
			return;
		}
		self::info( 'members', 'updated', __( 'Landlord account updated by staff.', 'thirtydayhomes' ), [ 'email' => $user->user_email ], (int) $user_id, 'user' );
	}

	/** @param \WP_User|mixed $user */
	public function on_user_deleted( $user_id, $reassign = null, $user = null ): void {
		$email = $user instanceof \WP_User ? $user->user_email : '';
		self::warning( 'members', 'deleted', __( 'Account deleted.', 'thirtydayhomes' ), [ 'email' => $email ], (int) $user_id, 'user' );
	}

	public function on_listing_submitted( $listing_id, $resubmitted = false ): void {
		self::info( 'listings', $resubmitted ? 'resubmitted' : 'submitted', $resubmitted
			/* translators: %s: home title */
			? sprintf( __( '“%s” resubmitted for review.', 'thirtydayhomes' ), self::title( (int) $listing_id ) )
			/* translators: %s: home title */
			: sprintf( __( '“%s” submitted for review.', 'thirtydayhomes' ), self::title( (int) $listing_id ) ), [], (int) $listing_id, 'listing' );
	}

	public function on_listing_changes_submitted( $listing_id, $changed = [], $from_resume = false ): void {
		/* translators: 1: home title, 2: comma-separated field names */
		self::info( 'listings', 'edit_to_review', sprintf( __( '“%1$s” went back to review after a live edit: %2$s.', 'thirtydayhomes' ), self::title( (int) $listing_id ), implode( ', ', (array) $changed ) ), [ 'from_resume' => (bool) $from_resume ], (int) $listing_id, 'listing' );
	}

	public function on_listing_approved( $listing_id ): void {
		/* translators: %s: home title */
		self::info( 'listings', 'approved', sprintf( __( '“%s” approved and live.', 'thirtydayhomes' ), self::title( (int) $listing_id ) ), [], (int) $listing_id, 'listing' );
	}

	public function on_listing_changes_requested( $listing_id ): void {
		/* translators: %s: home title */
		self::info( 'listings', 'changes_requested', sprintf( __( 'Changes requested on “%s”.', 'thirtydayhomes' ), self::title( (int) $listing_id ) ), [ 'note' => (string) get_post_meta( (int) $listing_id, '_tdh_rejection_reason', true ) ], (int) $listing_id, 'listing' );
	}

	public function on_listing_paused( $listing_id ): void {
		/* translators: %s: home title */
		self::info( 'listings', 'paused', sprintf( __( '“%s” paused.', 'thirtydayhomes' ), self::title( (int) $listing_id ) ), [], (int) $listing_id, 'listing' );
	}

	public function on_listing_resumed( $listing_id ): void {
		/* translators: %s: home title */
		self::info( 'listings', 'resumed', sprintf( __( '“%s” resumed and live.', 'thirtydayhomes' ), self::title( (int) $listing_id ) ), [], (int) $listing_id, 'listing' );
	}

	public function on_listing_deleted( $listing_id ): void {
		/* translators: %s: home title */
		self::warning( 'listings', 'deleted', sprintf( __( '“%s” deleted by its landlord.', 'thirtydayhomes' ), self::title( (int) $listing_id ) ), [], (int) $listing_id, 'listing' );
	}

	/**
	 * An address lookup finished.
	 *
	 * @param string $state  found | manual | failed | pending
	 * @param string $reason the stored reason code
	 * @param bool   $asked  whether Google was actually asked this time
	 */
	public function on_geocoded( $id, $state = '', $reason = '', $asked = false, $why = '' ): void {

		$id      = (int) $id;
		$type    = Post_Types::FACILITY === get_post_type( $id ) ? 'facility' : 'listing';
		$context = [ 'address' => Geocoder::address( $id ), 'reason' => (string) $reason, 'trigger' => (string) $why ];

		if ( 'found' === $state && $asked ) {
			/* translators: %s: title */
			self::info( 'locations', 'found', sprintf( __( 'Address found for “%s”.', 'thirtydayhomes' ), self::title( $id ) ), $context, $id, $type );
			return;
		}

		if ( 'failed' === $state && ( $asked || 'changed' === $why ) ) {
			/* translators: %s: title */
			self::warning( 'locations', 'not_found', sprintf( __( 'Address not found for “%s”.', 'thirtydayhomes' ), self::title( $id ) ), $context, $id, $type );
			return;
		}

		if ( 'pending' === $state && in_array( (string) $reason, [ 'denied', 'limit', 'network', 'unknown', 'error' ], true ) ) {
			$level = 'denied' === $reason ? self::ERROR : self::WARNING;
			/* translators: %s: title */
			self::write( $level, 'locations', 'no_answer', sprintf( __( 'No answer from the map service for “%s”.', 'thirtydayhomes' ), self::title( $id ) ), $context, null, $id, $type );
		}
	}

	/** @param \WP_Post|mixed $post */
	public function on_facility_saved( $post_id, $post = null, $update = false ): void {
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || wp_is_post_revision( (int) $post_id ) || wp_is_post_autosave( (int) $post_id ) ) {
			return;
		}
		/* translators: %s: facility name */
		self::info( 'facilities', $update ? 'updated' : 'added', sprintf( $update ? __( 'Facility “%s” updated.', 'thirtydayhomes' ) : __( 'Facility “%s” added.', 'thirtydayhomes' ), $post->post_title ), [], (int) $post_id, 'facility' );
	}

	/** @param \WP_Post|mixed $post */
	public function on_post_deleted( $post_id, $post = null ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( Post_Types::FACILITY === $post->post_type ) {
			/* translators: %s: facility name */
			self::warning( 'facilities', 'deleted', sprintf( __( 'Facility “%s” deleted.', 'thirtydayhomes' ), $post->post_title ), [], (int) $post_id, 'facility' );
		} elseif ( Post_Types::LISTING === $post->post_type ) {
			/* translators: %s: home title */
			self::warning( 'listings', 'removed', sprintf( __( '“%s” permanently removed.', 'thirtydayhomes' ), $post->post_title ), [], (int) $post_id, 'listing' );
		}
	}

	/** @param \WP_Error|mixed $error */
	public function on_mail_failed( $error ): void {
		$data = $error instanceof \WP_Error ? (array) $error->get_error_data() : [];
		$to   = isset( $data['to'] ) ? implode( ', ', (array) $data['to'] ) : '';
		self::error( 'email', 'failed', __( 'An email could not be sent.', 'thirtydayhomes' ), [ 'to' => $to, 'subject' => (string) ( $data['subject'] ?? '' ), 'error' => $error instanceof \WP_Error ? $error->get_error_message() : '' ] );
	}

	/** @param array<string,mixed> $atts */
	public function on_mail_sent( $atts ): void {
		$atts = (array) $atts;
		self::info( 'email', 'sent', __( 'Email sent.', 'thirtydayhomes' ), [ 'to' => implode( ', ', (array) ( $atts['to'] ?? [] ) ), 'subject' => (string) ( $atts['subject'] ?? '' ) ] );
	}

	/** @param array<string,mixed> $atts */
	public function on_mail_captured( $path, $atts ): void {
		$atts = (array) $atts;
		self::info( 'email', 'captured', __( 'Email captured to a file (not sent on this environment).', 'thirtydayhomes' ), [ 'to' => implode( ', ', (array) ( $atts['to'] ?? [] ) ), 'subject' => (string) ( $atts['subject'] ?? '' ), 'file' => basename( (string) $path ) ] );
	}

	public function on_contact_received( $inquiry_id ): void {
		self::info( 'inquiries', 'received', __( 'Contact message received.', 'thirtydayhomes' ), [ 'email' => (string) get_post_meta( (int) $inquiry_id, '_tdh_inquiry_email', true ) ], (int) $inquiry_id, 'inquiry' );
	}

	/**
	 * A renter's inquiry about a home was stored (D1).
	 *
	 * The address is masked by the context writer, which is why the raw
	 * value is handed over rather than trimmed here — one place decides
	 * what masking means, and it cannot drift per call site.
	 */
	public function on_inquiry_received( $inquiry_id, $listing_id = 0 ): void {

		self::info(
			'inquiries',
			'received',
			/* translators: %s: home title */
			sprintf( __( 'Inquiry received about “%s”.', 'thirtydayhomes' ), self::title( (int) $listing_id ) ),
			[
				'email'   => (string) get_post_meta( (int) $inquiry_id, '_tdh_renter_email', true ),
				'phone'   => (string) get_post_meta( (int) $inquiry_id, '_tdh_renter_phone', true ),
				'move_in' => (string) get_post_meta( (int) $inquiry_id, '_tdh_move_in', true ),
				'listing' => (int) $listing_id,
			],
			(int) $inquiry_id,
			'inquiry'
		);
	}

	/**
	 * A verified phone number was edited, so it is no longer verified (D4).
	 *
	 * Worth a line because the landlord will not be texted until they
	 * confirm the new number, and "why did the texts stop" is the question
	 * that lands with support.
	 */
	public function on_sms_verification_reset( $user_id ): void {

		self::info(
			'sms',
			'sms_verification_reset',
			__( 'The landlord changed their phone number. Texts pause until the new number is confirmed.', 'thirtydayhomes' ),
			[ 'phone' => (string) get_user_meta( (int) $user_id, '_tdh_phone', true ) ],
			(int) $user_id,
			'user'
		);
	}

	/**
	 * An inquiry was turned away (D1).
	 *
	 * A warning rather than info: each of these is a renter who wanted to
	 * write to a landlord and could not. A run of them on one home means
	 * something is wrong with the home, not with the renter.
	 */
	public function on_inquiry_refused( $reason, $listing_id = 0 ): void {

		$why = [
			'gone'     => __( 'the home was withdrawn while they were writing', 'thirtydayhomes' ),
			'too_many' => __( 'too many inquiries from one address in an hour', 'thirtydayhomes' ),
			'failed'   => __( 'the inquiry could not be written', 'thirtydayhomes' ),
		];

		$reason = (string) $reason;

		self::warning(
			'inquiries',
			'refused',
			sprintf(
				/* translators: 1: home title, 2: the reason in words */
				__( 'Inquiry about “%1$s” was not accepted: %2$s.', 'thirtydayhomes' ),
				self::title( (int) $listing_id ),
				$why[ $reason ] ?? $reason
			),
			[
				'reason'  => $reason,
				'listing' => (int) $listing_id,
			],
			(int) $listing_id,
			'listing'
		);
	}

	/** A landlord archived an inquiry or brought it back (D2). */
	public function on_inquiry_filed( $inquiry_id, $to = 'archive' ): void {

		$archived = 'archive' === (string) $to;

		self::info(
			'inquiries',
			$archived ? 'archived' : 'unarchived',
			$archived
				? __( 'Inquiry archived by the landlord.', 'thirtydayhomes' )
				: __( 'Inquiry moved back to the inbox.', 'thirtydayhomes' ),
			[],
			(int) $inquiry_id,
			'inquiry'
		);
	}

	public function on_stripe_received( $type, $event_id, $mode ): void {
		/* translators: %s: Stripe event type */
		self::info( 'payments', 'received', sprintf( __( 'Stripe event received: %s.', 'thirtydayhomes' ), (string) $type ), [ 'event_id' => (string) $event_id, 'mode' => (string) $mode ] );
	}

	public function on_stripe_rejected( $reason, $mode = '' ): void {
		$words = [
			'not_configured' => __( 'A Stripe event arrived but no webhook secret is set; Stripe will retry.', 'thirtydayhomes' ),
			'bad_signature'  => __( 'A Stripe event was refused: the signature did not match. Check the webhook secret.', 'thirtydayhomes' ),
			'malformed'      => __( 'A Stripe event was refused: the payload was not readable.', 'thirtydayhomes' ),
			'mode_mismatch'  => __( 'A Stripe event was ignored: live and test modes did not match.', 'thirtydayhomes' ),
			'duplicate'      => __( 'A Stripe event was ignored: already handled.', 'thirtydayhomes' ),
		];
		$level = in_array( (string) $reason, [ 'duplicate', 'mode_mismatch' ], true ) ? self::INFO : self::ERROR;
		self::write( $level, 'payments', 'rejected', $words[ (string) $reason ] ?? __( 'A Stripe event was refused.', 'thirtydayhomes' ), [ 'reason' => (string) $reason, 'mode' => (string) $mode ] );
	}

	/** @param array<string,mixed> $changes @param array<string,mixed> $before */
	public function on_membership_changed( $user_id, $changes = [], $before = [] ): void {
		$changes = (array) $changes;
		$before  = (array) $before;
		$status  = (string) ( $changes['status'] ?? '' );
		$moved   = '' !== $status && $status !== (string) ( $before['status'] ?? '' );

		self::write(
			$moved && in_array( $status, [ 'past_due', 'expired', 'cancelled' ], true ) ? self::WARNING : self::INFO,
			'payments',
			'membership',
			$moved
				/* translators: 1: old status, 2: new status */
				? sprintf( __( 'Membership changed from %1$s to %2$s.', 'thirtydayhomes' ), (string) ( $before['status'] ?? 'none' ), $status )
				: __( 'Membership details updated.', 'thirtydayhomes' ),
			[ 'changes' => array_intersect_key( $changes, array_flip( [ 'status', 'plan', 'quota', 'expires' ] ) ) ],
			null,
			(int) $user_id,
			'user'
		);
	}

	public function on_proximity_saved( $count, $radius ): void {
		/* translators: 1: count, 2: miles */
		self::info( 'settings', 'proximity', sprintf( __( 'Property pages now list the nearest %1$s facilities within %2$s miles.', 'thirtydayhomes' ), (string) $count, (string) $radius ) );
	}

	/* ---------------------------------------------------------------------
	 * Small helpers
	 * ------------------------------------------------------------------ */

	private static function title( int $post_id ): string {
		$title = get_the_title( $post_id );

		return '' !== $title ? $title : sprintf( '#%d', $post_id );
	}

	private static function ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		// The last octet is dropped: enough to see a pattern, not a person.
		return (string) preg_replace( '/\.\d+$/', '.x', $ip );
	}

	private static function request_id(): string {
		if ( '' === self::$request_id ) {
			self::$request_id = substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
		}

		return self::$request_id;
	}

	/**
	 * "class-tdh-accounts.php:do_login" — the plugin code the line came from.
	 *
	 * A backtrace frame carries the FILE where a call was made and the NAME
	 * of the function that was called; the function that contains that line
	 * is named by the next frame out. Pairing them gives "file:function the
	 * line sits in", which is what a person opens.
	 *
	 * WordPress's own library is plumbing, not a source: a refused sign-in
	 * fires from pluggable.php, but the answer wanted is the plugin code
	 * that called into it. Core frames and this class's own file are skipped.
	 */
	private static function source(): string {

		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 16 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace

		foreach ( $frames as $i => $frame ) {

			if ( empty( $frame['file'] ) ) {
				continue;
			}

			$file = str_replace( '\\', '/', (string) $frame['file'] );

			if ( str_ends_with( $file, '/class-tdh-log.php' )
				|| str_contains( $file, '/wp-includes/' )
				|| str_contains( $file, '/wp-admin/includes/' ) ) {
				continue;
			}

			$outer    = $frames[ $i + 1 ] ?? [];
			$function = (string) ( $outer['function'] ?? $frame['function'] ?? '' );

			// A line written from a WP-CLI script has no file of its own.
			if ( str_contains( $file, "eval()'d code" ) ) {
				return mb_substr( 'wp-cli:' . $function, 0, 120 );
			}

			return mb_substr( basename( $file ) . ':' . $function, 0, 120 );
		}

		return '';
	}

	private static function count_failure(): void {
		set_transient( self::TRANSIENT_FAILED, (int) get_transient( self::TRANSIENT_FAILED ) + 1, DAY_IN_SECONDS );
	}
}
