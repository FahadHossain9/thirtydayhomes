<?php
/**
 * Roles and capabilities.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * The landlord role, and the capability map that keeps landlords out of
 * each other's records.
 *
 * Ownership is enforced through WordPress's own capability system rather
 * than by checking `get_current_user_id()` in templates. A template that
 * forgets the check is a data leak; a capability that is never granted
 * cannot be forgotten.
 */
final class Roles {

	public const LANDLORD = 'tdh_landlord';

	public function register(): void {
		add_filter( 'map_meta_cap', [ $this, 'map_listing_caps' ], 10, 4 );
	}

	/**
	 * Create the landlord role and grant admins every marketplace capability.
	 *
	 * Called on activation and on version upgrade, so adding a capability
	 * here is enough to roll it out.
	 */
	public function install(): void {

		$landlord_caps = [
			'read'                      => true,

			// Own listings only — the "others" caps are deliberately absent.
			'edit_tdh_listing'          => true,
			'edit_tdh_listings'         => true,
			'delete_tdh_listing'        => true,
			'delete_tdh_listings'       => true,
			'publish_tdh_listings'      => false, // An administrator approves. Always.
			'upload_files'              => true,

			// Inquiries are read-only to the landlord who received them.
			'read_tdh_inquiry'          => true,
			'read_private_tdh_inquiries' => false,
		];

		// remove_role + add_role so capability changes actually apply on upgrade.
		remove_role( self::LANDLORD );
		add_role( self::LANDLORD, __( 'Landlord', 'thirtydayhomes' ), $landlord_caps );

		$admin = get_role( 'administrator' );
		if ( ! $admin ) {
			return;
		}

		foreach ( self::administrator_caps() as $cap ) {
			$admin->add_cap( $cap );
		}
	}

	/**
	 * Every capability an administrator needs to run the marketplace.
	 *
	 * @return string[]
	 */
	public static function administrator_caps(): array {
		$caps = [];

		foreach ( [ 'tdh_listing' => 'tdh_listings', 'tdh_facility' => 'tdh_facilities', 'tdh_inquiry' => 'tdh_inquiries' ] as $single => $plural ) {
			$caps[] = "edit_{$single}";
			$caps[] = "read_{$single}";
			$caps[] = "delete_{$single}";
			$caps[] = "edit_{$plural}";
			$caps[] = "edit_others_{$plural}";
			$caps[] = "publish_{$plural}";
			$caps[] = "read_private_{$plural}";
			$caps[] = "delete_{$plural}";
			$caps[] = "delete_private_{$plural}";
			$caps[] = "delete_published_{$plural}";
			$caps[] = "delete_others_{$plural}";
			$caps[] = "edit_private_{$plural}";
			$caps[] = "edit_published_{$plural}";
		}

		// Marketplace-specific administrative actions.
		$caps[] = 'tdh_moderate_listings';
		$caps[] = 'tdh_manage_facilities';
		$caps[] = 'tdh_view_all_inquiries';

		return $caps;
	}

	/**
	 * Landlords may only touch their own listings and their own inquiries.
	 *
	 * @param string[] $caps    Primitive capabilities required.
	 * @param string   $cap     Meta capability being checked.
	 * @param int      $user_id User being checked.
	 * @param array    $args    [0] is the object ID.
	 *
	 * @return string[]
	 */
	public function map_listing_caps( array $caps, string $cap, int $user_id, array $args ): array {

		$owned = [
			'edit_tdh_listing',
			'delete_tdh_listing',
			'read_tdh_listing',
			'read_tdh_inquiry',
		];

		if ( ! in_array( $cap, $owned, true ) || empty( $args[0] ) ) {
			return $caps;
		}

		$post = get_post( (int) $args[0] );
		if ( ! $post ) {
			return [ 'do_not_allow' ];
		}

		// An inquiry belongs to whoever owns the listing it was sent about.
		if ( Post_Types::INQUIRY === $post->post_type ) {
			$listing_id = (int) get_post_meta( $post->ID, '_tdh_listing_id', true );
			$listing    = $listing_id ? get_post( $listing_id ) : null;
			$owner_id   = $listing ? (int) $listing->post_author : 0;
		} else {
			$owner_id = (int) $post->post_author;
		}

		if ( $owner_id && $owner_id === $user_id ) {
			return $caps;
		}

		// Not the owner: fall back to the "others" capability, which the
		// landlord role does not have and an administrator does.
		$type   = get_post_type_object( $post->post_type );
		$plural = $type->cap->edit_others_posts ?? 'do_not_allow';

		return [ $plural ];
	}
}
