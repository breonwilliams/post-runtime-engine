<?php
/**
 * Strict validator for Post Runtime Engine data structures.
 *
 * Every write path through PRE_CPT_Registry, PRE_Grouping_Registry, and
 * PRE_Post_Data goes through this validator before persisting. Returns
 * `true` for valid input, `WP_Error` for invalid input.
 *
 * The validator is intentionally strict: malformed input is rejected at
 * save time, not glossed over at render time. This is what keeps Cowork
 * drift from producing silent rendering bugs in production.
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validation routines for CPT definitions, grouping definitions, post
 * groupings, and individual grouping items.
 */
class PRE_Validator {

	/**
	 * Allowed layout variants. Locked in v1.0; v1.1+ may add more via filter.
	 */
	const VARIANTS = array(
		'compact-grid',
		'card-grid',
		'featured-card',
		'horizontal-row',
	);

	/**
	 * Allowed positions for groupings within a single-post template.
	 */
	const POSITIONS = array(
		'above_main',
		'below_main',
		'sidebar',
	);

	/**
	 * Allowed source modes for grouping items.
	 */
	const SOURCE_MODES = array(
		'manual',
		'child_posts',
		'taxonomy_match',
	);

	/**
	 * Reserved CPT slugs that this plugin must never register over. WordPress
	 * core types plus types known to be owned by other plugins in the
	 * FlowMint stack or by widely-used third-party plugins.
	 */
	const RESERVED_CPT_SLUGS = array(
		'post',
		'page',
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		// FlowMint stack — owned by other plugins on the same install.
		'product',           // WooCommerce.
		'shop_order',        // WooCommerce.
		'shop_coupon',       // WooCommerce.
	);

	/**
	 * Maximum length for textual fields. Prevents pathological inputs from
	 * overwhelming the database and the rendering pipeline. Generous defaults
	 * — real content rarely approaches these limits.
	 */
	const MAX_HEADING_LEN         = 200;
	const MAX_SUPPORTING_TEXT_LEN = 1000;
	const MAX_LINK_LEN            = 2048;
	const MAX_LABEL_LEN           = 200;
	const MAX_ITEMS_PER_GROUPING  = 100;
	const MAX_GROUPINGS_PER_POST  = 24;

	// ---------------------------------------------------------------------
	// CPT definitions
	// ---------------------------------------------------------------------

