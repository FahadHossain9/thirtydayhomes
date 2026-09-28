<?php
/**
 * What a membership's state does to a landlord's homes.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Grace, hide, restore (E1).
 *
 * A failed payment starts a grace period in which nothing changes for
 * renters. When it runs out — or a membership ends — every live home of
 * that landlord goes to Statuses::BILLING_HOLD. When the landlord pays,
 * exactly the held homes come back. A home the landlord paused stays
 * paused: that is the whole reason BILLING_HOLD is its own status.
 *
 * ─── ONE QUESTION, ASKED FROM THREE PLACES ─────────────────────────────────
 *
 * enforce() looks at the landlord's membership NOW and makes the homes
 * agree with it. It is idempotent, so it can be called from anywhere:
 * the webhook (through tdh_membership_changed), the daily job (for the
 * grace running out and for an expiry no webhook announced) and staff
 * editing a member. Holding a held home or restoring a live one does
 * nothing, so a webhook delivered twice, or a renewal that beats the
 * daily job, is harmless.
 *
 * ─── NOTHING BUT THE STATUS CHANGES ────────────────────────────────────────
 *
 * The status is written with one UPDATE rather than wp_update_post().
 * wp_update_post() runs the content through the save filters as whoever
 * is current — in cron, nobody — which can alter a description, and it
 * fires every save hook in the plugin for a change that is not an edit.
 * A hold must leave the description, the photos, the terms and the
 * meta exactly as they were.
 */
final class Enforcement {

	/** When the grace period began, on the landlord. Unix time. */
	public const META_GRACE = '_tdh_grace_started';

	/** When the system hid this home, on the listing. MySQL time. */
	public const META_HELD_AT = '_tdh_held_at';

	/** The daily catch-up. */
	public const CRON = 'tdh_enforce_memberships';

	/** "Your homes are back online", said once on the next dashboard view. */
	private const TRANSIENT_RESTORED = 'tdh_homes_restored_';

	/** The three things a membership can mean for the homes. */
	public const OK    = 'ok';
	public const GRACE = 'grace';
	public const HOLD  = 'hold';

	/** @var array<int,int>|null Authors hidden this request; see inactive_ids(). */
	private static ?array $inactive = null;

	public function register(): void {
		add_action( 'tdh_membership_changed', [ $this, 'on_membership_changed' ], 20 );
		add_action( 'init', [ $this, 'schedule' ] );
		add_action( self::CRON, [ $this, 'run' ] );
		add_filter( 'tdh_inactive_member_ids', [ $this, 'inactive_member_ids' ] );
	}

	public function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/** @param int $user_id Whose membership moved. */
	public function on_membership_changed( $user_id ): void {
		self::enforce( (int) $user_id );
	}

	/* ---------------------------------------------------------------------
	 * The rule
	 * ------------------------------------------------------------------ */

	/**
	 * What this landlord's membership means for their homes right now.
	 *
	 * Active and cancelled-but-still-paid publish. Past due publishes for
	 * Visibility::GRACE_DAYS from the first failure, then hides. Expired
	 * and no plan hide. Staff are never billed, so never held.
	 */
	public static function state( int $user_id ): string {

		if ( $user_id < 1 || Accounts::is_staff( $user_id ) ) {
			return self::OK;
		}

		$status = Membership::status( $user_id );

		if ( in_array( $status, [ Membership::ACTIVE, Membership::CANCELLED ], true ) ) {
			return self::OK;
		}

		if ( Membership::PAST_DUE === $status ) {
			$started = (int) get_user_meta( $user_id, self::META_GRACE, true );

			// Past due before anything stamped it (the webhook ran before E1
			// existed): the grace counts from now, never from the past.
			return ( $started < 1 || time() < self::grace_ends( $user_id ) ) ? self::GRACE : self::HOLD;
		}

		return self::HOLD;
	}

	/** The moment the grace period runs out, or 0 when none is running. */
	public static function grace_ends( int $user_id ): int {
		$started = (int) get_user_meta( $user_id, self::META_GRACE, true );

		return $started > 0 ? $started + Visibility::GRACE_DAYS * DAY_IN_SECONDS : 0;
	}

