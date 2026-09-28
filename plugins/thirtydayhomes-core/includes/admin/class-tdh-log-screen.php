<?php
/**
 * The log, read in wp-admin.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Admin;

use TDH\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Listings → Logs.
 *
 * Here and not in the marketplace portal on purpose. The portal is the
 * client's daily surface — homes, members, hospitals — and a developer's
 * event log there is noise for them and a question they should not have to
 * ask. The other developer-facing screens (Email delivery, Payments,
 * Security) already live under this menu, and so does this one.
 *
 * Administrators only, like those screens: a moderator who approves homes
 * has no need of masked sign-in attempts and webhook internals.
 *
 * Native WordPress markup throughout — nav tabs, a striped table, the
 * button styles — so the screen needs almost no CSS of its own and looks
 * like the room it is in.
 */
final class Log_Screen {

	public const PAGE = 'tdh-logs';

	private string $hook = '';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
	}

	public function add_page(): void {

		$errors = Log::recent_errors();
		$label  = __( 'Logs', 'thirtydayhomes' );

		// The same bubble WordPress uses for comments awaiting moderation:
		// errors in the last week, visible from any admin screen.
		if ( $errors > 0 ) {
			$label .= sprintf(
				' <span class="awaiting-mod count-%1$d" title="%2$s"><span class="pending-count">%3$s</span></span>',
				$errors,
				esc_attr( sprintf( /* translators: %s: number of errors */ _n( '%s error in the last 7 days', '%s errors in the last 7 days', $errors, 'thirtydayhomes' ), number_format_i18n( $errors ) ) ),
				esc_html( number_format_i18n( $errors ) )
			);
		}

		$hook = add_submenu_page(
			'edit.php?post_type=tdh_listing',
			__( 'Logs', 'thirtydayhomes' ),
			$label,
			'manage_options',
			self::PAGE,
			[ $this, 'render' ]
		);

		if ( is_string( $hook ) && '' !== $hook ) {
			$this->hook = $hook;
			add_action( 'admin_head-' . $hook, [ $this, 'print_styles' ] );
		}
	}

	/** May the current user read the log? */
	public static function allowed(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @param array<string,mixed> $args Extra query arguments.
	 */
	public static function url( array $args = [] ): string {
		return add_query_arg(
			array_filter( $args, static fn( $v ) => null !== $v && '' !== $v ) + [
				'post_type' => 'tdh_listing',
				'page'      => self::PAGE,
			],
			admin_url( 'edit.php' )
		);
	}

	/* ---------------------------------------------------------------------
	 * The screen
	 * ------------------------------------------------------------------ */

	public function render(): void {

		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'You are not allowed to read the log.', 'thirtydayhomes' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$features = Log::features();
		$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'all';
		$level    = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( (string) $_GET['level'] ) ) : 'all';
		$search   = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		$days     = (int) ( $_GET['days'] ?? 7 );
		$page     = max( 1, (int) ( $_GET['pg'] ?? 1 ) );
		// phpcs:enable

		if ( 'status' !== $tab && 'all' !== $tab && ! isset( $features[ $tab ] ) ) {
			$tab = 'all';
		}
		if ( ! in_array( $level, [ 'all', 'warning', 'error' ], true ) ) {
			$level = 'all';
		}
		if ( ! in_array( $days, [ 1, 7, 30, 90 ], true ) ) {
			$days = 7;
		}

		$since    = time() - $days * DAY_IN_SECONDS;
		$errors   = Log::counts( Log::ERROR, $since );
		$filtered = 'all' !== $level || '' !== $search || 7 !== $days;

		$keep = [
			'level' => 'all' !== $level ? $level : null,
			'q'     => '' !== $search ? $search : null,
			'days'  => 7 !== $days ? $days : null,
		];

		$tab_url = static fn( string $to ): string => self::url( [ 'tab' => 'all' === $to ? null : $to ] + ( 'status' === $to ? [] : $keep ) );

		$periods = [
			1  => __( 'Last 24 hours', 'thirtydayhomes' ),
			7  => __( 'Last 7 days', 'thirtydayhomes' ),
			30 => __( 'Last 30 days', 'thirtydayhomes' ),
			90 => __( 'Last 90 days', 'thirtydayhomes' ),
		];
		?>
		<div class="wrap tdh-logs">
			<h1><?php esc_html_e( 'Logs', 'thirtydayhomes' ); ?></h1>
			<p class="description">
				<?php
				printf(
					/* translators: %s: timezone name */
					esc_html__( 'Every important action and failure, newest first. Times are shown in %s.', 'thirtydayhomes' ),
					esc_html( wp_timezone_string() )
				);
				?>
			</p>

			<nav class="nav-tab-wrapper tdh-logs-tabs" aria-label="<?php esc_attr_e( 'Log sections', 'thirtydayhomes' ); ?>">
				<a class="nav-tab <?php echo 'all' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $tab_url( 'all' ) ); ?>">
					<?php esc_html_e( 'All', 'thirtydayhomes' ); ?>
					<?php if ( array_sum( $errors ) > 0 ) : ?>
						<span class="tdh-logs-count"><?php echo esc_html( number_format_i18n( array_sum( $errors ) ) ); ?></span>
					<?php endif; ?>
				</a>
				<?php foreach ( $features as $key => $label ) : ?>
					<a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $tab_url( $key ) ); ?>">
						<?php echo esc_html( $label ); ?>
						<?php if ( ! empty( $errors[ $key ] ) ) : ?>
							<span class="tdh-logs-count" title="<?php echo esc_attr( sprintf( /* translators: %s: number of errors */ _n( '%s error', '%s errors', (int) $errors[ $key ], 'thirtydayhomes' ), number_format_i18n( (int) $errors[ $key ] ) ) ); ?>"><?php echo esc_html( number_format_i18n( (int) $errors[ $key ] ) ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
				<a class="nav-tab <?php echo 'status' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $tab_url( 'status' ) ); ?>"><?php esc_html_e( 'System status', 'thirtydayhomes' ); ?></a>
			</nav>

			<?php if ( 'status' === $tab ) : ?>
				<?php $this->status(); ?>
			<?php else : ?>
				<?php $this->lines( $tab, $level, $search, $days, $page, $since, $filtered, $features, $periods, $tab_url ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The filter form, the table, the pages.
	 *
	 * @param array<string,string> $features
	 * @param array<int,string>    $periods
	 */
	private function lines( string $tab, string $level, string $search, int $days, int $page, int $since, bool $filtered, array $features, array $periods, callable $tab_url ): void {

		$result = Log::query(
			[
				'feature'   => 'all' === $tab ? '' : $tab,
				'min_level' => 'all' === $level ? '' : $level,
				'search'    => $search,
				'since'     => $since,
				'page'      => $page,
			]
		);
		?>
		<form class="tdh-logs-filters" method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
			<input type="hidden" name="post_type" value="tdh_listing">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
			<?php if ( 'all' !== $tab ) : ?>
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
			<?php endif; ?>

			<label for="tdh-log-level"><?php esc_html_e( 'Show', 'thirtydayhomes' ); ?></label>
			<select id="tdh-log-level" name="level">
				<option value="all" <?php selected( $level, 'all' ); ?>><?php esc_html_e( 'Everything', 'thirtydayhomes' ); ?></option>
				<option value="warning" <?php selected( $level, 'warning' ); ?>><?php esc_html_e( 'Warnings and errors', 'thirtydayhomes' ); ?></option>
				<option value="error" <?php selected( $level, 'error' ); ?>><?php esc_html_e( 'Errors only', 'thirtydayhomes' ); ?></option>
			</select>

			<label for="tdh-log-days"><?php esc_html_e( 'Period', 'thirtydayhomes' ); ?></label>
			<select id="tdh-log-days" name="days">
				<?php foreach ( $periods as $value => $label ) : ?>
					<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $days, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>

			<label for="tdh-log-q"><?php esc_html_e( 'Search messages', 'thirtydayhomes' ); ?></label>
			<input id="tdh-log-q" name="q" type="search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'e.g. Shadyside, refused', 'thirtydayhomes' ); ?>">

			<button class="button button-primary" type="submit"><?php esc_html_e( 'Filter', 'thirtydayhomes' ); ?></button>
			<?php if ( $filtered ) : ?>
				<a class="button" href="<?php echo esc_url( self::url( [ 'tab' => 'all' === $tab ? null : $tab ] ) ); ?>"><?php esc_html_e( 'Clear filters', 'thirtydayhomes' ); ?></a>
			<?php endif; ?>
		</form>

		<?php if ( ! $result['rows'] ) : ?>
			<div class="tdh-logs-empty">
				<h2><?php esc_html_e( 'Nothing logged for these filters', 'thirtydayhomes' ); ?></h2>
				<p>
					<?php
					echo esc_html(
						$filtered
							? __( 'Try a longer period, or clear the filters.', 'thirtydayhomes' )
							: __( 'Nothing has happened in this section in the last 7 days. That is usually good news.', 'thirtydayhomes' )
					);
					?>
				</p>
			</div>
			<?php return; ?>
		<?php endif; ?>

		<p class="tdh-logs-total">
			<?php
			printf(
				/* translators: 1: number of lines, 2: period */
				esc_html( _n( '%1$s line, %2$s', '%1$s lines, %2$s', $result['total'], 'thirtydayhomes' ) ),
				esc_html( number_format_i18n( $result['total'] ) ),
				esc_html( mb_strtolower( $periods[ $days ] ) )
			);
			?>
		</p>

		<table class="widefat striped tdh-logs-table">
			<thead>
				<tr>
					<th scope="col" class="is-time"><?php esc_html_e( 'Time', 'thirtydayhomes' ); ?></th>
					<th scope="col" class="is-level"><?php esc_html_e( 'Level', 'thirtydayhomes' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What happened', 'thirtydayhomes' ); ?></th>
					<th scope="col" class="is-context"><?php esc_html_e( 'Details', 'thirtydayhomes' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $result['rows'] as $row ) : ?>
					<?php $this->row( $row, $features ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $result['pages'] > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						(string) paginate_links(
							[
								'base'      => add_query_arg( 'pg', '%#%', $tab_url( $tab ) ),
								'format'    => '',
								'current'   => $result['page'],
								'total'     => $result['pages'],
								'prev_text' => __( '‹ Newer', 'thirtydayhomes' ),
								'next_text' => __( 'Older ›', 'thirtydayhomes' ),
							]
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * One line: when, how serious, what happened (with who and about what
	 * underneath), and the context behind "View".
	 *
	 * @param array<string,mixed>  $row
	 * @param array<string,string> $features
	 */
	private function row( array $row, array $features ): void {

		$level_label = Log::levels()[ $row['level'] ] ?? ucfirst( (string) $row['level'] );
		$feature     = $features[ $row['feature'] ] ?? ucfirst( (string) $row['feature'] );

		if ( $row['user_id'] > 0 ) {
			$user = get_userdata( (int) $row['user_id'] );
			$who  = $user ? $user->display_name : sprintf( /* translators: %d: user id */ __( 'deleted account #%d', 'thirtydayhomes' ), (int) $row['user_id'] );
		} else {
			$who = __( 'Visitor', 'thirtydayhomes' );
		}

		$about = '';
		if ( $row['object_id'] > 0 ) {
			if ( 'user' === $row['object_type'] ) {
				$target = get_userdata( (int) $row['object_id'] );
				$about  = $target ? $target->display_name : __( 'deleted account', 'thirtydayhomes' );
			} else {
				$post  = get_post( (int) $row['object_id'] );
				$about = $post ? get_the_title( $post ) : __( 'deleted', 'thirtydayhomes' );
			}
		}

		$time = (int) $row['time'];
		?>
		<tr>
			<td class="is-time">
				<time datetime="<?php echo esc_attr( gmdate( 'c', $time ) ); ?>" title="<?php echo esc_attr( gmdate( 'Y-m-d H:i:s', $time ) . ' UTC' ); ?>"><?php echo esc_html( wp_date( 'j M, g:i A', $time ) ); ?></time>
			</td>
			<td class="is-level">
				<span class="tdh-logs-level is-<?php echo esc_attr( (string) $row['level'] ); ?>"><?php echo esc_html( $level_label ); ?></span>
			</td>
			<td class="is-message">
				<strong><?php echo esc_html( (string) $row['message'] ); ?></strong>
				<span class="tdh-logs-meta"><?php echo esc_html( trim( $feature . ' · ' . $who . ( '' !== $about ? ' · ' . $about : '' ) ) ); ?></span>
			</td>
			<td class="is-context">
				<details>
					<summary><?php esc_html_e( 'View', 'thirtydayhomes' ); ?><span class="screen-reader-text"> <?php esc_html_e( 'details of this line', 'thirtydayhomes' ); ?></span></summary>
					<dl>
						<?php foreach ( (array) $row['context'] as $key => $value ) : ?>
							<dt><?php echo esc_html( (string) $key ); ?></dt>
							<dd><?php echo esc_html( is_scalar( $value ) || null === $value ? (string) ( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : $value ) : (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></dd>
						<?php endforeach; ?>
						<dt><?php esc_html_e( 'event', 'thirtydayhomes' ); ?></dt>
						<dd><?php echo esc_html( (string) $row['event'] ); ?></dd>
						<?php if ( '' !== (string) $row['source'] ) : ?>
							<dt><?php esc_html_e( 'where', 'thirtydayhomes' ); ?></dt>
							<dd><?php echo esc_html( (string) $row['source'] ); ?></dd>
						<?php endif; ?>
						<?php if ( '' !== (string) $row['request_id'] ) : ?>
							<dt><?php esc_html_e( 'request', 'thirtydayhomes' ); ?></dt>
							<dd><?php echo esc_html( (string) $row['request_id'] ); ?></dd>
						<?php endif; ?>
					</dl>
				</details>
			</td>
		</tr>
		<?php
	}

	/**
	 * "Is everything connected?" — one row per dependency, in words.
	 */
	private function status(): void {

		$words = [
			'ok'        => __( 'Working', 'thirtydayhomes' ),
			'attention' => __( 'Needs attention', 'thirtydayhomes' ),
			'off'       => __( 'Not set up', 'thirtydayhomes' ),
			'pending'   => __( 'Not yet', 'thirtydayhomes' ),
		];
		?>
		<h2><?php esc_html_e( 'System status', 'thirtydayhomes' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Each service the site depends on, and the last thing it did.', 'thirtydayhomes' ); ?></p>

		<table class="widefat striped tdh-logs-status">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Service', 'thirtydayhomes' ); ?></th>
					<th scope="col"><?php esc_html_e( 'State', 'thirtydayhomes' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Detail', 'thirtydayhomes' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( Log::status() as $row ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $row['label'] ); ?></strong></td>
						<td><span class="tdh-logs-state is-<?php echo esc_attr( $row['state'] ); ?>"><?php echo esc_html( $words[ $row['state'] ] ?? $words['pending'] ); ?></span></td>
						<td><?php echo esc_html( $row['detail'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The little this screen needs beyond WordPress's own admin styles:
	 * worded level and state chips, the filter row, the context grid, and
	 * the phone layout. Scoped under .tdh-logs; nothing else is touched.
	 */
	public function print_styles(): void {
		?>
		<style>
			.tdh-logs .tdh-logs-tabs { margin-bottom: 16px; }
			.tdh-logs .nav-tab .tdh-logs-count { display: inline-block; min-width: 18px; margin-left: 4px; padding: 0 5px; border-radius: 9px; background: #d63638; color: #fff; font-size: 11px; font-weight: 600; line-height: 18px; text-align: center; }
			.tdh-logs .tdh-logs-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; margin: 0 0 12px; }
			.tdh-logs .tdh-logs-filters > label { font-weight: 600; }
			.tdh-logs .tdh-logs-filters > input[type="search"] { min-width: 16em; }
			.tdh-logs .tdh-logs-total { margin: 0 0 8px; color: #646970; }
			.tdh-logs .tdh-logs-table th.is-time, .tdh-logs .tdh-logs-table td.is-time { width: 9em; white-space: nowrap; color: #646970; font-variant-numeric: tabular-nums; }
			.tdh-logs .tdh-logs-table th.is-level, .tdh-logs .tdh-logs-table td.is-level { width: 7em; }
			.tdh-logs .tdh-logs-table th.is-context, .tdh-logs .tdh-logs-table td.is-context { width: 30%; }
			.tdh-logs .tdh-logs-table td { vertical-align: top; }
			.tdh-logs .tdh-logs-table td.is-message > strong { display: block; overflow-wrap: anywhere; }
			.tdh-logs .tdh-logs-table td.is-message > .tdh-logs-meta { display: block; margin-top: 2px; color: #646970; font-size: 12px; overflow-wrap: anywhere; }
			.tdh-logs .tdh-logs-level, .tdh-logs .tdh-logs-state { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #f0f0f1; color: #1d2327; font-size: 11px; font-weight: 600; line-height: 16px; white-space: nowrap; }
			.tdh-logs .tdh-logs-level.is-warning, .tdh-logs .tdh-logs-state.is-pending { background: #fcf9e8; color: #8a6d00; }
			.tdh-logs .tdh-logs-level.is-error, .tdh-logs .tdh-logs-level.is-critical, .tdh-logs .tdh-logs-state.is-attention { background: #fcf0f1; color: #b32d2e; }
			.tdh-logs .tdh-logs-state.is-ok { background: #edfaef; color: #00700f; }
			.tdh-logs .tdh-logs-table details > summary { display: inline-block; min-height: 2.25em; line-height: 2.25em; color: #2271b1; cursor: pointer; text-decoration: underline; list-style: none; }
			.tdh-logs .tdh-logs-table details > summary::-webkit-details-marker { display: none; }
			.tdh-logs .tdh-logs-table details > summary:focus-visible { outline: 2px solid #2271b1; outline-offset: 2px; }
			.tdh-logs .tdh-logs-table details > dl { display: grid; grid-template-columns: max-content minmax( 0, 1fr ); gap: 4px 12px; margin: 4px 0 0; padding: 10px 12px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; font-size: 12px; }
			.tdh-logs .tdh-logs-table details > dl > dt { color: #646970; }
			.tdh-logs .tdh-logs-table details > dl > dd { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; font-family: Consolas, Monaco, monospace; }
			.tdh-logs .tdh-logs-empty { margin: 24px 0; padding: 32px 16px; background: #fff; border: 1px solid #c3c4c7; text-align: center; }
			.tdh-logs .tdh-logs-empty > h2 { margin: 0 0 6px; }
			.tdh-logs .tdh-logs-empty > p { margin: 0; color: #646970; }
			.tdh-logs .tdh-logs-status td { vertical-align: top; }
			@media ( max-width: 782px ) {
				.tdh-logs .tdh-logs-table thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect( 0 0 0 0 ); }
				.tdh-logs .tdh-logs-table tr { display: grid; grid-template-columns: minmax( 0, 1fr ) auto; gap: 4px 12px; padding: 10px 8px; }
				.tdh-logs .tdh-logs-table td { display: block; width: auto !important; padding: 0; border: 0; }
				.tdh-logs .tdh-logs-table td.is-time { grid-area: 1 / 1; }
				.tdh-logs .tdh-logs-table td.is-level { grid-area: 1 / 2; justify-self: end; }
				.tdh-logs .tdh-logs-table td.is-message, .tdh-logs .tdh-logs-table td.is-context { grid-column: 1 / -1; }
				.tdh-logs .tdh-logs-table details > dl { grid-template-columns: minmax( 0, 1fr ); }
			}
		</style>
		<?php
	}
}
