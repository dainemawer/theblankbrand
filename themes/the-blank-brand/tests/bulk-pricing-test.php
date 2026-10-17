<?php
/**
 * Integration tests for {@see \BlankBrandTheme\BulkPricing}.
 *
 * Run with:  wp eval-file tests/bulk-pricing-test.php
 *
 * These drive a real WooCommerce cart rather than mocking one. The risk this
 * code carries is that the cart, the checkout, the payment amount, the order
 * record and the Zoho sales order must all agree on one number; a mocked cart
 * would prove none of that. Every assertion below reads back through
 * WooCommerce's own totals.
 *
 * Nothing is persisted — the cart is emptied between cases and at the end.
 *
 * @package BlankBrandTheme
 */

// These are command-line scripts, run with `wp eval-file`. They print to a terminal
// rather than render a page and read the database directly, so the rules written
// for web output and cached queries do not apply to them.
// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.ValidVariableName, WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery, WordPress.PHP.DevelopmentFunctions, WordPress.WP.AlternativeFunctions, WordPress.WP.PostsPerPageNoUnlimited, Universal.Operators.DisallowShortTernary, Universal.NamingConventions.NoReservedKeywordParameterNames

if ( ! defined( 'ABSPATH' ) ) { exit; }

// It raises the fixture variations' stock for the run. On a live shop that is
// stock a customer could buy for those few seconds.
if ( 'local' !== wp_get_environment_type() && ! defined( 'TBB_ALLOW_TESTS' ) ) {
	echo "REFUSING TO RUN — this temporarily rewrites stock, and is for a local site.\n";
	return;
}

$pass  = 0;
$fail  = 0;
$fails = [];

/**
 * Assert two numbers match to the cent.
 *
 * @param string $label    What is being checked.
 * @param float  $expected Expected value.
 * @param float  $actual   Actual value.
 * @return void
 */
$is = function ( string $label, $expected, $actual ) use ( &$pass, &$fail, &$fails ) {
	$ok = abs( (float) $expected - (float) $actual ) < 0.01;
	if ( $ok ) {
		++$pass;
		printf( "  PASS  %s\n", $label );
	} else {
		++$fail;
		$fails[] = $label;
		printf( "  FAIL  %s — expected %s, got %s\n", $label, $expected, $actual );
	}
};

wc_load_cart();

/**
 * Put a set of [variation_id => qty] in the cart and return its subtotal.
 *
 * @param int   $parent Parent product id.
 * @param array $lines  variation id => quantity.
 * @return array{subtotal:float,units:array<int,float>}
 */
$checkout = function ( int $parent, array $lines ) {
	WC()->cart->empty_cart();
	foreach ( $lines as $variation_id => $qty ) {
		WC()->cart->add_to_cart( $parent, $qty, $variation_id );
	}
	WC()->cart->calculate_totals();
	$units = [];
	foreach ( WC()->cart->get_cart() as $item ) {
		$units[ (int) $item['variation_id'] ] = (float) $item['data']->get_price();
	}
	return [
		'subtotal' => (float) WC()->cart->get_subtotal(),
		'units'    => $units,
	];
};

// ---------------------------------------------------------------- fixtures

$page = get_page_by_path( 'womens-boxy-tee', OBJECT, 'product' );
if ( ! $page ) { echo "FIXTURE MISSING: womens-boxy-tee\n";
	return; }
$product = wc_get_product( $page->ID );
$tiers   = get_post_meta( $page->ID, '_tbb_price_tiers', true );
$t1      = (float) $product->get_price();
$t2      = (float) $tiers['tiers'][1]['price'];
$vars    = array_values( $product->get_children() );

// The cases add up to 100 units of a variation, and real stock moves with
// every Zoho sync, so a fixture that depends on what happens to be in stock
// fails whenever the catalogue has sold down. Give the fixture variations
// plenty for the run and put back exactly what was there, even on an abort.
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

printf(
	"Fixture: %s — tier1 R%s, tier2 R%s, %d variations\n\n",
	html_entity_decode( $product->get_name() ),
	$t1,
	$t2,
	count( $vars )
);

// ---------------------------------------------------------------- cases

echo "Thresholds (single variation)\n";
$r = $checkout( $page->ID, [ $vars[0] => 1 ] );
$is( 'qty 1 charges tier 1', $t1, $r['subtotal'] );

$r = $checkout( $page->ID, [ $vars[0] => 49 ] );
$is( 'qty 49 charges tier 1', $t1 * 49, $r['subtotal'] );

$r = $checkout( $page->ID, [ $vars[0] => 50 ] );
$is( 'qty 50 crosses to tier 2', $t2 * 50, $r['subtotal'] );

$r = $checkout( $page->ID, [ $vars[0] => 51 ] );
$is( 'qty 51 stays on tier 2', $t2 * 51, $r['subtotal'] );

