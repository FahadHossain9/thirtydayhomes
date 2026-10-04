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
 *
 * G5b design review: the eleven feature tabs are six groups (a select on
 * phones); the page opens on the warnings of the period when there are
 * any, and says so; the line itself opens its context, so no Details
 * column; times in the market's zone, named.
 */
final class Log_Screen {

	public const PAGE = 'tdh-logs';

	/** The zone the market keeps time in; the site's own is UTC. */
	public const ZONE = 'America/New_York';

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

	/**
	 * The features as six tabs. Eleven in one row wrapped on anything
	 * narrower than a desk; a feature added by filter that belongs to no
	 * group gets a tab of its own, so nothing is unreachable.
	 *
	 * @return array<string,array{label:string,features:string[]}>
	 */
	public static function groups(): array {

		$features = Log::features();

		$groups = [
			'homes'    => [ 'label' => __( 'Homes & locations', 'thirtydayhomes' ), 'features' => [ 'listings', 'locations', 'facilities' ] ],
			'members'  => [ 'label' => __( 'Members & payments', 'thirtydayhomes' ), 'features' => [ 'members', 'payments' ] ],
			'messages' => [ 'label' => __( 'Messages', 'thirtydayhomes' ), 'features' => [ 'inquiries', 'email', 'sms' ] ],
			'signin'   => [ 'label' => __( 'Sign-in', 'thirtydayhomes' ), 'features' => [ 'signin' ] ],
			'settings' => [ 'label' => __( 'Settings', 'thirtydayhomes' ), 'features' => [ 'settings' ] ],
			'system'   => [ 'label' => __( 'System', 'thirtydayhomes' ), 'features' => [ 'system' ] ],
		];

		foreach ( $groups as &$group ) {
			$group['features'] = array_values( array_intersect( $group['features'], array_keys( $features ) ) );
		}
		unset( $group );

		$placed = array_merge( ...array_column( $groups, 'features' ) );

		foreach ( $features as $key => $label ) {
			if ( ! in_array( $key, $placed, true ) && ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = [ 'label' => $label, 'features' => [ $key ] ];
			}
		}

		return array_filter( $groups, static fn( array $g ): bool => [] !== $g['features'] );
	}

	/** The zone times are shown in. */
	public static function zone(): \DateTimeZone {
		/**
		 * The timezone the log is read in.
		 *
		 * @param string $zone A PHP timezone name.
		 */
		$name = (string) apply_filters( 'tdh_log_timezone', self::ZONE );

		try {
			return new \DateTimeZone( $name );
		} catch ( \Exception $e ) {
			return new \DateTimeZone( self::ZONE );
		}
	}

	/* ---------------------------------------------------------------------
	 * The screen
	 * ------------------------------------------------------------------ */

