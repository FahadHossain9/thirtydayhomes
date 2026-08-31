<?php
/**
 * About page widget.
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
 * Visual wrapper around [tdh_about].
 *
 * ─── WHY THIS EXISTS ───────────────────────────────────────────────────────
 *
 * The About page shipped as the bare shortcode `[tdh_about]`, with all of its
 * copy as PHP defaults inside TDH\Render. Measured on the live site, that was
 * 312 rendered words of which the administrator could edit two — the rest
 * needed a developer and a deploy.
 *
 * Handoff principle 2 requires public sections to be "editable through
 * Elementor without editing PHP", and Milestone 1 acceptance test 6 checks
 * exactly that. So every string on the page is a control here.
 *
 * ─── ONE WIDGET FOR FOUR BANDS ─────────────────────────────────────────────
 *
 * The page is four bands — statement, what to expect, rules, two doors — and
 * they share one background rhythm and one container measure. Splitting them
 * into four widgets would let somebody delete the third and leave the page
 * with two tinted bands touching, which is a layout bug reported as ours.
 * Repeaters keep each band's shape while leaving its content open.
 */
final class About extends Widget_Base {

	public function get_name(): string {
		return 'tdh-about';
	}

	public function get_title(): string {
		return __( 'About Page', 'thirtydayhomes' );
	}

	public function get_icon(): string {
		return 'eicon-info-circle-o';
	}

	/** @return string[] */
	public function get_categories(): array {
		return [ Registrar::CATEGORY ];
	}

	/** @return string[] */
	public function get_keywords(): array {
		return [ 'about', 'company', 'rules', 'fair housing' ];
	}

	/**
	 * Put a plain URL string into the shape Elementor's URL control stores.
	 *
	 * TDH\Render works in plain arrays, because the shortcode has to keep
	 * working with Elementor deactivated — that is the whole point of the
	 * shortcode-first arrangement. Elementor's URL control does not: it
	 * stores `[ 'url' => …, 'is_external' => …, 'nofollow' => … ]`.
	 *
	 * Seeding the repeater with Render's plain strings therefore produced
	 * rows whose URL read as empty, and Render skips a door with no link —
	 * correctly, since a door is a link. The result was an About page that
	 * silently lost its final section, 46 words, when rendered through the
	 * widget rather than the shortcode.
	 *
	 * The translation lives here rather than in Render so the renderer stays
	 * free of Elementor's shapes.
	 *
	 * @param array<int,array<string,string>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private static function as_link_rows( array $rows, string $key ): array {

		foreach ( $rows as &$row ) {
			$row[ $key ] = [
				'url'         => (string) ( $row[ $key ] ?? '' ),
				'is_external' => '',
				'nofollow'    => '',
			];
		}

		return $rows;
	}

	/**
	 * The icons a card or door may use.
	 *
	 * A fixed list, not free entry: these are the Lucide icons bundled with
	 * the theme, and a name that is not among them renders nothing at all.
	 *
	 * @return array<string,string>
	 */
	private function icon_options(): array {
		return [
			''             => __( 'No icon', 'thirtydayhomes' ),
			'shield-check' => __( 'Shield — trust, verification', 'thirtydayhomes' ),
			'key-round'    => __( 'Key — owners, listing', 'thirtydayhomes' ),
			'bed-double'   => __( 'Bed — homes, stays', 'thirtydayhomes' ),
			'search'       => __( 'Search — renters, finding', 'thirtydayhomes' ),
			'map-pin'      => __( 'Map pin — location', 'thirtydayhomes' ),
			'calendar-days' => __( 'Calendar — dates', 'thirtydayhomes' ),
			'check'        => __( 'Check', 'thirtydayhomes' ),
		];
	}

