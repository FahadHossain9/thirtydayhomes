<?php
/**
 * The contact form.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Takes a message from a visitor, stores it, and notifies the site owner.
 *
 * ─── WHY IT IS STORED AND NOT ONLY EMAILED ─────────────────────────────────
 *
 * A form that only emails loses every message the moment delivery fails —
 * and delivery is precisely what is not yet proven on this domain: no
 * provider, no SPF, no DKIM. A message that arrives nowhere and is saved
 * nowhere is simply gone, and nobody finds out until a customer asks why
 * they were ignored.
 *
 * So the message is written down first and emailed second. If the email
 * fails, the record is still in the admin and the send is marked as failed
 * on it, which is a bad day rather than a lost customer.
 *
 * ─── WHY IT REUSES tdh_inquiry ─────────────────────────────────────────────
 *
 * A contact message and a renter's enquiry about a home are the same shape:
 * somebody outside the business wants a reply. The difference is only
 * whether a listing is attached, and the capability filter already handles
 * that — an inquiry with no listing has no owner to route to, so it falls
 * through to `read_private_posts` and only an administrator can read it,
 * which is exactly right for a message addressed to the company.
 *
 * A second post type would have meant a second admin screen, a second set of
 * capabilities and a second thing to remember when the notification pipeline
 * is built in Milestone 2.
 */
final class Contact {

	private const ACTION = 'tdh_contact';
	private const NONCE  = 'tdh_contact_send';

	/** Marks which kind of enquiry a record is. */
	public const META_KIND    = '_tdh_inquiry_kind';
	public const KIND_CONTACT = 'contact';

	public const META_NAME    = '_tdh_from_name';
	public const META_EMAIL   = '_tdh_from_email';
	public const META_PHONE   = '_tdh_from_phone';
	public const META_TOPIC   = '_tdh_topic';
	public const META_NOTIFIED = '_tdh_notified';

	/** Messages allowed from one address before it is asked to wait. */
	private const RATE_LIMIT = 5;

	/** How long that window lasts. */
	private const RATE_WINDOW = HOUR_IN_SECONDS;

