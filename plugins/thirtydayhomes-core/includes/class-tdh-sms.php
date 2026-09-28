<?php
/**
 * Text messages to landlords.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

use TDH\Sms\Capture;
use TDH\Sms\Provider;
use TDH\Sms\Twilio;

defined( 'ABSPATH' ) || exit;

/**
 * A landlord who has verified a phone and said yes gets a text for each
 * inquiry. Everyone else gets nothing, and the site works exactly the
 * same with texting switched off.
 *
 * ─── NOBODY IS TEXTED WHO DID NOT ASK ──────────────────────────────────────
 *
 * Three things must all be true before a number is texted: the landlord
 * ticked the consent box, the number was confirmed with a six-digit code,
 * and they have not since replied STOP. Any one missing and the inquiry
 * goes by email only, with the reason written in the log. The carriers'
 * rules (A2P 10DLC) require this, and so does decency: a text arriving on
 * a number somebody mistyped is a stranger's phone buzzing at midnight.
 *
 * ─── THE SAME SHAPE AS EMAIL ───────────────────────────────────────────────
 *
 * One row per inquiry in `wp_tdh_notifications`, channel `sms`, written
 * BEFORE any sending. The send is booked through cron so the renter's
 * page never waits on a gateway, retried once on failure, then given up
 * and badged for staff. TDH\Notifications owns the email row and this
 * class owns the sms row; neither touches the other's.
 *
 * ─── TEST MODE ─────────────────────────────────────────────────────────────
 *
 * With TDH_SMS_TEST_RECIPIENTS set, only those numbers are ever texted.
 * Everyone else's message is written down as "test mode — not sent" and
 * counted as handled. That is how a real text reaches the owner's own
 * phone for the acceptance evidence while the campaign is still being
 * approved, without a single message going to a real landlord early.
 */
final class Sms {

	public const CHANNEL = 'sms';

	/** Switches the feature on. Without it nothing here registers a hook. */
	public const CONST_ENABLED = 'TDH_SMS_ENABLED';

	/** Comma-separated E.164 numbers. Non-empty means test mode. */
	public const CONST_TEST_RECIPIENTS = 'TDH_SMS_TEST_RECIPIENTS';

	public const REST_NAMESPACE = 'tdh/v1';
	public const ROUTE_INBOUND  = '/sms-inbound';
	public const ROUTE_STATUS   = '/sms-status';

	/** Sent, or deliberately not: test mode, opted out, unverified. */
	public const SKIPPED = 'skipped';

	/** The first attempt plus one retry. */
	public const MAX_ATTEMPTS = 2;
	public const RETRY_DELAY  = 600;
	private const SEND_HOOK   = 'tdh_sms_send';

	/** A booked first send that has not run in this long is picked up by the sweep. */
	public const QUEUED_GRACE = 120;

	/** One text. Longer is billed as two and can arrive as two. */
	public const SEGMENT = 160;

	/** Texts to one landlord in one hour before the rest go by email only. */
	public const RATE_LIMIT = 20;

	/** The six-digit code. */
	public const CODE_TTL      = 600;
	public const CODE_ATTEMPTS = 5;
	public const RESEND_GAP    = 60;
	public const RESEND_HOURLY = 5;

	/** Metadata on the landlord. */
	public const META_PHONE        = '_tdh_phone';
	public const META_VERIFIED     = '_tdh_sms_verified';
	public const META_VERIFIED_AT  = '_tdh_sms_verified_at';
	public const META_CONSENT      = '_tdh_sms_consent';
	public const META_CONSENT_AT   = '_tdh_sms_consent_at';
	public const META_OPTED_OUT    = '_tdh_sms_opted_out';
	public const META_OPTED_OUT_AT = '_tdh_sms_opted_out_at';
	public const META_CODE_HASH    = '_tdh_sms_code_hash';
	public const META_CODE_EXPIRES = '_tdh_sms_code_expires';
	public const META_CODE_TRIES   = '_tdh_sms_code_tries';
	public const META_CODE_SENT    = '_tdh_sms_code_sent';

	/** The profile card's actions. */
	public const ACTION_START  = 'tdh_sms_start';
	public const ACTION_VERIFY = 'tdh_sms_verify';
	public const ACTION_RESEND = 'tdh_sms_resend';
	public const ACTION_STOP   = 'tdh_sms_stop';

	/** Its own query flag, for the same reason Notifications has one. */
	public const PARAM_DONE = 'sms';

	public const CODE_SENT     = 'code_sent';
	public const VERIFIED      = 'verified';
	public const WRONG_CODE    = 'wrong_code';
	public const CODE_EXPIRED  = 'code_expired';
	public const TOO_MANY      = 'too_many';
	public const WAIT          = 'wait';
	public const BAD_NUMBER    = 'bad_number';
	public const NO_CONSENT    = 'no_consent';
	public const NOT_AVAILABLE = 'not_available';
	public const STOPPED       = 'stopped';
	public const SEND_FAILED   = 'send_failed';
	public const EXPIRED       = 'expired';
	public const STILL_PAUSED  = 'still_paused';

	/**
	 * Words landlords reply with. Twilio's own opt-out handling answers
	 * these at the carrier edge; we record them so the profile says so.
	 */
	private const STOP_WORDS  = [ 'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT' ];
	private const START_WORDS = [ 'START', 'UNSTOP', 'YES' ];

