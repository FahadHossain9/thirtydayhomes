<?php
/**
 * Keyword search over the listing archive.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the search box into a search.
 *
 * ─── WHY THIS EXISTS, AND WHERE IT STOPS ───────────────────────────────────
 *
 * The archive carried a search box that did nothing: it put the term in the
 * URL and the page answered "showing all homes". The owner asked for the
 * "Find a Home" link to open a real search, and the handoff's first delivery
 * principle is complete flows rather than isolated screens — a box that
 * accepts a word and ignores it teaches a reviewer the feature is broken.
 *
 * So the keyword half is real: a renter types a neighborhood, a city or a
 * ZIP and gets the homes that match, or an honest empty state.
 *
 * The FILTER half is deliberately absent. Price, bedrooms, bathrooms, type,
 * pet policy, availability and sorting are Milestone 2 deliverables, and the
 * date fields on the hero belong to that same work. Shipping a half-wired
 * filter bar now would be the exact mistake this class was written to undo.
 *
 * Query logic lives in the plugin, never the theme — a theme swap must not
 * take search with it.
 */
final class Search {

	/** The query variable the hero and the archive bar both submit. */
	public const PARAM = 'q';

	public function register(): void {
		// After Visibility (priority 10), so the status and membership
		// constraints are already on the query when this narrows it.
		add_action( 'pre_get_posts', [ $this, 'apply' ], 20 );
	}

	/**
	 * The search term for this request, sanitised. '' when none.
	 */
	public static function term(): string {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only search.
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return trim( sanitize_text_field( wp_unslash( (string) $_GET[ self::PARAM ] ) ) );
	}

	/**
	 * Narrow the archive to the homes that match.
	 */
	public function apply( \WP_Query $query ): void {

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! $query->is_main_query() || ! $query->is_post_type_archive( Post_Types::LISTING ) ) {
			return;
		}

		$term = self::term();

		if ( '' === $term ) {
			return;
		}

		$ids = self::matching_ids( $term );

		/*
		 * [0] rather than leaving the query alone when nothing matches.
		 * An empty post__in is ignored by WP_Query, which would answer a
		 * failed search with every home on the site — the worst possible
		 * reply, because it looks like a result.
		 */
		$query->set( 'post__in', $ids ?: [ 0 ] );
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

		$ids = array_merge(
			array_map( 'intval', (array) $by_text ),
			array_map( 'intval', (array) $by_place ),
			array_map( 'intval', (array) $by_zip )
		);

		return array_values( array_unique( $ids ) );
	}
}
