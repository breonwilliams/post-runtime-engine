<?php
/**
 * Post field definition registry for Promptless CPT Pages (v1.1).
 *
 * Each CPT can have one or more named "post fields" — scalar (non-repeatable)
 * fields with a closed enum of display types and positions. Definitions live
 * in their own options keyed by CPT slug (`pcptpages_post_fields_{cpt_slug}`),
 * parallel to the existing `pcptpages_groupings_{cpt_slug}` storage pattern used
 * by PCPTPages_Grouping_Registry.
 *
 * Distinct from groupings:
 *   - Groupings are repeatable with a fixed item shape (image-or-icon,
 *     heading, supporting_text, link). One grouping holds many items.
 *   - Post fields are scalar — one value per field per post. Multiple fields
 *     can occupy the same render position (e.g., several meta_strip items).
 *
 * Per-post values for these fields live in their own post meta entries
 * (one per field, keyed `_pcptpages_field_{field_key}`), not in a serialized blob.
 * Per-post visibility overrides live in `_pcptpages_field_visibility`. Both are
 * managed by PCPTPages_Post_Data; this class only owns the field DEFINITIONS.
 *
 * Design contract: docs/POST_FIELDS_V1_1_DESIGN.md § 5.
 *
 * @package PostRuntimeEngine
 * @since 1.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and reads post field definitions per CPT.
 */
class PCPTPages_Post_Field_Registry {

	/**
	 * Option key prefix. The actual option for a CPT is
	 * `pcptpages_post_fields_{cpt_slug}`.
	 *
	 * Parallel to PCPTPages_Grouping_Registry::OPTION_PREFIX (`pcptpages_groupings_`).
	 */
	const OPTION_PREFIX = 'pcptpages_post_fields_';

	/**
	 * Validator instance.
	 *
	 * @var PCPTPages_Validator
	 */
	private $validator;

	/**
	 * Memoized definitions, keyed by CPT slug. Each entry is an
	 * ORDERED associative array of `field_key => definition`. Order
	 * matters: it determines render order when multiple fields share a
	 * position.
	 *
	 * @var array<string,array<string,array>>
	 */
	private $cache = array();

	/**
	 * Constructor.
	 *
	 * @param PCPTPages_Validator|null $validator Optional validator dependency.
	 */
	public function __construct( $validator = null ) {
		$this->validator = $validator ?: new PCPTPages_Validator();
	}

