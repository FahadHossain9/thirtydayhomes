<?php
/**
 * wp-admin for the site administrator (G5b): the Listings table's columns
 * and cells, who is sent to the portal instead, the quieted Elementor
 * notice, and the Logs screen's groups and words.
 *
 * Every user and post this suite creates is removed at the end.
 *
 * Run through verify.bat, or directly:
 *     wp eval-file wp-content/plugins/thirtydayhomes-core/tools/verify-admin.php
 */

use TDH\Admin\Listing_Table;
use TDH\Admin\Log_Screen;
use TDH\Log;
use TDH\Statuses;

function ok( string $label = '', bool $cond = false, string $detail = '' ): array {
	static $p = 0, $f = 0;
	if ( '' === $label ) { return [ $p, $f ]; }
	if ( $cond ) { ++$p; printf( "  ok    %s\n", $label ); }
	else { ++$f; printf( "  FAIL  %s%s\n", $label, '' !== $detail ? "  --> {$detail}" : '' ); }
	return [ $p, $f ];
}

// Fixtures are not events on the site.
if ( false === getenv( 'TDH_LOG_SILENT' ) ) {
	putenv( 'TDH_LOG_SILENT=1' );
}

require_once ABSPATH . 'wp-admin/includes/user.php';

$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0] ?? null;

if ( ! $admin ) {
	echo "  (no administrator on this site — checks skipped)\n";
	printf( "\nPASSED  0 passed, 0 failed\n" );
	return;
}

wp_set_current_user( $admin->ID );
$table = new Listing_Table();

// Fixtures: a landlord with one home.
foreach ( [ 'tdh_table_landlord', 'tdh_table_staff' ] as $login ) {
	$old = get_user_by( 'login', $login );
	if ( $old ) {
		wp_delete_user( $old->ID );
	}
}
$landlord = (int) wp_insert_user( [ 'user_login' => 'tdh_table_landlord', 'user_email' => 'tdh_table_landlord@example.com', 'user_pass' => wp_generate_password( 20 ), 'role' => 'tdh_landlord', 'display_name' => 'Table Probe Landlord' ] );
$staff    = (int) wp_insert_user( [ 'user_login' => 'tdh_table_staff', 'user_email' => 'tdh_table_staff@example.com', 'user_pass' => wp_generate_password( 20 ), 'role' => 'editor' ] );
$home     = (int) wp_insert_post( [ 'post_type' => 'tdh_listing', 'post_status' => 'publish', 'post_title' => 'Table probe home', 'post_author' => $landlord ] );
update_post_meta( $home, '_tdh_price_monthly', 2400 );
update_post_meta( $home, '_tdh_lat', '40.4406' );
update_post_meta( $home, '_tdh_lng', '-79.9959' );
update_post_meta( $home, '_tdh_geocode_status', 'ok' );

$cell = static function ( string $column, int $id ) use ( $table ): string {
	ob_start();
	$table->cell( $column, $id );
	return (string) ob_get_clean();
};

echo "\n=== the Listings table: columns ===\n";

$default = [
	'cb'                         => '<input type="checkbox" />',
	'title'                      => 'Title',
	'author'                     => 'Author',
	'taxonomy-tdh_property_type' => 'Property Type',
	'taxonomy-tdh_neighborhood'  => 'Neighborhood',
	'taxonomy-tdh_city'          => 'City',
	'date'                       => 'Date',
];
$cols = $table->columns( $default );
$keys = array_keys( $cols );
ok( 'photo, title, status, landlord, rent, map point, the two taxonomies that vary, then Updated', [ 'cb', 'tdh_photo', 'title', 'tdh_status', 'tdh_landlord', 'tdh_rent', 'tdh_point', 'taxonomy-tdh_property_type', 'taxonomy-tdh_neighborhood', 'tdh_updated' ] === $keys, implode( ',', $keys ) );
ok( 'Author, Date and City are gone', ! isset( $cols['author'] ) && ! isset( $cols['date'] ) && ! isset( $cols['taxonomy-tdh_city'] ) );
ok( 'the heads read in sentence case', 'Property type' === $cols['taxonomy-tdh_property_type'] && 'Map point' === $cols['tdh_point'] && 'Updated' === $cols['tdh_updated'] );
ok( 'a table without a title column still gets ours', isset( $table->columns( [ 'cb' => 'x' ] )['tdh_status'] ) );
ok( 'rent and Updated sort', 'tdh_rent' === ( $table->sortable( [] )['tdh_rent'] ?? '' ) && 'modified' === ( $table->sortable( [] )['tdh_updated'] ?? '' ) );

echo "\n=== the Listings table: cells ===\n";

ok( 'status is a worded pill in the portal\'s words — Live', str_contains( $cell( 'tdh_status', $home ), 'tdh-pill is-live' ) && str_contains( $cell( 'tdh_status', $home ), '>Live<' ) );
wp_update_post( [ 'ID' => $home, 'post_status' => 'pending' ] );
ok( '...Pending review', str_contains( $cell( 'tdh_status', $home ), 'is-pending' ) && str_contains( $cell( 'tdh_status', $home ), '>Pending review<' ) );
wp_update_post( [ 'ID' => $home, 'post_status' => Statuses::BILLING_HOLD ] );
ok( '...Hidden — membership, in the warning tone', str_contains( $cell( 'tdh_status', $home ), 'is-warning' ) && str_contains( $cell( 'tdh_status', $home ), 'Hidden — membership' ) );
wp_update_post( [ 'ID' => $home, 'post_status' => 'publish' ] );