	/**
	 * Validate a CPT definition.
	 *
	 * Required fields: slug, label_singular, label_plural.
	 * Optional fields: supports[], public, has_archive, hierarchical,
	 * rest_base, capability_type, menu_icon, menu_position, taxonomies[],
	 * description.
	 *
	 * @param array $definition CPT definition.
	 * @return true|WP_Error
	 */
	public function validate_cpt_definition( $definition ) {
		if ( ! is_array( $definition ) ) {
			return new WP_Error(
				'pre_invalid_cpt',
				__( 'CPT definition must be an array.', 'post-runtime-engine' )
			);
		}

		// Slug — required, lowercase, sanitized, not reserved.
		if ( empty( $definition['slug'] ) || ! is_string( $definition['slug'] ) ) {
			return new WP_Error(
				'pre_missing_slug',
				__( 'CPT definition must include a non-empty slug string.', 'post-runtime-engine' )
			);
		}

		$slug = sanitize_key( $definition['slug'] );
		if ( $slug !== $definition['slug'] ) {
			return new WP_Error(
				'pre_invalid_slug',
				/* translators: %s: the rejected slug */
				sprintf( __( 'CPT slug %s contains invalid characters. Use lowercase letters, numbers, and underscores only.', 'post-runtime-engine' ), $definition['slug'] )
			);
		}

		// WordPress imposes a 20-character limit on post type slugs.
		if ( strlen( $slug ) > 20 ) {
			return new WP_Error(
				'pre_slug_too_long',
				__( 'CPT slug must be 20 characters or fewer (WordPress limitation).', 'post-runtime-engine' )
			);
		}

		if ( in_array( $slug, self::RESERVED_CPT_SLUGS, true ) ) {
			return new WP_Error(
				'pre_reserved_slug',
				/* translators: %s: the reserved slug */
				sprintf( __( 'CPT slug %s is reserved by WordPress core or another plugin.', 'post-runtime-engine' ), $slug )
			);
		}

		// Labels — required.
		if ( empty( $definition['label_singular'] ) || ! is_string( $definition['label_singular'] ) ) {
			return new WP_Error(
				'pre_missing_label_singular',
				__( 'CPT definition must include a non-empty label_singular string.', 'post-runtime-engine' )
			);
		}

		if ( empty( $definition['label_plural'] ) || ! is_string( $definition['label_plural'] ) ) {
			return new WP_Error(
				'pre_missing_label_plural',
				__( 'CPT definition must include a non-empty label_plural string.', 'post-runtime-engine' )
			);
		}

		if ( strlen( $definition['label_singular'] ) > self::MAX_LABEL_LEN
			|| strlen( $definition['label_plural'] ) > self::MAX_LABEL_LEN ) {
			return new WP_Error(
				'pre_label_too_long',
				/* translators: %d: the maximum allowed label length */
				sprintf( __( 'CPT labels must be %d characters or fewer.', 'post-runtime-engine' ), self::MAX_LABEL_LEN )
			);
		}

		// supports[] — optional; if present, must be an array of known strings.
		if ( isset( $definition['supports'] ) ) {
			if ( ! is_array( $definition['supports'] ) ) {
				return new WP_Error(
					'pre_invalid_supports',
					__( 'CPT supports must be an array.', 'post-runtime-engine' )
				);
			}

			$valid_supports = array(
				'title',
				'editor',
				'author',
				'thumbnail',
				'excerpt',
				'trackbacks',
				'custom-fields',
				'comments',
				'revisions',
				'page-attributes',
				'post-formats',
			);

			foreach ( $definition['supports'] as $support ) {
				if ( ! in_array( $support, $valid_supports, true ) ) {
					return new WP_Error(
						'pre_invalid_support',
						/* translators: %s: the invalid support string */
						sprintf( __( 'Unknown CPT support feature: %s', 'post-runtime-engine' ), is_string( $support ) ? $support : 'non-string' )
					);
				}
			}
		}

		// taxonomies[] — optional; if present, must be an array of valid keys.
		if ( isset( $definition['taxonomies'] ) ) {
			if ( ! is_array( $definition['taxonomies'] ) ) {
				return new WP_Error(
					'pre_invalid_taxonomies',
					__( 'CPT taxonomies must be an array of taxonomy slugs.', 'post-runtime-engine' )
				);
			}

			foreach ( $definition['taxonomies'] as $taxonomy ) {
				if ( ! is_string( $taxonomy ) || sanitize_key( $taxonomy ) !== $taxonomy ) {
					return new WP_Error(
						'pre_invalid_taxonomy',
						/* translators: %s: the invalid taxonomy slug */
						sprintf( __( 'Invalid taxonomy slug: %s', 'post-runtime-engine' ), is_string( $taxonomy ) ? $taxonomy : 'non-string' )
					);
				}
			}
		}

		// Booleans — coerce silently if present, but reject non-bool-coercible inputs.
		foreach ( array( 'public', 'has_archive', 'hierarchical', 'show_in_rest', 'show_in_menu' ) as $bool_key ) {
			if ( isset( $definition[ $bool_key ] ) && ! is_bool( $definition[ $bool_key ] ) && ! is_int( $definition[ $bool_key ] ) ) {
				return new WP_Error(
					'pre_invalid_boolean',
					/* translators: %s: the boolean field key */
					sprintf( __( 'CPT field %s must be true or false.', 'post-runtime-engine' ), $bool_key )
				);
			}
		}

		// rest_base — optional; sanitize-key match.
		if ( isset( $definition['rest_base'] ) ) {
			if ( ! is_string( $definition['rest_base'] ) || sanitize_key( $definition['rest_base'] ) !== $definition['rest_base'] ) {
				return new WP_Error(
					'pre_invalid_rest_base',
					__( 'CPT rest_base must be a lowercase slug.', 'post-runtime-engine' )
				);
			}
		}

		return true;
	}

