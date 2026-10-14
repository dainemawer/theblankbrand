<?php
/**
 * VAT is added at checkout, and WooCommerce agrees with what Zoho is sent.
 *
 *   wp eval-file wp-content/mu-plugins/tbb-zoho/tests/vat-test.php
 *
 * Needs the VAT settings applied first (see tools/vat.php). It checks three things:
 *
 *   1. The settings and the 15% South Africa rate are in place.
 *   2. The cart adds 15% to ex-VAT prices, to the cent, at the sizes that matter:
 *      one unit, a bulk tier, and an awkward amount that forces rounding.
 *   3. A real order's Zoho sales order carries ex-VAT unit prices, so Zoho's own
 *      15% lands on the figure the customer paid. Nothing reaches Zoho: this
 *      refuses to run unless dry run is on.
 *
 * It creates and removes a product and an order, so it is for a local site.
 *
 * @package TBB\Zoho
 */

// These are command-line scripts, run with `wp eval-file`. They print to a terminal
// rather than render a page and read the database directly, so the rules written
// for web output and cached queries do not apply to them.
// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.ValidVariableName, WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery, WordPress.PHP.DevelopmentFunctions, WordPress.WP.AlternativeFunctions, WordPress.WP.PostsPerPageNoUnlimited, Universal.Operators.DisallowShortTernary, Universal.NamingConventions.NoReservedKeywordParameterNames

if ( ! defined( 'ABSPATH' ) ) { exit; }

use TBB\Zoho\Client;
use TBB\Zoho\OrderSync;

if ( 'local' !== wp_get_environment_type() && ! defined( 'TBB_ALLOW_TESTS' ) ) {
	echo "REFUSING TO RUN — this creates and deletes a product and an order, and is for a local site.\n";
	return;
}
if ( ! Client::is_dry_run() ) {
	echo "REFUSING TO RUN — dry run is off, the order step would create real records in Zoho.\n";
	return;
}

$pass  = 0;
$fail  = 0;
$fails = [];

$is    = function ( string $label, $expected, $actual ) use ( &$pass, &$fail, &$fails ) {
	if ( $expected === $actual ) {
		++$pass;
		echo "  PASS  $label\n";
		return;
	}
	++$fail;
	$fails[] = $label;
	printf( "  FAIL  %s — expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
};
$near  = function ( string $label, float $expected, float $actual, float $tolerance = 0.02 ) use ( $is ) {
	$is( $label, true, abs( $expected - $actual ) <= $tolerance );
	if ( abs( $expected - $actual ) > $tolerance ) {
		printf( "        expected %.2f, got %.2f\n", $expected, $actual );
	}
};
$money = static fn( $n ): string => number_format( (float) $n, 2, '.', '' );

// ------------------------------------------------------------------ settings

echo "Settings\n";
$is( 'tax is switched on', 'yes', get_option( 'woocommerce_calc_taxes' ) );
$is( 'prices are entered excluding VAT', 'no', get_option( 'woocommerce_prices_include_tax' ) );
$is( 'shop prices are shown excluding VAT', 'excl', get_option( 'woocommerce_tax_display_shop' ) );

$rates = array_values(
	array_filter(
		WC_Tax::get_rates_for_tax_class( '' ),
		static fn( $r ) => 'ZA' === $r->tax_rate_country
	)
);
$is( 'exactly one South Africa rate', 1, count( $rates ) );
$is( 'and it is 15%', 15.0, (float) ( $rates[0]->tax_rate ?? 0 ) );

if ( $fail ) {
	echo "\nSettings are not in place, so the totals below would mean nothing. Run tools/vat.php with APPLY=1.\n";
	printf( "\n%d passed, %d failed\n", $pass, $fail );
	return;
}

// ------------------------------------------------------------------ cart maths

echo "\nCart totals\n";

wc_load_cart();
WC()->customer->set_billing_country( 'ZA' );
WC()->customer->set_shipping_country( 'ZA' );

/**
 * Put a given number of units at a given ex-VAT price in the cart.
 */
$cart_for = function ( float $price, int $qty ): WC_Cart {
	$p = new WC_Product_Simple();
	$p->set_name( 'VAT fixture' );
	$p->set_regular_price( (string) $price );
	$p->set_tax_status( 'taxable' );
	$p->set_status( 'publish' );
	$p->save();

	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $p->get_id(), $qty );
	WC()->cart->calculate_totals();
	$GLOBALS['vat_fixture_ids'][] = $p->get_id();
	return WC()->cart;
};

