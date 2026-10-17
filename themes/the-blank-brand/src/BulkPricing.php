<?php
/**
 * Bulk Pricing — cart enforcement.
 *
 * Applies the quantity tiers in `_tbb_price_tiers` to the cart, so the price a
 * customer is charged matches the one {@see BulkDiscounts} advertises on the
 * product page. Until this existed the tiers were display-only: a customer
 * ordering 100 units saw the 50+ price and was charged the 1-49 price.
 *
 * Quantity is counted PER PRODUCT, not per variation — these are blanks, and a
 * bulk order is 50 of a style across whatever mix of sizes and colours the
 * customer needs. Every line of that product then takes the tier price.
 *
 * The first tier's price is never read from the stored tiers: it is the
 * product's own price, exactly as the product page renders it. Only tiers
 * beyond the first override anything.
 *
 * @package BlankBrandTheme
 */

namespace BlankBrandTheme;

use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * Bulk pricing cart module.
 *
 * @package BlankBrandTheme
 */
class BulkPricing implements ModuleInterface {

	use Module;

	const META = BulkDiscounts::META;

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
		// Priority 20 — after anything that sets a base or sale price, since
		// the tier is compared against whatever the product would otherwise
		// cost and never raises it.
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply_tier_pricing' ], 20 );

		// WooCommerce reloads the cart from the session on an ordinary page load
		// and, when the saved totals are still good, does not recalculate them.
		// The products it hands back then hold their normal price, so the mini
		// cart read "50 × R150" next to a subtotal worked out at the tier price.
		// Apply the tier here as well. It is safe to run twice.
		add_action( 'woocommerce_cart_loaded_from_session', [ $this, 'apply_tier_pricing' ], 20 );

		// 100 or more of one style is a quote, not an online order. Started from
		// here rather than as a module of its own because the framework's cached
		// module list on production would not pick up a new module.
		( new QuoteLimit() )->register();
	}

	/**
	 * Apply tier pricing to every qualifying line in the cart.
	 *
	 * WooCommerce may run this several times in one request, so it must be
	 * idempotent: each line's tier is resolved against the product's own
	 * stored price rather than against whatever price the cart item currently
	 * holds. Reading the running total would compound the discount on the
	 * second pass.
	 *
	 * @param \WC_Cart $cart Cart being calculated.
	 * @return void
	 */
	public function apply_tier_pricing( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		$quantities = $this->quantities_by_product( $cart );

		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$group = (int) ( $item['product_id'] ?? 0 );
			$tiers = $this->tiers( $group );

			if ( ! $tiers ) {
				continue;
			}

			$price = $this->price_for( $product, $tiers, $quantities[ $group ] ?? 0 );

			if ( null !== $price ) {
				$product->set_price( (string) $price );
			}
		}
	}

	/**
	 * Total quantity in the cart per parent product.
	 *
	 * `product_id` is the parent for a variation and the product itself for a
	 * simple product, which is exactly the grouping a "50 of this style"
	 * threshold needs.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return array<int,int> product id => quantity.
	 */
	protected function quantities_by_product( \WC_Cart $cart ): array {
		$totals = [];

		foreach ( $cart->get_cart() as $item ) {
			$group = (int) ( $item['product_id'] ?? 0 );

			if ( ! $group ) {
				continue;
			}

			$totals[ $group ] = ( $totals[ $group ] ?? 0 ) + (int) ( $item['quantity'] ?? 0 );
		}

		return $totals;
	}

	/**
	 * The stored tiers for a product, or an empty array when it has none.
	 *
	 * @param int $product_id Parent product id.
	 * @return array
	 */
	protected function tiers( int $product_id ): array {
		$data = get_post_meta( $product_id, self::META, true );

		if ( ! is_array( $data ) || empty( $data['tiers'] ) || ! is_array( $data['tiers'] ) ) {
			return [];
		}

		return array_values( $data['tiers'] );
	}

	/**
	 * Resolve the price one cart line should be charged.
	 *
	 * @param \WC_Product $product  The cart line's product.
	 * @param array       $tiers    Stored tiers.
	 * @param int         $quantity Total quantity of the parent product in the cart.
	 * @return float|null Price to set, or null to leave the line alone.
	 */
	protected function price_for( \WC_Product $product, array $tiers, int $quantity ): ?float {
		$base = $this->base_price( $product );

		if ( null === $base ) {
			return null;
		}

		$match = $this->tier_for( $tiers, $quantity );

		// No band matches, or the first band does: the product's own price
		// already is the first tier, so put it back rather than leaving
		// whatever a previous pass may have set.
		if ( null === $match || 0 === $match['index'] ) {
			return $base;
		}

		// A tier that is zero, missing, or worse than the customer's current
		// price is not applied — a live sale must never be undone by a bulk
		// tier, and a bad figure must never raise what someone is charged.
		return self::effective_tier_price( (float) ( $match['tier']['price'] ?? 0 ), $base ) ?? $base;
	}

	/**
	 * The price a quantity tier really gives, or null when it gives none.
	 *
	 * One rule for what the cart charges and what the product page advertises.
	 * They used to apply it separately, and a product on sale showed a 50+ price
	 * above the sale price that the cart would never charge.
	 *
	 * @param float $tier Stored tier price.
	 * @param float $base The product's own current price.
	 * @return float|null The tier price, or null if it is zero, missing, or above the base.
	 */
	public static function effective_tier_price( float $tier, float $base ): ?float {
		return ( $tier <= 0 || $tier > $base ) ? null : $tier;
	}

	/**
	 * The product's own current price, read fresh so repeated passes over the
	 * same cart cannot compound.
	 *
	 * @param \WC_Product $product Cart line product.
	 * @return float|null
	 */
	protected function base_price( \WC_Product $product ): ?float {
		$fresh = wc_get_product( $product->get_id() );

		if ( ! $fresh instanceof \WC_Product ) {
			return null;
		}

		$price = $fresh->get_price( 'edit' );

		return ( '' === $price || null === $price ) ? null : (float) $price;
	}

	/**
	 * Find the band a quantity falls into.
	 *
	 * Bands are scanned in order and the last match wins, so overlapping or
	 * out-of-order bands resolve to the most generous one rather than the
	 * first one written.
	 *
	 * @param array $tiers    Stored tiers.
	 * @param int   $quantity Quantity to place.
	 * @return array{index:int,tier:array}|null
	 */
	protected function tier_for( array $tiers, int $quantity ): ?array {
		$match = null;

		foreach ( $tiers as $index => $tier ) {
			$min = (int) ( $tier['min'] ?? 0 );
			$max = ( isset( $tier['max'] ) && null !== $tier['max'] ) ? (int) $tier['max'] : null;

			if ( $quantity < $min ) {
				continue;
			}

			if ( null !== $max && $quantity > $max ) {
				continue;
			}

			$match = [
				'index' => (int) $index,
				'tier'  => $tier,
			];
		}

		return $match;
	}
}