	protected function register_controls(): void {

		/* -----------------------------------------------------------------
		 * The statement, and the three facts beside it
		 * -------------------------------------------------------------- */

		$this->start_controls_section( 'intro_section', [ 'label' => __( 'Opening statement', 'thirtydayhomes' ) ] );

		$this->add_control(
			'statement',
			[
				'label'       => __( 'Statement', 'thirtydayhomes' ),
				'description' => __( 'The page’s thesis, set large beside the facts. It is designed to be a full sentence — a very short one looks lost in the space.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 4,
				'default'     => __( 'ThirtyDayHomes connects traveling professionals and families with verified furnished homes near the places they need to be — starting in Pittsburgh, and built to expand.', 'thirtydayhomes' ),
			]
		);

		$facts = new Repeater();

		$facts->add_control(
			'value',
			[
				'label'       => __( 'Subject', 'thirtydayhomes' ),
				'description' => __( 'Set large. Keep it to a few words.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
			]
		);

		$facts->add_control(
			'label',
			[
				'label'       => __( 'Rest of the sentence', 'thirtydayhomes' ),
				'description' => __( 'Reads on from the subject, so it should continue the sentence rather than start a new one.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 3,
			]
		);

		$this->add_control(
			'facts',
			[
				'label'       => __( 'Facts', 'thirtydayhomes' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $facts->get_controls(),
				'title_field' => '{{{ value }}}',
				'default'     => Render::default_about_facts(),
			]
		);

		$this->end_controls_section();

		/* -----------------------------------------------------------------
		 * What to expect
		 * -------------------------------------------------------------- */

		$this->start_controls_section( 'expect_section', [ 'label' => __( 'What to expect', 'thirtydayhomes' ) ] );

		$this->add_control(
			'expect_eyebrow',
			[
				'label'   => __( 'Eyebrow', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'For renters and owners', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'expect_heading',
			[
				'label'       => __( 'Heading', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'What to expect', 'thirtydayhomes' ),
				'label_block' => true,
			]
		);

		$this->add_control(
			'expect_intro',
			[
				'label'   => __( 'Intro', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXTAREA,
				'rows'    => 3,
				'default' => __( 'Clear information, direct communication, and a thoughtfully designed experience for extended stays.', 'thirtydayhomes' ),
			]
		);

		$cards = new Repeater();

		$cards->add_control(
			'icon',
			[
				'label'   => __( 'Icon', 'thirtydayhomes' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'shield-check',
				'options' => $this->icon_options(),
			]
		);

		$cards->add_control(
			'title',
			[
				'label'       => __( 'Title', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
			]
		);

		$cards->add_control(
			'copy',
			[
				'label' => __( 'Description', 'thirtydayhomes' ),
				'type'  => Controls_Manager::TEXTAREA,
				'rows'  => 4,
			]
		);

		$this->add_control(
			'expect_cards',
			[
				'label'       => __( 'Cards', 'thirtydayhomes' ),
				'description' => __( 'Three sit side by side on a desktop. A fourth wraps onto its own row and looks unbalanced.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $cards->get_controls(),
				'title_field' => '{{{ title }}}',
				'default'     => Render::default_about_cards(),
			]
		);

		$this->end_controls_section();

		/* -----------------------------------------------------------------
		 * Rules
		 * -------------------------------------------------------------- */

		$this->start_controls_section( 'rules_section', [ 'label' => __( 'Rules and regulations', 'thirtydayhomes' ) ] );

		$this->add_control(
			'rules_eyebrow',
			[
				'label'   => __( 'Eyebrow', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'The ground rules', 'thirtydayhomes' ),
			]
		);

		$this->add_control(
			'rules_heading',
			[
				'label'       => __( 'Heading', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Rules and regulations', 'thirtydayhomes' ),
				'label_block' => true,
			]
		);

		$this->add_control(
			'rules_intro',
			[
				'label'   => __( 'Intro', 'thirtydayhomes' ),
				'type'    => Controls_Manager::TEXTAREA,
				'rows'    => 4,
				'default' => __( 'Renters and landlords must provide accurate information, communicate respectfully, follow Fair Housing requirements, and acknowledge property-specific rules before an inquiry is sent.', 'thirtydayhomes' ),
			]
		);

		$rules = new Repeater();

		$rules->add_control(
			'title',
			[
				'label'       => __( 'Rule', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
			]
		);

		$rules->add_control(
			'copy',
			[
				'label' => __( 'Explanation', 'thirtydayhomes' ),
				'type'  => Controls_Manager::TEXTAREA,
				'rows'  => 3,
			]
		);

		$this->add_control(
			'rules',
			[
				'label'       => __( 'Rules', 'thirtydayhomes' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $rules->get_controls(),
				'title_field' => '{{{ title }}}',
				'default'     => Render::default_about_rules(),
			]
		);

		$this->add_control(
			'rules_link_text',
			[
				'label'       => __( 'Link label', 'thirtydayhomes' ),
				'description' => __( 'Leave empty to hide the link.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Read our Fair Housing commitment', 'thirtydayhomes' ),
				'label_block' => true,
			]
		);

		$this->add_control(
			'rules_link_url',
			[
				'label'       => __( 'Link', 'thirtydayhomes' ),
				'description' => __( 'Empty points at the Fair Housing page.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::URL,
				'options'     => [ 'is_external', 'nofollow' ],
			]
		);

		$this->end_controls_section();

		/* -----------------------------------------------------------------
		 * The two doors
		 * -------------------------------------------------------------- */

		$this->start_controls_section( 'doors_section', [ 'label' => __( 'The two doors', 'thirtydayhomes' ) ] );

		$doors = new Repeater();

		$doors->add_control(
			'icon',
			[
				'label'   => __( 'Icon', 'thirtydayhomes' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'search',
				'options' => $this->icon_options(),
			]
		);

		$doors->add_control(
			'title',
			[
				'label'       => __( 'Title', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
			]
		);

		$doors->add_control(
			'copy',
			[
				'label' => __( 'Description', 'thirtydayhomes' ),
				'type'  => Controls_Manager::TEXTAREA,
				'rows'  => 3,
			]
		);

		$doors->add_control(
			'cta',
			[
				'label'       => __( 'Link label', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
			]
		);

		$doors->add_control(
			'url',
			[
				'label'   => __( 'Link', 'thirtydayhomes' ),
				'type'    => Controls_Manager::URL,
				'options' => [ 'is_external', 'nofollow' ],
			]
		);

		$this->add_control(
			'doors',
			[
				'label'       => __( 'Doors', 'thirtydayhomes' ),
				'description' => __( 'Two, side by side: one for renters and one for owners. About is the page both audiences read, so a single call to action sends half of them the wrong way. A door with no link is not shown.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $doors->get_controls(),
				'title_field' => '{{{ title }}}',
				'default'     => self::as_link_rows( Render::default_about_doors(), 'url' ),
			]
		);

		$this->add_control(
			'note',
			[
				'label'       => __( 'Draft notice', 'thirtydayhomes' ),
				'description' => __( 'The quiet line at the foot of the page. Empty it once the client has signed the copy off.', 'thirtydayhomes' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Draft copy, to be reviewed and approved before launch.', 'thirtydayhomes' ),
				'label_block' => true,
			]
		);

		$this->end_controls_section();
	}

	protected function render(): void {

		$s = $this->get_settings_for_display();

		/**
		 * Flatten one repeater row, keeping only the keys Render expects.
		 *
		 * Elementor stores a URL control as an array; Render wants a string.
		 * Doing that here rather than in Render keeps the renderer free of
		 * Elementor's shapes, which is what lets the shortcode keep working
		 * with the plugin's own plain arrays.
		 *
		 * @param array<string,mixed> $row
		 * @param string[]            $text
		 * @return array<string,string>
		 */
		$row = static function ( array $row, array $text, string $url_key = '' ): array {

			$out = [];

			foreach ( $text as $key ) {
				$out[ $key ] = (string) ( $row[ $key ] ?? '' );
			}

			if ( '' !== $url_key ) {
				$out[ $url_key ] = (string) ( $row[ $url_key ]['url'] ?? '' );
			}

			return $out;
		};

		$facts = [];
		foreach ( (array) ( $s['facts'] ?? [] ) as $r ) {
			$facts[] = $row( (array) $r, [ 'value', 'label' ] );
		}

		$cards = [];
		foreach ( (array) ( $s['expect_cards'] ?? [] ) as $r ) {
			$cards[] = $row( (array) $r, [ 'icon', 'title', 'copy' ] );
		}

		$rules = [];
		foreach ( (array) ( $s['rules'] ?? [] ) as $r ) {
			$rules[] = $row( (array) $r, [ 'title', 'copy' ] );
		}

		$doors = [];
		foreach ( (array) ( $s['doors'] ?? [] ) as $r ) {
			$doors[] = $row( (array) $r, [ 'icon', 'title', 'copy', 'cta' ], 'url' );
		}

		/*
		 * An emptied repeater falls back to the defaults rather than
		 * rendering a band with no contents. Deleting every card is far more
		 * often a mistake mid-edit than an instruction to leave a heading
		 * standing over nothing.
		 */
		$args = [
			'statement'       => (string) ( $s['statement'] ?? '' ),
			'facts'           => $facts ?: Render::default_about_facts(),
			'expect_eyebrow'  => (string) ( $s['expect_eyebrow'] ?? '' ),
			'expect_heading'  => (string) ( $s['expect_heading'] ?? '' ),
			'expect_intro'    => (string) ( $s['expect_intro'] ?? '' ),
			'expect_cards'    => $cards ?: Render::default_about_cards(),
			'rules_eyebrow'   => (string) ( $s['rules_eyebrow'] ?? '' ),
			'rules_heading'   => (string) ( $s['rules_heading'] ?? '' ),
			'rules_intro'     => (string) ( $s['rules_intro'] ?? '' ),
			'rules'           => $rules ?: Render::default_about_rules(),
			'rules_link_text' => (string) ( $s['rules_link_text'] ?? '' ),
			'rules_link_url'  => (string) ( $s['rules_link_url']['url'] ?? '' ),
			'doors'           => $doors ?: Render::default_about_doors(),
			'note'            => (string) ( $s['note'] ?? '' ),
		];

		/*
		 * The link URL is DROPPED when empty rather than passed as ''.
		 *
		 * wp_parse_args only fills in keys that are ABSENT, so sending an
		 * empty string would override Render's default — the Fair Housing
		 * page's own permalink — with nothing, and the link would vanish.
		 * The control's description promises the opposite: "Empty points at
		 * the Fair Housing page." Unsetting it is what keeps that promise,
		 * and it also means the link follows the page if its slug ever
		 * changes, instead of freezing whatever URL was correct on the day
		 * somebody opened Elementor.
		 */
		if ( '' === $args['rules_link_url'] ) {
			unset( $args['rules_link_url'] );
		}

		echo Render::about( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
