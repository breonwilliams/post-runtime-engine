<?php
/**
 * Per-post grouping value accessor for Post Runtime Engine.
 *
 * Reads and writes the `_pre_groupings` post meta array. Validates writes
 * through PRE_Validator (which cross-references the per-CPT grouping
 * definitions provided by PRE_Grouping_Registry). Creates a backup copy
 * before destructive writes so the connector can roll back.
 *
 * The post meta shape is documented in docs/ARCHITECTURE.md. Each entry
 * has:
 *   - grouping_key       (string)        — references a defined grouping
 *   - position           (string|null)   — overrides the definition default
 *   - variant_override   (string|null)   — overrides the definition default
 *   - source             (string|array|null) — overrides the definition default
 *   - items              (array)         — manual-source items only; auto sources use []
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-post grouping value accessor.
 */
class PRE_Post_Data {

	/**
	 * Post meta key holding the groupings array.
	 */
	const META_KEY = '_pre_groupings';

	/**
	 * Backup post meta keys. Created before each connector- or admin-driven
	 * write so a previous-state restore is always one option lookup away.
	 */
	const META_KEY_BACKUP        = '_pre_groupings_backup';
	const META_KEY_BACKUP_TIME   = '_pre_groupings_backup_time';
	const META_KEY_BACKUP_USER   = '_pre_groupings_backup_user';
	const META_KEY_BACKUP_SOURCE = '_pre_groupings_backup_source';

	// ---------------------------------------------------------------------
	// v1.1 post-field meta keys
	// ---------------------------------------------------------------------

	/**
	 * Prefix for per-field value post meta entries. Each registered field
	 * stores its value at `_pre_field_{field_key}`. Per-field meta keeps
	 * values queryable via WP_Query meta_query and visible in WP-CLI /
	 * wp_postmeta inspection (instead of buried in a serialized blob).
	 *
	 * Composite display types (rating, progress) store secondary values
	 * at `_pre_field_{field_key}_count` (rating review count) and
	 * `_pre_field_{field_key}_goal` (progress target).
	 */
	const FIELD_VALUE_META_PREFIX = '_pre_field_';

	/**
	 * Single post meta entry holding the per-post visibility overrides as
	 * a JSON-encoded object. Single entry (rather than per-field) because
	 * visibility is configuration data, not queryable content. See
	 * docs/POST_FIELDS_V1_1_DESIGN.md § 5.2.
	 */
	const FIELD_VISIBILITY_META_KEY = '_pre_field_visibility';

	/**
	 * CPT registry dependency.
	 *
	 * @var PRE_CPT_Registry
	 */
	private $cpts;

	/**
	 * Grouping registry dependency.
	 *
	 * @var PRE_Grouping_Registry
	 */
	private $groupings;

	/**
	 * Validator instance.
	 *
	 * @var PRE_Validator
	 */
	private $validator;

	/**
	 * Post field registry. Lazy-resolved on first use via the global
	 * plugin instance when not injected at construction time, so callers
	 * that pre-date v1.1 (3-argument constructor) keep working.
	 *
	 * @var PRE_Post_Field_Registry|null
	 */
	private $post_fields;

	/**
	 * Constructor.
	 *
	 * @param PRE_CPT_Registry           $cpts        CPT registry.
	 * @param PRE_Grouping_Registry      $groupings   Grouping registry.
	 * @param PRE_Validator|null         $validator   Optional validator dependency.
	 * @param PRE_Post_Field_Registry|null $post_fields Optional post field registry
	 *                                                  (v1.1). Lazy-resolved if null.
	 */
	public function __construct(
		PRE_CPT_Registry $cpts,
		PRE_Grouping_Registry $groupings,
		$validator = null,
		$post_fields = null
	) {
		$this->cpts        = $cpts;
		$this->groupings   = $groupings;
		$this->validator   = $validator ?: new PRE_Validator();
		$this->post_fields = $post_fields;
	}

