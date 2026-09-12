<?php
/**
 * Unit tests for PCPTPages_Event_Calendar's pure builders.
 *
 * The feed callback, the hero links and the record resolution read the
 * registry and post meta (covered by tests/smoke-events-calendar.php on a
 * site). These pin the iCalendar text itself, which is where a calendar
 * app silently refuses a file: CRLF endings, 75-octet folding that never
 * splits a multibyte character, TEXT escaping, all-day events with the
 * EXCLUSIVE end RFC 5545 requires, timed events in UTC, and status.
 *
 * @package PostRuntimeEngine\Tests\Unit
 */

namespace PRE\Tests\Unit;

/**
 * Tests for the static iCalendar builders.
 */
class EventCalendarTest extends UnitTestCase {

	protected function set_up() {
		parent::set_up();
		require_once PRE_TEST_PLUGIN_DIR . 'includes/Frontend/class-pre-event-calendar.php';
	}

	private function timed_event( array $over = array() ) {
		return array_merge(
			array(
				'uid'         => 'pre-42@example.test',
				'summary'     => 'Board meeting',
				'description' => 'Regular session.',
				'location'    => 'Room 101, City Hall',
				'url'         => 'https://example.test/agenda/board-meeting/',
				'start'       => '2026-09-12T09:00:00-05:00',
				'end'         => '2026-09-12T11:30:00-05:00',
				'status'      => 'CONFIRMED',
				'stamp'       => '20260901T120000Z',
				'modified'    => '20260901T120000Z',
			),
			$over
		);
	}