	/** Longest message accepted, in characters. */
	private const MAX_MESSAGE = 5000;

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'handle' ] );
	}

	/**
	 * The topics someone can pick.
	 *
	 * A fixed list rather than free text: it routes the message for whoever
	 * reads it, and it is one field a spam bot cannot fill with a link.
	 *
	 * @return array<string,string>
	 */
	public static function topics(): array {
		return [
			'renting'    => __( 'Finding a home to rent', 'thirtydayhomes' ),
			'listing'    => __( 'Listing my property', 'thirtydayhomes' ),
			'membership' => __( 'Membership or billing', 'thirtydayhomes' ),
			'other'      => __( 'Something else', 'thirtydayhomes' ),
		];
	}

	/* ---------------------------------------------------------------------
	 * Handling
	 * ------------------------------------------------------------------ */

	public function handle(): void {

		if ( ! isset( $_POST['tdh_action'] ) || self::ACTION !== sanitize_key( wp_unslash( (string) $_POST['tdh_action'] ) ) ) {
			return;
		}

		$back = self::page_url();

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), self::NONCE ) ) {
			$this->bounce( $back, 'expired' );
		}

		/*
		 * The honeypot. A field positioned off-screen and never filled by a
		 * person; a bot fills every input it finds. Answered with the same
		 * success page a real sender sees, because telling a bot it was
		 * caught only teaches whoever wrote it to stop filling that field.
		 */
		if ( ! empty( $_POST['tdh_website'] ) ) {
			$this->bounce( $back, 'sent' );
		}

		if ( $this->rate_limited() ) {
			$this->bounce( $back, 'too_many' );
		}

		$name  = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_name'] ?? '' ) ) );
		$phone = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_phone'] ?? '' ) ) );
		$topic = sanitize_key( wp_unslash( (string) ( $_POST['tdh_topic'] ?? '' ) ) );

		/*
		 * The address is kept twice on purpose.
		 *
		 * sanitize_email() returns an EMPTY STRING for anything it does not
		 * recognise, so a single missing character — "dana@example" — becomes
		 * nothing at all. Stashing that is what makes a form clear the field
		 * it just complained about and ask the visitor to type the whole
		 * address again, which is the moment people give up.
		 *
		 * $email is what gets validated and stored. $typed is only ever put
		 * back in the input, and it is escaped there like any other value.
		 */
		$typed = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_email'] ?? '' ) ) );
		$email = sanitize_email( $typed );

		$message = sanitize_textarea_field( wp_unslash( (string) ( $_POST['tdh_message'] ?? '' ) ) );

		$errors = [];

		if ( '' === $name ) {
			$errors[] = __( 'Please tell us your name.', 'thirtydayhomes' );
		}

		if ( ! is_email( $email ) ) {
			$errors[] = __( 'Please enter an email address we can reply to.', 'thirtydayhomes' );
		}

		if ( ! array_key_exists( $topic, self::topics() ) ) {
			// Not a validation message a person would understand, because a
			// person cannot produce this — the field is a fixed list.
			$topic = 'other';
		}

		if ( '' === trim( $message ) ) {
			$errors[] = __( 'Please write your message.', 'thirtydayhomes' );
		} elseif ( mb_strlen( $message ) > self::MAX_MESSAGE ) {
			$errors[] = sprintf(
				/* translators: %d: maximum characters */
				__( 'Please keep the message under %d characters.', 'thirtydayhomes' ),
				self::MAX_MESSAGE
			);
		}

		// What goes back in the form: the address as TYPED, not as validated.
		$typed_values = [
			'name'    => $name,
			'email'   => $typed,
			'phone'   => $phone,
			'topic'   => $topic,
			'message' => $message,
		];

		if ( $errors ) {
			$this->remember( $typed_values, $errors );
			$this->bounce( $back, 'invalid' );
		}

		$this->record_attempt();

		$id = self::store( $name, $email, $phone, $topic, $message );

		if ( ! $id ) {
			$this->remember( $typed_values, [ __( 'We could not save your message. Please try again.', 'thirtydayhomes' ) ] );
			$this->bounce( $back, 'invalid' );
		}

		$this->notify( $id, $name, $email, $phone, $topic, $message );

		$this->bounce( $back, 'sent' );
	}

	/**
	 * Write the message down.
	 *
	 * @return int The inquiry id, or 0.
	 */
	public static function store( string $name, string $email, string $phone, string $topic, string $message ): int {

		$id = wp_insert_post(
			[
				'post_type'   => Post_Types::INQUIRY,
				'post_status' => 'publish',
				'post_title'  => sprintf(
					/* translators: 1: sender name, 2: topic label */
					__( '%1$s — %2$s', 'thirtydayhomes' ),
					$name,
					self::topics()[ $topic ] ?? $topic
				),
				'post_content' => $message,
			],
			true
		);

		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}

		$id = (int) $id;

		update_post_meta( $id, self::META_KIND, self::KIND_CONTACT );
		update_post_meta( $id, self::META_NAME, $name );
		update_post_meta( $id, self::META_EMAIL, $email );
		update_post_meta( $id, self::META_PHONE, $phone );
		update_post_meta( $id, self::META_TOPIC, $topic );

		/**
		 * Fires when a contact message is stored.
		 *
		 * The seam the Milestone 2 notification pipeline hooks, so SMS and
		 * anything else added later does not have to reopen this handler.
		 *
		 * @param int $id The inquiry.
		 */
		do_action( 'tdh_contact_received', $id );

		return $id;
	}

	/**
	 * Tell the site owner, and record whether that worked.
	 */
	private function notify( int $id, string $name, string $email, string $phone, string $topic, string $message ): void {

		$to = (string) get_option( 'admin_email' );

		if ( ! is_email( $to ) ) {
			update_post_meta( $id, self::META_NOTIFIED, 'no-recipient' );
			return;
		}

		$body = implode(
			"\n",
			[
				sprintf( __( 'From:    %s', 'thirtydayhomes' ), $name ),
				sprintf( __( 'Email:   %s', 'thirtydayhomes' ), $email ),
				'' !== $phone ? sprintf( __( 'Phone:   %s', 'thirtydayhomes' ), $phone ) : '',
				sprintf( __( 'About:   %s', 'thirtydayhomes' ), self::topics()[ $topic ] ?? $topic ),
				'',
				str_repeat( '-', 60 ),
				'',
				$message,
				'',
				str_repeat( '-', 60 ),
				sprintf(
					/* translators: %s: admin URL */
					__( 'In the admin: %s', 'thirtydayhomes' ),
					admin_url( 'post.php?post=' . $id . '&action=edit' )
				),
			]
		);

		/*
		 * Reply-To is the sender, so hitting reply in a mail client answers
		 * the person rather than the website. The From stays our own
		 * authenticated address — putting the visitor's address there would
		 * fail SPF and land the notification in spam, which is the one email
		 * that must not be missed.
		 */
		$sent = wp_mail(
			$to,
			sprintf(
				/* translators: 1: site name, 2: topic */
				__( '[%1$s] %2$s', 'thirtydayhomes' ),
				get_bloginfo( 'name' ),
				self::topics()[ $topic ] ?? __( 'Message', 'thirtydayhomes' )
			),
			$body,
			[ 'Reply-To: ' . $name . ' <' . $email . '>' ]
		);

		update_post_meta( $id, self::META_NOTIFIED, $sent ? 'sent' : 'failed' );
	}

	/* ---------------------------------------------------------------------
	 * Spam and abuse
	 * ------------------------------------------------------------------ */

	private function rate_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

		return 'tdh_contact_rate_' . md5( $ip );
	}

	private function rate_limited(): bool {
		return (int) get_transient( $this->rate_key() ) >= self::RATE_LIMIT;
	}

	private function record_attempt(): void {
		$key = $this->rate_key();
		set_transient( $key, (int) get_transient( $key ) + 1, self::RATE_WINDOW );
	}

	/* ---------------------------------------------------------------------
	 * Talking back to the page
	 * ------------------------------------------------------------------ */

	/**
	 * Keep what was typed, so a rejected message is not retyped from scratch.
	 *
	 * @param array<string,string> $values
	 * @param string[]             $errors
	 */
	private function remember( array $values, array $errors ): void {
		set_transient( 'tdh_contact_' . self::visitor(), [ 'values' => $values, 'errors' => $errors ], 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * @return array{values:array<string,string>,errors:string[]}
	 */
	public static function take(): array {

		$key   = 'tdh_contact_' . self::visitor();
		$stash = get_transient( $key );

		delete_transient( $key );

		return [
			'values' => (array) ( $stash['values'] ?? [] ),
			'errors' => (array) ( $stash['errors'] ?? [] ),
		];
	}

	/**
	 * Identifies this visitor for the stash.
	 *
	 * Signed-in visitors key on their user id. Everyone else keys on the
	 * session cookie plus address — NOT the address alone, which would show
	 * one visitor's half-finished message to everybody else in the same
	 * office. That mistake was made once already in this codebase.
	 */
	private static function visitor(): string {

		$id = get_current_user_id();

		if ( $id ) {
			return 'u' . $id;
		}

		$seed = ( $_COOKIE[ TEST_COOKIE ] ?? '' )
			. ( $_SERVER['REMOTE_ADDR'] ?? '' )
			. ( $_SERVER['HTTP_USER_AGENT'] ?? '' );

		return 'g' . md5( (string) $seed );
	}

	public static function page_url(): string {
		$page = get_page_by_path( 'contact' );

		return $page ? (string) get_permalink( $page ) : home_url( '/' );
	}

	/**
	 * @return array<string,string> reason => message
	 */
	public static function messages(): array {
		return [
			'sent'     => __( 'Thank you — your message is with us. We reply within one business day.', 'thirtydayhomes' ),
			'expired'  => __( 'That form expired. Please send your message again.', 'thirtydayhomes' ),
			'too_many' => __( 'That is several messages in a short time. Please wait a little before sending another.', 'thirtydayhomes' ),
			'invalid'  => '',
		];
	}

	/**
	 * A KEY in the URL, never a message: text in a query string is text an
	 * attacker can rewrite, and a convincing sentence on our own domain is a
	 * useful thing to be able to link someone to.
	 */
	private function bounce( string $to, string $reason ): void {
		wp_safe_redirect( add_query_arg( 'tdh_contact', $reason, $to ) );
		exit;
	}

	public static function form_fields(): string {
		ob_start();
		wp_nonce_field( self::NONCE );
		?>
		<input type="hidden" name="tdh_action" value="<?php echo esc_attr( self::ACTION ); ?>">
		<?php
		return (string) ob_get_clean();
	}
}
