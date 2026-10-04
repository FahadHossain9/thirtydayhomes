<?php
/**
 * The event and failure log: writing and reading, what a row may never
 * carry, the features, filters and paging, retention, the lines the other
 * modules produce, the wp-admin screen, and who may see it.
 *
 * Every row this suite writes is removed at the end.
 *
 * Run through verify.bat, or directly:
 *     wp eval-file wp-content/plugins/thirtydayhomes-core/tools/verify-log.php
 */

use TDH\Account_Render;
use TDH\Activator;
use TDH\Admin\Log_Screen;
use TDH\Log;
use TDH\Post_Types;

function ok( string $label = '', bool $cond = false, string $detail = '' ): array {
	static $p = 0, $f = 0;
	if ( '' === $label ) { return [ $p, $f ]; }
	if ( $cond ) { ++$p; printf( "  ok    %s\n", $label ); }
	else { ++$f; printf( "  FAIL  %s%s\n", $label, '' !== $detail ? "  --> {$detail}" : '' ); }
	return [ $p, $f ];
}

global $wpdb;

// verify.bat and CI silence the log for every suite; this one is about the
// log, so it switches it back on for itself.
putenv( 'TDH_LOG_SILENT' );

// The table exists on any site that activated or upgraded the plugin; a
// suite run before that has happened makes it itself.
Activator::install_tables();

$table   = Log::table();
$floor   = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$table}" ); // phpcs:ignore
$created = [];
$raw     = static fn( int $id ) => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore

echo "\n=== writing ===\n";

$id = Log::info( 'listings', 'probe', 'Log probe: a home was approved.', [ 'home' => 'Probe House' ], 4242, 'listing' );
ok( 'a line is written and gets an id', $id > $floor, (string) $id );

$row = $raw( $id );
ok( '...with its level, feature, event, message and object', 'info' === $row['level'] && 'listings' === $row['feature'] && 'probe' === $row['event'] && 4242 === (int) $row['object_id'] && 'listing' === $row['object_type'] );
ok( '...its time in UTC', abs( strtotime( $row['created_at'] . ' UTC' ) - time() ) < 10 );
ok( '...where it was written from, as place:function, never the log\'s own file', '' !== (string) $row['source'] && str_contains( (string) $row['source'], ':' ) && ! str_contains( (string) $row['source'], 'class-tdh-log.php' ), (string) $row['source'] );
ok( '...and a request id shared by every line of this run', '' !== $row['request_id'] && $row['request_id'] === $raw( Log::info( 'listings', 'probe', 'Log probe: second line.' ) )['request_id'] );

ok( 'an unknown level becomes info', 'info' === $raw( Log::write( 'shout', 'listings', 'probe', 'Log probe: shouted.' ) )['level'] );
ok( 'an unknown feature lands in System', 'system' === $raw( Log::write( Log::INFO, 'nowhere', 'probe', 'Log probe: lost.' ) )['feature'] );

$long = 'Log probe: ' . str_repeat( 'a very long sentence ', 20 );
$row  = $raw( Log::info( 'listings', 'probe', $long ) );
ok( 'a message longer than the column is cut, and kept whole in the context', mb_strlen( $row['message'] ) <= 255 && str_contains( (string) $row['context'], 'message_full' ) );

