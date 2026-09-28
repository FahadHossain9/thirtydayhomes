<?php
/**
 * Listing archive — the renter's search results.
 *
 * Theme fallback for the Elementor archive template.
 *
 * The search box, the filter bar (price, bedrooms, bathrooms, type, pets,
 * sort), the chips and the empty states all live in
 * template-parts/listing-results.php, shared with the city, type and
 * neighborhood archives. Availability by date is task C2.
 *
 * @package ThirtyDayHomes
 */

defined( 'ABSPATH' ) || exit;

get_header();

if ( ! tdh_elementor_location( 'archive' ) ) :

	tdh_page_banner(
		[
			// City-neutral: this archive lists every city the site covers.
			'eyebrow' => __( 'Furnished monthly rentals', 'thirtydayhomes' ),
			'title'   => __( 'Find a home that fits your stay.', 'thirtydayhomes' ),
			'lead'    => __( 'Move-in ready homes for stays of 30 days and longer, close to the places you need to be.', 'thirtydayhomes' ),
		]
	);

	get_template_part( 'template-parts/listing-results' );

endif;

get_footer();