	public function register(): void {

		/*
		 * Off means off. Not one hook, so a site without the constant has no
		 * card on the profile, no routes, no cron, and nothing to go wrong.
		 * The only exception is the reset-on-number-change, which must run
		 * regardless — a verified number must never survive being edited.
		 */
		add_action( 'updated_user_meta', [ $this, 'on_phone_changed' ], 10, 4 );

		if ( ! self::is_enabled() ) {
			return;
		}

		// After the email listener (priority 10), so the email row exists
		// first — the order the renter was promised.
		add_action( 'tdh_inquiry_received', [ $this, 'on_inquiry' ], 20, 2 );
		add_action( self::SEND_HOOK, [ $this, 'retry' ] );
		add_action( 'admin_init', [ $this, 'sweep' ] );
		add_action( 'admin_notices', [ $this, 'test_mode_notice' ] );

		add_action( 'template_redirect', [ $this, 'handle' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/* ---------------------------------------------------------------------
	 * Configuration
	 * ------------------------------------------------------------------ */

	public static function is_enabled(): bool {
		return defined( self::CONST_ENABLED ) && (bool) constant( self::CONST_ENABLED );
	}

	/**
	 * The gateway. A filter first, so the test suite can stand in a fake
	 * without ever reaching Twilio.
	 */
	public static function provider(): ?Provider {

		/**
		 * The SMS provider in use.
		 *
		 * @param Provider|null $provider Null means "the default, if configured".
		 */
		$provider = apply_filters( 'tdh_sms_provider', null );

		if ( $provider instanceof Provider ) {
			return $provider;
		}

		if ( Twilio::is_configured() ) {
			return new Twilio();
		}

		/*
		 * No credentials. On a local or development site that is normal and
		 * texts go to a file, exactly as email does, so the whole landlord
		 * journey can be walked without a Twilio account. On production it
		 * means "not set up", and nothing is sent anywhere — the same rule
		 * Mail::should_capture() applies, for the same reason.
		 */
		if ( Mail::should_capture( wp_get_environment_type() ) ) {
			return new Capture();
		}

		return null;
	}

	/** @return string[] E.164 numbers texting is limited to; empty = live. */
	public static function test_recipients(): array {

		// The filter below must run whether or not the constant exists, or
		// the suite could never switch test mode ON on a runner without it.
		$raw = defined( self::CONST_TEST_RECIPIENTS ) ? (string) constant( self::CONST_TEST_RECIPIENTS ) : '';

		$list = array_values(
			array_filter(
				array_map(
					static fn( string $n ): string => Phone::normalise( $n ),
					explode( ',', $raw )
				)
			)
		);

		/**
		 * The numbers texting is limited to. A constant cannot be changed
		 * at runtime, so this is how the test suite drives test mode on and
		 * off; production has no reason to hook it.
		 *
		 * @param string[] $list E.164 numbers.
		 */
		$filtered = apply_filters( 'tdh_sms_test_recipients', $list );

		return is_array( $filtered ) ? array_values( array_filter( array_map( 'strval', $filtered ) ) ) : $list;
	}

	public static function is_test_mode(): bool {
		return [] !== self::test_recipients();
	}

	/** In test mode, only the listed numbers. Otherwise everyone. */
	public static function may_send_to( string $e164 ): bool {
		return ! self::is_test_mode() || in_array( $e164, self::test_recipients(), true );
	}

	/**
	 * Why a text cannot go out at all right now, or '' when it can.
	 *
	 * Configuration only — nothing about the landlord. A test-mode site
	 * with no Twilio credentials still "works" in the sense that every
	 * send is written down, so only the fully-off states are reported.
	 */
	public static function unavailable_reason(): string {

		if ( ! self::is_enabled() ) {
			return __( 'Text alerts are not switched on for this site.', 'thirtydayhomes' );
		}

		if ( null === self::provider() ) {
			return __( 'Text alerts are not set up yet. The site owner needs to add the texting credentials.', 'thirtydayhomes' );
		}

		return '';
	}

	/* ---------------------------------------------------------------------
	 * The landlord's state
	 * ------------------------------------------------------------------ */

	/**
	 * Everything the profile card and the sender need to know.
	 *
	 * @return array{
	 *   phone:string, display:string, verified:bool, consented:bool,
	 *   opted_out:bool, pending:bool, can_text:bool, state:string, label:string
	 * }
	 */
	public static function state_for( int $user_id ): array {

		$phone     = Phone::normalise( (string) get_user_meta( $user_id, self::META_PHONE, true ) );
		$verified  = '' !== $phone && $phone === (string) get_user_meta( $user_id, self::META_VERIFIED, true );
		$consented = '1' === (string) get_user_meta( $user_id, self::META_CONSENT, true );
		$opted_out = '1' === (string) get_user_meta( $user_id, self::META_OPTED_OUT, true );
		$pending   = ! $verified && (int) get_user_meta( $user_id, self::META_CODE_EXPIRES, true ) > time();

		if ( $opted_out ) {
			$state = 'paused';
			$label = __( 'Paused — you replied STOP', 'thirtydayhomes' );
		} elseif ( $verified && $consented ) {
			$state = 'on';
			$label = __( 'On', 'thirtydayhomes' );
		} elseif ( $verified ) {
			$state = 'verified';
			$label = __( 'Number verified, alerts off', 'thirtydayhomes' );
		} elseif ( $pending ) {
			$state = 'pending';
			$label = __( 'Code sent — check your phone', 'thirtydayhomes' );
		} else {
			$state = 'off';
			$label = __( 'Not set up', 'thirtydayhomes' );
		}

		return [
			'phone'     => $phone,
			'display'   => '' !== $phone ? Phone::display( $phone ) : '',
			'verified'  => $verified,
			'consented' => $consented,
			'opted_out' => $opted_out,
			'pending'   => $pending,
			'can_text'  => $verified && $consented && ! $opted_out && '' === self::unavailable_reason(),
			'state'     => $state,
			'label'     => $label,
		];
	}

	/**
	 * The number changed, so whatever was verified no longer is.
	 *
	 * Runs on every save of _tdh_phone, wherever it came from — the profile
	 * card, the staff member form, an import. A verification that survived
	 * an edit would mean a code confirmed for one number authorising texts
	 * to another.
	 *
	 * @param int    $meta_id  Unused.
	 * @param int    $user_id  Whose.
	 * @param string $meta_key Which key changed.
	 * @param mixed  $value    The new value.
	 */
	public function on_phone_changed( $meta_id, $user_id, $meta_key, $value ): void {

		if ( self::META_PHONE !== $meta_key ) {
			return;
		}

		$user_id  = (int) $user_id;
		$now      = Phone::normalise( (string) $value );
		$verified = (string) get_user_meta( $user_id, self::META_VERIFIED, true );

		if ( '' === $verified || $now === $verified ) {
			return;
		}

		delete_user_meta( $user_id, self::META_VERIFIED );
		delete_user_meta( $user_id, self::META_VERIFIED_AT );
		self::forget_code( $user_id );

		// A STOP belonged to the old number. A new one starts clean, or the
		// card would call a number nobody has ever texted "paused".
		delete_user_meta( $user_id, self::META_OPTED_OUT );
		delete_user_meta( $user_id, self::META_OPTED_OUT_AT );

		/**
		 * A verified number was replaced, so verification was reset.
		 *
		 * @param int $user_id The landlord.
		 */
		do_action( 'tdh_sms_verification_reset', $user_id );
	}

	/* ---------------------------------------------------------------------
	 * Sending on an inquiry
	 * ------------------------------------------------------------------ */

	/**
	 * An inquiry arrived. Text the landlord — if, and only if, they asked.
	 *
	 * A row is written for every outcome, including the ones where nothing
	 * is sent, so staff can see "not texted: opted out" rather than a gap.
	 * The exception is a landlord who never set texting up, or turned it
	 * off on the card: no row, because "not texted" is not news about them.
	 */
	public function on_inquiry( $inquiry_id, $listing_id = 0 ): void {

		$inquiry_id = (int) $inquiry_id;
		$listing_id = (int) $listing_id ?: (int) get_post_meta( $inquiry_id, '_tdh_listing_id', true );
		$landlord   = (int) get_post_field( 'post_author', $listing_id );

		if ( $landlord <= 0 ) {
			return;
		}

		$state = self::state_for( $landlord );

		// Never opted in: nothing to say.
		if ( ! $state['consented'] && ! $state['opted_out'] ) {
			return;
		}

		if ( $state['opted_out'] ) {
			$this->skip( $inquiry_id, $state['phone'], 'sms_skipped_opted_out', __( 'The landlord has replied STOP, so the inquiry went by email only.', 'thirtydayhomes' ), $landlord );

			return;
		}

		if ( ! $state['verified'] ) {
			$this->skip( $inquiry_id, $state['phone'], 'sms_skipped_unverified', __( 'The landlord ticked text alerts but never confirmed the number, so the inquiry went by email only.', 'thirtydayhomes' ), $landlord );

			return;
		}

		if ( '' !== self::unavailable_reason() ) {
			$this->skip( $inquiry_id, $state['phone'], 'sms_not_configured', __( 'Text alerts are switched on but the gateway is not configured, so the inquiry went by email only.', 'thirtydayhomes' ), $landlord );

			return;
		}

		if ( self::over_rate_limit( $landlord ) ) {
			$this->skip( $inquiry_id, $state['phone'], 'sms_rate_limited', __( 'This landlord has had the hour’s limit of texts, so the inquiry went by email only.', 'thirtydayhomes' ), $landlord );

			return;
		}

		$row = Notifications::queue( $inquiry_id, self::CHANNEL, $state['phone'] );

		if ( $row ) {
			// Booked, not sent here — the renter's page is still waiting.
			wp_schedule_single_event( time(), self::SEND_HOOK, [ $row ] );
		}
	}

	/** Write a row that says why nothing was sent, and log it. */
	private function skip( int $inquiry_id, string $phone, string $event, string $why, int $landlord ): void {

		$row = Notifications::queue( $inquiry_id, self::CHANNEL, $phone );

		if ( $row ) {
			self::mark( $row, self::SKIPPED, $why );
		}

		if ( class_exists( '\TDH\Log' ) ) {
			Log::info( 'sms', $event, $why, [ 'phone' => $phone, 'landlord' => $landlord ], $inquiry_id, 'inquiry' );
		}
	}

	/**
	 * Try one row once.
	 *
	 * @return bool Whether it got through.
	 */
	public static function send( int $row_id ): bool {

		$row = Notifications::row( $row_id );

		if ( null === $row || self::CHANNEL !== (string) $row['channel'] ) {
			return false;
		}

		if ( in_array( (string) $row['status'], [ Notifications::SENT, Notifications::GIVEN_UP, self::SKIPPED ], true ) ) {
			return false;
		}

		$inquiry_id = (int) $row['inquiry_id'];
		$to         = (string) $row['recipient'];
		$attempts   = (int) $row['attempts'] + 1;

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Notifications::table(), [ 'attempts' => $attempts ], [ 'id' => $row_id ], [ '%d' ], [ '%d' ] );

		/*
		 * Test mode. Decided here, at the moment of sending, not when the
		 * row was queued — the constant could have been added in between,
		 * and the safe reading of a change like that is the stricter one.
		 */
		if ( ! self::may_send_to( $to ) ) {
			self::mark( $row_id, self::SKIPPED, __( 'Test mode — not sent. Only the test numbers in wp-config.php are texted.', 'thirtydayhomes' ) );

			if ( class_exists( '\TDH\Log' ) ) {
				Log::info( 'sms', 'sms_skipped_test_mode', __( 'Test mode: the text was written down instead of sent.', 'thirtydayhomes' ), [ 'phone' => $to ], $inquiry_id, 'inquiry' );
			}

			return false;
		}

		$provider = self::provider();

		if ( null === $provider ) {
			self::mark( $row_id, Notifications::GIVEN_UP, __( 'No texting gateway is configured.', 'thirtydayhomes' ) );

			return false;
		}

		$result = $provider->send( $to, self::body( $inquiry_id ) );

		if ( $result['ok'] ) {
			self::mark( $row_id, Notifications::SENT, '', (string) $result['id'] );
			self::count_toward_limit( $inquiry_id );
			wp_clear_scheduled_hook( self::SEND_HOOK, [ $row_id ] );

			if ( class_exists( '\TDH\Log' ) ) {
				Log::info( 'sms', 'sms_sent', __( 'Inquiry texted to the landlord.', 'thirtydayhomes' ), [ 'phone' => $to, 'provider' => $provider->name(), 'message_id' => $result['id'], 'attempts' => $attempts ], $inquiry_id, 'inquiry' );
			}

			return true;
		}

		/*
		 * Error 21610: the landlord replied STOP and Twilio, which handles
		 * opt-outs at the carrier edge, refused to send. If the STOP itself
		 * never reached our webhook this is the first we hear of it, and a
		 * retry would fail the same way, for this and every inquiry after
		 * it. Record the opt-out and treat the row like any other paused one.
		 */
		if ( str_contains( (string) $result['error'], '21610' ) ) {
			self::set_opt_out( $to, true );
			self::mark( $row_id, self::SKIPPED, __( 'The landlord has replied STOP, so the inquiry went by email only.', 'thirtydayhomes' ) );
			wp_clear_scheduled_hook( self::SEND_HOOK, [ $row_id ] );

			if ( class_exists( '\TDH\Log' ) ) {
				Log::info( 'sms', 'sms_skipped_opted_out', __( 'The carrier refused the text because the landlord had replied STOP, so the inquiry went by email only.', 'thirtydayhomes' ), [ 'phone' => $to, 'provider' => $provider->name() ], $inquiry_id, 'inquiry' );
			}

			return false;
		}

		$terminal = $attempts >= self::MAX_ATTEMPTS;

		self::mark( $row_id, $terminal ? Notifications::GIVEN_UP : Notifications::FAILED, (string) $result['error'] );
		wp_clear_scheduled_hook( self::SEND_HOOK, [ $row_id ] );

		if ( ! $terminal ) {
			wp_schedule_single_event( time() + self::RETRY_DELAY, self::SEND_HOOK, [ $row_id ] );
		}

		if ( class_exists( '\TDH\Log' ) ) {
			Log::error(
				'sms',
				$terminal ? 'sms_given_up' : 'sms_failed',
				$terminal
					? __( 'A text could not be sent after two attempts. The inquiry itself is safe, and the landlord was emailed.', 'thirtydayhomes' )
					: __( 'A text failed and will be tried once more.', 'thirtydayhomes' ),
				[ 'phone' => $to, 'provider' => $provider->name(), 'reason' => $result['error'], 'attempts' => $attempts ],
				$inquiry_id,
				'inquiry'
			);
		}

		return false;
	}

	/** A scheduled retry. */
	public function retry( $row_id ): void {
		self::send( (int) $row_id );
	}

	/** The catch-up for a host whose cron only fires on a page view. */
	public function sweep(): void {

		global $wpdb;

		$table = Notifications::table();

		/*
		 * Two waits, not one. A queued row is a first send that cron never
		 * ran, and two minutes is long enough to be sure of that. A failed
		 * row already has its retry booked ten minutes out, and sweeping it
		 * sooner would spend the one retry inside the first minutes of a
		 * gateway outage — the same mistake the email sweep once made.
		 */
		$queued_due = gmdate( 'Y-m-d H:i:s', time() - self::QUEUED_GRACE );
		$failed_due = gmdate( 'Y-m-d H:i:s', time() - self::RETRY_DELAY );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE channel = %s
				   AND (
				         ( status = %s AND updated_at <= %s )
				      OR ( status = %s AND attempts < %d AND updated_at <= %s )
				   )
				 ORDER BY updated_at ASC LIMIT 5",
				self::CHANNEL,
				Notifications::QUEUED,
				$queued_due,
				Notifications::FAILED,
				self::MAX_ATTEMPTS,
				$failed_due
			)
		);

		foreach ( (array) $ids as $id ) {
			self::send( (int) $id );
		}
	}

