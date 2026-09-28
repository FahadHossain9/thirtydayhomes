<?php
/**
 * Availability: dates, the periods a landlord types, what "free" means,
 * the first move-in day, the calendar, the listing form's step 2, the quick
 * edit on My listings, and the property page.
 *
 * Run through verify.bat, or directly:
 *     wp eval-file wp-content/plugins/thirtydayhomes-core/tools/verify-availability.php
 */

use TDH\Account_Render;
use TDH\Availability;
use TDH\Listing_Actions;
use TDH\Listing_Form;
use TDH\Listing_Form_Render;
use TDH\Membership;
use TDH\Statuses;

function ok( string $label = '', bool $cond = false, string $detail = '' ): array {
	static $p = 0, $f = 0;
	if ( '' === $label ) { return [ $p, $f ]; }
	if ( $cond ) { ++$p; printf( "  ok    %s\n", $label ); }
	else { ++$f; printf( "  FAIL  %s%s\n", $label, '' !== $detail ? "  --> {$detail}" : '' ); }
	return [ $p, $f ];
}

/** Run a handler and return where it redirected. */
function redirect_of( callable $handler ): string {
	$catch = static function ( $location ) { throw new RuntimeException( (string) $location ); };
	add_filter( 'wp_redirect', $catch, 1 );
	try {
		$handler();
		$where = '(no redirect)';
	} catch ( RuntimeException $e ) {
		$where = $e->getMessage();
	} finally {
		remove_filter( 'wp_redirect', $catch, 1 );
	}
	return $where;
}

// A fixed "today", so every date below means the same thing on any day.
$today = '2026-09-20';
add_filter( 'tdh_availability_today', static fn() => $today );

$week_option = get_option( 'start_of_week' );
update_option( 'start_of_week', 0 ); // Sunday first, for the calendar checks.

echo "\n=== dates ===\n";

ok( 'a real date is a date', Availability::is_date( '2026-02-28' ) );
ok( '30 February is not', ! Availability::is_date( '2026-02-30' ) );
ok( 'nor is anything written another way', ! Availability::is_date( '26-1-1' ) && ! Availability::is_date( '2026/01/01' ) && ! Availability::is_date( '' ) );
ok( 'the horizon is three years from today', '2029-09-20' === Availability::horizon() );
ok( 'one day reads as one day', '25 Dec 2026' === Availability::format_range( [ 'from' => '2026-12-25', 'to' => '2026-12-25' ] ), Availability::format_range( [ 'from' => '2026-12-25', 'to' => '2026-12-25' ] ) );
ok( 'same month: "15 – 20 Dec 2026"', '15 – 20 Dec 2026' === Availability::format_range( [ 'from' => '2026-12-15', 'to' => '2026-12-20' ] ), Availability::format_range( [ 'from' => '2026-12-15', 'to' => '2026-12-20' ] ) );
ok( 'same year: "15 Nov – 5 Dec 2026"', '15 Nov – 5 Dec 2026' === Availability::format_range( [ 'from' => '2026-11-15', 'to' => '2026-12-05' ] ) );
ok( 'across a new year, both years', '15 Dec 2026 – 5 Jan 2027' === Availability::format_range( [ 'from' => '2026-12-15', 'to' => '2027-01-05' ] ) );

echo "\n=== stored periods ===\n";

$parsed = Availability::parse( "2026-12-15 to 2027-01-05\nnonsense\n2026-11-01 to 2026-10-01\n2026-10-10\n2026-12-01 to 2026-12-16\n2027-01-06 to 2027-01-09" );
ok( 'a hand-edited value is read forgivingly: bad and backwards lines skipped', 2 === count( $parsed ), wp_json_encode( $parsed ) );
ok( '...a lone date is a single day', '2026-10-10' === ( $parsed[0]['from'] ?? '' ) && '2026-10-10' === ( $parsed[0]['to'] ?? '' ) );
ok( '...overlapping and touching periods are joined, and sorted', '2026-12-01' === ( $parsed[1]['from'] ?? '' ) && '2027-01-09' === ( $parsed[1]['to'] ?? '' ) );
ok( 'stored as one readable line per period', "2026-10-10 to 2026-10-10\n2026-12-01 to 2027-01-09" === Availability::serialise( $parsed ) );
ok( 'and read back the same', Availability::parse( Availability::serialise( $parsed ) ) === $parsed );

