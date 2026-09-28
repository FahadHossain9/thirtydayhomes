<?php
/**
 * Telling the landlord an inquiry arrived — verification suite.
 *
 * Run it through verify.bat, which finds the binaries for you, or directly:
 *
 *   D:\xampp\php\php.exe D:\xampp\wp-cli.phar eval-file `
 *     "wp-content/plugins/thirtydayhomes-core/tools/verify-notifications.php"
 *
 * Safe on a populated site. Every home, user, inquiry and notification row
 * it creates is deleted at the end, and NOTHING LEAVES THE MACHINE: every
 * send in here is intercepted on `pre_wp_mail` before the mail layer is
 * reached, so a suite run on a box with working SMTP still cannot post a
 * message to a real landlord.
 *
 * @package ThirtyDayHomes
 */

use TDH\Accounts;
use TDH\Inquiry;
use TDH\Mail;
use TDH\Notifications;
use TDH\Post_Types;

/**
 * Record one assertion and hand back the running tally.
 *
 * Static, not global: under `wp eval-file` the script body is not the global
 * scope, so `global $pass` inside a function reaches a different variable
 * entirely and every tally comes out zero.
 *
 * @return array{0:int,1:int} passed, failed
 */
function ok( string $label = '', bool $condition = false, string $detail = '' ): array {

	static $pass = 0;
	static $fail = 0;

	if ( '' === $label ) {
		return [ $pass, $fail ];
	}

	if ( $condition ) {
		++$pass;
		printf( "  ok    %s\n", $label );
	} else {
		++$fail;
		printf( "  FAIL  %s%s\n", $label, '' !== $detail ? "  --> {$detail}" : '' );
	}

	return [ $pass, $fail ];
}

/* -------------------------------------------------------------------------
 * The mail interceptor
 *
 * One filter for the whole suite, switched between "accept" and "refuse"
 * by a variable. Registering and removing a filter per test left one behind
 * once and the next section sent for real.
 * ---------------------------------------------------------------------- */

$mail_state = 'accept';

/**
 * Every message the suite intercepted since the last reset.
 *
 * Static, not global — for the same reason ok() is. Under `wp eval-file`
 * the script body is not the global scope, so `global $sent` inside a
 * function reaches a different variable entirely: the first version of this
 * reset one array while the filter went on appending to another, and every
 * count came out one higher than the last.
 *
 * @param array<string,mixed>|null $atts  A message to record.
 * @param bool                     $reset Forget everything recorded so far.
 * @return array<int,array<string,mixed>>
 */
function mail_log( ?array $atts = null, bool $reset = false ): array {

	static $sent = [];

	if ( $reset ) {
		$sent = [];

		return $sent;
	}

	if ( null !== $atts ) {
		$sent[] = $atts;
	}

	return $sent;
}

add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) use ( &$mail_state ) {

		if ( 'through' === $mail_state ) {
			// Let Mail::capture() have it, so the real capture file is
			// exercised at least once — the card asks for that by name.
			return $short;
		}

		mail_log( (array) $atts );

		if ( 'refuse' === $mail_state ) {
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP connect() failed (550 relay denied)' ) );

			return false;
		}

		/* Only the second address fails: the landlord gets theirs. */
		if ( 'refuse_copy' === $mail_state ) {
			$to = (array) ( $atts['to'] ?? [] );

			return 'office@example.com' !== ( $to[0] ?? '' );
		}

		return true;
	},
	1,
	2
);

/** Forget what has been sent so far. */
function sent_reset(): void {
	mail_log( null, true );
}

/** How many messages have been intercepted since the last reset. */
function sent_count(): int {
	return count( mail_log() );
}

/** One intercepted message, or []. */
function sent_at( int $index ): array {
	return (array) ( mail_log()[ $index ] ?? [] );
}

/** The first recipient of an intercepted message. */
function sent_to( int $index ): string {

	$to = sent_at( $index )['to'] ?? '';

	return is_array( $to ) ? (string) ( $to[0] ?? '' ) : (string) $to;
}

/**
 * Do what WordPress's cron spawn does, for ONE row.
 *
 * on_inquiry() BOOKS the send rather than making it, so the renter's page
 * returns without waiting on a mail server. Nothing runs a due event inside
 * a CLI test, so the suite has to be the thing that collects it — and doing
 * it this way means the tests still drive the real path rather than a
 * special "send inline for tests" branch that production never takes.
 *
 * One row at a time, deliberately. Running every due event swept up rows
 * this suite never made — on a developer's machine that meant twenty other
 * fixtures going out mid-test and every count coming back nonsense, and on
 * a real site it would mean a verification run posting somebody's genuinely
 * pending email.
 *
 * @return int How many sends were run: 1 when the row had one booked.
 */
function run_due_send( int $row_id ): int {

	$ran = 0;

	foreach ( (array) _get_cron_array() as $at => $events ) {

		if ( $at > time() ) {
			continue;
		}

		foreach ( (array) ( $events['tdh_notification_retry'] ?? [] ) as $event ) {

			$args = (array) ( $event['args'] ?? [] );

			if ( $row_id !== (int) ( $args[0] ?? 0 ) ) {
				continue;
			}

			wp_unschedule_event( (int) $at, 'tdh_notification_retry', $args );
			Notifications::send( $row_id );
			++$ran;
		}
	}

	return $ran;
}

/** The booked send for whichever email row belongs to this inquiry. */
function run_due_sends( int $inquiry_id ): int {

	$row = email_row( $inquiry_id );

	return $row ? run_due_send( (int) $row['id'] ) : 0;
}

/**
 * Keep the sweep to this suite's own rows for the duration of a test.
 *
 * sweep() takes any due email row, five at a time, which is exactly right
 * in production and useless in a test: on a populated site the row under
 * test might not be in those five at all, and somebody's real pending
 * email would go out. Everything else sweepable is dated forward for the
 * duration and put back afterwards.
 *
 * @return array<int,string> The ids and timestamps to restore.
 */
function park_other_rows( int $keep ): array {

	global $wpdb;

	$table = Notifications::table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, updated_at FROM {$table} WHERE id <> %d AND status IN ( %s, %s )",
			$keep,
			Notifications::QUEUED,
			Notifications::FAILED
		),
		ARRAY_A
	);

	$parked = [];

	foreach ( $rows as $row ) {
		$parked[ (int) $row['id'] ] = (string) $row['updated_at'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $table, [ 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => (int) $row['id'] ], [ '%s' ], [ '%d' ] );
	}

	return $parked;
}

/** Put back what park_other_rows() moved. */
function unpark_rows( array $parked ): void {

	global $wpdb;

	foreach ( $parked as $id => $when ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Notifications::table(), [ 'updated_at' => $when ], [ 'id' => (int) $id ], [ '%s' ], [ '%d' ] );
	}
}

/** Is a send booked for this row? */
function is_booked( int $row_id ): bool {
	return (bool) wp_next_scheduled( 'tdh_notification_retry', [ $row_id ] );
}

/** The rows written for one inquiry's email. */
function email_row( int $inquiry_id ): ?array {

	foreach ( Notifications::for_inquiry( $inquiry_id ) as $row ) {
		if ( Notifications::CHANNEL_EMAIL === $row['channel'] ) {
			return $row;
		}
	}

	return null;
}

/** Make a home owned by somebody, and an inquiry about it. */
function probe_home( string $title, int $owner ): int {

	return (int) wp_insert_post(
		[
			'post_type'   => Post_Types::LISTING,
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_author' => $owner,
		]
	);
}

function probe_inquiry( int $home, array $over = [] ): int {

	return Inquiry::store(
		$home,
		(string) ( $over['email'] ?? 'renter@example.com' ),
		array_merge(
			[
				'name'    => 'Nora Blake',
				'phone'   => '412-555-0186',
				'move_in' => '2026-12-01',
				'stay'    => '30',
				'message' => 'Is there a lift in the building?',
			],
			$over
		)
	);
}

