<?php
/**
 * Sample facilities, listings and photographs.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Setup;

use TDH\Post_Types;
use TDH\Statuses;

defined( 'ABSPATH' ) || exit;

/**
 * Seeds the marketplace with demonstrable content.
 *
 * ⚠ THE COORDINATES BELOW ARE APPROXIMATE, FOR DEMONSTRATION ONLY.
 * The launch facility list with verified addresses is a client deliverable.
 * Once the geocoding module lands these are replaced by real geocoder
 * output. Nothing here should survive into a production dataset.
 */
final class Sample_Content {

	public function __construct( private Importer $importer ) {}

	public function run(): void {

		foreach ( $this->facilities() as $facility ) {
			$id = $this->upsert(
				Post_Types::FACILITY,
				$facility['slug'],
				$facility['title'],
				'publish',
				$facility['meta']
			);

			if ( $id ) {
				wp_set_object_terms( $id, 'Pittsburgh', Post_Types::TAX_CITY );
				$this->importer->log( sprintf( 'facility #%d %s', $id, $facility['title'] ) );
			}
		}

		foreach ( $this->listings() as $listing ) {
			$id = $this->upsert(
				Post_Types::LISTING,
				$listing['slug'],
				$listing['title'],
				$listing['status'],
				$listing['meta'],
				$listing['description']
			);

			if ( ! $id ) {
				continue;
			}

			wp_set_object_terms( $id, $listing['neighborhood'], Post_Types::TAX_NEIGHBORHOOD );
			wp_set_object_terms( $id, $listing['type'], Post_Types::TAX_TYPE );
			wp_set_object_terms( $id, 'Pittsburgh', Post_Types::TAX_CITY );

			// A listing may deliberately ship without a photograph — see the
			// pending entry below. Only warn when one was expected.
			if ( false !== ( $listing['photo'] ?? true ) ) {
				$this->attach_photo( $id, $listing['slug'] );
			}

			$this->importer->log( sprintf( 'listing #%d %s [%s]', $id, $listing['title'], $listing['status'] ) );
		}
	}

	/**
	 * Create or update, found by a meta key we control.
	 *
	 * NOT by slug. Two independent reasons, both of which produced silent
	 * duplicates before this was fixed:
	 *
	 *   1. WordPress excludes statuses registered exclude_from_search from
	 *      post_status => 'any'. All three of ours are.
	 *   2. A pending or draft post has NO post_name until first published,
	 *      so a slug lookup for a listing awaiting approval can never match.
	 *
	 * @param array<string,mixed> $meta
	 */
	private function upsert( string $post_type, string $slug, string $title, string $status, array $meta, string $content = '' ): int {

		$statuses = array_merge( array_keys( Statuses::all() ), [ 'future', 'private', 'trash' ] );

		$existing = get_posts(
			[
				'post_type'              => $post_type,
				'post_status'            => $statuses,
				'meta_key'               => '_tdh_seed_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'             => $slug,           // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'tdh_bypass_visibility'  => true,
			]
		);

		$args = [
			'post_type'    => $post_type,
			'post_name'    => $slug,
			'post_title'   => $title,
			'post_status'  => $status,
			'post_content' => $content,
		];

		if ( $existing ) {
			$args['ID'] = $existing[0]->ID;
			$id         = wp_update_post( $args, true );
		} else {
			$id = wp_insert_post( $args, true );
		}

		if ( is_wp_error( $id ) ) {
			$this->importer->warn( $title . ': ' . $id->get_error_message() );
			return 0;
		}

		update_post_meta( (int) $id, '_tdh_seed_key', $slug );

		foreach ( $meta as $key => $value ) {
			update_post_meta( (int) $id, $key, $value );
		}

		return (int) $id;
	}

	/**
	 * Attach a bundled photograph as the featured image.
	 *
	 * Images ship inside the plugin so a live site has them without any
	 * download — an importer that depends on reaching a stock photo host
	 * fails on exactly the locked-down hosting a client is most likely to
	 * be on.
	 */
	private function attach_photo( int $post_id, string $slug ): void {

		if ( has_post_thumbnail( $post_id ) ) {
			return;
		}

		$source = TDH_PLUGIN_DIR . 'assets/seed-images/' . $slug . '.webp';

		if ( ! is_readable( $source ) ) {
			$this->importer->warn( "no bundled photograph for {$slug}" );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$upload = wp_upload_bits( $slug . '.webp', null, (string) file_get_contents( $source ) );

		if ( ! empty( $upload['error'] ) ) {
			$this->importer->warn( $slug . ': ' . $upload['error'] );
			return;
		}

		$attachment_id = wp_insert_attachment(
			[
				'post_mime_type' => 'image/webp',
				'post_title'     => get_the_title( $post_id ),
				'post_status'    => 'inherit',
			],
			$upload['file'],
			$post_id
		);

		if ( is_wp_error( $attachment_id ) ) {
			$this->importer->warn( $slug . ': ' . $attachment_id->get_error_message() );
			return;
		}

		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $upload['file'] )
		);