echo "\n=== what a landlord types ===\n";

$c = Availability::check( [ [ 'from' => '2027-01-15', 'to' => '2027-01-05' ] ] );
ok( 'a period that ends before it starts is refused, and says why', [] === $c['ranges'] && str_contains( $c['errors'][0] ?? '', 'ends before it starts' ), $c['errors'][0] ?? '' );
ok( '...and the typed dates are kept for the form', '2027-01-15' === ( $c['refused'][0]['from'] ?? '' ) && '2027-01-05' === ( $c['refused'][0]['to'] ?? '' ) );

$c = Availability::check( [ [ 'from' => '2026-12-15', 'to' => '' ] ] );
ok( 'half a period is refused, naming the day given', [] === $c['ranges'] && str_contains( $c['errors'][0] ?? '', '15 Dec 2026' ) && str_contains( $c['errors'][0] ?? '', 'no last day' ) );

$c = Availability::check( [ [ 'from' => '2026-02-30', 'to' => '2026-03-02' ] ] );
ok( 'a date that does not exist is refused', [] === $c['ranges'] && str_contains( $c['errors'][0] ?? '', '2026-02-30' ) );

$c = Availability::check( [ [ 'from' => '2030-01-01', 'to' => '2030-01-10' ] ] );
ok( 'more than three years ahead is refused as a likely typo', [] === $c['ranges'] && str_contains( $c['errors'][0] ?? '', 'check the year' ) );

$c = Availability::check( [ [ 'from' => '2026-08-01', 'to' => '2026-08-31' ] ] );
ok( 'a period in the past is kept (history)', 1 === count( $c['ranges'] ) && [] === $c['errors'] );

$c = Availability::check( [ [ 'from' => '2026-12-25', 'to' => '2026-12-25' ] ] );
ok( 'a single day (same date twice) is fine', [ [ 'from' => '2026-12-25', 'to' => '2026-12-25' ] ] === $c['ranges'] );

$c = Availability::check( [ [ 'from' => '2026-12-10', 'to' => '2026-12-20' ], [ 'from' => '2026-12-15', 'to' => '2026-12-31' ], [ 'from' => '', 'to' => '' ] ] );
ok( 'overlapping periods are joined into one', [ [ 'from' => '2026-12-10', 'to' => '2026-12-31' ] ] === $c['ranges'] );
ok( '...and the landlord is told, with the result', 1 === count( $c['notes'] ) && str_contains( $c['notes'][0], '10 – 31 Dec 2026' ), $c['notes'][0] ?? '' );
ok( 'an empty row is simply skipped', [] === $c['errors'] );

$c = Availability::check( [ [ 'from' => '2026-12-01', 'to' => '2026-12-09' ], [ 'from' => '2026-12-10', 'to' => '2026-12-12' ] ] );
ok( 'touching periods are joined too, and said', [ [ 'from' => '2026-12-01', 'to' => '2026-12-12' ] ] === $c['ranges'] && 1 === count( $c['notes'] ) );

$many = [];
for ( $i = 0; $i < Availability::MAX_RANGES + 1; $i++ ) {
	$d      = Availability::add_days( '2026-10-01', $i * 3 );
	$many[] = [ 'from' => $d, 'to' => $d ];
}
$c = Availability::check( $many );
ok( 'at the limit: ' . Availability::MAX_RANGES . ' kept, the next one refused and named', Availability::MAX_RANGES === count( $c['ranges'] ) && 1 === count( $c['refused'] ) && str_contains( $c['errors'][0] ?? '', (string) Availability::MAX_RANGES ) );

$f = Availability::check_from( '' );
ok( 'a blank available-from clears it (free now)', '' === $f['value'] && '' === $f['error'] );
$f = Availability::check_from( 'next week' );
ok( 'an unreadable available-from is refused and left alone', null === $f['value'] && str_contains( $f['error'], 'next week' ) );
$f = Availability::check_from( '2031-01-01' );
ok( 'an available-from years away is refused', null === $f['value'] && str_contains( $f['error'], 'check the year' ) );
ok( 'a real one is accepted', '2026-11-01' === Availability::check_from( '2026-11-01' )['value'] );

echo "\n=== is it free? ===\n";

