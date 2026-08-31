<?php
/**
 * Stripe webhook endpoint.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Billing;

use TDH\Membership;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/tdh/v1/stripe-webhook
 *
 * ─── THIS ENDPOINT IS PUBLIC, AND THE SIGNATURE IS THE ONLY AUTHENTICATION ──
 *
 * Anyone on the internet can POST here. The signing secret is what separates
 * a real Stripe event from someone who has read our source and would like a
 * free membership. Four things have to hold, and dropping any one of them
 * makes the other three worthless:
 *
 *   1. The signature is computed over the RAW request body. Decoding JSON and
 *      re-encoding it changes key order and whitespace, so the HMAC no longer
 *      matches what Stripe signed — this is the single most common way a
 *      webhook ends up "verifying" nothing at all.
 *   2. The comparison is timing-safe. A plain === leaks, byte by byte, how
 *      much of a guessed signature was right.
 *   3. The timestamp is checked. Without it a valid request captured once can
 *      be replayed forever.
 *   4. The event id is remembered. Stripe retries on any non-2xx and can
 *      deliver the same event twice in normal operation; processing a renewal
 *      twice would extend a membership twice.
 *
 * ─── WHY IT ALWAYS RETURNS 200 ─────────────────────────────────────────────
 *
 * For anything that is genuinely from Stripe but that we do not act on, this
 * answers 200. Stripe retries non-2xx responses for up to three days and then
 * disables the endpoint — so returning an error for an event we simply do not
 * care about would eventually switch off the ones we do.
 *
 * A rejected signature is different: that is a 400, because it is not from
 * Stripe and nothing should encourage a repeat.
 */
final class Webhook {

	public const NAMESPACE = 'tdh/v1';
	public const ROUTE     = '/stripe-webhook';

	/** How far out of date a signature may be, in seconds. */
	private const TOLERANCE = 300;

	/**
	 * How long a processed event id is remembered.
	 *
	 * Longer than Stripe's retry window, which runs for up to three days.
	 * A shorter memory would let the last retry of a failed delivery be
	 * treated as a new event.
	 */
	private const SEEN_TTL = 4 * DAY_IN_SECONDS;

