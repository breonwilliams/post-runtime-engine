<?php
/**
 * PCPTPages_Event_Query::build_status_meta_query() — which records each
 * status matches.
 *
 * Tested by MEANING, not shape: every case evaluates the generated
 * meta_query against a record's stored `__sort` values the way WP_Query's
 * SQL would, and asserts the record lands in exactly the expected statuses.
 *
 * Two defects found while writing the documentation (2026-09-19):
 *  - an all-day date is stored as midnight and was compared with the
 *    current time, so a one-day event was "past" from 00:00:01 on its own
 *    day, and a multi-day event past for the whole of its last day;
 *  - on a type that maps an event_end field, a record with no end value
 *    matched no status at all — it vanished from Upcoming AND Past.
 *
 * @package PostRuntimeEngine\Tests\Unit
 */

namespace PRE\Tests\Unit;

class EventStatusQueryTest extends UnitTestCase {

	const S = '_pcptpages_field_starts__sort';
	const E = '_pcptpages_field_ends__sort';

	/** 2026-06-10 14:30:00 */
	const NOW = 20260610143000;

	protected function set_up() {
		parent::set_up();
		require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-post-data.php';
		require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-event-query.php';
	}

	/**
	 * Evaluate a meta_query group against one record's meta, as SQL would.
	 */
	private function evaluate( array $group, array $meta ) {
		$relation = strtoupper( $group['relation'] ?? 'AND' );
		$results  = array();
		foreach ( $group as $k => $clause ) {
			if ( $k === 'relation' ) {
				continue;
			}
			if ( isset( $clause['key'] ) ) {
				$has = array_key_exists( $clause['key'], $meta );
				if ( $clause['compare'] === 'NOT EXISTS' ) {
					$results[] = ! $has;
					continue;
				}
				if ( ! $has ) {
					$results[] = false;
					continue;
				}
				$v = (int) $meta[ $clause['key'] ];
				$n = (int) $clause['value'];
				switch ( $clause['compare'] ) {
					case '>=': $results[] = $v >= $n; break;
					case '<=': $results[] = $v <= $n; break;
					case '<':  $results[] = $v < $n; break;
					default:   $this->fail( 'unexpected operator ' . $clause['compare'] );
				}
			} else {
				$results[] = $this->evaluate( $clause, $meta );
			}
		}
		return $relation === 'OR' ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}

	private function statuses( array $meta, $end_key = self::E, array $all_day = array(), $now = self::NOW ) {
		$in = array();
		foreach ( \PCPTPages_Event_Query::STATUSES as $status ) {
			$group = \PCPTPages_Event_Query::build_status_meta_query( self::S, $end_key, $status, $now, $all_day );
			if ( $this->evaluate( array( 'relation' => 'AND', $group ), $meta ) ) {
				$in[] = $status;
			}
		}
		return $in;
	}

	public function test_timed_events_keep_their_existing_behaviour() {
		$this->assertSame( array( 'upcoming' ), $this->statuses( array( self::S => 20260610180000, self::E => 20260610200000 ) ) );
		$this->assertSame( array( 'upcoming', 'happening' ), $this->statuses( array( self::S => 20260610140000, self::E => 20260610160000 ) ) );
		$this->assertSame( array( 'past' ), $this->statuses( array( self::S => 20260610100000, self::E => 20260610120000 ) ) );
	}

	public function test_a_one_day_all_day_event_is_current_for_the_whole_day() {
		$today = array( self::S => 20260610000000, self::E => 20260610000000 );
		$both  = array( 'start' => true, 'end' => true );
		$this->assertSame( array( 'upcoming', 'happening' ), $this->statuses( $today, self::E, $both ) );
		$this->assertSame( array( 'upcoming', 'happening' ), $this->statuses( $today, self::E, $both, 20260610235959 ) );
		$this->assertSame( array( 'past' ), $this->statuses( $today, self::E, $both, 20260611000000 ), 'past from the next midnight' );
	}

	public function test_a_multi_day_all_day_event_is_current_through_its_last_day() {
		$fair = array( self::S => 20260608000000, self::E => 20260610000000 );
		$this->assertSame( array( 'upcoming', 'happening' ), $this->statuses( $fair, self::E, array( 'start' => true, 'end' => true ) ) );
	}

	public function test_an_all_day_type_with_no_end_field_uses_the_whole_start_day() {
		$today = array( self::S => 20260610000000 );
		$this->assertSame( array( 'upcoming', 'happening' ), $this->statuses( $today, '', array( 'start' => true ) ) );
		$this->assertSame( array( 'past' ), $this->statuses( array( self::S => 20260609000000 ), '', array( 'start' => true ) ) );
	}

	public function test_a_record_with_no_end_value_falls_back_to_its_start() {
		$this->assertSame( array( 'upcoming' ), $this->statuses( array( self::S => 20260612180000 ) ), 'future start, no end' );
		$this->assertSame( array( 'past' ), $this->statuses( array( self::S => 20260601180000 ) ), 'past start, no end' );
	}

	public function test_every_dated_record_is_in_upcoming_or_past_and_never_both() {
		$cases = array(
			array( self::S => 20260610180000, self::E => 20260610200000 ),
			array( self::S => 20260601090000 ),
			array( self::S => 20260620090000 ),
			array( self::S => 20260610000000, self::E => 20260610000000 ),
		);
		foreach ( array( array(), array( 'start' => true, 'end' => true ) ) as $all_day ) {
			foreach ( $cases as $meta ) {
				$in = $this->statuses( $meta, self::E, $all_day );
				$this->assertSame( 1, count( array_intersect( array( 'upcoming', 'past' ), $in ) ), wp_json_encode_safe( $meta ) );
			}
		}
	}

	public function test_start_of_day() {
		$this->assertSame( 20260610000000, \PCPTPages_Event_Query::start_of_day( 20260610143000 ) );
		$this->assertSame( 20260610000000, \PCPTPages_Event_Query::start_of_day( 20260610000000 ) );
	}
}

/** json_encode for assertion messages without WordPress. */
function wp_json_encode_safe( $v ) {
	return (string) json_encode( $v );
}
