<?php
/**
 * Zoho Inventory — inbound stock webhook.
 *
 * Registers `POST /wp-json/tbb/v1/zoho-stock`, called by a Zoho Inventory
 * workflow rule whenever stock changes. Expects a JSON body carrying at least
 * `{ "sku": "..." }` — configure the workflow rule's custom payload to match.
 * Authenticated via a shared secret (see {@see Settings::get_webhook_secret()}),
 * sent as either an `X-Zoho-Webhook-Secret` header or a `secret` query arg.
 *
 * The body is a notification, not a source of truth: only the SKU is read from
 * it. The request queues a job and returns 202; the job fetches the quantity
 * from Zoho and applies it through {@see StockSync::sync_sku()}, the same path
 * the scheduled run uses. Any quantity in the body is ignored: Zoho's workflow
 * payload offers `stock_on_hand`, which counts units already reserved against
 * open sales orders, and publishing that would re-create the overselling this
 * integration exists to prevent.
 *
 * @package TBB\Zoho
 */

namespace TBB\Zoho;

/**
 * Zoho Inventory stock webhook module.
 *
 * @package TBB\Zoho
 */
class StockWebhook {

	/**
	 * Background job that reconciles one SKU.
	 *
	 * @var string
	 */
	const JOB = 'tbb_zoho_sync_sku';

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
		add_action( self::JOB, [ $this, 'run_job' ] );
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
	 * Acknowledge a stock notification and queue the work.
	 *
	 * The request does no Zoho call of its own. Fetching the figure inline held
	 * a PHP worker for a Zoho round trip (a token refresh too, when the token
	 * had expired) on every notification, and a bulk edit in Zoho sends one per
	 * item. Queued as a background job instead, the burst is absorbed: repeated
	 * notifications for the same SKU collapse into one, and the queue runner
	 * works through them at its own pace.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		$sku  = isset( $body['sku'] ) ? sanitize_text_field( (string) $body['sku'] ) : '';

		if ( '' === $sku ) {
			return new \WP_REST_Response( [ 'message' => 'Expected "sku" in the request body.' ], 400 );
		}

		// A local lookup, so an unknown SKU is refused without queueing anything.
		if ( ! wc_get_product_id_by_sku( $sku ) ) {
			wc_get_logger()->warning( sprintf( 'Zoho stock webhook: no product found for SKU "%s".', $sku ), [ 'source' => 'zoho' ] );

			return new \WP_REST_Response( [ 'message' => 'No matching product for that SKU.' ], 404 );
		}

		// Without Action Scheduler there is nothing to queue on, so do the work now.
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$this->run_job( $sku );

			return new \WP_REST_Response( [ 'message' => 'Processed.' ], 200 );
		}

		$args = [ 'sku' => $sku ];

		if ( as_next_scheduled_action( self::JOB, $args, 'zoho' ) ) {
			return new \WP_REST_Response( [ 'message' => 'Already queued.' ], 202 );
		}

		as_enqueue_async_action( self::JOB, $args, 'zoho' );

		return new \WP_REST_Response( [ 'message' => 'Queued.' ], 202 );
	}

	/**
	 * Run the queued reconcile for one SKU.
	 *
	 * A failure is logged and not retried: the ten-minute reconcile covers the
	 * same SKU, so a missed notification costs a delay and nothing more.
	 *
	 * @param string $sku The SKU Zoho reported a change for.
	 * @return void
	 */
	public function run_job( $sku ): void {
		$report = ( new StockSync() )->sync_sku( (string) $sku );

		if ( is_wp_error( $report ) ) {
			wc_get_logger()->warning(
				sprintf( 'Zoho stock webhook for SKU "%s": %s', $sku, $report->get_error_message() ),
				[ 'source' => 'zoho' ]
			);
		}
	}
}
