<?php
/**
 * Events vertical — Phase 2 smoke test (self-contained, WP-free).
 *
 * Validates the pure query builders in PCPTPages_Event_Query:
 *   - build_status_meta_query(): upcoming / happening / past clause shapes,
 *     END-date anchoring, and the no-end-key fallback to the start key.
 *   - resolve_sort_direction(): explicit vs auto defaulting.
 *   - sort_meta_key(): companion meta-key construction.
 *
 * Runs with plain PHP — no WordPress, no DB:
 *     php tests/smoke-events-phase2.php
 *
 * The WP-aware composers (status_meta_query / sort_args / is_event_cpt) and
 * the PostGrid query-args handler resolve field keys through the registry
 * and are exercised in the integration / manual pass, not here.
 *
 * @package PostRuntimeEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

$base = dirname( __DIR__ ) . '/includes/Core/';
require_once $base . 'class-pre-post-data.php';   // for the companion-key constants
require_once $base . 'class-pre-event-query.php';

$pass = 0;
$fail = 0;
function check( $label, $condition ) {
	global $pass, $fail;
	if ( $condition ) {
		$pass++;
		echo "PASS: {$label}\n";
	} else {
		$fail++;
		echo "FAIL: {$label}\n";
	}
}

$NOW = 20260601120000; // 2026-06-01 12:00:00 as YYYYMMDDHHMMSS

echo "=== build_status_meta_query(): clause shapes ===\n";

// Upcoming anchors on the END key (in-progress multi-day events stay upcoming).
$mq = PCPTPages_Event_Query::build_status_meta_query( 'S', 'E', 'upcoming', $NOW );
check( 'upcoming: single clause', is_array( $mq ) && count( $mq ) === 1 && isset( $mq[0] ) );
check( 'upcoming: anchors on END key', $mq[0]['key'] === 'E' );
check( 'upcoming: compare >=', $mq[0]['compare'] === '>=' );
check( 'upcoming: numeric value = now', $mq[0]['value'] === $NOW && $mq[0]['type'] === 'NUMERIC' );

// Past anchors on END key with strict <.
$mq = PCPTPages_Event_Query::build_status_meta_query( 'S', 'E', 'past', $NOW );
check( 'past: anchors on END key, compare <', $mq[0]['key'] === 'E' && $mq[0]['compare'] === '<' );

// Happening: start <= now AND end >= now.
$mq = PCPTPages_Event_Query::build_status_meta_query( 'S', 'E', 'happening', $NOW );
check( 'happening: relation AND', isset( $mq['relation'] ) && $mq['relation'] === 'AND' );
check( 'happening: start clause <= now', $mq[0]['key'] === 'S' && $mq[0]['compare'] === '<=' );
check( 'happening: end clause >= now', $mq[1]['key'] === 'E' && $mq[1]['compare'] === '>=' );

echo "\n=== end-key fallback + guards ===\n";

// No end key -> upcoming falls back to the START key as the anchor.
$mq = PCPTPages_Event_Query::build_status_meta_query( 'S', '', 'upcoming', $NOW );
check( 'no end key: upcoming anchors on START key', $mq[0]['key'] === 'S' );

// No end key -> happening uses START key for both bounds.
$mq = PCPTPages_Event_Query::build_status_meta_query( 'S', '', 'happening', $NOW );
check( 'no end key: happening both bounds on START key', $mq[0]['key'] === 'S' && $mq[1]['key'] === 'S' );

// Invalid status -> empty.
$mq = PCPTPages_Event_Query::build_status_meta_query( 'S', 'E', 'someday', $NOW );
check( 'invalid status yields empty group', $mq === array() );

// Empty start key -> empty (can't build a query without a start anchor).
$mq = PCPTPages_Event_Query::build_status_meta_query( '', 'E', 'upcoming', $NOW );
check( 'empty start key yields empty group', $mq === array() );

echo "\n=== resolve_sort_direction() ===\n";
check( 'explicit soonest honored', PCPTPages_Event_Query::resolve_sort_direction( 'past', 'soonest' ) === 'soonest' );
check( 'explicit latest honored', PCPTPages_Event_Query::resolve_sort_direction( 'upcoming', 'latest' ) === 'latest' );
check( 'auto + past => latest', PCPTPages_Event_Query::resolve_sort_direction( 'past', 'auto' ) === 'latest' );
check( 'auto + upcoming => soonest', PCPTPages_Event_Query::resolve_sort_direction( 'upcoming', 'auto' ) === 'soonest' );
check( 'auto + happening => soonest', PCPTPages_Event_Query::resolve_sort_direction( 'happening', 'auto' ) === 'soonest' );

echo "\n=== sort_meta_key() ===\n";
check(
	'sort_meta_key builds _pcptpages_field_event_start__sort',
	PCPTPages_Event_Query::sort_meta_key( 'event_start' ) === '_pcptpages_field_event_start__sort'
);

echo "\n----------------------------------------\n";
echo "Events Phase 2 smoke: {$pass} passed, {$fail} failed.\n";
exit( $fail === 0 ? 0 : 1 );
