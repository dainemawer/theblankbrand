/**
 * Colour swatches.
 *
 * - Single product: clicking a chip fade-swaps the whole gallery (main preview +
 *   thumbnail strip) to that colour's images. Clicking a thumbnail then just
 *   swaps the larger image shown in the fixed main preview.
 * - Category grid: clicking a chip swaps that card's image.
 * - Barn2 grid: selecting a colour in the bulk-variations grid triggers the
 *   same gallery swap (best-effort, by matching the colour slug).
 *
 * This theme ships no flexslider/zoom, so the gallery is just stacked images —
 * we replace the wrapper contents and force it visible (no slider re-init).
 *
 * @package BlankBrandTheme
 */
(function ($) {
	'use strict';

	/**
	 * Fade-swap the single-product gallery to new markup.
	 *
	 * @param {string} html Pre-rendered gallery markup (main preview + thumbnail strip).
	 */
	function swapGallery(html) {
		var $gallery = $('.woocommerce-product-gallery');
		if (!$gallery.length || !html) {
			return;
		}
		var $wrapper = $gallery.find('.woocommerce-product-gallery__wrapper');

		// Unwind anything a slider may have added (defensive; usually absent here).
		if ($gallery.find('.flex-viewport').length) {
			$wrapper.unwrap();
		}
		$gallery.find('.flex-control-nav').remove();

		$gallery.css('transition', 'opacity .2s ease').css('opacity', 0);

		window.setTimeout(function () {
			$wrapper.removeAttr('style').html(html);
			if (
				typeof $.fn.flexslider === 'function' &&
				typeof $.fn.wc_product_gallery === 'function'
			) {
				$gallery.each(function () {
					$(this).wc_product_gallery();
				});
			}
			$gallery.each(function () {
				this.style.setProperty('opacity', '1', 'important');
			});
		}, 200);
	}

	/**
	 * Mark a chip active within its group.
	 *
	 * @param {Element} chip The chip.
	 */
	function setActive(chip) {
		var group = chip.closest('.tbb-swatches');
		if (!group) {
			return;
		}
		group.querySelectorAll('.tbb-chip').forEach(function (el) {
			var on = el === chip;
			el.classList.toggle('is-active', on);
			el.setAttribute('aria-pressed', on ? 'true' : 'false');
		});

		// Update the "Colour: {name}" label sitting just before the group.
		var label = group.previousElementSibling;
		if (label && label.classList.contains('tbb-colour-label')) {
			var span = label.querySelector('.tbb-selected-colour');
			if (span) {
				span.textContent = chip.getAttribute('aria-label') || '';
			}
		}
	}

	/**
	 * Swap a category-grid card's image.
	 *
	 * @param {Element} chip The chip.
	 */
	function swapCardImage(chip) {
		var src = chip.getAttribute('data-img');
		if (!src) {
			return;
		}
		var card = chip.closest('li.product, .wc-block-grid__product, .product');
		var img = card && card.querySelector('img');
		if (!img) {
			return;
		}
		img.src = src;
		var srcset = chip.getAttribute('data-srcset');
		if (srcset) {
			img.srcset = srcset;
		} else {
			img.removeAttribute('srcset');
		}
	}

	/**
	 * Mirror the chosen colour into the native variations form's (hidden) colour
	 * dropdown so WooCommerce resolves the variation for add-to-cart. Triggers a
	 * jQuery change so the wc-add-to-cart-variation script picks it up.
	 *
	 * @param {string} slug Colour term slug.
	 */
	function syncVariationColour(slug) {
		if (!slug) {
			return;
		}
		var $select = $('form.variations_form select[name="attribute_pa_colour"]');
		if ($select.length && $select.val() !== slug) {
			$select.val(slug).trigger('change');
		}
		// Available sizes depend on the chosen colour.
		updateSizeAvailability(slug);
	}

	/**
	 * Disable size pills that have no in-stock variation for the given colour,
	 * using the `window.tbbSizeStock` matrix ({ colour: { size: true } }). If the
	 * currently-selected size becomes unavailable, clear the selection.
	 *
	 * @param {string} colourSlug Colour term slug.
	 */
	function updateSizeAvailability(colourSlug) {
		if (!window.tbbSizeStock) {
			return;
		}
		var avail = window.tbbSizeStock[colourSlug];
		var clearedActive = false;
		document.querySelectorAll('.tbb-size').forEach(function (pill) {
			var size = pill.getAttribute('data-value');
			// No data for this colour → leave enabled; otherwise require an in-stock combo.
			var ok = avail ? avail[size] === true : true;
			pill.disabled = !ok;
			pill.classList.toggle('tbb-size--disabled', !ok);
			pill.setAttribute('aria-disabled', ok ? 'false' : 'true');
			if (!ok && pill.classList.contains('is-active')) {
				pill.classList.remove('is-active');
				pill.setAttribute('aria-checked', 'false');
				clearedActive = true;
			}
		});
		if (clearedActive) {
			var $size = $('form.variations_form select[name="attribute_pa_size"]');
			if ($size.length) {
				$size.val('').trigger('change');
			}
			var label = document.querySelector('.tbb-selected-size');
			if (label) {
				label.textContent = '';
			}
		}
	}

	/**
	 * Reflect the chosen size across the custom radio pills + the "Size:" label.
	 * Passing an empty/missing slug clears the selection (e.g. on reset).
	 *
	 * @param {string} slug Size term slug.
	 */
	function activateSize(slug) {
		var matched = '';
		document.querySelectorAll('.tbb-size').forEach(function (el) {
			var on = !!slug && el.getAttribute('data-value') === slug;
			el.classList.toggle('is-active', on);
			el.setAttribute('aria-checked', on ? 'true' : 'false');
			if (on) {
				matched = el.textContent;
			}
		});
		var label = document.querySelector('.tbb-selected-size');
		if (label) {
			label.textContent = matched;
		}
	}

	// Custom size pills drive the native (hidden) size dropdown.
	document.addEventListener('click', function (e) {
		var pill = e.target.closest('.tbb-size');
		if (!pill) {
			return;
		}
		e.preventDefault();
		// Ignore sizes that aren't available for the selected colour.
		if (pill.disabled || pill.classList.contains('tbb-size--disabled')) {
			return;
		}
		var slug = pill.getAttribute('data-value');
		activateSize(slug);
		var $select = $('form.variations_form select[name="attribute_pa_size"]');
		if ($select.length && $select.val() !== slug) {
			$select.val(slug).trigger('change');
		}
	});

	// Keep the pills in sync if the size dropdown changes elsewhere (e.g. the
	// "Clear" reset link, or a programmatic change).
	$(document).on('change', 'form.variations_form select[name="attribute_pa_size"]', function () {
		activateSize(this.value);
	});

	// Delegated clicks for all chips (single + grid).
	document.addEventListener('click', function (e) {
		var chip = e.target.closest('.tbb-chip');
		if (!chip || chip.classList.contains('tbb-chip--no-image')) {
			return;
		}
		e.preventDefault();

		if (chip.hasAttribute('data-img')) {
			swapCardImage(chip);
			setActive(chip);
			return;
		}
		var html = chip.getAttribute('data-gallery');
		if (html) {
			swapGallery(html);
			setActive(chip);
			syncVariationColour(chip.getAttribute('data-colour'));
		}
	});

	// Single product (no slider): clicking a gallery thumbnail swaps it into the
	// fixed main preview. Every colour image stays rendered as a thumbnail — we
	// only change the main image's source, never the DOM order.
	document.addEventListener('click', function (e) {
		var thumb = e.target.closest('.woocommerce-product-gallery__thumbs .woocommerce-product-gallery__image');
		if (!thumb) {
			return;
		}
		e.preventDefault();
		var wrapper = thumb.closest('.woocommerce-product-gallery__wrapper');
		var main = wrapper && wrapper.querySelector('.woocommerce-product-gallery__image--main');
		var img = main && main.querySelector('img');
		if (!img) {
			return;
		}

		var src = thumb.getAttribute('data-main-src');
		if (src) {
			img.src = src;
		}
		var srcset = thumb.getAttribute('data-main-srcset');
		if (srcset) {
			img.srcset = srcset;
		} else {
			img.removeAttribute('srcset');
		}
		var thumbImg = thumb.querySelector('img');
		img.alt = thumbImg ? thumbImg.alt || '' : '';

		var link = main.querySelector('a');
		var href = thumb.getAttribute('data-main-href');
		if (link && href) {
			link.href = href;
		}

		// Highlight the active thumbnail within this strip.
		thumb.parentNode.querySelectorAll('.woocommerce-product-gallery__image').forEach(function (el) {
			el.classList.toggle('is-active', el === thumb);
		});
	});

	// Barn2 bulk-variations grid: when a colour is chosen in the grid, mirror it
	// to the gallery. Best-effort — matches by colour slug against our chips.
	document.addEventListener('change', function (e) {
		var sel = e.target;
		if (!sel.matches || !sel.matches('.wc-bulk-variations-table select')) {
			return;
		}
		var attr = (sel.getAttribute('data-attribute_name') || sel.name || '').toLowerCase();
		if (attr.indexOf('colour') === -1 && attr.indexOf('color') === -1) {
			return;
		}
		var slug = sel.value;
		if (!slug) {
			return;
		}
		var chip = document.querySelector(
			'.tbb-swatches--strip .tbb-chip[data-colour="' + slug + '"], .tbb-swatches--chips .tbb-chip[data-colour="' + slug + '"]'
		);
		if (chip && !chip.classList.contains('tbb-chip--no-image')) {
			var html = chip.getAttribute('data-gallery');
			if (html) {
				swapGallery(html);
				setActive(chip);
			}
		}
		syncVariationColour(slug);
	});

	// Set the initial size availability as soon as the DOM is ready, based on the
	// pre-selected colour, so unavailable sizes are disabled from the start.
	$(function () {
		var colour = $('form.variations_form select[name="attribute_pa_colour"]').val();
		if (!colour) {
			var active = document.querySelector('.tbb-chip.is-active');
			colour = active ? active.getAttribute('data-colour') : '';
		}
		updateSizeAvailability(colour);
	});

	// On a single product, show the active colour's gallery on load so the page
	// starts on one clean colour, and pre-select that colour in the native form.
	$(window).on('load', function () {
		var active = document.querySelector('.tbb-swatches--chips .tbb-chip.is-active[data-gallery], .tbb-swatches--strip .tbb-chip.is-active[data-gallery]');
		if (active) {
			swapGallery(active.getAttribute('data-gallery'));
			syncVariationColour(active.getAttribute('data-colour'));
		}
	});
})(jQuery);
