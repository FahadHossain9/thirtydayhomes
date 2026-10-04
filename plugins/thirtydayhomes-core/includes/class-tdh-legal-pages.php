<?php
/**
 * The three legal pages, as a visitor should read them.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * Terms of Service, Privacy Policy and Fair Housing (G3b design review).
 *
 * Their text lives in the page editor, seeded by the importer, and two
 * things about it were wrong for a visitor and right only for us:
 *
 * - The seeded Terms wrote its two links out as bare URLs. On a phone the
 *   contact address took a line of its own, and neither was clickable.
 * - Each page ends on an editor's note — "Still to come", "Draft copy" —
 *   which is a message to the client, not a term of anything, and a
 *   visitor reading it under the policy loses trust in the policy.
 *
 * Both are corrected as the page is printed rather than by rewriting the
 * stored content, so nothing a client may have edited is touched, an
 * older page and a freshly imported one read the same, and staff — who
 * are the people the notes are for — still see them, labelled. Matched on
 * the importer's `_tdh_seed_key`, never on the slug, like every other
 * page rule in the plugin.
 */
final class Legal_Pages {

	/** The seeded pages this applies to. */
	public const KEYS = [ 'terms', 'privacy', 'fair-housing' ];

	public function register(): void {
		// Before wpautop (10), so the note's wrapper is still one block.
		add_filter( 'the_content', [ $this, 'filter' ], 9 );
	}

	/**
	 * Is this one of the three?
	 */
	public static function is_legal( int $post_id ): bool {
		return $post_id > 0 && in_array( (string) get_post_meta( $post_id, '_tdh_seed_key', true ), self::KEYS, true );
	}

	/**
	 * The `the_content` callback.
	 */
	public function filter( string $content ): string {

		$post_id = (int) get_the_ID();

		if ( ! self::is_legal( $post_id ) ) {
			return $content;
		}

		return self::prepare( $content, Accounts::is_staff() );
	}

	/**
	 * The content as it should print.
	 *
	 * Separate from the hook so a test can hand it a page's stored content
	 * and read both answers without signing anyone in.
	 *
	 * @param bool $staff Whether the reader is staff.
	 */
	public static function prepare( string $content, bool $staff ): string {

		/*
		 * Bare URLs become links. Only a URL that is not already inside a
		 * tag (an href) or already the text of one (after ">"), and the
		 * trailing full stop the seed put after each one stays outside the
		 * link. The visible text drops the scheme: "thirtydayhomes.com/contact"
		 * is what a person reads out, and it no longer needs a line of its
		 * own on a phone.
		 */
		$content = (string) preg_replace_callback(
			'~(?<![="\'>/\w])https?://[^\s<"\']+~',
			static function ( array $m ): string {
				$url  = rtrim( $m[0], '.,;:' );
				$tail = substr( $m[0], strlen( $url ) );
				$text = (string) preg_replace( '~^https?://~', '', rtrim( $url, '/' ) );

				return '<a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>' . $tail;
			},
			$content
		);

		if ( $staff ) {
			// Kept, and named as what it is.
			return str_replace( '<div class="page-note">', '<div class="page-note page-note--staff">', $content );
		}

		// The note goes, and so does a heading that had nothing under it but
		// the note ("The rest of these terms").
		return (string) preg_replace( '~(<h2>[^<]*</h2>\s*)?<div class="page-note">.*?</div>~s', '', $content );
	}
}
