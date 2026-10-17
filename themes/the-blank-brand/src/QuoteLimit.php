<?php
/**
 * Quote limit: 100 or more of one style is quoted, not sold online.
 *
 * The bulk discount table tells customers they can get a quote for 100 or more.
 * Nothing stopped them putting 100, or 999,000, in the cart and checking out
 * instead. This stops it, in every place a quantity can come from:
 *
 * - the add to cart button, and the quantity box on the product page and cart;
 * - the Bulk Order Grid, which posts a whole batch and then adds it line by line,
 *   so a batch that crosses the limit is refused whole rather than half added;
 * - the block cart and checkout, through the Store API;
 * - the cart itself, so a cart that got past the rest cannot check out.
 *
 * The limit is per style (the parent product), counting every colour and size
 * together plus whatever is already in the cart. Where it applies comes from the
 * product's own `quote_from` tier setting, so a product without one is untouched.
 *
 * This is a plain class started by BulkPricing rather than a module of its own:
 * on production the framework caches its list of modules and does not notice a
 * new one until the cache file is deleted.
 *
 * @package BlankBrandTheme
 */

namespace BlankBrandTheme;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;

/**
 * Keeps orders of 100 or more of one style off the web checkout.
 */
class QuoteLimit {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Add to cart, the product page quantity box, and cart quantity changes.
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_add' ], 20, 4 );
		add_filter( 'woocommerce_update_cart_validation', [ $this, 'validate_update' ], 20, 4 );
		add_filter( 'woocommerce_quantity_input_args', [ $this, 'limit_quantity_input' ], 20, 2 );
		add_filter( 'woocommerce_available_variation', [ $this, 'limit_variation' ], 20, 3 );

		// The Bulk Order Grid posts every cell at once. Look at the batch before its
		// own handler (priority 20) starts adding lines.
		add_action( 'template_redirect', [ $this, 'block_bulk_batch' ], 10 );

		// A cart that is somehow over the limit cannot check out.
		add_action( 'woocommerce_check_cart_items', [ $this, 'check_cart' ] );

		// Block cart and checkout.
		add_action( 'woocommerce_store_api_validate_add_to_cart', [ $this, 'store_api_validate_add' ], 10, 2 );
		add_action( 'woocommerce_store_api_cart_errors', [ $this, 'store_api_cart_errors' ], 10, 2 );
		add_filter( 'woocommerce_store_api_product_quantity_maximum', [ $this, 'store_api_maximum' ], 20, 3 );