	// ---------------------------------------------------------------------
	// Grouping definitions
	// ---------------------------------------------------------------------

	/**
	 * Validate a grouping definition.
	 *
	 * Required: key, label, default_variant, default_position.
	 * Optional: default_source (defaults to 'manual'), max_items, field
	 * requirements (heading_required, supporting_text_required, etc.),
	 * default_taxonomy (only meaningful for taxonomy_match source).
	 *
	 * @param array $definition Grouping definition.
	 * @return true|WP_Error
	 */
	public function validate_grouping_definition( $definition ) {
		if ( ! is_array( $definition ) ) {
			return new WP_Error(
				'pre_invalid_grouping',
				__( 'Grouping definition must be an array.', 'post-runtime-engine' )
			);
		}

		// Key — required, sanitize_key match.
		if ( empty( $definition['key'] ) || ! is_string( $definition['key'] ) ) {
			return new WP_Error(
				'pre_missing_grouping_key',
				__( 'Grouping definition must include a non-empty key.', 'post-runtime-engine' )
			);
		}

		if ( sanitize_key( $definition['key'] ) !== $definition['key'] ) {
			return new WP_Error(
				'pre_invalid_grouping_key',
				/* translators: %s: the rejected key */
				sprintf( __( 'Grouping key %s contains invalid characters. Use lowercase letters, numbers, and underscores only.', 'post-runtime-engine' ), $definition['key'] )
			);
		}

		// Label — required.
		if ( empty( $definition['label'] ) || ! is_string( $definition['label'] ) ) {
			return new WP_Error(
				'pre_missing_grouping_label',
				__( 'Grouping definition must include a label.', 'post-runtime-engine' )
			);
		}

		if ( strlen( $definition['label'] ) > self::MAX_LABEL_LEN ) {
			return new WP_Error(
				'pre_grouping_label_too_long',
				/* translators: %d: maximum label length */
				sprintf( __( 'Grouping label must be %d characters or fewer.', 'post-runtime-engine' ), self::MAX_LABEL_LEN )
			);
		}

		// Default variant — required.
		if ( empty( $definition['default_variant'] )
			|| ! in_array( $definition['default_variant'], self::VARIANTS, true ) ) {
			return new WP_Error(
				'pre_invalid_default_variant',
				/* translators: %1$s: the invalid variant; %2$s: list of allowed variants */
				sprintf(
					__( 'Grouping default_variant %1$s is not one of: %2$s', 'post-runtime-engine' ),
					isset( $definition['default_variant'] ) ? (string) $definition['default_variant'] : 'null',
					implode( ', ', self::VARIANTS )
				)
			);
		}

		// Default position — required.
		if ( empty( $definition['default_position'] )
			|| ! in_array( $definition['default_position'], self::POSITIONS, true ) ) {
			return new WP_Error(
				'pre_invalid_default_position',
				/* translators: %1$s: the invalid position; %2$s: list of allowed positions */
				sprintf(
					__( 'Grouping default_position %1$s is not one of: %2$s', 'post-runtime-engine' ),
					isset( $definition['default_position'] ) ? (string) $definition['default_position'] : 'null',
					implode( ', ', self::POSITIONS )
				)
			);
		}

		// Default source — optional; defaults to 'manual'.
		if ( isset( $definition['default_source'] ) ) {
			$source_check = $this->validate_source_value( $definition['default_source'] );
			if ( is_wp_error( $source_check ) ) {
				return $source_check;
			}
		}

		// max_items — optional; positive integer if present.
		if ( isset( $definition['max_items'] ) ) {
			if ( ! is_int( $definition['max_items'] ) || $definition['max_items'] < 1 ) {
				return new WP_Error(
					'pre_invalid_max_items',
					__( 'Grouping max_items must be a positive integer.', 'post-runtime-engine' )
				);
			}

			if ( $definition['max_items'] > self::MAX_ITEMS_PER_GROUPING ) {
				return new WP_Error(
					'pre_max_items_too_large',
					/* translators: %d: hard upper bound on items per grouping */
					sprintf( __( 'Grouping max_items cannot exceed %d.', 'post-runtime-engine' ), self::MAX_ITEMS_PER_GROUPING )
				);
			}
		}

		// Required-field flags — booleans only.
		foreach ( array( 'heading_required', 'supporting_text_required', 'link_required', 'icon_or_image_required' ) as $flag ) {
			if ( isset( $definition[ $flag ] ) && ! is_bool( $definition[ $flag ] ) ) {
				return new WP_Error(
					'pre_invalid_grouping_flag',
					/* translators: %s: the flag key */
					sprintf( __( 'Grouping field %s must be true or false.', 'post-runtime-engine' ), $flag )
				);
			}
		}

		// Featured-card variant only allows a single item — encode this in the
		// grouping definition for clarity. If user sets variant=featured-card,
		// max_items must be 1 (or unset).
		if ( $definition['default_variant'] === 'featured-card'
			&& isset( $definition['max_items'] )
			&& $definition['max_items'] !== 1 ) {
			return new WP_Error(
				'pre_featured_card_max_items',
				__( 'featured-card variant requires max_items=1 (or unset).', 'post-runtime-engine' )
			);
		}

		return true;
	}

