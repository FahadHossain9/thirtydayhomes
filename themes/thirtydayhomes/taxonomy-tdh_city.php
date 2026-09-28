<?php
/**
 * A city's homes — /city/pittsburgh/.
 *
 * The same search page as /homes/, narrowed to the city: the filter bar,
 * chips and empty states are shared through template-parts/listing-results
 * and the plugin applies the same query rules (TDH\Search). Before this
 * template the city archive fell through to the bare fallback and listed
 * post bodies.
 *
 * @package ThirtyDayHomes
 */

defined( 'ABSPATH' ) || exit;

get_header();

if ( ! tdh_elementor_location( 'archive' ) ) :

	$tdh_term = get_queried_object();
	$tdh_name = $tdh_term instanceof WP_Term ? $tdh_term->name : '';

	tdh_page_banner(
		[
			'eyebrow' => __( 'Furnished monthly rentals', 'thirtydayhomes' ),
			/* translators: %s: city name */
			'title'   => sprintf( __( 'Furnished homes in %s', 'thirtydayhomes' ), $tdh_name ),
			'lead'    => __( 'Move-in ready homes for stays of 30 days and longer, close to the places you need to be.', 'thirtydayhomes' ),
		]
	);

	get_template_part( 'template-parts/listing-results' );

endif;

get_footer();
