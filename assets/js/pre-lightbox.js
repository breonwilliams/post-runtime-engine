/**
 * Promptless CPT Pages — Gallery Lightbox
 *
 * Vanilla JS, no dependencies. Registered on managed pages, enqueued by
 * the renderer only when a gallery-variant grouping renders (footer
 * queue). Design contract: docs/GALLERY_VARIANT_DESIGN.md §4/§9/§10.
 *
 * Behavior contract (WAI-ARIA APG dialog, WCAG 2.2 AA):
 *   - Tile <button> click/Enter/Space opens the dialog at that image
 *   - role="dialog" + aria-modal="true", labelled by the caption/counter
 *   - Focus moves into the dialog on open; Tab / Shift+Tab are trapped
 *   - Escape closes; focus returns to the triggering tile
 *   - Visible prev/next buttons are the BASELINE navigation (WCAG 2.2
 *     §2.5.7 — dragging/swiping is an enhancement, never the only path)
 *   - ArrowLeft / ArrowRight navigate; Home / End jump to first / last
 *   - Touch swipe navigates (enhancement)
 *   - aria-live="polite" region announces "Image X of Y: caption"
 *   - Body scroll locked while open, preserving scrollbar gutter
 *   - Full-size images load ON OPEN only; adjacent images are prefetched
 *     (contract §9 — page load never pays for full-size files)
 *   - prefers-reduced-motion handled in CSS (no JS-driven motion here)
 */
