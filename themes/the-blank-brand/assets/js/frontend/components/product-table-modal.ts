/**
 * Variations table in a modal.
 *
 * The bulk-variations table is wide (a column per size) and cramps the product
 * summary. This relocates it into a native <dialog> opened by a trigger button,
 * giving it room and a horizontal scroll while keeping the page tidy.
 *
 * The bulk grid's footer (items / total / add-to-cart) lives inside the plugin's
 * `form.wcbvp-cart`, and the bulk add-to-cart is a real form submit — so we move
 * the WHOLE form into the dialog (not just `.wcbvp-total-wrapper`), keeping the
 * pool inputs and submit button form-associated. The live nodes are *moved* (not
 * cloned) to preserve the plugin's event bindings and submission.
 *
 * The trigger button is placed inline with the theme's native add-to-cart row so
 * the single-variation and bulk-order actions sit together.
 *
 * Native <dialog> gives focus trapping, Escape-to-close and focus restoration
 * for free.
 */

const TABLE_SEL       = '.wc-bulk-variations-table';
const CART_FORM_SEL   = 'form.wcbvp-cart';
const ADD_TO_CART_SEL = '.variations_form .woocommerce-variation-add-to-cart';
const TRIGGER_LABEL   = 'Bulk Order Grid';
const TITLE_LABEL     = 'Bulk Order Grid';
const CLOSE_LABEL     = 'Close Bulk Order Grid';
const BULK_PREFIX     = 'Looking to order in bulk?';

const CLOSE_ICON =
	'<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>';

function initProductTableModal(): void {
	const table = document.querySelector<HTMLElement>( TABLE_SEL );
	if ( ! table || ! table.parentNode ) return;

	// Bail if <dialog> isn't supported — leave the table inline rather than
	// trapping it behind a button that can't open.
	if ( typeof HTMLDialogElement === 'undefined' ) return;

	const parent = table.parentNode;

	// Trigger button — placed inline with the native add-to-cart row if present,
	// otherwise where the table currently sits.
	const trigger = document.createElement( 'button' );
	trigger.type = 'button';
	trigger.className = 'product-table-modal__trigger';
	trigger.textContent = TRIGGER_LABEL;

	// Dialog scaffold.
	const dialog = document.createElement( 'dialog' );
	dialog.className = 'product-table-modal';

	const inner = document.createElement( 'div' );
	inner.className = 'product-table-modal__inner';

	const head = document.createElement( 'div' );
	head.className = 'product-table-modal__head';

	const title = document.createElement( 'h2' );
	title.className = 'product-table-modal__title';
	title.textContent = TITLE_LABEL;

	const closeBtn = document.createElement( 'button' );
	closeBtn.type = 'button';
	closeBtn.className = 'product-table-modal__close';
	closeBtn.setAttribute( 'aria-label', CLOSE_LABEL );
	closeBtn.innerHTML = CLOSE_ICON;

	const body = document.createElement( 'div' );
	body.className = 'product-table-modal__body';

	// Label the dialog by its heading for assistive tech.
	if ( ! title.id ) title.id = 'product-table-modal-title';
	dialog.setAttribute( 'aria-labelledby', title.id );

	head.append( title, closeBtn );
	inner.append( head, body );
	dialog.append( inner );

	// Insert the dialog where the table was, then move the table into the dialog
	// body. The table drives the grid; it isn't inside the cart form itself.
	parent.insertBefore( dialog, table );
	body.append( table );

	// Move the plugin's whole `form.wcbvp-cart` (totals, variation pool, and the
	// submit button) into the dialog footer. Moving the entire form — not just
	// `.wcbvp-total-wrapper` — keeps the pool inputs and button form-associated so
	// the bulk add-to-cart still submits.
	const cartForm = document.querySelector<HTMLElement>( CART_FORM_SEL );
	if ( cartForm ) {
		const foot = document.createElement( 'div' );
		foot.className = 'product-table-modal__foot';
		foot.append( cartForm );
		inner.append( foot );
	}

	// Place the trigger in a "bulk order" CTA directly below the native
	// add-to-cart row, falling back to the table's original position if that row
	// isn't present.
	const addToCart = document.querySelector<HTMLElement>( ADD_TO_CART_SEL );
	if ( addToCart ) {
		const cta = document.createElement( 'div' );
		cta.className = 'tbb-bulk-cta';

		const prefix = document.createElement( 'span' );
		prefix.className = 'tbb-bulk-cta__text';
		prefix.textContent = BULK_PREFIX;

		cta.append( prefix, trigger );
		addToCart.after( cta );
	} else {
		parent.insertBefore( trigger, dialog );
	}

	function open(): void {
		dialog.showModal();
		document.body.classList.add( 'has-modal-open' );
	}

	function close(): void {
		dialog.close();
	}

	trigger.addEventListener( 'click', open );
	closeBtn.addEventListener( 'click', close );

	// Click on the backdrop (the dialog element itself, outside its content).
	dialog.addEventListener( 'click', ( e ) => {
		if ( e.target === dialog ) close();
	} );

	// Native dialog restores focus to the trigger on close; just unlock scroll.
	dialog.addEventListener( 'close', () => {
		document.body.classList.remove( 'has-modal-open' );
	} );
}

export { initProductTableModal };
