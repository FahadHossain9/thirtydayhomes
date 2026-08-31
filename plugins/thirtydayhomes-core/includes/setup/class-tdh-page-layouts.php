<?php
/**
 * Pages built as Elementor sections.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the editable pages as discrete Elementor sections.
 *
 * ─── ONE SECTION, ONE WIDGET ───────────────────────────────────────────
 *
 * Seeding a page as a block of HTML made Elementor import it as a single
 * "Text Editor" widget — the client would have edited an entire homepage
 * through one textarea. Each section now holds exactly one named widget,
 * so the structure tree reads as things a person can point at.
 *
 * ─── WHY MORE THAN THE HOMEPAGE ────────────────────────────────────────
 *
 * This class used to be Homepage_Layout, and only the homepage was built
 * this way. Every other page shipped as a bare shortcode with its copy as
 * PHP defaults, so measured on the live site the administrator could edit
 * 2 of About's 312 words, and none of How it works, Pricing or Contact.
 *
 * Handoff principle 2 requires public sections to be editable "through
 * Elementor without editing PHP", and Milestone 1 acceptance test 6 checks
 * it directly. A page listed here is a page the client owns.
 *
 * ─── THE SHORTCODE VERSION SURVIVES ────────────────────────────────────
 *
 * post_content keeps its shortcode version untouched. Elementor ignores it
 * while edit mode is "builder", but it is what renders if Elementor is
 * ever deactivated — which is the whole reason the blocks are shortcodes
 * first and widgets second.
 */
final class Page_Layouts {

	public function __construct( private Importer $importer ) {}

	private int $counter = 0;

	/**
	 * Which widgets each page is built from.
	 *
	 * Keyed by the importer's `_tdh_seed_key`, not by slug: a client who
	 * renames "About" must not quietly stop having a layout. The front page
	 * is handled separately because it is found through page_on_front.
	 *
	 * @return array<string,string[]>
	 */
	private function layouts(): array {
		return [
			'about'        => [ 'tdh-about' ],
			'how-it-works' => [ 'tdh-how-it-works' ],
			'pricing'      => [ 'tdh-pricing' ],
			'contact'      => [ 'tdh-contact' ],
		];
	}

	public function run(): void {

		if ( ! did_action( 'elementor/loaded' ) ) {
			$this->importer->log( 'Elementor not active — skipped, the shortcode pages still render' );
			return;
		}

		$this->build_front_page();

		foreach ( $this->layouts() as $key => $widgets ) {

			$id = $this->page_for( $key );

			if ( ! $id ) {
				$this->importer->warn( sprintf( 'no page found for "%s" — run the structure step first', $key ) );
				continue;
			}

			$this->build( $id, $widgets, $key );
		}
	}

	private function build_front_page(): void {

		$front_id = (int) get_option( 'page_on_front' );

		if ( ! $front_id ) {
			$this->importer->warn( 'no static front page is set — run the pages step first' );
			return;
		}

		$this->build(
			$front_id,
			[
				'tdh-hero-search',
				'tdh-audience',
				'tdh-property-grid',
				'tdh-split-feature',
				'tdh-owner-cta',
			],
			'home'
		);
	}

	/**
	 * Find a seeded page by the importer's own key.
	 */
	private function page_for( string $key ): int {

		$found = get_posts(
			[
				'post_type'        => 'page',
				'post_status'      => [ 'publish', 'draft', 'pending', 'private' ],
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'meta_key'         => '_tdh_seed_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $key,            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'suppress_filters' => false,
			]
		);

		return $found ? (int) $found[0] : 0;
	}

	/**
	 * @param string[] $widgets
	 */
	private function build( int $page_id, array $widgets, string $label ): void {

		/*
		 * Somebody has already edited this page in Elementor.
		 *
		 * Rebuilding would throw their work away, and this step is safe to
		 * re-run precisely because it does not. The check is the same idea
		 * as the content fingerprint in Site_Structure: written by us and
		 * untouched means ours to replace; edited means theirs to keep.
		 */
		$stored = (string) get_post_meta( $page_id, '_tdh_layout_fingerprint', true );
		$current = (string) get_post_meta( $page_id, '_elementor_data', true );

		if ( '' !== $current && '' !== $stored && ! hash_equals( $stored, md5( $current ) ) ) {
			$this->importer->log( sprintf( '%s left alone — edited in Elementor since import', $label ) );
			return;
		}

		$data = [];

		foreach ( $widgets as $widget ) {
			$data[] = $this->section( $widget );
		}

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
		$autosave = wp_get_post_autosave( $page_id );

		if ( $autosave ) {
			wp_delete_post( $autosave->ID, true );
			$this->importer->log( sprintf( '%s — removed a stale editor autosave', $label ) );
		}

		update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $page_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' );

		// wp_slash: update_post_meta unslashes, and Elementor data is JSON
		// full of quotes. Without it the stored JSON is corrupted and the
		// page opens empty in the editor.
		$json = (string) wp_json_encode( $data );

		update_post_meta( $page_id, '_elementor_data', wp_slash( $json ) );

		// Record what we wrote, so a later run can tell our layout from a
		// person's edit. Read back rather than hashing $json directly:
		// update_post_meta stores the unslashed form, and hashing the other
		// one would mark every page as edited on the next run.
		update_post_meta( $page_id, '_tdh_layout_fingerprint', md5( (string) get_post_meta( $page_id, '_elementor_data', true ) ) );

		$this->clear_caches( $page_id );

		$this->importer->log( sprintf( '%s built — %d section(s)', $label, count( $data ) ) );
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
