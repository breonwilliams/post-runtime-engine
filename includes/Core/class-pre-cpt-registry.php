<?php
/**
 * CPT registry for Promptless CPT Pages.
 *
 * Persists CPT definitions in the `pcptpages_cpts` option and registers them with
 * WordPress on `init` (priority 5, set up by the main plugin class). This
 * is the data layer for "Promptless owns CPT registration end-to-end" — no
 * dependency on CPT UI, ACF, or any other field plugin.
 *
 * Definitions are validated through PCPTPages_Validator before persistence. The
 * registry exposes a focused CRUD API; admin UI and connector endpoints in
 * later phases sit on top of this.
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CPT registry. Stores definitions in wp_options and registers them with
 * WordPress on init.
 */
class PCPTPages_CPT_Registry {

	/**
	 * Option key holding the CPT definitions array.
	 */
	const OPTION_KEY = 'pcptpages_cpts';

	/**
	 * Cache group used for in-process memoization. We do not write to the
	 * persistent object cache because the option API already does that for us
	 * via `alloptions`; this group is for repeat lookups within a single
	 * request only.
	 */
	const CACHE_GROUP = 'pcptpages_cpts';

	/**
	 * Validator instance.
	 *
	 * @var PCPTPages_Validator
	 */
	private $validator;

	/**
	 * Memoized definitions array. Loaded lazily by get_all().
	 *
	 * @var array<string,array>|null
	 */
	private $cache = null;

	/**
	 * Constructor.
	 *
	 * @param PCPTPages_Validator|null $validator Optional validator dependency
	 *                                      (defaults to a new instance).
	 */
	public function __construct( $validator = null ) {
		$this->validator = $validator ?: new PCPTPages_Validator();
	}

	/**
	 * Get all stored CPT definitions, keyed by slug.
	 *
	 * @return array<string,array>
	 */
	public function get_all() {
		if ( $this->cache !== null ) {
			return $this->cache;
		}

		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$this->cache = $stored;
		return $stored;
	}

	/**
	 * Get a single CPT definition by slug.
	 *
	 * @param string $slug CPT slug.
	 * @return array|null Definition or null if not registered.
	 */
	public function get( $slug ) {
		$slug = $this->sanitize_slug( $slug );
		if ( $slug === '' ) {
			return null;
		}

		$all = $this->get_all();
		return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
	}

	/**
	 * Check whether a CPT is registered.
	 *
	 * @param string $slug CPT slug.
	 * @return bool
	 */
	public function exists( $slug ) {
		return $this->get( $slug ) !== null;
	}

