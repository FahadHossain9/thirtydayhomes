<?php
/**
 * Account link helpers.
 *
 * Thin wrappers only. The pages, the forms and every rule about them live
 * in the plugin — this exists so templates can link to an account screen
 * without knowing how it is found, and so the theme still renders if the
 * plugin is ever deactivated.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * URL of an account page.
 *
 * Falls back to the home page rather than an empty href: a link to "" is a
 * link to the current page, which looks like a broken control rather than
 * an absent feature.
 *
 * @param string $slug One of register, login, lost-password, account, profile.
 */
function tdh_account_url( string $slug ): string {

	if ( class_exists( '\TDH\Accounts' ) ) {
		return \TDH\Accounts::url( $slug );
	}

	return home_url( '/' );
}

/**
 * Whether the current visitor is a landlord.
 *
 * Used for presentation choices only. Never for access control — that is
 * the plugin's capability map, which a template cannot forget to call.
 */
function tdh_is_landlord(): bool {

	if ( class_exists( '\TDH\Accounts' ) ) {
		return \TDH\Accounts::is_landlord();
	}

	return false;
}

/**
 * Is this one of the account screens?
 *
 * All six take over the whole page. The standard page template prints the
 * site name and the page title above the content, which put "Dashboard"
 * directly above the dashboard's own "Welcome, …", and "Create an account"
 * above "Create your account" — two headings saying the same thing.
 *
 * Matched on the meta key the importer sets, not the slug, so renaming the
 * page in wp-admin does not quietly drop it back to the plain layout.
 */
function tdh_is_account_page(): bool {

	if ( ! is_page() ) {
		return false;
	}

	$key = (string) get_post_meta( get_queried_object_id(), '_tdh_seed_key', true );

	return in_array(
		$key,
		[ 'register', 'login', 'lost-password', 'reset-password', 'account', 'profile' ],
		true
	);
}
