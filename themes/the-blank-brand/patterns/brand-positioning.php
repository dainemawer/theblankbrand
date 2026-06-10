<?php
/**
 * Title: Brand Positioning
 * Slug: blank-brand-theme/brand-positioning
 * Categories: blank-brand-theme
 * Keywords: about, brand, supplier, quality, split, image
 * Description: Two-column split — text left, image right.
 *
 * @package BlankBrandTheme
 */
?>

<!-- wp:group {"align":"full","backgroundColor":"primary","style":{"spacing":{"padding":{"top":"var(--wp--preset--spacing--80)","bottom":"var(--wp--preset--spacing--80)","left":"var(--wp--custom--site-outer-padding)","right":"var(--wp--custom--site-outer-padding)"}}}} -->
<div class="wp-block-group alignfull has-primary-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--custom--site-outer-padding);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--custom--site-outer-padding)">

	<!-- wp:columns {"align":"wide","verticalAlignment":"center","style":{"spacing":{"blockGap":{"top":"var(--wp--preset--spacing--62)","left":"var(--wp--preset--spacing--80)"}}}} -->
	<div class="wp-block-columns alignwide are-vertically-aligned-center">

		<!-- wp:column {"verticalAlignment":"center","width":"50%"} -->
		<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:50%">

			<!-- wp:heading {"textColor":"tertiary","style":{"typography":{"letterSpacing":"0.08em","textTransform":"uppercase"},"spacing":{"margin":{"bottom":"var(--wp--preset--spacing--24)"}}},"fontSize":"3xl"} -->
			<h2 class="wp-block-heading has-tertiary-color has-text-color" style="letter-spacing:0.08em;text-transform:uppercase;margin-bottom:var(--wp--preset--spacing--24)">Supplier of <em>Quality</em> Apparel</h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"textColor":"neutral","style":{"spacing":{"margin":{"bottom":"var(--wp--preset--spacing--40)"}}}} -->
			<p class="has-neutral-color has-text-color" style="margin-bottom:var(--wp--preset--spacing--40)">Since 2015 we've been dedicated to providing high-quality, stylish blank apparel to businesses, brands, and creators across South Africa. From tees to fleece, every garment is chosen for its cut, feel, and finish.</p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button -->
				<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/about-us/">Learn More</a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->

		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"center","width":"50%"} -->
		<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:50%">

			<!-- wp:image {"sizeSlug":"large","linkDestination":"none","alt":"Quality blank apparel on hangers","style":{"border":{"radius":"4px"}},"aspectRatio":"4/5"} -->
			<figure class="wp-block-image size-large" style="border-radius:4px"><img src="" alt="Quality blank apparel on hangers" style="aspect-ratio:4/5;object-fit:cover"/></figure>
			<!-- /wp:image -->

		</div>
		<!-- /wp:column -->

	</div>
	<!-- /wp:columns -->

</div>
<!-- /wp:group -->
