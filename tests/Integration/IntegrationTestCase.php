<?php
/**
 * Base Integration Test Case for Post Runtime Engine.
 *
 * Mirrors FRE's IntegrationTestCase but PRE-specific. Each test runs
 * against a real WordPress test instance — wp_options, post_meta, REST
 * API, hooks, and the autoloader all behave like production. Use these
 * tests for things mocks can't reach: persistence round-trips,
 * register_post_type / unregister_post_type interaction, REST endpoint
 * authentication, template_include routing.
 *
 * Setup discipline:
 *
 *   - Tests should clean up their own state in `tear_down`. The base
 *     class clears CPT and grouping options between tests so a leaky
 *     test in one class can't pollute another.
 *
 *   - Storage in PRE is wp_options + post_meta only (no custom tables).
 *     The cleanup hooks reflect that — anything PRE-prefixed in those
 *     two surfaces is fair game for the truncate.
 *
 * @package PostRuntimeEngine\Tests\Integration
 */

namespace PRE\Tests\Integration;

/**
 * Base integration test case with WordPress loaded.
 */
abstract class IntegrationTestCase extends \WP_UnitTestCase {

    /**
     * Plugin instance. Lazily resolved in set_up().
     *
     * @var \Post_Runtime_Engine
     */
    protected $plugin;

    /**
     * Set up before each test. Clears registry state so tests start
     * from a clean slate without depending on declaration order.
     */
    public function set_up() {
        parent::set_up();

        $this->plugin = pcptpages();

        $this->clear_pre_state();
    }

    /**
     * Tear down after each test. Same cleanup as set_up — paranoid
     * symmetry. Tests that intentionally leave data should opt out
     * by overriding this.
     */
    public function tear_down() {
        $this->clear_pre_state();

        parent::tear_down();
    }

    /**
     * Clear all PRE-managed state from wp_options and post_meta.
     *
     * This is the single source of truth for "what does PRE persist".
     * If a future feature adds a new options key or post-meta key,
     * extend the LIKE patterns here so the test suite continues to
     * leave a clean slate between tests.
     */
    protected function clear_pre_state() {
        global $wpdb;

        // wp_options: pre_cpts, pre_groupings_*, pre_connector_enabled,
        // pre_connector_* rate-limit transients, pre_needs_rewrite_flush
        // transient, and any future pre_* options.
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name = 'pre_cpts'
                OR option_name = 'pre_connector_enabled'
                OR option_name LIKE 'pre_groupings_%'
                OR option_name LIKE '_transient_pre_%'
                OR option_name LIKE '_transient_timeout_pre_%'"
        );

        // post_meta: _pre_groupings and the backup chain.
        $wpdb->query(
            "DELETE FROM {$wpdb->postmeta}
             WHERE meta_key LIKE '\\_pre\\_%'"
        );

        // Reset the in-process caches so the next test sees option-level
        // changes (rather than reading a stale memoized array).
        if ( $this->plugin && isset( $this->plugin->cpts ) && is_object( $this->plugin->cpts ) ) {
            $this->plugin->cpts->reset_cache();
        }
        if ( $this->plugin && isset( $this->plugin->groupings ) && is_object( $this->plugin->groupings ) ) {
            $this->plugin->groupings->reset_cache();
        }