(function () {
	'use strict';

	var FOCUSABLE = 'button:not([disabled]), [href], [tabindex]:not([tabindex="-1"])';

	var state = {
		open: false,
		images: [],
		index: 0,
		trigger: null, // Element to return focus to on close.
		el: null,      // Root .pre-lightbox element (created lazily once).
		touchStartX: null
	};

	// -----------------------------------------------------------------------
	// Setup: bind every gallery grid on the page to its JSON payload.
	// -----------------------------------------------------------------------

	function init() {
		var grids = document.querySelectorAll('.pre-gallery__grid[data-pre-lightbox]');
		if (!grids.length) {
			return;
		}

		grids.forEach(function (grid) {
			var dataScript = grid.parentElement
				? grid.parentElement.querySelector('.pre-gallery-lightbox-data')
				: null;
			if (!dataScript) {
				return;
			}

			var images;
			try {
				images = JSON.parse(dataScript.textContent);
			} catch (e) {
				return;
			}
			if (!images || !images.length) {
				return;
			}

			grid.addEventListener('click', function (e) {
				var btn = e.target.closest ? e.target.closest('.pre-gallery__tile-button') : null;
				if (!btn || !grid.contains(btn)) {
					return;
				}
				var index = parseInt(btn.getAttribute('data-pre-lightbox-index') || '0', 10);
				if (isNaN(index)) {
					index = 0;
				}
				open(images, index, btn);
			});
		});
	}

	// -----------------------------------------------------------------------
	// Dialog lifecycle
	// -----------------------------------------------------------------------

	function buildDialog() {
		if (state.el) {
			return state.el;
		}

		var strings = window.pcptpagesLightbox || {};
		var el = document.createElement('div');
		el.className = 'pre-lightbox';
		el.setAttribute('role', 'dialog');
		el.setAttribute('aria-modal', 'true');
		el.setAttribute('aria-label', strings.dialogLabel || 'Image viewer');
		el.hidden = true;

		el.innerHTML =
			'<button type="button" class="pre-lightbox__control pre-lightbox__close" aria-label="' + (strings.close || 'Close') + '">' +
				'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>' +
			'</button>' +
			'<button type="button" class="pre-lightbox__control pre-lightbox__prev" aria-label="' + (strings.prev || 'Previous image') + '">' +
				'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>' +
			'</button>' +
			'<div class="pre-lightbox__stage">' +
				'<img class="pre-lightbox__image" src="" alt="" />' +
			'</div>' +
			'<button type="button" class="pre-lightbox__control pre-lightbox__next" aria-label="' + (strings.next || 'Next image') + '">' +
				'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>' +
			'</button>' +
			'<div class="pre-lightbox__meta" aria-live="polite">' +
				'<p class="pre-lightbox__caption"></p>' +
				'<p class="pre-lightbox__counter"></p>' +
			'</div>';

		document.body.appendChild(el);

		el.querySelector('.pre-lightbox__close').addEventListener('click', close);
		el.querySelector('.pre-lightbox__prev').addEventListener('click', function () {
			navigate(-1);
		});
		el.querySelector('.pre-lightbox__next').addEventListener('click', function () {
			navigate(1);
		});

		// Backdrop click closes (clicks on the root, not its children).
		el.addEventListener('click', function (e) {
			if (e.target === el) {
				close();
			}
		});

		// Touch swipe — enhancement only; buttons/keys are the baseline.
		el.addEventListener('touchstart', function (e) {
			state.touchStartX = e.changedTouches[0].clientX;
		}, { passive: true });
		el.addEventListener('touchend', function (e) {
			if (state.touchStartX === null) {
				return;
			}
			var dx = e.changedTouches[0].clientX - state.touchStartX;
			state.touchStartX = null;
			if (Math.abs(dx) > 48) {
				navigate(dx < 0 ? 1 : -1);
			}
		}, { passive: true });

		// Persist the singleton BEFORE returning — render()/close()/navigate()
		// all read state.el, and the original omission of this line made them
		// silently no-op (caught by the live E2E: empty caption/counter,
		// Escape dead — an img with src="" reflects the page URL, which
		// masked the failure as "image loaded").
		state.el = el;

		return el;
	}

	function open(images, index, trigger) {
		state.images = images;
		state.index = Math.max(0, Math.min(index, images.length - 1));
		state.trigger = trigger || document.activeElement;

		var el = buildDialog();
		el.hidden = false;
		state.open = true;

		lockScroll();
		render();

		// Focus the close button — first interactive element; gives SR users
		// an immediate, predictable landmark inside the dialog.
		el.querySelector('.pre-lightbox__close').focus();

		document.addEventListener('keydown', onKeydown, true);
	}

	function close() {
		if (!state.open || !state.el) {
			return;
		}
		state.el.hidden = true;
		state.open = false;
		unlockScroll();
		document.removeEventListener('keydown', onKeydown, true);

		if (state.trigger && typeof state.trigger.focus === 'function') {
			state.trigger.focus();
		}
		state.trigger = null;
	}

	function navigate(delta) {
		if (!state.open) {
			return;
		}
		var len = state.images.length;
		state.index = (state.index + delta + len) % len;
		render();
	}

	function render() {
		var el = state.el;
		var img = state.images[state.index];
		if (!el || !img) {
			return;
		}

		var strings = window.pcptpagesLightbox || {};
		var stageImg = el.querySelector('.pre-lightbox__image');
		stageImg.src = img.url;
		stageImg.alt = img.alt || '';

		var caption = el.querySelector('.pre-lightbox__caption');
		caption.textContent = img.caption || '';
		caption.style.display = img.caption ? '' : 'none';

		// Counter doubles as the aria-live announcement payload:
		// "Image 3 of 12" (+ the caption node above, same live region).
		var counterTemplate = strings.counter || 'Image %1$s of %2$s';
		el.querySelector('.pre-lightbox__counter').textContent = counterTemplate
			.replace('%1$s', String(state.index + 1))
			.replace('%2$s', String(state.images.length));

		// Single-image galleries need no nav controls.
		var multi = state.images.length > 1;
		el.querySelector('.pre-lightbox__prev').style.display = multi ? '' : 'none';
		el.querySelector('.pre-lightbox__next').style.display = multi ? '' : 'none';

		prefetchNeighbors();
	}

	// Contract §9: only the open image + its neighbors are ever fetched.
	function prefetchNeighbors() {
		var len = state.images.length;
		if (len < 2) {
			return;
		}
		[state.index + 1, state.index - 1].forEach(function (i) {
			var img = state.images[(i + len) % len];
			if (img && img.url) {
				var pre = new Image();
				pre.src = img.url;
			}
		});
	}

	// -----------------------------------------------------------------------
	// Keyboard: Escape, arrows, Home/End, focus trap
	// -----------------------------------------------------------------------

	function onKeydown(e) {
		if (!state.open) {
			return;
		}

		switch (e.key) {
			case 'Escape':
			case 'Esc':
				e.preventDefault();
				close();
				return;
			case 'ArrowLeft':
				e.preventDefault();
				navigate(-1);
				return;
			case 'ArrowRight':
				e.preventDefault();
				navigate(1);
				return;
			case 'Home':
				e.preventDefault();
				navigate(-state.index);
				return;
			case 'End':
				e.preventDefault();
				navigate(state.images.length - 1 - state.index);
				return;
			case 'Tab':
				trapTab(e);
				return;
		}
	}

	function trapTab(e) {
		var focusables = Array.prototype.filter.call(
			state.el.querySelectorAll(FOCUSABLE),
			function (node) {
				return node.offsetWidth > 0 || node.offsetHeight > 0;
			}
		);
		if (!focusables.length) {
			e.preventDefault();
			return;
		}

		var first = focusables[0];
		var last = focusables[focusables.length - 1];
		var active = document.activeElement;

		if (e.shiftKey) {
			if (active === first || !state.el.contains(active)) {
				e.preventDefault();
				last.focus();
			}
		} else if (active === last || !state.el.contains(active)) {
			e.preventDefault();
			first.focus();
		}
	}

	// -----------------------------------------------------------------------
	// Scroll lock preserving scrollbar gutter (no layout shift on open)
	// -----------------------------------------------------------------------

	function lockScroll() {
		var gutter = window.innerWidth - document.documentElement.clientWidth;
		if (gutter > 0) {
			document.body.style.paddingRight = gutter + 'px';
		}
		document.body.style.overflow = 'hidden';
	}

	function unlockScroll() {
		document.body.style.overflow = '';
		document.body.style.paddingRight = '';
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
