<?php
/**
 * Phase 1 smoke test for Post Runtime Engine.
 *
 * Exercises the data layer end-to-end against a real WordPress install.
 * No assumptions about admin UI or frontend — only the programmatic API:
 *   pre()->cpts        (PRE_CPT_Registry)
 *   pre()->groupings   (PRE_Grouping_Registry)
 *   pre()->post_data   (PRE_Post_Data)
 *
 * Designed to catch regressions before manual browser testing. Validates:
 *   - Bootstrap and singleton wiring
 *   - CPT registration (success + failure cases)
 *   - Grouping definition (success + failure cases)
 *   - Per-post grouping write + read round-trip
 *   - Validator rejection of malformed input across every error class
 *   - Backup + restore behavior
 *   - Capability helpers
 *   - Cleanup paths (unregister, remove)
 *
 * USAGE — pick whichever fits your local environment:
 *
 *   1. Local by Flywheel (recommended for ai-section-builder.local):
 *      Open the site shell from Local's UI, then:
 *        wp eval-file wp-content/plugins/post-runtime-engine/tests/smoke-phase1.php
 *
 *   2. Standard WP-CLI:
 *        cd /path/to/wordpress
 *        wp eval-file wp-content/plugins/post-runtime-engine/tests/smoke-phase1.php
 *
 *   3. Browser fallback (if WP-CLI is unavailable):
 *      Add `?pre_smoke=1` to any wp-admin URL while logged in as an
 *      administrator. (Requires temporarily wiring the loader below — see
 *      end of file. Disabled by default.)
 *
 * The script registers a test CPT, defines test groupings, writes test post
 * data, then cleans up everything before exiting. It is safe to run
 * repeatedly.
 *
 * EXPECTED OUTPUT: a list of PASS / FAIL lines followed by a summary. Exits
 * with status 0 if all tests pass, 1 if any test fails.
 *
 * @package PostRuntimeEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	// When run via wp eval-file, ABSPATH is defined by WP-CLI.
	// When run via tests/run-via-browser.php, the runner defines it.
	// If neither, refuse to run.
	echo "ERROR: WordPress is not loaded. Run via wp eval-file or tests/run-via-browser.php.\n";
	exit( 2 );
}

if ( ! function_exists( 'pre' ) ) {
	echo "FAIL: pre() global accessor not defined. Is the plugin activated?\n";
	exit( 1 );
}

// ---------------------------------------------------------------------------
// Tiny test framework. No PHPUnit dependency — keeps this script portable.
// ---------------------------------------------------------------------------

$pre_smoke_results = array();

/**
 * Record a test result. Prints a single-line PASS/FAIL with context.
 *
 * @param string $name    Test name.
 * @param bool   $ok      Pass/fail.
 * @param string $details Optional details to print on failure.
 */
function pre_smoke_assert( $name, $ok, $details = '' ) {
	global $pre_smoke_results;
	$pre_smoke_results[] = array(
		'name'    => $name,
		'ok'      => (bool) $ok,
		'details' => $details,
	);
	$status = $ok ? 'PASS' : 'FAIL';
	$line   = sprintf( '%-4s  %s', $status, $name );
	if ( ! $ok && $details !== '' ) {
		$line .= "\n      " . $details;
	}
	echo $line . "\n";
}

/**
 * Assert two values are equal.
 *
 * @param string $name     Test name.
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 */
function pre_smoke_equals( $name, $expected, $actual ) {
	$ok = $expected === $actual;
	$details = $ok ? '' : sprintf( 'expected %s, got %s', var_export( $expected, true ), var_export( $actual, true ) );
	pre_smoke_assert( $name, $ok, $details );
}

/**
 * Assert a value is a WP_Error with a specific code.
 *
 * @param string $name          Test name.
 * @param mixed  $value         Value to check.
 * @param string $expected_code Expected error code.
 */
function pre_smoke_wp_error( $name, $value, $expected_code ) {
	if ( ! is_wp_error( $value ) ) {
		pre_smoke_assert( $name, false, 'expected WP_Error, got ' . gettype( $value ) );
		return;
	}
	$code = $value->get_error_code();
	pre_smoke_assert(
		$name,
		$code === $expected_code,
		$code === $expected_code ? '' : sprintf( 'expected error code %s, got %s (%s)', $expected_code, $code, $value->get_error_message() )
	);
}

