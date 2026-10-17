<?php
/**
 * The main template file
 *
 * @package BlankBrandTheme
 */

get_header();

// The My Account pages (login, lost password, orders, addresses...) are a shortcode with no
// wrapper of its own, so give them the same container and title the shop pages have.
$is_account_page = function_exists( 'is_account_page' ) && is_account_page();
?>

	<?php if ( have_posts() ) : ?>
		<?php while ( have_posts() ) : the_post(); ?>
			<?php if ( $is_account_page ) : ?>
				<div class="container u-margin-block account-page">
					<h1 class="page-title"><?php the_title(); ?></h1>
					<?php the_content(); ?>
				</div>
			<?php else : ?>
				<?php the_content(); ?>
			<?php endif; ?>
		<?php endwhile; ?>
	<?php endif; ?>

<?php
get_footer();
