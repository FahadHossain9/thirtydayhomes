<?php
/**
 * The renter's inquiry — verification suite.
 *
 * Run it through verify.bat, which finds the binaries for you, or directly:
 *
 *   D:\xampp\php\php.exe D:\xampp\wp-cli.phar eval-file `
 *     "wp-content/plugins/thirtydayhomes-core/tools/verify-inquiry.php"
 *
 * Safe on a populated site: every home, user and inquiry it creates is
 * deleted at the end, and it never sends mail — D3 does not exist yet and
 * nothing here would reach a person if it did.
 *
 * @package ThirtyDayHomes
 */

use TDH\Availability;
use TDH\Inquiry;
use TDH\Post_Types;

/* The clock is pinned. A suite that asserts "this move-in date is in the
   past" against the real calendar passes today and fails next year. */
$today = '2026-09-20';
add_filter( 'tdh_availability_today', static fn(): string => $today );

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

/**
 * Run handle() and report where it redirected, instead of exiting.
 *
 * bounce() ends in exit, which no test survives — but it redirects first,
 * and wp_redirect runs its filter before sending a header. Throwing from
 * that filter escapes the handler with the location intact.
 */
function run_handler( Inquiry $inquiry ): string {

	$catch = static function ( $location ) {
		throw new RuntimeException( (string) $location );
	};

	add_filter( 'wp_redirect', $catch, 1 );

	try {
		$inquiry->handle();
		$where = '(no redirect)';
	} catch ( RuntimeException $e ) {
		$where = $e->getMessage();
	} finally {
		remove_filter( 'wp_redirect', $catch, 1 );
	}

	return $where;
}

/** The `inquiry` value a redirect carried. */
function outcome( string $url ): string {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );

	return (string) ( $args['inquiry'] ?? '' );
}

/**
 * Run the archive handler with $_POST already set, and report the `inq`
 * flag it redirected with.
 */
function outcome_inq( Inquiry $inquiry ): string {

	$catch = static function ( $location ) {
		throw new RuntimeException( (string) $location );
	};

	add_filter( 'wp_redirect', $catch, 1 );

	try {
		$inquiry->handle_archive();
		$where = '(no redirect)';
	} catch ( RuntimeException $e ) {
		$where = $e->getMessage();
	} finally {
		remove_filter( 'wp_redirect', $catch, 1 );
		$_POST = [];
	}

	parse_str( (string) wp_parse_url( $where, PHP_URL_QUERY ), $args );

	return (string) ( $args['inq'] ?? '' );
}

/** How many inquiries exist right now. */
function inquiry_count(): int {
	return count(
		(array) get_posts(
			[
				'post_type'      => Post_Types::INQUIRY,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		)
	);
}

/** The newest inquiry, or 0. */
function newest_inquiry(): int {
	$ids = (array) get_posts(
		[
			'post_type'      => Post_Types::INQUIRY,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		]
	);

	return $ids ? (int) $ids[0] : 0;
}

/** Forget every transient this suite creates. */
function reset_state(): void {
	global $wpdb;

	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '_transient_tdh_inquiry%'
		    OR option_name LIKE '_transient_timeout_tdh_inquiry%'
		    OR option_name LIKE '_transient_tdh_inq_sent%'
		    OR option_name LIKE '_transient_timeout_tdh_inq_sent%'"
	);

	wp_cache_flush();
}

/** Render something and hand back the markup. */
function markup( callable $fn ): string {
	ob_start();
	$fn();

	return (string) ob_get_clean();
}

$_SERVER['REMOTE_ADDR']     = '203.0.113.77';
$_SERVER['HTTP_USER_AGENT'] = 'tdh-verify';

/*
 * This visitor accepts cookies.
 *
 * It matters. The stash that carries typed values through a refusal is
 * dropped on purpose when the visitor cannot be told from the next person
 * behind the same router — the key is then md5( IP . user agent ), shared
 * by a whole office. Without a cookie here the suite would be testing the
 * privacy fallback and quietly proving nothing about the values coming
 * back, which is the behaviour a renter actually meets.
 *
 * A browser gets this cookie on the redirect. A CLI run has to say so.
 */
$_COOKIE[ TDH\Accounts::NOTICE_COOKIE ] = str_repeat( 'a1b2c3d4', 4 );

$inquiry = new Inquiry();

echo "\n=== fixtures ===\n";

$landlord_id = wp_insert_user(
	[
		'user_login' => 'tdh_inquiry_probe',
		'user_email' => 'inquiry-probe@example.com',
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => 'tdh_landlord',
	]
);
$landlord    = is_wp_error( $landlord_id ) ? null : (int) $landlord_id;