	/**
	 * Make the landlord's homes agree with their membership.
	 *
	 * @return array{state:string,held:int,restored:int}
	 */
	public static function enforce( int $user_id ): array {

		$result = [
			'state'    => self::OK,
			'held'     => 0,
			'restored' => 0,
		];

		if ( $user_id < 1 || Accounts::is_staff( $user_id ) ) {
			return $result;
		}

		self::$inactive = null;

		$status = Membership::status( $user_id );

		// The grace clock starts at the first failure and is never reset by
		// a second failed retry — only a payment clears it.
		if ( Membership::PAST_DUE === $status ) {
			if ( (int) get_user_meta( $user_id, self::META_GRACE, true ) < 1 ) {
				update_user_meta( $user_id, self::META_GRACE, time() );

				if ( class_exists( '\TDH\Log' ) ) {
					Log::warning( 'payments', 'grace_started', sprintf( /* translators: %s: a date */ __( 'A payment failed. The landlord’s homes stay visible until %s.', 'thirtydayhomes' ), wp_date( 'j M Y', self::grace_ends( $user_id ) ) ), [], $user_id, 'user' );
				}
			}
		} else {
			delete_user_meta( $user_id, self::META_GRACE );
		}

		$result['state'] = self::state( $user_id );

		if ( self::HOLD === $result['state'] ) {
			$result['held'] = self::move( $user_id, Statuses::public_status(), Statuses::BILLING_HOLD );

			if ( $result['held'] > 0 && class_exists( '\TDH\Log' ) ) {
				Log::warning( 'listings', 'billing_hold', sprintf( /* translators: %d: number of homes */ _n( '%d home hidden because the membership is not active.', '%d homes hidden because the membership is not active.', $result['held'], 'thirtydayhomes' ), $result['held'] ), [ 'status' => $status ], $user_id, 'user' );
			}
		} elseif ( self::OK === $result['state'] ) {
			$result['restored'] = self::move( $user_id, Statuses::BILLING_HOLD, Statuses::public_status() );

			if ( $result['restored'] > 0 ) {
				set_transient( self::TRANSIENT_RESTORED . $user_id, $result['restored'], WEEK_IN_SECONDS );

				if ( class_exists( '\TDH\Log' ) ) {
					Log::info( 'listings', 'billing_restored', sprintf( /* translators: %d: number of homes */ _n( '%d home back online after payment.', '%d homes back online after payment.', $result['restored'], 'thirtydayhomes' ), $result['restored'] ), [ 'status' => $status ], $user_id, 'user' );
				}
			}
		}

		self::$inactive = null;

		return $result;
	}

	/**
	 * Move every home of one landlord from one status to another.
	 *
	 * @return int How many moved.
	 */
	private static function move( int $user_id, string $from, string $to ): int {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d AND post_status = %s", Post_Types::LISTING, $user_id, $from ) ) );

		$moved = 0;

		foreach ( $ids as $id ) {

			$before = get_post( $id );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ok = $wpdb->update( $wpdb->posts, [ 'post_status' => $to ], [ 'ID' => $id, 'post_status' => $from ], [ '%s' ], [ '%d', '%s' ] );

			if ( ! $ok ) {
				// One home refused is not a reason to leave the rest as they
				// were; it is named in the log and the next run tries again.
				if ( false === $ok && class_exists( '\TDH\Log' ) ) {
					Log::error( 'listings', 'billing_move_failed', __( 'A home’s visibility could not be changed for its membership. The next daily check tries again.', 'thirtydayhomes' ), [ 'from' => $from, 'to' => $to ], $id, 'listing' );
				}
				continue;
			}

			clean_post_cache( $id );

			if ( Statuses::BILLING_HOLD === $to ) {
				update_post_meta( $id, self::META_HELD_AT, current_time( 'mysql' ) );
			} else {
				delete_post_meta( $id, self::META_HELD_AT );
			}

			$after = get_post( $id );

			if ( $before instanceof \WP_Post && $after instanceof \WP_Post ) {
				// Page caches and anything else listening for a visibility change.
				wp_transition_post_status( $to, $from, $after );
			}

			++$moved;
		}

		return $moved;
	}

