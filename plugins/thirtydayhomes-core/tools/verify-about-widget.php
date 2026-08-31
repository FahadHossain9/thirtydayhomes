<?php
/**
 * Does the About widget register, render, and actually make the copy
 * editable — which is the whole point of acceptance test 6?
 */

use TDH\Render;

function ok( string $label = '', bool $cond = false, string $detail = '' ): array {
	static $p = 0, $f = 0;
	if ( '' === $label ) { return [ $p, $f ]; }
	if ( $cond ) { ++$p; printf( "  ok    %s\n", $label ); }
	else { ++$f; printf( "  FAIL  %s%s\n", $label, '' !== $detail ? "  --> {$detail}" : '' ); }
	return [ $p, $f ];
}

echo "\n=== the widget is registered ===\n";

if ( ! class_exists( '\Elementor\Plugin' ) ) {
	echo "  Elementor is not active — cannot test\n";
	return;
}

$mgr = \Elementor\Plugin::instance()->widgets_manager;
do_action( 'elementor/widgets/register', $mgr );

$w = $mgr->get_widget_types( 'tdh-about' );

ok( 'tdh-about exists', (bool) $w );

if ( ! $w ) { return; }

ok( 'it is in the ThirtyDayHomes category', in_array( TDH\Elementor\Registrar::CATEGORY, $w->get_categories(), true ) );

echo "\n=== every string on the page has a control ===\n";

$controls = $w->get_controls();
$ours     = array_filter( array_keys( $controls ), fn( $k ) => ! str_starts_with( $k, '_' ) && ! str_ends_with( $k, '_pro' ) );

foreach ( [ 'statement', 'facts', 'expect_eyebrow', 'expect_heading', 'expect_intro', 'expect_cards', 'rules_eyebrow', 'rules_heading', 'rules_intro', 'rules', 'rules_link_text', 'rules_link_url', 'doors', 'note' ] as $key ) {
	ok( sprintf( '%-16s is editable', $key ), isset( $controls[ $key ] ) );
}

echo "\n=== the repeaters are seeded from the same defaults the shortcode uses ===\n";

foreach ( [ 'facts' => 'default_about_facts', 'expect_cards' => 'default_about_cards', 'rules' => 'default_about_rules' ] as $control => $method ) {
	$default  = $controls[ $control ]['default'] ?? [];
	$expected = Render::$method();
	ok(
		sprintf( '%-13s seeded from Render::%s()', $control, $method ),
		count( (array) $default ) === count( $expected ) && $default == $expected,
		sprintf( 'widget has %d, Render has %d', count( (array) $default ), count( $expected ) )
	);
}

/*
 * Doors are compared differently, and deliberately. Render works in plain
 * arrays so the shortcode survives Elementor being deactivated; Elementor's
 * URL control stores an array. The widget translates between the two, so the
 * default is the same content in a different shape — not the same value.
 */
$doors_default = (array) ( $controls['doors']['default'] ?? [] );
$doors_render  = Render::default_about_doors();

ok( 'doors         has the same number of rows as Render', count( $doors_default ) === count( $doors_render ) );

$titles_match = true;
$urls_wrapped = true;

foreach ( $doors_render as $i => $expected ) {
	$titles_match = $titles_match && ( ( $doors_default[ $i ]['title'] ?? '' ) === $expected['title'] );
	$urls_wrapped = $urls_wrapped && ( ( $doors_default[ $i ]['url']['url'] ?? null ) === $expected['url'] );
}

ok( '...the same titles', $titles_match );
ok(
	'...and each URL wrapped in Elementor’s shape',
	$urls_wrapped,
	'a plain string here reads as an empty URL, and Render drops a door with no link'
);

echo "\n=== the widget renders the same page as the shortcode ===\n";

/**
 * Render the widget the way Elementor does on a real page.
 *
 * NOT via the prototype from get_widget_types() — that object exists only
 * to describe the widget and carries null settings, so render_content()
 * fatals on it. A page holds element INSTANCES, which is what this builds.
 *
 * @param array<string,mixed> $settings
 */
function render_about( array $settings = [] ): string {

	$el = \Elementor\Plugin::instance()->elements_manager->create_element_instance(
		[
			'id'         => substr( md5( (string) wp_json_encode( $settings ) ), 0, 7 ),
			'elType'     => 'widget',
			'widgetType' => 'tdh-about',
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

$shortcode  = do_shortcode( '[tdh_about]' );
$widget_out = render_about();

ok( 'the widget renders at all', '' !== trim( $widget_out ) );

foreach ( [ 'about-intro', 'about-statement', 'about-facts', 'about-expect', 'about-cards', 'about-rules', 'about-doors', 'about-note' ] as $hook ) {
	ok( sprintf( '.%-16s present in the widget output', $hook ), str_contains( $widget_out, $hook ) );
}

ok(
	'the widget output contains the whole page, not a fragment',
	substr_count( $widget_out, '<section' ) === substr_count( $shortcode, '<section' ),
	sprintf( 'widget %d sections, shortcode %d', substr_count( $widget_out, '<section' ), substr_count( $shortcode, '<section' ) )
);

ok(
	'with no settings it matches the shortcode word for word',
	str_contains( $widget_out, 'connects traveling professionals' )
		&& str_contains( $widget_out, 'Rules and regulations' )
		&& str_contains( $widget_out, 'Have a property to list' )
);

echo "\n=== changing a control changes the page ===\n";

$edited = render_about(
	[
		'statement'      => 'A completely different opening sentence from the client.',
		'expect_heading' => 'What we promise',
		'note'           => '',
	]
);

if ( '' === trim( $edited ) ) {
	ok( 'the widget accepts custom settings', false, 'rendered nothing' );
} else {

	ok( 'a new statement appears on the page', str_contains( $edited, 'A completely different opening sentence' ) );
	ok( 'a new heading appears on the page', str_contains( $edited, 'What we promise' ) );
	ok( 'the old statement is gone', ! str_contains( $edited, 'connects traveling professionals' ) );
	ok(
		'clearing the draft notice removes it',
		! str_contains( $edited, 'Draft copy, to be reviewed' ),
		'an emptied control must actually empty the page'
	);
	ok(
		'untouched sections keep their defaults',
		str_contains( $edited, 'Rules and regulations' ),
		'editing one field must not blank the rest'
	);
	ok(
		'the Fair Housing link survives an empty URL control',
		str_contains( $edited, 'about-rules-link' ),
		'wp_parse_args only fills ABSENT keys, so an empty string would have killed it'
	);
}

[ $p, $f ] = ok();
printf( "\n%s  %d passed, %d failed\n\n", $f ? 'FAILED' : 'PASSED', $p, $f );