/**
 * Run a handler that ends in exit, and report where it redirected.
 *
 * bounce() ends in exit, which no test survives — but it redirects first,
 * and wp_redirect runs its filter before sending a header. Throwing from
 * that filter escapes the handler with the location intact.
 */
function ran( Notifications $notifications ): string {

	$catch = static function ( $location ) {
		throw new RuntimeException( (string) $location );
	};

	add_filter( 'wp_redirect', $catch, 1 );

	try {
		$notifications->handle_resend();
		$where = '(no redirect)';
	} catch ( RuntimeException $e ) {
		$where = $e->getMessage();
	} finally {
		remove_filter( 'wp_redirect', $catch, 1 );
	}

	return $where;
}

/** The `send` flag a redirect carried. */
function outcome( string $url ): string {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );

	return (string) ( $args[ Notifications::PARAM_DONE ] ?? '' );
}

$notifications = new Notifications();

/*
 * What the Email delivery screen is showing before this suite starts.
 *
 * TDH\Smtp listens to wp_mail_failed and keeps the last failure so staff
 * can read it. This suite fires that action a dozen times on purpose, so
 * without putting these back a clean verification run ends with "Last
 * failure: SMTP connect() failed (550 relay denied), 6 minutes ago" on a
 * staff screen — a false alarm about a mail server that is perfectly well.
 */
$smtp_error_before = get_option( TDH\Smtp::OPTION_LAST_ERROR );
$smtp_sent_before  = get_option( TDH\Smtp::OPTION_LAST_SENT );

echo "\n=== fixtures ===\n";

/*
 * Clear anything an earlier run left behind before making it again.
 *
 * A suite that dies mid-way — a fatal in a screen it renders, say — never
 * reaches its own cleanup, and then wp_insert_user() answers "that login is
 * taken" with a WP_Error. The fixtures come out as user 0, and every single
 * check after this point fails for a reason that has nothing to do with the
 * code under test. Re-runnable from any state is worth these few lines.
 */
require_once ABSPATH . 'wp-admin/includes/user.php';

foreach ( [ 'tdh_notify_probe', 'tdh_notify_staff' ] as $login ) {
	$stale = get_user_by( 'login', $login );

	if ( $stale ) {
		wp_delete_user( (int) $stale->ID );
	}
}

global $wpdb;

/* Named, because `any` quietly excludes trash and a home left in the bin
   would still hold the title. */
$every_status = array_merge( array_keys( (array) get_post_stati( [ 'internal' => false ] ) ), [ 'trash' ] );

foreach ( [ 'Notify Probe Cottage', 'Notify Orphan Cottage' ] as $title ) {
	foreach ( (array) get_posts(
		[
			'post_type'      => Post_Types::LISTING,
			'post_status'    => $every_status,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'title'          => $title,
		]
	) as $stale ) {

		/*
		 * The inquiries BEFORE the home, and while the home still exists to
		 * find them by. Deleting a listing does not take its conversations
		 * with it — by design, so a landlord removing a home does not wipe
		 * the messages about it — which means an aborted run leaves its
		 * inquiries behind for good. Three crashed runs quietly left
		 * thirty-three of them on this developer's site.
		 */
		foreach ( (array) get_posts(
			[
				'post_type'      => Post_Types::INQUIRY,
				'post_status'    => $every_status,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_tdh_listing_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => (string) $stale,   // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		) as $conversation ) {
			foreach ( Notifications::for_inquiry( (int) $conversation ) as $row ) {
				$wpdb->delete( Notifications::table(), [ 'id' => (int) $row['id'] ], [ '%d' ] );
				wp_clear_scheduled_hook( 'tdh_notification_retry', [ (int) $row['id'] ] );
				delete_transient( 'tdh_resend_' . (int) $row['id'] );
			}

			wp_delete_post( (int) $conversation, true );
		}

		wp_delete_post( (int) $stale, true );
	}
}

/*
 * And any inquiry of ours whose home is ALREADY gone.
 *
 * The loop above can only find inquiries through the home they are about.
 * An older version of this clean-up deleted the home and left the
 * conversations, so those are now unreachable that way — and would stay on
 * the site for ever. Inquiry::store() puts the home's name in the title,
 * which still identifies them.
 */
foreach ( [ 'Notify Probe Cottage', 'Notify Orphan Cottage' ] as $title ) {

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$widowed = (array) $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title LIKE %s",
			Post_Types::INQUIRY,
			'%' . $wpdb->esc_like( $title )
		)
	);

	foreach ( $widowed as $conversation ) {
		foreach ( Notifications::for_inquiry( (int) $conversation ) as $row ) {
			$wpdb->delete( Notifications::table(), [ 'id' => (int) $row['id'] ], [ '%d' ] );
			wp_clear_scheduled_hook( 'tdh_notification_retry', [ (int) $row['id'] ] );
			delete_transient( 'tdh_resend_' . (int) $row['id'] );
		}

		wp_delete_post( (int) $conversation, true );
	}
}

/* And any notification row whose inquiry no longer exists. */
$wpdb->query( 'DELETE FROM ' . Notifications::table() . " WHERE inquiry_id NOT IN ( SELECT ID FROM {$wpdb->posts} )" ); // phpcs:ignore WordPress.DB

$enquiries_before = count( (array) get_posts( [ 'post_type' => Post_Types::INQUIRY, 'post_status' => $every_status, 'posts_per_page' => -1, 'fields' => 'ids' ] ) );
$rows_before      = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Notifications::table() ); // phpcs:ignore WordPress.DB

$landlord_id = wp_insert_user(
	[
		'user_login' => 'tdh_notify_probe',
		'user_email' => 'notify-landlord@example.com',
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => 'tdh_landlord',
	]
);
$landlord    = is_wp_error( $landlord_id ) ? 0 : (int) $landlord_id;

$staff_id = wp_insert_user(
	[
		'user_login' => 'tdh_notify_staff',
		'user_email' => 'notify-staff@example.com',
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => 'administrator',
	]
);
$staff    = is_wp_error( $staff_id ) ? 0 : (int) $staff_id;

$home = probe_home( 'Notify Probe Cottage', $landlord );

ok( 'a landlord, a staff account and a home exist', $landlord > 0 && $staff > 0 && $home > 0 );
ok( 'the staff account really is staff', Accounts::is_staff( $staff ) );
ok( '...and the landlord really is not', ! Accounts::is_staff( $landlord ) );

echo "\n=== who the email goes to ===\n";

$who = Notifications::recipient_for( $home );
ok( 'with no contact address it uses the account address', 'notify-landlord@example.com' === $who['to'], $who['to'] );
ok( '...and does not call that a fallback', ! $who['fell_back'] );

update_post_meta( $home, '_tdh_contact_email', 'front-desk@example.com' );
$who = Notifications::recipient_for( $home );
ok( 'a contact address on the home wins', 'front-desk@example.com' === $who['to'], $who['to'] );

update_post_meta( $home, '_tdh_contact_email', 'front desk at example' );
$who = Notifications::recipient_for( $home );
ok( 'an unusable contact address falls back to the account', 'notify-landlord@example.com' === $who['to'], $who['to'] );
ok( '...and records that it fell back', $who['fell_back'] );
ok( '...and keeps what was typed, so somebody can be told', 'front desk at example' === $who['typed'] );

delete_post_meta( $home, '_tdh_contact_email' );

$orphan = probe_home( 'Notify Orphan Cottage', 0 );
$who    = Notifications::recipient_for( $orphan );
ok( 'a home with no owner at all has no recipient', '' === $who['to'] );

echo "\n=== one row per inquiry per channel ===\n";