	private const SEEN_PREFIX = 'tdh_stripe_event_';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
	}

	public function register_route(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'  => 'POST',
				'callback' => [ $this, 'handle' ],

				// Public by design. The signature check inside handle() is the
				// authentication; there is no cookie or nonce on a server-to-
				// server call from Stripe.
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * @param \WP_REST_Request $request Incoming request.
	 */
	public function handle( $request ): \WP_REST_Response {

		$mode   = Stripe::mode();
		$secret = Stripe::webhook_secret( $mode );

		if ( '' === $secret ) {
			// Not configured. 500 rather than 200, because this one IS worth
			// retrying — the secret may be minutes away from being pasted in,
			// and silently discarding a payment event would be worse.
			return new \WP_REST_Response( [ 'error' => 'not_configured' ], 500 );
		}

		// The raw body, never get_json_params(). See the note above.
		$payload = (string) $request->get_body();
		$header  = (string) $request->get_header( 'stripe_signature' );

		if ( ! self::verify( $payload, $header, $secret ) ) {
			return new \WP_REST_Response( [ 'error' => 'bad_signature' ], 400 );
		}

		$event = json_decode( $payload, true );

		if ( ! is_array( $event ) || empty( $event['id'] ) || empty( $event['type'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'malformed' ], 400 );
		}

		/*
		 * A live event must never touch a site in test mode, or the reverse.
		 * Both secrets could be valid at once during a switch-over, and a
		 * stray test subscription granting a real allowance — or a live
		 * cancellation wiping a sandbox — is exactly the confusion the two
		 * separate credential sets exist to prevent.
		 */
		$event_is_live = ! empty( $event['livemode'] );

		if ( $event_is_live !== ( Stripe::MODE_LIVE === $mode ) ) {
			return new \WP_REST_Response( [ 'ignored' => 'mode_mismatch' ], 200 );
		}

		$id = (string) $event['id'];

		if ( self::already_seen( $id ) ) {
			return new \WP_REST_Response( [ 'ignored' => 'duplicate' ], 200 );
		}

		// Marked BEFORE the work, not after. A handler that fatals halfway
		// through would otherwise be retried and repeat whatever it had
		// already done.
		self::mark_seen( $id );

		$this->dispatch( (string) $event['type'], (array) ( $event['data']['object'] ?? [] ), $mode );

		return new \WP_REST_Response( [ 'received' => true ], 200 );
	}

	/* ---------------------------------------------------------------------
	 * Signature
	 * ------------------------------------------------------------------ */

	/**
	 * Verify a `Stripe-Signature` header against the raw payload.
	 *
	 * Header shape: `t=1614556800,v1=<hex>,v1=<hex>`
	 * Signed payload: the timestamp, a literal dot, then the body.
	 *
	 * More than one v1 is normal — Stripe sends one per active secret while a
	 * secret is being rotated, so the check passes if ANY of them matches.
	 *
	 * Public and static so it can be tested directly with payloads we control,
	 * including the ones an attacker would send.
	 */
	public static function verify( string $payload, string $header, string $secret, ?int $now = null ): bool {

		if ( '' === $header || '' === $secret ) {
			return false;
		}

		$now        = $now ?? time();
		$timestamp  = 0;
		$signatures = [];

		foreach ( explode( ',', $header ) as $part ) {

			$pair = explode( '=', trim( $part ), 2 );

			if ( 2 !== count( $pair ) ) {
				continue;
			}

			if ( 't' === $pair[0] ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}

		if ( ! $timestamp || ! $signatures ) {
			return false;
		}

		// Both directions. Too old is a replay; too far in the future is a
		// clock that cannot be trusted to make "too old" mean anything.
		if ( abs( $now - $timestamp ) > self::TOLERANCE ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

		foreach ( $signatures as $signature ) {
			// hash_equals, never ===. A short-circuiting comparison reveals
			// how many leading bytes were correct, which is enough to forge a
			// signature one byte at a time.
			if ( hash_equals( $expected, (string) $signature ) ) {
				return true;
			}
		}

		return false;
	}

	/* ---------------------------------------------------------------------
	 * Idempotency
	 * ------------------------------------------------------------------ */

	private static function already_seen( string $event_id ): bool {
		return (bool) get_transient( self::SEEN_PREFIX . md5( $event_id ) );
	}

	private static function mark_seen( string $event_id ): void {
		set_transient( self::SEEN_PREFIX . md5( $event_id ), 1, self::SEEN_TTL );
	}

	/* ---------------------------------------------------------------------
	 * Events
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string,mixed> $object The event's data.object.
	 */
	private function dispatch( string $type, array $object, string $mode ): void {

		switch ( $type ) {

			case 'checkout.session.completed':
				$this->on_checkout_complete( $object, $mode );
				break;

			case 'customer.subscription.created':
			case 'customer.subscription.updated':
			case 'customer.subscription.deleted':
				$this->on_subscription( $type, $object, $mode );
				break;

			default:
				/**
				 * Fires for a verified event this class does not handle.
				 *
				 * The seam for invoices, receipts and anything added later,
				 * without reopening the verification code.
				 *
				 * @param string              $type   Stripe event type.
				 * @param array<string,mixed> $object The event's data.object.
				 * @param string              $mode   test or live.
				 */
				do_action( 'tdh_stripe_event', $type, $object, $mode );
		}
	}

	/**
	 * Someone finished paying. Link the customer to the user.
	 *
	 * @param array<string,mixed> $session
	 */
	private function on_checkout_complete( array $session, string $mode ): void {

		$user_id = (int) ( $session['client_reference_id'] ?? 0 );

		// client_reference_id is what checkout puts there, and is the only
		// trustworthy link on a FIRST payment — the customer id cannot be
		// looked up yet because this is the event that creates it.
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return;
		}

		$customer = is_string( $session['customer'] ?? null ) ? (string) $session['customer'] : '';

		if ( '' !== $customer ) {
			Membership::apply( $user_id, [ 'customer' => $customer ] );
		}

		// The subscription itself carries the price, period end and status,
		// and arrives as its own event moments later. Nothing is granted
		// here, so a malformed or partial session cannot hand out allowance.
	}

	/**
	 * The subscription changed. This is what actually grants or removes
	 * allowance.
	 *
	 * @param array<string,mixed> $subscription
	 */
	private function on_subscription( string $type, array $subscription, string $mode ): void {

		$customer = is_string( $subscription['customer'] ?? null ) ? (string) $subscription['customer'] : '';
		$user_id  = Membership::user_for_customer( $customer );

		if ( ! $user_id ) {
			return;
		}

		$price_id = (string) ( $subscription['items']['data'][0]['price']['id'] ?? '' );
		$plan     = Stripe::plan_for_price( $price_id, $mode );

		$period_end = (int) ( $subscription['current_period_end'] ?? 0 );
		$cancelling = ! empty( $subscription['cancel_at_period_end'] );

		$changes = [
			'subscription' => (string) ( $subscription['id'] ?? '' ),
			'expires'      => $period_end,
		];

		if ( 'customer.subscription.deleted' === $type ) {
			// Gone for good. Allowance goes to zero the moment it ends rather
			// than being left for a cron to notice.
			Membership::apply(
				$user_id,
				array_merge( $changes, [ 'status' => Membership::EXPIRED, 'quota' => 0 ] )
			);

			return;
		}

		$changes['status'] = self::map_status( (string) ( $subscription['status'] ?? '' ), $cancelling );

		/*
		 * Quota follows the plan, and only when the subscription is one that
		 * should be publishing. An unrecognised Price grants nothing — see
		 * Stripe::plan_for_price(). A past-due member keeps their listings on
		 * hold rather than losing the allowance outright, so paying restores
		 * them without republishing anything.
		 */
		if ( null !== $plan && Membership::ACTIVE === $changes['status'] ) {
			$changes['quota'] = (int) $plan['listings'];
			$changes['plan']  = (string) $plan['label'];
		} elseif ( in_array( $changes['status'], [ Membership::EXPIRED, Membership::NONE ], true ) ) {
			$changes['quota'] = 0;
		}

		Membership::apply( $user_id, $changes );
	}

	/**
	 * Stripe's subscription status, in our vocabulary.
	 *
	 * `trialing` counts as active: a trial is a subscription that should be
	 * publishing. `incomplete` does not — the first payment has not settled,
	 * and treating it as active would grant allowance for money that may
	 * never arrive.
	 */
	public static function map_status( string $stripe_status, bool $cancel_at_period_end = false ): string {

		if ( $cancel_at_period_end && in_array( $stripe_status, [ 'active', 'trialing' ], true ) ) {
			// Still paid up to the period end, so still publishing — but the
			// dashboard should say it is ending.
			return Membership::CANCELLED;
		}

		return [
			'active'             => Membership::ACTIVE,
			'trialing'           => Membership::ACTIVE,
			'past_due'           => Membership::PAST_DUE,
			'unpaid'             => Membership::PAST_DUE,
			'canceled'           => Membership::EXPIRED,
			'incomplete_expired' => Membership::EXPIRED,
			'incomplete'         => Membership::NONE,
			'paused'             => Membership::NONE,
		][ $stripe_status ] ?? Membership::NONE;
	}
}
