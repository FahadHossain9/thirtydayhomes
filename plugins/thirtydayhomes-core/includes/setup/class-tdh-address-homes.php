<?php
/**
 * Homes from the client's own address list, with sample details.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Setup;

use TDH\Geocoder;
use TDH\Post_Types;
use TDH\Statuses;

defined( 'ABSPATH' ) || exit;

/**
 * Creates one live home per address in a private file on the server.
 *
 * ─── WHY THE ADDRESSES ARE NOT IN THE CODE ─────────────────────────────
 *
 * They are the client's real street addresses. Nothing private goes into
 * git, so the list lives in one file uploaded straight to the server, next
 * to wp-config.php, and this step reads it. A .php file is used because a
 * browser that requests it gets an empty page, never the list.
 *
 * The file returns:
 *
 *   [ 'owner' => 'landlord@example.com',
 *     'homes' => [ [ '1820 Example St', 'Pittsburgh', 'PA', '15212' ], … ] ]
 *
 * Rent, rooms and photos are samples so the map and the hospital distances
 * can be seen working; every home says so, and the owner replaces them.
 * Re-running updates the same homes (matched by a key built from the
 * address) and never duplicates one.
 */
final class Address_Homes {

	/** The private file, next to wp-config.php. */
	public const FILE = 'tdh-address-list.php';

	/** Marks a home whose details are samples. */
	public const META_SAMPLE = '_tdh_sample_details';

	/** Fourteen sample photos, one per home before any repeats. */
	private const PHOTO_COUNT = 14;
	private const TYPES  = [ 'Single Family House', 'Townhouse', 'Apartment' ];
	private const RENTS  = [ 1650, 1895, 2100, 1450, 2300, 1750, 1995 ];

	public function __construct( private Importer $importer ) {}

	public static function path(): string {
		return ABSPATH . self::FILE;
	}

	public function run(): void {

		$file = self::path();

		if ( ! is_readable( $file ) ) {
			$this->importer->warn( sprintf( 'No address list found. Upload %s next to wp-config.php, then run this step again.', self::FILE ) );
			return;
		}

		$list  = include $file;
		$owner = is_array( $list ) ? get_user_by( 'email', (string) ( $list['owner'] ?? '' ) ) : false;
		$homes = is_array( $list ) ? (array) ( $list['homes'] ?? [] ) : [];

		if ( ! $owner ) {
			$this->importer->warn( 'The address list names no owner with an account on this site, so nothing was added.' );
			return;
		}

		$made = [];

		foreach ( array_values( $homes ) as $i => $row ) {
			[ $street, $city, $state, $zip ] = array_map( 'trim', array_map( 'strval', array_pad( (array) $row, 4, '' ) ) );

			if ( '' === $street || '' === $city || ! preg_match( '/^\d{5}$/', $zip ) ) {
				$this->importer->warn( sprintf( 'Row %d skipped: it needs a street, a city and a 5-digit ZIP.', $i + 1 ) );
				continue;
			}

			$id = $this->upsert( $i, $street, $city, '' !== $state ? $state : 'PA', $zip, (int) $owner->ID );

			if ( $id ) {
				$made[] = $id;
			}
		}

		// Look every address up now rather than at shutdown, so the log can
		// say which ones were found on the map.
		Geocoder::flush();

		foreach ( $made as $id ) {
			$found = null !== Geocoder::coordinates( $id );
			$this->importer->log( sprintf( 'home #%d %s — %s', $id, get_the_title( $id ), $found ? 'on the map' : 'not found on the map yet (Listings → Needs location)' ) );
		}

		$this->importer->log( sprintf( '%d of %d %s added or updated for %s.', count( $made ), count( $homes ), 1 === count( $homes ) ? 'home' : 'homes', $owner->user_email ) );
	}

