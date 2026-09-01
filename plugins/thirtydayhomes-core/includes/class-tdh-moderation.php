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
 * Only these two transitions live here. Pausing, billing holds and edits
 * stay in wp-admin, which the portal links to for exactly that reason: the
 * portal is the client's desk, wp-admin is the workshop.
 */
final class Moderation {

	public const NONCE = 'tdh_moderation';

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

		if ( 'listing_approve' === $action ) {

			wp_update_post(
				[
					'ID'          => $listing->ID,
					'post_status' => 'publish',
				]
			);

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
	private function back( string $flag ): void {

		wp_safe_redirect(
			add_query_arg(
				[
					'view'          => 'listings',
					'tdh_moderated' => $flag,
				],
				Accounts::url( 'account' )
			)
		);
		exit;
	}
}
