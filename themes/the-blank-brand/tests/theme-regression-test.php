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

// One price on a variable product. The behaviour itself (the price follows the chosen colour
// and size, and the lowest price returns on clear) is checked in a browser; these keep the
// three pieces it depends on from being removed.
$js = file_get_contents( get_theme_file_path( 'assets/js/colour-swatches.js' ) );
$ok( 'the price under the title follows the selected variation', false !== strpos( $js, 'summaryPrice()' ) && false !== strpos( $js, 'variation.price_html' ) );
$ok( 'and goes back to the lowest price when the choice is cleared', 1 === preg_match( '/reset_data[^{]*\{[^}]*summaryPrice\(\)[\s\S]{0,200}tbbLowestPrice/', $js ) );
$ok( 'WooCommerce\'s second copy of the price is hidden (source)', 1 === preg_match( '/form\.variations_form \.woocommerce-variation-price\s*\{\s*display:\s*none/', $src ) );
$ok( 'and in the built stylesheet', false !== strpos( $dist, 'form.variations_form .woocommerce-variation-price{display:none}' ) );

// Mini cart layout and the bulk notice. The look itself is checked in a browser; these keep
// the rules that produce it from being removed or overridden by accident.
$hdr = file_get_contents( get_theme_file_path( 'assets/css/frontend/layout/header.css' ) );
$ok( 'the mini cart thumbnail is not floated right', 1 === preg_match( '/\.woocommerce-mini-cart \.woocommerce-mini-cart-item img\s*\{[^}]*float:\s*none/', $hdr ) );
$ok( 'the mini cart item places remove button, thumbnail and details in three columns, the button on the left', 1 === preg_match( '/\.woocommerce-mini-cart-item\s*\{[^}]*grid-template-columns:\s*1\.5rem var\(--minicart-thumb\) 1fr/', $hdr ) );
$ok( '  with a square thumbnail, cropped to fit', 1 === preg_match( '/\.woocommerce-mini-cart-item img\s*\{[^}]*aspect-ratio:\s*1;[^}]*object-fit:\s*cover/', $hdr ) );
$ok( '  and room between the last item\'s rule and the subtotal', 1 === preg_match( '/\.woocommerce-mini-cart\.cart_list\s*\{[^}]*margin:\s*0 0 var\(--wp--custom--spacing--24\)/', $hdr ) );
$ok( 'the remove button is a centred square, so its hover circle is round', 1 === preg_match( '/\.woocommerce-mini-cart-item \.remove\s*\{[^}]*border-radius:\s*50%[^}]*height:\s*1\.5rem[^}]*justify-content:\s*center[^}]*width:\s*1\.5rem/s', $hdr ) );
$ok( 'on small screens the mini cart panel is anchored to the header, not the icon', 1 === preg_match( '/\.site-header__cart(,\s*\.site-header__account)?\s*\{\s*position:\s*static/', $hdr ) );
$ok( '  and so is the account menu', 1 === preg_match( '/\.site-header__cart,\s*\.site-header__account\s*\{\s*position:\s*static/', $hdr ) );
$ok( 'the bulk notice lists variations in a row, not a stacked list', 1 === preg_match( '/ul\.wcbvp-cart-variation-list\s*\{[^}]*display:\s*flex[^}]*list-style:\s*none/s', $src ) );

// Checkout terms links, and the quote limit's product page pieces.
$ok( 'the terms and privacy links on checkout are black, not the white link colour', 1 === preg_match( '/\.wc-block-checkout__terms a\s*\{[^}]*color:\s*var\(--wp--preset--color--tertiary\)/', $src ) );
$ok( 'and the built stylesheet carries it', false !== strpos( $dist, '.wc-block-checkout__terms a{color:var(--wp--preset--color--tertiary)' ) );
$quote_js = file_get_contents( get_theme_file_path( 'assets/js/quote-limit.js' ) );
$ok( 'the product page script blocks a submit over the limit', false !== strpos( $quote_js, "addEventListener(\n\t\t'submit'" ) || false !== strpos( $quote_js, "'submit'" ) );
$ok( 'and the quote notice is styled', false !== strpos( $dist, '.tbb-quote-notice' ) );

