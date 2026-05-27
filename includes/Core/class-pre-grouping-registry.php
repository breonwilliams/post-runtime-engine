<?php
/**
 * Grouping definition registry for Promptless CPT Pages.
 *
 * Each CPT can have one or more named "groupings" — repeating field
 * collections that conform to the standard primitive shape (image-or-icon,
 * heading, supporting text, optional link). Definitions live in their own
 * options keyed by CPT slug (`pre_groupings_{cpt_slug}`).
 *
 * The grouping definition specifies the default variant, default position,
 * default source mode, item shape requirements, and max items. Per-post
 * grouping data (the actual filled-in items) lives in post meta and is
 * managed by PRE_Post_Data.
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and reads grouping definitions per CPT.
 */
class PRE_Grouping_Registry {

	/**
	 * Option key prefix. The actual option for a CPT is
	 * `pre_groupings_{cpt_slug}`.
	 */
	const OPTION_PREFIX = 'pre_groupings_';

	/**
	 * Validator instance.
	 *
	 * @var PRE_Validator
	 */
	private $validator;

	/**
	 * Memoized definitions, keyed by CPT slug, then by grouping key. Loaded
	 * lazily on first read for each CPT.
	 *
	 * @var array<string,array<string,array>>
	 */
	private $cache = array();

	/**
	 * Constructor.
	 *
	 * @param PRE_Validator|null $validator Optional validator dependency.
	 */
	public function __construct( $validator = null ) {
		$this->validator = $validator ?: new PRE_Validator();
	}

	/**
	 * Get all grouping definitions for a CPT, keyed by grouping key.
	 *
	 * @param string $cpt_slug CPT slug.
	 * @return array<string,array> Empty array if the CPT has no groupings yet.
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
	 * Get a single grouping definition.
	 *
	 * @param string $cpt_slug     CPT slug.
	 * @param string $grouping_key Grouping key.
	 * @return array|null Definition or null if not defined.
	 */
	public function get( $cpt_slug, $grouping_key ) {
		$grouping_key = sanitize_key( $grouping_key );
		if ( $grouping_key === '' ) {
			return null;
		}

		$all = $this->get_all( $cpt_slug );
		return isset( $all[ $grouping_key ] ) ? $all[ $grouping_key ] : null;
	}

	/**
	 * Check whether a grouping is defined for a CPT.
	 *
	 * @param string $cpt_slug     CPT slug.
	 * @param string $grouping_key Grouping key.
	 * @return bool
	 */
	public function exists( $cpt_slug, $grouping_key ) {
		return $this->get( $cpt_slug, $grouping_key ) !== null;
	}

	/**
	 * Define a grouping for a CPT, creating it or updating an existing one.
	 *
	 * @param string $cpt_slug   CPT slug. Must be a registered CPT.
	 * @param array  $definition Grouping definition. The `key` field is
	 *                           required and is also the array key in storage.
	 * @return true|WP_Error
	 */
	public function define( $cpt_slug, array $definition ) {
		$cpt_slug = sanitize_key( $cpt_slug );
		if ( $cpt_slug === '' ) {
			return new WP_Error(
				'pre_invalid_cpt_slug',
				__( 'CPT slug is empty or invalid.', 'promptless-cpt-pages' )
			);
		}

		// Optional but encouraged: confirm the CPT actually exists in the
		// registry. Defining groupings for a non-existent CPT is permitted
		// (e.g., for connector-driven setup where CPT and groupings are
		// pushed together) but flagged in the action so downstream code can
		// react.
		$cpt_exists = $this->is_cpt_registered( $cpt_slug );

		$valid = $this->validator->validate_grouping_definition( $definition );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$definition = $this->merge_defaults( $definition );

		$grouping_key = $definition['key'];

		$all      = $this->get_all( $cpt_slug );
		$existing = isset( $all[ $grouping_key ] ) ? $all[ $grouping_key ] : null;
		$now      = current_time( 'mysql', true );

		$definition['created_at']        = $existing['created_at'] ?? $now;
		$definition['updated_at']        = $now;
		$definition['connector_version'] = isset( $existing['connector_version'] )
			? (int) $existing['connector_version'] + 1
			: 1;

		$all[ $grouping_key ] = $definition;

		$saved = update_option( self::OPTION_PREFIX . $cpt_slug, $all );
		$this->cache[ $cpt_slug ] = $all;

		if ( ! $saved && get_option( self::OPTION_PREFIX . $cpt_slug ) !== $all ) {
			return new WP_Error(
				'pre_grouping_save_failed',
				__( 'Failed to persist grouping definition to the database.', 'promptless-cpt-pages' )
			);
		}

		/**
		 * Fires after a grouping definition is created or updated.
		 *
		 * @param string $cpt_slug     CPT slug.
		 * @param string $grouping_key Grouping key.
		 * @param array  $definition   Stored definition.
		 * @param bool   $is_new       True if this is a fresh definition.
		 * @param bool   $cpt_exists   Whether the parent CPT is registered.
		 *                             False indicates the grouping was defined
		 *                             ahead of its CPT (legal but unusual).
		 */
		do_action( 'pre_grouping_defined', $cpt_slug, $grouping_key, $definition, $existing === null, $cpt_exists );

		return true;
	}