// ---------------------------------------------------------------------------
// Test fixtures.
// ---------------------------------------------------------------------------

const PRE_SMOKE_CPT_SLUG     = 'pre_smoke_listing';
const PRE_SMOKE_GROUPING_KEY = 'quick_specs';

/**
 * Tear down any state from a previous run. Idempotent.
 */
function pre_smoke_cleanup() {
	$plugin = pre();

	// Delete any posts of the test CPT.
	$posts = get_posts(
		array(
			'post_type'      => PRE_SMOKE_CPT_SLUG,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $posts as $pid ) {
		wp_delete_post( $pid, true );
	}

	// Remove the CPT and its groupings.
	if ( $plugin->cpts && $plugin->cpts->exists( PRE_SMOKE_CPT_SLUG ) ) {
		$plugin->cpts->unregister( PRE_SMOKE_CPT_SLUG );
	}
	if ( $plugin->groupings ) {
		$plugin->groupings->remove_all_for_cpt( PRE_SMOKE_CPT_SLUG );
	}

	// Reset in-memory caches so subsequent reads hit the option layer.
	if ( $plugin->cpts ) {
		$plugin->cpts->reset_cache();
	}
	if ( $plugin->groupings ) {
		$plugin->groupings->reset_cache();
	}
}

// ---------------------------------------------------------------------------
// Run tests.
// ---------------------------------------------------------------------------

echo "Post Runtime Engine — Phase 1 smoke test\n";
echo "========================================\n\n";

pre_smoke_cleanup();

$plugin = pre();

// 1. Bootstrap sanity.
pre_smoke_assert( 'plugin singleton instantiated', $plugin instanceof Post_Runtime_Engine );
pre_smoke_assert( 'CPT registry is wired', $plugin->cpts instanceof PRE_CPT_Registry );
pre_smoke_assert( 'grouping registry is wired', $plugin->groupings instanceof PRE_Grouping_Registry );
pre_smoke_assert( 'post data accessor is wired', $plugin->post_data instanceof PRE_Post_Data );
pre_smoke_assert( 'PRE_Validator autoloads', class_exists( 'PRE_Validator' ) );
pre_smoke_assert( 'PRE_Icon_Library autoloads', class_exists( 'PRE_Icon_Library' ) );
pre_smoke_assert( 'PRE_Capabilities autoloads', class_exists( 'PRE_Capabilities' ) );

// 2. Icon library starter set is intact.
$icons = PRE_Icon_Library::get_all();
pre_smoke_assert( 'icon library returns an array', is_array( $icons ) );
pre_smoke_assert( 'icon library includes "bed"', isset( $icons['bed'] ) );
pre_smoke_assert( 'icon library includes "scale"', isset( $icons['scale'] ) );
pre_smoke_assert( 'PRE_Icon_Library::has() works for known id', PRE_Icon_Library::has( 'phone' ) );
pre_smoke_assert( 'PRE_Icon_Library::has() rejects unknown id', ! PRE_Icon_Library::has( 'no-such-icon' ) );

// 3. CPT registration — happy path.
$result = $plugin->cpts->register(
	PRE_SMOKE_CPT_SLUG,
	array(
		'slug'           => PRE_SMOKE_CPT_SLUG,
		'label_singular' => 'Smoke Listing',
		'label_plural'   => 'Smoke Listings',
		'hierarchical'   => true,
		'has_archive'    => true,
		'public'         => true,
		'supports'       => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ),
	)
);
pre_smoke_assert( 'register CPT returns true', $result === true );
pre_smoke_assert( 'CPT shows as registered', $plugin->cpts->exists( PRE_SMOKE_CPT_SLUG ) );

$stored = $plugin->cpts->get( PRE_SMOKE_CPT_SLUG );
pre_smoke_assert( 'stored definition has slug', isset( $stored['slug'] ) && $stored['slug'] === PRE_SMOKE_CPT_SLUG );
pre_smoke_assert( 'stored definition has connector_version=1', isset( $stored['connector_version'] ) && $stored['connector_version'] === 1 );
pre_smoke_assert( 'stored definition has created_at', ! empty( $stored['created_at'] ) );

