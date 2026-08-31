<?php
/**
 * Authenticated sending.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Sends through an authenticated SMTP server instead of PHP's mail().
 *
 * ─── WHY THIS EXISTS ───────────────────────────────────────────────────────
 *
 * TDH\Mail already fixes who the mail says it is FROM. That is half the job.
 * The other half is proving we are allowed to say it, and PHP's mail() cannot:
 * it hands the message to a local binary that sends unauthenticated, from a
 * shared host, over an IP shared with every other site on it. The message is
 * technically delivered and practically filed as spam.
 *
 * That is not a cosmetic problem here. This site emails a landlord when an
 * enquiry arrives, and emails a visitor a password-reset link. A landlord who
 * pays and never hears about an enquiry cancels and blames the product; a
 * visitor who cannot reset a password never signs in again. Both failures are
 * silent — nobody reports an email they did not know was coming.
 *
 * ─── WHY SMTP AND NOT A PROVIDER'S API ─────────────────────────────────────
 *
 * An API client would mean one class per provider and a rewrite the day the
 * client changes provider. SMTP is the interface every one of them exposes —
 * a Hostinger mailbox on the domain the site already owns, Google Workspace,
 * Brevo, Postmark, Resend, Amazon SES — so the same four fields work for all
 * of them and switching is a settings change rather than a release.
 *
 * It also costs nothing recurring, which this project is explicitly held to.
 *
 * ─── WHAT THIS DOES NOT DO ─────────────────────────────────────────────────
 *
 * Authentication is not reputation. SPF, DKIM and DMARC still have to exist in
 * the domain's DNS or a well-authenticated message still lands in spam, and no
 * amount of PHP can put them there. The settings screen says so, and the test
 * button reports what the server actually answered rather than a guess.
 */
final class Smtp {

	private const OPTION_PREFIX = 'tdh_smtp_';

	/** Never echoed back to a browser in full. */
	public const SECRET_FIELDS = [ 'password' ];

	/** Where the last failure is kept, so the screen can show it. */
	public const OPTION_LAST_ERROR = 'tdh_smtp_last_error';

	/** Where the last success is kept, so "it worked once" is provable. */
	public const OPTION_LAST_SENT = 'tdh_smtp_last_sent';

	/**
	 * How long to wait on a server that is not answering.
	 *
	 * PHPMailer's own default is 300 seconds. Mail is sent DURING the request
	 * that triggered it — the visitor is watching a spinner while it happens —
	 * so a dead mail server would hold the contact form open for five minutes
	 * and then fail anyway. Fifteen seconds is long enough for a slow relay and
	 * short enough that a broken one costs a page load rather than a session.
	 */
	private const TIMEOUT = 15;

	public function register(): void {

		add_action( 'phpmailer_init', [ $this, 'configure' ] );

		// Both exist since WordPress 5.9. Recording the outcome is what makes
		// "did that message actually leave" answerable a week later, when
		// somebody asks why a landlord never replied.
		add_action( 'wp_mail_failed', [ $this, 'record_failure' ] );
		add_action( 'wp_mail_succeeded', [ $this, 'record_success' ] );
	}

	/* ---------------------------------------------------------------------
	 * The settings
	 * ------------------------------------------------------------------ */

	/**
	 * Every field, with what it is for and how to check it.
	 *
	 * @return array<string,array{label:string,hint:string,secret:bool}>
	 */
	public static function fields(): array {
		return [
			'host'     => [
				'label'  => __( 'SMTP server', 'thirtydayhomes' ),
				'hint'   => __( 'Your mail provider gives you this. On Hostinger it is smtp.hostinger.com.', 'thirtydayhomes' ),
				'secret' => false,
			],
			'port'     => [
				'label'  => __( 'Port', 'thirtydayhomes' ),
				'hint'   => __( '587 for STARTTLS, 465 for SSL. Leave blank and the encryption choice picks it.', 'thirtydayhomes' ),
				'secret' => false,
			],
			'username' => [
				'label'  => __( 'Username', 'thirtydayhomes' ),
				'hint'   => __( 'Usually the full mailbox address, not just the part before the @.', 'thirtydayhomes' ),
				'secret' => false,
			],
			'password' => [
				'label'  => __( 'Password', 'thirtydayhomes' ),
				'hint'   => __( 'The mailbox password, or an app password where the provider issues one.', 'thirtydayhomes' ),
				'secret' => true,
			],
		];
	}