	/**
	 * Resolve the post field registry. Prefers the constructor-injected
	 * instance; falls back to the global plugin instance; otherwise builds
	 * a fresh registry. Lets v1.0 callers (3-arg constructor) keep working
	 * while v1.1 code can inject explicitly.
	 *
	 * @return PRE_Post_Field_Registry
	 */
	private function get_post_field_registry() {
		if ( $this->post_fields instanceof PRE_Post_Field_Registry ) {
			return $this->post_fields;
		}

		// Resolve through the global plugin instance when available.
		if ( function_exists( 'pre' ) ) {
			$plugin = pre();
			if ( $plugin && isset( $plugin->post_fields ) && $plugin->post_fields instanceof PRE_Post_Field_Registry ) {
				$this->post_fields = $plugin->post_fields;
				return $this->post_fields;
			}
		}

		// Last-resort fallback. Should not be hit in normal runtime.
		$this->post_fields = new PRE_Post_Field_Registry( $this->validator );
		return $this->post_fields;
	}

	/**
	 * Read the groupings array for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array Empty array if no groupings stored or post invalid.
	 */
	public function get_groupings( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id === 0 ) {
			return array();
		}

		$stored = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		return $stored;
	}

	/**
	 * Write the full groupings array for a post.
	 *
	 * Validates against the parent CPT's grouping definitions, creates a
	 * backup of the current state, persists the new value, and fires a hook.
	 *
	 * @param int    $post_id  Post ID.
	 * @param array  $groupings Full groupings array (replaces existing).
	 * @param string $source    Identifier for the write source. One of:
	 *                           'admin', 'connector', 'mcp', 'programmatic'.
	 *                           Stored in the backup metadata for audit.
	 * @return true|WP_Error
	 */
	public function set_groupings( $post_id, array $groupings, $source = 'programmatic' ) {
		$post_id = absint( $post_id );
		if ( $post_id === 0 ) {
			return new WP_Error(
				'pre_invalid_post_id',
				__( 'Post ID is invalid.', 'post-runtime-engine' )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'pre_post_not_found',
				/* translators: %d: post ID */
				sprintf( __( 'Post %d does not exist.', 'post-runtime-engine' ), $post_id )
			);
		}

		// Confirm the post belongs to a registered CPT.
		if ( ! $this->cpts->exists( $post->post_type ) ) {
			return new WP_Error(
				'pre_post_type_not_managed',
				/* translators: %s: post type */
				sprintf( __( 'Post type %s is not managed by Post Runtime Engine.', 'post-runtime-engine' ), $post->post_type )
			);
		}

		// Pull the grouping definitions for the parent CPT and pass them to
		// the validator. The validator does the heavy lifting of cross-checking
		// each per-post grouping against its definition.
		$cpt_groupings = $this->groupings->get_all( $post->post_type );

		$valid = $this->validator->validate_post_groupings( $groupings, $post->post_type, $cpt_groupings );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// Normalize each grouping entry: fill in null for omitted optional
		// keys and ensure consistent ordering of fields.
		$normalized = array_map( array( $this, 'normalize_grouping' ), $groupings );

		// Capture the current state as a backup before overwriting.
		$this->snapshot_backup( $post_id, $source );

		$saved = update_post_meta( $post_id, self::META_KEY, $normalized );
		// update_post_meta returns false when the value is unchanged. Verify
		// by reading back rather than treating false as failure.
		$current = get_post_meta( $post_id, self::META_KEY, true );
		if ( $current !== $normalized ) {
			return new WP_Error(
				'pre_post_data_save_failed',
				__( 'Failed to persist post groupings.', 'post-runtime-engine' )
			);
		}

		/**
		 * Fires after a post's groupings are written.
		 *
		 * @param int    $post_id    Post ID.
		 * @param array  $groupings  Stored (normalized) groupings.
		 * @param string $source     Identifier for the write source.
		 * @param string $cpt_slug   The post's post type.
		 */
		do_action( 'pre_post_groupings_saved', $post_id, $normalized, $source, $post->post_type );

		// Suppress the unused-saved warning (update_post_meta return value).
		unset( $saved );

		return true;
	}

	/**
	 * Update a single grouping on a post, leaving the rest unchanged.
	 *
	 * Convenience method built on top of set_groupings(). Resolves the
	 * existing groupings, replaces (or appends) the matching entry by
	 * grouping_key, and persists.
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $grouping_key Grouping key.
	 * @param array  $entry        New grouping entry. May omit grouping_key
	 *                             (it will be set from the parameter).
	 * @param string $source       Identifier for the write source.
	 * @return true|WP_Error
	 */
	public function update_grouping( $post_id, $grouping_key, array $entry, $source = 'programmatic' ) {
		$grouping_key = sanitize_key( $grouping_key );
		if ( $grouping_key === '' ) {
			return new WP_Error(
				'pre_invalid_grouping_key',
				__( 'Grouping key is empty or invalid.', 'post-runtime-engine' )
			);
		}

		$entry['grouping_key'] = $grouping_key;

		$existing = $this->get_groupings( $post_id );
		$replaced = false;

		foreach ( $existing as $idx => $current ) {
			if ( isset( $current['grouping_key'] ) && $current['grouping_key'] === $grouping_key ) {
				$existing[ $idx ] = $entry;
				$replaced         = true;
				break;
			}
		}

		if ( ! $replaced ) {
			$existing[] = $entry;
		}

		return $this->set_groupings( $post_id, $existing, $source );
	}

	/**
	 * Remove a single grouping from a post by key.
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $grouping_key Grouping key to remove.
	 * @param string $source       Identifier for the write source.
	 * @return true|WP_Error
	 */
	public function remove_grouping( $post_id, $grouping_key, $source = 'programmatic' ) {
		$grouping_key = sanitize_key( $grouping_key );
		$existing     = $this->get_groupings( $post_id );

		$filtered = array();
		$found    = false;
		foreach ( $existing as $entry ) {
			if ( isset( $entry['grouping_key'] ) && $entry['grouping_key'] === $grouping_key ) {
				$found = true;
				continue;
			}
			$filtered[] = $entry;
		}

		if ( ! $found ) {
			return new WP_Error(
				'pre_grouping_not_present',
				/* translators: %s: grouping key */
				sprintf( __( 'Grouping %s is not present on this post.', 'post-runtime-engine' ), $grouping_key )
			);
		}

		return $this->set_groupings( $post_id, $filtered, $source );
	}

	/**
	 * Restore the most recent backup. Used by the connector for one-step
	 * rollback after a failed deploy.
	 *
	 * Note: this performs a full overwrite of the current state with the
	 * backup. It does NOT validate the restored data through the validator —
	 * the assumption is that the backup was valid when it was taken. If the
	 * grouping definitions have since changed in incompatible ways, the
	 * restored data may render incorrectly until a subsequent valid write
	 * comes through.
	 *
	 * @param int $post_id Post ID.
	 * @return true|WP_Error
	 */
	public function restore_backup( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id === 0 ) {
			return new WP_Error(
				'pre_invalid_post_id',
				__( 'Post ID is invalid.', 'post-runtime-engine' )
			);
		}

		$backup = get_post_meta( $post_id, self::META_KEY_BACKUP, true );
		if ( ! is_array( $backup ) ) {
			return new WP_Error(
				'pre_no_backup',
				__( 'No backup available to restore.', 'post-runtime-engine' )
			);
		}

		update_post_meta( $post_id, self::META_KEY, $backup );

		/**
		 * Fires after a post's groupings are restored from backup.
		 *
		 * @param int   $post_id Post ID.
		 * @param array $backup  Restored groupings.
		 */
		do_action( 'pre_post_groupings_restored', $post_id, $backup );

		return true;
	}

	/**
	 * Snapshot the current state into the backup post-meta keys.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $source  Identifier for the write source.
	 */
	private function snapshot_backup( $post_id, $source ) {
		$current = $this->get_groupings( $post_id );

		// Don't overwrite a non-empty backup with an empty current state. If
		// somehow the current state is empty, leave the existing backup alone
		// — that's the only piece of recoverable history left.
		if ( empty( $current ) && $this->has_backup( $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, self::META_KEY_BACKUP, $current );
		update_post_meta( $post_id, self::META_KEY_BACKUP_TIME, current_time( 'mysql', true ) );
		update_post_meta( $post_id, self::META_KEY_BACKUP_USER, get_current_user_id() );
		update_post_meta( $post_id, self::META_KEY_BACKUP_SOURCE, sanitize_key( $source ) );
	}

	/**
	 * Whether a backup exists for the given post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function has_backup( $post_id ) {
		$backup = get_post_meta( $post_id, self::META_KEY_BACKUP, true );
		return is_array( $backup ) && ! empty( $backup );
	}

	/**
	 * Normalize a single grouping entry: fill omitted optional fields with
	 * defaults so storage is consistent and downstream code doesn't have to
	 * isset() every key.
	 *
	 * @param array $entry Per-post grouping entry.
	 * @return array
	 */
	private function normalize_grouping( $entry ) {
		if ( ! is_array( $entry ) ) {
			return array();
		}

		$defaults = array(
			'grouping_key'     => '',
			'position'         => null,
			'variant_override' => null,
			'source'           => 'manual',
			'items'            => array(),
		);

		$entry = wp_parse_args( $entry, $defaults );

		// Normalize each item to a consistent five-key shape.
		if ( is_array( $entry['items'] ) ) {
			$entry['items'] = array_map( array( $this, 'normalize_item' ), $entry['items'] );
		} else {
			$entry['items'] = array();
		}

		return $entry;
	}

	/**
	 * Normalize a single grouping item.
	 *
	 * Canonical shape includes link_post_id alongside the link string so
	 * internal links survive domain/permalink migrations. See
	 * PRE_Validator::validate_grouping_item for the contract.
	 *
	 * @param mixed $item Item shape.
	 * @return array
	 */
	private function normalize_item( $item ) {
		$defaults = array(
			'image_id'        => null,
			'icon_id'         => null,
			'heading'         => '',
			'supporting_text' => null,
			'link'            => null,
			'link_post_id'    => null,
		);

		if ( ! is_array( $item ) ) {
			return $defaults;
		}

		return wp_parse_args( $item, $defaults );
	}

	// ---------------------------------------------------------------------
	// v1.1 post field accessors
	//
	// Storage shape per docs/POST_FIELDS_V1_1_DESIGN.md § 5.2:
	//   - Per-field value: `_pre_field_{field_key}` (one meta per field)
	//   - Composite secondary values:
	//       rating count   => `_pre_field_{field_key}_count`
	//       progress goal  => `_pre_field_{field_key}_goal`
	//   - Visibility overrides: `_pre_field_visibility` (JSON object)
	//
	// All writes validate through PRE_Validator. Reads are tolerant of
	// missing values (return null / empty array) so the renderer can
	// skip cleanly when nothing is set.
	// ---------------------------------------------------------------------

	/**
	 * Read all post field values for a post.
	 *
	 * Returns an associative array keyed by field key. For composite
	 * display types (rating, progress), the value is an array containing
	 * the primary value plus the secondary key (count or goal).
	 *
	 * Only fields currently REGISTERED on the post's CPT are returned —
	 * orphaned meta from deleted field definitions is silently filtered.
	 * Use `cleanup_orphaned_field_values()` to physically remove them.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed> Field values keyed by field key.
	 *                              Empty array on invalid post or no values.
	 */
	public function get_field_values( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id === 0 ) {
			return array();
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$cpt_slug   = $post->post_type;
		$field_defs = $this->get_post_field_registry()->get_all( $cpt_slug );

		if ( empty( $field_defs ) ) {
			return array();
		}

		$values = array();
		foreach ( $field_defs as $field_key => $field_def ) {
			$value = $this->read_field_value( $post_id, $field_key, $field_def );
			if ( $value !== null ) {
				$values[ $field_key ] = $value;
			}
		}

		return $values;
	}

	/**
	 * Read a single field's value, including any composite secondary value.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $field_key Field key.
	 * @return mixed|null Scalar value, or array for composite types, or null
	 *                    if no value stored.
	 */
	public function get_field_value( $post_id, $field_key ) {
		$post_id   = absint( $post_id );
		$field_key = sanitize_key( $field_key );
		if ( $post_id === 0 || $field_key === '' ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		$field_def = $this->get_post_field_registry()->get( $post->post_type, $field_key );
		if ( $field_def === null ) {
			return null;
		}

		return $this->read_field_value( $post_id, $field_key, $field_def );
	}

	/**
	 * Bulk write field values for a post.
	 *
	 * Validates each value against the field definition before writing.
	 * Composite types (rating, progress) accept array-shape input:
	 *   `'rating'  => array( 'value' => 4.8, 'count' => 1243 )`
	 *   `'funding' => array( 'value' => 320000, 'goal' => 500000 )`
	 * Or the scalar shorthand:
	 *   `'price'   => 1250000`
	 *
	 * Fields not present in `$values` are LEFT UNCHANGED (this is a
	 * partial-update method, not a full-replace). To clear a field, pass
	 * an explicit null or empty string for that field's key.
	 *
	 * Returns the first WP_Error encountered (writes that already succeeded
	 * are not rolled back — the caller can re-fetch and reconcile).
	 *
	 * @param int    $post_id Post ID.
	 * @param array  $values  Field values keyed by field key.
	 * @param string $source  Identifier for the write source. One of:
	 *                         'admin', 'connector', 'mcp', 'programmatic'.
	 *                         Surfaced in the action hook for audit.
	 * @return true|WP_Error
	 */
	public function set_field_values( $post_id, array $values, $source = 'programmatic' ) {
		$post_id = absint( $post_id );
		if ( $post_id === 0 ) {
			return new WP_Error(
				'pre_invalid_post_id',
				__( 'Post ID is invalid.', 'post-runtime-engine' )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'pre_post_not_found',
				/* translators: %d: post ID */
				sprintf( __( 'Post %d does not exist.', 'post-runtime-engine' ), $post_id )
			);
		}

		$cpt_slug = $post->post_type;
		if ( ! $this->cpts->exists( $cpt_slug ) ) {
			return new WP_Error(
				'pre_post_type_not_managed',
				/* translators: %s: post type */
				sprintf( __( 'Post type %s is not managed by Post Runtime Engine.', 'post-runtime-engine' ), $cpt_slug )
			);
		}

		$field_defs = $this->get_post_field_registry()->get_all( $cpt_slug );

		foreach ( $values as $field_key => $value ) {
			$field_key = sanitize_key( $field_key );
			if ( $field_key === '' ) {
				continue;
			}

			if ( ! isset( $field_defs[ $field_key ] ) ) {
				return new WP_Error(
					'pre_unknown_field_key',
					sprintf(
						/* translators: %1$s: field key, %2$s: CPT slug */
						__( 'Field %1$s is not registered on CPT %2$s.', 'post-runtime-engine' ),
						$field_key,
						$cpt_slug
					)
				);
			}

			$field_def = $field_defs[ $field_key ];

			$write_result = $this->write_field_value( $post_id, $field_key, $field_def, $value );
			if ( is_wp_error( $write_result ) ) {
				return $write_result;
			}
		}

		/**
		 * Fires after one or more post field values are written.
		 *
		 * @param int    $post_id Post ID.
		 * @param array  $values  Values that were just written.
		 * @param string $source  Source identifier (admin / connector / mcp / programmatic).
		 */
		do_action( 'pre_post_field_values_saved', $post_id, $values, $source );

		return true;
	}

	/**
	 * Set a single field's value. Convenience wrapper over
	 * `set_field_values()`.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $field_key Field key.
	 * @param mixed  $value     Value (scalar or composite array).
	 * @param string $source    Source identifier.
	 * @return true|WP_Error
	 */
	public function set_field_value( $post_id, $field_key, $value, $source = 'programmatic' ) {
		return $this->set_field_values( $post_id, array( $field_key => $value ), $source );
	}

	/**
	 * Read the per-post field visibility overrides.
	 *
	 * Returns an associative array keyed by field key, each entry containing
	 * `card_hidden` and `single_hidden` booleans. Fields not present in the
	 * returned array use default visibility (rendered in both contexts).
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,array{card_hidden:bool,single_hidden:bool}>
	 */
	public function get_field_visibility( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id === 0 ) {
			return array();
		}

		$raw = get_post_meta( $post_id, self::FIELD_VISIBILITY_META_KEY, true );
		if ( empty( $raw ) ) {
			return array();
		}

		// Decode the JSON string. Tolerate the case where someone stored
		// an array directly (older paths, tests).
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				return array();
			}
			return $decoded;
		}

		if ( is_array( $raw ) ) {
			return $raw;
		}

		return array();
	}

	/**
	 * Write the per-post field visibility overrides.
	 *
	 * Validates the shape via PRE_Validator::validate_post_field_visibility,
	 * then persists as a JSON-encoded string at the single meta key.
	 *
	 * @param int    $post_id    Post ID.
	 * @param array  $visibility Visibility array. Empty array clears overrides.
	 * @param string $source     Source identifier.
	 * @return true|WP_Error
	 */
	public function set_field_visibility( $post_id, array $visibility, $source = 'programmatic' ) {
		$post_id = absint( $post_id );
		if ( $post_id === 0 ) {
			return new WP_Error(
				'pre_invalid_post_id',
				__( 'Post ID is invalid.', 'post-runtime-engine' )
			);
		}

		$valid = $this->validator->validate_post_field_visibility( $visibility );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// Normalize each entry to ensure both flags are always booleans —
		// downstream code can use direct bool comparison without isset().
		$normalized = array();
		foreach ( $visibility as $field_key => $flags ) {
			$normalized[ sanitize_key( $field_key ) ] = array(
				'card_hidden'   => isset( $flags['card_hidden'] ) ? (bool) $flags['card_hidden'] : false,
				'single_hidden' => isset( $flags['single_hidden'] ) ? (bool) $flags['single_hidden'] : false,
			);
		}

		if ( empty( $normalized ) ) {
			// Clean up the meta entry entirely when no overrides remain.
			delete_post_meta( $post_id, self::FIELD_VISIBILITY_META_KEY );
		} else {
			update_post_meta( $post_id, self::FIELD_VISIBILITY_META_KEY, wp_json_encode( $normalized ) );
		}

		/**
		 * Fires after per-post field visibility overrides are saved.
		 *
		 * @param int    $post_id    Post ID.
		 * @param array  $visibility Normalized visibility array.
		 * @param string $source     Source identifier.
		 */
		do_action( 'pre_post_field_visibility_saved', $post_id, $normalized, $source );

		return true;
	}

	/**
	 * Resolve whether a specific field should be rendered for a post in a
	 * given context (`card` or `single`).
	 *
	 * Applies the per-post visibility override on top of the CPT-level
	 * position. A field with `card_position=hidden` is hidden regardless
	 * of per-post settings; a field with a non-hidden card position can
	 * still be hidden via the per-post override.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $field_key Field key.
	 * @param string $context   'card' or 'single'.
	 * @return bool True if the field should render.
	 */
	public function is_field_visible( $post_id, $field_key, $context ) {
		$post_id   = absint( $post_id );
		$field_key = sanitize_key( $field_key );
		$context   = in_array( $context, array( 'card', 'single' ), true ) ? $context : 'card';

		if ( $post_id === 0 || $field_key === '' ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$field_def = $this->get_post_field_registry()->get( $post->post_type, $field_key );
		if ( $field_def === null ) {
			return false;
		}

		// CPT-level position: if hidden in this context, definitively hidden.
		$position_key = ( $context === 'card' ) ? 'card_position' : 'single_position';
		$position     = $field_def[ $position_key ] ?? 'meta_strip';
		if ( $position === 'hidden' ) {
			return false;
		}

		// Per-post override.
		$visibility = $this->get_field_visibility( $post_id );
		if ( isset( $visibility[ $field_key ] ) ) {
			$flag_key = ( $context === 'card' ) ? 'card_hidden' : 'single_hidden';
			if ( ! empty( $visibility[ $field_key ][ $flag_key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Internal: read a field's raw value from post meta, returning the
	 * scalar OR the composite-shape array depending on display type.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $field_key Field key.
	 * @param array  $field_def Field definition.
	 * @return mixed|null
	 */
	private function read_field_value( $post_id, $field_key, array $field_def ) {
		$primary = get_post_meta( $post_id, self::FIELD_VALUE_META_PREFIX . $field_key, true );

		if ( $primary === '' ) {
			$primary = null;
		}

		$display_type = $field_def['display_type'] ?? 'text';

		switch ( $display_type ) {
			case 'rating':
				if ( $primary === null ) {
					return null;
				}
				$count = get_post_meta( $post_id, self::FIELD_VALUE_META_PREFIX . $field_key . '_count', true );
				return array(
					'value' => is_numeric( $primary ) ? (float) $primary : 0.0,
					'count' => $count === '' ? null : (int) $count,
				);

			case 'progress':
				if ( $primary === null ) {
					return null;
				}
				$goal = get_post_meta( $post_id, self::FIELD_VALUE_META_PREFIX . $field_key . '_goal', true );
				return array(
					'value' => is_numeric( $primary ) ? (float) $primary : 0.0,
					'goal'  => $goal === '' ? null : (float) $goal,
				);

			default:
				return $primary;
		}
	}

	/**
	 * Internal: validate and write a single field's value(s) to post meta,
	 * including any composite secondary value.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $field_key Field key.
	 * @param array  $field_def Field definition.
	 * @param mixed  $value     Scalar or composite array.
	 * @return true|WP_Error
	 */
	private function write_field_value( $post_id, $field_key, array $field_def, $value ) {
		$display_type = $field_def['display_type'] ?? 'text';

		// Decompose composite shapes into primary + secondary values for
		// validation and storage.
		$primary   = $value;
		$secondary = null;

		if ( in_array( $display_type, array( 'rating', 'progress' ), true ) && is_array( $value ) ) {
			$primary   = $value['value'] ?? null;
			$secondary = ( $display_type === 'rating' )
				? ( $value['count'] ?? null )
				: ( $value['goal']  ?? null );
		}

		// Validate the primary value. The validator allows null/empty as
		// "not set" — that's our path to clearing a field.
		$valid = $this->validator->validate_post_field_value( $field_def, $primary );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$primary_meta_key   = self::FIELD_VALUE_META_PREFIX . $field_key;
		$secondary_meta_key = self::FIELD_VALUE_META_PREFIX . $field_key . ( $display_type === 'rating' ? '_count' : '_goal' );

		// Clear path: explicit null or empty string deletes both primary
		// and secondary meta.
		if ( $primary === null || $primary === '' ) {
			delete_post_meta( $post_id, $primary_meta_key );
			delete_post_meta( $post_id, $secondary_meta_key );
			return true;
		}

		// Normalize value shapes that vary by display type.
		switch ( $display_type ) {
			case 'date':
				// Normalize to YYYY-MM-DD for consistent storage. We've
				// already validated parseability via strtotime().
				$ts = is_numeric( $primary ) ? (int) $primary : strtotime( (string) $primary );
				if ( $ts !== false ) {
					$primary = gmdate( 'Y-m-d', $ts );
				}
				break;

			case 'multi_badge':
				// Normalize array-shape to comma-separated for storage —
				// keeps a single canonical representation. Renderer
				// splits at read time.
				if ( is_array( $primary ) ) {
					$primary = implode( ',', array_map( 'strval', $primary ) );
				}
				break;

			case 'currency':
			case 'number_with_label':
			case 'rating':
			case 'progress':
				// Numeric storage normalization.
				$primary = (string) $primary;
				break;

			default:
				$primary = (string) $primary;
		}

		update_post_meta( $post_id, $primary_meta_key, $primary );

		// Persist or clear the secondary value for composite types.
		if ( in_array( $display_type, array( 'rating', 'progress' ), true ) ) {
			if ( $secondary === null || $secondary === '' ) {
				delete_post_meta( $post_id, $secondary_meta_key );
			} else {
				update_post_meta( $post_id, $secondary_meta_key, (string) $secondary );
			}
		}

		return true;
	}
}
