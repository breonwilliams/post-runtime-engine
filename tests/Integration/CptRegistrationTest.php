<?php
/**
 * Integration tests for CPT registration round-trip.
 *
 * Verifies that PRE's option-backed CPT registry and WordPress's runtime
 * post-type registry stay in sync. The unit tests use mocks for
 * post_type_exists / register_post_type / unregister_post_type — these
 * tests exercise the real WP functions to catch drift between PRE's
 * stored definitions and what WP actually knows about.
 *
 * @package PostRuntimeEngine\Tests\Integration
 */

namespace PRE\Tests\Integration;

class CptRegistrationTest extends IntegrationTestCase {

    public function test_register_then_register_all_with_wp_makes_post_type_queryable() {
        // Step 1: persist via the registry (writes wp_options only).
        $this->register_test_cpt( 'pre_test_listing', array(
            'public'      => true,
            'has_archive' => true,
            'supports'    => array( 'title', 'editor', 'thumbnail' ),
        ) );

        // After register(), the option is set, but WP's runtime registry
        // does NOT know about the CPT yet — register_post_type runs on
        // the next init.
        $this->assertTrue(
            $this->plugin->cpts->exists( 'pre_test_listing' ),
            'Registry exists() should be true after register().'
        );

        // Step 2: simulate the init hook firing (or manually push, which
        // is what the init hook ultimately does).
        $this->plugin->cpts->register_all_with_wp();

        // Now WP knows about it.
        $this->assertTrue(
            post_type_exists( 'pre_test_listing' ),
            'post_type_exists() must be true after register_all_with_wp().'
        );

        $object = get_post_type_object( 'pre_test_listing' );
        $this->assertNotNull( $object );
        $this->assertSame(
            'Pre_test_listings',
            $object->labels->name,
            'Plural label must round-trip through register_post_type unchanged.'
        );
        $this->assertTrue(
            (bool) $object->public,
            'public flag must round-trip through register_post_type.'
        );
    }

    public function test_unregister_removes_from_both_pre_registry_and_wp_runtime() {
        $this->register_test_cpt( 'pre_test_listing' );
        $this->plugin->cpts->register_all_with_wp();
        $this->assertCptRegistered( 'pre_test_listing' );

        $result = $this->plugin->cpts->unregister( 'pre_test_listing' );
        $this->assertTrue( $result );

        // Both surfaces must now report it as gone.
        $this->assertFalse(
            $this->plugin->cpts->exists( 'pre_test_listing' ),
            'PRE registry exists() must be false after unregister().'
        );
        $this->assertFalse(
            post_type_exists( 'pre_test_listing' ),
            'WP runtime post_type_exists() must be false after unregister() — drift between PRE and WP is a bug.'
        );
    }

    public function test_register_persists_across_a_fresh_registry_instance() {
        // The actual production scenario: register on one request,
        // read on the next. We simulate this by clearing the in-process
        // cache and instantiating a fresh registry. The new instance
        // should see the same CPT via wp_options.
        $this->register_test_cpt( 'pre_test_listing', array(
            'label_singular' => 'Listing',
            'label_plural'   => 'Listings',
        ) );

        // New instance, no shared in-memory state with the original.
        $fresh_registry = new \PRE_CPT_Registry();

        $stored = $fresh_registry->get( 'pre_test_listing' );
        $this->assertNotNull(
            $stored,
            'A fresh registry instance must read the persisted CPT from wp_options.'
        );
        $this->assertSame( 'Listing', $stored['label_singular'] );
        $this->assertSame( 1, $stored['connector_version'] );
    }
}
