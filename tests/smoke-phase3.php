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

// =============================================================================
// Phase B (v0.3.0) — pressure-test findings encoded as smoke tests.
// Each section here corresponds to a Phase A code change. If a test fails,
// either the code regressed or the contract changed without updating tests.
// =============================================================================

// 6a. Phase A1 — critical_rules block in preflight.
$res          = $dispatch( 'GET', '/preflight' );
$preflight    = $res->get_data();
$rules        = isset( $preflight['critical_rules'] ) ? $preflight['critical_rules'] : null;
pre_smoke_assert( 'preflight returns critical_rules block', is_array( $rules ) );
$expected_rule_keys = array(
	'post_content_is_html',
	'groupings_creation_pattern',
	'cross_cpt_item_icons',
	'compact_grid_strips_image',
	'link_post_id_canonical',
	'postgrid_grid_balance',
	'featured_card_max_one',
);
foreach ( $expected_rule_keys as $rk ) {
	pre_smoke_assert( "critical_rules has '{$rk}'", is_array( $rules ) && isset( $rules[ $rk ] ) && is_string( $rules[ $rk ] ) && $rules[ $rk ] !== '' );
}
pre_smoke_assert(
	'post_content_is_html mentions CDATA explicitly',
	is_array( $rules ) && isset( $rules['post_content_is_html'] ) && stripos( $rules['post_content_is_html'], 'CDATA' ) !== false
);

// 6b. Phase A2 — field_name_hints in preflight.
$hints = isset( $preflight['field_name_hints'] ) ? $preflight['field_name_hints'] : null;
pre_smoke_assert( 'preflight returns field_name_hints', is_array( $hints ) );
pre_smoke_assert( 'field_name_hints has groupings_item_shape', is_array( $hints ) && isset( $hints['groupings_item_shape'] ) );
foreach ( array( 'compact-grid', 'horizontal-row', 'card-grid', 'featured-card' ) as $variant ) {
	pre_smoke_assert(
		"groupings_item_shape lists fields for {$variant}",
		isset( $hints['groupings_item_shape'][ $variant ] ) && is_array( $hints['groupings_item_shape'][ $variant ] ) && in_array( 'heading', $hints['groupings_item_shape'][ $variant ], true )
	);
}
pre_smoke_assert( 'field_name_hints has cpt_definition list with default_icon', isset( $hints['cpt_definition'] ) && is_array( $hints['cpt_definition'] ) && in_array( 'default_icon', $hints['cpt_definition'], true ) );

// 6c. Phase A6 — _site envelope present on every connector response.
pre_smoke_assert( 'preflight has _site envelope', isset( $preflight['_site'] ) && is_array( $preflight['_site'] ) );
pre_smoke_assert( '_site has site_url', isset( $preflight['_site']['site_url'] ) && filter_var( $preflight['_site']['site_url'], FILTER_VALIDATE_URL ) );
pre_smoke_assert( '_site has site_name', isset( $preflight['_site']['site_name'] ) && is_string( $preflight['_site']['site_name'] ) && $preflight['_site']['site_name'] !== '' );
pre_smoke_assert(
	'_site has env_hint with valid value',
	isset( $preflight['_site']['env_hint'] ) && in_array( $preflight['_site']['env_hint'], array( 'production', 'staging', 'development' ), true )
);
$res2 = $dispatch( 'GET', '/icons' );
pre_smoke_assert( '_site envelope present on /icons', isset( $res2->get_data()['_site'] ) );
$res2 = $dispatch( 'GET', '/cpts' );
pre_smoke_assert( '_site envelope present on /cpts', isset( $res2->get_data()['_site'] ) );

// 6d. Phase A3 — CDATA wrapper sanitization on create_post.
$cdata_cpt = 'pre_smk_cdata';
$dispatch( 'POST', '/cpts', array(
	'slug'           => $cdata_cpt,
	'label_singular' => 'CDATA Test',
	'label_plural'   => 'CDATA Tests',
	'supports'       => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
) );

// Bookend wrapper — opener AND closer at the boundaries → fully stripped.
$res = $dispatch( 'POST', '/posts', array(
	'post_type'    => $cdata_cpt,
	'post_title'   => 'CDATA bookend test',
	'post_content' => "<![CDATA[<p>This is the body.</p>]]>",
) );
pre_smoke_equals( 'CDATA bookend create returns 201', 201, $res->get_status() );
$post_id_a = isset( $res->get_data()['post_id'] ) ? (int) $res->get_data()['post_id'] : 0;
$warnings_a = $res->get_data()['warnings'] ?? array();
pre_smoke_assert(
	'CDATA bookend stripped warning surfaced',
	is_array( $warnings_a ) && ! empty( array_filter( $warnings_a, function ( $w ) { return is_string( $w ) && strpos( $w, 'cdata_stripped' ) !== false; } ) )
);
$stored = get_post( $post_id_a );
pre_smoke_assert( 'CDATA opener removed from stored content', $stored && strpos( $stored->post_content, '<![CDATA[' ) === false );
pre_smoke_assert( 'CDATA closer removed from stored content', $stored && strpos( $stored->post_content, ']]>' ) === false );
pre_smoke_assert( 'inner HTML preserved verbatim', $stored && strpos( $stored->post_content, '<p>This is the body.</p>' ) !== false );

