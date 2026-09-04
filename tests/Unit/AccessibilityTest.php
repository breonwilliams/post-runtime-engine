<?php
/**
 * Accessibility contract for the CPT single template.
 *
 * This plugin renders most of the pages on a content-heavy site, and its
 * template is a copy of the theme's `<main>` wrapper. That copying is the
 * hazard: when the theme's nine templates gained `tabindex="-1"` so the skip
 * link would actually move focus, this copy did not. Activating "Skip to
 * content" scrolled the page and left focus at the top — the link skipped
 * nothing, on exactly the pages that dominate a large site.
 *
 * Nothing would have caught that. It was found by driving a page with a
 * keyboard, and axe reports zero violations either way: a skip link that
 * scrolls without moving focus is indistinguishable, in a static snapshot,
 * from one that works.
 *
 * The template is a PHP view rather than a function, and rendering it needs a
 * WordPress runtime the unit suite does not have. So the contract is asserted
 * against the template SOURCE. That is a weaker check than rendering — it
 * cannot tell you the attribute survived to the browser — but it is the check
 * that fits here, and it holds the line that was actually crossed.
 *
 * @package PostRuntimeEngine\Tests\Unit
 */

namespace PRE\Tests\Unit;

/**
 * Template-level accessibility contract.
 */
class AccessibilityTest extends UnitTestCase {

    /**
     * Reads the single-post template source.
     *
     * @return string
     */
    private function template_source() {
        $path = dirname( dirname( __DIR__ ) ) . '/templates/single-base.php';
        $this->assertFileExists( $path, 'templates/single-base.php is missing' );
        return $this->strip_comments( file_get_contents( $path ) );
    }

    /**
     * Removes PHP comments so assertions read the markup, not the prose.
     *
     * This file's own header explains the wrapper it renders and names
     * `<main id="main-content">` seven times. Counting those would report the
     * template as having seven main landmarks — and worse, an assertion about
     * an attribute could be satisfied by a comment DESCRIBING the attribute
     * while the tag itself had lost it. Both happened on the first run.
     *
     * @param string $source Raw file contents.
     * @return string Source with comments removed.
     */
    private function strip_comments( $source ) {
        $out = '';
        foreach ( token_get_all( $source ) as $token ) {
            if ( is_array( $token ) ) {
                if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }

    /**
     * The skip link's target must be able to receive focus.
     *
     * A <main> is not focusable by default. Without tabindex="-1" the browser
     * moves the viewport to the fragment and leaves focus where it was, so a
     * keyboard user lands back at the top of the navigation on the next Tab.
     */
    public function test_main_landmark_can_receive_focus() {
        $source = $this->template_source();

        $this->assertMatchesRegularExpression(
            '/<main[^>]*\bid=["\']main-content["\'][^>]*>/',
            $source,
            'The template no longer renders <main id="main-content">, which is what the theme\'s skip link targets.'
        );

        $this->assertMatchesRegularExpression(
            '/<main[^>]*\btabindex=["\']-1["\'][^>]*>/',
            $source,
            'The <main> landmark has lost tabindex="-1", so "Skip to content" scrolls the page without moving focus — the link skips nothing.'
        );
    }

    /**
     * The focusable target must not also be a visible tab stop.
     *
     * tabindex="-1" is programmatically focusable and skipped by Tab, which is
     * the whole point. A positive value would put the page body into the tab
     * order ahead of the navigation.
     */
    public function test_main_landmark_is_not_a_tab_stop() {
        $source = $this->template_source();

        $this->assertDoesNotMatchRegularExpression(
            '/<main[^>]*\btabindex=["\'](0|[1-9][0-9]*)["\'][^>]*>/',
            $source,
            'The <main> landmark is in the tab order; it should be tabindex="-1" — focusable on demand, skipped by Tab.'
        );
    }

    /**
     * Exactly one main landmark.
     *
     * The template deliberately mirrors the theme's wrapper rather than nesting
     * inside it; two <main> elements would leave a screen reader's landmark
     * list ambiguous about where the content starts.
     */
    public function test_template_renders_a_single_main_landmark() {
        $source = $this->template_source();

        $this->assertSame(
            1,
            preg_match_all( '/<main\b/', $source ),
            'The template renders more than one <main> element.'
        );
    }
}
