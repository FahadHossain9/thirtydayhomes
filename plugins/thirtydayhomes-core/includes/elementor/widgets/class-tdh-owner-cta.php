<?php
/**
 * Owner call-to-action widget.
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
 * Visual wrapper around [tdh_owner_cta].
 *
 * The whole navy call-to-action band as one block, so it cannot be half
 * deleted or reordered into something that no longer reads as a unit.
 */
final class Owner_CTA extends Widget_Base {

	public function get_name(): string {
		return 'tdh-owner-cta';
	}

	public function get_title(): string {
		return __( 'Owner CTA', 'thirtydayhomes' );
	}

	public function get_icon(): string {
		return 'eicon-call-to-action';
	}

	/** @return string[] */
	public function get_categories(): array {
		return [ Registrar::CATEGORY ];
	}

	/** @return string[] */
	public function get_keywords(): array {
		return [ 'cta', 'owner', 'landlord', 'membership' ];
	}

	protected function register_controls(): void {

		$this->start_controls_section( 'content', [ 'label' => __( 'Content', 'thirtydayhomes' ) ] );

		$this->add_control(
			'eyebrow',
			[
				'label'   => __( 'Eyebrow', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'For property owners', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'heading',
			[
				'label'       => __( 'Heading', 'thirtydayhomes' ),
				'description' => __( 'Use &lt;br&gt; to force a line break. The break is ignored on phones, where the heading wraps naturally.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Your property works harder<br>with longer stays.', 'thirtydayhomes' ),
				'label_block' => true,
			]
		);

		$this->add_control(
			'copy',
			[
				'label'   => __( 'Supporting copy', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXTAREA,
				'rows'    => 3,
				'default' => __( 'Reach trusted professionals and families seeking furnished homes.', 'thirtydayhomes' ),
			]
		);

		$this->end_controls_section();

		$this->start_controls_section( 'button_section', [ 'label' => __( 'Button', 'thirtydayhomes' ) ] );

		$this->add_control(
			'button_text',
			[
				'label'   => __( 'Label', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'See membership options', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'button_url',
			[
				'label'       => __( 'Link', 'thirtydayhomes' ),
				'description' => __( 'Leave empty to link to the Membership page.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::URL,
				'options'     => [ 'is_external', 'nofollow' ],
			]
		);

		$this->end_controls_section();

		$this->start_controls_section( 'stats_section', [ 'label' => __( 'Statistics', 'thirtydayhomes' ) ] );

		$repeater = new Repeater();

		$repeater->add_control(
			'value',
			[
				'label'   => __( 'Figure', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXT,
				'default' => '30+',
			]
		);

		$repeater->add_control(
			'label',
			[
				'label'   => __( 'Label', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'night minimum', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'stats',
			[
				'label'       => __( 'Statistics', 'thirtydayhomes' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{{ value }}} {{{ label }}}',
				'default'     => [
					[ 'value' => '30+', 'label' => __( 'night minimum', 'thirtydayhomes' ) ],
					// Verify against the signed-off plans before launch —
					// this figure is a pricing commitment, not decoration.
					[ 'value' => '3', 'label' => __( 'listings per plan', 'thirtydayhomes' ) ],
					[ 'value' => '100%', 'label' => __( 'direct inquiries', 'thirtydayhomes' ) ],
				],
			]
		);

		$this->end_controls_section();
	}

	protected function render(): void {

		$s = $this->get_settings_for_display();

		$stats = [];
		foreach ( (array) ( $s['stats'] ?? [] ) as $stat ) {
			if ( '' !== (string) ( $stat['value'] ?? '' ) ) {
				$stats[] = [
					'value' => (string) $stat['value'],
					'label' => (string) ( $stat['label'] ?? '' ),
				];
			}
		}

		$args = [
			'eyebrow'     => (string) ( $s['eyebrow'] ?? '' ),
			'heading'     => (string) ( $s['heading'] ?? '' ),
			'copy'        => (string) ( $s['copy'] ?? '' ),
			'button_text' => (string) ( $s['button_text'] ?? '' ),
			'stats'       => $stats,
		];

		// Only override the default Membership link if one was set, so an
		// empty control does not silently remove the button.
		if ( ! empty( $s['button_url']['url'] ) ) {
			$args['button_url'] = (string) $s['button_url']['url'];
		}

		// Escaped inside Render.
		echo Render::owner_cta( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
