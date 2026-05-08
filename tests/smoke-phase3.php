<?php
/**
 * Phase 3 connector smoke test.
 *
 * Exercises the REST connector via WordPress's internal rest_do_request()
 * — no HTTP, no auth header complexity, just direct dispatch through the
 * WP_REST_Server. Confirms route registration, response shapes, error
 * codes, and round-trip persistence.
 *
 * Loads the same pre_smoke_assert / pre_smoke_equals / pre_smoke_wp_error
 * helpers as smoke-phase1.php so the runner output reads identically.
 *
 * NOT covered here (verified via browser fetch instead):
 *   - Application Password auth (Apache header forwarding is a deployment
 *     concern, not an API correctness concern)
 *   - Rate limiting (would require multiple bursts, slow to run)
 *
 * @package PostRuntimeEngine
 */

// Bootstrap (only if running standalone; the browser runner sets these up).
if ( ! defined( 'ABSPATH' ) ) {
	require_once dirname( __FILE__ ) . '/../../../../wp-load.php';
}
if ( ! function_exists( 'pre_smoke_assert' ) ) {
	require_once __DIR__ . '/smoke-phase1.php';
}

if ( php_sapi_name() === 'cli' ) {
	echo "\n=== Phase 3 connector smoke test ===\n\n";
}

// Ensure the connector is enabled for the duration of these tests.
$_pre_smoke_was_enabled = PRE_Connector_Settings::is_enabled();
PRE_Connector_Settings::set_enabled( true );

// Register a test admin user as the actor for permission checks.
$test_user = get_user_by( 'login', 'admin' );
if ( ! $test_user ) {
	// Fallback: use the first administrator on the site.
	$admins    = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	$test_user = ! empty( $admins ) ? $admins[0] : null;
}
if ( ! $test_user ) {
	pre_smoke_assert( 'admin user exists for connector smoke test', false );
	return;
}
wp_set_current_user( $test_user->ID );
pre_smoke_assert( 'connector smoke acting as administrator', current_user_can( 'manage_options' ) );

// Helper: dispatch a REST request internally and return the response.
$dispatch = function ( $method, $route, $body = null ) {
	$request = new WP_REST_Request( $method, '/' . PRE_REST_NAMESPACE . '/' . PRE_REST_BASE . $route );
	$request->set_header( 'Content-Type', 'application/json' );
	if ( $body !== null ) {
		$request->set_body( wp_json_encode( $body ) );
	}
	return rest_do_request( $request );
};

// 1. Preflight returns 200 with the expected shape.
$res = $dispatch( 'GET', '/preflight' );
pre_smoke_equals( 'preflight returns 200', 200, $res->get_status() );
$data = $res->get_data();
pre_smoke_assert( 'preflight has plugin_version', ! empty( $data['plugin_version'] ) );
pre_smoke_assert( 'preflight has registered_cpts array', is_array( $data['registered_cpts'] ?? null ) );
pre_smoke_assert( 'preflight has user.can_manage_cpts', isset( $data['user']['can_manage_cpts'] ) );

// 2. Introspection endpoints — icons/variants/positions.
$res = $dispatch( 'GET', '/icons' );
pre_smoke_equals( 'list_icons returns 200', 200, $res->get_status() );
$icons = $res->get_data();
pre_smoke_assert( 'icons returns >= 50 entries', is_array( $icons['icons'] ) && count( $icons['icons'] ) >= 50 );
pre_smoke_assert( 'icons returns >= 10 categories', is_array( $icons['categories'] ) && count( $icons['categories'] ) >= 10 );

$res = $dispatch( 'GET', '/variants' );
pre_smoke_equals( 'list_variants returns 200', 200, $res->get_status() );
pre_smoke_equals( 'list_variants returns 4 variants', 4, count( $res->get_data()['variants'] ) );

$res = $dispatch( 'GET', '/positions' );
pre_smoke_equals( 'list_positions returns 200', 200, $res->get_status() );
pre_smoke_equals( 'list_positions returns 3 positions', 3, count( $res->get_data()['positions'] ) );

