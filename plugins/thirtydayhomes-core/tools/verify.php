<?php
/**
 * Local verification — checks the marketplace rules that a page cannot show you.
 *
 * Run it:
 *
 *   cd D:\xampp\htdocs\thirtydayhomes
 *   php D:\xampp\wp-cli.phar eval-file wp-content/plugins/thirtydayhomes-core/tools/verify.php
 *
 * It creates two throwaway landlords and a few posts, asserts the rules, and
 * deletes everything it made. Safe to run against the local demo database.
 * It changes no setting permanently — the one option it touches, blog_public,
 * is restored before it exits.
 *
 * What this covers that clicking around cannot: capability checks are invisible
 * in a browser. A page that hides a link still serves the data if the
 * capability is wrong, so ownership has to be asserted, not eyeballed.
 *
 * @package ThirtyDayHomes
 */

// No declare( strict_types = 1 ) here on purpose: wp-cli runs this through
// eval(), where a strict_types declaration is a fatal error because it is
// not the first statement of the script it ends up inside.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "Run this through WP-CLI.\n" );
}

$GLOBALS['tdh_verify_fail'] = 0;
$GLOBALS['tdh_verify_ran']  = 0;

/**
 * Assert one expectation.
 */
function tdh_check( string $label, bool $got, bool $want = true ): void {

	++$GLOBALS['tdh_verify_ran'];

	$ok = ( $got === $want );

	if ( ! $ok ) {
		++$GLOBALS['tdh_verify_fail'];
	}

	printf( "  %-52s %-9s %s\n", $label, $got ? 'yes' : 'no', $ok ? 'ok' : '<<< WRONG' );
}

function tdh_section( string $title ): void {
	echo "\n" . $title . "\n" . str_repeat( '-', strlen( $title ) ) . "\n";
}

/* ========================================================================
   Fixtures
   ===================================================================== */

$pass = wp_generate_password( 24 );

$landlord_a = (int) wp_insert_user(
	[ 'user_login' => 'tdh_verify_a', 'user_email' => 'verify_a@example.test', 'user_pass' => $pass, 'role' => \TDH\Roles::LANDLORD ]
);
$landlord_b = (int) wp_insert_user(
	[ 'user_login' => 'tdh_verify_b', 'user_email' => 'verify_b@example.test', 'user_pass' => $pass, 'role' => \TDH\Roles::LANDLORD ]
);
$admin = (int) ( get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] ?? 0 );

$live    = (int) wp_insert_post( [ 'post_type' => \TDH\Post_Types::LISTING, 'post_title' => 'Verify live',    'post_status' => 'publish', 'post_author' => $landlord_a ] );
$pending = (int) wp_insert_post( [ 'post_type' => \TDH\Post_Types::LISTING, 'post_title' => 'Verify pending', 'post_status' => 'pending', 'post_author' => $landlord_a ] );
$held    = (int) wp_insert_post( [ 'post_type' => \TDH\Post_Types::LISTING, 'post_title' => 'Verify held',    'post_status' => \TDH\Statuses::BILLING_HOLD, 'post_author' => $landlord_a ] );
$inquiry = (int) wp_insert_post( [ 'post_type' => \TDH\Post_Types::INQUIRY, 'post_title' => 'Verify inquiry', 'post_status' => 'publish' ] );
update_post_meta( $inquiry, '_tdh_listing_id', $live );

/* ========================================================================
   Ownership
   ===================================================================== */

tdh_section( 'Ownership — a landlord reaches only their own records' );

tdh_check( 'owner can edit their live listing',        user_can( $landlord_a, 'edit_tdh_listing', $live ) );
tdh_check( 'owner can edit their pending listing',     user_can( $landlord_a, 'edit_tdh_listing', $pending ) );
tdh_check( 'owner can delete their live listing',      user_can( $landlord_a, 'delete_tdh_listing', $live ) );
tdh_check( 'owner can read an inquiry sent to them',   user_can( $landlord_a, 'read_tdh_inquiry', $inquiry ) );
tdh_check( 'owner CANNOT self-publish',                user_can( $landlord_a, 'publish_tdh_listings' ), false );

