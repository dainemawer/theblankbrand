<?php
/**
 * Regression tests for four theme defects found in review.
 *
 *   wp eval-file wp-content/themes/the-blank-brand/tests/theme-regression-test.php
 *
 *   - cart badge: it is always on the page, so WooCommerce's cart fragment
 *     (which can only replace an element that exists) can show the first item.
 *   - menu labels: a title carrying markup stays out of the aria-label.
 *   - swatches: the cheaper stock lookup gives exactly the answers the old
 *     get_available_variations() lookup did, for every product, and builds no
 *     variation payloads.
 *   - admin box: the Bulk Discounts box does not offer to edit a field the
 *     storefront ignores.
 *
 * It creates and removes a product and a menu, so it is for a local site.
 *
 * @package BlankBrandTheme
 */

// These are command-line scripts, run with `wp eval-file`. They print to a terminal
// rather than render a page and read the database directly, so the rules written
// for web output and cached queries do not apply to them.
// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.ValidVariableName, WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery, WordPress.PHP.DevelopmentFunctions, WordPress.WP.AlternativeFunctions, WordPress.WP.PostsPerPageNoUnlimited, Universal.Operators.DisallowShortTernary, Universal.NamingConventions.NoReservedKeywordParameterNames

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( 'local' !== wp_get_environment_type() && ! defined( 'TBB_ALLOW_TESTS' ) ) {
	echo "REFUSING TO RUN — this creates and deletes a product and a menu, and is for a local site.\n";
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

$ok = function ( string $label, bool $condition ) use ( $is ) {
	$is( $label, true, $condition );
};

// ------------------------------------------------------------------ cart badge

echo "Cart badge\n";

$html = BlankBrandTheme\WooCommerce::cart_count_html( 0 );
$ok( 'an empty cart still renders the badge', false !== strpos( $html, 'class="site-header__cart-count"' ) );
$ok( 'and marks it data-count="0", which the stylesheet hides', false !== strpos( $html, 'data-count="0"' ) );
$ok( 'one item is singular for screen readers', false !== strpos( BlankBrandTheme\WooCommerce::cart_count_html( 1 ), 'item in cart' ) && false === strpos( BlankBrandTheme\WooCommerce::cart_count_html( 1 ), 'items in cart' ) );
$ok( 'several items are plural', false !== strpos( BlankBrandTheme\WooCommerce::cart_count_html( 5 ), '5<span class="screen-reader-text"> items in cart' ) );

$fragments = ( new BlankBrandTheme\WooCommerce() )->cart_count_fragment( [] );
WC()->cart ?: wc_load_cart();
$is( 'the fragment is the very same markup as the header', BlankBrandTheme\WooCommerce::cart_count_html( (int) WC()->cart->get_cart_contents_count() ), $fragments['.site-header__cart-count'] ?? null );

$page = wp_remote_get(
	home_url( '/' ),
	[
		'sslverify' => false,
		'timeout'   => 25,
	]
);
$body = is_wp_error( $page ) ? '' : wp_remote_retrieve_body( $page );
$ok( 'the real page, with an empty cart, contains the badge element', false !== strpos( $body, 'class="site-header__cart-count" data-count="0"' ) );
$ok( 'and the toggle is labelled without a stale count', false !== strpos( $body, 'aria-label="Open cart"' ) && false === strpos( $body, 'Open cart. 0 items' ) );

// ------------------------------------------------------------------ menu label

echo "\nMenu labels\n";

foreach ( [ 'Men <b>new</b>', 'A <img src=x onerror=alert(1)> B', 'Men & Women' ] as $title ) {
	$mid = wp_create_nav_menu( 'regression-' . wp_rand() );
	$par = wp_update_nav_menu_item(
		$mid,
		0,
		[
			'menu-item-title'  => $title,
			'menu-item-url'    => 'https://example.com/',
			'menu-item-status' => 'publish',
			'menu-item-type'   => 'custom',
		]
	);
	wp_update_nav_menu_item(
		$mid,
		0,
		[
			'menu-item-title'     => 'Child',
			'menu-item-url'       => 'https://example.com/c',
			'menu-item-status'    => 'publish',
			'menu-item-type'      => 'custom',
			'menu-item-parent-id' => $par,
		]
	);
	$out = wp_nav_menu(
		[
			'menu'      => $mid,
			'walker'    => new BlankBrandTheme\NavWalker(),
			'echo'      => false,
			'container' => false,
		]
	);
	preg_match( '/<button[^>]*nav-dropdown-toggle[^>]*>/', $out, $button );
	preg_match( '/aria-label="([^"]*)"/', $button[0] ?? '', $label );
	$ok( 'no markup in the label for ' . json_encode( $title ), false === strpos( $label[1] ?? '<', '<' ) );
	$is( '  and the button keeps its three attributes', 3, preg_match_all( '/\s[a-z-]+="/', $button[0] ?? '' ) );
	wp_delete_nav_menu( $mid );
}

// ------------------------------------------------------------------ swatches

echo "\nSwatches\n";

$cs     = ( new ReflectionClass( BlankBrandTheme\ColourSwatches::class ) )->newInstanceWithoutConstructor();
$matrix = new ReflectionMethod( $cs, 'size_stock_matrix' );
$matrix->setAccessible( true );
$stock = new ReflectionMethod( $cs, 'colours_with_stock' );
$stock->setAccessible( true );

/**
 * The answers the old implementation gave, which built every variation's full
 * payload to learn what was in stock. Kept here as the oracle.
 */
$oracle = function ( WC_Product $product ) {
	$sizes   = wc_get_product_terms( $product->get_id(), 'pa_size', [ 'fields' => 'slugs' ] );
	$colours = wc_get_product_terms( $product->get_id(), 'pa_colour', [ 'fields' => 'slugs' ] );
	$all     = $colours ?: [ '' ];

	$matrix = null;
	if ( $sizes ) {
		$matrix = [];
		foreach ( $all as $c ) { $matrix[ $c ] = []; }
	}
	$with = $colours ? array_fill_keys( $colours, false ) : [];

	foreach ( $product->get_available_variations() as $v ) {
		if ( empty( $v['is_in_stock'] ) || empty( $v['is_purchasable'] ) ) { continue; }
		$vc = $v['attributes']['attribute_pa_colour'] ?? '';
		$vs = $v['attributes']['attribute_pa_size'] ?? '';
		if ( null !== $matrix ) {
			foreach ( ( '' !== $vc ? [ $vc ] : $all ) as $c ) {
				foreach ( ( '' !== $vs ? [ $vs ] : $sizes ) as $s ) { $matrix[ $c ][ $s ] = true; }
			}
		}
		foreach ( ( '' !== $vc ? [ $vc ] : $colours ) as $c ) { $with[ $c ] = true; }
	}

	return [
		'matrix' => $matrix,
		'with'   => $with,
	];
};

$ids = get_posts(
	[
		'post_type'   => 'product',
		'post_status' => 'publish',
		'numberposts' => -1,
		'fields'      => 'ids',
		'tax_query'   => [
			[
				'taxonomy' => 'product_type',
				'field'    => 'slug',
				'terms'    => 'variable',
			],
		],
	]
);
$ok( 'there are variable products to compare', count( $ids ) > 0 );

$mismatch = [];
foreach ( $ids as $id ) {
	$p   = wc_get_product( $id );
	$old = $oracle( $p );
	$new = [
		'matrix' => $matrix->invoke( $cs, $p ),
		'with'   => $stock->invoke( $cs, $p ),
	];
	if ( wp_json_encode( $old ) !== wp_json_encode( $new ) ) { $mismatch[] = $id; }
}
$is( sprintf( 'the stock matrix and colour map match the old answers for all %d products', count( $ids ) ), [], $mismatch );

$payloads = 0;
add_filter(
	'woocommerce_available_variation',
	function ( $v ) use ( &$payloads ) {
		++$payloads;
		return $v;
	}
);
$fresh = ( new ReflectionClass( BlankBrandTheme\ColourSwatches::class ) )->newInstanceWithoutConstructor();
$gc    = new ReflectionMethod( $fresh, 'get_colours' );
$gc->setAccessible( true );
foreach ( array_slice( $ids, 0, 12 ) as $id ) { $gc->invoke( $fresh, wc_get_product( $id ), false ); }
$is( 'a 12-card archive builds no variation payloads', 0, $payloads );

// ------------------------------------------------------------------ admin box

echo "\nBulk Discounts admin box\n";

$admin = new BlankBrandTheme\BulkDiscountsAdmin();
$pid   = get_page_by_path( 'womens-boxy-tee', OBJECT, 'product' )->ID;
$orig  = get_post_meta( $pid, '_tbb_bulk_discounts', true );

ob_start();
$admin->render_metabox( get_post( $pid ) );
$box = ob_get_clean();
$ok( 'a product with structured tiers shows them read-only', false !== strpos( $box, 'cannot be edited here' ) );
$ok( 'and offers no editor for the ignored field', false === strpos( $box, 'name="tbb_bulk_discounts"' ) );
$ok( 'and says where the 50+ price comes from', false !== strpos( $box, 'Zoho' ) );

wp_set_current_user( 1 );
$_POST[ BlankBrandTheme\BulkDiscountsAdmin::NONCE ] = wp_create_nonce( BlankBrandTheme\BulkDiscountsAdmin::NONCE );
$_POST['tbb_bulk_discounts']                        = '<ul><li>1-49 items: R999</li></ul>';
$admin->save_metabox( $pid );
$is( 'a posted value is not saved over the ignored field', $orig, get_post_meta( $pid, '_tbb_bulk_discounts', true ) );

$bare = new WC_Product_Simple();
$bare->set_name( 'Untiered fixture' );
$bare->set_regular_price( '10' );
$bare->set_status( 'publish' );
$bare->save();

ob_start();
$admin->render_metabox( get_post( $bare->get_id() ) );
$box = ob_get_clean();
$ok( 'a product with no structured tiers still gets the editor, since the storefront falls back to it', false !== strpos( $box, 'name="tbb_bulk_discounts"' ) );

$_POST['tbb_bulk_discounts'] = '<ul><li>1-49 items: R10</li><li>50+ items: R9</li></ul>';
$admin->save_metabox( $bare->get_id() );
$ok( 'and what it saves is kept', false !== strpos( (string) get_post_meta( $bare->get_id(), '_tbb_bulk_discounts', true ), 'R9' ) );

$bare->delete( true );
unset( $_POST['tbb_bulk_discounts'], $_POST[ BlankBrandTheme\BulkDiscountsAdmin::NONCE ] );
wp_set_current_user( 0 );

// ------------------------------------------------------------------ QA round 1

echo "\nShop heading, order, 404 page and size hover\n";

$shop      = wp_remote_get(
	home_url( '/shop/' ),
	[
		'sslverify' => false,
		'timeout'   => 25,
	]
);
$shop_html = is_wp_error( $shop ) ? '' : wp_remote_retrieve_body( $shop );
preg_match( '/<h1[^>]*page-title[^>]*>(.*?)<\/h1>/s', $shop_html, $h1 );
$is( 'the shop page has a heading with text in it', 'Shop', trim( wp_strip_all_tags( $h1[1] ?? '' ) ) );
$is( 'and only one h1', 1, preg_match_all( '/<h1[\s>]/', $shop_html ) );

$is( 'products are listed by name', 'title', apply_filters( 'woocommerce_default_catalog_orderby', 'menu_order' ) );
preg_match_all( '/<h2 class="woocommerce-loop-product__title">(.*?)<\/h2>/s', $shop_html, $names );
$names  = array_map( static fn( $n ) => strtolower( str_replace( [ "\u{2019}", "'" ], '', html_entity_decode( wp_strip_all_tags( $n ) ) ) ), $names[1] );
$sorted = $names;
sort( $sorted, SORT_STRING );
$is( 'the first page of the shop really is in name order', $sorted, $names );

$gone      = wp_remote_get(
	home_url( '/this-page-does-not-exist-' . wp_rand() . '/' ),
	[
		'sslverify' => false,
		'timeout'   => 25,
	]
);
$gone_html = is_wp_error( $gone ) ? '' : wp_remote_retrieve_body( $gone );
$is( 'a missing page answers 404', 404, is_wp_error( $gone ) ? 0 : wp_remote_retrieve_response_code( $gone ) );
$ok( 'and shows a heading, a paragraph and a way home', 1 === preg_match( '/<h1[^>]*>Page not found<\/h1>/', $gone_html ) && false !== strpos( $gone_html, 'class="error-404__text"' ) && 1 === preg_match( '/class="error-404__home" href="' . preg_quote( home_url( '/' ), '/' ) . '">\s*Return home/', $gone_html ) );

$src = file_get_contents( get_theme_file_path( 'assets/css/frontend/layout/woocommerce.css' ) );
$ok( 'the selected size turns its text white on hover (source)', 1 === preg_match( '/\.tbb-size\.is-active:hover[^{]*\{[^}]*color:\s*var\(--wp--preset--color--primary\)/', $src ) );
$dist = file_exists( get_theme_file_path( 'dist/css/frontend.css' ) ) ? file_get_contents( get_theme_file_path( 'dist/css/frontend.css' ) ) : '';
$ok( 'and the built stylesheet carries it', false !== strpos( $dist, '.tbb-size.is-active:hover{color:var(--wp--preset--color--primary)}' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
if ( $fails ) { foreach ( $fails as $f ) { echo "  - $f\n"; }
}
