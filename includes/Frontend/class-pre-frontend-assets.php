<?php
/**
 * Frontend asset enqueue for Promptless CPT Pages.
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
class PCPTPages_Frontend_Assets {

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
	 * fallback path through PCPTPages_Card_Filter_Hooks.
	 */
	public function enqueue() {
		if ( ! $this->is_pcptpages_managed_page() ) {
			return;
		}

		wp_enqueue_style(
			'pcptpages-frontend',
			PCPTPages_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			PCPTPages_VERSION
		);

		// v1.1: post-field rendering styles. Loaded on every registered
		// CPT single (parallel to frontend.css) — the card renderer emits
		// no output when the CPT has no post fields registered, so loading
		// the CSS unconditionally on these pages is harmless. PostGrid +
		// archive integrations in Phase 12 will enqueue this same
		// stylesheet from their own enqueue paths.
		wp_enqueue_style(
			'pcptpages-cards',
			PCPTPages_PLUGIN_URL . 'assets/css/cards.css',
			array( 'pcptpages-frontend' ),
			PCPTPages_VERSION
		);

		// Iconify web-component bundle. Bundled locally at
		// assets/js/iconify-icon.min.js (v2.1.0 of the iconify-icon
		// package) so the plugin has no third-party CDN dependency at
		// runtime — works offline, no GDPR concerns, no jsdelivr-outage
		// failure mode. The component fetches individual icon SVGs from
		// api.iconify.design at paint time (that part still requires
		// network), but the component itself is self-hosted. ~20kb
		// gzipped. Same library Promptless WP can use for its sections;
		// each plugin ships its own copy so the dependency is
		// self-contained.
		wp_enqueue_script(
			'pcptpages-iconify-icon',
			PCPTPages_PLUGIN_URL . 'assets/js/iconify-icon.min.js',
			array(),
			'2.1.0',
			true
		);
		wp_script_add_data( 'pcptpages-iconify-icon', 'type', 'module' );
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
	 * PCPTPages_Card_Filter_Hooks::maybe_enqueue_card_assets().
	 *
	 * @return bool
	 */
	private function is_pcptpages_managed_page() {
		$plugin = pcptpages();
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
