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
 * @package BlankBrandTheme
 */

namespace BlankBrandTheme\Zoho;

use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * Zoho Inventory order sync module.
 *
 * @package BlankBrandTheme
 */
class OrderSync implements ModuleInterface {

	use Module;

	const MAX_ATTEMPTS = 5;

	/**
	 * Can this module be registered?
	 *
	 * @return bool
	 */
	public function can_register(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'as_enqueue_async_action' ) && Settings::is_enabled();
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

		$client = new Client();

		$name       = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$contact_id = $client->get_or_create_contact_id( $order->get_billing_email(), $name );

		if ( is_wp_error( $contact_id ) ) {
			$this->handle_failure( $order, 'tbb_zoho_sync_order', 'contact lookup', $contact_id );
			return;
		}

		$payload = $this->build_sales_order_payload( $order, $contact_id );

		if ( empty( $payload['line_items'] ) ) {
			wc_get_logger()->critical(
				sprintf( 'Order #%d has no line items with a matching SKU — skipping Zoho sync.', $order_id ),
				[ 'source' => 'zoho' ]
			);
			return;
		}

		$result = $client->create_sales_order( $payload );

		if ( is_wp_error( $result ) ) {
			$this->handle_failure( $order, 'tbb_zoho_sync_order', 'sales order creation', $result );
			return;
		}

		$sales_order_id = $result['salesorder']['salesorder_id'] ?? '';

		$order->update_meta_data( '_tbb_zoho_synced', 'yes' );
		$order->update_meta_data( '_tbb_zoho_sales_order_id', $sales_order_id );
		$order->save();

		/* translators: %s: Zoho sales order id. */
		$order->add_order_note( sprintf( __( 'Synced to Zoho Inventory as Sales Order #%s.', 'blank-brand-theme' ), $sales_order_id ) );
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
		$body    = $this->build_warehouse_email_body( $order );
		$sent    = wp_mail( $to, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );

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
	 * @param \WC_Order $order      The order.
	 * @param int       $contact_id Zoho contact id for the customer.
	 * @return array
	 */
	private function build_sales_order_payload( \WC_Order $order, int $contact_id ): array {
		$line_items = [];

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			$sku     = $product ? $product->get_sku() : '';

			if ( '' === $sku ) {
				wc_get_logger()->warning(
					sprintf( 'Order #%d line item "%s" has no SKU — omitted from the Zoho sales order.', $order->get_id(), $item->get_name() ),
					[ 'source' => 'zoho' ]
				);
				continue;
			}

			$line_items[] = [
				'sku'      => $sku,
				'quantity' => $item->get_quantity(),
				'rate'     => wc_format_decimal( $order->get_item_total( $item, false, false ), 2 ),
			];
		}

		return [
			'customer_id'      => $contact_id,
			'date'             => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
			'reference_number' => $order->get_order_number(),
			'line_items'       => $line_items,
			'shipping_charge'  => (float) $order->get_shipping_total(),
			/* translators: %s: order number. */
			'notes'            => sprintf( __( 'WooCommerce order #%s', 'blank-brand-theme' ), $order->get_order_number() ),
		];
	}

	/**
	 * Build the HTML body for the warehouse notification email.
	 *
	 * @param \WC_Order $order The order.
	 * @return string
	 */
	private function build_warehouse_email_body( \WC_Order $order ): string {
		ob_start();
		?>
		<h2><?php echo esc_html( sprintf( /* translators: %s: order number. */ __( 'Order #%s', 'blank-brand-theme' ), $order->get_order_number() ) ); ?></h2>
		<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'SKU', 'blank-brand-theme' ); ?></th>
					<th><?php esc_html_e( 'Item', 'blank-brand-theme' ); ?></th>
					<th><?php esc_html_e( 'Qty', 'blank-brand-theme' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $order->get_items() as $item ) : ?>
					<?php
					$product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
					$sku     = $product ? $product->get_sku() : '';
					?>
					<tr>
						<td><?php echo esc_html( $sku ); ?></td>
						<td><?php echo esc_html( $item->get_name() ); ?></td>
						<td><?php echo esc_html( (string) $item->get_quantity() ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<h3><?php esc_html_e( 'Ship to', 'blank-brand-theme' ); ?></h3>
		<p><?php echo wp_kses_post( $order->get_formatted_shipping_address() ? $order->get_formatted_shipping_address() : $order->get_formatted_billing_address() ); ?></p>
		<?php if ( $order->get_customer_note() ) : ?>
			<h3><?php esc_html_e( 'Customer note', 'blank-brand-theme' ); ?></h3>
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
