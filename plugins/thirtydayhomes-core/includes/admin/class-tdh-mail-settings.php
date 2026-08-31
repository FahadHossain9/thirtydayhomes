<?php
/**
 * Email delivery settings screen.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Admin;

use TDH\Mail;
use TDH\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Listings → Email delivery.
 *
 * Three forms, because they are three separate decisions and one Save button
 * across all of them means changing the sender name also rewrites the server
 * credentials. The same reasoning splits the Payments screen.
 *
 * The screen leads with STATUS rather than fields. Whoever opens this page has
 * almost always arrived because something did not arrive, and the first
 * question is never "what are the settings" — it is "is it working, and if
 * not, what did the server say".
 */
final class Mail_Settings {

	private const PAGE   = 'tdh-email';
	private const NOTICE = 'tdh_mail_notice_';

	private string $hook = '';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
	}

	public function add_page(): void {

		$hook = add_submenu_page(
			'edit.php?post_type=tdh_listing',
			__( 'Email delivery', 'thirtydayhomes' ),
			__( 'Email delivery', 'thirtydayhomes' ),
			// A moderator who approves homes has no business holding the
			// mailbox password — the same reasoning that guards Payments.
			'manage_options',
			self::PAGE,
			[ $this, 'render' ]
		);

		if ( is_string( $hook ) && '' !== $hook ) {
			$this->hook = $hook;

			add_action( 'load-' . $hook, [ $this, 'handle_post' ] );
			add_action( 'admin_head-' . $hook, [ $this, 'print_styles' ] );
		}
	}

	public static function url(): string {
		return add_query_arg(
			[
				'post_type' => 'tdh_listing',
				'page'      => self::PAGE,
			],
			admin_url( 'edit.php' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Saving
	 * ------------------------------------------------------------------ */

	public function handle_post(): void {

		if ( empty( $_POST['tdh_mail_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change email settings.', 'thirtydayhomes' ) );
		}

		$action = sanitize_key( wp_unslash( (string) $_POST['tdh_mail_action'] ) );

		check_admin_referer( 'tdh_mail_' . $action );

		match ( $action ) {
			'sender' => $this->save_sender(),
			'smtp'   => $this->save_smtp(),
			'test'   => $this->send_test(),
			default  => null,
		};
	}

	private function save_sender(): void {

		$name = isset( $_POST['tdh_from_name'] )
			? trim( sanitize_text_field( wp_unslash( (string) $_POST['tdh_from_name'] ) ) )
			: '';

		$address = isset( $_POST['tdh_from'] )
			? trim( sanitize_text_field( wp_unslash( (string) $_POST['tdh_from'] ) ) )
			: '';

		/*
		 * An invalid address is refused rather than stored. Mail::from_address()
		 * falls back to noreply@ on the site domain when the stored value is not
		 * an email, so saving rubbish here would look accepted and quietly send
		 * as something else — the worst of both.
		 */
		if ( '' !== $address && ! is_email( $address ) ) {
			$this->notice( 'error', __( 'That is not a valid email address. The sender was left as it was.', 'thirtydayhomes' ) );
			$this->redirect();
		}

		update_option( Mail::OPTION_FROM_NAME, $name );
		update_option( Mail::OPTION_FROM, $address );

		$this->notice(
			'success',
			'' === $address
				? __( 'Sender saved. With the address blank it falls back to noreply@ on this site’s own domain.', 'thirtydayhomes' )
				: __( 'Sender saved.', 'thirtydayhomes' )
		);

		$this->redirect();
	}

	private function save_smtp(): void {

		Smtp::save( 'enabled', empty( $_POST['tdh_enabled'] ) ? '' : '1' );

		$encryption = isset( $_POST['tdh_encryption'] )
			? sanitize_key( wp_unslash( (string) $_POST['tdh_encryption'] ) )
			: 'tls';

		if ( ! Smtp::is_locked( 'encryption' ) ) {
			Smtp::save( 'encryption', array_key_exists( $encryption, Smtp::encryptions() ) ? $encryption : 'tls' );
		}

		foreach ( Smtp::fields() as $field => $spec ) {

			if ( Smtp::is_locked( $field ) ) {
				continue;
			}

			$raw = isset( $_POST[ 'tdh_' . $field ] )
				? trim( sanitize_text_field( wp_unslash( (string) $_POST[ 'tdh_' . $field ] ) ) )
				: '';

			// A blank password means "keep what is stored", not "erase it".
			// The field renders empty by design, so writing the blank back is
			// how a working password gets wiped by somebody fixing a typo in
			// the host name.
			if ( '' === $raw && $spec['secret'] ) {
				continue;
			}

			Smtp::save( $field, $raw );
		}

		$problems = Smtp::problems();

		if ( Smtp::is_on() && $problems ) {
			$this->notice(
				'warning',
				__( 'Saved, but not usable yet: ', 'thirtydayhomes' ) . implode( ' ', $problems )
			);
			$this->redirect();
		}

		$this->notice(
			'success',
			Smtp::is_on()
				? __( 'Saved. Send a test below — saving proves nothing until a message actually arrives.', 'thirtydayhomes' )
				: __( 'Saved. SMTP is switched off, so mail goes out through the server’s own sending, unauthenticated.', 'thirtydayhomes' )
		);

		$this->redirect();
	}

	private function send_test(): void {

		$to = isset( $_POST['tdh_test_to'] )
			? trim( sanitize_email( wp_unslash( (string) $_POST['tdh_test_to'] ) ) )
			: '';

		$result = Smtp::send_test( $to );

		/*
		 * The transcript is kept with the notice rather than printed straight
		 * out, because the send happens before any output — this runs on
		 * `load-{hook}` so that a save can redirect — and there is nowhere to
		 * print to yet.
		 */
		$this->notice(
			$result['sent'] ? 'success' : 'error',
			$result['message'],
			$result['transcript']
		);

		$this->redirect();
	}

	/* ---------------------------------------------------------------------
	 * Notices
	 * ------------------------------------------------------------------ */

	private function notice( string $type, string $message, string $detail = '' ): void {
		set_transient( self::NOTICE . get_current_user_id(), [ $type, $message, $detail ], 120 );
	}

	/**
	 * @return array{0:string,1:string,2:string}|null
	 */
	private function take_notice(): ?array {

		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );

		delete_transient( $key );

		if ( ! is_array( $notice ) ) {
			return null;
		}

		return [
			(string) ( $notice[0] ?? 'info' ),
			(string) ( $notice[1] ?? '' ),
			(string) ( $notice[2] ?? '' ),
		];
	}

	private function redirect(): void {
		wp_safe_redirect( self::url() );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Screen
	 * ------------------------------------------------------------------ */

	public function render(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice   = $this->take_notice();
		$mail     = new Mail();
		$from     = $mail->from_address( '' );
		$fromname = $mail->from_name( '' );
		$domain   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$domain   = preg_replace( '/^www\./i', '', $domain );
		$error    = Smtp::last_error();
		$sent     = Smtp::last_sent();
		?>
		<div class="wrap tdh-email">

			<h1><?php esc_html_e( 'Email delivery', 'thirtydayhomes' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?>">
					<p><?php echo esc_html( $notice[1] ); ?></p>

					<?php if ( '' !== $notice[2] ) : ?>
						<details>
							<summary><?php esc_html_e( 'What the mail server said', 'thirtydayhomes' ); ?></summary>
							<pre class="tdh-transcript"><?php echo esc_html( $notice[2] ); ?></pre>
						</details>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php $this->render_status( $from, $fromname, $error, $sent ); ?>

			<?php $this->render_sender_form( $from, $fromname ); ?>

			<?php $this->render_smtp_form(); ?>

			<?php $this->render_test_form(); ?>

			<?php $this->render_dns( (string) $domain ); ?>

		</div>
		<?php
	}

	/**
	 * @param array{at:int,message:string}|null $error
	 */
	private function render_status( string $from, string $fromname, ?array $error, int $sent ): void {

		$capturing = Mail::capturing();
		$ready     = Smtp::is_ready();
		?>
		<div class="tdh-card">
			<h2><?php esc_html_e( 'Right now', 'thirtydayhomes' ); ?></h2>

			<table class="widefat striped tdh-status">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Messages are', 'thirtydayhomes' ); ?></th>
						<td>
							<?php if ( $capturing ) : ?>
								<span class="tdh-pill tdh-pill--warn"><?php esc_html_e( 'written to a file, not sent', 'thirtydayhomes' ); ?></span>
								<p class="description">
									<?php
									printf(
										/* translators: 1: environment name, 2: directory */
										esc_html__( 'This is a %1$s environment, so nothing leaves the machine. Look in %2$s.', 'thirtydayhomes' ),
										esc_html( wp_get_environment_type() ),
										'<code>' . esc_html( Mail::capture_dir() ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput
									);
									?>
								</p>
							<?php elseif ( $ready ) : ?>
								<span class="tdh-pill tdh-pill--ok"><?php esc_html_e( 'sent through your SMTP server', 'thirtydayhomes' ); ?></span>
								<p class="description">
									<?php echo esc_html( Smtp::setting( 'host' ) . ':' . Smtp::port() ); ?>
								</p>
							<?php else : ?>
								<span class="tdh-pill tdh-pill--warn"><?php esc_html_e( 'handed to the server’s own mail, unauthenticated', 'thirtydayhomes' ); ?></span>
								<p class="description">
									<?php esc_html_e( 'This usually reaches an inbox eventually and is usually filed as spam. Configure SMTP below.', 'thirtydayhomes' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Sent as', 'thirtydayhomes' ); ?></th>
						<td>
							<code><?php echo esc_html( $fromname . ' <' . $from . '>' ); ?></code>

							<?php foreach ( Smtp::sender_problems() as $problem ) : ?>
								<p class="description tdh-warn"><?php echo esc_html( $problem ); ?></p>
							<?php endforeach; ?>

							<?php if ( ! Smtp::from_matches_site() ) : ?>
								<p class="description tdh-warn">
									<?php esc_html_e( 'That address is not on this site’s own domain. SPF and DKIM authenticate the From domain, so mail sent this way fails both no matter how the DNS is set up — unless you also control that other domain and have published records there.', 'thirtydayhomes' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Last success', 'thirtydayhomes' ); ?></th>
						<td>
							<?php if ( $sent ) : ?>
								<?php
								printf(
									/* translators: %s: human-readable time difference */
									esc_html__( '%s ago', 'thirtydayhomes' ),
									esc_html( human_time_diff( $sent ) )
								);
								?>
							<?php else : ?>
								<?php esc_html_e( 'Nothing recorded yet.', 'thirtydayhomes' ); ?>
							<?php endif; ?>
						</td>
					</tr>

					<?php if ( $error ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Last failure', 'thirtydayhomes' ); ?></th>
							<td>
								<p class="tdh-warn"><?php echo esc_html( $error['message'] ); ?></p>
								<p class="description">
									<?php
									printf(
										/* translators: %s: human-readable time difference */
										esc_html__( '%s ago. This clears itself the next time a message goes out successfully.', 'thirtydayhomes' ),
										esc_html( human_time_diff( $error['at'] ) )
									);
									?>
								</p>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_sender_form( string $from, string $fromname ): void {
		?>
		<div class="tdh-card">
			<h2><?php esc_html_e( 'Who the mail is from', 'thirtydayhomes' ); ?></h2>

			<form method="post">
				<?php wp_nonce_field( 'tdh_mail_sender' ); ?>
				<input type="hidden" name="tdh_mail_action" value="sender">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="tdh-from-name"><?php esc_html_e( 'Name', 'thirtydayhomes' ); ?></label>
						</th>
						<td>
							<input id="tdh-from-name" name="tdh_from_name" type="text" class="regular-text"
								value="<?php echo esc_attr( (string) get_option( Mail::OPTION_FROM_NAME, '' ) ); ?>"
								placeholder="<?php echo esc_attr( $fromname ); ?>">
							<p class="description"><?php esc_html_e( 'Blank uses the site title.', 'thirtydayhomes' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="tdh-from"><?php esc_html_e( 'Address', 'thirtydayhomes' ); ?></label>
						</th>
						<td>
							<input id="tdh-from" name="tdh_from" type="email" class="regular-text"
								value="<?php echo esc_attr( (string) get_option( Mail::OPTION_FROM, '' ) ); ?>"
								placeholder="<?php echo esc_attr( $from ); ?>">
							<p class="description">
								<?php esc_html_e( 'Blank uses noreply@ on this site’s domain. It must be an address the SMTP account below is allowed to send as, or the server will refuse the message.', 'thirtydayhomes' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save sender', 'thirtydayhomes' ) ); ?>
			</form>
		</div>
		<?php
	}

	private function render_smtp_form(): void {
		?>
		<div class="tdh-card">
			<h2><?php esc_html_e( 'Your mail server', 'thirtydayhomes' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'Any provider that speaks SMTP works here — a mailbox on your own hosting, Google Workspace, Brevo, Postmark, Resend, Amazon SES. Changing provider later is a change to these fields, not a change to the site.', 'thirtydayhomes' ); ?>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'tdh_mail_smtp' ); ?>
				<input type="hidden" name="tdh_mail_action" value="smtp">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Use SMTP', 'thirtydayhomes' ); ?></th>
						<td>
							<label>
								<input name="tdh_enabled" type="checkbox" value="1" <?php checked( Smtp::is_on() ); ?>>
								<?php esc_html_e( 'Send through the server below', 'thirtydayhomes' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Off means WordPress hands mail to the web server, which sends it unauthenticated.', 'thirtydayhomes' ); ?>
							</p>
						</td>
					</tr>

					<?php foreach ( Smtp::fields() as $field => $spec ) : ?>
						<?php
						$id     = 'tdh-' . $field;
						$locked = Smtp::is_locked( $field );
						$stored = Smtp::setting( $field );
						?>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $spec['label'] ); ?></label>
							</th>
							<td>
								<?php if ( $locked ) : ?>
									<input id="<?php echo esc_attr( $id ); ?>" type="text" class="regular-text" disabled
										value="<?php echo esc_attr( $spec['secret'] ? '••••••••' : $stored ); ?>">
									<p class="description">
										<?php
										printf(
											/* translators: %s: PHP constant name */
											esc_html__( 'Set in wp-config.php as %s, so it cannot be changed here.', 'thirtydayhomes' ),
											'<code>' . esc_html( Smtp::constant_name( $field ) ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput
										);
										?>
									</p>
								<?php else : ?>
									<?php
									/*
									 * type="text", not type="password", and the value is
									 * never echoed. A password field makes the browser
									 * offer to save the mailbox password into the admin's
									 * own password manager, filed under this site — which
									 * is how it later gets autofilled into the wrong form.
									 * The same decision, for the same reason, as the
									 * Stripe secret key field.
									 */
									?>
									<input id="<?php echo esc_attr( $id ); ?>" name="tdh_<?php echo esc_attr( $field ); ?>"
										type="text" class="regular-text" autocomplete="off" spellcheck="false"
										value="<?php echo esc_attr( $spec['secret'] ? '' : $stored ); ?>"
										<?php if ( $spec['secret'] && '' !== $stored ) : ?>
											placeholder="<?php esc_attr_e( '•••••••• saved — leave blank to keep it', 'thirtydayhomes' ); ?>"
										<?php endif; ?>>
									<p class="description"><?php echo esc_html( $spec['hint'] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>

					<tr>
						<th scope="row">
							<label for="tdh-encryption"><?php esc_html_e( 'Encryption', 'thirtydayhomes' ); ?></label>
						</th>
						<td>
							<select id="tdh-encryption" name="tdh_encryption" <?php disabled( Smtp::is_locked( 'encryption' ) ); ?>>
								<?php foreach ( Smtp::encryptions() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( Smtp::encryption(), $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save mail server', 'thirtydayhomes' ) ); ?>
			</form>
		</div>
		<?php
	}

	private function render_test_form(): void {

		$user = wp_get_current_user();
		?>
		<div class="tdh-card">
			<h2><?php esc_html_e( 'Send a test', 'thirtydayhomes' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'Saving proves nothing. This sends a real message and reports exactly what the mail server answered.', 'thirtydayhomes' ); ?>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'tdh_mail_test' ); ?>
				<input type="hidden" name="tdh_mail_action" value="test">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="tdh-test-to"><?php esc_html_e( 'Send to', 'thirtydayhomes' ); ?></label>
						</th>
						<td>
							<input id="tdh-test-to" name="tdh_test_to" type="email" class="regular-text" required
								value="<?php echo esc_attr( (string) $user->user_email ); ?>">
							<p class="description">
								<?php esc_html_e( 'Test an address on a different provider too — Gmail and Outlook filter differently, and passing one says little about the other.', 'thirtydayhomes' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Send test email', 'thirtydayhomes' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	private function render_dns( string $domain ): void {

		$domain = '' !== $domain ? $domain : 'yourdomain.com';
		?>
		<div class="tdh-card">
			<h2><?php esc_html_e( 'The part that is not in WordPress', 'thirtydayhomes' ); ?></h2>

			<p>
				<?php
				printf(
					/* translators: %s: the site's domain */
					esc_html__( 'Authenticating to your mail server proves the site may use that mailbox. It does not prove to Gmail that %s allows this server to send on its behalf — that is what these three DNS records do, and without them well-formed mail still lands in spam.', 'thirtydayhomes' ),
					'<strong>' . esc_html( $domain ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput
				);
				?>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Record', 'thirtydayhomes' ); ?></th>
						<th><?php esc_html_e( 'What it says', 'thirtydayhomes' ); ?></th>
						<th><?php esc_html_e( 'Where it comes from', 'thirtydayhomes' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><strong>SPF</strong><br><code>TXT</code> <?php esc_html_e( 'on the domain root', 'thirtydayhomes' ); ?></td>
						<td><?php esc_html_e( 'Which servers are allowed to send as this domain. One SPF record only — two is the same as none.', 'thirtydayhomes' ); ?></td>
						<td><?php esc_html_e( 'Your provider publishes the include: value to use.', 'thirtydayhomes' ); ?></td>
					</tr>
					<tr>
						<td><strong>DKIM</strong><br><code>TXT</code> <?php esc_html_e( 'on a selector subdomain', 'thirtydayhomes' ); ?></td>
						<td><?php esc_html_e( 'A signature proving the message was not altered and really came from you.', 'thirtydayhomes' ); ?></td>
						<td><?php esc_html_e( 'Your provider generates the key and gives you the exact record.', 'thirtydayhomes' ); ?></td>
					</tr>
					<tr>
						<td><strong>DMARC</strong><br><code>TXT</code> <?php esc_html_e( 'on _dmarc', 'thirtydayhomes' ); ?></td>
						<td><?php esc_html_e( 'What a receiver should do when the first two fail, and where to report it.', 'thirtydayhomes' ); ?></td>
						<td>
							<?php esc_html_e( 'Start permissive and tighten once reports are clean:', 'thirtydayhomes' ); ?>
							<br>
							<code>v=DMARC1; p=none; rua=mailto:dmarc@<?php echo esc_html( $domain ); ?></code>
						</td>
					</tr>
				</tbody>
			</table>

			<p class="description">
				<?php esc_html_e( 'Publish SPF and DKIM first and let them settle, then add DMARC at p=none. Going straight to p=reject before the first two are right rejects your own mail.', 'thirtydayhomes' ); ?>
			</p>
		</div>
		<?php
	}

	public function print_styles(): void {
		?>
		<style>
			.tdh-email .tdh-card {
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				padding: 4px 20px 20px;
				margin: 20px 0;
				max-width: 900px;
			}
			.tdh-email .tdh-card > h2 { margin-bottom: 4px; }
			.tdh-email .tdh-status th { width: 160px; }
			.tdh-email .tdh-pill {
				display: inline-block;
				padding: 2px 10px;
				border-radius: 10px;
				font-size: 12px;
				font-weight: 600;
			}
			.tdh-email .tdh-pill--ok   { background: #e3f2e9; color: #1c6b45; }
			.tdh-email .tdh-pill--warn { background: #fff1cf; color: #7a5c05; }
			.tdh-email .tdh-warn       { color: #a94242; }
			.tdh-email .tdh-transcript {
				max-height: 260px;
				overflow: auto;
				background: #1d2327;
				color: #e6e8ea;
				padding: 12px;
				border-radius: 3px;
				font-size: 12px;
				line-height: 1.5;
				white-space: pre-wrap;
				word-break: break-word;
			}
		</style>
		<?php
	}
}
