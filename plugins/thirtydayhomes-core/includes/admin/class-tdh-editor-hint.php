<?php
/**
 * A note in the block editor for pages built with Elementor.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Tells whoever opened the wrong editor that they opened the wrong editor.
 *
 * ─── THE THIRTY SECONDS THIS EXISTS TO PREVENT ─────────────────────────────
 *
 * The admin bar offers "Edit Page" and "Edit with Elementor" side by side.
 * About, How it works, Pricing and Contact are built as Elementor layouts,
 * and their post_content is a single shortcode — deliberately, because that
 * is what still renders if Elementor is ever deactivated.
 *
 * So a site owner who clicks the first button sees a page containing
 * `[tdh_about]` and nothing else, and concludes the page is empty or that
 * something is broken. It is neither. But nothing on that screen says so,
 * and the natural next move is to start typing, which replaces the
 * shortcode and quietly removes the fallback.
 *
 * One sentence and a link costs nothing and answers the question at the
 * moment it is asked.
 */
final class Editor_Hint {

	public function register(): void {
		add_action( 'edit_form_after_title', [ $this, 'render' ] );
	}

	/**
	 * @param \WP_Post $post
	 */
	public function render( $post ): void {

		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type ) {
			return;
		}

		// Built by us as an Elementor layout, and still in builder mode.
		$ours    = (string) get_post_meta( $post->ID, '_tdh_layout_fingerprint', true );
		$builder = 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true );

		if ( '' === $ours || ! $builder ) {
			return;
		}

		$edit = '';

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->documents ) ) {
			$document = \Elementor\Plugin::$instance->documents->get( $post->ID );
			$edit     = $document ? (string) $document->get_edit_url() : '';
		}
		?>
		<div class="notice notice-info inline" style="margin:16px 0;padding:12px 14px">
			<p style="margin:0 0 6px">
				<strong><?php esc_html_e( 'This page is built with Elementor.', 'thirtydayhomes' ); ?></strong>
			</p>
			<p style="margin:0 0 10px">
				<?php esc_html_e( 'Its headings, copy and buttons are edited there, not here. The shortcode below is not the page — it is the fallback that renders if Elementor is ever switched off, so leave it alone.', 'thirtydayhomes' ); ?>
			</p>

			<?php if ( '' !== $edit ) : ?>
				<p style="margin:0">
					<a class="button button-primary" href="<?php echo esc_url( $edit ); ?>">
						<?php esc_html_e( 'Edit with Elementor', 'thirtydayhomes' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
