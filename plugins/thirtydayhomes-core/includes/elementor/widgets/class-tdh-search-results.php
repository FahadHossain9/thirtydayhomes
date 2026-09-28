<?php
/**
 * Elementor widget: the search results band.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use TDH\Elementor\Registrar;
use TDH\Render;

defined( 'ABSPATH' ) || exit;

/**
 * The homes a renter is looking at, with the search bar and the filters.
 *
 * ─── WHAT THIS WIDGET DOES NOT DECIDE ──────────────────────────────────────
 *
 * Which homes appear. That is the plugin's, worked out by TDH\Search from
 * the URL before any widget runs, and it stays there on purpose: a "how
 * many" or "sort by" control here would put marketplace behaviour inside a
 * page layout, which the handoff forbids in as many words ("Do not store
 * critical structured data inside Elementor page JSON"). It would also not
 * work — the query has already run by the time a widget renders.
 *
 * The controls are therefore the ones a layout may honestly own: what the
 * band is called, and which of its parts are shown.
 *
 * The widget renders the theme's own results template rather than a copy of
 * it, so a page built in Elementor and `/homes/` cannot drift apart.
 */
final class Search_Results extends Widget_Base {

	public function get_name(): string {
		return 'tdh-search-results';
	}

	public function get_title(): string {
		return __( 'Search results', 'thirtydayhomes' );
	}

	public function get_icon(): string {
		return 'eicon-posts-grid';
	}

	/** @return string[] */
	public function get_categories(): array {
		return [ Registrar::CATEGORY ];
	}

	/** @return string[] */
	public function get_keywords(): array {
		return [ 'search', 'results', 'filters', 'homes', 'listings', 'map' ];
	}

	protected function register_controls(): void {

		$this->start_controls_section(
			'heading_section',
			[ 'label' => __( 'Heading', 'thirtydayhomes' ) ]
		);

		$this->add_control(
			'heading',
			[
				'label'       => __( 'Heading', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => '',
				'placeholder' => __( 'Leave empty for no heading', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'intro',
			[
				'label'       => __( 'Intro line', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 2,
				'default'     => '',
				'placeholder' => __( 'Optional sentence under the heading', 'thirtydayhomes' ),
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'parts_section',
			[ 'label' => __( 'What to show', 'thirtydayhomes' ) ]
		);

		$this->add_control(
			'show_search',
			[
				'label'        => __( 'Search box', 'thirtydayhomes' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			]
		);

		$this->add_control(
			'show_filters',
			[
				'label'        => __( 'Filters', 'thirtydayhomes' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'Price, dates, hospital, bedrooms and the rest.', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'show_view_toggle',
			[
				'label'        => __( 'List and Map buttons', 'thirtydayhomes' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'Shown only when a Google Maps key is set up.', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'per_page',
			[
				'label'       => __( 'Homes per page', 'thirtydayhomes' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'max'         => 48,
				'default'     => 0,
				'description' => __( 'Leave at 0 for the site setting. On Find a home itself the page decides, so this is ignored there.', 'thirtydayhomes' ),
			]
		);

		$this->end_controls_section();
	}

	protected function render(): void {

		$s = $this->get_settings_for_display();

		echo Render::search_results( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Render.
			[
				'heading'          => (string) ( $s['heading'] ?? '' ),
				'intro'            => (string) ( $s['intro'] ?? '' ),
				'show_search'      => 'yes' === ( $s['show_search'] ?? 'yes' ),
				'show_filters'     => 'yes' === ( $s['show_filters'] ?? 'yes' ),
				'show_view_toggle' => 'yes' === ( $s['show_view_toggle'] ?? 'yes' ),
				'per_page'         => (int) ( $s['per_page'] ?? 0 ),
			]
		);
	}
}
