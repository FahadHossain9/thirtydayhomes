<?php
/** The listing wizard: gated, ownership-safe, whitelisted, and honest at submit. */

use TDH\Listing_Form;
use TDH\Listing_Form_Render;
use TDH\Membership;

function ok( string $label = '', bool $cond = false, string $detail = '' ): array {
	static $p = 0, $f = 0;
	if ( '' === $label ) { return [ $p, $f ]; }
	if ( $cond ) { ++$p; printf( "  ok    %s\n", $label ); }
	else { ++$f; printf( "  FAIL  %s%s\n", $label, '' !== $detail ? "  --> {$detail}" : '' ); }
	return [ $p, $f ];
}

/**
 * Run handle() and report where it redirected to, instead of exiting —
 * the same escape hatch verify-contact.php uses: wp_redirect runs its
 * filter before sending a header, and throwing there escapes the exit.
 */
function run_wizard( Listing_Form $form ): string {

	$catch = static function ( $location ) {
		throw new RuntimeException( (string) $location );
	};

	add_filter( 'wp_redirect', $catch, 1 );

	try {
		$form->handle();
		$where = '(no redirect)';
	} catch ( RuntimeException $e ) {
		$where = $e->getMessage();
	} finally {
		remove_filter( 'wp_redirect', $catch, 1 );
	}

	return $where;
}

/** The ?listing= id a redirect carried, or 0. */
function carried_listing( string $url ): int {
	$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
	parse_str( $query, $args );
	return (int) ( $args['listing'] ?? 0 );
}

function reset_request(): void {
	$_POST = [];
	$_GET  = [];
}

$form = new Listing_Form();

/* Own fixtures, like every other suite. */
$landlord_id = wp_insert_user(
	[
		'user_login' => 'tdh_wizard_probe',
		'user_email' => 'wizard-probe@example.com',
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => 'tdh_landlord',
	]
);
$landlord    = is_wp_error( $landlord_id ) ? null : get_userdata( (int) $landlord_id );

$rival_id = wp_insert_user(
	[
		'user_login' => 'tdh_wizard_rival',
		'user_email' => 'wizard-rival@example.com',
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => 'tdh_landlord',
	]
);
$rival    = is_wp_error( $rival_id ) ? null : get_userdata( (int) $rival_id );

$rival_listing = $rival ? (int) wp_insert_post(
	[
		'post_type'   => 'tdh_listing',
		'post_status' => 'draft',
		'post_title'  => 'Somebody else\'s draft',
		'post_author' => $rival->ID,
	]
) : 0;

$hood = wp_insert_term( 'Wizard Probe Hood', 'tdh_neighborhood' );
$hood = is_wp_error( $hood ) ? 0 : (int) $hood['term_id'];

echo "\n=== the gate ===\n";

reset_request();
wp_set_current_user( 0 );
ok( 'logged out: signin', 'signin' === Listing_Form::gate_reason() );
$html = Listing_Form_Render::form();
ok( 'logged out render is the gate, not the form', str_contains( $html, 'lform-gate' ) && ! str_contains( $html, 'tdh_action' ) );

if ( $landlord ) {
	wp_set_current_user( $landlord->ID );
	ok( 'landlord with no plan: plan', 'plan' === Listing_Form::gate_reason() );

	// Grant the plan the way the webhook does: status + quota in user meta.
	update_user_meta( $landlord->ID, Membership::META_STATUS, Membership::ACTIVE );
	update_user_meta( $landlord->ID, Membership::META_QUOTA, 2 );
	ok( 'landlord with an active plan: open', '' === Listing_Form::gate_reason() );
}

// Staff. The allowance is a billing rule and staff are not billed, so an
// administrator with no membership at all walks straight in — and their
// dashboard button must agree with the gate, not send them to pricing.
$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0] ?? null;

if ( $admin ) {
	reset_request();
	wp_set_current_user( $admin->ID );
	ok( 'an administrator passes with no plan', '' === Listing_Form::gate_reason() );
	ok( 'and gets the real form, not a gate card', str_contains( TDH\Listing_Form_Render::form(), 'tdh_action' ) );
}

echo "\n=== step 1 creates a draft ===\n";

$created = 0;

