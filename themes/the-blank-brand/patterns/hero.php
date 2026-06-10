<?php
/**
 * Title: Homepage Hero
 * Slug: blank-brand-theme/hero
 * Categories: blank-brand-theme
 * Keywords: hero, banner, homepage
 * Description: Full-screen hero with logo, tagline and CTA buttons.
 *
 * @package BlankBrandTheme
 */
?>

<!-- wp:cover {"overlayColor":"neutral","dimRatio":50,"minHeight":100,"minHeightUnit":"vh","align":"full","style":{"spacing":{"padding":{"top":"var(--wp--preset--spacing--80)","bottom":"var(--wp--preset--spacing--80)","left":"var(--wp--custom--site-outer-padding)","right":"var(--wp--custom--site-outer-padding)"}}}} -->
<div class="wp-block-cover alignfull" style="min-height:100vh;padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--custom--site-outer-padding);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--custom--site-outer-padding)">
	<span aria-hidden="true" class="wp-block-cover__background has-neutral-background-color has-background-dim-50 has-background-dim"></span>
	<div class="wp-block-cover__inner-container">

		<!-- wp:group {"layout":{"type":"flex","orientation":"vertical","justifyContent":"center","verticalAlignment":"center"}} -->
		<div class="wp-block-group">

			<!-- wp:image {"align":"center","width":300,"sizeSlug":"full","linkDestination":"none","alt":"The Blank Brand"} -->
			<figure class="wp-block-image aligncenter size-full is-resized"><img src="" alt="The Blank Brand" style="width:300px" width="300"/></figure>
			<!-- /wp:image -->

			<!-- wp:paragraph {"align":"center","textColor":"secondary","style":{"typography":{"letterSpacing":"0.2em","textTransform":"uppercase"},"spacing":{"margin":{"top":"var(--wp--preset--spacing--16)","bottom":"0"}}}, "fontSize":"sm"} -->
			<p class="has-text-align-center has-secondary-color has-text-color" style="letter-spacing:0.2em;text-transform:uppercase;margin-top:var(--wp--preset--spacing--16);margin-bottom:0">Premium blank apparel suppliers &amp; printing house</p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"},"style":{"spacing":{"margin":{"top":"var(--wp--preset--spacing--40)"},"blockGap":"var(--wp--preset--spacing--16)"}}} -->
			<div class="wp-block-buttons" style="margin-top:var(--wp--preset--spacing--40)">

				<!-- wp:button -->
				<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/shop/?filter_cat=men">Shop Men</a></div>
				<!-- /wp:button -->

				<!-- wp:button {"className":"is-style-outline"} -->
				<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/shop/?filter_cat=women">Shop Women</a></div>
				<!-- /wp:button -->

			</div>
			<!-- /wp:buttons -->

		</div>
		<!-- /wp:group -->

	</div>
</div>
<!-- /wp:cover -->
