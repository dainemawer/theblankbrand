<?php
/**
 * WP Constants used by PHPStan
 *
 * These should be updated to match constants that are set in any custom plugins or themes that will be anylised.
 *
 * @package TenUpPhpStan
 */

// Change these when you update the constants in the plugin.
define( 'TENUP_PLUGIN_VERSION', '0.1.0' );
define( 'TENUP_PLUGIN_URL', '' );
define( 'TENUP_PLUGIN_PATH', '' );
define( 'TENUP_PLUGIN_INC', TENUP_PLUGIN_PATH . 'includes/' );

// Change these when you update the constants in the theme.
define( 'BLANK_BRAND_THEME_VERSION', '0.1.0' );
define( 'BLANK_BRAND_THEME_TEMPLATE_URL', '' );
define( 'BLANK_BRAND_THEME_PATH', '/' );
define( 'BLANK_BRAND_THEME_DIST_PATH', BLANK_BRAND_THEME_PATH . 'dist/' );
define( 'BLANK_BRAND_THEME_DIST_URL', BLANK_BRAND_THEME_TEMPLATE_URL . '/dist/' );
define( 'BLANK_BRAND_THEME_INC', BLANK_BRAND_THEME_PATH . 'includes/' );
define( 'BLANK_BRAND_THEME_BLOCK_DIR', BLANK_BRAND_THEME_INC . 'blocks/' );

define( 'TENUP_BLOCK_THEME_VERSION', '1.0.0' );
define( 'TENUP_BLOCK_THEME_TEMPLATE_URL', '' );
define( 'TENUP_BLOCK_THEME_PATH', '/' );
define( 'TENUP_BLOCK_THEME_DIST_PATH', TENUP_BLOCK_THEME_PATH . 'dist/' );
define( 'TENUP_BLOCK_THEME_DIST_URL', TENUP_BLOCK_THEME_TEMPLATE_URL . '/dist/' );
define( 'TENUP_BLOCK_THEME_INC', TENUP_BLOCK_THEME_PATH . 'includes/' );
define( 'TENUP_BLOCK_THEME_BLOCK_DIST_DIR', TENUP_BLOCK_THEME_DIST_PATH . '/blocks/' );
