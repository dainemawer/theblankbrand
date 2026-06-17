<?php
/**
 * Colour Swatches — admin side.
 *
 * Defines the editable relationship between product-gallery images and colour
 * terms, plus the hex value used for the swatch chip:
 *
 *   - `colour_hex` term meta on each `pa_colour` term (the chip colour).
 *   - `_tbb_colour_images` product meta: term_id => [ordered attachment ids],
 *     edited via a "Colour images" metabox on the product screen.
 *
 * Saving a product derives each variation's `_thumbnail_id` from the map so the
 * Barn2 bulk-variations grid shows the correct per-colour image.
 *
 * @package BlankBrandTheme
 */

namespace BlankBrandTheme;

use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * Colour Swatches admin module.
 *
 * @package BlankBrandTheme
 */
class ColourSwatchesAdmin implements ModuleInterface {

	use Module;

	const IMAGES_META = '_tbb_colour_images';
	const HEX_META    = 'colour_hex';
	const SWATCH_META = '_tbb_swatch_image';
	const NONCE       = 'tbb_colour_images_nonce';

	/**
	 * Can this module be registered?
	 *
	 * @return bool
	 */
	public function can_register() {
		return is_admin() && class_exists( 'WooCommerce' );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		// Swatch image + hex fields on pa_colour terms.
		add_action( 'admin_enqueue_scripts', [ $this, 'term_media_assets' ] );
		add_action( 'pa_colour_add_form_fields', [ $this, 'term_add_field' ] );
		add_action( 'pa_colour_edit_form_fields', [ $this, 'term_edit_field' ] );
		add_action( 'created_pa_colour', [ $this, 'save_term_meta' ] );
		add_action( 'edited_pa_colour', [ $this, 'save_term_meta' ] );
		add_filter( 'manage_edit-pa_colour_columns', [ $this, 'term_column' ] );
		add_filter( 'manage_pa_colour_custom_column', [ $this, 'term_column_content' ], 10, 3 );

		// Colour-images metabox on products.
		add_action( 'add_meta_boxes', [ $this, 'add_metabox' ] );
		add_action( 'save_post_product', [ $this, 'save_metabox' ], 20 );
	}

	/* ---------------------------------------------- term swatch image + hex */

