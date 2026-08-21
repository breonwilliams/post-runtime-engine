/**
 * Promptless CPT Pages — location field click-to-load map.
 *
 * The `location` field renders a token-styled facade <button> instead of a
 * live iframe (performance: a Google embed costs ~1MB+ of third-party payload;
 * privacy: no Google request until the visitor opts in). This script swaps the
 * facade for the embed iframe on click. The facade is a real <button>, so
 * keyboard activation (Enter/Space) arrives as a click for free.
 *
 * The embed URL is assembled HERE from the facade's data attributes (address
 * string + numeric zoom) with encodeURIComponent, never from markup — so the
 * iframe src is always a https://www.google.com/maps URL, and unicode /
 * apostrophes / "#" unit numbers encode correctly.
 *
 * Self-contained: no dependency on Promptless WP. Gated enqueue — only loaded
 * when a click-mode location map is on the page. Self-guards: does nothing
 * unless a .pre-map__facade button exists. Handles N maps per page.
 */
( function () {
	'use strict';

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	/**
	 * Swap a facade button for the Google Maps embed iframe.
	 *
	 * @param {HTMLElement} facade The .pre-map__facade button.
	 */
	function loadMap( facade ) {
		var address = facade.getAttribute( 'data-pre-map-address' ) || '';
		if ( ! address ) {
			return;
		}

		var zoom = parseInt( facade.getAttribute( 'data-pre-map-zoom' ), 10 );
		if ( isNaN( zoom ) || zoom < 1 || zoom > 21 ) {
			zoom = 14; // 'neighborhood' default.
		}

		var src =
			'https://www.google.com/maps?q=' +
			encodeURIComponent( address ) +
			'&z=' +
			zoom +
			'&output=embed';

		var iframe = document.createElement( 'iframe' );
		iframe.className = 'pre-map__iframe';
		iframe.src = src;
		iframe.title = 'Map of ' + address;
		iframe.setAttribute( 'loading', 'lazy' );
		iframe.setAttribute( 'allowfullscreen', '' );
		iframe.setAttribute( 'referrerpolicy', 'no-referrer-when-downgrade' );

		var frame = facade.parentNode;
		if ( ! frame ) {
			return;
		}
		frame.classList.add( 'pre-map__frame--loaded' );
		frame.replaceChild( iframe, facade );

		// Keep keyboard focus in place — the iframe is the natural next target.
		iframe.focus();
	}

	ready( function () {
		var facades = document.querySelectorAll( '.pre-map__facade' );
		if ( ! facades.length ) {
			return;
		}

		Array.prototype.forEach.call( facades, function ( facade ) {
			facade.addEventListener( 'click', function () {
				loadMap( facade );
			} );
		} );
	} );
} )();
