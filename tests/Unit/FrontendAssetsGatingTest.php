<?php
/**
 * Frontend stylesheets are gated to the pages that use them.
 *
 * Measured 2026-09-13 with CSS coverage on the demo site: the post-type
 * archive loaded frontend.css (72 KB) at 0% use — an archive renders the
 * theme's cards, which cards.css styles; frontend.css is the single's
 * hero/groupings/body/gallery. The contract this pins:
 *
 *   1. `pcptpages-frontend` is enqueued only on singles;
 *   2. `pcptpages-cards` is enqueued on both, depending on frontend.css
 *      only on a single;
 *   3. cards.css defines every `--pre-*` custom property it reads, so it
 *      can stand alone on an archive and on the late-inject path.
 *
 * Pure file inspection; no WordPress.
 *
 * @package PostRuntimeEngine\Tests\Unit
 */

namespace PRE\Tests\Unit;

class FrontendAssetsGatingTest extends UnitTestCase {

	public function test_frontend_stylesheet_is_enqueued_only_on_singles() {
		$src = (string) file_get_contents( PRE_TEST_PLUGIN_DIR . 'includes/Frontend/class-pre-frontend-assets.php' );
		$this->assertSame( 1, preg_match( '/\$is_single = is_singular\(\);/', $src ) );
		$this->assertSame(
			1,
			preg_match( "/if \( \\\$is_single \) \{\s*wp_enqueue_style\(\s*'pcptpages-frontend'/s", $src ),
			'pcptpages-frontend must be enqueued inside the is_single branch'
		);
		$this->assertSame(
			1,
			preg_match( "/'pcptpages-cards',\s*PCPTPages_PLUGIN_URL \. 'assets\/css\/cards\.css',\s*\\\$is_single \? array\( 'pcptpages-frontend' \) : array\(\)/s", $src ),
			'pcptpages-cards must depend on frontend.css only on a single'
		);
	}

	public function test_cards_stylesheet_defines_every_pre_variable_it_reads() {
		$css = (string) file_get_contents( PRE_TEST_PLUGIN_DIR . 'assets/css/cards.css' );
		preg_match_all( '/var\(\s*(--pre-[a-z0-9-]+)/', $css, $used );
		preg_match_all( '/(--pre-[a-z0-9-]+)\s*:/', $css, $defined );
		$missing = array_values( array_diff( array_unique( $used[1] ), array_unique( $defined[1] ) ) );
		$this->assertSame( array(), $missing, 'cards.css reads --pre-* variables it does not define; it must stand alone on archives' );
	}
}
