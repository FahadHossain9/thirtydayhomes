<?php
/**
 * Listing card.
 *
 * Read in the order a renter decides (G3a design review, master prompt
 * §9): the rent first, with when the home is free beside it; then the
 * name; then where it is; then the facts; then how close the hospitals
 * are; and one link at the foot. The photo above carries the badge and
 * the save button.
 *
 * Note what is deliberately absent: no star rating and no "Guest favorite"
 * badge. V1 has no review system, and the owner never sees booking volume,
 * so neither could ever be earned — a hardcoded 4.9 on every home promises
 * social proof that will never exist.
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

// When a renter could move in — from the plugin, which owns what "free"
// means. Null only on a site running this theme without it.
$tdh_avail = class_exists( '\TDH\Availability' ) ? \TDH\Availability::summary( (int) $listing_id ) : null;

// Bathrooms come in halves, and "1.5 bath" is wrong in the other direction
// from "1 baths": plural for anything that is not exactly one.
$tdh_bath_n   = (float) $baths;
$tdh_bath_one = abs( $tdh_bath_n - 1.0 ) < 0.001;
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

		<?php
		/*
		 * The rent, largest, and beside it when the home is free — a pill
		 * with words, so the answer never depends on its colour: green for
		 * now or for the dates asked, sand for a date to come, grey for
		 * none.
		 */
		?>
		<div class="card-head">
			<p class="card-price">
				<b><?php echo esc_html( '$' . number_format_i18n( $price ) ); ?></b>
				<span><?php esc_html_e( '/ month', 'thirtydayhomes' ); ?></span>
			</p>

			<?php if ( $tdh_dated ) : ?>
				<?php // This home is in these results because it is free then. ?>
				<span class="card-avail is-free"><?php esc_html_e( 'Free for your dates', 'thirtydayhomes' ); ?></span>
			<?php elseif ( $tdh_avail ) : ?>
				<span class="card-avail <?php echo '' === $tdh_avail['move_in'] ? 'is-none' : ( $tdh_avail['now'] ? 'is-now' : 'is-later' ); ?>">
					<?php echo esc_html( $tdh_avail['short'] ); ?>
				</span>
			<?php elseif ( $available ) : ?>
				<span class="card-avail is-later">
					<?php
					printf(
						/* translators: %s: date the home becomes available */
						esc_html__( 'Available %s', 'thirtydayhomes' ),
						esc_html( date_i18n( 'M j', (int) strtotime( (string) $available ) ) )
					);
					?>
				</span>
			<?php endif; ?>
		</div>

		<h3><a href="<?php echo esc_url( $tdh_link ); ?>"><?php the_title(); ?></a></h3>

		<?php if ( '' !== $location ) : ?>
			<p class="location">
				<span>
					<?php tdh_the_icon( 'map-pin', 14 ); ?>
					<?php echo esc_html( $location ); ?>
				</span>
			</p>
		<?php endif; ?>

		<?php // Each fact: the icon, the number in bold, the unit in grey. ?>
		<ul class="facts">
			<?php if ( $beds ) : ?>
				<li>
					<?php
					tdh_the_icon( 'bed-double', 14 );
					printf(
						/* translators: %s: number of bedrooms */
						esc_html( _n( '%s bed', '%s beds', (int) $beds, 'thirtydayhomes' ) ),
						'<b>' . esc_html( number_format_i18n( (float) $beds ) ) . '</b>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
					);
					?>
				</li>
			<?php endif; ?>
			<?php if ( $baths ) : ?>
				<li>
					<?php
					tdh_the_icon( 'bath', 14 );
					printf(
						/* translators: %s: number of bathrooms */
						esc_html( _n( '%s bath', '%s baths', $tdh_bath_one ? 1 : 2, 'thirtydayhomes' ) ),
						'<b>' . esc_html( number_format_i18n( $tdh_bath_n, $tdh_bath_n === floor( $tdh_bath_n ) ? 0 : 1 ) ) . '</b>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
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

		<?php
		/*
		 * One link at the foot, in words. "View home" alone, repeated down a
		 * grid, tells a screen-reader user nothing about which home they are
		 * on, so the title follows it for them.
		 */
		?>
		<a class="card-go" href="<?php echo esc_url( $tdh_link ); ?>">
			<?php esc_html_e( 'View home', 'thirtydayhomes' ); ?>
			<span class="screen-reader-text">
				<?php
				printf(
					/* translators: %s: listing title */
					esc_html__( ': %s', 'thirtydayhomes' ),
					esc_html( get_the_title() )
				);
				?>
			</span>
			<?php tdh_the_icon( 'arrow-right', 16 ); ?>
		</a>

	</div>
</article>
