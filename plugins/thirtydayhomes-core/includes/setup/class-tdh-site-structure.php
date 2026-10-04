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

	/**
	 * A fingerprint of the content this importer last wrote to a page.
	 *
	 * ─── WHY THIS EXISTS ───────────────────────────────────────────────────
	 *
	 * The importer used to overwrite every page and rebuild both menus on
	 * every run, which made it powerful and unsafe: re-running it discarded
	 * whatever anyone had edited since. So the rule became "never run this on
	 * a live site", and that rule then caused its own bug — a page's content
	 * is a shortcode, deploys copy code but not database rows, and live sat
	 * on last week's markup while running this week's code with no safe way
	 * to reconcile it.
	 *
	 * The fingerprint resolves both. The importer may rewrite anything that
	 * still hashes to what it last wrote — that is its own output, and
	 * replacing it changes nothing a person chose. The moment the hash
	 * differs, somebody has edited that page, and it is left alone and
	 * reported.
	 *
	 * The result is a tool that is safe to run after every deploy, which is
	 * the only way page content and code stay in step.
	 */
	private const META_FINGERPRINT = '_tdh_seed_fingerprint';

	/** The same idea for a menu, stored per location. */
	private const OPTION_MENU_FINGERPRINT = 'tdh_menu_fingerprint_';

	/** @var array<int,string> Pages and menus left alone because they were edited. */
	private array $protected = [];

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

		$this->seed_vocabularies();
		$this->set_front_page( $ids );
		$this->build_menus( $ids );
		$this->remove_sample_content();
		$this->set_site_icon();

		// City-neutral: the tagline appears in search results and on every
		// page, and the site lists in more than one market.
		update_option( 'blogdescription', __( 'Furnished 30+ day homes near the places you need to be', 'thirtydayhomes' ) );

		/*
		 * Say plainly what was left alone. A run that protects something and
		 * reports nothing looks like a run that quietly did not work, and
		 * whoever is watching would run it again — or worse, start deleting
		 * things to force it through.
		 */
		if ( $this->protected ) {
			$this->importer->log(
				sprintf(
					'left alone because they have been edited: %s',
					implode( ', ', $this->protected )
				)
			);
			$this->importer->log( 'to let the importer manage one of those again, revert it in the editor or delete it and re-run' );
		}
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
			'post_type'   => 'page',
			'post_name'   => $slug,
			'post_title'  => $title,
			'post_status' => 'publish',
		];

		/*
		 * Has anybody edited this page since we wrote it?
		 *
		 * A page with no fingerprint predates this check, so it is adopted:
		 * that keeps the first run after this change behaving exactly as it
		 * always did, and every run after it protected.
		 */
		$edited = false;

		if ( $existing ) {

			$stored = (string) get_post_meta( $existing[0]->ID, self::META_FINGERPRINT, true );

			if ( '' !== $stored && ! hash_equals( $stored, md5( (string) $existing[0]->post_content ) ) ) {
				$edited = true;
			}
		}

		if ( ! $edited ) {
			$args['post_content'] = $content;
		}

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

		/*
		 * Record what we wrote — but only when we actually wrote it. Storing
		 * a fingerprint of somebody else's edit would adopt that edit as our
		 * own, and the next run would overwrite it: the exact loss this is
		 * meant to prevent.
		 *
		 * The metadata below is NOT protected, deliberately. Headline, lead,
		 * layout and noindex are ours: they do not appear in the editor, so a
		 * person cannot have meant to change them, and keeping them in step
		 * with the code is the point of re-running.
		 */
		if ( $edited ) {
			$this->protected[] = sprintf( '%s (edited since import)', $slug );
		} else {
			update_post_meta( (int) $id, self::META_FINGERPRINT, md5( $content ) );
		}

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

		/*
		 * The three legal pages all carry a note saying what is not final
		 * yet. They share one treatment — a bordered panel, .page-note —
		 * so a reader can tell our editorial aside from the policy itself
		 * at a glance, and so the pages look like a set rather than three
		 * different stages of unfinished.
		 */
		$draft = '<div class="page-note"><p>'
			. esc_html__( 'Draft copy, to be reviewed and approved before launch.', 'thirtydayhomes' )
			. '</p></div>';

		return [
			'home' => [
				'title'   => __( 'Home', 'thirtydayhomes' ),
				// Shortcodes, so the page renders correctly even with
				// Elementor deactivated. The Elementor layout step
				// overlays a visual version on top of this.
				'content' => "[tdh_hero_search]\n\n[tdh_audience]\n\n[tdh_property_grid count=\"3\" columns=\"3\" eyebrow=\"Explore homes\" heading=\"Homes ready when you are\" show_link=\"yes\"]\n\n[tdh_split_feature]\n\n[tdh_owner_cta]",
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
				'title'    => __( 'Contact', 'thirtydayhomes' ),
				'headline' => __( 'Talk to a person, not a queue.', 'thirtydayhomes' ),
				'lead'     => __( 'One form, whether you are renting or listing.', 'thirtydayhomes' ),

				// The body is the form. The page previously invited people to
				// "get in touch" and then offered no means of doing so — no
				// form, no address, no number — which asks for something and
				// then refuses to take it.
				'content'  => '[tdh_contact]',
				'wide'     => true,
			],
			/*
			 * "Terms of Service", not "Terms of Use": the carriers' campaign
			 * form accepts a page titled Terms & Conditions or Terms of
			 * Service and asks for an SMS Terms section naming the brand,
			 * "message and data rates may apply", and how to stop (24 Sep
			 * 2026). The SMS section is real; the rest still waits for the
			 * attorney, said in a note BELOW it for the same reason as the
			 * privacy page.
			 */
			'terms' => [
				'title'   => __( 'Terms of Service', 'thirtydayhomes' ),
				'content' => '<h2>SMS terms</h2>'
					. '<p>ThirtyDayHomes (Thirty Day Homes LLC) offers landlords a text-message alert: one text each time a renter sends an inquiry about your property. You choose to receive these texts on your account page by entering your mobile number, ticking the consent box and confirming the number with a one-time code. Consent is not a condition of listing a property or of using the site.</p>'
					// Links, not bare URLs (G3b): the same words the carriers
					// approved, with the two addresses clickable. Pages seeded
					// before this are fixed as they print — see Legal_Pages.
					. '<p>Message frequency varies with the number of inquiries you receive. Message and data rates may apply. Reply STOP to any text to stop receiving them. Reply HELP for help, or contact us at <a href="' . esc_url( home_url( '/contact/' ) ) . '">' . esc_html( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) . '/contact' ) . '</a>. Carriers are not liable for delayed or undelivered messages. Our <a href="' . esc_url( home_url( '/privacy/' ) ) . '">Privacy Policy</a> explains how we handle your number.</p>'
					. '<h2>The rest of these terms</h2>'
					. '<div class="page-note">'
					. '<p><strong>Still to come.</strong> The remaining terms are supplied by the owner’s attorney before launch.</p>'
					. '</div>',
			],
			/*
			 * The text-message section is REAL POLICY, not placeholder.
			 *
			 * Rob approved these exact words on 22 Sep 2026, having been
			 * offered his attorney first and declined, and they are the
			 * clause a carrier looks for before approving the A2P 10DLC
			 * campaign (R46, which blocks R45, which blocks D4). A page
			 * headed "Placeholder" with no mobile-number clause is the
			 * normal reason such a campaign is rejected.
			 *
			 * The wording is his and is not edited here — not tidied, not
			 * made British, not expanded. What it does NOT yet state is
			 * message frequency or data retention, which carriers usually
			 * also want; adding those needs another yes from him and is
			 * recorded against R46.
			 *
			 * The rest of the page stays marked as placeholder, because it
			 * is: only the texting paragraph has been approved.
			 */
			'privacy' => [
				'title'   => __( 'Privacy Policy', 'thirtydayhomes' ),
				'content' => '<h2>Text messages</h2>'
					// The owner's own approved sentence, 22 Sep 2026, used
					// exactly as he wrote it.
					. '<p>We only send text messages about inquiries on your property. We never sell or share phone numbers. Reply STOP to any text and we will stop sending them.</p>'
					// What the carriers' campaign form demands of the privacy
					// page, word for word (24 Sep 2026): the registered brand
					// name, what is collected and how it is used, and the
					// exact no-selling statement. Every clause is true of the
					// code: Sms stores the number, the consent time and the
					// STOP/START state, and nothing else reads them.
					. '<p>These texts come from ThirtyDayHomes (Thirty Day Homes LLC). You choose to receive them on your account page by entering your mobile number, ticking the consent box and confirming the number with a one-time code we text you. We keep the number, the time you consented and any STOP or START reply, and we use them only to send these texts and to stop them when you ask. We do not sell or share your SMS opt-in data or personal information with third parties for marketing purposes.</p>'
					// Frequency, HELP and rates: the three things a carrier
					// looks for that his sentence does not cover. Every claim
					// here is a statement about what the software does, so
					// each one has a check in verify.php holding it true.
					. '<p>You will only get a text when someone sends an inquiry about your property. There is no marketing and nothing on a schedule, so how often you hear from us depends on how many inquiries you receive. Reply HELP to any text for help. Message and data rates may apply.</p>'
					// Retention, worded to what the site actually does. A
					// deleted listing deliberately KEEPS its messages, so
					// "deleted with the listing" would have been untrue.
					. '<p>We keep a phone number for as long as the account or the message it came with is on the site. Ask us to remove yours and we will.</p>'
					. '<h2>The rest of this policy</h2>'
					. '<div class="page-note">'
					. '<p><strong>Still to come.</strong> The remaining sections are supplied by the owner’s attorney before launch.</p>'
					. '<p>They cover what the site collects beyond the above, and how inquiry data reaches landlords.</p>'
					. '</div>',
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
			'add-listing' => [
				'title'   => __( 'List your home', 'thirtydayhomes' ),
				'content' => '[tdh_add_listing]',
				'noindex' => true,
			],
		];
	}

	/**
	 * The property-type vocabulary.
	 *
	 * ─── WHY THIS IS STRUCTURE, NOT SAMPLE CONTENT ─────────────────────────
	 *
	 * These terms used to exist only as a side effect of the demo listings:
	 * the four types the samples happened to use were the four types a
	 * landlord could pick, and removing the sample content removed the
	 * vocabulary with it. That is how "Single Family House" — the most
	 * common rental in the markets this site is opening in — was missing
	 * from the wizard's dropdown when the owner reviewed it.
	 *
	 * A fixed vocabulary for the same reason the amenity catalogue is fixed:
	 * types are a filter renters browse by, and free entry would split
	 * "House", "house" and "Single family" into three filters that each
	 * miss the other's homes.
	 *
	 * @return array<string,string[]> Taxonomy => term names.
	 */
	public static function vocabularies(): array {
		return [
			Post_Types::TAX_TYPE => [
				__( 'Apartment', 'thirtydayhomes' ),
				__( 'Condo', 'thirtydayhomes' ),
				__( 'Duplex', 'thirtydayhomes' ),
				__( 'Guest House', 'thirtydayhomes' ),
				__( 'Loft', 'thirtydayhomes' ),
				__( 'Single Family House', 'thirtydayhomes' ),
				__( 'Studio', 'thirtydayhomes' ),
				__( 'Townhouse', 'thirtydayhomes' ),
			],
		];
	}

	/**
	 * Create any vocabulary term that is missing.
	 *
	 * Additive only. It never renames or deletes, because a client who adds
	 * their own property type must not lose it to the next importer run —
	 * the same rule the page fingerprint enforces for content.
	 */
	private function seed_vocabularies(): void {

		$added = 0;

		foreach ( self::vocabularies() as $taxonomy => $terms ) {

			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			foreach ( $terms as $term ) {

				if ( term_exists( $term, $taxonomy ) ) {
					continue;
				}

				if ( ! is_wp_error( wp_insert_term( $term, $taxonomy ) ) ) {
					++$added;
				}
			}
		}

		$this->importer->log(
			$added > 0
				/* translators: %d: number of terms created */
				? sprintf( __( '%d property type(s) added', 'thirtydayhomes' ), $added )
				: __( 'property types already complete', 'thirtydayhomes' )
		);
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
				// The same names the footer uses for the same pages, and no
				// twin of the "List your home" button beside them (G2).
				[ 'page', (string) ( $ids['how-it-works'] ?? 0 ), __( 'How it works', 'thirtydayhomes' ), '' ],
				[ 'page', (string) ( $ids['pricing'] ?? 0 ),      __( 'Pricing', 'thirtydayhomes' ), '' ],

				// Our own sign-in page, not wp-login.php. A landlord is a
				// customer of this marketplace; sending them to a screen
				// branded "WordPress" to sign in is jarring, and the plugin
				// swaps this item for "Dashboard" once they are signed in.
				[ 'page', (string) ( $ids['login'] ?? 0 ),        __( 'Sign in', 'thirtydayhomes' ), '' ],

				// The gold call to action goes to registration, not pricing.
				// "List your home" is the first step of signing up, and
				// sending it to the plan list made it a duplicate of the
				// pricing item directly beside it.
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
	 * Rebuild a menu, unless somebody has reordered or edited it.
	 *
	 * Still delete-and-recreate rather than diffing: re-running must never
	 * leave a menu with every item listed twice, and reconciling items one by
	 * one is a great deal of code to get subtly wrong. A menu is cheap to
	 * rebuild — the only real cost was destroying a person's arrangement,
	 * and that is what the fingerprint now prevents.
	 *
	 * Before touching anything, the menu as it stands is fingerprinted and
	 * compared with what this importer last produced. Identical means nobody
	 * has been here and rebuilding changes nothing. Different means somebody
	 * has reordered, renamed or added an item, and their menu is left exactly
	 * as it is.
	 *
	 * @param array<int,array{0:string,1:string,2:string,3:string}> $items
	 */
	private function upsert_menu( string $name, string $location, array $items ): void {

		$existing = wp_get_nav_menu_object( $name );

		if ( $existing ) {

			$stored = (string) get_option( self::OPTION_MENU_FINGERPRINT . $location, '' );

			// No fingerprint means the menu predates this check, so it is
			// adopted — the first run after this change behaves as it always
			// did, and every run after it is protected.
			if ( '' !== $stored && ! hash_equals( $stored, $this->menu_signature( (int) $existing->term_id ) ) ) {

				$this->protected[] = sprintf( '%s menu (edited since import)', $name );
				$this->importer->log( sprintf( 'menu %s left alone — it has been edited', $name ) );

				return;
			}

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

		// Recorded AFTER the items are in, so it fingerprints the finished
		// menu rather than the empty one it started as.
		update_option( self::OPTION_MENU_FINGERPRINT . $location, $this->menu_signature( (int) $menu_id ) );

		$this->importer->log( sprintf( 'menu #%d %s → %s', $menu_id, $name, $location ) );
	}

	/**
	 * A fingerprint of a menu as it currently stands.
	 *
	 * Covers what a person would actually change: which items, in what
	 * order, pointing where, labelled how. Menu item IDs are deliberately
	 * NOT included — they are reassigned every time the menu is rebuilt, so
	 * including them would make every menu look edited immediately after the
	 * importer itself created it.
	 */
	private function menu_signature( int $menu_id ): string {

		$items = wp_get_nav_menu_items( $menu_id, [ 'update_post_term_cache' => false ] );

		if ( ! $items ) {
			return '';
		}

		$parts = [];

		foreach ( $items as $item ) {
			$classes = array_filter( (array) $item->classes );
			sort( $classes );

			$parts[] = implode(
				'|',
				[
					(string) $item->type,
					(string) $item->object_id,
					(string) $item->url,
					(string) $item->title,
					implode( ' ', $classes ),
					(string) $item->menu_item_parent,
				]
			);
		}

		return md5( implode( "\n", $parts ) );
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
