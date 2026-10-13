<?php
/**
 * A multi_badge value sent as a list reads back as a list (LOCAL, loads WordPress).
 *
 * multi_badge accepts a list or a comma-separated string and is stored as
 * comma-separated text (the meta_in_csv filter query reads it that way). The
 * connector used to hand that text back, so a value sent as ["a","b"] — the
 * form the tool's own examples use — read back as "a,b" (Promptless video dry
 * run, 2026-10). The connector now returns a list; storage is unchanged.
 *
 * Drives the REAL connector routes. Adds a temporary multi_badge field to the
 * first registered CPT, writes through PUT field-values, reads through GET
 * field-values, then removes the field and the test post.
 *
 * Run from the plugin root with Local's database reachable:
 *   php -d mysqli.default_socket="$HOME/Library/Application Support/Local/run/<site-id>/mysql/mysqld.sock" tests/smoke-multi-badge-roundtrip.php
 *
 * @package PostRuntimeEngine
 */

$wp_load = '';
$dir     = __DIR__;
for ( $i = 0; $i < 8; $i++ ) {
	$dir = dirname( $dir );
	if ( file_exists( $dir . '/wp-load.php' ) ) { $wp_load = $dir . '/wp-load.php'; break; }
}
if ( '' === $wp_load ) { fwrite( STDERR, "WordPress not found.\n" ); exit( 1 ); }
require_once $wp_load;

$pass = 0;
$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: {$label}\n"; }
	else { $fail++; echo "FAIL: {$label}" . ( $detail !== '' ? "\n      {$detail}" : '' ) . "\n"; }
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admins[0] );

$plugin = pcptpages();
$slugs  = array_keys( $plugin->cpts->get_all() );
if ( empty( $slugs ) ) { fwrite( STDERR, "No CPT is registered on this site to test against.\n" ); exit( 1 ); }
$cpt   = $slugs[0];
$key   = 'zz_roundtrip_topics';
$route = '/' . PCPTPages_REST_NAMESPACE . '/' . PCPTPages_REST_BASE;

$defined = $plugin->post_fields->define( $cpt, array(
	'key'             => $key,
	'label'           => 'Roundtrip topics',
	'display_type'    => 'multi_badge',
	'card_position'   => 'hidden',
	'single_position' => 'hidden',
) );
$post_id = wp_insert_post( array( 'post_type' => $cpt, 'post_status' => 'draft', 'post_title' => 'multi_badge roundtrip test' ) );
register_shutdown_function( function () use ( $plugin, $cpt, $key, $post_id ) {
	if ( $post_id ) { wp_delete_post( $post_id, true ); }
	$plugin->post_fields->remove( $cpt, $key );
} );
check( 'temporary multi_badge field defined', ! is_wp_error( $defined ), is_wp_error( $defined ) ? $defined->get_error_message() : '' );

function put_values( $route, $post_id, array $values ) {
	$req = new WP_REST_Request( 'PUT', "{$route}/posts/{$post_id}/field-values" );
	$req->set_header( 'content-type', 'application/json' );
	$req->set_body( wp_json_encode( array( 'values' => $values ) ) );
	return rest_do_request( $req );
}
function get_values( $route, $post_id ) {
	return rest_do_request( new WP_REST_Request( 'GET', "{$route}/posts/{$post_id}/field-values" ) );
}

echo "\n=== sent as a list ===\n";
$put = put_values( $route, $post_id, array( $key => array( 'Joinery', 'Hand tools', 'Finishing' ) ) );
check( 'PUT succeeds', ! $put->is_error(), wp_json_encode( $put->get_data() ) );
check( 'PUT response returns the list', ( $put->get_data()['field_values'][ $key ] ?? null ) === array( 'Joinery', 'Hand tools', 'Finishing' ), wp_json_encode( $put->get_data()['field_values'][ $key ] ?? null ) );
$get = get_values( $route, $post_id );
check( 'GET returns the list', ( $get->get_data()['field_values'][ $key ] ?? null ) === array( 'Joinery', 'Hand tools', 'Finishing' ), wp_json_encode( $get->get_data()['field_values'][ $key ] ?? null ) );
check( 'storage is unchanged: comma-separated text', get_post_meta( $post_id, PCPTPages_Post_Data::FIELD_VALUE_META_PREFIX . $key, true ) === 'Joinery,Hand tools,Finishing' );

echo "\n=== sent as a comma string ===\n";
put_values( $route, $post_id, array( $key => 'Sharpening, Layout ' ) );
check( 'a comma string also reads back as a trimmed list', ( get_values( $route, $post_id )->get_data()['field_values'][ $key ] ?? null ) === array( 'Sharpening', 'Layout' ) );

echo "\n----------------------------------------\n";
echo "multi_badge roundtrip: {$pass} passed, {$fail} failed.\n";
exit( $fail === 0 ? 0 : 1 );
