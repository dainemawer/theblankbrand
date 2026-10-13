<?php
/**
 * Template Tags
 *
 * @package BlankBrandTheme
 *
 * This file contains **only** pure functions that relate to templating.
 *
 * Rules:
 * - Functions in this file **must be pure** (i.e., they must not cause side effects).
 * - No hooks, filters, or global state modifications should be added here.
 * - If a function has side effects (e.g., enqueuing scripts, modifying post data, adding filters),
 *   it should be encapsulated within a class in the `src/` directory.
 *
 * A pure function:
 * - Given the same input, it always returns the same output.
 * - Does not modify external state (no global variables, no database writes, etc.).
 * - Does not rely on WordPress hooks or filters.
 *
 * Example of an allowed function:
 * ```php
 * function get_custom_excerpt( string $content, int $length = 50 ): string {
 *     return wp_trim_words( $content, $length );
 * }
 * ```
 *
 * Example of a function **that does not belong here**:
 * ```php
 * function modify_post_title( string $title ): string {
 *     return 'My Great ' . $title;
 * }
 * add_filter( 'the_title', 'modify_post_title' );
 * ```
 *
 * Keeping this file limited to pure functions ensures maintainability and a clear separation of concerns.
 */

namespace TenupTheme;

/**
 * The cart count badge in the header.
 *
 * Pure: the same count always gives the same markup. It lives here, and not in
 * a module class, because the header template calls it and this file loads even
 * when vendor/ is missing, whereas the module classes need the framework from
 * vendor/. Used by the header and by the cart fragment, so the two cannot
 * differ.
 *
 * The badge is always rendered. A cart fragment can only replace an element that
 * is already on the page, so leaving it out for an empty cart meant the count
 * never appeared after the first item was added. The stylesheet hides it while
 * data-count is 0.
 *
 * @param int $count Items in the cart.
 * @return string Markup, already escaped.
 */
function cart_count_html( int $count ): string {
	return sprintf(
		'<span class="site-header__cart-count" data-count="%1$d" aria-live="polite" aria-atomic="true">%1$d<span class="screen-reader-text"> %2$s</span></span>',
		$count,
		esc_html( _n( 'item in cart', 'items in cart', $count, 'blank-brand-theme' ) )
	);
}