	// ---------------------------------------------------------------------
	// Per-post groupings
	// ---------------------------------------------------------------------

	/**
	 * Validate the full per-post grouping array. Each entry must reference
	 * a known grouping definition for the given CPT, and the items must
	 * match the grouping definition's shape requirements.
	 *
	 * @param array  $groupings    Per-post groupings array.
	 * @param string $cpt_slug     CPT slug the post belongs to.
	 * @param array  $cpt_groupings Map of grouping definitions for the CPT
	 *                             keyed by grouping key (passed in by the
	 *                             caller — this validator does not look up
	 *                             options on its own).
	 * @return true|WP_Error
	 */
	public function validate_post_groupings( $groupings, $cpt_slug, array $cpt_groupings ) {
		if ( ! is_array( $groupings ) ) {
			return new WP_Error(
				'pre_invalid_post_groupings',
				__( 'Post groupings must be an array.', 'post-runtime-engine' )
			);
		}

		if ( count( $groupings ) > self::MAX_GROUPINGS_PER_POST ) {
			return new WP_Error(
				'pre_too_many_groupings',
				/* translators: %d: max groupings per post */
				sprintf( __( 'Post cannot have more than %d groupings.', 'post-runtime-engine' ), self::MAX_GROUPINGS_PER_POST )
			);
		}

		$seen_keys = array();

		foreach ( $groupings as $index => $grouping ) {
			if ( ! is_array( $grouping ) ) {
				return new WP_Error(
					'pre_invalid_grouping_entry',
					/* translators: %d: the position in the array */
					sprintf( __( 'Grouping entry at index %d is not an array.', 'post-runtime-engine' ), $index )
				);
			}

			// grouping_key — required, must reference an existing definition.
			if ( empty( $grouping['grouping_key'] ) || ! is_string( $grouping['grouping_key'] ) ) {
				return new WP_Error(
					'pre_missing_post_grouping_key',
					/* translators: %d: array index */
					sprintf( __( 'Grouping entry at index %d is missing grouping_key.', 'post-runtime-engine' ), $index )
				);
			}

			$grouping_key = $grouping['grouping_key'];

			if ( ! isset( $cpt_groupings[ $grouping_key ] ) ) {
				return new WP_Error(
					'pre_unknown_grouping_key',
					/* translators: %1$s: grouping key; %2$s: CPT slug */
					sprintf( __( 'Grouping %1$s is not defined for CPT %2$s.', 'post-runtime-engine' ), $grouping_key, $cpt_slug )
				);
			}

			// A grouping_key may appear at most once per post. (Avoids
			// confusion about which instance "wins" at render time.)
			if ( in_array( $grouping_key, $seen_keys, true ) ) {
				return new WP_Error(
					'pre_duplicate_post_grouping',
					/* translators: %s: grouping key */
					sprintf( __( 'Grouping %s appears more than once on this post.', 'post-runtime-engine' ), $grouping_key )
				);
			}
			$seen_keys[] = $grouping_key;

			$definition = $cpt_groupings[ $grouping_key ];

			// Position — must be valid.
			$position = isset( $grouping['position'] ) ? $grouping['position'] : $definition['default_position'];
			if ( ! in_array( $position, self::POSITIONS, true ) ) {
				return new WP_Error(
					'pre_invalid_post_position',
					/* translators: %1$s: invalid position; %2$s: grouping key */
					sprintf( __( 'Position %1$s is not valid for grouping %2$s.', 'post-runtime-engine' ), (string) $position, $grouping_key )
				);
			}

			// variant_override — optional; if set, must be valid.
			if ( isset( $grouping['variant_override'] ) && $grouping['variant_override'] !== null ) {
				if ( ! in_array( $grouping['variant_override'], self::VARIANTS, true ) ) {
					return new WP_Error(
						'pre_invalid_variant_override',
						/* translators: %1$s: invalid variant; %2$s: grouping key */
						sprintf( __( 'variant_override %1$s is not valid for grouping %2$s.', 'post-runtime-engine' ), (string) $grouping['variant_override'], $grouping_key )
					);
				}
			}

			// Source — optional; defaults to 'manual'.
			$source = isset( $grouping['source'] ) ? $grouping['source'] : 'manual';
			$source_check = $this->validate_source_value( $source );
			if ( is_wp_error( $source_check ) ) {
				return $source_check;
			}

			// Source-specific consistency checks.
			$is_auto = ( is_string( $source ) && $source !== 'manual' )
				|| ( is_array( $source ) && isset( $source['type'] ) && $source['type'] !== 'manual' );

			if ( $is_auto ) {
				// Auto sources should NOT carry a stored items array — the
				// renderer ignores it and we don't want stale ghost data.
				if ( ! empty( $grouping['items'] ) ) {
					return new WP_Error(
						'pre_auto_source_has_items',
						/* translators: %s: grouping key */
						sprintf( __( 'Grouping %s has source set to an auto mode but also has stored items. Auto sources must use an empty items array.', 'post-runtime-engine' ), $grouping_key )
					);
				}
			} else {
				// Manual source — items array is required and validated.
				$items = isset( $grouping['items'] ) ? $grouping['items'] : array();

				if ( ! is_array( $items ) ) {
					return new WP_Error(
						'pre_invalid_items',
						/* translators: %s: grouping key */
						sprintf( __( 'Grouping %s has a non-array items field.', 'post-runtime-engine' ), $grouping_key )
					);
				}

				if ( count( $items ) > self::MAX_ITEMS_PER_GROUPING ) {
					return new WP_Error(
						'pre_too_many_items',
						/* translators: %1$s: grouping key; %2$d: max items */
						sprintf( __( 'Grouping %1$s has more than %2$d items.', 'post-runtime-engine' ), $grouping_key, self::MAX_ITEMS_PER_GROUPING )
					);
				}

				// Per-grouping max_items override.
				if ( isset( $definition['max_items'] ) && count( $items ) > $definition['max_items'] ) {
					return new WP_Error(
						'pre_grouping_max_items_exceeded',
						/* translators: %1$s: grouping key; %2$d: max items for this grouping */
						sprintf( __( 'Grouping %1$s exceeds its max_items limit of %2$d.', 'post-runtime-engine' ), $grouping_key, $definition['max_items'] )
					);
				}

				// Validate each item.
				foreach ( $items as $item_index => $item ) {
					$item_check = $this->validate_grouping_item( $item, $definition );
					if ( is_wp_error( $item_check ) ) {
						$item_check->add_data(
							array(
								'grouping_key' => $grouping_key,
								'item_index'   => $item_index,
							)
						);
						return $item_check;
					}
				}
			}
		}

		return true;
	}

