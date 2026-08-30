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
