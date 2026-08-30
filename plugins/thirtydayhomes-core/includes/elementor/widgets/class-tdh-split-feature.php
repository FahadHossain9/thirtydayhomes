<?php
/**
 * Split feature widget.
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
 * Visual wrapper around [tdh_split_feature].
 *
 * Photograph with a floating badge on one side, a short benefit list on
 * the other. One widget for the pair, because the badge is positioned
 * against the photograph — split them into separate widgets and the badge
 * has nothing to anchor to.
 */
final class Split_Feature extends Widget_Base {

	public function get_name(): string {
		return 'tdh-split-feature';
	}

	public function get_title(): string {
		return __( 'Split Feature', 'thirtydayhomes' );
	}

	public function get_icon(): string {
		return 'eicon-image-box';
	}

	/** @return string[] */
	public function get_categories(): array {
		return [ Registrar::CATEGORY ];
	}

	/** @return string[] */
	public function get_keywords(): array {
		return [ 'split', 'benefits', 'feature', 'verified' ];
	}

	/**
	 * @return array<string,string>
	 */
	private function icon_options(): array {
		return [
			''               => __( 'No icon', 'thirtydayhomes' ),
			'key-round'      => __( 'Key — move-in ready', 'thirtydayhomes' ),
			'calendar-days'  => __( 'Calendar — flexible dates', 'thirtydayhomes' ),
			'stethoscope'    => __( 'Stethoscope — near care', 'thirtydayhomes' ),
			'shield-check'   => __( 'Shield — verified', 'thirtydayhomes' ),
			'map-pin'        => __( 'Map pin — location', 'thirtydayhomes' ),
			'briefcase'      => __( 'Briefcase — work', 'thirtydayhomes' ),
			'check'          => __( 'Check', 'thirtydayhomes' ),
		];
	}

	protected function register_controls(): void {

		$this->start_controls_section( 'content', [ 'label' => __( 'Content', 'thirtydayhomes' ) ] );

		$this->add_control(
			'eyebrow',
			[
				'label'   => __( 'Eyebrow', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'A better way to stay', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'heading',
			[
				'label'       => __( 'Heading', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Everything you need. Nothing you don’t.', 'thirtydayhomes' ),
				'label_block' => true,
			]
		);

		$this->add_control(
			'copy',
			[
				'label'   => __( 'Supporting copy', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXTAREA,
				'rows'    => 3,
				'default' => __( 'Skip the hotel shuffle and the year-long lease. Our homes are designed for real life, with space to work, cook, rest, and settle in.', 'thirtydayhomes' ),
			]
		);

		$this->end_controls_section();

		$this->start_controls_section( 'photo', [ 'label' => __( 'Photograph', 'thirtydayhomes' ) ] );

		$this->add_control(
			'image',
			[
				'label'       => __( 'Image', 'thirtydayhomes' ),
				'description' => __( 'Portrait or square works best. Empty uses the theme default.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::MEDIA,
			]
		);

		$this->add_control(
			'badge_title',
			[
				'label'       => __( 'Badge title', 'thirtydayhomes' ),
				'description' => __( 'The floating white card over the photograph. Leave empty to hide it.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Every home, verified.', 'thirtydayhomes' ),
				'label_block' => true,
			]
		);

		$this->add_control(
			'badge_copy',
			[
				'label'   => __( 'Badge subtitle', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Quality you can count on', 'thirtydayhomes' ),
			]
		);

		$this->end_controls_section();

		$this->start_controls_section( 'benefits_section', [ 'label' => __( 'Benefits', 'thirtydayhomes' ) ] );

		$repeater = new Repeater();

		$repeater->add_control(
			'icon',
			[
				'label'   => __( 'Icon', 'thirtydayhomes' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'key-round',
				'options' => $this->icon_options(),
			]
		);

		$repeater->add_control(
			'title',
			[
				'label'       => __( 'Title', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Move-in ready', 'thirtydayhomes' ),
				'label_block' => true,
			]
		);

		$repeater->add_control(
			'copy',
			[
				'label' => __( 'Description', 'thirtydayhomes' ),
				'type'  => Controls_Manager::TEXTAREA,
				'rows'  => 2,
			]
		);

		$this->add_control(
			'benefits',
			[
				'label'       => __( 'Benefits', 'thirtydayhomes' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{{ title }}}',
				'default'     => Render::default_benefits(),
			]
		);

		$this->end_controls_section();
	}

	protected function render(): void {

		$s = $this->get_settings_for_display();

		$benefits = [];
		foreach ( (array) ( $s['benefits'] ?? [] ) as $b ) {
			$benefits[] = [
				'icon'  => (string) ( $b['icon'] ?? '' ),
				'title' => (string) ( $b['title'] ?? '' ),
				'copy'  => (string) ( $b['copy'] ?? '' ),
			];
		}

		// Escaped inside Render.
		echo Render::split_feature( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			[
				'eyebrow'     => (string) ( $s['eyebrow'] ?? '' ),
				'heading'     => (string) ( $s['heading'] ?? '' ),
				'copy'        => (string) ( $s['copy'] ?? '' ),
				'image'       => (string) ( $s['image']['url'] ?? '' ),
				'badge_title' => (string) ( $s['badge_title'] ?? '' ),
				'badge_copy'  => (string) ( $s['badge_copy'] ?? '' ),
				'benefits'    => $benefits ?: Render::default_benefits(),
			]
		);
	}
}
