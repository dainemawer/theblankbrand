<?php
/**
 * 100 or more of one style is a quote, not an online order.
 *
 *   wp eval-file wp-content/themes/the-blank-brand/tests/quote-limit-test.php
 *
 * Drives a real cart. It covers every way a quantity can arrive: a plain add to
 * cart, an add split across colours and sizes, the Bulk Order Grid's whole-batch
 * post, the quantity boxes, the checkout check, and the Store API behind the block
 * cart and checkout.
 *
 * It raises the fixture variations' stock for the run and puts it back. For a
 * local site: it will not run elsewhere.
 *
 * @package BlankBrandTheme
 */

// These are command-line scripts, run with `wp eval-file`. They print to a terminal
// rather than render a page, so the rules written for web output do not apply.
// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.ValidVariableName, WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery, WordPress.PHP.DevelopmentFunctions, WordPress.WP.AlternativeFunctions, WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput, Universal.Operators.DisallowShortTernary

if ( ! defined( 'ABSPATH' ) ) { exit; }

use BlankBrandTheme\QuoteLimit;

if ( 'local' !== wp_get_environment_type() && ! defined( 'TBB_ALLOW_TESTS' ) ) {
	echo "REFUSING TO RUN: this changes stock for the run and is for a local site.\n";
	return;
}

$pass  = 0;
$fail  = 0;
$fails = [];

/**
 * Compare two values and print one line.
 *
 * @param string $label    What is being checked.
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 */
$is  = function ( string $label, $expected, $actual ) use ( &$pass, &$fail, &$fails ) {
	if ( $expected === $actual || ( is_numeric( $expected ) && is_numeric( $actual ) && abs( (float) $expected - (float) $actual ) < 0.001 ) ) {
		++$pass;
		printf( "  PASS  %s\n", $label );
		return;
	}
	++$fail;
	$fails[] = $label;
	printf( "  FAIL  %s: expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
};
$yes = function ( string $label, bool $condition ) use ( $is ) {
	$is( $label, true, $condition );
};

wc_load_cart();

$page    = get_page_by_path( 'womens-boxy-tee', OBJECT, 'product' );
$parent  = $page ? (int) $page->ID : 0;
$product = $parent ? wc_get_product( $parent ) : null;
$vars    = $product ? array_values( $product->get_children() ) : [];
if ( count( $vars ) < 2 ) {
	echo "FIXTURE MISSING: womens-boxy-tee with two variations\n";
	return;
}

// 100 units of a variation need stock; real stock moves with every Zoho sync.
$restore = [];
foreach ( array_slice( $vars, 0, 3 ) as $vid ) {
	$v = wc_get_product( $vid );
	if ( ! $v ) { continue; }
	$restore[ $vid ] = [ $v->get_manage_stock(), $v->get_stock_quantity(), $v->get_stock_status() ];
	$v->set_manage_stock( true );
	$v->set_stock_quantity( 500 );
	$v->set_stock_status( 'instock' );
	$v->save();
}
$put_back = static function () use ( &$restore ) {
	foreach ( $restore as $vid => $was ) {
		$v = wc_get_product( $vid );
		if ( ! $v ) { continue; }
		$v->set_manage_stock( (bool) $was[0] );
		$v->set_stock_quantity( $was[1] );
		$v->set_stock_status( $was[2] );
		$v->save();
	}
	$restore = [];
};
register_shutdown_function( $put_back );

$units  = static function () {
	return (int) array_sum( array_map( static fn( $i ) => (int) $i['quantity'], WC()->cart->get_cart() ) );
};
$errors = static function () {
	return implode( ' | ', array_map( static fn( $n ) => wp_strip_all_tags( $n['notice'] ), wc_get_notices( 'error' ) ) );
};
$fresh  = static function () {
	WC()->cart->empty_cart();
	wc_clear_notices();
};

/**
 * Add the way WooCommerce's form and AJAX handlers do: run the add to cart
 * validation first, then add. WC_Cart::add_to_cart() does not validate by
 * itself, so calling it directly would skip the check under test.
 */
$add = static function ( int $product_id, int $quantity, int $variation_id ) {
	$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity, $variation_id, [] );

	return $passed ? WC()->cart->add_to_cart( $product_id, $quantity, $variation_id ) : false;
};

