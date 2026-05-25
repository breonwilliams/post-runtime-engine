<?php
/**
 * Admin coordinator for Post Runtime Engine.
 *
 * Owns the top-level admin menu, sub-page registration, asset enqueuing,
 * and dispatches to the focused admin classes (PRE_Admin_CPTs, etc.) for
 * each page. Loaded on admin_init by the bootstrap.
 *
 * The admin UI is gated behind the `manage_options` capability via
 * PRE_Capabilities — the plugin is intended for administrators only in
 * v1.0. v1.1+ may introduce a delegated role for content editors who can
 * only fill in groupings without managing CPT registrations.
 *
 * @package PostRuntimeEngine
 *
 * phpcs:disable WordPress.Security.NonceVerification.Missing
 * phpcs:disable WordPress.Security.NonceVerification.Recommended
 *
 * Justification: This coordinator dispatches to focused admin pages
 * (PRE_Admin_CPTs, PRE_Admin_Groupings, etc.). Page selection from
 * $_GET['page'] is a read-only navigation check, not a state-changing
 * action — no nonce required per WordPress core admin conventions
 * (same pattern used by WP_List_Table filter parameters). Each page's
 * actual save/delete handlers verify nonces via check_admin_referer().
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin coordinator.
 */
class PRE_Admin {

	/**
	 * Top-level menu slug. Used as the parent for all sub-pages.
	 */
	const MENU_SLUG = 'post-runtime-engine';

	/**
	 * Sub-page slugs.
	 */
	const PAGE_CPTS        = 'post-runtime-engine';
	const PAGE_GROUPINGS   = 'pre-groupings';
	const PAGE_POST_FIELDS = 'pre-post-fields';
	const PAGE_SETTINGS    = 'pre-settings';

	/**
	 * CPT management page renderer.
	 *
	 * @var PRE_Admin_CPTs|null
	 */
	private $cpts_page = null;

	/**
	 * Grouping management page renderer.
	 *
	 * @var PRE_Admin_Groupings|null
	 */
	private $groupings_page = null;

	/**
	 * Post field management page renderer (v1.1).
	 *
	 * @var PRE_Admin_Post_Fields|null
	 */
	private $post_fields_page = null;

	/**
	 * Post-edit-screen meta box.
	 *
	 * @var PRE_Meta_Box|null
	 */
	private $meta_box = null;

	/**
	 * Constructor. Registers WordPress hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );

		// Meta box self-registers its own hooks (add_meta_boxes, save_post,
		// admin_enqueue_scripts) so we instantiate it eagerly.
		$this->meta_box = new PRE_Meta_Box();

		// v1.1 post-fields meta box. Same self-registering pattern; only
		// renders on CPTs that have at least one post field defined.
		new PRE_Meta_Box_Post_Fields();
	}

	/**
	 * Register the top-level admin menu and its sub-pages.
	 *
	 * Called on `admin_menu`. The CPT list IS the parent menu's default
	 * page (clicking the parent menu opens it). Other sub-pages branch
	 * from there.
	 */
	public function register_menu() {
		if ( ! PRE_Capabilities::current_user_can_manage() ) {
			return;
		}

		$cap = apply_filters( 'pre_manage_capability', PRE_Capabilities::MANAGE_CAP );

		add_menu_page(
			__( 'Post Runtime Engine', 'post-runtime-engine' ),
			__( 'Post Runtime', 'post-runtime-engine' ),
			$cap,
			self::MENU_SLUG,
			array( $this, 'render_cpts_page' ),
			'dashicons-screenoptions',
			30
		);

		// "Post Types" subpage (same callback as parent — WordPress convention
		// for matching the parent's content with a friendlier sub-label).
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Post Types', 'post-runtime-engine' ),
			__( 'Post Types', 'post-runtime-engine' ),
			$cap,
			self::PAGE_CPTS,
			array( $this, 'render_cpts_page' )
		);

		// "Groupings" subpage. Hidden from the menu by setting the parent
		// slug to null — it's only reachable via deep links from the CPT
		// list. Showing it as a top-level submenu without a CPT context
		// would be confusing.
		add_submenu_page(
			null, // hidden.
			__( 'Manage Groupings', 'post-runtime-engine' ),
			__( 'Manage Groupings', 'post-runtime-engine' ),
			$cap,
			self::PAGE_GROUPINGS,
			array( $this, 'render_groupings_page' )
		);

