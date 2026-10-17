<?php
/**
 * The template for a page that cannot be found.
 *
 * @package BlankBrandTheme
 */

get_header();
?>

	<div class="container u-margin-block">
		<section class="error-404">
			<h1 class="page-title"><?php esc_html_e( 'Page not found', 'blank-brand-theme' ); ?></h1>
			<p class="error-404__text">
				<?php esc_html_e( 'Sorry, we can’t find the page you’re looking for. It may have moved, or the link may be wrong.', 'blank-brand-theme' ); ?>
			</p>
			<a class="error-404__home" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php esc_html_e( 'Return home', 'blank-brand-theme' ); ?>
			</a>
		</section>
	</div>

<?php
get_footer();
