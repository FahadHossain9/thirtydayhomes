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
			'shortcodes' => new Shortcodes(),
		];

		// Admin-only modules. Loading the editing UI on every front-end
		// request would be pure overhead on the pages renters actually hit.
		if ( is_admin() ) {
			$this->modules['meta_boxes'] = new Admin\Meta_Boxes();
			$this->modules['reference']  = new Admin\Shortcode_Reference();
			$this->modules['importer']   = new Admin\Demo_Importer();
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