	/**
	 * Validate a single grouping item against its grouping definition.
	 *
	 * Item shape: { image_id, icon_id, heading, supporting_text, link, link_post_id }.
	 * - image_id and icon_id are mutually exclusive
	 * - heading is required (per the standard primitive); definitions may
	 *   relax this via heading_required=false but defaults to true
	 * - supporting_text and link are optional unless required by definition
	 * - link, if present, must be a valid URL or anchor reference
	 * - link_post_id, if present, must be a positive integer; this is the
	 *   site-portable counterpart to the link string (so domain/permalink
	 *   migrations don't break internal links). The renderer prefers
	 *   resolving via get_permalink(link_post_id) when set, falling back to
	 *   the stored link string otherwise.
	 *
	 * @param mixed $item       The item to validate.
	 * @param array $definition Grouping definition.
	 * @return true|WP_Error
	 */
	public function validate_grouping_item( $item, array $definition ) {
		if ( ! is_array( $item ) ) {
			return new WP_Error(
				'pre_invalid_item',
				__( 'Grouping item must be an array.', 'post-runtime-engine' )
			);
		}

		// Mutual exclusion between image_id and icon_id.
		$has_image = ! empty( $item['image_id'] );
		$has_icon  = ! empty( $item['icon_id'] );

		if ( $has_image && $has_icon ) {
			return new WP_Error(
				'pre_image_icon_conflict',
				__( 'Grouping item cannot have both image_id and icon_id set; pick one.', 'post-runtime-engine' )
			);
		}

		// image_id — must be a positive integer that resolves to an attachment.
		if ( $has_image ) {
			if ( ! is_int( $item['image_id'] ) || $item['image_id'] < 1 ) {
				return new WP_Error(
					'pre_invalid_image_id',
					__( 'Grouping item image_id must be a positive integer.', 'post-runtime-engine' )
				);
			}

			// Confirm the attachment exists.
			$attachment = get_post( $item['image_id'] );
			if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
				return new WP_Error(
					'pre_image_not_found',
					/* translators: %d: attachment ID */
					sprintf( __( 'image_id %d does not reference a valid attachment.', 'post-runtime-engine' ), $item['image_id'] )
				);
			}
		}

