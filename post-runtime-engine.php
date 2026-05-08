<?php
/**
 * Plugin Name: Post Runtime Engine
 * Plugin URI: https://promptlesswp.com
 * Description: Render custom-post-type single pages with structured data display through Promptless's design system. Companion plugin to Promptless WP and Form Runtime Engine.
 * Version: 0.2.0
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: Breon Williams
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: post-runtime-engine
 * Domain Path: /languages
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin version. Bumped on every meaningful release.
define( 'PRE_VERSION', '0.2.0' );

// Minimum data-storage schema version. Bumped when option / post-meta shapes
// change in a way that requires migration. Triggers PRE_Upgrader (added in a
// later phase). For Phase 1 this is informational only.
define( 'PRE_DATA_VERSION', '0.1.0' );

// Plugin paths and URLs.
define( 'PRE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PRE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'PRE_PLUGIN_FILE', __FILE__ );

// REST namespace (used by the connector in Phase 3+).
define( 'PRE_REST_NAMESPACE', 'post-runtime/v1' );
define( 'PRE_REST_BASE', 'connector' );

// Autoloader. Loaded immediately so any code below can rely on PRE_* classes.
require_once PRE_PLUGIN_DIR . 'includes/class-pre-autoloader.php';
PRE_Autoloader::register();

/**
 * Main plugin class.
 *
 * Singleton. Owns lifecycle hooks, exposes the registries, and wires up the
 * init flow. Admin / frontend / connector subsystems hook themselves on later
 * actions and are not loaded here directly — the autoloader resolves them
 * lazily as classes are referenced.
 */
final class Post_Runtime_Engine {

	/**
	 * Singleton instance.
	 *
	 * @var Post_Runtime_Engine|null
	 */
	private static $instance = null;

	/**
	 * CPT registry instance. Populated on init().
	 *
	 * @var PRE_CPT_Registry|null
	 */
	public $cpts = null;

	/**
	 * Grouping registry instance. Populated on init().
	 *
	 * @var PRE_Grouping_Registry|null
	 */
	public $groupings = null;

	/**
	 * Post-data accessor instance. Populated on init().
	 *
	 * @var PRE_Post_Data|null
	 */
	public $post_data = null;

	/**
	 * Admin coordinator instance. Populated on init() when in wp-admin.
	 *
	 * @var PRE_Admin|null
	 */
	public $admin = null;

	/**
	 * Template router instance.
	 *
	 * @var PRE_Template_Router|null
	 */
	public $template_router = null;

	/**
	 * Frontend asset coordinator.
	 *
	 * @var PRE_Frontend_Assets|null
	 */
	public $frontend_assets = null;

	/**
	 * Connector REST controller. Populated on init() — needed in REST
	 * requests as well as admin and frontend, so always loaded.
	 *
	 * @var PRE_Connector_API|null
	 */
	public $connector_api = null;

	/**
	 * Connector admin page coordinator. Populated on init() in admin
	 * context only — the settings UI for enabling the connector and
	 * generating Application Passwords.
	 *
	 * @var PRE_Connector_Admin|null
	 */
	public $connector_admin = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Post_Runtime_Engine
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
		register_activation_hook( PRE_PLUGIN_FILE, array( $this, 'on_activation' ) );
		register_deactivation_hook( PRE_PLUGIN_FILE, array( $this, 'on_deactivation' ) );

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

		// Load the text domain for translations.
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
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
		throw new Exception( 'Cannot unserialize Post_Runtime_Engine singleton.' );
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
		$this->cpts      = new PRE_CPT_Registry();
		$this->groupings = new PRE_Grouping_Registry();
		$this->post_data = new PRE_Post_Data( $this->cpts, $this->groupings );

		// Admin module is only loaded inside wp-admin. Frontend / REST
		// requests don't need any of this.
		if ( is_admin() ) {
			$this->admin           = new PRE_Admin();
			$this->connector_admin = new PRE_Connector_Admin();
			$this->connector_admin->init();
		}

		// Frontend rendering — instantiated regardless of context because
		// REST requests can also produce post output (preview endpoint)
		// and admin previews need the renderer too.
		$this->template_router = new PRE_Template_Router();
		$this->frontend_assets = new PRE_Frontend_Assets();

		// Wire render-cache invalidation hooks. Static — no instance
		// state required. The renderer caches output per-post via a
		// transient and busts the cache on save_post / before_delete_post
		// / set_object_terms, plus on grouping-definition changes.
		PRE_Renderer::init_cache_invalidation();

		// Connector REST API — always loaded so authenticated agents
		// can call /wp-json/post-runtime/v1/connector/* from any
		// context. The auth gate inside each route ensures the
		// connector_enabled toggle is honored.
		$this->connector_api = new PRE_Connector_API();
		$this->connector_api->init();

		/**
		 * Fires after Post Runtime Engine has finished initializing its core
		 * registries.
		 *
		 * Other plugins can hook here to extend behavior. The plugin instance
		 * is passed so consumers can access the registries.
		 *
		 * @param Post_Runtime_Engine $plugin The plugin instance.
		 */
		do_action( 'pre_init', $this );
	}

	/**
	 * Register taxonomies persisted in the `pre_auto_taxonomies` option.
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
		$auto = get_option( 'pre_auto_taxonomies', array() );
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
	 * Reads from PRE_CPT_Registry and calls register_post_type() for each
	 * stored CPT definition. Runs on init (priority 5).
	 */
	public function register_post_types() {
		if ( ! $this->cpts ) {
			return;
		}

		$this->cpts->register_all_with_wp();
	}

	/**
	 * Load the plugin text domain for translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'post-runtime-engine',
			false,
			dirname( PRE_PLUGIN_BASENAME ) . '/languages'
		);
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
		// Seed the data-version option so PRE_Upgrader (added later) knows
		// the install's starting point.
		if ( get_option( 'pre_data_version' ) === false ) {
			add_option( 'pre_data_version', PRE_DATA_VERSION );
		}

		// Seed the CPT registry option as an empty array if not present.
		if ( get_option( 'pre_cpts' ) === false ) {
			add_option( 'pre_cpts', array() );
		}

		/**
		 * Fires after the plugin is activated.
		 */
		do_action( 'pre_activated' );
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
		do_action( 'pre_deactivated' );
	}
}

/**
 * Global accessor for the plugin instance.
 *
 * Mirrors the fre() / pw_fs() pattern used across the FlowMint stack.
 *
 * @return Post_Runtime_Engine
 */
function pre() {
	return Post_Runtime_Engine::instance();
}

// Boot the plugin.
pre();
