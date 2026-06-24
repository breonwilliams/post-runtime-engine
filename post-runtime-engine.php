<?php
/**
 * Plugin Name: Promptless CPT Pages
 * Plugin URI: https://promptlesswp.com
 * Description: Render CPT single pages with structured data display, layout variants, and admin or AI population. Works standalone or with Promptless WP.
 * Version: 0.6.1
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author: Promptless WP
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: promptless-cpt-pages
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin version. Bumped on every meaningful release.
define( 'PCPTPages_VERSION', '0.6.1' );

// Minimum data-storage schema version. Bumped when option / post-meta shapes
// change in a way that requires migration, or when a new capability needs to
// be granted on existing installs. The plugin compares this constant to the
// stored `pcptpages_data_version` option on every page load and runs upgrade tasks
// when they don't match.
//
// Version history (data-version, not plugin-version):
//   0.1.0 — initial Phase 1 schema (CPT registry + grouping definitions)
//   0.2.0 — capability upgrade: scoped `pcptpages_manage_cpts` granted to
//           administrator role (replaces raw `manage_options` checks)
//   0.3.0 — meta_match source mode + auto-registration of meta_match meta
//           keys via register_post_meta() on init priority 6. No data
//           migration required — purely additive runtime behavior.
//   0.4.0 — v1.1 Phase 8: post fields (second field type). Adds the
//           pcptpages_post_fields_{cpt_slug} option family and the
//           _pcptpages_field_{key} / _pcptpages_field_visibility post meta keys.
//           No data migration required — purely additive opt-in;
//           CPTs without post fields render identically to v0.3.x.
//           Version bump exists as a marker so future upgrades can
//           confidently assume the v1.1 storage shape is available.
define( 'PCPTPages_DATA_VERSION', '0.4.0' );

// Plugin paths and URLs.
define( 'PCPTPages_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PCPTPages_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PCPTPages_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'PCPTPages_PLUGIN_FILE', __FILE__ );

// REST namespace (used by the connector in Phase 3+).
define( 'PCPTPages_REST_NAMESPACE', 'post-runtime/v1' );
define( 'PCPTPages_REST_BASE', 'connector' );

// Autoloader. Loaded immediately so any code below can rely on PCPTPages_* classes.
require_once PCPTPages_PLUGIN_DIR . 'includes/class-pre-autoloader.php';
PCPTPages_Autoloader::register();

/**
 * Main plugin class.
 *
 * Singleton. Owns lifecycle hooks, exposes the registries, and wires up the
 * init flow. Admin / frontend / connector subsystems hook themselves on later
 * actions and are not loaded here directly — the autoloader resolves them
 * lazily as classes are referenced.
 */
final class Promptless_CPT_Pages {

	/**
	 * Singleton instance.
	 *
	 * @var Promptless_CPT_Pages|null
	 */
	private static $instance = null;

	/**
	 * CPT registry instance. Populated on init().
	 *
	 * @var PCPTPages_CPT_Registry|null
	 */
	public $cpts = null;

	/**
	 * Grouping registry instance. Populated on init().
	 *
	 * @var PCPTPages_Grouping_Registry|null
	 */
	public $groupings = null;

	/**
	 * Post field registry instance (v1.1). Populated on init().
	 *
	 * Owns the second field type — scalar post fields keyed by display
	 * type and position. Distinct from $groupings (repeatable items).
	 * Design contract: docs/POST_FIELDS_V1_1_DESIGN.md.
	 *
	 * @var PCPTPages_Post_Field_Registry|null
	 */
	public $post_fields = null;

	/**
	 * Post-data accessor instance. Populated on init().
	 *
	 * @var PCPTPages_Post_Data|null
	 */
	public $post_data = null;

	/**
	 * Admin coordinator instance. Populated on init() when in wp-admin.
	 *
	 * @var PCPTPages_Admin|null
	 */
	public $admin = null;

	/**
	 * Template router instance.
	 *
	 * @var PCPTPages_Template_Router|null
	 */
	public $template_router = null;

	/**
	 * Frontend asset coordinator.
	 *
	 * @var PCPTPages_Frontend_Assets|null
	 */
	public $frontend_assets = null;