	public function test_calendar_envelope_and_crlf() {
		$ics = \PCPTPages_Event_Calendar::build_calendar( array( $this->timed_event() ), 'Meetings — Example' );
		$this->assertStringStartsWith( "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics );
		$this->assertStringEndsWith( "END:VCALENDAR\r\n", $ics );
		$this->assertStringContainsString( "\r\nMETHOD:PUBLISH\r\n", $ics );
		$this->assertStringContainsString( 'X-WR-CALNAME:Meetings — Example', $ics );
		// Every line ends with CRLF; no bare LF anywhere.
		$this->assertSame( 0, preg_match_all( '/(?<!\r)\n/', $ics ) );
		$this->assertSame( 1, substr_count( $ics, 'BEGIN:VEVENT' ) );
	}

	public function test_timed_event_is_converted_to_utc() {
		$lines = \PCPTPages_Event_Calendar::vevent_lines( $this->timed_event() );
		$this->assertContains( 'DTSTART:20260912T140000Z', $lines );
		$this->assertContains( 'DTEND:20260912T163000Z', $lines );
		$this->assertContains( 'UID:pre-42@example.test', $lines );
		$this->assertContains( 'SUMMARY:Board meeting', $lines );
		$this->assertContains( 'LOCATION:Room 101\\, City Hall', $lines );
		$this->assertContains( 'URL:https://example.test/agenda/board-meeting/', $lines );
		$this->assertContains( 'STATUS:CONFIRMED', $lines );
		$this->assertContains( 'LAST-MODIFIED:20260901T120000Z', $lines );
	}

	public function test_all_day_event_uses_date_values_with_exclusive_end() {
		$props = \PCPTPages_Event_Calendar::date_properties( '2026-09-12', '' );
		$this->assertSame( array( 'DTSTART;VALUE=DATE:20260912', 'DTEND;VALUE=DATE:20260913' ), $props );

		$two_days = \PCPTPages_Event_Calendar::date_properties( '2026-09-12', '2026-09-13' );
		$this->assertSame( array( 'DTSTART;VALUE=DATE:20260912', 'DTEND;VALUE=DATE:20260914' ), $two_days );

		// A year boundary.
		$nye = \PCPTPages_Event_Calendar::date_properties( '2026-12-31', '' );
		$this->assertSame( 'DTEND;VALUE=DATE:20270101', $nye[1] );
	}

	public function test_end_before_start_is_dropped_not_emitted() {
		$props = \PCPTPages_Event_Calendar::date_properties( '2026-09-12T09:00:00-05:00', '2026-09-12T08:00:00-05:00' );
		$this->assertSame( array( 'DTSTART:20260912T140000Z' ), $props );
	}

	public function test_event_without_usable_start_is_skipped() {
		$this->assertSame( array(), \PCPTPages_Event_Calendar::vevent_lines( $this->timed_event( array( 'start' => '' ) ) ) );
		$this->assertSame( array(), \PCPTPages_Event_Calendar::vevent_lines( $this->timed_event( array( 'start' => 'not a date' ) ) ) );
		$ics = \PCPTPages_Event_Calendar::build_calendar( array( $this->timed_event( array( 'start' => '' ) ), $this->timed_event() ), 'x' );
		$this->assertSame( 1, substr_count( $ics, 'BEGIN:VEVENT' ) );
	}

	public function test_text_escaping() {
		$this->assertSame( 'a\\, b\\; c\\\\ d\\nnext', \PCPTPages_Event_Calendar::escape_text( "a, b; c\\ d\r\nnext" ) );
		$lines = \PCPTPages_Event_Calendar::vevent_lines( $this->timed_event( array( 'summary' => 'Budget; Q3, final' ) ) );
		$this->assertContains( 'SUMMARY:Budget\\; Q3\\, final', $lines );
	}

	public function test_folding_keeps_lines_within_75_octets_and_unfolds_to_the_original() {
		$long = 'DESCRIPTION:' . str_repeat( 'The council will hear public comment. ', 8 );
		$folded = \PCPTPages_Event_Calendar::fold_line( $long );
		$this->assertGreaterThan( 1, substr_count( $folded, "\r\n" ) + 1 );
		foreach ( explode( "\r\n", $folded ) as $i => $line ) {
			$this->assertLessThanOrEqual( 75, strlen( $line ), 'line ' . $i );
			if ( $i > 0 ) {
				$this->assertSame( ' ', $line[0], 'continuation lines start with a space' );
			}
		}
		$this->assertSame( $long, str_replace( "\r\n ", '', $folded ), 'unfolding restores the original' );
	}

	public function test_folding_never_splits_a_multibyte_character() {
		// 70 ASCII octets then a run of 3-octet characters: the first cut would
		// land mid-character with a naive substr.
		$line   = 'SUMMARY:' . str_repeat( 'x', 62 ) . str_repeat( 'é', 20 ) . 'الاجتماع';
		$folded = \PCPTPages_Event_Calendar::fold_line( $line );
		foreach ( explode( "\r\n", $folded ) as $piece ) {
			$this->assertLessThanOrEqual( 75, strlen( $piece ) );
			$this->assertTrue( mb_check_encoding( $piece, 'UTF-8' ), 'every folded piece is valid UTF-8' );
		}
		$this->assertSame( $line, str_replace( "\r\n ", '', $folded ) );
	}

	public function test_short_line_is_not_folded() {
		$this->assertSame( 'BEGIN:VEVENT', \PCPTPages_Event_Calendar::fold_line( 'BEGIN:VEVENT' ) );
		$exact = str_repeat( 'a', 75 );
		$this->assertSame( $exact, \PCPTPages_Event_Calendar::fold_line( $exact ) );
	}

	public function test_status_map() {
		$this->assertSame( 'CANCELLED', \PCPTPages_Event_Calendar::map_status( 'cancelled' ) );
		$this->assertSame( 'CANCELLED', \PCPTPages_Event_Calendar::map_status( 'Canceled' ) );
		$this->assertSame( 'TENTATIVE', \PCPTPages_Event_Calendar::map_status( 'postponed' ) );
		$this->assertSame( 'TENTATIVE', \PCPTPages_Event_Calendar::map_status( 'rescheduled' ) );
		$this->assertSame( 'CONFIRMED', \PCPTPages_Event_Calendar::map_status( 'scheduled' ) );
		$this->assertSame( 'CONFIRMED', \PCPTPages_Event_Calendar::map_status( '' ) );
		$lines = \PCPTPages_Event_Calendar::vevent_lines( $this->timed_event( array( 'status' => 'CANCELLED' ) ) );
		$this->assertContains( 'STATUS:CANCELLED', $lines );
		$lines = \PCPTPages_Event_Calendar::vevent_lines( $this->timed_event( array( 'status' => 'garbage' ) ) );
		$this->assertContains( 'STATUS:CONFIRMED', $lines );
	}

	public function test_google_calendar_url() {
		$url = \PCPTPages_Event_Calendar::google_calendar_url( $this->timed_event() );
		$this->assertStringStartsWith( 'https://calendar.google.com/calendar/render?', $url );
		$this->assertStringContainsString( 'action=TEMPLATE', $url );
		$this->assertStringContainsString( 'dates=20260912T140000Z%2F20260912T163000Z', $url );
		$this->assertStringContainsString( 'text=Board%20meeting', $url );
		$this->assertStringContainsString( 'location=Room%20101%2C%20City%20Hall', $url );

		$all_day = \PCPTPages_Event_Calendar::google_calendar_url( $this->timed_event( array( 'start' => '2026-09-12', 'end' => '' ) ) );
		$this->assertStringContainsString( 'dates=20260912%2F20260913', $all_day );

		$this->assertSame( '', \PCPTPages_Event_Calendar::google_calendar_url( $this->timed_event( array( 'start' => '' ) ) ) );
	}

	public function test_to_utc_handles_offsets_and_rejects_garbage() {
		$this->assertSame( '20260912T140000Z', \PCPTPages_Event_Calendar::to_utc( '2026-09-12T09:00:00-05:00' ) );
		$this->assertSame( '20260912T090000Z', \PCPTPages_Event_Calendar::to_utc( '2026-09-12T09:00:00+00:00' ) );
		$this->assertSame( '20260101T030000Z', \PCPTPages_Event_Calendar::to_utc( '2026-01-01T12:00:00+09:00' ) );
		$this->assertSame( '', \PCPTPages_Event_Calendar::to_utc( 'yesterday-ish' ) );
	}
}
