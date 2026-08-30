<?php
/**
 * Image optimiser for theme and seed assets.
 *
 * Run with:
 *   D:\xampp\php\php.exe wp-content/plugins/thirtydayhomes-core/tools/optimise-images.php
 *
 * Plain PHP — no WordPress needed. Uses GD, which XAMPP ships with WebP
 * and AVIF support enabled.
 *
 * WHY THESE TARGETS
 *
 * The hero is the Largest Contentful Paint element on the homepage and is
 * served directly as a CSS background, so its file size is page weight,
 * one to one. 1920px wide covers all but ultra-wide displays, and WebP
 * around quality 58 is where the artefacts stop being visible on a
 * photograph at that size.
 *
 * Listing photographs are different: WordPress generates cropped sub-sizes
 * and serves those through srcset, so the original is a master copy that
 * mostly never reaches a browser. It stays larger on purpose — 1400px is
 * the source for the 1300px gallery crop.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( "Run this from the command line.\n" );
}

/**
 * Load an image of any supported type into a GD resource.
 */
function tdh_load_image( string $path ) {
	$info = @getimagesize( $path );

	if ( ! $info ) {
		return null;
	}

	return match ( $info[2] ) {
		IMAGETYPE_JPEG => @imagecreatefromjpeg( $path ),
		IMAGETYPE_PNG  => @imagecreatefrompng( $path ),
		IMAGETYPE_WEBP => @imagecreatefromwebp( $path ),
		default        => null,
	};
}

/**
 * Resize to a maximum width and write as WebP.
 *
 * @return array{0:int,1:int,2:int} [ bytes, width, height ]
 */
function tdh_write_webp( string $source, string $target, int $max_width, int $quality ): array {

	$image = tdh_load_image( $source );

	if ( ! $image ) {
		throw new RuntimeException( "cannot read {$source}" );
	}

	$width  = imagesx( $image );
	$height = imagesy( $image );

	if ( $width > $max_width ) {
		$new_height = (int) round( $height * ( $max_width / $width ) );
		$resized    = imagecreatetruecolor( $max_width, $new_height );

		// Preserve transparency for logos and marks.
		imagealphablending( $resized, false );
		imagesavealpha( $resized, true );

		imagecopyresampled( $resized, $image, 0, 0, 0, 0, $max_width, $new_height, $width, $height );
		imagedestroy( $image );

		$image  = $resized;
		$width  = $max_width;
		$height = $new_height;
	}

	imagewebp( $image, $target, $quality );
	imagedestroy( $image );

	clearstatcache( true, $target );

	return [ (int) filesize( $target ), $width, $height ];
}

$root  = dirname( __DIR__, 3 );
$theme = $root . '/themes/thirtydayhomes/assets';
$seed  = __DIR__ . '/seed-images';

$tasks = [
	// The hero IS page weight — compress it hard.
	[ 'src' => $theme . '/hero-home.webp', 'out' => $theme . '/hero-home.webp', 'w' => 1920, 'q' => 58 ],

	// The brand mark renders at 51x59. 160px wide covers 3x displays with
	// room to spare; 589 KB for a 51px logo was pure waste on every page.
	[ 'src' => $theme . '/brand-mark.png', 'out' => $theme . '/brand-mark.webp', 'w' => 160, 'q' => 88 ],
];

// Every other theme photograph. Half-width at most, so 1200px covers a 2x
// display comfortably. Picked up automatically — adding an image to
// assets/ means it gets compressed without editing this list.
foreach ( glob( $theme . '/*.webp' ) ?: [] as $file ) {

	// Already handled above with their own targets.
	if ( in_array( basename( $file ), [ 'hero-home.webp', 'brand-mark.webp' ], true ) ) {
		continue;
	}

	$tasks[] = [ 'src' => $file, 'out' => $file, 'w' => 1200, 'q' => 68 ];
}

foreach ( glob( $seed . '/*.webp' ) ?: [] as $file ) {
	$tasks[] = [ 'src' => $file, 'out' => $file, 'w' => 1400, 'q' => 68 ];
}

printf( "%-34s %10s %10s %8s  %s\n", 'file', 'before', 'after', 'saved', 'dimensions' );

$total_before = 0;
$total_after  = 0;

foreach ( $tasks as $task ) {

	if ( ! is_readable( $task['src'] ) ) {
		printf( "%-34s  MISSING\n", basename( $task['src'] ) );
		continue;
	}

	$before = (int) filesize( $task['src'] );

	try {
		[ $after, $w, $h ] = tdh_write_webp( $task['src'], $task['out'], $task['w'], $task['q'] );
	} catch ( Throwable $e ) {
		printf( "%-34s  FAILED: %s\n", basename( $task['src'] ), $e->getMessage() );
		continue;
	}

	$total_before += $before;
	$total_after  += $after;

	printf(
		"%-34s %7d KB %7d KB %7d%%  %dx%d\n",
		basename( $task['out'] ),
		(int) round( $before / 1024 ),
		(int) round( $after / 1024 ),
		(int) round( ( 1 - $after / $before ) * 100 ),
		$w,
		$h
	);
}

printf(
	"%-34s %7d KB %7d KB %7d%%\n",
	'TOTAL',
	(int) round( $total_before / 1024 ),
	(int) round( $total_after / 1024 ),
	(int) round( ( 1 - $total_after / $total_before ) * 100 )
);
