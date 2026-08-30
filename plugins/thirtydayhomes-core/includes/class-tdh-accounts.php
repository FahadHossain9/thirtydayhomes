<?php
/**
 * Landlord accounts — registration, login, password reset, profile.
 *
 * PLUGIN, not theme. An account is data. If the theme were replaced
 * tomorrow, every landlord would still need to log in.
 *
 * Front-end forms rather than wp-login.php and wp-admin, because a landlord
 * is a customer of this marketplace, not a WordPress user. Sending them to a
 * screen branded "WordPress" to reset a password is jarring, and wp-admin
 * exposes a menu we would then spend time hiding.
 *
 * Every handler runs on `template_redirect`: early enough to redirect before
 * output starts, late enough that conditional tags and permalinks resolve.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Account form processing and the guards around wp-admin.
 */
final class Accounts {

	/** Where errors and notices survive one redirect. */
	private const TRANSIENT_PREFIX = 'tdh_notice_';

	/** Failed logins allowed from one IP before throttling. */
	private const LOGIN_ATTEMPT_LIMIT = 5;

	/** How long the throttle lasts. */
	private const LOGIN_LOCKOUT = 15 * MINUTE_IN_SECONDS;

	public function register(): void {

		add_action( 'template_redirect', [ $this, 'handle_forms' ] );

		// Keep landlords out of wp-admin and off the admin bar.
		add_action( 'admin_init', [ $this, 'block_admin_access' ] );
		add_filter( 'show_admin_bar', [ $this, 'hide_admin_bar' ] );

		// Send them to their dashboard rather than the WordPress profile
		// screen after logging in through any route.
		add_filter( 'login_redirect', [ $this, 'login_redirect' ], 10, 3 );

		// Our own reset link, so the email does not point at wp-login.php.
		add_filter( 'retrieve_password_message', [ $this, 'reset_email_body' ], 10, 4 );
		add_filter( 'retrieve_password_title', [ $this, 'reset_email_subject' ] );

		// Keep account screens out of search results. In the plugin, not the
		// theme: the reset page carries a one-time token in its URL, and a
		// theme swap must not quietly make that crawlable.
		add_filter( 'wp_robots', [ $this, 'noindex_account_pages' ] );

		// "Sign in" becomes "Dashboard" once somebody is signed in.
		add_filter( 'wp_nav_menu_objects', [ $this, 'account_aware_menu' ] );
	}

	/**
	 * Point the sign-in menu item at the right place for the visitor.
	 *
	 * Done as a filter on the menu rather than as hardcoded links in the
	 * header, because the client owns the menu. Printing account links in
	 * the template as well produced a header with three buttons in it and
	 * a row that wrapped.
	 *
	 * Matching is by destination, not by label: an administrator who
	 * renames "Sign in" to "Landlord login" must not break this.
	 *
	 * @param array<int,object> $items Menu items about to be rendered.
	 *
	 * @return array<int,object>
	 */
	public function account_aware_menu( $items ) {

		if ( ! is_user_logged_in() ) {
			return $items;
		}

		$login   = untrailingslashit( self::url( 'login' ) );
		$account = self::url( 'account' );

		foreach ( $items as $item ) {

			$url = untrailingslashit( (string) $item->url );

			// wp_login_url() is matched too: the demo menu was seeded
			// pointing at wp-login.php before front-end auth existed, and
			// any site imported before that fix still carries it.
			if ( $url !== $login && $url !== untrailingslashit( wp_login_url() ) ) {
				continue;
			}

			$item->url   = $account;
			$item->title = __( 'Dashboard', 'thirtydayhomes' );
		}

		return $items;
	}

