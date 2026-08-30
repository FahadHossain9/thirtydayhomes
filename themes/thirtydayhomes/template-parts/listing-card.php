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

$hoods = get_the_terms( $listing_id, 'tdh_neighborhood' );
$hood  = ( $hoods && ! is_wp_error( $hoods ) ) ? $hoods[0]->name : '';

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
?>
<article class="property-card">

	<div class="property-img">
		<a href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
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

		<?php if ( $hood || '' !== (string) $rating ) : ?>
			<p class="location">
				<?php if ( $hood ) : ?>
					<span>
						<?php tdh_the_icon( 'map-pin', 14 ); ?>
						<?php echo esc_html( $hood ); ?><?php esc_html_e( ', Pittsburgh', 'thirtydayhomes' ); ?>
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

		<h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>

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
				<?php if ( $available ) : ?>
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
			<a class="card-go" href="<?php the_permalink(); ?>">
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
