<?php
/**
 * Membership pricing page widget.
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
 * Visual wrapper around [tdh_pricing].
 *
 * ─── WHAT IS DELIBERATELY NOT EDITABLE HERE ────────────────────────────────
 *
 * The plan tiers — how many listings, and what each costs — are NOT controls
 * on this widget, and that is the most important decision in the file.
 *
 * Those numbers are one half of a pair. The other half is a Stripe Price ID
 * on the Payments screen, and Stripe is what actually charges the card. An
 * editable price in Elementor would let the page advertise $60 while Stripe
 * takes $49, and nobody would find out until a landlord read their statement
 * — a billing dispute created by a text field.
 *
 * So the marketing copy around the table is editable and the numbers inside
 * it are not. Prices change on the Payments screen, next to the Price IDs
 * they have to agree with. Handoff §4 asks for exactly this line to be
 * drawn: "Dynamic values such as prices … must come from the plugin and must
 * not be manually duplicated in Elementor."
 */
final class Pricing extends Widget_Base {

	public function get_name(): string {
		return 'tdh-pricing';
	}

	public function get_title(): string {
		return __( 'Membership Pricing', 'thirtydayhomes' );
	}

	public function get_icon(): string {
		return 'eicon-price-table';
	}

	/** @return string[] */
	public function get_categories(): array {
		return [ Registrar::CATEGORY ];
	}

	/** @return string[] */
	public function get_keywords(): array {
		return [ 'pricing', 'membership', 'plans', 'subscription' ];
	}

	protected function register_controls(): void {

		$this->start_controls_section( 'heading_section', [ 'label' => __( 'Heading', 'thirtydayhomes' ) ] );

		$this->add_control(
			'eyebrow',
			[
				'label'   => __( 'Eyebrow', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Simple, transparent membership', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'heading',
			[
				'label'       => __( 'Heading', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'More listings. Better value.', 'thirtydayhomes' ),
				'label_block' => true,
			]
		);

		$this->add_control(
			'intro',
			[
				'label'   => __( 'Intro', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXTAREA,
				'rows'    => 3,
				'default' => __( 'Automatic volume pricing rewards landlords who publish more than one home.', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'plans_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'The plans, their prices and the listing allowances are set on Listings → Payments, beside the Stripe Price IDs they have to agree with. They are not editable here on purpose: a price typed into a page could advertise one figure while Stripe charged another.', 'thirtydayhomes' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			]
		);

		$this->end_controls_section();

		/* -----------------------------------------------------------------
		 * Included in every plan
		 * -------------------------------------------------------------- */

		$this->start_controls_section( 'included_section', [ 'label' => __( 'Included in every plan', 'thirtydayhomes' ) ] );

		$this->add_control(
			'included_heading',
			[
				'label'       => __( 'Heading', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Included in every plan', 'thirtydayhomes' ),
				'label_block' => true,
			]
		);

		$features = new Repeater();

		$features->add_control(
			'text',
			[
				'label'       => __( 'Feature', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
			]
		);

		$this->add_control(
			'features',
			[
				'label'       => __( 'Features', 'thirtydayhomes' ),
				'description' => __( 'One list for all plans, not one per plan: the plans differ only in how many homes they carry, and repeating the same bullets three times invites them to drift apart. Empty the list to hide the whole band.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $features->get_controls(),
				'title_field' => '{{{ text }}}',
				'default'     => array_map(
					static fn( string $f ): array => [ 'text' => $f ],
					Render::plan_features()
				),
			]
		);

		$this->end_controls_section();

		/* -----------------------------------------------------------------
		 * The footnote
		 * -------------------------------------------------------------- */

		$this->start_controls_section( 'note_section', [ 'label' => __( 'Footnote', 'thirtydayhomes' ) ] );

		$this->add_control(
			'note',
			[
				'label'   => __( 'Note', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXTAREA,
				'rows'    => 2,
				'default' => __( 'The discount applies automatically as homes are added.', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'note_emphasis',
			[
				'label'       => __( 'Emphasised note', 'thirtydayhomes' ),
				'description' => __( 'Set in bold after the note. Clear this once the client has confirmed the prices.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Prices shown are not final and are awaiting client confirmation.', 'thirtydayhomes' ),
				'label_block' => true,
			]
		);

		$this->end_controls_section();
	}

	protected function render(): void {

		$s = $this->get_settings_for_display();

		$features = [];
		foreach ( (array) ( $s['features'] ?? [] ) as $row ) {
			$text = trim( (string) ( $row['text'] ?? '' ) );

			if ( '' !== $text ) {
				$features[] = $text;
			}
		}

		/*
		 * An emptied features list is HONOURED here rather than falling back
		 * to the defaults, unlike the repeaters on the other page widgets.
		 * The band has a heading of its own and Render hides the whole
		 * section when the list is empty, so clearing it is a coherent
		 * thing to want — "we do not want this band" — where clearing the
		 * About page's cards would leave a heading over nothing.
		 */
		echo Render::pricing( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			[
				'eyebrow'          => (string) ( $s['eyebrow'] ?? '' ),
				'heading'          => (string) ( $s['heading'] ?? '' ),
				'intro'            => (string) ( $s['intro'] ?? '' ),
				'included_heading' => (string) ( $s['included_heading'] ?? '' ),
				'features'         => $features,
				'note'             => (string) ( $s['note'] ?? '' ),
				'note_emphasis'    => (string) ( $s['note_emphasis'] ?? '' ),
			]
		);
	}
}
