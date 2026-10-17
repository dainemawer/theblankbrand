<?php
/**
 * Tests for the hardening plugin.
 *
 *   wp eval-file wp-content/mu-plugins/tbb-hardening/tests/hardening-test.php
 *
 * Checks it over real HTTP against the site it runs on, so it needs a site that can
 * reach itself. Read only: it creates nothing.
 *
 * @package TBB\Hardening
 */

// These are command-line scripts, run with `wp eval-file`. They print to a terminal
// rather than render a page, so the rules written for web output do not apply.
// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.ValidVariableName, WordPress.PHP.DevelopmentFunctions, WordPress.WP.AlternativeFunctions, WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput, WordPress.WP.GlobalVariablesOverride, Universal.Operators.DisallowShortTernary

if ( ! defined( 'ABSPATH' ) ) { exit; }

use TBB\Hardening\Hardening;

if ( 'local' !== wp_get_environment_type() && ! defined( 'TBB_ALLOW_TESTS' ) ) {
	echo "REFUSING TO RUN: it makes requests to this site and is for a local copy.\n";
	return;
}

$pass  = 0;
$fail  = 0;
$fails = [];

$is  = function ( string $label, $expected, $actual ) use ( &$pass, &$fail, &$fails ) {
	if ( $expected === $actual ) {
		++$pass;
		echo "  PASS  $label\n";
		return;
	}
	++$fail;
	$fails[] = $label;
	printf( "  FAIL  %s: expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
};
$yes = function ( string $label, bool $condition ) use ( $is ) {
	$is( $label, true, $condition );
};

$get = static function ( string $path ) {
	$r = wp_remote_get(
		home_url( $path ),
		[
			'sslverify'   => false,
			'redirection' => 0,
			'timeout'     => 40,
		]
	);
	return is_wp_error( $r ) ? [ 0, [], '' ] : [ wp_remote_retrieve_response_code( $r ), wp_remote_retrieve_headers( $r ), wp_remote_retrieve_body( $r ) ];
};

$admin = get_users(
	[
		'role'   => 'administrator',
		'number' => 1,
	]
)[0] ?? null;
$yes( 'there is an administrator to look for', null !== $admin );
$nice = $admin ? $admin->user_nicename : 'admin';

// ------------------------------------------------------------------ the users route

echo "Users route\n";
[ $code, , $body ] = $get( '/wp-json/wp/v2/users' );
$yes( 'a visitor gets no list of users', 200 !== $code && false === strpos( $body, '"slug"' ) );
$is( '  it answers "no route"', 404, $code );
[ $code, , $body ] = $get( '/wp-json/wp/v2/users/' . ( $admin ? $admin->ID : 1 ) );
$yes( 'a visitor cannot read one user either', 200 !== $code && false === strpos( $body, $nice ) );
[ $code ] = $get( '/wp-json/wp/v2/pages' );
$is( 'other public routes still work', 200, $code );
[ $code ] = $get( '/wp-json/wc/store/v1/products?per_page=1' );
$is( 'and so does the store API', 200, $code );

$routes = [
	'/wp/v2/users'               => [],
	'/wp/v2/users/(?P<id>[\d]+)' => [],
	'/wp/v2/users/me'            => [],
	'/wp/v2/posts'               => [],
	'/wc/store/v1/cart'          => [],
];
wp_set_current_user( 0 );
$out = Hardening::hide_users_route( $routes );
$is( 'for a visitor every users route goes and the others stay', [ '/wp/v2/posts', '/wc/store/v1/cart' ], array_keys( $out ) );
wp_set_current_user( $admin ? $admin->ID : 1 );
$is( 'for a logged-in user nothing is removed (the editor needs them)', array_keys( $routes ), array_keys( Hardening::hide_users_route( $routes ) ) );
wp_set_current_user( 0 );

// ------------------------------------------------------------------ author pages

echo "\nAuthor pages\n";
[ $code, $h ] = $get( '/?author=1' );
$is( '/?author=1 sends a visitor to the home page', 301, $code );
$is( '  and not to /author/<username>/', home_url( '/' ), is_object( $h ) ? $h['location'] : '' );
[ $code, $h ] = $get( '/?author=9999' );
$is( 'an author id that does not exist gives nothing away either', 301, $code );
[ $code, $h ] = $get( '/author/' . $nice . '/' );
$is( 'the author archive is closed to visitors', 301, $code );
[ $code ] = $get( '/shop/' );
$is( 'ordinary pages are not redirected', 200, $code );
[ $code ] = $get( '/?s=test' );
$is( 'search still works', 200, $code );

// The sitemap list is empty while the site discourages search engines (as it does until launch),
// so test the filter itself: it must refuse the users provider and pass every other one through.
$other = new stdClass();
$is( 'the XML sitemap provider for users is refused', false, Hardening::drop_user_sitemap( $other, 'users' ) );
$is( '  and the posts and taxonomies providers are passed through', [ $other, $other ], [ Hardening::drop_user_sitemap( $other, 'posts' ), Hardening::drop_user_sitemap( $other, 'taxonomies' ) ] );
$yes( '  and the filter is hooked up', false !== has_filter( 'wp_sitemaps_add_provider', [ Hardening::class, 'drop_user_sitemap' ] ) );

// ------------------------------------------------------------------ the login message

echo "\nLogin message\n";
$generic = '<strong>Error:</strong> The username or password is incorrect.';
foreach ( [ 'invalid_username', 'incorrect_password', 'invalid_email' ] as $c ) {
	$GLOBALS['errors'] = new WP_Error( $c, 'Original message naming the account' );
	$is( "$c gets the one generic message", $generic, Hardening::generic_login_error( 'Original message naming the account' ) );
}
$GLOBALS['errors'] = new WP_Error( 'empty_password', 'The password field is empty.' );
$is( 'other errors are left alone', 'The password field is empty.', Hardening::generic_login_error( 'The password field is empty.' ) );
$GLOBALS['errors'] = new WP_Error( 'wfls_lockout', 'Too many attempts.' );
$is( 'so is a lockout from a security plugin', 'Too many attempts.', Hardening::generic_login_error( 'Too many attempts.' ) );
unset( $GLOBALS['errors'] );

// ------------------------------------------------------------------ the headers

echo "\nSecurity headers\n";
[ $code, $h ] = $get( '/' );
$is( 'the home page loads', 200, $code );
$is( 'it says it cannot be framed by other sites', 'SAMEORIGIN', is_object( $h ) ? (string) $h['x-frame-options'] : '' );
$is( 'it stops full URLs reaching other sites', 'strict-origin-when-cross-origin', is_object( $h ) ? (string) $h['referrer-policy'] : '' );
$yes( 'it switches off the camera, microphone and location', is_object( $h ) && false !== strpos( (string) $h['permissions-policy'], 'camera=()' ) && false !== strpos( (string) $h['permissions-policy'], 'geolocation=()' ) );
$yes( 'over HTTPS it asks browsers to insist on HTTPS', is_object( $h ) && 0 === strpos( (string) $h['strict-transport-security'], 'max-age=15552000' ) );
[ $code, $h ] = $get( '/wp-login.php' );
$yes( 'the login page carries the headers too', 200 === $code && is_object( $h ) && 'SAMEORIGIN' === (string) $h['x-frame-options'] && 'strict-origin-when-cross-origin' === (string) $h['referrer-policy'] );

$old_https = $_SERVER['HTTPS'] ?? null;
unset( $_SERVER['HTTPS'] );
$plain = Hardening::headers();
$yes( 'over plain HTTP no HSTS is sent (it would be ignored and is meaningless)', ! isset( $plain['Strict-Transport-Security'] ) );
$_SERVER['HTTPS'] = 'on';
$yes( 'over HTTPS it is sent', isset( Hardening::headers()['Strict-Transport-Security'] ) );
add_filter( 'tbb_hsts_max_age', fn() => 60 );
$is( 'and its lifetime can be changed', 'max-age=60', Hardening::headers()['Strict-Transport-Security'] );
remove_all_filters( 'tbb_hsts_max_age' );
add_filter( 'tbb_security_headers', '__return_empty_array' );
$is( 'and every header can be switched off in one place', [], Hardening::headers() );
remove_all_filters( 'tbb_security_headers' );
if ( null === $old_https ) { unset( $_SERVER['HTTPS'] );
} else { $_SERVER['HTTPS'] = $old_https; }

// ------------------------------------------------------------------ nothing important broke

echo "\nThe shop still works\n";
foreach ( [
	'/'                                 => 200,
	'/shop/'                            => 200,
	'/cart/'                            => 200,
	'/product/womens-boxy-tee/'         => 200,
	'/wp-login.php'                     => 200,
	'/?wc-ajax=get_refreshed_fragments' => 200,
	'/no-such-page-' . wp_rand() . '/'  => 404,
] as $path => $want ) {
	[ $code ] = $get( $path );
	$is( "$path answers $want", $want, $code );
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
if ( $fails ) {
	foreach ( $fails as $f ) {
		echo "  - $f\n";
	}
}