$block = [ [ 'from' => '2026-12-15', 'to' => '2027-01-05' ] ];
$fits  = static fn( string $start, string $end, string $from = '2026-10-01', int $min = 30 ): bool => Availability::fits( $block, $from, $min, $start, $end );

ok( 'before available-from: no', ! $fits( '2026-09-25', '2026-11-30' ) );
ok( 'a stay across the taken period: no', ! $fits( '2026-12-01', '2027-02-01' ) );
ok( 'moving out on the first taken day: yes (the turnover day is shared)', $fits( '2026-11-01', '2026-12-15' ) );
ok( 'moving in the day after it ends: yes', $fits( '2027-01-06', '2027-02-10' ) );
ok( 'moving in on its last day: no', ! $fits( '2027-01-05', '2027-02-10' ) );
ok( 'shorter than the minimum stay: no', ! $fits( '2026-10-01', '2026-10-20' ) );
ok( 'exactly the minimum stay: yes', $fits( '2026-10-01', '2026-10-31' ) );
ok( 'out before in: no', ! $fits( '2026-11-10', '2026-11-01' ) );
ok( 'no dates, no minimum, nothing taken: yes', Availability::fits( [], '', 0, '2026-10-01', '2026-10-02' ) );

echo "\n=== the first day a renter could move in ===\n";

ok( 'available-from inside a taken period: the day after it ends', '2026-11-11' === Availability::first_move_in( [ [ 'from' => '2026-10-20', 'to' => '2026-11-10' ] ], '2026-11-01', 30, $today ) );
ok( 'a gap too short for the minimum stay is skipped', '2027-01-01' === Availability::first_move_in( [ [ 'from' => '2026-09-25', 'to' => '2026-10-10' ], [ 'from' => '2026-10-20', 'to' => '2026-12-31' ] ], '', 30, $today ) );
ok( 'nothing open before the horizon: none', '' === Availability::first_move_in( [ [ 'from' => $today, 'to' => '2029-09-25' ] ], '', 30, $today ) );
ok( 'an available-from in the past means today', $today === Availability::first_move_in( [], '2026-08-01', 30, $today ) );

echo "\n=== a home, in words and as a calendar ===\n";

$owner    = 0;
$stranger = 0;
$created  = [];

$make_user = static function ( string $login ): int {
	$existing = get_user_by( 'login', $login );
	if ( $existing ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $existing->ID );
	}
	$id = wp_insert_user( [ 'user_login' => $login, 'user_email' => $login . '@example.com', 'user_pass' => wp_generate_password( 20 ), 'role' => 'tdh_landlord', 'display_name' => 'Avery Probe' ] );
	if ( is_wp_error( $id ) ) {
		return 0;
	}
	update_user_meta( (int) $id, Membership::META_STATUS, Membership::ACTIVE );
	update_user_meta( (int) $id, Membership::META_QUOTA, 20 );
	update_user_meta( (int) $id, Membership::META_EXPIRES, strtotime( '+30 days' ) );
	return (int) $id;
};

$owner    = $make_user( 'tdh_avail_owner' );
$stranger = $make_user( 'tdh_avail_stranger' );

$home = static function ( string $status, string $title, int $author = 0 ) use ( &$created, &$owner ): int {
	$id = (int) wp_insert_post( [ 'post_type' => 'tdh_listing', 'post_status' => $status, 'post_title' => $title, 'post_author' => $author ?: $owner ] );
	update_post_meta( $id, '_tdh_price_monthly', 1800 );
	update_post_meta( $id, '_tdh_min_stay_days', 30 );
	$created[] = $id;
	return $id;
};

$live = $home( 'publish', 'Avail probe live' );
update_post_meta( $live, Availability::META, "2026-08-01 to 2026-08-31\n2026-12-15 to 2027-01-05" );

$s = Availability::summary( $live );
ok( 'no available-from and nothing taken now: "Available now"', 'Available now' === $s['headline'] && $s['now'] );
ok( 'upcoming periods are listed for renters', [ '15 Dec 2026 – 5 Jan 2027' ] === $s['upcoming'], wp_json_encode( $s['upcoming'] ) );
ok( 'a past period is history, not shown to renters', [ '1 – 31 Aug 2026' ] === $s['past'] );

