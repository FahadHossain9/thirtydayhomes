<?php
/**
 * `wp tdh geocode` — look up addresses in bulk.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Run once after the maps key is first added, so everything saved before
 * it gets a location; run with --all after changing provider.
 */
final class Geocode_Command {

	/**
	 * Looks up the location of homes and facilities from their addresses.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Look up every record again, not only those without a location.
	 * Coordinates typed by hand are kept when Google cannot place the address.
	 *
	 * [--type=<type>]
	 * : Which records.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - listing
	 *   - facility
	 * ---
	 *
	 * [--dry-run]
	 * : List what would be looked up, without looking anything up.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tdh geocode
	 *     wp tdh geocode --type=facility
	 *     wp tdh geocode --all --dry-run
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc
	 */
	public function __invoke( array $args, array $assoc ): void {

		$all   = ! empty( $assoc['all'] );
		$dry   = ! empty( $assoc['dry-run'] );
		$type  = (string) ( $assoc['type'] ?? 'all' );
		$types = match ( $type ) {
			'listing'  => [ Post_Types::LISTING ],
			'facility' => [ Post_Types::FACILITY ],
			default    => [ Post_Types::LISTING, Post_Types::FACILITY ],
		};

		if ( ! $dry && ! Geocoder::configured() ) {
			\WP_CLI::error( 'No maps key: define TDH_MAPS_SERVER_KEY in wp-config.php first.' );
		}

		$counts = [ 'found' => 0, 'manual' => 0, 'failed' => 0, 'pending' => 0 ];
		$seen   = 0;

		foreach ( self::records( $types ) as $id ) {

			if ( ! $all && null !== Geocoder::coordinates( $id ) ) {
				continue;
			}

			++$seen;
			$line = sprintf( '#%d %s — %s', $id, get_the_title( $id ), '' !== Geocoder::address( $id ) ? Geocoder::address( $id ) : '(no street address)' );

			if ( $dry ) {
				\WP_CLI::log( $line );
				continue;
			}

			// The short pause after "slow down" is for page saves; here the
			// command paces itself instead.
			delete_transient( 'tdh_geocode_pause' );
			$state = Geocoder::geocode( $id, 'force' );
			++$counts[ $state ];

			\WP_CLI::log( $line . ' → ' . Geocoder::chip( $state )['label'] );
			usleep( 100000 );
		}

		if ( $dry ) {
			\WP_CLI::success( sprintf( '%d to look up. Nothing was changed.', $seen ) );
			return;
		}

		\WP_CLI::success(
			sprintf(
				'%d looked up: %d found, %d kept (set by hand), %d not found, %d not checked.',
				$seen,
				$counts['found'],
				$counts['manual'],
				$counts['failed'],
				$counts['pending']
			)
		);
	}

	/**
	 * Every home and facility that could have a location.
	 *
	 * @param string[] $types
	 * @return int[]
	 */
	private static function records( array $types ): array {

		$query = new \WP_Query(
			[
				'post_type'             => $types,
				'post_status'           => [ 'publish', 'pending', 'draft', 'private', Statuses::PAUSED, Statuses::REJECTED, Statuses::BILLING_HOLD ],
				'posts_per_page'        => -1,
				'fields'                => 'ids',
				'orderby'               => 'ID',
				'order'                 => 'ASC',
				'tdh_bypass_visibility' => true,
			]
		);

		return array_map( 'intval', $query->posts );
	}
}
