<?php
/**
 * Not found.
 *
 * WordPress used to fall back to index.php here, which printed an unstyled
 * "Nothing here yet" in the top-left corner. The commonest way to arrive is
 * a link to a home that is not public — rented, paused, still in review, or
 * a preview link opened by someone who is not its landlord or staff — so a
 * home gets its own wording, and every visitor gets a way to keep looking.
 *
 * Nothing here reveals WHY a particular home is unavailable: "in review"
 * and "never existed" must look the same to a stranger.
 *
 * @package ThirtyDayHomes
 */

defined( 'ABSPATH' ) || exit;

get_header();

$tdh_home_link = 'tdh_listing' === get_query_var( 'post_type' )
	|| str_contains( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/homes/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only, never printed.

$tdh_archive = (string) get_post_type_archive_link( 'tdh_listing' );
?>

<section class="not-found" aria-labelledby="tdh-not-found-title">
	<div class="not-found-inner">

		<span class="not-found-icon" aria-hidden="true">
			<?php tdh_the_icon( $tdh_home_link ? 'map-pinned' : 'search', 26 ); ?>
		</span>

		<p class="overline gold">
			<?php echo $tdh_home_link ? esc_html__( 'Home not available', 'thirtydayhomes' ) : esc_html__( 'Page not found', 'thirtydayhomes' ); ?>
		</p>

		<h1 id="tdh-not-found-title">
			<?php echo $tdh_home_link ? esc_html__( 'This home isn’t available right now.', 'thirtydayhomes' ) : esc_html__( 'We couldn’t find that page.', 'thirtydayhomes' ); ?>
		</h1>

		<p>
			<?php
			echo $tdh_home_link
				? esc_html__( 'It may have been rented, paused by its owner, or not published yet. Plenty of furnished homes are ready now.', 'thirtydayhomes' )
				: esc_html__( 'The link may be out of date, or the page may have moved.', 'thirtydayhomes' );
			?>
		</p>

		<div class="not-found-actions">
			<a class="primary" href="<?php echo esc_url( '' !== $tdh_archive ? $tdh_archive : home_url( '/' ) ); ?>">
				<?php esc_html_e( 'Browse homes', 'thirtydayhomes' ); ?>
				<?php tdh_the_icon( 'arrow-right', 16 ); ?>
			</a>
			<a class="secondary" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php esc_html_e( 'Go to the homepage', 'thirtydayhomes' ); ?>
			</a>
		</div>

		<?php if ( class_exists( '\TDH\Render' ) ) : ?>
			<div class="not-found-search">
				<?php echo \TDH\Render::search_bar(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
			</div>
		<?php endif; ?>

	</div>
</section>

<?php
get_footer();