$first = probe_inquiry( $home );
$row_a = Notifications::queue( $first, Notifications::CHANNEL_EMAIL, 'a@example.com' );
$row_b = Notifications::queue( $first, Notifications::CHANNEL_EMAIL, 'a@example.com' );

ok( 'queueing the same inquiry twice reuses the row', $row_a > 0 && $row_a === $row_b );
ok( '...so it cannot be logged as two inquiries', 1 === count( Notifications::for_inquiry( $first ) ) );

$row_sms = Notifications::queue( $first, 'sms', '+15555550100' );
ok( 'a second channel gets its own row', $row_sms > 0 && $row_sms !== $row_a );
ok( '...and the email row is still found among them', email_row( $first ) && (int) email_row( $first )['id'] === $row_a );

$fresh = Notifications::row( $row_a );
ok( 'a new row starts queued with no attempts', Notifications::QUEUED === $fresh['status'] && 0 === (int) $fresh['attempts'] );

echo "\n=== a send that works ===\n";

$mail_state = 'accept';
sent_reset();

$good = probe_inquiry( $home );
do_action( 'tdh_inquiry_received', $good, $home );

/*
 * The renter's request is over by now. Nothing has been sent yet, and that
 * is the point: this action fires between the inquiry being stored and the
 * renter's success screen, so a mail server that takes fifteen seconds to
 * refuse a connection would be fifteen seconds the renter spends looking
 * at a page that has not moved.
 */
$row = email_row( $good );
ok( 'the renter does not wait for the mail server', $row && Notifications::SENT !== $row['status'], $row ? $row['status'] : 'no row' );
ok( '...the row exists immediately all the same', $row && Notifications::QUEUED === $row['status'], $row ? $row['status'] : 'no row' );
ok( '...staff are told it is queued, not left with a blank', 'Email queued' === Notifications::state_of( $good )['label'] );
ok( '...and the send is booked for right now', $row && is_booked( (int) $row['id'] ) );
ok( 'nothing has gone out yet', 0 === sent_count(), (string) sent_count() );

ok( 'the booked send runs', 1 === run_due_sends( $good ) );

$row = email_row( $good );
ok( 'the row says sent', $row && Notifications::SENT === $row['status'], $row ? $row['status'] : 'no row' );
ok( '...after one attempt', $row && 1 === (int) $row['attempts'] );
ok( '...with the recipient written down', $row && 'notify-landlord@example.com' === $row['recipient'] );
ok( 'exactly one email went out', 1 === sent_count(), (string) sent_count() );

$msg = sent_at( 0 );
ok( 'it went to the landlord', 'notify-landlord@example.com' === ( is_array( $msg['to'] ) ? ( $msg['to'][0] ?? '' ) : ( $msg['to'] ?? '' ) ) );
ok( 'the subject names the home', str_contains( (string) ( $msg['subject'] ?? '' ), 'Notify Probe Cottage' ) );
ok( '...and the site, so it is filterable', str_contains( (string) ( $msg['subject'] ?? '' ), get_bloginfo( 'name' ) ) );

$body = (string) ( $msg['message'] ?? '' );
ok( 'the body links to the message in the dashboard', str_contains( $body, 'view=inquiries' ) && str_contains( $body, 'msg=' . $good ) );
ok( 'the body names the renter', str_contains( $body, 'Nora Blake' ) );

/* An inbox is a less careful place than the dashboard, and mail forwards. */
ok( 'the renter’s phone number is NOT in the email', ! str_contains( $body, '412-555-0186' ) );
ok( 'the renter’s message is NOT in the email', ! str_contains( $body, 'Is there a lift' ) );

ok( 'the body is spaced into paragraphs, not one block', str_contains( $body, "\n\n" ) );

echo "\n=== reply-to ===\n";

$headers = (array) ( $msg['headers'] ?? [] );
ok( 'reply-to is the renter', in_array( 'Reply-To: Nora Blake <renter@example.com>', $headers, true ), implode( ' | ', $headers ) );

$comma = probe_inquiry( $home, [ 'name' => 'Dana Whitfield, Jr.', 'email' => 'dana@example.com' ] );
$head  = Notifications::headers( $comma );
ok( 'a comma in the name is stripped', ! str_contains( $head[0] ?? '', ',' ), $head[0] ?? '' );
ok( '...because wp_mail splits reply-to on commas and would send two', 1 === count( $head ) && str_contains( $head[0], 'Dana Whitfield Jr.' ), $head[0] ?? '' );

$angle = probe_inquiry( $home, [ 'name' => 'A <script> Person;', 'email' => 'angle@example.com' ] );
$head  = Notifications::headers( $angle );
ok( 'angle brackets and semicolons go too', ! preg_match( '/[<>;]/', str_replace( [ '<angle@example.com>' ], '', $head[0] ?? '' ) ), $head[0] ?? '' );

$noaddr = probe_inquiry( $home, [ 'email' => 'not-an-address' ] );
ok( 'no reply-to at all when the renter gave no usable address', [] === Notifications::headers( $noaddr ) );

echo "\n=== nobody to write to ===\n";

sent_reset();
$lost = probe_inquiry( $orphan );
do_action( 'tdh_inquiry_received', $lost, $orphan );
run_due_sends( $lost );

$row = email_row( $lost );
ok( 'a row is still written when there is no address', null !== $row );
ok( '...marked given up rather than left queued', $row && Notifications::GIVEN_UP === $row['status'] );
ok( '...saying why in words', $row && str_contains( (string) $row['provider_response'], 'no usable address' ), $row ? $row['provider_response'] : '' );
ok( '...and no email was attempted', 0 === sent_count() );

echo "\n=== a landlord who mistyped their contact address ===\n";

/*
 * The whole path, not just recipient_for(). A renter's inquiry must still
 * arrive when the address on the home is nonsense — it goes to the account
 * address instead, and somebody is told so the typo can be fixed.
 */
$mail_state = 'accept';
sent_reset();

update_post_meta( $home, '_tdh_contact_email', 'front desk at example' );

$fellback = probe_inquiry( $home );
do_action( 'tdh_inquiry_received', $fellback, $home );
run_due_sends( $fellback );

delete_post_meta( $home, '_tdh_contact_email' );

$row = email_row( $fellback );
ok( 'the inquiry is still emailed', $row && Notifications::SENT === $row['status'], $row ? $row['status'] : 'no row' );
ok( '...to the account address, not the typo', 'notify-landlord@example.com' === sent_to( 0 ), sent_to( 0 ) );
ok( '...and exactly once', 1 === sent_count(), (string) sent_count() );

echo "\n=== a send that fails, and the retries ===\n";

$mail_state = 'refuse';
sent_reset();

$bad = probe_inquiry( $home );
do_action( 'tdh_inquiry_received', $bad, $home );
run_due_sends( $bad );

$row    = email_row( $bad );
$bad_id = (int) $row['id'];

ok( 'the first refusal is written as failed', Notifications::FAILED === $row['status'] );
ok( '...with one attempt spent', 1 === (int) $row['attempts'] );
ok( '...and what the mail server actually said', str_contains( (string) $row['provider_response'], '550 relay denied' ), $row['provider_response'] );

$at = wp_next_scheduled( Notifications::class ? 'tdh_notification_retry' : '', [ $bad_id ] );
ok( 'a retry is booked', (bool) $at );
ok( '...ten minutes out', $at && abs( ( $at - time() ) - 600 ) <= 5, $at ? (string) ( $at - time() ) : 'none' );

Notifications::send( $bad_id );
$row = Notifications::row( $bad_id );
ok( 'the second refusal spends the second attempt', 2 === (int) $row['attempts'] );
ok( '...and is still not terminal', Notifications::FAILED === $row['status'] );

