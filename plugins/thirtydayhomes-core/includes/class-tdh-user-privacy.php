<?php
/**
 * Closes the username-enumeration doors.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * A landlord's login name is half of their credentials, and WordPress
 * hands it out three ways by default: the REST users endpoint lists every
 * author, `?author=1` redirects to an archive URL built from the login
 * name, and the core sitemap publishes an authors file. The Milestone 1
 * security review flagged the first two open on the live site.
 *
 * This closes all three for visitors. Staff keep the REST endpoint —
 * wp-admin's own screens use it.
 */
final class User_Privacy {

	public function register(): void {

		add_filter( 'rest_endpoints', [ $this, 'hide_user_routes' ] );

		// Priority 1: BEFORE redirect_canonical (10), which would otherwise
		// answer ?author=1 with a redirect to /author/{login-name}/ — the
		// leak happening inside the very redirect.
		add_action( 'template_redirect', [ $this, 'block_author_archives' ], 1 );

		add_filter( 'wp_sitemaps_add_provider', [ $this, 'drop_users_sitemap' ], 10, 2 );
	}

	/**
	 * The REST users routes exist only for people who may list users.
	 *
	 * Removed rather than permission-filtered: a 401 on /wp/v2/users still
	 * confirms the route is there and worth authenticating against; a 404
	 * says nothing.
	 *
	 * @param array<string,mixed> $endpoints Registered REST routes.
	 * @return array<string,mixed>
	 */
	public function hide_user_routes( array $endpoints ): array {

		if ( current_user_can( 'list_users' ) ) {
			return $endpoints;
		}

		unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );

		return $endpoints;
	}

	/**
	 * Author archives answer 404 — the same 404 for a real author id and a
	 * made-up one, so probing learns nothing. The theme links no author
	 * archive anywhere; the only visitors these pages have are enumerators.
	 */
	public function block_author_archives(): void {

		global $wp_query;

		$probing = is_author()
			|| '' !== (string) get_query_var( 'author_name' )
			|| 0 !== (int) get_query_var( 'author' );

		if ( ! $probing || Accounts::is_staff() ) {
			return;
		}

		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * No authors file in the sitemap.
	 *
	 * @param mixed  $provider Sitemap provider instance.
	 * @param string $name     Provider name.
	 * @return mixed False for the users provider, the provider otherwise.
	 */
	public function drop_users_sitemap( $provider, string $name ) {
		return 'users' === $name ? false : $provider;
	}
}