putenv( 'TDH_LOG_SILENT=1' );
$silent = Log::info( 'listings', 'probe', 'Log probe: should not be written.' );
putenv( 'TDH_LOG_SILENT' );
ok( 'with TDH_LOG_SILENT set nothing is written (how the other suites run)', 0 === $silent && null === $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE message = %s", 'Log probe: should not be written.' ) ) ); // phpcs:ignore

echo "\n=== what a row may never carry ===\n";

$row = $raw(
	Log::info(
		'payments',
		'probe',
		'Log probe: secrets.',
		[
			'api_key'   => 'sk_live_abcdefghijklmnop',
			'password'  => 'hunter2',
			// Shaped like a Google key so the masking rule fires, but not a
			// real one. The client's actual key sat here until 21 Sep 2026,
			// which put a live secret in the repository and in its history.
			// Test data is invented; it is never copied from production.
			'note'      => 'AIzaSyEXAMPLEEXAMPLEEXAMPLEEXAMPLE12345',
			'email'     => 'fahad@example.com',
			'phone'     => '+1 412 555 1234',
			'login'     => 'someone@example.org',
			'safe'      => 'kept as is',
			'nested'    => [ 'token' => 'abc', 'depth' => [ 'ok' => true ] ],
			'object'    => new stdClass(),
			'wp_error'  => new WP_Error( 'x', 'the error text' ),
		]
	)
);
$context = json_decode( (string) $row['context'], true );

ok( 'a key that looks like a secret is hidden', '[hidden]' === $context['api_key'] && '[hidden]' === $context['password'] );
ok( 'a value shaped like an API key is hidden whatever its key', '[hidden]' === $context['note'] );
ok( 'an email is masked', 'f***@example.com' === $context['email'], (string) $context['email'] );
ok( 'a phone keeps its last four digits only', '***1234' === $context['phone'] );
ok( 'a login that is an email is masked too', 's***@example.org' === $context['login'] );
ok( 'ordinary values are kept', 'kept as is' === $context['safe'] );
ok( 'nested arrays are cleaned the same way', '[hidden]' === $context['nested']['token'] && true === $context['nested']['depth']['ok'] );
ok( 'an object becomes a name, a WP_Error its message — never a fatal', '[stdClass]' === $context['object'] && 'the error text' === $context['wp_error'] );
ok( 'the raw secret is nowhere in the row', ! str_contains( wp_json_encode( $row ), 'hunter2' ) && ! str_contains( wp_json_encode( $row ), 'sk_live_' ) );

ok( 'mask_email handles junk', '***' === Log::mask_email( 'not-an-email' ) && '' === Log::mask_email( '' ) );
ok( 'mask_phone handles junk', '***' === Log::mask_phone( 'call me' ) && '' === Log::mask_phone( '' ) );

echo "\n=== features and levels ===\n";

$features = Log::features();
ok( 'the features are the tabs staff expect', isset( $features['listings'], $features['locations'], $features['email'], $features['payments'], $features['signin'], $features['system'] ) );
ok( '...in words', 'Sign-in' === $features['signin'] && 'Locations' === $features['locations'] );
ok( 'levels rank in order of seriousness', Log::rank( Log::DEBUG ) < Log::rank( Log::INFO ) && Log::rank( Log::WARNING ) < Log::rank( Log::ERROR ) && Log::rank( Log::ERROR ) < Log::rank( Log::CRITICAL ) );

$filter = static function ( array $f ): array {
	$f['probe'] = 'Probe';
	return $f;
};
add_filter( 'tdh_log_features', $filter );
ok( 'a feature can be added by filter', 'Probe' === ( Log::features()['probe'] ?? '' ) );
remove_filter( 'tdh_log_features', $filter );

echo "\n=== reading: filters and pages ===\n";

// Counted as differences: the site may already hold real errors today.
$base_counts = Log::counts( Log::ERROR, time() - HOUR_IN_SECONDS );

Log::warning( 'locations', 'probe', 'Log probe: address not found for Probe House.' );
Log::error( 'email', 'probe', 'Log probe: an email failed.' );
Log::critical( 'payments', 'probe', 'Log probe: a webhook was refused.' );

$mine = static fn( array $args = [] ): array => array_values( array_filter( Log::query( $args + [ 'per_page' => 200 ] )['rows'], static fn( $r ) => $r['id'] > $floor ) );

ok( 'newest first', $mine()[0]['id'] > $mine()[1]['id'] );
ok( 'by feature', [] === array_filter( $mine( [ 'feature' => 'email' ] ), static fn( $r ) => 'email' !== $r['feature'] ) && count( $mine( [ 'feature' => 'email' ] ) ) === 1 );
ok( 'warnings and up leaves info out', [] === array_filter( $mine( [ 'min_level' => 'warning' ] ), static fn( $r ) => 'info' === $r['level'] ) && count( $mine( [ 'min_level' => 'warning' ] ) ) === 3 );
ok( 'errors only leaves warnings out', 2 === count( $mine( [ 'min_level' => 'error' ] ) ) );
ok( 'by words in the message', 1 === count( $mine( [ 'search' => 'webhook was refused' ] ) ) );
ok( 'a search that matches nothing returns nothing, not everything', [] === $mine( [ 'search' => 'zzz-no-such-text-zzz' ] ) );
ok( 'by object', 1 === count( $mine( [ 'object_id' => 4242, 'object_type' => 'listing' ] ) ) );

$paged = Log::query( [ 'per_page' => 2, 'page' => 1 ] );
ok( 'pages are counted from the total', 2 === count( $paged['rows'] ) && $paged['pages'] >= 4 && $paged['total'] >= 8 );
$last = Log::query( [ 'per_page' => 2, 'page' => 9999 ] );
ok( 'a page past the end is the last page, not empty', count( $last['rows'] ) >= 1 && $last['page'] === $last['pages'] );

$counts = Log::counts( Log::ERROR, time() - HOUR_IN_SECONDS );
ok(
	'error counts per feature, for the badges',
	( $counts['email'] ?? 0 ) - ( $base_counts['email'] ?? 0 ) === 1
		&& ( $counts['payments'] ?? 0 ) - ( $base_counts['payments'] ?? 0 ) === 1
		&& ( $counts['locations'] ?? 0 ) === ( $base_counts['locations'] ?? 0 ),
	wp_json_encode( [ 'before' => $base_counts, 'after' => $counts ] )
);
ok( 'recent errors for the menu bubble', Log::recent_errors() >= 2 );
ok( 'the latest line of a feature', 'Log probe: an email failed.' === ( Log::latest( 'email' )['message'] ?? '' ) );
ok( '...and of an event', 'Log probe: a webhook was refused.' === ( Log::latest( 'payments', 'probe' )['message'] ?? '' ) );

echo "\n=== retention ===\n";

$old_id = Log::info( 'system', 'probe', 'Log probe: an old line.' );
$wpdb->update( $table, [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( Log::RETENTION_DAYS + 5 ) * DAY_IN_SECONDS ) ], [ 'id' => $old_id ] ); // phpcs:ignore
$recent_id = Log::info( 'system', 'probe', 'Log probe: a recent line.' );

