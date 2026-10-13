<?php
/**
 * Regression tests for the order sync, the status screen, the stock webhook and
 * the Zoho client.
 *
 *   wp eval-file wp-content/mu-plugins/tbb-zoho/tests/regression-test.php
 *
 * Every Zoho call is answered by a mock, so this exercises the real write path
 * (contact, sales order, confirmation) without sending anything. "Dry run off"
 * is simulated with a filter, never by saving the setting, so a crash cannot
 * leave the integration live.
 *
 * Each section pins a defect that dry run alone could not show, because dry run
 * sends nothing and so cannot be refused:
 *
 *   - orders: a simulated sync must not read as a real one; the live path sends
 *     item ids, puts the email on a contact person, and survives a failed
 *     confirmation without creating a second sales order.
 *   - status: a failed sync must not erase the last good report.
 *   - webhook: a notification queues a job and does no Zoho call of its own.
 *   - client: a rejected token is replaced and retried; a failing refresh is
 *     not hammered.
 *
 * It creates and removes orders and a product, so it refuses to run anywhere
 * but a local site unless TBB_ALLOW_TESTS is defined.
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
use TBB\Zoho\StatusReport;
use TBB\Zoho\StockSync;
use TBB\Zoho\StockWebhook;

if ( 'local' !== wp_get_environment_type() && ! defined( 'TBB_ALLOW_TESTS' ) ) {
	echo "REFUSING TO RUN — this creates and deletes orders and products, and is for a local site.\n";
	return;
}

if ( ! Client::is_dry_run() ) {
	echo "REFUSING TO RUN — dry run is off. Turn it on first; the live-path tests switch it off for themselves, in memory only.\n";
	return;
}

$pass  = 0;
$fail  = 0;
$fails = [];

$is = function ( string $label, $expected, $actual ) use ( &$pass, &$fail, &$fails ) {
	if ( $expected === $actual ) {
		++$pass;
		echo "  PASS  $label\n";
		return;
	}
	++$fail;
	$fails[] = $label;
	printf( "  FAIL  %s — expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
};

$ok = function ( string $label, bool $condition, string $detail = '' ) use ( $is ) {
	$is( $label . ( $detail && ! $condition ? " [$detail]" : '' ), true, $condition );
};

// ------------------------------------------------------------------ a mocked Zoho

$state = [];

$reset = function () use ( &$state ) {
	$state = [
		'calls'         => [],
		'token_fails'   => false,
		'always_401'    => false,
		'confirm_fails' => false,
		'create_fails'  => false,
		'salesorders'   => 0,
		'contacts'      => [],
		'items'         => [
			'M900_BLK_L' => [
				'item_id'            => '7770000000000486612',
				'sku'                => 'M900_BLK_L',
				'available_for_sale' => 5,
				'stock_on_hand'      => 9,
			],
		],
	];
	delete_transient( Client::TOKEN_TRANSIENT );
	delete_transient( Client::TOKEN_FAILURE_TRANSIENT );
	set_transient(
		Client::TOKEN_TRANSIENT,
		[
			'access_token' => 'good-token',
			'api_domain'   => 'https://www.zohoapis.com',
		],
		3000
	);
};

$mock = function ( $pre, $args, $url ) use ( &$state ) {
	if ( false === strpos( $url, 'zoho' ) ) {
		return $pre;
	}

	$parts  = wp_parse_url( $url );
	$path   = $parts['path'] ?? '';
	$query  = $parts['query'] ?? '';
	$method = strtoupper( $args['method'] ?? 'GET' );
	$reply  = fn( int $code, array $body ) => [
		'headers'  => [],
		'body'     => wp_json_encode( $body ),
		'response' => [
			'code'    => $code,
			'message' => '',
		],
		'cookies'  => [],
		'filename' => null,
	];

	if ( false !== strpos( $path, '/oauth/v2/token' ) ) {
		$state['calls'][] = [
			'method' => 'TOKEN',
			'path'   => $path,
			'query'  => '',
			'body'   => null,
			'url'    => $url,
		];

		return $state['token_fails']
			? $reply( 400, [ 'error' => 'access denied' ] )
			: $reply(
				200,
				[
					'access_token' => 'fresh-token',
					'api_domain'   => 'https://www.zohoapis.com',
					'expires_in'   => 3600,
				]
			);
	}

	$rel  = preg_replace( '#^.*/inventory/v1#', '', $path );
	$body = isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null;
	$auth = $args['headers']['Authorization'] ?? '';

	$state['calls'][] = [
		'method' => $method,
		'path'   => $rel,
		'query'  => $query,
		'body'   => $body,
		'url'    => $url,
	];

	if ( $state['always_401'] || false !== strpos( $auth, 'bad-token' ) ) {
		return $reply(
			401,
			[
				'code'    => 57,
				'message' => 'You are not authorized to perform this operation',
			]
		);
	}

	if ( 'GET' === $method && 0 === strpos( $rel, '/organizations/' ) ) {
		return $reply(
			200,
			[
				'code'         => 0,
				'organization' => [ 'name' => 'Mock organisation' ],
			]
		);
	}

	if ( 'GET' === $method && '/contacts' === $rel ) {
		return $reply( 200, [ 'contacts' => $state['contacts'] ] );
	}

	if ( 'POST' === $method && '/contacts' === $rel ) {
		return $reply( 200, [ 'contact' => [ 'contact_id' => '7770000000009990001' ] ] );
	}

	if ( 'GET' === $method && '/items' === $rel ) {
		parse_str( $query, $q );
		$item = $state['items'][ $q['sku'] ?? '' ] ?? null;

		return $reply( 200, [ 'items' => $item ? [ $item ] : [] ] );
	}

	if ( 'POST' === $method && '/salesorders' === $rel ) {
		if ( $state['create_fails'] ) {
			return $reply( 400, [ 'message' => 'refused' ] );
		}
		++$state['salesorders'];

		return $reply( 200, [ 'salesorder' => [ 'salesorder_id' => '77700000000088' . $state['salesorders'] ] ] );
	}

	if ( 'POST' === $method && preg_match( '#^/salesorders/\d+/status/confirmed$#', $rel ) ) {
		return $state['confirm_fails'] ? $reply( 400, [ 'message' => 'cannot confirm' ] ) : $reply( 200, [ 'code' => 0 ] );
	}

	return $reply( 404, [ 'message' => "unmocked $method $rel" ] );
};

