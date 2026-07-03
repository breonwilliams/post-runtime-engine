<?php
/**
 * Unit tests for PCPTPages_Post_Data.
 *
 * Focus: read-modify-write semantics. The most important invariant is
 * that update_grouping() and remove_grouping() are non-destructive to
 * other groupings on the same post — that's what makes the connector's
 * `set_post_groupings` / `update_post` distinction safe.
 *
 * These tests use the in-memory option/post-meta store from UnitTestCase.
 *
 * @package PostRuntimeEngine\Tests\Unit
 */

namespace PRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for PCPTPages_Post_Data.
 */
class PostDataTest extends UnitTestCase {

    /**
     * @var \PCPTPages_CPT_Registry
     */
    private $cpts;

    /**
     * @var \PCPTPages_Grouping_Registry
     */
    private $groupings;

    /**
     * @var \PCPTPages_Post_Data
     */
    private $post_data;

    protected function set_up() {
        parent::set_up();
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-validator.php';
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-cpt-registry.php';
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-grouping-registry.php';
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-post-data.php';

        Functions\when( 'current_time' )->alias( function ( $type ) {
            if ( $type === 'mysql' ) {
                return '2026-05-10 12:00:00';
            }
            return time();
        } );

        // Mock get_post() — used in two distinct ways by code under test:
        //
        //   1. PCPTPages_Post_Data::set_groupings() calls get_post($post_id) to
        //      resolve the post's post_type and confirm the CPT is managed.
        //      Tests use ID 100 = a 'listing' post and ID 200 = an 'attorney'
        //      post (the latter is intentionally unmanaged so the
        //      pre_post_type_not_managed branch can be exercised).
        //
        //   2. PCPTPages_Validator::validate_grouping_item() calls get_post($image_id)
        //      to verify that referenced attachments still exist (a real
        //      production safety check against orphan media references).
        //      Tests use IDs 42 and 99 as fixture attachment IDs; the mock
        //      returns a minimal attachment shape so validation passes
        //      without standing up wp_posts.
        //
        // Per-test overrides are still possible — Brain\Monkey lets a later
        // Functions\when() call shadow this one for specific failure-mode
        // tests (e.g. simulating a deleted attachment).
        Functions\when( 'get_post' )->alias( function ( $post_id ) {
            $post_id = (int) $post_id;

            // Posts under test (managed CPT vs unmanaged CPT).
            if ( $post_id === 100 ) {
                return (object) array(
                    'ID'          => 100,
                    'post_type'   => 'listing',
                    'post_status' => 'publish',
                );
            }
            if ( $post_id === 200 ) {
                return (object) array(
                    'ID'          => 200,
                    'post_type'   => 'attorney',
                    'post_status' => 'publish',
                );
            }

            // Fixture attachments referenced by valid_groupings() items and
            // by the append-test fixture. Add new IDs here when fixtures
            // grow rather than per-test overriding the whole mock.
            if ( in_array( $post_id, array( 42, 99 ), true ) ) {
                return (object) array(
                    'ID'          => $post_id,
                    'post_type'   => 'attachment',
                    'post_status' => 'inherit',
                );
            }

            return null;
        } );

        // Mock current user (for backup audit trail).
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        $this->cpts      = new \PCPTPages_CPT_Registry();
        $this->groupings = new \PCPTPages_Grouping_Registry();
        $this->post_data = new \PCPTPages_Post_Data( $this->cpts, $this->groupings );

        // Set up a 'listing' CPT and one grouping definition so set_groupings has something to validate against.
        $this->cpts->register( 'listing', array(
            'slug'           => 'listing',
            'label_singular' => 'Listing',
            'label_plural'   => 'Listings',
        ) );
        $this->groupings->define( 'listing', array(
            'key'              => 'features',
            'label'            => 'Features',
            'default_variant'  => 'card-grid',
            'default_position' => 'above_main',
            'default_source'   => 'manual',
        ) );
        $this->groupings->define( 'listing', array(
            'key'              => 'gallery',
            'label'            => 'Gallery',
            'default_variant'  => 'card-grid',
            'default_position' => 'below_main',
            'default_source'   => 'manual',
        ) );
    }

