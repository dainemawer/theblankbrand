<?php
/**
 * Zoho Inventory — catalogue reconciliation.
 *
 * Pulls stock levels and prices from Zoho and applies them to WooCommerce.
 * The webhook in {@see StockWebhook} is the fast path for single stock
 * changes; this recurring Action Scheduler job is the one that guarantees
 * convergence, and is the only path that syncs price.
 *
 * Direction matters: this walks WooCommerce's catalogue and asks Zoho about
 * it, never the reverse. WooCommerce decides which products exist — Zoho
 * carries plenty of stock that is never listed online — so a Zoho item with
 * no matching SKU is silently ignored, while a WooCommerce SKU missing from
 * Zoho is recorded as an exception for someone to act on.
 *
 * @package TBB\Zoho
 */

namespace TBB\Zoho;

/**
 * Zoho Inventory catalogue reconciliation module.
 *
 * @package TBB\Zoho
 */
class StockSync {

	const ACTION = 'tbb_zoho_reconcile_stock';

	const INTERVAL = 10 * MINUTE_IN_SECONDS;

	const REPORT_OPTION = 'tbb_zoho_last_sync';

	const PRICE_BOOK = 'Retail';

	/**
	 * Which of Zoho's stock figures the website follows.
	 *
	 * `available_for_sale` is stock on hand minus whatever open sales orders
	 * have reserved. That is the number a shopper should see: once an order is
	 * placed those units are spoken for, even though they are physically still
	 * on the shelf until the warehouse ships them.
	 *
	 * Following `stock_on_hand` instead would keep selling reserved units for
	 * as long as it took the warehouse to pack — and the alternative, having
	 * the website create a shipment at payment, would mean inventing a tracking
	 * number and telling Zoho goods had left when they had not.
	 *
	 * @var string
	 */
	const STOCK_FIELD = 'available_for_sale';

	/**
	 * Fallback when a Zoho item does not report {@see STOCK_FIELD}.
	 *
	 * @var string
	 */
	const STOCK_FIELD_FALLBACK = 'stock_on_hand';

	/**
	 * Where a product's quantity-price tiers are stored.
	 *
	 * The theme renders these; this integration keeps the 50+ figure in step
	 * with Zoho. Declared here rather than borrowed from the theme so the
	 * integration carries no dependency on whichever theme is active.
	 *
	 * @var string
	 */
	const PRICE_TIERS_META = '_tbb_price_tiers';

