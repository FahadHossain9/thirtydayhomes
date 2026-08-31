<?php
/**
 * The whole-page Elementor widgets — verification suite.
 *
 *   wp eval-file wp-content/plugins/thirtydayhomes-core/tools/verify-page-widgets.php
 *
 * These widgets exist for one reason: Milestone 1 acceptance test 6 says an
 * administrator must be able to change public sections through Elementor
 * without editing PHP. So the suite asks three things of each:
 *
 *   1. is every string on the page a control?
 *   2. does it render the SAME page the shortcode renders?
 *   3. does editing a control actually change the page?
 *
 * The second is the one that earns its keep. The About widget silently
 * dropped its final section — both doors, 46 words — because Render works
 * in plain arrays while Elementor's URL control stores an array, so every
 * door's link read as empty and Render correctly skipped a door with no
 * link. Nothing errored. The page simply ended early. Comparing rendered
 * word counts is what caught it, and it is why this file compares them.
 *
 * Changes nothing: every widget is rendered from a throwaway instance, and
 * no post is touched.
 *
 * @package ThirtyDayHomes
 */

use TDH\Render;

function ok( string $label = '', bool $cond = false, string $detail = '' ): array {
	static $p = 0, $f = 0;
	if ( '' === $label ) { return [ $p, $f ]; }
	if ( $cond ) { ++$p; printf( "  ok    %s\n", $label ); }
	else { ++$f; printf( "  FAIL  %s%s\n", $label, '' !== $detail ? "  --> {$detail}" : '' ); }
	return [ $p, $f ];
}

if ( ! class_exists( '\Elementor\Plugin' ) ) {
	echo "\n  Elementor is not active — these widgets cannot be tested here.\n";
	echo "  The shortcode versions still render, which is the point of the fallback.\n\n";
	return;
}

$mgr = \Elementor\Plugin::instance()->widgets_manager;
do_action( 'elementor/widgets/register', $mgr );

/**
 * Render a widget the way a real page does.
 *
 * NOT through the prototype from get_widget_types(): that object exists to
 * describe the widget and carries null settings, so render_content() fatals
 * on it. A page holds element INSTANCES.
 *
 * @param array<string,mixed> $settings
 */
function render_widget( string $type, array $settings = [] ): string {

	$el = \Elementor\Plugin::instance()->elements_manager->create_element_instance(
		[
			'id'         => substr( md5( $type . wp_json_encode( $settings ) ), 0, 7 ),
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => $settings,
			'elements'   => [],
		]
	);

	if ( ! $el ) {
		return '';
	}

	ob_start();
	$el->render_content();

	return (string) ob_get_clean();
}

/** Visible words, so a missing section shows up as a number. */
function words( string $html ): int {
	return str_word_count( trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) ) );
}

/*
 * Each page under test: its widget, its shortcode, the style hooks that
 * prove every band rendered, and an edit that must visibly change it.
 */
$pages = [
	'About'         => [
		'widget'    => 'tdh-about',
		'shortcode' => '[tdh_about]',
		'hooks'     => [ 'about-intro', 'about-statement', 'about-facts', 'about-expect', 'about-cards', 'about-rules', 'about-doors' ],
		'controls'  => [ 'statement', 'facts', 'expect_eyebrow', 'expect_heading', 'expect_intro', 'expect_cards', 'rules_eyebrow', 'rules_heading', 'rules_intro', 'rules', 'rules_link_text', 'rules_link_url', 'doors', 'note' ],
		'edit'      => [ 'statement' => 'An entirely new opening line.', 'expect_heading' => 'What we promise' ],
		'expect'    => [ 'An entirely new opening line.', 'What we promise' ],
		'keeps'     => 'Rules and regulations',
	],
	'How it works'  => [
		'widget'    => 'tdh-how-it-works',
		'shortcode' => '[tdh_how_it_works]',
		'hooks'     => [ 'hiw-tracks', 'hiw-track', 'hiw-steps', 'hiw-faq', 'hiw-ask' ],
		'controls'  => [ 'renter_icon', 'renter_eyebrow', 'renter_heading', 'renter_steps', 'renter_cta', 'renter_url', 'owner_icon', 'owner_eyebrow', 'owner_heading', 'owner_steps', 'owner_cta', 'owner_url', 'faq_eyebrow', 'faq_heading', 'faq', 'ask_heading', 'ask_copy', 'ask_cta', 'ask_url' ],
		'edit'      => [ 'renter_heading' => 'If you are moving', 'faq_heading' => 'Common questions' ],
		'expect'    => [ 'If you are moving', 'Common questions' ],
		'keeps'     => 'For property owners',
	],
	'Pricing'       => [
		'widget'    => 'tdh-pricing',
		'shortcode' => '[tdh_pricing]',
		'hooks'     => [ 'pricing-grid', 'plan-tier', 'plan-price', 'plan-included', 'pricing-note' ],
		'controls'  => [ 'eyebrow', 'heading', 'intro', 'included_heading', 'features', 'note', 'note_emphasis' ],
		'edit'      => [ 'heading' => 'One home or ten', 'included_heading' => 'Every plan includes' ],
		'expect'    => [ 'One home or ten', 'Every plan includes' ],
		'keeps'     => 'per home',
	],
	'Contact'       => [
		'widget'    => 'tdh-contact',
		'shortcode' => '[tdh_contact]',
		'hooks'     => [ 'contact-shell', 'contact-promise', 'contact-assurances', 'contact-status', 'contact-panel', 'contact-form' ],
		'controls'  => [ 'eyebrow', 'heading', 'lead', 'assurances', 'status' ],
		'edit'      => [ 'heading' => 'Say hello.', 'status' => 'Answered every weekday' ],
		'expect'    => [ 'Say hello.', 'Answered every weekday' ],
		'keeps'     => 'Send message',
	],
];

