<?php
/**
 * VAT setup: add 15% at checkout, with prices entered and shown excluding VAT.
 *
 *   wp eval-file wp-content/mu-plugins/tbb-zoho/tools/vat.php             # report only
 *   APPLY=1 wp eval-file wp-content/mu-plugins/tbb-zoho/tools/vat.php     # make it so
 *
 * Why this exists. Zoho adds its own 15% to the sales order for a web order,
 * built from the ex-VAT unit price we send. With WooCommerce tax switched off
 * the customer paid the ex-VAT figure and Zoho showed 15% more. Switching WooCommerce
 * tax on makes the customer pay what Zoho shows. The order feed does not change:
 * it already sends the ex-VAT unit price.
 *
 * These are database settings, not code, so they do not travel with a deploy.
 * Run this on each site once. It is safe to repeat and reports each setting
 * before it touches anything.
 *
 * @package TBB\Zoho
 */

// These are command-line scripts, run with `wp eval-file`. They print to a terminal
// rather than render a page and read the database directly, so the rules written
// for web output and cached queries do not apply to them.
// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.ValidVariableName, WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery, WordPress.PHP.DevelopmentFunctions, WordPress.WP.AlternativeFunctions, WordPress.WP.PostsPerPageNoUnlimited, Universal.Operators.DisallowShortTernary, Universal.NamingConventions.NoReservedKeywordParameterNames

if ( ! defined( 'ABSPATH' ) ) { exit; }

$apply   = '1' === getenv( 'APPLY' );
$changes = 0;
$problem = 0;

echo $apply ? "APPLYING VAT settings to this site\n\n" : "Checking VAT settings (nothing is changed; run with APPLY=1 to apply)\n\n";

$want = [
	'woocommerce_calc_taxes'            => 'yes',  // charge VAT at all.
	'woocommerce_prices_include_tax'    => 'no',   // prices are entered excluding VAT, as the product pages say.
	'woocommerce_tax_display_shop'      => 'excl', // shop prices stay ex-VAT.
	'woocommerce_tax_display_cart'      => 'excl', // the cart shows ex-VAT lines and a VAT row.
	'woocommerce_tax_round_at_subtotal' => 'no',   // round per line, which is how Zoho totals a sales order.
	'woocommerce_tax_total_display'     => 'single', // one VAT row, not an itemised list.
];

echo "Settings\n";
foreach ( $want as $option => $value ) {
	$have = get_option( $option );
	if ( $have === $value ) {
		printf( "  OK      %-38s %s\n", $option, $value );
		continue;
	}
	++$changes;
	printf( "  %s  %-38s %s -> %s\n", $apply ? 'SET   ' : 'DIFFERS', $option, var_export( $have, true ), $value );
	if ( $apply ) {
		update_option( $option, $value );
	}
}

echo "\nStandard-rate tax table\n";
$standard = WC_Tax::get_rates_for_tax_class( '' );
$za15     = [];
$other    = [];
foreach ( $standard as $rate ) {
	if ( 'ZA' === $rate->tax_rate_country && abs( (float) $rate->tax_rate - 15.0 ) < 0.0001 ) {
		$za15[] = $rate;
	} else {
		$other[] = $rate;
	}
}

if ( count( $za15 ) === 1 ) {
	printf( "  OK      South Africa 15%% already present (\"%s\")\n", $za15[0]->tax_rate_name );
} elseif ( count( $za15 ) > 1 ) {
	++$problem;
	printf( "  FAIL    %d South Africa 15%% rows. Rates at one priority add together, so this charges more than 15%%. Remove the extra by hand.\n", count( $za15 ) );
} else {
	++$changes;
	printf( "  %s  add South Africa 15%% (VAT), applies to shipping\n", $apply ? 'ADD    ' : 'MISSING' );
	if ( $apply ) {
		WC_Tax::_insert_tax_rate(
			[
				'tax_rate_country'  => 'ZA',
				'tax_rate_state'    => '',
				'tax_rate'          => '15.0000',
				'tax_rate_name'     => 'VAT',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => '',
			]
		);
	}
}
foreach ( $other as $rate ) {
	++$problem;
	printf( "  FAIL    unexpected standard rate: %s %s%% (%s). Review it by hand.\n", $rate->tax_rate_country ?: '*', $rate->tax_rate, $rate->tax_rate_name );
}

echo "\nProducts that would escape VAT\n";
global $wpdb;
$untaxed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_tax_status' AND pm.meta_value = 'none' AND p.post_status = 'publish'" );
$classed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_tax_class' AND pm.meta_value NOT IN ('', 'parent') AND p.post_status = 'publish'" );
if ( $untaxed ) {
	++$problem;
	printf( "  FAIL    %d published product(s) have tax status \"None\". Set them to Taxable.\n", $untaxed );
} else {
	echo "  OK      every published product is taxable\n";
}
if ( $classed ) {
	++$problem;
	printf( "  FAIL    %d published product(s) use a non-standard tax class, which has no rates.\n", $classed );
} else {
	echo "  OK      every product uses the standard tax class\n";
}

echo "\n";
if ( $problem ) {
	printf( "%d problem(s) need a person to look at them.\n", $problem );
} elseif ( $changes && ! $apply ) {
	printf( "%d thing(s) to change. Run again with APPLY=1.\n", $changes );
} elseif ( $changes ) {
	printf( "Applied %d change(s). Run once more without APPLY to confirm.\n", $changes );
} else {
	echo "VAT is set up: 15% is added at checkout on ex-VAT prices.\n";
}
