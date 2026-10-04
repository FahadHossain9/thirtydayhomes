<?php
/**
 * The landlord's list of homes, and what each one lets them do.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * ─── ONE LINE AND ONE MAIN ACTION PER STATE ──────────────────────────────
 *
 * The list used to show a title, a date and a badge — the status was named
 * and nothing more. A landlord looking at "Changes requested" could not
 * see what to change, and nothing on the row could pause, resume or delete.
 *
 * Every home now says, in words, what its state means and what happens
 * next, and offers exactly one primary action for that state:
 *
 *   Draft              Continue editing / Review and submit
 *   In review          Preview                (Edit beside it)
 *   Live               View live page         (Edit, Pause, Delete beside it)
 *   Paused             Resume, or Manage billing when the membership lapsed
 *                                             (Edit, Preview beside it)
 *   Changes requested  Edit and resubmit      (the reason shown above it)
 *   Hidden — payment   Manage billing
 *
 * Delete asks first, in the page: the row opens a panel naming the home and
 * what is kept, with the destructive button in the danger colour and "Keep
 * it" beside it. The panel is a server-rendered state (?delete=ID), so it
 * works with no JavaScript, survives a reload, and Back simply closes it.
 *
 * Availability opens the same way (?availability=ID): "Available from" and
 * the unavailable periods, saved without going through the wizard
 * (TDH\Availability_Render::panel()).
 *
 * Handlers: TDH\Listing_Actions. Look: theme style.css "LANDLORD LISTINGS".
 */
final class Listing_Manage_Render {

	/** Homes per page on the My listings screen. */
	public const PER_PAGE = 10;

	/** Homes shown on the overview before "View all". */
	public const OVERVIEW_ROWS = 5;

	/** Statuses a landlord's list shows. Trash is gone from their view. */
	public static function statuses(): array {
		return array_values( array_unique( array_merge( [ 'publish', 'pending', 'draft' ], array_keys( Statuses::all() ) ) ) );
	}

	/**
	 * Print the list for this landlord.
	 *
	 * @param string $context 'overview' (a few rows) or 'listings' (paged).
	 */
	/**
	 * The status groups the My listings chips offer, in the order a landlord
	 * thinks of them. Each is a label and the statuses it holds.
	 *
	 * @return array<string,array{0:string,1:string[]}>
	 */
	public static function chip_groups(): array {
		return [
			'live'     => [ __( 'Live', 'thirtydayhomes' ), [ 'publish' ] ],
			'review'   => [ __( 'In review', 'thirtydayhomes' ), [ 'pending' ] ],
			'changes'  => [ __( 'Changes requested', 'thirtydayhomes' ), [ Statuses::REJECTED ] ],
			'paused'   => [ __( 'Paused', 'thirtydayhomes' ), [ Statuses::PAUSED ] ],
			'hidden'   => [ __( 'Hidden — payment', 'thirtydayhomes' ), [ Statuses::BILLING_HOLD ] ],
			'draft'    => [ __( 'Draft', 'thirtydayhomes' ), [ 'draft' ] ],
		];
	}

