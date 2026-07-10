<?php
/**
 * Cowork connector REST API.
 *
 * Registers all routes under /wp-json/post-runtime/v1/connector/*.
 * Delegates to existing plugin subsystems:
 *
 *   - PCPTPages_CPT_Registry      for CPT CRUD
 *   - PCPTPages_Grouping_Registry for grouping CRUD
 *   - PCPTPages_Post_Data         for per-post grouping read/write
 *   - PCPTPages_Validator         for shape validation (called transitively)
 *   - PCPTPages_Icon_Library      for the icon catalogue
 *   - PCPTPages_Renderer          for the preview endpoint
 *
 * Owns:
 *   - Route registration (self::register_routes)
 *   - Request parsing + response shaping
 *   - Argument validation via register_rest_route's $args
 *
 * Does NOT own:
 *   - Permission decisions   — see PCPTPages_Connector_Auth
 *   - Storage                — see PCPTPages_CPT_Registry, PCPTPages_Post_Data
 *   - Validation rules       — see PCPTPages_Validator
 *
 * Contract documented in docs/CONNECTOR_SPEC.md.
 *
 * @package PostRuntimeEngine
 *
 * phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key
 *
 * Justification: Connector endpoints legitimately query posts by
 * registered meta keys for the meta_match source mode lookups. These
 * are documented patterns flagged as performance-advisory.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connector REST controller.
 */
class PCPTPages_Connector_API {

	/**
	 * Hook registration. Wires register_routes() to rest_api_init and
	 * rest_post_dispatch (for the _site envelope injector).
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		// Use rest_request_after_callbacks (not rest_post_dispatch) so the
		// envelope is also applied when handlers are invoked through
		// rest_do_request() — the path our smoke tests + any internal
		// PHP-side caller takes. rest_post_dispatch only fires for genuine
		// HTTP-served REST requests via WP_REST_Server::serve_request, so
		// the previous wiring missed half the dispatch surface. Late
		// priority (20) keeps us out of the way of other plugins' shapers.
		add_filter( 'rest_request_after_callbacks', array( $this, 'inject_site_envelope' ), 20, 3 );
	}

	/**
	 * Inject a `_site` envelope into every connector response.
	 *
	 * Cross-site safety hardening: when the same MCP server config is
	 * pointed at a different site (or a stale Application Password is
	 * still valid against an old target), tools that don't return site
	 * identity in their response leave the agent unable to verify which
	 * site a call hit. Without that signal, an agent can run destructive
	 * operations against the wrong site without realizing.
	 *
	 * The envelope contains:
	 *   - site_url:  home_url() so the agent can verify the target host
	 *   - site_name: business_identity if Promptless is providing it,
	 *                else WP blogname as a fallback
	 *   - env_hint:  heuristic classification of the host as production
	 *                vs staging — best-effort, filterable via
	 *                pcptpages_site_envelope_env_hint
	 *
	 * Scoped strictly to PRE namespace routes; other plugins' REST
	 * responses are untouched.
	 *
	 * Hooked to rest_request_after_callbacks (not rest_post_dispatch) so
	 * we cover both serve_request HTTP dispatch AND internal dispatch via
	 * rest_do_request() — the latter being how smoke tests and PHP-side
	 * integrations call the connector.
	 *
	 * @param WP_REST_Response|WP_Error $response Built response or error.
	 * @param array                     $handler  Route-handler metadata.
	 * @param WP_REST_Request           $request  Inbound request.
	 * @return mixed The response (possibly with _site added).
	 */
	public function inject_site_envelope( $response, $handler, $request ) {
		// Bail on anything that isn't a JSON-shaped REST response.
		if ( ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		// Only act on our namespace's routes. PCPTPages_REST_NAMESPACE is
		// 'post-runtime/v1'; routes look like '/post-runtime/v1/connector/...'.
		$route = $request->get_route();
		if ( strpos( $route, '/' . PCPTPages_REST_NAMESPACE . '/' ) !== 0 ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			// Non-array bodies (rare, mostly error envelopes pre-WP_Error)
			// stay untouched — wrapping them would change the contract.
			return $response;
		}

		// Don't clobber if a handler already set _site explicitly.
		if ( ! isset( $data['_site'] ) ) {
			$data['_site'] = self::get_site_envelope();
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * Build the _site envelope. Cached per-request via static — site
	 * identity doesn't change within a single REST round-trip.
	 *
	 * @return array {site_url, site_name, env_hint}
	 */
	private static function get_site_envelope() {
		static $cached = null;
		if ( $cached !== null ) {
			return $cached;
		}

		// Prefer Promptless's business_identity name when available, since
		// that's the brand the operator manages. Fall back to WP blogname,
		// which can be the leftover-from-a-template default ("Health
		// Professions" in the live pressure test) and is rarely brand-correct.
		$business     = get_option( 'aisb_business_identity', array() );
		$business_name = '';
		if ( is_array( $business ) && ! empty( $business['business_name'] ) ) {
			$business_name = (string) $business['business_name'];
		}

		$site_name = $business_name !== '' ? $business_name : get_bloginfo( 'name' );

		$cached = array(
			'site_url'  => home_url(),
			'site_name' => $site_name,
			'env_hint'  => self::detect_env_hint(),
		);

		/**
		 * Filter the connector-response site envelope.
		 *
		 * Sites with non-standard staging/production patterns can override
		 * env_hint here, or add custom keys for their own conventions.
		 *
		 * @param array $envelope {site_url, site_name, env_hint, ...}
		 */
		$cached = apply_filters( 'pcptpages_site_envelope', $cached );

		return $cached;
	}

	/**
	 * Heuristic classification of the host as production vs staging.
	 *
	 * Best-effort — a sufficiently weird hostname will be misclassified.
	 * Sites that need tighter classification can override via the
	 * `pcptpages_site_envelope` filter.
	 *
	 * @return string 'production' | 'staging' | 'development'
	 */
	private static function detect_env_hint() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $host ) {
			return 'production';
		}

		$host = strtolower( $host );

		// Local-development indicators.
		if ( $host === 'localhost' ||
			strpos( $host, '127.0.0.1' ) === 0 ||
			substr( $host, -6 ) === '.local' ||
			substr( $host, -5 ) === '.test' ||
			substr( $host, -7 ) === '.docker'
		) {
			return 'development';
		}

		// Staging-host indicators. Subdomain prefixes are checked at
		// label boundaries (so 'staging.example.com' matches but
		// 'thestaging.com' doesn't). Common managed-host staging domains
		// are matched anywhere in the hostname since they sit at the
		// effective TLD boundary.
		$prefix_indicators = array( 'staging.', 'stage.', 'dev.', 'preview.', 'test.' );
		foreach ( $prefix_indicators as $prefix ) {
			if ( strpos( $host, $prefix ) === 0 ) {
				return 'staging';
			}
		}
		$contains_indicators = array(
			'.mybluehost.me',  // Bluehost staging
			'.wpengine.com',   // WP Engine staging convention
			'.kinsta.cloud',   // Kinsta staging
			'.ngrok.',         // ngrok tunnels
			'.flywheelsites.com',  // Flywheel staging
		);
		foreach ( $contains_indicators as $needle ) {
			if ( strpos( $host, $needle ) !== false ) {
				return 'staging';
			}
		}

		return 'production';
	}

	/**
	 * Register all connector routes.
	 *
	 * Every route delegates to PCPTPages_Connector_Auth::build_callback() for
	 * the auth + rate-limit stack. Per-post routes use
	 * build_per_post_callback() for object-level capability checks.
	 */
	public function register_routes() {
		$ns   = PCPTPages_REST_NAMESPACE;
		$base = PCPTPages_REST_BASE;

		// ----- Preflight + introspection (5 routes) -----

		register_rest_route( $ns, "/{$base}/preflight", array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_preflight' ),
			'permission_callback' => PCPTPages_Connector_Auth::build_callback( 'preflight' ),
		) );

		register_rest_route( $ns, "/{$base}/icons", array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_list_icons' ),
			'permission_callback' => PCPTPages_Connector_Auth::build_callback( 'list_icons' ),
		) );

