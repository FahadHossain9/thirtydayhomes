<?php
/**
 * Admin editing UI for marketplace fields.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Admin;

use TDH\Fields;
use TDH\Post_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the marketplace fields in wp-admin.
 *
 * Entirely schema-driven: every control comes from Fields::schema_for().
 * Adding a field to the schema makes it appear here automatically, which
 * is the point — two copies of a field list will always drift apart.
 *
 * This is the ADMIN editor. The landlord-facing front-end submission form
 * is a separate Milestone 2 build; it will read the same schema, so the
 * two can never disagree about what a listing holds.
 */
final class Meta_Boxes {

	private const NONCE_ACTION = 'tdh_save_meta';
	private const NONCE_NAME   = 'tdh_meta_nonce';

	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_boxes' ] );
		add_action( 'save_post', [ $this, 'save' ], 10, 2 );
		add_action( 'admin_head', [ $this, 'styles' ] );
	}

	public function add_boxes(): void {

		// Listings: one panel per group, so the editor is not a wall of inputs.
		foreach ( Fields::listing_groups() as $group => $label ) {
			add_meta_box(
				'tdh_listing_' . $group,
				$label,
				fn( $post ) => $this->render( $post, Post_Types::LISTING, $group ),
				Post_Types::LISTING,
				'moderation' === $group ? 'side' : 'normal',
				'moderation' === $group ? 'default' : 'high'
			);
		}

		add_meta_box(
			'tdh_facility_details',
			__( 'Facility details', 'thirtydayhomes' ),
			fn( $post ) => $this->render( $post, Post_Types::FACILITY, 'facility' ),
			Post_Types::FACILITY,
			'normal',
			'high'
		);

		add_meta_box(
			'tdh_inquiry_details',
			__( 'Inquiry', 'thirtydayhomes' ),
			fn( $post ) => $this->render( $post, Post_Types::INQUIRY, 'inquiry' ),
			Post_Types::INQUIRY,
			'normal',
			'high'
		);
	}

	/**
	 * Render one group of fields.
	 *
	 * @param \WP_Post $post      Post being edited.
	 * @param string   $post_type Post type.
	 * @param string   $group     Group key to render.
	 */
	private function render( \WP_Post $post, string $post_type, string $group ): void {

		static $nonce_printed = false;

		if ( ! $nonce_printed ) {
			wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
			$nonce_printed = true;
		}

		$schema = Fields::schema_for( $post_type );

		echo '<div class="tdh-fields">';

		foreach ( $schema as $key => $def ) {
			if ( ( $def['group'] ?? '' ) !== $group ) {
				continue;
			}

			$raw   = get_post_meta( $post->ID, $key, true );
			$value = ( '' === $raw || null === $raw ) ? ( $def['default'] ?? '' ) : $raw;
			$id    = 'tdh-' . ltrim( $key, '_' );

			echo '<p class="tdh-field">';
			printf(
				'<label for="%s"><strong>%s</strong></label>',
				esc_attr( $id ),
				esc_html( (string) $def['label'] )
			);

			$this->control( $id, $key, $value, $def );

			if ( ! empty( $def['help'] ) ) {
				printf( '<span class="tdh-help">%s</span>', esc_html( (string) $def['help'] ) );
			}

			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Print a single input.
	 *
	 * @param string $id    DOM id.
	 * @param string $key   Meta key, used as the field name.
	 * @param mixed  $value Current value.
	 * @param array  $def   Schema definition.
	 */
	private function control( string $id, string $key, $value, array $def ): void {

		$control = $def['control'] ?? 'text';
		$name    = 'tdh_meta[' . $key . ']';

		switch ( $control ) {

			case 'readonly':
				printf(
					'<input type="text" id="%s" value="%s" class="widefat" readonly disabled>',
					esc_attr( $id ),
					esc_attr( (string) $value )
				);
				break;

			case 'checkbox':
				// Hidden partner so an unchecked box still submits a value.
				printf( '<input type="hidden" name="%s" value="0">', esc_attr( $name ) );
				printf(
					'<input type="checkbox" id="%s" name="%s" value="1"%s>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (bool) $value, true, false )
				);
				break;

			case 'select':
				printf( '<select id="%s" name="%s" class="widefat">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( (array) ( $def['options'] ?? [] ) as $opt_value => $opt_label ) {
					printf(
						'<option value="%s"%s>%s</option>',
						esc_attr( (string) $opt_value ),
						selected( (string) $value, (string) $opt_value, false ),
						esc_html( (string) $opt_label )
					);
				}
				echo '</select>';
				break;

			case 'textarea':
				printf(
					'<textarea id="%s" name="%s" class="widefat" rows="3">%s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_textarea( (string) $value )
				);
				break;

			case 'number':
				printf(
					'<input type="number" step="%s" id="%s" name="%s" value="%s" class="widefat">',
					esc_attr( (string) ( $def['step'] ?? 'any' ) ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				break;

			case 'date':
			case 'email':
			case 'tel':
			case 'text':
			default:
				printf(
					'<input type="%s" id="%s" name="%s" value="%s" class="widefat">',
					esc_attr( 'text' === $control ? 'text' : $control ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				break;
		}
	}

	/**
	 * Persist submitted values.
	 *
	 * Guards, in order: autosave, nonce, capability, known post type,
	 * known meta key. A key not in the schema is never written — a POST
	 * body is attacker-controlled and must not be able to invent meta.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function save( int $post_id, \WP_Post $post ): void {

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_NAME ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) )
			: '';

		if ( ! $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$schema = Fields::schema_for( $post->post_type );
		if ( ! $schema ) {
			return;
		}

		$submitted = isset( $_POST['tdh_meta'] ) && is_array( $_POST['tdh_meta'] )
			? wp_unslash( $_POST['tdh_meta'] )
			: [];

		foreach ( $schema as $key => $def ) {

			// Read-only fields are set by the system, never by a form post.
			if ( 'readonly' === ( $def['control'] ?? '' ) ) {
				continue;
			}

			if ( ! array_key_exists( $key, $submitted ) ) {
				continue;
			}

			$sanitize = Fields::sanitizer( $def['type'] );
			$value    = $sanitize( $submitted[ $key ] );

			// A select may only ever hold one of its declared options.
			if ( 'select' === ( $def['control'] ?? '' ) && ! empty( $def['options'] ) ) {
				if ( ! array_key_exists( (string) $value, $def['options'] ) ) {
					continue;
				}
			}

			update_post_meta( $post_id, $key, $value );
		}
	}

	/**
	 * A few rules so the panels read as deliberate rather than default.
	 */
	public function styles(): void {
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->post_type, [ Post_Types::LISTING, Post_Types::FACILITY, Post_Types::INQUIRY ], true ) ) {
			return;
		}
		?>
		<style>
			.tdh-fields { display: grid; grid-template-columns: repeat( auto-fit, minmax( 220px, 1fr ) ); gap: 4px 20px; }
			.tdh-field { display: flex; flex-direction: column; gap: 4px; margin: 0 0 12px; }
			.tdh-field label { font-size: 12px; }
			.tdh-field input[type="checkbox"] { width: auto; align-self: flex-start; }
			.tdh-help { color: #646970; font-size: 11px; line-height: 1.5; font-style: italic; }
			#tdh_listing_moderation .tdh-fields { grid-template-columns: 1fr; }
		</style>
		<?php
	}
}
