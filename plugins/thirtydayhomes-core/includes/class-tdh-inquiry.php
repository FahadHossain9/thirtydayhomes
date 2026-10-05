<?php
/**
 * A renter's inquiry about one home.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * The renter writes to the owner of a home, and is told so.
 *
 * ─── THE ORDER IS THE FEATURE ──────────────────────────────────────────────
 *
 * Store first, notify second. The contract is explicit that "a notification
 * failure must not silently discard a successfully stored inquiry", so the
 * renter's success depends on ONE thing: the record existing. Email (D3)
 * and SMS (D4) hang off `tdh_inquiry_received` afterwards and cannot fail
 * the send. A renter who sees "sent" has been told the truth even when
 * every notification behind it is broken.
 *
 * ─── WHAT MAKES A SEND SUCCEED ─────────────────────────────────────────────
 *
 * The home must be publicly visible at the moment of the POST, not at the
 * moment the page was drawn. A home paused while the renter was typing is
 * refused and says so, because writing to a landlord about a home they have
 * withdrawn wastes everybody's time. Nothing else refuses by identity: a
 * landlord may inquire about their own home, which is harmless and useful
 * for testing.
 *
 * ─── PRIVACY ───────────────────────────────────────────────────────────────
 *
 * The renter's name, address, phone and message are private meta on a
 * private post type. Nothing here is ever printed on a public page, and the
 * stash that survives a validation error is dropped entirely when the
 * visitor cannot be told apart from the next person behind the same router
 * ({@see Accounts::key_is_private()}).
 */
final class Inquiry {

	private const ACTION = 'tdh_inquire';
	private const NONCE  = 'tdh_inquire_send';

	/** The query argument the property page reads after the redirect. */
	public const PARAM = 'inquiry';

	/** The id of the section that holds the form on a home's page. */
	public const ANCHOR = 'inquire';

	public const SENT     = 'sent';
	public const EXPIRED  = 'expired';
	public const TOO_MANY = 'too_many';
	public const INVALID  = 'invalid';
	public const GONE     = 'gone';
	public const FAILED   = 'failed';

	/**
	 * The version of the rules the renter agreed to.
	 *
	 * Stored on every inquiry (register R24) so that a dispute months later
	 * can be answered with the wording that was actually on screen. Bump it
	 * whenever the Terms or the house rules change; never reuse a number.
	 */
	public const RULES_VERSION = '2026-09';

	private const MAX_MESSAGE = 5000;

	/** Inquiries per IP per hour. */
	private const RATE_LIMIT  = 5;
	private const RATE_WINDOW = HOUR_IN_SECONDS;

