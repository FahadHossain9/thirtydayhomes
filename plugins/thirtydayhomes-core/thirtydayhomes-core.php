<?php
/**
 * Plugin Name:       ThirtyDayHomes Core
 * Plugin URI:        https://github.com/FahadHossain9/thirtydayhomes
 * Description:       Marketplace engine for ThirtyDayHomes — listings, facilities, proximity, memberships and inquiries. All marketplace data and business rules live here, never in the theme or in Elementor.
 * Version:           0.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Instaquirk
 * License:           GPL-2.0-or-later
 * Text Domain:       thirtydayhomes
 * Domain Path:       /languages
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

// No direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Architectural contract for this plugin — see DEVELOPMENT_PLAN.md §2.
 *
 * The plugin owns everything that is DATA or BEHAVIOUR:
 *   content types, statuses, fields, tables, roles and capabilities,
 *   membership rules, submission handling, query logic, proximity,
 *   notifications, Elementor widget registration, and admin screens.
 *
 * The theme owns everything that is PRESENTATION and holds no marketplace rules.
 *
 * The test for any new code: if the theme were deleted and replaced tomorrow,
 * would any listing, membership, inquiry, facility or relationship be lost?
 * If yes, it belongs in this plugin.
 */

/*
 * BUMP THIS ON EVERY RELEASE.
 *
 * Core::maybe_upgrade() compares it against the stored tdh_db_version and
 * only then reinstalls tables and re-registers roles. Ship a build with the
 * version unchanged and a live site keeps the OLD capabilities: the code
 * updates, the roles do not, and a landlord silently cannot do something the
 * new code assumes they can.
 */
const VERSION     = '0.2.0';
const PLUGIN_FILE = __FILE__;

define( 'TDH_VERSION', VERSION );
define( 'TDH_PLUGIN_FILE', __FILE__ );
define( 'TDH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TDH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimal PSR-4-ish autoloader for the TDH namespace.
 *
 * TDH\Post_Types  ->  includes/class-tdh-post-types.php
 * TDH\Admin\Queue ->  includes/admin/class-tdh-queue.php
 */
spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( __NAMESPACE__ ) + 1 );
		$parts    = explode( '\\', $relative );
		$class_nm = array_pop( $parts );

		$sub  = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$file = TDH_PLUGIN_DIR . 'includes/' . $sub . 'class-tdh-'
			. str_replace( '_', '-', strtolower( $class_nm ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

// Activation and deactivation must be registered from the main plugin file.
register_activation_hook( __FILE__, [ Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Activator::class, 'deactivate' ] );

/**
 * Boot the plugin.
 *
 * Deliberately on `plugins_loaded` rather than at file scope, so that
 * anything hooking `tdh_before_init` from another plugin can run first.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		Core::instance()->init();
	},
	5
);
