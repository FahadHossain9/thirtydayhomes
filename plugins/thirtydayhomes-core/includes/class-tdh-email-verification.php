<?php
/**
 * New landlords confirm their email address before they pay or submit (F1).
 *
 * Only an address that has NOT been proven is recorded — `_tdh_email_pending`
 * holds it. No record means confirmed, so every account from before this
 * feature, every member staff created (their password-setup email already
 * proved the address) and every review persona is confirmed without a
 * migration. Staff are never asked.
 *
 * The link carries the user id and a random token; only an HMAC of the token
 * is stored. It works while signed out and signs the landlord in, once: the
 * token is spent on use, dies after 24 hours, and dies when the address it
 * was sent to is changed again.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

final class Email_Verification {

	public const META_PENDING  = '_tdh_email_pending';
	public const META_VERIFIED = '_tdh_email_verified_at';
	public const META_TOKEN    = '_tdh_email_token';
	public const META_EXPIRES  = '_tdh_email_token_expires';
	public const META_SENDS    = '_tdh_email_sends';

	public const LIFETIME   = DAY_IN_SECONDS;
	public const GAP        = 60;
	public const PER_HOUR   = 5;
	public const ARG        = 'tdh_verify';
	public const ACTION     = 'verify_resend';
	public const NONCE      = 'tdh_verify_resend';

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'maybe_follow_link' ], 0 );
		add_action( 'template_redirect', [ $this, 'maybe_resend' ], 1 );
	}

	/* ---------------------------------------------------------------------
	 * State
	 * ------------------------------------------------------------------ */

	public static function is_verified( int $user_id ): bool {
		if ( $user_id < 1 || Accounts::is_staff( $user_id ) ) {
			return true;
		}
		return '' === (string) get_user_meta( $user_id, self::META_PENDING, true );
	}

	/** The address waiting to be confirmed, or ''. */
	public static function pending( int $user_id ): string {
		return (string) get_user_meta( $user_id, self::META_PENDING, true );
	}

	/**
	 * Ask this landlord to prove their current address, and send the link.
	 *
	 * @return bool Whether the email was handed to the mail server.
	 */
	public static function start( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user || Accounts::is_staff( $user_id ) ) {
			return false;
		}
		update_user_meta( $user_id, self::META_PENDING, $user->user_email );
		delete_user_meta( $user_id, self::META_VERIFIED );
		delete_user_meta( $user_id, self::META_SENDS );
		return self::send( $user_id );
	}

	/**
	 * Mint a fresh token (the old one dies) and email the link.
	 */
	private static function send( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user || '' === self::pending( $user_id ) ) {
			return false;
		}

		// Always the account's address NOW — if staff corrected it since the
		// last link, the next one goes to the corrected address.
		$address = (string) $user->user_email;
		update_user_meta( $user_id, self::META_PENDING, $address );

		$token = wp_generate_password( 32, false, false );
		update_user_meta( $user_id, self::META_TOKEN, self::hash( $token ) );
		update_user_meta( $user_id, self::META_EXPIRES, time() + self::LIFETIME );

		$sends   = array_values( array_filter( (array) get_user_meta( $user_id, self::META_SENDS, true ), static fn( $t ) => (int) $t > time() - HOUR_IN_SECONDS ) );
		$sends[] = time();
		update_user_meta( $user_id, self::META_SENDS, $sends );

		$link = add_query_arg( [ self::ARG => $user_id . '.' . $token ], Accounts::url( 'account' ) );
		$name = '' !== trim( (string) $user->display_name ) ? $user->display_name : __( 'there', 'thirtydayhomes' );

		$body = sprintf(
			/* translators: 1: the person's name, 2: the link */
			__( "Hello %1\$s,\n\nPlease confirm this is your email address for ThirtyDayHomes:\n\n%2\$s\n\nThe link works once and for 24 hours. Until you confirm, you can look around your dashboard but cannot start a plan or submit a home.\n\nIf you did not create this account, ignore this email and nothing will happen.\n\nThirtyDayHomes", 'thirtydayhomes' ),
			$name,
			$link
		);

		$sent = (bool) wp_mail( $address, __( 'Confirm your email for ThirtyDayHomes', 'thirtydayhomes' ), $body );

		if ( class_exists( '\TDH\Log' ) ) {
			$sent
				? Log::info( 'members', 'verify_sent', __( 'An email confirmation link was sent.', 'thirtydayhomes' ), [ 'email' => $address ], $user_id, 'user' )
				: Log::error( 'members', 'verify_send_failed', __( 'The email confirmation link could not be sent. The landlord can press Send a new link.', 'thirtydayhomes' ), [ 'email' => $address ], $user_id, 'user' );
		}

		return $sent;
	}

	private static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Why "Send a new link" cannot be pressed right now, or ''.
	 */
	public static function resend_block( int $user_id ): string {
		$sends = array_values( array_filter( (array) get_user_meta( $user_id, self::META_SENDS, true ), static fn( $t ) => (int) $t > time() - HOUR_IN_SECONDS ) );
		if ( $sends && max( array_map( 'intval', $sends ) ) > time() - self::GAP ) {
			return __( 'A link was sent less than a minute ago. Please check your inbox, and your spam folder, before asking for another.', 'thirtydayhomes' );
		}
		if ( count( $sends ) >= self::PER_HOUR ) {
			/* translators: %d: links per hour */
			return sprintf( __( 'That is %d links in an hour. Please wait a while before asking for another, or contact us if none arrive.', 'thirtydayhomes' ), self::PER_HOUR );
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * The link
	 * ------------------------------------------------------------------ */

	/**
	 * What following a link does. Public so the suite can drive it.
	 *
	 * @return array{0:string,1:int} [ outcome, user id ] — outcome is one of
	 *         confirmed, already, expired, invalid.
	 */
	public static function follow( string $raw ): array {
		$parts = explode( '.', $raw, 2 );
		$uid   = (int) ( $parts[0] ?? 0 );
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $parts[1] ?? '' ) );

		// A wrong id, a wrong token and a user who never existed read the
		// same, so the link cannot be used to probe for accounts.
		if ( $uid < 1 || '' === $token || ! get_userdata( $uid ) ) {
			return [ 'invalid', 0 ];
		}

		$stored = (string) get_user_meta( $uid, self::META_TOKEN, true );

		if ( '' === self::pending( $uid ) ) {
			// Confirmed already. Said only when the token WAS theirs, so a
			// guessed id learns nothing.
			$spent = (string) get_user_meta( $uid, self::META_TOKEN . '_spent', true );
			return hash_equals( $spent, self::hash( $token ) ) && '' !== $spent ? [ 'already', $uid ] : [ 'invalid', 0 ];
		}

		if ( '' === $stored || ! hash_equals( $stored, self::hash( $token ) ) ) {
			return [ 'invalid', 0 ];
		}

		if ( (int) get_user_meta( $uid, self::META_EXPIRES, true ) < time() ) {
			return [ 'expired', $uid ];
		}

		// The address it was sent to must still be the account's address.
		$user = get_userdata( $uid );
		if ( strtolower( self::pending( $uid ) ) !== strtolower( (string) $user->user_email ) ) {
			return [ 'invalid', 0 ];
		}

		delete_user_meta( $uid, self::META_PENDING );
		delete_user_meta( $uid, self::META_TOKEN );
		delete_user_meta( $uid, self::META_EXPIRES );
		delete_user_meta( $uid, self::META_SENDS );
		update_user_meta( $uid, self::META_TOKEN . '_spent', self::hash( $token ) );
		update_user_meta( $uid, self::META_VERIFIED, time() );

		if ( class_exists( '\TDH\Log' ) ) {
			Log::info( 'members', 'verified', __( 'Email address confirmed.', 'thirtydayhomes' ), [], $uid, 'user' );
		}

		return [ 'confirmed', $uid ];
	}

	public function maybe_follow_link(): void {
		if ( ! isset( $_GET[ self::ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		[ $outcome, $uid ] = self::follow( sanitize_text_field( wp_unslash( (string) $_GET[ self::ARG ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$current = get_current_user_id();
		$account = Accounts::url( 'account' );
		$login   = Accounts::url( 'login' );

		switch ( $outcome ) {
			case 'confirmed':
				// Signs them in, per the card — only when nobody else is.
				if ( ! $current ) {
					wp_set_current_user( $uid );
					wp_set_auth_cookie( $uid, false );
					$current = $uid;
				}
				Accounts::notify( [], $current === $uid ? __( 'Email confirmed. You can now start a plan and submit homes.', 'thirtydayhomes' ) : __( 'That email address is confirmed. Sign in as its owner to continue.', 'thirtydayhomes' ) );
				$this->go( $account );
				break;

			case 'already':
				// "Your" only to its owner: someone else signed in on this
				// browser is told about "that" address, not their own.
				Accounts::notify( [], ! $current ? __( 'Your email address is already confirmed. Please sign in.', 'thirtydayhomes' ) : ( $current === $uid ? __( 'Your email address is already confirmed.', 'thirtydayhomes' ) : __( 'That email address is already confirmed.', 'thirtydayhomes' ) ) );
				$this->go( $current ? $account : $login );
				break;

			case 'expired':
				Accounts::notify( [ $current === $uid ? __( 'That link has expired. Press Send a new link below and use the newest email.', 'thirtydayhomes' ) : __( 'That link has expired. Sign in and press Send a new link on your dashboard.', 'thirtydayhomes' ) ] );
				$this->go( $current ? $account : $login );
				break;

			default:
				Accounts::notify( [ __( 'That link is not valid. If you asked for more than one, use the newest email.', 'thirtydayhomes' ) ] );
				$this->go( $current ? $account : $login );
		}
	}

	/* ---------------------------------------------------------------------
	 * Send a new link
	 * ------------------------------------------------------------------ */

	public function maybe_resend(): void {
		if ( ! isset( $_POST['tdh_action'] ) || self::ACTION !== sanitize_key( wp_unslash( (string) $_POST['tdh_action'] ) ) ) {
			return;
		}

		$user_id = get_current_user_id();
		$account = Accounts::url( 'account' );

		if ( ! $user_id ) {
			Accounts::notify( [ __( 'Please sign in first.', 'thirtydayhomes' ) ] );
			$this->go( Accounts::url( 'login' ) );
		}

		if ( ! isset( $_POST['tdh_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tdh_nonce'] ) ), self::NONCE ) ) {
			Accounts::notify( [ __( 'That page expired. Please press Send a new link again.', 'thirtydayhomes' ) ] );
			$this->go( $account );
		}

		if ( self::is_verified( $user_id ) ) {
			Accounts::notify( [], __( 'Your email address is already confirmed.', 'thirtydayhomes' ) );
			$this->go( $account );
		}

		$block = self::resend_block( $user_id );
		if ( '' !== $block ) {
			Accounts::notify( [ $block ] );
			$this->go( $account );
		}

		if ( self::send( $user_id ) ) {
			/* translators: %s: email address */
			Accounts::notify( [], sprintf( __( 'A new link is on its way to %s. The older links no longer work.', 'thirtydayhomes' ), self::pending( $user_id ) ) );
		} else {
			Accounts::notify( [ __( 'We could not send the email just now. Please try again in a minute.', 'thirtydayhomes' ) ] );
		}
		$this->go( $account );
	}

	private function go( string $to ): void {
		wp_safe_redirect( $to );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * What the landlord sees
	 * ------------------------------------------------------------------ */

	/** The one sentence, shared by the dashboard band and the pricing page. */
	public static function sentence( int $user_id ): string {
		/* translators: %s: email address */
		return sprintf( __( 'We sent a link to %s. Until you click it, you can look around but can’t start a plan or submit a home.', 'thirtydayhomes' ), self::pending( $user_id ) );
	}

	/** The dashboard band, or '' when there is nothing to confirm. */
	public static function band( int $user_id ): string {
		if ( self::is_verified( $user_id ) ) {
			return '';
		}
		$icon = function_exists( 'tdh_icon' ) ? tdh_icon( 'mail', 20 ) : '';
		ob_start();
		?>
		<section class="portal-alert portal-alert--pending portal-alert--verify" id="verify-email" role="status">
			<?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<span>
				<b><?php esc_html_e( 'Confirm your email address', 'thirtydayhomes' ); ?></b>
				<small><?php echo esc_html( self::sentence( $user_id ) ); ?> <?php esc_html_e( 'Wrong address? Change it in Account details.', 'thirtydayhomes' ); ?></small>
			</span>
			<form method="post" action="<?php echo esc_url( Accounts::url( 'account' ) ); ?>">
				<input type="hidden" name="tdh_action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::NONCE, 'tdh_nonce' ); ?>
				<button type="submit"><?php esc_html_e( 'Send a new link', 'thirtydayhomes' ); ?></button>
			</form>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
