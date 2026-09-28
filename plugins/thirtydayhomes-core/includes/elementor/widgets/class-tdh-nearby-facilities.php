<?php
/**
 * Elementor widget: the nearest hospitals for the home being viewed.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use TDH\Elementor\Registrar;
use TDH\Proximity;
use TDH\Render;

defined( 'ABSPATH' ) || exit;

/**
 * "Close to care" as a widget.
 *
 * Every distance comes from TDH\Proximity, measured from the home's own
 * location against the facilities staff maintain. Nothing here can be typed
 * in: a distance written by hand into a page layout would be wrong the day
 * a hospital moves or a home is re-geocoded, and the handoff names exactly
 * this ("Dynamic values such as … distance … must come from the plugin and
 * must not be manually duplicated in Elementor").
 *
 * The only number the layout owns is how many rows to show, and even that
 * is clamped to what staff may set on Listing setup, so a page cannot
 * promise more than the product supports.
 */
final class Nearby_Facilities extends Widget_Base {

	public function get_name(): string {
		return 'tdh-nearby-facilities';
	}

	public function get_title(): string {
		return __( 'Nearby hospitals', 'thirtydayhomes' );
	}

	public function get_icon(): string {
		return 'eicon-map-pin';
	}

	/** @return string[] */
	public function get_categories(): array {
		return [ Registrar::CATEGORY ];
	}

	/** @return string[] */
	public function get_keywords(): array {
		return [ 'hospital', 'facilities', 'distance', 'medical', 'care', 'proximity' ];
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
				'default'     => __( 'Close to care', 'thirtydayhomes' ),
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'list_section',
			[ 'label' => __( 'How many', 'thirtydayhomes' ) ]
		);

		$this->add_control(
			'count',
			[
				'label'       => __( 'Hospitals to list', 'thirtydayhomes' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'max'         => Proximity::MAX_COUNT,
				'default'     => 0,
				'description' => sprintf(
					/* translators: %s: the number staff have set on Listing setup */
					__( 'Leave at 0 to use the site setting, currently %s. The distance to look within is always the site setting.', 'thirtydayhomes' ),
					number_format_i18n( Proximity::count_setting() )
				),
			]
		);

		$this->end_controls_section();
	}

	protected function render(): void {

		$s = $this->get_settings_for_display();

		echo Render::nearby_facilities( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Render.
			[
				'heading' => (string) ( $s['heading'] ?? '' ),
				'count'   => (int) ( $s['count'] ?? 0 ),
			]
		);
	}
}
