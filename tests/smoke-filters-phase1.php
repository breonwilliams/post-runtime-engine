<?php
/**
 * Schema-driven filters — Phase 1 smoke test (self-contained, WP-free).
 *
 * Validates the declarative filter attributes added to post-field
 * definitions in PCPTPages_Validator:
 *   - filterable / sortable boolean validation
 *   - meta_pair cannot be filterable / sortable
 *   - filter_widget override compatibility with display_type
 *   - reserved URL-param / suffix collision guard on opt-in
 *   - the FILTER_WIDGETS / DISPLAY_TYPE_FILTER_WIDGETS / reserved constants
 *
 * Runs with plain PHP — no WordPress, no composer, no DB:
 *     php tests/smoke-filters-phase1.php
 *
 * The registry merge_defaults + persistence live behind WordPress (options)
 * and are covered by the integration/host pass. This targets the pure
 * validator logic that can be verified deterministically in isolation.
 *
 * Full contract: ai-section-builder-modern/docs/development/SCHEMA_DRIVEN_FILTERS_DESIGN.md
 *
 * @package PostRuntimeEngine
 */

// ---------------------------------------------------------------------------
// Minimal WordPress shims (only what the loaded class touches in these paths).
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
// Load the class under test directly.
// ---------------------------------------------------------------------------

require_once dirname( __DIR__ ) . '/includes/Core/class-pre-validator.php';

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

/** Base of a valid field; tests override individual keys. */
function field( array $overrides = array() ) {
	return array_merge(
		array(
			'key'             => 'price',
			'label'           => 'Price',
			'display_type'    => 'currency',
			'card_position'   => 'headline',
			'single_position' => 'meta_strip',
		),
		$overrides
	);
}

echo "=== Constants ===\n";

check( 'FILTER_WIDGETS has the 7 generic widgets',
	count( PCPTPages_Validator::FILTER_WIDGETS ) === 7
	&& in_array( 'range', PCPTPages_Validator::FILTER_WIDGETS, true )
	&& in_array( 'date_toggle', PCPTPages_Validator::FILTER_WIDGETS, true )
	&& in_array( 'checkbox_group', PCPTPages_Validator::FILTER_WIDGETS, true )
);

$map = PCPTPages_Validator::DISPLAY_TYPE_FILTER_WIDGETS;
check( 'meta_pair absent from DISPLAY_TYPE_FILTER_WIDGETS (display-only)', ! isset( $map['meta_pair'] ) );
check( 'currency default widget is range', ( $map['currency'][0] ?? '' ) === 'range' );
check( 'number_with_label allows range + stepper',
	in_array( 'range', $map['number_with_label'], true ) && in_array( 'stepper', $map['number_with_label'], true ) );
check( 'date allows date_toggle + date_range',
	in_array( 'date_toggle', $map['date'], true ) && in_array( 'date_range', $map['date'], true ) );
check( 'reserved suffixes include _min/_max/_when', in_array( '_min', PCPTPages_Validator::RESERVED_FILTER_SUFFIXES, true )
	&& in_array( '_max', PCPTPages_Validator::RESERVED_FILTER_SUFFIXES, true )
	&& in_array( '_when', PCPTPages_Validator::RESERVED_FILTER_SUFFIXES, true ) );
check( 'reserved params include sort + paged', in_array( 'sort', PCPTPages_Validator::RESERVED_FILTER_PARAMS, true )
	&& in_array( 'paged', PCPTPages_Validator::RESERVED_FILTER_PARAMS, true ) );

echo "\n=== filterable / sortable booleans ===\n";

$r = $validator->validate_post_field_definition( field( array( 'filterable' => true, 'sortable' => true ) ), 'listings' );
check( 'valid filterable+sortable currency field accepted', $r === true );

$r = $validator->validate_post_field_definition( field( array( 'filterable' => 'yes' ) ), 'listings' );
check( 'non-bool filterable rejected', err_code( $r ) === 'pcptpages_invalid_filter_flag' );

$r = $validator->validate_post_field_definition( field( array( 'sortable' => 1 ) ), 'listings' );
check( 'non-bool sortable rejected (int 1 is not bool)', err_code( $r ) === 'pcptpages_invalid_filter_flag' );

// Absent attributes are fine (default off, applied by the registry).
$r = $validator->validate_post_field_definition( field(), 'listings' );
check( 'field without filter attrs still valid', $r === true );

