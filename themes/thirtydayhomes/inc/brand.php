<?php
/**
 * Brand mark, wordmark and the hero photograph.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * The two-tone wordmark: "ThirtyDay" in white, "Homes" in gold.
 *
 * Split from the site title rather than hardcoded, so renaming the site in
 * Settings renames the logo. That matters here — the final business name is
 * still an open decision, and a hardcoded wordmark would be one more place
 * to remember to change.
 *
 * @return array{0:string,1:string} [ leading text, accent text ]
 */
function tdh_wordmark_parts(): array {

	$name = trim( (string) get_bloginfo( 'name' ) );

	// "ThirtyDayHomes" -> ThirtyDay | Homes
	if ( preg_match( '/^(.*[a-z])([A-Z][a-z]+)$/', $name, $m ) ) {
		return [ $m[1], $m[2] ];
	}

	// "30 Day Homes" -> 30 Day | Homes
	$pos = strrpos( $name, ' ' );
	if ( false !== $pos ) {
		return [ substr( $name, 0, $pos ), substr( $name, $pos + 1 ) ];
	}

	return [ $name, '' ];
}

/**
 * Print the brand mark and wordmark.
 *
 * Uses the WordPress custom logo when one is set, so the client can change
 * it without a developer. Falls back to the bundled mark.
 */
function tdh_the_logo(): void {

	[ $lead, $accent ] = tdh_wordmark_parts();

	$custom_id = (int) get_theme_mod( 'custom_logo' );
	$src       = $custom_id
		? wp_get_attachment_image_url( $custom_id, 'full' )
		: get_template_directory_uri() . '/assets/brand-mark.webp';
	?>
	<span class="logo">
		<i>
			<?php
			// Intrinsic dimensions, not display dimensions — the browser
			// needs the real aspect ratio to reserve space and avoid a
			// layout shift. CSS sizes it down to 51x59.
			?>
			<img src="<?php echo esc_url( (string) $src ); ?>" alt="" width="160" height="129" decoding="async">
		</i>
		<span>
			<b><?php echo esc_html( $lead ); ?></b><strong><?php echo esc_html( $accent ); ?></strong>
		</span>
	</span>
	<?php
}

/**
 * The homepage hero background.
 *
 * Served from the theme rather than hotlinked from a stock photo CDN.
 * Hotlinking put an external host on the critical path of every homepage
 * load, and left the client depending on someone else's URL staying alive.
 *
 * Returns the client's own upload when one is set, so replacing the photo
 * is a Customizer task rather than a developer task.
 */
function tdh_hero_image(): string {

	$custom = (int) get_theme_mod( 'tdh_hero_image_id' );

	if ( $custom ) {
		$url = wp_get_attachment_image_url( $custom, 'full' );
		if ( $url ) {
			return $url;
		}
	}

	return get_template_directory_uri() . '/assets/hero-home.webp';
}
