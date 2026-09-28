<?php
/**
 * A listing's photographs: order, cover, descriptions, and what an upload
 * is turned into before it is stored.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * ─── ONE ORDER, AND THE COVER IS ITS FIRST PHOTO ──────────────────────────
 *
 * Order lives in each attachment's `menu_order`. The cover — the featured
 * image the search cards, the page and the social preview all use — is
 * simply whichever photo is first. There is no second, separate "cover"
 * setting to drift out of step with the order: "Make cover" moves a photo
 * to the front, and removing the cover promotes the next one.
 *
 * ─── WHAT HAPPENS TO AN UPLOAD ────────────────────────────────────────────
 *
 * Every photo is re-drawn before WordPress stores it:
 *   - turned the right way up (phones record rotation as a flag, not pixels)
 *   - reduced so its longest side is at most MAX_EDGE pixels
 *   - re-encoded, which drops ALL embedded metadata
 *
 * The last point is the one that matters most. A phone photo taken inside
 * the home carries the GPS coordinates of the home. This marketplace never
 * publishes a street address — a furnished rental that is often empty is a
 * target — and a photo file would publish it anyway, to anyone who opened
 * it in a photo viewer. WordPress keeps the uploaded original, so stripping
 * only the generated sizes would not be enough; the file is cleaned before
 * WordPress ever sees it.
 */
final class Listing_Photos {

	/** Longest side, in pixels, a stored photo may have. */
	public const MAX_EDGE = 2000;

	/** Longest photo description accepted. */
	public const MAX_ALT = 125;

	/** Larger than this many pixels is refused rather than risk running out of memory. */
	public const MAX_PIXELS = 40000000;

	/**
	 * Photos in display order — attachment ids, cover first.
	 *
	 * @return int[]
	 */
	public static function ordered( int $listing_id ): array {

		if ( $listing_id < 1 ) {
			return [];
		}

		$posts = get_posts(
			[
				'post_type'      => 'attachment',
				'post_parent'    => $listing_id,
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => Listing_Form::MAX_PHOTOS * 2,
				'orderby'        => [ 'menu_order' => 'ASC', 'ID' => 'ASC' ],
			]
		);

		/*
		 * Position 0 means "never arranged", not "first". Arranged photos
		 * (1, 2, 3…) lead in their order; any photo attached some other way —
		 * a wp-admin upload onto the listing — follows them in upload order,
		 * instead of jumping to the front and silently becoming the cover.
		 */
		usort(
			$posts,
			static fn( \WP_Post $x, \WP_Post $y ): int =>
				[ 0 === (int) $x->menu_order ? 1 : 0, (int) $x->menu_order, (int) $x->ID ]
				<=> [ 0 === (int) $y->menu_order ? 1 : 0, (int) $y->menu_order, (int) $y->ID ]
		);

		$ids = array_map( static fn( \WP_Post $p ): int => (int) $p->ID, $posts );

		/*
		 * Listings photographed before ordering existed have every photo at
		 * position 0, and a cover chosen the old way — which is not always
		 * the first upload. Until the landlord arranges them, that cover
		 * leads, so nothing changes on a page nobody has touched.
		 */
		$arranged = (bool) array_filter( $posts, static fn( \WP_Post $p ): bool => (int) $p->menu_order > 0 );
		$cover    = (int) get_post_thumbnail_id( $listing_id );

		if ( ! $arranged && $cover && in_array( $cover, $ids, true ) ) {
			$ids = array_values( array_merge( [ $cover ], array_diff( $ids, [ $cover ] ) ) );
		}

		return $ids;
	}

	/** Does this attachment belong to this listing? */
	public static function belongs( int $listing_id, int $attachment_id ): bool {
		$att = get_post( $attachment_id );
		return $att instanceof \WP_Post && 'attachment' === $att->post_type && (int) $att->post_parent === $listing_id;
	}

