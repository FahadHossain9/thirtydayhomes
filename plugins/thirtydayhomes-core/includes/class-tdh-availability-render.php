<?php
/**
 * The availability fields — one editor, used by the listing form's step 2
 * and by the quick edit on My listings.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * ─── ONE EDITOR, TWO DOORS ─────────────────────────────────────────────────
 *
 * "Available from" and the unavailable periods are the same fields wherever
 * a landlord meets them, posted under the same names and saved by the same
 * Availability::save_posted(). The wizard shows them on step 2; My listings
 * opens them in a panel so a month can be blocked without the wizard.
 *
 * ─── WITHOUT JAVASCRIPT ────────────────────────────────────────────────────
 *
 * Real date inputs in rows, plus one empty row at the end: typing into it
 * adds a period, clearing a row's dates removes it. The script adds the
 * "Add another period" and "Remove" buttons, moves focus sensibly, keeps the
 * count honest and stops at the limit — it never holds the only copy of
 * anything.
 *
 * Look: theme style.css "AVAILABILITY".
 */
final class Availability_Render {

	/** Printed once per page, however many editors it has. */
	private static bool $script_printed = false;

	/**
	 * "Available from", on its own.
	 *
	 * @param array{from:?string} $kept From Availability::take_kept().
	 */
	public static function from_field( int $id, array $kept, string $prefix ): void {

		$value = null !== ( $kept['from'] ?? null ) && Availability::is_date( (string) $kept['from'] )
			? (string) $kept['from']
			: Availability::available_from( $id );
		?>
		<div class="avail-from">
			<label class="avail-field" for="<?php echo esc_attr( $prefix ); ?>-from">
				<span>
					<b><?php esc_html_e( 'Available from', 'thirtydayhomes' ); ?></b>
					<small><?php esc_html_e( 'Leave blank if it’s free now', 'thirtydayhomes' ); ?></small>
				</span>
				<input id="<?php echo esc_attr( $prefix ); ?>-from" name="tdh_available" type="date"
					max="<?php echo esc_attr( Availability::horizon() ); ?>"
					value="<?php echo esc_attr( $value ); ?>">
			</label>
		</div>
		<?php
	}

