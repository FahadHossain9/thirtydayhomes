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
		<div class="page-shell narrow">

			<div class="page-head">
				<p class="overline gold"><?php bloginfo( 'name' ); ?></p>
				<h1><?php the_title(); ?></h1>
			</div>

			<div class="prose">
				<?php the_content(); ?>
			</div>

		</div>
		<?php
	endwhile;

endif;

get_footer();
