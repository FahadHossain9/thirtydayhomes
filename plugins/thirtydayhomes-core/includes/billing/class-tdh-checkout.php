<?php
/**
 * Stripe Checkout.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Billing;

use TDH\Accounts;
use TDH\Membership;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a landlord to Stripe to start a subscription.
 *
 * ─── THE BROWSER NEVER NAMES A PRICE ───────────────────────────────────────
 *
 * The form posts a listing count — 1, 2 or 3 — and this class looks up which
 * Stripe Price that is. It would be far easier to put the Price ID in a
 * hidden field and pass it straight through, and it is the single most
 * expensive mistake available here: anyone can edit a hidden field, and a
 * Price from some other product would buy a three-listing allowance for
 * whatever that product costs. The plan is chosen from OUR table, by a number
 * that means nothing to Stripe.
 *
 * ─── THE SUCCESS URL IS NOT PROOF OF PAYMENT ───────────────────────────────
 *
 * Nothing is granted when someone comes back from Stripe. That redirect is
 * just a URL — anyone can visit it, and a customer can close the tab before
 * it ever loads. The webhook is the only thing that grants a membership,
 * because it is the only thing Stripe signs. The return page reads the real
 * membership state and says "confirming" until the webhook has landed.
 */
final class Checkout {

	private const ACTION = 'tdh_checkout';
	private const NONCE  = 'tdh_start_checkout';

