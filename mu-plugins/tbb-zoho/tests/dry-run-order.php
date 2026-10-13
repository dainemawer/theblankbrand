<?php
/**
 * Drive a real WooCommerce order through the Zoho order flow with writes
 * simulated, and print every call that would have been made.
 *
 *   wp eval-file tests/dry-run-order.php
 *
 * Builds a cart, turns it into an order the way checkout does, fires the sync,
 * then deletes the order. Nothing reaches Zoho: the client intercepts writes
 * while dry run is on, which this refuses to proceed without.
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
use TBB\Zoho\Settings;

if ( ! Client::is_dry_run() ) {
	echo "REFUSING TO RUN — dry run is off, this would create real records in Zoho.\n";
	echo "Enable it under Settings > Zoho Inventory, then run again.\n";
	return;
}

printf( "Dry run against organisation %s (%s)\n\n", Settings::get( 'organization_id' ), Settings::get( 'data_center' ) );

delete_option( Client::DRY_RUN_LOG );
wc_load_cart();
WC()->cart->empty_cart();

// A bulk order, so the tier price is visible in what Zoho would receive.
// Variations are chosen by what is actually sellable — hard-coding them broke
// the moment the site switched to Zoho's available-for-sale figure.
$page = get_page_by_path( 'womens-boxy-tee', OBJECT, 'product' );
$prod = wc_get_product( $page->ID );

$sellable = [];
foreach ( $prod->get_children() as $vid ) {
	$v = wc_get_product( $vid );
	if ( $v && $v->is_in_stock() && (int) $v->get_stock_quantity() >= 30 ) {
		$sellable[] = $vid;
	}
	if ( count( $sellable ) === 2 ) {
		break;
	}
}

if ( count( $sellable ) < 2 ) {
	echo "No two variations of that product have enough stock to demonstrate a bulk order.\n";
	return;
}

WC()->cart->add_to_cart( $page->ID, 30, $sellable[0] );
WC()->cart->add_to_cart( $page->ID, 25, $sellable[1] );
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
$order->set_billing_first_name( 'Dry' );
$order->set_billing_last_name( 'Run' );
$order->set_billing_email( 'dry-run@example.invalid' );
$order->calculate_totals();

printf(
	"WooCommerce order #%d — %d units across %d lines, subtotal %s\n\n",
	$order->get_id(),
	array_sum( array_map( static fn( $i ) => $i->get_quantity(), $order->get_items() ) ),
	count( $order->get_items() ),
	wp_strip_all_tags( wc_price( $order->get_subtotal() ) )
);

( new OrderSync() )->sync_order( $order->get_id() );

$log = array_reverse( (array) get_option( Client::DRY_RUN_LOG, [] ) );

if ( ! $log ) {
	echo "No calls were attempted.\n";
} else {
	printf( "%d call%s would have been sent to Zoho:\n\n", count( $log ), count( $log ) === 1 ? '' : 's' );
	foreach ( $log as $i => $entry ) {
		printf( "%d. %s %s%s\n", $i + 1, $entry['method'], $entry['path'], $entry['query'] ? ' ?' . http_build_query( $entry['query'] ) : '' );
		echo '   ' . str_replace( "\n", "\n   ", wp_json_encode( $entry['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . "\n\n";
	}
}

$order->delete( true );
WC()->cart->empty_cart();
delete_option( Client::DRY_RUN_LOG );
echo "order deleted, log cleared\n";
