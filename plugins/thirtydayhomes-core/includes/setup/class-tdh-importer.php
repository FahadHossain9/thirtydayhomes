<?php
/**
 * Demo import orchestrator.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the demo import steps and collects a log.
 *
 * ─── WHY THIS EXISTS ───────────────────────────────────────────────────
 *
 * The seeders started life as WP-CLI scripts. They called WP_CLI::log()
 * directly and exited if not run through CLI, which meant a client
 * installing the theme and plugin on a live site had no way to reach them
 * at all — the content only existed on one laptop.
 *
 * The logic now lives here, and the two entry points are thin:
 *
 *   Tools → Import Demo Content   an admin screen with a button
 *   wp eval-file tools/*.php      the CLI wrappers, unchanged in usage
 *
 * Both call the same code, so a fix reaches both and neither can drift.
 *
 * ─── SAFETY ────────────────────────────────────────────────────────────
 *
 * Every step is idempotent: records are found by a meta key we control
 * (`_tdh_seed_key`), never by slug — a pending post has no slug until it
 * is first published, and custom statuses are excluded from
 * post_status => 'any', so a slug lookup silently creates duplicates. The
 * seeder did exactly that before it was fixed.
 *
 * The import is never automatic. It requires an explicit click, a nonce,
 * and the capability to manage options.
 */
final class Importer {

	/** @var string[] */
	private array $log = [];

	private bool $failed = false;

	/**
	 * Every step, in dependency order.
	 *
	 * @return array<string,array{label:string,description:string}>
	 */
	public static function steps(): array {
		return [
			'structure' => [
				'label'       => __( 'Pages and menus', 'thirtydayhomes' ),
				'description' => __( 'Home, About, How it works, Membership, Contact and the legal pages, plus the primary and footer menus. Sets the static front page.', 'thirtydayhomes' ),
			],
			'content'   => [
				'label'       => __( 'Sample listings and facilities', 'thirtydayhomes' ),
				'description' => __( 'Five Pittsburgh medical facilities and four furnished listings with photographs — one live, one pending review, one hidden for billing.', 'thirtydayhomes' ),
			],
			'homepage'  => [
				'label'       => __( 'Homepage layout', 'thirtydayhomes' ),
				'description' => __( 'Builds the homepage as Elementor sections. Skipped when Elementor is not active; the shortcode version still renders.', 'thirtydayhomes' ),
			],
		];
	}

	/**
	 * Run the requested steps.
	 *
	 * @param string[] $steps Step keys, or empty for all of them.
	 *
	 * @return array{log:string[],failed:bool}
	 */
	public function run( array $steps = [] ): array {

		$this->log    = [];
		$this->failed = false;

		if ( ! $steps ) {
			$steps = array_keys( self::steps() );
		}

		// Long-running on a slow host: four image imports each generate
		// seven cropped sub-sizes.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		foreach ( $steps as $step ) {
			try {
				match ( $step ) {
					'structure' => ( new Site_Structure( $this ) )->run(),
					'content'   => ( new Sample_Content( $this ) )->run(),
					'homepage'  => ( new Homepage_Layout( $this ) )->run(),
					default     => $this->warn( "unknown step: {$step}" ),
				};
			} catch ( \Throwable $e ) {
				// One failing step must not abandon the rest — a missing
				// image should not cost the client their menus.
				$this->failed = true;
				$this->warn( sprintf( '%s failed: %s', $step, $e->getMessage() ) );
			}
		}

		flush_rewrite_rules();

		update_option( 'tdh_demo_imported_at', current_time( 'mysql' ) );

		return [ 'log' => $this->log, 'failed' => $this->failed ];
	}

	public function log( string $message ): void {
		$this->log[] = $message;

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::log( '  ' . $message );
		}
	}

	public function warn( string $message ): void {
		$this->failed = true;
		$this->log[]  = '⚠ ' . $message;

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::warning( $message );
		}
	}

	/**
	 * Has the demo ever been imported on this site?
	 */
	public static function imported_at(): string {
		return (string) get_option( 'tdh_demo_imported_at', '' );
	}
}