add_filter( 'pre_http_request', $mock, 10, 3 );

// Dry run "off", in memory only.
$real_settings = get_option( Settings::OPTION, [] );
$live_filter   = fn() => array_merge( $real_settings, [ 'dry_run' => 'no' ] );
$live_on       = function () use ( $live_filter ) {
	add_filter( 'pre_option_' . Settings::OPTION, $live_filter );
};
$live_off      = function () use ( $live_filter ) {
	remove_filter( 'pre_option_' . Settings::OPTION, $live_filter );
};

// ------------------------------------------------------------------ helpers

$made_orders = [];

$mk_order = function ( string $email = 'regression@example.com', string $sku = 'M900_BLK_L' ) use ( &$made_orders ) {
	$o = wc_create_order();
	$o->add_product( wc_get_product( wc_get_product_id_by_sku( $sku ) ), 1 );
	$o->set_billing_first_name( 'Regression' );
	$o->set_billing_last_name( 'Probe' );
	$o->set_billing_email( $email );
	$o->calculate_totals();
	$o->save();
	$made_orders[] = $o->get_id();

	return $o->get_id();
};

$notes = function ( int $id ) {
	return array_map( fn( $n ) => trim( wp_strip_all_tags( $n->content ) ), wc_get_order_notes( [ 'order_id' => $id ] ) );
};

