<?php
/**
 * Activation, deactivation and schema installation.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the custom tables and roles the marketplace needs.
 *
 * Deactivation deliberately removes NOTHING. Uninstalling a plugin by
 * accident must never cost a client their listings, members or inquiries.
 * Data removal belongs in an explicit uninstall routine, gated behind a
 * conscious opt-in.
 */
final class Activator {

	public static function activate(): void {
		self::install_tables();
		self::register_roles();

		add_option( 'tdh_db_version', TDH_VERSION );
		update_option( 'tdh_db_version', TDH_VERSION );

		// Post types are not registered yet at activation time, so register
		// them now to get their rewrite rules into the flush below.
		( new Post_Types() )->register_post_types();
		( new Statuses() )->register_statuses();

		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		// Only clear rewrite rules. Never touch data.
		flush_rewrite_rules();
	}

	/**
	 * Custom tables.
	 *
	 * Both are real tables rather than post meta, for reasons documented
	 * in DEVELOPMENT_PLAN.md §3.5:
	 *
	 * - distances: fifteen facilities x hundreds of listings, sorted on
	 *   every search. Post meta would be slow enough for a renter to notice.
	 * - notifications: a delivery log is a log, not an attribute of a post.
	 */
	public static function install_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$distances = $wpdb->prefix . 'tdh_distances';
		$sql       = "CREATE TABLE {$distances} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			listing_id   BIGINT UNSIGNED NOT NULL,
			facility_id  BIGINT UNSIGNED NOT NULL,
			miles        DECIMAL(6,2)    NOT NULL,
			drive_minutes SMALLINT UNSIGNED DEFAULT NULL,
			computed_at  DATETIME        NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY listing_facility (listing_id, facility_id),
			KEY facility_miles (facility_id, miles),
			KEY listing_miles (listing_id, miles)
		) {$charset};";
		dbDelta( $sql );

		$notifications = $wpdb->prefix . 'tdh_notifications';
		$sql           = "CREATE TABLE {$notifications} (
			id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			inquiry_id          BIGINT UNSIGNED NOT NULL,
			channel             VARCHAR(20)     NOT NULL,
			recipient           VARCHAR(191)    NOT NULL,
			provider_message_id VARCHAR(191)    DEFAULT NULL,
			status              VARCHAR(20)     NOT NULL DEFAULT 'queued',
			provider_response   TEXT            DEFAULT NULL,
			attempts            TINYINT UNSIGNED NOT NULL DEFAULT 0,
			created_at          DATETIME        NOT NULL,
			updated_at          DATETIME        NOT NULL,
			PRIMARY KEY  (id),
			KEY inquiry_channel (inquiry_id, channel),
			KEY status_created (status, created_at)
		) {$charset};";
		dbDelta( $sql );
	}

	/**
	 * Table name helpers, so nothing hardcodes a prefix.
	 */
	public static function distances_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tdh_distances';
	}

	public static function notifications_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tdh_notifications';
	}

	/**
	 * Create the landlord role and grant administrators the marketplace caps.
	 */
	public static function register_roles(): void {
		( new Roles() )->install();
	}
}