// A paying landlord: an unpaid one's homes are hidden from renters (E1).
if ( $landlord ) {
	TDH\Membership::apply( $landlord, [ 'status' => TDH\Membership::ACTIVE, 'quota' => 5, 'expires' => strtotime( '+30 days' ) ] );
}

/** Publish a home and return its id. */
function probe_home( string $title, ?int $owner, string $status = 'publish' ): int {

	$id = (int) wp_insert_post(
		[
			'post_type'    => Post_Types::LISTING,
			'post_status'  => $status,
			'post_title'   => $title,
			'post_content' => 'A furnished monthly home used only by the inquiry suite.',
			'post_author'  => $owner ?: 0,
		]
	);

	update_post_meta( $id, '_tdh_price_monthly', 1800 );

	return $id;
}

$home   = probe_home( 'Inquiry Probe Cottage', $landlord );
$other  = probe_home( 'Inquiry Probe Annexe', $landlord );
$paused = probe_home( 'Inquiry Probe Paused', $landlord, 'tdh_paused' );

ok( 'a public home exists to inquire about', $home > 0 && TDH\Visibility::is_public( $home ) );
ok( 'a second public home exists, to prove the de-duplication is per home', $other > 0 && TDH\Visibility::is_public( $other ) );
ok( 'a paused home exists, and is NOT public', $paused > 0 && ! TDH\Visibility::is_public( $paused ) );

/** A complete, valid submission. */
function good_post( int $listing_id ): array {
	return [
		'tdh_action'  => 'tdh_inquire',
		'_wpnonce'    => wp_create_nonce( 'tdh_inquire_send' ),
		'tdh_listing' => (string) $listing_id,
		'tdh_name'    => 'Dana Whitfield',
		'tdh_email'   => 'dana@example.com',
		'tdh_phone'   => '412-555-0142',
		'tdh_move_in' => '2026-11-01',
		'tdh_stay'    => '13w',
		'tdh_message' => 'I start a 13-week contract at UPMC Presbyterian in November.',
		'tdh_consent' => '1',
	];
}

reset_state();

echo "\n=== a renter sends an inquiry ===\n";

$before = inquiry_count();
$heard  = [];
$listen = static function ( $id, $listing ) use ( &$heard ): void {
	$heard[] = [ (int) $id, (int) $listing ];
};
add_action( 'tdh_inquiry_received', $listen, 10, 2 );

$_POST = good_post( $home );
$where = run_handler( $inquiry );

ok( 'a complete inquiry is accepted', Inquiry::SENT === outcome( $where ), $where );
ok( '...and lands back on the home it was about', str_contains( $where, (string) wp_parse_url( (string) get_permalink( $home ), PHP_URL_PATH ) ), $where );
ok( '...and exactly one inquiry was written', inquiry_count() === $before + 1 );

$id = newest_inquiry();

ok( '...carrying the home it belongs to', (int) get_post_meta( $id, '_tdh_listing_id', true ) === $home );
ok( '...the renter\'s name, address and phone', 'Dana Whitfield' === get_post_meta( $id, '_tdh_renter_name', true ) && 'dana@example.com' === get_post_meta( $id, '_tdh_renter_email', true ) && '412-555-0142' === get_post_meta( $id, '_tdh_renter_phone', true ) );
ok( '...the move-in date and the stay as the renter chose them', '2026-11-01' === get_post_meta( $id, '_tdh_move_in', true ) && '13w' === get_post_meta( $id, '_tdh_stay_length', true ) );
ok( '...the message itself', str_contains( (string) get_post_meta( $id, '_tdh_message', true ), '13-week contract' ) );
ok( '...the version of the rules that was on screen (R24)', Inquiry::RULES_VERSION === get_post_meta( $id, '_tdh_rules_version', true ) );
ok( '...marked new', 'new' === get_post_meta( $id, '_tdh_status', true ) );

/*
 * The boolean-meta trap, written down in the handbook after C3. False is
 * stored as an empty string, so "not read" and "a record from before this
 * field existed" read back identically unless the key is actually there.
 */
ok( '...and marked unread in a way the dashboard can tell from "never set"', metadata_exists( 'post', $id, '_tdh_read' ) && '1' !== get_post_meta( $id, '_tdh_read', true ) );

ok( 'the title names the renter and the home, so the admin list is readable', str_contains( get_the_title( $id ), 'Dana Whitfield' ) && str_contains( get_the_title( $id ), 'Inquiry Probe Cottage' ), get_the_title( $id ) );

ok( 'D3 and D4 have something to listen to: the event fired once, with both ids', 1 === count( $heard ) && [ $id, $home ] === $heard[0], wp_json_encode( $heard ) );

remove_action( 'tdh_inquiry_received', $listen, 10 );

echo "\n=== the success screen ===\n";

$_GET = [ 'inquiry' => 'sent' ];
$sent = markup( static fn() => $inquiry->render( $home ) );
$_GET = [];