update_post_meta( $live, '_tdh_available_from', '2026-11-01' );
$s = Availability::summary( $live );
ok( 'with a future available-from: "Available from 1 Nov 2026"', 'Available from 1 Nov 2026' === $s['headline'], $s['headline'] );
ok( '...and the card says "Available 1 Nov"', 'Available 1 Nov' === $s['short'], $s['short'] );
ok( 'is_free() reads the home', Availability::is_free( $live, '2026-11-01', '2026-12-10' ) && ! Availability::is_free( $live, '2026-10-01', '2026-11-15' ) );

$months = Availability::months( $live );
ok( 'the calendar starts at the month it can first be lived in', 'November 2026' === ( $months[0]['label'] ?? '' ) );
ok( '...and runs ' . Availability::CALENDAR_MONTHS . ' months', Availability::CALENDAR_MONTHS === count( $months ) );

$cell = static function ( array $months, string $date ): ?array {
	foreach ( $months as $m ) {
		foreach ( $m['weeks'] as $week ) {
			foreach ( $week as $c ) {
				if ( $c && $c['date'] === $date ) {
					return $c;
				}
			}
		}
	}
	return null;
};

ok( 'a taken day is unavailable', 'unavailable' === ( $cell( $months, '2026-12-20' )['state'] ?? '' ) );
ok( 'a free day is free', 'free' === ( $cell( $months, '2026-11-20' )['state'] ?? '' ) );
ok( 'November 2026 starts on a Sunday, in the first column', 1 === ( $months[0]['weeks'][0][0]['day'] ?? 0 ) );

delete_post_meta( $live, '_tdh_available_from' );
$months = Availability::months( $live );
ok( 'without available-from it starts this month, with today marked', 'September 2026' === $months[0]['label'] && true === ( $cell( $months, $today )['today'] ?? false ) );
ok( 'days before today are past', 'past' === ( $cell( $months, '2026-09-19' )['state'] ?? '' ) );
ok( 'a week starting Sunday: 1 Sep 2026 (a Tuesday) sits in the third column', 1 === ( $months[0]['weeks'][0][2]['day'] ?? 0 ) && null === $months[0]['weeks'][0][1] );

echo "\n=== the listing form, step 2 ===\n";

wp_set_current_user( $owner );
$form  = new Listing_Form();
$draft = $home( 'draft', 'Avail probe draft' );
update_post_meta( $draft, '_tdh_utilities_included', 'yes' );
update_post_meta( $draft, '_tdh_pet_policy', 'no' );

$features = static function ( int $id, array $extra ) use ( $form ): string {
	$_GET  = [ 'listing' => (string) $id ];
	$_POST = array_merge(
		[
			'tdh_action'             => 'listing_features',
			'tdh_nonce'              => wp_create_nonce( Listing_Form::NONCE ),
			'tdh_stay'               => '30',
			'tdh_utilities_included' => 'yes',
			'tdh_pets'               => 'no',
			'tdh_sqft'               => '900',
		],
		$extra
	);
	$where = redirect_of( [ $form, 'handle' ] );
	$_POST = [];
	return $where;
};

$_GET = [ 'step' => '1', 'listing' => (string) $draft ];
$page = Listing_Form_Render::form();
ok( 'step 1 no longer asks for "Available from"', ! str_contains( $page, 'name="tdh_available"' ) );

$_GET = [ 'step' => '2', 'listing' => (string) $draft ];
$page = Listing_Form_Render::form();
ok( 'step 2 opens with Availability: the date, the periods, the limit', str_contains( $page, 'name="tdh_available"' ) && str_contains( $page, 'name="tdh_blocked_from[]"' ) && str_contains( $page, 'Unavailable dates' ) && str_contains( $page, 'None yet · up to 20 periods' ) );

$where = $features(
	$draft,
	[
		'tdh_availability' => '1',
		'tdh_available'    => '2026-10-01',
		'tdh_blocked_from' => [ '2026-12-10', '2026-12-15', '2027-03-10', '' ],
		'tdh_blocked_to'   => [ '2026-12-20', '2026-12-31', '2027-03-01', '' ],
	]
);
$errors = Listing_Form::take_errors();
ok( 'a backwards period stops Continue on step 2', str_contains( $where, 'step=2' ) && [] !== $errors, $where );
ok( '...named, with "everything else is saved"', str_contains( implode( ' ', $errors ), 'ends before it starts' ) && str_contains( implode( ' ', $errors ), 'Everything else on this step is saved' ) );
ok( 'the good periods are stored, joined', "2026-12-10 to 2026-12-31" === get_post_meta( $draft, Availability::META, true ) );
ok( 'available-from is stored', '2026-10-01' === get_post_meta( $draft, '_tdh_available_from', true ) );
ok( 'and the rest of the step too', 900 === (int) get_post_meta( $draft, '_tdh_sqft', true ) );