$r = $checkout( $page->ID, [ $vars[0] => 100 ] );
$is( 'qty 100 stays on tier 2 (quote band has no price)', $t2 * 100, $r['subtotal'] );

echo "\nAggregation across variations (the 50-per-product rule)\n";
$r = $checkout(
	$page->ID,
	[
		$vars[0] => 30,
		$vars[1] => 20,
	]
);
$is( '30 + 20 = 50 reaches tier 2', $t2 * 50, $r['subtotal'] );
$is( '  …and the 30-unit line is repriced', $t2, $r['units'][ $vars[0] ] );
$is( '  …and the 20-unit line is repriced', $t2, $r['units'][ $vars[1] ] );

$r = $checkout(
	$page->ID,
	[
		$vars[0] => 25,
		$vars[1] => 20,
	]
);
$is( '25 + 20 = 45 stays on tier 1', $t1 * 45, $r['subtotal'] );

$r = $checkout(
	$page->ID,
	[
		$vars[0] => 20,
		$vars[1] => 20,
		$vars[2] => 20,
	]
);
$is( '20 x 3 sizes = 60 reaches tier 2', $t2 * 60, $r['subtotal'] );

echo "\nIdempotency (WooCommerce recalculates repeatedly)\n";
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $page->ID, 50, $vars[0] );
WC()->cart->calculate_totals();
$first = (float) WC()->cart->get_subtotal();
WC()->cart->calculate_totals();
WC()->cart->calculate_totals();
$third = (float) WC()->cart->get_subtotal();
$is( 'three calculations give the same subtotal', $first, $third );
$is( 'and it is still the tier 2 total', $t2 * 50, $third );

echo "\nIsolation between products\n";
$other = get_page_by_path( 'mens-tee-pigment', OBJECT, 'product' );
if ( $other ) {
	$op    = wc_get_product( $other->ID );
	$ot    = get_post_meta( $other->ID, '_tbb_price_tiers', true );
	$ot1   = (float) $op->get_price();
	$ovars = array_values( $op->get_children() );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $page->ID, 50, $vars[0] );      // qualifies
	WC()->cart->add_to_cart( $other->ID, 5, $ovars[0] );     // does not
	WC()->cart->calculate_totals();
	$prices = [];
	foreach ( WC()->cart->get_cart() as $item ) {
		$prices[ (int) $item['product_id'] ] = (float) $item['data']->get_price();
	}
	$is( 'qualifying product gets tier 2', $t2, $prices[ $page->ID ] );
	$is( 'non-qualifying product keeps tier 1', $ot1, $prices[ $other->ID ] );
	$is( 'combined subtotal is correct', ( $t2 * 50 ) + ( $ot1 * 5 ), (float) WC()->cart->get_subtotal() );
}

echo "\nSafety\n";
$r = $checkout( $page->ID, [ $vars[0] => 50 ] );
$is( 'tier price never exceeds the base price', true, $r['units'][ $vars[0] ] <= $t1 );

// A product with no stored tiers must be left completely alone.
$untiered = null;
foreach ( wc_get_products(
	[
		'limit'  => 50,
		'status' => 'publish',
		'return' => 'ids',
	]
) as $pid ) {
	if ( ! get_post_meta( $pid, '_tbb_price_tiers', true ) ) { $untiered = $pid;
		break; }
}
if ( $untiered ) {
	$up       = wc_get_product( $untiered );
	$expected = (float) $up->get_price();
	WC()->cart->empty_cart();
	$kids = $up->get_children();
	WC()->cart->add_to_cart( $untiered, 100, $kids ? $kids[0] : 0 );
	WC()->cart->calculate_totals();
	$is( 'product with no tiers is untouched at qty 100', $expected * 100, (float) WC()->cart->get_subtotal() );
} else {
	echo "  SKIP  no untiered product available to test\n";
}

echo "\nBand resolution (direct, for cases a real cart cannot set up)\n";
$bp      = new \BlankBrandTheme\BulkPricing();
$tierFor = new ReflectionMethod( $bp, 'tier_for' );
$tierFor->setAccessible( true );
$band = [
	[
		'min'   => 1,
		'max'   => 49,
		'price' => 220,
	],
	[
		'min'   => 50,
		'max'   => null,
		'price' => 200,
	],
];
$is( 'qty 0 matches no band', true, null === $tierFor->invoke( $bp, $band, 0 ) );
$is( 'qty 1 is band 0', 0, $tierFor->invoke( $bp, $band, 1 )['index'] );
$is( 'qty 49 is band 0 (upper edge)', 0, $tierFor->invoke( $bp, $band, 49 )['index'] );
$is( 'qty 50 is band 1 (lower edge)', 1, $tierFor->invoke( $bp, $band, 50 )['index'] );
$is( 'qty 9999 is band 1', 1, $tierFor->invoke( $bp, $band, 9999 )['index'] );
$overlap = [
	[
		'min'   => 1,
		'max'   => 100,
		'price' => 220,
	],
	[
		'min'   => 50,
		'max'   => null,
		'price' => 200,
	],
];
$is( 'overlapping bands resolve to the cheaper one', 1, $tierFor->invoke( $bp, $overlap, 60 )['index'] );

