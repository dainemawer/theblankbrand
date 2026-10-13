<?php
/**
 * Plugin Name:  The Blank Brand — Zoho Inventory
 * Description:  Syncs stock and pricing from Zoho Inventory into WooCommerce, and pushes orders back to Zoho.
 * Version:      1.0.0
 * Requires PHP: 8.2
 *
 * Loaded as a must-use plugin deliberately. This integration owns stock levels,
 * pricing and the order feed into Zoho; if it stops running, the site quietly
 * keeps selling against stale stock and orders never reach the warehouse. It
 * previously lived in the theme, where switching themes would have disabled all
 * of that with no error and no admin notice. As a must-use plugin it cannot be
 * deactivated by accident, and it no longer depends on any theme.
 *
 * @package TBB\Zoho
 */

namespace TBB\Zoho;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TBB_ZOHO_PATH', __DIR__ . '/' );
define( 'TBB_ZOHO_VERSION', '1.0.0' );

/**
 * Autoload this integration's classes.
 *
 * Self-contained on purpose: the theme's Composer autoloader is not available
 * to a must-use plugin, and depending on it would reintroduce the coupling this
 * move exists to remove.
 */
spl_autoload_register(
	static function ( $class_name ) {
		if ( ! str_starts_with( $class_name, __NAMESPACE__ . '\\' ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( __NAMESPACE__ ) + 1 );
		$file     = TBB_ZOHO_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Every module this integration registers, in load order.
 *
 * @return string[]
 */
function modules(): array {
	return [
		Settings::class,      // Credentials first: the others ask it whether they may run.
		StockSync::class,
		StockWebhook::class,
		OrderSync::class,
		Mail::class,          // One sender for everything the shop sends.
		StatusReport::class,
	];
}

/**
 * Boot the integration.
 *
 * Runs on `plugins_loaded` rather than at file load: must-use plugins load
 * before regular ones, so WooCommerce does not exist yet when this file is
 * included, and every module's `can_register()` asks about it.
 *
 * @return void
 */
function bootstrap(): void {
	foreach ( modules() as $class ) {
		$module = new $class();

		if ( $module->can_register() ) {
			$module->register();
		}
	}
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap', 20 );
