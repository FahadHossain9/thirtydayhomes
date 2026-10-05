<?php
/**
 * Markup renderers for the dynamic marketplace blocks.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * ONE implementation of each dynamic block.
 *
 * ─── WHY THIS CLASS EXISTS ─────────────────────────────────────────────
 *
 * Every dynamic block has two entry points:
 *
 *   [tdh_property_grid]      a shortcode — works in Elementor, Gutenberg,
 *                            the classic editor, a widget area, or a
 *                            template via do_shortcode()
 *   Elementor "Property Grid" a visual widget with a controls panel
 *
 * Both call the methods below. Neither owns markup of its own.
 *
 * The shortcode is the primitive, not the afterthought. If content only
 * existed as Elementor widgets it would be locked inside Elementor's own
 * JSON, and removing the plugin would take the page with it — which is
 * exactly what handoff §2 forbids: "Marketplace data and behavior must
 * live in the custom plugin, not be locked into Elementor templates."
 *
 * ─── WHAT BELONGS HERE, AND WHAT DOES NOT ──────────────────────────────
 *
 * Only blocks that READ THE DATABASE. A property grid needs listings, the
 * visibility rule and ordering, so it belongs here.
 *
 * Static marketing content — audience cards, calls to action, FAQ
 * sections — does NOT. That is text and icons, and it is built with
 * Elementor's own widgets using the theme's CSS classes. Wrapping static
 * copy in a custom widget would make the client depend on a developer to
 * change a paragraph, which is the opposite of the point.
 */
final class Render {

	/**
	 * A section heading that may carry <br> for the desktop line break.
	 *
	 * Phones hide the <br> (style.css) and let the heading wrap by itself,
	 * so a break with no space beside it glued two words together —
	 * "worksas hard". A space always sits before the break, whether the
	 * text came from our defaults or from someone editing it in Elementor.
	 */
	public static function heading_with_breaks( string $heading ): string {
		$safe = wp_kses( $heading, [ 'br' => [] ] );
		return (string) preg_replace( '/\s*<br\s*\/?>\s*/i', ' <br>', $safe );
	}

