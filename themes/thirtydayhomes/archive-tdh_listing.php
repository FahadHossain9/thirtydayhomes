<?php
/**
 * Listing archive — the renter's search results.
 *
 * Theme fallback for the Elementor archive template.
 *
 * The search BOX is here and it works: TDH\Search narrows the archive by
 * neighborhood, city, ZIP or the home's name. The FILTER bar — price,
 * bedrooms, bathrooms, type, pet policy, availability, sorting — is still
 * Milestone 2, for the reason this file has always given: a control that
 * does not do what it says teaches the reviewer the feature is broken.
 *
 * @package ThirtyDayHomes
 */

defined( 'ABSPATH' ) || exit;

get_header();

if ( ! tdh_elementor_location( 'archive' ) ) :

	$found = (int) $GLOBALS['wp_query']->found_posts;

	// The plugin owns the search; the theme only asks it what was searched
	// for, so a theme swap cannot take the query logic with it.
	$q = class_exists( '\TDH\Search' ) ? \TDH\Search::term() : '';

	// Dates come from the hero, which asks for them because the approved
	// design does. Filtering by availability is Milestone 2, and the page
	// says so rather than quietly ignoring what was typed.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only search.
	$dated = isset( $_GET['start'] ) && '' !== $_GET['start'];
	?>

	<?php
	tdh_page_banner(
		[
			// City-neutral: this archive lists every city the site covers.
			'eyebrow' => __( 'Furnished monthly rentals', 'thirtydayhomes' ),
			'title'   => __( 'Find a home that fits your stay.', 'thirtydayhomes' ),
			'lead'    => __( 'Move-in ready homes for stays of 30 days and longer, close to the places you need to be.', 'thirtydayhomes' ),
		]
	);
	?>

	<div class="page-shell">

		<?php
		if ( class_exists( '\TDH\Render' ) ) {
			echo \TDH\Render::search_bar(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		?>

		<?php if ( $dated ) : ?>
			<p class="notice">
				<?php esc_html_e( 'Searching by name and place today. Filtering by move-in date arrives with the search module.', 'thirtydayhomes' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( have_posts() ) : ?>

			<div class="result-top">
				<b>
					<?php
					if ( '' !== $q ) {
						printf(
							/* translators: 1: number of homes found, 2: the renter's search term */
							esc_html( _n( '%1$s home matching “%2$s”', '%1$s homes matching “%2$s”', $found, 'thirtydayhomes' ) ),
							esc_html( number_format_i18n( $found ) ),
							esc_html( $q )
						);
					} else {
						printf(
							/* translators: %s: number of homes found */
							esc_html( _n( '%s home', '%s homes', $found, 'thirtydayhomes' ) ),
							esc_html( number_format_i18n( $found ) )
						);
					}
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

			<?php
			/*
			 * Two different empty states, because they are two different
			 * facts. "Nothing matched" with a way back is useful; telling a
			 * renter whose search missed that the site has no homes at all
			 * is simply untrue, and sends them away.
			 */
			?>
			<div class="empty">
				<?php if ( '' !== $q ) : ?>
					<h3>
						<?php
						printf(
							/* translators: %s: the renter's search term */
							esc_html__( 'No homes match “%s”', 'thirtydayhomes' ),
							esc_html( $q )
						);
						?>
					</h3>
					<p><?php esc_html_e( 'Try a neighborhood, a city, or a ZIP code — or clear the search to see every home.', 'thirtydayhomes' ); ?></p>
					<a class="secondary" href="<?php echo esc_url( (string) get_post_type_archive_link( 'tdh_listing' ) ); ?>">
						<?php esc_html_e( 'Show all homes', 'thirtydayhomes' ); ?>
					</a>
				<?php else : ?>
					<h3><?php esc_html_e( 'No homes are listed yet', 'thirtydayhomes' ); ?></h3>
					<p><?php esc_html_e( 'New furnished homes are added regularly. Check back soon, or list your own property.', 'thirtydayhomes' ); ?></p>
				<?php endif; ?>
			</div>

		<?php endif; ?>

	</div>

	<?php
endif;

get_footer();
