<?php
/**
 * Integration tests for PRE_Renderer.
 *
 * Renders real posts in registered CPTs against a real WP test instance
 * and asserts on the resulting HTML structure. The renderer is the
 * single point where stored data crosses into user-facing output, so
 * tests focus on:
 *
 *   - Structural correctness — the right wrapper elements, classes, data
 *     attributes appear so themes and the design system can target them.
 *   - Position correctness — above_main groupings render before main
 *     content; below_main after; sidebar groupings in <aside>.
 *   - Variant correctness — variant name reaches the CSS class so
 *     stylesheets can apply variant-specific rules.
 *   - Output safety — escaped at every interpolation point. The data is
 *     pre-validated, but the renderer is the last line of defense.
 *   - Cache behavior — second render of unchanged data returns cached
 *     HTML; saving the post invalidates the cache.
 *
 * @package PostRuntimeEngine\Tests\Integration
 */

namespace PRE\Tests\Integration;

class RendererTest extends IntegrationTestCase {

    /**
     * Test post ID. Created in set_up.
     *
     * @var int
     */
    protected $post_id;

    public function set_up() {
        parent::set_up();

        // Standard test fixture: one CPT with two grouping definitions
        // (one above_main, one below_main) and one post.
        $this->register_test_cpt( 'pre_test_listing' );
        $this->plugin->cpts->register_all_with_wp();

        $this->define_test_grouping( 'pre_test_listing', array(
            'key'              => 'features',
            'label'            => 'Features',
            'default_variant'  => 'card-grid',
            'default_position' => 'above_main',
        ) );

        $this->define_test_grouping( 'pre_test_listing', array(
            'key'              => 'amenities',
            'label'            => 'Amenities',
            'default_variant'  => 'compact-grid',
            'default_position' => 'below_main',
        ) );

        $this->post_id = $this->factory->post->create( array(
            'post_type'   => 'pre_test_listing',
            'post_title'  => 'Sunset Villa',
            'post_status' => 'publish',
            'post_content' => '<p>A lovely property.</p>',
        ) );

        // Force logged-out so the cache layer is exercisable. The
        // renderer bypasses cache for users with edit_post capability.
        wp_set_current_user( 0 );
    }

    // -----------------------------------------------------------------
    // Structural correctness
    // -----------------------------------------------------------------

    public function test_render_emits_outer_article_with_post_class_and_post_type_modifier() {
        $html = $this->capture_render( $this->post_id );

        // The article wrapper carries id="post-{ID}" plus post_class()
        // output. The CPT-specific modifier class lets stylesheets target
        // a single CPT's pages.
        $this->assertStringContainsString(
            'id="post-' . $this->post_id . '"',
            $html,
            'Article wrapper must carry the standard post id attribute so other code can target it.'
        );
        $this->assertStringContainsString(
            'pre-single--pre_test_listing',
            $html,
            'Article wrapper must include the per-CPT modifier class so themes can style each CPT differently.'
        );
    }

    public function test_empty_groupings_are_not_rendered() {
        // Production design: groupings with no items don't render — there
        // is no point emitting an empty <section> wrapper with just a
        // heading. The test fixture's set_up defines two groupings
        // (`features`, `amenities`) but does NOT add per-post items, so
        // both should be suppressed.
        //
        // This assertion exists so a future refactor that accidentally
        // starts rendering empty sections (e.g. by removing the
        // `if ( empty( $items ) ) { return; }` short-circuit in
        // PRE_Renderer::render_grouping) will fail this test rather than
        // silently changing user-visible output.
        $html = $this->capture_render( $this->post_id );

        $this->assertStringNotContainsString(
            'pre-grouping--card-grid',
            $html,
            'Empty card-grid grouping must NOT render — production behavior is to suppress empty sections.'
        );
        $this->assertStringNotContainsString(
            'pre-grouping--compact-grid',
            $html,
            'Empty compact-grid grouping must NOT render — same suppression rule.'
        );
        $this->assertStringNotContainsString(
            'data-grouping="features"',
            $html,
            'No data-grouping attribute must reach the rendered HTML when the grouping has no items.'
        );
    }