	/**
	 * The text. Decision 3, exactly, and nothing about the renter in it.
	 *
	 * Kept to one segment. The fixed words and the link take about 125
	 * characters on the live domain, so a long home name is cut with "..."
	 * rather than letting the text run to two segments — which is billed
	 * twice and, on some handsets, arrives as two. The name is also brought
	 * back to plain punctuation: WordPress's title filter hands us curly
	 * quotes and en dashes, and one of those switches the whole text to the
	 * 70-character encoding.
	 *
	 * @param int $inquiry_id The inquiry.
	 */
	public static function body( int $inquiry_id ): string {

		$about = class_exists( '\TDH\Inquiry' ) ? Inquiry::about( $inquiry_id ) : [ 'name' => '' ];

		$link = add_query_arg(
			[
				'view' => 'inquiries',
				'msg'  => (string) $inquiry_id,
			],
			Accounts::url( 'account' )
		);

		$template = 'New ThirtyDayHomes inquiry for %s. View it: %s Reply STOP to opt out.';
		$name     = self::plain( (string) $about['name'] );
		$room     = max( 8, self::SEGMENT - mb_strlen( sprintf( $template, '', $link ) ) );

		if ( mb_strlen( $name ) > $room ) {
			$name = rtrim( mb_substr( $name, 0, $room - 3 ) ) . '...';
		}

		return sprintf( $template, $name, $link );
	}

