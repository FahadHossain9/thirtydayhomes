<?php
/**
 * Shortcodes — the portable entry point to every dynamic block.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes are the primitive; Elementor widgets wrap them.
 *
 * Both call TDH\Render, so there is one implementation per block. The
 * shortcode works in Elementor, Gutenberg, the classic editor, a widget
 * area, or a template via do_shortcode() — and it keeps working if
 * Elementor is ever deactivated, which a widget-only approach would not.
 *
 * Available:
 *
 *   [tdh_property_grid count="3" columns="3" orderby="date"
 *                      neighborhood="shadyside" heading="Homes ready"
 *                      eyebrow="Explore Pittsburgh" show_link="yes"]
 *
 *   [tdh_hero_search heading="Stay a while." accent="Feel at home."
 *                    require_dates="yes"]
 */
final class Shortcodes {

	public function register(): void {
		add_shortcode( 'tdh_property_grid', [ $this, 'property_grid' ] );
		add_shortcode( 'tdh_hero_search', [ $this, 'hero_search' ] );
		add_shortcode( 'tdh_audience', [ $this, 'audience' ] );
		add_shortcode( 'tdh_split_feature', [ $this, 'split_feature' ] );
		add_shortcode( 'tdh_owner_cta', [ $this, 'owner_cta' ] );
		add_shortcode( 'tdh_pricing', [ $this, 'pricing' ] );

		// No attributes. Every string in the About body is a default in
		// Render::about(), which is one place to edit when the client signs
		// the copy off; an attribute list long enough to express four bands
		// would be unreadable in the editor and nobody would use it.
		add_shortcode( 'tdh_about', [ $this, 'about' ] );
		add_shortcode( 'tdh_how_it_works', [ $this, 'how_it_works' ] );
		add_shortcode( 'tdh_contact', [ $this, 'contact' ] );

		// Account screens. No attributes: these render one thing each, and
		// an attribute would only be a way to configure them into a state
		// the form handler does not accept.
		add_shortcode( 'tdh_register', [ Account_Render::class, 'register' ] );
		add_shortcode( 'tdh_login', [ Account_Render::class, 'login' ] );
		add_shortcode( 'tdh_lost_password', [ Account_Render::class, 'lost_password' ] );
		add_shortcode( 'tdh_reset_password', [ Account_Render::class, 'reset_password' ] );
		add_shortcode( 'tdh_account', [ Account_Render::class, 'dashboard' ] );
		add_shortcode( 'tdh_profile', [ Account_Render::class, 'profile' ] );
	}

	/**
	 * The About page body.
	 *
	 * @param array<string,string>|string $atts Unused.
	 */
	public function about( $atts ): string {
		unset( $atts );

		return Render::about();
	}

	/**
	 * The How it works page body.
	 *
	 * @param array<string,string>|string $atts Unused.
	 */
	public function how_it_works( $atts ): string {
		unset( $atts );

		return Render::how_it_works();
	}

	/**
	 * The Contact page body — the message form and what to expect from it.
	 *
	 * The heading is an attribute so the client can retitle the page without
	 * a developer; the three assurance cards are not, for the same reason
	 * the audience cards are not — three cards of three fields would make an
	 * unreadable shortcode nobody would edit correctly.
	 *
	 * @param array<string,string>|string $atts
	 */
	public function contact( $atts ): string {

		$a = shortcode_atts(
			[
				'eyebrow' => '',
				'heading' => '',
			],
			(array) $atts,
			'tdh_contact'
		);

		$args = [];

		foreach ( [ 'eyebrow', 'heading' ] as $key ) {
			if ( '' !== $a[ $key ] ) {
				$args[ $key ] = sanitize_text_field( $a[ $key ] );
			}
		}

		return Render::contact( $args );
	}

	/**
	 * The membership pricing table.
	 *
	 * @param array<string,string>|string $atts
	 */
	public function pricing( $atts ): string {

		$a = shortcode_atts(
			[
				'eyebrow' => '',
				'heading' => '',
				'intro'   => '',
			],
			(array) $atts,
			'tdh_pricing'
		);

		$args = [];

		foreach ( [ 'eyebrow', 'heading', 'intro' ] as $key ) {
			if ( '' !== $a[ $key ] ) {
				$args[ $key ] = sanitize_text_field( $a[ $key ] );
			}
		}

		return Render::pricing( $args );
	}

	/**
	 * The split feature block.
	 *
	 * @param array<string,string>|string $atts
	 */
	public function split_feature( $atts ): string {

		$a = shortcode_atts(
			[
				'eyebrow'     => '',
				'heading'     => '',
				'copy'        => '',
				'image'       => '',
				'badge_title' => '',
				'badge_copy'  => '',
			],
			(array) $atts,
			'tdh_split_feature'
		);

		$args = [];

		foreach ( [ 'eyebrow', 'heading', 'copy', 'badge_title', 'badge_copy' ] as $key ) {
			if ( '' !== $a[ $key ] ) {
				$args[ $key ] = sanitize_text_field( $a[ $key ] );
			}
		}

		if ( '' !== $a['image'] ) {
			$args['image'] = esc_url_raw( $a['image'] );
		}

		return Render::split_feature( $args );
	}

