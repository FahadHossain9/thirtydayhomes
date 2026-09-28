<?php
/**
 * Twilio, as an SMS provider.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Sms;

defined( 'ABSPATH' ) || exit;

/**
 * One HTTP call to Twilio's Messages API.
 *
 * No SDK. The whole exchange is a form POST with Basic auth and a JSON
 * answer, and pulling in a Composer dependency for that would add more
 * code than it saves — the Stripe integration made the same choice.
 *
 * Credentials come from wp-config.php constants only. There is no settings
 * screen for them and there must not be: an Auth Token in the database is
 * in every backup somebody emails around.
 */
final class Twilio implements Provider {

	public const CONST_SID   = 'TDH_TWILIO_SID';
	public const CONST_TOKEN = 'TDH_TWILIO_TOKEN';

	/**
	 * Either a phone number in E.164 or a Messaging Service SID ("MG…").
	 *
	 * The Messaging Service is what the A2P 10DLC campaign is attached to,
	 * so sending through it is what makes the messages registered traffic.
	 * A bare number still works for the test phone before the campaign is
	 * approved.
	 */
	public const CONST_FROM = 'TDH_TWILIO_FROM';

	private const ENDPOINT = 'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json';

	/** Seconds. A gateway that has not answered in this long is down. */
	private const TIMEOUT = 15;

	public static function sid(): string {
		return defined( self::CONST_SID ) ? trim( (string) constant( self::CONST_SID ) ) : '';
	}

	public static function token(): string {
		return defined( self::CONST_TOKEN ) ? trim( (string) constant( self::CONST_TOKEN ) ) : '';
	}

	public static function from(): string {
		return defined( self::CONST_FROM ) ? trim( (string) constant( self::CONST_FROM ) ) : '';
	}

	/** All three present. Says nothing about whether they are correct. */
	public static function is_configured(): bool {
		return '' !== self::sid() && '' !== self::token() && '' !== self::from();
	}

	public function name(): string {
		return 'twilio';
	}

	/**
	 * @inheritDoc
	 */
	public function send( string $to, string $body ): array {

		if ( ! self::is_configured() ) {
			return [
				'ok'    => false,
				'id'    => '',
				'error' => 'Twilio is not configured (TDH_TWILIO_SID, TDH_TWILIO_TOKEN and TDH_TWILIO_FROM).',
			];
		}

		$from = self::from();

		$fields = [
			'To'   => $to,
			'Body' => $body,
		];

		// "MG…" is a Messaging Service; anything else is a number.
		if ( str_starts_with( $from, 'MG' ) ) {
			$fields['MessagingServiceSid'] = $from;
		} else {
			$fields['From'] = $from;
		}

		$callback = self::status_callback_url();

		if ( '' !== $callback ) {
			$fields['StatusCallback'] = $callback;
		}

		$response = wp_remote_post(
			sprintf( self::ENDPOINT, rawurlencode( self::sid() ) ),
			[
				'timeout' => self::TIMEOUT,
				'headers' => [
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth, not obfuscation.
					'Authorization' => 'Basic ' . base64_encode( self::sid() . ':' . self::token() ),
					'Accept'        => 'application/json',
				],
				'body'    => $fields,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'ok'    => false,
				'id'    => '',
				'error' => 'Could not reach Twilio: ' . $response->get_error_message(),
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : [];

		/*
		 * 201 with a sid is the only success. Twilio answers 4xx with a JSON
		 * body carrying `message` and `code`; that message is what the mail
		 * server would have said, and it goes on the staff screen as such.
		 */
		if ( 201 === $code && ! empty( $data['sid'] ) ) {
			return [
				'ok'    => true,
				'id'    => (string) $data['sid'],
				'error' => '',
			];
		}

		$why = (string) ( $data['message'] ?? '' );

		if ( '' === $why ) {
			$why = sprintf( 'Twilio answered HTTP %d', $code );
		}

		if ( ! empty( $data['code'] ) ) {
			$why .= sprintf( ' (error %s)', (string) $data['code'] );
		}

		return [
			'ok'    => false,
			'id'    => '',
			'error' => $why,
		];
	}

	/**
	 * Where Twilio reports delivery back to.
	 *
	 * Only on a public https site. A localhost URL is not reachable from
	 * Twilio and would be rejected as invalid, failing the send for a reason
	 * that has nothing to do with the message.
	 */
	public static function status_callback_url(): string {

		$url = rest_url( \TDH\Sms::REST_NAMESPACE . \TDH\Sms::ROUTE_STATUS );

		if ( ! str_starts_with( $url, 'https://' ) ) {
			return '';
		}

		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( in_array( $host, [ 'localhost', '127.0.0.1' ], true ) || str_ends_with( $host, '.local' ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Is this request really from Twilio?
	 *
	 * Twilio signs every webhook: HMAC-SHA1, keyed with the Auth Token, over
	 * the full URL it posted to followed by every POST field name and value
	 * in sorted order, base64-encoded, in the X-Twilio-Signature header.
	 * Without this check anyone who found the route could opt every
	 * landlord out with one curl.
	 *
	 * @param string               $url    The URL Twilio was given, exactly.
	 * @param array<string,string> $params The POST fields, unslashed.
	 * @param string               $header The X-Twilio-Signature header.
	 */
	public static function verify_signature( string $url, array $params, string $header ): bool {

		$token = self::token();

		if ( '' === $token || '' === $header ) {
			return false;
		}

		ksort( $params );

		$data = $url;

		foreach ( $params as $key => $value ) {
			$data .= $key . $value;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the algorithm Twilio specifies.
		$expected = base64_encode( hash_hmac( 'sha1', $data, $token, true ) );

		return hash_equals( $expected, $header );
	}
}