$_GET = [ 'step' => '2', 'listing' => (string) $draft ];
$page = Listing_Form_Render::form();
ok( 'the refused period comes back in its row, with the reason beside it', str_contains( $page, 'value="2027-03-10"' ) && str_contains( $page, 'avail-row-error' ) && str_contains( $page, 'aria-invalid="true"' ) );
ok( 'the joining is said on the page, not done silently', str_contains( $page, 'were joined into one: 10 – 31 Dec 2026' ) );
ok( 'the saved period shows, and the count is right', str_contains( $page, 'value="2026-12-10"' ) && str_contains( $page, '2 of 20 periods' ) );

$where = $features( $draft, [ 'tdh_availability' => '1', 'tdh_available' => '', 'tdh_blocked_from' => [ '' ], 'tdh_blocked_to' => [ '' ] ] );
ok( 'clearing everything clears it: free now, nothing taken', '' === (string) get_post_meta( $draft, '_tdh_available_from', true ) && '' === (string) get_post_meta( $draft, Availability::META, true ) && str_contains( $where, 'step=3' ), $where );

update_post_meta( $draft, Availability::META, '2026-12-01 to 2026-12-05' );
$features( $draft, [] );
ok( 'a form posted without the section leaves the periods alone', '2026-12-01 to 2026-12-05' === get_post_meta( $draft, Availability::META, true ) );

$_GET = [ 'step' => '4', 'listing' => (string) $draft ];
$page = Listing_Form_Render::form();
ok( 'the review has an Availability group with the periods', str_contains( $page, 'lform-review-availability' ) && str_contains( $page, '1 – 5 Dec 2026' ) && str_contains( $page, 'Now — no date given' ) );

$live_form = $home( 'publish', 'Avail probe live form' );
update_post_meta( $live_form, '_tdh_utilities_included', 'yes' );
update_post_meta( $live_form, '_tdh_pet_policy', 'no' );
$where = $features( $live_form, [ 'tdh_availability' => '1', 'tdh_available' => '', 'tdh_blocked_from' => [ '2026-12-24' ], 'tdh_blocked_to' => [ '2026-12-26' ] ] );
ok( 'on a live home it saves straight away — no review, no confirmation', 'publish' === get_post_status( $live_form ) && ! str_contains( $where, 'confirm=1' ) && str_contains( $where, 'updated=1' ) && '2026-12-24 to 2026-12-26' === get_post_meta( $live_form, Availability::META, true ), $where );

echo "\n=== My listings: the quick edit ===\n";

$handler = new Availability();
$quick   = static function ( int $id, array $extra = [], bool $good_nonce = true ) use ( $handler ): string {
	$_POST = array_merge(
		[
			'tdh_action'       => 'listing_availability',
			'tdh_listing'      => (string) $id,
			'tdh_return'       => 'listings',
			'tdh_page'         => '1',
			'tdh_availability' => '1',
			'tdh_nonce'        => $good_nonce ? wp_create_nonce( Availability::NONCE ) : 'expired',
		],
		$extra
	);
	$where = redirect_of( [ $handler, 'handle' ] );
	$_POST = [];
	return $where;
};

$paused  = $home( Statuses::PAUSED, 'Avail probe paused' );
$pending = $home( 'pending', 'Avail probe pending' );
$held    = $home( Statuses::BILLING_HOLD, 'Avail probe held' );

wp_set_current_user( $owner );
$_GET = [ 'view' => 'listings' ];
$rows = Account_Render::dashboard();
ok( 'live, paused and in-review homes offer Availability', substr_count( $rows, 'availability=' ) >= 3 && str_contains( $rows, 'availability=' . $live ) && str_contains( $rows, 'availability=' . $paused ) && str_contains( $rows, 'availability=' . $pending ) );
ok( 'a draft (edited in the wizard) and a payment-held home do not', ! str_contains( $rows, 'availability=' . $draft . '#' ) && ! str_contains( $rows, 'availability=' . $held . '#' ) );

