<?php
/**
 * Cowork connector REST API.
 *
 * Registers all routes under /wp-json/post-runtime/v1/connector/*.
 * Delegates to existing plugin subsystems:
 *
 *   - PRE_CPT_Registry      for CPT CRUD
 *   - PRE_Grouping_Registry for grouping CRUD
 *   - PRE_Post_Data         for per-post grouping read/write
 *   - PRE_Validator         for shape validation (called transitively)
 *   - PRE_Icon_Library      for the icon catalogue
 *   - PRE_Renderer          for the preview endpoint
 *
 * Owns:
 *   - Route registration (self::register_routes)
 *   - Request parsing + response shaping
 *   - Argument validation via register_rest_route's $args
 *
 * Does NOT own:
 *   - Permission decisions   — see PRE_Connector_Auth
 *   - Storage                — see PRE_CPT_Registry, PRE_Post_Data
 *   - Validation rules       — see PRE_Validator
 *
 * Contract documented in docs/CONNECTOR_SPEC.md.
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connector REST controller.
 */
class PRE_Connector_API {

	/**
	 * Hook registration. Wires register_routes() to rest_api_init.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all connector routes.
	 *
	 * Every route delegates to PRE_Connector_Auth::build_callback() for
	 * the auth + rate-limit stack. Per-post routes use
	 * build_per_post_callback() for object-level capability checks.
	 */
	public function register_routes() {
		$ns   = PRE_REST_NAMESPACE;
		$base = PRE_REST_BASE;

		// ----- Preflight + introspection (5 routes) -----

		register_rest_route( $ns, "/{$base}/preflight", array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_preflight' ),
			'permission_callback' => PRE_Connector_Auth::build_callback( 'preflight' ),
		) );