	/**
	 * @param array<string,mixed> $robots
	 *
	 * @return array<string,mixed>
	 */
	public function noindex_account_pages( $robots ) {

		if ( is_singular() && get_post_meta( get_queried_object_id(), '_tdh_noindex', true ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}

		return $robots;
	}

	/* ---------------------------------------------------------------------
	 * Routing
	 * ------------------------------------------------------------------ */

	/**
	 * Dispatch whichever account form was submitted.
	 */
	public function handle_forms(): void {

		$action = isset( $_POST['tdh_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['tdh_action'] ) ) : '';

		if ( '' === $action ) {
			return;
		}

		$handlers = [
			'register'       => 'do_register',
			'login'          => 'do_login',
			'lost_password'  => 'do_lost_password',
			'reset_password' => 'do_reset_password',
			'profile'        => 'do_profile',
		];

		if ( ! isset( $handlers[ $action ] ) ) {
			return;
		}

		// One nonce action per form. A single shared nonce would let a token
		// harvested from the login page authorise a profile change.
		if ( ! isset( $_POST['tdh_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tdh_nonce'] ) ), 'tdh_' . $action ) ) {
			$this->fail( __( 'That form expired. Please try again.', 'thirtydayhomes' ) );
		}

		$this->{$handlers[ $action ]}();
	}

	/* ---------------------------------------------------------------------
	 * Registration
	 * ------------------------------------------------------------------ */

	private function do_register(): void {

		if ( is_user_logged_in() ) {
			$this->redirect( self::url( 'account' ) );
		}

		$name    = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_name'] ?? '' ) ) );
		$email   = sanitize_email( wp_unslash( (string) ( $_POST['tdh_email'] ?? '' ) ) );
		$phone   = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_phone'] ?? '' ) ) );
		$company = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_company'] ?? '' ) ) );

		// Deliberately not sanitised: any transformation of a password
		// changes what the user typed, and they would then be unable to log
		// in with it. It is hashed, never echoed, never stored in the clear.
		$password = (string) ( $_POST['tdh_password'] ?? '' );

		$errors = [];

		if ( '' === $name ) {
			$errors[] = __( 'Please enter your name.', 'thirtydayhomes' );
		}

		if ( ! is_email( $email ) ) {
			$errors[] = __( 'Please enter a valid email address.', 'thirtydayhomes' );
		} elseif ( email_exists( $email ) ) {
			$errors[] = __( 'An account already exists with that email address.', 'thirtydayhomes' );
		}

		if ( strlen( $password ) < 12 ) {
			/* translators: %d: minimum password length */
			$errors[] = sprintf( __( 'Passwords must be at least %d characters.', 'thirtydayhomes' ), 12 );
		}

		if ( empty( $_POST['tdh_terms'] ) ) {
			$errors[] = __( 'Please accept the Terms of Use and Fair Housing policy.', 'thirtydayhomes' );
		}

		// Honeypot. A field a person never sees and a bot fills in. Cheaper
		// than a CAPTCHA and it costs a real user nothing.
		if ( ! empty( $_POST['tdh_website'] ) ) {
			$this->redirect( self::url( 'account' ) );
		}

		if ( $errors ) {
			$this->fail( $errors, [ 'tdh_name' => $name, 'tdh_email' => $email, 'tdh_phone' => $phone, 'tdh_company' => $company ] );
		}

		$user_id = wp_insert_user(
			[
				'user_login'   => $email,
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $name,
				'first_name'   => $name,
				'role'         => Roles::LANDLORD,
			]
		);

		if ( is_wp_error( $user_id ) ) {
			$this->fail( $user_id->get_error_message() );
		}

		$user_id = (int) $user_id;

		update_user_meta( $user_id, '_tdh_phone', $phone );
		update_user_meta( $user_id, '_tdh_company', $company );
		update_user_meta( $user_id, Membership::META_STATUS, Membership::NONE );

		// Records that consent happened and when. Fair Housing acknowledgment
		// is a compliance artefact, not a checkbox we can forget after signup.
		update_user_meta( $user_id, '_tdh_terms_accepted', time() );

		/**
		 * Fires after a landlord account is created.
		 *
		 * @param int $user_id The new landlord.
		 */
		do_action( 'tdh_landlord_registered', $user_id );

		wp_new_user_notification( $user_id, null, 'admin' );

		// Log them straight in. Asking someone to type the password they
		// just chose, on the next screen, achieves nothing.
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		$this->succeed( __( 'Welcome. Your landlord account is ready.', 'thirtydayhomes' ), self::url( 'account' ) );
	}

	/* ---------------------------------------------------------------------
	 * Login and logout
	 * ------------------------------------------------------------------ */

	private function do_login(): void {

		if ( $this->is_throttled() ) {
			$this->fail( __( 'Too many failed attempts. Please try again in fifteen minutes.', 'thirtydayhomes' ) );
		}

		$login    = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_email'] ?? '' ) ) );
		$password = (string) ( $_POST['tdh_password'] ?? '' );

		$user = wp_signon(
			[
				'user_login'    => $login,
				'user_password' => $password,
				'remember'      => ! empty( $_POST['tdh_remember'] ),
			],
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			$this->record_failed_login();

			// One message for a wrong email and a wrong password alike.
			// Distinguishing them tells an attacker which addresses are
			// registered, which is a list worth having.
			$this->fail( __( 'That email address and password do not match.', 'thirtydayhomes' ), [ 'tdh_email' => $login ] );
		}

		$this->clear_failed_logins();
		wp_set_current_user( $user->ID );

		$redirect = isset( $_POST['tdh_redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_POST['tdh_redirect_to'] ) ) : '';

		$this->redirect( $redirect ?: self::url( 'account' ) );
	}

	/* ---------------------------------------------------------------------
	 * Password reset
	 * ------------------------------------------------------------------ */

	private function do_lost_password(): void {

		$login = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_email'] ?? '' ) ) );

		if ( '' === $login ) {
			$this->fail( __( 'Please enter your email address.', 'thirtydayhomes' ) );
		}

		$user = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );

		// The same confirmation whether or not the address is registered.
		// "No account with that email" is an account-enumeration oracle.
		$confirmation = __( 'If that address has an account, a reset link is on its way.', 'thirtydayhomes' );

		if ( ! $user ) {
			$this->succeed( $confirmation, self::url( 'login' ) );
		}

		$sent = retrieve_password( $user->user_login );

		if ( is_wp_error( $sent ) ) {
			$this->fail( __( 'We could not send the reset email. Please try again shortly.', 'thirtydayhomes' ) );
		}

		$this->succeed( $confirmation, self::url( 'login' ) );
	}

	private function do_reset_password(): void {

		$key   = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_key'] ?? '' ) ) );
		$login = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_login'] ?? '' ) ) );
		$pass  = (string) ( $_POST['tdh_password'] ?? '' );
		$again = (string) ( $_POST['tdh_password_confirm'] ?? '' );

		$user = check_password_reset_key( $key, $login );

		if ( is_wp_error( $user ) ) {
			$this->fail( __( 'That reset link has expired. Please request a new one.', 'thirtydayhomes' ), [], self::url( 'lost-password' ) );
		}

		if ( strlen( $pass ) < 12 ) {
			/* translators: %d: minimum password length */
			$this->fail( sprintf( __( 'Passwords must be at least %d characters.', 'thirtydayhomes' ), 12 ) );
		}

		if ( $pass !== $again ) {
			$this->fail( __( 'Those passwords do not match.', 'thirtydayhomes' ) );
		}

		reset_password( $user, $pass );

		// Every other session is invalidated. A reset is what someone does
		// when they think the account is compromised, so leaving the
		// attacker's session alive would defeat the point.
		$sessions = \WP_Session_Tokens::get_instance( $user->ID );
		$sessions->destroy_all();

		$this->succeed( __( 'Your password has been changed. Please sign in.', 'thirtydayhomes' ), self::url( 'login' ) );
	}

	/* ---------------------------------------------------------------------
	 * Profile
	 * ------------------------------------------------------------------ */

	private function do_profile(): void {

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			$this->fail( __( 'Please sign in first.', 'thirtydayhomes' ), [], self::url( 'login' ) );
		}

		$name    = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_name'] ?? '' ) ) );
		$email   = sanitize_email( wp_unslash( (string) ( $_POST['tdh_email'] ?? '' ) ) );
		$phone   = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_phone'] ?? '' ) ) );
		$company = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_company'] ?? '' ) ) );

		$current  = (string) ( $_POST['tdh_password_current'] ?? '' );
		$new_pass = (string) ( $_POST['tdh_password_new'] ?? '' );

		$errors = [];

		if ( '' === $name ) {
			$errors[] = __( 'Please enter your name.', 'thirtydayhomes' );
		}

		if ( ! is_email( $email ) ) {
			$errors[] = __( 'Please enter a valid email address.', 'thirtydayhomes' );
		} else {
			$owner = email_exists( $email );
			if ( $owner && (int) $owner !== $user_id ) {
				$errors[] = __( 'Another account already uses that email address.', 'thirtydayhomes' );
			}
		}

		$user = get_userdata( $user_id );

		// Changing a password or an email address requires proving you are
		// the person sitting at the keyboard, not just holding the session.
		$sensitive = ( '' !== $new_pass ) || ( $email !== $user->user_email );

		if ( $sensitive && ! wp_check_password( $current, $user->user_pass, $user_id ) ) {
			$errors[] = __( 'Please enter your current password to change your email or password.', 'thirtydayhomes' );
		}

		if ( '' !== $new_pass && strlen( $new_pass ) < 12 ) {
			/* translators: %d: minimum password length */
			$errors[] = sprintf( __( 'Passwords must be at least %d characters.', 'thirtydayhomes' ), 12 );
		}

		if ( $errors ) {
			$this->fail( $errors, [], self::url( 'profile' ) );
		}

		$update = [
			'ID'           => $user_id,
			'display_name' => $name,
			'first_name'   => $name,
			'user_email'   => $email,
		];

		if ( '' !== $new_pass ) {
			$update['user_pass'] = $new_pass;
		}

		$result = wp_update_user( $update );

		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_message(), [], self::url( 'profile' ) );
		}

