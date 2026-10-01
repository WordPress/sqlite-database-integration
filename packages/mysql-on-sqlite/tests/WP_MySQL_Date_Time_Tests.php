<?php

require_once __DIR__ . '/fixtures/WP_MySQL_Date_Time_Test_Cases.php';

use PHPUnit\Framework\TestCase;

class WP_MySQL_Date_Time_Tests extends TestCase {
	/**
	 * @dataProvider parseInputs
	 */
	public function testParse( $input, $components, $kind, $precision ) {
		$expected              = array_combine(
			array( 'year', 'month', 'day', 'hour', 'minute', 'second', 'microsecond' ),
			$components
		);
		$expected['kind']      = $kind;
		$expected['precision'] = $precision;
		$this->assertSame( $expected, WP_MySQL_Date_Time::parse( $input ) );
	}

	public static function parseInputs() {
		return array(
			array( '2014-10-21', array( 2014, 10, 21, 0, 0, 0, 0 ), 'date', 0 ),
			array( '2014-10-21 00:00:00', array( 2014, 10, 21, 0, 0, 0, 0 ), 'datetime', 0 ),
			array( '2014-10-21 07:30:15.123456', array( 2014, 10, 21, 7, 30, 15, 123456 ), 'datetime', 6 ),
			array( '14-1-2T3:4:5.1', array( 2014, 1, 2, 3, 4, 5, 100000 ), 'datetime', 1 ),
			array( '2014-1-2 3:4:5.1200', array( 2014, 1, 2, 3, 4, 5, 120000 ), 'datetime', 4 ),
			array( '2014-1-2 3:4:5.000001', array( 2014, 1, 2, 3, 4, 5, 1 ), 'datetime', 6 ),
			array( '2014-1-2 3:4:5.000000', array( 2014, 1, 2, 3, 4, 5, 0 ), 'datetime', 6 ),
			array( ' 2014-10-21 ', array( 2014, 10, 21, 0, 0, 0, 0 ), 'date', 0 ),
			array( '20141021', array( 2014, 10, 21, 0, 0, 0, 0 ), 'date', 0 ),
			array( 20141021, array( 2014, 10, 21, 0, 0, 0, 0 ), 'date', 0 ),
			array( '141021', array( 2014, 10, 21, 0, 0, 0, 0 ), 'date', 0 ),
			array( '20141021073015', array( 2014, 10, 21, 7, 30, 15, 0 ), 'datetime', 0 ),
			array( '141021073015.123456', array( 2014, 10, 21, 7, 30, 15, 123456 ), 'datetime', 6 ),
			array( '690101', array( 2069, 1, 1, 0, 0, 0, 0 ), 'date', 0 ),
			array( '700101', array( 1970, 1, 1, 0, 0, 0, 0 ), 'date', 0 ),
			array( '2024-02-29', array( 2024, 2, 29, 0, 0, 0, 0 ), 'date', 0 ),
			array( '2000-02-29', array( 2000, 2, 29, 0, 0, 0, 0 ), 'date', 0 ),
			array( '9999-12-31 23:59:59.999999', array( 9999, 12, 31, 23, 59, 59, 999999 ), 'datetime', 6 ),
			array( '2006-06-00', array( 2006, 6, 0, 0, 0, 0, 0 ), 'date', 0 ),
			array( '2006-00-15', array( 2006, 0, 15, 0, 0, 0, 0 ), 'date', 0 ),
			array( '0000-06-15', array( 0, 6, 15, 0, 0, 0, 0 ), 'date', 0 ),
			array( '0000-00-00', array( 0, 0, 0, 0, 0, 0, 0 ), 'date', 0 ),
			array( '0000-00-00 00:00:00.000000', array( 0, 0, 0, 0, 0, 0, 0 ), 'datetime', 6 ),
			array( '000000', array( 0, 0, 0, 0, 0, 0, 0 ), 'date', 0 ),
			array( '00000000', array( 0, 0, 0, 0, 0, 0, 0 ), 'date', 0 ),
			array( '000000000000', array( 0, 0, 0, 0, 0, 0, 0 ), 'datetime', 0 ),
			array( '00000000000000', array( 0, 0, 0, 0, 0, 0, 0 ), 'datetime', 0 ),
			array( '00-00-00', array( 0, 0, 0, 0, 0, 0, 0 ), 'date', 0 ),
			array( '00-00-00 01:00:00', array( 2000, 0, 0, 1, 0, 0, 0 ), 'datetime', 0 ),
			array( '000000000000.1', array( 2000, 0, 0, 0, 0, 0, 100000 ), 'datetime', 1 ),
			array( 0, array( 0, 0, 0, 0, 0, 0, 0 ), 'datetime', 0 ),
			array( 0.0, array( 0, 0, 0, 0, 0, 0, 0 ), 'datetime', 6 ),
			array( '2014-10-21 07', array( 2014, 10, 21, 7, 0, 0, 0 ), 'datetime', 0 ),
			array( '2014-10-21 07:30', array( 2014, 10, 21, 7, 30, 0, 0 ), 'datetime', 0 ),
			array( '2014-10-21  07:30:15', array( 2014, 10, 21, 7, 30, 15, 0 ), 'datetime', 0 ),
			array( '2014/10/21 7*30*15', array( 2014, 10, 21, 7, 30, 15, 0 ), 'datetime', 0 ),
			array( '2014-10-21 07:30:15.', array( 2014, 10, 21, 7, 30, 15, 0 ), 'datetime', 0 ),
			array( '2014-10-21 07:30:15.123456499', array( 2014, 10, 21, 7, 30, 15, 123456 ), 'datetime', 6 ),
			array( '2014-10-21 07:30:15.123456500', array( 2014, 10, 21, 7, 30, 15, 123457 ), 'datetime', 6 ),
			array( '2014-10-21 07:30:15.9999999', array( 2014, 10, 21, 7, 30, 16, 0 ), 'datetime', 6 ),
			array( '2024-02-28 23:59:59.9999999', array( 2024, 2, 29, 0, 0, 0, 0 ), 'datetime', 6 ),
			array( '2023-02-28 23:59:59.9999999', array( 2023, 3, 1, 0, 0, 0, 0 ), 'datetime', 6 ),
			array( '1999-12-31 23:59:59.9999999', array( 2000, 1, 1, 0, 0, 0, 0 ), 'datetime', 6 ),
			array( '0000-00-00 00:00:00.1234567', array( 0, 0, 0, 0, 0, 0, 123457 ), 'datetime', 6 ),
			array( '0000-02-28 23:59:59.9999999', array( 0, 0, 0, 0, 0, 0, 0 ), 'datetime', 6 ),
			array( '00-00-00 00:00:00.0000001', array( 0, 0, 0, 0, 0, 0, 0 ), 'datetime', 6 ),
			array( '20141021073015.1234567', array( 2014, 10, 21, 7, 30, 15, 123457 ), 'datetime', 6 ),
			array( '2014-10-21T07:30:15+02:00', array( 2014, 10, 21, 5, 30, 15, 0 ), 'datetime', 0 ),
			array( '2014-10-21 07:30:15.1234567+02:00', array( 2014, 10, 21, 5, 30, 15, 123457 ), 'datetime', 6 ),
			array( '2014-10-21 07:30:15+14:00', array( 2014, 10, 20, 17, 30, 15, 0 ), 'datetime', 0 ),
			array( '2024-03-01 00:30:15+01:00', array( 2024, 2, 29, 23, 30, 15, 0 ), 'datetime', 0 ),
			array( '2023-03-01 00:30:15+01:00', array( 2023, 2, 28, 23, 30, 15, 0 ), 'datetime', 0 ),
			array( '1999-12-31 23:30:15-01:00', array( 2000, 1, 1, 0, 30, 15, 0 ), 'datetime', 0 ),
		);
	}

