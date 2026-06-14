<?php
/**
 * Schema-driven filters — Phase 2 smoke test (self-contained, WP-free).
 *
 * Exercises the pure descriptor-mapping logic in PCPTPages_Filter_Descriptors:
 *   - resolve_widget(): display_type + filter_widget override + date role
 *   - params_for_widget(): URL param-name conventions per widget
 *   - query_for(): the self-describing query block (mode / handler / type)
 *   - options_for_field() + range_for_field()
 *   - build_field_descriptor(): end-to-end field -> descriptor shape
 *
 * The WP-dependent paths (build_for_cpt's plugin lookup, taxonomy descriptors,
 * the REST route, the aisb_postgrid_available_filters hook) live behind
 * WordPress and are covered by the host kitchen-sink pass (Phase 7).
 *
 * Runs with plain PHP — no WordPress, no composer, no DB:
 *     php tests/smoke-filters-phase2.php
 *
 * @package PostRuntimeEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}
if ( ! function_exists( '__' ) ) {
	function __( $t, $d = 'default' ) { return $t; }
}

// Stub only the constant the builder reads from Post_Data; the real class
// pulls in WordPress, which we deliberately avoid here.
if ( ! class_exists( 'PCPTPages_Post_Data' ) ) {
	class PCPTPages_Post_Data {
		const FIELD_VALUE_META_PREFIX = '_pcptpages_field_';
		const FIELD_SORT_SUFFIX       = '__sort';
		const FIELD_UTC_SUFFIX        = '__utc';
	}
}

require_once dirname( __DIR__ ) . '/includes/Core/class-pre-validator.php';
require_once dirname( __DIR__ ) . '/includes/Frontend/class-pre-filter-descriptors.php';

$pass = 0;
$fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: {$label}\n"; }
	else { $fail++; echo "FAIL: {$label}\n"; }
}

/** Invoke a private static method on the descriptors class. */
function call_priv( $method, array $args ) {
	$m = new ReflectionMethod( 'PCPTPages_Filter_Descriptors', $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true );
	}
	return $m->invokeArgs( null, $args );
}

function field( array $o = array() ) {
	return array_merge(
		array( 'key' => 'price', 'label' => 'Price', 'display_type' => 'currency', 'filterable' => true ),
		$o
	);
}

echo "=== resolve_widget ===\n";
check( 'currency -> range',            call_priv( 'resolve_widget', array( 'price', field(), 'listings' ) ) === 'range' );
check( 'number_with_label -> range',   call_priv( 'resolve_widget', array( 'beds', field( array( 'display_type' => 'number_with_label' ) ), 'listings' ) ) === 'range' );
check( 'number + override stepper',    call_priv( 'resolve_widget', array( 'beds', field( array( 'display_type' => 'number_with_label', 'filter_widget' => 'stepper' ) ), 'listings' ) ) === 'stepper' );
check( 'rating -> stepper',            call_priv( 'resolve_widget', array( 'stars', field( array( 'display_type' => 'rating' ) ), 'listings' ) ) === 'stepper' );
check( 'progress -> range',            call_priv( 'resolve_widget', array( 'pct', field( array( 'display_type' => 'progress' ) ), 'listings' ) ) === 'range' );
check( 'badge -> pill_select',         call_priv( 'resolve_widget', array( 'status', field( array( 'display_type' => 'badge' ) ), 'listings' ) ) === 'pill_select' );
check( 'badge + override checkbox',    call_priv( 'resolve_widget', array( 'status', field( array( 'display_type' => 'badge', 'filter_widget' => 'checkbox_group' ) ), 'listings' ) ) === 'checkbox_group' );
check( 'multi_badge -> checkbox_group',call_priv( 'resolve_widget', array( 'tags', field( array( 'display_type' => 'multi_badge' ) ), 'listings' ) ) === 'checkbox_group' );
check( 'text -> text_search',          call_priv( 'resolve_widget', array( 'title', field( array( 'display_type' => 'text' ) ), 'listings' ) ) === 'text_search' );
check( 'date + event_start -> date_toggle', call_priv( 'resolve_widget', array( 'start', field( array( 'display_type' => 'date', 'semantic_role' => 'event_start' ) ), 'workshops' ) ) === 'date_toggle' );
check( 'date + event role + override date_range', call_priv( 'resolve_widget', array( 'start', field( array( 'display_type' => 'date', 'semantic_role' => 'event_start', 'filter_widget' => 'date_range' ) ), 'workshops' ) ) === 'date_range' );
check( 'date no role -> date_range',   call_priv( 'resolve_widget', array( 'deadline', field( array( 'display_type' => 'date' ) ), 'listings' ) ) === 'date_range' );
check( 'meta_pair -> "" (not filterable)', call_priv( 'resolve_widget', array( 'spec', field( array( 'display_type' => 'meta_pair' ) ), 'listings' ) ) === '' );

