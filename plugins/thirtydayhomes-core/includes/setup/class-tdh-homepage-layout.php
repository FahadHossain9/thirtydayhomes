<?php
/**
 * Homepage as Elementor sections.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the homepage as five discrete Elementor sections.
 *
 * ─── ONE SECTION, ONE WIDGET ───────────────────────────────────────────
 *
 * Seeding the page as a block of HTML made Elementor import it as a single
 * "Text Editor" widget — the client would have edited an entire homepage
 * through one textarea. Each section now holds exactly one named widget,
 * so the structure tree reads as five things a person can point at.
 *
 * ─── THE SHORTCODE VERSION SURVIVES ────────────────────────────────────
 *
 * post_content keeps its shortcode version untouched. Elementor ignores it
 * while edit mode is "builder", but it is what renders if Elementor is
 * ever deactivated — which is the whole reason the blocks are shortcodes
 * first and widgets second.
 */
final class Homepage_Layout {

	public function __construct( private Importer $importer ) {}

	private int $counter = 0;

	public function run(): void {

		if ( ! did_action( 'elementor/loaded' ) ) {
			$this->importer->log( 'Elementor not active — skipped, the shortcode homepage still renders' );
			return;
		}

		$front_id = (int) get_option( 'page_on_front' );

		if ( ! $front_id ) {
			$this->importer->warn( 'no static front page is set — run the pages step first' );
			return;
		}

		$data = [
			$this->section( 'tdh-hero-search' ),
			$this->section( 'tdh-audience' ),
			$this->section( 'tdh-property-grid' ),
			$this->section( 'tdh-split-feature' ),
			$this->section( 'tdh-owner-cta' ),
		];

		/*
		 * Drop any autosave FIRST.
		 *
		 * The Elementor editor writes an autosave as you work and loads it
		 * in preference to the published data when reopened. With the
		 * editor open during an import, the front end shows the new layout
		 * while the editor keeps showing the old draft — which looks
		 * exactly like the import silently failed. It did not; the autosave
		 * simply won.
		 */
		$autosave = wp_get_post_autosave( $front_id );

		if ( $autosave ) {
			wp_delete_post( $autosave->ID, true );
			$this->importer->log( 'removed a stale editor autosave' );
		}

		update_post_meta( $front_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $front_id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $front_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' );

		// wp_slash: update_post_meta unslashes, and Elementor data is JSON
		// full of quotes. Without it the stored JSON is corrupted and the
		// page opens empty in the editor.
		update_post_meta( $front_id, '_elementor_data', wp_slash( (string) wp_json_encode( $data ) ) );

		$this->clear_caches( $front_id );

		$this->importer->log( sprintf( 'homepage built — %d sections', count( $data ) ) );
	}

	/**
	 * A full-width section holding one widget.
	 *
	 * @return array<string,mixed>
	 */
	private function section( string $widget ): array {
		return [
			'id'       => $this->element_id(),
			'elType'   => 'section',
			'settings' => [ 'layout' => 'full_width', 'gap' => 'no' ],
			'elements' => [
				[
					'id'       => $this->element_id(),
					'elType'   => 'column',
					'settings' => [ '_column_size' => 100, '_inline_size' => null ],
					'elements' => [
						[
							'id'         => $this->element_id(),
							'elType'     => 'widget',
							'widgetType' => $widget,
							'settings'   => [],
							'elements'   => [],
						],
					],
				],
			],
			'isInner'  => false,
		];
	}

	/**
	 * Elementor element ids: 7 hex characters, unique within the page.
	 */
	private function element_id(): string {
		++$this->counter;
		return substr( md5( 'tdh-' . $this->counter . '-' . $this->salt() ), 0, 7 );
	}

	/**
	 * Stable per-run salt so ids differ between imports without needing a
	 * random source Elementor might collide with.
	 */
	private function salt(): string {
		static $salt = null;
		return $salt ??= (string) get_current_blog_id() . AUTH_SALT;
	}

	/**
	 * Elementor caches rendered CSS per post. Changing _elementor_data does
	 * not invalidate it, so a stale stylesheet outlives the new layout.
	 */
	private function clear_caches( int $post_id ): void {

		delete_post_meta( $post_id, '_elementor_css' );

		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			( new \Elementor\Core\Files\CSS\Post( $post_id ) )->delete();
		}

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}
}
