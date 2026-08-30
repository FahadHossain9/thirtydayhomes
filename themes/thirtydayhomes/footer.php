<?php
/**
 * Site footer.
 *
 * @package ThirtyDayHomes
 */

defined( 'ABSPATH' ) || exit;
?>
</main><!-- #content -->

<?php if ( ! tdh_elementor_location( 'footer' ) ) : ?>

	<footer class="site-footer">

		<a class="logo-link" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
			<?php tdh_the_logo(); ?>
		</a>

		<p><?php esc_html_e( 'Furnished homes for life’s in-between moments.', 'thirtydayhomes' ); ?></p>

		<nav aria-label="<?php esc_attr_e( 'Footer', 'thirtydayhomes' ); ?>">
			<?php
			wp_nav_menu(
				[
					'theme_location' => 'footer',
					'container'      => false,
					'depth'          => 1,
					'fallback_cb'    => false,
				]
			);
			?>
		</nav>

		<small class="footer-legal">
			<?php
			printf(
				/* translators: 1: current year, 2: site name */
				esc_html__( '© %1$s %2$s. All rights reserved.', 'thirtydayhomes' ),
				esc_html( gmdate( 'Y' ) ),
				esc_html( get_bloginfo( 'name' ) )
			);
			?>
			<br>
			<?php esc_html_e( 'ThirtyDayHomes supports equal access to housing and requires inclusive listing language.', 'thirtydayhomes' ); ?>
		</small>

	</footer>

<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
