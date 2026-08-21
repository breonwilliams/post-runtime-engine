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

		// CSS versions carry the file's own mtime alongside the plugin
		// version (kitchen-sink pressure test, 2026-07-25): a static
		// ?ver means an updated stylesheet keeps an identical URL after
		// a plugin update, so returning browsers serve their stale
		// cached copy of a file that is new on disk.
		wp_enqueue_style(
			'pcptpages-frontend',
			PCPTPages_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			PCPTPages_VERSION . '.' . (int) @filemtime( PCPTPages_PLUGIN_DIR . 'assets/css/frontend.css' )
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
			PCPTPages_VERSION . '.' . (int) @filemtime( PCPTPages_PLUGIN_DIR . 'assets/css/cards.css' )
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

		// Gallery lightbox: REGISTERED here (cheap — no output), ENQUEUED
		// only by the renderer when a gallery-variant grouping actually
		// renders (mid-render enqueues land in the footer queue). Pages
		// without a gallery never ship this script — the "never ship
		// assets a page can't use" gating philosophy.
		// Behavior contract: WAI-ARIA APG dialog (focus trap, Escape,
		// arrow keys, visible prev/next buttons, swipe as enhancement) —
		// docs/GALLERY_VARIANT_DESIGN.md §10.
		wp_register_script(
			'pcptpages-lightbox',
			PCPTPages_PLUGIN_URL . 'assets/js/pre-lightbox.js',
			array(),
			PCPTPages_VERSION,
			true
		);
		wp_localize_script(
			'pcptpages-lightbox',
			'pcptpagesLightbox',
			array(
				'dialogLabel' => __( 'Image viewer', 'promptless-cpt-pages' ),
				'close'       => __( 'Close', 'promptless-cpt-pages' ),
				'prev'        => __( 'Previous image', 'promptless-cpt-pages' ),
				'next'        => __( 'Next image', 'promptless-cpt-pages' ),
				/* translators: 1: current image number, 2: total image count */
				'counter'     => __( 'Image %1$s of %2$s', 'promptless-cpt-pages' ),
			)
		);

		// Location map: when this single CPT post will render a map block,
		// ask Promptless WP to enqueue the Map section's CSS/JS EARLY (here,
		// on wp_enqueue_scripts) rather than mid-render — a late CSS enqueue
		// would paint the facade unstyled for a frame. The action is defined
		// on the AISB side (MapRenderer::enqueue_embed_assets); when AISB is
		// inactive nothing is hooked and this is a harmless no-op, matching
		// the CSS-token-only coupling rule. docs/LOCATION_MAP_DESIGN.md § 7.
		$this->maybe_enqueue_map_assets();
	}

	/**
	 * Fire the AISB map-asset enqueue action if the current singular CPT post
	 * will render at least one location map block.
	 *
	 * Gating (never ship assets a page can't use): the post's CPT must define
	 * a visible, non-hidden `location` field for which an address resolves —
	 * either a per-post value or the AISB business-identity address. `$needs_js`
	 * is true only when a rendering field uses `click` load mode (the facade
	 * needs map.js); an all-`auto` page loads CSS only.
	 *
	 * @return void
	 */
	private function maybe_enqueue_map_assets() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		$plugin = pcptpages();
		if ( ! $plugin || ! $plugin->post_fields || ! $plugin->post_data ) {
			return;
		}

		$field_defs = $plugin->post_fields->get_all( $post->post_type );
		if ( empty( $field_defs ) ) {
			return;
		}

		$will_render = false;
		$needs_js    = false;

		foreach ( $field_defs as $field_key => $def ) {
			if ( ( $def['display_type'] ?? '' ) !== 'location' ) {
				continue;
			}
			// Effective block placement (per-post override → definition
			// default). `hidden` means no map renders on this post, so no
			// assets are needed for it.
			if ( $plugin->post_data->get_effective_map_position( $post->ID, $field_key, $def ) === 'hidden' ) {
				continue;
			}

			$address = trim( (string) $plugin->post_data->get_field_value( $post->ID, $field_key ) );
			// No per-post address → the map only renders if AISB's business
			// identity supplies one. Check the option's presence (a gate, not
			// the full assembly — AISB owns that); a slight over-enqueue when
			// business_address holds only a country is harmless.
			if ( $address === '' && ! $this->has_business_identity_address() ) {
				continue;
			}

			$will_render = true;
			if ( ( $def['map_load'] ?? 'click' ) !== 'auto' ) {
				$needs_js = true;
			}
		}

		if ( $will_render ) {
			// PRE ships its own map assets — no dependency on Promptless WP.
			// map.css styles the frame/facade/directions with `--aisb-*` tokens
			// plus literal fallbacks; pre-map.js swaps the click facade for the
			// live iframe. Gated: only pages with a rendering location field
			// load them (house pattern, cf. the gallery lightbox above).
			wp_enqueue_style(
				'pcptpages-map',
				PCPTPages_PLUGIN_URL . 'assets/css/map.css',
				array(),
				PCPTPages_VERSION . '.' . (int) @filemtime( PCPTPages_PLUGIN_DIR . 'assets/css/map.css' )
			);
			if ( $needs_js ) {
				wp_enqueue_script(
					'pcptpages-map',
					PCPTPages_PLUGIN_URL . 'assets/js/pre-map.js',
					array(),
					PCPTPages_VERSION . '.' . (int) @filemtime( PCPTPages_PLUGIN_DIR . 'assets/js/pre-map.js' ),
					true
				);
			}
		}
	}

	/**
	 * Whether the shared business-identity address has any non-empty component.
	 * Reads the `aisb_business_settings` option shape (the same option PRE
	 * already reads for currency) — plain option data, not a PHP dependency on
	 * Promptless WP. Used only to gate asset enqueue; PRE assembles the address
	 * itself at render time (PCPTPages_Renderer::get_business_identity_address).
	 *
	 * @return bool
	 */
	private function has_business_identity_address() {
		$settings = get_option( 'aisb_business_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings['business_address'] ) || ! is_array( $settings['business_address'] ) ) {
			return false;
		}
		foreach ( $settings['business_address'] as $part ) {
			if ( is_string( $part ) && trim( $part ) !== '' ) {
				return true;
			}
		}
		return false;
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