// Stylesheet versioning. The ?ver= must change when the CSS changes, even if the JavaScript bundle did not.
$tmp_a = wp_tempnam( 'css-a' );
$tmp_b = wp_tempnam( 'css-b' );
$tmp_c = wp_tempnam( 'css-c' );
file_put_contents( $tmp_a, 'a{color:#000}' );
file_put_contents( $tmp_b, 'a{color:#111}' );
file_put_contents( $tmp_c, 'a{color:#000}' );
$va = BlankBrandTheme\Assets::file_version( $tmp_a, 'fallback' );
$is( 'a stylesheet version is a short content hash', 12, strlen( $va ) );
$ok( 'a different file gets a different version', BlankBrandTheme\Assets::file_version( $tmp_b, 'fallback' ) !== $va );
$is( 'identical content gets the same version', $va, BlankBrandTheme\Assets::file_version( $tmp_c, 'fallback' ) );
$is( 'a missing file falls back to the supplied version', 'fallback', BlankBrandTheme\Assets::file_version( '/no/such/file.css', 'fallback' ) );
foreach ( [ $tmp_a, $tmp_b, $tmp_c ] as $f ) { unlink( $f ); }
$home_html = wp_remote_retrieve_body(
	wp_remote_get(
		home_url( '/' ),
		[
			'sslverify' => false,
			'timeout'   => 25,
		]
	)
);
preg_match( '/dist\/css\/frontend\.css\?ver=([0-9a-f]+)/', $home_html, $ver );
$want  = substr( md5_file( get_theme_file_path( 'dist/css/frontend.css' ) ), 0, 12 );
$asset = include get_theme_file_path( 'dist/js/frontend.asset.php' );
$is( 'the page links frontend.css with its own content hash', $want, $ver[1] ?? '' );
$ok( '  which is not the JavaScript bundle\'s hash it used to borrow', ( $ver[1] ?? '' ) !== $asset['version'] );

// ------------------------------------------------------------------ QA round 4

echo "\nLinks, the header account control and the My Account pages\n";

// Link colour. The theme's own link colour used to be white, which is invisible on every white page.
$theme_json = json_decode( (string) file_get_contents( get_theme_file_path( 'theme.json' ) ), true );
$palette    = [];
foreach ( $theme_json['settings']['color']['palette'] as $swatch ) { $palette[ $swatch['slug'] ] = ltrim( $swatch['color'], '#' ); }
$slug_of    = fn( $ref ) => preg_replace( '/^var\(--wp--preset--color--(.+)\)$/', '$1', (string) $ref );
$link       = $theme_json['styles']['elements']['link'];
$link_slug  = $slug_of( $link['color']['text'] );
$hover_slug = $slug_of( $link[':hover']['color']['text'] );
$luminance  = function ( string $hex ): float {
	$out = [];
	foreach ( str_split( $hex, 2 ) as $channel ) {
		$v     = hexdec( $channel ) / 255;
		$out[] = $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
	}
	return 0.2126 * $out[0] + 0.7152 * $out[1] + 0.0722 * $out[2];
};
$contrast   = function ( string $a, string $b ) use ( $luminance ): float {
	$la = $luminance( $a );
	$lb = $luminance( $b );
	return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
};
$white = $palette['primary'];
$ok( 'the default link colour is not white (white links vanish on the white pages)', 'primary' !== $link_slug );
$ok( 'the default link colour reads on white (at least 7:1)', $contrast( $palette[ $link_slug ], $white ) >= 7 );
$ok( 'the hover colour reads on white too (at least 4.5:1), not the pale grey it was', $contrast( $palette[ $hover_slug ], $white ) >= 4.5 );
$ok( 'the header and footer links set their own white, so they are not caught by that change', 1 === preg_match( '/site-header__menu > \.menu-item > a \{[^}]*color: var\(--wp--preset--color--primary\)/s', (string) file_get_contents( get_theme_file_path( 'assets/css/frontend/layout/header.css' ) ) ) && 1 === preg_match( '/site-footer__menu li > a \{[^}]*color: var\(--wp--preset--color--primary\)/s', (string) file_get_contents( get_theme_file_path( 'assets/css/frontend/layout/footer.css' ) ) ) );
$woo_css = (string) file_get_contents( get_theme_file_path( 'assets/css/frontend/layout/woocommerce.css' ) );
$ok( 'links inside the black notice panels are kept white', 1 === preg_match( '/woocommerce-notices-wrapper \.woocommerce-message a:not\(\.button\)[^{]*\{[^}]*color: var\(--wp--preset--color--primary\)/s', $woo_css ) );

