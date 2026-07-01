<?php
/**
 * Bulk Discounts — front end.
 *
 * Renders the `_tbb_bulk_discounts` product meta (edited via
 * {@see BulkDiscountsAdmin}) as an accordion under the product description
 * and price on the single product page. Dormant when the field is empty.
 *
 * @package BlankBrandTheme
 */

namespace BlankBrandTheme;

use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * Bulk Discounts front-end module.
 *
 * @package BlankBrandTheme
 */
class BulkDiscounts implements ModuleInterface {

	use Module;

	const META = '_tbb_bulk_discounts';

	/**
	 * Can this module be registered?
	 *
	 * @return bool
	 */
	public function can_register() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		// Priority 26 — after the price (25) and before the colour swatches
		// (27), so the order reads: price, bulk-discounts accordion, colour.
		add_action( 'woocommerce_single_product_summary', [ $this, 'render' ], 26 );
	}

	/**
	 * Output the accordion, expanded by default.
	 *
	 * @return void
	 */
	public function render() {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$content = get_post_meta( $product->get_id(), self::META, true );

		if ( ! $content ) {
			return;
		}
		?>
		<details class="tbb-bulk-discounts" open>
			<summary class="tbb-bulk-discounts__toggle">
				<?php esc_html_e( 'See Bulk Discounts', 'blank-brand-theme' ); ?>
				<svg class="tbb-bulk-discounts__icon" width="12" height="7" viewBox="0 0 12 7" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
					<path d="M1 1L6 6L11 1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
			</summary>
			<div class="tbb-bulk-discounts__content">
				<?php echo apply_filters( 'the_content', $content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</details>
		<?php
	}
}