		// icon_id — must be a string in the registered icon library.
		if ( $has_icon ) {
			if ( ! is_string( $item['icon_id'] ) ) {
				return new WP_Error(
					'pre_invalid_icon_id',
					__( 'Grouping item icon_id must be a string.', 'post-runtime-engine' )
				);
			}

			// PRE_Icon_Library is autoloaded; defer to it for the actual
			// registry of valid icons.
			if ( class_exists( 'PRE_Icon_Library' ) && ! PRE_Icon_Library::has( $item['icon_id'] ) ) {
				return new WP_Error(
					'pre_unknown_icon',
					/* translators: %s: the unknown icon ID */
					sprintf( __( 'Unknown icon: %s', 'post-runtime-engine' ), $item['icon_id'] )
				);
			}
		}

		// icon_or_image_required — if set on definition, item must have one.
		if ( ! empty( $definition['icon_or_image_required'] ) && ! $has_image && ! $has_icon ) {
			return new WP_Error(
				'pre_missing_image_or_icon',
				/* translators: %s: grouping key (filled in by caller via add_data) */
				__( 'Grouping requires an image or icon on every item.', 'post-runtime-engine' )
			);
		}

		// Heading — required by default; can be made optional per definition.
		$heading_required = ! isset( $definition['heading_required'] ) || $definition['heading_required'];
		$heading          = isset( $item['heading'] ) ? $item['heading'] : '';

		if ( ! is_string( $heading ) ) {
			return new WP_Error(
				'pre_invalid_heading',
				__( 'Grouping item heading must be a string.', 'post-runtime-engine' )
			);
		}

