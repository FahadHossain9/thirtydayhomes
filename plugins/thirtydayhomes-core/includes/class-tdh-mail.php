<?php
/**
 * Outgoing email.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Who our email says it is from, and — in development — where it goes.
 *
 * ─── WHY THE SENDER MATTERS MORE THAN IT LOOKS ─────────────────────────────
 *
 * Left alone, WordPress sends everything as `WordPress
 * <wordpress@yourdomain.com>`. That is two problems in one line. It is
 * unbranded, which is a poor first impression on the only email a landlord is
 * guaranteed to read. And it is a well-known spam signal: filters weight an
 * unconfigured default heavily, and `wordpress@` is almost never a mailbox
 * that exists, so a reply or a bounce goes nowhere.
 *
 * This matters commercially, not just cosmetically. The delivery plan is
 * blunt about it: a landlord who pays and never receives an inquiry cancels,
 * and blames the product for what is really a DNS and sender problem.
 *
 * ─── SENDING ACTUALLY WORKS IN DEVELOPMENT NOW ─────────────────────────────
 *
 * It did not before, and the reason is worth writing down because it wastes
 * an afternoon otherwise. XAMPP ships `sendmail_path` pointed at
 * mailtodisk.exe, which looks like it captures mail. It does not: on Windows,
 * PHP's mail() ignores sendmail_path entirely — it is a Unix setting — and
 * uses SMTP/smtp_port, which defaults to localhost:25 with nothing listening.
 * So wp_mail() returned false, retrieve_password() reported "the email could
 * not be sent", and password reset could not be tested at all.
 *
 * Outside production this class short-circuits wp_mail and writes the message
 * to a file instead, so the whole flow can be followed end to end without a
 * mail server. It writes to the system temp directory, NOT anywhere under the
 * web root: these files contain one-time password-reset links, and a captured
 * inbox served over HTTP would be an account takeover waiting to happen.
 */
final class Mail {

	public const OPTION_FROM      = 'tdh_mail_from';
	public const OPTION_FROM_NAME = 'tdh_mail_from_name';

	/** Turn capture on or off explicitly, whatever the environment says. */
	public const CAPTURE_CONSTANT = 'TDH_MAIL_CAPTURE';

	public function register(): void {

		add_filter( 'wp_mail_from', [ $this, 'from_address' ] );
		add_filter( 'wp_mail_from_name', [ $this, 'from_name' ] );

		if ( self::capturing() ) {
			// pre_wp_mail short-circuits the send. Returning true tells
			// WordPress it succeeded, so retrieve_password() and everything
			// else behaves exactly as it would with a working mail server.
			add_filter( 'pre_wp_mail', [ $this, 'capture' ], 10, 2 );
		}
	}

	/* ---------------------------------------------------------------------
	 * Who it is from
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $from The address WordPress was going to use.
	 */
	public function from_address( $from ): string {

		$configured = trim( (string) get_option( self::OPTION_FROM, '' ) );

		if ( '' !== $configured && is_email( $configured ) ) {
			return $configured;
		}

		/*
		 * Default to noreply@ on the SITE's own domain, never the server's.
		 * SPF and DKIM authenticate a domain, so a From address on any other
		 * domain fails both however well the DNS is set up.
		 */
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? preg_replace( '/^www\./i', '', $host ) : '';

		return $host ? 'noreply@' . $host : (string) $from;
	}

	/**
	 * @param string $name The name WordPress was going to use.
	 */
	public function from_name( $name ): string {

		$configured = trim( (string) get_option( self::OPTION_FROM_NAME, '' ) );

		if ( '' !== $configured ) {
			return $configured;
		}

		// The site's own name, which is also what the logo shows — so the
		// email and the site agree about who sent it.
		$blog = trim( (string) get_bloginfo( 'name' ) );

		return '' !== $blog ? $blog : (string) $name;
	}

	/* ---------------------------------------------------------------------
	 * Capturing, outside production
	 * ------------------------------------------------------------------ */

	/**
	 * Should mail be written to a file rather than sent?
	 *
	 * Production is excluded even if the constant is set. Silently swallowing
	 * a real customer's password reset is far worse than any convenience this
	 * buys, so the environment gets the final say.
	 */
	public static function capturing(): bool {

		return self::should_capture(
			wp_get_environment_type(),
			defined( self::CAPTURE_CONSTANT ) ? (bool) constant( self::CAPTURE_CONSTANT ) : null
		);
	}

