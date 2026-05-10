<?php
/**
 * Integration tests for the Cowork connector REST API.
 *
 * Why integration: the auth stack (PRE_Connector_Auth) and the route
 * registration in PRE_Connector_API are tightly coupled to WP's REST
 * infrastructure — register_rest_route, permission callbacks, capability
 * checks, transient-backed rate limiting. Mocking that surface meaningfully
 * is harder than just running tests against a real WP test instance.
 *
 * Coverage strategy: this file pins the audit-flagged "god-object"
 * behavior that's most error-prone — auth gates, validation propagation,
 * the highest-traffic write endpoints. Read endpoints mostly delegate to
 * registries already covered by unit tests, so we don't duplicate.
 *
 * @package PostRuntimeEngine\Tests\Integration
 */

namespace PRE\Tests\Integration;

class ConnectorApiTest extends IntegrationTestCase {

    /**
     * Base path for connector routes — matches PRE_REST_NAMESPACE/PRE_REST_BASE.
     */
    const BASE = '/post-runtime/v1/connector';

    public function set_up() {
        parent::set_up();

        // The REST server is lazily initialized — force it now so route
        // registration runs against a clean dispatcher each test.
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
    // Auth gate 1 — connector toggle
    //
    // The connector is opt-in. Until enabled, every endpoint must
    // return 403 connector_disabled — even for site administrators.
    // -----------------------------------------------------------------

    public function test_preflight_blocks_when_connector_disabled() {
        // Connector disabled (default state). Even an admin should get 403.
        $admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );

        $response = $this->dispatch_rest_request( 'GET', self::BASE . '/preflight' );

        $this->assertSame( 403, $response->get_status() );
        $this->assertSame(
            'connector_disabled',
            $response->get_data()['code'] ?? null,
            'Disabled connector must return code "connector_disabled" so external agents can self-diagnose.'
        );
    }

    // -----------------------------------------------------------------
    // Auth gate 2 — authentication
    // -----------------------------------------------------------------

    public function test_preflight_blocks_unauthenticated_request_when_enabled() {
        \PRE_Connector_Settings::set_enabled( true );
        wp_set_current_user( 0 );  // explicitly unauthenticated

        $response = $this->dispatch_rest_request( 'GET', self::BASE . '/preflight' );

        $this->assertSame( 401, $response->get_status() );
        $this->assertSame(
            'rest_not_logged_in',
            $response->get_data()['code'] ?? null,
            'Unauthenticated requests must return rest_not_logged_in (401), not connector_disabled (403).'
        );
    }

    // -----------------------------------------------------------------
    // Auth gate 3 — capability
    //
    // Logged in but without the manage capability — must be 403
    // rest_forbidden, NOT 401. The 401-vs-403 distinction matters for
    // external agents to know whether to retry with different creds
    // (401) or escalate to a human (403).
    // -----------------------------------------------------------------

    public function test_preflight_blocks_subscriber_role_with_403() {
        \PRE_Connector_Settings::set_enabled( true );

        $subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $subscriber_id );

        $response = $this->dispatch_rest_request( 'GET', self::BASE . '/preflight' );