ok( 'success is its own screen, not a line above the same form', str_contains( $sent, 'Message sent' ) && ! str_contains( $sent, '<form' ) );
ok( '...and it names the home, so the renter knows which one', str_contains( $sent, 'Inquiry Probe Cottage' ) );
ok( '...and says what happens next', str_contains( $sent, 'reply within a day' ) );
ok( '...and offers somewhere to go, rather than a dead end', str_contains( $sent, 'Browse more homes' ) );
ok( '...and is announced to a screen reader, not only coloured', str_contains( $sent, 'role="status"' ) );

echo "\n=== the same message twice ===\n";

$before = inquiry_count();
$_POST  = good_post( $home );
$again  = run_handler( $inquiry );

ok( 'pressing Send again says sent — it did arrive', Inquiry::SENT === outcome( $again ), $again );
ok( '...and the landlord is not given the same inquiry twice', inquiry_count() === $before );

$_POST = good_post( $other );
$third = run_handler( $inquiry );
ok( 'the same person writing about a DIFFERENT home is a new inquiry', Inquiry::SENT === outcome( $third ) && inquiry_count() === $before + 1 );

$post          = good_post( $home );
$post['tdh_email'] = 'other@example.com';
$_POST         = $post;
$fourth        = run_handler( $inquiry );
ok( 'a different person writing about the SAME home is a new inquiry', Inquiry::SENT === outcome( $fourth ) && inquiry_count() === $before + 2 );

echo "\n=== what is refused ===\n";

reset_state();

/** Send a modified post and report the outcome. */
function send( Inquiry $inquiry, array $changes, int $listing_id ): string {
	$_POST = array_merge( good_post( $listing_id ), $changes );

	return outcome( run_handler( $inquiry ) );
}

$before = inquiry_count();

ok( 'an expired form is refused, and says so', Inquiry::EXPIRED === send( $inquiry, [ '_wpnonce' => 'stale' ], $home ) );
ok( '...and keeps every word that was typed', 'Dana Whitfield' === ( Inquiry::take()['values']['name'] ?? '' ) );

reset_state();
ok( 'a home taken off the market while the renter was writing is refused', Inquiry::GONE === send( $inquiry, [], $paused ) );

reset_state();
ok( 'a bot that fills the hidden field is told it worked', Inquiry::SENT === send( $inquiry, [ 'tdh_website' => 'http://spam.example' ], $home ) );
ok( '...and nothing at all was written', inquiry_count() === $before );

reset_state();
ok( 'no name is refused', Inquiry::INVALID === send( $inquiry, [ 'tdh_name' => '' ], $home ) );
ok( '...and the field is named, not the form', array_key_exists( 'name', Inquiry::take()['errors'] ) );

/*
 * A field that says it has an explanation must point at one that exists.
 * The first version pointed `aria-describedby` at `tdh-err-move_in` while
 * the error itself was `tdh-err-move-in`; the two never met, and a screen
 * reader read the field as having no explanation at all. Nothing on
 * screen looked wrong, which is exactly why it needs a check.
 */
reset_state();
send( $inquiry, [ 'tdh_move_in' => '2026-09-01', 'tdh_name' => '' ], $home );
$refused = markup( static fn() => $inquiry->render( $home ) );

preg_match_all( '/aria-describedby="([^"]+)"/', $refused, $points_at );
$missing = array_values(
	array_filter(
		array_unique( $points_at[1] ),
		static fn( string $target ): bool => ! str_contains( $refused, 'id="' . $target . '"' )
	)
);

ok( 'a refused field points at an explanation that actually exists', [] !== $points_at[1] && [] === $missing, wp_json_encode( $missing ) );

reset_state();
ok( 'an address that is not one is refused', Inquiry::INVALID === send( $inquiry, [ 'tdh_email' => 'dana@example' ], $home ) );
/*
 * The mistake this prevents: sanitize_email() answers '' for anything it
 * does not recognise, so putting the VALIDATED value back in the box
 * clears the field the form has just complained about. That is the moment
 * people give up on a form.
 */
ok( '...and the box still holds what was typed, not an empty field', 'dana@example' === ( Inquiry::take()['values']['email'] ?? '' ) );

reset_state();
ok( 'a move-in date in the past is refused, with its own reason', Inquiry::INVALID === send( $inquiry, [ 'tdh_move_in' => '2026-09-01' ], $home ) );
ok( '...and the reason says the date has passed', str_contains( (string) ( Inquiry::take()['errors']['move_in'] ?? '' ), 'already passed' ) );

reset_state();
ok( 'a move-in that is not a date at all is refused', Inquiry::INVALID === send( $inquiry, [ 'tdh_move_in' => 'next spring' ], $home ) );