		// "Post Fields" subpage (v1.1). Same hidden-by-design pattern as
		// Groupings — only reachable via deep links from the CPT list,
		// because both pages require a CPT context to be meaningful.
		add_submenu_page(
			null, // hidden.
			__( 'Manage Post Fields', 'post-runtime-engine' ),
			__( 'Manage Post Fields', 'post-runtime-engine' ),
			$cap,
			self::PAGE_POST_FIELDS,
			array( $this, 'render_post_fields_page' )
		);
	}

	/**
	 * Enqueue admin CSS / JS only on PRE admin pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// Match any of our admin page hooks. WordPress prefixes the parent
		// menu slug with "toplevel_page_" and submenus with the parent slug.
		$is_pre_page = (
			strpos( $hook, self::MENU_SLUG ) !== false
			|| strpos( $hook, 'pre-' ) !== false
			|| $hook === 'toplevel_page_' . self::MENU_SLUG
		);

		if ( ! $is_pre_page ) {
			return;
		}

		wp_enqueue_style(
			'pre-admin',
			PRE_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			PRE_VERSION
		);

		// v1.1: conditional field display + drag reorder on the Post
		// Fields admin page. Loaded only on that page to keep the rest
		// of the admin lean.
		$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( $current_page === self::PAGE_POST_FIELDS ) {
			wp_enqueue_script(
				'pre-post-fields-editor',
				PRE_PLUGIN_URL . 'assets/js/post-fields-editor.js',
				array( 'jquery', 'jquery-ui-sortable' ),
				PRE_VERSION,
				true
			);
		}
	}

	/**
	 * Handle form submissions and other admin actions before any output.
	 *
	 * Runs on `admin_init` priority 10. Each page's handler is responsible
	 * for nonce verification and capability checks.
	 */
	public function handle_actions() {
		if ( ! is_admin() ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( $page === self::PAGE_CPTS ) {
			$this->get_cpts_page()->handle_action();
		} elseif ( $page === self::PAGE_GROUPINGS ) {
			$this->get_groupings_page()->handle_action();
		} elseif ( $page === self::PAGE_POST_FIELDS ) {
			$this->get_post_fields_page()->handle_action();
		}
	}

	/**
	 * Render the CPT management page.
	 */
	public function render_cpts_page() {
		if ( ! PRE_Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'post-runtime-engine' ) );
		}

		$this->get_cpts_page()->render();
	}

	/**
	 * Render the per-CPT groupings management page.
	 */
	public function render_groupings_page() {
		if ( ! PRE_Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'post-runtime-engine' ) );
		}

		$page = $this->get_groupings_page();

		// Render any queued notice (success/error from POST-redirect-GET).
		// We do this in a wrap-prefix so the notice appears before the page's
		// own H1.
		echo '<div class="pre-admin-notice-host">';
		$page->render_notice();
		echo '</div>';

		$page->render();
	}

	/**
	 * Render the per-CPT post-fields management page (v1.1).
	 */
	public function render_post_fields_page() {
		if ( ! PRE_Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'post-runtime-engine' ) );
		}

		$page = $this->get_post_fields_page();

		echo '<div class="pre-admin-notice-host">';
		$page->render_notice();
		echo '</div>';

		$page->render();
	}

	/**
	 * Lazily instantiate and return the CPTs page handler.
	 *
	 * @return PRE_Admin_CPTs
	 */
	private function get_cpts_page() {
		if ( $this->cpts_page === null ) {
			$this->cpts_page = new PRE_Admin_CPTs();
		}
		return $this->cpts_page;
	}

	/**
	 * Lazily instantiate and return the Groupings page handler.
	 *
	 * @return PRE_Admin_Groupings
	 */
	private function get_groupings_page() {
		if ( $this->groupings_page === null ) {
			$this->groupings_page = new PRE_Admin_Groupings();
		}
		return $this->groupings_page;
	}

	/**
	 * Lazily instantiate and return the Post Fields page handler.
	 *
	 * @return PRE_Admin_Post_Fields
	 */
	private function get_post_fields_page() {
		if ( $this->post_fields_page === null ) {
			$this->post_fields_page = new PRE_Admin_Post_Fields();
		}
		return $this->post_fields_page;
	}
}
