<?php
/**
 * Where a listing is.
 *
 * PRESENTATION ONLY. These read taxonomy terms the plugin owns and format
 * them for display; no rule about what a listing IS lives here.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * The first term name a listing holds in a taxonomy, or ''.
 */
function tdh_listing_term( int $post_id, string $taxonomy ): string {

	$terms = get_the_terms( $post_id, $taxonomy );

	if ( ! $terms || is_wp_error( $terms ) ) {
		return '';
	}

	return (string) $terms[0]->name;
}

/**
 * A listing's city.
 *
 * ─── WHY THIS IS READ, NOT WRITTEN ─────────────────────────────────────────
 *
 * Every listing card, banner and image caption used to end in the literal
 * word "Pittsburgh", typed into the template. The owner has properties in
 * Cleveland too and asked that the site be worded to scale, and a hardcoded
 * city is not a wording problem — it is a template that would tell a
 * Cleveland renter their home is in Pittsburgh.
 *
 * The city has always been real data: every listing carries a term in the
 * city taxonomy, and the importer sets it. These helpers just read it.
 */
function tdh_listing_city( int $post_id ): string {
	return tdh_listing_term( $post_id, 'tdh_city' );
}

/**
 * Neighborhood and city as one line — "Shadyside · Pittsburgh".
 *
 * Either half may be missing, and the separator goes with it: a listing
 * with no neighborhood must not render as "· Pittsburgh", and one with no
 * city must not render as "Shadyside ·". Both absent gives '', which every
 * caller already treats as "print nothing".
 *
 * @param string $separator Between the two parts when both exist.
 */
function tdh_listing_location( int $post_id, string $separator = ' · ' ): string {

	$parts = array_filter(
		[
			tdh_listing_term( $post_id, 'tdh_neighborhood' ),
			tdh_listing_city( $post_id ),
		]
	);

	return implode( $separator, $parts );
}

/**
 * What a renter pays for utilities, as one line.
 *
 * "Utilities included — Water, gas and Wi-Fi", "Some utilities included —
 * Water and trash", "Utilities not included". The answer comes from the
 * landlord's Included / Partly / Not choice; the details are their own words.
 *
 * A listing from before the choice existed has only the words, and they are
 * shown as they were written rather than guessed into an answer.
 */
function tdh_listing_utilities( int $post_id ): string {

	$answer  = (string) get_post_meta( $post_id, '_tdh_utilities_included', true );
	$details = trim( (string) get_post_meta( $post_id, '_tdh_utilities', true ) );

	$lead = [
		'yes'     => __( 'Utilities included', 'thirtydayhomes' ),
		'partial' => __( 'Some utilities included', 'thirtydayhomes' ),
		'no'      => __( 'Utilities not included', 'thirtydayhomes' ),
	][ $answer ] ?? '';

	if ( '' === $lead ) {
		return $details;
	}

	return '' !== $details ? $lead . ' — ' . $details : $lead;
}
