<?php
/**
 * Addresses become coordinates — and when they cannot, staff are told.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * ─── WHEN AN ADDRESS IS LOOKED UP ──────────────────────────────────────────
 *
 * Whenever it really changes, from wherever it changes: the listing form,
 * the Facilities screen, the wp-admin boxes, an import. The address fields
 * are watched as they are written, and the lookups run once, at the end of
 * the request — after every field of the save is in, and never in the way
 * of the save itself. A lookup that fails never stops anything from saving.
 *
 * An address that did not change is not looked up again (a fingerprint of
 * the last address looked up is kept), and a home still waiting for a
 * lookup — the service was down, or no key yet — is tried again on its next
 * save, or all at once with `wp tdh geocode --missing`.
 *
 * ─── THE FOUR STATES ───────────────────────────────────────────────────────
 *
 *   found          Google placed the building                  (status ok)
 *   set by hand    staff typed the coordinates, or they came   (status manual,
 *                  from before lookups existed                  or legacy)
 *   not found      no result, or only the street / ZIP / town  (status failed)
 *   not checked    no key, the service was down, or waiting    (no coordinates)
 *
 * Only "not found" stops approval: the address itself is the problem, and a
 * live home measured from nowhere would show no distances. "Not checked" is
 * the site's problem, not the landlord's, so it never holds a home back.
 *
 * ─── WHAT IS STORED (all private: never in REST, never in public markup) ──
 *
 *   _tdh_lat, _tdh_lng        the point
 *   _tdh_geocode_status       '' | ok | failed | manual
 *   _tdh_geocode_reason       why it is not found / not checked (a code)
 *   _tdh_geocode_address      fingerprint of the address last looked up
 *   _tdh_geocode_point        the point the lookup wrote, so a typed point
 *                             can be told apart from a re-saved one
 */
final class Geocoder {

	public const NONCE = 'tdh_geocode';

	public const OK     = 'ok';
	public const FAILED = 'failed';
	public const MANUAL = 'manual';

	/** Seconds to leave the service alone after it says "slow down" or times out. */
	public const PAUSE = 60;

	private const ADDRESS_KEYS = [ '_tdh_street_address', '_tdh_zip', '_tdh_state' ];
	private const POINT_KEYS   = [ '_tdh_lat', '_tdh_lng' ];

	/** @var array<int,true> Homes and facilities whose address changed this request. */
	private static array $changed = [];

	/** @var array<int,true> ...whose coordinates were typed this request. */
	private static array $typed = [];

	/** @var array<int,true> ...saved while still waiting for a lookup. */
	private static array $waiting = [];

	/** Set while this class writes, so its own writes are not "typed". */
	private static bool $writing = false;

	public function register(): void {
		add_action( 'added_post_meta', [ $this, 'watch_added' ], 10, 3 );
		add_action( 'updated_post_meta', [ $this, 'watch_updated' ], 10, 3 );
		add_filter( 'update_post_metadata', [ $this, 'watch_point' ], 10, 4 );
		add_action( 'deleted_post_meta', [ $this, 'watch_added' ], 10, 3 );
		add_action( 'set_object_terms', [ $this, 'watch_terms' ], 10, 4 );
		add_action( 'save_post_' . Post_Types::LISTING, [ $this, 'watch_save' ] );
		add_action( 'save_post_' . Post_Types::FACILITY, [ $this, 'watch_save' ] );
		add_action( 'shutdown', [ self::class, 'flush' ], 1 );
		add_action( 'template_redirect', [ $this, 'handle' ] );
	}

	/* ---------------------------------------------------------------------
	 * The provider
	 * ------------------------------------------------------------------ */