$removed = ( new Log() )->purge();
ok( 'the purge removes lines older than the retention period', $removed >= 1 && null === $raw( $old_id ) );
ok( '...and keeps recent ones', null !== $raw( $recent_id ) );
ok( '...and says what it did, in the log', str_contains( (string) ( Log::latest( 'system', 'purged' )['message'] ?? '' ), 'older than 90 days' ) );
ok( '...and remembers when', is_array( get_option( Log::OPTION_PURGED ) ) && time() - (int) get_option( Log::OPTION_PURGED )['at'] < 10 );

( new Log() )->schedule();
ok( 'a daily clean-up is scheduled', (bool) wp_next_scheduled( Log::CRON ) );

echo "\n=== the lines the site produces ===\n";

$before = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$table}" ); // phpcs:ignore
$since  = static fn( string $feature, string $event ) => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d AND feature = %s AND event = %s ORDER BY id DESC LIMIT 1", $before, $feature, $event ), ARRAY_A ); // phpcs:ignore

$home = wp_insert_post( [ 'post_type' => Post_Types::LISTING, 'post_status' => 'pending', 'post_title' => 'Log Probe Loft' ] );
$created[] = (int) $home;

do_action( 'tdh_listing_approved', $home );
$row = $since( 'listings', 'approved' );
ok( 'approving a home writes its line, naming the home', $row && str_contains( $row['message'], 'Log Probe Loft' ) && (int) $row['object_id'] === (int) $home );

do_action( 'tdh_listing_paused', $home );
ok( 'pausing writes its line', (bool) $since( 'listings', 'paused' ) );

do_action( 'tdh_listing_changes_submitted', $home, [ 'Title', 'Photos' ], false );
$row = $since( 'listings', 'edit_to_review' );
ok( 'a live edit that went back to review names the fields', $row && str_contains( $row['message'], 'Title, Photos' ) );

do_action( 'wp_login_failed', 'someone@example.com', new WP_Error( 'incorrect_password', 'wrong' ) );
$row = $since( 'signin', 'refused' );
ok( 'a refused sign-in is a warning with the login masked', $row && 'warning' === $row['level'] && str_contains( (string) $row['context'], 's***@example.com' ) && ! str_contains( (string) $row['context'], 'someone@' ) );