	/**
	 * How long an identical inquiry counts as the one already sent.
	 *
	 * A double press, an impatient reload and a Back-then-Send all land
	 * here. The second one is answered with success rather than an error,
	 * because from the renter's side it IS the same message and it did
	 * arrive — and the landlord is not shown the same inquiry twice.
	 */
	private const DEDUPE_WINDOW = 10 * MINUTE_IN_SECONDS;

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'handle' ] );
		add_action( 'template_redirect', [ $this, 'handle_archive' ] );
		add_action( 'tdh_listing_inquiry_form', [ $this, 'render' ] );
	}

	/* ---------------------------------------------------------------------
	 * Putting one away, and taking it back out (D2)
	 * ------------------------------------------------------------------ */

	private const ARCHIVE_ACTION = 'tdh_inquiry_archive';
	private const ARCHIVE_NONCE  = 'tdh_inquiry_archive';

	/*
	 * The inbox has its own two query arguments, deliberately NOT `inquiry`.
	 *
	 * `inquiry` already means "what happened when you pressed Send" on a
	 * property page. Reusing it here for a message id would give one name
	 * two meanings, and `?inquiry=123` would quietly fall through
	 * reason()'s whitelist as "nothing happened" — the kind of collision
	 * that reads as a bug months later.
	 */
	public const PARAM_OPEN = 'msg';
	public const PARAM_DONE = 'inq';

	public const ARCHIVED   = 'archived';
	public const UNARCHIVED = 'unarchived';
	public const REFUSED    = 'refused';

	/**
	 * Archive or unarchive one inquiry.
	 *
	 * Nothing is ever deleted: archiving moves a message to its own tab and
	 * unarchiving brings it back, so the control is secondary rather than
	 * destructive and needs no confirmation.
	 *
	 * POST-redirect-GET, so the Back button cannot repeat it — and it is
	 * idempotent anyway: archiving an archived message leaves it archived.
	 */
	public function handle_archive(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked below, once we know it is ours.
		if ( ! isset( $_POST['tdh_action'] ) || self::ARCHIVE_ACTION !== sanitize_key( wp_unslash( (string) $_POST['tdh_action'] ) ) ) {
			return;
		}

		/*
		 * `account`, not `dashboard`: no page is seeded under that key and
		 * Accounts::url() answers an unknown key with the home page, so an
		 * archive would have redirected to the front of the site.
		 */
		$back = Accounts::url( 'account' );
		$back = '' !== $back ? add_query_arg( 'view', 'inquiries', $back ) : home_url( '/' );

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), self::ARCHIVE_NONCE ) ) {
			$this->bounce( $back, self::EXPIRED, self::PARAM_DONE );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$id = (int) ( $_POST['tdh_inquiry'] ?? 0 );

		/*
		 * A wrong owner is answered exactly as a missing id is. Two
		 * different replies would let somebody walk the ids and learn
		 * which ones exist.
		 */
		if ( ! self::can_read( $id ) ) {
			$this->bounce( $back, self::REFUSED, self::PARAM_DONE );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$to = 'unarchive' === sanitize_key( wp_unslash( (string) ( $_POST['tdh_to'] ?? '' ) ) ) ? 'unarchive' : 'archive';

		if ( 'unarchive' === $to ) {
			// Back to the inbox as opened, not as new: somebody has read it.
			update_post_meta( $id, '_tdh_status', 'opened' );

			/** @param int $id The inquiry. @param string $to archive|unarchive. */
			do_action( 'tdh_inquiry_filed', $id, 'unarchive' );

			$this->bounce( add_query_arg( 'filter', self::FILTER_ARCHIVED, $back ), self::UNARCHIVED, self::PARAM_DONE );
		}

		update_post_meta( $id, '_tdh_status', 'archived' );

		/** @param int $id The inquiry. @param string $to archive|unarchive. */
		do_action( 'tdh_inquiry_filed', $id, 'archive' );

		$this->bounce( $back, self::ARCHIVED, self::PARAM_DONE );
	}

	/**
	 * What the inbox says after an archive, or ''.
	 *
	 * Its own list, separate from {@see messages()}: those are answers to
	 * a renter pressing Send on a property page, these are answers to a
	 * landlord tidying their inbox, and one list serving both would put a
	 * renter's wording in front of a landlord sooner or later.
	 *
	 * @return array<string,string>
	 */
	public static function archive_messages(): array {
		return [
			self::ARCHIVED   => __( 'Message archived. It is on the Archived tab whenever you want it.', 'thirtydayhomes' ),
			self::UNARCHIVED => __( 'Message moved back to your inbox.', 'thirtydayhomes' ),
			self::EXPIRED    => __( 'That page had been open a while. Nothing was changed — please try again.', 'thirtydayhomes' ),
			self::REFUSED    => __( 'That message could not be found.', 'thirtydayhomes' ),
		];
	}

	/** The sentence to show in the inbox right now, or ''. */
	public static function archive_notice(): string {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only flag.
		$raw = isset( $_GET[ self::PARAM_DONE ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::PARAM_DONE ] ) ) : '';

		return (string) ( self::archive_messages()[ $raw ] ?? '' );
	}

	/** The hidden fields an Archive button needs. */
	public static function archive_fields( int $inquiry_id, bool $archived ): string {

		ob_start();
		wp_nonce_field( self::ARCHIVE_NONCE );
		?>
		<input type="hidden" name="tdh_action" value="<?php echo esc_attr( self::ARCHIVE_ACTION ); ?>">
		<input type="hidden" name="tdh_inquiry" value="<?php echo esc_attr( (string) $inquiry_id ); ?>">
		<input type="hidden" name="tdh_to" value="<?php echo $archived ? 'unarchive' : 'archive'; ?>">
		<?php

		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * The words
	 * ------------------------------------------------------------------ */

	/**
	 * How long the renter expects to stay, as people say it.
	 *
	 * Written out rather than stored as a number of days, because "13 weeks"
	 * is how a travelling nurse describes a contract and turning it into
	 * "91 days" on the landlord's screen loses that.
	 *
	 * @return array<string,string>
	 */
	public static function stays(): array {
		return [
			'30'     => __( '30 days', 'thirtydayhomes' ),
			'60'     => __( '60 days', 'thirtydayhomes' ),
			'90'     => __( '90 days', 'thirtydayhomes' ),
			'13w'    => __( '13 weeks', 'thirtydayhomes' ),
			'longer' => __( 'Longer than 13 weeks', 'thirtydayhomes' ),
		];
	}

	/** The stay in the renter's words, or '' for a value we do not know. */
	public static function stay_label( string $key ): string {
		return (string) ( self::stays()[ $key ] ?? '' );
	}

	/* ---------------------------------------------------------------------
	 * Handling the send
	 * ------------------------------------------------------------------ */

	public function handle(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked below, once we know this is our form.
		if ( ! isset( $_POST['tdh_action'] ) || self::ACTION !== sanitize_key( wp_unslash( (string) $_POST['tdh_action'] ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- same.
		$listing_id = (int) ( $_POST['tdh_listing'] ?? 0 );
		$listing    = get_post( $listing_id );

		/*
		 * No home, no page to go back to. This is the one failure with
		 * nowhere sensible to return the visitor, so it goes to the
		 * marketplace rather than to a broken permalink.
		 */
		if ( ! $listing instanceof \WP_Post || Post_Types::LISTING !== $listing->post_type ) {
			wp_safe_redirect( (string) get_post_type_archive_link( Post_Types::LISTING ) );
			exit;
		}

		$back = (string) get_permalink( $listing );

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), self::NONCE ) ) {
			// The values are kept, so an expired form is a re-press of Send
			// rather than the whole message typed again.
			$this->remember( $this->typed(), [] );
			$this->bounce( $back, self::EXPIRED );
		}

		/*
		 * The honeypot: a field placed off-screen that no person fills and
		 * every bot does. Answered with the same success a real sender
		 * sees, because telling a bot it was caught only teaches whoever
		 * wrote it to stop filling that field.
		 */
		if ( ! empty( $_POST['tdh_website'] ) ) {
			$this->bounce( $back, self::SENT );
		}

		/*
		 * Public NOW, not when the page was drawn. A home paused while the
		 * renter was typing must not take a message the owner has stopped
		 * expecting.
		 */
		if ( ! Visibility::is_public( $listing_id ) ) {
			$this->refused( self::GONE, $listing_id );
			$this->bounce( $back, self::GONE );
		}

		if ( $this->rate_limited() ) {
			$this->refused( self::TOO_MANY, $listing_id );
			$this->bounce( $back, self::TOO_MANY );
		}

		$values = $this->typed();
		$errors = [];

		if ( '' === $values['name'] ) {
			$errors['name'] = __( 'Please tell the owner your name.', 'thirtydayhomes' );
		}

		/*
		 * The address is read twice. sanitize_email() answers an empty
		 * string for anything it does not recognise, so one missing
		 * character — "dana@example" — becomes nothing at all, and putting
		 * THAT back in the field is how a form clears the box it just
		 * complained about. The typed text goes back on screen; only the
		 * validated address is stored.
		 */
		$email = sanitize_email( $values['email'] );

		if ( ! is_email( $email ) ) {
			$errors['email'] = __( 'Please enter an email address the owner can reply to.', 'thirtydayhomes' );
		}

		if ( '' === $values['move_in'] ) {
			$errors['move_in'] = __( 'Please choose the date you would like to move in.', 'thirtydayhomes' );
		} elseif ( ! Availability::is_date( $values['move_in'] ) ) {
			$errors['move_in'] = __( 'That move-in date could not be read. Please pick it from the calendar.', 'thirtydayhomes' );
		} elseif ( $values['move_in'] < Availability::today() ) {
			$errors['move_in'] = __( 'That move-in date has already passed. Please choose a later one.', 'thirtydayhomes' );
		}

		if ( ! array_key_exists( $values['stay'], self::stays() ) ) {
			$errors['stay'] = __( 'Please say roughly how long you would like to stay.', 'thirtydayhomes' );
		}

		if ( '' === trim( $values['message'] ) ) {
			$errors['message'] = __( 'Please write a short message for the owner.', 'thirtydayhomes' );
		} elseif ( mb_strlen( $values['message'] ) > self::MAX_MESSAGE ) {
			$errors['message'] = sprintf(
				/* translators: %s: maximum number of characters */
				__( 'Please keep the message under %s characters.', 'thirtydayhomes' ),
				number_format_i18n( self::MAX_MESSAGE )
			);
		}

		if ( '' === $values['consent'] ) {
			$errors['consent'] = __( 'Please tick the box to send your details to the owner.', 'thirtydayhomes' );
		}

		// "Is this a person?" (Turnstile; off until its keys are in wp-config).
		// Asked last, so a mistyped field is fixed in the same round trip.
		if ( ! $errors && ! Bot_Check::passes( 'inquiry' ) ) {
			$errors['_bot'] = Bot_Check::message();
		}

		if ( $errors ) {
			$this->remember( $values, $errors );
			$this->bounce( $back, self::INVALID );
		}

		/*
		 * The same message again. Counted as already sent rather than
		 * refused: to the renter it IS the message they sent, it did
		 * arrive, and the landlord should not read it twice.
		 */
		if ( self::already_sent( $listing_id, $email ) ) {
			$this->bounce( $back, self::SENT );
		}

		$this->record_attempt();

		$id = self::store( $listing_id, $email, $values );

		if ( ! $id ) {
			// Never a white screen and never a lost message: the typed
			// words come back with the form.
			$this->remember( $values, [ 'message' => __( 'We could not save your message just now. Please press Send again.', 'thirtydayhomes' ) ] );
			$this->refused( self::FAILED, $listing_id );
			$this->bounce( $back, self::FAILED );
		}

		/**
		 * An inquiry was stored. Email (D3) and SMS (D4) listen here.
		 *
		 * Deliberately after the store and before the redirect, and
		 * deliberately unable to affect either: a listener that throws or
		 * fails must not turn a saved inquiry into a failed one.
		 *
		 * @param int $id         The inquiry post id.
		 * @param int $listing_id The home it is about.
		 */
		do_action( 'tdh_inquiry_received', $id, $listing_id );

		self::remember_send( $listing_id, $email );

		// POST-redirect-GET: the success screen is a fresh GET, so Back
		// cannot send the message a second time.
		$this->bounce( $back, self::SENT );
	}

	/**
	 * Announce that an inquiry was turned away, and why.
	 *
	 * An event rather than a `Log::` call, because logging in this codebase
	 * is subscription: `TDH\Log` listens and every other feature stays
	 * unaware of it. It also means a refusal is visible to anything else
	 * that wants to know — a future rate-limit dashboard, say — without
	 * this class learning about it.
	 *
	 * The master prompt is explicit that **every permission refusal** is
	 * logged, and until now none of these were: a renter could be turned
	 * away because a home was withdrawn mid-typing and the only trace was
	 * a sentence on their screen that nobody else ever saw.
	 *
	 * @param string $reason One of the outcome constants.
	 */
	private function refused( string $reason, int $listing_id ): void {

		/**
		 * An inquiry was not accepted.
		 *
		 * @param string $reason     Why: gone, too_many, failed.
		 * @param int    $listing_id The home it was about.
		 */
		do_action( 'tdh_inquiry_refused', $reason, $listing_id );
	}

	/**
	 * What the visitor typed, sanitised but not yet judged.
	 *
	 * @return array<string,string>
	 */
	private function typed(): array {

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the caller has checked it.
		$get = static fn( string $key ): string => isset( $_POST[ $key ] )
			? trim( sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) )
			: '';

		return [
			'name'    => $get( 'tdh_name' ),
			'email'   => $get( 'tdh_email' ),
			'phone'   => $get( 'tdh_phone' ),
			'move_in' => $get( 'tdh_move_in' ),
			'stay'    => $get( 'tdh_stay' ),
			'message' => isset( $_POST['tdh_message'] ) ? trim( sanitize_textarea_field( wp_unslash( (string) $_POST['tdh_message'] ) ) ) : '',
			'consent' => empty( $_POST['tdh_consent'] ) ? '' : '1',
		];
		// phpcs:enable
	}

	/**
	 * Write the inquiry down.
	 *
	 * @param array<string,string> $values The typed values, already valid.
	 *
	 * @return int The inquiry id, or 0 if it could not be written.
	 */
	public static function store( int $listing_id, string $email, array $values ): int {

		$home = get_the_title( $listing_id );

		$id = wp_insert_post(
			[
				'post_type'   => Post_Types::INQUIRY,
				'post_status' => 'publish',
				'post_title'  => sprintf(
					/* translators: 1: the renter's name, 2: the home's name */
					__( '%1$s — %2$s', 'thirtydayhomes' ),
					$values['name'],
					'' !== $home ? $home : __( 'a home', 'thirtydayhomes' )
				),
			],
			true
		);

		if ( is_wp_error( $id ) || ! $id ) {
			if ( class_exists( '\TDH\Log' ) ) {
				Log::error(
					'inquiries',
					'inquiry_not_stored',
					'A renter pressed Send and the inquiry could not be written. The renter was asked to try again.',
					[ 'listing' => $listing_id ]
				);
			}

			return 0;
		}

		$id = (int) $id;

		update_post_meta( $id, '_tdh_listing_id', $listing_id );
		update_post_meta( $id, '_tdh_renter_name', $values['name'] );
		update_post_meta( $id, '_tdh_renter_email', $email );
		update_post_meta( $id, '_tdh_renter_phone', $values['phone'] );
		update_post_meta( $id, '_tdh_move_in', $values['move_in'] );
		update_post_meta( $id, '_tdh_stay_length', $values['stay'] );
		update_post_meta( $id, '_tdh_message', $values['message'] );
		update_post_meta( $id, '_tdh_rules_version', self::RULES_VERSION );
		update_post_meta( $id, '_tdh_status', 'new' );

		/*
		 * Written as false rather than left absent. `_tdh_read` is a
		 * registered BOOLEAN, so false is stored as an empty string — which
		 * reads back identically to a key that was never written. Writing it
		 * here is what lets the dashboard tell "not read yet" from "an old
		 * record from before this field existed" with metadata_exists(),
		 * the same trap that put every switched-off hospital back into
		 * renter search in C3.
		 */
		update_post_meta( $id, '_tdh_read', false );

		return $id;
	}

	/* ---------------------------------------------------------------------
	 * What the renter sees
	 * ------------------------------------------------------------------ */

	/**
	 * The form, or the screen that replaces it once the message is sent.
	 *
	 * Success is a screen and not a green line above the same form, because
	 * a form still sitting there after a send reads as "that did not work,
	 * try again" — the silent-success mistake this project has already made
	 * once with photo uploads.
	 */
	public function render( int $listing_id ): void {

		$reason = self::reason();

		if ( self::SENT === $reason ) {
			$this->render_sent( $listing_id );

			return;
		}

		// Defensive: the template only reaches here on a public home, but a
		// paused one must never show a form that cannot be sent.
		if ( ! Visibility::is_public( $listing_id ) ) {
			$this->render_gone();

			return;
		}

		$this->render_form( $listing_id, $reason );
	}

	private function render_sent( int $listing_id ): void {

		$home = get_the_title( $listing_id );

		?>
		<div class="inquiry-sent" role="status">
			<p class="sent-head">
				<?php
				if ( function_exists( 'tdh_the_icon' ) ) {
					tdh_the_icon( 'circle-check', 20 );
				}
				esc_html_e( 'Message sent', 'thirtydayhomes' );
				?>
			</p>

			<p class="sent-body">
				<?php
				if ( '' !== $home ) {
					printf(
						/* translators: %s: the home's name */
						esc_html__( 'Your message is with the owner of %s.', 'thirtydayhomes' ),
						'<b>' . esc_html( $home ) . '</b>' // phpcs:ignore WordPress.Security.EscapeOutput
					);
				} else {
					esc_html_e( 'Your message is with the owner.', 'thirtydayhomes' );
				}
				?>
				<?php esc_html_e( 'They usually reply within a day, by email or text.', 'thirtydayhomes' ); ?>
			</p>

			<a class="primary" href="<?php echo esc_url( (string) get_post_type_archive_link( Post_Types::LISTING ) ); ?>">
				<?php esc_html_e( 'Browse more homes', 'thirtydayhomes' ); ?>
			</a>
		</div>
		<?php
	}

	private function render_gone(): void {
		?>
		<div class="inquiry-gone">
			<p><?php esc_html_e( 'This home is not taking inquiries at the moment.', 'thirtydayhomes' ); ?></p>
			<a class="primary" href="<?php echo esc_url( (string) get_post_type_archive_link( Post_Types::LISTING ) ); ?>">
				<?php esc_html_e( 'Browse other homes', 'thirtydayhomes' ); ?>
			</a>
		</div>
		<?php
	}

	private function render_form( int $listing_id, string $reason ): void {

		$stash  = self::take();
		$values = $stash['values'];
		$errors = $stash['errors'];

		$value = static fn( string $key ): string => (string) ( $values[ $key ] ?? '' );
		$error = static fn( string $key ): string => (string) ( $errors[ $key ] ?? '' );

		// A sentence above the form for the things no single field owns:
		// an expired nonce, a rate limit, a failed write.
		$notice = self::SENT === $reason || self::INVALID === $reason
			? ''
			: (string) ( self::messages()[ $reason ] ?? '' );

		/*
		 * The id a field's error carries, and the id the field points at.
		 *
		 * One function, because writing the two by hand produced a field
		 * describing itself by an id spelled with an underscore while the
		 * error's own id was spelled with a hyphen. They never met. A
		 * screen reader then reads the field as having no explanation at
		 * all, and nothing on screen looks the least bit wrong.
		 */
		$err_id = static fn( string $key ): string => 'tdh-err-' . str_replace( '_', '-', $key );

		$rule = static function ( string $key ) use ( $error, $err_id ): string {
			return '' === $error( $key ) ? '' : ' aria-invalid="true" aria-describedby="' . $err_id( $key ) . '"';
		};

		?>
		<form class="inquiry-form" method="post" action="<?php echo esc_url( (string) get_permalink( $listing_id ) ); ?>">

			<h3><?php esc_html_e( 'Inquire about this home', 'thirtydayhomes' ); ?></h3>

			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="tdh_action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="tdh_listing" value="<?php echo esc_attr( (string) $listing_id ); ?>">

			<?php if ( '' !== $notice ) : ?>
				<p class="inquiry-notice" role="alert"><?php echo esc_html( $notice ); ?></p>
			<?php elseif ( $errors ) : ?>
				<p class="inquiry-notice" role="alert"><?php echo esc_html( '' !== $error( '_bot' ) ? $error( '_bot' ) : (string) self::messages()[ self::INVALID ] ); ?></p>
			<?php endif; ?>

			<p class="form-field">
				<label for="tdh-inq-name"><?php esc_html_e( 'Your name', 'thirtydayhomes' ); ?></label>
				<input type="text" id="tdh-inq-name" name="tdh_name" autocomplete="name" required
					value="<?php echo esc_attr( $value( 'name' ) ); ?>"<?php echo $rule( 'name' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
				<?php if ( '' !== $error( 'name' ) ) : ?>
					<span class="field-error" id="<?php echo esc_attr( $err_id( 'name' ) ); ?>"><?php echo esc_html( $error( 'name' ) ); ?></span>
				<?php endif; ?>
			</p>

			<p class="form-field">
				<label for="tdh-inq-email"><?php esc_html_e( 'Email', 'thirtydayhomes' ); ?></label>
				<input type="email" id="tdh-inq-email" name="tdh_email" autocomplete="email" required
					value="<?php echo esc_attr( $value( 'email' ) ); ?>"<?php echo $rule( 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
				<?php if ( '' !== $error( 'email' ) ) : ?>
					<span class="field-error" id="<?php echo esc_attr( $err_id( 'email' ) ); ?>"><?php echo esc_html( $error( 'email' ) ); ?></span>
				<?php endif; ?>
			</p>

			<?php // Optional by decision 4. Said so, in words, rather than left to be guessed. ?>
			<p class="form-field">
				<label for="tdh-inq-phone">
					<?php esc_html_e( 'Phone', 'thirtydayhomes' ); ?>
					<small><?php esc_html_e( '(optional)', 'thirtydayhomes' ); ?></small>
				</label>
				<input type="tel" id="tdh-inq-phone" name="tdh_phone" autocomplete="tel"
					value="<?php echo esc_attr( $value( 'phone' ) ); ?>">
				<span class="field-help"><?php esc_html_e( 'Add it if you would rather be called or texted back.', 'thirtydayhomes' ); ?></span>
			</p>

			<p class="form-field">
				<label for="tdh-inq-move-in"><?php esc_html_e( 'Move-in date', 'thirtydayhomes' ); ?></label>
				<input type="date" id="tdh-inq-move-in" name="tdh_move_in" required
					min="<?php echo esc_attr( Availability::today() ); ?>"
					value="<?php echo esc_attr( $value( 'move_in' ) ); ?>"<?php echo $rule( 'move_in' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
				<?php if ( '' !== $error( 'move_in' ) ) : ?>
					<span class="field-error" id="<?php echo esc_attr( $err_id( 'move_in' ) ); ?>"><?php echo esc_html( $error( 'move_in' ) ); ?></span>
				<?php endif; ?>
			</p>

			<p class="form-field">
				<label for="tdh-inq-stay"><?php esc_html_e( 'How long do you need it?', 'thirtydayhomes' ); ?></label>
				<select id="tdh-inq-stay" name="tdh_stay" required<?php echo $rule( 'stay' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
					<option value=""><?php esc_html_e( 'Choose a length', 'thirtydayhomes' ); ?></option>
					<?php foreach ( self::stays() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value( 'stay' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( '' !== $error( 'stay' ) ) : ?>
					<span class="field-error" id="<?php echo esc_attr( $err_id( 'stay' ) ); ?>"><?php echo esc_html( $error( 'stay' ) ); ?></span>
				<?php endif; ?>
			</p>

			<p class="form-field">
				<label for="tdh-inq-message"><?php esc_html_e( 'Message', 'thirtydayhomes' ); ?></label>
				<textarea id="tdh-inq-message" name="tdh_message" rows="4" required
					maxlength="<?php echo esc_attr( (string) self::MAX_MESSAGE ); ?>"<?php echo $rule( 'message' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo esc_textarea( $value( 'message' ) ); ?></textarea>
				<?php // The limit stated before it is reached, not after. ?>
				<span class="field-help">
					<?php
					printf(
						/* translators: %s: maximum number of characters */
						esc_html__( 'Up to %s characters. Say when you arrive, who is coming, and anything you need.', 'thirtydayhomes' ),
						esc_html( number_format_i18n( self::MAX_MESSAGE ) )
					);
					?>
				</span>
				<?php if ( '' !== $error( 'message' ) ) : ?>
					<span class="field-error" id="<?php echo esc_attr( $err_id( 'message' ) ); ?>"><?php echo esc_html( $error( 'message' ) ); ?></span>
				<?php endif; ?>
			</p>

			<p class="form-field consent">
				<label for="tdh-inq-consent">
					<input type="checkbox" id="tdh-inq-consent" name="tdh_consent" value="1" required
						<?php checked( '1', $value( 'consent' ) ); ?><?php echo $rule( 'consent' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
					<span>
						<?php // Two short sentences (G3a): what is sent, and why a copy is kept. ?>
						<?php esc_html_e( 'Send my name, email and message to the owner of this home. ThirtyDayHomes keeps a copy in case you need help.', 'thirtydayhomes' ); ?>
					</span>
				</label>
				<?php if ( '' !== $error( 'consent' ) ) : ?>
					<span class="field-error" id="<?php echo esc_attr( $err_id( 'consent' ) ); ?>"><?php echo esc_html( $error( 'consent' ) ); ?></span>
				<?php endif; ?>
			</p>

			<?php
			/*
			 * The honeypot. Off-screen rather than hidden with `display:
			 * none`, which some bots now check for. tabindex and
			 * autocomplete keep it away from people using a keyboard or a
			 * password manager.
			 */
			?>
			<p class="tdh-hp" aria-hidden="true">
				<label for="tdh-inq-website"><?php esc_html_e( 'Leave this empty', 'thirtydayhomes' ); ?></label>
				<input type="text" id="tdh-inq-website" name="tdh_website" tabindex="-1" autocomplete="off" value="">
			</p>

			<?php echo Bot_Check::field(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>

			<button type="submit" class="primary" data-tdh-send
				data-sending="<?php esc_attr_e( 'Sending…', 'thirtydayhomes' ); ?>">
				<?php esc_html_e( 'Send inquiry', 'thirtydayhomes' ); ?>
			</button>

			<?php // What happens next, under the button that makes it happen (G3a). ?>
			<p class="inquiry-next"><?php esc_html_e( 'Your message goes straight to the owner, who replies to you by email or phone.', 'thirtydayhomes' ); ?></p>

			<?php
			/*
			 * The rules, with somewhere to actually read them.
			 *
			 * This replaces a line that used to sit under the price box
			 * saying "Rules and regulations must be reviewed before sending
			 * an inquiry" — an instruction with nothing to follow: no rules
			 * were shown and no agreement was recorded. Register R24 asked
			 * for a reminder at the point of inquiry AND for the accepted
			 * version to be stored; the consent box above does the storing,
			 * and this is the reminder, as a link a renter can follow.
			 *
			 * Nothing about fees is repeated here: the box already says
			 * "No guest booking fee" a few lines up.
			 */
			$terms = Accounts::url( 'terms' );
			?>
			<?php
			/*
			 * Its own class, NOT the theme's `.fine`. That one is a flex
			 * row built for an icon beside a word, so a sentence with a
			 * link in the middle of it became three flex items laid out
			 * side by side — "Sending an inquiry accepts our | house rules
			 * and terms | , as they stand today." Reusing a class for its
			 * colour and inheriting its layout is how that happens.
			 */
			?>
			<?php if ( '' !== $terms ) : ?>
				<p class="inquiry-terms">
					<?php
					printf(
						/* translators: %s: a link reading "house rules and terms" */
						esc_html__( 'Sending an inquiry accepts our %s, as they stand today.', 'thirtydayhomes' ),
						'<a href="' . esc_url( $terms ) . '">' . esc_html__( 'house rules and terms', 'thirtydayhomes' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput
					);
					?>
				</p>
			<?php endif; ?>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Reading them back (D2)
	 * ------------------------------------------------------------------ */

	/** Rows per page in the inbox. */
	public const PER_PAGE = 20;

	public const FILTER_ALL      = 'all';
	public const FILTER_UNREAD   = 'unread';
	public const FILTER_ARCHIVED = 'archived';

	/** @return array<string,string> filter key => the tab's label */
	public static function filters(): array {
		return [
			self::FILTER_ALL      => __( 'All', 'thirtydayhomes' ),
			self::FILTER_UNREAD   => __( 'Unread', 'thirtydayhomes' ),
			self::FILTER_ARCHIVED => __( 'Archived', 'thirtydayhomes' ),
		];
	}

	/**
	 * The meta clause for "not read yet".
	 *
	 * ─── WHY THIS IS NOT `[ '_tdh_read', '' ]` ─────────────────────────────
	 *
	 * `_tdh_read` is a registered BOOLEAN, so WordPress stores false as an
	 * EMPTY STRING — and a record written before the field existed has no
	 * row at all. Those are two different things to a meta query and the
	 * same thing to a renter: both mean nobody has opened it.
	 *
	 * Ask for only one and the badge disagrees with the list, which is the
	 * C3 boolean-meta trap in a new place. There is a real example of each
	 * on the site today.
	 *
	 * @return array<int|string,mixed>
	 */
	public static function unread_clause(): array {
		return [
			'relation' => 'OR',
			[
				'key'     => '_tdh_read',
				'compare' => 'NOT EXISTS',
			],
			[
				'key'     => '_tdh_read',
				'value'   => '1',
				'compare' => '!=',
			],
		];
	}

	/**
	 * The meta clause for "not archived", for the same reason: a record
	 * from before the status existed has no row, and it is not archived.
	 *
	 * @return array<int|string,mixed>
	 */
	public static function not_archived_clause(): array {
		return [
			'relation' => 'OR',
			[
				'key'     => '_tdh_status',
				'compare' => 'NOT EXISTS',
			],
			[
				'key'     => '_tdh_status',
				'value'   => 'archived',
				'compare' => '!=',
			],
		];
	}

	/**
	 * The homes this user owns, including deleted ones.
	 *
	 * Deleting a home must not delete the conversations about it: the
	 * confirmation panel promises the inquiries are kept, so trash is in.
	 *
	 * @return int[]
	 */
	private static function owned_listing_ids( int $user_id ): array {

		if ( $user_id < 1 ) {
			return [];
		}

		/*
		 * `'any'` is NOT enough here: WordPress excludes trash from it, and
		 * a deleted home's inquiries would disappear from the inbox with
		 * the home. The delete confirmation promises the opposite — that
		 * the conversations are kept — and the row is built to say
		 * "Listing removed" precisely so they can still be read.
		 *
		 * Caught by the walkthrough fixture: 23 inquiries went in, one
		 * about a home that was then deleted, and the unread count came
		 * back one short.
		 */
		$statuses = array_values(
			array_unique(
				array_merge(
					array_keys( (array) get_post_stati( [ 'internal' => false ] ) ),
					[ 'trash' ]
				)
			)
		);

		return array_map(
			'intval',
			(array) get_posts(
				[
					'post_type'             => Post_Types::LISTING,
					'author'                => $user_id,
					'post_status'           => $statuses,
					'posts_per_page'        => -1,
					'fields'                => 'ids',
					'no_found_rows'         => true,
					'tdh_bypass_visibility' => true,
				]
			)
		);
	}

	/**
	 * May this person read this inquiry?
	 *
	 * An inquiry belongs to whoever owns the home it asks about — never to
	 * anything the inquiry itself says, which a stranger could have typed.
	 * Staff read everything. A message from the Contact page has no home,
	 * so it belongs to nobody but staff.
	 *
	 * Used by the detail view and by Archive. A wrong owner is answered
	 * exactly as a missing id is, so nothing can be probed by trying ids.
	 */
	public static function can_read( int $inquiry_id, int $user_id = 0 ): bool {

		$user_id = $user_id ?: get_current_user_id();

		if ( $user_id < 1 ) {
			return false;
		}

		$post = get_post( $inquiry_id );

		if ( ! $post instanceof \WP_Post || Post_Types::INQUIRY !== $post->post_type ) {
			return false;
		}

		if ( class_exists( '\TDH\Accounts' ) && Accounts::is_staff( $user_id ) ) {
			return true;
		}

		$listing_id = (int) get_post_meta( $inquiry_id, '_tdh_listing_id', true );

		if ( $listing_id < 1 ) {
			return false;
		}

		return (int) get_post_field( 'post_author', $listing_id ) === $user_id;
	}

	/**
	 * One page of a landlord's inbox.
	 *
	 * @param string $filter One of the {@see filters()} keys.
	 *
	 * @return array{items:\WP_Post[],total:int,pages:int,page:int}
	 */
	public static function inbox( int $user_id, string $filter = self::FILTER_ALL, int $page = 1 ): array {

		$empty = [
			'items' => [],
			'total' => 0,
			'pages' => 0,
			'page'  => 1,
		];

		$listing_ids = self::owned_listing_ids( $user_id );

		if ( ! $listing_ids ) {
			return $empty;
		}

		$meta = [
			'relation' => 'AND',
			[
				'key'     => '_tdh_listing_id',
				'value'   => array_map( 'strval', $listing_ids ),
				'compare' => 'IN',
			],
		];

		if ( self::FILTER_UNREAD === $filter ) {
			$meta[] = self::unread_clause();
			$meta[] = self::not_archived_clause();
		} elseif ( self::FILTER_ARCHIVED === $filter ) {
			$meta[] = [
				'key'   => '_tdh_status',
				'value' => 'archived',
			];
		} else {
			// "All" means the inbox, and an archived message has been put
			// away — it is still reachable, on its own tab.
			$meta[] = self::not_archived_clause();
		}

		$page  = max( 1, $page );
		$query = new \WP_Query(
			[
				'post_type'      => Post_Types::INQUIRY,
				'post_status'    => 'publish',
				'posts_per_page' => self::PER_PAGE,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		return [
			'items' => $query->posts,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
			'page'  => $page,
		];
	}

	/** How many of this landlord's inquiries nobody has opened yet. */
	public static function unread_count( int $user_id ): int {

		$listing_ids = self::owned_listing_ids( $user_id );

		if ( ! $listing_ids ) {
			return 0;
		}

		$query = new \WP_Query(
			[
				'post_type'      => Post_Types::INQUIRY,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					[
						'key'     => '_tdh_listing_id',
						'value'   => array_map( 'strval', $listing_ids ),
						'compare' => 'IN',
					],
					self::unread_clause(),
					self::not_archived_clause(),
				],
			]
		);

		return (int) $query->found_posts;
	}

	/** Has nobody opened this one yet? */
	public static function is_unread( int $inquiry_id ): bool {
		return '1' !== (string) get_post_meta( $inquiry_id, '_tdh_read', true );
	}

	/** Is it put away? */
	public static function is_archived( int $inquiry_id ): bool {
		return 'archived' === (string) get_post_meta( $inquiry_id, '_tdh_status', true );
	}

	/**
	 * The home an inquiry is about, in words.
	 *
	 * A deleted home still has a title, and saying "Listing removed" is
	 * the honest answer rather than leaving the row nameless — the
	 * landlord kept the conversation, so they should be able to tell which
	 * home it was about.
	 *
	 * @return array{id:int,name:string,removed:bool}
	 */
	public static function about( int $inquiry_id ): array {

		$listing_id = (int) get_post_meta( $inquiry_id, '_tdh_listing_id', true );
		$post       = $listing_id ? get_post( $listing_id ) : null;

		if ( ! $post instanceof \WP_Post ) {
			return [
				'id'      => $listing_id,
				'name'    => __( 'Listing removed', 'thirtydayhomes' ),
				'removed' => true,
			];
		}

		/*
		 * Plain text, not HTML. get_the_title() runs the title filter, which
		 * turns an apostrophe into &#8217; — right for a web page, where the
		 * browser decodes it, and wrong everywhere this name goes next: an
		 * email subject, a text message. Screens escape it again on output.
		 */
		$title = html_entity_decode( wp_strip_all_tags( (string) get_the_title( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return [
			'id'      => $listing_id,
			'name'    => '' !== $title ? $title : __( 'Untitled home', 'thirtydayhomes' ),
			'removed' => 'trash' === $post->post_status,
		];
	}

	/**
	 * Mark one as read.
	 *
	 * Idempotent, and deliberately safe to run from a GET: opening a
	 * message is what marks it read, re-opening changes nothing, and the
	 * Back button cannot undo or repeat anything that matters.
	 */
	public static function mark_read( int $inquiry_id ): void {
		update_post_meta( $inquiry_id, '_tdh_read', true );

		if ( 'archived' !== (string) get_post_meta( $inquiry_id, '_tdh_status', true ) ) {
			update_post_meta( $inquiry_id, '_tdh_status', 'opened' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Spam, double presses and abuse
	 * ------------------------------------------------------------------ */

	private function rate_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

		return 'tdh_inquiry_rate_' . md5( $ip );
	}

	private function rate_limited(): bool {
		return (int) get_transient( $this->rate_key() ) >= self::RATE_LIMIT;
	}

	private function record_attempt(): void {
		$key = $this->rate_key();
		set_transient( $key, (int) get_transient( $key ) + 1, self::RATE_WINDOW );
	}

	/** The key under which "this person already wrote to this home" is kept. */
	private static function sent_key( int $listing_id, string $email ): string {
		return 'tdh_inq_sent_' . md5( $listing_id . '|' . strtolower( $email ) );
	}

	public static function already_sent( int $listing_id, string $email ): bool {
		return (bool) get_transient( self::sent_key( $listing_id, $email ) );
	}

	public static function remember_send( int $listing_id, string $email ): void {
		set_transient( self::sent_key( $listing_id, $email ), 1, self::DEDUPE_WINDOW );
	}

	/* ---------------------------------------------------------------------
	 * Talking back to the page
	 * ------------------------------------------------------------------ */

	/**
	 * Keep what was typed, so a refused inquiry is not written again.
	 *
	 * @param array<string,string> $values
	 * @param array<string,string> $errors field => message
	 */
	private function remember( array $values, array $errors ): void {

		$key = Accounts::visitor_key( true );

		/*
		 * On the cookie-less fallback key the errors are sentences anybody
		 * may see; the values are not. They carry a name, an address, a
		 * phone number and a message, and that key is shared by everyone
		 * behind one router on the same browser build. Drop them rather
		 * than hand them to the next visitor.
		 */
		if ( ! Accounts::key_is_private( $key ) ) {
			$values = [];
		}

		set_transient( self::stash_key( $key ), [ 'values' => $values, 'errors' => $errors ], 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * @return array{values:array<string,string>,errors:array<string,string>}
	 */
	public static function take(): array {

		// No `true`: this runs while the page renders, and minting a token
		// means a Set-Cookie header that is long gone by then.
		$key   = self::stash_key( Accounts::visitor_key() );
		$stash = get_transient( $key );

		delete_transient( $key );

		return [
			'values' => (array) ( $stash['values'] ?? [] ),
			'errors' => (array) ( $stash['errors'] ?? [] ),
		];
	}

	private static function stash_key( string $visitor ): string {
		return 'tdh_inquiry_' . $visitor;
	}

	/** The reason in the URL, or '' when there is none. */
	public static function reason(): string {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public read-only flag.
		$raw = isset( $_GET[ self::PARAM ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::PARAM ] ) ) : '';

		return array_key_exists( $raw, self::messages() ) ? $raw : '';
	}

	/**
	 * @return array<string,string> reason => what the page says
	 */
	public static function messages(): array {
		return [
			self::SENT     => '', // Its own screen, not a line of text.
			self::EXPIRED  => __( 'That form had been open a while and expired. Your message is still here — please press Send again.', 'thirtydayhomes' ),
			self::TOO_MANY => __( 'That is several inquiries in a short time. Please wait a little before sending another.', 'thirtydayhomes' ),
			self::INVALID  => __( 'Please check the highlighted fields.', 'thirtydayhomes' ),
			self::GONE     => __( 'This home was taken off the market while you were writing, so the message was not sent.', 'thirtydayhomes' ),
			self::FAILED   => __( 'We could not save your message just now. Please press Send again.', 'thirtydayhomes' ),
		];
	}

	/**
	 * A KEY in the URL, never a sentence: text in a query string is text a
	 * stranger can rewrite, and a convincing sentence on our own domain is
	 * a useful thing to be able to link somebody to.
	 */
	private function bounce( string $to, string $reason, string $param = self::PARAM ): void {

		/*
		 * The renter's form lands back ON the form, not at the top of the
		 * page (team review, 4 Oct 2026): "Message sent", or the message
		 * saying what to fix, is the first thing in view. The landlord's
		 * archive actions keep their own place.
		 */
		$anchor = self::PARAM === $param ? '#' . self::ANCHOR : '';

		wp_safe_redirect( add_query_arg( $param, $reason, $to ) . $anchor );
		exit;
	}
}
