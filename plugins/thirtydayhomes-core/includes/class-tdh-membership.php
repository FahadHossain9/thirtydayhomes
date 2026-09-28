<?php
/**
 * Membership state.
 *
 * The seam between billing and the rest of the marketplace. Everything that
 * needs to know whether a landlord may publish — the dashboard, the listing
 * form, the visibility rule — asks this class and nothing else.
 *
 * Nothing outside this class knows that Stripe exists. The webhook writes
 * these meta keys through apply() and every reader — the dashboard, the
 * listing form, the visibility rule — asks the getters. A dashboard that
 * queried Stripe directly would have to be rewritten the day the client
 * changes processor, and would be making a network call to render a page.
 *
 * A landlord with no plan is NONE with a quota of zero, and the dashboard
 * says so plainly rather than showing an invented "active" state.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Membership status, plan and listing allowance for a landlord.
 */
final class Membership {

	/** No plan has ever been started. The state a new landlord is in. */
	public const NONE = 'none';

	/** Paid and current. The only state that allows publishing. */
	public const ACTIVE = 'active';

	/** A renewal failed and we are still retrying. Listings go to
	 *  Statuses::BILLING_HOLD, not PAUSED — see that class. */
	public const PAST_DUE = 'past_due';

	/** The landlord cancelled. Runs to the end of the paid period. */
	public const CANCELLED = 'cancelled';

	/** Cancelled or failed, and the paid period has now ended. */
	public const EXPIRED = 'expired';

	public const META_STATUS  = '_tdh_membership_status';
	public const META_PLAN    = '_tdh_membership_plan';
	public const META_EXPIRES = '_tdh_membership_expires';
	public const META_QUOTA   = '_tdh_listing_quota';

	/**
	 * The link back to the payment processor.
	 *
	 * Stored per user so a webhook carrying only a customer id can find who
	 * it is about. Kept separate from the status keys above because these
	 * two are the only place the processor's vocabulary appears — everything
	 * else in the codebase reads the status, not the subscription.
	 */
	public const META_CUSTOMER     = '_tdh_stripe_customer_id';
	public const META_SUBSCRIPTION = '_tdh_stripe_subscription_id';

	/**
	 * Listings allowed when no plan sets an explicit quota.
	 *
	 * Zero, not three. A landlord with no plan can publish nothing, and the
	 * homepage's "3 listings per plan" is an unconfirmed marketing figure —
	 * see DEVELOPMENT_PLAN.md §2.4. Wiring that number in here would turn a
	 * placeholder into an enforced business rule.
	 */
	public const DEFAULT_QUOTA = 0;

	/**
	 * Every status and its human label.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return [
			self::NONE      => __( 'No active plan', 'thirtydayhomes' ),
			self::ACTIVE    => __( 'Active', 'thirtydayhomes' ),
			self::PAST_DUE  => __( 'Payment failed', 'thirtydayhomes' ),
			self::CANCELLED => __( 'Cancelling', 'thirtydayhomes' ),
			self::EXPIRED   => __( 'Expired', 'thirtydayhomes' ),
		];
	}

	/**
	 * The status badge class, matching the prototype's .status variants.
	 */
	public static function badge_class( string $status ): string {
		return [
			self::ACTIVE    => 'active',
			self::PAST_DUE  => 'past_due',
			self::CANCELLED => 'pending',
			self::EXPIRED   => 'inactive',
		][ $status ] ?? 'inactive';
	}

	/**
	 * A landlord's membership status.
	 *
	 * Filterable so the billing layer can answer from its own records
	 * during the migration rather than requiring a backfill first.
	 *
	 * @param int $user_id Defaults to the current user.
	 */
	public static function status( int $user_id = 0 ): string {

		$user_id = $user_id ?: get_current_user_id();

		if ( ! $user_id ) {
			return self::NONE;
		}

		$status = (string) get_user_meta( $user_id, self::META_STATUS, true );

		if ( ! array_key_exists( $status, self::labels() ) ) {
			$status = self::NONE;
		}

		// An expiry in the past outranks a stale "active" left behind by a
		// webhook that never arrived. Trusting the stored status alone would
		// let a lapsed member keep publishing until someone noticed.
		$expires = self::expires( $user_id );

		if ( $expires && $expires < time() && in_array( $status, [ self::ACTIVE, self::CANCELLED ], true ) ) {
			$status = self::EXPIRED;
		}

		/**
		 * Filter the resolved membership status.
		 *
		 * @param string $status  One of the class constants.
		 * @param int    $user_id Landlord being checked.
		 */
		return (string) apply_filters( 'tdh_membership_status', $status, $user_id );
	}

	/**
	 * True when the landlord may have listings visible to the public.
	 */
	public static function is_active( int $user_id = 0 ): bool {
		return self::ACTIVE === self::status( $user_id );
	}

	/**
	 * The plan name, or an empty string.
	 */
	public static function plan( int $user_id = 0 ): string {
		$user_id = $user_id ?: get_current_user_id();

		return $user_id ? (string) get_user_meta( $user_id, self::META_PLAN, true ) : '';
	}

	/**
	 * Expiry as a unix timestamp, or 0 when none is set.
	 */
	public static function expires( int $user_id = 0 ): int {
		$user_id = $user_id ?: get_current_user_id();

		if ( ! $user_id ) {
			return 0;
		}

		$raw = get_user_meta( $user_id, self::META_EXPIRES, true );

		if ( '' === $raw || null === $raw ) {
			return 0;
		}

		return is_numeric( $raw ) ? (int) $raw : (int) strtotime( (string) $raw );
	}

