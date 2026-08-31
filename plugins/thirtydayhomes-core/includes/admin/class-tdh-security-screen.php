<?php
/**
 * Listings → Security.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Admin;

use TDH\Security_Baseline;

defined( 'ABSPATH' ) || exit;

/**
 * The security baseline, in the admin.
 *
 * It existed only as a CLI script, and the one instruction it gives is "run
 * this again after any change" — which meant the person most likely to
 * change something was the one person who could not run it. A check nobody
 * runs is not a check.
 *
 * Read-only. There is no button that fixes anything: each finding names the
 * exact setting and where it lives, because the fixes are one-line edits to
 * wp-config.php or a WordPress setting, and a plugin that rewrites
 * wp-config.php on a live site is a worse idea than an unfixed warning.
 */
final class Security_Screen {

	private const PAGE = 'tdh-security';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
	}

	public function add_page(): void {

		$hook = add_submenu_page(
			'edit.php?post_type=tdh_listing',
			__( 'Security', 'thirtydayhomes' ),
			__( 'Security', 'thirtydayhomes' ),
			'manage_options',
			self::PAGE,
			[ $this, 'render' ]
		);

		if ( is_string( $hook ) && '' !== $hook ) {
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

	public function render(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		/*
		 * The checks hit the WordPress.org update API, so this is slow enough
		 * to be worth not doing on every page load of the whole admin — but
		 * it is a page somebody opened on purpose, so it runs now rather than
		 * showing a cached answer that may no longer be true.
		 */
		$results = ( new Security_Baseline() )->run();
		$counts  = Security_Baseline::summary( $results );

		$production = Security_Baseline::is_production();
		?>
		<div class="wrap tdh-security">

			<h1><?php esc_html_e( 'Security baseline', 'thirtydayhomes' ); ?></h1>

			<div class="tdh-sec-summary tdh-sec-summary--<?php echo esc_attr( $counts['fail'] ? 'bad' : 'good' ); ?>">
				<p>
					<strong>
						<?php
						if ( $counts['fail'] ) {
							printf(
								/* translators: %d: number of failing checks */
								esc_html( _n( '%d thing needs attention', '%d things need attention', $counts['fail'], 'thirtydayhomes' ) ),
								(int) $counts['fail']
							);
						} else {
							esc_html_e( 'Everything checked here is in order', 'thirtydayhomes' );
						}
						?>
					</strong>
				</p>
				<p class="description">
					<?php
					printf(
						/* translators: 1: passed, 2: skipped, 3: environment, 4: site url */
						esc_html__( '%1$d passed, %2$d not applicable. Environment: %3$s — %4$s', 'thirtydayhomes' ),
						(int) $counts['pass'],
						(int) $counts['skip'],
						esc_html( wp_get_environment_type() ),
						esc_html( home_url() )
					);
					?>
				</p>

				<?php if ( ! $production ) : ?>
					<p class="description tdh-sec-warn">
						<?php esc_html_e( 'This is not the live site. Every production-only check was skipped, so a clean result here says nothing about the live site — run it there too.', 'thirtydayhomes' ); ?>
					</p>
				<?php endif; ?>
			</div>

			<?php
			$group = '';

			foreach ( $results as $r ) :

				if ( $r['group'] !== $group ) :
					if ( '' !== $group ) :
						?>
						</tbody></table>
						<?php
					endif;
					$group = $r['group'];
					?>
					<h2><?php echo esc_html( $group ); ?></h2>
					<table class="widefat striped tdh-sec-table"><tbody>
					<?php
				endif;
				?>
				<tr class="tdh-sec-row tdh-sec-row--<?php echo esc_attr( $r['status'] ); ?>">
					<td class="tdh-sec-status">
						<?php
						$label = [
							Security_Baseline::PASS => __( 'ok', 'thirtydayhomes' ),
							Security_Baseline::FAIL => __( 'FIX', 'thirtydayhomes' ),
							Security_Baseline::SKIP => __( 'n/a', 'thirtydayhomes' ),
							Security_Baseline::NOTE => __( 'note', 'thirtydayhomes' ),
						][ $r['status'] ] ?? '';
						?>
						<span class="tdh-sec-pill"><?php echo esc_html( $label ); ?></span>
					</td>
					<td class="tdh-sec-label"><?php echo esc_html( $r['label'] ); ?></td>
					<td class="tdh-sec-detail">
						<?php echo esc_html( $r['detail'] ); ?>

						<?php if ( '' !== $r['fix'] ) : ?>
							<p class="tdh-sec-fix"><?php echo esc_html( $r['fix'] ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<?php
			endforeach;

			if ( '' !== $group ) :
				?>
				</tbody></table>
				<?php
			endif;
			?>

			<h2><?php esc_html_e( 'What this does not cover', 'thirtydayhomes' ); ?></h2>

			<p class="description" style="max-width:52em">
				<?php esc_html_e( 'This is a posture check, not monitoring. It tells you how the site is configured at the moment you open this page; it does not watch for intrusions, file changes or downtime, and it cannot tell you whether anything has already happened. Uptime monitoring and error logging are set up separately — see tools/BACKUPS.md in the repository.', 'thirtydayhomes' ); ?>
			</p>
		</div>
		<?php
	}

	public function print_styles(): void {
		?>
		<style>
			.tdh-security .tdh-sec-summary {
				background: #fff;
				border: 1px solid #c3c4c7;
				border-left-width: 4px;
				border-radius: 3px;
				padding: 12px 16px;
				margin: 16px 0 24px;
				max-width: 900px;
			}
			.tdh-security .tdh-sec-summary--good { border-left-color: #2e7358; }
			.tdh-security .tdh-sec-summary--bad  { border-left-color: #a94242; }
			.tdh-security .tdh-sec-summary p { margin: 4px 0; }
			.tdh-security .tdh-sec-warn { color: #8b6810; }
			.tdh-security .tdh-sec-table { max-width: 900px; margin-bottom: 8px; }
			.tdh-security .tdh-sec-status { width: 62px; vertical-align: top; padding-top: 12px; }
			.tdh-security .tdh-sec-label  { width: 300px; vertical-align: top; padding-top: 12px; font-weight: 600; }
			.tdh-security .tdh-sec-detail { vertical-align: top; padding-top: 12px; }
			.tdh-security .tdh-sec-pill {
				display: inline-block;
				min-width: 34px;
				text-align: center;
				padding: 2px 8px;
				border-radius: 10px;
				font-size: 11px;
				font-weight: 700;
				text-transform: uppercase;
				letter-spacing: 0.4px;
			}
			.tdh-sec-row--pass .tdh-sec-pill { background: #e3f2e9; color: #1c6b45; }
			.tdh-sec-row--fail .tdh-sec-pill { background: #fae5e5; color: #a94242; }
			.tdh-sec-row--skip .tdh-sec-pill { background: #edf0f3; color: #5d6772; }
			.tdh-sec-row--note .tdh-sec-pill { background: #fff1cf; color: #7a5c05; }
			.tdh-security .tdh-sec-fix {
				margin: 6px 0 0;
				padding: 8px 10px;
				background: #f6f7f7;
				border-left: 3px solid #c3c4c7;
				font-size: 12px;
				line-height: 1.6;
				color: #3c434a;
			}
			.tdh-sec-row--fail .tdh-sec-fix { border-left-color: #a94242; }
		</style>
		<?php
	}
}