$priceFor = new ReflectionMethod( $bp, 'price_for' );
$priceFor->setAccessible( true );
$fixture = wc_get_product( $vars[0] );
$is(
	'a tier priced above base is ignored',
	$t1,
	$priceFor->invoke(
		$bp,
		$fixture,
		[
			[
				'min'   => 1,
				'max'   => 49,
				'price' => $t1,
			],
			[
				'min'   => 50,
				'max'   => null,
				'price' => $t1 + 500,
			],
		],
		50
	)
);
$is(
	'a zero tier price is ignored',
	$t1,
	$priceFor->invoke(
		$bp,
		$fixture,
		[
			[
				'min'   => 1,
				'max'   => 49,
				'price' => $t1,
			],
			[
				'min'   => 50,
				'max'   => null,
				'price' => 0,
			],
		],
		50
	)
);
$tiersOf = new ReflectionMethod( $bp, 'tiers' );
$tiersOf->setAccessible( true );
$is( 'a product with no stored tiers yields no bands', true, [] === $tiersOf->invoke( $bp, 999999999 ) );

echo "\nParity: cart -> order -> Zoho payload\n";
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $page->ID, 50, $vars[0] );
WC()->cart->calculate_totals();
$cart_subtotal = (float) WC()->cart->get_subtotal();

// Built the way WooCommerce's own checkout builds it: line totals come from
// the calculated cart, not from re-reading the product price.
$order = wc_create_order();
foreach ( WC()->cart->get_cart() as $item ) {
	$order->add_product(
		$item['data'],
		$item['quantity'],
		[
			'subtotal' => $item['line_subtotal'],
			'total'    => $item['line_total'],
		]
	);
}
$order->calculate_totals();
$line = current( $order->get_items() );

$is( 'order line unit price matches the cart', $t2, (float) $order->get_item_total( $line, false, false ) );
$is( 'order subtotal matches the cart', $cart_subtotal, (float) $order->get_subtotal() );

$sync    = new \TBB\Zoho\OrderSync();
$builder = new ReflectionMethod( $sync, 'build_sales_order_payload' );
$builder->setAccessible( true );
$payload = $builder->invoke( $sync, $order, 1, new \TBB\Zoho\Client() );
$is( 'Zoho payload builds without error', false, is_wp_error( $payload ) );
$is( 'Zoho line carries an item id, not a SKU', true, ! empty( $payload['line_items'][0]['item_id'] ) && ! isset( $payload['line_items'][0]['sku'] ) );
$is( 'Zoho sales-order line rate matches the tier', $t2, (float) $payload['line_items'][0]['rate'] );
$is( 'Zoho line quantity matches', 50, (int) $payload['line_items'][0]['quantity'] );

$order->delete( true );
WC()->cart->empty_cart();


// ---------------------------------------------------------------- page and cart agree

echo "\nSale prices: the tier table and the cart quote one price\n";

/**
 * Price the page advertises for each band, keyed by the band's minimum.
 *
 * @param WC_Product $product Product.
 * @return array<int,float>
 */
$advertised = function ( WC_Product $product ) {
	$bd   = ( new ReflectionClass( BlankBrandTheme\BulkDiscounts::class ) )->newInstanceWithoutConstructor();
	$rows = new ReflectionMethod( $bd, 'rows' );
	$rows->setAccessible( true );
	$out = [];
	foreach ( $rows->invoke( $bd, $product ) as $row ) {
		if ( preg_match( '/^(\d+)/', $row['label'], $m ) && preg_match( '/R\s*([\d\s.,]+)/', $row['value'], $v ) ) {
			$out[ (int) $m[1] ] = (float) str_replace( [ ' ', ',' ], [ '', '.' ], $v[1] );
		}
	}
	return $out;
};

$charged = function ( WC_Product $product, int $quantity ) {
	$bp = ( new ReflectionClass( BlankBrandTheme\BulkPricing::class ) )->newInstanceWithoutConstructor();
	$pf = new ReflectionMethod( $bp, 'price_for' );
	$pf->setAccessible( true );
	$tiers = array_values( get_post_meta( $product->get_id(), '_tbb_price_tiers', true )['tiers'] );
	return (float) $pf->invoke( $bp, $product, $tiers, $quantity );
};

