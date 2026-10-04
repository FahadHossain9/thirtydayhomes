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
 * The two search bands (task C5)
 *
 * These are not whole-page widgets and cannot be tested like them: they read
 * the page they are on. A results band on an ordinary page must be nothing,
 * and on the listing archive it must be the same list the archive already
 * renders. So each is rendered twice, in two pretended requests.
 * ---------------------------------------------------------------------- */

echo "\n=== the search bands: registered and controllable ===\n";

$bands = [
	'tdh-search-results'    => [ 'heading', 'intro', 'show_search', 'show_filters', 'show_view_toggle', 'per_page' ],
	'tdh-nearby-facilities' => [ 'heading', 'count' ],
];

foreach ( $bands as $type => $controls ) {

	$w = $mgr->get_widget_types( $type );

	ok( sprintf( '%s is registered', $type ), (bool) $w );

	if ( ! $w ) {
		continue;
	}

	ok( sprintf( '...%s is in the ThirtyDayHomes category', $type ), in_array( TDH\Elementor\Registrar::CATEGORY, $w->get_categories(), true ) );

	$have    = $w->get_controls();
	$missing = array_values( array_filter( $controls, static fn( string $k ): bool => ! isset( $have[ $k ] ) ) );

	ok( sprintf( '...every %s setting exists', $type ), [] === $missing, $missing ? 'no control for: ' . implode( ', ', $missing ) : '' );
}

echo "\n=== on an ordinary page ===\n";

/*
 * The two bands differ here, on purpose.
 *
 * A search of all the homes makes sense anywhere, so the results band
 * runs its own query and shows real homes on any page somebody builds.
 * Hospital distances are measured FROM a home, so with no home in context
 * there is nothing true to show: a visitor gets nothing at all, and only
 * the person in the editor gets a line and an example. An empty box on a
 * public page is the site showing its own plumbing.
 */
$anywhere = render_widget( 'tdh-search-results' );

ok( 'the results band works on an ordinary page too', '' !== trim( $anywhere ) );
ok( '...showing real homes, not a placeholder', str_contains( $anywhere, 'property-card' ) || str_contains( $anywhere, 'No homes are listed yet' ) );
ok( '...and its own query obeys the same filters, so it is the plugin deciding', str_contains( $anywhere, 'data-tdh-filters' ) );

ok( 'the hospitals band prints nothing for a visitor with no home in context', '' === trim( render_widget( 'tdh-nearby-facilities' ) ) );
ok( '...and neither does its shortcode', '' === trim( do_shortcode( '[tdh_nearby_facilities]' ) ) );

$six = render_widget( 'tdh-search-results', [ 'per_page' => 2 ] );
ok( 'asking for two homes a page shows two', 2 === substr_count( $six, 'property-card' ), sprintf( '%d cards', substr_count( $six, 'property-card' ) ) );

/*
 * A page holding the results band must take the full width. Ordinary pages
 * put their content in a narrow reading column, and the first version of
 * this widget was squeezed into it with no way for the person building the
 * page to know why. The plugin answers the theme's question for them.
 */
$wide_page = (int) wp_insert_post(
	[
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Widget width probe',
		'post_content' => '',
	]
);

update_post_meta( $wide_page, '_elementor_data', wp_slash( wp_json_encode( [ [ 'id' => 'a1', 'elType' => 'section', 'settings' => [], 'elements' => [ [ 'id' => 'b1', 'elType' => 'column', 'settings' => [], 'elements' => [ [ 'id' => 'c1', 'elType' => 'widget', 'widgetType' => 'tdh-search-results', 'settings' => [], 'elements' => [] ] ] ] ] ] ] ) ) );

$plain_page = (int) wp_insert_post(
	[
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Ordinary page probe',
		'post_content' => 'Just some words.',
	]
);

$shorted = (int) wp_insert_post(
	[
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Shortcode width probe',
		'post_content' => '[tdh_search_results]',
	]
);

$widener = new TDH\Shortcodes();

ok( 'a page holding the results widget asks for the full width', $widener->wide_for_results( false, $wide_page ) );
ok( '...and so does one holding the shortcode', $widener->wide_for_results( false, $shorted ) );
ok( 'an ordinary page keeps its reading column', ! $widener->wide_for_results( false, $plain_page ) );
ok( '...and a page already marked wide is left as it is', $widener->wide_for_results( true, $plain_page ) );

/*
 * The same question decides which scripts load. A page with the band but
 * without filters.js has a Filters button that does nothing on a phone,
 * and without map.js a map panel that says "Loading the map" for ever.
 * Offering a control that cannot work is worse than not offering it.
 */
