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

	/**
	 * Names the visitor a stashed notice belongs to. See visitor_key().
	 *
	 * Public alongside visitor_key(): every form that stashes across a
	 * redirect keys on this one cookie, and the verification suites have to
	 * be able to set it to prove that two visitors behind one office NAT
	 * cannot read each other's half-typed message.
	 */
	public const NOTICE_COOKIE = 'tdh_notice_id';

	/** Which form is being handled, so a failure knows where to send it back. */
	private string $screen = '';

	/**
	 * Failed sign-ins allowed against ONE account from one address.
	 *
	 * Five, which is what someone genuinely mistyping their own password
	 * needs. It is counted per account, not per address — see throttle_keys().
	 */
	private const LOGIN_ATTEMPT_LIMIT = 5;

	/**
	 * Failed sign-ins allowed from one address across ALL accounts.
	 *
	 * Deliberately much higher than the per-account limit. Its job is not to
	 * catch someone guessing one password, which the limit above already
	 * does; it is to catch one address trying a few passwords against many
	 * different accounts, which the per-account counter would never see.
	 */
	private const LOGIN_IP_LIMIT = 20;

	/** How long either throttle lasts. */
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

		// Remembered before anything can fail, so every fail() below knows
		// which screen to return to without consulting the Referer.
		$this->screen = $action;

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

		$login    = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_email'] ?? '' ) ) );
		$password = (string) ( $_POST['tdh_password'] ?? '' );

		// After reading the address, because the allowance is counted per
		// account now rather than per visitor address.
		//
		// One message whichever limit tripped. Saying which would tell
		// someone probing whether they are near the per-account ceiling,
		// and the address kept in the form is the same either way.
		if ( $this->is_throttled( $login ) ) {
			$this->fail(
				__( 'Too many failed attempts. Please try again in fifteen minutes.', 'thirtydayhomes' ),
				[ 'tdh_email' => $login ]
			);
		}

		$user = wp_signon(
			[
				'user_login'    => $login,
				'user_password' => $password,
				'remember'      => ! empty( $_POST['tdh_remember'] ),
			],
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			$this->record_failed_login( $login );

			// One message for a wrong email and a wrong password alike.
			// Distinguishing them tells an attacker which addresses are
			// registered, which is a list worth having.
			$this->fail( __( 'That email address and password do not match.', 'thirtydayhomes' ), [ 'tdh_email' => $login ] );
		}

		$this->clear_failed_logins( $login );
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

		if ( ! $user instanceof \WP_User ) {
			return $redirect;
		}

		if ( in_array( Roles::LANDLORD, (array) $user->roles, true ) ) {
			return self::url( 'account' );
		}

		/*
		 * Staff too — but only away from the GENERIC destination. Signing
		 * in lands the owner on their marketplace portal, where the day
		 * starts; a login that was asked for by a specific wp-admin screen
		 * (an edit link opened while logged out) still goes where it was
		 * going. The developer's door back into WordPress is in the
		 * portal's sidebar.
		 */
		if ( self::is_staff( (int) $user->ID ) ) {

			$generic = untrailingslashit( admin_url() );

			if ( '' === (string) $redirect || untrailingslashit( (string) $redirect ) === $generic ) {
				return self::url( 'account' );
			}
		}

		return $redirect;
	}

	/**
	 * True when this user runs the marketplace — approves listings, sees
	 * the administration portal.
	 *
	 * Keyed on a MARKETPLACE capability, not manage_options: the client
	 * review mode's "Administrator" persona deliberately lacks
	 * manage_options (it must not be able to take over WordPress on a
	 * shared staging URL), yet it exists precisely to run the marketplace.
	 * manage_options answers "may configure WordPress", which is a
	 * different question.
	 */
	public static function is_staff( int $user_id = 0 ): bool {

		if ( $user_id ) {
			return user_can( $user_id, 'edit_others_tdh_listings' );
		}

		return current_user_can( 'edit_others_tdh_listings' );
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

	/**
	 * The two counters a failed sign-in increments.
	 *
	 * WHY TWO. This used to be one counter keyed on md5( IP ) alone, so
	 * every visitor sharing an address shared a single allowance of five.
	 * Behind an office NAT — a property management company with four staff,
	 * exactly the customer this marketplace is for — one person mistyping
	 * their password five times locked out all of their colleagues, and none
	 * of them would have any idea why. On this machine it bit us directly:
	 * automated tests and a browser both come from localhost, so the
	 * developer's failures spent the client's allowance.
	 *
	 * Per account and address: five, which is what a real person mistyping
	 * their own password needs, and it cannot be spent by anyone else.
	 *
	 * Per address across all accounts: twenty, so one address quietly trying
	 * two or three common passwords against dozens of accounts still trips
	 * something. The per-account counter cannot see that pattern, because no
	 * single account gets near five.
	 *
	 * @return array{0:string,1:string} The per-account key, then the per-address key.
	 */
	private function throttle_keys( string $login ): array {

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

		// Lower-cased and trimmed so "Test@Example.com" and the same address
		// in lower case count against one allowance; they are one account.
		$account = strtolower( trim( $login ) );

		return [
			'tdh_login_fail_' . md5( $ip . '|' . $account ),
			'tdh_login_ip_' . md5( $ip ),
		];
	}

	private function is_throttled( string $login ): bool {

		list( $account_key, $ip_key ) = $this->throttle_keys( $login );

		return (int) get_transient( $account_key ) >= self::LOGIN_ATTEMPT_LIMIT
			|| (int) get_transient( $ip_key ) >= self::LOGIN_IP_LIMIT;
	}

	private function record_failed_login( string $login ): void {

		foreach ( $this->throttle_keys( $login ) as $key ) {
			set_transient( $key, (int) get_transient( $key ) + 1, self::LOGIN_LOCKOUT );
		}
	}

	/**
	 * Clears the account's allowance, NOT the address-wide one.
	 *
	 * Someone who signs in successfully has proved they own that account, so
	 * its counter is meaningless. The address-wide counter is evidence about
	 * the address rather than the account, and one successful sign-in should
	 * not wipe it — otherwise anyone spraying passwords resets the wider
	 * limit simply by signing into an account they do own.
	 */
	private function clear_failed_logins( string $login ): void {
		list( $account_key ) = $this->throttle_keys( $login );

		delete_transient( $account_key );
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

		$key = self::visitor_key( true );

		// On the cookie-less fallback key the message is generic enough to
		// show anyone; the values are not, because they carry the email
		// address this visitor just typed. Drop them rather than risk
		// handing them to the next person on the same IP and browser.
		if ( ! self::key_is_private( $key ) ) {
			$values = [];
		}

		set_transient(
			self::TRANSIENT_PREFIX . $key,
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
	 * Where "Sign out" goes.
	 *
	 * The sign-in screen, saying so — not the home page in silence, which is
	 * what a landlord got before: the site simply changed under them with no
	 * word that anything had happened, and the header quietly swapped
	 * "Dashboard" back to "Sign in".
	 *
	 * The flag rides in the URL rather than in the notice transient on
	 * purpose. Signing out changes the visitor key from the user id to a
	 * guest token, so a stashed notice would be written under one key and
	 * read under another — the same class of bug as the referrer redirect.
	 * "You signed out" is not sensitive, so a query argument is honest here
	 * where an error message would not be.
	 */
	public static function logout_url(): string {
		return wp_logout_url( add_query_arg( 'signed_out', '1', self::url( 'login' ) ) );
	}

	/**
	 * Identifies this visitor for the notice transient.
	 *
	 * The user id once signed in; otherwise a token in a cookie of our own.
	 *
	 * This used to hash TEST_COOKIE . REMOTE_ADDR . HTTP_USER_AGENT and call
	 * itself "cookie-scoped". It was not. WordPress sets TEST_COOKIE on
	 * wp-login.php, and these are our own front-end forms, so that cookie is
	 * simply absent here — verified on a live request — and the key
	 * collapsed to md5( IP . user agent ). Two people behind one office NAT
	 * on the same browser build shared a key, and the stash carries the
	 * email address the visitor typed for repopulating the form. One of them
	 * could be handed the other's address.
	 *
	 * PUBLIC because it is not only this class's problem. Any front-end form
	 * that stashes what somebody typed across a redirect needs exactly this
	 * key, and the contact form was written with a private copy of the same
	 * broken TEST_COOKIE reasoning described above — carrying the bug forward
	 * rather than the fix. One implementation, so a security-relevant key
	 * cannot be right in one place and wrong in another.
	 *
	 * Callers MUST drop anything the visitor typed when this returns a 'g'
	 * key: that one is shared by everybody behind an office NAT. Use
	 * key_is_private() rather than testing the prefix by hand.
	 *
	 * @param bool $create Mint a token if there is none. Only true on the
	 *                     stashing request: minting sends a Set-Cookie
	 *                     header, and take_notice() runs during rendering,
	 *                     after headers are away.
	 */
	public static function visitor_key( bool $create = false ): string {

		$user_id = get_current_user_id();

		if ( $user_id ) {
			return 'u' . $user_id;
		}

		$token = self::notice_token( $create );

		if ( '' !== $token ) {
			return 't' . $token;
		}

		// Cookies refused. Keep the old behaviour rather than swallowing the
		// message — but stash() drops the form values on this key, so the
		// worst a collision can now show someone is a generic sentence.
		return 'g' . md5( ( $_SERVER['REMOTE_ADDR'] ?? '' ) . ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
	}

	/**
	 * Does this key belong to ONE visitor?
	 *
	 * A user id or a minted token does. The 'g' fallback does not — it is
	 * md5( IP . user agent ), which everybody behind one office NAT on the
	 * same browser build shares.
	 *
	 * A named check rather than `$key[0] === 't'` scattered around, because
	 * the consequence of getting it wrong is handing one visitor the email
	 * address and message another just typed.
	 */
	public static function key_is_private( string $key ): bool {
		return '' !== $key && ( 't' === $key[0] || 'u' === $key[0] );
	}

	/**
	 * Read, or mint, this visitor's notice token.
	 *
	 * Ten minutes, host-only, HttpOnly, SameSite=Lax. It identifies nobody
	 * and survives exactly one redirect; it is not an analytics cookie and
	 * needs no consent banner.
	 */
	private static function notice_token( bool $create = false ): string {

		$existing = preg_replace( '/[^a-f0-9]/', '', (string) ( $_COOKIE[ self::NOTICE_COOKIE ] ?? '' ) );

		if ( is_string( $existing ) && 32 === strlen( $existing ) ) {
			return $existing;
		}

		if ( ! $create || headers_sent() ) {
			return '';
		}

		$token = md5( wp_generate_password( 40, true, true ) );

		setcookie(
			self::NOTICE_COOKIE,
			$token,
			[
				'expires'  => time() + ( 10 * MINUTE_IN_SECONDS ),
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);

		// So the same request can key its own stash with it.
		$_COOKIE[ self::NOTICE_COOKIE ] = $token;

		return $token;
	}

	/**
	 * @param string|string[] $errors
	 * @param array<string,string> $values
	 */
	private function fail( $errors, array $values = [], string $to = '' ): void {
		$this->stash( (array) $errors, '', $values );
		$this->redirect( $to ?: $this->screen_url() );
	}

	private function succeed( string $message, string $to ): void {
		$this->stash( [], $message );
		$this->redirect( $to );
	}

	/**
	 * The page the form being handled lives on.
	 *
	 * THIS IS NOT THE REFERRER, and that is the whole point. The previous
	 * version redirected to wp_get_referer(), which is the Referer header
	 * and is optional: privacy browsers trim it, proxies and some
	 * navigations drop it entirely. When it was missing, a failed sign-in
	 * redirected to the HOME PAGE — and the home page renders no notices,
	 * so the stashed "that email address and password do not match" was
	 * never shown. Someone typed a wrong password and the site quietly
	 * showed them the front page, which is indistinguishable from having
	 * signed in successfully.
	 *
	 * A form always knows which screen it belongs to. Ask that, not the
	 * browser.
	 */
	private function screen_url(): string {

		switch ( $this->screen ) {
			case 'register':
				return self::url( 'register' );
			case 'login':
				return self::url( 'login' );
			case 'lost_password':
				return self::url( 'lost-password' );
			case 'reset_password':
				return $this->reset_url();
			case 'profile':
				return self::url( 'profile' );
		}

		// Nothing was being handled — a direct hit on the hook, which should
		// not happen. Home is a dead end but it is a working one.
		return home_url( '/' );
	}

	/**
	 * The reset screen, with the credentials from the link kept.
	 *
	 * Without them the page has no key to check and renders "this page needs
	 * the link from your reset email" — so a mistyped password confirmation
	 * would cost the visitor the link they were sent.
	 */
	private function reset_url(): string {

		$key   = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_key'] ?? '' ) ) );
		$login = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_login'] ?? '' ) ) );

		if ( '' === $key || '' === $login ) {
			return self::url( 'reset-password' );
		}

		return add_query_arg(
			[
				'key'   => $key,
				'login' => $login,
			],
			self::url( 'reset-password' )
		);
	}

	private function redirect( string $to ): void {
		wp_safe_redirect( $to );
		exit;
	}
}
