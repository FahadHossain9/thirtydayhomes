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
		$clean_fee = (float) get_post_meta( $listing_id, '_tdh_cleaning_fee', true );
		$beds      = get_post_meta( $listing_id, '_tdh_beds', true );
		$baths     = get_post_meta( $listing_id, '_tdh_baths', true );
		$sqft      = get_post_meta( $listing_id, '_tdh_sqft', true );
		$rooms     = get_post_meta( $listing_id, '_tdh_rooms', true );
		$available = get_post_meta( $listing_id, '_tdh_available_from', true );
		$min_stay  = (int) get_post_meta( $listing_id, '_tdh_min_stay_days', true );
		$utilities = tdh_listing_utilities( $listing_id );
		$parking   = get_post_meta( $listing_id, '_tdh_parking', true );
		$backyard  = get_post_meta( $listing_id, '_tdh_backyard', true );
		$pets      = get_post_meta( $listing_id, '_tdh_pet_policy', true );
		$zip       = get_post_meta( $listing_id, '_tdh_zip', true );

		$hoods = get_the_terms( $listing_id, 'tdh_neighborhood' );
		$hood  = ( $hoods && ! is_wp_error( $hoods ) ) ? $hoods[0]->name : '';

		// Neighborhood and city, both read from the listing's own terms.
		// The city used to be typed into this template as "Pittsburgh",
		// which would have told a Cleveland renter the wrong city.
		$location = tdh_listing_location( $listing_id );
		$city     = tdh_listing_city( $listing_id );

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
		/**
		 * The "Preview — not public yet" bar, for the landlord or staff
		 * looking at a home renters cannot see. Prints nothing on a live
		 * listing. The plugin decides; this only places it.
		 *
		 * @param int $listing_id Listing being rendered.
		 */
		do_action( 'tdh_listing_preview_bar', $listing_id );
		?>

		<div class="detail">

			<a class="back" href="<?php echo esc_url( (string) get_post_type_archive_link( 'tdh_listing' ) ); ?>">
				<?php echo tdh_icon( 'arrow-left', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'Back to homes', 'thirtydayhomes' ); ?>
			</a>

			<header class="detail-title">
				<div>
					<?php if ( '' !== $location ) : ?>
						<p class="overline gold"><?php echo esc_html( $location ); ?></p>
					<?php endif; ?>
					<h1><?php the_title(); ?></h1>
					<p class="detail-location">
						<?php echo tdh_icon( 'map-pin', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php
						printf(
							/* translators: %s: ZIP code */
							esc_html__( 'Approximate location · %s', 'thirtydayhomes' ),
							esc_html( (string) $zip )
						);
						?>
					</p>
				</div>
			</header>

			<?php
			/*
			 * The photos in the landlord's order, cover first — read from the
			 * plugin, which owns the order. A listing with a cover but no
			 * attached photos (added in wp-admin) still shows its cover.
			 */
			$photo_ids = class_exists( '\TDH\Listing_Photos' ) ? \TDH\Listing_Photos::ordered( $listing_id ) : [];

			if ( ! $photo_ids && has_post_thumbnail() ) {
				$photo_ids = [ (int) get_post_thumbnail_id() ];
			}

			get_template_part(
				'template-parts/listing-gallery',
				null,
				[
					'ids'   => $photo_ids,
					'title' => get_the_title(),
					'city'  => $city,
				]
			);
			?>

			<div class="detail-grid">

				<div>

					<ul class="quick-facts">
						<?php
						/*
						 * One line (G3a design review): "2 bedrooms · 1 bathroom
						 * · 1,050 sq ft · 5 rooms", each with its icon, the number
						 * in bold and the unit in the renter's words — singular
						 * when there is one. A list of triples rather than
						 * label => value, because two homes can share a value and
						 * an array keyed by label cannot hold the icon beside it.
						 *
						 * Bathrooms come in halves: plural for anything that is
						 * not exactly one, so "1.5 bathrooms" and "1 bathroom".
						 */
						$bath_n = (float) $baths;
						$facts  = [
							[ 'bed-double', $beds ? number_format_i18n( (float) $beds ) : '', $beds ? _n( 'bedroom', 'bedrooms', (int) $beds, 'thirtydayhomes' ) : '' ],
							[ 'bath', $baths ? number_format_i18n( $bath_n, $bath_n === floor( $bath_n ) ? 0 : 1 ) : '', _n( 'bathroom', 'bathrooms', abs( $bath_n - 1.0 ) < 0.001 ? 1 : 2, 'thirtydayhomes' ) ],
							[ 'layout-grid', $sqft ? number_format_i18n( (int) $sqft ) : '', __( 'sq ft', 'thirtydayhomes' ) ],
							[ 'building-2', $rooms ? number_format_i18n( (float) $rooms ) : '', $rooms ? _n( 'room', 'rooms', (int) $rooms, 'thirtydayhomes' ) : '' ],
						];
						foreach ( $facts as list( $icon, $value, $unit ) ) :
							if ( '' === $value ) {
								continue;
							}
							?>
							<li>
								<?php
								/*
								 * Decorative: the number and the word beside
								 * it already say everything, so the icon is
								 * hidden from a screen reader rather than
								 * read out as a second, wordless "bedrooms".
								 */
								tdh_the_icon( $icon, 18 );
								?>
								<b><?php echo esc_html( (string) $value ); ?></b>
								<span><?php echo esc_html( $unit ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>

					<?php
					// A description is optional, and a heading over nothing
					// reads as a page that failed to load.
					if ( '' !== trim( wp_strip_all_tags( (string) get_the_content() ) ) ) :
						?>
						<section class="detail-section">
							<h2><?php esc_html_e( 'About this home', 'thirtydayhomes' ); ?></h2>
							<?php the_content(); ?>
						</section>
					<?php endif; ?>

					<?php
					$amenities = get_the_terms( $listing_id, 'tdh_amenity' );
					$amenities = ( $amenities && ! is_wp_error( $amenities ) ) ? $amenities : [];
					/*
					 * Parking and backyard are the landlord's own words, and
					 * "Driveway, two cars" alone in a list of amenities does not
					 * say what it is about. The label goes with them.
					 */
					$extras = array_filter(
						[
							$utilities,
							'' !== (string) $parking
								/* translators: %s: the landlord's parking description */
								? sprintf( __( 'Parking: %s', 'thirtydayhomes' ), $parking )
								: '',
							'' !== (string) $backyard
								/* translators: %s: the landlord's backyard description */
								? sprintf( __( 'Backyard: %s', 'thirtydayhomes' ), $backyard )
								: '',
						]
					);

					/*
					 * The rules are not amenities (G3a design review). With a
					 * tick in front of it "No pets" read as a promise of the
					 * opposite, so the pet policy and the minimum stay stand in
					 * their own list with a neutral mark.
					 */
					$rules = array_filter(
						[
							$pet_label,
							$min_stay > 0
								/* translators: %s: minimum stay, e.g. "30 days" or "13 weeks" */
								? sprintf( __( 'Minimum stay %s', 'thirtydayhomes' ), $stay_label )
								: '',
						]
					);

					/*
					 * Older listings carry "Utilities included" and "Pet friendly"
					 * as amenity chips from before each became its own question.
					 * Where the listing has answered the question, the answer is
					 * what shows — otherwise one home could list "Utilities not
					 * included" and "Utilities included" side by side.
					 */
					$answered = array_filter(
						[
							'Utilities included' => '' !== (string) get_post_meta( $listing_id, '_tdh_utilities_included', true ),
							'Pet friendly'       => '' !== $pet_label,
						]
					);

					$amenities = array_filter(
						$amenities,
						static fn( $term ) => ! isset( $answered[ $term->name ] )
					);

					if ( $amenities || $extras ) :
						?>
						<section class="detail-section">
							<h2><?php esc_html_e( 'Included', 'thirtydayhomes' ); ?></h2>
							<ul class="amenities">
								<?php
								foreach ( $amenities as $amenity ) {
									echo '<li>' . esc_html( $amenity->name ) . '</li>';
								}
								foreach ( $extras as $extra ) {
									echo '<li>' . esc_html( (string) $extra ) . '</li>';
								}
								?>
							</ul>
						</section>
					<?php endif; ?>

					<?php if ( $rules ) : ?>
						<section class="detail-section">
							<h2><?php esc_html_e( 'House rules', 'thirtydayhomes' ); ?></h2>
							<ul class="house-rules">
								<?php
								foreach ( $rules as $rule ) {
									echo '<li>' . esc_html( (string) $rule ) . '</li>';
								}
								?>
							</ul>
						</section>
					<?php endif; ?>

					<?php
					/*
					 * When a renter can move in, the dates already taken, and a
					 * calendar — from the plugin, which owns what "free" means.
					 * The plain box below is only the fallback for a site
					 * running this theme without the plugin.
					 */
					if ( class_exists( '\TDH\Availability' ) ) :
						get_template_part( 'template-parts/listing-availability', null, [ 'id' => $listing_id ] );
					elseif ( $available ) :
						?>
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

						<?php
						/*
						 * The map, when there is a key and a location for
						 * this home. It draws one circle around a point
						 * that has been moved on purpose — see TDH\Maps.
						 * With no key, no location, or no JavaScript, the
						 * words below stand on their own, which is why
						 * they were written before the map existed.
						 */
						$tdh_area = class_exists( '\TDH\Render' ) ? \TDH\Render::map_area( $listing_id ) : '';
						?>

						<?php if ( '' !== $tdh_area ) : ?>
							<?php echo $tdh_area; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
						<?php else : ?>
							<div class="map-box">
								<b><?php esc_html_e( 'Approximate map area', 'thirtydayhomes' ); ?></b>
								<small>
									<?php esc_html_e( 'We show the neighborhood rather than the exact address. A furnished home that is often empty should not have its address published — the owner shares it with you after you make contact.', 'thirtydayhomes' ); ?>
								</small>
							</div>
						<?php endif; ?>

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

					<?php
					/*
					 * The inquiry form, last in the main column (G3a design
					 * review). It used to share the side column with the
					 * price, which made that column too tall to pin — so the
					 * price scrolled away and, once pinned anyway, covered
					 * the form. Here the form is something a renter DOES after
					 * reading everything above it, and the side column is
					 * left short enough to stay in view with one button that
					 * jumps here.
					 */
					?>
					<section id="inquire" class="detail-section">
						<div class="enquiry-card">

							<?php
							/**
							 * The inquiry form, or the screen that replaces it once a
							 * message has been sent (task D1). The plugin owns both:
							 * the form posts to a handler, and what a renter may send
							 * is a marketplace rule, not a presentation choice.
							 *
							 * The line that used to sit below this — "Rules and
							 * regulations must be reviewed before sending an inquiry"
							 * — has gone with the placeholder. It was an instruction
							 * with nothing to follow: no rules were shown and nothing
							 * was recorded. The consent checkbox in the form is the
							 * real thing (register R24), and it stores which version
							 * of the rules was on screen when the renter agreed.
							 */
							if ( has_action( 'tdh_listing_inquiry_form' ) ) {
								do_action( 'tdh_listing_inquiry_form', $listing_id );
							} else {
								?>
								<p class="notice">
									<?php esc_html_e( 'The inquiry form is not available right now.', 'thirtydayhomes' ); ?>
								</p>
								<?php
							}
							?>

						</div>
					</section>

				</div>

				<?php
				/*
				 * The side column: the price and the fees, and one button to
				 * the form. Short on purpose — about 420px — so it can stay
				 * pinned beside the whole page (the stylesheet's .listing-side
				 * explains the three layouts that came before).
				 */
				?>
				<aside class="listing-side">

				<div class="price-box">

					<p>
						<b><?php echo esc_html( '$' . number_format_i18n( $price ) ); ?></b>
						<?php esc_html_e( '/ month', 'thirtydayhomes' ); ?>
					</p>
					<small><?php esc_html_e( 'No guest booking fee', 'thirtydayhomes' ); ?></small>

					<hr>

					<dl>
						<?php
						// The first day a renter could actually move in — later than
						// the landlord's date when the start of it is taken.
						$move_in = class_exists( '\TDH\Availability' ) ? \TDH\Availability::summary( $listing_id ) : null;
						?>
						<?php if ( $move_in ) : ?>
							<div>
								<dt><?php esc_html_e( 'Available', 'thirtydayhomes' ); ?></dt>
								<dd>
									<?php
									if ( '' === $move_in['move_in'] ) {
										esc_html_e( 'No open dates', 'thirtydayhomes' );
									} elseif ( $move_in['now'] ) {
										esc_html_e( 'Now', 'thirtydayhomes' );
									} else {
										echo esc_html( \TDH\Availability::format_day( $move_in['move_in'] ) );
									}
									?>
								</dd>
							</div>
						<?php elseif ( $available ) : ?>
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
							<dt><?php esc_html_e( 'Cleaning fee', 'thirtydayhomes' ); ?></dt>
							<dd><?php echo esc_html( $clean_fee ? '$' . number_format_i18n( $clean_fee ) : __( 'None', 'thirtydayhomes' ) ); ?></dd>
						</div>
						<?php
						// No pet-fee row on a home that takes no pets: "Pet fee:
						// None" there reads as "pets are free", the opposite.
						if ( 'no' !== $pets ) :
							?>
							<div>
								<dt><?php esc_html_e( 'Pet fee', 'thirtydayhomes' ); ?></dt>
								<dd><?php echo esc_html( $pet_fee ? '$' . number_format_i18n( $pet_fee ) : __( 'None', 'thirtydayhomes' ) ); ?></dd>
							</div>
						<?php endif; ?>
						<div>
							<dt><?php esc_html_e( 'Refundable deposit', 'thirtydayhomes' ); ?></dt>
							<dd><?php echo esc_html( $deposit ? '$' . number_format_i18n( $deposit ) : __( 'None', 'thirtydayhomes' ) ); ?></dd>
						</div>
					</dl>

					<?php
					/*
					 * The page's one call to action. A link to the form, not a
					 * second form: it steps aside while the form itself is on
					 * screen (detail.js), so one filled button is ever in view.
					 * Only when there is a form to reach.
					 */
					if ( has_action( 'tdh_listing_inquiry_form' ) ) :
						?>
						<a class="gold-btn ask-owner" href="#inquire" data-tdh-ask><?php esc_html_e( 'Ask the owner', 'thirtydayhomes' ); ?></a>
					<?php endif; ?>

				</div>

				</aside>

			</div>

			<?php
			/*
			 * On a phone the side column sits below everything, so the rent
			 * and the button ride in a bar along the bottom until the form —
			 * or the footer — is on screen. Hidden on a desktop by the
			 * stylesheet, where the side column does this job.
			 */
			if ( has_action( 'tdh_listing_inquiry_form' ) ) :
				?>
				<div class="detail-bar" data-tdh-detail-bar>
					<p>
						<b><?php echo esc_html( '$' . number_format_i18n( $price ) ); ?></b>
						<span><?php esc_html_e( '/ month', 'thirtydayhomes' ); ?></span>
					</p>
					<a class="gold-btn" href="#inquire" data-tdh-ask><?php esc_html_e( 'Ask the owner', 'thirtydayhomes' ); ?></a>
				</div>
			<?php endif; ?>
		</div>

		<?php
	endwhile;

endif;

get_footer();