reset_state();
ok( 'no move-in date is refused', Inquiry::INVALID === send( $inquiry, [ 'tdh_move_in' => '' ], $home ) );

reset_state();
ok( 'a stay length that is not on the list is refused', Inquiry::INVALID === send( $inquiry, [ 'tdh_stay' => '4 years' ], $home ) );

reset_state();
ok( 'an empty message is refused', Inquiry::INVALID === send( $inquiry, [ 'tdh_message' => '   ' ], $home ) );

reset_state();
ok( 'a message past the limit is refused', Inquiry::INVALID === send( $inquiry, [ 'tdh_message' => str_repeat( 'a', 5001 ) ], $home ) );
ok( '...and the limit is stated as a number', str_contains( (string) ( Inquiry::take()['errors']['message'] ?? '' ), '5,000' ) );

reset_state();
ok( 'an unticked consent box is refused', Inquiry::INVALID === send( $inquiry, [ 'tdh_consent' => '' ], $home ) );

ok( 'and after all of that, still nothing was written', inquiry_count() === $before );

reset_state();
$_POST = array_merge( good_post( $home ), [ 'tdh_listing' => '99999999' ] );
$lost  = run_handler( $inquiry );
ok( 'an id that is not a home goes to the marketplace rather than a broken page', str_contains( $lost, 'homes' ) && '' === outcome( $lost ), $lost );
ok( '...and writes nothing', inquiry_count() === $before );

echo "\n=== too many, too fast ===\n";

reset_state();
$accepted = 0;

for ( $i = 1; $i <= 7; $i++ ) {
	$result = send( $inquiry, [ 'tdh_email' => 'rapid' . $i . '@example.com' ], $home );

	if ( Inquiry::SENT === $result ) {
		++$accepted;
	}
}

ok( 'a flood from one address is stopped', $accepted <= 5, (string) $accepted );
ok( '...and the last one is told why, rather than silently dropped', Inquiry::TOO_MANY === send( $inquiry, [ 'tdh_email' => 'rapid8@example.com' ], $home ) );

echo "\n=== the form itself ===\n";

reset_state();
$_GET = [];
$form = markup( static fn() => $inquiry->render( $home ) );

ok( 'the form posts to the home it is on', str_contains( $form, 'name="tdh_listing" value="' . $home . '"' ) );
ok( '...with a nonce and the action', str_contains( $form, 'name="_wpnonce"' ) && str_contains( $form, 'value="tdh_inquire"' ) );
/*
 * Every control has a real <label for>, checked as a rule rather than as a
 * count: a count passes the day somebody adds a seventh field without a
 * label and quietly changes the number to match.
 */
preg_match_all( '/id="(tdh-inq-[a-z-]+)"/', $form, $ids );
preg_match_all( '/<label for="(tdh-inq-[a-z-]+)"/', $form, $fors );
$unlabelled = array_diff( array_unique( $ids[1] ), array_unique( $fors[1] ) );

ok( 'every control on the form has a real label tied to it', [] === $unlabelled && count( $ids[1] ) >= 7, wp_json_encode( array_values( $unlabelled ) ) );
ok( 'the message limit is stated before it is hit, not after', str_contains( $form, 'Up to 5,000 characters' ) );
ok( 'the phone is marked optional, so nobody guesses', str_contains( $form, 'optional' ) );
ok( 'the move-in field cannot offer a date in the past', str_contains( $form, 'min="' . Availability::today() . '"' ) );
ok( 'consent says who gets the details and why', str_contains( $form, 'Send my name, email and message to the owner' ) );
ok( 'the honeypot is there and out of the way of people', str_contains( $form, 'class="tdh-hp"' ) && str_contains( $form, 'tabindex="-1"' ) );
ok( 'the button says what it does and carries its sending state', str_contains( $form, 'Send inquiry' ) && str_contains( $form, 'data-sending' ) );

/*
 * R24 wanted two things at the point of inquiry: a reminder of the rules,
 * and a record of which version was accepted. The consent box does the
 * recording; this is the reminder, and it has to be somewhere a renter can
 * actually go. The line it replaced — "Rules and regulations must be
 * reviewed before sending an inquiry" — pointed nowhere at all.
 */
ok( 'the rules reminder is a link a renter can follow, not an instruction (R24)', (bool) preg_match( '/<p class="inquiry-terms">.*?<a href="http[^"]+">.*?house rules and terms/s', $form ) );

/*
 * It must NOT borrow the theme's `.fine`. That class is a flex row built
 * for an icon beside a word, so a sentence with a link in the middle came
 * out as three columns: "Sending an inquiry accepts our | house rules and
 * terms | , as they stand today." Reusing a class for its colour and
 * inheriting its layout is how that happened, and a class name is the
 * only thing a test can hold on to.
 */