if ( $landlord ) {
	reset_request();
	wp_set_current_user( $landlord->ID );

	$_POST = [
		'tdh_action'       => 'listing_basics',
		'tdh_nonce'        => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_title'        => 'Wizard Probe Retreat',
		'tdh_address'      => '123 Probe Street',
		'tdh_zip'          => '15232',
		'tdh_neighborhood' => (string) $hood,
		'tdh_type'         => '999999', // Not a term. Must create nothing.
		'tdh_rent'         => '2400',
		'tdh_deposit'      => '500',
		'tdh_beds'         => '2',
		'tdh_baths'        => '1.5',
		'tdh_available'    => '2026-10-01',
	];

	$where   = run_wizard( $form );
	$created = carried_listing( $where );
	$post    = $created ? get_post( $created ) : null;

	ok( 'it lands on step 2 with the draft id', $created > 0 && str_contains( $where, 'step=2' ), $where );
	ok( 'the draft exists, owned, as a draft', $post && 'draft' === $post->post_status && (int) $post->post_author === $landlord->ID );
	ok( 'the address is stored (private meta)', $post && '123 Probe Street' === get_post_meta( $created, '_tdh_street_address', true ) );
	ok( 'rent, beds, baths, deposit stored', $post
		&& 2400.0 === (float) get_post_meta( $created, '_tdh_price_monthly', true )
		&& 2 === (int) get_post_meta( $created, '_tdh_beds', true )
		&& 1.5 === (float) get_post_meta( $created, '_tdh_baths', true )
		&& 500.0 === (float) get_post_meta( $created, '_tdh_deposit', true ) );
	ok( 'the neighborhood term is assigned', $post && in_array( $hood, wp_get_object_terms( $created, 'tdh_neighborhood', [ 'fields' => 'ids' ] ), true ) );
	ok( 'a fake term id assigns nothing', $post && [] === wp_get_object_terms( $created, 'tdh_property_type', [ 'fields' => 'ids' ] ) );

	// Validation: a bad ZIP bounces back to step 1 with the error stashed.
	reset_request();
	$_POST = [
		'tdh_action' => 'listing_basics',
		'tdh_nonce'  => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_title'  => 'Broken',
		'tdh_zip'    => 'ABCDE',
	];
	$where = run_wizard( $form );
	$errs  = Listing_Form::take_errors();
	ok( 'a bad submission bounces to step 1', str_contains( $where, 'step=1' ) );
	ok( 'the errors are stashed for the redirect', [] !== $errs );
	ok( 'and consumed once', [] === Listing_Form::take_errors() );

	// Save draft stays put instead of continuing.
	reset_request();
	$_GET  = [ 'listing' => (string) $created ];
	$_POST = [
		'tdh_action'    => 'listing_basics',
		'tdh_nonce'     => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_save_only' => '1',
		'tdh_title'     => 'Wizard Probe Retreat',
		'tdh_address'   => '123 Probe Street',
		'tdh_zip'       => '15232',
		'tdh_rent'      => '2400',
		'tdh_beds'      => '2',
		'tdh_baths'     => '1.5',
	];
	$where = run_wizard( $form );
	ok( 'Save draft stays on step 1 and says so', str_contains( $where, 'step=1' ) && str_contains( $where, 'saved=1' ), $where );
}

echo "\n=== ownership: not yours answers like not real ===\n";

if ( $landlord && $rival_listing ) {
	reset_request();
	wp_set_current_user( $landlord->ID );
	$_GET = [ 'listing' => (string) $rival_listing ];
	ok( 'another landlord\'s id resolves to null', null === Listing_Form::current_listing() );

	$_GET = [ 'listing' => '999999' ];
	ok( 'a nonexistent id resolves to null the same way', null === Listing_Form::current_listing() );
}

echo "\n=== step 2 whitelists ===\n";

if ( $landlord && $created ) {
	reset_request();
	wp_set_current_user( $landlord->ID );
	$_GET  = [ 'listing' => (string) $created ];
	$_POST = [
		'tdh_action'    => 'listing_features',
		'tdh_nonce'     => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_stay'      => '60',
		'tdh_amenities' => [ 'Fully furnished', 'Heating', 'Gold-plated taps' ],
	];

	$where = run_wizard( $form );
	$names = wp_get_object_terms( $created, 'tdh_amenity', [ 'fields' => 'names' ] );

	ok( 'it lands on step 3', str_contains( $where, 'step=3' ), $where );
	ok( 'the minimum stay is stored', 60 === (int) get_post_meta( $created, '_tdh_min_stay_days', true ) );
	ok( 'catalogue amenities are assigned', in_array( 'Fully furnished', $names, true ) && in_array( 'Heating', $names, true ) );
	ok( 'an invented amenity is NOT — no new filter terms', ! in_array( 'Gold-plated taps', $names, true ) && ! term_exists( 'Gold-plated taps', 'tdh_amenity' ) );
}

