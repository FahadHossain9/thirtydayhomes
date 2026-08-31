<?php
/**
 * Contact page widget.
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
 * Visual wrapper around [tdh_contact].
 *
 * ─── THE FORM ITSELF IS NOT EDITABLE, AND MUST NOT BE ──────────────────────
 *
 * The controls here cover the promise beside the form — its heading, its
 * lead, the three assurances, the status line. They do not cover the form:
 * its fields, its labels, its topics, its honeypot or its nonce.
 *
 * That is not an omission. The form's markup and its handler are two halves
 * of one thing — TDH\Contact reads exactly the field names Render writes,
 * validates them, and rejects a submission whose nonce or honeypot is wrong.
 * A field renamed in a page builder would be a field the handler never
 * receives, and the failure would be a contact form that silently drops
 * messages while looking perfectly correct.
 *
 * The topics are the same story: they route the message and are checked
 * against a fixed list, so an editable topic is a message that arrives
 * labelled "other" for reasons nobody can see. Both belong in code, and
 * handoff §3 agrees — behaviour lives in the plugin.
 */
final class Contact extends Widget_Base {

	public function get_name(): string {
		return 'tdh-contact';
	}

	public function get_title(): string {
		return __( 'Contact Page', 'thirtydayhomes' );
	}

	public function get_icon(): string {
		return 'eicon-envelope';
	}

	/** @return string[] */
	public function get_categories(): array {
		return [ Registrar::CATEGORY ];
	}

	/** @return string[] */
	public function get_keywords(): array {
		return [ 'contact', 'form', 'message', 'enquiry' ];
	}

	/**
	 * @return array<string,string>
	 */
	private function icon_options(): array {
		return [
			''              => __( 'No icon', 'thirtydayhomes' ),
			'calendar-days' => __( 'Calendar — timing, response', 'thirtydayhomes' ),
			'key-round'     => __( 'Key — owners, listing', 'thirtydayhomes' ),
			'shield-check'  => __( 'Shield — privacy, trust', 'thirtydayhomes' ),
			'search'        => __( 'Search — renters', 'thirtydayhomes' ),
			'map-pin'       => __( 'Map pin — location', 'thirtydayhomes' ),
			'check'         => __( 'Check', 'thirtydayhomes' ),
		];
	}

	protected function register_controls(): void {

		$this->start_controls_section( 'promise_section', [ 'label' => __( 'The promise', 'thirtydayhomes' ) ] );

		$this->add_control(
			'eyebrow',
			[
				'label'   => __( 'Eyebrow', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'We read every message', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'heading',
			[
				'label'       => __( 'Heading', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Tell us what you need.', 'thirtydayhomes' ),
				'label_block' => true,
			]
		);

		$this->add_control(
			'lead',
			[
				'label'   => __( 'Lead', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXTAREA,
				'rows'    => 3,
				'default' => __( 'A real person answers this, in Pittsburgh, from the same team that reviews every home on the site.', 'thirtydayhomes' ),
			]
		);

		$assurances = new Repeater();

		$assurances->add_control(
			'icon',
			[
				'label'   => __( 'Icon', 'thirtydayhomes' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'calendar-days',
				'options' => $this->icon_options(),
			]
		);

		$assurances->add_control(
			'title',
			[
				'label'       => __( 'Title', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
			]
		);

		$assurances->add_control(
			'copy',
			[
				'label' => __( 'Copy', 'thirtydayhomes' ),
				'type'  => Controls_Manager::TEXTAREA,
				'rows'  => 3,
			]
		);

		$this->add_control(
			'assurances',
			[
				'label'       => __( 'Assurances', 'thirtydayhomes' ),
				'description' => __( 'These answer the two questions that stop people sending: how long until somebody replies, and am I writing to the right place. They are not decoration — a form that answers them before the first keystroke gets filled in more often.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $assurances->get_controls(),
				'title_field' => '{{{ title }}}',
				'default'     => Render::default_contact_assurances(),
			]
		);

		$this->add_control(
			'status',
			[
				'label'       => __( 'Status line', 'thirtydayhomes' ),
				'description' => __( 'The pill with the pulsing dot, read last before the eye crosses to the form.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Someone reads this inbox every business day', 'thirtydayhomes' ),
				'label_block' => true,
			]
		);

		$this->end_controls_section();

		$this->start_controls_section( 'form_section', [ 'label' => __( 'The form', 'thirtydayhomes' ) ] );

		$this->add_control(
			'form_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'The form’s fields, labels and topics are not editable here. Its markup and the handler that receives it are two halves of one thing: a field renamed in a page builder is a field the handler never receives, and the result would be a contact form that silently drops messages while looking correct. Messages arrive under Listings → Inquiries.', 'thirtydayhomes' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			]
		);

		$this->end_controls_section();
	}

	protected function render(): void {

		$s = $this->get_settings_for_display();

		$assurances = [];

		foreach ( (array) ( $s['assurances'] ?? [] ) as $row ) {
			$assurances[] = [
				'icon'  => (string) ( $row['icon'] ?? '' ),
				'title' => (string) ( $row['title'] ?? '' ),
				'copy'  => (string) ( $row['copy'] ?? '' ),
			];
		}

		echo Render::contact( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			[
				'eyebrow'    => (string) ( $s['eyebrow'] ?? '' ),
				'heading'    => (string) ( $s['heading'] ?? '' ),
				'lead'       => (string) ( $s['lead'] ?? '' ),
				'status'     => (string) ( $s['status'] ?? '' ),

				// An emptied repeater falls back rather than leaving the dark
				// half of the card holding a heading and nothing else.
				'assurances' => $assurances ?: Render::default_contact_assurances(),
			]
		);
	}
}