do_action( 'tdh_login_throttled', 'someone@example.com' );
ok( 'a throttled sign-in is an error', 'error' === ( $since( 'signin', 'throttled' )['level'] ?? '' ) );

do_action( 'tdh_geocoded', $home, 'failed', 'not_found', true, 'changed' );
$row = $since( 'locations', 'not_found' );
ok( 'an address that was not found is a warning naming the home', $row && 'warning' === $row['level'] && str_contains( $row['message'], 'Log Probe Loft' ) );

do_action( 'tdh_geocoded', $home, 'found', '', true, 'changed' );
ok( 'a found address is an info line', 'info' === ( $since( 'locations', 'found' )['level'] ?? '' ) );

do_action( 'tdh_geocoded', $home, 'found', '', false, 'retry' );
ok( 'a save that asked nothing writes nothing', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id > %d AND feature = 'locations' AND event = 'found'", $before ) ) ); // phpcs:ignore

do_action( 'tdh_geocoded', $home, 'pending', 'denied', true, 'changed' );
ok( 'a refused key is an error', 'error' === ( $since( 'locations', 'no_answer' )['level'] ?? '' ) );

do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP connect() failed', [ 'to' => [ 'rob@example.com' ], 'subject' => 'Your home is live' ] ) );
$row = $since( 'email', 'failed' );
ok( 'a failed email is an error with the recipient masked and the reason kept', $row && 'error' === $row['level'] && str_contains( (string) $row['context'], 'r***@example.com' ) && str_contains( (string) $row['context'], 'SMTP connect' ) );

do_action( 'tdh_mail_captured', '/tmp/x.eml', [ 'to' => 'rob@example.com', 'subject' => 'Captured' ] );
ok( 'a captured email is recorded as captured, not sent', (bool) $since( 'email', 'captured' ) );

do_action( 'tdh_stripe_rejected', 'bad_signature', 'test' );
$row = $since( 'payments', 'rejected' );
ok( 'a refused Stripe webhook is an error that says to check the secret', $row && 'error' === $row['level'] && str_contains( $row['message'], 'webhook secret' ) );

do_action( 'tdh_stripe_rejected', 'duplicate', 'test' );
ok( 'a duplicate event is only information', 'info' === ( $since( 'payments', 'rejected' )['level'] ?? '' ) );

do_action( 'tdh_stripe_received', 'invoice.paid', 'evt_123', 'test' );
ok( 'a received event names its type', str_contains( (string) ( $since( 'payments', 'received' )['message'] ?? '' ), 'invoice.paid' ) );

do_action( 'tdh_membership_changed', 1, [ 'status' => 'past_due', 'quota' => 3 ], [ 'status' => 'active', 'quota' => 3 ] );
$row = $since( 'payments', 'membership' );
ok( 'a membership going past due is a warning that says from what to what', $row && 'warning' === $row['level'] && str_contains( $row['message'], 'from active to past_due' ) );

do_action( 'tdh_proximity_settings_saved', 2, 8.0 );
ok( 'a settings change is recorded', str_contains( (string) ( $since( 'settings', 'proximity' )['message'] ?? '' ), '2 facilities within 8 miles' ) );

echo "\n=== the wp-admin screen ===\n";

$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0] ?? null;