echo "\n=== params_for_widget ===\n";
$p = call_priv( 'params_for_widget', array( 'price', 'range' ) );
check( 'range params min/max', ( $p['min'] ?? '' ) === 'price_min' && ( $p['max'] ?? '' ) === 'price_max' );
$p = call_priv( 'params_for_widget', array( 'start', 'date_toggle' ) );
check( 'date_toggle param when', ( $p['when'] ?? '' ) === 'start_when' );
$p = call_priv( 'params_for_widget', array( 'deadline', 'date_range' ) );
check( 'date_range params after/before', ( $p['after'] ?? '' ) === 'deadline_after' && ( $p['before'] ?? '' ) === 'deadline_before' );
$p = call_priv( 'params_for_widget', array( 'title', 'text_search' ) );
check( 'text_search param q', ( $p['q'] ?? '' ) === 'title_q' );
$p = call_priv( 'params_for_widget', array( 'tags', 'checkbox_group' ) );
check( 'checkbox_group param values', ( $p['values'] ?? '' ) === 'tags' );
$p = call_priv( 'params_for_widget', array( 'status', 'pill_select' ) );
check( 'pill_select param value', ( $p['value'] ?? '' ) === 'status' );

echo "\n=== query_for ===\n";
$mk = '_pcptpages_field_price';
$q = call_priv( 'query_for', array( 'listings', 'price', field(), 'range', $mk ) );
check( 'range -> meta_range NUMERIC generic', $q['mode'] === 'meta_range' && $q['type'] === 'NUMERIC' && $q['handler'] === 'generic' && $q['meta_key'] === $mk );
$q = call_priv( 'query_for', array( 'listings', 'beds', field(), 'stepper', '_pcptpages_field_beds' ) );
check( 'stepper -> meta_min NUMERIC', $q['mode'] === 'meta_min' && $q['type'] === 'NUMERIC' );
$q = call_priv( 'query_for', array( 'listings', 'status', field(), 'pill_select', '_pcptpages_field_status' ) );
check( 'pill_select -> meta_equals', $q['mode'] === 'meta_equals' );
$q = call_priv( 'query_for', array( 'listings', 'tags', field(), 'checkbox_group', '_pcptpages_field_tags' ) );
check( 'checkbox_group -> meta_in_csv', $q['mode'] === 'meta_in_csv' );
$q = call_priv( 'query_for', array( 'listings', 'title', field(), 'text_search', '_pcptpages_field_title' ) );
check( 'text_search -> meta_like', $q['mode'] === 'meta_like' );
$q = call_priv( 'query_for', array( 'listings', 'deadline', field(), 'date_range', '_pcptpages_field_deadline' ) );
check( 'date_range -> date_range DATETIME', $q['mode'] === 'date_range' && $q['type'] === 'DATETIME' );
$q = call_priv( 'query_for', array( 'workshops', 'start', field(), 'date_toggle', '_pcptpages_field_start' ) );
check( 'date_toggle -> event_status provider-handled, carries cpt', $q['mode'] === 'event_status' && $q['handler'] === 'provider' && $q['cpt'] === 'workshops' );

echo "\n=== options_for_field ===\n";
$opts = call_priv( 'options_for_field', array( array( 'options' => array(
	'scheduled' => array( 'label' => 'Scheduled' ),
	'cancelled' => array( 'label' => 'Cancelled' ),
) ) ) );
check( 'badge options mapped to value/label list',
	count( $opts ) === 2
	&& $opts[0]['value'] === 'scheduled' && $opts[0]['label'] === 'Scheduled'
	&& $opts[1]['value'] === 'cancelled' && $opts[1]['label'] === 'Cancelled'
);

echo "\n=== range_for_field ===\n";
$r = call_priv( 'range_for_field', array( array( 'display_type' => 'rating' ) ) );
check( 'rating range default max 5', $r['max'] === 5 && $r['min'] === 0 );
$r = call_priv( 'range_for_field', array( array( 'display_type' => 'progress' ) ) );
check( 'progress range default max 100', $r['max'] === 100 );
$r = call_priv( 'range_for_field', array( array( 'display_type' => 'rating', 'max' => 10 ) ) );
check( 'rating honors declared max', $r['max'] === 10.0 );
$r = call_priv( 'range_for_field', array( array( 'display_type' => 'currency' ) ) );
check( 'currency range open-ended (max null)', $r['max'] === null && $r['min'] === 0 );

echo "\n=== build_field_descriptor (end-to-end shape) ===\n";
$d = call_priv( 'build_field_descriptor', array( 'listings', 'price', field( array( 'sortable' => true ) ) ) );
check( 'currency descriptor: widget range', $d['widget'] === 'range' );
check( 'currency descriptor: query meta_key resolved', $d['query']['meta_key'] === '_pcptpages_field_price' );
check( 'currency descriptor: sortable + sort_param', $d['sortable'] === true && $d['sort_param'] === 'price' );
check( 'currency descriptor: range present, options null', is_array( $d['range'] ) && $d['options'] === null );

$d = call_priv( 'build_field_descriptor', array( 'workshops', 'event_date', field( array( 'display_type' => 'date', 'semantic_role' => 'event_start', 'label' => 'Date' ) ) ) );
check( 'event date descriptor: widget date_toggle', $d['widget'] === 'date_toggle' );
check( 'event date descriptor: provider-handled event_status', $d['query']['handler'] === 'provider' && $d['query']['mode'] === 'event_status' );
check( 'event date descriptor: when param', ( $d['params']['when'] ?? '' ) === 'event_date_when' );

$d = call_priv( 'build_field_descriptor', array( 'listings', 'spec', field( array( 'display_type' => 'meta_pair' ) ) ) );
check( 'meta_pair field yields null descriptor (not filterable)', $d === null );

echo "\n----------------------------------------\n";
echo "Filters Phase 2 smoke: {$pass} passed, {$fail} failed.\n";
exit( $fail === 0 ? 0 : 1 );