	/**
	 * How many listings this landlord's plan allows.
	 */
	public static function quota( int $user_id = 0 ): int {

		$user_id = $user_id ?: get_current_user_id();

		if ( ! $user_id ) {
			return 0;
		}

		$quota = get_user_meta( $user_id, self::META_QUOTA, true );
		$quota = '' === $quota ? self::DEFAULT_QUOTA : (int) $quota;

		/**
		 * Filter the listing allowance.
		 *
		 * @param int $quota   Listings allowed.
		 * @param int $user_id Landlord being checked.
		 */
		return (int) apply_filters( 'tdh_listing_quota', max( 0, $quota ), $user_id );
	}

	/**
	 * Listings this landlord currently holds, in every status.
	 *
	 * Counts drafts and pending too: the quota is what they may HAVE, not
	 * what is live, otherwise a landlord could queue fifty drafts and
	 * publish them the moment a plan starts.
	 */
	public static function listing_count( int $user_id = 0 ): int {

		$user_id = $user_id ?: get_current_user_id();

		if ( ! $user_id ) {
			return 0;
		}

		// WordPress's own count_user_posts() counts published posts only, so
		// it reports zero for a landlord whose listings are all pending
		// approval — which is every new landlord.
		$statuses = array_merge(
			[ 'publish', 'pending', 'draft', 'future', 'private' ],
			array_keys( Statuses::all() )
		);

		$query = new \WP_Query(
			[
				'post_type'             => Post_Types::LISTING,
				'author'                => $user_id,
				'post_status'           => $statuses,
				'posts_per_page'        => -1,
				'fields'                => 'ids',
				'no_found_rows'         => true,
				// The owner counting their own listings must see all of them.
				'tdh_bypass_visibility' => true,
			]
		);

		return (int) $query->post_count;
	}

	/**
	 * Whether this landlord may create another listing.
	 */
	public static function can_add_listing( int $user_id = 0 ): bool {

		$user_id = $user_id ?: get_current_user_id();
		$quota   = self::quota( $user_id );

		return $quota > 0 && self::listing_count( $user_id ) < $quota;
	}

	/* ---------------------------------------------------------------------
	 * Writing — only the billing layer should call these
	 * ------------------------------------------------------------------ */

	/**
	 * Record a membership change.
	 *
	 * ONE method rather than four setters, because these values are only ever
	 * meaningful together. Set the status to ACTIVE without also setting the
	 * quota and a landlord is active with an allowance of zero; set the quota
	 * without the expiry and a lapsed member keeps publishing. A single call
	 * makes a half-applied state something you would have to write on purpose.
	 *
	 * Only the keys present in $changes are touched, so an invoice event that
	 * knows the new expiry but not the plan does not blank the plan.
	 *
	 * @param int                  $user_id Landlord.
	 * @param array<string,mixed>  $changes status, plan, expires, quota,
	 *                                      customer, subscription.
	 */
	public static function apply( int $user_id, array $changes ): void {

		if ( ! $user_id ) {
			return;
		}

		$before = [
			'status' => self::status( $user_id ),
			'quota'  => self::quota( $user_id ),
		];

		$map = [
			'status'       => self::META_STATUS,
			'plan'         => self::META_PLAN,
			'expires'      => self::META_EXPIRES,
			'quota'        => self::META_QUOTA,
			'customer'     => self::META_CUSTOMER,
			'subscription' => self::META_SUBSCRIPTION,
		];

		foreach ( $map as $key => $meta ) {

			if ( ! array_key_exists( $key, $changes ) ) {
				continue;
			}

			$value = $changes[ $key ];

			if ( 'status' === $key && ! array_key_exists( (string) $value, self::labels() ) ) {
				// An unknown status would read as NONE through the getter
				// anyway; refusing to store it keeps the database honest.
				continue;
			}

			if ( in_array( $key, [ 'expires', 'quota' ], true ) ) {
				$value = max( 0, (int) $value );
			}

			update_user_meta( $user_id, $meta, $value );
		}

		/**
		 * Fires after a membership changes.
		 *
		 * The seam for everything that should react rather than poll: putting
		 * a landlord's listings on billing hold when they go past due,
		 * restoring them when they pay, emailing a receipt.
		 *
		 * @param int                 $user_id Landlord.
		 * @param array<string,mixed> $changes What was applied.
		 * @param array<string,mixed> $before  status and quota beforehand.
		 */
		do_action( 'tdh_membership_changed', $user_id, $changes, $before );
	}

	/**
	 * Find the landlord behind a Stripe customer id.
	 *
	 * Returns 0 when nobody matches, which a caller must treat as "do not
	 * guess" rather than "use the current user" — a webhook has no current
	 * user, and falling back to one would apply somebody else's subscription.
	 */
	public static function user_for_customer( string $customer_id ): int {

		if ( '' === $customer_id ) {
			return 0;
		}

		$users = get_users(
			[
				'meta_key'   => self::META_CUSTOMER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $customer_id,        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 2,
				'fields'     => 'ID',
			]
		);

		// Exactly one, or nothing. Two users sharing a customer id is a data
		// fault, and picking one at random would silently bill the wrong
		// person's account into the right person's membership.
		return 1 === count( $users ) ? (int) $users[0] : 0;
	}
}