tdh_check( 'stranger CANNOT edit it',                  user_can( $landlord_b, 'edit_tdh_listing', $live ), false );
tdh_check( 'stranger CANNOT delete it',                user_can( $landlord_b, 'delete_tdh_listing', $live ), false );
tdh_check( 'stranger CANNOT read the inquiry',         user_can( $landlord_b, 'read_tdh_inquiry', $inquiry ), false );
tdh_check( 'stranger CANNOT moderate',                 user_can( $landlord_b, 'tdh_moderate_listings' ), false );
tdh_check( 'stranger CANNOT manage facilities',        user_can( $landlord_b, 'tdh_manage_facilities' ), false );
tdh_check( 'stranger CANNOT reach site settings',      user_can( $landlord_b, 'manage_options' ), false );

if ( $admin ) {
	tdh_check( 'administrator can edit any listing',   user_can( $admin, 'edit_tdh_listing', $live ) );
	tdh_check( 'administrator can read any inquiry',   user_can( $admin, 'read_tdh_inquiry', $inquiry ) );
}

/* ========================================================================
   Public visibility
   ===================================================================== */

tdh_section( 'Visibility — only approved listings reach the public' );

$public_ids = get_posts(
	[
		'post_type'      => \TDH\Post_Types::LISTING,
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'suppress_filters' => false,
	]
);

tdh_check( 'a live listing is public',                 in_array( $live, $public_ids, true ) );
tdh_check( 'a pending listing is NOT public',          in_array( $pending, $public_ids, true ), false );
tdh_check( 'a billing-held listing is NOT public',     in_array( $held, $public_ids, true ), false );

/* ========================================================================
   Membership
   ===================================================================== */

tdh_section( 'Membership — a new landlord can publish nothing' );

tdh_check( 'new landlord has no plan',                 \TDH\Membership::NONE === \TDH\Membership::status( $landlord_b ) );
tdh_check( 'new landlord is not active',               \TDH\Membership::is_active( $landlord_b ), false );
tdh_check( 'quota is zero, not the marketing figure',  0 === \TDH\Membership::quota( $landlord_b ) );
tdh_check( 'cannot add a listing without a plan',      \TDH\Membership::can_add_listing( $landlord_b ), false );
tdh_check( 'listing count sees non-public statuses',   3 === \TDH\Membership::listing_count( $landlord_a ) );

// An expiry in the past must beat a stale "active" left by a missed webhook.
update_user_meta( $landlord_b, \TDH\Membership::META_STATUS, \TDH\Membership::ACTIVE );
update_user_meta( $landlord_b, \TDH\Membership::META_EXPIRES, time() - DAY_IN_SECONDS );
tdh_check( 'a lapsed "active" reads as expired',       \TDH\Membership::EXPIRED === \TDH\Membership::status( $landlord_b ) );
delete_user_meta( $landlord_b, \TDH\Membership::META_EXPIRES );
update_user_meta( $landlord_b, \TDH\Membership::META_STATUS, \TDH\Membership::NONE );

/* ========================================================================
   Proximity
   ===================================================================== */

tdh_section( 'Proximity — distances are computed, not typed' );

// Known answer: UPMC Shadyside to the Shadyside listing, ~0.28 miles.
$miles = \TDH\Proximity::miles( 40.4523, -79.9340, 40.4557, -79.9370 );
tdh_check( 'haversine agrees with a hand calculation', abs( $miles - 0.283 ) < 0.02 );

$shadyside = get_page_by_path( 'sunlit-shadyside-retreat', OBJECT, \TDH\Post_Types::LISTING );

if ( $shadyside ) {
	$near = \TDH\Proximity::nearest( $shadyside->ID );
	tdh_check( 'the seeded listing finds a facility',  is_array( $near ) );
	if ( $near ) {
		printf( "  %-52s %s (%s)\n", '  -> nearest', $near['title'], \TDH\Proximity::format_miles( $near['miles'] ) );
	}
}

// A listing with no coordinates must print no band rather than "0.0 mi".
tdh_check( 'an un-geocoded listing gets no distance',  null === \TDH\Proximity::nearest( $pending ), true );

/* ========================================================================
   Account pages and indexing
   ===================================================================== */

tdh_section( 'Account pages exist and stay out of search results' );

$was_public = get_option( 'blog_public' );
remove_all_filters( 'pre_option_blog_public' );
update_option( 'blog_public', 1 );