		register_rest_route( $ns, "/{$base}/variants", array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_list_variants' ),
			'permission_callback' => PCPTPages_Connector_Auth::build_callback( 'list_variants' ),
		) );

		register_rest_route( $ns, "/{$base}/positions", array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_list_positions' ),
			'permission_callback' => PCPTPages_Connector_Auth::build_callback( 'list_positions' ),
		) );

		// ----- CPTs (5 routes) -----

		register_rest_route( $ns, "/{$base}/cpts", array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list_cpts' ),
				'permission_callback' => PCPTPages_Connector_Auth::build_callback( 'list_cpts' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_register_cpt' ),
				'permission_callback' => PCPTPages_Connector_Auth::build_callback( 'register_cpt' ),
			),
		) );

		register_rest_route( $ns, "/{$base}/cpts/(?P<slug>[a-z0-9_]+)", array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_cpt' ),
				'permission_callback' => PCPTPages_Connector_Auth::build_callback( 'get_cpt' ),
				'args'                => $this->cpt_slug_arg(),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'handle_update_cpt' ),
				'permission_callback' => PCPTPages_Connector_Auth::build_callback( 'update_cpt' ),
				'args'                => $this->cpt_slug_arg(),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'handle_delete_cpt' ),
				'permission_callback' => PCPTPages_Connector_Auth::build_callback( 'delete_cpt' ),
				'args'                => $this->cpt_slug_arg(),
			),
		) );

		// ----- Groupings (5 routes) -----

		register_rest_route( $ns, "/{$base}/cpts/(?P<slug>[a-z0-9_]+)/groupings", array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list_groupings' ),
				'permission_callback' => PCPTPages_Connector_Auth::build_callback( 'list_groupings' ),
				'args'                => $this->cpt_slug_arg(),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_define_grouping' ),
				'permission_callback' => PCPTPages_Connector_Auth::build_callback( 'define_grouping' ),
				'args'                => $this->cpt_slug_arg(),
			),
		) );

		register_rest_route( $ns, "/{$base}/cpts/(?P<slug>[a-z0-9_]+)/groupings/(?P<key>[a-z0-9_]+)", array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_grouping' ),
				'permission_callback' => PCPTPages_Connector_Auth::build_callback( 'get_grouping' ),
				'args'                => $this->cpt_slug_and_key_args(),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'handle_update_grouping' ),
				'permission_callback' => PCPTPages_Connector_Auth::build_callback( 'update_grouping' ),
				'args'                => $this->cpt_slug_and_key_args(),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'handle_delete_grouping' ),
				'permission_callback' => PCPTPages_Connector_Auth::build_callback( 'delete_grouping' ),
				'args'                => $this->cpt_slug_and_key_args(),
			),
		) );

		// ----- Post groupings (2 routes — per-post auth) -----

		register_rest_route( $ns, "/{$base}/posts/(?P<id>\d+)/groupings", array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_post_groupings' ),
				'permission_callback' => PCPTPages_Connector_Auth::build_per_post_callback( 'get_post_groupings' ),
				'args'                => $this->post_id_arg(),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'handle_set_post_groupings' ),
				'permission_callback' => PCPTPages_Connector_Auth::build_per_post_callback( 'set_post_groupings' ),
				'args'                => $this->post_id_arg(),
			),
		) );

		// ----- Post fields (v1.1) — 6 routes for definitions + 4 for values/visibility -----

		// Collection: list / create field definitions for a CPT.
		register_rest_route( $ns, "/{$base}/cpts/(?P<slug>[a-z0-9_]+)/post-fields", array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list_post_fields' ),
				'permission_callback' => PCPTPages_Connector_Auth::build_callback( 'list_post_fields' ),
				'args'                => $this->cpt_slug_arg(),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_define_post_field' ),
				'permission_callback' => PCPTPages_Connector_Auth::build_callback( 'define_post_field' ),
				'args'                => $this->cpt_slug_arg(),
			),
		) );

		// Bulk reorder. Registered BEFORE the {key} route so the literal
		// "reorder" path segment doesn't get captured as a field key.
		register_rest_route( $ns, "/{$base}/cpts/(?P<slug>[a-z0-9_]+)/post-fields/reorder", array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_reorder_post_fields' ),
			'permission_callback' => PCPTPages_Connector_Auth::build_callback( 'reorder_post_fields' ),
			'args'                => $this->cpt_slug_arg(),
		) );

		// Single field definition: read / update / delete.
		register_rest_route( $ns, "/{$base}/cpts/(?P<slug>[a-z0-9_]+)/post-fields/(?P<key>[a-z0-9_]+)", array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_post_field' ),
				'permission_callback' => PCPTPages_Connector_Auth::build_callback( 'get_post_field' ),
				'args'                => $this->cpt_slug_and_key_args(),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'handle_update_post_field' ),
				'permission_callback' => PCPTPages_Connector_Auth::build_callback( 'update_post_field' ),
				'args'                => $this->cpt_slug_and_key_args(),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'handle_delete_post_field' ),
				'permission_callback' => PCPTPages_Connector_Auth::build_callback( 'delete_post_field' ),
				'args'                => $this->cpt_slug_and_key_args(),
			),
		) );

		// Per-post field values: read all, bulk write.
		register_rest_route( $ns, "/{$base}/posts/(?P<id>\d+)/field-values", array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_post_field_values' ),
				'permission_callback' => PCPTPages_Connector_Auth::build_per_post_callback( 'get_post_field_values' ),
				'args'                => $this->post_id_arg(),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'handle_set_post_field_values' ),
				'permission_callback' => PCPTPages_Connector_Auth::build_per_post_callback( 'set_post_field_values' ),
				'args'                => $this->post_id_arg(),
			),
		) );

		// Per-post visibility overrides: read + write.
		register_rest_route( $ns, "/{$base}/posts/(?P<id>\d+)/field-visibility", array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_post_field_visibility' ),
				'permission_callback' => PCPTPages_Connector_Auth::build_per_post_callback( 'get_post_field_visibility' ),
				'args'                => $this->post_id_arg(),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'handle_set_post_field_visibility' ),
				'permission_callback' => PCPTPages_Connector_Auth::build_per_post_callback( 'set_post_field_visibility' ),
				'args'                => $this->post_id_arg(),
			),
		) );

		// ----- Post create + preview -----

		register_rest_route( $ns, "/{$base}/posts", array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_create_post' ),
			'permission_callback' => PCPTPages_Connector_Auth::build_callback( 'create_post' ),
		) );

		register_rest_route( $ns, "/{$base}/posts/(?P<id>\d+)", array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( $this, 'handle_update_post' ),
			'permission_callback' => PCPTPages_Connector_Auth::build_per_post_callback( 'update_post' ),
			'args'                => $this->post_id_arg(),
		) );

		register_rest_route( $ns, "/{$base}/posts/(?P<id>\d+)/preview", array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_preview_post' ),
			'permission_callback' => PCPTPages_Connector_Auth::build_per_post_callback( 'preview_post' ),
			'args'                => $this->post_id_arg(),
		) );
	}

	// ========================================================================
	// Handlers — preflight + introspection
	// ========================================================================

	/**
	 * GET /preflight — site readiness check.
	 */
	public function handle_preflight( WP_REST_Request $request ) {
		$plugin = pcptpages();

		$registered_cpts = array();
		if ( $plugin->cpts ) {
			foreach ( $plugin->cpts->get_all() as $slug => $_def ) {
				$registered_cpts[] = $slug;
			}
		}

		$user = wp_get_current_user();

		return rest_ensure_response( array(
			'plugin_version'   => PCPTPages_VERSION,
			'data_version'     => PCPTPages_DATA_VERSION,
			'wp_version'       => get_bloginfo( 'version' ),
			'rest_namespace'   => PCPTPages_REST_NAMESPACE,
			'rest_base'        => '/wp-json/' . PCPTPages_REST_NAMESPACE . '/' . PCPTPages_REST_BASE,
			'user'             => array(
				'id'                     => (int) $user->ID,
				'login'                  => $user->user_login,
				'can_manage_cpts'        => current_user_can( PCPTPages_Capabilities::MANAGE_CAP ),
				'can_manage_groupings'   => current_user_can( PCPTPages_Capabilities::MANAGE_CAP ),
				'can_manage_post_fields' => current_user_can( PCPTPages_Capabilities::MANAGE_CAP ),
			),
			'registered_cpts'  => $registered_cpts,
			// Promptless WP defines AISB_MODERN_VERSION; this flag tells the
			// agent whether the design tokens will inherit. When false, the
			// agent may want to warn the operator that the documented
			// fallbacks will be used instead.
			'promptless_active' => defined( 'AISB_MODERN_VERSION' ),
			'promptless_version' => defined( 'AISB_MODERN_VERSION' ) ? AISB_MODERN_VERSION : null,
			// Authoring rulebook — distilled from past authoring mistakes.
			// AI agents and human integrators should read these before the
			// first content-creation call. The connector itself enforces
			// many of these via validation, but several ('post_content_is_gutenberg_blocks',
			// 'cross_cpt_item_icons', 'postgrid_grid_balance') describe AI-side
			// patterns that the connector cannot detect at write time.
			'critical_rules'   => self::get_critical_rules(),
			// Per-variant grouping item shape. Field names that aren't in
			// this list will be silently ignored on write — the canonical
			// shape is enforced by PCPTPages_Validator::validate_grouping_item.
			'field_name_hints' => self::get_field_name_hints(),
			// Allowed source modes + per-mode required parameter set. Surfaced
			// here so AI consumers can discover the meta_match mode added in
			// data-version 0.3.0 without having to read the validator source.
			'source_modes'     => self::get_source_modes_descriptor(),
			// v1.1: closed enums for the second field type (post fields).
			// Each enum is documented with human labels so AI agents can
			// pick values without trial-and-error against the validator.
			'post_field_enums' => self::get_post_field_enums(),
		) );
	}

	/**
	 * Allowed source modes and the parameter shape each mode requires.
	 *
	 * Mirrors PCPTPages_Validator::SOURCE_MODES + the per-mode validation rules in
	 * PCPTPages_Validator::validate_source_value(). Each entry documents the form
	 * (string vs. object), required + optional params, and a one-line use
	 * case so AI consumers can choose the right mode without trial and error.
	 *
	 * @return array<string,array>
	 */
	private static function get_source_modes_descriptor() {
		return array(
			'manual'         => array(
				'form'        => 'string',
				'value'       => 'manual',
				'description' => 'Items entered explicitly per post via the admin meta box or set_post_groupings.',
			),
			'child_posts'    => array(
				'form'        => 'string',
				'value'       => 'child_posts',
				'description' => 'Auto-populates from posts whose post_parent equals the current post (same CPT). The CPT must have hierarchical = true.',
			),
			'taxonomy_match' => array(
				'form'        => 'object',
				'shape'       => array(
					'type'                  => 'taxonomy_match',
					'taxonomy'              => '<taxonomy slug, required>',
					'limit'                 => '<int 1-100, optional, defaults to 6>',
					'exclude_self'          => '<bool, optional, defaults to true>',
				),
				'description' => 'Auto-populates from posts in the same CPT that share at least one term with the current post in the named taxonomy. Use for "related X by topic / category / tag" patterns.',
			),
			'meta_match'     => array(
				'form'        => 'object',
				'shape'       => array(
					'type'                  => 'meta_match',
					'meta_key'              => '<raw post-meta key; EXACTLY ONE of meta_key/field_key required; max 64 chars, may start with single underscore>',
					'field_key'             => '<PRE post-field key (resolved to _pcptpages_field_{key} storage); EXACTLY ONE of meta_key/field_key required — prefer this form, post-field values are the meta this connector writes>',
					'post_type'             => '<CPT slug to query, optional, defaults to the current post\'s CPT — set it to pull from a DIFFERENT CPT (cross-CPT reverse lookup)>',
					'match_against'         => '<one of same_key|current_id|current_slug|current_title, optional, defaults to same_key — what the TARGET posts\' meta value is compared to>',
					'limit'                 => '<int 1-100, optional, defaults to 6>',
					'exclude_self'          => '<bool, optional, defaults to true>',
				),
				'description' => 'Auto-populates from posts whose meta value equals a value derived from the current post. Two shapes: (1) MIRROR (match_against=same_key, the default): posts in the same CPT whose value for the key equals the current post\'s own value for the same key — "more from this entity" patterns, e.g. _agent_id (real estate), _employer_id (jobs), _business_id (multi-location). (2) REVERSE LOOKUP (match_against=current_id/current_slug/current_title, usually with post_type): posts in ANOTHER CPT whose field names the current post — the parent-pulls-children shape, e.g. an Agent page pulling its Listings with {post_type:"listing", field_key:"agent", match_against:"current_title"}, or a Neighborhood page pulling area Listings with {post_type:"listing", field_key:"neighborhood", match_against:"current_title"}. Use current_title when the child\'s post-field stores the human-readable name shown on cards; use current_id with a hidden post-field when you want a rename-proof link. Fails soft to an empty item list when the match value is empty or the post_type is not registered.',
			),
		);
	}

	/**
	 * Critical authoring rules surfaced via preflight.
	 *
	 * Mirrors Promptless WP's `wordpress_preflight.critical_rules` shape so
	 * agents that already understand the Promptless contract recognize the
	 * pattern. Each rule has a stable key + a clear instruction. Keys are
	 * referenced by smoke tests so the suite stays in sync with the rulebook.
	 *
	 * Rules below are distilled from real authoring failures; do not remove
	 * a rule without first removing the corresponding smoke-test assertion
	 * and updating CHANGELOG.md.
	 *
	 * @return array<string,string>
	 */
	private static function get_critical_rules() {
		return array(
			'post_content_is_gutenberg_blocks' => 'post_content should be sent as Gutenberg block-format markup so the WP editor opens with discrete editable blocks instead of a single Classic / Freeform wrapper. Each block is wrapped in HTML comment delimiters: <!-- wp:heading --><h2 class="wp-block-heading">Title</h2><!-- /wp:heading --> for headings, <!-- wp:paragraph --><p>Body text.</p><!-- /wp:paragraph --> for paragraphs, <!-- wp:list --><ul class="wp-block-list"><li>...</li></ul><!-- /wp:list --> for lists (add {"ordered":true} for ordered lists: <!-- wp:list {"ordered":true} -->), <!-- wp:quote --><blockquote class="wp-block-quote">...</blockquote><!-- /wp:quote --> for quotes, <!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator --> for horizontal rules. Heading levels other than h2 carry a level attribute: <!-- wp:heading {"level":3} --><h3 class="wp-block-heading">…</h3><!-- /wp:heading -->. The connector ships a defense-in-depth converter that wraps raw HTML in block delimiters automatically and emits a "post_content_block_conversion_applied" warning when it fires — treat that as a safety net, not a feature, because the converter has to guess block-level attributes (heading levels, list types) that you control precisely when you send blocks directly. Do NOT wrap content in <![CDATA[...]]> — that XML idiom belongs to SOAP and importer XML; in JSON-typed connector parameters it is stored verbatim and leaks into the rendered body. The connector also strips a leading <![CDATA[ and trailing ]]> as a defensive net and adds a "post_content_cdata_stripped" warning when it fires.',
			'groupings_creation_pattern'   => 'Two ways to populate groupings on a post: (1) pass `groupings` inline with create_post for atomic creation in a single call; (2) call set_post_groupings after a bare create_post. Both work. set_post_groupings fully replaces all groupings — to update one grouping without touching others, use the read-modify-write pattern (get_post_groupings → modify → set_post_groupings). update_post also accepts a groupings field for atomic edits. SOURCE INHERITANCE: an entry that omits `source` and carries no items inherits the definition\'s default_source — so activating an auto-sourced grouping (taxonomy_match / meta_match / child_posts) on a post is just {grouping_key:"<key>"} with nothing else. An entry that omits `source` but includes items is treated as manual. Passing an explicit `source` object always wins (per-post override).',
			'taxonomy_term_assignment'     => 'To assign taxonomy terms (categories / tags / any taxonomy registered for the CPT) pass a `taxonomies` map to create_post or update_post: {"category": ["Downtown", "Waterfront District"]}. Terms may be names, slugs, or IDs; names that don\'t exist yet are created automatically. On update_post it is a REPLACE per taxonomy supplied (omitted taxonomies untouched; empty list clears). PREREQUISITE: the taxonomy must already be attached to the CPT via the CPT\'s `taxonomies` list at register_cpt time (e.g. taxonomies:["category"]). This connector attaches EXISTING taxonomies (built-in category/post_tag, or any custom taxonomy another plugin/theme registered) — it does not itself register brand-new custom taxonomies. Assigning terms is what powers taxonomy-based archive facets (a PostGrid filter auto-generates a checkbox group per public taxonomy attached to the CPT) and the taxonomy_match grouping source mode. Term problems are non-fatal and surface in the response `warnings`.',
			'cross_cpt_item_icons'         => 'When a grouping item links to another CPT via link_post_id, set per-item icon_id explicitly to reflect what the item IS (e.g. icon_id:"user" on a Lead Architect featured-card item that links to an architect post). The renderer\'s default_icon fallback is link-aware: when link_post_id is set, it tries the LINKED post\'s CPT default_icon first, then falls back to the host post\'s default_icon. Setting icon_id explicitly bypasses both fallbacks.',
			'compact_grid_strips_image'    => 'compact-grid and horizontal-row are icon-only variants by design. Any image_id on an item in these variants is dropped at render time — set icon_id on each item, or rely on the linked-CPT / host-CPT default_icon fallback chain. card-grid and featured-card variants accept either icon_id OR image_id (mutually exclusive; validator rejects both being set on the same item).',
			'link_post_id_canonical'       => 'For internal links to same-site posts, prefer link_post_id over a literal URL. The renderer resolves it via get_permalink() at render time, which makes stored data domain-portable across staging → production migrations and after permalink-structure changes. The literal `link` field is preserved as a fallback when the referenced post has been trashed/deleted.',
			'postgrid_grid_balance'        => 'Postgrid sections render posts_per_page items in a grid_columns grid. Promptless\'s design optimizer (LayoutOptimizer::apply_postgrid_grid_balance, v1.4+) auto-balances these for you when only one is explicit: send `posts_per_page: 4` and the optimizer picks `grid_columns: "4"`; send `grid_columns: "4"` and the optimizer picks `posts_per_page: 8`. When BOTH are explicit, defer-to-explicit applies (your asymmetric pair like 5+3 is honored as-is). When NEITHER is explicit, the renderer\'s defaults align (6+3). Awkward post counts are handled by minimizing orphan slots: 5 → 3 cols (1 orphan), 7 → 4 cols (1 orphan). Bypass entirely by passing design_options.apply_design_strategy:false.',
			'featured_card_max_one'        => 'featured-card variant has max_items=1 enforced by the validator. featured-card is for ONE prominent item per grouping (a Lead Architect, a Schedule a Tour CTA, a Currently Featured project). For multi-item collections of cards-with-images use card-grid.',
			'icon_ids_must_be_registered'  => 'CPT default_icon and grouping item icon_id accept TWO formats, both verified by PCPTPages_Icon_Library::is_valid_id(): (1) A legacy curated ID from the built-in 53-icon library — e.g. `home`, `shield`, `briefcase` — renders as an inline SVG (zero network requests, fastest paint). Call GET /icons (or postruntime_list_icons via MCP) to discover them. (2) Any Iconify code in `collection:name` form — e.g. `mdi:home`, `logos:wordpress`, `material-symbols:business-outline`, `heroicons:user-circle`, `fa6-solid:tooth` — renders as an `<iconify-icon>` web component, fetching SVG from api.iconify.design at paint time. 200,000+ icons across 100+ sets; browse at https://icon-sets.iconify.design/. Prefer Iconify codes for parity with Promptless WP (which already uses Iconify everywhere) and for industry-specific glyphs the curated 53 do not cover. Both formats pass through the same validator and renderer; an invalid format (anything that does not match either) returns 422 pcptpages_invalid_default_icon or pcptpages_unknown_icon at write time. The /icons response includes an `iconify` block with the format pattern + a legacy → Iconify map for cross-format awareness.',
			'choosing_a_source_mode'       => 'Four source modes are available — see source_modes in this preflight for the full descriptor. Quick chooser: (1) Use manual when each post curates its own items (e.g. a Listing\'s Features grouping where the agent picks specific selling points). (2) Use child_posts when the relationship is hierarchical and natural in WordPress (a Course post with Lesson child posts). (3) Use taxonomy_match when the relationship is "shares a category / tag / region" (related Articles in same topic). (4) Use meta_match when the relationship is a stored value. It has two shapes: the MIRROR shape (default) finds same-CPT siblings sharing the current post\'s value — "more from this agent" with meta_key=_agent_id; the REVERSE-LOOKUP shape (post_type + match_against=current_title/current_id/current_slug + field_key) pulls posts from a DIFFERENT CPT whose post-field names the current post — an Agent page pulling its Listings via {post_type:"listing", field_key:"agent", match_against:"current_title"}. Prefer field_key over meta_key: post-field values are the meta this connector can write (set_post_field_values), so field_key closes the loop with no raw-meta write path needed. meta_match short-circuits to empty when the derived match value is empty, so it is safe to define before every post has its fields populated.',
			// v1.1 — post field authoring rules.
			'post_fields_vs_groupings'     => 'Two field types coexist on every CPT: groupings (v1.0, repeatable {icon, heading, text, link} items) and post fields (v1.1, scalar values with typed display). Use groupings for collections that need their own items per post — features lists, FAQ items, course modules. Use post fields for single per-post values that decorate the hero and cards — price, status, location, beds/baths, rating, posted date. Two extreme tells: if every post has a different LIST of things, it is a grouping; if every post has the SAME named values (each one filled differently), they are post fields.',
			'post_field_positions'         => 'Post fields render in a closed set of 5 positions per context (card AND single-post hero, symmetric): image_overlay (badge on featured image, top-left), headline (large prominent value above the title — price, salary, event date), subtitle (small line under the title — location, company, cuisine), meta_strip (inline horizontal list of small items — beds/baths/sqft, prep/cook/rating, posted/duration/level), footer_meta (smallest line at the bottom — listed-date, attendees). A field can use ANY of the 5 positions in EITHER context, plus a sixth `hidden` value to register a field that should not render in that context. Multiple fields can occupy the same position — they render in field-definition order (controlled by /post-fields/reorder).',
			'post_field_display_types'     => 'Closed enum of 9 display types — see post_field_enums.display_types in this preflight for the full list with examples. Quick chooser: currency for monetary values (locale-formatted), number_with_label for "N <unit>" pairs (1,800 sqft, 3 BR), badge for single pills with color intent (For sale, Featured), meta_pair for icon+value cells in meta_strip (🛏 3), date for dates (absolute or relative), text for plain string display, rating for "★★★★☆ 4.8 (1,243)" composites, progress for funding-style "$320K of $500K" bars, multi_badge for comma-separated pill lists (Vegan, GF, Quick). Each display type has its own per-value validation in the validator — see post_field_value_shape below.',
			'post_field_value_shape'       => 'Per-display-type value rules: (currency, number_with_label, rating-value, progress-value) numeric; (date) YYYY-MM-DD or any strtotime-parseable string; (text, meta_pair) string up to 500 chars; (badge) string or one of the predefined options[].key when defined; (multi_badge) comma-separated string OR array of segments; (rating) array { value: 0-max, count: int|null }; (progress) array { value: 0+, goal: number|null }. Empty / null clears the field on set_post_field_values. Composite types (rating, progress) accept either the array shape OR a bare scalar for the primary value (secondary defaults to null).',
			'post_field_count_cap'         => 'Each CPT supports up to 12 post fields (HARD_FIELD_COUNT_LIMIT, filterable via pcptpages_max_post_fields_per_cpt). Cards display best with 8 or fewer; beyond that meta_strip starts wrapping on mobile. The connector rejects field #13 with pcptpages_max_field_count_exceeded (HTTP 422). Plan field allocation up front: 1-2 for headline (price/date), 1 for image_overlay (status), 0-1 for subtitle (location), 2-4 for meta_strip, 0-2 for footer_meta.',
			'post_field_visibility_model'  => 'Two layers of visibility control: (1) CPT-level position attribute (card_position, single_position) — set to `hidden` to drop the field from one context entirely; (2) per-post overrides via PUT /posts/{id}/field-visibility — hide a specific field on a specific post in a specific context while leaving the CPT-level config intact. The intent is "homeowner doesn\'t want their price shown on the card listing" without redefining the entire field. Positions cannot be overridden per post; only visibility.',
				'hero_contrast_band'           => 'HERO CONTRAST (Phase A, docs/HERO_CONTRAST_DESIGN.md). Two CPT-level options give single-post pages a contrasting hero band: hero_theme (inherit|light|dark, default inherit) forces the hero\'s light/dark mode independent of the page — set "dark" on a light site for the standard high-contrast detail-page band (title, featured image, and all hero-positioned post fields render on a dark surface with WCAG-corrected token colors). hero_width (contained|full, default contained) — "full" bleeds the band\'s background to the viewport edges while hero content stays aligned with the page grid; most striking combined with hero_theme:"dark". Defaults reproduce pre-Phase-A rendering exactly, so existing CPTs are unaffected until you opt in. These are CPT-level (all posts of the type share the treatment); per-post overrides are a planned Phase C. OVERLAY (Phase B): hero_layout also accepts "overlay" — the featured image fills the hero band and the title/price/badges/meta render ON TOP of it over a fixed darkening gradient (guaranteed WCAG contrast for any photo; hero_theme is ignored while an image is present). The premium treatment for image-rich CPTs: real estate listings, events, venues, portfolios. Posts WITHOUT a featured image automatically fall back to the stacked treatment honoring hero_theme — never an empty band, so overlay is safe to enable before every post has imagery. Pair with hero_width:"full" for the full-bleed cinematic version. hero_overlay_focus (top|center|bottom, default center) controls which part of the photo survives the band\'s crop — "top" keeps rooflines/skies, "bottom" keeps foregrounds.',
			'events_archive_setup'         => 'EVENTS (v1.2). To turn any CPT into an events vertical (branded event pages + filterable upcoming/past archive + Schema.org Event markup), tag post fields with a semantic_role (see post_field_enums.semantic_roles). Steps: (1) define a date field with semantic_role:"event_start" (REQUIRED — makes the CPT event-shaped) and another with "event_end" (recommended; status is END-date anchored so an in-progress multi-day event still counts as upcoming); set all_day:true for all-day/multi-day dates. (2) Optionally add a badge with semantic_role:"event_status" (option keys scheduled/cancelled/postponed/moved_online), a text "event_location", a currency "event_offers", and a badge "event_attendance_mode" (in_person/online/mixed). (3) Populate values per post via set_post_field_values — date values as "YYYY-MM-DD HH:MM" for timed events or "YYYY-MM-DD" for all-day; the plugin auto-writes the sortable companions used by the filter. (4) Build the archive on a Promptless WP page (via the promptless-wordpress connector): add a PostGrid section over the CPT with content.event_status:"upcoming" (or "happening"/"past") and content.event_sort:"soonest" (or "latest"/"none"). Each event single page automatically emits Event JSON-LD. The role↔display_type pairing is enforced (e.g. event_start on a non-date field returns pcptpages_role_display_type_mismatch); each role is unique per CPT.',
			'filterable_archive_setup'     => 'FILTERS (v1.2). To give any CPT a visitor-facing filterable + sortable archive (price range sliders, category checkboxes, date toggles, text search — all auto-generated from the field schema, no per-filter config), do TWO things. (1) On the PRE side, mark the relevant post fields filterable and/or sortable when you define_post_field / update_post_field: set filterable:true to expose a filter control (the widget is chosen automatically from display_type — currency/number/progress → range slider, rating → stepper, badge → single-select, multi_badge → checkboxes, date → upcoming/past toggle for event-role dates else a date range, text → search box; public taxonomies on the CPT auto-become checkbox facets) and sortable:true to offer the field as a sort option. Optionally set filter_widget to override the auto choice (must be compatible with display_type — see post_field_enums.filter_widgets). meta_pair cannot be filterable/sortable, and a filterable/sortable field key may not end with a reserved suffix (_min,_max,_when,_after,_before,_q) or equal a reserved param (sort,paged). (2) On the Promptless WP side (promptless-wordpress connector), add a PostGrid section over the CPT and set content.enable_filters:true, content.filter_layout:"top_bar" (or "sidebar"), and optionally content.default_sort:"{field}_asc" / "{field}_desc" (the sort applied until a visitor picks one). The filter UI then renders itself from the filterable fields — there is nothing else to configure. The same field schema powers the single-post hero, the cards, and the filter controls. Filtered URLs are server-rendered (work with JS off, SEO-clean: filtered combinations are noindexed and canonicalize to the base archive) and JS-enhanced for in-place updates, chips, and a mobile drawer. This is the generalized form of events_archive_setup — the event date toggle is just one filterable date field. URL PARAM NAMES: filter params are derived from the field key (selects/checkbox groups use the bare key; ranges use {key}_min/{key}_max, search {key}_q, dates {key}_when/{key}_after/{key}_before) — BUT any name that collides with a WordPress public query variable (a registered post type slug like `listing`/`agent`/`neighborhood`, a taxonomy query var, or a core var like name/author/s/order) is automatically prefixed with `f_` (e.g. `?f_neighborhood=downtown`), because WP core would otherwise claim the parameter and redirect the archive away before the filter runs. When CONSTRUCTING filtered URLs by hand, don\'t guess: the rendered filter form\'s input names (or GET /editor/post-type-filters/{slug} → descriptors[].params) are the authoritative param names.',
		);
	}

	/**
	 * Per-variant grouping item shape. Field names that aren't in this map
	 * are silently dropped on write by PCPTPages_Validator. The list helps AI
	 * agents avoid invented field names like "title" (use heading) or
	 * "subtitle" (use supporting_text). icon_id and image_id are mutually
	 * exclusive — the validator rejects both being set on the same item.
	 *
	 * @return array<string,array>
	 */
	private static function get_field_name_hints() {
		return array(
			'groupings_item_shape' => array(
				'compact-grid'    => array( 'heading', 'icon_id', 'link', 'link_post_id', 'link_text', 'link_target' ),
				'horizontal-row'  => array( 'heading', 'icon_id' ),
				'card-grid'       => array( 'heading', 'supporting_text', 'icon_id', 'image_id', 'link', 'link_post_id', 'link_text', 'link_target' ),
				'featured-card'   => array( 'heading', 'supporting_text', 'icon_id', 'image_id', 'link', 'link_post_id', 'link_text', 'link_target' ),
			),
			'cpt_definition'       => array( 'slug', 'label_singular', 'label_plural', 'supports', 'public', 'has_archive', 'show_in_rest', 'show_in_menu', 'menu_position', 'menu_icon', 'taxonomies', 'capability_type', 'description', 'rewrite', 'hero_layout', 'hero_image_position', 'hero_image_aspect', 'hero_overlay_focus', 'hero_theme', 'hero_width', 'default_icon', 'archive_show_post_date', 'archive_show_post_author' ),
			'grouping_definition'  => array( 'key', 'label', 'description', 'default_variant', 'default_position', 'default_source', 'max_items', 'heading_required', 'supporting_text_required', 'link_required', 'icon_or_image_required' ),
			'post_field_definition' => array( 'key', 'label', 'description', 'display_type', 'card_position', 'single_position', 'color_intent', 'icon', 'options', 'required', 'date_format', 'date_format_string', 'currency_code', 'value_suffix', 'max', 'unit_label', 'number_grouping', 'semantic_role', 'all_day', 'event_timezone', 'filterable', 'sortable', 'filter_widget' ),
			'notes'                => 'icon_id and image_id are mutually exclusive on a single item. featured-card has max_items=1 enforced. Compact-grid and horizontal-row are icon-only — image_id is dropped at render time. link_post_id is preferred over literal `link` URLs for internal references; both can be set (link is the fallback when link_post_id resolution fails). Post field definition fields not in post_field_definition above are silently dropped on write by PCPTPages_Validator; conditional fields (color_intent, options for badge; icon for meta_pair; date_format / date_format_string for date; currency_code for currency; max + unit_label for rating / progress / number_with_label; number_grouping for number_with_label) are only meaningful when paired with the right display_type. EVENTS: semantic_role (see post_field_enums.semantic_roles) tags a field for the event archive filter + Event schema; all_day and event_timezone apply to date fields (see post_field_enums.date_field_event_attributes). See critical_rules.events_archive_setup for the full workflow. FILTERS: set filterable:true to let visitors filter the archive by this field (the widget is auto-chosen from display_type) and sortable:true to offer it as a sort option; filter_widget optionally overrides the widget (see post_field_enums.filter_widgets). meta_pair cannot be filterable/sortable. See critical_rules.filterable_archive_setup for the full two-plugin workflow (declare filterable fields here, then enable_filters on a Promptless PostGrid).',
		);
	}

	/**
	 * v1.1 — closed enums for post field definitions, surfaced in preflight
	 * so connector clients can discover valid values without trial-and-error
	 * against the validator. All enums are CLOSED in v1.1; no filter-based
	 * extension is supported (see docs/POST_FIELDS_V1_1_DESIGN.md § 4).
	 *
	 * @return array
	 */
	private static function get_post_field_enums() {
		return array(
			// 9 display types with example renderings — the labels mirror
			// the admin-UI dropdown so AI agents and humans see the same hints.
			'display_types' => array(
				array( 'value' => 'currency',          'label' => 'Currency ($1,250,000)',           'description' => 'Locale-formatted currency value. Currency code resolves via field currency_code → AISB Business Identity → pcptpages_currency option → USD. Optional value_suffix is appended after the formatted amount: "+" for starting-at pricing ($2,328+), "/mo" for subscriptions ($45/mo), "/night" for hotels.' ),
				array( 'value' => 'number_with_label', 'label' => 'Number with unit (1,800 sqft)',   'description' => 'Numeric value with a unit suffix from unit_label. Designed for meta_strip cells. Thousands grouping is ON by default (3,200 sqft). For identifier-like numbers that must NOT be grouped — year built (2019, not 2,019), model year, unit/lot numbers, IDs — set number_grouping:false.' ),
				array( 'value' => 'badge',             'label' => 'Badge (For sale)',                'description' => 'Single pill with color intent. Define options to constrain the value to a curated list with per-value intent overrides.' ),
				array( 'value' => 'meta_pair',         'label' => 'Icon + value (🛏 3)',             'description' => 'Designed for meta_strip. Pulls icon from PCPTPages_Icon_Library via the icon attribute. Pairs an icon glyph with a short numeric or string value.' ),
				array( 'value' => 'date',              'label' => 'Date (May 20, 2026)',             'description' => 'Date display. date_format picks one of: absolute (May 20, 2026), relative (2 days ago), custom (uses date_format_string).' ),
				array( 'value' => 'text',              'label' => 'Plain text',                       'description' => 'Catch-all string display. Use for category names, location strings, anything that doesn\'t fit a more specific type.' ),
				array( 'value' => 'rating',            'label' => 'Star rating (★★★★☆ 4.8 (1,243))', 'description' => 'Composite: stars + numeric value + optional review count. Value stored at _pcptpages_field_{key}; count at _pcptpages_field_{key}_count. max defaults to 5.' ),
				array( 'value' => 'progress',          'label' => 'Progress bar (65% funded)',       'description' => 'Bar + label. Value stored at _pcptpages_field_{key} (current); goal at _pcptpages_field_{key}_goal (target). When currency_code is set, label reads "$320,000 of $500,000".' ),
				array( 'value' => 'multi_badge',       'label' => 'Multi-badge (Vegan, GF, Quick)',  'description' => 'Comma-separated string OR array. Each segment renders as its own pill in the same row. options can map per-segment to a label + color_intent.' ),
			),
			// 5 positions + hidden, symmetric across card and single-hero contexts.
			'field_positions' => array(
				array( 'value' => 'image_overlay', 'label' => 'Image overlay (top-left badge)',     'description' => 'Pill / chip overlaid on the featured image. Best for status badges (For sale, Featured, Almost full).' ),
				array( 'value' => 'headline',      'label' => 'Headline (prominent, above title)',  'description' => 'Large prominent value above the title. For one star value per card: price, salary, event date, course price.' ),
				array( 'value' => 'subtitle',      'label' => 'Subtitle (under title)',             'description' => 'Small line directly under the title. For location, company name, restaurant cuisine, course instructor.' ),
				array( 'value' => 'meta_strip',    'label' => 'Meta strip (inline list)',           'description' => 'Inline horizontal strip of small items. For up to 3-4 meta_pair / number_with_label / rating items. Wraps on mobile.' ),
				array( 'value' => 'footer_meta',   'label' => 'Footer meta (bottom row)',           'description' => 'Smallest muted line at the bottom of the card / hero. For listed-date, attendees-count, MLS#, posted-by author.' ),
				array( 'value' => 'hidden',        'label' => 'Hidden in this context',             'description' => 'Field is registered but does not render in this context. Use when a field belongs in only ONE of card or single-hero (e.g. MLS# in footer_meta of single hero but hidden on the card).' ),
			),
			// 3 color intents — simplified to brand + neutral after the 2026-05-21
			// pressure test surfaced that semantic colors (success/warning/etc.)
			// didn't align with the site design system. SmartColorManager handles
			// WCAG contrast computation automatically via the --aisb-button-*-text
			// tokens, so primary/secondary intents stay accessible regardless of
			// what color the user picks for the brand palette.
			'color_intents' => array(
				array( 'value' => 'primary',   'label' => 'Primary (brand)',   'description' => 'Site\'s brand primary color. Background uses --aisb-color-primary; text uses --aisb-button-primary-text (SmartColorManager-computed contrast color, always WCAG-AA compliant against the chosen primary). Visually identical to AISB\'s WooCommerce SALE badge — same token pair. Best for promotional / high-emphasis badges (FOR SALE, SALE, FEATURED, $1,000 OFF, NEW LISTING).' ),
				array( 'value' => 'secondary', 'label' => 'Secondary (brand)', 'description' => 'Site\'s brand secondary color. Background --aisb-color-secondary; text --aisb-button-secondary-text. Best for paired badges that need to contrast against primary while still feeling brand-aligned. E.g., status badge in primary + deal badge in secondary side-by-side.' ),
				array( 'value' => 'neutral',   'label' => 'Neutral (dark overlay)', 'description' => 'Semi-transparent dark (rgba 15,23,42 at 0.85 opacity) with white text and backdrop blur. Independent of brand color. Works on any image. The Zillow "Designer finishes" pattern. Safe default; use when the badge content is informational (NEW, COMING SOON, FAST) rather than promotional, or when you want the badge to recede visually so the brand colors stand out.' ),
			),
			// Date format modes for the date display type.
			'date_formats' => array(
				array( 'value' => 'absolute', 'label' => 'Absolute (May 20, 2026)',   'description' => 'WP date_i18n with the format from get_option(date_format).' ),
				array( 'value' => 'relative', 'label' => 'Relative (2 days ago)',     'description' => 'human_time_diff output. Best for posted-date / updated-at fields where the value is recent.' ),
				array( 'value' => 'custom',   'label' => 'Custom format string',      'description' => 'Uses the value of date_format_string with date_i18n. Example: "F j" for "May 20".' ),
			),
			// Events vertical (v1.2): semantic roles tag a field for event
			// archive date-filtering + Schema.org Event markup. Each role pairs
			// with a required display_type and may be mapped once per CPT.
			'semantic_roles' => array(
				array( 'value' => 'event_start',           'display_type' => 'date',     'description' => 'Event start date/time. Required to make a CPT event-shaped; drives the upcoming/happening/past archive filter and Event.startDate.' ),
				array( 'value' => 'event_end',             'display_type' => 'date',     'description' => 'Event end date/time. Strongly recommended — status logic is end-date anchored (an in-progress multi-day event still counts as upcoming). Maps to Event.endDate.' ),
				array( 'value' => 'event_status',          'display_type' => 'badge',    'description' => 'Event status. Values map to Event.eventStatus: scheduled, cancelled, postponed, moved_online.' ),
				array( 'value' => 'event_location',        'display_type' => 'text',     'description' => 'Event location string. Maps to Event.location (Place).' ),
				array( 'value' => 'event_offers',          'display_type' => 'currency', 'description' => 'Ticket price. Maps to Event.offers (Offer).' ),
				array( 'value' => 'event_attendance_mode', 'display_type' => 'badge',    'description' => 'Attendance mode. Values map to Event.eventAttendanceMode: in_person, online, mixed.' ),
			),
			// Additive event attributes valid on date display types.
			'date_field_event_attributes' => array(
				'all_day'        => 'Boolean. On date fields: true = all-day / multi-day (date-only, no time). Affects Event schema output (date-only vs ISO-8601-with-offset) and date-status filtering.',
				'event_timezone' => 'Optional IANA timezone id (e.g. America/New_York) on date fields. Empty = site timezone. v1 renders in the site timezone; a stored UTC companion enables future viewer-local rendering.',
			),
			// Schema-driven filters (v1.2): the generic filter widget each
			// display_type maps to. Set filterable:true on a field to opt into
			// the archive filter UI; the widget is auto-chosen per this map.
			// filter_widget may override it with another widget VALID for the
			// field's display_type (first entry below is the default).
			'filter_widgets' => array(
				'currency'          => array( 'range' ),
				'number_with_label' => array( 'range', 'stepper' ),
				'rating'            => array( 'stepper', 'range' ),
				'progress'          => array( 'range' ),
				'date'              => array( 'date_toggle', 'date_range' ),
				'badge'             => array( 'pill_select', 'checkbox_group' ),
				'multi_badge'       => array( 'checkbox_group' ),
				'text'              => array( 'text_search' ),
				'meta_pair'         => 'not filterable (display-only)',
			),
			// Closed set of ISO 4217 currency codes the validator accepts on
			// currency_code. Extend via the pcptpages_supported_currencies filter.
			'supported_currencies' => PCPTPages_Validator::SUPPORTED_CURRENCIES,
			// Field-count thresholds. Soft is a UI warning; hard is enforced.
			'soft_field_count_warning' => PCPTPages_Validator::SOFT_FIELD_COUNT_WARNING,
			'hard_field_count_limit'   => (int) apply_filters( 'pcptpages_max_post_fields_per_cpt', PCPTPages_Validator::HARD_FIELD_COUNT_LIMIT ),
		);
	}

	/**
	 * GET /icons — curated icon catalogue (without SVG markup, to keep
	 * payload size sane) PLUS Iconify support metadata. Connector
	 * consumers can use either:
	 *
	 *   - A legacy curated ID from the `icons[]` list (`home`, `shield`,
	 *     `briefcase`, …) — renders the inline SVG. Best for fast paint
	 *     and predictable visual style across a curated palette.
	 *
	 *   - An Iconify code in `collection:name` form (`mdi:home`,
	 *     `logos:wordpress`, `material-symbols:business`, …) — renders
	 *     the iconify-icon web component. Best for industry-specific or
	 *     brand-specific glyphs the curated set doesn't cover.
	 *
	 * The `iconify` block in the response carries the format pattern, the
	 * browse URL, and the legacy → Iconify mapping so an agent can pick
	 * the right shape for the content without an additional round trip.
	 */
	public function handle_list_icons( WP_REST_Request $request ) {
		$icons       = array();
		$cat_seen    = array();

		foreach ( PCPTPages_Icon_Library::get_all() as $id => $icon ) {
			$icons[] = array(
				'id'           => $id,
				'label'        => $icon['label'],
				'category'     => $icon['category'],
				'tags'         => $icon['tags'],
				'iconify_code' => isset( PCPTPages_Icon_Library::get_legacy_iconify_map()[ $id ] )
					? PCPTPages_Icon_Library::get_legacy_iconify_map()[ $id ]
					: null,
			);
			$cat_seen[ $icon['category'] ] = true;
		}

		return rest_ensure_response( array(
			'icons'      => $icons,
			'categories' => array_keys( $cat_seen ),
			'iconify'    => array(
				'supported'      => true,
				'format'         => 'collection:name',
				'pattern'        => '^[a-z0-9][a-z0-9_-]*:[a-z0-9][a-z0-9_-]*$',
				'max_length'     => PCPTPages_Icon_Library::MAX_ICONIFY_LENGTH,
				'browse_url'     => 'https://icon-sets.iconify.design/',
				'render_pattern' => '<iconify-icon icon="…"></iconify-icon>',
				'note'           => 'icon_id accepts BOTH a curated id from icons[] (renders inline SVG) and any Iconify code in collection:name form (renders via iconify-icon web component; SVG fetched from api.iconify.design at paint time with graceful fallback for missing icons). Recommended for connector workflows: prefer Iconify codes for parity with Promptless WP (which already uses Iconify everywhere) and for industry-specific glyphs the curated 53 do not cover (logos:wordpress, mdi:hammer-wrench, fa6-solid:tooth, etc.).',
				'legacy_map'     => PCPTPages_Icon_Library::get_legacy_iconify_map(),
			),
		) );
	}

	/**
	 * GET /variants — variant catalogue with rendering hints.
	 */
	public function handle_list_variants( WP_REST_Request $request ) {
		return rest_ensure_response( array(
			'variants' => array(
				array(
					'id'                       => 'compact-grid',
					'label'                    => 'Compact grid',
					'max_items_required'       => null,
					'supports_supporting_text' => false,
				),
				array(
					'id'                       => 'card-grid',
					'label'                    => 'Card grid',
					'max_items_required'       => null,
					'supports_supporting_text' => true,
				),
				array(
					'id'                       => 'featured-card',
					'label'                    => 'Featured card',
					'max_items_required'       => 1,
					'supports_supporting_text' => true,
				),
				array(
					'id'                       => 'horizontal-row',
					'label'                    => 'Horizontal row',
					'max_items_required'       => null,
					'supports_supporting_text' => false,
				),
			),
		) );
	}

	/**
	 * GET /positions — position catalogue.
	 */
	public function handle_list_positions( WP_REST_Request $request ) {
		return rest_ensure_response( array(
			'positions' => array(
				array( 'id' => 'above_main', 'label' => 'Above main content' ),
				array( 'id' => 'below_main', 'label' => 'Below main content' ),
				array( 'id' => 'sidebar',    'label' => 'Sidebar' ),
			),
		) );
	}

	// ========================================================================
	// Handlers — CPTs
	// ========================================================================

	public function handle_list_cpts( WP_REST_Request $request ) {
		$plugin = pcptpages();
		if ( ! $plugin->cpts ) {
			return $this->error_response( 'pcptpages_internal_error', __( 'CPT registry not initialized.', 'promptless-cpt-pages' ), 500 );
		}

		$cpts = array();
		foreach ( $plugin->cpts->get_all() as $slug => $def ) {
			$cpts[] = $this->shape_cpt( $slug, $def );
		}

		return rest_ensure_response( array( 'cpts' => $cpts ) );
	}

	public function handle_register_cpt( WP_REST_Request $request ) {
		$plugin = pcptpages();
		if ( ! $plugin->cpts ) {
			return $this->error_response( 'pcptpages_internal_error', __( 'CPT registry not initialized.', 'promptless-cpt-pages' ), 500 );
		}

		$body = $this->parse_json_body( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$slug = isset( $body['slug'] ) ? sanitize_key( $body['slug'] ) : '';
		if ( $slug === '' ) {
			return $this->error_response( 'pcptpages_invalid_slug', __( 'Missing or invalid slug.', 'promptless-cpt-pages' ), 422 );
		}

		// Strip server-managed fields if the agent sent them.
		unset( $body['slug'], $body['connector_version'], $body['created_at'], $body['updated_at'] );

		$result = $plugin->cpts->register( $slug, $body );
		if ( is_wp_error( $result ) ) {
			return $this->error_from_wp_error( $result );
		}

		$def = $plugin->cpts->get( $slug );
		return new WP_REST_Response( $this->shape_cpt( $slug, $def ), 201 );
	}

	public function handle_get_cpt( WP_REST_Request $request ) {
		$plugin = pcptpages();
		$slug   = sanitize_key( $this->get_url_param( $request, 'slug' ) );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pcptpages_cpt_not_found', __( 'CPT not found.', 'promptless-cpt-pages' ), 404 );
		}

		$def = $plugin->cpts->get( $slug );
		return rest_ensure_response( $this->shape_cpt( $slug, $def ) );
	}

	public function handle_update_cpt( WP_REST_Request $request ) {
		$plugin = pcptpages();
		$slug   = sanitize_key( $this->get_url_param( $request, 'slug' ) );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pcptpages_cpt_not_found', __( 'CPT not found.', 'promptless-cpt-pages' ), 404 );
		}

		$body = $this->parse_json_body( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		// Slug is immutable.
		if ( isset( $body['slug'] ) && sanitize_key( $body['slug'] ) !== $slug ) {
			return $this->error_response(
				'pcptpages_immutable_field',
				__( 'CPT slug is immutable. Delete and re-register to rename.', 'promptless-cpt-pages' ),
				400
			);
		}

		// Concurrency check.
		$version_check = $this->check_connector_version(
			$request,
			$body,
			$plugin->cpts->get( $slug )
		);
		if ( is_wp_error( $version_check ) ) {
			return $version_check;
		}

		// Pass through to registry. The registry's register() method is
		// an upsert that always re-runs the full validator (label_singular
		// / label_plural required, etc.) — so for a true partial-update
		// we must merge the body INTO the existing definition first.
		// Without this merge a caller doing `update_cpt(slug, has_archive: true)`
		// would be rejected with `pcptpages_missing_label_singular`. The slug-
		// keyed registry storage already discards anything we don't pass
		// here; merging just fills in the required keys from the
		// last-known-good state.
		unset( $body['slug'], $body['connector_version'], $body['created_at'], $body['updated_at'] );
		$existing = $plugin->cpts->get( $slug );
		if ( is_array( $existing ) ) {
			// Strip stamp/connector_version fields off the existing definition
			// before merging so register() can re-stamp them cleanly.
			unset( $existing['connector_version'], $existing['created_at'], $existing['updated_at'] );
			$body = array_merge( $existing, $body );
		}
		$result = $plugin->cpts->register( $slug, $body );
		if ( is_wp_error( $result ) ) {
			return $this->error_from_wp_error( $result );
		}

		$def = $plugin->cpts->get( $slug );
		return rest_ensure_response( $this->shape_cpt( $slug, $def ) );
	}

	public function handle_delete_cpt( WP_REST_Request $request ) {
		$plugin     = pcptpages();
		$slug       = sanitize_key( $this->get_url_param( $request, 'slug' ) );
		// purge_data is a query-string flag, not a URL-pattern capture, so
		// it correctly uses get_param.
		$purge_data = (bool) $request->get_param( 'purge_data' );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pcptpages_cpt_not_found', __( 'CPT not found.', 'promptless-cpt-pages' ), 404 );
		}

		// Always remove grouping definitions for the CPT.
		if ( $plugin->groupings ) {
			$plugin->groupings->remove_all_for_cpt( $slug );
		}

		// Purge per-post data only on explicit opt-in (data-protection
		// principle — uninstall preserves data by default; same here).
		if ( $purge_data ) {
			$posts = get_posts( array(
				'post_type'      => $slug,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_status'    => 'any',
			) );
			foreach ( $posts as $post_id ) {
				delete_post_meta( $post_id, '_pcptpages_groupings' );
				delete_post_meta( $post_id, '_pcptpages_groupings_backup' );
			}
		}

		$plugin->cpts->unregister( $slug );

		// Return a 200 JSON envelope rather than a bare 204 No Content.
		// 204 cannot legally carry a body, and connector clients that
		// expect JSON on every response (the MCP bridge) misread an
		// empty body as a parse failure. Match the sibling handlers,
		// which all return rest_ensure_response( array(...) ).
		return rest_ensure_response(
			array(
				'deleted' => true,
				'slug'    => $slug,
				'purged'  => $purge_data,
			)
		);
	}

	// ========================================================================
	// Handlers — groupings
	// ========================================================================

	public function handle_list_groupings( WP_REST_Request $request ) {
		$plugin = pcptpages();
		$slug   = sanitize_key( $this->get_url_param( $request, 'slug' ) );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pcptpages_cpt_not_found', __( 'CPT not found.', 'promptless-cpt-pages' ), 404 );
		}

		$groupings = array();
		foreach ( $plugin->groupings->get_all( $slug ) as $key => $def ) {
			$groupings[] = $this->shape_grouping( $key, $def );
		}

		return rest_ensure_response( array( 'groupings' => $groupings ) );
	}

	public function handle_define_grouping( WP_REST_Request $request ) {
		$plugin = pcptpages();
		$slug   = sanitize_key( $this->get_url_param( $request, 'slug' ) );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pcptpages_cpt_not_found', __( 'CPT not found.', 'promptless-cpt-pages' ), 404 );
		}

		$body = $this->parse_json_body( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		unset( $body['connector_version'] );

		$result = $plugin->groupings->define( $slug, $body );
		if ( is_wp_error( $result ) ) {
			return $this->error_from_wp_error( $result );
		}

		$key = sanitize_key( $body['key'] ?? '' );
		$def = $plugin->groupings->get( $slug, $key );
		return new WP_REST_Response( $this->shape_grouping( $key, $def ), 201 );
	}

	public function handle_get_grouping( WP_REST_Request $request ) {
		$plugin = pcptpages();
		$slug   = sanitize_key( $this->get_url_param( $request, 'slug' ) );
		$key    = sanitize_key( $this->get_url_param( $request, 'key' ) );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pcptpages_cpt_not_found', __( 'CPT not found.', 'promptless-cpt-pages' ), 404 );
		}
		if ( ! $plugin->groupings->exists( $slug, $key ) ) {
			return $this->error_response( 'pcptpages_grouping_not_found', __( 'Grouping not found.', 'promptless-cpt-pages' ), 404 );
		}

		$def = $plugin->groupings->get( $slug, $key );
		return rest_ensure_response( $this->shape_grouping( $key, $def ) );
	}

	public function handle_update_grouping( WP_REST_Request $request ) {
		$plugin = pcptpages();
		$slug   = sanitize_key( $this->get_url_param( $request, 'slug' ) );
		$key    = sanitize_key( $this->get_url_param( $request, 'key' ) );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pcptpages_cpt_not_found', __( 'CPT not found.', 'promptless-cpt-pages' ), 404 );
		}
		if ( ! $plugin->groupings->exists( $slug, $key ) ) {
			return $this->error_response( 'pcptpages_grouping_not_found', __( 'Grouping not found.', 'promptless-cpt-pages' ), 404 );
		}

		$body = $this->parse_json_body( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		// Concurrency check.
		$version_check = $this->check_connector_version(
			$request,
			$body,
			$plugin->groupings->get( $slug, $key )
		);
		if ( is_wp_error( $version_check ) ) {
			return $version_check;
		}

		// Force the key (the URL path is authoritative).
		$body['key'] = $key;
		unset( $body['connector_version'] );

		$result = $plugin->groupings->define( $slug, $body );
		if ( is_wp_error( $result ) ) {
			return $this->error_from_wp_error( $result );
		}

		$def = $plugin->groupings->get( $slug, $key );
		return rest_ensure_response( $this->shape_grouping( $key, $def ) );
	}

	public function handle_delete_grouping( WP_REST_Request $request ) {
		$plugin = pcptpages();
		$slug   = sanitize_key( $this->get_url_param( $request, 'slug' ) );
		$key    = sanitize_key( $this->get_url_param( $request, 'key' ) );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pcptpages_cpt_not_found', __( 'CPT not found.', 'promptless-cpt-pages' ), 404 );
		}
		if ( ! $plugin->groupings->exists( $slug, $key ) ) {
			return $this->error_response( 'pcptpages_grouping_not_found', __( 'Grouping not found.', 'promptless-cpt-pages' ), 404 );
		}

		$plugin->groupings->remove( $slug, $key );

		// 200 JSON envelope, not a bare 204 — see handle_delete_cpt.
		return rest_ensure_response(
			array(
				'deleted' => true,
				'slug'    => $slug,
				'key'     => $key,
			)
		);
	}

	// ========================================================================
	// Handlers — post groupings
	// ========================================================================

	public function handle_get_post_groupings( WP_REST_Request $request ) {
		$plugin  = pcptpages();
		$post_id = (int) $this->get_url_param( $request, 'id' );

		$err = $this->require_pcptpages_post( $post_id );
		if ( is_wp_error( $err ) ) {
			return $err;
		}

		$post      = get_post( $post_id );
		$groupings = $plugin->post_data->get_groupings( $post_id );

		return rest_ensure_response( array(
			'post_id'   => $post_id,
			'post_type' => $post->post_type,
			'groupings' => $groupings,
		) );
	}

	public function handle_set_post_groupings( WP_REST_Request $request ) {
		$plugin  = pcptpages();
		$post_id = (int) $this->get_url_param( $request, 'id' );

		$err = $this->require_pcptpages_post( $post_id );
		if ( is_wp_error( $err ) ) {
			return $err;
		}

		$body = $this->parse_json_body( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$groupings = isset( $body['groupings'] ) && is_array( $body['groupings'] )
			? $body['groupings']
			: array();

		$result = $plugin->post_data->set_groupings( $post_id, $groupings, 'connector' );
		if ( is_wp_error( $result ) ) {
			return $this->error_from_wp_error( $result );
		}

		$persisted = $plugin->post_data->get_groupings( $post_id );
		$post      = get_post( $post_id );

		return rest_ensure_response( array(
			'post_id'   => $post_id,
			'post_type' => $post->post_type,
			'groupings' => $persisted,
		) );
	}

	// ========================================================================
	// Handlers — post create + preview
	// ========================================================================

	public function handle_create_post( WP_REST_Request $request ) {
		$plugin = pcptpages();

		$body = $this->parse_json_body( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$post_type = isset( $body['post_type'] ) ? sanitize_key( $body['post_type'] ) : '';

		if ( $post_type === '' || ! $plugin->cpts || ! $plugin->cpts->exists( $post_type ) ) {
			return $this->error_response(
				'pcptpages_unregistered_post_type',
				__( 'post_type must be a CPT registered through Promptless CPT Pages.', 'promptless-cpt-pages' ),
				422
			);
		}

		// Per-CPT capability check (the route-level callback only checked
		// site-wide create_post; tighten to the actual CPT's publish cap).
		if ( ! current_user_can( PCPTPages_Capabilities::publish_cap_for( $post_type ) ) ) {
			return $this->error_response(
				'rest_forbidden',
				__( 'Your account cannot publish posts of this type.', 'promptless-cpt-pages' ),
				403
			);
		}

		$title = isset( $body['post_title'] ) ? wp_strip_all_tags( (string) $body['post_title'] ) : '';
		if ( $title === '' ) {
			return $this->error_response( 'pcptpages_missing_post_title', __( 'post_title is required.', 'promptless-cpt-pages' ), 422 );
		}

		// Defensive sanitization: AI agents sometimes wrap post_content with
		// <![CDATA[...]]> by analogy with SOAP / WordPress importer XML. In
		// JSON-typed connector params the wrapper is meaningless and would
		// be stored verbatim, leaking <![CDATA[ at the top of the rendered
		// body. We strip it here and surface a warning so authors know.
		// Then run the Gutenberg block conversion: if the content is raw
		// HTML (no `<!-- wp: -->` delimiters), wrap top-level elements in
		// the appropriate block comments so the editor opens with proper
		// individual blocks instead of a single Classic/Freeform wrapper.
		// See critical_rules.post_content_is_gutenberg_blocks for the contract.
		$content_warnings = array();
		$raw_content      = isset( $body['post_content'] ) ? (string) $body['post_content'] : '';
		$cleaned_content  = self::strip_cdata_envelope( $raw_content, $content_warnings );
		$cleaned_content  = self::ensure_gutenberg_blocks( $cleaned_content, $content_warnings );

		$insert_args = array(
			'post_type'    => $post_type,
			'post_status'  => isset( $body['post_status'] ) ? sanitize_key( $body['post_status'] ) : 'draft',
			'post_title'   => $title,
			'post_excerpt' => isset( $body['post_excerpt'] ) ? wp_kses_post( (string) $body['post_excerpt'] ) : '',
			'post_content' => wp_kses_post( $cleaned_content ),
		);

		$post_id = wp_insert_post( $insert_args, true );
		if ( is_wp_error( $post_id ) ) {
			return $this->error_response( 'pcptpages_post_create_failed', $post_id->get_error_message(), 500 );
		}

		$warnings = $content_warnings;

		// Featured image.
		if ( isset( $body['featured_image_id'] ) ) {
			$thumb = (int) $body['featured_image_id'];
			if ( $thumb > 0 ) {
				$result = set_post_thumbnail( $post_id, $thumb );
				if ( ! $result ) {
					$warnings[] = sprintf( 'featured_image_id %d could not be set.', $thumb );
				}
			}
		}

		// Groupings — atomic with the post: roll back on failure.
		if ( isset( $body['groupings'] ) && is_array( $body['groupings'] ) ) {
			$result = $plugin->post_data->set_groupings( $post_id, $body['groupings'], 'connector' );
			if ( is_wp_error( $result ) ) {
				wp_delete_post( $post_id, true );
				return $this->error_from_wp_error( $result );
			}
		}

		// Taxonomy terms (categories, tags, or any taxonomy registered for the
		// CPT). Non-fatal: term-level problems are surfaced as warnings rather
		// than failing the whole create, since the post itself is valid.
		if ( isset( $body['taxonomies'] ) ) {
			$this->apply_taxonomies( $post_id, $post_type, $body['taxonomies'], $warnings );
		}

		return new WP_REST_Response(
			array(
				'post_id'   => $post_id,
				'permalink' => get_permalink( $post_id ),
				'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
				'warnings'  => $warnings,
			),
			201
		);
	}

	/**
	 * PUT /posts/{id} — partial update of a post created through the
	 * connector. Accepts any subset of post_title, post_content,
	 * post_excerpt, post_status, featured_image_id, groupings.
	 *
	 * Partial-update semantics: omitted fields are not changed. Sending
	 * an empty string clears a field. Sending a `groupings` array fully
	 * replaces all groupings (same semantics as set_post_groupings).
	 *
	 * Atomicity: post updates and groupings updates are applied in
	 * sequence. If groupings validation fails, the post-level update is
	 * NOT rolled back — partial updates are preserved (use the response's
	 * `errors` field to see what failed). Authors who need strict atomicity
	 * should call set_post_groupings separately and check its response
	 * before issuing further changes.
	 *
	 * Same defensive sanitization as create_post: a CDATA wrapper around
	 * post_content is stripped with a warning. See critical_rules.
	 */
	public function handle_update_post( WP_REST_Request $request ) {
		$post_id = (int) $this->get_url_param( $request, 'id' );

		$err = $this->require_pcptpages_post( $post_id );
		if ( is_wp_error( $err ) ) {
			return $err;
		}

		$body = $this->parse_json_body( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$plugin = pcptpages();

		$update_args = array( 'ID' => $post_id );
		$warnings    = array();

		if ( array_key_exists( 'post_title', $body ) ) {
			$title = wp_strip_all_tags( (string) $body['post_title'] );
			if ( $title === '' ) {
				return $this->error_response( 'pcptpages_missing_post_title', __( 'post_title cannot be empty.', 'promptless-cpt-pages' ), 422 );
			}
			$update_args['post_title'] = $title;
		}

		if ( array_key_exists( 'post_excerpt', $body ) ) {
			$update_args['post_excerpt'] = wp_kses_post( (string) $body['post_excerpt'] );
		}

		if ( array_key_exists( 'post_content', $body ) ) {
			// Same defense-in-depth as create_post: strip a CDATA wrapper,
			// then convert raw HTML into Gutenberg block format so the
			// editor opens with discrete blocks instead of a single Classic
			// / Freeform wrapper. Both helpers emit warnings when they
			// fire so authors can audit the AI's output shape.
			$cleaned = self::strip_cdata_envelope( (string) $body['post_content'], $warnings );
			$cleaned = self::ensure_gutenberg_blocks( $cleaned, $warnings );
			$update_args['post_content'] = wp_kses_post( $cleaned );
		}

		if ( array_key_exists( 'post_status', $body ) ) {
			$status = sanitize_key( (string) $body['post_status'] );
			$valid  = array( 'publish', 'draft', 'pending', 'private', 'future' );
			if ( ! in_array( $status, $valid, true ) ) {
				return $this->error_response(
					'pcptpages_invalid_post_status',
					sprintf(
						/* translators: %1$s: invalid status; %2$s: list of valid statuses */
						__( 'post_status %1$s is not one of: %2$s', 'promptless-cpt-pages' ),
						$status,
						implode( ', ', $valid )
					),
					422
				);
			}

			// Authorization: transitioning a post to a publicly-visible
			// state (publish / future / private) requires the publish_posts
			// capability on the post's post-type, not just edit_post on the
			// target. build_per_post_callback already verified edit_post via
			// the permission_callback; this is the additional gate the
			// WP.org reviewer specifically called out as missing.
			$publish_states = array( 'publish', 'future', 'private' );
			if ( in_array( $status, $publish_states, true ) ) {
				$post_obj = get_post( $post_id );
				if ( ! $post_obj ) {
					return $this->error_response( 'pcptpages_post_not_found', __( 'Post not found.', 'promptless-cpt-pages' ), 404 );
				}
				$pt_obj = get_post_type_object( $post_obj->post_type );
				$publish_cap = ( $pt_obj && isset( $pt_obj->cap->publish_posts ) ) ? $pt_obj->cap->publish_posts : 'publish_posts';
				if ( ! current_user_can( $publish_cap, $post_id ) ) {
					return $this->error_response(
						'pcptpages_publish_forbidden',
						sprintf(
							/* translators: %s: target post status */
							__( 'You do not have permission to set post_status to %s on this post.', 'promptless-cpt-pages' ),
							$status
						),
						403
					);
				}
			}

			$update_args['post_status'] = $status;
		}

		// Apply post-level updates only if there's something to change.
		if ( count( $update_args ) > 1 ) {
			$result = wp_update_post( $update_args, true );
			if ( is_wp_error( $result ) ) {
				return $this->error_response( 'pcptpages_post_update_failed', $result->get_error_message(), 500 );
			}
		}

		// Featured image — separate path, mirrors create_post handling.
		if ( array_key_exists( 'featured_image_id', $body ) ) {
			$thumb = (int) $body['featured_image_id'];
			if ( $thumb > 0 ) {
				$ok = set_post_thumbnail( $post_id, $thumb );
				if ( ! $ok ) {
					$warnings[] = sprintf( 'featured_image_id %d could not be set.', $thumb );
				}
			} else {
				delete_post_thumbnail( $post_id );
			}
		}

		// Groupings — full replace, same semantics as set_post_groupings.
		if ( array_key_exists( 'groupings', $body ) && is_array( $body['groupings'] ) ) {
			$result = $plugin->post_data->set_groupings( $post_id, $body['groupings'], 'connector' );
			if ( is_wp_error( $result ) ) {
				return $this->error_from_wp_error( $result );
			}
		}

		// Taxonomy terms — full replace per taxonomy supplied. Omitted
		// taxonomies are left untouched; a supplied taxonomy with an empty
		// list clears that taxonomy's terms for the post.
		if ( array_key_exists( 'taxonomies', $body ) ) {
			$this->apply_taxonomies( $post_id, get_post_type( $post_id ), $body['taxonomies'], $warnings );
		}

		return rest_ensure_response( array(
			'post_id'   => $post_id,
			'permalink' => get_permalink( $post_id ),
			'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
			'warnings'  => $warnings,
		) );
	}

	/**
	 * Assign taxonomy terms to a post from a connector `taxonomies` map.
	 *
	 * Shape: { taxonomy_slug: [ "Term A", "term-b", 123 ], ... }. Each list
	 * entry may be a term name, slug, or term ID. Names/slugs that don't yet
	 * exist are created (this is greenfield CPT setup — the AI agent declares
	 * the neighborhoods/regions/etc. as it populates posts). Assignment is a
	 * REPLACE per taxonomy (mirrors wp_set_object_terms with append=false), so
	 * the supplied list becomes the post's complete term set for that taxonomy.
	 *
	 * Non-fatal by design: an unregistered taxonomy or a single bad term is
	 * surfaced as a warning, never a hard failure — the post itself is valid
	 * and the author can correct terms in a follow-up call.
	 *
	 * @param int          $post_id    Target post.
	 * @param string       $post_type  Post type (to validate taxonomy attachment).
	 * @param mixed        $taxonomies The connector `taxonomies` value.
	 * @param array        $warnings   Warnings accumulator (by reference).
	 * @return void
	 */
	private function apply_taxonomies( $post_id, $post_type, $taxonomies, array &$warnings ) {
		if ( ! is_array( $taxonomies ) ) {
			$warnings[] = 'taxonomies must be an object mapping a taxonomy slug to a list of terms; ignored.';
			return;
		}

		$registered = get_object_taxonomies( $post_type );

		foreach ( $taxonomies as $tax_slug => $terms ) {
			$tax_slug = sanitize_key( (string) $tax_slug );
			if ( $tax_slug === '' ) {
				continue;
			}

			if ( ! taxonomy_exists( $tax_slug ) || ! in_array( $tax_slug, $registered, true ) ) {
				$warnings[] = sprintf(
					'taxonomy "%s" is not registered for post type "%s"; skipped. Available: %s. (Register it via the CPT\'s `taxonomies` list first.)',
					$tax_slug,
					$post_type,
					$registered ? implode( ', ', $registered ) : '(none)'
				);
				continue;
			}

			// Accept an array OR a comma-separated string of terms.
			if ( is_string( $terms ) ) {
				$terms = array_map( 'trim', explode( ',', $terms ) );
			}
			if ( ! is_array( $terms ) ) {
				$warnings[] = sprintf( 'terms for taxonomy "%s" must be an array or comma-separated string; skipped.', $tax_slug );
				continue;
			}

			$term_ids = array();
			foreach ( $terms as $term ) {
				if ( ! is_scalar( $term ) ) {
					continue;
				}
				$term = trim( (string) $term );
				if ( $term === '' ) {
					continue;
				}

				// Numeric entries are treated as existing term IDs.
				if ( ctype_digit( $term ) ) {
					$maybe = get_term( (int) $term, $tax_slug );
					if ( $maybe && ! is_wp_error( $maybe ) ) {
						$term_ids[] = (int) $maybe->term_id;
						continue;
					}
				}

				// Resolve by name/slug; create if missing.
				$existing = term_exists( $term, $tax_slug );
				if ( $existing ) {
					$term_ids[] = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
					continue;
				}

				$created = wp_insert_term( $term, $tax_slug );
				if ( is_wp_error( $created ) ) {
					$warnings[] = sprintf(
						'term "%s" in taxonomy "%s" could not be created: %s',
						$term,
						$tax_slug,
						$created->get_error_message()
					);
					continue;
				}
				$term_ids[] = (int) $created['term_id'];
			}

			$set = wp_set_object_terms( $post_id, $term_ids, $tax_slug, false );
			if ( is_wp_error( $set ) ) {
				$warnings[] = sprintf(
					'failed to assign terms for taxonomy "%s": %s',
					$tax_slug,
					$set->get_error_message()
				);
			}
		}
	}

	public function handle_preview_post( WP_REST_Request $request ) {
		$post_id = (int) $this->get_url_param( $request, 'id' );

		$err = $this->require_pcptpages_post( $post_id );
		if ( is_wp_error( $err ) ) {
			return $err;
		}

		$post = get_post( $post_id );

		// Render through PCPTPages_Renderer with output buffering so we
		// capture the article HTML without theme chrome. Pass $use_cache
		// = false: the connector preview is for agents verifying their
		// just-written changes, so a cached version from before the
		// write would be misleading.
		ob_start();
		setup_postdata( $post );
		( new PCPTPages_Renderer() )->render( $post, false );
		wp_reset_postdata();
		$html = ob_get_clean();

		return rest_ensure_response( array(
			'post_id'   => $post_id,
			'html'      => $html,
			'css_url'   => PCPTPages_PLUGIN_URL . 'assets/css/frontend.css?ver=' . PCPTPages_VERSION,
			'permalink' => get_permalink( $post_id ),
		) );
	}

	// ========================================================================
	// Helpers — argument schemas
	// ========================================================================

	/**
	 * Read a URL-pattern-derived identifier explicitly, bypassing the
	 * default parameter resolution order.
	 *
	 * Why this exists: WP_REST_Request::get_param() resolves params in
	 * this order for application/json bodies — JSON → POST → GET → URL
	 * — which means a body field named the same as a URL pattern capture
	 * silently overrides the URL value. For PUT/PATCH/DELETE handlers
	 * that name URL identifiers (e.g. {slug} in /cpts/{slug}, {id} in
	 * /posts/{id}/groupings), that override has two real consequences:
	 *
	 *   1. UX: the immutability check in handle_update_cpt would never
	 *      reach a 400 error code — instead returning 404 because the
	 *      body's slug overrode the URL's slug before existence was
	 *      checked. That's a misleading error code for external agents
	 *      pattern-matching on the response.
	 *
	 *   2. Security: in per-post-auth handlers (set_post_groupings,
	 *      update_post, etc.) the auth callback and the handler both
	 *      read `id` via get_param. If a body field overrides URL, the
	 *      auth check would gate access to the body's id while the
	 *      handler operates on the URL's id (or vice versa, depending
	 *      on call order). That's a path to authorization bypass.
	 *
	 * Calling get_url_params() directly skips all that — the returned
	 * array only contains values extracted by the route's regex. By the
	 * time a handler runs, the route has already matched, so the URL
	 * param is guaranteed present.
	 *
	 * Use this method (or the same get_url_params() pattern in
	 * non-PCPTPages_Connector_API code) for ANY URL-derived identifier.
	 * Continue using $request->get_param() for body/query data.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @param string          $name    URL pattern capture name.
	 * @return string URL param value, or empty string if missing.
	 */
	private function get_url_param( WP_REST_Request $request, $name ) {
		$url_params = $request->get_url_params();
		return isset( $url_params[ $name ] ) ? (string) $url_params[ $name ] : '';
	}

	private function cpt_slug_arg() {
		return array(
			'slug' => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}

	private function cpt_slug_and_key_args() {
		return array(
			'slug' => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			),
			'key'  => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}

	private function post_id_arg() {
		return array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);
	}

	// ========================================================================
	// Helpers — request parsing
	// ========================================================================

	/**
	 * Parse the JSON body. Returns the decoded array or a 415 / 400 error.
	 * Strip a leading <![CDATA[ and trailing ]]> from incoming post_content
	 * if present. AI agents occasionally wrap content with this XML idiom by
	 * analogy with SOAP / WordPress importer XML; in JSON-typed connector
	 * params the wrapper is meaningless and stored verbatim, leaking into
	 * the rendered body as literal text. This is defense-in-depth — the
	 * critical_rules.post_content_is_html rule documents the contract;
	 * this helper catches the mistake when it slips through.
	 *
	 * Conservative: only strips the wrapper when it appears at the very
	 * start AND end of the content (allowing leading/trailing whitespace).
	 * A `<![CDATA[` somewhere in the middle of HTML is left untouched —
	 * that's almost certainly intentional (a literal example, technical
	 * documentation about XML, etc.).
	 *
	 * @param string $content    Raw post_content from the request body.
	 * @param array  &$warnings  Warning bucket; appended to when a strip fires.
	 * @return string Sanitized content.
	 */
	private static function strip_cdata_envelope( $content, array &$warnings ) {
		$trimmed = trim( $content );
		// Only act when the wrapper bookends the entire content.
		if ( strpos( $trimmed, '<![CDATA[' ) === 0 ) {
			$end = strrpos( $trimmed, ']]>' );
			// Closer must be at the very end, not somewhere in the middle.
			if ( $end !== false && $end === strlen( $trimmed ) - 3 ) {
				$inner       = substr( $trimmed, 9, $end - 9 );
				$warnings[]  = 'post_content_cdata_stripped: a <![CDATA[...]]> wrapper was removed from post_content. JSON-typed connector params do not need XML CDATA escaping. See critical_rules.post_content_is_gutenberg_blocks.';
				return $inner;
			}
			// Opener present but no clean closer — strip the opener anyway
			// (better to ship clean content than leak the literal tag) and
			// flag a stronger warning so the author can review.
			$warnings[]  = 'post_content_cdata_opener_stripped: a leading <![CDATA[ was removed from post_content but no matching closer was found. Review the rendered content for any trailing ]]>.';
			return ltrim( substr( $trimmed, 9 ) );
		}
		return $content;
	}

	/**
	 * Convert raw HTML post_content into Gutenberg block-format markup when
	 * the incoming content has no block delimiters. Defense-in-depth pattern
	 * paired with the critical_rules.post_content_is_gutenberg_blocks rule
	 * (which asks AI agents to send block format directly).
	 *
	 * Without this, raw HTML like `<h2>Title</h2><p>Body</p>` is stored
	 * verbatim, and the Gutenberg editor treats the whole post as a single
	 * Classic (Freeform) block — the "Convert to blocks" toolbar appears at
	 * the top of the editor and the content isn't editable as discrete
	 * blocks until the user manually clicks that button. With this converter,
	 * top-level recognized elements are wrapped in the appropriate Gutenberg
	 * block comment so the editor opens with proper individual blocks from
	 * the start.
	 *
	 * Idempotent: if the content already contains `<!-- wp:` delimiters
	 * anywhere, we assume it's already in block format and return as-is.
	 * The caller (AI agent that sends explicit blocks) bypasses the
	 * conversion entirely and pays no parsing cost.
	 *
	 * Conservative scope: only handles top-level elements in the recognized
	 * set (h1-h6, p, ul, ol, blockquote, pre, hr, figure). Anything else
	 * (nested divs, custom HTML, scripts) is wrapped in a single
	 * `core/html` block — matches what Gutenberg's own "Convert to blocks"
	 * does for unrecognized markup. Inline phrasing inside recognized
	 * elements (a, em, strong, code, etc.) is preserved verbatim — those
	 * are rich-text content within the block, not block-level structure.
	 *
	 * DOMDocument is used because regex over HTML is famously fragile; it
	 * handles whitespace, attributes, self-closing tags, and HTML entities
	 * correctly. LIBXML_HTML_NOIMPLIED + LIBXML_HTML_NODEFDTD prevent
	 * DOMDocument from wrapping the fragment in `<html><body>` shells
	 * that would otherwise leak into the serialized output.
	 *
	 * @param string $content    Raw post_content from the request body.
	 * @param array  &$warnings  Warning bucket; appended to when conversion fires.
	 * @return string Block-format content (or original content if no conversion needed).
	 */
	private static function ensure_gutenberg_blocks( $content, array &$warnings ) {
		// Short-circuit empty content — nothing to convert.
		if ( ! is_string( $content ) || trim( $content ) === '' ) {
			return $content;
		}

		// Short-circuit content already in block format. Detection is
		// permissive: any `<!-- wp:` anywhere in the content is treated as
		// "author sent blocks, leave alone." A mixed file with some blocks
		// and some raw HTML between them is rare in practice, and forcing
		// the converter to find and re-wrap raw fragments inside a partially-
		// blockified document would produce far more surprises than it
		// prevents.
		if ( strpos( $content, '<!-- wp:' ) !== false ) {
			return $content;
		}

		// Parse the HTML fragment. DOMDocument needs a UTF-8 hint via a
		// meta tag because by default it treats input as ISO-8859-1, which
		// mangles any non-ASCII characters in the content.
		$dom = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		// Wrap in a div sentinel so we can iterate top-level children
		// unambiguously without DOMDocument synthesizing <html>/<body>.
		$source = '<?xml encoding="UTF-8"><div id="pre-converter-root">' . $content . '</div>';
		$loaded = $dom->loadHTML( $source, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $loaded ) {
			// Couldn't parse — return original content rather than risk
			// corrupting it. The user still gets a Classic block, but no
			// data loss.
			$warnings[] = 'post_content_block_conversion_skipped: post_content could not be parsed as HTML; stored as-is.';
			return $content;
		}

		$root = $dom->getElementById( 'pre-converter-root' );
		if ( ! $root || ! $root->hasChildNodes() ) {
			return $content;
		}

		$blocks = array();
		foreach ( iterator_to_array( $root->childNodes ) as $node ) {
			$block = self::node_to_gutenberg_block( $dom, $node );
			if ( $block !== '' ) {
				$blocks[] = $block;
			}
		}

		if ( empty( $blocks ) ) {
			return $content;
		}

		$warnings[] = 'post_content_block_conversion_applied: post_content was sent as raw HTML and auto-converted to Gutenberg block format. For optimal control over block-level attributes (heading levels, list ordering, alignment), send block-format markup directly. See critical_rules.post_content_is_gutenberg_blocks.';
		return implode( "\n\n", $blocks );
	}

	/**
	 * Wrap a single top-level DOM node in the appropriate Gutenberg block
	 * comment. Helper for ensure_gutenberg_blocks().
	 *
	 * Maps top-level element name → block type:
	 *   h1-h6 → core/heading (level attribute carried over)
	 *   p     → core/paragraph
	 *   ul    → core/list (default unordered)
	 *   ol    → core/list with ordered:true
	 *   blockquote → core/quote
	 *   pre   → core/preformatted
	 *   hr    → core/separator
	 *   figure → core/image when it contains an <img>, otherwise core/html
	 *   anything else → core/html (matches Gutenberg's own fallback for
	 *     unrecognized top-level markup during "Convert to blocks")
	 *
	 * Text nodes between top-level elements (e.g. stray whitespace from
	 * pretty-printed HTML) are dropped — they would render as bare text
	 * inside a paragraph anyway and aren't worth a block of their own.
	 * Non-whitespace text becomes a paragraph block so we don't silently
	 * drop content.
	 *
	 * @param DOMDocument $dom  Parent document (needed for saveHTML on child fragments).
	 * @param DOMNode     $node Top-level child of the converter root.
	 * @return string Gutenberg block markup or empty string.
	 */
	private static function node_to_gutenberg_block( DOMDocument $dom, DOMNode $node ) {
		// Text nodes — only emit a paragraph block for non-whitespace text.
		if ( $node->nodeType === XML_TEXT_NODE ) {
			$text = trim( $node->nodeValue );
			if ( $text === '' ) {
				return '';
			}
			return "<!-- wp:paragraph -->\n<p>" . esc_html( $text ) . "</p>\n<!-- /wp:paragraph -->";
		}

		if ( $node->nodeType !== XML_ELEMENT_NODE ) {
			return '';
		}

		$tag        = strtolower( $node->nodeName );
		$inner_html = self::inner_html( $dom, $node );

		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$level    = (int) substr( $tag, 1 );
				// h2 is the Gutenberg default, so we omit the level attribute
				// for h2 to match what the editor itself serializes — keeps
				// block markup byte-identical between AI-sent and editor-saved
				// content.
				$attrs    = ( $level === 2 ) ? '' : ' {"level":' . $level . '}';
				return "<!-- wp:heading{$attrs} -->\n<{$tag} class=\"wp-block-heading\">{$inner_html}</{$tag}>\n<!-- /wp:heading -->";

			case 'p':
				// Empty paragraph (e.g. `<p></p>`) is dropped — would render
				// as visible empty block in the editor for no reason.
				if ( trim( $inner_html ) === '' ) {
					return '';
				}
				return "<!-- wp:paragraph -->\n<p>{$inner_html}</p>\n<!-- /wp:paragraph -->";

			case 'ul':
				return "<!-- wp:list -->\n<ul class=\"wp-block-list\">{$inner_html}</ul>\n<!-- /wp:list -->";

			case 'ol':
				return "<!-- wp:list {\"ordered\":true} -->\n<ol class=\"wp-block-list\">{$inner_html}</ol>\n<!-- /wp:list -->";

			case 'blockquote':
				return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">{$inner_html}</blockquote>\n<!-- /wp:quote -->";

			case 'pre':
				return "<!-- wp:preformatted -->\n<pre class=\"wp-block-preformatted\">{$inner_html}</pre>\n<!-- /wp:preformatted -->";

			case 'hr':
				// Self-closing separator. The has-alpha-channel-opacity class
				// matches Gutenberg's default for new separator blocks.
				return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";

			case 'figure':
				// Figure containing an img is the canonical image-block shape.
				// Figures with other content (e.g. svg, video) fall through
				// to core/html — preserves the markup without claiming a
				// specific block type that wouldn't validate.
				$img = $node->getElementsByTagName( 'img' )->item( 0 );
				if ( $img instanceof DOMElement ) {
					return "<!-- wp:image -->\n<figure class=\"wp-block-image\">" . self::node_outer_html( $dom, $node ) . "</figure>\n<!-- /wp:image -->";
				}
				// fallthrough to default

			default:
				// Unknown / unhandled top-level element → core/html block.
				// Matches what Gutenberg's "Convert to blocks" does for any
				// markup it can't map to a typed block. Preserves the
				// original markup losslessly.
				return "<!-- wp:html -->\n" . self::node_outer_html( $dom, $node ) . "\n<!-- /wp:html -->";
		}
	}

	/**
	 * Serialize the inner HTML of a DOM element (children only, no wrapper).
	 *
	 * Why not just `saveHTML( $node )`: that includes the wrapper tag. We
	 * want the contents between the wrapper's opening and closing tags so
	 * we can re-wrap with our own (block-attributed) tag.
	 *
	 * @param DOMDocument $dom  Owning document.
	 * @param DOMNode     $node Parent element whose children to serialize.
	 * @return string Inner HTML.
	 */
	private static function inner_html( DOMDocument $dom, DOMNode $node ) {
		$out = '';
		foreach ( $node->childNodes as $child ) {
			$out .= $dom->saveHTML( $child );
		}
		return $out;
	}

	/**
	 * Serialize a DOM element and all its descendants (outer HTML).
	 *
	 * @param DOMDocument $dom  Owning document.
	 * @param DOMNode     $node Element to serialize.
	 * @return string Outer HTML.
	 */
	private static function node_outer_html( DOMDocument $dom, DOMNode $node ) {
		return $dom->saveHTML( $node );
	}

	/**
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error Body or error.
	 */
	private function parse_json_body( WP_REST_Request $request ) {
		$content_type = $request->get_content_type();
		if ( ! $content_type || strpos( $content_type['value'], 'application/json' ) !== 0 ) {
			// Allow if WordPress already parsed it — only error on
			// genuinely non-JSON bodies.
			$body = $request->get_json_params();
			if ( ! is_array( $body ) || empty( $body ) ) {
				return $this->error_response(
					'pcptpages_unsupported_media_type',
					__( 'Content-Type must be application/json.', 'promptless-cpt-pages' ),
					415
				);
			}
			return $body;
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return $this->error_response(
				'pcptpages_invalid_json',
				__( 'Request body is not valid JSON.', 'promptless-cpt-pages' ),
				400
			);
		}

		return $body;
	}

	/**
	 * Compare submitted connector_version against the stored one.
	 * Returns true on pass, WP_Error on conflict.
	 *
	 * @param WP_REST_Request $request   Request (for If-Match header).
	 * @param array           $submitted Submitted body (may have connector_version inline).
	 * @param array|null      $stored    Currently stored definition with connector_version.
	 * @return true|WP_Error
	 */
	private function check_connector_version( WP_REST_Request $request, array $submitted, $stored ) {
		if ( ! is_array( $stored ) || ! isset( $stored['connector_version'] ) ) {
			// Stored definition has no version (shouldn't happen — registry
			// always stamps it). Skip the check; the next write will
			// initialize it.
			return true;
		}

		$current = (int) $stored['connector_version'];

		// Read submitted version: header preferred, body fallback.
		$header = $request->get_header( 'if_match' );
		if ( $header !== null && $header !== '' ) {
			$submitted_version = (int) $header;
		} elseif ( isset( $submitted['connector_version'] ) ) {
			$submitted_version = (int) $submitted['connector_version'];
		} else {
			// Neither provided — let it through. Strict mode would reject;
			// we let agents that haven't read connector_version yet still
			// write. Mid-implementation tightening can change this.
			return true;
		}

		if ( $submitted_version !== $current ) {
			return new WP_Error(
				'pcptpages_version_conflict',
				sprintf(
					/* translators: 1: server version, 2: submitted version */
					__( 'Version conflict — server has %1$d, you sent %2$d. Re-read the resource and retry.', 'promptless-cpt-pages' ),
					$current,
					$submitted_version
				),
				array(
					'status'             => 409,
					'current_version'    => $current,
					'submitted_version'  => $submitted_version,
				)
			);
		}

		return true;
	}

	/**
	 * Verify the post exists AND its post_type is a registered PRE CPT.
	 *
	 * @param int $post_id Post ID.
	 * @return true|WP_Error
	 */
	private function require_pcptpages_post( $post_id ) {
		$plugin = pcptpages();
		$post   = get_post( $post_id );

		if ( ! $post ) {
			return $this->error_response( 'pcptpages_post_not_found', __( 'Post not found.', 'promptless-cpt-pages' ), 404 );
		}

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $post->post_type ) ) {
			return $this->error_response(
				'pcptpages_unregistered_post_type',
				__( 'This post is not of a Promptless CPT Pages CPT.', 'promptless-cpt-pages' ),
				404
			);
		}

		return true;
	}

	// ========================================================================
	// Helpers — response shaping
	// ========================================================================

	/**
	 * Shape a CPT definition for the wire. Adds the `slug` field
	 * (storage key) and ensures stable ordering for diffing.
	 */
	private function shape_cpt( $slug, array $def ) {
		return array_merge(
			array( 'slug' => $slug ),
			$def
		);
	}

	/**
	 * Shape a grouping definition. Adds `key` to the response.
	 */
	private function shape_grouping( $key, array $def ) {
		return array_merge(
			array( 'key' => $key ),
			$def
		);
	}

	// ========================================================================
	// Handlers — post fields (v1.1)
	// ========================================================================

	/**
	 * GET /cpts/{slug}/post-fields — list all field definitions for a CPT.
	 */
	public function handle_list_post_fields( WP_REST_Request $request ) {
		$plugin = pcptpages();
		$slug   = sanitize_key( $this->get_url_param( $request, 'slug' ) );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pcptpages_cpt_not_found', __( 'CPT not found.', 'promptless-cpt-pages' ), 404 );
		}
		if ( ! $plugin->post_fields ) {
			return $this->error_response( 'pcptpages_post_fields_unavailable', __( 'Post field registry not initialized.', 'promptless-cpt-pages' ), 500 );
		}

		$out = array();
		foreach ( $plugin->post_fields->get_all( $slug ) as $key => $def ) {
			$out[] = $this->shape_post_field( $key, $def );
		}

		return rest_ensure_response( array( 'post_fields' => $out ) );
	}

	/**
	 * POST /cpts/{slug}/post-fields — define a new post field.
	 */
	public function handle_define_post_field( WP_REST_Request $request ) {
		$plugin = pcptpages();
		$slug   = sanitize_key( $this->get_url_param( $request, 'slug' ) );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pcptpages_cpt_not_found', __( 'CPT not found.', 'promptless-cpt-pages' ), 404 );
		}
		if ( ! $plugin->post_fields ) {
			return $this->error_response( 'pcptpages_post_fields_unavailable', __( 'Post field registry not initialized.', 'promptless-cpt-pages' ), 500 );
		}

		$body = $this->parse_json_body( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		// Strip any caller-supplied connector_version on create — the
		// registry assigns it. Update calls use a check_connector_version
		// preflight against the stored value (see handle_update_post_field).
		unset( $body['connector_version'] );

		$result = $plugin->post_fields->define( $slug, $body );
		if ( is_wp_error( $result ) ) {
			return $this->error_from_wp_error( $result );
		}

		$key = sanitize_key( $body['key'] ?? '' );
		$def = $plugin->post_fields->get( $slug, $key );
		return new WP_REST_Response( $this->shape_post_field( $key, $def ), 201 );
	}

	/**
	 * GET /cpts/{slug}/post-fields/{key} — read one definition.
	 */
	public function handle_get_post_field( WP_REST_Request $request ) {
		$plugin = pcptpages();
		$slug   = sanitize_key( $this->get_url_param( $request, 'slug' ) );
		$key    = sanitize_key( $this->get_url_param( $request, 'key' ) );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pcptpages_cpt_not_found', __( 'CPT not found.', 'promptless-cpt-pages' ), 404 );
		}
		if ( ! $plugin->post_fields || ! $plugin->post_fields->exists( $slug, $key ) ) {
			return $this->error_response( 'pcptpages_post_field_not_found', __( 'Post field not found.', 'promptless-cpt-pages' ), 404 );
		}

		$def = $plugin->post_fields->get( $slug, $key );
		return rest_ensure_response( $this->shape_post_field( $key, $def ) );
	}

	/**
	 * PUT /cpts/{slug}/post-fields/{key} — update an existing definition.
	 *
	 * Same upsert semantics as the grouping update path: the URL key is
	 * authoritative (body `key` is ignored / forced). connector_version
	 * is checked for optimistic concurrency.
	 */
	public function handle_update_post_field( WP_REST_Request $request ) {
		$plugin = pcptpages();
		$slug   = sanitize_key( $this->get_url_param( $request, 'slug' ) );
		$key    = sanitize_key( $this->get_url_param( $request, 'key' ) );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pcptpages_cpt_not_found', __( 'CPT not found.', 'promptless-cpt-pages' ), 404 );
		}
		if ( ! $plugin->post_fields || ! $plugin->post_fields->exists( $slug, $key ) ) {
			return $this->error_response( 'pcptpages_post_field_not_found', __( 'Post field not found.', 'promptless-cpt-pages' ), 404 );
		}

		$body = $this->parse_json_body( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		// Concurrency check — same shape as the grouping update path so
		// connector clients use a single pattern across both field types.
		$version_check = $this->check_connector_version(
			$request,
			$body,
			$plugin->post_fields->get( $slug, $key )
		);
		if ( is_wp_error( $version_check ) ) {
			return $version_check;
		}

		// Partial update: merge the incoming body INTO the existing definition
		// before re-defining, so a caller can touch one attribute (e.g.
		// `filterable: true`) without resending the whole field — and without
		// the absent keys being clobbered back to defaults by merge_defaults.
		// Mirrors handle_update_cpt's read-merge pattern.
		$existing = $plugin->post_fields->get( $slug, $key );
		if ( is_array( $existing ) ) {
			unset( $existing['connector_version'], $existing['created_at'], $existing['updated_at'] );
			$body = array_merge( $existing, $body );
		}

		// URL key wins.
		$body['key'] = $key;
		unset( $body['connector_version'] );

		$result = $plugin->post_fields->define( $slug, $body );
		if ( is_wp_error( $result ) ) {
			return $this->error_from_wp_error( $result );
		}

		$def = $plugin->post_fields->get( $slug, $key );
		return rest_ensure_response( $this->shape_post_field( $key, $def ) );
	}

	/**
	 * DELETE /cpts/{slug}/post-fields/{key} — remove a definition.
	 *
	 * Per-post stored values are intentionally NOT touched. They're
	 * orphaned until the post is next saved (which sweeps them) or until
	 * a future explicit cleanup endpoint lands. This matches the grouping
	 * delete behavior — preserves user data through accidental deletions.
	 */
	public function handle_delete_post_field( WP_REST_Request $request ) {
		$plugin = pcptpages();
		$slug   = sanitize_key( $this->get_url_param( $request, 'slug' ) );
		$key    = sanitize_key( $this->get_url_param( $request, 'key' ) );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pcptpages_cpt_not_found', __( 'CPT not found.', 'promptless-cpt-pages' ), 404 );
		}
		if ( ! $plugin->post_fields || ! $plugin->post_fields->exists( $slug, $key ) ) {
			return $this->error_response( 'pcptpages_post_field_not_found', __( 'Post field not found.', 'promptless-cpt-pages' ), 404 );
		}

		$plugin->post_fields->remove( $slug, $key );

		// 200 JSON envelope, not a bare 204 — see handle_delete_cpt.
		return rest_ensure_response(
			array(
				'deleted' => true,
				'slug'    => $slug,
				'key'     => $key,
			)
		);
	}

	/**
	 * POST /cpts/{slug}/post-fields/reorder — bulk reorder.
	 *
	 * Body shape: { "ordered_keys": ["price","status","beds","baths"] }
	 *
	 * The submitted list MUST contain exactly the set of currently-defined
	 * field keys — no additions, no removals, no duplicates. Validation
	 * happens in PCPTPages_Post_Field_Registry::reorder.
	 */
	public function handle_reorder_post_fields( WP_REST_Request $request ) {
		$plugin = pcptpages();
		$slug   = sanitize_key( $this->get_url_param( $request, 'slug' ) );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pcptpages_cpt_not_found', __( 'CPT not found.', 'promptless-cpt-pages' ), 404 );
		}
		if ( ! $plugin->post_fields ) {
			return $this->error_response( 'pcptpages_post_fields_unavailable', __( 'Post field registry not initialized.', 'promptless-cpt-pages' ), 500 );
		}

		$body = $this->parse_json_body( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$ordered = isset( $body['ordered_keys'] ) && is_array( $body['ordered_keys'] )
			? $body['ordered_keys']
			: array();

		$result = $plugin->post_fields->reorder( $slug, $ordered );
		if ( is_wp_error( $result ) ) {
			return $this->error_from_wp_error( $result );
		}

		// Return the new order as confirmation.
		$out = array();
		foreach ( $plugin->post_fields->get_all( $slug ) as $key => $def ) {
			$out[] = $this->shape_post_field( $key, $def );
		}
		return rest_ensure_response( array( 'post_fields' => $out ) );
	}

	/**
	 * GET /posts/{id}/field-values — read all field values for a post.
	 *
	 * Returns values keyed by field key. Composite types (rating, progress)
	 * surface as `{ value: ..., count: ... }` / `{ value: ..., goal: ... }`
	 * arrays so a consumer can read both halves in one call.
	 */
	public function handle_get_post_field_values( WP_REST_Request $request ) {
		$plugin  = pcptpages();
		$post_id = (int) $this->get_url_param( $request, 'id' );

		$err = $this->require_pcptpages_post( $post_id );
		if ( is_wp_error( $err ) ) {
			return $err;
		}

		$post   = get_post( $post_id );
		$values = $plugin->post_data ? $plugin->post_data->get_field_values( $post_id ) : array();

		return rest_ensure_response( array(
			'post_id'      => $post_id,
			'post_type'    => $post->post_type,
			'field_values' => $values,
		) );
	}

	/**
	 * PUT /posts/{id}/field-values — bulk write field values.
	 *
	 * Body shape: { "values": { "price": 1250000, "status": "for_sale", "beds": 3 } }
	 * For composite types: { "rating": { "value": 4.8, "count": 1243 } }
	 *
	 * Partial-update semantics: fields not present in the payload are
	 * unchanged. To clear a field, send an explicit null or empty string.
	 */
	public function handle_set_post_field_values( WP_REST_Request $request ) {
		$plugin  = pcptpages();
		$post_id = (int) $this->get_url_param( $request, 'id' );

		$err = $this->require_pcptpages_post( $post_id );
		if ( is_wp_error( $err ) ) {
			return $err;
		}

		$body = $this->parse_json_body( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		// Accept either { values: { ... } } (canonical) or a bare field
		// map at the root for caller convenience. Connector clients are
		// expected to use the canonical shape; the bare-map fallback
		// shouldn't be relied on long-term.
		$values = array();
		if ( isset( $body['values'] ) && is_array( $body['values'] ) ) {
			$values = $body['values'];
		} elseif ( ! isset( $body['values'] ) && ! empty( $body ) ) {
			$values = $body;
		}

		if ( ! is_array( $values ) || empty( $values ) ) {
			return $this->error_response(
				'pcptpages_empty_field_values',
				__( 'Body must include a non-empty "values" object mapping field keys to values.', 'promptless-cpt-pages' ),
				400
			);
		}

		$result = $plugin->post_data->set_field_values( $post_id, $values, 'connector' );
		if ( is_wp_error( $result ) ) {
			return $this->error_from_wp_error( $result );
		}

		// Return the resulting full value set so callers can confirm what landed.
		return rest_ensure_response( array(
			'post_id'      => $post_id,
			'field_values' => $plugin->post_data->get_field_values( $post_id ),
		) );
	}

	/**
	 * GET /posts/{id}/field-visibility — read per-post visibility overrides.
	 */
	public function handle_get_post_field_visibility( WP_REST_Request $request ) {
		$plugin  = pcptpages();
		$post_id = (int) $this->get_url_param( $request, 'id' );

		$err = $this->require_pcptpages_post( $post_id );
		if ( is_wp_error( $err ) ) {
			return $err;
		}

		$visibility = $plugin->post_data ? $plugin->post_data->get_field_visibility( $post_id ) : array();

		return rest_ensure_response( array(
			'post_id'    => $post_id,
			'visibility' => $visibility,
		) );
	}

	/**
	 * PUT /posts/{id}/field-visibility — write per-post visibility overrides.
	 *
	 * Body shape: { "visibility": { "price": { "card_hidden": true, "single_hidden": false } } }
	 *
	 * Full-replace semantics (replaces all visibility overrides on the
	 * post). Send an empty object to clear all overrides.
	 */
	public function handle_set_post_field_visibility( WP_REST_Request $request ) {
		$plugin  = pcptpages();
		$post_id = (int) $this->get_url_param( $request, 'id' );

		$err = $this->require_pcptpages_post( $post_id );
		if ( is_wp_error( $err ) ) {
			return $err;
		}

		$body = $this->parse_json_body( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$visibility = isset( $body['visibility'] ) && is_array( $body['visibility'] )
			? $body['visibility']
			: ( is_array( $body ) ? $body : array() );

		$result = $plugin->post_data->set_field_visibility( $post_id, $visibility, 'connector' );
		if ( is_wp_error( $result ) ) {
			return $this->error_from_wp_error( $result );
		}

		return rest_ensure_response( array(
			'post_id'    => $post_id,
			'visibility' => $plugin->post_data->get_field_visibility( $post_id ),
		) );
	}

	/**
	 * Shape a post field definition for the wire. Adds `key` to the
	 * response (storage uses the key as the array index, so it's not in
	 * the definition payload itself).
	 */
	private function shape_post_field( $key, array $def ) {
		return array_merge(
			array( 'key' => $key ),
			$def
		);
	}

	// ========================================================================
	// Helpers — error formatting
	// ========================================================================

	/**
	 * Build a WP_Error suitable for direct return from a REST handler.
	 *
	 * @param string $code    Stable code (matches CONNECTOR_SPEC.md catalogue).
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status.
	 * @param array  $extra   Optional extra data.
	 * @return WP_Error
	 */
	private function error_response( $code, $message, $status, array $extra = array() ) {
		return new WP_Error(
			$code,
			$message,
			array_merge( array( 'status' => $status ), $extra )
		);
	}

	/**
	 * Convert a validator-sourced WP_Error into a connector REST error
	 * with the right HTTP status. The validator returns the right code;
	 * we just need to add the status (default 422 for validation).
	 */
	private function error_from_wp_error( WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = isset( $data['status'] ) ? (int) $data['status'] : 422;

		return new WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			array_merge( is_array( $data ) ? $data : array(), array( 'status' => $status ) )
		);
	}
}
