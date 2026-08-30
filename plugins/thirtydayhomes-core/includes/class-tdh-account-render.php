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
					[ 'key-round', __( 'Enquiries come straight to you', 'thirtydayhomes' ), __( 'No commission on the booking. You deal with the renter directly.', 'thirtydayhomes' ) ],
				],
			],
			'login'    => [
				'eyebrow' => __( 'Welcome back', 'thirtydayhomes' ),
				'heading' => __( 'Your listings and enquiries, where you left them.', 'thirtydayhomes' ),
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
					<small><?php esc_html_e( 'You will sign in with this, and renter enquiries are sent here.', 'thirtydayhomes' ); ?></small>
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
								'<a href="' . esc_url( Accounts::url( 'terms' ) ) . '">' . esc_html__( 'Terms of Use', 'thirtydayhomes' ) . '</a>', // phpcs:ignore WordPress.Security.EscapeOutput
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
				<p class="muted"><?php esc_html_e( 'Manage your listings and enquiries.', 'thirtydayhomes' ); ?></p>
			</div>

			<?php self::notices( $notice ); ?>

			<form method="post" action="">
				<?php self::form_head( 'login' ); ?>
				<input type="hidden" name="tdh_redirect_to" value="<?php echo esc_attr( $redirect ); ?>">

				<div class="form-field">
					<label for="tdh-login-email"><?php esc_html_e( 'Email address', 'thirtydayhomes' ); ?></label>
					<input id="tdh-login-email" name="tdh_email" type="email" autocomplete="username" required
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

		$user     = wp_get_current_user();
		$user_id  = (int) $user->ID;
		$notice   = Accounts::take_notice();

		$status   = Membership::status( $user_id );
		$labels   = Membership::labels();
		$quota    = Membership::quota( $user_id );
		$used     = Membership::listing_count( $user_id );
		$expires  = Membership::expires( $user_id );

		$icon = static fn( string $name, int $size = 19 ): string =>
			function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size ) : '';

		ob_start();
		?>
		<div class="account">

			<?php
			/*
			 * A dark band, so the dashboard opens on the brand rather than
			 * on a bare white page, and so the greeting is not competing
			 * with the four cards below it for the eye.
			 */
			?>
			<header class="account-bar">
				<?php
				if ( function_exists( 'tdh_the_breadcrumb' ) ) {
					tdh_the_breadcrumb();
				}
				?>
				<div class="account-bar-inner">
					<div>
						<p class="overline"><?php esc_html_e( 'Landlord dashboard', 'thirtydayhomes' ); ?></p>
						<h1>
							<?php
							printf(
								/* translators: %s: display name */
								esc_html__( 'Welcome, %s', 'thirtydayhomes' ),
								esc_html( $user->display_name )
							);
							?>
						</h1>
					</div>

					<nav class="account-nav" aria-label="<?php esc_attr_e( 'Account', 'thirtydayhomes' ); ?>">
						<a class="is-current" href="<?php echo esc_url( Accounts::url( 'account' ) ); ?>"><?php esc_html_e( 'Dashboard', 'thirtydayhomes' ); ?></a>
						<a href="<?php echo esc_url( Accounts::url( 'profile' ) ); ?>"><?php esc_html_e( 'Account details', 'thirtydayhomes' ); ?></a>
						<a href="<?php echo esc_url( Accounts::logout_url() ); ?>"><?php esc_html_e( 'Sign out', 'thirtydayhomes' ); ?></a>
					</nav>
				</div>
			</header>

			<div class="account-body">

				<?php self::notices( $notice ); ?>

				<?php
				/*
				 * One membership panel, not a banner AND a card. The two
				 * said the same thing directly above one another — "You do
				 * not have a membership yet" over "No active plan" — which
				 * read as a layout accident rather than as emphasis.
				 *
				 * This is the only thing on the screen a landlord without a
				 * plan can act on, so it is the anchor and everything else
				 * is secondary to it.
				 */
				$copy = [
					Membership::NONE      => __( 'Choose a plan to publish your first home. Nothing is charged until you do.', 'thirtydayhomes' ),
					Membership::ACTIVE    => __( 'Your listings are visible to renters searching Pittsburgh.', 'thirtydayhomes' ),
					Membership::PAST_DUE  => __( 'Your listings are hidden until payment succeeds. They come back automatically — nothing is deleted.', 'thirtydayhomes' ),
					Membership::CANCELLED => __( 'Your membership runs to the end of the paid period, then your listings come down.', 'thirtydayhomes' ),
					Membership::EXPIRED   => __( 'Your membership has ended and your listings are hidden. Restart a plan to bring them back.', 'thirtydayhomes' ),
				];
				?>
				<section class="member-panel member-panel--<?php echo esc_attr( Membership::badge_class( $status ) ); ?>">
					<div class="member-panel-main">
						<p class="overline"><?php esc_html_e( 'Membership', 'thirtydayhomes' ); ?></p>
						<h2><?php echo esc_html( $labels[ $status ] ?? $status ); ?></h2>
						<p><?php echo esc_html( $copy[ $status ] ?? '' ); ?></p>
					</div>

					<?php if ( Membership::ACTIVE !== $status ) : ?>
						<a class="gold-btn" href="<?php echo esc_url( Accounts::url( 'pricing' ) ); ?>">
							<?php echo Membership::NONE === $status ? esc_html__( 'See plans', 'thirtydayhomes' ) : esc_html__( 'Manage billing', 'thirtydayhomes' ); ?>
							<?php echo $icon( 'arrow-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</a>
					<?php endif; ?>
				</section>

				<div class="stat-grid">

					<div class="stat">
						<i><?php echo $icon( 'key-round' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
						<div>
							<p class="stat-label"><?php esc_html_e( 'Plan', 'thirtydayhomes' ); ?></p>
							<p class="stat-value"><?php echo esc_html( Membership::plan( $user_id ) ?: __( 'None', 'thirtydayhomes' ) ); ?></p>
						</div>
					</div>

					<div class="stat">
						<i><?php echo $icon( 'map-pinned' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
						<div>
							<p class="stat-label"><?php esc_html_e( 'Listings', 'thirtydayhomes' ); ?></p>
							<p class="stat-value">
								<?php echo esc_html( number_format_i18n( $used ) ); ?><em><?php echo esc_html( '/' . number_format_i18n( $quota ) ); ?></em>
							</p>
						</div>
					</div>

					<div class="stat">
						<i><?php echo $icon( 'calendar-days' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
						<div>
							<p class="stat-label"><?php esc_html_e( 'Renews', 'thirtydayhomes' ); ?></p>
							<p class="stat-value"><?php echo esc_html( $expires ? date_i18n( 'j M Y', $expires ) : __( 'Not yet', 'thirtydayhomes' ) ); ?></p>
						</div>
					</div>

				</div>

				<div class="panel">
					<div class="panel-title">
						<h3><?php esc_html_e( 'Your listings', 'thirtydayhomes' ); ?></h3>
						<?php if ( $quota > 0 ) : ?>
							<span class="panel-note">
								<?php
								printf(
									/* translators: 1: used, 2: allowed */
									esc_html__( '%1$s of %2$s used', 'thirtydayhomes' ),
									esc_html( number_format_i18n( $used ) ),
									esc_html( number_format_i18n( $quota ) )
								);
								?>
							</span>
						<?php endif; ?>
					</div>
					<?php self::listing_rows( $user_id ); ?>
				</div>

			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The landlord's own listings, in every status.
	 *
	 * Uses the bypass because an owner must see their own pending, rejected
	 * and billing-held listings — the public visibility rule would hide
	 * exactly the ones they most need to act on.
	 */
	private static function listing_rows( int $user_id ): void {

		$query = new \WP_Query(
			[
				'post_type'             => Post_Types::LISTING,
				'author'                => $user_id,
				'post_status'           => array_merge( [ 'publish', 'pending', 'draft' ], array_keys( Statuses::all() ) ),
				'posts_per_page'        => 20,
				'no_found_rows'         => true,
				'tdh_bypass_visibility' => true,
			]
		);

		if ( ! $query->have_posts() ) {

			// The empty state says what to do next, and what that depends
			// on. "You have not created a listing yet" in the middle of a
			// large blank box states a fact and offers no way forward.
			$has_plan = Membership::quota( $user_id ) > 0;
			?>
			<div class="empty-state">
				<i><?php echo function_exists( 'tdh_icon' ) ? tdh_icon( 'map-pinned', 22 ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
				<h4><?php esc_html_e( 'No homes listed yet', 'thirtydayhomes' ); ?></h4>

				<?php if ( $has_plan ) : ?>
					<p><?php esc_html_e( 'Add your first home and it goes to review before publishing.', 'thirtydayhomes' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'A membership comes first. Once a plan is active you can publish your home here.', 'thirtydayhomes' ); ?></p>
					<a class="secondary" href="<?php echo esc_url( Accounts::url( 'pricing' ) ); ?>">
						<?php esc_html_e( 'See plans', 'thirtydayhomes' ); ?>
					</a>
				<?php endif; ?>
			</div>
			<?php
			return;
		}

		$labels = [
			'publish'               => __( 'Live', 'thirtydayhomes' ),
			'pending'               => __( 'In review', 'thirtydayhomes' ),
			'draft'                 => __( 'Draft', 'thirtydayhomes' ),
			Statuses::PAUSED        => __( 'Paused', 'thirtydayhomes' ),
			Statuses::REJECTED      => __( 'Changes requested', 'thirtydayhomes' ),
			Statuses::BILLING_HOLD  => __( 'Hidden — payment', 'thirtydayhomes' ),
		];

		$badges = [
			'publish'              => 'live',
			'pending'              => 'pending',
			Statuses::REJECTED     => 'rejected',
			Statuses::BILLING_HOLD => 'past_due',
		];

		while ( $query->have_posts() ) {
			$query->the_post();
			$state = (string) get_post_status();
			?>
			<div class="mini-listing">
				<span>
					<b><?php the_title(); ?></b>
					<small><?php echo esc_html( get_the_date() ); ?></small>
				</span>
				<span class="status <?php echo esc_attr( $badges[ $state ] ?? '' ); ?>">
					<?php echo esc_html( $labels[ $state ] ?? $state ); ?>
				</span>
			</div>
			<?php
		}

		wp_reset_postdata();
	}

	/* ---------------------------------------------------------------------
	 * Profile
	 * ------------------------------------------------------------------ */

	public static function profile(): string {

		if ( ! is_user_logged_in() ) {
			return self::sign_in_wall( __( 'Sign in to manage your account details.', 'thirtydayhomes' ) );
		}

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

		$here = home_url( add_query_arg( [] ) );

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
