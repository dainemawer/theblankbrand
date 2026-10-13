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

// Pure template helpers have no dependencies, and the templates call them.
require_once __DIR__ . '/template-tags.php';

// Require Composer autoloader if it exists.
//
// vendor/ is not committed, so a deploy or a fresh clone can arrive without it.
// Throwing here took down every request, the login page included, which left no
// admin to read an error in. Say so in the admin and in the log, and stop
// loading the theme's modules. The header still needs the theme's own walker
// class, so that namespace gets a small loader of its own; the modules, which
// need the framework from vendor/, stay off. The site stays up, without their
// features, until `composer install` is run.
if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	spl_autoload_register(
		static function ( $class_name ) {
			$prefix = 'BlankBrandTheme\\';

			if ( 0 !== strpos( $class_name, $prefix ) ) {
				return;
			}

			$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);

	// Normally declared by ThemeCore, which needs the framework. Without it
	// WooCommerce treats this as an unsupported theme and its shop pages 404.
	add_action(
		'after_setup_theme',
		static function () {
			add_theme_support( 'woocommerce' );
		}
	);

	error_log( 'The Blank Brand theme: vendor/autoload.php is missing. Run `composer install` in the theme directory.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' . wp_kses_post(
				__( '<strong>The Blank Brand theme is not fully installed.</strong> Run <code>composer install</code> in the theme directory.', 'blank-brand-theme' )
			) . '</p></div>';
		}
	);

	return;
}

require_once __DIR__ . '/vendor/autoload.php';

$theme_core = new \BlankBrandTheme\ThemeCore();
$theme_core->setup();