echo "\n=== meta_pair cannot be filterable / sortable ===\n";

$mp = array(
	'key'             => 'spec',
	'label'           => 'Spec',
	'display_type'    => 'meta_pair',
	'card_position'   => 'meta_strip',
	'single_position' => 'meta_strip',
);
$r = $validator->validate_post_field_definition( array_merge( $mp, array( 'filterable' => true ) ), 'listings' );
check( 'filterable meta_pair rejected', err_code( $r ) === 'pcptpages_meta_pair_not_filterable' );

$r = $validator->validate_post_field_definition( array_merge( $mp, array( 'sortable' => true ) ), 'listings' );
check( 'sortable meta_pair rejected', err_code( $r ) === 'pcptpages_meta_pair_not_filterable' );

// meta_pair WITHOUT filter opt-in is still fine.
$r = $validator->validate_post_field_definition( $mp, 'listings' );
check( 'plain meta_pair (no filter opt-in) accepted', $r === true );

echo "\n=== filter_widget override compatibility ===\n";

// number_with_label may override to stepper.
$num = array(
	'key'             => 'bedrooms',
	'label'           => 'Bedrooms',
	'display_type'    => 'number_with_label',
	'card_position'   => 'meta_strip',
	'single_position' => 'meta_strip',
	'filterable'      => true,
);
$r = $validator->validate_post_field_definition( array_merge( $num, array( 'filter_widget' => 'stepper' ) ), 'listings' );
check( 'number_with_label -> stepper override accepted', $r === true );

$r = $validator->validate_post_field_definition( array_merge( $num, array( 'filter_widget' => 'range' ) ), 'listings' );
check( 'number_with_label -> range override accepted', $r === true );

// currency may NOT override to stepper (only range is valid).
$r = $validator->validate_post_field_definition( field( array( 'filterable' => true, 'filter_widget' => 'stepper' ) ), 'listings' );
check( 'currency -> stepper override rejected', err_code( $r ) === 'pcptpages_invalid_filter_widget' );

// A widget outside the whole vocabulary is rejected.
$r = $validator->validate_post_field_definition( field( array( 'filterable' => true, 'filter_widget' => 'magic_dial' ) ), 'listings' );
check( 'unknown filter_widget rejected', err_code( $r ) === 'pcptpages_invalid_filter_widget' );

// Empty / null filter_widget means "use default" — accepted.
$r = $validator->validate_post_field_definition( field( array( 'filterable' => true, 'filter_widget' => '' ) ), 'listings' );
check( 'empty filter_widget (use default) accepted', $r === true );

// badge may override pill_select -> checkbox_group.
$badge = array(
	'key'             => 'status',
	'label'           => 'Status',
	'display_type'    => 'badge',
	'card_position'   => 'image_overlay',
	'single_position' => 'meta_strip',
	'filterable'      => true,
);
$r = $validator->validate_post_field_definition( array_merge( $badge, array( 'filter_widget' => 'checkbox_group' ) ), 'listings' );
check( 'badge -> checkbox_group override accepted', $r === true );

echo "\n=== reserved key collision guard (only on opt-in) ===\n";

// Field key ending in a reserved suffix, opted into filtering -> rejected.
$r = $validator->validate_post_field_definition(
	field( array( 'key' => 'price_max', 'filterable' => true ) ),
	'listings'
);
check( 'filterable field key ending _max rejected', err_code( $r ) === 'pcptpages_field_key_reserved_suffix' );

// Field key equal to a reserved param -> rejected.
$r = $validator->validate_post_field_definition(
	field( array( 'key' => 'sort', 'display_type' => 'text', 'sortable' => true ) ),
	'listings'
);
check( 'sortable field key "sort" rejected', err_code( $r ) === 'pcptpages_field_key_reserved_param' );

// The SAME collision-shaped key is fine when NOT opted in (back-compat).
$r = $validator->validate_post_field_definition(
	field( array( 'key' => 'price_max' ) ),
	'listings'
);
check( 'non-filtered field key ending _max still accepted (no opt-in)', $r === true );

echo "\n----------------------------------------\n";
echo "Filters Phase 1 smoke: {$pass} passed, {$fail} failed.\n";
exit( $fail === 0 ? 0 : 1 );