	/** The chip asked for on My listings, or '' for All. */
	public static function requested_chip(): string {
		$chip = isset( $_GET['show'] ) ? sanitize_key( wp_unslash( (string) $_GET['show'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return array_key_exists( $chip, self::chip_groups() ) ? $chip : '';
	}

	/** How many of this landlord's homes are in these statuses. */
	private static function count_in( int $user_id, array $statuses ): int {
		$query = new \WP_Query(
			[
				'post_type'             => Post_Types::LISTING,
				'author'                => $user_id,
				'post_status'           => $statuses,
				'posts_per_page'        => 1,
				'fields'                => 'ids',
				'tdh_bypass_visibility' => true,
			]
		);
		return (int) $query->found_posts;
	}

	/**
	 * The My listings chips (G4a design review): All and every status that
	 * has a home in it, each with its count, as links that filter the list.
	 * A status with nothing in it is not offered — a chip that can only show
	 * an empty list is a dead end. The allowance sits at the end of the row.
	 */
	public static function status_chips( int $user_id, string $usage ): void {

		$current = self::requested_chip();
		$base    = add_query_arg( 'view', 'listings', Accounts::url( 'account' ) );
		$all     = self::count_in( $user_id, self::statuses() );
		?>
		<div class="manage-chips">
			<nav class="portal-status-filters" aria-label="<?php esc_attr_e( 'Show homes by status', 'thirtydayhomes' ); ?>">
				<a href="<?php echo esc_url( $base ); ?>" class="<?php echo '' === $current ? 'is-current' : ''; ?>"<?php echo '' === $current ? ' aria-current="true"' : ''; ?>>
					<?php esc_html_e( 'All', 'thirtydayhomes' ); ?> <b><?php echo esc_html( number_format_i18n( $all ) ); ?></b>
				</a>
				<?php foreach ( self::chip_groups() as $key => [ $label, $statuses ] ) : ?>
					<?php
					$n = self::count_in( $user_id, $statuses );
					if ( 0 === $n && $key !== $current ) {
						continue;
					}
					?>
					<a href="<?php echo esc_url( add_query_arg( 'show', $key, $base ) ); ?>" class="<?php echo $key === $current ? 'is-current' : ''; ?>"<?php echo $key === $current ? ' aria-current="true"' : ''; ?>>
						<?php echo esc_html( $label ); ?> <b><?php echo esc_html( number_format_i18n( $n ) ); ?></b>
					</a>
				<?php endforeach; ?>
			</nav>
			<?php if ( '' !== $usage ) : ?>
				<span class="panel-note"><?php echo esc_html( $usage ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render( int $user_id, string $context ): void {

		$context  = 'overview' === $context ? 'overview' : 'listings';
		$per_page = 'overview' === $context ? self::OVERVIEW_ROWS : self::PER_PAGE;
		$page     = 'listings' === $context ? self::requested_page() : 1;
		$chip     = 'listings' === $context ? self::requested_chip() : '';

		$args = [
			'post_type'             => Post_Types::LISTING,
			'author'                => $user_id,
			'post_status'           => '' !== $chip ? self::chip_groups()[ $chip ][1] : self::statuses(),
			'posts_per_page'        => $per_page,
			'paged'                 => $page,
			'orderby'               => [ 'date' => 'DESC', 'ID' => 'DESC' ],
			'tdh_bypass_visibility' => true,
		];

		$query = new \WP_Query( $args );

		// Past the last page — a Delete emptied it, or an old link — shows the
		// last page that exists rather than an empty list claiming no homes.
		// Counted from page one: WordPress reports no total for an empty page.
		if ( ! $query->have_posts() && $page > 1 ) {
			$first = new \WP_Query( array_merge( $args, [ 'paged' => 1, 'fields' => 'ids' ] ) );

			if ( $first->found_posts > 0 ) {
				$page          = (int) $first->max_num_pages;
				$args['paged'] = $page;
				$query         = new \WP_Query( $args );
			}
		}

		if ( ! $query->have_posts() ) {
			if ( '' !== $chip ) {
				// A filter with nothing in it says so and offers the way back,
				// rather than the "add your first home" welcome (G4a).
				?>
				<p class="manage-none">
					<?php esc_html_e( 'No homes in this status.', 'thirtydayhomes' ); ?>
					<a href="<?php echo esc_url( add_query_arg( 'view', 'listings', Accounts::url( 'account' ) ) ); ?>"><?php esc_html_e( 'Show all homes', 'thirtydayhomes' ); ?></a>
				</p>
				<?php
				return;
			}
			self::empty_state();
			return;
		}

		$confirming = isset( $_GET['delete'] ) ? (int) $_GET['delete'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing    = isset( $_GET['availability'] ) ? (int) $_GET['availability'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<ul class="manage-list">
			<?php foreach ( $query->posts as $post ) : ?>
				<?php self::row( $post, $context, $page, $confirming === (int) $post->ID, $editing === (int) $post->ID ); ?>
			<?php endforeach; ?>
		</ul>
		<?php

		if ( 'overview' === $context && $query->found_posts > self::OVERVIEW_ROWS ) {
			?>
			<a class="manage-more" href="<?php echo esc_url( add_query_arg( 'view', 'listings', Accounts::url( 'account' ) ) ); ?>">
				<?php
				printf(
					/* translators: %s: number of homes */
					esc_html__( 'View all %s homes', 'thirtydayhomes' ),
					esc_html( number_format_i18n( (int) $query->found_posts ) )
				);
				?>
				<?php echo self::icon( 'arrow-right', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</a>
			<?php
		}

		if ( 'listings' === $context && $query->max_num_pages > 1 ) {
			self::pagination( $page, (int) $query->max_num_pages );
		}
	}

	/** The page asked for on the My listings screen, at least 1. */
	public static function requested_page(): int {
		return max( 1, (int) ( $_GET['listings_page'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/** The address of this list, on the page a row sits on. */
	public static function list_url( string $context, int $page ): string {

		if ( 'overview' === $context ) {
			return Accounts::url( 'account' );
		}

		$url = add_query_arg( 'view', 'listings', Accounts::url( 'account' ) );

		// An action taken on a filtered list comes back to the same filter.
		$chip = self::requested_chip();
		if ( '' !== $chip ) {
			$url = add_query_arg( 'show', $chip, $url );
		}

		return $page > 1 ? add_query_arg( 'listings_page', $page, $url ) : $url;
	}

	/**
	 * What a state means and what to do about it.
	 *
	 * @return array{label:string,badge:string,line:string,primary:array,secondary:array}
	 */
	public static function state( \WP_Post $post ): array {

		$id      = (int) $post->ID;
		$preview = [ 'link', __( 'Preview', 'thirtydayhomes' ), (string) get_preview_post_link( $post ) ];
		$billing = [ 'link', __( 'Manage billing', 'thirtydayhomes' ), add_query_arg( 'view', 'membership', Accounts::url( 'account' ) ) ];
		$edit    = [ 'link', __( 'Edit', 'thirtydayhomes' ), Listing_Form::url( 1, $id ) ];

		switch ( $post->post_status ) {

			case 'publish':
				return [
					'label'     => __( 'Live', 'thirtydayhomes' ),
					'badge'     => 'live',
					/* translators: %s: date */
					'line'      => sprintf( __( 'Live since %s. Renters can find it.', 'thirtydayhomes' ), self::date( self::live_since( $post ) ) ),
					// Edit is what a landlord comes here to do; the live page is
					// one click away as a quiet link (G2 design review).
					'primary'   => $edit,
					'secondary' => [ [ 'link', __( 'View live page', 'thirtydayhomes' ), (string) get_permalink( $post ) ], [ 'post', __( 'Pause', 'thirtydayhomes' ), 'listing_pause' ] ],
				];

			case 'pending':
				$submitted = (string) get_post_meta( $id, '_tdh_submitted_at', true );
				$edits     = (string) get_post_meta( $id, '_tdh_reviewing_edits', true );

				if ( '' !== $edits ) {
					// Edited after it was approved; off the site while that is checked.
					$what = Listing_Form::changes_phrase( $edits );
					$line = '' !== $submitted
						/* translators: 1: date, 2: what changed e.g. "the title and photos" */
						? sprintf( __( 'Submitted %1$s. Your changes to the %2$s are being checked — usually within one business day. It’s off the site until they’re approved.', 'thirtydayhomes' ), self::date( $submitted ), $what )
						/* translators: %s: what changed */
						: sprintf( __( 'Your changes to the %s are being checked — usually within one business day. It’s off the site until they’re approved.', 'thirtydayhomes' ), $what );
				} else {
					$line = '' !== $submitted
						/* translators: %s: date */
						? sprintf( __( 'Submitted %s. A person reviews every home, usually within one business day.', 'thirtydayhomes' ), self::date( $submitted ) )
						: __( 'A person reviews every home, usually within one business day.', 'thirtydayhomes' );
				}

				return [
					'label'     => __( 'In review', 'thirtydayhomes' ),
					'badge'     => 'pending',
					'line'      => $line,
					'primary'   => $preview,
					'secondary' => [ [ 'link', __( 'Edit', 'thirtydayhomes' ), Listing_Form::url( 4, $id ) ] ],
				];

			case Statuses::PAUSED:
				$can_resume = Listing_Actions::owner_can_publish( (int) $post->post_author );
				$paused     = (string) get_post_meta( $id, '_tdh_paused_at', true );
				$edited     = (string) get_post_meta( $id, '_tdh_edited_while_paused', true );

				if ( ! $can_resume ) {
					$line = __( 'Your membership isn’t active, so this home can’t go live again yet.', 'thirtydayhomes' );
				} elseif ( '' !== $paused ) {
					/* translators: %s: date */
					$line = sprintf( __( 'Paused on %s. Hidden from renters until you resume it.', 'thirtydayhomes' ), self::date( $paused ) );
				} else {
					$line = __( 'Hidden from renters until you resume it.', 'thirtydayhomes' );
				}

				if ( $can_resume && '' !== $edited ) {
					/* translators: %s: what changed e.g. "the title and bedrooms" */
					$line .= ' ' . sprintf( __( 'You changed the %s while it was paused, so Resume sends it for a quick review before it goes live again.', 'thirtydayhomes' ), Listing_Form::changes_phrase( $edited ) );
				}

				return [
					'label'     => __( 'Paused', 'thirtydayhomes' ),
					'badge'     => 'inactive',
					'line'      => $line,
					'primary'   => $can_resume ? [ 'post', __( 'Resume', 'thirtydayhomes' ), 'listing_resume' ] : $billing,
					'secondary' => [ $edit, $preview ],
				];

			case Statuses::REJECTED:
				return [
					'label'     => __( 'Changes requested', 'thirtydayhomes' ),
					'badge'     => 'rejected',
					'line'      => __( 'Our team asked for changes before it can go live. Make them, then resubmit.', 'thirtydayhomes' ),
					'primary'   => [ 'link', __( 'Edit and resubmit', 'thirtydayhomes' ), Listing_Form::url( 4, $id ) ],
					'secondary' => [ $preview ],
				];

			case Statuses::BILLING_HOLD:
				return [
					'label'     => __( 'Hidden — payment', 'thirtydayhomes' ),
					'badge'     => 'past_due',
					// Says why in the same words as the membership band (E1).
					'line'      => Membership::PAST_DUE === Membership::status( (int) $post->post_author )
						? __( 'Hidden because a payment failed. Update your card and it comes back on its own.', 'thirtydayhomes' )
						: __( 'Hidden because the plan has ended. Restart a plan and it comes back on its own.', 'thirtydayhomes' ),
					'primary'   => $billing,
					'secondary' => [ $preview ],
				];

			default: // draft, and anything unexpected, is still work in progress.
				$missing = Listing_Form::missing_for_submit( $id );
				$first   = $missing ? (int) $missing[0]['step'] : 4;
				return [
					'label'     => __( 'Draft', 'thirtydayhomes' ),
					'badge'     => 'inactive',
					'line'      => $missing
						/* translators: %s: number of missing answers */
						? sprintf( _n( 'Not submitted yet. %s answer still needed.', 'Not submitted yet. %s answers still needed.', count( $missing ), 'thirtydayhomes' ), number_format_i18n( count( $missing ) ) )
						: __( 'Complete. Submit it for review when you are ready.', 'thirtydayhomes' ),
					'primary'   => [ 'link', $missing ? __( 'Continue editing', 'thirtydayhomes' ) : __( 'Review and submit', 'thirtydayhomes' ), Listing_Form::url( $first, $id ) ],
					'secondary' => [ $preview ],
				];
		}
	}

	/**
	 * Homes whose availability can be changed from the row: every home past
	 * the draft stage that the wizard can also open. A draft is edited in the
	 * wizard it is already in; a payment-held home waits for billing.
	 */
	public static function has_availability( \WP_Post $post ): bool {
		return in_array( $post->post_status, [ 'publish', Statuses::PAUSED, 'pending', Statuses::REJECTED ], true );
	}

	/**
	 * One home.
	 */
	private static function row( \WP_Post $post, string $context, int $page, bool $confirming, bool $editing = false ): void {

		$id      = (int) $post->ID;
		$state   = self::state( $post );
		$here    = self::list_url( $context, $page );
		$editing = $editing && ! $confirming && self::has_availability( $post );

		// Beside Edit, where a landlord looks for "change something".
		if ( self::has_availability( $post ) ) {
			array_splice( $state['secondary'], 1, 0, [ [ 'link', __( 'Availability', 'thirtydayhomes' ), add_query_arg( 'availability', $id, $here ) . '#listing-' . $id ] ] );
		}

		$rent = (float) get_post_meta( $id, '_tdh_price_monthly', true );
		$hood = get_the_terms( $id, Post_Types::TAX_NEIGHBORHOOD );
		$city = get_the_terms( $id, Post_Types::TAX_CITY );
		// The rent is the figure a landlord scans for, so it stands apart;
		// the place follows it with a pin, as on the renter's card.
		$place = implode(
			' · ',
			array_filter(
				[
					$hood && ! is_wp_error( $hood ) ? $hood[0]->name : '',
					$city && ! is_wp_error( $city ) ? $city[0]->name : '',
				]
			)
		);
		$meta  = $rent > 0 || '' !== $place;

		$title  = get_the_title( $post );
		$title  = '' !== trim( $title ) ? $title : __( 'Untitled home', 'thirtydayhomes' );
		$reason = Statuses::REJECTED === $post->post_status ? trim( (string) get_post_meta( $id, '_tdh_rejection_reason', true ) ) : '';
		?>
		<li class="manage-item<?php echo $confirming ? ' is-confirming' : ''; ?><?php echo $editing ? ' is-editing' : ''; ?>" id="listing-<?php echo esc_attr( (string) $id ); ?>">

			<span class="manage-media" aria-hidden="true">
				<?php if ( has_post_thumbnail( $post ) ) : ?>
					<?php echo get_the_post_thumbnail( $post, 'tdh-card', [ 'alt' => '' ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php else : ?>
					<i><?php echo self::icon( 'building-2', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
				<?php endif; ?>
			</span>

			<div class="manage-body">
				<div class="manage-head">
					<h4><?php echo esc_html( $title ); ?></h4>
					<span class="status <?php echo esc_attr( $state['badge'] ); ?>"><?php echo esc_html( $state['label'] ); ?></span>
				</div>

				<?php if ( $meta ) : ?>
					<p class="manage-meta">
						<?php if ( $rent > 0 ) : ?>
							<strong class="manage-rent"><?php echo esc_html( '$' . number_format_i18n( $rent ) ); ?><small><?php esc_html_e( '/mo', 'thirtydayhomes' ); ?></small></strong>
						<?php endif; ?>
						<?php if ( '' !== $place ) : ?>
							<span class="manage-place"><?php echo self::icon( 'map-pin', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html( $place ); ?></span>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<p class="manage-line"><?php echo esc_html( $state['line'] ); ?></p>

				<?php
				/*
				 * What the home has earned, per home (G4a): the reason a
				 * landlord opens this screen. Only once it has been public —
				 * a draft has no views to count.
				 */
				if ( in_array( $post->post_status, [ 'publish', Statuses::PAUSED, Statuses::BILLING_HOLD ], true ) || '' !== (string) get_post_meta( $id, '_tdh_live_since', true ) ) :
					$seen  = Views::for_listing( $id );
					$asked = self::inquiry_count( $id );
					?>
					<p class="manage-stats">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: "12 views", 2: "3 inquiries" */
								__( '%1$s · %2$s', 'thirtydayhomes' ),
								/* translators: %s: number of views */
								sprintf( _n( '%s view', '%s views', $seen, 'thirtydayhomes' ), number_format_i18n( $seen ) ),
								/* translators: %s: number of inquiries */
								sprintf( _n( '%s inquiry', '%s inquiries', $asked, 'thirtydayhomes' ), number_format_i18n( $asked ) )
							)
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( '' !== $reason ) : ?>
					<div class="manage-reason">
						<b><?php esc_html_e( 'What to change', 'thirtydayhomes' ); ?></b>
						<p><?php echo nl2br( esc_html( $reason ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					</div>
				<?php endif; ?>

				<?php if ( $confirming ) : ?>
					<?php self::confirm( $post, $title, $context, $page, $here ); ?>
				<?php elseif ( $editing ) : ?>
					<?php Availability_Render::panel( $post, $title, $context, $page, $here ); ?>
				<?php else : ?>
					<?php
					/*
					 * The links (View live page, Availability, Preview) stay in
					 * the row; the actions that change the home's state — Pause,
					 * and Delete — sit in a labelled "More" on a phone, so a
					 * thumb reaching for Edit cannot land on them (G4a). The
					 * menu is printed open, and a phone's script closes it: on
					 * a desktop, or with no script, everything is in the row.
					 */
					$links   = array_filter( $state['secondary'], static fn( array $a ): bool => 'post' !== $a[0] );
					$changes = array_filter( $state['secondary'], static fn( array $a ): bool => 'post' === $a[0] );
					?>
					<div class="manage-actions">
						<?php self::action( $state['primary'], true, $id, $context, $page, $title ); ?>
						<?php foreach ( $links as $secondary ) : ?>
							<?php self::action( $secondary, false, $id, $context, $page, $title ); ?>
						<?php endforeach; ?>
						<details class="manage-overflow" open data-tdh-overflow>
							<summary>
								<?php esc_html_e( 'More', 'thirtydayhomes' ); ?>
								<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: listing title */ __( 'actions for %s', 'thirtydayhomes' ), $title ) ); ?></span>
							</summary>
							<div class="manage-overflow-items">
								<?php foreach ( $changes as $secondary ) : ?>
									<?php self::action( $secondary, false, $id, $context, $page, $title ); ?>
								<?php endforeach; ?>
								<a class="manage-delete" href="<?php echo esc_url( add_query_arg( 'delete', $id, $here ) . '#listing-' . $id ); ?>">
									<?php esc_html_e( 'Delete', 'thirtydayhomes' ); ?>
									<span class="screen-reader-text"><?php echo esc_html( $title ); ?></span>
								</a>
							</div>
						</details>
					</div>
				<?php endif; ?>
			</div>
		</li>
		<?php
	}

	/**
	 * A link, or a one-button form for an action that changes something.
	 *
	 * @param array{0:string,1:string,2:string} $action kind, label, url or action name.
	 */
	private static function action( array $action, bool $primary, int $id, string $context, int $page, string $title ): void {

		[ $kind, $label, $target ] = $action;

		// Outlined, not filled: the page keeps one filled button, "Add
		// property", and every card action ranks below it.
		$class = $primary ? 'manage-primary' : 'manage-link';

		if ( 'link' === $kind ) {
			?>
			<a class="<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $target ); ?>">
				<?php echo esc_html( $label ); ?>
				<?php if ( ! $primary ) : ?>
					<span class="screen-reader-text"><?php echo esc_html( $title ); ?></span>
				<?php endif; ?>
			</a>
			<?php
			return;
		}

		self::post_form( $target, $label, $class, $id, $context, $page, $title );
	}

	/**
	 * The form every state change posts through: action, home, nonce, and
	 * where to come back to.
	 */
	public static function post_form( string $action, string $label, string $class, int $id, string $context, int $page, string $title ): void {
		?>
		<form class="manage-form" method="post" action="<?php echo esc_url( self::list_url( $context, $page ) ); ?>">
			<input type="hidden" name="tdh_action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="tdh_listing" value="<?php echo esc_attr( (string) $id ); ?>">
			<input type="hidden" name="tdh_return" value="<?php echo esc_attr( $context ); ?>">
			<input type="hidden" name="tdh_page" value="<?php echo esc_attr( (string) $page ); ?>">
			<?php wp_nonce_field( Listing_Actions::NONCE, 'tdh_nonce' ); ?>
			<button class="<?php echo esc_attr( $class ); ?>" type="submit">
				<?php echo esc_html( $label ); ?>
				<span class="screen-reader-text"><?php echo esc_html( $title ); ?></span>
			</button>
		</form>
		<?php
	}

	/**
	 * "Delete Shadyside Loft?" — in the page, naming what is kept.
	 */
	private static function confirm( \WP_Post $post, string $title, string $context, int $page, string $cancel ): void {

		$id        = (int) $post->ID;
		$photos    = count( Listing_Photos::ordered( $id ) );
		$inquiries = self::inquiry_count( $id );
		$panel     = 'manage-confirm-' . $id;

		$kept = array_filter(
			[
				/* translators: %s: number of photos */
				$photos > 0 ? sprintf( _n( '%s photo', '%s photos', $photos, 'thirtydayhomes' ), number_format_i18n( $photos ) ) : '',
				/* translators: %s: number of inquiries */
				$inquiries > 0 ? sprintf( _n( '%s inquiry', '%s inquiries', $inquiries, 'thirtydayhomes' ), number_format_i18n( $inquiries ) ) : '',
			]
		);

		$gone = Statuses::public_status() === $post->post_status
			? __( 'It disappears from your dashboard and from renters straight away.', 'thirtydayhomes' )
			: __( 'It disappears from your dashboard straight away.', 'thirtydayhomes' );

		if ( ! EMPTY_TRASH_DAYS ) {
			$after = __( 'This can’t be undone.', 'thirtydayhomes' );
		} elseif ( $kept ) {
			$after = sprintf(
				/* translators: 1: "3 photos and 2 inquiries", 2: number of days */
				__( 'Its %1$s are kept, and our team can restore it for %2$s days if you change your mind.', 'thirtydayhomes' ),
				implode( __( ' and ', 'thirtydayhomes' ), $kept ),
				number_format_i18n( (int) EMPTY_TRASH_DAYS )
			);
		} else {
			$after = sprintf(
				/* translators: %s: number of days */
				__( 'Our team can restore it for %s days if you change your mind.', 'thirtydayhomes' ),
				number_format_i18n( (int) EMPTY_TRASH_DAYS )
			);
		}
		?>
		<div class="manage-confirm" id="<?php echo esc_attr( $panel ); ?>" role="group" aria-labelledby="<?php echo esc_attr( $panel ); ?>-title" data-cancel="<?php echo esc_url( $cancel . '#listing-' . $id ); ?>">
			<h5 id="<?php echo esc_attr( $panel ); ?>-title" tabindex="-1">
				<?php
				/* translators: %s: listing title */
				printf( esc_html__( 'Delete “%s”?', 'thirtydayhomes' ), esc_html( $title ) );
				?>
			</h5>
			<p><?php echo esc_html( $gone . ' ' . $after ); ?></p>
			<div class="manage-confirm-actions">
				<?php self::post_form( 'listing_delete', __( 'Delete listing', 'thirtydayhomes' ), 'danger', $id, $context, $page, '' ); ?>
				<a class="secondary" href="<?php echo esc_url( $cancel . '#listing-' . $id ); ?>"><?php esc_html_e( 'Keep it', 'thirtydayhomes' ); ?></a>
			</div>
		</div>
		<script>
		/* Focus moves into the question, so a keyboard or screen-reader user
		   lands on it; Escape means "Keep it". Without this script the panel
		   works the same, by its two buttons. */
		( function () {
			var panel = document.getElementById( <?php echo wp_json_encode( $panel ); ?> );
			if ( ! panel ) { return; }
			var title = panel.querySelector( 'h5' );
			if ( title ) { title.focus( { preventScroll: true } ); }
			panel.addEventListener( 'keydown', function ( event ) {
				if ( 'Escape' === event.key ) { window.location.href = panel.getAttribute( 'data-cancel' ); }
			} );
		} )();
		</script>
		<?php
	}

	/** Inquiries about one home. */
	private static function inquiry_count( int $listing_id ): int {

		$query = new \WP_Query(
			[
				'post_type'      => Post_Types::INQUIRY,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_tdh_listing_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $listing_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);

		return (int) $query->found_posts;
	}

	private static function pagination( int $page, int $pages ): void {

		$base = self::list_url( 'listings', 1 );
		?>
		<nav class="portal-pagination" aria-label="<?php esc_attr_e( 'Listing pages', 'thirtydayhomes' ); ?>">
			<?php if ( $page > 1 ) : ?>
				<a class="secondary" rel="prev" href="<?php echo esc_url( $page - 1 > 1 ? add_query_arg( 'listings_page', $page - 1, $base ) : $base ); ?>">
					<?php echo self::icon( 'chevron-left', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php esc_html_e( 'Previous', 'thirtydayhomes' ); ?>
				</a>
			<?php else : ?>
				<span class="secondary is-disabled" aria-hidden="true"><?php echo self::icon( 'chevron-left', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Previous', 'thirtydayhomes' ); ?></span>
			<?php endif; ?>

			<span class="portal-pagination-count" aria-current="page">
				<?php
				/* translators: 1: current page, 2: number of pages */
				printf( esc_html__( 'Page %1$s of %2$s', 'thirtydayhomes' ), esc_html( number_format_i18n( $page ) ), esc_html( number_format_i18n( $pages ) ) );
				?>
			</span>

			<?php if ( $page < $pages ) : ?>
				<a class="secondary" rel="next" href="<?php echo esc_url( add_query_arg( 'listings_page', $page + 1, $base ) ); ?>">
					<?php esc_html_e( 'Next', 'thirtydayhomes' ); ?>
					<?php echo self::icon( 'chevron-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</a>
			<?php else : ?>
				<span class="secondary is-disabled" aria-hidden="true"><?php esc_html_e( 'Next', 'thirtydayhomes' ); ?><?php echo self::icon( 'chevron-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<?php endif; ?>
		</nav>
		<?php
	}

	/**
	 * No homes: say what to do next, and what that depends on. The wizard's
	 * gate decides, so the button always leads somewhere that lets them in.
	 */
	private static function empty_state(): void {

		$can_add = '' === Listing_Form::gate_reason();
		?>
		<div class="empty-state">
			<i><?php echo self::icon( 'map-pinned', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></i>
			<h4><?php esc_html_e( 'No homes listed yet', 'thirtydayhomes' ); ?></h4>

			<?php if ( $can_add ) : ?>
				<p><?php esc_html_e( 'Add your first home and it goes to review before publishing.', 'thirtydayhomes' ); ?></p>
				<a class="secondary" href="<?php echo esc_url( Listing_Form::url() ); ?>">
					<?php esc_html_e( 'Add your home', 'thirtydayhomes' ); ?>
				</a>
			<?php else : ?>
				<p><?php esc_html_e( 'A membership comes first. Once a plan is active you can publish your home here.', 'thirtydayhomes' ); ?></p>
				<a class="secondary" href="<?php echo esc_url( Accounts::url( 'pricing' ) ); ?>">
					<?php esc_html_e( 'See plans', 'thirtydayhomes' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}

	/** When a home went live — resumed, approved, or first published. */
	private static function live_since( \WP_Post $post ): string {

		foreach ( [ '_tdh_live_since', '_tdh_approved_at' ] as $key ) {
			$value = (string) get_post_meta( $post->ID, $key, true );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return (string) $post->post_date;
	}

	/** "17 Sep 2026" from a stored local datetime. */
	private static function date( string $mysql ): string {
		return '' !== $mysql ? (string) mysql2date( 'j M Y', $mysql ) : '';
	}

	private static function icon( string $name, int $size ): string {
		return function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size ) : '';
	}
}
