<?php
/**
 * Colour Swatches — front end.
 *
 * Renders hex-chip swatches (single product + category grid) from the editable
 * relationship stored by {@see ColourSwatchesAdmin}:
 *   - `_tbb_colour_images` product meta: term_id => [attachment ids]
 *   - `colour_hex` term meta: the chip colour
 *
 * Clicking a chip swaps the whole product gallery (single) or the card image
 * (category grid). Dormant until images/hex are assigned — no images means the
 * chip still shows the colour but performs no swap (never a broken/flat tile).
 *
 * @package BlankBrandTheme
 */

namespace BlankBrandTheme;

use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * Colour Swatches front-end module.
 *
 * @package BlankBrandTheme
 */
class ColourSwatches implements ModuleInterface {

	use Module;

	const IMAGES_META = '_tbb_colour_images';
	const HEX_META    = 'colour_hex';
	const SWATCH_META = '_tbb_swatch_image';

	/**
	 * Can this module be registered?
	 *
	 * @return bool
	 */
	public function can_register() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', [ $this, 'assets' ] );

		// Our swatches own per-colour gallery display, so stop Barn2 from dumping
		// every variation image into the gallery.
		add_filter( 'wc_bulk_variations_add_images_to_gallery', '__return_false' );

		// Single product: chips under the short description.
		add_action( 'woocommerce_single_product_summary', [ $this, 'render_single_chips' ], 25 );

