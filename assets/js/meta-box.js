/*
 * Post Runtime Engine — meta box client logic.
 *
 * Responsibilities:
 *   - Add / remove / reorder grouping items
 *   - Open WordPress media library for image selection
 *   - Sync icon preview when the icon text input changes or the curated
 *     dropdown is changed (live update; both legacy curated IDs and
 *     Iconify codes render through the same preview span)
 *   - Toggle icon-only-variant UI state per grouping: when the effective
 *     variant (override or default) is compact-grid or horizontal-row,
 *     hide the image upload controls and show an explanatory note. Mirrors
 *     the renderer's variant-aware media resolution so the meta box never
 *     accepts data the renderer will silently drop.
 *   - Hide "Add item" when max_items is reached
 *   - Inline post search on the link field (jQuery UI Autocomplete +
 *     /wp/v2/search). Lazy-initialized on first focus per input so we
 *     don't pay the setup cost on items the user never opens.
 *
 * Dependencies (enqueued by class-pre-meta-box.php): jQuery, jQuery UI
 * Sortable, jQuery UI Autocomplete, wp.media (via wp_enqueue_media).
 * Localized data is on window.preMetaBox.
 */

(function ($) {
	'use strict';

	if (typeof window.preMetaBox === 'undefined') {
		return;
	}

	var icons      = window.preMetaBox.icons || {};
	var mediaTitle = window.preMetaBox.mediaTitle || 'Choose Image';
	var mediaBtn   = window.preMetaBox.mediaButton || 'Use this image';

	$(function () {
		$('.pre-meta-grouping').each(function () {
			initGrouping($(this));
		});
	});

	/**
	 * Initialize one grouping section: sortable items, add-item handler,
	 * delegated handlers for items.
	 */
	function initGrouping($grouping) {
		var $list = $grouping.find('.pre-items-list').first();

		// jQuery UI sortable for drag-reorder. Items are <li class="pre-item">.
		$list.sortable({
			handle: '.pre-item__handle',
			placeholder: 'pre-item--placeholder',
			tolerance: 'pointer',
			items: '> li.pre-item',
			update: function () {
				renumberIndices($list, $grouping.data('grouping-key'));
			}
		});

		// Add item.
		$grouping.on('click', '.pre-add-item', function (e) {
			e.preventDefault();
			addItem($grouping);
		});

		// Remove item.
		$grouping.on('click', '.pre-remove-item', function (e) {
			e.preventDefault();
			$(this).closest('.pre-item').remove();
			updateAddButtonVisibility($grouping);
			renumberIndices($list, $grouping.data('grouping-key'));
		});

		// Icon text input — live preview while typing. Debounced lightly via
		// the browser's input event coalescing; no setTimeout needed because
		// setItemIcon is cheap (DOM swap of a single span / web component).
		// Sync the curated dropdown when the typed value matches a known
		// curated ID, otherwise clear the dropdown so it doesn't show a
		// stale selection.
		$grouping.on('input change', '.pre-item__icon-input', function () {
			var $input = $(this);
			var $item  = $input.closest('.pre-item');
			var iconId = ($input.val() || '').trim();
			setItemIcon($item, iconId);
			syncIconSelectFromInput($item, iconId);
		});

		// Curated icon dropdown change — writes the chosen ID into the text
		// input above and updates the preview. Replaces the previous
		// visual quickpick grid (removed 2026-05-16 — bulky UI, low
		// information density). Users can still type any Iconify code
		// manually in the text input.
		$grouping.on('change', '.pre-item__icon-select', function () {
			var $select = $(this);
			var $item   = $select.closest('.pre-item');
			var iconId  = ($select.val() || '').trim();
			$item.find('.pre-item__icon-input').val(iconId);
			setItemIcon($item, iconId);
		});

		// Pick image (opens WP media library).
		$grouping.on('click', '.pre-pick-image', function (e) {
			e.preventDefault();
			var $btn  = $(this);
			var $item = $btn.closest('.pre-item');
			openMediaPicker($item);
		});

		// Clear media (icon + image both cleared).
		$grouping.on('click', '.pre-clear-media', function (e) {
			e.preventDefault();
			var $item = $(this).closest('.pre-item');
			clearItemMedia($item);
		});

		// Lazy-init link autocomplete on first focus. Avoids paying setup
		// cost on items the user never opens, and keeps the wiring simple
		// for items added via the template (no need to re-init on append).
		$grouping.on('focus', '.pre-item__link', function () {
			initLinkAutocomplete($(this));
		});

		// Any user-driven edit to the visible link clears the hidden
		// link_post_id. Once the URL no longer matches the picked post, the
		// post_id is no longer trustworthy as a domain-portable reference
		// and we should fall back to the URL string as authority. Note:
		// jQuery .val() and direct .value assignment do NOT fire 'input',
		// so the autocomplete's programmatic URL fill won't trip this.
		$grouping.on('input', '.pre-item__link', function () {
			$(this).closest('.pre-item').find('.pre-item__link-post-id').val('');
		});

		// Variant override changes — re-evaluate icon-only state so the
		// image upload controls show / hide to match the renderer's
		// variant-aware media resolution. Triggered by the user picking a
		// different variant from the dropdown above the items list.
		$grouping.on('change', 'select[name$="[variant_override]"]', function () {
			evaluateGroupingVariant($grouping);
		});

		updateAddButtonVisibility($grouping);
		evaluateGroupingVariant($grouping);
	}

	/**
	 * Initialize jQuery UI Autocomplete on a link input. Idempotent —
	 * sets a data flag after first init so repeated focus events are
	 * cheap.
	 *
	 * Source: WordPress REST /wp/v2/search filtered to posts. Returns
	 * matches from any post type with show_in_rest=true. Skipped when the
	 * input value looks like a URL/anchor/scheme — autocomplete is for
	 * search-by-title, not for paste-and-edit.
	 */
	function initLinkAutocomplete($link) {
		if ($link.data('pre-autocomplete-init')) {
			return;
		}

		var searchUrl = window.preMetaBox.searchUrl;
		var nonce     = window.preMetaBox.nonce;

		// If localized data is missing for any reason, fail silently — the
		// input still works as a plain text field.
		if (!searchUrl) {
			$link.data('pre-autocomplete-init', true);
			return;
		}

		$link.autocomplete({
			minLength: 2,
			delay: 250,
			// Append the dropdown to the meta box wrapper so it inherits
			// our scoped styles and stays correctly positioned even when
			// the post-edit page scrolls.
			appendTo: $link.closest('.pre-meta-box').length
				? $link.closest('.pre-meta-box')
				: 'body',
			source: function (request, response) {
				var term = request.term;

				// Suppress autocomplete for input that already looks like
				// a URL or scheme — the user is paste-editing, not
				// searching.
				if (/^(https?:\/\/|\/\/|\/|#|tel:|mailto:|javascript:)/i.test(term)) {
					response([]);
					return;
				}

				$.ajax({
					url: searchUrl,
					method: 'GET',
					dataType: 'json',
					beforeSend: function (xhr) {
						if (nonce) {
							xhr.setRequestHeader('X-WP-Nonce', nonce);
						}
					},
					data: {
						search: term,
						type: 'post',
						per_page: 8,
						_fields: 'id,title,url,subtype'
					}
				}).done(function (data) {
					if (!Array.isArray(data)) {
						response([]);
						return;
					}
					response(data.map(function (item) {
						return {
							// id passes through to select() so we can
							// capture the site-portable post reference.
							id: item.id,
							label: item.title || '(untitled)',
							value: item.url || '',
							subtype: item.subtype || ''
						};
					}));
				}).fail(function () {
					response([]);
				});
			},
			select: function (event, ui) {
				// Capture the post ID alongside the URL. The renderer
				// prefers get_permalink(post_id) at render time, which
				// makes this link survive domain migrations and permalink
				// changes. The visible URL is still saved (as fallback
				// when the post is later trashed/deleted), but the post_id
				// is the canonical reference.
				if (ui.item && ui.item.id) {
					$link
						.closest('.pre-item')
						.find('.pre-item__link-post-id')
						.val(ui.item.id);
				}
				// Default behavior continues — jQuery UI sets the input
				// value to ui.item.value (the URL).
			}
		});

		// Custom item rendering: title + post-type label + URL preview.
		// jQuery UI's autocomplete has a tiny escape-hatch on the widget
		// instance for this; safer than fragile DOM diving.
		var instance = $link.autocomplete('instance');
		if (instance) {
			instance._renderItem = function (ul, item) {
				return $('<li>')
					.append(
						$('<div class="pre-link-suggest">')
							.append($('<span class="pre-link-suggest__title">').text(item.label))
							.append(
								item.subtype
									? $('<span class="pre-link-suggest__type">').text(item.subtype)
									: ''
							)
							.append($('<span class="pre-link-suggest__url">').text(item.value))
					)
					.appendTo(ul);
			};
		}

		$link.data('pre-autocomplete-init', true);
	}

	/**
	 * Add a new empty item to a grouping. Uses the <template> child as the
	 * source; replaces __INDEX__ with a fresh integer index based on the
	 * current item count.
	 */
	function addItem($grouping) {
		var $template = $grouping.find('.pre-item-template').first();
		if (!$template.length) {
			return;
		}

		// <template> contents — use innerHTML on the actual element.
		var html = $template[0].innerHTML;

		// Replace __INDEX__ with a unique-per-grouping index.
		var $list = $grouping.find('.pre-items-list').first();
		var nextIdx = $list.children('.pre-item').length;
		// Ensure uniqueness even after reordering — append a timestamp so
		// stale indices from a previous reorder don't collide. PHP doesn't
		// care about index continuity; renumberIndices() normalizes on save
		// reorder.
		var uniqueIdx = nextIdx + '_' + Date.now();
		var rendered  = html.split('__INDEX__').join(uniqueIdx);

		var $newItem = $(rendered);
		$list.append($newItem);

		updateAddButtonVisibility($grouping);
		renumberIndices($list, $grouping.data('grouping-key'));
	}

	/**
	 * After reorder or removal, renumber the [items][N][...] indices in
	 * input names so PHP receives a clean 0-based sequence.
	 */
	function renumberIndices($list, groupingKey) {
		$list.children('.pre-item').each(function (i) {
			var newPrefix = 'pre_groupings[' + groupingKey + '][items][' + i + ']';
			$(this).find('input, textarea').each(function () {
				var $el = $(this);
				var name = $el.attr('name');
				if (!name) return;
				// Match: pre_groupings[KEY][items][ANYINDEX][FIELD]
				var newName = name.replace(
					/pre_groupings\[[^\]]+\]\[items\]\[[^\]]+\]/,
					newPrefix
				);
				$el.attr('name', newName);
			});
		});
	}

	/**
	 * Update the icon preview to reflect the current icon_id text input value.
	 * Selecting/typing an icon clears any existing image (mutual exclusion).
	 *
	 * Recognizes both representations:
	 *   - Legacy curated ID (e.g. "home") → render the inline SVG bundled
	 *     in window.preMetaBox.icons[iconId].svg
	 *   - Iconify code (e.g. "mdi:home", "logos:wordpress") → render a
	 *     <iconify-icon> web component; the iconify-icon script loaded on
	 *     this admin screen fetches the SVG from the Iconify CDN at paint
	 *     time. Invalid format (no colon, weird chars) → empty placeholder.
	 */
	function setItemIcon($item, iconId) {
		var $imageInput = $item.find('.pre-item__image-input');
		var $preview    = $item.find('.pre-item__preview');

		iconId = (iconId || '').trim();

		if (iconId === '') {
			if (!$imageInput.val() || $imageInput.val() === '0') {
				$preview.html('<span class="pre-item__preview-empty">—</span>');
			}
			$preview.attr('data-icon-id', '');
			updatePickImageButtonLabel($item);
			return;
		}

		// Picking ANY icon clears the image (mutual exclusion enforced by
		// the validator; the UI mirrors it so users don't see both at once).
		$imageInput.val('0');
		$preview.attr('data-icon-id', iconId);

		if (icons[iconId]) {
			// Legacy curated ID — inline SVG. Cheapest paint path.
			$preview.html(icons[iconId].svg);
		} else if (isIconifyCode(iconId)) {
			// Iconify code — web component fetches from api.iconify.design.
			// Build via createElement so attribute escaping is the DOM's
			// responsibility (no innerHTML injection of a user-typed value).
			var el = document.createElement('iconify-icon');
			el.setAttribute('icon', iconId);
			el.setAttribute('aria-hidden', 'true');
			$preview.empty().append(el);
		} else {
			// Doesn't match any known shape — placeholder. Save will fail
			// with a clear validator error rather than store junk.
			$preview.html('<span class="pre-item__preview-empty" aria-hidden="true">?</span>');
		}

		updatePickImageButtonLabel($item);
	}

	/**
	 * Iconify code shape check — mirrors PRE_Icon_Library::is_iconify_format()
	 * in PHP. `collection:name` where both sides are sanitize-key-safe slugs
	 * (lowercase letters, digits, hyphens, underscores), one colon separator,
	 * no whitespace. Hyphens are required because Iconify icon names use
	 * them (`account-group`, `arrow-right-circle`, etc.).
	 */
	function isIconifyCode(value) {
		if (typeof value !== 'string' || value.length === 0 || value.length > 100) {
			return false;
		}
		return /^[a-z0-9][a-z0-9_-]*:[a-z0-9][a-z0-9_-]*$/.test(value);
	}

	/**
	 * Open WordPress's media library picker for image selection.
	 */
	function openMediaPicker($item) {
		var frame = wp.media({
			title: mediaTitle,
			button: { text: mediaBtn },
			library: { type: 'image' },
			multiple: false
		});

		frame.on('select', function () {
			var attachment = frame.state().get('selection').first().toJSON();
			setItemImage($item, attachment);
		});

		frame.open();
	}

	/**
	 * Apply a chosen attachment to the item: store the ID, update preview,
	 * clear any selected icon (mutual exclusion).
	 *
	 * Bug history: prior version referenced an undefined `$iconSelect`
	 * variable here (carried over from when the icon picker was a single
	 * <select>). That ReferenceError threw AFTER the image_id was stored
	 * in the hidden input but BEFORE the preview thumbnail rendered, so
	 * the image silently persisted on save with no visual confirmation —
	 * the bug Breon hit during the 2026-05-16 demo. Now uses the actual
	 * `.pre-item__icon-select` class selector and runs to completion.
	 */
	function setItemImage($item, attachment) {
		var $imageInput  = $item.find('.pre-item__image-input');
		var $iconInput   = $item.find('.pre-item__icon-input');
		var $iconSelect  = $item.find('.pre-item__icon-select');
		var $preview     = $item.find('.pre-item__preview');

		$imageInput.val(attachment.id);
		$iconInput.val('');
		// Reset the curated dropdown back to its placeholder option so it
		// doesn't show a stale "selected" state alongside the new image.
		$iconSelect.val('');

		var thumbUrl = (attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url)
			|| attachment.url;
		$preview.html('<img src="' + thumbUrl + '" alt="">');

		updatePickImageButtonLabel($item);
	}

	/**
	 * Reset both icon and image on this item.
	 */
	function clearItemMedia($item) {
		$item.find('.pre-item__icon-input').val('');
		$item.find('.pre-item__image-input').val('0');
		$item.find('.pre-item__icon-select').val('');
		$item.find('.pre-item__preview').html('<span class="pre-item__preview-empty">—</span>').attr('data-icon-id', '');
		updatePickImageButtonLabel($item);
	}

	/**
	 * Sync the curated <select> when the text input value changes. If the
	 * typed value matches a known curated ID, select that option; otherwise
	 * clear the dropdown back to the placeholder so it doesn't show a
	 * misleading "selected" state.
	 */
	function syncIconSelectFromInput($item, iconId) {
		var $select = $item.find('.pre-item__icon-select');
		if (!$select.length) {
			return;
		}
		// Only set the value if it matches an existing option — otherwise
		// .val() on a missing option is a no-op in jQuery but the visible
		// state shows the previous option. Clear explicitly.
		if (iconId && $select.find('option[value="' + cssEscape(iconId) + '"]').length) {
			$select.val(iconId);
		} else {
			$select.val('');
		}
	}

	/**
	 * Minimal CSS-attribute-value escape — enough for the icon-id shape
	 * (lowercase letters, digits, hyphens, underscores, and a single colon
	 * for Iconify codes). Escapes the colon because jQuery's attribute
	 * selector treats it as a pseudo-class separator.
	 */
	function cssEscape(value) {
		return String(value).replace(/([:.])/g, '\\$1');
	}

	/**
	 * Icon-only variants — the renderer drops image_id silently for these.
	 * Mirrors PRE_Renderer::render_item's `$is_icon_only_variant` check
	 * (compact-grid, horizontal-row). Keep this list in sync with the PHP
	 * side, or images uploaded in the meta box will silently vanish at
	 * render time again.
	 */
	var ICON_ONLY_VARIANTS = ['compact-grid', 'horizontal-row'];

	/**
	 * Compute the effective variant for a grouping (override if set,
	 * otherwise the default from the grouping definition), then toggle
	 * the `is-icon-only` class on the grouping wrapper. CSS uses that
	 * class to hide the image-upload controls and reveal the explanatory
	 * note, so the meta box state matches the render contract.
	 */
	function evaluateGroupingVariant($grouping) {
		var defaultVariant = ($grouping.data('default-variant') || '').toString();
		var $overrideSelect = $grouping.find('select[name$="[variant_override]"]').first();
		var override = ($overrideSelect.val() || '').toString();
		var effective = override !== '' ? override : defaultVariant;
		var isIconOnly = ICON_ONLY_VARIANTS.indexOf(effective) !== -1;
		$grouping.toggleClass('is-icon-only', isIconOnly);
		// Toggle the grouping-level explanatory note visibility. There is
		// exactly one note element per grouping (rendered above the items
		// list in class-pre-meta-box.php — formerly rendered per-item,
		// which produced 29x duplicate noise on the demo page). `hidden`
		// is the canonical accessible-hide attribute; CSS in admin.css
		// has a fallback `display: none[hidden]` rule for older browsers.
		var $note = $grouping.find('.pre-meta-grouping__icon-only-note').first();
		if ($note.length) {
			if (isIconOnly) {
				$note.removeAttr('hidden');
			} else {
				$note.attr('hidden', 'hidden');
			}
		}
	}

	/**
	 * Toggle the pick-image button label between "Pick image" and "Change image"
	 * based on whether an image is already selected.
	 */
	function updatePickImageButtonLabel($item) {
		var hasImage = $item.find('.pre-item__image-input').val();
		var $btn     = $item.find('.pre-pick-image');
		hasImage = hasImage && hasImage !== '0';
		$btn.text(hasImage ? (window.preMetaBox.i18n.change || 'Change image') : (window.preMetaBox.i18n.pickImg || 'Pick image'));
	}

	/**
	 * Hide the "Add item" button when max_items is reached for this grouping.
	 */
	function updateAddButtonVisibility($grouping) {
		var max     = parseInt($grouping.data('max-items'), 10) || 0;
		var $list   = $grouping.find('.pre-items-list').first();
		var $addBtn = $grouping.find('.pre-add-item').first();
		if (max > 0 && $list.children('.pre-item').length >= max) {
			$addBtn.hide();
		} else {
			$addBtn.show();
		}
	}
})(jQuery);