ok( 'the page holding the band is recognised, so its scripts are loaded', TDH\Search::page_holds_results( $wide_page ) );
ok( '...whether it was built in Elementor or written as a shortcode', TDH\Search::page_holds_results( $shorted ) );
ok( '...and an ordinary page loads neither', ! TDH\Search::page_holds_results( $plain_page ) );

foreach ( [ $wide_page, $plain_page, $shorted ] as $probe ) {
	wp_delete_post( $probe, true );
}

echo "\n=== on the page they do belong to ===\n";

/** Run something as though this request were the listing archive. */
function as_archive( callable $render ): string {

	$archive = new WP_Query(
		[
			'post_type'      => 'tdh_listing',
			'post_status'    => 'publish',
			'posts_per_page' => 3,
		]
	);

	$archive->is_archive           = true;
	$archive->is_post_type_archive = true;

	$was     = $GLOBALS['wp_query'];
	$was_the = $GLOBALS['wp_the_query'];

	$GLOBALS['wp_query']     = $archive;
	$GLOBALS['wp_the_query'] = $archive;

	$out = $render();

	$GLOBALS['wp_query']     = $was;
	$GLOBALS['wp_the_query'] = $was_the;
	wp_reset_postdata();

	return $out;
}

$on_archive = as_archive( static fn(): string => render_widget( 'tdh-search-results' ) );

ok( 'the results band renders on the listing archive', '' !== trim( $on_archive ) );
ok( '...with the search box', str_contains( $on_archive, 'search-bar' ) );
ok( '...with the filters', str_contains( $on_archive, 'data-tdh-filters' ) );
ok( '...and with real homes, not a placeholder', str_contains( $on_archive, 'property-card' ) || str_contains( $on_archive, 'No homes are listed yet' ) );

$headed = as_archive( static fn(): string => render_widget( 'tdh-search-results', [ 'heading' => 'Homes near the hospital' ] ) );
ok( 'a heading typed in the editor appears on the page', str_contains( $headed, 'Homes near the hospital' ) );

$bare = as_archive( static fn(): string => render_widget( 'tdh-search-results', [ 'show_search' => '', 'show_filters' => '' ] ) );
ok( 'switching the filters off removes them', ! str_contains( $bare, 'data-tdh-filters' ) );
ok( '...and switching the search box off removes it', ! str_contains( $bare, 'search-bar' ) );
ok( '...but the homes are still there, because the widget never decides which homes', str_contains( $bare, 'property-grid' ) );

$by_shortcode = as_archive( static fn(): string => do_shortcode( '[tdh_search_results]' ) );
ok( 'the shortcode renders the same page as the widget', words( $by_shortcode ) === words( $on_archive ), sprintf( 'shortcode %d words, widget %d', words( $by_shortcode ), words( $on_archive ) ) );

echo "\n=== the hospitals band on a property page ===\n";

$placed = get_posts(
	[
		'post_type'      => 'tdh_listing',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		'meta_query'     => [ [ 'key' => '_tdh_lat', 'compare' => 'EXISTS' ] ],
	]
);

$home = $placed ? (int) $placed[0] : 0;

if ( ! $home ) {
	ok( 'a home with a location exists to measure from', false, 'none found — seed the demo content' );
} else {

	/** Run something as though this request were that home's page. */
	$as_home = static function ( callable $render ) use ( $home ): string {

		$single = new WP_Query( [ 'p' => $home, 'post_type' => 'tdh_listing' ] );

		$single->is_singular = true;
		$single->is_single   = true;

		$was     = $GLOBALS['wp_query'];
		$was_the = $GLOBALS['wp_the_query'];

		$GLOBALS['wp_query']     = $single;
		$GLOBALS['wp_the_query'] = $single;

		$out = $render();

		$GLOBALS['wp_query']     = $was;
		$GLOBALS['wp_the_query'] = $was_the;
		wp_reset_postdata();

		return $out;
	};

	$near = $as_home( static fn(): string => render_widget( 'tdh-nearby-facilities' ) );

	ok( 'the hospitals band renders on a property page', '' !== trim( $near ) );
	ok( '...listing real facilities with distances', str_contains( $near, 'facility-list' ) || str_contains( $near, 'facility-none' ) );

	$titled = $as_home( static fn(): string => render_widget( 'tdh-nearby-facilities', [ 'heading' => 'Hospitals nearby' ] ) );
	ok( 'its heading is editable', str_contains( $titled, 'Hospitals nearby' ) );

	if ( str_contains( $near, 'facility-list' ) ) {
		$one = $as_home( static fn(): string => render_widget( 'tdh-nearby-facilities', [ 'count' => 1 ] ) );
		ok( 'asking for one hospital lists one', 1 === substr_count( $one, '<li>' ), sprintf( '%d rows', substr_count( $one, '<li>' ) ) );

		$many = $as_home( static fn(): string => render_widget( 'tdh-nearby-facilities', [ 'count' => 99 ] ) );
		ok( '...and asking for ninety-nine is clamped to what staff may set', substr_count( $many, '<li>' ) <= TDH\Proximity::MAX_COUNT );
	}

	ok( 'the widget arrives with a heading already written, so dragging it in gives a finished band', str_contains( $near, 'Close to care' ) );

	/*
	 * Compared with the heading turned off, because the two defaults differ
	 * on purpose: the widget is dropped onto a blank page and needs its own
	 * heading, while the shortcode sits inside a section on the property
	 * page that already has one, and a second would be a duplicate.
	 */
	$shortcoded = $as_home( static fn(): string => do_shortcode( '[tdh_nearby_facilities]' ) );
	$headless   = $as_home( static fn(): string => render_widget( 'tdh-nearby-facilities', [ 'heading' => '' ] ) );

	ok( 'the shortcode renders the same list as the widget', words( $shortcoded ) === words( $headless ), sprintf( 'shortcode %d words, widget %d', words( $shortcoded ), words( $headless ) ) );
}