	/**
	 * Who answers lookups, or null when nobody can (no key).
	 */
	public static function provider(): ?Geocode_Provider {

		/*
		 * A test run never talks to Google. verify.bat and CI set
		 * TDH_OFFLINE, and the real key — which is installed on the
		 * developer's machine — is ignored while it is set. A suite that
		 * needs an answer stands in its own provider through the filter
		 * below, which still works, so this switches off the *live*
		 * service rather than the geocoder.
		 *
		 * Without it, any suite that put a postcode in the search box
		 * would spend the client's Google quota, and its results would
		 * change with the weather.
		 */
		$key = getenv( 'TDH_OFFLINE' )
			? ''
			: ( defined( 'TDH_MAPS_SERVER_KEY' ) ? trim( (string) constant( 'TDH_MAPS_SERVER_KEY' ) ) : '' );

		$provider = '' !== $key ? new Geocode_Google( $key ) : null;

		/**
		 * Filter the geocoding provider. The test suite stands in a fake.
		 *
		 * @param Geocode_Provider|null $provider
		 */
		$provider = apply_filters( 'tdh_geocode_provider', $provider );

		return $provider instanceof Geocode_Provider ? $provider : null;
	}

	/** Can addresses be looked up at all on this site? */
	public static function configured(): bool {
		return null !== self::provider();
	}

	/* ---------------------------------------------------------------------
	 * Reading a record
	 * ------------------------------------------------------------------ */

	/**
	 * The stored point, or null. Empty, non-numeric and 0,0 are not a point:
	 * a form that saved an empty box used to store 0, and a distance measured
	 * from the Gulf of Guinea is worse than none.
	 *
	 * @return array{lat:float,lng:float}|null
	 */
	public static function coordinates( int $id ): ?array {

		$lat = get_post_meta( $id, '_tdh_lat', true );
		$lng = get_post_meta( $id, '_tdh_lng', true );

		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return null;
		}

		$lat = (float) $lat;
		$lng = (float) $lng;

		if ( ( 0.0 === $lat && 0.0 === $lng ) || abs( $lat ) > 90 || abs( $lng ) > 180 ) {
			return null;
		}

