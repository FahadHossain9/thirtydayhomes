<?php
/**
 * Listing photos: order, cover, descriptions, the upload clean-up, and the
 * property page gallery.
 *
 * Run through verify.bat, or directly:
 *     wp eval-file wp-content/plugins/thirtydayhomes-core/tools/verify-photos.php
 */

use TDH\Listing_Form;
use TDH\Listing_Form_Render;
use TDH\Listing_Photos;
use TDH\Membership;

function ok( string $label = '', bool $cond = false, string $detail = '' ): array {
	static $p = 0, $f = 0;
	if ( '' === $label ) { return [ $p, $f ]; }
	if ( $cond ) { ++$p; printf( "  ok    %s\n", $label ); }
	else { ++$f; printf( "  FAIL  %s%s\n", $label, '' !== $detail ? "  --> {$detail}" : '' ); }
	return [ $p, $f ];
}

/** Run the wizard handler and return where it redirected. */
function run_wizard( Listing_Form $form ): string {
	$catch = static function ( $location ) { throw new RuntimeException( (string) $location ); };
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

/**
 * A real JPEG, larger than the edge limit, carrying an EXIF block — the
 * kind of file a phone produces, minus the pixels worth looking at.
 */
function big_jpeg_with_exif( string $path, int $w, int $h ): void {
	$img = imagecreatetruecolor( $w, $h );
	imagefilledrectangle( $img, 0, 0, $w, $h, imagecolorallocate( $img, 200, 170, 100 ) );
	ob_start();
	imagejpeg( $img, null, 90 );
	$jpeg = (string) ob_get_clean();
	imagedestroy( $img );

	// APP1 "Exif\0\0" + a minimal big-endian TIFF header with an empty IFD,
	// inserted straight after the SOI marker.
	$payload = "Exif\0\0" . "MM\0*\0\0\0\x08" . "\0\0" . "\0\0\0\0";
	$app1    = "\xFF\xE1" . pack( 'n', strlen( $payload ) + 2 ) . $payload;

	file_put_contents( $path, substr( $jpeg, 0, 2 ) . $app1 . substr( $jpeg, 2 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

/** Stage fake uploads: [ [name, source path], ... ]. */
function stage( array $files ): void {
	$_FILES['tdh_photos'] = [ 'name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => [] ];
	foreach ( $files as [ $name, $path, $type ] ) {
		$_FILES['tdh_photos']['name'][]     = $name;
		$_FILES['tdh_photos']['type'][]     = $type;
		$_FILES['tdh_photos']['tmp_name'][] = $path;
		$_FILES['tdh_photos']['error'][]    = 0;
		$_FILES['tdh_photos']['size'][]     = filesize( $path );
	}
}

function post_step3( int $listing, array $extra = [] ): void {
	$_GET  = [ 'listing' => (string) $listing ];
	$_POST = array_merge(
		[
			'tdh_action'      => 'listing_photos',
			'tdh_nonce'       => wp_create_nonce( Listing_Form::NONCE ),
			'tdh_description' => 'Photo probe home.',
		],
		$extra
	);
}

/** Render the shared property-page gallery with stable fixture labels. */
function photo_probe_render_gallery( array $ids ): string {
	ob_start();
	get_template_part(
		'template-parts/listing-gallery',
		null,
		[
			'ids'   => $ids,
			'title' => 'Photo probe home',
			'city'  => 'Probeville',
		]
	);
	return (string) ob_get_clean();
}

/** Parse a rendered gallery fragment without relying on attribute order. */
function photo_probe_gallery_dom( string $html ): array {
	$dom      = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8"?><!doctype html><html><body><div id="photo-probe">' . $html . '</div></body></html>' );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	return [ $dom, new DOMXPath( $dom ) ];
}

/** Make a real renderable image attachment for gallery boundary fixtures. */
function photo_probe_make_image( int $parent, string $path, string $title, string $alt, string $bytes ): int {
	file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$id = wp_insert_attachment(
		[
			'post_title'     => $title,
			'post_mime_type' => 'image/png',
			'post_status'    => 'inherit',
		],
		$path,
		$parent,
		true
	);

	if ( is_wp_error( $id ) ) {
		return 0;
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	update_attached_file( (int) $id, $path );
	wp_update_attachment_metadata( (int) $id, wp_generate_attachment_metadata( (int) $id, $path ) );

	if ( '' !== $alt ) {
		update_post_meta( (int) $id, '_wp_attachment_image_alt', $alt );
	}

	return (int) $id;
}

$form = new Listing_Form();
add_filter( 'tdh_listing_upload_overrides', static fn( array $o ): array => $o + [ 'action' => 'wp_handle_sideload' ] );

$login    = 'tdh_photos_probe';
$existing = get_user_by( 'login', $login );
if ( $existing ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $existing->ID );
}
$landlord = (int) wp_insert_user( [ 'user_login' => $login, 'user_email' => 'photos-probe@example.com', 'user_pass' => wp_generate_password( 20 ), 'role' => 'tdh_landlord' ] );
update_user_meta( $landlord, Membership::META_STATUS, Membership::ACTIVE );
update_user_meta( $landlord, Membership::META_QUOTA, 3 );
wp_set_current_user( $landlord );

$listing = (int) wp_insert_post( [ 'post_type' => 'tdh_listing', 'post_status' => 'draft', 'post_title' => 'Photo probe home', 'post_author' => $landlord ] );
$other   = (int) wp_insert_post( [ 'post_type' => 'tdh_listing', 'post_status' => 'draft', 'post_title' => 'Someone else', 'post_author' => 1 ] );

$dir = wp_upload_dir()['basedir'] . '/tdh-photos-probe';
wp_mkdir_p( $dir );
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );

echo "\n=== upload: resized, cleaned, in order ===\n";

big_jpeg_with_exif( "$dir/big.jpg", 3000, 2000 );
ok( 'the fixture really has an EXIF block to remove', str_contains( (string) file_get_contents( "$dir/big.jpg" ), "Exif\0\0" ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
file_put_contents( "$dir/b.png", $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions
file_put_contents( "$dir/c.png", $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions

post_step3( $listing );
stage( [ [ 'big.jpg', "$dir/big.jpg", 'image/jpeg' ], [ 'b.png', "$dir/b.png", 'image/png' ], [ 'c.png', "$dir/c.png", 'image/png' ] ] );
$where  = run_wizard( $form );
$_FILES = [];
$ids    = Listing_Photos::ordered( $listing );

ok( 'three photos stored, the step moves on', 3 === count( $ids ) && str_contains( $where, 'step=4' ) && str_contains( $where, 'added=3' ), $where );
[ $a, $b, $c ] = array_pad( $ids, 3, 0 );
ok( 'they keep the order they were chosen in', get_the_title( $a ) === 'big' && get_the_title( $b ) === 'b' && get_the_title( $c ) === 'c', implode( ',', array_map( 'get_the_title', $ids ) ) );
ok( 'the first is the cover', (int) get_post_thumbnail_id( $listing ) === $a );

$meta = wp_get_attachment_metadata( $a );
ok( 'a 3000 px photo is stored at 2000 px on its longest side', is_array( $meta ) && 2000 === (int) ( $meta['width'] ?? 0 ) && 1333 === (int) ( $meta['height'] ?? 0 ), wp_json_encode( [ $meta['width'] ?? null, $meta['height'] ?? null ] ) );
ok( 'WordPress kept no oversized "original" beside it', empty( $meta['original_image'] ) );

$stored = (string) file_get_contents( (string) get_attached_file( $a ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
ok( 'the stored file carries no EXIF block (no GPS can leak)', '' !== $stored && ! str_contains( $stored, "Exif\0\0" ) );

echo "\n=== arranging ===\n";

post_step3( $listing, [ 'tdh_photo_move' => $c . ':up' ] );
$where = run_wizard( $form );
ok( 'moving a photo stays on the photo step and says so', str_contains( $where, 'step=3' ) && str_contains( $where, 'arranged=1' ), $where );
ok( '"up" swaps it with the one before', [ $a, $c, $b ] === Listing_Photos::ordered( $listing ) );

post_step3( $listing, [ 'tdh_photo_move' => $a . ':up' ] );
run_wizard( $form );
ok( 'the first photo cannot move earlier', [ $a, $c, $b ] === Listing_Photos::ordered( $listing ) );

post_step3( $listing, [ 'tdh_photo_cover' => (string) $b ] );
run_wizard( $form );
ok( 'Make cover puts the photo first', [ $b, $a, $c ] === Listing_Photos::ordered( $listing ) );
ok( '...and makes it the featured image', (int) get_post_thumbnail_id( $listing ) === $b );

$foreign = (int) wp_insert_attachment( [ 'post_title' => 'Not yours', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ], '', $other );
post_step3( $listing, [ 'tdh_photo_cover' => (string) $foreign, 'tdh_photo_move' => $foreign . ':up' ] );
run_wizard( $form );
ok( 'another listing\'s photo cannot be moved or made cover here', [ $b, $a, $c ] === Listing_Photos::ordered( $listing ) && (int) get_post_thumbnail_id( $listing ) === $b );

echo "\n=== descriptions ===\n";

post_step3( $listing, [ 'tdh_alt' => [ $a => '  Bright living room with a sofa  ', $foreign => 'Hijacked' ] ] );
run_wizard( $form );
ok( 'a description is saved as the photo\'s alt text, trimmed', 'Bright living room with a sofa' === Listing_Photos::alt( $a ) );
ok( 'another listing\'s photo cannot be described from here', '' === Listing_Photos::alt( $foreign ) );

post_step3( $listing, [ 'tdh_alt' => [ $a => str_repeat( 'x', 200 ) ] ] );
run_wizard( $form );
ok( 'descriptions are capped at 125 characters', 125 === mb_strlen( Listing_Photos::alt( $a ) ) );

post_step3( $listing, [ 'tdh_alt' => [ $a => '' ] ] );
run_wizard( $form );
ok( 'emptying a description removes it', '' === Listing_Photos::alt( $a ) );

echo "\n=== removing ===\n";

post_step3( $listing, [ 'tdh_remove' => [ (string) $b ] ] );
run_wizard( $form );
ok( 'removing the cover promotes the next photo', [ $a, $c ] === Listing_Photos::ordered( $listing ) && (int) get_post_thumbnail_id( $listing ) === $a );

post_step3( $listing, [ 'tdh_remove' => [ (string) $a, (string) $c ] ] );
run_wizard( $form );
ok( 'removing every photo leaves no cover behind', [] === Listing_Photos::ordered( $listing ) && ! has_post_thumbnail( $listing ) );

echo "\n=== refusals ===\n";

file_put_contents( "$dir/IMG_0042.HEIC", 'not really heic' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
file_put_contents( "$dir/notes.jpg", 'this is text, not a photo' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
post_step3( $listing );
stage( [ [ 'IMG_0042.HEIC', "$dir/IMG_0042.HEIC", 'image/heic' ], [ 'notes.jpg', "$dir/notes.jpg", 'image/jpeg' ] ] );
$where  = run_wizard( $form );
$_FILES = [];
$errors = implode( ' ', Listing_Form::take_errors() );
ok( 'an iPhone HEIC photo is refused with how to fix it', str_contains( $errors, 'IMG_0042.HEIC' ) && str_contains( $errors, 'Most Compatible' ), $errors );
ok( 'a file that is not really a photo is named and refused', str_contains( $errors, 'notes.jpg' ) );
ok( 'nothing was stored from either', [] === Listing_Photos::ordered( $listing ) && str_contains( $where, 'step=3' ) );

echo "\n=== older listings keep their cover ===\n";

$legacy = (int) wp_insert_post( [ 'post_type' => 'tdh_listing', 'post_status' => 'draft', 'post_title' => 'Legacy', 'post_author' => $landlord ] );
$l1     = (int) wp_insert_attachment( [ 'post_title' => 'l1', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ], '', $legacy );
$l2     = (int) wp_insert_attachment( [ 'post_title' => 'l2', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ], '', $legacy );
// Direct meta: set_post_thumbnail() refuses an attachment with no file.
update_post_meta( $legacy, '_thumbnail_id', $l2 );
ok( 'a never-arranged listing shows its existing cover first', [ $l2, $l1 ] === Listing_Photos::ordered( $legacy ) );

echo "\n=== the photo step ===\n";

post_step3( $listing );
stage( [ [ 'b.png', ( static function () use ( $dir, $png ) { file_put_contents( "$dir/d.png", $png ); return "$dir/d.png"; } )(), 'image/png' ], [ 'e.png', ( static function () use ( $dir, $png ) { file_put_contents( "$dir/e.png", $png ); return "$dir/e.png"; } )(), 'image/png' ] ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
run_wizard( $form );
$_FILES = [];

$_POST = [];
$_GET  = [ 'step' => '3', 'listing' => (string) $listing, 'arranged' => '1' ];
$three = Listing_Form_Render::form();
ok( 'photos show as a numbered list with the cover marked', str_contains( $three, 'lform-photos' ) && str_contains( $three, 'lform-photo-badge' ) && str_contains( $three, '2 of 10' ) );
ok( 'each photo has arrows, Make cover, a description and Remove', str_contains( $three, 'name="tdh_photo_move"' ) && str_contains( $three, 'name="tdh_photo_cover"' ) && str_contains( $three, 'name="tdh_alt[' ) && str_contains( $three, 'name="tdh_remove[]"' ) );
ok( 'the cover has no "Make cover", and its "earlier" arrow is disabled', 1 === substr_count( $three, 'name="tdh_photo_cover"' ) && (bool) preg_match( '/value="\d+:up"\s+disabled/', $three ) );
ok( '...its slot says the cover is shown first instead', 1 === substr_count( $three, 'lform-photo-cover-note' ) );
ok( 'the arrows name what they do for screen readers', str_contains( $three, 'Move photo 2 earlier' ) );
ok( 'after arranging, the step confirms the order was saved', str_contains( $three, 'Photo order saved' ) );

for ( $i = 0; $i < 8; $i++ ) {
	wp_insert_attachment( [ 'post_title' => "fill-$i", 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ], '', $listing );
}
$_GET = [ 'step' => '3', 'listing' => (string) $listing ];
$full = Listing_Form_Render::form();
ok( 'at ten photos the upload box becomes a note saying what to do', str_contains( $full, 'lform-drop--full' ) && str_contains( $full, 'All 10 photos are in use' ) && ! str_contains( $full, 'name="tdh_photos[]"' ) );

$ordered = Listing_Photos::ordered( $listing );
ok( 'photos attached outside the form follow the arranged ones, not jump ahead', 'fill-0' === get_the_title( $ordered[2] ?? 0 ) && ! str_starts_with( get_the_title( $ordered[0] ?? 0 ), 'fill-' ) );

echo "\n=== the property page gallery ===\n";

$gallery_ids = array_slice( Listing_Photos::ordered( $listing ), 0, 2 );
$empty = photo_probe_render_gallery( [] );
ok( 'zero photos render no gallery shell or controls', '' === trim( $empty ) );

$fileless_id = (int) ( $ordered[2] ?? 0 );
$deleted_id  = photo_probe_make_image( $other, "$dir/gallery-{$other}-deleted.png", 'deleted gallery fixture', '', $png );
wp_delete_attachment( $deleted_id, true );
$text_path = "$dir/gallery-{$other}.txt";
file_put_contents( $text_path, 'not an image' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
$text_id = (int) wp_insert_attachment(
	[
		'post_title'     => 'wrong gallery type',
		'post_mime_type' => 'text/plain',
		'post_status'    => 'inherit',
	],
	$text_path,
	$other
);
update_attached_file( $text_id, $text_path );

$invalid = photo_probe_render_gallery( [ 0, $fileless_id, $deleted_id, $text_id, $listing ] );
ok( 'deleted, fileless, non-image and non-attachment IDs render no gallery', '' === trim( $invalid ) );

$mixed = photo_probe_render_gallery( [ $fileless_id, $gallery_ids[0], $deleted_id, $text_id, $gallery_ids[1] ] );
[ $mixed_dom, $mixed_xpath ] = photo_probe_gallery_dom( $mixed );
$mixed_root = '//*[@id="photo-probe"]';
$mixed_tiles = $mixed_xpath->query( $mixed_root . '//section[contains(concat(" ", normalize-space(@class), " "), " detail-gallery ")]/figure[contains(concat(" ", normalize-space(@class), " "), " detail-gallery-tile ")]' );
$mixed_slides = $mixed_xpath->query( $mixed_root . '//dialog//li[@data-tdh-photo]' );
ok(
	'invalid photos are skipped before the count and the two valid photos are renumbered',
	str_contains( $mixed, 'data-count="2"' )
		&& str_contains( $mixed, 'data-total="2"' )
		&& 2 === $mixed_tiles->length
		&& 2 === $mixed_slides->length
		&& '0' === $mixed_slides->item( 0 )->getAttribute( 'data-tdh-photo' )
		&& '1' === $mixed_slides->item( 1 )->getAttribute( 'data-tdh-photo' )
);
unset( $mixed_dom );

$single = photo_probe_render_gallery( [ $gallery_ids[0] ] );
[ $single_dom, $single_xpath ] = photo_probe_gallery_dom( $single );
$single_root = '//*[@id="photo-probe"]';
$single_image = $single_xpath->query( $single_root . '//section[@data-count="1"]//img' );
ok(
	'one photo has its fallback description and no false viewer controls',
	1 === $single_image->length
		&& 'Photo probe home — furnished rental in Probeville' === $single_image->item( 0 )->getAttribute( 'alt' )
		&& 0 === $single_xpath->query( $single_root . '//dialog | ' . $single_root . '//*[@data-tdh-gallery-open] | ' . $single_root . '//*[@data-tdh-gallery-prev] | ' . $single_root . '//*[@data-tdh-gallery-next]' )->length
		&& ! str_contains( $single, '1 photos' )
);
unset( $single_dom );

$gallery_ten = [];
for ( $i = 1; $i <= 10; $i++ ) {
	$gallery_ten[] = photo_probe_make_image( $other, "$dir/gallery-{$other}-{$i}.png", "gallery source {$i}", '', $png );
}
$gallery_ten = array_reverse( $gallery_ten );
foreach ( $gallery_ten as $i => $gallery_id ) {
	update_post_meta( $gallery_id, '_wp_attachment_image_alt', 'Gallery photo ' . ( $i + 1 ) );
}

$gallery = photo_probe_render_gallery( $gallery_ten );
[ $gallery_dom, $gallery_xpath ] = photo_probe_gallery_dom( $gallery );
$gallery_root = '//*[@id="photo-probe"]';
$preview_tiles = $gallery_xpath->query( $gallery_root . '//section[contains(concat(" ", normalize-space(@class), " "), " detail-gallery ")]/figure[contains(concat(" ", normalize-space(@class), " "), " detail-gallery-tile ")]' );
$preview_images = $gallery_xpath->query( $gallery_root . '//section[contains(concat(" ", normalize-space(@class), " "), " detail-gallery ")]//figure[contains(concat(" ", normalize-space(@class), " "), " detail-gallery-tile ")]/img' );
$viewer_slides = $gallery_xpath->query( $gallery_root . '//dialog//ol[contains(concat(" ", normalize-space(@class), " "), " gallery-dialog-list ")]/li[@data-tdh-photo]' );
$viewer_images = $gallery_xpath->query( $gallery_root . '//dialog//ol[contains(concat(" ", normalize-space(@class), " "), " gallery-dialog-list ")]/li/figure/img' );

$preview_order = [];
foreach ( $preview_images as $image ) {
	$preview_order[] = $image->getAttribute( 'alt' );
}
$viewer_order = [];
$viewer_indexes = [];
foreach ( $viewer_slides as $slide ) {
	$viewer_indexes[] = $slide->getAttribute( 'data-tdh-photo' );
}
foreach ( $viewer_images as $image ) {
	$viewer_order[] = $image->getAttribute( 'alt' );
}

ok(
	'ten photos keep the landlord order: five previews and all ten viewer slides',
	str_contains( $gallery, 'data-count="5"' )
		&& str_contains( $gallery, 'data-total="10"' )
		&& 5 === $preview_tiles->length
		&& 10 === $viewer_slides->length
		&& [ 'Gallery photo 1', 'Gallery photo 2', 'Gallery photo 3', 'Gallery photo 4', 'Gallery photo 5' ] === $preview_order
		&& array_map( static fn( int $i ): string => 'Gallery photo ' . $i, range( 1, 10 ) ) === $viewer_order
		&& array_map( 'strval', range( 0, 9 ) ) === $viewer_indexes
);

$captions = $gallery_xpath->query( $gallery_root . '//dialog//figcaption' );
ok(
	'captions stay with every ordered photo, including beyond the preview',
	10 === $captions->length
		&& 'Gallery photo 1' === trim( $captions->item( 0 )->textContent )
		&& 'Gallery photo 10' === trim( $captions->item( 9 )->textContent )
);

$dialog = $gallery_xpath->query( $gallery_root . '//dialog[@aria-labelledby="tdh-gallery-title"]' );
$title = $gallery_xpath->query( $gallery_root . '//*[@id="tdh-gallery-title"]' );
$position = $gallery_xpath->query( $gallery_root . '//*[@data-tdh-gallery-position][@aria-live="polite"][@aria-atomic="true"]' );
$previous = $gallery_xpath->query( $gallery_root . '//button[@data-tdh-gallery-prev][@aria-label="Previous photo"][@disabled]' );
$next = $gallery_xpath->query( $gallery_root . '//button[@data-tdh-gallery-next][@aria-label="Next photo" and not(@disabled)]' );
$close = $gallery_xpath->query( $gallery_root . '//button[@data-tdh-gallery-close][@aria-label="Close photos"]' );
ok(
	'the viewer names the total, current position and all three controls',
	1 === $dialog->length
		&& 1 === $title->length
		&& str_contains( preg_replace( '/\s+/u', ' ', trim( $title->item( 0 )->textContent ) ), '10 photos · Photo probe home' )
		&& 1 === $position->length
		&& 'Photo 1 of 10' === trim( $position->item( 0 )->textContent )
		&& 1 === $previous->length
		&& 1 === $next->length
		&& 1 === $close->length
		&& str_contains( preg_replace( '/\s+/u', ' ', $gallery ), 'Show all 10 photos' )
);

$visible_slides = $gallery_xpath->query( $gallery_root . '//dialog//li[@data-tdh-photo and not(@hidden)]' );
$hidden_slides = $gallery_xpath->query( $gallery_root . '//dialog//li[@data-tdh-photo and @hidden]' );
$openers = $gallery_xpath->query( $gallery_root . '//section//*[@data-tdh-gallery-open]' );
$hidden_openers = $gallery_xpath->query( $gallery_root . '//section//*[@data-tdh-gallery-open and @hidden]' );
ok(
	'the first viewer photo is ready and enhancement controls stay hidden without JavaScript',
	1 === $visible_slides->length
		&& '0' === $visible_slides->item( 0 )->getAttribute( 'data-tdh-photo' )
		&& 9 === $hidden_slides->length
		&& 6 === $openers->length
		&& $openers->length === $hidden_openers->length
		&& 0 === $gallery_xpath->query( $gallery_root . '//section//img[@hidden]' )->length
);

$gallery_script = (string) file_get_contents( get_template_directory() . '/assets/gallery.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
ok(
	'the viewer script supports bounded buttons, arrow keys, Home / End and exact focus return',
	str_contains( $gallery_script, 'previous.disabled' )
		&& str_contains( $gallery_script, 'next.disabled' )
		&& str_contains( $gallery_script, 'var focused = document.activeElement' )
		&& str_contains( $gallery_script, 'previous.disabled && previous === focused' )
		&& str_contains( $gallery_script, 'next.disabled && next === focused' )
		&& str_contains( $gallery_script, "'ArrowLeft'" )
		&& str_contains( $gallery_script, "'ArrowRight'" )
		&& str_contains( $gallery_script, "'Home'" )
		&& str_contains( $gallery_script, "'End'" )
		&& str_contains( $gallery_script, 'opener.focus' )
);
$gallery_css = (string) file_get_contents( get_template_directory() . '/style.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
ok(
	'no-script gallery controls are hidden visually as well as in the markup',
	str_contains( $gallery_css, '.detail-gallery > .detail-gallery-all[hidden]' )
		&& str_contains( $gallery_css, '.detail-gallery > .detail-gallery-tile > .detail-gallery-open[hidden]' )
);
unset( $gallery_dom );

ok( 'the property page uses the gallery', str_contains( (string) file_get_contents( get_template_directory() . '/single-tdh_listing.php' ), "template-parts/listing-gallery" ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

/* Fixtures away. */
wp_set_current_user( 0 );
$_GET = $_POST = [];
foreach ( [ $listing, $legacy, $other ] as $parent ) {
	foreach ( get_posts( [ 'post_type' => 'attachment', 'post_parent' => $parent, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ] ) as $att ) {
		wp_delete_attachment( (int) $att, true );
	}
	wp_delete_post( $parent, true );
}
array_map( 'unlink', glob( $dir . '/*' ) ?: [] );
@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $landlord );
echo "\n  fixtures removed\n";

[ $p, $f ] = ok();
printf( "\n%s  %d passed, %d failed\n\n", $f ? 'FAILED' : 'PASSED', $p, $f );

if ( $f ) {
	WP_CLI::halt( 1 );
}
