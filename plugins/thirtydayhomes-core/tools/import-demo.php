<?php
/**
 * Demo import — command line entry point.
 *
 * Run with:
 *   cd D:\xampp\htdocs\thirtydayhomes
 *   php D:\xampp\wp-cli.phar eval-file wp-content/plugins/thirtydayhomes-core/tools/import-demo.php
 *
 * Optional: import only some steps.
 *   ... tools/import-demo.php structure
 *   ... tools/import-demo.php content homepage
 *
 * This is a THIN WRAPPER. All of the logic lives in TDH\Setup\Importer,
 * which the admin screen (Tools → Import Demo Content) calls as well — so
 * a fix reaches both entry points and neither can drift from the other.
 *
 * @package ThirtyDayHomes
 */

// No declare(strict_types) — `wp eval-file` eval()s this file's contents.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "Run this through WP-CLI, or use Tools → Import Demo Content in wp-admin.\n" );
}

$steps = array_values( array_filter( (array) ( $args ?? [] ) ) );

if ( $steps ) {
	WP_CLI::log( 'Steps: ' . implode( ', ', $steps ) );
} else {
	WP_CLI::log( 'Importing everything.' );
}

$result = ( new TDH\Setup\Importer() )->run( $steps );

if ( $result['failed'] ) {
	WP_CLI::warning( 'Import finished with warnings — see above.' );
} else {
	WP_CLI::success( 'Demo content imported.' );
}
