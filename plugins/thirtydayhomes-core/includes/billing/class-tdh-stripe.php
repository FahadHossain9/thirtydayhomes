<?php
/**
 * Stripe credentials and payment mode.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Billing;

use TDH\Render;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that answers "which Stripe are we talking to, and with what".
 *
 * ─── TEST AND LIVE NEVER SHARE A SLOT ───────────────────────────────────
 *
 * Every credential is stored under a name that contains its mode, so the two
 * sets sit side by side in the database and the mode switch only chooses
 * which set is READ. Flipping to live to check something and flipping back
 * must leave the sandbox keys exactly where they were. A single "secret key"
 * slot that the mode switch rewrites is how a sandbox key gets overwritten by
 * a live one and never noticed until a real card is charged in testing.
 *
 * ─── CONSTANTS BEAT THE DATABASE ────────────────────────────────────────
 *
 * Any credential can be set in wp-config.php instead:
 *
 *     define( 'TDH_STRIPE_LIVE_SECRET', 'sk_live_...' );
 *
 * and that wins over whatever is stored. Secrets in the options table end up
 * in every database export, every migration and every backup that gets
 * emailed around; a constant stays in a file that is not in the database and
 * not in the repository. The settings screen shows such a field as locked
 * rather than pretending it can be edited.
 *
 * ─── DEFAULTS TO TEST ───────────────────────────────────────────────────
 *
 * A fresh install, a restored backup and a site with a corrupt option all
 * resolve to test mode. Nothing about this class should ever make "take real
 * money" the fallback.
 */
final class Stripe {

	public const MODE_TEST = 'test';
	public const MODE_LIVE = 'live';

	private const OPTION_MODE   = 'tdh_stripe_mode';
	private const OPTION_PREFIX = 'tdh_stripe_';

	/** Secret fields — never echoed back to a browser in full. */
	public const SECRET_FIELDS = [ 'secret', 'webhook' ];

	/**
	 * @return array<string,string> mode => human label
	 */
	public static function modes(): array {
		return [
			self::MODE_TEST => __( 'Test mode', 'thirtydayhomes' ),
			self::MODE_LIVE => __( 'Live mode', 'thirtydayhomes' ),
		];
	}

	/**
	 * The mode payments and webhooks actually use.
	 *
	 * Anything that is not exactly "live" reads as test, so a truncated or
	 * hand-edited option cannot land the site in live mode by accident.
	 */
	public static function mode(): string {
		return self::MODE_LIVE === get_option( self::OPTION_MODE ) ? self::MODE_LIVE : self::MODE_TEST;
	}

	public static function set_mode( string $mode ): void {
		update_option( self::OPTION_MODE, self::MODE_LIVE === $mode ? self::MODE_LIVE : self::MODE_TEST );
	}

	public static function is_live(): bool {
		return self::MODE_LIVE === self::mode();
	}

	public static function is_test(): bool {
		return ! self::is_live();
	}

	/** Where one credential is stored. */
	public static function option_name( string $mode, string $field ): string {
		return self::OPTION_PREFIX . $mode . '_' . $field;
	}

	/** The wp-config.php constant that overrides it. */
	public static function constant_name( string $mode, string $field ): string {
		return 'TDH_STRIPE_' . strtoupper( $mode . '_' . $field );
	}

	public static function is_locked( string $mode, string $field ): bool {
		return defined( self::constant_name( $mode, $field ) );
	}

	/**
	 * Read one credential, constant first.
	 *
	 * @param string      $field publishable | secret | webhook | price_1 | price_2 …
	 * @param string|null $mode  Defaults to the active mode.
	 */
	public static function credential( string $field, ?string $mode = null ): string {

		$mode  = self::normalise_mode( $mode );
		$const = self::constant_name( $mode, $field );

		if ( defined( $const ) ) {
			return trim( (string) constant( $const ) );
		}

		return trim( (string) get_option( self::option_name( $mode, $field ), '' ) );
	}

	public static function save_credential( string $mode, string $field, string $value ): void {
		update_option( self::option_name( self::normalise_mode( $mode ), $field ), $value );
	}

