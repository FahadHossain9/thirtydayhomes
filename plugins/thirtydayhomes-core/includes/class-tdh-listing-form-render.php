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

		$titles = [
			1 => __( 'The basics', 'thirtydayhomes' ),
			2 => __( 'Features & amenities', 'thirtydayhomes' ),
			3 => __( 'Photos & description', 'thirtydayhomes' ),
			4 => __( 'Review & submit', 'thirtydayhomes' ),
		];

		$back = 1 === $step
			? Accounts::url( 'account' )
			: Listing_Form::url( $step - 1, $listing ? $listing->ID : 0 );

		$errors = Listing_Form::take_errors();
		$saved  = isset( $_GET['saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();
		?>
		<div class="lform">

			<div class="lform-head">
				<a class="lform-back" href="<?php echo esc_url( $back ); ?>" aria-label="<?php esc_attr_e( 'Back', 'thirtydayhomes' ); ?>">
					<?php echo self::icon( 'arrow-left' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</a>
				<div>
					<p><?php esc_html_e( 'Create a listing', 'thirtydayhomes' ); ?></p>
					<h1><?php echo esc_html( $titles[ $step ] ); ?></h1>
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

			<?php if ( $saved ) : ?>
				<div class="form-notice form-notice--ok lform-notice" role="status">
					<?php echo self::icon( 'check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<p><?php esc_html_e( 'Draft saved. It is on your dashboard whenever you want to continue.', 'thirtydayhomes' ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			match ( $step ) {
				1 => self::step_basics( $listing ),
				2 => self::step_features( $listing ),
				3 => self::step_photos( $listing ),
				4 => self::step_review( $listing ),
			};
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Step 1 — the basics
	 * ------------------------------------------------------------------ */

	private static function step_basics( ?\WP_Post $listing ): void {

		$id   = $listing ? $listing->ID : 0;
		$meta = static fn( string $key ): string => $id ? (string) get_post_meta( $id, $key, true ) : '';

		$neighborhoods = get_terms( [ 'taxonomy' => Post_Types::TAX_NEIGHBORHOOD, 'hide_empty' => false ] );
		$types         = get_terms( [ 'taxonomy' => Post_Types::TAX_TYPE, 'hide_empty' => false ] );

		$current_hood = $id ? (int) ( wp_get_object_terms( $id, Post_Types::TAX_NEIGHBORHOOD, [ 'fields' => 'ids' ] )[0] ?? 0 ) : 0;
		$current_type = $id ? (int) ( wp_get_object_terms( $id, Post_Types::TAX_TYPE, [ 'fields' => 'ids' ] )[0] ?? 0 ) : 0;
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

				<label class="lform-field">
					<span><b><?php esc_html_e( 'ZIP code', 'thirtydayhomes' ); ?></b></span>
					<input name="tdh_zip" type="text" required inputmode="numeric" pattern="\d{5}"
						placeholder="15232" value="<?php echo esc_attr( $meta( '_tdh_zip' ) ); ?>">
				</label>

				<label class="lform-field">
					<span><b><?php esc_html_e( 'Monthly rent', 'thirtydayhomes' ); ?></b></span>
					<input name="tdh_rent" type="number" required min="1" step="1"
						value="<?php echo esc_attr( $meta( '_tdh_price_monthly' ) ); ?>">
				</label>

				<label class="lform-field">
					<span>
						<b><?php esc_html_e( 'Security deposit', 'thirtydayhomes' ); ?></b>
						<small><?php esc_html_e( 'Optional', 'thirtydayhomes' ); ?></small>
					</span>
					<input name="tdh_deposit" type="number" min="0" step="1"
						value="<?php echo esc_attr( $meta( '_tdh_deposit' ) ); ?>">
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
					<span><b><?php esc_html_e( 'Available from', 'thirtydayhomes' ); ?></b></span>
					<input name="tdh_available" type="date"
						value="<?php echo esc_attr( $meta( '_tdh_available_from' ) ); ?>">
				</label>

			</div>

			<?php self::actions( __( 'Continue', 'thirtydayhomes' ) ); ?>
		</form>
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
		<form class="lform-card" method="post" action="<?php echo esc_url( Listing_Form::url( 2, $id ) ); ?>">
			<?php self::head( 'listing_features' ); ?>

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

			<?php self::actions( __( 'Continue', 'thirtydayhomes' ), Listing_Form::url( 1, $id ) ); ?>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Step 3 — photos and description
	 * ------------------------------------------------------------------ */

	private static function step_photos( ?\WP_Post $listing ): void {

		$id     = $listing ? $listing->ID : 0;
		$photos = Listing_Form::photos( $id );
		?>
		<form class="lform-card" method="post" enctype="multipart/form-data" action="<?php echo esc_url( Listing_Form::url( 3, $id ) ); ?>">
			<?php self::head( 'listing_photos' ); ?>

			<?php if ( $photos ) : ?>
				<div class="lform-photos">
					<?php foreach ( $photos as $att_id ) : ?>
						<label class="lform-photo">
							<?php echo wp_get_attachment_image( $att_id, 'thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<input type="checkbox" name="tdh_remove[]" value="<?php echo esc_attr( (string) $att_id ); ?>">
							<span><?php esc_html_e( 'Remove', 'thirtydayhomes' ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

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
			<script>
			/* Confirmation that the choice registered — the input itself is
			   invisible under the dropzone, so without this the picker closes
			   and the page looks exactly as before. Enhancement only: with
			   JavaScript off, the form still uploads and the server still
			   names every refusal. */
			( function () {
				var s = document.currentScript, drop = s ? s.previousElementSibling : null;
				var input = drop ? drop.querySelector( 'input[type="file"]' ) : null;
				var title = drop ? drop.querySelector( 'b' ) : null;
				if ( ! input || ! title ) { return; }
				var idle = title.textContent, max = parseInt( drop.dataset.max, 10 ) || 0;
				input.addEventListener( 'change', function () {
					var files = input.files || [], total = 0, tooBig = false, i;
					if ( ! files.length ) { title.textContent = idle; return; }
					for ( i = 0; i < files.length; i++ ) {
						total += files[ i ].size;
						if ( max && files[ i ].size > max ) { tooBig = true; }
					}
					title.textContent = tooBig
						? drop.dataset.tooBig
						: files.length + ' ' + ( 1 === files.length ? drop.dataset.one : drop.dataset.many )
							+ ' · ' + ( total / 1048576 ).toFixed( 1 ) + ' MB';
				} );
			} )();
			</script>

			<div class="lform-field lform-desc">
				<span>
					<b><?php esc_html_e( 'Description', 'thirtydayhomes' ); ?></b>
					<small>
						<?php
						printf(
							/* translators: %s: character limit */
							esc_html__( 'Maximum %s characters', 'thirtydayhomes' ),
							esc_html( number_format_i18n( Listing_Form::MAX_DESCRIPTION ) )
						);
						?>
					</small>
				</span>
				<textarea name="tdh_description" rows="6"
					maxlength="<?php echo esc_attr( (string) Listing_Form::MAX_DESCRIPTION ); ?>"
					placeholder="<?php esc_attr_e( 'What makes this home a good month? Light, quiet, the desk by the window, the walk to the hospital…', 'thirtydayhomes' ); ?>"><?php echo esc_textarea( $listing ? $listing->post_content : '' ); ?></textarea>
			</div>

			<div class="lform-remind">
				<?php echo self::icon( 'shield-check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span>
					<b><?php esc_html_e( 'Fair Housing reminder', 'thirtydayhomes' ); ?></b>
					<small><?php esc_html_e( 'Describe the property, not the ideal renter.', 'thirtydayhomes' ); ?></small>
				</span>
			</div>

			<?php self::actions( __( 'Continue', 'thirtydayhomes' ), Listing_Form::url( 2, $id ) ); ?>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Step 4 — review and submit
	 * ------------------------------------------------------------------ */

	private static function step_review( ?\WP_Post $listing ): void {

		$id   = $listing ? $listing->ID : 0;
		$rent = (string) get_post_meta( $id, '_tdh_price_monthly', true );

		$hood = wp_get_object_terms( $id, Post_Types::TAX_NEIGHBORHOOD, [ 'fields' => 'names' ] );
		$hood = is_wp_error( $hood ) ? [] : $hood;
		$amen = wp_get_object_terms( $id, Post_Types::TAX_AMENITY, [ 'fields' => 'names' ] );
		$amen = is_wp_error( $amen ) ? [] : $amen;

		/*
		 * ", Pittsburgh" is the single listing template's own precedent —
		 * its banner eyebrow prints "{neighborhood} · Pittsburgh". Launch
		 * is one city; when a second one arrives, both places change
		 * together or the tests catch the drift.
		 */
		$location = $hood
			/* translators: %s: neighborhood name */
			? sprintf( __( '%s, Pittsburgh', 'thirtydayhomes' ), implode( ', ', $hood ) )
			: __( 'Pittsburgh', 'thirtydayhomes' );

		$rows = [
			__( 'Title', 'thirtydayhomes' )        => $listing && '' !== $listing->post_title
				? $listing->post_title
				: __( 'Untitled furnished home', 'thirtydayhomes' ),
			__( 'Location', 'thirtydayhomes' )     => $location,
			__( 'Monthly rent', 'thirtydayhomes' ) => '' !== $rent ? '$' . number_format_i18n( (float) $rent ) : '—',
			__( 'Amenities', 'thirtydayhomes' )    =>
				/* translators: %s: amenity count */
				sprintf( __( '%s selected', 'thirtydayhomes' ), number_format_i18n( count( $amen ) ) ),
		];
		?>
		<form class="lform-card" method="post" action="<?php echo esc_url( Listing_Form::url( 4, $id ) ); ?>">
			<?php self::head( 'listing_submit' ); ?>

			<div class="lform-ready">
				<i><?php echo self::icon( 'circle-check', 30 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
				<h3><?php esc_html_e( 'Ready for review', 'thirtydayhomes' ); ?></h3>
				<p><?php esc_html_e( 'The listing will not appear publicly until an administrator approves it.', 'thirtydayhomes' ); ?></p>
			</div>

			<div class="lform-review">
				<?php foreach ( $rows as $label => $value ) : ?>
					<div>
						<small><?php echo esc_html( $label ); ?></small>
						<b><?php echo esc_html( $value ); ?></b>
					</div>
				<?php endforeach; ?>
			</div>

			<label class="lform-fair">
				<input type="checkbox" name="tdh_fair_housing" value="1" required>
				<span>
					<?php esc_html_e( 'I confirm the information is accurate and follows Fair Housing guidelines.', 'thirtydayhomes' ); ?>
				</span>
			</label>

			<div class="lform-actions">
				<a class="secondary" href="<?php echo esc_url( Listing_Form::url( 3, $id ) ); ?>"><?php esc_html_e( 'Back', 'thirtydayhomes' ); ?></a>
				<button class="primary" type="submit">
					<?php esc_html_e( 'Submit for approval', 'thirtydayhomes' ); ?>
					<?php echo self::icon( 'arrow-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</button>
			</div>
		</form>
		<?php
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
	 * "Save draft" (there is nothing to go back to), later steps offer
	 * "Back" — a plain link, because going back must not require a save.
	 */
	private static function actions( string $continue, string $back_url = '' ): void {
		?>
		<div class="lform-actions">
			<?php if ( '' !== $back_url ) : ?>
				<a class="secondary" href="<?php echo esc_url( $back_url ); ?>">
					<?php esc_html_e( 'Back', 'thirtydayhomes' ); ?>
				</a>
			<?php else : ?>
				<button class="secondary" type="submit" name="tdh_save_only" value="1">
					<?php esc_html_e( 'Save draft', 'thirtydayhomes' ); ?>
				</button>
			<?php endif; ?>
			<button class="primary" type="submit">
				<?php echo esc_html( $continue ); ?>
				<?php echo self::icon( 'arrow-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</button>
		</div>
		<?php
	}

	private static function icon( string $name, int $size = 19 ): string {
		return function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size ) : '';
	}
}
