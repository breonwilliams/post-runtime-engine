/**
 * Post Runtime Engine — PostGrid editor-preview provider.
 *
 * Registers a metadata provider on Promptless WP's
 * `aisb.postgrid.cardMetadataProvider` JS hook. When the editor's PostGrid
 * preview resolves its posts, it calls this provider with the post IDs; we
 * return the same position-keyed field HTML the front end renders, fetched in
 * a single batched REST call. See docs/POSTGRID_PREVIEW_PARITY.md.
 *
 * Promptless stays unaware of PRE — it only calls whatever provider is
 * registered on the hook. No build step; plain JS, mirroring the plugin's
 * other admin scripts. Depends on wp-hooks (window.wp.hooks).
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.hooks || typeof wp.hooks.addFilter !== 'function' ) {
		return;
	}

	var cfg = window.pcptpagesPreview || {};
	if ( ! cfg.endpoint ) {
		return;
	}

	/**
	 * Provider function handed to Promptless.
	 *
	 * @param {number[]} postIds Visible post IDs in the preview.
	 * @return {Promise<Object>} Map: { <postId>: { <position>: htmlString } }.
	 */
	function provider( postIds ) {
		var ids = ( postIds || [] ).filter( function ( id ) {
			return typeof id === 'number' && id > 0;
		} );

		if ( ! ids.length ) {
			return Promise.resolve( {} );
		}

		return fetch( cfg.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || ''
			},
			body: JSON.stringify( { post_ids: ids } )
		} )
			.then( function ( res ) {
				return res.ok ? res.json() : {};
			} )
			.then( function ( map ) {
				return map && typeof map === 'object' ? map : {};
			} )
			.catch( function () {
				// Non-blocking: the editor renders base cards on any failure.
				return {};
			} );
	}

	// Register. The filter returns the provider FUNCTION (sync retrieval of an
	// async fn) — Promptless calls it once per resolved post set.
	wp.hooks.addFilter(
		'aisb.postgrid.cardMetadataProvider',
		'pcptpages/postgrid-preview',
		function () {
			return provider;
		}
	);
} )( window.wp );