	/**
	 * Remove a grouping definition. Does not touch per-post values; existing
	 * posts that reference the removed grouping will be flagged by the
	 * validator on next save and the renderer will skip the orphaned data.
	 *
	 * @param string $cpt_slug     CPT slug.
	 * @param string $grouping_key Grouping key.
	 * @return true|WP_Error
	 */
	public function remove( $cpt_slug, $grouping_key ) {
		$cpt_slug     = sanitize_key( $cpt_slug );
		$grouping_key = sanitize_key( $grouping_key );

		if ( $cpt_slug === '' || $grouping_key === '' ) {
			return new WP_Error(
				'pre_invalid_args',
				__( 'CPT slug and grouping key are required.', 'promptless-cpt-pages' )
			);
		}

		$all = $this->get_all( $cpt_slug );
		if ( ! isset( $all[ $grouping_key ] ) ) {
			return new WP_Error(
				'pre_grouping_not_found',
				/* translators: %1$s: grouping key, %2$s: CPT slug */
				sprintf( __( 'Grouping %1$s is not defined for CPT %2$s.', 'promptless-cpt-pages' ), $grouping_key, $cpt_slug )
			);
		}

		unset( $all[ $grouping_key ] );

		if ( empty( $all ) ) {
			// Clean up the option entirely when no groupings remain.
			delete_option( self::OPTION_PREFIX . $cpt_slug );
		} else {
			update_option( self::OPTION_PREFIX . $cpt_slug, $all );
		}

		$this->cache[ $cpt_slug ] = $all;

		/**
		 * Fires after a grouping definition is removed.
		 *
		 * @param string $cpt_slug     CPT slug.
		 * @param string $grouping_key Removed grouping key.
		 */
		do_action( 'pre_grouping_removed', $cpt_slug, $grouping_key );

		return true;
	}

	/**
	 * Remove all groupings for a CPT. Called automatically when a CPT is
	 * unregistered via PRE_CPT_Registry::unregister().
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
	 * Apply defaults to a partial grouping definition.
	 *
	 * @param array $definition Partial definition.
	 * @return array Normalized definition.
	 */
	private function merge_defaults( array $definition ) {
		$defaults = array(
			'description'              => '',
			'default_source'           => 'manual',
			'max_items'                => null,
			'heading_required'         => true,
			'supporting_text_required' => false,
			'link_required'            => false,
			'icon_or_image_required'   => false,
		);

		// Sanitize the key field (validator already verified it's safe; this
		// is belt-and-suspenders so storage doesn't drift).
		$definition['key'] = sanitize_key( $definition['key'] );

		// Normalize the `default_source`. The validator accepts either a
		// string or an object; we keep whatever shape was provided and let
		// the source resolver (Phase 2) handle both at render time.

		return wp_parse_args( $definition, $defaults );
	}

	/**
	 * Check whether a CPT is registered. Uses post_type_exists when WP has
	 * already finished init; falls back to the option-level registry
	 * otherwise.
	 *
	 * @param string $cpt_slug CPT slug.
	 * @return bool
	 */
	private function is_cpt_registered( $cpt_slug ) {
		if ( did_action( 'init' ) > 0 && post_type_exists( $cpt_slug ) ) {
			return true;
		}

		$cpts = get_option( PRE_CPT_Registry::OPTION_KEY, array() );
		return is_array( $cpts ) && isset( $cpts[ $cpt_slug ] );
	}

	/**
	 * Reset the in-memory cache. Tests and bulk-write paths use this.
	 */
	public function reset_cache() {
		$this->cache = array();
	}
}