	/**
	 * The unavailable periods: saved ones, then any the last save refused
	 * (with the reason beside them), then one empty row.
	 *
	 * @param array{rows:array,replace:bool} $kept From Availability::take_kept().
	 */
	public static function ranges_field( int $id, array $kept, string $prefix ): void {

		$today = Availability::today();
		$rows  = [];

		if ( empty( $kept['replace'] ) ) {
			foreach ( Availability::ranges( $id ) as $range ) {
				$rows[] = [ 'from' => $range['from'], 'to' => $range['to'], 'error' => '' ];
			}
		}

		foreach ( (array) ( $kept['rows'] ?? [] ) as $row ) {
			$rows[] = [
				'from'  => (string) ( $row['from'] ?? '' ),
				'to'    => (string) ( $row['to'] ?? '' ),
				'error' => (string) ( $row['error'] ?? '' ),
			];
		}

		$filled = count( $rows );
		$max    = Availability::MAX_RANGES;

		// The empty row to type a new period into — unless the list is full.
		if ( $filled < $max ) {
			$rows[] = [ 'from' => '', 'to' => '', 'error' => '' ];
		}

		$legend = $prefix . '-legend';
		$help   = $prefix . '-help';
		?>
		<fieldset class="avail-periods" data-avail-editor
			data-max="<?php echo esc_attr( (string) $max ); ?>"
			data-count-none="<?php echo esc_attr( sprintf( /* translators: %s: the limit */ __( 'None yet · up to %s periods', 'thirtydayhomes' ), number_format_i18n( $max ) ) ); ?>"
			data-count-some="<?php echo esc_attr( /* translators: 1: periods entered, 2: the limit */ __( '%1$s of %2$s periods', 'thirtydayhomes' ) ); ?>"
			data-count-full="<?php echo esc_attr( sprintf( /* translators: %s: the limit */ __( '%1$s of %1$s periods — remove one to add another', 'thirtydayhomes' ), number_format_i18n( $max ) ) ); ?>"
			data-period="<?php echo esc_attr( /* translators: %s: number of the period in the list */ __( 'period %s', 'thirtydayhomes' ) ); ?>"
			aria-describedby="<?php echo esc_attr( $help ); ?>">

			<legend id="<?php echo esc_attr( $legend ); ?>"><?php esc_html_e( 'Unavailable dates', 'thirtydayhomes' ); ?></legend>
			<p class="avail-help" id="<?php echo esc_attr( $help ); ?>">
				<?php esc_html_e( 'Periods the home is already rented or you’re away. Renters see them on the listing. For a single day, use the same date twice.', 'thirtydayhomes' ); ?>
			</p>

			<ul class="avail-rows" data-avail-rows>
				<?php foreach ( $rows as $i => $row ) : ?>
					<?php self::row( $row, $i + 1, $prefix, $today ); ?>
				<?php endforeach; ?>
			</ul>

			<template data-avail-template>
				<?php self::row( [ 'from' => '', 'to' => '', 'error' => '' ], 0, $prefix, $today ); ?>
			</template>

			<div class="avail-foot">
				<button class="avail-add" type="button" data-avail-add hidden <?php disabled( $filled >= $max ); ?>>
					<?php echo self::icon( 'plus', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php esc_html_e( 'Add another period', 'thirtydayhomes' ); ?>
				</button>
				<p class="avail-count" data-avail-count aria-live="polite">
					<?php
					if ( 0 === $filled ) {
						/* translators: %s: the limit */
						printf( esc_html__( 'None yet · up to %s periods', 'thirtydayhomes' ), esc_html( number_format_i18n( $max ) ) );
					} elseif ( $filled >= $max ) {
						/* translators: %s: the limit */
						printf( esc_html__( '%1$s of %1$s periods — remove one to add another', 'thirtydayhomes' ), esc_html( number_format_i18n( $max ) ) );
					} else {
						/* translators: 1: periods entered, 2: the limit */
						printf( esc_html__( '%1$s of %2$s periods', 'thirtydayhomes' ), esc_html( number_format_i18n( $filled ) ), esc_html( number_format_i18n( $max ) ) );
					}
					?>
				</p>
			</div>

			<p class="avail-nojs" data-avail-nojs>
				<?php esc_html_e( 'To remove a period, clear both of its dates. To add another, fill in the empty row and save.', 'thirtydayhomes' ); ?>
			</p>
		</fieldset>
		<?php
		self::script();
	}

	/**
	 * One period.
	 *
	 * @param array{from:string,to:string,error:string} $row
	 */
	private static function row( array $row, int $n, string $prefix, string $today ): void {

		$from  = (string) $row['from'];
		$to    = (string) $row['to'];
		$past  = Availability::is_date( $to ) && $to < $today && '' === $row['error'];
		$error = (string) $row['error'];
		$uid   = $prefix . '-p' . ( $n > 0 ? $n : '__n__' );
		$label = $n > 0 ? sprintf( /* translators: %s: number */ __( 'period %s', 'thirtydayhomes' ), number_format_i18n( $n ) ) : '';

		$classes = array_filter( [ 'avail-row', $past ? 'is-past' : '', '' !== $error ? 'is-invalid' : '' ] );
		?>
		<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-avail-row>
			<label class="avail-field">
				<span>
					<b><?php esc_html_e( 'First day', 'thirtydayhomes' ); ?></b>
					<span class="screen-reader-text" data-avail-n><?php echo esc_html( $label ); ?></span>
				</span>
				<input name="tdh_blocked_from[]" type="date" data-avail-first
					max="<?php echo esc_attr( Availability::horizon() ); ?>"
					value="<?php echo esc_attr( Availability::is_date( $from ) ? $from : '' ); ?>"
					<?php echo '' !== $error ? 'aria-invalid="true" aria-describedby="' . esc_attr( $uid ) . '-error"' : ''; ?>>
			</label>
			<label class="avail-field">
				<span>
					<b><?php esc_html_e( 'Last day', 'thirtydayhomes' ); ?></b>
					<span class="screen-reader-text" data-avail-n><?php echo esc_html( $label ); ?></span>
				</span>
				<input name="tdh_blocked_to[]" type="date" data-avail-last
					max="<?php echo esc_attr( Availability::horizon() ); ?>"
					<?php echo Availability::is_date( $from ) ? 'min="' . esc_attr( $from ) . '"' : ''; ?>
					value="<?php echo esc_attr( Availability::is_date( $to ) ? $to : '' ); ?>"
					<?php echo '' !== $error ? 'aria-invalid="true" aria-describedby="' . esc_attr( $uid ) . '-error"' : ''; ?>>
			</label>
			<button class="avail-remove" type="button" data-avail-remove hidden>
				<?php echo self::icon( 'x', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span><?php esc_html_e( 'Remove', 'thirtydayhomes' ); ?></span>
				<span class="screen-reader-text" data-avail-n><?php echo esc_html( $label ); ?></span>
			</button>
			<?php if ( $past ) : ?>
				<p class="avail-row-note"><?php esc_html_e( 'Past — renters don’t see it. Remove it whenever you like.', 'thirtydayhomes' ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $error ) : ?>
				<p class="avail-row-error" id="<?php echo esc_attr( $uid ); ?>-error"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>
		</li>
		<?php
	}

	/**
	 * The quick edit on My listings: the home, where it stands, the fields,
	 * Save and Cancel. A server-rendered state (?availability=ID), like the
	 * delete question — it survives a reload, and Back simply closes it.
	 */
	public static function panel( \WP_Post $post, string $title, string $context, int $page, string $cancel ): void {

		$id      = (int) $post->ID;
		$kept    = Availability::take_kept( $id );
		$summary = Availability::summary( $id );
		$stay    = Availability::min_stay( $id );
		$panel   = 'manage-avail-' . $id;

		$status = $summary['headline'];

		if ( $stay > 0 ) {
			$status .= ' · ' . ( 91 === $stay
				? __( 'Minimum stay 13 weeks', 'thirtydayhomes' )
				/* translators: %s: number of days */
				: sprintf( __( 'Minimum stay %s days', 'thirtydayhomes' ), number_format_i18n( $stay ) ) );
		}
		?>
		<div class="manage-avail" id="<?php echo esc_attr( $panel ); ?>" role="group" aria-labelledby="<?php echo esc_attr( $panel ); ?>-title">
			<h5 id="<?php echo esc_attr( $panel ); ?>-title" tabindex="-1">
				<?php
				/* translators: %s: listing title */
				printf( esc_html__( 'Availability for “%s”', 'thirtydayhomes' ), esc_html( $title ) );
				?>
			</h5>
			<p class="manage-avail-status">
				<?php echo esc_html( Statuses::public_status() === $post->post_status ? __( 'Renters see now:', 'thirtydayhomes' ) : __( 'Renters will see, once it’s live:', 'thirtydayhomes' ) ); ?>
				<b><?php echo esc_html( $status ); ?></b>
			</p>

			<?php if ( $kept['errors'] ) : ?>
				<div class="manage-avail-errors" role="alert">
					<?php foreach ( $kept['errors'] as $error ) : ?>
						<p><?php echo esc_html( $error ); ?></p>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<form class="manage-avail-form" method="post" action="<?php echo esc_url( Listing_Manage_Render::list_url( $context, $page ) ); ?>">
				<input type="hidden" name="tdh_action" value="listing_availability">
				<input type="hidden" name="tdh_listing" value="<?php echo esc_attr( (string) $id ); ?>">
				<input type="hidden" name="tdh_return" value="<?php echo esc_attr( $context ); ?>">
				<input type="hidden" name="tdh_page" value="<?php echo esc_attr( (string) $page ); ?>">
				<input type="hidden" name="tdh_availability" value="1">
				<?php wp_nonce_field( Availability::NONCE, 'tdh_nonce' ); ?>

				<?php self::from_field( $id, $kept, $panel ); ?>
				<?php self::ranges_field( $id, $kept, $panel ); ?>

				<div class="manage-avail-actions">
					<button class="primary" type="submit"><?php esc_html_e( 'Save availability', 'thirtydayhomes' ); ?></button>
					<a class="secondary" href="<?php echo esc_url( $cancel . '#listing-' . $id ); ?>"><?php esc_html_e( 'Cancel', 'thirtydayhomes' ); ?></a>
				</div>
			</form>
			<?php Listing_Form_Render::busy_script( __( 'Saving…', 'thirtydayhomes' ) ); ?>
			<script>
			/* Focus moves into the panel, so a keyboard or screen-reader user
			   lands on it. No Escape shortcut: it would throw away typed dates. */
			( function () {
				var title = document.getElementById( <?php echo wp_json_encode( $panel . '-title' ); ?> );
				if ( title ) { title.focus( { preventScroll: true } ); }
			} )();
			</script>
		</div>
		<?php
	}

	/**
	 * The editor's behaviour. Enhancement only; see the class note.
	 */
	private static function script(): void {

		if ( self::$script_printed ) {
			return;
		}

		self::$script_printed = true;
		?>
		<script>
		( function () {
			var editors = document.querySelectorAll( '[data-avail-editor]' );

			Array.prototype.forEach.call( editors, function ( editor ) {
				var list     = editor.querySelector( '[data-avail-rows]' );
				var template = editor.querySelector( '[data-avail-template]' );
				var add      = editor.querySelector( '[data-avail-add]' );
				var count    = editor.querySelector( '[data-avail-count]' );
				var nojs     = editor.querySelector( '[data-avail-nojs]' );
				var max      = parseInt( editor.getAttribute( 'data-max' ), 10 ) || 20;

				if ( ! list || ! template || ! add ) { return; }

				var rows = function () { return Array.prototype.slice.call( list.querySelectorAll( '[data-avail-row]' ) ); };
				var filled = function ( row ) {
					return Array.prototype.some.call( row.querySelectorAll( 'input' ), function ( input ) { return '' !== input.value; } );
				};

				var refresh = function () {
					var all = rows(), used = all.filter( filled ).length;

					all.forEach( function ( row, i ) {
						Array.prototype.forEach.call( row.querySelectorAll( '[data-avail-n]' ), function ( el ) {
							el.textContent = editor.getAttribute( 'data-period' ).replace( '%s', String( i + 1 ) );
						} );
						var remove = row.querySelector( '[data-avail-remove]' );
						if ( remove ) { remove.hidden = false; }
					} );

					if ( 0 === used ) {
						count.textContent = editor.getAttribute( 'data-count-none' );
					} else if ( used >= max ) {
						count.textContent = editor.getAttribute( 'data-count-full' );
					} else {
						count.textContent = editor.getAttribute( 'data-count-some' ).replace( '%1$s', String( used ) ).replace( '%2$s', String( max ) );
					}

					add.disabled = all.length >= max;
				};

				add.hidden = false;
				if ( nojs ) { nojs.hidden = true; }

				add.addEventListener( 'click', function () {
					if ( rows().length >= max ) { return; }
					var row = template.content.firstElementChild.cloneNode( true );
					list.appendChild( row );
					refresh();
					var first = row.querySelector( 'input' );
					if ( first ) { first.focus(); }
				} );

				list.addEventListener( 'click', function ( event ) {
					var button = event.target.closest( '[data-avail-remove]' );
					if ( ! button ) { return; }

					var row  = button.closest( '[data-avail-row]' );
					var next = row.nextElementSibling || row.previousElementSibling;

					if ( rows().length > 1 ) {
						row.parentNode.removeChild( row );
					} else {
						// The only row: empty it rather than leave nowhere to type.
						Array.prototype.forEach.call( row.querySelectorAll( 'input' ), function ( input ) { input.value = ''; } );
						next = row;
					}

					refresh();
					var target = next ? next.querySelector( 'input' ) : add;
					( target || add ).focus();
				} );

				// The last day cannot come before the first: the picker opens on
				// the right month, and the browser flags a backwards pair at once.
				list.addEventListener( 'input', function ( event ) {
					if ( event.target.hasAttribute( 'data-avail-first' ) ) {
						var last = event.target.closest( '[data-avail-row]' ).querySelector( '[data-avail-last]' );
						if ( last ) {
							if ( event.target.value ) { last.min = event.target.value; } else { last.removeAttribute( 'min' ); }
						}
					}
					refresh();
				} );

				refresh();
			} );
		} )();
		</script>
		<?php
	}

	private static function icon( string $name, int $size ): string {
		return function_exists( 'tdh_icon' ) ? tdh_icon( $name, $size ) : '';
	}
}
