<?php
/**
 * Integration tests for per-post grouping persistence.
 *
 * Exercises PCPTPages_Post_Data against real wp_postmeta storage. The unit
 * tests cover read-modify-write semantics with mocked meta — these
 * tests confirm the same logic works against the actual WP meta API,
 * including the backup chain (`_pre_groupings_backup` and friends).
 *
 * @package PostRuntimeEngine\Tests\Integration
 */

namespace PRE\Tests\Integration;

class PostGroupingsTest extends IntegrationTestCase {

    /**
     * Post ID created in set_up for each test.
     *
     * @var int
     */
    protected $post_id;

    public function set_up() {
        parent::set_up();

        // Register a CPT and a grouping definition so the test post has
        // somewhere to live and the validator has something to validate
        // against.
        $this->register_test_cpt( 'pre_test_listing' );
        $this->plugin->cpts->register_all_with_wp();

        $this->define_test_grouping( 'pre_test_listing', array(
            'key'              => 'features',
            'label'            => 'Features',
            'default_variant'  => 'card-grid',
            'default_position' => 'above_main',
            'default_source'   => 'manual',
        ) );

        // Create a test post in the registered CPT.
        $this->post_id = $this->factory->post->create( array(
            'post_type'   => 'pre_test_listing',
            'post_title'  => 'Test Listing',
            'post_status' => 'publish',
        ) );
    }

    public function test_set_groupings_persists_to_post_meta() {
        $groupings = array(
            array(
                'grouping_key' => 'features',
                'position'     => 'above_main',
                'source'       => 'manual',
                'items'        => array(
                    array( 'heading' => 'Pool', 'icon_id' => 'home' ),
                    array( 'heading' => 'Garage', 'icon_id' => 'home' ),
                ),
            ),
        );

        $result = $this->plugin->post_data->set_groupings( $this->post_id, $groupings, 'integration_test' );
        $this->assertTrue( $result );

        // Read back through the public API.
        $stored = $this->plugin->post_data->get_groupings( $this->post_id );
        $this->assertCount( 1, $stored );
        $this->assertSame( 'features', $stored[0]['grouping_key'] );
        $this->assertCount( 2, $stored[0]['items'] );

        // Read directly from post_meta — confirms storage shape, not just
        // accessor behavior. If the renderer ever queries get_post_meta
        // directly, this is the contract it'll see.
        $raw = get_post_meta( $this->post_id, '_pre_groupings', true );
        $this->assertIsArray( $raw );
        $this->assertCount( 1, $raw );
        $this->assertSame( 'features', $raw[0]['grouping_key'] );
    }

    public function test_set_groupings_creates_backup_chain_on_overwrite() {
        // First write — no prior state, backup is whatever was there
        // (empty array).
        $this->plugin->post_data->set_groupings(
            $this->post_id,
            array(
                array(
                    'grouping_key' => 'features',
                    'position'     => 'above_main',
                    'source'       => 'manual',
                    'items'        => array( array( 'heading' => 'Original', 'icon_id' => 'home' ) ),
                ),
            ),
            'integration_test'
        );

        // Second write — current state ('Original') should now appear
        // in the backup, and the live meta should hold 'Replaced'.
        $this->plugin->post_data->set_groupings(
            $this->post_id,
            array(
                array(
                    'grouping_key' => 'features',
                    'position'     => 'above_main',
                    'source'       => 'manual',
                    'items'        => array( array( 'heading' => 'Replaced', 'icon_id' => 'home' ) ),
                ),
            ),
            'integration_test'
        );

        $live   = get_post_meta( $this->post_id, '_pre_groupings', true );
        $backup = get_post_meta( $this->post_id, '_pre_groupings_backup', true );

        $this->assertSame( 'Replaced', $live[0]['items'][0]['heading'], 'Live meta should hold the most recent write.' );
        $this->assertSame( 'Original', $backup[0]['items'][0]['heading'], 'Backup should hold the previous state.' );

        // The audit-trail metadata.
        $this->assertNotEmpty(
            get_post_meta( $this->post_id, '_pre_groupings_backup_time', true ),
            'Backup time meta must be set on overwrite.'
        );
        $this->assertSame(
            'integration_test',
            get_post_meta( $this->post_id, '_pre_groupings_backup_source', true ),
            'Backup source meta should record the write source identifier.'
        );
    }

    public function test_restore_backup_swaps_live_state_back() {
        $original_items = array( array( 'heading' => 'Original', 'icon_id' => 'home' ) );
        $replaced_items = array( array( 'heading' => 'Replaced', 'icon_id' => 'home' ) );

        $this->plugin->post_data->set_groupings(
            $this->post_id,
            array( array(
                'grouping_key' => 'features',
                'position'     => 'above_main',
                'source'       => 'manual',
                'items'        => $original_items,
            ) ),
            'integration_test'
        );

        $this->plugin->post_data->set_groupings(
            $this->post_id,
            array( array(
                'grouping_key' => 'features',
                'position'     => 'above_main',
                'source'       => 'manual',
                'items'        => $replaced_items,
            ) ),
            'integration_test'
        );

        // Restore the backup ("Original" content).
        $result = $this->plugin->post_data->restore_backup( $this->post_id );
        $this->assertTrue( $result );

        $current = $this->plugin->post_data->get_groupings( $this->post_id );
        $this->assertSame(
            'Original',
            $current[0]['items'][0]['heading'],
            'After restore_backup(), live state must match the prior backup.'
        );
    }
}
