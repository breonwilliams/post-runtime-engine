<?php
/**
 * Cowork connector authentication and authorization.
 *
 * Implements the three-check permission stack documented in
 * docs/CONNECTOR_SPEC.md §2:
 *
 *   1. Connector enabled toggle — else 403 connector_disabled
 *   2. is_user_logged_in()      — else 401 rest_not_logged_in
 *   3. capability check         — else 403 rest_forbidden
 *
 * Plus per-user per-route rate limiting via WP transients. Limits live in
 * self::RATE_LIMITS and are tuned per FRE's pattern: reads are generous,
 * writes are throttled, destructive operations are sharply limited.
 *
 * Mirrors FRE_Connector_Auth in form-runtime-engine. Differences:
 *   - No "entry read" secondary gate (PRE has no equivalent surface).
 *   - Capability check accepts a per-route capability so per-post
 *     endpoints can demand `edit_post` against a specific object,
 *     while site-config endpoints demand `manage_options`.
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connector auth and rate-limit enforcement.
 */
class PRE_Connector_Auth {

	/**
	 * Per-route-per-user rate limits, in requests per minute.
	 *
	 * Route keys are semantic identifiers chosen for readability — they
	 * match the keys passed to self::build_callback() when routes are
	 * registered. Keep in sync with docs/CONNECTOR_SPEC.md §3.
	 *
	 * Tier breakdown:
	 *   60/min — reads (list, get, preflight, introspection, preview)
	 *   30/min — light writes (set post groupings, create post)
	 *   10/min — config writes (register cpt, define grouping, update
	 *            cpt/grouping, set post groupings)
	 *    5/min — destructive operations (delete cpt, delete grouping)
	 *
	 * @var array<string,int>
	 */
	const RATE_LIMITS = array(
		// Reads (60/min).
		'preflight'           => 60,
		'list_cpts'           => 60,
		'get_cpt'             => 60,
		'list_groupings'      => 60,
		'get_grouping'        => 60,
		'get_post_groupings'  => 60,
		'preview_post'        => 60,
		'list_icons'          => 60,
		'list_variants'       => 60,
		'list_positions'      => 60,

		// Light writes (30/min).
		'set_post_groupings' => 30,
		'create_post'        => 30,
		'update_post'        => 30,

		// Config writes (10/min).
		'register_cpt'     => 10,
		'update_cpt'       => 10,
		'define_grouping'  => 10,
		'update_grouping'  => 10,

		// Destructive (5/min).
		'delete_cpt'      => 5,
		'delete_grouping' => 5,
	);

	/**
	 * Default limit when the route key is unknown — intentionally strict.
	 *
	 * Reaching this branch means a new route was wired up but its rate
	 * limit was not added to RATE_LIMITS. The fallback keeps the site
	 * safe while the omission is noticed and fixed.
	 *
	 * @var int
	 */
	const DEFAULT_RATE_LIMIT = 10;

	/**
	 * Build the standard permission callback for a connector route.
	 *
	 * Returns a closure suitable for register_rest_route's
	 * `permission_callback`. Runs the three-check stack and the rate
	 * limit check for the given route key.
	 *
	 * @param string      $route_key  Route identifier for rate-limit bucketing.
	 *                                Must exist in self::RATE_LIMITS.
	 * @param string|null $capability Capability to check. Null defaults to
	 *                                PRE_Capabilities::MANAGE_CAP.
	 * @return callable Closure for permission_callback.
	 */
	public static function build_callback( $route_key, $capability = null ) {
		return function ( $request ) use ( $route_key, $capability ) {
			return self::run_permission_stack( $route_key, $capability, $request );
		};
	}

	/**
	 * Build a per-post permission callback. Runs the same three-check
	 * stack but resolves the capability against a specific post ID
	 * pulled from the URL. Used for endpoints that operate on one post
	 * (e.g. PUT /posts/{id}/groupings → must can edit_post({id})).
	 *
	 * @param string $route_key Route identifier.
	 * @return callable
	 */
	public static function build_per_post_callback( $route_key ) {
		return function ( $request ) use ( $route_key ) {
			// SECURITY: read $post_id from the URL pattern explicitly.
			// $request->get_param( 'id' ) would resolve a JSON body
			// field named 'id' BEFORE the URL pattern match — for
			// application/json content-type, WP's parameter-resolution
			// order is JSON → POST → GET → URL. If body could override
			// the URL id, an authenticated user could pass the auth
			// gate against post X (one they own via edit_post) while
			// the handler operates on post Y from the URL — or vice
			// versa, depending on which read happened first. Reading
			// get_url_params() directly forecloses that bypass.
			$url_params = $request->get_url_params();
			$post_id    = isset( $url_params['id'] ) ? (int) $url_params['id'] : 0;

			return self::run_permission_stack(
				$route_key,
				array(
					'cap'     => 'edit_post',
					'meta_id' => $post_id,
				),
				$request
			);
		};
	}

