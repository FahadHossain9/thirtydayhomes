<?php
/**
 * The property page's availability: when a renter can move in, the dates
 * already taken, and a month-by-month calendar.
 *
 * Every date comes from the plugin (TDH\Availability), which owns what
 * "free" means; this part only draws it. Past periods are the landlord's
 * history and never shown here.
 *
 * The calendar is twelve server-rendered months in a row that scrolls
 * sideways — two at a time on a wide screen, one on a phone. Without
 * JavaScript it is swiped or scrolled; availability.js adds Previous and
 * Next buttons. The words above it carry the same facts, so nothing is
 * known only by colour or only to people who can see the grid.
 *
 * @package ThirtyDayHomes
 *
 * @var array{id:int} $args
 */

defined( 'ABSPATH' ) || exit;

$listing_id = (int) ( $args['id'] ?? 0 );

if ( $listing_id < 1 || ! class_exists( '\TDH\Availability' ) ) {
	return;
}

$summary  = \TDH\Availability::summary( $listing_id );
$min_stay = \TDH\Availability::min_stay( $listing_id );
$months   = \TDH\Availability::months( $listing_id );
$weekdays = \TDH\Availability::weekdays();

$stay_label = '';

if ( $min_stay > 0 ) {
	$stay_label = 91 === $min_stay
		? __( 'Minimum stay 13 weeks', 'thirtydayhomes' )
		/* translators: %s: number of days */
		: sprintf( __( 'Minimum stay %s days', 'thirtydayhomes' ), number_format_i18n( $min_stay ) );
}

/*
 * The stay a renter arrived with (task C2). The dates ride on the link
 * from the results, so the calendar can be read against the question that
 * was actually asked rather than against the whole year.
 *
 * It is checked again here rather than trusted. A link can be shared,
 * edited or opened weeks later, and telling somebody a home is free for
 * dates it is not would be the one mistake this page must never make.
 */
$asked     = [];
$asked_fit = false;

if ( class_exists( '\TDH\Search' ) ) {

	$search    = \TDH\Search::filters();
	$asked     = [
		'start' => $search['start'],
		'end'   => $search['end'],
	];
	$asked_fit = null !== $search['start']
		&& \TDH\Availability::is_free(
			$listing_id,
			$search['start'],
			$search['end'] ?? \TDH\Availability::add_days( $search['start'], max( \TDH\Search::MIN_STAY_DAYS, $min_stay ) )
		);
}

$asked_phrase = null !== ( $asked['start'] ?? null ) ? \TDH\Search::stay_phrase() : '';
?>
<section class="detail-section avail-public" id="availability" aria-labelledby="avail-public-title">
	<h2 id="avail-public-title"><?php esc_html_e( 'Availability', 'thirtydayhomes' ); ?></h2>

	<?php if ( '' !== $asked_phrase ) : ?>
		<p class="avail-asked <?php echo $asked_fit ? 'is-free' : 'is-taken'; ?>">
			<?php tdh_the_icon( $asked_fit ? 'check' : 'x', 16 ); ?>
			<span>
				<b>
					<?php
					printf(
						/* translators: %s: the stay, e.g. "1 Nov – 1 Dec 2026" */
						esc_html__( 'Your stay: %s', 'thirtydayhomes' ),
						esc_html( $asked_phrase )
					);
					?>
				</b>
				<small>
					<?php
					echo esc_html(
						$asked_fit
							? __( 'This home is free for those dates.', 'thirtydayhomes' )
							: __( 'This home is not free for those dates. The calendar below shows when it is.', 'thirtydayhomes' )
					);
					?>
				</small>
			</span>
		</p>
	<?php endif; ?>

	<div class="availability">
		<?php tdh_the_icon( 'calendar-days', 22 ); ?>
		<span>
			<b><?php echo esc_html( $summary['headline'] ); ?></b>
			<?php if ( '' !== $stay_label ) : ?>
				<small><?php echo esc_html( $stay_label ); ?></small>
			<?php endif; ?>
		</span>
	</div>

	<?php if ( $summary['upcoming'] ) : ?>
		<p class="avail-public-taken">
			<b><?php esc_html_e( 'Unavailable:', 'thirtydayhomes' ); ?></b>
			<?php
			echo implode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each part escaped below.
				' · ',
				array_map( static fn( string $range ): string => '<span>' . esc_html( $range ) . '</span>', $summary['upcoming'] )
			);
			?>
		</p>
	<?php endif; ?>

	<div class="avail-cal" data-avail-cal>
		<div class="avail-cal-head">
			<ul class="avail-cal-legend">
				<li class="is-free"><i aria-hidden="true"></i><?php esc_html_e( 'Available', 'thirtydayhomes' ); ?></li>
				<li class="is-off"><i aria-hidden="true"></i><?php esc_html_e( 'Unavailable', 'thirtydayhomes' ); ?></li>
			</ul>
			<div class="avail-cal-nav" data-avail-cal-nav hidden>
				<button type="button" data-avail-cal-prev aria-label="<?php esc_attr_e( 'Previous month', 'thirtydayhomes' ); ?>">
					<?php tdh_the_icon( 'chevron-left', 18 ); ?>
				</button>
				<button type="button" data-avail-cal-next aria-label="<?php esc_attr_e( 'Next month', 'thirtydayhomes' ); ?>">
					<?php tdh_the_icon( 'chevron-right', 18 ); ?>
				</button>
			</div>
		</div>

		<div class="avail-cal-track" data-avail-cal-track tabindex="0" role="region"
			aria-label="<?php echo esc_attr( sprintf( /* translators: %s: number of months */ __( 'Availability calendar, %s months', 'thirtydayhomes' ), number_format_i18n( count( $months ) ) ) ); ?>">
			<?php foreach ( $months as $month ) : ?>
				<table class="avail-cal-month">
					<caption><?php echo esc_html( $month['label'] ); ?></caption>
					<thead>
						<tr>
							<?php foreach ( $weekdays as $weekday ) : ?>
								<th scope="col" abbr="<?php echo esc_attr( $weekday['long'] ); ?>"><?php echo esc_html( $weekday['short'] ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $month['weeks'] as $week ) : ?>
							<tr>
								<?php foreach ( $week as $cell ) : ?>
									<?php if ( null === $cell ) : ?>
										<td></td>
									<?php else : ?>
										<td class="is-<?php echo esc_attr( $cell['state'] ); ?><?php echo $cell['today'] ? ' is-today' : ''; ?>"<?php echo $cell['today'] ? ' aria-current="date"' : ''; ?>>
											<span><?php echo esc_html( (string) $cell['day'] ); ?></span>
											<?php if ( 'unavailable' === $cell['state'] ) : ?>
												<span class="screen-reader-text"><?php esc_html_e( ', unavailable', 'thirtydayhomes' ); ?></span>
											<?php elseif ( $cell['today'] ) : ?>
												<span class="screen-reader-text"><?php esc_html_e( ', today', 'thirtydayhomes' ); ?></span>
											<?php endif; ?>
										</td>
									<?php endif; ?>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
		</div>
	</div>
</section>