	/**
	 * The decision itself, separated from where the answers come from.
	 *
	 * WP_ENVIRONMENT_TYPE is a constant, so it cannot be changed at runtime —
	 * which means the production guard, the single rule here that must never
	 * be wrong, could not be tested on a machine that is not production. This
	 * takes the environment as an argument so it can be.
	 *
	 * @param string    $environment What wp_get_environment_type() returned.
	 * @param bool|null $override    TDH_MAIL_CAPTURE, or null when unset.
	 */
	public static function should_capture( string $environment, ?bool $override = null ): bool {

		// Production first, and it cannot be overridden. Silently swallowing
		// a real customer's password reset is far worse than any convenience
		// the constant buys, so the environment gets the final say.
		if ( 'production' === $environment ) {
			return false;
		}

		if ( null !== $override ) {
			return $override;
		}

		return in_array( $environment, [ 'local', 'development' ], true );
	}

	/**
	 * Where captured mail is written.
	 *
	 * The system temp directory, deliberately. Anywhere under wp-content is
	 * reachable over HTTP on a default install, and these files contain live
	 * password-reset tokens — an "inbox" a stranger can read is an account
	 * takeover, not a developer convenience. Protecting it with .htaccess
	 * would work on Apache and quietly not on nginx.
	 */
	public static function capture_dir(): string {
		return rtrim( sys_get_temp_dir(), '/\\' ) . DIRECTORY_SEPARATOR . 'thirtydayhomes-mail';
	}

	/**
	 * Write the message instead of sending it.
	 *
	 * @param null|bool           $short  Whatever an earlier filter decided.
	 * @param array<string,mixed> $atts   to, subject, message, headers, attachments.
	 * @return null|bool True to tell WordPress the mail was handled.
	 */
	public function capture( $short, $atts ) {

		// Someone else already handled it; do not send twice.
		if ( null !== $short ) {
			return $short;
		}

		$dir = self::capture_dir();

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			// Could not write, so let WordPress try for real rather than
			// swallowing the message.
			return null;
		}

		$to      = (array) ( $atts['to'] ?? [] );
		$subject = (string) ( $atts['subject'] ?? '' );
		$body    = (string) ( $atts['message'] ?? '' );
		$headers = (array) ( $atts['headers'] ?? [] );

		$lines = [
			'Date:    ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			'From:    ' . $this->from_name( '' ) . ' <' . $this->from_address( '' ) . '>',
			'To:      ' . implode( ', ', array_map( 'strval', $to ) ),
			'Subject: ' . $subject,
		];

		foreach ( $headers as $header ) {
			$lines[] = 'Header:  ' . ( is_string( $header ) ? $header : wp_json_encode( $header ) );
		}

		$lines[] = str_repeat( '-', 70 );
		$lines[] = $body;

		$name = gmdate( 'Ymd-His' ) . '-' . substr( md5( $subject . microtime() ), 0, 6 ) . '.txt';

		file_put_contents( $dir . DIRECTORY_SEPARATOR . $name, implode( "\n", $lines ) . "\n" );

		/**
		 * Fires when an email is captured rather than sent.
		 *
		 * @param string              $path  File it was written to.
		 * @param array<string,mixed> $atts  The wp_mail arguments.
		 */
		do_action( 'tdh_mail_captured', $dir . DIRECTORY_SEPARATOR . $name, $atts );

		return true;
	}

	/**
	 * The most recently captured message, for tests and for the CLI.
	 *
	 * @return array{path:string,body:string}|null
	 */
	public static function latest_capture(): ?array {

		$dir = self::capture_dir();

		if ( ! is_dir( $dir ) ) {
			return null;
		}

		$files = glob( $dir . DIRECTORY_SEPARATOR . '*.txt' );

		if ( ! $files ) {
			return null;
		}

		usort( $files, static fn( $a, $b ) => filemtime( $b ) <=> filemtime( $a ) );

		return [
			'path' => $files[0],
			'body' => (string) file_get_contents( $files[0] ),
		];
	}
}