	/**
	 * Text as a phone keypad would type it: no tags, no HTML entities, and
	 * the typographic quotes and dashes the title filter adds turned back
	 * into the plain ones. Everything else is left alone — a home called
	 * "Café Loft" keeps its accent, and pays the encoding cost knowingly.
	 */
	private static function plain( string $text ): string {

		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$text = strtr(
			$text,
			[
				"\u{2018}" => "'",
				"\u{2019}" => "'",
				"\u{201A}" => "'",
				"\u{201C}" => '"',
				"\u{201D}" => '"',
				"\u{201E}" => '"',
				"\u{2013}" => '-',
				"\u{2014}" => '-',
				"\u{2026}" => '...',
				"\u{00A0}" => ' ',
			]
		);

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	private static function mark( int $id, string $status, string $response = '', string $message_id = '' ): void {

		global $wpdb;

		$data   = [
			'status'            => $status,
			'provider_response' => mb_substr( $response, 0, 500 ),
			'updated_at'        => current_time( 'mysql', true ),
		];
		$format = [ '%s', '%s', '%s' ];

		if ( '' !== $message_id ) {
			$data['provider_message_id'] = $message_id;
			$format[]                    = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Notifications::table(), $data, [ 'id' => $id ], $format, [ '%d' ] );
	}

	private static function over_rate_limit( int $landlord ): bool {
		return (int) get_transient( 'tdh_sms_rate_' . $landlord ) >= self::RATE_LIMIT;
	}

	private static function count_toward_limit( int $inquiry_id ): void {

		$listing  = (int) get_post_meta( $inquiry_id, '_tdh_listing_id', true );
		$landlord = (int) get_post_field( 'post_author', $listing );

		if ( $landlord <= 0 ) {
			return;
		}

		$key = 'tdh_sms_rate_' . $landlord;
		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
	}

	/**
	 * The delivery state of an inquiry's text, in words, for staff.
	 *
	 * @return array{state:string,label:string,detail:string}
	 */
	public static function state_of( int $inquiry_id ): array {

		foreach ( Notifications::for_inquiry( $inquiry_id ) as $row ) {

			if ( self::CHANNEL !== $row['channel'] ) {
				continue;
			}

			$status = (string) $row['status'];

			$labels = [
				Notifications::SENT     => __( 'Texted', 'thirtydayhomes' ),
				Notifications::QUEUED   => __( 'Text queued', 'thirtydayhomes' ),
				Notifications::FAILED   => __( 'Text failed — trying again', 'thirtydayhomes' ),
				Notifications::GIVEN_UP => __( 'Text failed', 'thirtydayhomes' ),
				self::SKIPPED           => __( 'Not texted', 'thirtydayhomes' ),
			];

			return [
				'state'  => $status,
				'label'  => (string) ( $labels[ $status ] ?? $status ),
				'detail' => (string) $row['provider_response'],
			];
		}

		return [
			'state'  => '',
			'label'  => '',
			'detail' => '',
		];
	}

	/* ---------------------------------------------------------------------
	 * The profile card: number, code, consent
	 * ------------------------------------------------------------------ */

	/** POST from the profile card. */
	public function handle(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked below, once we know it is ours.
		$action = isset( $_POST['tdh_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['tdh_action'] ) ) : '';

		if ( ! in_array( $action, [ self::ACTION_START, self::ACTION_VERIFY, self::ACTION_RESEND, self::ACTION_STOP ], true ) ) {
			return;
		}

		$back = add_query_arg( 'view', 'profile', Accounts::url( 'account' ) );

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( Accounts::url( 'login' ) );
			exit;
		}

		if ( ! isset( $_POST['tdh_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tdh_nonce'] ) ), $action ) ) {
			self::bounce( $back, self::EXPIRED );
		}

		$user_id = get_current_user_id();

		if ( '' !== self::unavailable_reason() && self::ACTION_STOP !== $action ) {
			self::bounce( $back, self::NOT_AVAILABLE );
		}

		match ( $action ) {
			self::ACTION_START  => $this->start( $user_id, $back ),
			self::ACTION_VERIFY => $this->verify( $user_id, $back ),
			self::ACTION_RESEND => $this->resend( $user_id, $back ),
			self::ACTION_STOP   => $this->stop( $user_id, $back ),
			default             => null,
		};
	}