	/**
	 * Load the WP media picker + a small delegated uploader script on the
	 * pa_colour term-edit screen only.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public function term_media_assets( $hook ) {
		if ( 'edit-tags.php' !== $hook && 'term.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'pa_colour' !== $screen->taxonomy ) {
			return;
		}

		wp_enqueue_media();

		$js = <<<'JS'
jQuery(function($){
	$(document).on('click', '.tbb-swatch-upload', function(e){
		e.preventDefault();
		var $field = $(this).closest('.tbb-swatch-field');
		var frame = wp.media({ title: 'Select swatch image', multiple: false, library: { type: 'image' } });
		frame.on('select', function(){
			var a = frame.state().get('selection').first().toJSON();
			var url = (a.sizes && a.sizes.thumbnail) ? a.sizes.thumbnail.url : a.url;
			$field.find('.tbb-swatch-id').val(a.id);
			$field.find('.tbb-swatch-preview').attr('src', url).show();
			$field.find('.tbb-swatch-remove').show();
		});
		frame.open();
	});
	$(document).on('click', '.tbb-swatch-remove', function(e){
		e.preventDefault();
		var $field = $(this).closest('.tbb-swatch-field');
		$field.find('.tbb-swatch-id').val('');
		$field.find('.tbb-swatch-preview').hide();
		$(this).hide();
	});
	// Reset the add-term form after a term is created via AJAX.
	$(document).ajaxComplete(function(e, x, s){
		if (s && s.data && s.data.indexOf('action=add-tag') !== -1) {
			$('.tbb-swatch-field .tbb-swatch-id').val('');
			$('.tbb-swatch-field .tbb-swatch-preview').hide();
			$('.tbb-swatch-field .tbb-swatch-remove').hide();
		}
	});
});
JS;
		wp_add_inline_script( 'jquery-core', $js );
	}

	/**
	 * Markup for the swatch-image picker (shared by add/edit screens).
	 *
	 * @param int $img_id Current attachment id.
	 * @return void
	 */
	protected function swatch_image_control( $img_id ) {
		$url = $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '';
		?>
		<div class="tbb-swatch-field">
			<input type="hidden" class="tbb-swatch-id" name="tbb_swatch_image" value="<?php echo esc_attr( (int) $img_id ); ?>" />
			<img class="tbb-swatch-preview" src="<?php echo esc_url( $url ); ?>" alt="" style="display:<?php echo $url ? 'inline-block' : 'none'; ?>;width:48px;height:48px;object-fit:cover;border:1px solid #ccc;border-radius:4px;vertical-align:middle;margin-right:8px;" />
			<button type="button" class="button tbb-swatch-upload"><?php esc_html_e( 'Select image', 'blank-brand-theme' ); ?></button>
			<button type="button" class="button tbb-swatch-remove" style="display:<?php echo $url ? 'inline-block' : 'none'; ?>;"><?php esc_html_e( 'Remove', 'blank-brand-theme' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Fields on the "add term" screen.
	 *
	 * @return void
	 */
	public function term_add_field() {
		?>
		<div class="form-field">
			<label><?php esc_html_e( 'Swatch image', 'blank-brand-theme' ); ?></label>
			<?php $this->swatch_image_control( 0 ); ?>
			<p><?php esc_html_e( 'Image shown as the colour swatch on the front end. Falls back to the hex below if empty.', 'blank-brand-theme' ); ?></p>
		</div>
		<div class="form-field">
			<label for="colour_hex"><?php esc_html_e( 'Swatch hex (fallback)', 'blank-brand-theme' ); ?></label>
			<input type="text" name="colour_hex" id="colour_hex" value="" placeholder="#000000" />
		</div>
		<?php
	}

	/**
	 * Hex field on the "edit term" screen.
	 *
	 * @param \WP_Term $term The term being edited.
	 * @return void
	 */
	public function term_edit_field( $term ) {
		$hex    = get_term_meta( $term->term_id, self::HEX_META, true );
		$img_id = (int) get_term_meta( $term->term_id, self::SWATCH_META, true );
		?>
		<tr class="form-field">
			<th scope="row"><label><?php esc_html_e( 'Swatch image', 'blank-brand-theme' ); ?></label></th>
			<td>
				<?php $this->swatch_image_control( $img_id ); ?>
				<p class="description"><?php esc_html_e( 'Image shown as the colour swatch on the front end. Falls back to the hex below if empty.', 'blank-brand-theme' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="colour_hex"><?php esc_html_e( 'Swatch hex (fallback)', 'blank-brand-theme' ); ?></label></th>
			<td>
				<input type="text" name="colour_hex" id="colour_hex" value="<?php echo esc_attr( $hex ); ?>" placeholder="#000000" />
				<?php if ( $hex ) : ?>
					<span style="display:inline-block;width:22px;height:22px;vertical-align:middle;margin-left:8px;border:1px solid #ccc;border-radius:3px;background:<?php echo esc_attr( $hex ); ?>"></span>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persist the swatch image + hex term meta.
	 *
	 * @param int $term_id The term id.
	 * @return void
	 */
	public function save_term_meta( $term_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- core term-edit nonce already verified by WP.
		if ( isset( $_POST['colour_hex'] ) ) {
			$hex = sanitize_hex_color( wp_unslash( $_POST['colour_hex'] ) );
			if ( $hex ) {
				update_term_meta( $term_id, self::HEX_META, $hex );
			} else {
				delete_term_meta( $term_id, self::HEX_META );
			}
		}

		if ( isset( $_POST['tbb_swatch_image'] ) ) {
			$img_id = (int) $_POST['tbb_swatch_image'];
			if ( $img_id && 'attachment' === get_post_type( $img_id ) ) {
				update_term_meta( $term_id, self::SWATCH_META, $img_id );
			} else {
				delete_term_meta( $term_id, self::SWATCH_META );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Add a swatch column to the pa_colour term list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function term_column( $columns ) {
		$columns['colour_hex'] = __( 'Swatch', 'blank-brand-theme' );
		return $columns;
	}

	/**
	 * Render the swatch column.
	 *
	 * @param string $content Column content.
	 * @param string $column  Column key.
	 * @param int    $term_id Term id.
	 * @return string
	 */
	public function term_column_content( $content, $column, $term_id ) {
		if ( 'colour_hex' !== $column ) {
			return $content;
		}
		$img_id = (int) get_term_meta( $term_id, self::SWATCH_META, true );
		if ( $img_id && 'attachment' === get_post_type( $img_id ) ) {
			$url = wp_get_attachment_image_url( $img_id, 'thumbnail' );
			return '<img src="' . esc_url( $url ) . '" style="width:30px;height:30px;object-fit:cover;border:1px solid #ccc;border-radius:3px;" alt="" />';
		}
		$hex = get_term_meta( $term_id, self::HEX_META, true );
		if ( ! $hex ) {
			return '—';
		}
		return '<span style="display:inline-block;width:22px;height:22px;border:1px solid #ccc;border-radius:3px;background:' . esc_attr( $hex ) . '" title="' . esc_attr( $hex ) . '"></span>';
	}

	/* ----------------------------------------------------------- colour images */

	/**
	 * Register the metabox.
	 *
	 * @return void
	 */
	public function add_metabox() {
		add_meta_box(
			'tbb-colour-images',
			__( 'Colour images', 'blank-brand-theme' ),
			[ $this, 'render_metabox' ],
			'product',
			'normal',
			'default'
		);
	}

	/**
	 * Ordered list of this product's image ids (featured first, then gallery).
	 *
	 * @param int $product_id Product id.
	 * @return int[]
	 */
	protected function product_image_ids( $product_id ) {
		$ids = [];
		$feat = (int) get_post_thumbnail_id( $product_id );
		if ( $feat ) {
			$ids[] = $feat;
		}
		$gallery = get_post_meta( $product_id, '_product_image_gallery', true );
		foreach ( array_filter( explode( ',', (string) $gallery ) ) as $gid ) {
			$gid = (int) $gid;
			if ( $gid && ! in_array( $gid, $ids, true ) ) {
				$ids[] = $gid;
			}
		}
		return $ids;
	}

	/**
	 * Render the metabox: each image + a colour dropdown.
	 *
	 * @param \WP_Post $post The product post.
	 * @return void
	 */
	public function render_metabox( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );

		$image_ids = $this->product_image_ids( $post->ID );
		$terms     = wc_get_product_terms( $post->ID, 'pa_colour', [ 'fields' => 'all' ] );

		if ( ! $terms ) {
			echo '<p>' . esc_html__( 'Add colour attributes to this product first.', 'blank-brand-theme' ) . '</p>';
			return;
		}
		if ( ! $image_ids ) {
			echo '<p>' . esc_html__( 'Upload images to the product gallery, save, then assign each one a colour here.', 'blank-brand-theme' ) . '</p>';
			return;
		}

		// Reverse map: attachment id => term id.
		$map     = get_post_meta( $post->ID, self::IMAGES_META, true );
		$reverse = [];
		if ( is_array( $map ) ) {
			foreach ( $map as $tid => $ids ) {
				foreach ( (array) $ids as $aid ) {
					$reverse[ (int) $aid ] = (int) $tid;
				}
			}
		}

		echo '<p class="description">' . esc_html__( 'Assign each gallery image to a colour. Order within a colour follows the gallery; the first image is that colour\'s main image.', 'blank-brand-theme' ) . '</p>';
		echo '<div style="display:flex;flex-wrap:wrap;gap:16px;">';
		foreach ( $image_ids as $aid ) {
			$selected = $reverse[ $aid ] ?? 0;
			echo '<div style="width:120px;text-align:center;">';
			echo wp_get_attachment_image( $aid, [ 110, 110 ], false, [ 'style' => 'width:110px;height:110px;object-fit:cover;border:1px solid #ddd;border-radius:4px;' ] );
			echo '<select name="tbb_colour_image[' . esc_attr( $aid ) . ']" style="width:110px;margin-top:6px;">';
			echo '<option value="0">' . esc_html__( '— none —', 'blank-brand-theme' ) . '</option>';
			foreach ( $terms as $term ) {
				printf(
					'<option value="%d" %s>%s</option>',
					(int) $term->term_id,
					selected( $selected, $term->term_id, false ),
					esc_html( $term->name )
				);
			}
			echo '</select>';
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Save the colour-image map and derive variation images.
	 *
	 * @param int $post_id Product id.
	 * @return void
	 */
	public function save_metabox( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE ] ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$posted = isset( $_POST['tbb_colour_image'] ) ? (array) wp_unslash( $_POST['tbb_colour_image'] ) : [];

		// Build term_id => [ids] preserving the product's image order.
		$ordered = $this->product_image_ids( $post_id );
		$map     = [];
		foreach ( $ordered as $aid ) {
			$tid = isset( $posted[ $aid ] ) ? (int) $posted[ $aid ] : 0;
			if ( $tid > 0 ) {
				$map[ $tid ][] = (int) $aid;
			}
		}

		if ( $map ) {
			update_post_meta( $post_id, self::IMAGES_META, $map );
		} else {
			delete_post_meta( $post_id, self::IMAGES_META );
		}

		// Derive each variation's image from the colour's main image.
		$product = wc_get_product( $post_id );
		if ( $product && $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $vid ) {
				$slug = get_post_meta( $vid, 'attribute_pa_colour', true );
				if ( ! $slug ) {
					continue;
				}
				$term = get_term_by( 'slug', $slug, 'pa_colour' );
				if ( $term && ! empty( $map[ $term->term_id ] ) ) {
					update_post_meta( $vid, '_thumbnail_id', (int) $map[ $term->term_id ][0] );
				}
			}
		}

		wc_delete_product_transients( $post_id );
	}
}
