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
		[ 'register', 'login', 'lost-password', 'reset-password', 'account', 'profile', 'add-listing' ],
		true
	);
}

/**
 * Does this page render its own heading and container?
 *
 * Account screens always do. Any other page can opt in with the
 * `_tdh_full_layout` meta the importer sets — the pricing page does,
 * because its shortcode prints its own headline and would otherwise sit
 * under a duplicate "Membership" from the template.
 */
function tdh_is_full_layout_page(): bool {

	if ( tdh_is_account_page() ) {
		return true;
	}

	return is_page() && (bool) get_post_meta( get_queried_object_id(), '_tdh_full_layout', true );
}

/**
 * Is this the landlord portal — a page that takes over the whole viewport?
 *
 * The dashboard is drawn as a portal in the approved design: its own navy
 * sidebar, its own top bar, and NO site header or footer around it — the
 * sidebar's "Public website" link is the way back out. Matched on the seed
 * key like everything else here, so renaming the page keeps the layout.
 *
 * The dashboard and the listing wizard. The other account screens keep the
 * site chrome: someone signing in has not entered the portal yet, and taking
 * the header away from a visitor who is still deciding whether to sign up
 * removes their navigation, not their distraction. The wizard is inside the
 * portal in the approved design — its back arrow returns to the dashboard —
 * and a logged-out visitor on its URL sees the sign-in gate WITH the chrome,
 * because the logged-in check below fails.
 */
function tdh_is_portal_page(): bool {

	if ( ! is_page() || ! is_user_logged_in() ) {
		return false;
	}

	return in_array(
		(string) get_post_meta( get_queried_object_id(), '_tdh_seed_key', true ),
		[ 'account', 'add-listing' ],
		true
	);
}

/**
 * Marks the portal on <body>, so the stylesheet can hide the site chrome
 * without a template fork.
 */
add_filter(
	'body_class',
	static function ( array $classes ): array {

		if ( tdh_is_portal_page() ) {
			$classes[] = 'tdh-portal-page';
		}

		return $classes;
	}
);

/**
 * No WordPress toolbar on portal pages — for anyone.
 *
 * The portal is a full-viewport app with its own top bar, and every link
 * on the toolbar (Dashboard, Listings, Edit Page) leads into wp-admin —
 * the exact trapdoor the portal exists to close for the client. The
 * developer's way in is the sidebar's "WordPress dashboard" link, and the
 * toolbar is untouched everywhere else on the site.
 */
add_filter(
	'show_admin_bar',
	static function ( $show ) {
		return tdh_is_portal_page() ? false : $show;
	},
	20
);

/**
 * Does this page render its own bands, but still want the banner?
 *
 * The middle setting between a full-layout page and an ordinary one. The
 * page keeps the banner that opens every inner page, and its content is
 * released from the narrow prose column so a block can run edge to edge.
 * About uses it: its sections carry their own backgrounds, and a tinted
 * band inside a 1040px column is a grey rectangle floating in white.
 */
function tdh_is_wide_body_page(): bool {
	return is_page() && (bool) get_post_meta( get_queried_object_id(), '_tdh_wide_body', true );
}