	/**
	 * @dataProvider invalidInputs
	 */
	public function testRejectInvalidInput( $input ) {
		$this->assertNull( WP_MySQL_Date_Time::parse( $input ) );
	}

	public static function invalidInputs() {
		return array(
			array( null ),
			array( '' ),
			array( 'not-a-date' ),
			array( '2014-02-29' ),
			array( '1900-02-29' ),
			array( '0000-02-29' ),
			array( '2014-04-31' ),
			array( '2014-13-01' ),
			array( '2014-00-32' ),
			array( '2014-01-01 24:00:00' ),
			array( '2014-01-01 00:60:00' ),
			array( '2014-01-01 00:00:60' ),
			array( '2014-10-21 07:30:15+14:01' ),
			array( '2014-10-21 07:30:15+01:60' ),
			array( '2014-10-21 07:30:15-00:00' ),
			array( '2006-06-00 23:59:59.9999999' ),
			array( '0000-00-00 00:00:00.9999999' ),
			array( '9999-12-31 23:59:59.9999999' ),
			array( '9999-12-31 23:59:59-01:00' ),
		);
	}

	public function testParseWithExplicitTimezone() {
		$expected = WP_MySQL_Date_Time::parse( '2014-10-21 08:30:15' );
		$this->assertSame( $expected, WP_MySQL_Date_Time::parse( '2014-10-21 07:30:15+02:00', 180 ) );
		$this->assertSame( $expected, WP_MySQL_Date_Time::parse( '2014-10-21 08:30:15', 180 ) );
		$this->assertSame( $expected, WP_MySQL_Date_Time::parse( '2014-10-21T08:30:15Z', 180 ) );
	}