		if ( $heading_required && trim( $heading ) === '' ) {
			return new WP_Error(
				'pre_missing_heading',
				__( 'Grouping item heading is required.', 'post-runtime-engine' )
			);
		}

		if ( strlen( $heading ) > self::MAX_HEADING_LEN ) {
			return new WP_Error(
				'pre_heading_too_long',
				/* translators: %d: max heading length */
				sprintf( __( 'Grouping item heading exceeds the %d character limit.', 'post-runtime-engine' ), self::MAX_HEADING_LEN )
			);
		}

		// Supporting text — optional unless flagged.
		$supporting_text = isset( $item['supporting_text'] ) ? $item['supporting_text'] : null;
		if ( $supporting_text !== null ) {
			if ( ! is_string( $supporting_text ) ) {
				return new WP_Error(
					'pre_invalid_supporting_text',
					__( 'Grouping item supporting_text must be a string or null.', 'post-runtime-engine' )
				);
			}

			if ( strlen( $supporting_text ) > self::MAX_SUPPORTING_TEXT_LEN ) {
				return new WP_Error(
					'pre_supporting_text_too_long',
					/* translators: %d: max length */
					sprintf( __( 'Grouping item supporting_text exceeds the %d character limit.', 'post-runtime-engine' ), self::MAX_SUPPORTING_TEXT_LEN )
				);
			}
		}

		if ( ! empty( $definition['supporting_text_required'] )
			&& ( $supporting_text === null || trim( $supporting_text ) === '' ) ) {
			return new WP_Error(
				'pre_missing_supporting_text',
				__( 'Grouping item supporting_text is required.', 'post-runtime-engine' )
			);
		}

		// Link — optional unless flagged.
		$link = isset( $item['link'] ) ? $item['link'] : null;
		if ( $link !== null && $link !== '' ) {
			if ( ! is_string( $link ) ) {
				return new WP_Error(
					'pre_invalid_link',
					__( 'Grouping item link must be a string, null, or empty.', 'post-runtime-engine' )
				);
			}

			if ( strlen( $link ) > self::MAX_LINK_LEN ) {
				return new WP_Error(
					'pre_link_too_long',
					/* translators: %d: max link length */
					sprintf( __( 'Grouping item link exceeds the %d character limit.', 'post-runtime-engine' ), self::MAX_LINK_LEN )
				);
			}

			$link_check = $this->validate_link( $link );
			if ( is_wp_error( $link_check ) ) {
				return $link_check;
			}
		}

		if ( ! empty( $definition['link_required'] ) && ( $link === null || trim( (string) $link ) === '' ) ) {
			return new WP_Error(
				'pre_missing_link',
				__( 'Grouping item link is required.', 'post-runtime-engine' )
			);
		}

		// link_post_id — optional, nullable, positive integer when set.
		// Validates structure only — we do NOT require the post to currently
		// exist, because:
		//   (a) drafts/scheduled posts the user is linking to ARE valid
		//   (b) if the post is later trashed, render_item gracefully falls
		//       back to the stored link string (validator stays write-safe).
		$link_post_id = isset( $item['link_post_id'] ) ? $item['link_post_id'] : null;
		if ( $link_post_id !== null && $link_post_id !== '' && $link_post_id !== 0 ) {
			if ( ! is_int( $link_post_id ) || $link_post_id < 1 ) {
				return new WP_Error(
					'pre_invalid_link_post_id',
					__( 'Grouping item link_post_id must be a positive integer or null.', 'post-runtime-engine' )
				);
			}
		}

