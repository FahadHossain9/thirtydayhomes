<?php
/**
 * Stripe settings screen.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Billing;

defined( 'ABSPATH' ) || exit;

/**
 * Listings → Payments.
 *
 * Two forms, deliberately. The mode switch is its own form at the top,
 * because choosing which Stripe the site talks to is a different decision
 * from editing a key, and burying it in the same Save button means changing
 * one plan's Price also flips the site live.
 *
 * The credential form edits ONE mode, chosen by a tab. Sandbox and
 * production keys are never on screen together and never submitted together,
 * so there is no form state in which the wrong set can be written.
 */
final class Settings {

	private const PAGE  = 'tdh-payments';
	private const NOTICE = 'tdh_stripe_notice_';

	private string $hook = '';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
	}

	public function add_page(): void {

		$hook = add_submenu_page(
			'edit.php?post_type=tdh_listing',
			__( 'Payments', 'thirtydayhomes' ),
			__( 'Payments', 'thirtydayhomes' ),
			// Not edit_tdh_listings: a moderator who approves homes has no
			// business holding the key that moves money.
			'manage_options',
			self::PAGE,
			[ $this, 'render' ]
		);

		if ( is_string( $hook ) && '' !== $hook ) {
			$this->hook = $hook;

			// Runs before any output, so a save can redirect.
			add_action( 'load-' . $hook, [ $this, 'handle_post' ] );

			// Scoped to this screen only — nothing here belongs on any other
			// admin page.
			add_action( 'admin_head-' . $hook, [ $this, 'print_styles' ] );
			add_action( 'admin_footer-' . $hook, [ $this, 'print_script' ] );
		}
	}

	public static function url( string $tab = '' ): string {

		$args = [
			'post_type' => 'tdh_listing',
			'page'      => self::PAGE,
		];

		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}

		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/** Which credential set is being edited. */
	private function tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';

		return Stripe::MODE_LIVE === $tab ? Stripe::MODE_LIVE : Stripe::MODE_TEST;
	}

	/* ---------------------------------------------------------------------
	 * Saving
	 * ------------------------------------------------------------------ */

	public function handle_post(): void {

		if ( empty( $_POST['tdh_stripe_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change payment settings.', 'thirtydayhomes' ) );
		}

		$action = sanitize_key( wp_unslash( (string) $_POST['tdh_stripe_action'] ) );

		check_admin_referer( 'tdh_stripe_' . $action );

		if ( 'mode' === $action ) {
			$this->save_mode();
			return;
		}

		if ( 'credentials' === $action ) {
			$this->save_credentials();
			return;
		}

		// "test_connection", not "test": tdh_tab already uses "test" to mean
		// sandbox mode, and one form carrying value="test" for two unrelated
		// meanings is a trap for whoever reads the request next.
		if ( 'test_connection' === $action ) {
			$this->test_connection();
		}
	}

	private function save_mode(): void {

		$mode = isset( $_POST['tdh_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['tdh_mode'] ) ) : Stripe::MODE_TEST;

		Stripe::set_mode( $mode );

		if ( Stripe::is_live() && ! Stripe::is_configured( Stripe::MODE_LIVE ) ) {
			// Switching to live with nothing to switch to is worth saying out
			// loud rather than leaving them to discover it at checkout.
			$this->notice(
				'warning',
				__( 'Live mode is on, but the live credentials are incomplete. Payments will fail until they are filled in.', 'thirtydayhomes' )
			);
		} else {
			$this->notice(
				'success',
				Stripe::is_live()
					? __( 'Live mode is on. Real cards will be charged.', 'thirtydayhomes' )
					: __( 'Test mode is on. No real money will move.', 'thirtydayhomes' )
			);
		}

		$this->redirect();
	}

	private function save_credentials(): void {

		$mode   = isset( $_POST['tdh_tab'] ) ? sanitize_key( wp_unslash( (string) $_POST['tdh_tab'] ) ) : Stripe::MODE_TEST;
		$mode   = Stripe::MODE_LIVE === $mode ? Stripe::MODE_LIVE : Stripe::MODE_TEST;
		$errors = [];
		$saved  = 0;

		foreach ( $this->fields( $mode ) as $field => $spec ) {

			if ( Stripe::is_locked( $mode, $field ) ) {
				continue;
			}

			$raw = isset( $_POST[ 'tdh_' . $field ] )
				? trim( sanitize_text_field( wp_unslash( (string) $_POST[ 'tdh_' . $field ] ) ) )
				: '';

			// A blank secret means "keep what is stored", not "erase it".
			// The field renders empty by design — writing the blank back is
			// how a working key gets wiped by someone saving a Price ID.
			if ( '' === $raw && $spec['secret'] ) {
				continue;
			}

			if ( '' !== $raw && ! $this->prefix_ok( $raw, $field, $mode ) ) {
				$errors[] = sprintf(
					/* translators: 1: field label, 2: expected prefix list */
					__( '%1$s does not look like a %2$s value. Nothing was saved for it.', 'thirtydayhomes' ),
					$spec['label'],
					implode( __( ' or ', 'thirtydayhomes' ), Stripe::expected_prefixes( $field, $mode ) )
				);
				continue;
			}

			Stripe::save_credential( $mode, $field, $raw );
			++$saved;
		}

		if ( $errors ) {
			$this->notice( 'error', implode( ' ', $errors ) );
		}

		if ( $saved > 0 && ! $errors ) {
			$this->notice( 'success', __( 'Payment settings saved.', 'thirtydayhomes' ) );
		}

		$this->redirect( $mode );
	}

	/**
	 * Ask Stripe whether the secret key works — and which mode it belongs to.
	 *
	 * The prefix check on save catches a pasted live key. This catches the
	 * rest: a revoked key, a typo, a key from a different Stripe account, and
	 * a key whose real mode disagrees with the tab it was entered on, which
	 * Stripe reports as `livemode` on any response.
	 */
	private function test_connection(): void {

		$mode = isset( $_POST['tdh_tab'] ) ? sanitize_key( wp_unslash( (string) $_POST['tdh_tab'] ) ) : Stripe::MODE_TEST;
		$mode = Stripe::MODE_LIVE === $mode ? Stripe::MODE_LIVE : Stripe::MODE_TEST;

		$secret = Stripe::secret_key( $mode );

		if ( '' === $secret ) {
			$this->notice( 'error', __( 'No secret key is saved for this mode yet.', 'thirtydayhomes' ) );
			$this->redirect( $mode );
		}

		$response = wp_remote_get(
			'https://api.stripe.com/v1/balance',
			[
				'timeout' => 15,
				'headers' => [
					'Authorization'  => 'Bearer ' . $secret,
					'Stripe-Version' => '2024-06-20',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->notice(
				'error',
				sprintf(
					/* translators: %s: network error message */
					__( 'Could not reach Stripe: %s', 'thirtydayhomes' ),
					$response->get_error_message()
				)
			);
			$this->redirect( $mode );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			// Stripe's own message names the problem precisely — expired key,
			// wrong account, insufficient permissions on a restricted key.
			$this->notice(
				'error',
				sprintf(
					/* translators: 1: HTTP status, 2: message from Stripe */
					__( 'Stripe rejected the key (HTTP %1$d): %2$s', 'thirtydayhomes' ),
					$code,
					isset( $body['error']['message'] ) ? (string) $body['error']['message'] : __( 'no reason given', 'thirtydayhomes' )
				)
			);
			$this->redirect( $mode );
		}

		$key_is_live  = ! empty( $body['livemode'] );
		$tab_is_live  = Stripe::MODE_LIVE === $mode;

		if ( $key_is_live !== $tab_is_live ) {
			$this->notice(
				'error',
				$key_is_live
					? __( 'That key works, but it is a LIVE key sitting in the sandbox tab. Remove it before testing — it charges real cards.', 'thirtydayhomes' )
					: __( 'That key works, but it is a test key sitting in the production tab. Live payments would not go through.', 'thirtydayhomes' )
			);
			$this->redirect( $mode );
		}

		$missing = Stripe::plans_missing_price( $mode );

		if ( $missing ) {
			$this->notice(
				'warning',
				sprintf(
					/* translators: %s: comma-separated plan names */
					__( 'Connected to Stripe. No Price ID yet for: %s.', 'thirtydayhomes' ),
					implode( ', ', $missing )
				)
			);
			$this->redirect( $mode );
		}

		/*
		 * Every Price is filled in — but filled in is not the same as
		 * correct. Ask Stripe what each one actually charges, because the
		 * right IDs in the wrong slots is the mistake that silently bills
		 * the wrong amount for the wrong quota.
		 */
		$wrong = Stripe::verify_prices( $mode, $secret );

		$this->notice(
			$wrong ? 'error' : 'success',
			$wrong
				? __( 'Connected to Stripe, but the Price IDs do not match the plans: ', 'thirtydayhomes' ) . implode( ' ', $wrong )
				: __( 'Connected to Stripe. Every plan has a Price ID, and each one charges what the pricing page says, monthly.', 'thirtydayhomes' )
		);

		$this->redirect( $mode );
	}

	/* ---------------------------------------------------------------------
	 * Fields
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string,array{label:string,secret:bool,hint:string}>
	 */
	private function fields( string $mode ): array {

		$fields = [
			'publishable' => [
				'label'  => __( 'Publishable key', 'thirtydayhomes' ),
				'secret' => false,
				'hint'   => '',
			],
			'secret'      => [
				'label'  => __( 'Secret key', 'thirtydayhomes' ),
				'secret' => true,
				'hint'   => '',
			],
			'webhook'     => [
				'label'  => __( 'Webhook signing secret', 'thirtydayhomes' ),
				'secret' => true,
				'hint'   => __( 'From the matching Stripe mode. Subscribe the endpoint to checkout, subscription and invoice events.', 'thirtydayhomes' ),
			],
		];

		foreach ( Stripe::plans() as $plan ) {
			$fields[ Stripe::price_field( $plan['listings'] ) ] = [
				'label'  => sprintf(
					/* translators: %s: plan name, e.g. "2 listings" */
					__( '%s — Price ID', 'thirtydayhomes' ),
					$plan['label']
				),
				'secret' => false,
				'hint'   => '',
			];
		}

		return $fields;
	}

	private function prefix_ok( string $value, string $field, string $mode ): bool {

		$prefixes = Stripe::expected_prefixes( $field, $mode );

		if ( ! $prefixes ) {
			return true;
		}

		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $value, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/* ---------------------------------------------------------------------
	 * Notices
	 * ------------------------------------------------------------------ */

	private function notice( string $type, string $message ): void {
		set_transient( self::NOTICE . get_current_user_id(), [ $type, $message ], 60 );
	}

	private function take_notice(): ?array {

		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );

		delete_transient( $key );

		return is_array( $notice ) ? $notice : null;
	}

	private function redirect( string $tab = '' ): void {
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Screen
	 * ------------------------------------------------------------------ */

	public function render(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab    = $this->tab();
		$mode   = Stripe::mode();
		$notice = $this->take_notice();
		?>
		<div class="wrap tdh-payments">

			<h1><?php esc_html_e( 'Stripe — payments', 'thirtydayhomes' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?>">
					<p><?php echo esc_html( $notice[1] ); ?></p>
				</div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Sandbox and production credentials are stored separately. Switching the mode changes which set is used; it never overwrites the other.', 'thirtydayhomes' ); ?>
			</p>

			<?php $this->render_mode_form( $mode ); ?>
			<?php $this->render_tabs( $tab ); ?>

			<?php
			/*
			 * BOTH panels are printed, and the tab click just swaps which one
			 * is visible — no page load. They stay SEPARATE FORMS, which is
			 * the point: sandbox and production fields are never inside one
			 * form and so can never be submitted together, whatever the tab
			 * script does or fails to do.
			 *
			 * With JavaScript off the tabs are still ordinary links, the
			 * server decides which panel is hidden, and everything works —
			 * one page load slower.
			 */
			foreach ( array_keys( Stripe::modes() ) as $panel ) {
				$this->render_credentials_form( $panel, $panel === $tab );
			}
			?>

		</div>
		<?php
	}

	/**
	 * No focus ring on the two mode tabs. Client's explicit call, asked twice.
	 *
	 * An earlier version suppressed it only for `:focus:not(:focus-visible)`,
	 * to keep the ring for keyboard users. That did not work here and is
	 * worth recording: the tab script calls preventDefault(), so focus stays
	 * on the anchor, and Chrome then treats it as keyboard focus and matches
	 * :focus-visible — falling through to the browser's own default outline,
	 * which is the dark ring that replaced the blue one.
	 *
	 * WHAT IS LOST: someone tabbing with a keyboard gets no ring on these two
	 * tabs. What they do still get is WordPress's active-tab styling, which
	 * changes the moment a tab is activated — so the STATE stays visible even
	 * though focus is not. Scoped to `.nav-tab` inside this screen only:
	 * every field, button and link here keeps its normal focus indicator.
	 */
	public function print_styles(): void {
		?>
		<style>
			.tdh-payments .nav-tab:focus,
			.tdh-payments .nav-tab:focus-visible,
			.tdh-payments .nav-tab:active {
				box-shadow: none;
				outline: 0;
			}
		</style>
		<?php
	}

	/**
	 * Swap panels without a page load.
	 *
	 * Progressive enhancement: the tabs are real links to ?tab=…, so this
	 * only intercepts them. If the script never runs, the links navigate and
	 * the screen behaves exactly as before.
	 */
	public function print_script(): void {
		?>
		<script>
		( function () {
			var tabs   = document.querySelectorAll( '[data-tdh-tab]' );
			var panels = document.querySelectorAll( '[data-tdh-panel]' );

			if ( ! tabs.length || ! panels.length ) {
				return;
			}

			function show( mode ) {
				Array.prototype.forEach.call( panels, function ( panel ) {
					panel.hidden = panel.getAttribute( 'data-tdh-panel' ) !== mode;
				} );

				Array.prototype.forEach.call( tabs, function ( tab ) {
					var on = tab.getAttribute( 'data-tdh-tab' ) === mode;
					tab.classList.toggle( 'nav-tab-active', on );
					tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				} );

				// Keep the address bar honest without navigating, so a reload
				// or a bookmark reopens the tab actually being looked at.
				if ( window.history && window.history.replaceState ) {
					try {
						var url = new URL( window.location.href );
						url.searchParams.set( 'tab', mode );
						window.history.replaceState( {}, '', url.toString() );
					} catch ( e ) {}
				}
			}

			Array.prototype.forEach.call( tabs, function ( tab ) {
				tab.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					show( tab.getAttribute( 'data-tdh-tab' ) );
				} );
			} );
		}() );
		</script>
		<?php
	}

	private function render_mode_form( string $mode ): void {
		?>
		<form method="post" class="tdh-mode-form" style="margin:1.5em 0;padding:1em 1.25em;background:#fff;border:1px solid #c3c4c7;">
			<?php wp_nonce_field( 'tdh_stripe_mode' ); ?>
			<input type="hidden" name="tdh_stripe_action" value="mode">

			<fieldset>
				<legend class="screen-reader-text"><?php esc_html_e( 'Payment mode', 'thirtydayhomes' ); ?></legend>
				<strong style="margin-right:1em;"><?php esc_html_e( 'Payment mode', 'thirtydayhomes' ); ?></strong>

				<?php foreach ( Stripe::modes() as $value => $label ) : ?>
					<label style="margin-right:1.25em;">
						<input type="radio" name="tdh_mode" value="<?php echo esc_attr( $value ); ?>" <?php checked( $mode, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>

				<span class="description" style="margin-right:1em;">
					<?php esc_html_e( 'Only the selected mode is used for payments and webhooks.', 'thirtydayhomes' ); ?>
				</span>

				<?php submit_button( __( 'Save payment mode', 'thirtydayhomes' ), 'primary', 'submit', false ); ?>
			</fieldset>

			<?php if ( Stripe::is_live() ) : ?>
				<p style="margin:0.75em 0 0;color:#8a2424;">
					<strong><?php esc_html_e( 'Live mode is on — real cards are charged.', 'thirtydayhomes' ); ?></strong>
				</p>
			<?php endif; ?>
		</form>
		<?php
	}

	private function render_tabs( string $tab ): void {
		?>
		<h2 class="nav-tab-wrapper" role="tablist">
			<?php
			$labels = [
				Stripe::MODE_TEST => __( 'Test / Sandbox', 'thirtydayhomes' ),
				Stripe::MODE_LIVE => __( 'Live / Production', 'thirtydayhomes' ),
			];

			foreach ( $labels as $mode => $label ) :
				$active = $mode === $tab;
				?>
				<a href="<?php echo esc_url( self::url( $mode ) ); ?>"
					class="nav-tab <?php echo $active ? 'nav-tab-active' : ''; ?>"
					role="tab"
					aria-selected="<?php echo $active ? 'true' : 'false'; ?>"
					data-tdh-tab="<?php echo esc_attr( $mode ); ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</h2>
		<?php
	}

	private function render_credentials_form( string $tab, bool $active = true ): void {

		$is_live = Stripe::MODE_LIVE === $tab;
		?>
		<div data-tdh-panel="<?php echo esc_attr( $tab ); ?>" <?php echo $active ? '' : 'hidden'; ?>>
		<form method="post" style="padding:1em 1.25em;background:#fff;border:1px solid #c3c4c7;border-top:0;">
			<?php wp_nonce_field( 'tdh_stripe_credentials' ); ?>
			<input type="hidden" name="tdh_stripe_action" value="credentials">
			<input type="hidden" name="tdh_tab" value="<?php echo esc_attr( $tab ); ?>">

			<p class="description">
				<?php
				echo esc_html(
					$is_live
						? __( 'Live keys and live Price IDs. Only fill these in when the site is ready to accept real payments.', 'thirtydayhomes' )
						: __( 'Stripe test keys and test Price IDs. No real money is charged.', 'thirtydayhomes' )
				);
				?>
			</p>

			<table class="form-table" role="presentation">
				<?php foreach ( $this->fields( $tab ) as $field => $spec ) : ?>
					<?php
					$locked = Stripe::is_locked( $tab, $field );
					$stored = Stripe::credential( $field, $tab );

					// Scoped by mode: both panels are in the DOM at once, so
					// an unscoped id would appear twice and every <label for>
					// would point at whichever came first — clicking the live
					// label would focus the sandbox field.
					$id = 'tdh-' . $tab . '-' . $field;

					$prefixes = Stripe::expected_prefixes( $field, $tab );
					?>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $spec['label'] ); ?></label>
						</th>
						<td>
							<?php if ( $locked ) : ?>

								<?php // Carries the id too, or the label above it points at nothing. ?>
								<input type="text" class="regular-text code" disabled
									id="<?php echo esc_attr( $id ); ?>"
									value="<?php echo esc_attr( $spec['secret'] ? '••••••••••••••••' : $stored ); ?>">
								<p class="description">
									<?php
									printf(
										/* translators: %s: PHP constant name */
										esc_html__( 'Set in wp-config.php as %s, so it cannot be edited here.', 'thirtydayhomes' ),
										'<code>' . esc_html( Stripe::constant_name( $tab, $field ) ) . '</code>'
									);
									?>
								</p>

							<?php elseif ( $spec['secret'] ) : ?>

								<?php
								/*
								 * type="text", NOT type="password".
								 *
								 * A password field makes the browser offer to
								 * save the value in its password manager — Brave
								 * prompted to store a Stripe secret key against
								 * the admin's own login, which would sync it to
								 * a browser profile in the cloud. An API key does
								 * not belong there.
								 *
								 * Nothing is lost by showing it: the field is
								 * ALWAYS empty on load, because a stored secret is
								 * never rendered. The only thing ever visible is
								 * what the person pasting is already looking at,
								 * and seeing it is how a truncated paste gets
								 * caught. Stripe's own dashboard shows keys the
								 * same way.
								 */
								?>
								<input type="text" class="regular-text code"
									autocomplete="off" spellcheck="false"
									data-lpignore="true" data-1p-ignore
									id="<?php echo esc_attr( $id ); ?>"
									name="tdh_<?php echo esc_attr( $field ); ?>"
									placeholder="<?php echo esc_attr( '' !== $stored ? '••••••••••••••••' : ( $prefixes[0] ?? '' ) . '…' ); ?>">
								<p class="description">
									<?php
									echo '' !== $stored
										? esc_html__( 'Saved. Leave blank to keep it, or paste a new value to replace it.', 'thirtydayhomes' )
										: esc_html__( 'Not set yet.', 'thirtydayhomes' );
									?>
									<?php echo '' !== $spec['hint'] ? '<br>' . esc_html( $spec['hint'] ) : ''; ?>
								</p>

							<?php else : ?>

								<input type="text" class="regular-text code"
									id="<?php echo esc_attr( $id ); ?>"
									name="tdh_<?php echo esc_attr( $field ); ?>"
									value="<?php echo esc_attr( $stored ); ?>"
									placeholder="<?php echo esc_attr( ( $prefixes[0] ?? '' ) . '…' ); ?>">
								<?php if ( '' !== $spec['hint'] ) : ?>
									<p class="description"><?php echo esc_html( $spec['hint'] ); ?></p>
								<?php endif; ?>

							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>

				<tr>
					<th scope="row"><?php esc_html_e( 'Webhook endpoint', 'thirtydayhomes' ); ?></th>
					<td>
						<code><?php echo esc_html( Stripe::webhook_url() ); ?></code>
						<p class="description">
							<?php esc_html_e( 'Add this as an endpoint in the Stripe dashboard for this mode, then paste its signing secret above.', 'thirtydayhomes' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save', 'thirtydayhomes' ) ); ?>
		</form>

		<form method="post" style="margin-top:-1em;">
			<?php wp_nonce_field( 'tdh_stripe_test_connection' ); ?>
			<input type="hidden" name="tdh_stripe_action" value="test_connection">
			<input type="hidden" name="tdh_tab" value="<?php echo esc_attr( $tab ); ?>">
			<?php
			submit_button(
				$is_live
					? __( 'Test live connection', 'thirtydayhomes' )
					: __( 'Test sandbox connection', 'thirtydayhomes' ),
				'secondary',
				'submit',
				true
			);
			?>
		</form>
		</div>
		<?php
	}
}
