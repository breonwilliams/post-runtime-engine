<?php
/**
 * Unit tests for PCPTPages_Post_Data::remove_post() — trash vs permanent.
 *
 * wp_delete_post( $id, false ) deletes a custom post type PERMANENTLY: core
 * diverts only 'post' and 'page' to the trash. The connector's delete_post
 * relied on it for records documented as "trashed by default", so every
 * non-forced delete through the connector destroyed the record while
 * answering permanent:false (2026-09-19 pressure test). These tests pin the
 * contract: a normal delete trashes, only force deletes, a second trash is
 * not escalated, and `permanent` reports what the database says.
 *
 * @package PostRuntimeEngine\Tests\Unit
 */

namespace PRE\Tests\Unit;

use Brain\Monkey\Functions;

class RemovePostTest extends UnitTestCase {

    /** @var \PCPTPages_Post_Data */
    private $post_data;

    /** @var array<int, object|null> id → post row the get_post() mock returns */
    private $posts = array();

    protected function set_up() {
        parent::set_up();
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-validator.php';
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-cpt-registry.php';
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-grouping-registry.php';
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-post-data.php';

        $this->posts = array(
            7 => (object) array( 'ID' => 7, 'post_type' => 'meeting', 'post_status' => 'publish' ),
            8 => (object) array( 'ID' => 8, 'post_type' => 'meeting', 'post_status' => 'trash' ),
        );
        Functions\when( 'get_post' )->alias( function ( $id ) {
            return $this->posts[ (int) $id ] ?? null;
        } );

        $this->post_data = new \PCPTPages_Post_Data( new \PCPTPages_CPT_Registry(), new \PCPTPages_Grouping_Registry() );
    }

    public function test_a_normal_delete_trashes_and_never_calls_wp_delete_post() {
        Functions\expect( 'wp_delete_post' )->never();
        Functions\expect( 'wp_trash_post' )->once()->with( 7 )->andReturnUsing( function ( $id ) {
            $this->posts[ $id ]->post_status = 'trash';
            return $this->posts[ $id ];
        } );

        $result = $this->post_data->remove_post( 7, false );

        $this->assertTrue( $result['deleted'] );
        $this->assertFalse( $result['permanent'], 'the record is still in the database, in the trash' );
        $this->assertFalse( $result['already_trashed'] );
    }

    public function test_force_deletes_permanently_and_says_so() {
        Functions\expect( 'wp_trash_post' )->never();
        Functions\expect( 'wp_delete_post' )->once()->with( 7, true )->andReturnUsing( function ( $id ) {
            $row = $this->posts[ $id ];
            unset( $this->posts[ $id ] );
            return $row;
        } );

        $result = $this->post_data->remove_post( 7, true );

        $this->assertTrue( $result['deleted'] );
        $this->assertTrue( $result['permanent'] );
    }

    public function test_trashing_a_trashed_record_does_not_escalate_to_a_permanent_delete() {
        Functions\expect( 'wp_trash_post' )->never();
        Functions\expect( 'wp_delete_post' )->never();

        $result = $this->post_data->remove_post( 8, false );

        $this->assertTrue( $result['deleted'] );
        $this->assertFalse( $result['permanent'] );
        $this->assertTrue( $result['already_trashed'] );
    }

    public function test_permanent_is_read_back_when_the_site_does_not_keep_a_trash() {
        // EMPTY_TRASH_DAYS = 0: core's wp_trash_post() deletes permanently.
        Functions\expect( 'wp_trash_post' )->once()->andReturnUsing( function ( $id ) {
            $row = $this->posts[ $id ];
            unset( $this->posts[ $id ] );
            return $row;
        } );

        $result = $this->post_data->remove_post( 7, false );

        $this->assertTrue( $result['deleted'] );
        $this->assertTrue( $result['permanent'], 'the caller must be told the record is gone for good' );
    }

    public function test_a_missing_record_is_not_reported_deleted() {
        Functions\expect( 'wp_trash_post' )->never();
        Functions\expect( 'wp_delete_post' )->never();

        $this->assertFalse( $this->post_data->remove_post( 999, false )['deleted'] );
    }

    public function test_the_connector_never_calls_wp_delete_post_without_force() {
        // Source guard: the handler must go through remove_post(). A future
        // edit that reintroduces wp_delete_post( $id, $force ) brings the
        // data loss back, and no mock-based test above would notice.
        $src = file_get_contents( PRE_TEST_PLUGIN_DIR . 'includes/Connector/class-pre-connector-api.php' );
        // Code only: comments may (and do) name the trap.
        $src = preg_replace( '#/\*.*?\*/#s', '', $src );
        $src = preg_replace( '#^\s*//.*$#m', '', $src );
        preg_match_all( '/wp_delete_post\(\s*([^,)]+)\s*(?:,\s*([^)]+))?\)/', $src, $calls, PREG_SET_ORDER );
        foreach ( $calls as $call ) {
            $this->assertSame( 'true', trim( $call[2] ?? '' ), 'wp_delete_post() without an explicit true: ' . $call[0] );
        }
        $this->assertStringContainsString( 'post_data->remove_post(', $src );
    }
}
