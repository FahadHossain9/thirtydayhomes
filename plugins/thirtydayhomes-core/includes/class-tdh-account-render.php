<?php
/**
 * Account screen markup — sign up, sign in, reset, dashboard, profile.
 *
 * Separate from TDH\Render, which holds the marketing blocks. Same rule
 * applies: one implementation per block, shortcodes are the entry point.
 * The split is by subject, not by principle — Render was already six
 * hundred lines, and account forms are read alongside the handler in
 * TDH\Accounts far more often than alongside the hero.
 *
 * Field names and nonce actions must match TDH\Accounts exactly. They are
 * paired on purpose: a form whose markup lives in the theme could be
 * edited into a state the handler rejects, with no error anyone would see.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the landlord account screens.
 */
final class Account_Render {

	/** Matches the handler. Stated once so the two cannot drift. */
	private const MIN_PASSWORD = 12;

	/* ---------------------------------------------------------------------
	 * Shared parts
	 * ------------------------------------------------------------------ */

	/**
	 * Errors and confirmations left behind by the last submission.
	 *
	 * role="alert" so a screen reader announces the failure rather than
	 * leaving someone wondering why the page simply reloaded.
	 *
	 * @param array{errors:string[],success:string,values:array<string,string>} $notice
	 */
	private static function notices( array $notice ): void {

		if ( $notice['errors'] ) :
			?>
			<div class="form-notice form-notice--error" role="alert">
				<?php echo function_exists( 'tdh_icon' ) ? tdh_icon( 'shield-check', 18 ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<div>
					<?php foreach ( $notice['errors'] as $error ) : ?>
						<p><?php echo esc_html( (string) $error ); ?></p>
					<?php endforeach; ?>
				</div>
			</div>
			<?php
		endif;

		if ( '' !== $notice['success'] ) :
			?>
			<div class="form-notice form-notice--ok" role="status">
				<?php echo function_exists( 'tdh_icon' ) ? tdh_icon( 'check', 18 ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<p><?php echo esc_html( $notice['success'] ); ?></p>
			</div>
			<?php
		endif;

		/*
		 * A third state, not a green one. "We are confirming your payment"
		 * is neither a success nor a failure, and dressing it in the success
		 * colour would tell someone their plan is live seconds before they
		 * refresh and find it is not.
		 */
		if ( ! empty( $notice['info'] ) ) :
			?>
			<div class="form-notice form-notice--info" role="status">
				<?php echo function_exists( 'tdh_icon' ) ? tdh_icon( 'calendar-days', 18 ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<p><?php echo esc_html( (string) $notice['info'] ); ?></p>
			</div>
			<?php
		endif;
	}

	/**
	 * Wrap a sign-up / sign-in form in the split brand layout.
	 *
	 * The panel is not decoration. A form asking a stranger for a password
	 * has to say who is asking and why it is worth their time — a bare
	 * white card on a page with no header does neither, and it is the point
	 * in the whole site where someone is most likely to give up.
	 *
	 * @param string   $screen One of register, login, lost, reset.
	 * @param callable $form   Prints the form column.
	 */
	private static function shell( string $screen, callable $form ): string {

		$panels = [
			'register' => [
				'eyebrow' => __( 'For property owners', 'thirtydayhomes' ),
				'heading' => __( 'Your home, in front of the people looking for it.', 'thirtydayhomes' ),
				'points'  => [
					[ 'stethoscope', __( 'Renters who search by hospital', 'thirtydayhomes' ), __( 'Nurses and clinicians on 13-week assignments, comparing homes by the drive to work.', 'thirtydayhomes' ) ],
					[ 'shield-check', __( 'Every listing reviewed', 'thirtydayhomes' ), __( 'Homes are checked before they go live, so the ones that are published are trusted.', 'thirtydayhomes' ) ],
					[ 'key-round', __( 'Inquiries come straight to you', 'thirtydayhomes' ), __( 'No commission on the booking. You deal with the renter directly.', 'thirtydayhomes' ) ],
				],
			],
			'login'    => [
				'eyebrow' => __( 'Welcome back', 'thirtydayhomes' ),
				'heading' => __( 'Your listings and inquiries, where you left them.', 'thirtydayhomes' ),
				'points'  => [
					[ 'map-pinned', __( 'Manage your homes', 'thirtydayhomes' ), __( 'Edit details, pause a listing while it is occupied, bring it back when it is free.', 'thirtydayhomes' ) ],
					[ 'calendar-days', __( 'Keep availability current', 'thirtydayhomes' ), __( 'Renters filter by move-in date, so an accurate date is what gets you found.', 'thirtydayhomes' ) ],
				],
			],
			'lost'     => [
				'eyebrow' => __( 'Account recovery', 'thirtydayhomes' ),
				'heading' => __( 'It happens. Let’s get you back in.', 'thirtydayhomes' ),
				'points'  => [
					[ 'shield-check', __( 'The link expires in 24 hours', 'thirtydayhomes' ), __( 'And it can only be used once, so an old email in your inbox is not a way in.', 'thirtydayhomes' ) ],
				],
			],
			'reset'    => [
				'eyebrow' => __( 'Account recovery', 'thirtydayhomes' ),
				'heading' => __( 'Choose something you will remember.', 'thirtydayhomes' ),
				'points'  => [
					[ 'shield-check', __( 'Every other session ends', 'thirtydayhomes' ), __( 'Changing your password signs out every other device, in case someone else had access.', 'thirtydayhomes' ) ],
				],
			],
		];

		$panel = $panels[ $screen ] ?? $panels['login'];

		ob_start();
		?>
		<div class="auth">

			<aside class="auth-brand">
				<div class="auth-brand-inner">

					<?php if ( function_exists( 'tdh_the_logo' ) ) : ?>
						<a class="auth-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
							<?php tdh_the_logo(); ?>
						</a>
					<?php endif; ?>

					<div class="auth-pitch">
						<p class="overline"><?php echo esc_html( $panel['eyebrow'] ); ?></p>
						<h2><?php echo esc_html( $panel['heading'] ); ?></h2>
					</div>

					<ul class="auth-points">
						<?php foreach ( $panel['points'] as [ $icon, $title, $copy ] ) : ?>
							<li>
								<i><?php echo function_exists( 'tdh_icon' ) ? tdh_icon( $icon, 19 ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
								<span>
									<b><?php echo esc_html( $title ); ?></b>
									<small><?php echo esc_html( $copy ); ?></small>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>

				</div>
			</aside>

			<div class="auth-panel">
				<div class="auth-panel-inner">
					<?php
					// In the form column, not the brand panel: it belongs
					// with the content someone is acting on.
					if ( function_exists( 'tdh_the_breadcrumb' ) ) {
						tdh_the_breadcrumb();
					}
					?>
					<?php $form(); ?>
				</div>
			</div>

		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The hidden pair every account form needs.
	 */
	private static function form_head( string $action ): void {
		?>
		<input type="hidden" name="tdh_action" value="<?php echo esc_attr( $action ); ?>">
		<?php wp_nonce_field( 'tdh_' . $action, 'tdh_nonce' ); ?>
		<?php
	}

	/**
	 * A field's previously submitted value, so a failed form is not blanked.
	 *
	 * @param array<string,string> $values
	 */
	private static function old( array $values, string $key ): string {
		return (string) ( $values[ $key ] ?? '' );
	}

	/**
	 * Shown instead of a form when someone is already signed in.
	 */
	private static function already_in(): string {

		$user = wp_get_current_user();

		// Wrapped in .auth-simple, which supplies the page padding and
		// centring. The auth screens bypass the page template, so anything
		// rendered here without its own shell lands flush against the
		// header with the footer pulled up under it.
		ob_start();
		?>
		<div class="auth-simple">
			<div class="form-card">
				<div class="form-intro">
					<h1><?php esc_html_e( 'You are already signed in', 'thirtydayhomes' ); ?></h1>
					<p class="muted">
						<?php
						printf(
							/* translators: %s: display name */
							esc_html__( 'Signed in as %s.', 'thirtydayhomes' ),
							'<strong>' . esc_html( $user->display_name ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput
						);
						?>
					</p>
				</div>

				<p class="form-actions-inline">
					<a class="primary" href="<?php echo esc_url( Accounts::url( 'account' ) ); ?>"><?php esc_html_e( 'Go to your dashboard', 'thirtydayhomes' ); ?></a>
					<a class="secondary" href="<?php echo esc_url( Accounts::logout_url() ); ?>"><?php esc_html_e( 'Sign out', 'thirtydayhomes' ); ?></a>
				</p>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Registration
	 * ------------------------------------------------------------------ */

	public static function register(): string {

		if ( is_user_logged_in() ) {
			return self::already_in();
		}

		$notice = Accounts::take_notice();
		$values = $notice['values'];

		return self::shell(
			'register',
			static function () use ( $notice, $values ) {
				?>
			<div class="form-intro">
				<h1><?php esc_html_e( 'Create your account', 'thirtydayhomes' ); ?></h1>
				<p class="muted"><?php esc_html_e( 'Free to open. You only pay when you are ready to publish.', 'thirtydayhomes' ); ?></p>
			</div>

			<?php self::notices( $notice ); ?>

			<form method="post" action="">
				<?php self::form_head( 'register' ); ?>

				<div class="form-field">
					<label for="tdh-name"><?php esc_html_e( 'Your name', 'thirtydayhomes' ); ?></label>
					<input id="tdh-name" name="tdh_name" type="text" autocomplete="name" required
						value="<?php echo esc_attr( self::old( $values, 'tdh_name' ) ); ?>">
				</div>

				<div class="form-field">
					<label for="tdh-email"><?php esc_html_e( 'Email address', 'thirtydayhomes' ); ?></label>
					<input id="tdh-email" name="tdh_email" type="email" autocomplete="email" required
						value="<?php echo esc_attr( self::old( $values, 'tdh_email' ) ); ?>">
					<small><?php esc_html_e( 'You will sign in with this, and renter inquiries are sent here.', 'thirtydayhomes' ); ?></small>
				</div>

				<div class="form-grid">
					<div class="form-field">
						<label for="tdh-phone">
							<?php esc_html_e( 'Phone', 'thirtydayhomes' ); ?>
							<span class="label-note"><?php esc_html_e( '(optional)', 'thirtydayhomes' ); ?></span>
						</label>
						<input id="tdh-phone" name="tdh_phone" type="tel" autocomplete="tel"
							value="<?php echo esc_attr( self::old( $values, 'tdh_phone' ) ); ?>">
					</div>

					<div class="form-field">
						<label for="tdh-company">
							<?php esc_html_e( 'Company', 'thirtydayhomes' ); ?>
							<span class="label-note"><?php esc_html_e( '(optional)', 'thirtydayhomes' ); ?></span>
						</label>
						<input id="tdh-company" name="tdh_company" type="text" autocomplete="organization"
							value="<?php echo esc_attr( self::old( $values, 'tdh_company' ) ); ?>">
					</div>
				</div>

				<div class="form-field">
					<label for="tdh-password"><?php esc_html_e( 'Password', 'thirtydayhomes' ); ?></label>
					<input id="tdh-password" name="tdh_password" type="password" autocomplete="new-password"
						required minlength="<?php echo esc_attr( (string) self::MIN_PASSWORD ); ?>">
					<?php
					// The one hint kept visible. Stated before typing it
					// prevents a rejected submission; discovered afterwards
					// it is an error message someone already earned.
					?>
					<small class="form-hint--show">
						<?php
						printf(
							/* translators: %d: minimum password length */
							esc_html__( 'At least %d characters.', 'thirtydayhomes' ),
							(int) self::MIN_PASSWORD
						);
						?>
					</small>
				</div>

				<?php
				/*
				 * Honeypot. Hidden from people, tempting to bots. tabindex -1
				 * and aria-hidden keep it out of the keyboard path and the
				 * screen-reader tree, so it costs a real user nothing.
				 */
				?>
				<div class="tdh-hp" aria-hidden="true">
					<label for="tdh-website"><?php esc_html_e( 'Leave this field empty', 'thirtydayhomes' ); ?></label>
					<input id="tdh-website" name="tdh_website" type="text" tabindex="-1" autocomplete="off">
				</div>

				<div class="form-field form-field--check">
					<label for="tdh-terms">
						<input id="tdh-terms" name="tdh_terms" type="checkbox" value="1" required>
						<span>
							<?php
							printf(
								/* translators: 1: terms link, 2: fair housing link */
								esc_html__( 'I accept the %1$s and confirm my listings will follow %2$s rules.', 'thirtydayhomes' ),
								'<a href="' . esc_url( Accounts::url( 'terms' ) ) . '">' . esc_html__( 'Terms of Service', 'thirtydayhomes' ) . '</a>', // phpcs:ignore WordPress.Security.EscapeOutput
								'<a href="' . esc_url( Accounts::url( 'fair-housing' ) ) . '">' . esc_html__( 'Fair Housing', 'thirtydayhomes' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput
							);
							?>
						</span>
					</label>
				</div>

				<button class="primary full big" type="submit">
					<?php esc_html_e( 'Create account', 'thirtydayhomes' ); ?>
				</button>
			</form>

			<p class="form-alt">
				<?php esc_html_e( 'Already have an account?', 'thirtydayhomes' ); ?>
				<a href="<?php echo esc_url( Accounts::url( 'login' ) ); ?>"><?php esc_html_e( 'Sign in', 'thirtydayhomes' ); ?></a>
			</p>
				<?php
			}
		);
	}

	/* ---------------------------------------------------------------------
	 * Sign in
	 * ------------------------------------------------------------------ */

	public static function login(): string {

		if ( is_user_logged_in() ) {
			return self::already_in();
		}

		$notice = Accounts::take_notice();
		$values = $notice['values'];

		/*
		 * Signing out lands here rather than on the home page, and says so.
		 * Only when there is nothing else to report — a real message about
		 * what just happened always outranks the summary of what happened
		 * before it.
		 */
		if ( isset( $_GET['signed_out'] ) && '' === $notice['success'] && ! $notice['errors'] ) {
			$notice['success'] = __( 'You have been signed out.', 'thirtydayhomes' );
		}

		// Carried through so someone bounced off a protected page lands back
		// on it. esc_url_raw plus wp_safe_redirect in the handler keeps this
		// from becoming an open redirect.
		$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_GET['redirect_to'] ) ) : '';

		return self::shell(
			'login',
			static function () use ( $notice, $values, $redirect ) {
				?>
			<div class="form-intro">
				<h1><?php esc_html_e( 'Sign in', 'thirtydayhomes' ); ?></h1>
				<p class="muted"><?php esc_html_e( 'Manage your listings and inquiries.', 'thirtydayhomes' ); ?></p>
			</div>

			<?php self::notices( $notice ); ?>

			<form method="post" action="">
				<?php self::form_head( 'login' ); ?>
				<input type="hidden" name="tdh_redirect_to" value="<?php echo esc_attr( $redirect ); ?>">

				<div class="form-field">
					<?php
					// Email OR username: WordPress signs in with either, and a landlord
					// who knows theirs as "testuser24" was being turned away by an
					// email-only box before the server ever saw it.
					?>
					<label for="tdh-login-email"><?php esc_html_e( 'Email or username', 'thirtydayhomes' ); ?></label>
					<input id="tdh-login-email" name="tdh_email" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required
						value="<?php echo esc_attr( self::old( $values, 'tdh_email' ) ); ?>">
				</div>

				<div class="form-field">
					<label for="tdh-login-password"><?php esc_html_e( 'Password', 'thirtydayhomes' ); ?></label>
					<input id="tdh-login-password" name="tdh_password" type="password" autocomplete="current-password" required>
				</div>

				<div class="form-field form-field--check">
					<label for="tdh-remember">
						<input id="tdh-remember" name="tdh_remember" type="checkbox" value="1">
						<span><?php esc_html_e( 'Keep me signed in', 'thirtydayhomes' ); ?></span>
					</label>
				</div>

				<button class="primary full big" type="submit"><?php esc_html_e( 'Sign in', 'thirtydayhomes' ); ?></button>
			</form>

			<p class="form-alt">
				<a href="<?php echo esc_url( Accounts::url( 'lost-password' ) ); ?>"><?php esc_html_e( 'Forgotten your password?', 'thirtydayhomes' ); ?></a>
			</p>
			<p class="form-alt">
				<?php esc_html_e( 'New here?', 'thirtydayhomes' ); ?>
				<a href="<?php echo esc_url( Accounts::url( 'register' ) ); ?>"><?php esc_html_e( 'Create a landlord account', 'thirtydayhomes' ); ?></a>
			</p>
				<?php
			}
		);
	}

	/* ---------------------------------------------------------------------
	 * Password reset
	 * ------------------------------------------------------------------ */

	public static function lost_password(): string {

		$notice = Accounts::take_notice();

		return self::shell(
			'lost',
			static function () use ( $notice ) {
				if ( '' !== $notice['success'] ) {
					?>
					<div class="password-reset-sent" role="status">
						<i aria-hidden="true"><?php echo function_exists( 'tdh_icon' ) ? tdh_icon( 'circle-check', 24 ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
						<p class="overline"><?php esc_html_e( 'Password recovery', 'thirtydayhomes' ); ?></p>
						<h1><?php esc_html_e( 'Check your email', 'thirtydayhomes' ); ?></h1>
						<p><?php echo esc_html( $notice['success'] ); ?></p>
						<p class="muted"><?php esc_html_e( 'Open the message from ThirtyDayHomes and use the secure link to choose a new password. Check your spam folder if it does not arrive within a few minutes.', 'thirtydayhomes' ); ?></p>
						<a class="primary full big" href="<?php echo esc_url( Accounts::url( 'login' ) ); ?>"><?php esc_html_e( 'Return to sign in', 'thirtydayhomes' ); ?></a>
						<a class="password-reset-again" href="<?php echo esc_url( Accounts::url( 'lost-password' ) ); ?>"><?php esc_html_e( 'Send another reset link', 'thirtydayhomes' ); ?></a>
					</div>
					<?php
					return;
				}
				?>
			<div class="form-intro">
				<h1><?php esc_html_e( 'Reset your password', 'thirtydayhomes' ); ?></h1>
				<p class="muted"><?php esc_html_e( 'Enter your email address and we will send you a link.', 'thirtydayhomes' ); ?></p>
			</div>

			<?php self::notices( $notice ); ?>

			<form method="post" action="">
				<?php self::form_head( 'lost_password' ); ?>

				<div class="form-field">
					<label for="tdh-lost-email"><?php esc_html_e( 'Email address', 'thirtydayhomes' ); ?></label>
					<input id="tdh-lost-email" name="tdh_email" type="email" autocomplete="email" required>
				</div>

				<button class="primary full big" type="submit"><?php esc_html_e( 'Send reset link', 'thirtydayhomes' ); ?></button>
			</form>

			<p class="form-alt">
				<a href="<?php echo esc_url( Accounts::url( 'login' ) ); ?>"><?php esc_html_e( 'Back to sign in', 'thirtydayhomes' ); ?></a>
			</p>
				<?php
			}
		);
	}

	public static function reset_password(): string {

		$notice = Accounts::take_notice();

		$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['key'] ) ) : '';
		$login = isset( $_GET['login'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['login'] ) ) : '';

		return self::shell(
			'reset',
			static function () use ( $notice, $key, $login ) {
				?>
			<div class="form-intro">
				<h1><?php esc_html_e( 'Choose a new password', 'thirtydayhomes' ); ?></h1>
			</div>

			<?php self::notices( $notice ); ?>

			<?php if ( '' === $key || '' === $login ) : ?>

				<p class="muted"><?php esc_html_e( 'This page needs the link from your reset email. Request a new one if the link has expired.', 'thirtydayhomes' ); ?></p>
				<p class="form-actions-inline">
					<a class="primary" href="<?php echo esc_url( Accounts::url( 'lost-password' ) ); ?>"><?php esc_html_e( 'Request a reset link', 'thirtydayhomes' ); ?></a>
				</p>

			<?php else : ?>

				<form method="post" action="">
					<?php self::form_head( 'reset_password' ); ?>
					<input type="hidden" name="tdh_key" value="<?php echo esc_attr( $key ); ?>">
					<input type="hidden" name="tdh_login" value="<?php echo esc_attr( $login ); ?>">

					<div class="form-field">
						<label for="tdh-new-password"><?php esc_html_e( 'New password', 'thirtydayhomes' ); ?></label>
						<input id="tdh-new-password" name="tdh_password" type="password" autocomplete="new-password"
							required minlength="<?php echo esc_attr( (string) self::MIN_PASSWORD ); ?>">
					</div>

					<div class="form-field">
						<label for="tdh-new-password-2"><?php esc_html_e( 'Confirm new password', 'thirtydayhomes' ); ?></label>
						<input id="tdh-new-password-2" name="tdh_password_confirm" type="password" autocomplete="new-password"
							required minlength="<?php echo esc_attr( (string) self::MIN_PASSWORD ); ?>">
					</div>

					<p class="muted form-fine"><?php esc_html_e( 'Changing your password signs you out everywhere else.', 'thirtydayhomes' ); ?></p>

					<button class="primary full big" type="submit"><?php esc_html_e( 'Save new password', 'thirtydayhomes' ); ?></button>
				</form>

			<?php endif; ?>
				<?php
			}
		);
	}

	/* ---------------------------------------------------------------------
	 * Dashboard
	 * ------------------------------------------------------------------ */

	public static function dashboard(): string {

		if ( ! is_user_logged_in() ) {
			return self::sign_in_wall( __( 'Sign in to see your dashboard.', 'thirtydayhomes' ) );
		}

		/*
		 * Staff get the marketplace, not a landlord's cockpit. The approved
		 * design draws a third portal for the administrator — members,
		 * queue, membership health — and showing the owner a "No active
		 * plan, choose a plan" banner on their own site is the design
		 * telling them a lie about who they are.
		 *
		 * is_staff(), not manage_options: the client-review "Administrator"
		 * persona runs the marketplace without WordPress-takeover rights,
		 * and it must see this portal too.
		 */
		if ( Accounts::is_staff() ) {
			return self::marketplace();
		}

		$user     = wp_get_current_user();
		$user_id  = (int) $user->ID;
		$notice   = Accounts::take_notice();

		/*
		 * Someone returning from Stripe. This reads the real membership
		 * rather than the URL, so pasting ?tdh_checkout=success into the
		 * address bar is told accurately that nothing is active — and a
		 * genuine payment whose webhook has not landed yet is told to wait
		 * rather than being shown a failure.
		 */
		$checkout = Billing\Checkout::return_notice();

		if ( null !== $checkout ) {
			if ( 'success' === $checkout[0] ) {
				$notice['success'] = $checkout[1];
			} else {
				$notice['info'] = $checkout[1];
			}
		}

		if ( isset( $_GET['tdh_portal_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$reason   = sanitize_key( wp_unslash( (string) $_GET['tdh_portal_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$messages = Billing\Customer_Portal::messages();

			if ( isset( $messages[ $reason ] ) ) {
				$notice['errors'][] = $messages[ $reason ];
			}
		}

		/*
		 * Someone returning from the listing wizard. A boolean flag, not a
		 * message from the URL — the copy lives here, so the query string
		 * cannot be edited into saying something else.
		 */
		if ( isset( $_GET['tdh_submitted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$notice['success'] = __( 'Your listing is submitted. A person reviews it — usually within one business day — and it goes live from there.', 'thirtydayhomes' );
		}

		// Back from Pause, Resume or Delete. Flag and id; the words are the
		// handler's own, and the name only shows for a home this user owns.
		if ( isset( $_GET['tdh_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$done = Listing_Actions::notice(
				sanitize_key( wp_unslash( (string) $_GET['tdh_done'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				(int) ( $_GET['tdh_home'] ?? 0 ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			);

			if ( $done ) {
				if ( 'error' === $done['type'] ) {
					$notice['errors'][] = $done['text'];
				} else {
					$notice[ $done['type'] ] = $done['text'];
				}
			}
		}

		$status   = Membership::status( $user_id );
		$labels   = Membership::labels();
		$quota    = Membership::quota( $user_id );
		$used     = Membership::listing_count( $user_id );
		$expires  = Membership::expires( $user_id );

		$icon = static fn( string $name, int $size = 19 ): string =>
			function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size ) : '';

		/*
		 * ─── THE PORTAL LAYOUT ─────────────────────────────────────────
		 *
		 * The approved prototype draws the dashboard as a portal: a navy
		 * sidebar, a white top bar, four metric tiles, two panels. This is
		 * that layout — with one deliberate difference from the prototype.
		 *
		 * Every number here is TRUE. The prototype shows "Listing views
		 * 284" because a prototype may invent; a live dashboard may not,
		 * because a landlord prices and pauses off these figures. So the
		 * views tile waited until TDH\Views existed to make it real, the
		 * inquiry panel reads actual inquiry records, and the counts come
		 * from the same queries the rest of the plugin trusts.
		 *
		 * "Add property" asks the wizard's own gate where it should go: the
		 * wizard when it would let this person in (members with room, and
		 * staff — the allowance is a billing rule, staff are not billed),
		 * the plans page otherwise. One gate, one answer — a duplicated
		 * quota comparison here once sent administrators to the pricing
		 * page while the wizard itself would have let them through.
		 */

		/*
		 * Which screen of the portal is open. Real screens, not anchors —
		 * the nav originally pointed at #sections of this one page, and on
		 * a display tall enough to show everything, clicking them moved
		 * nothing: three menu items that read as broken empty pages.
		 */
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $view, [ 'overview', 'listings', 'inquiries', 'membership', 'profile' ], true ) ) {
			$view = 'overview';
		}

		$live     = self::count_listings( $user_id, [ 'publish' ] );
		$pending  = self::count_listings( $user_id, [ 'pending' ] );
		$views    = Views::total_for_author( $user_id );

		/*
		 * Opening a message marks it read BEFORE anything is counted.
		 *
		 * The nav badge and the tab count are worked out here, at the top
		 * of the render; the detail view runs further down. Marking read
		 * there left the badge counting a message the landlord was looking
		 * at — 18 in the nav, 17 in the list — until they navigated again.
		 * The same disagreement the counted badge above was fixed for.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$opening = 'inquiries' === $view && isset( $_GET[ Inquiry::PARAM_OPEN ] ) ? (int) $_GET[ Inquiry::PARAM_OPEN ] : 0;

		if ( $opening > 0 && Inquiry::can_read( $opening, $user_id ) ) {
			Inquiry::mark_read( $opening );
		}

		/*
		 * The overview shows a taste; the Inquiries screen shows a page of
		 * the inbox with its own tabs and paging.
		 *
		 * The unread number is COUNTED, not derived from the rows fetched.
		 * It used to be `count( array_filter( $inquiries ) )` over the four
		 * rows the overview had loaded, so a landlord with thirty unread
		 * messages was shown a badge reading 4 — the tile and the screen
		 * disagreed, and the tile was always the one that was wrong.
		 */
		$unread    = Inquiry::unread_count( $user_id );
		$inquiries = self::inquiries_for( $user_id, 4 );

		$initials = strtoupper( mb_substr( trim( $user->display_name ), 0, 2 ) );

		$copy = [
			Membership::NONE      => __( 'Choose a plan to publish your first home. Nothing is charged until you do.', 'thirtydayhomes' ),
			// No city here: this line is shown to every landlord, and the
			// owner lists in more than one market.
			Membership::ACTIVE    => __( 'Your listings are visible to renters searching your area.', 'thirtydayhomes' ),
			// Only the fallback: Enforcement::band() says which it is — still
			// visible during the grace period, or hidden after it.
			Membership::PAST_DUE  => __( 'A payment failed. Update your card to keep your listings visible — nothing is deleted.', 'thirtydayhomes' ),
			Membership::CANCELLED => __( 'Your membership runs to the end of the paid period, then your listings come down.', 'thirtydayhomes' ),
			Membership::EXPIRED   => __( 'Your membership has ended and your listings are hidden. Restart a plan to bring them back.', 'thirtydayhomes' ),
		];

		/*
		 * What the membership means for the homes right now (E1), with a
		 * date or a count, and the one next step. Shown on EVERY screen
		 * while homes are in grace or hidden: a landlord who opens My
		 * listings and finds their homes gone must not have to visit the
		 * overview to learn why.
		 */
		$band   = Enforcement::band( $user_id );
		$urgent = null !== $band && in_array( $band['state'], [ Enforcement::GRACE, Enforcement::HOLD ], true );

		// Once, after a payment brought hidden homes back.
		$restored = Enforcement::take_restored( $user_id );

		if ( '' !== $restored ) {
			$notice['success'] = $restored;
		}

		$add_url = '' === Listing_Form::gate_reason()
			? Listing_Form::url()
			: Accounts::url( 'pricing' );

		ob_start();
		?>
		<div class="portal">

			<aside class="portal-side" id="portal-sidebar">
				<a class="portal-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<?php
					if ( function_exists( 'tdh_the_logo' ) ) {
						tdh_the_logo();
					} else {
						echo '<b>' . esc_html( get_bloginfo( 'name' ) ) . '</b>';
					}
					?>
				</a>

				<p class="portal-side-label"><?php esc_html_e( 'Landlord portal', 'thirtydayhomes' ); ?></p>

				<?php
				$nav = [
					'overview'   => [ 'layout-dashboard', __( 'Overview', 'thirtydayhomes' ) ],
					'listings'   => [ 'building-2', __( 'My listings', 'thirtydayhomes' ) ],
					'inquiries'  => [ 'mail', __( 'Inquiries', 'thirtydayhomes' ) ],
					'membership' => [ 'wallet-cards', __( 'Membership', 'thirtydayhomes' ) ],
					'profile'    => [ 'user', __( 'Account details', 'thirtydayhomes' ) ],
				];
				?>
				<nav class="portal-nav" aria-label="<?php esc_attr_e( 'Dashboard', 'thirtydayhomes' ); ?>">
					<?php foreach ( $nav as $slug => [ $nav_icon, $label ] ) : ?>
						<a class="<?php echo $view === $slug ? 'is-current' : ''; ?>"
							title="<?php echo esc_attr( $label ); ?>"
							href="<?php echo esc_url( 'overview' === $slug ? Accounts::url( 'account' ) : add_query_arg( 'view', $slug, Accounts::url( 'account' ) ) ); ?>">
							<?php echo $icon( $nav_icon, 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php echo esc_html( $label ); ?>
							<?php if ( 'inquiries' === $slug && $unread > 0 ) : ?>
								<em class="portal-count"><?php echo esc_html( number_format_i18n( $unread ) ); ?></em>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
				</nav>

				<a class="portal-return" title="<?php esc_attr_e( 'Public website', 'thirtydayhomes' ); ?>" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<?php echo $icon( 'arrow-left', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php esc_html_e( 'Public website', 'thirtydayhomes' ); ?>
				</a>
			</aside>

			<div class="portal-main" id="overview">

				<div class="portal-top">
					<div class="portal-top-context">
						<button class="portal-menu-toggle" type="button" aria-controls="portal-sidebar" aria-expanded="true" aria-label="<?php esc_attr_e( 'Collapse dashboard sidebar', 'thirtydayhomes' ); ?>">
							<span class="portal-toggle-collapse"><?php echo $icon( 'chevron-left', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
							<span class="portal-toggle-expand"><?php echo $icon( 'chevron-right', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						</button>
						<span>
							<?php
							printf(
								/* translators: %s: display name */
								esc_html__( 'Welcome back, %s', 'thirtydayhomes' ),
								esc_html( $user->display_name )
							);
							?>
						</span>
					</div>
					<div>
						<a class="portal-signout" href="<?php echo esc_url( Accounts::logout_url() ); ?>"><?php esc_html_e( 'Sign out', 'thirtydayhomes' ); ?></a>
						<i class="portal-avatar" aria-hidden="true"><?php echo esc_html( $initials ); ?></i>
					</div>
				</div>

				<div class="portal-body">

					<?php
					$headings = [
						'overview'   => [ __( 'Dashboard', 'thirtydayhomes' ), __( 'Here’s what’s happening with your properties.', 'thirtydayhomes' ) ],
						'listings'   => [ __( 'My listings', 'thirtydayhomes' ), __( 'Every home on your account, in every status.', 'thirtydayhomes' ) ],
						'inquiries'  => [ __( 'Inquiries', 'thirtydayhomes' ), __( 'Renters who asked about your homes.', 'thirtydayhomes' ) ],
						'membership' => [ __( 'Membership', 'thirtydayhomes' ), __( 'Your plan, allowance and renewal.', 'thirtydayhomes' ) ],
						'profile'    => [ __( 'Account details', 'thirtydayhomes' ), __( 'Manage your contact information and sign-in security.', 'thirtydayhomes' ) ],
					];
					?>
					<div class="portal-heading">
						<span>
							<h1><?php echo esc_html( $headings[ $view ][0] ); ?></h1>
							<p><?php echo esc_html( $headings[ $view ][1] ); ?></p>
						</span>
						<?php if ( in_array( $view, [ 'overview', 'listings' ], true ) ) : ?>
							<a class="primary" href="<?php echo esc_url( $add_url ); ?>">
								<?php echo $icon( 'plus', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php esc_html_e( 'Add property', 'thirtydayhomes' ); ?>
							</a>
						<?php endif; ?>
					</div>

					<?php self::notices( $notice ); ?>

					<?php echo Email_Verification::band( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. F1: on every view until confirmed. ?>

					<?php if ( in_array( $view, [ 'overview', 'membership' ], true ) || $urgent ) : ?>
					<?php
					/*
					 * The membership band. One panel, not a banner AND a
					 * card — the two said the same thing above one another
					 * and read as a layout accident.
					 */
					?>
					<section class="portal-alert portal-alert--<?php echo esc_attr( Membership::badge_class( $status ) ); ?>" id="membership"<?php echo $urgent ? ' role="status"' : ''; ?>>
						<?php echo $icon( 'wallet-cards', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<span>
							<b><?php echo esc_html( $labels[ $status ] ?? $status ); ?></b>
							<small><?php echo esc_html( null !== $band ? $band['text'] : ( $copy[ $status ] ?? '' ) ); ?></small>
						</span>

						<?php if ( null !== $band && Membership::PAST_DUE === $status && \TDH\Billing\Customer_Portal::is_ready( $user_id ) ) : ?>
							<?php // Straight to Stripe's card form; a failure returns to the membership screen, which says why. ?>
							<form method="post" action="<?php echo esc_url( add_query_arg( 'view', 'membership', Accounts::url( 'account' ) ) ); ?>">
								<?php echo \TDH\Billing\Customer_Portal::form_fields(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<button type="submit"><?php echo esc_html( $band['action'] ); ?></button>
							</form>
						<?php elseif ( null !== $band ) : ?>
							<a href="<?php echo esc_url( $band['url'] ); ?>"><?php echo esc_html( $band['action'] ); ?></a>
						<?php elseif ( Membership::ACTIVE === $status ) : ?>
							<span class="portal-plan-tier">
								<small><?php esc_html_e( 'Current plan', 'thirtydayhomes' ); ?></small>
								<b><?php echo esc_html( self::plan_label( $user_id ) ); ?></b>
							</span>
							<span class="portal-renews">
								<?php
								printf(
									/* translators: %s: renewal date */
									esc_html__( 'Renews %s', 'thirtydayhomes' ),
									esc_html( $expires ? date_i18n( 'j M Y', $expires ) : __( 'soon', 'thirtydayhomes' ) )
								);
								?>
							</span>
						<?php else : ?>
							<a href="<?php echo esc_url( Accounts::url( 'pricing' ) ); ?>">
								<?php echo Membership::NONE === $status ? esc_html__( 'Choose plan', 'thirtydayhomes' ) : esc_html__( 'Manage billing', 'thirtydayhomes' ); ?>
							</a>
						<?php endif; ?>
					</section>
					<?php endif; ?>

					<?php if ( 'overview' === $view ) : ?>
					<div class="portal-metrics">
						<div class="portal-metric">
							<i><?php echo $icon( 'building-2' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
							<span>
								<small><?php esc_html_e( 'Live listings', 'thirtydayhomes' ); ?></small>
								<b><?php echo esc_html( number_format_i18n( $live ) ); ?><em><?php echo esc_html( '/' . number_format_i18n( $quota ) ); ?></em></b>
							</span>
						</div>
						<div class="portal-metric">
							<i><?php echo $icon( 'clock-3' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
							<span>
								<small><?php esc_html_e( 'Pending review', 'thirtydayhomes' ); ?></small>
								<b><?php echo esc_html( number_format_i18n( $pending ) ); ?></b>
							</span>
						</div>
						<div class="portal-metric">
							<i><?php echo $icon( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
							<span>
								<small><?php esc_html_e( 'New inquiries', 'thirtydayhomes' ); ?></small>
								<b><?php echo esc_html( number_format_i18n( $unread ) ); ?></b>
							</span>
						</div>
						<div class="portal-metric">
							<i><?php echo $icon( 'eye' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
							<span>
								<small><?php esc_html_e( 'Listing views', 'thirtydayhomes' ); ?></small>
								<b><?php echo esc_html( number_format_i18n( $views ) ); ?></b>
							</span>
						</div>
					</div>

					<div class="portal-columns">
						<?php self::listings_panel( $user_id, $used, $quota, 'overview' ); ?>
						<?php self::inquiries_panel( $inquiries ); ?>
					</div>

					<?php elseif ( 'listings' === $view ) : ?>
						<?php self::listings_panel( $user_id, $used, $quota, 'listings' ); ?>

					<?php elseif ( 'inquiries' === $view ) : ?>
						<?php self::inquiries_screen( $user_id ); ?>

					<?php elseif ( 'membership' === $view ) : ?>
						<div class="panel">
							<div class="panel-title">
								<h3><?php esc_html_e( 'Plan details', 'thirtydayhomes' ); ?></h3>
							</div>
							<div class="portal-health">
								<div>
									<small><?php esc_html_e( 'Plan', 'thirtydayhomes' ); ?></small>
									<b><?php echo esc_html( self::plan_label( $user_id ) ); ?></b>
								</div>
								<div>
									<small><?php esc_html_e( 'Listings used', 'thirtydayhomes' ); ?></small>
									<b>
										<?php
										echo esc_html(
											$quota > 0
												? ( $used > $quota ? Membership::usage( $used, $quota ) : number_format_i18n( $used ) . ' / ' . number_format_i18n( $quota ) )
												: number_format_i18n( $used )
										);
										?>
									</b>
								</div>
								<div>
									<small>
										<?php echo Membership::CANCELLED === $status ? esc_html__( 'Ends', 'thirtydayhomes' ) : esc_html__( 'Renews', 'thirtydayhomes' ); ?>
									</small>
									<b><?php echo esc_html( $expires ? date_i18n( 'j M Y', $expires ) : '—' ); ?></b>
								</div>
							</div>
							<?php if ( in_array( $status, [ Membership::ACTIVE, Membership::PAST_DUE ], true ) ) : ?>
								<form class="portal-billing-actions" method="post" action="<?php echo esc_url( add_query_arg( 'view', 'membership', Accounts::url( 'account' ) ) ); ?>">
									<?php echo \TDH\Billing\Customer_Portal::form_fields(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<button class="secondary" type="submit"><?php esc_html_e( 'Manage or cancel membership', 'thirtydayhomes' ); ?></button>
									<small>
										<?php
										echo esc_html(
											\TDH\Billing\Customer_Portal::is_ready( $user_id )
												? __( 'Cancellation takes effect according to your billing period in Stripe.', 'thirtydayhomes' )
												: __( 'If online billing is unavailable, support can update this membership.', 'thirtydayhomes' )
										);
										?>
									</small>
								</form>
							<?php endif; ?>
						</div>
					<?php elseif ( 'profile' === $view ) : ?>
						<?php self::landlord_profile( $user ); ?>
					<?php endif; ?>

				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** Human-facing membership tier, never a database slug. */
	private static function plan_label( int $user_id ): string {
		$plan = trim( Membership::plan( $user_id ) );
		return '' === $plan
			? __( 'No plan selected', 'thirtydayhomes' )
			: ucwords( str_replace( [ '-', '_' ], ' ', $plan ) );
	}

	/** Account editing belongs inside the landlord portal shell. */
	private static function landlord_profile( \WP_User $user ): void {
		?>
		<form class="panel portal-profile" method="post" action="">
			<?php self::form_head( 'profile' ); ?>
			<div class="panel-title">
				<span><h3><?php esc_html_e( 'Personal information', 'thirtydayhomes' ); ?></h3><p><?php esc_html_e( 'Used for your account and renter inquiries.', 'thirtydayhomes' ); ?></p></span>
			</div>
			<div class="form-grid">
				<div class="form-field"><label for="tdh-p-name"><?php esc_html_e( 'Your name', 'thirtydayhomes' ); ?></label><input id="tdh-p-name" name="tdh_name" type="text" autocomplete="name" required value="<?php echo esc_attr( $user->display_name ); ?>"></div>
				<div class="form-field"><label for="tdh-p-email"><?php esc_html_e( 'Email address', 'thirtydayhomes' ); ?></label><input id="tdh-p-email" name="tdh_email" type="email" autocomplete="email" required value="<?php echo esc_attr( $user->user_email ); ?>"></div>
				<?php
				/*
				 * The phone field moved to the Text message alerts card
				 * below when texting arrived (D4). One number, one place to
				 * change it, and it sits next to the sentence that explains
				 * why the site wants it. Without texting the card still
				 * shows, greyed, so the number is never orphaned.
				 */
				?>
				<div class="form-field"><label for="tdh-p-company"><?php esc_html_e( 'Company (optional)', 'thirtydayhomes' ); ?></label><input id="tdh-p-company" name="tdh_company" type="text" autocomplete="organization" value="<?php echo esc_attr( (string) get_user_meta( $user->ID, '_tdh_company', true ) ); ?>"></div>
			</div>
			<hr>
			<div class="panel-title"><span><h3><?php esc_html_e( 'Password and security', 'thirtydayhomes' ); ?></h3><p><?php esc_html_e( 'Leave these blank unless you want to change your password.', 'thirtydayhomes' ); ?></p></span></div>
			<div class="form-grid">
				<div class="form-field"><label for="tdh-p-current"><?php esc_html_e( 'Current password', 'thirtydayhomes' ); ?></label><input id="tdh-p-current" name="tdh_password_current" type="password" autocomplete="current-password"><small class="form-hint--show"><?php esc_html_e( 'Required when changing your email or password.', 'thirtydayhomes' ); ?></small></div>
				<div class="form-field"><label for="tdh-p-new"><?php esc_html_e( 'New password', 'thirtydayhomes' ); ?></label><input id="tdh-p-new" name="tdh_password_new" type="password" autocomplete="new-password" minlength="<?php echo esc_attr( (string) self::MIN_PASSWORD ); ?>"><small class="form-hint--show"><?php printf( esc_html__( 'At least %d characters.', 'thirtydayhomes' ), (int) self::MIN_PASSWORD ); ?></small></div>
			</div>
			<div class="portal-profile-actions"><button class="primary" type="submit"><?php esc_html_e( 'Save account details', 'thirtydayhomes' ); ?></button></div>
		</form>

		<?php self::sms_card( $user ); ?>
		<?php
	}

	/**
	 * A form that has to wait for the texting gateway says so while it
	 * does: the button goes quiet and reads "Sending…" the moment the form
	 * is genuinely on its way, and a second press is impossible. Deferred a
	 * tick so the browser has already serialised the form.
	 */
	private static function sending_attrs(): void {
		printf(
			' data-sending="%s" onsubmit="%s"',
			esc_attr__( 'Sending…', 'thirtydayhomes' ),
			esc_attr( "var b=this.querySelector('button[type=submit]'),t=this.dataset.sending;if(b){setTimeout(function(){b.disabled=true;b.setAttribute('aria-disabled','true');b.textContent=t;},0);}" )
		);
	}

	/**
	 * Text message alerts (D4).
	 *
	 * One card, one state at a time. The state decides which single form
	 * is shown, so there is always exactly one primary button and it always
	 * says the next thing to do: Send code → Verify → (done) Stop texts.
	 * Every state is words on a pill, never a colour on its own.
	 *
	 * Shown even when texting is off for the site — greyed, with the reason
	 * — because the phone number lives here now and must stay editable, and
	 * because a card that appears and vanishes with a wp-config constant
	 * reads as a bug.
	 */
	private static function sms_card( \WP_User $user ): void {

		$state   = Sms::state_for( (int) $user->ID );
		$why_not = Sms::unavailable_reason();
		$notice  = Sms::notice();
		$pills   = [
			'on'       => 'is-on',
			'verified' => 'is-verified',
			'pending'  => 'is-pending',
			'paused'   => 'is-paused',
			'off'      => 'is-off',
		];
		?>
		<section class="panel portal-profile portal-sms" aria-labelledby="tdh-sms-title">
			<div class="panel-title">
				<span>
					<h3 id="tdh-sms-title"><?php esc_html_e( 'Text message alerts', 'thirtydayhomes' ); ?></h3>
					<p><?php esc_html_e( 'A text the moment a renter inquires, so you can reply first.', 'thirtydayhomes' ); ?></p>
				</span>
				<span class="sms-pill <?php echo esc_attr( $pills[ $state['state'] ] ?? 'is-off' ); ?>"><?php echo esc_html( $state['label'] ); ?></span>
			</div>

			<?php if ( $notice ) : ?>
				<p class="portal-notice<?php echo 'error' === $notice[0] ? ' is-warn' : ''; ?>" role="<?php echo 'error' === $notice[0] ? 'alert' : 'status'; ?>">
					<?php echo esc_html( $notice[1] ); ?>
				</p>
			<?php endif; ?>

			<?php if ( '' !== $why_not ) : ?>
				<?php
				/*
				 * Disabled state. The number is still editable through the
				 * ordinary details path? No — it moved here, so a plain
				 * field is kept so nobody loses the ability to change it.
				 */
				?>
				<p class="sms-unavailable"><?php echo esc_html( $why_not ); ?> <?php esc_html_e( 'You still get every inquiry by email.', 'thirtydayhomes' ); ?></p>

				<form method="post" action="" class="sms-form">
					<?php self::form_head( 'profile' ); ?>
					<input type="hidden" name="tdh_name" value="<?php echo esc_attr( $user->display_name ); ?>">
					<input type="hidden" name="tdh_email" value="<?php echo esc_attr( $user->user_email ); ?>">
					<input type="hidden" name="tdh_company" value="<?php echo esc_attr( (string) get_user_meta( $user->ID, '_tdh_company', true ) ); ?>">
					<div class="form-grid">
						<div class="form-field">
							<label for="tdh-sms-phone"><?php esc_html_e( 'Mobile number (optional)', 'thirtydayhomes' ); ?></label>
							<input id="tdh-sms-phone" name="tdh_phone" type="tel" autocomplete="tel" inputmode="tel" value="<?php echo esc_attr( $state['display'] ?: (string) get_user_meta( $user->ID, Sms::META_PHONE, true ) ); ?>">
						</div>
					</div>
					<div class="portal-profile-actions"><button class="secondary" type="submit"><?php esc_html_e( 'Save number', 'thirtydayhomes' ); ?></button></div>
				</form>

			<?php elseif ( 'on' === $state['state'] || 'verified' === $state['state'] ) : ?>

				<dl class="sms-facts">
					<div>
						<dt><?php esc_html_e( 'Texts go to', 'thirtydayhomes' ); ?></dt>
						<dd><?php echo esc_html( $state['display'] ); ?> <span class="sms-verified-mark"><?php esc_html_e( '· verified', 'thirtydayhomes' ); ?></span></dd>
					</div>
				</dl>

				<?php if ( 'on' === $state['state'] ) : ?>
					<p class="sms-hint"><?php esc_html_e( 'One text per inquiry, nothing else. Reply STOP to any text to pause them, or turn them off here.', 'thirtydayhomes' ); ?></p>
					<div class="sms-actions">
						<form method="post" action="">
							<input type="hidden" name="tdh_action" value="<?php echo esc_attr( Sms::ACTION_STOP ); ?>">
							<?php wp_nonce_field( Sms::ACTION_STOP, 'tdh_nonce' ); ?>
							<button class="secondary" type="submit"><?php esc_html_e( 'Turn texts off', 'thirtydayhomes' ); ?></button>
						</form>
						<form method="post" action="" class="sms-change"<?php self::sending_attrs(); ?>>
							<input type="hidden" name="tdh_action" value="<?php echo esc_attr( Sms::ACTION_START ); ?>">
							<?php wp_nonce_field( Sms::ACTION_START, 'tdh_nonce' ); ?>
							<input type="hidden" name="tdh_sms_consent" value="1">
							<?php /* Inside .form-field so it takes the same fill, border and focus ring as every other input, instead of the browser's white box. */ ?>
							<div class="form-field">
								<label for="tdh-sms-new" class="screen-reader-text"><?php esc_html_e( 'New mobile number', 'thirtydayhomes' ); ?></label>
								<input id="tdh-sms-new" name="tdh_sms_phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="<?php esc_attr_e( 'Change number…', 'thirtydayhomes' ); ?>">
							</div>
							<button class="secondary" type="submit"><?php esc_html_e( 'Change', 'thirtydayhomes' ); ?></button>
						</form>
					</div>
				<?php else : ?>
					<form method="post" action="" class="sms-form">
						<input type="hidden" name="tdh_action" value="<?php echo esc_attr( Sms::ACTION_START ); ?>">
						<?php wp_nonce_field( Sms::ACTION_START, 'tdh_nonce' ); ?>
						<input type="hidden" name="tdh_sms_phone" value="<?php echo esc_attr( $state['phone'] ); ?>">
						<label class="sms-consent">
							<input type="checkbox" name="tdh_sms_consent" value="1">
							<span><?php esc_html_e( 'Text me when I receive an inquiry. One message per inquiry; message and data rates may apply; reply STOP to opt out.', 'thirtydayhomes' ); ?></span>
						</label>
						<div class="portal-profile-actions"><button class="primary" type="submit"><?php esc_html_e( 'Turn texts on', 'thirtydayhomes' ); ?></button></div>
					</form>
				<?php endif; ?>

			<?php elseif ( 'pending' === $state['state'] ) : ?>

				<?php
				/*
				 * Says which number, and what to do if it is the wrong
				 * one. The green notice above already says a code was
				 * sent; repeating that here read as the page stammering.
				 */
				?>
				<p class="sms-hint">
					<?php
					printf(
						/* translators: %s: a phone number */
						esc_html__( 'Sent to %s. Wrong number? Correct it below and we will send a new one.', 'thirtydayhomes' ),
						'<strong>' . esc_html( $state['display'] ) . '</strong>'
					);
					?>
				</p>

				<form method="post" action="" class="sms-form sms-verify">
					<input type="hidden" name="tdh_action" value="<?php echo esc_attr( Sms::ACTION_VERIFY ); ?>">
					<?php wp_nonce_field( Sms::ACTION_VERIFY, 'tdh_nonce' ); ?>
					<div class="form-field">
						<label for="tdh-sms-code"><?php esc_html_e( 'Code from the text', 'thirtydayhomes' ); ?></label>
						<input id="tdh-sms-code" name="tdh_sms_code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus>
						<small class="form-hint--show"><?php esc_html_e( 'It works for 10 minutes.', 'thirtydayhomes' ); ?></small>
					</div>
					<div class="portal-profile-actions"><button class="primary" type="submit"><?php esc_html_e( 'Verify', 'thirtydayhomes' ); ?></button></div>
				</form>

				<div class="sms-actions">
					<form method="post" action=""<?php self::sending_attrs(); ?>>
						<input type="hidden" name="tdh_action" value="<?php echo esc_attr( Sms::ACTION_RESEND ); ?>">
						<?php wp_nonce_field( Sms::ACTION_RESEND, 'tdh_nonce' ); ?>
						<button class="link-button" type="submit"><?php esc_html_e( 'Send a new code', 'thirtydayhomes' ); ?></button>
					</form>
					<form method="post" action="" class="sms-change"<?php self::sending_attrs(); ?>>
						<input type="hidden" name="tdh_action" value="<?php echo esc_attr( Sms::ACTION_START ); ?>">
						<?php wp_nonce_field( Sms::ACTION_START, 'tdh_nonce' ); ?>
						<input type="hidden" name="tdh_sms_consent" value="1">
						<div class="form-field">
							<label for="tdh-sms-fix" class="screen-reader-text"><?php esc_html_e( 'Correct the number', 'thirtydayhomes' ); ?></label>
							<input id="tdh-sms-fix" name="tdh_sms_phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="<?php esc_attr_e( 'Wrong number?', 'thirtydayhomes' ); ?>">
						</div>
						<button class="secondary" type="submit"><?php esc_html_e( 'Use this', 'thirtydayhomes' ); ?></button>
					</form>
				</div>

			<?php else : ?>

				<?php if ( $state['opted_out'] ) : ?>
					<p class="sms-hint"><?php esc_html_e( 'You replied STOP, so your phone company blocks our texts to this number. Reply START to that text to resume them, or set up a different number below.', 'thirtydayhomes' ); ?></p>
				<?php endif; ?>

				<form method="post" action="" class="sms-form"<?php self::sending_attrs(); ?>>
					<input type="hidden" name="tdh_action" value="<?php echo esc_attr( Sms::ACTION_START ); ?>">
					<?php wp_nonce_field( Sms::ACTION_START, 'tdh_nonce' ); ?>
					<div class="form-field">
						<label for="tdh-sms-phone"><?php esc_html_e( 'Mobile number', 'thirtydayhomes' ); ?></label>
						<input id="tdh-sms-phone" name="tdh_sms_phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="(412) 555-0184" value="<?php echo esc_attr( $state['display'] ); ?>" required>
						<small class="form-hint--show"><?php esc_html_e( 'US mobile numbers only. We text a code to confirm it is yours.', 'thirtydayhomes' ); ?></small>
					</div>
					<label class="sms-consent">
						<input type="checkbox" name="tdh_sms_consent" value="1" required>
						<span><?php esc_html_e( 'Text me when I receive an inquiry. One message per inquiry; message and data rates may apply; reply STOP to opt out.', 'thirtydayhomes' ); ?></span>
					</label>
					<div class="portal-profile-actions"><button class="primary" type="submit"><?php esc_html_e( 'Send code', 'thirtydayhomes' ); ?></button></div>
				</form>

			<?php endif; ?>
		</section>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * The marketplace — the administrator's portal
	 * ------------------------------------------------------------------ */

	/**
	 * The approved design's third portal: what the OWNER sees on /account/.
	 *
	 * Same discipline as the landlord portal — every number is the
	 * database's own answer, and every link goes where the work actually
	 * happens today, which for managing listings, members and inquiries is
	 * wp-admin. This screen is the morning overview; the tools are real.
	 */
	private static function marketplace(): string {

		$user     = wp_get_current_user();
		$notice   = Accounts::take_notice();
		$initials = strtoupper( mb_substr( trim( $user->display_name ), 0, 2 ) );

		if ( isset( $_GET['tdh_submitted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$notice['success'] = __( 'The listing is submitted and waiting in the approval queue below.', 'thirtydayhomes' );
		}

		/*
		 * Coming back from a moderation decision. Flags, not messages: the
		 * copy lives here, so the query string cannot be edited into saying
		 * something else.
		 */
		$moderated = isset( $_GET['tdh_moderated'] ) ? sanitize_key( wp_unslash( (string) $_GET['tdh_moderated'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'approved' === $moderated ) {
			$notice['success'] = __( 'Approved. The listing is live and visible to renters.', 'thirtydayhomes' );
		} elseif ( 'changes' === $moderated ) {
			$notice['info'] = __( 'Sent back to the landlord with your note. It returns to this queue when they resubmit.', 'thirtydayhomes' );
		} elseif ( 'reason' === $moderated ) {
			// 'errors', the key notices() prints — these three once set
			// 'error', which nothing read, so every failure was silent.
			$notice['errors'][] = __( 'Tell the landlord what to change — the note can’t be empty. Nothing was sent.', 'thirtydayhomes' );
		} elseif ( 'expired' === $moderated ) {
			$notice['errors'][] = __( 'That action expired before it was saved. Please try again.', 'thirtydayhomes' );
		} elseif ( 'missing' === $moderated ) {
			$notice['errors'][] = __( 'That listing no longer exists.', 'thirtydayhomes' );
		} elseif ( 'owner_inactive' === $moderated ) {
			$notice['errors'][] = __( 'Not approved: the landlord’s membership isn’t active, so the home would only be hidden again. Approve it once they’ve paid.', 'thirtydayhomes' );
		} elseif ( 'not_pending' === $moderated ) {
			$notice['errors'][] = __( 'That home is no longer waiting for review — it may have been approved already, or the landlord took it back. Nothing was changed.', 'thirtydayhomes' );
		} elseif ( 'location' === $moderated ) {
			$notice['errors'][] = __( 'Not approved yet: Google couldn’t find this home’s address, so no distances to hospitals can be shown. Set its location below, then approve.', 'thirtydayhomes' );
		}

		// Back from Set location or Try again.
		if ( isset( $_GET['tdh_located'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$located = Geocoder::notice(
				sanitize_key( wp_unslash( (string) $_GET['tdh_located'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				(int) ( $_GET['tdh_home'] ?? 0 ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			);

			if ( $located ) {
				if ( 'error' === $located['type'] ) {
					$notice['errors'][] = $located['text'];
				} else {
					$notice[ $located['type'] ] = $located['text'];
				}
			}
		}

		// Back from a staff Pause, Resume or Delete (the handler allows staff).
		if ( isset( $_GET['tdh_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$done = Listing_Actions::notice(
				sanitize_key( wp_unslash( (string) $_GET['tdh_done'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				(int) ( $_GET['tdh_home'] ?? 0 ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			);

			if ( $done ) {
				if ( 'error' === $done['type'] ) {
					$notice['errors'][] = $done['text'];
				} else {
					$notice[ $done['type'] ] = $done['text'];
				}
			}
		}

		// Which screen of the portal is open. Anything unrecognised is the
		// overview, not an error page.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $view, [ 'overview', 'listings', 'listing-setup', 'members', 'inquiries', 'facilities' ], true ) ) {
			$view = 'overview';
		}

		$icon = static fn( string $name, int $size = 19 ): string =>
			function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size ) : '';

		// The sidebar badge: how many homes wait for a decision, on every view.
		$pending_total = self::count_site_listings( [ 'pending' ] );

		ob_start();
		?>
		<div class="portal">

			<aside class="portal-side" id="portal-sidebar">
				<a class="portal-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<?php
					if ( function_exists( 'tdh_the_logo' ) ) {
						tdh_the_logo();
					} else {
						echo '<b>' . esc_html( get_bloginfo( 'name' ) ) . '</b>';
					}
					?>
				</a>

				<p class="portal-side-label"><?php esc_html_e( 'Administration', 'thirtydayhomes' ); ?></p>

				<?php
				/*
				 * The client's screens are portal views — daily work stays
				 * in this UI. Site content alone opens wp-admin, because
				 * editing pages IS the WordPress editor's job.
				 */
				$nav = [
					[ 'overview', 'layout-dashboard', __( 'Overview', 'thirtydayhomes' ) ],
					[ 'listings', 'building-2', __( 'Listings', 'thirtydayhomes' ) ],
					[ 'listing-setup', 'settings-2', __( 'Listing setup', 'thirtydayhomes' ) ],
					[ 'members', 'users', __( 'Members', 'thirtydayhomes' ) ],
					[ 'facilities', 'stethoscope', __( 'Facilities', 'thirtydayhomes' ) ],
					[ 'inquiries', 'mail', __( 'Inquiries', 'thirtydayhomes' ) ],
				];
				?>
				<nav class="portal-nav" aria-label="<?php esc_attr_e( 'Administration', 'thirtydayhomes' ); ?>">
					<?php foreach ( $nav as [ $slug, $nav_icon, $label ] ) : ?>
						<a class="<?php echo $view === $slug ? 'is-current' : ''; ?>" title="<?php echo esc_attr( $label ); ?>" href="<?php echo esc_url( self::mk_url( $slug ) ); ?>">
							<?php echo $icon( $nav_icon, 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php echo esc_html( $label ); ?>
							<?php if ( 'listings' === $slug && $pending_total > 0 ) : ?>
								<em class="portal-count"><?php echo esc_html( number_format_i18n( $pending_total ) ); ?></em>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
					<a title="<?php esc_attr_e( 'Site content', 'thirtydayhomes' ); ?>" href="<?php echo esc_url( admin_url( 'edit.php?post_type=page' ) ); ?>">
						<?php echo $icon( 'file-text', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php esc_html_e( 'Site content', 'thirtydayhomes' ); ?>
					</a>
				</nav>

				<a class="portal-return" title="<?php esc_attr_e( 'Public website', 'thirtydayhomes' ); ?>" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<?php echo $icon( 'arrow-left', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php esc_html_e( 'Public website', 'thirtydayhomes' ); ?>
				</a>
			</aside>

			<div class="portal-main" id="overview">

				<div class="portal-top">
					<div class="portal-top-context">
						<button class="portal-menu-toggle" type="button" aria-controls="portal-sidebar" aria-expanded="true" aria-label="<?php esc_attr_e( 'Collapse dashboard sidebar', 'thirtydayhomes' ); ?>">
							<span class="portal-toggle-collapse"><?php echo $icon( 'chevron-left', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
							<span class="portal-toggle-expand"><?php echo $icon( 'chevron-right', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						</button>
						<span><?php esc_html_e( 'Marketplace administration', 'thirtydayhomes' ); ?></span>
					</div>
					<div>
						<a class="portal-signout" href="<?php echo esc_url( Accounts::logout_url() ); ?>"><?php esc_html_e( 'Sign out', 'thirtydayhomes' ); ?></a>
						<i class="portal-avatar" aria-hidden="true"><?php echo esc_html( $initials ); ?></i>
					</div>
				</div>

				<div class="portal-body">

					<?php self::notices( $notice ); ?>

					<?php
					match ( $view ) {
						'listings'   => self::mk_listings(),
						'listing-setup' => self::mk_listing_setup( $notice['values'] ),
						'members'    => self::mk_members(),
						'inquiries'  => self::mk_inquiries(),
						'facilities' => self::mk_facilities(),
						default      => self::mk_overview(),
					};
					?>

				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * A staff portal view's address. The overview is the bare account page.
	 *
	 * @param array<string,string> $args Extra arguments, such as the
	 *                                   message to open on Inquiries.
	 */
	private static function mk_url( string $view, array $args = [] ): string {

		$base = Accounts::url( 'account' );
		$base = 'overview' === $view ? $base : add_query_arg( 'view', $view, $base );

		return $args ? add_query_arg( array_map( 'rawurlencode', $args ), $base ) : $base;
	}

	private static function mk_icon( string $name, int $size = 19 ): string {
		return function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size ) : '';
	}

	/**
	 * One row in a portal list: cover (or placeholder tile), two lines of
	 * text, then whatever trails — badges, actions.
	 */
	private static function mk_row_media( int $post_id ): void {
		?>
		<?php if ( has_post_thumbnail( $post_id ) ) : ?>
			<?php echo get_the_post_thumbnail( $post_id, 'thumbnail', [ 'alt' => '' ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php else : ?>
			<i aria-hidden="true"><?php echo self::mk_icon( 'building-2', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
		<?php endif; ?>
		<?php
	}

	/**
	 * "$2,400/mo · Shadyside · Landlord: Jane" — owner is always explicit.
	 */
	private static function mk_listing_line( int $post_id ): string {

		$rent = (string) get_post_meta( $post_id, '_tdh_price_monthly', true );
		$hood = get_the_terms( $post_id, Post_Types::TAX_NEIGHBORHOOD );
		$hood = $hood && ! is_wp_error( $hood ) ? $hood[0]->name : '';

		$author = (int) get_post_field( 'post_author', $post_id );
		$owner  = $author ? get_userdata( $author ) : null;
		$owner_label = $owner
			? sprintf( __( 'Landlord: %s', 'thirtydayhomes' ), $owner->display_name )
			: __( 'Landlord: Unassigned', 'thirtydayhomes' );

		return implode(
			' · ',
			array_filter(
				[
					/* translators: %s: monthly rent */
					'' !== $rent ? sprintf( __( '$%s/mo', 'thirtydayhomes' ), number_format_i18n( (float) $rent ) ) : '',
					$hood,
					$owner_label,
				]
			)
		);
	}

	/* --- Overview -------------------------------------------------------- */

	private static function mk_overview(): void {

		/*
		 * Membership health, from the same user meta the Stripe webhook
		 * maintains. Counted by stored status: this is the billing ledger's
		 * view, and the one place a stale "active" would surface anyway —
		 * on the owner's own health panel, where it prompts the question.
		 */
		$health = [
			__( 'Active', 'thirtydayhomes' )   => self::count_members( Membership::ACTIVE ),
			__( 'Past due', 'thirtydayhomes' ) => self::count_members( Membership::PAST_DUE ),
			__( 'Canceled', 'thirtydayhomes' ) => self::count_members( Membership::CANCELLED ),
		];

		$queue = new \WP_Query(
			[
				'post_type'             => Post_Types::LISTING,
				'post_status'           => 'pending',
				'posts_per_page'        => 5,
				'orderby'               => 'modified',
				'order'                 => 'DESC',
				'tdh_bypass_visibility' => true,
			]
		);

		// Inquiries from the last seven days, matching the tile's own word.
		$recent = new \WP_Query(
			[
				'post_type'      => Post_Types::INQUIRY,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'date_query'     => [ [ 'after' => '7 days ago' ] ],
			]
		);

		$tiles = [
			[ 'users', __( 'Active members', 'thirtydayhomes' ), $health[ __( 'Active', 'thirtydayhomes' ) ] ],
			[ 'building-2', __( 'Live listings', 'thirtydayhomes' ), self::count_site_listings( [ 'publish' ] ) ],
			[ 'clock-3', __( 'Pending approval', 'thirtydayhomes' ), (int) $queue->found_posts ],
			[ 'mail', __( 'Recent inquiries', 'thirtydayhomes' ), (int) $recent->found_posts ],
		];
		?>
		<div class="portal-heading">
			<span>
				<h1><?php esc_html_e( 'Marketplace overview', 'thirtydayhomes' ); ?></h1>
				<p><?php echo esc_html( date_i18n( 'l, F j, Y' ) ); ?></p>
			</span>
		</div>

		<div class="portal-metrics">
			<?php foreach ( $tiles as [ $tile_icon, $label, $value ] ) : ?>
				<div class="portal-metric">
					<i><?php echo self::mk_icon( $tile_icon ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
					<span>
						<small><?php echo esc_html( $label ); ?></small>
						<b><?php echo esc_html( number_format_i18n( (int) $value ) ); ?></b>
					</span>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="portal-columns">

			<div class="panel">
				<div class="panel-title">
					<h3><?php esc_html_e( 'Approval queue', 'thirtydayhomes' ); ?></h3>
					<a href="<?php echo esc_url( self::mk_url( 'listings' ) ); ?>">
						<?php esc_html_e( 'View all', 'thirtydayhomes' ); ?>
						<?php echo self::mk_icon( 'arrow-right', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</a>
				</div>

				<?php if ( ! $queue->have_posts() ) : ?>
					<div class="empty-state">
						<i><?php echo self::mk_icon( 'circle-check', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
						<h4><?php esc_html_e( 'Nothing waiting', 'thirtydayhomes' ); ?></h4>
						<p><?php esc_html_e( 'When a landlord submits a home, it appears here for review before going live.', 'thirtydayhomes' ); ?></p>
					</div>
				<?php else : ?>
					<?php
					while ( $queue->have_posts() ) :
						$queue->the_post();
						?>
						<div class="portal-approval">
							<?php self::mk_row_media( get_the_ID() ); ?>
							<span>
								<b><a href="<?php echo esc_url( (string) get_preview_post_link( get_the_ID() ) ); ?>"><?php the_title(); ?></a></b>
								<small><?php echo esc_html( self::mk_listing_line( get_the_ID() ) ); ?></small>
							</span>
							<span class="status pending"><?php esc_html_e( 'Pending', 'thirtydayhomes' ); ?></span>
						</div>
					<?php endwhile; ?>
					<?php wp_reset_postdata(); ?>
				<?php endif; ?>
			</div>

			<div class="panel">
				<div class="panel-title">
					<h3><?php esc_html_e( 'Membership health', 'thirtydayhomes' ); ?></h3>
				</div>

				<div class="portal-health">
					<?php foreach ( $health as $label => $count ) : ?>
						<div>
							<small><?php echo esc_html( $label ); ?></small>
							<b><?php echo esc_html( number_format_i18n( $count ) ); ?></b>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

		</div>
		<?php
	}

	/* --- Listings: the approval loop lives here -------------------------- */

	private static function mk_listings(): void {

		$labels         = Statuses::all();
		$requested      = isset( $_GET['listing_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['listing_status'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_filter  = array_key_exists( $requested, $labels ) ? $requested : 'all';
		$all_statuses   = array_values( array_unique( array_merge( [ 'publish', 'pending', 'draft' ], array_keys( $labels ) ) ) );
		$query_statuses = 'all' === $active_filter ? $all_statuses : [ $active_filter ];

		$pending = new \WP_Query(
			[
				'post_type'             => Post_Types::LISTING,
				'post_status'           => 'pending',
				'posts_per_page'        => 20,
				'orderby'               => 'modified',
				'order'                 => 'ASC',
				'tdh_bypass_visibility' => true,
			]
		);

		// Twenty a page. A fixed twenty with no pages quietly hid every
		// listing after the twentieth.
		$page = Listing_Manage_Render::requested_page();
		$all  = new \WP_Query(
			[
				'post_type'             => Post_Types::LISTING,
				'post_status'           => $query_statuses,
				'posts_per_page'        => 20,
				'paged'                 => $page,
				'tdh_bypass_visibility' => true,
			]
		);

		$badges = [
			'publish'              => 'live',
			'pending'              => 'pending',
			Statuses::PAUSED       => 'inactive',
			Statuses::REJECTED     => 'rejected',
			Statuses::BILLING_HOLD => 'past_due',
			'draft'                => 'inactive',
		];

		// The home whose "what to change" note is being written, if any.
		$requesting = isset( $_GET['request_changes'] ) ? (int) $_GET['request_changes'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// The home whose location is being set by hand, if any.
		$locating = isset( $_GET['locate'] ) ? (int) $_GET['locate'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="portal-heading">
			<span>
				<h1><?php esc_html_e( 'Listings', 'thirtydayhomes' ); ?></h1>
				<p><?php esc_html_e( 'Create, edit, review and publish homes without leaving the marketplace portal.', 'thirtydayhomes' ); ?></p>
			</span>
			<a class="primary" href="<?php echo esc_url( Listing_Form::url() ); ?>">
				<?php echo self::mk_icon( 'plus', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php esc_html_e( 'Add listing', 'thirtydayhomes' ); ?>
			</a>
		</div>

		<nav class="portal-status-filters" aria-label="<?php esc_attr_e( 'Filter listings by status', 'thirtydayhomes' ); ?>">
			<a class="<?php echo 'all' === $active_filter ? 'is-current' : ''; ?>" href="<?php echo esc_url( self::mk_url( 'listings' ) ); ?>"><?php esc_html_e( 'All', 'thirtydayhomes' ); ?></a>
			<?php foreach ( $labels as $status_key => $status_label ) : ?>
				<a class="<?php echo $status_key === $active_filter ? 'is-current' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'listing_status', $status_key, self::mk_url( 'listings' ) ) ); ?>"><?php echo esc_html( $status_label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php self::mk_service_notice(); ?>

		<div class="panel portal-panel-block">
			<div class="panel-title">
				<h3><?php esc_html_e( 'Waiting for approval', 'thirtydayhomes' ); ?></h3>
			</div>

			<?php if ( ! $pending->have_posts() ) : ?>
				<div class="empty-state">
					<i><?php echo self::mk_icon( 'circle-check', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
					<h4><?php esc_html_e( 'Nothing waiting', 'thirtydayhomes' ); ?></h4>
					<p><?php esc_html_e( 'Submitted homes land here for a decision before going live.', 'thirtydayhomes' ); ?></p>
				</div>
			<?php else : ?>
				<?php
				while ( $pending->have_posts() ) :
					$pending->the_post();
					$queue_id = (int) get_the_ID();
					$writing  = $requesting === $queue_id;
					$placing  = $locating === $queue_id && ! $writing;
					$held     = Geocoder::blocks_approval( $queue_id );
					$unpaid   = Listing_Actions::approval_blocked( (int) get_post_field( 'post_author', $queue_id ) );
					$reasons  = trim( ( $held ? 'loc-' . $queue_id : '' ) . ( $unpaid ? ' owner-' . $queue_id : '' ) );
					?>
					<div class="portal-approval<?php echo $writing || $placing ? ' is-requesting' : ''; ?>" id="queue-<?php echo esc_attr( (string) $queue_id ); ?>">
						<?php self::mk_row_media( $queue_id ); ?>
						<span>
							<?php // Preview: see the home as a renter would, before deciding. ?>
							<b><a href="<?php echo esc_url( (string) get_preview_post_link( $queue_id ) ); ?>"><?php the_title(); ?></a></b>
							<small><?php echo esc_html( self::mk_listing_line( $queue_id ) ); ?></small>
							<?php self::mk_location_line( $queue_id, $placing ); ?>
							<?php if ( $unpaid ) : ?>
								<span class="portal-owner-hold" id="owner-<?php echo esc_attr( (string) $queue_id ); ?>">
									<em class="status past_due"><?php esc_html_e( 'Membership inactive', 'thirtydayhomes' ); ?></em>
									<?php esc_html_e( 'The landlord hasn’t paid, so this home can’t go live yet. Approve it once they have.', 'thirtydayhomes' ); ?>
								</span>
							<?php endif; ?>
						</span>
						<span class="portal-row-actions">
							<form method="post" action="<?php echo esc_url( self::mk_url( 'listings' ) ); ?>">
								<input type="hidden" name="tdh_action" value="listing_approve">
								<input type="hidden" name="tdh_listing" value="<?php echo esc_attr( (string) $queue_id ); ?>">
								<?php wp_nonce_field( Moderation::NONCE, 'tdh_nonce' ); ?>
								<?php // Not approvable while its address was not found or its landlord is unpaid: the reasons are the lines beside it. ?>
								<button class="primary" type="submit"<?php echo '' !== $reasons ? ' disabled aria-describedby="' . esc_attr( $reasons ) . '"' : ''; ?>><?php esc_html_e( 'Approve', 'thirtydayhomes' ); ?></button>
							</form>
							<?php if ( ! $writing ) : ?>
								<?php // Opens the note below the row: a request always says what to change. ?>
								<a class="secondary" href="<?php echo esc_url( self::request_changes_url( $queue_id ) ); ?>"><?php esc_html_e( 'Request changes', 'thirtydayhomes' ); ?></a>
							<?php endif; ?>
						</span>
					</div>
					<?php if ( $writing ) : ?>
						<?php self::mk_reason_form( $queue_id, get_the_title() ); ?>
					<?php elseif ( $placing ) : ?>
						<?php self::mk_locate_form( $queue_id, get_the_title() ); ?>
					<?php endif; ?>
				<?php endwhile; ?>
				<?php wp_reset_postdata(); ?>
			<?php endif; ?>
		</div>

		<div class="panel portal-panel-block">
			<div class="panel-title">
				<h3><?php esc_html_e( 'All listings', 'thirtydayhomes' ); ?></h3>
			</div>

			<?php if ( ! $all->have_posts() ) : ?>
				<div class="empty-state">
					<i><?php echo self::mk_icon( 'map-pinned', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
					<h4><?php esc_html_e( 'No listings yet', 'thirtydayhomes' ); ?></h4>
					<p><?php esc_html_e( 'Homes appear here as landlords create them.', 'thirtydayhomes' ); ?></p>
				</div>
			<?php else : ?>
				<?php
				while ( $all->have_posts() ) :
					$all->the_post();
					$state   = (string) get_post_status();
					$open    = Listing_Form::url( 1, get_the_ID() );
					$row_id  = (int) get_the_ID();
					// A waiting home sets its location in the queue above, not twice.
					$placing = $locating === $row_id && 'pending' !== $state;
					?>
					<div class="portal-approval<?php echo $placing ? ' is-requesting' : ''; ?>" id="row-<?php echo esc_attr( (string) $row_id ); ?>">
						<?php self::mk_row_media( $row_id ); ?>
						<span>
							<b><a href="<?php echo esc_url( (string) $open ); ?>"><?php the_title(); ?></a></b>
							<small><?php echo esc_html( self::mk_listing_line( $row_id ) ); ?></small>
							<?php self::mk_location_line( $row_id, $placing ); ?>
						</span>
						<span class="status <?php echo esc_attr( $badges[ $state ] ?? '' ); ?>">
							<?php echo esc_html( $labels[ $state ] ?? $state ); ?>
						</span>
					</div>
					<?php if ( $placing ) : ?>
						<?php self::mk_locate_form( $row_id, get_the_title() ); ?>
					<?php endif; ?>
				<?php endwhile; ?>
				<?php wp_reset_postdata(); ?>

				<?php if ( $all->max_num_pages > 1 ) : ?>
					<?php
					$pages = (int) $all->max_num_pages;
					$base  = 'all' === $active_filter ? self::mk_url( 'listings' ) : add_query_arg( 'listing_status', $active_filter, self::mk_url( 'listings' ) );
					?>
					<nav class="portal-pagination" aria-label="<?php esc_attr_e( 'Listing pages', 'thirtydayhomes' ); ?>">
						<?php if ( $page > 1 ) : ?>
							<a class="secondary" rel="prev" href="<?php echo esc_url( add_query_arg( 'listings_page', $page - 1, $base ) ); ?>"><?php echo self::mk_icon( 'chevron-left', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Previous', 'thirtydayhomes' ); ?></a>
						<?php else : ?>
							<span class="secondary is-disabled" aria-hidden="true"><?php echo self::mk_icon( 'chevron-left', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Previous', 'thirtydayhomes' ); ?></span>
						<?php endif; ?>
						<span class="portal-pagination-count" aria-current="page">
							<?php
							/* translators: 1: current page, 2: number of pages */
							printf( esc_html__( 'Page %1$s of %2$s', 'thirtydayhomes' ), esc_html( number_format_i18n( min( $page, $pages ) ) ), esc_html( number_format_i18n( $pages ) ) );
							?>
						</span>
						<?php if ( $page < $pages ) : ?>
							<a class="secondary" rel="next" href="<?php echo esc_url( add_query_arg( 'listings_page', $page + 1, $base ) ); ?>"><?php esc_html_e( 'Next', 'thirtydayhomes' ); ?><?php echo self::mk_icon( 'chevron-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
						<?php else : ?>
							<span class="secondary is-disabled" aria-hidden="true"><?php esc_html_e( 'Next', 'thirtydayhomes' ); ?><?php echo self::mk_icon( 'chevron-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<?php endif; ?>
					</nav>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A problem with the maps service — no key, a refused key, a limit — once
	 * at the top of the screens it affects, instead of on every row.
	 */
	private static function mk_service_notice(): void {

		$text = Geocoder::service_notice();

		if ( '' === $text ) {
			return;
		}
		?>
		<div class="form-notice form-notice--info portal-service-notice" role="status">
			<?php echo self::mk_icon( 'map-pin', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p><?php echo esc_html( $text ); ?></p>
		</div>
		<?php
	}

	/**
	 * Where a home stands on the map, under its name — only when it needs
	 * attention. "Found" and "set by hand" are the normal case and say
	 * nothing; "not found" and "not checked yet" say why and what to do.
	 */
	private static function mk_location_line( int $listing_id, bool $open ): void {

		$state = Geocoder::state( $listing_id );

		if ( in_array( $state, [ 'found', 'manual' ], true ) ) {
			return;
		}

		$chip = Geocoder::chip( $state );

		// With no maps key every home is "not checked yet" for the same reason,
		// and the notice at the top of the screen already says it once.
		$why = 'pending' === $state && ! Geocoder::configured() ? '' : Geocoder::reason( $listing_id );
		?>
		<span class="portal-location is-<?php echo esc_attr( $state ); ?>" id="loc-<?php echo esc_attr( (string) $listing_id ); ?>">
			<em class="status <?php echo esc_attr( $chip['badge'] ); ?>"><?php echo esc_html( $chip['label'] ); ?></em>
			<?php echo esc_html( $why ); ?>
			<?php if ( ! $open ) : ?>
				<a href="<?php echo esc_url( self::locate_url( $listing_id ) ); ?>">
					<?php esc_html_e( 'Set location', 'thirtydayhomes' ); ?>
					<span class="screen-reader-text"><?php echo esc_html( get_the_title( $listing_id ) ); ?></span>
				</a>
			<?php endif; ?>
		</span>
		<?php
	}

	/** Where "Set location" opens: the Listings screen, at the home. */
	public static function locate_url( int $listing_id ): string {
		return add_query_arg( 'locate', $listing_id, self::mk_url( 'listings' ) ) . '#' . Geocoder::anchor( $listing_id );
	}

	/**
	 * Set a home's location by hand: paste the coordinates from Google Maps.
	 * One box, because that is how Maps copies them ("40.4406, -79.9959").
	 * What a refused save typed comes back; a point already set is shown.
	 */
	private static function mk_locate_form( int $listing_id, string $title ): void {

		$field   = 'tdh-point-' . $listing_id;
		$retry   = 'tdh-retry-' . $listing_id;
		$typed   = Geocoder::take_typed( $listing_id );
		$point   = Geocoder::coordinates( $listing_id );
		$value   = '' !== $typed ? $typed : ( $point ? $point['lat'] . ', ' . $point['lng'] : '' );
		$address = Geocoder::address( $listing_id );
		$cancel  = self::mk_url( 'listings' ) . '#' . Geocoder::anchor( $listing_id );
		?>
		<div class="portal-locate">
			<form class="portal-locate-form" method="post" action="<?php echo esc_url( self::mk_url( 'listings' ) ); ?>">
				<input type="hidden" name="tdh_action" value="listing_location">
				<input type="hidden" name="tdh_listing" value="<?php echo esc_attr( (string) $listing_id ); ?>">
				<?php wp_nonce_field( Geocoder::NONCE, 'tdh_nonce' ); ?>

				<div class="form-field">
					<label for="<?php echo esc_attr( $field ); ?>">
						<?php
						/* translators: %s: listing title */
						printf( esc_html__( 'Coordinates for “%s”', 'thirtydayhomes' ), esc_html( $title ) );
						?>
					</label>
					<input id="<?php echo esc_attr( $field ); ?>" name="tdh_point" type="text" inputmode="decimal" autocomplete="off" spellcheck="false" required autofocus
						placeholder="40.4406, -79.9959"
						aria-describedby="<?php echo esc_attr( $field ); ?>-hint"
						value="<?php echo esc_attr( $value ); ?>">
					<small id="<?php echo esc_attr( $field ); ?>-hint" class="form-hint--show">
						<?php esc_html_e( 'In Google Maps, right-click the home and click the numbers at the top of the menu to copy them, then paste them here.', 'thirtydayhomes' ); ?>
					</small>
				</div>

				<p class="portal-locate-address">
					<?php if ( '' !== $address ) : ?>
						<?php
						/* translators: %s: street address (staff only) */
						printf( esc_html__( 'Address on file: %s', 'thirtydayhomes' ), esc_html( $address ) );
						?>
						·
					<?php endif; ?>
					<a href="<?php echo esc_url( Geocoder::maps_search_url( $listing_id ) ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Find it on Google Maps', 'thirtydayhomes' ); ?>
						<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'thirtydayhomes' ); ?></span>
					</a>
				</p>

				<div class="portal-reason-actions">
					<button class="primary" type="submit"><?php esc_html_e( 'Save location', 'thirtydayhomes' ); ?></button>
					<?php if ( Geocoder::configured() && '' !== $address ) : ?>
						<button class="secondary" type="submit" form="<?php echo esc_attr( $retry ); ?>" formnovalidate><?php esc_html_e( 'Look up the address again', 'thirtydayhomes' ); ?></button>
					<?php endif; ?>
					<a class="secondary" href="<?php echo esc_url( $cancel ); ?>"><?php esc_html_e( 'Cancel', 'thirtydayhomes' ); ?></a>
				</div>
			</form>

			<?php if ( Geocoder::configured() && '' !== $address ) : ?>
				<form id="<?php echo esc_attr( $retry ); ?>" method="post" action="<?php echo esc_url( self::mk_url( 'listings' ) ); ?>" hidden>
					<input type="hidden" name="tdh_action" value="listing_geocode">
					<input type="hidden" name="tdh_listing" value="<?php echo esc_attr( (string) $listing_id ); ?>">
					<?php wp_nonce_field( Geocoder::NONCE, 'tdh_nonce' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Where "Request changes" opens its note: the listings screen, scrolled
	 * to that home. Public, so the preview bar sends staff to the same place.
	 */
	public static function request_changes_url( int $listing_id ): string {
		return add_query_arg( 'request_changes', $listing_id, self::mk_url( 'listings' ) ) . '#queue-' . $listing_id;
	}

	/**
	 * The "what to change" note, under the home it is about.
	 *
	 * Required, and shown to the landlord word for word — so the label says
	 * who reads it, and the limit is stated before it is reached.
	 */
	private static function mk_reason_form( int $listing_id, string $title ): void {

		$field = 'tdh-reason-' . $listing_id;
		?>
		<form class="portal-reason" method="post" action="<?php echo esc_url( self::mk_url( 'listings' ) ); ?>">
			<input type="hidden" name="tdh_action" value="listing_changes">
			<input type="hidden" name="tdh_listing" value="<?php echo esc_attr( (string) $listing_id ); ?>">
			<?php wp_nonce_field( Moderation::NONCE, 'tdh_nonce' ); ?>

			<div class="form-field">
				<label for="<?php echo esc_attr( $field ); ?>">
					<?php
					/* translators: %s: listing title */
					printf( esc_html__( 'What should the landlord change in “%s”?', 'thirtydayhomes' ), esc_html( $title ) );
					?>
				</label>
				<textarea id="<?php echo esc_attr( $field ); ?>" name="tdh_reason" rows="4" required autofocus
					maxlength="<?php echo esc_attr( (string) Moderation::MAX_REASON ); ?>"
					aria-describedby="<?php echo esc_attr( $field ); ?>-hint"
					placeholder="<?php esc_attr_e( 'e.g. Please add a photo of each bedroom and the kitchen.', 'thirtydayhomes' ); ?>"></textarea>
				<small id="<?php echo esc_attr( $field ); ?>-hint" class="form-hint--show">
					<?php
					/* translators: %s: character limit */
					printf( esc_html__( 'The landlord sees this on their dashboard, word for word. Up to %s characters.', 'thirtydayhomes' ), esc_html( number_format_i18n( Moderation::MAX_REASON ) ) );
					?>
				</small>
			</div>

			<div class="portal-reason-actions">
				<button class="primary" type="submit"><?php esc_html_e( 'Send to landlord', 'thirtydayhomes' ); ?></button>
				<a class="secondary" href="<?php echo esc_url( self::mk_url( 'listings' ) . '#queue-' . $listing_id ); ?>"><?php esc_html_e( 'Cancel', 'thirtydayhomes' ); ?></a>
			</div>
		</form>
		<?php
	}

	/* --- Listing setup: the taxonomy menus WordPress nests under Listings. */

	/**
	 * @param array<string,string> $values Numbers a refused save typed back.
	 */
	private static function mk_listing_setup( array $values = [] ): void {

		$groups = [
			[ Post_Types::TAX_TYPE, 'building-2', __( 'Property Types', 'thirtydayhomes' ), __( 'Apartment, house, townhouse and other property categories.', 'thirtydayhomes' ) ],
			[ Post_Types::TAX_NEIGHBORHOOD, 'map-pin', __( 'Neighborhoods', 'thirtydayhomes' ), __( 'The local areas renters use to narrow their search.', 'thirtydayhomes' ) ],
			[ Post_Types::TAX_AMENITY, 'check', __( 'Amenities', 'thirtydayhomes' ), __( 'Reusable features landlords can assign to homes.', 'thirtydayhomes' ) ],
			[ Post_Types::TAX_CITY, 'map-pinned', __( 'Cities', 'thirtydayhomes' ), __( 'Markets shared by listings and medical facilities.', 'thirtydayhomes' ) ],
		];
		?>
		<div class="portal-heading">
			<span>
				<h1><?php esc_html_e( 'Listing setup', 'thirtydayhomes' ); ?></h1>
				<p><?php esc_html_e( 'The supporting menus behind every property listing.', 'thirtydayhomes' ); ?></p>
			</span>
		</div>

		<div class="portal-setup-grid">
			<?php foreach ( $groups as [ $taxonomy, $group_icon, $title, $copy ] ) : ?>
				<?php $count = wp_count_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] ); ?>
				<section class="panel portal-setup-card">
					<i><?php echo self::mk_icon( $group_icon, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
					<span>
						<h2><?php echo esc_html( $title ); ?></h2>
						<p><?php echo esc_html( $copy ); ?></p>
						<small>
							<?php
							printf(
								/* translators: %s: taxonomy term count */
								esc_html__( '%s configured', 'thirtydayhomes' ),
								esc_html( number_format_i18n( is_wp_error( $count ) ? 0 : (int) $count ) )
							);
							?>
						</small>
					</span>
					<button class="secondary" type="button" disabled aria-disabled="true"><?php esc_html_e( 'Manage — Milestone 2', 'thirtydayhomes' ); ?></button>
				</section>
			<?php endforeach; ?>
		</div>

		<?php self::mk_proximity_form( $values ); ?>

		<div class="form-notice form-notice--info" role="status">
			<?php echo self::mk_icon( 'calendar-days', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p><?php esc_html_e( 'These controls are visible now so the administration scope is clear. Portal-based add, rename, merge and delete actions arrive in Milestone 2; listing authors can already use configured values in the listing form.', 'thirtydayhomes' ); ?></p>
		</div>
		<?php
	}

	/**
	 * How many hospitals a property page lists, and how far out it looks.
	 *
	 * Two numbers rather than a free choice: the limits are stated next to
	 * the fields, because a rejected "12" that only says "invalid" teaches
	 * nothing.
	 */
	private static function mk_proximity_form( array $values = [] ): void {

		$radius = Proximity::radius_setting();

		$typed_count  = self::old( $values, 'tdh_count' );
		$typed_radius = self::old( $values, 'tdh_radius' );

		$count_value  = '' !== $typed_count ? $typed_count : (string) Proximity::count_setting();
		$radius_value = '' !== $typed_radius ? $typed_radius : (string) ( fmod( $radius, 1.0 ) > 0 ? $radius : (int) $radius );
		?>
		<section class="panel portal-panel-block">
			<div class="panel-title">
				<span>
					<h2><?php esc_html_e( 'Hospitals on a property page', 'thirtydayhomes' ); ?></h2>
					<p><?php esc_html_e( 'Every home’s “Close to care” section lists the nearest medical facilities. A home outside the distance says so rather than hiding the section.', 'thirtydayhomes' ); ?></p>
				</span>
			</div>

			<form class="portal-admin-form" method="post" action="<?php echo esc_url( self::mk_url( 'listing-setup' ) ); ?>">
				<input type="hidden" name="tdh_action" value="proximity_save">
				<?php wp_nonce_field( 'tdh_proximity_save', 'tdh_nonce' ); ?>

				<div class="form-grid">
					<div class="form-field">
						<label for="tdh-proximity-count"><?php esc_html_e( 'How many to list', 'thirtydayhomes' ); ?></label>
						<input id="tdh-proximity-count" name="tdh_count" type="number" inputmode="numeric" min="1" max="<?php echo esc_attr( (string) Proximity::MAX_COUNT ); ?>" step="1" required
							aria-describedby="tdh-proximity-count-hint"
							value="<?php echo esc_attr( $count_value ); ?>">
						<small id="tdh-proximity-count-hint" class="form-hint--show">
							<?php
							printf(
								/* translators: 1: smallest allowed, 2: largest allowed */
								esc_html__( '%1$s to %2$s facilities, closest first.', 'thirtydayhomes' ),
								esc_html( number_format_i18n( 1 ) ),
								esc_html( number_format_i18n( Proximity::MAX_COUNT ) )
							);
							?>
						</small>
					</div>

					<div class="form-field">
						<label for="tdh-proximity-radius"><?php esc_html_e( 'Only within (miles)', 'thirtydayhomes' ); ?></label>
						<input id="tdh-proximity-radius" name="tdh_radius" type="number" inputmode="decimal" min="1" max="<?php echo esc_attr( (string) Proximity::MAX_RADIUS ); ?>" step="0.5" required
							aria-describedby="tdh-proximity-radius-hint"
							value="<?php echo esc_attr( $radius_value ); ?>">
						<small id="tdh-proximity-radius-hint" class="form-hint--show">
							<?php
							printf(
								/* translators: %s: largest allowed radius */
								esc_html__( 'Up to %s miles. Anything further away is not listed.', 'thirtydayhomes' ),
								esc_html( number_format_i18n( Proximity::MAX_RADIUS ) )
							);
							?>
						</small>
					</div>
				</div>

				<button class="primary" type="submit"><?php esc_html_e( 'Save', 'thirtydayhomes' ); ?></button>
			</form>
		</section>
		<?php
	}

	/* --- Members ---------------------------------------------------------- */

	private static function mk_members(): void {

		$members = get_users(
			[
				'role'    => Roles::LANDLORD,
				'number'  => 50,
				'orderby' => 'registered',
				'order'   => 'DESC',
			]
		);

		$status_badges = [
			Membership::ACTIVE   => 'live',
			Membership::PAST_DUE => 'past_due',
		];

		$labels = Membership::labels();
		?>
		<div class="portal-heading">
			<span>
				<h1><?php esc_html_e( 'Members', 'thirtydayhomes' ); ?></h1>
				<p><?php esc_html_e( 'Create and manage landlord accounts, access, plans and listing allowances.', 'thirtydayhomes' ); ?></p>
			</span>
		</div>

		<details class="panel portal-panel-block portal-editor"<?php echo ! $members ? ' open' : ''; ?>>
			<summary>
				<span>
					<b><?php esc_html_e( 'Add a member', 'thirtydayhomes' ); ?></b>
					<small><?php esc_html_e( 'Create a landlord account and email them a secure password setup link.', 'thirtydayhomes' ); ?></small>
				</span>
				<?php echo self::mk_icon( 'plus', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</summary>
			<form class="portal-admin-form" method="post" action="<?php echo esc_url( self::mk_url( 'members' ) ); ?>">
				<input type="hidden" name="tdh_action" value="member_create">
				<?php wp_nonce_field( 'tdh_member_create', 'tdh_nonce' ); ?>
				<div class="form-grid">
					<div class="form-field">
						<label for="member-new-name"><?php esc_html_e( 'Name', 'thirtydayhomes' ); ?></label>
						<input id="member-new-name" name="tdh_name" type="text" autocomplete="off" required>
					</div>
					<div class="form-field">
						<label for="member-new-email"><?php esc_html_e( 'Email', 'thirtydayhomes' ); ?></label>
						<input id="member-new-email" name="tdh_email" type="email" autocomplete="off" required>
					</div>
					<div class="form-field">
						<label for="member-new-phone"><?php esc_html_e( 'Phone', 'thirtydayhomes' ); ?></label>
						<input id="member-new-phone" name="tdh_phone" type="tel" autocomplete="off">
					</div>
					<div class="form-field">
						<label for="member-new-company"><?php esc_html_e( 'Company', 'thirtydayhomes' ); ?></label>
						<input id="member-new-company" name="tdh_company" type="text" autocomplete="off">
					</div>
				</div>
				<button class="primary" type="submit"><?php esc_html_e( 'Create member', 'thirtydayhomes' ); ?></button>
			</form>
		</details>

		<div class="panel portal-panel-block">
			<?php if ( ! $members ) : ?>
				<div class="empty-state">
					<i><?php echo self::mk_icon( 'users', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
					<h4><?php esc_html_e( 'No members yet', 'thirtydayhomes' ); ?></h4>
					<p><?php esc_html_e( 'Landlord accounts appear here as people register.', 'thirtydayhomes' ); ?></p>
				</div>
			<?php else : ?>
				<?php
				// The member whose delete question is open, if any (asked in the page, never a pop-up).
				$deleting_member = isset( $_GET['delete_member'] ) ? (int) $_GET['delete_member'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				foreach ( $members as $member ) :
					$m_id     = (int) $member->ID;
					$m_status = Membership::status( $m_id );
					$m_plan   = Membership::plan( $m_id );
					$m_count  = Membership::listing_count( $m_id );
					$m_quota  = Membership::quota( $m_id );
					$line     = implode(
						' · ',
						array_filter(
							[
								$member->user_email,
								'' !== $m_plan ? $m_plan : '',
								$m_count > $m_quota
									? Membership::usage( $m_count, $m_quota )
									: sprintf(
										/* translators: 1: listings held, 2: allowance */
										__( '%1$s of %2$s listings', 'thirtydayhomes' ),
										number_format_i18n( $m_count ),
										number_format_i18n( $m_quota )
									),
							]
						)
					);
					?>
					<details class="portal-member" id="member-<?php echo esc_attr( (string) $m_id ); ?>"<?php echo $deleting_member === $m_id ? ' open' : ''; ?>>
						<summary class="portal-approval">
							<i class="portal-avatar" aria-hidden="true"><?php echo esc_html( strtoupper( mb_substr( trim( $member->display_name ), 0, 2 ) ) ); ?></i>
							<span>
								<b><?php echo esc_html( $member->display_name ); ?></b>
								<small><?php echo esc_html( $line ); ?></small>
							</span>
							<span class="status <?php echo esc_attr( $status_badges[ $m_status ] ?? '' ); ?>">
								<?php echo esc_html( $labels[ $m_status ] ?? $m_status ); ?>
							</span>
							<?php echo self::mk_icon( 'chevron-down', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</summary>

						<div class="portal-member-edit">
							<form class="portal-admin-form" method="post" action="<?php echo esc_url( self::mk_url( 'members' ) ); ?>">
								<input type="hidden" name="tdh_action" value="member_update">
								<input type="hidden" name="tdh_member" value="<?php echo esc_attr( (string) $m_id ); ?>">
								<?php wp_nonce_field( 'tdh_member_update', 'tdh_nonce' ); ?>
								<div class="form-grid">
									<div class="form-field"><label for="tdh-m-<?php echo esc_attr( (string) $m_id ); ?>-tdh-name"><?php esc_html_e( 'Name', 'thirtydayhomes' ); ?></label><input id="tdh-m-<?php echo esc_attr( (string) $m_id ); ?>-tdh-name" name="tdh_name" type="text" value="<?php echo esc_attr( $member->display_name ); ?>" required></div>
									<div class="form-field"><label for="tdh-m-<?php echo esc_attr( (string) $m_id ); ?>-tdh-email"><?php esc_html_e( 'Email', 'thirtydayhomes' ); ?></label><input id="tdh-m-<?php echo esc_attr( (string) $m_id ); ?>-tdh-email" name="tdh_email" type="email" value="<?php echo esc_attr( $member->user_email ); ?>" required></div>
									<div class="form-field"><label for="tdh-m-<?php echo esc_attr( (string) $m_id ); ?>-tdh-phone"><?php esc_html_e( 'Phone', 'thirtydayhomes' ); ?></label><input id="tdh-m-<?php echo esc_attr( (string) $m_id ); ?>-tdh-phone" name="tdh_phone" type="tel" value="<?php echo esc_attr( (string) get_user_meta( $m_id, '_tdh_phone', true ) ); ?>"></div>
									<div class="form-field"><label for="tdh-m-<?php echo esc_attr( (string) $m_id ); ?>-tdh-company"><?php esc_html_e( 'Company', 'thirtydayhomes' ); ?></label><input id="tdh-m-<?php echo esc_attr( (string) $m_id ); ?>-tdh-company" name="tdh_company" type="text" value="<?php echo esc_attr( (string) get_user_meta( $m_id, '_tdh_company', true ) ); ?>"></div>
									<div class="form-field"><label for="tdh-m-<?php echo esc_attr( (string) $m_id ); ?>-tdh-status"><?php esc_html_e( 'Membership status', 'thirtydayhomes' ); ?></label><select id="tdh-m-<?php echo esc_attr( (string) $m_id ); ?>-tdh-status" name="tdh_status"><?php foreach ( $labels as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $m_status, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></div>
									<div class="form-field"><label for="tdh-m-<?php echo esc_attr( (string) $m_id ); ?>-tdh-plan"><?php esc_html_e( 'Plan name', 'thirtydayhomes' ); ?></label><input id="tdh-m-<?php echo esc_attr( (string) $m_id ); ?>-tdh-plan" name="tdh_plan" type="text" value="<?php echo esc_attr( $m_plan ); ?>" placeholder="<?php esc_attr_e( 'Professional', 'thirtydayhomes' ); ?>"></div>
									<div class="form-field"><label for="tdh-m-<?php echo esc_attr( (string) $m_id ); ?>-tdh-quota"><?php esc_html_e( 'Listing allowance', 'thirtydayhomes' ); ?></label><input id="tdh-m-<?php echo esc_attr( (string) $m_id ); ?>-tdh-quota" name="tdh_quota" type="number" min="0" value="<?php echo esc_attr( (string) Membership::quota( $m_id ) ); ?>"></div>
									<div class="form-field"><label for="tdh-m-<?php echo esc_attr( (string) $m_id ); ?>-tdh-expires"><?php esc_html_e( 'Plan expiry', 'thirtydayhomes' ); ?></label><input id="tdh-m-<?php echo esc_attr( (string) $m_id ); ?>-tdh-expires" name="tdh_expires" type="date" value="<?php echo esc_attr( Membership::expires( $m_id ) ? wp_date( 'Y-m-d', Membership::expires( $m_id ) ) : '' ); ?>"></div>
								</div>
								<button class="primary" type="submit"><?php esc_html_e( 'Save member', 'thirtydayhomes' ); ?></button>
							</form>

							<div class="portal-member-actions">
								<form method="post" action="<?php echo esc_url( self::mk_url( 'members' ) ); ?>">
									<input type="hidden" name="tdh_action" value="member_reset"><input type="hidden" name="tdh_member" value="<?php echo esc_attr( (string) $m_id ); ?>">
									<?php wp_nonce_field( 'tdh_member_reset', 'tdh_nonce' ); ?>
									<button class="secondary" type="submit"><?php esc_html_e( 'Send password reset', 'thirtydayhomes' ); ?></button>
								</form>
								<?php if ( $deleting_member === $m_id ) : ?>
									<div class="portal-confirm" id="member-confirm-<?php echo esc_attr( (string) $m_id ); ?>" role="group" aria-labelledby="member-confirm-<?php echo esc_attr( (string) $m_id ); ?>-title" data-cancel="<?php echo esc_url( self::mk_url( 'members' ) . '#member-' . $m_id ); ?>">
										<h5 id="member-confirm-<?php echo esc_attr( (string) $m_id ); ?>-title" tabindex="-1">
											<?php
											/* translators: %s: member name */
											printf( esc_html__( 'Delete %s?', 'thirtydayhomes' ), esc_html( $member->display_name ) );
											?>
										</h5>
										<p>
											<?php
											echo esc_html(
												$m_count > 0
													/* translators: %s: number of homes */
													? sprintf( _n( 'Their account is removed. Their %s home is moved to your account, so nothing is lost.', 'Their account is removed. Their %s homes are moved to your account, so nothing is lost.', $m_count, 'thirtydayhomes' ), number_format_i18n( $m_count ) )
													: __( 'Their account is removed. They have no homes.', 'thirtydayhomes' )
											);
											?>
										</p>
										<div class="portal-confirm-actions">
											<form method="post" action="<?php echo esc_url( self::mk_url( 'members' ) ); ?>">
												<input type="hidden" name="tdh_action" value="member_delete"><input type="hidden" name="tdh_member" value="<?php echo esc_attr( (string) $m_id ); ?>"><input type="hidden" name="tdh_confirm_delete" value="1">
												<?php wp_nonce_field( 'tdh_member_delete', 'tdh_nonce' ); ?>
												<button class="danger" type="submit"><?php esc_html_e( 'Delete member', 'thirtydayhomes' ); ?></button>
											</form>
											<a class="secondary" href="<?php echo esc_url( self::mk_url( 'members' ) . '#member-' . $m_id ); ?>"><?php esc_html_e( 'Keep them', 'thirtydayhomes' ); ?></a>
										</div>
									</div>
									<script>
									/* Focus moves into the question; Escape means "Keep them". */
									( function () {
										var panel = document.getElementById( <?php echo wp_json_encode( 'member-confirm-' . $m_id ); ?> );
										if ( ! panel ) { return; }
										var title = panel.querySelector( 'h5' );
										if ( title ) { title.focus( { preventScroll: true } ); }
										panel.addEventListener( 'keydown', function ( event ) {
											if ( 'Escape' === event.key ) { window.location.href = panel.getAttribute( 'data-cancel' ); }
										} );
									} )();
									</script>
								<?php else : ?>
									<a class="portal-delete-link" href="<?php echo esc_url( add_query_arg( 'delete_member', $m_id, self::mk_url( 'members' ) ) . '#member-' . $m_id ); ?>">
										<?php esc_html_e( 'Delete member', 'thirtydayhomes' ); ?>
										<span class="screen-reader-text"><?php echo esc_html( $member->display_name ); ?></span>
									</a>
								<?php endif; ?>
							</div>
						</div>
					</details>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/* --- Inquiries --------------------------------------------------------- */

	private static function mk_inquiries(): void {

		/*
		 * Staff read a message on the same screen a landlord does, inside
		 * the portal. can_read() lets staff through to anything, so this is
		 * the ownership rule doing its job rather than a second one.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$open = isset( $_GET[ Inquiry::PARAM_OPEN ] ) ? (int) $_GET[ Inquiry::PARAM_OPEN ] : 0;

		if ( $open > 0 && Inquiry::can_read( $open ) ) {
			self::mk_inquiry_detail( $open );

			return;
		}

		/*
		 * Paged, and saying how many there are.
		 *
		 * This drew a flat twenty with no pager and no count, so on a site
		 * with more than twenty inquiries the rest were unreachable and
		 * nothing on screen admitted it. That is bad on its own and worse
		 * with D3: the delivery badge exists so staff can spot a message
		 * that never reached its landlord, and the twenty-first oldest one
		 * could never be seen at all.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$paged = isset( $_GET['ipage'] ) ? max( 1, (int) $_GET['ipage'] ) : 1;

		$found = new \WP_Query(
			[
				'post_type'      => Post_Types::INQUIRY,
				'post_status'    => 'any',
				'posts_per_page' => Inquiry::PER_PAGE,
				'paged'          => $paged,
			]
		);

		$inquiries = $found->posts;
		$total     = (int) $found->found_posts;
		$pages     = max( 1, (int) $found->max_num_pages );
		?>
		<div class="portal-heading">
			<span>
				<h1><?php esc_html_e( 'Inquiries', 'thirtydayhomes' ); ?></h1>
				<p>
					<?php
					if ( $total > 0 ) {
						printf(
							/* translators: %s: how many messages there are in total */
							esc_html( _n( '%s message, newest first. Open one to read and reply.', '%s messages, newest first. Open one to read and reply.', $total, 'thirtydayhomes' ) ),
							esc_html( number_format_i18n( $total ) )
						);
					} else {
						esc_html_e( 'Everything renters have sent, newest first. Open one to read and reply.', 'thirtydayhomes' );
					}
					?>
				</p>
			</span>
		</div>

		<div class="panel portal-panel-block">
			<?php if ( ! $inquiries ) : ?>
				<div class="empty-state">
					<i><?php echo self::mk_icon( 'mail', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
					<h4><?php esc_html_e( 'No inquiries yet', 'thirtydayhomes' ); ?></h4>
					<p><?php esc_html_e( 'Messages from renters appear here and reach the landlord by email.', 'thirtydayhomes' ); ?></p>
				</div>
			<?php else : ?>
				<?php
				foreach ( $inquiries as $inquiry ) :
					$name    = (string) get_post_meta( $inquiry->ID, '_tdh_renter_name', true );
					$name    = '' !== $name ? $name : __( 'Website visitor', 'thirtydayhomes' );
					$message = (string) get_post_meta( $inquiry->ID, '_tdh_message', true );
					$about   = (int) get_post_meta( $inquiry->ID, '_tdh_listing_id', true );
					$line    = implode(
						' · ',
						array_filter(
							[
								wp_html_excerpt( $message, 70, '…' ),
								$about ? get_the_title( $about ) : '',
							]
						)
					);

					/*
					 * Only the states that need somebody to act. A green
					 * "Emailed" on all twenty rows is noise, and noise is
					 * what hides the one row that did not go out.
					 */
					$sending = Notifications::state_of( (int) $inquiry->ID );
					$trouble = in_array( $sending['state'], [ Notifications::FAILED, Notifications::GIVEN_UP ], true );
					?>
					<div class="portal-inquiry<?php echo Inquiry::is_unread( (int) $inquiry->ID ) ? ' is-unread' : ''; ?>">
						<i class="portal-avatar" aria-hidden="true"><?php echo esc_html( strtoupper( mb_substr( trim( $name ), 0, 2 ) ) ); ?></i>
						<span>
							<?php
							/*
							 * Inside the portal, never wp-admin.
							 *
							 * This row used to link to post.php, which threw
							 * staff out of the branded marketplace and into
							 * the WordPress editor to read a message —
							 * register R29, and one of the seven UX mistakes
							 * the standards were written from.
							 */
							?>
							<b><a href="<?php echo esc_url( self::mk_url( 'inquiries', [ Inquiry::PARAM_OPEN => (string) $inquiry->ID ] ) ); ?>"><?php echo esc_html( $name ); ?></a></b>
							<small><?php echo esc_html( $line ); ?></small>
						</span>
						<?php if ( $trouble ) : ?>
							<span class="delivery-pill <?php echo Notifications::GIVEN_UP === $sending['state'] ? 'is-gone' : 'is-failed'; ?>">
								<?php echo esc_html( $sending['label'] ); ?>
							</span>
						<?php endif; ?>
						<?php if ( Inquiry::is_unread( (int) $inquiry->ID ) ) : ?>
							<em aria-label="<?php esc_attr_e( 'Unread', 'thirtydayhomes' ); ?>"></em>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>

				<?php if ( $pages > 1 ) : ?>
					<?php /* The same pager the landlord's inbox uses, so the two screens behave the same way and share its styling. */ ?>
					<nav class="inbox-pages" aria-label="<?php esc_attr_e( 'Inquiry pages', 'thirtydayhomes' ); ?>">
						<?php
						for ( $n = 1; $n <= $pages; $n++ ) :
							$here = $n === $paged;
							?>
							<a
								class="inbox-page<?php echo $here ? ' is-on' : ''; ?>"
								href="<?php echo esc_url( self::mk_url( 'inquiries', $n > 1 ? [ 'ipage' => (string) $n ] : [] ) ); ?>"
								<?php echo $here ? 'aria-current="page"' : ''; ?>
							><?php echo esc_html( number_format_i18n( $n ) ); ?></a>
						<?php endfor; ?>
					</nav>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One message, read by staff.
	 *
	 * The same facts a landlord sees, plus the two only staff need: which
	 * landlord owns the home, and whether the landlord has opened it yet —
	 * the question support is actually asked ("did they get my message?").
	 */
	private static function mk_inquiry_detail( int $id ): void {

		$who     = (string) get_post_meta( $id, '_tdh_renter_name', true );
		$who     = '' !== $who ? $who : __( 'A renter', 'thirtydayhomes' );
		$email   = (string) get_post_meta( $id, '_tdh_renter_email', true );
		$phone   = (string) get_post_meta( $id, '_tdh_renter_phone', true );
		$stay    = Inquiry::stay_label( (string) get_post_meta( $id, '_tdh_stay_length', true ) );
		$move_in = (string) get_post_meta( $id, '_tdh_move_in', true );
		$body    = (string) get_post_meta( $id, '_tdh_message', true );
		$about   = Inquiry::about( $id );
		$owner   = $about['id'] ? (int) get_post_field( 'post_author', $about['id'] ) : 0;
		$owner_u = $owner ? get_userdata( $owner ) : false;
		$unread  = Inquiry::is_unread( $id );
		$sending = Notifications::state_of( $id );
		$notice  = Notifications::resend_notice();

		?>
		<a class="inbox-back" href="<?php echo esc_url( self::mk_url( 'inquiries' ) ); ?>">
			<?php esc_html_e( '← Back to inquiries', 'thirtydayhomes' ); ?>
		</a>

		<?php if ( '' !== $notice ) : ?>
			<p class="portal-notice<?php echo Notifications::RESENT === self::mk_done() ? '' : ' is-warn'; ?>" role="status">
				<?php echo esc_html( $notice ); ?>
			</p>
		<?php endif; ?>

		<div class="panel portal-panel-block inquiry-detail">

			<div class="inquiry-from">
				<h2><?php echo esc_html( $who ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: 1: home title, 2: date received */
						esc_html__( 'About %1$s · %2$s', 'thirtydayhomes' ),
						esc_html( $about['name'] ),
						esc_html( get_the_date( '', $id ) )
					);
					?>
				</p>
			</div>

			<dl class="inquiry-facts">
				<div>
					<dt><?php esc_html_e( 'Renter email', 'thirtydayhomes' ); ?></dt>
					<dd><?php echo '' !== $email ? '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>' : esc_html__( 'Not given', 'thirtydayhomes' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Renter phone', 'thirtydayhomes' ); ?></dt>
					<dd><?php echo esc_html( '' !== $phone ? $phone : __( 'Not given', 'thirtydayhomes' ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Landlord', 'thirtydayhomes' ); ?></dt>
					<dd><?php echo esc_html( $owner_u ? $owner_u->display_name : __( 'Listing removed', 'thirtydayhomes' ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Opened by the landlord', 'thirtydayhomes' ); ?></dt>
					<dd><?php echo esc_html( $unread ? __( 'Not yet', 'thirtydayhomes' ) : __( 'Yes', 'thirtydayhomes' ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Move-in', 'thirtydayhomes' ); ?></dt>
					<dd>
						<?php
						echo esc_html(
							'' !== $move_in && class_exists( '\TDH\Availability' )
								? Availability::format_day( $move_in )
								: ( '' !== $move_in ? $move_in : __( 'Not given', 'thirtydayhomes' ) )
						);
						?>
					</dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Length of stay', 'thirtydayhomes' ); ?></dt>
					<dd><?php echo esc_html( '' !== $stay ? $stay : __( 'Not given', 'thirtydayhomes' ) ); ?></dd>
				</div>
			</dl>

			<div class="inquiry-message">
				<h3><?php esc_html_e( 'Their message', 'thirtydayhomes' ); ?></h3>
				<p><?php echo nl2br( esc_html( $body ) ); ?></p>
			</div>

			<?php self::mk_delivery( $id, $sending ); ?>
		</div>
		<?php
	}

	/** Which resend outcome the address bar is carrying, or ''. */
	private static function mk_done(): string {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only flag.
		return isset( $_GET[ Notifications::PARAM_DONE ] ) ? sanitize_key( wp_unslash( (string) $_GET[ Notifications::PARAM_DONE ] ) ) : '';
	}

	/**
	 * Did the landlord's email get through, and what to do if it did not.
	 *
	 * Staff only, and it is the question support is actually rung about. A
	 * landlord is not shown this: they are reading the message on this very
	 * screen, so whether a copy of it also reached their inbox is our
	 * problem to fix, not a worry to hand them.
	 *
	 * @param array{state:string,label:string,detail:string} $sending
	 */
	private static function mk_delivery( int $id, array $sending ): void {

		$classes = [
			Notifications::SENT     => 'is-sent',
			Notifications::QUEUED   => 'is-queued',
			Notifications::FAILED   => 'is-failed',
			Notifications::GIVEN_UP => 'is-gone',
		];

		$to = '';

		foreach ( Notifications::for_inquiry( $id ) as $row ) {
			if ( Notifications::CHANNEL_EMAIL === $row['channel'] ) {
				$to = (string) $row['recipient'];
				break;
			}
		}
		?>
		<div class="inquiry-delivery">
			<h3><?php esc_html_e( 'Delivery', 'thirtydayhomes' ); ?></h3>

			<?php if ( '' === $sending['state'] ) : ?>
				<?php
				/*
				 * No row at all. Almost always an inquiry from before D3, or
				 * a Contact-page message, which has no landlord to email —
				 * so it says which, rather than leaving a blank space that
				 * reads as a broken screen.
				 */
				?>
				<p class="delivery-detail"><?php esc_html_e( 'No email was sent for this message. Messages received before email delivery was switched on have no record here.', 'thirtydayhomes' ); ?></p>
			<?php else : ?>
				<p class="delivery-state">
					<span class="delivery-pill <?php echo esc_attr( (string) ( $classes[ $sending['state'] ] ?? 'is-queued' ) ); ?>">
						<?php echo esc_html( $sending['label'] ); ?>
					</span>
					<?php if ( '' !== $to ) : ?>
						<span class="delivery-to">
							<?php
							printf(
								/* translators: %s: an email address */
								esc_html__( 'to %s', 'thirtydayhomes' ),
								esc_html( $to )
							);
							?>
						</span>
					<?php endif; ?>
				</p>

				<?php if ( '' !== $sending['detail'] ) : ?>
					<p class="delivery-detail"><?php echo esc_html( $sending['detail'] ); ?></p>
				<?php endif; ?>

				<?php if ( Notifications::SENT !== $sending['state'] ) : ?>
					<?php
					/*
					 * Says what is happening while it happens.
					 *
					 * This send is synchronous and a mail server that is not
					 * answering costs fifteen seconds, during which the page
					 * did not move at all — so the honest reading was "the
					 * button is broken", and the next thing anybody does is
					 * press it again. The second press is already harmless
					 * (a 30-second lock), but the silence was not.
					 *
					 * Progressive enhancement: without JavaScript the button
					 * is an ordinary submit and everything still works.
					 *
					 * The label lives in a data attribute rather than inside
					 * the handler. Written straight into the attribute it
					 * arrived as wp_json_encode's DOUBLE-quoted string, whose
					 * first quote closed `onsubmit=\"` and left the rest of
					 * the script sitting in the tag as nonsense attributes —
					 * a broken handler and broken markup in one line.
					 *
					 * Disabling waits a tick: a button disabled during its
					 * own submit is not posted, and setTimeout puts it after
					 * the form has been handed over.
					 */
					?>
					<form
						method="post"
						class="delivery-resend"
						data-sending="<?php esc_attr_e( 'Sending…', 'thirtydayhomes' ); ?>"
						onsubmit="var b=this.querySelector('button'),t=this.dataset.sending;if(b){setTimeout(function(){b.disabled=true;b.textContent=t;},0);}"
					>
						<?php echo Notifications::resend_fields( $id ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<button class="secondary" type="submit"><?php esc_html_e( 'Send it again', 'thirtydayhomes' ); ?></button>
						<span class="delivery-hint">
							<?php esc_html_e( 'Tries the address on the home again, from the beginning. The renter is not emailed.', 'thirtydayhomes' ); ?>
						</span>
					</form>
				<?php endif; ?>
			<?php endif; ?>

			<?php
			/*
			 * The text, when the site sends them (D4). Its own line under
			 * the email's, in the same words-not-colour pills, so "Emailed
			 * · Not texted (opted out)" reads as two facts rather than a
			 * contradiction. Nothing at all when texting is off for the
			 * site — an absent row is not a failure.
			 */
			$texting = Sms::is_enabled() ? Sms::state_of( $id ) : [ 'state' => '', 'label' => '', 'detail' => '' ];

			if ( '' !== $texting['state'] ) :
				$text_pill = [
					Notifications::SENT     => 'is-sent',
					Notifications::QUEUED   => 'is-queued',
					Notifications::FAILED   => 'is-failed',
					Notifications::GIVEN_UP => 'is-gone',
					Sms::SKIPPED            => 'is-queued',
				];
				?>
				<p class="delivery-state delivery-state--sms">
					<span class="delivery-pill <?php echo esc_attr( $text_pill[ $texting['state'] ] ?? 'is-queued' ); ?>"><?php echo esc_html( $texting['label'] ); ?></span>
					<?php if ( '' !== $texting['detail'] ) : ?>
						<span class="delivery-to"><?php echo esc_html( $texting['detail'] ); ?></span>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* --- Facilities -------------------------------------------------------- */

	private static function mk_facilities(): void {

		$facilities = get_posts(
			[
				'post_type'      => Post_Types::FACILITY,
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);
		?>
		<div class="portal-heading">
			<span>
				<h1><?php esc_html_e( 'Facilities', 'thirtydayhomes' ); ?></h1>
				<p><?php esc_html_e( 'The hospitals and campuses the distance search measures from.', 'thirtydayhomes' ); ?></p>
			</span>
		</div>

		<?php self::mk_service_notice(); ?>

		<?php
		// The facility whose delete question is open, if any.
		$deleting = isset( $_GET['delete_facility'] ) ? (int) $_GET['delete_facility'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>

		<details class="panel portal-panel-block portal-editor"<?php echo ! $facilities ? ' open' : ''; ?>>
			<summary><span><b><?php esc_html_e( 'Add a facility', 'thirtydayhomes' ); ?></b><small><?php esc_html_e( 'Add a hospital or campus used by distance search.', 'thirtydayhomes' ); ?></small></span><?php echo self::mk_icon( 'plus', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></summary>
			<form class="portal-admin-form" method="post" action="<?php echo esc_url( self::mk_url( 'facilities' ) ); ?>">
				<input type="hidden" name="tdh_action" value="facility_save">
				<?php wp_nonce_field( 'tdh_facility_save', 'tdh_nonce' ); ?>
				<?php self::mk_facility_fields(); ?>
				<button class="primary" type="submit"><?php esc_html_e( 'Add facility', 'thirtydayhomes' ); ?></button>
			</form>
		</details>

		<div class="panel portal-panel-block">
			<?php if ( ! $facilities ) : ?>
				<div class="empty-state">
					<i><?php echo self::mk_icon( 'stethoscope', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
					<h4><?php esc_html_e( 'No facilities yet', 'thirtydayhomes' ); ?></h4>
					<p><?php esc_html_e( 'Use “Add a facility” above to create the first search destination.', 'thirtydayhomes' ); ?></p>
				</div>
			<?php else : ?>
				<?php foreach ( $facilities as $facility ) : ?>
					<?php
					$place     = Geocoder::state( (int) $facility->ID );
					$place_tag = Geocoder::chip( $place );
					$asking    = $deleting === (int) $facility->ID;
					$name      = get_the_title( $facility );
					?>
					<details class="portal-member" id="facility-<?php echo esc_attr( (string) $facility->ID ); ?>"<?php echo $asking ? ' open' : ''; ?>>
						<summary class="portal-approval">
							<i aria-hidden="true"><?php echo self::mk_icon( 'stethoscope', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
							<span><b><?php echo esc_html( $name ); ?></b><small><?php echo esc_html( (string) get_post_meta( $facility->ID, '_tdh_street_address', true ) ); ?></small></span>
							<?php if ( in_array( $place, [ 'failed', 'pending' ], true ) ) : // Quiet when it has a place, as on Listings. ?>
								<span class="status <?php echo esc_attr( $place_tag['badge'] ); ?>"><?php echo esc_html( $place_tag['label'] ); ?></span>
							<?php endif; ?>
							<span class="status <?php echo get_post_meta( $facility->ID, '_tdh_active', true ) ? 'live' : 'inactive'; ?>"><?php echo get_post_meta( $facility->ID, '_tdh_active', true ) ? esc_html__( 'Active', 'thirtydayhomes' ) : esc_html__( 'Inactive', 'thirtydayhomes' ); ?></span>
							<?php echo self::mk_icon( 'chevron-down', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</summary>
						<div class="portal-member-edit">
							<?php if ( in_array( $place, [ 'failed', 'pending' ], true ) ) : ?>
								<p class="portal-facility-location is-<?php echo esc_attr( $place ); ?>"><?php echo esc_html( Geocoder::reason( (int) $facility->ID ) ); ?></p>
							<?php endif; ?>
							<form class="portal-admin-form" method="post" action="<?php echo esc_url( self::mk_url( 'facilities' ) ); ?>">
								<input type="hidden" name="tdh_action" value="facility_save"><input type="hidden" name="tdh_facility" value="<?php echo esc_attr( (string) $facility->ID ); ?>">
								<?php wp_nonce_field( 'tdh_facility_save', 'tdh_nonce' ); ?>
								<?php self::mk_facility_fields( $facility->ID ); ?>
								<button class="primary" type="submit"><?php esc_html_e( 'Save facility', 'thirtydayhomes' ); ?></button>
							</form>
							<div class="portal-member-actions">
								<?php
								/*
								 * Delete asks inside the page (?delete_facility=ID), as a
								 * landlord's Delete does — a browser pop-up is easy to click
								 * through and cannot say what the delete takes with it.
								 */
								?>
								<?php if ( $asking ) : ?>
									<div class="portal-confirm" id="facility-confirm-<?php echo esc_attr( (string) $facility->ID ); ?>" role="group" aria-labelledby="facility-confirm-<?php echo esc_attr( (string) $facility->ID ); ?>-title" data-cancel="<?php echo esc_url( self::mk_url( 'facilities' ) . '#facility-' . $facility->ID ); ?>">
										<h5 id="facility-confirm-<?php echo esc_attr( (string) $facility->ID ); ?>-title" tabindex="-1">
											<?php
											/* translators: %s: facility name */
											printf( esc_html__( 'Delete “%s”?', 'thirtydayhomes' ), esc_html( $name ) );
											?>
										</h5>
										<p><?php esc_html_e( 'It disappears straight away, and homes stop showing their distance to it. This can’t be undone — to hide it for a while instead, untick “Active in renter search”.', 'thirtydayhomes' ); ?></p>
										<div class="portal-confirm-actions">
											<form method="post" action="<?php echo esc_url( self::mk_url( 'facilities' ) ); ?>">
												<input type="hidden" name="tdh_action" value="facility_delete"><input type="hidden" name="tdh_facility" value="<?php echo esc_attr( (string) $facility->ID ); ?>"><input type="hidden" name="tdh_confirm_delete" value="1">
												<?php wp_nonce_field( 'tdh_facility_delete', 'tdh_nonce' ); ?>
												<button class="danger" type="submit"><?php esc_html_e( 'Delete facility', 'thirtydayhomes' ); ?></button>
											</form>
											<a class="secondary" href="<?php echo esc_url( self::mk_url( 'facilities' ) . '#facility-' . $facility->ID ); ?>"><?php esc_html_e( 'Keep it', 'thirtydayhomes' ); ?></a>
										</div>
									</div>
									<script>
									/* Focus moves into the question; Escape means "Keep it". */
									( function () {
										var panel = document.getElementById( <?php echo wp_json_encode( 'facility-confirm-' . $facility->ID ); ?> );
										if ( ! panel ) { return; }
										var title = panel.querySelector( 'h5' );
										if ( title ) { title.focus( { preventScroll: true } ); }
										panel.addEventListener( 'keydown', function ( event ) {
											if ( 'Escape' === event.key ) { window.location.href = panel.getAttribute( 'data-cancel' ); }
										} );
									} )();
									</script>
								<?php else : ?>
									<a class="portal-delete-link" href="<?php echo esc_url( add_query_arg( 'delete_facility', $facility->ID, self::mk_url( 'facilities' ) ) . '#facility-' . $facility->ID ); ?>">
										<?php esc_html_e( 'Delete facility', 'thirtydayhomes' ); ?>
										<span class="screen-reader-text"><?php echo esc_html( $name ); ?></span>
									</a>
								<?php endif; ?>
							</div>
						</div>
					</details>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function mk_facility_fields( int $facility_id = 0 ): void {
		$value = static fn( string $key, string $default = '' ): string => $facility_id ? (string) get_post_meta( $facility_id, $key, true ) : $default;
		$type  = $value( '_tdh_facility_type', 'hospital' );
		?>
		<div class="form-grid">
			<div class="form-field"><label for="tdh-f-<?php echo esc_attr( (string) (int) $facility_id ); ?>-tdh-title"><?php esc_html_e( 'Facility name', 'thirtydayhomes' ); ?></label><input id="tdh-f-<?php echo esc_attr( (string) (int) $facility_id ); ?>-tdh-title" name="tdh_title" type="text" value="<?php echo esc_attr( $facility_id ? get_the_title( $facility_id ) : '' ); ?>" required></div>
			<div class="form-field"><label for="tdh-f-<?php echo esc_attr( (string) (int) $facility_id ); ?>-tdh-meta-tdh-facility-type"><?php esc_html_e( 'Facility type', 'thirtydayhomes' ); ?></label><select id="tdh-f-<?php echo esc_attr( (string) (int) $facility_id ); ?>-tdh-meta-tdh-facility-type" name="tdh_meta[_tdh_facility_type]"><?php foreach ( Fields::facility_schema()['_tdh_facility_type']['options'] as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></div>
			<div class="form-field"><label for="tdh-f-<?php echo esc_attr( (string) (int) $facility_id ); ?>-tdh-meta-tdh-street-address"><?php esc_html_e( 'Street address', 'thirtydayhomes' ); ?></label><input id="tdh-f-<?php echo esc_attr( (string) (int) $facility_id ); ?>-tdh-meta-tdh-street-address" name="tdh_meta[_tdh_street_address]" type="text" value="<?php echo esc_attr( $value( '_tdh_street_address' ) ); ?>"></div>
			<div class="form-field"><label for="tdh-f-<?php echo esc_attr( (string) (int) $facility_id ); ?>-tdh-meta-tdh-state"><?php esc_html_e( 'State', 'thirtydayhomes' ); ?></label><input id="tdh-f-<?php echo esc_attr( (string) (int) $facility_id ); ?>-tdh-meta-tdh-state" name="tdh_meta[_tdh_state]" type="text" value="<?php echo esc_attr( $value( '_tdh_state', 'PA' ) ); ?>"></div>
			<div class="form-field"><label for="tdh-f-<?php echo esc_attr( (string) (int) $facility_id ); ?>-tdh-meta-tdh-zip"><?php esc_html_e( 'ZIP code', 'thirtydayhomes' ); ?></label><input id="tdh-f-<?php echo esc_attr( (string) (int) $facility_id ); ?>-tdh-meta-tdh-zip" name="tdh_meta[_tdh_zip]" type="text" value="<?php echo esc_attr( $value( '_tdh_zip' ) ); ?>"></div>
			<div class="form-field"><label for="tdh-f-<?php echo esc_attr( (string) (int) $facility_id ); ?>-tdh-meta-tdh-sort-order"><?php esc_html_e( 'Sort order', 'thirtydayhomes' ); ?></label><input id="tdh-f-<?php echo esc_attr( (string) (int) $facility_id ); ?>-tdh-meta-tdh-sort-order" name="tdh_meta[_tdh_sort_order]" type="number" value="<?php echo esc_attr( $value( '_tdh_sort_order', '0' ) ); ?>"></div>
			<div class="form-field"><label for="tdh-f-<?php echo esc_attr( (string) (int) $facility_id ); ?>-tdh-meta-tdh-lat"><?php esc_html_e( 'Latitude', 'thirtydayhomes' ); ?></label><input id="tdh-f-<?php echo esc_attr( (string) (int) $facility_id ); ?>-tdh-meta-tdh-lat" name="tdh_meta[_tdh_lat]" type="number" step="any" min="-90" max="90" value="<?php echo esc_attr( $value( '_tdh_lat' ) ); ?>"></div>
			<div class="form-field"><label for="tdh-f-<?php echo esc_attr( (string) (int) $facility_id ); ?>-tdh-meta-tdh-lng"><?php esc_html_e( 'Longitude', 'thirtydayhomes' ); ?></label><input id="tdh-f-<?php echo esc_attr( (string) (int) $facility_id ); ?>-tdh-meta-tdh-lng" name="tdh_meta[_tdh_lng]" type="number" step="any" min="-180" max="180" value="<?php echo esc_attr( $value( '_tdh_lng' ) ); ?>"></div>
		</div>
		<p class="portal-form-note"><?php esc_html_e( 'Latitude and longitude fill in from the address when you save. Type your own to override them; clear both to look the address up again.', 'thirtydayhomes' ); ?></p>
		<label class="portal-check"><input type="hidden" name="tdh_meta[_tdh_active]" value="0"><input name="tdh_meta[_tdh_active]" type="checkbox" value="1" <?php checked( $facility_id ? (bool) get_post_meta( $facility_id, '_tdh_active', true ) : true ); ?>> <?php esc_html_e( 'Active in renter search', 'thirtydayhomes' ); ?></label>
		<?php
	}

	/**
	 * How many members hold this stored membership status.
	 */
	private static function count_members( string $status ): int {

		$query = new \WP_User_Query(
			[
				'meta_key'    => Membership::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => $status,                 // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => true,
			]
		);

		return (int) $query->get_total();
	}

	/**
	 * Listings across the whole site in the given statuses.
	 *
	 * @param string[] $statuses
	 */
	private static function count_site_listings( array $statuses ): int {

		$query = new \WP_Query(
			[
				'post_type'             => Post_Types::LISTING,
				'post_status'           => $statuses,
				'posts_per_page'        => 1,
				'fields'                => 'ids',
				'tdh_bypass_visibility' => true,
			]
		);

		return (int) $query->found_posts;
	}

	/**
	 * The "Your listings" panel — shared by the overview and the dedicated
	 * My listings screen, so the two can never drift apart.
	 */
	private static function listings_panel( int $user_id, int $used, int $quota, string $context ): void {
		?>
		<div class="panel" id="listings">
			<div class="panel-title">
				<h3><?php esc_html_e( 'Your listings', 'thirtydayhomes' ); ?></h3>
				<?php if ( $quota > 0 ) : ?>
					<span class="panel-note">
						<?php echo esc_html( Membership::usage( $used, $quota ) ); ?>
					</span>
				<?php endif; ?>
			</div>
			<?php if ( $quota > 0 && $used > $quota && ! Accounts::is_staff( $user_id ) ) : ?>
				<p class="portal-over-plan">
					<?php
					printf(
						/* translators: 1: the plan's allowance, 2: homes to delete */
						esc_html( _n( 'Your plan covers %1$s home. To add another, delete %2$s or move to a larger plan.', 'Your plan covers %1$s homes. To add another, delete %2$s or move to a larger plan.', $quota, 'thirtydayhomes' ) ),
						esc_html( number_format_i18n( $quota ) ),
						esc_html( number_format_i18n( $used - $quota + 1 ) )
					);
					?>
				</p>
			<?php endif; ?>
			<?php Listing_Manage_Render::render( $user_id, $context ); ?>
		</div>
		<?php
	}

	/**
	 * The inquiries panel — the overview hands it four, the dedicated
	 * screen hands it the history.
	 *
	 * @param array<int,array{name:string,excerpt:string,initials:string,unread:bool}> $inquiries
	 */
	/* ---------------------------------------------------------------------
	 * The landlord's inbox (D2)
	 * ------------------------------------------------------------------ */

	/**
	 * A URL inside the Inquiries screen.
	 *
	 * `account`, not `dashboard`. There is no page seeded under the key
	 * "dashboard", and Accounts::url() answers an unknown key with the
	 * HOME PAGE rather than an empty string — so every link in this inbox
	 * pointed at the front of the site and nothing looked wrong until one
	 * was clicked. The landlord portal is /account/.
	 */
	private static function inbox_url( array $args = [] ): string {

		$base = add_query_arg( 'view', 'inquiries', Accounts::url( 'account' ) );

		return $args ? add_query_arg( array_map( 'rawurlencode', $args ), $base ) : $base;
	}

	/**
	 * The Inquiries screen: one message, or a page of the inbox.
	 *
	 * One entry point rather than two views, because opening a message is
	 * not a different screen to a landlord — it is the same inbox with one
	 * message showing, and Back must return to the tab and page they came
	 * from rather than to the top of everything.
	 */
	private static function inquiries_screen( int $user_id ): void {

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$open   = isset( $_GET[ Inquiry::PARAM_OPEN ] ) ? (int) $_GET[ Inquiry::PARAM_OPEN ] : 0;
		$filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( (string) $_GET['filter'] ) ) : Inquiry::FILTER_ALL;
		$page   = isset( $_GET['ipage'] ) ? max( 1, (int) $_GET['ipage'] ) : 1;
		// phpcs:enable

		if ( ! array_key_exists( $filter, Inquiry::filters() ) ) {
			$filter = Inquiry::FILTER_ALL;
		}

		/*
		 * A message this person may not read is answered exactly as one
		 * that does not exist: the inbox, with nothing said. Two different
		 * answers would let somebody walk the ids and learn which exist.
		 */
		if ( $open > 0 && Inquiry::can_read( $open, $user_id ) ) {
			self::inquiry_detail( $open, $filter, $page );

			return;
		}

		self::inbox_list( $user_id, $filter, $page );
	}

	/** One page of the inbox. */
	private static function inbox_list( int $user_id, string $filter, int $page ): void {

		$icon   = static fn( string $name, int $size = 19 ): string =>
			function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size ) : '';
		$inbox  = Inquiry::inbox( $user_id, $filter, $page );
		$unread = Inquiry::unread_count( $user_id );
		$notice = Inquiry::archive_notice();

		?>
		<?php // No heading here: the portal already prints one for the view. ?>
		<?php if ( '' !== $notice ) : ?>
			<p class="portal-notice" role="status"><?php echo esc_html( $notice ); ?></p>
		<?php endif; ?>

		<div class="panel portal-panel-block">

			<?php // Tabs. The unread count rides on its own tab, where it is the thing being counted. ?>
			<div class="inbox-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Filter inquiries', 'thirtydayhomes' ); ?>">
				<?php foreach ( Inquiry::filters() as $key => $label ) : ?>
					<a
						class="inbox-tab<?php echo $key === $filter ? ' is-on' : ''; ?>"
						href="<?php echo esc_url( self::inbox_url( Inquiry::FILTER_ALL === $key ? [] : [ 'filter' => $key ] ) ); ?>"
						<?php echo $key === $filter ? 'aria-current="true"' : ''; ?>
					>
						<?php echo esc_html( $label ); ?>
						<?php if ( Inquiry::FILTER_UNREAD === $key && $unread > 0 ) : ?>
							<em><?php echo esc_html( number_format_i18n( $unread ) ); ?></em>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</div>

			<?php if ( ! $inbox['items'] ) : ?>
				<?php
				/*
				 * Three different empty states. "No inquiries yet" on the
				 * Unread tab would read as "you have never had one", which
				 * is a different and alarming thing to tell somebody whose
				 * inbox is simply all caught up.
				 */
				$empty = [
					Inquiry::FILTER_ALL      => [
						__( 'No inquiries yet', 'thirtydayhomes' ),
						__( 'When a renter asks about one of your homes, it appears here. Homes with photos and a description get the most.', 'thirtydayhomes' ),
					],
					Inquiry::FILTER_UNREAD   => [
						__( 'Nothing unread', 'thirtydayhomes' ),
						__( 'You have opened every message. They are all on the All tab.', 'thirtydayhomes' ),
					],
					Inquiry::FILTER_ARCHIVED => [
						__( 'Nothing archived', 'thirtydayhomes' ),
						__( 'Messages you archive are kept here, out of your inbox.', 'thirtydayhomes' ),
					],
				][ $filter ];
				?>
				<div class="empty-state">
					<i><?php echo $icon( 'mail', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
					<h4><?php echo esc_html( $empty[0] ); ?></h4>
					<p><?php echo esc_html( $empty[1] ); ?></p>
				</div>
			<?php else : ?>

				<p class="inbox-count">
					<?php
					printf(
						/* translators: %s: number of messages */
						esc_html( _n( '%s message', '%s messages', (int) $inbox['total'], 'thirtydayhomes' ) ),
						esc_html( number_format_i18n( (int) $inbox['total'] ) )
					);
					?>
				</p>

				<ul class="inbox-rows">
					<?php
					foreach ( $inbox['items'] as $item ) :
						$id     = (int) $item->ID;
						$who    = (string) get_post_meta( $id, '_tdh_renter_name', true );
						$who    = '' !== $who ? $who : __( 'A renter', 'thirtydayhomes' );
						$about  = Inquiry::about( $id );
						$new    = Inquiry::is_unread( $id );
						$body   = (string) get_post_meta( $id, '_tdh_message', true );
						$link   = self::inbox_url(
							array_filter(
								[
									Inquiry::PARAM_OPEN => (string) $id,
									'filter'            => Inquiry::FILTER_ALL === $filter ? '' : $filter,
									'ipage'             => $page > 1 ? (string) $page : '',
								]
							)
						);
						?>
						<li class="inbox-row<?php echo $new ? ' is-unread' : ''; ?>">
							<a class="inbox-open" href="<?php echo esc_url( $link ); ?>">
								<span class="inbox-who">
									<b><?php echo esc_html( $who ); ?></b>
									<?php // The state is a WORD as well as a weight — never colour alone. ?>
									<?php if ( $new ) : ?>
										<em class="inbox-new"><?php esc_html_e( 'New', 'thirtydayhomes' ); ?></em>
									<?php endif; ?>
								</span>
								<span class="inbox-about<?php echo $about['removed'] ? ' is-gone' : ''; ?>">
									<?php echo esc_html( $about['name'] ); ?>
								</span>
								<span class="inbox-excerpt"><?php echo esc_html( wp_html_excerpt( $body, 90, '…' ) ); ?></span>
								<time class="inbox-when" datetime="<?php echo esc_attr( get_post_time( 'c', true, $item ) ); ?>">
									<?php echo esc_html( get_the_date( '', $item ) ); ?>
								</time>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>

				<?php if ( (int) $inbox['pages'] > 1 ) : ?>
					<nav class="inbox-pages" aria-label="<?php esc_attr_e( 'Inquiry pages', 'thirtydayhomes' ); ?>">
						<?php
						$args = Inquiry::FILTER_ALL === $filter ? [] : [ 'filter' => $filter ];

						for ( $n = 1; $n <= (int) $inbox['pages']; $n++ ) :
							$here = $n === (int) $inbox['page'];
							?>
							<a
								class="inbox-page<?php echo $here ? ' is-on' : ''; ?>"
								href="<?php echo esc_url( self::inbox_url( $args + ( $n > 1 ? [ 'ipage' => (string) $n ] : [] ) ) ); ?>"
								<?php echo $here ? 'aria-current="page"' : ''; ?>
							><?php echo esc_html( number_format_i18n( $n ) ); ?></a>
						<?php endfor; ?>
					</nav>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One message, in full.
	 *
	 * Opening it is what marks it read — a GET that changes one boolean,
	 * idempotent, so the Back button and a reload cannot do any harm.
	 */
	private static function inquiry_detail( int $id, string $filter, int $page ): void {

		Inquiry::mark_read( $id );

		$who      = (string) get_post_meta( $id, '_tdh_renter_name', true );
		$who      = '' !== $who ? $who : __( 'A renter', 'thirtydayhomes' );
		$email    = (string) get_post_meta( $id, '_tdh_renter_email', true );
		$phone    = (string) get_post_meta( $id, '_tdh_renter_phone', true );
		$move_in  = (string) get_post_meta( $id, '_tdh_move_in', true );
		$stay     = Inquiry::stay_label( (string) get_post_meta( $id, '_tdh_stay_length', true ) );
		$body     = (string) get_post_meta( $id, '_tdh_message', true );
		$about    = Inquiry::about( $id );
		$archived = Inquiry::is_archived( $id );

		$back = self::inbox_url(
			array_filter(
				[
					'filter' => Inquiry::FILTER_ALL === $filter ? '' : $filter,
					'ipage'  => $page > 1 ? (string) $page : '',
				]
			)
		);

		?>
		<?php
		/*
		 * The portal prints "Inquiries" above this, so the message's own
		 * title lives inside the panel. Two <h1>s on one screen is both a
		 * duplicate heading for a screen reader and, as the first walk
		 * showed, the word "Inquiries" twice down the page.
		 */
		?>
		<a class="inbox-back" href="<?php echo esc_url( $back ); ?>">
			<?php esc_html_e( '← Back to inquiries', 'thirtydayhomes' ); ?>
		</a>

		<div class="panel portal-panel-block inquiry-detail">

			<div class="inquiry-from">
				<h2><?php echo esc_html( $who ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: 1: the home's name, 2: when it arrived */
						esc_html__( 'About %1$s · %2$s', 'thirtydayhomes' ),
						esc_html( $about['name'] ),
						esc_html( get_the_date( '', $id ) )
					);
					?>
				</p>
			</div>


			<?php // Contact details first: this is what the landlord opened it for. ?>
			<dl class="inquiry-facts">
				<div>
					<dt><?php esc_html_e( 'Email', 'thirtydayhomes' ); ?></dt>
					<dd>
						<?php if ( '' !== $email ) : ?>
							<a href="<?php echo esc_url( 'mailto:' . $email ); ?>"><?php echo esc_html( $email ); ?></a>
						<?php else : ?>
							<?php esc_html_e( 'Not given', 'thirtydayhomes' ); ?>
						<?php endif; ?>
					</dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Phone', 'thirtydayhomes' ); ?></dt>
					<dd>
						<?php if ( '' !== $phone ) : ?>
							<a href="<?php echo esc_url( 'tel:' . preg_replace( '/[^\d+]/', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a>
						<?php else : ?>
							<?php esc_html_e( 'Not given', 'thirtydayhomes' ); ?>
						<?php endif; ?>
					</dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Move-in', 'thirtydayhomes' ); ?></dt>
					<dd>
						<?php
						echo esc_html(
							'' !== $move_in && class_exists( '\TDH\Availability' )
								? Availability::format_day( $move_in )
								: ( '' !== $move_in ? $move_in : __( 'Not given', 'thirtydayhomes' ) )
						);
						?>
					</dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Length of stay', 'thirtydayhomes' ); ?></dt>
					<dd><?php echo esc_html( '' !== $stay ? $stay : __( 'Not given', 'thirtydayhomes' ) ); ?></dd>
				</div>
			</dl>

			<div class="inquiry-message">
				<h3><?php esc_html_e( 'Their message', 'thirtydayhomes' ); ?></h3>
				<p><?php echo nl2br( esc_html( $body ) ); ?></p>
			</div>

			<div class="inquiry-actions">
				<?php if ( '' !== $email ) : ?>
					<a class="primary" href="<?php echo esc_url( 'mailto:' . $email ); ?>">
						<?php esc_html_e( 'Reply by email', 'thirtydayhomes' ); ?>
					</a>
				<?php endif; ?>

				<?php
				/*
				 * Archiving is not destructive — nothing is deleted and it
				 * comes straight back — so it is a secondary button and
				 * needs no confirmation.
				 */
				?>
				<form method="post" action="<?php echo esc_url( $back ); ?>">
					<?php echo Inquiry::archive_fields( $id, $archived ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<button type="submit" class="secondary">
						<?php echo esc_html( $archived ? __( 'Move back to inbox', 'thirtydayhomes' ) : __( 'Archive', 'thirtydayhomes' ) ); ?>
					</button>
				</form>
			</div>
		</div>
		<?php
	}

	private static function inquiries_panel( array $inquiries ): void {

		$icon = static fn( string $name, int $size = 19 ): string =>
			function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size ) : '';
		?>
		<div class="panel" id="inquiries">
			<div class="panel-title">
				<h3><?php esc_html_e( 'Recent inquiries', 'thirtydayhomes' ); ?></h3>
			</div>

			<?php if ( ! $inquiries ) : ?>
				<div class="empty-state">
					<i><?php echo $icon( 'mail', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
					<h4><?php esc_html_e( 'No inquiries yet', 'thirtydayhomes' ); ?></h4>
					<p><?php esc_html_e( 'When a renter asks about one of your homes, it appears here and reaches you by email.', 'thirtydayhomes' ); ?></p>
				</div>
			<?php else : ?>
				<?php foreach ( $inquiries as $inquiry ) : ?>
					<?php
					/*
					 * The row is a link now. It was a dead <div>: the
					 * overview promised four recent inquiries and clicking
					 * one did nothing at all, which reads as a broken page
					 * rather than as a summary.
					 */
					?>
					<a class="portal-inquiry<?php echo $inquiry['unread'] ? ' is-unread' : ''; ?>" href="<?php echo esc_url( self::inbox_url( [ Inquiry::PARAM_OPEN => (string) $inquiry['id'] ] ) ); ?>">
						<i class="portal-avatar" aria-hidden="true"><?php echo esc_html( $inquiry['initials'] ); ?></i>
						<span>
							<b><?php echo esc_html( $inquiry['name'] ); ?></b>
							<small><?php echo esc_html( $inquiry['excerpt'] ); ?></small>
						</span>
						<?php if ( $inquiry['unread'] ) : ?>
							<em aria-label="<?php esc_attr_e( 'Unread', 'thirtydayhomes' ); ?>"></em>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * How many listings a landlord has in the given statuses.
	 *
	 * @param string[] $statuses
	 */
	private static function count_listings( int $user_id, array $statuses ): int {

		$query = new \WP_Query(
			[
				'post_type'             => Post_Types::LISTING,
				'author'                => $user_id,
				'post_status'           => $statuses,
				'posts_per_page'        => 1,
				'fields'                => 'ids',
				'tdh_bypass_visibility' => true,
			]
		);

		return (int) $query->found_posts;
	}

	/**
	 * The most recent inquiries about this landlord's listings.
	 *
	 * Routed by the listing's owner, not by anything the inquiry says: an
	 * inquiry belongs to whoever owns the home it asks about, which is the
	 * same rule the capability filter enforces when one is opened.
	 *
	 * @return array<int,array{name:string,excerpt:string,initials:string,unread:bool}>
	 */
	private static function inquiries_for( int $user_id, int $limit ): array {

		/*
		 * Deleted homes included. Deleting a listing must not also delete the
		 * conversations about it from the landlord's inbox — the confirmation
		 * panel promises those inquiries are kept. ('any' excludes trash.)
		 */
		$listing_ids = get_posts(
			[
				'post_type'             => Post_Types::LISTING,
				'author'                => $user_id,
				'post_status'           => array_merge( Listing_Manage_Render::statuses(), [ 'trash' ] ),
				'posts_per_page'        => -1,
				'fields'                => 'ids',
				'tdh_bypass_visibility' => true,
			]
		);

		if ( ! $listing_ids ) {
			return [];
		}

		$found = get_posts(
			[
				'post_type'      => Post_Types::INQUIRY,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => '_tdh_listing_id',
						'value'   => array_map( 'strval', $listing_ids ),
						'compare' => 'IN',
					],
				],
			]
		);

		$out = [];

		foreach ( $found as $inquiry ) {

			$name    = (string) get_post_meta( $inquiry->ID, '_tdh_renter_name', true );
			$message = (string) get_post_meta( $inquiry->ID, '_tdh_message', true );

			if ( '' === $message ) {
				$message = (string) $inquiry->post_content;
			}

			$out[] = [
				'id'       => (int) $inquiry->ID,
				'name'     => '' !== $name ? $name : __( 'A renter', 'thirtydayhomes' ),
				'excerpt'  => wp_html_excerpt( $message, 52, '…' ),
				'initials' => strtoupper( mb_substr( trim( $name ?: 'R' ), 0, 2 ) ),
				/*
				 * Through Inquiry, not `! get_post_meta()`. The stored value
				 * for false is an empty string and an old record has no row
				 * at all; one helper decides what unread means so this panel
				 * and the counted badge beside it cannot disagree.
				 */
				'unread'   => Inquiry::is_unread( (int) $inquiry->ID ),
			];
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Profile
	 * ------------------------------------------------------------------ */

	public static function profile(): string {

		if ( ! is_user_logged_in() ) {
			return self::sign_in_wall( __( 'Sign in to manage your account details.', 'thirtydayhomes' ) );
		}

		// Preserve old /profile/ bookmarks while using the consistent portal UI.
		$previous_view = $_GET['view'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['view']  = 'profile';
		$portal        = self::dashboard();
		if ( null === $previous_view ) {
			unset( $_GET['view'] );
		} else {
			$_GET['view'] = $previous_view;
		}
		return $portal;

		$user   = wp_get_current_user();
		$notice = Accounts::take_notice();

		ob_start();
		?>
		<div class="account">

			<header class="account-bar">
				<?php
				if ( function_exists( 'tdh_the_breadcrumb' ) ) {
					tdh_the_breadcrumb();
				}
				?>
				<div class="account-bar-inner">
					<div>
						<p class="overline"><?php esc_html_e( 'Landlord dashboard', 'thirtydayhomes' ); ?></p>
						<h1><?php esc_html_e( 'Account details', 'thirtydayhomes' ); ?></h1>
					</div>

					<nav class="account-nav" aria-label="<?php esc_attr_e( 'Account', 'thirtydayhomes' ); ?>">
						<a href="<?php echo esc_url( Accounts::url( 'account' ) ); ?>"><?php esc_html_e( 'Dashboard', 'thirtydayhomes' ); ?></a>
						<a class="is-current" href="<?php echo esc_url( Accounts::url( 'profile' ) ); ?>"><?php esc_html_e( 'Account details', 'thirtydayhomes' ); ?></a>
						<a href="<?php echo esc_url( Accounts::logout_url() ); ?>"><?php esc_html_e( 'Sign out', 'thirtydayhomes' ); ?></a>
					</nav>
				</div>
			</header>

			<div class="account-body">

				<?php self::notices( $notice ); ?>

				<div class="settings">

					<?php
					/*
					 * One form, two cards. The two are separate concerns —
					 * contact details and credentials — and grouping them
					 * makes that obvious, but they stay a single submit so
					 * the handler has one nonce and one validation pass.
					 */
					?>
					<form class="settings-main" method="post" action="">
						<?php self::form_head( 'profile' ); ?>

						<section class="settings-card">
							<header>
								<h2><?php esc_html_e( 'Your details', 'thirtydayhomes' ); ?></h2>
								<p><?php esc_html_e( 'How renters reach you about a home.', 'thirtydayhomes' ); ?></p>
							</header>

							<div class="form-field">
								<label for="tdh-p-name"><?php esc_html_e( 'Your name', 'thirtydayhomes' ); ?></label>
								<input id="tdh-p-name" name="tdh_name" type="text" autocomplete="name" required
									value="<?php echo esc_attr( $user->display_name ); ?>">
							</div>

							<div class="form-field">
								<label for="tdh-p-email"><?php esc_html_e( 'Email address', 'thirtydayhomes' ); ?></label>
								<input id="tdh-p-email" name="tdh_email" type="email" autocomplete="email" required
									value="<?php echo esc_attr( $user->user_email ); ?>">
							</div>

							<div class="form-grid">
								<div class="form-field">
									<label for="tdh-p-phone">
										<?php esc_html_e( 'Phone', 'thirtydayhomes' ); ?>
										<span class="label-note"><?php esc_html_e( '(optional)', 'thirtydayhomes' ); ?></span>
									</label>
									<input id="tdh-p-phone" name="tdh_phone" type="tel" autocomplete="tel"
										value="<?php echo esc_attr( (string) get_user_meta( $user->ID, '_tdh_phone', true ) ); ?>">
								</div>

								<div class="form-field">
									<label for="tdh-p-company">
										<?php esc_html_e( 'Company', 'thirtydayhomes' ); ?>
										<span class="label-note"><?php esc_html_e( '(optional)', 'thirtydayhomes' ); ?></span>
									</label>
									<input id="tdh-p-company" name="tdh_company" type="text" autocomplete="organization"
										value="<?php echo esc_attr( (string) get_user_meta( $user->ID, '_tdh_company', true ) ); ?>">
								</div>
							</div>
						</section>

						<section class="settings-card">
							<header>
								<h2><?php esc_html_e( 'Password', 'thirtydayhomes' ); ?></h2>
								<p><?php esc_html_e( 'Leave both blank unless you are changing it.', 'thirtydayhomes' ); ?></p>
							</header>

							<div class="form-field">
								<label for="tdh-p-current"><?php esc_html_e( 'Current password', 'thirtydayhomes' ); ?></label>
								<input id="tdh-p-current" name="tdh_password_current" type="password" autocomplete="current-password">
								<small class="form-hint--show"><?php esc_html_e( 'Needed to change your email address or password.', 'thirtydayhomes' ); ?></small>
							</div>

							<div class="form-field">
								<label for="tdh-p-new"><?php esc_html_e( 'New password', 'thirtydayhomes' ); ?></label>
								<input id="tdh-p-new" name="tdh_password_new" type="password" autocomplete="new-password"
									minlength="<?php echo esc_attr( (string) self::MIN_PASSWORD ); ?>">
								<small class="form-hint--show">
									<?php
									printf(
										/* translators: %d: minimum password length */
										esc_html__( 'At least %d characters.', 'thirtydayhomes' ),
										(int) self::MIN_PASSWORD
									);
									?>
								</small>
							</div>
						</section>

						<div class="settings-actions">
							<button class="primary big" type="submit"><?php esc_html_e( 'Save changes', 'thirtydayhomes' ); ?></button>
						</div>
					</form>

					<?php
					/*
					 * The summary. Read-only facts about the account, so
					 * the page has an anchor instead of a form floating in
					 * the middle of an empty screen — and so the landlord
					 * can see their plan without going back a page.
					 */
					$status  = Membership::status( (int) $user->ID );
					$labels  = Membership::labels();
					$joined  = strtotime( (string) $user->user_registered );
					$initial = mb_strtoupper( mb_substr( trim( (string) $user->display_name ), 0, 1 ) );
					?>
					<aside class="settings-side">
						<div class="settings-card settings-identity">
							<span class="avatar" aria-hidden="true"><?php echo esc_html( $initial ); ?></span>
							<b><?php echo esc_html( $user->display_name ); ?></b>
							<small><?php echo esc_html( $user->user_email ); ?></small>
						</div>

						<div class="settings-card">
							<dl class="settings-facts">
								<div>
									<dt><?php esc_html_e( 'Membership', 'thirtydayhomes' ); ?></dt>
									<dd class="metric-state metric-state--<?php echo esc_attr( Membership::badge_class( $status ) ); ?>">
										<?php echo esc_html( $labels[ $status ] ?? $status ); ?>
									</dd>
								</div>
								<div>
									<dt><?php esc_html_e( 'Homes listed', 'thirtydayhomes' ); ?></dt>
									<dd>
										<?php
										printf(
											'%s / %s',
											esc_html( number_format_i18n( Membership::listing_count( (int) $user->ID ) ) ),
											esc_html( number_format_i18n( Membership::quota( (int) $user->ID ) ) )
										);
										?>
									</dd>
								</div>
								<div>
									<dt><?php esc_html_e( 'Member since', 'thirtydayhomes' ); ?></dt>
									<dd><?php echo esc_html( $joined ? date_i18n( 'j M Y', $joined ) : '—' ); ?></dd>
								</div>
							</dl>

							<a class="secondary full" href="<?php echo esc_url( Accounts::url( 'account' ) ); ?>">
								<?php esc_html_e( 'Back to dashboard', 'thirtydayhomes' ); ?>
							</a>
						</div>
					</aside>

				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Shown on an account screen to someone who is not signed in.
	 */
	private static function sign_in_wall( string $message ): string {

		/*
		 * The page they were trying to reach, host and all. home_url() plus
		 * the request path doubled the site folder on a sub-directory
		 * install (/thirtydayhomes/thirtydayhomes/account/…), and the login
		 * page then sent people there after they signed in.
		 */
		$host = sanitize_text_field( wp_unslash( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) ) );
		$here = '' !== $host ? set_url_scheme( 'http://' . $host . add_query_arg( [] ) ) : Accounts::url( 'account' );

		ob_start();
		?>
		<div class="form-card form-card--narrow">
			<div class="form-intro">
				<h1><?php esc_html_e( 'Please sign in', 'thirtydayhomes' ); ?></h1>
				<p class="muted"><?php echo esc_html( $message ); ?></p>
			</div>
			<p class="form-actions-inline">
				<a class="primary" href="<?php echo esc_url( add_query_arg( 'redirect_to', rawurlencode( $here ), Accounts::url( 'login' ) ) ); ?>">
					<?php esc_html_e( 'Sign in', 'thirtydayhomes' ); ?>
				</a>
				<a class="secondary" href="<?php echo esc_url( Accounts::url( 'register' ) ); ?>">
					<?php esc_html_e( 'Create an account', 'thirtydayhomes' ); ?>
				</a>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
