<?php
/**
 * US phone numbers.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * One way to read a phone number in, one way to show it back.
 *
 * Stored as E.164 (+14125550184), because that is the only form an SMS
 * gateway accepts and the only form two copies of the same number compare
 * equal in. Shown as (412) 555-0184, because that is how a person in the US
 * recognises their own number.
 *
 * US only, deliberately. The SMS registration this marketplace runs under
 * (A2P 10DLC) covers US numbers; accepting a foreign one here would store a
 * number the site can never text, and say nothing about it until an
 * inquiry silently failed to arrive.
 */
final class Phone {

	/**
	 * A typed number as E.164, or '' when it is not a valid US number.
	 *
	 * Accepts the ways people actually type: "412-555-0184",
	 * "(412) 555 0184", "+1 412.555.0184", "14125550184".
	 */
	public static function normalise( string $raw ): string {

		$raw = trim( $raw );

		if ( '' === $raw ) {
			return '';
		}

		// A leading + with any country code other than 1 is a foreign
		// number, however many digits follow.
		if ( str_starts_with( $raw, '+' ) && ! str_starts_with( ltrim( substr( $raw, 1 ) ), '1' ) ) {
			return '';
		}

		$digits = (string) preg_replace( '/\D+/', '', $raw );

		if ( 11 === strlen( $digits ) && str_starts_with( $digits, '1' ) ) {
			$digits = substr( $digits, 1 );
		}

		/*
		 * North American numbering: the area code and the exchange both
		 * start 2–9. "123 456 7890" has ten digits and is not a number
		 * anyone owns — accepting it would store a phone that can never ring.
		 */
		if ( ! preg_match( '/^[2-9]\d{2}[2-9]\d{6}$/', $digits ) ) {
			return '';
		}

		return '+1' . $digits;
	}

	/**
	 * "(412) 555-0184" from a stored E.164 number.
	 *
	 * Anything that is not a stored US number comes back unchanged, so a
	 * value typed before this class existed still shows as it was entered
	 * rather than disappearing.
	 */
	public static function display( string $stored ): string {

		$e164 = self::normalise( $stored );

		if ( '' === $e164 ) {
			return $stored;
		}

		$d = substr( $e164, 2 );

		return sprintf( '(%s) %s-%s', substr( $d, 0, 3 ), substr( $d, 3, 3 ), substr( $d, 6 ) );
	}
}
