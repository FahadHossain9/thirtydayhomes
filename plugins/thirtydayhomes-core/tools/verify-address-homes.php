<?php
/**
 * Homes from the client's address list (R55).
 *
 *     wp eval-file wp-content/plugins/thirtydayhomes-core/tools/verify-address-homes.php
 *
 * Writes its own two-row list (plus one bad row) in place of the real one,
 * restores the real one afterwards, and removes every home it made.
 *
 * @package ThirtyDayHomes
 */

use TDH\Setup\Address_Homes;
use TDH\Setup\Importer;

defined( 'ABSPATH' ) || exit;

function ok( string $label = '', bool $cond = false, string $detail = '' ): array {
	static $p = 0, $f = 0;
	if ( '' === $label ) { return [ $p, $f ]; }
	if ( $cond ) { ++$p; printf( "  ok    %s\n", $label ); }
	else { ++$f; printf( "  FAIL  %s%s\n", $label, '' !== $detail ? "  --> {$detail}" : '' ); }
	return [ $p, $f ];
}

require_once ABSPATH . 'wp-admin/includes/user.php';

$file   = Address_Homes::path();
$backup = is_readable( $file ) ? (string) file_get_contents( $file ) : null;
$owner  = wp_insert_user( [ 'user_login' => 'tdh_r55_owner', 'user_email' => 'r55-owner@example.com', 'user_pass' => wp_generate_password( 20 ), 'role' => 'tdh_landlord' ] );
$owner  = is_wp_error( $owner ) ? (int) get_user_by( 'login', 'tdh_r55_owner' )->ID : (int) $owner;

$write = static function ( string $body ) use ( $file ): void {
	file_put_contents( $file, "<?php\ndefined( 'ABSPATH' ) || exit;\nreturn " . $body . ";\n" );
};
$run = static fn(): array => ( new Importer() )->run( [ 'addresses' ] );
$homes = static fn(): array => get_posts( [ 'post_type' => TDH\Post_Types::LISTING, 'author' => $owner, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'tdh_bypass_visibility' => true ] );

echo "\n=== the step ===\n";

$steps = Importer::steps();
ok( 'the step is listed, unticked by default', isset( $steps['addresses'] ) && false === ( $steps['addresses']['checked'] ?? true ) );
ok( 'running "everything" does not include it', ! in_array( 'addresses', array_keys( array_filter( $steps, static fn( $s ) => $s['checked'] ?? true ) ), true ) );

echo "\n=== no file ===\n";

if ( is_readable( $file ) ) { unlink( $file ); }
$r = $run();
ok( 'without the file it says what to upload and adds nothing', str_contains( implode( ' ', $r['log'] ), 'Upload ' . Address_Homes::FILE ) && ! $homes() );

echo "\n=== a list ===\n";

$write( "[ 'owner' => 'nobody-r55@example.com', 'homes' => [ [ '1 Probe St', 'Pittsburgh', 'PA', '15213' ] ] ]" );
$r = $run();
ok( 'an owner with no account adds nothing and says why', str_contains( implode( ' ', $r['log'] ), 'names no owner' ) && ! $homes() );

$write( "[ 'owner' => 'r55-owner@example.com', 'homes' => [ [ '200 Lothrop St', 'Pittsburgh', 'PA', '15213' ], [ '5230 Centre Ave', 'Pittsburgh', 'PA', '15232' ], [ 'No Zip Rd', 'Pittsburgh', 'PA', '' ] ] ]" );
$r   = $run();
$ids = $homes();
ok( 'two good rows make two homes', 2 === count( $ids ), (string) count( $ids ) );
ok( '...the bad row is named, not silently dropped', str_contains( implode( ' ', $r['log'] ), 'Row 3 skipped' ) );
$one = $ids ? get_post( (int) min( $ids ) ) : null;
ok( '...live, owned by the named landlord', $one && TDH\Statuses::public_status() === $one->post_status && $owner === (int) $one->post_author );
ok( '...titled by street and city, never the house number', $one && '1-bedroom home on Lothrop St, Pittsburgh' === $one->post_title, $one ? $one->post_title : '' );
ok( '...marked as sample details, in words on the page', $one && get_post_meta( $one->ID, Address_Homes::META_SAMPLE, true ) && str_contains( $one->post_content, 'Sample details' ) );
ok( '...with rent, rooms and a photo', $one && (int) get_post_meta( $one->ID, '_tdh_price_monthly', true ) > 0 && (int) get_post_meta( $one->ID, '_tdh_beds', true ) > 0 && has_post_thumbnail( $one->ID ) );
ok( '...and the street kept for the map, as every home does', $one && '200 Lothrop St' === get_post_meta( $one->ID, '_tdh_street_address', true ) );

$r = $run();
ok( 'running again adds nothing and says so', 2 === count( $homes() ) && str_contains( implode( ' ', $r['log'] ), 'already on the site' ) );

echo "\n=== cleanup ===\n";

foreach ( $homes() as $id ) {
	$thumb = (int) get_post_thumbnail_id( $id );
	if ( $thumb ) { wp_delete_attachment( $thumb, true ); }
	wp_delete_post( $id, true );
}
wp_delete_user( $owner );
if ( null === $backup ) { if ( is_readable( $file ) ) { unlink( $file ); } } else { file_put_contents( $file, $backup ); }
ok( 'the real list is back exactly as it was', null === $backup ? ! is_readable( $file ) : $backup === file_get_contents( $file ) );
ok( 'no probe home is left', ! get_user_by( 'login', 'tdh_r55_owner' ) );

[ $pass, $fail ] = ok();
printf( "\n%s  %d passed, %d failed\n", $fail ? 'FAILED' : 'PASSED', $pass, $fail );
if ( $fail ) {
	exit( 1 );
}