if ( $admin ) {
	wp_set_current_user( $admin->ID );

	$screen = new Log_Screen();
	$render = static function ( array $get ) use ( $screen ): string {
		$_GET = $get;
		ob_start();
		$screen->render();
		$_GET = [];
		return (string) ob_get_clean();
	};

	ok( 'an administrator may read it', Log_Screen::allowed() );
	ok( 'it lives under Listings in wp-admin, beside Email delivery and Payments', str_contains( Log_Screen::url(), 'edit.php?post_type=tdh_listing' ) && str_contains( Log_Screen::url(), 'page=tdh-logs' ) );

	$page = $render( [] );
	ok( 'the screen has a tab per group and System status', str_contains( $page, 'System status' ) && preg_match( '/>\s*Homes &amp; locations\s*</', $page ) && preg_match( '/>\s*Sign-in\s*</', $page ) );
	ok( 'the tabs carry the error count', (bool) preg_match( '/<span class="tdh-logs-count"[^>]*>\d+<\/span>/', $page ) );
	ok( 'the newest lines are shown with their level in words', str_contains( $page, 'Log Probe Loft' ) && str_contains( $page, '>Warning<' ) && str_contains( $page, '>Error<' ) );
	ok( 'each line has a View that opens its context', str_contains( $page, '<details>' ) && str_contains( $page, 'r***@example.com' ) );
	ok( 'the raw email never reaches the page', ! str_contains( $page, 'rob@example.com' ) && ! str_contains( $page, 'someone@example.com' ) );
	ok( 'the time is shown in the site timezone with the raw UTC on hover', (bool) preg_match( '/<time datetime="[^"]+" title="[^"]+ UTC"/', $page ) );

	echo "\n=== G5b: the Logs screen, design review ===\n";

	preg_match_all( '/class="nav-tab[ "]/', $page, $tabs );
	ok( 'G5b: at most eight tabs — All, six groups, System status (plus one per feature added by filter)', count( $tabs[0] ) <= 9 && str_contains( $page, '>Messages' ) && str_contains( $page, 'Members &amp; payments' ) );
	ok( 'G5b: warnings lead when the period has any, and the line says so with a way to everything', str_contains( $page, 'tdh-logs-lead' ) && str_contains( $page, 'Show everything' ) && ! str_contains( $page, 'approved and live' ) );
	ok( 'G5b: no Details column — the line itself opens its context', ! preg_match( '/<th[^>]*>\s*Details\s*<\/th>/', $page ) && (bool) preg_match( '/<td class="is-message">\s*<details>\s*<summary>/', $page ) );
	ok( 'G5b: times in Eastern Time, named', (bool) preg_match( '/<time [^>]*>[^<]* (ET|EDT|EST)<\/time>/', $page ) );
	ok( 'G5b: a stored status key reads as words on screen', str_contains( $page, 'from active to past due' ) && ! preg_match( '/<strong>[^<]*past_due[^<]*<\/strong>/', $page ) );
	ok( 'G5b: the groups are a Section select for phones', str_contains( $page, 'id="tdh-log-tab"' ) && str_contains( $page, '>All sections<' ) );
	ok( 'G5b: warning and error rows are marked on the row too', (bool) preg_match( '/<tr class="is-(warning|error)">/', $page ) );

	$page = $render( [ 'level' => 'all' ] );
	ok( 'G5b: ?level=all shows the information lines again, without the lead line', str_contains( $page, 'approved and live' ) && ! str_contains( $page, 'tdh-logs-lead' ) );

	$page = $render( [ 'tab' => 'messages' ] );
	ok( 'G5b: a group tab shows its features together and nothing else', str_contains( $page, 'Log probe: an email failed.' ) && ! str_contains( $page, 'address not found' ) );

	$page = $render( [ 'tab' => 'email' ] );
	// The approval line lives under Listings only. (The home's NAME may still
	// appear on the Email tab — the staff email about it was captured and
	// logged there, which is the log working.)
	ok( 'a feature tab shows only that feature', str_contains( $page, 'Log probe: an email failed.' ) && ! str_contains( $page, 'approved and live' ) && ! preg_match( '/tdh-logs-meta">Listings ·/', $page ) );

	$page = $render( [ 'level' => 'error' ] );
	ok( '"Errors only" hides warnings', str_contains( $page, 'Log probe: a webhook was refused.' ) && ! str_contains( $page, 'Log probe: address not found' ) );

	$page = $render( [ 'q' => 'zzz-no-such-text-zzz' ] );
	ok( 'no match says so and offers to clear the filters', str_contains( $page, 'Nothing logged for these filters' ) && str_contains( $page, 'Clear filters' ) && ! str_contains( $page, '<table' ) );

	$page = $render( [ 'tab' => 'nonsense' ] );
	ok( 'an unknown tab falls back to All, not an error', str_contains( $page, 'Log Probe Loft' ) );

	$page = $render( [ 'tab' => 'status' ] );
	ok( 'System status lists the services in words', str_contains( $page, 'Google Maps key' ) && str_contains( $page, 'Email' ) && str_contains( $page, 'Text messages (SMS)' ) && str_contains( $page, 'Stripe webhook' ) && str_contains( $page, 'This log' ) );
	ok( '...each with a worded state, never a bare colour', (bool) preg_match( '/<span class="tdh-logs-state is-[a-z]+">(Working|Needs attention|Not set up|Not yet)<\/span>/', $page ) );
	ok( '...and SMS no longer says it is not built (it once said so for good)', ! str_contains( $page, 'Not built yet' ) );

	// The SMS row reads the same settings as the landlord's card.
	$sms_row = static fn(): array => array_values( array_filter( Log::status(), static fn( array $r ): bool => 'Text messages (SMS)' === $r['label'] ) )[0] ?? [ 'state' => '', 'detail' => '' ];

	if ( \TDH\Sms::is_enabled() ) {
		ok( 'SMS on a local site: written to a file, nothing sent', 'pending' === $sms_row()['state'] && str_contains( $sms_row()['detail'], 'Written to a file' ) );

		$twilio = static fn() => new \TDH\Sms\Twilio();
		$test   = static fn(): array => [ '+14125550100' ];
		add_filter( 'tdh_sms_provider', $twilio );
		add_filter( 'tdh_sms_test_recipients', $test );
		ok( 'SMS with Twilio in test mode names the test number', 'ok' === $sms_row()['state'] && str_contains( $sms_row()['detail'], 'Test mode: only (412) 555-0100 is texted.' ) );

		Log::error( 'sms', 'sms_code_failed', 'Log probe: Twilio refused the text.' );
		ok( '...a newer failure needs attention and says what went wrong', 'attention' === $sms_row()['state'] && str_contains( $sms_row()['detail'], 'Log probe: Twilio refused the text.' ) );

		Log::info( 'sms', 'sms_sent', 'Log probe: a text went out.' );
		ok( '...and a text sent after it is Working again, with the time', 'ok' === $sms_row()['state'] && str_contains( $sms_row()['detail'], 'Last text sent' ) );

		remove_filter( 'tdh_sms_test_recipients', $test );
		add_filter( 'tdh_sms_test_recipients', '__return_empty_array' );
		ok( 'SMS live says every landlord is texted', str_contains( $sms_row()['detail'], 'Live: every landlord' ) );
		remove_filter( 'tdh_sms_test_recipients', '__return_empty_array' );
		remove_filter( 'tdh_sms_provider', $twilio );
	} else {
		ok( 'SMS switched off says so', 'off' === $sms_row()['state'] && str_contains( $sms_row()['detail'], 'Switched off' ) );
	}

	// The client's portal knows nothing of it.
	$_GET   = [ 'view' => 'logs' ];
	$portal = Account_Render::dashboard();
	$_GET   = [];
	ok( 'the marketplace portal has no Logs view or link', ! str_contains( $portal, 'view=logs' ) && ! str_contains( $portal, 'System status' ) );

	// A landlord may not read it.
	$landlord = wp_insert_user( [ 'user_login' => 'tdh_log_landlord', 'user_email' => 'tdh_log_landlord@example.com', 'user_pass' => wp_generate_password( 20 ), 'role' => 'tdh_landlord' ] );
	if ( ! is_wp_error( $landlord ) ) {
		wp_set_current_user( (int) $landlord );
		ok( 'a landlord may not read it', ! Log_Screen::allowed() );
		wp_set_current_user( $admin->ID );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( (int) $landlord );
	}
} else {
	echo "  (no administrator on this site — screen checks skipped)\n";
}

echo "\n=== cleanup ===\n";

foreach ( $created as $post_id ) {
	wp_delete_post( $post_id, true );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id > %d", $floor ) ); // phpcs:ignore
delete_transient( Log::TRANSIENT_FAILED );
$_GET = [];
wp_set_current_user( 0 );
echo "  fixtures removed\n";

[ $passed, $failed ] = ok();
printf( "\n%s  %d passed, %d failed\n", $failed ? 'FAILED' : 'PASSED', $passed, $failed );
if ( $failed && defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::halt( 1 );
}
