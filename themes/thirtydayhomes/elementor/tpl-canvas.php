<?php
/**
 * Template Name: Canvas
 *
 * No header, no footer, no navigation — just the document shell and the
 * page content.
 *
 * For landing pages and anything where the site chrome would compete with
 * the message. Note that a page with no navigation is a dead end for a
 * visitor, so whatever is built here needs to provide its own way out.
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
	<?php wp_head(); ?>
</head>

<body <?php body_class( 'tdh-canvas' ); ?>>
<?php wp_body_open(); ?>

<main id="content">
	<?php
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;
	?>
</main>

<?php wp_footer(); ?>
</body>
</html>