	/**
	 * Register a new CPT or update an existing one.
	 *
	 * Validates the definition, merges in defaults, persists to the option,
	 * and fires an action hook. Does NOT call register_post_type() directly —
	 * that happens on the next init action via register_all_with_wp() to keep
	 * the WP registration timing predictable.
	 *
	 * @param string $slug       CPT slug. Sanitized to a key shape.
	 * @param array  $definition Definition. See PCPTPages_Validator::validate_cpt_definition.
	 * @return true|WP_Error
	 */
	public function register( $slug, array $definition ) {
		// Quick reject for empty / non-string input. Format-level checks
		// (sanitize_key match, length, reserved-slug list) happen in the
		// validator. Do NOT pre-sanitize here — pre-sanitizing would silently
		// transform malformed input ('Bad Slug!' → 'badslug') and bypass the
		// validator's rejection. The validator must see the original input
		// to decide whether to reject.
		if ( ! is_string( $slug ) || $slug === '' ) {
			return new WP_Error(
				'pcptpages_invalid_slug',
				__( 'CPT slug is empty or invalid.', 'promptless-cpt-pages' )
			);
		}

		// Force the slug field of the definition to match the parameter for
		// internal consistency. The validator then checks the format.
		$definition['slug'] = $slug;

		$valid = $this->validator->validate_cpt_definition( $definition );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// After validation succeeds, the slug is guaranteed to equal
		// sanitize_key($slug), so it's safe to use directly as the storage key.

		$definition = $this->merge_defaults( $definition );

		// Stamp metadata that's helpful for debugging and the connector audit
		// trail. `connector_version` mirrors the FRE / FlowMint pattern —
		// every persistence bump increments it.
		$existing = $this->get( $slug );
		$now      = current_time( 'mysql', true );

		$definition['created_at']        = $existing['created_at'] ?? $now;
		$definition['updated_at']        = $now;
		$definition['connector_version'] = isset( $existing['connector_version'] )
			? (int) $existing['connector_version'] + 1
			: 1;

		$all          = $this->get_all();
		$all[ $slug ] = $definition;

		$saved = update_option( self::OPTION_KEY, $all );
		// update_option() returns false when the value is unchanged. That's
		// not actually a failure for our purposes — but it IS a failure if
		// the option didn't exist and add_option also failed. The cleanest
		// way to detect a real failure is to read back and compare.
		$this->cache = $all;

		if ( ! $saved && get_option( self::OPTION_KEY ) !== $all ) {
			return new WP_Error(
				'pcptpages_cpt_save_failed',
				__( 'Failed to persist CPT definition to the database.', 'promptless-cpt-pages' )
			);
		}

		// Flag a rewrite-rules flush. The actual flush runs on the NEXT
		// init after register_all_with_wp() finishes, because flushing
		// before the new CPT is registered would just rebuild stale rules.
		set_transient( 'pcptpages_needs_rewrite_flush', 1, HOUR_IN_SECONDS );

		/**
		 * Fires after a CPT is registered or updated through the registry.
		 *
		 * Note: this fires BEFORE the CPT is registered with WordPress
		 * (which happens on the next init). Listeners that need the post
		 * type to be queryable should hook a later action.
		 *
		 * @param string $slug       CPT slug.
		 * @param array  $definition Stored definition.
		 * @param bool   $is_new     True if this is a fresh registration; false on update.
		 */
		do_action( 'pcptpages_cpt_registered', $slug, $definition, $existing === null );

		return true;
	}

	/**
	 * Unregister a CPT. Removes the definition from storage. Does NOT delete
	 * any posts of that type — they remain in the database, just orphaned.
	 *
	 * @param string $slug CPT slug.
	 * @return true|WP_Error
	 */
	public function unregister( $slug ) {
		$slug = $this->sanitize_slug( $slug );
		if ( $slug === '' ) {
			return new WP_Error(
				'pcptpages_invalid_slug',
				__( 'CPT slug is empty or invalid.', 'promptless-cpt-pages' )
			);
		}

		$all = $this->get_all();
		if ( ! isset( $all[ $slug ] ) ) {
			return new WP_Error(
				'pcptpages_cpt_not_found',
				/* translators: %s: CPT slug */
				sprintf( __( 'CPT %s is not registered.', 'promptless-cpt-pages' ), $slug )
			);
		}

		unset( $all[ $slug ] );
		update_option( self::OPTION_KEY, $all );
		$this->cache = $all;

		// Unregister from WordPress's runtime registry too. This is reversible
		// — the CPT will not be re-registered until something calls register
		// again.
		if ( post_type_exists( $slug ) ) {
			unregister_post_type( $slug );
		}

		// Same flush flag as register() — removing a CPT also dirties the
		// rewrite cache.
		set_transient( 'pcptpages_needs_rewrite_flush', 1, HOUR_IN_SECONDS );

		/**
		 * Fires after a CPT is unregistered through the registry.
		 *
		 * @param string $slug CPT slug.
		 */
		do_action( 'pcptpages_cpt_unregistered', $slug );

		return true;
	}