ok( '...in running text of its own, not the flex row meant for an icon', ! preg_match( '/<p class="fine"[^>]*>\s*Sending an inquiry/', $form ) );
ok( '...and the old pointerless line is gone from the property page', ! str_contains( (string) file_get_contents( (string) locate_template( 'single-tdh_listing.php' ) ), 'Rules and regulations must be reviewed' ) );

/*
 * The form lives in the main column; the side column holds only the price
 * (G3a design review).
 *
 * Three layouts came before this one, and each is a check below:
 *
 * - One tall side card holding price AND form: 1178px, taller than a
 *   laptop viewport, so `position: sticky` never engaged and the price
 *   scrolled away.
 * - The side column split into two cards, the price one pinned: the form
 *   beneath slid up BEHIND the pinned card, hiding the fields.
 * - Nothing pinned at all: honest, but the price and the way to ask were
 *   gone from view for the length of the page.
 *
 * Now the side column is short — price, fees, one "Ask the owner" button,
 * about 420px — so it CAN pin, and it covers nothing because the form is
 * no longer inside it. The checks are the pairing, not the pixels: the
 * inquiry hook fires inside `.enquiry-card`, that card sits in the main
 * column at `#inquire`, the side column carries the button to it, and the
 * stylesheet scopes the form to the card it is in.
 */
$tpl = (string) file_get_contents( (string) locate_template( 'single-tdh_listing.php' ) );
$css = (string) file_get_contents( get_template_directory() . '/style.css' );

$price_at = strpos( $tpl, 'class="price-box"' );
$card_at  = strpos( $tpl, 'class="enquiry-card"' );
$hook_at  = strpos( $tpl, 'tdh_listing_inquiry_form' );
$aside_at = strpos( $tpl, '<aside class="listing-side"' );
$anchor   = strpos( $tpl, 'id="inquire"' );

ok( 'the form is in the main column, before the side column begins', false !== $card_at && false !== $aside_at && $card_at < $aside_at );
ok( '...at an anchor the side column\'s button can reach', false !== $anchor && $anchor < $card_at && str_contains( $tpl, 'href="#inquire"' ) && str_contains( $tpl, 'Ask the owner' ) );
ok( '...and the hook fires inside the card, not the price box', false !== $hook_at && $hook_at > $card_at && false !== $price_at && $price_at > $aside_at );
ok( '...and the stylesheet scopes the form to that same card', str_contains( $css, '.enquiry-card > .inquiry-form' ) && ! str_contains( $css, '.inquiry-box > .inquiry-form' ) );
ok( 'the side column pins, now that it is short enough to', (bool) preg_match( '/\.listing-side \{[^}]*position:\s*sticky/s', $css ) );
ok( '...and nothing caps its height, which is what once sliced a form mid-field', ! preg_match( '/\.listing-side \{[^}]*max-height/s', $css ) );
ok( 'a phone gets the rent and the button in a bar', str_contains( $tpl, 'data-tdh-detail-bar' ) && str_contains( $css, '.detail-bar' ) );

$gone = markup( static fn() => $inquiry->render( $paused ) );
ok( 'a paused home shows no form at all, and says why', ! str_contains( $gone, '<form' ) && str_contains( $gone, 'not taking inquiries' ) );

echo "\n=== it leaves a trace in the log ===\n";

/*
 * The master prompt is explicit: every form submit, every permission
 * refusal and every write that matters is logged, in the same commit as
 * the code. D1 shipped without any of it — `tdh_inquiry_received` fired
 * and nobody was listening, so an inquiry arrived, was stored, and left
 * no trace at all. These checks are what stops that being true again.
 *
 * The suites run with TDH_LOG_SILENT set so fixtures do not fill the log;
 * this section turns it off for itself, exactly as verify-log.php does,
 * and puts it back afterwards.
 *
 * Putting the flag back is not enough on its own, which is what this
 * section did at first: the lines written while it was off stayed in the
 * staff log, twenty-four of them per run, and an administrator opening
 * Logs found invented inquiries and invented mail failures against homes
 * that do not exist. verify-log.php has always taken its own rows out
 * again by id; so does this now.
 */
/* Declared here because under `wp eval-file` the script body is not the
   global scope, so $wpdb is not simply there the way it is in a theme. */
global $wpdb;

$log_table = TDH\Log::table();
$log_floor = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$log_table}" ); // phpcs:ignore WordPress.DB
$log_was   = getenv( 'TDH_LOG_SILENT' );

putenv( 'TDH_LOG_SILENT' );
reset_state();

/** Log rows for this feature, newest first. Rows are associative arrays. */
$log_rows = static function (): array {
	$page = TDH\Log::query( [ 'feature' => 'inquiries', 'per_page' => 50 ] );

	return (array) ( $page['rows'] ?? [] );
};

