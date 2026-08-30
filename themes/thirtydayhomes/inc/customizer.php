<?php
/**
 * Customizer settings.
 *
 * Anything a client should be able to change without a developer belongs
 * here rather than in a template.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * @param WP_Customize_Manager $wp_customize Customizer manager.
 */
function tdh_customize_register( $wp_customize ): void {

	$wp_customize->add_section(
		'tdh_hero',
		[
			'title'       => __( 'Hero', 'thirtydayhomes' ),
			'priority'    => 30,
			'description' => __( 'The photograph behind the homepage headline. Use a wide landscape image, at least 1600px across.', 'thirtydayhomes' ),
		]
	);

	$wp_customize->add_setting(
		'tdh_hero_image_id',
		[
			'default'           => 0,
			'sanitize_callback' => 'absint',
			'transport'         => 'refresh',
		]
	);

	$wp_customize->add_control(
		new WP_Customize_Media_Control(
			$wp_customize,
			'tdh_hero_image_id',
			[
				'label'     => __( 'Hero photograph', 'thirtydayhomes' ),
				'section'   => 'tdh_hero',
				'mime_type' => 'image',
			]
		)
	);
}
add_action( 'customize_register', 'tdh_customize_register' );
