<?php
/**
 * Telling the landlord an inquiry arrived.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Every inquiry emails the landlord; every attempt is written down.
 *
 * ─── THE RENTER NEVER LEARNS WHETHER THIS WORKED ───────────────────────────
 *
 * D1 stores the inquiry and answers the renter before this class runs. A
 * broken mail server, a wrong address, a host with no SMTP at all — none
 * of it can reach the renter's screen, because by then they have gone.
 * The contract is explicit: "a notification failure must not silently
 * discard a successfully stored inquiry". Here the emphasis is on
 * *silently*: the failure is loud, but only to us.
 *
 * ─── ONE ROW PER ENQUIRY PER CHANNEL ───────────────────────────────────────
 *
 * `wp_tdh_notifications` holds one row per (inquiry, channel), and an
 * attempt increments a counter on it rather than adding a row. Three
 * attempts at one email are one thing that happened three times, not
 * three things; a staff screen listing it three times would read as three
 * inquiries.
 *
 * ─── RETRIES ───────────────────────────────────────────────────────────────
 *
 * +10 minutes, then +60, then it gives up and says so. WordPress cron
 * only runs when somebody visits the site, so a quiet night would leave a
 * failure unretried until morning — `sweep()` picks up anything overdue
 * on the next admin page load as well.
 */
final class Notifications {

	public const CHANNEL_EMAIL = 'email';

	public const QUEUED   = 'queued';
	public const SENT     = 'sent';
	public const FAILED   = 'failed';
	public const GIVEN_UP = 'given_up';

	/** Including the first. Two retries follow it. */
	public const MAX_ATTEMPTS = 3;

	/** How long after a failure each retry is scheduled, in seconds. */
	private const BACKOFF = [ 600, 3600 ];

	/**
	 * How long a queued row is left for cron before the sweep takes it.
	 *
	 * Two minutes is long enough that the sweep is not racing the loopback
	 * request on a healthy site, and short enough that a host with cron
	 * switched off still gets the landlord's email out while the renter is
	 * plausibly still at their desk.
	 */
	private const QUEUED_GRACE = 120;

	private const RETRY_HOOK = 'tdh_notification_retry';

	/** Staff switch: send a copy of every inquiry somewhere. Off by default. */
	public const OPTION_COPY    = 'tdh_inquiry_admin_copy';
	public const OPTION_COPY_TO = 'tdh_inquiry_admin_copy_to';

	/** The staff Resend button. */
	public const RESEND_ACTION = 'tdh_resend_email';
	private const RESEND_NONCE = 'tdh_resend_email';

	/*
	 * Its own query argument. `inq` already answers a landlord tidying
	 * their inbox, and one name with two meanings would sooner or later
	 * put "Message archived" on the screen after a resend.
	 */
	public const PARAM_DONE = 'send';

	public const RESENT  = 'resent';
	public const NOT_YET = 'not_yet';
	public const EXPIRED = 'expired';
	public const REFUSED = 'refused';

