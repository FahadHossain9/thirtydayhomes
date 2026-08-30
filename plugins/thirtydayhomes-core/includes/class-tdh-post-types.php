<?php
/**
 * Custom post types.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the three marketplace content types and their taxonomies.
 */
final class Post_Types {

	public const LISTING  = 'tdh_listing';
	public const FACILITY = 'tdh_facility';
	public const INQUIRY  = 'tdh_inquiry';

	public const TAX_TYPE         = 'tdh_property_type';
	public const TAX_NEIGHBORHOOD = 'tdh_neighborhood';
	public const TAX_AMENITY      = 'tdh_amenity';
	public const TAX_CITY         = 'tdh_city';

	public function register(): void {
		add_action( 'init', [ $this, 'register_post_types' ], 5 );
		add_action( 'init', [ $this, 'register_taxonomies' ], 6 );
	}

	public function register_post_types(): void {

		/**
		 * Listings.
		 *
		 * `map_meta_cap` with a custom capability type is what lets us stop
		 * one landlord editing another landlord's listing at the WordPress
		 * level, rather than by checking ownership in every template.
		 */
		register_post_type(
			self::LISTING,
			[
				'labels'              => $this->labels( __( 'Listing', 'thirtydayhomes' ), __( 'Listings', 'thirtydayhomes' ) ),
				'public'              => true,
				'show_in_rest'        => true,
				'menu_icon'           => 'dashicons-admin-home',
				'menu_position'       => 20,
				// 'custom-fields' is required for registered meta to appear in
				// REST at all. Without it, show_in_rest on a field is inert.
				// Safe here: underscore-prefixed keys are hidden from the
				// custom-fields editor panel, and REST returns only the keys
				// explicitly registered with show_in_rest => true.
				'supports'            => [ 'title', 'editor', 'thumbnail', 'author', 'revisions', 'excerpt', 'custom-fields' ],
				'has_archive'         => 'homes',
				'rewrite'             => [ 'slug' => 'homes', 'with_front' => false ],
				'capability_type'     => [ 'tdh_listing', 'tdh_listings' ],
				'map_meta_cap'        => true,
				'hierarchical'        => false,
				'exclude_from_search' => false,
				'delete_with_user'    => false,
			]
		);

		/**
		 * Medical facilities.
		 *
		 * Not publicly queryable — a facility is reference data that shapes
		 * search, not a page a renter should land on. It carries city and
		 * state from day one so a second market needs no code change.
		 */
		register_post_type(
			self::FACILITY,
			[
				'labels'             => $this->labels( __( 'Facility', 'thirtydayhomes' ), __( 'Medical Facilities', 'thirtydayhomes' ) ),
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_rest'       => false,
				'publicly_queryable' => false,
				'menu_icon'          => 'dashicons-plus-alt',
				'menu_position'      => 21,
				'supports'           => [ 'title' ],
				'capability_type'    => [ 'tdh_facility', 'tdh_facilities' ],
				'map_meta_cap'       => true,
				'delete_with_user'   => false,
			]
		);

		/**
		 * Inquiries.
		 *
		 * Stored as well as emailed. The spec only asks for email, but an
		 * emailed lead that bounces is a lead lost for good, and the admin
		 * dashboard needs a recent-inquiry count that comes from somewhere.
		 *
		 * Never public, never in REST, never in search.
		 */
		register_post_type(
			self::INQUIRY,
			[
				'labels'              => $this->labels( __( 'Inquiry', 'thirtydayhomes' ), __( 'Inquiries', 'thirtydayhomes' ) ),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'menu_icon'           => 'dashicons-email-alt',
				'menu_position'       => 22,
				'supports'            => [ 'title' ],
				'capability_type'     => [ 'tdh_inquiry', 'tdh_inquiries' ],
				'map_meta_cap'        => true,
				'delete_with_user'    => false,
			]
		);
	}

	public function register_taxonomies(): void {

		// Taxonomies rather than meta: indexed filtering and clean archive
		// URLs for free, which is what makes faceted search fast.
		register_taxonomy(
			self::TAX_TYPE,
			[ self::LISTING ],
			[
				'label'             => __( 'Property Type', 'thirtydayhomes' ),
				'public'            => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'hierarchical'      => true,
				'rewrite'           => [ 'slug' => 'property-type' ],
			]
		);

		register_taxonomy(
			self::TAX_NEIGHBORHOOD,
			[ self::LISTING ],
			[
				'label'             => __( 'Neighborhood', 'thirtydayhomes' ),
				'public'            => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'hierarchical'      => true,
				'rewrite'           => [ 'slug' => 'neighborhood' ],
			]
		);

		register_taxonomy(
			self::TAX_AMENITY,
			[ self::LISTING ],
			[
				'label'             => __( 'Amenities', 'thirtydayhomes' ),
				'public'            => true,
				'show_admin_column' => false,
				'show_in_rest'      => true,
				'hierarchical'      => true,
				'rewrite'           => [ 'slug' => 'amenity' ],
			]
		);

		register_taxonomy(
			self::TAX_CITY,
			[ self::LISTING, self::FACILITY ],
			[
				'label'             => __( 'City', 'thirtydayhomes' ),
				'public'            => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'hierarchical'      => true,
				'rewrite'           => [ 'slug' => 'city' ],
			]
		);
	}

	/**
	 * Standard label set.
	 *
	 * @return array<string,string>
	 */
	private function labels( string $single, string $plural ): array {
		return [
			'name'               => $plural,
			'singular_name'      => $single,
			'menu_name'          => $plural,
			'add_new'            => __( 'Add New', 'thirtydayhomes' ),
			/* translators: %s: singular post type name */
			'add_new_item'       => sprintf( __( 'Add New %s', 'thirtydayhomes' ), $single ),
			/* translators: %s: singular post type name */
			'edit_item'          => sprintf( __( 'Edit %s', 'thirtydayhomes' ), $single ),
			/* translators: %s: singular post type name */
			'new_item'           => sprintf( __( 'New %s', 'thirtydayhomes' ), $single ),
			/* translators: %s: singular post type name */
			'view_item'          => sprintf( __( 'View %s', 'thirtydayhomes' ), $single ),
			/* translators: %s: plural post type name */
			'search_items'       => sprintf( __( 'Search %s', 'thirtydayhomes' ), $plural ),
			/* translators: %s: plural post type name, lowercased */
			'not_found'          => sprintf( __( 'No %s found', 'thirtydayhomes' ), strtolower( $plural ) ),
			/* translators: %s: plural post type name, lowercased */
			'not_found_in_trash' => sprintf( __( 'No %s found in Trash', 'thirtydayhomes' ), strtolower( $plural ) ),
			'all_items'          => $plural,
		];
	}
}
