<?php
/**
 * Standard page.
 *
 * @package ThirtyDayHomes
 */

defined( 'ABSPATH' ) || exit;

get_header();

if ( ! tdh_elementor_location( 'single' ) ) :

	while ( have_posts() ) :
		the_post();

		// Some pages own the whole page: no site name, no page title, no
		// prose column. Their shortcode renders its own layout.
		if ( tdh_is_full_layout_page() ) {
			the_content();
			continue;
		}
		?>
		<?php
		// Full-bleed, so it sits outside the padded shell below.
		/*
		 * A page may carry its own banner headline and lead. Without them
		 * the banner falls back to the page title — fine for Terms, wrong
		 * for About, where the design opens on a sentence rather than the
		 * one-word label the menu needs.
		 *
		 * 'narrow' because the prose below sits in the narrow shell, and
		 * the heading has to share its left edge.
		 */
		$page_id = get_the_ID();

		/*
		 * A wide-body page brings its own full-bleed sections, so the banner
		 * takes the standard container to line up with them, and the content
		 * is printed without the prose column.
		 */
		$wide = tdh_is_wide_body_page();

		tdh_page_banner(
			[
				'title' => (string) ( get_post_meta( $page_id, '_tdh_headline', true ) ?: get_the_title() ),
				'lead'  => (string) get_post_meta( $page_id, '_tdh_lead', true ),
				'width' => $wide ? 'wide' : 'narrow',
			]
		);

		if ( $wide ) {
			the_content();
			continue;
		}
		?>

		<div class="page-shell narrow">
			<div class="prose">
				<?php the_content(); ?>
			</div>
		</div>
		<?php
	endwhile;

endif;

get_footer();