		add_action( 'wp_enqueue_scripts', [ $this, 'assets' ] );
	}

	/**
	 * The quantity at which a product is quoted rather than sold, or 0 for none.
	 *
	 * @param int $product_id Parent product id.
	 * @return int
	 */
	public static function quote_from( int $product_id ): int {
		$data = get_post_meta( $product_id, BulkDiscounts::META, true );

		return is_array( $data ) ? max( 0, (int) ( $data['quote_from'] ?? 0 ) ) : 0;
	}

	/**
	 * The message customers see, as a sentence with a link or as plain text.
	 *
	 * @param int  $from Quantity at which a quote is needed.
	 * @param bool $html Whether to link the address.
	 * @return string
	 */
	public static function message( int $from, bool $html = true ): string {
		$email = BulkDiscounts::QUOTE_EMAIL;
		$most  = $from - 1;

		if ( $html ) {
			return sprintf(
				/* translators: 1: quantity needing a quote, 2: mailto link text, 3: most that can be ordered online. */
				__( 'For %1$d or more of one style, please %2$s. You can order up to %3$d online.', 'blank-brand-theme' ),
				$from,
				sprintf( '<a href="%s">%s</a>', esc_url( 'mailto:' . $email ), esc_html__( 'get a quote', 'blank-brand-theme' ) ),
				$most
			);
		}

		return sprintf(
			/* translators: 1: quantity needing a quote, 2: email address, 3: most that can be ordered online. */
			__( 'For %1$d or more of one style, email %2$s for a quote. You can order up to %3$d online.', 'blank-brand-theme' ),
			$from,
			$email,
			$most
		);
	}

	/**
	 * The message for a notice, in the form that survives where it is shown.
	 *
	 * Classic pages print a notice as HTML, so the address can be a link. The block
	 * cart and block buttons go through the REST API, which strips the markup from a
	 * notice, and the customer would read "get a quote" with no address to write to.
	 * There the address is spelled out.
	 *
	 * @param int $from Quantity at which a quote is needed.
	 * @return string
	 */
	protected function notice_message( int $from ): string {
		return self::message( $from, ! WC()->is_rest_api_request() );
	}

	/**
	 * The parent product id for a product or a variation.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return int
	 */
	protected function parent_id( \WC_Product $product ): int {
		return $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
	}

	/**
	 * Units of one style already in the cart, across all its variations.
	 *
	 * @param int    $product_id Parent product id.
	 * @param string $except_key Cart line to leave out (the line being edited).
	 * @return int
	 */
	protected function in_cart( int $product_id, string $except_key = '' ): int {
		if ( ! WC()->cart ) {
			return 0;
		}

		$total = 0;

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( (string) $key === $except_key ) {
				continue;
			}

			if ( (int) ( $item['product_id'] ?? 0 ) === $product_id ) {
				$total += (int) ( $item['quantity'] ?? 0 );
			}
		}

		return $total;
	}

	/**
	 * How many more units can be added before the style needs a quote.
	 *
	 * @param int    $product_id Parent product id.
	 * @param string $except_key Cart line to leave out.
	 * @return int|null Null when the product has no limit.
	 */
	protected function allowance( int $product_id, string $except_key = '' ): ?int {
		$from = self::quote_from( $product_id );

		return $from ? max( 0, $from - 1 - $this->in_cart( $product_id, $except_key ) ) : null;
	}

	/**
	 * Refuse an add to cart that would take a style to the limit.
	 *
	 * @param bool $passed     Whether earlier checks passed.
	 * @param int  $product_id Parent product id.
	 * @param int  $quantity   Quantity being added.
	 * @return bool
	 */
	public function validate_add( $passed, $product_id, $quantity ) {
		$product_id = (int) $product_id;
		$from       = $passed ? self::quote_from( $product_id ) : 0;

		if ( $from && $this->in_cart( $product_id ) + (int) $quantity >= $from ) {
			wc_add_notice( $this->notice_message( $from ), 'error' );
			return false;
		}

		return $passed;
	}

	/**
	 * Refuse a cart quantity change that would take a style to the limit.
	 *
	 * @param bool   $passed        Whether earlier checks passed.
	 * @param string $cart_item_key Cart line being changed.
	 * @param array  $values        The cart line.
	 * @param int    $quantity      New quantity.
	 * @return bool
	 */
	public function validate_update( $passed, $cart_item_key, $values, $quantity ) {
		$product_id = (int) ( $values['product_id'] ?? 0 );
		$from       = $passed ? self::quote_from( $product_id ) : 0;

		if ( $from && $this->in_cart( $product_id, (string) $cart_item_key ) + (int) $quantity >= $from ) {
			wc_add_notice( $this->notice_message( $from ), 'error' );
			return false;
		}

		return $passed;
	}

	/**
	 * Cap the quantity box at what can still be ordered online.
	 *
	 * @param array       $args    Quantity input arguments.
	 * @param \WC_Product $product Product the box belongs to.
	 * @return array
	 */
	public function limit_quantity_input( $args, $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return $args;
		}

		// The Store API also asks this filter for a line's limits, but without
		// saying which line. Counting the line against itself then cut a cart of
		// 60 down to 39 the moment the block cart loaded. It has its own filter
		// that does know the line (store_api_maximum), so leave it to that.
		if ( WC()->is_rest_api_request() ) {
			return $args;
		}

		// On the cart page the input is named cart[<key>][qty]; leave that line out
		// of the count so a line can be edited up to the limit on its own.
		$except = '';
		if ( isset( $args['input_name'] ) && preg_match( '/^cart\[([^\]]+)\]\[qty\]$/', (string) $args['input_name'], $match ) ) {
			$except = $match[1];
		}

		$allowance = $this->allowance( $this->parent_id( $product ), $except );

		if ( null === $allowance ) {
			return $args;
		}

		$cap = max( 1, $allowance );
		$max = isset( $args['max_value'] ) ? (float) $args['max_value'] : -1;

		$args['max_value'] = $max > 0 ? min( $max, $cap ) : $cap;

		return $args;
	}

	/**
	 * Cap the quantity a selected variation allows, which replaces the box's own
	 * maximum when a colour and size are chosen.
	 *
	 * @param array                 $data      Variation data sent to the page.
	 * @param \WC_Product           $product   Variable product.
	 * @param \WC_Product_Variation $variation The variation.
	 * @return array
	 */
	public function limit_variation( $data, $product, $variation ) {
		if ( ! $variation instanceof \WC_Product ) {
			return $data;
		}

		$allowance = $this->allowance( $this->parent_id( $variation ) );

		if ( null === $allowance ) {
			return $data;
		}

		$cap     = max( 1, $allowance );
		$current = isset( $data['max_qty'] ) && '' !== $data['max_qty'] ? (int) $data['max_qty'] : 0;

		$data['max_qty'] = $current > 0 ? min( $current, $cap ) : $cap;

		return $data;
	}

	/**
	 * Refuse a Bulk Order Grid batch that would take a style to the limit.
	 *
	 * The grid's handler adds one variation at a time and does not undo the ones
	 * already added when a later one fails, so a batch of 60 and 50 would leave
	 * 60 in the cart. Removing the posted quantities here leaves it nothing to do.
	 *
	 * @return void
	 */
	public function block_bulk_batch(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		// Read only: the grid's own handler owns the form. Values are cast to integers below.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['quantity'] ) || ! is_array( $_POST['quantity'] ) ) {
			return;
		}

		$groups = [];

		foreach ( $_POST['quantity'] as $variation_id => $quantity ) {
			$variation = wc_get_product( absint( $variation_id ) );

			if ( $variation ) {
				$group            = $this->parent_id( $variation );
				$groups[ $group ] = ( $groups[ $group ] ?? 0 ) + absint( $quantity );
			}
		}

		foreach ( $groups as $product_id => $added ) {
			$from = self::quote_from( $product_id );

			if ( $from && $this->in_cart( $product_id ) + $added >= $from ) {
				wc_add_notice( $this->notice_message( $from ), 'error' );
				unset( $_POST['quantity'] );
				return;
			}
		}
		// phpcs:enable
	}

	/**
	 * Styles in the cart at or over the limit.
	 *
	 * @return array<int,array{quantity:int,from:int,name:string}> Keyed by parent product id.
	 */
	protected function over_limit(): array {
		$over = [];

		if ( ! WC()->cart ) {
			return $over;
		}

		$quantities = [];
		$names      = [];

		foreach ( WC()->cart->get_cart() as $item ) {
			$product_id = (int) ( $item['product_id'] ?? 0 );

			if ( ! $product_id ) {
				continue;
			}

			$quantities[ $product_id ] = ( $quantities[ $product_id ] ?? 0 ) + (int) ( $item['quantity'] ?? 0 );
			$names[ $product_id ]      = get_the_title( $product_id );
		}

		foreach ( $quantities as $product_id => $quantity ) {
			$from = self::quote_from( $product_id );

			if ( $from && $quantity >= $from ) {
				$over[ $product_id ] = [
					'quantity' => $quantity,
					'from'     => $from,
					'name'     => html_entity_decode( $names[ $product_id ] ),
				];
			}
		}

		return $over;
	}

	/**
	 * Stop checkout for a cart that is over the limit (classic cart and checkout).
	 *
	 * @return void
	 */
	public function check_cart(): void {
		foreach ( $this->over_limit() as $info ) {
			wc_add_notice(
				sprintf(
					/* translators: 1: product name, 2: quantity in the cart. */
					__( 'You have %2$d of %1$s in your cart. ', 'blank-brand-theme' ),
					$info['name'],
					$info['quantity']
				) . $this->notice_message( $info['from'] ),
				'error'
			);
		}
	}

	/**
	 * Refuse a Store API add to cart that would take a style to the limit.
	 *
	 * @param \WC_Product      $product Product being added.
	 * @param \WP_REST_Request $request The request.
	 * @return void
	 * @throws RouteException When the add would reach the limit.
	 */
	public function store_api_validate_add( $product, $request ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$product_id = $this->parent_id( $product );
		$from       = self::quote_from( $product_id );
		$quantity   = (int) ( $request['quantity'] ?? 1 );

		if ( $from && $this->in_cart( $product_id ) + $quantity >= $from ) {
			throw new RouteException( 'tbb_quote_required', esc_html( self::message( $from, false ) ), 400 );
		}
	}

	/**
	 * Report an over-limit cart to the block cart and checkout.
	 *
	 * @param \WP_Error $errors Errors collected so far.
	 * @return void
	 */
	public function store_api_cart_errors( $errors ): void {
		if ( ! $errors instanceof \WP_Error ) {
			return;
		}

		foreach ( $this->over_limit() as $info ) {
			$errors->add(
				'tbb_quote_required',
				sprintf(
					/* translators: 1: product name, 2: quantity in the cart. */
					__( 'You have %2$d of %1$s in your cart. ', 'blank-brand-theme' ),
					$info['name'],
					$info['quantity']
				) . self::message( $info['from'], false )
			);
		}
	}

	/**
	 * The cart key for a cart line as the Store API hands it over.
	 *
	 * The line does not always carry its own `key` (it is missing when the cart
	 * is normalised), and without it the line is counted against itself and gets
	 * cut down to what is left after its own quantity. So fall back to finding the
	 * line by product and variation.
	 *
	 * @param array $cart_item Cart line.
	 * @return string Empty when the line is not in the cart yet.
	 */
	protected function line_key( array $cart_item ): string {
		if ( ! empty( $cart_item['key'] ) ) {
			return (string) $cart_item['key'];
		}

		if ( ! WC()->cart ) {
			return '';
		}

		foreach ( WC()->cart->get_cart() as $key => $line ) {
			if (
				(int) ( $line['product_id'] ?? 0 ) === (int) ( $cart_item['product_id'] ?? -1 )
				&& (int) ( $line['variation_id'] ?? 0 ) === (int) ( $cart_item['variation_id'] ?? 0 )
			) {
				return (string) $key;
			}
		}

		return '';
	}

	/**
	 * Cap a block cart line's quantity box at what can still be ordered online.
	 *
	 * @param int         $quantity  Current maximum.
	 * @param \WC_Product $product   Product.
	 * @param array|null  $cart_item The cart line, when there is one.
	 * @return int
	 */
	public function store_api_maximum( $quantity, $product, $cart_item = null ) {
		if ( ! $product instanceof \WC_Product ) {
			return $quantity;
		}

		$except    = is_array( $cart_item ) ? $this->line_key( $cart_item ) : '';
		$allowance = $this->allowance( $this->parent_id( $product ), $except );

		if ( null === $allowance ) {
			return $quantity;
		}

		$cap = max( 1, $allowance );

		return $quantity > 0 ? min( (int) $quantity, $cap ) : $cap;
	}

	/**
	 * Load the product page script that explains the limit as a number is typed.
	 *
	 * @return void
	 */
	public function assets(): void {
		if ( ! is_product() ) {
			return;
		}

		$product = wc_get_product( get_queried_object_id() );
		$from    = $product ? self::quote_from( $product->get_id() ) : 0;

		if ( ! $from ) {
			return;
		}

		$file = get_theme_file_path( 'assets/js/quote-limit.js' );

		wp_enqueue_script(
			'tbb-quote-limit',
			get_theme_file_uri( 'assets/js/quote-limit.js' ),
			[ 'jquery' ],
			file_exists( $file ) ? (string) filemtime( $file ) : BLANK_BRAND_THEME_VERSION,
			true
		);

		wp_add_inline_script(
			'tbb-quote-limit',
			'window.tbbQuoteLimit = ' . wp_json_encode(
				[
					'from'   => $from,
					'inCart' => $this->in_cart( $product->get_id() ),
					'html'   => self::message( $from ),
				]
			) . ';',
			'before'
		);
	}
}
