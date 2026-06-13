<?php
/**
 * Events vertical — connector discoverability smoke test (WP-free).
 *
 * Verifies that the PRE connector PREFLIGHT actually advertises the events
 * surface, so Cowork/Claude can discover it. Also doubles as a parse check
 * for class-pre-connector-api.php (it must load for this to run).
 *
 * Asserts:
 *   - get_post_field_enums() exposes semantic_roles (6 roles, correct
 *     role->display_type pairing) + date_field_event_attributes.
 *   - get_field_name_hints().post_field_definition includes the 3 new
 *     attributes (so they aren't reported as "silently dropped").
 *   - get_critical_rules() includes the events_archive_setup walkthrough.
 *
 * Runs with plain PHP — no WordPress:
 *     php tests/smoke-events-connector.php
 *
 * The write/read passthrough + role validation are covered by the live
 * connector test on the host and by smoke-events-phase1.php (validator).
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
require_once $core . 'Core/class-pre-validator.php';        // constants used by the enums
require_once $core . 'Connector/class-pre-connector-api.php'; // class under test (parse check)

$pass = 0;
$fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: {$label}\n"; }
	else { $fail++; echo "FAIL: {$label}\n"; }
}

/** Invoke a private static method on the connector API. */
function call_private_static( $method ) {
	$m = new ReflectionMethod( 'PCPTPages_Connector_API', $method );
	// setAccessible() is required on PHP < 8.1 and a deprecated no-op on 8.1+.
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true );
	}
	return $m->invoke( null );
}

echo "=== preflight: post_field_enums ===\n";
$enums = call_private_static( 'get_post_field_enums' );

check( 'semantic_roles present', isset( $enums['semantic_roles'] ) && is_array( $enums['semantic_roles'] ) );
$roles = array();
foreach ( $enums['semantic_roles'] ?? array() as $r ) {
	$roles[ $r['value'] ] = $r['display_type'];
}
$expected = array(
	'event_start'           => 'date',
	'event_end'             => 'date',
	'event_status'          => 'badge',
	'event_location'        => 'text',
	'event_offers'          => 'currency',
	'event_attendance_mode' => 'badge',
);
check( 'all 6 roles present', count( $roles ) === 6 );
$pairing_ok = true;
foreach ( $expected as $role => $dt ) {
	if ( ( $roles[ $role ] ?? null ) !== $dt ) { $pairing_ok = false; }
}
check( 'role -> display_type pairing correct (event_start=date, event_status=badge, ...)', $pairing_ok );
check( 'date_field_event_attributes advertises all_day + event_timezone',
	isset( $enums['date_field_event_attributes']['all_day'], $enums['date_field_event_attributes']['event_timezone'] ) );

echo "\n=== preflight: field_name_hints ===\n";
$hints = call_private_static( 'get_field_name_hints' );
$def_keys = $hints['post_field_definition'] ?? array();
check( 'post_field_definition hint includes semantic_role', in_array( 'semantic_role', $def_keys, true ) );
check( 'post_field_definition hint includes all_day', in_array( 'all_day', $def_keys, true ) );
check( 'post_field_definition hint includes event_timezone', in_array( 'event_timezone', $def_keys, true ) );

echo "\n=== preflight: critical_rules ===\n";
$rules = call_private_static( 'get_critical_rules' );
check( 'events_archive_setup rule present', isset( $rules['events_archive_setup'] ) );
$txt = (string) ( $rules['events_archive_setup'] ?? '' );
check( 'events rule mentions event_start (the required role)', strpos( $txt, 'event_start' ) !== false );
check( 'events rule mentions the PostGrid event_status step', strpos( $txt, 'event_status' ) !== false );
check( 'events rule mentions event_sort', strpos( $txt, 'event_sort' ) !== false );

echo "\n----------------------------------------\n";
echo "Events connector smoke: {$pass} passed, {$fail} failed.\n";
exit( $fail === 0 ? 0 : 1 );
