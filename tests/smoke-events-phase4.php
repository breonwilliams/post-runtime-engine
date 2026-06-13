<?php
/**
 * Events vertical — Phase 4 smoke test (self-contained, WP-free).
 *
 * Validates the pure pieces of the Event JSON-LD emitter:
 *   - format_schema_date(): all-day date-only vs timed ISO-8601-with-offset,
 *     timezone/DST correctness, and graceful degradation.
 *   - map_event_status() / map_attendance_mode(): value → schema.org URI maps.
 *
 * Runs with plain PHP — no WordPress, no DB:
 *     php tests/smoke-events-phase4.php
 *
 * The wp_head gating + full schema assembly (build_schema) read post meta and
 * the registry, so they are covered by the integration / manual pass.
 *
 * @package PostRuntimeEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

require_once dirname( __DIR__ ) . '/includes/Frontend/class-pre-event-schema.php';

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

echo "=== format_schema_date(): all-day vs timed, tz/DST ===\n";

// Timed, America/New_York September = EDT (-04:00). Local time preserved + offset.
$d = PCPTPages_Event_Schema::format_schema_date( '2026-09-05 19:00:00', false, 'America/New_York', 'UTC' );
check( 'timed NY EDT => 2026-09-05T19:00:00-04:00', $d === '2026-09-05T19:00:00-04:00' );

// Timed, January = EST (-05:00).
$d = PCPTPages_Event_Schema::format_schema_date( '2026-01-15 12:00:00', false, 'America/New_York', 'UTC' );
check( 'timed NY EST => 2026-01-15T12:00:00-05:00', $d === '2026-01-15T12:00:00-05:00' );

// All-day => date-only, no time, no offset.
$d = PCPTPages_Event_Schema::format_schema_date( '2026-03-15 00:00:00', true, 'America/New_York', 'UTC' );
check( 'all-day => date-only 2026-03-15', $d === '2026-03-15' );

// Non-all-day value with no time component => date-only.
$d = PCPTPages_Event_Schema::format_schema_date( '2026-06-01', false, 'America/New_York', 'UTC' );
check( 'date-only input (not all-day) => 2026-06-01', $d === '2026-06-01' );

// H:i (no seconds) accepted and padded.
$d = PCPTPages_Event_Schema::format_schema_date( '2026-06-01 09:30', false, 'UTC', 'UTC' );
check( 'H:i timed UTC => 2026-06-01T09:30:00+00:00', $d === '2026-06-01T09:30:00+00:00' );

// Empty / garbage => ''.
check( 'empty value => empty string', PCPTPages_Event_Schema::format_schema_date( '', false, '', 'UTC' ) === '' );
check( 'garbage value => empty string', PCPTPages_Event_Schema::format_schema_date( 'nope', false, '', 'UTC' ) === '' );

echo "\n=== map_event_status() ===\n";
check( "'cancelled' => EventCancelled", PCPTPages_Event_Schema::map_event_status( 'cancelled' ) === 'https://schema.org/EventCancelled' );
check( "'Scheduled' (case-insensitive) => EventScheduled", PCPTPages_Event_Schema::map_event_status( 'Scheduled' ) === 'https://schema.org/EventScheduled' );
check( "'moved_online' => EventMovedOnline", PCPTPages_Event_Schema::map_event_status( 'moved_online' ) === 'https://schema.org/EventMovedOnline' );
check( "unknown status => ''", PCPTPages_Event_Schema::map_event_status( 'whatever' ) === '' );

echo "\n=== map_attendance_mode() ===\n";
check( "'online' => OnlineEventAttendanceMode", PCPTPages_Event_Schema::map_attendance_mode( 'online' ) === 'https://schema.org/OnlineEventAttendanceMode' );
check( "'in_person' => OfflineEventAttendanceMode", PCPTPages_Event_Schema::map_attendance_mode( 'in_person' ) === 'https://schema.org/OfflineEventAttendanceMode' );
check( "'mixed' => MixedEventAttendanceMode", PCPTPages_Event_Schema::map_attendance_mode( 'mixed' ) === 'https://schema.org/MixedEventAttendanceMode' );
check( "unknown mode => ''", PCPTPages_Event_Schema::map_attendance_mode( 'x' ) === '' );

echo "\n----------------------------------------\n";
echo "Events Phase 4 smoke: {$pass} passed, {$fail} failed.\n";
exit( $fail === 0 ? 0 : 1 );
