<?php
/**
 * Property grid widget.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use TDH\Elementor\Registrar;
use TDH\Post_Types;
use TDH\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Visual wrapper around [tdh_property_grid].
 *
 * The client chooses how many listings and which selection. They cannot
 * choose to show a pending listing, a paused one, or one belonging to a
 * lapsed member — the query passes through the plugin's visibility rule,
 * so an Elementor block can never become a back door around moderation.
 */
final class Property_Grid extends Widget_Base {

	public function get_name(): string {
		return 'tdh-property-grid';
	}

	public function get_title(): string {
		return __( 'Property Grid', 'thirtydayhomes' );
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
		return [ 'listings', 'properties', 'homes', 'grid' ];
	}

	protected function register_controls(): void {

		$this->start_controls_section( 'heading_section', [ 'label' => __( 'Heading', 'thirtydayhomes' ) ] );

		$this->add_control(
			'eyebrow',
			[
				'label'   => __( 'Eyebrow', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Explore Pittsburgh', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'heading',
			[
				'label'       => __( 'Heading', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Homes ready when you are', 'thirtydayhomes' ),
				'label_block' => true,
			]
		);

		$this->add_control(
			'subheading',
			[
				'label'   => __( 'Supporting copy', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXTAREA,
				'rows'    => 2,
				'default' => __( 'Handpicked monthly rentals close to Pittsburgh’s leading medical centres.', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'show_link',
			[
				'label'        => __( 'Show "view all" link', 'thirtydayhomes' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			]
		);

		$this->add_control(
			'link_text',
			[
				'label'     => __( 'Link label', 'thirtydayhomes' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'View all homes', 'thirtydayhomes' ),
				'condition' => [ 'show_link' => 'yes' ],
			]
		);

		$this->end_controls_section();

		$this->start_controls_section( 'query_section', [ 'label' => __( 'Which homes', 'thirtydayhomes' ) ] );

		$this->add_control(
			'count',
			[
				'label'   => __( 'How many', 'thirtydayhomes' ),
				'type'    => Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => 24,
				'default' => 3,
			]
		);

		$this->add_control(
			'orderby',
			[
				'label'   => __( 'Order by', 'thirtydayhomes' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'date',
				'options' => [
					'date'       => __( 'Newest first', 'thirtydayhomes' ),
					'price_asc'  => __( 'Price, low to high', 'thirtydayhomes' ),
					'price_desc' => __( 'Price, high to low', 'thirtydayhomes' ),
					'rand'       => __( 'Random', 'thirtydayhomes' ),
				],
			]
		);

		$this->add_control(
			'neighborhood',
			[
				'label'   => __( 'Limit to a neighborhood', 'thirtydayhomes' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => $this->neighborhood_options(),
			]
		);

		$this->add_control(
			'columns',
			[
				'label'   => __( 'Columns', 'thirtydayhomes' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '3',
				'options' => [
					'2' => __( 'Two', 'thirtydayhomes' ),
					'3' => __( 'Three', 'thirtydayhomes' ),
					'4' => __( 'Four', 'thirtydayhomes' ),
				],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * @return array<string,string>
	 */
	private function neighborhood_options(): array {

		$options = [ '' => __( 'All neighborhoods', 'thirtydayhomes' ) ];
		$terms   = get_terms( [ 'taxonomy' => Post_Types::TAX_NEIGHBORHOOD, 'hide_empty' => false ] );

		if ( is_wp_error( $terms ) ) {
			return $options;
		}

		foreach ( $terms as $term ) {
			$options[ $term->slug ] = $term->name;
		}

		return $options;
	}

	protected function render(): void {

		$s = $this->get_settings_for_display();

		// Escaped inside Render.
		echo Render::property_grid( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			[
				'count'        => (int) ( $s['count'] ?? 3 ),
				'columns'      => (int) ( $s['columns'] ?? 3 ),
				'orderby'      => (string) ( $s['orderby'] ?? 'date' ),
				'neighborhood' => (string) ( $s['neighborhood'] ?? '' ),
				'eyebrow'      => (string) ( $s['eyebrow'] ?? '' ),
				'heading'      => (string) ( $s['heading'] ?? '' ),
				'subheading'   => (string) ( $s['subheading'] ?? '' ),
				'show_link'    => 'yes' === ( $s['show_link'] ?? 'yes' ),
				'link_text'    => (string) ( $s['link_text'] ?? '' ),
			]
		);
	}
}
