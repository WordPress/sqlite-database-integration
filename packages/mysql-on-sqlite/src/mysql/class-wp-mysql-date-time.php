<?php

/**
 * MySQL date and calendar rules.
 *
 * @access private
 */
class WP_MySQL_Date_Time {
	/**
	 * Match compact dates and datetimes, with an optional fractional second.
	 *
	 * Captures: year, month, day, hour, minute, second, fraction.
	 * Examples: 20141021, 141021, 20141021073015.123456.
	 */
	private const COMPACT_DATE_PATTERN = '/^(\d{4}|\d{2})(\d{2})(\d{2})(?:(\d{2})(\d{2})(\d{2})(?:\.(\d*))?)?$/';

	/**
	 * Match dates with punctuation separators and optional time and UTC offset.
	 * MySQL ignores a trailing Z on ISO timestamps without applying a timezone shift.
	 *
	 * Captures: year, month, day, hour, minute, second, fraction, offset sign, hours, minutes.
	 * Examples: 2014-10-21, 14/1/2, 2014-10-21 07:30, 2014-10-21T07:30:15.123456+02:00.
	 */
	private const DELIMITED_DATE_PATTERN = '/^(\d{2}|\d{4})[[:punct:]](\d{1,2})[[:punct:]](\d{1,2})(?:[\sT]+(\d{1,2})(?:[[:punct:]](\d{1,2})(?:[[:punct:]](\d{1,2})(?:\.(\d*))?)?)?(?:([+-])(\d{2}):(\d{2})|[zZ])?)?$/';

	/**
	 * Parse a MySQL date or datetime value.
	 *
	 * Returns an array with the following components:
	 * - year, month, day, hour, minute, second, microsecond: Integer date and time fields.
	 * - kind: 'date' or 'datetime', depending on whether the parsed input has a time part.
	 * - precision: Number of fractional second digits, from 0 to 6.
	 *
	 * Zero date fields are preserved. Operations requiring a complete date must reject them.
	 *
	 * @param mixed $date                   The date value.
	 * @param int   $timezone_offset_minutes Target UTC offset for inputs with a timezone offset.
	 * @return array|null The parsed fields, or NULL for an invalid date.
	 */
	public static function parse( $date, $timezone_offset_minutes = 0 ): ?array {
		if ( is_int( $date ) || is_float( $date ) ) {
			$date = self::numeric_to_string( $date );
		}
		$date = trim( (string) $date );

		if (
			! preg_match( self::COMPACT_DATE_PATTERN, $date, $matches )
			&& ! preg_match( self::DELIMITED_DATE_PATTERN, $date, $matches )
		) {
			return null;
		}

		$year        = (int) $matches[1];
		$month       = (int) $matches[2];
		$day         = (int) $matches[3];
		$hour        = (int) ( $matches[4] ?? 0 );
		$minute      = (int) ( $matches[5] ?? 0 );
		$second      = (int) ( $matches[6] ?? 0 );
		$fraction    = $matches[7] ?? '';
		$microsecond = (int) str_pad( substr( $fraction, 0, 6 ), 6, '0' );
		$is_zero     = 0 === max( $year, $month, $day, $hour, $minute, $second, $microsecond );
		if ( 2 === strlen( $matches[1] ) && ! $is_zero ) {
			$year += $year <= 69 ? 2000 : 1900;
		}

		$maximum_day = 0 === $month ? 31 : self::days_in_month( $year, $month );
		if (
			$month > 12
			|| $day > $maximum_day
			|| $hour > 23
			|| $minute > 59
			|| $second > 59
		) {
			return null;
		}

		$parts = array(
			'year'        => $year,
			'month'       => $month,
			'day'         => $day,
			'hour'        => $hour,
			'minute'      => $minute,
			'second'      => $second,
			'microsecond' => $microsecond,
			'kind'        => isset( $matches[4] ) ? 'datetime' : 'date',
			'precision'   => min( 6, strlen( $fraction ) ),
		);
		if ( isset( $fraction[6] ) && $fraction[6] >= '5' ) {
			++$parts['microsecond'];
			if ( 1000000 === $parts['microsecond'] ) {
				$parts['microsecond'] = 0;
				$parts                = self::shift_seconds( $parts, 1 );
				if ( null === $parts ) {
					return null;
				}
			}
		}
		if ( isset( $matches[8] ) && '' !== $matches[8] ) {
			$offset = (int) $matches[9] * 60 + (int) $matches[10];
			if ( $offset > 14 * 60 || (int) $matches[10] > 59 || ( '-' === $matches[8] && 0 === $offset ) ) {
				return null;
			}
			$offset = '-' === $matches[8] ? -$offset : $offset;
			$parts  = self::shift_seconds( $parts, ( $timezone_offset_minutes - $offset ) * 60 );
		}
		return $parts;
	}