	/**
	 * Store a new order. Ids not belonging to the listing are ignored, and
	 * photos missing from the list keep their place after the listed ones.
	 *
	 * @param int[] $ids
	 */
	public static function save_order( int $listing_id, array $ids ): void {

		$current = self::ordered( $listing_id );
		$ids     = array_values( array_intersect( array_map( 'intval', $ids ), $current ) );
		$ids     = array_merge( $ids, array_values( array_diff( $current, $ids ) ) );

		foreach ( $ids as $position => $id ) {
			// +1 so an arranged set is distinguishable from the untouched
			// all-zero one (see ordered()).
			if ( (int) get_post_field( 'menu_order', $id ) !== $position + 1 ) {
				wp_update_post( [ 'ID' => $id, 'menu_order' => $position + 1 ] );
			}
		}

		self::sync_cover( $listing_id );
	}

	/**
	 * Move one photo a place earlier or later.
	 *
	 * @param string $direction 'up' (earlier) or 'down' (later).
	 */
	public static function move( int $listing_id, int $attachment_id, string $direction ): bool {

		$ids = self::ordered( $listing_id );
		$at  = array_search( $attachment_id, $ids, true );

		if ( false === $at ) {
			return false;
		}

		$to = 'up' === $direction ? $at - 1 : $at + 1;

		if ( $to < 0 || $to >= count( $ids ) ) {
			return false;
		}

		[ $ids[ $at ], $ids[ $to ] ] = [ $ids[ $to ], $ids[ $at ] ];

		self::save_order( $listing_id, $ids );

		return true;
	}

	/** Put one photo first, which makes it the cover. */
	public static function make_cover( int $listing_id, int $attachment_id ): bool {

		$ids = self::ordered( $listing_id );

		if ( ! in_array( $attachment_id, $ids, true ) ) {
			return false;
		}

		self::save_order( $listing_id, array_merge( [ $attachment_id ], array_diff( $ids, [ $attachment_id ] ) ) );

		return true;
	}

	/**
	 * The cover is the first photo; with no photos, there is no cover.
	 */
	public static function sync_cover( int $listing_id ): void {

		$first = self::ordered( $listing_id )[0] ?? 0;

		if ( $first ) {
			if ( (int) get_post_thumbnail_id( $listing_id ) !== $first ) {
				set_post_thumbnail( $listing_id, $first );
			}
			return;
		}

		delete_post_thumbnail( $listing_id );
	}

	/**
	 * Save photo descriptions, keyed by attachment id.
	 *
	 * Stored as WordPress's own alt text, so every image function that
	 * prints the photo — cards, gallery, anything added later — uses it.
	 *
	 * @param array<int|string,mixed> $posted
	 */
	public static function save_alts( int $listing_id, array $posted ): void {

		foreach ( $posted as $id => $text ) {

			$id = (int) $id;

			if ( ! self::belongs( $listing_id, $id ) ) {
				continue;
			}

			$text = mb_substr( trim( sanitize_text_field( wp_unslash( (string) $text ) ) ), 0, self::MAX_ALT );

			if ( '' === $text ) {
				delete_post_meta( $id, '_wp_attachment_image_alt' );
			} else {
				update_post_meta( $id, '_wp_attachment_image_alt', $text );
			}
		}
	}

