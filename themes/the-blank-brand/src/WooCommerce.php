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

		// Keep the mini cart fragment up to date after AJAX add-to-cart.
		add_filter( 'woocommerce_add_to_cart_fragments', [ $this, 'cart_count_fragment' ] );

		// Ensure WooCommerce cart scripts load on every page so AJAX
		// add-to-cart and fragment refresh work site-wide.
		add_action( 'wp_enqueue_scripts', [ $this, 'load_cart_fragments' ] );
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
