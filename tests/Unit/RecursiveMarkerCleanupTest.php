<?php
/**
 * Unit tests for the 0.7.0 recursive grouping-marker cleanup discriminator.
 *
 * Focus: the cleanup deletes option rows, and the option NAME cannot tell junk
 * from real data. `pcptpages_groupings_changed_events` is both the shape the
 * recursive defect produced AND the legitimate groupings option for a CPT
 * whose slug is `changed_events`. Only the stored VALUE separates them, so
 * these tests pin that predicate — a false positive here silently destroys a
 * CPT's grouping definitions, which exist in no other copy.
 *
 * @package PostRuntimeEngine\Tests\Unit
 */

namespace PRE\Tests\Unit;

/**
 * Tests for PCPTPages_Renderer::is_legacy_recursive_marker_value().
 */
class RecursiveMarkerCleanupTest extends UnitTestCase {

    protected function set_up() {
        parent::set_up();
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Frontend/class-pre-renderer.php';
    }

    /**
     * The junk rows held a bare time() integer. Those are the only rows the
     * migration may delete.
     */
    public function test_bare_timestamp_is_a_marker() {
        $this->assertTrue( \PCPTPages_Renderer::is_legacy_recursive_marker_value( 1756944000 ) );
    }

    /**
     * WordPress hands option values back as strings after a DB round trip, so
     * the string form has to be recognised too — otherwise the migration
     * inspects real installs and deletes nothing.
     */
    public function test_timestamp_as_string_is_a_marker() {
        $this->assertTrue( \PCPTPages_Renderer::is_legacy_recursive_marker_value( '1756944000' ) );
    }

    /**
     * The case the whole predicate exists for: a CPT slug beginning with
     * `changed` produces a legitimate option matching the junk name pattern.
     * Its value is an array of grouping definitions and must survive.
     */
    public function test_grouping_definitions_are_never_a_marker() {
        $definitions = array(
            array(
                'key'             => 'session_type',
                'label'           => 'Session Type',
                'default_variant' => 'gallery',
                'source'          => array( 'mode' => 'taxonomy', 'taxonomy' => 'session_cat' ),
            ),
        );

        $this->assertFalse( \PCPTPages_Renderer::is_legacy_recursive_marker_value( $definitions ) );
    }

    /**
     * An empty array is still a groupings option — a CPT whose groupings were
     * all removed. Deleting it would be deleting real state.
     */
    public function test_empty_array_is_never_a_marker() {
        $this->assertFalse( \PCPTPages_Renderer::is_legacy_recursive_marker_value( array() ) );
    }

    /**
     * get_option() returns false for a row that does not exist. That is not a
     * marker, and `(string) false` is '' which ctype_digit() already rejects —
     * but false must not slip through on any future edit to the predicate.
     */
    public function test_false_is_never_a_marker() {
        $this->assertFalse( \PCPTPages_Renderer::is_legacy_recursive_marker_value( false ) );
    }

    /**
     * `(string) true` is '1', which IS a run of digits. Without the explicit
     * bool guard this would read as a marker and the row would be deleted.
     */
    public function test_true_is_never_a_marker() {
        $this->assertFalse( \PCPTPages_Renderer::is_legacy_recursive_marker_value( true ) );
    }

    /**
     * Anything unrecognised is kept. The failure direction is deliberate.
     *
     * @dataProvider provide_non_marker_values
     *
     * @param mixed $value Value that must not be treated as a marker.
     */
    public function test_unrecognised_values_are_kept( $value ) {
        $this->assertFalse( \PCPTPages_Renderer::is_legacy_recursive_marker_value( $value ) );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public function provide_non_marker_values() {
        return array(
            'null'              => array( null ),
            'empty string'      => array( '' ),
            'arbitrary string'  => array( 'gallery' ),
            'digits with space' => array( ' 1756944000' ),
            'negative number'   => array( -1 ),
            'float'             => array( 1756944000.5 ),
            'object'            => array( new \stdClass() ),
        );
    }
}