	/**
	 * @return array<string,string> value => label
	 */
	public static function encryptions(): array {
		return [
			'tls'  => __( 'STARTTLS — usually port 587', 'thirtydayhomes' ),
			'ssl'  => __( 'SSL/TLS — usually port 465', 'thirtydayhomes' ),
			'none' => __( 'None — only for a relay on this same machine', 'thirtydayhomes' ),
		];
	}

	public static function option_name( string $field ): string {
		return self::OPTION_PREFIX . $field;
	}

	/**
	 * The wp-config.php constant that overrides it.
	 *
	 * The same arrangement the Stripe credentials use: a constant beats the
	 * database, so a password can live in a file that is not in the repository
	 * and not in a database backup somebody emails around.
	 */
	public static function constant_name( string $field ): string {
		return 'TDH_SMTP_' . strtoupper( $field );
	}

	public static function is_locked( string $field ): bool {
		return defined( self::constant_name( $field ) );
	}

	/** Read one setting, constant first. */
	public static function setting( string $field ): string {

		$const = self::constant_name( $field );

		if ( defined( $const ) ) {
			return trim( (string) constant( $const ) );
		}

		return trim( (string) get_option( self::option_name( $field ), '' ) );
	}

	public static function save( string $field, string $value ): void {
		update_option( self::option_name( $field ), $value );
	}

	public static function encryption(): string {

		$value = self::setting( 'encryption' );

		return array_key_exists( $value, self::encryptions() ) ? $value : 'tls';
	}

	/**
	 * The port to use.
	 *
	 * A blank port is not an error — the encryption choice already implies the
	 * conventional one, and asking somebody to know that 587 goes with STARTTLS
	 * is asking them to know the thing they came here to avoid knowing.
	 */
	public static function port(): int {

		$set = (int) self::setting( 'port' );

		if ( $set > 0 && $set <= 65535 ) {
			return $set;
		}

		return match ( self::encryption() ) {
			'ssl'   => 465,
			'none'  => 25,
			default => 587,
		};
	}

	/** Is the switch on? */
	public static function is_on(): bool {
		return '1' === self::setting( 'enabled' );
	}

	/**
	 * Will a message actually go through SMTP?
	 *
	 * On AND configured. A half-filled form must not reconfigure PHPMailer —
	 * pointing it at a host with no credentials turns working-but-unauthenticated
	 * delivery into no delivery at all, which is a downgrade.
	 */
	public static function is_ready(): bool {
		return self::is_on() && ! self::problems();
	}

	/**
	 * What is wrong with the TRANSPORT. Empty means usable.
	 *
	 * Shared by the settings screen, the test button and is_ready(), so the
	 * screen can never say one thing while the sender does another.
	 *
	 * Deliberately says nothing about the From address — see sender_problems()
	 * for why those two questions are kept apart.
	 *
	 * @return array<int,string>
	 */
	public static function problems(): array {

		$problems = [];

		if ( '' === self::setting( 'host' ) ) {
			$problems[] = __( 'The SMTP server address is empty.', 'thirtydayhomes' );
		}

		/*
		 * Username and password travel together. A host with one but not the
		 * other is a typo every time — an unauthenticated relay is configured
		 * by leaving BOTH blank, not one.
		 */
		$user = '' !== self::setting( 'username' );
		$pass = '' !== self::setting( 'password' );

		if ( $user !== $pass ) {
			$problems[] = $user
				? __( 'There is a username but no password.', 'thirtydayhomes' )
				: __( 'There is a password but no username.', 'thirtydayhomes' );
		}

		return $problems;
	}

