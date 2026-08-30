<?php
/**
 * Fallback template.
 *
 * Deliberately minimal. Real page composition happens in Elementor
 * templates; this exists so WordPress always has something valid to
 * render, and so the theme passes its own requirements check.
 *
 * @package ThirtyDayHomes
 */

defined( 'ABSPATH' ) || exit;

get_header();

if ( ! tdh_elementor_location( 'single' ) && ! tdh_elementor_location( 'archive' ) ) :
	?>
	<div class="tdh-container">
		<?php if ( have_posts() ) : ?>

			<?php
			while ( have_posts() ) :
				the_post();
				?>
				<article <?php post_class(); ?>>
					<h1><?php the_title(); ?></h1>
					<?php the_content(); ?>
				</article>
				<?php
			endwhile;

			the_posts_pagination(
				[
					'mid_size'  => 1,
					'prev_text' => __( 'Previous', 'thirtydayhomes' ),
					'next_text' => __( 'Next', 'thirtydayhomes' ),
				]
			);
			?>

		<?php else : ?>

			<div class="tdh-state">
				<p class="tdh-state__title"><?php esc_html_e( 'Nothing here yet', 'thirtydayhomes' ); ?></p>
				<p><?php esc_html_e( 'There is no content to show on this page.', 'thirtydayhomes' ); ?></p>
			</div>

		<?php endif; ?>
	</div>
	<?php
endif;

get_footer();
