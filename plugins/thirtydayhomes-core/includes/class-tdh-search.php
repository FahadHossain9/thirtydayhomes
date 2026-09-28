<?php
/**
 * Search over the listing archive: the keyword, the filters and the sort.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the search page into a search.
 *
 * ─── WHAT LIVES HERE ───────────────────────────────────────────────────────
 *
 * Everything that decides WHICH homes a renter sees and in WHAT ORDER: the
 * keyword (name, neighborhood, city, ZIP), the filters (price range,
 * bedrooms, bathrooms, property type, pet policy), the stay dates and the
 * sort. All of it is read from the URL, so a search can be shared,
 * bookmarked, paged and walked back with the Back button, and none of it
 * depends on the theme.
 *
 * The same rules apply on the listing archive and on the listing taxonomy
 * archives (a city, a property type, a neighborhood): a renter filtering
 * inside /city/pittsburgh/ gets the same controls and the same answers.
 *
 * ─── WHAT A FILTER NEVER DOES ──────────────────────────────────────────────
 *
 * It never fails loudly and it never widens. A value that makes no sense
 * (beds=abc, type=nonsense, pets=maybe) is ignored, not an error. Prices the
 * wrong way round are swapped and the page says so. A search that matches
 * nothing answers with nothing — never with every home on the site.
 *
 * ─── THE STAY (task C2) ────────────────────────────────────────────────────
 *
 * `start` and `end` are the hero's own field names, so the dates a renter
 * typed on the front page survive the jump to the results. Whether a home
 * is free is not decided here: `Availability::is_free()` from A5 is the one
 * rule, and this class only asks it, once per candidate home, and turns the
 * answer into `post__in`. A date that cannot be honoured — in the past, back
 * to front, shorter than the site's minimum stay — is named on the page
 * rather than silently dropped.
 *
 * "Closest to a hospital" as a sort arrives with task C3.
 */
final class Search {

	/** The query variables. Short, because they live in shared URLs. */
	public const PARAM = 'q';
	public const MIN   = 'min_price';
	public const MAX   = 'max_price';
	public const BEDS  = 'beds';
	public const BATHS = 'baths';
	public const TYPE  = 'type';
	public const PETS  = 'pets';
	public const SORT  = 'sort';

	/** The hero's own field names, so its dates survive the jump here. */
	public const START = 'start';
	public const END   = 'end';

	/** The hospital a renter is moving near, and how far out to look. */
	public const FACILITY = 'facility';
	public const WITHIN   = 'within';

	/** `within=any` — order by distance but do not cut anything off. */
	public const WITHIN_ANY = 'any';

	/**
	 * List or map. A way of looking at the same homes, not a filter: it
	 * narrows nothing, so it never counts towards the Filters badge, never
	 * gets a chip, and survives Clear all.
	 */
	public const VIEW      = 'view';
	public const VIEW_LIST = 'list';
	public const VIEW_MAP  = 'map';

	/** Stored as `_tdh_price_monthly`; the wizard field is "rent". */
	public const META_PRICE = '_tdh_price_monthly';
	public const META_BEDS  = '_tdh_beds';
	public const META_BATHS = '_tdh_baths';
	public const META_PETS  = '_tdh_pet_policy';

	/** Above this a "price" is a typo. */
	public const PRICE_CAP = 100000;
	public const ROOMS_CAP = 10;

	/**
	 * The shortest stay this marketplace is for, in nights.
	 *
	 * A home may ask for more (`_tdh_min_stay_days`, 30 / 60 / 90), never
	 * for less, so this is the floor a renter's own dates are measured
	 * against before any home is looked at. It is the "thirty days" in the
	 * name of the site.
	 */
	public const MIN_STAY_DAYS = 30;

	/**
	 * Above this many published homes, asking each one whether it is free
	 * is no longer cheap. It still answers correctly; it is logged so the
	 * move to a dates table happens before a renter feels it.
	 */
	private const DATE_SCAN_LIMIT = 500;

	/** Why the dates on the URL could not be used as typed. */
	public const DATE_OK        = '';
	public const DATE_PAST      = 'past';
	public const DATE_BACKWARDS = 'backwards';
	public const DATE_SHORT     = 'short';
	public const DATE_NO_START  = 'no-start';
	public const DATE_FAR       = 'far';

	public const SORT_NEWEST     = 'newest';
	public const SORT_PRICE_ASC  = 'price-asc';
	public const SORT_PRICE_DESC = 'price-desc';
	public const SORT_CLOSEST    = 'closest';

	/** The radii offered beside a hospital or a typed place, in miles. */
	public const RADII = [ 5, 10, 15, 25, 50 ];

	/** "Closest" was asked for with nothing to be close to. */
	public const NO_FACILITY = 'no-facility';

	/** The taxonomies whose archives are listing searches too. */
	private const TAXONOMIES = [ Post_Types::TAX_TYPE, Post_Types::TAX_NEIGHBORHOOD, Post_Types::TAX_CITY, Post_Types::TAX_AMENITY ];

	public function register(): void {
		// After Visibility (priority 10), so the status and membership
		// constraints are already on the query when this narrows it.
		add_action( 'pre_get_posts', [ $this, 'apply' ], 20 );
		// Before the template is chosen (and before redirect_canonical at 10).
		add_action( 'template_redirect', [ $this, 'back_to_first_page' ], 5 );
	}

	/**
	 * A page number past the end of the results: back to the first page of
	 * the same search, filters kept.
	 *
	 * WordPress answers a paged archive with no posts as a 404, and on the
	 * listing archive the theme's not-found page is written for a missing
	 * HOME — "This home isn't available right now" — which is the wrong
	 * sentence for a renter who followed a stale page link, or narrowed a
	 * three-page search down to one page while standing on page 3. The first
	 * page of what they asked for is the honest answer. A city or
	 * neighbourhood archive goes back to its own first page, not to /homes/.
	 */
	public function back_to_first_page(): void {

		if ( is_admin() || ! is_404() ) {
			return;
		}

		global $wp_query;

		$paged = max( (int) $wp_query->get( 'paged' ), (int) $wp_query->get( 'page' ) );

		// A single home that does not exist is a real not-found, whatever
		// page number rides on it.
		if ( $paged < 2 || '' !== (string) $wp_query->get( 'name' ) || ! self::is_listing_request( $wp_query ) ) {
			return;
		}

		$base = self::request_base( $wp_query );

		if ( '' === $base ) {
			return;
		}

		wp_safe_redirect( add_query_arg( self::args(), $base ), 302 );
		exit;
	}

	/**
	 * Is this request for the listing archive or one of its taxonomy pages?
	 *
	 * Asked of the query VARS, not the flags: once WordPress has decided on
	 * a 404, is_post_type_archive() and is_tax() are already false.
	 */
	private static function is_listing_request( \WP_Query $query ): bool {

		if ( Post_Types::LISTING === (string) $query->get( 'post_type' ) ) {
			return true;
		}

		foreach ( self::TAXONOMIES as $taxonomy ) {
			if ( '' !== (string) $query->get( $taxonomy ) ) {
				return true;
			}
		}

		return false;
	}

	/** The first page of the archive this request was for, from its query vars. */
	private static function request_base( \WP_Query $query ): string {

		foreach ( self::TAXONOMIES as $taxonomy ) {
			$slug = (string) $query->get( $taxonomy );

			if ( '' === $slug ) {
				continue;
			}

			$term = get_term_by( 'slug', $slug, $taxonomy );
			$link = $term instanceof \WP_Term ? get_term_link( $term ) : '';

			return is_string( $link ) ? $link : '';
		}

		return (string) get_post_type_archive_link( Post_Types::LISTING );
	}

	/* ---------------------------------------------------------------------
	 * Reading the URL
	 * ------------------------------------------------------------------ */

	/**
	 * The search term for this request, sanitised. '' when none.
	 */
	public static function term(): string {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only search.
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return '';
		}