	/**
	 * What is wrong with the SENDER. Empty means fine.
	 *
	 * Separate from problems(), and it must stay separate.
	 *
	 * A bad From address is a real failure — the server will reject the
	 * message — but it is NOT a reason to stop using SMTP. Folding it into
	 * is_ready() means a site with a valid mailbox and a mistyped From quietly
	 * abandons the configured server and hands the message to PHP's mail()
	 * instead. That send fails too, differently, with a worse error, and the
	 * settings screen reports the SMTP configuration as unusable when there is
	 * nothing wrong with it.
	 *
	 * Reporting the two independently means each error names its own cause.
	 *
	 * @return array<int,string>
	 */
	public static function sender_problems(): array {

		$from = ( new Mail() )->from_address( '' );

		if ( is_email( $from ) ) {
			return [];
		}

		/*
		 * The usual way to arrive here is a site whose host has no dot in it —
		 * `localhost`, or an intranet name. is_email() rejects those, and there
		 * is no address on such a host that it would accept. Worth saying
		 * plainly, because on a development machine it is expected and nothing
		 * is broken.
		 */
		return [
			sprintf(
				/* translators: %s: the address that would be used */
				__( '%s is not a deliverable address, so a mail server would refuse it.', 'thirtydayhomes' ),
				$from
			),
		];
	}

	/**
	 * Does the From address sit on the domain this site is served from?
	 *
	 * Not fatal, and not enforced — a site legitimately sending as a Google
	 * Workspace address on another domain is a real arrangement. But it is the
	 * single most common reason authenticated mail still lands in spam, because
	 * SPF and DKIM authenticate the FROM domain and nobody has published records
	 * for the one being borrowed.
	 */
	public static function from_matches_site(): bool {

		$from = ( new Mail() )->from_address( '' );
		$at   = strrchr( $from, '@' );

		if ( false === $at ) {
			return false;
		}

		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		$site = is_string( $site ) ? strtolower( preg_replace( '/^www\./i', '', $site ) ) : '';

		return '' !== $site && strtolower( substr( $at, 1 ) ) === $site;
	}

	/* ---------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------ */