$posts = function () use ( &$state ) {
	return array_values( array_filter( $state['calls'], fn( $c ) => 'POST' === $c['method'] ) );
};

$pending = function ( string $hook, array $args ) {
	return (bool) as_next_scheduled_action( $hook, $args, 'zoho' );
};

$status_text = function () {
	ob_start();
	( new StatusReport() )->render();

	return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( ob_get_clean() ) ) ) );
};

// ================================================================== orders

echo "Orders: a simulated sync must not read as a real one\n";
$reset();

$id = $mk_order();
( new OrderSync() )->sync_order( $id );
$o = wc_get_order( $id );

$is( 'not marked synced', '', (string) $o->get_meta( '_tbb_zoho_synced' ) );
$is( 'no sales order id stored (a made-up one would break a later live retry)', '', (string) $o->get_meta( '_tbb_zoho_sales_order_id' ) );
$ok( 'marked as simulated', '' !== (string) $o->get_meta( OrderSync::SIMULATED_META ) );
$joined = implode( ' | ', $notes( $id ) );
$ok( 'note says SIMULATED', false !== strpos( $joined, 'SIMULATED' ) );
$ok( 'no "Synced to Zoho Inventory" note', false === strpos( $joined, 'Synced to Zoho Inventory' ) );
$is( 'nothing was written to Zoho', 0, count( $posts() ) );

$before = count( $notes( $id ) );
( new OrderSync() )->sync_order( $id );
$is( 'a second simulated run adds no second note', $before, count( $notes( $id ) ) );

$ok( 'listed as an order that is not in Zoho', in_array( $id, array_map( fn( $x ) => $x->get_id(), OrderSync::simulated_orders() ), true ) );
$text = $status_text();
$ok( 'status screen says writes are simulated', false !== strpos( $text, 'Simulated. Dry run is on' ) );
$ok( 'status screen lists the order that is not in Zoho', false !== strpos( $text, 'Not in Zoho' ) && false !== strpos( $text, '#' . $id ) );
$is( 'requeue refuses while dry run is on', 0, OrderSync::requeue_simulated() );

echo "\nOrders: the live path, against a mocked Zoho\n";
$live_on();
$ok( 'status screen would say live', false !== strpos( $status_text(), 'Live. Paid orders are sent to Zoho' ) );
$ok( 'requeue queues the simulated order', OrderSync::requeue_simulated() >= 1 && $pending( 'tbb_zoho_sync_order', [ 'order_id' => $id ] ) );

$reset();
( new OrderSync() )->sync_order( $id );
$o = wc_get_order( $id );
$is( 'marked synced', 'yes', (string) $o->get_meta( '_tbb_zoho_synced' ) );
$ok( 'stores the real sales order id', 0 === strpos( (string) $o->get_meta( '_tbb_zoho_sales_order_id' ), '777000' ) );
$is( 'the simulated marker is cleared', '', (string) $o->get_meta( OrderSync::SIMULATED_META ) );
$ok( 'note names the sales order', (bool) preg_grep( '/^Synced to Zoho Inventory as Sales Order #777000/', $notes( $id ) ) );
$is( 'exactly one sales order created', 1, $state['salesorders'] );

$contact_post = array_values( array_filter( $posts(), fn( $c ) => '/contacts' === $c['path'] ) )[0] ?? null;
$ok( 'a new customer is created', null !== $contact_post );
$is( 'the email is on a contact person, not the contact', 'regression@example.com', $contact_post['body']['contact_persons'][0]['email'] ?? null );
$is( 'and not sent as a top-level field (Zoho ignores it)', false, isset( $contact_post['body']['email'] ) );

