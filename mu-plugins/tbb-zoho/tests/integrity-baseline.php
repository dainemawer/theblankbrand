<?php
/**
 * Integrity baseline for the Zoho integration.
 *
 *   wp eval-file tests/integrity-baseline.php            # check against the saved baseline
 *   BASELINE=save wp eval-file tests/integrity-baseline.php   # record the current state as the baseline
 *
 * Two jobs. It asserts the invariants that must hold no matter what the data
 * does — Zoho reachable, nothing oversold into negative stock, no silent
 * unmatched SKUs, prices agreeing with Zoho, the write guard intact. And it
 * compares today's headline figures against a saved snapshot, so a change that
 * breaks nothing outright but moves 400 products still gets noticed.
 *
 * Read-only against both WooCommerce and Zoho. Safe to run at any time.
 *
 * @package TBB\Zoho
 */

// These are command-line scripts, run with `wp eval-file`. They print to a terminal
// rather than render a page and read the database directly, so the rules written
// for web output and cached queries do not apply to them.
// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.ValidVariableName, WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery, WordPress.PHP.DevelopmentFunctions, WordPress.WP.AlternativeFunctions, WordPress.WP.PostsPerPageNoUnlimited, Universal.Operators.DisallowShortTernary, Universal.NamingConventions.NoReservedKeywordParameterNames

if ( ! defined( 'ABSPATH' ) ) { exit; }

use TBB\Zoho\Client;
use TBB\Zoho\Settings;
use TBB\Zoho\StockSync;

global $wpdb;

$pass = 0;
$warn = 0;
$fail = 0;

$check = function ( string $label, bool $ok, string $detail = '', bool $soft = false ) use ( &$pass, &$warn, &$fail ) {
	if ( $ok ) { ++$pass;
		$tag = 'PASS';
	} elseif ( $soft ) { ++$warn;
		$tag = 'WARN';
	} else { ++$fail;
		$tag = 'FAIL'; }
		printf( "  %-4s  %-52s %s\n", $tag, $label, $detail );
};

printf( "Zoho integration integrity baseline — %s\n", gmdate( 'Y-m-d H:i' ) . ' UTC' );
printf( "site %s | org %s\n\n", home_url(), Settings::get( 'organization_id' ) );

// ---------------------------------------------------------------- configuration

echo "Configuration\n";
$check( 'integration enabled', Settings::is_enabled() );
$check( 'credentials present', '' !== Settings::get( 'client_id' ) && '' !== Settings::get( 'client_secret' ) && '' !== Settings::get( 'refresh_token' ) );
$check( 'webhook secret set', '' !== Settings::get( 'webhook_secret' ) );
$check( 'warehouse email set', '' !== Settings::get( 'warehouse_email' ), Settings::get( 'warehouse_email' ) );
$check( 'writes are simulated (dry run)', Client::is_dry_run(), Client::is_dry_run() ? '' : 'LIVE — orders will create real Zoho records', true );
$check( 'stock follows available_for_sale', 'available_for_sale' === StockSync::STOCK_FIELD, StockSync::STOCK_FIELD );
$check( 'runs as a must-use plugin', defined( 'TBB_ZOHO_VERSION' ), defined( 'TBB_ZOHO_VERSION' ) ? TBB_ZOHO_VERSION : '' );

// ---------------------------------------------------------------- write guard

echo "\nWrite guard — Zoho must stay read-only for stock and price\n";
$permitted = new ReflectionMethod( Client::class, 'is_permitted' );
$permitted->setAccessible( true );
$client = new Client();
$check( 'GET /items permitted', true === $permitted->invoke( $client, 'GET', '/items' ) );
$check( 'PUT /items blocked', false === $permitted->invoke( $client, 'PUT', '/items/1' ) );
$check( 'POST /items blocked', false === $permitted->invoke( $client, 'POST', '/items' ) );
$check( 'DELETE /items blocked', false === $permitted->invoke( $client, 'DELETE', '/items/1' ) );
$check( 'PUT /pricebooks blocked', false === $permitted->invoke( $client, 'PUT', '/pricebooks/1' ) );
$check( 'POST /salesorders permitted', true === $permitted->invoke( $client, 'POST', '/salesorders' ) );

// ---------------------------------------------------------------- connectivity

echo "\nZoho connectivity\n";
$org = $client->get_organization();
$check( 'organisation reachable', ! is_wp_error( $org ), is_wp_error( $org ) ? $org->get_error_message() : ( $org['organization']['name'] ?? '' ) );

$prices = $client->get_pricebook_rates( StockSync::PRICE_BOOK );
$check( 'Retail price book readable', ! is_wp_error( $prices ), is_wp_error( $prices ) ? $prices->get_error_message() : count( $prices ) . ' entries' );

// A customer email with a plus sign is ordinary (Gmail plus-addressing). The
// contact lookup once sent it unencoded, Zoho rejected it, and the order never
// synced. Dry run cannot catch that, because it never sends the lookup's
// siblings, so this exercises the real read path.
$lookup = ( new ReflectionMethod( $client, 'request' ) );
$lookup->setAccessible( true );
$plus = $lookup->invoke(
	$client,
	'GET',
	'/contacts',
	[
		'email'        => 'baseline+probe@example.com',
		'contact_type' => 'customer',
	]
);
$check( 'contact lookup accepts a plus-addressed email', ! is_wp_error( $plus ), is_wp_error( $plus ) ? $plus->get_error_message() : '' );