// The account stylesheet, and that it is part of the build.
$account_css = (string) file_get_contents( get_theme_file_path( 'assets/css/frontend/layout/account.css' ) );
$ok( 'the account stylesheet is imported by the layout', false !== strpos( (string) file_get_contents( get_theme_file_path( 'assets/css/frontend/layout/index.css' ) ), '@import url("account.css")' ) );
foreach ( [
	'.woocommerce-MyAccount-navigation'      => 'the account navigation',
	'form.login'                             => 'the login form',
	'form.lost_reset_password'               => 'the lost and reset password forms',
	'.woocommerce-table__product-name a'     => 'the product name link in an order table',
	'.woocommerce-order-overview'            => 'the thank-you page overview',
	'.woocommerce-Address-title'             => 'the address headings',
] as $needle => $what ) {
	$ok( "it styles $what", false !== strpos( $account_css, $needle ) );
}
$ok( 'the product name link is a dark, bold link rather than the old white', 1 === preg_match( '/\.woocommerce-table__product-name a,[^{]*\{[^}]*color: var\(--wp--preset--color--tertiary\)[^}]*font-weight: 600/s', $account_css ) );
$ok( 'the buttons are the black pill the rest of the shop uses', 1 === preg_match( '/\.account-page \.woocommerce \.button,[^{]*\{[^}]*background-color: var\(--wp--preset--color--tertiary\)[^}]*border-radius: var\(--wp--custom--border-radius--full\)/s', $account_css ) );
$dist_css = (string) @file_get_contents( get_theme_file_path( 'dist/css/frontend.css' ) );
$ok( 'the built stylesheet carries the account styles and the header dropdown', false !== strpos( $dist_css, 'woocommerce-MyAccount-navigation' ) && false !== strpos( $dist_css, 'site-header__account-menu' ) );
$dist_js = (string) @file_get_contents( get_theme_file_path( 'dist/js/frontend.js' ) );
$ok( 'the built script knows about the account menu', false !== strpos( $dist_js, 'site-header__account-toggle' ) );

// The header as a visitor sees it (over HTTP, so it is the real page).
$page = function ( string $path ) {
	return wp_remote_retrieve_body(
		wp_remote_get(
			home_url( $path ),
			[
				'sslverify' => false,
				'timeout'   => 30,
			]
		)
	);
};
$home = $page( '/' );
$ok( 'a visitor gets a Log in link in the header', 1 === preg_match( '/<a class="site-header__account-link" href="' . preg_quote( esc_url( wc_get_page_permalink( 'myaccount' ) ), '/' ) . '">.*?Log in/s', $home ) );
$ok( '  and no account dropdown and no Log out link', false === strpos( $home, 'site-header__account-toggle' ) && false === strpos( $home, 'customer-logout' ) );

