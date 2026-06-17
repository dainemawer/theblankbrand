/**
 * Quantity stepper.
 *
 * WooCommerce renders a bare number input for the product quantity. This wraps
 * it with − / + buttons so it reads as a clear stepper alongside the add-to-cart
 * button. Skips fixed-quantity inputs (rendered as type="hidden" when only one
 * unit is purchasable) since there's nothing to step.
 */

type Direction = 'up' | 'down';

function stepValue( input: HTMLInputElement, dir: Direction ): void {
	const stepAttr = parseFloat( input.step );
	const step = isNaN( stepAttr ) ? 1 : stepAttr;
	const min = input.min !== '' ? parseFloat( input.min ) : 1;
	const max = input.max !== '' ? parseFloat( input.max ) : Infinity;

	let value = parseFloat( input.value );
	if ( isNaN( value ) ) {
		value = min;
	}

	value = 'up' === dir ? value + step : value - step;
	value = Math.min( max, Math.max( min, value ) );

	input.value = String( value );
	// Let WooCommerce (and any listeners) react to the change.
	input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
}

function makeButton( dir: Direction, label: string ): HTMLButtonElement {
	const button = document.createElement( 'button' );
	button.type = 'button';
	button.className =
		'tbb-qty-btn ' + ( 'down' === dir ? 'tbb-qty-btn--minus' : 'tbb-qty-btn--plus' );
	button.setAttribute( 'aria-label', label );
	button.textContent = 'down' === dir ? '−' : '+';
	return button;
}

function decorate( quantity: HTMLElement ): void {
	if ( 'done' === quantity.dataset.tbbStepper ) {
		return;
	}
	const input = quantity.querySelector<HTMLInputElement>( 'input.qty' );
	if ( ! input || 'hidden' === input.type ) {
		return;
	}
	quantity.dataset.tbbStepper = 'done';
	quantity.classList.add( 'tbb-quantity' );

	const minus = makeButton( 'down', 'Decrease quantity' );
	const plus = makeButton( 'up', 'Increase quantity' );

	minus.addEventListener( 'click', () => stepValue( input, 'down' ) );
	plus.addEventListener( 'click', () => stepValue( input, 'up' ) );

	quantity.insertBefore( minus, input );
	quantity.appendChild( plus );
}

function initQuantityStepper(): void {
	document
		.querySelectorAll<HTMLElement>(
			'.woocommerce div.product form.variations_form .quantity'
		)
		.forEach( decorate );
}

export { initQuantityStepper };