	/** Where a returning customer lands. */
	public const RETURN_ARG = 'tdh_checkout';

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'maybe_start' ] );
	}

	/**
	 * Is checkout available at all right now?
	 *
	 * Used by the pricing table so a button is never shown that could only
	 * fail: the keys needed to open a session, and a Price for the plan in
	 * question — a plan whose Price is missing cannot be bought even though
	 * the rest is configured.
	 *
	 * DELIBERATELY NOT Stripe::is_configured(), which also demands a webhook
	 * secret. That secret is required to GO LIVE and the go-live gate checks
	 * it, because taking real money with no way to hear back about it is the
	 * worst outcome available. But it is not needed to open a session, and
	 * requiring it here would make checkout permanently untestable on any
	 * machine Stripe cannot reach — which is every developer's.
	 */
	public static function is_ready( int $listings ): bool {

		$mode = Stripe::mode();

		return '' !== Stripe::publishable_key( $mode )
			&& '' !== Stripe::secret_key( $mode )
			&& '' !== Stripe::price_id( $listings, $mode );
	}

	/**
	 * The form that starts checkout for one plan.
	 *
	 * A POST, not a link. Starting a subscription is not a safe, repeatable
	 * read — it must not be something a crawler, a prefetch or a
	 * <link rel="prerender"> can trigger by following a URL.
	 */
	public static function form_fields( int $listings ): string {

		ob_start();
		wp_nonce_field( self::NONCE );
		?>
		<input type="hidden" name="tdh_action" value="<?php echo esc_attr( self::ACTION ); ?>">
		<input type="hidden" name="tdh_listings" value="<?php echo esc_attr( (string) $listings ); ?>">
		<?php
		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Starting a session
	 * ------------------------------------------------------------------ */

	public function maybe_start(): void {

		if ( ! isset( $_POST['tdh_action'] ) || self::ACTION !== sanitize_key( wp_unslash( (string) $_POST['tdh_action'] ) ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			// Not an error — they simply have to be someone before they can
			// be a paying someone.
			$this->bounce( Accounts::url( 'register' ), 'sign_in_first' );
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), self::NONCE ) ) {
			$this->bounce( self::pricing_url(), 'expired' );
		}

		$user_id = get_current_user_id();

		if ( ! Accounts::is_landlord( $user_id ) && ! user_can( $user_id, 'manage_options' ) ) {
			$this->bounce( self::pricing_url(), 'not_a_landlord' );
		}

		// Already paying. A second subscription would bill them twice for one
		// account, and Stripe will happily create it if asked.
		if ( in_array( Membership::status( $user_id ), [ Membership::ACTIVE, Membership::PAST_DUE ], true ) ) {
			$this->bounce( Accounts::url( 'account' ), 'already_subscribed' );
		}

		$listings = isset( $_POST['tdh_listings'] ) ? absint( wp_unslash( (string) $_POST['tdh_listings'] ) ) : 0;
		$mode     = Stripe::mode();

		// The number is looked up in OUR plan table. An unknown one buys
		// nothing — see the note at the top of this class.
		$plan = null;

		foreach ( Stripe::plans() as $candidate ) {
			if ( (int) $candidate['listings'] === $listings ) {
				$plan = $candidate;
				break;
			}
		}

		if ( null === $plan ) {
			$this->bounce( self::pricing_url(), 'unknown_plan' );
		}

		$price = Stripe::price_id( (int) $plan['listings'], $mode );

		if ( '' === $price ) {
			$this->bounce( self::pricing_url(), 'not_configured' );
		}

		$session = $this->create_session( $user_id, $price, $mode );

		if ( ! $session['ok'] ) {
			$this->bounce( self::pricing_url(), 'stripe_error' );
		}

		$url = (string) ( $session['body']['url'] ?? '' );

		if ( '' === $url ) {
			$this->bounce( self::pricing_url(), 'stripe_error' );
		}

		/*
		 * wp_redirect, NOT wp_safe_redirect. Safe redirect refuses any host
		 * that is not on the allowed list, and this deliberately goes to
		 * checkout.stripe.com. The destination is not user input — it came
		 * back from an authenticated API call we just made — so the check
		 * safe_redirect exists to perform has already been satisfied.
		 */
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * @return array{ok:bool,code:int,body:array<string,mixed>,error:string}
	 */
	private function create_session( int $user_id, string $price, string $mode ): array {

		$user   = get_userdata( $user_id );
		$return = Accounts::url( 'account' );

		$body = [
			'mode'                 => 'subscription',
			'line_items[0][price]' => $price,
			'line_items[0][quantity]' => 1,

			// What the webhook reads on a first payment, when no customer
			// record exists yet to look the user up by.
			'client_reference_id'  => (string) $user_id,

			'success_url' => add_query_arg( self::RETURN_ARG, 'success', $return ),
			'cancel_url'  => add_query_arg( self::RETURN_ARG, 'cancelled', self::pricing_url() ),

			// Metadata rides along to the subscription too, so a support
			// question months later can be answered from the Stripe dashboard
			// without a lookup in our database.
			'metadata[user_id]'    => (string) $user_id,
			'subscription_data[metadata][user_id]' => (string) $user_id,
		];

		$customer = (string) get_user_meta( $user_id, Membership::META_CUSTOMER, true );

		if ( '' !== $customer ) {
			// Reuse the record they already have, or Stripe creates a second
			// customer for the same person and their history splits in two.
			$body['customer'] = $customer;
		} elseif ( $user && is_email( $user->user_email ) ) {
			$body['customer_email'] = $user->user_email;
		}

		/*
		 * Keyed on the user and the price, not on the moment. A double click
		 * sends two identical requests; this makes the second one return the
		 * first session rather than opening a second subscription. The date
		 * keeps it from binding forever, so trying again tomorrow is a fresh
		 * session rather than a replay of a stale one.
		 */
		$idempotency = 'tdh-' . $user_id . '-' . $price . '-' . gmdate( 'Y-m-d' );

		return Stripe::api_post( 'checkout/sessions', $body, $mode, $idempotency );
	}

	/* ---------------------------------------------------------------------
	 * Coming back
	 * ------------------------------------------------------------------ */

	/**
	 * What to tell someone who has just come back from Stripe.
	 *
	 * Reads the real membership rather than the URL. Someone who pastes
	 * ?tdh_checkout=success into the address bar is told, accurately, that
	 * nothing is active — because nothing is.
	 *
	 * @return array{0:string,1:string}|null [ type, message ]
	 */
	public static function return_notice(): ?array {

		$state = isset( $_GET[ self::RETURN_ARG ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::RETURN_ARG ] ) ) : '';

		if ( '' === $state ) {
			return null;
		}

		if ( 'cancelled' === $state ) {
			return [ 'info', __( 'Checkout was cancelled. Nothing has been charged.', 'thirtydayhomes' ) ];
		}

		if ( 'success' !== $state ) {
			return null;
		}

		if ( Membership::is_active() ) {
			return [ 'success', __( 'Payment received. Your plan is active and you can publish your homes.', 'thirtydayhomes' ) ];
		}

		// Paid, but the webhook has not arrived yet — normal for a few
		// seconds. Saying "active" here would be a guess, and saying nothing
		// would read as a failed payment.
		return [
			'info',
			__( 'Thank you. We are confirming your payment with the card network — refresh in a moment and your plan will appear.', 'thirtydayhomes' ),
		];
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	public static function pricing_url(): string {
		$page = get_page_by_path( 'pricing' );

		return $page ? (string) get_permalink( $page ) : home_url( '/' );
	}

	/**
	 * @return array<string,string> reason => message
	 */
	public static function messages(): array {
		return [
			'sign_in_first'      => __( 'Create an account first — it takes a minute, and your plan starts straight after.', 'thirtydayhomes' ),
			'expired'            => __( 'That form expired. Please choose your plan again.', 'thirtydayhomes' ),
			'not_a_landlord'     => __( 'Only landlord accounts can hold a membership.', 'thirtydayhomes' ),
			'already_subscribed' => __( 'You already have a membership. There is nothing more to buy.', 'thirtydayhomes' ),
			'unknown_plan'       => __( 'That plan is not one we offer.', 'thirtydayhomes' ),
			'not_configured'     => __( 'Payments are not switched on yet. Please try again shortly.', 'thirtydayhomes' ),
			'stripe_error'       => __( 'We could not reach the payment provider. Nothing has been charged — please try again.', 'thirtydayhomes' ),
		];
	}

	/**
	 * Send them somewhere with a reason, and stop.
	 *
	 * The reason is a KEY, never a message. A message in the URL is one an
	 * attacker can rewrite, and "Your card was declined, call this number"
	 * on our own domain is a convincing thing to link someone to.
	 */
	private function bounce( string $to, string $reason ): void {
		wp_safe_redirect( add_query_arg( 'tdh_checkout_error', $reason, $to ) );
		exit;
	}
}
