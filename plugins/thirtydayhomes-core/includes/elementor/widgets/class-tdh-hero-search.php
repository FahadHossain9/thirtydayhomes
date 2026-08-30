<?php
/**
 * Hero search widget.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Repeater;
use Elementor\Widget_Base;
use TDH\Elementor\Registrar;
use TDH\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Visual wrapper around [tdh_hero_search].
 *
 * Holds no markup — it turns a controls panel into the arguments
 * TDH\Render::hero_search() already accepts.
 *
 * The search FIELDS are deliberately not client-editable: the form posts to
 * the listing archive and the archive reads those parameter names, so a
 * renamed field would break search with no visible error.
 */
final class Hero_Search extends Widget_Base {

	public function get_name(): string {
		return 'tdh-hero-search';
	}

	public function get_title(): string {
		return __( 'Hero Search', 'thirtydayhomes' );
	}

	public function get_icon(): string {
		return 'eicon-search-results';
	}

	/** @return string[] */
	public function get_categories(): array {
		return [ Registrar::CATEGORY ];
	}

	/** @return string[] */
	public function get_keywords(): array {
		return [ 'hero', 'search', 'banner', 'home' ];
	}

	protected function register_controls(): void {

		$this->start_controls_section( 'content', [ 'label' => __( 'Content', 'thirtydayhomes' ) ] );

		$this->add_control(
			'eyebrow',
			[
				'label'   => __( 'Eyebrow', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Furnished homes · 30+ day stays', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'heading',
			[
				'label'       => __( 'Headline', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Stay a while.', 'thirtydayhomes' ),
				'label_block' => true,
			]
		);

		$this->add_control(
			'heading_accent',
			[
				'label'       => __( 'Headline, second line', 'thirtydayhomes' ),
				'description' => __( 'Gold italic, beneath the headline. Leave empty to omit.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Feel at home.', 'thirtydayhomes' ),
				'label_block' => true,
			]
		);

		$this->add_control(
			'lead',
			[
				'label'   => __( 'Lead paragraph', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXTAREA,
				'rows'    => 3,
				'default' => __( 'Move-in ready homes near the places that matter. Flexible monthly stays for traveling professionals, families, and everyone in between.', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'image',
			[
				'label'       => __( 'Background photograph', 'thirtydayhomes' ),
				'description' => __( 'Wide landscape, at least 1600px across. Empty uses the theme default.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::MEDIA,
			]
		);

		$this->end_controls_section();

		$this->start_controls_section( 'search', [ 'label' => __( 'Search form', 'thirtydayhomes' ) ] );

		$this->add_control(
			'button_text',
			[
				'label'   => __( 'Button label', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Search homes', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'where_placeholder',
			[
				'label'   => __( 'Location placeholder', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Neighborhood, ZIP, or hospital', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'require_dates',
			[
				'label'        => __( 'Require both dates', 'thirtydayhomes' ),
				'description'  => __( 'Keeps the button dimmed until a start and end date are chosen.', 'thirtydayhomes' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			]
		);

		$this->end_controls_section();

		$this->start_controls_section( 'trust', [ 'label' => __( 'Trust row', 'thirtydayhomes' ) ] );

		$repeater = new Repeater();

		$repeater->add_control(
			'text',
			[
				'label'   => __( 'Text', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Fully furnished', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'trust_items',
			[
				'label'       => __( 'Items', 'thirtydayhomes' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{{ text }}}',
				'default'     => [
					[ 'text' => __( 'Fully furnished', 'thirtydayhomes' ) ],
					[ 'text' => __( 'Utilities included', 'thirtydayhomes' ) ],
					[ 'text' => __( 'Reviewed before listing', 'thirtydayhomes' ) ],
				],
			]
		);

		$this->end_controls_section();
	}

	protected function render(): void {

		$s = $this->get_settings_for_display();

		$trust = [];
		foreach ( (array) ( $s['trust_items'] ?? [] ) as $item ) {
			if ( ! empty( $item['text'] ) ) {
				$trust[] = $item['text'];
			}
		}

		// Escaped inside Render. These are the widget's own controls, not
		// user-submitted request data.
		echo Render::hero_search( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			[
				'eyebrow'       => (string) ( $s['eyebrow'] ?? '' ),
				'heading'       => (string) ( $s['heading'] ?? '' ),
				'accent'        => (string) ( $s['heading_accent'] ?? '' ),
				'lead'          => (string) ( $s['lead'] ?? '' ),
				'image'         => (string) ( $s['image']['url'] ?? '' ),
				'button_text'   => (string) ( $s['button_text'] ?? '' ),
				'placeholder'   => (string) ( $s['where_placeholder'] ?? '' ),
				'require_dates' => 'yes' === ( $s['require_dates'] ?? 'yes' ),
				'trust'         => $trust,
				// Unique per instance, so two heroes on one page do not
				// collide on the aria-describedby id.
				'uid'           => 'w-' . $this->get_id(),
			]
		);
	}
}
