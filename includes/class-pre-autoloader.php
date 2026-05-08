<?php
/**
 * Autoloader for Post Runtime Engine classes.
 *
 * Static class-map autoloader. Mirrors the FRE_Autoloader pattern used in
 * Form Runtime Engine — each PRE_* class name is mapped explicitly to its
 * file path under includes/. New classes added in later phases must be
 * added to the $class_map below.
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static autoloader for PRE_* classes.
 */
class PRE_Autoloader {

	/**
	 * Class-name → file-path map. Paths are relative to includes/.
	 *
	 * Phase 1 only registers the data-layer classes. Phase 2+ classes
	 * (frontend, admin, connector, etc.) are added incrementally as those
	 * phases are built.
	 *
	 * @var array<string,string>
	 */
	private static $class_map = array(
		// Core data layer.
		'PRE_Validator'         => 'Core/class-pre-validator.php',
		'PRE_Icon_Library'      => 'Core/class-pre-icon-library.php',
		'PRE_CPT_Registry'      => 'Core/class-pre-cpt-registry.php',
		'PRE_Grouping_Registry' => 'Core/class-pre-grouping-registry.php',
		'PRE_Post_Data'         => 'Core/class-pre-post-data.php',
		'PRE_Capabilities'      => 'Core/class-pre-capabilities.php',

		// Phase 2 frontend rendering.
		'PRE_Template_Router' => 'Core/class-pre-template-router.php',
		'PRE_Source_Resolver' => 'Core/class-pre-source-resolver.php',
		'PRE_Renderer'        => 'Frontend/class-pre-renderer.php',
		'PRE_Frontend_Assets' => 'Frontend/class-pre-frontend-assets.php',

		// Phase 3: Connector + MCP.
		// 'PRE_Connector_API'       => 'Connector/class-pre-connector-api.php',
		// 'PRE_Connector_Auth'      => 'Connector/class-pre-connector-auth.php',
		// 'PRE_Connector_CPTs'      => 'Connector/class-pre-connector-cpts.php',
		// 'PRE_Connector_Groupings' => 'Connector/class-pre-connector-groupings.php',
		// 'PRE_Connector_Posts'     => 'Connector/class-pre-connector-posts.php',
		// 'PRE_Connector_Preview'   => 'Connector/class-pre-connector-preview.php',
		// 'PRE_Connector_Preflight' => 'Connector/class-pre-connector-preflight.php',
		// 'PRE_MCP_Tools'           => 'Mcp/class-pre-mcp-tools.php',

		// Phase 1 admin UI.
		'PRE_Admin'           => 'Admin/class-pre-admin.php',
		'PRE_Admin_CPTs'      => 'Admin/class-pre-admin-cpts.php',
		'PRE_Admin_Groupings' => 'Admin/class-pre-admin-groupings.php',
		'PRE_Meta_Box'        => 'Admin/class-pre-meta-box.php',
		// Pending in next pass:
		// 'PRE_Settings'        => 'Admin/class-pre-settings.php',
	);

	/**
	 * Register the autoloader with PHP.
	 *
	 * Called from post-runtime-engine.php immediately after this file is
	 * required.
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Resolve and require a class file when PHP requests an unknown class.
	 *
	 * @param string $class_name Class being autoloaded.
	 */
	public static function autoload( $class_name ) {
		if ( ! isset( self::$class_map[ $class_name ] ) ) {
			return;
		}

		$file = PRE_PLUGIN_DIR . 'includes/' . self::$class_map[ $class_name ];

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Get the full class map. Useful for debugging and for tests.
	 *
	 * @return array<string,string>
	 */
	public static function get_class_map() {
		return self::$class_map;
	}
}