printf( "Fixture: %s (parent %d), quote from %d\n", html_entity_decode( $product->get_name() ), $parent, QuoteLimit::quote_from( $parent ) );
$from = QuoteLimit::quote_from( $parent );
$is( 'the fixture product has a quote threshold', 100, $from );

// ---------------------------------------------------------------- add to cart

echo "\nAdd to cart\n";
$fresh();
$is( '99 of one variation is allowed', true, false !== $add( $parent, 99, $vars[0] ) );
$is( '  and they are in the cart', 99, $units() );

$fresh();
$result = $add( $parent, 100, $vars[0] );
$is( '100 of one variation is refused', false, $result );
$is( '  and nothing is in the cart', 0, $units() );
$yes( '  and the customer is told to get a quote', false !== strpos( $errors(), 'get a quote' ) );
$yes( '  and the notice links to the quote address', false !== strpos( wc_get_notices( 'error' )[0]['notice'] ?? '', 'mailto:info@theblankbrand.co.za' ) );

$fresh();
$is( '999000 is refused', false, $add( $parent, 999000, $vars[0] ) );

echo "\nAcross colours and sizes (the limit is per style)\n";
$fresh();
$add( $parent, 60, $vars[0] );
wc_clear_notices();
$is( '60 plus 40 of another variation is refused', false, $add( $parent, 40, $vars[1] ) );
$is( '  and only the 60 is in the cart', 60, $units() );
$yes( '  with a message', false !== strpos( $errors(), 'get a quote' ) );
$fresh();
$add( $parent, 60, $vars[0] );
$is( '60 plus 39 of another variation is allowed', true, false !== $add( $parent, 39, $vars[1] ) );
$is( '  making 99', 99, $units() );
$is( 'and one more is then refused', false, $add( $parent, 1, $vars[1] ) );

echo "\nWooCommerce's own add to cart handler\n";
$fresh();
$variation                = wc_get_product( $vars[0] );
$_REQUEST['add-to-cart']  = $parent;
$_REQUEST['variation_id'] = $vars[0];
$_REQUEST['quantity']     = 100;
foreach ( $variation->get_attributes() as $name => $value ) {
	$_REQUEST[ 'attribute_' . $name ] = $value;
}
WC_Form_Handler::add_to_cart_action( false );
$is( 'a request to add 100 through the real form handler adds nothing', 0, $units() );
$yes( '  and shows the quote message', false !== strpos( $errors(), 'get a quote' ) );
$fresh();
$_REQUEST['quantity'] = 99;
WC_Form_Handler::add_to_cart_action( false );
$is( 'and 99 through it is added', 99, $units() );
unset( $_REQUEST['add-to-cart'], $_REQUEST['variation_id'], $_REQUEST['quantity'] );
foreach ( array_keys( $_REQUEST ) as $k ) { if ( 0 === strpos( $k, 'attribute_' ) ) { unset( $_REQUEST[ $k ] ); }
}

echo "\nCart quantity change\n";
$fresh();
$key_a = WC()->cart->add_to_cart( $parent, 60, $vars[0] );
$key_b = WC()->cart->add_to_cart( $parent, 30, $vars[1] );
wc_clear_notices();
$passed = apply_filters( 'woocommerce_update_cart_validation', true, $key_b, WC()->cart->get_cart()[ $key_b ], 45 );
$is( 'raising a line so the style reaches 105 is refused', false, $passed );
$passed = apply_filters( 'woocommerce_update_cart_validation', true, $key_b, WC()->cart->get_cart()[ $key_b ], 39 );
$is( 'raising it to make 99 is allowed', true, $passed );

// ---------------------------------------------------------------- the quantity boxes

