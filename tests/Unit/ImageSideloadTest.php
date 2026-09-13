<?php
/**
 * featured_image_url — the pure halves of the sideload path.
 *
 * The download itself is core (`download_url()` + `media_handle_sideload()`)
 * and is exercised by tests/smoke-external-upsert.php on a site. What can be
 * pinned without WordPress: which URLs are accepted, and what filename a
 * URL is saved under — the case that matters being a feed URL with no
 * extension (`/photo?id=9`), which core would refuse without one.
 *
 * @package PostRuntimeEngine\Tests\Unit
 */

namespace PRE\Tests\Unit;

use Brain\Monkey\Functions;
use PCPTPages_Post_Data;

class ImageSideloadTest extends UnitTestCase {

	protected function set_up() {
		parent::set_up();
		require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-validator.php';
		require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-cpt-registry.php';
		require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-grouping-registry.php';
		require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-post-data.php';
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			return preg_replace( '/[^A-Za-z0-9._-]+/', '-', $name );
		} );
	}

	public function test_only_http_and_https_urls_are_accepted() {
		$this->assertSame( 'https://cdn.example.org/p/1.jpg', PCPTPages_Post_Data::normalize_image_url( '  https://cdn.example.org/p/1.jpg ' ) );
		$this->assertSame( 'http://cdn.example.org/p/1.jpg', PCPTPages_Post_Data::normalize_image_url( 'http://cdn.example.org/p/1.jpg' ) );
		$this->assertSame( '', PCPTPages_Post_Data::normalize_image_url( '' ) );
		$this->assertSame( '', PCPTPages_Post_Data::normalize_image_url( 'ftp://cdn.example.org/p/1.jpg' ) );
		$this->assertSame( '', PCPTPages_Post_Data::normalize_image_url( 'data:image/png;base64,AAAA' ) );
		$this->assertSame( '', PCPTPages_Post_Data::normalize_image_url( '/uploads/local.jpg' ), 'a relative path is not a URL to fetch' );
	}

	public function test_filename_keeps_the_urls_own_image_extension() {
		$this->assertSame( 'summer-camp.jpg', PCPTPages_Post_Data::filename_for_image_url( 'https://cdn.example.org/photos/summer-camp.jpg?w=1200' ) );
		$this->assertSame( 'hero.webp', PCPTPages_Post_Data::filename_for_image_url( 'https://cdn.example.org/a/hero.WEBP', 'image/jpeg' ), 'the URL extension wins over the detected type' );
		$this->assertSame( 'photo.jpeg', PCPTPages_Post_Data::filename_for_image_url( 'https://cdn.example.org/photo.jpeg' ) );
	}

	public function test_filename_takes_the_extension_from_the_detected_mime_when_the_url_has_none() {
		$this->assertSame( 'photo.jpg', PCPTPages_Post_Data::filename_for_image_url( 'https://api.example.org/photo?id=9', 'image/jpeg' ) );
		$this->assertSame( '9.png', PCPTPages_Post_Data::filename_for_image_url( 'https://api.example.org/media/9', 'image/png' ) );
		$this->assertSame( 'download.webp', PCPTPages_Post_Data::filename_for_image_url( 'https://api.example.org/download.php', 'image/webp' ), 'a non-image extension is replaced' );
	}

	public function test_filename_is_derived_from_the_url_when_the_path_has_no_basename() {
		$name = PCPTPages_Post_Data::filename_for_image_url( 'https://api.example.org/', 'image/jpeg' );
		$this->assertMatchesRegularExpression( '/^image-[0-9a-f]{8}\.jpg$/', $name );
		$this->assertSame( $name, PCPTPages_Post_Data::filename_for_image_url( 'https://api.example.org/', 'image/jpeg' ), 'deterministic for the same URL' );
	}

	public function test_no_image_extension_and_no_image_mime_gives_nothing() {
		$this->assertSame( '', PCPTPages_Post_Data::filename_for_image_url( 'https://api.example.org/photo?id=9' ) );
		$this->assertSame( '', PCPTPages_Post_Data::filename_for_image_url( 'https://api.example.org/photo?id=9', 'text/html' ) );
		$this->assertSame( '', PCPTPages_Post_Data::filename_for_image_url( 'https://api.example.org/report.pdf', 'application/pdf' ) );
	}
}