	/**
	 * @dataProvider numericInputs
	 */
	public function testParseNumericInput( $input, $expected ) {
		$expected = null === $expected ? null : WP_MySQL_Date_Time::parse( $expected );
		$this->assertSame( $expected, WP_MySQL_Date_Time::parse( $input ) );
	}

	public static function numericInputs() {
		return array(
			array( 20141021073015.125, '20141021073015.125000' ),
			array( 141021073015.125, '20141021073015.125000' ),
			array( 615120000.1, '20000615120000.100000' ),
			array( 20141021.1, '20141021' ),
			array( 101.1, '20000101' ),
			array( 0.1, '00000000000000.100000' ),
			array( 0, '00000000000000' ),
			array( 100, null ),
			array( 691231, '20691231' ),
			array( 691232, null ),
			array( 700100, null ),
			array( 700101, '19700101' ),
			array( 991231.1, '19991231' ),
			array( 991232.1, null ),
			array( 99991231.1, '99991231' ),
			array( 99991232, null ),
			array( 101000000.1, '20000101000000.100000' ),
			array( 691231235959.0, '20691231235959.000000' ),
			array( 691231235960.0, null ),
			array( 700100999999.0, null ),
			array( 700101000000.0, '19700101000000.000000' ),
			array( 991231235959.125, '19991231235959.125000' ),
			array( 991231235960.125, null ),
			array( 10000100999999.125, null ),
			array( 10000101000000.0, '10000101000000.000000' ),
			array( 99999999999999.0, null ),
			array( 1, null ),
			array( 700000, null ),
			array( 100000000, null ),
			array( 700100000000.0, null ),
			array( 100000000000000.0, null ),
			array( -20141021, null ),
		);
	}

	/**
	 * @dataProvider WP_MySQL_Date_Time_Test_Cases::date_formats
	 */
	public function testDateFormats( $format, $expected ) {
		$this->assertSame( $expected, WP_MySQL_Date_Time::format( '2014-10-21 07:30:15.123456', $format ) );
	}

	/**
	 * @dataProvider WP_MySQL_Date_Time_Test_Cases::date_format_week_boundaries
	 */
	public function testDateFormatWeekBoundaries( $date, $expected ) {
		$this->assertSame( $expected, WP_MySQL_Date_Time::format( $date, '%U|%u|%V|%v|%X|%x' ) );
	}

	/**
	 * @dataProvider WP_MySQL_Date_Time_Test_Cases::date_format_inputs
	 */
	public function testDateFormatInputs( $date, $format, $expected ) {
		$this->assertSame( $expected, WP_MySQL_Date_Time::format( $date, $format ) );
	}

	/**
	 * @dataProvider WP_MySQL_Date_Time_Test_Cases::date_format_runtime_inputs
	 */
	public function testDateFormatRuntimeInputs( $date, $expected ) {
		$this->assertSame( $expected, WP_MySQL_Date_Time::format( $date, '%Y-%m-%d %H:%i:%s.%f' ) );
	}
}
