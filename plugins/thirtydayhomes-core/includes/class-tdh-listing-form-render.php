<?php
/**
 * The create-a-listing wizard's markup.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Renders [tdh_add_listing].
 *
 * Field names and the nonce match TDH\Listing_Form exactly — the same
 * pairing rule every form in this plugin follows, because markup that can
 * drift from its handler is a form that silently stops working.
 *
 * The controls are honest without JavaScript: the minimum stay is a radio
 * group and the amenities are checkboxes, both styled as the prototype's
 * chips. The prototype uses buttons and script; a button is nothing when
 * the script fails, where a checkbox is still a checkbox.
 */
final class Listing_Form_Render {

	public static function form(): string {

		$gate = Listing_Form::gate_reason();

		if ( '' !== $gate && 'full' !== $gate ) {
			return self::gate( $gate );
		}

		$listing = Listing_Form::current_listing();

		if ( 'full' === $gate && ! $listing ) {
			return self::gate( 'full' );
		}

		$step = isset( $_GET['step'] ) ? max( 1, min( 4, (int) $_GET['step'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Steps past the first need a draft to talk about. A bare URL with
		// step=4 starts at the beginning rather than reviewing nothing.
		if ( $step > 1 && ! $listing ) {
			$step = 1;
		}

		/*
		 * A home that is already out there — live, or paused by its owner —
		 * is EDITED, not created: the wording, the last step and the save
		 * buttons all say so. See Listing_Form::guard_live_edit() for what
		 * happens to the edits. Staff keep the ordinary screens: their edits
		 * never send a home to review.
		 */
		$status        = $listing ? (string) $listing->post_status : 'draft';
		$settled       = in_array( $status, [ 'publish', Statuses::PAUSED ], true );
		$owner_settled = $settled && ! Accounts::is_staff();
		self::$listing = $listing;

		// The step held for confirmation, if this is that page.
		$held      = null;
		$expired   = false;
		$in_review = false;

		if ( isset( $_GET['confirm'] ) && $listing ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$held = Listing_Form::held_confirmation();

			if ( ! $held || (int) $held['listing'] !== (int) $listing->ID ) {
				$held = null;

				// Back to this page after confirming: the change is in, say so.
				if ( 'pending' === $status && '' !== (string) get_post_meta( $listing->ID, '_tdh_reviewing_edits', true ) ) {
					$in_review = true;
				} else {
					$expired = true;
				}
			}
		} else {
			// Any other visit to a step is "go back and discard changes".
			Listing_Form::discard_confirmation();
		}

		$titles = [
			1 => __( 'The basics', 'thirtydayhomes' ),
			2 => __( 'Features & amenities', 'thirtydayhomes' ),
			3 => __( 'Photos & description', 'thirtydayhomes' ),
			4 => $owner_settled ? __( 'Check your listing', 'thirtydayhomes' ) : __( 'Review & submit', 'thirtydayhomes' ),
		];

		$back = 1 === $step
			? Accounts::url( 'account' )
			: Listing_Form::url( $step - 1, $listing ? $listing->ID : 0 );

		// On the confirmation page, back means the step, unsaved.
		$held_back = $held && $listing ? self::held_back_url( $listing, $held ) : '';

		$errors = Listing_Form::take_errors();
		$saved  = isset( $_GET['saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// How many photographs the previous step just stored. A number, not
		// a message, so the wording lives here and the URL cannot be edited
		// into claiming something that did not happen.
		$added = isset( $_GET['added'] ) ? max( 0, (int) $_GET['added'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();
		?>
		<div class="lform">

			<div class="lform-head">
				<?php if ( $held ) : ?>
					<a class="lform-back" href="<?php echo esc_url( $held_back ); ?>" aria-label="<?php esc_attr_e( 'Back', 'thirtydayhomes' ); ?>">
						<?php echo self::icon( 'arrow-left' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</a>
				<?php elseif ( in_array( $step, [ 2, 3 ], true ) ) : ?>
					<?php
					// On steps with answers to lose, the arrow is the step's own
					// Back button (it submits the form below and saves first).
					?>
					<button class="lform-back" type="submit" form="lform-step" name="tdh_go" value="back" formnovalidate
						aria-label="<?php esc_attr_e( 'Save and go back', 'thirtydayhomes' ); ?>">
						<?php echo self::icon( 'arrow-left' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</button>
				<?php else : ?>
					<a class="lform-back" href="<?php echo esc_url( $back ); ?>" aria-label="<?php esc_attr_e( 'Back', 'thirtydayhomes' ); ?>">
						<?php echo self::icon( 'arrow-left' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</a>
				<?php endif; ?>
				<div>
					<p><?php echo $settled ? esc_html__( 'Edit listing', 'thirtydayhomes' ) : esc_html__( 'Create a listing', 'thirtydayhomes' ); ?></p>
					<h1><?php echo $held ? esc_html__( 'Before this is saved', 'thirtydayhomes' ) : esc_html( $titles[ $step ] ); ?></h1>
				</div>
				<span>
					<?php
					printf(
						/* translators: 1: current step, 2: total steps */
						esc_html__( 'Step %1$d of %2$d', 'thirtydayhomes' ),
						(int) $step,
						4
					);
					?>
				</span>
			</div>

			<div class="lform-progress" role="progressbar" aria-valuemin="0" aria-valuemax="4" aria-valuenow="<?php echo esc_attr( (string) $step ); ?>">
				<i style="width: <?php echo esc_attr( (string) round( $step / 4 * 100 ) ); ?>%"></i>
			</div>

			<?php
			/*
			 * A home sent back for changes carries the note on every step, so
			 * the landlord never has to go back to the dashboard to re-read
			 * what they are meant to be fixing.
			 */
			$tdh_reason = $listing && Statuses::REJECTED === $listing->post_status
				? trim( (string) get_post_meta( $listing->ID, '_tdh_rejection_reason', true ) )
				: '';
			?>
			<?php if ( '' !== $tdh_reason ) : ?>
				<div class="form-notice form-notice--info lform-notice lform-changes">
					<?php echo self::icon( 'circle-alert', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<div>
						<p><b><?php esc_html_e( 'Changes requested', 'thirtydayhomes' ); ?></b></p>
						<p><?php echo nl2br( esc_html( $tdh_reason ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<p><?php esc_html_e( 'Make the changes, then resubmit on the last step.', 'thirtydayhomes' ); ?></p>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $errors ) : ?>
				<div class="form-notice form-notice--error lform-notice" role="alert">
					<?php echo self::icon( 'shield-check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<div>
						<?php foreach ( $errors as $error ) : ?>
							<p><?php echo esc_html( $error ); ?></p>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $saved && ! $settled ) : ?>
				<div class="form-notice form-notice--ok lform-notice" role="status">
					<?php echo self::icon( 'check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<p><?php esc_html_e( 'Draft saved. It is on your dashboard whenever you want to continue.', 'thirtydayhomes' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $settled && ( $saved || isset( $_GET['updated'] ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<?php
				// A live home's small change is already out there; a paused
				// home's change waits, and says whether Resume will review it.
				$tdh_paused_edits = $listing ? trim( (string) get_post_meta( $listing->ID, '_tdh_edited_while_paused', true ) ) : '';
				?>
				<div class="form-notice form-notice--ok lform-notice" role="status">
					<?php echo self::icon( 'check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<p>
						<?php
						if ( 'publish' === $status ) {
							esc_html_e( 'Saved. Anything you changed here is live now.', 'thirtydayhomes' );
						} elseif ( '' !== $tdh_paused_edits ) {
							printf(
								/* translators: %s: what changed, e.g. "the title and bedrooms" */
								esc_html__( 'Saved. This home stays paused. Because you changed the %s, Resume will send it for a quick review before it goes live again.', 'thirtydayhomes' ),
								esc_html( Listing_Form::changes_phrase( $tdh_paused_edits ) )
							);
						} else {
							esc_html_e( 'Saved. This home stays paused until you resume it from My listings.', 'thirtydayhomes' );
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $expired ) : ?>
				<div class="form-notice form-notice--info lform-notice" role="status">
					<?php echo self::icon( 'circle-alert', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<p><?php esc_html_e( 'That confirmation page has expired, so nothing was changed. Make the change again if you still want it.', 'thirtydayhomes' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $in_review ) : ?>
				<div class="form-notice form-notice--ok lform-notice" role="status">
					<?php echo self::icon( 'check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<p><?php esc_html_e( 'Those changes are already saved and the home is in review — nothing more to do here.', 'thirtydayhomes' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $owner_settled && $step < 4 && ! $held ) : ?>
				<?php
				/*
				 * Said up front, on every editing step, so no landlord meets
				 * the confirmation page as a surprise: which changes show at
				 * once and which take the home back through review.
				 */
				$tdh_material = Listing_Form::changes_phrase( array_values( Listing_Form::material_fields() ) );
				?>
				<div class="form-notice form-notice--info lform-notice lform-live-note">
					<?php echo self::icon( 'eye', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<div>
						<?php if ( 'publish' === $status ) : ?>
							<p><b><?php esc_html_e( 'This home is live.', 'thirtydayhomes' ); ?></b></p>
							<p><?php esc_html_e( 'Rent, fees, availability, amenities, utilities, pets and contact details update straight away.', 'thirtydayhomes' ); ?></p>
							<p>
								<?php
								printf(
									/* translators: %s: list of fields, e.g. "the title, description and photos" */
									esc_html__( 'Changes to the %s go back to review first, and the home comes off the site until they’re approved — you’ll be asked before that happens.', 'thirtydayhomes' ),
									esc_html( $tdh_material )
								);
								?>
							</p>
						<?php else : ?>
							<p><b><?php esc_html_e( 'This home is paused.', 'thirtydayhomes' ); ?></b></p>
							<p>
								<?php
								printf(
									/* translators: %s: list of fields */
									esc_html__( 'Changes save straight away. If you change the %s, Resume sends the home for a quick review before it goes live again.', 'thirtydayhomes' ),
									esc_html( $tdh_material )
								);
								?>
							</p>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $added > 0 ) : ?>
				<div class="form-notice form-notice--ok lform-notice" role="status">
					<?php echo self::icon( 'check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<p>
						<?php
						printf(
							/* translators: %s: number of photos added */
							esc_html( _n( '%s photo added to this listing.', '%s photos added to this listing.', $added, 'thirtydayhomes' ) ),
							esc_html( number_format_i18n( $added ) )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['arranged'] ) && 3 === $step ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="form-notice form-notice--ok lform-notice" role="status">
					<?php echo self::icon( 'check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<p><?php esc_html_e( 'Photo order saved. The first photo is the cover.', 'thirtydayhomes' ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			// Unavailable periods joined on the landlord's behalf — said, not
			// done silently, on whichever page the save led to.
			$tdh_avail_notes = $listing && ! $held ? Availability::take_notes( (int) $listing->ID ) : [];
			?>
			<?php if ( $listing && ! $held && 'failed' === Geocoder::state( (int) $listing->ID ) ) : ?>
				<?php
				/*
				 * Google could not place the address. Said to the landlord,
				 * who can fix a typo in seconds, not only to staff — and
				 * without alarm: the team can also place it by hand.
				 */
				?>
				<div class="form-notice form-notice--info lform-notice lform-location" role="status">
					<?php echo self::icon( 'map-pin', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<div>
						<p><b><?php esc_html_e( 'We couldn’t find this address on the map.', 'thirtydayhomes' ); ?></b></p>
						<p><?php esc_html_e( 'Please check the street number and ZIP code: renters see how far the home is from hospitals, and that comes from the address. If it is right, our team places it by hand during review.', 'thirtydayhomes' ); ?></p>
						<?php if ( 1 !== $step ) : ?>
							<p><a href="<?php echo esc_url( Listing_Form::url( 1, (int) $listing->ID ) ); ?>"><?php esc_html_e( 'Check the address', 'thirtydayhomes' ); ?></a></p>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $tdh_avail_notes ) : ?>
				<div class="form-notice form-notice--info lform-notice" role="status">
					<?php echo self::icon( 'calendar-days', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<div>
						<?php foreach ( $tdh_avail_notes as $tdh_note ) : ?>
							<p><?php echo esc_html( $tdh_note ); ?></p>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php
			if ( $held && $listing ) {
				self::confirmation( $listing, $held );
			} else {
				match ( $step ) {
					1 => self::step_basics( $listing ),
					2 => self::step_features( $listing ),
					3 => self::step_photos( $listing ),
					4 => self::step_review( $listing ),
				};
			}
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** The listing being edited, for parts of the page rendered without it. */
	private static ?\WP_Post $listing = null;

	/* ---------------------------------------------------------------------
	 * A live home's big change — asked before it is saved
	 * ------------------------------------------------------------------ */

	/**
	 * The page a landlord sees instead of the next step when an edit to a
	 * live home would send it back to review. Nothing has been saved yet:
	 * the step's answers travel in this form and are saved only by the
	 * button that also submits the home for review.
	 *
	 * @param array{listing:int,step:int,changes:string[],post:array<string,mixed>} $held
	 */
	private static function confirmation( \WP_Post $listing, array $held ): void {

		$step = max( 1, min( 3, (int) $held['step'] ) );
		$back = self::held_back_url( $listing, $held );
		?>
		<div class="lform-card lform-confirm">
			<i><?php echo self::icon( 'circle-alert', 30 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
			<h2><?php esc_html_e( 'These changes go back to review', 'thirtydayhomes' ); ?></h2>
			<p class="lform-confirm-what">
				<?php esc_html_e( 'You changed:', 'thirtydayhomes' ); ?>
				<b><?php echo esc_html( Listing_Form::human_list( $held['changes'] ) ); ?></b>
			</p>
			<p>
				<?php
				printf(
					/* translators: %s: listing title */
					esc_html__( 'Saving takes “%s” off the site until a person approves the changes — usually within one business day. Renters won’t see the home in the meantime. Nothing is saved until you choose.', 'thirtydayhomes' ),
					esc_html( get_the_title( $listing ) )
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( Listing_Form::url( $step, $listing->ID ) ); ?>">
				<?php self::hidden_fields( $held['post'] ); ?>
				<input type="hidden" name="tdh_confirm_review" value="1">
				<?php wp_nonce_field( Listing_Form::NONCE, 'tdh_nonce' ); ?>
				<div class="lform-actions lform-confirm-actions">
					<a class="secondary" href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( 'Go back and discard changes', 'thirtydayhomes' ); ?></a>
					<button class="primary" type="submit">
						<?php esc_html_e( 'Save and submit for review', 'thirtydayhomes' ); ?>
						<?php echo self::icon( 'arrow-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</button>
				</div>
			</form>
			<?php self::busy_script( __( 'Saving…', 'thirtydayhomes' ) ); ?>
		</div>
		<?php
	}

	/**
	 * Where "back" leads from the confirmation page: the held step, unsaved,
	 * in review mode when it was opened from the review.
	 *
	 * @param array{listing:int,step:int,changes:string[],post:array<string,mixed>} $held
	 */
	private static function held_back_url( \WP_Post $listing, array $held ): string {

		$back = Listing_Form::url( max( 1, min( 3, (int) $held['step'] ) ), (int) $listing->ID );

		return 'review' === (string) ( $held['post']['tdh_return'] ?? '' ) ? add_query_arg( 'review', '1', $back ) : $back;
	}

	/**
	 * The held step's answers as hidden fields, arrays included
	 * (`tdh_amenities[0]`, `tdh_alt[123]`, `tdh_remove[0]`…).
	 *
	 * @param array<string|int,mixed> $data
	 */
	private static function hidden_fields( array $data, string $prefix = '' ): void {

		foreach ( $data as $key => $value ) {

			$name = '' === $prefix ? (string) $key : $prefix . '[' . $key . ']';

			if ( is_array( $value ) ) {
				self::hidden_fields( $value, $name );
				continue;
			}

			printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $name ), esc_attr( (string) $value ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Step 1 — the basics
	 * ------------------------------------------------------------------ */

	private static function step_basics( ?\WP_Post $listing ): void {

		$id   = $listing ? $listing->ID : 0;
		$meta = static fn( string $key ): string => $id ? (string) get_post_meta( $id, $key, true ) : '';

		$neighborhoods = get_terms( [ 'taxonomy' => Post_Types::TAX_NEIGHBORHOOD, 'hide_empty' => false ] );
		$types         = get_terms( [ 'taxonomy' => Post_Types::TAX_TYPE, 'hide_empty' => false ] );
		$cities        = get_terms( [ 'taxonomy' => Post_Types::TAX_CITY, 'hide_empty' => false ] );

		$current_hood = $id ? (int) ( wp_get_object_terms( $id, Post_Types::TAX_NEIGHBORHOOD, [ 'fields' => 'ids' ] )[0] ?? 0 ) : 0;
		$current_type = $id ? (int) ( wp_get_object_terms( $id, Post_Types::TAX_TYPE, [ 'fields' => 'ids' ] )[0] ?? 0 ) : 0;
		$current_city = $id ? (int) ( wp_get_object_terms( $id, Post_Types::TAX_CITY, [ 'fields' => 'ids' ] )[0] ?? 0 ) : 0;

		// One city, and it is the only one on offer? Then choosing it is not
		// a decision, so it is preselected rather than made a required act.
		if ( 0 === $current_city && is_array( $cities ) && 1 === count( $cities ) ) {
			$current_city = (int) $cities[0]->term_id;
		}

		/*
		 * Inquiry contact starts as the account's own details — most
		 * landlords answer their own inquiries, and retyping a name and an
		 * address the site already knows is exactly the chore that makes a
		 * long form feel longer.
		 *
		 * The OWNER's details, not the current user's: staff can open a
		 * landlord's draft here, and prefilling the staff member's own
		 * email would route that home's inquiries to the wrong person.
		 *
		 * A saved preferred-contact value marks that this step has been
		 * saved before, so a field the landlord deliberately emptied stays
		 * empty instead of refilling itself.
		 */
		$owner   = $listing ? get_userdata( (int) $listing->post_author ) : wp_get_current_user();
		$owner   = $owner instanceof \WP_User ? $owner : wp_get_current_user();
		$started = $id && metadata_exists( 'post', $id, '_tdh_contact_method' );

		$contact = [
			'name'   => $started ? $meta( '_tdh_contact_name' ) : (string) $owner->display_name,
			'email'  => $started ? $meta( '_tdh_contact_email' ) : (string) $owner->user_email,
			'phone'  => Phone::display( $started ? $meta( '_tdh_contact_phone' ) : (string) get_user_meta( $owner->ID, '_tdh_phone', true ) ),
			'method' => $started ? $meta( '_tdh_contact_method' ) : 'email',
		];
		?>
		<form class="lform-card" method="post" action="<?php echo esc_url( Listing_Form::url( 1, $id ) ); ?>">
			<?php self::head( 'listing_basics' ); ?>

			<div class="lform-grid">

				<label class="lform-field">
					<span><b><?php esc_html_e( 'Listing title', 'thirtydayhomes' ); ?></b></span>
					<input name="tdh_title" type="text" required maxlength="120"
						placeholder="<?php esc_attr_e( 'Sunlit Shadyside Retreat', 'thirtydayhomes' ); ?>"
						value="<?php echo esc_attr( $listing ? $listing->post_title : '' ); ?>">
				</label>

				<label class="lform-field">
					<span>
						<b><?php esc_html_e( 'Street address', 'thirtydayhomes' ); ?></b>
						<small><?php esc_html_e( 'Never shown publicly', 'thirtydayhomes' ); ?></small>
					</span>
					<input name="tdh_address" type="text" required autocomplete="street-address"
						placeholder="<?php esc_attr_e( '123 Walnut Street', 'thirtydayhomes' ); ?>"
						value="<?php echo esc_attr( $meta( '_tdh_street_address' ) ); ?>">
				</label>

				<label class="lform-field">
					<span><b><?php esc_html_e( 'Neighborhood', 'thirtydayhomes' ); ?></b></span>
					<select name="tdh_neighborhood">
						<option value=""><?php esc_html_e( 'Choose…', 'thirtydayhomes' ); ?></option>
						<?php foreach ( (array) $neighborhoods as $term ) : ?>
							<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( $current_hood, $term->term_id ); ?>>
								<?php echo esc_html( $term->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<?php
				/*
				 * The city, asked for rather than assumed.
				 *
				 * Every card and banner used to end in the literal word
				 * "Pittsburgh" because the form never asked which city a
				 * home was in. The owner lists in more than one, so the
				 * question is now put to the landlord and the answer is
				 * what the public pages read back.
				 */
				?>
				<label class="lform-field">
					<span><b><?php esc_html_e( 'City', 'thirtydayhomes' ); ?></b></span>
					<select name="tdh_city" required>
						<option value=""><?php esc_html_e( 'Choose…', 'thirtydayhomes' ); ?></option>
						<?php foreach ( (array) $cities as $term ) : ?>
							<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( $current_city, $term->term_id ); ?>>
								<?php echo esc_html( $term->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="lform-field">
					<span><b><?php esc_html_e( 'ZIP code', 'thirtydayhomes' ); ?></b></span>
					<input name="tdh_zip" type="text" required inputmode="numeric" pattern="\d{5}"
						placeholder="15232" value="<?php echo esc_attr( $meta( '_tdh_zip' ) ); ?>">
				</label>

				<label class="lform-field">
					<span><b><?php esc_html_e( 'Property type', 'thirtydayhomes' ); ?></b></span>
					<select name="tdh_type">
						<option value=""><?php esc_html_e( 'Choose…', 'thirtydayhomes' ); ?></option>
						<?php foreach ( (array) $types as $term ) : ?>
							<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( $current_type, $term->term_id ); ?>>
								<?php echo esc_html( $term->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="lform-field">
					<span><b><?php esc_html_e( 'Bedrooms', 'thirtydayhomes' ); ?></b></span>
					<input name="tdh_beds" type="number" required min="0" step="1"
						value="<?php echo esc_attr( $meta( '_tdh_beds' ) ); ?>">
				</label>

				<label class="lform-field">
					<span><b><?php esc_html_e( 'Bathrooms', 'thirtydayhomes' ); ?></b></span>
					<input name="tdh_baths" type="number" required min="0" step="0.5"
						value="<?php echo esc_attr( $meta( '_tdh_baths' ) ); ?>">
				</label>

			</div>

			<h3 class="lform-h"><?php esc_html_e( 'Rent & fees', 'thirtydayhomes' ); ?></h3>
			<p class="lform-sub"><?php esc_html_e( 'Renters see these on the listing. Leave a fee blank if you do not charge it.', 'thirtydayhomes' ); ?></p>

			<div class="lform-grid">

				<label class="lform-field">
					<span><b><?php esc_html_e( 'Monthly rent', 'thirtydayhomes' ); ?></b></span>
					<input name="tdh_rent" type="number" required min="1" step="1" inputmode="numeric"
						value="<?php echo esc_attr( $meta( '_tdh_price_monthly' ) ); ?>">
				</label>

				<?php
				self::amount_field( 'tdh_deposit', __( 'Security deposit', 'thirtydayhomes' ), $meta( '_tdh_deposit' ) );
				self::amount_field( 'tdh_application_fee', __( 'Application fee', 'thirtydayhomes' ), $meta( '_tdh_application_fee' ) );
				self::amount_field( 'tdh_cleaning_fee', __( 'Cleaning fee', 'thirtydayhomes' ), $meta( '_tdh_cleaning_fee' ) );
				?>

			</div>

			<h3 class="lform-h"><?php esc_html_e( 'Inquiries', 'thirtydayhomes' ); ?></h3>
			<p class="lform-sub"><?php esc_html_e( 'Where renters’ inquiries about this home are sent. None of this appears on the listing.', 'thirtydayhomes' ); ?></p>

			<div class="lform-grid">

				<label class="lform-field">
					<span><b><?php esc_html_e( 'Contact name', 'thirtydayhomes' ); ?></b></span>
					<input name="tdh_contact_name" type="text" autocomplete="name"
						maxlength="<?php echo esc_attr( (string) Listing_Form::MAX_DETAIL ); ?>"
						value="<?php echo esc_attr( $contact['name'] ); ?>">
				</label>

				<label class="lform-field">
					<span>
						<b><?php esc_html_e( 'Email for inquiries', 'thirtydayhomes' ); ?></b>
						<small><?php esc_html_e( 'Blank uses your account email', 'thirtydayhomes' ); ?></small>
					</span>
					<input name="tdh_contact_email" type="email" autocomplete="email"
						value="<?php echo esc_attr( $contact['email'] ); ?>">
				</label>

				<label class="lform-field">
					<span>
						<b><?php esc_html_e( 'Mobile number', 'thirtydayhomes' ); ?></b>
						<small><?php esc_html_e( 'US numbers', 'thirtydayhomes' ); ?></small>
					</span>
					<input name="tdh_contact_phone" type="tel" autocomplete="tel-national" inputmode="tel"
						placeholder="(412) 555-0184"
						value="<?php echo esc_attr( $contact['phone'] ); ?>">
				</label>

				<label class="lform-field">
					<span><b><?php esc_html_e( 'Preferred contact', 'thirtydayhomes' ); ?></b></span>
					<select name="tdh_contact_method">
						<?php foreach ( Listing_Form::choices( '_tdh_contact_method' ) as $value => $label ) : ?>
							<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $contact['method'], (string) $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

			</div>

			<?php self::actions( __( 'Continue', 'thirtydayhomes' ) ); ?>
		</form>
		<?php
		self::busy_script( __( 'Saving…', 'thirtydayhomes' ) );
	}

	/**
	 * An optional money field: whole dollars, blank meaning "not charged".
	 */
	private static function amount_field( string $name, string $label, string $value ): void {
		?>
		<label class="lform-field">
			<span>
				<b><?php echo esc_html( $label ); ?></b>
				<small><?php esc_html_e( 'Optional', 'thirtydayhomes' ); ?></small>
			</span>
			<input name="<?php echo esc_attr( $name ); ?>" type="number" min="0" step="1" inputmode="numeric"
				max="<?php echo esc_attr( (string) Listing_Form::MAX_FEE ); ?>"
				value="<?php echo esc_attr( $value ); ?>">
		</label>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Step 2 — features and amenities
	 * ------------------------------------------------------------------ */

	private static function step_features( ?\WP_Post $listing ): void {

		$id     = $listing ? $listing->ID : 0;
		$stay   = (string) get_post_meta( $id, '_tdh_min_stay_days', true );
		$picked = wp_get_object_terms( $id, Post_Types::TAX_AMENITY, [ 'fields' => 'names' ] );
		$picked = is_wp_error( $picked ) ? [] : $picked;
		?>
		<form class="lform-card" id="lform-step" method="post" action="<?php echo esc_url( Listing_Form::url( 2, $id ) ); ?>">
			<?php self::head( 'listing_features' ); ?>

			<?php
			/*
			 * Availability first: when renters can move in, and the dates the
			 * home is already taken. The same editor as the dashboard's quick
			 * edit (TDH\Availability_Render), so the two can never disagree.
			 * What the last save refused comes back in its row, with the reason.
			 */
			$kept = Availability::take_kept( $id );
			?>
			<h3 class="lform-h"><?php esc_html_e( 'Availability', 'thirtydayhomes' ); ?></h3>
			<p class="lform-sub"><?php esc_html_e( 'When renters can move in, and any dates the home is already taken. Renters see this on the listing.', 'thirtydayhomes' ); ?></p>

			<div class="lform-avail">
				<?php Availability_Render::from_field( $id, $kept, 'lform-avail' ); ?>
				<input type="hidden" name="tdh_availability" value="1">
				<?php Availability_Render::ranges_field( $id, $kept, 'lform-avail' ); ?>
			</div>

			<h3 class="lform-h"><?php esc_html_e( 'Minimum stay', 'thirtydayhomes' ); ?></h3>

			<div class="lform-options" role="radiogroup" aria-label="<?php esc_attr_e( 'Minimum stay', 'thirtydayhomes' ); ?>">
				<?php foreach ( Listing_Form::stay_options() as $value => $label ) : ?>
					<label class="lform-option">
						<input type="radio" name="tdh_stay" value="<?php echo esc_attr( (string) $value ); ?>"
							<?php checked( $stay ?: '30', (string) $value ); ?>>
						<span>
							<?php echo self::icon( 'calendar-days' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<b><?php echo esc_html( $label ); ?></b>
						</span>
					</label>
				<?php endforeach; ?>
			</div>

			<?php
			/*
			 * Utilities and pets: three answers each, as the same cards the
			 * minimum stay uses, and required — a renter asks both before
			 * anything else. No answer is preselected, because a default
			 * here would be the site answering on the landlord's behalf.
			 */
			$utilities = (string) get_post_meta( $id, '_tdh_utilities_included', true );
			$pets      = (string) get_post_meta( $id, '_tdh_pet_policy', true );
			$detail    = static fn( string $key ): string => (string) get_post_meta( $id, $key, true );
			?>

			<h3 class="lform-h" id="lform-utilities-h"><?php esc_html_e( 'Utilities', 'thirtydayhomes' ); ?></h3>
			<p class="lform-sub"><?php esc_html_e( 'Are utilities part of the monthly rent?', 'thirtydayhomes' ); ?></p>

			<?php self::choice_cards( 'tdh_utilities_included', '_tdh_utilities_included', $utilities, 'zap', 'lform-utilities-h' ); ?>

			<div class="lform-grid lform-grid--single">
				<label class="lform-field">
					<span>
						<b><?php esc_html_e( 'Which utilities', 'thirtydayhomes' ); ?></b>
						<small><?php esc_html_e( 'Optional', 'thirtydayhomes' ); ?></small>
					</span>
					<input name="tdh_utilities" type="text"
						maxlength="<?php echo esc_attr( (string) Listing_Form::MAX_DETAIL ); ?>"
						placeholder="<?php esc_attr_e( 'Water, gas, electric and Wi-Fi', 'thirtydayhomes' ); ?>"
						value="<?php echo esc_attr( $detail( '_tdh_utilities' ) ); ?>">
				</label>
			</div>

			<div class="lform-pets">
				<h3 class="lform-h" id="lform-pets-h"><?php esc_html_e( 'Pets', 'thirtydayhomes' ); ?></h3>

				<?php
				self::choice_cards( 'tdh_pets', '_tdh_pet_policy', $pets, 'paw-print', 'lform-pets-h' );
				?>

				<?php // Hidden by CSS while "Not allowed" is chosen; the server clears it too. ?>
				<div class="lform-grid lform-petfee">
					<label class="lform-field">
						<span>
							<b><?php esc_html_e( 'Pet fee', 'thirtydayhomes' ); ?></b>
							<small><?php esc_html_e( 'Optional', 'thirtydayhomes' ); ?></small>
						</span>
						<input name="tdh_pet_fee" type="number" min="0" step="1" inputmode="numeric"
							max="<?php echo esc_attr( (string) Listing_Form::MAX_FEE ); ?>"
							value="<?php echo esc_attr( $detail( '_tdh_pet_fee' ) ); ?>">
					</label>
				</div>
			</div>

			<h3 class="lform-h"><?php esc_html_e( 'Home details', 'thirtydayhomes' ); ?></h3>
			<p class="lform-sub"><?php esc_html_e( 'Optional. Renters compare homes on these.', 'thirtydayhomes' ); ?></p>

			<div class="lform-grid">

				<label class="lform-field">
					<span><b><?php esc_html_e( 'Square feet', 'thirtydayhomes' ); ?></b></span>
					<input name="tdh_sqft" type="number" min="0" step="1" inputmode="numeric"
						max="<?php echo esc_attr( (string) Listing_Form::MAX_SQFT ); ?>"
						value="<?php echo esc_attr( $detail( '_tdh_sqft' ) ); ?>">
				</label>

				<label class="lform-field">
					<span><b><?php esc_html_e( 'Total rooms', 'thirtydayhomes' ); ?></b></span>
					<input name="tdh_rooms" type="number" min="0" step="1" inputmode="numeric"
						max="<?php echo esc_attr( (string) Listing_Form::MAX_ROOMS ); ?>"
						value="<?php echo esc_attr( $detail( '_tdh_rooms' ) ); ?>">
				</label>

				<label class="lform-field">
					<span><b><?php esc_html_e( 'Parking', 'thirtydayhomes' ); ?></b></span>
					<input name="tdh_parking" type="text"
						maxlength="<?php echo esc_attr( (string) Listing_Form::MAX_DETAIL ); ?>"
						placeholder="<?php esc_attr_e( 'Driveway, two cars', 'thirtydayhomes' ); ?>"
						value="<?php echo esc_attr( $detail( '_tdh_parking' ) ); ?>">
				</label>

				<label class="lform-field">
					<span><b><?php esc_html_e( 'Backyard', 'thirtydayhomes' ); ?></b></span>
					<input name="tdh_backyard" type="text"
						maxlength="<?php echo esc_attr( (string) Listing_Form::MAX_DETAIL ); ?>"
						placeholder="<?php esc_attr_e( 'Shared garden', 'thirtydayhomes' ); ?>"
						value="<?php echo esc_attr( $detail( '_tdh_backyard' ) ); ?>">
				</label>

			</div>

			<div class="lform-amenity-head">
				<div>
					<h3 class="lform-h"><?php esc_html_e( 'Amenities', 'thirtydayhomes' ); ?></h3>
					<p><?php esc_html_e( 'Select everything included with this home.', 'thirtydayhomes' ); ?></p>
				</div>
			</div>

			<div class="lform-groups">
				<?php $first = true; ?>
				<?php foreach ( Listing_Form::amenity_groups() as $group => $amenities ) : ?>
					<?php $in_group = count( array_intersect( $amenities, $picked ) ); ?>
					<details <?php echo ( $first || $in_group > 0 ) ? 'open' : ''; ?>>
						<summary>
							<span><?php echo esc_html( $group ); ?></span>
							<?php if ( $in_group > 0 ) : ?>
								<small>
									<?php
									printf(
										/* translators: %d: number selected */
										esc_html( _n( '%d selected', '%d selected', $in_group, 'thirtydayhomes' ) ),
										(int) $in_group
									);
									?>
								</small>
							<?php endif; ?>
						</summary>
						<div class="lform-chips">
							<?php foreach ( $amenities as $amenity ) : ?>
								<label class="lform-chip">
									<input type="checkbox" name="tdh_amenities[]"
										value="<?php echo esc_attr( $amenity ); ?>"
										<?php checked( in_array( $amenity, $picked, true ) ); ?>>
									<span>
										<?php echo self::icon( 'check', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
										<?php echo esc_html( $amenity ); ?>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
					</details>
					<?php $first = false; ?>
				<?php endforeach; ?>
			</div>

			<?php self::actions( __( 'Continue', 'thirtydayhomes' ), true ); ?>
		</form>
		<?php
		self::busy_script( __( 'Saving…', 'thirtydayhomes' ) );
	}

	/**
	 * A required one-of-three question, drawn as the minimum-stay cards.
	 *
	 * Real radios, so it works without JavaScript and the browser itself
	 * refuses to continue with nothing chosen. `required` on each radio of
	 * a group makes the GROUP required, not every radio. The cards follow
	 * the schema's option order, which is written most-to-least permissive.
	 */
	private static function choice_cards( string $name, string $key, string $current, string $icon, string $labelled_by ): void {
		?>
		<div class="lform-options lform-options--three" role="radiogroup" aria-labelledby="<?php echo esc_attr( $labelled_by ); ?>">
			<?php foreach ( Listing_Form::choices( $key ) as $value => $label ) : ?>
				<label class="lform-option">
					<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" required
						<?php checked( $current, (string) $value ); ?>>
					<span>
						<?php echo self::icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<b><?php echo esc_html( $label ); ?></b>
					</span>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Step 3 — photos and description
	 * ------------------------------------------------------------------ */

	private static function step_photos( ?\WP_Post $listing ): void {

		$id     = $listing ? $listing->ID : 0;
		$photos = Listing_Form::photos( $id );
		?>
		<form class="lform-card" id="lform-step" method="post" enctype="multipart/form-data" action="<?php echo esc_url( Listing_Form::url( 3, $id ) ); ?>">
			<?php self::head( 'listing_photos' ); ?>

			<?php
			$count = count( $photos );
			$full  = $count >= Listing_Form::MAX_PHOTOS;

			// Typed, refused, kept: the description survives a refused upload —
			// on this home's photo step only.
			$kept = Listing_Form::take_values( (int) $id );
			?>

			<?php if ( $listing && 'publish' === $listing->post_status && ! Accounts::is_staff() ) : ?>
				<?php
				/*
				 * A live home's photos. Chosen files cannot wait on a
				 * confirmation page the way typed answers can, so the
				 * permission is given HERE, first: until the box is ticked the
				 * photo controls are locked (script) and the server refuses
				 * uploads (Listing_Form::ask_to_confirm).
				 */
				?>
				<label class="lform-fair lform-live-tick">
					<input type="checkbox" name="tdh_confirm_review" value="1" data-tdh-unlock>
					<span>
						<b><?php esc_html_e( 'Allow a review before changing photos or the description', 'thirtydayhomes' ); ?></b>
						<?php esc_html_e( 'New, removed or reordered photos and a new description send this home back to review; it comes off the site until they’re approved — usually within one business day. Photo captions update straight away. Tick to allow that, then make your changes.', 'thirtydayhomes' ); ?>
					</span>
				</label>
			<?php endif; ?>

			<?php if ( $photos ) : ?>
				<?php
				/*
				 * The stored photos, in the order renters will see them.
				 *
				 * Every control is a real button or field: the arrows and
				 * "Make cover" save the step and come straight back, so order
				 * is changed by keyboard or touch as easily as by mouse — no
				 * drag-and-drop that a keyboard or a screen reader cannot do.
				 */
				?>
				<div class="lform-photos-head">
					<h3 class="lform-h"><?php esc_html_e( 'Your photos', 'thirtydayhomes' ); ?></h3>
					<p class="lform-sub">
						<?php
						printf(
							/* translators: 1: photos stored, 2: photo limit */
							esc_html__( '%1$s of %2$s · The first photo is the cover renters see first. Use the arrows to change the order.', 'thirtydayhomes' ),
							esc_html( number_format_i18n( $count ) ),
							esc_html( number_format_i18n( Listing_Form::MAX_PHOTOS ) )
						);
						?>
					</p>
				</div>

				<ol class="lform-photos">
					<?php foreach ( $photos as $index => $att_id ) : ?>
						<?php
						$number = $index + 1;
						$field  = 'tdh-alt-' . $att_id;
						?>
						<li class="lform-photo">
							<div class="lform-photo-media">
								<?php echo wp_get_attachment_image( $att_id, 'medium', false, [ 'alt' => '' ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php if ( 0 === $index ) : ?>
									<span class="lform-photo-badge"><?php esc_html_e( 'Cover', 'thirtydayhomes' ); ?></span>
								<?php else : ?>
									<span class="lform-photo-number" aria-hidden="true"><?php echo esc_html( number_format_i18n( $number ) ); ?></span>
								<?php endif; ?>
							</div>

							<div class="lform-photo-alt">
								<label for="<?php echo esc_attr( $field ); ?>">
									<?php
									/* translators: %s: photo position */
									printf( esc_html__( 'Describe photo %s', 'thirtydayhomes' ), esc_html( number_format_i18n( $number ) ) );
									?>
								</label>
								<input id="<?php echo esc_attr( $field ); ?>" type="text"
									name="tdh_alt[<?php echo esc_attr( (string) $att_id ); ?>]"
									maxlength="<?php echo esc_attr( (string) Listing_Photos::MAX_ALT ); ?>"
									placeholder="<?php esc_attr_e( 'e.g. Sunny living room', 'thirtydayhomes' ); ?>"
									value="<?php echo esc_attr( Listing_Photos::alt( $att_id ) ); ?>">
							</div>

							<div class="lform-photo-tools">
								<button class="lform-photo-move" type="submit" name="tdh_photo_move" formnovalidate
									value="<?php echo esc_attr( $att_id . ':up' ); ?>"
									<?php disabled( 0 === $index ); ?>
									aria-label="<?php echo esc_attr( sprintf( /* translators: %s: photo position */ __( 'Move photo %s earlier', 'thirtydayhomes' ), number_format_i18n( $number ) ) ); ?>">
									<?php echo self::icon( 'chevron-left', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								</button>
								<button class="lform-photo-move" type="submit" name="tdh_photo_move" formnovalidate
									value="<?php echo esc_attr( $att_id . ':down' ); ?>"
									<?php disabled( $count - 1 === $index ); ?>
									aria-label="<?php echo esc_attr( sprintf( /* translators: %s: photo position */ __( 'Move photo %s later', 'thirtydayhomes' ), number_format_i18n( $number ) ) ); ?>">
									<?php echo self::icon( 'chevron-right', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								</button>

								<label class="lform-photo-remove">
									<input type="checkbox" name="tdh_remove[]" value="<?php echo esc_attr( (string) $att_id ); ?>">
									<span><?php esc_html_e( 'Remove', 'thirtydayhomes' ); ?></span>
								</label>

								<?php // The cover keeps the same slot, so every tile lines up. ?>
								<?php if ( $index > 0 ) : ?>
									<button class="lform-photo-cover" type="submit" name="tdh_photo_cover" formnovalidate
										value="<?php echo esc_attr( (string) $att_id ); ?>">
										<?php esc_html_e( 'Make cover', 'thirtydayhomes' ); ?>
									</button>
								<?php else : ?>
									<span class="lform-photo-cover-note"><?php esc_html_e( 'Shown first everywhere', 'thirtydayhomes' ); ?></span>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>

			<?php if ( $full ) : ?>
				<?php // At the limit there is nothing to choose; say what to do instead. ?>
				<div class="lform-drop lform-drop--full" role="status">
					<?php echo self::icon( 'image-plus', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<b>
						<?php
						/* translators: %s: photo limit */
						printf( esc_html__( 'All %s photos are in use', 'thirtydayhomes' ), esc_html( number_format_i18n( Listing_Form::MAX_PHOTOS ) ) );
						?>
					</b>
					<small><?php esc_html_e( 'Tick Remove on a photo and press Continue to make room for another.', 'thirtydayhomes' ); ?></small>
				</div>
			<?php else : ?>

			<?php
			/*
			 * A real file input stretched across the whole dropzone, not a
			 * scripted stand-in: clicking anywhere opens the picker, and
			 * dropping files onto it is the browser's own native behaviour —
			 * both still work with JavaScript off.
			 */
			?>
			<label class="lform-drop"
				data-one="<?php esc_attr_e( 'photo selected', 'thirtydayhomes' ); ?>"
				data-many="<?php esc_attr_e( 'photos selected', 'thirtydayhomes' ); ?>"
				data-max="<?php echo esc_attr( (string) wp_max_upload_size() ); ?>"
				data-too-big="<?php esc_attr_e( 'Some of those photos are bigger than the server accepts — they will be refused.', 'thirtydayhomes' ); ?>">
				<input type="file" name="tdh_photos[]" multiple
					accept="image/jpeg,image/png,image/webp"
					aria-label="<?php esc_attr_e( 'Add photos', 'thirtydayhomes' ); ?>">
				<?php echo self::icon( 'image-plus', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<b><?php esc_html_e( 'Drop photos here or browse', 'thirtydayhomes' ); ?></b>
				<small>
					<?php
					printf(
						/* translators: 1: photo limit, 2: per-photo size limit e.g. "64 MB" */
						esc_html__( 'JPG, PNG, or WebP · maximum %1$s · up to %2$s each', 'thirtydayhomes' ),
						esc_html( number_format_i18n( Listing_Form::MAX_PHOTOS ) ),
						esc_html( (string) size_format( wp_max_upload_size() ) )
					);
					?>
				</small>
				<span><?php esc_html_e( 'Choose photos', 'thirtydayhomes' ); ?></span>
			</label>

			<?php
			/*
			 * Where the browser shows what was just chosen.
			 *
			 * Empty and hidden until then, and filled entirely on the
			 * client from the files themselves — nothing here has been
			 * sent yet, and the caption says so. Choosing a photo used to
			 * change one line of text, which was too quiet to register:
			 * the owner reported picking files and seeing "nothing happen".
			 */
			?>
			<div class="lform-preview" hidden>
				<p class="lform-preview-head">
					<b><?php esc_html_e( 'Ready to upload', 'thirtydayhomes' ); ?></b>
					<small><?php esc_html_e( 'These are added when you press Continue', 'thirtydayhomes' ); ?></small>
				</p>
				<div class="lform-preview-grid"></div>
			</div>

			<?php endif; ?>

			<div class="lform-field lform-desc">
				<span>
					<b id="lform-desc-label"><?php esc_html_e( 'Description', 'thirtydayhomes' ); ?></b>
					<small id="lform-desc-limit">
						<?php
						printf(
							/* translators: %s: character limit */
							esc_html__( 'Maximum %s characters', 'thirtydayhomes' ),
							esc_html( number_format_i18n( Listing_Form::MAX_DESCRIPTION ) )
						);
						?>
					</small>
				</span>
				<textarea name="tdh_description" rows="6" aria-labelledby="lform-desc-label" aria-describedby="lform-desc-limit"
					maxlength="<?php echo esc_attr( (string) Listing_Form::MAX_DESCRIPTION ); ?>"
					placeholder="<?php esc_attr_e( 'What makes this home a good month? Light, quiet, the desk by the window, the walk to the hospital…', 'thirtydayhomes' ); ?>"><?php echo esc_textarea( $kept['tdh_description'] ?? ( $listing ? $listing->post_content : '' ) ); ?></textarea>
			</div>

			<div class="lform-remind">
				<?php echo self::icon( 'shield-check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span>
					<b><?php esc_html_e( 'Fair Housing reminder', 'thirtydayhomes' ); ?></b>
					<small><?php esc_html_e( 'Describe the property, not the ideal renter.', 'thirtydayhomes' ); ?></small>
				</span>
			</div>

			<?php self::actions( __( 'Continue', 'thirtydayhomes' ), true ); ?>
		</form>
		<script>
		/* Two silences this closes.

		   Choosing photographs changed one line of text, which was too
		   quiet to notice — the owner picked files and reported that
		   nothing happened. Now the browser draws them immediately, from
		   the files themselves, and the Upload button counts them.

		   Uploading then travelled with the page saying nothing, which read
		   as a broken feature. The pressed button now names what it is
		   doing and refuses a second click.

		   Enhancement only. With JavaScript off the form still posts, the
		   photographs still arrive, and the server still reports refusals —
		   this only makes a working thing visible sooner. */
		( function () {
			var s = document.currentScript, form = s ? s.previousElementSibling : null;
			if ( ! form ) { return; }

			var input   = form.querySelector( 'input[type="file"]' );
			var title   = form.querySelector( '.lform-drop > b' );
			var preview = form.querySelector( '.lform-preview' );
			var grid    = form.querySelector( '.lform-preview-grid' );

			var TXT = {
				one:    <?php echo wp_json_encode( __( 'photo selected', 'thirtydayhomes' ) ); ?>,
				many:   <?php echo wp_json_encode( __( 'photos selected', 'thirtydayhomes' ) ); ?>,
				drop:   title ? title.textContent : '',
				big:    <?php echo wp_json_encode( __( 'Some of these are bigger than the server accepts — they will be refused.', 'thirtydayhomes' ) ); ?>,
				busy:   <?php echo wp_json_encode( __( 'Uploading…', 'thirtydayhomes' ) ); ?>,
				saving: <?php echo wp_json_encode( __( 'Saving…', 'thirtydayhomes' ) ); ?>
			};

			var drop = form.querySelector( '.lform-drop' );
			var max  = drop ? parseInt( drop.dataset.max, 10 ) || 0 : 0;
			var urls = [];

			// A live home: the photo controls wait for the tick that allows a
			// review. Only controls that were usable are locked, so the arrow
			// disabled at the end of the row stays disabled.
			var unlock = form.querySelector( '[data-tdh-unlock]' );
			if ( unlock ) {
				var gated = Array.prototype.filter.call(
					form.querySelectorAll( '.lform-photo-tools button, .lform-photo-remove input, .lform-drop input' ),
					function ( el ) { return ! el.disabled; }
				);
				var gate = function () {
					gated.forEach( function ( el ) { el.disabled = ! unlock.checked; } );
					form.classList.toggle( 'is-locked', ! unlock.checked );
					// Unticking takes back any photos already chosen: a locked
					// picker would otherwise send nothing while the preview
					// still promised an upload.
					if ( ! unlock.checked && input && input.value ) {
						input.value = '';
						reset();
					}
				};
				unlock.addEventListener( 'change', gate );
				gate();
			}

			function clearPreviews() {
				urls.forEach( function ( u ) { URL.revokeObjectURL( u ); } );
				urls = [];
				if ( grid ) { grid.textContent = ''; }
			}

			function reset() {
				clearPreviews();
				if ( preview ) { preview.hidden = true; }
				if ( title ) { title.textContent = TXT.drop; }
			}

			if ( input ) {
				input.addEventListener( 'change', function () {

					var files = input.files || [];
					clearPreviews();

					if ( ! files.length ) { reset(); return; }

					var total = 0, tooBig = false, i, file, url, fig;

					for ( i = 0; i < files.length; i++ ) {
						file   = files[ i ];
						total += file.size;

						if ( max && file.size > max ) { tooBig = true; }

						if ( grid && file.type.indexOf( 'image/' ) === 0 ) {
							url = URL.createObjectURL( file );
							urls.push( url );

							fig = document.createElement( 'figure' );
							fig.className = 'lform-preview-item';

							var img = document.createElement( 'img' );
							img.src = url;
							img.alt = '';

							var cap = document.createElement( 'figcaption' );
							cap.textContent = file.name;

							fig.appendChild( img );
							fig.appendChild( cap );
							grid.appendChild( fig );
						}
					}

					if ( title ) {
						title.textContent = tooBig
							? TXT.big
							: files.length + ' ' + ( 1 === files.length ? TXT.one : TXT.many )
								+ ' · ' + ( total / 1048576 ).toFixed( 1 ) + ' MB';
					}

					if ( preview ) { preview.hidden = false; }
				} );
			}

			// Every submit button that belongs to this form, including the
			// header arrow, which sits outside it and joins by form="…".
			function submits() {
				return Array.prototype.filter.call( form.elements, function ( el ) {
					return 'submit' === el.type;
				} );
			}

			form.addEventListener( 'submit', function ( e ) {
				var button = e.submitter || form.querySelector( 'button.primary' );
				var label  = ( input && input.files && input.files.length ) ? TXT.busy : TXT.saving;

				// One tick later: the browser reads the pressed button's
				// name and value (Back sends tdh_go=back) after this handler,
				// and a button disabled now would be left out of the post.
				window.setTimeout( function () {
					submits().forEach( function ( b ) {
						if ( b.disabled ) { return; }
						b.dataset.idle = b.innerHTML;
						b.dataset.busy = '1';
						b.disabled = true;
					} );
					// The arrow keeps its icon; a text button says what it is doing.
					if ( button && button.closest( '.lform-actions' ) ) { button.textContent = label; }
				}, 0 );
			} );

			// Re-enable when the browser restores this page from history,
			// otherwise Back leaves dead buttons behind.
			window.addEventListener( 'pageshow', function () {
				submits().forEach( function ( b ) {
					if ( '1' !== b.dataset.busy ) { return; }
					b.innerHTML = b.dataset.idle;
					b.disabled = false;
					delete b.dataset.busy;
				} );
			} );
		} )();
		</script>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Step 4 — review and submit
	 * ------------------------------------------------------------------ */

	private static function step_review( ?\WP_Post $listing ): void {

		$id       = $listing ? $listing->ID : 0;
		$missing  = Listing_Form::missing_for_submit( $id );
		$resubmit = $listing && Statuses::REJECTED === $listing->post_status && ! Accounts::is_staff();

		/*
		 * A landlord's live or paused home has nothing left to submit: every
		 * step saved as it went, and the changes that needed a review asked
		 * on the step. This page is the read-through, with the way out.
		 * Staff keep the ordinary review and Submit (which keeps the status).
		 */
		$settled      = $listing && in_array( $listing->post_status, [ 'publish', Statuses::PAUSED ], true ) && ! Accounts::is_staff();
		$paused_edits = $settled ? trim( (string) get_post_meta( $listing->ID, '_tdh_edited_while_paused', true ) ) : '';
		?>
		<?php if ( $settled ) : ?>
		<div class="lform-card">
			<div class="lform-ready">
				<i><?php echo self::icon( 'circle-check', 30 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
				<h3><?php esc_html_e( 'Everything is saved', 'thirtydayhomes' ); ?></h3>
				<p>
					<?php
					if ( 'publish' === $listing->post_status ) {
						esc_html_e( 'Everything you changed is saved, and small changes are already live. Bigger changes ask you first, then take the home off the site until a person approves them.', 'thirtydayhomes' );
					} elseif ( '' !== $paused_edits ) {
						printf(
							/* translators: %s: what changed, e.g. "the title and bedrooms" */
							esc_html__( 'This home stays paused. Because you changed the %s, Resume will send it for a quick review before it goes live again.', 'thirtydayhomes' ),
							esc_html( Listing_Form::changes_phrase( $paused_edits ) )
						);
					} else {
						esc_html_e( 'This home stays paused until you resume it from My listings.', 'thirtydayhomes' );
					}
					?>
				</p>
			</div>
		<?php else : ?>
		<form class="lform-card" method="post" action="<?php echo esc_url( Listing_Form::url( 4, $id ) ); ?>">
			<?php self::head( 'listing_submit' ); ?>
		<?php endif; ?>

			<?php if ( $settled ) : ?>
				<?php // Nothing to add here: what is saved is saved. ?>
			<?php elseif ( $missing ) : ?>
				<?php
				/*
				 * Not "Ready for review" with a button that then refuses.
				 * The gaps are named up front, each one a link straight to
				 * the step that fills it.
				 */
				?>
				<div class="lform-missing" id="lform-missing">
					<h3><?php esc_html_e( 'A few things before review', 'thirtydayhomes' ); ?></h3>
					<p><?php esc_html_e( 'Add these and the listing can be submitted. Everything else is saved.', 'thirtydayhomes' ); ?></p>
					<ul>
						<?php foreach ( $missing as $gap ) : ?>
							<li>
								<a href="<?php echo esc_url( add_query_arg( 'review', '1', Listing_Form::url( $gap['step'], $id ) ) ); ?>"><?php echo esc_html( $gap['label'] ); ?></a>
								<small>
									<?php
									/* translators: %d: wizard step number */
									printf( esc_html__( 'Step %d', 'thirtydayhomes' ), (int) $gap['step'] );
									?>
								</small>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php else : ?>
				<div class="lform-ready">
					<i><?php echo self::icon( 'circle-check', 30 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
					<?php if ( $resubmit ) : ?>
						<h3><?php esc_html_e( 'Ready to resubmit', 'thirtydayhomes' ); ?></h3>
						<p><?php esc_html_e( 'Our team checks your changes, usually within one business day, and it goes live from there.', 'thirtydayhomes' ); ?></p>
					<?php else : ?>
						<h3><?php esc_html_e( 'Ready for review', 'thirtydayhomes' ); ?></h3>
						<p><?php esc_html_e( 'The listing will not appear publicly until an administrator approves it.', 'thirtydayhomes' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php foreach ( self::review_blocks( $listing ) as $block ) : ?>
				<section class="lform-review-block" aria-labelledby="<?php echo esc_attr( $block['id'] ); ?>">
					<div class="lform-review-head">
						<h3 id="<?php echo esc_attr( $block['id'] ); ?>"><?php echo esc_html( $block['title'] ); ?></h3>
						<a href="<?php echo esc_url( add_query_arg( 'review', '1', Listing_Form::url( $block['step'], $id ) ) ); ?>">
							<?php esc_html_e( 'Edit', 'thirtydayhomes' ); ?>
							<span class="screen-reader-text"><?php echo esc_html( $block['title'] ); ?></span>
						</a>
					</div>
					<div class="lform-review">
						<?php foreach ( $block['rows'] as $label => $value ) : ?>
							<div>
								<small><?php echo esc_html( $label ); ?></small>
								<b><?php echo esc_html( $value ); ?></b>
							</div>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endforeach; ?>

			<?php if ( $settled ) : ?>
				<div class="lform-actions">
					<a class="secondary" href="<?php echo esc_url( add_query_arg( 'view', 'listings', Accounts::url( 'account' ) ) ); ?>"><?php esc_html_e( 'Back to My listings', 'thirtydayhomes' ); ?></a>
					<?php if ( 'publish' === $listing->post_status ) : ?>
						<a class="primary" href="<?php echo esc_url( (string) get_permalink( $listing ) ); ?>">
							<?php esc_html_e( 'View live page', 'thirtydayhomes' ); ?>
							<?php echo self::icon( 'arrow-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</a>
					<?php else : ?>
						<a class="primary" href="<?php echo esc_url( (string) get_preview_post_link( $listing ) ); ?>">
							<?php esc_html_e( 'Preview', 'thirtydayhomes' ); ?>
							<?php echo self::icon( 'arrow-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</a>
					<?php endif; ?>
				</div>
		</div>
		<?php
			return;
			endif;
		?>

			<label class="lform-fair">
				<input type="checkbox" name="tdh_fair_housing" value="1" required>
				<span>
					<?php esc_html_e( 'I confirm the information is accurate and follows Fair Housing guidelines.', 'thirtydayhomes' ); ?>
				</span>
			</label>

			<div class="lform-actions">
				<a class="secondary" href="<?php echo esc_url( Listing_Form::url( 3, $id ) ); ?>"><?php esc_html_e( 'Back', 'thirtydayhomes' ); ?></a>
				<button class="primary" type="submit"<?php echo $missing ? ' disabled aria-describedby="lform-missing"' : ''; ?>>
					<?php echo $resubmit ? esc_html__( 'Resubmit for review', 'thirtydayhomes' ) : esc_html__( 'Submit for approval', 'thirtydayhomes' ); ?>
					<?php echo self::icon( 'arrow-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</button>
			</div>
		</form>
		<?php
		self::busy_script( __( 'Submitting…', 'thirtydayhomes' ) );
	}

	/**
	 * Everything the landlord entered, grouped the way they entered it.
	 *
	 * The review used to show five facts. A landlord about to put a home in
	 * front of renters should see every answer they gave, in plain words,
	 * with the way back to change each group one click away.
	 *
	 * @return array<int,array{id:string,title:string,step:int,rows:array<string,string>}>
	 */
	private static function review_blocks( ?\WP_Post $listing ): array {

		$id   = $listing ? $listing->ID : 0;
		$meta = static fn( string $key ): string => (string) get_post_meta( $id, $key, true );

		$not_given = __( 'Not given', 'thirtydayhomes' );

		$terms = static function ( string $taxonomy ) use ( $id ): array {
			$names = wp_get_object_terms( $id, $taxonomy, [ 'fields' => 'names' ] );
			return is_wp_error( $names ) ? [] : $names;
		};

		// Blank and zero are the same answer to a renter: nothing to pay.
		$money = static function ( string $value ): string {
			return (float) $value > 0 ? '$' . number_format_i18n( (float) $value ) : __( 'None', 'thirtydayhomes' );
		};

		$number = static fn( string $value ): string => '' !== $value ? number_format_i18n( (float) $value, (float) $value === floor( (float) $value ) ? 0 : 1 ) : $not_given;

		/*
		 * The city was typed in here as "Pittsburgh", with a note saying
		 * that when a second city arrived this and the listing template
		 * would have to change together. That has happened: the form now
		 * asks for the city, and both places read the listing's own terms.
		 */
		$location = implode( ', ', array_filter( array_merge( $terms( Post_Types::TAX_NEIGHBORHOOD ), $terms( Post_Types::TAX_CITY ) ) ) );
		$zip      = $meta( '_tdh_zip' );
		$location = implode( ' · ', array_filter( [ $location, $zip ] ) );

		$type = $terms( Post_Types::TAX_TYPE );

		// Availability, as renters will read it.
		$available = Availability::available_from( $id );
		$periods   = array_map( [ Availability::class, 'format_range' ], Availability::ranges( $id ) );
		$shown     = array_slice( $periods, 0, 3 );

		if ( count( $periods ) > 3 ) {
			/* translators: %s: number of further periods */
			$shown[] = sprintf( _n( '%s more', '%s more', count( $periods ) - 3, 'thirtydayhomes' ), number_format_i18n( count( $periods ) - 3 ) );
		}

		// Contact. An empty inquiry email means the account address.
		$owner = $listing ? get_userdata( (int) $listing->post_author ) : null;
		$email = $meta( '_tdh_contact_email' );

		if ( '' === $email && $owner instanceof \WP_User ) {
			/* translators: %s: the landlord's account email */
			$email = sprintf( __( '%s (account email)', 'thirtydayhomes' ), $owner->user_email );
		}

		$methods = Listing_Form::choices( '_tdh_contact_method' );

		// The home.
		$stay      = Listing_Form::stay_options()[ $meta( '_tdh_min_stay_days' ) ] ?? $not_given;
		$utilities = Listing_Form::choices( '_tdh_utilities_included' )[ $meta( '_tdh_utilities_included' ) ] ?? $not_given;

		if ( '' !== $meta( '_tdh_utilities' ) ) {
			$utilities .= ' — ' . $meta( '_tdh_utilities' );
		}

		$policy = $meta( '_tdh_pet_policy' );
		$pets   = Listing_Form::choices( '_tdh_pet_policy' )[ $policy ] ?? $not_given;

		if ( 'no' !== $policy && (float) $meta( '_tdh_pet_fee' ) > 0 ) {
			/* translators: %s: pet fee, e.g. "$150" */
			$pets .= ' · ' . sprintf( __( '%s pet fee', 'thirtydayhomes' ), $money( $meta( '_tdh_pet_fee' ) ) );
		}

		$amenities = count( $terms( Post_Types::TAX_AMENITY ) );
		$photos    = count( Listing_Form::photos( $id ) );
		$chars     = $listing ? mb_strlen( trim( $listing->post_content ) ) : 0;

		return [
			[
				'id'    => 'lform-review-basics',
				'title' => __( 'The basics', 'thirtydayhomes' ),
				'step'  => 1,
				'rows'  => [
					__( 'Title', 'thirtydayhomes' )          => $listing && '' !== $listing->post_title ? $listing->post_title : $not_given,
					__( 'Location', 'thirtydayhomes' )       => '' !== $location ? $location : $not_given,
					__( 'Property type', 'thirtydayhomes' )  => $type ? implode( ', ', $type ) : $not_given,
					__( 'Bedrooms', 'thirtydayhomes' )       => $number( $meta( '_tdh_beds' ) ),
					__( 'Bathrooms', 'thirtydayhomes' )      => $number( $meta( '_tdh_baths' ) ),
				],
			],
			[
				'id'    => 'lform-review-fees',
				'title' => __( 'Rent & fees', 'thirtydayhomes' ),
				'step'  => 1,
				'rows'  => [
					__( 'Monthly rent', 'thirtydayhomes' )     => (float) $meta( '_tdh_price_monthly' ) > 0 ? $money( $meta( '_tdh_price_monthly' ) ) : $not_given,
					__( 'Security deposit', 'thirtydayhomes' ) => $money( $meta( '_tdh_deposit' ) ),
					__( 'Application fee', 'thirtydayhomes' )  => $money( $meta( '_tdh_application_fee' ) ),
					__( 'Cleaning fee', 'thirtydayhomes' )     => $money( $meta( '_tdh_cleaning_fee' ) ),
				],
			],
			[
				'id'    => 'lform-review-inquiries',
				'title' => __( 'Inquiries', 'thirtydayhomes' ),
				'step'  => 1,
				'rows'  => [
					__( 'Contact name', 'thirtydayhomes' )      => '' !== $meta( '_tdh_contact_name' ) ? $meta( '_tdh_contact_name' ) : $not_given,
					__( 'Email', 'thirtydayhomes' )             => '' !== $email ? $email : $not_given,
					__( 'Mobile number', 'thirtydayhomes' )     => '' !== $meta( '_tdh_contact_phone' ) ? Phone::display( $meta( '_tdh_contact_phone' ) ) : $not_given,
					__( 'Preferred contact', 'thirtydayhomes' ) => $methods[ $meta( '_tdh_contact_method' ) ] ?? $not_given,
				],
			],
			[
				'id'    => 'lform-review-availability',
				'title' => __( 'Availability', 'thirtydayhomes' ),
				'step'  => 2,
				'rows'  => [
					__( 'Available from', 'thirtydayhomes' )    => '' !== $available ? Availability::format_day( $available ) : __( 'Now — no date given', 'thirtydayhomes' ),
					__( 'Minimum stay', 'thirtydayhomes' )      => $stay,
					__( 'Unavailable dates', 'thirtydayhomes' ) => $shown ? implode( ' · ', $shown ) : __( 'None', 'thirtydayhomes' ),
				],
			],
			[
				'id'    => 'lform-review-home',
				'title' => __( 'The home', 'thirtydayhomes' ),
				'step'  => 2,
				'rows'  => [
					__( 'Utilities', 'thirtydayhomes' )    => $utilities,
					__( 'Pets', 'thirtydayhomes' )         => $pets,
					__( 'Square feet', 'thirtydayhomes' )  => $number( $meta( '_tdh_sqft' ) ),
					__( 'Total rooms', 'thirtydayhomes' )  => $number( $meta( '_tdh_rooms' ) ),
					__( 'Parking', 'thirtydayhomes' )      => '' !== $meta( '_tdh_parking' ) ? $meta( '_tdh_parking' ) : $not_given,
					__( 'Backyard', 'thirtydayhomes' )     => '' !== $meta( '_tdh_backyard' ) ? $meta( '_tdh_backyard' ) : $not_given,
					/* translators: %s: number of amenities */
					__( 'Amenities', 'thirtydayhomes' )    => sprintf( __( '%s selected', 'thirtydayhomes' ), number_format_i18n( $amenities ) ),
				],
			],
			[
				'id'    => 'lform-review-photos',
				'title' => __( 'Photos & description', 'thirtydayhomes' ),
				'step'  => 3,
				'rows'  => [
					/*
					 * Photographs, counted back.
					 *
					 * The review listed everything a landlord had entered
					 * EXCEPT the photos, so the one step with no confirmation
					 * was the one that takes longest and matters most. The
					 * owner reported the upload as broken on that basis while
					 * it was working. A count here is how the wizard says
					 * "yes, they arrived".
					 */
					__( 'Photos', 'thirtydayhomes' )      => $photos
						/* translators: %s: number of photos */
						? sprintf( _n( '%s photo', '%s photos', $photos, 'thirtydayhomes' ), number_format_i18n( $photos ) )
						: __( 'None yet', 'thirtydayhomes' ),
					__( 'Description', 'thirtydayhomes' ) => $chars
						/* translators: %s: number of characters */
						? sprintf( _n( '%s character', '%s characters', $chars, 'thirtydayhomes' ), number_format_i18n( $chars ) )
						: __( 'Not written yet', 'thirtydayhomes' ),
				],
			],
		];
	}

	/* ---------------------------------------------------------------------
	 * Shared parts
	 * ------------------------------------------------------------------ */

	private static function gate( string $reason ): string {

		$copy = [
			'signin' => [ __( 'Sign in to list your home', 'thirtydayhomes' ), __( 'The listing wizard belongs to your landlord account.', 'thirtydayhomes' ), __( 'Sign in', 'thirtydayhomes' ), Accounts::url( 'login' ) ],
			'role'   => [ __( 'This is the landlord side', 'thirtydayhomes' ), __( 'Listing a home needs a landlord account.', 'thirtydayhomes' ), __( 'Create one', 'thirtydayhomes' ), Accounts::url( 'register' ) ],
			'plan'   => [ __( 'A membership comes first', 'thirtydayhomes' ), __( 'Choose a plan, and your home can be live after review. Nothing is charged until you pick one.', 'thirtydayhomes' ), __( 'See plans', 'thirtydayhomes' ), Accounts::url( 'pricing' ) ],
			'full'   => [ __( 'Your plan is full', 'thirtydayhomes' ), __( 'Every listing your plan allows is in use. A bigger plan raises the allowance in one step.', 'thirtydayhomes' ), __( 'See plans', 'thirtydayhomes' ), Accounts::url( 'pricing' ) ],
		];

		[ $title, $body, $cta, $url ] = $copy[ $reason ] ?? $copy['signin'];

		ob_start();
		?>
		<div class="lform">
			<div class="lform-card lform-gate">
				<h1><?php echo esc_html( $title ); ?></h1>
				<p><?php echo esc_html( $body ); ?></p>
				<a class="gold-btn" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $cta ); ?></a>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function head( string $action ): void {
		?>
		<input type="hidden" name="tdh_action" value="<?php echo esc_attr( $action ); ?>">
		<?php wp_nonce_field( Listing_Form::NONCE, 'tdh_nonce' ); ?>
		<?php
	}

	/**
	 * The step's footer, as the prototype draws it: the first step offers
	 * "Save draft" (there is nothing to go back to), later steps offer Back.
	 *
	 * Back SAVES, then goes back. It was a plain link, and a landlord lost a
	 * whole step of changes by pressing it. `formnovalidate` lets it leave
	 * with a question still unanswered; the server still names any value it
	 * cannot store, so nothing typed vanishes.
	 *
	 * A step opened from the review's Edit link says so on its main button
	 * and returns there after saving, instead of walking through every step
	 * in between.
	 */
	private static function actions( string $continue, bool $has_back = false ): void {

		$from_review = isset( $_GET['review'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $from_review ) {
			$continue = __( 'Save and return to review', 'thirtydayhomes' );
		}
		?>
		<div class="lform-actions">
			<?php if ( $from_review ) : ?>
				<input type="hidden" name="tdh_return" value="review">
			<?php endif; ?>
			<?php if ( $has_back ) : ?>
				<button class="secondary" type="submit" name="tdh_go" value="back" formnovalidate>
					<?php esc_html_e( 'Back', 'thirtydayhomes' ); ?>
				</button>
			<?php else : ?>
				<button class="secondary" type="submit" name="tdh_save_only" value="1">
					<?php
					// A home that is already live or paused is not a draft.
					echo self::$listing && in_array( self::$listing->post_status, [ 'publish', Statuses::PAUSED ], true )
						? esc_html__( 'Save', 'thirtydayhomes' )
						: esc_html__( 'Save draft', 'thirtydayhomes' );
					?>
				</button>
			<?php endif; ?>
			<button class="primary" type="submit">
				<?php echo esc_html( $continue ); ?>
				<?php echo self::icon( 'arrow-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Say what a pressed button is doing, and refuse a second press.
	 *
	 * A double click on step 1's Continue used to post twice, and a new
	 * listing's first post has no id yet — so it created TWO drafts, one of
	 * them counting against the plan's allowance with nobody aware it
	 * existed.
	 *
	 * The disabling waits one tick. A disabled button is left out of the
	 * form data, and the browser builds that data after this handler runs:
	 * disabling at once would silently turn "Save draft" into "Continue".
	 * Only buttons this script disabled are re-enabled when the page comes
	 * back from history, so a button disabled on purpose stays disabled.
	 *
	 * Must be printed straight after its form. Enhancement only. Public: the
	 * availability panel on My listings uses it too.
	 */
	public static function busy_script( string $busy ): void {
		?>
		<script>
		( function () {
			var s = document.currentScript, form = s ? s.previousElementSibling : null;
			if ( ! form || 'FORM' !== form.tagName ) { return; }

			// form.elements, not a query inside the form: the header arrow on
			// steps 2 and 3 is outside it and joins with form="lform-step".
			var buttons = function () {
				return Array.prototype.filter.call( form.elements, function ( el ) { return 'submit' === el.type; } );
			};

			form.addEventListener( 'submit', function ( e ) {
				var pressed = e.submitter || form.querySelector( 'button.primary' );
				window.setTimeout( function () {
					buttons().forEach( function ( b ) {
						if ( b.disabled ) { return; }
						b.dataset.idle = b.innerHTML;
						b.dataset.busy = '1';
						b.disabled = true;
					} );
					// The arrow keeps its icon; a text button says what it is doing.
					if ( pressed && pressed.closest( '.lform-actions, .manage-avail-actions' ) ) { pressed.textContent = <?php echo wp_json_encode( $busy ); ?>; }
				}, 0 );
			} );

			window.addEventListener( 'pageshow', function () {
				buttons().forEach( function ( b ) {
					if ( '1' !== b.dataset.busy ) { return; }
					b.innerHTML = b.dataset.idle;
					b.disabled = false;
					delete b.dataset.busy;
				} );
			} );
		} )();
		</script>
		<?php
	}

	private static function icon( string $name, int $size = 19 ): string {
		return function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size ) : '';
	}
}