    public function test_render_emits_grouping_section_per_definition() {
        // Empty groupings are intentionally suppressed by the renderer
        // (see render_grouping(): "Empty groupings are hidden — no point
        // rendering an empty section container with just a heading").
        // To exercise the section-rendering path, populate per-post
        // items for both definitions.
        $this->plugin->post_data->set_groupings( $this->post_id, array(
            array(
                'grouping_key' => 'features',
                'position'     => 'above_main',
                'source'       => 'manual',
                'items'        => array( array( 'heading' => 'Pool', 'icon_id' => 'home' ) ),
            ),
            array(
                'grouping_key' => 'amenities',
                'position'     => 'below_main',
                'source'       => 'manual',
                'items'        => array( array( 'heading' => 'Parking', 'icon_id' => 'home' ) ),
            ),
        ), 'integration_test' );

        $html = $this->capture_render( $this->post_id );

        // Each grouping renders as a <section> with the variant in the
        // class name (so CSS can target variants) and data-grouping
        // identifying the key (so JS can manipulate per-grouping).
        $this->assertStringContainsString(
            'pre-grouping--card-grid',
            $html,
            'Variant must reach the CSS class so stylesheets can apply variant-specific rules.'
        );
        $this->assertStringContainsString(
            'pre-grouping--compact-grid',
            $html,
            'Both groupings must render with their respective variant classes.'
        );
        $this->assertStringContainsString(
            'data-grouping="features"',
            $html,
            'Grouping key must reach the data attribute so JS can target it.'
        );
        $this->assertStringContainsString(
            'data-grouping="amenities"',
            $html,
            'Both groupings must expose their key via data-grouping.'
        );
    }

    // -----------------------------------------------------------------
    // Position correctness
    //
    // The renderer is supposed to lay out groupings in three buckets:
    // above_main → main content → below_main, with sidebar groupings
    // pulled into a separate <aside>. Position drift is the kind of
    // bug that's invisible to most tests but obvious to anyone looking
    // at a rendered page, so we pin the order here.
    // -----------------------------------------------------------------

    public function test_render_orders_above_main_before_main_content_before_below_main() {
        $this->plugin->post_data->set_groupings( $this->post_id, array(
            array(
                'grouping_key' => 'features',
                'position'     => 'above_main',
                'source'       => 'manual',
                'items'        => array( array( 'heading' => 'TOP_OF_PAGE_FEATURE', 'icon_id' => 'home' ) ),
            ),
            array(
                'grouping_key' => 'amenities',
                'position'     => 'below_main',
                'source'       => 'manual',
                'items'        => array( array( 'heading' => 'BOTTOM_OF_PAGE_AMENITY', 'icon_id' => 'home' ) ),
            ),
        ), 'integration_test' );

        $html = $this->capture_render( $this->post_id );

        // Use the main-content WRAPPER as the position marker rather than
        // a specific content string. The wrapper is structural — always
        // emitted for posts that support 'editor' — whereas the_content()
        // output depends on factory-generated post_content + the WP filter
        // chain in the test environment, both of which are outside this
        // test's concern. The TEST'S purpose is to verify position
        // ORDERING, not what the_content() produces.
        $top_pos     = strpos( $html, 'TOP_OF_PAGE_FEATURE' );
        $content_pos = strpos( $html, 'class="pre-content"' );
        $bottom_pos  = strpos( $html, 'BOTTOM_OF_PAGE_AMENITY' );

        $this->assertNotFalse( $top_pos, 'above_main grouping must render.' );
        $this->assertNotFalse( $content_pos, 'Main content wrapper (class="pre-content") must render.' );
        $this->assertNotFalse( $bottom_pos, 'below_main grouping must render.' );

        $this->assertLessThan( $content_pos, $top_pos, 'above_main grouping must appear before main content wrapper.' );
        $this->assertLessThan( $bottom_pos, $content_pos, 'Main content wrapper must appear before below_main grouping.' );
    }

    /**
     * Regression test for the 2026-05-10 connector pressure-test finding
     * where preview_post returned an empty <div class="pre-content"></div>
     * even when the post had real content stored.
     *
     * Root cause: the renderer called setup_postdata( $post ) but did NOT
     * set the global $post itself. WordPress's setup_postdata only updates
     * the derived globals ($id, $authordata, $page, etc.) — the global
     * $post variable has to be set separately. Without that, the_content()
     * → get_post() reads an unset/stale global and returns empty.
     *
     * The fix in PRE_Renderer pins $GLOBALS['post'] explicitly around the
     * the_content() call. This test pins that fix in place — if anyone
     * removes the GLOBALS assignment, the assertion fires.
     *
     * The test simulates the REST-context call site: no global $post set,
     * no the_post() loop in progress. Without the fix, the rendered HTML
     * is missing the post_content; with the fix, it's present.
     */
    public function test_render_includes_post_content_when_no_global_post_is_set() {
        // Defensively unset the global $post to simulate the REST request
        // context where no theme template loop has primed it. This is the
        // exact condition where the bug surfaced on 2026-05-10.
        unset( $GLOBALS['post'] );

        // Update the post with a unique, recognizable content string so we
        // can assert on its presence specifically (not just on a non-empty
        // pre-content div, which could be filled by some default).
        wp_update_post( array(
            'ID'           => $this->post_id,
            'post_content' => '<p>RENDER_CONTENT_MARKER_CONNECTOR_FIX</p>',
        ) );
        clean_post_cache( $this->post_id );

        $html = $this->capture_render( $this->post_id );

        $this->assertStringContainsString(
            'class="pre-content"',
            $html,
            'pre-content wrapper must always render for editor-supporting CPTs.'
        );
        $this->assertStringContainsString(
            'RENDER_CONTENT_MARKER_CONNECTOR_FIX',
            $html,
            'the_content() must emit the post_content even when called outside a theme loop. '
            . 'If this fails, someone removed the $GLOBALS[\'post\'] assignment in PRE_Renderer::render_internal().'
        );
    }

