<?php
/**
 * Unit tests for PCPTPages_Validator.
 *
 * Focus: enforce that the contract documented in CLAUDE.md and
 * docs/CONNECTOR_SPEC.md is actually enforced by the validator. Each
 * "critical_rules" entry surfaced via the connector preflight should
 * have a corresponding validator test below — when the rule changes,
 * the failing test catches the drift.
 *
 * @package PostRuntimeEngine\Tests\Unit
 */

namespace PRE\Tests\Unit;

/**
 * Tests for PCPTPages_Validator.
 */
class ValidatorTest extends UnitTestCase {

    /**
     * Validator instance.
     *
     * @var \PCPTPages_Validator
     */
    private $validator;

    protected function set_up() {
        parent::set_up();
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-validator.php';
        // Load the icon library too: PCPTPages_Validator::validate_grouping_item()
        // gates its icon_id validity check on class_exists( 'PCPTPages_Icon_Library' ),
        // so without this the icon-format assertions are silently skipped.
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-icon-library.php';
        $this->validator = new \PCPTPages_Validator();
    }

    // -----------------------------------------------------------------
    // Constants — these are the contract surface; pin them.
    // -----------------------------------------------------------------

    public function test_variants_constant_lists_exactly_four_options() {
        $this->assertSame(
            array( 'compact-grid', 'card-grid', 'featured-card', 'horizontal-row' ),
            \PCPTPages_Validator::VARIANTS,
            'VARIANTS must list exactly the four documented variants. Adding a fifth requires an architectural conversation per CLAUDE.md.'
        );
    }

    public function test_positions_constant_lists_exactly_three_positions() {
        $this->assertSame(
            array( 'above_main', 'below_main', 'sidebar' ),
            \PCPTPages_Validator::POSITIONS,
            'POSITIONS must list exactly the three documented positions. CLAUDE.md guardrail says do not add a fourth without an architectural conversation.'
        );
    }

    public function test_source_modes_constant_lists_exactly_four_modes() {
        $this->assertSame(
            array( 'manual', 'child_posts', 'taxonomy_match', 'meta_match' ),
            \PCPTPages_Validator::SOURCE_MODES,
            'SOURCE_MODES must list exactly the four documented modes (manual, child_posts, taxonomy_match, meta_match).'
        );
    }

    public function test_meta_match_against_constant_lists_exactly_four_values() {
        $this->assertSame(
            array( 'same_key', 'current_id', 'current_slug', 'current_title' ),
            \PCPTPages_Validator::META_MATCH_AGAINST,
            'META_MATCH_AGAINST must list exactly the four documented values (data-version 0.5.0). Do not extend without updating docs/CONNECTOR_SPEC.md and the connector source_modes descriptor first.'
        );
    }

    public function test_reserved_cpt_slugs_includes_wp_core_and_woocommerce() {
        // Spot-check a few critical reserved slugs. If WordPress adds new
        // built-in post types or this list expands for a new plugin, this
        // test should be updated alongside the constant.
        $reserved = \PCPTPages_Validator::RESERVED_CPT_SLUGS;
        foreach ( array( 'post', 'page', 'attachment', 'wp_template', 'product' ) as $must_be_reserved ) {
            $this->assertContains(
                $must_be_reserved,
                $reserved,
                "Reserved slug list must include '{$must_be_reserved}' to prevent collision with WP core / WooCommerce."
            );
        }
    }

    // -----------------------------------------------------------------
    // CPT definition validation
    // -----------------------------------------------------------------

    public function test_validate_cpt_definition_rejects_non_array_input() {
        $result = $this->validator->validate_cpt_definition( 'not-an-array' );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_cpt', $result->get_error_code() );
    }

