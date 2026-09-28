<?php
/**
 * Breadcrumb trail.
 *
 * Sits where the page template used to print the site name as an overline.
 * That line told the visitor nothing — they can see the site name in the
 * header they just clicked from — so the trail costs no extra height and
 * replaces filler with orientation.
 *
 * Not shown on the front page, on account screens, or on any page that
 * renders its own layout. A trail reading "Home" on the home page is the
 * clutter that gives breadcrumbs a bad name.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Build the trail for the current view.
 *
 * @return array<int,array{label:string,url:string}> Last item has an empty url.
 */
function tdh_breadcrumb_trail(): array {

	$trail = [
		[
			'label' => __( 'Home', 'thirtydayhomes' ),
			'url'   => home_url( '/' ),
		],
	];

	if ( is_singular( 'tdh_listing' ) ) {

		$archive = get_post_type_archive_link( 'tdh_listing' );

		if ( $archive ) {
			$trail[] = [
				'label' => __( 'Find a home', 'thirtydayhomes' ),
				'url'   => (string) $archive,
			];
		}

		// The neighbourhood, when the listing has one. It is the step a
		// renter actually thinks in — "homes in Shadyside" — and once
		// search exists it becomes a real filtered link rather than a label.
		$hoods = get_the_terms( get_the_ID(), 'tdh_neighborhood' );

		if ( $hoods && ! is_wp_error( $hoods ) ) {
			$trail[] = [
				'label' => $hoods[0]->name,
				'url'   => '',
			];
		}

		$trail[] = [ 'label' => get_the_title(), 'url' => '' ];

		return $trail;
	}

	if ( is_post_type_archive( 'tdh_listing' ) ) {
		$trail[] = [ 'label' => __( 'Find a home', 'thirtydayhomes' ), 'url' => '' ];

		return $trail;
	}

	if ( is_page() ) {

		// Walk up through any parent pages, so a nested page shows its
		// real position rather than pretending to sit at the top level.
		$ancestors = array_reverse( (array) get_post_ancestors( get_the_ID() ) );

		foreach ( $ancestors as $ancestor_id ) {
			$trail[] = [
				'label' => (string) get_the_title( $ancestor_id ),
				'url'   => (string) get_permalink( $ancestor_id ),
			];
		}

		$trail[] = [ 'label' => get_the_title(), 'url' => '' ];

		return $trail;
	}

	if ( is_search() ) {
		$trail[] = [ 'label' => __( 'Search results', 'thirtydayhomes' ), 'url' => '' ];

		return $trail;
	}

	if ( is_404() ) {
		$trail[] = [ 'label' => __( 'Page not found', 'thirtydayhomes' ), 'url' => '' ];

		return $trail;
	}

	$trail[] = [ 'label' => wp_get_document_title(), 'url' => '' ];

	return $trail;
}

/**
 * Should this view show a trail at all?
 */
function tdh_has_breadcrumb(): bool {

	// Everywhere except the front page. A trail reading "Home" on the home
	// page is the clutter that gives breadcrumbs a bad name; everywhere
	// else it earns its place.
	//
	// Pages that render their own layout used to be excluded here. They are
	// not any more — each one places the call itself, in the slot that
	// suits it: the pricing page above its centred heading, the sign-up
	// screens in the form column, the dashboard inside its dark bar.
	return ! ( is_front_page() || is_home() );
}

/**
 * Print the trail.
 *
 * An ordered list, because the steps are a sequence and their order is the
 * information. aria-current marks where the visitor is, so a screen reader
 * announces the last item as the current page rather than as a dead link.
 */