	/**
	 * Save the number and the consent, and send the first code.
	 *
	 * Consent is required to proceed — there is no point confirming a
	 * number for texts nobody asked for. It is recorded at once, but
	 * nothing rides on it until the number is confirmed, so a tick with a
	 * mistyped number costs a stranger one code and never an inquiry.
	 */
	private function start( int $user_id, string $back ): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle().
		$raw   = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_sms_phone'] ?? '' ) ) );
		$phone = Phone::normalise( $raw );

		if ( '' === $phone ) {
			self::bounce( $back, self::BAD_NUMBER );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle().
		if ( empty( $_POST['tdh_sms_consent'] ) ) {
			self::bounce( $back, self::NO_CONSENT );
		}

		$before = self::state_for( $user_id );

		/*
		 * The block after a STOP lives at the carrier, and only START lifts
		 * it. Clearing our flag for the same number would show "On" and then
		 * fail every text; a different number was never blocked.
		 */
		if ( $before['opted_out'] && $phone === $before['phone'] ) {
			self::bounce( $back, self::STILL_PAUSED );
		}

		// Stored as the landlord's phone. on_phone_changed() resets the old
		// verification, and the old number's pause, if this is a different one.
		update_user_meta( $user_id, self::META_PHONE, $phone );
		update_user_meta( $user_id, self::META_CONSENT, '1' );
		update_user_meta( $user_id, self::META_CONSENT_AT, time() );

		if ( class_exists( '\TDH\Log' ) ) {
			Log::info( 'sms', 'sms_opted_in', __( 'A landlord asked for text alerts and is confirming their number.', 'thirtydayhomes' ), [ 'phone' => $phone ], $user_id, 'user' );
		}

		$state = self::state_for( $user_id );

		if ( $state['verified'] ) {
			// Same number, already confirmed: nothing to send.
			self::bounce( $back, self::VERIFIED );
		}

		self::bounce( $back, $this->send_code( $user_id, $phone ) );
	}

