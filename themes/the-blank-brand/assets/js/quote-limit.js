/**
 * Quote limit, on the product page.
 *
 * 100 or more of one style is quoted, not sold online. The server refuses it
 * (see QuoteLimit.php); this only makes the reason visible as a number is typed,
 * instead of after a click. The quantity box already stops the + button at the
 * limit, but a number can still be typed in.
 *
 * window.tbbQuoteLimit = { from, inCart, html } is set by QuoteLimit::assets():
 * the quantity that needs a quote, how many of this style are already in the
 * cart, and the message.
 */
(function () {
	'use strict';

	var cfg = window.tbbQuoteLimit;
	var form = document.querySelector('form.cart');

	if (!cfg || !form) {
		return;
	}

	var notice = null;

	function ensureNotice() {
		if (notice) {
			return notice;
		}
		notice = document.createElement('p');
		notice.className = 'tbb-quote-notice';
		notice.setAttribute('role', 'alert');
		notice.innerHTML = cfg.html;
		notice.hidden = true;

		// Inside the quantity and button row, as a full-width line under them. That
		// row wraps, and anything inserted after it lands below the Bulk Order Grid
		// link, which another script adds later.
		var row = form.querySelector('.woocommerce-variation-add-to-cart');
		var button = form.querySelector('.single_add_to_cart_button');
		if (row) {
			row.appendChild(notice);
		} else if (button) {
			button.insertAdjacentElement('afterend', notice);
		} else {
			form.appendChild(notice);
		}
		return notice;
	}

	function quantity() {
		var input = form.querySelector('input.qty');
		var value = input ? parseInt(input.value, 10) : 0;
		return isNaN(value) ? 0 : value;
	}

	function isOver() {
		return cfg.inCart + quantity() >= cfg.from;
	}

	function update() {
		var blocked = isOver();
		var button = form.querySelector('.single_add_to_cart_button');

		ensureNotice().hidden = !blocked;

		if (button) {
			button.classList.toggle('tbb-quote-blocked', blocked);
			button.setAttribute('aria-disabled', blocked ? 'true' : 'false');
		}
	}

	form.addEventListener('input', function (e) {
		if (e.target.matches('input.qty')) {
			update();
		}
	});
	form.addEventListener('change', function (e) {
		if (e.target.matches('input.qty')) {
			update();
		}
	});

	// Capture phase, so this runs before WooCommerce's own submit handling.
	form.addEventListener(
		'submit',
		function (e) {
			if (isOver()) {
				e.preventDefault();
				e.stopImmediatePropagation();
				update();
			}
		},
		true
	);

	// WooCommerce rewrites the quantity box's limits when a variation is chosen.
	if (window.jQuery) {
		window
			.jQuery(document)
			.on('found_variation reset_data', 'form.variations_form', function () {
				window.setTimeout(update, 0);
			});
	}

	update();
})();