	public function render(): void {

		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'You are not allowed to read the log.', 'thirtydayhomes' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$features    = Log::features();
		$groups      = self::groups();
		$tab         = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'all';
		$level_given = isset( $_GET['level'] );
		$level       = $level_given ? sanitize_key( wp_unslash( (string) $_GET['level'] ) ) : 'all';
		$search      = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		$days        = (int) ( $_GET['days'] ?? 7 );
		$page        = max( 1, (int) ( $_GET['pg'] ?? 1 ) );
		// phpcs:enable

		// A group key, or a feature key from an older link (?tab=email):
		// both still work. Anything else is All, not an error.
		if ( 'status' !== $tab && 'all' !== $tab && ! isset( $groups[ $tab ] ) && ! isset( $features[ $tab ] ) ) {
			$tab = 'all';
		}
		if ( ! in_array( $level, [ 'all', 'warning', 'error' ], true ) ) {
			$level       = 'all';
			$level_given = false;
		}
		if ( ! in_array( $days, [ 1, 7, 30, 90 ], true ) ) {
			$days = 7;
		}

		$scope    = in_array( $tab, [ 'all', 'status' ], true ) ? [] : ( $groups[ $tab ]['features'] ?? [ $tab ] );
		$since    = time() - $days * DAY_IN_SECONDS;
		$errors   = Log::counts( Log::ERROR, $since );
		$warnings = Log::counts( Log::WARNING, $since );
		$in_scope = static fn( array $counts ): int => (int) array_sum( $scope ? array_intersect_key( $counts, array_flip( $scope ) ) : $counts );

		// Warnings first (G5b): when the period holds any, that is what the
		// page is opened for, and fifty "Signed in." lines buried them.
		$lead = 0;
		if ( ! $level_given && $in_scope( $warnings ) > 0 ) {
			$level = 'warning';
			$lead  = $in_scope( $warnings );
		}

		$filtered = ( $level_given && 'all' !== $level ) || '' !== $search || 7 !== $days;

		$keep = [
			'level' => $level_given && 'all' !== $level ? $level : null,
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

		$on_tab = static fn( string $key, array $group ): bool => $tab === $key || in_array( $tab, $group['features'], true );
		?>
		<div class="wrap tdh-logs">
			<h1><?php esc_html_e( 'Logs', 'thirtydayhomes' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Every important action and failure, newest first. Times are shown in Eastern Time (Pittsburgh); hover a time for UTC.', 'thirtydayhomes' ); ?></p>

			<nav class="nav-tab-wrapper tdh-logs-tabs" aria-label="<?php esc_attr_e( 'Log sections', 'thirtydayhomes' ); ?>">
				<a class="nav-tab <?php echo 'all' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $tab_url( 'all' ) ); ?>">
					<?php esc_html_e( 'All', 'thirtydayhomes' ); ?>
					<?php if ( array_sum( $errors ) > 0 ) : ?>
						<span class="tdh-logs-count"><?php echo esc_html( number_format_i18n( array_sum( $errors ) ) ); ?></span>
					<?php endif; ?>
				</a>
				<?php foreach ( $groups as $key => $group ) : ?>
					<?php $n = (int) array_sum( array_intersect_key( $errors, array_flip( $group['features'] ) ) ); ?>
					<a class="nav-tab <?php echo $on_tab( $key, $group ) ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $tab_url( $key ) ); ?>">
						<?php echo esc_html( $group['label'] ); ?>
						<?php if ( $n > 0 ) : ?>
							<span class="tdh-logs-count" title="<?php echo esc_attr( sprintf( /* translators: %s: number of errors */ _n( '%s error', '%s errors', $n, 'thirtydayhomes' ), number_format_i18n( $n ) ) ); ?>"><?php echo esc_html( number_format_i18n( $n ) ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
				<a class="nav-tab <?php echo 'status' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $tab_url( 'status' ) ); ?>"><?php esc_html_e( 'System status', 'thirtydayhomes' ); ?></a>
			</nav>

			<?php if ( 'status' === $tab ) : ?>
				<?php $this->status(); ?>
			<?php else : ?>
				<?php $this->lines( $tab, $scope, $level, $search, $days, $page, $since, $filtered, $lead, $groups, $periods, $tab_url, $on_tab ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The filter form, the table, the pages.
	 *
	 * @param string[]                                          $scope   Features this tab covers ([] = all).
	 * @param array<string,array{label:string,features:string[]}> $groups
	 * @param array<int,string>                                 $periods
	 */
	private function lines( string $tab, array $scope, string $level, string $search, int $days, int $page, int $since, bool $filtered, int $lead, array $groups, array $periods, callable $tab_url, callable $on_tab ): void {

		$result = Log::query(
			[
				'feature'   => $scope,
				'min_level' => 'all' === $level ? '' : $level,
				'search'    => $search,
				'since'     => $since,
				'page'      => $page,
			]
		);

		$section = 'all';
		foreach ( $groups as $key => $group ) {
			if ( $on_tab( $key, $group ) ) {
				$section = $key;
			}
		}
		?>
		<form class="tdh-logs-filters" method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
			<input type="hidden" name="post_type" value="tdh_listing">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">

			<?php // The tabs, as a select, for phones — hidden on a desk where the tabs are. ?>
			<label for="tdh-log-tab" class="tdh-logs-section"><?php esc_html_e( 'Section', 'thirtydayhomes' ); ?></label>
			<select id="tdh-log-tab" name="tab" class="tdh-logs-section">
				<option value="" <?php selected( $section, 'all' ); ?>><?php esc_html_e( 'All sections', 'thirtydayhomes' ); ?></option>
				<?php foreach ( $groups as $key => $group ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $section, $key ); ?>><?php echo esc_html( $group['label'] ); ?></option>
				<?php endforeach; ?>
			</select>

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

		<?php if ( $lead > 0 ) : ?>
			<p class="tdh-logs-lead">
				<?php
				printf(
					/* translators: 1: number of warnings and errors, 2: the period, e.g. "last 7 days" */
					esc_html( _n( '%1$s warning or error in the %2$s.', '%1$s warnings and errors in the %2$s.', $lead, 'thirtydayhomes' ) ),
					esc_html( number_format_i18n( $lead ) ),
					esc_html( mb_strtolower( $periods[ $days ] ) )
				);
				?>
				<a href="<?php echo esc_url( self::url( [ 'tab' => 'all' === $tab ? null : $tab, 'level' => 'all', 'days' => 7 !== $days ? $days : null, 'q' => '' !== $search ? $search : null ] ) ); ?>"><?php esc_html_e( 'Show everything', 'thirtydayhomes' ); ?></a>
			</p>
		<?php endif; ?>

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
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $result['rows'] as $row ) : ?>
					<?php $this->row( $row, Log::features() ); ?>
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
	 * underneath). The line itself opens its context (G5b) — a "View" link
	 * repeated fifty times in a column of its own was 200px of noise.
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

		// "Sign-in · admin · admin" said the same thing twice.
		$meta = implode( ' · ', array_filter( [ $feature, $who, $about !== $who ? $about : '' ] ) );
		$time = (int) $row['time'];
		$zone = self::zone();
		$abbr = self::ZONE === $zone->getName() ? __( 'ET', 'thirtydayhomes' ) : wp_date( 'T', $time, $zone );
		?>
		<tr class="is-<?php echo esc_attr( (string) $row['level'] ); ?>">
			<td class="is-time">
				<time datetime="<?php echo esc_attr( gmdate( 'c', $time ) ); ?>" title="<?php echo esc_attr( gmdate( 'Y-m-d H:i:s', $time ) . ' UTC' ); ?>"><?php echo esc_html( wp_date( 'j M, g:i A', $time, $zone ) . ' ' . $abbr ); ?></time>
			</td>
			<td class="is-level">
				<span class="tdh-logs-level is-<?php echo esc_attr( (string) $row['level'] ); ?>"><?php echo esc_html( $level_label ); ?></span>
			</td>
			<td class="is-message">
				<details>
					<summary>
						<strong><?php echo esc_html( self::words( (string) $row['message'] ) ); ?></strong>
						<span class="tdh-logs-meta"><?php echo esc_html( $meta ); ?></span>
						<span class="screen-reader-text"><?php esc_html_e( 'Show the details of this line', 'thirtydayhomes' ); ?></span>
					</summary>
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
	 * A stored status key inside a message, read as words: the message
	 * "Membership changed from active to past_due" was written for the
	 * record, and the record is read by people.
	 */
	public static function words( string $message ): string {

		$map = [
			'past_due'         => __( 'past due', 'thirtydayhomes' ),
			'tdh_billing_hold' => __( 'hidden (membership)', 'thirtydayhomes' ),
			'billing_hold'     => __( 'hidden (membership)', 'thirtydayhomes' ),
			'tdh_paused'       => __( 'paused', 'thirtydayhomes' ),
			'tdh_rejected'     => __( 'rejected', 'thirtydayhomes' ),
		];

		return (string) preg_replace_callback(
			'/\b(tdh_billing_hold|billing_hold|tdh_paused|tdh_rejected|past_due)\b/',
			static fn( array $m ): string => $map[ $m[1] ],
			$message
		);
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
	 * worded level and state chips, the filter row, the lead line, the
	 * context grid, and the phone layout. Scoped under .tdh-logs; nothing
	 * else is touched.
	 */
	public function print_styles(): void {
		?>
		<style>
			.tdh-logs .tdh-logs-tabs { margin-bottom: 16px; }
			.tdh-logs .nav-tab .tdh-logs-count { display: inline-block; min-width: 18px; margin-left: 4px; padding: 0 5px; border-radius: 9px; background: #d63638; color: #fff; font-size: 11px; font-weight: 600; line-height: 18px; text-align: center; }
			.tdh-logs .tdh-logs-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; margin: 0 0 12px; }
			.tdh-logs .tdh-logs-filters > label { font-weight: 600; }
			.tdh-logs .tdh-logs-filters > input[type="search"] { min-width: 16em; }
			.tdh-logs .tdh-logs-filters > .tdh-logs-section { display: none; }
			.tdh-logs .tdh-logs-lead { display: flex; flex-wrap: wrap; gap: 4px 10px; margin: 0 0 12px; padding: 10px 12px; background: #fcf9e8; border: 1px solid #f0e6c0; border-left: 4px solid #dba617; color: #1d2327; }
			.tdh-logs .tdh-logs-total { margin: 0 0 8px; color: #646970; }
			.tdh-logs .tdh-logs-table th.is-time, .tdh-logs .tdh-logs-table td.is-time { width: 10em; white-space: nowrap; color: #646970; font-variant-numeric: tabular-nums; }
			.tdh-logs .tdh-logs-table th.is-level, .tdh-logs .tdh-logs-table td.is-level { width: 7em; }
			.tdh-logs .tdh-logs-table td { vertical-align: top; }
			.tdh-logs .tdh-logs-table tr.is-warning > td:first-child { box-shadow: inset 4px 0 0 #dba617; }
			.tdh-logs .tdh-logs-table tr.is-error > td:first-child, .tdh-logs .tdh-logs-table tr.is-critical > td:first-child { box-shadow: inset 4px 0 0 #d63638; }
			.tdh-logs .tdh-logs-level, .tdh-logs .tdh-logs-state { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #f0f0f1; color: #1d2327; font-size: 12px; font-weight: 600; line-height: 16px; white-space: nowrap; }
			.tdh-logs .tdh-logs-level.is-warning, .tdh-logs .tdh-logs-state.is-pending { background: #fcf9e8; color: #8a6d00; }
			.tdh-logs .tdh-logs-level.is-error, .tdh-logs .tdh-logs-level.is-critical, .tdh-logs .tdh-logs-state.is-attention { background: #fcf0f1; color: #b32d2e; }
			.tdh-logs .tdh-logs-state.is-ok { background: #edfaef; color: #00700f; }
			.tdh-logs .tdh-logs-table td.is-message details > summary { position: relative; display: block; padding-right: 28px; cursor: pointer; list-style: none; }
			.tdh-logs .tdh-logs-table td.is-message details > summary::-webkit-details-marker { display: none; }
			.tdh-logs .tdh-logs-table td.is-message details > summary::after { content: ""; position: absolute; top: 6px; right: 8px; width: 7px; height: 7px; border-right: 2px solid #2271b1; border-bottom: 2px solid #2271b1; transform: rotate( 45deg ); transition: transform 120ms ease; }
			.tdh-logs .tdh-logs-table td.is-message details[open] > summary::after { transform: rotate( 225deg ); top: 10px; }
			.tdh-logs .tdh-logs-table td.is-message details > summary:hover > strong { color: #2271b1; }
			.tdh-logs .tdh-logs-table td.is-message details > summary:focus-visible { outline: 2px solid #2271b1; outline-offset: 2px; }
			.tdh-logs .tdh-logs-table td.is-message details > summary > strong { display: block; overflow-wrap: anywhere; }
			.tdh-logs .tdh-logs-table td.is-message details > summary > .tdh-logs-meta { display: block; margin-top: 2px; color: #646970; font-size: 12px; overflow-wrap: anywhere; }
			.tdh-logs .tdh-logs-table details > dl { display: grid; grid-template-columns: max-content minmax( 0, 1fr ); gap: 4px 12px; margin: 8px 0 0; padding: 10px 12px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; font-size: 12px; }
			.tdh-logs .tdh-logs-table details > dl > dt { color: #646970; }
			.tdh-logs .tdh-logs-table details > dl > dd { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; font-family: Consolas, Monaco, monospace; }
			.tdh-logs .tdh-logs-empty { margin: 24px 0; padding: 32px 16px; background: #fff; border: 1px solid #c3c4c7; text-align: center; }
			.tdh-logs .tdh-logs-empty > h2 { margin: 0 0 6px; }
			.tdh-logs .tdh-logs-empty > p { margin: 0; color: #646970; }
			.tdh-logs .tdh-logs-status td { vertical-align: top; }
			@media ( prefers-reduced-motion: reduce ) {
				.tdh-logs .tdh-logs-table td.is-message details > summary::after { transition: none; }
			}
			@media ( max-width: 782px ) {
				.tdh-logs .tdh-logs-tabs { display: none; }
				.tdh-logs .tdh-logs-filters > .tdh-logs-section { display: block; }
				.tdh-logs .tdh-logs-filters > select, .tdh-logs .tdh-logs-filters > input[type="search"] { flex: 1 1 100%; min-width: 0; }
				.tdh-logs .tdh-logs-filters > label { flex: 1 1 100%; margin-bottom: -4px; }
				.tdh-logs .tdh-logs-filters > .button { flex: 1; text-align: center; }
				.tdh-logs .tdh-logs-table thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect( 0 0 0 0 ); }
				.tdh-logs .tdh-logs-table tr { display: grid; grid-template-columns: minmax( 0, 1fr ) auto; gap: 4px 12px; padding: 10px 8px; }
				.tdh-logs .tdh-logs-table td { display: block; width: auto !important; padding: 0; border: 0; }
				.tdh-logs .tdh-logs-table tr.is-warning > td:first-child, .tdh-logs .tdh-logs-table tr.is-error > td:first-child, .tdh-logs .tdh-logs-table tr.is-critical > td:first-child { box-shadow: none; }
				.tdh-logs .tdh-logs-table tr.is-warning { box-shadow: inset 4px 0 0 #dba617; }
				.tdh-logs .tdh-logs-table tr.is-error, .tdh-logs .tdh-logs-table tr.is-critical { box-shadow: inset 4px 0 0 #d63638; }
				.tdh-logs .tdh-logs-table td.is-time { grid-area: 1 / 1; }
				.tdh-logs .tdh-logs-table td.is-level { grid-area: 1 / 2; justify-self: end; }
				.tdh-logs .tdh-logs-table td.is-message { grid-column: 1 / -1; }
				.tdh-logs .tdh-logs-table details > dl { grid-template-columns: minmax( 0, 1fr ); }
			}
		</style>
		<?php
	}
}
