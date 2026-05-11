<?php
/**
 * Unit tests for PRE_CPT_Registry.
 *
 * Focus: round-trip the registry through register → get → exists →
 * unregister, and verify validation rejection bubbles up correctly.
 *
 * @package PostRuntimeEngine\Tests\Unit
 */

namespace PRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for PRE_CPT_Registry.
 */
class CPTRegistryTest extends UnitTestCase {

    /**
     * @var \PRE_CPT_Registry
     */
    private $registry;

    protected function set_up() {
        parent::set_up();
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-validator.php';
        require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-cpt-registry.php';

        // Mock current_time() — registry stamps created_at / updated_at.
        Functions\when( 'current_time' )->alias( function ( $type ) {
            if ( $type === 'mysql' ) {
                return '2026-05-10 12:00:00';
            }
            return time();
        } );

        // HOUR_IN_SECONDS may not be defined in the test environment.
        if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
            define( 'HOUR_IN_SECONDS', 3600 );
        }

        $this->registry = new \PRE_CPT_Registry();
    }

    /**
     * Helper: a minimal valid CPT definition.
     */
    private function valid_definition( array $overrides = array() ) {
        return array_merge( array(
            'slug'           => 'listing',
            'label_singular' => 'Listing',
            'label_plural'   => 'Listings',
            'public'         => true,
        ), $overrides );
    }

    public function test_get_all_returns_empty_array_when_no_cpts_registered() {
        $this->assertSame( array(), $this->registry->get_all() );
    }

    public function test_get_returns_null_for_unknown_slug() {
        $this->assertNull( $this->registry->get( 'does-not-exist' ) );
    }

    public function test_exists_returns_false_for_unknown_slug() {
        $this->assertFalse( $this->registry->exists( 'does-not-exist' ) );
    }

    public function test_register_persists_a_minimal_valid_cpt() {
        $result = $this->registry->register( 'listing', $this->valid_definition() );
        $this->assertTrue( $result, 'Registering a valid CPT should return true.' );

        $this->assertTrue( $this->registry->exists( 'listing' ) );
        $stored = $this->registry->get( 'listing' );
        $this->assertSame( 'listing', $stored['slug'] );
        $this->assertSame( 'Listing', $stored['label_singular'] );
    }

    public function test_register_stamps_created_at_and_updated_at() {
        $this->registry->register( 'listing', $this->valid_definition() );
        $stored = $this->registry->get( 'listing' );

        $this->assertArrayHasKey( 'created_at', $stored );
        $this->assertArrayHasKey( 'updated_at', $stored );
        $this->assertSame( '2026-05-10 12:00:00', $stored['created_at'] );
    }

    public function test_register_increments_connector_version_on_re_register() {
        $this->registry->register( 'listing', $this->valid_definition() );
        $first = $this->registry->get( 'listing' );

        // Re-register with same slug → updates the existing record.
        $this->registry->register( 'listing', $this->valid_definition( array( 'label_singular' => 'Property' ) ) );
        $second = $this->registry->get( 'listing' );

        $this->assertSame( 1, $first['connector_version'] );
        $this->assertSame(
            2,
            $second['connector_version'],
            'connector_version increments on every persistence bump (FRE pattern).'
        );
        $this->assertSame( 'Property', $second['label_singular'], 'Updates must be reflected in subsequent get().' );
    }

    /**
     * Regression for the 2026-05-10 connector pressure-test finding where
     * register_cpt appeared to drop the `description` field. The actual
     * cause turned out to be the MCP wrapper's tool schema (separate
     * Node.js package) not exposing `description` as an input property
     * — so the value never reached the WordPress endpoint. This test
     * pins that the WP-side plumbing IS correct: register() with a
     * description persists it, get() returns it. If the MCP schema is
     * later updated to forward description and this test still passes,
     * the fix flows through end-to-end.
     */
    public function test_register_persists_description_field() {
        $this->registry->register( 'listing', $this->valid_definition( array(
            'description' => 'Real estate listings shown on the Properties page.',
        ) ) );

        $stored = $this->registry->get( 'listing' );

        $this->assertSame(
            'Real estate listings shown on the Properties page.',
            $stored['description'],
            'description must round-trip through register() → get(). If this fails, the registry or merge_defaults() is dropping the field.'
        );
    }

    public function test_register_defaults_description_to_empty_when_omitted() {
        $this->registry->register( 'listing', $this->valid_definition() );
        $stored = $this->registry->get( 'listing' );

        $this->assertSame(
            '',
            $stored['description'],
            'Omitted description must default to empty string (WP register_post_type contract).'
        );
    }

    public function test_register_rejects_reserved_slug() {
        $result = $this->registry->register( 'page', $this->valid_definition( array( 'slug' => 'page' ) ) );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pre_reserved_slug', $result->get_error_code() );
        $this->assertFalse( $this->registry->exists( 'page' ), 'Rejected registration must not leak into storage.' );
    }

    public function test_register_rejects_empty_slug() {
        $result = $this->registry->register( '', $this->valid_definition() );
        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'pre_invalid_slug', $result->get_error_code() );
    }

    public function test_unregister_removes_existing_cpt() {
        $this->registry->register( 'listing', $this->valid_definition() );
        $this->assertTrue( $this->registry->exists( 'listing' ) );

        $result = $this->registry->unregister( 'listing' );
        $this->assertTrue( $result );
        $this->assertFalse( $this->registry->exists( 'listing' ) );
        $this->assertNull( $this->registry->get( 'listing' ) );
    }

    public function test_register_multiple_cpts_returns_all_via_get_all() {
        $this->registry->register( 'listing', $this->valid_definition() );
        $this->registry->register( 'attorney', $this->valid_definition( array(
            'slug' => 'attorney', 'label_singular' => 'Attorney', 'label_plural' => 'Attorneys',
        ) ) );

        $all = $this->registry->get_all();
        $this->assertCount( 2, $all );
        $this->assertArrayHasKey( 'listing', $all );
        $this->assertArrayHasKey( 'attorney', $all );
    }
}
