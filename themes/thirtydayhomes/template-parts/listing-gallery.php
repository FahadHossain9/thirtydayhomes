<?php
/**
 * The property page's photographs.
 *
 * The cover large, the next four beside it, and "Show all photos" opening
 * every photo in order. The order and the cover are the landlord's own,
 * set in the listing form; this only lays them out.
 *
 * Without JavaScript the mosaic still shows up to five photos — the viewer
 * is an enhancement, and its button stays hidden until the script can
 * actually open it.
 *
 * Expects $args: ids (int[] in display order), title (string), city (string).
 *
 * @package ThirtyDayHomes
 */

defined( 'ABSPATH' ) || exit;

$tdh_ids   = array_values( array_map( 'intval', (array) ( $args['ids'] ?? [] ) ) );
$tdh_title = (string) ( $args['title'] ?? '' );
$tdh_city  = (string) ( $args['city'] ?? '' );
$tdh_count = count( $tdh_ids );

if ( 0 === $tdh_count ) {
	return;
}

/**
 * A photo's alt text: the landlord's description, or a sentence that at
 * least says which home and which photo it is.
 */
$tdh_alt = static function ( int $id, int $position ) use ( $tdh_title, $tdh_city, $tdh_count ): string {

	$own = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );

	if ( '' !== $own ) {
		return $own;
	}

	if ( 1 === $tdh_count ) {
		return '' !== $tdh_city
			/* translators: 1: listing title, 2: city */
			? sprintf( __( '%1$s — furnished rental in %2$s', 'thirtydayhomes' ), $tdh_title, $tdh_city )
			/* translators: %s: listing title */
			: sprintf( __( '%s — furnished monthly rental', 'thirtydayhomes' ), $tdh_title );
	}

	/* translators: 1: listing title, 2: photo position, 3: photo count */
	return sprintf( __( '%1$s — photo %2$s of %3$s', 'thirtydayhomes' ), $tdh_title, number_format_i18n( $position ), number_format_i18n( $tdh_count ) );
};

$tdh_shown = array_slice( $tdh_ids, 0, 5 );
?>

<section class="detail-gallery" data-count="<?php echo esc_attr( (string) count( $tdh_shown ) ); ?>" data-tdh-gallery
	aria-label="<?php esc_attr_e( 'Photos', 'thirtydayhomes' ); ?>">

	<?php foreach ( $tdh_shown as $tdh_i => $tdh_id ) : ?>
		<figure class="detail-gallery-tile">
			<?php
			echo wp_get_attachment_image( // phpcs:ignore WordPress.Security.EscapeOutput
				$tdh_id,
				0 === $tdh_i ? 'tdh-gallery' : 'tdh-card',
				false,
				[
					'alt'     => $tdh_alt( $tdh_id, $tdh_i + 1 ),
					'sizes'   => 0 === $tdh_i ? '(max-width: 43.75rem) 100vw, 60vw' : '(max-width: 43.75rem) 1px, 25vw',
					'loading' => 0 === $tdh_i ? 'eager' : 'lazy',
				]
			);
			?>
			<?php if ( $tdh_count > 1 ) : ?>
				<button type="button" class="detail-gallery-open" data-tdh-gallery-open="<?php echo esc_attr( (string) $tdh_i ); ?>" hidden
					aria-label="<?php echo esc_attr( sprintf( /* translators: 1: photo position, 2: photo count */ __( 'Open photo %1$s of %2$s', 'thirtydayhomes' ), number_format_i18n( $tdh_i + 1 ), number_format_i18n( $tdh_count ) ) ); ?>"></button>
			<?php endif; ?>
		</figure>
	<?php endforeach; ?>

	<?php if ( $tdh_count > 1 ) : ?>
		<button type="button" class="detail-gallery-all" data-tdh-gallery-open="0" hidden>
			<?php tdh_the_icon( 'layout-grid', 16 ); ?>
			<?php
			/* translators: %s: number of photos */
			printf( esc_html__( 'Show all %s photos', 'thirtydayhomes' ), esc_html( number_format_i18n( $tdh_count ) ) );
			?>
		</button>
	<?php endif; ?>
</section>

<?php if ( $tdh_count > 1 ) : ?>
	<dialog class="gallery-dialog" id="tdh-gallery-dialog" aria-labelledby="tdh-gallery-title">
		<div class="gallery-dialog-head">
			<h2 id="tdh-gallery-title">
				<?php
				/* translators: 1: number of photos, 2: listing title */
				printf( esc_html__( '%1$s photos · %2$s', 'thirtydayhomes' ), esc_html( number_format_i18n( $tdh_count ) ), esc_html( $tdh_title ) );
				?>
			</h2>
			<button type="button" class="gallery-dialog-close" data-tdh-gallery-close
				aria-label="<?php esc_attr_e( 'Close photos', 'thirtydayhomes' ); ?>">
				<?php tdh_the_icon( 'x', 20 ); ?>
			</button>
		</div>

		<ol class="gallery-dialog-list">
			<?php foreach ( $tdh_ids as $tdh_i => $tdh_id ) : ?>
				<?php $tdh_description = trim( (string) get_post_meta( $tdh_id, '_wp_attachment_image_alt', true ) ); ?>
				<li data-tdh-photo="<?php echo esc_attr( (string) $tdh_i ); ?>">
					<figure>
						<?php
						echo wp_get_attachment_image( // phpcs:ignore WordPress.Security.EscapeOutput
							$tdh_id,
							'large',
							false,
							[
								'alt'     => $tdh_alt( $tdh_id, $tdh_i + 1 ),
								'sizes'   => '(max-width: 64rem) 100vw, 64rem',
								'loading' => 'lazy',
							]
						);
						?>
						<?php if ( '' !== $tdh_description ) : ?>
							<figcaption><?php echo esc_html( $tdh_description ); ?></figcaption>
						<?php endif; ?>
					</figure>
				</li>
			<?php endforeach; ?>
		</ol>
	</dialog>
<?php endif; ?>
