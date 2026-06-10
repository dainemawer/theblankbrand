<?php
/**
 * Accessible navigation walker.
 *
 * For top-level items with children the link is kept navigable (it goes to the
 * parent page) and a separate toggle <button> is appended alongside it so that
 * keyboard/mouse users can independently open the sub-menu.
 *
 * @package BlankBrandTheme
 */

namespace BlankBrandTheme;

/**
 * Custom Walker_Nav_Menu for accessible primary navigation.
 */
class NavWalker extends \Walker_Nav_Menu {

	/**
	 * Whether the current item has children.
	 *
	 * @var bool
	 */
	private bool $item_has_children = false;

	/**
	 * Start the element output.
	 *
	 * @param string    $output Used to append additional content (passed by reference).
	 * @param \WP_Post  $item   Menu item data object.
	 * @param int       $depth  Depth of menu item.
	 * @param \stdClass $args   An object of wp_nav_menu() arguments.
	 * @param int       $id     Current item ID.
	 */
	public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
		$this->item_has_children = in_array( 'menu-item-has-children', (array) $item->classes, true );

		$indent = $depth ? str_repeat( "\t", $depth ) : '';

		// Build class list.
		$classes   = empty( $item->classes ) ? [] : (array) $item->classes;
		$classes[] = 'menu-item-' . $item->ID;

		$class_names = implode( ' ', array_filter( array_map( 'trim', $classes ) ) );
		$class_names = $class_names ? ' class="' . esc_attr( $class_names ) . '"' : '';

		// Item ID attribute.
		$id_attr = apply_filters( 'nav_menu_item_id', 'menu-item-' . $item->ID, $item, $args, $depth );
		$id_attr = $id_attr ? ' id="' . esc_attr( $id_attr ) . '"' : '';

		$output .= $indent . '<li' . $id_attr . $class_names . '>';

		// Build link attributes.
		$atts = [
			'title'  => ! empty( $item->attr_title ) ? $item->attr_title : '',
			'target' => ! empty( $item->target ) ? $item->target : '',
			'rel'    => ! empty( $item->xfn ) ? $item->xfn : '',
			'href'   => ! empty( $item->url ) ? $item->url : '',
		];

		// External links — add noopener.
		if ( '_blank' === ( $atts['target'] ?? '' ) ) {
			$atts['rel'] = trim( ( $atts['rel'] ?? '' ) . ' noopener noreferrer' );
		}

		// Current page indicator.
		$is_current = in_array( 'current-menu-item', (array) $item->classes, true );

		$atts = apply_filters( 'nav_menu_link_attributes', $atts, $item, $args, $depth );

		$attributes = '';
		foreach ( $atts as $attr => $value ) {
			if ( is_scalar( $value ) && '' !== $value && false !== $value ) {
				$value       = 'href' === $attr ? esc_url( $value ) : esc_attr( $value );
				$attributes .= ' ' . $attr . '="' . $value . '"';
			}
		}

		if ( $is_current ) {
			$attributes .= ' aria-current="page"';
		}

		$title = apply_filters( 'the_title', $item->title, $item->ID );
		$title = apply_filters( 'nav_menu_item_title', $title, $item, $args, $depth );

		$item_output  = $args->before ?? '';
		$item_output .= '<a' . $attributes . '>';
		$item_output .= ( $args->link_before ?? '' ) . $title . ( $args->link_after ?? '' );
		$item_output .= '</a>';

		// Top-level items with children: append a separate toggle button.
		// The <a> navigates; the <button> opens/closes the sub-menu.
		if ( 0 === $depth && $this->item_has_children ) {
			$label        = sprintf(
				/* translators: %s: parent menu item label */
				esc_attr__( 'Toggle %s sub-menu', 'blank-brand-theme' ),
				$title
			);
			$item_output .= '<button class="nav-dropdown-toggle" aria-expanded="false" aria-label="' . $label . '">';
			$item_output .= '<svg class="nav-dropdown-toggle__icon" width="12" height="7" viewBox="0 0 12 7" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">';
			$item_output .= '<path d="M1 1L6 6L11 1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>';
			$item_output .= '</svg>';
			$item_output .= '</button>';
		}

		$item_output .= $args->after ?? '';

		$output .= apply_filters( 'walker_nav_menu_start_el', $item_output, $item, $depth, $args );
	}

	/**
	 * Start the sub-menu list.
	 *
	 * @param string    $output Used to append additional content (passed by reference).
	 * @param int       $depth  Depth of menu item.
	 * @param \stdClass $args   An object of wp_nav_menu() arguments.
	 */
	public function start_lvl( &$output, $depth = 0, $args = null ) {
		$indent  = str_repeat( "\t", $depth );
		$output .= "\n{$indent}<ul class=\"sub-menu\" role=\"list\">\n";
	}
}
