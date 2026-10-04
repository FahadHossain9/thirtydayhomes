<?php
/**
 * Plugin orchestrator.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the modules together. Holds no business logic of its own —
 * each concern lives in its own class so it can be tested and replaced.
 */
final class Core {

	private static ?Core $instance = null;

	/** @var array<string,object> Loaded modules, keyed by short name. */
	private array $modules = [];

	private bool $booted = false;

	private function __construct() {}

	public static function instance(): Core {
		return self::$instance ??= new self();
	}

	/**
	 * Load every module. Safe to call more than once.
	 */
	public function init(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		/**
		 * Fires before ThirtyDayHomes registers anything.
		 *
		 * @param Core $core The plugin orchestrator.
		 */
		do_action( 'tdh_before_init', $this );

		$this->modules = [
			'post_types' => new Post_Types(),
			'statuses'   => new Statuses(),
			'fields'     => new Fields(),
			'roles'      => new Roles(),
			'visibility' => new Visibility(),
			'proximity'  => new Proximity(),
			'accounts'   => new Accounts(),
			'shortcodes' => new Shortcodes(),

			// Registered before anything that sends: the From filters have to
			// be in place by the time the first wp_mail() runs, and a
			// registration email goes out during the request that creates the
			// account.
			'mail'       => new Mail(),

			// The transport, and NOT admin-only: the messages that matter most
			// are sent from the front end — a contact message, a registration,
			// a password reset — so the SMTP configuration has to be in place
			// on exactly the requests no administrator is present for.
			'smtp'       => new Smtp(),

			// NOT admin-only. Stripe posts to the REST API as nobody, so
			// registering this behind is_admin() would mean the endpoint did
			// not exist for the only caller that ever uses it.
			'webhook'    => new Billing\Webhook(),

			// Front end: the pricing page posts to it.
			'checkout'   => new Billing\Checkout(),

			// Front end: active members launch Stripe's hosted billing portal.
			'customer_portal' => new Billing\Customer_Portal(),

			// Front end: the contact page posts to itself, and the handler
			// runs on template_redirect before any output.
			'contact'    => new Contact(),

			// Front end: a renter's inquiry about one home. Posts to the
			// property page and runs on template_redirect, before output,
			// so the success screen is a redirect and Back cannot re-send.
			'inquiry'    => new Inquiry(),

			// Tells the landlord an inquiry arrived, and writes down every
			// attempt. Listens to tdh_inquiry_received, so it runs only
			// AFTER the inquiry is stored — a mail server that hangs can
			// never cost a renter their message.
			'notifications' => new Notifications(),

			// Texts the landlord too, once they have verified a phone and
			// said yes (D4). Registers nothing at all unless TDH_SMS_ENABLED
			// is set, except the reset that runs when a verified number is
			// edited — that one must never depend on a switch.
			'sms'           => new Sms(),

			// Grace, hide, restore (E1). Listens to tdh_membership_changed,
			// runs a daily catch-up, and tells Visibility which landlords'
			// homes must not be seen.
			'enforcement'   => new Enforcement(),

			// New landlords confirm their email before they pay or submit
			// (F1): the link, "Send a new link", and the checks below read it.
			'email_verification' => new Email_Verification(),

			// Keeps the host's page cache off the account pages. NOT
			// optional and not admin-only: a cached /register/ swallows
			// every validation error, and a cached /account/ serves one
			// landlord's dashboard to the next visitor.
			'no_cache'   => new No_Cache(),

			// The three legal pages as a visitor should read them: bare URLs
			// as links, the editor's notes for staff only (G3b).
			'legal_pages' => new Legal_Pages(),

			// Listing view counts, via a REST beacon because a cache hit is
			// a view PHP never hears about. Front end: the viewers being
			// counted are logged-out renters.
			'views'      => new Views(),

			// The landlord's create-a-listing wizard (Milestone 2). Front
			// end: it posts from the add-listing page, never from wp-admin.
			'listing_form' => new Listing_Form(),

			// The event and failure log. Listens to the actions the other
			// modules fire and writes the sentence; staff read it on the
			// portal's Logs screen. Registered before the modules it listens
			// to only for reading order — hooks are bound before any fires.
			'log'          => new Log(),

			// Approve / request changes from the marketplace portal — the
			// owner's daily loop, actionable without wp-admin.
			'moderation'   => new Moderation(),

			// Closes the username-enumeration doors (REST users route,
			// ?author= archives, the authors sitemap) — the Milestone 1
			// security finding. Front end: every door it closes is public.
			'user_privacy' => new User_Privacy(),

			// Keyword search on the listing archive. Front end: it reads a
			// public query variable and narrows the public archive.
			'search'       => new Search(),

			// The bar on a not-yet-public listing page seen by its landlord
			// or staff. Front end: previews are front-end pages.
			'listing_preview' => new Listing_Preview(),

			// Pause, resume and delete from the landlord's dashboard, and the
			// staff email when a home is submitted. Front end: the dashboard
			// and the preview bar post to it.
			'listing_actions' => new Listing_Actions(),

			// Availability saved from the panel on My listings. Front end: the
			// dashboard posts to it.
			'availability'    => new Availability(),

			// Addresses become coordinates wherever they are saved — the
			// listing form, the Facilities screen, wp-admin, an import — and
			// staff set a location by hand from the Listings screen.
			'geocoder'        => new Geocoder(),
		];

		// `wp tdh geocode --missing`: look up every home and facility still
		// waiting for a location (after the maps key is first added, say).
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'tdh geocode', Geocode_Command::class );
		}

		// Admin-only modules. Loading the editing UI on every front-end
		// request would be pure overhead on the pages renters actually hit.
		if ( is_admin() ) {
			$this->modules['meta_boxes'] = new Admin\Meta_Boxes();
			$this->modules['reference']  = new Admin\Shortcode_Reference();
			$this->modules['importer']   = new Admin\Demo_Importer();

			// The credential screen is admin-only. TDH\Billing\Stripe, which
			// reads those credentials, is a static accessor with no hooks —
			// checkout and the webhook load it themselves on the front end.
			$this->modules['payments'] = new Billing\Settings();

			// Reads and writes the SMTP settings TDH\Smtp sends with. Admin
			// only: it is a screen, and the sender itself is registered above.
			$this->modules['email'] = new Admin\Mail_Settings();

			// The security baseline, for everyone without a terminal. Its
			// one instruction is "run this again after any change", which
			// was useless while it needed SSH.
			$this->modules['security'] = new Admin\Security_Screen();

			// The event and failure log, read. Beside Email delivery and
			// Payments and not in the client's portal: it is ours, not
			// theirs.
			$this->modules['log_screen'] = new Admin\Log_Screen();

			// Says "this page is built with Elementor" to anyone who opened
			// the block editor on one of our layout pages and found a lone
			// shortcode staring back.
			$this->modules['editor_hint'] = new Admin\Editor_Hint();

			// The Listings table for the site administrator: status, landlord,
			// rent and map point in the columns; staff without manage_options
			// sent to the portal; Elementor's opt-in notice quieted.
			$this->modules['listing_table'] = new Admin\Listing_Table();
		}

		// Client review mode. Registers nothing unless TDH_DEMO_MODE is on
		// AND the environment is not production — see the class docblock.
		$this->modules['demo'] = new Demo\Demo_Mode();

		// Elementor widgets. Thin wrappers over the same TDH\Render the
		// shortcodes use — shortcode for portability, widget so each block
		// is a discrete, named node in the Elementor structure tree.
		if ( Elementor\Registrar::is_active() ) {
			$this->modules['elementor'] = new Elementor\Registrar();
		}

		foreach ( $this->modules as $module ) {
			if ( method_exists( $module, 'register' ) ) {
				$module->register();
			}
		}

		add_action( 'init', [ $this, 'load_textdomain' ] );

		// On `init`, not `admin_init`: a deploy is followed by front-end
		// visits and WP-CLI runs long before anyone opens wp-admin, and an
		// upgrade step that changes what visitors see (0.24.7 renames menu
		// items and clears sample meta) must not wait for that. The check
		// is one autoloaded option compare.
		add_action( 'init', [ $this, 'maybe_upgrade' ], 5 );

		/**
		 * Fires once every ThirtyDayHomes module has registered its hooks.
		 *
		 * @param Core $core The plugin orchestrator.
		 */
		do_action( 'tdh_init', $this );
	}

	/**
	 * Fetch a loaded module.
	 */
	public function module( string $name ): ?object {
		return $this->modules[ $name ] ?? null;
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'thirtydayhomes',
			false,
			dirname( plugin_basename( TDH_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Run schema upgrades when the stored version is behind the code.
	 *
	 * Activation hooks do not fire on plugin *update*, so this is the
	 * only reliable place to migrate tables and capabilities.
	 */
	public function maybe_upgrade(): void {
		$stored = get_option( 'tdh_db_version', '0' );

		if ( version_compare( $stored, TDH_VERSION, '>=' ) ) {
			return;
		}

		Activator::install_tables();
		Activator::register_roles();
		flush_rewrite_rules();

		if ( version_compare( $stored, '0.24.7', '<' ) ) {
			self::upgrade_design_system();
		}

		update_option( 'tdh_db_version', TDH_VERSION );
	}

	/**
	 * G2 design review, batch 1 (0.24.7).
	 *
	 * Two content changes that tokens and templates cannot make on their
	 * own, because both live in the database of every site:
	 *
	 *  - The primary menu's "Renter FAQ" becomes "How it works" (the name
	 *    the footer already uses for the same page) and "List your
	 *    property" becomes "Pricing", so it no longer reads as a twin of
	 *    the "List your home" button beside it. Only a title that still
	 *    says exactly what the importer wrote is touched; a title the
	 *    client has edited is left alone.
	 *  - The card never prints a rating again, so `_tdh_rating` goes, and
	 *    the two subjective sample badges ("Guest favorite", "Top
	 *    location") are cleared. There is no review system, so both were
	 *    invented social proof. Every value removed is written to one
	 *    option first, so the change can be undone from the database.
	 */
	private static function upgrade_design_system(): void {

		$renames   = [ 'Renter FAQ' => 'How it works', 'List your property' => 'Pricing' ];
		$locations = get_nav_menu_locations();
		$menu_id   = (int) ( $locations['primary'] ?? 0 );

		if ( $menu_id > 0 ) {
			foreach ( (array) wp_get_nav_menu_items( $menu_id, [ 'update_post_term_cache' => false ] ) as $item ) {
				$title = trim( (string) ( $item->title ?? '' ) );

				if ( isset( $renames[ $title ] ) ) {
					wp_update_post( [ 'ID' => (int) $item->ID, 'post_title' => $renames[ $title ] ] );
				}
			}
		}

		$removed = [];
		$ids     = get_posts(
			[
				'post_type'      => Post_Types::LISTING,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		foreach ( $ids as $id ) {
			$id     = (int) $id;
			$rating = get_post_meta( $id, '_tdh_rating', true );
			$badge  = (string) get_post_meta( $id, '_tdh_badge', true );
			$row    = [];

			if ( '' !== (string) $rating ) {
				$row['rating'] = $rating;
				delete_post_meta( $id, '_tdh_rating' );
			}

			if ( in_array( $badge, [ 'Guest favorite', 'Top location' ], true ) ) {
				$row['badge'] = $badge;
				delete_post_meta( $id, '_tdh_badge' );
			}

			if ( $row ) {
				$removed[ $id ] = $row;
			}
		}

		if ( $removed ) {
			update_option( 'tdh_design_v2_removed_meta', [ 'at' => time(), 'items' => $removed ], false );
		}

		if ( class_exists( Log::class ) ) {
			Log::info(
				'system',
				'upgrade',
				sprintf(
					/* translators: %s: number of homes */
					__( 'Design system upgrade: menu labels updated; the rating and sample badges were removed from %s home(s) and saved for restore.', 'thirtydayhomes' ),
					number_format_i18n( count( $removed ) )
				)
			);
		}
	}
}