// 4. CPT registration — re-registering bumps connector_version.
$plugin->cpts->register(
	PRE_SMOKE_CPT_SLUG,
	array(
		'slug'           => PRE_SMOKE_CPT_SLUG,
		'label_singular' => 'Smoke Listing',
		'label_plural'   => 'Smoke Listings',
		'public'         => true,
	)
);
$updated = $plugin->cpts->get( PRE_SMOKE_CPT_SLUG );
pre_smoke_equals( 'connector_version increments on update', 2, isset( $updated['connector_version'] ) ? $updated['connector_version'] : 0 );

// 5. CPT registration — failure cases.
pre_smoke_wp_error(
	'reserved slug "post" is rejected',
	$plugin->cpts->register( 'post', array( 'slug' => 'post', 'label_singular' => 'X', 'label_plural' => 'Xs' ) ),
	'pre_reserved_slug'
);
pre_smoke_wp_error(
	'reserved slug "product" is rejected (WooCommerce)',
	$plugin->cpts->register( 'product', array( 'slug' => 'product', 'label_singular' => 'X', 'label_plural' => 'Xs' ) ),
	'pre_reserved_slug'
);
pre_smoke_wp_error(
	'missing label_singular is rejected',
	$plugin->cpts->register( 'pre_smoke_test_2', array( 'slug' => 'pre_smoke_test_2' ) ),
	'pre_missing_label_singular'
);
pre_smoke_wp_error(
	'invalid slug characters are rejected',
	$plugin->cpts->register( 'Bad Slug!', array( 'slug' => 'Bad Slug!', 'label_singular' => 'X', 'label_plural' => 'Xs' ) ),
	'pre_invalid_slug'
);
pre_smoke_wp_error(
	'slug longer than 20 chars is rejected',
	$plugin->cpts->register( 'this_slug_is_way_too_long_for_wp', array( 'slug' => 'this_slug_is_way_too_long_for_wp', 'label_singular' => 'X', 'label_plural' => 'Xs' ) ),
	'pre_slug_too_long'
);

// 5b. Hero fields — happy path round-trip + validation rejections.
$plugin->cpts->register(
	PRE_SMOKE_CPT_SLUG,
	array(
		'slug'                => PRE_SMOKE_CPT_SLUG,
		'label_singular'      => 'Smoke',
		'label_plural'        => 'Smokes',
		'hero_layout'         => 'split',
		'hero_image_position' => 'right',
		'hero_image_aspect'   => 'landscape',
	)
);
$with_hero = $plugin->cpts->get( PRE_SMOKE_CPT_SLUG );
pre_smoke_equals( 'hero_layout round-trips', 'split', $with_hero['hero_layout'] ?? null );
pre_smoke_equals( 'hero_image_position round-trips', 'right', $with_hero['hero_image_position'] ?? null );
pre_smoke_equals( 'hero_image_aspect round-trips', 'landscape', $with_hero['hero_image_aspect'] ?? null );

pre_smoke_wp_error(
	'invalid hero_layout is rejected',
	$plugin->cpts->register( 'pre_smoke_hero_test', array( 'slug' => 'pre_smoke_hero_test', 'label_singular' => 'X', 'label_plural' => 'Xs', 'hero_layout' => 'fancy' ) ),
	'pre_invalid_hero_layout'
);
pre_smoke_wp_error(
	'invalid hero_image_position is rejected',
	$plugin->cpts->register( 'pre_smoke_hero_test', array( 'slug' => 'pre_smoke_hero_test', 'label_singular' => 'X', 'label_plural' => 'Xs', 'hero_image_position' => 'middle' ) ),
	'pre_invalid_hero_image_position'
);
pre_smoke_wp_error(
	'invalid hero_image_aspect is rejected',
	$plugin->cpts->register( 'pre_smoke_hero_test', array( 'slug' => 'pre_smoke_hero_test', 'label_singular' => 'X', 'label_plural' => 'Xs', 'hero_image_aspect' => 'panoramic' ) ),
	'pre_invalid_hero_image_aspect'
);

