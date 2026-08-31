<?php
/**
 * Document head and site header.
 *
 * Markup mirrors the approved prototype: a boxed brand mark beside a
 * two-tone wordmark, primary navigation, and a gold call to action.
 *
 * The prototype used <button> elements for navigation because it was a
 * single-page React demo with no URLs. These are real links here — a nav
 * item that cannot be opened in a new tab, bookmarked or crawled would
 * fail the spec's own "clean URLs, indexable" requirement.
 *
 * @package ThirtyDayHomes
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">
	<?php
	/*
	 * Marks the document as scripted, before anything paints.
	 *
	 * The mobile drawer is hidden in CSS and opened by script, so if that
	 * script never runs — blocked, failed, still loading — a phone visitor
	 * would be left with a hamburger that does nothing and no way to reach
	 * any page on the site. Hiding it only under `.has-js` means the
	 * fallback is a plain stacked list that works.
	 *
	 * Inline and in the head deliberately: from the footer it would land
	 * after first paint and the menu would flash open on every load.
	 */
	?>
	<script>document.documentElement.className += ' has-js';</script>
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="skip-link" href="#content"><?php esc_html_e( 'Skip to content', 'thirtydayhomes' ); ?></a>

<?php if ( ! tdh_elementor_location( 'header' ) ) : ?>

	<header class="site-header">

		<a class="logo-link" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
			<?php tdh_the_logo(); ?>
		</a>

		<nav class="site-nav" id="site-nav" aria-label="<?php esc_attr_e( 'Primary', 'thirtydayhomes' ); ?>">
			<?php
			wp_nav_menu(
				[
					'theme_location' => 'primary',
					'container'      => false,
					'depth'          => 1,
					'fallback_cb'    => false,
				]
			);

			/*
			 * No account links are printed here. The Primary menu already
			 * carries "Sign in" and the gold call to action, and hardcoding
			 * a second pair produced three buttons in the header. The
			 * plugin swaps the sign-in item for a dashboard link once
			 * somebody is signed in, which keeps the menu editable by the
			 * client instead of being half template and half database.
			 */
			?>
		</nav>

		<button
			class="nav-toggle"
			type="button"
			aria-controls="site-nav"
			aria-expanded="false"
			aria-label="<?php esc_attr_e( 'Open menu', 'thirtydayhomes' ); ?>"
		>
			<svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
				<path d="M4 5h16"></path><path d="M4 12h16"></path><path d="M4 19h16"></path>
			</svg>
		</button>

	</header>

<?php endif; ?>

<main id="content">