        $this->assertSame( 403, $response->get_status() );
        $this->assertSame(
            'rest_forbidden',
            $response->get_data()['code'] ?? null,
            'Subscribers must get rest_forbidden — never connector_disabled (which would imply re-enabling fixes it) or rest_not_logged_in (which would imply re-auth fixes it).'
        );
    }

    // -----------------------------------------------------------------
    // Happy path — preflight payload contract
    //
    // The audit (I2) flagged that docs/CONNECTOR_SPEC.md doesn't
    // document critical_rules and field_name_hints, even though the
    // preflight handler emits them. Pinning these here so the contract
    // is enforced at the test layer until the spec catches up — and
    // afterward, so accidental regressions are caught.
    //
    // Note: enum catalogs (variants, positions, source_modes) are NOT
    // in preflight — they're exposed via dedicated /variants and
    // /positions endpoints. That separation is intentional: preflight
    // is for connector state + authoring rules, not for raw enum dumps.
    // -----------------------------------------------------------------

    public function test_preflight_returns_metadata_for_admin_when_enabled() {
        $this->enable_connector_as_admin();

        $response = $this->dispatch_rest_request( 'GET', self::BASE . '/preflight' );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();

        // Version + REST routing metadata — agents use these to verify
        // they're talking to a compatible plugin version and to know
        // where to send subsequent requests.
        $this->assertArrayHasKey( 'plugin_version', $data );
        $this->assertArrayHasKey( 'data_version', $data );
        $this->assertArrayHasKey( 'rest_namespace', $data );
        $this->assertArrayHasKey( 'rest_base', $data );
        $this->assertSame( PRE_VERSION, $data['plugin_version'] );

        // Per-user capability summary — lets agents know which writes
        // they're allowed to make before they bother attempting them.
        $this->assertArrayHasKey( 'user', $data );
        $this->assertArrayHasKey( 'can_manage_cpts', $data['user'] );
        $this->assertTrue(
            $data['user']['can_manage_cpts'],
            'An administrator must report can_manage_cpts=true in preflight.'
        );

        // Critical fields the audit (I2) flagged as undocumented — pin
        // their presence and shape now so the contract is observable.
        $this->assertArrayHasKey(
            'critical_rules',
            $data,
            'preflight must include critical_rules — the authoring rulebook AI agents rely on. (Audit finding I2.)'
        );
        $this->assertIsArray( $data['critical_rules'] );
        $this->assertNotEmpty(
            $data['critical_rules'],
            'critical_rules cannot be empty — it carries the don\'t-do-this lessons distilled from past authoring failures.'
        );

        $this->assertArrayHasKey(
            'field_name_hints',
            $data,
            'preflight must include field_name_hints — the canonical field-name map per variant. (Audit finding I2.)'
        );
        $this->assertArrayHasKey( 'groupings_item_shape', $data['field_name_hints'] );
        $this->assertArrayHasKey(
            'card-grid',
            $data['field_name_hints']['groupings_item_shape'],
            'field_name_hints.groupings_item_shape must include every variant by name.'
        );

        // Promptless WP integration discovery — agents use this to know
        // whether design tokens will inherit or whether the documented
        // fallbacks apply.
        $this->assertArrayHasKey( 'promptless_active', $data );
        $this->assertIsBool( $data['promptless_active'] );
    }

    // -----------------------------------------------------------------
    // POST /cpts — register a CPT via the connector
    // -----------------------------------------------------------------

    public function test_register_cpt_persists_via_registry() {
        $this->enable_connector_as_admin();

        $response = $this->dispatch_rest_request( 'POST', self::BASE . '/cpts', array(
            'slug'           => 'pre_test_listing',
            'label_singular' => 'Listing',
            'label_plural'   => 'Listings',
            'public'         => true,
        ) );

        // Two-status acceptance: REST conventions allow either 200
        // (idempotent semantics) or 201 (resource-created semantics).
        // Pin both as acceptable rather than locking the test to one.
        $this->assertContains(
            $response->get_status(),
            array( 200, 201 ),
            'Successful CPT registration must return 200 or 201, got ' . $response->get_status()
        );

        // Round-trip via the registry. The connector and the registry
        // must agree on what was persisted.
        $stored = $this->plugin->cpts->get( 'pre_test_listing' );
        $this->assertNotNull( $stored, 'Registry must reflect a successfully-dispatched CPT registration.' );
        $this->assertSame( 'Listing', $stored['label_singular'] );
    }

    public function test_register_cpt_propagates_validator_error_to_rest_response() {
        $this->enable_connector_as_admin();

        // Reserved slug — validator must reject. Connector must propagate
        // the validator's error code to the REST response so external
        // agents can pattern-match on it.
        $response = $this->dispatch_rest_request( 'POST', self::BASE . '/cpts', array(
            'slug'           => 'page',  // reserved
            'label_singular' => 'X',
            'label_plural'   => 'Xs',
        ) );

        // Plugin uses 422 Unprocessable Entity — RFC 4918's status for
        // "well-formed but semantically invalid", which is exactly what a
        // validator rejection is. (400 is for malformed-syntax-level
        // failures: malformed JSON, missing required URL params, etc.)
        // Pin 422 specifically — changing it would be a breaking change
        // to external clients pattern-matching on status codes.
        $this->assertSame(
            422,
            $response->get_status(),
            'Validator rejections must return 422 Unprocessable Entity (semantically-invalid input), not 400 (malformed syntax) or 500 (server error).'
        );
        $this->assertSame(
            'pre_reserved_slug',
            $response->get_data()['code'] ?? null,
            'Validator error code must reach the REST response unchanged so agents can self-correct.'
        );

        // No partial write should have happened.
        $this->assertFalse(
            $this->plugin->cpts->exists( 'page' ),
            'Failed registration must not leak into storage.'
        );
    }

    // -----------------------------------------------------------------
    // POST /cpts/{slug}/groupings — define a grouping via the connector
    // -----------------------------------------------------------------

    public function test_define_grouping_persists_via_registry() {
        $this->enable_connector_as_admin();

        // Set up the parent CPT first.
        $this->register_test_cpt( 'pre_test_listing' );
        $this->plugin->cpts->register_all_with_wp();

        $response = $this->dispatch_rest_request(
            'POST',
            self::BASE . '/cpts/pre_test_listing/groupings',
            array(
                'key'              => 'features',
                'label'            => 'Features',
                'default_variant'  => 'card-grid',
                'default_position' => 'above_main',
            )
        );

        $this->assertContains(
            $response->get_status(),
            array( 200, 201 ),
            'Successful grouping definition must return 200 or 201, got ' . $response->get_status()
        );

        $stored = $this->plugin->groupings->get( 'pre_test_listing', 'features' );
        $this->assertNotNull( $stored );
        $this->assertSame( 'card-grid', $stored['default_variant'] );
    }

    // -----------------------------------------------------------------
    // PUT /posts/{id}/groupings — set per-post groupings
    //
    // The "workhorse" endpoint — every connector-driven content update
    // flows through it. Per-post permission check (edit_post) means
    // the test admin must own or have rights to the post.
    // -----------------------------------------------------------------

    public function test_set_post_groupings_round_trip() {
        $admin_id = $this->enable_connector_as_admin();

        // Register CPT + grouping definition via the data layer (faster
        // than going through REST for setup).
        $this->register_test_cpt( 'pre_test_listing' );
        $this->plugin->cpts->register_all_with_wp();
        $this->define_test_grouping( 'pre_test_listing', array(
            'key'              => 'features',
            'label'            => 'Features',
            'default_variant'  => 'card-grid',
            'default_position' => 'above_main',
        ) );

        // Create a post the admin can edit.
        $post_id = $this->factory->post->create( array(
            'post_type'   => 'pre_test_listing',
            'post_status' => 'publish',
            'post_author' => $admin_id,
        ) );

        $response = $this->dispatch_rest_request(
            'PUT',
            self::BASE . '/posts/' . $post_id . '/groupings',
            array(
                'groupings' => array(
                    array(
                        'grouping_key' => 'features',
                        'position'     => 'above_main',
                        'source'       => 'manual',
                        'items'        => array(
                            array( 'heading' => 'Pool', 'icon_id' => 'home' ),
                            array( 'heading' => 'Garage', 'icon_id' => 'home' ),
                        ),
                    ),
                ),
            )
        );

        $this->assertSame(
            200,
            $response->get_status(),
            'Successful set_post_groupings must return 200. Response: ' . wp_json_encode( $response->get_data() )
        );

        // Verify via post_meta directly — confirms the storage shape, not
        // just the accessor.
        $stored = get_post_meta( $post_id, '_pre_groupings', true );
        $this->assertIsArray( $stored );
        $this->assertCount( 1, $stored );
        $this->assertSame( 'features', $stored[0]['grouping_key'] );
        $this->assertCount( 2, $stored[0]['items'] );
    }
}
