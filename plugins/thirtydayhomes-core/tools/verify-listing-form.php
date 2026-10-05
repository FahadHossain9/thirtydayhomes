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

// A city that is NOT Pittsburgh, so a hardcoded city anywhere in the flow
// shows up as a failure rather than passing by coincidence.
$city = wp_insert_term( 'Wizard Probe City', 'tdh_city' );
$city = is_wp_error( $city ) ? 0 : (int) $city['term_id'];

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
	$framed = do_shortcode( '[tdh_add_listing]' );
	ok( 'the form sits inside the dashboard: sidebar, tab bar, Listings current', str_contains( $framed, 'class="portal-side"' ) && str_contains( $framed, 'class="portal-tabs"' ) && (bool) preg_match( '#class="is-current"[^>]*title="[^"]*listings"#si', $framed ) && str_contains( $framed, 'class="lform"' ) );
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
		'tdh_city'         => (string) $city,
		'tdh_type'         => '999999', // Not a term. Must create nothing.
		'tdh_rent'            => '2400',
		'tdh_deposit'         => '500',
		'tdh_application_fee' => '45',
		'tdh_cleaning_fee'    => '$1,200', // Written the way people write money.
		'tdh_beds'            => '2',
		'tdh_baths'           => '1.5',
		'tdh_contact_name'    => 'Probe Owner',
		'tdh_contact_email'   => 'owner-probe@example.com',
		'tdh_contact_phone'   => '412-555-0184',
		'tdh_contact_method'  => 'both',
		'tdh_state'           => 'oh', // Picked from Google's suggestions.
	];

	$where   = run_wizard( $form );
	$created = carried_listing( $where );
	$post    = $created ? get_post( $created ) : null;

	ok( 'application and cleaning fees stored as numbers ("$1,200" accepted)', $post
		&& 45.0 === (float) get_post_meta( $created, '_tdh_application_fee', true )
		&& 1200.0 === (float) get_post_meta( $created, '_tdh_cleaning_fee', true ) );
	ok( 'inquiry contact stored privately', $post
		&& 'Probe Owner' === get_post_meta( $created, '_tdh_contact_name', true )
		&& 'owner-probe@example.com' === get_post_meta( $created, '_tdh_contact_email', true )
		&& 'both' === get_post_meta( $created, '_tdh_contact_method', true ) );
	ok( 'the mobile number is stored as E.164', '+14125550184' === get_post_meta( $created, '_tdh_contact_phone', true ) );

	ok( 'it lands on step 2 with the draft id', $created > 0 && str_contains( $where, 'step=2' ), $where );
	ok( 'the draft exists, owned, as a draft', $post && 'draft' === $post->post_status && (int) $post->post_author === $landlord->ID );
	ok( 'the address is stored (private meta)', $post && '123 Probe Street' === get_post_meta( $created, '_tdh_street_address', true ) );
	ok( 'rent, beds, baths, deposit stored', $post
		&& 2400.0 === (float) get_post_meta( $created, '_tdh_price_monthly', true )
		&& 2 === (int) get_post_meta( $created, '_tdh_beds', true )
		&& 1.5 === (float) get_post_meta( $created, '_tdh_baths', true )
		&& 500.0 === (float) get_post_meta( $created, '_tdh_deposit', true ) );
	ok( 'the neighborhood term is assigned', $post && in_array( $hood, wp_get_object_terms( $created, 'tdh_neighborhood', [ 'fields' => 'ids' ] ), true ) );
	ok( 'the city term is assigned', $post && in_array( $city, wp_get_object_terms( $created, 'tdh_city', [ 'fields' => 'ids' ] ), true ) );
	ok( 'a fake term id assigns nothing', $post && [] === wp_get_object_terms( $created, 'tdh_property_type', [ 'fields' => 'ids' ] ) );
	ok( 'address suggestions: a picked state is stored as two capitals', $post && 'OH' === get_post_meta( $created, '_tdh_state', true ) );
	ok( '...and the lookup uses it, not PA', $post && str_ends_with( TDH\Geocoder::address( $created ), 'OH 15232' ) );
	$_GET  = [ 'step' => '1', 'listing' => (string) $created ];
	$step1 = TDH\Listing_Form_Render::form();
	$_GET  = [];
	ok( '...the street box asks Google for suggestions, with a state field beside it', str_contains( $step1, 'data-tdh-places="address"' ) && str_contains( $step1, 'name="tdh_state"' ) && str_contains( $step1, 'Start typing your address' ) );
	$ps = TDH\Maps::places_settings();
	ok( '...the browser is given a loader, the service area and every message, no server key', ! TDH\Maps::configured() || ( str_contains( (string) $ps['src'], 'callback=tdhPlacesReady' ) && isset( $ps['bounds']['south'], $ps['words']['found'], $ps['words']['noCity'] ) && ( ! defined( 'TDH_MAPS_SERVER_KEY' ) || ! str_contains( wp_json_encode( $ps ), (string) TDH_MAPS_SERVER_KEY ) ) ) );

	// A city id from the wrong taxonomy must not invent a city term.
	reset_request();
	$_GET  = [ 'listing' => (string) $created ];
	$_POST = [
		'tdh_action'  => 'listing_basics',
		'tdh_nonce'   => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_title'   => 'Wizard Probe Retreat',
		'tdh_address' => '123 Probe Street',
		'tdh_zip'     => '15232',
		'tdh_city'    => (string) $hood, // A neighborhood id, not a city.
		'tdh_rent'    => '2400',
		'tdh_beds'    => '2',
		'tdh_baths'   => '1.5',
	];
	run_wizard( $form );
	ok(
		'a city id from another taxonomy is refused',
		in_array( $city, wp_get_object_terms( $created, 'tdh_city', [ 'fields' => 'ids' ] ), true )
			&& ! in_array( $hood, wp_get_object_terms( $created, 'tdh_city', [ 'fields' => 'ids' ] ), true )
	);

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

	/*
	 * Partial success. A mistyped email, phone and fee must not cost the
	 * landlord the rest of the step: the good values save, the bad ones are
	 * named, the old good values survive.
	 */
	reset_request();
	$_GET  = [ 'listing' => (string) $created ];
	$_POST = [
		'tdh_action'          => 'listing_basics',
		'tdh_nonce'           => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_title'           => 'Wizard Probe Retreat Renamed',
		'tdh_address'         => '123 Probe Street',
		'tdh_zip'             => '15232',
		'tdh_rent'            => '2400',
		'tdh_beds'            => '2',
		'tdh_baths'           => '1.5',
		'tdh_deposit'         => '',        // Cleared on purpose.
		'tdh_application_fee' => 'forty',   // Not a number.
		'tdh_contact_email'   => 'owner-probe@',
		'tdh_contact_phone'   => '123 456 7890', // Ten digits, not a real number.
		'tdh_contact_method'  => 'both',
		// tdh_cleaning_fee deliberately absent: an older page must not erase it.
	];
	$where = run_wizard( $form );
	$errs  = Listing_Form::take_errors();
	$text  = implode( ' ', $errs );

	ok( 'mistakes in optional fields return to step 1 with the draft', str_contains( $where, 'step=1' ) && carried_listing( $where ) === $created, $where );
	ok( 'each mistake is named with what was typed', str_contains( $text, 'owner-probe@' ) && str_contains( $text, '123 456 7890' ) && str_contains( $text, 'forty' ) );
	ok( 'and the landlord is told the rest is saved', str_contains( $text, 'Everything else on this step is saved' ) );
	ok( 'the valid change in the same post was saved', 'Wizard Probe Retreat Renamed' === get_post_field( 'post_title', $created ) );
	ok( 'the previous good email and phone survive a bad retype', 'owner-probe@example.com' === get_post_meta( $created, '_tdh_contact_email', true ) && '+14125550184' === get_post_meta( $created, '_tdh_contact_phone', true ) );
	ok( 'the previous good fee survives a bad retype', 45.0 === (float) get_post_meta( $created, '_tdh_application_fee', true ) );
	ok( 'a fee emptied on purpose is cleared', ! metadata_exists( 'post', $created, '_tdh_deposit' ) );
	ok( 'a fee missing from the post is left alone', 1200.0 === (float) get_post_meta( $created, '_tdh_cleaning_fee', true ) );

	// Texts chosen with no number at all.
	reset_request();
	$_GET  = [ 'listing' => (string) $created ];
	$_POST = [
		'tdh_action'         => 'listing_basics',
		'tdh_nonce'          => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_title'          => 'Wizard Probe Retreat',
		'tdh_address'        => '123 Probe Street',
		'tdh_zip'            => '15232',
		'tdh_rent'           => '2400',
		'tdh_beds'           => '2',
		'tdh_baths'          => '1.5',
		'tdh_contact_phone'  => '',
		'tdh_contact_method' => 'sms',
	];
	run_wizard( $form );
	ok( 'text messages with no mobile number is named on the spot', str_contains( implode( ' ', Listing_Form::take_errors() ), 'need a mobile number' ) );
	ok( '...and blocks submission until fixed', in_array( 'A mobile number for text messages', array_column( Listing_Form::missing_for_submit( $created ), 'label' ), true ) );

	// Put the good contact back for the rest of the suite.
	reset_request();
	$_GET  = [ 'listing' => (string) $created ];
	$_POST = [
		'tdh_action'          => 'listing_basics',
		'tdh_nonce'           => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_title'           => 'Wizard Probe Retreat',
		'tdh_address'         => '123 Probe Street',
		'tdh_zip'             => '15232',
		'tdh_city'            => (string) $city,
		'tdh_rent'            => '2400',
		'tdh_deposit'         => '500',
		'tdh_beds'            => '2',
		'tdh_baths'           => '1.5',
		'tdh_contact_phone'   => '(412) 555-0184',
		'tdh_contact_method'  => 'both',
	];
	ok( 'a clean step 1 continues to step 2', str_contains( run_wizard( $form ), 'step=2' ) );
}

