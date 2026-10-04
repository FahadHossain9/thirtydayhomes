<?php
/**
 * The Listings table in wp-admin, and the housekeeping around it.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Admin;

use TDH\Accounts;
use TDH\Geocoder;
use TDH\Post_Types;
use TDH\Statuses;

defined( 'ABSPATH' ) || exit;

/**
 * Listings › All Listings (edit.php?post_type=tdh_listing), for the site
 * administrator (G5b design review).
 *
 * The portal's Listings screen is where the team works; this table is the
 * administrator's back door, and it said almost nothing: two of thirteen
 * titles carried " — Pending", Author showed a username, and rent, landlord
 * and map point were not there at all. Now: a Status pill in the portal's
 * words, Landlord, Rent, Map point, a photo, and one Updated date. Staff
 * without manage_options are sent to the portal instead, which does this
 * job better for them.
 *
 * Also quiets Elementor's "Want to shape the future of web creation?"
 * notice once for the whole site: its purple button was the loudest control
 * on every operations screen.
 */
final class Listing_Table {

	public function register(): void {
		add_filter( 'manage_' . Post_Types::LISTING . '_posts_columns', [ $this, 'columns' ] );
		add_action( 'manage_' . Post_Types::LISTING . '_posts_custom_column', [ $this, 'cell' ], 10, 2 );
		add_filter( 'manage_edit-' . Post_Types::LISTING . '_sortable_columns', [ $this, 'sortable' ] );
		add_action( 'pre_get_posts', [ $this, 'sort' ] );
		add_filter( 'display_post_states', [ $this, 'post_states' ], 10, 2 );
		add_action( 'load-edit.php', [ $this, 'send_staff_to_portal' ] );
		add_action( 'admin_head-edit.php', [ $this, 'print_styles' ] );
		add_action( 'admin_init', [ $this, 'quiet_elementor' ] );
	}

	/* ---------------------------------------------------------------------
	 * Columns
	 * ------------------------------------------------------------------ */