$so_post = array_values( array_filter( $posts(), fn( $c ) => '/salesorders' === $c['path'] ) )[0] ?? null;
$is( 'sales order lines carry a Zoho item id', '7770000000000486612', $so_post['body']['line_items'][0]['item_id'] ?? null );
$is( 'and no SKU, which Zoho does not accept', false, isset( $so_post['body']['line_items'][0]['sku'] ) );
$is( 'customer id is sent as a string', 'string', gettype( $so_post['body']['customer_id'] ?? null ) );
$is( 'rate is sent as a number', true, is_float( $so_post['body']['line_items'][0]['rate'] ?? null ) || is_int( $so_post['body']['line_items'][0]['rate'] ?? null ) );

echo "\nOrders: a failed confirmation must not create a second sales order\n";
$reset();
$state['confirm_fails'] = true;
$id2                    = $mk_order( 'retry@example.com' );
( new OrderSync() )->sync_order( $id2 );
$o = wc_get_order( $id2 );
$is( 'not synced while confirmation fails', '', (string) $o->get_meta( '_tbb_zoho_synced' ) );
$ok( 'the sales order id is kept for the retry', 0 === strpos( (string) $o->get_meta( '_tbb_zoho_sales_order_id' ), '777000' ) );
$ok( 'a retry is queued', $pending( 'tbb_zoho_sync_order', [ 'order_id' => $id2 ] ) );
$state['confirm_fails'] = false;
( new OrderSync() )->sync_order( $id2 );
$is( 'the retry completes the sync', 'yes', (string) wc_get_order( $id2 )->get_meta( '_tbb_zoho_synced' ) );
$is( 'and still only one sales order exists', 1, $state['salesorders'] );

echo "\nOrders: an email with a plus sign (ordinary Gmail plus-addressing)\n";
$reset();
$id3 = $mk_order( 'jo+shop@example.com' );
( new OrderSync() )->sync_order( $id3 );
$lookup = array_values( array_filter( $state['calls'], fn( $c ) => 'GET' === $c['method'] && '/contacts' === $c['path'] ) )[0] ?? null;
$ok( 'the lookup percent-encodes the plus', null !== $lookup && false !== strpos( $lookup['query'], 'jo%2Bshop%40example.com' ) );
$ok( 'and never sends a bare plus, which Zoho reads as a space', null !== $lookup && false === strpos( $lookup['query'], 'jo+shop' ) );

echo "\nOrders: an item Zoho does not have fails the whole order\n";
$reset();
$state['items'] = [];
$id4            = $mk_order( 'unknown@example.com' );
( new OrderSync() )->sync_order( $id4 );
$is( 'no sales order is created', 0, $state['salesorders'] );
$ok( 'the order says it was not sent', (bool) preg_grep( '/^Not sent to Zoho/', $notes( $id4 ) ) );
$ok( 'no retry is queued, since retrying cannot help', ! $pending( 'tbb_zoho_sync_order', [ 'order_id' => $id4 ] ) );
$live_off();

// ================================================================== status

echo "\nStatus: a failed sync must not erase the last good report\n";
$was_report  = get_option( StockSync::REPORT_OPTION );
$was_failure = get_option( StockSync::FAILURE_OPTION );
$good        = [
	'started'        => time() - 600,
	'finished'       => time() - 590,
	'zoho_items'     => 1051,
	'checked'        => 784,
	'matched'        => 783,
	'unmatched'      => [ 'M710_BLK_2XL' ],
	'stock_updated'  => 5,
	'status_updated' => 0,
	'price_updated'  => 0,
	'bulk_updated'   => 0,
	'stock_managed'  => 0,
];
update_option( StockSync::REPORT_OPTION, $good, false );
delete_option( StockSync::FAILURE_OPTION );

$sync        = new StockSync();
$fail_method = new ReflectionMethod( $sync, 'fail' );
$fail_method->setAccessible( true );
$fail_method->invoke( $sync, 'item fetch failed: Zoho unreachable' );

