<?php
/**
 * Per-post grouping value accessor for Promptless CPT Pages.
 *
 * Reads and writes the `_pcptpages_groupings` post meta array. Validates writes
 * through PCPTPages_Validator (which cross-references the per-CPT grouping
 * definitions provided by PCPTPages_Grouping_Registry). Creates a backup copy
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
class PCPTPages_Post_Data {

	/**
	 * Post meta key holding the groupings array.
	 */
	const META_KEY = '_pcptpages_groupings';

	/**
	 * Backup post meta keys. Created before each connector- or admin-driven
	 * write so a previous-state restore is always one option lookup away.
	 */
	const META_KEY_BACKUP        = '_pcptpages_groupings_backup';
	const META_KEY_BACKUP_TIME   = '_pcptpages_groupings_backup_time';
	const META_KEY_BACKUP_USER   = '_pcptpages_groupings_backup_user';
	const META_KEY_BACKUP_SOURCE = '_pcptpages_groupings_backup_source';

	// ---------------------------------------------------------------------
	// External identity (ingest from a system of record)
	// ---------------------------------------------------------------------

	/**
	 * Where a record came from and which upstream record it mirrors. A
	 * record with these keys was created or last written by an ingest
	 * (FlowMint's pre_upsert_records step, the connector's upsert route, an
	 * import); (post_type, source, external_id) is the identity a re-run
	 * looks up, so re-running never duplicates. The hash is of the mapped
	 * payload as last written, so an unchanged record is skipped without a
	 * write — no post_modified churn, no revision. All four share the
	 * prefix so the CPT purge removes them by LIKE.
	 */
	const EXTERNAL_META_PREFIX = '_pcptpages_external_';
	const EXTERNAL_SOURCE_META = '_pcptpages_external_source';
	const EXTERNAL_ID_META     = '_pcptpages_external_id';
	const EXTERNAL_HASH_META   = '_pcptpages_external_hash';
	const EXTERNAL_SYNCED_META = '_pcptpages_external_synced_at';

	/**
	 * Attachment meta: the URL an image was sideloaded from. A feed that
	 * sends the same URL again reuses that attachment instead of
	 * downloading a second copy — the identity idiom applied to images.
	 */
	const IMAGE_SOURCE_META = '_pcptpages_source_url';

	/**
	 * Image MIME types a sideloaded file may have, and the extension the
	 * saved file gets when the URL carries none (`/photo?id=9`).
	 */
	const IMAGE_MIME_EXTENSIONS = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
		'image/avif' => 'avif',
	);

	// ---------------------------------------------------------------------
	// v1.1 post-field meta keys
	// ---------------------------------------------------------------------

	/**
	 * Prefix for per-field value post meta entries. Each registered field
	 * stores its value at `_pcptpages_field_{field_key}`. Per-field meta keeps
	 * values queryable via WP_Query meta_query and visible in WP-CLI /
	 * wp_postmeta inspection (instead of buried in a serialized blob).
	 *
	 * Composite display types (rating, progress) store secondary values
	 * at `_pcptpages_field_{field_key}_count` (rating review count) and
	 * `_pcptpages_field_{field_key}_goal` (progress target).
	 */
	const FIELD_VALUE_META_PREFIX = '_pcptpages_field_';

	/**
	 * Single post meta entry holding the per-post visibility overrides as
	 * a JSON-encoded object. Single entry (rather than per-field) because
	 * visibility is configuration data, not queryable content. See
	 * docs/POST_FIELDS_V1_1_DESIGN.md § 5.2.
	 */
	const FIELD_VISIBILITY_META_KEY = '_pcptpages_field_visibility';

	/**
	 * Suffixes for the normalized date companion meta written for event
	 * date fields (display_type === 'date' AND a non-empty semantic_role).
	 * Events vertical, v1.2 — see docs/EVENTS_VERTICAL_DESIGN.md § 5.3.
	 *
	 *   {key}__sort — numeric YYYYMMDDHHMMSS (site-local wall clock) for
	 *                 range/sort meta_query without parsing the display value.
	 *   {key}__utc  — unix UTC timestamp (tz-aware) for future viewer-local
	 *                 rendering. Stored now; not used for v1 rendering.
	 *
	 * Double underscore avoids collision with the single-underscore
	 * composite secondaries (`_count` / `_goal`).
	 */
	const FIELD_SORT_SUFFIX = '__sort';
	const FIELD_UTC_SUFFIX  = '__utc';

	/**
	 * CPT registry dependency.
	 *
	 * @var PCPTPages_CPT_Registry
	 */
	private $cpts;

	/**
	 * Grouping registry dependency.
	 *
	 * @var PCPTPages_Grouping_Registry
	 */
	private $groupings;

	/**
	 * Validator instance.
	 *
	 * @var PCPTPages_Validator
	 */
	private $validator;

	/**
	 * Post field registry. Lazy-resolved on first use via the global
	 * plugin instance when not injected at construction time, so callers
	 * that pre-date v1.1 (3-argument constructor) keep working.
	 *
	 * @var PCPTPages_Post_Field_Registry|null
	 */
	private $post_fields;

	/**
	 * Constructor.
	 *
	 * @param PCPTPages_CPT_Registry           $cpts        CPT registry.
	 * @param PCPTPages_Grouping_Registry      $groupings   Grouping registry.
	 * @param PCPTPages_Validator|null         $validator   Optional validator dependency.
	 * @param PCPTPages_Post_Field_Registry|null $post_fields Optional post field registry
	 *                                                  (v1.1). Lazy-resolved if null.
	 */
	public function __construct(
		PCPTPages_CPT_Registry $cpts,
		PCPTPages_Grouping_Registry $groupings,
		$validator = null,
		$post_fields = null
	) {
		$this->cpts        = $cpts;
		$this->groupings   = $groupings;
		$this->validator   = $validator ?: new PCPTPages_Validator();
		$this->post_fields = $post_fields;
	}

	/**
	 * Resolve the post field registry. Prefers the constructor-injected
	 * instance; falls back to the global plugin instance; otherwise builds
	 * a fresh registry. Lets v1.0 callers (3-arg constructor) keep working
	 * while v1.1 code can inject explicitly.
	 *
	 * @return PCPTPages_Post_Field_Registry
	 */
	private function get_post_field_registry() {
		if ( $this->post_fields instanceof PCPTPages_Post_Field_Registry ) {
			return $this->post_fields;
		}

		// Resolve through the global plugin instance when available.
		if ( function_exists( 'pcptpages' ) ) {
			$plugin = pcptpages();
			if ( $plugin && isset( $plugin->post_fields ) && $plugin->post_fields instanceof PCPTPages_Post_Field_Registry ) {
				$this->post_fields = $plugin->post_fields;
				return $this->post_fields;
			}
		}

		// Last-resort fallback. Should not be hit in normal runtime.
		$this->post_fields = new PCPTPages_Post_Field_Registry( $this->validator );
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
				'pcptpages_invalid_post_id',
				__( 'Post ID is invalid.', 'promptless-cpt-pages' )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'pcptpages_post_not_found',
				/* translators: %d: post ID */
				sprintf( __( 'Post %d does not exist.', 'promptless-cpt-pages' ), $post_id )
			);
		}

		// Confirm the post belongs to a registered CPT.
		if ( ! $this->cpts->exists( $post->post_type ) ) {
			return new WP_Error(
				'pcptpages_post_type_not_managed',
				/* translators: %s: post type */
				sprintf( __( 'Post type %s is not managed by Promptless CPT Pages.', 'promptless-cpt-pages' ), $post->post_type )
			);
		}

		// Pull the grouping definitions for the parent CPT and pass them to
		// the validator. The validator does the heavy lifting of cross-checking
		// each per-post grouping against its definition.
		$cpt_groupings = $this->groupings->get_all( $post->post_type );

		// Accept `key` as an alias for `grouping_key` on each entry. The
		// grouping DEFINITION (define_grouping) uses `key`, so authors
		// naturally reach for `key` when attaching grouping DATA to a post
		// too. Canonicalize to `grouping_key` here — before validation and
		// storage — so both spellings attach correctly instead of silently
		// failing with a "missing grouping_key" error.
		foreach ( $groupings as $gi => $gentry ) {
			if ( is_array( $gentry ) && empty( $gentry['grouping_key'] ) && ! empty( $gentry['key'] ) ) {
				$groupings[ $gi ]['grouping_key'] = $gentry['key'];
				unset( $groupings[ $gi ]['key'] );
			}
		}

		// Inherit the definition's default_source when an entry omits
		// `source` and carries no items (2026-07-10 smoke-test finding).
		// This mirrors the admin meta box save path, which snapshots
		// $def['default_source'] into every entry it writes — without this,
		// a connector caller attaching an auto-sourced grouping with a bare
		// {grouping_key} entry silently got source:'manual' + items:[] and
		// the grouping rendered nothing, making define_grouping's
		// default_source unreachable through the connector. Entries that
		// omit source but DO carry items keep the implicit-manual behavior
		// (items only make sense on a manual source, and the caller clearly
		// meant them to render).
		foreach ( $groupings as $gi => $gentry ) {
			if ( ! is_array( $gentry ) || isset( $gentry['source'] ) ) {
				continue;
			}
			if ( ! empty( $gentry['items'] ) ) {
				continue;
			}
			$gkey = isset( $gentry['grouping_key'] ) ? (string) $gentry['grouping_key'] : '';
			if ( $gkey !== '' && isset( $cpt_groupings[ $gkey ]['default_source'] ) ) {
				$groupings[ $gi ]['source'] = $cpt_groupings[ $gkey ]['default_source'];
			}
		}

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
				'pcptpages_post_data_save_failed',
				__( 'Failed to persist post groupings.', 'promptless-cpt-pages' )
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
		do_action( 'pcptpages_post_groupings_saved', $post_id, $normalized, $source, $post->post_type );

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
				'pcptpages_invalid_grouping_key',
				__( 'Grouping key is empty or invalid.', 'promptless-cpt-pages' )
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
				'pcptpages_grouping_not_present',
				/* translators: %s: grouping key */
				sprintf( __( 'Grouping %s is not present on this post.', 'promptless-cpt-pages' ), $grouping_key )
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
				'pcptpages_invalid_post_id',
				__( 'Post ID is invalid.', 'promptless-cpt-pages' )
			);
		}

		$backup = get_post_meta( $post_id, self::META_KEY_BACKUP, true );
		if ( ! is_array( $backup ) ) {
			return new WP_Error(
				'pcptpages_no_backup',
				__( 'No backup available to restore.', 'promptless-cpt-pages' )
			);
		}

		update_post_meta( $post_id, self::META_KEY, $backup );

		/**
		 * Fires after a post's groupings are restored from backup.
		 *
		 * @param int   $post_id Post ID.
		 * @param array $backup  Restored groupings.
		 */
		do_action( 'pcptpages_post_groupings_restored', $post_id, $backup );

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
	 * PCPTPages_Validator::validate_grouping_item for the contract.
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
	//   - Per-field value: `_pcptpages_field_{field_key}` (one meta per field)
	//   - Composite secondary values:
	//       rating count   => `_pcptpages_field_{field_key}_count`
	//       progress goal  => `_pcptpages_field_{field_key}_goal`
	//   - Visibility overrides: `_pcptpages_field_visibility` (JSON object)
	//
	// All writes validate through PCPTPages_Validator. Reads are tolerant of
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
				'pcptpages_invalid_post_id',
				__( 'Post ID is invalid.', 'promptless-cpt-pages' )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'pcptpages_post_not_found',
				/* translators: %d: post ID */
				sprintf( __( 'Post %d does not exist.', 'promptless-cpt-pages' ), $post_id )
			);
		}

		$cpt_slug = $post->post_type;
		if ( ! $this->cpts->exists( $cpt_slug ) ) {
			return new WP_Error(
				'pcptpages_post_type_not_managed',
				/* translators: %s: post type */
				sprintf( __( 'Post type %s is not managed by Promptless CPT Pages.', 'promptless-cpt-pages' ), $cpt_slug )
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
					'pcptpages_unknown_field_key',
					sprintf(
						/* translators: %1$s: field key, %2$s: CPT slug */
						__( 'Field %1$s is not registered on CPT %2$s.', 'promptless-cpt-pages' ),
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
		do_action( 'pcptpages_post_field_values_saved', $post_id, $values, $source );

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
	 * Validates the shape via PCPTPages_Validator::validate_post_field_visibility,
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
				'pcptpages_invalid_post_id',
				__( 'Post ID is invalid.', 'promptless-cpt-pages' )
			);
		}

		$valid = $this->validator->validate_post_field_visibility( $visibility );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// Normalize each entry to ensure both flags are always booleans —
		// downstream code can use direct bool comparison without isset().
		// A per-post map_position override (location fields) is preserved when
		// present and non-empty — this store doubles as the per-post override
		// store, so a location map can be repositioned per post.
		$normalized = array();
		foreach ( $visibility as $field_key => $flags ) {
			$entry = array(
				'card_hidden'   => isset( $flags['card_hidden'] ) ? (bool) $flags['card_hidden'] : false,
				'single_hidden' => isset( $flags['single_hidden'] ) ? (bool) $flags['single_hidden'] : false,
			);
			if ( isset( $flags['map_position'] ) && $flags['map_position'] !== ''
				&& in_array( $flags['map_position'], PCPTPages_Validator::MAP_POSITIONS, true ) ) {
				$entry['map_position'] = $flags['map_position'];
			}
			$normalized[ sanitize_key( $field_key ) ] = $entry;
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
		do_action( 'pcptpages_post_field_visibility_saved', $post_id, $normalized, $source );

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
	 * Resolve the effective single-post map placement for a `location`
	 * field on a specific post.
	 *
	 * Resolution mirrors a grouping's position resolution exactly: the
	 * per-post override (stored in the field-visibility/override store) wins;
	 * otherwise the field definition's `map_position` default; final fallback
	 * `below_main`. Returns one of MAP_POSITIONS (including `hidden`, meaning
	 * "do not render the map on this post").
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $field_key Field key.
	 * @param array  $field_def Field definition (for the map_position default).
	 * @return string One of PCPTPages_Validator::MAP_POSITIONS.
	 */
	public function get_effective_map_position( $post_id, $field_key, array $field_def ) {
		$post_id   = absint( $post_id );
		$field_key = sanitize_key( $field_key );

		// Per-post override.
		if ( $post_id > 0 && $field_key !== '' ) {
			$visibility = $this->get_field_visibility( $post_id );
			if ( isset( $visibility[ $field_key ]['map_position'] )
				&& in_array( $visibility[ $field_key ]['map_position'], PCPTPages_Validator::MAP_POSITIONS, true ) ) {
				return $visibility[ $field_key ]['map_position'];
			}
		}

		// CPT-level default from the definition.
		$default = $field_def['map_position'] ?? 'below_main';
		return in_array( $default, PCPTPages_Validator::MAP_POSITIONS, true ) ? $default : 'below_main';
	}

	/**
	 * Normalize a date field's input to the stored wall-clock form:
	 * 'Y-m-d H:i:s' when the input carries a time, 'Y-m-d' when it does not.
	 *
	 * The stored value is a wall clock in the field's timezone (the event
	 * timezone, else the site's) — that is how it renders and how the
	 * `__sort` companion reads it. An input that names its own offset
	 * ("2026-10-01T18:00:00-05:00", "…Z") is an absolute instant, so it is
	 * converted INTO that timezone. Before 2026-09-19 every input went
	 * through strtotime() and gmdate(), which stored the UTC clock time:
	 * 18:00 Chicago became 23:00 on the page, the calendar and the schema.
	 * Inputs without an offset are wall clocks already and keep their digits.
	 *
	 * Pure (no WordPress calls) so it is unit-testable.
	 *
	 * @param mixed  $value   Input: a date string, or a unix timestamp (stored as its date).
	 * @param string $tz_name IANA timezone the stored value is a wall clock in.
	 * @return mixed The normalized string, or $value unchanged when unparseable.
	 */
	public static function normalize_date_value( $value, $tz_name ) {
		$raw      = trim( (string) $value );
		$has_time = preg_match( '/\d{1,2}:\d{2}/', $raw ) === 1;

		try {
			$tz = new DateTimeZone( $tz_name !== '' ? $tz_name : 'UTC' );
		} catch ( \Exception $e ) {
			$tz = new DateTimeZone( 'UTC' );
		}

		if ( is_numeric( $value ) ) {
			// Unchanged from before: a bare timestamp stores its date only.
			return gmdate( 'Y-m-d', (int) $value );
		}

		$has_offset = $has_time && preg_match( '/(?:Z|[+-]\d{2}:?\d{2})$/i', $raw ) === 1;
		if ( $has_offset ) {
			try {
				$dt = ( new DateTimeImmutable( $raw ) )->setTimezone( $tz );
				return $dt->format( 'Y-m-d H:i:s' );
			} catch ( \Exception $e ) {
				return $value;
			}
		}

		// A wall clock: parse without any timezone arithmetic.
		$ts = strtotime( $raw . ' UTC' );
		if ( $ts === false ) {
			$ts = strtotime( $raw );
		}
		if ( $ts === false ) {
			return $value;
		}
		return $has_time ? gmdate( 'Y-m-d H:i:s', $ts ) : gmdate( 'Y-m-d', $ts );
	}

	/**
	 * Compute the normalized sort + UTC companion values for an event date.
	 *
	 * Pure function (no WordPress calls) so it is unit-testable in isolation.
	 * `$value` is the normalized stored value ('Y-m-d' or 'Y-m-d H:i:s').
	 *
	 *   - 'sort' is the site-local wall-clock as a numeric YYYYMMDDHHMMSS,
	 *     matching how the value renders. Used for range/sort meta_query.
	 *   - 'utc'  is the true unix timestamp, interpreting the wall-clock in
	 *     the event timezone (or the site timezone when none is set). Stored
	 *     for future viewer-local rendering; not used for v1 rendering.
	 *
	 * For all-day fields the time is forced to 00:00:00. Returns null/null
	 * for empty or unparseable input (caller deletes the companions).
	 *
	 * @param string $value    Normalized date string ('Y-m-d' or 'Y-m-d H:i:s').
	 * @param bool   $all_day  Whether the field is an all-day event date.
	 * @param string $event_tz IANA timezone id, or '' for the site timezone.
	 * @param string $site_tz  IANA timezone id for the site (caller passes wp_timezone()->getName()).
	 * @return array{sort:?int,utc:?int}
	 */
	public static function compute_date_sort_keys( $value, $all_day, $event_tz, $site_tz ) {
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return array( 'sort' => null, 'utc' => null );
		}

		$date_part = substr( $value, 0, 10 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_part ) ) {
			return array( 'sort' => null, 'utc' => null );
		}

		// Reduce to a canonical 'Y-m-d H:i:s' wall-clock string.
		if ( $all_day ) {
			$wall = $date_part . ' 00:00:00';
		} elseif ( strlen( $value ) > 10 && preg_match( '/\d{1,2}:\d{2}/', $value ) ) {
			$time_part = trim( substr( $value, 10 ) );
			if ( preg_match( '/^\d{1,2}:\d{2}$/', $time_part ) ) {
				$time_part .= ':00';
			}
			$wall = $date_part . ' ' . $time_part;
		} else {
			$wall = $date_part . ' 00:00:00';
		}

		// Sort key: digits of the wall-clock value (site-local), no tz math.
		$sort = (int) preg_replace( '/\D/', '', $wall );

		// UTC: interpret the wall-clock in the resolved timezone (DST-safe
		// because DateTimeZone applies the correct offset for the date).
		$tz_name = ( is_string( $event_tz ) && $event_tz !== '' ) ? $event_tz : (string) $site_tz;
		$utc     = null;
		try {
			$tz = new DateTimeZone( $tz_name );
			$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $wall, $tz );
			if ( $dt instanceof DateTimeImmutable ) {
				$utc = $dt->getTimestamp();
			}
		} catch ( \Exception $e ) {
			$utc = null;
		}

		return array( 'sort' => $sort, 'utc' => $utc );
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
			// Events vertical (v1.2): clear the date companions too.
			delete_post_meta( $post_id, $primary_meta_key . self::FIELD_SORT_SUFFIX );
			delete_post_meta( $post_id, $primary_meta_key . self::FIELD_UTC_SUFFIX );
			return true;
		}

		// Normalize value shapes that vary by display type.
		switch ( $display_type ) {
			case 'date':
				// Preserve time if the input string contains a time
				// component, otherwise normalize to date-only. This lets
				// the same display type cover both "May 20, 2026" (date-
				// only) and "May 20 · 2:30 PM" (event time) use cases.
				// The renderer's date_i18n() call works with either
				// stored shape.
				$primary = self::normalize_date_value(
					$primary,
					! empty( $field_def['event_timezone'] ) ? (string) $field_def['event_timezone'] : wp_timezone()->getName()
				);
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

			case 'location':
				// Address string — strip tags/control chars on write. The
				// renderer additionally escapes on output and the AISB embed
				// rawurlencodes it, so this is defense-in-depth, not the only
				// guard. docs/LOCATION_MAP_DESIGN.md § 5.2.
				$primary = sanitize_text_field( (string) $primary );
				break;

			default:
				$primary = (string) $primary;
		}

		update_post_meta( $post_id, $primary_meta_key, $primary );

		// Events vertical (v1.2): for date fields tagged with a semantic
		// role, write normalized sort + UTC companions so the query layer
		// can range/sort efficiently without parsing the display value.
		// Non-event date fields and all other types are untouched.
		if ( $display_type === 'date' && ! empty( $field_def['semantic_role'] ) ) {
			$sort_key   = $primary_meta_key . self::FIELD_SORT_SUFFIX;
			$utc_key    = $primary_meta_key . self::FIELD_UTC_SUFFIX;
			$companions = self::compute_date_sort_keys(
				$primary,
				! empty( $field_def['all_day'] ),
				isset( $field_def['event_timezone'] ) ? (string) $field_def['event_timezone'] : '',
				wp_timezone()->getName()
			);

			if ( $companions['sort'] !== null ) {
				update_post_meta( $post_id, $sort_key, $companions['sort'] );
			} else {
				delete_post_meta( $post_id, $sort_key );
			}

			if ( $companions['utc'] !== null ) {
				update_post_meta( $post_id, $utc_key, $companions['utc'] );
			} else {
				delete_post_meta( $post_id, $utc_key );
			}
		}

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
	// =====================================================================
	// External identity + upsert
	// =====================================================================

	/**
	 * Find the post that mirrors an upstream record, or 0.
	 *
	 * @param string $post_type   Registered CPT slug.
	 * @param string $source      Source key (e.g. "recdesk", "civicclerk").
	 * @param string $external_id Upstream identifier, as a string.
	 * @return int Post ID, or 0.
	 */
	public function find_external( $post_type, $source, $external_id ) {
		$post_type   = sanitize_key( $post_type );
		$source      = self::normalize_source( $source );
		$external_id = self::normalize_external_id( $external_id );
		if ( $post_type === '' || $source === '' || $external_id === '' ) {
			return 0;
		}
		$ids = get_posts( array(
			'post_type'        => $post_type,
			'post_status'      => 'any',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- identity lookup, two keys, bounded by post_type.
				array( 'key' => self::EXTERNAL_SOURCE_META, 'value' => $source ),
				array( 'key' => self::EXTERNAL_ID_META, 'value' => $external_id ),
			),
		) );
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * A post's external identity, or null when it has none.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null { source, external_id, hash, synced_at }
	 */
	public function get_external( $post_id ) {
		$source = (string) get_post_meta( $post_id, self::EXTERNAL_SOURCE_META, true );
		if ( $source === '' ) {
			return null;
		}
		return array(
			'source'      => $source,
			'external_id' => (string) get_post_meta( $post_id, self::EXTERNAL_ID_META, true ),
			'hash'        => (string) get_post_meta( $post_id, self::EXTERNAL_HASH_META, true ),
			'synced_at'   => (string) get_post_meta( $post_id, self::EXTERNAL_SYNCED_META, true ),
		);
	}

	/**
	 * Every post of a type that mirrors a source, with when it was last
	 * seen. Used to find records the upstream no longer returns.
	 *
	 * @param string $post_type Registered CPT slug.
	 * @param string $source    Source key.
	 * @return array<int, string> post_id => synced_at (MySQL, GMT)
	 */
	public function list_external( $post_type, $source ) {
		$post_type = sanitize_key( $post_type );
		$source    = self::normalize_source( $source );
		if ( $post_type === '' || $source === '' ) {
			return array();
		}
		$ids = get_posts( array(
			'post_type'        => $post_type,
			'post_status'      => 'any',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'meta_key'         => self::EXTERNAL_SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'       => $source, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		) );
		$out = array();
		foreach ( $ids as $id ) {
			$out[ (int) $id ] = (string) get_post_meta( (int) $id, self::EXTERNAL_SYNCED_META, true );
		}
		return $out;
	}

	/**
	 * Create or update the post that mirrors an upstream record.
	 *
	 * The record is the mapped payload:
	 *   title (required), content, excerpt, status (default publish on
	 *   create; on update the current status is kept unless given),
	 *   fields {key => value}, taxonomies {tax => [terms]},
	 *   featured_image_id, featured_image_url (sideloaded once per URL and
	 *   set as the thumbnail; ignored when featured_image_id is given).
	 * Only the keys present are written; a field the mapping does not name
	 * is left exactly as it is, so local edits to other fields survive a
	 * sync. When the payload hash matches what was last written the post
	 * is not touched at all (action "unchanged") — only synced_at moves.
	 *
	 * @param string $post_type   Registered CPT slug.
	 * @param string $source      Source key.
	 * @param string $external_id Upstream identifier.
	 * @param array  $record      Mapped payload (see above).
	 * @param string $origin      Write source for the audit hooks.
	 * @return array|WP_Error { post_id, action: created|updated|unchanged, permalink, warnings[] }
	 */
	public function upsert_external( $post_type, $source, $external_id, array $record, $origin = 'programmatic' ) {
		$post_type   = sanitize_key( $post_type );
		$source      = self::normalize_source( $source );
		$external_id = self::normalize_external_id( $external_id );

		if ( ! $this->cpts->exists( $post_type ) ) {
			/* translators: %s: post type slug */
			return new WP_Error( 'pcptpages_unregistered_post_type', sprintf( __( 'Post type %s is not registered through Post Runtime.', 'promptless-cpt-pages' ), $post_type ) );
		}
		if ( $source === '' ) {
			return new WP_Error( 'pcptpages_missing_source', __( 'source is required: a short key naming the system the record comes from.', 'promptless-cpt-pages' ) );
		}
		if ( $external_id === '' ) {
			return new WP_Error( 'pcptpages_missing_external_id', __( 'external_id is required: the upstream identifier of the record.', 'promptless-cpt-pages' ) );
		}
		$title = isset( $record['title'] ) ? trim( wp_strip_all_tags( (string) $record['title'] ) ) : '';
		if ( $title === '' ) {
			return new WP_Error( 'pcptpages_missing_post_title', __( 'title is required.', 'promptless-cpt-pages' ) );
		}

		$hash     = self::payload_hash( $record );
		$now      = current_time( 'mysql', true );
		$existing = $this->find_external( $post_type, $source, $external_id );
		$warnings = array();

		if ( $existing && (string) get_post_meta( $existing, self::EXTERNAL_HASH_META, true ) === $hash ) {
			update_post_meta( $existing, self::EXTERNAL_SYNCED_META, $now );
			return array( 'post_id' => $existing, 'action' => 'unchanged', 'permalink' => get_permalink( $existing ), 'warnings' => array() );
		}

		$post_args = array(
			'post_type'  => $post_type,
			'post_title' => $title,
		);
		if ( array_key_exists( 'content', $record ) ) {
			$post_args['post_content'] = wp_kses_post( (string) $record['content'] );
		}
		if ( array_key_exists( 'excerpt', $record ) ) {
			$post_args['post_excerpt'] = wp_kses_post( (string) $record['excerpt'] );
		}
		if ( ! empty( $record['status'] ) ) {
			$post_args['post_status'] = sanitize_key( (string) $record['status'] );
		} elseif ( ! $existing ) {
			$post_args['post_status'] = 'publish';
		}

		if ( $existing ) {
			$post_args['ID'] = $existing;
			$result          = wp_update_post( $post_args, true );
		} else {
			$result = wp_insert_post( $post_args, true );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$post_id = (int) $result;
		$action  = $existing ? 'updated' : 'created';

		if ( isset( $record['featured_image_id'] ) ) {
			$thumb = (int) $record['featured_image_id'];
			if ( $thumb > 0 && ! set_post_thumbnail( $post_id, $thumb ) ) {
				$warnings[] = sprintf( 'featured_image_id %d could not be set.', $thumb );
			}
		}
		if ( ! empty( $record['featured_image_url'] ) && empty( $record['featured_image_id'] ) ) {
			$warnings = array_merge( $warnings, $this->apply_featured_image_url( $post_id, (string) $record['featured_image_url'], $title ) );
		}
		if ( isset( $record['taxonomies'] ) && is_array( $record['taxonomies'] ) ) {
			$warnings = array_merge( $warnings, $this->apply_taxonomies( $post_id, $post_type, $record['taxonomies'] ) );
		}
		if ( isset( $record['fields'] ) && is_array( $record['fields'] ) && ! empty( $record['fields'] ) ) {
			$written = $this->set_field_values( $post_id, $record['fields'], $origin );
			if ( is_wp_error( $written ) ) {
				if ( ! $existing ) {
					// Atomic create, as the connector's create_post is: no half-made records.
					wp_delete_post( $post_id, true );
				}
				return $written;
			}
		}

		update_post_meta( $post_id, self::EXTERNAL_SOURCE_META, $source );
		update_post_meta( $post_id, self::EXTERNAL_ID_META, $external_id );
		update_post_meta( $post_id, self::EXTERNAL_HASH_META, $hash );
		update_post_meta( $post_id, self::EXTERNAL_SYNCED_META, $now );

		/**
		 * Fires after a record mirroring an upstream system was created or updated.
		 *
		 * @param int    $post_id     Post ID.
		 * @param string $action      "created" or "updated".
		 * @param string $source      Source key.
		 * @param string $external_id Upstream identifier.
		 * @param string $origin      Write source.
		 */
		do_action( 'pcptpages_external_record_upserted', $post_id, $action, $source, $external_id, $origin );

		return array( 'post_id' => $post_id, 'action' => $action, 'permalink' => get_permalink( $post_id ), 'warnings' => $warnings );
	}

	/**
	 * Set a record's featured image from a URL: sideload it (or reuse the
	 * attachment a previous call made from the same URL) and make it the
	 * thumbnail. Never fatal — every failure comes back as a warning and
	 * the record stands without the image, the way featured_image_id does.
	 *
	 * A feed re-sent every hour hits this for every record; the upsert's
	 * payload hash already skips unchanged records, and for a changed one
	 * whose image URL did not move the current thumbnail's source URL
	 * matches and nothing is downloaded.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     Image URL.
	 * @param string $alt     Alt text for a NEWLY created attachment (the record title); an existing attachment keeps its own.
	 * @return string[] Warnings; empty on success.
	 */
	public function apply_featured_image_url( $post_id, $url, $alt = '' ) {
		$url = self::normalize_image_url( $url );
		if ( $url === '' ) {
			return array( 'featured_image_url is not an http(s) URL; ignored.' );
		}
		$current = (int) get_post_thumbnail_id( $post_id );
		if ( $current > 0 && (string) get_post_meta( $current, self::IMAGE_SOURCE_META, true ) === $url ) {
			return array();
		}
		$attachment_id = $this->sideload_image( $url, $post_id, $alt );
		if ( is_wp_error( $attachment_id ) ) {
			return array( sprintf( 'featured_image_url could not be sideloaded (%s); the record was written without it.', $attachment_id->get_error_message() ) );
		}
		if ( ! set_post_thumbnail( $post_id, $attachment_id ) ) {
			return array( sprintf( 'featured_image_url was sideloaded as attachment %d but could not be set as the thumbnail.', $attachment_id ) );
		}
		return array();
	}

	/**
	 * Sideload an image URL into the media library, once per URL.
	 *
	 * Core's own path: `download_url()` (which goes through
	 * `wp_safe_remote_get()`, so private and loopback hosts are refused)
	 * then `media_handle_sideload()` (which validates the file as an image
	 * and generates the sizes). The source URL is kept on the attachment so
	 * the next call with the same URL returns the same attachment.
	 *
	 * @param string $url     Image URL (http/https).
	 * @param int    $post_id Post the attachment is filed under (0 for none).
	 * @param string $alt     Alt text when the attachment is created.
	 * @return int|WP_Error Attachment ID.
	 */
	public function sideload_image( $url, $post_id = 0, $alt = '' ) {
		$url = self::normalize_image_url( $url );
		if ( $url === '' ) {
			return new WP_Error( 'pcptpages_invalid_image_url', __( 'The image URL must be an http(s) URL.', 'promptless-cpt-pages' ) );
		}
		$existing = $this->find_attachment_by_source_url( $url );
		if ( $existing > 0 ) {
			return $existing;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$tmp = download_url( $url, 20 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$mime = wp_get_image_mime( $tmp );
		$name = is_string( $mime ) ? self::filename_for_image_url( $url, $mime ) : '';
		if ( $name === '' ) {
			wp_delete_file( $tmp );
			/* translators: %s: the MIME type detected, or "unknown" */
			return new WP_Error( 'pcptpages_not_an_image', sprintf( __( 'The URL did not return a supported image (%s).', 'promptless-cpt-pages' ), is_string( $mime ) ? $mime : 'unknown' ) );
		}

		$attachment_id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), (int) $post_id );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return $attachment_id;
		}
		$attachment_id = (int) $attachment_id;
		update_post_meta( $attachment_id, self::IMAGE_SOURCE_META, $url );
		$alt = sanitize_text_field( (string) $alt );
		if ( $alt !== '' && (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) === '' ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}
		return $attachment_id;
	}

	/**
	 * The attachment previously sideloaded from a URL, or 0.
	 *
	 * @param string $url Normalised URL.
	 * @return int
	 */
	private function find_attachment_by_source_url( $url ) {
		$ids = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => self::IMAGE_SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $url, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		) );
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Trim and validate an image URL; '' when it is not http(s).
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	public static function normalize_image_url( $url ) {
		$url = trim( (string) $url );
		if ( $url === '' || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		$clean = esc_url_raw( $url, array( 'http', 'https' ) );
		return is_string( $clean ) ? $clean : '';
	}

	/**
	 * The filename a sideloaded image is saved under: the URL path's
	 * basename, or a hash-derived name when the path has none, with an
	 * image extension — the URL's own when it is one, else the one for the
	 * detected MIME type. '' when neither gives an image extension.
	 *
	 * @param string $url  Image URL.
	 * @param string $mime Detected MIME type ('' when unknown).
	 * @return string
	 */
	public static function filename_for_image_url( $url, $mime = '' ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$base = is_string( $path ) ? basename( rawurldecode( $path ) ) : '';
		$ext  = strtolower( (string) pathinfo( $base, PATHINFO_EXTENSION ) );
		$name = (string) pathinfo( $base, PATHINFO_FILENAME );
		$accepted = array_merge( array_values( self::IMAGE_MIME_EXTENSIONS ), array( 'jpeg' ) );
		if ( ! in_array( $ext, $accepted, true ) ) {
			$ext = isset( self::IMAGE_MIME_EXTENSIONS[ $mime ] ) ? self::IMAGE_MIME_EXTENSIONS[ $mime ] : '';
		}
		if ( $ext === '' ) {
			return '';
		}
		if ( $name === '' || $name === '.' ) {
			$name = 'image-' . substr( md5( $url ), 0, 8 );
		}
		return sanitize_file_name( $name . '.' . $ext );
	}

	/**
	 * Assign terms by name (created when missing) or ID, per taxonomy.
	 * Unregistered taxonomies are skipped with a warning, never fatal.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $post_type  CPT slug.
	 * @param array  $taxonomies { tax_slug => string[]|string }
	 * @return string[] Warnings.
	 */
	public function apply_taxonomies( $post_id, $post_type, array $taxonomies ) {
		$warnings   = array();
		$registered = get_object_taxonomies( $post_type );
		foreach ( $taxonomies as $tax_slug => $terms ) {
			$tax_slug = sanitize_key( (string) $tax_slug );
			if ( $tax_slug === '' ) {
				continue;
			}
			if ( ! taxonomy_exists( $tax_slug ) || ! in_array( $tax_slug, $registered, true ) ) {
				$warnings[] = sprintf( 'taxonomy "%s" is not registered for post type "%s"; skipped.', $tax_slug, $post_type );
				continue;
			}
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
				if ( ctype_digit( $term ) ) {
					$maybe = get_term( (int) $term, $tax_slug );
					if ( $maybe && ! is_wp_error( $maybe ) ) {
						$term_ids[] = (int) $maybe->term_id;
						continue;
					}
				}
				$found = term_exists( $term, $tax_slug );
				if ( $found ) {
					$term_ids[] = (int) ( is_array( $found ) ? $found['term_id'] : $found );
					continue;
				}
				$created = wp_insert_term( $term, $tax_slug );
				if ( is_wp_error( $created ) ) {
					$warnings[] = sprintf( 'term "%s" in taxonomy "%s" could not be created: %s', $term, $tax_slug, $created->get_error_message() );
					continue;
				}
				$term_ids[] = (int) $created['term_id'];
			}
			$set = wp_set_object_terms( $post_id, $term_ids, $tax_slug, false );
			if ( is_wp_error( $set ) ) {
				$warnings[] = sprintf( 'terms for taxonomy "%s" could not be set: %s', $tax_slug, $set->get_error_message() );
			}
		}
		return $warnings;
	}

	/**
	 * Stable hash of a mapped payload: keys sorted at every level so the
	 * same data in a different order is the same record.
	 */
	public static function payload_hash( array $record ) {
		return md5( wp_json_encode( self::ksort_deep( $record ) ) );
	}

	private static function ksort_deep( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		foreach ( $value as $k => $v ) {
			$value[ $k ] = self::ksort_deep( $v );
		}
		if ( ! $is_list ) {
			ksort( $value );
		}
		return $value;
	}

	public static function normalize_source( $source ) {
		return sanitize_key( (string) $source );
	}

	public static function normalize_external_id( $external_id ) {
		if ( is_int( $external_id ) || is_float( $external_id ) ) {
			$external_id = (string) $external_id;
		}
		return is_string( $external_id ) ? substr( trim( $external_id ), 0, 191 ) : '';
	}

	/**
	 * Remove a record: to the trash by default, permanently when forced.
	 *
	 * Exists because wp_delete_post( $id, false ) does NOT trash a custom
	 * post type. Core only diverts 'post' and 'page' to wp_trash_post();
	 * every other type is deleted permanently whatever $force_delete says.
	 * The connector's delete_post called it that way for records it
	 * documented as "trashed by default (recoverable)", and answered
	 * permanent:false while the rows were gone (found by the 2026-09-19
	 * pressure test: five records "trashed" that way were not in the trash).
	 *
	 * `permanent` is read back from the database rather than assumed from
	 * $force, because a site with EMPTY_TRASH_DAYS set to 0 makes
	 * wp_trash_post() delete permanently too, and the caller must be told.
	 *
	 * @param int  $post_id Record id.
	 * @param bool $force   true = delete permanently; false = trash.
	 * @return array{deleted: bool, permanent: bool, already_trashed: bool}
	 */
	public function remove_post( $post_id, $force = false ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return array( 'deleted' => false, 'permanent' => false, 'already_trashed' => false );
		}

		if ( ! $force && 'trash' === $post->post_status ) {
			// Nothing to do: trashing twice must not become a permanent
			// delete, which is what core does for a trashed post.
			return array( 'deleted' => true, 'permanent' => false, 'already_trashed' => true );
		}

		$result = $force ? wp_delete_post( $post_id, true ) : wp_trash_post( $post_id );

		return array(
			'deleted'         => (bool) $result,
			'permanent'       => (bool) $result && null === get_post( $post_id ),
			'already_trashed' => false,
		);
	}
}