		return true;
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * Validate a `source` value, accepting either a simple string ("manual",
	 * "child_posts") or a config object ({ "type": "taxonomy_match", ... }).
	 *
	 * @param mixed $source Source value to validate.
	 * @return true|WP_Error
	 */
	public function validate_source_value( $source ) {
		// String form: "manual" | "child_posts" | (future simple modes).
		if ( is_string( $source ) ) {
			if ( ! in_array( $source, self::SOURCE_MODES, true ) ) {
				return new WP_Error(
					'pre_invalid_source_string',
					/* translators: %1$s: invalid source; %2$s: list of allowed sources */
					sprintf(
						__( 'Source %1$s is not one of: %2$s', 'post-runtime-engine' ),
						$source,
						implode( ', ', self::SOURCE_MODES )
					)
				);
			}

			// taxonomy_match cannot be expressed as a bare string — it needs
			// a taxonomy slug. Force the object form.
			if ( $source === 'taxonomy_match' ) {
				return new WP_Error(
					'pre_taxonomy_match_needs_object',
					__( 'taxonomy_match source must be expressed as an object with a taxonomy slug.', 'post-runtime-engine' )
				);
			}

			return true;
		}

		// Object form: { type, ...params }.
		if ( is_array( $source ) ) {
			if ( empty( $source['type'] ) || ! in_array( $source['type'], self::SOURCE_MODES, true ) ) {
				return new WP_Error(
					'pre_invalid_source_type',
					/* translators: %s: list of allowed source types */
					sprintf( __( 'Source object must have a type field set to one of: %s', 'post-runtime-engine' ), implode( ', ', self::SOURCE_MODES ) )
				);
			}

			if ( $source['type'] === 'taxonomy_match' ) {
				if ( empty( $source['taxonomy'] ) || ! is_string( $source['taxonomy'] )
					|| sanitize_key( $source['taxonomy'] ) !== $source['taxonomy'] ) {
					return new WP_Error(
						'pre_invalid_source_taxonomy',
						__( 'taxonomy_match source requires a valid taxonomy slug.', 'post-runtime-engine' )
					);
				}

				if ( isset( $source['limit'] ) && ( ! is_int( $source['limit'] ) || $source['limit'] < 1 || $source['limit'] > self::MAX_ITEMS_PER_GROUPING ) ) {
					return new WP_Error(
						'pre_invalid_source_limit',
						/* translators: %d: max allowed limit */
						sprintf( __( 'taxonomy_match source limit must be between 1 and %d.', 'post-runtime-engine' ), self::MAX_ITEMS_PER_GROUPING )
					);
				}

				if ( isset( $source['exclude_self'] ) && ! is_bool( $source['exclude_self'] ) ) {
					return new WP_Error(
						'pre_invalid_source_exclude_self',
						__( 'taxonomy_match source exclude_self must be true or false.', 'post-runtime-engine' )
					);
				}
			}

			return true;
		}

		return new WP_Error(
			'pre_invalid_source',
			__( 'Source must be a string or an object.', 'post-runtime-engine' )
		);
	}

	/**
	 * Validate a link value. Accepts:
	 *   - Anchor refs starting with #
	 *   - Relative paths starting with /
	 *   - tel: and mailto: URIs
	 *   - http(s):// URLs (validated via esc_url_raw round-trip)
	 *
	 * @param string $link The link.
	 * @return true|WP_Error
	 */
	public function validate_link( $link ) {
		// Anchors.
		if ( strpos( $link, '#' ) === 0 ) {
			$anchor = substr( $link, 1 );
			if ( $anchor === '' || sanitize_html_class( $anchor ) !== $anchor ) {
				return new WP_Error(
					'pre_invalid_anchor',
					/* translators: %s: the anchor */
					sprintf( __( 'Invalid anchor: %s', 'post-runtime-engine' ), $link )
				);
			}
			return true;
		}

		// Relative paths.
		if ( strpos( $link, '/' ) === 0 ) {
			return true;
		}

		// tel: and mailto:.
		if ( stripos( $link, 'tel:' ) === 0 || stripos( $link, 'mailto:' ) === 0 ) {
			return true;
		}

		// http(s) URL — round-trip through esc_url_raw to confirm it's safe.
		$sanitized = esc_url_raw( $link );
		if ( $sanitized !== $link || $sanitized === '' ) {
			return new WP_Error(
				'pre_invalid_url',
				/* translators: %s: the URL */
				sprintf( __( 'Invalid or unsafe URL: %s', 'post-runtime-engine' ), $link )
			);
		}

		return true;
	}
}