	/**
	 * Connector REST controller. Populated on init() — needed in REST
	 * requests as well as admin and frontend, so always loaded.
	 *
	 * @var PCPTPages_Connector_API|null
	 */
	public $connector_api = null;

	/**
	 * Connector admin page coordinator. Populated on init() in admin
	 * context only — the settings UI for enabling the connector and
	 * generating Application Passwords.
	 *
	 * @var PCPTPages_Connector_Admin|null
	 */
	public $connector_admin = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Promptless_CPT_Pages
	 */
	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Wires lifecycle and bootstrap hooks. Does NOT instantiate
	 * subsystems — that happens in init() which fires on plugins_loaded.
	 */
	private function __construct() {
		// Lifecycle hooks must be registered against the main plugin file path.
		register_activation_hook( PCPTPages_PLUGIN_FILE, array( $this, 'on_activation' ) );
		register_deactivation_hook( PCPTPages_PLUGIN_FILE, array( $this, 'on_deactivation' ) );

		// Initialize registries on plugins_loaded so all WordPress core
		// functions are available, but BEFORE init so we can register CPTs
		// from stored definitions on the standard init hook.
		add_action( 'plugins_loaded', array( $this, 'init' ), 10 );

		// Register any auto-managed taxonomies BEFORE CPTs so attach-by-name
		// resolution succeeds. Priority 4 = before register_post_types().
		add_action( 'init', array( $this, 'register_auto_taxonomies' ), 4 );

		// Register all stored CPTs with WordPress on init. Uses priority 5 so
		// any code hooking init at the default priority (10) sees the CPTs
		// already registered.
		add_action( 'init', array( $this, 'register_post_types' ), 5 );

		// Auto-register post-meta keys referenced by meta_match grouping
		// definitions. Runs at priority 6 — after CPTs are registered, so
		// the meta is correctly scoped to the parent CPT. See method
		// docblock for the full rationale.
		add_action( 'init', array( $this, 'register_meta_match_keys' ), 6 );

		// Translation loading: WordPress 4.6+ automatically loads .mo files
		// for plugins hosted on WordPress.org. For GitHub-distributed copies,
		// translations also load automatically as long as the .mo files are
		// in the standard WP_LANG_DIR/plugins/post-runtime-engine-{locale}.mo
		// path. No explicit load_plugin_textdomain() call is needed — and
		// Plugin Check flags that call as discouraged for WP.org plugins.

		// Run lightweight data-version upgrade tasks (capability grants,
		// option migrations) on every admin/CLI request. The check itself is
		// a single get_option() call — cheap to run, only writes when the
		// stored version differs from PCPTPages_DATA_VERSION.
		add_action( 'plugins_loaded', array( $this, 'maybe_run_data_upgrade' ), 5 );
	}

	/**
	 * Block cloning of the singleton.
	 */
	private function __clone() {}

	/**
	 * Block unserializing of the singleton.
	 *
	 * @throws Exception When unserialize is attempted.
	 */
	public function __wakeup() {
		throw new Exception( 'Cannot unserialize Promptless_CPT_Pages singleton.' );
	}

