<?php
/**
 * Listing card — ported from the approved prototype.
 *
 * Note what is deliberately absent: no star rating and no "Guest favorite"
 * badge. Both were in the prototype and both are removed. V1 has no review
 * system, and the owner never sees booking volume, so neither could ever
 * be earned — a hardcoded 4.9 on every home promises social proof that
 * will never exist. See DEVELOPMENT_PLAN.md §6.1.
 *
 * The hospital band is kept as a slot. The proximity module fills it in
 * Milestone 2; until then it stays empty rather than showing a made-up
 * distance, because a wrong number is worse than no number.
 *
 * @package ThirtyDayHomes
 */

defined( 'ABSPATH' ) || exit;

$listing_id = get_the_ID();
$price      = (float) get_post_meta( $listing_id, '_tdh_price_monthly', true );
$beds       = get_post_meta( $listing_id, '_tdh_beds', true );
$baths      = get_post_meta( $listing_id, '_tdh_baths', true );
$pets       = get_post_meta( $listing_id, '_tdh_pet_policy', true );
$available  = get_post_meta( $listing_id, '_tdh_available_from', true );

// "Shadyside, Pittsburgh" — the city read from the listing rather than
// typed here, so a Cleveland home says Cleveland.
$location = tdh_listing_location( $listing_id, ', ' );

$pet_label = [
	'yes'        => __( 'Pets', 'thirtydayhomes' ),
	'considered' => __( 'Pets considered', 'thirtydayhomes' ),
	'no'         => __( 'No pets', 'thirtydayhomes' ),
][ $pets ] ?? '';

// An editorial badge set by staff wins. With none, fall back to something
// derivable from real data rather than inventing a claim.
$badge  = (string) get_post_meta( $listing_id, '_tdh_badge', true );
$is_new = ( time() - (int) get_post_time( 'U', true, $listing_id ) ) < WEEK_IN_SECONDS;

if ( '' === $badge && $is_new ) {
	$badge = __( 'New this week', 'thirtydayhomes' );
}

// Empty on every real listing until a review system exists. The block is
// skipped entirely rather than printing a zero.
$rating = get_post_meta( $listing_id, '_tdh_rating', true );

/*
 * The stay, when the renter searched with dates (task C2).
 *
 * Every card on the page is a home that is free for those dates, so the
 * card says so instead of repeating the first free day, which would read
 * as a different date from the one just asked for. The dates travel on the
 * link too: the property page can then show the stay against its calendar,
 * and the Back button returns to the same search.
 */
$tdh_stay = [];

if ( class_exists( '\TDH\Search' ) ) {
	$tdh_stay = array_intersect_key(
		\TDH\Search::args(),
		array_flip(
			[
				\TDH\Search::START,
				\TDH\Search::END,
				// The chosen hospital travels too (C3), so the property
				// page can keep measuring to the same one. Price and
				// bedroom filters do not: they change nothing there.
				\TDH\Search::FACILITY,
				\TDH\Search::WITHIN,
			]
		)
	);
}

$tdh_dated = isset( $tdh_stay[ \TDH\Search::START ] );
$tdh_link  = (string) get_permalink();

