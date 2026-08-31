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

	/*
	 * ─── THESE ARE THE KEYS THE ADMIN SCREEN ALREADY RENDERS ───────────────
	 *
	 * They were `_tdh_from_name`, `_tdh_from_email`, `_tdh_from_phone` — a
	 * parallel set invented for this feature — and the message body went to
	 * post_content. Every one of those was invisible: tdh_inquiry is
	 * registered with `supports => ['title']`, so post_content is never
	 * displayed, and the inquiry meta box renders exactly the keys listed in
	 * Fields::inquiry_schema(), which those were not in.
	 *
	 * So a stored message opened in wp-admin as a row of blank fields. The
	 * entire reason this feature writes the message down before emailing it
	 * is that the email may fail — and the place somebody goes to recover it
	 * showed them nothing. Reusing the enquiry schema's own keys means the
	 * screen that already exists displays them, with no second meta box.
	 */
	public const META_NAME     = '_tdh_renter_name';
	public const META_EMAIL    = '_tdh_renter_email';
	public const META_PHONE    = '_tdh_renter_phone';
	public const META_BODY     = '_tdh_message';
	public const META_TOPIC    = '_tdh_topic';
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
	 * The topics someone can pick, each under two names.
	 *
	 * A fixed list rather than free text: it routes the message for whoever
	 * reads it, and it is one field a spam bot cannot fill with a link.
	 *
	 * ─── WHY TWO LABELS ────────────────────────────────────────────────────
	 *
	 * `chip` is what the visitor taps. A chip is a control, not a sentence:
	 * four sentence-length pills wrapped onto a second row and left one
	 * orphan hanging under the others.
	 *
	 * `full` is what the notification's subject line and the admin record
	 * say. There the extra words are the whole point — "Renting" in a subject
	 * line beside forty other emails means nothing, where "Finding a home to
	 * rent" is instantly placeable.
	 *
	 * One list so the two can never drift apart.
	 *
	 * @return array<string,array{chip:string,full:string}>
	 */
	private static function topic_list(): array {
		return [
			'renting'    => [
				'chip' => __( 'Renting', 'thirtydayhomes' ),
				'full' => __( 'Finding a home to rent', 'thirtydayhomes' ),
			],
			'listing'    => [
				'chip' => __( 'Listing my home', 'thirtydayhomes' ),
				'full' => __( 'Listing my property', 'thirtydayhomes' ),
			],
			'membership' => [
				'chip' => __( 'Membership', 'thirtydayhomes' ),
				'full' => __( 'Membership or billing', 'thirtydayhomes' ),
			],
			'other'      => [
				'chip' => __( 'Something else', 'thirtydayhomes' ),
				'full' => __( 'Something else', 'thirtydayhomes' ),
			],
		];
	}

	/**
	 * The descriptive labels — email subjects, the stored record, anywhere a
	 * topic is read outside the form it was chosen on.
	 *
	 * @return array<string,string>
	 */
	public static function topics(): array {
		return array_map( static fn( array $t ): string => $t['full'], self::topic_list() );
	}

	/**
	 * The short labels the chips carry.
	 *
	 * @return array<string,string>
	 */
	public static function topic_chips(): array {
		return array_map( static fn( array $t ): string => $t['chip'], self::topic_list() );
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

		// The body is written twice, and deliberately. post_content is where a
		// message belongs and is what a WordPress export carries out; the meta
		// copy is what the inquiry screen actually displays. Both are written
		// once, here, so they cannot drift apart.
		update_post_meta( $id, self::META_BODY, $message );

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
		 *
		 * The display name has its delimiters stripped first. wp_mail() splits
		 * a Reply-To value on COMMAS and treats each piece as another address,
		 * so a visitor named "Dana Whitfield, Jr." would have produced two
		 * Reply-To entries — the second one garbage. sanitize_text_field()
		 * removes newlines, so this is not header injection, but it is a
		 * malformed header built from user input, and the angle brackets and
		 * quotes are stripped for the same reason.
		 */
		$reply_name = trim( str_replace( [ ',', ';', '<', '>', '"' ], ' ', $name ) );
		$reply_name = (string) preg_replace( '/\s+/', ' ', $reply_name );

		$sent = wp_mail(
			$to,
			sprintf(
				/* translators: 1: site name, 2: topic */
				__( '[%1$s] %2$s', 'thirtydayhomes' ),
				get_bloginfo( 'name' ),
				self::topics()[ $topic ] ?? __( 'Message', 'thirtydayhomes' )
			),
			$body,
			[
				'' !== $reply_name
					? sprintf( 'Reply-To: %s <%s>', $reply_name, $email )
					: 'Reply-To: ' . $email,
			]
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

		$key = Accounts::visitor_key( true );

		/*
		 * On the cookie-less fallback key, the errors are generic sentences
		 * anybody may see. The values are not: they carry the name, email
		 * address, phone number and full message this visitor just typed, and
		 * that key is shared by everyone behind one office NAT on the same
		 * browser build. Drop them rather than hand them to the next person.
		 */
		if ( ! Accounts::key_is_private( $key ) ) {
			$values = [];
		}

		set_transient( self::stash_key( $key ), [ 'values' => $values, 'errors' => $errors ], 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * @return array{values:array<string,string>,errors:string[]}
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

	/**
	 * Identifies this visitor for the stash.
	 *
	 * ─── THIS WAS WRITTEN WRONG ONCE, HERE, ON PURPOSE-SOUNDING GROUNDS ────
	 *
	 * The first version of this class hashed TEST_COOKIE . REMOTE_ADDR .
	 * HTTP_USER_AGENT and called itself cookie-scoped, with a comment saying
	 * the IP-only mistake had already been made once in this codebase. It had
	 * — and this was the same mistake wearing the comment as a disguise.
	 * TEST_COOKIE is `wordpress_test_cookie`, WordPress sets it only on
	 * wp-login.php, and its value is the fixed literal `WP Cookie check`. It
	 * carries no entropy whether present or not, so the key collapsed to
	 * md5( IP . user agent ) exactly as before.
	 *
	 * TDH\Accounts had already solved this properly, with a minted token in a
	 * cookie of our own. The fix now lives in one place and both forms call
	 * it, so it cannot be right in one and wrong in the other.
	 */
	private static function stash_key( string $visitor ): string {
		return 'tdh_contact_' . $visitor;
	}

	/**
	 * Where to send the visitor back to.
	 *
	 * The page they actually posted from, when that is known — the form
	 * self-posts, so during handle() the queried object IS the page holding
	 * the shortcode. Only when that fails does it fall back to looking the
	 * page up, and it looks it up by the importer's seed key rather than by
	 * slug, because a client who renames the page would otherwise be
	 * redirected to the home page, which renders neither the notice nor the
	 * errors. TDH\Accounts::url() resolves account pages the same way.
	 */
	public static function page_url(): string {

		$queried = get_queried_object();

		if ( $queried instanceof \WP_Post && 'page' === $queried->post_type ) {
			return (string) get_permalink( $queried );
		}

		return Accounts::url( 'contact' );
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