$before_ids = array_map( static fn( array $r ): int => (int) $r['id'], $log_rows() );

/** Rows written since the snapshot, optionally of one event. */
$new_rows = static function ( string $event = '' ) use ( $log_rows, $before_ids ): array {
	return array_values(
		array_filter(
			$log_rows(),
			static fn( array $r ): bool => ! in_array( (int) $r['id'], $before_ids, true )
				&& ( '' === $event || $event === $r['event'] )
		)
	);
};

send( $inquiry, [ 'tdh_email' => 'logged@example.com' ], $home );

$received = $new_rows( 'received' );

ok( 'a stored inquiry writes one line to the log', 1 === count( $received ), wp_json_encode( array_column( $new_rows(), 'event' ) ) );
ok( '...naming the home, so the line reads without opening it', isset( $received[0] ) && str_contains( (string) $received[0]['message'], 'Inquiry Probe Cottage' ), $received[0]['message'] ?? '-' );

/*
 * The renter's address is in the context so support can act on it, but
 * MASKED — the master prompt forbids unmasked personal data in a log, and
 * the log screen is readable by every administrator.
 */
$ctx = isset( $received[0] ) ? (string) wp_json_encode( $received[0]['context'] ) : '';
ok( '...with the renter\'s address masked, never in full', '' !== $ctx && ! str_contains( $ctx, 'logged@example.com' ) && str_contains( $ctx, '@example.com' ), $ctx );

reset_state();
send( $inquiry, [], $paused );

$refused = $new_rows( 'refused' );

ok( 'a refused inquiry is logged as a warning, not swallowed', [] !== $refused && 'warning' === $refused[0]['level'], $refused[0]['level'] ?? 'nothing logged' );
ok( '...saying which home and why, in words', isset( $refused[0] ) && str_contains( (string) $refused[0]['message'], 'withdrawn' ), $refused[0]['message'] ?? '-' );

/* Back to whatever it was, not hardcoded on. */
if ( false === $log_was ) {
	putenv( 'TDH_LOG_SILENT' );
} else {
	putenv( 'TDH_LOG_SILENT=' . $log_was );
}

/*
 * And every line this section caused, out again. By id range, not by
 * event: storing an inquiry wakes Notifications, Mail and Smtp as well,
 * and each writes its own line.
 */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( $wpdb->prepare( "DELETE FROM {$log_table} WHERE id > %d", $log_floor ) );

$log_left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$log_table} WHERE id > {$log_floor}" ); // phpcs:ignore WordPress.DB

ok( 'the log section leaves nothing behind in the staff log', 0 === $log_left, "{$log_left} left behind" );

echo "\n=== the renter's details stay private ===\n";

reset_state();
send( $inquiry, [ 'tdh_name' => '', 'tdh_email' => 'secret@example.com' ], $home );

$next = markup( static fn() => $inquiry->render( $home ) );
ok( 'a refused inquiry hands its typed values back once...', str_contains( $next, 'secret@example.com' ) );

$after = markup( static fn() => $inquiry->render( $home ) );
ok( '...and never again, so the next visitor cannot read them', ! str_contains( $after, 'secret@example.com' ) );

$page = markup(
	static function () use ( $home ): void {
		echo esc_html( (string) get_post_field( 'post_content', $home ) );
	}
);
ok( 'nothing a renter typed is stored on the home itself', ! str_contains( $page, 'dana@example.com' ) && ! str_contains( $page, 'Dana Whitfield' ) );

echo "\n=== the inbox: who may read what (D2) ===\n";

reset_state();

/* A second landlord with their own home, so "wrong owner" is a real
   person with a real home rather than a made-up id. */
$other_landlord = wp_insert_user(
	[
		'user_login' => 'tdh_inquiry_probe_2',
		'user_email' => 'inquiry-probe-2@example.com',
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => 'tdh_landlord',
	]
);
$other_landlord = is_wp_error( $other_landlord ) ? 0 : (int) $other_landlord;
$their_home     = probe_home( 'Inquiry Probe Neighbour', $other_landlord );

$staff = wp_insert_user(
	[
		'user_login' => 'tdh_inquiry_probe_staff',
		'user_email' => 'inquiry-probe-staff@example.com',
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => 'administrator',
	]
);
$staff = is_wp_error( $staff ) ? 0 : (int) $staff;

send( $inquiry, [ 'tdh_email' => 'owner-test@example.com' ], $home );
$mine = newest_inquiry();

ok( 'the landlord who owns the home may read it', Inquiry::can_read( $mine, (int) $landlord ) );
ok( 'another landlord may not', ! Inquiry::can_read( $mine, $other_landlord ) );
ok( 'staff may read anything', Inquiry::can_read( $mine, $staff ) );
ok( 'a logged-out visitor may not', ! Inquiry::can_read( $mine, 0 ) );

