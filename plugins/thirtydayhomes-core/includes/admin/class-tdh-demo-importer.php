<?php
/**
 * Demo import admin screen.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Admin;

use TDH\Setup\Importer;

defined( 'ABSPATH' ) || exit;

/**
 * Tools → Import Demo Content.
 *
 * One button that builds the whole demonstration site: pages, menus,
 * sample listings with photographs, and the Elementor homepage.
 *
 * ─── WHY IT IS NOT AUTOMATIC ───────────────────────────────────────────
 *
 * Plenty of themes run their demo import on activation. That is a trap:
 * activating a theme on a site that already has content would overwrite
 * the client's menus and front page with no warning and no undo. This
 * requires a deliberate click, states plainly what it will overwrite, and
 * asks for confirmation when it has already been run.
 */
final class Demo_Importer {

	private const SLUG  = 'tdh-import-demo';
	private const NONCE = 'tdh_import_demo';

	/** @var array{log:string[],failed:bool}|null */
	private ?array $result = null;

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_init', [ $this, 'handle' ] );
	}

	public function add_page(): void {
		add_management_page(
			__( 'Import Demo Content', 'thirtydayhomes' ),
			__( 'Import Demo Content', 'thirtydayhomes' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Run the import when the form is posted.
	 *
	 * On admin_init rather than inside render() so the work happens before
	 * any output — an import that takes 40 seconds should not be streaming
	 * into a half-rendered page, and a redirect after would lose the log.
	 */
	public function handle(): void {

		if ( ! isset( $_POST['tdh_import_demo_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import demo content.', 'thirtydayhomes' ), 403 );
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			wp_die( esc_html__( 'That form has expired. Reload the page and try again.', 'thirtydayhomes' ), 403 );
		}

		$steps = isset( $_POST['steps'] ) && is_array( $_POST['steps'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['steps'] ) )
			: [];

		// Nothing ticked is a no-op, not "run everything". Unticking every
		// box then clicking should not import the whole site.
		if ( ! $steps ) {
			$this->result = [ 'log' => [ __( 'Nothing selected — nothing was imported.', 'thirtydayhomes' ) ], 'failed' => false ];
			return;
		}

		$this->result = ( new Importer() )->run( $steps );
	}

	public function render(): void {

		$imported = Importer::imported_at();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Demo Content', 'thirtydayhomes' ); ?></h1>

			<?php if ( null !== $this->result ) : ?>
				<div class="notice notice-<?php echo $this->result['failed'] ? 'warning' : 'success'; ?>">
					<p>
						<strong>
							<?php
							echo $this->result['failed']
								? esc_html__( 'Import finished with warnings.', 'thirtydayhomes' )
								: esc_html__( 'Import complete.', 'thirtydayhomes' );
							?>
						</strong>
					</p>
				</div>

				<div class="card" style="max-width:none;padding:16px 20px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'What happened', 'thirtydayhomes' ); ?></h2>
					<pre style="margin:0;padding:14px;background:#f6f7f7;border:1px solid #dcdcde;overflow:auto;max-height:22em;"><?php
						echo esc_html( implode( "\n", $this->result['log'] ) );
					?></pre>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>">
							<?php esc_html_e( 'View the site', 'thirtydayhomes' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>

			<div class="card" style="max-width:none;padding:16px 20px;margin-top:20px;">

				<p style="max-width:62em;font-size:14px;">
					<?php esc_html_e( 'Builds the complete demonstration site in one step: every public page, both navigation menus, five Pittsburgh medical facilities, four furnished listings with photographs, and the homepage laid out in Elementor.', 'thirtydayhomes' ); ?>
				</p>

				<?php if ( $imported ) : ?>
					<div class="notice notice-info inline" style="margin:12px 0;">
						<p>
							<?php
							printf(
								/* translators: %s: date and time of the last import */
								esc_html__( 'Demo content was last imported on %s. Running it again is safe: anything you have edited is left exactly as it is, and only pages and menus still untouched since the import are brought up to date.', 'thirtydayhomes' ),
								esc_html( $imported )
							);
							?>
						</p>
						<p>
							<?php esc_html_e( 'Re-run it after a deployment that changed page content — a deployment copies code, not pages, so the two can otherwise drift apart.', 'thirtydayhomes' ); ?>
						</p>
					</div>
				<?php endif; ?>

				<form method="post">
					<?php wp_nonce_field( self::NONCE ); ?>

					<table class="form-table" role="presentation">
						<tbody>
						<?php foreach ( Importer::steps() as $key => $step ) : ?>
							<tr>
								<th scope="row" style="padding-left:0;">
									<label for="tdh-step-<?php echo esc_attr( $key ); ?>">
										<input
											type="checkbox"
											id="tdh-step-<?php echo esc_attr( $key ); ?>"
											name="steps[]"
											value="<?php echo esc_attr( $key ); ?>"
											checked
										>
										<?php echo esc_html( $step['label'] ); ?>
									</label>
								</th>
								<td><p class="description" style="max-width:56em;"><?php echo esc_html( $step['description'] ); ?></p></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<p style="margin-top:18px;">
						<button
							type="submit"
							name="tdh_import_demo_submit"
							value="1"
							class="button button-primary button-hero"
						>
							<?php esc_html_e( 'Import demo content', 'thirtydayhomes' ); ?>
						</button>
					</p>

					<p class="description">
						<?php esc_html_e( 'Takes up to a minute — each photograph generates seven cropped sizes. Do not close this tab while it runs.', 'thirtydayhomes' ); ?>
					</p>
				</form>
			</div>

			<div class="card" style="max-width:none;padding:16px 20px;margin-top:20px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'What it does not touch', 'thirtydayhomes' ); ?></h2>
				<ul style="list-style:disc;margin-left:20px;max-width:62em;">
					<li><?php esc_html_e( 'Existing listings you created yourself. Only the four sample listings are updated, matched by an internal key rather than by title.', 'thirtydayhomes' ); ?></li>
					<li><?php esc_html_e( 'Users, roles, memberships and inquiries.', 'thirtydayhomes' ); ?></li>
					<li><?php esc_html_e( 'Theme settings, the Customizer, and your uploaded logo or hero photograph.', 'thirtydayhomes' ); ?></li>
					<li><?php esc_html_e( 'Any page you created that is not part of the demo set.', 'thirtydayhomes' ); ?></li>
				</ul>
				<p class="description">
					<?php esc_html_e( 'Re-running is safe and does not duplicate anything. A page or menu you have edited is fingerprinted as yours and left alone; the run reports which ones it skipped. To hand one back, revert your change and run it again.', 'thirtydayhomes' ); ?>
				</p>
			</div>
		</div>
		<?php
	}
}
