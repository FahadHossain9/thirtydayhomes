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
				'note'     => __( 'Standard rate', 'thirtydayhomes' ),
				'featured' => false,
				'badge'    => '',
			],
			[
				'listings' => 2,
				'label'    => __( '2 listings', 'thirtydayhomes' ),
				'price'    => 88,
				'note'     => __( '10% multi-listing discount', 'thirtydayhomes' ),
				'featured' => false,
				'badge'    => '',
			],
			[
				'listings' => 3,
				'label'    => __( '3 listings', 'thirtydayhomes' ),
				'price'    => 125,
				'note'     => __( '15% multi-listing discount', 'thirtydayhomes' ),
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

		$features = self::plan_features();
		$signed_in = is_user_logged_in();

		$icon = static fn( string $name, int $size = 16 ): string =>
			function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size ) : '';

		ob_start();
		?>
		<div class="pricing">

			<div class="page-head center">
				<p class="overline gold"><?php echo esc_html( (string) $args['eyebrow'] ); ?></p>
				<h1><?php echo esc_html( (string) $args['heading'] ); ?></h1>
				<p><?php echo esc_html( (string) $args['intro'] ); ?></p>
			</div>

			<div class="pricing-grid">
				<?php foreach ( self::plans() as $plan ) : ?>
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
							<b><?php echo esc_html( $args['currency'] . number_format_i18n( (float) $plan['price'] ) ); ?></b>
							<small><?php esc_html_e( '/ month', 'thirtydayhomes' ); ?></small>
						</p>

						<p class="plan-note"><?php echo esc_html( (string) $plan['note'] ); ?></p>

						<ul class="plan-features">
							<?php foreach ( $features as $feature ) : ?>
								<li>
									<?php echo $icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<?php echo esc_html( (string) $feature ); ?>
								</li>
							<?php endforeach; ?>
							<li>
								<?php echo $icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php
								printf(
									/* translators: %d: number of homes the plan allows */
									esc_html( _n( '%d home published', '%d homes published', $listings, 'thirtydayhomes' ) ),
									(int) $listings
								);
								?>
							</li>
						</ul>

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

			<p class="pricing-note">
				<?php esc_html_e( 'The discount applies automatically as homes are added.', 'thirtydayhomes' ); ?>
				<strong><?php esc_html_e( 'Prices shown are not final and are awaiting client confirmation.', 'thirtydayhomes' ); ?></strong>
			</p>

		</div>
		<?php
		return (string) ob_get_clean();
	}
}