ok( 'the landlord is a name that links to their homes, never a username', str_contains( $cell( 'tdh_landlord', $home ), 'Table Probe Landlord' ) && str_contains( $cell( 'tdh_landlord', $home ), 'author=' . $landlord ) && ! str_contains( $cell( 'tdh_landlord', $home ), 'tdh_table_landlord' ) );
ok( 'rent carries its dollar sign and thousands separator', str_contains( $cell( 'tdh_rent', $home ), '$2,400' ) );
ok( 'a found map point says Set', str_contains( $cell( 'tdh_point', $home ), 'is-live' ) && str_contains( $cell( 'tdh_point', $home ), '>Set<' ) );
ok( 'Updated is one date, with the time on hover', (bool) preg_match( '/<time datetime="[^"]+" title="[^"]+">\d{1,2} \w{3} \d{4}<\/time>/', $cell( 'tdh_updated', $home ) ) );
ok( 'no photo is a quiet tile, not a broken image', str_contains( $cell( 'tdh_photo', $home ), 'tdh-table-photo is-none' ) );

delete_post_meta( $home, '_tdh_lat' );
delete_post_meta( $home, '_tdh_lng' );
update_post_meta( $home, '_tdh_geocode_status', 'failed' );
ok( 'an address Google could not place says Not found', str_contains( $cell( 'tdh_point', $home ), 'is-danger' ) && str_contains( $cell( 'tdh_point', $home ), '>Not found<' ) );
delete_post_meta( $home, '_tdh_geocode_status' );
ok( 'a home never looked up says Not set', str_contains( $cell( 'tdh_point', $home ), '>Not set<' ) );
delete_post_meta( $home, '_tdh_price_monthly' );
ok( 'no rent is a dash', str_contains( $cell( 'tdh_rent', $home ), '—' ) && ! str_contains( $cell( 'tdh_rent', $home ), '$' ) );
wp_update_post( [ 'ID' => $home, 'post_author' => 0 ] );
ok( 'no owner reads "No landlord"', str_contains( $cell( 'tdh_landlord', $home ), 'No landlord' ) );
ok( 'no " — Pending" after a listing\'s title: the Status column says it', [] === $table->post_states( [ 'pending' => 'Pending' ], get_post( $home ) ) && [ 'draft' => 'Draft' ] === $table->post_states( [ 'draft' => 'Draft' ], null ) );

echo "\n=== who belongs here ===\n";

ok( 'an administrator keeps the table', null === Listing_Table::staff_destination() );
$staff_user = get_user_by( 'id', $staff );
$staff_user->add_cap( 'edit_others_tdh_listings' );
wp_set_current_user( $staff );
ok( 'a staff member without manage_options is sent to the portal\'s Listings', str_contains( (string) Listing_Table::staff_destination(), 'view=listings' ) );
wp_set_current_user( $landlord );
ok( 'a landlord is left to WordPress, which refuses them itself', null === Listing_Table::staff_destination() );
wp_set_current_user( $admin->ID );

echo "\n=== housekeeping ===\n";

$had_notice = get_option( 'elementor_tracker_notice', null );
delete_option( 'elementor_tracker_notice' );
$table->quiet_elementor();
ok( 'the Elementor opt-in notice is dismissed for the whole site', '1' === (string) get_option( 'elementor_tracker_notice' ) );

echo "\n=== the Logs screen: groups and words ===\n";

$groups  = Log_Screen::groups();
$covered = array_merge( ...array_column( $groups, 'features' ) );
$all     = array_keys( Log::features() );
sort( $covered );
sort( $all );
ok( 'the six groups cover every feature once', $covered === $all && count( $groups ) <= 7, implode( ',', array_keys( $groups ) ) );
ok( 'Messages holds inquiries, email and SMS', [ 'inquiries', 'email', 'sms' ] === ( $groups['messages']['features'] ?? [] ) );
add_filter( 'tdh_log_features', static fn( array $f ): array => $f + [ 'probe_feature' => 'Probe' ] );
ok( 'a feature added by filter gets a tab of its own', 'Probe' === ( Log_Screen::groups()['probe_feature']['label'] ?? '' ) );
remove_all_filters( 'tdh_log_features' );
ok( 'stored status keys read as words', 'Membership changed from active to past due.' === Log_Screen::words( 'Membership changed from active to past_due.' ) && 'A hidden (membership) home, a paused one, a rejected one.' === Log_Screen::words( 'A tdh_billing_hold home, a tdh_paused one, a tdh_rejected one.' ) );
ok( '...and ordinary words are untouched', 'past_dues and active' === Log_Screen::words( 'past_dues and active' ) );
ok( 'times are read in the market\'s zone', 'America/New_York' === Log_Screen::zone()->getName() );

echo "\n=== cleanup ===\n";

wp_delete_post( $home, true );
wp_delete_user( $landlord );
wp_delete_user( $staff );
if ( null !== $had_notice ) {
	update_option( 'elementor_tracker_notice', $had_notice, false );
}
wp_set_current_user( 0 );
echo "  fixtures removed\n";

[ $passed, $failed ] = ok();
printf( "\n%s  %d passed, %d failed\n", $failed ? 'FAILED' : 'PASSED', $passed, $failed );
if ( $failed && defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::halt( 1 );
}