        // Drop any post types this plugin registered with WordPress so
        // they don't leak between tests. WP keeps a separate runtime
        // registry from our options-backed one.
        global $wp_post_types;
        if ( is_array( $wp_post_types ) ) {
            foreach ( array_keys( $wp_post_types ) as $post_type ) {
                // Spare core types and types owned by other plugins —
                // only unregister types we know we created.
                if ( in_array( $post_type, array( 'pre_test_listing', 'pre_test_attorney', 'pre_test_event' ), true ) ) {
                    unregister_post_type( $post_type );
                }
            }
        }
    }

    /**
     * Convenience: register a CPT through the registry, asserting
     * success. Tests that need failure paths should call register()
     * directly and inspect the WP_Error.
     *
     * @param string $slug       CPT slug.
     * @param array  $overrides  Optional overrides on top of the minimal
     *                           valid definition.
     */
    protected function register_test_cpt( $slug, array $overrides = array() ) {
        $definition = array_merge( array(
            'slug'           => $slug,
            'label_singular' => ucfirst( $slug ),
            'label_plural'   => ucfirst( $slug ) . 's',
            'public'         => true,
        ), $overrides );

        $result = $this->plugin->cpts->register( $slug, $definition );
        $this->assertTrue(
            $result === true,
            'Test fixture failed to register CPT: '
                . ( is_wp_error( $result ) ? $result->get_error_message() : 'unknown error' )
        );
        return $result;
    }

    /**
     * Convenience: define a grouping for a CPT, asserting success.
     *
     * @param string $cpt_slug  CPT slug (must already be registered).
     * @param array  $overrides Optional overrides on top of the minimal
     *                          valid definition.
     */
    protected function define_test_grouping( $cpt_slug, array $overrides = array() ) {
        $definition = array_merge( array(
            'key'              => 'features',
            'label'            => 'Features',
            'default_variant'  => 'card-grid',
            'default_position' => 'above_main',
        ), $overrides );

        $result = $this->plugin->groupings->define( $cpt_slug, $definition );
        $this->assertTrue(
            $result === true,
            'Test fixture failed to define grouping: '
                . ( is_wp_error( $result ) ? $result->get_error_message() : 'unknown error' )
        );
        return $result;
    }

    /**
     * Convenience: assert a CPT slug is registered both in PRE's option
     * store AND with WordPress's runtime registry. The double-check
     * matters because the two can drift (PRE option says yes, but WP
     * never got the register_post_type call, or vice versa).
     *
     * @param string $slug    CPT slug.
     * @param string $message Optional message.
     */
    protected function assertCptRegistered( $slug, $message = '' ) {
        $this->assertTrue(
            $this->plugin->cpts->exists( $slug ),
            $message ?: "CPT '{$slug}' should exist in PRE registry."
        );
        $this->assertTrue(
            post_type_exists( $slug ),
            $message ?: "CPT '{$slug}' should be registered with WordPress."
        );
    }

    /**
     * Set up the "admin can use the connector" baseline. Most connector
     * tests start from this state, so the helper avoids per-test boilerplate.
     *
     * Side effects:
     *   - Enables the site-wide connector toggle.
     *   - Creates an administrator user and sets them as the current user
     *     (satisfying the auth stack's logged-in + capable gates).
     *
     * @return int The administrator user ID.
     */
    protected function enable_connector_as_admin() {
        \PCPTPages_Connector_Settings::set_enabled( true );

        $admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );

        return $admin_id;
    }

    /**
     * Capture the renderer's output for a post into a string.
     *
     * The renderer echoes via the_content() and direct echo statements,
     * so tests need an output-buffer wrapper to inspect the HTML. This
     * helper isolates that boilerplate.
     *
     * @param int  $post_id   Post ID to render.
     * @param bool $use_cache Whether to allow cache hits. Default false
     *                        for tests so each call exercises the full
     *                        render pipeline (tests that specifically
     *                        check caching pass true).
     * @return string Rendered HTML.
     */
    protected function capture_render( $post_id, $use_cache = false ) {
        $post = get_post( $post_id );
        $this->assertNotNull( $post, "capture_render: post {$post_id} not found." );

        $renderer = new \PCPTPages_Renderer();

        ob_start();
        $renderer->render( $post, $use_cache );
        return ob_get_clean();
    }

    /**
     * Dispatch a REST request through the running test server.
     *
     * Wraps the boilerplate of building a WP_REST_Request, attaching
     * params, and dispatching via rest_get_server(). Returns the
     * WP_REST_Response so tests can inspect status + data.
     *
     * @param string $method HTTP method.
     * @param string $route  Full route, including leading slash and
     *                       namespace (e.g. "/post-runtime/v1/connector/cpts").
     * @param array  $params Optional params (body for POST/PUT/DELETE,
     *                       query string for GET).
     * @return \WP_REST_Response
     */
    protected function dispatch_rest_request( $method, $route, array $params = array() ) {
        $request = new \WP_REST_Request( $method, $route );

        // For write methods, set body params + JSON content type so the
        // server pulls them via $request->get_json_params() the same way
        // a real client would.
        if ( in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
            $request->set_header( 'Content-Type', 'application/json' );
            $request->set_body( wp_json_encode( $params ) );
        } else {
            foreach ( $params as $key => $value ) {
                $request->set_param( $key, $value );
            }
        }

        return rest_get_server()->dispatch( $request );
    }
}