$at = wp_next_scheduled( 'tdh_notification_retry', [ $bad_id ] );
ok( 'the next retry is an hour out, not ten minutes', $at && abs( ( $at - time() ) - 3600 ) <= 5, $at ? (string) ( $at - time() ) : 'none' );

/* The stale-event trap: each failure must clear the last booking, or the
   retries stack up and keep firing after the row is finished with. */
$cron  = _get_cron_array();
$count = 0;

foreach ( (array) $cron as $events ) {
	foreach ( (array) ( $events['tdh_notification_retry'] ?? [] ) as $event ) {
		if ( [ $bad_id ] === (array) ( $event['args'] ?? [] ) ) {
			++$count;
		}
	}
}

ok( 'only one retry is booked at a time, never a stack', 1 === $count, (string) $count );

Notifications::send( $bad_id );
$row = Notifications::row( $bad_id );
ok( 'the third refusal gives up', Notifications::GIVEN_UP === $row['status'] );
ok( '...at exactly three attempts', 3 === (int) $row['attempts'] );
ok( 'nothing is booked after giving up', ! wp_next_scheduled( 'tdh_notification_retry', [ $bad_id ] ) );

$before = (int) Notifications::row( $bad_id )['attempts'];
Notifications::send( $bad_id );
ok( 'a further send on a row that gave up changes nothing', $before === (int) Notifications::row( $bad_id )['attempts'] );

ok( 'three refusals sent three emails and no more', 3 === sent_count(), (string) sent_count() );

$mail_state = 'accept';
sent_reset();
Notifications::send( (int) email_row( $good )['id'] );
ok( 'a send on a row already sent does not send it twice', 0 === sent_count() );

echo "\n=== the catch-up sweep ===\n";

$mail_state = 'refuse';
sent_reset();

global $wpdb;

/*
 * Judged by what happened to THIS row, not by a global count of sends.
 *
 * sweep() legitimately collects any queued or failed email row that is due,
 * and this suite leaves a couple of its own lying about on purpose — so
 * counting every message that went out measured the fixtures as much as the
 * behaviour, and the numbers drifted as soon as queued rows were included.
 */
$swept = static function () use ( $notifications, $bad_id ): bool {

	/*
	 * Nothing else sweepable for the length of one call. sweep() takes five
	 * rows at a time in date order, so on a site with any other pending row
	 * the one under test might not be among them — and somebody's real
	 * pending email would go out to prove a point about ours.
	 */
	$parked = park_other_rows( $bad_id );

	$before = Notifications::row( $bad_id );
	$notifications->sweep();
	$after = Notifications::row( $bad_id );

	unpark_rows( $parked );

	return (int) $before['attempts'] !== (int) $after['attempts']
		|| (string) $before['status'] !== (string) $after['status'];
};

/* A row on its first attempt, refused nine minutes ago: not due yet. */
$wpdb->update(
	Notifications::table(),
	[
		'status'     => Notifications::FAILED,
		'attempts'   => 1,
		'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 540 ),
	],
	[ 'id' => $bad_id ],
	[ '%s', '%d', '%s' ],
	[ '%d' ]
);

ok( 'a failure that is not due yet is left alone', ! $swept() );

/* The same row, refused eleven minutes ago: due. */
$wpdb->update(
	Notifications::table(),
	[ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 660 ) ],
	[ 'id' => $bad_id ],
	[ '%s' ],
	[ '%d' ]
);

ok( 'a failure past its ten minutes is picked up', $swept() );

/* A row on its SECOND attempt waits an hour, not ten minutes. The query can
   only ask for the shortest backoff, so this is the filter doing its job. */
$wpdb->update(
	Notifications::table(),
	[
		'status'     => Notifications::FAILED,
		'attempts'   => 2,
		'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 1800 ),
	],
	[ 'id' => $bad_id ],
	[ '%s', '%d', '%s' ],
	[ '%d' ]
);

ok( 'a second attempt waits the full hour before the sweep takes it', ! $swept() );

$wpdb->update(
	Notifications::table(),
	[ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 3700 ) ],
	[ 'id' => $bad_id ],
	[ '%s' ],
	[ '%d' ]
);

ok( '...and is taken once the hour is up', $swept() );

/* Terminal rows are never swept, however old they get. */
$wpdb->update(
	Notifications::table(),
	[
		'status'     => Notifications::GIVEN_UP,
		'attempts'   => 3,
		'updated_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
	],
	[ 'id' => $bad_id ],
	[ '%s', '%d', '%s' ],
	[ '%d' ]
);

ok( 'a row that gave up is never swept, however old', ! $swept() );

/*
 * The host whose cron never fires. The send is booked rather than made, so
 * on such a host the FIRST attempt is the one that goes missing — and a row
 * sitting queued for ever is worse than one that failed loudly, because
 * nothing about it looks wrong.
 */
$mail_state = 'accept';

$wpdb->update(
	Notifications::table(),
	[
		'status'     => Notifications::QUEUED,
		'attempts'   => 0,
		'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 30 ),
	],
	[ 'id' => $bad_id ],
	[ '%s', '%d', '%s' ],
	[ '%d' ]
);

ok( 'a just-queued row is left for cron, not raced', ! $swept() );

$wpdb->update(
	Notifications::table(),
	[ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 300 ) ],
	[ 'id' => $bad_id ],
	[ '%s' ],
	[ '%d' ]
);

ok( '...but one still queued minutes later is sent anyway', $swept() );
ok( '...so cron being off delays the email, never loses it', Notifications::SENT === Notifications::row( $bad_id )['status'] );

/*
 * The sms row in this same table is never emailed.
 *
 * Including queued rows in the sweep quietly made this possible: D4's rows
 * start life queued too, and everything the sweep hands to send() ends in
 * wp_mail(), so the first text anybody sent would have gone out as an email
 * addressed to a phone number.
 */
$wpdb->update(
	Notifications::table(),
	[
		'status'     => Notifications::QUEUED,
		'attempts'   => 0,
		'updated_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
	],
	[ 'id' => $row_sms ],
	[ '%s', '%d', '%s' ],
	[ '%d' ]
);

sent_reset();
$notifications->sweep();

$sms_after = Notifications::row( $row_sms );
ok( 'an sms row is never picked up by the email sweep', Notifications::QUEUED === $sms_after['status'] && 0 === (int) $sms_after['attempts'], $sms_after['status'] . '/' . $sms_after['attempts'] );
ok( '...and send() refuses one outright', ! Notifications::send( $row_sms ) );

foreach ( mail_log() as $message ) {
	$to = is_array( $message['to'] ) ? ( $message['to'][0] ?? '' ) : (string) $message['to'];
	ok( 'no phone number reached a To header', ! str_starts_with( (string) $to, '+' ), (string) $to );
}

echo "\n=== resend ===\n";

sent_reset();
$mail_state = 'refuse';

Notifications::resend( $bad_id );
$row = Notifications::row( $bad_id );
ok( 'resend starts the count again rather than spending a fourth attempt', 1 === (int) $row['attempts'], (string) $row['attempts'] );
ok( '...and the row is live again, not terminal', Notifications::FAILED === $row['status'] );
ok( '...and it really did try', 1 === sent_count() );

$mail_state = 'accept';
sent_reset();
ok( 'resend on a working mail server succeeds', Notifications::resend( $bad_id ) );
ok( '...and the row says sent', Notifications::SENT === Notifications::row( $bad_id )['status'] );
ok( '...with nothing left booked', ! wp_next_scheduled( 'tdh_notification_retry', [ $bad_id ] ) );

/* The address is looked up again: the usual reason a row is here at all is
   that the address was wrong, and by now somebody has corrected it. */
update_post_meta( $home, '_tdh_contact_email', 'corrected@example.com' );
sent_reset();
Notifications::resend( $bad_id );
ok( 'resend re-reads the address from the home', 'corrected@example.com' === Notifications::row( $bad_id )['recipient'] );
delete_post_meta( $home, '_tdh_contact_email' );

