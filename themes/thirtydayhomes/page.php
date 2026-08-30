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
