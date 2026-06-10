<?php
/**
 * The template for displaying the footer.
 *
 * @package BlankBrandTheme
 */

?>
		</main>

		<footer class="site-footer" aria-label="<?php esc_attr_e( 'Site footer', 'blank-brand-theme' ); ?>">
			<div class="container">
				<div class="site-footer__inner">

					<!-- Brand -->
					<div class="site-footer__box">
						<a href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php echo esc_attr( get_bloginfo( 'name' ) . ' — ' . __( 'Go to homepage', 'blank-brand-theme' ) ); ?>">
							<img
								src="<?php echo esc_url( get_template_directory_uri() ); ?>/assets/images/logo-white.png"
								alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
								width="219"
								height="21"
								loading="lazy"
							/>
						</a>
						<p>
							<?php esc_html_e( 'The Blank Brand delivers premium blank garments for businesses creating merchandise or clothing lines. We focus on quality, sustainability, and helping clients succeed by providing stylish, eco-conscious options for branding and clothing ventures.', 'blank-brand-theme' ); ?>
						</p>
					</div>

					<!-- Information nav -->
					<div class="site-footer__col">
						<h4 class="site-footer__col-heading"><?php esc_html_e( 'Information', 'blank-brand-theme' ); ?></h4>
						<nav aria-label="<?php esc_attr_e( 'Footer navigation', 'blank-brand-theme' ); ?>">
							<?php
							wp_nav_menu(
								[
									'theme_location' => 'footer',
									'menu_class'     => 'site-footer__menu',
									'container'      => false,
									'depth'          => 1,
									'fallback_cb'    => false,
								]
							);
							?>
						</nav>
					</div>

					<!-- Contact details -->
					<div class="site-footer__col">
						<h4 class="site-footer__col-heading"><?php esc_html_e( 'Contact Details', 'blank-brand-theme' ); ?></h4>

						<address class="site-footer__address">
							<a href="mailto:info@theblankbrand.co.za">info@theblankbrand.co.za</a>
							<a href="tel:+27826436707">+27 82 643 6707</a>
						</address>

						<ul class="site-footer__social" role="list" aria-label="<?php esc_attr_e( 'Social media links', 'blank-brand-theme' ); ?>">
							<li>
								<a
									href="<?php echo esc_url( 'https://www.facebook.com/theblankbrand' ); ?>"
									class="site-footer__social-link"
									aria-label="<?php esc_attr_e( 'Follow us on Facebook (opens in a new tab)', 'blank-brand-theme' ); ?>"
									target="_blank"
									rel="noopener noreferrer"
								>
									<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
										<path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
									</svg>
								</a>
							</li>
							<li>
								<a
									href="<?php echo esc_url( 'https://www.instagram.com/theblankbrand' ); ?>"
									class="site-footer__social-link"
									aria-label="<?php esc_attr_e( 'Follow us on Instagram (opens in a new tab)', 'blank-brand-theme' ); ?>"
									target="_blank"
									rel="noopener noreferrer"
								>
									<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
										<path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
									</svg>
								</a>
							</li>
						</ul>
					</div>

				</div>
			</div>

			<div class="site-footer__bottom">
				<div class="container">
					<p class="site-footer__copyright">
						&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php echo esc_html( get_bloginfo( 'name' ) ); ?>. <?php esc_html_e( 'All rights reserved.', 'blank-brand-theme' ); ?>
					</p>
				</div>
			</div>
		</footer>

		<?php wp_footer(); ?>
	</body>
</html>