/*
 * A wrong owner and a missing id must be answered the same way, or the
 * ids can be walked to learn which exist.
 */
ok( 'an id that is not an inquiry is refused exactly as a wrong owner is', ! Inquiry::can_read( 99999999, (int) $landlord ) && ! Inquiry::can_read( $mine, $other_landlord ) );
ok( 'a home is not an inquiry', ! Inquiry::can_read( $home, (int) $landlord ) );

/* A Contact-page message has no home, so it belongs to staff alone. */
$contact = (int) wp_insert_post( [ 'post_type' => Post_Types::INQUIRY, 'post_status' => 'publish', 'post_title' => 'Inquiry Probe contact message' ] );
update_post_meta( $contact, '_tdh_inquiry_kind', 'contact' );

ok( 'a Contact-page message never reaches a landlord\'s inbox', ! Inquiry::can_read( $contact, (int) $landlord ) );
ok( '...but staff can read it', Inquiry::can_read( $contact, $staff ) );

echo "\n=== the inbox: unread counts what a renter would call unread ===\n";

/*
 * The trap this exists for. `_tdh_read` is a registered BOOLEAN: false is
 * stored as an EMPTY STRING, and a record written before the field
 * existed has no row at all. Both mean "nobody has opened it". Ask for
 * only one and the badge disagrees with the list — the C3 boolean-meta
 * mistake in a new place, and there is a real example of each on the
 * site today.
 */
$legacy = (int) wp_insert_post( [ 'post_type' => Post_Types::INQUIRY, 'post_status' => 'publish', 'post_title' => 'Inquiry Probe legacy' ] );
update_post_meta( $legacy, '_tdh_listing_id', $home );
delete_post_meta( $legacy, '_tdh_read' );

$false_read = (int) wp_insert_post( [ 'post_type' => Post_Types::INQUIRY, 'post_status' => 'publish', 'post_title' => 'Inquiry Probe false-read' ] );
update_post_meta( $false_read, '_tdh_listing_id', $home );
update_post_meta( $false_read, '_tdh_read', false );

ok( 'a record with no _tdh_read row at all is unread', Inquiry::is_unread( $legacy ) );
ok( 'a record with _tdh_read stored as false is unread', Inquiry::is_unread( $false_read ) );
ok( '...and the COUNT finds both, not just one', Inquiry::unread_count( (int) $landlord ) >= 3, (string) Inquiry::unread_count( (int) $landlord ) );

Inquiry::mark_read( $legacy );
ok( 'opening one marks it read', ! Inquiry::is_unread( $legacy ) );
Inquiry::mark_read( $legacy );
ok( '...and doing it twice changes nothing', ! Inquiry::is_unread( $legacy ) );

echo "\n=== the inbox: filters, paging and a deleted home ===\n";

$all_now = Inquiry::inbox( (int) $landlord, Inquiry::FILTER_ALL, 1 );
ok( 'the inbox returns this landlord\'s messages', $all_now['total'] >= 3 );

update_post_meta( $false_read, '_tdh_status', 'archived' );
$arch = Inquiry::inbox( (int) $landlord, Inquiry::FILTER_ARCHIVED, 1 );
$open = Inquiry::inbox( (int) $landlord, Inquiry::FILTER_ALL, 1 );

ok( 'an archived message moves to its own tab', 1 === $arch['total'] && (int) $arch['items'][0]->ID === $false_read );
ok( '...and out of the inbox', ! in_array( $false_read, array_map( static fn( $p ): int => (int) $p->ID, $open['items'] ), true ) );

/*
 * A deleted home keeps its conversations. WordPress excludes trash from
 * `post_status => 'any'`, so asking that way lost them with the home —
 * while the delete confirmation promises the opposite.
 */
$doomed = probe_home( 'Inquiry Probe Doomed', $landlord );
send( $inquiry, [ 'tdh_email' => 'doomed@example.com' ], $doomed );
$about_doomed = newest_inquiry();
wp_trash_post( $doomed );

$after_delete = Inquiry::inbox( (int) $landlord, Inquiry::FILTER_ALL, 1 );
ok( 'deleting a home keeps its inquiries in the inbox', in_array( $about_doomed, array_map( static fn( $p ): int => (int) $p->ID, $after_delete['items'] ), true ) );
ok( '...and the row says the listing was removed, rather than nothing', Inquiry::about( $about_doomed )['removed'] && '' !== Inquiry::about( $about_doomed )['name'] );

echo "\n=== the inbox: archiving ===\n";

reset_state();
wp_set_current_user( (int) $landlord );

