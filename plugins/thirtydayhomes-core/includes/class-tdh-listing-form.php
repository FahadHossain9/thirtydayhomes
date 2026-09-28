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

	/** Longest free-text detail accepted: parking, backyard, utilities. */
	public const MAX_DETAIL = 80;

	/** Upper bounds that catch a typo without refusing a real home. */
	public const MAX_SQFT  = 20000;
	public const MAX_ROOMS = 50;
	public const MAX_FEE   = 100000;

	/**
	 * The material fields as they stood before this request's save, for a
	 * landlord's live or paused home. Null for everything else.
	 *
	 * @var array<string,string>|null
	 */
	private ?array $snapshot = null;

	/** The landlord confirmed that this save may send the home to review. */
	private bool $confirmed = false;

	/** Set once the home has been moved to review in this request. */
	private bool $went_to_review = false;

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'handle' ] );
	}

	/* ---------------------------------------------------------------------
	 * Editing a home that is already live
	 * ------------------------------------------------------------------ */

	/*
	 * ─── SMALL CHANGES GO LIVE, BIG ONES ASK FIRST ─────────────────────────
	 *
	 * A live home's rent, fees, availability, amenities, utilities, pets,
	 * parking and contact details change on the spot — nothing a reviewer
	 * needs to see again. The things a reviewer approved the home FOR —
	 * what it is called, what it says, what it looks like, where it is, what
	 * kind of home it is and how big — go back to review, and the home comes
	 * off the site until they are approved.
	 *
	 * The landlord is told BEFORE that happens, never after: the step is
	 * held, a confirmation page names the fields, and only "Save and submit
	 * for review" saves. Chosen photo files cannot travel through a
	 * confirmation page, so the photo step carries its own tick for live
	 * homes, and the photo controls wait for it.
	 *
	 * ─── WHAT DECIDES IT IS WHAT WAS STORED ────────────────────────────────
	 *
	 * The confirmation page is a courtesy, not the rule. The rule is enforced
	 * after the save, by comparing the material fields as they were stored
	 * before the request with how they are stored after it
	 * (material_snapshot()). So a form posted without a field, a refused
	 * upload, a title WordPress stores with "&amp;", or a description written
	 * in the block editor can neither slip a real change past review nor
	 * send an unchanged home to it.
	 *
	 * Staff are exempt: their edits publish at once and never change the
	 * status. A PAUSED home is already off the site, so its edits just save
	 * — but what really changed is remembered, and Resume reviews it first.
	 */

	/**
	 * The fields a reviewer approved the home for. Decision 8 in the plan;
	 * filterable so the client's final answer changes one list.
	 *
	 * @return array<string,string> field key => label shown to the landlord
	 */
	public static function material_fields(): array {

		$fields = [
			'post_title'          => __( 'Title', 'thirtydayhomes' ),
			'post_content'        => __( 'Description', 'thirtydayhomes' ),
			'photos'              => __( 'Photos', 'thirtydayhomes' ),
			'_tdh_street_address' => __( 'Street address', 'thirtydayhomes' ),
			'_tdh_zip'            => __( 'ZIP code', 'thirtydayhomes' ),
			'tdh_city'            => __( 'City', 'thirtydayhomes' ),
			'tdh_neighborhood'    => __( 'Neighborhood', 'thirtydayhomes' ),
			'tdh_property_type'   => __( 'Property type', 'thirtydayhomes' ),
			'_tdh_beds'           => __( 'Bedrooms', 'thirtydayhomes' ),
			'_tdh_baths'          => __( 'Bathrooms', 'thirtydayhomes' ),
		];

		/**
		 * Filter which edits to a live listing send it back to review.
		 *
		 * @param array<string,string> $fields Field key => label.
		 */
		return (array) apply_filters( 'tdh_material_fields', $fields );
	}

	/** "Title, photos and bedrooms" — the labels as one readable list. */
	public static function human_list( array $labels ): string {

		$labels = array_values( array_filter( array_map( 'strval', $labels ), static fn( string $l ): bool => '' !== $l ) );

		if ( count( $labels ) < 2 ) {
			return (string) ( $labels[0] ?? '' );
		}

		$last = array_pop( $labels );

		/* translators: 1: comma-separated items, 2: the last item */
		return sprintf( __( '%1$s and %2$s', 'thirtydayhomes' ), implode( __( ', ', 'thirtydayhomes' ), $labels ), $last );
	}

	/**
	 * The labels as they read inside a sentence: "the title, photos and ZIP
	 * code". Accepts a stored comma list or an array.
	 *
	 * @param string|string[] $labels
	 */
	public static function changes_phrase( $labels ): string {

		$labels = is_array( $labels ) ? $labels : explode( ',', (string) $labels );

		$lower = array_map(
			// "ZIP code" keeps its capitals; ordinary labels do not.
			static fn( string $l ): string => preg_match( '/^\p{Lu}{2}/u', $l ) ? $l : mb_strtolower( mb_substr( $l, 0, 1 ) ) . mb_substr( $l, 1 ),
			array_map( 'trim', $labels )
		);

		return self::human_list( $lower );
	}

	/** Text compared the way it reads, not the way WordPress happened to store it. */
	private static function comparable( string $text, bool $multiline = false ): string {
		$clean = $multiline ? sanitize_textarea_field( $text ) : sanitize_text_field( $text );
		return trim( html_entity_decode( $clean, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * The material fields of a home as they are stored now.
	 *
	 * @return array<string,string> field key => comparable value
	 */
	public static function material_snapshot( int $id ): array {

		$post   = get_post( $id );
		$fields = self::material_fields();
		$terms  = static function ( string $tax ) use ( $id ): string {
			$ids = array_map( 'intval', (array) wp_get_object_terms( $id, $tax, [ 'fields' => 'ids' ] ) );
			sort( $ids );
			return implode( ',', $ids );
		};

		$values = [
			'post_title'          => static fn() => self::comparable( (string) ( $post->post_title ?? '' ) ),
			'post_content'        => static fn() => self::comparable( (string) ( $post->post_content ?? '' ), true ),
			'photos'              => static fn() => implode( ',', Listing_Photos::ordered( $id ) ),
			'_tdh_street_address' => static fn() => self::comparable( (string) get_post_meta( $id, '_tdh_street_address', true ) ),
			'_tdh_zip'            => static fn() => trim( (string) get_post_meta( $id, '_tdh_zip', true ) ),
			'tdh_city'            => static fn() => $terms( Post_Types::TAX_CITY ),
			'tdh_neighborhood'    => static fn() => $terms( Post_Types::TAX_NEIGHBORHOOD ),
			'tdh_property_type'   => static fn() => $terms( Post_Types::TAX_TYPE ),
			'_tdh_beds'           => static fn() => (string) (int) get_post_meta( $id, '_tdh_beds', true ),
			'_tdh_baths'          => static fn() => (string) (float) get_post_meta( $id, '_tdh_baths', true ),
		];

		$snapshot = [];

		foreach ( array_keys( $fields ) as $key ) {
			if ( isset( $values[ $key ] ) ) {
				$snapshot[ $key ] = $values[ $key ]();
			}
		}

		return $snapshot;
	}

	/**
	 * Which material fields this step's POST would change, as labels — the
	 * question the confirmation page asks. Compared the way the step saves:
	 * a field missing from the POST counts as what the save would write.
	 *
	 * @return array<string,string> field key => label
	 */
	public static function material_changes( \WP_Post $listing, string $action ): array {

		$fields  = self::material_fields();
		$before  = self::material_snapshot( (int) $listing->ID );
		$changed = [];

		$raw  = static fn( string $key ): string => wp_unslash( (string) ( $_POST[ $key ] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- checked in handle(); compared, never stored, here.
		$term = static function ( string $field, string $key ) use ( $raw, $before ): bool {
			$picked = (int) $raw( $field );
			// "Choose…" clears nothing, and a home that never had this term
			// is having it filled in, not changed. (City is preselected when
			// there is only one, so a missing city must not force a review.)
			return $picked > 0 && '' !== ( $before[ $key ] ?? '' ) && (string) $picked !== $before[ $key ];
		};

		if ( 'listing_basics' === $action ) {

			$compare = [
				'post_title'          => static fn() => self::comparable( $raw( 'tdh_title' ) ) !== $before['post_title'],
				'_tdh_street_address' => static fn() => self::comparable( $raw( 'tdh_address' ) ) !== $before['_tdh_street_address'],
				'_tdh_zip'            => static fn() => trim( sanitize_text_field( $raw( 'tdh_zip' ) ) ) !== $before['_tdh_zip'],
				'tdh_city'            => static fn() => $term( 'tdh_city', 'tdh_city' ),
				'tdh_neighborhood'    => static fn() => $term( 'tdh_neighborhood', 'tdh_neighborhood' ),
				'tdh_property_type'   => static fn() => $term( 'tdh_type', 'tdh_property_type' ),
				'_tdh_beds'           => static fn() => (string) max( 0, (int) $raw( 'tdh_beds' ) ) !== $before['_tdh_beds'],
				'_tdh_baths'          => static fn() => (string) max( 0.0, (float) $raw( 'tdh_baths' ) ) !== $before['_tdh_baths'],
			];

			foreach ( $compare as $key => $differs ) {
				if ( isset( $fields[ $key ], $before[ $key ] ) && $differs() ) {
					$changed[ $key ] = $fields[ $key ];
				}
			}
		}

		if ( 'listing_photos' === $action ) {

			if ( isset( $fields['post_content'], $before['post_content'] ) && self::comparable( $raw( 'tdh_description' ), true ) !== $before['post_content'] ) {
				$changed['post_content'] = $fields['post_content'];
			}

			$id       = (int) $listing->ID;
			$removing = array_filter(
				array_map( 'intval', (array) ( $_POST['tdh_remove'] ?? [] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				static fn( int $att ): bool => Listing_Photos::belongs( $id, $att )
			);

			$touching = $removing
				|| self::files_chosen()
				|| (bool) preg_match( '/^\d+:(up|down)$/', sanitize_text_field( $raw( 'tdh_photo_move' ) ) )
				|| isset( $_POST['tdh_photo_cover'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( isset( $fields['photos'] ) && $touching ) {
				$changed['photos'] = $fields['photos'];
			}
		}

		return $changed;
	}

	/** Did files arrive with the photo step? */
	private static function files_chosen(): bool {
		$names = (array) ( $_FILES['tdh_photos']['name'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return [] !== array_filter( $names, static fn( $n ): bool => '' !== (string) $n );
	}

	/**
	 * What step 1 refuses outright, before anything is saved. Shared with
	 * guard_live_edit(), so a landlord is never asked to confirm a review
	 * for a step that is about to be refused.
	 *
	 * @return string[]
	 */
	private static function basics_errors(): array {

		$field = static fn( string $key ): string => sanitize_text_field( wp_unslash( (string) ( $_POST[ $key ] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in handle().

		$errors = [];

		if ( '' === $field( 'tdh_title' ) ) {
			$errors[] = __( 'Give the listing a title — it is the first thing a renter reads.', 'thirtydayhomes' );
		}

		if ( '' === $field( 'tdh_address' ) ) {
			$errors[] = __( 'The street address is needed for the distance search. It is never shown publicly.', 'thirtydayhomes' );
		}

		if ( ! preg_match( '/^\d{5}$/', $field( 'tdh_zip' ) ) ) {
			$errors[] = __( 'Please enter a five-digit ZIP code.', 'thirtydayhomes' );
		}

		if ( (float) ( $_POST['tdh_rent'] ?? 0 ) < 1 ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$errors[] = __( 'Please enter the monthly rent.', 'thirtydayhomes' );
		}

		if ( (int) ( $_POST['tdh_beds'] ?? 0 ) < 0 || (float) ( $_POST['tdh_baths'] ?? 0 ) < 0 ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$errors[] = __( 'Bedrooms and bathrooms cannot be negative.', 'thirtydayhomes' );
		}

		// "Available from" moved to step 2 with the rest of availability (A5).

		return $errors;
	}

	/**
	 * Before a step saves: remember how the home stands, and ask first when
	 * the edit would send a live home back to review.
	 */
	private function guard_live_edit( string $action ): void {

		$listing = self::current_listing();

		if ( ! $listing || Accounts::is_staff() ) {
			return;
		}

		if ( ! in_array( $listing->post_status, [ 'publish', Statuses::PAUSED ], true ) ) {
			return;
		}

		// Always, for a landlord's live or paused home: the comparison after
		// the save is what enforces review, whatever the form claimed.
		$this->snapshot = self::material_snapshot( (int) $listing->ID );

		if ( ! empty( $_POST['tdh_confirm_review'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in handle().
			// The confirmation came back: it has done its job.
			self::discard_confirmation();
		}

		if ( 'publish' !== $listing->post_status ) {
			return; // Paused: nothing to take offline; see finish_live_edit().
		}

		$changes = self::material_changes( $listing, $action );

		if ( ! $changes ) {
			return;
		}

		// A step that is about to be refused is not worth a confirmation.
		if ( 'listing_basics' === $action && self::basics_errors() ) {
			return;
		}

		$step = [ 'listing_basics' => 1, 'listing_features' => 2, 'listing_photos' => 3 ][ $action ] ?? 1;

		if ( empty( $_POST['tdh_confirm_review'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$this->ask_to_confirm( $listing, $step, $changes );
		}

		// Confirmed. The allowance is checked now, before anything is saved:
		// a lapsed member cannot put a home into the review queue.
		if ( Membership::quota( get_current_user_id() ) < 1 ) {
			$this->fail( $step, [ __( 'Your membership isn’t active, so a change that needs a review can’t be saved. Update your billing on the Membership screen first. Nothing was changed.', 'thirtydayhomes' ) ], $listing->ID );
		}

		$this->confirmed = true;
	}

	/**
	 * Hold the step and show the confirmation page.
	 *
	 * The POST is kept for ten minutes and re-sent, with the confirmation
	 * flag, by the page's "Save and submit for review" button. Chosen files
	 * cannot be kept, so uploads to a live home are refused here with the
	 * way to do it — the photo step's tick — and the description typed
	 * alongside is kept for that home's photo step.
	 *
	 * @param string[] $changes
	 */
	private function ask_to_confirm( \WP_Post $listing, int $step, array $changes ): void {

		if ( self::files_chosen() ) {
			self::stash_values( (int) $listing->ID, [ 'tdh_description' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['tdh_description'] ?? '' ) ) ) ] );
			$this->fail( 3, [ __( 'Adding photos to a live home sends it back to review. Tick the box above the photos to allow that, then choose the photos again. Nothing was changed.', 'thirtydayhomes' ) ], $listing->ID );
		}

		$carry = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- re-posted through the same handler, which sanitises every field.
		unset( $carry['tdh_nonce'], $carry['tdh_confirm_review'] );

		set_transient(
			'tdh_lform_confirm_' . get_current_user_id(),
			[
				'listing' => (int) $listing->ID,
				'step'    => $step,
				'changes' => array_values( $changes ),
				'post'    => $carry,
			],
			10 * MINUTE_IN_SECONDS
		);

		$this->go( $step, $listing->ID, false, [ 'confirm' => 1 ] );
	}

	/**
	 * The held step, for the confirmation page. Read, not taken: a refresh
	 * of the page must not lose it. It goes when the confirmation is sent,
	 * when the step is opened again, or after ten minutes.
	 *
	 * @return array{listing:int,step:int,changes:string[],post:array<string,mixed>}|null
	 */
	public static function held_confirmation(): ?array {

		if ( ! is_user_logged_in() ) {
			return null;
		}

		$held = get_transient( 'tdh_lform_confirm_' . get_current_user_id() );

		return is_array( $held ) && isset( $held['listing'], $held['step'], $held['changes'], $held['post'] ) ? $held : null;
	}

	/** "Go back and discard changes" is simply the step opened again; the hold goes. */
	public static function discard_confirmation(): void {
		if ( is_user_logged_in() ) {
			delete_transient( 'tdh_lform_confirm_' . get_current_user_id() );
		}
	}

	/**
	 * After the save: did a material field really change?
	 *
	 * A live home goes to review — once, with the fields that really
	 * changed. A paused home remembers them, so Resume reviews it first.
	 * An edit that changed nothing material changes nothing here.
	 */
	private function finish_live_edit( int $id ): void {

		if ( null === $this->snapshot || $this->went_to_review ) {
			return;
		}

		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$fields  = self::material_fields();
		$after   = self::material_snapshot( $id );
		$changed = [];

		// Terms a home never had are being filled in, not changed — the same
		// rule material_changes() asks the landlord by.
		$terms = [ 'tdh_city', 'tdh_neighborhood', 'tdh_property_type' ];

		foreach ( $this->snapshot as $key => $value ) {
			if ( in_array( $key, $terms, true ) && '' === $value ) {
				continue;
			}

			if ( ( $after[ $key ] ?? '' ) !== $value && isset( $fields[ $key ] ) ) {
				$changed[] = $fields[ $key ];
			}
		}

		// Compared once per request; a second call has nothing new to say.
		$this->snapshot = null;

		if ( ! $changed ) {
			return;
		}

		if ( Statuses::PAUSED === $post->post_status ) {
			$known = array_filter( array_map( 'trim', explode( ',', (string) get_post_meta( $id, '_tdh_edited_while_paused', true ) ) ) );
			update_post_meta( $id, '_tdh_edited_while_paused', implode( ', ', array_values( array_unique( array_merge( $known, $changed ) ) ) ) );
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}

		/*
		 * Normally the landlord confirmed this. If not — a form posted by
		 * hand, without the page's fields — the change still goes to review:
		 * what renters see is never changed without one.
		 */
		wp_update_post( [ 'ID' => $id, 'post_status' => 'pending' ] );
		update_post_meta( $id, '_tdh_submitted_at', current_time( 'mysql' ) );
		update_post_meta( $id, '_tdh_reviewing_edits', implode( ', ', $changed ) );
		delete_post_meta( $id, '_tdh_edited_while_paused' );

		/**
		 * Fires when an edited listing goes to review: a live home with a
		 * material change, or a paused one resumed after such a change.
		 *
		 * TDH\Listing_Actions emails staff from here.
		 *
		 * @param int      $listing_id
		 * @param string[] $changed    Labels of the fields that changed.
		 * @param bool     $was_paused True when it comes from Resume on a paused home.
		 */
		do_action( 'tdh_listing_changes_submitted', $id, $changed, false );

		$this->went_to_review = true;
	}

	/**
	 * Keep typed values across a redirect when a step is refused — for that
	 * home only.
	 *
	 * @param array<string,string> $values Field name => value.
	 */
	private static function stash_values( int $listing, array $values ): void {
		set_transient( 'tdh_lform_values_' . get_current_user_id(), [ 'listing' => $listing, 'values' => $values ], 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * The values kept for this home, once. Another home's stash is left for
	 * that home.
	 *
	 * @return array<string,string>
	 */
	public static function take_values( int $listing ): array {

		if ( ! is_user_logged_in() || $listing < 1 ) {
			return [];
		}

		$key    = 'tdh_lform_values_' . get_current_user_id();
		$stored = get_transient( $key );

		if ( ! is_array( $stored ) || (int) ( $stored['listing'] ?? 0 ) !== $listing ) {
			return [];
		}

		delete_transient( $key );

		return array_map( 'strval', (array) ( $stored['values'] ?? [] ) );
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
	 * The real answers to a select field — its schema options without the
	 * "Not given" placeholder, which is a state, not a choice.
	 *
	 * @return array<string,string>
	 */
	public static function choices( string $key ): array {
		$options = (array) ( Fields::listing_schema()[ $key ]['options'] ?? [] );
		unset( $options[''] );
		return $options;
	}

	/**
	 * The amenity catalogue, grouped as the prototype groups it.
	 *
	 * A fixed catalogue rather than free entry: amenities are a taxonomy
	 * renters filter by, and free text would split "Wi-Fi", "wifi" and
	 * "wireless internet" into three filters that each miss the other two
	 * homes.
	 *
	 * "Utilities included" and "Pet friendly" used to be chips here. Both are
	 * now questions of their own with three answers each, and a chip beside
	 * the question would let one listing say "utilities not included" and
	 * "utilities included" at the same time. Saving step 2 drops the old
	 * chips from a listing, because the catalogue is the whitelist.
	 *
	 * @return array<string,string[]>
	 */
	public static function amenity_groups(): array {
		return [
			__( 'Essentials', 'thirtydayhomes' )             => [
				__( 'Fully furnished', 'thirtydayhomes' ),
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
		$staff_statuses = array_merge( [ 'publish', 'pending', 'draft', 'future', 'private' ], array_keys( Statuses::all() ) );
		$is_staff       = Accounts::is_staff();

		/*
		 * Landlords edit everything that is theirs: drafts, homes in review,
		 * homes sent back for changes, and live or paused homes — where the
		 * edits that need a second review ask first (guard_live_edit()).
		 * A billing-held home is the membership layer's, not the wizard's.
		 */
		$owner_statuses = [ 'draft', 'pending', Statuses::REJECTED, 'publish', Statuses::PAUSED ];

		if ( ! $post
			|| Post_Types::LISTING !== $post->post_type
			|| ( ! $is_staff && (int) $post->post_author !== get_current_user_id() )
			|| ! in_array( $post->post_status, $is_staff ? $staff_statuses : $owner_statuses, true )
		) {
			return null;
		}

		return $post;
	}

	/* ---------------------------------------------------------------------
	 * Handling
	 * ------------------------------------------------------------------ */

	public function handle(): void {

		$this->catch_overflow();

		if ( ! isset( $_POST['tdh_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( (string) $_POST['tdh_action'] ) );

		if ( ! in_array( $action, [ 'listing_basics', 'listing_features', 'listing_photos', 'listing_submit' ], true ) ) {
			return;
		}

		if ( ! isset( $_POST['tdh_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tdh_nonce'] ) ), self::NONCE ) ) {
			/*
			 * Back to the step that was posted, with the home, not to step 1
			 * of whatever: a landlord who left step 3 open overnight should
			 * find step 3 again. And no claim about "a draft" — the home may
			 * be live; what is true is that this step did not save.
			 */
			$step = [ 'listing_basics' => 1, 'listing_features' => 2, 'listing_photos' => 3, 'listing_submit' => 4 ][ $action ] ?? 1;
			$this->fail( $step, [ __( 'That page had been open too long, so this step was not saved. Everything saved before it is still there — please check your answers and continue.', 'thirtydayhomes' ) ] );
		}

		/*
		 * The gate again, on the POST. "full" used to be let through so an
		 * existing draft could still be edited at the limit — but the gate
		 * already answers '' for an existing draft, so the exception only
		 * ever admitted a NEW draft past the allowance.
		 */
		if ( '' !== self::gate_reason() ) {
			wp_safe_redirect( self::url() );
			exit;
		}

		// A live home's big changes ask first; see guard_live_edit().
		if ( 'listing_submit' !== $action ) {
			$this->guard_live_edit( $action );
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
		$city         = (int) ( $_POST['tdh_city'] ?? 0 );
		$rent         = (float) ( $_POST['tdh_rent'] ?? 0 );
		$beds         = (int) ( $_POST['tdh_beds'] ?? 0 );
		$baths        = (float) ( $_POST['tdh_baths'] ?? 0 );

		// The required answers, all or nothing — one list, shared with the
		// live-home guard so a refused step is never sent for confirmation.
		$errors = self::basics_errors();

		if ( $errors ) {
			$this->fail( 1, $errors );
		}

		$existing = self::current_listing();

		$postarr = [
			'post_type'   => Post_Types::LISTING,
			'post_title'  => $title,
			'post_status' => $existing ? $existing->post_status : 'draft',
		];

		if ( $existing ) {
			/*
			 * The author is set once, on creation, and never rewritten here.
			 * Staff may open any landlord's listing in this wizard; stamping
			 * the current user as author on every save would move that home
			 * out of its landlord's dashboard and into the staff member's.
			 */
			$postarr['ID'] = $existing->ID;
			$id            = wp_update_post( $postarr, true );
		} else {
			$postarr['post_author'] = get_current_user_id();
			$id                     = wp_insert_post( $postarr, true );
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

		/*
		 * Everything below is optional, and a mistake in any of it must not
		 * cost the landlord the rest of the step. The required fields above
		 * are all-or-nothing because there is no listing without them; from
		 * here on, what is valid is saved, what is not is named, and the
		 * landlord lands back on this step with the draft intact.
		 */
		$problems = [];

		// Fees. Stored when given, cleared when emptied — a deposit removed
		// in an edit must not survive as a stale number.
		$fees = [
			'tdh_deposit'         => [ '_tdh_deposit', __( 'Security deposit', 'thirtydayhomes' ) ],
			'tdh_application_fee' => [ '_tdh_application_fee', __( 'Application fee', 'thirtydayhomes' ) ],
			'tdh_cleaning_fee'    => [ '_tdh_cleaning_fee', __( 'Cleaning fee', 'thirtydayhomes' ) ],
		];

		foreach ( $fees as $field => [ $key, $label ] ) {
			$problems[] = self::store_number( $id, $key, $field, $label, self::MAX_FEE );
		}

		$problems = array_merge( $problems, self::save_contact( $id ) );
		$problems = array_values( array_filter( $problems ) );

		// Term IDS from our own select, verified to exist in the taxonomy
		// rather than trusted. An id from another taxonomy silently creates
		// nothing, which is correct.
		if ( $neighborhood > 0 && term_exists( $neighborhood, Post_Types::TAX_NEIGHBORHOOD ) ) {
			wp_set_object_terms( $id, [ $neighborhood ], Post_Types::TAX_NEIGHBORHOOD );
		}

		if ( $type > 0 && term_exists( $type, Post_Types::TAX_TYPE ) ) {
			wp_set_object_terms( $id, [ $type ], Post_Types::TAX_TYPE );
		}

		// The city the public pages read back. Verified like the others: an
		// id from another taxonomy assigns nothing rather than inventing a
		// city term the search would then offer to renters.
		if ( $city > 0 && term_exists( $city, Post_Types::TAX_CITY ) ) {
			wp_set_object_terms( $id, [ $city ], Post_Types::TAX_CITY );
		}

		if ( $problems ) {
			$problems[] = __( 'Everything else on this step is saved.', 'thirtydayhomes' );
			$this->fail( 1, $problems, $id );
		}

		// "Save draft" saves the same way Continue does — the only difference
		// is staying put, with the save acknowledged.
		if ( ! empty( $_POST['tdh_save_only'] ) ) {
			$this->go( 1, $id, true );
		}

		$this->after_save( 1, $id );
	}

	/*
	 * ─── WHERE A SAVED STEP GOES NEXT ──────────────────────────────────────
	 *
	 * Back used to be a plain link. A landlord who opened step 2 from the
	 * review, changed the pet policy and the square footage, and pressed
	 * Back lost every change without a word — the next page simply showed
	 * the old answers, and the listing was submitted with them. That was
	 * seen on localhost on 17 Sep 2026.
	 *
	 * So every way out of a step saves first:
	 *   Continue                   → the next step
	 *   Back (button or arrow)     → the previous step
	 *   opened from the review     → back to the review
	 * Invalid values still stop all three, with the value named; nothing a
	 * landlord typed disappears silently.
	 */

	/** Did the landlord press Back? */
	private static function going_back(): bool {
		return 'back' === sanitize_key( wp_unslash( (string) ( $_POST['tdh_go'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in handle().
	}

	/** Was this step opened from the review's Edit link? */
	private static function returning_to_review(): bool {
		return 'review' === sanitize_key( wp_unslash( (string) ( $_POST['tdh_return'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in handle().
	}

	/**
	 * @param array<string,int|string> $extra Query arguments to carry along.
	 */
	private function after_save( int $step, int $listing, array $extra = [] ): void {

		// A live or paused home: say the change is in, and where it stands.
		if ( in_array( get_post_status( $listing ), [ 'publish', Statuses::PAUSED ], true ) ) {
			$extra['updated'] = 1;
		}

		if ( self::going_back() && $step > 1 ) {
			$this->go( $step - 1, $listing, false, $extra );
		}

		if ( self::returning_to_review() ) {
			$this->go( 4, $listing, false, $extra );
		}

		$this->go( $step + 1, $listing, false, $extra );
	}

	/**
	 * Where inquiries about this home go. Private: never on the public page.
	 *
	 * Checked here as well as at submission, so a landlord who picks "Text
	 * message" and leaves the phone blank hears about it on the step where
	 * both fields are, not three steps later.
	 *
	 * @return string[] What could not be saved, in words.
	 */
	private static function save_contact( int $id ): array {

		$problems = [];

		if ( array_key_exists( 'tdh_contact_name', $_POST ) ) {
			$name = sanitize_text_field( wp_unslash( (string) $_POST['tdh_contact_name'] ) );
			self::store_text( $id, '_tdh_contact_name', mb_substr( $name, 0, self::MAX_DETAIL ) );
		}

		$phone_typed = '';

		if ( array_key_exists( 'tdh_contact_email', $_POST ) ) {
			$typed = sanitize_text_field( wp_unslash( (string) $_POST['tdh_contact_email'] ) );
			$email = sanitize_email( $typed );

			if ( '' === $typed ) {
				// Empty means "use my account address", which is what the
				// inquiry email falls back to.
				delete_post_meta( $id, '_tdh_contact_email' );
			} elseif ( is_email( $email ) ) {
				update_post_meta( $id, '_tdh_contact_email', $email );
			} else {
				$problems[] = sprintf(
					/* translators: %s: what the landlord typed */
					__( 'Email for inquiries: “%s” is not an address we can send to, so it was not saved.', 'thirtydayhomes' ),
					$typed
				);
			}
		}

		if ( array_key_exists( 'tdh_contact_phone', $_POST ) ) {
			$phone_typed = sanitize_text_field( wp_unslash( (string) $_POST['tdh_contact_phone'] ) );
			$phone       = Phone::normalise( $phone_typed );

			if ( '' === $phone_typed ) {
				delete_post_meta( $id, '_tdh_contact_phone' );
			} elseif ( '' !== $phone ) {
				update_post_meta( $id, '_tdh_contact_phone', $phone );
			} else {
				$problems[] = sprintf(
					/* translators: %s: what the landlord typed */
					__( 'Mobile number: “%s” is not a US mobile number, so it was not saved. Include the area code, like (412) 555-0184.', 'thirtydayhomes' ),
					$phone_typed
				);
			}
		}

		if ( array_key_exists( 'tdh_contact_method', $_POST ) ) {
			$method = sanitize_key( wp_unslash( (string) $_POST['tdh_contact_method'] ) );

			if ( array_key_exists( $method, self::choices( '_tdh_contact_method' ) ) ) {
				update_post_meta( $id, '_tdh_contact_method', $method );
			}

			// Only when the phone was left EMPTY: a mistyped number has
			// already been named above, and saying it twice is noise.
			if ( self::needs_phone( $id ) && '' === $phone_typed ) {
				$problems[] = __( 'Text messages need a mobile number. Add one, or choose Email as the way to reach you.', 'thirtydayhomes' );
			}
		}

		return $problems;
	}

	/** Does this listing ask for texts without a number to send them to? */
	private static function needs_phone( int $id ): bool {
		return in_array( (string) get_post_meta( $id, '_tdh_contact_method', true ), [ 'sms', 'both' ], true )
			&& '' === (string) get_post_meta( $id, '_tdh_contact_phone', true );
	}

	/**
	 * Store an optional amount, or name why it could not be stored.
	 *
	 * A field absent from the POST is left alone rather than cleared: a page
	 * rendered before a field existed must not erase what a newer page
	 * saved. Present and empty is the landlord clearing it.
	 *
	 * @return string '' when stored or cleared, otherwise the problem.
	 */
	private static function store_number( int $id, string $key, string $field, string $label, int $max, bool $integer = false ): string {

		if ( ! array_key_exists( $field, $_POST ) ) {
			return '';
		}

		$typed = trim( sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) ) );

		if ( '' === $typed ) {
			delete_post_meta( $id, $key );
			return '';
		}

		// "$1,200" is how people write money. The symbol and the separator
		// are not the mistake, so they are not refused.
		$clean = str_replace( [ '$', ',', ' ' ], '', $typed );

		if ( ! is_numeric( $clean ) || (float) $clean < 0 || (float) $clean > $max ) {
			return sprintf(
				/* translators: 1: field label, 2: what was typed, 3: largest accepted number */
				__( '%1$s: “%2$s” was not saved. Use a whole number from 0 to %3$s.', 'thirtydayhomes' ),
				$label,
				$typed,
				number_format_i18n( $max )
			);
		}

		update_post_meta( $id, $key, $integer ? (int) round( (float) $clean ) : round( (float) $clean ) );

		return '';
	}

	/** Store short text when given, clear it when emptied. */
	private static function store_text( int $id, string $key, string $value ): void {
		if ( '' === $value ) {
			delete_post_meta( $id, $key );
		} else {
			update_post_meta( $id, $key, $value );
		}
	}

	/**
	 * What still stands between this listing and review.
	 *
	 * The wizard's steps can be reached out of order — a bookmarked step 3,
	 * a draft started before a question existed — so "they went through
	 * every step" is not something submission can assume. This is the one
	 * list of what a listing must have, read by the review screen to show
	 * it and by submit() to enforce it.
	 *
	 * @return array<int,array{label:string,step:int}>
	 */
	public static function missing_for_submit( int $id ): array {

		$post    = get_post( $id );
		$missing = [];

		$need = static function ( bool $present, string $label, int $step ) use ( &$missing ): void {
			if ( ! $present ) {
				$missing[] = [ 'label' => $label, 'step' => $step ];
			}
		};

		$city = $post ? wp_get_object_terms( $id, Post_Types::TAX_CITY, [ 'fields' => 'ids' ] ) : [];

		$need( $post && '' !== trim( $post->post_title ), __( 'A title', 'thirtydayhomes' ), 1 );
		$need( '' !== (string) get_post_meta( $id, '_tdh_street_address', true ), __( 'The street address', 'thirtydayhomes' ), 1 );
		$need( is_array( $city ) && [] !== $city, __( 'The city', 'thirtydayhomes' ), 1 );
		$need( (bool) preg_match( '/^\d{5}$/', (string) get_post_meta( $id, '_tdh_zip', true ) ), __( 'A five-digit ZIP code', 'thirtydayhomes' ), 1 );
		$need( (float) get_post_meta( $id, '_tdh_price_monthly', true ) >= 1, __( 'The monthly rent', 'thirtydayhomes' ), 1 );
		$need( ! self::needs_phone( $id ), __( 'A mobile number for text messages', 'thirtydayhomes' ), 1 );

		$need(
			array_key_exists( (string) get_post_meta( $id, '_tdh_utilities_included', true ), self::choices( '_tdh_utilities_included' ) ),
			__( 'Whether utilities are included', 'thirtydayhomes' ),
			2
		);

		$need(
			array_key_exists( (string) get_post_meta( $id, '_tdh_pet_policy', true ), self::choices( '_tdh_pet_policy' ) ),
			__( 'The pet policy', 'thirtydayhomes' ),
			2
		);

		return $missing;
	}

	/**
	 * Every step handler needs the draft it is editing. When that draft
	 * cannot be opened, SAY SO.
	 *
	 * This used to be a bare redirect to step 1, and it is exactly how a
	 * landlord could choose a photo, press Continue, and be shown an empty
	 * form with nothing saved and nothing explained. Silence is the worst
	 * answer a form can give — it reads as a broken site.
	 *
	 * One message for "not yours" and "no longer editable" alike: which
	 * listing ids exist under which numbers is not information this screen
	 * hands out, and current_listing() deliberately cannot tell the two
	 * apart. The wording covers both honestly.
	 */
	private function lost_listing(): void {
		$this->fail(
			1,
			[
				__( 'That listing could not be opened for editing, so nothing was saved. It may already be published, or it may not be on your account — your dashboard lists every home you own.', 'thirtydayhomes' ),
			]
		);
	}

	private function save_features(): void {

		$listing = self::current_listing();

		if ( ! $listing ) {
			$this->lost_listing();
		}

		$stay = sanitize_key( wp_unslash( (string) ( $_POST['tdh_stay'] ?? '' ) ) );

		if ( array_key_exists( $stay, self::stay_options() ) ) {
			update_post_meta( $listing->ID, '_tdh_min_stay_days', (int) $stay );
		}

		// Available from and the unavailable periods: the good ones are
		// stored now, a refused one is named below and kept in its row.
		$availability = Availability::save_posted( (int) $listing->ID );

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

		$id       = $listing->ID;
		$problems = [];

		/*
		 * Utilities and pets are questions every renter asks, so they are
		 * required — but a missing answer must not throw away the stay and
		 * the amenities chosen on the same screen. Everything valid above
		 * and below is stored first; what is missing is named after.
		 */
		foreach ( [ 'tdh_utilities_included' => '_tdh_utilities_included', 'tdh_pets' => '_tdh_pet_policy' ] as $field => $key ) {
			$answer = sanitize_key( wp_unslash( (string) ( $_POST[ $field ] ?? '' ) ) );

			if ( array_key_exists( $answer, self::choices( $key ) ) ) {
				update_post_meta( $id, $key, $answer );
			}
		}

		if ( array_key_exists( 'tdh_utilities', $_POST ) ) {
			self::store_text( $id, '_tdh_utilities', mb_substr( sanitize_text_field( wp_unslash( (string) $_POST['tdh_utilities'] ) ), 0, self::MAX_DETAIL ) );
		}

		// A pet fee on a home that takes no pets is a contradiction the
		// public page would otherwise print. Both answers are on this one
		// screen, so the landlord sees the fee field go as they choose "Not
		// allowed" — nothing is removed behind their back.
		if ( 'no' === get_post_meta( $id, '_tdh_pet_policy', true ) ) {
			delete_post_meta( $id, '_tdh_pet_fee' );
		} else {
			$problems[] = self::store_number( $id, '_tdh_pet_fee', 'tdh_pet_fee', __( 'Pet fee', 'thirtydayhomes' ), self::MAX_FEE );
		}

		$problems[] = self::store_number( $id, '_tdh_sqft', 'tdh_sqft', __( 'Square feet', 'thirtydayhomes' ), self::MAX_SQFT, true );
		$problems[] = self::store_number( $id, '_tdh_rooms', 'tdh_rooms', __( 'Total rooms', 'thirtydayhomes' ), self::MAX_ROOMS, true );

		foreach ( [ 'tdh_parking' => '_tdh_parking', 'tdh_backyard' => '_tdh_backyard' ] as $field => $key ) {
			if ( array_key_exists( $field, $_POST ) ) {
				self::store_text( $id, $key, mb_substr( sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) ), 0, self::MAX_DETAIL ) );
			}
		}

		// Named first: the section sits at the top of the step.
		$problems = array_values( array_filter( array_merge( $availability, $problems ) ) );

		/*
		 * Unanswered questions stop Continue, not Back. Someone going back to
		 * fix step 1 has not finished step 2 yet, and refusing to let them
		 * leave until they answer it would be a trap. Submission still
		 * refuses the listing until both are answered.
		 */
		if ( ! self::going_back() ) {
			if ( ! array_key_exists( (string) get_post_meta( $id, '_tdh_utilities_included', true ), self::choices( '_tdh_utilities_included' ) ) ) {
				$problems[] = __( 'Choose whether utilities are included.', 'thirtydayhomes' );
			}

			if ( ! array_key_exists( (string) get_post_meta( $id, '_tdh_pet_policy', true ), self::choices( '_tdh_pet_policy' ) ) ) {
				$problems[] = __( 'Choose a pet policy — renters with a pet search by it.', 'thirtydayhomes' );
			}
		}

		if ( $problems ) {
			$problems[] = __( 'Everything else on this step is saved.', 'thirtydayhomes' );
			$this->fail( 2, $problems, $id );
		}

		$this->after_save( 2, $id );
	}

	/**
	 * Name the failure PHP hides.
	 *
	 * When a POST is bigger than post_max_size, PHP drops the ENTIRE
	 * request body: $_POST and $_FILES arrive empty, the handler below
	 * sees no action, and the page re-renders as if nothing was ever
	 * submitted. That is exactly how a landlord's photo upload "just does
	 * nothing" — a 10-photo batch from a phone camera sails past the
	 * limit. The data is unrecoverable at this point; the honest thing
	 * left to do is SAY SO, with the actual limit, instead of silence.
	 */
	private function catch_overflow(): void {

		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return;
		}

		// A dropped body means BOTH are empty. Any content in either means
		// the request arrived intact and the normal handlers deal with it.
		if ( ! empty( $_POST ) || ! empty( $_FILES ) || ! is_user_logged_in() ) {
			return;
		}

		$length = (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 );
		$limit  = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );

		if ( $length <= 0 || $limit <= 0 || $length <= $limit ) {
			return;
		}

		// Only on the wizard's own page — this runs on template_redirect
		// everywhere, and other forms' overflows are not this form's story.
		if ( 'add-listing' !== (string) get_post_meta( get_queried_object_id(), '_tdh_seed_key', true ) ) {
			return;
		}

		$this->fail(
			3,
			[
				sprintf(
					/* translators: %s: the server's upload limit, e.g. "64 MB" */
					__( 'That upload was bigger than the server accepts in one go (%s), so nothing from it was saved — not even the description. Please try again with fewer or smaller photos.', 'thirtydayhomes' ),
					size_format( $limit )
				),
			]
		);
	}

	private function save_photos(): void {

		$listing = self::current_listing();

		if ( ! $listing ) {
			$this->lost_listing();
		}

		$errors = [];
		$stored = 0;

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
		} elseif ( self::comparable( $description, true ) !== self::comparable( (string) $listing->post_content, true ) ) {
			/*
			 * Written only when it reads differently. An unchanged box must
			 * not rewrite a description — one written in the block editor
			 * would lose its formatting, and a live home would look edited.
			 */
			wp_update_post(
				[
					'ID'           => $listing->ID,
					'post_content' => $description,
				]
			);
		}

		// Descriptions first: they belong to photos that exist now, and a
		// photo removed below simply takes its description with it.
		if ( isset( $_POST['tdh_alt'] ) && is_array( $_POST['tdh_alt'] ) ) {
			Listing_Photos::save_alts( $listing->ID, $_POST['tdh_alt'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised per value inside.
		}

		/*
		 * Removals before uploads, so swapping the tenth photo for a better
		 * one works in a single visit instead of failing the count check.
		 * Only attachments parented to THIS listing can be removed — an id
		 * from someone else's media library is ignored, same ownership rule
		 * as everywhere else in the wizard.
		 */
		$removed = 0;

		foreach ( array_map( 'intval', (array) ( $_POST['tdh_remove'] ?? [] ) ) as $att_id ) {
			if ( Listing_Photos::belongs( $listing->ID, $att_id ) ) {
				wp_delete_attachment( $att_id, true );
				++$removed;
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

					/*
					 * PHP's own verdict comes first. When the upload failed
					 * before WordPress ever saw it — no temp directory, disk
					 * not writable, transfer cut off — media_handle_upload()
					 * reports something generic, and the landlord is left
					 * guessing at a problem only the server can fix. Name it.
					 */
					$code = (int) ( $files['error'][ $i ] ?? UPLOAD_ERR_OK );

					if ( UPLOAD_ERR_OK !== $code ) {
						$errors[] = sprintf(
							/* translators: 1: file name, 2: reason the upload failed */
							__( '“%1$s” did not upload: %2$s', 'thirtydayhomes' ),
							sanitize_text_field( (string) $files['name'][ $i ] ),
							self::upload_error( $code )
						);
						continue;
					}

					$name = sanitize_text_field( (string) $files['name'][ $i ] );

					// Upright, at most 2,000 px, and every embedded detail —
					// including the GPS position of the home — removed, before
					// WordPress keeps a copy. See Listing_Photos.
					$refusal = Listing_Photos::prepare_upload( (string) $files['tmp_name'][ $i ], $name );

					if ( '' !== $refusal ) {
						$errors[] = $refusal;
						continue;
					}

					// media_handle_upload() reads one $_FILES slot by key, so
					// each file from the multi-input is staged under its own.
					$_FILES['tdh_photo_one'] = [
						'name'     => $files['name'][ $i ],
						'type'     => $files['type'][ $i ],
						'tmp_name' => $files['tmp_name'][ $i ],
						'error'    => $files['error'][ $i ],
						'size'     => (int) @filesize( (string) $files['tmp_name'][ $i ] ), // phpcs:ignore WordPress.PHP.NoSilencedErrors -- the prepared size, not the uploaded one.
					];

					$att = media_handle_upload( 'tdh_photo_one', $listing->ID, [], $overrides );

					unset( $_FILES['tdh_photo_one'] );

					if ( is_wp_error( $att ) ) {
						$errors[] = sprintf(
							/* translators: 1: file name, 2: reason */
							__( '“%1$s” was not uploaded: %2$s', 'thirtydayhomes' ),
							$name,
							$att->get_error_message()
						);
						continue;
					}

					// New photos join at the end of the current order.
					Listing_Photos::save_order( $listing->ID, array_merge( array_diff( Listing_Photos::ordered( $listing->ID ), [ (int) $att ] ), [ (int) $att ] ) );
					++$stored;
				}
			}
		}

		/*
		 * Arranging: the arrow and "Make cover" buttons on each photo. They
		 * submit the whole step — so a description typed a moment ago is
		 * saved too — and bring the landlord straight back here to see the
		 * new order, rather than moving on to the review.
		 */
		$arranged = false;
		$move     = sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_photo_move'] ?? '' ) ) );

		// A press brings the landlord back here whether or not the photo
		// could move — a stale tab's arrow must not jump them to step 4.
		if ( preg_match( '/^(\d+):(up|down)$/', $move, $m ) ) {
			Listing_Photos::move( $listing->ID, (int) $m[1], $m[2] );
			$arranged = true;
		}

		if ( isset( $_POST['tdh_photo_cover'] ) ) {
			Listing_Photos::make_cover( $listing->ID, (int) $_POST['tdh_photo_cover'] );
			$arranged = true;
		}

		// The cover is always the first photo. A removed cover hands the
		// role to the next one; removing the last photo leaves no cover.
		if ( $removed || $stored || $arranged || Listing_Photos::ordered( $listing->ID ) ) {
			Listing_Photos::sync_cover( $listing->ID );
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

		/*
		 * One press: Continue uploads what was chosen AND moves on.
		 *
		 * An earlier version stopped here to show the photographs, and a
		 * still earlier one had a separate Upload button. Both were ways of
		 * proving the upload had worked — but the proof now arrives without
		 * costing a click: the browser draws the chosen photographs the
		 * moment they are picked, and the review step counts them back and
		 * says how many were added.
		 *
		 * So the extra stop earned nothing and cost a press on every
		 * listing. The count travels forward instead.
		 */
		$extra = $stored > 0 ? [ 'added' => $stored ] : [];

		if ( $arranged ) {
			$this->go( 3, $listing->ID, false, $extra + [ 'arranged' => 1 ] );
		}

		$this->after_save( 3, $listing->ID, $extra );
	}

	/**
	 * PHP's upload error codes, in words a landlord can act on.
	 *
	 * Half of these are server faults, not user faults, and saying which
	 * is the difference between "try a smaller photo" (useless when the
	 * disk is full) and a message whoever maintains the site can act on.
	 */
	private static function upload_error( int $code ): string {

		$reasons = [
			UPLOAD_ERR_INI_SIZE   => __( 'it is larger than this server accepts.', 'thirtydayhomes' ),
			UPLOAD_ERR_FORM_SIZE  => __( 'it is larger than this form accepts.', 'thirtydayhomes' ),
			UPLOAD_ERR_PARTIAL    => __( 'the upload was interrupted. Please try again.', 'thirtydayhomes' ),
			UPLOAD_ERR_NO_FILE    => __( 'no file arrived with the form.', 'thirtydayhomes' ),
			UPLOAD_ERR_NO_TMP_DIR => __( 'the server has no temporary folder to receive uploads. This is a hosting setting, not something you can fix — please tell us.', 'thirtydayhomes' ),
			UPLOAD_ERR_CANT_WRITE => __( 'the server could not write the file to disk. This is a hosting setting, not something you can fix — please tell us.', 'thirtydayhomes' ),
			UPLOAD_ERR_EXTENSION  => __( 'a server extension blocked it. Please tell us so we can look.', 'thirtydayhomes' ),
		];

		/* translators: %d: PHP's numeric upload error code */
		return $reasons[ $code ] ?? sprintf( __( 'the server refused it (error %d).', 'thirtydayhomes' ), $code );
	}

	/**
	 * The listing's photos in display order, cover first — attachment ids.
	 *
	 * @return int[]
	 */
	public static function photos( int $listing_id ): array {
		return Listing_Photos::ordered( $listing_id );
	}

	private function submit(): void {

		$listing = self::current_listing();

		if ( ! $listing ) {
			$this->lost_listing();
		}

		/*
		 * A live or paused home has nothing to submit: its edits save step by
		 * step, and the ones that need a review ask on the step. Step 4 shows
		 * no submit button for these; a stale page that posts one anyway must
		 * not unpublish a live home.
		 */
		if ( ! Accounts::is_staff() && in_array( $listing->post_status, [ 'publish', Statuses::PAUSED ], true ) ) {
			wp_safe_redirect( add_query_arg( 'view', 'listings', Accounts::url( 'account' ) ) );
			exit;
		}

		// Anything a listing must have, checked before the Fair Housing box:
		// the review screen lists the gaps with a link to each step, so the
		// message only has to point there.
		if ( self::missing_for_submit( $listing->ID ) ) {
			$this->fail( 4, [ __( 'This listing is not ready for review yet. The list below shows what is missing.', 'thirtydayhomes' ) ], $listing->ID );
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

		/*
		 * And the membership itself (E1): a past-due landlord keeps their
		 * allowance, so the quota alone would let a home into a queue where
		 * staff could not approve it. The draft is kept either way.
		 */
		if ( ! Accounts::is_staff() && ( Membership::quota( $user ) < 1 || ! Listing_Actions::owner_can_publish( $user ) ) ) {
			$this->fail( 4, [ __( 'Your membership is not active, so the listing stays as a draft. It submits from here as soon as a plan is.', 'thirtydayhomes' ) ], $listing->ID );
		}

		// F1: an unconfirmed address cannot send a home to review. Draft kept.
		if ( ! Email_Verification::is_verified( $user ) ) {
			$this->fail( 4, [ __( 'Confirm your email address first — click the link we emailed you. The listing stays as a draft until then.', 'thirtydayhomes' ) ], $listing->ID );
		}

		// Under the key the schema declares, so the wp-admin panel shows it.
		// It was written as `_tdh_fair_housing_ack`, which no screen read.
		update_post_meta( $listing->ID, '_tdh_fair_housing_ack_at', current_time( 'mysql' ) );

		// Coming back after "Request changes" — staff are told it is a
		// resubmission, not a new home.
		$resubmitted = Statuses::REJECTED === $listing->post_status;

		if ( ! Accounts::is_staff() || 'draft' === $listing->post_status ) {
			// "In review since …" on the landlord's dashboard reads this.
			update_post_meta( $listing->ID, '_tdh_submitted_at', current_time( 'mysql' ) );
			// An ordinary submission: whatever edit once sent it here is history.
			delete_post_meta( $listing->ID, '_tdh_reviewing_edits' );
		}

		wp_update_post(
			[
				'ID'          => $listing->ID,
				// A staff edit must not silently unpublish a live home or turn a
				// rejected record back into a pending submission. Landlords still
				// enter the normal review queue.
				'post_status' => Accounts::is_staff() && 'draft' !== $listing->post_status
					? $listing->post_status
					: 'pending',
			]
		);

		/**
		 * Fires when a landlord submits a listing for review.
		 *
		 * TDH\Listing_Actions emails staff from here.
		 *
		 * @param int  $listing_id
		 * @param bool $resubmitted True when it returns after changes were requested.
		 */
		if ( ! Accounts::is_staff() ) {
			do_action( 'tdh_listing_submitted', $listing->ID, $resubmitted );
		}

		$destination = Accounts::is_staff()
			? add_query_arg( 'view', 'listings', Accounts::url( 'account' ) )
			: Accounts::url( 'account' );

		wp_safe_redirect( add_query_arg( 'tdh_submitted', '1', $destination ) );
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

	/**
	 * @param array<string,int|string> $extra Query arguments to carry along.
	 */
	private function go( int $step, int $listing, bool $saved = false, array $extra = [] ): void {

		/*
		 * A confirmed edit to a live home ends on My listings, where the row
		 * now says the home is in review — not on the next step of a home
		 * that has just come off the site.
		 */
		$this->finish_live_edit( $listing );

		if ( $this->went_to_review && ! isset( $extra['failed'] ) ) {
			wp_safe_redirect(
				add_query_arg(
					[
						'view'     => 'listings',
						'tdh_done' => 'in_review',
						'tdh_home' => $listing,
					],
					Accounts::url( 'account' )
				)
			);
			exit;
		}

		unset( $extra['failed'] );

		$url = self::url( $step, $listing );

		// A step opened from the review stays in that mode through an error
		// or a Back, so "Save and return to review" is still offered.
		if ( self::returning_to_review() && $step < 4 ) {
			$url = add_query_arg( 'review', '1', $url );
		}

		if ( $saved ) {
			$url = add_query_arg( 'saved', '1', $url );
		}

		if ( $extra ) {
			$url = add_query_arg( $extra, $url );
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

		$listing = $listing ?: (int) ( self::current_listing()->ID ?? 0 );

		/*
		 * A live edit that stored SOME of its material changes before the
		 * problem still goes to review — a live page showing half an edit is
		 * worse than a home briefly off the site. The step is shown again
		 * with the problem named, and the review said.
		 */
		$this->finish_live_edit( $listing );

		if ( $this->went_to_review ) {
			$errors[] = __( 'The rest of your changes are saved and the home is now in review. Fix the problem above and save again — it comes back on the site once the changes are approved.', 'thirtydayhomes' );
		}

		set_transient( 'tdh_lform_' . get_current_user_id(), $errors, 5 * MINUTE_IN_SECONDS );

		$this->go( $step, $listing, false, [ 'failed' => 1 ] );
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