		// Nothing a renter searches for — a name, a place, a postcode — is
		// longer than this; anything that is would only stretch the chip and
		// the count sentence off the side of a phone.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return mb_substr( trim( sanitize_text_field( wp_unslash( (string) $_GET[ self::PARAM ] ) ) ), 0, self::MAX_TERM );
	}

	/** The longest search term read from the URL, in characters. */
	public const MAX_TERM = 100;

	/**
	 * The filters for this request, each validated or null.
	 *
	 * @return array{min_price:?int,max_price:?int,beds:?int,baths:?float,type:?\WP_Term,pets:?string,sort:string,swapped:bool,start:?string,end:?string,date_issue:string}
	 */
	public static function filters(): array {

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public read-only search.
		$get = static fn( string $key ): string => isset( $_GET[ $key ] ) ? trim( sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) ) : '';
		// phpcs:enable

		$min = self::money( $get( self::MIN ) );
		$max = self::money( $get( self::MAX ) );

		// Prices the wrong way round are a slip, not a request for nothing.
		$swapped = null !== $min && null !== $max && $min > $max;
		if ( $swapped ) {
			[ $min, $max ] = [ $max, $min ];
		}

		$beds  = self::rooms( $get( self::BEDS ), 1.0 );
		$baths = self::rooms( $get( self::BATHS ), 0.5 );

		$type_slug = sanitize_title( $get( self::TYPE ) );
		$type      = '' !== $type_slug ? get_term_by( 'slug', $type_slug, Post_Types::TAX_TYPE ) : false;

		$pets = $get( self::PETS );

		$stay     = self::stay( $get( self::START ), $get( self::END ) );
		$facility = self::facility( $get( self::FACILITY ) );
		$within   = self::within( $get( self::WITHIN ) );
		$area     = self::area( $facility );

		/*
		 * The sort, and the one place it is not simply what the URL said.
		 *
		 * A renter who picks a hospital — or types a postcode — wants the
		 * near ones first; that is the whole reason both exist. So
		 * "closest" becomes the default order the moment there is something
		 * to be close to, and any sort they pick afterwards still wins.
		 * Asking for "closest" with nothing to measure from is not a sort
		 * at all, so it falls back to newest and the page says why rather
		 * than reordering nothing in silence.
		 */
		$anchored     = null !== $facility || null !== $area;
		$asked        = $get( self::SORT );
		$sort         = array_key_exists( $asked, self::sorts() ) ? $asked : '';
		$sort_issue   = self::SORT_CLOSEST === $sort && ! $anchored ? self::NO_FACILITY : '';
		$default_sort = $anchored ? self::SORT_CLOSEST : self::SORT_NEWEST;

		if ( '' === $sort || '' !== $sort_issue ) {
			$sort = '' !== $sort_issue ? self::SORT_NEWEST : $default_sort;
		}

		return [
			'min_price'  => $min,
			'max_price'  => $max,
			'beds'       => null === $beds ? null : (int) $beds,
			'baths'      => $baths,
			'type'       => $type instanceof \WP_Term ? $type : null,
			'pets'       => array_key_exists( $pets, self::pets_options() ) ? $pets : null,
			'sort'       => $sort,
			'swapped'    => $swapped,
			'start'      => $stay['start'],
			'end'        => $stay['end'],
			'date_issue' => $stay['issue'],
			'facility'   => $facility,
			'within'     => $within,
			'sort_issue' => $sort_issue,
			'area'       => $area,
		];
	}

	/**
	 * The place the typed term names, or null.
	 *
	 * Three things have to be true before a term is treated as a place, and
	 * each of them protects something the search already promised:
	 *
	 * - **No hospital is chosen.** The hospital is an explicit control the
	 *   renter clicked; text typed in a box never overrules it.
	 * - **The term does not name a hospital.** The hero asks for
	 *   "Neighborhood, ZIP, or hospital", so "Mercy" has to keep meaning the
	 *   hospital rather than becoming a town somewhere with that name.
	 * - **The term is a postcode, town, neighbourhood or county.** Anything
	 *   else — a home's name, a description, nonsense — searches by text as
	 *   it always has.
	 *
	 * Looked up once per request. `filters()` is called from the filter bar,
	 * the chips, the heading, the cards and the map, and none of them should
	 * cost a second cache read.
	 *
	 * @param \WP_Post|null $facility The hospital already chosen, if any.
	 *
	 * @return array{lat:float,lng:float,label:string}|null
	 */
	private static function area( ?\WP_Post $facility ): ?array {

		static $memo = [];

		if ( null !== $facility || ! class_exists( '\TDH\Area' ) ) {
			return null;
		}

		$term = self::term();

		if ( '' === $term ) {
			return null;
		}

		if ( array_key_exists( $term, $memo ) ) {
			return $memo[ $term ];
		}

		$memo[ $term ] = self::facilities_named( $term ) ? null : Area::point( $term );

		return $memo[ $term ];
	}

	/**
	 * The hospital a renter chose, or null.
	 *
	 * Refused, and treated as no choice at all: anything that is not a
	 * published facility, one switched off for renter search, and one
	 * without a real point on the map. The last is the important one — a
	 * facility with no coordinates could only ever answer "no homes near
	 * it", which reads as a fact about the homes rather than about our own
	 * missing data.
	 */
	private static function facility( string $raw ): ?\WP_Post {

		$id = (int) $raw;

		if ( $id < 1 ) {
			return null;
		}

		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || Post_Types::FACILITY !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		/*
		 * Missing means active — the field arrived after the first
		 * facilities were entered, and those are all in renter search.
		 *
		 * But "missing" has to be asked for properly. `_tdh_active` is a
		 * registered BOOLEAN meta, and WordPress sanitises false to an
		 * empty string, so a facility switched off reads back as '' —
		 * exactly what an absent key reads as. Only metadata_exists()
		 * tells the two apart, and treating them the same would put every
		 * switched-off hospital back into renter search.
		 */
		if ( metadata_exists( 'post', $id, '_tdh_active' ) && '1' !== get_post_meta( $id, '_tdh_active', true ) ) {
			return null;
		}

		return null === Geocoder::coordinates( $id ) ? null : $post;
	}

	/**
	 * How far from the hospital to look, in miles.
	 *
	 * Null means "any distance": order by distance but cut nothing off.
	 * A blank means the staff setting from Listing setup, so the radius a
	 * renter gets by default is the same one the property pages use.
	 */
	private static function within( string $raw ): ?float {

		if ( self::WITHIN_ANY === strtolower( $raw ) ) {
			return null;
		}

		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return class_exists( '\TDH\Proximity' ) ? Proximity::radius_setting() : (float) Proximity::DEFAULT_RADIUS;
		}

		$miles = (float) $raw;

		// The same 1–100 staff are held to, so a URL cannot ask for a
		// radius the product does not claim to support.
		return $miles >= 1 && $miles <= Proximity::MAX_RADIUS ? $miles : Proximity::radius_setting();
	}

	/**
	 * The stay a renter asked for, or the reason it could not be honoured.
	 *
	 * The rules, in the order a renter meets them:
	 *
	 * - Neither date, or a "date" that is not one → no date search at all.
	 *   Nonsense is ignored here exactly as it is everywhere else.
	 * - A move-out with no move-in → we cannot tell when the stay starts,
	 *   so nothing is filtered and the page asks for the missing date.
	 * - A move-in already past → moved forward to today rather than
	 *   throwing the search away, and the page says that it was.
	 * - A move-out on or before the move-in → refused; that is not a stay.
	 * - Fewer nights than {@see MIN_STAY_DAYS} → refused with the reason.
	 *   The homes are all monthly, so a week's search would answer "none"
	 *   and teach a renter nothing.
	 * - Further ahead than a landlord can even mark a home taken
	 *   ({@see Availability::horizon()}) → refused, because every home
	 *   would look free and the answer would be worthless.
	 *
	 * A refused stay leaves `start` null, so the results are not narrowed
	 * by dates; `issue` is what the page says about it.
	 *
	 * @return array{start:?string,end:?string,issue:string}
	 */
	private static function stay( string $raw_start, string $raw_end ): array {

		$none  = [
			'start' => null,
			'end'   => null,
			'issue' => self::DATE_OK,
		];
		$start = Availability::is_date( $raw_start ) ? $raw_start : null;
		$end   = Availability::is_date( $raw_end ) ? $raw_end : null;

		if ( null === $start ) {
			// A move-out alone is the one blank worth mentioning: something
			// was typed, and ignoring it in silence would look like a bug.
			return null === $end ? $none : [ 'start' => null, 'end' => null, 'issue' => self::DATE_NO_START ];
		}

		$today = Availability::today();
		$issue = self::DATE_OK;

		if ( $start < $today ) {

			if ( null !== $end && $end <= $today ) {
				return [ 'start' => null, 'end' => null, 'issue' => self::DATE_PAST ];
			}

			$start = $today;
			$issue = self::DATE_PAST;
		}

		if ( $start > Availability::horizon() ) {
			return [ 'start' => null, 'end' => null, 'issue' => self::DATE_FAR ];
		}

		if ( null !== $end ) {

			$nights = Availability::days_between( $start, $end );

			if ( $nights < 1 ) {
				return [ 'start' => null, 'end' => null, 'issue' => self::DATE_BACKWARDS ];
			}

			if ( $nights < self::MIN_STAY_DAYS ) {
				return [ 'start' => null, 'end' => null, 'issue' => self::DATE_SHORT ];
			}
		}

		return [
			'start' => $start,
			'end'   => $end,
			'issue' => $issue,
		];
	}

	/**
	 * List or map, for this request.
	 *
	 * Always the list when there is no map key: with nothing to draw, a
	 * Map tab would be a door onto an empty room. Anything that is not
	 * "map" is the list, so a mistyped URL lands somewhere useful rather
	 * than nowhere.
	 */
	public static function view(): string {

		if ( ! class_exists( '\TDH\Maps' ) || ! Maps::configured() ) {
			return self::VIEW_LIST;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only search.
		$asked = isset( $_GET[ self::VIEW ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::VIEW ] ) ) : '';

		return self::VIEW_MAP === $asked ? self::VIEW_MAP : self::VIEW_LIST;
	}

	/** The same search, seen the other way. */
	public static function view_url( string $view ): string {

		$args = self::args();

		if ( self::VIEW_MAP === $view ) {
			$args[ self::VIEW ] = self::VIEW_MAP;
		} else {
			unset( $args[ self::VIEW ] );
		}

		return $args ? add_query_arg( array_map( 'rawurlencode', $args ), self::base_url() ) : self::base_url();
	}

	/**
	 * Exactly what was typed into one of the date fields, sanitised.
	 *
	 * The fields are filled from this and not from the validated stay, so a
	 * date the page has just refused is still sitting there to be corrected.
	 * Blanking it would make the notice above the form point at nothing.
	 */
	public static function typed( string $key ): string {

		if ( ! in_array( $key, [ self::START, self::END ], true ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only search.
		if ( ! isset( $_GET[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = trim( sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) );

		// A date input can only show a real date; anything else empties it
		// anyway, and echoing junk back helps nobody.
		return Availability::is_date( $raw ) ? $raw : '';
	}

	/** "$1,500", "1500", "1,500.00" → 1500; blank, 0, negative or nonsense → null. */
	private static function money( string $raw ): ?int {

		/*
		 * A minus sign is refused rather than stripped. Dropping it would turn
		 * "-99" into a real "From $99" filter, so a value nobody could have
		 * meant would quietly come back as a chip on the page.
		 */
		if ( false !== strpos( $raw, '-' ) ) {
			return null;
		}

		$digits = preg_replace( '/[^\d.]/', '', $raw ) ?? '';

		if ( '' === $digits || ! is_numeric( $digits ) ) {
			return null;
		}

		$value = (int) round( (float) $digits );

		return $value >= 1 && $value <= self::PRICE_CAP ? $value : null;
	}

	/** A room count in the given step (1 for bedrooms, 0.5 for bathrooms). */
	private static function rooms( string $raw, float $step ): ?float {

		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return null;
		}

		$value = (float) $raw;

		if ( $value < 1 || $value > self::ROOMS_CAP || abs( $value / $step - round( $value / $step ) ) > 0.0001 ) {
			return null;
		}

		return $value;
	}

	/**
	 * The filters that are actually set (sort only when not the default).
	 *
	 * @return array<string,mixed>
	 */
	public static function active(): array {

		$filters = self::filters();
		$active  = [];

		foreach ( [ 'min_price', 'max_price', 'beds', 'baths', 'type', 'pets' ] as $key ) {
			if ( null !== $filters[ $key ] ) {
				$active[ $key ] = $filters[ $key ];
			}
		}

		/*
		 * The two dates count as one filter, because that is what they are
		 * to a renter: a stay. One chip removes them, and the badge on the
		 * Filters button says "1", not "2".
		 */
		if ( null !== $filters['start'] ) {
			$active['dates'] = $filters['start'] . '|' . (string) $filters['end'];
		}

		/*
		 * The hospital and the radius are one filter too. The radius alone
		 * narrows nothing, and counting it would put "2" on the badge for
		 * one decision.
		 */
		if ( null !== $filters['facility'] ) {
			$active['facility'] = $filters['facility']->ID;
		}

		/*
		 * "Closest" is the order an anchor brings with it — a chosen
		 * hospital, or a typed postcode — not a separate thing the renter
		 * asked for, so it is not counted twice.
		 */
		if ( self::SORT_NEWEST !== $filters['sort']
			&& ! ( self::SORT_CLOSEST === $filters['sort'] && self::anchored() ) ) {
			$active['sort'] = $filters['sort'];
		}

		return $active;
	}

	/**
	 * Is there something on this page for a distance to be measured from?
	 *
	 * True for a chosen hospital and for a typed place. The two behave the
	 * same everywhere it matters — the sort is implied rather than asked
	 * for, the radius means something, and the cards can say how far.
	 */
	public static function anchored(): bool {

		$f = self::filters();

		return null !== $f['facility'] || null !== $f['area'];
	}

	/**
	 * The sort options and their plain names.
	 *
	 * Deliberately knows nothing about the current request: `filters()`
	 * validates the asked-for sort against these keys, so anything this
	 * method looked up would be looked up again on every call.
	 * {@see sort_label()} is where the wording learns what is anchoring
	 * the page.
	 *
	 * @return array<string,string>
	 */
	public static function sorts(): array {
		return [
			self::SORT_NEWEST     => __( 'Newest', 'thirtydayhomes' ),
			self::SORT_CLOSEST    => __( 'Closest first', 'thirtydayhomes' ),
			self::SORT_PRICE_ASC  => __( 'Price: low to high', 'thirtydayhomes' ),
			self::SORT_PRICE_DESC => __( 'Price: high to low', 'thirtydayhomes' ),
		];
	}

	/**
	 * One sort option's name, saying what "closest" is closest to.
	 *
	 * "Closest first" is honest but vague, and the renter has usually just
	 * typed or picked the thing being measured from, so the option names it
	 * back to them: "Closest to 15226", "Closest to UPMC Mercy". With
	 * nothing anchoring the page the option is disabled anyway, and the
	 * vague wording is the right one.
	 */
	public static function sort_label( string $value ): string {

		$labels = self::sorts();
		$plain  = (string) ( $labels[ $value ] ?? '' );

		if ( self::SORT_CLOSEST !== $value ) {
			return $plain;
		}

		$anchor = self::anchor_label();

		return '' === $anchor
			? $plain
			/* translators: %s: a hospital's name, or the postcode or town the renter typed. */
			: sprintf( __( 'Closest to %s', 'thirtydayhomes' ), $anchor );
	}

	/**
	 * The hospitals a renter may choose, grouped by city.
	 *
	 * Only the ones a distance can honestly be measured to: published, in
	 * renter search, and with a real point. Two hospitals of the same name
	 * in two cities are told apart by the group they sit in, which is why
	 * the list is grouped rather than flat.
	 *
	 * @return array<string,array<int,\WP_Post>> City name => facilities.
	 */
	public static function facilities(): array {

		if ( ! class_exists( '\TDH\Proximity' ) ) {
			return [];
		}

		$grouped = [];
		$other   = __( 'Elsewhere', 'thirtydayhomes' );

		foreach ( Proximity::facilities() as $facility ) {

			if ( null === Geocoder::coordinates( $facility->ID ) ) {
				continue;
			}

			$cities = wp_get_object_terms( $facility->ID, Post_Types::TAX_CITY, [ 'fields' => 'names' ] );
			$city   = is_array( $cities ) && $cities ? (string) $cities[0] : $other;

			$grouped[ $city ][] = $facility;
		}

		ksort( $grouped );

		return $grouped;
	}

	/** The radius choices offered beside a hospital, in miles. */
	public static function radii(): array {
		return self::RADII;
	}

	/** @return array<string,string> value => label, in the renter's words. */
	public static function pets_options(): array {
		return [
			'yes'        => __( 'Pets allowed', 'thirtydayhomes' ),
			'considered' => __( 'Pets considered', 'thirtydayhomes' ),
			'no'         => __( 'No pets', 'thirtydayhomes' ),
		];
	}

	/** @return array<int,string> The bedroom choices offered. */
	public static function beds_options(): array {
		return [ 1, 2, 3, 4 ];
	}

	/** @return array<int,float> The bathroom choices offered. */
	public static function baths_options(): array {
		return [ 1.0, 1.5, 2.0, 3.0 ];
	}

	/* ---------------------------------------------------------------------
	 * The query
	 * ------------------------------------------------------------------ */

	/**
	 * Is this the main query of a listing search page?
	 */
	/**
	 * A query var we set on our own listing queries.
	 *
	 * The filters are built for the archive's main query. A widget dropped
	 * on an ordinary page needs the same rules — the same keyword, dates,
	 * hospital, price and sort — or it would show a different set of homes
	 * from the one the URL describes. Flagging the query rather than
	 * loosening `is_listing_query()` keeps every OTHER secondary query on
	 * the site untouched, which is what that guard is there for.
	 */
	public const OWN_QUERY = 'tdh_search';

	public static function is_listing_query( \WP_Query $query ): bool {

		if ( true === $query->get( self::OWN_QUERY ) ) {
			return true;
		}

		return $query->is_main_query()
			&& ( $query->is_post_type_archive( Post_Types::LISTING ) || $query->is_tax( self::TAXONOMIES ) );
	}

	/**
	 * A listing query that obeys the same rules as the archive.
	 *
	 * For the widget and the shortcode when they are not on the archive.
	 * `post_status` is set here rather than left to Visibility, which only
	 * guards the main query: a secondary query must never be the way a
	 * draft or a paused home becomes visible.
	 */
	public static function own_query( int $per_page = 0 ): \WP_Query {

		$per_page = $per_page > 0 ? min( $per_page, 48 ) : (int) get_option( 'posts_per_page', 10 );

		return new \WP_Query(
			[
				'post_type'      => Post_Types::LISTING,
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'paged'          => max( 1, (int) get_query_var( 'paged' ) ),
				self::OWN_QUERY  => true,
			]
		);
	}

	/**
	 * Is the page being rendered one of the search results pages?
	 *
	 * The same question as {@see is_listing_query()} asked of the request
	 * rather than of a query object, for callers that are rendering rather
	 * than filtering — the Elementor widget and the shortcode, which must
	 * show nothing anywhere else.
	 */
	public static function is_results_page(): bool {
		return is_post_type_archive( Post_Types::LISTING ) || is_tax( self::TAXONOMIES );
	}

	/**
	 * Does this page hold the results band?
	 *
	 * A page built in Elementor keeps its layout in `_elementor_data`, and
	 * one written by hand keeps it in the content, so both are looked at.
	 * The names are searched as plain text rather than parsed: this runs
	 * on every page load, and a search for a string we wrote ourselves is
	 * both faster and harder to get wrong than decoding the JSON.
	 *
	 * Two things need the answer: the theme, to give the page its full
	 * width instead of a reading column, and the asset loader, because a
	 * filter drawer with no script and a map with no map are worse than
	 * not offering them.
	 */
	public static function page_holds_results( int $page_id = 0 ): bool {

		$page_id = $page_id > 0 ? $page_id : (int) get_queried_object_id();

		if ( $page_id < 1 ) {
			return false;
		}

		$page = get_post( $page_id );

		if ( $page instanceof \WP_Post && str_contains( $page->post_content, '[tdh_search_results' ) ) {
			return true;
		}

		return str_contains( (string) get_post_meta( $page_id, '_elementor_data', true ), 'tdh-search-results' );
	}

	/**
	 * Narrow and order the archive to what was asked for.
	 */
	public function apply( \WP_Query $query ): void {

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! self::is_listing_query( $query ) ) {
			return;
		}

		$term = self::term();
		$f    = self::filters();

		if ( '' !== $term ) {

			if ( null !== $f['area'] ) {
				/*
				 * A place, not a word. The renter asked "what is near here?",
				 * so the answer is every home near there, closest first —
				 * including the ones whose stored postcode is a different
				 * number, which is the whole point of R44. A home inside the
				 * postcode needs no text match to be found: it is already at
				 * the top of a list ordered by distance.
				 *
				 * With one exception. A home that names the place but was
				 * never geocoded cannot be measured, and dropping it would
				 * mean a search for "Pittsburgh" losing a home that says
				 * Pittsburgh on it — the renter would read that as the home
				 * being gone rather than as our own missing coordinates. So
				 * it is kept, and placed last, where an unmeasurable home
				 * belongs in a list ordered by distance.
				 */
				$ids = self::near_point_ids( $f['area']['lat'], $f['area']['lng'], $f['within'] );

				foreach ( self::matching_ids( $term ) as $named ) {
					if ( ! in_array( $named, $ids, true ) && null === Geocoder::coordinates( $named ) ) {
						$ids[] = $named;
					}
				}
			} else {
				$ids = self::matching_ids( $term );
			}

			/*
			 * [0] rather than leaving the query alone when nothing matches.
			 * An empty post__in is ignored by WP_Query, which would answer a
			 * failed search with every home on the site — the worst possible
			 * reply, because it looks like a result.
			 */
			$query->set( 'post__in', $ids ?: [ 0 ] );
		}

		/*
		 * The stay. Whether a home is free cannot be asked of the database:
		 * the taken periods are stored as lines of text, and the rule about
		 * the shared turnover day lives in Availability::fits(). So the
		 * homes are asked one at a time and the answer becomes post__in,
		 * intersected with whatever the keyword already narrowed it to.
		 */
		if ( null !== $f['start'] ) {

			$free    = self::free_ids( $f['start'], $f['end'] );
			$already = $query->get( 'post__in' );

			if ( is_array( $already ) && $already ) {
				$free = array_values( array_intersect( $already, $free ) );
			}

			$query->set( 'post__in', $free ?: [ 0 ] );
		}

		/*
		 * The hospital. Same shape as the stay: the answer is a list of
		 * ids, intersected with whatever came before. The difference is
		 * that this list is ORDERED — closest first — and WordPress is
		 * told to keep that order, so the distance sort costs no second
		 * query. array_intersect keeps the order of its first argument,
		 * so the ordered list is passed first.
		 */
		if ( null !== $f['facility'] ) {

			$near    = self::near_facility_ids( (int) $f['facility']->ID, $f['within'] );
			$already = $query->get( 'post__in' );

			if ( is_array( $already ) && $already ) {
				$near = array_values( array_intersect( $near, $already ) );
			}

			$query->set( 'post__in', $near ?: [ 0 ] );
		}

		// An unset query var reads as '' — and (array) '' is [''], a truthy
		// list with one empty clause in it. Only a real array is kept.
		$existing = $query->get( 'meta_query' );
		$meta     = is_array( $existing ) ? $existing : [];

		if ( null !== $f['min_price'] && null !== $f['max_price'] ) {
			$meta['price'] = [
				'key'     => self::META_PRICE,
				'value'   => [ $f['min_price'], $f['max_price'] ],
				'compare' => 'BETWEEN',
				'type'    => 'NUMERIC',
			];
		} elseif ( null !== $f['min_price'] ) {
			$meta['price'] = [
				'key'     => self::META_PRICE,
				'value'   => $f['min_price'],
				'compare' => '>=',
				'type'    => 'NUMERIC',
			];
		} elseif ( null !== $f['max_price'] ) {
			$meta['price'] = [
				'key'     => self::META_PRICE,
				'value'   => $f['max_price'],
				'compare' => '<=',
				'type'    => 'NUMERIC',
			];
		}

		if ( null !== $f['beds'] ) {
			$meta['beds'] = [
				'key'     => self::META_BEDS,
				'value'   => $f['beds'],
				'compare' => '>=',
				'type'    => 'NUMERIC',
			];
		}

		if ( null !== $f['baths'] ) {
			$meta['baths'] = [
				'key'     => self::META_BATHS,
				'value'   => $f['baths'],
				'compare' => '>=',
				'type'    => 'DECIMAL(4,1)',
			];
		}

		if ( null !== $f['pets'] ) {
			$meta['pets'] = [
				'key'     => self::META_PETS,
				'value'   => $f['pets'],
				'compare' => '=',
			];
		}

		if ( null !== $f['type'] ) {
			$existing_tax = $query->get( 'tax_query' );
			$tax          = is_array( $existing_tax ) ? $existing_tax : [];
			$tax[]        = [
				'taxonomy' => Post_Types::TAX_TYPE,
				'field'    => 'term_id',
				'terms'    => [ (int) $f['type']->term_id ],
			];
			$query->set( 'tax_query', $tax ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		if ( self::SORT_CLOSEST === $f['sort'] && ( null !== $f['facility'] || null !== $f['area'] ) ) {
			/*
			 * The order is already in post__in, closest first, so keeping
			 * it is the whole sort. With nothing to be close to, "closest"
			 * never reaches here: filters() has already turned it into
			 * newest and the page says so.
			 */
			$query->set( 'orderby', 'post__in' );
		} elseif ( in_array( $f['sort'], [ self::SORT_PRICE_ASC, self::SORT_PRICE_DESC ], true ) ) {
			/*
			 * A named clause to order by. A home with no price cannot be
			 * placed in a price order, so it is left out of a price sort;
			 * the wizard requires a rent, so that is a legacy record if it
			 * ever happens, and it still appears in every other order.
			 */
			if ( ! isset( $meta['price'] ) ) {
				$meta['price'] = [
					'key'     => self::META_PRICE,
					'compare' => 'EXISTS',
					'type'    => 'NUMERIC',
				];
			}
			$query->set( 'orderby', [ 'price' => self::SORT_PRICE_ASC === $f['sort'] ? 'ASC' : 'DESC' ] );
		} else {
			$query->set( 'orderby', 'date' );
			$query->set( 'order', 'DESC' );
		}

		if ( $meta ) {
			$meta['relation'] = 'AND';
			$query->set( 'meta_query', $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
	}

	/**
	 * The published homes free for this stay.
	 *
	 * A move-out of null is an open-ended stay: the renter said when they
	 * arrive and nothing more, so each home is asked whether it is free
	 * from that day for as long as **it** requires — its own minimum stay,
	 * or the site's, whichever is longer. Asking for a fixed thirty nights
	 * instead would hide every home that only takes longer bookings, which
	 * is the opposite of what an open-ended search means.
	 *
	 * @param string      $start Move-in, `YYYY-MM-DD`.
	 * @param string|null $end   Move-out, or null for an open-ended stay.
	 *
	 * @return int[]
	 */
	public static function free_ids( string $start, ?string $end ): array {

		$candidates = get_posts(
			[
				'post_type'      => Post_Types::LISTING,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		$candidates = array_map( 'intval', (array) $candidates );

		if ( count( $candidates ) > self::DATE_SCAN_LIMIT && class_exists( '\TDH\Log' ) ) {
			Log::warning(
				'listings',
				'date_scan_large',
				'Date search read every published home one by one. Time to store availability in its own table.',
				[
					'homes' => count( $candidates ),
					'limit' => self::DATE_SCAN_LIMIT,
				]
			);
		}

		$free = [];

		foreach ( $candidates as $id ) {

			$until = $end ?? Availability::add_days( $start, max( self::MIN_STAY_DAYS, Availability::min_stay( $id ) ) );

			if ( Availability::is_free( $id, $start, $until ) ) {
				$free[] = $id;
			}
		}

		return $free;
	}

	/**
	 * Published homes near a facility, closest first.
	 *
	 * The distance is measured to the facility the renter chose, not to
	 * each home's own nearest one. That difference is the whole point of
	 * the feature: a nurse starting at Mercy does not care that a home is
	 * half a mile from a hospital on the other side of the city.
	 *
	 * `$within` of null means "any distance": every home that has a point
	 * is ordered by distance, and the homes with no point follow at the
	 * end, keeping the order they already had. With a radius they are left
	 * out instead — a home listed under "within 15 miles" with no distance
	 * beside it is a claim we cannot make.
	 *
	 * @param int        $facility_id The chosen facility.
	 * @param float|null $within      Miles, or null for any distance.
	 *
	 * @return int[] Listing ids, closest first.
	 */
	public static function near_facility_ids( int $facility_id, ?float $within ): array {

		$point = Geocoder::coordinates( $facility_id );

		return null === $point ? [] : self::near_point_ids( $point['lat'], $point['lng'], $within );
	}

	/**
	 * Published homes ordered by distance from a point, closest first.
	 *
	 * The one measuring routine behind both anchors a renter can have: the
	 * hospital they picked from the list, and the postcode or town they
	 * typed into the box. Keeping it in one place is what stops the two
	 * disagreeing about a boundary or about where an unplaced home belongs.
	 *
	 * A home with no coordinates cannot be measured. It is dropped from a
	 * radius — claiming it is "within 10 miles" would be an invention — and
	 * placed last when the renter asked for any distance, so it is still
	 * reachable rather than quietly deleted from the marketplace.
	 *
	 * @param float      $lat    Latitude of the point to measure from.
	 * @param float      $lng    Longitude of the point to measure from.
	 * @param float|null $within Miles, or null for any distance.
	 *
	 * @return int[]
	 */
	public static function near_point_ids( float $lat, float $lng, ?float $within ): array {

		$point = [
			'lat' => $lat,
			'lng' => $lng,
		];

		$candidates = array_map(
			'intval',
			(array) get_posts(
				[
					'post_type'      => Post_Types::LISTING,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				]
			)
		);

		if ( count( $candidates ) > self::DATE_SCAN_LIMIT && class_exists( '\TDH\Log' ) ) {
			Log::warning(
				'listings',
				'distance_scan_large',
				'Distance search measured every published home one by one. Time to store coordinates where the database can sort them.',
				[
					'homes' => count( $candidates ),
					'limit' => self::DATE_SCAN_LIMIT,
				]
			);
		}

		$measured = [];
		$unplaced = [];

		foreach ( $candidates as $id ) {

			$home = Geocoder::coordinates( $id );

			if ( null === $home ) {
				$unplaced[] = $id;
				continue;
			}

			$miles = Proximity::miles( $point['lat'], $point['lng'], $home['lat'], $home['lng'] );

			// Exactly at the radius is within it — the same boundary the
			// property pages already use, so the two never disagree.
			if ( null !== $within && $miles > $within ) {
				continue;
			}

			$measured[ $id ] = $miles;
		}

		asort( $measured );

		$ordered = array_keys( $measured );

		return null === $within ? array_merge( $ordered, $unplaced ) : $ordered;
	}

	/**
	 * How far a home is from the hospital the renter chose, or null.
	 *
	 * Null when no hospital was chosen, when the home has no point, or
	 * when either lookup fails — never a zero, which would read as "next
	 * door" instead of "we do not know".
	 */
	public static function miles_to_choice( int $listing_id ): ?float {

		$f = self::filters();

		if ( ! class_exists( '\TDH\Proximity' ) ) {
			return null;
		}

		$there = null !== $f['facility'] ? Geocoder::coordinates( (int) $f['facility']->ID ) : $f['area'];
		$here  = Geocoder::coordinates( $listing_id );

		if ( null === $there || null === $here ) {
			return null;
		}

		return Proximity::miles( $there['lat'], $there['lng'], $here['lat'], $here['lng'] );
	}

	/**
	 * What the distances on this page are measured from, in words.
	 *
	 * The hospital's name, or the place the renter typed as the geocoder
	 * spells it back ("15226", "Squirrel Hill, PA"), or an empty string when
	 * nothing is anchoring the search. Every "2.1 miles" on the page is
	 * followed by this, because a distance with no "from what" is a number
	 * the renter has to guess at.
	 */
	public static function anchor_label(): string {

		$f = self::filters();

		if ( null !== $f['facility'] ) {
			return (string) $f['facility']->post_title;
		}

		return null !== $f['area'] ? (string) $f['area']['label'] : '';
	}

	/**
	 * Listing ids matching the term by name, place or postcode.
	 *
	 * Three separate lookups unioned, rather than one clever SQL join:
	 * a renter typing "Shadyside" means the neighborhood, typing "15232"
	 * means the postcode, and typing part of a home's name means the name.
	 * Each is a different question and WP_Query answers each one cleanly.
	 *
	 * @return int[]
	 */
	public static function matching_ids( string $term ): array {

		if ( '' === $term ) {
			return [];
		}

		$base = [
			'post_type'      => Post_Types::LISTING,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		];

		// 1. The home's own name and description.
		$by_text = get_posts( $base + [ 's' => $term ] );

		// 2. Where it is: neighborhood, city, or the kind of property.
		$term_ids = [];

		foreach ( [ Post_Types::TAX_NEIGHBORHOOD, Post_Types::TAX_CITY, Post_Types::TAX_TYPE ] as $taxonomy ) {

			$found = get_terms(
				[
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'name__like' => $term,
					'fields'     => 'ids',
				]
			);

			if ( ! is_wp_error( $found ) ) {
				$term_ids[ $taxonomy ] = array_map( 'intval', $found );
			}
		}

		$term_ids = array_filter( $term_ids );
		$by_place = [];

		if ( $term_ids ) {

			$tax_query = [ 'relation' => 'OR' ];

			foreach ( $term_ids as $taxonomy => $ids ) {
				$tax_query[] = [
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $ids,
				];
			}

			$by_place = get_posts( $base + [ 'tax_query' => $tax_query ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		// 3. The postcode, which a renter types in full or in part.
		$by_zip = get_posts(
			$base + [
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => '_tdh_zip',
						'value'   => $term,
						'compare' => 'LIKE',
					],
				],
			]
		);

		/*
		 * 4. The hospital. The hero asks for a "neighborhood, ZIP, or
		 * hospital", so typing "Mercy" has to find the homes near Mercy —
		 * otherwise the field promises something the search cannot do.
		 * Homes near any matching hospital, within the staff radius.
		 */
		$by_facility = [];

		foreach ( self::facilities_named( $term ) as $facility_id ) {
			$by_facility = array_merge( $by_facility, self::near_facility_ids( $facility_id, Proximity::radius_setting() ) );
		}

		$ids = array_merge(
			array_map( 'intval', (array) $by_text ),
			array_map( 'intval', (array) $by_place ),
			array_map( 'intval', (array) $by_zip ),
			$by_facility
		);

		return array_values( array_unique( $ids ) );
	}

	/**
	 * The ids of the facilities whose name contains this term.
	 *
	 * Goes through the same query the property pages use, filter and all,
	 * so a facility hidden from renter search cannot be found here either.
	 *
	 * @return int[]
	 */
	private static function facilities_named( string $term ): array {

		if ( '' === $term || ! class_exists( '\TDH\Proximity' ) ) {
			return [];
		}

		$found = [];

		foreach ( Proximity::facilities() as $facility ) {
			if ( false !== stripos( $facility->post_title, $term ) ) {
				$found[] = (int) $facility->ID;
			}
		}

		return $found;
	}

	/* ---------------------------------------------------------------------
	 * URLs — where the form posts, what a chip removes, what Clear keeps
	 * ------------------------------------------------------------------ */

	/**
	 * The page the search belongs to: the listing archive, or the term's
	 * own archive when the renter is inside a city, type or neighborhood.
	 */
	public static function base_url(): string {

		if ( is_tax( self::TAXONOMIES ) ) {
			$object = get_queried_object();

			if ( $object instanceof \WP_Term ) {
				$link = get_term_link( $object );

				if ( is_string( $link ) ) {
					return $link;
				}
			}
		}

		return (string) get_post_type_archive_link( Post_Types::LISTING );
	}

	/**
	 * The current search as query arguments, ready to be edited.
	 *
	 * @return array<string,string>
	 */
	public static function args(): array {

		$f    = self::filters();
		$args = [];

		if ( '' !== self::term() ) {
			$args[ self::PARAM ] = self::term();
		}
		if ( null !== $f['min_price'] ) {
			$args[ self::MIN ] = (string) $f['min_price'];
		}
		if ( null !== $f['max_price'] ) {
			$args[ self::MAX ] = (string) $f['max_price'];
		}
		if ( null !== $f['beds'] ) {
			$args[ self::BEDS ] = (string) $f['beds'];
		}
		if ( null !== $f['baths'] ) {
			$args[ self::BATHS ] = self::half( $f['baths'] );
		}
		if ( null !== $f['type'] ) {
			$args[ self::TYPE ] = $f['type']->slug;
		}
		if ( null !== $f['pets'] ) {
			$args[ self::PETS ] = $f['pets'];
		}
		if ( null !== $f['start'] ) {
			$args[ self::START ] = $f['start'];
		}
		if ( null !== $f['end'] ) {
			$args[ self::END ] = $f['end'];
		}
		if ( null !== $f['facility'] ) {
			$args[ self::FACILITY ] = (string) $f['facility']->ID;
		}

		// The radius belongs to whichever anchor the page has — the chosen
		// hospital, or the place that was typed. Carried only when it is
		// not simply the setting, so a shared link stays short and the
		// default can be changed later without every old link disagreeing
		// with the new one.
		if ( null !== $f['facility'] || null !== $f['area'] ) {
			if ( null === $f['within'] ) {
				$args[ self::WITHIN ] = self::WITHIN_ANY;
			} elseif ( abs( $f['within'] - Proximity::radius_setting() ) > 0.001 ) {
				$args[ self::WITHIN ] = Proximity::miles_number( $f['within'] );
			}
		}
		if ( self::SORT_NEWEST !== $f['sort']
			&& ! ( self::SORT_CLOSEST === $f['sort'] && self::anchored() ) ) {
			$args[ self::SORT ] = $f['sort'];
		}

		// Last, so that every chip, every page link and every widened
		// radius keeps the renter on the map if that is where they were.
		if ( self::VIEW_MAP === self::view() ) {
			$args[ self::VIEW ] = self::VIEW_MAP;
		}

		return $args;
	}

	/**
	 * The search, minus one argument.
	 *
	 * Removing the move-in takes the move-out with it. A move-out on its
	 * own filters nothing, so leaving it behind would put a date in the URL
	 * that does nothing and a field on the page that looks ignored.
	 */
	public static function url_without( string $key ): string {
		$args = self::args();
		unset( $args[ $key ] );

		if ( self::START === $key ) {
			unset( $args[ self::END ] );
		}

		/*
		 * Dropping the anchor drops the radius and the order that came with
		 * it; a radius on its own narrows nothing, and "closest" with
		 * nothing to be close to is not an order. The anchor is the
		 * hospital, or — when the place was typed rather than picked — the
		 * search term itself, so clearing the box has to clear both too.
		 */
		$anchor_gone = self::FACILITY === $key
			|| ( self::PARAM === $key && null !== self::filters()['area'] );

		if ( $anchor_gone ) {
			unset( $args[ self::WITHIN ] );

			if ( isset( $args[ self::SORT ] ) && self::SORT_CLOSEST === $args[ self::SORT ] ) {
				unset( $args[ self::SORT ] );
			}
		}

		return $args ? add_query_arg( array_map( 'rawurlencode', $args ), self::base_url() ) : self::base_url();
	}

	/**
	 * Every filter and the sort gone; the keyword, if any, kept — and the
	 * map kept too. Clearing filters is about which homes are shown, not
	 * about how the renter chose to look at them.
	 */
	public static function clear_url(): string {

		$args = [];
		$term = self::term();

		if ( '' !== $term ) {
			$args[ self::PARAM ] = $term;
		}
		if ( self::VIEW_MAP === self::view() ) {
			$args[ self::VIEW ] = self::VIEW_MAP;
		}

		return $args ? add_query_arg( array_map( 'rawurlencode', $args ), self::base_url() ) : self::base_url();
	}

	/**
	 * The active filters as chips: a label, and the search without it.
	 *
	 * @return array<int,array{key:string,label:string,remove:string}>
	 */
	public static function chips(): array {

		$f     = self::filters();
		$chips = [];

		if ( '' !== self::term() ) {
			/* translators: %s: the renter's search term */
			$chips[] = [ 'key' => self::PARAM, 'label' => sprintf( __( '“%s”', 'thirtydayhomes' ), self::term() ), 'remove' => self::url_without( self::PARAM ) ];
		}
		if ( null !== $f['min_price'] ) {
			/* translators: %s: amount */
			$chips[] = [ 'key' => self::MIN, 'label' => sprintf( __( 'From %s', 'thirtydayhomes' ), self::dollars( $f['min_price'] ) ), 'remove' => self::url_without( self::MIN ) ];
		}
		if ( null !== $f['max_price'] ) {
			/* translators: %s: amount */
			$chips[] = [ 'key' => self::MAX, 'label' => sprintf( __( 'Up to %s', 'thirtydayhomes' ), self::dollars( $f['max_price'] ) ), 'remove' => self::url_without( self::MAX ) ];
		}
		if ( null !== $f['beds'] ) {
			/* translators: %s: number of bedrooms */
			$chips[] = [ 'key' => self::BEDS, 'label' => sprintf( _n( '%s+ bedroom', '%s+ bedrooms', $f['beds'], 'thirtydayhomes' ), number_format_i18n( $f['beds'] ) ), 'remove' => self::url_without( self::BEDS ) ];
		}
		if ( null !== $f['baths'] ) {
			/* translators: %s: number of bathrooms, may be a half */
			$chips[] = [ 'key' => self::BATHS, 'label' => sprintf( _n( '%s+ bathroom', '%s+ bathrooms', (int) ceil( $f['baths'] ), 'thirtydayhomes' ), self::half( $f['baths'] ) ), 'remove' => self::url_without( self::BATHS ) ];
		}
		if ( null !== $f['type'] ) {
			$chips[] = [ 'key' => self::TYPE, 'label' => $f['type']->name, 'remove' => self::url_without( self::TYPE ) ];
		}
		if ( null !== $f['pets'] ) {
			$chips[] = [ 'key' => self::PETS, 'label' => self::pets_options()[ $f['pets'] ], 'remove' => self::url_without( self::PETS ) ];
		}
		if ( null !== $f['start'] ) {
			$chips[] = [ 'key' => self::START, 'label' => self::stay_phrase(), 'remove' => self::url_without( self::START ) ];
		}
		if ( null !== $f['facility'] ) {
			$chips[] = [ 'key' => self::FACILITY, 'label' => self::near_phrase(), 'remove' => self::url_without( self::FACILITY ) ];
		}
		/*
		 * "Closest" alongside an anchor is not a second chip. It is the
		 * order the hospital or the typed place came with, already named in
		 * its own chip or in the search box itself, and a chip whose ×
		 * would change nothing visible is worse than no chip at all.
		 */
		if ( self::SORT_NEWEST !== $f['sort']
			&& ! ( self::SORT_CLOSEST === $f['sort'] && self::anchored() ) ) {
			$chips[] = [ 'key' => self::SORT, 'label' => self::sort_label( $f['sort'] ), 'remove' => self::url_without( self::SORT ) ];
		}

		return $chips;
	}

	/* ---------------------------------------------------------------------
	 * Words
	 * ------------------------------------------------------------------ */

	/**
	 * The count line above the results.
	 */
	public static function summary( int $found ): string {

		$f    = self::filters();
		$term = self::term();

		/*
		 * Dates first. "3 homes match your filters" is true but useless to
		 * someone who came from the hero with a stay in mind; they want to
		 * read their own dates back and know these homes are free for them.
		 */
		/*
		 * Where comes before everything, including the dates. It is the
		 * question this marketplace exists to answer, and a renter who
		 * chose a hospital — or typed a postcode — is reading the list as
		 * "how close is each of these". Saying "8 homes within 15 miles of
		 * 15226" is also the honest reply when none of the eight is in
		 * 15226 itself, which "8 homes matching 15226" would not be.
		 */
		if ( self::anchored() ) {
			/* translators: 1: number of homes, 2: e.g. "within 15 miles of UPMC Mercy" */
			return sprintf( _n( '%1$s home %2$s', '%1$s homes %2$s', $found, 'thirtydayhomes' ), number_format_i18n( $found ), self::near_phrase() );
		}

		if ( null !== $f['start'] ) {
			/* translators: 1: number of homes, 2: the stay, e.g. "1 Nov – 1 Dec 2026" */
			return sprintf( _n( '%1$s home free %2$s', '%1$s homes free %2$s', $found, 'thirtydayhomes' ), number_format_i18n( $found ), self::stay_phrase() );
		}

		if ( self::active() ) {
			/* translators: %s: number of homes */
			return sprintf( _n( '%s home matches your filters', '%s homes match your filters', $found, 'thirtydayhomes' ), number_format_i18n( $found ) );
		}

		if ( '' !== $term ) {
			/* translators: 1: number of homes found, 2: the renter's search term */
			return sprintf( _n( '%1$s home matching “%2$s”', '%1$s homes matching “%2$s”', $found, 'thirtydayhomes' ), number_format_i18n( $found ), $term );
		}

		/* translators: %s: number of homes found */
		return sprintf( _n( '%s home', '%s homes', $found, 'thirtydayhomes' ), number_format_i18n( $found ) );
	}

	/**
	 * What matched nothing, in one sentence the renter can act on:
	 * "No 2+ bedroom homes under $2,000 that allow pets matching “Shadyside”."
	 */
	public static function empty_sentence(): string {

		$f    = self::filters();
		$term = self::term();

		if ( ! self::active() && '' === $term ) {
			$place = self::archive_term();

			/*
			 * A neighbourhood, city, type or amenity page with nothing on it
			 * names the place. "No homes are listed yet" on the South Side
			 * page would say more than is true about the rest of the site.
			 */
			if ( $place instanceof \WP_Term ) {
				return match ( $place->taxonomy ) {
					/* translators: %s: property type */
					Post_Types::TAX_TYPE    => sprintf( __( 'No %s homes yet', 'thirtydayhomes' ), $place->name ),
					/* translators: %s: amenity */
					Post_Types::TAX_AMENITY => sprintf( __( 'No homes with %s yet', 'thirtydayhomes' ), $place->name ),
					/* translators: %s: neighbourhood or city */
					default                 => sprintf( __( 'No homes in %s yet', 'thirtydayhomes' ), $place->name ),
				};
			}

			return __( 'No homes are listed yet', 'thirtydayhomes' );
		}

		$parts = [ __( 'No', 'thirtydayhomes' ) ];

		if ( null !== $f['beds'] ) {
			/* translators: %s: number of bedrooms */
			$parts[] = sprintf( __( '%s+ bedroom', 'thirtydayhomes' ), number_format_i18n( $f['beds'] ) );
		}

		$parts[] = null !== $f['type']
			/* translators: %s: property type name */
			? sprintf( __( '%s homes', 'thirtydayhomes' ), $f['type']->name )
			: __( 'homes', 'thirtydayhomes' );

		if ( null !== $f['baths'] ) {
			/* translators: %s: number of bathrooms */
			$parts[] = sprintf( __( 'with %s+ bathrooms', 'thirtydayhomes' ), self::half( $f['baths'] ) );
		}

		if ( null !== $f['min_price'] && null !== $f['max_price'] ) {
			/* translators: 1: lower amount, 2: upper amount */
			$parts[] = sprintf( __( 'between %1$s and %2$s', 'thirtydayhomes' ), self::dollars( $f['min_price'] ), self::dollars( $f['max_price'] ) );
		} elseif ( null !== $f['min_price'] ) {
			/* translators: %s: amount */
			$parts[] = sprintf( __( 'over %s', 'thirtydayhomes' ), self::dollars( $f['min_price'] ) );
		} elseif ( null !== $f['max_price'] ) {
			/* translators: %s: amount */
			$parts[] = sprintf( __( 'under %s', 'thirtydayhomes' ), self::dollars( $f['max_price'] ) );
		}

		if ( null !== $f['pets'] ) {
			$parts[] = [
				'yes'        => __( 'that allow pets', 'thirtydayhomes' ),
				'considered' => __( 'that consider pets', 'thirtydayhomes' ),
				'no'         => __( 'with no pets', 'thirtydayhomes' ),
			][ $f['pets'] ];
		}

		if ( null !== $f['start'] ) {
			/* translators: %s: the stay, e.g. "1 Nov – 1 Dec 2026" */
			$parts[] = sprintf( __( 'free %s', 'thirtydayhomes' ), self::stay_phrase() );
		}

		if ( self::anchored() ) {
			$parts[] = self::near_phrase();
		}

		/*
		 * A typed place is already said as "within 15 miles of 15226".
		 * Adding "matching “15226”" after it would repeat the term and,
		 * worse, describe a text match that is not what happened.
		 */
		if ( '' !== $term && null === $f['area'] ) {
			/* translators: %s: the renter's search term */
			$parts[] = sprintf( __( 'matching “%s”', 'thirtydayhomes' ), $term );
		}

		return implode( ' ', $parts ) . '.';
	}

	/** The term whose archive this request is, when it is one. */
	private static function archive_term(): ?\WP_Term {

		if ( ! is_tax( self::TAXONOMIES ) ) {
			return null;
		}

		$object = get_queried_object();

		return $object instanceof \WP_Term ? $object : null;
	}

	/**
	 * The stay in the renter's own words: "1 Nov – 1 Dec 2026", or
	 * "from 1 Nov 2026" when only a move-in was given.
	 */
	public static function stay_phrase(): string {

		$f = self::filters();

		if ( null === $f['start'] ) {
			return '';
		}

		if ( null === $f['end'] ) {
			/* translators: %s: a date */
			return sprintf( __( 'from %s', 'thirtydayhomes' ), Availability::format_day( $f['start'] ) );
		}

		return Availability::format_range(
			[
				'from' => $f['start'],
				'to'   => $f['end'],
			]
		);
	}

	/**
	 * What the page says about dates it could not use as typed, or ''.
	 *
	 * Every one of these says what happened and what to do next, because a
	 * renter who typed two dates and got a page that ignored them has no
	 * way to tell a filter from a fault.
	 */
	public static function date_notice(): string {

		$f = self::filters();

		switch ( $f['date_issue'] ) {

			case self::DATE_PAST:
				return null === $f['start']
					? __( 'Those dates have already passed. Choose a move-in date from today onwards.', 'thirtydayhomes' )
					/* translators: %s: today's date */
					: sprintf( __( 'A move-in date cannot be in the past, so we searched from %s.', 'thirtydayhomes' ), Availability::format_day( $f['start'] ) );

			case self::DATE_BACKWARDS:
				return __( 'Your move-out date is not after your move-in date, so we ignored both. Try a later move-out date.', 'thirtydayhomes' );

			case self::DATE_SHORT:
				/* translators: %s: number of days, e.g. "30" */
				return sprintf( __( 'Stays are %s days or longer — try a later move-out date. We searched without dates.', 'thirtydayhomes' ), number_format_i18n( self::MIN_STAY_DAYS ) );

			case self::DATE_NO_START:
				return __( 'Add a move-in date and we can show only the homes free for your whole stay.', 'thirtydayhomes' );

			case self::DATE_FAR:
				/* translators: %s: the furthest date that can be searched */
				return sprintf( __( 'We can only search as far ahead as %s, so we searched without dates.', 'thirtydayhomes' ), Availability::format_day( Availability::horizon() ) );
		}

		return '';
	}

	/**
	 * The anchor in the renter's own words: "within 15 miles of UPMC
	 * Mercy", "within 15 miles of 15226", or "near …" when no radius was
	 * set. The same sentence whether the place was picked from the list or
	 * typed into the box, because to a renter it is the same question.
	 */
	public static function near_phrase(): string {

		$f      = self::filters();
		$anchor = self::anchor_label();

		if ( '' === $anchor ) {
			return '';
		}

		if ( null === $f['within'] ) {
			/* translators: %s: a hospital's name, or a postcode or town */
			return sprintf( __( 'near %s', 'thirtydayhomes' ), $anchor );
		}

		return sprintf(
			/* translators: 1: a distance, e.g. "15 miles", 2: a hospital's name, or a postcode or town */
			__( 'within %1$s of %2$s', 'thirtydayhomes' ),
			Proximity::miles_phrase( $f['within'] ),
			$anchor
		);
	}

	/**
	 * The next radius out, for an empty result to offer, or ''.
	 *
	 * A renter who searched 15 miles and found nothing is told the number
	 * that would find something, not simply that there is nothing. When
	 * the widest step has already been tried the offer becomes "any
	 * distance" rather than a step that does not exist.
	 */
	public static function wider_url(): string {

		$f = self::filters();

		if ( ! self::anchored() || null === $f['within'] ) {
			return '';
		}

		$args = self::args();

		foreach ( self::RADII as $miles ) {
			if ( $miles > $f['within'] ) {
				$args[ self::WITHIN ] = (string) $miles;

				return add_query_arg( array_map( 'rawurlencode', $args ), self::base_url() );
			}
		}

		$args[ self::WITHIN ] = self::WITHIN_ANY;

		return add_query_arg( array_map( 'rawurlencode', $args ), self::base_url() );
	}

	/** The words on that wider-radius link. */
	public static function wider_label(): string {

		$f = self::filters();

		if ( ! self::anchored() || null === $f['within'] ) {
			return '';
		}

		foreach ( self::RADII as $miles ) {
			if ( $miles > $f['within'] ) {
				/* translators: %s: a distance, e.g. "25 miles" */
				return sprintf( __( 'Look within %s instead', 'thirtydayhomes' ), Proximity::miles_phrase( (float) $miles ) );
			}
		}

		return __( 'Look at any distance instead', 'thirtydayhomes' );
	}

	/**
	 * What the page says when "closest" was asked for with nothing to be
	 * close to, or ''.
	 */
	public static function sort_notice(): string {

		$f = self::filters();

		if ( self::NO_FACILITY !== $f['sort_issue'] ) {
			return '';
		}

		return __( 'Type a postcode or area, or choose a hospital, and we can put the closest homes first. Showing the newest instead.', 'thirtydayhomes' );
	}

	/** The notice when the prices were swapped, or ''. */
	public static function swapped_notice(): string {

		$f = self::filters();

		if ( ! $f['swapped'] ) {
			return '';
		}

		/* translators: 1: lower amount, 2: upper amount */
		return sprintf( __( 'Your prices were the wrong way round, so we swapped them: showing %1$s to %2$s.', 'thirtydayhomes' ), self::dollars( (int) $f['min_price'] ), self::dollars( (int) $f['max_price'] ) );
	}

	/** "$1,500". */
	public static function dollars( int $amount ): string {
		return '$' . number_format_i18n( $amount );
	}

	/** 1.5 → "1.5", 2.0 → "2". */
	public static function half( float $value ): string {
		return fmod( $value, 1.0 ) > 0 ? number_format_i18n( $value, 1 ) : number_format_i18n( $value );
	}
}
