<?php
/**
 * Hardening.
 *
 * Small, uncontroversial defaults only. Anything larger belongs in a
 * security plugin the client owns and can see in their plugin list —
 * security that is invisible to the owner is security they cannot audit
 * or replace.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// Do not advertise the WordPress version in markup or feeds.
remove_action( 'wp_head', 'wp_generator' );

// XML-RPC is unused by this build and is a standing brute-force surface.
add_filter( 'xmlrpc_enabled', '__return_false' );

/**
 * Do not reveal whether a username exists on a failed login.
 *
 * The default messages distinguish "unknown username" from "wrong
 * password", which hands an attacker a free user-enumeration oracle.
 */
function tdh_generic_login_error(): string {
	return __( 'The username or password you entered is not correct.', 'thirtydayhomes' );
}
add_filter( 'login_errors', 'tdh_generic_login_error' );