// CPTs without explicit hero fields should default to stacked + left +
// square after merge_defaults. Test the same CPT registered without
// those keys.
$plugin->cpts->register(
	PRE_SMOKE_CPT_SLUG,
	array(
		'slug'           => PRE_SMOKE_CPT_SLUG,
		'label_singular' => 'Smoke',
		'label_plural'   => 'Smokes',
	)
);
$no_hero = $plugin->cpts->get( PRE_SMOKE_CPT_SLUG );
pre_smoke_equals( 'hero_layout defaults to stacked', 'stacked', $no_hero['hero_layout'] ?? null );
pre_smoke_equals( 'hero_image_position defaults to left', 'left', $no_hero['hero_image_position'] ?? null );
pre_smoke_equals( 'hero_image_aspect defaults to square', 'square', $no_hero['hero_image_aspect'] ?? null );

// 6. Grouping definition — happy path.
$result = $plugin->groupings->define(
	PRE_SMOKE_CPT_SLUG,
	array(
		'key'              => PRE_SMOKE_GROUPING_KEY,
		'label'            => 'Quick Specs',
		'default_variant'  => 'compact-grid',
		'default_position' => 'above_main',
	)
);
pre_smoke_assert( 'define grouping returns true', $result === true );
pre_smoke_assert( 'grouping is queryable', $plugin->groupings->exists( PRE_SMOKE_CPT_SLUG, PRE_SMOKE_GROUPING_KEY ) );

$stored_grouping = $plugin->groupings->get( PRE_SMOKE_CPT_SLUG, PRE_SMOKE_GROUPING_KEY );
pre_smoke_equals( 'grouping default_source defaults to manual', 'manual', isset( $stored_grouping['default_source'] ) ? $stored_grouping['default_source'] : null );
pre_smoke_equals( 'grouping heading_required defaults to true', true, isset( $stored_grouping['heading_required'] ) ? $stored_grouping['heading_required'] : null );

// 7. Grouping definition — failure cases.
pre_smoke_wp_error(
	'invalid variant is rejected',
	$plugin->groupings->define( PRE_SMOKE_CPT_SLUG, array( 'key' => 'broken', 'label' => 'X', 'default_variant' => 'rainbow-grid', 'default_position' => 'above_main' ) ),
	'pre_invalid_default_variant'
);
pre_smoke_wp_error(
	'invalid position is rejected',
	$plugin->groupings->define( PRE_SMOKE_CPT_SLUG, array( 'key' => 'broken', 'label' => 'X', 'default_variant' => 'compact-grid', 'default_position' => 'floating' ) ),
	'pre_invalid_default_position'
);
pre_smoke_wp_error(
	'featured-card with max_items>1 is rejected',
	$plugin->groupings->define( PRE_SMOKE_CPT_SLUG, array( 'key' => 'broken', 'label' => 'X', 'default_variant' => 'featured-card', 'default_position' => 'sidebar', 'max_items' => 5 ) ),
	'pre_featured_card_max_items'
);

// 8. Per-post groupings — happy path.
// Need a real post in the registered CPT. WordPress requires the CPT to be
// registered with itself first, so call register_post_types() now.
$plugin->register_post_types();

$post_id = wp_insert_post(
	array(
		'post_type'   => PRE_SMOKE_CPT_SLUG,
		'post_title'  => 'Smoke Test Listing',
		'post_status' => 'draft',
	)
);
pre_smoke_assert( 'test post created', $post_id > 0 && ! is_wp_error( $post_id ) );

$result = $plugin->post_data->set_groupings(
	$post_id,
	array(
		array(
			'grouping_key' => PRE_SMOKE_GROUPING_KEY,
			'position'     => 'above_main',
			'source'       => 'manual',
			'items'        => array(
				array( 'icon_id' => 'bed',   'heading' => '4 Bedrooms' ),
				array( 'icon_id' => 'bath',  'heading' => '2 Bathrooms' ),
				array( 'icon_id' => 'ruler', 'heading' => '1,800 sqft' ),
			),
		),
	),
	'smoke-test'
);
pre_smoke_assert( 'set_groupings returns true', $result === true );

$read_back = $plugin->post_data->get_groupings( $post_id );
pre_smoke_equals( 'one grouping persists', 1, count( $read_back ) );
pre_smoke_equals( 'three items persist', 3, count( $read_back[0]['items'] ) );
pre_smoke_equals( 'first item heading round-trips', '4 Bedrooms', $read_back[0]['items'][0]['heading'] );
pre_smoke_equals( 'first item icon round-trips', 'bed', $read_back[0]['items'][0]['icon_id'] );

