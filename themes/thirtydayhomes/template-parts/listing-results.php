<?php
/**
 * The search results: bar, filters, count, cards, pages — and what to say
 * when nothing matched.
 *
 * Shared by the listing archive and the city, property type and
 * neighborhood archives, so a renter filtering inside /city/pittsburgh/
 * gets the same page as on /homes/. The plugin owns every decision about
 * WHICH homes appear (TDH\Search); this part only lays them out.
 *
 * The stay dates from the hero are honoured by Search and shown inside the
 * filter bar, so there is nothing about dates to say here.
 *
 * Settings (task C5). The Elementor widget and the shortcode both render
 * THIS file rather than a copy of it, passing what the editor chose. Which
 * homes appear is never one of those choices: that is the plugin's, and a
 * setting that could change it would put marketplace behaviour inside a
 * page layout, which the contract forbids.
 *
 * @package ThirtyDayHomes
 *
 * @var array{heading?:string,intro?:string,show_search?:bool,show_filters?:bool,show_view_toggle?:bool} $args
 */

defined( 'ABSPATH' ) || exit;

$tdh_set = wp_parse_args(
	is_array( $args ?? null ) ? $args : [],
	[
		'heading'          => '',
		'intro'            => '',
		'show_search'      => true,
		'show_filters'     => true,
		'show_view_toggle' => true,
	]
);

$tdh_found  = (int) $GLOBALS['wp_query']->found_posts;
$tdh_q      = class_exists( '\TDH\Search' ) ? \TDH\Search::term() : '';
$tdh_active = class_exists( '\TDH\Search' ) ? \TDH\Search::active() : [];
$tdh_view   = class_exists( '\TDH\Search' ) ? \TDH\Search::view() : 'list';

?>

<div class="page-shell">

	<?php if ( '' !== (string) $tdh_set['heading'] || '' !== (string) $tdh_set['intro'] ) : ?>
		<div class="results-intro">
			<?php if ( '' !== (string) $tdh_set['heading'] ) : ?>
				<h2><?php echo esc_html( (string) $tdh_set['heading'] ); ?></h2>
			<?php endif; ?>
			<?php if ( '' !== (string) $tdh_set['intro'] ) : ?>
				<p><?php echo esc_html( (string) $tdh_set['intro'] ); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php
	if ( class_exists( '\TDH\Render' ) ) {
		if ( $tdh_set['show_search'] ) {
			echo \TDH\Render::search_bar(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		if ( $tdh_set['show_filters'] ) {
			echo \TDH\Render::filter_bar(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
	}
	?>

	<?php if ( have_posts() ) : ?>

		<div class="result-top">
			<b><?php echo esc_html( class_exists( '\TDH\Search' ) ? \TDH\Search::summary( $tdh_found ) : (string) $tdh_found ); ?></b>
			<?php
			if ( $tdh_set['show_view_toggle'] && class_exists( '\TDH\Render' ) ) {
				echo \TDH\Render::view_toggle(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			}
			?>
		</div>

		<?php
		/*
		 * The cards are rendered either way, because the map is an
		 * addition and not a replacement: it needs their ids, it can fail,
		 * and a keyboard or screen-reader user is better served by the
		 * list. In map view the list is hidden from sight but still read.
		 */
		$tdh_ids = [];
		?>

		<div class="property-grid<?php echo \TDH\Search::VIEW_MAP === $tdh_view ? ' is-behind-map' : ''; ?>">
			<?php
			while ( have_posts() ) :
				the_post();
				$tdh_ids[] = (int) get_the_ID();
				get_template_part( 'template-parts/listing-card' );
			endwhile;
			?>
		</div>

		<?php
		if ( \TDH\Search::VIEW_MAP === $tdh_view && class_exists( '\TDH\Render' ) ) {
			echo \TDH\Render::map_panel( $tdh_ids ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		?>

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
		 * Two different empty states, because they are two different facts.
		 * "Nothing matched these filters" with the filters right there to
		 * loosen is useful; telling a renter whose search missed that the
		 * site has no homes at all is simply untrue, and sends them away.
		 */
		?>
		<div class="empty">
			<?php if ( '' !== $tdh_q || $tdh_active ) : ?>
				<h3><?php echo esc_html( \TDH\Search::empty_sentence() ); ?></h3>
				<p><?php esc_html_e( 'Loosen a filter, or clear them all to see every home.', 'thirtydayhomes' ); ?></p>

				<?php
				/*
				 * When the search was "within N miles of a hospital", the
				 * most useful next step is a bigger N, and naming it is
				 * kinder than telling a renter to work it out. Offered as
				 * a primary action because it is the one most likely to
				 * end the search well.
				 */
				$tdh_wider = \TDH\Search::wider_url();
				?>
				<?php if ( '' !== $tdh_wider ) : ?>
					<a class="primary" href="<?php echo esc_url( $tdh_wider ); ?>">
						<?php echo esc_html( \TDH\Search::wider_label() ); ?>
					</a>
				<?php endif; ?>

				<?php echo \TDH\Render::filter_chips(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
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
