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
		'PRE_Validator'           => 'Core/class-pre-validator.php',
		'PRE_Icon_Library'        => 'Core/class-pre-icon-library.php',
		'PRE_CPT_Registry'        => 'Core/class-pre-cpt-registry.php',
		'PRE_Grouping_Registry'   => 'Core/class-pre-grouping-registry.php',
		'PRE_Post_Field_Registry' => 'Core/class-pre-post-field-registry.php',
		'PRE_Post_Data'           => 'Core/class-pre-post-data.php',
		'PRE_Capabilities'        => 'Core/class-pre-capabilities.php',

		// Phase 2 frontend rendering.
		'PRE_Template_Router' => 'Core/class-pre-template-router.php',
		'PRE_Source_Resolver' => 'Core/class-pre-source-resolver.php',
		'PRE_Renderer'        => 'Frontend/class-pre-renderer.php',
		'PRE_Frontend_Assets' => 'Frontend/class-pre-frontend-assets.php',

		// Phase 9 (v1.1) post-field rendering.
		'PRE_Card_Renderer'      => 'Frontend/class-pre-card-renderer.php',

		// Phase 12 (v1.1) AISB PostGrid + theme archive integration.
		'PRE_Card_Filter_Hooks'  => 'Frontend/class-pre-card-filter-hooks.php',

		// Phase 3: Connector REST + admin.
		// One API class owns all 18 routes (FRE pattern); auth + settings
		// + admin are split out for testability.
		'PRE_Connector_API'      => 'Connector/class-pre-connector-api.php',
		'PRE_Connector_Auth'     => 'Connector/class-pre-connector-auth.php',
		'PRE_Connector_Settings' => 'Connector/class-pre-connector-settings.php',
		'PRE_Connector_Admin'    => 'Connector/class-pre-connector-admin.php',

		// Phase 1 admin UI.
		'PRE_Admin'                  => 'Admin/class-pre-admin.php',
		'PRE_Admin_CPTs'             => 'Admin/class-pre-admin-cpts.php',
		'PRE_Admin_Groupings'        => 'Admin/class-pre-admin-groupings.php',
		'PRE_Admin_Post_Fields'      => 'Admin/class-pre-admin-post-fields.php',
		'PRE_Meta_Box'               => 'Admin/class-pre-meta-box.php',
		'PRE_Meta_Box_Post_Fields'   => 'Admin/class-pre-meta-box-post-fields.php',
		// Pending in next pass:
		// 'PRE_Settings'        => 'Admin/class-pre-settings.php',

		// GitHub auto-updater. Loaded only inside wp-admin (see main
		// plugin file) so frontend requests don't incur its overhead.
		'PRE_GitHub_Updater'         => 'Updates/class-pre-github-updater.php',
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