$_GET = [ 'view' => 'listings', 'availability' => (string) $live ];
$rows = Account_Render::dashboard();
ok( 'the panel opens in the row: heading, what renters see, Save and Cancel', str_contains( $rows, 'Availability for “Avail probe live”' ) && str_contains( $rows, 'Renters see now:' ) && str_contains( $rows, 'Save availability' ) && str_contains( $rows, 'is-editing' ) );

$where = $quick( $live, [ 'tdh_available' => '2026-10-15', 'tdh_blocked_from' => [ '2026-12-15', '2027-02-01' ], 'tdh_blocked_to' => [ '2027-01-05', '2027-02-03' ] ] );
ok( 'Save availability stores it and returns to the list, flagged', str_contains( $where, 'tdh_done=availability' ) && str_contains( $where, 'tdh_home=' . $live ) && "2026-12-15 to 2027-01-05\n2027-02-01 to 2027-02-03" === get_post_meta( $live, Availability::META, true ), $where );
ok( '...at the top, where the message is — no jump past it to the row', ! str_contains( $where, '#listing-' ) );
ok( 'the home stays live', 'publish' === get_post_status( $live ) );
$note = Listing_Actions::notice( 'availability', $live );
ok( 'the message says what renters now see', $note && 'success' === $note['type'] && str_contains( $note['text'], 'Renters see: Available from 15 Oct 2026' ), $note['text'] ?? '' );

$quick( $paused, [ 'tdh_blocked_from' => [ '2026-11-01', '2026-11-05' ], 'tdh_blocked_to' => [ '2026-11-10', '2026-11-20' ] ] );
$note = Listing_Actions::notice( 'availability', $paused );
ok( 'on a paused home: "once the home is live", and the joining is said', $note && str_contains( $note['text'], 'once the home is live' ) && str_contains( $note['text'], 'joined into one' ), $note['text'] ?? '' );
ok( 'a paused home stays paused', Statuses::PAUSED === get_post_status( $paused ) );

$before = (string) get_post_meta( $live, Availability::META, true );
$where  = $quick( $live, [ 'tdh_blocked_from' => [ '2027-04-01', '2027-06-10' ], 'tdh_blocked_to' => [ '2027-04-05', '2027-06-01' ] ] );
$kept   = Availability::take_kept( $live );
ok( 'one bad period: the panel opens again', str_contains( $where, 'availability=' . $live ) && ! str_contains( $where, 'tdh_done' ), $where );
ok( '...the good one is saved', '2027-04-01 to 2027-04-05' === get_post_meta( $live, Availability::META, true ) );
ok( '...the bad one is kept, with the reason and "everything else is saved"', '2027-06-10' === ( $kept['rows'][0]['from'] ?? '' ) && str_contains( implode( ' ', $kept['errors'] ), 'ends before it starts' ) && str_contains( implode( ' ', $kept['errors'] ), 'Everything else is saved' ) );

$quick( $live, [ 'tdh_blocked_from' => [ '2027-04-01', '2027-06-10' ], 'tdh_blocked_to' => [ '2027-04-05', '2027-06-01' ] ] );
$_GET = [ 'view' => 'listings', 'availability' => (string) $live ];
$rows = Account_Render::dashboard();
ok( 'the reopened panel shows the error and the typed dates', str_contains( $rows, 'manage-avail-errors' ) && str_contains( $rows, 'value="2027-06-10"' ) && str_contains( $rows, 'value="2027-04-01"' ) );

$stored = (string) get_post_meta( $live, Availability::META, true );
$where  = $quick( $live, [ 'tdh_blocked_from' => [ '2027-05-01' ], 'tdh_blocked_to' => [ '2027-05-09' ] ], false );
$kept   = Availability::take_kept( $live );
ok( 'an expired page saves nothing', $stored === get_post_meta( $live, Availability::META, true ) && str_contains( $where, 'availability=' . $live ), $where );
ok( '...but keeps every typed date, to press Save again', '2027-05-01' === ( $kept['rows'][0]['from'] ?? '' ) && $kept['replace'] && str_contains( implode( ' ', $kept['errors'] ), 'open too long' ) );

wp_set_current_user( $stranger );
$where = $quick( $live, [ 'tdh_blocked_from' => [ '2027-07-01' ], 'tdh_blocked_to' => [ '2027-07-09' ] ] );
ok( 'someone else\'s home: the "missing" answer, nothing stored', str_contains( $where, 'tdh_done=missing' ) && $stored === get_post_meta( $live, Availability::META, true ), $where );

