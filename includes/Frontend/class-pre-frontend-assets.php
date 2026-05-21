<?php
/**
 * Frontend asset enqueue for Post Runtime Engine.
 *
 * Loads the plugin's frontend.css only on registered CPT singles. The
 * stylesheet uses the `--aisb-*` design tokens (with documented fallbacks
 * per docs/AISB_TOKEN_CONTRACT.md), so when Promptless WP is active the
 * brand styling flows through automatically; without it, the fallbacks
 * produce a clean default look.
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend asset coordinator.
 */
class PRE_Frontend_Assets {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the frontend stylesheet on registered CPT singles.
	 */
	public function enqueue() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		$plugin = pre();
		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $post->post_type ) ) {
			return;
		}

		// If Promptless took over this post, its assets are loading instead;
		// don't add ours.
		if ( get_post_meta( $post->ID, '_aisb_enabled', true ) ) {
			return;
		}

		wp_enqueue_style(
			'pre-frontend',
			PRE_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			PRE_VERSION
		);

		// v1.1: post-field rendering styles. Loaded on every registered
		// CPT single (parallel to frontend.css) — the card renderer emits
		// no output when the CPT has no post fields registered, so loading
		// the CSS unconditionally on these pages is harmless. PostGrid +
		// archive integrations in Phase 12 will enqueue this same
		// stylesheet from their own enqueue paths.
		wp_enqueue_style(
			'pre-cards',
			PRE_PLUGIN_URL . 'assets/css/cards.css',
			array( 'pre-frontend' ),
			PRE_VERSION
		);

		// Iconify web-component bundle. Loaded on every registered CPT single
		// so any grouping item carrying an Iconify icon code (e.g. `mdi:home`,
		// `logos:wordpress`) renders the right SVG. The component fetches
		// SVGs from api.iconify.design at paint time with aggressive
		// browser-level caching; pages that use only the legacy curated
		// icons (which ship inline) pay the cost of one module download
		// (~20kb gzipped) but no network roundtrip per icon.
		//
		// We could conditionally enqueue this by scanning the post's meta
		// for any non-legacy icon_id, but the scan would itself be a
		// per-page cost on every page load — the always-on enqueue is
		// cheaper in practice, and the browser caches the module
		// site-wide after the first request.
		//
		// Same module Promptless WP enqueues for its sections; when both
		// plugins are active the browser caches one copy across pages.
		wp_enqueue_script(
			'pre-iconify-icon',
			'https://cdn.jsdelivr.net/npm/iconify-icon@2.1.0/dist/iconify-icon.min.js',
			array(),
			'2.1.0',
			true
		);
		wp_script_add_data( 'pre-iconify-icon', 'type', 'module' );
	}
}
