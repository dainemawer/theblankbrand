<?php
/**
 * The template for displaying the search form.
 *
 * @package BlankBrandTheme
 */

?>

<div itemscope itemtype="http://schema.org/WebSite">
	<form role="search" id="searchform" class="search-form" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
		<meta itemprop="target" content="<?php echo esc_url( home_url() ); ?>/?s={s}" />
		<label for="search-field">
			<?php echo esc_html_x( 'Search for:', 'label', 'blank-brand-theme' ); ?>
		</label>
		<input itemprop="query-input" type="search" id="search-field" value="<?php echo get_search_query(); ?>" placeholder="<?php echo esc_attr_x( 'Search &hellip;', 'placeholder', 'blank-brand-theme' ); ?>" name="s" />
		<input type="submit" value="<?php echo esc_attr_x( 'Submit', 'submit button', 'blank-brand-theme' ); ?>">
	</form>
</div>
