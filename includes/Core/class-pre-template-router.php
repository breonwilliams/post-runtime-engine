<?php
/**
 * Template router for Promptless CPT Pages.
 *
 * Hooks `template_include` to take over single-post rendering for any CPT
 * registered with this plugin. Honors theme overrides via locate_template,
 * and defers entirely to Promptless WP when `_aisb_enabled` is set on the
 * post (so a flagship listing can still be hand-built as a Promptless page
 * while the rest of the CPT renders through this template).
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * template_include filter handler.
 */
class PRE_Template_Router {

	/**
	 * Constructor. Registers the filter at high priority so we run after
	 * theme template hierarchy has resolved but before anything else
	 * intercepts rendering.
	 */
	public function __construct() {
		add_filter( 'template_include', array( $this, 'maybe_use_template' ), 999 );
	}

	/**
	 * Decide whether to substitute our template for the current request.
	 *
	 * @param string $template Template path resolved by WP core.
	 * @return string
	 */
	public function maybe_use_template( $template ) {
		if ( ! is_singular() ) {
			return $template;
		}

		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) ) {
			return $template;
		}

		$plugin = pre();
		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $post->post_type ) ) {
			return $template;
		}

		// Promptless WP precedence: if the post has _aisb_enabled set, hand
		// off to Promptless's renderer. This is the documented coexistence
		// pattern in INTEGRATION_PROMPTLESS.md — per-post Promptless wins.
		if ( get_post_meta( $post->ID, '_aisb_enabled', true ) ) {
			return $template;
		}

		// Theme override: a theme can supply post-runtime-engine-{cpt}-single.php
		// or a generic post-runtime-engine-single.php. locate_template returns
		// the first match it finds in the active theme.
		$theme_template = locate_template(
			array(
				'post-runtime-engine-' . $post->post_type . '-single.php',
				'post-runtime-engine-single.php',
			)
		);

		if ( $theme_template ) {
			return $theme_template;
		}

		// Fall back to the plugin-bundled template.
		return PRE_PLUGIN_DIR . 'templates/single-base.php';
	}
}
