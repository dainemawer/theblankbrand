<?php
/**
 * Zoho Inventory — stock reconciliation safety net.
 *
 * The webhook in {@see StockWebhook} is the primary path for keeping
 * WooCommerce stock in sync with Zoho; this recurring Action Scheduler job
 * is a backstop that walks every Zoho item and reapplies its stock level, in
 * case a webhook call is ever missed.
 *
 * @package BlankBrandTheme
 */

namespace BlankBrandTheme\Zoho;

use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * Zoho Inventory stock reconciliation module.
 *
 * @package BlankBrandTheme
 */
class StockSync implements ModuleInterface {

	use Module;

	const ACTION = 'tbb_zoho_reconcile_stock';

	const INTERVAL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Can this module be registered?
	 *
	 * @return bool
	 */
	public function can_register(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'as_schedule_recurring_action' ) && Settings::is_enabled();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'maybe_schedule' ] );
		add_action( self::ACTION, [ $this, 'reconcile' ] );
	}

	/**
	 * Schedule the recurring reconciliation job if it isn't already queued.
	 *
	 * @return void
	 */
	public function maybe_schedule(): void {
		if ( ! as_next_scheduled_action( self::ACTION, [], 'zoho' ) ) {
			as_schedule_recurring_action( time() + self::INTERVAL, self::INTERVAL, self::ACTION, [], 'zoho' );
		}
	}

	/**
	 * Walk every Zoho item and reapply its stock level to the matching
	 * WooCommerce product/variation.
	 *
	 * @return void
	 */
	public function reconcile(): void {
		$client = new Client();
		$page   = 1;

		do {
			$response = $client->list_items( $page );

			if ( is_wp_error( $response ) ) {
				wc_get_logger()->error( 'Zoho stock reconciliation failed: ' . $response->get_error_message(), [ 'source' => 'zoho' ] );
				return;
			}

			foreach ( $response['items'] ?? [] as $item ) {
				$this->apply_stock( $item );
			}

			$has_more = ! empty( $response['page_context']['has_more_page'] );
			++$page;
		} while ( $has_more );
	}

	/**
	 * Apply a single Zoho item's stock level to the matching product.
	 *
	 * @param array $item Zoho item record.
	 * @return void
	 */
	private function apply_stock( array $item ): void {
		$sku = $item['sku'] ?? '';

		if ( '' === $sku || ! isset( $item['stock_on_hand'] ) ) {
			return;
		}

		$product_id = wc_get_product_id_by_sku( $sku );

		if ( ! $product_id ) {
			return;
		}

		$product = wc_get_product( $product_id );

		if ( $product instanceof \WC_Product ) {
			wc_update_product_stock( $product, (int) $item['stock_on_hand'], 'set' );
		}
	}
}