foreach ( $pages as $name => $page ) {

	printf( "\n=== %s ===\n", $name );

	$w = $mgr->get_widget_types( $page['widget'] );

	ok( sprintf( '%s is registered', $page['widget'] ), (bool) $w );

	if ( ! $w ) {
		continue;
	}

	ok( '...in the ThirtyDayHomes category', in_array( TDH\Elementor\Registrar::CATEGORY, $w->get_categories(), true ) );

	$controls = $w->get_controls();
	$missing  = [];

	foreach ( $page['controls'] as $key ) {
		if ( ! isset( $controls[ $key ] ) ) {
			$missing[] = $key;
		}
	}

	ok(
		sprintf( 'all %d strings on the page are editable', count( $page['controls'] ) ),
		[] === $missing,
		$missing ? 'no control for: ' . implode( ', ', $missing ) : ''
	);

	/* --- the same page as the shortcode ---------------------------- */

	$shortcode = do_shortcode( $page['shortcode'] );
	$rendered  = render_widget( $page['widget'] );

	ok( 'the widget renders', '' !== trim( $rendered ) );

	$absent = [];
	foreach ( $page['hooks'] as $hook ) {
		if ( ! str_contains( $rendered, $hook ) ) {
			$absent[] = $hook;
		}
	}

	ok( 'every band is present', [] === $absent, $absent ? 'missing: ' . implode( ', ', $absent ) : '' );

	ok(
		'same number of sections as the shortcode',
		substr_count( $rendered, '<section' ) === substr_count( $shortcode, '<section' ),
		sprintf( 'widget %d, shortcode %d', substr_count( $rendered, '<section' ), substr_count( $shortcode, '<section' ) )
	);

	/*
	 * The assertion that found the missing doors. A widget can render every
	 * style hook and still be quietly short of a whole section's worth of
	 * words, because the hook belongs to the band and the content does not.
	 */
	ok(
		'same number of words as the shortcode',
		words( $rendered ) === words( $shortcode ),
		sprintf( 'widget %d words, shortcode %d — a section is missing', words( $rendered ), words( $shortcode ) )
	);

	/* --- editing works --------------------------------------------- */

	$edited = render_widget( $page['widget'], $page['edit'] );

	foreach ( $page['expect'] as $needle ) {
		ok( sprintf( 'an edit appears on the page: "%s"', $needle ), str_contains( $edited, $needle ) );
	}

	ok(
		'editing one field leaves the rest alone',
		str_contains( $edited, $page['keeps'] ),
		sprintf( '"%s" disappeared', $page['keeps'] )
	);
}

/* -------------------------------------------------------------------------
 * The shortcodes still work, which is the whole fallback
 * ---------------------------------------------------------------------- */

echo "\n=== the shortcode fallback ===\n";

foreach ( $pages as $name => $page ) {
	ok(
		sprintf( '%s still renders from its shortcode', $name ),
		words( do_shortcode( $page['shortcode'] ) ) > 50
	);
}

[ $p, $f ] = ok();

printf( "\n%s  %d passed, %d failed\n\n", $f ? 'FAILED' : 'PASSED', $p, $f );

if ( $f && defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::halt( 1 );
}
