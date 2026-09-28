<?php
/**
 * Meta field registration — the listing data dictionary.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Every marketplace field, declared in one place.
 *
 * This schema is the single source of truth. It drives meta registration,
 * REST exposure, sanitisation AND the admin editing UI — so adding a field
 * here is the only step needed to make it real. Nothing downstream keeps
 * its own copy of the field list.
 *
 * ADDRESS PRIVACY — read before adding a field.
 *
 * The street address must never reach the public. This is a safety issue:
 * a furnished rental that sits empty, with its address published, is an
 * easy target. On WordPress the DEFAULT behaviour leaks it, so three
 * deliberate steps are required and the third is a release blocker:
 *
 *   1. Register the field with 'show_in_rest' => false, so it never
 *      appears at /wp-json/wp/v2/tdh_listing.
 *   2. Never place it on the single-listing template, not even hidden —
 *      Elementor renders hidden elements into the DOM.
 *   3. An explicit REST-endpoint and page-source check in QA.
 *
 * Any field marked 'private' => true is covered by step 1 and is
 * additionally stripped from REST by Visibility::strip_private_meta().
 */
final class Fields {

	public function register(): void {
		add_action( 'init', [ $this, 'register_meta' ], 8 );
	}

	/**
	 * Field groups, in the order they should appear in the editor.
	 *
	 * @return array<string,string>
	 */
	public static function listing_groups(): array {
		return [
			'location'   => __( 'Location', 'thirtydayhomes' ),
			'pricing'    => __( 'Pricing & fees', 'thirtydayhomes' ),
			'property'   => __( 'Property details', 'thirtydayhomes' ),
			'terms'      => __( 'Stay terms', 'thirtydayhomes' ),
			'contact'    => __( 'Contact', 'thirtydayhomes' ),
			'moderation' => __( 'Moderation', 'thirtydayhomes' ),
		];
	}

