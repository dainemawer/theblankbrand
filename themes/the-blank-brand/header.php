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

					<button
						class="site-header__menu-toggle"
						aria-expanded="false"
						aria-controls="site-navigation"
						aria-label="<?php esc_attr_e( 'Open menu', 'blank-brand-theme' ); ?>"
					>
						<svg class="site-header__menu-toggle-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
							<line x1="3" y1="6" x2="21" y2="6"/>
							<line x1="3" y1="12" x2="21" y2="12"/>
							<line x1="3" y1="18" x2="21" y2="18"/>
						</svg>
					</button>

					<nav id="site-navigation" class="site-header__nav" aria-label="<?php esc_attr_e( 'Primary navigation', 'blank-brand-theme' ); ?>">
						<div class="site-header__nav-head">
							<button
								class="site-header__nav-close"
								aria-label="<?php esc_attr_e( 'Close menu', 'blank-brand-theme' ); ?>"
							>
								<svg class="site-header__nav-close-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
									<line x1="6" y1="6" x2="18" y2="18"/>
									<line x1="18" y1="6" x2="6" y2="18"/>
								</svg>
							</button>
						</div>
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
					<div class="site-header__account">
						<?php if ( is_user_logged_in() ) : ?>
							<?php
							$account_items = wc_get_account_menu_items();
							unset( $account_items['customer-logout'] );
							?>
							<button
								class="site-header__account-toggle"
								aria-expanded="false"
								aria-controls="site-header-account-menu"
								aria-label="<?php esc_attr_e( 'Account menu', 'blank-brand-theme' ); ?>"
							>
								<svg class="site-header__account-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
									<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
									<circle cx="12" cy="7" r="4"/>
								</svg>
							</button>

							<div
								id="site-header-account-menu"
								class="site-header__account-menu"
								aria-label="<?php esc_attr_e( 'Your account', 'blank-brand-theme' ); ?>"
								hidden
							>
								<p class="site-header__account-name">
									<?php
									/* translators: %s: the logged-in customer's display name. */
									printf( esc_html__( 'Signed in as %s', 'blank-brand-theme' ), '<strong>' . esc_html( wp_get_current_user()->display_name ) . '</strong>' );
									?>
								</p>
								<ul class="site-header__account-list">
									<?php foreach ( $account_items as $endpoint => $label ) : ?>
										<li>
											<a href="<?php echo esc_url( wc_get_account_endpoint_url( $endpoint ) ); ?>"><?php echo esc_html( 'dashboard' === $endpoint ? __( 'My account', 'blank-brand-theme' ) : $label ); ?></a>
										</li>
									<?php endforeach; ?>
								</ul>
								<a class="site-header__account-logout" href="<?php echo esc_url( wc_logout_url() ); ?>"><?php esc_html_e( 'Log out', 'blank-brand-theme' ); ?></a>
							</div>
						<?php else : ?>
							<a class="site-header__account-link" href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>">
								<svg class="site-header__account-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
									<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
									<circle cx="12" cy="7" r="4"/>
								</svg>
								<span class="site-header__account-label"><?php esc_html_e( 'Log in', 'blank-brand-theme' ); ?></span>
							</a>
						<?php endif; ?>
					</div>

					<div class="site-header__cart">
						<button
							class="site-header__cart-toggle"
							aria-expanded="false"
							aria-controls="site-header-minicart"
							aria-label="<?php esc_attr_e( 'Open cart', 'blank-brand-theme' ); ?>"
						>
							<svg class="site-header__cart-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
								<path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/>
								<line x1="3" y1="6" x2="21" y2="6"/>
								<path d="M16 10a4 4 0 01-8 0"/>
							</svg>
							<?php $cart_count = ( WC()->cart && method_exists( WC()->cart, 'get_cart_contents_count' ) ) ? (int) WC()->cart->get_cart_contents_count() : 0; ?>
							<?php echo \TenupTheme\cart_count_html( $cart_count ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
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

			<div class="site-header__backdrop" hidden></div>
		</header>

		<main id="main" role="main" tabindex="-1">