	/**
	 * Point PHPMailer at the configured server.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Passed by reference by WordPress.
	 */
	public function configure( $phpmailer ): void {

		if ( ! is_object( $phpmailer ) || ! self::is_ready() ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host    = self::setting( 'host' );
		$phpmailer->Port    = self::port();
		$phpmailer->Timeout = self::TIMEOUT;

		$username = self::setting( 'username' );

		if ( '' !== $username ) {
			$phpmailer->SMTPAuth = true;
			$phpmailer->Username = $username;
			$phpmailer->Password = self::setting( 'password' );
		} else {
			$phpmailer->SMTPAuth = false;
		}

		$encryption = self::encryption();

		if ( 'none' === $encryption ) {
			$phpmailer->SMTPSecure = '';

			/*
			 * PHPMailer otherwise upgrades to TLS on its own whenever the
			 * server advertises STARTTLS. That is the right default, and it is
			 * the wrong one here: somebody who chose "none" is talking to a
			 * relay whose certificate will not validate, and a silent upgrade
			 * turns a working configuration into a confusing failure.
			 */
			$phpmailer->SMTPAutoTLS = false;
		} else {
			$phpmailer->SMTPSecure  = $encryption;
			$phpmailer->SMTPAutoTLS = true;
		}

		/**
		 * Fires after the SMTP transport is configured.
		 *
		 * The seam for anything a provider needs that these four fields do not
		 * express — a custom EHLO name, an alternate auth type — without
		 * editing this class.
		 *
		 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer
		 */
		do_action( 'tdh_smtp_configured', $phpmailer );
	}

	/**
	 * @param \WP_Error $error Why it failed.
	 */
	public function record_failure( $error ): void {

		$message = is_wp_error( $error ) ? $error->get_error_message() : __( 'Unknown error', 'thirtydayhomes' );

		update_option(
			self::OPTION_LAST_ERROR,
			[
				'at'      => time(),
				'message' => (string) $message,
			],
			false
		);
	}

	public function record_success(): void {

		update_option( self::OPTION_LAST_SENT, time(), false );

		// A success answers the last failure. Leaving a fixed problem on screen
		// is how somebody spends an afternoon re-solving it.
		delete_option( self::OPTION_LAST_ERROR );
	}

	/**
	 * @return array{at:int,message:string}|null
	 */
	public static function last_error(): ?array {

		$stored = get_option( self::OPTION_LAST_ERROR );

		if ( ! is_array( $stored ) || empty( $stored['message'] ) ) {
			return null;
		}

		return [
			'at'      => (int) ( $stored['at'] ?? 0 ),
			'message' => (string) $stored['message'],
		];
	}

	public static function last_sent(): int {
		return (int) get_option( self::OPTION_LAST_SENT, 0 );
	}

	/* ---------------------------------------------------------------------
	 * The test
	 * ------------------------------------------------------------------ */

	/**
	 * Send one message and report what actually happened.
	 *
	 * The conversation with the server is captured and returned. That detail is
	 * the entire value of this button: "could not send" sends somebody guessing
	 * for an hour, where `535 5.7.8 Authentication failed` tells them the
	 * password is wrong and nothing else is.
	 *
	 * @return array{sent:bool,message:string,transcript:string}
	 */
	public static function send_test( string $to ): array {

		if ( ! is_email( $to ) ) {
			return [
				'sent'       => false,
				'message'    => __( 'That is not a valid email address.', 'thirtydayhomes' ),
				'transcript' => '',
			];
		}

		if ( Mail::capturing() ) {
			return [
				'sent'       => false,
				'message'    => sprintf(
					/* translators: %s: directory path */
					__( 'This environment captures mail instead of sending it, so nothing left the machine. Look in %s.', 'thirtydayhomes' ),
					Mail::capture_dir()
				),
				'transcript' => '',
			];
		}

		$problems = array_merge(
			self::is_on() ? self::problems() : [],
			self::sender_problems()
		);

		if ( $problems ) {
			return [
				'sent'       => false,
				'message'    => implode( ' ', $problems ),
				'transcript' => '',
			];
		}

		$transcript = '';
		$failure    = '';

		$listen = static function ( $phpmailer ) use ( &$transcript ) {
			if ( ! is_object( $phpmailer ) ) {
				return;
			}

			// 2 is client and server; enough to see the auth exchange without
			// dumping the whole TLS negotiation.
			$phpmailer->SMTPDebug   = 2;
			$phpmailer->Debugoutput = static function ( $line ) use ( &$transcript ) {
				$transcript .= rtrim( (string) $line ) . "\n";
			};
		};

		$note = static function ( $error ) use ( &$failure ) {
			$failure = is_wp_error( $error ) ? (string) $error->get_error_message() : '';
		};

		// Last, so the debug settings above are not overwritten by anything
		// else hooked to phpmailer_init.
		add_action( 'phpmailer_init', $listen, PHP_INT_MAX );
		add_action( 'wp_mail_failed', $note );

		$sent = wp_mail(
			$to,
			sprintf(
				/* translators: %s: site name */
				__( '[%s] Email delivery test', 'thirtydayhomes' ),
				get_bloginfo( 'name' )
			),
			implode(
				"\n",
				[
					__( 'If you are reading this, outgoing email works.', 'thirtydayhomes' ),
					'',
					sprintf(
						/* translators: 1: from address, 2: server */
						__( 'Sent as %1$s through %2$s.', 'thirtydayhomes' ),
						( new Mail() )->from_address( '' ),
						self::is_ready() ? self::setting( 'host' ) . ':' . self::port() : __( 'the server default (no SMTP configured)', 'thirtydayhomes' )
					),
					'',
					__( 'Arriving is not the same as arriving in the inbox. Check whether this landed in spam, and if it did, the domain still needs SPF, DKIM and DMARC records.', 'thirtydayhomes' ),
				]
			)
		);

		remove_action( 'phpmailer_init', $listen, PHP_INT_MAX );
		remove_action( 'wp_mail_failed', $note );

		if ( $sent ) {
			return [
				'sent'       => true,
				'message'    => sprintf(
					/* translators: %s: email address */
					__( 'Sent to %s. Check the inbox, and the spam folder.', 'thirtydayhomes' ),
					$to
				),
				'transcript' => $transcript,
			];
		}

		return [
			'sent'       => false,
			'message'    => '' !== $failure ? $failure : __( 'The message could not be sent, and the mail library gave no reason.', 'thirtydayhomes' ),
			'transcript' => $transcript,
		];
	}
}