sent_reset();
$lost_row = (int) email_row( $lost )['id'];
ok( 'resend with still no address refuses', ! Notifications::resend( $lost_row ) );
ok( '...without spending an attempt on it', 0 === (int) Notifications::row( $lost_row )['attempts'] );
ok( '...and says so in words', str_contains( (string) Notifications::row( $lost_row )['provider_response'], 'still no usable address' ), (string) Notifications::row( $lost_row )['provider_response'] );
ok( '...and sent nothing', 0 === sent_count() );

echo "\n=== the resend button ===\n";

$mail_state = 'accept';

/* Nothing at all happens to a request that is not ours. */
$_POST = [];
ok( 'the handler ignores a post that is not a resend', '(no redirect)' === ran( $notifications ) );

wp_set_current_user( $staff );

$_POST = [
	'tdh_action'  => Notifications::RESEND_ACTION,
	'tdh_inquiry' => (string) $bad,
	'_wpnonce'    => 'not-a-real-nonce',
];
ok( 'a stale page is refused as expired', Notifications::EXPIRED === outcome( ran( $notifications ) ) );

/*
 * The nonce is minted as whoever is being tested. A WordPress nonce is tied
 * to the user, so reusing staff's would bounce a landlord as "expired"
 * before the staff gate was ever reached — the suite would pass while
 * proving nothing about who is allowed to press the button.
 */
wp_set_current_user( $landlord );
$_POST['_wpnonce'] = wp_create_nonce( 'tdh_resend_email' );
delete_transient( 'tdh_resend_' . $bad_id );
sent_reset();
ok( 'a landlord cannot make the site send mail', Notifications::REFUSED === outcome( ran( $notifications ) ) );
ok( '...and nothing was sent', 0 === sent_count() );

wp_set_current_user( 0 );
$_POST['_wpnonce'] = wp_create_nonce( 'tdh_resend_email' );
delete_transient( 'tdh_resend_' . $bad_id );
sent_reset();
ok( 'nor can a logged-out visitor', Notifications::REFUSED === outcome( ran( $notifications ) ) );
ok( '...and nothing was sent for them either', 0 === sent_count() );

wp_set_current_user( $staff );
$_POST['_wpnonce'] = wp_create_nonce( 'tdh_resend_email' );
delete_transient( 'tdh_resend_' . $bad_id );
sent_reset();
$where = ran( $notifications );
ok( 'staff can', Notifications::RESENT === outcome( $where ), $where );
ok( '...and it actually sent', 1 === sent_count() );
ok( '...landing back on the message they were reading', str_contains( $where, Inquiry::PARAM_OPEN . '=' . $bad ), $where );

/* Double submit. The second press must not send a second email. */
sent_reset();
$where = ran( $notifications );
ok( 'a second press within the lock sends nothing more', 0 === sent_count() );
ok( '...and still reads as success, because to the presser it was', Notifications::RESENT === outcome( $where ) );

delete_transient( 'tdh_resend_' . $bad_id );
$_POST['tdh_inquiry'] = '99999999';
ok( 'an inquiry that does not exist is refused exactly as a wrong owner is', Notifications::REFUSED === outcome( ran( $notifications ) ) );

$_POST = [];

echo "\n=== what staff see ===\n";

$state = Notifications::state_of( $good );
ok( 'a sent inquiry reads "Emailed"', 'Emailed' === $state['label'], $state['label'] );
ok( '...with no failure detail hanging off it', '' === $state['detail'] );

$wpdb->update(
	Notifications::table(),
	[ 'status' => Notifications::FAILED, 'attempts' => 1, 'provider_response' => 'mailbox unavailable' ],
	[ 'id' => $bad_id ],
	[ '%s', '%d', '%s' ],
	[ '%d' ]
);

$state = Notifications::state_of( $bad );
ok( 'a failure says it is still trying', str_contains( $state['label'], 'trying again' ), $state['label'] );
ok( 'one attempt is "1 attempt", not "1 attempts"', str_contains( $state['detail'], '1 attempt ' ) && ! str_contains( $state['detail'], '1 attempts' ), $state['detail'] );
ok( '...and the reason is shown, not swallowed', str_contains( $state['detail'], 'mailbox unavailable' ) );

$wpdb->update(
	Notifications::table(),
	[ 'status' => Notifications::GIVEN_UP, 'attempts' => 3 ],
	[ 'id' => $bad_id ],
	[ '%s', '%d' ],
	[ '%d' ]
);

$state = Notifications::state_of( $bad );
ok( 'giving up drops the "trying again"', 'Email failed' === $state['label'], $state['label'] );
ok( 'three attempts is "3 attempts"', str_contains( $state['detail'], '3 attempts' ), $state['detail'] );

/* Every state has words of its own. A colour alone is not a status. */
$labels = [];

foreach ( [ Notifications::SENT, Notifications::QUEUED, Notifications::FAILED, Notifications::GIVEN_UP ] as $status ) {
	$wpdb->update( Notifications::table(), [ 'status' => $status ], [ 'id' => $bad_id ], [ '%s' ], [ '%d' ] );
	$labels[] = Notifications::state_of( $bad )['label'];
}

ok( 'every delivery state has its own words', count( array_unique( $labels ) ) === count( $labels ), implode( ' / ', $labels ) );
ok( '...and none of them is a bare status key', ! in_array( Notifications::GIVEN_UP, $labels, true ) );

$never = probe_inquiry( $home );
ok( 'an inquiry with no notification row at all reads as empty, not broken', '' === Notifications::state_of( $never )['state'] );

echo "\n=== the screen ===\n";

wp_set_current_user( $staff );
$_GET = [ 'view' => 'inquiries', Inquiry::PARAM_OPEN => (string) $bad ];

$wpdb->update(
	Notifications::table(),
	[ 'status' => Notifications::GIVEN_UP, 'attempts' => 3, 'provider_response' => 'mailbox unavailable' ],
	[ 'id' => $bad_id ],
	[ '%s', '%d', '%s' ],
	[ '%d' ]
);

/* dashboard() routes staff to the marketplace portal itself, and returns
   the markup rather than echoing it. */
$html = TDH\Account_Render::dashboard();

ok( 'the staff message shows a Delivery block', str_contains( $html, 'inquiry-delivery' ) );
ok( '...with the failure badge', str_contains( $html, 'delivery-pill is-gone' ) );
ok( '...saying "Email failed" in words, not by colour', str_contains( $html, 'Email failed' ) );
ok( '...naming the address it tried', str_contains( $html, 'notify-landlord@example.com' ) );
ok( '...showing what the mail server said', str_contains( $html, 'mailbox unavailable' ) );
ok( '...and offering to send it again', str_contains( $html, Notifications::RESEND_ACTION ) && str_contains( $html, 'Send it again' ) );
ok( 'the resend form carries a nonce', str_contains( $html, 'name="_wpnonce"' ) );
ok( 'it is a POST, so the Back button cannot repeat it', (bool) preg_match( '/<form[^>]*class="delivery-resend"[^>]*>/', $html, $form ) && str_contains( $form[0], 'method="post"' ) );
ok( 'the hint says the renter is not emailed', str_contains( $html, 'The renter is not emailed' ) );

/*
 * The submit handler is well-formed markup.
 *
 * The first version wrote the label into the handler with wp_json_encode,
 * whose DOUBLE quotes closed the onsubmit attribute and left the rest of
 * the script loose in the tag. The handler never ran and the form element
 * itself was malformed — and it still LOOKED right on screen, because a
 * browser shrugs and carries on. The label lives in a data attribute now.
 */
$attr = '';
if ( preg_match( '/<form[^>]*class="delivery-resend"[^>]*>/', $html, $m ) ) {
	$attr = $m[0];
}

