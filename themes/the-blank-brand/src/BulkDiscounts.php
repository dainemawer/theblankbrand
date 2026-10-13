<?php
/**
 * Bulk Discounts — front end.
 *
 * Renders the quantity-price tiers as an accordion under the product
 * description and price on the single product page. Dormant when a product
 * has no tiers.
 *
 * Tiers come from the `_tbb_price_tiers` structure rather than the hand-typed
 * HTML this used to print, and the first tier's price is never read from that
 * structure at all — it is taken live from the product. Storing it twice is
 * what let the page advertise one price while checkout charged another; there
 * is now only one number, so the two cannot drift.
 *
 * The 50+ rate has no home in WooCommerce, so it is stored, synced from Zoho
 * by {@see \TBB\Zoho\StockSync}, and stamped with the time it was
 * last confirmed.
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

	const META = '_tbb_price_tiers';

	const LEGACY_META = '_tbb_bulk_discounts';

	const QUOTE_EMAIL = 'info@theblankbrand.co.za';

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

		$rows = $this->rows( $product );

		if ( ! $rows ) {
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
				<ul>
					<?php foreach ( $rows as $row ) : ?>
						<li><strong><?php echo esc_html( $row['label'] ); ?></strong> <?php echo wp_kses_post( $row['value'] ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p><em><?php esc_html_e( 'Prices exclude VAT.', 'blank-brand-theme' ); ?></em></p>
			</div>
		</details>
		<?php
	}

	/**
	 * Build the rows for a product's tier table.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<int,array{label:string,value:string}>
	 */
	protected function rows( \WC_Product $product ): array {
		$data = get_post_meta( $product->get_id(), self::META, true );

		if ( ! is_array( $data ) || empty( $data['tiers'] ) ) {
			return $this->legacy_rows( $product );
		}

		// The first tier is what the customer actually pays, so it is read from
		// the product rather than from the stored tiers.
		$price = $product->get_price();

		if ( '' === $price || null === $price ) {
			return [];
		}

		$rows  = [];
		$tiers = array_values( $data['tiers'] );

		foreach ( $tiers as $index => $tier ) {
			$amount = 0 === $index
				? (float) $price
				: BulkPricing::effective_tier_price( (float) ( $tier['price'] ?? 0 ), (float) $price );

			// A tier the cart would not apply is not advertised either.
			if ( null === $amount || $amount <= 0 ) {
				continue;
			}

			$rows[] = [
				'label' => $this->label( (int) ( $tier['min'] ?? 0 ), $tier['max'] ?? null ),
				// Formatted by wc_price() for the currency symbol and separators,
				// then reduced to plain text: the markup carries
				// `.woocommerce-Price-amount`, which the summary styles at
				// 1.5rem for the headline price. These are reference figures in
				// a list, not the product's price, and should read as list text.
				'value' => html_entity_decode(
					wp_strip_all_tags( wc_price( $amount, [ 'decimals' => 0 ] ) ),
					ENT_QUOTES,
					get_bloginfo( 'charset' )
				),
			];
		}

		if ( ! empty( $data['quote_from'] ) ) {
			$rows[] = [
				'label' => sprintf(
					/* translators: %d: minimum quantity for a quote. */
					__( '%d+ items?', 'blank-brand-theme' ),
					(int) $data['quote_from']
				),
				'value' => sprintf(
					'<a href="%s">%s</a>',
					esc_url( 'mailto:' . self::QUOTE_EMAIL ),
					esc_html__( 'Get a quote', 'blank-brand-theme' )
				),
			];
		}

		// A lone tier tells the customer nothing they can't read off the price.
		return count( $rows ) > 1 ? $rows : [];
	}

	/**
	 * Fall back to the hand-typed HTML this module used to render.
	 *
	 * A product with no stored tiers would otherwise render nothing at all, and
	 * an accordion that quietly disappears is the kind of thing nobody notices
	 * until a customer asks why bulk pricing has gone. Products added after the
	 * migration have no tiers until the next sync writes them, so this is the
	 * gap that actually occurs.
	 *
	 * The legacy field is left in place on every migrated product precisely so
	 * this remains possible. Its figures are not synced, so they are only ever
	 * a stopgap — but a stale price shown is easier to spot than no price at all.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<int,array{label:string,value:string}>
	 */
	protected function legacy_rows( \WC_Product $product ): array {
		$content = get_post_meta( $product->get_id(), self::LEGACY_META, true );

		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return [];
		}

		preg_match_all( '#<li>(.*?)</li>#is', $content, $matches );

		$rows = [];

		foreach ( $matches[1] as $item ) {
			$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $item ) ) );

			if ( '' === $text ) {
				continue;
			}

			// Split on the first colon or question mark so the label keeps its
			// emphasis and the value carries any link, matching the structured
			// rows this stands in for.
			if ( preg_match( '/^(.*?[:?])\s*(.*)$/', $text, $parts ) ) {
				$label = $parts[1];
				$value = $parts[2];
			} else {
				$label = $text;
				$value = '';
			}

			// The quote row is the only one carrying a link; rebuild it rather
			// than passing stored markup through.
			if ( false !== stripos( $value, 'quote' ) ) {
				$value = sprintf(
					'<a href="%s">%s</a>',
					esc_url( 'mailto:' . self::QUOTE_EMAIL ),
					esc_html__( 'Get a quote', 'blank-brand-theme' )
				);
			}

			$rows[] = [
				'label' => $label,
				'value' => $value,
			];
		}

		return count( $rows ) > 1 ? $rows : [];
	}

	/**
	 * Human label for a quantity band, e.g. "1-49 items:" or "50+ items:".
	 *
	 * @param int      $min Lower bound.
	 * @param int|null $max Upper bound, null for open-ended.
	 * @return string
	 */
	protected function label( int $min, $max ): string {
		return null === $max
			/* translators: %d: minimum quantity. */
			? sprintf( __( '%d+ items:', 'blank-brand-theme' ), $min )
			/* translators: 1: minimum quantity, 2: maximum quantity. */
			: sprintf( __( '%1$d-%2$d items:', 'blank-brand-theme' ), $min, (int) $max );
	}
}