$is( 'the last good report is untouched', $good, get_option( StockSync::REPORT_OPTION ) );
$is( 'the failure is recorded separately', 'item fetch failed: Zoho unreachable', get_option( StockSync::FAILURE_OPTION )['message'] ?? null );
$text = $status_text();
$ok( 'the screen leads with the failure', false !== strpos( $text, 'Failed' ) && false !== strpos( $text, 'item fetch failed: Zoho unreachable' ) );
$ok( 'the old figures are labelled as the last successful run', false !== strpos( $text, 'Last successful run' ) );
$ok( 'the previous unmatched SKU is still shown', false !== strpos( $text, 'M710_BLK_2XL' ) );
$ok( 'it does not claim every product is linked', false === strpos( $text, 'every product is linked' ) );

update_option(
	StockSync::REPORT_OPTION,
	[
		'started'  => time(),
		'finished' => time(),
		'error'    => 'old-style failure',
	],
	false
);
delete_option( StockSync::FAILURE_OPTION );
$text = $status_text();
$ok( 'a report written the old way, holding only an error, reads as a failure', false !== strpos( $text, 'old-style failure' ) );
$ok( 'and does not show empty figures as results', false === strpos( $text, '0 checked' ) && false === strpos( $text, 'every product is linked' ) );

delete_option( StockSync::REPORT_OPTION );
$fail_method->invoke( $sync, 'first ever run failed' );
$ok( 'a failure with no earlier report still renders', false !== strpos( $status_text(), 'first ever run failed' ) );

// ================================================================== webhook

echo "\nWebhook: a notification queues a job and calls Zoho for nothing\n";
$reset();
$product = new WC_Product_Simple();
$product->set_name( 'Regression webhook product' );
$product->set_sku( 'TBB-TEST-WEBHOOK' );
$product->set_regular_price( '10' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 50 );
$product->save();
$state['items']['TBB-TEST-WEBHOOK'] = [
	'item_id'            => '7770000000000999999',
	'sku'                => 'TBB-TEST-WEBHOOK',
	'available_for_sale' => 7,
	'stock_on_hand'      => 20,
];

$hit    = function ( ?string $secret, array $body ) {
	$r = new WP_REST_Request( 'POST', '/tbb/v1/zoho-stock' );
	if ( null !== $secret ) { $r->set_header( 'X-Zoho-Webhook-Secret', $secret ); }
	$r->set_header( 'Content-Type', 'application/json' );
	$r->set_body( wp_json_encode( $body ) );

	return rest_do_request( $r );
};
$secret = Settings::get_webhook_secret();
$args   = [ 'sku' => 'TBB-TEST-WEBHOOK' ];

$state['calls'] = [];
$res            = $hit(
	$secret,
	[
		'sku'           => 'TBB-TEST-WEBHOOK',
		'stock_on_hand' => 99999,
	]
);
$is( 'answers 202 Accepted', 202, $res->get_status() );
$is( 'makes no Zoho call inside the request', 0, count( $state['calls'] ) );
$ok( 'a job is queued', $pending( StockWebhook::JOB, $args ) );
$is( 'the stock is not touched yet', 50, (int) wc_get_product( $product->get_id() )->get_stock_quantity() );

$res = $hit( $secret, $args );
$is( 'a repeat for the same SKU is still 202', 202, $res->get_status() );
global $wpdb;
$queued = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook=%s AND status='pending' AND args LIKE %s", StockWebhook::JOB, '%TBB-TEST-WEBHOOK%' ) );
$is( 'and collapses into one queued job', 1, $queued );

$is( 'an unknown SKU is 404 and queues nothing', 404, $hit( $secret, [ 'sku' => 'NO-SUCH-SKU-AT-ALL' ] )->get_status() );
$is( 'a missing SKU is 400', 400, $hit( $secret, [] )->get_status() );
$is( 'a wrong secret is 401', 401, $hit( 'wrong-secret', $args )->get_status() );
$is( 'no secret is 401', 401, $hit( null, $args )->get_status() );