ok( 'the resend form carries a sending label', str_contains( $attr, 'data-sending=' ), $attr );
ok( '...and a submit handler', str_contains( $attr, 'onsubmit=' ) );

$handler = '';
if ( preg_match( '/onsubmit="([^"]*)"/', $attr, $m ) ) {
	$handler = $m[1];
}

ok( '...whose attribute is not cut short by a quote of its own', str_contains( $handler, 'setTimeout' ) && str_contains( $handler, 'dataset.sending' ), $handler );
ok( '...and the button label is not inside it', ! str_contains( $handler, 'Sending' ) );
ok( 'the button is a plain submit, so it works without JavaScript', str_contains( $html, '<button class="secondary" type="submit">' ) );

/* A sent message offers no Resend: there is nothing to fix. */
$wpdb->update( Notifications::table(), [ 'status' => Notifications::SENT ], [ 'id' => $bad_id ], [ '%s' ], [ '%d' ] );

$html_sent = TDH\Account_Render::dashboard();

ok( 'a message that was emailed shows the sent badge', str_contains( $html_sent, 'delivery-pill is-sent' ) );
ok( '...and offers no Resend button', ! str_contains( $html_sent, 'Send it again' ) );

/* The landlord's own screen never shows any of it. */
wp_set_current_user( $landlord );

$html_landlord = TDH\Account_Render::dashboard();

ok( 'a landlord is not shown the delivery machinery', ! str_contains( $html_landlord, 'inquiry-delivery' ) );
ok( '...and cannot reach the resend action from their screen', ! str_contains( $html_landlord, Notifications::RESEND_ACTION ) );

wp_set_current_user( $staff );
$_GET = [ 'view' => 'inquiries' ];

$wpdb->update(
	Notifications::table(),
	[ 'status' => Notifications::GIVEN_UP, 'attempts' => 3 ],
	[ 'id' => $bad_id ],
	[ '%s', '%d' ],
	[ '%d' ]
);

$list = TDH\Account_Render::dashboard();

ok( 'the staff list flags the message that never got out', str_contains( $list, 'delivery-pill is-gone' ) );

$wpdb->update( Notifications::table(), [ 'status' => Notifications::SENT ], [ 'id' => $bad_id ], [ '%s' ], [ '%d' ] );

$list_ok = TDH\Account_Render::dashboard();

/*
 * Counted, not "is there a badge anywhere". The list holds twenty messages
 * and this suite deliberately leaves several of them failed, so asking for
 * no badge at all on the whole screen would be asserting something the
 * fixtures make false. What must be true is that THIS one stopped carrying
 * one the moment it went out.
 */
$before = substr_count( $list, 'delivery-pill' );
$after  = substr_count( $list_ok, 'delivery-pill' );

ok( '...and stops badging the one that has now gone out', $after === $before - 1, "{$before} then {$after}" );
ok( '...leaving the failures still flagged, not hidden with it', $after > 0, (string) $after );

/*
 * More than one page of them.
 *
 * The staff list drew a flat twenty with no pager and no count, so the
 * twenty-first oldest message was unreachable and nothing said so — which
 * defeats the delivery badge entirely, since the message nobody can reach
 * is exactly the one whose failure nobody can act on.
 */
$crowd = [];

$already = (int) ( new WP_Query(
	[
		'post_type'      => Post_Types::INQUIRY,
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	]
) )->found_posts;

for ( $i = $already; $i <= Inquiry::PER_PAGE + 2; $i++ ) {
	$crowd[] = probe_inquiry( $home, [ 'name' => "Crowd {$i}", 'email' => "crowd{$i}@example.com" ] );
}

$total = $already + count( $crowd );

$_GET = [ 'view' => 'inquiries' ];
$page1 = TDH\Account_Render::dashboard();

ok( 'page one draws a full page and no more', Inquiry::PER_PAGE === substr_count( $page1, 'class="portal-inquiry' ), (string) substr_count( $page1, 'class="portal-inquiry' ) );
ok( '...and offers a second page', str_contains( $page1, 'inbox-pages' ) && str_contains( $page1, 'ipage=2' ) );
ok( '...and says how many there are altogether', str_contains( wp_strip_all_tags( $page1 ), number_format_i18n( $total ) . ' messages' ), (string) $total );
ok( '...marking which page you are on, for a screen reader too', str_contains( $page1, 'aria-current="page"' ) );

$_GET = [ 'view' => 'inquiries', 'ipage' => '2' ];
$page2 = TDH\Account_Render::dashboard();

/* Capped at a page: on a site already holding forty-five inquiries the
   second page is full, not "the remainder". */
$rest = min( Inquiry::PER_PAGE, $total - Inquiry::PER_PAGE );
ok( 'page two holds the rest', $rest === substr_count( $page2, 'class="portal-inquiry' ), "expected {$rest}, drew " . substr_count( $page2, 'class="portal-inquiry' ) );
ok( '...and they are different messages, not the same twenty again', $page1 !== $page2 );

foreach ( $crowd as $id ) {
	foreach ( Notifications::for_inquiry( (int) $id ) as $r ) {
		$wpdb->delete( Notifications::table(), [ 'id' => (int) $r['id'] ], [ '%d' ] );
	}
	wp_delete_post( (int) $id, true );
}

$_GET = [];
wp_set_current_user( 0 );

echo "\n=== the copy to staff ===\n";

delete_option( Notifications::OPTION_COPY );
delete_option( Notifications::OPTION_COPY_TO );

ok( 'copies are off until somebody switches them on', '' === Notifications::copy_to() );

update_option( Notifications::OPTION_COPY_TO, 'office@example.com' );
ok( '...an address alone does not switch them on', '' === Notifications::copy_to() );

update_option( Notifications::OPTION_COPY, '1' );
ok( 'switched on with an address, copies go there', 'office@example.com' === Notifications::copy_to() );

update_option( Notifications::OPTION_COPY_TO, 'office at example' );
ok( 'switched on with rubbish, nothing is sent anywhere', '' === Notifications::copy_to() );

update_option( Notifications::OPTION_COPY_TO, 'office@example.com' );

$mail_state = 'accept';
sent_reset();

$copied = probe_inquiry( $home );
do_action( 'tdh_inquiry_received', $copied, $home );
run_due_sends( $copied );

ok( 'a copy goes out alongside the landlord’s email', 2 === sent_count(), (string) sent_count() );
ok( '...to the office address', 'office@example.com' === ( sent_to( 1 ) ) );
ok( '...marked as a copy rather than reading as the landlord’s own', str_contains( (string) ( sent_at( 1 )['message'] ?? '' ), 'A copy of an inquiry' ) );
ok( '...and it leaves out the phone number too', ! str_contains( (string) ( sent_at( 1 )['message'] ?? '' ), '412-555-0186' ) );

/* A failure must not produce a copy: there is nothing to copy. */
$mail_state = 'refuse';
sent_reset();
$nocopy = probe_inquiry( $home );
do_action( 'tdh_inquiry_received', $nocopy, $home );
run_due_sends( $nocopy );
ok( 'a failed send sends no copy either', 1 === sent_count(), (string) sent_count() );

/*
 * Partial success: the landlord's email works and the copy does not. The
 * row must still say sent, because the person who needed it got it — but
 * the copy going missing has to leave a trace, or copies could stop for a
 * month and the only clue would be somebody noticing an empty mailbox.
 */
update_option( Notifications::OPTION_COPY, '1' );
update_option( Notifications::OPTION_COPY_TO, 'office@example.com' );

$mail_state = 'refuse_copy';
sent_reset();

$halfway = probe_inquiry( $home );
do_action( 'tdh_inquiry_received', $halfway, $home );
run_due_sends( $halfway );

