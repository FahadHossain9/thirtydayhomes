<?php
/**
 * How it works page widget.
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
 * Visual wrapper around [tdh_how_it_works].
 *
 * ─── WHY THE TWO TRACKS ARE FLATTENED ──────────────────────────────────────
 *
 * The page's data is two tracks, each holding three numbered steps. That is
 * a repeater inside a repeater, and Elementor has no such control — a
 * Repeater's fields cannot themselves contain one.
 *
 * So the two tracks become two fixed groups of controls, each with its own
 * flat steps repeater. That is not a workaround dressed as a design: the
 * marketplace has exactly two audiences, and the page's entire job is
 * telling a renter and an owner apart before either reads the other's
 * steps. A variable number of tracks would be a different page.
 *
 * The renderer still receives the nested shape it expects; the assembly
 * happens in render() below, so TDH\Render stays free of Elementor's
 * limitations.
 */
final class How_It_Works extends Widget_Base {

	public function get_name(): string {
		return 'tdh-how-it-works';
	}

	public function get_title(): string {
		return __( 'How It Works Page', 'thirtydayhomes' );
	}

	public function get_icon(): string {
		return 'eicon-number-field';
	}

	/** @return string[] */
	public function get_categories(): array {
		return [ Registrar::CATEGORY ];
	}

	/** @return string[] */
	public function get_keywords(): array {
		return [ 'how it works', 'faq', 'steps', 'process' ];
	}

	/**
	 * @return array<string,string>
	 */
	private function icon_options(): array {
		return [
			''            => __( 'No icon', 'thirtydayhomes' ),
			'search'      => __( 'Search — renters, finding', 'thirtydayhomes' ),
			'key-round'   => __( 'Key — owners, listing', 'thirtydayhomes' ),
			'map-pin'     => __( 'Map pin — location', 'thirtydayhomes' ),
			'shield-check' => __( 'Shield — trust', 'thirtydayhomes' ),
			'bed-double'  => __( 'Bed — homes, stays', 'thirtydayhomes' ),
			'check'       => __( 'Check', 'thirtydayhomes' ),
		];
	}

