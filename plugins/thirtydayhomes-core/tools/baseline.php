<?php
/**
 * Security baseline — the command-line view.
 *
 *   wp eval-file wp-content/plugins/thirtydayhomes-core/tools/baseline.php
 *
 * Read-only: it changes nothing and sends nothing. Exits non-zero if
 * anything failed, so it can go into CI or a deploy log later.
 *
 * The checks themselves live in TDH\Security_Baseline, because the same list
 * is rendered at Listings → Security for everyone without a terminal. This
 * file is only a printer — if you came here to add a check, add it there.
 *
 * @package ThirtyDayHomes
 */

use TDH\Security_Baseline;

if ( ! class_exists( Security_Baseline::class ) ) {
	echo "  The ThirtyDayHomes Core plugin is not active on this site.\n";
	return;
}

$results = ( new Security_Baseline() )->run();
$counts  = Security_Baseline::summary( $results );

printf( "\n  ThirtyDayHomes security baseline\n" );
printf( "  %s\n", str_repeat( '=', 68 ) );
printf( "  site        %s\n", home_url() );
printf( "  environment %s\n", wp_get_environment_type() );
printf( "  checked     %s UTC\n", gmdate( 'Y-m-d H:i' ) );
printf( "  %s\n", str_repeat( '=', 68 ) );

$group = '';

foreach ( $results as $r ) {

	if ( $r['group'] !== $group ) {
		$group = $r['group'];
		printf( "\n=== %s ===\n", strtolower( $group ) );
	}

	$mark = [
		Security_Baseline::PASS => 'ok  ',
		Security_Baseline::FAIL => 'FAIL',
		Security_Baseline::SKIP => '--  ',
		Security_Baseline::NOTE => 'note',
	][ $r['status'] ] ?? '?   ';

	printf( "  %s  %-46s %s\n", $mark, $r['label'], $r['detail'] );

	if ( '' !== $r['fix'] ) {
		printf( "        %s\n", $r['fix'] );
	}
}

printf( "\n  %s\n", str_repeat( '=', 68 ) );
printf(
	"  %s   %d passed, %d failed, %d not applicable here\n",
	$counts['fail'] ? 'ATTENTION' : 'CLEAN    ',
	$counts['pass'],
	$counts['fail'],
	$counts['skip']
);
printf( "  %s\n\n", str_repeat( '=', 68 ) );

if ( ! Security_Baseline::is_production() ) {
	printf( "  This is a %s environment. The production-only checks were skipped,\n", wp_get_environment_type() );
	printf( "  so a clean run here says nothing about the live site.\n\n" );
}

printf( "  The same list is at Listings → Security in wp-admin.\n\n" );

if ( $counts['fail'] && defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::halt( 1 );
}
