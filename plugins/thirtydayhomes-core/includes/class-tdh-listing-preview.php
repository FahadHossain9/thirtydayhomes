<?php
/**
 * Previewing a listing that is not public yet.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * The bar across the top of a listing page that only its landlord or staff
 * can see — a pending, draft, paused or hidden home.
 *
 * ─── WHY IT EXISTS ─────────────────────────────────────────────────────────
 *
 * Staff open a waiting home from the approval queue to decide on it, and a
 * landlord opens their own to check it. Both used to land on a bare "Page
 * not found". With preview allowed (Visibility::permits_preview), the page
 * renders exactly as renters will see it — which is the point — so the page
 * alone cannot tell anyone that it is NOT public yet. This bar says so, says
 * what happens next, and puts the next action one click away: Approve for
 * staff, Edit for the landlord.
 *
 * Logic here, markup here, look in the theme (style.css "LISTING PREVIEW
 * BAR"). The theme only fires `tdh_listing_preview_bar` where it belongs.
 */
final class Listing_Preview {

	public function register(): void {
		add_action( 'tdh_listing_preview_bar', [ $this, 'render_bar' ] );

		// A preview is never for search engines, even if a link to one leaks.
		add_filter( 'wp_robots', [ $this, 'noindex' ] );
	}

	/**
	 * @param array<string,bool|string> $robots
	 * @return array<string,bool|string>
	 */
	public function noindex( array $robots ): array {

		if ( is_preview() && is_singular( Post_Types::LISTING ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}

		return $robots;
	}

	/**
	 * Print the bar, or nothing.
	 *
	 * Nothing for a live listing (that page IS what renters see), and
	 * nothing for anyone who could not edit the listing — though such a
	 * person never reaches a non-public listing page in the first place.
	 */
	public function render_bar( int $listing_id ): void {
		echo self::bar( $listing_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}

	/**
	 * The bar's markup, for the current user.
	 */
	public static function bar( int $listing_id ): string {

		$post = get_post( $listing_id );

		if ( ! $post instanceof \WP_Post || Post_Types::LISTING !== $post->post_type ) {
			return '';
		}

		if ( Statuses::public_status() === $post->post_status || ! is_user_logged_in() || ! current_user_can( 'edit_post', $listing_id ) ) {
			return '';
		}

		$staff = Accounts::is_staff();
		$copy  = self::copy( $post, $staff );

		$listings_url = add_query_arg( 'view', 'listings', Accounts::url( 'account' ) );

		ob_start();
		?>
		<section class="preview-bar" aria-labelledby="tdh-preview-title">
			<div class="preview-bar-inner">

				<span class="preview-bar-icon" aria-hidden="true">
					<?php echo self::icon( 'eye', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</span>

				<div class="preview-bar-copy">
					<p class="preview-bar-eyebrow">
						<?php esc_html_e( 'Preview', 'thirtydayhomes' ); ?>
						<span class="status <?php echo esc_attr( $copy['badge'] ); ?>"><?php echo esc_html( $copy['state'] ); ?></span>
					</p>
					<h2 class="preview-bar-title" id="tdh-preview-title"><?php echo esc_html( $copy['title'] ); ?></h2>
					<p class="preview-bar-text"><?php echo esc_html( $copy['text'] ); ?></p>
				</div>

				<div class="preview-bar-actions">
					<?php if ( $staff && 'pending' === $post->post_status && Geocoder::blocks_approval( $listing_id ) ) : ?>
						<?php
						// Its address was not found: approval waits for a location,
						// here as in the queue. The one next step is setting it.
						?>
						<p class="preview-bar-hold" id="tdh-preview-hold"><?php esc_html_e( 'Can’t be approved yet: Google couldn’t find this address.', 'thirtydayhomes' ); ?></p>
						<a class="primary" href="<?php echo esc_url( Account_Render::locate_url( $listing_id ) ); ?>" aria-describedby="tdh-preview-hold"><?php esc_html_e( 'Set location', 'thirtydayhomes' ); ?></a>
						<a class="secondary" href="<?php echo esc_url( Account_Render::request_changes_url( $listing_id ) ); ?>"><?php esc_html_e( 'Request changes', 'thirtydayhomes' ); ?></a>
					<?php elseif ( $staff && 'pending' === $post->post_status && Listing_Actions::approval_blocked( (int) $post->post_author ) ) : ?>
						<?php // Its landlord is not paid up (E1): same rule, same words as the queue. ?>
						<p class="preview-bar-hold" id="tdh-preview-hold"><?php esc_html_e( 'Can’t be approved yet: the landlord’s membership isn’t active.', 'thirtydayhomes' ); ?></p>
						<a class="secondary" href="<?php echo esc_url( Account_Render::request_changes_url( $listing_id ) ); ?>"><?php esc_html_e( 'Request changes', 'thirtydayhomes' ); ?></a>
					<?php elseif ( $staff && 'pending' === $post->post_status ) : ?>
						<?php
						/*
						 * The same two decisions as the approval queue, posting to
						 * the same handler, so the result lands on the listings
						 * screen with the same confirmation.
						 */
						?>
						<form method="post" action="<?php echo esc_url( $listings_url ); ?>">
							<input type="hidden" name="tdh_action" value="listing_approve">
							<input type="hidden" name="tdh_listing" value="<?php echo esc_attr( (string) $listing_id ); ?>">
							<?php wp_nonce_field( Moderation::NONCE, 'tdh_nonce' ); ?>
							<button class="primary" type="submit">
								<?php echo self::icon( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php esc_html_e( 'Approve', 'thirtydayhomes' ); ?>
							</button>
						</form>
						<?php // A request always carries a note: it opens the note form under this home in the queue. ?>
						<a class="secondary" href="<?php echo esc_url( Account_Render::request_changes_url( $listing_id ) ); ?>"><?php esc_html_e( 'Request changes', 'thirtydayhomes' ); ?></a>
					<?php elseif ( ! $staff && Statuses::PAUSED === $post->post_status && Listing_Actions::owner_can_publish( (int) $post->post_author ) ) : ?>
						<?php Listing_Manage_Render::post_form( 'listing_resume', __( 'Resume', 'thirtydayhomes' ), 'primary', $listing_id, 'listings', 1, '' ); ?>
					<?php elseif ( ! $staff && in_array( $post->post_status, [ Statuses::PAUSED, Statuses::BILLING_HOLD ], true ) ) : ?>
						<a class="primary" href="<?php echo esc_url( add_query_arg( 'view', 'membership', Accounts::url( 'account' ) ) ); ?>"><?php esc_html_e( 'Manage billing', 'thirtydayhomes' ); ?></a>
					<?php elseif ( $copy['edit'] ) : ?>
						<a class="<?php echo esc_attr( $staff ? 'secondary' : 'primary' ); ?>" href="<?php echo esc_url( Listing_Form::url( 4, $listing_id ) ); ?>">
							<?php echo esc_html( $copy['edit'] ); ?>
						</a>
					<?php endif; ?>

					<a class="preview-bar-link" href="<?php echo esc_url( $staff ? $listings_url : Accounts::url( 'account' ) ); ?>">
						<?php echo $staff ? esc_html__( 'All listings', 'thirtydayhomes' ) : esc_html__( 'Dashboard', 'thirtydayhomes' ); ?>
					</a>
				</div>

			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * What the bar says, by status and by who is looking.
	 *
	 * @return array{state:string,badge:string,title:string,text:string,edit:string}
	 */
	private static function copy( \WP_Post $post, bool $staff ): array {

		$reason = trim( (string) get_post_meta( $post->ID, '_tdh_rejection_reason', true ) );

		return match ( $post->post_status ) {

			'pending' => $staff
				? [
					'state' => __( 'Waiting for approval', 'thirtydayhomes' ),
					'badge' => 'pending',
					'title' => __( 'This home is waiting for your decision.', 'thirtydayhomes' ),
					'text'  => __( 'Only staff and the landlord can see this page. Renters will see it exactly like this once you approve it.', 'thirtydayhomes' ),
					'edit'  => '',
				]
				: [
					'state' => __( 'In review', 'thirtydayhomes' ),
					'badge' => 'pending',
					'title' => __( 'Only you can see this page for now.', 'thirtydayhomes' ),
					'text'  => '' !== trim( (string) get_post_meta( $post->ID, '_tdh_reviewing_edits', true ) )
						? __( 'Your changes are being checked, usually within one business day. The home is off the site until they’re approved, then it comes straight back — exactly like this.', 'thirtydayhomes' )
						: __( 'A person reviews every home, usually within one business day. Renters will see it exactly like this once it is approved.', 'thirtydayhomes' ),
					'edit'  => __( 'Edit listing', 'thirtydayhomes' ),
				],

			'draft' => [
				'state' => __( 'Draft', 'thirtydayhomes' ),
				'badge' => 'inactive',
				'title' => $staff
					? __( 'The landlord has not submitted this home yet.', 'thirtydayhomes' )
					: __( 'This draft is only visible to you.', 'thirtydayhomes' ),
				'text'  => $staff
					? __( 'It reaches the approval queue when they submit it.', 'thirtydayhomes' )
					: __( 'Finish the listing and submit it for review when you are ready.', 'thirtydayhomes' ),
				'edit'  => $staff ? __( 'Open in the listing form', 'thirtydayhomes' ) : __( 'Continue editing', 'thirtydayhomes' ),
			],

			Statuses::REJECTED => [
				'state' => __( 'Changes requested', 'thirtydayhomes' ),
				'badge' => 'rejected',
				'title' => $staff
					? __( 'This home was sent back to the landlord.', 'thirtydayhomes' )
					: __( 'Changes were requested before this home can go live.', 'thirtydayhomes' ),
				'text'  => '' !== $reason
					/* translators: %s: what staff asked the landlord to change */
					? sprintf( __( 'What to change: %s', 'thirtydayhomes' ), $reason )
					: ( $staff
						? __( 'It returns to the approval queue when the landlord resubmits it.', 'thirtydayhomes' )
						: __( 'Our team will be in touch about what to change.', 'thirtydayhomes' ) ),
				'edit'  => $staff ? __( 'Open in the listing form', 'thirtydayhomes' ) : __( 'Edit and resubmit', 'thirtydayhomes' ),
			],

			Statuses::PAUSED => [
				'state' => __( 'Paused', 'thirtydayhomes' ),
				'badge' => 'inactive',
				'title' => __( 'This home is paused and hidden from renters.', 'thirtydayhomes' ),
				'text'  => $staff
					? __( 'The landlord took it down. It stays hidden until they resume it.', 'thirtydayhomes' )
					: ( Listing_Actions::owner_can_publish( (int) $post->post_author )
						? __( 'It stays hidden until you resume it. Resuming puts it straight back — no second review.', 'thirtydayhomes' )
						: __( 'Your membership isn’t active, so it can’t go live again yet. Update your billing first.', 'thirtydayhomes' ) ),
				'edit'  => $staff ? __( 'Open in the listing form', 'thirtydayhomes' ) : '',
			],

			Statuses::BILLING_HOLD => [
				'state' => __( 'Hidden — membership', 'thirtydayhomes' ),
				'badge' => 'past_due',
				'title' => __( 'This home is hidden because the membership lapsed.', 'thirtydayhomes' ),
				'text'  => $staff
					? __( 'It comes back automatically when the landlord renews.', 'thirtydayhomes' )
					: __( 'Renew your membership and it comes back on its own — nothing needs re-entering.', 'thirtydayhomes' ),
				'edit'  => '',
			],

			default => [
				'state' => __( 'Not public', 'thirtydayhomes' ),
				'badge' => 'inactive',
				'title' => __( 'Renters cannot see this home right now.', 'thirtydayhomes' ),
				'text'  => __( 'This is a preview of how the page will look.', 'thirtydayhomes' ),
				'edit'  => '',
			],
		};
	}

	private static function icon( string $name, int $size ): string {
		return function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size ) : '';
	}
}
