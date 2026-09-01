<?php
/**
 * The landlord's create-a-listing wizard.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Create, save as draft, and submit a listing from the front end.
 *
 * ─── THE FIRST MILESTONE 2 FEATURE ─────────────────────────────────────────
 *
 * Until now a landlord's home was listed by the staff, in wp-admin, on their
 * behalf — the dashboard's "Add property" button said so by pointing at the
 * contact form. This wizard is the approved prototype's four-step flow made
 * real: basics, features, then review and submit into the same moderation
 * queue the admin already runs.
 *
 * ─── EVERY STEP LEAVES A COMPLETE FLOW ─────────────────────────────────────
 *
 * The handoff forbids screens that dead-end, so the wizard is built to be
 * whole at every stage of ITS OWN construction too. It began as a
 * three-step flow while the photo step was still being designed; that
 * design has landed, and photos & description now sit as step 3 of four —
 * uploads become real attachments the public listing page already reads
 * (the first one becomes the featured image), and the description becomes
 * post_content, which `the_content()` on the single template renders.
 *
 * ─── WHAT IT WRITES ────────────────────────────────────────────────────────
 *
 * Exactly the keys Fields::listing_schema() declares and the admin meta
 * boxes read — the wizard is another door into the same record, not a second
 * data model. The street address stays in private meta, never public markup
 * (§9). Neighborhood, property type and amenities are the existing
 * taxonomies, so search and filtering see a wizard-made listing exactly as
 * they see a staff-made one.
 */
final class Listing_Form {

	public const NONCE = 'tdh_listing_form';

	/** Basics, features, photos & description, review. */
	private const STEPS = [ 1, 2, 3, 4 ];

	/** The prototype's stated ceiling: "JPG, PNG, or WebP · maximum 10". */
	public const MAX_PHOTOS = 10;