	/**
	 * Run the full permission stack.
	 *
	 * Separate from build_callback() so tests can call it directly
	 * without building a closure.
	 *
	 * @param string                            $route_key  Rate-limit bucket.
	 * @param string|array|null                 $capability Capability spec. Null =
	 *                                          PRE_Capabilities::MANAGE_CAP. String =
	 *                                          single cap. Array = ['cap' => cap,
	 *                                          'meta_id' => post_id] for
	 *                                          map_meta_cap-style checks.
	 * @param WP_REST_Request                   $request    REST request.
	 * @return true|WP_Error True on pass, WP_Error on any check failure.
	 */
	public static function run_permission_stack( $route_key, $capability, $request ) {
		// Gate 1: connector enabled site-wide.
		if ( ! PRE_Connector_Settings::is_enabled() ) {
			return new WP_Error(
				'connector_disabled',
				__( 'The Post Runtime Engine connector is not enabled on this site. A site administrator can enable it in Post Runtime → Connector settings.', 'post-runtime-engine' ),
				array( 'status' => 403 )
			);
		}

		// Gate 2: must be authenticated. Application Passwords grant a
		// real WP user session that satisfies is_user_logged_in().
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'Authentication required. Use a WordPress Application Password generated through the Connector settings page.', 'post-runtime-engine' ),
				array( 'status' => 401 )
			);
		}

		// Gate 3: capability check. Resolve the capability spec into an
		// actual current_user_can() call.
		$cap_check = self::check_capability( $capability );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		// Rate limit (after capability — don't waste rate-limit budget
		// on requests that would have failed auth anyway).
		$rate_result = self::enforce_rate_limit( $route_key, get_current_user_id() );
		if ( is_wp_error( $rate_result ) ) {
			return $rate_result;
		}

		return true;
	}

	/**
	 * Resolve a capability spec into a current_user_can() check.
	 *
	 * @param string|array|null $capability Spec.
	 * @return true|WP_Error
	 */
	private static function check_capability( $capability ) {
		// Default: site-config capability.
		if ( $capability === null ) {
			$capability = PRE_Capabilities::MANAGE_CAP;
		}

		// Per-post: ['cap' => 'edit_post', 'meta_id' => 123].
		if ( is_array( $capability ) && isset( $capability['cap'] ) ) {
			$cap     = $capability['cap'];
			$meta_id = isset( $capability['meta_id'] ) ? (int) $capability['meta_id'] : 0;

			if ( $meta_id <= 0 || ! current_user_can( $cap, $meta_id ) ) {
				return new WP_Error(
					'rest_forbidden',
					__( 'Your account does not have permission to perform this action on this post.', 'post-runtime-engine' ),
					array(
						'status'              => 403,
						'required_capability' => $cap,
					)
				);
			}
			return true;
		}

		// Simple capability string.
		if ( is_string( $capability ) ) {
			if ( ! current_user_can( $capability ) ) {
				return new WP_Error(
					'rest_forbidden',
					__( 'Your account does not have permission to access the connector.', 'post-runtime-engine' ),
					array(
						'status'              => 403,
						'required_capability' => $capability,
					)
				);
			}
			return true;
		}

		// Malformed spec — fail safe.
		return new WP_Error(
			'rest_forbidden',
			__( 'Connector permission misconfigured.', 'post-runtime-engine' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Enforce per-user per-route rate limiting.
	 *
	 * Fixed-window counter stored as a transient. When the counter hits
	 * the route's limit, the transient's remaining TTL becomes the
	 * Retry-After value returned to the caller.
	 *
	 * @param string $route_key Route identifier.
	 * @param int    $user_id   Authenticated user's ID.
	 * @return true|WP_Error
	 */
	public static function enforce_rate_limit( $route_key, $user_id ) {
		$limit = self::RATE_LIMITS[ $route_key ] ?? self::DEFAULT_RATE_LIMIT;

		/**
		 * Filter per-route rate limits at runtime. Site operators with
		 * unusual needs (high-volume agent automation, multiple
		 * concurrent agents) can loosen the bucket via this filter.
		 *
		 * @param int    $limit     Requests per minute.
		 * @param string $route_key Route identifier.
		 * @param int    $user_id   Authenticated user's ID.
		 */
		$limit = (int) apply_filters( 'pre_connector_rate_limit', $limit, $route_key, $user_id );

		$transient_key = 'pre_connector_rate_' . sanitize_key( $route_key ) . '_' . (int) $user_id;

		$current = get_transient( $transient_key );

		if ( false === $current ) {
			set_transient( $transient_key, 1, MINUTE_IN_SECONDS );
			return true;
		}

		$current = (int) $current;

		if ( $current >= $limit ) {
			$retry_after = self::get_transient_retry_after( $transient_key );

			return new WP_Error(
				'rate_limit_exceeded',
				sprintf(
					/* translators: 1: limit per minute, 2: route identifier */
					__( 'Rate limit exceeded for this connector endpoint (%1$d requests/minute on "%2$s"). Retry after a moment.', 'post-runtime-engine' ),
					$limit,
					$route_key
				),
				array(
					'status'      => 429,
					'retry_after' => $retry_after,
					'route'       => $route_key,
					'limit'       => $limit,
				)
			);
		}

		set_transient( $transient_key, $current + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Read remaining TTL for a transient. WordPress doesn't expose this
	 * directly, so we read the underlying option timeout row.
	 *
	 * @param string $transient_key Transient name (without prefix).
	 * @return int Seconds until expiry. Conservative fallback: 60.
	 */
	private static function get_transient_retry_after( $transient_key ) {
		$timeout = (int) get_option( '_transient_timeout_' . $transient_key );
		if ( 0 === $timeout ) {
			return MINUTE_IN_SECONDS;
		}
		$remaining = $timeout - time();
		return $remaining > 0 ? $remaining : 1;
	}
}
