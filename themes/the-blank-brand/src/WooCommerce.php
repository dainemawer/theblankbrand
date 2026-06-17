<?php
/**
 * WooCommerce integration.
 *
 * @package BlankBrandTheme
 */

namespace BlankBrandTheme;

use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * WooCommerce module.
 */
class WooCommerce implements ModuleInterface {

	use Module;

	/**
	 * Only register if WooCommerce is active.
	 *
	 * @return bool
	 */
	public function can_register(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_image_size_thumbnail', [ $this, 'thumbnail_image_size' ] );

		// Breadcrumb is rendered inside loop/header.php instead.
		remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );

		// This theme has no sidebar.
		remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );

		// Hide the catalog ordering ("Default sorting") dropdown.
		remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );

		// Show 12 products per archive page (WooCommerce defaults to 16).
		add_filter( 'loop_shop_per_page', [ $this, 'products_per_page' ] );

		// Single product summary — add breadcrumb + SKU and re-order so the
		// stack reads: breadcrumb, title, SKU, rating, description, price.
		add_action( 'woocommerce_single_product_summary', 'woocommerce_breadcrumb', 4 );
		add_action( 'woocommerce_single_product_summary', [ $this, 'single_sku' ], 6 );

		// Review rating under the title, before the description.
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10 );
		add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_rating', 7 );

		// Show the full description (in place of the short one) above the price,
		// and remove the product data tabs entirely.
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20 );
		add_action( 'woocommerce_single_product_summary', [ $this, 'single_full_description' ], 15 );

		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
		add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 25 );

		remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10 );

		// The bulk-variations plugin replaces WooCommerce's native variations form
		// with its grid (which we relocate to a modal). Re-render the native
		// size/quantity/add-to-cart form inline so customers can also buy a single
		// variation the standard way. Colour is chosen via the swatch chips, which
		// set the (hidden) colour dropdown in this form via JS.
		add_action( 'woocommerce_variable_add_to_cart', [ $this, 'render_native_variation_form' ], 2 );

		// Render the Size attribute as custom radio pills (alongside the native
		// select, which JS keeps in sync). Colour is handled by the swatch chips.
		add_filter( 'woocommerce_dropdown_variation_attribute_options_html', [ $this, 'size_radio_options' ], 20, 2 );

		// Keep the mini cart fragment up to date after AJAX add-to-cart.
		add_filter( 'woocommerce_add_to_cart_fragments', [ $this, 'cart_count_fragment' ] );

		// Ensure WooCommerce cart scripts load on every page so AJAX
		// add-to-cart and fragment refresh work site-wide.
		add_action( 'wp_enqueue_scripts', [ $this, 'load_cart_fragments' ] );
	}

	/**
	 * Number of products shown per shop/archive page.
	 *
	 * @param int $per_page Current per-page value.
	 * @return int
	 */
	public function products_per_page( $per_page ): int {
		return 12;
	}

	/**
	 * Render WooCommerce's native variable add-to-cart form (attribute dropdowns,
	 * quantity, add-to-cart) when the bulk-variations plugin has removed it.
	 *
	 * Runs at priority 2 on `woocommerce_variable_add_to_cart`, after the plugin's
	 * priority-1 callback has (when its grid is enabled) removed the core form. We
	 * only render if the core form is gone, so products without the grid are left
	 * untouched (WooCommerce renders its own form at priority 30). The grid still
	 * renders at priority 30 and is relocated to a modal by JS.
	 *
	 * @return void
	 */
	public function render_native_variation_form(): void {
		if ( ! has_action( 'woocommerce_variable_add_to_cart', 'woocommerce_variable_add_to_cart' ) ) {
			woocommerce_variable_add_to_cart();
		}
	}

	/**
	 * Append custom radio-pill size controls to the native Size dropdown.
	 *
	 * Returns the original `<select>` markup (kept for WooCommerce's variation
	 * script and hidden via CSS) followed by a radio group + "Size:" label. JS
	 * mirrors a pill click into the select. Only affects the `pa_size` attribute.
	 *
	 * @param string $html The default dropdown HTML.
	 * @param array  $args Dropdown args (options, attribute, selected, product…).
	 * @return string
	 */
	public function size_radio_options( $html, $args ): string {
		$attribute = $args['attribute'] ?? '';
		if ( 'pa_size' !== $attribute ) {
			return $html;
		}

		$options  = $args['options'] ?? [];
		$product  = $args['product'] ?? null;
		$selected = isset( $args['selected'] ) ? (string) $args['selected'] : '';

		// Build slug => label (term name) in WooCommerce's configured attribute
		// order. `$args['options']` is unordered (alphabetical), so for taxonomy
		// attributes we iterate the menu-ordered terms and keep only the values
		// this product offers — mirroring how the native <select> is ordered.
		$labels = [];
		if ( taxonomy_exists( $attribute ) && $product instanceof \WC_Product ) {
			$terms = wc_get_product_terms( $product->get_id(), $attribute, [ 'fields' => 'all' ] );
			foreach ( $terms as $term ) {
				if ( ! $options || in_array( $term->slug, $options, true ) ) {
					$labels[ $term->slug ] = $term->name;
				}
			}
		} else {
			foreach ( $options as $slug ) {
				$labels[ $slug ] = $slug;
			}
		}

		if ( ! $labels ) {
			return $html;
		}

		$selected_label = $labels[ $selected ] ?? '';

		ob_start();
		?>
		<p class="tbb-colour-label tbb-size-label">
			<?php esc_html_e( 'Size:', 'blank-brand-theme' ); ?>
			<span class="tbb-selected-size"><?php echo esc_html( $selected_label ); ?></span>
		</p>
		<div class="tbb-sizes" role="radiogroup" aria-label="<?php esc_attr_e( 'Choose a size', 'blank-brand-theme' ); ?>">
			<?php
			foreach ( $labels as $slug => $label ) :
				$is_active = ( (string) $slug === $selected );
				?>
				<button
					type="button"
					class="tbb-size<?php echo $is_active ? ' is-active' : ''; ?>"
					role="radio"
					aria-checked="<?php echo $is_active ? 'true' : 'false'; ?>"
					data-value="<?php echo esc_attr( $slug ); ?>"
				><?php echo esc_html( $label ); ?></button>
			<?php endforeach; ?>
		</div>
		<?php
		return $html . ob_get_clean();
	}

	/**
	 * Output the product SKU beneath the title in the single product summary.
	 * (The default SKU in product_meta is removed via the meta.php override.)
	 *
	 * @return void
	 */
	public function single_sku(): void {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$sku = $product->get_sku();

		if ( ! $sku ) {
			return;
		}

		printf(
			'<p class="product-sku"><span class="product-sku__label">%1$s</span> %2$s</p>',
			esc_html__( 'SKU:', 'blank-brand-theme' ),
			esc_html( $sku )
		);
	}

	/**
	 * Output the full product description in the single product summary.
	 * Replaces the short description; the data tabs are removed separately.
	 *
	 * @return void
	 */
	public function single_full_description(): void {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$description = $product->get_description();

		if ( ! $description ) {
			return;
		}

		echo '<div class="woocommerce-product-details__description">';
		// the_content runs blocks, shortcodes and wpautop; output is sanitised
		// by core's content filters, matching how WooCommerce renders it.
		echo apply_filters( 'the_content', $description ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	/**
	 * Set the loop thumbnail size to 300×533 (portrait crop).
	 *
	 * @param array $size Current size array.
	 * @return array
	 */
	public function thumbnail_image_size( array $size ): array {
		$size['width']  = 600;
		$size['height'] = 1066;
		$size['crop']   = 1;

		return $size;
	}

	/**
	 * Push an updated cart count element into WooCommerce's AJAX fragment response.
	 * WooCommerce replaces any element matching the array key with the new HTML.
	 *
	 * @param array $fragments Existing fragments.
	 * @return array
	 */
	public function cart_count_fragment( array $fragments ): array {
		$count = WC()->cart->get_cart_contents_count();

		ob_start();
		?>
		<span class="site-header__cart-count" data-count="<?php echo esc_attr( $count ); ?>" aria-live="polite" aria-atomic="true">
			<?php echo esc_html( $count ); ?>
		</span>
		<?php
		$fragments['.site-header__cart-count'] = ob_get_clean();

		return $fragments;
	}

	/**
	 * Ensure WooCommerce cart fragments JS is enqueued everywhere.
	 * By default WooCommerce only loads it on the cart/checkout pages.
	 *
	 * @return void
	 */
	public function load_cart_fragments(): void {
		if ( function_exists( 'wc_get_cart_url' ) ) {
			wp_enqueue_script( 'wc-cart-fragments' );
		}
	}
}
