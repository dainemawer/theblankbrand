<?php
/**
 * The template for displaying the header.
 *
 * @package BlankBrandTheme
 */

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> class="no-js">
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<?php wp_head(); ?>
	</head>
	<body <?php body_class(); ?>>
		<?php wp_body_open(); ?>

		<a href="#main" class="skip-to-content-link visually-hidden-focusable"><?php esc_html_e( 'Skip to main content', 'blank-brand-theme' ); ?></a>

		<header class="site-header" aria-label="<?php esc_attr_e( 'Site header', 'blank-brand-theme' ); ?>">
			<div class="container">
				<div class="site-header__inner">

					<div class="site-header__logo">
						<a
							href="<?php echo esc_url( home_url( '/' ) ); ?>"
							aria-label="<?php echo esc_attr( get_bloginfo( 'name' ) . ' — ' . __( 'Go to homepage', 'blank-brand-theme' ) ); ?>"
							rel="home"
						>
							<img
								src="<?php echo esc_url( get_template_directory_uri() ); ?>/assets/images/logo-white.png"
								alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
								width="173"
								height="18"
							/>
						</a>
					</div>

					<nav id="site-navigation" class="site-header__nav" aria-label="<?php esc_attr_e( 'Primary navigation', 'blank-brand-theme' ); ?>">
						<?php
						wp_nav_menu(
							[
								'theme_location' => 'primary',
								'menu_id'        => 'primary-menu',
								'menu_class'     => 'site-header__menu',
								'container'      => false,
								'depth'          => 2,
								'fallback_cb'    => false,
								'walker'         => new \BlankBrandTheme\NavWalker(),
							]
						);
						?>
					</nav>

					<?php if ( class_exists( 'WooCommerce' ) ) : ?>
					<div class="site-header__cart">
						<button
							class="site-header__cart-toggle"
							aria-expanded="false"
							aria-controls="site-header-minicart"
							aria-label="<?php
								$cart_count_label = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
								echo esc_attr(
									sprintf(
										/* translators: %d: number of items in cart */
										__( 'Open cart. %d items', 'blank-brand-theme' ),
										$cart_count_label
									)
								);
							?>"
						>
							<svg class="site-header__cart-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
								<path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/>
								<line x1="3" y1="6" x2="21" y2="6"/>
								<path d="M16 10a4 4 0 01-8 0"/>
							</svg>
							<?php $cart_count = ( WC()->cart && method_exists( WC()->cart, 'get_cart_contents_count' ) ) ? (int) WC()->cart->get_cart_contents_count() : 0; ?>
							<?php if ( $cart_count >= 1 ) : ?>
								<span class="site-header__cart-count" data-count="<?php echo esc_attr( $cart_count ); ?>" aria-live="polite" aria-atomic="true"><?php echo esc_html( $cart_count ); ?></span>
							<?php endif; ?>
						</button>

						<div
							id="site-header-minicart"
							class="site-header__minicart"
							aria-label="<?php esc_attr_e( 'Your cart', 'blank-brand-theme' ); ?>"
							hidden
						>
							<div class="widget_shopping_cart_content">
								<?php woocommerce_mini_cart(); ?>
							</div>
						</div>
					</div>
					<?php endif; ?>

				</div>
			</div>
		</header>

		<main id="main" role="main" tabindex="-1">