	/**
	 * Register every stored CPT with WordPress.
	 *
	 * Called from Post_Runtime_Engine::register_post_types() on init priority
	 * 5. Idempotent — safe to call repeatedly because register_post_type
	 * itself overwrites silently.
	 */
	public function register_all_with_wp() {
		foreach ( $this->get_all() as $slug => $definition ) {
			$args = $this->build_register_args( $definition );

			$result = register_post_type( $slug, $args );

			// register_post_type returns WP_Error on failure (typically a
			// reserved slug). Surface those failures via an action so other
			// code can react without crashing the request.
			if ( is_wp_error( $result ) ) {
				/**
				 * Fires when register_post_type fails for a stored CPT.
				 *
				 * @param string   $slug       CPT slug.
				 * @param WP_Error $error      The error returned by core.
				 * @param array    $definition Stored definition.
				 */
				do_action( 'pcptpages_cpt_registration_failed', $slug, $result, $definition );
			}
		}

		// If a register() or unregister() call set the dirty flag earlier,
		// flush rewrite rules now (after WP has re-learned the post types).
		// flush_rewrite_rules(false) is the recommended form — it doesn't
		// touch the .htaccess file, which is a more expensive write.
		if ( get_transient( 'pcptpages_needs_rewrite_flush' ) ) {
			flush_rewrite_rules( false );
			delete_transient( 'pcptpages_needs_rewrite_flush' );
		}
	}