	/**
	 * The steps repeater, built twice — once per track.
	 */
	private function steps_repeater(): Repeater {

		$r = new Repeater();

		$r->add_control(
			'title',
			[
				'label'       => __( 'Step', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
			]
		);

		$r->add_control(
			'copy',
			[
				'label' => __( 'What happens', 'thirtydayhomes' ),
				'type'  => Controls_Manager::TEXTAREA,
				'rows'  => 3,
			]
		);

		return $r;
	}

	/**
	 * One track's worth of controls.
	 *
	 * @param array<string,mixed> $default
	 */
	private function track_controls( string $prefix, string $section_label, array $default ): void {

		$this->start_controls_section( $prefix . '_section', [ 'label' => $section_label ] );

		$this->add_control(
			$prefix . '_icon',
			[
				'label'   => __( 'Icon', 'thirtydayhomes' ),
				'type'    => Controls_Manager::SELECT,
				'default' => (string) ( $default['icon'] ?? '' ),
				'options' => $this->icon_options(),
			]
		);

		$this->add_control(
			$prefix . '_eyebrow',
			[
				'label'   => __( 'Eyebrow', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXT,
				'default' => (string) ( $default['eyebrow'] ?? '' ),
			]
		);

		$this->add_control(
			$prefix . '_heading',
			[
				'label'       => __( 'Heading', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => (string) ( $default['heading'] ?? '' ),
				'label_block' => true,
			]
		);

		$this->add_control(
			$prefix . '_steps',
			[
				'label'       => __( 'Steps', 'thirtydayhomes' ),
				'description' => __( 'Numbered automatically, in this order. The numbering is real — you cannot compare before you search — so reordering these changes what the page claims.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $this->steps_repeater()->get_controls(),
				'title_field' => '{{{ title }}}',
				'default'     => (array) ( $default['steps'] ?? [] ),
			]
		);

		$this->add_control(
			$prefix . '_cta',
			[
				'label'       => __( 'Link label', 'thirtydayhomes' ),
				'description' => __( 'Leave empty to hide the link on this track.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => (string) ( $default['cta'] ?? '' ),
				'label_block' => true,
			]
		);

		$this->add_control(
			$prefix . '_url',
			[
				'label'   => __( 'Link', 'thirtydayhomes' ),
				'type'    => Controls_Manager::URL,
				'options' => [ 'is_external', 'nofollow' ],
				'default' => [ 'url' => (string) ( $default['url'] ?? '' ) ],
			]
		);

		$this->end_controls_section();
	}

	protected function register_controls(): void {

		$tracks = Render::default_hiw_tracks();

		$this->track_controls( 'renter', __( 'For renters', 'thirtydayhomes' ), (array) ( $tracks[0] ?? [] ) );
		$this->track_controls( 'owner', __( 'For property owners', 'thirtydayhomes' ), (array) ( $tracks[1] ?? [] ) );

		/* -----------------------------------------------------------------
		 * FAQ
		 * -------------------------------------------------------------- */

		$this->start_controls_section( 'faq_section', [ 'label' => __( 'Questions', 'thirtydayhomes' ) ] );

		$this->add_control(
			'faq_eyebrow',
			[
				'label'   => __( 'Eyebrow', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Before you ask', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'faq_heading',
			[
				'label'       => __( 'Heading', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Frequently asked questions', 'thirtydayhomes' ),
				'label_block' => true,
			]
		);

		$faq = new Repeater();

		$faq->add_control(
			'q',
			[
				'label'       => __( 'Question', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
			]
		);

		$faq->add_control(
			'a',
			[
				'label' => __( 'Answer', 'thirtydayhomes' ),
				'type'  => Controls_Manager::TEXTAREA,
				'rows'  => 5,
			]
		);

		$this->add_control(
			'faq',
			[
				'label'       => __( 'Questions', 'thirtydayhomes' ),
				'description' => __( 'Only one answer is open at a time, so a long list stays readable.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $faq->get_controls(),
				'title_field' => '{{{ q }}}',
				'default'     => Render::default_hiw_faq(),
			]
		);

		$this->end_controls_section();

		/* -----------------------------------------------------------------
		 * Still not sure
		 * -------------------------------------------------------------- */

		$this->start_controls_section( 'ask_section', [ 'label' => __( 'Still not sure?', 'thirtydayhomes' ) ] );

		$this->add_control(
			'ask_heading',
			[
				'label'       => __( 'Heading', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Still not sure?', 'thirtydayhomes' ),
				'label_block' => true,
			]
		);

		$this->add_control(
			'ask_copy',
			[
				'label'   => __( 'Copy', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXTAREA,
				'rows'    => 2,
				'default' => __( 'Ask us. We answer within one business day.', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'ask_cta',
			[
				'label'   => __( 'Button label', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Contact us', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'ask_url',
			[
				'label'       => __( 'Button link', 'thirtydayhomes' ),
				'description' => __( 'Empty points at the Contact page, and follows it if its address ever changes.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::URL,
				'options'     => [ 'is_external', 'nofollow' ],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Rebuild one track from its flat controls.
	 *
	 * @param array<string,mixed> $s
	 * @param array<string,mixed> $fallback
	 * @return array<string,mixed>
	 */
	private function track( array $s, string $prefix, array $fallback ): array {

		$steps = [];

		foreach ( (array) ( $s[ $prefix . '_steps' ] ?? [] ) as $step ) {
			$steps[] = [
				'title' => (string) ( $step['title'] ?? '' ),
				'copy'  => (string) ( $step['copy'] ?? '' ),
			];
		}

		return [
			'icon'    => (string) ( $s[ $prefix . '_icon' ] ?? '' ),
			'eyebrow' => (string) ( $s[ $prefix . '_eyebrow' ] ?? '' ),
			'heading' => (string) ( $s[ $prefix . '_heading' ] ?? '' ),

			// An emptied repeater falls back rather than leaving a track
			// heading standing over nothing. Deleting every step mid-edit is
			// far more often a slip than an instruction.
			'steps'   => $steps ?: (array) ( $fallback['steps'] ?? [] ),
			'cta'     => (string) ( $s[ $prefix . '_cta' ] ?? '' ),
			'url'     => (string) ( $s[ $prefix . '_url' ]['url'] ?? '' ),
		];
	}

	protected function render(): void {

		$s        = $this->get_settings_for_display();
		$defaults = Render::default_hiw_tracks();

		$faq = [];
		foreach ( (array) ( $s['faq'] ?? [] ) as $row ) {
			$faq[] = [
				'q' => (string) ( $row['q'] ?? '' ),
				'a' => (string) ( $row['a'] ?? '' ),
			];
		}

		$args = [
			'tracks'      => [
				$this->track( $s, 'renter', (array) ( $defaults[0] ?? [] ) ),
				$this->track( $s, 'owner', (array) ( $defaults[1] ?? [] ) ),
			],
			'faq_eyebrow' => (string) ( $s['faq_eyebrow'] ?? '' ),
			'faq_heading' => (string) ( $s['faq_heading'] ?? '' ),
			'faq'         => $faq ?: Render::default_hiw_faq(),
			'ask_heading' => (string) ( $s['ask_heading'] ?? '' ),
			'ask_copy'    => (string) ( $s['ask_copy'] ?? '' ),
			'ask_cta'     => (string) ( $s['ask_cta'] ?? '' ),
			'ask_url'     => (string) ( $s['ask_url']['url'] ?? '' ),
		];

		/*
		 * Dropped when empty rather than passed as ''. wp_parse_args only
		 * fills keys that are ABSENT, so an empty string would override
		 * Render's default — the Contact page's own permalink — with
		 * nothing, and the button would disappear. Unsetting it also means
		 * the link follows that page if its slug ever changes.
		 */
		if ( '' === $args['ask_url'] ) {
			unset( $args['ask_url'] );
		}

		echo Render::how_it_works( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
