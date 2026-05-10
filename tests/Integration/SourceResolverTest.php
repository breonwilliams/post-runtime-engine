<?php
/**
 * Integration tests for PRE_Source_Resolver.
 *
 * The resolver bridges grouping definitions (where do items come from?)
 * and the renderer (here are the items to display). It's pure WP-API
 * orchestration — get_posts, wp_get_post_terms, get_permalink — so
 * integration tests against a real WP test instance are the right
 * level of granularity.
 *
 * Three sources to cover:
 *   - manual          → returns stored items unchanged
 *   - child_posts     → returns hierarchical children mapped to item shape
 *   - taxonomy_match  → returns posts sharing the parent's terms
 *
 * @package PostRuntimeEngine\Tests\Integration
 */

namespace PRE\Tests\Integration;

class SourceResolverTest extends IntegrationTestCase {

    /**
     * Resolver instance.
     *
     * @var \PRE_Source_Resolver
     */
    protected $resolver;

    /**
     * Parent post ID. Created in set_up.
     *
     * @var int
     */
    protected $parent_id;

    public function set_up() {
        parent::set_up();

        // Register a hierarchical CPT so child_posts source can be exercised.
        $this->register_test_cpt( 'pre_test_listing', array(
            'hierarchical' => true,
            'public'       => true,
        ) );
        $this->plugin->cpts->register_all_with_wp();

        $this->parent_id = $this->factory->post->create( array(
            'post_type'   => 'pre_test_listing',
            'post_title'  => 'Parent Listing',
            'post_status' => 'publish',
        ) );

        $this->resolver = new \PRE_Source_Resolver();
    }

    /**
     * Convenience: minimal grouping definition for source-resolver tests.
     */
    private function minimal_def( array $overrides = array() ) {
        return array_merge( array(
            'key'              => 'features',
            'label'            => 'Features',
            'default_variant'  => 'card-grid',
            'default_position' => 'above_main',
            'default_source'   => 'manual',
        ), $overrides );
    }

    // -----------------------------------------------------------------
    // manual source
    // -----------------------------------------------------------------

    public function test_manual_source_returns_stored_items_unchanged() {
        $entry = array(
            'grouping_key' => 'features',
            'source'       => 'manual',
            'items'        => array(
                array( 'heading' => 'Pool', 'icon_id' => 'home' ),
                array( 'heading' => 'Garage', 'icon_id' => 'home' ),
            ),
        );

        $items = $this->resolver->resolve( $entry, $this->minimal_def(), get_post( $this->parent_id ) );

        $this->assertCount( 2, $items );
        $this->assertSame( 'Pool', $items[0]['heading'] );
        $this->assertSame( 'Garage', $items[1]['heading'] );
    }

    public function test_manual_source_with_no_items_returns_empty_array() {
        $entry = array(
            'grouping_key' => 'features',
            'source'       => 'manual',
            'items'        => array(),
        );

        $items = $this->resolver->resolve( $entry, $this->minimal_def(), get_post( $this->parent_id ) );

        $this->assertSame( array(), $items );
    }

    // -----------------------------------------------------------------
    // child_posts source
    // -----------------------------------------------------------------

    public function test_child_posts_source_returns_hierarchical_children_mapped_to_item_shape() {
        // Create three children. The post_to_item mapper should turn
        // each into the standard grouping-item shape.
        $child_one = $this->factory->post->create( array(
            'post_type'    => 'pre_test_listing',
            'post_parent'  => $this->parent_id,
            'post_title'   => 'Child One',
            'post_excerpt' => 'A description of child one.',
            'post_status'  => 'publish',
            'menu_order'   => 1,
        ) );
        $child_two = $this->factory->post->create( array(
            'post_type'    => 'pre_test_listing',
            'post_parent'  => $this->parent_id,
            'post_title'   => 'Child Two',
            'post_excerpt' => 'A description of child two.',
            'post_status'  => 'publish',
            'menu_order'   => 2,
        ) );

        $entry = array( 'grouping_key' => 'features', 'source' => 'child_posts' );

        $items = $this->resolver->resolve( $entry, $this->minimal_def(), get_post( $this->parent_id ) );

        $this->assertCount( 2, $items );
        $this->assertSame( 'Child One', $items[0]['heading'], 'menu_order ASC must be honored.' );
        $this->assertSame( 'Child Two', $items[1]['heading'] );
        $this->assertSame( 'A description of child one.', $items[0]['supporting_text'], 'post_excerpt must map to supporting_text.' );
        $this->assertNotEmpty( $items[0]['link'], 'Permalink must be resolved into the link field.' );
    }

    public function test_child_posts_source_respects_max_items_limit() {
        // Create 5 children. Definition says max 3.
        for ( $i = 1; $i <= 5; $i++ ) {
            $this->factory->post->create( array(
                'post_type'   => 'pre_test_listing',
                'post_parent' => $this->parent_id,
                'post_title'  => 'Child ' . $i,
                'post_status' => 'publish',
                'menu_order'  => $i,
            ) );
        }

        $entry = array( 'grouping_key' => 'features', 'source' => 'child_posts' );
        $def   = $this->minimal_def( array( 'max_items' => 3 ) );

        $items = $this->resolver->resolve( $entry, $def, get_post( $this->parent_id ) );

        $this->assertCount( 3, $items, 'max_items must cap the resolved items array.' );
    }

