<?php
/**
 * Template Name: Full Width
 *
 * Header and footer, but no content container — the page fills the viewport
 * edge to edge and each section manages its own gutters.
 *
 * This is the template for pages composed with shortcodes or Elementor,
 * because our sections (.hero, .section, .audience, .owner-cta) already
 * carry their own padding. Wrapping them in the default narrow container
 * would double the gutters and break the full-bleed hero.
 *
 * @package ThirtyDayHomes
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();
	the_content();
endwhile;

get_footer();