echo "\n=== phone numbers ===\n";

ok( '"412-555-0184" normalises', '+14125550184' === TDH\Phone::normalise( '412-555-0184' ) );
ok( '"+1 (412) 555 0184" normalises', '+14125550184' === TDH\Phone::normalise( '+1 (412) 555 0184' ) );
ok( '"14125550184" normalises', '+14125550184' === TDH\Phone::normalise( '14125550184' ) );
ok( 'a foreign number is refused', '' === TDH\Phone::normalise( '+44 20 7946 0958' ) );
ok( 'an impossible area code is refused', '' === TDH\Phone::normalise( '112-555-0184' ) );
ok( 'too few digits are refused', '' === TDH\Phone::normalise( '555-0184' ) );
ok( 'stored numbers display the US way', '(412) 555-0184' === TDH\Phone::display( '+14125550184' ) );
ok( 'an old free-text value displays unchanged', 'call the office' === TDH\Phone::display( 'call the office' ) );

echo "\n=== staff edits keep the landlord as author ===\n";

if ( $landlord && $created && $admin ) {
	reset_request();
	wp_set_current_user( $admin->ID );
	$_GET  = [ 'listing' => (string) $created ];
	$_POST = [
		'tdh_action'  => 'listing_basics',
		'tdh_nonce'   => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_title'   => 'Wizard Probe Retreat',
		'tdh_address' => '123 Probe Street',
		'tdh_zip'     => '15232',
		'tdh_rent'    => '2400',
		'tdh_beds'    => '2',
		'tdh_baths'   => '1.5',
	];
	run_wizard( $form );
	ok( 'a staff save does not take the listing from its landlord', (int) get_post_field( 'post_author', $created ) === $landlord->ID );
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

	// No utilities or pet answer: refused, but the rest of the step is kept.
	$_GET  = [ 'listing' => (string) $created ];
	$_POST = [
		'tdh_action'    => 'listing_features',
		'tdh_nonce'     => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_stay'      => '90',
		'tdh_amenities' => [ 'Heating' ],
		'tdh_sqft'      => '850',
	];
	$where = run_wizard( $form );
	$text  = implode( ' ', Listing_Form::take_errors() );
	ok( 'unanswered utilities and pets keep the landlord on step 2', str_contains( $where, 'step=2' ), $where );
	ok( 'both questions are named', str_contains( $text, 'utilities' ) && str_contains( $text, 'pet policy' ) );
	ok( 'the stay, amenities and details on that screen were still saved', 90 === (int) get_post_meta( $created, '_tdh_min_stay_days', true ) && 850 === (int) get_post_meta( $created, '_tdh_sqft', true ) );

	reset_request();
	$_GET  = [ 'listing' => (string) $created ];
	$_POST = [
		'tdh_action'             => 'listing_features',
		'tdh_nonce'              => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_stay'               => '60',
		// "Utilities included" and "Pet friendly" are questions now, not chips.
		'tdh_amenities'          => [ 'Fully furnished', 'Heating', 'Gold-plated taps', 'Utilities included', 'Pet friendly' ],
		'tdh_utilities_included' => 'partial',
		'tdh_utilities'          => 'Water and trash',
		'tdh_pets'               => 'considered',
		'tdh_pet_fee'            => '150',
		'tdh_sqft'               => '900',
		'tdh_rooms'              => '4',
		'tdh_parking'            => 'Driveway, two cars',
		'tdh_backyard'           => 'Shared garden',
	];

	$where = run_wizard( $form );
	$names = wp_get_object_terms( $created, 'tdh_amenity', [ 'fields' => 'names' ] );

	ok( 'it lands on step 3', str_contains( $where, 'step=3' ), $where );
	ok( 'the minimum stay is stored', 60 === (int) get_post_meta( $created, '_tdh_min_stay_days', true ) );
	ok( 'catalogue amenities are assigned', in_array( 'Fully furnished', $names, true ) && in_array( 'Heating', $names, true ) );
	ok( 'an invented amenity is NOT — no new filter terms', ! in_array( 'Gold-plated taps', $names, true ) && ! term_exists( 'Gold-plated taps', 'tdh_amenity' ) );
	ok( 'the old "Utilities included" / "Pet friendly" chips are not assigned', ! in_array( 'Utilities included', $names, true ) && ! in_array( 'Pet friendly', $names, true ) );
	ok( 'utilities answer and details stored', 'partial' === get_post_meta( $created, '_tdh_utilities_included', true ) && 'Water and trash' === get_post_meta( $created, '_tdh_utilities', true ) );
	ok( 'pet policy and pet fee stored', 'considered' === get_post_meta( $created, '_tdh_pet_policy', true ) && 150.0 === (float) get_post_meta( $created, '_tdh_pet_fee', true ) );
	ok( 'square feet, rooms, parking, backyard stored', 900 === (int) get_post_meta( $created, '_tdh_sqft', true )
		&& 4 === (int) get_post_meta( $created, '_tdh_rooms', true )
		&& 'Driveway, two cars' === get_post_meta( $created, '_tdh_parking', true )
		&& 'Shared garden' === get_post_meta( $created, '_tdh_backyard', true ) );

	// "Not allowed" takes the pet fee with it; an invented answer is ignored.
	reset_request();
	$_GET  = [ 'listing' => (string) $created ];
	$_POST = [
		'tdh_action'             => 'listing_features',
		'tdh_nonce'              => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_stay'               => '60',
		'tdh_amenities'          => [ 'Fully furnished', 'Heating' ],
		'tdh_utilities_included' => 'sometimes',
		'tdh_pets'               => 'no',
		'tdh_pet_fee'            => '150',
		'tdh_sqft'               => '999999',
	];
	run_wizard( $form );
	$text = implode( ' ', Listing_Form::take_errors() );
	ok( '"Not allowed" clears the pet fee', 'no' === get_post_meta( $created, '_tdh_pet_policy', true ) && ! metadata_exists( 'post', $created, '_tdh_pet_fee' ) );
	ok( 'an invented utilities answer does not replace the real one', 'partial' === get_post_meta( $created, '_tdh_utilities_included', true ) );
	ok( 'an impossible square footage is refused by name, the old one kept', str_contains( $text, 'Square feet' ) && 900 === (int) get_post_meta( $created, '_tdh_sqft', true ) );

	// Back to the answers the rest of the suite expects.
	update_post_meta( $created, '_tdh_pet_policy', 'considered' );
	update_post_meta( $created, '_tdh_pet_fee', 150 );
}

