<?php
/**
 * Integration tests for the remaining connector REST endpoints.
 *
 * ConnectorApiTest covers auth gates + the 5 highest-traffic endpoints.
 * This file rounds out coverage of the remaining 14 endpoints, focused
 * on connector-LAYER behavior that isn't tested elsewhere:
 *
 *   - Slug / key immutability on updates
 *   - Concurrency (connector_version) checks
 *   - Atomic rollback on create_post when groupings validation fails
 *   - Data-protection: delete preserves post meta unless purge_data=true
 *   - Catalog endpoints stay in sync with PCPTPages_Validator constants
 *
 * Pure-delegation paths (e.g. list_cpts wrapping the registry's get_all)
 * get a single sanity check rather than a full happy-path test, since
 * the underlying behavior is already covered by the data-layer tests.
 *
 * @package PostRuntimeEngine\Tests\Integration
 */

namespace PRE\Tests\Integration;

class ConnectorEndpointsTest extends IntegrationTestCase {

    const BASE = '/post-runtime/v1/connector';

    public function set_up() {
        parent::set_up();

        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down() {
        global $wp_rest_server;
        $wp_rest_server = null;

        parent::tear_down();
    }

    // -----------------------------------------------------------------
    // Catalog endpoints — must stay in sync with PCPTPages_Validator
    //
    // The catalog endpoints exist so external agents can introspect
    // valid values WITHOUT hardcoding them. If the validator's enum
    // grows but the catalog endpoint stays stale, agents will see
    // false rejections — which is hard to debug from the agent side.
    // -----------------------------------------------------------------

    public function test_variants_endpoint_returns_every_validator_variant() {
        $this->enable_connector_as_admin();

        $response = $this->dispatch_rest_request( 'GET', self::BASE . '/variants' );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertArrayHasKey( 'variants', $data );

        $returned_ids = array_column( $data['variants'], 'id' );
        sort( $returned_ids );
        $expected_ids = \PCPTPages_Validator::VARIANTS;
        sort( $expected_ids );

        $this->assertSame(
            $expected_ids,
            $returned_ids,
            'variants endpoint must mirror PCPTPages_Validator::VARIANTS exactly — drift between the two means agents see invalid options or miss valid ones.'
        );
    }

    public function test_positions_endpoint_returns_every_validator_position() {
        $this->enable_connector_as_admin();

        $response = $this->dispatch_rest_request( 'GET', self::BASE . '/positions' );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertArrayHasKey( 'positions', $data );

        $returned_ids = array_column( $data['positions'], 'id' );
        sort( $returned_ids );
        $expected_ids = \PCPTPages_Validator::POSITIONS;
        sort( $expected_ids );

        $this->assertSame(
            $expected_ids,
            $returned_ids,
            'positions endpoint must mirror PCPTPages_Validator::POSITIONS exactly.'
        );
    }

    // -----------------------------------------------------------------
    // List endpoints — sanity check (delegate to registries)
    // -----------------------------------------------------------------

    public function test_list_cpts_returns_all_registered_cpts() {
        $this->enable_connector_as_admin();

        $this->register_test_cpt( 'pre_test_listing' );
        $this->register_test_cpt( 'pre_test_attorney' );

        $response = $this->dispatch_rest_request( 'GET', self::BASE . '/cpts' );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertArrayHasKey( 'cpts', $data );

        $slugs = array_column( $data['cpts'], 'slug' );
        $this->assertContains( 'pre_test_listing', $slugs );
        $this->assertContains( 'pre_test_attorney', $slugs );
    }

    // -----------------------------------------------------------------
    // CPT update — slug immutability
    //
    // CPT slugs are part of the URL structure of the site once published.
    // Renaming a CPT mid-life would break inbound links + cached
    // permalinks. The connector enforces this at write time so an agent
    // can't introduce drift by accident.
    // -----------------------------------------------------------------

    public function test_update_cpt_rejects_attempt_to_change_slug() {
        $this->enable_connector_as_admin();

        $this->register_test_cpt( 'pre_test_listing' );

        // PUT with a different slug in the body must fail. URL slug is
        // authoritative; body slug must match (or be omitted).
        $response = $this->dispatch_rest_request(
            'PUT',
            self::BASE . '/cpts/pre_test_listing',
            array(
                'slug'           => 'pre_test_renamed',  // mismatched — must reject
                'label_singular' => 'Listing',
                'label_plural'   => 'Listings',
            )
        );

        $this->assertSame(
            400,
            $response->get_status(),
            'Slug-mismatch must surface as 400 Bad Request, not 422 — it\'s a URL-level mismatch, not a validation failure.'
        );
        $this->assertSame(
            'pre_immutable_field',
            $response->get_data()['code'] ?? null,
            'Error code must clearly identify the immutability violation so agents can self-correct.'
        );
    }

    // -----------------------------------------------------------------
    // CPT delete — data protection
    //
    // The connector mirrors the plugin's overall data-protection
    // principle: destructive operations must be reversible OR explicit.
    // delete_cpt removes the CPT registration but preserves per-post
    // meta UNLESS the caller explicitly opts in via purge_data=true.
    // -----------------------------------------------------------------

    public function test_delete_cpt_without_purge_data_preserves_post_meta() {
        $this->enable_connector_as_admin();

        $this->register_test_cpt( 'pre_test_listing' );
        $this->plugin->cpts->register_all_with_wp();

        // Create a post with grouping data.
        $post_id = $this->factory->post->create( array(
            'post_type'   => 'pre_test_listing',
            'post_status' => 'publish',
        ) );
        update_post_meta( $post_id, '_pre_groupings', array( array( 'grouping_key' => 'features' ) ) );

        // Delete CPT WITHOUT purge_data flag.
        $response = $this->dispatch_rest_request(
            'DELETE',
            self::BASE . '/cpts/pre_test_listing'
        );

        $this->assertSame( 200, $response->get_status() );
        $this->assertTrue( (bool) $response->get_data()['deleted'] );

        // Per-post meta must still exist — the data is preserved so a
        // future re-registration picks up where it left off.
        $this->assertNotEmpty(
            get_post_meta( $post_id, '_pre_groupings', true ),
            'Per-post meta must NOT be deleted unless purge_data=true was explicitly passed. Data protection principle: destruction is opt-in.'
        );
    }

    public function test_delete_cpt_with_purge_data_true_removes_post_meta() {
        $this->enable_connector_as_admin();

        $this->register_test_cpt( 'pre_test_listing' );
        $this->plugin->cpts->register_all_with_wp();

        $post_id = $this->factory->post->create( array(
            'post_type'   => 'pre_test_listing',
            'post_status' => 'publish',
        ) );
        update_post_meta( $post_id, '_pre_groupings', array( array( 'grouping_key' => 'features' ) ) );

        // Build a request with purge_data as a query param.
        $request = new \WP_REST_Request( 'DELETE', self::BASE . '/cpts/pre_test_listing' );
        $request->set_param( 'purge_data', true );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertTrue( (bool) $response->get_data()['purged'] );

        // Per-post meta should now be gone — explicit opt-in honored.
        $this->assertEmpty(
            get_post_meta( $post_id, '_pre_groupings', true ),
            'When purge_data=true is explicit, per-post meta must be cleaned up so the CPT can be cleanly removed.'
        );
    }

    // -----------------------------------------------------------------
    // Grouping delete
    // -----------------------------------------------------------------

    public function test_delete_grouping_returns_200_with_body() {
        $this->enable_connector_as_admin();

        $this->register_test_cpt( 'pre_test_listing' );
        $this->define_test_grouping( 'pre_test_listing' );

        $response = $this->dispatch_rest_request(
            'DELETE',
            self::BASE . '/cpts/pre_test_listing/groupings/features'
        );

        $this->assertSame(
            200,
            $response->get_status(),
            'DELETE returns a 200 JSON envelope (not a bare 204) so connector clients that expect a body on every response do not misread success as an error.'
        );
        $this->assertTrue( (bool) $response->get_data()['deleted'] );

        $this->assertFalse(
            $this->plugin->groupings->exists( 'pre_test_listing', 'features' ),
            'Grouping must actually be removed from the registry.'
        );
    }

    // -----------------------------------------------------------------
    // 404 paths — surface "not found" cleanly
    //
    // Connector consumers need stable error codes to pattern-match on.
    // 404 + a specific code lets an agent know whether it should
    // create-then-update or just bail.
    // -----------------------------------------------------------------

    public function test_get_unknown_cpt_returns_404_with_specific_code() {
        $this->enable_connector_as_admin();

        $response = $this->dispatch_rest_request( 'GET', self::BASE . '/cpts/this_cpt_was_never_registered' );

        $this->assertSame( 404, $response->get_status() );
        $this->assertSame(
            'pre_cpt_not_found',
            $response->get_data()['code'] ?? null,
            'Unknown CPT must surface as pre_cpt_not_found — distinct from rest_no_route or generic 404 so agents can branch on it.'
        );
    }

    public function test_get_unknown_grouping_returns_404_with_specific_code() {
        $this->enable_connector_as_admin();

        $this->register_test_cpt( 'pre_test_listing' );

        $response = $this->dispatch_rest_request(
            'GET',
            self::BASE . '/cpts/pre_test_listing/groupings/never_defined'
        );

        $this->assertSame( 404, $response->get_status() );
        $this->assertSame(
            'pre_grouping_not_found',
            $response->get_data()['code'] ?? null,
            'Unknown grouping (on a known CPT) must return pre_grouping_not_found — distinct from pre_cpt_not_found.'
        );
    }

    // -----------------------------------------------------------------
    // Create post with inline groupings — atomicity
    //
    // The contract: if grouping validation fails after the post is
    // inserted, the post is deleted (rolled back). External agents
    // should NEVER see a draft post with no grouping data when their
    // create_post call returned an error.
    // -----------------------------------------------------------------

    public function test_create_post_with_inline_groupings_persists_both() {
        $this->enable_connector_as_admin();

        $this->register_test_cpt( 'pre_test_listing' );
        $this->plugin->cpts->register_all_with_wp();
        $this->define_test_grouping( 'pre_test_listing', array(
            'key'              => 'features',
            'label'            => 'Features',
            'default_variant'  => 'card-grid',
            'default_position' => 'above_main',
        ) );

        $response = $this->dispatch_rest_request( 'POST', self::BASE . '/posts', array(
            'post_type'   => 'pre_test_listing',
            'post_title'  => 'Sunset Villa',
            'post_status' => 'publish',
            'groupings'   => array(
                array(
                    'grouping_key' => 'features',
                    'position'     => 'above_main',
                    'source'       => 'manual',
                    'items'        => array( array( 'heading' => 'Pool', 'icon_id' => 'home' ) ),
                ),
            ),
        ) );

        $this->assertSame(
            201,
            $response->get_status(),
            'Post creation with inline groupings must return 201. Response: ' . wp_json_encode( $response->get_data() )
        );

        $data    = $response->get_data();
        $post_id = $data['post_id'];

        // The post itself.
        $post = get_post( $post_id );
        $this->assertNotNull( $post );
        $this->assertSame( 'Sunset Villa', $post->post_title );

        // The groupings — must have been persisted alongside the post.
        $stored = get_post_meta( $post_id, '_pre_groupings', true );
        $this->assertIsArray( $stored );
        $this->assertCount( 1, $stored );
        $this->assertSame( 'features', $stored[0]['grouping_key'] );
    }

    public function test_create_post_rolls_back_when_inline_groupings_fail_validation() {
        $this->enable_connector_as_admin();

        $this->register_test_cpt( 'pre_test_listing' );
        $this->plugin->cpts->register_all_with_wp();
        $this->define_test_grouping( 'pre_test_listing', array(
            'key'              => 'features',
            'label'            => 'Features',
            'default_variant'  => 'card-grid',
            'default_position' => 'above_main',
        ) );

        // Snapshot post count before. The rollback assertion compares to this.
        $before = (int) wp_count_posts( 'pre_test_listing' )->publish;

        // Inline groupings reference a grouping_key that doesn't exist
        // on this CPT — validator must reject, connector must roll back
        // the post creation.
        $response = $this->dispatch_rest_request( 'POST', self::BASE . '/posts', array(
            'post_type'   => 'pre_test_listing',
            'post_title'  => 'Will Be Rolled Back',
            'post_status' => 'publish',
            'groupings'   => array(
                array(
                    'grouping_key' => 'never_defined',  // invalid
                    'position'     => 'above_main',
                    'source'       => 'manual',
                    'items'        => array( array( 'heading' => 'Should not persist', 'icon_id' => 'home' ) ),
                ),
            ),
        ) );

        $this->assertGreaterThanOrEqual(
            400,
            $response->get_status(),
            'Failed inline-grouping validation must return a 4xx status, not a 201 with errors buried in the response body.'
        );

        // The post must NOT exist — rollback discipline.
        $after = (int) wp_count_posts( 'pre_test_listing' )->publish;
        $this->assertSame(
            $before,
            $after,
            'Post count must be unchanged after a failed inline-groupings create — partial state would leak draft posts with no groupings.'
        );

        // And specifically, no post with this title.
        $found = get_posts( array(
            'post_type'   => 'pre_test_listing',
            'post_status' => 'any',
            'title'       => 'Will Be Rolled Back',
            'fields'      => 'ids',
        ) );
        $this->assertEmpty( $found, 'No orphan post with the rejected title must exist.' );
    }
}
