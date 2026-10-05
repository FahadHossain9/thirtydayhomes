<?php
/**
 * Cloudflare Turnstile on the public forms.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * "Is this a person?" — asked of sign-in, sign-up, password reset, contact
 * and inquiry (team review, 4 Oct 2026: about 1,200 refused sign-ins every
 * few minutes from bots on 29 Sep).
 *
 * ─── OFF UNTIL THE KEYS EXIST ──────────────────────────────────────────
 *
 * Both keys live in wp-config.php, never in the repository:
 *
 *   define( 'TDH_TURNSTILE_SITE_KEY', '0x4AAAA…' );   // shown in the page
 *   define( 'TDH_TURNSTILE_SECRET',   '0x4AAAA…' );   // server only
 *
 * Without both, nothing is printed and every form passes, exactly as before.
 * The honeypot fields stay as a second, free layer either way.
 *
 * ─── WHEN CLOUDFLARE CANNOT BE REACHED ────────────────────────────────
 *
 * A person must never be locked out because Cloudflare had a bad minute. A
 * network failure lets the form through and is logged; only a definite
 * "not a person" (or a missing or reused token) refuses it.
 */
final class Bot_Check {

	private const SITE_CONST   = 'TDH_TURNSTILE_SITE_KEY';
	private const SECRET_CONST = 'TDH_TURNSTILE_SECRET';
	private const VERIFY_URL   = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	private const SCRIPT_URL   = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

	/** The field Turnstile writes its answer into. */
	public const FIELD = 'cf-turnstile-response';

	public static function site_key(): string {
		return defined( self::SITE_CONST ) ? trim( (string) constant( self::SITE_CONST ) ) : '';
	}

	private static function secret(): string {
		return defined( self::SECRET_CONST ) ? trim( (string) constant( self::SECRET_CONST ) ) : '';
	}

	/** Both keys present: the check is on. */
	public static function enabled(): bool {
		return '' !== self::site_key() && '' !== self::secret();
	}

	/**
	 * The widget for one form, or '' while the check is off.
	 *
	 * "managed" mode: most people see a small tick that passes by itself;
	 * only suspicious visits are asked to click. The script is loaded once
	 * per page, from Cloudflare, only where a form needs it.
	 */
	public static function field(): string {

		if ( ! self::enabled() ) {
			return '';
		}

		static $script = false;

		$out = '';

		if ( ! $script ) {
			$script = true;
			$out   .= '<script src="' . esc_url( self::SCRIPT_URL ) . '" async defer></script>';
		}

		$out .= sprintf(
			'<div class="tdh-bot-check cf-turnstile" data-sitekey="%1$s" data-theme="light" data-size="flexible" data-language="en"></div>',
			esc_attr( self::site_key() )
		);

		$out .= '<noscript><p class="tdh-bot-check-note">' . esc_html__( 'Please turn on JavaScript to send this form — it checks that you are a person.', 'thirtydayhomes' ) . '</p></noscript>';

		return $out;
	}

	/** What a refused visitor is told: what happened and what to do. */
	public static function message(): string {
		return __( 'We could not confirm you are a person. Please wait for the tick under the form to appear, then press the button again.', 'thirtydayhomes' );
	}

	/**
	 * Did this request come from a person?
	 *
	 * @param string $form Which form asked, for the log.
	 */
	public static function passes( string $form ): bool {

		if ( ! self::enabled() ) {
			return true;
		}

		/*
		 * A command-line run (WP-CLI, and the verification suites that run
		 * through it) has no browser to show the widget to, so there is
		 * nobody to ask. The constant is set only by the WP-CLI program
		 * itself; a web request cannot claim it.
		 */
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- each form checks its own nonce first.
		$token = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD ] ) ) : '';

		return self::judge( $token, $form );
	}

	/**
	 * The verdict on one answer from the widget.
	 *
	 * Separate from passes() so the verification suite can put answers to
	 * it directly; a form always goes through passes().
	 *
	 * @param string $token What the widget wrote into the form ('' if nothing).
	 * @param string $form  Which form asked, for the log.
	 */
	public static function judge( string $token, string $form ): bool {

		if ( ! self::enabled() ) {
			return true;
		}

		if ( '' === $token ) {
			self::log( 'bot_check_refused', $form, 'no answer from the check' );
			return false;
		}

		$response = wp_remote_post(
			self::VERIFY_URL,
			[
				'timeout' => 8,
				'body'    => [
					'secret'   => self::secret(),
					'response' => $token,
					'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '',
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cloudflare unreachable: let the person through, say so in the log.
			self::log( 'bot_check_unreachable', $form, is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $response ) );
			return true;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( is_array( $body ) && ! empty( $body['success'] ) ) {
			return true;
		}

		$codes = is_array( $body ) && ! empty( $body['error-codes'] ) ? implode( ', ', (array) $body['error-codes'] ) : 'refused';
		self::log( 'bot_check_refused', $form, $codes );

		return false;
	}

	private static function log( string $event, string $form, string $detail ): void {
		if ( class_exists( __NAMESPACE__ . '\\Log' ) ) {
			Log::warning(
				'signin',
				$event,
				/* translators: 1: form name, 2: detail */
				sprintf( __( 'Bot check on the %1$s form: %2$s', 'thirtydayhomes' ), $form, $detail )
			);
		}
	}
}