// [ ex-VAT unit price, quantity, expected subtotal, expected VAT, expected total ]
$cases = [
	'one unit at R180'               => [ 180.00, 1, '180.00', '27.00', '207.00' ],
	'a 50-unit tier at R180'         => [ 180.00, 50, '9000.00', '1350.00', '10350.00' ],
	'an amount that forces rounding' => [ 165.55, 7, '1158.85', '173.83', '1332.68' ],
];
foreach ( $cases as $label => [ $price, $qty, $sub, $vat, $total ] ) {
	$cart = $cart_for( $price, $qty );
	$is( "$label: subtotal", $sub, $money( $cart->get_subtotal() ) );
	$is( "$label: VAT", $vat, $money( $cart->get_total_tax() ) );
	$is( "$label: total", $total, $money( $cart->get_total( 'edit' ) ) );
}

foreach ( (array) ( $GLOBALS['vat_fixture_ids'] ?? [] ) as $id ) {
	wp_delete_post( $id, true );
}
WC()->cart->empty_cart();

// ------------------------------------------------------------------ what Zoho is sent

echo "\nWhat Zoho receives\n";

$page = get_page_by_path( 'womens-boxy-tee', OBJECT, 'product' );
$prod = $page ? wc_get_product( $page->ID ) : null;
$ids  = [];
foreach ( $prod ? $prod->get_children() : [] as $vid ) {
	$v = wc_get_product( $vid );
	if ( $v && $v->is_in_stock() && (int) $v->get_stock_quantity() >= 30 ) {
		$ids[] = $vid;
	}
	if ( 2 === count( $ids ) ) {
		break;
	}
}

if ( 2 !== count( $ids ) ) {
	echo "  SKIP  no two variations of that product have 30 units, so a bulk order cannot be built\n";
} else {
	delete_option( Client::DRY_RUN_LOG );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $page->ID, 30, $ids[0] );
	WC()->cart->add_to_cart( $page->ID, 25, $ids[1] );
	WC()->cart->calculate_totals();

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
	$address = [
		'first_name' => 'Vat',
		'last_name'  => 'Test',
		'email'      => 'vat-test@example.invalid',
		'country'    => 'ZA',
	];
	$order->set_address( $address, 'billing' );
	$order->set_address( $address, 'shipping' );
	$order->calculate_totals();

	$ex_vat = (float) $order->get_total() - (float) $order->get_total_tax();
	$near( 'WooCommerce adds 15% to the goods', $ex_vat * 0.15, (float) $order->get_total_tax() );

	( new OrderSync() )->sync_order( $order->get_id() );

	$sent = null;
	foreach ( array_reverse( (array) get_option( Client::DRY_RUN_LOG, [] ) ) as $entry ) {
		if ( 'POST' === $entry['method'] && '/salesorders' === $entry['path'] ) {
			$sent = $entry['body'];
			break;
		}
	}

	if ( ! $sent ) {
		++$fail;
		$fails[] = 'a sales order was built';
		echo "  FAIL  no sales order call was made. Is Zoho reachable, and do those SKUs exist in it?\n";
	} else {
		$lines_ex_vat = 0.0;
		foreach ( $sent['line_items'] as $line ) {
			$lines_ex_vat += (float) $line['rate'] * (float) $line['quantity'];
		}
		$near( 'the sales order lines add up to the ex-VAT goods', $ex_vat, $lines_ex_vat );
		$near( 'so Zoho\'s own 15% lands on what the customer paid', (float) $order->get_total(), $lines_ex_vat * 1.15 );
		$is( 'shipping is sent ex-VAT and is zero for free shipping', 0.0, (float) $sent['shipping_charge'] );
	}

	$order->delete( true );
	WC()->cart->empty_cart();
	delete_option( Client::DRY_RUN_LOG );
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
if ( $fails ) {
	foreach ( $fails as $f ) {
		echo "  - $f\n";
	}
}