$expect = [
	'home' => false, 'pricing' => false, 'about' => false,
	'register' => true, 'login' => true, 'lost-password' => true,
	'reset-password' => true, 'account' => true, 'profile' => true,
];

foreach ( $expect as $slug => $should_be_noindex ) {

	$page = get_page_by_path( $slug );

	if ( ! $page ) {
		printf( "  %-52s %s\n", $slug, 'MISSING PAGE' );
		++$GLOBALS['tdh_verify_fail'];
		continue;
	}

	global $wp_query, $post;
	$wp_query = new WP_Query( [ 'page_id' => $page->ID ] );
	$post     = $page;
	setup_postdata( $post );

	$robots = apply_filters( 'wp_robots', [] );

	tdh_check(
		sprintf( '/%s/ is hidden from search engines', $slug ),
		! empty( $robots['noindex'] ),
		$should_be_noindex
	);

	wp_reset_postdata();
}

update_option( 'blog_public', $was_public );

/* ========================================================================
   Clean up
   ===================================================================== */

foreach ( [ $live, $pending, $held, $inquiry ] as $id ) {
	wp_delete_post( $id, true );
}

/* ========================================================================
   Theme assets
   ===================================================================== */

tdh_section( 'Theme assets' );

/*
 * No literal non-ASCII character inside a CSS `content:` value.
 *
 * The stylesheet is served as `text/css` with NO charset — on localhost
 * and on live — and it carries no BOM, so the browser has to guess its
 * encoding. When it guesses wrong a tick written as "✓" reaches the page
 * as "âœ", which is what happened to the amenities list. The file on disk
 * is perfectly good UTF-8; the damage is done in transit, so grepping the
 * source for mojibake can never catch it.
 *
 * A CSS escape — "\2713" — is plain ASCII and cannot be misread whatever
 * the browser decides. Comments are exempt: they are never rendered.
 */
$tdh_css = (string) file_get_contents( get_template_directory() . '/style.css' );

// Strip comments first, so an em dash in a note does not fail the check.
$tdh_css_code = (string) preg_replace( '!/\*.*?\*/!s', '', $tdh_css );

preg_match_all( '/content\s*:\s*"([^"]*)"/', $tdh_css_code, $tdh_contents );

$tdh_literal = array_values(
	array_filter(
		$tdh_contents[1],
		static fn( string $value ): bool => (bool) preg_match( '/[^\x00-\x7F]/', $value )
	)
);

tdh_check( 'no raw non-ASCII in a CSS content: value', [] === $tdh_literal );

if ( $tdh_literal ) {
	printf( "      found: %s  — write it as a CSS escape instead\n", implode( ', ', $tdh_literal ) );
}

/* ========================================================================
   The privacy policy's text-message clause
   ===================================================================== */

tdh_section( 'Privacy policy' );

/*
 * A carrier will not approve the A2P 10DLC campaign without a mobile-number
 * clause on the privacy page, and no campaign means no text messages at
 * all (R46 blocks R45 blocks D4). The owner approved this exact wording on
 * 22 Sep 2026, so it is checked as written rather than paraphrased — a
 * well-meaning tidy-up of somebody else's approved legal sentence is still
 * a change they did not agree to.
 */
/*
 * Read from the seed source, the way the CSS check above reads style.css.
 * Site_Structure::pages() is private and the seeded page only exists once
 * an import has been run, so neither is a dependable thing for this to
 * ask; the source is the thing that would actually be edited by mistake.
 */
$tdh_seed_src = (string) file_get_contents(
	dirname( __DIR__ ) . '/includes/setup/class-tdh-site-structure.php'
);

// Just the privacy entry: the Terms page carries its own "Placeholder"
// note, and searching the whole file would find that one first.
$tdh_privacy = '';

if ( preg_match( "/'privacy'\s*=>\s*\[(.*?)\],\s*'fair-housing'/s", $tdh_seed_src, $tdh_m ) ) {
	$tdh_privacy = $tdh_m[1];
}

/*
 * Comments out, the way the CSS check above strips them before looking
 * for stray characters. The note explaining WHY a phrase is not used
 * contains that phrase, so the first version of the check below failed on
 * its own reasoning.
 */
$tdh_privacy = (string) preg_replace( [ '!/\*.*?\*/!s', '!//[^\n]*!' ], '', $tdh_privacy );