    public function test_child_posts_source_returns_empty_when_no_children_exist() {
        $entry = array( 'grouping_key' => 'features', 'source' => 'child_posts' );

        $items = $this->resolver->resolve( $entry, $this->minimal_def(), get_post( $this->parent_id ) );

        $this->assertSame( array(), $items, 'No children → empty array, not WP_Error or null.' );
    }

    // -----------------------------------------------------------------
    // taxonomy_match source
    // -----------------------------------------------------------------

    public function test_taxonomy_match_returns_sibling_posts_sharing_a_term() {
        // Register a taxonomy on the test CPT so wp_get_post_terms has
        // something to read.
        register_taxonomy(
            'pre_test_neighborhood',
            'pre_test_listing',
            array( 'public' => true, 'hierarchical' => false )
        );

        // Create a term and assign it to the parent + two siblings.
        $term_id = $this->factory->term->create( array(
            'taxonomy' => 'pre_test_neighborhood',
            'name'     => 'Sunset District',
        ) );

        wp_set_object_terms( $this->parent_id, array( $term_id ), 'pre_test_neighborhood' );

        $sibling_one = $this->factory->post->create( array(
            'post_type'   => 'pre_test_listing',
            'post_title'  => 'Sibling One',
            'post_status' => 'publish',
        ) );
        $sibling_two = $this->factory->post->create( array(
            'post_type'   => 'pre_test_listing',
            'post_title'  => 'Sibling Two',
            'post_status' => 'publish',
        ) );
        wp_set_object_terms( $sibling_one, array( $term_id ), 'pre_test_neighborhood' );
        wp_set_object_terms( $sibling_two, array( $term_id ), 'pre_test_neighborhood' );

        $entry = array(
            'grouping_key' => 'features',
            'source'       => array(
                'type'         => 'taxonomy_match',
                'taxonomy'     => 'pre_test_neighborhood',
                'exclude_self' => true,
            ),
        );

        $items = $this->resolver->resolve( $entry, $this->minimal_def(), get_post( $this->parent_id ) );

        $this->assertCount(
            2,
            $items,
            'taxonomy_match must return siblings sharing the term, with exclude_self=true (default) skipping the parent.'
        );

        $headings = array_map( function ( $i ) { return $i['heading']; }, $items );
        $this->assertContains( 'Sibling One', $headings );
        $this->assertContains( 'Sibling Two', $headings );
        $this->assertNotContains(
            'Parent Listing',
            $headings,
            'exclude_self=true must skip the post being rendered.'
        );

        unregister_taxonomy( 'pre_test_neighborhood' );
    }

    public function test_taxonomy_match_returns_empty_for_unknown_taxonomy() {
        $entry = array(
            'grouping_key' => 'features',
            'source'       => array(
                'type'     => 'taxonomy_match',
                'taxonomy' => 'this_taxonomy_does_not_exist',
            ),
        );

        $items = $this->resolver->resolve( $entry, $this->minimal_def(), get_post( $this->parent_id ) );

        $this->assertSame(
            array(),
            $items,
            'Unknown taxonomy must resolve to empty items, not throw.'
        );
    }

    public function test_taxonomy_match_respects_explicit_limit_param() {
        register_taxonomy(
            'pre_test_neighborhood',
            'pre_test_listing',
            array( 'public' => true, 'hierarchical' => false )
        );

        $term_id = $this->factory->term->create( array(
            'taxonomy' => 'pre_test_neighborhood',
            'name'     => 'Sunset District',
        ) );
        wp_set_object_terms( $this->parent_id, array( $term_id ), 'pre_test_neighborhood' );

        // Create 5 siblings.
        for ( $i = 1; $i <= 5; $i++ ) {
            $sibling_id = $this->factory->post->create( array(
                'post_type'   => 'pre_test_listing',
                'post_title'  => 'Sibling ' . $i,
                'post_status' => 'publish',
            ) );
            wp_set_object_terms( $sibling_id, array( $term_id ), 'pre_test_neighborhood' );
        }

        $entry = array(
            'grouping_key' => 'features',
            'source'       => array(
                'type'         => 'taxonomy_match',
                'taxonomy'     => 'pre_test_neighborhood',
                'limit'        => 2,
                'exclude_self' => true,
            ),
        );

        $items = $this->resolver->resolve( $entry, $this->minimal_def(), get_post( $this->parent_id ) );

        $this->assertCount(
            2,
            $items,
            'Explicit limit parameter must cap the resolved items.'
        );

        unregister_taxonomy( 'pre_test_neighborhood' );
    }

    // -----------------------------------------------------------------
    // Fallthrough behavior
    // -----------------------------------------------------------------

    public function test_unknown_source_string_resolves_to_empty_array() {
        // Any string source other than "manual" or "child_posts" should
        // resolve to empty rather than throwing — the validator already
        // rejected it at write time, but the resolver runs in render
        // path against potentially-stale storage.
        $entry = array( 'grouping_key' => 'features', 'source' => 'not_a_real_source_mode' );

        $items = $this->resolver->resolve( $entry, $this->minimal_def(), get_post( $this->parent_id ) );

        $this->assertSame( array(), $items );
    }
}