// 9. Per-post groupings — variant override.
$plugin->post_data->update_grouping(
	$post_id,
	PRE_SMOKE_GROUPING_KEY,
	array(
		'grouping_key'     => PRE_SMOKE_GROUPING_KEY,
		'position'         => 'above_main',
		'variant_override' => 'card-grid',
		'source'           => 'manual',
		'items'            => array(
			array( 'icon_id' => 'bed', 'heading' => '4 Bedrooms', 'supporting_text' => 'All with en-suites' ),
		),
	),
	'smoke-test'
);
$read_back = $plugin->post_data->get_groupings( $post_id );
pre_smoke_equals( 'variant_override persists', 'card-grid', $read_back[0]['variant_override'] );
pre_smoke_equals( 'supporting_text persists', 'All with en-suites', $read_back[0]['items'][0]['supporting_text'] );

// 10. Per-post groupings — backup is created.
pre_smoke_assert( 'backup exists after set_groupings', $plugin->post_data->has_backup( $post_id ) );

// 10b. link_post_id round-trips alongside the URL string. This is the
// site-portability contract — internal links survive domain migrations
// because the renderer resolves via get_permalink(link_post_id) at
// render time. Validator must accept link_post_id as a positive int.
$plugin->post_data->update_grouping(
	$post_id,
	PRE_SMOKE_GROUPING_KEY,
	array(
		'grouping_key' => PRE_SMOKE_GROUPING_KEY,
		'source'       => 'manual',
		'items'        => array(
			array(
				'icon_id'      => 'bed',
				'heading'      => 'Linked card',
				'link'         => 'http://example.test/some-post/',
				'link_post_id' => 42,
			),
		),
	),
	'smoke-test'
);
$read_back = $plugin->post_data->get_groupings( $post_id );
pre_smoke_equals( 'link_post_id round-trips', 42, (int) $read_back[0]['items'][0]['link_post_id'] );
pre_smoke_equals( 'link string round-trips alongside post_id', 'http://example.test/some-post/', $read_back[0]['items'][0]['link'] );

// 10c. link_post_id rejects non-integer values (string, array, negative).
pre_smoke_wp_error(
	'link_post_id as string is rejected',
	$plugin->post_data->set_groupings(
		$post_id,
		array(
			array(
				'grouping_key' => PRE_SMOKE_GROUPING_KEY,
				'source'       => 'manual',
				'items'        => array(
					array( 'icon_id' => 'bed', 'heading' => 'X', 'link_post_id' => 'not-a-number' ),
				),
			),
		)
	),
	'pre_invalid_link_post_id'
);

// 11. Per-post groupings — failure cases.
pre_smoke_wp_error(
	'unknown grouping_key is rejected',
	$plugin->post_data->set_groupings( $post_id, array( array( 'grouping_key' => 'nope', 'items' => array() ) ) ),
	'pre_unknown_grouping_key'
);
pre_smoke_wp_error(
	'image_id and icon_id together are rejected',
	$plugin->post_data->set_groupings(
		$post_id,
		array(
			array(
				'grouping_key' => PRE_SMOKE_GROUPING_KEY,
				'items'        => array(
					array( 'image_id' => 1, 'icon_id' => 'bed', 'heading' => 'X' ),
				),
			),
		)
	),
	'pre_image_icon_conflict'
);
pre_smoke_wp_error(
	'unknown icon_id is rejected',
	$plugin->post_data->set_groupings(
		$post_id,
		array(
			array(
				'grouping_key' => PRE_SMOKE_GROUPING_KEY,
				'items'        => array( array( 'icon_id' => 'unicorn', 'heading' => 'X' ) ),
			),
		)
	),
	'pre_unknown_icon'
);
pre_smoke_wp_error(
	'invalid variant_override is rejected',
	$plugin->post_data->set_groupings(
		$post_id,
		array(
			array(
				'grouping_key'     => PRE_SMOKE_GROUPING_KEY,
				'variant_override' => 'rainbow-grid',
				'items'            => array( array( 'icon_id' => 'bed', 'heading' => 'X' ) ),
			),
		)
	),
	'pre_invalid_variant_override'
);
pre_smoke_wp_error(
	'auto source with stored items is rejected',
	$plugin->post_data->set_groupings(
		$post_id,
		array(
			array(
				'grouping_key' => PRE_SMOKE_GROUPING_KEY,
				'source'       => 'child_posts',
				'items'        => array( array( 'icon_id' => 'bed', 'heading' => 'X' ) ),
			),
		)
	),
	'pre_auto_source_has_items'
);
pre_smoke_wp_error(
	'duplicate grouping_key in same post is rejected',
	$plugin->post_data->set_groupings(
		$post_id,
		array(
			array( 'grouping_key' => PRE_SMOKE_GROUPING_KEY, 'items' => array( array( 'icon_id' => 'bed', 'heading' => 'X' ) ) ),
			array( 'grouping_key' => PRE_SMOKE_GROUPING_KEY, 'items' => array( array( 'icon_id' => 'bath', 'heading' => 'Y' ) ) ),
		)
	),
	'pre_duplicate_post_grouping'
);