	private function resend( int $user_id, string $back ): void {

		$state = self::state_for( $user_id );

		if ( '' === $state['phone'] ) {
			self::bounce( $back, self::BAD_NUMBER );
		}

		self::bounce( $back, $this->send_code( $user_id, $state['phone'] ) );
	}

	/**
	 * Text a fresh code, within the resend limits.
	 *
	 * @return string The outcome flag for the redirect.
	 */
	private function send_code( int $user_id, string $phone ): string {

		$sent = array_values( array_filter( (array) get_user_meta( $user_id, self::META_CODE_SENT, true ), static fn( $t ): bool => (int) $t > time() - HOUR_IN_SECONDS ) );

		if ( $sent && (int) end( $sent ) > time() - self::RESEND_GAP ) {
			return self::WAIT;
		}

		if ( count( $sent ) >= self::RESEND_HOURLY ) {
			return self::TOO_MANY;
		}

		$provider = self::provider();

		if ( null === $provider ) {
			return self::NOT_AVAILABLE;
		}

		if ( ! self::may_send_to( $phone ) ) {
			// Test mode: the code is written down, not sent, and the landlord
			// is told plainly rather than left waiting for a text that will
			// never come.
			if ( class_exists( '\TDH\Log' ) ) {
				Log::info( 'sms', 'sms_skipped_test_mode', __( 'Test mode: a verification code was written down instead of sent.', 'thirtydayhomes' ), [ 'phone' => $phone ], $user_id, 'user' );
			}

			return self::NOT_AVAILABLE;
		}

		$code = (string) wp_rand( 100000, 999999 );

		update_user_meta( $user_id, self::META_CODE_HASH, self::hash_code( $user_id, $code ) );
		update_user_meta( $user_id, self::META_CODE_EXPIRES, time() + self::CODE_TTL );
		update_user_meta( $user_id, self::META_CODE_TRIES, 0 );

		$sent[] = time();
		update_user_meta( $user_id, self::META_CODE_SENT, $sent );

		$result = $provider->send(
			$phone,
			/* translators: %s: a six-digit code */
			sprintf( __( 'Your ThirtyDayHomes code is %s. It expires in 10 minutes. Reply STOP to opt out.', 'thirtydayhomes' ), $code )
		);

		if ( class_exists( '\TDH\Log' ) ) {
			if ( $result['ok'] ) {
				Log::info( 'sms', 'sms_code_sent', __( 'A verification code was texted.', 'thirtydayhomes' ), [ 'phone' => $phone, 'provider' => $provider->name() ], $user_id, 'user' );
			} else {
				Log::error( 'sms', 'sms_code_failed', __( 'A verification code could not be sent.', 'thirtydayhomes' ), [ 'phone' => $phone, 'provider' => $provider->name(), 'reason' => $result['error'] ], $user_id, 'user' );
			}
		}

		return $result['ok'] ? self::CODE_SENT : self::SEND_FAILED;
	}

	/** The landlord typed the code. */
	private function verify( int $user_id, string $back ): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle().
		$typed = preg_replace( '/\D+/', '', (string) ( $_POST['tdh_sms_code'] ?? '' ) ) ?? '';

		$hash    = (string) get_user_meta( $user_id, self::META_CODE_HASH, true );
		$expires = (int) get_user_meta( $user_id, self::META_CODE_EXPIRES, true );
		$tries   = (int) get_user_meta( $user_id, self::META_CODE_TRIES, true );
		$phone   = Phone::normalise( (string) get_user_meta( $user_id, self::META_PHONE, true ) );

		if ( '' === $hash || '' === $phone ) {
			// No code outstanding. The usual way here is Verify pressed
			// twice, the second press arriving after the first did the work
			// — and "expired" on a card that already says On reads as failure.
			self::bounce( $back, self::state_for( $user_id )['verified'] ? self::VERIFIED : self::CODE_EXPIRED );
		}

		if ( $expires < time() ) {
			self::forget_code( $user_id );
			self::bounce( $back, self::CODE_EXPIRED );
		}

		if ( $tries >= self::CODE_ATTEMPTS ) {
			self::forget_code( $user_id );
			self::bounce( $back, self::TOO_MANY );
		}

		if ( ! hash_equals( $hash, self::hash_code( $user_id, $typed ) ) ) {
			update_user_meta( $user_id, self::META_CODE_TRIES, $tries + 1 );

			if ( class_exists( '\TDH\Log' ) ) {
				Log::warning( 'sms', 'sms_verify_failed', __( 'A wrong verification code was entered.', 'thirtydayhomes' ), [ 'phone' => $phone, 'tries' => $tries + 1 ], $user_id, 'user' );
			}

			self::bounce( $back, $tries + 1 >= self::CODE_ATTEMPTS ? self::TOO_MANY : self::WRONG_CODE );
		}

