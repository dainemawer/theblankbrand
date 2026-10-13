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
		// A product with structured tiers is priced from those, and the free-text
		// field below is only the fallback for products without them. Offering
		// the editor anyway let an admin change prices that the storefront
		// ignores, with nothing to say so.
		if ( $this->has_structured_tiers( (int) $post->ID ) ) {
			$this->render_structured_tiers( (int) $post->ID );
			return;
		}

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
	 * Whether the product carries structured tiers, which the storefront prefers.
	 *
	 * @param int $post_id Product id.
	 * @return bool
	 */
	protected function has_structured_tiers( int $post_id ): bool {
		$data = get_post_meta( $post_id, BulkDiscounts::META, true );

		return is_array( $data ) && ! empty( $data['tiers'] );
	}

	/**
	 * Show the structured tiers read-only, and say where each figure comes from.
	 *
	 * @param int $post_id Product id.
	 * @return void
	 */
	protected function render_structured_tiers( int $post_id ): void {
		$data = get_post_meta( $post_id, BulkDiscounts::META, true );
		?>
		<p class="description">
			<?php esc_html_e( 'These tiers are managed for you and cannot be edited here. The first price is the product\'s own price, and the 50+ price is synced from Zoho. To change them, edit the product price, or the item rate in Zoho.', 'blank-brand-theme' ); ?>
		</p>
		<table class="widefat striped" style="max-width:32rem">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Quantity', 'blank-brand-theme' ); ?></th>
					<th><?php esc_html_e( 'Price', 'blank-brand-theme' ); ?></th>
					<th><?php esc_html_e( 'Source', 'blank-brand-theme' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_values( $data['tiers'] ) as $index => $tier ) : ?>
					<tr>
						<td>
							<?php
							echo esc_html(
								null === ( $tier['max'] ?? null )
									? sprintf( '%d+', (int) ( $tier['min'] ?? 0 ) )
									: sprintf( '%d-%d', (int) ( $tier['min'] ?? 0 ), (int) $tier['max'] )
							);
							?>
						</td>
						<td>
							<?php
							echo 0 === $index
								? esc_html__( 'The product price', 'blank-brand-theme' )
								: wp_kses_post( wc_price( (float) ( $tier['price'] ?? 0 ) ) );
							?>
						</td>
						<td>
							<?php
							echo esc_html(
								0 === $index
									? __( 'Product', 'blank-brand-theme' )
									: ( false !== strpos( (string) ( $tier['source'] ?? '' ), 'zoho' ) ? __( 'Zoho', 'blank-brand-theme' ) : __( 'Stored', 'blank-brand-theme' ) )
							);
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
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

		// The storefront does not read this field once structured tiers exist.
		if ( $this->has_structured_tiers( (int) $post_id ) ) {
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