echo "\nQuantity boxes\n";
$fresh();
$args = apply_filters(
	'woocommerce_quantity_input_args',
	[
		'max_value'  => -1,
		'input_name' => 'quantity',
	],
	$product
);
$is( 'an empty cart caps the box at 99', 99, $args['max_value'] );
$args = apply_filters(
	'woocommerce_quantity_input_args',
	[
		'max_value'  => 40,
		'input_name' => 'quantity',
	],
	$product
);
$is( 'a smaller stock limit still wins', 40, $args['max_value'] );

WC()->cart->add_to_cart( $parent, 60, $vars[0] );
$args = apply_filters(
	'woocommerce_quantity_input_args',
	[
		'max_value'  => -1,
		'input_name' => 'quantity',
	],
	$product
);
$is( 'with 60 in the cart the product page box caps at 39', 39, $args['max_value'] );
$line = array_key_first( WC()->cart->get_cart() );
$args = apply_filters(
	'woocommerce_quantity_input_args',
	[
		'max_value'  => -1,
		'input_name' => "cart[$line][qty]",
	],
	wc_get_product( $vars[0] )
);
$is( 'a cart line can be edited up to 99 on its own', 99, $args['max_value'] );

$data = apply_filters( 'woocommerce_available_variation', [ 'max_qty' => '' ], $product, wc_get_product( $vars[1] ) );
$is( 'a chosen variation is capped at what is left (39)', 39, $data['max_qty'] );
$data = apply_filters( 'woocommerce_available_variation', [ 'max_qty' => 20 ], $product, wc_get_product( $vars[1] ) );
$is( '  and a smaller stock figure still wins', 20, $data['max_qty'] );

// ---------------------------------------------------------------- Bulk Order Grid

echo "\nBulk Order Grid (whole batch)\n";
$fresh();
$limit                     = new QuoteLimit();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['quantity']         = [
	$vars[0] => '60',
	$vars[1] => '50',
];
$limit->block_bulk_batch();
$is( 'a batch of 60 and 50 is removed before anything is added', false, isset( $_POST['quantity'] ) );
$is( '  so the cart stays empty', 0, $units() );
$yes( '  and the customer is told', false !== strpos( $errors(), 'get a quote' ) );

wc_clear_notices();
$_POST['quantity'] = [
	$vars[0] => '60',
	$vars[1] => '39',
];
$limit->block_bulk_batch();
$is( 'a batch of 60 and 39 is left alone', true, isset( $_POST['quantity'] ) );
$is( '  with no error', '', $errors() );

$fresh();
WC()->cart->add_to_cart( $parent, 50, $vars[0] );
wc_clear_notices();
$_POST['quantity'] = [ $vars[1] => '50' ];
$limit->block_bulk_batch();
$is( 'a batch is counted together with what is already in the cart', false, isset( $_POST['quantity'] ) );
unset( $_POST['quantity'], $_SERVER['REQUEST_METHOD'] );

// ---------------------------------------------------------------- checkout

echo "\nA cart that got past everything else\n";
$fresh();
$key = WC()->cart->add_to_cart( $parent, 50, $vars[0] );
WC()->cart->set_quantity( $key, 120, false ); // Bypasses validation, as a stale session could.
wc_clear_notices();
do_action( 'woocommerce_check_cart_items' );
$yes( 'the classic cart and checkout report it', false !== strpos( $errors(), 'You have 120' ) );

$wp_errors = new WP_Error();
do_action( 'woocommerce_store_api_cart_errors', $wp_errors, WC()->cart );
$is( 'the block cart and checkout report it', true, $wp_errors->has_errors() && in_array( 'tbb_quote_required', $wp_errors->get_error_codes(), true ) );

$fresh();
WC()->cart->add_to_cart( $parent, 99, $vars[0] );
$wp_errors = new WP_Error();
do_action( 'woocommerce_store_api_cart_errors', $wp_errors, WC()->cart );
$is( '99 in the cart is fine for the block checkout', false, $wp_errors->has_errors() );
wc_clear_notices();
do_action( 'woocommerce_check_cart_items' );
$is( '  and for the classic one', '', $errors() );

// ---------------------------------------------------------------- Store API