	private function upsert( int $i, string $street, string $city, string $state, string $zip, int $owner ): int {

		$key   = 'address-home-' . md5( strtolower( $street . '|' . $zip ) );
		$beds  = 1 + ( $i % 3 );
		$baths = $beds > 2 ? 2 : 1;
		$name  = trim( (string) preg_replace( '/^\d+[A-Za-z]?\s+/', '', $street ) );
		$title = sprintf( '%d-bedroom home on %s, %s', $beds, $name, $city );

		$found = get_posts(
			[
				'post_type'             => Post_Types::LISTING,
				'post_status'           => array_merge( array_keys( Statuses::all() ), [ 'trash' ] ),
				'meta_key'              => '_tdh_seed_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'            => $key,            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page'        => 1,
				'fields'                => 'ids',
				'tdh_bypass_visibility' => true,
			]
		);

		$args = [
			'post_type'    => Post_Types::LISTING,
			'post_status'  => Statuses::public_status(),
			'post_author'  => $owner,
			'post_title'   => $title,
			'post_content' => sprintf(
				"A furnished %d-bedroom home in %s, ready for stays of 30 days or longer.\n\nSample details: the rent, rooms and photos shown here are examples. The owner will replace them with the real ones.",
				$beds,
				$city
			),
		];

		// Already added: left exactly as it is, so a second run never
		// duplicates a home or undoes what the owner has since changed.
		if ( $found ) {
			$this->importer->log( sprintf( 'home #%d %s is already on the site, left as it is', (int) $found[0], get_the_title( (int) $found[0] ) ) );
			return 0;
		}

		$id = wp_insert_post( $args, true );

		if ( is_wp_error( $id ) ) {
			$this->importer->warn( $street . ': ' . $id->get_error_message() );
			return 0;
		}

		$id   = (int) $id;
		$rent = self::RENTS[ $i % count( self::RENTS ) ];
		$meta = [
			'_tdh_seed_key'        => $key,
			self::META_SAMPLE      => 1,
			'_tdh_street_address'  => $street,
			'_tdh_state'           => $state,
			'_tdh_zip'             => $zip,
			'_tdh_price_monthly'   => $rent,
			'_tdh_deposit'         => (int) round( $rent / 2, -1 ),
			'_tdh_application_fee' => 45,
			'_tdh_pet_fee'         => 0,
			'_tdh_beds'            => $beds,
			'_tdh_baths'           => $baths,
			'_tdh_sqft'            => 550 + 350 * $beds,
			'_tdh_rooms'           => $beds + 2,
			'_tdh_furnished'       => true,
			'_tdh_parking'         => 'Street parking',
			'_tdh_min_stay_days'   => 30,
			'_tdh_available_from'  => gmdate( 'Y-m-d' ),
			'_tdh_pet_policy'      => 'considered',
			'_tdh_utilities'       => 'Water, electric and internet included',
			'_tdh_contact_method'  => 'both',
		];

		foreach ( $meta as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}

		wp_set_object_terms( $id, $city, Post_Types::TAX_CITY );
		wp_set_object_terms( $id, self::TYPES[ $i % count( self::TYPES ) ], Post_Types::TAX_TYPE );

		$this->photo( $id, sprintf( 'address-homes/sample-%02d', 1 + $i % self::PHOTO_COUNT ) );

		return $id;
	}

	/** One bundled photo per home, so nothing depends on a download. */
	private function photo( int $id, string $file ): void {

		if ( has_post_thumbnail( $id ) ) {
			return;
		}

		$source = TDH_PLUGIN_DIR . 'assets/seed-images/' . $file . '.webp';

		if ( ! is_readable( $source ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$upload = wp_upload_bits( 'sample-home-' . $id . '.webp', null, (string) file_get_contents( $source ) );

		if ( ! empty( $upload['error'] ) ) {
			$this->importer->warn( 'photo for #' . $id . ': ' . $upload['error'] );
			return;
		}

		$attachment = wp_insert_attachment( [ 'post_mime_type' => 'image/webp', 'post_title' => get_the_title( $id ), 'post_status' => 'inherit' ], $upload['file'], $id );

		if ( is_wp_error( $attachment ) ) {
			return;
		}

		wp_update_attachment_metadata( $attachment, wp_generate_attachment_metadata( $attachment, $upload['file'] ) );
		update_post_meta( $attachment, '_wp_attachment_image_alt', __( 'Sample photo — the owner will add photos of this home', 'thirtydayhomes' ) );
		set_post_thumbnail( $id, $attachment );
	}
}
