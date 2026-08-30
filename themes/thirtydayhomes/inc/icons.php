<?php
/**
 * Icon library.
 *
 * Lucide paths, inlined. Lucide is what the approved prototype used and
 * Elementor does not bundle it, so rather than substituting Font Awesome
 * lookalikes the exact paths live here.
 *
 * Inline SVG rather than an icon font: it inherits currentColor, scales
 * without a second network request, and carries no FOUT.
 *
 * Adding an icon: copy the path data out of the Lucide source. Keep the
 * 24x24 viewBox — every size below assumes it.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Path data, keyed by Lucide icon name.
 *
 * @return array<string,string>
 */
function tdh_icon_paths(): array {
	return [
		'map-pin'       => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
		'calendar-days' => '<path d="M8 2v3"/><path d="M16 2v3"/><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M8 13h.01"/><path d="M12 13h.01"/><path d="M16 13h.01"/><path d="M8 17h.01"/><path d="M12 17h.01"/><path d="M16 17h.01"/>',
		'search'        => '<path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/>',
		'check'         => '<path d="M20 6 9 17l-5-5"/>',
		'arrow-right'   => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
		'arrow-left'    => '<path d="M19 12H5"/><path d="m12 19-7-7 7-7"/>',
		'stethoscope'   => '<path d="M11 2v2"/><path d="M5 2v2"/><path d="M5 3H4a2 2 0 0 0-2 2v4a6 6 0 0 0 12 0V5a2 2 0 0 0-2-2h-1"/><path d="M8 15a6 6 0 0 0 12 0v-3"/><circle cx="20" cy="10" r="2"/>',
		// briefcase-business and hard-hat, exact Lucide paths. Earlier
		// versions here were approximations drawn from memory and did not
		// match the approved prototype.
		'briefcase'     => '<path d="M12 12h.01"/><path d="M16 6V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><path d="M22 13a18.15 18.15 0 0 1-20 0"/><rect width="20" height="14" x="2" y="6" rx="2"/>',
		'hard-hat'      => '<path d="M10 10V5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5"/><path d="M14 6a6 6 0 0 1 6 6v3"/><path d="M4 15v-3a6 6 0 0 1 6-6"/><rect x="2" y="15" width="20" height="4" rx="1"/>',
		'graduation-cap' => '<path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>',
		'map-pinned'    => '<path d="M18 8c0 3.613-3.869 7.429-5.393 8.795a1 1 0 0 1-1.214 0C9.87 15.429 6 11.613 6 8a6 6 0 0 1 12 0"/><circle cx="12" cy="8" r="2"/><path d="M8.714 14h-3.71a1 1 0 0 0-.948.683l-2.004 6A1 1 0 0 0 3 22h18a1 1 0 0 0 .948-1.316l-2-6a1 1 0 0 0-.949-.684h-3.712"/>',
		'shield-check'  => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
		'key-round'     => '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>',
		// Listing card facts.
		'bed-double'    => '<path d="M2 20v-8a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v8"/><path d="M4 10V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v4"/><path d="M12 4v6"/><path d="M2 18h20"/>',
		'bath'          => '<path d="M10 4 8 6"/><path d="M17 19v2"/><path d="M2 12h20"/><path d="M7 19v2"/><path d="M9 5 7.621 3.621A2.121 2.121 0 0 0 4 5v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/>',
		'paw-print'     => '<circle cx="11" cy="4" r="2"/><circle cx="18" cy="8" r="2"/><circle cx="20" cy="16" r="2"/><path d="M9 10a5 5 0 0 1 5 5v3.5a3.5 3.5 0 0 1-6.84 1.045Q6.52 17.48 4.46 16.84A3.5 3.5 0 0 1 5.5 10Z"/>',
		'star'          => '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>',
		'heart'         => '<path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5"/>',
	];
}

/**
 * Icons that are drawn filled rather than as an outline.
 *
 * A star outline reads as "not rated"; the whole point of a rating star is
 * that it is solid. The heart is the opposite — outline means "not saved",
 * and it is filled by CSS only once the visitor has saved the home.
 *
 * @return string[]
 */
function tdh_filled_icons(): array {
	return [ 'star' ];
}

/**
 * Return an inline SVG icon.
 *
 * Always aria-hidden: every icon in this theme sits beside its own visible
 * text label, so announcing it again would only add noise for a screen
 * reader. An icon that ever stands alone needs a real label instead.
 *
 * @param string $name   Lucide icon name.
 * @param int    $size   Rendered size in pixels.
 * @param float  $stroke Stroke width.
 */
function tdh_icon( string $name, int $size = 19, float $stroke = 2 ): string {

	$paths = tdh_icon_paths();

	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}

	$fill = in_array( $name, tdh_filled_icons(), true ) ? 'currentColor' : 'none';

	return sprintf(
		'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="%5$s" stroke="currentColor" stroke-width="%2$s" stroke-linecap="round" stroke-linejoin="round" class="tdh-icon tdh-icon--%3$s" aria-hidden="true" focusable="false">%4$s</svg>',
		$size,
		esc_attr( (string) $stroke ),
		esc_attr( $name ),
		$paths[ $name ],
		esc_attr( $fill )
	);
}

/**
 * Echo an icon.
 */
function tdh_the_icon( string $name, int $size = 19, float $stroke = 2 ): void {
	// Path data is a fixed internal allow-list, not user input.
	echo tdh_icon( $name, $size, $stroke ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
