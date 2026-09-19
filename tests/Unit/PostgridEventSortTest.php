<?php
/**
 * Unit tests for PCPTPages_Card_Filter_Hooks::filter_postgrid_query_args().
 *
 * PostGrid's event_sort used to be read only when event_status filtered the
 * grid to upcoming/happening/past. The all-dates archive — status none, so
 * visitors choose upcoming or past from the filter bar — ignored
 * event_sort:"soonest" and listed meetings in publish order (2026-09-19
 * pressure test). An explicit sort now orders it, without dropping records
 * that have no start date; 'auto' keeps the section's own ordering.
 *
 * @package PostRuntimeEngine\Tests\Unit
 */

namespace PRE\Tests\Unit;

use Brain\Monkey\Functions;

class PostgridEventSortTest extends UnitTestCase {

    /** @var \PCPTPages_Card_Filter_Hooks */
    private $hooks;

    protected function set_up() {
        parent::set_up();
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-validator.php';
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-cpt-registry.php';
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-grouping-registry.php';
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-post-data.php';
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-event-query.php';
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Frontend/class-pre-card-filter-hooks.php';

        // One event-shaped type (meeting: `starts` carries event_start) and one plain type.
        $fields = new class {
            public function get_all( $cpt ) {
                return $cpt === 'meeting'
                    ? array( 'starts' => array( 'semantic_role' => 'event_start' ), 'room' => array() )
                    : array( 'price' => array() );
            }
        };
        $plugin = new \stdClass();
        $plugin->post_fields = $fields;
        Functions\when( 'pcptpages' )->justReturn( $plugin );
        Functions\when( 'wp_date' )->justReturn( '20260919120000' );

        $this->hooks = new \PCPTPages_Card_Filter_Hooks();
    }

    private function args( $type = 'meeting' ) {
        return array( 'post_type' => $type, 'posts_per_page' => 12, 'orderby' => 'date', 'order' => 'DESC' );
    }

    public function test_explicit_soonest_orders_an_unfiltered_archive_by_event_date() {
        $out = $this->hooks->filter_postgrid_query_args( $this->args(), array( 'event_status' => 'none', 'event_sort' => 'soonest' ) );

        $this->assertSame( array( 'pcptpages_event_sort' => 'ASC' ), $out['orderby'] );
        $this->assertArrayNotHasKey( 'meta_key', $out, 'meta_key would INNER JOIN and drop records with no start date' );
        $this->assertArrayNotHasKey( 'order', $out );
        $this->assertSame( 'OR', $out['meta_query']['relation'] );
        $this->assertSame( 'EXISTS', $out['meta_query']['pcptpages_event_sort']['compare'] );
        $this->assertSame( 'NOT EXISTS', $out['meta_query'][0]['compare'], 'records without the companion stay in the grid' );
        $this->assertSame( '_pcptpages_field_starts__sort', $out['meta_query']['pcptpages_event_sort']['key'] );
    }

    public function test_explicit_latest_orders_descending() {
        $out = $this->hooks->filter_postgrid_query_args( $this->args(), array( 'event_sort' => 'latest' ) );
        $this->assertSame( array( 'pcptpages_event_sort' => 'DESC' ), $out['orderby'] );
    }

    public function test_auto_without_a_status_leaves_the_section_ordering_alone() {
        $this->assertSame( $this->args(), $this->hooks->filter_postgrid_query_args( $this->args(), array( 'event_sort' => 'auto' ) ) );
        $this->assertSame( $this->args(), $this->hooks->filter_postgrid_query_args( $this->args(), array() ) );
    }

    public function test_a_type_that_is_not_event_shaped_is_untouched() {
        $this->assertSame( $this->args( 'listing' ), $this->hooks->filter_postgrid_query_args( $this->args( 'listing' ), array( 'event_sort' => 'soonest' ) ) );
    }

    public function test_an_existing_meta_query_is_kept() {
        $in  = $this->args() + array( 'meta_query' => array( array( 'key' => 'x', 'value' => 1 ) ) );
        $out = $this->hooks->filter_postgrid_query_args( $in, array( 'event_sort' => 'soonest' ) );
        $this->assertSame( 'AND', $out['meta_query']['relation'] );
        $this->assertSame( array( array( 'key' => 'x', 'value' => 1 ) ), $out['meta_query'][0] );
    }

    public function test_a_filtered_status_still_gets_its_status_query_and_date_order() {
        $out = $this->hooks->filter_postgrid_query_args( $this->args(), array( 'event_status' => 'upcoming', 'event_sort' => 'soonest' ) );
        $this->assertSame( '_pcptpages_field_starts__sort', $out['meta_key'] );
        $this->assertSame( 'ASC', $out['order'] );
        $this->assertNotEmpty( $out['meta_query'] );
    }
}