	public function register(): void {

		add_action( 'tdh_inquiry_received', [ $this, 'on_inquiry' ], 10, 2 );
		add_action( self::RETRY_HOOK, [ $this, 'retry' ] );
		add_action( 'template_redirect', [ $this, 'handle_resend' ] );

		/*
		 * The catch-up. Cheap: one indexed query, and only for somebody
		 * who is already inside the admin.
		 */
		add_action( 'admin_init', [ $this, 'sweep' ] );
	}

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'tdh_notifications';
	}

	/* ---------------------------------------------------------------------
	 * Who the email goes to
	 * ------------------------------------------------------------------ */

	/**
	 * The landlord's address for this home.
	 *
	 * The listing's own contact address when the landlord gave one and it
	 * is usable, otherwise their account address. A contact address that
	 * is not an address is not an error the renter caused, so it falls
	 * back rather than failing — but the fallback is recorded, because a
	 * landlord who mistyped their address should be told eventually.
	 *
	 * @return array{to:string,fell_back:bool,typed:string}
	 */
	public static function recipient_for( int $listing_id ): array {

		$typed = (string) get_post_meta( $listing_id, '_tdh_contact_email', true );

		if ( '' !== $typed && is_email( $typed ) ) {
			return [
				'to'        => $typed,
				'fell_back' => false,
				'typed'     => $typed,
			];
		}

		$author = (int) get_post_field( 'post_author', $listing_id );
		$user   = $author ? get_userdata( $author ) : false;
		$email  = $user ? (string) $user->user_email : '';

		return [
			'to'        => is_email( $email ) ? $email : '',
			'fell_back' => '' !== $typed,
			'typed'     => $typed,
		];
	}

	/** Where a copy goes, or '' when the staff switch is off. */
	public static function copy_to(): string {

		if ( '1' !== (string) get_option( self::OPTION_COPY, '' ) ) {
			return '';
		}

		$to = (string) get_option( self::OPTION_COPY_TO, '' );

		return is_email( $to ) ? $to : '';
	}

	/* ---------------------------------------------------------------------
	 * The queue
	 * ------------------------------------------------------------------ */

	/**
	 * Find or make the row for this inquiry and channel.
	 *
	 * @return int The row id, or 0 if it could not be written.
	 */
	public static function queue( int $inquiry_id, string $channel, string $recipient ): int {

		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE inquiry_id = %d AND channel = %s", $inquiry_id, $channel ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( $existing ) {
			return $existing;
		}

		$now = current_time( 'mysql', true );

		$wpdb->insert(
			$table,
			[
				'inquiry_id' => $inquiry_id,
				'channel'    => $channel,
				'recipient'  => $recipient,
				'status'     => self::QUEUED,
				'attempts'   => 0,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);
		// phpcs:enable

		return (int) $wpdb->insert_id;
	}

	/**
	 * One row, as an array, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function row( int $id ): ?array {

		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Every row for one inquiry.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_inquiry( int $inquiry_id ): array {

		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE inquiry_id = %d ORDER BY id ASC", $inquiry_id ), ARRAY_A );

		return is_array( $rows ) ? $rows : [];
	}

	/** Write the outcome of one attempt. */
	private static function mark( int $id, string $status, string $response = '' ): void {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::table(),
			[
				'status'            => $status,
				/*
				 * Truncated, and never a secret: this is whatever the mail
				 * server said, and it is shown on a staff screen.
				 */
				'provider_response' => mb_substr( $response, 0, 500 ),
				'updated_at'        => current_time( 'mysql', true ),
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	/* ---------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------ */

	/**
	 * An inquiry arrived. Queue the landlord's email and try it now.
	 *
	 * Synchronous, and deliberately after the record exists: a mail server
	 * that hangs cannot cost the renter their inquiry, because by the time
	 * this runs the inquiry is already stored and the renter has already
	 * been told it was.
	 */
	public function on_inquiry( $inquiry_id, $listing_id = 0 ): void {

		$inquiry_id = (int) $inquiry_id;
		$listing_id = (int) $listing_id ?: (int) get_post_meta( $inquiry_id, '_tdh_listing_id', true );

		$who = self::recipient_for( $listing_id );

		if ( '' === $who['to'] ) {
			/*
			 * Nobody to write to. A row is still written: "we did not send
			 * this, and here is why" is a fact staff need, and an inquiry
			 * with no notification row at all looks like one that was
			 * never processed.
			 */
			$id = self::queue( $inquiry_id, self::CHANNEL_EMAIL, '' );

			if ( $id ) {
				self::mark( $id, self::GIVEN_UP, 'no usable address for this home' );
			}

			if ( class_exists( '\TDH\Log' ) ) {
				Log::error(
					'email',
					'inquiry_no_recipient',
					__( 'An inquiry could not be emailed: the home has no usable contact address and its owner has none either.', 'thirtydayhomes' ),
					[ 'listing' => $listing_id ],
					$inquiry_id,
					'inquiry'
				);
			}

			return;
		}

		if ( $who['fell_back'] && class_exists( '\TDH\Log' ) ) {
			Log::warning(
				'email',
				'contact_email_unusable',
				__( 'The address on this home could not be used, so the inquiry went to the account address instead.', 'thirtydayhomes' ),
				[
					'typed' => $who['typed'],
					'email' => $who['to'],
				],
				$listing_id,
				'listing'
			);
		}

		$id = self::queue( $inquiry_id, self::CHANNEL_EMAIL, $who['to'] );

		if ( $id ) {
			/*
			 * Booked for right now, NOT sent on this request.
			 *
			 * This runs between the renter's inquiry being stored and their
			 * success screen being sent, so anything slow here is time the
			 * renter spends looking at a page that has not moved. A mail
			 * server that is not answering costs Smtp::TIMEOUT — fifteen
			 * seconds — and a renter who gives up before then never sees
			 * the confirmation for a message that was saved perfectly well.
			 * Silent success reads as failure.
			 *
			 * WordPress spawns due events in a separate, non-blocking
			 * request, so the renter's page returns at once and the landlord
			 * is emailed a moment later. The row exists either way, so staff
			 * see "Email queued" rather than nothing, and sweep() below
			 * still collects it on a host where cron never fires.
			 */
			wp_schedule_single_event( time(), self::RETRY_HOOK, [ $id ] );
		}
	}

	/**
	 * Try one row once.
	 *
	 * @return bool Whether this attempt got through.
	 */
	public static function send( int $id ): bool {

		$row = self::row( $id );

		if ( null === $row ) {
			return false;
		}

		/*
		 * Terminal, both of them. Sent is obvious; given up is deliberate —
		 * it was decided after the third refusal and has to stay decided.
		 * A stray cron event, a sweep and a staff click can all reach the
		 * same row, and without this guard each one drives the counter past
		 * the maximum: the screen then says "4 attempts" under a message
		 * promising three, and the ceiling stops meaning anything. Staff
		 * who want another go press Resend, which is resend() below.
		 */
		if ( self::SENT === $row['status'] || self::GIVEN_UP === $row['status'] ) {
			return false;
		}

		/*
		 * This method sends EMAIL. The table is shared with D4's sms rows,
		 * and everything below ends in wp_mail() — so a row of the wrong
		 * channel reaching here would put a phone number in a To header.
		 * Refused rather than trusted to never happen.
		 */
		if ( self::CHANNEL_EMAIL !== (string) $row['channel'] ) {
			return false;
		}

		$inquiry_id = (int) $row['inquiry_id'];
		$to         = (string) $row['recipient'];
		$attempts   = (int) $row['attempts'] + 1;

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( self::table(), [ 'attempts' => $attempts ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );

		/*
		 * Capture whatever the mail layer complains about. wp_mail()
		 * answers only true or false; the reason arrives on this hook, and
		 * without it every failure reads "something went wrong", which the
		 * master prompt forbids as the only trace.
		 */
		$reason = '';
		$catch  = static function ( $error ) use ( &$reason ): void {
			$reason = is_wp_error( $error ) ? (string) $error->get_error_message() : 'unknown';
		};

		add_action( 'wp_mail_failed', $catch );

		$sent = wp_mail(
			$to,
			self::subject( $inquiry_id ),
			self::body( $inquiry_id ),
			self::headers( $inquiry_id )
		);

		remove_action( 'wp_mail_failed', $catch );

		if ( $sent ) {
			self::mark( $id, self::SENT, '' );
			self::unschedule( $id );
			self::send_copy( $inquiry_id );

			if ( class_exists( '\TDH\Log' ) ) {
				Log::info(
					'email',
					'inquiry_sent',
					__( 'Inquiry emailed to the landlord.', 'thirtydayhomes' ),
					[
						'email'    => $to,
						'attempts' => $attempts,
					],
					$inquiry_id,
					'inquiry'
				);
			}

			return true;
		}

		$terminal = $attempts >= self::MAX_ATTEMPTS;

		self::mark( $id, $terminal ? self::GIVEN_UP : self::FAILED, '' !== $reason ? $reason : 'the mail server refused it' );

		/*
		 * Clear whatever is already on the books before booking the next
		 * one. A sweep and a cron event can both reach this row, and each
		 * failure that left its own event behind would stack another retry
		 * on top — all of them still firing long after the row is finished
		 * with, and the last of them firing on a row that gave up.
		 */
		self::unschedule( $id );

		if ( ! $terminal ) {
			$delay = self::BACKOFF[ $attempts - 1 ] ?? end( self::BACKOFF );
			wp_schedule_single_event( time() + $delay, self::RETRY_HOOK, [ $id ] );
		}

		if ( class_exists( '\TDH\Log' ) ) {
			Log::error(
				'email',
				$terminal ? 'inquiry_given_up' : 'inquiry_failed',
				$terminal
					? __( 'An inquiry could not be emailed after three attempts. The inquiry itself is safe in the dashboard.', 'thirtydayhomes' )
					: __( 'An inquiry email failed and will be tried again.', 'thirtydayhomes' ),
				[
					'email'    => $to,
					'attempts' => $attempts,
					'reason'   => $reason,
				],
				$inquiry_id,
				'inquiry'
			);
		}

		return false;
	}

	/** A scheduled retry. */
	public function retry( $id ): void {
		self::send( (int) $id );
	}

	/** Drop any retry already booked for this row. */
	private static function unschedule( int $id ): void {
		wp_clear_scheduled_hook( self::RETRY_HOOK, [ $id ] );
	}

	/**
	 * Staff pressing Resend.
	 *
	 * A fresh start, not a fourth attempt. The counter goes back to zero
	 * and the row back to queued, for two reasons: send() refuses a row
	 * that gave up, so without this the button would be dead on the only
	 * rows anybody ever wants to press it on; and a landlord who has since
	 * corrected a mistyped address deserves the full three attempts again,
	 * not the one that is left.
	 *
	 * The recipient is looked up again rather than reused, because the
	 * wrong address is the most common reason this row is here at all.
	 */
	public static function resend( int $id ): bool {

		$row = self::row( $id );

		if ( null === $row ) {
			return false;
		}

		$inquiry_id = (int) $row['inquiry_id'];
		$listing_id = (int) get_post_meta( $inquiry_id, '_tdh_listing_id', true );
		$who        = self::recipient_for( $listing_id );

		if ( '' === $who['to'] ) {
			/*
			 * Still nowhere to write to. Say exactly that instead of
			 * spending an attempt to discover it again.
			 */
			self::mark( $id, self::GIVEN_UP, __( 'There is still no usable address for this home.', 'thirtydayhomes' ) );

			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::table(),
			[
				'recipient'         => $who['to'],
				'status'            => self::QUEUED,
				'attempts'          => 0,
				'provider_response' => '',
				'updated_at'        => current_time( 'mysql', true ),
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%d', '%s', '%s' ],
			[ '%d' ]
		);

		return self::send( $id );
	}

	/**
	 * Anything that failed and is overdue, tried again.
	 *
	 * The safety net for a host whose cron only fires on a page view: on a
	 * quiet site a failure would otherwise wait until somebody happened to
	 * visit. Runs on admin_init, so it costs a visitor nothing.
	 */
	public function sweep(): void {

		global $wpdb;

		$table = self::table();

		/*
		 * The shortest wait of any kind is the floor the query asks for;
		 * each row's own wait is applied below. Queued rows are collected
		 * as well as failed ones — on a host with cron switched off the
		 * first attempt is the one that never happens, and a row that sat
		 * queued for ever would be worse than one that failed loudly.
		 */
		$due = gmdate( 'Y-m-d H:i:s', time() - min( self::QUEUED_GRACE, self::BACKOFF[0] ) );

		/*
		 * Email only.
		 *
		 * This is the EMAIL catch-up, and it hands rows to send(), which
		 * calls wp_mail(). The moment queued rows were included here that
		 * stopped being obvious: D4 writes `sms` rows to this same table
		 * and they start life queued too, so without this clause the first
		 * text message anybody sent would be posted to wp_mail() with a
		 * phone number in the To field.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, status, attempts, updated_at FROM {$table}
				 WHERE channel = %s
				   AND ( ( status = %s AND attempts < %d ) OR status = %s )
				   AND updated_at <= %s
				 ORDER BY updated_at ASC LIMIT 5",
				self::CHANNEL_EMAIL,
				self::FAILED,
				self::MAX_ATTEMPTS,
				self::QUEUED,
				$due
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {

			$attempts = (int) $row['attempts'];

			$wait = self::QUEUED === (string) $row['status']
				? self::QUEUED_GRACE
				: ( self::BACKOFF[ $attempts - 1 ] ?? end( self::BACKOFF ) );

			/*
			 * Every attempt has its own backoff and the query can only ask
			 * for the shortest of them, so the rest are filtered here. A
			 * row on its second attempt waits an hour, not ten minutes:
			 * sweeping it early would spend all three attempts inside the
			 * first ten minutes of an outage, which is precisely when a
			 * mail server is least likely to have recovered.
			 */
			if ( strtotime( (string) $row['updated_at'] . ' UTC' ) > time() - $wait ) {
				continue;
			}

			self::send( (int) $row['id'] );
		}
	}

	/** Send the staff copy, when one is switched on. */
	private static function send_copy( int $inquiry_id ): void {

		$to = self::copy_to();

		if ( '' === $to ) {
			return;
		}

		$sent = wp_mail(
			$to,
			self::subject( $inquiry_id ),
			self::body( $inquiry_id, true ),
			self::headers( $inquiry_id )
		);

		/*
		 * A copy that does not arrive is not an error — the landlord, who
		 * is the one who needs it, got theirs, and the notification row
		 * says so truthfully. But it is not nothing either: without this
		 * line the copies could stop for a month and the only clue would
		 * be somebody eventually noticing an empty mailbox.
		 */
		if ( ! $sent && class_exists( '\TDH\Log' ) ) {
			Log::warning(
				'email',
				'inquiry_copy_failed',
				__( 'The landlord was emailed, but the copy to the second address was not.', 'thirtydayhomes' ),
				[ 'email' => $to ],
				$inquiry_id,
				'inquiry'
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * What the email says
	 * ------------------------------------------------------------------ */

	public static function subject( int $inquiry_id ): string {

		$about = class_exists( '\TDH\Inquiry' ) ? Inquiry::about( $inquiry_id ) : [ 'name' => '' ];

		return sprintf(
			/* translators: 1: site name, 2: the home's name */
			__( '[%1$s] New inquiry about %2$s', 'thirtydayhomes' ),
			get_bloginfo( 'name' ),
			$about['name']
		);
	}

	/**
	 * The body.
	 *
	 * The renter's message is NOT included in full and neither is their
	 * phone number: an inbox is a less careful place than the dashboard,
	 * and mail can be forwarded. The email says who wrote, about which
	 * home, when they want it, and links to the message.
	 */
	public static function body( int $inquiry_id, bool $for_staff = false ): string {

		$name    = (string) get_post_meta( $inquiry_id, '_tdh_renter_name', true );
		$move_in = (string) get_post_meta( $inquiry_id, '_tdh_move_in', true );
		$stay    = class_exists( '\TDH\Inquiry' ) ? Inquiry::stay_label( (string) get_post_meta( $inquiry_id, '_tdh_stay_length', true ) ) : '';
		$about   = class_exists( '\TDH\Inquiry' ) ? Inquiry::about( $inquiry_id ) : [ 'name' => '' ];

		if ( '' !== $move_in && class_exists( '\TDH\Availability' ) ) {
			$move_in = Availability::format_day( $move_in );
		}

		$link = add_query_arg(
			[
				'view' => 'inquiries',
				'msg'  => (string) $inquiry_id,
			],
			Accounts::url( 'account' )
		);

		/*
		 * Only the facts we actually have. A home with no move-in date
		 * skips the line rather than printing an empty label, which reads
		 * as a broken email rather than an unanswered question.
		 *
		 * Labels take one space, not a padded column: mail clients render
		 * text/plain in a proportional font, so a column aligned here
		 * arrives ragged there.
		 */
		$facts = array_filter(
			[
				/* translators: %s: the renter's name */
				'' !== $name ? sprintf( __( 'From: %s', 'thirtydayhomes' ), $name ) : '',
				/* translators: %s: the home's name */
				sprintf( __( 'About: %s', 'thirtydayhomes' ), $about['name'] ),
				/* translators: %s: a date */
				'' !== $move_in ? sprintf( __( 'Move-in: %s', 'thirtydayhomes' ), $move_in ) : '',
				/* translators: %s: a length of stay in words */
				'' !== $stay ? sprintf( __( 'Stay: %s', 'thirtydayhomes' ), $stay ) : '',
			],
			static fn( $line ): bool => '' !== $line
		);

		/*
		 * Joined as blocks with a blank line between them. Filtering a flat
		 * list of lines cannot do this: the same test that drops a missing
		 * fact drops the empty strings meant as spacing, and the email
		 * arrives as one unbroken paragraph.
		 */
		return implode(
			"\n\n",
			[
				$for_staff
					? __( 'A copy of an inquiry sent to a landlord.', 'thirtydayhomes' )
					: __( 'Someone has asked about one of your homes.', 'thirtydayhomes' ),
				implode( "\n", $facts ),
				__( 'Read it and reply here:', 'thirtydayhomes' ) . "\n" . $link,
				__( 'You can reply to this email directly and it will reach them.', 'thirtydayhomes' ),
			]
		);
	}

	/**
	 * Headers.
	 *
	 * Reply-To is the renter, so the landlord can simply press reply. From
	 * stays our own authenticated address: putting the renter's there
	 * would fail SPF and land the one email that must not be missed in
	 * spam. The display name has its delimiters stripped first, because
	 * wp_mail() splits Reply-To on COMMAS and a renter called
	 * "Dana Whitfield, Jr." would otherwise produce two addresses, the
	 * second one nonsense.
	 *
	 * @return string[]
	 */
	public static function headers( int $inquiry_id ): array {

		$email = (string) get_post_meta( $inquiry_id, '_tdh_renter_email', true );

		if ( ! is_email( $email ) ) {
			return [];
		}

		$name = (string) get_post_meta( $inquiry_id, '_tdh_renter_name', true );
		$name = trim( str_replace( [ ',', ';', '<', '>', '"' ], ' ', $name ) );
		$name = (string) preg_replace( '/\s+/', ' ', $name );

		return [
			'' !== $name
				? sprintf( 'Reply-To: %s <%s>', $name, $email )
				: 'Reply-To: ' . $email,
		];
	}

	/* ---------------------------------------------------------------------
	 * The staff Resend button
	 * ------------------------------------------------------------------ */

	/**
	 * Staff pressed Resend on a message.
	 *
	 * POST-redirect-GET, like every other action in the portal, so the Back
	 * button cannot make the site send another email.
	 */
	public function handle_resend(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked below, once we know it is ours.
		if ( ! isset( $_POST['tdh_action'] ) || self::RESEND_ACTION !== sanitize_key( wp_unslash( (string) $_POST['tdh_action'] ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
		$id = (int) ( $_POST['tdh_inquiry'] ?? 0 );

		/*
		 * Back to the message that was open, not to the top of the list:
		 * whoever pressed Resend wants to see what happened to this one.
		 */
		$back = Accounts::url( 'account' );
		$back = '' !== $back ? add_query_arg( 'view', 'inquiries', $back ) : home_url( '/' );
		$back = $id > 0 ? add_query_arg( Inquiry::PARAM_OPEN, (string) $id, $back ) : $back;

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), self::RESEND_NONCE ) ) {
			self::bounce( $back, self::EXPIRED );
		}

		/*
		 * Staff only, and a wrong id is answered exactly as a refusal is.
		 * A landlord may read their own messages but may not make the site
		 * send mail on demand — and answering the two cases differently
		 * would let anybody walk the ids to learn which exist.
		 */
		if ( $id <= 0 || ! Accounts::is_staff() ) {
			self::bounce( $back, self::REFUSED );
		}

		$row_id = 0;

		foreach ( self::for_inquiry( $id ) as $row ) {
			if ( self::CHANNEL_EMAIL === $row['channel'] ) {
				$row_id = (int) $row['id'];
				break;
			}
		}

		if ( 0 === $row_id ) {
			self::bounce( $back, self::REFUSED );
		}

		/*
		 * Double submit. The second press of a button that sends real email
		 * must not send it twice, and the answer it gets is the same as the
		 * first — because from the presser's side the same thing happened.
		 */
		$lock = 'tdh_resend_' . $row_id;

		if ( get_transient( $lock ) ) {
			self::bounce( $back, self::RESENT );
		}

		set_transient( $lock, 1, 30 );

		self::bounce( $back, self::resend( $row_id ) ? self::RESENT : self::NOT_YET );
	}

	private static function bounce( string $to, string $reason ): void {
		wp_safe_redirect( add_query_arg( self::PARAM_DONE, $reason, $to ) );
		exit;
	}

	/**
	 * What the screen says after a Resend.
	 *
	 * @return array<string,string>
	 */
	public static function resend_messages(): array {
		return [
			self::RESENT  => __( 'Sent again — the mail server accepted it this time.', 'thirtydayhomes' ),
			self::NOT_YET => __( 'It still could not be sent. What the mail server said is under Delivery below. The message itself is safe here either way.', 'thirtydayhomes' ),
			self::EXPIRED => __( 'That page had been open a while. Nothing was sent — please try again.', 'thirtydayhomes' ),
			self::REFUSED => __( 'That message could not be found.', 'thirtydayhomes' ),
		];
	}

	/** The sentence to show right now, or ''. */
	public static function resend_notice(): string {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only flag.
		$raw = isset( $_GET[ self::PARAM_DONE ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::PARAM_DONE ] ) ) : '';

		return (string) ( self::resend_messages()[ $raw ] ?? '' );
	}

	/** The hidden fields a Resend button needs. */
	public static function resend_fields( int $inquiry_id ): string {

		ob_start();
		wp_nonce_field( self::RESEND_NONCE );
		?>
		<input type="hidden" name="tdh_action" value="<?php echo esc_attr( self::RESEND_ACTION ); ?>">
		<input type="hidden" name="tdh_inquiry" value="<?php echo esc_attr( (string) $inquiry_id ); ?>">
		<?php

		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * What staff see
	 * ------------------------------------------------------------------ */

	/**
	 * The delivery state of one inquiry, in words.
	 *
	 * Words, not a colour: "Email failed" and "Sent" must be tellable
	 * apart by somebody who cannot distinguish red from green.
	 *
	 * @return array{state:string,label:string,detail:string}
	 */
	public static function state_of( int $inquiry_id ): array {

		$rows = self::for_inquiry( $inquiry_id );

		foreach ( $rows as $row ) {

			if ( self::CHANNEL_EMAIL !== $row['channel'] ) {
				continue;
			}

			$status   = (string) $row['status'];
			$attempts = (int) $row['attempts'];

			$labels = [
				self::SENT     => __( 'Emailed', 'thirtydayhomes' ),
				self::QUEUED   => __( 'Email queued', 'thirtydayhomes' ),
				self::FAILED   => __( 'Email failed — trying again', 'thirtydayhomes' ),
				self::GIVEN_UP => __( 'Email failed', 'thirtydayhomes' ),
			];

			return [
				'state'  => $status,
				'label'  => (string) ( $labels[ $status ] ?? $status ),
				'detail' => self::GIVEN_UP === $status || self::FAILED === $status
					? sprintf(
						/* translators: 1: number of attempts, 2: what the mail server said */
						_n( '%1$s attempt · %2$s', '%1$s attempts · %2$s', $attempts, 'thirtydayhomes' ),
						number_format_i18n( $attempts ),
						(string) $row['provider_response']
					)
					: '',
			];
		}

		return [
			'state'  => '',
			'label'  => '',
			'detail' => '',
		];
	}
}