echo "\n=== step 3: photos & description ===\n";

/*
 * Fixture images live inside the uploads directory's own volume: PHP's
 * rename() is not reliable across Windows drives, and the sideload path
 * moves the staged file with rename().
 */
$px  = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
$dir = wp_upload_dir()['basedir'] . '/tdh-wizard-probe';
wp_mkdir_p( $dir );

/** Stage one fake browser upload of $count tiny PNGs. */
function stage_photos( string $dir, string $px, int $count ): void {
	$_FILES['tdh_photos'] = [ 'name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => [] ];
	for ( $i = 0; $i < $count; $i++ ) {
		$path = $dir . "/probe-{$i}.png";
		file_put_contents( $path, $px ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$_FILES['tdh_photos']['name'][]     = "probe-{$i}.png";
		$_FILES['tdh_photos']['type'][]     = 'image/png';
		$_FILES['tdh_photos']['tmp_name'][] = $path;
		$_FILES['tdh_photos']['error'][]    = 0;
		$_FILES['tdh_photos']['size'][]     = strlen( $px );
	}
}

// The staged files are not real browser uploads, so the sideload action —
// readable-check and rename() instead of is_uploaded_file() — is swapped in
// through the handler's own override seam.
add_filter( 'tdh_listing_upload_overrides', static fn( array $o ): array => $o + [ 'action' => 'wp_handle_sideload' ] );

if ( $landlord && $created ) {
	wp_set_current_user( $landlord->ID );

	// Render first.
	reset_request();
	$_GET  = [ 'step' => '3', 'listing' => (string) $created ];
	$three = TDH\Listing_Form_Render::form();
	foreach ( [ 'lform-drop', 'tdh_photos', 'tdh_description', 'Fair Housing reminder', 'Drop photos here or browse' ] as $hook ) {
		ok( sprintf( 'step 3: %-26s present', $hook ), str_contains( $three, $hook ) );
	}

	// Description saves as post_content, tags stripped.
	reset_request();
	$_GET  = [ 'listing' => (string) $created ];
	$_POST = [
		'tdh_action'      => 'listing_photos',
		'tdh_nonce'       => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_description' => "Bright corner apartment. <script>alert(1)</script>\nWalk to UPMC Shadyside.",
	];
	$where = run_wizard( $form );
	$body  = (string) get_post_field( 'post_content', $created );
	ok( 'it lands on step 4', str_contains( $where, 'step=4' ), $where );
	ok( 'the description is post_content, the field the site reads', str_contains( $body, 'Bright corner apartment.' ) );
	ok( 'markup in it is stripped', ! str_contains( $body, '<script>' ) );

	// Over the limit: refused, previous description kept.
	reset_request();
	$_GET  = [ 'listing' => (string) $created ];
	$_POST = [
		'tdh_action'      => 'listing_photos',
		'tdh_nonce'       => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_description' => str_repeat( 'a', Listing_Form::MAX_DESCRIPTION + 1 ),
	];
	run_wizard( $form );
	ok( 'an over-limit description is refused with the reason', [] !== Listing_Form::take_errors() );
	ok( 'and the saved one survives', str_contains( (string) get_post_field( 'post_content', $created ), 'Bright corner apartment.' ) );

	// A real upload becomes a real attachment and the cover.
	reset_request();
	$_GET  = [ 'listing' => (string) $created ];
	$_POST = [
		'tdh_action'      => 'listing_photos',
		'tdh_nonce'       => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_description' => 'Bright corner apartment. Walk to UPMC Shadyside.',
	];
	stage_photos( $dir, $px, 2 );
	$where  = run_wizard( $form );
	$_FILES = [];
	$photos = Listing_Form::photos( $created );
	ok( 'two photos upload as attachments of the listing', 2 === count( $photos ), $where );
	ok( 'the first becomes the featured image the cards show', (int) get_post_thumbnail_id( $created ) === ( $photos[0] ?? -1 ) );

	// Eleven photos will not fit under the ceiling of ten.
	reset_request();
	$_GET  = [ 'listing' => (string) $created ];
	$_POST = [
		'tdh_action'      => 'listing_photos',
		'tdh_nonce'       => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_description' => 'Bright corner apartment. Walk to UPMC Shadyside.',
	];
	stage_photos( $dir, $px, Listing_Form::MAX_PHOTOS - 1 );
	$where  = run_wizard( $form );
	$_FILES = [];
	ok( 'more than ten in total is refused, none taken', [] !== Listing_Form::take_errors() && 2 === count( Listing_Form::photos( $created ) ), $where );

	// The step now shows the uploaded photos with a Remove control.
	reset_request();
	$_GET = [ 'step' => '3', 'listing' => (string) $created ];
	ok( 'the step shows the photos with a Remove control', str_contains( TDH\Listing_Form_Render::form(), 'tdh_remove[]' ) );

	// Remove one — and try to remove somebody else's attachment too.
	$foreign = $rival_listing ? (int) wp_insert_attachment(
		[
			'post_title'     => 'Rival photo',
			'post_mime_type' => 'image/png',
			'post_status'    => 'inherit',
		],
		'',
		$rival_listing
	) : 0;

	reset_request();
	$_GET  = [ 'listing' => (string) $created ];
	$_POST = [
		'tdh_action'      => 'listing_photos',
		'tdh_nonce'       => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_description' => 'Bright corner apartment. Walk to UPMC Shadyside.',
		'tdh_remove'      => [ (string) $photos[1], (string) $foreign ],
	];
	run_wizard( $form );
	ok( 'a ticked photo is removed on Continue', 1 === count( Listing_Form::photos( $created ) ) );
	ok( 'somebody else\'s attachment cannot be removed through it', $foreign && null !== get_post( $foreign ) );

	if ( $foreign ) {
		wp_delete_attachment( $foreign, true );
	}
}

echo "\n=== the wizard renders each step ===\n";

if ( $landlord && $created ) {
	reset_request();
	wp_set_current_user( $landlord->ID );

	$_GET = [ 'step' => '1', 'listing' => (string) $created ];
	$one  = Listing_Form_Render::form();
	foreach ( [ 'lform-head', 'lform-progress', 'lform-grid', 'tdh_title', 'tdh_address', 'tdh_zip', 'Never shown publicly' ] as $hook ) {
		ok( sprintf( 'step 1: %-22s present', $hook ), str_contains( $one, $hook ) );
	}
	ok( 'step 1: the draft\'s values prefill', str_contains( $one, 'Wizard Probe Retreat' ) && str_contains( $one, '123 Probe Street' ) );

	$_GET = [ 'step' => '2', 'listing' => (string) $created ];
	$two  = Listing_Form_Render::form();
	ok( 'step 2: the stay options render as radios', str_contains( $two, 'lform-option' ) && str_contains( $two, 'type="radio"' ) );
	ok( 'step 2: the saved stay is checked', (bool) preg_match( '/value="60"\s+checked/', $two ) );
	ok( 'step 2: amenity chips are checkboxes', str_contains( $two, 'lform-chip' ) && str_contains( $two, 'tdh_amenities[]' ) );
	ok( 'step 2: the group counts its picks', str_contains( $two, '2 selected' ) );

	$_GET = [ 'step' => '4', 'listing' => (string) $created ];
	$four = Listing_Form_Render::form();
	ok( 'step 4: Ready for review, with the approval warning', str_contains( $four, 'Ready for review' ) && str_contains( $four, 'until an administrator approves it' ) );
	ok( 'step 4: the facts — title, location, rent, amenities', str_contains( $four, 'Wizard Probe Retreat' ) && str_contains( $four, 'Wizard Probe Hood, Pittsburgh' ) && str_contains( $four, '2,400' ) && str_contains( $four, '2 selected' ) );
	ok( 'step 4: Fair Housing is required to submit', str_contains( $four, 'tdh_fair_housing' ) && str_contains( $four, 'required' ) && str_contains( $four, 'Submit for approval' ) );

	// A deep link to step 4 with no draft starts at the beginning.
	$_GET = [ 'step' => '4' ];
	ok( 'step 4 without a draft falls back to step 1', str_contains( Listing_Form_Render::form(), 'lform-grid' ) );
}

echo "\n=== submit ===\n";

$heard = 0;
add_action( 'tdh_listing_submitted', static function ( int $id ) use ( &$heard ): void { $heard = $id; } );

if ( $landlord && $created ) {

	// Without the Fair Housing tick: refused, still a draft.
	reset_request();
	wp_set_current_user( $landlord->ID );
	$_GET  = [ 'listing' => (string) $created ];
	$_POST = [
		'tdh_action' => 'listing_submit',
		'tdh_nonce'  => wp_create_nonce( Listing_Form::NONCE ),
	];
	$where = run_wizard( $form );
	ok( 'no Fair Housing tick: bounced with the reason', str_contains( $where, 'step=4' ) && [] !== Listing_Form::take_errors() );
	ok( 'and the listing stays a draft', 'draft' === get_post_status( $created ) );

	// With it: pending, acknowledged, announced.
	$_POST['tdh_fair_housing'] = '1';
	$where = run_wizard( $form );
	ok( 'submitted: redirects home with the flag', str_contains( $where, 'tdh_submitted=1' ), $where );
	ok( 'the listing is pending review', 'pending' === get_post_status( $created ) );
	ok( 'the Fair Housing acknowledgment is recorded with a time', (int) get_post_meta( $created, '_tdh_fair_housing_ack', true ) > 0 );
	ok( 'the tdh_listing_submitted hook heard it', $heard === $created );

	// A lapsed plan cannot submit: the gate bounces it and the wizard
	// shows the plan card instead of the form.
	$second = (int) wp_insert_post(
		[
			'post_type'   => 'tdh_listing',
			'post_status' => 'draft',
			'post_title'  => 'Lapsed plan draft',
			'post_author' => $landlord->ID,
		]
	);
	update_user_meta( $landlord->ID, Membership::META_QUOTA, 0 );
	reset_request();
	$_GET  = [ 'listing' => (string) $second ];
	$_POST = [
		'tdh_action'       => 'listing_submit',
		'tdh_nonce'        => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_fair_housing' => '1',
	];
	run_wizard( $form );
	ok( 'a lapsed plan cannot submit — the draft stays a draft', 'draft' === get_post_status( $second ) );
	$_POST = [];
	ok( 'and the wizard shows the membership gate', str_contains( Listing_Form_Render::form(), 'A membership comes first' ) );

	// At the limit, editing stays open but a NEW listing is blocked.
	update_user_meta( $landlord->ID, Membership::META_QUOTA, 2 );
	reset_request();
	ok( 'at the limit (2 of 2): a new listing is blocked', 'full' === Listing_Form::gate_reason() );
	$_GET = [ 'listing' => (string) $second ];
	ok( 'but an existing draft still opens', '' === Listing_Form::gate_reason() || null !== Listing_Form::current_listing() );

	wp_delete_post( $second, true );
}

// Staff carry the whole flow without a membership: an administrator's own
// draft submits into the review queue — the quota re-check exempts them
// the same way the gate does.
if ( $admin ) {
	wp_set_current_user( $admin->ID );

	$staff_draft = (int) wp_insert_post(
		[
			'post_type'   => 'tdh_listing',
			'post_status' => 'draft',
			'post_title'  => 'Staff-listed home',
			'post_author' => $admin->ID,
		]
	);

	reset_request();
	$_GET  = [ 'listing' => (string) $staff_draft ];
	$_POST = [
		'tdh_action'       => 'listing_submit',
		'tdh_nonce'        => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_fair_housing' => '1',
	];
	run_wizard( $form );
	ok( 'staff submit with no plan at all: pending', 'pending' === get_post_status( $staff_draft ) );

	wp_delete_post( $staff_draft, true );
}

/* Fixtures away. */
reset_request();
wp_set_current_user( 0 );

if ( $created ) {
	// Attachments do not go down with their parent on their own.
	foreach ( Listing_Form::photos( $created ) as $att ) {
		wp_delete_attachment( $att, true );
	}
	wp_delete_post( $created, true );
}

// The staged source files (successful ones were moved away by the upload).
if ( isset( $dir ) && is_dir( $dir ) ) {
	array_map( 'unlink', glob( $dir . '/*' ) ?: [] );
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}
if ( $rival_listing ) {
	wp_delete_post( $rival_listing, true );
}
if ( $hood ) {
	wp_delete_term( $hood, 'tdh_neighborhood' );
}
foreach ( [ 'Wizard Probe Hood' ] as $t ) {
	$left = term_exists( $t, 'tdh_neighborhood' );
	if ( $left ) {
		wp_delete_term( (int) $left['term_id'], 'tdh_neighborhood' );
	}
}
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( $landlord ) {
	wp_delete_user( $landlord->ID );
}
if ( $rival ) {
	wp_delete_user( $rival->ID );
}
echo "\n  fixtures removed\n";

[ $p, $f ] = ok();
printf( "\n%s  %d passed, %d failed\n\n", $f ? 'FAILED' : 'PASSED', $p, $f );
