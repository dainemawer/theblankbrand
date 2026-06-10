/**
 * Primary navigation — dropdown behaviour.
 *
 * Progressive enhancement: dropdowns are already functional via CSS
 * :hover / :focus-within when JS is unavailable (html.no-js). This
 * module replaces that with explicit ARIA-controlled show/hide once JS
 * has loaded, giving full keyboard and screen-reader support.
 */

const OPEN_CLASS       = 'is-open';
const NAV_SELECTOR     = '#site-navigation';
const TOGGLE_SEL       = '.nav-dropdown-toggle';
const PARENT_SEL       = '.menu-item-has-children';
const CART_TOGGLE_SEL  = '.site-header__cart-toggle';
const CART_PANEL_SEL   = '#site-header-minicart';

function initNavigation(): void {
	const nav = document.querySelector<HTMLElement>( NAV_SELECTOR );
	if ( ! nav ) return;

	const toggles = Array.from(
		nav.querySelectorAll<HTMLButtonElement>( TOGGLE_SEL )
	);

	if ( ! toggles.length ) return;

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	function getParent( toggle: HTMLButtonElement ): HTMLElement | null {
		return toggle.closest<HTMLElement>( PARENT_SEL );
	}

	function openDropdown( toggle: HTMLButtonElement ): void {
		const parent = getParent( toggle );
		if ( ! parent ) return;
		parent.classList.add( OPEN_CLASS );
		toggle.setAttribute( 'aria-expanded', 'true' );
	}

	function closeDropdown( toggle: HTMLButtonElement ): void {
		const parent = getParent( toggle );
		if ( ! parent ) return;
		parent.classList.remove( OPEN_CLASS );
		toggle.setAttribute( 'aria-expanded', 'false' );
	}

	function closeAll( except?: HTMLButtonElement ): void {
		toggles.forEach( ( t ) => {
			if ( t !== except ) closeDropdown( t );
		} );
	}

	function isOpen( toggle: HTMLButtonElement ): boolean {
		return toggle.getAttribute( 'aria-expanded' ) === 'true';
	}

	// ------------------------------------------------------------------
	// Toggle on click
	// ------------------------------------------------------------------

	toggles.forEach( ( toggle ) => {
		toggle.addEventListener( 'click', () => {
			if ( isOpen( toggle ) ) {
				closeDropdown( toggle );
			} else {
				closeAll( toggle );
				openDropdown( toggle );
			}
		} );
	} );

	// ------------------------------------------------------------------
	// Close on outside click
	// ------------------------------------------------------------------

	document.addEventListener( 'click', ( e ) => {
		if ( ! ( e.target as Element ).closest( PARENT_SEL ) ) {
			closeAll();
		}
	} );

	// ------------------------------------------------------------------
	// Keyboard: Escape closes the open dropdown and returns focus
	// ------------------------------------------------------------------

	nav.addEventListener( 'keydown', ( e ) => {
		if ( e.key !== 'Escape' ) return;

		const openToggle = toggles.find( isOpen );
		if ( openToggle ) {
			closeDropdown( openToggle );
			openToggle.focus();
			e.stopPropagation();
		}
	} );

	// ------------------------------------------------------------------
	// Keyboard: close when Tab moves focus outside a sub-menu
	// ------------------------------------------------------------------

	nav.addEventListener( 'focusout', ( e ) => {
		const related = e.relatedTarget as Element | null;

		toggles.forEach( ( toggle ) => {
			const parent = getParent( toggle );
			if ( parent && ! parent.contains( related ) ) {
				closeDropdown( toggle );
			}
		} );
	} );

	// ------------------------------------------------------------------
	// Keyboard: ↑ / ↓ arrow navigation inside an open sub-menu
	// ------------------------------------------------------------------

	nav.addEventListener( 'keydown', ( e ) => {
		if ( ! [ 'ArrowDown', 'ArrowUp' ].includes( e.key ) ) return;

		const openParent = nav.querySelector<HTMLElement>(
			`${ PARENT_SEL }.${ OPEN_CLASS }`
		);
		if ( ! openParent ) return;

		const items = Array.from(
			openParent.querySelectorAll<HTMLElement>( '.sub-menu a' )
		);
		if ( ! items.length ) return;

		const focused = document.activeElement as HTMLElement;
		const index   = items.indexOf( focused );

		e.preventDefault();

		if ( e.key === 'ArrowDown' ) {
			( items[ index + 1 ] ?? items[ 0 ] ).focus();
		} else {
			( items[ index - 1 ] ?? items[ items.length - 1 ] ).focus();
		}
	} );
}

/**
 * Minicart toggle.
 */
function initMinicart(): void {
	const toggle = document.querySelector<HTMLButtonElement>( CART_TOGGLE_SEL );
	const panel  = document.querySelector<HTMLElement>( CART_PANEL_SEL );

	if ( ! toggle || ! panel ) return;

	function openCart(): void {
		panel!.removeAttribute( 'hidden' );
		toggle!.setAttribute( 'aria-expanded', 'true' );
		// Move focus into the panel for screen readers
		panel!.focus();
	}

	function closeCart(): void {
		panel!.setAttribute( 'hidden', '' );
		toggle!.setAttribute( 'aria-expanded', 'false' );
	}

	function isCartOpen(): boolean {
		return toggle!.getAttribute( 'aria-expanded' ) === 'true';
	}

	toggle.addEventListener( 'click', () => {
		isCartOpen() ? closeCart() : openCart();
	} );

	// Close on outside click
	document.addEventListener( 'click', ( e ) => {
		if (
			! toggle.contains( e.target as Node ) &&
			! panel.contains( e.target as Node )
		) {
			closeCart();
		}
	} );

	// Close on Escape
	document.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Escape' && isCartOpen() ) {
			closeCart();
			toggle.focus();
		}
	} );

	// Close when Tab moves focus outside the panel
	panel.addEventListener( 'focusout', ( e ) => {
		const related = e.relatedTarget as Node | null;
		if ( related && ! panel.contains( related ) && ! toggle.contains( related ) ) {
			closeCart();
		}
	} );

	// Make panel focusable so we can move focus into it
	if ( ! panel.hasAttribute( 'tabindex' ) ) {
		panel.setAttribute( 'tabindex', '-1' );
	}
}

export { initNavigation, initMinicart };