		update_user_meta( $user_id, self::META_VERIFIED, $phone );
		update_user_meta( $user_id, self::META_VERIFIED_AT, time() );
		self::forget_code( $user_id );

		if ( class_exists( '\TDH\Log' ) ) {
			Log::info( 'sms', 'sms_verified', __( 'A landlord confirmed their number. Text alerts are on.', 'thirtydayhomes' ), [ 'phone' => $phone ], $user_id, 'user' );
		}

		self::bounce( $back, self::VERIFIED );
	}

	/** The landlord turned texts off from the card. */
	private function stop( int $user_id, string $back ): void {

		delete_user_meta( $user_id, self::META_CONSENT );
		delete_user_meta( $user_id, self::META_CONSENT_AT );

		if ( class_exists( '\TDH\Log' ) ) {
			Log::info( 'sms', 'sms_consent_withdrawn', __( 'A landlord turned text alerts off.', 'thirtydayhomes' ), [], $user_id, 'user' );
		}

		self::bounce( $back, self::STOPPED );
	}

	private static function hash_code( int $user_id, string $code ): string {
		return hash_hmac( 'sha256', $user_id . ':' . $code, wp_salt( 'auth' ) );
	}

	private static function forget_code( int $user_id ): void {
		delete_user_meta( $user_id, self::META_CODE_HASH );
		delete_user_meta( $user_id, self::META_CODE_EXPIRES );
		delete_user_meta( $user_id, self::META_CODE_TRIES );
	}

	private static function bounce( string $to, string $flag ): void {
		wp_safe_redirect( add_query_arg( self::PARAM_DONE, $flag, $to ) );
		exit;
	}

	/**
	 * What the card says after an action.
	 *
	 * @return array<string,array{0:string,1:string}> flag => [kind, sentence].
	 */
	public static function messages(): array {
		return [
			self::CODE_SENT     => [ 'success', __( 'We texted you a 6-digit code. Enter it below within 10 minutes.', 'thirtydayhomes' ) ],
			self::VERIFIED      => [ 'success', __( 'Your number is confirmed. You will get a text for each new inquiry.', 'thirtydayhomes' ) ],
			self::STOPPED       => [ 'success', __( 'Text alerts are off. You will still get every inquiry by email.', 'thirtydayhomes' ) ],
			self::WRONG_CODE    => [ 'error', __( 'That code is not right. Check the text and try again.', 'thirtydayhomes' ) ],
			self::CODE_EXPIRED  => [ 'error', __( 'That code has expired. Send a new one.', 'thirtydayhomes' ) ],
			self::TOO_MANY      => [ 'error', __( 'Too many tries. Wait a little, then send a new code.', 'thirtydayhomes' ) ],
			self::WAIT          => [ 'error', __( 'A code was sent less than a minute ago. Give it a moment to arrive.', 'thirtydayhomes' ) ],
			self::BAD_NUMBER    => [ 'error', __( 'That does not look like a US mobile number. Enter it like (412) 555-0184.', 'thirtydayhomes' ) ],
			self::NO_CONSENT    => [ 'error', __( 'Tick the box to say you want text alerts first. Nothing was changed.', 'thirtydayhomes' ) ],
			self::NOT_AVAILABLE => [ 'error', __( 'Text alerts are not available on this account yet. You will still get every inquiry by email.', 'thirtydayhomes' ) ],
			self::SEND_FAILED   => [ 'error', __( 'We could not send the code just now. Try again in a minute.', 'thirtydayhomes' ) ],
			self::EXPIRED       => [ 'error', __( 'That page had been open a while. Nothing was changed — please try again.', 'thirtydayhomes' ) ],
			self::STILL_PAUSED  => [ 'error', __( 'Texts to this number are paused because you replied STOP. Reply START to our last text to resume them, or enter a different number.', 'thirtydayhomes' ) ],
		];
	}

	/** @return array{0:string,1:string}|null The current flag's message. */
	public static function notice(): ?array {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only flag.
		$raw = isset( $_GET[ self::PARAM_DONE ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::PARAM_DONE ] ) ) : '';

		return self::messages()[ $raw ] ?? null;
	}

	/* ---------------------------------------------------------------------
	 * Webhooks: STOP/START from the landlord, delivery status from Twilio
	 * ------------------------------------------------------------------ */

	public function register_routes(): void {

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_INBOUND,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_inbound' ],
				// Public by design: the signature check is the authentication.
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_STATUS,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_status' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * A text arrived on our number.
	 *
	 * STOP words pause the landlord; START words resume them. Twilio's own
	 * opt-out handling has already answered the sender at the carrier edge,
	 * so the reply here is empty TwiML — a second confirmation would be
	 * noise.
	 *
	 * @param \WP_REST_Request $request
	 */
	public function rest_inbound( $request ): \WP_REST_Response {

		if ( ! $this->trusted( $request, self::ROUTE_INBOUND ) ) {
			return $this->twiml( 403 );
		}

		$from = Phone::normalise( (string) $request->get_param( 'From' ) );
		$body = strtoupper( trim( (string) $request->get_param( 'Body' ) ) );

		if ( '' === $from ) {
			return $this->twiml( 200 );
		}

		$word = strtok( $body, " \n\t" ) ?: '';

		if ( in_array( $word, self::STOP_WORDS, true ) ) {
			self::set_opt_out( $from, true );
		} elseif ( in_array( $word, self::START_WORDS, true ) ) {
			self::set_opt_out( $from, false );
		}

		return $this->twiml( 200 );
	}

	/**
	 * Twilio telling us what became of a message.
	 *
	 * @param \WP_REST_Request $request
	 */
	public function rest_status( $request ): \WP_REST_Response {

		if ( ! $this->trusted( $request, self::ROUTE_STATUS ) ) {
			return new \WP_REST_Response( null, 403 );
		}

		$sid    = sanitize_text_field( (string) $request->get_param( 'MessageSid' ) );
		$status = sanitize_key( (string) $request->get_param( 'MessageStatus' ) );
		$error  = sanitize_text_field( (string) $request->get_param( 'ErrorCode' ) );

		if ( '' === $sid ) {
			return new \WP_REST_Response( null, 204 );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, inquiry_id FROM ' . Notifications::table() . ' WHERE provider_message_id = %s', $sid ), ARRAY_A );

		if ( ! $row ) {
			return new \WP_REST_Response( null, 204 );
		}

		/*
		 * Only the terminal bad outcomes change the row: an accepted message
		 * that the carrier later refused is a failure staff need to see.
		 * "delivered" confirms what "sent" already said and is logged only;
		 * the steps in between (queued, sending, sent) are Twilio's own
		 * bookkeeping and would put three lines in the log for every text.
		 */
		if ( in_array( $status, [ 'undelivered', 'failed' ], true ) ) {
			self::mark( (int) $row['id'], Notifications::GIVEN_UP, sprintf( 'Carrier reported %s%s', $status, '' !== $error ? " (error {$error})" : '' ) );

			if ( class_exists( '\TDH\Log' ) ) {
				Log::error( 'sms', 'sms_status', __( 'The carrier could not deliver a text that had been accepted. The inquiry itself is safe, and the landlord was emailed.', 'thirtydayhomes' ), [ 'status' => $status, 'error_code' => $error, 'message_id' => $sid ], (int) $row['inquiry_id'], 'inquiry' );
			}
		} elseif ( 'delivered' === $status && class_exists( '\TDH\Log' ) ) {
			Log::info( 'sms', 'sms_status', __( 'The carrier confirmed the text was delivered.', 'thirtydayhomes' ), [ 'status' => $status, 'message_id' => $sid ], (int) $row['inquiry_id'], 'inquiry' );
		}

		return new \WP_REST_Response( null, 204 );
	}

	/** Is this webhook signed by our Twilio account? */
	private function trusted( \WP_REST_Request $request, string $route ): bool {

		$params = [];

		foreach ( (array) $request->get_body_params() as $key => $value ) {
			$params[ (string) $key ] = is_scalar( $value ) ? (string) $value : '';
		}

		$ok = Twilio::verify_signature(
			rest_url( self::REST_NAMESPACE . $route ),
			$params,
			(string) $request->get_header( 'x_twilio_signature' )
		);

		/**
		 * Lets the test suite drive the routes with a fake signature check.
		 *
		 * @param bool             $ok      Whether the signature verified.
		 * @param \WP_REST_Request $request The request.
		 */
		$ok = (bool) apply_filters( 'tdh_sms_webhook_trusted', $ok, $request );

		if ( ! $ok && class_exists( '\TDH\Log' ) ) {
			Log::warning( 'sms', 'sms_webhook_rejected', __( 'A text-message webhook arrived without a valid signature and was ignored.', 'thirtydayhomes' ), [ 'route' => $route ] );
		}

		return $ok;
	}

	private function twiml( int $code ): \WP_REST_Response {

		$response = new \WP_REST_Response( '<?xml version="1.0" encoding="UTF-8"?><Response></Response>', $code );
		$response->header( 'Content-Type', 'text/xml; charset=utf-8' );

		return $response;
	}

	/** Every landlord on this number is paused, or resumed. */
	private static function set_opt_out( string $phone, bool $out ): void {

		$users = get_users(
			[
				'meta_key'   => self::META_VERIFIED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $phone,              // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'     => 'ID',
			]
		);

		foreach ( $users as $user_id ) {

			$user_id = (int) $user_id;

			if ( $out ) {
				update_user_meta( $user_id, self::META_OPTED_OUT, '1' );
				update_user_meta( $user_id, self::META_OPTED_OUT_AT, time() );
			} else {
				delete_user_meta( $user_id, self::META_OPTED_OUT );
				delete_user_meta( $user_id, self::META_OPTED_OUT_AT );
			}

			if ( class_exists( '\TDH\Log' ) ) {
				Log::info(
					'sms',
					$out ? 'sms_opted_out' : 'sms_resumed',
					$out ? __( 'A landlord replied STOP. Texts are paused.', 'thirtydayhomes' ) : __( 'A landlord replied START. Texts resume.', 'thirtydayhomes' ),
					[ 'phone' => $phone ],
					$user_id,
					'user'
				);
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Test mode, said out loud
	 * ------------------------------------------------------------------ */

	/**
	 * On every admin page while test mode is on.
	 *
	 * Test mode on a production site is the intended state while the
	 * owner's phone is the only one being texted, and a mistake the day the
	 * campaign is approved and everyone else's texts are still being
	 * written down. The notice is how the second case gets noticed.
	 */
	public function test_mode_notice(): void {

		if ( ! self::is_test_mode() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$numbers = array_map( [ Phone::class, 'display' ], self::test_recipients() );
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Text alerts are in test mode.', 'thirtydayhomes' ); ?></strong>
				<?php
				printf(
					/* translators: %s: a list of phone numbers */
					esc_html__( 'Only these numbers are texted: %s. Every other landlord’s text is written down as “not sent”. Remove TDH_SMS_TEST_RECIPIENTS from wp-config.php when the carrier approves the campaign.', 'thirtydayhomes' ),
					esc_html( implode( ', ', $numbers ) )
				);
				?>
			</p>
		</div>
		<?php
	}
}