		// Category grid: drop the "Select options" / add-to-cart button on cards…
		remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
		// …and render colour chips under each product card instead.
		add_action( 'woocommerce_after_shop_loop_item', [ $this, 'render_loop_chips' ], 11 );
	}

	/**
	 * Enqueue build-free assets on product + archive views.
	 *
	 * @return void
	 */
	public function assets() {
		if ( ! is_product() && ! is_shop() && ! is_product_taxonomy() ) {
			return;
		}
		$base = get_template_directory_uri() . '/assets';
		$path = get_template_directory() . '/assets';

		wp_enqueue_style(
			'tbb-colour-swatches',
			"$base/css/colour-swatches.css",
			[],
			file_exists( "$path/css/colour-swatches.css" ) ? filemtime( "$path/css/colour-swatches.css" ) : BLANK_BRAND_THEME_VERSION
		);
		wp_enqueue_script(
			'tbb-colour-swatches',
			"$base/js/colour-swatches.js",
			[ 'jquery' ],
			file_exists( "$path/js/colour-swatches.js" ) ? filemtime( "$path/js/colour-swatches.js" ) : BLANK_BRAND_THEME_VERSION,
			true
		);

		// Expose an in-stock colour×size matrix so the size pills can disable
		// sizes that aren't available for the selected colour.
		if ( is_product() ) {
			$matrix = $this->size_stock_matrix( wc_get_product( get_queried_object_id() ) );
			if ( null !== $matrix ) {
				wp_add_inline_script(
					'tbb-colour-swatches',
					'window.tbbSizeStock = ' . wp_json_encode( $matrix ) . ';',
					'before'
				);
			}
		}
	}

	/**
	 * Build a `{ colourSlug: { sizeSlug: true } }` map of in-stock, purchasable
	 * colour×size combinations for a variable product. Only in-stock combos are
	 * listed; every product colour gets an entry (empty if none of its sizes are
	 * available), so the JS can disable any size not present for a colour.
	 *
	 * Variations with an empty colour or size apply to all of that attribute's
	 * terms (WooCommerce "Any" variations).
	 *
	 * @param \WC_Product|false $product Product.
	 * @return array|null Matrix, or null if not an applicable variable product.
	 */
	protected function size_stock_matrix( $product ) {
		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
			return null;
		}

		$sizes   = wc_get_product_terms( $product->get_id(), 'pa_size', [ 'fields' => 'slugs' ] );
		$colours = wc_get_product_terms( $product->get_id(), 'pa_colour', [ 'fields' => 'slugs' ] );
		if ( ! $sizes ) {
			return null;
		}
		if ( ! $colours ) {
			$colours = [ '' ]; // Size-only product: single bucket.
		}

		// Seed every colour so a colour with no available sizes disables them all.
		$matrix = [];
		foreach ( $colours as $colour ) {
			$matrix[ $colour ] = [];
		}

		foreach ( $product->get_available_variations() as $variation ) {
			$in_stock = ! empty( $variation['is_in_stock'] ) && ! empty( $variation['is_purchasable'] );
			if ( ! $in_stock ) {
				continue;
			}
			$v_colour = $variation['attributes']['attribute_pa_colour'] ?? '';
			$v_size   = $variation['attributes']['attribute_pa_size'] ?? '';

			$v_colours = '' !== $v_colour ? [ $v_colour ] : $colours;
			$v_sizes   = '' !== $v_size ? [ $v_size ] : $sizes;

			foreach ( $v_colours as $colour ) {
				foreach ( $v_sizes as $size ) {
					$matrix[ $colour ][ $size ] = true;
				}
			}
		}

		return $matrix;
	}

	/**
	 * Map each colour slug to its variation thumbnail attachment IDs (deduped).
	 *
	 * This is the deterministic fallback for colour images: WooCommerce already
	 * stores a per-variation image (`_thumbnail_id`), and variations of the same
	 * colour share it, so this yields each colour's main product shot without any
	 * manual mapping. Variations with an empty colour ("Any") are skipped.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string,int[]> colour slug => attachment IDs.
	 */
	protected function variation_colour_thumbs( $product ) {
		$out = [];
		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
			return $out;
		}
		foreach ( $product->get_children() as $variation_id ) {
			$slug  = get_post_meta( $variation_id, 'attribute_pa_colour', true );
			$thumb = (int) get_post_meta( $variation_id, '_thumbnail_id', true );
			if ( ! $slug || ! $thumb ) {
				continue;
			}
			if ( ! isset( $out[ $slug ] ) ) {
				$out[ $slug ] = [];
			}
			if ( ! in_array( $thumb, $out[ $slug ], true ) ) {
				$out[ $slug ][] = $thumb;
			}
		}
		return $out;
	}

	/**
	 * Build the ordered colour dataset for a product.
	 *
	 * @param \WC_Product $product      Product.
	 * @param bool        $with_gallery Include pre-rendered gallery markup (single).
	 * @return array
	 */
	protected function get_colours( $product, $with_gallery = false ) {
		if ( ! $product instanceof \WC_Product ) {
			return [];
		}

		$map   = get_post_meta( $product->get_id(), self::IMAGES_META, true );
		$map   = is_array( $map ) ? $map : [];
		$terms = wc_get_product_terms( $product->get_id(), 'pa_colour', [ 'fields' => 'all' ] );

		// Fallback image source: each colour's variation thumbnail(s). Used when a
		// colour has no manual `_tbb_colour_images` entry, so chips work straight
		// from WooCommerce's per-variation images with no manual mapping.
		$variation_thumbs = $this->variation_colour_thumbs( $product );

		$out = [];

		foreach ( $terms as $term ) {
			$ids = isset( $map[ $term->term_id ] ) ? array_map( 'intval', (array) $map[ $term->term_id ] ) : [];
			$ids = array_values( array_filter( $ids, fn( $id ) => 'attachment' === get_post_type( $id ) ) );

			if ( ! $ids && ! empty( $variation_thumbs[ $term->slug ] ) ) {
				$ids = array_values( array_filter( $variation_thumbs[ $term->slug ], fn( $id ) => 'attachment' === get_post_type( $id ) ) );
			}

			$swatch_id = (int) get_term_meta( $term->term_id, self::SWATCH_META, true );

			$entry = [
				'slug'   => $term->slug,
				'name'   => $term->name,
				'hex'    => get_term_meta( $term->term_id, self::HEX_META, true ) ?: '',
				'swatch' => $swatch_id ? wp_get_attachment_image_url( $swatch_id, 'thumbnail' ) : '',
				'has'    => ! empty( $ids ),
			];

			if ( $ids && $with_gallery ) {
				// WooCommerce renders non-main gallery images at the square
				// `gallery_thumbnail` size (clipped). Force the portrait crop used
				// elsewhere on the site so the thumbnails match the main image.
				$portrait_thumb = static fn() => 'woocommerce_thumbnail';
				add_filter( 'woocommerce_gallery_thumbnail_size', $portrait_thumb );

				$html = '';
				foreach ( $ids as $i => $id ) {
					$html .= wc_get_gallery_image_html( $id, 0 === $i );
				}
				$entry['gallery'] = $html;

				remove_filter( 'woocommerce_gallery_thumbnail_size', $portrait_thumb );
			}
			if ( $ids && ! $with_gallery ) {
				$entry['img']    = wp_get_attachment_image_url( $ids[0], 'woocommerce_thumbnail' );
				$entry['srcset'] = wp_get_attachment_image_srcset( $ids[0], 'woocommerce_thumbnail' ) ?: '';
			}

			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * Shared chip markup.
	 *
	 * @param array  $colours Colour entries.
	 * @param string $variant CSS modifier (strip|chips|loop).
	 * @param string $label   Group aria-label.
	 * @return void
	 */
	protected function render_chips( $colours, $variant, $label, $show_selected_label = false ) {
		// Only show colours that actually have an image — hide unmapped chips
		// entirely rather than rendering an inert, imageless swatch.
		$colours = array_values( array_filter( $colours, fn( $c ) => ! empty( $c['has'] ) ) );

		if ( ! $colours ) {
			return;
		}

		// The active colour = first one that has images (falls back to the first).
		$selected = $colours[0]['name'];
		foreach ( $colours as $c ) {
			if ( $c['has'] ) {
				$selected = $c['name'];
				break;
			}
		}

		if ( $show_selected_label ) {
			printf(
				'<p class="tbb-colour-label">%s <span class="tbb-selected-colour">%s</span></p>',
				esc_html__( 'Colour:', 'blank-brand-theme' ),
				esc_html( $selected )
			);
		}

		printf( '<div class="tbb-swatches tbb-swatches--%s" role="group" aria-label="%s">', esc_attr( $variant ), esc_attr( $label ) );
		$first_active = false;
		foreach ( $colours as $c ) {
			$is_loop = 'loop' === $variant;
			$active  = ! $first_active && $c['has'] && ! $is_loop;
			if ( $active ) {
				$first_active = true;
			}

			$data = '';
			if ( $is_loop && ! empty( $c['img'] ) ) {
				$data = sprintf( ' data-img="%s" data-srcset="%s"', esc_url( $c['img'] ), esc_attr( $c['srcset'] ?? '' ) );
			} elseif ( ! $is_loop && ! empty( $c['gallery'] ) ) {
				$data = sprintf( ' data-gallery="%s"', esc_attr( $c['gallery'] ) );
			}

			$inner = ! empty( $c['swatch'] )
				? sprintf( '<img src="%s" alt="" loading="lazy" />', esc_url( $c['swatch'] ) )
				: '';

			printf(
				'<button type="button" class="tbb-chip%1$s%2$s%3$s" data-colour="%4$s" style="--swatch:%5$s" title="%6$s" aria-label="%6$s" aria-pressed="%7$s"%8$s>%9$s<span class="screen-reader-text">%6$s</span></button>',
				$active ? ' is-active' : '',
				$c['has'] ? '' : ' tbb-chip--no-image',
				! empty( $c['swatch'] ) ? ' tbb-chip--image' : '',
				esc_attr( $c['slug'] ),
				esc_attr( $c['hex'] ?: '#cccccc' ),
				esc_attr( $c['name'] ),
				$active ? 'true' : 'false',
				$data,
				$inner
			);
		}
		echo '</div>';
	}

	/**
	 * Single product: chips under the gallery.
	 *
	 * @return void
	 */
	public function render_single_strip() {
		global $product;
		$this->render_chips( $this->get_colours( $product, true ), 'strip', __( 'Choose a colour', 'blank-brand-theme' ) );
	}

	/**
	 * Single product: chips under the short description.
	 *
	 * @return void
	 */
	public function render_single_chips() {
		global $product;
		$this->render_chips( $this->get_colours( $product, true ), 'chips', __( 'Available colours', 'blank-brand-theme' ), true );
	}

	/**
	 * Category grid: chips under each card.
	 *
	 * @return void
	 */
	public function render_loop_chips() {
		global $product;
		$this->render_chips( $this->get_colours( $product, false ), 'loop', __( 'Available colours', 'blank-brand-theme' ) );
	}
}