	/**
	 * The listing schema.
	 *
	 * type     — storage and sanitisation
	 * private  — true keeps it out of REST and out of public output
	 * control  — how the admin editor renders it
	 * group    — which panel it belongs to
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function listing_schema(): array {
		return [
			// --- Location -------------------------------------------------
			'_tdh_street_address' => [
				'type' => 'string', 'private' => true, 'group' => 'location',
				'label' => __( 'Street address', 'thirtydayhomes' ),
				'control' => 'text',
				'help' => __( 'Never shown publicly. Used for geocoding, and released to a renter only after contact.', 'thirtydayhomes' ),
			],
			'_tdh_zip' => [
				'type' => 'string', 'private' => false, 'group' => 'location',
				'label' => __( 'ZIP code', 'thirtydayhomes' ), 'control' => 'text',
			],
			'_tdh_state' => [
				'type' => 'string', 'private' => false, 'group' => 'location',
				'label' => __( 'State', 'thirtydayhomes' ), 'control' => 'text', 'default' => 'PA',
			],
			'_tdh_lat' => [
				'type' => 'number', 'private' => true, 'group' => 'location',
				'label' => __( 'Latitude', 'thirtydayhomes' ), 'control' => 'number', 'step' => 'any',
				'help' => __( 'Filled automatically on save. Override only if geocoding failed.', 'thirtydayhomes' ),
			],
			'_tdh_lng' => [
				'type' => 'number', 'private' => true, 'group' => 'location',
				'label' => __( 'Longitude', 'thirtydayhomes' ), 'control' => 'number', 'step' => 'any',
			],
			'_tdh_geocode_status' => [
				'type' => 'string', 'private' => true, 'group' => 'location',
				'label' => __( 'Geocode status', 'thirtydayhomes' ), 'control' => 'select',
				'options' => [
					''        => __( 'Not yet geocoded', 'thirtydayhomes' ),
					'ok'      => __( 'OK', 'thirtydayhomes' ),
					'failed'  => __( 'Failed — needs manual coordinates', 'thirtydayhomes' ),
					'manual'  => __( 'Set manually', 'thirtydayhomes' ),
				],
			],

			// --- Pricing --------------------------------------------------
			'_tdh_price_monthly' => [
				'type' => 'number', 'private' => false, 'group' => 'pricing',
				'label' => __( 'Monthly rent', 'thirtydayhomes' ), 'control' => 'number', 'step' => '1',
			],
			'_tdh_deposit' => [
				'type' => 'number', 'private' => false, 'group' => 'pricing',
				'label' => __( 'Security deposit', 'thirtydayhomes' ), 'control' => 'number', 'step' => '1',
			],
			'_tdh_application_fee' => [
				'type' => 'number', 'private' => false, 'group' => 'pricing',
				'label' => __( 'Application fee', 'thirtydayhomes' ), 'control' => 'number', 'step' => '1',
			],
			'_tdh_pet_fee' => [
				'type' => 'number', 'private' => false, 'group' => 'pricing',
				'label' => __( 'Pet fee', 'thirtydayhomes' ), 'control' => 'number', 'step' => '1',
			],

			// --- Property -------------------------------------------------
			'_tdh_beds' => [
				'type' => 'number', 'private' => false, 'group' => 'property',
				'label' => __( 'Bedrooms', 'thirtydayhomes' ), 'control' => 'number', 'step' => '1',
			],
			'_tdh_baths' => [
				'type' => 'number', 'private' => false, 'group' => 'property',
				'label' => __( 'Bathrooms', 'thirtydayhomes' ), 'control' => 'number', 'step' => '0.5',
				'help' => __( 'Half bathrooms are supported — use 1.5, 2.5 and so on.', 'thirtydayhomes' ),
			],
			'_tdh_sqft' => [
				'type' => 'integer', 'private' => false, 'group' => 'property',
				'label' => __( 'Square feet', 'thirtydayhomes' ), 'control' => 'number', 'step' => '1',
			],
			// Editorial, not computed. The badge is a label staff apply by
			// hand; leaving it empty falls back to an automatic "New this
			// week" derived from the publish date.
			'_tdh_badge' => [
				'type' => 'string', 'private' => false, 'group' => 'property',
				'label' => __( 'Card badge', 'thirtydayhomes' ), 'control' => 'text',
				'help' => __( 'Shown on the photo, e.g. "Guest favorite". Leave empty for the automatic "New this week" badge.', 'thirtydayhomes' ),
			],
			// There is no review system in V1, so nothing writes this
			// automatically. The card hides the rating when it is empty,
			// which is what every real listing will be until reviews ship.
			'_tdh_rating' => [
				'type' => 'number', 'private' => false, 'group' => 'property',
				'label' => __( 'Rating', 'thirtydayhomes' ), 'control' => 'number', 'step' => '0.1',
				'help' => __( 'Optional, 0–5. Leave empty until there is a real review system — a rating no guest gave is a promise the site cannot keep.', 'thirtydayhomes' ),
			],
			'_tdh_rooms' => [
				'type' => 'integer', 'private' => false, 'group' => 'property',
				'label' => __( 'Total rooms', 'thirtydayhomes' ), 'control' => 'number', 'step' => '1',
			],
			'_tdh_furnished' => [
				'type' => 'boolean', 'private' => false, 'group' => 'property',
				'label' => __( 'Furnished', 'thirtydayhomes' ), 'control' => 'checkbox', 'default' => true,
			],
			'_tdh_backyard' => [
				'type' => 'string', 'private' => false, 'group' => 'property',
				'label' => __( 'Backyard', 'thirtydayhomes' ), 'control' => 'text',
			],
			'_tdh_parking' => [
				'type' => 'string', 'private' => false, 'group' => 'property',
				'label' => __( 'Parking', 'thirtydayhomes' ), 'control' => 'text',
			],

			// --- Terms ----------------------------------------------------
			'_tdh_min_stay_days' => [
				'type' => 'integer', 'private' => false, 'group' => 'terms',
				'label' => __( 'Minimum stay', 'thirtydayhomes' ), 'control' => 'select',
				'options' => [
					'30' => __( '30 days', 'thirtydayhomes' ),
					'60' => __( '60 days', 'thirtydayhomes' ),
					'90' => __( '90 days', 'thirtydayhomes' ),
					'91' => __( '13 weeks', 'thirtydayhomes' ),
				],
				'help' => __( '13 weeks is the standard travel-nurse contract. Offering it explicitly saves the renter working out whether "3 months" is close enough.', 'thirtydayhomes' ),
			],
			'_tdh_available_from' => [
				'type' => 'string', 'private' => false, 'group' => 'terms',
				'label' => __( 'Available from', 'thirtydayhomes' ), 'control' => 'date',
			],
			'_tdh_lease_term' => [
				'type' => 'string', 'private' => false, 'group' => 'terms',
				'label' => __( 'Lease term', 'thirtydayhomes' ), 'control' => 'text',
			],
			'_tdh_utilities' => [
				'type' => 'string', 'private' => false, 'group' => 'terms',
				'label' => __( 'Utilities included', 'thirtydayhomes' ), 'control' => 'textarea',
			],
			'_tdh_pet_policy' => [
				'type' => 'string', 'private' => false, 'group' => 'terms',
				'label' => __( 'Pet policy', 'thirtydayhomes' ), 'control' => 'select',
				'options' => [
					'no'         => __( 'Not allowed', 'thirtydayhomes' ),
					'considered' => __( 'Considered', 'thirtydayhomes' ),
					'yes'        => __( 'Allowed', 'thirtydayhomes' ),
				],
				'help' => __( 'Three states, not a yes/no toggle — spec §D asks for "considered" as a distinct answer.', 'thirtydayhomes' ),
			],

			// --- Contact (private: released to renters only after inquiry) --
			'_tdh_contact_name' => [
				'type' => 'string', 'private' => true, 'group' => 'contact',
				'label' => __( 'Contact name', 'thirtydayhomes' ), 'control' => 'text',
			],
			'_tdh_contact_email' => [
				'type' => 'string', 'private' => true, 'group' => 'contact',
				'label' => __( 'Contact email', 'thirtydayhomes' ), 'control' => 'email',
			],
			'_tdh_contact_phone' => [
				'type' => 'string', 'private' => true, 'group' => 'contact',
				'label' => __( 'SMS-capable phone', 'thirtydayhomes' ), 'control' => 'tel',
				'help' => __( 'Stored in E.164 format. Must be verified before the site will text it.', 'thirtydayhomes' ),
			],
			'_tdh_contact_method' => [
				'type' => 'string', 'private' => true, 'group' => 'contact',
				'label' => __( 'Preferred contact method', 'thirtydayhomes' ), 'control' => 'select',
				'options' => [
					'email' => __( 'Email', 'thirtydayhomes' ),
					'sms'   => __( 'Text message', 'thirtydayhomes' ),
					'both'  => __( 'Either', 'thirtydayhomes' ),
				],
			],

			// --- Compliance and moderation --------------------------------
			'_tdh_fair_housing_ack_at' => [
				'type' => 'string', 'private' => true, 'group' => 'moderation',
				'label' => __( 'Fair Housing acknowledged', 'thirtydayhomes' ), 'control' => 'readonly',
			],
			'_tdh_rejection_reason' => [
				'type' => 'string', 'private' => true, 'group' => 'moderation',
				'label' => __( 'Rejection reason', 'thirtydayhomes' ), 'control' => 'textarea',
				'help' => __( 'Shown to the landlord so they can revise and resubmit.', 'thirtydayhomes' ),
			],
			'_tdh_submitted_at' => [
				'type' => 'string', 'private' => true, 'group' => 'moderation',
				'label' => __( 'Submitted at', 'thirtydayhomes' ), 'control' => 'readonly',
			],
			'_tdh_approved_at' => [
				'type' => 'string', 'private' => true, 'group' => 'moderation',
				'label' => __( 'Approved at', 'thirtydayhomes' ), 'control' => 'readonly',
			],
			'_tdh_approved_by' => [
				'type' => 'integer', 'private' => true, 'group' => 'moderation',
				'label' => __( 'Approved by', 'thirtydayhomes' ), 'control' => 'readonly',
			],
		];
	}

	/**
	 * The facility schema.
	 *
	 * City and state are present from day one, so Pittsburgh is seed data
	 * rather than an assumption baked into templates. That is the whole of
	 * the spec's "additional facilities and cities later without rebuilding
	 * the website".
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function facility_schema(): array {
		return [
			'_tdh_facility_type' => [
				'type' => 'string', 'private' => false, 'group' => 'facility',
				'label' => __( 'Facility type', 'thirtydayhomes' ), 'control' => 'select',
				'options' => [
					'hospital' => __( 'Hospital campus', 'thirtydayhomes' ),
					'clinic'   => __( 'Clinic', 'thirtydayhomes' ),
					'rehab'    => __( 'Rehabilitation', 'thirtydayhomes' ),
					'other'    => __( 'Other', 'thirtydayhomes' ),
				],
				'default' => 'hospital',
			],
			'_tdh_street_address' => [
				'type' => 'string', 'private' => false, 'group' => 'facility',
				'label' => __( 'Street address', 'thirtydayhomes' ), 'control' => 'text',
			],
			'_tdh_state' => [
				'type' => 'string', 'private' => false, 'group' => 'facility',
				'label' => __( 'State', 'thirtydayhomes' ), 'control' => 'text', 'default' => 'PA',
			],
			'_tdh_zip' => [
				'type' => 'string', 'private' => false, 'group' => 'facility',
				'label' => __( 'ZIP code', 'thirtydayhomes' ), 'control' => 'text',
			],
			'_tdh_lat' => [
				'type' => 'number', 'private' => false, 'group' => 'facility',
				'label' => __( 'Latitude', 'thirtydayhomes' ), 'control' => 'number', 'step' => 'any',
			],
			'_tdh_lng' => [
				'type' => 'number', 'private' => false, 'group' => 'facility',
				'label' => __( 'Longitude', 'thirtydayhomes' ), 'control' => 'number', 'step' => 'any',
			],
			'_tdh_active' => [
				'type' => 'boolean', 'private' => false, 'group' => 'facility',
				'label' => __( 'Active in renter search', 'thirtydayhomes' ), 'control' => 'checkbox', 'default' => true,
				'help' => __( 'Deactivating hides a facility from search without losing its record or its computed distances.', 'thirtydayhomes' ),
			],
			'_tdh_sort_order' => [
				'type' => 'integer', 'private' => false, 'group' => 'facility',
				'label' => __( 'Sort order', 'thirtydayhomes' ), 'control' => 'number', 'step' => '1',
			],
		];
	}

	/**
	 * The inquiry schema. Every field is private — this is personal data.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function inquiry_schema(): array {
		return [
			'_tdh_listing_id'    => [ 'type' => 'integer', 'private' => true, 'group' => 'inquiry', 'label' => __( 'Listing', 'thirtydayhomes' ), 'control' => 'readonly' ],
			'_tdh_renter_name'   => [ 'type' => 'string',  'private' => true, 'group' => 'inquiry', 'label' => __( 'Renter name', 'thirtydayhomes' ), 'control' => 'readonly' ],
			'_tdh_renter_email'  => [ 'type' => 'string',  'private' => true, 'group' => 'inquiry', 'label' => __( 'Renter email', 'thirtydayhomes' ), 'control' => 'readonly' ],
			'_tdh_renter_phone'  => [ 'type' => 'string',  'private' => true, 'group' => 'inquiry', 'label' => __( 'Renter phone', 'thirtydayhomes' ), 'control' => 'readonly' ],
			'_tdh_move_in'       => [ 'type' => 'string',  'private' => true, 'group' => 'inquiry', 'label' => __( 'Desired move-in', 'thirtydayhomes' ), 'control' => 'readonly' ],
			'_tdh_stay_length'   => [ 'type' => 'string',  'private' => true, 'group' => 'inquiry', 'label' => __( 'Expected stay', 'thirtydayhomes' ), 'control' => 'readonly' ],
			'_tdh_message'       => [ 'type' => 'string',  'private' => true, 'group' => 'inquiry', 'label' => __( 'Message', 'thirtydayhomes' ), 'control' => 'readonly' ],
			'_tdh_rules_version' => [ 'type' => 'string',  'private' => true, 'group' => 'inquiry', 'label' => __( 'Accepted rules version', 'thirtydayhomes' ), 'control' => 'readonly' ],

			/*
			 * Written by the contact form. Listed here because this schema is
			 * what the inquiry meta box renders — a key the form writes and
			 * this list omits is a key nobody in wp-admin can ever read, which
			 * is precisely how a stored message became unrecoverable once.
			 */
			'_tdh_inquiry_kind'  => [ 'type' => 'string',  'private' => true, 'group' => 'inquiry', 'label' => __( 'Kind', 'thirtydayhomes' ), 'control' => 'readonly', 'help' => __( 'Blank for an enquiry about a listing; "contact" for a message sent from the Contact page.', 'thirtydayhomes' ) ],
			'_tdh_topic'         => [ 'type' => 'string',  'private' => true, 'group' => 'inquiry', 'label' => __( 'Topic', 'thirtydayhomes' ), 'control' => 'readonly' ],
			'_tdh_notified'      => [
				'type' => 'string', 'private' => true, 'group' => 'inquiry',
				'label' => __( 'Notification', 'thirtydayhomes' ), 'control' => 'readonly',
				'help'  => __( '"failed" means this message reached nobody by email. It is only here, so reply from this screen.', 'thirtydayhomes' ),
			],
			'_tdh_read'          => [ 'type' => 'boolean', 'private' => true, 'group' => 'inquiry', 'label' => __( 'Read by landlord', 'thirtydayhomes' ), 'control' => 'checkbox' ],
			'_tdh_status'        => [
				'type' => 'string', 'private' => true, 'group' => 'inquiry',
				'label' => __( 'Status', 'thirtydayhomes' ), 'control' => 'select',
				'options' => [
					'new'      => __( 'New', 'thirtydayhomes' ),
					'opened'   => __( 'Opened', 'thirtydayhomes' ),
					'archived' => __( 'Archived', 'thirtydayhomes' ),
				],
				'default' => 'new',
			],
		];
	}

	/**
	 * Schema for a given post type.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function schema_for( string $post_type ): array {
		return match ( $post_type ) {
			Post_Types::LISTING  => self::listing_schema(),
			Post_Types::FACILITY => self::facility_schema(),
			Post_Types::INQUIRY  => self::inquiry_schema(),
			default              => [],
		};
	}

	public function register_meta(): void {
		foreach ( [ Post_Types::LISTING, Post_Types::FACILITY, Post_Types::INQUIRY ] as $post_type ) {
			foreach ( self::schema_for( $post_type ) as $key => $def ) {
				register_post_meta(
					$post_type,
					$key,
					[
						'type'              => $def['type'],
						'single'            => true,
						'show_in_rest'      => empty( $def['private'] ),
						'sanitize_callback' => self::sanitizer( $def['type'] ),
						'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
							return current_user_can( 'edit_post', $post_id );
						},
					]
				);
			}
		}
	}

	/**
	 * Return the right sanitiser for a declared field type.
	 */
	public static function sanitizer( string $type ): callable {
		return match ( $type ) {
			'number'  => static fn( $v ) => is_numeric( $v ) ? (float) $v : 0.0,
			'integer' => static fn( $v ) => (int) $v,
			'boolean' => static fn( $v ) => (bool) $v,
			default   => static fn( $v ) => sanitize_textarea_field( (string) $v ),
		};
	}

	/**
	 * Every meta key marked private, across all post types.
	 *
	 * @return string[]
	 */
	public static function private_keys(): array {
		$keys = [];

		foreach ( [ self::listing_schema(), self::facility_schema(), self::inquiry_schema() ] as $schema ) {
			foreach ( $schema as $key => $def ) {
				if ( ! empty( $def['private'] ) ) {
					$keys[ $key ] = true;
				}
			}
		}

		return array_keys( $keys );
	}
}