		update_user_meta( $user_id, '_tdh_phone', $phone );
		update_user_meta( $user_id, '_tdh_company', $company );

		// wp_update_user() with a new password clears the current session's
		// cookie. Reissue it so a password change does not log the user out
		// of the very request that made it.
		if ( '' !== $new_pass ) {
			wp_set_auth_cookie( $user_id, true );
		}

		$this->succeed( __( 'Your details have been saved.', 'thirtydayhomes' ), self::url( 'profile' ) );
	}

	/* ---------------------------------------------------------------------
	 * wp-admin guards
	 * ------------------------------------------------------------------ */

	/**
	 * Landlords never see wp-admin.
	 *
	 * AJAX is exempt: the media uploader they use on the listing form posts
	 * to admin-ajax.php, and blocking it would break photo upload.
	 */
	public function block_admin_access(): void {

		if ( wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}

		if ( ! self::is_landlord() ) {
			return;
		}

		wp_safe_redirect( self::url( 'account' ) );
		exit;
	}

	/**
	 * @param bool $show Whether to show the bar.
	 */
	public function hide_admin_bar( $show ) {
		return self::is_landlord() ? false : $show;
	}

	/**
	 * @param string $redirect Requested destination.
	 * @param string $request  Original request.
	 * @param mixed  $user     WP_User or WP_Error.
	 */
	public function login_redirect( $redirect, $request, $user ) {

		if ( $user instanceof \WP_User && in_array( Roles::LANDLORD, (array) $user->roles, true ) ) {
			return self::url( 'account' );
		}

		return $redirect;
	}

	/**
	 * True when the current user is a landlord and nothing more.
	 *
	 * An administrator who also holds the landlord role keeps wp-admin —
	 * checking the role alone would lock out a client who granted
	 * themselves a landlord account to test the flow.
	 */
	public static function is_landlord( int $user_id = 0 ): bool {

		$user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();

		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		return in_array( Roles::LANDLORD, (array) $user->roles, true )
			&& ! user_can( $user, 'manage_options' );
	}

	/* ---------------------------------------------------------------------
	 * Reset email
	 * ------------------------------------------------------------------ */

	/**
	 * Point the reset link at our page instead of wp-login.php.
	 *
	 * @param string $message Default body.
	 * @param string $key     Reset key.
	 * @param string $login   User login.
	 * @param mixed  $data    WP_User.
	 */
	public function reset_email_body( $message, $key, $login, $data ) {

		$url = add_query_arg(
			[
				'key'   => rawurlencode( (string) $key ),
				'login' => rawurlencode( (string) $login ),
			],
			self::url( 'reset-password' )
		);

		$lines = [
			__( 'Someone asked to reset the password for your ThirtyDayHomes account.', 'thirtydayhomes' ),
			'',
			__( 'If that was not you, ignore this email and nothing will change.', 'thirtydayhomes' ),
			'',
			__( 'To choose a new password, open this link:', 'thirtydayhomes' ),
			$url,
			'',
			__( 'The link expires in 24 hours.', 'thirtydayhomes' ),
		];

		return implode( "\r\n", $lines );
	}

	/**
	 * @param string $title Default subject.
	 */
	public function reset_email_subject( $title ) {
		return sprintf(
			/* translators: %s: site name */
			__( '[%s] Reset your password', 'thirtydayhomes' ),
			wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES )
		);
	}

	/* ---------------------------------------------------------------------
	 * Login throttling
	 * ------------------------------------------------------------------ */

	private function throttle_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

		return 'tdh_login_fail_' . md5( $ip );
	}

	private function is_throttled(): bool {
		return (int) get_transient( $this->throttle_key() ) >= self::LOGIN_ATTEMPT_LIMIT;
	}

	private function record_failed_login(): void {
		$key = $this->throttle_key();
		set_transient( $key, (int) get_transient( $key ) + 1, self::LOGIN_LOCKOUT );
	}

	private function clear_failed_logins(): void {
		delete_transient( $this->throttle_key() );
	}

	/* ---------------------------------------------------------------------
	 * Notices, redirects, page URLs
	 * ------------------------------------------------------------------ */

	/**
	 * Where an account page lives.
	 *
	 * Looked up by the meta key the importer sets, not by slug — a client
	 * who renames "Sign in" to "Landlord login" would otherwise break every
	 * link in this class.
	 */
	public static function url( string $slug ): string {

		$found = get_posts(
			[
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'meta_key'         => '_tdh_seed_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $slug,           // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'suppress_filters' => false,
			]
		);

		if ( $found ) {
			return (string) get_permalink( (int) $found[0] );
		}

		// The page has not been created yet. Home is a dead end but it is a
		// working one, which a broken permalink is not.
		return home_url( '/' );
	}

	/**
	 * Stash a notice that survives the redirect after a POST.
	 *
	 * A transient keyed on the session, not a query string: an error in the
	 * URL is shareable, bookmarkable and shows up in server logs.
	 *
	 * @param string[] $errors
	 * @param array<string,string> $values Values to repopulate the form with.
	 */
	private function stash( array $errors, string $success = '', array $values = [] ): void {

		set_transient(
			self::TRANSIENT_PREFIX . self::visitor_key(),
			[
				'errors'  => $errors,
				'success' => $success,
				'values'  => $values,
			],
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Read and clear the stashed notice.
	 *
	 * @return array{errors:string[],success:string,values:array<string,string>}
	 */
	public static function take_notice(): array {

		$key   = self::TRANSIENT_PREFIX . self::visitor_key();
		$stash = get_transient( $key );

		delete_transient( $key );

		return [
			'errors'  => (array) ( $stash['errors'] ?? [] ),
			'success' => (string) ( $stash['success'] ?? '' ),
			'values'  => (array) ( $stash['values'] ?? [] ),
		];
	}

	/**
	 * Identifies this visitor for the notice transient.
	 *
	 * The user id once logged in; otherwise a cookie-scoped hash. Falling
	 * back to IP alone would show one visitor's error to everyone behind
	 * the same office NAT.
	 */
	private static function visitor_key(): string {

		$user_id = get_current_user_id();

		if ( $user_id ) {
			return 'u' . $user_id;
		}

		$seed = ( $_COOKIE[ TEST_COOKIE ] ?? '' ) . ( $_SERVER['REMOTE_ADDR'] ?? '' ) . ( $_SERVER['HTTP_USER_AGENT'] ?? '' );

		return 'g' . md5( (string) $seed );
	}

	/**
	 * @param string|string[] $errors
	 * @param array<string,string> $values
	 */
	private function fail( $errors, array $values = [], string $to = '' ): void {
		$this->stash( (array) $errors, '', $values );
		$this->redirect( $to ?: $this->back() );
	}

	private function succeed( string $message, string $to ): void {
		$this->stash( [], $message );
		$this->redirect( $to );
	}

	private function back(): string {
		$ref = wp_get_referer();

		return $ref ?: home_url( '/' );
	}

	private function redirect( string $to ): void {
		wp_safe_redirect( $to );
		exit;
	}
}