	/**
	 * The columns, in reading order: photo, title, status, landlord, rent,
	 * map point, the two taxonomies that vary, updated. Author (a username),
	 * Date (two lines of "Published"/"Last Modified") and City (one city)
	 * go.
	 *
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public function columns( array $columns ): array {

		$ours = [
			'tdh_status'   => __( 'Status', 'thirtydayhomes' ),
			'tdh_landlord' => __( 'Landlord', 'thirtydayhomes' ),
			'tdh_rent'     => __( 'Rent', 'thirtydayhomes' ),
			'tdh_point'    => __( 'Map point', 'thirtydayhomes' ),
		];

		$photo = '<span class="screen-reader-text">' . esc_html__( 'Photo', 'thirtydayhomes' ) . '</span>';

		$drop = [ 'author', 'date', 'taxonomy-' . Post_Types::TAX_CITY ];

		// Sentence case for the taxonomy heads, like every other label.
		$renamed = [
			'taxonomy-' . Post_Types::TAX_TYPE         => __( 'Property type', 'thirtydayhomes' ),
			'taxonomy-' . Post_Types::TAX_NEIGHBORHOOD => __( 'Neighborhood', 'thirtydayhomes' ),
		];

		$out = [];

		foreach ( $columns as $key => $label ) {

			if ( in_array( $key, $drop, true ) ) {
				continue;
			}

			if ( 'cb' === $key ) {
				$out['cb']        = $label;
				$out['tdh_photo'] = $photo;
				continue;
			}

			$out[ $key ] = $renamed[ $key ] ?? $label;

			if ( 'title' === $key ) {
				$out = array_merge( $out, $ours );
			}
		}

		// A table with no title column (nothing does that) still gets ours.
		foreach ( [ 'tdh_photo' => $photo ] + $ours as $key => $label ) {
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $label;
			}
		}

		$out['tdh_updated'] = __( 'Updated', 'thirtydayhomes' );

		return $out;
	}

	/**
	 * One cell of ours.
	 */
	public function cell( string $column, int $post_id ): void {

		switch ( $column ) {

			case 'tdh_photo':
				if ( has_post_thumbnail( $post_id ) ) {
					echo get_the_post_thumbnail( $post_id, [ 96, 96 ], [ 'alt' => '', 'class' => 'tdh-table-photo' ] ); // phpcs:ignore WordPress.Security.EscapeOutput
				} else {
					echo '<span class="tdh-table-photo is-none" aria-hidden="true"></span>';
				}
				break;

			case 'tdh_status':
				$status = (string) ( get_post_status( $post_id ) ?: 'draft' );
				$tones  = [
					'publish'              => 'live',
					'pending'              => 'pending',
					'draft'                => 'neutral',
					Statuses::PAUSED       => 'neutral',
					Statuses::REJECTED     => 'danger',
					Statuses::BILLING_HOLD => 'warning',
				];
				self::pill( $tones[ $status ] ?? 'neutral', Statuses::all()[ $status ] ?? ucfirst( $status ) );
				break;

			case 'tdh_landlord':
				$author = (int) get_post_field( 'post_author', $post_id );
				$user   = $author > 0 ? get_userdata( $author ) : false;

				if ( $user ) {
					printf(
						'<a href="%s">%s</a>',
						esc_url( add_query_arg( [ 'post_type' => Post_Types::LISTING, 'author' => $author ], admin_url( 'edit.php' ) ) ),
						esc_html( $user->display_name )
					);
				} else {
					echo '<span class="tdh-table-none">' . esc_html__( 'No landlord', 'thirtydayhomes' ) . '</span>';
				}
				break;

			case 'tdh_rent':
				$rent = get_post_meta( $post_id, '_tdh_price_monthly', true );

				if ( is_numeric( $rent ) && (float) $rent > 0 ) {
					echo '<span class="tdh-table-rent">$' . esc_html( number_format_i18n( (float) $rent ) ) . '</span>';
				} else {
					echo '<span class="tdh-table-none">—</span>';
				}
				break;

			case 'tdh_point':
				[ $tone, $word ] = match ( Geocoder::state( $post_id ) ) {
					'found', 'manual' => [ 'live', __( 'Set', 'thirtydayhomes' ) ],
					'failed'          => [ 'danger', __( 'Not found', 'thirtydayhomes' ) ],
					default           => [ 'warning', __( 'Not set', 'thirtydayhomes' ) ],
				};
				self::pill( $tone, $word );
				break;

			case 'tdh_updated':
				$time = (int) get_post_modified_time( 'U', true, $post_id );
				printf(
					'<time datetime="%s" title="%s">%s</time>',
					esc_attr( gmdate( 'c', $time ) ),
					esc_attr( wp_date( 'j M Y, g:i A', $time ) ),
					esc_html( wp_date( 'j M Y', $time ) )
				);
				break;
		}
	}

	/**
	 * No " — Pending" / " — Draft" after a listing's title: the Status
	 * column says it once, in the portal's words, for every status.
	 *
	 * @param array<string,string> $states
	 * @return array<string,string>
	 */
	public function post_states( array $states, ?\WP_Post $post ): array {
		return $post && Post_Types::LISTING === $post->post_type ? [] : $states;
	}

	/** A worded pill, never a colour alone. */
	private static function pill( string $tone, string $word ): void {
		printf( '<span class="tdh-pill is-%s">%s</span>', esc_attr( $tone ), esc_html( $word ) );
	}

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public function sortable( array $columns ): array {
		$columns['tdh_rent']    = 'tdh_rent';
		$columns['tdh_updated'] = 'modified';

		return $columns;
	}

