<?php
/**
 * Theme supports, menus and image sizes.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Theme supports.
 */
function tdh_theme_setup(): void {

	load_theme_textdomain( 'thirtydayhomes', get_template_directory() . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'editor-styles' );

	add_theme_support(
		'html5',
		[ 'search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ]
	);

	add_theme_support(
		'custom-logo',
		[
			'height'      => 59,
			'width'       => 51,
			'flex-height' => true,
			'flex-width'  => true,
		]
	);

	register_nav_menus(
		[
			'primary' => __( 'Primary Navigation', 'thirtydayhomes' ),
			'footer'  => __( 'Footer Navigation', 'thirtydayhomes' ),
		]
	);

	// Listing gallery sizes. Cropped, so cards never jump as images load.
	add_image_size( 'tdh-card', 640, 480, true );
	add_image_size( 'tdh-gallery', 1300, 760, true );
	add_image_size( 'tdh-thumb', 160, 120, true );
}
add_action( 'after_setup_theme', 'tdh_theme_setup' );

/**
 * Content width, used by embeds and wide alignments.
 */
function tdh_content_width(): void {
	$GLOBALS['content_width'] = 1240;
}
add_action( 'after_setup_theme', 'tdh_content_width', 0 );
