/**
 * Keyboard-accessible scrollable tables.
 *
 * The `.has-table-scroll` utility lets a table overflow and scroll sideways on
 * small screens. A scrollable box of plain text can't be reached by keyboard
 * on its own (it has no focusable content), so when a table actually overflows
 * we expose its wrapper as a focusable, labelled region — letting keyboard
 * users Tab to it and arrow-scroll (WCAG 1.4.10 Reflow / 2.1.1 Keyboard).
 *
 * The attributes are added only while the wrapper overflows and removed again
 * when it doesn't (e.g. back up at desktop widths), so we never expose an empty
 * region or a focus stop that scrolls nowhere.
 */

const SELECTOR = '.has-table-scroll .wp-block-table';

/**
 * Build an accessible name for the region from a table caption, then a nearby
 * group heading, falling back to a generic label.
 */
function labelFor( wrap: HTMLElement ): string {
	const caption = wrap.querySelector( 'figcaption, caption' );
	const captionText = caption?.textContent?.trim();
	if ( captionText ) return captionText;

	const group = wrap.closest( '.wp-block-group' );
	const heading = group?.querySelector( 'h1, h2, h3, h4, h5, h6' );
	const headingText = heading?.textContent?.trim();

	return headingText ? `${ headingText } table` : 'Table';
}

function sync( wrap: HTMLElement ): void {
	// +1 absorbs sub-pixel rounding so we don't flag a false overflow.
	const overflowing = wrap.scrollWidth > wrap.clientWidth + 1;

	if ( overflowing ) {
		wrap.setAttribute( 'role', 'region' );
		wrap.setAttribute( 'tabindex', '0' );
		if ( ! wrap.getAttribute( 'aria-label' ) ) {
			wrap.setAttribute( 'aria-label', labelFor( wrap ) );
		}
	} else {
		wrap.removeAttribute( 'role' );
		wrap.removeAttribute( 'tabindex' );
		wrap.removeAttribute( 'aria-label' );
	}
}

function initScrollableTables(): void {
	const wraps = Array.from(
		document.querySelectorAll<HTMLElement>( SELECTOR )
	);
	if ( ! wraps.length ) return;

	const syncAll = (): void => wraps.forEach( sync );
	syncAll();

	let raf = 0;
	window.addEventListener( 'resize', () => {
		cancelAnimationFrame( raf );
		raf = requestAnimationFrame( syncAll );
	} );
}

export { initScrollableTables };