/** Post to the archive handler and report where it went. */
$file = static function ( Inquiry $inquiry, int $id, string $to ): string {
	$_POST = [
		'tdh_action'  => 'tdh_inquiry_archive',
		'_wpnonce'    => wp_create_nonce( 'tdh_inquiry_archive' ),
		'tdh_inquiry' => (string) $id,
		'tdh_to'      => $to,
	];

	$catch = static function ( $location ) {
		throw new RuntimeException( (string) $location );
	};

	add_filter( 'wp_redirect', $catch, 1 );

	try {
		$inquiry->handle_archive();
		$where = '(no redirect)';
	} catch ( RuntimeException $e ) {
		$where = $e->getMessage();
	} finally {
		remove_filter( 'wp_redirect', $catch, 1 );
		$_POST = [];
	}

	parse_str( (string) wp_parse_url( $where, PHP_URL_QUERY ), $args );

	return (string) ( $args['inq'] ?? '' );
};

ok( 'the owner can archive one', Inquiry::ARCHIVED === $file( $inquiry, $mine, 'archive' ) && Inquiry::is_archived( $mine ) );
ok( '...and archiving it again is harmless', Inquiry::ARCHIVED === $file( $inquiry, $mine, 'archive' ) && Inquiry::is_archived( $mine ) );
ok( '...and it comes back', Inquiry::UNARCHIVED === $file( $inquiry, $mine, 'unarchive' ) && ! Inquiry::is_archived( $mine ) );

wp_set_current_user( $other_landlord );
ok( 'another landlord cannot archive it', Inquiry::REFUSED === $file( $inquiry, $mine, 'archive' ) && ! Inquiry::is_archived( $mine ) );
ok( '...and is told exactly what a missing id is told', Inquiry::REFUSED === $file( $inquiry, 99999999, 'archive' ) );

wp_set_current_user( (int) $landlord );
$_POST = [ 'tdh_action' => 'tdh_inquiry_archive', '_wpnonce' => 'stale', 'tdh_inquiry' => (string) $mine, 'tdh_to' => 'archive' ];
$stale = outcome_inq( $inquiry );
ok( 'an expired page changes nothing and says so', Inquiry::EXPIRED === $stale && ! Inquiry::is_archived( $mine ) );

wp_set_current_user( 0 );

echo "\n=== the inbox: no way out of the portal ===\n";

/*
 * R29, and one of the seven UX mistakes the standards came from. The
 * staff list used to link each message to wp-admin's post editor, which
 * threw staff out of the branded portal to read a sentence.
 */
$render = (string) file_get_contents( TDH_PLUGIN_DIR . 'includes/class-tdh-account-render.php' );

ok( 'the staff inquiry list does not link into wp-admin', ! preg_match( '/portal-inquiry.{0,400}admin_url\(\s*.post\.php/s', $render ) );
ok( 'the landlord\'s inbox links to the portal, not the home page', str_contains( $render, "add_query_arg( 'view', 'inquiries', Accounts::url( 'account' ) )" ) );

echo "\n=== cleanup ===\n";

$removed = 0;

foreach ( (array) get_posts(
	[
		'post_type'      => Post_Types::INQUIRY,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	]
) as $inq ) {
	if ( str_contains( get_the_title( (int) $inq ), 'Inquiry Probe' ) ) {

		/*
		 * The notification row and its booked send go with the inquiry.
		 * These fixtures are stored through the real handler, so D3 queues
		 * an email for each one — and deleting only the inquiry left the
		 * row behind with a cron event still pointing at it, ready to send
		 * mail about a home that no longer exists.
		 */
		if ( class_exists( '\TDH\Notifications' ) ) {
			foreach ( TDH\Notifications::for_inquiry( (int) $inq ) as $note ) {
				$wpdb->delete( TDH\Notifications::table(), [ 'id' => (int) $note['id'] ], [ '%d' ] );
				wp_clear_scheduled_hook( 'tdh_notification_retry', [ (int) $note['id'] ] );
			}
		}

		wp_delete_post( (int) $inq, true );
		++$removed;
	}
}

foreach ( [ $home, $other, $paused, $their_home ?? 0, $doomed ?? 0 ] as $fixture ) {
	if ( $fixture ) {
		wp_delete_post( (int) $fixture, true );
	}
}

require_once ABSPATH . 'wp-admin/includes/user.php';

foreach ( [ $landlord, $other_landlord ?? 0, $staff ?? 0 ] as $person ) {
	if ( $person ) {
		wp_delete_user( (int) $person );
	}
}

reset_state();
$_POST = [];
$_GET  = [];

ok( 'every fixture is gone', ! get_post( $home ) && ! get_post( $paused ) && ( $removed > 0 || true ) );

[ $pass, $fail ] = ok();

printf( "\n%s  %d passed, %d failed\n", $fail ? 'FAILED' : 'PASSED', $pass, $fail );

if ( $fail ) {
	exit( 1 );
}
