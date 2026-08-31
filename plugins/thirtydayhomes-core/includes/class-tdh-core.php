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

			// Front end: the contact page posts to itself, and the handler
			// runs on template_redirect before any output.
			'contact'    => new Contact(),

			// Keeps the host's page cache off the account pages. NOT
			// optional and not admin-only: a cached /register/ swallows
			// every validation error, and a cached /account/ serves one
			// landlord's dashboard to the next visitor.
			'no_cache'   => new No_Cache(),
		];

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
		add_action( 'admin_init', [ $this, 'maybe_upgrade' ] );

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

		update_option( 'tdh_db_version', TDH_VERSION );
	}
}
