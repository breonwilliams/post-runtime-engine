<?php
/**
 * Base single-post template for Post Runtime Engine.
 *
 * Selected by PRE_Template_Router for any registered CPT single page that
 * isn't being handled by Promptless WP and isn't overridden by a theme
 * template. Themes can supply their own copy at:
 *   wp-content/themes/{theme}/post-runtime-engine-{cpt}-single.php
 *   wp-content/themes/{theme}/post-runtime-engine-single.php
 *
 * The template intentionally uses get_header() / get_footer() so the
 * theme's chrome (site nav, footer, body classes) wraps our content.
 * Only the body area is owned by the renderer.
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();
	$pre_post = get_post();
	if ( $pre_post instanceof WP_Post ) {
		$pre_renderer = new PRE_Renderer();
		$pre_renderer->render( $pre_post );
	}
endwhile;

get_footer();