	/**
	 * The audience section.
	 *
	 * Cards come from the defaults. Editing them is a widget job — a
	 * shortcode attribute cannot express four cards of four fields each
	 * without becoming unreadable, and an unreadable shortcode is one
	 * nobody will edit correctly.
	 *
	 * @param array<string,string>|string $atts
	 */
	public function audience( $atts ): string {

		$a = shortcode_atts(
			[
				'eyebrow' => '',
				'heading' => '',
				'intro'   => '',
			],
			(array) $atts,
			'tdh_audience'
		);

		$args = [];

		foreach ( [ 'eyebrow', 'heading', 'intro' ] as $key ) {
			if ( '' !== $a[ $key ] ) {
				$args[ $key ] = sanitize_text_field( $a[ $key ] );
			}
		}

		return Render::audience( $args );
	}

	/**
	 * The owner call to action.
	 *
	 * @param array<string,string>|string $atts
	 */
	public function owner_cta( $atts ): string {

		$a = shortcode_atts(
			[
				'eyebrow'     => '',
				'heading'     => '',
				'copy'        => '',
				'button_text' => '',
				'button_url'  => '',
			],
			(array) $atts,
			'tdh_owner_cta'
		);

		$args = [];

		foreach ( [ 'eyebrow', 'heading', 'copy', 'button_text' ] as $key ) {
			if ( '' !== $a[ $key ] ) {
				$args[ $key ] = sanitize_text_field( $a[ $key ] );
			}
		}

		if ( '' !== $a['button_url'] ) {
			$args['button_url'] = esc_url_raw( $a['button_url'] );
		}

		return Render::owner_cta( $args );
	}

	/**
	 * @param array<string,string>|string $atts
	 */
	public function property_grid( $atts ): string {

		$a = shortcode_atts(
			[
				'count'        => '3',
				'columns'      => '3',
				'orderby'      => 'date',
				'neighborhood' => '',
				'eyebrow'      => '',
				'heading'      => '',
				'subheading'   => '',
				'show_link'    => 'no',
				'link_text'    => __( 'View all homes', 'thirtydayhomes' ),
			],
			(array) $atts,
			'tdh_property_grid'
		);

		// Only the four orderings we support. An unknown value falls back
		// rather than reaching WP_Query, where it could become a slow or
		// surprising sort.
		$orderby = in_array( $a['orderby'], [ 'date', 'price_asc', 'price_desc', 'rand' ], true )
			? $a['orderby']
			: 'date';

		return Render::property_grid(
			[
				'count'        => (int) $a['count'],
				'columns'      => (int) $a['columns'],
				'orderby'      => $orderby,
				'neighborhood' => sanitize_title( $a['neighborhood'] ),
				'eyebrow'      => sanitize_text_field( $a['eyebrow'] ),
				'heading'      => sanitize_text_field( $a['heading'] ),
				'subheading'   => sanitize_text_field( $a['subheading'] ),
				'show_link'    => in_array( strtolower( $a['show_link'] ), [ 'yes', 'true', '1' ], true ),
				'link_text'    => sanitize_text_field( $a['link_text'] ),
			]
		);
	}

	/**
	 * @param array<string,string>|string $atts
	 */
	public function hero_search( $atts ): string {

		static $instance = 0;
		++$instance;

		$a = shortcode_atts(
			[
				'eyebrow'       => __( 'Furnished homes · 30+ day stays', 'thirtydayhomes' ),
				'heading'       => __( 'Stay a while.', 'thirtydayhomes' ),
				'accent'        => __( 'Feel at home.', 'thirtydayhomes' ),
				'lead'          => '',
				'image'         => '',
				'button_text'   => __( 'Search homes', 'thirtydayhomes' ),
				'placeholder'   => __( 'Neighborhood, ZIP, or hospital', 'thirtydayhomes' ),
				'require_dates' => 'yes',
				'trust'         => '',
			],
			(array) $atts,
			'tdh_hero_search'
		);

		$args = [
			'eyebrow'       => sanitize_text_field( $a['eyebrow'] ),
			'heading'       => sanitize_text_field( $a['heading'] ),
			'accent'        => sanitize_text_field( $a['accent'] ),
			'image'         => esc_url_raw( $a['image'] ),
			'button_text'   => sanitize_text_field( $a['button_text'] ),
			'placeholder'   => sanitize_text_field( $a['placeholder'] ),
			'require_dates' => in_array( strtolower( $a['require_dates'] ), [ 'yes', 'true', '1' ], true ),
			'uid'           => 'sc-' . $instance,
		];

		if ( '' !== $a['lead'] ) {
			$args['lead'] = sanitize_textarea_field( $a['lead'] );
		}

		// trust="Fully furnished|Utilities included|Reviewed before listing"
		if ( '' !== $a['trust'] ) {
			$args['trust'] = array_map( 'sanitize_text_field', explode( '|', $a['trust'] ) );
		}

		return Render::hero_search( $args );
	}
}