	/**
	 * The daily catch-up.
	 *
	 * Every landlord who has a live or held home, or is past due, is asked
	 * the one question again. That catches a grace period that ran out, a
	 * paid period that ended with no webhook (Membership::status() already
	 * reads that as expired), and anything a lost webhook left behind.
	 *
	 * @param int[]|null $only Check just these landlords (the test suite,
	 *                         which must never sweep real accounts).
	 * @return int Landlords checked.
	 */
	public function run( $only = null ): int {

		if ( is_array( $only ) ) {
			foreach ( array_map( 'intval', $only ) as $user_id ) {
				self::enforce( $user_id );
			}

			return count( $only );
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$authors = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ( %s, %s )", Post_Types::LISTING, Statuses::public_status(), Statuses::BILLING_HOLD ) );
		$due     = (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s", Membership::META_STATUS, Membership::PAST_DUE ) );
		// phpcs:enable

		$users = array_values( array_unique( array_filter( array_map( 'intval', array_merge( $authors, $due ) ) ) ) );

		foreach ( $users as $user_id ) {
			self::enforce( $user_id );
		}

		update_option( 'tdh_enforce_last_run', time(), false );

		return count( $users );
	}

	/**
	 * Visibility's filter: authors whose homes must not be seen.
	 *
	 * The second line of defence. A hold already takes the homes out of
	 * `publish`; this hides any live row a lapsed landlord still has —
	 * one approved by mistake, or restored by hand in wp-admin — until the
	 * daily job puts it right.
	 *
	 * @param int[] $ids From other filters.
	 * @return int[]
	 */
	public function inactive_member_ids( $ids ): array {
		return array_values( array_unique( array_merge( array_map( 'intval', (array) $ids ), self::inactive_ids() ) ) );
	}

	/** @return int[] */
	private static function inactive_ids(): array {

		if ( null !== self::$inactive ) {
			return self::$inactive;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$authors = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s", Post_Types::LISTING, Statuses::public_status() ) ) );

		if ( $authors ) {
			update_meta_cache( 'user', $authors );
		}

		self::$inactive = array_values( array_filter( $authors, static fn( int $id ): bool => self::HOLD === self::state( $id ) ) );

		return self::$inactive;
	}

	/* ---------------------------------------------------------------------
	 * What the landlord is told
	 * ------------------------------------------------------------------ */

	/**
	 * The membership band's words and its one next step, for this state.
	 *
	 * @return array{state:string,text:string,action:string,url:string}|null
	 *         Null when the ordinary per-status copy is right.
	 */
	public static function band( int $user_id ): ?array {

		$status  = Membership::status( $user_id );
		$live    = self::count( $user_id, Statuses::public_status() );
		$held    = self::count( $user_id, Statuses::BILLING_HOLD );
		$billing = add_query_arg( 'view', 'membership', Accounts::url( 'account' ) );

		if ( Membership::PAST_DUE === $status && self::GRACE === self::state( $user_id ) ) {
			$ends = self::grace_ends( $user_id ) ?: time() + Visibility::GRACE_DAYS * DAY_IN_SECONDS;

			$text = $live > 0
				/* translators: 1: number of homes, 2: a date */
				? sprintf( _n( 'Your %1$s home stays visible until %2$s. Update your card and nothing changes.', 'Your %1$s homes stay visible until %2$s. Update your card and nothing changes.', $live, 'thirtydayhomes' ), number_format_i18n( $live ), wp_date( 'j M Y', $ends ) )
				/* translators: %s: a date */
				: sprintf( __( 'Update your card by %s to keep your membership.', 'thirtydayhomes' ), wp_date( 'j M Y', $ends ) );

			return [ 'state' => self::GRACE, 'text' => $text, 'action' => __( 'Update your card', 'thirtydayhomes' ), 'url' => $billing ];
		}

		if ( self::HOLD === self::state( $user_id ) && $held > 0 ) {
			$text = sprintf(
				/* translators: %s: number of homes */
				_n( 'Your %s home is hidden until a plan is active. Nothing is deleted — it comes back the moment payment succeeds.', 'Your %s homes are hidden until a plan is active. Nothing is deleted — they come back the moment payment succeeds.', $held, 'thirtydayhomes' ),
				number_format_i18n( $held )
			);

			// Past due can fix the card; an ended plan has to start again.
			return Membership::PAST_DUE === $status
				? [ 'state' => self::HOLD, 'text' => $text, 'action' => __( 'Update your card', 'thirtydayhomes' ), 'url' => $billing ]
				: [ 'state' => self::HOLD, 'text' => $text, 'action' => __( 'Restart a plan', 'thirtydayhomes' ), 'url' => Accounts::url( 'pricing' ) ];
		}

		if ( Membership::CANCELLED === $status && Membership::expires( $user_id ) > 0 ) {
			return [
				'state'  => self::OK,
				/* translators: %s: a date */
				'text'   => sprintf( __( 'Your membership ends %s. Your homes stay visible until then, and are hidden after — nothing is deleted.', 'thirtydayhomes' ), wp_date( 'j M Y', Membership::expires( $user_id ) ) ),
				'action' => __( 'Manage membership', 'thirtydayhomes' ),
				'url'    => $billing,
			];
		}

		return null;
	}

	/**
	 * "Your homes are back online", once.
	 *
	 * @return string '' when there is nothing to say.
	 */
	public static function take_restored( int $user_id ): string {

		$n = (int) get_transient( self::TRANSIENT_RESTORED . $user_id );

		if ( $n < 1 ) {
			return '';
		}

		delete_transient( self::TRANSIENT_RESTORED . $user_id );

		/* translators: %s: number of homes */
		return sprintf( _n( 'Payment received — your %s home is back online.', 'Payment received — your %s homes are back online.', $n, 'thirtydayhomes' ), number_format_i18n( $n ) );
	}

	/** Homes of one landlord in one status. */
	public static function count( int $user_id, string $status ): int {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d AND post_status = %s", Post_Types::LISTING, $user_id, $status ) );
	}
}
