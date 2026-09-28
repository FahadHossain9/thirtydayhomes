<?php
/**
 * One property type's homes — /property-type/apartment/.
 *
 * The same search page as /homes/, narrowed to the type. See
 * taxonomy-tdh_city.php for why these archives share one part.
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
			/* translators: %s: property type name */
			'title'   => sprintf( __( '%s homes for stays of 30 days and longer', 'thirtydayhomes' ), $tdh_name ),
			'lead'    => __( 'Move-in ready and close to the places you need to be.', 'thirtydayhomes' ),
		]
	);

	get_template_part( 'template-parts/listing-results' );

endif;

get_footer();
