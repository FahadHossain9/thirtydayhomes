<?php
/**
 * Front-end assets and resource hints.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Stylesheets and scripts.
 *
 * Fonts come from Google Fonts to match the approved prototype (Playfair
 * Display for display, DM Sans for body). Self-hosting them is a launch
 * task: it removes a third-party request, improves Core Web Vitals, and
 * sidesteps the GDPR question about Google Fonts entirely.
 */
function tdh_enqueue_assets(): void {

	wp_enqueue_style(
		'tdh-fonts',
		'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap',
		[],
		null
	);

	/*
	 * The theme stylesheet must load AFTER Elementor's.
	 *
	 * Elementor ships `.elementor img { height: auto }`. Our art-directed
	 * images use rules like `.split-photo > img { height: 560px }` — which
	 * is the SAME specificity, (0,0,1,1). Equal specificity is decided by
	 * source order, and Elementor's frontend.css was loading second, so
	 * every fixed-height image inside an Elementor container silently lost
	 * its height.
	 *
	 * Raising specificity on each selector would fix today's two cases and
	 * lose again on the next image someone adds. Declaring the dependency
	 * fixes the whole class of problem: the theme owns presentation, so the
	 * theme's stylesheet goes last.
	 *
	 * Conditional because a missing dependency stops WordPress printing the
	 * stylesheet at all — with Elementor deactivated, an unconditional
	 * dependency would take the entire site's CSS with it.
	 */
	$deps = [ 'tdh-fonts' ];

	if ( wp_style_is( 'elementor-frontend', 'registered' ) ) {
		$deps[] = 'elementor-frontend';
	}

	/*
	 * Design tokens load before everything that consumes them.
	 *
	 * Separate file rather than a block at the top of style.css: tokens are
	 * read constantly and changed rarely, and a diff here is always a
	 * system-level decision rather than a component tweak. It is also what
	 * lets the Elementor editor pull in the token layer on its own.
	 */
	wp_enqueue_style(
		'tdh-tokens',
		get_template_directory_uri() . '/assets/design-tokens.css',
		$deps,
		TDH_THEME_VERSION
	);

	wp_enqueue_style( 'tdh-theme', get_stylesheet_uri(), [ 'tdh-tokens' ], TDH_THEME_VERSION );

	wp_enqueue_script(
		'tdh-nav',
		get_template_directory_uri() . '/assets/nav.js',
		[],
		TDH_THEME_VERSION,
		true
	);

	wp_enqueue_script(
		'tdh-saved',
		get_template_directory_uri() . '/assets/saved.js',
		[],
		TDH_THEME_VERSION,
		true
	);

	// The filter drawer, only where the search results are: the listing
	// archive and the city, type, neighborhood and amenity archives.
	/*
	 * The results bar is on the archives — and on any page somebody built
	 * with the search widget or its shortcode. Without this that page gets
	 * the markup and none of the behaviour: a Filters button that does
	 * nothing on a phone, and a map panel that says "Loading the map" for
	 * ever. Offering a control that cannot work is worse than not offering
	 * it, so the scripts follow the block rather than the template.
	 */
	$tdh_is_results = is_post_type_archive( 'tdh_listing' )
		|| is_tax( [ 'tdh_property_type', 'tdh_neighborhood', 'tdh_city', 'tdh_amenity' ] )
		|| ( is_page() && class_exists( '\TDH\Search' ) && \TDH\Search::page_holds_results() );

	if ( $tdh_is_results ) {
		wp_enqueue_script(
			'tdh-filters',
			get_template_directory_uri() . '/assets/filters.js',
			[],
			TDH_THEME_VERSION,
			true
		);
	}

	// The photo viewer and the availability calendar exist only on a
	// property page.
	if ( is_singular( 'tdh_listing' ) ) {
		wp_enqueue_script(
			'tdh-gallery',
			get_template_directory_uri() . '/assets/gallery.js',
			[],
			TDH_THEME_VERSION,
			true
		);

		wp_enqueue_script(
			'tdh-availability',
			get_template_directory_uri() . '/assets/availability.js',
			[],
			TDH_THEME_VERSION,
			true
		);

		// The inquiry form's sending state. Nothing depends on it: without
		// the script the form still sends, it just does not say "Sending…".
		wp_enqueue_script(
			'tdh-inquiry',
			get_template_directory_uri() . '/assets/inquiry.js',
			[],
			TDH_THEME_VERSION,
			true
		);
	}

	/*
	 * The map (task C4). Loaded on a results page that is actually in map
	 * view, and on a property page — never anywhere else, because Google
	 * bills by the map load and a script on a page with no map is a charge
	 * for nothing.
	 *
	 * Our own file goes first so that window.tdhMapsReady exists before
	 * Google's loader calls it. The loader is async: it can take its time
	 * without holding the page up, and if it never arrives map.js says so.
	 */
	$tdh_wants_map = class_exists( '\TDH\Maps' ) && \TDH\Maps::configured()
		&& (
			is_singular( 'tdh_listing' )
			|| ( $tdh_is_results && class_exists( '\TDH\Search' ) && \TDH\Search::VIEW_MAP === \TDH\Search::view() )
		);

	if ( $tdh_wants_map ) {

		wp_enqueue_script(
			'tdh-map',
			get_template_directory_uri() . '/assets/map.js',
			[],
			TDH_THEME_VERSION,
			true
		);

		wp_enqueue_script(
			'tdh-google-maps',
			\TDH\Maps::script_url(),
			[ 'tdh-map' ],
			null, // Google versions its own library; a query string of ours would only confuse its caching.
			true
		);

		wp_script_add_data( 'tdh-google-maps', 'strategy', 'async' );
	}
}
// Priority 20, not the default 10: Elementor registers its frontend styles
// on this hook too, and the dependency check above only works once they
// exist.
add_action( 'wp_enqueue_scripts', 'tdh_enqueue_assets', 20 );

