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
				'require_dates' => true,
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

		$require = (bool) $args['require_dates'];

		// Unique per instance: a page may hold two heroes, and duplicate
		// ids would break the aria-describedby link on both.
		$hint_id = 'tdh-search-hint-' . sanitize_html_class( (string) $args['uid'] );

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
							<b><?php esc_html_e( 'Start date', 'thirtydayhomes' ); ?><?php echo $require ? ' <span aria-hidden="true">*</span>' : ''; ?></b>
							<input type="date" name="start" <?php echo $require ? 'required' : ''; ?> data-tdh-start>
						</span>
					</label>

					<label>
						<?php echo $icon( 'calendar-days' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<span>
							<b><?php esc_html_e( 'End date', 'thirtydayhomes' ); ?><?php echo $require ? ' <span aria-hidden="true">*</span>' : ''; ?></b>
							<input type="date" name="end" <?php echo $require ? 'required' : ''; ?> data-tdh-end>
						</span>
					</label>

					<button type="submit" <?php echo $require ? 'disabled aria-describedby="' . esc_attr( $hint_id ) . '"' : ''; ?> data-tdh-submit>
						<?php echo $icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php echo esc_html( (string) $args['button_text'] ); ?>
					</button>
				</form>

				<?php if ( $require ) : ?>
					<p id="<?php echo esc_attr( $hint_id ); ?>" class="hero-hint" role="status">
						<?php esc_html_e( 'Enter a start and end date to search.', 'thirtydayhomes' ); ?>
					</p>
				<?php endif; ?>

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
							echo wp_kses( (string) $args['heading'], [ 'br' => [] ] );
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
	 * The four audience cards, as approved in the prototype.
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
				'link_text' => __( 'Explore housing', 'thirtydayhomes' ),
				'link_url'  => '',
			],
			[
				'icon'      => 'briefcase',
				'eyebrow'   => __( 'For business', 'thirtydayhomes' ),
				'title'     => __( 'Corporate travelers', 'thirtydayhomes' ),
				'copy'      => __( 'Move-in-ready homes with space to work, recharge, and settle in during relocations, training, and extended assignments.', 'thirtydayhomes' ),
				'link_text' => __( 'Explore housing', 'thirtydayhomes' ),
				'link_url'  => '',
			],
			[
				'icon'      => 'hard-hat',
				'eyebrow'   => __( 'For project teams', 'thirtydayhomes' ),
				'title'     => __( 'Construction crews', 'thirtydayhomes' ),
				'copy'      => __( 'Practical housing for crews of every size—with kitchens, parking, laundry, and flexible monthly terms.', 'thirtydayhomes' ),
				'link_text' => __( 'Explore housing', 'thirtydayhomes' ),
				'link_url'  => '',
			],
			[
				'icon'      => 'graduation-cap',
				'eyebrow'   => __( 'For academics', 'thirtydayhomes' ),
				'title'     => __( 'Student housing', 'thirtydayhomes' ),
				'copy'      => __( 'Furnished monthly homes for internships, clinical rotations, semester stays, visiting scholars, and temporary placements.', 'thirtydayhomes' ),
				'link_text' => __( 'Explore housing', 'thirtydayhomes' ),
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
						echo wp_kses( (string) $args['heading'], [ 'br' => [] ] );
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
				'eyebrow'  => __( 'Simple, transparent membership', 'thirtydayhomes' ),
				'heading'  => __( 'More listings. Better value.', 'thirtydayhomes' ),
				'intro'    => __( 'Automatic volume pricing rewards landlords who publish more than one home.', 'thirtydayhomes' ),
				'currency' => '$',
			]
		);

		$features  = self::plan_features();
		$signed_in = is_user_logged_in();

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
						 * Checkout does not exist yet. Rather than a button
						 * that silently does nothing, a signed-in landlord
						 * is told plainly; a visitor is sent to the step
						 * that IS built, which is creating an account.
						 */
						?>
						<?php if ( $signed_in ) : ?>
							<button class="<?php echo $is_featured ? 'gold-btn' : 'secondary'; ?> full" type="button" disabled>
								<?php esc_html_e( 'Checkout coming soon', 'thirtydayhomes' ); ?>
							</button>
						<?php else : ?>
							<a class="<?php echo $is_featured ? 'gold-btn' : 'secondary'; ?> full" href="<?php echo esc_url( Accounts::url( 'register' ) ); ?>">
								<?php esc_html_e( 'Create an account', 'thirtydayhomes' ); ?>
							</a>
						<?php endif; ?>

					</div>
				<?php endforeach; ?>
			</div>

			<section class="plan-included">
				<h2><?php esc_html_e( 'Included in every plan', 'thirtydayhomes' ); ?></h2>
				<ul>
					<?php foreach ( $features as $feature ) : ?>
						<li>
							<?php echo $icon( 'check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<span><?php echo esc_html( (string) $feature ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>

			<p class="pricing-note">
				<?php esc_html_e( 'The discount applies automatically as homes are added.', 'thirtydayhomes' ); ?>
				<strong><?php esc_html_e( 'Prices shown are not final and are awaiting client confirmation.', 'thirtydayhomes' ); ?></strong>
			</p>

		</div>
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
	public static function how_it_works( array $args = [] ): string {

		$homes   = (string) get_post_type_archive_link( Post_Types::LISTING );
		$pricing = get_page_by_path( 'pricing' );
		$contact = get_page_by_path( 'contact' );

		$args = wp_parse_args(
			$args,
			[
				'tracks'      => [
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
				],

				'faq_eyebrow' => __( 'Before you ask', 'thirtydayhomes' ),
				'faq_heading' => __( 'Frequently asked questions', 'thirtydayhomes' ),
				'faq'         => [
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
				],

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
				<?php foreach ( (array) $args['tracks'] as $track ) : ?>
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

						<?php if ( ! empty( $track['url'] ) && ! empty( $track['cta'] ) ) : ?>
							<a class="hiw-track-cta" href="<?php echo esc_url( (string) $track['url'] ); ?>">
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
				 */
				?>
				<div class="hiw-faq-list">
					<?php foreach ( (array) $args['faq'] as $item ) : ?>
						<details class="hiw-q">
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
	public static function about( array $args = [] ): string {

		$pricing = get_page_by_path( 'pricing' );
		$fair    = get_page_by_path( 'fair-housing' );
		$homes   = (string) get_post_type_archive_link( Post_Types::LISTING );

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

				/*
				 * Subject set large, predicate small, so the three read as
				 * sentences rather than as a stat block. They are facts about
				 * how the marketplace works, not achievements — this site has
				 * no users yet, and a rail of invented numbers on the About
				 * page is the first thing a careful visitor disbelieves.
				 */
				'facts'           => [
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
				],

				'expect_eyebrow'  => __( 'For renters and owners', 'thirtydayhomes' ),
				'expect_heading'  => __( 'What to expect', 'thirtydayhomes' ),
				'expect_intro'    => __( 'Clear information, direct communication, and a thoughtfully designed experience for extended stays.', 'thirtydayhomes' ),
				'expect_cards'    => [
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
				],

				'rules_eyebrow'   => __( 'The ground rules', 'thirtydayhomes' ),
				'rules_heading'   => __( 'Rules and regulations', 'thirtydayhomes' ),
				'rules_intro'     => __( 'Renters and landlords must provide accurate information, communicate respectfully, follow Fair Housing requirements, and acknowledge property-specific rules before an inquiry is sent.', 'thirtydayhomes' ),
				'rules'           => [
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
				],
				'rules_link_text' => __( 'Read our Fair Housing commitment', 'thirtydayhomes' ),
				'rules_link_url'  => $fair ? (string) get_permalink( $fair ) : '',

				/*
				 * The page ends on the fork the whole marketplace is built
				 * around. Two doors rather than one call to action, because
				 * About is the page both audiences read, and sending a renter
				 * to the membership plans is the wrong door.
				 */
				'doors'           => [
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
				],

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
					foreach ( (array) $args['doors'] as $door ) :
						if ( empty( $door['url'] ) ) {
							continue;
						}
						?>
						<a class="about-door" href="<?php echo esc_url( (string) $door['url'] ); ?>">
							<i><?php echo $icon( (string) ( $door['icon'] ?? 'arrow-right' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
							<h3><?php echo esc_html( (string) ( $door['title'] ?? '' ) ); ?></h3>
							<p><?php echo esc_html( (string) ( $door['copy'] ?? '' ) ); ?></p>
							<span class="about-door-cta">
								<?php echo esc_html( (string) ( $door['cta'] ?? '' ) ); ?>
								<?php echo $icon( 'arrow-right', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							</span>
						</a>
					<?php endforeach; ?>
				</div>

				<?php if ( '' !== $args['note'] ) : ?>
					<p class="about-note"><em><?php echo esc_html( (string) $args['note'] ); ?></em></p>
				<?php endif; ?>

			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
