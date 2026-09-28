<?php
/**
 * What a landlord does to a listing after creating it: pause, resume, delete.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * ─── THE LIFECYCLE, FROM THE DASHBOARD ───────────────────────────────────
 *
 *   live (publish)  ── Pause ──▶  paused (tdh_paused)
 *   paused          ── Resume ─▶  live       no second review: it was approved
 *   any state       ── Delete ─▶  trash      staff can restore it in wp-admin
 *
 * "Edit and resubmit" for a home sent back for changes is not here: it goes
 * through the wizard's own step 4, because resubmitting means passing the
 * same checks and the same Fair Housing confirmation as the first time.
 *
 * ─── RULES EVERY ACTION FOLLOWS ──────────────────────────────────────────
 *
 * - Owner or staff only. Someone else's listing gets exactly the answer a
 *   missing one gets, so ids cannot be probed for who owns what.
 * - Repeating an action is harmless. A double click on Delete, or Back and
 *   resubmit, finds the work already done and reports it done — no error.
 * - Resume refuses when the owner's membership is not live. The row says
 *   so before anyone presses the button; this is the server's own check.
 * - Outcomes travel as a flag and an id, never as text: the words live in
 *   notice(), so a query string cannot be edited into saying something else.
 */
final class Listing_Actions {

	public const NONCE = 'tdh_listing_actions';