	/**
	 * Initialize the plugin.
	 *
	 * Instantiates the registries and the post-data accessor. Each of these
	 * holds its own state and exposes a focused API. Admin and frontend
	 * subsystems will hook their own bootstrap on later actions in subsequent
	 * phases — they are NOT instantiated here.
	 */
	public function init() {
		$this->cpts        = new PCPTPages_CPT_Registry();
		$this->groupings   = new PCPTPages_Grouping_Registry();
		// v1.1: post field registry. Instantiated BEFORE PCPTPages_Post_Data so
		// the constructor injection path resolves to the populated
		// registry rather than falling through to the lazy-resolve in
		// PCPTPages_Post_Data::get_post_field_registry().
		$this->post_fields = new PCPTPages_Post_Field_Registry();
		$this->post_data   = new PCPTPages_Post_Data( $this->cpts, $this->groupings, null, $this->post_fields );

		// Admin module is only loaded inside wp-admin. Frontend / REST
		// requests don't need any of this.
		if ( is_admin() ) {
			$this->admin           = new PCPTPages_Admin();
			$this->connector_admin = new PCPTPages_Connector_Admin();
			$this->connector_admin->init();
		}

		// Frontend rendering — instantiated regardless of context because
		// REST requests can also produce post output (preview endpoint)
		// and admin previews need the renderer too.
		$this->template_router = new PCPTPages_Template_Router();
		$this->frontend_assets = new PCPTPages_Frontend_Assets();

		// v1.1 Phase 12: card filter hooks. Subscribes to the actions
		// exposed by Promptless WP's PostGrid renderer and the Promptless
		// theme's archive card template so post fields render in those
		// surfaces too. When the upstream consumers don't exist (different
		// theme, no PostGrid section on the page) the actions never fire
		// and this listener stays silent — no overhead, no dependency.
		( new PCPTPages_Card_Filter_Hooks() )->init();

		// Events vertical (v1.2): Schema.org Event JSON-LD on CPT singles
		// that map an event_start role. Self-contained — no Promptless
		// dependency; defers to Promptless on _aisb_enabled pages.
		( new PCPTPages_Event_Schema() )->init();

		// PostGrid editor-preview parity (v1.2.x): REST endpoint that returns
		// batched, position-keyed card-field HTML so Promptless WP's React
		// canvas preview can render the same metadata the front end shows.
		( new PCPTPages_Editor_Preview_API() )->init();

		// Schema-driven filters (v1.2): filter-descriptor provider. Supplies
		// the `aisb_postgrid_available_filters` PHP hook (front-end render)
		// and the `/editor/post-type-filters/{slug}` REST route (editor JS),
		// mapping filterable post fields + taxonomies to Promptless's generic
		// widget vocabulary. Decoupled — Promptless never learns a PRE type.
		( new PCPTPages_Filter_Descriptors() )->init();

		// Wire render-cache invalidation hooks. Static — no instance
		// state required. The renderer caches output per-post via a
		// transient and busts the cache on save_post / before_delete_post
		// / set_object_terms, plus on grouping-definition changes.
		PCPTPages_Renderer::init_cache_invalidation();

		// Connector REST API — always loaded so authenticated agents
		// can call /wp-json/post-runtime/v1/connector/* from any
		// context. The auth gate inside each route ensures the
		// connector_enabled toggle is honored.
		$this->connector_api = new PCPTPages_Connector_API();
		$this->connector_api->init();

		/**
		 * Fires after Promptless CPT Pages has finished initializing its core
		 * registries.
		 *
		 * Other plugins can hook here to extend behavior. The plugin instance
		 * is passed so consumers can access the registries.
		 *
		 * @param Promptless_CPT_Pages $plugin The plugin instance.
		 */
		do_action( 'pcptpages_init', $this );
	}

	/**
	 * Register taxonomies persisted in the `pcptpages_auto_taxonomies` option.
	 *
	 * This is the bridge that lets fixture scripts and (eventually) Cowork
	 * register a taxonomy alongside a CPT and have it survive across
	 * requests. The plugin's main job remains rendering CPT data, not
	 * defining taxonomy schemas — but storing-and-replaying taxonomy
	 * registrations is a small enough affordance that we pay it.
	 *
	 * The option shape is:
	 *   array(
	 *     '{slug}' => array(
	 *       'object_type' => string|string[],
	 *       'args'        => array (passed straight to register_taxonomy),
	 *     ),
	 *     ...
	 *   )
	 */
	public function register_auto_taxonomies() {
		$auto = get_option( 'pcptpages_auto_taxonomies', array() );
		if ( ! is_array( $auto ) ) {
			return;
		}

		foreach ( $auto as $slug => $config ) {
			$slug = sanitize_key( $slug );
			if ( $slug === '' || taxonomy_exists( $slug ) ) {
				continue;
			}
			$object_type = $config['object_type'] ?? 'post';
			$args        = is_array( $config['args'] ?? null ) ? $config['args'] : array();
			register_taxonomy( $slug, $object_type, $args );
		}
	}