	/** A photo's description, or ''. */
	public static function alt( int $attachment_id ): string {
		return trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Clean and resize an uploaded photo in place, before WordPress stores it.
	 *
	 * @param string $path The uploaded file on disk (PHP's temporary file).
	 * @param string $name The file's original name, for messages.
	 * @return string '' when the file is ready, otherwise why it was refused.
	 */
	public static function prepare_upload( string $path, string $name ): string {

		$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		// iPhones save HEIC by default, and no browser or GD can show it.
		// Refusing it with the fix beats WordPress's "file type not allowed".
		if ( in_array( $ext, [ 'heic', 'heif' ], true ) ) {
			return sprintf(
				/* translators: %s: file name */
				__( '“%s” is an iPhone HEIC photo, which browsers cannot show. Save it as JPG and upload it again — on an iPhone: Settings → Camera → Formats → Most Compatible.', 'thirtydayhomes' ),
				$name
			);
		}

		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an unreadable file is an answer, not a warning.

		if ( ! is_array( $info ) || ! in_array( (int) $info[2], [ IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ], true ) ) {
			return sprintf(
				/* translators: %s: file name */
				__( '“%s” is not a JPG, PNG or WebP photo, so it was not added.', 'thirtydayhomes' ),
				$name
			);
		}

		[ $width, $height, $type ] = [ (int) $info[0], (int) $info[1], (int) $info[2] ];

		if ( $width * $height > self::MAX_PIXELS ) {
			return sprintf(
				/* translators: 1: file name, 2: megapixel limit */
				__( '“%1$s” is too large to process (over %2$s megapixels). Please upload a smaller version.', 'thirtydayhomes' ),
				$name,
				number_format_i18n( self::MAX_PIXELS / 1000000 )
			);
		}

		// Without GD the photo is stored as it came. Every mainstream host
		// has GD, and refusing all uploads on one that lacks it would break
		// the listing flow outright.
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return '';
		}

		wp_raise_memory_limit( 'image' );

		$image = match ( $type ) {
			IMAGETYPE_JPEG => @imagecreatefromjpeg( $path ), // phpcs:ignore WordPress.PHP.NoSilencedErrors
			IMAGETYPE_PNG  => @imagecreatefrompng( $path ),  // phpcs:ignore WordPress.PHP.NoSilencedErrors
			IMAGETYPE_WEBP => function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : false, // phpcs:ignore WordPress.PHP.NoSilencedErrors
		};

		if ( ! $image ) {
			return sprintf(
				/* translators: %s: file name */
				__( '“%s” could not be read as a photo. It may be damaged — please export it again and retry.', 'thirtydayhomes' ),
				$name
			);
		}

		if ( IMAGETYPE_JPEG === $type ) {
			$image = self::upright( $image, $path );
		}

		$width  = imagesx( $image );
		$height = imagesy( $image );
		$scale  = min( 1, self::MAX_EDGE / max( $width, $height ) );

		$out_w = max( 1, (int) round( $width * $scale ) );
		$out_h = max( 1, (int) round( $height * $scale ) );

		$canvas = imagecreatetruecolor( $out_w, $out_h );

		if ( IMAGETYPE_JPEG !== $type ) {
			imagealphablending( $canvas, false );
			imagesavealpha( $canvas, true );
		}

		imagecopyresampled( $canvas, $image, 0, 0, 0, 0, $out_w, $out_h, $width, $height );
		unset( $image );

		$saved = match ( $type ) {
			IMAGETYPE_JPEG => imagejpeg( $canvas, $path, 82 ),
			IMAGETYPE_PNG  => imagepng( $canvas, $path, 6 ),
			IMAGETYPE_WEBP => imagewebp( $canvas, $path, 82 ),
		};

		unset( $canvas );
		clearstatcache( true, $path );

		if ( ! $saved ) {
			return sprintf(
				/* translators: %s: file name */
				__( '“%s” could not be prepared on the server, so it was not added. Please try again.', 'thirtydayhomes' ),
				$name
			);
		}

		return '';
	}

	/**
	 * Apply a JPEG's orientation flag to its pixels.
	 *
	 * Re-encoding drops the flag, so without this a portrait phone photo
	 * would be stored lying on its side.
	 *
	 * @param \GdImage $image
	 * @return \GdImage
	 */
	private static function upright( $image, string $path ) {

		if ( ! function_exists( 'exif_read_data' ) ) {
			return $image;
		}

		$exif        = @exif_read_data( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$orientation = is_array( $exif ) ? (int) ( $exif['Orientation'] ?? 1 ) : 1;

		$flip   = in_array( $orientation, [ 2, 4, 5, 7 ], true );
		$rotate = [ 3 => 180, 4 => 180, 5 => -90, 6 => -90, 7 => 90, 8 => 90 ][ $orientation ] ?? 0;

		if ( $rotate ) {
			$turned = imagerotate( $image, $rotate, 0 );
			if ( $turned ) {
				unset( $image );
				$image = $turned;
			}
		}

		if ( $flip ) {
			imageflip( $image, IMG_FLIP_HORIZONTAL );
		}

		return $image;
	}
}
