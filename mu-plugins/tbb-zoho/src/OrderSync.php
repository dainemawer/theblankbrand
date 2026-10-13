<?php
/**
 * Zoho Inventory — order sync + warehouse notification.
 *
 * On payment completion, schedules two independent Action Scheduler jobs so
 * neither a Zoho outage nor a mail failure blocks the other: one creates the
 * matching Sales Order in Zoho Inventory, the other emails the warehouse the
 * pick/pack details. Both are idempotent (guarded by order meta) and retry
 * with backoff on failure.
 *
 * @package TBB\Zoho
 */

namespace TBB\Zoho;

/**
 * Zoho Inventory order sync module.
 *
 * @package TBB\Zoho
 */
class OrderSync {

	const MAX_ATTEMPTS = 5;

	/**
	 * Set when a sync ran in dry run. Deliberately not the "synced" flag: the
	 * order is not in Zoho, and nothing about it should say otherwise.
	 *
	 * @var string
	 */
	const SIMULATED_META = '_tbb_zoho_simulated_at';

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
		add_action( 'woocommerce_payment_complete', [ $this, 'schedule_sync' ] );
		add_action( 'tbb_zoho_sync_order', [ $this, 'sync_order' ] );
		add_action( 'tbb_zoho_notify_warehouse', [ $this, 'notify_warehouse' ] );
	}

	/**
	 * Schedule the Zoho sync and warehouse notification as independent,
	 * async background jobs. Skips anything already done or already queued.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function schedule_sync( $order_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( 'yes' !== $order->get_meta( '_tbb_zoho_synced' )
			&& ! as_next_scheduled_action( 'tbb_zoho_sync_order', [ 'order_id' => $order_id ], 'zoho' )
		) {
			as_enqueue_async_action( 'tbb_zoho_sync_order', [ 'order_id' => $order_id ], 'zoho' );
		}

		if ( 'yes' !== $order->get_meta( '_tbb_warehouse_notified' )
			&& ! as_next_scheduled_action( 'tbb_zoho_notify_warehouse', [ 'order_id' => $order_id ], 'zoho' )
		) {
			as_enqueue_async_action( 'tbb_zoho_notify_warehouse', [ 'order_id' => $order_id ], 'zoho' );
		}
	}

	/**
	 * Create the Sales Order in Zoho Inventory for a WooCommerce order.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function sync_order( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order || 'yes' === $order->get_meta( '_tbb_zoho_synced' ) ) {
			return;
		}

		$simulating = Client::is_dry_run();

		// A second simulated run would only repeat the note.
		if ( $simulating && '' !== (string) $order->get_meta( self::SIMULATED_META ) ) {
			return;
		}

		$client = new Client();

		// A previous attempt may have created the sales order and then failed
		// to confirm it, which schedules a retry through this same method.
		// Starting from the top would create a second sales order against the
		// same WooCommerce order, and each confirmed duplicate reserves the
		// stock again. Resume at the step that failed instead.
		$existing = (string) $order->get_meta( '_tbb_zoho_sales_order_id' );

		if ( '' !== $existing ) {
			// A real sales order is already half-built. Dry run must not touch it.
			if ( $simulating ) {
				return;
			}

			$this->confirm_and_finish( $order, $client, $existing );
			return;
		}

		$name       = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$contact_id = $client->get_or_create_contact_id( $order->get_billing_email(), $name );

		if ( is_wp_error( $contact_id ) ) {
			$this->handle_failure( $order, 'tbb_zoho_sync_order', 'contact lookup', $contact_id );
			return;
		}

		$payload = $this->build_sales_order_payload( $order, $contact_id, $client );

		if ( is_wp_error( $payload ) ) {
			// A Zoho outage while looking items up is worth retrying. An item
			// Zoho has never heard of is not: retrying cannot make it exist.
			if ( 'zoho_unmatched_sku' !== $payload->get_error_code() ) {
				$this->handle_failure( $order, 'tbb_zoho_sync_order', 'item lookup', $payload );
				return;
			}

			wc_get_logger()->critical(
				sprintf( 'Order #%d not sent to Zoho — %s', $order_id, $payload->get_error_message() ),
				[ 'source' => 'zoho' ]
			);

			// It still has to be visible on the order itself: the warehouse is
			// emailed separately, so without a note the goods ship while Zoho
			// knows nothing about the sale, and the only trace is a log line.
			/* translators: %s: detail of which items could not be matched. */
			$order->add_order_note( sprintf( __( 'Not sent to Zoho — %s Needs manual follow-up.', 'blank-brand-theme' ), $payload->get_error_message() ) );
			return;
		}

		$result = $client->create_sales_order( $payload );

		if ( is_wp_error( $result ) ) {
			$this->handle_failure( $order, 'tbb_zoho_sync_order', 'sales order creation', $result );
			return;
		}

		$sales_order_id = (string) ( $result['salesorder']['salesorder_id'] ?? '' );

		// Zoho answering without an id is not a success. Treating it as one
		// would mark the order synced, leave nothing to confirm, and never
		// retry — the sale would simply never reach Zoho.
		if ( '' === $sales_order_id ) {
			$this->handle_failure(
				$order,
				'tbb_zoho_sync_order',
				'sales order creation',
				new \WP_Error( 'zoho_no_salesorder_id', __( 'Zoho did not return a sales order id.', 'blank-brand-theme' ) )
			);
			return;
		}

		// Persisted before confirming, so that a timeout or a crash between the
		// two steps leaves a recoverable state rather than an invisible draft.
		// A simulated id is never stored: it names nothing in Zoho, and a later
		// live retry would resume against it and fail for good.
		if ( ! $simulating ) {
			$order->update_meta_data( '_tbb_zoho_sales_order_id', $sales_order_id );
			$order->save();
		}

		$this->confirm_and_finish( $order, $client, $sales_order_id );
	}

	/**
	 * Confirm an existing Zoho sales order and mark the WooCommerce order done.
	 *
	 * Split out because it is reached two ways: straight after creating the
	 * sales order, and again on a retry where the sales order already exists
	 * and only the confirmation failed.
	 *
	 * A draft sales order reserves no stock, so the website would keep selling
	 * units this order has already claimed. Confirming is what makes the
	 * reservation real, and a failure here is worth surfacing loudly.
	 *
	 * @param \WC_Order $order          The order.
	 * @param Client    $client         API client.
	 * @param string    $sales_order_id Zoho sales order id.
	 * @return void
	 */
	private function confirm_and_finish( \WC_Order $order, Client $client, string $sales_order_id ): void {
		$confirmed = $client->confirm_sales_order( $sales_order_id );

		if ( is_wp_error( $confirmed ) ) {
			$this->handle_failure( $order, 'tbb_zoho_sync_order', 'sales order confirmation', $confirmed );
			return;
		}

		// Dry run answers every call with a made-up id, so reaching this point
		// proves nothing reached Zoho. Recording it as a sync would put a
		// "Synced to Zoho" note on an order that is not in Zoho, and staff are
		// told to trust that note.
		if ( Client::is_dry_run() ) {
			$order->update_meta_data( self::SIMULATED_META, time() );
			$order->save();
			$order->add_order_note( __( 'SIMULATED: dry run is on, so nothing was sent to Zoho and this order is not in Zoho. Once dry run is off, run the sync for this order to send it.', 'blank-brand-theme' ) );
			return;
		}

		$order->update_meta_data( '_tbb_zoho_synced', 'yes' );
		$order->update_meta_data( '_tbb_zoho_sales_order_id', $sales_order_id );
		$order->delete_meta_data( self::SIMULATED_META );
		$order->save();

		/* translators: %s: Zoho sales order id. */
		$order->add_order_note( sprintf( __( 'Synced to Zoho Inventory as Sales Order #%s.', 'blank-brand-theme' ), $sales_order_id ) );
	}

	/**
	 * Orders whose sync only ever ran in dry run, so they are not in Zoho.
	 *
	 * @return \WC_Order[]
	 */
	public static function simulated_orders(): array {
		$orders = wc_get_orders(
			[
				'limit'      => 50,
				'status'     => array_keys( wc_get_order_statuses() ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded by the limit above; the marker is how simulated orders are found.
				'meta_query' => [
					[
						'key'     => self::SIMULATED_META,
						'compare' => 'EXISTS',
					],
				],
			]
		);

		return array_values( array_filter( $orders, fn( $o ) => $o instanceof \WC_Order && 'yes' !== $o->get_meta( '_tbb_zoho_synced' ) ) );
	}

	/**
	 * Queue the real sync for every order that was only simulated.
	 *
	 * For the day dry run goes off: orders placed while it was on are not in
	 * Zoho and nothing else will send them. Refuses while dry run is still on,
	 * since the jobs would only simulate again.
	 *
	 * @return int How many orders were queued.
	 */
	public static function requeue_simulated(): int {
		if ( Client::is_dry_run() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return 0;
		}

		$queued = 0;

		foreach ( self::simulated_orders() as $order ) {
			if ( ! as_next_scheduled_action( 'tbb_zoho_sync_order', [ 'order_id' => $order->get_id() ], 'zoho' ) ) {
				as_enqueue_async_action( 'tbb_zoho_sync_order', [ 'order_id' => $order->get_id() ], 'zoho' );
				++$queued;
			}
		}

		return $queued;
	}

	/**
	 * Email the warehouse the pick/pack details for an order.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function notify_warehouse( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order || 'yes' === $order->get_meta( '_tbb_warehouse_notified' ) ) {
			return;
		}

		$to = Settings::get( 'warehouse_email' );

		if ( '' === $to ) {
			wc_get_logger()->warning(
				sprintf( 'No warehouse email configured — skipping notification for order #%d.', $order_id ),
				[ 'source' => 'zoho' ]
			);
			return;
		}

		/* translators: %s: order number. */
		$subject = sprintf( __( 'New order #%s — pick & pack', 'blank-brand-theme' ), $order->get_order_number() );

		// Sent through WooCommerce's own mailer and wrapped in its header and
		// footer, so it carries the same logo, colours and sender as every other
		// email the shop sends instead of being a bare table.
		$mailer  = WC()->mailer();
		$message = $mailer->wrap_message( __( 'Pick and pack', 'blank-brand-theme' ), $this->build_warehouse_email_body( $order ) );
		$sent    = $mailer->send( $to, $subject, $message );

		if ( ! $sent ) {
			$this->handle_failure( $order, 'tbb_zoho_notify_warehouse', 'warehouse email', new \WP_Error( 'wp_mail_failed', 'wp_mail() returned false.' ) );
			return;
		}

		$order->update_meta_data( '_tbb_warehouse_notified', 'yes' );
		$order->save();
	}

	/**
	 * Build the Zoho Sales Order payload for a WooCommerce order.
	 *
	 * Zoho identifies a line by `item_id`, not by SKU. The first version sent
	 * `sku` and Zoho answered 400 "Select an item or give a description" — which
	 * dry run could never show, because dry run sends nothing. Each SKU is
	 * therefore resolved to its Zoho item here.
	 *
	 * A line that cannot be resolved fails the whole order rather than being
	 * dropped. A sales order missing a line reserves less stock than was sold,
	 * while the warehouse email still lists everything, and the two would
	 * disagree with nothing to say so.
	 *
	 * @param \WC_Order $order      The order.
	 * @param int       $contact_id Zoho contact id for the customer.
	 * @param Client    $client     API client, used to look items up.
	 * @return array|\WP_Error Payload, or an error. The code `zoho_unmatched_sku`
	 *                         means retrying cannot help; any other is an API fault.
	 */
	private function build_sales_order_payload( \WC_Order $order, int $contact_id, Client $client ) {
		$line_items = [];
		$unmatched  = [];

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			$sku     = $product ? $product->get_sku() : '';

			if ( '' === $sku ) {
				$unmatched[] = sprintf( '"%s" has no SKU', $item->get_name() );
				continue;
			}

			$item_id = $this->zoho_item_id( $client, $sku );

			if ( is_wp_error( $item_id ) ) {
				return $item_id;
			}

			if ( '' === $item_id ) {
				$unmatched[] = sprintf( '%s is not in Zoho', $sku );
				continue;
			}

			$line_items[] = [
				'item_id'  => $item_id,
				'quantity' => $item->get_quantity(),
				// Zoho documents rate as a number. A string may be tolerated, but
				// the first live order showed what assuming that costs.
				'rate'     => (float) wc_format_decimal( $order->get_item_total( $item, false, false ), 2 ),
			];
		}

		if ( $unmatched || ! $line_items ) {
			return new \WP_Error(
				'zoho_unmatched_sku',
				$unmatched ? implode( '; ', $unmatched ) . '.' : __( 'no line items could be matched to Zoho.', 'blank-brand-theme' )
			);
		}

		return [
			'customer_id'      => (string) $contact_id,
			'date'             => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
			'reference_number' => $order->get_order_number(),
			'line_items'       => $line_items,
			'shipping_charge'  => (float) $order->get_shipping_total(),
			/* translators: %s: order number. */
			'notes'            => sprintf( __( 'WooCommerce order #%s', 'blank-brand-theme' ), $order->get_order_number() ),
		];
	}

	/**
	 * Resolve a SKU to its Zoho item id.
	 *
	 * Matches the SKU exactly in the response: Zoho's `sku` filter is not
	 * guaranteed to return only an exact match, and the wrong item on an order
	 * line would reserve the wrong stock.
	 *
	 * @param Client $client API client.
	 * @param string $sku    SKU to look up.
	 * @return string|\WP_Error Item id, an empty string when Zoho has no such item, or an API error.
	 */
	private function zoho_item_id( Client $client, string $sku ) {
		$response = $client->get_item_by_sku( $sku );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		foreach ( $response['items'] ?? [] as $candidate ) {
			if ( ( $candidate['sku'] ?? '' ) === $sku && ! empty( $candidate['item_id'] ) ) {
				return (string) $candidate['item_id'];
			}
		}

		return '';
	}

	/**
	 * Build the body of the warehouse notification email.
	 *
	 * Laid out like WooCommerce's own order emails and using the same CSS
	 * classes, so the stylesheet WooCommerce inlines at send time gives it the
	 * shop's colours. What differs is the content: a picker needs the SKU, a
	 * thumbnail and a quantity, a delivery address with a phone number a courier
	 * can ring, and any note from the customer. Prices are deliberately absent.
	 *
	 * @param \WC_Order $order The order.
	 * @return string
	 */
	private function build_warehouse_email_body( \WC_Order $order ): string {
		$address = $order->get_formatted_shipping_address() ? $order->get_formatted_shipping_address() : $order->get_formatted_billing_address();
		$phone   = $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone();
		$method  = $order->get_shipping_method();

		ob_start();
		?>
		<div class="email-introduction">
			<p><?php esc_html_e( 'A new paid order is ready to pack.', 'blank-brand-theme' ); ?></p>
		</div>

		<h2 class="email-order-detail-heading">
			<?php esc_html_e( 'Items to pack', 'blank-brand-theme' ); ?><br>
			<span>
				<?php
				printf(
					/* translators: 1: order number, 2: order date. */
					esc_html__( 'Order #%1$s (%2$s)', 'blank-brand-theme' ),
					esc_html( $order->get_order_number() ),
					esc_html( wc_format_datetime( $order->get_date_created() ) )
				);
				?>
			</span>
		</h2>

		<div style="margin-bottom: 24px;">
			<table class="td font-family email-order-details" cellspacing="0" cellpadding="6" style="width: 100%;" border="1">
				<thead>
					<tr>
						<th class="td text-align-left" scope="col"><?php esc_html_e( 'Product', 'blank-brand-theme' ); ?></th>
						<th class="td text-align-left" scope="col"><?php esc_html_e( 'SKU', 'blank-brand-theme' ); ?></th>
						<th class="td text-align-right" scope="col"><?php esc_html_e( 'Quantity', 'blank-brand-theme' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $order->get_items() as $item ) : ?>
						<?php
						$product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
						$image   = $product ? wp_get_attachment_image_url( (int) $product->get_image_id(), 'thumbnail' ) : '';
						?>
						<tr class="order_item">
							<td class="td font-family text-align-left" style="vertical-align: middle; word-wrap:break-word;">
								<table class="order-item-data" role="presentation">
									<tr>
										<?php if ( $image ) : ?>
											<td style="vertical-align: top;"><img width="48" height="48" src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $item->get_name() ); ?>" /></td>
										<?php endif; ?>
										<td><h3 style="font-size: inherit;font-weight: inherit"><?php echo esc_html( $item->get_name() ); ?></h3></td>
									</tr>
								</table>
							</td>
							<td class="td font-family text-align-left" style="vertical-align: middle;"><?php echo esc_html( $product ? $product->get_sku() : '' ); ?></td>
							<td class="td font-family text-align-right" style="vertical-align: middle;"><?php echo esc_html( (string) $item->get_quantity() ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<table id="addresses" cellspacing="0" cellpadding="0" border="0" role="presentation" style="width: 100%; vertical-align: top; margin-bottom: 0; padding: 0;" width="100%">
			<tr>
				<td class="font-family text-align-left" valign="top" width="50%" style="text-align: left; border: 0; padding: 0;" align="left">
					<b class="address-title"><?php esc_html_e( 'Ship to', 'blank-brand-theme' ); ?></b>
					<address class="address" style="font-style: normal; line-height: 120%; padding: 8px 0;">
						<?php echo wp_kses_post( $address ); ?>
						<?php if ( $phone ) : ?>
							<br><?php echo esc_html( $phone ); ?>
						<?php endif; ?>
					</address>
					<?php if ( $method ) : ?>
						<p style="margin: 8px 0 0;"><?php echo esc_html( sprintf( /* translators: %s: shipping method. */ __( 'Shipping: %s', 'blank-brand-theme' ), $method ) ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<?php if ( $order->get_customer_note() ) : ?>
			<h2 class="email-order-detail-heading"><?php esc_html_e( 'Customer note', 'blank-brand-theme' ); ?></h2>
			<p><?php echo esc_html( $order->get_customer_note() ); ?></p>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Log a failure and, up to `MAX_ATTEMPTS`, schedule a delayed retry.
	 *
	 * @param \WC_Order $order  The order.
	 * @param string    $action Action hook to reschedule.
	 * @param string    $stage  Human-readable stage, for logging.
	 * @param \WP_Error $error  The error.
	 * @return void
	 */
	private function handle_failure( \WC_Order $order, string $action, string $stage, \WP_Error $error ): void {
		$meta_key = 'tbb_zoho_sync_order' === $action ? '_tbb_zoho_sync_attempts' : '_tbb_warehouse_email_attempts';
		$attempts = (int) $order->get_meta( $meta_key ) + 1;

		$order->update_meta_data( $meta_key, $attempts );
		$order->save();

		wc_get_logger()->error(
			sprintf( 'Zoho %s failed for order #%d (attempt %d): %s', $stage, $order->get_id(), $attempts, $error->get_error_message() ),
			[ 'source' => 'zoho' ]
		);

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			/* translators: %s: failed stage, e.g. "sales order creation". */
			$order->add_order_note( sprintf( __( 'Zoho %s failed after multiple attempts — needs manual follow-up.', 'blank-brand-theme' ), $stage ) );
			return;
		}

		as_schedule_single_action( time() + ( $attempts * 5 * MINUTE_IN_SECONDS ), $action, [ 'order_id' => $order->get_id() ], 'zoho' );
	}
}