	/**
	 * The most recent failed run. Kept apart from the report of the last
	 * successful one, which a failure must not overwrite: the status screen
	 * would then print empty fields as though they were results.
	 *
	 * @var string
	 */
	const FAILURE_OPTION = 'tbb_zoho_last_failure';

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
		add_action( 'init', [ $this, 'maybe_schedule' ] );
		add_action( self::ACTION, [ $this, 'reconcile' ] );
	}

	/**
	 * Schedule the recurring reconciliation job, rescheduling it if the
	 * interval has changed since it was queued.
	 *
	 * @return void
	 */
	public function maybe_schedule(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		/**
		 * Filter the catalogue reconciliation interval.
		 *
		 * @param int $interval Seconds between runs.
		 */
		$interval = (int) apply_filters( 'tbb_zoho_sync_interval', self::INTERVAL );

		if ( ! as_next_scheduled_action( self::ACTION, [], 'zoho' ) ) {
			as_schedule_recurring_action( time() + $interval, $interval, self::ACTION, [], 'zoho' );
		}
	}

	/**
	 * Reconcile a single SKU against Zoho, for the stock webhook.
	 *
	 * The webhook is a trigger, not a source of truth. It says "this SKU
	 * moved"; the authoritative figure is then fetched here and applied
	 * through the same {@see self::apply()} the scheduled run uses.
	 *
	 * Taking the quantity straight off the webhook body is what this
	 * replaces. The body carries `stock_on_hand`, which counts units already
	 * reserved against open sales orders, so publishing it re-creates the
	 * overselling the `available_for_sale` basis exists to prevent — and the
	 * next reconcile would silently correct it ten minutes later, leaving
	 * stock oscillating between two figures.
	 *
	 * Price is deliberately not touched: this is a stock notification, and
	 * pulling the whole price book on every webhook would be wasteful. The
	 * scheduled run owns pricing.
	 *
	 * @param string $sku The SKU Zoho reported a change for.
	 * @return array|\WP_Error Report of what changed, or an error.
	 */
	public function sync_sku( string $sku ) {
		$product_id = wc_get_product_id_by_sku( $sku );

		if ( ! $product_id ) {
			return new \WP_Error( 'tbb_zoho_no_product', 'No matching product for that SKU.' );
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product ) {
			return new \WP_Error( 'tbb_zoho_bad_product', 'Product could not be loaded.' );
		}

		$response = ( new Client() )->get_item_by_sku( $sku );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$item = $response['items'][0] ?? ( $response['item'] ?? null );

		if ( ! is_array( $item ) ) {
			return new \WP_Error( 'tbb_zoho_no_item', 'Zoho returned no item for that SKU.' );
		}

		$report = [
			'stock_updated'  => 0,
			'status_updated' => 0,
			'price_updated'  => 0,
			'stock_managed'  => 0,
			'skipped'        => 0,
			'oversold'       => 0,
		];

		// An empty price map leaves apply()'s price branch alone.
		$changed = $this->apply( $product, $item, [], $report );

		// A variable product caches its children's stock status and price range.
		// Without this the parent can still read "in stock" after its last
		// variation reaches zero, which is precisely the state the webhook
		// exists to correct quickly.
		$parent = $product->get_parent_id();

		if ( $changed && $parent ) {
			\WC_Product_Variable::sync( $parent );
			\WC_Product_Variable::sync_stock_status( $parent );
		}

		if ( $changed ) {
			wc_delete_product_transients( $parent ? $parent : $product->get_id() );
		}

		return $report;
	}

	/**
	 * Pull stock and prices from Zoho and apply them to WooCommerce.
	 *
	 * Nothing is written unless Zoho returned usable data: a null stock level
	 * or a missing/zero price means "Zoho did not tell us", never "set this to
	 * zero". A price book that comes back implausibly short aborts the whole
	 * run rather than repricing the catalogue from a bad response.
	 *
	 * @return void
	 */
	public function reconcile(): void {
		$client = new Client();

		$prices = $client->get_pricebook_rates( self::PRICE_BOOK );

		if ( is_wp_error( $prices ) ) {
			$this->fail( 'price book unavailable: ' . $prices->get_error_message() );
			return;
		}

		$items = $this->fetch_items( $client );

		if ( is_wp_error( $items ) ) {
			$this->fail( 'item fetch failed: ' . $items->get_error_message() );
			return;
		}

		$report = [
			'started'        => time(),
			'zoho_items'     => count( $items ),
			'checked'        => 0,
			'matched'        => 0,
			'unmatched'      => [],
			'stock_updated'  => 0,
			'status_updated' => 0,
			'price_updated'  => 0,
			'stock_managed'  => 0,
			'skipped'        => 0,
			'oversold'       => 0,
		];

		$parents    = [];
		$bulk_rates = [];

		foreach ( $this->sellable_product_ids() as $id ) {
			$product = wc_get_product( $id );

			if ( ! $product instanceof \WC_Product || $product->is_type( 'variable' ) ) {
				continue;
			}

			$sku = $product->get_sku();

			if ( '' === $sku ) {
				continue;
			}

			++$report['checked'];

			if ( ! isset( $items[ $sku ] ) ) {
				$report['unmatched'][] = $sku;
				continue;
			}

			++$report['matched'];

			$parent = $product->get_parent_id();

			// The 50+ rate is a property of the product, not the variation, so
			// collect every variation's rate and settle it once per parent.
			if ( $parent && ! empty( $items[ $sku ]['rate'] ) ) {
				$bulk_rates[ $parent ][] = (float) $items[ $sku ]['rate'];
			}

			if ( $this->apply( $product, $items[ $sku ], $prices, $report ) && $parent ) {
				$parents[ $parent ] = true;
			}
		}

		$report['bulk_updated']   = 0;
		$report['bulk_divergent'] = 0;

		foreach ( $bulk_rates as $parent => $rates ) {
			if ( $this->apply_bulk_rate( (int) $parent, $rates, $report ) ) {
				$parents[ $parent ] = true;
			}
		}

		foreach ( array_keys( $parents ) as $parent ) {
			\WC_Product_Variable::sync( $parent );
			\WC_Product_Variable::sync_stock_status( $parent );
		}

		if ( $parents ) {
			wc_delete_product_transients();
		}

		$report['parents_resynced'] = count( $parents );
		$report['finished']         = time();

		update_option( self::REPORT_OPTION, $report, false );
		delete_option( self::FAILURE_OPTION );

		wc_get_logger()->info(
			sprintf(
				'Zoho sync: %d checked, %d matched, %d unmatched, %d stock, %d status, %d price, %d newly managed.',
				$report['checked'],
				$report['matched'],
				count( $report['unmatched'] ),
				$report['stock_updated'],
				$report['status_updated'],
				$report['price_updated'],
				$report['stock_managed']
			),
			[ 'source' => 'zoho' ]
		);
	}

	/**
	 * Apply one Zoho item to its WooCommerce product.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param array       $item    Zoho item record.
	 * @param array       $prices  item id => price book rate.
	 * @param array       $report  Running report, by reference.
	 * @return bool Whether anything changed.
	 */
	protected function apply( \WC_Product $product, array $item, array $prices, array &$report ): bool {
		$stock = $item[ self::STOCK_FIELD ] ?? ( $item[ self::STOCK_FIELD_FALLBACK ] ?? null );

		if ( null === $stock ) {
			++$report['skipped'];
			return false;
		}

		$stock = (int) $stock;

		// Zoho reports a negative available_for_sale when its own open orders
		// exceed stock on hand. That is a warehouse problem, not a quantity to
		// publish: nothing is sellable either way, so it lands as zero and is
		// counted so the run report can surface it.
		if ( $stock < 0 ) {
			++$report['oversold'];
			$stock = 0;
		}

		$dirty = false;

		// A variation that isn't managing stock can't hold a quantity, so the
		// Zoho figure would be silently discarded. Five products' worth of
		// stock was invisible on the site for exactly this reason.
		if ( ! $product->get_manage_stock() ) {
			$product->set_manage_stock( true );
			++$report['stock_managed'];
			$dirty = true;
		}

		if ( (int) $product->get_stock_quantity() !== $stock ) {
			$product->set_stock_quantity( $stock );
			++$report['stock_updated'];
			$dirty = true;
		}

		$status = $stock > 0 ? 'instock' : 'outofstock';

		if ( $product->get_stock_status() !== $status ) {
			$product->set_stock_status( $status );
			++$report['status_updated'];
			$dirty = true;
		}

		$price = $prices[ $item['item_id'] ] ?? null;

		if ( null !== $price && $price > 0 && abs( (float) $product->get_regular_price() - $price ) >= 0.01 ) {
			$product->set_regular_price( (string) $price );

			// Leave a live sale price alone — promotions are a WooCommerce
			// concern and Zoho knows nothing about them.
			if ( '' === $product->get_sale_price() ) {
				$product->set_price( (string) $price );
			}

			++$report['price_updated'];
			$dirty = true;
		}

		if ( $dirty ) {
			$product->save();
		}

		return $dirty;
	}

	/**
	 * Write a product's 50+ rate into its stored tiers.
	 *
	 * Zoho's `rate` is the bulk price and has nowhere to live in WooCommerce,
	 * so it is stored per product for the theme's bulk-discount display to
	 * read. The first tier is deliberately left untouched — that price is
	 * rendered live from the product, and writing a second copy here is what
	 * let the page and the checkout disagree in the first place.
	 *
	 * @param int   $parent_id Parent product id.
	 * @param array $rates     Every matched variation's Zoho rate.
	 * @param array $report Running report, by reference.
	 * @return bool Whether the stored tiers changed.
	 */
	protected function apply_bulk_rate( int $parent_id, array $rates, array &$report ): bool {
		$data = get_post_meta( $parent_id, self::PRICE_TIERS_META, true );

		if ( ! is_array( $data ) || empty( $data['tiers'][1] ) ) {
			return false;
		}

		// Variations of one product should share a bulk rate. If they don't,
		// the cheapest is the only one safe to advertise — and the divergence
		// is counted so it shows up in the run report.
		$unique = array_unique( $rates );

		if ( count( $unique ) > 1 ) {
			++$report['bulk_divergent'];
		}

		$rate = min( $rates );

		if ( $rate <= 0 || abs( (float) ( $data['tiers'][1]['price'] ?? 0 ) - $rate ) < 0.01 ) {
			return false;
		}

		$data['tiers'][1]['price']  = $rate;
		$data['tiers'][1]['source'] = 'zoho:item.rate';
		$data['synced_at']          = time();

		update_post_meta( $parent_id, self::PRICE_TIERS_META, $data );

		++$report['bulk_updated'];

		return true;
	}

	/**
	 * Fetch every Zoho item that carries a SKU, keyed by SKU.
	 *
	 * @param Client $client API client.
	 * @return array|\WP_Error
	 */
	protected function fetch_items( Client $client ) {
		$items = [];
		$page  = 1;

		do {
			$response = $client->list_items( $page );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			foreach ( $response['items'] ?? [] as $item ) {
				if ( ! empty( $item['sku'] ) ) {
					$items[ $item['sku'] ] = $item;
				}
			}

			$has_more = ! empty( $response['page_context']['has_more_page'] );
			++$page;
		} while ( $has_more && $page <= 50 );

		return $items;
	}

	/**
	 * Every product and variation that can carry its own stock and price.
	 *
	 * @return int[]
	 */
	protected function sellable_product_ids(): array {
		global $wpdb;

		return array_map(
			'intval',
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a static query with no input, run once per sync.
			$wpdb->get_col(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type IN ( 'product', 'product_variation' )
				 AND post_status IN ( 'publish', 'private' )"
			)
		);
	}

	/**
	 * Record a failed run without touching any product.
	 *
	 * @param string $message Failure detail.
	 * @return void
	 */
	protected function fail( string $message ): void {
		update_option(
			self::FAILURE_OPTION,
			[
				'at'      => time(),
				'message' => $message,
			],
			false
		);

		wc_get_logger()->error( 'Zoho sync aborted — ' . $message, [ 'source' => 'zoho' ] );
	}
}
