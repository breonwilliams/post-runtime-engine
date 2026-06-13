<?php
/**
 * Events vertical — Phase 1 smoke test (self-contained, WP-free).
 *
 * Validates the data-layer extensions added for the events vertical:
 *   - PCPTPages_Validator: all_day / event_timezone / semantic_role rules
 *     and the role <-> display_type pairing.
 *   - PCPTPages_Post_Data::compute_date_sort_keys(): the pure normalized
 *     sort + UTC companion math, including timezone/DST correctness.
 *
 * Runs with plain PHP — no WordPress, no composer, no DB:
 *     php tests/smoke-events-phase1.php
 *
 * The CPT-level role-uniqueness guard and the write_field_value companion
 * gating live behind WordPress (options + post meta) and are covered by the
 * integration/manual pass, not here. This script targets the pure logic that
 * can be verified deterministically in isolation.
 *
 * @package PostRuntimeEngine
 */

// ---------------------------------------------------------------------------
// Minimal WordPress shims (only what the loaded classes touch in these paths).
// ---------------------------------------------------------------------------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

// ---------------------------------------------------------------------------
// Load the classes under test directly.
// ---------------------------------------------------------------------------

$base = dirname( __DIR__ ) . '/includes/Core/';
require_once $base . 'class-pre-validator.php';
require_once $base . 'class-pre-post-data.php';

// ---------------------------------------------------------------------------
// Tiny assertion harness.
// ---------------------------------------------------------------------------

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

function err_code( $result ) {
	return ( $result instanceof WP_Error ) ? $result->get_error_code() : '(not-an-error)';
}

$validator = new PCPTPages_Validator();

// Base of a valid date field; tests override individual keys.
function date_field( array $overrides = array() ) {
	return array_merge(
		array(
			'key'             => 'event_start',
			'label'           => 'Starts',
			'display_type'    => 'date',
			'card_position'   => 'headline',
			'single_position' => 'meta_strip',
		),
		$overrides
	);
}

echo "=== Validator: events attributes ===\n";

// A. Valid event_start date field (timed).
$r = $validator->validate_post_field_definition(
	date_field( array( 'semantic_role' => 'event_start', 'all_day' => false ) ),
	'workshops'
);
check( 'valid event_start date field accepted', $r === true );

// B. Valid all_day true accepted.
$r = $validator->validate_post_field_definition(
	date_field( array( 'semantic_role' => 'event_start', 'all_day' => true ) ),
	'workshops'
);
check( 'all_day=true accepted', $r === true );

// C. Valid event_timezone accepted.
$r = $validator->validate_post_field_definition(
	date_field( array( 'semantic_role' => 'event_start', 'event_timezone' => 'America/New_York' ) ),
	'workshops'
);
check( 'valid event_timezone accepted', $r === true );

// D. Role on wrong display_type rejected (event_start needs date, given currency).
$r = $validator->validate_post_field_definition(
	array(
		'key'             => 'price',
		'label'           => 'Price',
		'display_type'    => 'currency',
		'card_position'   => 'headline',
		'single_position' => 'headline',
		'semantic_role'   => 'event_start',
	),
	'workshops'
);
check( 'role/display_type mismatch rejected', err_code( $r ) === 'pcptpages_role_display_type_mismatch' );

// E. event_status pairs with badge (valid).
$r = $validator->validate_post_field_definition(
	array(
		'key'             => 'status',
		'label'           => 'Status',
		'display_type'    => 'badge',
		'card_position'   => 'image_overlay',
		'single_position' => 'subtitle',
		'semantic_role'   => 'event_status',
	),
	'workshops'
);
check( 'event_status on badge accepted', $r === true );

// F. Unknown semantic_role rejected.
$r = $validator->validate_post_field_definition(
	date_field( array( 'semantic_role' => 'event_middle' ) ),
	'workshops'
);
check( 'unknown semantic_role rejected', err_code( $r ) === 'pcptpages_invalid_semantic_role' );