tdh_check( 'the privacy seed entry was found', '' !== $tdh_privacy );
tdh_check( '...and has a text-message section', str_contains( $tdh_privacy, '<h2>Text messages</h2>' ) );

foreach (
	[
		'what the texts are for'  => 'We only send text messages about inquiries on your property.',
		'that numbers are not sold' => 'We never sell or share phone numbers.',
		'how to stop them'        => 'Reply STOP to any text and we will stop sending them.',
	] as $tdh_what => $tdh_sentence
) {
	tdh_check( "...saying {$tdh_what}, in the owner's own words", str_contains( $tdh_privacy, $tdh_sentence ) );
}

/*
 * The four things a carrier looks for that the owner's own sentence does
 * not cover. Each is a claim about behaviour, so each is checked here and
 * has to stay true of the code: an inquiry is the ONLY trigger (D3/D4 hang
 * off tdh_inquiry_received and nothing else), HELP and STOP are answered
 * by the provider, and nothing deletes a phone number on a schedule.
 */
foreach (
	[
		'how often texts arrive'  => 'how often you hear from us depends on how many inquiries you receive',
		'that nothing is marketing' => 'There is no marketing and nothing on a schedule',
		'the HELP keyword'        => 'Reply HELP to any text for help.',
		'that rates may apply'    => 'Message and data rates may apply.',
		'how long numbers are kept' => 'We keep a phone number for as long as the account or the message it came with is on the site.',
	] as $tdh_what => $tdh_sentence
) {
	tdh_check( "...and {$tdh_what}", str_contains( $tdh_privacy, $tdh_sentence ) );
}

/*
 * Retention is worded to what the site actually does. Deleting a listing
 * deliberately KEEPS its messages — a landlord removing a home does not
 * wipe the conversations about it — so a promise that a number goes with
 * the listing would be a promise the code breaks.
 */
tdh_check(
	'...without promising numbers vanish with a deleted listing',
	! str_contains( $tdh_privacy, 'deleted with the listing' )
		&& ! str_contains( $tdh_privacy, 'delete it when that is removed' )
);

/*
 * The clause must not sit under the word "Placeholder". A reviewer reading
 * "Placeholder" above a consent sentence has every reason to treat the
 * whole page as unfinished, which is the rejection we are avoiding.
 */
$tdh_sms_at    = strpos( $tdh_privacy, '<h2>Text messages</h2>' );
$tdh_holder_at = strpos( $tdh_privacy, 'Placeholder.' );

tdh_check(
	'...and it comes BEFORE the placeholder note, not under it',
	false !== $tdh_sms_at && ( false === $tdh_holder_at || $tdh_sms_at < $tdh_holder_at )
);

/*
 * What the carriers' campaign form literally demands of the privacy page
 * (Twilio, 24 Sep 2026): the registered brand name, and one statement
 * quoted word for word. A paraphrase is the rejection we are avoiding.
 */
tdh_check( '...names the registered brand', str_contains( $tdh_privacy, 'ThirtyDayHomes (Thirty Day Homes LLC)' ) );
tdh_check( '...carries the carriers\' no-selling statement, word for word', str_contains( $tdh_privacy, 'We do not sell or share your SMS opt-in data or personal information with third parties for marketing purposes.' ) );
tdh_check( '...and says what is kept and why', str_contains( $tdh_privacy, 'We keep the number, the time you consented and any STOP or START reply' ) );

/* =====================================================================
   Terms of Service — the SMS terms the same form demands
   ===================================================================== */

tdh_section( 'Terms of Service' );

$tdh_terms = '';

if ( preg_match( "/'terms'\s*=>\s*\[(.*?)\],/s", $tdh_seed_src, $tdh_m ) ) {
	$tdh_terms = $tdh_m[1];
}

$tdh_terms = (string) preg_replace( [ '!/\*.*?\*/!s', '!//[^\n]*!' ], '', $tdh_terms );

tdh_check( 'the terms seed entry was found', '' !== $tdh_terms );
tdh_check( '...titled Terms of Service, a title the carriers accept', str_contains( $tdh_terms, "'Terms of Service'" ) );
tdh_check( '...with an SMS terms section', str_contains( $tdh_terms, '<h2>SMS terms</h2>' ) );

