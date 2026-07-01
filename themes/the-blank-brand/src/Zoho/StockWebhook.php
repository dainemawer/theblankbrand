<?php
/**
 * Zoho Inventory — inbound stock webhook.
 *
 * Registers `POST /wp-json/tbb/v1/zoho-stock`, called by a Zoho Inventory
 * workflow rule whenever stock changes. Expects a JSON body of
 * `{ "sku": "...", "stock_on_hand": 12 }` — configure the workflow rule's
 * custom payload to match. Authenticated via a shared secret (see
 * {@see Settings::get_webhook_secret()}), sent as either an
 * `X-Zoho-Webhook-Secret` header or a `secret` query arg.
 *
 * @package BlankBrandTheme
 */

namespace BlankBrandTheme\Zoho;

use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * Zoho Inventory stock webhook module.
 *
 * @package BlankBrandTheme
 */
class StockWebhook implements ModuleInterface {

	use Module;

	/**
	 * Can this module be registered?
	 *
	 * @return bool
	 */
	public function can_register(): bool {
		return class_exists( 'WooCommerce' ) && Settings::is_enabled();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the webhook route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'tbb/v1',
			'/zoho-stock',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => [ $this, 'verify_request' ],
			]
		);
	}

	/**
	 * Verify the shared secret sent by the Zoho workflow rule.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool
	 */
	public function verify_request( \WP_REST_Request $request ): bool {
		$provided = $request->get_header( 'X-Zoho-Webhook-Secret' );

		if ( ! $provided ) {
			$provided = $request->get_param( 'secret' );
		}

		return is_string( $provided ) && hash_equals( Settings::get_webhook_secret(), $provided );
	}

	/**
	 * Apply an incoming stock update to the matching WooCommerce product.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		$sku  = isset( $body['sku'] ) ? sanitize_text_field( (string) $body['sku'] ) : '';

		if ( '' === $sku || ! isset( $body['stock_on_hand'] ) ) {
			return new \WP_REST_Response( [ 'message' => 'Expected "sku" and "stock_on_hand" in the request body.' ], 400 );
		}

		$product_id = wc_get_product_id_by_sku( $sku );

		if ( ! $product_id ) {
			wc_get_logger()->warning( sprintf( 'Zoho stock webhook: no product found for SKU "%s".', $sku ), [ 'source' => 'zoho' ] );

			return new \WP_REST_Response( [ 'message' => 'No matching product for that SKU.' ], 404 );
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product ) {
			return new \WP_REST_Response( [ 'message' => 'Product could not be loaded.' ], 404 );
		}

		wc_update_product_stock( $product, (int) $body['stock_on_hand'], 'set' );

		return new \WP_REST_Response( [ 'message' => 'Stock updated.' ], 200 );
	}
}