	/** The prototype's stated ceiling: "Maximum 1500 characters". */
	public const MAX_DESCRIPTION = 1500;

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'handle' ] );
	}

	/* ---------------------------------------------------------------------
	 * What may be picked
	 * ------------------------------------------------------------------ */

	/**
	 * Minimum-stay choices — the schema's own option list, so the wizard
	 * and the admin select can never disagree.
	 *
	 * @return array<string,string>
	 */
	public static function stay_options(): array {
		return (array) ( Fields::listing_schema()['_tdh_min_stay_days']['options'] ?? [] );
	}

	/**
	 * The amenity catalogue, grouped as the prototype groups it.
	 *
	 * A fixed catalogue rather than free entry: amenities are a taxonomy
	 * renters filter by, and free text would split "Wi-Fi", "wifi" and
	 * "wireless internet" into three filters that each miss the other two
	 * homes.
	 *
	 * @return array<string,string[]>
	 */
	public static function amenity_groups(): array {
		return [
			__( 'Essentials', 'thirtydayhomes' )             => [
				__( 'Fully furnished', 'thirtydayhomes' ),
				__( 'Utilities included', 'thirtydayhomes' ),
				__( 'High-speed Wi-Fi', 'thirtydayhomes' ),
				__( 'Heating', 'thirtydayhomes' ),
				__( 'Air conditioning', 'thirtydayhomes' ),
				__( 'Linens provided', 'thirtydayhomes' ),
				__( 'Room-darkening shades', 'thirtydayhomes' ),
				__( 'Extra storage', 'thirtydayhomes' ),
			],
			__( 'Kitchen & dining', 'thirtydayhomes' )       => [
				__( 'Full kitchen', 'thirtydayhomes' ),
				__( 'Kitchenette', 'thirtydayhomes' ),
				__( 'Refrigerator', 'thirtydayhomes' ),
				__( 'Freezer', 'thirtydayhomes' ),
				__( 'Dishwasher', 'thirtydayhomes' ),
				__( 'Microwave', 'thirtydayhomes' ),
				__( 'Stovetop', 'thirtydayhomes' ),
				__( 'Oven', 'thirtydayhomes' ),
				__( 'Dining table', 'thirtydayhomes' ),
				__( 'Cookware and dishes', 'thirtydayhomes' ),
				__( 'Coffee maker', 'thirtydayhomes' ),
				__( 'Toaster', 'thirtydayhomes' ),
			],
			__( 'Laundry', 'thirtydayhomes' )                => [
				__( 'In-unit washer', 'thirtydayhomes' ),
				__( 'In-unit dryer', 'thirtydayhomes' ),
				__( 'Shared laundry', 'thirtydayhomes' ),
				__( 'Iron and ironing board', 'thirtydayhomes' ),
				__( 'Laundry supplies', 'thirtydayhomes' ),
			],
			__( 'Workspace', 'thirtydayhomes' )              => [
				__( 'Dedicated workspace', 'thirtydayhomes' ),
				__( 'Desk and chair', 'thirtydayhomes' ),
				__( 'Ethernet connection', 'thirtydayhomes' ),
			],
			__( 'Bathroom', 'thirtydayhomes' )               => [
				__( 'Bathtub', 'thirtydayhomes' ),
				__( 'Walk-in shower', 'thirtydayhomes' ),
				__( 'Hair dryer', 'thirtydayhomes' ),
				__( 'Towels provided', 'thirtydayhomes' ),
				__( 'Starter toiletries', 'thirtydayhomes' ),
			],
			__( 'Entertainment', 'thirtydayhomes' )          => [
				__( 'Smart TV', 'thirtydayhomes' ),
				__( 'Cable or streaming TV', 'thirtydayhomes' ),
				__( 'Game console', 'thirtydayhomes' ),
				__( 'Books and games', 'thirtydayhomes' ),
			],
			__( 'Parking & access', 'thirtydayhomes' )       => [
				__( 'Off-street parking', 'thirtydayhomes' ),
				__( 'Covered parking', 'thirtydayhomes' ),
				__( 'Garage parking', 'thirtydayhomes' ),
				__( 'Accessible parking', 'thirtydayhomes' ),
				__( 'EV charging', 'thirtydayhomes' ),
				__( 'Elevator', 'thirtydayhomes' ),
				__( 'Step-free entrance', 'thirtydayhomes' ),
				__( 'Wheelchair accessible', 'thirtydayhomes' ),
				__( 'Wide hallways', 'thirtydayhomes' ),
			],
			__( 'Outdoor & fitness', 'thirtydayhomes' )      => [
				__( 'Private patio or balcony', 'thirtydayhomes' ),
				__( 'Fenced yard', 'thirtydayhomes' ),
				__( 'Outdoor grill', 'thirtydayhomes' ),
				__( 'Fire pit', 'thirtydayhomes' ),
				__( 'Pool access', 'thirtydayhomes' ),
				__( 'Fitness equipment', 'thirtydayhomes' ),
				__( 'Building gym', 'thirtydayhomes' ),
			],
			__( 'Safety', 'thirtydayhomes' )                 => [
				__( 'Smoke alarm', 'thirtydayhomes' ),
				__( 'Carbon monoxide alarm', 'thirtydayhomes' ),
				__( 'Fire extinguisher', 'thirtydayhomes' ),
				__( 'First-aid kit', 'thirtydayhomes' ),
				__( 'Security system', 'thirtydayhomes' ),
				__( 'Gated property', 'thirtydayhomes' ),
				__( 'No smoking', 'thirtydayhomes' ),
			],
			__( 'Services & suitability', 'thirtydayhomes' ) => [
				__( 'Cleaning supplies', 'thirtydayhomes' ),
				__( 'Housekeeping available', 'thirtydayhomes' ),
				__( 'Pet friendly', 'thirtydayhomes' ),
				__( 'Close to public transit', 'thirtydayhomes' ),
				__( 'Quiet setting', 'thirtydayhomes' ),
				__( 'Long-term stay ready', 'thirtydayhomes' ),
			],
		];
	}

	/* ---------------------------------------------------------------------
	 * Who may be here, and with which listing
	 * ------------------------------------------------------------------ */

	/**
	 * Why this landlord cannot add a home right now — or '' if they can.
	 */
	public static function gate_reason(): string {

		if ( ! is_user_logged_in() ) {
			return 'signin';
		}

		if ( ! Accounts::is_landlord() && ! Accounts::is_staff() ) {
			return 'role';
		}

		/*
		 * Staff skip the plan and allowance gates. The quota is a BILLING
		 * rule — what a paying member may hold — and staff are not billed;
		 * an administrator uses this wizard to list a home on a member's
		 * behalf, then assigns the author in wp-admin. Without this bypass
		 * the role check above lets staff in and the quota check throws
		 * them straight back out, which is a door onto a wall.
		 */
		if ( Accounts::is_staff() ) {
			return '';
		}

		$user  = get_current_user_id();
		$quota = Membership::quota( $user );

		if ( $quota < 1 ) {
			return 'plan';
		}

		/*
		 * Membership::listing_count() counts drafts and pending too — the
		 * quota is what a landlord may HAVE, not what is live, so fifty
		 * drafts cannot be queued up to publish the moment a plan starts.
		 * Someone at their limit can still open an EXISTING draft (the
		 * current_listing() escape below); only starting a new one is what
		 * the allowance blocks.
		 */
		if ( Membership::listing_count( $user ) >= $quota && ! self::current_listing() ) {
			return 'full';
		}

		return '';
	}

	/**
	 * The draft being edited, ownership enforced. Null when starting fresh.
	 */
	public static function current_listing(): ?\WP_Post {

		$id = isset( $_GET['listing'] ) ? (int) $_GET['listing'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $id ) {
			return null;
		}

		$post = get_post( $id );

		/*
		 * Somebody else's id in the URL gets the same answer as a missing
		 * one. Which listings exist under which numbers is not information
		 * this screen hands out — the ownership suite exists to keep one
		 * landlord out of another's records, and a wizard that behaved
		 * differently for "not yours" and "not real" would leak the
		 * difference.
		 */
		if ( ! $post
			|| Post_Types::LISTING !== $post->post_type
			|| (int) $post->post_author !== get_current_user_id()
			|| ! in_array( $post->post_status, [ 'draft', 'pending' ], true )
		) {
			return null;
		}

		return $post;
	}

	/* ---------------------------------------------------------------------
	 * Handling
	 * ------------------------------------------------------------------ */

	public function handle(): void {

		if ( ! isset( $_POST['tdh_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( (string) $_POST['tdh_action'] ) );

		if ( ! in_array( $action, [ 'listing_basics', 'listing_features', 'listing_photos', 'listing_submit' ], true ) ) {
			return;
		}

		if ( ! isset( $_POST['tdh_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tdh_nonce'] ) ), self::NONCE ) ) {
			$this->fail( 1, [ __( 'That form expired. Your work so far is saved as a draft — please continue from here.', 'thirtydayhomes' ) ] );
		}

		if ( '' !== self::gate_reason() && 'full' !== self::gate_reason() ) {
			wp_safe_redirect( self::url() );
			exit;
		}

		match ( $action ) {
			'listing_basics'   => $this->save_basics(),
			'listing_features' => $this->save_features(),
			'listing_photos'   => $this->save_photos(),
			'listing_submit'   => $this->submit(),
		};
	}

	private function save_basics(): void {

		$title        = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_title'] ?? '' ) ) );
		$address      = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_address'] ?? '' ) ) );
		$zip          = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_zip'] ?? '' ) ) );
		$neighborhood = (int) ( $_POST['tdh_neighborhood'] ?? 0 );
		$type         = (int) ( $_POST['tdh_type'] ?? 0 );
		$rent         = (float) ( $_POST['tdh_rent'] ?? 0 );
		$deposit      = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_deposit'] ?? '' ) ) );
		$beds         = (int) ( $_POST['tdh_beds'] ?? 0 );
		$baths        = (float) ( $_POST['tdh_baths'] ?? 0 );
		$available    = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_available'] ?? '' ) ) );

		$errors = [];

		if ( '' === $title ) {
			$errors[] = __( 'Give the listing a title — it is the first thing a renter reads.', 'thirtydayhomes' );
		}

		if ( '' === $address ) {
			$errors[] = __( 'The street address is needed for the distance search. It is never shown publicly.', 'thirtydayhomes' );
		}

		if ( ! preg_match( '/^\d{5}$/', $zip ) ) {
			$errors[] = __( 'Please enter a five-digit ZIP code.', 'thirtydayhomes' );
		}

		if ( $rent < 1 ) {
			$errors[] = __( 'Please enter the monthly rent.', 'thirtydayhomes' );
		}

		if ( $beds < 0 || $baths < 0 ) {
			$errors[] = __( 'Bedrooms and bathrooms cannot be negative.', 'thirtydayhomes' );
		}

		if ( '' !== $available && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $available ) ) {
			$errors[] = __( 'The available date does not look like a date.', 'thirtydayhomes' );
		}

		if ( $errors ) {
			$this->fail( 1, $errors );
		}

		$existing = self::current_listing();

		$postarr = [
			'post_type'   => Post_Types::LISTING,
			'post_title'  => $title,
			'post_status' => $existing ? $existing->post_status : 'draft',
			'post_author' => get_current_user_id(),
		];

		if ( $existing ) {
			$postarr['ID'] = $existing->ID;
			$id            = wp_update_post( $postarr, true );
		} else {
			$id = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $id ) || ! $id ) {
			$this->fail( 1, [ __( 'The draft could not be saved. Please try again.', 'thirtydayhomes' ) ] );
		}

		$id = (int) $id;

		update_post_meta( $id, '_tdh_street_address', $address );
		update_post_meta( $id, '_tdh_zip', $zip );
		update_post_meta( $id, '_tdh_price_monthly', $rent );
		update_post_meta( $id, '_tdh_beds', max( 0, $beds ) );
		update_post_meta( $id, '_tdh_baths', max( 0, $baths ) );

		// Optional fields are stored when given and cleared when emptied —
		// a deposit removed in an edit must not survive as a stale number.
		if ( '' !== $deposit ) {
			update_post_meta( $id, '_tdh_deposit', (float) $deposit );
		} else {
			delete_post_meta( $id, '_tdh_deposit' );
		}

		if ( '' !== $available ) {
			update_post_meta( $id, '_tdh_available_from', $available );
		}

		// Term IDS from our own select, verified to exist in the taxonomy
		// rather than trusted. An id from another taxonomy silently creates
		// nothing, which is correct.
		if ( $neighborhood > 0 && term_exists( $neighborhood, Post_Types::TAX_NEIGHBORHOOD ) ) {
			wp_set_object_terms( $id, [ $neighborhood ], Post_Types::TAX_NEIGHBORHOOD );
		}

		if ( $type > 0 && term_exists( $type, Post_Types::TAX_TYPE ) ) {
			wp_set_object_terms( $id, [ $type ], Post_Types::TAX_TYPE );
		}

		// "Save draft" saves the same way Continue does — the only difference
		// is staying put, with the save acknowledged.
		if ( ! empty( $_POST['tdh_save_only'] ) ) {
			$this->go( 1, $id, true );
		}

		$this->go( 2, $id );
	}

	private function save_features(): void {

		$listing = self::current_listing();

		if ( ! $listing ) {
			wp_safe_redirect( self::url() );
			exit;
		}

		$stay = sanitize_key( wp_unslash( (string) ( $_POST['tdh_stay'] ?? '' ) ) );

		if ( array_key_exists( $stay, self::stay_options() ) ) {
			update_post_meta( $listing->ID, '_tdh_min_stay_days', (int) $stay );
		}

		/*
		 * Amenities arrive as labels and are matched against the catalogue,
		 * NOT trusted: an invented value would otherwise create a taxonomy
		 * term every renter's filter list then displays. The catalogue is
		 * the vocabulary; the form only picks from it.
		 */
		$catalogue = array_merge( ...array_values( self::amenity_groups() ) );
		$picked    = array_map(
			static fn( $a ) => sanitize_text_field( wp_unslash( (string) $a ) ),
			(array) ( $_POST['tdh_amenities'] ?? [] )
		);
		$picked    = array_values( array_intersect( $picked, $catalogue ) );

		wp_set_object_terms( $listing->ID, $picked, Post_Types::TAX_AMENITY );

		if ( ! empty( $_POST['tdh_save_only'] ) ) {
			$this->go( 2, $listing->ID, true );
		}

		$this->go( 3, $listing->ID );
	}

	private function save_photos(): void {

		$listing = self::current_listing();

		if ( ! $listing ) {
			wp_safe_redirect( self::url() );
			exit;
		}

		$errors = [];

		/*
		 * The description becomes post_content — the field the single
		 * listing template's the_content() already renders, and the field
		 * the admin's editor already edits. Not a parallel meta key.
		 */
		$description = sanitize_textarea_field( wp_unslash( (string) ( $_POST['tdh_description'] ?? '' ) ) );

		if ( mb_strlen( $description ) > self::MAX_DESCRIPTION ) {
			$errors[] = sprintf(
				/* translators: %s: character limit */
				__( 'The description is over the %s-character limit. It was not saved — please shorten it.', 'thirtydayhomes' ),
				number_format_i18n( self::MAX_DESCRIPTION )
			);
		} else {
			wp_update_post(
				[
					'ID'           => $listing->ID,
					'post_content' => $description,
				]
			);
		}

		/*
		 * Removals before uploads, so swapping the tenth photo for a better
		 * one works in a single visit instead of failing the count check.
		 * Only attachments parented to THIS listing can be removed — an id
		 * from someone else's media library is ignored, same ownership rule
		 * as everywhere else in the wizard.
		 */
		foreach ( array_map( 'intval', (array) ( $_POST['tdh_remove'] ?? [] ) ) as $att_id ) {
			$att = get_post( $att_id );

			if ( $att && 'attachment' === $att->post_type && (int) $att->post_parent === $listing->ID ) {
				wp_delete_attachment( $att_id, true );
			}
		}

		$files = isset( $_FILES['tdh_photos'] ) && is_array( $_FILES['tdh_photos'] ) ? $_FILES['tdh_photos'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( $files && is_array( $files['name'] ?? null ) ) {

			$incoming = count( array_filter( (array) $files['name'], static fn( $n ) => '' !== (string) $n ) );

			if ( $incoming > 0 && count( self::photos( $listing->ID ) ) + $incoming > self::MAX_PHOTOS ) {
				$errors[] = sprintf(
					/* translators: %s: photo limit */
					__( 'A listing can hold %s photos. Remove one to make room, then upload again.', 'thirtydayhomes' ),
					number_format_i18n( self::MAX_PHOTOS )
				);
			} elseif ( $incoming > 0 ) {

				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';

				/**
				 * Filter the upload overrides.
				 *
				 * The mimes list is the whole security story here: only the
				 * three formats the prototype names, no SVG (scriptable), no
				 * GIF. The verify suite uses this hook to disable the
				 * is_uploaded_file() check its fixtures cannot satisfy.
				 *
				 * @param array<string,mixed> $overrides For wp_handle_upload().
				 */
				$overrides = apply_filters(
					'tdh_listing_upload_overrides',
					[
						'test_form' => false,
						'mimes'     => [
							'jpg|jpeg' => 'image/jpeg',
							'png'      => 'image/png',
							'webp'     => 'image/webp',
						],
					]
				);

				foreach ( array_keys( (array) $files['name'] ) as $i ) {

					if ( '' === (string) $files['name'][ $i ] ) {
						continue;
					}

					// media_handle_upload() reads one $_FILES slot by key, so
					// each file from the multi-input is staged under its own.
					$_FILES['tdh_photo_one'] = [
						'name'     => $files['name'][ $i ],
						'type'     => $files['type'][ $i ],
						'tmp_name' => $files['tmp_name'][ $i ],
						'error'    => $files['error'][ $i ],
						'size'     => $files['size'][ $i ],
					];

					$att = media_handle_upload( 'tdh_photo_one', $listing->ID, [], $overrides );

					unset( $_FILES['tdh_photo_one'] );

					if ( is_wp_error( $att ) ) {
						$errors[] = sprintf(
							/* translators: 1: file name, 2: reason */
							__( '“%1$s” was not uploaded: %2$s', 'thirtydayhomes' ),
							sanitize_text_field( (string) $files['name'][ $i ] ),
							$att->get_error_message()
						);
					}
				}
			}
		}

		// The first photo becomes the cover the search cards and the single
		// page already show — unless the landlord has set one already.
		if ( ! has_post_thumbnail( $listing->ID ) ) {
			$all = self::photos( $listing->ID );

			if ( $all ) {
				set_post_thumbnail( $listing->ID, $all[0] );
			}
		}

		/*
		 * Partial success stays saved AND reported: three photos in, one
		 * refused, means three real attachments and one named error — not a
		 * rollback, and not a silent shrug.
		 */
		if ( $errors ) {
			$this->fail( 3, $errors, $listing->ID );
		}

		if ( ! empty( $_POST['tdh_save_only'] ) ) {
			$this->go( 3, $listing->ID, true );
		}

		$this->go( 4, $listing->ID );
	}

	/**
	 * The listing's photos, oldest first — attachment ids.
	 *
	 * @return int[]
	 */
	public static function photos( int $listing_id ): array {
		return array_map(
			'intval',
			get_posts(
				[
					'post_type'      => 'attachment',
					'post_parent'    => $listing_id,
					'post_mime_type' => 'image',
					'post_status'    => 'inherit',
					'posts_per_page' => self::MAX_PHOTOS * 2,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
				]
			)
		);
	}

	private function submit(): void {

		$listing = self::current_listing();

		if ( ! $listing ) {
			wp_safe_redirect( self::url() );
			exit;
		}

		/*
		 * The Fair Housing acknowledgment is required by the handoff's data
		 * model and §9's legal baseline. It is recorded, not just required:
		 * "they ticked the box" is only worth something if the record can
		 * say when.
		 */
		if ( empty( $_POST['tdh_fair_housing'] ) ) {
			$this->fail( 4, [ __( 'Please confirm the listing describes the property, not the ideal renter — the Fair Housing commitment.', 'thirtydayhomes' ) ], $listing->ID );
		}

		// The allowance is re-checked at the moment of submission, not only
		// at the door: a membership can lapse between starting a draft on
		// Monday and submitting it on Friday. Staff are exempt for the same
		// reason they pass the gate — the allowance is a billing rule.
		$user = get_current_user_id();

		if ( ! Accounts::is_staff() && Membership::quota( $user ) < 1 ) {
			$this->fail( 4, [ __( 'Your membership is not active, so the listing stays as a draft. It submits from here as soon as a plan is.', 'thirtydayhomes' ) ], $listing->ID );
		}

		update_post_meta( $listing->ID, '_tdh_fair_housing_ack', time() );

		wp_update_post(
			[
				'ID'          => $listing->ID,
				'post_status' => 'pending',
			]
		);

		/**
		 * Fires when a landlord submits a listing for review.
		 *
		 * The seam for the moderation notification when the admin queue
		 * grows one, without reopening this handler.
		 *
		 * @param int $listing_id
		 */
		do_action( 'tdh_listing_submitted', $listing->ID );

		wp_safe_redirect( add_query_arg( 'tdh_submitted', '1', Accounts::url( 'account' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Plumbing
	 * ------------------------------------------------------------------ */

	public static function url( int $step = 1, int $listing = 0 ): string {

		$base = Accounts::url( 'add-listing' );
		$args = [ 'step' => max( 1, min( max( self::STEPS ), $step ) ) ];

		if ( $listing > 0 ) {
			$args['listing'] = $listing;
		}

		return add_query_arg( $args, $base );
	}

	private function go( int $step, int $listing, bool $saved = false ): void {

		$url = self::url( $step, $listing );

		if ( $saved ) {
			$url = add_query_arg( 'saved', '1', $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Stash errors for the redirect and go back to the step they belong to.
	 *
	 * Keyed on the user id alone: this screen requires sign-in, so the
	 * cookie-token machinery the public forms need is unnecessary here.
	 *
	 * @param string[] $errors
	 */
	private function fail( int $step, array $errors, int $listing = 0 ): void {

		set_transient( 'tdh_lform_' . get_current_user_id(), $errors, 5 * MINUTE_IN_SECONDS );

		$listing = $listing ?: (int) ( self::current_listing()->ID ?? 0 );

		$this->go( $step, $listing );
	}

	/**
	 * @return string[]
	 */
	public static function take_errors(): array {

		if ( ! is_user_logged_in() ) {
			return [];
		}

		$key    = 'tdh_lform_' . get_current_user_id();
		$stored = get_transient( $key );

		delete_transient( $key );

		return array_map( 'strval', (array) ( $stored ?: [] ) );
	}
}