/**
 * Preconnect to the font host so first paint is not blocked.
 *
 * @param string[] $urls          URLs to print.
 * @param string   $relation_type Relation type being printed.
 *
 * @return string[]
 */
function tdh_resource_hints( array $urls, string $relation_type ): array {

	if ( 'preconnect' === $relation_type && wp_style_is( 'tdh-fonts', 'queue' ) ) {
		$urls[] = [ 'href' => 'https://fonts.gstatic.com', 'crossorigin' => '' ];
	}

	return $urls;
}
add_filter( 'wp_resource_hints', 'tdh_resource_hints', 10, 2 );

/**
 * Preload the hero photograph.
 *
 * It is the Largest Contentful Paint element and it is a CSS background,
 * which the browser cannot discover until the stylesheet has parsed. The
 * preload moves that discovery to the first bytes of the document.
 */
function tdh_preload_hero(): void {

	if ( ! is_front_page() ) {
		return;
	}

	printf(
		'<link rel="preload" as="image" href="%s" fetchpriority="high">' . "\n",
		esc_url( tdh_hero_image() )
	);
}
add_action( 'wp_head', 'tdh_preload_hero', 2 );

/**
 * Serve the site icon for a bare /favicon.ico request.
 *
 * WordPress emits correct <link rel="icon"> tags once a site icon is set,
 * and a browser that reads them never asks for /favicon.ico. Some clients
 * ask anyway — crawlers, feed readers, a browser's very first hit — and
 * core's own handler compares REQUEST_URI to the literal '/favicon.ico',
 * so it never matches on an install in a subdirectory. Those requests fall
 * through to a 404.
 *
 * 302 rather than 301: the icon changes when the client changes their
 * branding, and a permanent redirect would be cached past that.
 */
function tdh_redirect_favicon(): void {

	// Matched on the path rather than via is_favicon(), which depends on
	// core flagging the request during routing and does not do so on an
	// install in a subdirectory — the exact case this exists to cover.
	$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );

	if ( 'favicon.ico' !== basename( $path ) ) {
		return;
	}

	$icon = get_site_icon_url( 32 );

	if ( ! $icon ) {
		return;
	}

	wp_safe_redirect( $icon, 302 );
	exit;
}
add_action( 'template_redirect', 'tdh_redirect_favicon' );