		return [ 'lat' => $lat, 'lng' => $lng ];
	}

	/**
	 * found | manual | failed | pending — what the portal shows.
	 */
	public static function state( int $id ): string {

		$status = (string) get_post_meta( $id, '_tdh_geocode_status', true );

		if ( self::FAILED === $status && null === self::coordinates( $id ) ) {
			return 'failed';
		}

		if ( null === self::coordinates( $id ) ) {
			return 'pending';
		}

		// Points from before lookups existed were typed or imported by hand.
		return self::OK === $status ? 'found' : 'manual';
	}

	/** Does this home's location stop it being approved? */
	public static function blocks_approval( int $id ): bool {
		return 'failed' === self::state( $id );
	}

	/**
	 * The state in words, for a chip.
	 *
	 * @return array{label:string,badge:string}
	 */
	public static function chip( string $state ): array {
		return match ( $state ) {
			'found'  => [ 'label' => __( 'Location found', 'thirtydayhomes' ), 'badge' => 'live' ],
			'manual' => [ 'label' => __( 'Location set by hand', 'thirtydayhomes' ), 'badge' => 'live' ],
			'failed' => [ 'label' => __( 'Location not found', 'thirtydayhomes' ), 'badge' => 'rejected' ],
			default  => [ 'label' => __( 'Location not checked yet', 'thirtydayhomes' ), 'badge' => 'pending' ],
		};
	}

	/**
	 * Why a record has no location, in words staff can act on.
	 */
	public static function reason( int $id ): string {

		$state  = self::state( $id );
		$reason = (string) get_post_meta( $id, '_tdh_geocode_reason', true );

		if ( 'failed' === $state ) {
			return 'imprecise' === $reason
				? __( 'Google only matched the street or the ZIP code, not the building. Check the street number, or set the location by hand.', 'thirtydayhomes' )
				: __( 'Google couldn’t find this address. Check the street and ZIP code, or set the location by hand.', 'thirtydayhomes' );
		}

		if ( 'pending' !== $state ) {
			return '';
		}

		if ( '' === self::address( $id ) ) {
			return __( 'There is no street address to look up yet.', 'thirtydayhomes' );
		}

		if ( ! self::configured() ) {
			return __( 'Addresses aren’t looked up yet because the Google Maps key isn’t set up. Distances won’t show until it has a location.', 'thirtydayhomes' );
		}

		// No stored reason means nothing was ever asked — which is the normal
		// state of every record saved before the key existed. Saying "the map
		// service didn’t answer" there would blame a failure that never
		// happened, so each case names what actually did or did not occur and
		// points at the control that exists ("Set location" opens the panel
		// holding "Look up the address again").
		return match ( $reason ) {
			'denied' => __( 'Google refused the maps key. Distances won’t show until it has a location.', 'thirtydayhomes' ),
			'limit'  => __( 'Google asked us to slow down. It is tried again on the next save, or from “Set location” in a minute.', 'thirtydayhomes' ),
			''       => __( 'Open “Set location” to look it up now, or set the point by hand.', 'thirtydayhomes' ),
			default  => __( 'The map service didn’t answer. It is tried again on the next save, or from “Set location”.', 'thirtydayhomes' ),
		};
	}

	/**
	 * The address as one line, the way a person writes it:
	 * "412 Stanton Ave, Pittsburgh, PA 15209". '' without a street.
	 */
	public static function address( int $id ): string {

		$street = trim( (string) get_post_meta( $id, '_tdh_street_address', true ) );

		if ( '' === $street ) {
			return '';
		}

		$city  = wp_get_object_terms( $id, Post_Types::TAX_CITY, [ 'fields' => 'names' ] );
		$city  = is_array( $city ) && isset( $city[0] ) ? (string) $city[0] : '';
		$state = trim( (string) get_post_meta( $id, '_tdh_state', true ) );
		$state = '' !== $state ? $state : 'PA';
		$zip   = trim( (string) get_post_meta( $id, '_tdh_zip', true ) );

		return implode( ', ', array_filter( [ $street, $city, trim( $state . ' ' . $zip ) ] ) );
	}

	/** An address reduced to what makes it a different address. */
	private static function fingerprint( string $address ): string {
		return md5( strtolower( (string) preg_replace( '/\s+/', ' ', trim( $address ) ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Watching saves
	 * ------------------------------------------------------------------ */

	private static function tracked( int $post_id ): bool {
		return in_array( get_post_type( $post_id ), [ Post_Types::LISTING, Post_Types::FACILITY ], true );
	}

	/**
	 * An address field or a coordinate that appears or is cleared.
	 *
	 * @param int|int[] $meta_id
	 */
	public function watch_added( $meta_id, int $post_id, string $key ): void {

		if ( self::$writing || ! self::tracked( $post_id ) ) {
			return;
		}

		if ( in_array( $key, self::ADDRESS_KEYS, true ) ) {
			self::$changed[ $post_id ] = true;
		} elseif ( in_array( $key, self::POINT_KEYS, true ) ) {
			self::$typed[ $post_id ] = true;
		}
	}

	/**
	 * An address field whose text changed (WordPress only reports a real
	 * change of text).
	 *
	 * @param int $meta_id
	 */
	public function watch_updated( $meta_id, int $post_id, string $key ): void {
		if ( ! self::$writing && in_array( $key, self::ADDRESS_KEYS, true ) && self::tracked( $post_id ) ) {
			self::$changed[ $post_id ] = true;
		}
	}

	/**
	 * A coordinate about to be written. Only a different NUMBER counts as
	 * typed: forms post the stored point back on every save, and WordPress
	 * reports "40.4418" written over "40.4418" as an update.
	 *
	 * @param mixed $check      Null to let the update go ahead.
	 * @param mixed $meta_value The new value.
	 * @return mixed The unchanged $check.
	 */
	public function watch_point( $check, $post_id, $key, $meta_value ) {

		if ( null !== $check || self::$writing || ! in_array( $key, self::POINT_KEYS, true ) || ! self::tracked( (int) $post_id ) ) {
			return $check;
		}

		$old = get_post_meta( (int) $post_id, (string) $key, true );

		if ( ! is_numeric( $old ) || ! is_numeric( $meta_value ) || abs( (float) $old - (float) $meta_value ) > 1e-9 ) {
			self::$typed[ (int) $post_id ] = true;
		}

		return $check;
	}

	/**
	 * The city is a term, not meta.
	 *
	 * @param int[] $terms
	 * @param int[] $tt_ids
	 */
	public function watch_terms( int $post_id, $terms, $tt_ids, string $taxonomy ): void {
		if ( Post_Types::TAX_CITY === $taxonomy && ! self::$writing && self::tracked( $post_id ) ) {
			self::$changed[ $post_id ] = true;
		}
	}

	/**
	 * Any save of a record still waiting for a lookup tries again.
	 */
	public function watch_save( int $post_id ): void {
		if ( ! wp_is_post_revision( $post_id ) && 'pending' === self::state( $post_id ) ) {
			self::$waiting[ $post_id ] = true;
		}
	}

	/**
	 * Run the lookups this request asked for. On shutdown, and called
	 * directly where the next words on screen depend on the answer.
	 */
	public static function flush(): void {

		$changed = self::$changed;
		$typed   = self::$typed;
		$waiting = self::$waiting;

		self::$changed = [];
		self::$typed   = [];
		self::$waiting = [];

		foreach ( array_keys( $changed + $typed + $waiting ) as $id ) {

			$post = get_post( $id );

			if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status || ! self::tracked( $id ) ) {
				continue;
			}

			// Coordinates typed by hand win over the address — unless they
			// were only saved again unchanged, or cleared to ask for a lookup.
			if ( isset( $typed[ $id ] ) ) {
				$point = self::coordinates( $id );

				if ( $point && self::point_key( $point ) !== (string) get_post_meta( $id, '_tdh_geocode_point', true ) ) {
					self::mark_manual( $id );
					continue;
				}

				if ( ! $point ) {
					self::geocode( $id, 'changed' );
					continue;
				}
			}

			if ( isset( $changed[ $id ] ) ) {
				self::geocode( $id, 'changed' );
			} elseif ( isset( $waiting[ $id ] ) ) {
				self::geocode( $id, 'retry' );
			}
		}
	}

	/** "40.4406000,-79.9959000": equal points, equal strings. */
	private static function point_key( array $point ): string {
		return sprintf( '%.7F,%.7F', $point['lat'], $point['lng'] );
	}

	/* ---------------------------------------------------------------------
	 * Looking up
	 * ------------------------------------------------------------------ */

	/**
	 * Look up one record's address and store what came back.
	 *
	 * @param string $why 'changed' (the address changed: old coordinates are
	 *                    stale), 'retry' (waiting for a lookup) or 'force'
	 *                    (asked for: Try again, or the WP-CLI command).
	 * @return string The state afterwards (see state()).
	 */
	public static function geocode( int $id, string $why = 'force' ): string {

		self::$asked = false;

		$state = self::lookup_and_store( $id, $why );

		/**
		 * Fires after an address lookup has run (or been decided against).
		 *
		 * @param int    $id     The listing or facility.
		 * @param string $state  found | manual | failed | pending.
		 * @param string $reason The stored reason code ('' when found).
		 * @param bool   $asked  Whether the map service was actually asked.
		 * @param string $why    changed | retry | force.
		 */
		do_action( 'tdh_geocoded', $id, $state, (string) get_post_meta( $id, '_tdh_geocode_reason', true ), self::$asked, $why );

		return $state;
	}

	/** Whether the current geocode() call reached the provider. */
	private static bool $asked = false;

	/** The lookup itself; geocode() wraps it so every exit fires one action. */
	private static function lookup_and_store( int $id, string $why ): string {

		$address = self::address( $id );

		if ( '' === $address ) {
			self::write( $id, [ '_tdh_geocode_reason' => 'no_address' ] );
			return self::state( $id );
		}

		$print   = self::fingerprint( $address );
		$same    = $print === (string) get_post_meta( $id, '_tdh_geocode_address', true );
		$settled = in_array( self::state( $id ), [ 'found', 'manual' ], true );

		// Nothing new to ask: the same address, already placed.
		if ( 'force' !== $why && $same && $settled ) {
			return self::state( $id );
		}

		$provider = self::provider();

		if ( null === $provider ) {
			self::unanswered( $id, 'no_key', 'changed' === $why && ! $same );
			return self::state( $id );
		}

		// Told to slow down a moment ago: do not ask again yet.
		if ( get_transient( 'tdh_geocode_pause' ) ) {
			self::unanswered( $id, 'limit', 'changed' === $why && ! $same );
			return self::state( $id );
		}

		self::$asked = true;

		$answer = $provider->lookup( $address );
		$status = (string) ( $answer['status'] ?? 'error' );

		if ( 'ok' === $status && isset( $answer['lat'], $answer['lng'] ) ) {
			$point = [ 'lat' => (float) $answer['lat'], 'lng' => (float) $answer['lng'] ];

			self::write(
				$id,
				[
					'_tdh_lat'             => $point['lat'],
					'_tdh_lng'             => $point['lng'],
					'_tdh_geocode_status'  => self::OK,
					'_tdh_geocode_address' => $print,
					'_tdh_geocode_point'   => self::point_key( $point ),
					'_tdh_geocode_reason'  => null,
				]
			);
			delete_option( 'tdh_geocode_problem' );
			return self::state( $id );
		}

		if ( in_array( $status, [ 'not_found', 'imprecise' ], true ) ) {

			// A point typed by hand for this very address outlives a lookup
			// that was only asked for again.
			if ( 'force' === $why && $settled && $same ) {
				self::write( $id, [ '_tdh_geocode_reason' => $status ] );
				return self::state( $id );
			}

			self::write(
				$id,
				[
					'_tdh_lat'             => null,
					'_tdh_lng'             => null,
					'_tdh_geocode_status'  => self::FAILED,
					'_tdh_geocode_address' => $print,
					'_tdh_geocode_point'   => null,
					'_tdh_geocode_reason'  => $status,
				]
			);
			return self::state( $id );
		}

		$error = (string) ( $answer['error'] ?? 'unknown' );

		if ( in_array( $error, [ 'limit', 'network' ], true ) ) {
			set_transient( 'tdh_geocode_pause', 1, self::PAUSE );
		}

		if ( in_array( $error, [ 'denied', 'limit' ], true ) ) {
			update_option( 'tdh_geocode_problem', [ 'error' => $error, 'at' => time() ], false );
		}

		self::unanswered( $id, $error, 'changed' === $why && ! $same );
		return self::state( $id );
	}

	/**
	 * No answer. The record waits ("not checked yet"); if its address
	 * changed, the old point no longer belongs to it.
	 */
	private static function unanswered( int $id, string $reason, bool $drop ): void {

		$values = [ '_tdh_geocode_reason' => $reason ];

		if ( $drop ) {
			$values += [
				'_tdh_lat'             => null,
				'_tdh_lng'             => null,
				'_tdh_geocode_status'  => null,
				'_tdh_geocode_address' => null,
				'_tdh_geocode_point'   => null,
			];
		} elseif ( self::FAILED === (string) get_post_meta( $id, '_tdh_geocode_status', true ) && null === self::coordinates( $id ) ) {
			// A retry that got no answer is "not checked", not "not found".
			$values['_tdh_geocode_status'] = null;
		}

		self::write( $id, $values );
	}

	/** Coordinates typed by staff: kept until the address changes. */
	public static function mark_manual( int $id ): void {

		$point = self::coordinates( $id );

		if ( null === $point ) {
			return;
		}

		self::write(
			$id,
			[
				'_tdh_lat'             => $point['lat'],
				'_tdh_lng'             => $point['lng'],
				'_tdh_geocode_status'  => self::MANUAL,
				'_tdh_geocode_address' => '' !== self::address( $id ) ? self::fingerprint( self::address( $id ) ) : null,
				'_tdh_geocode_point'   => null,
				'_tdh_geocode_reason'  => null,
			]
		);
	}

	/**
	 * Write (or, for null, delete) meta without it counting as typed, then
	 * drop the distance answers that depended on the old point.
	 *
	 * @param array<string,mixed> $values
	 */
	private static function write( int $id, array $values ): void {

		self::$writing = true;

		try {
			foreach ( $values as $key => $value ) {
				if ( null === $value ) {
					delete_post_meta( $id, $key );
				} else {
					update_post_meta( $id, $key, $value );
				}
			}
		} finally {
			self::$writing = false;
		}

		if ( array_intersect( array_keys( $values ), self::POINT_KEYS ) ) {
			if ( Post_Types::FACILITY === get_post_type( $id ) ) {
				( new Proximity() )->clear_all_caches();
			} else {
				delete_post_meta( $id, '_tdh_nearest_facility' );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Coordinates typed by staff
	 * ------------------------------------------------------------------ */

	/**
	 * Read coordinates the way people copy them: "40.4406, -79.9959",
	 * "40.4406 -79.9959", "(40.4406°, -79.9959°)". Null when it is not a
	 * point on Earth, or is the empty 0,0.
	 *
	 * @return array{lat:float,lng:float}|null
	 */
	public static function parse_point( string $typed ): ?array {

		$clean = str_replace( [ '°', '(', ')', '[', ']' ], ' ', $typed );

		if ( ! preg_match( '/^\s*(-?\d{1,3}(?:\.\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $clean, $m ) ) {
			return null;
		}

		$lat = (float) $m[1];
		$lng = (float) $m[2];

		if ( abs( $lat ) > 90 || abs( $lng ) > 180 || ( 0.0 === $lat && 0.0 === $lng ) ) {
			return null;
		}

		return [ 'lat' => $lat, 'lng' => $lng ];
	}

	/**
	 * Staff save a home's location from the Listings screen, or ask for the
	 * address to be looked up again.
	 */
	public function handle(): void {

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below, after the capability.
		$action = sanitize_key( wp_unslash( (string) ( $_POST['tdh_action'] ?? '' ) ) );

		if ( ! in_array( $action, [ 'listing_location', 'listing_geocode' ], true ) ) {
			return;
		}

		if ( ! Accounts::is_staff() ) {
			return;
		}

		$id = (int) ( $_POST['tdh_listing'] ?? 0 );

		if ( ! isset( $_POST['tdh_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tdh_nonce'] ) ), self::NONCE ) ) {
			self::back( 'expired', $id );
		}

		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || Post_Types::LISTING !== $post->post_type || 'trash' === $post->post_status ) {
			self::back( 'missing', 0 );
		}

		if ( 'listing_geocode' === $action ) {

			if ( ! self::configured() ) {
				self::back( 'no_key', $id );
			}

			// Asked for, so asked now — not held back by the short pause.
			delete_transient( 'tdh_geocode_pause' );
			$state = self::geocode( $id, 'force' );

			self::back( 'found' === $state ? 'found' : ( 'failed' === $state ? 'not_found' : 'unanswered' ), $id );
		}

		$typed = trim( sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_point'] ?? '' ) ) ) );
		$point = self::parse_point( $typed );
		// phpcs:enable

		if ( null === $point ) {
			set_transient( 'tdh_geocode_typed_' . get_current_user_id(), [ 'listing' => $id, 'typed' => $typed ], 5 * MINUTE_IN_SECONDS );
			self::back( '' === $typed ? 'empty' : 'invalid', $id, true );
		}

		self::write( $id, [ '_tdh_lat' => $point['lat'], '_tdh_lng' => $point['lng'] ] );
		self::mark_manual( $id );

		self::back( 'saved', $id );
	}

	/** What was typed into a refused location form, once. */
	public static function take_typed( int $id ): string {

		$key    = 'tdh_geocode_typed_' . get_current_user_id();
		$stored = get_transient( $key );

		if ( ! is_array( $stored ) || (int) ( $stored['listing'] ?? 0 ) !== $id ) {
			return '';
		}

		delete_transient( $key );

		return (string) ( $stored['typed'] ?? '' );
	}

	/**
	 * Back to the Listings screen, at the home, with the outcome flagged.
	 */
	private static function back( string $flag, int $id, bool $reopen = false ): void {

		$args = [ 'view' => 'listings', 'tdh_located' => $flag ];

		if ( $id > 0 ) {
			$args['tdh_home'] = $id;
		}

		if ( $reopen ) {
			$args['locate'] = $id;
		}

		$url = add_query_arg( $args, Accounts::url( 'account' ) );

		wp_safe_redirect( $id > 0 ? $url . '#' . self::anchor( $id ) : $url );
		exit;
	}

	/** Where a home's row is: the approval queue while it waits, the list otherwise. */
	public static function anchor( int $id ): string {
		return ( 'pending' === get_post_status( $id ) ? 'queue-' : 'row-' ) . $id;
	}

	/**
	 * The words for an outcome flag.
	 *
	 * @return array{type:string,text:string}|null
	 */
	public static function notice( string $flag, int $id ): ?array {

		$title = $id > 0 && Post_Types::LISTING === get_post_type( $id ) ? get_the_title( $id ) : '';
		/* translators: %s: listing title */
		$name = '' !== $title ? sprintf( __( '“%s”', 'thirtydayhomes' ), $title ) : __( 'The listing', 'thirtydayhomes' );

		return match ( $flag ) {
			/* translators: %s: listing name */
			'saved'      => [ 'type' => 'success', 'text' => sprintf( __( 'Location saved for %s. Distances to hospitals use it from now on.', 'thirtydayhomes' ), $name ) ],
			/* translators: %s: listing name */
			'found'      => [ 'type' => 'success', 'text' => sprintf( __( 'Location found for %s.', 'thirtydayhomes' ), $name ) ],
			/* translators: %s: listing name */
			'not_found'  => [ 'type' => 'error', 'text' => sprintf( __( 'Google still couldn’t place %s. Check the address, or set the location by hand.', 'thirtydayhomes' ), $name ) ],
			'unanswered' => [ 'type' => 'error', 'text' => __( 'The map service didn’t answer. Nothing changed — try again in a minute.', 'thirtydayhomes' ) ],
			'no_key'     => [ 'type' => 'error', 'text' => __( 'Addresses can’t be looked up yet: the Google Maps key isn’t set up. Set the location by hand instead.', 'thirtydayhomes' ) ],
			'empty'      => [ 'type' => 'error', 'text' => __( 'Paste the coordinates first, like 40.4406, -79.9959. Nothing was saved.', 'thirtydayhomes' ) ],
			'invalid'    => [ 'type' => 'error', 'text' => __( 'Those aren’t coordinates we can read. Paste them as two numbers, like 40.4406, -79.9959. Nothing was saved.', 'thirtydayhomes' ) ],
			'expired'    => [ 'type' => 'error', 'text' => __( 'That page was open too long, so nothing was saved. Please try again.', 'thirtydayhomes' ) ],
			'missing'    => [ 'type' => 'error', 'text' => __( 'That listing no longer exists.', 'thirtydayhomes' ) ],
			default      => null,
		};
	}

	/**
	 * A problem with the maps service itself, for a notice at the top of the
	 * staff screens: no key, a refused key, or a limit reached.
	 */
	public static function service_notice(): string {

		if ( ! self::configured() ) {
			return __( 'Map locations are off: the Google Maps key isn’t set up yet. Homes can still be saved and approved. Until the key is added, set a home’s location by hand with “Set location”.', 'thirtydayhomes' );
		}

		$problem = get_option( 'tdh_geocode_problem' );
		$error   = is_array( $problem ) ? (string) ( $problem['error'] ?? '' ) : '';

		return match ( $error ) {
			'denied' => __( 'Google refused the maps key on the last lookup. Check that the Geocoding API is turned on for the key and that its restrictions allow this server.', 'thirtydayhomes' ),
			'limit'  => __( 'Google’s lookup limit was reached on the last lookup. Homes waiting for a location are tried again on their next save.', 'thirtydayhomes' ),
			default  => '',
		};
	}

	/** A Google Maps search for this address, for staff finding a point by hand. */
	public static function maps_search_url( int $id ): string {
		$address = self::address( $id );
		return '' !== $address ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $address ) : 'https://www.google.com/maps';
	}
}