    private function valid_groupings() {
        // Two groupings on a single post — the read-modify-write tests
        // verify that touching one leaves the other intact.
        return array(
            array(
                'grouping_key' => 'features',
                'variant'      => 'card-grid',
                'position'     => 'above_main',
                'source'       => 'manual',
                'items'        => array(
                    array( 'heading' => 'Pool', 'icon_id' => 'home' ),
                    array( 'heading' => 'Garage', 'icon_id' => 'home' ),
                ),
            ),
            array(
                'grouping_key' => 'gallery',
                'variant'      => 'card-grid',
                'position'     => 'below_main',
                'source'       => 'manual',
                'items'        => array(
                    array( 'heading' => 'Front view', 'image_id' => 42 ),
                ),
            ),
        );
    }

    public function test_get_groupings_returns_empty_array_for_post_with_no_meta() {
        $this->assertSame( array(), $this->post_data->get_groupings( 100 ) );
    }

    public function test_get_groupings_returns_empty_for_invalid_post_id() {
        $this->assertSame( array(), $this->post_data->get_groupings( 0 ) );
        $this->assertSame( array(), $this->post_data->get_groupings( -1 ) );
    }

    public function test_set_groupings_persists_valid_array() {
        $result = $this->post_data->set_groupings( 100, $this->valid_groupings() );
        $this->assertTrue( $result );

        $stored = $this->post_data->get_groupings( 100 );
        $this->assertCount( 2, $stored );
        $this->assertSame( 'features', $stored[0]['grouping_key'] );
        $this->assertSame( 'gallery', $stored[1]['grouping_key'] );
    }

    public function test_set_groupings_rejects_unknown_post_id() {
        $result = $this->post_data->set_groupings( 999, $this->valid_groupings() );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_post_not_found', $result->get_error_code() );
    }

    public function test_set_groupings_rejects_post_in_unmanaged_cpt() {
        // Post ID 200 is an 'attorney' post but we only registered 'listing'.
        $result = $this->post_data->set_groupings( 200, $this->valid_groupings() );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pcptpages_post_type_not_managed', $result->get_error_code() );
    }

    public function test_update_grouping_replaces_only_the_targeted_grouping() {
        // Seed both groupings.
        $this->post_data->set_groupings( 100, $this->valid_groupings() );

        // Update the 'features' grouping with new items.
        $result = $this->post_data->update_grouping( 100, 'features', array(
            'variant'  => 'compact-grid',
            'position' => 'above_main',
            'source'   => 'manual',
            'items'    => array(
                array( 'heading' => 'Updated Feature', 'icon_id' => 'home' ),
            ),
        ) );
        $this->assertTrue( $result );

        $stored = $this->post_data->get_groupings( 100 );
        $this->assertCount( 2, $stored, 'update_grouping must NOT delete other groupings.' );

        // Find each by key.
        $by_key = array();
        foreach ( $stored as $entry ) {
            $by_key[ $entry['grouping_key'] ] = $entry;
        }

        $this->assertSame( 'compact-grid', $by_key['features']['variant'], 'Targeted grouping was updated.' );
        $this->assertCount( 1, $by_key['features']['items'], 'Targeted grouping items were replaced, not merged.' );
        $this->assertSame( 'card-grid', $by_key['gallery']['variant'], 'Untargeted grouping is unchanged.' );
        $this->assertCount( 1, $by_key['gallery']['items'], 'Untargeted grouping items are unchanged.' );
    }

    public function test_update_grouping_appends_when_grouping_key_doesnt_exist() {
        // Seed with just 'features'.
        $this->post_data->set_groupings( 100, array( $this->valid_groupings()[0] ) );
        $this->assertCount( 1, $this->post_data->get_groupings( 100 ) );

        // Update a grouping that doesn't exist on this post → appended.
        $this->post_data->update_grouping( 100, 'gallery', array(
            'variant'  => 'card-grid',
            'position' => 'below_main',
            'source'   => 'manual',
            'items'    => array( array( 'heading' => 'Photo', 'image_id' => 99 ) ),
        ) );

        $this->assertCount( 2, $this->post_data->get_groupings( 100 ), 'Non-existent grouping_key appends a new entry.' );
    }
}