// 3. CPT CRUD round-trip on a brand-new throwaway slug.
$test_slug = 'pre_smoke_cpt_' . substr( md5( (string) microtime( true ) ), 0, 6 );

// Cleanup any leftover from a previous failed run.
pre()->cpts->unregister( $test_slug );

$res = $dispatch( 'POST', '/cpts', array(
	'slug'           => $test_slug,
	'label_singular' => 'Smoke',
	'label_plural'   => 'Smokes',
	'supports'       => array( 'title', 'editor' ),
	'public'         => true,
	'has_archive'    => true,
	'show_in_rest'   => true,
) );
pre_smoke_equals( 'register_cpt returns 201', 201, $res->get_status() );
$cpt = $res->get_data();
pre_smoke_equals( 'registered slug round-trips', $test_slug, $cpt['slug'] );
pre_smoke_equals( 'connector_version starts at 1', 1, (int) $cpt['connector_version'] );

// GET it back.
$res = $dispatch( 'GET', '/cpts/' . $test_slug );
pre_smoke_equals( 'get_cpt returns 200', 200, $res->get_status() );
pre_smoke_equals( 'get_cpt label round-trips', 'Smoke', $res->get_data()['label_singular'] );

// 4. Define a grouping on the test CPT.
$res = $dispatch( 'POST', '/cpts/' . $test_slug . '/groupings', array(
	'key'              => 'smoke_grouping',
	'label'            => 'Smoke grouping',
	'default_variant'  => 'compact-grid',
	'default_position' => 'above_main',
	'default_source'   => 'manual',
	'heading_required' => true,
) );
pre_smoke_equals( 'define_grouping returns 201', 201, $res->get_status() );
pre_smoke_equals( 'grouping key round-trips', 'smoke_grouping', $res->get_data()['key'] );

// List groupings.
$res = $dispatch( 'GET', '/cpts/' . $test_slug . '/groupings' );
pre_smoke_equals( 'list_groupings returns 200', 200, $res->get_status() );
pre_smoke_equals( 'one grouping listed', 1, count( $res->get_data()['groupings'] ) );

// 5. Validation rejects malformed input.
$res = $dispatch( 'POST', '/cpts/' . $test_slug . '/groupings', array(
	'key'              => 'broken',
	'label'            => 'Broken',
	'default_variant'  => 'card-fancy',
	'default_position' => 'above_main',
	'default_source'   => 'manual',
) );
pre_smoke_equals( 'invalid variant returns 422', 422, $res->get_status() );
$err = $res->as_error();
pre_smoke_assert(
	'error code starts with pre_invalid',
	$err && is_wp_error( $err ) && strpos( $err->get_error_code(), 'pre_invalid' ) === 0
);

// 6. Cleanup.
$res = $dispatch( 'DELETE', '/cpts/' . $test_slug );
pre_smoke_equals( 'delete_cpt returns 204', 204, $res->get_status() );

$res = $dispatch( 'GET', '/cpts/' . $test_slug );
pre_smoke_equals( 'deleted CPT now returns 404', 404, $res->get_status() );

// 7. Unauthenticated request should be rejected. Drop the user, hit a route.
wp_set_current_user( 0 );
$res = $dispatch( 'GET', '/preflight' );
pre_smoke_equals( 'unauthenticated returns 401', 401, $res->get_status() );

// 8. Connector disabled returns 403 even for authenticated users.
wp_set_current_user( $test_user->ID );
PRE_Connector_Settings::set_enabled( false );
$res = $dispatch( 'GET', '/preflight' );
pre_smoke_equals( 'connector_disabled returns 403', 403, $res->get_status() );
$err = $res->as_error();
pre_smoke_equals(
	'connector_disabled error code',
	'connector_disabled',
	$err && is_wp_error( $err ) ? $err->get_error_code() : ''
);

// Restore the connector toggle to whatever it was before this test ran.
PRE_Connector_Settings::set_enabled( $_pre_smoke_was_enabled );
