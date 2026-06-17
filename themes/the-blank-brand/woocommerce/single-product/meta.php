<?php
/**
 * Single Product Meta — customised.
 *
 * Differs from the WooCommerce default in two ways:
 *  - The SKU is rendered beneath the title (see WooCommerce::single_sku()), so
 *    it is omitted here to avoid duplication.
 *  - Product categories are intentionally not shown.
 *
 * Tags are kept. This template can be overridden by copying it to
 * yourtheme/woocommerce/single-product/meta.php.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 3.0.0
 */

defined( 'ABSPATH' ) || exit;

global $product;
?>
<div class="product_meta">

	<?php do_action( 'woocommerce_product_meta_start' ); ?>

	<?php
	echo wc_get_product_tag_list(
		$product->get_id(),
		', ',
		'<span class="tagged_as">' . _n( 'Tag:', 'Tags:', count( $product->get_tag_ids() ), 'woocommerce' ) . ' ',
		'</span>'
	);
	?>

	<?php do_action( 'woocommerce_product_meta_end' ); ?>

</div>
