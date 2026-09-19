<?php
/**
 * Unit tests for PCPTPages_Filter_Descriptors::term_ids_used_by().
 *
 * A taxonomy facet on a record type's archive offers only the terms that
 * published records OF THAT TYPE carry. `hide_empty` counted every post type,
 * so a meetings archive on the shared `category` taxonomy offered the blog's
 * and the workshops' categories — each a dead end (2026-09-19 pressure test).
 *
 * @package PostRuntimeEngine\Tests\Unit
 */

namespace PRE\Tests\Unit;

use Brain\Monkey\Functions;

class FacetTermScopeTest extends UnitTestCase {

    /** @var object the $wpdb stand-in; records the prepared SQL */
    private $db;

    protected function set_up() {
        parent::set_up();
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Frontend/class-pre-filter-descriptors.php';

        $this->db = new class {
            public $term_relationships = 'wp_term_relationships';
            public $term_taxonomy      = 'wp_term_taxonomy';
            public $posts              = 'wp_posts';
            public $prepared           = array();
            public $col                = array();
            public function prepare( $sql, ...$args ) {
                $this->prepared = array( 'sql' => $sql, 'args' => $args );
                return 'PREPARED';
            }
            public function get_col( $q ) {
                return $this->col;
            }
        };
        $GLOBALS['wpdb'] = $this->db;
    }

    protected function tear_down() {
        unset( $GLOBALS['wpdb'] );
        parent::tear_down();
    }

    public function test_the_query_is_scoped_to_published_records_of_the_type() {
        Functions\when( 'is_taxonomy_hierarchical' )->justReturn( false );
        $this->db->col = array( '12', '15', '12' );

        $ids = \PCPTPages_Filter_Descriptors::term_ids_used_by( 'post_tag', 'meeting' );

        $this->assertSame( array( 12, 15 ), $ids );
        $this->assertSame( array( 'post_tag', 'meeting' ), $this->db->prepared['args'] );
        $this->assertStringContainsString( 'p.post_type = %s', $this->db->prepared['sql'] );
        $this->assertStringContainsString( "p.post_status = 'publish'", $this->db->prepared['sql'] );
    }

    public function test_ancestors_are_added_so_nested_terms_keep_their_parent_row() {
        Functions\when( 'is_taxonomy_hierarchical' )->justReturn( true );
        Functions\when( 'get_ancestors' )->alias( function ( $id ) {
            return $id === 30 ? array( 20, 10 ) : array();
        } );
        $this->db->col = array( '30' );

        $ids = \PCPTPages_Filter_Descriptors::term_ids_used_by( 'category', 'department' );

        sort( $ids );
        $this->assertSame( array( 10, 20, 30 ), $ids );
    }

    public function test_a_type_with_no_tagged_records_gets_no_terms() {
        Functions\when( 'is_taxonomy_hierarchical' )->justReturn( true );
        $this->db->col = array();

        $this->assertSame( array(), \PCPTPages_Filter_Descriptors::term_ids_used_by( 'category', 'empty_type' ) );
    }
}