// ---------------------------------------------------------------- catalogue

echo "\nCatalogue\n";
$report     = get_option( StockSync::REPORT_OPTION, [] );
$unmatched  = (array) ( $report['unmatched'] ?? [] );
$units      = (int) $wpdb->get_var( "SELECT SUM(CAST(meta_value AS SIGNED)) FROM {$wpdb->postmeta} WHERE meta_key='_stock'" );
$negative   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_stock' AND CAST(meta_value AS SIGNED) < 0" );
$variations = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product_variation' AND post_status='publish'" );
$managed    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_manage_stock' AND meta_value='yes'" );
$products   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'" );
$tiers      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_tbb_price_tiers'" );
$swatches   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key='_tbb_swatch_image'" );
$colours    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy='pa_colour'" );

$check( 'no negative stock anywhere', 0 === $negative, $negative ? "$negative rows" : '' );
$check( 'every variation manages stock', $managed >= $variations, "$managed of $variations" );
$check( 'every colour has a swatch', $swatches >= $colours, "$swatches of $colours" );
$check( 'every product has price tiers', $tiers >= $products, "$tiers of $products" );
$check( 'no unmatched SKUs', 0 === count( $unmatched ), $unmatched ? implode( ', ', $unmatched ) : '', true );

// swatch files actually exist
$missing = 0;
foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->termmeta} WHERE meta_key='_tbb_swatch_image'" ) as $aid ) {
	$file = get_attached_file( (int) $aid );
	if ( ! $file || ! file_exists( $file ) ) { ++$missing; }
}
$check( 'all swatch files present on disk', 0 === $missing, $missing ? "$missing missing" : '' );

// displayed price must equal charged price, on every product
$mismatched = [];
foreach ( $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_tbb_price_tiers'" ) as $pid ) {
	$product = wc_get_product( $pid );
	if ( ! $product ) { continue; }
	$headline = (float) preg_replace( '/[^\d.]/', '', str_replace( ',', '.', html_entity_decode( wp_strip_all_tags( $product->get_price_html() ) ) ) );
	$actual   = (float) $product->get_price();
	if ( $headline > 0 && abs( $headline - $actual ) >= 0.01 ) {
		$mismatched[] = get_the_title( $pid );
	}
}
$check( 'headline price equals charged price', ! $mismatched, $mismatched ? implode( ', ', array_slice( $mismatched, 0, 3 ) ) : '' );

// ---------------------------------------------------------------- scheduler

echo "\nScheduler\n";
$next     = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( StockSync::ACTION, [], 'zoho' ) : false;
$finished = (int) ( $report['finished'] ?? 0 );
$age      = $finished ? time() - $finished : PHP_INT_MAX;
$check( 'reconciliation scheduled', (bool) $next, is_int( $next ) ? gmdate( 'Y-m-d H:i', $next ) . ' UTC' : '' );
$check( 'last run within three intervals', $age < StockSync::INTERVAL * 3, $finished ? human_time_diff( $finished ) . ' ago' : 'never', true );
$failure = get_option( StockSync::FAILURE_OPTION, [] );
$failed  = $failure && (int) ( $failure['at'] ?? 0 ) >= $finished;
$check( 'last run did not error', ! $failed, $failed ? (string) ( $failure['message'] ?? '' ) : '' );

// ---------------------------------------------------------------- baseline

$current = [
	'recorded_at' => gmdate( 'c' ),
	'products'    => $products,
	'variations'  => $variations,
	'units'       => $units,
	'managed'     => $managed,
	'tiers'       => $tiers,
	'swatches'    => $swatches,
	'colours'     => $colours,
	'unmatched'   => count( $unmatched ),
	'zoho_items'  => (int) ( $report['zoho_items'] ?? 0 ),
	'matched'     => (int) ( $report['matched'] ?? 0 ),
	'stock_field' => StockSync::STOCK_FIELD,
];

if ( 'save' === getenv( 'BASELINE' ) ) {
	update_option( 'tbb_zoho_integrity_baseline', $current, false );
	echo "\nBaseline saved:\n";
	foreach ( $current as $k => $v ) { printf( "  %-14s %s\n", $k, $v ); }
} else {
	$base = get_option( 'tbb_zoho_integrity_baseline', [] );
	echo "\nAgainst the saved baseline\n";
	if ( ! $base ) {
		echo "  no baseline recorded yet — run with BASELINE=save\n";
	} else {
		printf( "  baseline taken %s\n", $base['recorded_at'] ?? '?' );
		foreach ( $current as $k => $v ) {
			if ( 'recorded_at' === $k ) { continue; }
			$was = $base[ $k ] ?? null;
			if ( null === $was ) { continue; }
			$same = (string) $was === (string) $v;
			printf( "  %-4s  %-20s %s%s\n", $same ? 'same' : 'DIFF', $k, $v, $same ? '' : "   (was $was)" );
		}
	}
}

printf( "\n%d passed, %d warnings, %d failed\n", $pass, $warn, $fail );