	/**
	 * A grid of listings.
	 *
	 * @param array<string,mixed> $args
	 */
	public static function property_grid( array $args = [] ): string {

		$args = wp_parse_args(
			$args,
			[
				'count'        => 3,
				'columns'      => 3,
				'orderby'      => 'date',
				'neighborhood' => '',
				'eyebrow'      => '',
				'heading'      => '',
				'subheading'   => '',
				'show_link'    => true,
				'link_text'    => __( 'View all homes', 'thirtydayhomes' ),
			]
		);

		$query_args = [
			'post_type'      => Post_Types::LISTING,
			'posts_per_page' => max( 1, (int) $args['count'] ),
			'no_found_rows'  => true,
		];

		switch ( $args['orderby'] ) {
			case 'price_asc':
				$query_args['meta_key'] = '_tdh_price_monthly'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$query_args['orderby']  = 'meta_value_num';
				$query_args['order']    = 'ASC';
				break;
			case 'price_desc':
				$query_args['meta_key'] = '_tdh_price_monthly'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$query_args['orderby']  = 'meta_value_num';
				$query_args['order']    = 'DESC';
				break;
			case 'rand':
				$query_args['orderby'] = 'rand';
				break;
			default:
				$query_args['orderby'] = 'date';
				$query_args['order']   = 'DESC';
		}

		if ( ! empty( $args['neighborhood'] ) ) {
			$query_args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => Post_Types::TAX_NEIGHBORHOOD,
					'field'    => 'slug',
					'terms'    => sanitize_title( (string) $args['neighborhood'] ),
				],
			];
		}

		// No tdh_bypass_visibility, on purpose. The visibility rule applies,
		// so no shortcode or widget can surface an unapproved listing.
		$query   = new \WP_Query( $query_args );
		$archive = get_post_type_archive_link( Post_Types::LISTING );
		$columns = max( 1, min( 4, (int) $args['columns'] ) );

		ob_start();
		?>
		<section class="section">

			<?php if ( '' !== $args['heading'] || '' !== $args['eyebrow'] ) : ?>
				<div class="section-title">
					<div>
						<?php if ( '' !== $args['eyebrow'] ) : ?>
							<p class="overline gold"><?php echo esc_html( (string) $args['eyebrow'] ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $args['heading'] ) : ?>
							<h2><?php echo esc_html( (string) $args['heading'] ); ?></h2>
						<?php endif; ?>
						<?php if ( '' !== $args['subheading'] ) : ?>
							<p><?php echo esc_html( (string) $args['subheading'] ); ?></p>
						<?php endif; ?>
					</div>

					<?php if ( $args['show_link'] && $archive ) : ?>
						<a class="text-btn" href="<?php echo esc_url( $archive ); ?>">
							<?php
							// Strip a trailing arrow typed into the field. The
							// arrow is drawn as an icon; a literal character
							// beside it renders "View all homes → →".
							echo esc_html( rtrim( (string) $args['link_text'], " \t\u{2192}\u{27F6}" ) );
							?>
							<?php echo function_exists( 'tdh_icon' ) ? tdh_icon( 'arrow-right', 16 ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $query->have_posts() ) : ?>
				<div class="property-grid" style="--grid-columns: <?php echo esc_attr( (string) $columns ); ?>;">
					<?php
					while ( $query->have_posts() ) :
						$query->the_post();
						self::listing_card();
					endwhile;
					wp_reset_postdata();
					?>
				</div>
			<?php else : ?>
				<div class="empty">
					<h3><?php esc_html_e( 'No homes to show yet', 'thirtydayhomes' ); ?></h3>
					<p><?php esc_html_e( 'Approved listings from members with an active plan appear here.', 'thirtydayhomes' ); ?></p>
				</div>
			<?php endif; ?>

		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * One listing card.
	 *
	 * The theme owns the card markup — it is presentation, and handoff §3.1
	 * puts presentation in the theme. Falls back to a bare title link so a
	 * theme without the part degrades instead of fatalling.
	 */
	private static function listing_card(): void {

		if ( locate_template( 'template-parts/listing-card.php' ) ) {
			get_template_part( 'template-parts/listing-card' );
			return;
		}

		printf(
			'<article class="property-card"><div class="property-body"><h3><a href="%s">%s</a></h3></div></article>',
			esc_url( (string) get_permalink() ),
			esc_html( get_the_title() )
		);
	}

	/**
	 * The hero search block.
	 *
	 * @param array<string,mixed> $args
	 */
	/**
	 * The compact search bar that sits at the top of the listing archive.
	 *
	 * Same field name as the hero (`q`) and the same destination, so a
	 * renter who searched from the homepage can refine without learning a
	 * second control — and so TDH\Search has one input to satisfy.
	 *
	 * No date fields here, deliberately. The stay is asked for once on this
	 * page, in the filter bar just below, where it sits beside the other
	 * things a renter narrows by. Two sets of date fields a few pixels
	 * apart would only raise the question of which one wins.
	 *
	 * The bar does carry the dates through: whatever stay is on the URL
	 * travels with a new keyword instead of being dropped by searching
	 * again.
	 *
	 * @param array<string,mixed> $args
	 */
	public static function search_bar( array $args = [] ): string {

		$args = wp_parse_args(
			$args,
			[
				'placeholder' => __( 'Neighborhood, city, or ZIP', 'thirtydayhomes' ),
				'button_text' => __( 'Search', 'thirtydayhomes' ),
				'label'       => __( 'Search homes', 'thirtydayhomes' ),
			]
		);

		$icon = static fn( string $name, int $size = 19 ): string =>
			function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size ) : '';

		$current = class_exists( Search::class ) ? Search::term() : '';

		ob_start();
		?>
		<form class="search-bar" role="search" method="get"
			action="<?php echo esc_url( (string) get_post_type_archive_link( Post_Types::LISTING ) ); ?>">

			<label>
				<span class="screen-reader-text"><?php echo esc_html( (string) $args['label'] ); ?></span>
				<?php echo $icon( 'search', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<input type="search" name="<?php echo esc_attr( Search::PARAM ); ?>"
					value="<?php echo esc_attr( $current ); ?>"
					placeholder="<?php echo esc_attr( (string) $args['placeholder'] ); ?>">
			</label>

			<?php
			// A new keyword must not throw away the stay the renter came
			// with, so the dates ride along as hidden fields.
			foreach ( [ Search::START, Search::END ] as $tdh_date_key ) :
				$tdh_date = class_exists( Search::class ) ? Search::typed( $tdh_date_key ) : '';
				?>
				<?php if ( '' !== $tdh_date ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $tdh_date_key ); ?>" value="<?php echo esc_attr( $tdh_date ); ?>">
				<?php endif; ?>
			<?php endforeach; ?>

			<button class="primary" type="submit"><?php echo esc_html( (string) $args['button_text'] ); ?></button>

			<?php // Only offered once there is something to clear. ?>
			<?php if ( '' !== $current ) : ?>
				<a class="search-clear" href="<?php echo esc_url( (string) get_post_type_archive_link( Post_Types::LISTING ) ); ?>">
					<?php esc_html_e( 'Clear', 'thirtydayhomes' ); ?>
				</a>
			<?php endif; ?>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * A line that only the person building the page can see.
	 *
	 * Some widgets only mean something on a particular kind of page. Put
	 * one on the wrong page and a visitor must see nothing at all — an
	 * empty box, or worse a message about pages and templates, is the
	 * site leaking its own plumbing. The person in the editor, though,
	 * needs to know why the thing they just dragged in looks empty.
	 *
	 * Written in the same voice as the block-editor notice: it says where
	 * the widget belongs and stops.
	 */
	public static function editor_note( string $text ): string {

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return '';
		}

		$editor = \Elementor\Plugin::$instance->editor ?? null;

		if ( ! $editor || ! method_exists( $editor, 'is_edit_mode' ) || ! $editor->is_edit_mode() ) {
			return '';
		}

		return '<p class="tdh-editor-note">' . esc_html( $text ) . '</p>';
	}

	/**
	 * The search results: the real page, not a copy of it.
	 *
	 * The theme owns how results look, so this renders the theme's own
	 * template part and hands it what the editor chose. Duplicating the
	 * markup here would give a page built in Elementor a quietly different
	 * results list from `/homes/`, and the two would drift apart.
	 *
	 * On the archive it uses the page's own query, because that is the one
	 * the URL describes and the one pagination pages. Anywhere else — a
	 * landing page someone builds, and the Elementor editor, where a page
	 * is never an archive — it runs its own query through
	 * `Search::own_query()`, which is flagged so that the identical
	 * keyword, date, hospital, price and sort rules apply. Either way the
	 * homes come from the plugin; the layout never decides which.
	 *
	 * @param array<string,mixed> $args See template-parts/listing-results.php,
	 *                                  plus `per_page` for its own query.
	 */
	public static function search_results( array $args = [] ): string {

		$per_page = (int) ( $args['per_page'] ?? 0 );
		unset( $args['per_page'] );

		if ( Search::is_results_page() ) {
			ob_start();
			get_template_part( 'template-parts/listing-results', null, $args );

			return (string) ob_get_clean();
		}

		/*
		 * Its own query, standing in for the main one while the template
		 * part runs. The globals are put back afterwards: a widget that
		 * left them changed would break everything printed after it on
		 * the page, which is the classic way a "posts" widget goes wrong.
		 */
		$query = Search::own_query( $per_page );

		$was     = $GLOBALS['wp_query'];
		$was_the = $GLOBALS['wp_the_query'];

		$GLOBALS['wp_query'] = $query;

		ob_start();
		get_template_part( 'template-parts/listing-results', null, $args );
		$html = (string) ob_get_clean();

		$GLOBALS['wp_query']     = $was;
		$GLOBALS['wp_the_query'] = $was_the;
		wp_reset_postdata();

		return $html;
	}

	/**
	 * The nearest hospitals for one home.
	 *
	 * @param array<string,mixed> $args heading, count, listing_id.
	 */
	public static function nearby_facilities( array $args = [] ): string {

		$args = wp_parse_args(
			$args,
			[
				'heading'    => '',
				'count'      => 0,
				'listing_id' => 0,
			]
		);

		$listing_id = (int) $args['listing_id'];

		if ( $listing_id < 1 && is_singular( Post_Types::LISTING ) ) {
			$listing_id = (int) get_queried_object_id();
		}

		if ( ! class_exists( '\TDH\Proximity' ) ) {
			return '';
		}

		/*
		 * In the editor, with no home in context, show a real one.
		 *
		 * A widget that can only ever say "I will work somewhere else" is
		 * not something anybody can judge while building a page, and the
		 * contract asks for real data in the editor, not a placeholder.
		 * So the newest home that has a location stands in, and a line
		 * says plainly that it is standing in. On the front end there is
		 * no stand-in: a visitor gets nothing at all.
		 */
		$sample = '';

		if ( $listing_id < 1 ) {

			$listing_id = self::sample_listing();

			if ( $listing_id < 1 ) {
				return self::editor_note(
					__( 'Nearby hospitals appear on a property page. There is no home with a location yet to show as an example.', 'thirtydayhomes' )
				);
			}

			$sample = self::editor_note(
				sprintf(
					/* translators: %s: a home's name */
					__( 'Showing %s as an example. On a property page this shows that home, measured from its own location.', 'thirtydayhomes' ),
					get_the_title( $listing_id )
				)
			);

			// Not the editor, so there is no example to show and no page
			// to show it on.
			if ( '' === $sample ) {
				return '';
			}
		}

		$count = (int) $args['count'];
		$list  = Proximity::list_html( $listing_id, $count > 0 ? $count : null );

		if ( '' === $list ) {
			return $sample;
		}

		$heading = (string) $args['heading'];

		return $sample
			. ( '' !== $heading ? '<h2 class="facility-heading">' . esc_html( $heading ) . '</h2>' : '' )
			. $list;
	}

	/**
	 * The newest published home that has a point on the map, or 0.
	 *
	 * Only ever used to give the Elementor editor something real to show.
	 */
	private static function sample_listing(): int {

		$ids = get_posts(
			[
				'post_type'      => Post_Types::LISTING,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => [
					[
						'key'     => '_tdh_lat',
						'compare' => 'EXISTS',
					],
				],
			]
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * List / Map, beside the count.
	 *
	 * Two links, not two buttons and not a script: the whole search is in
	 * the URL, so each view is a real address that can be shared, opened
	 * in a new tab and reached with the Back button. With no map key there
	 * is nothing to switch to, so nothing is offered.
	 */
	public static function view_toggle(): string {

		if ( ! class_exists( Maps::class ) || ! Maps::configured() ) {
			return '';
		}

		$icon = static fn( string $name, int $size = 16 ): string =>
			function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size ) : '';

		$current = Search::view();

		$views = [
			Search::VIEW_LIST => [ 'label' => __( 'List', 'thirtydayhomes' ), 'icon' => 'layout-grid' ],
			Search::VIEW_MAP  => [ 'label' => __( 'Map', 'thirtydayhomes' ), 'icon' => 'map-pinned' ],
		];

		ob_start();
		?>
		<div class="view-toggle" role="group" aria-label="<?php esc_attr_e( 'How to show the homes', 'thirtydayhomes' ); ?>">
			<?php foreach ( $views as $key => $view ) : ?>
				<a
					class="view-choice<?php echo $current === $key ? ' is-current' : ''; ?>"
					href="<?php echo esc_url( Search::view_url( $key ) ); ?>"
					<?php echo $current === $key ? 'aria-current="true"' : ''; ?>
				>
					<?php echo $icon( $view['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php echo esc_html( $view['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The map itself.
	 *
	 * Everything the browser is given about a home is in one data
	 * attribute, and it is built by {@see Maps::homes()}, which is the only
	 * place allowed to turn a real address into something publishable. No
	 * exact coordinate and no street address passes through here.
	 *
	 * @param int[] $listing_ids The homes on this page, in the order shown.
	 */
	public static function map_panel( array $listing_ids ): string {

		if ( ! class_exists( Maps::class ) || ! Maps::configured() ) {
			return '';
		}

		$summary = Maps::homes( $listing_ids );
		$note    = Maps::note( $summary );

		$payload = [
			'homes'   => $summary['homes'],
			'circle'  => Maps::CIRCLE_METRES,
			'listUrl' => Search::view_url( Search::VIEW_LIST ),
			'words'   => [
				'about'  => __( 'Approximate area', 'thirtydayhomes' ),
				'view'   => __( 'View this home', 'thirtydayhomes' ),
				'close'  => __( 'Close', 'thirtydayhomes' ),
				'failed' => __( 'The map could not be loaded. Every home is still here as a list.', 'thirtydayhomes' ),
				'list'   => __( 'Show the list', 'thirtydayhomes' ),
			],
		];

		ob_start();
		?>
		<div class="map-panel">

			<div class="map-canvas" data-tdh-map="<?php echo esc_attr( (string) wp_json_encode( $payload ) ); ?>">
				<?php // Replaced by the map, or by the failure line if it never loads. ?>
				<p class="map-status"><?php esc_html_e( 'Loading the map…', 'thirtydayhomes' ); ?></p>
			</div>

			<p class="map-privacy">
				<?php esc_html_e( 'Each circle is the neighborhood, not the address. Owners share the address after you make contact.', 'thirtydayhomes' ); ?>
			</p>

			<?php if ( '' !== $note ) : ?>
				<p class="map-note"><?php echo esc_html( $note ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The single home's circle, for the property page.
	 *
	 * Falls back to the words that were there before the maps account
	 * existed, so a page with no key still explains why the address is not
	 * shown rather than leaving a hole where a map should be.
	 */
	public static function map_area( int $listing_id ): string {

		$point = class_exists( Maps::class ) && Maps::configured() ? Maps::approximate( $listing_id ) : null;

		if ( null === $point ) {
			return '';
		}

		$payload = [
			'homes'  => [
				[
					'id'    => $listing_id,
					'lat'   => $point['lat'],
					'lng'   => $point['lng'],
					'title' => get_the_title( $listing_id ),
				],
			],
			'circle' => Maps::CIRCLE_METRES,
			'single' => true,
			'words'  => [
				'about'  => __( 'Approximate area', 'thirtydayhomes' ),
				'failed' => __( 'The map could not be loaded.', 'thirtydayhomes' ),
			],
		];

		ob_start();
		?>
		<div class="map-panel is-single">
			<div class="map-canvas" data-tdh-map="<?php echo esc_attr( (string) wp_json_encode( $payload ) ); ?>">
				<p class="map-status"><?php esc_html_e( 'Loading the map…', 'thirtydayhomes' ); ?></p>
			</div>
			<p class="map-privacy">
				<b><?php esc_html_e( 'Approximate area', 'thirtydayhomes' ); ?></b>
				<?php esc_html_e( 'We show the neighborhood rather than the exact address. A furnished home that is often empty should not have its address published — the owner shares it with you after you make contact.', 'thirtydayhomes' ); ?>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The one search form on a results page (G3a design review): the place
	 * a renter types, the stay, the hospital and the radius, price, rooms,
	 * type and pets — and a single filled button that applies all of it. It
	 * used to be two forms with two filled buttons, "Search" beside the
	 * keyword and "Show homes" under the filters, and a renter could not
	 * tell whether one applied the other's fields.
	 *
	 * One plain GET form. Nothing here needs JavaScript to work — the
	 * theme's filters.js only turns the filter panel into a drawer on
	 * phones. Every control is a real <label>, and every active filter
	 * comes back as a chip whose × removes only that one. The sort is not a
	 * filter — it changes the order of what was found, not what was found —
	 * so it lives beside the result count; see sort_control().
	 *
	 * @param array<string,mixed> $args button_text; placeholder; search —
	 *                                  print the keyword row (the default),
	 *                                  or carry the keyword as a hidden
	 *                                  field when the page shows the search
	 *                                  box somewhere else or not at all.
	 */
	public static function filter_bar( array $args = [] ): string {

		$args = wp_parse_args(
			$args,
			[
				'button_text' => __( 'Show homes', 'thirtydayhomes' ),
				'placeholder' => __( 'Neighborhood, city, or ZIP', 'thirtydayhomes' ),
				'search'      => true,
			]
		);

		$icon = static fn( string $name, int $size = 18 ): string =>
			function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size ) : '';

		$f           = Search::filters();
		$active      = Search::active();
		$count       = count( $active );
		$term        = Search::term();
		$types       = get_terms( [ 'taxonomy' => Post_Types::TAX_TYPE, 'hide_empty' => true ] );
		$types       = is_array( $types ) ? $types : [];
		$swapped     = Search::swapped_notice();
		$dates       = Search::date_notice();
		$with_search = (bool) $args['search'];

		ob_start();
		?>
		<div class="filters" data-tdh-filters>

			<form id="tdh-filter-form" class="filter-bar<?php echo $with_search ? ' has-search' : ''; ?>" method="get" action="<?php echo esc_url( Search::base_url() ); ?>" data-tdh-min-stay="<?php echo esc_attr( (string) Search::MIN_STAY_DAYS ); ?>" aria-label="<?php esc_attr_e( 'Search homes', 'thirtydayhomes' ); ?>">

				<?php
				/*
				 * The "Filters" button that opens the drawer on a phone. It is
				 * hidden until a phone width AND filters.js are both present,
				 * so it sits wherever the phone layout wants it: beside the
				 * filled button in the keyword row, or alone when the page
				 * shows no keyword row.
				 */
				$toggle = static function () use ( $icon, $count ): void {
					?>
					<button class="secondary filter-toggle" type="button" aria-controls="tdh-filter-drawer" aria-expanded="false" data-tdh-filter-toggle>
						<?php echo $icon( 'settings-2', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php esc_html_e( 'Filters', 'thirtydayhomes' ); ?>
						<?php if ( $count > 0 ) : ?>
							<em class="filter-count" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: number of active filters */ _n( '%s filter on', '%s filters on', $count, 'thirtydayhomes' ), number_format_i18n( $count ) ) ); ?>"><?php echo esc_html( number_format_i18n( $count ) ); ?></em>
						<?php endif; ?>
					</button>
					<?php
				};
				?>

				<?php if ( $with_search ) : ?>
					<?php
					/*
					 * The keyword and the one filled button. Clear removes
					 * only the keyword and keeps every other filter, because
					 * a renter narrowing by price who mistypes a ZIP has not
					 * changed their mind about the price.
					 */
					?>
					<div class="search-bar" role="search">
						<label>
							<span class="screen-reader-text"><?php esc_html_e( 'Search homes', 'thirtydayhomes' ); ?></span>
							<?php echo $icon( 'search', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<input type="search" name="<?php echo esc_attr( Search::PARAM ); ?>" value="<?php echo esc_attr( $term ); ?>" placeholder="<?php echo esc_attr( (string) $args['placeholder'] ); ?>">
						</label>
						<?php $toggle(); ?>
						<button class="primary" type="submit"><?php echo esc_html( (string) $args['button_text'] ); ?></button>
						<?php if ( '' !== $term ) : ?>
							<a class="search-clear" href="<?php echo esc_url( add_query_arg( array_diff_key( Search::args(), [ Search::PARAM => 1 ] ), Search::base_url() ) ); ?>">
								<?php esc_html_e( 'Clear', 'thirtydayhomes' ); ?>
							</a>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<?php if ( '' !== $term ) : ?>
						<input type="hidden" name="<?php echo esc_attr( Search::PARAM ); ?>" value="<?php echo esc_attr( $term ); ?>">
					<?php endif; ?>
					<?php $toggle(); ?>
				<?php endif; ?>

				<div id="tdh-filter-drawer" class="filter-drawer" data-tdh-filter-drawer>

					<div class="filter-head">
						<b><?php esc_html_e( 'Filters', 'thirtydayhomes' ); ?></b>
						<button class="filter-close" type="button" data-tdh-filter-close>
							<?php echo $icon( 'x', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<span class="screen-reader-text"><?php esc_html_e( 'Close filters', 'thirtydayhomes' ); ?></span>
						</button>
					</div>

					<div class="filter-fields">

						<?php
						/*
						 * The stay comes first, because it is the one thing this
						 * marketplace is about and the one the hero already asked
						 * for. The fields hold what was typed, not what survived
						 * validation, so a refused date can be corrected in place.
						 */
						?>
						<label class="filter-field">
							<span><?php esc_html_e( 'Move in', 'thirtydayhomes' ); ?></span>
							<input type="date" name="<?php echo esc_attr( Search::START ); ?>" min="<?php echo esc_attr( Availability::today() ); ?>" max="<?php echo esc_attr( Availability::horizon() ); ?>" value="<?php echo esc_attr( Search::typed( Search::START ) ); ?>" data-tdh-start>
						</label>

						<label class="filter-field">
							<span><?php esc_html_e( 'Move out', 'thirtydayhomes' ); ?></span>
							<input type="date" name="<?php echo esc_attr( Search::END ); ?>" min="<?php echo esc_attr( Availability::today() ); ?>" max="<?php echo esc_attr( Availability::horizon() ); ?>" value="<?php echo esc_attr( Search::typed( Search::END ) ); ?>" data-tdh-end>
						</label>

						<?php // Helper text belongs beside the fields it explains, not at the foot of the form. ?>
						<?php if ( '' !== $dates ) : ?>
							<p class="date-notice"><?php echo esc_html( $dates ); ?></p>
						<?php endif; ?>

						<?php
						/*
						 * The hospital and how far from it. Two controls, one
						 * decision: the radius means nothing on its own, so it
						 * is disabled until there is something to measure from
						 * and says why. Disabling rather than hiding keeps the
						 * row from jumping about as choices are made.
						 *
						 * "Something to measure from" is the chosen hospital or
						 * a postcode or town typed into the search box, so a
						 * renter who searched "15226" gets a working radius
						 * without having to pick a hospital they did not ask
						 * about.
						 */
						$has_facility = null !== $f['facility'];
						$has_anchor   = Search::anchored();
						?>
						<label class="filter-field wide">
							<span><?php esc_html_e( 'Near a hospital', 'thirtydayhomes' ); ?></span>
							<select name="<?php echo esc_attr( Search::FACILITY ); ?>">
								<option value=""><?php esc_html_e( 'Any hospital', 'thirtydayhomes' ); ?></option>
								<?php foreach ( Search::facilities() as $city => $group ) : ?>
									<optgroup label="<?php echo esc_attr( $city ); ?>">
										<?php foreach ( $group as $place ) : ?>
											<option value="<?php echo esc_attr( (string) $place->ID ); ?>" <?php selected( $has_facility && $f['facility']->ID === $place->ID ); ?>><?php echo esc_html( $place->post_title ); ?></option>
										<?php endforeach; ?>
									</optgroup>
								<?php endforeach; ?>
							</select>
						</label>

						<?php
						/*
						 * The real distances are always in the list, so the
						 * script can switch the field on the moment a hospital
						 * is chosen or a place typed (filters.js), without a
						 * round trip. While there is nothing to measure from,
						 * "Pick a place" is the option shown, nothing else is
						 * marked selected, and screen readers hear what unlocks
						 * it (a visible line under one field would push it out
						 * of line with its row). Without the script it unlocks after Show
						 * homes, as it always has.
						 *
						 * The staff radius is the default, so it has to be in
						 * the list even when it is not one of the standard
						 * steps: a 9-mile setting left nothing selected and the
						 * browser showed "5 miles" under a count that said
						 * "within 9 miles" (G3a).
						 */
						$radii   = Search::radii();
						$default = Proximity::radius_setting();
						$has     = static fn( float $r ): bool => null !== $f['within'] && abs( $f['within'] - $r ) < 0.001;
						foreach ( [ $f['within'], $default ] as $must ) {
							if ( null !== $must && ! array_filter( $radii, static fn( float $r ): bool => abs( $r - $must ) < 0.001 ) ) {
								$radii[] = $must;
							}
						}
						sort( $radii );
						?>
						<label class="filter-field">
							<span><?php esc_html_e( 'Within', 'thirtydayhomes' ); ?></span>
							<select name="<?php echo esc_attr( Search::WITHIN ); ?>" <?php disabled( ! $has_anchor ); ?> data-tdh-within aria-describedby="tdh-within-hint">
								<?php
								/*
								 * No longer than the longest real choice
								 * ("Any distance"), because this column is
								 * sized for those. "Pick a place first"
								 * fitted at 1280 with three pixels to spare
								 * and was cut off at every width above it.
								 */
								?>
								<option value="" data-tdh-within-placeholder <?php selected( ! $has_anchor ); ?> <?php echo $has_anchor ? 'hidden' : ''; ?>><?php esc_html_e( 'Pick a place', 'thirtydayhomes' ); ?></option>
								<?php foreach ( $radii as $miles ) : ?>
									<option value="<?php echo esc_attr( Proximity::miles_number( (float) $miles ) ); ?>" <?php selected( $has_anchor && $has( (float) $miles ) ); ?> <?php echo abs( (float) $miles - $default ) < 0.001 ? 'data-tdh-within-default' : ''; ?>><?php echo esc_html( Proximity::miles_phrase( (float) $miles ) ); ?></option>
								<?php endforeach; ?>
								<option value="<?php echo esc_attr( Search::WITHIN_ANY ); ?>" <?php selected( $has_anchor && null === $f['within'] ); ?>><?php esc_html_e( 'Any distance', 'thirtydayhomes' ); ?></option>
							</select>
							<span class="screen-reader-text" id="tdh-within-hint" data-tdh-within-hint <?php echo $has_anchor ? 'hidden' : ''; ?>><?php esc_html_e( 'Choose a hospital or type a place to set a distance.', 'thirtydayhomes' ); ?></span>
						</label>

						<label class="filter-field">
							<span><?php esc_html_e( 'Min price', 'thirtydayhomes' ); ?></span>
							<span class="filter-money">
								<i aria-hidden="true">$</i>
								<input type="number" inputmode="numeric" name="<?php echo esc_attr( Search::MIN ); ?>" min="0" max="<?php echo esc_attr( (string) Search::PRICE_CAP ); ?>" step="50" placeholder="<?php esc_attr_e( 'Any', 'thirtydayhomes' ); ?>" value="<?php echo esc_attr( null !== $f['min_price'] ? (string) $f['min_price'] : '' ); ?>">
							</span>
						</label>

						<label class="filter-field">
							<span><?php esc_html_e( 'Max price', 'thirtydayhomes' ); ?></span>
							<span class="filter-money">
								<i aria-hidden="true">$</i>
								<input type="number" inputmode="numeric" name="<?php echo esc_attr( Search::MAX ); ?>" min="0" max="<?php echo esc_attr( (string) Search::PRICE_CAP ); ?>" step="50" placeholder="<?php esc_attr_e( 'Any', 'thirtydayhomes' ); ?>" value="<?php echo esc_attr( null !== $f['max_price'] ? (string) $f['max_price'] : '' ); ?>">
							</span>
						</label>

						<label class="filter-field">
							<span><?php esc_html_e( 'Bedrooms', 'thirtydayhomes' ); ?></span>
							<select name="<?php echo esc_attr( Search::BEDS ); ?>">
								<option value=""><?php esc_html_e( 'Any', 'thirtydayhomes' ); ?></option>
								<?php foreach ( Search::beds_options() as $n ) : ?>
									<option value="<?php echo esc_attr( (string) $n ); ?>" <?php selected( $f['beds'], $n ); ?>><?php echo esc_html( number_format_i18n( $n ) . '+' ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>

						<label class="filter-field">
							<span><?php esc_html_e( 'Bathrooms', 'thirtydayhomes' ); ?></span>
							<select name="<?php echo esc_attr( Search::BATHS ); ?>">
								<option value=""><?php esc_html_e( 'Any', 'thirtydayhomes' ); ?></option>
								<?php foreach ( Search::baths_options() as $n ) : ?>
									<option value="<?php echo esc_attr( Search::half( $n ) ); ?>" <?php selected( null !== $f['baths'] && abs( $f['baths'] - $n ) < 0.01 ); ?>><?php echo esc_html( Search::half( $n ) . '+' ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>

						<?php // A type is a name ("Single-family house"), not a number: two tracks, like the hospital. ?>
						<label class="filter-field wide">
							<span><?php esc_html_e( 'Property type', 'thirtydayhomes' ); ?></span>
							<select name="<?php echo esc_attr( Search::TYPE ); ?>">
								<option value=""><?php esc_html_e( 'Any type', 'thirtydayhomes' ); ?></option>
								<?php foreach ( $types as $type ) : ?>
									<option value="<?php echo esc_attr( $type->slug ); ?>" <?php selected( null !== $f['type'] && $f['type']->term_id === $type->term_id ); ?>><?php echo esc_html( $type->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>

						<label class="filter-field">
							<span><?php esc_html_e( 'Pets', 'thirtydayhomes' ); ?></span>
							<select name="<?php echo esc_attr( Search::PETS ); ?>">
								<option value=""><?php esc_html_e( 'Any', 'thirtydayhomes' ); ?></option>
								<?php foreach ( Search::pets_options() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $f['pets'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>

					</div>

					<?php
					/*
					 * The drawer's own Show homes exists for the phone, where
					 * the keyword row's button is off screen behind the open
					 * drawer. On a desktop with the keyword row the stylesheet
					 * hides it, so one filled button is ever in view.
					 */
					?>
					<div class="filter-actions">
						<button class="primary filter-apply" type="submit"><?php echo esc_html( (string) $args['button_text'] ); ?></button>
						<?php if ( $count > 0 ) : ?>
							<a class="filter-clear" href="<?php echo esc_url( Search::clear_url() ); ?>"><?php esc_html_e( 'Clear all filters', 'thirtydayhomes' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
			</form>

			<?php if ( '' !== $swapped ) : ?>
				<p class="notice filter-notice" role="status"><?php echo esc_html( $swapped ); ?></p>
			<?php endif; ?>

			<?php echo self::filter_chips(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * "Sort by", beside the result count rather than among the filters
	 * (G3a design review): sorting changes the order of what was found, not
	 * what was found. Its own small GET form carrying the current search as
	 * hidden fields, so choosing an order never loses a filter. filters.js
	 * submits it on change; without the script its Sort button does.
	 */
	public static function sort_control(): string {

		$f          = Search::filters();
		$has_anchor = Search::anchored();
		$sorted     = Search::sort_notice();
		$carry      = array_diff_key( Search::args(), [ Search::SORT => 1 ] );

		ob_start();
		?>
		<form class="sort-form" method="get" action="<?php echo esc_url( Search::base_url() ); ?>" data-tdh-sort-form>
			<?php foreach ( $carry as $key => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( (string) $key ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
			<?php endforeach; ?>
			<label>
				<span><?php esc_html_e( 'Sort by', 'thirtydayhomes' ); ?></span>
				<select name="<?php echo esc_attr( Search::SORT ); ?>" data-tdh-sort>
					<?php foreach ( array_keys( Search::sorts() ) as $value ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $f['sort'], $value ); ?> <?php disabled( Search::SORT_CLOSEST === $value && ! $has_anchor ); ?>><?php echo esc_html( Search::sort_label( $value ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button class="secondary sort-apply" type="submit"><?php esc_html_e( 'Sort', 'thirtydayhomes' ); ?></button>
			<?php if ( '' !== $sorted ) : ?>
				<p class="date-notice sort-notice"><?php echo esc_html( $sorted ); ?></p>
			<?php endif; ?>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The active filters as removable chips, with Clear all at the end.
	 *
	 * Printed under the bar and again inside the empty state, so a renter
	 * who matched nothing can loosen one thing without scrolling back up.
	 */
	public static function filter_chips(): string {

		$chips = Search::chips();

		if ( ! $chips ) {
			return '';
		}

		$icon = static fn( string $name, int $size = 14 ): string =>
			function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size ) : '';

		ob_start();
		?>
		<ul class="filter-chips" aria-label="<?php esc_attr_e( 'Active filters', 'thirtydayhomes' ); ?>">
			<?php foreach ( $chips as $chip ) : ?>
				<li>
					<a href="<?php echo esc_url( $chip['remove'] ); ?>">
						<?php echo esc_html( $chip['label'] ); ?>
						<?php echo $icon( 'x', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<span class="screen-reader-text"><?php esc_html_e( '— remove', 'thirtydayhomes' ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
			<?php if ( count( $chips ) > 1 ) : ?>
				<li class="filter-chips-clear">
					<a href="<?php echo esc_url( Search::clear_url() ); ?>"><?php esc_html_e( 'Clear all', 'thirtydayhomes' ); ?></a>
				</li>
			<?php endif; ?>
		</ul>
		<?php
		return (string) ob_get_clean();
	}

	public static function hero_search( array $args = [] ): string {

		$args = wp_parse_args(
			$args,
			[
				'eyebrow'       => __( 'Furnished homes · 30+ day stays', 'thirtydayhomes' ),
				'heading'       => __( 'Stay a while.', 'thirtydayhomes' ),
				'accent'        => __( 'Feel at home.', 'thirtydayhomes' ),
				'lead'          => __( 'Move-in ready homes near the places that matter. Flexible monthly stays for traveling professionals, families, and everyone in between.', 'thirtydayhomes' ),
				'image'         => '',
				'button_text'   => __( 'Search homes', 'thirtydayhomes' ),
				'placeholder'   => __( 'Neighborhood, ZIP, or hospital', 'thirtydayhomes' ),
				'trust'         => [
					__( 'Fully furnished', 'thirtydayhomes' ),
					__( 'Utilities included', 'thirtydayhomes' ),
					__( 'Reviewed before listing', 'thirtydayhomes' ),
				],
				'uid'           => 'hero',
			]
		);

		$archive = get_post_type_archive_link( Post_Types::LISTING );

		$image = $args['image'];
		if ( '' === $image && function_exists( 'tdh_hero_image' ) ) {
			$image = tdh_hero_image();
		}

		$icon = static fn( string $name, int $size = 19 ): string =>
			function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size ) : '';

		ob_start();
		?>
		<section class="hero" style="--hero-image: url( '<?php echo esc_url( (string) $image ); ?>' );">
			<div class="hero-bg" aria-hidden="true"></div>

			<div class="hero-inner">

				<?php if ( '' !== $args['eyebrow'] ) : ?>
					<p class="overline"><?php echo esc_html( (string) $args['eyebrow'] ); ?></p>
				<?php endif; ?>

				<h1>
					<?php echo esc_html( (string) $args['heading'] ); ?>
					<?php if ( '' !== $args['accent'] ) : ?>
						<br><em><?php echo esc_html( (string) $args['accent'] ); ?></em>
					<?php endif; ?>
				</h1>

				<?php if ( '' !== $args['lead'] ) : ?>
					<p class="lead"><?php echo esc_html( (string) $args['lead'] ); ?></p>
				<?php endif; ?>

				<form class="hero-search date-search" action="<?php echo esc_url( (string) $archive ); ?>" method="get" role="search" data-tdh-hero-search>

					<label>
						<?php echo $icon( 'map-pin' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<span>
							<b><?php esc_html_e( 'Where', 'thirtydayhomes' ); ?></b>
							<input type="search" name="q" placeholder="<?php echo esc_attr( (string) $args['placeholder'] ); ?>">
						</span>
					</label>

					<label>
						<?php echo $icon( 'calendar-days' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<span>
							<b><?php esc_html_e( 'Start date', 'thirtydayhomes' ); ?></b>
							<input type="date" name="start" data-tdh-start>
						</span>
					</label>

					<label>
						<?php echo $icon( 'calendar-days' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<span>
							<b><?php esc_html_e( 'End date', 'thirtydayhomes' ); ?></b>
							<input type="date" name="end" data-tdh-end>
						</span>
					</label>

					<?php
					/*
					 * Always usable (G2 design review). The button used to sit
					 * greyed out until both dates were typed, which read as
					 * "not available"; dates narrow the search, they are not a
					 * condition of it. An old `require_dates` attribute on a
					 * shortcode or a saved widget is ignored.
					 */
					?>
					<button type="submit" data-tdh-submit>
						<?php echo $icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php echo esc_html( (string) $args['button_text'] ); ?>
					</button>
				</form>

				<?php if ( ! empty( $args['trust'] ) ) : ?>
					<ul class="hero-trust">
						<?php foreach ( (array) $args['trust'] as $item ) : ?>
							<?php if ( '' === trim( (string) $item ) ) { continue; } ?>
							<li><?php echo $icon( 'check', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html( (string) $item ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The audience section — four cards for the people we house.
	 *
	 * Static copy, but still one block rather than loose Heading and Text
	 * widgets. A card grid has a fixed shape: four cards, each with an
	 * eyebrow, a title and a paragraph. Expressing that as twelve separate
	 * Elementor widgets lets a client accidentally delete one heading and
	 * leave a card with no title. A repeater cannot produce that state.
	 *
	 * @param array<string,mixed> $args
	 */
	public static function audience( array $args = [] ): string {

		$args = wp_parse_args(
			$args,
			[
				'eyebrow' => __( 'Made for working travelers', 'thirtydayhomes' ),
				'heading' => __( 'Housing that works<br>as hard as you do.', 'thirtydayhomes' ),
				'intro'   => __( 'Whether it’s one traveler or an entire project team, find a furnished home with the space and flexibility your assignment needs.', 'thirtydayhomes' ),
				'cards'   => self::default_audience_cards(),
			]
		);

		// Stroke 2 matches the prototype. Rendered size is set in CSS from
		// --icon-base, so the number here is only the fallback attribute.
		$icon = static fn( string $name, int $size = 19 ): string =>
			function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size, 2 ) : '';

		ob_start();
		?>
		<section class="audience">

			<div class="audience-head">
				<div>
					<?php if ( '' !== $args['eyebrow'] ) : ?>
						<p class="overline gold"><?php echo esc_html( (string) $args['eyebrow'] ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $args['heading'] ) : ?>
						<h2>
							<?php
							// <br> only — the approved design breaks this
							// heading after "works". Everything else is
							// stripped, so the field stays plain text to a
							// client and cannot become a markup injection
							// point. CSS drops the break on narrow screens.
							echo self::heading_with_breaks( (string) $args['heading'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
							?>
						</h2>
					<?php endif; ?>
				</div>
				<?php if ( '' !== $args['intro'] ) : ?>
					<p><?php echo esc_html( (string) $args['intro'] ); ?></p>
				<?php endif; ?>
			</div>

			<div class="audience-grid">
				<?php foreach ( (array) $args['cards'] as $card ) : ?>
					<article>
						<?php if ( ! empty( $card['icon'] ) ) : ?>
							<i><?php echo $icon( (string) $card['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
						<?php endif; ?>
						<?php if ( ! empty( $card['eyebrow'] ) ) : ?>
							<p class="overline gold"><?php echo esc_html( (string) $card['eyebrow'] ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $card['title'] ) ) : ?>
							<h3><?php echo esc_html( (string) $card['title'] ); ?></h3>
						<?php endif; ?>
						<?php if ( ! empty( $card['copy'] ) ) : ?>
							<p><?php echo esc_html( (string) $card['copy'] ); ?></p>
						<?php endif; ?>

						<?php
						/*
						 * A link, not a <button>.
						 *
						 * The prototype used <button> because it was a React
						 * single-page app with no URLs. This navigates, so it
						 * is an anchor — a button cannot be middle-clicked,
						 * opened in a new tab, or crawled, and the spec asks
						 * for indexable pages.
						 */
						if ( ! empty( $card['link_text'] ) ) :
							$href = ! empty( $card['link_url'] )
								? $card['link_url']
								: (string) get_post_type_archive_link( Post_Types::LISTING );
							?>
							<a class="audience-link" href="<?php echo esc_url( (string) $href ); ?>">
								<?php echo esc_html( (string) $card['link_text'] ); ?>
								<?php echo $icon( 'arrow-right', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							</a>
						<?php endif; ?>
					</article>
				<?php endforeach; ?>
			</div>

		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The four audience cards.
	 *
	 * ─── WHY THESE CARDS DO NOT LINK ───────────────────────────────────────
	 *
	 * The prototype gave each card an "Explore housing" link, and the owner
	 * asked for them to come out: these are the audiences the site is
	 * MARKETED to, not categories of property it holds. A link implies a
	 * filtered set of homes tailored to construction crews or students, and
	 * no such set exists — every listing is a furnished monthly home, and
	 * the same home suits a travelling nurse and a visiting scholar alike.
	 * A link that promises a category and delivers the unfiltered archive
	 * is a small lie repeated four times.
	 *
	 * The cards remain as what they honestly are: who this is for. The
	 * renderer omits the anchor entirely when link_text is empty, so
	 * restoring a link later is a copy change, not a code change.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function default_audience_cards(): array {
		return [
			[
				'icon'      => 'stethoscope',
				'eyebrow'   => __( 'For healthcare', 'thirtydayhomes' ),
				'title'     => __( 'Medical professionals', 'thirtydayhomes' ),
				'copy'      => __( 'Comfortable homes near hospitals and healthcare facilities for nurses, physicians, therapists, and clinical teams.', 'thirtydayhomes' ),
				'link_text' => '',
				'link_url'  => '',
			],
			[
				'icon'      => 'briefcase',
				'eyebrow'   => __( 'For business', 'thirtydayhomes' ),
				'title'     => __( 'Corporate travelers', 'thirtydayhomes' ),
				'copy'      => __( 'Move-in-ready homes with space to work, recharge, and settle in during relocations, training, and extended assignments.', 'thirtydayhomes' ),
				'link_text' => '',
				'link_url'  => '',
			],
			[
				'icon'      => 'hard-hat',
				'eyebrow'   => __( 'For project teams', 'thirtydayhomes' ),
				'title'     => __( 'Construction crews', 'thirtydayhomes' ),
				'copy'      => __( 'Practical housing for crews of every size—with kitchens, parking, laundry, and flexible monthly terms.', 'thirtydayhomes' ),
				'link_text' => '',
				'link_url'  => '',
			],
			[
				'icon'      => 'graduation-cap',
				'eyebrow'   => __( 'For academics', 'thirtydayhomes' ),
				'title'     => __( 'Student housing', 'thirtydayhomes' ),
				'copy'      => __( 'Furnished monthly homes for internships, clinical rotations, semester stays, visiting scholars, and temporary placements.', 'thirtydayhomes' ),
				'link_text' => '',
				'link_url'  => '',
			],
		];
	}

	/**
	 * The split feature — photograph, badge, and a short list of benefits.
	 *
	 * @param array<string,mixed> $args
	 */
	public static function split_feature( array $args = [] ): string {

		$args = wp_parse_args(
			$args,
			[
				'eyebrow'       => __( 'A better way to stay', 'thirtydayhomes' ),
				'heading'       => __( 'Everything you need. Nothing you don’t.', 'thirtydayhomes' ),
				'copy'          => __( 'Skip the hotel shuffle and the year-long lease. Our homes are designed for real life, with space to work, cook, rest, and settle in.', 'thirtydayhomes' ),
				'image'         => '',
				'badge_title'   => __( 'Every home, verified.', 'thirtydayhomes' ),
				'badge_copy'    => __( 'Quality you can count on', 'thirtydayhomes' ),
				'benefits'      => self::default_benefits(),
			]
		);

		$image = (string) $args['image'];
		if ( '' === $image ) {
			$image = get_template_directory_uri() . '/assets/split-verified.webp';
		}

		$icon = static fn( string $name, int $size = 19 ): string =>
			function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size ) : '';

		ob_start();
		?>
		<section class="split">

			<div class="split-photo">
				<img
					src="<?php echo esc_url( $image ); ?>"
					alt="<?php esc_attr_e( 'A furnished ThirtyDayHomes living space', 'thirtydayhomes' ); ?>"
					width="1200" height="800" loading="lazy" decoding="async"
				>

				<?php if ( '' !== $args['badge_title'] ) : ?>
					<div class="verified">
						<?php echo $icon( 'shield-check', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<span>
							<b><?php echo esc_html( (string) $args['badge_title'] ); ?></b>
							<small><?php echo esc_html( (string) $args['badge_copy'] ); ?></small>
						</span>
					</div>
				<?php endif; ?>
			</div>

			<div>
				<?php if ( '' !== $args['eyebrow'] ) : ?>
					<p class="overline gold"><?php echo esc_html( (string) $args['eyebrow'] ); ?></p>
				<?php endif; ?>

				<?php if ( '' !== $args['heading'] ) : ?>
					<h2><?php echo esc_html( (string) $args['heading'] ); ?></h2>
				<?php endif; ?>

				<?php if ( '' !== $args['copy'] ) : ?>
					<p class="muted"><?php echo esc_html( (string) $args['copy'] ); ?></p>
				<?php endif; ?>

				<?php foreach ( (array) $args['benefits'] as $benefit ) : ?>
					<?php if ( empty( $benefit['title'] ) ) { continue; } ?>
					<div class="benefit">
						<?php if ( ! empty( $benefit['icon'] ) ) : ?>
							<i><?php echo $icon( (string) $benefit['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
						<?php endif; ?>
						<span>
							<b><?php echo esc_html( (string) $benefit['title'] ); ?></b>
							<small><?php echo esc_html( (string) ( $benefit['copy'] ?? '' ) ); ?></small>
						</span>
					</div>
				<?php endforeach; ?>
			</div>

		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The three benefits, as approved in the prototype.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function default_benefits(): array {
		return [
			[
				'icon'  => 'key-round',
				'title' => __( 'Move-in ready', 'thirtydayhomes' ),
				'copy'  => __( 'Furniture, Wi-Fi, and utilities are already set.', 'thirtydayhomes' ),
			],
			[
				'icon'  => 'calendar-days',
				'title' => __( 'Stay on your terms', 'thirtydayhomes' ),
				'copy'  => __( 'Flexible 30+ day stays without a long lease.', 'thirtydayhomes' ),
			],
			[
				'icon'  => 'stethoscope',
				'title' => __( 'Close to care', 'thirtydayhomes' ),
				'copy'  => __( 'Search by hospital and compare every commute.', 'thirtydayhomes' ),
			],
		];
	}

	/**
	 * The owner call to action.
	 *
	 * @param array<string,mixed> $args
	 */
	public static function owner_cta( array $args = [] ): string {

		$pricing = get_page_by_path( 'pricing' );

		$args = wp_parse_args(
			$args,
			[
				'eyebrow'     => __( 'For property owners', 'thirtydayhomes' ),
				'heading'     => __( 'Your property works harder<br>with longer stays.', 'thirtydayhomes' ),
				'copy'        => __( 'Reach trusted professionals and families seeking furnished homes.', 'thirtydayhomes' ),
				'button_text' => __( 'See membership options', 'thirtydayhomes' ),
				'button_url'  => $pricing ? (string) get_permalink( $pricing ) : '',
				'stats'       => [
					[ 'value' => '30+', 'label' => __( 'night minimum', 'thirtydayhomes' ) ],
					// "3 listings per plan" is a pricing commitment, and the
					// plan structure is not signed off yet. It is editable in
					// the widget for exactly that reason — check it against
					// the final plans before launch.
					[ 'value' => '3', 'label' => __( 'listings per plan', 'thirtydayhomes' ) ],
					[ 'value' => '100%', 'label' => __( 'direct inquiries', 'thirtydayhomes' ) ],
				],
			]
		);

		ob_start();
		?>
		<section class="owner-cta">

			<div>
				<?php if ( '' !== $args['eyebrow'] ) : ?>
					<p class="overline"><?php echo esc_html( (string) $args['eyebrow'] ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $args['heading'] ) : ?>
					<h2>
						<?php
						// <br> only, same rule as the audience heading: the
						// approved design breaks after "harder". CSS drops
						// the break on narrow screens.
						echo self::heading_with_breaks( (string) $args['heading'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
						?>
					</h2>
				<?php endif; ?>
				<?php if ( '' !== $args['copy'] ) : ?>
					<p><?php echo esc_html( (string) $args['copy'] ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $args['button_text'] && '' !== $args['button_url'] ) : ?>
					<a class="gold-btn" href="<?php echo esc_url( (string) $args['button_url'] ); ?>">
						<?php echo esc_html( (string) $args['button_text'] ); ?>
						<?php echo function_exists( 'tdh_icon' ) ? tdh_icon( 'arrow-right', 16 ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</a>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $args['stats'] ) ) : ?>
				<div class="cta-stats">
					<?php foreach ( (array) $args['stats'] as $stat ) : ?>
						<?php if ( empty( $stat['value'] ) ) { continue; } ?>
						<div>
							<b><?php echo esc_html( (string) $stat['value'] ); ?></b>
							<small><?php echo esc_html( (string) ( $stat['label'] ?? '' ) ); ?></small>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The membership plans.
	 *
	 * PRICES ARE NOT SIGNED OFF. These are the approved prototype's figures
	 * and the delivery plan lists them as placeholders — see
	 * DEVELOPMENT_PLAN.md §5. They live here, in one array, so confirming
	 * them is a single edit rather than a hunt through markup.
	 *
	 * `listings` is the quota the plan grants, and it is the same number
	 * TDH\Membership enforces. Changing a plan's listing count here without
	 * changing what billing writes to _tdh_listing_quota would let someone
	 * publish more homes than they paid for.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function plans(): array {

		$plans = [
			[
				'listings' => 1,
				'label'    => __( 'One listing', 'thirtydayhomes' ),
				'price'    => 49,
				'save'     => 0,
				'note'     => __( 'Standard rate', 'thirtydayhomes' ),
				'featured' => false,
				'badge'    => '',
			],
			[
				'listings' => 2,
				'label'    => __( '2 listings', 'thirtydayhomes' ),
				'price'    => 88,
				'save'     => 10,
				'note'     => __( 'Multi-listing discount', 'thirtydayhomes' ),
				'featured' => false,
				'badge'    => '',
			],
			[
				'listings' => 3,
				'label'    => __( '3 listings', 'thirtydayhomes' ),
				'price'    => 125,
				'save'     => 15,
				'note'     => __( 'Multi-listing discount', 'thirtydayhomes' ),
				'featured' => true,
				'badge'    => __( 'Best value', 'thirtydayhomes' ),
			],
		];

		/**
		 * Filter the membership plans.
		 *
		 * The billing layer will own these once it exists.
		 *
		 * @param array<int,array<string,mixed>> $plans
		 */
		return (array) apply_filters( 'tdh_membership_plans', $plans );
	}

	/**
	 * What every plan includes.
	 *
	 * One list, not one per plan: the plans differ only in how many homes
	 * they carry, and repeating four identical bullets three times invites
	 * them to drift apart in a later edit.
	 *
	 * @return string[]
	 */
	public static function plan_features(): array {
		return (array) apply_filters(
			'tdh_plan_features',
			[
				__( 'Unlimited renter inquiries', 'thirtydayhomes' ),
				__( 'Location proximity search', 'thirtydayhomes' ),
				__( 'Pause or update any time', 'thirtydayhomes' ),
				__( 'No guest booking fees', 'thirtydayhomes' ),
			]
		);
	}

	/**
	 * The membership pricing table.
	 *
	 * @param array<string,mixed> $args
	 */
	public static function pricing( array $args = [] ): string {

		$args = wp_parse_args(
			$args,
			[
				'eyebrow'          => __( 'Simple, transparent membership', 'thirtydayhomes' ),
				'heading'          => __( 'More listings. Better value.', 'thirtydayhomes' ),
				'intro'            => __( 'Automatic volume pricing rewards landlords who publish more than one home.', 'thirtydayhomes' ),
				'currency'         => '$',

				'included_heading' => __( 'Included in every plan', 'thirtydayhomes' ),
				'features'         => self::plan_features(),

				'note'             => __( 'The discount applies automatically as homes are added.', 'thirtydayhomes' ),
				'note_emphasis'    => __( 'Prices shown are not final and are awaiting client confirmation.', 'thirtydayhomes' ),
			]
		);

		$features = (array) $args['features'];
		$signed_in = is_user_logged_in();

		/*
		 * PAST_DUE counts as having a plan. They are mid-retry, not
		 * unsubscribed, and selling them a second subscription while the
		 * first is still being collected would bill one account twice.
		 */
		$status       = $signed_in ? Membership::status() : Membership::NONE;
		$has_plan     = in_array( $status, [ Membership::ACTIVE, Membership::PAST_DUE, Membership::CANCELLED ], true );
		$current_plan = $has_plan ? Membership::plan() : '';

		// Someone sent back here by the checkout handler. The reason arrives
		// as a key and is looked up locally — never printed from the URL.
		$checkout_error = '';

		if ( isset( $_GET['tdh_checkout_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$reason         = sanitize_key( wp_unslash( (string) $_GET['tdh_checkout_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$checkout_error = (string) ( Billing\Checkout::messages()[ $reason ] ?? '' );
		}

		// F1: said before they press a plan, not only after — as information,
		// not an error: they have done nothing wrong yet.
		$verify_note = '' === $checkout_error && $signed_in && ! Email_Verification::is_verified( get_current_user_id() )
			? Email_Verification::sentence( get_current_user_id() )
			: '';

		/*
		 * Ascending, exactly as plans() lists them. NOT reordered to put the
		 * recommended plan in the middle.
		 *
		 * That convention comes from tables with NAMED tiers — Basic, Pro,
		 * Enterprise — where the middle column carries no meaning of its own
		 * and is free to mean "recommended". Here the tiers are a number
		 * sequence and the page's whole argument is that more listings cost
		 * less each. Moving the featured plan to the middle rendered the row
		 * as 1 listing, 3 listings, 2 listings — $49, $125, $88 — so the
		 * prices climbed, dropped, and the "Save 15%" badge sat to the left
		 * of "Save 10%". The sequence IS the argument, and scrambling it
		 * made the page read as a mistake.
		 *
		 * The recommended plan is already marked twice over, by its badge
		 * and its gold border, which is what the middle position was there
		 * to do.
		 */
		$plans = self::plans();

		$icon = static fn( string $name, int $size = 16 ): string =>
			function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size ) : '';

		ob_start();

		/*
		 * The same banner every other inner page opens on, carrying the
		 * trail and the heading. This page used to print its own centred
		 * head instead, which left it the only page on the site with
		 * neither a banner nor a header design of its own — a bare trail
		 * on white above a centred title.
		 *
		 * Theme-side, so it degrades if this plugin ever runs under a theme
		 * that has no banner — same guard as the icons above.
		 */
		if ( function_exists( 'tdh_page_banner' ) ) {
			tdh_page_banner(
				[
					'eyebrow' => (string) $args['eyebrow'],
					'title'   => (string) $args['heading'],
					'lead'    => (string) $args['intro'],
				]
			);
		}
		?>
		<div class="pricing">

			<?php if ( '' !== $checkout_error ) : ?>
				<div class="form-notice form-notice--error pricing-notice">
					<?php echo function_exists( 'tdh_icon' ) ? tdh_icon( 'shield-check', 18 ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<p><?php echo esc_html( $checkout_error ); ?></p>
				</div>
			<?php elseif ( '' !== $verify_note ) : ?>
				<div class="form-notice form-notice--info pricing-notice">
					<?php echo function_exists( 'tdh_icon' ) ? tdh_icon( 'mail', 18 ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<p><?php echo esc_html( $verify_note ); ?></p>
				</div>
			<?php endif; ?>

			<div class="pricing-grid">
				<?php foreach ( $plans as $plan ) : ?>
					<?php
					$is_featured = ! empty( $plan['featured'] );
					$listings    = (int) $plan['listings'];
					?>
					<div class="plan<?php echo $is_featured ? ' plan--featured' : ''; ?>">

						<?php if ( ! empty( $plan['badge'] ) ) : ?>
							<span class="plan-badge"><?php echo esc_html( (string) $plan['badge'] ); ?></span>
						<?php endif; ?>

						<p class="plan-tier"><?php echo esc_html( (string) $plan['label'] ); ?></p>

						<p class="plan-price">
							<?php
							/*
							 * Currency in its own element so it can be set
							 * smaller and raised. Playfair's dollar sign has
							 * a tall bar and tight sidebearings, and at the
							 * price's display size it collided with the
							 * first digit — "$49" rendered with the bar
							 * struck through the 4. Setting a currency
							 * symbol smaller and raised is the convention
							 * for a price anyway, and it puts the number
							 * where the eye should land.
							 */
							?>
							<b>
								<span class="plan-currency"><?php echo esc_html( (string) $args['currency'] ); ?></span><?php echo esc_html( number_format_i18n( (float) $plan['price'] ) ); ?>
							</b>
							<small><?php esc_html_e( '/ month', 'thirtydayhomes' ); ?></small>
						</p>

						<?php
						/*
						 * The cost of one home, worked out rather than
						 * asserted. "15% discount" is a claim; "£41.67 a
						 * home instead of £49" is the claim made checkable,
						 * and it is the number a landlord with three
						 * properties is actually doing in their head.
						 */
						$per_home = (float) $plan['price'] / max( 1, $listings );
						$decimals = ( $per_home === floor( $per_home ) ) ? 0 : 2;
						?>
						<p class="plan-each">
							<?php
							printf(
								/* translators: %s: price for a single home */
								esc_html__( '%s per home', 'thirtydayhomes' ),
								'<b>' . esc_html( $args['currency'] . number_format_i18n( $per_home, $decimals ) ) . '</b>' // phpcs:ignore WordPress.Security.EscapeOutput
							);
							?>
						</p>

						<p class="plan-note">
							<?php if ( (int) $plan['save'] > 0 ) : ?>
								<span class="plan-save">
									<?php
									printf(
										/* translators: %d: percentage saved */
										esc_html__( 'Save %d%%', 'thirtydayhomes' ),
										(int) $plan['save']
									);
									?>
								</span>
							<?php endif; ?>
							<?php echo esc_html( (string) $plan['note'] ); ?>
						</p>

						<?php
						/*
						 * Only what differs between plans. The four shared
						 * features used to be repeated in all three cards —
						 * twelve lines saying the same thing, with the one
						 * number that actually varies buried at the bottom
						 * of each list. They are stated once, below.
						 */
						?>
						<p class="plan-allowance">
							<?php echo $icon( 'map-pinned', 19 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php
							printf(
								/* translators: %d: number of homes the plan allows */
								esc_html( _n( '%d home published', '%d homes published', $listings, 'thirtydayhomes' ) ),
								(int) $listings
							);
							?>
						</p>

						<?php
						/*
						 * Four states, and each one is a different thing to
						 * say. A single disabled button for all of them is
						 * how a landlord ends up staring at a dead control
						 * with no idea whether the fault is theirs.
						 *
						 * The button names the plan ("Start with 3 homes")
						 * and the line under it says what happens next and
						 * what it costs, because a button that costs money
						 * must say so (G3b design review). Button and line
						 * sit in one block pinned to the card's foot, so the
						 * three cards end on the same line.
						 */
						$button_class = ( $is_featured ? 'gold-btn' : 'secondary' ) . ' full';
						$start_label  = sprintf(
							/* translators: %d: number of homes the plan allows */
							_n( 'Start with %d home', 'Start with %d homes', $listings, 'thirtydayhomes' ),
							$listings
						);
						$monthly      = $args['currency'] . number_format_i18n( (float) $plan['price'] );
						?>
						<div class="plan-action">
						<?php if ( ! $signed_in ) : ?>
							<?php // Nothing to attach a subscription to yet: the account comes first. ?>
							<a class="<?php echo esc_attr( $button_class ); ?>" href="<?php echo esc_url( Accounts::url( 'register' ) ); ?>">
								<?php echo esc_html( $start_label ); ?>
							</a>
							<p class="plan-next">
								<?php
								printf(
									/* translators: %s: monthly price, e.g. "$49" */
									esc_html__( 'Create your account, then pay %s a month by card. Cancel any time.', 'thirtydayhomes' ),
									esc_html( $monthly )
								);
								?>
							</p>

						<?php elseif ( $has_plan ) : ?>
							<?php // Already paying. Buying again would bill them twice for one account. ?>
							<button class="<?php echo esc_attr( $button_class ); ?>" type="button" disabled>
								<?php
								echo esc_html(
									$current_plan === $plan['label']
										? __( 'Your current plan', 'thirtydayhomes' )
										: __( 'You have a plan', 'thirtydayhomes' )
								);
								?>
							</button>

						<?php elseif ( ! Billing\Checkout::is_ready( $listings ) ) : ?>
							<?php
							/*
							 * Keys or this plan's Price are missing. Shown as
							 * unavailable rather than as a button that would
							 * bounce them back with an error — and the reason
							 * is only spelled out for someone who can fix it.
							 */
							?>
							<button class="<?php echo esc_attr( $button_class ); ?>" type="button" disabled>
								<?php esc_html_e( 'Not available yet', 'thirtydayhomes' ); ?>
							</button>
							<?php if ( current_user_can( 'manage_options' ) ) : ?>
								<small class="plan-admin-note">
									<?php esc_html_e( 'Admin: add this plan’s Price ID under Listings → Payments.', 'thirtydayhomes' ); ?>
								</small>
							<?php endif; ?>

						<?php else : ?>
							<?php
							/*
							 * A POST, not a link. Starting a subscription is
							 * not a safe repeatable read, and must not be
							 * reachable by a crawler or a prefetch following
							 * a URL.
							 */
							?>
							<form method="post" class="plan-buy">
								<?php echo Billing\Checkout::form_fields( $listings ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<button class="<?php echo esc_attr( $button_class ); ?>" type="submit">
									<?php echo esc_html( $start_label ); ?>
								</button>
							</form>
							<p class="plan-next">
								<?php
								printf(
									/* translators: %s: monthly price, e.g. "$125" */
									esc_html__( 'You’ll pay %s a month by card, through Stripe. Cancel any time.', 'thirtydayhomes' ),
									esc_html( $monthly )
								);
								?>
							</p>
						<?php endif; ?>
						</div>

					</div>
				<?php endforeach; ?>
			</div>

			<?php
			/*
			 * The boundary, stated (G3b). A landlord with five homes found
			 * no answer on this page; now they find the door. No price is
			 * invented for it — that is a conversation.
			 */
			$contact_page = get_page_by_path( 'contact' );
			$most         = $plans ? max( array_map( static fn( array $p ): int => (int) $p['listings'], $plans ) ) : 0;
			if ( $contact_page && $most > 0 ) :
				?>
				<p class="pricing-more">
					<?php
					printf(
						/* translators: 1: the largest plan's number of homes, 2: a link reading "Contact us" */
						esc_html__( 'More than %1$d homes? %2$s and we’ll sort out a plan for you.', 'thirtydayhomes' ),
						(int) $most,
						'<a href="' . esc_url( (string) get_permalink( $contact_page ) ) . '">' . esc_html__( 'Contact us', 'thirtydayhomes' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( $features ) : ?>
				<section class="plan-included">
					<h2><?php echo esc_html( (string) $args['included_heading'] ); ?></h2>
					<ul>
						<?php foreach ( $features as $feature ) : ?>
							<li>
								<?php echo $icon( 'check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<span><?php echo esc_html( (string) $feature ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<?php
			/*
			 * The emphasised sentence is a note to the client — "prices are
			 * not final" — and a visitor reading it under a Start button
			 * loses trust in the number above it (G3b). Staff still see
			 * it, labelled as theirs; the plain line stays public.
			 */
			$staff = Accounts::is_staff();
			?>
			<?php if ( '' !== $args['note'] || ( $staff && '' !== $args['note_emphasis'] ) ) : ?>
				<p class="pricing-note">
					<?php echo esc_html( (string) $args['note'] ); ?>
					<?php if ( $staff && '' !== $args['note_emphasis'] ) : ?>
						<strong><?php echo esc_html( __( 'Staff note:', 'thirtydayhomes' ) . ' ' . (string) $args['note_emphasis'] ); ?></strong>
					<?php endif; ?>
				</p>
			<?php endif; ?>

		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The Contact page body.
	 *
	 * The page used to say "get in touch" and then offer no way to do it —
	 * no form, no address, no number. That is worse than a page with nothing
	 * on it, because it asks for something and then refuses to take it.
	 *
	 * One card split in two: the promise on a navy ground, the form on white.
	 * The dark half is not decoration. "How long until somebody answers" and
	 * "am I even writing to the right place" are the two questions that stop
	 * people sending, and answering them before the first keystroke costs
	 * nothing. Putting them on the site's own dark ground makes the pair read
	 * as one object rather than as a form with a paragraph beside it.
	 *
	 * @param array<string,mixed> $args
	 */
	/**
	 * The three assurances beside the form.
	 *
	 * Public and separate so the Elementor widget seeds itself from exactly
	 * what the shortcode renders — see the note on default_about_facts().
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function default_contact_assurances(): array {
		return [
			[
				'icon'  => 'calendar-days',
				'title' => __( 'One business day', 'thirtydayhomes' ),
				'copy'  => __( 'That is how long a reply takes. If it is urgent, say so in the message and it moves up the pile.', 'thirtydayhomes' ),
			],
			[
				'icon'  => 'key-round',
				'title' => __( 'Renting or listing', 'thirtydayhomes' ),
				'copy'  => __( 'Both come here. Choosing what it is about only routes it to whoever answers fastest.', 'thirtydayhomes' ),
			],
			[
				'icon'  => 'shield-check',
				'title' => __( 'Only we see it', 'thirtydayhomes' ),
				'copy'  => __( 'Your message is not published anywhere and your address is never passed on.', 'thirtydayhomes' ),
			],
		];
	}

	public static function contact( array $args = [] ): string {

		$args = wp_parse_args(
			$args,
			[
				'eyebrow'    => __( 'We read every message', 'thirtydayhomes' ),
				'heading'    => __( 'Tell us what you need.', 'thirtydayhomes' ),
				'lead'       => __( 'A real person answers this, from the same team that reviews every home on the site.', 'thirtydayhomes' ),
				'status'     => __( 'Someone reads this inbox every business day', 'thirtydayhomes' ),
				'assurances' => self::default_contact_assurances(),

				/*
				 * The way round the form (G3b design review): the address
				 * the site already sends from, so it is a mailbox somebody
				 * reads. Empty when none is configured, and then not shown.
				 *
				 * @param string $email The address offered on the Contact page.
				 */
				'email'      => (string) apply_filters( 'tdh_contact_email', (string) get_option( Mail::OPTION_FROM, '' ) ),
			]
		);

		$icon = static fn( string $name, int $size = 19 ): string =>
			function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size, 2 ) : '';

		// What was typed before a rejected submission, so it is not retyped.
		$stash  = Contact::take();
		$values = $stash['values'];
		$errors = $stash['errors'];

		$old = static fn( string $key ): string => (string) ( $values[ $key ] ?? '' );

		// The outcome key from the redirect, looked up locally — never
		// printed from the URL.
		$notice = '';
		$kind   = '';

		if ( isset( $_GET['tdh_contact'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$reason = sanitize_key( wp_unslash( (string) $_GET['tdh_contact'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$notice = (string) ( Contact::messages()[ $reason ] ?? '' );
			$kind   = 'sent' === $reason ? 'ok' : 'error';
		}

		ob_start();
		?>

		<section class="section contact">
			<div class="contact-inner">
				<div class="contact-shell">

					<aside class="contact-promise">
						<div class="contact-promise-inner">
							<p class="overline"><?php echo esc_html( (string) $args['eyebrow'] ); ?></p>
							<h2><?php echo esc_html( (string) $args['heading'] ); ?></h2>
							<p class="contact-lead"><?php echo esc_html( (string) $args['lead'] ); ?></p>

							<ul class="contact-assurances">
								<?php foreach ( (array) $args['assurances'] as $i => $item ) : ?>
									<?php
									/*
									 * The rows arrive one after another rather than
									 * all at once. --i drives the delay from CSS, so
									 * adding a fourth assurance needs no new rule —
									 * and the reduced-motion query zeroes the whole
									 * thing without this markup knowing about it.
									 */
									?>
									<li style="--i: <?php echo (int) $i; ?>">
										<i><?php echo $icon( (string) $item['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
										<div>
											<b><?php echo esc_html( (string) $item['title'] ); ?></b>
											<p><?php echo esc_html( (string) $item['copy'] ); ?></p>
										</div>
									</li>
								<?php endforeach; ?>
							</ul>

							<p class="contact-status">
								<span class="contact-dot" aria-hidden="true"></span>
								<?php echo esc_html( (string) $args['status'] ); ?>
							</p>

							<?php if ( '' !== (string) $args['email'] && is_email( (string) $args['email'] ) ) : ?>
								<p class="contact-alt">
									<?php esc_html_e( 'Prefer email?', 'thirtydayhomes' ); ?>
									<a href="mailto:<?php echo esc_attr( (string) $args['email'] ); ?>"><?php echo esc_html( (string) $args['email'] ); ?></a>
								</p>
							<?php endif; ?>
						</div>
					</aside>

					<div class="contact-panel">

						<?php if ( '' !== $notice ) : ?>
							<div class="form-notice form-notice--<?php echo esc_attr( 'ok' === $kind ? 'ok' : 'error' ); ?>" role="status">
								<?php echo $icon( 'ok' === $kind ? 'check' : 'shield-check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<p><?php echo esc_html( $notice ); ?></p>
							</div>
						<?php endif; ?>

						<?php if ( $errors ) : ?>
							<div class="form-notice form-notice--error" role="alert">
								<?php echo $icon( 'shield-check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<div>
									<?php foreach ( $errors as $error ) : ?>
										<p><?php echo esc_html( (string) $error ); ?></p>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>

						<form class="contact-form" method="post">

							<?php echo Contact::form_fields(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

							<?php
							/*
							 * The topic is a set of chips, not a dropdown. Four
							 * options is below the count at which a select earns
							 * its collapse, and a dropdown hides them all behind
							 * a tap — so nobody discovers that "listing my
							 * property" is a route until they go looking. The
							 * posted value is identical either way: the handler
							 * reads tdh_topic and checks it against topics().
							 */
							?>
							<fieldset class="contact-topics">
								<legend><?php esc_html_e( 'What is it about?', 'thirtydayhomes' ); ?></legend>

								<div class="contact-chips">
									<?php
									$chosen = $old( 'topic' );
									$chosen = array_key_exists( $chosen, Contact::topics() ) ? $chosen : 'renting';
									?>
									<?php foreach ( Contact::topic_chips() as $key => $label ) : ?>
										<label class="contact-chip">
											<input type="radio" name="tdh_topic"
												value="<?php echo esc_attr( $key ); ?>"
												<?php checked( $chosen, $key ); ?>>
											<span><?php echo esc_html( $label ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</fieldset>

							<div class="form-grid">
								<div class="form-field">
									<label for="tdh-c-name"><?php esc_html_e( 'Your name', 'thirtydayhomes' ); ?></label>
									<input id="tdh-c-name" name="tdh_name" type="text" autocomplete="name" required
										value="<?php echo esc_attr( $old( 'name' ) ); ?>">
								</div>

								<div class="form-field">
									<label for="tdh-c-email"><?php esc_html_e( 'Email address', 'thirtydayhomes' ); ?></label>
									<input id="tdh-c-email" name="tdh_email" type="email" autocomplete="email" required
										value="<?php echo esc_attr( $old( 'email' ) ); ?>">
								</div>
							</div>

							<div class="form-field">
								<label for="tdh-c-phone">
									<?php esc_html_e( 'Phone', 'thirtydayhomes' ); ?>
									<span class="label-note"><?php esc_html_e( '(optional)', 'thirtydayhomes' ); ?></span>
								</label>
								<input id="tdh-c-phone" name="tdh_phone" type="tel" autocomplete="tel"
									value="<?php echo esc_attr( $old( 'phone' ) ); ?>">
							</div>

							<div class="form-field contact-message">
								<label for="tdh-c-message"><?php esc_html_e( 'Message', 'thirtydayhomes' ); ?></label>
								<textarea id="tdh-c-message" name="tdh_message" rows="7" required
									placeholder="<?php esc_attr_e( 'Dates, neighborhood, how many people — whatever you already know.', 'thirtydayhomes' ); ?>"><?php echo esc_textarea( $old( 'message' ) ); ?></textarea>
							</div>

							<?php
							/*
							 * The honeypot. Off-screen and out of the tab order, so
							 * a person never meets it and a bot fills it like every
							 * other field. aria-hidden keeps a screen reader from
							 * reading out a field its user must not complete.
							 */
							?>
							<div class="tdh-hp" aria-hidden="true">
								<label for="tdh-c-website"><?php esc_html_e( 'Leave this empty', 'thirtydayhomes' ); ?></label>
								<input id="tdh-c-website" name="tdh_website" type="text" tabindex="-1" autocomplete="off">
							</div>

							<?php echo Bot_Check::field(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>

							<button class="gold-btn full contact-send" type="submit">
								<?php esc_html_e( 'Send message', 'thirtydayhomes' ); ?>
								<?php echo $icon( 'arrow-right', 17 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							</button>

							<p class="contact-fine">
								<?php echo $icon( 'shield-check', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php esc_html_e( 'Your details go to us and nobody else.', 'thirtydayhomes' ); ?>
							</p>

						</form>
					</div>

				</div>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The How it works page body.
	 *
	 * The prose version threw away the one thing this page's content actually
	 * has: shape. Two audiences, each with a SEQUENCE, and then a list of
	 * questions. As three headings and five paragraphs, a renter had to read
	 * the owner's paragraph to find out it was not theirs.
	 *
	 * The steps are numbered, and that is a deliberate difference from the
	 * About cards, which are not. Numbering has to encode something true:
	 * search, then compare, then make contact is an order — you cannot
	 * compare before you search. About's three cards are a set, not a
	 * sequence, so numbering them there would have been decoration.
	 *
	 * Every claim below is in the approved copy already; only its shape has
	 * changed.
	 *
	 * @param array<string,mixed> $args
	 */
	/**
	 * The two tracks the page is built around.
	 *
	 * Public and separate so the Elementor widget seeds itself from exactly
	 * what the shortcode renders — see the note on default_about_facts().
	 *
	 * Two tracks, not a list: the marketplace has exactly two audiences, and
	 * the page's whole job is telling a renter and an owner apart before
	 * either reads the other's steps.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function default_hiw_tracks(): array {

		$homes   = (string) get_post_type_archive_link( Post_Types::LISTING );
		$pricing = get_page_by_path( 'pricing' );

		return [
			[
				'icon'    => 'search',
				'eyebrow' => __( 'If you need a home', 'thirtydayhomes' ),
				'heading' => __( 'For renters', 'thirtydayhomes' ),
				'steps'   => [
					[
						'title' => __( 'Search', 'thirtydayhomes' ),
						'copy'  => __( 'By neighborhood, by ZIP code, or by the hospital you are working at.', 'thirtydayhomes' ),
					],
					[
						'title' => __( 'Compare', 'thirtydayhomes' ),
						'copy'  => __( 'Homes are ordered by distance from where you need to be, and the full cost of a stay is on the listing before you inquire.', 'thirtydayhomes' ),
					],
					[
						'title' => __( 'Get in touch', 'thirtydayhomes' ),
						'copy'  => __( 'Contact the owner of the home directly. No agent in the middle, and no booking fee.', 'thirtydayhomes' ),
					],
				],
				'cta'     => __( 'Find a home', 'thirtydayhomes' ),
				'url'     => $homes,
			],
			[
				'icon'    => 'key-round',
				'eyebrow' => __( 'If you have a home', 'thirtydayhomes' ),
				'heading' => __( 'For property owners', 'thirtydayhomes' ),
				'steps'   => [
					[
						'title' => __( 'Join', 'thirtydayhomes' ),
						'copy'  => __( 'Choose a membership for the number of homes you plan to list.', 'thirtydayhomes' ),
					],
					[
						'title' => __( 'Publish', 'thirtydayhomes' ),
						'copy'  => __( 'Add your furnished home. Every listing is reviewed before it goes live.', 'thirtydayhomes' ),
					],
					[
						'title' => __( 'Take inquiries', 'thirtydayhomes' ),
						'copy'  => __( 'Renters contact you directly, and you agree the terms between you.', 'thirtydayhomes' ),
					],
				],
				'cta'     => __( 'See membership options', 'thirtydayhomes' ),
				'url'     => $pricing ? (string) get_permalink( $pricing ) : '',
			],
		];
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	public static function default_hiw_faq(): array {
		return [
			[
				'q' => __( 'How are search results ordered?', 'thirtydayhomes' ),
				'a' => __( 'When you search by location or ZIP code, homes appear closest to farthest.', 'thirtydayhomes' ),
			],
			[
				'q' => __( 'How do I know a home is available?', 'thirtydayhomes' ),
				'a' => __( 'Each listing shows its available date and any blocked date ranges.', 'thirtydayhomes' ),
			],
			[
				'q' => __( 'Are there extra fees?', 'thirtydayhomes' ),
				'a' => __( 'Application, pet and refundable deposit amounts are itemised on every listing.', 'thirtydayhomes' ),
			],
			[
				'q' => __( 'Why is the exact address not shown?', 'thirtydayhomes' ),
				'a' => __( 'Listings show the neighborhood and an approximate map area. The full address is shared by the owner after you make contact — a deliberate choice, because a furnished home that is often empty should not have its address published.', 'thirtydayhomes' ),
			],

			/*
			 * The owner's three (G3b design review). Every answer states
			 * something the product already does; the price is read from
			 * the plans, so confirming it there confirms it here.
			 */
			[
				'q' => __( 'What does it cost to list a home?', 'thirtydayhomes' ),
				'a' => sprintf(
					/* translators: %s: the smallest plan's monthly price, e.g. "$49" */
					__( 'Membership is %s a month for one home, and less per home for two or three. There is no commission, and renters pay no booking fee.', 'thirtydayhomes' ),
					'$' . number_format_i18n( (float) ( self::plans()[0]['price'] ?? 0 ) )
				),
			],
			[
				'q' => __( 'What happens after I submit a home?', 'thirtydayhomes' ),
				'a' => __( 'A member of our team checks it before it goes live. Your dashboard shows where it stands, and if anything needs changing first you get a note saying what.', 'thirtydayhomes' ),
			],
			[
				'q' => __( 'Can I pause a listing while the home is occupied?', 'thirtydayhomes' ),
				'a' => __( 'Yes. Pause and resume it from your dashboard. Resuming needs no second review unless you changed the home’s main details while it was paused.', 'thirtydayhomes' ),
			],
		];
	}

	public static function how_it_works( array $args = [] ): string {

		$contact = get_page_by_path( 'contact' );

		$args = wp_parse_args(
			$args,
			[
				'tracks'      => self::default_hiw_tracks(),

				'faq_eyebrow' => __( 'Before you ask', 'thirtydayhomes' ),
				'faq_heading' => __( 'Frequently asked questions', 'thirtydayhomes' ),
				'faq'         => self::default_hiw_faq(),

				'ask_heading' => __( 'Still not sure?', 'thirtydayhomes' ),
				'ask_copy'    => __( 'Ask us. We answer within one business day.', 'thirtydayhomes' ),
				'ask_cta'     => __( 'Contact us', 'thirtydayhomes' ),
				'ask_url'     => $contact ? (string) get_permalink( $contact ) : '',
			]
		);

		$icon = static fn( string $name, int $size = 19 ): string =>
			function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size, 2 ) : '';

		ob_start();
		?>

		<section class="section hiw-tracks">
			<div class="hiw-inner hiw-tracks-grid">
				<?php foreach ( array_values( (array) $args['tracks'] ) as $track_i => $track ) : ?>
					<article class="hiw-track">

						<header>
							<i><?php echo $icon( (string) $track['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
							<p class="overline gold"><?php echo esc_html( (string) $track['eyebrow'] ); ?></p>
							<h2><?php echo esc_html( (string) $track['heading'] ); ?></h2>
						</header>

						<?php
						/*
						 * An ordered list, because the order IS the content.
						 * The numbers on screen come from the markup rather
						 * than from CSS counters, so they survive a stylesheet
						 * that fails to load and a screen reader announces
						 * "3 items" rather than three unrelated headings.
						 */
						?>
						<ol class="hiw-steps">
							<?php foreach ( (array) $track['steps'] as $i => $step ) : ?>
								<li>
									<span class="hiw-step-n" aria-hidden="true"><?php echo esc_html( (string) ( $i + 1 ) ); ?></span>
									<div>
										<b><?php echo esc_html( (string) $step['title'] ); ?></b>
										<p><?php echo esc_html( (string) $step['copy'] ); ?></p>
									</div>
								</li>
							<?php endforeach; ?>
						</ol>

						<?php
						/*
						 * A button at the foot of each track, not a text link
						 * (G3b design review): the renter's gold, the owner's
						 * outlined — one filled primary on the page — and both
						 * pinned level by the stylesheet.
						 */
						?>
						<?php if ( ! empty( $track['url'] ) && ! empty( $track['cta'] ) ) : ?>
							<a class="<?php echo 0 === $track_i ? 'gold-btn' : 'secondary'; ?> hiw-track-cta" href="<?php echo esc_url( (string) $track['url'] ); ?>">
								<?php echo esc_html( (string) $track['cta'] ); ?>
								<?php echo $icon( 'arrow-right', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							</a>
						<?php endif; ?>

					</article>
				<?php endforeach; ?>
			</div>
		</section>

		<section class="section hiw-faq">
			<div class="hiw-inner">

				<div class="section-title">
					<div>
						<p class="overline gold"><?php echo esc_html( (string) $args['faq_eyebrow'] ); ?></p>
						<h2><?php echo esc_html( (string) $args['faq_heading'] ); ?></h2>
					</div>
				</div>

				<?php
				/*
				 * <details>, not a JavaScript accordion. It opens with a
				 * click, with Enter, and with the keyboard alone; a browser's
				 * find-in-page can search inside a closed one; and it still
				 * works with every script on the page blocked. No library
				 * earns its place against that.
				 *
				 * The shared `name` makes the group exclusive — opening one
				 * closes the rest, the way radio buttons work — and that is
				 * also native, so it costs nothing. A browser too old to know
				 * the attribute simply lets two stay open, which is a lesser
				 * version rather than a broken one.
				 *
				 * The name is unique per render: two of these blocks on one
				 * page would otherwise form a single group, and opening a
				 * question in the second would silently close one in the
				 * first.
				 */
				static $group = 0;
				++$group;
				$name = 'tdh-faq-' . $group;
				?>
				<div class="hiw-faq-list">
					<?php foreach ( (array) $args['faq'] as $item ) : ?>
						<details class="hiw-q" name="<?php echo esc_attr( $name ); ?>">
							<summary>
								<span><?php echo esc_html( (string) $item['q'] ); ?></span>
								<i aria-hidden="true"></i>
							</summary>
							<p><?php echo esc_html( (string) $item['a'] ); ?></p>
						</details>
					<?php endforeach; ?>
				</div>

			</div>
		</section>

		<?php if ( '' !== $args['ask_url'] ) : ?>
			<section class="section hiw-ask">
				<div class="hiw-inner hiw-ask-inner">
					<div>
						<h2><?php echo esc_html( (string) $args['ask_heading'] ); ?></h2>
						<p><?php echo esc_html( (string) $args['ask_copy'] ); ?></p>
					</div>
					<a class="gold-btn" href="<?php echo esc_url( (string) $args['ask_url'] ); ?>">
						<?php echo esc_html( (string) $args['ask_cta'] ); ?>
						<?php echo $icon( 'arrow-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</a>
				</div>
			</section>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The About page body.
	 *
	 * Four bands, not a column of prose. Two headings and two short
	 * paragraphs left three quarters of the screen empty and answered
	 * neither of the questions people actually arrive with: a renter asking
	 * whether this is a real service, and an owner asking whether it is
	 * worth paying for. Each band answers one thing, and the page ends by
	 * sending each of those two visitors somewhere.
	 *
	 * The approved design's copy is kept — its statement, its "What to
	 * expect", its "Rules and regulations" paragraph. What is added only
	 * describes behaviour the site already has: itemised costs on a listing,
	 * inquiries that go to the owner, distance search, review before
	 * publication, and the Fair Housing rule the listing form enforces.
	 * Nothing here claims anything the product does not do.
	 *
	 * @param array<string,mixed> $args
	 */
	/**
	 * The three facts beside the statement.
	 *
	 * Public and separate so the Elementor widget seeds its repeater from
	 * exactly what the shortcode renders. Two copies of this list would
	 * drift the first time somebody edited one of them.
	 *
	 * Subject set large, predicate small, so the three read as sentences
	 * rather than as a stat block. They are facts about how the marketplace
	 * works, not achievements — this site has no users yet, and a rail of
	 * invented numbers on the About page is the first thing a careful
	 * visitor disbelieves.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function default_about_facts(): array {
		return [
			[
				'value' => __( '30+ nights', 'thirtydayhomes' ),
				'label' => __( 'is the minimum stay — long enough to unpack, short of signing a year.', 'thirtydayhomes' ),
			],
			[
				'value' => __( 'Pittsburgh', 'thirtydayhomes' ),
				'label' => __( 'is the first market. The platform is built to add more.', 'thirtydayhomes' ),
			],
			[
				'value' => __( 'Every listing', 'thirtydayhomes' ),
				'label' => __( 'is reviewed before it goes live on the site.', 'thirtydayhomes' ),
			],
		];
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	public static function default_about_cards(): array {
		return [
			[
				'icon'  => 'shield-check',
				'title' => __( 'Clear information', 'thirtydayhomes' ),
				'copy'  => __( 'Rent, deposits, application and pet fees are itemised on every listing, so the full cost of a stay is visible before you get in touch.', 'thirtydayhomes' ),
			],
			[
				'icon'  => 'key-round',
				'title' => __( 'Direct communication', 'thirtydayhomes' ),
				'copy'  => __( 'Inquiries go to the owner of the home. No agent in the middle, and no booking fee for renters.', 'thirtydayhomes' ),
			],
			[
				'icon'  => 'bed-double',
				'title' => __( 'Built for extended stays', 'thirtydayhomes' ),
				'copy'  => __( 'Furnished homes you can search by distance from the hospital, campus or site you are working at.', 'thirtydayhomes' ),
			],
		];
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	public static function default_about_rules(): array {
		return [
			[
				'title' => __( 'Accurate information', 'thirtydayhomes' ),
				'copy'  => __( 'Describe the home, the terms and the stay as they actually are.', 'thirtydayhomes' ),
			],
			[
				'title' => __( 'Respectful communication', 'thirtydayhomes' ),
				'copy'  => __( 'There is a person at the other end of every inquiry, on both sides of it.', 'thirtydayhomes' ),
			],
			[
				'title' => __( 'Fair Housing', 'thirtydayhomes' ),
				'copy'  => __( 'Listings describe the property, not the ideal renter.', 'thirtydayhomes' ),
			],
			[
				'title' => __( 'Property rules', 'thirtydayhomes' ),
				'copy'  => __( 'Pets, parking, smoking and guests are acknowledged before an inquiry is sent.', 'thirtydayhomes' ),
			],
		];
	}

	/**
	 * The two doors the page ends on.
	 *
	 * Two rather than one call to action, because About is the page both
	 * audiences read, and sending a renter to the membership plans is the
	 * wrong door.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function default_about_doors(): array {

		$pricing = get_page_by_path( 'pricing' );
		$homes   = (string) get_post_type_archive_link( Post_Types::LISTING );

		return [
			[
				'icon'  => 'search',
				'title' => __( 'Looking for a home', 'thirtydayhomes' ),
				'copy'  => __( 'Search furnished homes by neighborhood, ZIP code or hospital, and see the full cost before you inquire.', 'thirtydayhomes' ),
				'cta'   => __( 'Find a home', 'thirtydayhomes' ),
				'url'   => $homes,
			],
			[
				'icon'  => 'key-round',
				'title' => __( 'Have a property to list', 'thirtydayhomes' ),
				'copy'  => __( 'Join as a member, publish your furnished home, and take inquiries directly from renters.', 'thirtydayhomes' ),
				'cta'   => __( 'See membership options', 'thirtydayhomes' ),
				'url'   => $pricing ? (string) get_permalink( $pricing ) : '',
			],
		];
	}

	public static function about( array $args = [] ): string {

		$fair = get_page_by_path( 'fair-housing' );

		$args = wp_parse_args(
			$args,
			[
				/*
				 * The statement that used to sit under the banner heading.
				 * It moved down here because at 168 characters it wrapped to
				 * two ragged centred lines inside a 13rem band; on a light
				 * ground with room around it, its length is an asset.
				 */
				'statement'       => __( 'ThirtyDayHomes connects traveling professionals and families with verified furnished homes near the places they need to be — starting in Pittsburgh, and built to expand.', 'thirtydayhomes' ),

				'facts'           => self::default_about_facts(),

				'expect_eyebrow'  => __( 'For renters and owners', 'thirtydayhomes' ),
				'expect_heading'  => __( 'What to expect', 'thirtydayhomes' ),
				'expect_intro'    => __( 'Clear information, direct communication, and a thoughtfully designed experience for extended stays.', 'thirtydayhomes' ),
				'expect_cards'    => self::default_about_cards(),

				'rules_eyebrow'   => __( 'The ground rules', 'thirtydayhomes' ),
				'rules_heading'   => __( 'Rules and regulations', 'thirtydayhomes' ),
				'rules_intro'     => __( 'Renters and landlords must provide accurate information, communicate respectfully, follow Fair Housing requirements, and acknowledge property-specific rules before an inquiry is sent.', 'thirtydayhomes' ),
				'rules'           => self::default_about_rules(),
				'rules_link_text' => __( 'Read our Fair Housing commitment', 'thirtydayhomes' ),
				'rules_link_url'  => $fair ? (string) get_permalink( $fair ) : '',

				'doors'           => self::default_about_doors(),

				// Every placeholder page on this site says so on its face.
				// About stays placeholder until the client signs the copy off.
				'note'            => __( 'Draft copy, to be reviewed and approved before launch.', 'thirtydayhomes' ),
			]
		);

		$icon = static fn( string $name, int $size = 19 ): string =>
			function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size, 2 ) : '';

		ob_start();
		?>

		<section class="section about-intro">
			<div class="about-inner about-intro-grid">

				<?php if ( '' !== $args['statement'] ) : ?>
					<p class="about-statement"><?php echo esc_html( (string) $args['statement'] ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $args['facts'] ) ) : ?>
					<ul class="about-facts">
						<?php foreach ( (array) $args['facts'] as $fact ) : ?>
							<li>
								<b><?php echo esc_html( (string) ( $fact['value'] ?? '' ) ); ?></b>
								<span><?php echo esc_html( (string) ( $fact['label'] ?? '' ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

			</div>
		</section>

		<section class="section about-expect">
			<div class="about-inner">

				<div class="section-title">
					<div>
						<?php if ( '' !== $args['expect_eyebrow'] ) : ?>
							<p class="overline gold"><?php echo esc_html( (string) $args['expect_eyebrow'] ); ?></p>
						<?php endif; ?>
						<h2><?php echo esc_html( (string) $args['expect_heading'] ); ?></h2>
						<?php if ( '' !== $args['expect_intro'] ) : ?>
							<p><?php echo esc_html( (string) $args['expect_intro'] ); ?></p>
						<?php endif; ?>
					</div>
				</div>

				<div class="about-cards">
					<?php foreach ( (array) $args['expect_cards'] as $card ) : ?>
						<article>
							<i><?php echo $icon( (string) ( $card['icon'] ?? 'check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
							<h3><?php echo esc_html( (string) ( $card['title'] ?? '' ) ); ?></h3>
							<p><?php echo esc_html( (string) ( $card['copy'] ?? '' ) ); ?></p>
						</article>
					<?php endforeach; ?>
				</div>

			</div>
		</section>

		<section class="section about-rules">
			<div class="about-inner about-rules-grid">

				<div class="about-rules-head">
					<div class="section-title">
						<div>
							<?php if ( '' !== $args['rules_eyebrow'] ) : ?>
								<?php // No .gold here: that modifier is the deep gold for light grounds, and this band is navy. ?>
								<p class="overline"><?php echo esc_html( (string) $args['rules_eyebrow'] ); ?></p>
							<?php endif; ?>
							<h2><?php echo esc_html( (string) $args['rules_heading'] ); ?></h2>
							<?php if ( '' !== $args['rules_intro'] ) : ?>
								<p><?php echo esc_html( (string) $args['rules_intro'] ); ?></p>
							<?php endif; ?>
						</div>
					</div>

					<?php if ( '' !== $args['rules_link_text'] && '' !== $args['rules_link_url'] ) : ?>
						<a class="about-rules-link" href="<?php echo esc_url( (string) $args['rules_link_url'] ); ?>">
							<?php echo esc_html( (string) $args['rules_link_text'] ); ?>
							<?php echo $icon( 'arrow-right', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</a>
					<?php endif; ?>
				</div>

				<ul class="about-rules-list">
					<?php foreach ( (array) $args['rules'] as $rule ) : ?>
						<li>
							<?php echo $icon( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<div>
								<b><?php echo esc_html( (string) ( $rule['title'] ?? '' ) ); ?></b>
								<p><?php echo esc_html( (string) ( $rule['copy'] ?? '' ) ); ?></p>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>

			</div>
		</section>

		<section class="section about-doors">
			<div class="about-inner">

				<div class="about-doors-grid">
					<?php
					$door_i = 0;
					foreach ( (array) $args['doors'] as $door ) :
						if ( empty( $door['url'] ) ) {
							continue;
						}
						?>
						<?php
						/*
						 * Two doors, one gold (G3b design review): the first
						 * is the renter's, and renters are the marketplace's
						 * larger audience, so it takes the filled button; the
						 * owner's door is outlined. Both are pinned to the
						 * card's foot so the pair ends level.
						 */
						$door_kind = 0 === $door_i ? 'about-door-cta--gold' : 'about-door-cta--outline';
						?>
						<a class="about-door" href="<?php echo esc_url( (string) $door['url'] ); ?>">
							<i><?php echo $icon( (string) ( $door['icon'] ?? 'arrow-right' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
							<h3><?php echo esc_html( (string) ( $door['title'] ?? '' ) ); ?></h3>
							<p><?php echo esc_html( (string) ( $door['copy'] ?? '' ) ); ?></p>
							<span class="about-door-cta <?php echo esc_attr( $door_kind ); ?>">
								<?php echo esc_html( (string) ( $door['cta'] ?? '' ) ); ?>
								<?php echo $icon( 'arrow-right', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							</span>
						</a>
						<?php ++$door_i; ?>
					<?php endforeach; ?>
				</div>

				<?php
				// A note to the client, not to a visitor (G3b): staff see it,
				// labelled; the public page carries no "draft" on its face.
				if ( '' !== $args['note'] && Accounts::is_staff() ) :
					?>
					<p class="about-note"><em><?php echo esc_html( __( 'Staff note:', 'thirtydayhomes' ) . ' ' . (string) $args['note'] ); ?></em></p>
				<?php endif; ?>

			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
