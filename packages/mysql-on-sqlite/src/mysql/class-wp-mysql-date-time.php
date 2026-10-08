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
	 * Month names for MySQL's default en_US locale, indexed from January at 0.
	 */
	private const MONTH_NAMES_EN_US = array( 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' );

	/**
	 * Weekday names for MySQL's default en_US locale, indexed from Monday at 0.
	 */
	private const WEEKDAY_NAMES_EN_US = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );

	/**
	 * Flag to start weeks on Monday instead of Sunday.
	 *
	 * The WEEK_FLAG_* constants match MySQL's calc_week() flags.
	 * @see https://github.com/mysql/mysql-server/blob/mysql-8.0.46/include/my_time.h
	 */
	private const WEEK_FLAG_MONDAY_FIRST = 1;

	/**
	 * Flag to use weeks 1-53 and the corresponding week-year instead of weeks 0-53.
	 *
	 * The WEEK_FLAG_* constants match MySQL's calc_week() flags.
	 * @see https://github.com/mysql/mysql-server/blob/mysql-8.0.46/include/my_time.h
	 */
	private const WEEK_FLAG_YEAR = 2;

	/**
	 * Flag to start week 1 on the year's first Monday or Sunday, according to WEEK_FLAG_MONDAY_FIRST.
	 * Otherwise, week 1 must contain at least four days of the year.
	 *
	 * The WEEK_FLAG_* constants match MySQL's calc_week() flags.
	 * @see https://github.com/mysql/mysql-server/blob/mysql-8.0.46/include/my_time.h
	 */
	private const WEEK_FLAG_FIRST_WEEKDAY = 4;

	/**
	 * Map DATE_FORMAT() specifiers to date parts and minimum output widths.
	 */
	private const FORMAT_COMPONENTS = array(
		'c' => array( 'month', 1 ),
		'd' => array( 'day', 2 ),
		'e' => array( 'day', 1 ),
		'f' => array( 'microsecond', 6 ),
		'H' => array( 'hour', 2 ),
		'i' => array( 'minute', 2 ),
		'k' => array( 'hour', 1 ),
		'm' => array( 'month', 2 ),
		'S' => array( 'second', 2 ),
		's' => array( 'second', 2 ),
		'Y' => array( 'year', 4 ),
	);

	/**
	 * Map DATE_FORMAT() week and week-year specifiers to week calculation flags.
	 */
	private const FORMAT_WEEK_FLAGS = array(
		'U' => self::WEEK_FLAG_FIRST_WEEKDAY,
		'u' => self::WEEK_FLAG_MONDAY_FIRST,
		'V' => self::WEEK_FLAG_YEAR | self::WEEK_FLAG_FIRST_WEEKDAY,
		'v' => self::WEEK_FLAG_YEAR | self::WEEK_FLAG_MONDAY_FIRST,
		'X' => self::WEEK_FLAG_YEAR | self::WEEK_FLAG_FIRST_WEEKDAY,
		'x' => self::WEEK_FLAG_YEAR | self::WEEK_FLAG_MONDAY_FIRST,
	);

	/**
	 * Format a MySQL date or datetime value.
	 *
	 * @param mixed       $date   The date value.
	 * @param string|null $format The MySQL format.
	 * @return string|null The formatted date, or NULL for invalid input or unavailable components.
	 */
	public static function format( $date, $format ): ?string {
		if ( null === $date || null === $format || '' === $format ) {
			return null;
		}
		$date_parts = self::parse( $date );
		return null === $date_parts ? null : self::format_parts( $date_parts, (string) $format );
	}

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
	 * Format a date using MySQL DATE_FORMAT() specifiers.
	 *
	 * @param array  $date_parts The parsed date parts.
	 * @param string $format     The MySQL format.
	 * @return string|null The formatted date, or NULL when a required component is unavailable.
	 */
	private static function format_parts( $date_parts, $format ): ?string {
		$weeks  = array();
		$result = '';
		$length = strlen( $format );
		for ( $i = 0; $i < $length; ++$i ) {
			if ( '%' !== $format[ $i ] || $i + 1 === $length ) {
				$result .= $format[ $i ];
				continue;
			}

			$specifier = $format[ ++$i ];
			if ( isset( self::FORMAT_COMPONENTS[ $specifier ] ) ) {
				list( $component, $width ) = self::FORMAT_COMPONENTS[ $specifier ];
				$result                   .= str_pad( (string) $date_parts[ $component ], $width, '0', STR_PAD_LEFT );
				continue;
			}
			if ( isset( self::FORMAT_WEEK_FLAGS[ $specifier ] ) ) {
				$behavior = self::FORMAT_WEEK_FLAGS[ $specifier ];
				if ( ! isset( $weeks[ $behavior ] ) ) {
					$weeks[ $behavior ] = self::week( $date_parts, $behavior );
				}
				$result .= 'X' === $specifier || 'x' === $specifier
					? str_pad( (string) $weeks[ $behavior ]['year'], 4, '0', STR_PAD_LEFT )
					: str_pad( (string) $weeks[ $behavior ]['week'], 2, '0', STR_PAD_LEFT );
				continue;
			}

			switch ( $specifier ) {
				case 'a':
				case 'W':
				case 'w':
					if ( 0 === $date_parts['year'] && 0 === $date_parts['month'] ) {
						return null;
					}
					$weekday = self::weekday(
						self::day_number( $date_parts['year'], $date_parts['month'], $date_parts['day'] ),
						false
					);
					if ( 'w' === $specifier ) {
						$result .= ( $weekday + 1 ) % 7;
					} else {
						$result .= 'a' === $specifier ? substr( self::WEEKDAY_NAMES_EN_US[ $weekday ], 0, 3 ) : self::WEEKDAY_NAMES_EN_US[ $weekday ];
					}
					break;
				case 'b':
				case 'M':
					if ( 0 === $date_parts['month'] ) {
						return null;
					}
					$month   = self::MONTH_NAMES_EN_US[ $date_parts['month'] - 1 ];
					$result .= 'b' === $specifier ? substr( $month, 0, 3 ) : $month;
					break;
				case 'D':
					$suffixes = array( 'th', 'st', 'nd', 'rd' );
					$suffix   = $date_parts['day'] >= 10 && $date_parts['day'] <= 19
						? 'th'
						: ( $suffixes[ $date_parts['day'] % 10 ] ?? 'th' );
					$result  .= $date_parts['day'] . $suffix;
					break;
				case 'h':
				case 'I':
				case 'l':
					$hour    = ( $date_parts['hour'] + 11 ) % 12 + 1;
					$result .= str_pad( (string) $hour, 'l' === $specifier ? 1 : 2, '0', STR_PAD_LEFT );
					break;
				case 'j':
					$day     = self::day_number( $date_parts['year'], $date_parts['month'], $date_parts['day'] )
						- self::day_number( $date_parts['year'], 1, 1 ) + 1;
					$result .= str_pad( (string) $day, 3, '0', STR_PAD_LEFT );
					break;
				case 'p':
					$result .= $date_parts['hour'] < 12 ? 'AM' : 'PM';
					break;
				case 'r':
				case 'T':
					$result .= self::format_parts( $date_parts, 'r' === $specifier ? '%h:%i:%s %p' : '%H:%i:%s' );
					break;
				case 'y':
					$result .= sprintf( '%02d', $date_parts['year'] % 100 );
					break;
				default:
					$result .= $specifier;
			}
		}
		return $result;
	}

	/**
	 * Get a MySQL week number and its corresponding year.
	 *
	 * Matches calc_week() in MySQL's mysys/my_time.cc.
	 * @see https://github.com/mysql/mysql-server/blob/mysql-8.0.46/mysys/my_time.cc#L2198
	 *
	 * @param array $date_parts The parsed date parts.
	 * @param int   $behavior   A combination of WEEK_FLAG_* flags.
	 * @return array The week and year.
	 */
	private static function week( $date_parts, $behavior ): array {
		$day_number       = self::day_number( $date_parts['year'], $date_parts['month'], $date_parts['day'] );
		$first_day_number = self::day_number( $date_parts['year'], 1, 1 );
		$monday_first     = 0 !== ( $behavior & self::WEEK_FLAG_MONDAY_FIRST );
		$week_year        = 0 !== ( $behavior & self::WEEK_FLAG_YEAR );
		$first_weekday    = 0 !== ( $behavior & self::WEEK_FLAG_FIRST_WEEKDAY );
		$weekday          = self::weekday( $first_day_number, ! $monday_first );
		$year             = $date_parts['year'];

		if ( 1 === $date_parts['month'] && $date_parts['day'] <= 7 - $weekday ) {
			if ( ! $week_year && ( $first_weekday ? 0 !== $weekday : $weekday >= 4 ) ) {
				return array(
					'week' => 0,
					'year' => $year,
				);
			}
			$week_year         = true;
			$year              = self::unsigned_int_32( $year - 1 );
			$days              = self::days_in_year( $year );
			$first_day_number -= $days;
			$weekday           = ( $weekday + 53 * 7 - $days ) % 7;
		}

		if ( $first_weekday ? 0 !== $weekday : $weekday >= 4 ) {
			$days = $day_number - ( $first_day_number + ( 7 - $weekday ) );
		} else {
			$days = $day_number - ( $first_day_number - $weekday );
		}
		$days = self::unsigned_int_32( $days );

		if ( $week_year && $days >= 52 * 7 ) {
			$weekday = ( $weekday + self::days_in_year( $year ) ) % 7;
			if ( $first_weekday ? 0 === $weekday : $weekday < 4 ) {
				return array(
					'week' => 1,
					'year' => self::unsigned_int_32( $year + 1 ),
				);
			}
		}

		return array(
			'week' => (int) floor( $days / 7 ) + 1,
			'year' => $year,
		);
	}

	/**
	 * Calculate MySQL's day number for raw date parts.
	 *
	 * Matches calc_daynr() in MySQL's mysys/my_time.cc.
	 * @see https://github.com/mysql/mysql-server/blob/mysql-8.0.46/mysys/my_time.cc#L1042
	 *
	 * @param int $year  The year.
	 * @param int $month The month.
	 * @param int $day   The day.
	 * @return int The number of days since 0000-00-00.
	 */
	private static function day_number( $year, $month, $day ): int {
		if ( 0 === $year && 0 === $month ) {
			return 0;
		}

		$day_number = 365 * $year + 31 * ( $month - 1 ) + $day;
		if ( $month <= 2 ) {
			--$year;
		} else {
			$day_number -= intdiv( $month * 4 + 23, 10 );
		}
		$century_leap_days = intdiv( ( intdiv( $year, 100 ) + 1 ) * 3, 4 );

		return $day_number + intdiv( $year, 4 ) - $century_leap_days;
	}

	/**
	 * Get a weekday from a MySQL day number.
	 *
	 * @param int  $day_number  The day number.
	 * @param bool $sunday_first Whether Sunday is weekday zero.
	 * @return int The weekday number.
	 */
	private static function weekday( $day_number, $sunday_first ): int {
		return ( $day_number + 5 + ( $sunday_first ? 1 : 0 ) ) % 7;
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

	/**
	 * Wrap a number like a MySQL unsigned 32-bit integer.
	 *
	 * @param int|float $number The number.
	 * @return float The wrapped integer value, represented exactly as a float for 32-bit PHP.
	 */
	private static function unsigned_int_32( $number ): float {
		$number = fmod( (float) $number, 4294967296.0 );
		return $number < 0 ? $number + 4294967296.0 : $number;
	}
}
