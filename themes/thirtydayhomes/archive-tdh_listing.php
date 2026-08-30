<?php
/**
 * Listing archive — the renter's search results.
 *
 * Theme fallback for the Elementor archive template.
 *
 * The filter bar is deliberately NOT here yet. It arrives in Milestone 2
 * with the query module — a filter bar that does not filter is worse than
 * no filter bar, because it teaches the reviewer the feature is broken.
 * The hero search already puts its terms in the URL, ready for that work.
 *
 * @package ThirtyDayHomes
 */

defined( 'ABSPATH' ) || exit;

get_header();

if ( ! tdh_elementor_location( 'archive' ) ) :

	$found = (int) $GLOBALS['wp_query']->found_posts;

	// Echo back what the renter searched for, so the page acknowledges it
	// even though filtering is not wired yet.
	$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only search.
	?>

	<?php
	tdh_page_banner(
		[
			'eyebrow' => __( 'Pittsburgh furnished rentals', 'thirtydayhomes' ),
			'title'   => __( 'Find a home that fits your stay.', 'thirtydayhomes' ),
			'lead'    => __( 'Move-in ready homes for stays of 30 days and longer, close to the places you need to be.', 'thirtydayhomes' ),
		]
	);
	?>

	<div class="page-shell">

		<?php if ( '' !== $q ) : ?>
			<p class="notice">
				<?php
				printf(
					/* translators: %s: the renter's search term */
					esc_html__( 'Showing all homes. Filtering for “%s” arrives with the search module — every home currently listed is below.', 'thirtydayhomes' ),
					esc_html( $q )
				);
				?>
			</p>
		<?php endif; ?>

		<?php if ( have_posts() ) : ?>

			<div class="result-top">
				<b>
					<?php
					printf(
						/* translators: %s: number of homes found */
						esc_html( _n( '%s home', '%s homes', $found, 'thirtydayhomes' ) ),
						esc_html( number_format_i18n( $found ) )
					);
					?>
				</b>
			</div>

			<div class="property-grid">
				<?php
				while ( have_posts() ) :
					the_post();
					get_template_part( 'template-parts/listing-card' );
				endwhile;
				?>
			</div>

			<?php
			the_posts_pagination(
				[
					'mid_size'  => 1,
					'prev_text' => __( 'Previous', 'thirtydayhomes' ),
					'next_text' => __( 'Next', 'thirtydayhomes' ),
				]
			);
			?>

		<?php else : ?>

			<div class="empty">
				<h3><?php esc_html_e( 'No homes are listed yet', 'thirtydayhomes' ); ?></h3>
				<p><?php esc_html_e( 'New furnished homes are added regularly. Check back soon, or list your own property.', 'thirtydayhomes' ); ?></p>
			</div>

		<?php endif; ?>

	</div>

	<?php
endif;

get_footer();
