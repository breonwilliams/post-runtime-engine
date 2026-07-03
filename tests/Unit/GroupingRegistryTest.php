<?php
/**
 * Unit tests for PCPTPages_Grouping_Registry.
 *
 * Focus: per-CPT scoping. Groupings on different CPTs MUST NOT collide
 * — that's the whole point of using `OPTION_PREFIX . $cpt_slug` keys
 * instead of a single options bucket.
 *
 * @package PostRuntimeEngine\Tests\Unit
 */

namespace PRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for PCPTPages_Grouping_Registry.
 */
class GroupingRegistryTest extends UnitTestCase {

    /**
     * @var \PCPTPages_Grouping_Registry
     */
    private $registry;

    protected function set_up() {
        parent::set_up();
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-validator.php';
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-cpt-registry.php';
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-grouping-registry.php';

        Functions\when( 'current_time' )->alias( function ( $type ) {
            if ( $type === 'mysql' ) {
                return '2026-05-10 12:00:00';
            }
            return time();
        } );

        $this->registry = new \PCPTPages_Grouping_Registry();
    }

    private function valid_grouping( array $overrides = array() ) {
        return array_merge( array(
            'key'              => 'features',
            'label'            => 'Features',
            'default_variant'  => 'card-grid',
            'default_position' => 'above_main',
            'default_source'   => 'manual',
        ), $overrides );
    }

    public function test_get_all_returns_empty_for_unknown_cpt() {
        $this->assertSame( array(), $this->registry->get_all( 'no-such-cpt' ) );
    }

    public function test_define_persists_a_minimal_valid_grouping() {
        $result = $this->registry->define( 'listing', $this->valid_grouping() );
        $this->assertTrue( $result );

        $stored = $this->registry->get( 'listing', 'features' );
        $this->assertNotNull( $stored );
        $this->assertSame( 'features', $stored['key'] );
        $this->assertSame( 'card-grid', $stored['default_variant'] );
    }

    public function test_groupings_are_scoped_per_cpt() {
        // The architectural decision: each CPT has its own option bucket
        // (`pre_groupings_{cpt}`). A grouping with the same key on two
        // different CPTs must not collide.
        $this->registry->define( 'listing', $this->valid_grouping( array( 'label' => 'Listing Features' ) ) );
        $this->registry->define( 'attorney', $this->valid_grouping( array( 'label' => 'Attorney Features' ) ) );

        $listing_grouping  = $this->registry->get( 'listing', 'features' );
        $attorney_grouping = $this->registry->get( 'attorney', 'features' );

        $this->assertSame( 'Listing Features', $listing_grouping['label'] );
        $this->assertSame( 'Attorney Features', $attorney_grouping['label'] );
        $this->assertNotSame(
            $listing_grouping['label'],
            $attorney_grouping['label'],
            'Groupings keyed the same on different CPTs must be independent records.'
        );
    }

    public function test_define_rejects_invalid_grouping_definition() {
        $result = $this->registry->define( 'listing', $this->valid_grouping( array(
            'default_variant' => 'invalid-variant',
        ) ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertFalse( $this->registry->exists( 'listing', 'features' ) );
    }

    public function test_remove_deletes_a_defined_grouping() {
        $this->registry->define( 'listing', $this->valid_grouping() );
        $this->assertTrue( $this->registry->exists( 'listing', 'features' ) );

        $result = $this->registry->remove( 'listing', 'features' );
        $this->assertTrue( $result );
        $this->assertFalse( $this->registry->exists( 'listing', 'features' ) );
    }

    public function test_remove_all_for_cpt_clears_only_that_cpts_groupings() {
        $this->registry->define( 'listing', $this->valid_grouping( array( 'key' => 'features' ) ) );
        $this->registry->define( 'listing', $this->valid_grouping( array( 'key' => 'gallery', 'label' => 'Gallery' ) ) );
        $this->registry->define( 'attorney', $this->valid_grouping( array( 'key' => 'features', 'label' => 'A' ) ) );

        $this->assertSame( 2, count( $this->registry->get_all( 'listing' ) ) );
        $this->assertSame( 1, count( $this->registry->get_all( 'attorney' ) ) );

        $this->registry->remove_all_for_cpt( 'listing' );

        $this->assertSame( 0, count( $this->registry->get_all( 'listing' ) ), 'Listing groupings cleared.' );
        $this->assertSame( 1, count( $this->registry->get_all( 'attorney' ) ), 'Attorney groupings untouched.' );
    }

    // -----------------------------------------------------------------
    // Per-grouping CRUD edge cases
    //
    // The registry follows the FRE pattern: re-defining an existing
    // grouping bumps connector_version so external systems (Cowork
    // audit log, future webhook listeners) can detect drift. These
    // tests pin that contract.
    // -----------------------------------------------------------------

    public function test_define_overwrites_existing_grouping_and_bumps_connector_version() {
        $this->registry->define( 'listing', $this->valid_grouping( array(
            'label' => 'Original Label',
        ) ) );
        $first = $this->registry->get( 'listing', 'features' );
        $this->assertSame( 1, $first['connector_version'] );
        $this->assertSame( 'Original Label', $first['label'] );

        // Re-define the same grouping_key on the same CPT — must overwrite.
        $this->registry->define( 'listing', $this->valid_grouping( array(
            'label' => 'Updated Label',
        ) ) );
        $second = $this->registry->get( 'listing', 'features' );

        $this->assertSame(
            'Updated Label',
            $second['label'],
            'Re-defining a grouping must overwrite the existing record.'
        );
        $this->assertSame(
            2,
            $second['connector_version'],
            'connector_version must increment on every persistence bump (FRE pattern).'
        );
        $this->assertCount(
            1,
            $this->registry->get_all( 'listing' ),
            'Re-defining must NOT create a duplicate entry.'
        );
    }

    public function test_exists_returns_true_after_define() {
        $this->assertFalse(
            $this->registry->exists( 'listing', 'features' ),
            'exists() must be false before define().'
        );

        $this->registry->define( 'listing', $this->valid_grouping() );

        $this->assertTrue(
            $this->registry->exists( 'listing', 'features' ),
            'exists() must be true after a successful define().'
        );
    }

    public function test_get_returns_null_for_unknown_grouping_key_on_known_cpt() {
        // CPT has groupings, but not the one we ask for. Tests that the
        // per-CPT bucket lookup is independent of the per-grouping lookup.
        $this->registry->define( 'listing', $this->valid_grouping( array( 'key' => 'features' ) ) );

        $this->assertNull(
            $this->registry->get( 'listing', 'no_such_grouping' ),
            'get() must return null when CPT has other groupings but not the requested one.'
        );
    }

    public function test_remove_returns_error_for_unknown_grouping_key() {
        // Removing a grouping that doesn't exist should be a structured
        // failure, not a silent no-op — the connector / admin surfaces
        // need to distinguish "removed" from "wasn't there in the first place"
        // for accurate audit logging.
        $result = $this->registry->remove( 'listing', 'never_defined' );

        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_grouping_not_found', $result->get_error_code() );
    }
}
