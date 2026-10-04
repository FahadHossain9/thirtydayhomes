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

		/*
		 * A legal page says when it last changed (G3b), from the page's
		 * own modified date, so an edit in wp-admin moves it by itself.
		 * The plugin's Legal_Pages decides the rest of how these print.
		 */
		$tdh_legal = in_array( (string) get_post_meta( $page_id, '_tdh_seed_key', true ), [ 'terms', 'privacy', 'fair-housing' ], true );
		?>

		<div class="page-shell narrow">
			<?php if ( $tdh_legal ) : ?>
				<p class="page-updated">
					<?php
					printf(
						/* translators: %s: a date */
						esc_html__( 'Last updated %s', 'thirtydayhomes' ),
						'<time datetime="' . esc_attr( (string) get_the_modified_date( 'c' ) ) . '">' . esc_html( (string) get_the_modified_date() ) . '</time>'
					);
					?>
				</p>
			<?php endif; ?>
			<div class="prose">
				<?php the_content(); ?>
			</div>
		</div>
		<?php
	endwhile;

endif;

get_footer();
