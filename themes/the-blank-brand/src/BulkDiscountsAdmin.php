<?php
/**
 * Bulk Discounts — admin side.
 *
 * Adds a "Bulk Discounts" rich text metabox to the product screen, stored as
 * `_tbb_bulk_discounts` post meta. Rendered on the front end by
 * {@see BulkDiscounts}.
 *
 * @package BlankBrandTheme
 */

namespace BlankBrandTheme;

use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * Bulk Discounts admin module.
 *
 * @package BlankBrandTheme
 */
class BulkDiscountsAdmin implements ModuleInterface {

	use Module;

	const META  = '_tbb_bulk_discounts';
	const NONCE = 'tbb_bulk_discounts_nonce';

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
		add_action( 'add_meta_boxes', [ $this, 'add_metabox' ] );
		add_action( 'save_post_product', [ $this, 'save_metabox' ], 20 );
	}

	/**
	 * Register the metabox.
	 *
	 * @return void
	 */
	public function add_metabox() {
		add_meta_box(
			'tbb-bulk-discounts',
			__( 'Bulk Discounts', 'blank-brand-theme' ),
			[ $this, 'render_metabox' ],
			'product',
			'normal',
			'default'
		);
	}

	/**
	 * Render the metabox: a WYSIWYG editor for the bulk discounts content.
	 *
	 * @param \WP_Post $post The product post.
	 * @return void
	 */
	public function render_metabox( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );

		$content = get_post_meta( $post->ID, self::META, true );

		wp_editor(
			(string) $content,
			'tbb_bulk_discounts',
			[
				'textarea_name' => 'tbb_bulk_discounts',
				'textarea_rows' => 10,
				'media_buttons' => false,
			]
		);

		echo '<p class="description">' . esc_html__( 'Shown on the single product page in an accordion under the description, labelled "See Bulk Discounts". Left empty, the accordion is not shown.', 'blank-brand-theme' ) . '</p>';
	}

	/**
	 * Persist the bulk discounts content.
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

		if ( ! isset( $_POST['tbb_bulk_discounts'] ) ) {
			return;
		}

		$content = wp_kses_post( wp_unslash( $_POST['tbb_bulk_discounts'] ) );

		if ( '' !== trim( $content ) ) {
			update_post_meta( $post_id, self::META, $content );
		} else {
			delete_post_meta( $post_id, self::META );
		}
	}
}
