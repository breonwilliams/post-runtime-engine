<?php
/**
 * Unit tests for PCPTPages_Meta_Tags.
 *
 * Focus: the description-resolution logic that feeds <meta name="description">
 * and the OG/Twitter tags — excerpt precedence, content fallback, whitespace
 * normalization, word-boundary truncation — plus the SEO-plugin deference
 * guard. The wp_head gating (is_singular / registered CPT / _aisb_enabled)
 * is exercised by integration tests; these unit tests pin the pure logic.
 *
 * @package PostRuntimeEngine\Tests\Unit
 */

namespace PRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for PCPTPages_Meta_Tags::get_description() and is_seo_plugin_active().
 */
class MetaTagsTest extends UnitTestCase {

	protected function set_up() {
		parent::set_up();
		require_once PRE_TEST_PLUGIN_DIR . 'includes/Frontend/class-pre-meta-tags.php';

		// Text helpers the description resolver leans on. Kept lightweight so
		// the assertions test PCPTPages_Meta_Tags' logic, not WP core.
		Functions\when( 'wp_strip_all_tags' )->alias( function ( $str ) {
			return trim( preg_replace( '/<[^>]*>/', '', (string) $str ) );
		} );
		Functions\when( 'strip_shortcodes' )->alias( function ( $str ) {
			return preg_replace( '/\[[^\]]*\]/', '', (string) $str );
		} );
		Functions\when( 'wp_trim_words' )->alias( function ( $text, $num = 55, $more = null ) {
			$words = preg_split( '/\s+/', trim( (string) $text ) );
			if ( count( $words ) <= $num ) {
				return implode( ' ', $words );
			}
			return implode( ' ', array_slice( $words, 0, $num ) ) . ( $more === null ? '…' : $more );
		} );
	}

	/**
	 * A hand-written excerpt takes precedence and is whitespace-normalized.
	 */
	public function test_excerpt_is_preferred_and_collapsed() {
		Functions\when( 'has_excerpt' )->justReturn( true );
		Functions\when( 'get_the_excerpt' )->justReturn( "  Seasoned   trial\n attorneys.  " );
		Functions\when( 'get_post_field' )->justReturn( 'SHOULD NOT BE USED' );

		$this->assertSame(
			'Seasoned trial attorneys.',
			\PCPTPages_Meta_Tags::get_description( 1 )
		);
	}

	/**
	 * With no excerpt, the description is derived from the post content with
	 * shortcodes and tags stripped.
	 */
	public function test_falls_back_to_content_stripping_shortcodes_and_tags() {
		Functions\when( 'has_excerpt' )->justReturn( false );
		Functions\when( 'get_post_field' )->justReturn( '<p>Car accident help [shortcode] for Floridians.</p>' );

		$this->assertSame(
			'Car accident help for Floridians.',
			\PCPTPages_Meta_Tags::get_description( 7 )
		);
	}

	/**
	 * Long descriptions truncate on a word boundary, cap at ~155 chars, and
	 * gain a trailing ellipsis — never a mid-word cut.
	 */
	public function test_long_description_truncates_on_word_boundary_with_ellipsis() {
		$long = trim( str_repeat( 'recovery ', 60 ) ); // ~540 chars, well over the cap.
		Functions\when( 'has_excerpt' )->justReturn( true );
		Functions\when( 'get_the_excerpt' )->justReturn( $long );

		$result = \PCPTPages_Meta_Tags::get_description( 3 );

		$this->assertLessThanOrEqual( 156, mb_strlen( $result ), 'Description should cap at 155 chars + ellipsis.' );
		$this->assertStringEndsWith( '…', $result );
		// The word before the ellipsis must be whole (no partial "recov…").
		$this->assertMatchesRegularExpression( '/recovery…$/', $result );
	}

	/**
	 * A short description is returned verbatim — no ellipsis appended.
	 */
	public function test_short_description_is_not_truncated() {
		Functions\when( 'has_excerpt' )->justReturn( true );
		Functions\when( 'get_the_excerpt' )->justReturn( 'Short and sweet.' );

		$this->assertSame( 'Short and sweet.', \PCPTPages_Meta_Tags::get_description( 4 ) );
	}

	/**
	 * No excerpt and empty content yields an empty string (so the caller
	 * suppresses the tag rather than emitting an empty description).
	 */
	public function test_empty_content_yields_empty_string() {
		Functions\when( 'has_excerpt' )->justReturn( false );
		Functions\when( 'get_post_field' )->justReturn( '' );

		$this->assertSame( '', \PCPTPages_Meta_Tags::get_description( 5 ) );
	}

	/**
	 * With no SEO plugin constants defined, deference is off by default.
	 */
	public function test_no_seo_plugin_active_by_default() {
		$this->assertFalse( \PCPTPages_Meta_Tags::is_seo_plugin_active() );
	}

	/**
	 * The pcptpages_seo_plugin_active filter can force deference on (e.g. for
	 * an SEO plugin not covered by the built-in constant checks).
	 */
	public function test_seo_plugin_active_can_be_forced_via_filter() {
		Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
			return $tag === 'pcptpages_seo_plugin_active' ? true : $value;
		} );

		$this->assertTrue( \PCPTPages_Meta_Tags::is_seo_plugin_active() );
	}
}