// G. Invalid event_timezone rejected.
$r = $validator->validate_post_field_definition(
	date_field( array( 'semantic_role' => 'event_start', 'event_timezone' => 'Mars/Olympus_Mons' ) ),
	'workshops'
);
check( 'invalid event_timezone rejected', err_code( $r ) === 'pcptpages_invalid_event_timezone' );

// H. Non-boolean all_day rejected.
$r = $validator->validate_post_field_definition(
	date_field( array( 'semantic_role' => 'event_start', 'all_day' => 'yes' ) ),
	'workshops'
);
check( 'non-boolean all_day rejected', err_code( $r ) === 'pcptpages_invalid_all_day' );

// I. A plain date field with no role still validates (backward compat).
$r = $validator->validate_post_field_definition( date_field(), 'workshops' );
check( 'plain date field (no role) still valid', $r === true );

echo "\n=== compute_date_sort_keys(): normalization + tz/DST ===\n";

// J. Timed event in America/New_York (Sept = EDT, UTC-4): 19:00 -> 23:00 UTC.
$c = PCPTPages_Post_Data::compute_date_sort_keys( '2026-09-05 19:00:00', false, 'America/New_York', 'UTC' );
check( 'timed sort key = 20260905190000', $c['sort'] === 20260905190000 );
check( 'timed UTC = 2026-09-05 23:00:00', $c['utc'] !== null && gmdate( 'Y-m-d H:i:s', $c['utc'] ) === '2026-09-05 23:00:00' );

// K. All-day forces midnight; NY March 15 (EDT, UTC-4): 00:00 -> 04:00 UTC.
$c = PCPTPages_Post_Data::compute_date_sort_keys( '2026-03-15', true, 'America/New_York', 'UTC' );
check( 'all-day sort key = 20260315000000', $c['sort'] === 20260315000000 );
check( 'all-day UTC midnight = 2026-03-15 04:00:00', $c['utc'] !== null && gmdate( 'Y-m-d H:i:s', $c['utc'] ) === '2026-03-15 04:00:00' );

// L. DST awareness: same wall time, winter vs summer, different UTC offset.
$winter = PCPTPages_Post_Data::compute_date_sort_keys( '2026-01-15 12:00:00', false, 'America/New_York', 'UTC' );
$summer = PCPTPages_Post_Data::compute_date_sort_keys( '2026-07-15 12:00:00', false, 'America/New_York', 'UTC' );
check( 'winter noon NY = 17:00 UTC (EST -5)', gmdate( 'H:i', $winter['utc'] ) === '17:00' );
check( 'summer noon NY = 16:00 UTC (EDT -4)', gmdate( 'H:i', $summer['utc'] ) === '16:00' );

// M. Empty event_tz falls back to site timezone (UTC here): no offset shift.
$c = PCPTPages_Post_Data::compute_date_sort_keys( '2026-06-01 09:30:00', false, '', 'UTC' );
check( 'site-tz fallback UTC = 2026-06-01 09:30:00', gmdate( 'Y-m-d H:i:s', $c['utc'] ) === '2026-06-01 09:30:00' );

// N. H:i (no seconds) padded correctly in the sort key.
$c = PCPTPages_Post_Data::compute_date_sort_keys( '2026-06-01 09:30', false, '', 'UTC' );
check( 'H:i padded to sort 20260601093000', $c['sort'] === 20260601093000 );

// O. Empty value -> null/null (caller deletes companions).
$c = PCPTPages_Post_Data::compute_date_sort_keys( '', false, '', 'UTC' );
check( 'empty value yields null sort + null utc', $c['sort'] === null && $c['utc'] === null );

// P. Garbage value -> null/null.
$c = PCPTPages_Post_Data::compute_date_sort_keys( 'not-a-date', false, '', 'UTC' );
check( 'unparseable value yields null/null', $c['sort'] === null && $c['utc'] === null );

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------

echo "\n----------------------------------------\n";
echo "Events Phase 1 smoke: {$pass} passed, {$fail} failed.\n";
exit( $fail === 0 ? 0 : 1 );