	/**
	 * Convert a numeric date to a compact date or datetime string.
	 * Numeric dates discard fractions; numeric datetimes retain them.
	 *
	 * Format thresholds follow MySQL's number_to_datetime().
	 * @see https://github.com/mysql/mysql-server/blob/mysql-8.0.46/mysys/my_time.cc#L1453
	 *
	 * @param int|float $number The numeric date value.
	 * @return string|null A compact date/datetime string, or NULL for an invalid number.
	 */
	private static function numeric_to_string( $number ): ?string {
		$number = is_float( $number ) ? sprintf( '%.6F', $number ) : (string) $number;
		if ( ! preg_match( '/^(\d+)(?:\.(\d*))?$/', $number, $matches ) ) {
			return null;
		}

		// All supported integer parts are below 2^53, so this is exact on 32-bit PHP too.
		$integer = (float) $matches[1];

		/*
		 * Select the format by numeric value, not by chronological date.
		 * Zero and values >= 10000101000000 (1000-01-01 00:00:00 encoded as
		 * YYYYMMDDHHMMSS) use the full datetime format directly. Smaller values
		 * need format selection: for example, 20241021 represents 2024-10-21.
		 *
		 * The thresholds below encode dates as YYMMDD or datetimes as YYMMDDHHMMSS.
		 * Years 00-69 expand to 2000-2069; years 70-99 expand to 1970-1999.
		 * The YYYYMMDD limit is 99991231; larger values are interpreted as datetimes.
		 */
		if ( 0.0 === $integer || $integer >= 10000101000000 ) {
			// YYYYMMDDHHMMSS, including the zero datetime.
			$width = 14;
		} elseif ( $integer < 101 ) {
			// Before 00-01-01 in YYMMDD form.
			return null;
		} elseif ( $integer <= 691231 ) {
			// Through 69-12-31. Expand YYMMDD to years 2000-2069.
			$integer += 20000000;
			$width    = 8;
		} elseif ( $integer < 700101 ) {
			// Before 70-01-01.
			return null;
		} elseif ( $integer <= 991231 ) {
			// Through 99-12-31. Expand YYMMDD to years 1970-1999.
			$integer += 19000000;
			$width    = 8;
		} elseif ( $integer <= 99991231 ) {
			// Through 9999-12-31 in YYYYMMDD form.
			$width = 8;
		} elseif ( $integer < 101000000 ) {
			// Before 00-01-01 00:00:00 in YYMMDDHHMMSS form.
			return null;
		} elseif ( $integer <= 691231235959 ) {
			// Through 69-12-31 23:59:59. Expand YYMMDDHHMMSS to years 2000-2069.
			$integer += 20000000000000;
			$width    = 14;
		} elseif ( $integer < 700101000000 ) {
			// Before 70-01-01 00:00:00.
			return null;
		} elseif ( $integer <= 991231235959 ) {
			// Through 99-12-31 23:59:59. Expand YYMMDDHHMMSS to years 1970-1999.
			$integer += 19000000000000;
			$width    = 14;
		} else {
			// YYYYMMDDHHMMSS with a year below 1000.
			$width = 14;
		}

		// Enforce 14 digits, not calendar validity.
		if ( $integer > 99999999999999 ) {
			return null;
		}

		return str_pad( sprintf( '%.0F', $integer ), $width, '0', STR_PAD_LEFT )
			. ( 14 === $width && isset( $matches[2] ) ? '.' . $matches[2] : '' );
	}

	/**
	 * Carry fractional seconds or convert an explicit timezone offset.
	 * Incomplete dates cannot be shifted, even when the day would not change.
	 *
	 * @param array $parts   The parsed date parts.
	 * @param int   $seconds The adjustment in seconds.
	 * @return array|null The adjusted parts, or NULL for an invalid date or overflow.
	 */
	private static function shift_seconds( $parts, $seconds ): ?array {
		if ( 0 === $parts['month'] || 0 === $parts['day'] ) {
			return null;
		}
		$seconds        += $parts['hour'] * 3600 + $parts['minute'] * 60 + $parts['second'];
		$days            = (int) floor( $seconds / 86400 );
		$seconds        -= $days * 86400;
		$parts['hour']   = intdiv( $seconds, 3600 );
		$parts['minute'] = intdiv( $seconds % 3600, 60 );
		$parts['second'] = $seconds % 60;
		$parts['day']   += $days;
		while ( $parts['day'] < 1 ) {
			if ( --$parts['month'] < 1 ) {
				$parts['month'] = 12;
				--$parts['year'];
			}
			$parts['day'] += self::days_in_month( $parts['year'], $parts['month'] );
		}
		while ( $parts['day'] > self::days_in_month( $parts['year'], $parts['month'] ) ) {
			$parts['day'] -= self::days_in_month( $parts['year'], $parts['month'] );
			if ( ++$parts['month'] > 12 ) {
				$parts['month'] = 1;
				++$parts['year'];
			}
		}
		if ( $parts['year'] < 0 || $parts['year'] > 9999 ) {
			return null;
		}
		// MySQL's conversion from a day number maps days in year zero to the zero date.
		if ( 0 === $parts['year'] ) {
			$parts['month'] = 0;
			$parts['day']   = 0;
		}
		return $parts;
	}

	/**
	 * Get the number of days in a month, or zero for an invalid month.
	 *
	 * @param int $year  The year.
	 * @param int $month The month.
	 * @return int The number of days.
	 */
	private static function days_in_month( $year, $month ): int {
		static $days = array( 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
		return 2 === $month && 366 === self::days_in_year( $year ) ? 29 : ( $days[ $month - 1 ] ?? 0 );
	}

	/**
	 * Get the number of days in a year using MySQL's calendar rules.
	 *
	 * @param int|float $year The year, including wrapped unsigned values.
	 * @return int The number of days.
	 */
	private static function days_in_year( $year ): int {
		$year    = (float) $year;
		$is_leap = 0.0 === fmod( $year, 4.0 )
			&& ( 0.0 !== fmod( $year, 100.0 ) || ( 0.0 === fmod( $year, 400.0 ) && 0.0 !== $year ) );
		return $is_leap ? 366 : 365;
	}
}
