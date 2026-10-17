<?php
/**
 * Plugin Name:  The Blank Brand — Hardening
 * Description:  Keeps usernames away from visitors and adds standard security headers.
 * Version:      1.0.0
 * Requires PHP: 8.2
 *
 * Two small jobs, kept apart from the theme so a theme switch cannot undo them.
 *
 * 1. Usernames. WordPress lists them to anyone: the REST users route, the
 *    ?author=1 redirect, author archives, the user sitemap and the login form's
 *    "unknown username" message. A known username (and "admin" is the first one
 *    tried) halves the work of a password guess. Logged-out visitors no longer get
 *    any of those. Logged-in users are untouched, because the block editor and the
 *    admin need the users route.
 * 2. Headers. Clickjacking protection, a referrer policy that stops full URLs
 *    (order links carry a key) reaching other sites, HTTPS-only for returning
 *    browsers, and switching off sensors the shop never uses.
 *
 * Deliberately not here: a Content-Security-Policy. The checkout loads Paystack and
 * the site loads Google tools, and a policy that blocks one of them breaks payment
 * with no obvious error. It needs its own careful pass.
 *
 * @package TBB\Hardening
 */

namespace TBB\Hardening;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hardening hooks.
 */
class Hardening {

	/**
	 * Error codes WordPress uses for a wrong username, password or email at login.
	 */
	const LOGIN_ERROR_CODES = [ 'invalid_username', 'incorrect_password', 'invalid_email' ];

	/**
	 * The referrer policy, sent as a header and as a meta tag. The host's front layer
	 * replaces the header with its own (no-referrer-when-downgrade), which still sends
	 * the full URL, order key included, to other sites on HTTPS. Browsers honour the
	 * meta tag over the header, so the tag is what makes it stick.
	 */
	const REFERRER_POLICY = 'strict-origin-when-cross-origin';

	/**
	 * Seconds browsers are told to insist on HTTPS: 180 days. Short of a year on
	 * purpose, since this cannot be undone from the server once a browser has it.
	 */
	const HSTS_MAX_AGE = 15552000;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rest_endpoints', [ __CLASS__, 'hide_users_route' ] );
		add_action( 'template_redirect', [ __CLASS__, 'send_authors_home' ], 1 );
		add_filter( 'wp_sitemaps_add_provider', [ __CLASS__, 'drop_user_sitemap' ], 10, 2 );
		add_filter( 'login_errors', [ __CLASS__, 'generic_login_error' ] );

		add_action( 'send_headers', [ __CLASS__, 'send_security_headers' ] );
		add_action( 'login_init', [ __CLASS__, 'send_security_headers' ] );

		add_action( 'wp_head', [ __CLASS__, 'print_referrer_meta' ], 1 );
		add_action( 'login_head', [ __CLASS__, 'print_referrer_meta' ], 1 );
	}

	/**
	 * Take the users routes away for visitors who are not logged in.
	 *
	 * @param array $endpoints Registered REST routes.
	 * @return array
	 */
	public static function hide_users_route( $endpoints ) {
		if ( is_user_logged_in() || ! is_array( $endpoints ) ) {
			return $endpoints;
		}

		foreach ( array_keys( $endpoints ) as $route ) {
			if ( preg_match( '#^/wp/v2/users(/|$)#', (string) $route ) ) {
				unset( $endpoints[ $route ] );
			}
		}

		return $endpoints;
	}

	/**
	 * Send visitors who ask for an author page, or for ?author=, to the home page.
	 *
	 * Without this, /?author=1 redirects to /author/<username>/, which names the user.
	 *
	 * @return void
	 */
	public static function send_authors_home(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only: checks whether the parameter exists.
		$asked_by_id = isset( $_GET['author'] );

		if ( is_user_logged_in() || ! ( $asked_by_id || is_author() ) ) {
			return;
		}

		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}

	/**
	 * Leave the users provider out of the XML sitemap, which lists every author URL.
	 *
	 * @param object|false $provider Sitemap provider.
	 * @param string       $name     Provider name.
	 * @return object|false
	 */
	public static function drop_user_sitemap( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	}

	/**
	 * Give one message for a wrong username and a wrong password.
	 *
	 * Other errors, such as a lockout or a missing field, are left as they are.
	 *
	 * @param string $error The login error HTML.
	 * @return string
	 */
	public static function generic_login_error( $error ) {
		global $errors;

		if ( $errors instanceof \WP_Error && array_intersect( self::LOGIN_ERROR_CODES, $errors->get_error_codes() ) ) {
			return '<strong>' . esc_html__( 'Error:', 'tbb-hardening' ) . '</strong> ' . esc_html__( 'The username or password is incorrect.', 'tbb-hardening' );
		}

		return $error;
	}

	/**
	 * The headers to send, as name => value.
	 *
	 * @return array<string,string>
	 */
	public static function headers(): array {
		$headers = [
			'X-Frame-Options'         => 'SAMEORIGIN',
			// The host drops X-Frame-Options before it reaches visitors, so say the same
			// thing in the header that replaced it. frame-ancestors only limits who may
			// frame these pages; it cannot block Paystack or the Google tools.
			'Content-Security-Policy' => "frame-ancestors 'self'",
			'Referrer-Policy'         => self::REFERRER_POLICY,
			'Permissions-Policy'      => 'camera=(), microphone=(), geolocation=()',
		];

		if ( is_ssl() ) {
			$headers['Strict-Transport-Security'] = 'max-age=' . (int) apply_filters( 'tbb_hsts_max_age', self::HSTS_MAX_AGE );
		}

		/**
		 * Filter the security headers. Return an empty array to send none.
		 *
		 * @param array<string,string> $headers Header name => value.
		 */
		return (array) apply_filters( 'tbb_security_headers', $headers );
	}

	/**
	 * Send the security headers.
	 *
	 * @return void
	 */
	public static function send_security_headers(): void {
		if ( headers_sent() ) {
			return;
		}

		foreach ( self::headers() as $name => $value ) {
			// A Content-Security-Policy is added beside any other one, never in place of it:
			// two policies both apply, whereas replacing one would silently drop a stricter
			// policy another plugin set.
			header( $name . ': ' . $value, 'Content-Security-Policy' !== $name );
		}
	}

	/**
	 * Print the referrer policy as a meta tag in the page head.
	 *
	 * @return void
	 */
	public static function print_referrer_meta(): void {
		printf( '<meta name="referrer" content="%s" />' . "\n", esc_attr( self::REFERRER_POLICY ) );
	}
}

Hardening::register();