$make = function ( string $regular, string $sale, float $tier2 ) {
	$p = new WC_Product_Simple();
	$p->set_name( 'Tier parity fixture' );
	$p->set_regular_price( $regular );
	if ( '' !== $sale ) { $p->set_sale_price( $sale ); }
	$p->set_status( 'publish' );
	$p->save();
	update_post_meta(
		$p->get_id(),
		'_tbb_price_tiers',
		[
			'tiers'      => [
				[
					'min'   => 1,
					'max'   => 49,
					'price' => (float) $regular,
				],
				[
					'min'   => 50,
					'max'   => null,
					'price' => $tier2,
				],
			],
			'quote_from' => 100,
			'version'    => 1,
		]
	);
	return wc_get_product( $p->get_id() );
};

// A real discount: the page lists it, and the cart charges it.
$full = $make( '200', '', 180 );
$page = $advertised( $full );
$is( 'no sale: page shows the 1-49 price', 200, $page[1] ?? 0 );
$is( 'no sale: page shows the 50+ price', 180, $page[50] ?? 0 );
$is( 'no sale: cart charges the 50+ price at 50', 180, $charged( $full, 50 ) );

// On sale below the tier: a bigger order must not look dearer.
$sale = $make( '200', '150', 180 );
$page = $advertised( $sale );
$is( 'on sale: page shows the sale price for 1-49', 150, $page[1] ?? 0 );
$is( 'on sale: page does not list a 50+ price above the sale price', 0, $page[50] ?? 0 );
$is( 'on sale: cart charges the sale price at 1', 150, $charged( $sale, 1 ) );
$is( 'on sale: cart charges the sale price at 50', 150, $charged( $sale, 50 ) );

// On sale, but the tier still beats it: both agree on the tier.
$deeper = $make( '200', '190', 170 );
$page   = $advertised( $deeper );
$is( 'sale above the tier: page shows the 50+ price', 170, $page[50] ?? 0 );
$is( 'sale above the tier: cart charges the 50+ price', 170, $charged( $deeper, 50 ) );

foreach ( [ $full, $sale, $deeper ] as $fixture ) { $fixture->delete( true ); }

$is( 'effective_tier_price rejects a tier above the base', 1, BlankBrandTheme\BulkPricing::effective_tier_price( 180, 150 ) === null ? 1 : 0 );
$is( 'effective_tier_price rejects zero', 1, BlankBrandTheme\BulkPricing::effective_tier_price( 0, 150 ) === null ? 1 : 0 );
$is( 'effective_tier_price keeps a real discount', 170, BlankBrandTheme\BulkPricing::effective_tier_price( 170, 190 ) );

echo "\nCart reloaded from the session (what the mini cart prints)\n";
// An ordinary page load rebuilds the cart's products at their normal price and,
// when the saved totals are still good, does not recalculate them. The mini cart
// then printed "50 x R150" beside a subtotal worked out at the tier price.
// Reproduce that: price the line, put the normal price back as a reload does,
// then fire the hook WooCommerce fires once it has loaded the cart.
// $page is reused above for the sale-price cases, so take the parent from the variation.
$parent_id = (int) wp_get_post_parent_id( $vars[0] );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $parent_id, 50, $vars[0] );
WC()->cart->calculate_totals();
$base = (float) wc_get_product( $vars[0] )->get_price( 'edit' );
$tier = 0.0;
foreach ( WC()->cart->get_cart() as $line ) {
	$tier = (float) $line['data']->get_price( 'edit' );
	$line['data']->set_price( (string) $base );
}
$is( 'the tier is cheaper than the normal price, so this test can fail', true, $tier > 0 && $tier < $base );
foreach ( WC()->cart->get_cart() as $line ) {
	$is( 'after a reload the line holds the normal price (the bug)', $base, (float) $line['data']->get_price( 'edit' ) );
}
do_action( 'woocommerce_cart_loaded_from_session', WC()->cart );
foreach ( WC()->cart->get_cart() as $line ) {
	$is( 'once the cart is loaded from the session the line holds the tier price', $tier, (float) $line['data']->get_price( 'edit' ) );
}
ob_start();
woocommerce_mini_cart();
$mini      = ob_get_clean();
$mini_text = html_entity_decode( wp_strip_all_tags( $mini ) );
$plain     = static fn( float $amount ): string => html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ) );
$is( 'the mini cart prints 50 x the tier price', 1, false !== strpos( $mini_text, '50 × ' . $plain( $tier ) ) ? 1 : 0 );
$is( 'and not the normal price', 1, false === strpos( $mini_text, '50 × ' . $plain( $base ) ) ? 1 : 0 );
WC()->cart->empty_cart();

$put_back();

printf( "\n%d passed, %d failed\n", $pass, $fail );
if ( $fails ) { foreach ( $fails as $f ) { echo "  - $f\n"; }
}
