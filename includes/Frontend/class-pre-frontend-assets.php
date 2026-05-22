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
	 * Enqueue the frontend stylesheet on registered CPT singles AND on the
	 * matching post-type archive page (so theme archive cards get the
	 * Iconify web component + cards.css that the post fields rely on).
	 * PostGrid sections inside Promptless pages take the late-inject
	 * fallback path through PRE_Card_Filter_Hooks.
	 */
	public function enqueue() {
		if ( ! $this->is_pre_managed_page() ) {
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

	/**
	 * Decide whether this page is one PRE should decorate with its frontend
	 * assets. True on:
	 *   - Single posts of a registered PRE CPT (where the hero renders)
	 *   - Post-type archives of a registered PRE CPT (where cards render via
	 *     the theme's archive template, hooked through
	 *     `promptless_archive_card_section`).
	 *
	 * Returns false (and assets are skipped) when:
	 *   - We're on a non-CPT page (homepage, taxonomy, search, etc.)
	 *   - The CPT isn't registered with PRE
	 *   - The post is AISB-managed (Promptless takes over)
	 *
	 * PostGrid sections living inside a Promptless page on a non-CPT URL
	 * still get assets via the late-inject path in
	 * PRE_Card_Filter_Hooks::maybe_enqueue_card_assets().
	 *
	 * @return bool
	 */
	private function is_pre_managed_page() {
		$plugin = pre();
		if ( ! $plugin->cpts ) {
			return false;
		}

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( ! ( $post instanceof WP_Post ) ) {
				return false;
			}
			if ( ! $plugin->cpts->exists( $post->post_type ) ) {
				return false;
			}
			// If Promptless took over this post, its assets are loading
			// instead; don't add ours.
			if ( get_post_meta( $post->ID, '_aisb_enabled', true ) ) {
				return false;
			}
			return true;
		}

		if ( is_post_type_archive() ) {
			$post_type_obj = get_queried_object();
			if ( ! $post_type_obj || ! isset( $post_type_obj->name ) ) {
				return false;
			}
			return $plugin->cpts->exists( $post_type_obj->name );
		}

		return false;
	}
}