    public function test_render_pulls_sidebar_groupings_into_aside_element() {
        // Add a sidebar grouping definition + per-post entry.
        $this->define_test_grouping( 'pre_test_listing', array(
            'key'              => 'contact',
            'label'            => 'Contact Info',
            'default_variant'  => 'horizontal-row',
            'default_position' => 'sidebar',
        ) );

        $this->plugin->post_data->set_groupings( $this->post_id, array(
            array(
                'grouping_key' => 'contact',
                'position'     => 'sidebar',
                'source'       => 'manual',
                'items'        => array( array( 'heading' => 'CALL_AGENT_HEADING', 'icon_id' => 'phone' ) ),
            ),
        ), 'integration_test' );

        $html = $this->capture_render( $this->post_id );

        // The body wrapper switches to a sidebar variant when sidebar
        // groupings exist, AND the sidebar grouping renders inside <aside>.
        $this->assertStringContainsString(
            'pre-body--with-sidebar',
            $html,
            'Body wrapper must switch to the with-sidebar layout when at least one sidebar grouping exists.'
        );

        // Find the aside region and confirm the sidebar item heading lives inside it.
        $aside_open  = strpos( $html, '<aside class="pre-body__sidebar">' );
        $aside_close = strpos( $html, '</aside>', $aside_open ?: 0 );
        $heading_pos = strpos( $html, 'CALL_AGENT_HEADING' );

        $this->assertNotFalse( $aside_open, 'Sidebar wrapper <aside> must be emitted.' );
        $this->assertNotFalse( $aside_close, 'Sidebar wrapper must be closed.' );
        $this->assertNotFalse( $heading_pos, 'Sidebar item heading must render somewhere.' );
        $this->assertGreaterThan( $aside_open, $heading_pos, 'Sidebar item must render inside <aside>.' );
        $this->assertLessThan( $aside_close, $heading_pos, 'Sidebar item must render before </aside>.' );
    }

    // -----------------------------------------------------------------
    // Variant-specific behavior
    //
    // Per the documented authoring rules: compact-grid and horizontal-row
    // are icon-only — image_id is dropped at render time. Tests pin this
    // so a renderer refactor doesn't accidentally start showing images on
    // icon-only variants.
    // -----------------------------------------------------------------

    public function test_compact_grid_variant_does_not_emit_image_markup() {
        // Create an attachment via the WP factory so image_id resolves
        // through the validator's get_post() check.
        $attachment_id = $this->factory->attachment->create_object( 'compact-grid-test.jpg', $this->post_id, array(
            'post_mime_type' => 'image/jpeg',
            'post_type'      => 'attachment',
        ) );

        $this->plugin->post_data->set_groupings( $this->post_id, array(
            array(
                'grouping_key'     => 'features',
                'position'         => 'above_main',
                'variant_override' => 'compact-grid',  // override card-grid default
                'source'           => 'manual',
                'items'            => array(
                    array(
                        'heading'  => 'POOL_FEATURE',
                        // image_id is allowed by validator (icon_id and image_id
                        // are mutually exclusive but image_id alone is fine on
                        // ANY variant — the variant decides what to do with it).
                        'image_id' => (int) $attachment_id,
                    ),
                ),
            ),
        ), 'integration_test' );

        $html = $this->capture_render( $this->post_id );

        $this->assertStringContainsString(
            'POOL_FEATURE',
            $html,
            'Item heading must render even when its image_id is being dropped.'
        );
        $this->assertStringContainsString(
            'pre-grouping--compact-grid',
            $html,
            'Variant override must reach the CSS class.'
        );

        // The variant must NOT emit <img> for this item — the rule from
        // CLAUDE.md authoring rules: compact-grid is icon-only. Look for
        // an img tag specifically inside this grouping's section.
        if ( preg_match( '|<section class="pre-grouping pre-grouping--compact-grid"[^>]*>.*?</section>|s', $html, $match ) ) {
            $this->assertStringNotContainsString(
                '<img',
                $match[0],
                'compact-grid variant must NOT emit <img> for an item with image_id — icon-only by design (see compact_grid_strips_image rule).'
            );
        } else {
            $this->fail( 'Could not find compact-grid <section> in rendered HTML.' );
        }
    }

