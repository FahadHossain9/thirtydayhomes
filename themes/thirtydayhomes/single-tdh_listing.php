<?php
/**
 * Single listing — the renter's detail page.
 *
 * ADDRESS PRIVACY: the street address is NOT rendered here, and must not
 * be added, not even inside a hidden element — both Elementor and this
 * theme put hidden markup in the DOM where anyone can read it. Only the
 * neighborhood and ZIP appear. See DEVELOPMENT_PLAN.md §3.8.
 *
 * @package ThirtyDayHomes
 */

defined( 'ABSPATH' ) || exit;

get_header();

if ( ! tdh_elementor_location( 'single' ) ) :

	while ( have_posts() ) :
		the_post();

		$listing_id = get_the_ID();

		$price     = (float) get_post_meta( $listing_id, '_tdh_price_monthly', true );
		$deposit   = (float) get_post_meta( $listing_id, '_tdh_deposit', true );
		$app_fee   = (float) get_post_meta( $listing_id, '_tdh_application_fee', true );
		$pet_fee   = (float) get_post_meta( $listing_id, '_tdh_pet_fee', true );
		$beds      = get_post_meta( $listing_id, '_tdh_beds', true );
		$baths     = get_post_meta( $listing_id, '_tdh_baths', true );
		$sqft      = get_post_meta( $listing_id, '_tdh_sqft', true );
		$rooms     = get_post_meta( $listing_id, '_tdh_rooms', true );
		$available = get_post_meta( $listing_id, '_tdh_available_from', true );
		$min_stay  = (int) get_post_meta( $listing_id, '_tdh_min_stay_days', true );
		$utilities = get_post_meta( $listing_id, '_tdh_utilities', true );
		$parking   = get_post_meta( $listing_id, '_tdh_parking', true );
		$backyard  = get_post_meta( $listing_id, '_tdh_backyard', true );
		$pets      = get_post_meta( $listing_id, '_tdh_pet_policy', true );
		$zip       = get_post_meta( $listing_id, '_tdh_zip', true );

		$hoods = get_the_terms( $listing_id, 'tdh_neighborhood' );
		$hood  = ( $hoods && ! is_wp_error( $hoods ) ) ? $hoods[0]->name : '';

		$stay_label = 91 === $min_stay
			? __( '13 weeks', 'thirtydayhomes' )
			/* translators: %s: number of days */
			: sprintf( __( '%s days', 'thirtydayhomes' ), number_format_i18n( $min_stay ) );

		$pet_label = [
			'yes'        => __( 'Pets welcome', 'thirtydayhomes' ),
			'considered' => __( 'Pets considered', 'thirtydayhomes' ),
			'no'         => __( 'No pets', 'thirtydayhomes' ),
		][ $pets ] ?? '';
		?>

		<?php
		/*
		 * The banner carries the trail, which replaced the old "Back to
		 * homes" link — it does the same job and also says where this home
		 * sits, which a lone back arrow does not.
		 */
		tdh_page_banner(
			[
				'eyebrow' => $hood ? $hood . __( ' · Pittsburgh', 'thirtydayhomes' ) : '',
				'title'   => get_the_title(),
				'lead'    => sprintf(
					/* translators: %s: ZIP code */
					__( 'Approximate location · %s', 'thirtydayhomes' ),
					(string) $zip
				),
			]
		);
		?>

		<div class="detail">

			<?php if ( has_post_thumbnail() ) : ?>
				<figure class="detail-media">
					<?php
					the_post_thumbnail(
						'tdh-gallery',
						[
							'alt' => esc_attr(
								sprintf(
									/* translators: %s: listing title */
									__( '%s — furnished rental in Pittsburgh', 'thirtydayhomes' ),
									get_the_title()
								)
							),
						]
					);
					?>
				</figure>
			<?php endif; ?>

			<div class="detail-grid">

				<div>

					<ul class="quick-facts">
						<?php
						$facts = [
							__( 'Bedrooms', 'thirtydayhomes' )    => $beds,
							__( 'Bathrooms', 'thirtydayhomes' )   => $baths,
							__( 'Square feet', 'thirtydayhomes' ) => $sqft ? number_format_i18n( (int) $sqft ) : '',
							__( 'Total rooms', 'thirtydayhomes' ) => $rooms,
						];
						foreach ( $facts as $label => $value ) :
							if ( '' === $value || null === $value ) {
								continue;
							}
							?>
							<li>
								<b><?php echo esc_html( (string) $value ); ?></b>
								<small><?php echo esc_html( $label ); ?></small>
							</li>
						<?php endforeach; ?>
					</ul>

					<section class="detail-section">
						<h2><?php esc_html_e( 'About this home', 'thirtydayhomes' ); ?></h2>
						<?php the_content(); ?>
					</section>

					<?php
					$amenities = get_the_terms( $listing_id, 'tdh_amenity' );
					$extras    = array_filter( [ $utilities, $parking, $backyard, $pet_label ] );

					if ( ( $amenities && ! is_wp_error( $amenities ) ) || $extras ) :
						?>
						<section class="detail-section">
							<h2><?php esc_html_e( 'Everything you need', 'thirtydayhomes' ); ?></h2>
							<ul class="amenities">
								<?php
								if ( $amenities && ! is_wp_error( $amenities ) ) {
									foreach ( $amenities as $amenity ) {
										echo '<li>' . esc_html( $amenity->name ) . '</li>';
									}
								}
								foreach ( $extras as $extra ) {
									echo '<li>' . esc_html( (string) $extra ) . '</li>';
								}
								?>
							</ul>
						</section>
					<?php endif; ?>

					<?php if ( $available ) : ?>
						<section class="detail-section">
							<h2><?php esc_html_e( 'Availability', 'thirtydayhomes' ); ?></h2>
							<div class="availability">
								<span>
									<b>
										<?php
										printf(
											/* translators: %s: availability date */
											esc_html__( 'Available from %s', 'thirtydayhomes' ),
											esc_html( date_i18n( get_option( 'date_format' ), (int) strtotime( (string) $available ) ) )
										);
										?>
									</b>
									<small>
										<?php
										printf(
											/* translators: %s: minimum stay */
											esc_html__( 'Minimum stay %s', 'thirtydayhomes' ),
											esc_html( $stay_label )
										);
										?>
									</small>
								</span>
							</div>
						</section>
					<?php endif; ?>

					<section class="detail-section">
						<h2><?php esc_html_e( 'Close to care', 'thirtydayhomes' ); ?></h2>

						<div class="map-box">
							<b><?php esc_html_e( 'Approximate map area', 'thirtydayhomes' ); ?></b>
							<small>
								<?php esc_html_e( 'We show the neighborhood rather than the exact address. A furnished home that is often empty should not have its address published — the owner shares it with you after you make contact.', 'thirtydayhomes' ); ?>
							</small>
						</div>

						<?php
						/**
						 * Three nearest facilities with distance and drive time.
						 * Rendered by the proximity module in Milestone 2.
						 *
						 * @param int $listing_id Listing being rendered.
						 */
						do_action( 'tdh_listing_proximity', $listing_id );
						?>
					</section>

				</div>

				<aside class="inquiry-box">

					<p>
						<b><?php echo esc_html( '$' . number_format_i18n( $price ) ); ?></b>
						<?php esc_html_e( '/ month', 'thirtydayhomes' ); ?>
					</p>
					<small><?php esc_html_e( 'No guest booking fee', 'thirtydayhomes' ); ?></small>

					<hr>

					<dl>
						<?php if ( $available ) : ?>
							<div>
								<dt><?php esc_html_e( 'Available', 'thirtydayhomes' ); ?></dt>
								<dd><?php echo esc_html( date_i18n( 'M j, Y', (int) strtotime( (string) $available ) ) ); ?></dd>
							</div>
						<?php endif; ?>
						<div>
							<dt><?php esc_html_e( 'Application fee', 'thirtydayhomes' ); ?></dt>
							<dd><?php echo esc_html( $app_fee ? '$' . number_format_i18n( $app_fee ) : __( 'None', 'thirtydayhomes' ) ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Pet fee', 'thirtydayhomes' ); ?></dt>
							<dd><?php echo esc_html( $pet_fee ? '$' . number_format_i18n( $pet_fee ) : __( 'None', 'thirtydayhomes' ) ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Refundable deposit', 'thirtydayhomes' ); ?></dt>
							<dd><?php echo esc_html( $deposit ? '$' . number_format_i18n( $deposit ) : __( 'None', 'thirtydayhomes' ) ); ?></dd>
						</div>
					</dl>

					<?php
					/**
					 * Enquiry form and the text-the-owner flow, built in
					 * Milestone 2 with the inquiry pipeline.
					 */
					if ( has_action( 'tdh_listing_inquiry_form' ) ) {
						do_action( 'tdh_listing_inquiry_form', $listing_id );
					} else {
						?>
						<p class="notice">
							<?php esc_html_e( 'The enquiry form arrives with the inquiry pipeline in Milestone 2.', 'thirtydayhomes' ); ?>
						</p>
						<?php
					}
					?>

					<p class="fine">
						<?php esc_html_e( 'Rules and regulations must be reviewed before sending an enquiry.', 'thirtydayhomes' ); ?>
					</p>

				</aside>

			</div>
		</div>

		<?php
	endwhile;

endif;

get_footer();
