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
	 * Constructor.
	 *
	 * @param PRE_CPT_Registry      $cpts      CPT registry.
	 * @param PRE_Grouping_Registry $groupings Grouping registry.
	 * @param PRE_Validator|null    $validator Optional validator dependency.
	 */
	public function __construct( PRE_CPT_Registry $cpts, PRE_Grouping_Registry $groupings, $validator = null ) {
		$this->cpts      = $cpts;
		$this->groupings = $groupings;
		$this->validator = $validator ?: new PRE_Validator();
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
}
