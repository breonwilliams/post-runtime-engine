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
    // meta_match source
    // -----------------------------------------------------------------

    public function test_meta_match_returns_sibling_posts_with_matching_meta_value() {
        // Tag the parent with an _agent_id, then create two siblings with
        // the same _agent_id (matches) and one with a different _agent_id
        // (no match).
        update_post_meta( $this->parent_id, '_agent_id', 'agent-42' );

        $sibling_match_one = $this->factory->post->create( array(
            'post_type'   => 'pre_test_listing',
            'post_title'  => 'Match One',
            'post_status' => 'publish',
        ) );
        $sibling_match_two = $this->factory->post->create( array(
            'post_type'   => 'pre_test_listing',
            'post_title'  => 'Match Two',
            'post_status' => 'publish',
        ) );
        $sibling_no_match = $this->factory->post->create( array(
            'post_type'   => 'pre_test_listing',
            'post_title'  => 'No Match',
            'post_status' => 'publish',
        ) );
        update_post_meta( $sibling_match_one, '_agent_id', 'agent-42' );
        update_post_meta( $sibling_match_two, '_agent_id', 'agent-42' );
        update_post_meta( $sibling_no_match, '_agent_id', 'agent-99' );

        $entry = array(
            'grouping_key' => 'features',
            'source'       => array(
                'type'         => 'meta_match',
                'meta_key'     => '_agent_id',
                'exclude_self' => true,
            ),
        );

        $items = $this->resolver->resolve( $entry, $this->minimal_def(), get_post( $this->parent_id ) );

        $this->assertCount(
            2,
            $items,
            'meta_match must return only siblings whose meta value matches the parent.'
        );

        $headings = array_map( function ( $i ) { return $i['heading']; }, $items );
        $this->assertContains( 'Match One', $headings );
        $this->assertContains( 'Match Two', $headings );
        $this->assertNotContains( 'No Match', $headings, 'Posts with a different meta value must not be included.' );
        $this->assertNotContains(
            'Parent Listing',
            $headings,
            'exclude_self=true (default) must skip the post being rendered.'
        );
    }

    public function test_meta_match_includes_self_when_exclude_self_false() {
        update_post_meta( $this->parent_id, '_agent_id', 'agent-42' );

        $sibling = $this->factory->post->create( array(
            'post_type'   => 'pre_test_listing',
            'post_title'  => 'Sibling',
            'post_status' => 'publish',
        ) );
        update_post_meta( $sibling, '_agent_id', 'agent-42' );

        $entry = array(
            'grouping_key' => 'features',
            'source'       => array(
                'type'         => 'meta_match',
                'meta_key'     => '_agent_id',
                'exclude_self' => false,
            ),
        );

        $items = $this->resolver->resolve( $entry, $this->minimal_def(), get_post( $this->parent_id ) );

        $headings = array_map( function ( $i ) { return $i['heading']; }, $items );
        $this->assertContains( 'Parent Listing', $headings, 'exclude_self=false must include the parent.' );
        $this->assertContains( 'Sibling', $headings );
    }

    public function test_meta_match_returns_empty_when_parent_has_no_meta_value() {
        // Parent has no _agent_id at all. Resolver must short-circuit to
        // empty rather than match every post that ALSO has no value.
        $sibling_unset = $this->factory->post->create( array(
            'post_type'   => 'pre_test_listing',
            'post_title'  => 'Sibling Without Meta',
            'post_status' => 'publish',
        ) );

        $entry = array(
            'grouping_key' => 'features',
            'source'       => array(
                'type'     => 'meta_match',
                'meta_key' => '_agent_id',
            ),
        );

        $items = $this->resolver->resolve( $entry, $this->minimal_def(), get_post( $this->parent_id ) );

        $this->assertSame(
            array(),
            $items,
            'Empty parent meta value must short-circuit to empty array (would otherwise match every empty-meta post in the CPT).'
        );
    }

    public function test_meta_match_returns_empty_when_parent_meta_value_is_empty_string() {
        update_post_meta( $this->parent_id, '_agent_id', '' );

        $sibling_match = $this->factory->post->create( array(
            'post_type'   => 'pre_test_listing',
            'post_title'  => 'Sibling',
            'post_status' => 'publish',
        ) );
        update_post_meta( $sibling_match, '_agent_id', '' );

        $entry = array(
            'grouping_key' => 'features',
            'source'       => array(
                'type'     => 'meta_match',
                'meta_key' => '_agent_id',
            ),
        );

        $items = $this->resolver->resolve( $entry, $this->minimal_def(), get_post( $this->parent_id ) );

        $this->assertSame( array(), $items, 'Empty-string meta value must short-circuit to empty.' );
    }

    public function test_meta_match_respects_explicit_limit_param() {
        update_post_meta( $this->parent_id, '_agent_id', 'agent-42' );

        for ( $i = 1; $i <= 5; $i++ ) {
            $sibling_id = $this->factory->post->create( array(
                'post_type'   => 'pre_test_listing',
                'post_title'  => 'Sibling ' . $i,
                'post_status' => 'publish',
            ) );
            update_post_meta( $sibling_id, '_agent_id', 'agent-42' );
        }

        $entry = array(
            'grouping_key' => 'features',
            'source'       => array(
                'type'         => 'meta_match',
                'meta_key'     => '_agent_id',
                'limit'        => 2,
                'exclude_self' => true,
            ),
        );

        $items = $this->resolver->resolve( $entry, $this->minimal_def(), get_post( $this->parent_id ) );

        $this->assertCount( 2, $items, 'Explicit limit must cap resolved items.' );
    }

    public function test_meta_match_only_matches_same_post_type() {
        // Sanity check: meta_match constrains to the parent's post_type.
        // A different CPT with the same meta value must NOT be returned.
        update_post_meta( $this->parent_id, '_agent_id', 'agent-42' );

        // Create a post in a different CPT with the same meta value.
        $foreign = $this->factory->post->create( array(
            'post_type'   => 'post', // different post type
            'post_title'  => 'Foreign Match',
            'post_status' => 'publish',
        ) );
        update_post_meta( $foreign, '_agent_id', 'agent-42' );

        $entry = array(
            'grouping_key' => 'features',
            'source'       => array(
                'type'     => 'meta_match',
                'meta_key' => '_agent_id',
            ),
        );

        $items = $this->resolver->resolve( $entry, $this->minimal_def(), get_post( $this->parent_id ) );

        $headings = array_map( function ( $i ) { return $i['heading']; }, $items );
        $this->assertNotContains(
            'Foreign Match',
            $headings,
            'meta_match must scope to the parent\'s post_type — cross-CPT matches are out of scope for v1.'
        );
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