	/**
	 * Translate a stored definition into the args array that
	 * register_post_type() expects.
	 *
	 * @param array $definition Stored definition.
	 * @return array
	 */
	private function build_register_args( array $definition ) {
		$singular = $definition['label_singular'];
		$plural   = $definition['label_plural'];

		$labels = array(
			'name'                  => $plural,
			'singular_name'         => $singular,
			'menu_name'             => $plural,
			'name_admin_bar'        => $singular,
			'add_new'               => __( 'Add New', 'promptless-cpt-pages' ),
			/* translators: %s: singular post type label */
			'add_new_item'          => sprintf( __( 'Add New %s', 'promptless-cpt-pages' ), $singular ),
			/* translators: %s: singular label */
			'new_item'              => sprintf( __( 'New %s', 'promptless-cpt-pages' ), $singular ),
			/* translators: %s: singular label */
			'edit_item'             => sprintf( __( 'Edit %s', 'promptless-cpt-pages' ), $singular ),
			/* translators: %s: singular label */
			'view_item'             => sprintf( __( 'View %s', 'promptless-cpt-pages' ), $singular ),
			/* translators: %s: plural label */
			'all_items'             => sprintf( __( 'All %s', 'promptless-cpt-pages' ), $plural ),
			/* translators: %s: plural label */
			'search_items'          => sprintf( __( 'Search %s', 'promptless-cpt-pages' ), $plural ),
			/* translators: %s: plural label */
			'not_found'             => sprintf( __( 'No %s found.', 'promptless-cpt-pages' ), strtolower( $plural ) ),
			/* translators: %s: plural label */
			'not_found_in_trash'    => sprintf( __( 'No %s found in Trash.', 'promptless-cpt-pages' ), strtolower( $plural ) ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => $definition['public'],
			'hierarchical'        => $definition['hierarchical'],
			'has_archive'         => $definition['has_archive'],
			'show_in_rest'        => $definition['show_in_rest'],
			'show_in_menu'        => $definition['show_in_menu'],
			'menu_position'       => $definition['menu_position'],
			'menu_icon'           => $definition['menu_icon'],
			'supports'            => $definition['supports'],
			'taxonomies'          => $definition['taxonomies'],
			'capability_type'     => $definition['capability_type'],
			'map_meta_cap'        => true,
			'rewrite'             => array(
				'slug'       => isset( $definition['rewrite_slug'] ) ? $definition['rewrite_slug'] : $definition['slug'],
				'with_front' => false,
				'feeds'      => false,
				'pages'      => true,
			),
			'description'         => $definition['description'],
		);

		if ( isset( $definition['rest_base'] ) && $definition['rest_base'] !== '' ) {
			$args['rest_base'] = $definition['rest_base'];
		}

		/**
		 * Filter the args passed to register_post_type for a Promptless-owned
		 * CPT. Use sparingly — broad changes here can break consumer
		 * expectations of how registered CPTs behave.
		 *
		 * @param array  $args       register_post_type args.
		 * @param array  $definition The stored definition.
		 * @param string $slug       The CPT slug.
		 */
		return apply_filters( 'pcptpages_cpt_register_args', $args, $definition, $definition['slug'] );
	}

	/**
	 * Apply default values to a partial definition. Called after validation
	 * (which doesn't mutate input) so the persisted shape is normalized.
	 *
	 * @param array $definition Partial definition.
	 * @return array Fully-normalized definition.
	 */
	private function merge_defaults( array $definition ) {
		$defaults = array(
			'public'              => true,
			'hierarchical'        => false,
			'has_archive'         => true,
			'show_in_rest'        => true,
			'show_in_menu'        => true,
			'menu_position'       => 25,
			'menu_icon'           => 'dashicons-admin-post',
			'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
			'taxonomies'          => array(),
			'capability_type'     => 'post',
			'description'         => '',

			// Hero defaults — applied to existing CPT registrations
			// transparently. Stacked is the safe default (matches the
			// pre-feature behavior); split is opt-in per CPT.
			'hero_layout'         => 'stacked',
			'hero_image_position' => 'left',
			'hero_image_aspect'   => 'square',

			// Hero overlay focus (Phase B, docs/HERO_CONTRAST_DESIGN.md).
			// Only meaningful when hero_layout is 'overlay'; maps to the
			// backdrop image's object-position.
			'hero_overlay_focus'  => 'center',

			// Hero contrast/width (Phase A, docs/HERO_CONTRAST_DESIGN.md).
			// 'inherit'/'contained' reproduce pre-Phase-A rendering exactly
			// (no extra classes emitted), so existing CPTs pick these up
			// transparently with zero visual change. 'dark' + 'full' is the
			// opt-in "full-width dark hero band on a light page" treatment.
			'hero_theme'          => 'inherit',
			'hero_width'          => 'contained',

			// Default icon — empty string means "no fallback". Authors who
			// want their compact-grid / horizontal-row groupings to always
			// have a visual cue set this to an icon ID from PCPTPages_Icon_Library.
			// Validated at register time, so empty string here is the only
			// path that bypasses the icon-library check.
			'default_icon'        => '',

			// Archive card meta — whether the theme should render the post
			// date and author byline on archive cards. Default true to
			// preserve existing-site behavior; turn off per-CPT when the
			// CPT already exposes a date field of its own (e.g. an event
			// CPT whose `event_date` post field is the meaningful date
			// rather than the post's create-date).
			'archive_show_post_date'   => true,
			'archive_show_post_author' => true,

			// Archive card featured-image aspect ratio. Answered to the
			// theme through the promptless_archive_image_aspect filter
			// (PCPTPages_Card_Filter_Hooks). '16:9' matches the theme's
			// historical hardcoded crop, so existing CPTs render
			// unchanged; people-centric CPTs typically want '1:1' or
			// '4:5'. Enum: PCPTPages_Validator::ARCHIVE_IMAGE_ASPECTS.
			'archive_image_aspect'     => '16:9',
		);

		// wp_parse_args is shallow — it only fills missing top-level keys.
		// That's what we want here.
		return wp_parse_args( $definition, $defaults );
	}

	/**
	 * Sanitize a CPT slug to a valid WP key. Returns empty string on invalid
	 * input.
	 *
	 * @param string $slug Raw slug.
	 * @return string Sanitized slug.
	 */
	private function sanitize_slug( $slug ) {
		if ( ! is_string( $slug ) ) {
			return '';
		}
		$sanitized = sanitize_key( $slug );
		return $sanitized;
	}

	/**
	 * Reset the in-memory cache. Used by tests and after option-level writes
	 * from external code. Most callers should not need this.
	 */
	public function reset_cache() {
		$this->cache = null;
	}
}
