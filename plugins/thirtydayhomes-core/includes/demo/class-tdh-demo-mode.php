<?php
/**
 * Client review mode — the "Viewing as" persona bar.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Demo;

use TDH\Post_Types;
use TDH\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the client walk every role during review without juggling passwords.
 *
 * ─── READ THIS BEFORE CHANGING ANYTHING HERE ───────────────────────────
 *
 * In the React prototype this bar was harmless: nothing was real, so
 * "Viewing as Administrator" changed some local state and no more.
 *
 * Here the roles are REAL WordPress capabilities against a REAL database.
 * A control that logs anyone in as any role is an authentication bypass —
 * on a public staging URL that is a site takeover waiting to happen.
 *
 * So it is gated four ways, and none of them should be loosened:
 *
 *   1. OFF unless TDH_DEMO_MODE is defined true in wp-config.php.
 *   2. HARD REFUSED when the environment type is 'production', even if
 *      the constant is set — because one day someone will copy wp-config
 *      to the live server, and that must not be the moment this matters.
 *   3. Every switch is nonce-checked, so the link cannot be forged and
 *      fired at a logged-in administrator from another site.
 *   4. The "Administrator" persona is a DEMO admin. It holds the
 *      marketplace capabilities the client needs to review — moderating
 *      listings, managing facilities, reading inquiries — and NOT
 *      manage_options, install_plugins, edit_users or edit_files. The
 *      client can run the marketplace; they cannot take over WordPress.
 *
 * Staging should additionally be noindex and behind HTTP auth. That is a
 * hosting task, not something this file can enforce.
 */
final class Demo_Mode {

	private const QUERY_ARG   = 'tdh_persona';
	private const RESET_ARG   = 'tdh_demo_reset';
	private const NONCE       = 'tdh_demo_switch';
	private const USER_FLAG   = '_tdh_demo_persona';

	public function register(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'init', [ $this, 'handle_request' ] );
		add_action( 'wp_body_open', [ $this, 'render_bar' ] );
		add_action( 'wp_head', [ $this, 'styles' ] );

		// The switcher also belongs in wp-admin. Without it, switching to a
		// landlord persona strands the reviewer: a landlord has no
		// switch_themes or activate_plugins capability, so the menu they
		// land on has no way back to Administrator. A review tool that can
		// trap the reviewer is worse than no review tool.
		add_action( 'admin_bar_menu', [ $this, 'admin_bar' ], 90 );