if ( $tdh_stay ) {
	$tdh_link = add_query_arg( array_map( 'rawurlencode', $tdh_stay ), $tdh_link );
}
?>
<article class="property-card">

	<div class="property-img">
		<a href="<?php echo esc_url( $tdh_link ); ?>" tabindex="-1" aria-hidden="true">
			<?php if ( has_post_thumbnail() ) : ?>
				<?php the_post_thumbnail( 'tdh-card', [ 'alt' => '' ] ); ?>
			<?php else : ?>
				<span class="placeholder"><?php esc_html_e( 'Photo coming soon', 'thirtydayhomes' ); ?></span>
			<?php endif; ?>
		</a>

		<?php if ( '' !== $badge ) : ?>
			<span class="tag"><?php echo esc_html( $badge ); ?></span>
		<?php endif; ?>

		<?php
		/*
		 * Saved homes. aria-pressed carries the state, so a screen reader
		 * announces "Save this home, not pressed" rather than leaving the
		 * toggle silent. Printed hidden and revealed by the script, because
		 * a save button that cannot save is worse than no button at all.
		 */
		?>
		<button
			type="button"
			class="heart"
			data-tdh-save="<?php echo esc_attr( (string) $listing_id ); ?>"
			aria-pressed="false"
			hidden
		>
			<span class="screen-reader-text">
				<?php
				printf(
					/* translators: %s: listing title */
					esc_html__( 'Save %s', 'thirtydayhomes' ),
					esc_html( get_the_title() )
				);
				?>
			</span>
			<?php tdh_the_icon( 'heart', 18 ); ?>
		</button>
	</div>

	<div class="property-body">

		<?php if ( '' !== $location || '' !== (string) $rating ) : ?>
			<p class="location">
				<?php if ( '' !== $location ) : ?>
					<span>
						<?php tdh_the_icon( 'map-pin', 14 ); ?>
						<?php echo esc_html( $location ); ?>
					</span>
				<?php endif; ?>

				<?php if ( '' !== (string) $rating ) : ?>
					<span class="rating">
						<?php tdh_the_icon( 'star', 13 ); ?>
						<?php echo esc_html( number_format_i18n( (float) $rating, 1 ) ); ?>
					</span>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<h3><a href="<?php echo esc_url( $tdh_link ); ?>"><?php the_title(); ?></a></h3>

		<ul class="facts">
			<?php if ( $beds ) : ?>
				<li>
					<?php
					tdh_the_icon( 'bed-double', 14 );
					printf(
						/* translators: %s: number of bedrooms */
						esc_html( _n( '%s bed', '%s beds', (int) $beds, 'thirtydayhomes' ) ),
						esc_html( number_format_i18n( (float) $beds ) )
					);
					?>
				</li>
			<?php endif; ?>
			<?php if ( $baths ) : ?>
				<li>
					<?php
					$bath_decimals = ( (float) $baths === floor( (float) $baths ) ) ? 0 : 1;
					tdh_the_icon( 'bath', 14 );
					printf(
						/* translators: %s: number of bathrooms */
						esc_html( _n( '%s bath', '%s baths', (int) $baths, 'thirtydayhomes' ) ),
						esc_html( number_format_i18n( (float) $baths, $bath_decimals ) )
					);
					?>
				</li>
			<?php endif; ?>
			<?php if ( $pet_label ) : ?>
				<li>
					<?php tdh_the_icon( 'paw-print', 14 ); ?>
					<?php echo esc_html( $pet_label ); ?>
				</li>
			<?php endif; ?>
		</ul>

		<?php
		/**
		 * Nearest medical facility band.
		 *
		 * @param int $listing_id Listing being rendered.
		 */
		do_action( 'tdh_listing_card_proximity', $listing_id );
		?>

		<div class="card-foot">
			<div class="card-price">
				<p>
					<b><?php echo esc_html( '$' . number_format_i18n( $price ) ); ?></b>
					<?php esc_html_e( '/ month', 'thirtydayhomes' ); ?>
				</p>
				<?php if ( $tdh_dated ) : ?>
					<?php // This home is in these results because it is free then. ?>
					<p class="free-for-dates"><?php esc_html_e( 'Free for your dates', 'thirtydayhomes' ); ?></p>
				<?php elseif ( class_exists( '\TDH\Availability' ) ) : ?>
					<?php // The first day a renter could move in: "Available now", "Available 11 Nov". ?>
					<p><?php echo esc_html( \TDH\Availability::summary( (int) $listing_id )['short'] ); ?></p>
				<?php elseif ( $available ) : ?>
					<p>
						<?php
						printf(
							/* translators: %s: date the home becomes available */
							esc_html__( 'Available %s', 'thirtydayhomes' ),
							esc_html( date_i18n( 'M j', (int) strtotime( (string) $available ) ) )
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<?php
			/*
			 * The prototype used a bare <button> holding only an arrow. A
			 * screen reader announces that as "button" and nothing else, and
			 * it cannot be opened in a new tab. It is a link to this home, so
			 * it is a link — with the title as its accessible name, since
			 * "Read more" repeated down a grid tells a screen-reader user
			 * nothing about which home they are on.
			 */
			?>
			<a class="card-go" href="<?php echo esc_url( $tdh_link ); ?>">
				<span class="screen-reader-text">
					<?php
					printf(
						/* translators: %s: listing title */
						esc_html__( 'View %s', 'thirtydayhomes' ),
						esc_html( get_the_title() )
					);
					?>
				</span>
				<?php tdh_the_icon( 'arrow-right', 16 ); ?>
			</a>
		</div>

	</div>
</article>