	/** Where a finished action may send the landlord back to. */
	private const RETURNS = [ 'overview', 'listings' ];

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'handle' ] );
		add_action( 'tdh_listing_submitted', [ $this, 'notify_staff' ], 10, 2 );
		add_action( 'tdh_listing_changes_submitted', [ $this, 'notify_staff_of_changes' ], 10, 3 );
	}

	public function handle(): void {

		if ( ! isset( $_POST['tdh_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( (string) $_POST['tdh_action'] ) );

		if ( ! in_array( $action, [ 'listing_pause', 'listing_resume', 'listing_delete' ], true ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( Accounts::url( 'login' ) );
			exit;
		}

		$id = (int) ( $_POST['tdh_listing'] ?? 0 );

		if ( ! isset( $_POST['tdh_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tdh_nonce'] ) ), self::NONCE ) ) {
			$this->back( 'expired', $id );
		}

		$listing = self::find( $id );

		if ( ! $listing ) {
			$this->back( 'missing', 0 );
		}

		match ( $action ) {
			'listing_pause'  => $this->pause( $listing ),
			'listing_resume' => $this->resume( $listing ),
			'listing_delete' => $this->delete( $listing ),
		};
	}

	/**
	 * The listing, if the current user may act on it — trashed ones included,
	 * so a repeated Delete finds its own earlier result.
	 */
	public static function find( int $id ): ?\WP_Post {

		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post instanceof \WP_Post || Post_Types::LISTING !== $post->post_type ) {
			return null;
		}

		if ( ! Accounts::is_staff() && (int) $post->post_author !== get_current_user_id() ) {
			return null;
		}

		return $post;
	}

	/**
	 * Must staff wait for this owner to pay before approving (E1)?
	 *
	 * A home with no landlord (staff-made, "Unassigned") has no plan to
	 * lapse: Enforcement never holds it, so approval never waits on one.
	 */
	public static function approval_blocked( int $owner_id ): bool {
		return $owner_id > 0 && ! self::owner_can_publish( $owner_id );
	}

	/**
	 * May this owner's homes be live right now?
	 *
	 * Active, or cancelled but still inside the paid period (Membership
	 * reports a finished period as expired). Staff-owned homes are not
	 * billed, so they are never held back by a membership they do not have.
	 */
	public static function owner_can_publish( int $owner_id ): bool {

		if ( $owner_id < 1 ) {
			return false;
		}

		if ( Accounts::is_staff( $owner_id ) ) {
			return true;
		}

		return in_array( Membership::status( $owner_id ), [ Membership::ACTIVE, Membership::CANCELLED ], true );
	}

	private function pause( \WP_Post $listing ): void {

		if ( Statuses::PAUSED === $listing->post_status ) {
			$this->back( 'paused', $listing->ID );
		}

		if ( Statuses::public_status() !== $listing->post_status ) {
			$this->back( 'not_live', $listing->ID );
		}

		wp_update_post( [ 'ID' => $listing->ID, 'post_status' => Statuses::PAUSED ] );
		update_post_meta( $listing->ID, '_tdh_paused_at', current_time( 'mysql' ) );

		/**
		 * Fires when a live listing is paused.
		 *
		 * @param int $listing_id
		 */
		do_action( 'tdh_listing_paused', $listing->ID );

		$this->back( 'paused', $listing->ID );
	}

	private function resume( \WP_Post $listing ): void {

		if ( Statuses::public_status() === $listing->post_status ) {
			$this->back( 'resumed', $listing->ID );
		}

		if ( Statuses::PAUSED !== $listing->post_status ) {
			$this->back( 'not_paused', $listing->ID );
		}

		if ( ! self::owner_can_publish( (int) $listing->post_author ) ) {
			$this->back( 'billing', $listing->ID );
		}

		/*
		 * Edited while paused in a way a reviewer approved it FOR — a new
		 * title, other photos — it goes back through review rather than
		 * straight to renters. The row said so before the button was pressed.
		 */
		$edited = trim( (string) get_post_meta( $listing->ID, '_tdh_edited_while_paused', true ) );

		if ( '' !== $edited ) {
			wp_update_post( [ 'ID' => $listing->ID, 'post_status' => 'pending' ] );
			update_post_meta( $listing->ID, '_tdh_submitted_at', current_time( 'mysql' ) );
			update_post_meta( $listing->ID, '_tdh_reviewing_edits', $edited );
			delete_post_meta( $listing->ID, '_tdh_edited_while_paused' );
			delete_post_meta( $listing->ID, '_tdh_paused_at' );

			/** This action is documented in class-tdh-listing-form.php. */
			do_action( 'tdh_listing_changes_submitted', $listing->ID, array_map( 'trim', explode( ',', $edited ) ), true );

			$this->back( 'resume_review', $listing->ID );
		}

		wp_update_post( [ 'ID' => $listing->ID, 'post_status' => Statuses::public_status() ] );
		update_post_meta( $listing->ID, '_tdh_live_since', current_time( 'mysql' ) );
		delete_post_meta( $listing->ID, '_tdh_paused_at' );

		/**
		 * Fires when a paused listing goes live again.
		 *
		 * @param int $listing_id
		 */
		do_action( 'tdh_listing_resumed', $listing->ID );

		$this->back( 'resumed', $listing->ID );
	}

	private function delete( \WP_Post $listing ): void {

		if ( 'trash' === $listing->post_status ) {
			$this->back( 'deleted', $listing->ID );
		}

		/**
		 * Fires just before a listing is deleted from the dashboard.
		 *
		 * @param int $listing_id
		 */
		do_action( 'tdh_listing_deleted', $listing->ID );

		// Trash, not a hard delete: staff restore it from Listings → Trash.
		// With the trash switched off (EMPTY_TRASH_DAYS = 0) WordPress deletes
		// outright, and the confirmation panel says it cannot be undone.
		$title = get_the_title( $listing );
		wp_trash_post( $listing->ID );

		$this->back( 'deleted', get_post( $listing->ID ) ? $listing->ID : 0, $title );
	}

	/**
	 * Back to the screen the action came from, with the outcome flagged.
	 */
	private function back( string $flag, int $id, string $title = '' ): void {

		if ( Accounts::is_staff() ) {
			$base = add_query_arg( 'view', 'listings', Accounts::url( 'account' ) );
		} else {
			$return = sanitize_key( wp_unslash( (string) ( $_POST['tdh_return'] ?? 'listings' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- an allow-list, and the redirect is only a destination.
			$base   = 'overview' === $return || ! in_array( $return, self::RETURNS, true )
				? Accounts::url( 'account' )
				: add_query_arg( 'view', 'listings', Accounts::url( 'account' ) );

			$page = (int) ( $_POST['tdh_page'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( $page > 1 && 'listings' === $return ) {
				$base = add_query_arg( 'listings_page', $page, $base );
			}
		}

		$args = [ 'tdh_done' => $flag ];

		if ( $id > 0 ) {
			// NOT tdh_listing: that is the listing post type's own query variable,
			// and ?tdh_listing=123 makes WordPress look for a home page called 123.
			$args['tdh_home'] = $id;
		}

		// A home deleted outright has no record left to read its name from.
		if ( 0 === $id && '' !== $title ) {
			set_transient( 'tdh_deleted_title_' . get_current_user_id(), $title, MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( add_query_arg( $args, $base ) );
		exit;
	}

	/**
	 * The words for an outcome flag, for the current user.
	 *
	 * The listing's name is only used when this user may act on it, so the
	 * id in the address bar cannot be changed to read another landlord's.
	 *
	 * @return array{type:string,text:string}|null type is success, info or error.
	 */
	public static function notice( string $flag, int $id ): ?array {

		$listing = self::find( $id );
		$title   = $listing ? get_the_title( $listing ) : '';

		if ( '' === $title && 'deleted' === $flag ) {
			$title = (string) get_transient( 'tdh_deleted_title_' . get_current_user_id() );
		}

		// “Shadyside Loft” when we know it, "The listing" when we do not.
		$name = '' !== $title
			/* translators: %s: listing title */
			? sprintf( __( '“%s”', 'thirtydayhomes' ), $title )
			: __( 'The listing', 'thirtydayhomes' );

		return match ( $flag ) {
			/* translators: %s: listing name */
			'paused'     => [ 'type' => 'success', 'text' => sprintf( __( '%s is paused. Renters can’t see it until you resume it.', 'thirtydayhomes' ), $name ) ],
			/* translators: %s: listing name */
			'resumed'    => [ 'type' => 'success', 'text' => sprintf( __( '%s is live again. Renters can find it now.', 'thirtydayhomes' ), $name ) ],
			/* translators: %s: listing name */
			'deleted'    => [ 'type' => 'success', 'text' => sprintf( __( '%s is deleted.', 'thirtydayhomes' ), $name ) ],
			/* translators: %s: listing name */
			'in_review'     => [ 'type' => 'success', 'text' => sprintf( __( 'Your changes to %s are saved and a person is checking them — usually within one business day. It goes back on the site as soon as they’re approved.', 'thirtydayhomes' ), $name ) ],
			/* translators: %s: listing name */
			'resume_review' => [ 'type' => 'info', 'text' => sprintf( __( '%s isn’t live yet. You changed it while it was paused, so a person checks the changes first — usually within one business day.', 'thirtydayhomes' ), $name ) ],
			'availability'  => [ 'type' => 'success', 'text' => self::availability_text( $listing, $name ) ],
			'not_live'   => [ 'type' => 'info', 'text' => self::not_live_text( $listing, $name ) ],
			/* translators: %s: listing name */
			'not_paused' => [ 'type' => 'info', 'text' => sprintf( __( '%s isn’t paused, so there is nothing to resume.', 'thirtydayhomes' ), $name ) ],
			/* translators: %s: listing name */
			'billing'    => [ 'type' => 'error', 'text' => sprintf( __( 'Your membership isn’t active, so %s can’t go live. Update your billing on the Membership screen, then resume it.', 'thirtydayhomes' ), $name ) ],
			'missing'    => [ 'type' => 'error', 'text' => __( 'That listing isn’t available. It may already have been deleted.', 'thirtydayhomes' ) ],
			'expired'    => [ 'type' => 'error', 'text' => __( 'That page was open too long, so nothing was changed. Please try again.', 'thirtydayhomes' ) ],
			default      => null,
		};
	}

	/**
	 * "Availability for “Shadyside Loft” is saved. Renters see: Available now."
	 * Plus anything the save changed on its own, such as two periods joined.
	 */
	private static function availability_text( ?\WP_Post $listing, string $name ): string {

		if ( ! $listing ) {
			return __( 'Availability is saved.', 'thirtydayhomes' );
		}

		$summary = Availability::summary( (int) $listing->ID );

		$text = Statuses::public_status() === $listing->post_status
			/* translators: 1: listing name, 2: what renters see, e.g. "Available from 11 Nov 2026" */
			? sprintf( __( 'Availability for %1$s is saved. Renters see: %2$s.', 'thirtydayhomes' ), $name, $summary['headline'] )
			/* translators: %s: listing name */
			: sprintf( __( 'Availability for %s is saved. Renters see it once the home is live.', 'thirtydayhomes' ), $name );

		foreach ( Availability::take_notes( (int) $listing->ID ) as $note ) {
			$text .= ' ' . $note;
		}

		return $text;
	}

	private static function not_live_text( ?\WP_Post $listing, string $name ): string {

		if ( $listing && 'pending' === $listing->post_status ) {
			/* translators: %s: listing name */
			return sprintf( __( '%s is still in review, so it can’t be paused — renters can’t see it yet anyway.', 'thirtydayhomes' ), $name );
		}

		/* translators: %s: listing name */
		return sprintf( __( 'Only a live home can be paused, and %s isn’t live.', 'thirtydayhomes' ), $name );
	}

	/**
	 * Tell staff a home is waiting for review.
	 *
	 * @param int  $listing_id
	 * @param bool $resubmitted True when it comes back after changes were requested.
	 */
	public function notify_staff( int $listing_id, bool $resubmitted = false ): bool {

		$listing = get_post( $listing_id );
		$to      = (string) get_option( 'admin_email' );

		if ( ! $listing instanceof \WP_Post || ! is_email( $to ) ) {
			return false;
		}

		$owner = get_userdata( (int) $listing->post_author );
		$who   = $owner ? $owner->display_name : __( 'A landlord', 'thirtydayhomes' );
		$title = get_the_title( $listing );

		$subject = $resubmitted
			/* translators: 1: site name, 2: listing title */
			? sprintf( __( '[%1$s] Resubmitted for review: %2$s', 'thirtydayhomes' ), get_bloginfo( 'name' ), $title )
			/* translators: 1: site name, 2: listing title */
			: sprintf( __( '[%1$s] New home waiting for review: %2$s', 'thirtydayhomes' ), get_bloginfo( 'name' ), $title );

		$body = implode(
			"\n",
			[
				$resubmitted
					/* translators: 1: landlord name, 2: listing title */
					? sprintf( __( '%1$s made the changes you asked for and resubmitted “%2$s”.', 'thirtydayhomes' ), $who, $title )
					/* translators: 1: landlord name, 2: listing title */
					: sprintf( __( '%1$s submitted “%2$s” for review.', 'thirtydayhomes' ), $who, $title ),
				'',
				/* translators: %s: URL */
				sprintf( __( 'See it as renters will: %s', 'thirtydayhomes' ), (string) get_preview_post_link( $listing ) ),
				/* translators: %s: URL */
				sprintf( __( 'Approve or request changes: %s', 'thirtydayhomes' ), add_query_arg( 'view', 'listings', Accounts::url( 'account' ) ) ),
			]
		);

		return (bool) wp_mail( $to, $subject, $body );
	}

	/**
	 * Tell staff an approved home has been edited and is waiting for review.
	 *
	 * @param int      $listing_id
	 * @param string[] $changed    Labels of the fields that changed.
	 * @param bool     $was_paused True when it comes from Resume on a paused home.
	 */
	public function notify_staff_of_changes( int $listing_id, array $changed = [], bool $was_paused = false ): bool {

		$listing = get_post( $listing_id );
		$to      = (string) get_option( 'admin_email' );

		if ( ! $listing instanceof \WP_Post || ! is_email( $to ) ) {
			return false;
		}

		$owner = get_userdata( (int) $listing->post_author );
		$who   = $owner ? $owner->display_name : __( 'A landlord', 'thirtydayhomes' );
		$title = get_the_title( $listing );
		$what  = Listing_Form::changes_phrase( array_map( 'strval', $changed ) );
		$what  = '' !== $what ? $what : __( 'details', 'thirtydayhomes' );

		$body = implode(
			"\n",
			[
				$was_paused
					/* translators: 1: landlord name, 2: what changed e.g. "the title and photos", 3: listing title */
					? sprintf( __( '%1$s changed the %2$s of “%3$s” while it was paused, and now wants it live again. It stays off the site until you approve the changes.', 'thirtydayhomes' ), $who, $what, $title )
					/* translators: 1: landlord name, 2: what changed, 3: listing title */
					: sprintf( __( '%1$s changed the %2$s of “%3$s”, which was live. It is off the site until you approve the changes.', 'thirtydayhomes' ), $who, $what, $title ),
				'',
				/* translators: %s: URL */
				sprintf( __( 'See it as renters will: %s', 'thirtydayhomes' ), (string) get_preview_post_link( $listing ) ),
				/* translators: %s: URL */
				sprintf( __( 'Approve or request changes: %s', 'thirtydayhomes' ), add_query_arg( 'view', 'listings', Accounts::url( 'account' ) ) ),
			]
		);

		return (bool) wp_mail(
			$to,
			/* translators: 1: site name, 2: listing title */
			sprintf( __( '[%1$s] Edited home waiting for review: %2$s', 'thirtydayhomes' ), get_bloginfo( 'name' ), $title ),
			$body
		);
	}
}