		// Keep review sites out of search results. Belt and braces — the
		// host should also be enforcing this.
		add_filter( 'pre_option_blog_public', '__return_zero' );
	}

	/**
	 * Is review mode available at all?
	 */
	public static function is_enabled(): bool {
		if ( ! defined( 'TDH_DEMO_MODE' ) || ! TDH_DEMO_MODE ) {
			return false;
		}

		// The guard that matters. Never on production, whatever wp-config says.
		if ( 'production' === wp_get_environment_type() ) {
			return false;
		}

		return true;
	}

	/**
	 * The personas, in the order the prototype presented them.
	 *
	 * @return array<string,array{label:string,role:string,note:string}>
	 */
	public static function personas(): array {
		return [
			'renter'   => [
				'label' => __( 'Renter', 'thirtydayhomes' ),
				'role'  => '',
				'note'  => __( 'Signed out. Browses and enquires.', 'thirtydayhomes' ),
			],
			'new'      => [
				'label' => __( 'New landlord', 'thirtydayhomes' ),
				'role'  => Roles::LANDLORD,
				'note'  => __( 'No listings yet.', 'thirtydayhomes' ),
			],
			'landlord' => [
				'label' => __( 'Active landlord', 'thirtydayhomes' ),
				'role'  => Roles::LANDLORD,
				'note'  => __( 'Owns the seeded listings.', 'thirtydayhomes' ),
			],
			'failed'   => [
				'label' => __( 'Failed payment', 'thirtydayhomes' ),
				'role'  => Roles::LANDLORD,
				'note'  => __( 'Billing states are inert until the subscription layer lands.', 'thirtydayhomes' ),
			],
			'admin'    => [
				'label' => __( 'Administrator', 'thirtydayhomes' ),
				'role'  => 'tdh_demo_admin',
				'note'  => __( 'Marketplace administration only — cannot change WordPress settings.', 'thirtydayhomes' ),
			],
		];
	}

	/**
	 * Act on a persona switch or a reset request.
	 */
	public function handle_request(): void {

		$persona = isset( $_GET[ self::QUERY_ARG ] )
			? sanitize_key( wp_unslash( $_GET[ self::QUERY_ARG ] ) )
			: '';

		$reset = isset( $_GET[ self::RESET_ARG ] );

		if ( '' === $persona && ! $reset ) {
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			wp_die( esc_html__( 'That review link has expired. Reload the page and try again.', 'thirtydayhomes' ), 403 );
		}

		if ( $reset ) {
			$this->reset();
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		$this->switch_to( $persona );
	}

	/**
	 * Log in as a persona and land on the screen that shows their world.
	 */
	private function switch_to( string $persona ): void {

		$personas = self::personas();

		if ( ! isset( $personas[ $persona ] ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		// The renter is simply nobody. Signing out is the whole switch.
		if ( '' === $personas[ $persona ]['role'] ) {
			wp_logout();
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		$user = $this->ensure_persona_user( $persona, $personas[ $persona ] );

		if ( ! $user ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		// Drop the previous session server-side, then issue the new cookie.
		//
		// Deliberately NOT wp_clear_auth_cookie() first: that emits an
		// expired Set-Cookie under the same name and path, immediately
		// followed by the real one. Browsers keep the last per RFC 6265,
		// but stricter clients keep the first and the switch silently
		// fails. One cookie per name is simply correct.
		wp_destroy_current_session();
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false );

		/** Fires after a reviewer switches persona. */
		do_action( 'wp_login', $user->user_login, $user );

		$destination = 'admin' === $persona
			? admin_url( 'edit.php?post_type=' . Post_Types::LISTING )
			: admin_url( 'edit.php?post_type=' . Post_Types::LISTING . '&author=' . $user->ID );

		wp_safe_redirect( $destination );
		exit;
	}

	/**
	 * Fetch or create the user behind a persona.
	 *
	 * @param array{label:string,role:string,note:string} $spec
	 */
	private function ensure_persona_user( string $persona, array $spec ): ?\WP_User {

		$login = 'demo_' . $persona;

		/*
		 * Refresh the role BEFORE the existing-user check.
		 *
		 * Previously this sat after the early return, so a persona user
		 * created once never saw a capability added later — the role in the
		 * database stayed frozen at whatever shipped first. Rebuilding on
		 * every switch costs one write per switch and is always correct.
		 */
		if ( 'tdh_demo_admin' === $spec['role'] ) {
			$this->ensure_demo_admin_role();
		}

		$user = get_user_by( 'login', $login );

		if ( $user instanceof \WP_User ) {
			return $user;
		}

		$user_id = wp_insert_user(
			[
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'user_email'   => $login . '@review.invalid',
				'display_name' => $spec['label'],
				'role'         => $spec['role'],
			]
		);

		if ( is_wp_error( $user_id ) ) {
			return null;
		}

		update_user_meta( $user_id, self::USER_FLAG, $persona );

		return get_user_by( 'id', $user_id );
	}

	/**
	 * The demo administrator role.
	 *
	 * Deliberately NOT the WordPress administrator role. It carries every
	 * marketplace capability and none of the site-control ones, so the
	 * client can approve listings and manage facilities without being one
	 * click from installing a plugin on a shared review environment.
	 */
	private function ensure_demo_admin_role(): void {

		$caps = [
			'read'         => true,
			'upload_files' => true,
			'edit_posts'   => true, // Needed to reach the admin list tables.
			'delete_posts' => true,

			/*
			 * Pages.
			 *
			 * The client reviewing as "Administrator" has to be able to open
			 * the homepage and edit it — that is most of what they are here
			 * to check. Without these they get a Dashboard with nothing on
			 * it, and no "Edit with Elementor" link, which is exactly how
			 * this role shipped the first time.
			 */
			'edit_pages'              => true,
			'edit_others_pages'       => true,
			'edit_published_pages'    => true,
			'edit_private_pages'      => true,
			'publish_pages'           => true,
			'read_private_pages'      => true,
			'delete_pages'            => true,
			'delete_others_pages'     => true,
			'delete_published_pages'  => true,

			/*
			 * Menus and the Customizer, but NOT theme switching.
			 *
			 * edit_theme_options covers Appearance > Menus and Customize,
			 * which is where the hero photograph and navigation live.
			 * switch_themes is deliberately absent, so the review admin can
			 * arrange the site without being able to replace it.
			 */
			'edit_theme_options'      => true,
		];

		foreach ( Roles::administrator_caps() as $cap ) {
			$caps[ $cap ] = true;
		}

		/*
		 * Always rewrite the role rather than returning early when it
		 * exists. Roles live in the database, so an early return means any
		 * capability added here later never reaches a site where the role
		 * was already created — the change looks applied and silently is
		 * not. That is how the missing page capabilities went unnoticed.
		 */
		remove_role( 'tdh_demo_admin' );
		add_role( 'tdh_demo_admin', __( 'Marketplace Administrator (review)', 'thirtydayhomes' ), $caps );
	}

	/**
	 * Restore the seeded review data.
	 */
	private function reset(): void {
		$seed = TDH_PLUGIN_DIR . 'tools/seed-dev-data.php';

		/**
		 * Fires when a reviewer asks for the demo data to be restored.
		 *
		 * The seeder is WP-CLI only, so this hook is where a future
		 * browser-runnable reseed belongs.
		 */
		do_action( 'tdh_demo_reset', $seed );

		set_transient( 'tdh_demo_reset_notice', 1, 60 );
	}

	/**
	 * Build a nonce-protected switch URL.
	 */
	private function switch_url( string $persona ): string {
		return wp_nonce_url(
			add_query_arg( self::QUERY_ARG, $persona, home_url( '/' ) ),
			self::NONCE
		);
	}

	/**
	 * The bar itself. Markup mirrors the prototype's `.demo-bar`.
	 */
	public function render_bar(): void {

		$current  = '';
		$user     = wp_get_current_user();

		if ( $user && $user->ID ) {
			$current = (string) get_user_meta( $user->ID, self::USER_FLAG, true );
		} else {
			$current = 'renter';
		}
		?>
		<div class="demo-bar">
			<span>
				<i aria-hidden="true"></i>
				<?php esc_html_e( 'Interactive client review — staging only', 'thirtydayhomes' ); ?>
			</span>

			<div>
				<label for="tdh-persona"><?php esc_html_e( 'Viewing as', 'thirtydayhomes' ); ?></label>

				<select id="tdh-persona" onchange="if(this.value)window.location=this.value;">
					<?php foreach ( self::personas() as $key => $spec ) : ?>
						<option
							value="<?php echo esc_url( $this->switch_url( $key ) ); ?>"
							<?php selected( $current, $key ); ?>
						>
							<?php echo esc_html( $spec['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( self::RESET_ARG, 1, home_url( '/' ) ), self::NONCE ) ); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
						<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"></path>
						<path d="M21 3v5h-5"></path>
						<path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"></path>
						<path d="M8 16H3v5"></path>
					</svg>
					<?php esc_html_e( 'Reset', 'thirtydayhomes' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * The persona switcher in the WordPress admin bar.
	 *
	 * Present on both the front end and wp-admin, so a reviewer can always
	 * get back to Administrator no matter which persona they are wearing or
	 * which screen they are on.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar instance.
	 */
	public function admin_bar( $bar ): void {

		$user    = wp_get_current_user();
		$current = ( $user && $user->ID )
			? (string) get_user_meta( $user->ID, self::USER_FLAG, true )
			: 'renter';

		$personas = self::personas();
		$label    = $personas[ $current ]['label'] ?? __( 'Renter', 'thirtydayhomes' );

		$bar->add_node(
			[
				'id'    => 'tdh-persona',
				/* translators: %s: the persona currently being viewed as */
				'title' => sprintf( __( 'Viewing as: %s', 'thirtydayhomes' ), $label ),
				'href'  => false,
				'meta'  => [ 'title' => __( 'ThirtyDayHomes client review mode', 'thirtydayhomes' ) ],
			]
		);

		foreach ( $personas as $key => $spec ) {
			$bar->add_node(
				[
					'id'     => 'tdh-persona-' . $key,
					'parent' => 'tdh-persona',
					'title'  => ( $key === $current ? '✓ ' : '' ) . $spec['label'],
					'href'   => $this->switch_url( $key ),
				]
			);
		}

		$bar->add_node(
			[
				'id'     => 'tdh-persona-reset',
				'parent' => 'tdh-persona',
				'title'  => __( 'Reset demo data', 'thirtydayhomes' ),
				'href'   => wp_nonce_url( add_query_arg( self::RESET_ARG, 1, home_url( '/' ) ), self::NONCE ),
			]
		);
	}

	/**
	 * Bar styling.
	 *
	 * Printed by the plugin rather than the theme, so the bar keeps working
	 * if the theme is swapped mid-review.
	 */
	public function styles(): void {
		?>
		<style>
			.demo-bar{height:34px;padding:0 5vw;background:#0c192b;color:#dce2e9;display:flex;justify-content:space-between;align-items:center;gap:12px;font-size:11px;letter-spacing:.4px;font-family:'DM Sans',sans-serif}
			.demo-bar > span{display:flex;align-items:center;gap:8px}
			.demo-bar > span i{width:7px;height:7px;background:#d7b967;border-radius:50%;box-shadow:0 0 0 4px rgba(215,185,103,.13)}
			.demo-bar > div{display:flex;align-items:center;gap:8px}
			.demo-bar label{white-space:nowrap}
			.demo-bar select,.demo-bar a{height:26px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.11);color:#fff;border-radius:3px;padding:0 8px;font:inherit}
			.demo-bar a{display:flex;align-items:center;gap:5px;text-decoration:none}
			.demo-bar a:hover{background:rgba(255,255,255,.1)}
			.demo-bar option{color:#111}
			.demo-bar svg{width:13px;height:13px}
			@media (max-width:700px){
				.demo-bar{height:auto;padding:7px 4%;align-items:flex-start;flex-wrap:wrap}
				.demo-bar label{display:none}
			}
		</style>
		<?php
	}
}