echo "\nStore API (block cart)\n";
$fresh();
$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
$request->set_param( 'quantity', 100 );
$thrown = null;
try {
	do_action( 'woocommerce_store_api_validate_add_to_cart', wc_get_product( $vars[0] ), $request );
} catch ( \Throwable $e ) {
	$thrown = $e;
}
$is( 'adding 100 through the Store API is refused', true, $thrown instanceof \Automattic\WooCommerce\StoreApi\Exceptions\RouteException );
$is( '  with its own error code', 'tbb_quote_required', $thrown && method_exists( $thrown, 'getErrorCode' ) ? $thrown->getErrorCode() : '' );
$yes( '  and a message that names the quote address', $thrown && false !== strpos( $thrown->getMessage(), 'info@theblankbrand.co.za' ) );

$request->set_param( 'quantity', 99 );
$thrown = null;
try {
	do_action( 'woocommerce_store_api_validate_add_to_cart', wc_get_product( $vars[0] ), $request );
} catch ( \Throwable $e ) {
	$thrown = $e;
}
$is( 'adding 99 is allowed', null, $thrown );

$fresh();
$ka  = WC()->cart->add_to_cart( $parent, 60, $vars[0] );
$kb  = WC()->cart->add_to_cart( $parent, 30, $vars[1] );
$max = apply_filters( 'woocommerce_store_api_product_quantity_maximum', 9999, wc_get_product( $vars[1] ), [ 'key' => $kb ] );
$is( 'a block cart line can rise to 39 (the other line holds 60)', 39, $max );

echo "\nBlock cart normalisation (it lowers any line above the maximum it is told)\n";
// WooCommerce's cart route runs every line through normalize_cart_item_quantity()
// when the cart loads, and it asks the older quantity box filter for limits too,
// without saying which line. Counting a line against itself cut 60 down to 39.
$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart';
$limits                 = new Automattic\WooCommerce\StoreApi\Utilities\QuantityLimits();
$args                   = apply_filters(
	'woocommerce_quantity_input_args',
	[
		'max_value'  => -1,
		'input_name' => 'quantity',
	],
	$product
);
$is( 'during a Store API request the older quantity filter steps aside', -1, $args['max_value'] );

$settle = static function ( array $cart ) use ( $limits, $parent, $fresh ) {
	$fresh();
	$out = [];
	foreach ( $cart as $vid => $qty ) {
		$key         = WC()->cart->add_to_cart( $parent, $qty, $vid );
		$out[ $vid ] = $key;
	}
	$result = [];
	foreach ( WC()->cart->get_cart() as $line ) {
		$result[ (int) $line['variation_id'] ] = (int) $limits->normalize_cart_item_quantity( $line['quantity'], $line );
	}
	return $result;
};

$r = $settle( [ $vars[0] => 60 ] );
$is( 'a single line of 60 is left at 60', 60, $r[ $vars[0] ] ?? 0 );
$r = $settle(
	[
		$vars[0] => 60,
		$vars[1] => 39,
	]
);
$is( '60 and 39 are both left alone (60)', 60, $r[ $vars[0] ] ?? 0 );
$is( '  (39)', 39, $r[ $vars[1] ] ?? 0 );
$r = $settle( [ $vars[0] => 99 ] );
$is( 'a single line of 99 is left at 99', 99, $r[ $vars[0] ] ?? 0 );
$r = $settle(
	[
		$vars[0] => 60,
		$vars[1] => 40,
	]
);
$yes( 'a cart that reached 100 is brought back under it (never 100 or more)', array_sum( $r ) < 100 && array_sum( $r ) > 0 );
$_SERVER['REQUEST_URI'] = '/';

// ---------------------------------------------------------------- products without a limit

echo "\nProducts with no quote threshold\n";
$is( 'a product with no tiers has no limit', 0, QuoteLimit::quote_from( 999999999 ) );
$is( '  so an add of any size is not blocked here', true, $limit->validate_add( true, 999999999, 5000 ) );

$fresh();
$put_back();

printf( "\n%d passed, %d failed\n", $pass, $fail );
if ( $fails ) {
	foreach ( $fails as $f ) {
		echo "  - $f\n";
	}
}