    public function test_validate_cpt_definition_rejects_missing_slug() {
        $result = $this->validator->validate_cpt_definition( array(
            'label_singular' => 'Listing',
            'label_plural'   => 'Listings',
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_missing_slug', $result->get_error_code() );
    }

    public function test_validate_cpt_definition_rejects_uppercase_slug() {
        $result = $this->validator->validate_cpt_definition( array(
            'slug'           => 'Listing',
            'label_singular' => 'Listing',
            'label_plural'   => 'Listings',
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_slug', $result->get_error_code() );
    }

    public function test_validate_cpt_definition_rejects_slug_over_20_chars() {
        $result = $this->validator->validate_cpt_definition( array(
            'slug'           => str_repeat( 'a', 21 ),
            'label_singular' => 'X',
            'label_plural'   => 'Xs',
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        // Per WP CPT slug max — explicit error code lives in the validator.
        $this->assertContains(
            $result->get_error_code(),
            array( 'pcptpages_slug_too_long', 'pcptpages_invalid_slug' ),
            'Over-long slug must be rejected with a slug-related error code.'
        );
    }

    public function test_validate_cpt_definition_rejects_reserved_slug() {
        $result = $this->validator->validate_cpt_definition( array(
            'slug'           => 'page',
            'label_singular' => 'X',
            'label_plural'   => 'Xs',
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_reserved_slug', $result->get_error_code() );
    }

    public function test_validate_cpt_definition_accepts_minimal_valid_definition() {
        $result = $this->validator->validate_cpt_definition( array(
            'slug'           => 'listing',
            'label_singular' => 'Listing',
            'label_plural'   => 'Listings',
        ) );
        $this->assertTrue( $result, 'A minimal valid CPT (slug + both labels) must validate cleanly.' );
    }

    // -----------------------------------------------------------------
    // Hero contrast band + overlay (docs/HERO_CONTRAST_DESIGN.md).
    //
    // These enums are the Phase A/B contract surface. The kitchen-sink
    // incident history (v1.2.5 theme zip, badge contrast) is why every
    // closed enum gets pinned: extending one without updating the design
    // contract must fail a test, not slip through review.
    // -----------------------------------------------------------------

    public function test_hero_themes_constant_lists_exactly_three_modes() {
        $this->assertSame(
            array( 'inherit', 'light', 'dark' ),
            \PCPTPages_Validator::HERO_THEMES,
            'HERO_THEMES is a closed enum per docs/HERO_CONTRAST_DESIGN.md — do not extend without updating the contract first.'
        );
    }

    public function test_hero_widths_constant_lists_exactly_two_modes() {
        $this->assertSame(
            array( 'contained', 'full' ),
            \PCPTPages_Validator::HERO_WIDTHS,
            'HERO_WIDTHS is a closed enum per docs/HERO_CONTRAST_DESIGN.md — do not extend without updating the contract first.'
        );
    }

    public function test_hero_overlay_focus_constant_lists_exactly_three_values() {
        $this->assertSame(
            array( 'top', 'center', 'bottom' ),
            \PCPTPages_Validator::HERO_OVERLAY_FOCUS,
            'HERO_OVERLAY_FOCUS is a closed enum per docs/HERO_CONTRAST_DESIGN.md — do not extend without updating the contract first.'
        );
    }

    /**
     * Minimal valid CPT definition with one hero field overridden.
     *
     * @param string $key   Hero field key.
     * @param mixed  $value Value under test.
     * @return array
     */
    private function cpt_with( $key, $value ) {
        return array(
            'slug'           => 'listing',
            'label_singular' => 'Listing',
            'label_plural'   => 'Listings',
            $key             => $value,
        );
    }

    public function test_validate_cpt_definition_accepts_overlay_hero_layout() {
        $result = $this->validator->validate_cpt_definition( $this->cpt_with( 'hero_layout', 'overlay' ) );
        $this->assertTrue( $result, 'overlay is a valid hero_layout since Phase B.' );
    }

    public function test_validate_cpt_definition_rejects_unknown_hero_layout() {
        $result = $this->validator->validate_cpt_definition( $this->cpt_with( 'hero_layout', 'banner' ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_hero_layout', $result->get_error_code() );
    }

    public function test_validate_cpt_definition_accepts_each_valid_hero_theme() {
        foreach ( \PCPTPages_Validator::HERO_THEMES as $theme ) {
            $result = $this->validator->validate_cpt_definition( $this->cpt_with( 'hero_theme', $theme ) );
            $this->assertTrue( $result, "hero_theme '{$theme}' must validate cleanly." );
        }
    }

    public function test_validate_cpt_definition_rejects_unknown_hero_theme() {
        $result = $this->validator->validate_cpt_definition( $this->cpt_with( 'hero_theme', 'midnight' ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_hero_theme', $result->get_error_code() );
    }

    public function test_validate_cpt_definition_rejects_non_string_hero_theme() {
        $result = $this->validator->validate_cpt_definition( $this->cpt_with( 'hero_theme', true ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_hero_theme', $result->get_error_code() );
    }

    public function test_validate_cpt_definition_accepts_each_valid_hero_width() {
        foreach ( \PCPTPages_Validator::HERO_WIDTHS as $width ) {
            $result = $this->validator->validate_cpt_definition( $this->cpt_with( 'hero_width', $width ) );
            $this->assertTrue( $result, "hero_width '{$width}' must validate cleanly." );
        }
    }

    public function test_validate_cpt_definition_rejects_unknown_hero_width() {
        $result = $this->validator->validate_cpt_definition( $this->cpt_with( 'hero_width', 'fullscreen' ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_hero_width', $result->get_error_code() );
    }

    public function test_validate_cpt_definition_accepts_each_valid_hero_overlay_focus() {
        foreach ( \PCPTPages_Validator::HERO_OVERLAY_FOCUS as $focus ) {
            $result = $this->validator->validate_cpt_definition( $this->cpt_with( 'hero_overlay_focus', $focus ) );
            $this->assertTrue( $result, "hero_overlay_focus '{$focus}' must validate cleanly." );
        }
    }

    public function test_validate_cpt_definition_rejects_unknown_hero_overlay_focus() {
        $result = $this->validator->validate_cpt_definition( $this->cpt_with( 'hero_overlay_focus', 'left' ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_hero_overlay_focus', $result->get_error_code() );
    }

    public function test_validate_cpt_definition_accepts_each_valid_archive_image_aspect() {
        foreach ( \PCPTPages_Validator::ARCHIVE_IMAGE_ASPECTS as $aspect ) {
            $result = $this->validator->validate_cpt_definition( $this->cpt_with( 'archive_image_aspect', $aspect ) );
            $this->assertTrue( $result, "archive_image_aspect '{$aspect}' must validate cleanly." );
        }
    }

    public function test_validate_cpt_definition_rejects_unknown_archive_image_aspect() {
        // 'square' is the HERO aspect vocabulary — the archive enum speaks
        // ratio strings ('1:1'), so the word form must be rejected.
        $result = $this->validator->validate_cpt_definition( $this->cpt_with( 'archive_image_aspect', 'square' ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_archive_image_aspect', $result->get_error_code() );
    }

    public function test_archive_image_aspects_constant_lists_exactly_four_values() {
        $this->assertSame( array( '16:9', '4:3', '1:1', '4:5' ), \PCPTPages_Validator::ARCHIVE_IMAGE_ASPECTS );
    }

    public function test_validate_cpt_definition_accepts_full_phase_a_b_combination() {
        $result = $this->validator->validate_cpt_definition( array(
            'slug'               => 'listing',
            'label_singular'     => 'Listing',
            'label_plural'       => 'Listings',
            'hero_layout'        => 'overlay',
            'hero_theme'         => 'dark',
            'hero_width'         => 'full',
            'hero_overlay_focus' => 'top',
        ) );
        $this->assertTrue( $result, 'The full opt-in combination (overlay + dark + full + top) must validate cleanly.' );
    }

    // -----------------------------------------------------------------
    // Grouping definition validation
    // -----------------------------------------------------------------

    public function test_validate_grouping_definition_rejects_unknown_variant() {
        $result = $this->validator->validate_grouping_definition( array(
            'key'              => 'features',
            'label'            => 'Features',
            'default_variant'  => 'invalid-variant',
            'default_position' => 'above_main',
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame(
            'pcptpages_invalid_default_variant',
            $result->get_error_code(),
            'Unknown variants must be rejected — VARIANTS constant is the source of truth.'
        );
    }

    public function test_validate_grouping_definition_rejects_unknown_position() {
        $result = $this->validator->validate_grouping_definition( array(
            'key'              => 'features',
            'label'            => 'Features',
            'default_variant'  => 'card-grid',
            'default_position' => 'middle',  // not in POSITIONS
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_default_position', $result->get_error_code() );
    }

    public function test_validate_grouping_definition_accepts_minimal_valid_definition() {
        $result = $this->validator->validate_grouping_definition( array(
            'key'              => 'features',
            'label'            => 'Features',
            'default_variant'  => 'card-grid',
            'default_position' => 'above_main',
        ) );
        $this->assertTrue( $result );
    }

    // -----------------------------------------------------------------
    // Grouping item validation — the meat of authoring quality
    // -----------------------------------------------------------------

    public function test_validate_grouping_item_accepts_iconify_code_in_icon_id() {
        // Fix #2 (v0.4.0): icon_id accepts Iconify codes alongside legacy
        // curated IDs. mdi:home should pass through the validator unchanged
        // and render via <iconify-icon> at render time.
        $definition = array(
            'key'             => 'features',
            'label'           => 'Features',
            'default_variant' => 'card-grid',
        );

        $cases = array(
            'mdi:home',
            'logos:wordpress',
            'material-symbols:business-outline',
            'heroicons:arrow-right-circle',
            'fa6-solid:tooth',
        );
        foreach ( $cases as $code ) {
            $result = $this->validator->validate_grouping_item(
                array(
                    'heading' => 'Item',
                    'icon_id' => $code,
                ),
                $definition
            );
            $this->assertTrue( $result, sprintf( 'Iconify code "%s" should validate', $code ) );
        }
    }

    public function test_validate_grouping_item_rejects_invalid_iconify_format() {
        // Malformed inputs that look like Iconify but aren't — still rejected.
        $definition = array(
            'key'             => 'features',
            'label'           => 'Features',
            'default_variant' => 'card-grid',
        );

        $invalid_cases = array(
            'mdi:'                         => 'trailing colon',
            ':home'                        => 'leading colon',
            'mdi: home'                    => 'whitespace inside',
            'MDI:HOME'                     => 'uppercase rejected (sanitize-key shape)',
            'not.an.icon'                  => 'dots, no colon',
            str_repeat( 'a', 101 ) . ':b'  => 'over MAX_ICONIFY_LENGTH',
        );
        foreach ( $invalid_cases as $bad => $reason ) {
            $result = $this->validator->validate_grouping_item(
                array(
                    'heading' => 'Item',
                    'icon_id' => $bad,
                ),
                $definition
            );
            $this->assertInstanceOf( '\\WP_Error', $result, sprintf( 'Should reject "%s" (%s)', $bad, $reason ) );
            $this->assertSame( 'pcptpages_unknown_icon', $result->get_error_code(), sprintf( 'Wrong error code for "%s"', $bad ) );
        }
    }

    public function test_validate_grouping_item_rejects_both_icon_id_and_image_id() {
        // From critical_rules.compact_grid_strips_image rationale:
        // icon_id and image_id are mutually exclusive on a single item.
        $definition = array(
            'key'             => 'team',
            'label'           => 'Team',
            'default_variant' => 'card-grid',
        );
        $result = $this->validator->validate_grouping_item(
            array(
                'heading'  => 'Member',
                'icon_id'  => 'user',
                'image_id' => 42,
            ),
            $definition
        );
        $this->assertInstanceOf( '\\WP_Error', $result );
        // Pin the rule even if the exact code changes — keep the assertion narrow.
        $this->assertStringContainsString(
            'icon',
            strtolower( $result->get_error_message() ),
            'Error message must reference the icon/image conflict so authoring agents can self-correct.'
        );
    }

    // -----------------------------------------------------------------
    // Grouping definition — featured-card single-item constraint
    //
    // Per CLAUDE.md: featured-card is a one-item-per-grouping variant.
    // The validator enforces this at write time so author intent is
    // captured early, not silently truncated at render time.
    // -----------------------------------------------------------------

    public function test_validate_grouping_definition_rejects_featured_card_with_max_items_above_one() {
        $result = $this->validator->validate_grouping_definition( array(
            'key'              => 'hero',
            'label'            => 'Hero Card',
            'default_variant'  => 'featured-card',
            'default_position' => 'above_main',
            'max_items'        => 3,
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_featured_card_max_items', $result->get_error_code() );
    }

    public function test_validate_grouping_definition_accepts_featured_card_with_max_items_one() {
        $result = $this->validator->validate_grouping_definition( array(
            'key'              => 'hero',
            'label'            => 'Hero Card',
            'default_variant'  => 'featured-card',
            'default_position' => 'above_main',
            'max_items'        => 1,
        ) );
        $this->assertTrue( $result, 'featured-card with max_items=1 is the canonical valid form.' );
    }

    public function test_validate_grouping_definition_accepts_featured_card_without_max_items() {
        // Omitting max_items is the same as max_items=1 for featured-card,
        // because the variant CSS only renders the first item regardless.
        $result = $this->validator->validate_grouping_definition( array(
            'key'              => 'hero',
            'label'            => 'Hero Card',
            'default_variant'  => 'featured-card',
            'default_position' => 'above_main',
        ) );
        $this->assertTrue( $result, 'Omitted max_items must be allowed for featured-card.' );
    }

    // -----------------------------------------------------------------
    // Grouping definition — max_items bounds
    // -----------------------------------------------------------------

    public function test_validate_grouping_definition_rejects_zero_max_items() {
        $result = $this->validator->validate_grouping_definition( array(
            'key'              => 'features',
            'label'            => 'Features',
            'default_variant'  => 'card-grid',
            'default_position' => 'above_main',
            'max_items'        => 0,
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_max_items', $result->get_error_code() );
    }

    public function test_validate_grouping_definition_rejects_max_items_above_hard_cap() {
        // MAX_ITEMS_PER_GROUPING = 100. Asking for more is a sign of
        // misuse — at that point the grouping primitive isn't the right
        // tool and the validator should push back rather than accept.
        $result = $this->validator->validate_grouping_definition( array(
            'key'              => 'features',
            'label'            => 'Features',
            'default_variant'  => 'card-grid',
            'default_position' => 'above_main',
            'max_items'        => \PCPTPages_Validator::MAX_ITEMS_PER_GROUPING + 1,
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_max_items_too_large', $result->get_error_code() );
    }

    // -----------------------------------------------------------------
    // Source value validation — taxonomy_match special case
    //
    // The string form is rejected for taxonomy_match because the
    // taxonomy slug is essential information that can't be defaulted.
    // The object form is required so the slug and optional limit /
    // exclude_self params travel together.
    // -----------------------------------------------------------------

    public function test_validate_source_value_rejects_taxonomy_match_as_bare_string() {
        $result = $this->validator->validate_source_value( 'taxonomy_match' );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame(
            'pcptpages_taxonomy_match_needs_object',
            $result->get_error_code(),
            'Bare-string "taxonomy_match" must fail — the taxonomy slug is required and not defaultable.'
        );
    }

    public function test_validate_source_value_accepts_taxonomy_match_object_with_taxonomy() {
        $result = $this->validator->validate_source_value( array(
            'type'         => 'taxonomy_match',
            'taxonomy'     => 'category',
            'limit'        => 5,
            'exclude_self' => true,
        ) );
        $this->assertTrue( $result, 'Fully-formed taxonomy_match object must validate cleanly.' );
    }

    public function test_validate_source_value_rejects_taxonomy_match_object_without_taxonomy() {
        $result = $this->validator->validate_source_value( array(
            'type' => 'taxonomy_match',
            // missing 'taxonomy'
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_source_taxonomy', $result->get_error_code() );
    }

    public function test_validate_source_value_rejects_taxonomy_match_with_invalid_limit() {
        $result = $this->validator->validate_source_value( array(
            'type'     => 'taxonomy_match',
            'taxonomy' => 'category',
            'limit'    => 0,  // must be >= 1
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_source_limit', $result->get_error_code() );
    }

    // -----------------------------------------------------------------
    // Source value validation — meta_match
    //
    // meta_match mirrors taxonomy_match's shape rules: bare-string form
    // is rejected because the meta_key is required, and the object form
    // validates the meta_key + optional limit + optional exclude_self.
    // The meta_key validation tolerates a single leading underscore (the
    // WordPress private-meta convention) but otherwise enforces the
    // sanitize_key canonical form.
    // -----------------------------------------------------------------

    public function test_validate_source_value_rejects_meta_match_as_bare_string() {
        $result = $this->validator->validate_source_value( 'meta_match' );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame(
            'pcptpages_meta_match_needs_object',
            $result->get_error_code(),
            'Bare-string "meta_match" must fail — the meta_key is required and not defaultable.'
        );
    }

    public function test_validate_source_value_accepts_meta_match_object_with_meta_key() {
        $result = $this->validator->validate_source_value( array(
            'type'         => 'meta_match',
            'meta_key'     => '_agent_id',
            'limit'        => 6,
            'exclude_self' => true,
        ) );
        $this->assertTrue( $result, 'Fully-formed meta_match object must validate cleanly.' );
    }

    public function test_validate_source_value_accepts_meta_match_with_underscore_prefix() {
        $result = $this->validator->validate_source_value( array(
            'type'     => 'meta_match',
            'meta_key' => '_employer_id',
        ) );
        $this->assertTrue( $result, 'A single leading underscore (private-meta convention) must be accepted.' );
    }

    public function test_validate_source_value_accepts_meta_match_without_underscore() {
        $result = $this->validator->validate_source_value( array(
            'type'     => 'meta_match',
            'meta_key' => 'brand_id',
        ) );
        $this->assertTrue( $result, 'Public-meta keys (no underscore prefix) must also be accepted.' );
    }

    public function test_validate_source_value_rejects_meta_match_object_without_meta_key() {
        $result = $this->validator->validate_source_value( array(
            'type' => 'meta_match',
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_source_meta_key', $result->get_error_code() );
    }

    public function test_validate_source_value_rejects_meta_match_with_empty_meta_key() {
        $result = $this->validator->validate_source_value( array(
            'type'     => 'meta_match',
            'meta_key' => '',
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_source_meta_key', $result->get_error_code() );
    }

    public function test_validate_source_value_rejects_meta_match_with_uppercase_meta_key() {
        $result = $this->validator->validate_source_value( array(
            'type'     => 'meta_match',
            'meta_key' => 'AgentId',  // sanitize_key would lowercase + drop chars; we reject silent transforms
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_source_meta_key', $result->get_error_code() );
    }

    public function test_validate_source_value_rejects_meta_match_with_meta_key_too_long() {
        $result = $this->validator->validate_source_value( array(
            'type'     => 'meta_match',
            'meta_key' => str_repeat( 'a', 65 ),  // MAX_META_KEY_LENGTH is 64
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_source_meta_key_length', $result->get_error_code() );
    }

    public function test_validate_source_value_rejects_meta_match_with_invalid_limit() {
        $result = $this->validator->validate_source_value( array(
            'type'     => 'meta_match',
            'meta_key' => '_agent_id',
            'limit'    => 0,
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_source_limit', $result->get_error_code() );
    }

    public function test_validate_source_value_rejects_meta_match_with_non_bool_exclude_self() {
        $result = $this->validator->validate_source_value( array(
            'type'         => 'meta_match',
            'meta_key'     => '_agent_id',
            'exclude_self' => 'yes',  // string instead of bool
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_source_exclude_self', $result->get_error_code() );
    }

    // -----------------------------------------------------------------
    // Source value validation — meta_match reverse-lookup params
    // (field_key / post_type / match_against, data-version 0.5.0)
    //
    // These guard the cross-CPT parent-pulls-children shape: an Agent
    // page pulling Listings whose post-field names this agent. field_key
    // references a PRE post-field (resolved to _pcptpages_field_{key} at
    // render); exactly one of meta_key/field_key is required.
    // -----------------------------------------------------------------

    public function test_validate_source_value_accepts_meta_match_with_field_key() {
        $result = $this->validator->validate_source_value( array(
            'type'      => 'meta_match',
            'field_key' => 'agent',
        ) );
        $this->assertTrue( $result, 'field_key alone must satisfy the key requirement (connector-writable post-field form).' );
    }

    public function test_validate_source_value_accepts_meta_match_reverse_lookup_shape() {
        $result = $this->validator->validate_source_value( array(
            'type'          => 'meta_match',
            'field_key'     => 'agent',
            'post_type'     => 'listing',
            'match_against' => 'current_title',
            'limit'         => 12,
            'exclude_self'  => true,
        ) );
        $this->assertTrue( $result, 'The full cross-CPT reverse-lookup shape (the real-estate agent→listings pattern) must validate cleanly.' );
    }

    public function test_validate_source_value_rejects_meta_match_with_both_meta_key_and_field_key() {
        $result = $this->validator->validate_source_value( array(
            'type'      => 'meta_match',
            'meta_key'  => '_agent_id',
            'field_key' => 'agent',
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame(
            'pcptpages_meta_match_key_conflict',
            $result->get_error_code(),
            'meta_key and field_key are mutually exclusive — ambiguous key resolution must be rejected, not guessed.'
        );
    }

    public function test_validate_source_value_rejects_meta_match_field_key_with_underscore_prefix() {
        // Post-field keys are plain (the _pcptpages_field_ storage prefix is
        // added by the resolver) — a leading underscore signals the caller
        // confused field_key with a raw meta_key.
        $result = $this->validator->validate_source_value( array(
            'type'      => 'meta_match',
            'field_key' => '_agent',
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_source_field_key', $result->get_error_code() );
    }

    public function test_validate_source_value_rejects_meta_match_field_key_with_uppercase() {
        $result = $this->validator->validate_source_value( array(
            'type'      => 'meta_match',
            'field_key' => 'AgentName',  // sanitize_key would silently transform; we reject
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_source_field_key', $result->get_error_code() );
    }

    public function test_validate_source_value_rejects_meta_match_field_key_too_long() {
        $result = $this->validator->validate_source_value( array(
            'type'      => 'meta_match',
            'field_key' => str_repeat( 'a', 65 ),  // MAX_META_KEY_LENGTH is 64
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_source_field_key_length', $result->get_error_code() );
    }

    public function test_validate_source_value_accepts_meta_match_with_valid_post_type() {
        $result = $this->validator->validate_source_value( array(
            'type'      => 'meta_match',
            'meta_key'  => '_agent_id',
            'post_type' => 'listing',
        ) );
        $this->assertTrue( $result, 'A sanitize_key-form post_type must be accepted (existence is checked at resolve time, failing soft to empty).' );
    }

    public function test_validate_source_value_rejects_meta_match_with_malformed_post_type() {
        $result = $this->validator->validate_source_value( array(
            'type'      => 'meta_match',
            'meta_key'  => '_agent_id',
            'post_type' => 'My Listings',  // spaces + uppercase — not sanitize_key form
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_source_post_type', $result->get_error_code() );
    }

    public function test_validate_source_value_rejects_meta_match_with_empty_post_type() {
        $result = $this->validator->validate_source_value( array(
            'type'      => 'meta_match',
            'meta_key'  => '_agent_id',
            'post_type' => '',
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_source_post_type', $result->get_error_code() );
    }

    public function test_validate_source_value_accepts_each_match_against_value() {
        foreach ( \PCPTPages_Validator::META_MATCH_AGAINST as $value ) {
            $result = $this->validator->validate_source_value( array(
                'type'          => 'meta_match',
                'meta_key'      => '_agent_id',
                'match_against' => $value,
            ) );
            $this->assertTrue( $result, "match_against '{$value}' is in META_MATCH_AGAINST and must be accepted." );
        }
    }

    public function test_validate_source_value_rejects_unknown_match_against() {
        $result = $this->validator->validate_source_value( array(
            'type'          => 'meta_match',
            'meta_key'      => '_agent_id',
            'match_against' => 'current_author',  // not in the closed enum
        ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_invalid_source_match_against', $result->get_error_code() );
    }

    // -----------------------------------------------------------------
    // Per-post groupings — bounds and consistency
    //
    // These guard the persistence boundary. validate_post_groupings is
    // what every connector / admin save flows through, so these limits
    // are the difference between "AI agent makes a structural mistake"
    // and "AI agent's mistake corrupts the database."
    // -----------------------------------------------------------------

    public function test_validate_post_groupings_rejects_more_than_24_groupings() {
        // Build a CPT-groupings dictionary big enough to authorize 25
        // distinct grouping_keys, then submit per-post entries for all 25.
        $cpt_groupings = array();
        $post_entries  = array();
        for ( $i = 0; $i < \PCPTPages_Validator::MAX_GROUPINGS_PER_POST + 1; $i++ ) {
            $key                   = 'group_' . $i;
            $cpt_groupings[ $key ] = array(
                'key'              => $key,
                'label'            => 'Group ' . $i,
                'default_variant'  => 'card-grid',
                'default_position' => 'above_main',
            );
            $post_entries[] = array(
                'grouping_key' => $key,
                'position'     => 'above_main',
                'source'       => 'manual',
                'items'        => array(),
            );
        }

        $result = $this->validator->validate_post_groupings( $post_entries, 'listing', $cpt_groupings );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_too_many_groupings', $result->get_error_code() );
    }

    public function test_validate_post_groupings_rejects_unknown_grouping_key() {
        $cpt_groupings = array(
            'features' => array(
                'key'              => 'features',
                'label'            => 'Features',
                'default_variant'  => 'card-grid',
                'default_position' => 'above_main',
            ),
        );

        $result = $this->validator->validate_post_groupings(
            array(
                array(
                    'grouping_key' => 'not_defined_for_this_cpt',
                    'position'     => 'above_main',
                    'items'        => array(),
                ),
            ),
            'listing',
            $cpt_groupings
        );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_unknown_grouping_key', $result->get_error_code() );
    }

    public function test_validate_post_groupings_rejects_duplicate_grouping_key() {
        // Same grouping_key appearing twice on one post is ambiguous —
        // which entry wins at render time? Reject at write time so the
        // ambiguity never reaches the renderer.
        $cpt_groupings = array(
            'features' => array(
                'key'              => 'features',
                'label'            => 'Features',
                'default_variant'  => 'card-grid',
                'default_position' => 'above_main',
            ),
        );

        $result = $this->validator->validate_post_groupings(
            array(
                array(
                    'grouping_key' => 'features',
                    'position'     => 'above_main',
                    'items'        => array(),
                ),
                array(
                    'grouping_key' => 'features',
                    'position'     => 'below_main',
                    'items'        => array(),
                ),
            ),
            'listing',
            $cpt_groupings
        );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_duplicate_post_grouping', $result->get_error_code() );
    }
}
