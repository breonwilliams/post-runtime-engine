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
	}
}
