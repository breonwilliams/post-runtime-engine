<?php
/**
 * Deleting the plugin keeps definitions and values unless the owner opted in.
 *
 * Up to 0.10.0 uninstall.php ALWAYS deleted the record-type and grouping
 * definitions — the only copy of what makes the records render, the loss the
 * connector's delete_cpt stopped causing in 0.8.0 — while keeping post-field
 * definitions, and its opt-in removed grouping values but left every field
 * value behind. Housekeeping now always goes; data only with consent.
 *
 * Runs the real uninstall.php against a recording $wpdb, one process per test.
 *
 * @package PostRuntimeEngine\Tests\Unit
 */

namespace PRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UninstallTest extends UnitTestCase {

	/** @var string[] */
	private $deleted_options = [];

	/** @var array */
	private $settings = [];

	private function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'post-runtime-engine/post-runtime-engine.php' );
		}
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', dirname( PRE_TEST_PLUGIN_DIR ) );
		}
		$db = new class {
			public $options  = 'wp_options';
			public $postmeta = 'wp_postmeta';
			public $queries  = [];
			public function query( $sql ) { $this->queries[] = preg_replace( '/\s+/', ' ', trim( $sql ) ); return 1; }
			public function prepare( $sql, ...$args ) { return vsprintf( str_replace( '%s', "'%s'", $sql ), $args ); }
			public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
		};
		$GLOBALS['wpdb'] = $db;

		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			return 'pcptpages_settings' === $name ? $this->settings : $default;
		} );
		Functions\when( 'delete_option' )->alias( function ( $name ) { $this->deleted_options[] = $name; return true; } );
		Functions\when( 'wp_roles' )->justReturn( null );

		include PRE_TEST_PLUGIN_DIR . 'uninstall.php';
		return implode( "\n", $db->queries );
	}

	public function test_by_default_definitions_and_values_are_kept() {
		$sql = $this->run_uninstall();

		$this->assertStringNotContainsString( "LIKE 'pcptpages\\_%'", $sql, 'no definition or option sweep' );
		$this->assertStringNotContainsString( 'wp_postmeta', $sql, 'no record value is touched' );
		$this->assertNotContains( 'pcptpages_cpts', $this->deleted_options, 'type definitions are the only copy — kept' );

		// Housekeeping still happens.
		$this->assertStringContainsString( '_transient\\_pcptpages\\_', $sql );
		$this->assertStringContainsString( 'pcptpages\\_gchanged\\_', $sql );
		$this->assertContains( 'pcptpages_connector_enabled', $this->deleted_options );
		$this->assertContains( 'pcptpages_ics_feed_rules', $this->deleted_options );
	}

	public function test_the_settings_opt_in_removes_everything_but_posts() {
		$this->settings = [ 'delete_data_on_uninstall' => true ];
		$sql            = $this->run_uninstall();

		$this->assertStringContainsString( "DELETE FROM wp_options WHERE option_name LIKE 'pcptpages\\_%'", $sql );
		$this->assertStringContainsString( "DELETE FROM wp_postmeta WHERE meta_key LIKE '\\_pcptpages\\_%'", $sql, 'field values too, not just groupings' );
		$this->assertStringNotContainsString( 'wp_posts', $sql, 'records themselves are never deleted' );
	}

	public function test_the_constant_is_consent_too() {
		define( 'PCPTPAGES_REMOVE_ALL_DATA', true );
		$sql = $this->run_uninstall();
		$this->assertStringContainsString( "LIKE '\\_pcptpages\\_%'", $sql );
	}
}
