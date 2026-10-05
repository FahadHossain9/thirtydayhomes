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

$tdh_ids   = array_values(
	array_unique(
		array_filter(
			array_map( 'intval', (array) ( $args['ids'] ?? [] ) ),
			static function ( int $id ): bool {
				return $id > 0
					&& 'attachment' === get_post_type( $id )
					&& wp_attachment_is_image( $id )
					&& false !== wp_get_attachment_image_src( $id, 'full' );
			}
		)
	)
);
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

<section class="detail-gallery" data-count="<?php echo esc_attr( (string) count( $tdh_shown ) ); ?>" data-total="<?php echo esc_attr( (string) $tdh_count ); ?>" data-tdh-gallery
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
					'sizes'   => 0 === $tdh_i ? '(max-width: 43.75rem) 100vw, (max-width: 62rem) 60vw, 48rem' : '(max-width: 43.75rem) 1px, (max-width: 62rem) 40vw, 24rem',
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
			<div class="gallery-dialog-copy">
				<h2 id="tdh-gallery-title">
					<?php
					/* translators: 1: number of photos, 2: listing title */
					printf( esc_html__( '%1$s photos · %2$s', 'thirtydayhomes' ), esc_html( number_format_i18n( $tdh_count ) ), esc_html( $tdh_title ) );
					?>
				</h2>
				<p class="gallery-dialog-position" data-tdh-gallery-position aria-live="polite" aria-atomic="true">
					<?php
					/* translators: 1: current photo position, 2: photo count */
					printf( esc_html__( 'Photo %1$s of %2$s', 'thirtydayhomes' ), esc_html( number_format_i18n( 1 ) ), esc_html( number_format_i18n( $tdh_count ) ) );
					?>
				</p>
			</div>
			<button type="button" class="gallery-dialog-close" data-tdh-gallery-close
				aria-label="<?php esc_attr_e( 'Close photos', 'thirtydayhomes' ); ?>">
				<?php tdh_the_icon( 'x', 20 ); ?>
			</button>
		</div>

		<div class="gallery-dialog-stage">
			<button type="button" class="gallery-dialog-nav gallery-dialog-nav--previous" data-tdh-gallery-prev disabled
				aria-label="<?php esc_attr_e( 'Previous photo', 'thirtydayhomes' ); ?>">
				<?php tdh_the_icon( 'chevron-left', 20 ); ?>
			</button>

			<ol class="gallery-dialog-list">
				<?php foreach ( $tdh_ids as $tdh_i => $tdh_id ) : ?>
					<?php
					$tdh_description = trim( (string) get_post_meta( $tdh_id, '_wp_attachment_image_alt', true ) );
					$tdh_position    = sprintf(
						/* translators: 1: current photo position, 2: photo count */
						__( 'Photo %1$s of %2$s', 'thirtydayhomes' ),
						number_format_i18n( $tdh_i + 1 ),
						number_format_i18n( $tdh_count )
					);
					?>
					<li data-tdh-photo="<?php echo esc_attr( (string) $tdh_i ); ?>" data-position-label="<?php echo esc_attr( $tdh_position ); ?>"<?php echo 0 === $tdh_i ? '' : ' hidden'; ?>>
						<figure>
							<?php
							echo wp_get_attachment_image( // phpcs:ignore WordPress.Security.EscapeOutput
								$tdh_id,
								'large',
								false,
								[
									'alt'     => $tdh_alt( $tdh_id, $tdh_i + 1 ),
									'sizes'   => '(max-width: 62rem) 100vw, 72rem',
									'loading' => 'lazy',
								]
							);
							?>
							<p class="gallery-dialog-unavailable" data-tdh-photo-error hidden>
								<?php esc_html_e( 'This photo could not load. Try the previous or next photo.', 'thirtydayhomes' ); ?>
							</p>
							<?php if ( '' !== $tdh_description ) : ?>
								<figcaption><?php echo esc_html( $tdh_description ); ?></figcaption>
							<?php endif; ?>
						</figure>
					</li>
				<?php endforeach; ?>
			</ol>

			<button type="button" class="gallery-dialog-nav gallery-dialog-nav--next" data-tdh-gallery-next
				aria-label="<?php esc_attr_e( 'Next photo', 'thirtydayhomes' ); ?>">
				<?php tdh_the_icon( 'chevron-right', 20 ); ?>
			</button>
		</div>
	</dialog>
<?php endif; ?>
