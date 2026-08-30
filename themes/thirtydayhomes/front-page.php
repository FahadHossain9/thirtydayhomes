<?php
/**
 * Front page — ported from the approved prototype.
 *
 * Theme fallback: once Elementor Pro is installed the homepage is composed
 * there and this stops rendering. Built now so the site is demonstrable
 * and so there is a reference for what the Elementor version must match.
 *
 * The hero search is a real GET form pointing at the listing archive. The
 * query module that reads these parameters lands in Milestone 2 — until
 * then submitting takes the renter to the full listing archive with their
 * terms already in the URL, ready to be consumed. That is preferable to a
 * decorative search box that silently does nothing.
 *
 * @package ThirtyDayHomes
 */

defined( 'ABSPATH' ) || exit;

get_header();

/*
 * Whatever the client has put on the Home page wins — shortcodes in the
 * editor, or an Elementor layout. The sections further down are the
 * fallback that renders on a fresh install before anyone has composed
 * anything, and they are the reference for what the composed version is
 * expected to reproduce.
 */
$front_id  = (int) get_queried_object_id();
$has_own   = tdh_page_has_content( $front_id );

if ( $has_own ) :

	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;

elseif ( ! tdh_elementor_location( 'single' ) ) :

	$archive = get_post_type_archive_link( 'tdh_listing' );

	// Served locally, and replaceable by the client in Appearance →
	// Customize → Hero without touching a line of code. See tdh_hero_image().
	$hero_image = tdh_hero_image();

	$featured = new WP_Query(
		[
			'post_type'      => 'tdh_listing',
			'posts_per_page' => 3,
			'no_found_rows'  => true,
		]
	);
	?>

	<section class="hero" style="--hero-image: url( '<?php echo esc_url( $hero_image ); ?>' );">
		<div class="hero-bg" aria-hidden="true"></div>

		<div class="hero-inner">
			<p class="overline"><?php esc_html_e( 'Furnished homes · 30+ day stays', 'thirtydayhomes' ); ?></p>

			<h1>
				<?php esc_html_e( 'Stay a while.', 'thirtydayhomes' ); ?><br>
				<em><?php esc_html_e( 'Feel at home.', 'thirtydayhomes' ); ?></em>
			</h1>

			<p class="lead">
				<?php esc_html_e( 'Move-in ready homes near the places that matter. Flexible monthly stays for traveling professionals, families, and everyone in between.', 'thirtydayhomes' ); ?>
			</p>

			<form class="hero-search date-search" action="<?php echo esc_url( $archive ); ?>" method="get" role="search" data-tdh-hero-search>

				<label>
					<?php tdh_the_icon( 'map-pin' ); ?>
					<span>
						<b><?php esc_html_e( 'Where', 'thirtydayhomes' ); ?></b>
						<input type="search" name="q" placeholder="<?php esc_attr_e( 'Neighborhood, ZIP, or hospital', 'thirtydayhomes' ); ?>">
					</span>
				</label>

				<label>
					<?php tdh_the_icon( 'calendar-days' ); ?>
					<span>
						<b><?php esc_html_e( 'Start date', 'thirtydayhomes' ); ?> <span aria-hidden="true">*</span></b>
						<input type="date" name="start" required data-tdh-start>
					</span>
				</label>

				<label>
					<?php tdh_the_icon( 'calendar-days' ); ?>
					<span>
						<b><?php esc_html_e( 'End date', 'thirtydayhomes' ); ?> <span aria-hidden="true">*</span></b>
						<input type="date" name="end" required data-tdh-end>
					</span>
				</label>

				<?php
				/**
				 * The approved design dims this button until both dates are
				 * set. A disabled control with no stated reason is a dead end
				 * for a screen reader, so the hint below is wired to it with
				 * aria-describedby and announces when the state changes.
				 */
				?>
				<button type="submit" disabled aria-describedby="tdh-search-hint" data-tdh-submit>
					<?php tdh_the_icon( 'search' ); ?>
					<?php esc_html_e( 'Search homes', 'thirtydayhomes' ); ?>
				</button>
			</form>

			<p id="tdh-search-hint" class="hero-hint" role="status">
				<?php esc_html_e( 'Enter a start and end date to search.', 'thirtydayhomes' ); ?>
			</p>

			<ul class="hero-trust">
				<li><?php tdh_the_icon( 'check', 14 ); ?><?php esc_html_e( 'Fully furnished', 'thirtydayhomes' ); ?></li>
				<li><?php tdh_the_icon( 'check', 14 ); ?><?php esc_html_e( 'Utilities included', 'thirtydayhomes' ); ?></li>
				<li><?php tdh_the_icon( 'check', 14 ); ?><?php esc_html_e( 'Reviewed before listing', 'thirtydayhomes' ); ?></li>
			</ul>
		</div>
	</section>

	<?php
	// Icons are the real Lucide set from the approved prototype — see
	// inc/icons.php for why they are inlined rather than substituted.
	$audiences = [
		[
			'eyebrow' => __( 'For healthcare', 'thirtydayhomes' ),
			'title'   => __( 'Medical professionals', 'thirtydayhomes' ),
			'copy'    => __( 'Comfortable homes near hospitals and healthcare facilities for nurses, physicians, therapists and clinical teams — with the 13-week contract in mind.', 'thirtydayhomes' ),
			'icon'    => 'stethoscope',
		],
		[
			'eyebrow' => __( 'For business', 'thirtydayhomes' ),
			'title'   => __( 'Corporate travelers', 'thirtydayhomes' ),
			'copy'    => __( 'Move-in-ready homes with space to work, recharge and settle in during relocations, training and extended assignments.', 'thirtydayhomes' ),
			'icon'    => 'briefcase',
		],
		[
			'eyebrow' => __( 'For project teams', 'thirtydayhomes' ),
			'title'   => __( 'Construction crews', 'thirtydayhomes' ),
			'copy'    => __( 'Practical housing for crews of every size — with kitchens, parking, laundry and flexible monthly terms.', 'thirtydayhomes' ),
			'icon'    => 'hard-hat',
		],
		[
			'eyebrow' => __( 'For academics', 'thirtydayhomes' ),
			'title'   => __( 'Student housing', 'thirtydayhomes' ),
			'copy'    => __( 'Furnished monthly homes for internships, clinical rotations, semester stays, visiting scholars and temporary placements.', 'thirtydayhomes' ),
			'icon'    => 'graduation-cap',
		],
	];
	?>

	<section class="audience">
		<div class="audience-head">
			<div>
				<p class="overline gold"><?php esc_html_e( 'Made for working travelers', 'thirtydayhomes' ); ?></p>
				<h2><?php esc_html_e( 'Housing that works as hard as you do.', 'thirtydayhomes' ); ?></h2>
			</div>
			<p><?php esc_html_e( 'Whether it’s one traveler or an entire project team, find a furnished home with the space and flexibility your assignment needs.', 'thirtydayhomes' ); ?></p>
		</div>

		<div class="audience-grid">
			<?php foreach ( $audiences as $card ) : ?>
				<article>
					<i><?php tdh_the_icon( $card['icon'], 26, 1.6 ); ?></i>
					<p class="overline gold"><?php echo esc_html( $card['eyebrow'] ); ?></p>
					<h3><?php echo esc_html( $card['title'] ); ?></h3>
					<p><?php echo esc_html( $card['copy'] ); ?></p>
				</article>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="section">
		<div class="section-title">
			<div>
				<p class="overline gold"><?php esc_html_e( 'Explore Pittsburgh', 'thirtydayhomes' ); ?></p>
				<h2><?php esc_html_e( 'Homes ready when you are', 'thirtydayhomes' ); ?></h2>
				<p><?php esc_html_e( 'Handpicked monthly rentals close to Pittsburgh’s leading medical centres.', 'thirtydayhomes' ); ?></p>
			</div>
			<a class="text-btn" href="<?php echo esc_url( $archive ); ?>">
				<?php esc_html_e( 'View all homes →', 'thirtydayhomes' ); ?>
			</a>
		</div>

		<?php if ( $featured->have_posts() ) : ?>
			<div class="property-grid">
				<?php
				while ( $featured->have_posts() ) :
					$featured->the_post();
					get_template_part( 'template-parts/listing-card' );
				endwhile;
				wp_reset_postdata();
				?>
			</div>
		<?php else : ?>
			<div class="empty">
				<h3><?php esc_html_e( 'No homes are listed yet', 'thirtydayhomes' ); ?></h3>
				<p><?php esc_html_e( 'New furnished homes are added regularly.', 'thirtydayhomes' ); ?></p>
			</div>
		<?php endif; ?>
	</section>

	<section class="owner-cta">
		<div>
			<p class="overline"><?php esc_html_e( 'For property owners', 'thirtydayhomes' ); ?></p>
			<h2><?php esc_html_e( 'Your property works harder with longer stays.', 'thirtydayhomes' ); ?></h2>
			<p><?php esc_html_e( 'Reach trusted professionals and families seeking furnished homes.', 'thirtydayhomes' ); ?></p>
			<?php
			$pricing = get_page_by_path( 'pricing' );
			if ( $pricing ) :
				?>
				<a class="gold-btn" href="<?php echo esc_url( (string) get_permalink( $pricing ) ); ?>">
					<?php esc_html_e( 'See membership options', 'thirtydayhomes' ); ?>
				</a>
			<?php endif; ?>
		</div>

		<div class="cta-stats">
			<div><b><?php echo esc_html( '30+' ); ?></b><small><?php esc_html_e( 'night minimum', 'thirtydayhomes' ); ?></small></div>
			<div><b><?php echo esc_html( '100%' ); ?></b><small><?php esc_html_e( 'direct inquiries', 'thirtydayhomes' ); ?></small></div>
		</div>
	</section>

	<?php
endif;

get_footer();
