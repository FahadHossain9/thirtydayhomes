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

			// Own listings only — the "others" caps are deliberately absent,
			// and that absence is what enforces ownership. WordPress maps
			// edit_tdh_listing on someone else's post to
			// edit_others_tdh_listings, which a landlord never holds.
			'edit_tdh_listing'          => true,
			'edit_tdh_listings'         => true,
			'delete_tdh_listing'        => true,
			'delete_tdh_listings'       => true,

			// Required to edit a listing that is already live. Without
			// these a landlord can edit a draft and then never touch it
			// again the moment an administrator approves it. Ownership is
			// still enforced: a non-owner needs the "others" cap as well.
			'edit_published_tdh_listings'   => true,
			'delete_published_tdh_listings' => true,

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
	 * An inquiry belongs to whoever owns the listing it was sent about.
	 *
	 * ONLY inquiries. Listings are left to WordPress, which compares
	 * post_author itself and already handles the published, private and
	 * trashed variants correctly — duplicating that here would mean
	 * maintaining a copy of core's logic that silently drifts from it.
	 *
	 * An inquiry is different: its post_author is not the landlord, so the
	 * ownership relationship runs through _tdh_listing_id and core cannot
	 * see it. Without this, read_post on a published inquiry maps to plain
	 * `read`, which every signed-in user holds — any landlord could read
	 * any other landlord's enquiries.
	 *
	 * NOTE ON THE CAP NAME. WordPress rewrites a custom post type's meta
	 * capability to its generic form *before* applying this filter:
	 * `read_tdh_inquiry` arrives here as `read_post`. Matching the
	 * marketplace-specific name means this filter never runs at all, which
	 * is exactly the bug this comment exists to prevent recurring.
	 *
	 * @param string[] $caps    Primitive capabilities required.
	 * @param string   $cap     Meta capability being checked, already mapped.
	 * @param int      $user_id User being checked.
	 * @param array    $args    [0] is the object ID.
	 *
	 * @return string[]
	 */
	public function map_listing_caps( array $caps, string $cap, int $user_id, array $args ): array {

		if ( ! in_array( $cap, [ 'read_post', 'edit_post', 'delete_post' ], true ) || empty( $args[0] ) ) {
			return $caps;
		}

		$post = get_post( (int) $args[0] );

		if ( ! $post || Post_Types::INQUIRY !== $post->post_type ) {
			return $caps;
		}

		$listing_id = (int) get_post_meta( $post->ID, '_tdh_listing_id', true );
		$listing    = $listing_id ? get_post( $listing_id ) : null;
		$owner_id   = $listing ? (int) $listing->post_author : 0;

		if ( $owner_id && $owner_id === $user_id ) {
			return $caps;
		}

		// Not the recipient. Require the capability only an administrator
		// holds, rather than do_not_allow, so an administrator can still
		// read every inquiry for support and moderation.
		$type = get_post_type_object( Post_Types::INQUIRY );

		return [ $type->cap->read_private_posts ?? 'do_not_allow' ];
	}
}
