<?php
/**
 * Pages, menus and the front page.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Setup;

use TDH\Post_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the public page set, the navigation menus, and the front page.
 *
 * Page COPY here is placeholder scaffolding so the structure is navigable.
 * Real copy is client-approved, and the legal pages are attorney-supplied.
 * Every placeholder page says so on its face rather than looking finished.
 */
final class Site_Structure {

	public function __construct( private Importer $importer ) {}

	public function run(): void {

		$ids = [];

		foreach ( $this->pages() as $slug => $page ) {
			$id = $this->upsert_page( $slug, $page );

			if ( $id ) {
				$ids[ $slug ] = $id;
				$this->importer->log( sprintf( 'page #%d %s', $id, $page['title'] ) );
			}
		}

		$this->set_front_page( $ids );
		$this->build_menus( $ids );
		$this->remove_sample_content();
		$this->set_site_icon();

		update_option( 'blogdescription', __( 'Furnished 30+ day homes near Pittsburgh’s medical centres', 'thirtydayhomes' ) );
	}

	/**
	 * Set the browser-tab icon.
	 *
	 * Without one, every page request produces a 404 for /favicon.ico —
	 * harmless but noisy in the console, and a blank tab icon reads as an
	 * unfinished site to a client reviewing it.
	 *
	 * The source lives in the theme because it is brand artwork, so this
	 * skips quietly when a different theme is active rather than failing
	 * the import. WordPress generates the various sizes from the 512px
	 * square once it is registered as an attachment.
	 */
	private function set_site_icon(): void {

		if ( (int) get_option( 'site_icon' ) > 0 ) {
			return;
		}

		$source = get_template_directory() . '/assets/site-icon.png';

		if ( ! is_readable( $source ) ) {
			$this->importer->warn( 'no site icon in the active theme — browser tab will stay blank' );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$upload = wp_upload_bits( 'thirtydayhomes-site-icon.png', null, (string) file_get_contents( $source ) );

		if ( ! empty( $upload['error'] ) ) {
			$this->importer->warn( 'site icon: ' . $upload['error'] );
			return;
		}

		$attachment_id = wp_insert_attachment(
			[
				'post_mime_type' => 'image/png',
				'post_title'     => __( 'Site icon', 'thirtydayhomes' ),
				'post_status'    => 'inherit',
			],
			$upload['file']
		);

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			$this->importer->warn( 'site icon: could not create the attachment' );
			return;
		}

		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $upload['file'] )
		);

		update_option( 'site_icon', $attachment_id );

		$this->importer->log( sprintf( 'site icon #%d', $attachment_id ) );
	}

	/**
	 * Create or update a page, found by the meta key we control.
	 *
	 * Not by slug: WordPress appends -2 when a slug collides, so a lookup
	 * that misses silently produces a duplicate rather than an error.
	 *
	 * The definition arrives as the array from pages() rather than as a list
	 * of arguments. Seven positional booleans and strings had already become
	 * a row of `false, false, '', ''` at most call sites, which is the point
	 * at which the next one gets passed in the wrong slot.
	 *
	 * @param array<string,mixed> $page One entry from pages().
	 */
	private function upsert_page( string $slug, array $page ): int {

		$title    = (string) $page['title'];
		$content  = (string) $page['content'];
		$noindex  = ! empty( $page['noindex'] );
		$full     = ! empty( $page['full'] );
		$wide     = ! empty( $page['wide'] );
		$headline = (string) ( $page['headline'] ?? '' );
		$lead     = (string) ( $page['lead'] ?? '' );

		$existing = get_posts(
			[
				'post_type'              => 'page',
				'post_status'            => [ 'publish', 'draft', 'pending', 'private', 'trash' ],
				'meta_key'               => '_tdh_seed_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'             => $slug,           // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			]
		);

		$args = [
			'post_type'    => 'page',
			'post_name'    => $slug,
			'post_title'   => $title,
			'post_status'  => 'publish',
			'post_content' => $content,
		];

		if ( $existing ) {
			$args['ID'] = $existing[0]->ID;
			$id         = wp_update_post( $args, true );
		} else {
			$id = wp_insert_post( $args, true );
		}

		if ( is_wp_error( $id ) ) {
			$this->importer->warn( $title . ': ' . $id->get_error_message() );
			return 0;
		}

		update_post_meta( (int) $id, '_tdh_seed_key', $slug );

		if ( $noindex ) {
			update_post_meta( (int) $id, '_tdh_noindex', 1 );
		} else {
			delete_post_meta( (int) $id, '_tdh_noindex' );
		}

		// The page renders its own heading and container, so the theme
		// skips the site name and page title it would normally print above
		// the content. Without this the pricing page shows "Membership"
		// directly above the pricing block's own headline.
		if ( $full ) {
			update_post_meta( (int) $id, '_tdh_full_layout', 1 );
		} else {
			delete_post_meta( (int) $id, '_tdh_full_layout' );
		}

		/*
		 * The third layout. `full` gives a page the whole canvas and no
		 * banner; the default wraps the content in the narrow prose column.
		 * `wide` sits between them: the page keeps the banner every inner
		 * page opens on, and its content is released from the 1040px prose
		 * column so a block can run edge to edge. About needs it — its bands
		 * carry their own grounds, and a tinted band inside a padded column
		 * is a grey rectangle floating in white.
		 */
		if ( $wide ) {
			update_post_meta( (int) $id, '_tdh_wide_body', 1 );
		} else {
			delete_post_meta( (int) $id, '_tdh_wide_body' );
		}

		/*
		 * A page can carry its own banner headline and lead. Without them
		 * the banner falls back to the page title, which is a label — "About"
		 * — where the approved design has a sentence: "A monthly home should
		 * still feel like home." Keeping them separate means the menu and the
		 * browser tab stay short while the page itself opens properly.
		 */
		foreach ( [ '_tdh_headline' => $headline, '_tdh_lead' => $lead ] as $key => $value ) {
			if ( '' !== $value ) {
				update_post_meta( (int) $id, $key, $value );
			} else {
				delete_post_meta( (int) $id, $key );
			}
		}

		return (int) $id;
	}

	/**
	 * @return array<string,array{title:string,content:string}>
	 */
	private function pages(): array {

		$draft = '<p><em>' . esc_html__( 'Draft copy, to be reviewed and approved before launch.', 'thirtydayhomes' ) . '</em></p>';

		return [
			'home' => [
				'title'   => __( 'Home', 'thirtydayhomes' ),
				// Shortcodes, so the page renders correctly even with
				// Elementor deactivated. The Elementor layout step
				// overlays a visual version on top of this.
				'content' => "[tdh_hero_search]\n\n[tdh_audience]\n\n[tdh_property_grid count=\"3\" columns=\"3\" eyebrow=\"Explore Pittsburgh\" heading=\"Homes ready when you are\" show_link=\"yes\"]\n\n[tdh_split_feature]\n\n[tdh_owner_cta]",
			],
			'how-it-works' => [
				'title'    => __( 'How it works', 'thirtydayhomes' ),

				// Both audiences in four words, because this is the one page
				// a renter and an owner are equally likely to open.
				'headline' => __( 'Find a home, or fill one.', 'thirtydayhomes' ),
				'lead'     => __( 'The whole process, both sides of it.', 'thirtydayhomes' ),

				// One block. The copy lives in Render::how_it_works(), where
				// the two tracks and the questions are structured data rather
				// than a wall of headings someone can break by editing.
				'content'  => '[tdh_how_it_works]',
				'wide'     => true,
			],
			'pricing' => [
				'title'   => __( 'Membership', 'thirtydayhomes' ),
				// The plans, their prices and the copy all come from
				// TDH\Render::plans(), so confirming a price is one edit
				// there rather than a hunt through page content.
				'content' => '[tdh_pricing]',
				'full'    => true,
			],
			'about' => [
				'title'    => __( 'About', 'thirtydayhomes' ),

				// The headline goes in the banner; the menu and the browser
				// tab keep the one-word title.
				'headline' => __( 'A monthly home should still feel like home.', 'thirtydayhomes' ),

				/*
				 * Short on purpose. The banner lead is capped at a reading
				 * measure and centred, so anything past roughly one line
				 * breaks into two ragged centred lines under a large serif
				 * heading — which is exactly how this page looked when the
				 * 168-character statement sat here. That statement now opens
				 * the body, where it has the room it wants.
				 */
				'lead'     => __( 'Verified furnished homes, starting in Pittsburgh.', 'thirtydayhomes' ),

				// The body is one block, and the draft notice is part of it.
				// Its copy lives in Render::about().
				'content'  => '[tdh_about]',
				'wide'     => true,
			],
			'contact' => [
				'title'   => __( 'Contact', 'thirtydayhomes' ),
				'content' => '<p>Questions about a home, membership, or an urgent stay? Get in touch and we will respond within one business day.</p>' . $draft,
			],
			'terms' => [
				'title'   => __( 'Terms of Use', 'thirtydayhomes' ),
				'content' => '<p><strong>Placeholder.</strong> Final wording is supplied by the owner’s attorney before launch. This page exists so the structure, navigation and footer links are complete and testable.</p>',
			],
			'privacy' => [
				'title'   => __( 'Privacy Policy', 'thirtydayhomes' ),
				'content' => '<p><strong>Placeholder.</strong> Final wording is supplied by the owner’s attorney before launch.</p>'
					. '<p>It must cover what the site collects, how enquiry data reaches landlords, and — once the site sends text messages — SMS consent, message frequency, opt-out and data retention.</p>',
			],
			'fair-housing' => [
				'title'   => __( 'Fair Housing', 'thirtydayhomes' ),
				'content' => '<p>ThirtyDayHomes supports equal access to housing.</p>'
					. '<p>Listings must describe the property, not the ideal renter. Every landlord acknowledges this before a listing is submitted, and listings are reviewed before publication.</p>' . $draft,
			],

			/*
			 * Account screens. Each is one shortcode and nothing else — the
			 * markup is paired with its handler in the plugin, so an editor
			 * cannot accidentally break a form by editing the page.
			 *
			 * noindex: a sign-in screen in search results is worthless to a
			 * searcher and dilutes the pages that do matter. The reset page
			 * additionally carries a token in the URL, which must never be
			 * crawled or logged by a third party.
			 */
			'register' => [
				'title'   => __( 'Create an account', 'thirtydayhomes' ),
				'content' => '[tdh_register]',
				'noindex' => true,
			],
			'login' => [
				'title'   => __( 'Sign in', 'thirtydayhomes' ),
				'content' => '[tdh_login]',
				'noindex' => true,
			],
			'lost-password' => [
				'title'   => __( 'Reset your password', 'thirtydayhomes' ),
				'content' => '[tdh_lost_password]',
				'noindex' => true,
			],
			'reset-password' => [
				'title'   => __( 'Choose a new password', 'thirtydayhomes' ),
				'content' => '[tdh_reset_password]',
				'noindex' => true,
			],
			'account' => [
				'title'   => __( 'Dashboard', 'thirtydayhomes' ),
				'content' => '[tdh_account]',
				'noindex' => true,
			],
			'profile' => [
				'title'   => __( 'Account details', 'thirtydayhomes' ),
				'content' => '[tdh_profile]',
				'noindex' => true,
			],
		];
	}

	/**
	 * @param array<string,int> $ids
	 */
	private function set_front_page( array $ids ): void {

		if ( empty( $ids['home'] ) ) {
			return;
		}

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $ids['home'] );
		update_post_meta( $ids['home'], '_wp_page_template', 'elementor/tpl-full-width.php' );

		if ( ! empty( $ids['privacy'] ) ) {
			update_option( 'wp_page_for_privacy_policy', $ids['privacy'] );
		}

		$this->importer->log( 'front page set' );
	}

	/**
	 * @param array<string,int> $ids
	 */
	private function build_menus( array $ids ): void {

		$homes = get_post_type_archive_link( Post_Types::LISTING ) ?: home_url( '/homes/' );

		$this->upsert_menu(
			__( 'Primary', 'thirtydayhomes' ),
			'primary',
			[
				[ 'page', (string) ( $ids['about'] ?? 0 ),        __( 'About', 'thirtydayhomes' ), '' ],
				[ 'url',  $homes,                                 __( 'Find a home', 'thirtydayhomes' ), '' ],
				[ 'page', (string) ( $ids['how-it-works'] ?? 0 ), __( 'Renter FAQ', 'thirtydayhomes' ), '' ],
				[ 'page', (string) ( $ids['pricing'] ?? 0 ),      __( 'List your property', 'thirtydayhomes' ), '' ],

				// Our own sign-in page, not wp-login.php. A landlord is a
				// customer of this marketplace; sending them to a screen
				// branded "WordPress" to sign in is jarring, and the plugin
				// swaps this item for "Dashboard" once they are signed in.
				[ 'page', (string) ( $ids['login'] ?? 0 ),        __( 'Sign in', 'thirtydayhomes' ), '' ],

				// The gold call to action goes to registration, not pricing.
				// "List your home" is the first step of signing up, and
				// sending it to the plan list made it a duplicate of the
				// "List your property" item directly beside it.
				[ 'page', (string) ( $ids['register'] ?? 0 ),     __( 'List your home', 'thirtydayhomes' ), 'nav-cta' ],
			]
		);

		$this->upsert_menu(
			__( 'Footer', 'thirtydayhomes' ),
			'footer',
			[
				[ 'url',  $homes,                                 __( 'Find a home', 'thirtydayhomes' ), '' ],
				[ 'page', (string) ( $ids['how-it-works'] ?? 0 ), __( 'How it works', 'thirtydayhomes' ), '' ],
				[ 'page', (string) ( $ids['pricing'] ?? 0 ),      __( 'Membership', 'thirtydayhomes' ), '' ],
				[ 'page', (string) ( $ids['about'] ?? 0 ),        __( 'About', 'thirtydayhomes' ), '' ],
				[ 'page', (string) ( $ids['contact'] ?? 0 ),      __( 'Contact', 'thirtydayhomes' ), '' ],
				[ 'page', (string) ( $ids['terms'] ?? 0 ),        __( 'Terms', 'thirtydayhomes' ), '' ],
				[ 'page', (string) ( $ids['privacy'] ?? 0 ),      __( 'Privacy', 'thirtydayhomes' ), '' ],
				[ 'page', (string) ( $ids['fair-housing'] ?? 0 ), __( 'Fair Housing', 'thirtydayhomes' ), '' ],
			]
		);
	}

	/**
	 * Rebuild a menu from scratch.
	 *
	 * Deleting and recreating rather than diffing: re-running must never
	 * leave a menu with every item listed twice, and a menu is cheap to
	 * rebuild. The cost is that a client's manual reordering is lost on
	 * re-import, which is why the admin screen says so before you click.
	 *
	 * @param array<int,array{0:string,1:string,2:string,3:string}> $items
	 */
	private function upsert_menu( string $name, string $location, array $items ): void {

		$existing = wp_get_nav_menu_object( $name );

		if ( $existing ) {
			wp_delete_nav_menu( $existing->term_id );
		}

		$menu_id = wp_create_nav_menu( $name );

		if ( is_wp_error( $menu_id ) ) {
			$this->importer->warn( $name . ': ' . $menu_id->get_error_message() );
			return;
		}

		foreach ( $items as [ $type, $value, $label, $class ] ) {

			$args = [
				'menu-item-title'   => $label,
				'menu-item-status'  => 'publish',
				'menu-item-classes' => $class,
			];

			if ( 'page' === $type ) {
				if ( ! (int) $value ) {
					continue;
				}
				$args['menu-item-object-id'] = (int) $value;
				$args['menu-item-object']    = 'page';
				$args['menu-item-type']      = 'post_type';
			} else {
				$args['menu-item-url']  = $value;
				$args['menu-item-type'] = 'custom';
			}

			wp_update_nav_menu_item( $menu_id, 0, $args );
		}

		$locations              = get_theme_mod( 'nav_menu_locations', [] );
		$locations[ $location ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );

		$this->importer->log( sprintf( 'menu #%d %s → %s', $menu_id, $name, $location ) );
	}

	/**
	 * Clear WordPress's own sample content.
	 */
	private function remove_sample_content(): void {

		foreach ( [ 'hello-world' => 'post', 'sample-page' => 'page' ] as $slug => $type ) {

			$sample = get_page_by_path( $slug, OBJECT, $type );

			if ( $sample ) {
				wp_delete_post( $sample->ID, true );
				$this->importer->log( "removed WordPress sample {$type}" );
			}
		}
	}
}
