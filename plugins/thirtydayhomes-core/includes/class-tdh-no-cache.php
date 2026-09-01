<?php
/**
 * Pages a page cache must never serve.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the page cache off anything personal or transactional.
 *
 * ─── THE BUG THIS EXISTS BECAUSE OF ────────────────────────────────────────
 *
 * On the live host, registration failed silently. Submitting the form with a
 * bad address produced no account, no error, nothing — twice, for a real
 * person testing it. The PHP was correct the whole time:
 *
 *   POST /register/          302, error stashed, notice cookie minted   ✅
 *   GET  /register/          x-litespeed-cache: hit                     ❌
 *
 * The redirect landed on a CACHED copy of the page. PHP never ran, so
 * Accounts::take_notice() never ran, so the error was never printed — and
 * the stash was left sitting in a transient nobody read. Every account form
 * on the site fails the same way: register, sign in, lost password, reset
 * password, and the contact form.
 *
 * Worse than the missing message: the cached page carries a nonce, and every
 * visitor was being handed the same one. Once that copy ages past the nonce
 * lifetime, every submission on the site is rejected as expired, for
 * everybody, until something happens to purge the cache.
 *
 * ─── WHY IT IS HERE AND NOT IN THE HOST'S CACHE SETTINGS ───────────────────
 *
 * It could be done by typing six paths into a LiteSpeed exclusion box. That
 * setting lives in one server's database, survives no rebuild, is invisible
 * in code review, and nobody would ever find out it had been lost — the
 * symptom is a form that quietly stops reporting errors.
 *
 * The plugin is the thing that KNOWS which pages hold personal data and
 * which carry a stash across a redirect. So it says so itself, on every
 * request, and the rule travels with the deploy.
 *
 * ─── AND WHY IT MATTERS MORE THAN A MISSING ERROR MESSAGE ──────────────────
 *
 * A cached /account/ or /profile/ is one landlord's dashboard served to the
 * next visitor. The headers below are sent for logged-in requests too, for
 * exactly that reason.
 */
final class No_Cache {

	/**
	 * Seeded pages that must never be cached.
	 *
	 * Matched on the importer's `_tdh_seed_key`, not on the slug: a client
	 * who renames "Sign in" to "Landlord login" must not silently lose the
	 * protection. TDH\Accounts::url() resolves pages the same way.
	 */
	private const PRIVATE_KEYS = [
		'login',
		'register',
		'lost-password',
		'reset-password',
		'account',
		'profile',

		// Not personal, but it stashes what was typed across a redirect and
		// prints validation errors — so a cached copy loses both.
		'contact',

		// Posts to Stripe and renders the member's own plan state.
		'pricing',

		// The listing wizard: draft contents, validation errors and a nonce,
		// all belonging to one signed-in landlord.
		'add-listing',
	];

	public function register(): void {

		/*
		 * send_headers fires on every front-end request, before any output
		 * and before a cache plugin decides what to store. template_redirect
		 * would be too late for some of them.
		 */
		add_action( 'send_headers', [ $this, 'maybe_no_cache' ] );

		// LiteSpeed asks plugins directly, and honours this over its own
		// URI rules.
		add_filter( 'litespeed_control_cacheable', [ $this, 'litespeed_cacheable' ] );
	}

	/**
	 * Should this request be kept out of the cache?
	 */
	public static function is_private_request(): bool {

		if ( is_admin() ) {
			return false; // Never cached anyway.
		}

		// Anyone signed in. Their page is theirs.
		if ( is_user_logged_in() ) {
			return true;
		}

		/*
		 * Carrying a stashed notice. This is the general rule the specific
		 * page list cannot express: whatever page a form redirects BACK to,
		 * that response is personal to this visitor for the next few minutes
		 * and must not be stored for anybody else.
		 */
		if ( ! empty( $_COOKIE[ Accounts::NOTICE_COOKIE ] ) ) {
			return true;
		}

		// A form has just been submitted.
		if ( ! empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return true;
		}

		$queried = get_queried_object();

		if ( ! $queried instanceof \WP_Post ) {
			return false;
		}

		$key = (string) get_post_meta( $queried->ID, '_tdh_seed_key', true );

		return in_array( $key, self::PRIVATE_KEYS, true );
	}

	public function maybe_no_cache(): void {

		if ( ! self::is_private_request() ) {
			return;
		}

		/*
		 * The constant every major WordPress page cache reads — LiteSpeed,
		 * WP Super Cache, W3 Total Cache, WP Rocket. Cheap insurance against
		 * the host changing which one is installed.
		 */
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		if ( headers_sent() ) {
			return;
		}

		// LiteSpeed's own control header, which it honours ahead of its
		// configured URI rules.
		header( 'X-LiteSpeed-Cache-Control: no-cache, no-store' );

		// And the ordinary ones, for Cloudflare and for the browser. The
		// site sits behind Cloudflare as well; cf-cache-status reads DYNAMIC
		// today, and this keeps it that way if someone turns on caching.
		nocache_headers();
	}

	/**
	 * LiteSpeed's own gate.
	 *
	 * @param bool $cacheable What LiteSpeed decided.
	 */
	public function litespeed_cacheable( $cacheable ): bool {
		return self::is_private_request() ? false : (bool) $cacheable;
	}
}
