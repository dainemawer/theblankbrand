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

const MENU_TOGGLE_SEL  = '.site-header__menu-toggle';
const NAV_CLOSE_SEL    = '.site-header__nav-close';
const BACKDROP_SEL     = '.site-header__backdrop';
const ACTIVE_CLASS     = 'is-active';
const SCROLL_LOCK_CLASS = 'nav-drawer-open';
const DESKTOP_MQ       = '(min-width: 68.75em)';
const FOCUSABLE_SEL    = 'a[href], button:not([disabled])';

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

/**
 * Mobile navigation drawer.
 *
 * Below the desktop breakpoint the primary nav is presented as an
 * off-canvas drawer toggled by the hamburger button. This adds the
 * modal-style behaviour expected for an overlay: focus is moved into the
 * drawer, trapped while open, restored on close, Escape and the backdrop
 * close it, and background scroll is locked.
 */
function initMobileNav(): void {
	const toggle   = document.querySelector<HTMLButtonElement>( MENU_TOGGLE_SEL );
	const nav      = document.querySelector<HTMLElement>( NAV_SELECTOR );
	const closeBtn = document.querySelector<HTMLButtonElement>( NAV_CLOSE_SEL );
	const backdrop = document.querySelector<HTMLElement>( BACKDROP_SEL );

	if ( ! toggle || ! nav || ! backdrop ) return;

	const desktopMql = window.matchMedia( DESKTOP_MQ );

	function isOpen(): boolean {
		return toggle!.getAttribute( 'aria-expanded' ) === 'true';
	}

	/** Visible, focusable elements inside the drawer (closed sub-menus are hidden). */
	function getFocusable(): HTMLElement[] {
		return Array.from(
			nav!.querySelectorAll<HTMLElement>( FOCUSABLE_SEL )
		).filter( ( el ) => el.offsetParent !== null );
	}

	function openDrawer(): void {
		nav!.classList.add( ACTIVE_CLASS );
		backdrop!.removeAttribute( 'hidden' );
		toggle!.setAttribute( 'aria-expanded', 'true' );
		document.body.classList.add( SCROLL_LOCK_CLASS );

		// Move focus into the drawer (close button, falling back to first item).
		( closeBtn ?? getFocusable()[ 0 ] )?.focus();
	}

	function closeDrawer( restoreFocus = true ): void {
		nav!.classList.remove( ACTIVE_CLASS );
		backdrop!.setAttribute( 'hidden', '' );
		toggle!.setAttribute( 'aria-expanded', 'false' );
		document.body.classList.remove( SCROLL_LOCK_CLASS );

		if ( restoreFocus ) toggle!.focus();
	}

	// Open / close via the hamburger.
	toggle.addEventListener( 'click', () => {
		isOpen() ? closeDrawer() : openDrawer();
	} );

	// Dedicated close affordances.
	closeBtn?.addEventListener( 'click', () => closeDrawer() );
	backdrop.addEventListener( 'click', () => closeDrawer() );

	// Escape closes and returns focus to the toggle.
	document.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Escape' && isOpen() ) {
			closeDrawer();
		}
	} );

	// Trap focus inside the drawer while it is open.
	document.addEventListener( 'keydown', ( e ) => {
		if ( e.key !== 'Tab' || ! isOpen() ) return;

		const items = getFocusable();
		if ( ! items.length ) return;

		const first = items[ 0 ];
		const last  = items[ items.length - 1 ];
		const active = document.activeElement;

		if ( e.shiftKey && active === first ) {
			e.preventDefault();
			last.focus();
		} else if ( ! e.shiftKey && active === last ) {
			e.preventDefault();
			first.focus();
		} else if ( active instanceof Node && ! nav!.contains( active ) ) {
			// Focus escaped the drawer entirely — pull it back.
			e.preventDefault();
			first.focus();
		}
	} );

	// Crossing into the desktop layout removes the drawer: tidy up state
	// so the page isn't left scroll-locked or with stale ARIA.
	desktopMql.addEventListener( 'change', ( e ) => {
		if ( e.matches && isOpen() ) {
			closeDrawer( false );
		}
	} );
}

export { initNavigation, initMinicart, initMobileNav };