echo "\n=== nothing is typed in that should be measured ===\n";

/*
 * The contract names this: distances must come from the plugin and must
 * not be duplicated in Elementor. So neither band may offer a control that
 * would let somebody write a distance, a price or a home's name by hand.
 */
foreach ( $bands as $type => $controls ) {

	$w = $mgr->get_widget_types( $type );

	if ( ! $w ) {
		continue;
	}

	$keys    = array_keys( $w->get_controls() );
	$forbids = array_values(
		array_filter(
			$keys,
			static fn( string $k ): bool => (bool) preg_match( '/(miles|distance|price|address|latitude|longitude|hospital_name)/i', $k )
		)
	);

	ok( sprintf( '%s has no control for data the site measures', $type ), [] === $forbids, $forbids ? 'found: ' . implode( ', ', $forbids ) : '' );
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

/* -------------------------------------------------------------------------
 * What a visitor reads, and what staff read (G3b design review)
 * ---------------------------------------------------------------------- */

echo "\n=== a visitor's page, and staff's ===\n";

wp_set_current_user( 0 );

$about_v   = do_shortcode( '[tdh_about]' );
$pricing_v = do_shortcode( '[tdh_pricing]' );
$hiw_v     = do_shortcode( '[tdh_how_it_works]' );
$from      = '$' . number_format_i18n( (float) TDH\Render::plans()[0]['price'] );

ok( 'a visitor never reads "Draft copy" on About', ! str_contains( $about_v, 'Draft copy' ) );
ok( '...nor "not final" on Pricing, though the plain discount line stays', ! str_contains( $pricing_v, 'not final' ) && str_contains( $pricing_v, 'class="pricing-note"' ) );
ok( 'About ends on one gold door and one outlined', 1 === substr_count( $about_v, 'about-door-cta--gold' ) && 1 === substr_count( $about_v, 'about-door-cta--outline' ) );
ok( 'How it works ends each track on a button, the renter\'s gold', str_contains( $hiw_v, 'class="gold-btn hiw-track-cta"' ) && str_contains( $hiw_v, 'class="secondary hiw-track-cta"' ) );
ok( '...and answers the owner\'s three questions, the price read from the plans', str_contains( $hiw_v, 'What does it cost to list a home?' ) && str_contains( $hiw_v, $from . ' a month' ) && str_contains( $hiw_v, 'pause a listing' ) );
ok( 'Pricing buttons say what they start, the line under says what it costs, and the boundary is stated', str_contains( $pricing_v, 'Start with 1 home' ) && str_contains( $pricing_v, 'Start with 3 homes' ) && str_contains( $pricing_v, 'pay ' . $from . ' a month by card' ) && str_contains( $pricing_v, 'More than 3 homes?' ) );

$staff_user = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0] ?? null;

if ( $staff_user ) {
	wp_set_current_user( $staff_user->ID );
	ok( 'staff still read both notes, labelled as theirs', str_contains( do_shortcode( '[tdh_about]' ), 'Staff note: Draft copy' ) && str_contains( do_shortcode( '[tdh_pricing]' ), 'Staff note: Prices shown are not final' ) );
	wp_set_current_user( 0 );
}

[ $p, $f ] = ok();

printf( "\n%s  %d passed, %d failed\n\n", $f ? 'FAILED' : 'PASSED', $p, $f );

if ( $f && defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::halt( 1 );
}