// The header for a customer: built in-process as that customer, with a name that tries to inject markup.
$evil = 'Pat <script>alert(1)</script> "Q"';
$uid  = wp_insert_user(
	[
		'user_login'   => 'tbb_header_test_' . wp_rand(),
		'user_pass'    => wp_generate_password( 24 ),
		'user_email'   => 'tbb-header-test-' . wp_rand() . '@example.com',
		'display_name' => $evil,
		'role'         => 'customer',
	]
);
$ok( 'a throwaway customer was created', ! is_wp_error( $uid ) );
if ( ! is_wp_error( $uid ) ) {
	wp_set_current_user( $uid );
	// WordPress strips tags from a display name when it saves one, so set the hostile name on the
	// loaded user (in memory only) to see what the header does with markup that gets that far.
	wp_get_current_user()->display_name = $evil;
	ob_start();
	locate_template( 'header.php', true, false );
	$head = ob_get_clean();
	preg_match( '/<div class="site-header__account">.*?<div class="site-header__cart">/s', $head, $m );
	$box = $m[0] ?? '';
	$ok( 'a customer gets the account button, closed to start with', false !== strpos( $box, 'class="site-header__account-toggle"' ) && false !== strpos( $box, 'aria-expanded="false"' ) && 1 === preg_match( '/id="site-header-account-menu"[^>]*\bhidden\b/s', $box ) );
	$ok( '  the button controls the menu and has a name for screen readers', false !== strpos( $box, 'aria-controls="site-header-account-menu"' ) && false !== strpos( $box, 'aria-label="Account menu"' ) );
	$ok( '  and no longer the Log in link', false === strpos( $box, 'site-header__account-link' ) );
	$items = wc_get_account_menu_items();
	unset( $items['customer-logout'] );
	$links = [];
	preg_match_all( '/<li>\s*<a href="([^"]+)">([^<]+)<\/a>/', $box, $mm, PREG_SET_ORDER );
	foreach ( $mm as $row ) { $links[ html_entity_decode( $row[1] ) ] = html_entity_decode( $row[2] ); }
	$want = [];
	foreach ( $items as $endpoint => $label ) { $want[ wc_get_account_endpoint_url( $endpoint ) ] = 'dashboard' === $endpoint ? 'My account' : $label; }
	$is( '  it lists every account page WooCommerce offers, My account first', $want, $links );
	$ok( '  and My account is first', array_key_first( $links ) === wc_get_account_endpoint_url( 'dashboard' ) );
	$ok( '  it ends with a Log out link, the one WooCommerce gives (it carries a nonce)', false !== strpos( $box, '<a class="site-header__account-logout" href="' . esc_url( wc_logout_url() ) . '">Log out</a>' ) && false !== strpos( wc_logout_url(), '_wpnonce=' ) );
	$ok( '  and it sends the customer back to My Account, the login page, afterwards', false !== strpos( urldecode( wc_logout_url() ), wc_get_page_permalink( 'myaccount' ) ) );
	$ok( '  the name is shown, with its markup escaped', false !== strpos( $box, 'Pat &lt;script&gt;alert(1)&lt;/script&gt; &quot;Q&quot;' ) && false === strpos( $box, '<script>alert(1)' ) );
	wp_set_current_user( 0 );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $uid );
	$ok( 'the throwaway customer was removed', false === get_userdata( $uid ) );
}

// The My Account page: a title and a container it did not have.
$login = $page( '/my-account/' );
$ok( 'the My Account page has exactly one h1', 1 === preg_match_all( '/<h1[ >]/', $login ) );
$ok( '  it is the page title, in the shop\'s heading style', 1 === preg_match( '/<h1 class="page-title">My account<\/h1>/', $login ) );
$ok( '  inside the container the page now has', 1 === preg_match( '/<div class="container u-margin-block account-page">\s*<h1/', $login ) );
$ok( '  and the login form is on it', false !== strpos( $login, 'woocommerce-form-login' ) );
$ok( 'an endpoint gets its own title (lost password)', 1 === preg_match( '/<h1 class="page-title">Lost password<\/h1>/', $page( '/my-account/lost-password/' ) ) );
$about = $page( '/about-us/' );
$ok( 'other pages are not wrapped or given a second title', false === strpos( $about, 'account-page' ) );

// The script.
$ok( 'the account menu is started with the rest of the header script', false !== strpos( (string) file_get_contents( get_theme_file_path( 'assets/js/frontend/frontend.ts' ) ), 'initAccountMenu()' ) );
$nav_ts = (string) file_get_contents( get_theme_file_path( 'assets/js/frontend/components/navigation.ts' ) );
$ok( 'the minicart and the account menu share one open/close routine', 2 === substr_count( $nav_ts, 'initTogglePanel(' ) - 1 );

printf( "\n%d passed, %d failed\n", $pass, $fail );
if ( $fails ) { foreach ( $fails as $f ) { echo "  - $f\n"; }
}