echo "\n=== nothing typed is lost: Back saves, Edit returns to the review ===\n";

/*
 * Seen on localhost on 17 Sep 2026: step 2 opened from the review, answers
 * changed, Back pressed — and every change was silently discarded, because
 * Back was a plain link. The listing was then submitted with the old answers.
 */
if ( $landlord && $created ) {
	wp_set_current_user( $landlord->ID );

	$fresh = (int) wp_insert_post( [ 'post_type' => 'tdh_listing', 'post_status' => 'draft', 'post_title' => 'Back probe', 'post_author' => $landlord->ID ] );

	// Back from step 2 with questions unanswered: saves what is there, leaves.
	reset_request();
	$_GET  = [ 'listing' => (string) $fresh ];
	$_POST = [
		'tdh_action'  => 'listing_features',
		'tdh_nonce'   => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_go'      => 'back',
		'tdh_stay'    => '90',
		'tdh_sqft'    => '777',
		'tdh_parking' => 'Carport',
	];
	$where = run_wizard( $form );
	ok( 'Back on step 2 goes to step 1', str_contains( $where, 'step=1' ) && carried_listing( $where ) === $fresh, $where );
	ok( '...having saved what was typed', 777 === (int) get_post_meta( $fresh, '_tdh_sqft', true ) && 'Carport' === get_post_meta( $fresh, '_tdh_parking', true ) && 90 === (int) get_post_meta( $fresh, '_tdh_min_stay_days', true ) );
	ok( '...without nagging about unanswered questions', [] === Listing_Form::take_errors() );

	// Back with a value that cannot be stored: stays, and names it.
	reset_request();
	$_GET  = [ 'listing' => (string) $fresh ];
	$_POST = [
		'tdh_action' => 'listing_features',
		'tdh_nonce'  => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_go'     => 'back',
		'tdh_sqft'   => '999999',
	];
	$where = run_wizard( $form );
	ok( 'Back with an impossible value stays on step 2 and names it', str_contains( $where, 'step=2' ) && str_contains( implode( ' ', Listing_Form::take_errors() ), 'Square feet' ), $where );
	ok( '...and the good value is not overwritten', 777 === (int) get_post_meta( $fresh, '_tdh_sqft', true ) );

	// Opened from the review: saving returns to the review.
	reset_request();
	$_GET  = [ 'listing' => (string) $fresh ];
	$_POST = [
		'tdh_action'             => 'listing_features',
		'tdh_nonce'              => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_return'             => 'review',
		'tdh_stay'               => '90',
		'tdh_utilities_included' => 'yes',
		'tdh_pets'               => 'yes',
		'tdh_pet_fee'            => '150',
	];
	$where = run_wizard( $form );
	ok( 'step 2 opened from the review returns to the review', str_contains( $where, 'step=4' ) && ! str_contains( $where, 'review=1' ), $where );
	ok( '...with the changes saved', 'yes' === get_post_meta( $fresh, '_tdh_pet_policy', true ) && 150.0 === (float) get_post_meta( $fresh, '_tdh_pet_fee', true ) );

	// A mistake while in review mode keeps review mode.
	reset_request();
	$_GET  = [ 'listing' => (string) $fresh ];
	$_POST = [
		'tdh_action' => 'listing_features',
		'tdh_nonce'  => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_return' => 'review',
		'tdh_sqft'   => 'lots',
	];
	$where = run_wizard( $form );
	Listing_Form::take_errors();
	ok( 'a mistake in review mode stays on the step, still in review mode', str_contains( $where, 'step=2' ) && str_contains( $where, 'review=1' ), $where );

	// Step 3 Back saves the description.
	reset_request();
	$_GET  = [ 'listing' => (string) $fresh ];
	$_POST = [
		'tdh_action'      => 'listing_photos',
		'tdh_nonce'       => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_go'          => 'back',
		'tdh_description' => 'Typed before pressing Back.',
	];
	$where = run_wizard( $form );
	ok( 'Back on step 3 goes to step 2 with the description saved', str_contains( $where, 'step=2' ) && str_contains( (string) get_post_field( 'post_content', $fresh ), 'Typed before pressing Back.' ), $where );

	// The markup that makes all of this reachable.
	reset_request();
	$_GET = [ 'step' => '2', 'listing' => (string) $fresh ];
	$two  = Listing_Form_Render::form();
	ok( 'step 2 Back is a saving button, allowed to skip required answers', (bool) preg_match( '/<button class="secondary" type="submit" name="tdh_go" value="back" formnovalidate>/', $two ) );
	ok( 'the header arrow on step 2 saves too', str_contains( $two, 'form="lform-step" name="tdh_go" value="back" formnovalidate' ) && str_contains( $two, 'id="lform-step"' ) );
	ok( 'outside review mode the main button says Continue', ! str_contains( $two, 'Save and return to review' ) && ! str_contains( $two, 'name="tdh_return"' ) );

	$_GET = [ 'step' => '2', 'listing' => (string) $fresh, 'review' => '1' ];
	$two  = Listing_Form_Render::form();
	ok( 'opened from the review, it says "Save and return to review"', str_contains( $two, 'Save and return to review' ) && str_contains( $two, 'name="tdh_return" value="review"' ) );

	$_GET = [ 'step' => '4', 'listing' => (string) $fresh ];
	ok( 'the review\'s Edit links open steps in review mode', str_contains( Listing_Form_Render::form(), 'review=1' ) );

	wp_delete_post( $fresh, true );
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

	/*
	 * The silence that made the owner report a working upload as broken:
	 * photos were stored and the wizard moved straight on, so the step
	 * with the longest wait never showed its own result.
	 */
	// One press does both: the photos are stored and the wizard moves on,
	// carrying how many arrived so the review can confirm it.
	ok( 'one Continue uploads and advances to review', str_contains( $where, 'step=4' ), $where );
	ok( 'and the redirect carries how many arrived', str_contains( $where, 'added=2' ), $where );

	reset_request();
	$_GET = [ 'step' => '4', 'listing' => (string) $created, 'added' => '2' ];
	ok( 'the review confirms it in words', str_contains( TDH\Listing_Form_Render::form(), '2 photos added' ) );

	// Nothing extra to press: the dropzone is Choose photos and nothing
	// else, and the browser previews the selection before it is sent.
	reset_request();
	$_GET  = [ 'step' => '3', 'listing' => (string) $created ];
	$step3 = TDH\Listing_Form_Render::form();
	ok( 'there is no separate upload button', ! str_contains( $step3, 'tdh_upload_only' ) );
	ok( 'the browser previews the chosen files before sending', str_contains( $step3, 'lform-preview-grid' ) && str_contains( $step3, 'createObjectURL' ) );
	ok( 'and says the preview is not saved yet', str_contains( $step3, 'Ready to upload' ) );

	reset_request();
	$_GET = [ 'step' => '4', 'listing' => (string) $created ];
	$rev  = TDH\Listing_Form_Render::form();
	ok( 'the review counts the photos back', str_contains( $rev, 'Photos' ) && str_contains( $rev, '2 photos' ) );

	reset_request();
	$_GET = [ 'step' => '3', 'listing' => (string) $created ];
	ok( 'the photo step says what is happening while it uploads', str_contains( TDH\Listing_Form_Render::form(), 'Uploading' ) );

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

	// The dropzone declares the real limits and confirms a selection.
	reset_request();
	$_GET  = [ 'step' => '3', 'listing' => (string) $created ];
	$three = TDH\Listing_Form_Render::form();
	ok( 'the dropzone states the per-photo size limit', str_contains( $three, 'each' ) && str_contains( $three, (string) size_format( TDH\Listing_Form::max_photo_bytes() ) ) );
	ok( 'and carries the selection-feedback script', str_contains( $three, 'data-one' ) && str_contains( $three, '</script>' ) );

	/*
	 * The silent killer: a POST bigger than post_max_size reaches PHP with
	 * $_POST and $_FILES both EMPTY. Without the guard the wizard would
	 * re-render as if nothing was ever submitted.
	 */
	$page = get_posts( [ 'post_type' => 'page', 'meta_key' => '_tdh_seed_key', 'meta_value' => 'add-listing', 'posts_per_page' => 1 ] )[0] ?? null;

	if ( $page ) {
		reset_request();
		$GLOBALS['wp_query']->queried_object    = $page;
		$GLOBALS['wp_query']->queried_object_id = (int) $page->ID;

		$_GET                          = [ 'listing' => (string) $created, 'step' => '3' ];
		$_SERVER['REQUEST_METHOD']     = 'POST';
		$_SERVER['CONTENT_LENGTH']     = (string) ( wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ) + 1 );

		$where = run_wizard( $form );
		$errs  = Listing_Form::take_errors();

		ok( 'an oversized POST is caught, not silent', str_contains( $where, 'step=3' ) && [] !== $errs, $where );
		ok( 'and the message names the server limit', isset( $errs[0] ) && str_contains( $errs[0], 'bigger than the server accepts' ) );

		unset( $_SERVER['CONTENT_LENGTH'] );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset( $GLOBALS['wp_query']->queried_object, $GLOBALS['wp_query']->queried_object_id );
	}

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
	foreach ( [ 'lform-head', 'lform-progress', 'lform-grid', 'tdh_title', 'tdh_address', 'tdh_zip', 'Never shown publicly', 'tdh_application_fee', 'tdh_cleaning_fee', 'tdh_contact_email', 'tdh_contact_method' ] as $hook ) {
		ok( sprintf( 'step 1: %-22s present', $hook ), str_contains( $one, $hook ) );
	}
	ok( 'step 1: the draft\'s values prefill', str_contains( $one, 'Wizard Probe Retreat' ) && str_contains( $one, '123 Probe Street' ) );
	ok( 'step 1: the stored phone shows the US way, not as E.164', str_contains( $one, 'value="(412) 555-0184"' ) );
	ok( 'step 1: a double press cannot post twice', str_contains( $one, 'dataset.busy' ) );

	$_GET = [ 'step' => '2', 'listing' => (string) $created ];
	$two  = Listing_Form_Render::form();
	ok( 'step 2: the stay options render as radios', str_contains( $two, 'lform-option' ) && str_contains( $two, 'type="radio"' ) );
	ok( 'step 2: the saved stay is checked', (bool) preg_match( '/value="60"\s+checked/', $two ) );
	ok( 'step 2: amenity chips are checkboxes', str_contains( $two, 'lform-chip' ) && str_contains( $two, 'tdh_amenities[]' ) );
	ok( 'step 2: the group counts its picks', str_contains( $two, '2 selected' ) );
	ok( 'step 2: utilities and pets are required radio questions', str_contains( $two, 'name="tdh_utilities_included"' ) && str_contains( $two, 'name="tdh_pets"' ) && str_contains( $two, 'required' ) );
	ok( 'step 2: the saved answers are checked', (bool) preg_match( '/value="partial" required\s+checked/', $two ) && (bool) preg_match( '/value="considered" required\s+checked/', $two ) );
	ok( 'step 2: the pet fee sits with the pet question', str_contains( $two, 'lform-petfee' ) && str_contains( $two, 'name="tdh_pet_fee"' ) );
	ok( 'step 2: no "Utilities included" or "Pet friendly" chip remains', ! str_contains( $two, 'value="Utilities included"' ) && ! str_contains( $two, 'value="Pet friendly"' ) );

	$_GET = [ 'step' => '4', 'listing' => (string) $created ];
	$four = Listing_Form_Render::form();
	ok( 'step 4: Ready for review, with the approval warning', str_contains( $four, 'Ready for review' ) && str_contains( $four, 'until an administrator approves it' ) );
	ok( 'step 4: the facts — title, location, rent, amenities', str_contains( $four, 'Wizard Probe Retreat' ) && str_contains( $four, 'Wizard Probe Hood, Wizard Probe City' ) && str_contains( $four, '2,400' ) && str_contains( $four, '2 selected' ) );
	ok( 'step 4: the city is read, never typed in', ! str_contains( $four, 'Pittsburgh' ) );
	ok( 'step 4: Fair Housing is required to submit', str_contains( $four, 'tdh_fair_housing' ) && str_contains( $four, 'required' ) && str_contains( $four, 'Submit for approval' ) );
	ok( 'step 4: every answer is shown in words', str_contains( $four, 'Partly included — Water and trash' ) && str_contains( $four, 'Considered · $150 pet fee' ) && str_contains( $four, '$1,200' ) && str_contains( $four, '(412) 555-0184' ) && str_contains( $four, 'Either' ) );
	// Six groups since A5 gave availability its own: basics, fees, inquiries, availability, the home, photos.
	ok( 'step 4: grouped by step, each with an Edit link', 6 === substr_count( $four, 'lform-review-block' ) && str_contains( $four, 'step=2&#038;listing=' . $created ) );
	ok( 'step 4: nothing missing, so the submit button is live', ! str_contains( $four, 'lform-missing' ) && ! (bool) preg_match( '/<button class="primary" type="submit" disabled/', $four ) );

	echo "\n=== G4b: the listing form, design review ===\n";

	ok( 'G4b: a named stepper, the current step marked', str_contains( $one, 'lform-steps' ) && str_contains( $one, 'aria-current="step"' ) && str_contains( $one, '>Basics<' ) && str_contains( $one, '>Review<' ) );
	$stepper = static fn( string $html ): string => preg_match( '/<ol class="lform-progress lform-steps".*?<\/ol>/s', $html, $m ) ? $m[0] : '';
	ok( 'G4b: steps before the review are not links (nothing unsaved is skipped)', '' !== $stepper( $two ) && ! str_contains( $stepper( $two ), '<a ' ) );
	ok( 'G4b: on the review, the three finished steps are links back', 3 === substr_count( $stepper( $four ), '<a href=' ) && str_contains( $stepper( $four ), 'review=1' ) );
	ok( 'G4b: short related fields pair, the rest take the full width', substr_count( $one, 'lform-field--half' ) >= 8 && str_contains( $one, '<label class="lform-field">' ) );
	ok( 'G4b: "$" and "/ month" sit inside the rent field', (bool) preg_match( '/<span class="lform-unit">\s*<i aria-hidden="true">\$<\/i>\s*<input name="tdh_rent"(?:(?!<\/span>).)*\/ month/s', $one ) );
	ok( 'G4b: every fee has the "$" too', 4 === substr_count( $one, '<i aria-hidden="true">$</i>' ) );
	ok( 'G4b: placeholders read as examples', str_contains( $one, 'placeholder="e.g. Sunlit Shadyside Retreat"' ) && str_contains( $one, 'placeholder="e.g. 15232"' ) );
	ok( 'G4b: square feet carries its unit, the pet fee its "$"', str_contains( $two, '>sq ft<' ) && (bool) preg_match( '/<i aria-hidden="true">\$<\/i>\s*<input name="tdh_pet_fee"/', $two ) );
	ok( 'G4b: a running amenity total, singular and plural ready', str_contains( $two, 'lform-amenity-total' ) && str_contains( $two, 'data-one="1 selected"' ) && str_contains( $two, 'data-none="None selected yet"' ) );
	ok( 'G4b: a draft\'s main button stays "Continue"', ! str_contains( $one, 'Save and continue' ) );

	$_GET  = [ 'step' => '3', 'listing' => (string) $created ];
	$three = Listing_Form_Render::form();
	ok( 'G4b: step 3 captions are two-line boxes', (bool) preg_match( '/<textarea id="tdh-alt-\d+" rows="2"\s+name="tdh_alt\[/', $three ) );
	ok( 'G4b: "Remove photo" says what it removes', str_contains( $three, 'Remove photo' ) );
	ok( 'G4b: the cover is named in words, not a fake button', str_contains( $three, 'Cover photo' ) && ! str_contains( $three, 'Shown first everywhere' ) );
	ok( 'G4b: under five photos, a nudge (not a rule)', str_contains( $three, 'Add at least five photos' ) );
	ok( 'G4b: the drop zone says how many more fit', str_contains( $three, 'Room for 9 more of 10' ) );
	ok( 'photos: the limit is 20 MB a photo (never the 2 GB the server allowed)', TDH\Listing_Form::max_photo_bytes() <= 20 * MB_IN_BYTES && str_contains( $three, 'up to ' . size_format( TDH\Listing_Form::max_photo_bytes() ) . ' each' ) );
	ok( '...the picker knows the limit and the room left, so it can keep adding', str_contains( $three, 'data-max="' . TDH\Listing_Form::max_photo_bytes() . '"' ) && str_contains( $three, 'data-room="9"' ) );
	ok( '...and says choosing more adds, × takes out', str_contains( $three, 'Choose more to add them; press × to take one out.' ) && str_contains( $three, 'lform-preview-remove' ) );
	ok( 'G4b: the description counts as it is typed', (bool) preg_match( '/id="lform-desc-limit" aria-live="polite"/', $three ) && str_contains( $three, ' of ' . number_format_i18n( Listing_Form::MAX_DESCRIPTION ) . ' characters' ) );
	ok( 'G4b: a draft has no live-home lock message', ! str_contains( $three, 'lform-drop-locked' ) );

	ok( 'G4b: the review opens with the home\'s state as a pill', str_contains( $four, 'lform-pill--draft' ) && str_contains( $four, 'Draft · not on the site yet' ) );
	ok( 'G4b: "Worth fixing" names what a renter would miss, with links', str_contains( $four, 'Worth fixing' ) && str_contains( $four, 'Add more photos — only 1 so far' ) && str_contains( $four, 'Say more in the description' ) );
	ok( 'G4b: ...never what is already there (phone and amenities are)', ! str_contains( $four, 'Add a mobile number' ) && ! str_contains( $four, 'Choose the amenities' ) );
	ok( 'G4b: the cover and the opening of the description come first', str_contains( $four, 'lform-glance' ) && str_contains( $four, 'Bright corner apartment.' ) );
	ok( 'G4b: "Worth fixing" is advice — the submit button stays live', ! (bool) preg_match( '/<button class="primary" type="submit" disabled/', $four ) );

	// A draft with gaps: named, linked, and the button says it cannot go yet.
	$gappy = (int) wp_insert_post( [ 'post_type' => 'tdh_listing', 'post_status' => 'draft', 'post_title' => 'Gappy draft', 'post_author' => $landlord->ID ] );
	$_GET  = [ 'step' => '4', 'listing' => (string) $gappy ];
	$gaps  = Listing_Form_Render::form();
	ok( 'step 4 with gaps: "A few things before review", not "Ready"', str_contains( $gaps, 'A few things before review' ) && ! str_contains( $gaps, 'Ready for review' ) );
	ok( 'step 4 with gaps: each gap links to its step', str_contains( $gaps, 'Whether utilities are included' ) && str_contains( $gaps, 'The street address' ) );
	ok( 'step 4 with gaps: submit is disabled and says why', (bool) preg_match( '/type="submit" disabled aria-describedby="lform-missing"/', $gaps ) );
	ok( 'step 4 with gaps: blanks read "Not given", never empty', str_contains( $gaps, 'Not given' ) );
	ok( 'G4b: the required gaps come before "Worth fixing"', false !== strpos( $gaps, 'lform-worth' ) && strpos( $gaps, 'id="lform-missing"' ) < strpos( $gaps, 'lform-worth' ) );

	// And the server refuses it too — the button is not the only guard.
	reset_request();
	$_GET  = [ 'listing' => (string) $gappy ];
	$_POST = [
		'tdh_action'       => 'listing_submit',
		'tdh_nonce'        => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_fair_housing' => '1',
	];
	$where = run_wizard( $form );
	ok( 'submitting a draft with gaps is refused on the server', str_contains( $where, 'step=4' ) && 'draft' === get_post_status( $gappy ) && [] !== Listing_Form::take_errors(), $where );
	wp_delete_post( $gappy, true );

	echo "\n=== the public page ===\n";

	if ( function_exists( 'tdh_listing_utilities' ) ) {
		ok( 'utilities read as one line with the details', 'Some utilities included — Water and trash' === tdh_listing_utilities( $created ) );

		update_post_meta( $created, '_tdh_utilities_included', 'no' );
		delete_post_meta( $created, '_tdh_utilities' );
		ok( '"Not included" with no details', 'Utilities not included' === tdh_listing_utilities( $created ) );

		delete_post_meta( $created, '_tdh_utilities_included' );
		update_post_meta( $created, '_tdh_utilities', 'Electric included' );
		ok( 'an older listing keeps its own words', 'Electric included' === tdh_listing_utilities( $created ) );

		update_post_meta( $created, '_tdh_utilities_included', 'partial' );
		update_post_meta( $created, '_tdh_utilities', 'Water and trash' );
	} else {
		ok( 'the theme utilities helper is loaded', false, 'tdh_listing_utilities() missing — is the theme active?' );
	}

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
	ok( 'the Fair Housing acknowledgment is recorded with a time, under the key the admin shows', false !== strtotime( (string) get_post_meta( $created, '_tdh_fair_housing_ack_at', true ) ) && '' !== (string) get_post_meta( $created, '_tdh_fair_housing_ack_at', true ) );
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

	// The hole the handler used to leave: a crafted step 1 for a NEW home at
	// the limit. The gate said "full"; the handler let "full" through.
	reset_request();
	$count_before = Membership::listing_count( $landlord->ID );
	$_POST        = [
		'tdh_action'  => 'listing_basics',
		'tdh_nonce'   => wp_create_nonce( Listing_Form::NONCE ),
		'tdh_title'   => 'One over the allowance',
		'tdh_address' => '2 Probe Way',
		'tdh_zip'     => '15232',
		'tdh_rent'    => '1500',
		'tdh_beds'    => '1',
		'tdh_baths'   => '1',
	];
	$where = run_wizard( $form );
	ok( 'a posted step 1 at the limit creates nothing — back to the gate', Membership::listing_count( $landlord->ID ) === $count_before && ! str_contains( $where, 'step=2' ), $where );
	ok( '...and no draft by that name exists', [] === get_posts( [ 'post_type' => 'tdh_listing', 'post_status' => 'any', 'title' => 'One over the allowance', 'fields' => 'ids' ] ) );

	// A page left open past its nonce: back to the SAME step, home carried,
	// and no claim that anything on it was saved.
	reset_request();
	$_GET  = [ 'listing' => (string) $second, 'step' => '2' ];
	$_POST = [ 'tdh_action' => 'listing_features', 'tdh_nonce' => 'stale', 'tdh_sqft' => '999' ];
	$where = run_wizard( $form );
	$errs  = Listing_Form::take_errors();
	ok( 'an expired page returns to the step that was posted, with the home', str_contains( $where, 'step=2' ) && carried_listing( $where ) === $second, $where );
	ok( '...saving nothing from it', '' === (string) get_post_meta( $second, '_tdh_sqft', true ) );
	ok( '...and says so, without calling the home "a draft"', isset( $errs[0] ) && str_contains( $errs[0], 'was not saved' ) && ! str_contains( $errs[0], 'draft' ), $errs[0] ?? '' );
	reset_request();

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

	// Complete, so the only thing under test is the missing membership.
	foreach ( [ '_tdh_street_address' => '9 Staff Way', '_tdh_zip' => '15213', '_tdh_price_monthly' => 2000, '_tdh_utilities_included' => 'yes', '_tdh_pet_policy' => 'no' ] as $key => $value ) {
		update_post_meta( $staff_draft, $key, $value );
	}
	if ( $city ) {
		wp_set_object_terms( $staff_draft, [ $city ], 'tdh_city' );
	}

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
if ( $city ) {
	wp_delete_term( $city, 'tdh_city' );
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
