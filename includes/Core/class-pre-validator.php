<?php
/**
 * Strict validator for Promptless CPT Pages data structures.
 *
 * Every write path through PCPTPages_CPT_Registry, PCPTPages_Grouping_Registry, and
 * PCPTPages_Post_Data goes through this validator before persisting. Returns
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
class PCPTPages_Validator {

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
	 *
	 * Modes:
	 *   - manual: items entered per-post via admin meta box or connector
	 *   - child_posts: posts whose post_parent equals the current post
	 *   - taxonomy_match: posts sharing one of the current post's terms
	 *   - meta_match: posts whose configured post-meta value equals the
	 *     current post's value for the same meta key (e.g., "more from
	 *     this agent" when meta_key='_agent_id')
	 *
	 * `taxonomy_match` and `meta_match` MUST be expressed as objects
	 * (string forms are rejected by validate_source_value()) because they
	 * require a parameter — the taxonomy slug or the meta key.
	 */
	const SOURCE_MODES = array(
		'manual',
		'child_posts',
		'taxonomy_match',
		'meta_match',
	);

	/**
	 * Maximum length of a post-meta key referenced by a meta_match source.
	 *
	 * WordPress's wp_postmeta.meta_key is varchar(255) but practical keys are
	 * far shorter. Capping at 64 catches typos and discourages encoding data
	 * into the key name itself, while still admitting common patterns like
	 * `_underscored_private_meta_keys_with_descriptive_names`.
	 */
	const MAX_META_KEY_LENGTH = 64;

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
	// v1.1 post field constants
	//
	// Post fields are the second field type — scalar (non-repeatable) with
	// a closed enum of display types and positions. Full design contract:
	// docs/POST_FIELDS_V1_1_DESIGN.md.
	//
	// All enums below are CLOSED. Extension via filter is deliberately not
	// supported in v1.1; adding a new value to any of these enums requires
	// updating the design contract first.
	// ---------------------------------------------------------------------

	/**
	 * Allowed display types for post fields. 9 types covering the full
	 * card / hero metadata surface across ~15 verticals. See design
	 * contract § 5.3 for the visual mapping of each type.
	 */
	const DISPLAY_TYPES = array(
		'currency',
		'number_with_label',
		'badge',
		'meta_pair',
		'date',
		'text',
		'rating',
		'progress',
		'multi_badge',
	);

	/**
	 * Allowed positions for post fields. Symmetric across single-post hero
	 * and card contexts — same enum drives both. CSS handles the per-
	 * context visual treatment.
	 *
	 * `hidden` is a valid choice meaning "field is defined but not
	 * rendered in this context." Used when a field should appear in the
	 * hero but not on cards (or vice versa).
	 */
	const FIELD_POSITIONS = array(
		'image_overlay',
		'headline',
		'subtitle',
		'meta_strip',
		'footer_meta',
		'hidden',
	);

	/**
	 * Allowed color intents for badge display types.
	 *
	 * Reduced from a 7-value semantic-plus-brand set down to 3 in 2026-05-21
	 * after the Zillow pressure test surfaced that semantic colors (green
	 * success / red danger / etc.) don't align with the site's design system
	 * and add complexity for both AI agents and human authors. The brand
	 * palette is the single source of badge color; SmartColorManager handles
	 * WCAG contrast computation automatically via the --aisb-button-*-text
	 * tokens.
	 *
	 *   primary — site's brand primary color. Uses --aisb-color-primary as
	 *             background and --aisb-button-primary-text (SmartColor-
	 *             computed contrast color) as text. Visual alignment with
	 *             AISB's WooCommerce SALE badge is automatic — both use the
	 *             same token pair. Best for promotional and high-emphasis
	 *             badges (SALE, FEATURED, $1,000 OFF, FOR SALE).
	 *
	 *   secondary — site's brand secondary color. Uses --aisb-color-secondary
	 *               + smart-computed text. Best for paired badges that need
	 *               to contrast against primary while still feeling branded.
	 *
	 *   neutral — semi-transparent dark overlay (rgba 0.85) with white text.
	 *             Independent of brand color. Best for state badges that
	 *             should read clearly regardless of brand choice and on any
	 *             image content underneath (the Zillow "Designer finishes"
	 *             pattern). Also the safe default.
	 *
	 * Old semantic intents (success / warning / danger / info) are removed
	 * from the admin enum and preflight disclosure but their CSS rules
	 * remain in cards.css for backward compatibility. Existing fields
	 * configured with those values keep rendering correctly until migrated.
	 *
	 * Enum is CLOSED. Adding a new intent requires updating cards.css with
	 * matching color rules AND the SmartColor token plumbing.
	 */
	const COLOR_INTENTS = array(
		'primary',
		'secondary',
		'neutral',
	);

	/**
	 * Legacy semantic intents — accepted for backward compatibility with
	 * pre-2026-05-21 field configurations but no longer exposed in the
	 * admin dropdown or preflight enum. New fields should use the closed
	 * COLOR_INTENTS list above.
	 *
	 * @var string[]
	 */
	const COLOR_INTENTS_LEGACY = array(
		'success',
		'warning',
		'danger',
		'info',
	);

	/**
	 * Allowed date format modes for the `date` display type.
	 *
	 * `absolute` — WP's date_i18n() with the field-defined or sitewide format
	 * `relative` — human_time_diff() output ("2 days ago")
	 * `custom` — caller-supplied strftime-style string in date_format_string
	 */
	const DATE_FORMATS = array(
		'absolute',
		'relative',
		'custom',
	);

	/**
	 * Allowed semantic roles for post fields (events vertical, v1.2).
	 *
	 * ADDITIVE attribute — does NOT change the closed DISPLAY_TYPES /
	 * FIELD_POSITIONS / COLOR_INTENTS enums, which remain frozen. A
	 * semantic_role tags a field with the meaning the events query + schema
	 * layers read (e.g. which date is the start, which badge is the status).
	 * Closed, events-scoped enum. Full contract: docs/EVENTS_VERTICAL_DESIGN.md § 5.2.
	 *
	 * Each role pairs with exactly one display_type (see ROLE_DISPLAY_TYPES)
	 * and may be mapped at most once per CPT (uniqueness enforced in
	 * PCPTPages_Post_Field_Registry::define()).
	 */
	const SEMANTIC_ROLES = array(
		'event_start',
		'event_end',
		'event_status',
		'event_location',
		'event_offers',
		'event_attendance_mode',
	);

	/**
	 * Required display_type for each semantic role. A role declared on a
	 * field whose display_type doesn't match is rejected at save time so the
	 * downstream query + schema layers can rely on the pairing.
	 *
	 * @var array<string,string>
	 */
	const ROLE_DISPLAY_TYPES = array(
		'event_start'           => 'date',
		'event_end'             => 'date',
		'event_status'          => 'badge',
		'event_location'        => 'text',
		'event_offers'          => 'currency',
		'event_attendance_mode' => 'badge',
	);

	/**
	 * Generic filter-widget vocabulary (schema-driven filters, v1.2).
	 *
	 * CLOSED, Promptless-shared contract. PRE maps each filterable post
	 * field's display_type to one of these generic widgets in its filter
	 * descriptor (Phase 2); Promptless WP renders purely from this vocabulary
	 * and never learns PRE's display_type names. The `filter_widget` attribute
	 * on a field definition overrides the default mapping with one of these.
	 *
	 * Full contract: ai-section-builder-modern/docs/development/SCHEMA_DRIVEN_FILTERS_DESIGN.md § 4.
	 */
	const FILTER_WIDGETS = array(
		'range',
		'stepper',
		'pill_select',
		'checkbox_group',
		'date_toggle',
		'date_range',
		'text_search',
	);

	/**
	 * Allowed filter widgets per display_type. The FIRST entry is the DEFAULT
	 * widget the descriptor emits when `filter_widget` is null; a non-null
	 * `filter_widget` override must be a member of the field's list here.
	 *
	 * `meta_pair` is intentionally absent — it is display-only and cannot be
	 * marked filterable. Taxonomies (not a display_type) map to checkbox_group
	 * in the descriptor layer (Phase 2), not here.
	 */
	const DISPLAY_TYPE_FILTER_WIDGETS = array(
		'currency'          => array( 'range' ),
		'number_with_label' => array( 'range', 'stepper' ),
		'rating'            => array( 'stepper', 'range' ),
		'progress'          => array( 'range' ),
		'date'              => array( 'date_toggle', 'date_range' ),
		'badge'             => array( 'pill_select', 'checkbox_group' ),
		'multi_badge'       => array( 'checkbox_group' ),
		'text'              => array( 'text_search' ),
	);

	/**
	 * Reserved URL-param suffixes + standalone params owned by the filter
	 * engine's URL-state schema (SCHEMA_DRIVEN_FILTERS_DESIGN.md § 5). A field
	 * that opts into filtering/sorting may not have a key that collides with
	 * these, or its generated query params would be ambiguous. Enforced only
	 * when a field opts in, so existing non-filtered fields are unaffected.
	 */
	const RESERVED_FILTER_SUFFIXES = array( '_min', '_max', '_when', '_after', '_before', '_q' );
	const RESERVED_FILTER_PARAMS   = array( 'sort', 'paged' );

	/**
	 * Soft warning threshold for post fields per CPT. Cards display best
	 * with 8 or fewer; beyond this the admin UI surfaces a warning banner.
	 */
	const SOFT_FIELD_COUNT_WARNING = 8;

	/**
	 * Hard cap on post fields per CPT. Filterable via
	 * `pcptpages_max_post_fields_per_cpt`. Beyond 12 the card layout breaks down
	 * regardless of viewport.
	 */
	const HARD_FIELD_COUNT_LIMIT = 12;

	/**
	 * Maximum length for various post-field text inputs.
	 */
	const MAX_FIELD_KEY_LEN         = 64;
	const MAX_FIELD_LABEL_LEN       = 100;
	const MAX_FIELD_DESCRIPTION_LEN = 500;
	const MAX_FIELD_OPTIONS         = 24;
	const MAX_TEXT_VALUE_LEN        = 500;
	const MAX_MULTI_BADGE_VALUES    = 8;
	const MAX_VALUE_SUFFIX_LEN      = 16;

	/**
	 * Closed enum of supported ISO 4217 currency codes. Closed by design —
	 * a typo in a currency code shouldn't silently produce broken pricing
	 * displays. Filter `pcptpages_supported_currencies` accepts site-specific
	 * additions when needed (e.g., crypto, regional codes).
	 *
	 * Includes the ~30 most commonly-used currencies across the FlowMint
	 * stack's expected client base. Add via filter for niche needs.
	 */
	const SUPPORTED_CURRENCIES = array(
		'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CNY', 'CHF', 'SEK', 'NOK',
		'DKK', 'NZD', 'SGD', 'HKD', 'KRW', 'INR', 'BRL', 'MXN', 'ZAR', 'AED',
		'SAR', 'TRY', 'PLN', 'CZK', 'HUF', 'RUB', 'ILS', 'THB', 'PHP', 'IDR',
	);

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
				'pcptpages_invalid_cpt',
				__( 'CPT definition must be an array.', 'promptless-cpt-pages' )
			);
		}

		// Slug — required, lowercase, sanitized, not reserved.
		if ( empty( $definition['slug'] ) || ! is_string( $definition['slug'] ) ) {
			return new WP_Error(
				'pcptpages_missing_slug',
				__( 'CPT definition must include a non-empty slug string.', 'promptless-cpt-pages' )
			);
		}

		$slug = sanitize_key( $definition['slug'] );
		if ( $slug !== $definition['slug'] ) {
			return new WP_Error(
				'pcptpages_invalid_slug',
				/* translators: %s: the rejected slug */
				sprintf( __( 'CPT slug %s contains invalid characters. Use lowercase letters, numbers, and underscores only.', 'promptless-cpt-pages' ), $definition['slug'] )
			);
		}

		// WordPress imposes a 20-character limit on post type slugs.
		if ( strlen( $slug ) > 20 ) {
			return new WP_Error(
				'pcptpages_slug_too_long',
				__( 'CPT slug must be 20 characters or fewer (WordPress limitation).', 'promptless-cpt-pages' )
			);
		}

		if ( in_array( $slug, self::RESERVED_CPT_SLUGS, true ) ) {
			return new WP_Error(
				'pcptpages_reserved_slug',
				/* translators: %s: the reserved slug */
				sprintf( __( 'CPT slug %s is reserved by WordPress core or another plugin.', 'promptless-cpt-pages' ), $slug )
			);
		}

		// Labels — required.
		if ( empty( $definition['label_singular'] ) || ! is_string( $definition['label_singular'] ) ) {
			return new WP_Error(
				'pcptpages_missing_label_singular',
				__( 'CPT definition must include a non-empty label_singular string.', 'promptless-cpt-pages' )
			);
		}

		if ( empty( $definition['label_plural'] ) || ! is_string( $definition['label_plural'] ) ) {
			return new WP_Error(
				'pcptpages_missing_label_plural',
				__( 'CPT definition must include a non-empty label_plural string.', 'promptless-cpt-pages' )
			);
		}

		if ( strlen( $definition['label_singular'] ) > self::MAX_LABEL_LEN
			|| strlen( $definition['label_plural'] ) > self::MAX_LABEL_LEN ) {
			return new WP_Error(
				'pcptpages_label_too_long',
				/* translators: %d: the maximum allowed label length */
				sprintf( __( 'CPT labels must be %d characters or fewer.', 'promptless-cpt-pages' ), self::MAX_LABEL_LEN )
			);
		}

		// supports[] — optional; if present, must be an array of known strings.
		if ( isset( $definition['supports'] ) ) {
			if ( ! is_array( $definition['supports'] ) ) {
				return new WP_Error(
					'pcptpages_invalid_supports',
					__( 'CPT supports must be an array.', 'promptless-cpt-pages' )
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
						'pcptpages_invalid_support',
						/* translators: %s: the invalid support string */
						sprintf( __( 'Unknown CPT support feature: %s', 'promptless-cpt-pages' ), is_string( $support ) ? $support : 'non-string' )
					);
				}
			}
		}

		// taxonomies[] — optional; if present, must be an array of valid keys.
		if ( isset( $definition['taxonomies'] ) ) {
			if ( ! is_array( $definition['taxonomies'] ) ) {
				return new WP_Error(
					'pcptpages_invalid_taxonomies',
					__( 'CPT taxonomies must be an array of taxonomy slugs.', 'promptless-cpt-pages' )
				);
			}

			foreach ( $definition['taxonomies'] as $taxonomy ) {
				if ( ! is_string( $taxonomy ) || sanitize_key( $taxonomy ) !== $taxonomy ) {
					return new WP_Error(
						'pcptpages_invalid_taxonomy',
						/* translators: %s: the invalid taxonomy slug */
						sprintf( __( 'Invalid taxonomy slug: %s', 'promptless-cpt-pages' ), is_string( $taxonomy ) ? $taxonomy : 'non-string' )
					);
				}
			}
		}

		// Booleans — coerce silently if present, but reject non-bool-coercible inputs.
		foreach ( array( 'public', 'has_archive', 'hierarchical', 'show_in_rest', 'show_in_menu' ) as $bool_key ) {
			if ( isset( $definition[ $bool_key ] ) && ! is_bool( $definition[ $bool_key ] ) && ! is_int( $definition[ $bool_key ] ) ) {
				return new WP_Error(
					'pcptpages_invalid_boolean',
					/* translators: %s: the boolean field key */
					sprintf( __( 'CPT field %s must be true or false.', 'promptless-cpt-pages' ), $bool_key )
				);
			}
		}

		// rest_base — optional; sanitize-key match.
		if ( isset( $definition['rest_base'] ) ) {
			if ( ! is_string( $definition['rest_base'] ) || sanitize_key( $definition['rest_base'] ) !== $definition['rest_base'] ) {
				return new WP_Error(
					'pcptpages_invalid_rest_base',
					__( 'CPT rest_base must be a lowercase slug.', 'promptless-cpt-pages' )
				);
			}
		}

		// Hero layout — optional; enum (stacked|split). Default 'stacked' is
		// applied by PCPTPages_CPT_Registry::merge_defaults; the validator only
		// rejects malformed input. Existing CPTs registered before this
		// field existed pass through untouched (the field is absent =>
		// merge_defaults supplies the default).
		if ( isset( $definition['hero_layout'] ) ) {
			$valid_layouts = array( 'stacked', 'split' );
			if ( ! in_array( $definition['hero_layout'], $valid_layouts, true ) ) {
				return new WP_Error(
					'pcptpages_invalid_hero_layout',
					sprintf(
						/* translators: %1$s: the invalid layout; %2$s: list of allowed layouts */
						__( 'CPT hero_layout %1$s is not one of: %2$s', 'promptless-cpt-pages' ),
						is_string( $definition['hero_layout'] ) ? $definition['hero_layout'] : 'non-string',
						implode( ', ', $valid_layouts )
					)
				);
			}
		}

		// Hero image position — optional; enum (left|right). Only meaningful
		// when hero_layout is 'split'; ignored when 'stacked'. Validator
		// rejects malformed values regardless of layout to keep stored data
		// consistent and to surface typos at write time rather than render.
		if ( isset( $definition['hero_image_position'] ) ) {
			$valid_positions = array( 'left', 'right' );
			if ( ! in_array( $definition['hero_image_position'], $valid_positions, true ) ) {
				return new WP_Error(
					'pcptpages_invalid_hero_image_position',
					sprintf(
						/* translators: %1$s: the invalid position; %2$s: list of allowed positions */
						__( 'CPT hero_image_position %1$s is not one of: %2$s', 'promptless-cpt-pages' ),
						is_string( $definition['hero_image_position'] ) ? $definition['hero_image_position'] : 'non-string',
						implode( ', ', $valid_positions )
					)
				);
			}
		}

		// Hero image aspect — optional; enum (square|landscape|wide). Only
		// meaningful when hero_layout is 'split' (stacked always uses a
		// 16:9 banner regardless). Pick the aspect that matches the
		// content's natural photo shape — square for headshots, landscape
		// for property photos / product shots, wide for cinematic banners.
		if ( isset( $definition['hero_image_aspect'] ) ) {
			$valid_aspects = array( 'square', 'landscape', 'wide' );
			if ( ! in_array( $definition['hero_image_aspect'], $valid_aspects, true ) ) {
				return new WP_Error(
					'pcptpages_invalid_hero_image_aspect',
					sprintf(
						/* translators: %1$s: the invalid aspect; %2$s: list of allowed aspects */
						__( 'CPT hero_image_aspect %1$s is not one of: %2$s', 'promptless-cpt-pages' ),
						is_string( $definition['hero_image_aspect'] ) ? $definition['hero_image_aspect'] : 'non-string',
						implode( ', ', $valid_aspects )
					)
				);
			}
		}

		// Default icon — optional; must be a known icon ID from
		// PCPTPages_Icon_Library. Used by the renderer as a fallback when an
		// item resolves to no media (icon-only variants strip the image
		// and fall through to this; image-friendly variants use it as a
		// last-resort cue when neither image nor per-item icon is set).
		// Empty string is allowed and means "no fallback" — items with
		// no media simply render iconless.
		if ( isset( $definition['default_icon'] ) && $definition['default_icon'] !== '' ) {
			if ( ! is_string( $definition['default_icon'] ) ) {
				return new WP_Error(
					'pcptpages_invalid_default_icon',
					__( 'CPT default_icon must be a string icon ID.', 'promptless-cpt-pages' )
				);
			}
			if ( ! PCPTPages_Icon_Library::is_valid_id( $definition['default_icon'] ) ) {
				return new WP_Error(
					'pcptpages_invalid_default_icon',
					sprintf(
						/* translators: %s: the unknown icon ID */
						__( 'CPT default_icon "%s" is not a valid icon identifier. Accepts either a legacy curated ID (e.g. "home", "user", "shield" — call postruntime_list_icons to discover all 53) OR an Iconify code in `collection:name` form (e.g. "mdi:home", "logos:wordpress", "material-symbols:business" — browse 200,000+ icons at icon-sets.iconify.design).', 'promptless-cpt-pages' ),
						$definition['default_icon']
					)
				);
			}
		}

		// archive_show_post_date / archive_show_post_author — control whether
		// the theme renders the post's create-date and author byline on
		// archive cards. Default true (backward compatible). Booleans only.
		foreach ( array( 'archive_show_post_date', 'archive_show_post_author' ) as $bool_key ) {
			if ( isset( $definition[ $bool_key ] ) && ! is_bool( $definition[ $bool_key ] ) ) {
				return new WP_Error(
					'pcptpages_invalid_archive_meta_flag',
					sprintf(
						/* translators: %s: the offending key */
						__( 'CPT %s must be a boolean.', 'promptless-cpt-pages' ),
						$bool_key
					)
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
				'pcptpages_invalid_grouping',
				__( 'Grouping definition must be an array.', 'promptless-cpt-pages' )
			);
		}

		// Key — required, sanitize_key match.
		if ( empty( $definition['key'] ) || ! is_string( $definition['key'] ) ) {
			return new WP_Error(
				'pcptpages_missing_grouping_key',
				__( 'Grouping definition must include a non-empty key.', 'promptless-cpt-pages' )
			);
		}

		if ( sanitize_key( $definition['key'] ) !== $definition['key'] ) {
			return new WP_Error(
				'pcptpages_invalid_grouping_key',
				/* translators: %s: the rejected key */
				sprintf( __( 'Grouping key %s contains invalid characters. Use lowercase letters, numbers, and underscores only.', 'promptless-cpt-pages' ), $definition['key'] )
			);
		}

		// Label — required.
		if ( empty( $definition['label'] ) || ! is_string( $definition['label'] ) ) {
			return new WP_Error(
				'pcptpages_missing_grouping_label',
				__( 'Grouping definition must include a label.', 'promptless-cpt-pages' )
			);
		}

		if ( strlen( $definition['label'] ) > self::MAX_LABEL_LEN ) {
			return new WP_Error(
				'pcptpages_grouping_label_too_long',
				/* translators: %d: maximum label length */
				sprintf( __( 'Grouping label must be %d characters or fewer.', 'promptless-cpt-pages' ), self::MAX_LABEL_LEN )
			);
		}

		// Default variant — required.
		if ( empty( $definition['default_variant'] )
			|| ! in_array( $definition['default_variant'], self::VARIANTS, true ) ) {
			return new WP_Error(
				'pcptpages_invalid_default_variant',
				sprintf(
					/* translators: %1$s: the invalid variant; %2$s: list of allowed variants */
					__( 'Grouping default_variant %1$s is not one of: %2$s', 'promptless-cpt-pages' ),
					isset( $definition['default_variant'] ) ? (string) $definition['default_variant'] : 'null',
					implode( ', ', self::VARIANTS )
				)
			);
		}

		// Default position — required.
		if ( empty( $definition['default_position'] )
			|| ! in_array( $definition['default_position'], self::POSITIONS, true ) ) {
			return new WP_Error(
				'pcptpages_invalid_default_position',
				sprintf(
					/* translators: %1$s: the invalid position; %2$s: list of allowed positions */
					__( 'Grouping default_position %1$s is not one of: %2$s', 'promptless-cpt-pages' ),
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
					'pcptpages_invalid_max_items',
					__( 'Grouping max_items must be a positive integer.', 'promptless-cpt-pages' )
				);
			}

			if ( $definition['max_items'] > self::MAX_ITEMS_PER_GROUPING ) {
				return new WP_Error(
					'pcptpages_max_items_too_large',
					/* translators: %d: hard upper bound on items per grouping */
					sprintf( __( 'Grouping max_items cannot exceed %d.', 'promptless-cpt-pages' ), self::MAX_ITEMS_PER_GROUPING )
				);
			}
		}

		// Required-field flags — booleans only.
		foreach ( array( 'heading_required', 'supporting_text_required', 'link_required', 'icon_or_image_required' ) as $flag ) {
			if ( isset( $definition[ $flag ] ) && ! is_bool( $definition[ $flag ] ) ) {
				return new WP_Error(
					'pcptpages_invalid_grouping_flag',
					/* translators: %s: the flag key */
					sprintf( __( 'Grouping field %s must be true or false.', 'promptless-cpt-pages' ), $flag )
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
				'pcptpages_featured_card_max_items',
				__( 'featured-card variant requires max_items=1 (or unset).', 'promptless-cpt-pages' )
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
				'pcptpages_invalid_post_groupings',
				__( 'Post groupings must be an array.', 'promptless-cpt-pages' )
			);
		}

		if ( count( $groupings ) > self::MAX_GROUPINGS_PER_POST ) {
			return new WP_Error(
				'pcptpages_too_many_groupings',
				/* translators: %d: max groupings per post */
				sprintf( __( 'Post cannot have more than %d groupings.', 'promptless-cpt-pages' ), self::MAX_GROUPINGS_PER_POST )
			);
		}

		$seen_keys = array();

		foreach ( $groupings as $index => $grouping ) {
			if ( ! is_array( $grouping ) ) {
				return new WP_Error(
					'pcptpages_invalid_grouping_entry',
					/* translators: %d: the position in the array */
					sprintf( __( 'Grouping entry at index %d is not an array.', 'promptless-cpt-pages' ), $index )
				);
			}

			// grouping_key — required, must reference an existing definition.
			if ( empty( $grouping['grouping_key'] ) || ! is_string( $grouping['grouping_key'] ) ) {
				return new WP_Error(
					'pcptpages_missing_post_grouping_key',
					/* translators: %d: array index */
					sprintf( __( 'Grouping entry at index %d is missing grouping_key.', 'promptless-cpt-pages' ), $index )
				);
			}

			$grouping_key = $grouping['grouping_key'];

			if ( ! isset( $cpt_groupings[ $grouping_key ] ) ) {
				return new WP_Error(
					'pcptpages_unknown_grouping_key',
					/* translators: %1$s: grouping key; %2$s: CPT slug */
					sprintf( __( 'Grouping %1$s is not defined for CPT %2$s.', 'promptless-cpt-pages' ), $grouping_key, $cpt_slug )
				);
			}

			// A grouping_key may appear at most once per post. (Avoids
			// confusion about which instance "wins" at render time.)
			if ( in_array( $grouping_key, $seen_keys, true ) ) {
				return new WP_Error(
					'pcptpages_duplicate_post_grouping',
					/* translators: %s: grouping key */
					sprintf( __( 'Grouping %s appears more than once on this post.', 'promptless-cpt-pages' ), $grouping_key )
				);
			}
			$seen_keys[] = $grouping_key;

			$definition = $cpt_groupings[ $grouping_key ];

			// Position — must be valid.
			$position = isset( $grouping['position'] ) ? $grouping['position'] : $definition['default_position'];
			if ( ! in_array( $position, self::POSITIONS, true ) ) {
				return new WP_Error(
					'pcptpages_invalid_post_position',
					/* translators: %1$s: invalid position; %2$s: grouping key */
					sprintf( __( 'Position %1$s is not valid for grouping %2$s.', 'promptless-cpt-pages' ), (string) $position, $grouping_key )
				);
			}

			// variant_override — optional; if set, must be valid.
			if ( isset( $grouping['variant_override'] ) && $grouping['variant_override'] !== null ) {
				if ( ! in_array( $grouping['variant_override'], self::VARIANTS, true ) ) {
					return new WP_Error(
						'pcptpages_invalid_variant_override',
						/* translators: %1$s: invalid variant; %2$s: grouping key */
						sprintf( __( 'variant_override %1$s is not valid for grouping %2$s.', 'promptless-cpt-pages' ), (string) $grouping['variant_override'], $grouping_key )
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
						'pcptpages_auto_source_has_items',
						/* translators: %s: grouping key */
						sprintf( __( 'Grouping %s has source set to an auto mode but also has stored items. Auto sources must use an empty items array.', 'promptless-cpt-pages' ), $grouping_key )
					);
				}
			} else {
				// Manual source — items array is required and validated.
				$items = isset( $grouping['items'] ) ? $grouping['items'] : array();

				if ( ! is_array( $items ) ) {
					return new WP_Error(
						'pcptpages_invalid_items',
						/* translators: %s: grouping key */
						sprintf( __( 'Grouping %s has a non-array items field.', 'promptless-cpt-pages' ), $grouping_key )
					);
				}

				if ( count( $items ) > self::MAX_ITEMS_PER_GROUPING ) {
					return new WP_Error(
						'pcptpages_too_many_items',
						/* translators: %1$s: grouping key; %2$d: max items */
						sprintf( __( 'Grouping %1$s has more than %2$d items.', 'promptless-cpt-pages' ), $grouping_key, self::MAX_ITEMS_PER_GROUPING )
					);
				}

				// Per-grouping max_items override.
				if ( isset( $definition['max_items'] ) && count( $items ) > $definition['max_items'] ) {
					return new WP_Error(
						'pcptpages_grouping_max_items_exceeded',
						/* translators: %1$s: grouping key; %2$d: max items for this grouping */
						sprintf( __( 'Grouping %1$s exceeds its max_items limit of %2$d.', 'promptless-cpt-pages' ), $grouping_key, $definition['max_items'] )
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
				'pcptpages_invalid_item',
				__( 'Grouping item must be an array.', 'promptless-cpt-pages' )
			);
		}

		// Mutual exclusion between image_id and icon_id.
		$has_image = ! empty( $item['image_id'] );
		$has_icon  = ! empty( $item['icon_id'] );

		if ( $has_image && $has_icon ) {
			return new WP_Error(
				'pcptpages_image_icon_conflict',
				__( 'Grouping item cannot have both image_id and icon_id set; pick one.', 'promptless-cpt-pages' )
			);
		}

		// image_id — must be a positive integer that resolves to an attachment.
		if ( $has_image ) {
			if ( ! is_int( $item['image_id'] ) || $item['image_id'] < 1 ) {
				return new WP_Error(
					'pcptpages_invalid_image_id',
					__( 'Grouping item image_id must be a positive integer.', 'promptless-cpt-pages' )
				);
			}

			// Confirm the attachment exists.
			$attachment = get_post( $item['image_id'] );
			if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
				return new WP_Error(
					'pcptpages_image_not_found',
					/* translators: %d: attachment ID */
					sprintf( __( 'image_id %d does not reference a valid attachment.', 'promptless-cpt-pages' ), $item['image_id'] )
				);
			}
		}

		// icon_id — must be a string AND either a legacy curated ID or a
		// well-formed Iconify code (`collection:name`). Iconify codes are
		// validated by shape only (regex), not against the live Iconify
		// registry — the registry lives at api.iconify.design and is fetched
		// at render time with graceful fallback for missing icons, so typos
		// produce a placeholder rather than a save-time crash.
		if ( $has_icon ) {
			if ( ! is_string( $item['icon_id'] ) ) {
				return new WP_Error(
					'pcptpages_invalid_icon_id',
					__( 'Grouping item icon_id must be a string.', 'promptless-cpt-pages' )
				);
			}

			// PCPTPages_Icon_Library is autoloaded; defer to it for the dual-format
			// recognition logic.
			if ( class_exists( 'PCPTPages_Icon_Library' ) && ! PCPTPages_Icon_Library::is_valid_id( $item['icon_id'] ) ) {
				return new WP_Error(
					'pcptpages_unknown_icon',
					/* translators: %s: the unknown icon ID */
					sprintf( __( 'Grouping item icon_id "%s" is not a valid icon identifier. Accepts either a legacy curated ID (e.g. "home", "user", "calendar" — 53 built-in icons; call postruntime_list_icons to see them all) OR an Iconify code in `collection:name` form (e.g. "mdi:home", "logos:wordpress" — 200,000+ icons at icon-sets.iconify.design).', 'promptless-cpt-pages' ), $item['icon_id'] )
				);
			}
		}

		// icon_or_image_required — if set on definition, item must have one.
		if ( ! empty( $definition['icon_or_image_required'] ) && ! $has_image && ! $has_icon ) {
			return new WP_Error(
				'pcptpages_missing_image_or_icon',
				/* translators: %s: grouping key (filled in by caller via add_data) */
				__( 'Grouping requires an image or icon on every item.', 'promptless-cpt-pages' )
			);
		}

		// Heading — required by default; can be made optional per definition.
		$heading_required = ! isset( $definition['heading_required'] ) || $definition['heading_required'];
		$heading          = isset( $item['heading'] ) ? $item['heading'] : '';

		if ( ! is_string( $heading ) ) {
			return new WP_Error(
				'pcptpages_invalid_heading',
				__( 'Grouping item heading must be a string.', 'promptless-cpt-pages' )
			);
		}

		if ( $heading_required && trim( $heading ) === '' ) {
			return new WP_Error(
				'pcptpages_missing_heading',
				__( 'Grouping item heading is required.', 'promptless-cpt-pages' )
			);
		}

		if ( strlen( $heading ) > self::MAX_HEADING_LEN ) {
			return new WP_Error(
				'pcptpages_heading_too_long',
				/* translators: %d: max heading length */
				sprintf( __( 'Grouping item heading exceeds the %d character limit.', 'promptless-cpt-pages' ), self::MAX_HEADING_LEN )
			);
		}

		// Supporting text — optional unless flagged.
		$supporting_text = isset( $item['supporting_text'] ) ? $item['supporting_text'] : null;
		if ( $supporting_text !== null ) {
			if ( ! is_string( $supporting_text ) ) {
				return new WP_Error(
					'pcptpages_invalid_supporting_text',
					__( 'Grouping item supporting_text must be a string or null.', 'promptless-cpt-pages' )
				);
			}

			if ( strlen( $supporting_text ) > self::MAX_SUPPORTING_TEXT_LEN ) {
				return new WP_Error(
					'pcptpages_supporting_text_too_long',
					/* translators: %d: max length */
					sprintf( __( 'Grouping item supporting_text exceeds the %d character limit.', 'promptless-cpt-pages' ), self::MAX_SUPPORTING_TEXT_LEN )
				);
			}
		}

		if ( ! empty( $definition['supporting_text_required'] )
			&& ( $supporting_text === null || trim( $supporting_text ) === '' ) ) {
			return new WP_Error(
				'pcptpages_missing_supporting_text',
				__( 'Grouping item supporting_text is required.', 'promptless-cpt-pages' )
			);
		}

		// Link — optional unless flagged.
		$link = isset( $item['link'] ) ? $item['link'] : null;
		if ( $link !== null && $link !== '' ) {
			if ( ! is_string( $link ) ) {
				return new WP_Error(
					'pcptpages_invalid_link',
					__( 'Grouping item link must be a string, null, or empty.', 'promptless-cpt-pages' )
				);
			}

			if ( strlen( $link ) > self::MAX_LINK_LEN ) {
				return new WP_Error(
					'pcptpages_link_too_long',
					/* translators: %d: max link length */
					sprintf( __( 'Grouping item link exceeds the %d character limit.', 'promptless-cpt-pages' ), self::MAX_LINK_LEN )
				);
			}

			$link_check = $this->validate_link( $link );
			if ( is_wp_error( $link_check ) ) {
				return $link_check;
			}
		}

		if ( ! empty( $definition['link_required'] ) && ( $link === null || trim( (string) $link ) === '' ) ) {
			return new WP_Error(
				'pcptpages_missing_link',
				__( 'Grouping item link is required.', 'promptless-cpt-pages' )
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
					'pcptpages_invalid_link_post_id',
					__( 'Grouping item link_post_id must be a positive integer or null.', 'promptless-cpt-pages' )
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
					'pcptpages_invalid_source_string',
					sprintf(
						/* translators: %1$s: invalid source; %2$s: list of allowed sources */
						__( 'Source %1$s is not one of: %2$s', 'promptless-cpt-pages' ),
						$source,
						implode( ', ', self::SOURCE_MODES )
					)
				);
			}

			// taxonomy_match cannot be expressed as a bare string — it needs
			// a taxonomy slug. Force the object form.
			if ( $source === 'taxonomy_match' ) {
				return new WP_Error(
					'pcptpages_taxonomy_match_needs_object',
					__( 'taxonomy_match source must be expressed as an object with a taxonomy slug.', 'promptless-cpt-pages' )
				);
			}

			// meta_match also cannot be expressed as a bare string — it needs
			// a meta key. Force the object form.
			if ( $source === 'meta_match' ) {
				return new WP_Error(
					'pcptpages_meta_match_needs_object',
					__( 'meta_match source must be expressed as an object with a meta_key.', 'promptless-cpt-pages' )
				);
			}

			return true;
		}

		// Object form: { type, ...params }.
		if ( is_array( $source ) ) {
			if ( empty( $source['type'] ) || ! in_array( $source['type'], self::SOURCE_MODES, true ) ) {
				return new WP_Error(
					'pcptpages_invalid_source_type',
					/* translators: %s: list of allowed source types */
					sprintf( __( 'Source object must have a type field set to one of: %s', 'promptless-cpt-pages' ), implode( ', ', self::SOURCE_MODES ) )
				);
			}

			if ( $source['type'] === 'taxonomy_match' ) {
				if ( empty( $source['taxonomy'] ) || ! is_string( $source['taxonomy'] )
					|| sanitize_key( $source['taxonomy'] ) !== $source['taxonomy'] ) {
					return new WP_Error(
						'pcptpages_invalid_source_taxonomy',
						__( 'taxonomy_match source requires a valid taxonomy slug.', 'promptless-cpt-pages' )
					);
				}

				if ( isset( $source['limit'] ) && ( ! is_int( $source['limit'] ) || $source['limit'] < 1 || $source['limit'] > self::MAX_ITEMS_PER_GROUPING ) ) {
					return new WP_Error(
						'pcptpages_invalid_source_limit',
						/* translators: %d: max allowed limit */
						sprintf( __( 'taxonomy_match source limit must be between 1 and %d.', 'promptless-cpt-pages' ), self::MAX_ITEMS_PER_GROUPING )
					);
				}

				if ( isset( $source['exclude_self'] ) && ! is_bool( $source['exclude_self'] ) ) {
					return new WP_Error(
						'pcptpages_invalid_source_exclude_self',
						__( 'taxonomy_match source exclude_self must be true or false.', 'promptless-cpt-pages' )
					);
				}
			}

			if ( $source['type'] === 'meta_match' ) {
				// meta_key required: must be a string, in canonical sanitize_key
				// form, and within the practical length cap. Strict equality with
				// sanitize_key() rejects silent transformation (mirrors the
				// taxonomy slug check and the ReusableElementsService key check).
				if ( empty( $source['meta_key'] ) || ! is_string( $source['meta_key'] ) ) {
					return new WP_Error(
						'pcptpages_invalid_source_meta_key',
						__( 'meta_match source requires a meta_key (non-empty string).', 'promptless-cpt-pages' )
					);
				}

				$meta_key_raw = $source['meta_key'];
				// Allow a single leading underscore (the WordPress convention
				// for "private" meta) but otherwise enforce sanitize_key form.
				$meta_key_normalized = preg_replace( '/^_+/', '', $meta_key_raw );
				if ( $meta_key_normalized === '' || sanitize_key( $meta_key_normalized ) !== $meta_key_normalized ) {
					return new WP_Error(
						'pcptpages_invalid_source_meta_key',
						__( 'meta_match meta_key must be a valid post-meta key (lowercase alphanumeric + underscores, optionally prefixed with _).', 'promptless-cpt-pages' )
					);
				}

				if ( strlen( $meta_key_raw ) > self::MAX_META_KEY_LENGTH ) {
					return new WP_Error(
						'pcptpages_invalid_source_meta_key_length',
						/* translators: %d: max allowed meta key length */
						sprintf( __( 'meta_match meta_key must be %d characters or fewer.', 'promptless-cpt-pages' ), self::MAX_META_KEY_LENGTH )
					);
				}

				if ( isset( $source['limit'] ) && ( ! is_int( $source['limit'] ) || $source['limit'] < 1 || $source['limit'] > self::MAX_ITEMS_PER_GROUPING ) ) {
					return new WP_Error(
						'pcptpages_invalid_source_limit',
						/* translators: %d: max allowed limit */
						sprintf( __( 'meta_match source limit must be between 1 and %d.', 'promptless-cpt-pages' ), self::MAX_ITEMS_PER_GROUPING )
					);
				}

				if ( isset( $source['exclude_self'] ) && ! is_bool( $source['exclude_self'] ) ) {
					return new WP_Error(
						'pcptpages_invalid_source_exclude_self',
						__( 'meta_match source exclude_self must be true or false.', 'promptless-cpt-pages' )
					);
				}
			}

			return true;
		}

		return new WP_Error(
			'pcptpages_invalid_source',
			__( 'Source must be a string or an object.', 'promptless-cpt-pages' )
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
					'pcptpages_invalid_anchor',
					/* translators: %s: the anchor */
					sprintf( __( 'Invalid anchor: %s', 'promptless-cpt-pages' ), $link )
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
				'pcptpages_invalid_url',
				/* translators: %s: the URL */
				sprintf( __( 'Invalid or unsafe URL: %s', 'promptless-cpt-pages' ), $link )
			);
		}

		return true;
	}

	// ---------------------------------------------------------------------
	// v1.1 post field validation
	//
	// Three entry points:
	//   validate_post_field_definition()  — shape of the registered field
	//   validate_post_field_value()       — value being stored for a post
	//   validate_post_field_visibility()  — per-post visibility overrides
	//
	// All three are strict by design. Malformed input is rejected at save
	// time rather than glossed over at render time. Same discipline as
	// the existing v1.0 validation surface.
	// ---------------------------------------------------------------------

	/**
	 * Validate a post field definition (the shape stored in
	 * `pcptpages_post_fields_{cpt_slug}`).
	 *
	 * Required: key, label, display_type, card_position, single_position.
	 * Optional: description, icon, color_intent, options, required,
	 *           date_format, date_format_string, currency_code, max.
	 *
	 * @param mixed  $definition The definition to validate.
	 * @param string $cpt_slug   CPT slug the field belongs to. Used for
	 *                            context-aware error messages; not a
	 *                            registration check (the registry does that).
	 * @return true|WP_Error
	 */
	public function validate_post_field_definition( $definition, $cpt_slug = '' ) {
		if ( ! is_array( $definition ) ) {
			return new WP_Error(
				'pcptpages_invalid_post_field',
				__( 'Post field definition must be an array.', 'promptless-cpt-pages' )
			);
		}

		// key — required, sanitize_key match, length cap.
		if ( empty( $definition['key'] ) || ! is_string( $definition['key'] ) ) {
			return new WP_Error(
				'pcptpages_missing_field_key',
				__( 'Post field definition must include a non-empty key string.', 'promptless-cpt-pages' )
			);
		}

		if ( sanitize_key( $definition['key'] ) !== $definition['key'] ) {
			return new WP_Error(
				'pcptpages_invalid_field_key',
				sprintf(
					/* translators: %s: the rejected key */
					__( 'Post field key %s contains invalid characters. Use lowercase letters, numbers, and underscores only.', 'promptless-cpt-pages' ),
					$definition['key']
				)
			);
		}

		if ( strlen( $definition['key'] ) > self::MAX_FIELD_KEY_LEN ) {
			return new WP_Error(
				'pcptpages_field_key_too_long',
				sprintf(
					/* translators: %d: max length */
					__( 'Post field key must be %d characters or fewer.', 'promptless-cpt-pages' ),
					self::MAX_FIELD_KEY_LEN
				)
			);
		}

		// label — required, non-empty string, length cap.
		if ( empty( $definition['label'] ) || ! is_string( $definition['label'] ) ) {
			return new WP_Error(
				'pcptpages_missing_field_label',
				__( 'Post field definition must include a non-empty label string.', 'promptless-cpt-pages' )
			);
		}

		if ( strlen( $definition['label'] ) > self::MAX_FIELD_LABEL_LEN ) {
			return new WP_Error(
				'pcptpages_field_label_too_long',
				sprintf(
					/* translators: %d: max length */
					__( 'Post field label must be %d characters or fewer.', 'promptless-cpt-pages' ),
					self::MAX_FIELD_LABEL_LEN
				)
			);
		}

		// display_type — required, must be in DISPLAY_TYPES.
		if ( empty( $definition['display_type'] ) || ! in_array( $definition['display_type'], self::DISPLAY_TYPES, true ) ) {
			return new WP_Error(
				'pcptpages_invalid_display_type',
				sprintf(
					/* translators: %1$s: invalid display type, %2$s: list of allowed types */
					__( 'Post field display_type %1$s is not one of: %2$s', 'promptless-cpt-pages' ),
					is_string( $definition['display_type'] ?? null ) ? $definition['display_type'] : 'missing-or-non-string',
					implode( ', ', self::DISPLAY_TYPES )
				)
			);
		}

		// card_position — required, must be in FIELD_POSITIONS.
		if ( empty( $definition['card_position'] ) || ! in_array( $definition['card_position'], self::FIELD_POSITIONS, true ) ) {
			return new WP_Error(
				'pcptpages_invalid_card_position',
				sprintf(
					/* translators: %1$s: invalid position, %2$s: list of allowed positions */
					__( 'Post field card_position %1$s is not one of: %2$s', 'promptless-cpt-pages' ),
					is_string( $definition['card_position'] ?? null ) ? $definition['card_position'] : 'missing-or-non-string',
					implode( ', ', self::FIELD_POSITIONS )
				)
			);
		}

		// single_position — required, must be in FIELD_POSITIONS.
		if ( empty( $definition['single_position'] ) || ! in_array( $definition['single_position'], self::FIELD_POSITIONS, true ) ) {
			return new WP_Error(
				'pcptpages_invalid_single_position',
				sprintf(
					/* translators: %1$s: invalid position, %2$s: list of allowed positions */
					__( 'Post field single_position %1$s is not one of: %2$s', 'promptless-cpt-pages' ),
					is_string( $definition['single_position'] ?? null ) ? $definition['single_position'] : 'missing-or-non-string',
					implode( ', ', self::FIELD_POSITIONS )
				)
			);
		}

		// Both positions hidden = field never renders. Allowed (drafting
		// a field before deciding where to show it), but the renderer
		// will skip it; surface a hint in the action context rather than
		// here.

		// description — optional string with a length cap.
		if ( isset( $definition['description'] ) ) {
			if ( ! is_string( $definition['description'] ) ) {
				return new WP_Error(
					'pcptpages_invalid_field_description',
					__( 'Post field description must be a string.', 'promptless-cpt-pages' )
				);
			}
			if ( strlen( $definition['description'] ) > self::MAX_FIELD_DESCRIPTION_LEN ) {
				return new WP_Error(
					'pcptpages_field_description_too_long',
					sprintf(
						/* translators: %d: max length */
						__( 'Post field description must be %d characters or fewer.', 'promptless-cpt-pages' ),
						self::MAX_FIELD_DESCRIPTION_LEN
					)
				);
			}
		}

		// icon — optional string (validated by PCPTPages_Icon_Library at render
		// time). Here we only check the type to catch obvious typos.
		if ( isset( $definition['icon'] ) && ! is_string( $definition['icon'] ) ) {
			return new WP_Error(
				'pcptpages_invalid_field_icon',
				__( 'Post field icon must be a string (icon slug from PCPTPages_Icon_Library) or empty.', 'promptless-cpt-pages' )
			);
		}

		// color_intent — optional, must be in COLOR_INTENTS or the legacy
		// set (accepted for backward compatibility with pre-2026-05-21
		// fields). Legacy intents render via cards.css fallback rules but
		// new fields should use the simplified set.
		if ( isset( $definition['color_intent'] ) ) {
			$valid_intents = array_merge( self::COLOR_INTENTS, self::COLOR_INTENTS_LEGACY );
			if ( ! in_array( $definition['color_intent'], $valid_intents, true ) ) {
				return new WP_Error(
					'pcptpages_invalid_color_intent',
					sprintf(
						/* translators: %1$s: invalid intent, %2$s: list of allowed intents */
						__( 'Post field color_intent %1$s is not one of: %2$s', 'promptless-cpt-pages' ),
						is_string( $definition['color_intent'] ) ? $definition['color_intent'] : 'non-string',
						implode( ', ', self::COLOR_INTENTS )
					)
				);
			}
		}

		// options — optional array of { key: { label, color_intent? } }.
		// Only meaningful for badge display type but legal on any type
		// (silently ignored by the renderer for non-badge types).
		if ( isset( $definition['options'] ) ) {
			$options_check = $this->validate_post_field_options( $definition['options'] );
			if ( is_wp_error( $options_check ) ) {
				return $options_check;
			}
		}

		// required — optional boolean (affects admin UI only, not validation).
		if ( isset( $definition['required'] ) && ! is_bool( $definition['required'] ) ) {
			return new WP_Error(
				'pcptpages_invalid_field_required',
				__( 'Post field required attribute must be true or false.', 'promptless-cpt-pages' )
			);
		}

		// date_format — optional, must be in DATE_FORMATS. Only meaningful
		// for date display type but validated regardless to surface typos.
		if ( isset( $definition['date_format'] ) ) {
			if ( ! in_array( $definition['date_format'], self::DATE_FORMATS, true ) ) {
				return new WP_Error(
					'pcptpages_invalid_date_format',
					sprintf(
						/* translators: %1$s: invalid format, %2$s: list of allowed formats */
						__( 'Post field date_format %1$s is not one of: %2$s', 'promptless-cpt-pages' ),
						is_string( $definition['date_format'] ) ? $definition['date_format'] : 'non-string',
						implode( ', ', self::DATE_FORMATS )
					)
				);
			}
		}

		// date_format_string — optional string. Required only when
		// date_format is 'custom'.
		if ( isset( $definition['date_format_string'] ) && ! is_string( $definition['date_format_string'] ) ) {
			return new WP_Error(
				'pcptpages_invalid_date_format_string',
				__( 'Post field date_format_string must be a string.', 'promptless-cpt-pages' )
			);
		}

		if ( ( $definition['date_format'] ?? '' ) === 'custom' && empty( $definition['date_format_string'] ) ) {
			return new WP_Error(
				'pcptpages_missing_date_format_string',
				__( 'Post field date_format is "custom" but date_format_string is empty.', 'promptless-cpt-pages' )
			);
		}

		// currency_code — optional, must be in SUPPORTED_CURRENCIES (or
		// extension via the pcptpages_supported_currencies filter).
		if ( ! empty( $definition['currency_code'] ) ) {
			$supported = $this->get_supported_currencies();
			$code      = strtoupper( $definition['currency_code'] );
			if ( ! in_array( $code, $supported, true ) ) {
				return new WP_Error(
					'pcptpages_invalid_currency_code',
					sprintf(
						/* translators: %s: the unsupported code */
						__( 'Currency code %s is not in the supported set. Use a standard ISO 4217 code or extend the list via the pcptpages_supported_currencies filter.', 'promptless-cpt-pages' ),
						$definition['currency_code']
					)
				);
			}
		}

		// max — optional integer (used by rating type, default 5; and
		// progress type, default 100).
		if ( isset( $definition['max'] ) && ! is_int( $definition['max'] ) && ! is_numeric( $definition['max'] ) ) {
			return new WP_Error(
				'pcptpages_invalid_field_max',
				__( 'Post field max must be a number.', 'promptless-cpt-pages' )
			);
		}

		// ----- Events vertical (v1.2) additive attributes -----
		// These tag a field for the events query + schema layers. They do
		// NOT alter the closed display-type / position / color-intent enums.
		// See docs/EVENTS_VERTICAL_DESIGN.md § 5 and § 7.

		// all_day — optional boolean. Only meaningful for the `date` display
		// type; validated regardless to surface typos early.
		if ( isset( $definition['all_day'] ) && ! is_bool( $definition['all_day'] ) ) {
			return new WP_Error(
				'pcptpages_invalid_all_day',
				__( 'Post field all_day must be true or false.', 'promptless-cpt-pages' )
			);
		}

		// event_timezone — optional IANA timezone id (e.g. America/New_York).
		// Empty means "use the site timezone." Only meaningful for date fields.
		if ( ! empty( $definition['event_timezone'] ) ) {
			if ( ! is_string( $definition['event_timezone'] )
				|| ! in_array( $definition['event_timezone'], timezone_identifiers_list(), true ) ) {
				return new WP_Error(
					'pcptpages_invalid_event_timezone',
					sprintf(
						/* translators: %s: the rejected timezone */
						__( 'Post field event_timezone %s is not a valid IANA timezone identifier.', 'promptless-cpt-pages' ),
						is_string( $definition['event_timezone'] ) ? $definition['event_timezone'] : 'non-string'
					)
				);
			}
		}

		// semantic_role — optional; must be in SEMANTIC_ROLES and must pair
		// with the role's required display_type. CPT-level uniqueness (a role
		// mapped at most once) is enforced in the registry, which has the
		// full field set in scope.
		if ( ! empty( $definition['semantic_role'] ) ) {
			if ( ! is_string( $definition['semantic_role'] )
				|| ! in_array( $definition['semantic_role'], self::SEMANTIC_ROLES, true ) ) {
				return new WP_Error(
					'pcptpages_invalid_semantic_role',
					sprintf(
						/* translators: %1$s: invalid role, %2$s: list of allowed roles */
						__( 'Post field semantic_role %1$s is not one of: %2$s', 'promptless-cpt-pages' ),
						is_string( $definition['semantic_role'] ) ? $definition['semantic_role'] : 'non-string',
						implode( ', ', self::SEMANTIC_ROLES )
					)
				);
			}

			$required_type = self::ROLE_DISPLAY_TYPES[ $definition['semantic_role'] ];
			if ( ( $definition['display_type'] ?? '' ) !== $required_type ) {
				return new WP_Error(
					'pcptpages_role_display_type_mismatch',
					sprintf(
						/* translators: %1$s: role, %2$s: required display type, %3$s: actual display type */
						__( 'Post field semantic_role %1$s requires display_type %2$s, got %3$s.', 'promptless-cpt-pages' ),
						$definition['semantic_role'],
						$required_type,
						is_string( $definition['display_type'] ?? null ) ? $definition['display_type'] : 'missing'
					)
				);
			}
		}

		// value_suffix — optional string appended after the formatted value.
		// Only meaningful for currency type (currently); validated regardless
		// of display_type so the data shape is consistent. Common values:
		// "+" (starting-at), "/mo" (monthly), "/night" (per night),
		// "+ tax" (taxes excluded). Length-capped to keep card layouts sane.
		if ( isset( $definition['value_suffix'] ) ) {
			if ( ! is_string( $definition['value_suffix'] ) ) {
				return new WP_Error(
					'pcptpages_invalid_value_suffix',
					__( 'Post field value_suffix must be a string.', 'promptless-cpt-pages' )
				);
			}
			if ( strlen( $definition['value_suffix'] ) > self::MAX_VALUE_SUFFIX_LEN ) {
				return new WP_Error(
					'pcptpages_value_suffix_too_long',
					sprintf(
						/* translators: %d: max length */
						__( 'Post field value_suffix must be %d characters or fewer.', 'promptless-cpt-pages' ),
						self::MAX_VALUE_SUFFIX_LEN
					)
				);
			}
		}

		// ----- Schema-driven filters (v1.2) additive attributes -----
		// Declarative opt-in for the filter/sort UI. The filter widget follows
		// from display_type (DISPLAY_TYPE_FILTER_WIDGETS); these attributes only
		// say "participate" and optionally override the widget. They do NOT
		// alter the closed display-type / position / color-intent enums. Full
		// contract: ai-section-builder-modern/docs/development/SCHEMA_DRIVEN_FILTERS_DESIGN.md § 3.

		// filterable / sortable — optional booleans.
		foreach ( array( 'filterable', 'sortable' ) as $flag ) {
			if ( isset( $definition[ $flag ] ) && ! is_bool( $definition[ $flag ] ) ) {
				return new WP_Error(
					'pcptpages_invalid_filter_flag',
					sprintf(
						/* translators: %s: the attribute key */
						__( 'Post field %s must be true or false.', 'promptless-cpt-pages' ),
						$flag
					)
				);
			}
		}

		$display_type  = $definition['display_type'] ?? '';
		$is_filterable = ! empty( $definition['filterable'] );
		$is_sortable   = ! empty( $definition['sortable'] );

		// meta_pair is display-only — it cannot be filtered or sorted.
		if ( ( $is_filterable || $is_sortable ) && $display_type === 'meta_pair' ) {
			return new WP_Error(
				'pcptpages_meta_pair_not_filterable',
				__( 'The meta_pair display type is display-only and cannot be marked filterable or sortable.', 'promptless-cpt-pages' )
			);
		}

		// A filterable/sortable field's key must not collide with the filter
		// engine's reserved URL params/suffixes, or its generated query params
		// would be ambiguous. Enforced only on opt-in, so existing non-filtered
		// fields are never affected.
		if ( $is_filterable || $is_sortable ) {
			$key = $definition['key'];
			if ( in_array( $key, self::RESERVED_FILTER_PARAMS, true ) ) {
				return new WP_Error(
					'pcptpages_field_key_reserved_param',
					sprintf(
						/* translators: %1$s: field key, %2$s: reserved params */
						__( 'Filterable/sortable field key %1$s collides with a reserved filter param (%2$s). Rename the field.', 'promptless-cpt-pages' ),
						$key,
						implode( ', ', self::RESERVED_FILTER_PARAMS )
					)
				);
			}
			foreach ( self::RESERVED_FILTER_SUFFIXES as $suffix ) {
				if ( substr( $key, -strlen( $suffix ) ) === $suffix ) {
					return new WP_Error(
						'pcptpages_field_key_reserved_suffix',
						sprintf(
							/* translators: %1$s: field key, %2$s: reserved suffix */
							__( 'Filterable/sortable field key %1$s ends with the reserved filter suffix %2$s. Rename the field.', 'promptless-cpt-pages' ),
							$key,
							$suffix
						)
					);
				}
			}
		}

		// filter_widget — optional override of the default widget. null / empty
		// means "use the display_type default." When set, must be a widget
		// allowed for this field's display_type.
		if ( isset( $definition['filter_widget'] ) && $definition['filter_widget'] !== null && $definition['filter_widget'] !== '' ) {
			$widget  = $definition['filter_widget'];
			$allowed = self::DISPLAY_TYPE_FILTER_WIDGETS[ $display_type ] ?? array();
			if ( ! is_string( $widget ) || ! in_array( $widget, $allowed, true ) ) {
				return new WP_Error(
					'pcptpages_invalid_filter_widget',
					sprintf(
						/* translators: %1$s: widget, %2$s: display type, %3$s: allowed widgets */
						__( 'Post field filter_widget %1$s is not valid for display_type %2$s. Allowed: %3$s', 'promptless-cpt-pages' ),
						is_string( $widget ) ? $widget : 'non-string',
						$display_type,
						$allowed ? implode( ', ', $allowed ) : '(this display type is not filterable)'
					)
				);
			}
		}

		return true;
	}

	/**
	 * Validate the `options` array on a post field definition.
	 *
	 * Shape: { option_value: { label: string, color_intent?: string } }
	 *
	 * @param mixed $options The options structure.
	 * @return true|WP_Error
	 */
	private function validate_post_field_options( $options ) {
		if ( ! is_array( $options ) ) {
			return new WP_Error(
				'pcptpages_invalid_field_options',
				__( 'Post field options must be an array.', 'promptless-cpt-pages' )
			);
		}

		if ( count( $options ) > self::MAX_FIELD_OPTIONS ) {
			return new WP_Error(
				'pcptpages_too_many_field_options',
				sprintf(
					/* translators: %d: max options count */
					__( 'Post field options must contain %d or fewer entries.', 'promptless-cpt-pages' ),
					self::MAX_FIELD_OPTIONS
				)
			);
		}

		foreach ( $options as $value => $opt ) {
			if ( ! is_string( $value ) || sanitize_key( $value ) !== $value ) {
				return new WP_Error(
					'pcptpages_invalid_option_key',
					sprintf(
						/* translators: %s: the invalid option key */
						__( 'Post field option key %s must be a sanitize_key-safe string.', 'promptless-cpt-pages' ),
						is_string( $value ) ? $value : 'non-string'
					)
				);
			}

			if ( ! is_array( $opt ) ) {
				return new WP_Error(
					'pcptpages_invalid_option_shape',
					sprintf(
						/* translators: %s: the option key */
						__( 'Post field option %s must be an object with at least a label.', 'promptless-cpt-pages' ),
						$value
					)
				);
			}

			if ( empty( $opt['label'] ) || ! is_string( $opt['label'] ) ) {
				return new WP_Error(
					'pcptpages_missing_option_label',
					sprintf(
						/* translators: %s: the option key */
						__( 'Post field option %s is missing a label.', 'promptless-cpt-pages' ),
						$value
					)
				);
			}

			if ( isset( $opt['color_intent'] ) ) {
				$valid_intents = array_merge( self::COLOR_INTENTS, self::COLOR_INTENTS_LEGACY );
				if ( ! in_array( $opt['color_intent'], $valid_intents, true ) ) {
					return new WP_Error(
						'pcptpages_invalid_option_color_intent',
						sprintf(
							/* translators: %s: the option key */
							__( 'Post field option %s has an invalid color_intent.', 'promptless-cpt-pages' ),
							$value
						)
					);
				}
			}
		}

		return true;
	}

	/**
	 * Validate a single field value being stored for a post.
	 *
	 * Per-display-type checks — currency must be numeric, date must be
	 * YYYY-MM-DD, rating must be between 0 and max, progress between 0
	 * and max, badge value must match one of the defined options (when
	 * options are defined), etc.
	 *
	 * @param array $field_def The field definition.
	 * @param mixed $value     The value being stored.
	 * @return true|WP_Error
	 */
	public function validate_post_field_value( array $field_def, $value ) {
		if ( empty( $field_def['display_type'] ) ) {
			return new WP_Error(
				'pcptpages_invalid_field_def',
				__( 'Field definition is missing a display_type.', 'promptless-cpt-pages' )
			);
		}

		// Null / empty values are always allowed — represent "not set" and
		// the renderer skips them. Validation only applies when a value is
		// actually being stored.
		if ( $value === null || $value === '' ) {
			return true;
		}

		switch ( $field_def['display_type'] ) {
			case 'currency':
				if ( ! is_numeric( $value ) ) {
					return new WP_Error(
						'pcptpages_invalid_currency_value',
						__( 'Currency value must be numeric.', 'promptless-cpt-pages' )
					);
				}
				return true;

			case 'number_with_label':
				if ( ! is_numeric( $value ) ) {
					return new WP_Error(
						'pcptpages_invalid_number_value',
						__( 'number_with_label value must be numeric.', 'promptless-cpt-pages' )
					);
				}
				return true;

			case 'date':
				// Accept YYYY-MM-DD (most common) or any strtotime-parseable
				// string. Storage normalization happens in PCPTPages_Post_Data;
				// here we just confirm parseability.
				if ( ! is_string( $value ) ) {
					return new WP_Error(
						'pcptpages_invalid_date_value',
						__( 'Date value must be a string.', 'promptless-cpt-pages' )
					);
				}
				if ( strtotime( $value ) === false ) {
					return new WP_Error(
						'pcptpages_unparseable_date',
						sprintf(
							/* translators: %s: the invalid date string */
							__( 'Date value %s could not be parsed. Use YYYY-MM-DD or a date format strtotime() understands.', 'promptless-cpt-pages' ),
							$value
						)
					);
				}
				return true;

			case 'text':
				if ( ! is_string( $value ) ) {
					return new WP_Error(
						'pcptpages_invalid_text_value',
						__( 'Text value must be a string.', 'promptless-cpt-pages' )
					);
				}
				if ( strlen( $value ) > self::MAX_TEXT_VALUE_LEN ) {
					return new WP_Error(
						'pcptpages_text_value_too_long',
						sprintf(
							/* translators: %d: max length */
							__( 'Text value must be %d characters or fewer.', 'promptless-cpt-pages' ),
							self::MAX_TEXT_VALUE_LEN
						)
					);
				}
				return true;

			case 'badge':
				// If options are defined, value must be one of them.
				// Otherwise any sanitize-keyable string is accepted.
				if ( ! is_string( $value ) ) {
					return new WP_Error(
						'pcptpages_invalid_badge_value',
						__( 'Badge value must be a string.', 'promptless-cpt-pages' )
					);
				}
				if ( ! empty( $field_def['options'] ) && is_array( $field_def['options'] ) ) {
					if ( ! isset( $field_def['options'][ $value ] ) ) {
						return new WP_Error(
							'pcptpages_badge_value_not_in_options',
							sprintf(
								/* translators: %1$s: value, %2$s: comma-separated valid options */
								__( 'Badge value %1$s is not one of the defined options: %2$s', 'promptless-cpt-pages' ),
								$value,
								implode( ', ', array_keys( $field_def['options'] ) )
							)
						);
					}
				}
				return true;

			case 'meta_pair':
				// Value is the right-hand side of the icon+value pair.
				// Accepts any non-empty string or number.
				if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
					return new WP_Error(
						'pcptpages_invalid_meta_pair_value',
						__( 'meta_pair value must be a string or number.', 'promptless-cpt-pages' )
					);
				}
				return true;

			case 'rating':
				// 0 to max (default 5). Decimal values allowed (4.7 stars).
				if ( ! is_numeric( $value ) ) {
					return new WP_Error(
						'pcptpages_invalid_rating_value',
						__( 'Rating value must be numeric.', 'promptless-cpt-pages' )
					);
				}
				$max = isset( $field_def['max'] ) && (int) $field_def['max'] > 0 ? (float) $field_def['max'] : 5.0;
				$num = (float) $value;
				if ( $num < 0 || $num > $max ) {
					return new WP_Error(
						'pcptpages_rating_out_of_range',
						sprintf(
							/* translators: %1$f: rating value, %2$f: max */
							__( 'Rating value %1$s is out of range. Must be between 0 and %2$s.', 'promptless-cpt-pages' ),
							(string) $num,
							(string) $max
						)
					);
				}
				return true;

			case 'progress':
				// Current value; the goal is stored in `_pcptpages_field_{key}_goal`.
				// Here we only validate the current value is numeric and
				// non-negative; the renderer clamps to [0, goal].
				if ( ! is_numeric( $value ) ) {
					return new WP_Error(
						'pcptpages_invalid_progress_value',
						__( 'Progress value must be numeric.', 'promptless-cpt-pages' )
					);
				}
				if ( (float) $value < 0 ) {
					return new WP_Error(
						'pcptpages_progress_negative',
						__( 'Progress value cannot be negative.', 'promptless-cpt-pages' )
					);
				}
				return true;

			case 'multi_badge':
				// Value is a comma-separated string OR an array. Both accepted;
				// renderer normalizes. Validate count cap and per-segment shape.
				if ( is_string( $value ) ) {
					$segments = array_map( 'trim', explode( ',', $value ) );
				} elseif ( is_array( $value ) ) {
					$segments = array_map( 'strval', $value );
				} else {
					return new WP_Error(
						'pcptpages_invalid_multi_badge_value',
						__( 'multi_badge value must be a comma-separated string or array.', 'promptless-cpt-pages' )
					);
				}

				$segments = array_filter( $segments, 'strlen' );
				if ( count( $segments ) > self::MAX_MULTI_BADGE_VALUES ) {
					return new WP_Error(
						'pcptpages_too_many_multi_badge_values',
						sprintf(
							/* translators: %d: max count */
							__( 'multi_badge value contains too many segments. Maximum is %d.', 'promptless-cpt-pages' ),
							self::MAX_MULTI_BADGE_VALUES
						)
					);
				}
				return true;
		}

		// Unknown display type — should be caught at definition validation
		// time; this is belt-and-suspenders.
		return new WP_Error(
			'pcptpages_unknown_display_type',
			sprintf(
				/* translators: %s: the display type */
				__( 'Unknown display type %s on field definition.', 'promptless-cpt-pages' ),
				is_string( $field_def['display_type'] ) ? $field_def['display_type'] : 'non-string'
			)
		);
	}

	/**
	 * Validate the per-post field visibility overrides structure.
	 *
	 * Expected shape:
	 *   {
	 *     "field_key_1": { "card_hidden": bool, "single_hidden": bool },
	 *     "field_key_2": { "card_hidden": bool, "single_hidden": bool },
	 *   }
	 *
	 * Either key in each entry is optional (defaults to false). The whole
	 * entry may be absent for a field, meaning "use default visibility."
	 *
	 * @param mixed $visibility The visibility array (already decoded if JSON).
	 * @return true|WP_Error
	 */
	public function validate_post_field_visibility( $visibility ) {
		if ( ! is_array( $visibility ) ) {
			return new WP_Error(
				'pcptpages_invalid_visibility_shape',
				__( 'Field visibility must be an array (or JSON object).', 'promptless-cpt-pages' )
			);
		}

		foreach ( $visibility as $field_key => $flags ) {
			if ( ! is_string( $field_key ) || sanitize_key( $field_key ) !== $field_key ) {
				return new WP_Error(
					'pcptpages_invalid_visibility_key',
					sprintf(
						/* translators: %s: the invalid key */
						__( 'Visibility key %s must be a sanitize_key-safe field key.', 'promptless-cpt-pages' ),
						is_string( $field_key ) ? $field_key : 'non-string'
					)
				);
			}

			if ( ! is_array( $flags ) ) {
				return new WP_Error(
					'pcptpages_invalid_visibility_entry',
					sprintf(
						/* translators: %s: the field key */
						__( 'Visibility entry for %s must be an object with card_hidden and/or single_hidden booleans.', 'promptless-cpt-pages' ),
						$field_key
					)
				);
			}

			foreach ( array( 'card_hidden', 'single_hidden' ) as $flag_key ) {
				if ( isset( $flags[ $flag_key ] ) && ! is_bool( $flags[ $flag_key ] ) ) {
					return new WP_Error(
						'pcptpages_invalid_visibility_flag',
						sprintf(
							/* translators: %1$s: field key, %2$s: flag name */
							__( 'Visibility flag %2$s for field %1$s must be true or false.', 'promptless-cpt-pages' ),
							$field_key,
							$flag_key
						)
					);
				}
			}
		}

		return true;
	}

	/**
	 * Get the effective supported-currencies list, honoring the
	 * `pcptpages_supported_currencies` filter.
	 *
	 * @return string[]
	 */
	private function get_supported_currencies() {
		/**
		 * Filter the closed list of ISO 4217 currency codes accepted by
		 * the validator.
		 *
		 * Default includes the ~30 most commonly-used currencies. Sites
		 * with niche needs (crypto, regional codes) extend via this
		 * filter. Always return an array of uppercase 3-letter strings.
		 *
		 * @param string[] $codes Supported currency codes.
		 */
		$codes = apply_filters( 'pcptpages_supported_currencies', self::SUPPORTED_CURRENCIES );
		return is_array( $codes ) ? array_map( 'strtoupper', $codes ) : self::SUPPORTED_CURRENCIES;
	}
}