// Opener-only (no closer) — stripped with stronger warning text.
$res = $dispatch( 'POST', '/posts', array(
	'post_type'    => $cdata_cpt,
	'post_title'   => 'CDATA opener-only test',
	'post_content' => "<![CDATA[<p>Unclosed wrapper.</p>",
) );
$post_id_b = (int) $res->get_data()['post_id'];
$warnings_b = $res->get_data()['warnings'] ?? array();
pre_smoke_assert(
	'CDATA opener-only stripped warning mentions no matching closer',
	is_array( $warnings_b ) && ! empty( array_filter( $warnings_b, function ( $w ) { return is_string( $w ) && stripos( $w, 'no matching closer' ) !== false; } ) )
);

// Mid-content CDATA — our sanitizer must NOT fire (no warning). What WordPress
// does to the text after our sanitizer is wp_kses_post's concern; that path
// strips literal <![CDATA[ tokens regardless because they aren't in the
// allowed-HTML list. The contract we're testing here is narrower: our
// connector-level CDATA strip only acts on bookend wrappers, not on
// CDATA-shaped tokens elsewhere in the body.
$res = $dispatch( 'POST', '/posts', array(
	'post_type'    => $cdata_cpt,
	'post_title'   => 'CDATA mid-content test',
	'post_content' => "<p>For example, the syntax <![CDATA[<x>literal</x>]]> embeds raw markup.</p>",
) );
$post_id_c  = (int) $res->get_data()['post_id'];
$warnings_c = $res->get_data()['warnings'] ?? array();
$has_cdata_warn_c = is_array( $warnings_c ) && ! empty( array_filter( $warnings_c, function ( $w ) { return is_string( $w ) && strpos( $w, 'cdata' ) !== false; } ) );
pre_smoke_assert( 'mid-content CDATA does NOT trigger our sanitizer (no warning)', ! $has_cdata_warn_c );

// 6e. Phase A5 — postruntime_update_post round-trip.
// Title-only update — content untouched.
$dispatch( 'PUT', '/posts/' . $post_id_a, array( 'post_title' => 'Renamed by update_post' ) );
$updated_a = get_post( $post_id_a );
pre_smoke_equals( 'update_post: title updated', 'Renamed by update_post', $updated_a ? $updated_a->post_title : '' );
pre_smoke_assert(
	'update_post: content untouched on title-only update',
	$updated_a && strpos( $updated_a->post_content, '<p>This is the body.</p>' ) !== false
);

// Content-only update with CDATA wrapper — strip should fire here too.
$res = $dispatch( 'PUT', '/posts/' . $post_id_a, array(
	'post_content' => "<![CDATA[<p>New body via update.</p>]]>",
) );
pre_smoke_equals( 'update_post returns 200', 200, $res->get_status() );
$update_warnings = $res->get_data()['warnings'] ?? array();
pre_smoke_assert(
	'update_post strips CDATA wrapper too',
	is_array( $update_warnings ) && ! empty( array_filter( $update_warnings, function ( $w ) { return is_string( $w ) && strpos( $w, 'cdata_stripped' ) !== false; } ) )
);
$updated_a = get_post( $post_id_a );
pre_smoke_assert(
	'update_post: CDATA-stripped content stored cleanly',
	$updated_a && strpos( $updated_a->post_content, '<![CDATA[' ) === false && strpos( $updated_a->post_content, '<p>New body via update.</p>' ) !== false
);

// Invalid post_status — typed 422.
$res = $dispatch( 'PUT', '/posts/' . $post_id_a, array( 'post_status' => 'not-a-real-status' ) );
pre_smoke_equals( 'update_post invalid status returns 422', 422, $res->get_status() );
$err = $res->as_error();
pre_smoke_equals( 'update_post invalid status error code', 'pre_invalid_post_status', $err && is_wp_error( $err ) ? $err->get_error_code() : '' );

// Empty title — explicit rejection (vs partial-update where omitting is fine).
$res = $dispatch( 'PUT', '/posts/' . $post_id_a, array( 'post_title' => '' ) );
pre_smoke_equals( 'update_post empty title returns 422', 422, $res->get_status() );

