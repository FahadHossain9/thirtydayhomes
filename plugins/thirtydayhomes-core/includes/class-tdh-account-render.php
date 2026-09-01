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

		/*
		 * Someone returning from the listing wizard. A boolean flag, not a
		 * message from the URL — the copy lives here, so the query string
		 * cannot be edited into saying something else.
		 */
		if ( isset( $_GET['tdh_submitted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$notice['success'] = __( 'Your listing is submitted. A person reviews it — usually within one business day — and it goes live from there.', 'thirtydayhomes' );
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

		if ( ! in_array( $view, [ 'overview', 'listings', 'inquiries', 'membership' ], true ) ) {
			$view = 'overview';
		}

		$live     = self::count_listings( $user_id, [ 'publish' ] );
		$pending  = self::count_listings( $user_id, [ 'pending' ] );
		$views    = Views::total_for_author( $user_id );

		// The dedicated screen shows the history; the overview shows a taste.
		$inquiries = self::inquiries_for( $user_id, 'inquiries' === $view ? 50 : 4 );
		$unread    = count( array_filter( $inquiries, static fn( $i ) => $i['unread'] ) );

		$initials = strtoupper( mb_substr( trim( $user->display_name ), 0, 2 ) );

		$copy = [
			Membership::NONE      => __( 'Choose a plan to publish your first home. Nothing is charged until you do.', 'thirtydayhomes' ),
			Membership::ACTIVE    => __( 'Your listings are visible to renters searching Pittsburgh.', 'thirtydayhomes' ),
			Membership::PAST_DUE  => __( 'Your listings are hidden until payment succeeds. They come back automatically — nothing is deleted.', 'thirtydayhomes' ),
			Membership::CANCELLED => __( 'Your membership runs to the end of the paid period, then your listings come down.', 'thirtydayhomes' ),
			Membership::EXPIRED   => __( 'Your membership has ended and your listings are hidden. Restart a plan to bring them back.', 'thirtydayhomes' ),
		];

		$add_url = '' === Listing_Form::gate_reason()
			? Listing_Form::url()
			: Accounts::url( 'pricing' );

		ob_start();
		?>
		<div class="portal">

			<aside class="portal-side">
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
				];
				?>
				<nav class="portal-nav" aria-label="<?php esc_attr_e( 'Dashboard', 'thirtydayhomes' ); ?>">
					<?php foreach ( $nav as $slug => [ $nav_icon, $label ] ) : ?>
						<a class="<?php echo $view === $slug ? 'is-current' : ''; ?>"
							href="<?php echo esc_url( 'overview' === $slug ? Accounts::url( 'account' ) : add_query_arg( 'view', $slug, Accounts::url( 'account' ) ) ); ?>">
							<?php echo $icon( $nav_icon, 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php echo esc_html( $label ); ?>
							<?php if ( 'inquiries' === $slug && $unread > 0 ) : ?>
								<em class="portal-count"><?php echo esc_html( number_format_i18n( $unread ) ); ?></em>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
					<a href="<?php echo esc_url( Accounts::url( 'profile' ) ); ?>">
						<?php echo $icon( 'user', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php esc_html_e( 'Profile', 'thirtydayhomes' ); ?>
					</a>
				</nav>

				<a class="portal-return" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<?php echo $icon( 'arrow-left', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php esc_html_e( 'Public website', 'thirtydayhomes' ); ?>
				</a>
			</aside>

			<div class="portal-main" id="overview">

				<div class="portal-top">
					<span>
						<?php
						printf(
							/* translators: %s: display name */
							esc_html__( 'Welcome back, %s', 'thirtydayhomes' ),
							esc_html( $user->display_name )
						);
						?>
					</span>
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

					<?php if ( in_array( $view, [ 'overview', 'membership' ], true ) ) : ?>
					<?php
					/*
					 * The membership band. One panel, not a banner AND a
					 * card — the two said the same thing above one another
					 * and read as a layout accident.
					 */
					?>
					<section class="portal-alert portal-alert--<?php echo esc_attr( Membership::badge_class( $status ) ); ?>" id="membership">
						<?php echo $icon( 'wallet-cards', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<span>
							<b><?php echo esc_html( $labels[ $status ] ?? $status ); ?></b>
							<small><?php echo esc_html( $copy[ $status ] ?? '' ); ?></small>
						</span>

						<?php if ( Membership::ACTIVE === $status ) : ?>
							<span class="portal-renews">
								<?php
								printf(
									/* translators: 1: plan name, 2: renewal date */
									esc_html__( '%1$s · renews %2$s', 'thirtydayhomes' ),
									esc_html( Membership::plan( $user_id ) ),
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
						<?php self::listings_panel( $user_id, $used, $quota ); ?>
						<?php self::inquiries_panel( $inquiries ); ?>
					</div>

					<?php elseif ( 'listings' === $view ) : ?>
						<?php self::listings_panel( $user_id, $used, $quota ); ?>

					<?php elseif ( 'inquiries' === $view ) : ?>
						<?php self::inquiries_panel( $inquiries ); ?>

					<?php elseif ( 'membership' === $view ) : ?>
						<div class="panel">
							<div class="panel-title">
								<h3><?php esc_html_e( 'Plan details', 'thirtydayhomes' ); ?></h3>
							</div>
							<div class="portal-health">
								<div>
									<small><?php esc_html_e( 'Plan', 'thirtydayhomes' ); ?></small>
									<b><?php echo esc_html( Membership::plan( $user_id ) ?: '—' ); ?></b>
								</div>
								<div>
									<small><?php esc_html_e( 'Listings used', 'thirtydayhomes' ); ?></small>
									<b>
										<?php
										echo esc_html(
											$quota > 0
												? number_format_i18n( $used ) . ' / ' . number_format_i18n( $quota )
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
						</div>
					<?php endif; ?>

				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
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
			$notice['info'] = __( 'Sent back to the landlord for changes. It returns to this queue when they resubmit.', 'thirtydayhomes' );
		} elseif ( 'expired' === $moderated ) {
			$notice['error'] = __( 'That action expired before it was saved. Please try again.', 'thirtydayhomes' );
		} elseif ( 'missing' === $moderated ) {
			$notice['error'] = __( 'That listing no longer exists.', 'thirtydayhomes' );
		}

		// Which screen of the portal is open. Anything unrecognised is the
		// overview, not an error page.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $view, [ 'overview', 'listings', 'members', 'inquiries', 'facilities' ], true ) ) {
			$view = 'overview';
		}

		$icon = static fn( string $name, int $size = 19 ): string =>
			function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size ) : '';

		// The sidebar badge: how many homes wait for a decision, on every view.
		$pending_total = self::count_site_listings( [ 'pending' ] );

		ob_start();
		?>
		<div class="portal">

			<aside class="portal-side">
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
					[ 'members', 'users', __( 'Members', 'thirtydayhomes' ) ],
					[ 'facilities', 'stethoscope', __( 'Facilities', 'thirtydayhomes' ) ],
					[ 'inquiries', 'mail', __( 'Inquiries', 'thirtydayhomes' ) ],
				];
				?>
				<nav class="portal-nav" aria-label="<?php esc_attr_e( 'Administration', 'thirtydayhomes' ); ?>">
					<?php foreach ( $nav as [ $slug, $nav_icon, $label ] ) : ?>
						<a class="<?php echo $view === $slug ? 'is-current' : ''; ?>" href="<?php echo esc_url( self::mk_url( $slug ) ); ?>">
							<?php echo $icon( $nav_icon, 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php echo esc_html( $label ); ?>
							<?php if ( 'listings' === $slug && $pending_total > 0 ) : ?>
								<em class="portal-count"><?php echo esc_html( number_format_i18n( $pending_total ) ); ?></em>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=page' ) ); ?>">
						<?php echo $icon( 'file-text', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php esc_html_e( 'Site content', 'thirtydayhomes' ); ?>
					</a>
				</nav>

				<?php // The developer's separate door: full WordPress, everything. ?>
				<a class="portal-return portal-wp" href="<?php echo esc_url( admin_url() ); ?>">
					<?php echo $icon( 'wrench', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php esc_html_e( 'WordPress dashboard', 'thirtydayhomes' ); ?>
				</a>

				<a class="portal-return" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<?php echo $icon( 'arrow-left', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php esc_html_e( 'Public website', 'thirtydayhomes' ); ?>
				</a>
			</aside>

			<div class="portal-main" id="overview">

				<div class="portal-top">
					<span><?php esc_html_e( 'Marketplace administration', 'thirtydayhomes' ); ?></span>
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
	 * A portal view's address. The overview is the bare account page.
	 */
	private static function mk_url( string $view ): string {

		$base = Accounts::url( 'account' );

		return 'overview' === $view ? $base : add_query_arg( 'view', $view, $base );
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
	 * "$2,400/mo · Shadyside · Jane Landlord" — whichever parts exist.
	 */
	private static function mk_listing_line( int $post_id ): string {

		$rent = (string) get_post_meta( $post_id, '_tdh_price_monthly', true );
		$hood = get_the_terms( $post_id, Post_Types::TAX_NEIGHBORHOOD );
		$hood = $hood && ! is_wp_error( $hood ) ? $hood[0]->name : '';

		$author = (int) get_post_field( 'post_author', $post_id );
		$owner  = $author ? get_userdata( $author ) : null;

		return implode(
			' · ',
			array_filter(
				[
					/* translators: %s: monthly rent */
					'' !== $rent ? sprintf( __( '$%s/mo', 'thirtydayhomes' ), number_format_i18n( (float) $rent ) ) : '',
					$hood,
					$owner ? $owner->display_name : '',
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

		$all = new \WP_Query(
			[
				'post_type'             => Post_Types::LISTING,
				'post_status'           => array_merge( [ 'publish', 'pending', 'draft' ], array_keys( Statuses::all() ) ),
				'posts_per_page'        => 20,
				'tdh_bypass_visibility' => true,
			]
		);

		$labels = Statuses::all();
		$badges = [
			'publish'              => 'live',
			'pending'              => 'pending',
			Statuses::REJECTED     => 'rejected',
			Statuses::BILLING_HOLD => 'past_due',
		];
		?>
		<div class="portal-heading">
			<span>
				<h1><?php esc_html_e( 'Listings', 'thirtydayhomes' ); ?></h1>
				<p><?php esc_html_e( 'Approve submissions here; open a listing in WordPress for edits.', 'thirtydayhomes' ); ?></p>
			</span>
			<a class="primary" href="<?php echo esc_url( Listing_Form::url() ); ?>">
				<?php echo self::mk_icon( 'plus', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php esc_html_e( 'Add listing', 'thirtydayhomes' ); ?>
			</a>
		</div>

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
					?>
					<div class="portal-approval">
						<?php self::mk_row_media( get_the_ID() ); ?>
						<span>
							<?php // Preview: see the home as a renter would, before deciding. ?>
							<b><a href="<?php echo esc_url( (string) get_preview_post_link( get_the_ID() ) ); ?>"><?php the_title(); ?></a></b>
							<small><?php echo esc_html( self::mk_listing_line( get_the_ID() ) ); ?></small>
						</span>
						<span class="portal-row-actions">
							<form method="post" action="<?php echo esc_url( self::mk_url( 'listings' ) ); ?>">
								<input type="hidden" name="tdh_action" value="listing_approve">
								<input type="hidden" name="tdh_listing" value="<?php echo esc_attr( (string) get_the_ID() ); ?>">
								<?php wp_nonce_field( Moderation::NONCE, 'tdh_nonce' ); ?>
								<button class="primary" type="submit"><?php esc_html_e( 'Approve', 'thirtydayhomes' ); ?></button>
							</form>
							<form method="post" action="<?php echo esc_url( self::mk_url( 'listings' ) ); ?>">
								<input type="hidden" name="tdh_action" value="listing_changes">
								<input type="hidden" name="tdh_listing" value="<?php echo esc_attr( (string) get_the_ID() ); ?>">
								<?php wp_nonce_field( Moderation::NONCE, 'tdh_nonce' ); ?>
								<button class="secondary" type="submit"><?php esc_html_e( 'Request changes', 'thirtydayhomes' ); ?></button>
							</form>
						</span>
					</div>
				<?php endwhile; ?>
				<?php wp_reset_postdata(); ?>
			<?php endif; ?>
		</div>

		<div class="panel portal-panel-block">
			<div class="panel-title">
				<h3><?php esc_html_e( 'All listings', 'thirtydayhomes' ); ?></h3>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Post_Types::LISTING ) ); ?>">
					<?php esc_html_e( 'Open in WordPress', 'thirtydayhomes' ); ?>
					<?php echo self::mk_icon( 'arrow-right', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</a>
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
					$state = (string) get_post_status();
					$open  = 'publish' === $state
						? get_permalink()
						: admin_url( 'post.php?action=edit&post=' . get_the_ID() );
					?>
					<div class="portal-approval">
						<?php self::mk_row_media( get_the_ID() ); ?>
						<span>
							<b><a href="<?php echo esc_url( (string) $open ); ?>"><?php the_title(); ?></a></b>
							<small><?php echo esc_html( self::mk_listing_line( get_the_ID() ) ); ?></small>
						</span>
						<span class="status <?php echo esc_attr( $badges[ $state ] ?? '' ); ?>">
							<?php echo esc_html( $labels[ $state ] ?? $state ); ?>
						</span>
					</div>
				<?php endwhile; ?>
				<?php wp_reset_postdata(); ?>
			<?php endif; ?>
		</div>
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
				<p><?php esc_html_e( 'Every landlord account, with its plan and listings.', 'thirtydayhomes' ); ?></p>
			</span>
			<a class="secondary" href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>">
				<?php esc_html_e( 'Open in WordPress', 'thirtydayhomes' ); ?>
			</a>
		</div>

		<div class="panel portal-panel-block">
			<?php if ( ! $members ) : ?>
				<div class="empty-state">
					<i><?php echo self::mk_icon( 'users', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
					<h4><?php esc_html_e( 'No members yet', 'thirtydayhomes' ); ?></h4>
					<p><?php esc_html_e( 'Landlord accounts appear here as people register.', 'thirtydayhomes' ); ?></p>
				</div>
			<?php else : ?>
				<?php
				foreach ( $members as $member ) :
					$m_id     = (int) $member->ID;
					$m_status = Membership::status( $m_id );
					$m_plan   = Membership::plan( $m_id );
					$line     = implode(
						' · ',
						array_filter(
							[
								$member->user_email,
								'' !== $m_plan ? $m_plan : '',
								sprintf(
									/* translators: 1: listings held, 2: allowance */
									__( '%1$s of %2$s listings', 'thirtydayhomes' ),
									number_format_i18n( Membership::listing_count( $m_id ) ),
									number_format_i18n( Membership::quota( $m_id ) )
								),
							]
						)
					);
					?>
					<div class="portal-approval">
						<i class="portal-avatar" aria-hidden="true"><?php echo esc_html( strtoupper( mb_substr( trim( $member->display_name ), 0, 2 ) ) ); ?></i>
						<span>
							<?php // The user editor link only for staff who can open it — the review persona cannot, and a link to "you need permission" is worse than a name. ?>
							<?php if ( current_user_can( 'edit_users' ) ) : ?>
								<b><a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . $m_id ) ); ?>"><?php echo esc_html( $member->display_name ); ?></a></b>
							<?php else : ?>
								<b><?php echo esc_html( $member->display_name ); ?></b>
							<?php endif; ?>
							<small><?php echo esc_html( $line ); ?></small>
						</span>
						<span class="status <?php echo esc_attr( $status_badges[ $m_status ] ?? '' ); ?>">
							<?php echo esc_html( $labels[ $m_status ] ?? $m_status ); ?>
						</span>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/* --- Inquiries --------------------------------------------------------- */

	private static function mk_inquiries(): void {

		$inquiries = get_posts(
			[
				'post_type'      => Post_Types::INQUIRY,
				'post_status'    => 'any',
				'posts_per_page' => 20,
			]
		);
		?>
		<div class="portal-heading">
			<span>
				<h1><?php esc_html_e( 'Inquiries', 'thirtydayhomes' ); ?></h1>
				<p><?php esc_html_e( 'Everything renters have sent, newest first. Open one to read and reply.', 'thirtydayhomes' ); ?></p>
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
					?>
					<div class="portal-inquiry<?php echo ! get_post_meta( $inquiry->ID, '_tdh_read', true ) ? ' is-unread' : ''; ?>">
						<i class="portal-avatar" aria-hidden="true"><?php echo esc_html( strtoupper( mb_substr( trim( $name ), 0, 2 ) ) ); ?></i>
						<span>
							<b><a href="<?php echo esc_url( admin_url( 'post.php?action=edit&post=' . $inquiry->ID ) ); ?>"><?php echo esc_html( $name ); ?></a></b>
							<small><?php echo esc_html( $line ); ?></small>
						</span>
						<?php if ( ! get_post_meta( $inquiry->ID, '_tdh_read', true ) ) : ?>
							<em aria-label="<?php esc_attr_e( 'Unread', 'thirtydayhomes' ); ?>"></em>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
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
			<a class="secondary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Post_Types::FACILITY ) ); ?>">
				<?php esc_html_e( 'Open in WordPress', 'thirtydayhomes' ); ?>
			</a>
		</div>

		<div class="panel portal-panel-block">
			<?php if ( ! $facilities ) : ?>
				<div class="empty-state">
					<i><?php echo self::mk_icon( 'stethoscope', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
					<h4><?php esc_html_e( 'No facilities yet', 'thirtydayhomes' ); ?></h4>
					<p><?php esc_html_e( 'Add hospitals and campuses in WordPress and they appear here.', 'thirtydayhomes' ); ?></p>
				</div>
			<?php else : ?>
				<?php foreach ( $facilities as $facility ) : ?>
					<div class="portal-approval">
						<i aria-hidden="true"><?php echo self::mk_icon( 'stethoscope', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
						<span>
							<b><a href="<?php echo esc_url( admin_url( 'post.php?action=edit&post=' . $facility->ID ) ); ?>"><?php echo esc_html( get_the_title( $facility ) ); ?></a></b>
						</span>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
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
	private static function listings_panel( int $user_id, int $used, int $quota ): void {
		?>
		<div class="panel" id="listings">
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
		<?php
	}

	/**
	 * The inquiries panel — the overview hands it four, the dedicated
	 * screen hands it the history.
	 *
	 * @param array<int,array{name:string,excerpt:string,initials:string,unread:bool}> $inquiries
	 */
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
					<div class="portal-inquiry<?php echo $inquiry['unread'] ? ' is-unread' : ''; ?>">
						<i class="portal-avatar" aria-hidden="true"><?php echo esc_html( $inquiry['initials'] ); ?></i>
						<span>
							<b><?php echo esc_html( $inquiry['name'] ); ?></b>
							<small><?php echo esc_html( $inquiry['excerpt'] ); ?></small>
						</span>
						<?php if ( $inquiry['unread'] ) : ?>
							<em aria-label="<?php esc_attr_e( 'Unread', 'thirtydayhomes' ); ?>"></em>
						<?php endif; ?>
					</div>
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

		$listing_ids = get_posts(
			[
				'post_type'             => Post_Types::LISTING,
				'author'                => $user_id,
				'post_status'           => 'any',
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
				'name'     => '' !== $name ? $name : __( 'A renter', 'thirtydayhomes' ),
				'excerpt'  => wp_html_excerpt( $message, 52, '…' ),
				'initials' => strtoupper( mb_substr( trim( $name ?: 'R' ), 0, 2 ) ),
				'unread'   => ! get_post_meta( $inquiry->ID, '_tdh_read', true ),
			];
		}

		return $out;
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
			// The wizard's gate decides, so staff see the add path too.
			$can_add = '' === Listing_Form::gate_reason();
			?>
			<div class="empty-state">
				<i><?php echo function_exists( 'tdh_icon' ) ? tdh_icon( 'map-pinned', 22 ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
				<h4><?php esc_html_e( 'No homes listed yet', 'thirtydayhomes' ); ?></h4>

				<?php if ( $can_add ) : ?>
					<p><?php esc_html_e( 'Add your first home and it goes to review before publishing.', 'thirtydayhomes' ); ?></p>
					<a class="secondary" href="<?php echo esc_url( Listing_Form::url() ); ?>">
						<?php esc_html_e( 'Add your home', 'thirtydayhomes' ); ?>
					</a>
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
				<?php if ( in_array( $state, [ 'draft', 'pending' ], true ) ) : ?>
					<?php // The wizard edits exactly what it created: drafts and pending. ?>
					<a class="mini-listing-edit" href="<?php echo esc_url( Listing_Form::url( 1, get_the_ID() ) ); ?>">
						<?php esc_html_e( 'Edit', 'thirtydayhomes' ); ?>
					</a>
				<?php endif; ?>
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
