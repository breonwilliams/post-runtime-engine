<?php
/**
 * Which writes invalidate cached record pages.
 *
 * Found writing the documentation (2026-09-19): the render cache was only
 * invalidated by save_post on the one post, and by definition changes. The
 * connector's field-value, grouping and visibility writes are post-meta
 * writes that fire no save_post, post-field definition changes were not
 * watched, and a page that lists OTHER records (related footer, child
 * posts, a reverse meta_match) never noticed those records change — so
 * logged-out visitors saw the old page for up to an hour.
 *
 * @package PostRuntimeEngine\Tests\Unit
 */

namespace PRE\Tests\Unit;

use Brain\Monkey\Functions;

class RenderCacheInvalidationTest extends UnitTestCase {

	/** @var array<string,mixed> the options table */
	private $opts = array();

	protected function set_up() {
		parent::set_up();
		require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-grouping-registry.php';
		require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-post-field-registry.php';
		require_once PRE_TEST_PLUGIN_DIR . 'includes/Frontend/class-pre-renderer.php';

		$cpts = new class {
			public function exists( $slug ) {
				return in_array( $slug, array( 'meeting', 'department' ), true );
			}
		};
		$plugin       = new \stdClass();
		$plugin->cpts = $cpts;
		Functions\when( 'pcptpages' )->justReturn( $plugin );

		$types = array( 10 => 'meeting', 20 => 'page', 30 => 'revision', 40 => 'post' );
		Functions\when( 'get_post_type' )->alias( function ( $id ) use ( $types ) {
			return $types[ $id ] ?? false;
		} );
		Functions\when( 'wp_is_post_revision' )->alias( function ( $id ) {
			return $id === 30 ? 10 : false;
		} );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			return array_key_exists( $name, $this->opts ) ? $this->opts[ $name ] : $default;
		} );
		Functions\when( 'update_option' )->alias( function ( $name, $value ) {
			$this->opts[ $name ] = $value;
			return true;
		} );
	}

	private function marker( $type ) {
		return $this->opts[ \PCPTPages_Renderer::CHANGED_OPTION_PREFIX . $type ] ?? null;
	}

	public function test_a_field_value_write_invalidates_the_records_type() {
		\PCPTPages_Renderer::record_meta_changed( 1, 10, '_pcptpages_field_room' );
		$this->assertNotNull( $this->marker( 'meeting' ) );
	}

	public function test_a_featured_image_change_invalidates() {
		\PCPTPages_Renderer::record_meta_changed( 1, 10, '_thumbnail_id' );
		$this->assertNotNull( $this->marker( 'meeting' ) );
	}

	public function test_bookkeeping_meta_does_not_invalidate() {
		foreach ( array( '_edit_lock', '_edit_last', '_pcptpages_external_synced_at', '_pcptpages_groupings_backup' ) as $key ) {
			\PCPTPages_Renderer::record_meta_changed( 1, 10, $key );
		}
		$this->assertNull( $this->marker( 'meeting' ), 'the heartbeat rewrites _edit_lock while a record is open' );
	}

	public function test_an_unwatched_type_writes_no_marker() {
		\PCPTPages_Renderer::record_changed( 20 );
		$this->assertArrayNotHasKey( \PCPTPages_Renderer::CHANGED_OPTION_PREFIX . 'page', $this->opts );
	}

	public function test_a_type_some_render_depends_on_is_watched() {
		$this->opts[ \PCPTPages_Renderer::CHANGED_OPTION_PREFIX . 'post' ] = 100;
		\PCPTPages_Renderer::record_changed( 40 );
		$this->assertNotSame( 100, $this->marker( 'post' ) );
	}

	public function test_revisions_are_ignored() {
		\PCPTPages_Renderer::record_changed( 30 );
		$this->assertSame( array(), $this->opts );
	}

	public function test_a_post_field_definition_change_invalidates_its_type() {
		\PCPTPages_Renderer::maybe_bump_groupings_changed( \PCPTPages_Post_Field_Registry::OPTION_PREFIX . 'meeting' );
		$this->assertNotNull( $this->marker( 'meeting' ) );
	}

	public function test_a_bump_always_changes_the_marker() {
		// Same second as the render that cached the page: the marker must
		// still move, or that page stays valid (measured on Local).
		$future = time() + 100;
		$this->assertSame( $future + 1, \PCPTPages_Renderer::next_marker( $future ) );
		$this->assertSame( time() + 1, \PCPTPages_Renderer::next_marker( time() ) );
		$this->assertGreaterThanOrEqual( time(), \PCPTPages_Renderer::next_marker( 5 ) );

		$this->opts[ \PCPTPages_Renderer::CHANGED_OPTION_PREFIX . 'meeting' ] = time();
		$before = $this->marker( 'meeting' );
		\PCPTPages_Renderer::record_changed( 10 );
		\PCPTPages_Renderer::record_changed( 10 );
		$this->assertSame( $before + 2, $this->marker( 'meeting' ) );
	}

	public function test_dependencies_are_only_noted_while_a_render_captures() {
		// Outside a render there is nowhere to record it; must not fatal.
		\PCPTPages_Renderer::note_dependency( 'meeting' );
		$this->assertTrue( true );
	}

	public function test_a_reverse_lookup_registers_its_dependency() {
		$src = file_get_contents( PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-source-resolver.php' );
		$this->assertMatchesRegularExpression( '/PCPTPages_Renderer::note_dependency\(\s*\$target_post_type\s*\)/', $src );
	}
}