		register_rest_route( $ns, "/{$base}/icons", array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_list_icons' ),
			'permission_callback' => PRE_Connector_Auth::build_callback( 'list_icons' ),
		) );

		register_rest_route( $ns, "/{$base}/variants", array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_list_variants' ),
			'permission_callback' => PRE_Connector_Auth::build_callback( 'list_variants' ),
		) );

		register_rest_route( $ns, "/{$base}/positions", array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_list_positions' ),
			'permission_callback' => PRE_Connector_Auth::build_callback( 'list_positions' ),
		) );

		// ----- CPTs (5 routes) -----

		register_rest_route( $ns, "/{$base}/cpts", array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list_cpts' ),
				'permission_callback' => PRE_Connector_Auth::build_callback( 'list_cpts' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_register_cpt' ),
				'permission_callback' => PRE_Connector_Auth::build_callback( 'register_cpt' ),
			),
		) );

		register_rest_route( $ns, "/{$base}/cpts/(?P<slug>[a-z0-9_]+)", array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_cpt' ),
				'permission_callback' => PRE_Connector_Auth::build_callback( 'get_cpt' ),
				'args'                => $this->cpt_slug_arg(),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'handle_update_cpt' ),
				'permission_callback' => PRE_Connector_Auth::build_callback( 'update_cpt' ),
				'args'                => $this->cpt_slug_arg(),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'handle_delete_cpt' ),
				'permission_callback' => PRE_Connector_Auth::build_callback( 'delete_cpt' ),
				'args'                => $this->cpt_slug_arg(),
			),
		) );

		// ----- Groupings (5 routes) -----

		register_rest_route( $ns, "/{$base}/cpts/(?P<slug>[a-z0-9_]+)/groupings", array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list_groupings' ),
				'permission_callback' => PRE_Connector_Auth::build_callback( 'list_groupings' ),
				'args'                => $this->cpt_slug_arg(),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_define_grouping' ),
				'permission_callback' => PRE_Connector_Auth::build_callback( 'define_grouping' ),
				'args'                => $this->cpt_slug_arg(),
			),
		) );

		register_rest_route( $ns, "/{$base}/cpts/(?P<slug>[a-z0-9_]+)/groupings/(?P<key>[a-z0-9_]+)", array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_grouping' ),
				'permission_callback' => PRE_Connector_Auth::build_callback( 'get_grouping' ),
				'args'                => $this->cpt_slug_and_key_args(),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'handle_update_grouping' ),
				'permission_callback' => PRE_Connector_Auth::build_callback( 'update_grouping' ),
				'args'                => $this->cpt_slug_and_key_args(),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'handle_delete_grouping' ),
				'permission_callback' => PRE_Connector_Auth::build_callback( 'delete_grouping' ),
				'args'                => $this->cpt_slug_and_key_args(),
			),
		) );

		// ----- Post groupings (2 routes — per-post auth) -----

		register_rest_route( $ns, "/{$base}/posts/(?P<id>\d+)/groupings", array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_post_groupings' ),
				'permission_callback' => PRE_Connector_Auth::build_per_post_callback( 'get_post_groupings' ),
				'args'                => $this->post_id_arg(),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'handle_set_post_groupings' ),
				'permission_callback' => PRE_Connector_Auth::build_per_post_callback( 'set_post_groupings' ),
				'args'                => $this->post_id_arg(),
			),
		) );

		// ----- Post create + preview -----

		register_rest_route( $ns, "/{$base}/posts", array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_create_post' ),
			'permission_callback' => PRE_Connector_Auth::build_callback( 'create_post' ),
		) );

		register_rest_route( $ns, "/{$base}/posts/(?P<id>\d+)/preview", array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_preview_post' ),
			'permission_callback' => PRE_Connector_Auth::build_per_post_callback( 'preview_post' ),
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
		$plugin = pre();

		$registered_cpts = array();
		if ( $plugin->cpts ) {
			foreach ( $plugin->cpts->get_all() as $slug => $_def ) {
				$registered_cpts[] = $slug;
			}
		}

		$user = wp_get_current_user();

		return rest_ensure_response( array(
			'plugin_version'   => PRE_VERSION,
			'data_version'     => PRE_DATA_VERSION,
			'wp_version'       => get_bloginfo( 'version' ),
			'rest_namespace'   => PRE_REST_NAMESPACE,
			'rest_base'        => '/wp-json/' . PRE_REST_NAMESPACE . '/' . PRE_REST_BASE,
			'user'             => array(
				'id'                   => (int) $user->ID,
				'login'                => $user->user_login,
				'can_manage_cpts'      => current_user_can( PRE_Capabilities::MANAGE_CAP ),
				'can_manage_groupings' => current_user_can( PRE_Capabilities::MANAGE_CAP ),
			),
			'registered_cpts'  => $registered_cpts,
			// Promptless WP defines AISB_MODERN_VERSION; this flag tells the
			// agent whether the design tokens will inherit. When false, the
			// agent may want to warn the operator that the documented
			// fallbacks will be used instead.
			'promptless_active' => defined( 'AISB_MODERN_VERSION' ),
			'promptless_version' => defined( 'AISB_MODERN_VERSION' ) ? AISB_MODERN_VERSION : null,
		) );
	}

	/**
	 * GET /icons — full icon catalogue (without SVG markup, to keep
	 * payload size sane). Agents that need the SVG render the post or
	 * fetch the catalogue at template-time, not at planning-time.
	 */
	public function handle_list_icons( WP_REST_Request $request ) {
		$icons       = array();
		$cat_seen    = array();

		foreach ( PRE_Icon_Library::get_all() as $id => $icon ) {
			$icons[] = array(
				'id'       => $id,
				'label'    => $icon['label'],
				'category' => $icon['category'],
				'tags'     => $icon['tags'],
			);
			$cat_seen[ $icon['category'] ] = true;
		}

		return rest_ensure_response( array(
			'icons'      => $icons,
			'categories' => array_keys( $cat_seen ),
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
		$plugin = pre();
		if ( ! $plugin->cpts ) {
			return $this->error_response( 'pre_internal_error', __( 'CPT registry not initialized.', 'post-runtime-engine' ), 500 );
		}

		$cpts = array();
		foreach ( $plugin->cpts->get_all() as $slug => $def ) {
			$cpts[] = $this->shape_cpt( $slug, $def );
		}

		return rest_ensure_response( array( 'cpts' => $cpts ) );
	}

	public function handle_register_cpt( WP_REST_Request $request ) {
		$plugin = pre();
		if ( ! $plugin->cpts ) {
			return $this->error_response( 'pre_internal_error', __( 'CPT registry not initialized.', 'post-runtime-engine' ), 500 );
		}

		$body = $this->parse_json_body( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$slug = isset( $body['slug'] ) ? sanitize_key( $body['slug'] ) : '';
		if ( $slug === '' ) {
			return $this->error_response( 'pre_invalid_slug', __( 'Missing or invalid slug.', 'post-runtime-engine' ), 422 );
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
		$plugin = pre();
		$slug   = sanitize_key( $request->get_param( 'slug' ) );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pre_cpt_not_found', __( 'CPT not found.', 'post-runtime-engine' ), 404 );
		}

		$def = $plugin->cpts->get( $slug );
		return rest_ensure_response( $this->shape_cpt( $slug, $def ) );
	}

	public function handle_update_cpt( WP_REST_Request $request ) {
		$plugin = pre();
		$slug   = sanitize_key( $request->get_param( 'slug' ) );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pre_cpt_not_found', __( 'CPT not found.', 'post-runtime-engine' ), 404 );
		}

		$body = $this->parse_json_body( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		// Slug is immutable.
		if ( isset( $body['slug'] ) && sanitize_key( $body['slug'] ) !== $slug ) {
			return $this->error_response(
				'pre_immutable_field',
				__( 'CPT slug is immutable. Delete and re-register to rename.', 'post-runtime-engine' ),
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

		// Pass through to registry.
		unset( $body['slug'], $body['connector_version'], $body['created_at'], $body['updated_at'] );
		$result = $plugin->cpts->register( $slug, $body );
		if ( is_wp_error( $result ) ) {
			return $this->error_from_wp_error( $result );
		}

		$def = $plugin->cpts->get( $slug );
		return rest_ensure_response( $this->shape_cpt( $slug, $def ) );
	}

	public function handle_delete_cpt( WP_REST_Request $request ) {
		$plugin     = pre();
		$slug       = sanitize_key( $request->get_param( 'slug' ) );
		$purge_data = (bool) $request->get_param( 'purge_data' );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pre_cpt_not_found', __( 'CPT not found.', 'post-runtime-engine' ), 404 );
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
				delete_post_meta( $post_id, '_pre_groupings' );
				delete_post_meta( $post_id, '_pre_groupings_backup' );
			}
		}

		$plugin->cpts->unregister( $slug );

		return new WP_REST_Response( null, 204 );
	}

	// ========================================================================
	// Handlers — groupings
	// ========================================================================

	public function handle_list_groupings( WP_REST_Request $request ) {
		$plugin = pre();
		$slug   = sanitize_key( $request->get_param( 'slug' ) );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pre_cpt_not_found', __( 'CPT not found.', 'post-runtime-engine' ), 404 );
		}

		$groupings = array();
		foreach ( $plugin->groupings->get_all( $slug ) as $key => $def ) {
			$groupings[] = $this->shape_grouping( $key, $def );
		}

		return rest_ensure_response( array( 'groupings' => $groupings ) );
	}

	public function handle_define_grouping( WP_REST_Request $request ) {
		$plugin = pre();
		$slug   = sanitize_key( $request->get_param( 'slug' ) );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pre_cpt_not_found', __( 'CPT not found.', 'post-runtime-engine' ), 404 );
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
		$plugin = pre();
		$slug   = sanitize_key( $request->get_param( 'slug' ) );
		$key    = sanitize_key( $request->get_param( 'key' ) );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pre_cpt_not_found', __( 'CPT not found.', 'post-runtime-engine' ), 404 );
		}
		if ( ! $plugin->groupings->exists( $slug, $key ) ) {
			return $this->error_response( 'pre_grouping_not_found', __( 'Grouping not found.', 'post-runtime-engine' ), 404 );
		}

		$def = $plugin->groupings->get( $slug, $key );
		return rest_ensure_response( $this->shape_grouping( $key, $def ) );
	}

	public function handle_update_grouping( WP_REST_Request $request ) {
		$plugin = pre();
		$slug   = sanitize_key( $request->get_param( 'slug' ) );
		$key    = sanitize_key( $request->get_param( 'key' ) );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pre_cpt_not_found', __( 'CPT not found.', 'post-runtime-engine' ), 404 );
		}
		if ( ! $plugin->groupings->exists( $slug, $key ) ) {
			return $this->error_response( 'pre_grouping_not_found', __( 'Grouping not found.', 'post-runtime-engine' ), 404 );
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
		$plugin = pre();
		$slug   = sanitize_key( $request->get_param( 'slug' ) );
		$key    = sanitize_key( $request->get_param( 'key' ) );

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $slug ) ) {
			return $this->error_response( 'pre_cpt_not_found', __( 'CPT not found.', 'post-runtime-engine' ), 404 );
		}
		if ( ! $plugin->groupings->exists( $slug, $key ) ) {
			return $this->error_response( 'pre_grouping_not_found', __( 'Grouping not found.', 'post-runtime-engine' ), 404 );
		}

		$plugin->groupings->remove( $slug, $key );

		return new WP_REST_Response( null, 204 );
	}

	// ========================================================================
	// Handlers — post groupings
	// ========================================================================

	public function handle_get_post_groupings( WP_REST_Request $request ) {
		$plugin  = pre();
		$post_id = (int) $request->get_param( 'id' );

		$err = $this->require_pre_post( $post_id );
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
		$plugin  = pre();
		$post_id = (int) $request->get_param( 'id' );

		$err = $this->require_pre_post( $post_id );
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
		$plugin = pre();

		$body = $this->parse_json_body( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$post_type = isset( $body['post_type'] ) ? sanitize_key( $body['post_type'] ) : '';

		if ( $post_type === '' || ! $plugin->cpts || ! $plugin->cpts->exists( $post_type ) ) {
			return $this->error_response(
				'pre_unregistered_post_type',
				__( 'post_type must be a CPT registered through Post Runtime Engine.', 'post-runtime-engine' ),
				422
			);
		}

		// Per-CPT capability check (the route-level callback only checked
		// site-wide create_post; tighten to the actual CPT's publish cap).
		if ( ! current_user_can( PRE_Capabilities::publish_cap_for( $post_type ) ) ) {
			return $this->error_response(
				'rest_forbidden',
				__( 'Your account cannot publish posts of this type.', 'post-runtime-engine' ),
				403
			);
		}

		$title = isset( $body['post_title'] ) ? wp_strip_all_tags( (string) $body['post_title'] ) : '';
		if ( $title === '' ) {
			return $this->error_response( 'pre_missing_post_title', __( 'post_title is required.', 'post-runtime-engine' ), 422 );
		}

		$insert_args = array(
			'post_type'    => $post_type,
			'post_status'  => isset( $body['post_status'] ) ? sanitize_key( $body['post_status'] ) : 'draft',
			'post_title'   => $title,
			'post_excerpt' => isset( $body['post_excerpt'] ) ? wp_kses_post( (string) $body['post_excerpt'] ) : '',
			'post_content' => isset( $body['post_content'] ) ? wp_kses_post( (string) $body['post_content'] ) : '',
		);

		$post_id = wp_insert_post( $insert_args, true );
		if ( is_wp_error( $post_id ) ) {
			return $this->error_response( 'pre_post_create_failed', $post_id->get_error_message(), 500 );
		}

		$warnings = array();

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

	public function handle_preview_post( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'id' );

		$err = $this->require_pre_post( $post_id );
		if ( is_wp_error( $err ) ) {
			return $err;
		}

		$post = get_post( $post_id );

		// Render through PRE_Renderer with output buffering so we
		// capture the article HTML without theme chrome. Pass $use_cache
		// = false: the connector preview is for agents verifying their
		// just-written changes, so a cached version from before the
		// write would be misleading.
		ob_start();
		setup_postdata( $post );
		( new PRE_Renderer() )->render( $post, false );
		wp_reset_postdata();
		$html = ob_get_clean();

		return rest_ensure_response( array(
			'post_id'   => $post_id,
			'html'      => $html,
			'css_url'   => PRE_PLUGIN_URL . 'assets/css/frontend.css?ver=' . PRE_VERSION,
			'permalink' => get_permalink( $post_id ),
		) );
	}

	// ========================================================================
	// Helpers — argument schemas
	// ========================================================================

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
					'pre_unsupported_media_type',
					__( 'Content-Type must be application/json.', 'post-runtime-engine' ),
					415
				);
			}
			return $body;
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return $this->error_response(
				'pre_invalid_json',
				__( 'Request body is not valid JSON.', 'post-runtime-engine' ),
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
				'pre_version_conflict',
				sprintf(
					/* translators: 1: server version, 2: submitted version */
					__( 'Version conflict — server has %1$d, you sent %2$d. Re-read the resource and retry.', 'post-runtime-engine' ),
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
	private function require_pre_post( $post_id ) {
		$plugin = pre();
		$post   = get_post( $post_id );

		if ( ! $post ) {
			return $this->error_response( 'pre_post_not_found', __( 'Post not found.', 'post-runtime-engine' ), 404 );
		}

		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $post->post_type ) ) {
			return $this->error_response(
				'pre_unregistered_post_type',
				__( 'This post is not of a Post Runtime Engine CPT.', 'post-runtime-engine' ),
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
