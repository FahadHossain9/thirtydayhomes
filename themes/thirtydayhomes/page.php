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
		// 'narrow' because the prose below sits in the narrow shell, and the
		// heading has to share its left edge.
		tdh_page_banner(
			[
				'title' => get_the_title(),
				'width' => 'narrow',
			]
		);
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