function tdh_the_breadcrumb(): void {

	if ( ! tdh_has_breadcrumb() ) {
		return;
	}

	$trail = tdh_breadcrumb_trail();

	if ( count( $trail ) < 2 ) {
		return;
	}

	$last = count( $trail ) - 1;
	?>
	<nav class="breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'thirtydayhomes' ); ?>">
		<ol>
			<?php foreach ( $trail as $i => $step ) : ?>
				<li>
					<?php if ( '' !== $step['url'] && $i !== $last ) : ?>
						<a href="<?php echo esc_url( $step['url'] ); ?>"><?php echo esc_html( $step['label'] ); ?></a>
					<?php elseif ( $i === $last ) : ?>
						<span aria-current="page"><?php echo esc_html( $step['label'] ); ?></span>
					<?php else : ?>
						<span><?php echo esc_html( $step['label'] ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
	</nav>
	<?php

	tdh_breadcrumb_schema( $trail );
}

/**
 * The banner that opens an inner page: trail, title, and optional lead.
 *
 * One component for every inner page rather than three near-identical
 * blocks. A page that composes its own header out of loose parts drifts
 * from the others the first time one of them is edited.
 *
 * Full-bleed, so it must be called OUTSIDE the page's padded container.
 *
 * @param array<string,string> $args eyebrow, title, lead.
 */
function tdh_page_banner( array $args = [] ): void {

	$args = wp_parse_args(
		$args,
		[
			'eyebrow' => '',
			'title'   => '',
			'lead'    => '',
			// 'narrow' for a page whose content sits in the narrow shell.
			// The banner has to share the content's measure or the heading
			// and the paragraph beneath it sit on different left edges.
			'width'   => 'wide',
		]
	);

	if ( '' === $args['title'] ) {
		return;
	}

	$classes = 'page-banner' . ( 'narrow' === $args['width'] ? ' page-banner--narrow' : '' );

	// Passed as a custom property rather than hardcoded in the stylesheet,
	// because the path is a theme URL and because the client can swap the
	// photograph in the Customizer without a developer.
	//
	// The url() is unquoted: esc_attr turns a quote into &#039;, and while
	// the browser does decode that before parsing the CSS, it is one less
	// thing to be wrong about. esc_url already rejects anything that would
	// break out of the attribute.
	$image = function_exists( 'tdh_hero_image' ) ? tdh_hero_image() : '';
	$style = $image ? sprintf( '--banner-image: url(%s);', esc_url( $image ) ) : '';
	?>
	<section class="<?php echo esc_attr( $classes ); ?>"<?php echo $style ? ' style="' . esc_attr( $style ) . '"' : ''; ?>>
		<div class="page-banner-inner">

			<?php tdh_the_breadcrumb(); ?>

			<?php if ( '' !== $args['eyebrow'] ) : ?>
				<p class="overline gold"><?php echo esc_html( $args['eyebrow'] ); ?></p>
			<?php endif; ?>

			<h1><?php echo esc_html( $args['title'] ); ?></h1>

			<?php if ( '' !== $args['lead'] ) : ?>
				<p class="page-banner-lead"><?php echo esc_html( $args['lead'] ); ?></p>
			<?php endif; ?>

		</div>
	</section>
	<?php
}

/**
 * Emit the trail as BreadcrumbList structured data.
 *
 * Google renders this as the path under a search result instead of a raw
 * URL. The handoff asks for indexable pages, and this is the cheapest
 * thing that improves how they appear once indexed.
 *
 * Only steps with a URL are listed: a position without an item is invalid
 * in the schema and Google drops the whole trail rather than that one step.
 *
 * @param array<int,array{label:string,url:string}> $trail
 */
function tdh_breadcrumb_schema( array $trail ): void {

	$items = [];
	$position = 0;

	foreach ( $trail as $step ) {

		$url = $step['url'];

		// The final step is the current page, which does have a canonical
		// URL even though the markup renders it without a link.
		if ( '' === $url && $step === end( $trail ) && is_singular() ) {
			$url = (string) get_permalink();
		}

		if ( '' === $url ) {
			continue;
		}

		++$position;

		$items[] = [
			'@type'    => 'ListItem',
			'position' => $position,
			'name'     => $step['label'],
			'item'     => $url,
		];
	}

	if ( count( $items ) < 2 ) {
		return;
	}

	$data = [
		'@context'        => 'https://schema.org',
		'@type'           => 'BreadcrumbList',
		'itemListElement' => $items,
	];

	printf(
		'<script type="application/ld+json">%s</script>' . "\n",
		wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
	);
}
