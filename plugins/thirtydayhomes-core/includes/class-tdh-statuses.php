<?php
/**
 * Custom listing statuses.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Listing lifecycle.
 *
 * Core gives us draft, pending and publish. Three more are needed:
 *
 *   tdh_paused       the landlord took it down deliberately
 *   tdh_rejected     an administrator rejected it, with a reason
 *   tdh_billing_hold the system hid it because membership lapsed
 *
 * tdh_billing_hold is deliberately NOT the same as tdh_paused. To a renter
 * they look identical, but on renewal they must behave completely
 * differently: we restore only billing holds. Without the distinction, a
 * landlord who paused one of their three homes last week would find it
 * silently republished the moment their card was fixed.
 *
 * Because these are real post statuses, the standard admin list table, its
 * status filters and its bulk actions all work with no extra UI to build.
 */
final class Statuses {

	public const PAUSED       = 'tdh_paused';
	public const REJECTED     = 'tdh_rejected';
	public const BILLING_HOLD = 'tdh_billing_hold';

	public function register(): void {
		add_action( 'init', [ $this, 'register_statuses' ], 7 );
		add_action( 'admin_footer-post.php', [ $this, 'inject_status_options' ] );
		add_action( 'admin_footer-edit.php', [ $this, 'inject_status_options' ] );
	}

	/**
	 * All statuses a listing may hold.
	 *
	 * @return array<string,string> status => human label
	 */
	public static function all(): array {
		return [
			'draft'            => __( 'Draft', 'thirtydayhomes' ),
			'pending'          => __( 'Pending review', 'thirtydayhomes' ),
			'publish'          => __( 'Live', 'thirtydayhomes' ),
			self::PAUSED       => __( 'Paused', 'thirtydayhomes' ),
			self::REJECTED     => __( 'Rejected', 'thirtydayhomes' ),
			self::BILLING_HOLD => __( 'Hidden — membership', 'thirtydayhomes' ),
		];
	}

	/**
	 * The only status that is ever publicly visible.
	 *
	 * Membership is checked separately — see Visibility.
	 */
	public static function public_status(): string {
		return 'publish';
	}

	public function register_statuses(): void {

		register_post_status(
			self::PAUSED,
			[
				'label'                     => _x( 'Paused', 'listing status', 'thirtydayhomes' ),
				'public'                    => false,
				'internal'                  => false,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of paused listings */
				'label_count'               => _n_noop( 'Paused <span class="count">(%s)</span>', 'Paused <span class="count">(%s)</span>', 'thirtydayhomes' ),
			]
		);

		register_post_status(
			self::REJECTED,
			[
				'label'                     => _x( 'Rejected', 'listing status', 'thirtydayhomes' ),
				'public'                    => false,
				'internal'                  => false,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of rejected listings */
				'label_count'               => _n_noop( 'Rejected <span class="count">(%s)</span>', 'Rejected <span class="count">(%s)</span>', 'thirtydayhomes' ),
			]
		);

		register_post_status(
			self::BILLING_HOLD,
			[
				'label'                     => _x( 'Hidden — membership', 'listing status', 'thirtydayhomes' ),
				'public'                    => false,
				'internal'                  => false,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of listings hidden for billing */
				'label_count'               => _n_noop( 'Hidden — membership <span class="count">(%s)</span>', 'Hidden — membership <span class="count">(%s)</span>', 'thirtydayhomes' ),
			]
		);
	}

	/**
	 * Add the custom statuses to the classic editor's status dropdown.
	 *
	 * WordPress has no API for this, so a small inline script is the
	 * conventional approach. Restricted to the listing post type.
	 */
	public function inject_status_options(): void {
		global $post;

		$screen = get_current_screen();
		if ( ! $screen || Post_Types::LISTING !== $screen->post_type ) {
			return;
		}

		$custom  = [ self::PAUSED, self::REJECTED, self::BILLING_HOLD ];
		$current = $post->post_status ?? '';
		$labels  = self::all();

		$options = '';
		foreach ( $custom as $status ) {
			$options .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $status ),
				selected( $current, $status, false ),
				esc_html( $labels[ $status ] )
			);
		}

		$selected_label = $labels[ $current ] ?? '';
		?>
		<script>
		jQuery( function ( $ ) {
			$( 'select#post_status' ).append( <?php echo wp_json_encode( $options ); ?> );
			<?php if ( in_array( $current, $custom, true ) ) : ?>
			$( '#post-status-display' ).text( <?php echo wp_json_encode( $selected_label ); ?> );
			<?php endif; ?>
		} );
		</script>
		<?php
	}
}