foreach (
	[
		'the registered brand'        => 'ThirtyDayHomes (Thirty Day Homes LLC)',
		'what the texts are'          => 'one text each time a renter sends an inquiry about your property',
		'that consent is optional'    => 'Consent is not a condition of listing a property or of using the site.',
		'that frequency varies'       => 'Message frequency varies with the number of inquiries you receive.',
		'that rates may apply'        => 'Message and data rates may apply.',
		'how to stop'                 => 'Reply STOP to any text to stop receiving them.',
		'how to get help'             => 'Reply HELP for help',
		'the carrier liability line'  => 'Carriers are not liable for delayed or undelivered messages.',
	] as $tdh_what => $tdh_sentence
) {
	tdh_check( "...saying {$tdh_what}", str_contains( $tdh_terms, $tdh_sentence ) );
}

$tdh_sms_at    = strpos( $tdh_terms, '<h2>SMS terms</h2>' );
$tdh_holder_at = strpos( $tdh_terms, 'Still to come.' );

tdh_check(
	'...and the SMS terms come BEFORE the still-to-come note, not under it',
	false !== $tdh_sms_at && ( false === $tdh_holder_at || $tdh_sms_at < $tdh_holder_at )
);

// The sign-up form names the page; it must name it by its new title.
$tdh_signup_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tdh-account-render.php' )
	. (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tdh-accounts.php' );
tdh_check( 'the sign-up form and its refusal call the page Terms of Service', str_contains( $tdh_signup_src, 'Terms of Service' ) && ! str_contains( $tdh_signup_src, 'Terms of Use' ) );

/* =====================================================================
   The legal pages as a visitor reads them (G3b design review)
   ===================================================================== */

tdh_section( 'Legal pages, printed' );

/*
 * Against the page as it is stored on this site — which on an installed
 * site is the OLD seed, bare URLs and all — so this proves the fix for
 * pages that exist, not only for pages imported from now on.
 */
$tdh_terms_page = get_page_by_path( 'terms' );

if ( $tdh_terms_page instanceof WP_Post ) {
	$tdh_visitor = TDH\Legal_Pages::prepare( $tdh_terms_page->post_content, false );
	$tdh_staffv  = TDH\Legal_Pages::prepare( $tdh_terms_page->post_content, true );

	tdh_check( 'the contact address is a link, not a bare URL', (bool) preg_match( '~<a href="[^"]*/contact/">[^<]+</a>~', $tdh_visitor ) && ! preg_match( '~(?<!href=")https?://[^\s<"]+/contact/~', $tdh_visitor ) );
	tdh_check( '...and so is the privacy policy', (bool) preg_match( '~<a href="[^"]*/privacy/">~', $tdh_visitor ) );
	tdh_check( 'a visitor does not read "Still to come", nor the heading that stood over it', ! str_contains( $tdh_visitor, 'Still to come' ) && ! str_contains( $tdh_visitor, 'The rest of these terms' ) );
	tdh_check( '...while every SMS sentence is still there', str_contains( $tdh_visitor, 'Message and data rates may apply.' ) && str_contains( $tdh_visitor, 'Reply STOP to any text' ) );
	tdh_check( 'staff read the note, labelled as theirs', str_contains( $tdh_staffv, 'page-note--staff' ) && str_contains( $tdh_staffv, 'Still to come' ) );
	tdh_check( 'the filter applies to the three seeded legal pages and nothing else', TDH\Legal_Pages::is_legal( (int) $tdh_terms_page->ID ) && ! TDH\Legal_Pages::is_legal( (int) ( get_page_by_path( 'about' )->ID ?? 0 ) ) );
} else {
	tdh_check( 'the terms page exists to be printed', false );
}

require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $landlord_a );
wp_delete_user( $landlord_b );

$fail = (int) $GLOBALS['tdh_verify_fail'];
$ran  = (int) $GLOBALS['tdh_verify_ran'];

echo "\n" . str_repeat( '=', 66 ) . "\n";
echo $fail
	? "  {$fail} of {$ran} CHECKS FAILED\n"
	: "  all {$ran} checks passed\n";
echo str_repeat( '=', 66 ) . "\n";
echo "  fixtures removed; blog_public restored to " . get_option( 'blog_public' ) . "\n";
