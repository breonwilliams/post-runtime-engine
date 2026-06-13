<?php
/**
 * Event query helper (events vertical, v1.2).
 *
 * Translates an event date-status request (upcoming / happening / past) into
 * a WP_Query `meta_query` + ordering, keyed on the normalized `__sort`
 * companion meta that PCPTPages_Post_Data writes for date fields tagged with
 * a semantic role (event_start / event_end).
 *
 * Design contract: docs/EVENTS_VERTICAL_DESIGN.md § 6.
 *
 * Status semantics (END-date anchored, so a multi-day event that is already
 * in progress still counts as upcoming / happening rather than past):
 *
 *   upcoming  — end   >= now            (includes in-progress events)
 *   happening — start <= now AND end >= now
 *   past      — end   <  now
 *
 * When a CPT maps no `event_end` role, the end anchor falls back to the
 * `event_start` companion so single-instant events still filter correctly.
 *
 * The pure builders (build_status_meta_query, resolve_sort_direction) take
 * already-resolved keys + a caller-supplied "now" so they are unit-testable
 * without WordPress. The WP-aware composers (status_meta_query, sort_args,
 * is_event_cpt) resolve field keys through the post-field registry.
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds event date-status meta_query + ordering fragments.
 */
class PCPTPages_Event_Query {

	/**
	 * Valid status tokens.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'upcoming', 'happening', 'past' );

	/**
	 * Resolve the field key that holds a given semantic role on a CPT.
	 * Returns the first matching field key, or '' if none is mapped.
	 *
	 * @param string $cpt_slug CPT slug.
	 * @param string $role     Semantic role (see PCPTPages_Validator::SEMANTIC_ROLES).
	 * @return string
	 */
	public static function resolve_role_field_key( $cpt_slug, $role ) {
		$plugin = function_exists( 'pcptpages' ) ? pcptpages() : null;
		if ( ! $plugin || ! $plugin->post_fields ) {
			return '';
		}
		$defs = $plugin->post_fields->get_all( $cpt_slug );
		if ( ! is_array( $defs ) ) {
			return '';
		}
		foreach ( $defs as $key => $def ) {
			if ( is_array( $def ) && ( $def['semantic_role'] ?? '' ) === $role ) {
				return (string) $key;
			}
		}
		return '';
	}

	/**
	 * Whether a CPT is "event-shaped" — i.e. it maps an event_start role.
	 *
	 * @param string $cpt_slug CPT slug.
	 * @return bool
	 */
	public static function is_event_cpt( $cpt_slug ) {
		return self::resolve_role_field_key( $cpt_slug, 'event_start' ) !== '';
	}

	/**
	 * Build the `__sort` companion meta key for a field key.
	 *
	 * @param string $field_key Field key.
	 * @return string
	 */
	public static function sort_meta_key( $field_key ) {
		return PCPTPages_Post_Data::FIELD_VALUE_META_PREFIX . $field_key . PCPTPages_Post_Data::FIELD_SORT_SUFFIX;
	}

	/**
	 * Pure builder: produce a meta_query group for the given status using
	 * already-resolved sort-meta keys and a caller-supplied "now".
	 *
	 * @param string $start_sort_key The event_start `__sort` meta key.
	 * @param string $end_sort_key   The event_end `__sort` meta key, or '' if none.
	 * @param string $status         One of STATUSES.
	 * @param int    $now            Current time as numeric YYYYMMDDHHMMSS (site-local).
	 * @return array A meta_query group (possibly empty on invalid input).
	 */
	public static function build_status_meta_query( $start_sort_key, $end_sort_key, $status, $now ) {
		if ( ! in_array( $status, self::STATUSES, true ) || $start_sort_key === '' ) {
			return array();
		}

		// End anchor drives upcoming/past so in-progress multi-day events
		// resolve correctly. Falls back to the start key when no end mapped.
		$end_anchor = ( $end_sort_key !== '' ) ? $end_sort_key : $start_sort_key;

		switch ( $status ) {
			case 'upcoming':
				return array(
					array(
						'key'     => $end_anchor,
						'value'   => (int) $now,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				);

			case 'past':
				return array(
					array(
						'key'     => $end_anchor,
						'value'   => (int) $now,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				);

			case 'happening':
				return array(
					'relation' => 'AND',
					array(
						'key'     => $start_sort_key,
						'value'   => (int) $now,
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => $end_anchor,
						'value'   => (int) $now,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				);
		}

		return array();
	}

	/**
	 * Pure helper: resolve the effective sort direction.
	 *
	 * An explicit 'soonest' / 'latest' request always wins. 'auto' (or any
	 * other value) defaults to 'latest' for past events (most-recent first)
	 * and 'soonest' for upcoming / happening (next-up first).
	 *
	 * @param string $status    The status token.
	 * @param string $requested 'soonest' | 'latest' | 'auto'.
	 * @return string 'soonest' | 'latest'.
	 */
	public static function resolve_sort_direction( $status, $requested ) {
		if ( $requested === 'soonest' || $requested === 'latest' ) {
			return $requested;
		}
		return ( $status === 'past' ) ? 'latest' : 'soonest';
	}

	/**
	 * WP-aware composer: resolve the CPT's event field keys and build the
	 * status meta_query. Returns an empty array when the CPT is not
	 * event-shaped or the status is invalid.
	 *
	 * @param string   $cpt_slug CPT slug.
	 * @param string   $status   One of STATUSES.
	 * @param int|null $now      Numeric YYYYMMDDHHMMSS; defaults to current site time.
	 * @return array
	 */
	public static function status_meta_query( $cpt_slug, $status, $now = null ) {
		$start_key = self::resolve_role_field_key( $cpt_slug, 'event_start' );
		if ( $start_key === '' ) {
			return array();
		}
		$end_key = self::resolve_role_field_key( $cpt_slug, 'event_end' );

		if ( $now === null ) {
			// wp_date() formats the CURRENT time in the site timezone, matching
			// how the `__sort` companion is stored (site-local wall clock).
			$now = (int) wp_date( 'YmdHis' );
		}

		return self::build_status_meta_query(
			self::sort_meta_key( $start_key ),
			$end_key !== '' ? self::sort_meta_key( $end_key ) : '',
			$status,
			$now
		);
	}

	/**
	 * WP-aware composer: ordering args (meta_key + orderby + order) that sort
	 * by the event_start companion. Empty array when the CPT is not event-shaped.
	 *
	 * @param string $cpt_slug  CPT slug.
	 * @param string $direction 'soonest' (ASC) | 'latest' (DESC).
	 * @return array
	 */
	public static function sort_args( $cpt_slug, $direction = 'soonest' ) {
		$start_key = self::resolve_role_field_key( $cpt_slug, 'event_start' );
		if ( $start_key === '' ) {
			return array();
		}
		return array(
			'meta_key' => self::sort_meta_key( $start_key ),
			'orderby'  => 'meta_value_num',
			'order'    => ( $direction === 'latest' ) ? 'DESC' : 'ASC',
		);
	}
}