		// Alt text at source. No seeded listing ships an unlabelled image —
		// which is the habit the landlord submission form has to establish.
		update_post_meta(
			$attachment_id,
			'_wp_attachment_image_alt',
			sprintf(
				/* translators: %s: listing title */
				__( '%s — furnished rental in Pittsburgh', 'thirtydayhomes' ),
				get_the_title( $post_id )
			)
		);

		set_post_thumbnail( $post_id, $attachment_id );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function facilities(): array {
		return [
			[ 'slug' => 'upmc-presbyterian',    'title' => 'UPMC Presbyterian',        'meta' => $this->facility_meta( '200 Lothrop St',  '15213', 40.4425, -79.9614, 1 ) ],
			[ 'slug' => 'upmc-shadyside',       'title' => 'UPMC Shadyside',           'meta' => $this->facility_meta( '5230 Centre Ave', '15232', 40.4557, -79.9370, 2 ) ],
			[ 'slug' => 'upmc-childrens',       'title' => 'UPMC Children’s Hospital', 'meta' => $this->facility_meta( '4401 Penn Ave',   '15224', 40.4674, -79.9510, 3 ) ],
			[ 'slug' => 'ahn-allegheny-general','title' => 'AHN Allegheny General',    'meta' => $this->facility_meta( '320 E North Ave', '15212', 40.4553, -80.0043, 4 ) ],
			[ 'slug' => 'upmc-mercy',           'title' => 'UPMC Mercy',               'meta' => $this->facility_meta( '1400 Locust St',  '15219', 40.4373, -79.9878, 5 ) ],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function facility_meta( string $address, string $zip, float $lat, float $lng, int $order ): array {
		return [
			'_tdh_facility_type'  => 'hospital',
			'_tdh_street_address' => $address,
			'_tdh_state'          => 'PA',
			'_tdh_zip'            => $zip,
			'_tdh_lat'            => $lat,
			'_tdh_lng'            => $lng,
			'_tdh_active'         => true,
			'_tdh_sort_order'     => $order,
		];
	}

	/**
	 * Five listings covering the states the moderation flow depends on.
	 *
	 * Three are published, matching the three-card homepage grid. One is
	 * pending and one is on billing hold, so both ways a listing can be
	 * hidden from the public are visible in wp-admin.
	 *
	 * `_tdh_badge` and `_tdh_rating` are editorial, not computed. The badge
	 * is a label the operator applies. The rating is DEMO DATA ONLY — there
	 * is no review system in V1, and the card omits the rating entirely when
	 * the field is empty, which is what a real listing will have.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function listings(): array {
		return [
			[
				'slug'         => 'sunlit-shadyside-retreat',
				'title'        => 'Sunlit Shadyside Retreat',
				'status'       => 'publish',
				'neighborhood' => 'Shadyside',
				'type'         => 'Apartment',
				'description'  => 'A serene, fully furnished home with generous natural light, a dedicated workspace, and everything needed for an effortless extended stay.',
				'meta'         => [
					'_tdh_street_address' => '5500 Walnut St',
					'_tdh_zip' => '15232', '_tdh_state' => 'PA',
					'_tdh_lat' => 40.4523, '_tdh_lng' => -79.9340, '_tdh_geocode_status' => 'manual',
					'_tdh_price_monthly' => 2450, '_tdh_deposit' => 800,
					'_tdh_application_fee' => 45, '_tdh_pet_fee' => 150,
					'_tdh_beds' => 2, '_tdh_baths' => 1, '_tdh_sqft' => 1050, '_tdh_rooms' => 5,
					'_tdh_furnished' => true, '_tdh_backyard' => 'Fenced backyard',
					'_tdh_parking' => 'Reserved off-street parking',
					'_tdh_min_stay_days' => 30, '_tdh_available_from' => '2026-09-01',
					'_tdh_pet_policy' => 'yes',
					'_tdh_utilities' => 'Water, gas, electric, high-speed internet',
					'_tdh_contact_method' => 'both',
					'_tdh_badge' => 'Guest favorite',
					'_tdh_rating' => 4.9,
				],
			],
			[
				'slug'         => 'modern-butler-street-loft',
				'title'        => 'Modern Butler Street Loft',
				'status'       => 'publish',
				'neighborhood' => 'Lawrenceville',
				'type'         => 'Loft',
				'description'  => 'A thoughtful city loft steps from neighborhood dining, designed for traveling professionals who value comfort and convenience.',
				'meta'         => [
					'_tdh_street_address' => '3600 Butler St',
					'_tdh_zip' => '15201', '_tdh_state' => 'PA',
					'_tdh_lat' => 40.4691, '_tdh_lng' => -79.9605, '_tdh_geocode_status' => 'manual',
					'_tdh_price_monthly' => 1895, '_tdh_deposit' => 700,
					'_tdh_application_fee' => 40, '_tdh_pet_fee' => 125,
					'_tdh_beds' => 1, '_tdh_baths' => 1, '_tdh_sqft' => 720, '_tdh_rooms' => 3,
					'_tdh_furnished' => true, '_tdh_parking' => 'Street parking',
					'_tdh_min_stay_days' => 91, '_tdh_available_from' => '2026-08-25',
					'_tdh_pet_policy' => 'considered',
					'_tdh_utilities' => 'All utilities included',
					'_tdh_contact_method' => 'email',
					'_tdh_badge' => 'New',
					'_tdh_rating' => 4.9,
				],
			],
			[
				'slug'         => 'the-oakland-townhouse',
				'title'        => 'The Oakland Townhouse',
				'status'       => 'publish',
				'neighborhood' => 'Oakland',
				'type'         => 'Townhouse',
				'description'  => 'A spacious furnished townhouse made for teams and families, with quiet bedrooms and a short commute to the medical district.',
				'meta'         => [
					'_tdh_street_address' => '250 Meyran Ave',
					'_tdh_zip' => '15213', '_tdh_state' => 'PA',
					'_tdh_lat' => 40.4395, '_tdh_lng' => -79.9560, '_tdh_geocode_status' => 'manual',
					'_tdh_price_monthly' => 3200, '_tdh_deposit' => 1200,
					'_tdh_application_fee' => 50, '_tdh_pet_fee' => 0,
					'_tdh_beds' => 3, '_tdh_baths' => 2, '_tdh_sqft' => 1560, '_tdh_rooms' => 7,
					'_tdh_furnished' => true, '_tdh_parking' => 'Driveway, two vehicles',
					'_tdh_min_stay_days' => 60, '_tdh_available_from' => '2026-09-15',
					'_tdh_pet_policy' => 'no',
					'_tdh_utilities' => 'Water and trash included',
					'_tdh_contact_method' => 'both',
					'_tdh_badge' => 'Top location',
					'_tdh_rating' => 4.9,
				],
			],
			[
				'slug'         => 'south-side-studio',
				'title'        => 'South Side Studio',
				'status'       => Statuses::BILLING_HOLD,
				'neighborhood' => 'South Side',
				'type'         => 'Studio',
				'description'  => 'Compact and comfortable furnished studio with quick access to downtown.',
				'meta'         => [
					'_tdh_street_address' => '1200 E Carson St',
					'_tdh_zip' => '15203', '_tdh_state' => 'PA',
					'_tdh_lat' => 40.4287, '_tdh_lng' => -79.9760, '_tdh_geocode_status' => 'manual',
					'_tdh_price_monthly' => 1550, '_tdh_deposit' => 600,
					'_tdh_application_fee' => 35, '_tdh_pet_fee' => 0,
					'_tdh_beds' => 1, '_tdh_baths' => 1, '_tdh_sqft' => 480, '_tdh_rooms' => 2,
					'_tdh_furnished' => true, '_tdh_parking' => 'Permit street parking',
					'_tdh_min_stay_days' => 30, '_tdh_available_from' => '2026-08-20',
					'_tdh_pet_policy' => 'no',
					'_tdh_utilities' => 'Electric included',
					'_tdh_contact_method' => 'email',
				],
			],
			[
				// The homepage grid shows three, so all three of those are
				// published. This fifth listing is what keeps the approval
				// queue non-empty for a demo — without a pending listing,
				// Milestone 1's moderation screen has nothing to show.
				'slug'         => 'squirrel-hill-garden-flat',
				'title'        => 'Squirrel Hill Garden Flat',
				'status'       => 'pending',
				// No photograph on purpose. It is an owner submission that
				// has not been completed, which is a real thing a moderator
				// has to handle, and it is the only content in the demo that
				// exercises the card's "Photo coming soon" placeholder.
				'photo'        => false,
				'neighborhood' => 'Squirrel Hill',
				'type'         => 'Apartment',
				'description'  => 'A quiet garden-level flat with a private entrance, a short drive from the medical district and walkable to Murray Avenue.',
				'meta'         => [
					'_tdh_street_address' => '5800 Forbes Ave',
					'_tdh_zip' => '15217', '_tdh_state' => 'PA',
					'_tdh_lat' => 40.4380, '_tdh_lng' => -79.9230, '_tdh_geocode_status' => 'manual',
					'_tdh_price_monthly' => 2100, '_tdh_deposit' => 750,
					'_tdh_application_fee' => 45, '_tdh_pet_fee' => 100,
					'_tdh_beds' => 2, '_tdh_baths' => 1, '_tdh_sqft' => 890, '_tdh_rooms' => 4,
					'_tdh_furnished' => true, '_tdh_backyard' => 'Shared garden',
					'_tdh_parking' => 'Off-street parking, one vehicle',
					'_tdh_min_stay_days' => 30, '_tdh_available_from' => '2026-10-01',
					'_tdh_pet_policy' => 'considered',
					'_tdh_utilities' => 'Water and trash included',
					'_tdh_contact_method' => 'both',
				],
			],
		];
	}
}