	/**
	 * Get all post field definitions for a CPT, in definition order.
	 *
	 * Order is significant: render order within a position follows the
	 * order returned here. Reorder via `reorder()`.
	 *
	 * @param string $cpt_slug CPT slug.
	 * @return array<string,array> Empty array if the CPT has no post fields yet.
	 */
	public function get_all( $cpt_slug ) {
		$cpt_slug = sanitize_key( $cpt_slug );
		if ( $cpt_slug === '' ) {
			return array();
		}

		if ( isset( $this->cache[ $cpt_slug ] ) ) {
			return $this->cache[ $cpt_slug ];
		}

		$stored = get_option( self::OPTION_PREFIX . $cpt_slug, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$this->cache[ $cpt_slug ] = $stored;
		return $stored;
	}

	/**
	 * Get a single post field definition.
	 *
	 * @param string $cpt_slug  CPT slug.
	 * @param string $field_key Field key.
	 * @return array|null Definition or null if not defined.
	 */
	public function get( $cpt_slug, $field_key ) {
		$field_key = sanitize_key( $field_key );
		if ( $field_key === '' ) {
			return null;
		}

		$all = $this->get_all( $cpt_slug );
		return isset( $all[ $field_key ] ) ? $all[ $field_key ] : null;
	}

	/**
	 * Check whether a post field is defined for a CPT.
	 *
	 * @param string $cpt_slug  CPT slug.
	 * @param string $field_key Field key.
	 * @return bool
	 */
	public function exists( $cpt_slug, $field_key ) {
		return $this->get( $cpt_slug, $field_key ) !== null;
	}

	/**
	 * Define a post field for a CPT, creating it or updating an existing one.
	 *
	 * New fields are appended to the end of the definition list. Existing
	 * fields are updated in place (order preserved). To change order, use
	 * `reorder()` after defining.
	 *
	 * Enforces the hard field-count cap (`PCPTPages_Validator::HARD_FIELD_COUNT_LIMIT`,
	 * default 12). Returns WP_Error on attempt to exceed.
	 *
	 * @param string $cpt_slug   CPT slug. Must be a registered CPT.
	 * @param array  $definition Field definition. The `key` field is required
	 *                            and is also the array key in storage.
	 * @return true|WP_Error
	 */
	public function define( $cpt_slug, array $definition ) {
		$cpt_slug = sanitize_key( $cpt_slug );
		if ( $cpt_slug === '' ) {
			return new WP_Error(
				'pcptpages_invalid_cpt_slug',
				__( 'CPT slug is empty or invalid.', 'promptless-cpt-pages' )
			);
		}

		// Optional CPT-existence check: matches PCPTPages_Grouping_Registry's
		// behavior of permitting field definitions ahead of CPT
		// registration (connector-driven setups commonly push the two
		// together). The action below carries the cpt_exists flag so
		// downstream listeners can react.
		$cpt_exists = $this->is_cpt_registered( $cpt_slug );

		$valid = $this->validator->validate_post_field_definition( $definition, $cpt_slug );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$definition = $this->merge_defaults( $definition );
		$field_key  = $definition['key'];

		$all      = $this->get_all( $cpt_slug );
		$existing = isset( $all[ $field_key ] ) ? $all[ $field_key ] : null;

		// Semantic-role uniqueness (events vertical, v1.2). A given event
		// role (event_start, event_end, etc.) may be mapped at most once per
		// CPT so the query + schema layers can resolve it unambiguously.
		// Re-saving the SAME field that already holds the role is fine.
		if ( ! empty( $definition['semantic_role'] ) ) {
			foreach ( $all as $other_key => $other_def ) {
				if ( $other_key === $field_key ) {
					continue;
				}
				if ( ( $other_def['semantic_role'] ?? '' ) === $definition['semantic_role'] ) {
					return new WP_Error(
						'pcptpages_duplicate_semantic_role',
						sprintf(
							/* translators: %1$s: role, %2$s: field key already holding it */
							__( 'Semantic role %1$s is already mapped to field %2$s on this CPT. Each role may be mapped once.', 'promptless-cpt-pages' ),
							$definition['semantic_role'],
							$other_key
						)
					);
				}
			}
		}

		// Field count cap enforcement. Only applies when ADDING a new
		// field — updating an existing field doesn't change the count.
		if ( $existing === null ) {
			$limit = $this->get_max_field_count();
			if ( count( $all ) >= $limit ) {
				return new WP_Error(
					'pcptpages_max_field_count_exceeded',
					sprintf(
						/* translators: %d: the field count limit */
						__( 'Cannot add another post field. The hard limit of %d post fields per CPT has been reached. Remove an unused field to add a new one.', 'promptless-cpt-pages' ),
						$limit
					),
					array(
						'limit'   => $limit,
						'current' => count( $all ),
					)
				);
			}
		}

		$now = current_time( 'mysql', true );

		$definition['created_at']        = $existing['created_at'] ?? $now;
		$definition['updated_at']        = $now;
		$definition['connector_version'] = isset( $existing['connector_version'] )
			? (int) $existing['connector_version'] + 1
			: 1;

		$all[ $field_key ] = $definition;

		$saved = update_option( self::OPTION_PREFIX . $cpt_slug, $all );
		$this->cache[ $cpt_slug ] = $all;

		// update_option returns false when the value didn't change. That's
		// not a failure for us (re-saving an identical definition is a
		// no-op success). The defensive read confirms persistence.
		if ( ! $saved && get_option( self::OPTION_PREFIX . $cpt_slug ) !== $all ) {
			return new WP_Error(
				'pcptpages_post_field_save_failed',
				__( 'Failed to persist post field definition to the database.', 'promptless-cpt-pages' )
			);
		}

		/**
		 * Fires after a post field definition is created or updated.
		 *
		 * @param string $cpt_slug   CPT slug.
		 * @param string $field_key  Field key.
		 * @param array  $definition Stored definition.
		 * @param bool   $is_new     True if this is a fresh definition.
		 * @param bool   $cpt_exists Whether the parent CPT is registered.
		 *                           False indicates the field was defined
		 *                           ahead of its CPT (legal but unusual).
		 */
		do_action( 'pcptpages_post_field_defined', $cpt_slug, $field_key, $definition, $existing === null, $cpt_exists );

		return true;
	}

	/**
	 * Remove a post field definition. Does not touch per-post field values;
	 * the orphaned `_pcptpages_field_{key}` meta entries remain on existing posts
	 * until the post is next saved, at which point PCPTPages_Post_Data cleans
	 * them up (or they can be cleaned via a manual sweep).
	 *
	 * @param string $cpt_slug  CPT slug.
	 * @param string $field_key Field key.
	 * @return true|WP_Error
	 */
	public function remove( $cpt_slug, $field_key ) {
		$cpt_slug  = sanitize_key( $cpt_slug );
		$field_key = sanitize_key( $field_key );

		if ( $cpt_slug === '' || $field_key === '' ) {
			return new WP_Error(
				'pcptpages_invalid_args',
				__( 'CPT slug and field key are required.', 'promptless-cpt-pages' )
			);
		}

		$all = $this->get_all( $cpt_slug );
		if ( ! isset( $all[ $field_key ] ) ) {
			return new WP_Error(
				'pcptpages_post_field_not_found',
				sprintf(
					/* translators: %1$s: field key, %2$s: CPT slug */
					__( 'Post field %1$s is not defined for CPT %2$s.', 'promptless-cpt-pages' ),
					$field_key,
					$cpt_slug
				)
			);
		}

		$removed_definition = $all[ $field_key ];
		unset( $all[ $field_key ] );

		if ( empty( $all ) ) {
			// Clean up the option entirely when no fields remain.
			delete_option( self::OPTION_PREFIX . $cpt_slug );
		} else {
			update_option( self::OPTION_PREFIX . $cpt_slug, $all );
		}

		$this->cache[ $cpt_slug ] = $all;

		/**
		 * Fires after a post field definition is removed.
		 *
		 * @param string $cpt_slug           CPT slug.
		 * @param string $field_key          Removed field key.
		 * @param array  $removed_definition The definition that was removed,
		 *                                   for listeners that need to clean
		 *                                   up related state.
		 */
		do_action( 'pcptpages_post_field_removed', $cpt_slug, $field_key, $removed_definition );

		return true;
	}

	/**
	 * Remove all post field definitions for a CPT. Called automatically when
	 * a CPT is unregistered via PCPTPages_CPT_Registry::unregister(). Mirrors the
	 * behavior of PCPTPages_Grouping_Registry::remove_all_for_cpt().
	 *
	 * Does not touch per-post field-value post meta. Orphaned values clean
	 * up on next save or via manual sweep.
	 *
	 * @param string $cpt_slug CPT slug.
	 * @return bool True if the option was deleted; false if it didn't exist.
	 */
	public function remove_all_for_cpt( $cpt_slug ) {
		$cpt_slug = sanitize_key( $cpt_slug );
		if ( $cpt_slug === '' ) {
			return false;
		}

		unset( $this->cache[ $cpt_slug ] );
		return delete_option( self::OPTION_PREFIX . $cpt_slug );
	}

	/**
	 * Reorder the fields for a CPT.
	 *
	 * Accepts an ordered list of field keys representing the new order.
	 * The list MUST contain exactly the set of currently-defined field keys
	 * — no additions, no removals, no duplicates. Use `define()` and
	 * `remove()` for content changes.
	 *
	 * Order matters for render output: when multiple fields share a position
	 * (e.g., three items in meta_strip), they render in the order returned
	 * by `get_all()`, which is the order written by this method.
	 *
	 * @param string   $cpt_slug    CPT slug.
	 * @param string[] $ordered_keys Field keys in the new order.
	 * @return true|WP_Error
	 */
	public function reorder( $cpt_slug, array $ordered_keys ) {
		$cpt_slug = sanitize_key( $cpt_slug );
		if ( $cpt_slug === '' ) {
			return new WP_Error(
				'pcptpages_invalid_cpt_slug',
				__( 'CPT slug is empty or invalid.', 'promptless-cpt-pages' )
			);
		}

		// Sanitize the incoming list so we compare apples-to-apples against
		// existing storage (which always stores sanitized keys).
		$ordered_keys = array_map( 'sanitize_key', $ordered_keys );
		$ordered_keys = array_values( array_filter( $ordered_keys, 'strlen' ) );

		// Check for duplicates — same key twice in the new order is a
		// clear caller error and would silently lose data on rewrite.
		if ( count( $ordered_keys ) !== count( array_unique( $ordered_keys ) ) ) {
			return new WP_Error(
				'pcptpages_reorder_duplicate_keys',
				__( 'Reorder list contains duplicate field keys.', 'promptless-cpt-pages' )
			);
		}

		$all      = $this->get_all( $cpt_slug );
		$existing = array_keys( $all );

		// Sets must match exactly.
		$missing = array_diff( $existing, $ordered_keys );
		$extra   = array_diff( $ordered_keys, $existing );

		if ( ! empty( $missing ) || ! empty( $extra ) ) {
			return new WP_Error(
				'pcptpages_reorder_keys_mismatch',
				__( 'Reorder list must contain exactly the existing field keys, no additions or removals.', 'promptless-cpt-pages' ),
				array(
					'missing_from_input' => array_values( $missing ),
					'unknown_in_input'   => array_values( $extra ),
				)
			);
		}

		// Rebuild the storage in the requested order.
		$reordered = array();
		foreach ( $ordered_keys as $key ) {
			$reordered[ $key ] = $all[ $key ];
			// Bump the per-field updated_at so callers can detect the
			// reorder via timestamp diffing. connector_version stays
			// unchanged since the field DEFINITION didn't change, only
			// its position in the list.
			$reordered[ $key ]['updated_at'] = current_time( 'mysql', true );
		}

		update_option( self::OPTION_PREFIX . $cpt_slug, $reordered );
		$this->cache[ $cpt_slug ] = $reordered;

		/**
		 * Fires after post field definitions are reordered.
		 *
		 * @param string   $cpt_slug     CPT slug.
		 * @param string[] $ordered_keys New order (sanitized).
		 */
		do_action( 'pcptpages_post_fields_reordered', $cpt_slug, $ordered_keys );

		return true;
	}

	/**
	 * Apply defaults to a partial post field definition.
	 *
	 * @param array $definition Partial definition.
	 * @return array Normalized definition.
	 */
	private function merge_defaults( array $definition ) {
		$defaults = array(
			'description'        => '',
			'icon'               => '',
			'color_intent'       => 'neutral',
			'options'            => array(),
			'required'           => false,
			'card_position'      => 'meta_strip',
			'single_position'    => 'meta_strip',
			'date_format'        => 'absolute',
			'date_format_string' => '',
			'currency_code'      => '',
			'value_suffix'       => '',
			'max'                => 0,
			'unit_label'         => '',
			// Number formatting (v1.2.x). Thousands grouping defaults ON so
			// existing number_with_label fields (sqft, counts) are unchanged;
			// set false for identifier-like numbers (year built, model year,
			// unit/lot numbers, IDs) so they render ungrouped: 2019, not 2,019.
			'number_grouping'    => true,
			// Events vertical (v1.2) additive attributes. Defaults keep
			// non-event fields byte-identical to pre-v1.2 behavior.
			'all_day'            => false,
			'event_timezone'     => '',
			'semantic_role'      => '',
			// Schema-driven filters (v1.2) additive attributes. Default
			// off — a field only participates in the filter/sort UI when
			// explicitly opted in. '' filter_widget = use the display_type
			// default mapping. Non-filtered fields are byte-identical to before.
			'filterable'         => false,
			'sortable'           => false,
			'filter_widget'      => '',
		);

		// sanitize_key on the field key (validator already verified safety;
		// this is belt-and-suspenders so storage doesn't drift).
		$definition['key'] = sanitize_key( $definition['key'] );

		return wp_parse_args( $definition, $defaults );
	}

	/**
	 * Resolve the effective max field count, honoring the
	 * `pcptpages_max_post_fields_per_cpt` filter.
	 *
	 * @return int
	 */
	private function get_max_field_count() {
		/**
		 * Filter the hard cap on post fields per CPT.
		 *
		 * Default 12 (PCPTPages_Validator::HARD_FIELD_COUNT_LIMIT). Beyond 12
		 * the card layout starts to break down regardless of viewport.
		 * Sites with genuinely unusual needs can raise the cap via this
		 * filter; raising it past ~16 is unlikely to produce usable
		 * cards on mobile.
		 *
		 * @param int $limit Maximum fields allowed per CPT.
		 */
		return (int) apply_filters( 'pcptpages_max_post_fields_per_cpt', PCPTPages_Validator::HARD_FIELD_COUNT_LIMIT );
	}

	/**
	 * Check whether a CPT is registered. Mirrors PCPTPages_Grouping_Registry's
	 * helper: prefer post_type_exists() once WP has hit init; fall back to
	 * the option-level registry otherwise.
	 *
	 * @param string $cpt_slug CPT slug.
	 * @return bool
	 */
	private function is_cpt_registered( $cpt_slug ) {
		if ( did_action( 'init' ) > 0 && post_type_exists( $cpt_slug ) ) {
			return true;
		}

		$cpts = get_option( PCPTPages_CPT_Registry::OPTION_KEY, array() );
		return is_array( $cpts ) && isset( $cpts[ $cpt_slug ] );
	}

	/**
	 * Reset the in-memory cache. Tests and bulk-write paths use this.
	 */
	public function reset_cache() {
		$this->cache = array();
	}
}
