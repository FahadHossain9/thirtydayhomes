<?php
/**
 * ThirtyDayHomes — theme bootstrap.
 *
 * This file is intentionally thin. All feature logic lives in inc/.
 *
 * PRESENTATION ONLY. If you are about to add a marketplace rule anywhere
 * in this theme — listing visibility, membership gating, ownership checks,
 * proximity maths — it belongs in the ThirtyDayHomes Core plugin instead.
 * The test: if this theme were deleted tomorrow, would any data or
 * behaviour be lost? Nothing here should answer yes.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

const TDH_THEME_VERSION = '0.16.0';

require_once get_template_directory() . '/inc/setup.php';
require_once get_template_directory() . '/inc/icons.php';
require_once get_template_directory() . '/inc/brand.php';
require_once get_template_directory() . '/inc/account.php';
require_once get_template_directory() . '/inc/breadcrumb.php';
require_once get_template_directory() . '/inc/assets.php';
require_once get_template_directory() . '/inc/customizer.php';
require_once get_template_directory() . '/inc/elementor.php';
require_once get_template_directory() . '/inc/security.php';
