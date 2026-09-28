<?php
/**
 * Listing moderation from the marketplace portal.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Approve a submitted listing, or send it back for changes — the two
 * decisions the owner makes every day, actionable from the styled portal
 * so the client never needs wp-admin for the routine loop.
 *
 * Only these two transitions live here. Pause, resume and delete are the
 * landlord's own (TDH\Listing_Actions); billing holds belong to the
 * membership layer. "Request changes" always carries a reason, because the
 * landlord's dashboard shows it word for word as the thing to fix.
 */
final class Moderation {

	public const NONCE = 'tdh_moderation';

	/** Longest "what to change" message staff can send. */
	public const MAX_REASON = 1000;

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'handle' ] );
	}

	public function handle(): void {

		if ( ! isset( $_POST['tdh_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( (string) $_POST['tdh_action'] ) );

		if ( ! in_array( $action, [ 'listing_approve', 'listing_changes' ], true ) ) {
			return;
		}

		// Capability before nonce: a nonce says "this form came from us",
		// not "this person may moderate". The staff test is the marketplace
		// capability, so the client-review administrator can moderate too.
		if ( ! Accounts::is_staff() ) {
			return;
		}

		if ( ! isset( $_POST['tdh_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tdh_nonce'] ) ), self::NONCE ) ) {
			$this->back( 'expired' );
		}

		$listing = get_post( (int) ( $_POST['tdh_listing'] ?? 0 ) );

		if ( ! $listing || Post_Types::LISTING !== $listing->post_type ) {
			$this->back( 'missing' );
		}

		/*
		 * Only a home that is waiting can be decided on. A queue page can be
		 * open for a while: another staff member may have approved the home
		 * since, or the landlord deleted it. A stale Approve would then
		 * publish a trashed post — the home comes back from the bin, live —
		 * or re-stamp "Live since" on one already live, and a stale Request
		 * changes would take a live home down.
		 */
		if ( 'pending' !== $listing->post_status ) {
			$this->back( 'not_pending', [ 'tdh_home' => $listing->ID ] );
		}

		if ( 'listing_approve' === $action ) {

			/*
			 * An address Google could not place would go live with no
			 * distances to any hospital — the one thing renters come for.
			 * The button already says so; this is the server's own check.
			 * ("Not checked yet" never blocks: that is the site waiting for
			 * its maps key, not a problem with the home.)
			 */
			/*
			 * "Not checked yet" with a maps key installed means the home was
			 * saved before the key existed. Look it up now, so a home never
			 * goes live without a location while the service is there to ask
			 * (the promise in the client report). With no key it still does
			 * not block — that is the site waiting, not the home's fault.
			 */
			if ( 'pending' === Geocoder::state( (int) $listing->ID ) && null !== Geocoder::provider() ) {
				Geocoder::geocode( (int) $listing->ID, 'retry' );
			}

			if ( Geocoder::blocks_approval( (int) $listing->ID ) ) {
				$this->back( 'location', [ 'locate' => $listing->ID, 'tdh_home' => $listing->ID ], Geocoder::anchor( (int) $listing->ID ) );
			}

			/*
			 * A home whose landlord is not paid up would go live only for the
			 * daily check to hide it again (E1). The button already says so;
			 * this is the server's own check. Request changes stays open.
			 */
			if ( Listing_Actions::approval_blocked( (int) $listing->post_author ) ) {
				$this->back( 'owner_inactive', [ 'tdh_home' => $listing->ID ], Geocoder::anchor( (int) $listing->ID ) );
			}

			wp_update_post(
				[
					'ID'          => $listing->ID,
					'post_status' => 'publish',
				]
			);

			// When, and by whom — the landlord's row reads "Live since" from
			// this. An old "what to change" note has done its job.
			update_post_meta( $listing->ID, '_tdh_approved_at', current_time( 'mysql' ) );
			update_post_meta( $listing->ID, '_tdh_approved_by', get_current_user_id() );
			update_post_meta( $listing->ID, '_tdh_live_since', current_time( 'mysql' ) );
			delete_post_meta( $listing->ID, '_tdh_rejection_reason' );
			// Approved as it is now: any edit that put it here is settled.
			delete_post_meta( $listing->ID, '_tdh_reviewing_edits' );
			delete_post_meta( $listing->ID, '_tdh_edited_while_paused' );

			/**
			 * Fires when staff approve a listing.
			 *
			 * The seam for the "your home is live" email to the landlord.
			 *
			 * @param int $listing_id
			 */
			do_action( 'tdh_listing_approved', $listing->ID );

			$this->back( 'approved' );
		}

		/*
		 * A reason is required. "Changes requested" with nothing to change is
		 * a dead end for the landlord: they cannot fix what nobody named, and
		 * the home sits there. Refused, the form opens again where it was.
		 */
		$reason = trim( sanitize_textarea_field( wp_unslash( (string) ( $_POST['tdh_reason'] ?? '' ) ) ) );
		$reason = mb_substr( $reason, 0, self::MAX_REASON );

		if ( '' === $reason ) {
			$this->back( 'reason', [ 'request_changes' => $listing->ID ] );
		}

		update_post_meta( $listing->ID, '_tdh_rejection_reason', $reason );
		// Sent back: when it returns it is a resubmission, not the old edit.
		delete_post_meta( $listing->ID, '_tdh_reviewing_edits' );

		wp_update_post(
			[
				'ID'          => $listing->ID,
				'post_status' => Statuses::REJECTED,
			]
		);

		/**
		 * Fires when staff send a listing back for changes.
		 *
		 * @param int $listing_id
		 */
		do_action( 'tdh_listing_changes_requested', $listing->ID );

		$this->back( 'changes' );
	}

	/**
	 * Return to the portal's listings view with the outcome flagged.
	 *
	 * A flag, not a message: the copy lives in the renderer, so the query
	 * string cannot be edited into saying something else.
	 */
	private function back( string $flag, array $extra = [], string $anchor = '' ): void {

		$url = add_query_arg(
			array_merge(
				[
					'view'          => 'listings',
					'tdh_moderated' => $flag,
				],
				$extra
			),
			Accounts::url( 'account' )
		);

		wp_safe_redirect( '' !== $anchor ? $url . '#' . $anchor : $url );
		exit;
	}
}
