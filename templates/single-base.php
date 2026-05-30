<?php
/**
 * Base single-post template for Promptless CPT Pages.
 *
 * Selected by PCPTPages_Template_Router for any registered CPT single page that
 * isn't being handled by Promptless WP and isn't overridden by a theme
 * template. Themes can supply their own copy at:
 *   wp-content/themes/{theme}/post-runtime-engine-{cpt}-single.php
 *   wp-content/themes/{theme}/post-runtime-engine-single.php
 *
 * The template intentionally uses get_header() / get_footer() so the
 * theme's chrome (site nav, footer, body classes) wraps our content.
 * Only the body area is owned by the renderer.
 *
 * Theme integration — content theme inheritance:
 *
 *   We wrap our render in a <main id="main-content"> with the same class
 *   set the Promptless theme applies to its own page/single/archive
 *   templates. When the Promptless theme is active and the user has set
 *   "Content theme" to dark in the customizer (Appearance → Customize →
 *   General Settings → Content), the wrapper picks up class
 *   `aisb-section--dark` and our frontend.css automatically flips to
 *   dark-mode rendering.
 *
 *   We rely on function_exists() so the fallback works on any non-
 *   Promptless theme: a plain `<main class="site-main">` without the
 *   theme variant class. In that fallback, our content stays in light
 *   mode (the default), which is the expected graceful degradation.
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

// Mirror the theme's <main> wrapper so the Promptless customizer's
// "Content theme" setting (light/dark) flows through to our render.
// This matches single.php / page.php / archive.php in the Promptless
// theme, all of which output a <main id="main-content"> with class
// "site-main" plus whatever promptless_get_content_classes() returns.
// We always include `site-main` (theme-agnostic class WordPress and
// many themes hook to). When the Promptless theme is active, we append
// its content classes — which include aisb-section--{light|dark} based
// on the customizer's "Content theme" setting.
$pcptpages_main_classes = 'site-main';
if ( function_exists( 'promptless_get_content_classes' ) ) {
	$pcptpages_main_classes .= ' ' . promptless_get_content_classes();
}
?>
<main id="main-content" class="<?php echo esc_attr( $pcptpages_main_classes ); ?>">
<?php

while ( have_posts() ) :
	the_post();
	$pcptpages_post = get_post();
	if ( $pcptpages_post instanceof WP_Post ) {
		$pcptpages_renderer = new PCPTPages_Renderer();
		$pcptpages_renderer->render( $pcptpages_post );
	}
endwhile;

?>
</main>
<?php

get_footer();