do_action( StockWebhook::JOB, 'TBB-TEST-WEBHOOK' );
$is( 'the job publishes available_for_sale, not the payload and not stock_on_hand', 7, (int) wc_get_product( $product->get_id() )->get_stock_quantity() );

// ================================================================== client

echo "\nClient: a rejected token is replaced and the call retried\n";
$client = new Client();
$call   = new ReflectionMethod( $client, 'request' );
$call->setAccessible( true );

$reset();
set_transient(
	Client::TOKEN_TRANSIENT,
	[
		'access_token' => 'bad-token',
		'api_domain'   => 'https://www.zohoapis.com',
	],
	3000
);
$r      = $call->invoke( $client, 'GET', '/organizations/1' );
$tokens = count( array_filter( $state['calls'], fn( $c ) => 'TOKEN' === $c['method'] ) );
$api    = count( array_filter( $state['calls'], fn( $c ) => 'TOKEN' !== $c['method'] ) );
$ok( 'the call succeeds', ! is_wp_error( $r ) );
$is( 'one fresh token was fetched', 1, $tokens );
$is( 'the call was made twice, once rejected and once accepted', 2, $api );
$is( 'the bad token is no longer cached', 'fresh-token', get_transient( Client::TOKEN_TRANSIENT )['access_token'] ?? null );

$reset();
$state['always_401'] = true;
$r                   = $call->invoke( $client, 'GET', '/organizations/1' );
$api                 = count( array_filter( $state['calls'], fn( $c ) => 'TOKEN' !== $c['method'] ) );
$ok( 'a token that is refused even when fresh still returns an error', is_wp_error( $r ) );
$is( 'and the retry happens once, not in a loop', 2, $api );

echo "\nClient: a failing token refresh is not hammered\n";
$reset();
delete_transient( Client::TOKEN_TRANSIENT );
$state['token_fails'] = true;
for ( $i = 0; $i < 3; $i++ ) { $call->invoke( $client, 'GET', '/organizations/1' ); }
$tokens = count( array_filter( $state['calls'], fn( $c ) => 'TOKEN' === $c['method'] ) );
$is( 'three requests reach the token endpoint once', 1, $tokens );
$ok( 'the later ones are told why', is_wp_error( $call->invoke( $client, 'GET', '/organizations/1' ) ) );
delete_transient( Client::TOKEN_FAILURE_TRANSIENT );
$state['token_fails'] = false;
$r                    = $call->invoke( $client, 'GET', '/organizations/1' );
$ok( 'once the back-off is cleared, a refresh works again', ! is_wp_error( $r ) );
$ok( 'and a successful refresh leaves no back-off behind', false === get_transient( Client::TOKEN_FAILURE_TRANSIENT ) );

// ------------------------------------------------------------------ tidy up

remove_filter( 'pre_http_request', $mock, 10 );
remove_filter( 'pre_option_' . Settings::OPTION, $live_filter );

foreach ( $made_orders as $oid ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE args LIKE %s", '%' . $oid . '%' ) );
	$order = wc_get_order( $oid );
	if ( $order ) { $order->delete( true ); }
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE args LIKE %s", '%TBB-TEST-WEBHOOK%' ) );
$product->delete( true );

if ( $was_report ) { update_option( StockSync::REPORT_OPTION, $was_report, false );
} else { delete_option( StockSync::REPORT_OPTION ); }
if ( $was_failure ) { update_option( StockSync::FAILURE_OPTION, $was_failure, false );
} else { delete_option( StockSync::FAILURE_OPTION ); }

delete_option( Client::DRY_RUN_LOG );
delete_transient( Client::TOKEN_TRANSIENT );          // the mock's token must not outlive the test
delete_transient( Client::TOKEN_FAILURE_TRANSIENT );

printf( "\n%d passed, %d failed\n", $pass, $fail );
if ( $fails ) { foreach ( $fails as $f ) { echo "  - $f\n"; }
}