// 6f. Phase A4 — renderer falls back to LINKED CPT default_icon, not host.
$dispatch( 'POST', '/cpts', array(
	'slug'           => 'pre_smk_host',
	'label_singular' => 'Host',
	'label_plural'   => 'Hosts',
	'default_icon'   => 'home',
) );
$dispatch( 'POST', '/cpts', array(
	'slug'           => 'pre_smk_link',
	'label_singular' => 'Link',
	'label_plural'   => 'Links',
	'default_icon'   => 'user',
) );
$dispatch( 'POST', '/cpts/pre_smk_host/groupings', array(
	'key'              => 'related',
	'label'            => 'Related',
	'default_variant'  => 'featured-card',
	'default_position' => 'sidebar',
	'default_source'   => 'manual',
	'link_required'    => true,
	'max_items'        => 1,
) );
$res = $dispatch( 'POST', '/posts', array(
	'post_type'  => 'pre_smk_link',
	'post_title' => 'Link target post',
) );
$link_target_id = (int) $res->get_data()['post_id'];
$res = $dispatch( 'POST', '/posts', array(
	'post_type'  => 'pre_smk_host',
	'post_title' => 'Host post',
	'groupings'  => array(
		array(
			'grouping_key' => 'related',
			'items'        => array(
				array(
					'heading'      => 'Linked item',
					'link_post_id' => $link_target_id,
					'link'         => '/?p=' . $link_target_id,
				),
			),
		),
	),
) );
$host_post_id = (int) $res->get_data()['post_id'];
$res          = $dispatch( 'GET', '/posts/' . $host_post_id . '/preview' );
$render_data  = $res->get_data();
$html         = isset( $render_data['html'] ) ? $render_data['html'] : '';
$expected_user_svg = PRE_Icon_Library::render( 'user', 'pre-grouping__icon' );
$expected_home_svg = PRE_Icon_Library::render( 'home', 'pre-grouping__icon' );
pre_smoke_assert(
	'rendered featured-card uses LINKED CPT default_icon (user)',
	$expected_user_svg !== '' && strpos( $html, $expected_user_svg ) !== false
);
pre_smoke_assert(
	'rendered featured-card does NOT use host CPT default_icon (home)',
	$expected_home_svg !== '' && strpos( $html, $expected_home_svg ) === false
);

// =============================================================================
// PT-9-1 — Trashed / non-existent post referenced via link_post_id.
// Covers two related code paths:
//   A) link_post_id → non-existent ID — get_post() returns null, get_permalink()
//      returns false; renderer must fall back to the literal `link` URL and not
//      crash, and the cross-CPT default_icon resolution must skip its lookup.
//   B) link_post_id → trashed ID — get_post() still returns the post object
//      with post_status='trash'; get_permalink() returns false on most setups.
//      Renderer must still complete cleanly.
// =============================================================================

// Setup: create a host post that points its featured-card item at a known-bad
// link_post_id (99999 — guaranteed to not exist). Reuse the pre_smk_host /
// pre_smk_link CPTs from PT-A4 (still registered at this point in the suite).
$res = $dispatch( 'POST', '/posts', array(
	'post_type'  => 'pre_smk_host',
	'post_title' => 'Host with bad link_post_id',
	'groupings'  => array(
		array(
			'grouping_key' => 'related',
			'items'        => array(
				array(
					'heading'      => 'Linked to ghost',
					'link_post_id' => 99999,
					'link'         => '/some/fallback/url/',
				),
			),
		),
	),
) );
pre_smoke_equals( 'PT-9-1: bad link_post_id post created', 201, $res->get_status() );
$bad_link_post_id = (int) $res->get_data()['post_id'];

// Render via the connector preview endpoint. If the renderer crashes on a
// non-existent linked post, this returns a 500 and the suite halts. If it
// gracefully falls back, we get HTML back.
$res = $dispatch( 'GET', '/posts/' . $bad_link_post_id . '/preview' );
pre_smoke_equals( 'PT-9-1A: preview with non-existent link_post_id returns 200', 200, $res->get_status() );
$html_a = $res->get_data()['html'] ?? '';
pre_smoke_assert( 'PT-9-1A: preview returns non-empty HTML', is_string( $html_a ) && $html_a !== '' );
// The renderer should fall back to the literal `link` URL when the post_id
// can't be resolved. Confirm the literal URL is present in rendered output.
pre_smoke_assert(
	'PT-9-1A: literal link URL preserved as fallback',
	strpos( $html_a, '/some/fallback/url/' ) !== false
);

// Trashed-post scenario: create a real linked post, point at it, then trash.
$res = $dispatch( 'POST', '/posts', array(
	'post_type'  => 'pre_smk_link',
	'post_title' => 'Will be trashed',
) );
$trashable_id = (int) $res->get_data()['post_id'];

