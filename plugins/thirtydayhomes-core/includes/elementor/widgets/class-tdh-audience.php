<?php
/**
 * Audience section widget.
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
 * Visual wrapper around [tdh_audience].
 *
 * One widget for the whole section rather than twelve loose Heading and
 * Text widgets. A card grid has a fixed shape — four cards, each with an
 * eyebrow, a title and a paragraph — and a repeater enforces that shape.
 * Loose widgets let someone delete one heading and leave a card with no
 * title, which is a layout bug the client then reports as our fault.
 */
final class Audience extends Widget_Base {

	public function get_name(): string {
		return 'tdh-audience';
	}

	public function get_title(): string {
		return __( 'Audience Cards', 'thirtydayhomes' );
	}

	public function get_icon(): string {
		return 'eicon-nested-carousel';
	}

	/** @return string[] */
	public function get_categories(): array {
		return [ Registrar::CATEGORY ];
	}

	/** @return string[] */
	public function get_keywords(): array {
		return [ 'audience', 'cards', 'who', 'travelers' ];
	}

	/**
	 * The icons a card may use.
	 *
	 * A fixed list rather than free entry: these are the Lucide icons
	 * bundled with the theme, and an unknown name renders nothing.
	 *
	 * @return array<string,string>
	 */
	private function icon_options(): array {
		return [
			''               => __( 'No icon', 'thirtydayhomes' ),
			'stethoscope'    => __( 'Stethoscope — healthcare', 'thirtydayhomes' ),
			'briefcase'      => __( 'Briefcase — business', 'thirtydayhomes' ),
			'hard-hat'       => __( 'Hard hat — trades', 'thirtydayhomes' ),
			'graduation-cap' => __( 'Graduation cap — academic', 'thirtydayhomes' ),
			'map-pin'        => __( 'Map pin — location', 'thirtydayhomes' ),
			'check'          => __( 'Check', 'thirtydayhomes' ),
		];
	}

	protected function register_controls(): void {

		$this->start_controls_section( 'heading_section', [ 'label' => __( 'Heading', 'thirtydayhomes' ) ] );

		$this->add_control(
			'eyebrow',
			[
				'label'   => __( 'Eyebrow', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Made for working travelers', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'heading',
			[
				'label'       => __( 'Heading', 'thirtydayhomes' ),
				'description' => __( 'Use &lt;br&gt; to force a line break. The break is ignored on phones, where the heading wraps naturally.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Housing that works<br>as hard as you do.', 'thirtydayhomes' ),
				'label_block' => true,
			]
		);

		$this->add_control(
			'intro',
			[
				'label'   => __( 'Intro paragraph', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXTAREA,
				'rows'    => 3,
				'default' => __( 'Whether it’s one traveler or an entire project team, find a furnished home with the space and flexibility your assignment needs.', 'thirtydayhomes' ),
			]
		);

		$this->end_controls_section();

		$this->start_controls_section( 'cards_section', [ 'label' => __( 'Cards', 'thirtydayhomes' ) ] );

		$repeater = new Repeater();

		$repeater->add_control(
			'icon',
			[
				'label'   => __( 'Icon', 'thirtydayhomes' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'stethoscope',
				'options' => $this->icon_options(),
			]
		);

		$repeater->add_control(
			'eyebrow',
			[
				'label'   => __( 'Eyebrow', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'For healthcare', 'thirtydayhomes' ),
			]
		);

		$repeater->add_control(
			'title',
			[
				'label'       => __( 'Title', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Medical professionals', 'thirtydayhomes' ),
				'label_block' => true,
			]
		);

		$repeater->add_control(
			'copy',
			[
				'label' => __( 'Description', 'thirtydayhomes' ),
				'type'  => Controls_Manager::TEXTAREA,
				'rows'  => 4,
			]
		);

		$repeater->add_control(
			'link_text',
			[
				'label'       => __( 'Link label', 'thirtydayhomes' ),
				'description' => __( 'Leave empty to hide the link on this card.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Explore housing', 'thirtydayhomes' ),
			]
		);

		$repeater->add_control(
			'link_url',
			[
				'label'       => __( 'Link', 'thirtydayhomes' ),
				'description' => __( 'Empty links to all homes. Later this can point at a filtered search once search exists.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::URL,
				'options'     => [ 'is_external', 'nofollow' ],
			]
		);

		$this->add_control(
			'cards',
			[
				'label'       => __( 'Cards', 'thirtydayhomes' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{{ title }}}',
				'default'     => Render::default_audience_cards(),
			]
		);

		$this->end_controls_section();
	}

	protected function render(): void {

		$s = $this->get_settings_for_display();

		$cards = [];
		foreach ( (array) ( $s['cards'] ?? [] ) as $card ) {
			$cards[] = [
				'icon'      => (string) ( $card['icon'] ?? '' ),
				'eyebrow'   => (string) ( $card['eyebrow'] ?? '' ),
				'title'     => (string) ( $card['title'] ?? '' ),
				'copy'      => (string) ( $card['copy'] ?? '' ),
				'link_text' => (string) ( $card['link_text'] ?? '' ),
				'link_url'  => (string) ( $card['link_url']['url'] ?? '' ),
			];
		}

		// Escaped inside Render.
		echo Render::audience( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			[
				'eyebrow' => (string) ( $s['eyebrow'] ?? '' ),
				'heading' => (string) ( $s['heading'] ?? '' ),
				'intro'   => (string) ( $s['intro'] ?? '' ),
				'cards'   => $cards ?: Render::default_audience_cards(),
			]
		);
	}
}
