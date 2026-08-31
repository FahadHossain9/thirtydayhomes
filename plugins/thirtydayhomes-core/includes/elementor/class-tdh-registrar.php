<?php
/**
 * Elementor integration.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the marketplace widgets with Elementor.
 *
 * ─── WHY BOTH SHORTCODES AND WIDGETS ───────────────────────────────────
 *
 * The shortcode is the primitive. TDH\Render holds the one implementation;
 * [tdh_property_grid] calls it, and so does the widget below. Neither owns
 * markup. That keeps content portable — a page built with shortcodes still
 * renders if Elementor is deactivated, which handoff §2 requires.
 *
 * The widget exists for a different reason: the Elementor structure tree.
 * A page assembled from raw HTML in post_content collapses into a single
 * "Text Editor" node, and the client ends up editing an entire homepage
 * through one textarea. Named widgets give discrete, selectable sections —
 * "Hero Search", "Property Grid" — which is the whole point of handing a
 * page builder to a non-technical owner.
 *
 * So: shortcode for portability, widget for editability, one renderer
 * underneath so they can never disagree.
 *
 * ─── WHY ELEMENTOR FREE IS ENOUGH ──────────────────────────────────────
 *
 * Widget registration uses the same Widget_Base API in free as in Pro.
 * Pro's value is its Theme Builder, and handoff §3.1 assigns header,
 * footer and templates to the theme, which already renders them.
 */
final class Registrar {

	public const CATEGORY = 'thirtydayhomes';

	public function register(): void {
		add_action( 'elementor/elements/categories_registered', [ $this, 'register_category' ] );
		add_action( 'elementor/widgets/register', [ $this, 'register_widgets' ] );
		add_action( 'elementor/editor/after_enqueue_styles', [ $this, 'editor_styles' ] );
	}

	/**
	 * Is Elementor present and loaded?
	 */
	public static function is_active(): bool {
		return did_action( 'elementor/loaded' ) > 0;
	}

	/**
	 * @param \Elementor\Elements_Manager $manager Elements manager.
	 */
	public function register_category( $manager ): void {
		$manager->add_category(
			self::CATEGORY,
			[
				'title' => __( 'ThirtyDayHomes', 'thirtydayhomes' ),
				'icon'  => 'eicon-home-heart',
			]
		);
	}

	/**
	 * @param \Elementor\Widgets_Manager $manager Widgets manager.
	 */
	public function register_widgets( $manager ): void {
		$manager->register( new Widgets\Hero_Search() );
		$manager->register( new Widgets\Audience() );
		$manager->register( new Widgets\Property_Grid() );
		$manager->register( new Widgets\Split_Feature() );
		$manager->register( new Widgets\Owner_CTA() );

		// Whole-page widgets. These exist because the pages they cover
		// shipped as bare shortcodes with every string as a PHP default,
		// which made them uneditable by the person who owns the site —
		// handoff principle 2, and Milestone 1 acceptance test 6.
		$manager->register( new Widgets\About() );
		$manager->register( new Widgets\How_It_Works() );
	}

	/**
	 * Load the theme stylesheet inside the Elementor editor.
	 *
	 * Without it the widgets render unstyled in the editor while looking
	 * correct on the front end, which makes the editor useless for judging
	 * a change. Handoff §4 requires widgets to "render correctly in the
	 * editor and on the frontend".
	 */
	public function editor_styles(): void {

		$version = defined( 'TDH_THEME_VERSION' ) ? TDH_THEME_VERSION : TDH_VERSION;

		// Tokens first — the stylesheet is nothing but var() references, so
		// loading it alone renders every widget unstyled in the editor.
		wp_enqueue_style(
			'tdh-tokens-in-editor',
			get_template_directory_uri() . '/assets/design-tokens.css',
			[],
			$version
		);

		wp_enqueue_style(
			'tdh-theme-in-editor',
			get_stylesheet_uri(),
			[ 'tdh-tokens-in-editor' ],
			$version
		);
	}
}