	/**
	 * Register all stored CPTs with WordPress.
	 *
	 * Reads from PCPTPages_CPT_Registry and calls register_post_type() for each
	 * stored CPT definition. Runs on init (priority 5).
	 */
	public function register_post_types() {
		if ( ! $this->cpts ) {
			return;
		}

		$this->cpts->register_all_with_wp();
	}

	/**
	 * Auto-register post-meta keys referenced by meta_match grouping sources.
	 *
	 * The meta_match source mode (added in v0.4.0) reads a configured post-meta
	 * key on the current post and finds sibling posts whose value matches.
	 * For the resolver to be useful, those meta keys need to be writable on
	 * the posts themselves.
	 *
	 * On sites that use ACF / MetaBox / Pods, the field plugin already
	 * registers the meta and exposes it via REST. On sites without one of
	 * those plugins, the meta key would be unwritable through the REST API
	 * (WordPress filters out unregistered meta from the `meta` field on
	 * /wp/v2/{cpt}/{id} POST). That's a real production friction — the user
	 * defines a meta_match grouping, then has no way to populate the values
	 * via the connector.
	 *
	 * This method closes the gap by walking every grouping definition for
	 * every registered CPT, finding meta_match groupings, and calling
	 * register_post_meta() with show_in_rest=true and an edit_post auth
	 * callback. Idempotent — register_post_meta() with the same args is a
	 * no-op on subsequent calls.
	 *
	 * Runs on init priority 6, after CPTs are registered (priority 5) so the
	 * post type exists when register_post_meta is called against it.
	 *
	 * Sites that prefer to manage these meta keys through their own field
	 * plugin can disable this auto-registration via the
	 * `pcptpages_auto_register_meta_match_keys` filter (return false).
	 */
	public function register_meta_match_keys() {
		if ( ! $this->cpts || ! $this->groupings ) {
			return;
		}

		/**
		 * Filter whether PRE auto-registers post-meta keys referenced by
		 * meta_match grouping sources. Return false to disable when a field
		 * plugin (ACF, MetaBox, Pods) already manages these keys.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'pcptpages_auto_register_meta_match_keys', true ) ) {
			return;
		}

		// Track which (cpt, key) pairs we've already registered this request
		// so a key referenced by multiple groupings isn't re-registered (cheap
		// but pointless).
		static $registered = array();

		foreach ( $this->cpts->get_all() as $cpt_slug => $_def ) {
			if ( ! post_type_exists( $cpt_slug ) ) {
				continue;
			}

			$groupings = $this->groupings->get_all( $cpt_slug );
			if ( ! is_array( $groupings ) ) {
				continue;
			}

			foreach ( $groupings as $grouping ) {
				$source = $grouping['default_source'] ?? null;
				if ( ! is_array( $source ) || ( $source['type'] ?? '' ) !== 'meta_match' ) {
					continue;
				}

				$meta_key = isset( $source['meta_key'] ) ? (string) $source['meta_key'] : '';
				if ( $meta_key === '' ) {
					continue;
				}

				$dedupe_key = $cpt_slug . '|' . $meta_key;
				if ( isset( $registered[ $dedupe_key ] ) ) {
					continue;
				}
				$registered[ $dedupe_key ] = true;

				register_post_meta(
					$cpt_slug,
					$meta_key,
					array(
						'single'        => true,
						'type'          => 'string',
						'show_in_rest'  => true,
						'description'   => sprintf(
							/* translators: %s: meta key */
							__( 'Auto-registered by Promptless CPT Pages for meta_match grouping: %s', 'promptless-cpt-pages' ),
							$meta_key
						),
						'auth_callback' => static function ( $allowed, $meta_key, $object_id ) {
							// Standard per-post permission check. Mirrors WP core's
							// default for registered meta on edit_post-gated CPTs.
							return current_user_can( 'edit_post', (int) $object_id );
						},
					)
				);

				/**
				 * Fires after a meta_match meta key is auto-registered.
				 *
				 * @param string $cpt_slug CPT the meta is attached to.
				 * @param string $meta_key The registered meta key.
				 */
				do_action( 'pcptpages_meta_match_key_registered', $cpt_slug, $meta_key );
			}
		}
	}

	/**
	 * Plugin activation handler.
	 *
	 * Idempotent. Sets up default options if not already present.
	 * Does NOT register CPTs here — that happens on init each request.
	 *
	 * Note: WordPress flushes rewrite rules automatically when register_post_type
	 * is called on init, so we don't flush here either. The first frontend
	 * request after activation will rebuild rules naturally.
	 */
	public function on_activation() {
		// Seed the data-version option so the upgrade handler knows the
		// install's starting point. Existing installs already have the
		// option set; only fresh installs hit this branch.
		if ( get_option( 'pcptpages_data_version' ) === false ) {
			add_option( 'pcptpages_data_version', PCPTPages_DATA_VERSION );
		}

		// Seed the CPT registry option as an empty array if not present.
		if ( get_option( 'pcptpages_cpts' ) === false ) {
			add_option( 'pcptpages_cpts', array() );
		}

		// Grant the scoped `pcptpages_manage_cpts` capability to the default roles
		// (administrator only by default). Idempotent — safe to call on
		// re-activation. The maybe_run_data_upgrade() handler also calls this
		// on every version bump so existing installs pick it up without a
		// manual deactivate/reactivate.
		PCPTPages_Capabilities::grant_default_capabilities();

		/**
		 * Fires after the plugin is activated.
		 */
		do_action( 'pcptpages_activated' );
	}

	/**
	 * Run lightweight data-version upgrade tasks when the stored version
	 * differs from PCPTPages_DATA_VERSION.
	 *
	 * Runs on `plugins_loaded` (priority 5) so option access is available but
	 * subsystems that depend on the upgrade having completed (admin pages,
	 * connector routes) initialize afterward.
	 *
	 * Each upgrade step is idempotent: callers may execute the same step
	 * twice without harm. The version comparison short-circuits when the
	 * stored version already matches, so steady-state cost is one
	 * get_option() call per request.
	 */
	public function maybe_run_data_upgrade() {
		$stored_version = get_option( 'pcptpages_data_version', '0.0.0' );

		if ( version_compare( $stored_version, PCPTPages_DATA_VERSION, '>=' ) ) {
			return;
		}

		// 0.2.0 — Grant the scoped `pcptpages_manage_cpts` capability. Existing
		// installs that previously relied on `manage_options` need this so
		// administrators retain access after the constant value changed.
		// Idempotent; safe to run on every upgrade boot.
		if ( version_compare( $stored_version, '0.2.0', '<' ) ) {
			PCPTPages_Capabilities::grant_default_capabilities();
		}

		// Persist the new version so subsequent requests skip the upgrade
		// branch entirely.
		update_option( 'pcptpages_data_version', PCPTPages_DATA_VERSION );

		/**
		 * Fires after a data-version upgrade completes.
		 *
		 * @param string $stored_version Previous stored version.
		 * @param string $new_version    Version we just upgraded to.
		 */
		do_action( 'pcptpages_data_version_upgraded', $stored_version, PCPTPages_DATA_VERSION );
	}

	/**
	 * Plugin deactivation handler.
	 *
	 * Idempotent. Does NOT delete any data — that's uninstall.php's job, and
	 * even uninstall preserves data by default. Deactivation is reversible.
	 */
	public function on_deactivation() {
		// Flush rewrite rules so removed CPTs don't leave stale URLs.
		flush_rewrite_rules();

		/**
		 * Fires after the plugin is deactivated.
		 */
		do_action( 'pcptpages_deactivated' );
	}
}

// Backward-compatibility alias. The main class was renamed from
// `Post_Runtime_Engine` to `Promptless_CPT_Pages` in 0.4.1 to comply
// with WordPress.org plugin guideline 11 (avoid common-word prefixes
// like "post"). Third-party code that referenced `Post_Runtime_Engine`
// directly — theme functions.php, smoke tests, integration test
// scaffolding — keeps working via this alias.
class_alias( 'Promptless_CPT_Pages', 'Post_Runtime_Engine' );

/**
 * Global accessor for the plugin instance.
 *
 * Mirrors the fre() / pw_fs() pattern used across the FlowMint stack.
 *
 * @return Promptless_CPT_Pages
 */
function pcptpages() {
	return Promptless_CPT_Pages::instance();
}

// Boot the plugin.
pcptpages();
