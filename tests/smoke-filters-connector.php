<?php
/**
 * Schema-driven filters — connector discoverability smoke test (WP-free).
 *
 * Verifies the PRE connector PREFLIGHT actually advertises the filter surface
 * so Cowork/Claude can discover it without trial and error:
 *   - get_post_field_enums() exposes `filter_widgets` (display_type -> widget).
 *   - get_field_name_hints().post_field_definition includes filterable /
 *     sortable / filter_widget.
 *   - get_critical_rules() includes filterable_archive_setup, and it names the
 *     two-plugin workflow (filterable/sortable here, enable_filters on PostGrid).
 *
 * Runs with plain PHP — no WordPress:
 *     php tests/smoke-filters-connector.php
 *
 * @package PostRuntimeEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}
if ( ! function_exists( '__' ) ) {
	function __( $t, $d = 'default' ) { return $t; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; }
}

$core = dirname( __DIR__ ) . '/includes/';
require_once $core . 'Core/class-pre-validator.php';
require_once $core . 'Connector/class-pre-connector-api.php';

$pass = 0;
$fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: {$label}\n"; }
	else { $fail++; echo "FAIL: {$label}\n"; }
}

function call_priv( $method ) {
	$m = new ReflectionMethod( 'PCPTPages_Connector_API', $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true );
	}
	return $m->invoke( null );
}

echo "=== preflight: post_field_enums.filter_widgets ===\n";
$enums = call_priv( 'get_post_field_enums' );
check( 'filter_widgets present', isset( $enums['filter_widgets'] ) && is_array( $enums['filter_widgets'] ) );
$fw = $enums['filter_widgets'] ?? array();
check( 'currency -> range default', ( $fw['currency'][0] ?? '' ) === 'range' );
check( 'number_with_label allows range + stepper',
	isset( $fw['number_with_label'] ) && in_array( 'range', $fw['number_with_label'], true ) && in_array( 'stepper', $fw['number_with_label'], true ) );
check( 'date allows date_toggle + date_range',
	isset( $fw['date'] ) && in_array( 'date_toggle', $fw['date'], true ) && in_array( 'date_range', $fw['date'], true ) );
check( 'meta_pair flagged not filterable', isset( $fw['meta_pair'] ) && is_string( $fw['meta_pair'] ) );

echo "\n=== preflight: field_name_hints ===\n";
$hints = call_priv( 'get_field_name_hints' );
$def_keys = $hints['post_field_definition'] ?? array();
check( 'post_field_definition includes filterable', in_array( 'filterable', $def_keys, true ) );
check( 'post_field_definition includes sortable', in_array( 'sortable', $def_keys, true ) );
check( 'post_field_definition includes filter_widget', in_array( 'filter_widget', $def_keys, true ) );
$notes = (string) ( $hints['notes'] ?? '' );
check( 'notes reference filterable_archive_setup (no stale "in progress")',
	strpos( $notes, 'filterable_archive_setup' ) !== false && strpos( $notes, 'in progress' ) === false );

echo "\n=== preflight: critical_rules.filterable_archive_setup ===\n";
$rules = call_priv( 'get_critical_rules' );
check( 'filterable_archive_setup rule present', isset( $rules['filterable_archive_setup'] ) );
$txt = (string) ( $rules['filterable_archive_setup'] ?? '' );
check( 'rule mentions filterable + sortable', strpos( $txt, 'filterable' ) !== false && strpos( $txt, 'sortable' ) !== false );
check( 'rule mentions enable_filters (PostGrid side)', strpos( $txt, 'enable_filters' ) !== false );
check( 'rule mentions filter_layout', strpos( $txt, 'filter_layout' ) !== false );
check( 'rule mentions default_sort', strpos( $txt, 'default_sort' ) !== false );

echo "\n----------------------------------------\n";
echo "Filters connector smoke: {$pass} passed, {$fail} failed.\n";
exit( $fail === 0 ? 0 : 1 );
