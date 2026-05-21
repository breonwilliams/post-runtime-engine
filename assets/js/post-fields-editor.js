/*
 * Post Runtime Engine — Post Fields admin interactivity (v1.1).
 *
 * Two responsibilities:
 *   1. Form view: show/hide the conditional sections (badge attrs, meta
 *      pair icon picker, date format, currency code, rating/progress
 *      attrs) based on the currently-selected display type. Driven by
 *      the data-shown-when="..." attribute on each conditional block.
 *   2. List view: drag-to-reorder via jQuery UI Sortable. The hidden
 *      ordered_keys[] inputs on each row track the new order; submitting
 *      the form sends the reorder POST.
 *
 * No build step. Plain jQuery, mirroring the existing meta-box.js style.
 * No live preview pane in v1.1 — see PRE_Admin_Post_Fields docblock for
 * the design decision.
 */

(function ($) {
	'use strict';

	$(function () {
		initFormConditionals();
		initListReorder();
	});

	/**
	 * Toggle visibility of the conditional blocks on the form view based
	 * on the selected display type.
	 *
	 * Each conditional block carries data-shown-when="type1,type2,...".
	 * The block is visible if-and-only-if the currently-selected display
	 * type is in that comma list.
	 */
	function initFormConditionals() {
		var $form = $('.pre-post-field-form');
		if ($form.length === 0) {
			return;
		}

		var $select = $form.find('.pre-field-display-type-select');
		if ($select.length === 0) {
			return;
		}

		function apply() {
			var current = $select.val();
			$form.find('.pre-field-cond[data-shown-when]').each(function () {
				var $block = $(this);
				var allowed = ($block.attr('data-shown-when') || '').split(',').map(function (s) {
					return s.trim();
				});
				if (allowed.indexOf(current) !== -1) {
					$block.show();
				} else {
					$block.hide();
				}
			});
		}

		$select.on('change', apply);
		apply();
	}

	/**
	 * Wire jQuery UI Sortable on the field list table body for drag-to-
	 * reorder. Reorder is then persisted by clicking "Save order" which
	 * submits the surrounding form with the new ordered_keys[] sequence.
	 */
	function initListReorder() {
		var $tbody = $('.pre-post-fields-sortable');
		if ($tbody.length === 0) {
			return;
		}

		if (typeof $.fn.sortable !== 'function') {
			// jquery-ui-sortable not loaded — fall back to manual ordering
			// via row buttons (future enhancement; for now just bail).
			return;
		}

		$tbody.sortable({
			items: '> tr',
			handle: '.pre-drag-handle',
			axis: 'y',
			cursor: 'move',
			placeholder: 'pre-post-fields-sortable__placeholder',
			helper: function (e, tr) {
				// Preserve column widths during drag.
				var $originals = tr.children();
				var $helper = tr.clone();
				$helper.children().each(function (index) {
					$(this).width($originals.eq(index).width());
				});
				return $helper;
			},
		});
	}
})(jQuery);