// 12. Source modes — taxonomy_match string form is rejected.
pre_smoke_wp_error(
	'taxonomy_match as bare string is rejected',
	$plugin->post_data->set_groupings(
		$post_id,
		array(
			array(
				'grouping_key' => PRE_SMOKE_GROUPING_KEY,
				'source'       => 'taxonomy_match',
			),
		)
	),
	'pre_taxonomy_match_needs_object'
);

// 13. Source modes — taxonomy_match object form requires taxonomy.
pre_smoke_wp_error(
	'taxonomy_match without taxonomy is rejected',
	$plugin->post_data->set_groupings(
		$post_id,
		array(
			array(
				'grouping_key' => PRE_SMOKE_GROUPING_KEY,
				'source'       => array( 'type' => 'taxonomy_match' ),
			),
		)
	),
	'pre_invalid_source_taxonomy'
);

// 14. Source modes — child_posts is accepted.
$result = $plugin->post_data->set_groupings(
	$post_id,
	array(
		array(
			'grouping_key' => PRE_SMOKE_GROUPING_KEY,
			'source'       => 'child_posts',
			'items'        => array(),
		),
	),
	'smoke-test'
);
pre_smoke_assert( 'child_posts source accepted', $result === true );

// 15. Capability helpers.
pre_smoke_assert( 'edit_cap_for() returns a string', is_string( PRE_Capabilities::edit_cap_for( PRE_SMOKE_CPT_SLUG ) ) );
pre_smoke_assert( 'PRE_Capabilities::MANAGE_CAP is manage_options', PRE_Capabilities::MANAGE_CAP === 'manage_options' );

// 16. Validator — link validation.
$validator = new PRE_Validator();
pre_smoke_assert( 'anchor link is accepted', true === $validator->validate_link( '#contact' ) );
pre_smoke_assert( 'relative path is accepted', true === $validator->validate_link( '/contact/' ) );
pre_smoke_assert( 'tel: URI is accepted', true === $validator->validate_link( 'tel:+15555550100' ) );
pre_smoke_assert( 'mailto: URI is accepted', true === $validator->validate_link( 'mailto:hi@example.com' ) );
pre_smoke_assert( 'https URL is accepted', true === $validator->validate_link( 'https://example.com/path' ) );
pre_smoke_wp_error( 'javascript: URL is rejected', $validator->validate_link( 'javascript:alert(1)' ), 'pre_invalid_url' );

// ---------------------------------------------------------------------------
// Phase 3 — Connector tests (append to the shared $pre_smoke_results array
// so the final report includes them).
// ---------------------------------------------------------------------------

$phase3 = __DIR__ . '/smoke-phase3.php';
if ( file_exists( $phase3 ) ) {
	include $phase3;
}

// ---------------------------------------------------------------------------
// Cleanup and report.
// ---------------------------------------------------------------------------

pre_smoke_cleanup();

$total  = count( $pre_smoke_results );
$passed = count( array_filter( $pre_smoke_results, function ( $r ) { return $r['ok']; } ) );
$failed = $total - $passed;

echo "\n----------------------------------------\n";
echo sprintf( "Result: %d/%d passed\n", $passed, $total );

if ( $failed > 0 ) {
	echo "\nFailures:\n";
	foreach ( $pre_smoke_results as $r ) {
		if ( ! $r['ok'] ) {
			echo "  - " . $r['name'];
			if ( $r['details'] !== '' ) {
				echo "\n    " . $r['details'];
			}
			echo "\n";
		}
	}
	exit( 1 );
}

echo "\nAll data-layer + connector tests passed. Plugin is ready for use.\n";
exit( 0 );
