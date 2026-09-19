<?php
/**
 * PCPTPages_Post_Data::normalize_date_value() — a date with an explicit
 * offset is an instant, and is stored as the wall clock in the field's
 * timezone.
 *
 * Found writing the documentation (2026-09-19): every input went through
 * strtotime() + gmdate(), which stored the UTC clock time, so an upsert
 * carrying "2026-10-01T18:00:00-05:00" — the ISO 8601 form the relay's
 * upsert description invites — rendered as 11:00 PM on the page, in the
 * calendar file and in the Event schema.
 *
 * @package PostRuntimeEngine\Tests\Unit
 */

namespace PRE\Tests\Unit;

class DateNormalizeTest extends UnitTestCase {

	protected function set_up() {
		parent::set_up();
		require_once PRE_TEST_PLUGIN_DIR . 'includes/Core/class-pre-post-data.php';
	}

	private function norm( $value, $tz = 'America/Chicago' ) {
		return \PCPTPages_Post_Data::normalize_date_value( $value, $tz );
	}

	public function test_an_offset_is_converted_into_the_fields_timezone() {
		$this->assertSame( '2026-10-01 18:00:00', $this->norm( '2026-10-01T18:00:00-05:00' ) );
		$this->assertSame( '2026-10-01 18:00:00', $this->norm( '2026-10-01T23:00:00Z' ) );
		$this->assertSame( '2026-10-01 18:00:00', $this->norm( '2026-10-01T23:00:00+00:00' ) );
		$this->assertSame( '2026-10-02 00:00:00', $this->norm( '2026-10-01T18:00:00-05:00', 'Europe/London' ) );
	}

	public function test_a_wall_clock_keeps_its_digits() {
		$this->assertSame( '2026-10-01 18:00:00', $this->norm( '2026-10-01 18:00' ) );
		$this->assertSame( '2026-10-01 18:00:00', $this->norm( '2026-10-01T18:00:00' ) );
		$this->assertSame( '2026-10-01 18:30:00', $this->norm( 'October 1, 2026 6:30 PM' ) );
		$this->assertSame( '2026-10-01 18:00:00', $this->norm( '2026-10-01 18:00', 'Asia/Tokyo' ), 'no offset, no conversion' );
	}

	public function test_date_only_values_stay_date_only() {
		$this->assertSame( '2026-10-01', $this->norm( '2026-10-01' ) );
		$this->assertSame( '2026-10-01', $this->norm( 'October 1, 2026' ) );
	}

	public function test_unparseable_input_is_returned_unchanged() {
		$this->assertSame( 'next council meeting', $this->norm( 'next council meeting' ) );
	}

	public function test_a_bad_timezone_falls_back_to_utc() {
		$this->assertSame( '2026-10-01 23:00:00', $this->norm( '2026-10-01T18:00:00-05:00', 'Not/AZone' ) );
	}
}
