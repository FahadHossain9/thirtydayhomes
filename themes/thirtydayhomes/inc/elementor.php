<?php
/**
 * Elementor integration.
 *
 * Elementor free is the editing layer. It is NOT required — every template
 * renders without it — and Elementor Pro is deliberately not used: handoff
 * §3.1 puts header, footer and templates in this theme, which is precisely
 * what Pro's Theme Builder would otherwise provide.
 *
 * The marketplace blocks are shortcodes registered by the CORE PLUGIN, not
 * widgets registered here, so the content a client composes is not locked
 * inside Elementor's own data.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Register the page templates that live in elementor/.
 *
 * WordPress only scans the theme ROOT for "Template Name:" headers — a
 * template in a subdirectory is invisible to it, however correct the file
 * is. Themes that keep templates in folders have to declare them, so this
 * filter is not optional bookkeeping; without it the templates simply do
 * not appear in the Page Attributes dropdown.
 *
 * @param array<string,string> $templates Registered templates.
 *
 * @return array<string,string>
 */
function tdh_register_page_templates( array $templates ): array {

	$templates['elementor/tpl-full-width.php'] = __( 'Full Width', 'thirtydayhomes' );
	$templates['elementor/tpl-canvas.php']     = __( 'Canvas', 'thirtydayhomes' );

	return $templates;
}
add_filter( 'theme_page_templates', 'tdh_register_page_templates' );

/**
 * Register Elementor theme locations.
 *
 * Only fires if Elementor Pro is ever installed. Harmless without it, and
 * it means a client who later buys Pro gains theme templates with no code
 * change on our side.
 *
 * @param object $manager Elementor Pro locations manager.
 */
function tdh_register_elementor_locations( $manager ): void {
	$manager->register_all_core_location();
}
add_action( 'elementor/theme/register_locations', 'tdh_register_elementor_locations' );

/**
 * Is an Elementor theme template rendering this location?
 *
 * Always false on a free install, so the theme renders. See above.
 */
function tdh_elementor_location( string $location ): bool {
	return function_exists( 'elementor_theme_do_location' )
		&& elementor_theme_do_location( $location );
}

/**
 * Was this page composed in the Elementor editor?
 */
function tdh_is_built_with_elementor( int $post_id ): bool {

	if ( ! $post_id || ! did_action( 'elementor/loaded' ) ) {
		return false;
	}

	return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true );
}

/**
 * Has anyone actually put content on this page?
 *
 * True for editor content — including a bare shortcode — and for an
 * Elementor layout. Decides whether a template hands over to what the
 * client composed or falls back to its own hardcoded sections.
 *
 * The fallback is not dead code: it is what renders on a fresh install
 * before anyone has composed anything, and what the site keeps showing if
 * a layout is ever emptied by accident.
 */
function tdh_page_has_content( int $post_id ): bool {

	if ( ! $post_id ) {
		return false;
	}

	if ( tdh_is_built_with_elementor( $post_id ) ) {
		return true;
	}

	$post = get_post( $post_id );

	return $post instanceof WP_Post && '' !== trim( (string) $post->post_content );
}