$res = $dispatch( 'POST', '/posts', array(
	'post_type'  => 'pre_smk_host',
	'post_title' => 'Host with trashed link target',
	'groupings'  => array(
		array(
			'grouping_key' => 'related',
			'items'        => array(
				array(
					'heading'      => 'Linked to trashed',
					'link_post_id' => $trashable_id,
					'link'         => '/another/fallback/',
				),
			),
		),
	),
) );
$host_with_trashed_link_id = (int) $res->get_data()['post_id'];

// Trash the linked post directly (mirrors what wp-admin → trash would do).
wp_trash_post( $trashable_id );

// Render the host post; renderer must not crash.
$res = $dispatch( 'GET', '/posts/' . $host_with_trashed_link_id . '/preview' );
pre_smoke_equals( 'PT-9-1B: preview with trashed link_post_id returns 200', 200, $res->get_status() );
$html_b = $res->get_data()['html'] ?? '';
pre_smoke_assert( 'PT-9-1B: preview returns non-empty HTML for trashed-link host', is_string( $html_b ) && $html_b !== '' );
pre_smoke_assert(
	'PT-9-1B: literal link URL preserved when target is trashed',
	strpos( $html_b, '/another/fallback/' ) !== false
);

// =============================================================================
// PT-9-2 — Deleted attachment referenced as featured image.
// Renderer's hero must skip the image gracefully (no broken <img> tag,
// no PHP warning) when the attachment has been hard-deleted from the media
// library while the post still has _thumbnail_id pointing at the gone ID.
// =============================================================================

// Create an attachment by inserting a fake media post directly. Avoids
// requiring a real upload — we just need an attachment ID we can then
// delete to simulate the gap.
$attachment_id = wp_insert_post( array(
	'post_title'     => 'Test attachment',
	'post_status'    => 'inherit',
	'post_type'      => 'attachment',
	'post_mime_type' => 'image/jpeg',
), true );

if ( ! is_wp_error( $attachment_id ) ) {
	// Create a host post with this attachment as featured image.
	$res = $dispatch( 'POST', '/posts', array(
		'post_type'         => 'pre_smk_host',
		'post_title'        => 'Host with soon-deleted thumbnail',
		'featured_image_id' => $attachment_id,
	) );
	$thumb_host_id = (int) $res->get_data()['post_id'];

	// Now delete the attachment hard.
	wp_delete_attachment( $attachment_id, true );

	// Render the host post — should complete without crash.
	$res = $dispatch( 'GET', '/posts/' . $thumb_host_id . '/preview' );
	pre_smoke_equals( 'PT-9-2: preview with deleted thumbnail returns 200', 200, $res->get_status() );
	$html_c = $res->get_data()['html'] ?? '';
	pre_smoke_assert( 'PT-9-2: preview returns non-empty HTML', is_string( $html_c ) && $html_c !== '' );
	// The renderer's pre-check on has_post_thumbnail / wp_get_attachment_image
	// should mean no broken <img> tag is emitted referencing the dead ID.
	$attachment_id_str = (string) $attachment_id;
	pre_smoke_assert(
		'PT-9-2: rendered HTML does not reference deleted attachment ID',
		strpos( $html_c, '"' . $attachment_id_str . '"' ) === false || strpos( $html_c, 'wp-image-' . $attachment_id_str ) === false
	);

	// Cleanup the host post; attachment is already gone.
	wp_delete_post( $thumb_host_id, true );
}

// =============================================================================
// PT-9-1 / PT-9-2 cleanup — wipe the new test posts before the broader cleanup.
// =============================================================================
foreach ( array( $bad_link_post_id, $trashable_id, $host_with_trashed_link_id ) as $cleanup_id ) {
	if ( $cleanup_id > 0 ) {
		wp_delete_post( $cleanup_id, true );
	}
}

// 6g. Phase B cleanup — wipe all Phase B test posts + CPTs.
foreach ( array( $post_id_a, $post_id_b, $post_id_c, $link_target_id, $host_post_id ) as $cleanup_id ) {
	if ( $cleanup_id > 0 ) {
		wp_delete_post( $cleanup_id, true );
	}
}
$dispatch( 'DELETE', '/cpts/' . $cdata_cpt . '?purge_data=1' );
$dispatch( 'DELETE', '/cpts/pre_smk_host?purge_data=1' );
$dispatch( 'DELETE', '/cpts/pre_smk_link?purge_data=1' );

// 6. Cleanup (original Phase 3 test fixture).
$res = $dispatch( 'DELETE', '/cpts/' . $test_slug );
pre_smoke_equals( 'delete_cpt returns 200', 200, $res->get_status() );
$del_body = $res->get_data();
pre_smoke_equals( 'delete_cpt body reports deleted', true, ! empty( $del_body['deleted'] ) );

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