wp_set_current_user( $owner );
$gone = $home( 'publish', 'Avail probe trashed' );
wp_trash_post( $gone );
ok( 'a deleted home: the same "missing" answer', str_contains( $quick( $gone, [ 'tdh_blocked_from' => [ '2027-07-01' ], 'tdh_blocked_to' => [ '2027-07-09' ] ] ), 'tdh_done=missing' ) );

$again = $quick( $live, [ 'tdh_blocked_from' => [ '2027-08-01' ], 'tdh_blocked_to' => [ '2027-08-09' ] ] );
$twice = $quick( $live, [ 'tdh_blocked_from' => [ '2027-08-01' ], 'tdh_blocked_to' => [ '2027-08-09' ] ] );
ok( 'saving the same thing twice (double press, Back and resubmit) is harmless', $again === $twice && '2027-08-01 to 2027-08-09' === get_post_meta( $live, Availability::META, true ) );

echo "\n=== the property page ===\n";

update_post_meta( $live, Availability::META, "2026-08-01 to 2026-08-31\n2026-12-15 to 2027-01-05" );
delete_post_meta( $live, '_tdh_available_from' );

if ( locate_template( 'template-parts/listing-availability.php' ) ) {
	ob_start();
	get_template_part( 'template-parts/listing-availability', null, [ 'id' => $live ] );
	$html = (string) ob_get_clean();

	ok( 'it says when a renter can move in, and the minimum stay', str_contains( $html, 'Available now' ) && str_contains( $html, 'Minimum stay 30 days' ) );
	ok( 'it lists the upcoming taken period', str_contains( $html, 'Unavailable:' ) && str_contains( $html, '15 Dec 2026 – 5 Jan 2027' ) );
	ok( 'it never shows the past one', ! str_contains( $html, 'Aug 2026' ) );
	ok( 'twelve months, each a table with a caption', 12 === substr_count( $html, '<caption>' ) && str_contains( $html, '<caption>September 2026</caption>' ) );
	ok( 'taken days are marked in the markup and for screen readers', str_contains( $html, 'is-unavailable' ) && str_contains( $html, ', unavailable' ) );
	ok( 'today is marked as the current date', str_contains( $html, 'aria-current="date"' ) );
	ok( 'a legend in words, and month buttons hidden until the script adds them', str_contains( $html, 'Unavailable</li>' ) && str_contains( $html, 'data-avail-cal-nav hidden' ) );
	ok( 'no street address anywhere in it', ! str_contains( $html, '_tdh_street_address' ) );

	/*
	 * The legend and the calendar must agree about what a free day looks
	 * like. The swatch beside the word "Available" is a separate rule from
	 * the day cells, so recolouring the cells and forgetting the swatch
	 * leaves a legend describing a calendar that is not on screen — and
	 * nothing about the page looks broken while it does. Both are read out
	 * of the stylesheet and compared.
	 */
	$css  = (string) file_get_contents( get_template_directory() . '/style.css' );
	$swat = preg_match( '/\.avail-cal-legend > li > i \{[^}]*background:\s*var\(\s*(--[a-z0-9-]+)\s*\)/s', $css, $m1 ) ? $m1[1] : '';
	$free = preg_match( '/td\.is-free > span:first-child \{[^}]*background:\s*var\(\s*(--[a-z0-9-]+)\s*\)/s', $css, $m2 ) ? $m2[1] : '';

	ok( 'the "Available" swatch is the colour a free day actually is', '' !== $swat && $swat === $free, $swat . ' vs ' . $free );
} else {
	ok( 'the theme is active (template part found)', false );
}

echo "\n=== cleanup ===\n";

foreach ( $created as $id ) {
	wp_delete_post( $id, true );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( [ $owner, $stranger ] as $uid ) {
	if ( $uid ) {
		wp_delete_user( $uid );
	}
}
update_option( 'start_of_week', $week_option );
$_GET = [];
wp_set_current_user( 0 );
echo "  fixtures removed\n";

[ $passed, $failed ] = ok();
printf( "\n%s  %d passed, %d failed\n", $failed ? 'FAILED' : 'PASSED', $passed, $failed );
exit( $failed ? 1 : 0 );
