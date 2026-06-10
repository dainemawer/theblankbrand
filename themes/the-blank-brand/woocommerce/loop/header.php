<?php
/**
 * Product taxonomy archive header
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/loop/header.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 8.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<header class="woocommerce-products-header">
	<?php if ( apply_filters( 'woocommerce_show_page_title', true ) ) : ?>
		<h1 class="woocommerce-products-header__title page-title"><?php woocommerce_page_title(); ?></h1>
	<?php endif; ?>

	<?php
	$term = get_queried_object();
	if ( $term instanceof WP_Term ) :
		$ancestors = array_reverse( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) );
		?>
		<nav class="woocommerce-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'woocommerce' ); ?>">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'woocommerce' ); ?></a>
			<?php foreach ( $ancestors as $ancestor_id ) : ?>
				<?php $ancestor = get_term( $ancestor_id, 'product_cat' ); ?>
				&nbsp;/&nbsp;<a href="<?php echo esc_url( get_term_link( $ancestor ) ); ?>"><?php echo esc_html( $ancestor->name ); ?></a>
			<?php endforeach; ?>
			&nbsp;/&nbsp;<span><?php echo esc_html( $term->name ); ?></span>
		</nav>
	<?php endif; ?>

	<?php do_action( 'woocommerce_archive_description' ); ?>
</header>
