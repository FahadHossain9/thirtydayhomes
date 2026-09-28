<?php
/**
 * What an SMS gateway has to be able to do.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Sms;

defined( 'ABSPATH' ) || exit;

/**
 * One method, deliberately.
 *
 * Everything the marketplace knows about text messages — who consented,
 * what the template says, when to retry, what to log — lives above this
 * line and never learns which company carries the message. Below it there
 * is exactly one job: take a number and a body, and say what happened.
 * That is what lets the test suite stand in a fake and drive every path
 * without a socket, and what lets Twilio be swapped without touching a
 * landlord's settings.
 */
interface Provider {

	/**
	 * Send one message.
	 *
	 * @param string $to   E.164, e.g. +14125550184.
	 * @param string $body The text, already final.
	 * @return array{ok:bool,id:string,error:string} ok; the provider's message
	 *         id when it accepted the message; a human-readable reason when
	 *         it did not. Never throws — a gateway that is down is an outcome,
	 *         not an exception.
	 */
	public function send( string $to, string $body ): array;

	/** A short name for the log: "twilio", "fake". */
	public function name(): string;
}