ok( 'the landlord still gets theirs when the copy fails', Notifications::SENT === email_row( $halfway )['status'] );
ok( '...and the row does not pretend the send failed', 'Emailed' === Notifications::state_of( $halfway )['label'] );

/*
 * That the missing copy is WRITTEN DOWN is asserted in "what the log was
 * told", not here.
 *
 * It was checked here first and passed on a developer's machine while
 * failing on CI, for the same reason the whole log section did: verify.bat
 * and CI set TDH_LOG_SILENT, so nothing above that section logs anything,
 * and the rows it found were left over from earlier un-silenced runs. Only
 * the section that clears the flag for itself can ask the log a question
 * and believe the answer.
 */

$mail_state = 'accept';
delete_option( Notifications::OPTION_COPY );
delete_option( Notifications::OPTION_COPY_TO );

delete_option( Notifications::OPTION_COPY );
delete_option( Notifications::OPTION_COPY_TO );

echo "\n=== the settings screen that switches copies on ===\n";

/*
 * The server's own guard, not the browser's.
 *
 * The field is type="email", so Chrome refuses to submit rubbish and the
 * PHP below never runs in a real browser. That is exactly why it is tested
 * here: the guard that matters is the one a script, a curl or an old
 * browser meets, and it is the one nobody ever sees fail.
 */
wp_set_current_user( $staff );

$settings = new TDH\Admin\Mail_Settings();

/** Run the settings POST handler and report where it redirected. */
$save = static function ( array $post ) use ( $settings ): string {

	$_POST = array_merge( [ 'tdh_mail_action' => 'copy', '_wpnonce' => wp_create_nonce( 'tdh_mail_copy' ) ], $post );

	// check_admin_referer() reads $_REQUEST, which is built once at the
	// start of the request and knows nothing about an assignment to $_POST.
	$_REQUEST = $_POST;

	$catch = static function ( $location ) {
		throw new RuntimeException( (string) $location );
	};

	add_filter( 'wp_redirect', $catch, 1 );

	try {
		$settings->handle_post();
		$where = '(no redirect)';
	} catch ( RuntimeException $e ) {
		$where = $e->getMessage();
	} finally {
		remove_filter( 'wp_redirect', $catch, 1 );
		$_POST    = [];
		$_REQUEST = [];
	}

	return $where;
};

$save( [ 'tdh_copy_on' => '1', 'tdh_copy_to' => 'office at example' ] );
ok( 'switching copies on with rubbish does not switch them on', '1' !== (string) get_option( Notifications::OPTION_COPY, '' ), (string) get_option( Notifications::OPTION_COPY, '' ) );
ok( '...and stores nothing', '' === (string) get_option( Notifications::OPTION_COPY_TO, '' ), (string) get_option( Notifications::OPTION_COPY_TO, '' ) );

$save( [ 'tdh_copy_on' => '1', 'tdh_copy_to' => '' ] );
ok( 'switching them on with no address at all is refused too', '1' !== (string) get_option( Notifications::OPTION_COPY, '' ) );

$save( [ 'tdh_copy_on' => '1', 'tdh_copy_to' => 'office@example.com' ] );
ok( 'a real address switches them on', '1' === (string) get_option( Notifications::OPTION_COPY, '' ) );
ok( '...and is where copies go', 'office@example.com' === Notifications::copy_to() );

$save( [ 'tdh_copy_to' => 'office@example.com' ] );
ok( 'unticking the box switches them off', '' === (string) get_option( Notifications::OPTION_COPY, '' ) );
ok( '...but keeps the address, so a week off does not lose it', 'office@example.com' === (string) get_option( Notifications::OPTION_COPY_TO, '' ) );
ok( '...and nothing is copied while it is off', '' === Notifications::copy_to() );

wp_set_current_user( $landlord );
$_POST    = [ 'tdh_mail_action' => 'copy', '_wpnonce' => wp_create_nonce( 'tdh_mail_copy' ), 'tdh_copy_on' => '1', 'tdh_copy_to' => 'landlord@example.com' ];
$_REQUEST = $_POST;
$died     = false;

/*
 * wp_die() under WP-CLI ends the whole process, which would take the suite
 * with it. Swapping the handler for one that throws turns "the screen
 * refused this person" into something a test can actually assert.
 */
$thrower = static function (): callable {
	return static function ( $message ) {
		throw new RuntimeException( is_wp_error( $message ) ? (string) $message->get_error_message() : (string) $message );
	};
};

add_filter( 'wp_die_handler', $thrower, 99 );

try {
	$settings->handle_post();
} catch ( Throwable $e ) {
	$died = true;
}

remove_filter( 'wp_die_handler', $thrower, 99 );

$_POST    = [];
$_REQUEST = [];
ok( 'a landlord is refused outright at the settings screen', $died );
ok( '...and the stored address is untouched', 'office@example.com' === (string) get_option( Notifications::OPTION_COPY_TO, '' ), (string) get_option( Notifications::OPTION_COPY_TO, '' ) );

wp_set_current_user( 0 );
delete_option( Notifications::OPTION_COPY );
delete_option( Notifications::OPTION_COPY_TO );

echo "\n=== the real capture file ===\n";

if ( Mail::capturing() ) {

	$mail_state = 'through';
	$captured   = probe_inquiry( $home, [ 'name' => 'Capture Probe' ] );
	do_action( 'tdh_inquiry_received', $captured, $home );
	run_due_sends( $captured );

	$capture = Mail::latest_capture();
	$text    = $capture ? (string) $capture['body'] : '';

	ok( 'the send reaches the capture file', '' !== $text );
	ok( '...addressed to the landlord', str_contains( $text, 'To:      notify-landlord@example.com' ), substr( $text, 0, 120 ) );
	ok( '...with the subject naming the home', str_contains( $text, 'New inquiry about Notify Probe Cottage' ) );
	ok( '...and reply-to the renter', str_contains( $text, 'Reply-To: Capture Probe <renter@example.com>' ) );
	ok( '...still without the renter’s phone number', ! str_contains( $text, '412-555-0186' ) );

	$mail_state = 'accept';
} else {
	// A branch this environment cannot exercise is said, not failed. A CI
	// runner with real SMTP configured has nowhere to capture to.
	echo "  ..    capture branch skipped: this environment does not capture mail\n";
}

echo "\n=== what the log was told ===\n";