    // -----------------------------------------------------------------
    // Output safety
    //
    // Validator rejects script tags etc. on save, but the renderer
    // is the last layer of defense. Pin escape behavior on common
    // attack vectors so a regression in escaping is caught.
    // -----------------------------------------------------------------

    public function test_render_escapes_html_in_item_heading() {
        // The validator allows the literal string "<script>alert(1)</script>"
        // in heading (it's just a string). The renderer is responsible for
        // escaping it on output.
        $this->plugin->post_data->set_groupings( $this->post_id, array(
            array(
                'grouping_key' => 'features',
                'position'     => 'above_main',
                'source'       => 'manual',
                'items'        => array(
                    array( 'heading' => '<script>alert(1)</script>', 'icon_id' => 'home' ),
                ),
            ),
        ), 'integration_test' );

        $html = $this->capture_render( $this->post_id );

        $this->assertStringNotContainsString(
            '<script>alert(1)</script>',
            $html,
            'Renderer must escape heading content — a literal <script> tag in stored data must NOT reach the rendered HTML unescaped.'
        );
        // The escaped form should appear instead.
        $this->assertStringContainsString(
            '&lt;script&gt;',
            $html,
            'Escaped form of <script must appear, confirming esc_html() was applied.'
        );
    }

    // -----------------------------------------------------------------
    // Caching
    // -----------------------------------------------------------------

    public function test_cache_hit_returns_identical_output_on_repeat_render() {
        // Seed groupings.
        $this->plugin->post_data->set_groupings( $this->post_id, array(
            array(
                'grouping_key' => 'features',
                'position'     => 'above_main',
                'source'       => 'manual',
                'items'        => array( array( 'heading' => 'CACHED_HEADING', 'icon_id' => 'home' ) ),
            ),
        ), 'integration_test' );

        // First render with cache enabled — populates the transient.
        $first  = $this->capture_render( $this->post_id, true );

        // Second render — should hit cache. Output should be byte-identical
        // (modulo the optional WP_DEBUG comment).
        $second = $this->capture_render( $this->post_id, true );

        $this->assertSame(
            $this->strip_cache_debug_comment( $first ),
            $this->strip_cache_debug_comment( $second ),
            'Second render must produce identical HTML to the first — that\'s the entire point of the transient cache.'
        );
        $this->assertStringContainsString( 'CACHED_HEADING', $first );
    }

    public function test_cache_invalidates_when_groupings_change() {
        // Render once with one heading.
        $this->plugin->post_data->set_groupings( $this->post_id, array(
            array(
                'grouping_key' => 'features',
                'position'     => 'above_main',
                'source'       => 'manual',
                'items'        => array( array( 'heading' => 'ORIGINAL_HEADING', 'icon_id' => 'home' ) ),
            ),
        ), 'integration_test' );

        $this->capture_render( $this->post_id, true );  // populate cache

        // Update the post's groupings — must invalidate cache for this post.
        $this->plugin->post_data->set_groupings( $this->post_id, array(
            array(
                'grouping_key' => 'features',
                'position'     => 'above_main',
                'source'       => 'manual',
                'items'        => array( array( 'heading' => 'UPDATED_HEADING', 'icon_id' => 'home' ) ),
            ),
        ), 'integration_test' );

        // Manually invalidate (simulating what save_post would do via the
        // hook). The data layer doesn't fire save_post, so we explicitly
        // call the same invalidation function the hook calls.
        \PRE_Renderer::invalidate_post_cache( $this->post_id );

        $html = $this->capture_render( $this->post_id, true );

        $this->assertStringContainsString(
            'UPDATED_HEADING',
            $html,
            'Cache invalidation must result in fresh data appearing on next render.'
        );
        $this->assertStringNotContainsString(
            'ORIGINAL_HEADING',
            $html,
            'Stale cache value must NOT survive an invalidation — that\'s the bug class this whole layer exists to prevent.'
        );
    }

    /**
     * Strip the optional WP_DEBUG cache-status comment so HIT and MISS
     * outputs can be byte-compared for equality.
     */
    private function strip_cache_debug_comment( $html ) {
        return preg_replace( '|\s*<!--\s*pre-render-cache:[^>]*-->\s*|', '', $html );
    }
}
