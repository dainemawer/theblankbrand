<?php
/**
 * Zoho Inventory — sync status report.
 *
 * Surfaces the last reconciliation run under WooCommerce → Status → Zoho Sync.
 *
 * The report exists for one failure mode in particular: a WooCommerce SKU with
 * no counterpart in Zoho can never receive stock or price, and nothing about
 * the product says so. Three colourways were affected that way and 724 units
 * sat invisible until a client noticed. The data was in the run report all
 * along — it just had nowhere to be seen.
 *
 * Read-only: everything here comes from the option {@see StockSync} writes.
 *
 * @package TBB\Zoho
 */

namespace TBB\Zoho;

/**
 * Zoho sync status report module.
 *
 * @package TBB\Zoho
 */
class StatusReport {

	/**
	 * Can this module be registered?
	 *
	 * @return bool
	 */
	public function can_register(): bool {
		return is_admin() && class_exists( 'WooCommerce' );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_system_status_report', [ $this, 'render' ] );
	}

	/**
	 * Render the report table.
	 *
	 * @return void
	 */
	public function render(): void {
		$report  = get_option( StockSync::REPORT_OPTION, [] );
		$failure = get_option( StockSync::FAILURE_OPTION, [] );

		// Before failures were kept apart, one was stored in place of the report
		// and held nothing but its error.
		if ( ! empty( $report['error'] ) && ! isset( $report['checked'] ) ) {
			$failure = [
				'at'      => (int) ( $report['finished'] ?? 0 ),
				'message' => (string) $report['error'],
			];
			$report  = [];
		}
		?>
		<table class="wc_status_table widefat" cellspacing="0">
			<thead>
				<tr><th colspan="3" data-export-label="Zoho Sync"><h2><?php esc_html_e( 'Zoho Sync', 'blank-brand-theme' ); ?></h2></th></tr>
			</thead>
			<tbody>
			<?php

			if ( ! Settings::is_enabled() ) {
				$this->row( __( 'Status', 'blank-brand-theme' ), __( 'Disabled or not fully configured.', 'blank-brand-theme' ), 'error' );
				echo '</tbody></table>';
				return;
			}

			$this->mode_rows();

			$finished = (int) ( $report['finished'] ?? 0 );

			// A failure newer than the last good run is what the screen has to
			// lead with. The last good run's figures stay below it, labelled as
			// that run's, so they are never mistaken for the failed one's.
			$failed = $failure && (int) ( $failure['at'] ?? 0 ) >= $finished;

			if ( $failed ) {
				$this->row(
					__( 'Last attempt', 'blank-brand-theme' ),
					sprintf(
						/* translators: 1: human time diff, 2: error message. */
						esc_html__( 'Failed %1$s ago: %2$s', 'blank-brand-theme' ),
						esc_html( human_time_diff( (int) $failure['at'] ) ),
						esc_html( (string) ( $failure['message'] ?? '' ) )
					),
					'error'
				);
			}

			if ( ! $report ) {
				if ( ! $failed ) {
					$this->row( __( 'Status', 'blank-brand-theme' ), __( 'Enabled, but has not run yet.', 'blank-brand-theme' ), 'warning' );
				}
				echo '</tbody></table>';
				return;
			}

			$age      = $finished ? ( time() - $finished ) : null;
			$interval = (int) apply_filters( 'tbb_zoho_sync_interval', StockSync::INTERVAL );

			// Stale means "older than three scheduled runs" — enough to ride out
			// a slow run or a missed tick without crying wolf.
			$this->row(
				$failed ? __( 'Last successful run', 'blank-brand-theme' ) : __( 'Last run', 'blank-brand-theme' ),
				$finished
					/* translators: 1: human time diff, 2: duration in seconds. */
					? sprintf( __( '%1$s ago, took %2$ds', 'blank-brand-theme' ), human_time_diff( $finished ), max( 0, $finished - (int) ( $report['started'] ?? $finished ) ) )
					: __( 'unknown', 'blank-brand-theme' ),
				( $failed || ( null !== $age && $age > $interval * 3 ) ) ? 'warning' : 'yes'
			);

			$this->row( __( 'Next run', 'blank-brand-theme' ), $this->next_run(), 'yes' );

			$checked   = (int) ( $report['checked'] ?? 0 );
			$matched   = (int) ( $report['matched'] ?? 0 );
			$unmatched = (array) ( $report['unmatched'] ?? [] );

			$this->row(
				__( 'SKUs', 'blank-brand-theme' ),
				sprintf(
					/* translators: 1: checked, 2: matched, 3: Zoho item count. */
					__( '%1$d checked, %2$d matched against %3$d Zoho items', 'blank-brand-theme' ),
					$checked,
					$matched,
					(int) ( $report['zoho_items'] ?? 0 )
				),
				'yes'
			);

			$this->row(
				__( 'Applied', 'blank-brand-theme' ),
				sprintf(
					/* translators: 1: stock, 2: status, 3: price, 4: bulk rate, 5: newly managed counts. */
					__( '%1$d stock, %2$d status, %3$d price, %4$d bulk rate, %5$d newly managed', 'blank-brand-theme' ),
					(int) ( $report['stock_updated'] ?? 0 ),
					(int) ( $report['status_updated'] ?? 0 ),
					(int) ( $report['price_updated'] ?? 0 ),
					(int) ( $report['bulk_updated'] ?? 0 ),
					(int) ( $report['stock_managed'] ?? 0 )
				),
				'yes'
			);

			if ( ! empty( $report['bulk_divergent'] ) ) {
				$this->row(
					__( 'Divergent bulk rates', 'blank-brand-theme' ),
					sprintf(
						/* translators: %d: number of products. */
						_n(
							'%d product has variations priced differently in Zoho; the lowest is advertised.',
							'%d products have variations priced differently in Zoho; the lowest is advertised.',
							(int) $report['bulk_divergent'],
							'blank-brand-theme'
						),
						(int) $report['bulk_divergent']
					),
					'warning'
				);
			}

			$this->row(
				__( 'Unmatched SKUs', 'blank-brand-theme' ),
				$unmatched ? $this->unmatched_list( $unmatched ) : __( 'None — every product is linked to Zoho.', 'blank-brand-theme' ),
				$unmatched ? 'error' : 'yes'
			);
			?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Whether orders are reaching Zoho, and any that were only simulated.
	 *
	 * Dry run is the one setting that decides whether the whole integration is
	 * live, and it was nowhere on this screen.
	 *
	 * @return void
	 */
	protected function mode_rows(): void {
		if ( Client::is_dry_run() ) {
			$this->row( __( 'Writes to Zoho', 'blank-brand-theme' ), __( 'Simulated. Dry run is on, so orders are not sent to Zoho.', 'blank-brand-theme' ), 'warning' );
		} else {
			$this->row( __( 'Writes to Zoho', 'blank-brand-theme' ), __( 'Live. Paid orders are sent to Zoho.', 'blank-brand-theme' ), 'yes' );
		}

		$simulated = OrderSync::simulated_orders();

		if ( ! $simulated ) {
			return;
		}

		$links = [];

		foreach ( array_slice( $simulated, 0, 10 ) as $order ) {
			$links[] = sprintf( '<a href="%s">#%s</a>', esc_url( $order->get_edit_order_url() ), esc_html( $order->get_order_number() ) );
		}

		$this->row(
			__( 'Not in Zoho', 'blank-brand-theme' ),
			sprintf(
				/* translators: 1: number of orders, 2: list of order links. */
				esc_html( _n( '%1$d order was only simulated and never reached Zoho: %2$s', '%1$d orders were only simulated and never reached Zoho: %2$s', count( $simulated ), 'blank-brand-theme' ) ),
				count( $simulated ),
				implode( ', ', $links )
			),
			Client::is_dry_run() ? 'warning' : 'error'
		);
	}

	/**
	 * Build the unmatched list, linking each SKU to the product that owns it.
	 *
	 * @param string[] $skus Unmatched SKUs.
	 * @return string
	 */
	protected function unmatched_list( array $skus ): string {
		$out = '<p>' . esc_html__( 'These exist on the website but not in Zoho, so they can never receive stock or price:', 'blank-brand-theme' ) . '</p><ul style="margin:0">';

		foreach ( $skus as $sku ) {
			$id      = wc_get_product_id_by_sku( $sku );
			$product = $id ? wc_get_product( $id ) : false;
			$parent  = 0;

			// The product can disappear between the sync and this screen.
			if ( $product ) {
				$parent = $product->get_parent_id() ? $product->get_parent_id() : $id;
			}

			$out .= sprintf(
				'<li><code>%s</code>%s</li>',
				esc_html( $sku ),
				$parent ? sprintf(
					' — <a href="%s">%s</a>',
					esc_url( get_edit_post_link( $parent ) ),
					esc_html( get_the_title( $parent ) )
				) : ''
			);
		}

		return $out . '</ul>';
	}

	/**
	 * When the reconciliation job is next due.
	 *
	 * @return string
	 */
	protected function next_run(): string {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return __( 'Action Scheduler unavailable', 'blank-brand-theme' );
		}

		$next = as_next_scheduled_action( StockSync::ACTION, [], 'zoho' );

		if ( is_int( $next ) ) {
			/* translators: %s: human time diff. */
			return sprintf( __( 'in %s', 'blank-brand-theme' ), human_time_diff( $next ) );
		}

		return $next ? __( 'queued', 'blank-brand-theme' ) : __( 'not scheduled', 'blank-brand-theme' );
	}

	/**
	 * Output one status row.
	 *
	 * @param string $label Row label.
	 * @param string $value Row value; may contain markup.
	 * @param string $state One of yes|warning|error.
	 * @return void
	 */
	protected function row( string $label, string $value, string $state = 'yes' ): void {
		$mark = [
			'yes'     => '<mark class="yes">&#10004;</mark> ',
			'warning' => '<mark class="error">&#10005;</mark> ',
			'error'   => '<mark class="error">&#10005;</mark> ',
		];

		printf(
			'<tr><td>%s</td><td class="help">&nbsp;</td><td>%s%s</td></tr>',
			esc_html( $label ),
			wp_kses_post( $mark[ $state ] ?? '' ),
			wp_kses_post( $value )
		);
	}
}
