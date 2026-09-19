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
 * When it maps one but a RECORD has no end value, that record falls back to
 * its own start the same way — before 2026-09-19 such a record matched none
 * of the three statuses, so a meeting entered without an end time vanished
 * from both Upcoming and Past.
 *
 * All-day anchors compare against the START of today rather than now: an
 * all-day date is stored as midnight, so comparing it to the current time
 * made a one-day event "past" from 00:00:01 on its own day, and a multi-day
 * event past for the whole of its last day.
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
	 * @param array  $all_day        Optional. `start` / `end` => bool: whether
	 *                               that field is all-day. An all-day anchor
	 *                               compares against the start of today.
	 * @return array A meta_query group (possibly empty on invalid input).
	 */
	public static function build_status_meta_query( $start_sort_key, $end_sort_key, $status, $now, array $all_day = array() ) {
		if ( ! in_array( $status, self::STATUSES, true ) || $start_sort_key === '' ) {
			return array();
		}

		$now       = (int) $now;
		$today     = self::start_of_day( $now );
		$start_now = empty( $all_day['start'] ) ? $now : $today;
		$end_now   = empty( $all_day['end'] ) ? $now : $today;

		switch ( $status ) {
			case 'upcoming':
				return array( self::end_side( $start_sort_key, $end_sort_key, '>=', $start_now, $end_now ) );

			case 'past':
				return array( self::end_side( $start_sort_key, $end_sort_key, '<', $start_now, $end_now ) );

			case 'happening':
				return array(
					'relation' => 'AND',
					self::clause( $start_sort_key, '<=', $now ),
					self::end_side( $start_sort_key, $end_sort_key, '>=', $start_now, $end_now ),
				);
		}

		return array();
	}

	/**
	 * The END-anchored half of a status query. Upcoming, past and the second
	 * bound of happening all compare "when does it finish" with now.
	 *
	 * With no end field mapped, the start is the end. With one mapped, a
	 * record that carries an end compares its end, and a record without one
	 * compares its start — so a record is never in none of the statuses.
	 *
	 * @param string $start_key Start `__sort` key.
	 * @param string $end_key   End `__sort` key, or ''.
	 * @param string $compare   '>=' or '<'.
	 * @param int    $start_now "Now" for a start-as-end comparison.
	 * @param int    $end_now   "Now" for an end comparison.
	 * @return array A meta_query clause or group.
	 */
	private static function end_side( $start_key, $end_key, $compare, $start_now, $end_now ) {
		if ( $end_key === '' ) {
			return self::clause( $start_key, $compare, $start_now );
		}
		return array(
			'relation' => 'OR',
			self::clause( $end_key, $compare, $end_now ),
			array(
				'relation' => 'AND',
				array(
					'key'     => $end_key,
					'compare' => 'NOT EXISTS',
				),
				self::clause( $start_key, $compare, $start_now ),
			),
		);
	}

	/**
	 * One numeric comparison clause.
	 *
	 * @param string $key     Meta key.
	 * @param string $compare Operator.
	 * @param int    $value   YYYYMMDDHHMMSS.
	 * @return array
	 */
	private static function clause( $key, $compare, $value ) {
		return array(
			'key'     => $key,
			'value'   => (int) $value,
			'compare' => $compare,
			'type'    => 'NUMERIC',
		);
	}

	/**
	 * Midnight of the day a YYYYMMDDHHMMSS value falls on, in the same form.
	 *
	 * @param int $ymdhis Numeric YYYYMMDDHHMMSS.
	 * @return int
	 */
	public static function start_of_day( $ymdhis ) {
		return intdiv( (int) $ymdhis, 1000000 ) * 1000000;
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
			$now,
			array(
				'start' => self::is_all_day( $cpt_slug, $start_key ),
				'end'   => $end_key !== '' && self::is_all_day( $cpt_slug, $end_key ),
			)
		);
	}

	/**
	 * Whether a CPT's date field is all-day.
	 *
	 * @param string $cpt_slug  CPT slug.
	 * @param string $field_key Field key.
	 * @return bool
	 */
	private static function is_all_day( $cpt_slug, $field_key ) {
		$plugin = function_exists( 'pcptpages' ) ? pcptpages() : null;
		if ( ! $plugin || ! $plugin->post_fields ) {
			return false;
		}
		$defs = $plugin->post_fields->get_all( $cpt_slug );
		return is_array( $defs ) && ! empty( $defs[ $field_key ]['all_day'] );
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