	public static function publishable_key( ?string $mode = null ): string {
		return self::credential( 'publishable', $mode );
	}

	public static function secret_key( ?string $mode = null ): string {
		return self::credential( 'secret', $mode );
	}

	public static function webhook_secret( ?string $mode = null ): string {
		return self::credential( 'webhook', $mode );
	}

	/**
	 * The Stripe Price for the plan granting this many listings.
	 */
	public static function price_id( int $listings, ?string $mode = null ): string {
		return self::credential( self::price_field( $listings ), $mode );
	}

	public static function price_field( int $listings ): string {
		return 'price_' . max( 1, $listings );
	}

	/**
	 * The plans a Price ID has to be supplied for.
	 *
	 * Derived from Render::plans() rather than hardcoded, so adding a fourth
	 * plan grows the settings screen instead of silently shipping a plan with
	 * nothing to charge for it.
	 *
	 * @return array<int,array{listings:int,label:string,price:float}>
	 */
	public static function plans(): array {

		if ( ! class_exists( Render::class ) ) {
			return [];
		}

		$plans = [];

		foreach ( Render::plans() as $plan ) {
			$plans[] = [
				'listings' => (int) $plan['listings'],
				'label'    => (string) $plan['label'],
				'price'    => (float) $plan['price'],
			];
		}

		return $plans;
	}

	/**
	 * Where Stripe should send events. Shown on the settings screen so it can
	 * be pasted into the Stripe dashboard without hunting for it.
	 */
	public static function webhook_url(): string {
		return rest_url( 'tdh/v1/stripe-webhook' );
	}

	/**
	 * Can this mode actually take a payment?
	 *
	 * Price IDs are deliberately not required here: keys plus a webhook
	 * secret is what "connected to Stripe" means, and a plan with no Price
	 * is reported separately so the message can name the plan.
	 */
	public static function is_configured( ?string $mode = null ): bool {

		$mode = self::normalise_mode( $mode );

		return '' !== self::credential( 'publishable', $mode )
			&& '' !== self::credential( 'secret', $mode )
			&& '' !== self::credential( 'webhook', $mode );
	}

	/**
	 * Plans in this mode with no Price ID set.
	 *
	 * @return array<int,string> Plan labels.
	 */
	public static function plans_missing_price( ?string $mode = null ): array {

		$mode    = self::normalise_mode( $mode );
		$missing = [];

		foreach ( self::plans() as $plan ) {
			if ( '' === self::price_id( $plan['listings'], $mode ) ) {
				$missing[] = $plan['label'];
			}
		}

		return $missing;
	}

	/**
	 * What a credential must begin with, per mode.
	 *
	 * This is the most valuable validation on the settings screen. Stripe
	 * stamps the mode into the key itself, so a live secret pasted into the
	 * sandbox field is detectable before it ever charges a real card — which
	 * is otherwise a mistake nobody notices until a customer complains.
	 *
	 * Price IDs carry no mode marker, so they cannot be checked this way;
	 * the connection test is what catches a mismatched Price.
	 *
	 * @return array<int,string> Acceptable prefixes. Empty means anything.
	 */
	public static function expected_prefixes( string $field, string $mode ): array {

		$mode = self::normalise_mode( $mode );

		if ( 'publishable' === $field ) {
			return [ 'pk_' . $mode . '_' ];
		}

		if ( 'secret' === $field ) {
			// rk_ is a restricted key, which is the better practice for a
			// server that only needs a few permissions.
			return [ 'sk_' . $mode . '_', 'rk_' . $mode . '_' ];
		}

		if ( 'webhook' === $field ) {
			return [ 'whsec_' ];
		}

		if ( str_starts_with( $field, 'price_' ) ) {
			return [ 'price_' ];
		}

		return [];
	}

	private static function normalise_mode( ?string $mode ): string {

		if ( null === $mode ) {
			return self::mode();
		}

		return self::MODE_LIVE === $mode ? self::MODE_LIVE : self::MODE_TEST;
	}
}