if ( class_exists( '\TDH\Log' ) ) {

	/*
	 * This section writes its own log lines and reads back only what it
	 * wrote.
	 *
	 * Both halves of that matter. verify.bat and CI set TDH_LOG_SILENT, so
	 * everything above this point logged nothing at all — the first version
	 * of these checks passed on a developer's machine purely because rows
	 * from earlier, un-silenced runs were still in the table, and failed the
	 * moment CI ran them against an empty database. Clearing the flag is
	 * what verify-log.php does for the same reason; the `since` filter is
	 * what stops stale rows standing in for real ones ever again.
	 */
	$was_silent = getenv( 'TDH_LOG_SILENT' );

	/*
	 * Everything written from here to the end of the section is deleted
	 * again, by id, the way verify-log.php does it.
	 *
	 * TDH_LOG_SILENT exists because a verification run's fixtures once
	 * filled the staff log with two hundred lines of invented failures.
	 * Clearing the flag to test the logging and then walking away puts them
	 * straight back — "SMTP connect() failed (550 relay denied)" against a
	 * home that no longer exists, which is indistinguishable from a real
	 * mail server falling over until somebody checks the listing id.
	 */
	$log_table = TDH\Log::table();
	$log_floor = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$log_table}" ); // phpcs:ignore WordPress.DB

	putenv( 'TDH_LOG_SILENT' );

	$log_from = time() - 1;

	$log_home   = probe_home( 'Notify Probe Cottage', $landlord );
	$log_orphan = probe_home( 'Notify Orphan Cottage', 0 );

	/* One that works. */
	$mail_state = 'accept';
	$l_ok       = probe_inquiry( $log_home );
	do_action( 'tdh_inquiry_received', $l_ok, $log_home );
	run_due_sends( $l_ok );

	/* One that fails, then gives up. */
	$mail_state = 'refuse';
	$l_bad      = probe_inquiry( $log_home );
	do_action( 'tdh_inquiry_received', $l_bad, $log_home );
	run_due_sends( $l_bad );

	$l_bad_row = (int) email_row( $l_bad )['id'];
	Notifications::send( $l_bad_row );
	Notifications::send( $l_bad_row );

	/* One with nobody to write to. */
	$l_lost = probe_inquiry( $log_orphan );
	do_action( 'tdh_inquiry_received', $l_lost, $log_orphan );

	/* One whose contact address is nonsense, so it falls back. */
	$mail_state = 'accept';
	update_post_meta( $log_home, '_tdh_contact_email', 'front desk at example' );
	$l_fell = probe_inquiry( $log_home );
	do_action( 'tdh_inquiry_received', $l_fell, $log_home );
	run_due_sends( $l_fell );
	delete_post_meta( $log_home, '_tdh_contact_email' );

	/* One whose staff copy fails while the landlord's own works. */
	update_option( Notifications::OPTION_COPY, '1' );
	update_option( Notifications::OPTION_COPY_TO, 'office@example.com' );
	$mail_state = 'refuse_copy';
	$l_copy     = probe_inquiry( $log_home );
	do_action( 'tdh_inquiry_received', $l_copy, $log_home );
	run_due_sends( $l_copy );
	$mail_state = 'accept';
	delete_option( Notifications::OPTION_COPY );
	delete_option( Notifications::OPTION_COPY_TO );

	$lines = TDH\Log::query( [ 'feature' => 'email', 'per_page' => 200, 'since' => $log_from ] );
	$codes = array_column( (array) ( $lines['rows'] ?? [] ), 'event' );
	$seen  = implode( ', ', array_unique( $codes ) );

	ok( 'anything was logged at all', [] !== $codes, 'nothing — is TDH_LOG_SILENT still set?' );
	ok( 'a send that worked is logged', in_array( 'inquiry_sent', $codes, true ), $seen );
	ok( 'a send that failed is logged', in_array( 'inquiry_failed', $codes, true ), $seen );
	ok( 'giving up is logged as its own thing, not as another failure', in_array( 'inquiry_given_up', $codes, true ), $seen );
	ok( 'having nobody to write to is logged', in_array( 'inquiry_no_recipient', $codes, true ), $seen );
	ok( 'a fallen-back contact address is logged', in_array( 'contact_email_unusable', $codes, true ), $seen );
	ok( 'a staff copy that failed is logged', in_array( 'inquiry_copy_failed', $codes, true ), $seen );

	$masked = true;

	foreach ( (array) ( $lines['rows'] ?? [] ) as $line ) {
		$context = (array) ( $line['context'] ?? [] );

		if ( isset( $context['email'] ) && str_contains( (string) $context['email'], 'notify-landlord@example.com' ) ) {
			$masked = false;
		}
	}

	ok( 'the address in the log is masked, not stored in full', $masked );

	/* Put the flag back exactly as it was found. */
	if ( false === $was_silent ) {
		putenv( 'TDH_LOG_SILENT' );
	} else {
		putenv( 'TDH_LOG_SILENT=' . $was_silent );
	}

	/*
	 * And take every line back out. Notifications is not the only listener
	 * that wakes up during this section — Smtp and Mail record the same
	 * failures from their own side — so this deletes by id range rather
	 * than by event name, which catches all of them.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$log_table} WHERE id > %d", $log_floor ) );

	$log_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$log_table} WHERE id > {$log_floor}" ); // phpcs:ignore WordPress.DB

	ok( 'the suite leaves not one invented failure in the staff log', 0 === $log_after, "{$log_after} left behind" );

	delete_transient( TDH\Log::TRANSIENT_FAILED );

	foreach ( [ $l_ok, $l_bad, $l_lost, $l_fell, $l_copy ] as $inq ) {
		foreach ( Notifications::for_inquiry( (int) $inq ) as $row ) {
			$wpdb->delete( Notifications::table(), [ 'id' => (int) $row['id'] ], [ '%d' ] );
			wp_clear_scheduled_hook( 'tdh_notification_retry', [ (int) $row['id'] ] );
		}
		wp_delete_post( (int) $inq, true );
	}

	wp_delete_post( $log_home, true );
	wp_delete_post( $log_orphan, true );
} else {
	echo "  ..    log branch skipped: TDH\\Log is not loaded\n";
}

echo "\n=== cleanup ===\n";

foreach ( [ $first, $good, $bad, $lost, $comma, $angle, $noaddr, $never, $copied, $nocopy, $fellback, $halfway, $captured ?? 0 ] as $inq ) {
	if ( $inq ) {
		foreach ( Notifications::for_inquiry( (int) $inq ) as $row ) {
			$wpdb->delete( Notifications::table(), [ 'id' => (int) $row['id'] ], [ '%d' ] );
			wp_clear_scheduled_hook( 'tdh_notification_retry', [ (int) $row['id'] ] );
			delete_transient( 'tdh_resend_' . (int) $row['id'] );
		}

		wp_delete_post( (int) $inq, true );
	}
}

foreach ( [ $home, $orphan ] as $fixture ) {
	if ( $fixture ) {
		wp_delete_post( (int) $fixture, true );
	}
}

require_once ABSPATH . 'wp-admin/includes/user.php';

foreach ( [ $landlord, $staff ] as $person ) {
	if ( $person ) {
		wp_delete_user( (int) $person );
	}
}

$_POST = [];
$_GET  = [];
wp_set_current_user( 0 );

/* Put the Email delivery screen back exactly as it was found. */
if ( false === $smtp_error_before ) {
	delete_option( TDH\Smtp::OPTION_LAST_ERROR );
} else {
	update_option( TDH\Smtp::OPTION_LAST_ERROR, $smtp_error_before, false );
}

if ( false === $smtp_sent_before ) {
	delete_option( TDH\Smtp::OPTION_LAST_SENT );
} else {
	update_option( TDH\Smtp::OPTION_LAST_SENT, $smtp_sent_before, false );
}

ok(
	'the suite leaves no false alarm on the Email delivery screen',
	$smtp_error_before === get_option( TDH\Smtp::OPTION_LAST_ERROR ),
	'forced failures were left showing as a real one'
);

/*
 * Counted against what was here BEFORE, not "rows that look like ours".
 *
 * The first version of this check counted orphans — rows whose inquiry no
 * longer exists — which can only ever catch a row left behind by an
 * inquiry that WAS deleted. It passed happily while three aborted runs
 * piled thirty-three inquiries and twenty-four rows onto the site, because
 * those rows still had their inquiries. A count either side is the only
 * version of this that can fail when it should.
 */
$enquiries_after = count( (array) get_posts( [ 'post_type' => Post_Types::INQUIRY, 'post_status' => $every_status, 'posts_per_page' => -1, 'fields' => 'ids' ] ) );
$rows_after      = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Notifications::table() ); // phpcs:ignore WordPress.DB

ok( 'every fixture is gone', ! get_post( $home ) && ! get_post( $good ) );
ok( '...leaving no inquiry behind', $enquiries_after === $enquiries_before, "{$enquiries_before} before, {$enquiries_after} after" );
ok( '...and no notification row behind', $rows_after === $rows_before, "{$rows_before} before, {$rows_after} after" );

[ $pass, $fail ] = ok();

printf( "\n%s  %d passed, %d failed\n", $fail ? 'FAILED' : 'PASSED', $pass, $fail );

if ( $fail ) {
	exit( 1 );
}