	/** Rent sorts as a number, not as text ("900" after "2,400"). */
	public function sort( \WP_Query $query ): void {

		if ( ! is_admin() || ! $query->is_main_query() || Post_Types::LISTING !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( 'tdh_rent' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', '_tdh_price_monthly' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$query->set( 'orderby', 'meta_value_num' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Who belongs here
	 * ------------------------------------------------------------------ */

	/**
	 * A staff member who is not an administrator is sent to the portal's
	 * Listings: the same homes, with Approve and Set location on the row.
	 */
	public function send_staff_to_portal(): void {

		$type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['post_type'] ) ) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( Post_Types::LISTING !== $type ) {
			return;
		}

		$to = self::staff_destination();

		if ( null === $to ) {
			return;
		}

		wp_safe_redirect( $to );
		exit;
	}

	/**
	 * Where the current user belongs instead of this table, or null when
	 * the table is theirs (an administrator) or none of their business
	 * (WordPress refuses them itself).
	 */
	public static function staff_destination(): ?string {

		if ( ! Accounts::is_staff() || current_user_can( 'manage_options' ) ) {
			return null;
		}

		return add_query_arg( 'view', 'listings', Accounts::url( 'account' ) );
	}

	/* ---------------------------------------------------------------------
	 * Housekeeping
	 * ------------------------------------------------------------------ */

	/**
	 * Elementor's tracking opt-in notice, dismissed for the whole site.
	 * The option is the one its own "No thanks" writes; harmless when
	 * Elementor is absent.
	 */
	public function quiet_elementor(): void {

		if ( '1' !== (string) get_option( 'elementor_tracker_notice' ) ) {
			update_option( 'elementor_tracker_notice', '1', false );
		}
	}

	/**
	 * The little the table needs beyond WordPress's own styles: the photo,
	 * worded pills, right-aligned rent, and on phones the photo and status
	 * kept beside the title while the rest fold under "Show more details".
	 * Scoped to this post type's screen; nothing else is touched.
	 */
	public function print_styles(): void {

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || Post_Types::LISTING !== $screen->post_type ) {
			return;
		}
		?>
		<style>
			.post-type-tdh_listing .wp-list-table .column-tdh_photo { width: 56px; }
			.post-type-tdh_listing .wp-list-table .column-tdh_status,
			.post-type-tdh_listing .wp-list-table .column-tdh_point { width: 10em; }
			.post-type-tdh_listing .wp-list-table .column-tdh_landlord { width: 12em; }
			.post-type-tdh_listing .wp-list-table .column-tdh_rent { width: 6em; text-align: right; }
			.post-type-tdh_listing .wp-list-table .column-tdh_updated { width: 8em; }
			.post-type-tdh_listing .tdh-table-photo { display: block; width: 48px; height: 48px; border-radius: 4px; object-fit: cover; }
			.post-type-tdh_listing .tdh-table-photo.is-none { background: #f0f0f1; }
			.post-type-tdh_listing .tdh-table-rent { font-variant-numeric: tabular-nums; }
			.post-type-tdh_listing .tdh-table-none { color: #646970; }
			.post-type-tdh_listing .tdh-pill { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #f0f0f1; color: #1d2327; font-size: 12px; font-weight: 600; line-height: 16px; white-space: nowrap; }
			.post-type-tdh_listing .tdh-pill.is-live { background: #edfaef; color: #00700f; }
			.post-type-tdh_listing .tdh-pill.is-pending,
			.post-type-tdh_listing .tdh-pill.is-warning { background: #fcf9e8; color: #8a6d00; }
			.post-type-tdh_listing .tdh-pill.is-danger { background: #fcf0f1; color: #b32d2e; }
			@media ( max-width: 782px ) {
				.post-type-tdh_listing .wp-list-table th.column-tdh_photo { display: none; }
				.post-type-tdh_listing .wp-list-table tr:not(.inline-edit-row):not(.no-items) td.column-tdh_photo { display: block !important; float: left; width: 48px; padding: 8px 8px 8px 10px; }
				.post-type-tdh_listing .wp-list-table tr:not(.inline-edit-row):not(.no-items) td.column-tdh_photo::before { display: none; }
				/* The row is a wrapped flex card here and WordPress puts its Edit / Trash links under the title, so the
				   status is the card's last line, aligned with the title's text (check column + photo cell). The
				   selector repeats WordPress's own (th.column-primary ~ td) to outrank its 35% padding. */
				.post-type-tdh_listing .wp-list-table tr:not(.inline-edit-row):not(.no-items) th.column-primary ~ td.column-tdh_status { display: block !important; clear: none; padding: 0 8px 10px 111px; }
				/* WordPress keeps its Edit / Trash links off-screen but still 70px tall under the title until the row is
				   expanded; without that blank the status sits straight under the title. Expanding the row brings the links back. */
				.post-type-tdh_listing .wp-list-table tr:not(.inline-edit-row):not(.no-items):not(.is-expanded) th.column-primary > .row-actions { display: none; }
				.post-type-tdh_listing .wp-list-table tr:not(.inline-edit-row):not(.no-items) th.column-primary ~ td.column-tdh_status::before { display: none; }
			}
		</style>
		<?php
	}
}
