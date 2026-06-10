<?php
/**
 * WP Theme constants and setup functions
 *
 * @package BlankBrandTheme
 */

// Useful global constants.
define( 'BLANK_BRAND_THEME_VERSION', '0.1.0' );
define( 'BLANK_BRAND_THEME_TEMPLATE_URL', get_template_directory_uri() );
define( 'BLANK_BRAND_THEME_PATH', get_template_directory() . '/' );
define( 'BLANK_BRAND_THEME_DIST_PATH', BLANK_BRAND_THEME_PATH . 'dist/' );
define( 'BLANK_BRAND_THEME_DIST_URL', BLANK_BRAND_THEME_TEMPLATE_URL . '/dist/' );
define( 'BLANK_BRAND_THEME_INC', BLANK_BRAND_THEME_PATH . 'src/' );
define( 'BLANK_BRAND_THEME_BLOCK_DIR', BLANK_BRAND_THEME_PATH . 'blocks/' );
define( 'BLANK_BRAND_THEME_BLOCK_DIST_DIR', BLANK_BRAND_THEME_PATH . 'dist/blocks/' );

$is_local_env = in_array( wp_get_environment_type(), [ 'local', 'development' ], true );
$is_local_url = strpos( home_url(), '.test' ) || strpos( home_url(), '.local' );
$is_local     = $is_local_env || $is_local_url;

if ( $is_local && file_exists( __DIR__ . '/dist/fast-refresh.php' ) ) {
	require_once __DIR__ . '/dist/fast-refresh.php';

	if ( function_exists( 'TenUpToolkit\set_dist_url_path' ) ) {
		TenUpToolkit\set_dist_url_path( basename( __DIR__ ), BLANK_BRAND_THEME_DIST_URL, BLANK_BRAND_THEME_DIST_PATH );
	}
}

// Require Composer autoloader if it exists.
if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	throw new Exception( 'Please run `composer install` in your theme directory.' );
}

require_once __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/template-tags.php';

$theme_core = new \BlankBrandTheme\ThemeCore();
$theme_core->setup();
