<?php
/**
 * Custom functions for the SQLite implementation.
 */

/**
 * Registers MySQL-compatible functions with PDO SQLite.
 *
 * Each callback implements a MySQL SQL function that SQLite does not provide.
 *
 * @access private
 */
class WP_SQLite_PDO_User_Defined_Functions {

	/**
	 * Register the user-defined SQLite functions on a PDO connection.
	 *
	 * The functions are registered using PDO::sqliteCreateFunction().
	 *
	 * @param PDO|Pdo\Sqlite $pdo The PDO object.
	 */
	public static function register_for( $pdo ): self {
		$instance = new self();
		foreach ( $instance->functions as $f => $t ) {
			$arities = $instance->function_arities[ $f ] ?? array( -1 );
			foreach ( $arities as $arity ) {
				if ( $pdo instanceof Pdo\Sqlite ) {
					$pdo->createFunction( $f, array( $instance, $t ), $arity );
				} else {
					$pdo->sqliteCreateFunction( $f, array( $instance, $t ), $arity );
				}
			}
		}
		return $instance;
	}

	/**
	 * Array to define MySQL function => function defined with PHP.
	 *
	 * Replaced functions must be public.
	 *
	 * @var array
	 */
	private $functions = array(
		'throw'                        => 'throw',
		'month'                        => 'month',
		'monthnum'                     => 'month',
		'year'                         => 'year',
		'day'                          => 'day',
		'hour'                         => 'hour',
		'minute'                       => 'minute',
		'second'                       => 'second',
		'week'                         => 'week',
		'weekday'                      => 'weekday',
		'dayofweek'                    => 'dayofweek',
		'dayofmonth'                   => 'dayofmonth',
		'unix_timestamp'               => 'unix_timestamp',
		'now'                          => 'now',
		'md5'                          => 'md5',
		'curdate'                      => 'curdate',
		'rand'                         => 'rand',
		'from_unixtime'                => 'from_unixtime',
		'localtime'                    => 'now',
		'localtimestamp'               => 'now',
		'isnull'                       => 'isnull',
		'regexp'                       => 'regexp',
		'regexp_like'                  => 'regexp_like',
		'regexp_replace'               => 'regexp_replace',
		'regexp_substr'                => 'regexp_substr',
		'regexp_instr'                 => 'regexp_instr',
		'field'                        => 'field',
		'log'                          => 'log',
		'least'                        => 'least',
		'greatest'                     => 'greatest',
		'get_lock'                     => 'get_lock',
		'release_lock'                 => 'release_lock',
		'ucase'                        => 'ucase',
		'lcase'                        => 'lcase',
		'unhex'                        => 'unhex',
		'from_base64'                  => 'from_base64',
		'to_base64'                    => 'to_base64',
		'inet_ntoa'                    => 'inet_ntoa',
		'inet_aton'                    => 'inet_aton',
		'datediff'                     => 'datediff',
		'locate'                       => 'locate',
		'utc_date'                     => 'utc_date',
		'utc_time'                     => 'utc_time',
		'utc_timestamp'                => 'utc_timestamp',
		'version'                      => 'version',
		'reverse'                      => 'reverse',

		// Internal helper functions.
		'_helper_like_to_glob_pattern' => '_helper_like_to_glob_pattern',
	);

	/**
	 * Exact argument counts for functions with optional arguments.
	 *
	 * Functions absent from this array are registered as variadic.
	 *
	 * @var array
	 */
	private $function_arities = array(
		'regexp'         => array( 2, 3 ),
		'regexp_like'    => array( 2, 3 ),
		'regexp_replace' => array( 3, 4, 5, 6 ),
		'regexp_substr'  => array( 2, 3, 4, 5 ),
		'regexp_instr'   => array( 2, 3, 4, 5, 6 ),
	);

	/** @var array|null Pattern, match type, and byte mode of the last compiled regex. */
	private $regexp_cached_key = null;

	/** @var string|null Last compiled PCRE pattern. */
	private $regexp_cached_compiled = null;

	/**
	 * First element of the RAND(N) LCG state (the value the output is derived from).
	 *
	 * @var int|null
	 */
	private $rand_seed1 = null;

	/**
	 * Second element of the RAND(N) LCG state (the paired value used in the recurrence).
	 *
	 * @var int|null
	 */
	private $rand_seed2 = null;

	/**
	 * Last seed value passed to RAND(N) in the current statement.
	 *
	 * Used to detect whether the rand sequence is advancing with the same seed
	 * (e.g. "SELECT RAND(3) FROM t"), or reseeding (starting a new sequence).
	 *
	 * @var int|null
	 */
	private $rand_last_seed = null;

	/**
	 * Clear any per-statement state held by the UDFs.
	 */
	public function flush(): void {
		$this->rand_seed1     = null;
		$this->rand_seed2     = null;
		$this->rand_last_seed = null;
	}

	/**
	 * A helper function to throw an error from SQLite expressions.
	 *
	 * @param string $message The error message.
	 *
	 * @throws Exception The error message.
	 * @return void
	 */
	public function throw( $message ): void {
		throw new Exception( $message );
	}

	/**
	 * Method to return the unix timestamp.
	 *
	 * Used without an argument, it returns PHP time() function (total seconds passed
	 * from '1970-01-01 00:00:00' GMT). Used with the argument, it changes the value
	 * to the timestamp.
	 *
	 * @param string $field Representing the date formatted as '0000-00-00 00:00:00'.
	 *
	 * @return number of unsigned integer
	 */
	public function unix_timestamp( $field = null ) {
		return is_null( $field ) ? time() : strtotime( $field );
	}

	/**
	 * Method to emulate MySQL FROM_UNIXTIME() function.
	 *
	 * @param int    $field The unix timestamp.
	 * @param string $format Indicate the way of formatting(optional).
	 *
	 * @return string
	 */
	public function from_unixtime( $field, $format = null ) {
		// Convert to ISO time.
		$date = gmdate( 'Y-m-d H:i:s', $field );

		return is_null( $format ) ? $date : $this->dateformat( $date, $format );
	}

	/**
	 * Method to emulate MySQL NOW() function.
	 *
	 * @return string representing current time formatted as '0000-00-00 00:00:00'.
	 */
	public function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Method to emulate MySQL CURDATE() function.
	 *
	 * @return string representing current time formatted as '0000-00-00'.
	 */
	public function curdate() {
		return gmdate( 'Y-m-d' );
	}

	/**
	 * Method to emulate MySQL MD5() function.
	 *
	 * @param string $field The string to be hashed.
	 *
	 * @return string of the md5 hash value of the argument.
	 */
	public function md5( $field ) {
		return md5( $field );
	}

	/**
	 * Method to emulate MySQL's seeded RAND(N) function.
	 *
	 * Implements MySQL's deterministic LCG (Linear Congruential Generator),
	 * producing bit-exact output for a given seed.
	 *
	 * Known divergences from MySQL:
	 *
	 *  1. In MySQL, RAND(N) behaves differently depending on whether the seed
	 *     is constant expression or varies per invocation:
	 *      - Constant seed (e.g. "SELECT RAND(3) FROM t"):
	 *        LCG is initialized once per statement and advanced for each row.
	 *      - Non-constant seed (e.g. "SELECT RAND(col) FROM t"):
	 *        LCG is initialized for every row with its seed value.
	 *
	 *     A SQLite UDF cannot tell whether the seed expression is constant, so
	 *     we just compare the seed against its last value. This diverges from
	 *     MySQL in rare cases, and we can consider improving it in the future.
	 *
	 *  2. The LCG state is shared across call sites in the same query, so
	 *     "SELECT RAND(1), RAND(1)" yields different results here than in MySQL.
	 *     This is a rare edge case that we can consider improving in the future.
	 *
	 * Unseeded RAND() never reaches this function. The AST driver translates it
	 * directly to a more efficient SQLite-native expression.
	 *
	 * @param int|float|string|null $seed Seed value.
	 *
	 * @return float A value in [0, 1).
	 */
	public function rand( $seed ) {
		// Requires 64-bit PHP. Seed * 0x10000001 can exceed PHP_INT_MAX on 32-bit.
		$max_value = 0x3FFFFFFF;

		if ( null === $seed ) {
			// MySQL treats NULL seed as 0.
			$seed = 0;
		} elseif ( ! is_int( $seed ) ) {
			/*
			 * MySQL rounds float values and numeric strings take the same path.
			 * Reduce the value to a 32-bit range using "fmod" to avoid firing
			 * the "out-of-range float to int" cast deprecation on PHP 8.1+.
			 */
			$seed = (int) fmod( round( (float) $seed, 0, PHP_ROUND_HALF_EVEN ), 0x100000000 );
		}

		// Initialize MySQL's internal 30-bit seeds.
		if ( $seed !== $this->rand_last_seed ) {
			/*
			 * MySQL casts to uint32, and the intermediate results wrap at 32-bit
			 * unsigned boundaries. We emulate this with & 0xFFFFFFFF masks.
			 */
			$seed_u32             = $seed & 0xFFFFFFFF;
			$this->rand_seed1     = ( ( $seed_u32 * 0x10001 + 55555555 ) & 0xFFFFFFFF ) % $max_value;
			$this->rand_seed2     = ( ( $seed_u32 * 0x10000001 ) & 0xFFFFFFFF ) % $max_value;
			$this->rand_last_seed = $seed;
		}

		/*
		 * MySQL's LCG recurrence:
		 *   seed1 = (seed1 * 3 + seed2) % max_value
		 *   seed2 = (seed1 + seed2 + 33) % max_value
		 *
		 * Note that seed1 is updated first and the new value is used for seed2.
		 */
		$this->rand_seed1 = ( $this->rand_seed1 * 3 + $this->rand_seed2 ) % $max_value;
		$this->rand_seed2 = ( $this->rand_seed1 + $this->rand_seed2 + 33 ) % $max_value;

		return (float) $this->rand_seed1 / (float) $max_value;
	}

	/**
	 * Method to emulate MySQL DATEFORMAT() function.
	 *
	 * @param string $date   Formatted as '0000-00-00' or datetime as '0000-00-00 00:00:00'.
	 * @param string $format The string format.
	 *
	 * @return string formatted according to $format
	 */
	public function dateformat( $date, $format ) {
		$mysql_php_date_formats = array(
			'%a' => 'D',
			'%b' => 'M',
			'%c' => 'n',
			'%D' => 'jS',
			'%d' => 'd',
			'%e' => 'j',
			'%H' => 'H',
			'%h' => 'h',
			'%I' => 'h',
			'%i' => 'i',
			'%j' => 'z',
			'%k' => 'G',
			'%l' => 'g',
			'%M' => 'F',
			'%m' => 'm',
			'%p' => 'A',
			'%r' => 'h:i:s A',
			'%S' => 's',
			'%s' => 's',
			'%T' => 'H:i:s',
			'%U' => 'W',
			'%u' => 'W',
			'%V' => 'W',
			'%v' => 'W',
			'%W' => 'l',
			'%w' => 'w',
			'%X' => 'Y',
			'%x' => 'o',
			'%Y' => 'Y',
			'%y' => 'y',
		);

		$time   = strtotime( $date );
		$format = strtr( $format, $mysql_php_date_formats );

		return gmdate( $format, $time );
	}

	/**
	 * Method to extract the month value from the date.
	 *
	 * @param string $field Representing the date formatted as 0000-00-00.
	 *
	 * @return string Representing the number of the month between 1 and 12.
	 */
	public function month( $field ) {
		/*
		 * MySQL returns 0 for MONTH('0000-00-00') and for dates with
		 * zero month parts like '2020-00-15'. PHP's strtotime() can't
		 * parse these, so we extract the month directly from the string.
		 */
		if ( preg_match( '/^\d{4}-(\d{2})/', $field, $matches ) ) {
			return intval( $matches[1] );
		}
		/*
		 * From https://www.php.net/manual/en/datetime.format.php:
		 *
		 * n - Numeric representation of a month, without leading zeros.
		 *     1 through 12
		 */
		return intval( gmdate( 'n', strtotime( $field ) ) );
	}

	/**
	 * Method to extract the year value from the date.
	 *
	 * @param string $field Representing the date formatted as 0000-00-00.
	 *
	 * @return string Representing the number of the year.
	 */
	public function year( $field ) {
		/*
		 * MySQL returns 0 for YEAR('0000-00-00'). PHP's strtotime()
		 * can't parse zero dates, so we extract the year directly.
		 */
		if ( preg_match( '/^(\d{4})-\d{2}/', $field, $matches ) ) {
			return intval( $matches[1] );
		}
		/*
		 * From https://www.php.net/manual/en/datetime.format.php:
		 *
		 * Y - A full numeric representation of a year, 4 digits.
		 */
		return intval( gmdate( 'Y', strtotime( $field ) ) );
	}

	/**
	 * Method to extract the day value from the date.
	 *
	 * @param string $field Representing the date formatted as 0000-00-00.
	 *
	 * @return string Representing the number of the day of the month from 1 and 31.
	 */
	public function day( $field ) {
		/*
		 * MySQL returns 0 for DAY('0000-00-00') and for dates with
		 * zero day parts like '2020-01-00'. PHP's strtotime() can't
		 * parse these, so we extract the day directly from the string.
		 */
		if ( preg_match( '/^\d{4}-\d{2}-(\d{2})/', $field, $matches ) ) {
			return intval( $matches[1] );
		}
		/*
		 * From https://www.php.net/manual/en/datetime.format.php:
		 *
		 * j - Day of the month without leading zeros.
		 *     1 to 31.
		 */
		return intval( gmdate( 'j', strtotime( $field ) ) );
	}

	/**
	 * Method to emulate MySQL SECOND() function.
	 *
	 * @see https://www.php.net/manual/en/datetime.format.php
	 *
	 * @param string $field Representing the time formatted as '00:00:00'.
	 *
	 * @return number Unsigned integer
	 */
	public function second( $field ) {
		/*
		 * From https://www.php.net/manual/en/datetime.format.php:
		 *
		 * s - Seconds, with leading zeros (00 to 59)
		 */
		return intval( gmdate( 's', strtotime( $field ) ) );
	}

	/**
	 * Method to emulate MySQL MINUTE() function.
	 *
	 * @param string $field Representing the time formatted as '00:00:00'.
	 *
	 * @return int
	 */
	public function minute( $field ) {
		/*
		 * From https://www.php.net/manual/en/datetime.format.php:
		 *
		 * i - Minutes with leading zeros.
		 *     00 to 59.
		 */
		return intval( gmdate( 'i', strtotime( $field ) ) );
	}

	/**
	 * Method to emulate MySQL HOUR() function.
	 *
	 * Returns the hour for time, in 24-hour format, from 0 to 23.
	 * Importantly, midnight is 0, not 24.
	 *
	 * @param string $time Representing the time formatted, like '14:08:12'.
	 *
	 * @return int
	 */
	public function hour( $time ) {
		/*
		 * From https://www.php.net/manual/en/datetime.format.php:
		 *
		 * H   24-hour format of an hour with leading zeros.
		 *     00 through 23.
		 */
		return intval( gmdate( 'H', strtotime( $time ) ) );
	}

	/**
	 * Covers MySQL WEEK() function.
	 *
	 * Always assumes $mode = 1.
	 *
	 * @TODO: Support other modes.
	 *
	 * From https://dev.mysql.com/doc/refman/8.0/en/date-and-time-functions.html#function_week:
	 *
	 * > Returns the week number for date. The two-argument form of WEEK()
	 * > enables you to specify whether the week starts on Sunday or Monday
	 * > and whether the return value should be in the range from 0 to 53
	 * > or from 1 to 53. If the mode argument is omitted, the value of the
	 * > default_week_format system variable is used.
	 * >
	 * > The following table describes how the mode argument works:
	 * >
	 * > Mode   First day of week   Range   Week 1 is the first week …
	 * > 0      Sunday              0-53    with a Sunday in this year
	 * > 1      Monday              0-53    with 4 or more days this year
	 * > 2      Sunday              1-53    with a Sunday in this year
	 * > 3      Monday              1-53    with 4 or more days this year
	 * > 4      Sunday              0-53    with 4 or more days this year
	 * > 5      Monday              0-53    with a Monday in this year
	 * > 6      Sunday              1-53    with 4 or more days this year
	 * > 7      Monday              1-53    with a Monday in this year
	 *
	 * @param string $field Representing the date.
	 * @param int    $mode  The mode argument.
	 */
	public function week( $field, $mode ) {
		/*
		 * From https://www.php.net/manual/en/datetime.format.php:
		 *
		 * W - ISO-8601 week number of year, weeks starting on Monday.
		 *     Example: 42 (the 42nd week in the year)
		 *
		 * Week 1 is the first week with a Thursday in it.
		 */
		return intval( gmdate( 'W', strtotime( $field ) ) );
	}

	/**
	 * Simulates WEEKDAY() function in MySQL.
	 *
	 * Returns the day of the week as an integer.
	 * The days of the week are numbered 0 to 6:
	 * * 0 for Monday
	 * * 1 for Tuesday
	 * * 2 for Wednesday
	 * * 3 for Thursday
	 * * 4 for Friday
	 * * 5 for Saturday
	 * * 6 for Sunday
	 *
	 * @param string $field Representing the date.
	 *
	 * @return int
	 */
	public function weekday( $field ) {
		/*
		 * date('N') returns 1 (for Monday) through 7 (for Sunday)
		 * That's one more than MySQL.
		 * Let's subtract one to make it compatible.
		 */
		return intval( gmdate( 'N', strtotime( $field ) ) ) - 1;
	}

	/**
	 * Method to emulate MySQL DAYOFMONTH() function.
	 *
	 * @see https://dev.mysql.com/doc/refman/8.0/en/date-and-time-functions.html#function_dayofmonth
	 *
	 * @param string $field Representing the date.
	 *
	 * @return int Returns the day of the month for date as a number in the range 1 to 31.
	 */
	public function dayofmonth( $field ) {
		return intval( gmdate( 'j', strtotime( $field ) ) );
	}

	/**
	 * Method to emulate MySQL DAYOFWEEK() function.
	 *
	 * > Returns the weekday index for date (1 = Sunday, 2 = Monday, …, 7 = Saturday).
	 * > These index values correspond to the ODBC standard. Returns NULL if date is NULL.
	 *
	 * @param string $field Representing the date.
	 *
	 * @return int Returns the weekday index for date (1 = Sunday, 2 = Monday, …, 7 = Saturday).
	 */
	public function dayofweek( $field ) {
		/**
		 * From https://www.php.net/manual/en/datetime.format.php:
		 *
		 * `w` – Numeric representation of the day of the week
		 *     0 (for Sunday) through 6 (for Saturday)
		 */
		return intval( gmdate( 'w', strtotime( $field ) ) ) + 1;
	}

	/**
	 * Method to emulate MySQL DATE() function.
	 *
	 * @see https://www.php.net/manual/en/datetime.format.php
	 *
	 * @param string $date formatted as unix time.
	 *
	 * @return string formatted as '0000-00-00'.
	 */
	public function date( $date ) {
		return gmdate( 'Y-m-d', strtotime( $date ) );
	}

	/**
	 * Method to emulate MySQL ISNULL() function.
	 *
	 * This function returns true if the argument is null, and true if not.
	 *
	 * @param mixed $field The field to be tested.
	 *
	 * @return boolean
	 */
	public function isnull( $field ) {
		return is_null( $field );
	}

	/**
	 * Method to emulate the MySQL REGEXP operator.
	 *
	 * @param string|null $pattern Regular expression to match.
	 * @param string|null $field   Haystack.
	 * @param int         $binary  1 for REGEXP BINARY, which matches bytes
	 *                             case-sensitively; 0 otherwise.
	 *
	 * @throws Exception If the pattern is not a valid regular expression.
	 * @return int|null 1 if matched, 0 if not matched, or NULL for NULL input.
	 */
	public function regexp( $pattern, $field, $binary = 0 ) {
		if ( null === $pattern || null === $field ) {
			return null;
		}

		/*
		 * Data that is not valid UTF-8 can only be a binary string in MySQL,
		 * and MySQL matches binary strings byte by byte and case-sensitively.
		 */
		$byte_mode = 1 === $binary || ! $this->regexp_is_utf8( $pattern ) || ! $this->regexp_is_utf8( $field );
		$compiled  = $this->regexp_compile( $pattern, $byte_mode ? 'c' : '', $byte_mode );
		$result    = preg_match( $compiled, $field );
		if ( false === $result ) {
			$this->regexp_fail( $pattern );
		}
		return $result;
	}

	/**
	 * Method to emulate MySQL REGEXP_LIKE() function.
	 *
	 * @param string|null $expr       The subject string.
	 * @param string|null $pattern    The regex pattern.
	 * @param string|null $match_type Optional MySQL match_type flags.
	 *
	 * @throws Exception If the pattern is not a valid regular expression.
	 * @return int|null 1 on match, 0 on no match, NULL if any argument is NULL.
	 */
	public function regexp_like( $expr, $pattern, $match_type = '' ) {
		if ( null === $expr || null === $pattern || null === $match_type ) {
			return null;
		}
		$compiled = $this->regexp_compile( $pattern, $match_type );
		$result   = preg_match( $compiled, $expr );
		if ( false === $result ) {
			$this->regexp_fail( $pattern );
		}
		return $result;
	}

	/**
	 * Method to emulate MySQL REGEXP_REPLACE() function.
	 *
	 * Uses MySQL/ICU replacement grammar: "$N" backreferences ("$0" is the
	 * full match), "${name}" named references, and "\X" emits X (drops the
	 * backslash).
	 * Negative `occurrence` is clamped to 1; `pos = char_count + 1` is
	 * accepted and can match a zero-width pattern at the end of the subject.
	 *
	 * @param string|null $expr        Subject string.
	 * @param string|null $pattern     Regex pattern.
	 * @param string|null $replacement Replacement string (supports $N backreferences).
	 * @param int|float|string|null $pos        1-based character position to start matching.
	 * @param int|float|string|null $occurrence Nth match to replace; 0 = all matches.
	 * @param string|null $match_type  MySQL match_type flags.
	 *
	 * @throws Exception If the pattern is not a valid regular expression, or pos is out of range.
	 * @return string|null The replaced string, or NULL if any argument is NULL.
	 */
	public function regexp_replace( $expr, $pattern, $replacement, $pos = 1, $occurrence = 0, $match_type = '' ) {
		if (
			null === $expr || null === $pattern || null === $replacement
			|| null === $pos || null === $occurrence || null === $match_type
		) {
			return null;
		}
		$compiled = $this->regexp_compile( $pattern, $match_type );

		$byte_start = $this->regexp_char_to_byte_offset( $expr, (int) $pos, true );

		// 0 means replace all; negative occurrences are clamped to 1 (MySQL behavior).
		$n = (int) $occurrence;
		if ( $n < 0 ) {
			$n = 1;
		}
		if ( '' === $expr ) {
			return $expr;
		}

		/*
		 * List the capture group keys, by index and name. preg_match_all()
		 * reports every group even when nothing matches, while preg_match()
		 * omits trailing unmatched groups before PHP 7.4.
		 */
		preg_match_all( $compiled, '', $groups );
		$group_keys = array_flip( array_keys( $groups ) );

		// Rebuild the subject while streaming through the matches. With
		// a positive occurrence, replace only that match.
		$out   = substr( $expr, 0, $byte_start );
		$cur   = $byte_start;
		$index = 0;
		foreach ( $this->regexp_matches( $compiled, $pattern, $expr, $byte_start ) as $match ) {
			if ( ++$index < $n ) {
				continue;
			}
			$match_start  = $match[0][1];
			$match_length = strlen( $match[0][0] );
			$out         .= substr( $expr, $cur, $match_start - $cur );
			$out         .= $this->regexp_expand_replacement( $replacement, $match, $group_keys );
			$cur          = $match_start + $match_length;
			if ( $n > 0 ) {
				break;
			}
		}
		$out .= substr( $expr, $cur );

		return $out;
	}

	/**
	 * Method to emulate MySQL REGEXP_SUBSTR() function.
	 *
	 * Values of `occurrence` less than 1 are clamped to 1, matching MySQL.
	 * `pos = char_count + 1` is accepted and can return a zero-width match.
	 *
	 * @param string|null $expr       Subject string.
	 * @param string|null $pattern    Regex pattern.
	 * @param int|float|string|null $pos        1-based character position to start matching.
	 * @param int|float|string|null $occurrence Which match to return (1-based; <= 0 clamps to 1).
	 * @param string|null $match_type MySQL match_type flags.
	 *
	 * @throws Exception If the pattern is not a valid regular expression, or pos is out of range.
	 * @return string|null The matched substring, NULL if no match or any argument is NULL.
	 */
	public function regexp_substr( $expr, $pattern, $pos = 1, $occurrence = 1, $match_type = '' ) {
		if (
			null === $expr || null === $pattern
			|| null === $pos || null === $occurrence || null === $match_type
		) {
			return null;
		}
		$compiled = $this->regexp_compile( $pattern, $match_type );

		// MySQL clamps occurrence <= 0 to 1.
		$n = max( 1, (int) $occurrence );

		$byte_start = $this->regexp_char_to_byte_offset( $expr, (int) $pos, true );

		$match = $this->regexp_find_nth_match( $compiled, $pattern, $expr, $byte_start, $n );
		if ( null === $match ) {
			return null;
		}
		return $match[0][0];
	}

	/**
	 * Method to emulate MySQL REGEXP_INSTR() function.
	 *
	 * Values of `occurrence` less than 1 are clamped to 1, matching MySQL.
	 * `pos` greater than char_count is rejected (unlike SUBSTR and REPLACE).
	 * Like MySQL, `pos` is not checked for an empty subject, where a match is
	 * reported at `pos`.
	 *
	 * @param string|null $expr          Subject string.
	 * @param string|null $pattern       Regex pattern.
	 * @param int|float|string|null $pos           1-based character position to start matching.
	 * @param int|float|string|null $occurrence    Which match to locate (1-based; <= 0 clamps to 1).
	 * @param int|float|string|null $return_option 0 = start of match (default), 1 = one past end.
	 * @param string|null $match_type    MySQL match_type flags.
	 *
	 * @throws Exception If the pattern is invalid, pos is out of range, or
	 *                   return_option is not 0 or 1.
	 * @return int|null 1-based character position, 0 if no match, NULL on NULL input.
	 */
	public function regexp_instr( $expr, $pattern, $pos = 1, $occurrence = 1, $return_option = 0, $match_type = '' ) {
		if (
			null === $expr || null === $pattern || null === $pos
			|| null === $occurrence || null === $return_option || null === $match_type
		) {
			return null;
		}
		$ret = (int) $return_option;
		if ( 0 !== $ret && 1 !== $ret ) {
			throw new Exception( 'Incorrect arguments to regexp_instr: return_option must be 1 or 0.' );
		}
		$compiled = $this->regexp_compile( $pattern, $match_type );

		$position = (int) $pos;

		// MySQL clamps occurrence <= 0 to 1.
		$n = max( 1, (int) $occurrence );

		$byte_start = '' === $expr ? 0 : $this->regexp_char_to_byte_offset( $expr, $position );
		$region     = substr( $expr, $byte_start );

		$match = $this->regexp_find_nth_match( $compiled, $pattern, $region, 0, $n );
		if ( null === $match ) {
			return 0;
		}
		if ( '' === $expr ) {
			return $position;
		}

		list( $matched_text, $matched_byte_offset ) = $match[0];
		$target_byte                                = $byte_start + $matched_byte_offset;
		if ( 1 === $ret ) {
			$target_byte += strlen( $matched_text );
		}

		// Every byte except a UTF-8 continuation byte starts a character.
		return $target_byte - preg_match_all( '/[\x80-\xBF]/', substr( $expr, 0, $target_byte ) ) + 1;
	}

	/**
	 * Method to emulate MySQL FIELD() function.
	 *
	 * This function gets the list argument and compares the first item to all the others.
	 * If the same value is found, it returns the position of that value. If not, it
	 * returns 0.
	 *
	 * @return int
	 */
	public function field() {
		$num_args = func_num_args();
		if ( $num_args < 2 || is_null( func_get_arg( 0 ) ) ) {
			return 0;
		}
		$arg_list      = func_get_args();
		$search_string = strtolower( array_shift( $arg_list ) );

		for ( $i = 0; $i < $num_args - 1; $i++ ) {
			if ( strtolower( $arg_list[ $i ] ) === $search_string ) {
				return $i + 1;
			}
		}

		return 0;
	}

	/**
	 * Method to emulate MySQL LOG() function.
	 *
	 * Used with one argument, it returns the natural logarithm of X.
	 * <code>
	 * LOG(X)
	 * </code>
	 * Used with two arguments, it returns the natural logarithm of X base B.
	 * <code>
	 * LOG(B, X)
	 * </code>
	 * In this case, it returns the value of log(X) / log(B).
	 *
	 * Used without an argument, it returns false. This returned value will be
	 * rewritten to 0, because SQLite doesn't understand true/false value.
	 *
	 * @return double|null
	 */
	public function log() {
		$num_args = func_num_args();
		if ( 1 === $num_args ) {
			$arg1 = func_get_arg( 0 );

			return log( $arg1 );
		}
		if ( 2 === $num_args ) {
			$arg1 = func_get_arg( 0 );
			$arg2 = func_get_arg( 1 );

			return log( $arg1 ) / log( $arg2 );
		}
		return null;
	}

	/**
	 * Method to emulate MySQL LEAST() function.
	 *
	 * This function rewrites the function name to SQLite compatible function name.
	 *
	 * @return mixed
	 */
	public function least() {
		$arg_list = func_get_args();

		return min( $arg_list );
	}

	/**
	 * Method to emulate MySQL GREATEST() function.
	 *
	 * This function rewrites the function name to SQLite compatible function name.
	 *
	 * @return mixed
	 */
	public function greatest() {
		$arg_list = func_get_args();

		return max( $arg_list );
	}

	/**
	 * Method to dummy out MySQL GET_LOCK() function.
	 *
	 * This function is meaningless in SQLite, so we do nothing.
	 *
	 * @param string  $name    Not used.
	 * @param integer $timeout Not used.
	 *
	 * @return string
	 */
	public function get_lock( $name, $timeout ) {
		return '1=1';
	}

	/**
	 * Method to dummy out MySQL RELEASE_LOCK() function.
	 *
	 * This function is meaningless in SQLite, so we do nothing.
	 *
	 * @param string $name Not used.
	 *
	 * @return string
	 */
	public function release_lock( $name ) {
		return '1=1';
	}

	/**
	 * Method to emulate MySQL UCASE() function.
	 *
	 * This is MySQL alias for upper() function. This function rewrites it
	 * to SQLite compatible name upper().
	 *
	 * @param string $content String to be converted to uppercase.
	 *
	 * @return string SQLite compatible function name.
	 */
	public function ucase( $content ) {
		return "upper($content)";
	}

	/**
	 * Method to emulate MySQL LCASE() function.
	 *
	 * This is MySQL alias for lower() function. This function rewrites it
	 * to SQLite compatible name lower().
	 *
	 * @param string $content String to be converted to lowercase.
	 *
	 * @return string SQLite compatible function name.
	 */
	public function lcase( $content ) {
		return "lower($content)";
	}

	/**
	 * Method to emulate MySQL UNHEX() function.
	 *
	 * For a string argument str, UNHEX(str) interprets each pair of characters
	 * in the argument as a hexadecimal number and converts it to the byte represented
	 * by the number. The return value is a binary string.
	 *
	 * @param string $number Number to be unhexed.
	 *
	 * @return string Binary string
	 */
	public function unhex( $number ) {
		return pack( 'H*', $number );
	}

	/**
	 * Method to emulate MySQL FROM_BASE64() function.
	 *
	 * Takes a base64-encoded string and returns the decoded result as a binary
	 * string. Returns NULL if the argument is NULL or is not a valid base64 string.
	 *
	 * @param string|null $str The base64-encoded string.
	 *
	 * @return string|null Decoded binary string, or NULL.
	 */
	public function from_base64( $str ) {
		if ( null === $str ) {
			return null;
		}
		$decoded = base64_decode( $str, true );
		if ( false === $decoded ) {
			return null;
		}
		return $decoded;
	}

	/**
	 * Method to emulate MySQL TO_BASE64() function.
	 *
	 * Takes a string and returns a base64-encoded result.
	 * Returns NULL if the argument is NULL.
	 *
	 * @param string|null $str The string to encode.
	 *
	 * @return string|null Base64-encoded string, or NULL.
	 */
	public function to_base64( $str ) {
		if ( null === $str ) {
			return null;
		}
		return base64_encode( $str );
	}

	/**
	 * Method to emulate MySQL INET_NTOA() function.
	 *
	 * This function gets 4 or 8 bytes integer and turn it into the network address.
	 *
	 * @param integer $num Long integer.
	 *
	 * @return string
	 */
	public function inet_ntoa( $num ) {
		return long2ip( $num );
	}

	/**
	 * Method to emulate MySQL INET_ATON() function.
	 *
	 * This function gets the network address and turns it into integer.
	 *
	 * @param string $addr Network address.
	 *
	 * @return int long integer
	 */
	public function inet_aton( $addr ) {
		return abs( (int) ip2long( $addr ) );
	}

	/**
	 * Method to emulate MySQL DATEDIFF() function.
	 *
	 * This function compares two dates value and returns the difference.
	 *
	 * @param string $start Start date.
	 * @param string $end   End date.
	 *
	 * @return string
	 */
	public function datediff( $start, $end ) {
		$start_date = new DateTime( $start );
		$end_date   = new DateTime( $end );
		$interval   = $end_date->diff( $start_date, false );

		return $interval->format( '%r%a' );
	}

	/**
	 * Method to emulate MySQL LOCATE() function.
	 *
	 * This function returns the position if $substr is found in $str. If not,
	 * it returns 0. If mbstring extension is loaded, mb_strpos() function is
	 * used.
	 *
	 * @param string  $substr Needle.
	 * @param string  $str    Haystack.
	 * @param integer $pos    Position.
	 *
	 * @return integer
	 */
	public function locate( $substr, $str, $pos = 0 ) {
		if ( ! extension_loaded( 'mbstring' ) ) {
			$val = strpos( $str, $substr, $pos );
			if ( false !== $val ) {
				return $val + 1;
			}
			return 0;
		}
		$val = mb_strpos( $str, $substr, $pos );
		if ( false !== $val ) {
			return $val + 1;
		}
		return 0;
	}

	/**
	 * Method to return GMT date in the string format.
	 *
	 * @return string formatted GMT date 'dddd-mm-dd'
	 */
	public function utc_date() {
		return gmdate( 'Y-m-d', time() );
	}

	/**
	 * Method to return GMT time in the string format.
	 *
	 * @return string formatted GMT time '00:00:00'
	 */
	public function utc_time() {
		return gmdate( 'H:i:s', time() );
	}

	/**
	 * Method to return GMT time stamp in the string format.
	 *
	 * @return string formatted GMT timestamp 'yyyy-mm-dd 00:00:00'
	 */
	public function utc_timestamp() {
		return gmdate( 'Y-m-d H:i:s', time() );
	}

	/**
	 * Method to return MySQL version.
	 *
	 * This function only returns the current newest version number of MySQL,
	 * because it is meaningless for SQLite database.
	 *
	 * @return string representing the version number: major_version.minor_version
	 */
	public function version() {
		return '5.5';
	}

	/**
	 * Method to emulate MySQL REVERSE() function.
	 *
	 * Reverse UTF-8 text by code point, matching MySQL behavior.
	 *
	 * @param string|null $str The string to reverse.
	 *
	 * @return string|null reversed string, or NULL.
	 */
	public function reverse( $str ) {
		if ( null === $str ) {
			return null;
		}
		if (
			preg_match( '/[^\x00-\x7F]/', $str )
			&& preg_match_all( '/./us', $str, $matches )
		) {
			return implode( '', array_reverse( $matches[0] ) );
		}
		return strrev( $str );
	}

	/**
	 * A helper to convert a LIKE pattern to a GLOB pattern for "LIKE BINARY" support.

	 * @TODO: Some of the MySQL string specifics described below are likely to
	 *        affect also other patterns than just "LIKE BINARY". We should
	 *        consider applying some of the conversions more broadly.
	 *
	 * @param string|null $pattern
	 * @return string|null
	 */
	public function _helper_like_to_glob_pattern( $pattern ) {
		if ( null === $pattern ) {
			return null;
		}

		/*
		 * 1. Escape characters that have special meaning in GLOB patterns.
		 *
		 * We need to:
		 *  1. Escape "]" as "[]]" to avoid interpreting "[...]" as a character class.
		 *  2. Escape "*" as "[*]" (must be after 1 to avoid being escaped).
		 *  3. Escape "?" as "[?]" (must be after 1 to avoid being escaped).
		 */
		$pattern = str_replace( ']', '[]]', $pattern );
		$pattern = str_replace( '*', '[*]', $pattern );
		$pattern = str_replace( '?', '[?]', $pattern );

		/*
		 * 2. Convert LIKE wildcards to GLOB wildcards ("%" -> "*", "_" -> "?").
		 *
		 * We need to convert them only when they don't follow any backslashes,
		 * or when they follow an even number of backslashes (as "\\" is "\").
		 */
		$pattern = preg_replace( '/(^|[^\\\\](?:\\\\{2})*)%/', '$1*', $pattern );
		$pattern = preg_replace( '/(^|[^\\\\](?:\\\\{2})*)_/', '$1?', $pattern );

		/*
		 * 3. Unescape LIKE escape sequences.
		 *
		 * While in MySQL LIKE patterns, a backslash is usually used to escape
		 * special characters ("%", "_", and "\"), it works with all characters.
		 *
		 * That is:
		 *   SELECT '\\x' prints '\x', but LIKE '\\x' is equivalent to LIKE 'x'.
		 *
		 * This is true also for multi-byte characters:
		 *   SELECT '\\©' prints '\©', but LIKE '\\©' is equivalent to LIKE '©'.
		 *
		 * Use "s" to include escaped newlines and omit "u" to preserve raw bytes.
		 */
		$pattern = preg_replace( '/\\\\(.)/s', '$1', $pattern );

		return $pattern;
	}

	/**
	 * Compile a MySQL-style regex into a PCRE pattern string.
	 *
	 * Translates MySQL match_type flags (c/i/m/n/u) to PCRE modifiers. The u
	 * (UTF-8) modifier is applied unless byte-oriented matching is requested.
	 * Case-insensitive is the default.
	 *
	 * MySQL's native engine is ICU; we use PHP's PCRE. The two diverge in a
	 * few corners:
	 *
	 * - Some Unicode property shorthands and POSIX class spellings differ.
	 * - PCRE accepts both `(?<name>...)` and `(?P<name>...)`; MySQL accepts
	 *   only the former and errors on the latter.
	 * - ICU supports multi-code-point case folds such as "ß" matching "ss";
	 *   PCRE's case-insensitive mode does not.
	 * - ICU-only syntax, such as `\uXXXX` and `\N{name}` in patterns, is an error.
	 * - An unknown escape, such as `\y`, is an error. ICU reads it as a literal.
	 * - ICU reads CRLF as one line break, and PCRE as two characters. `$` also
	 *   matches between them, and `.` with the `n` flag matches only one of them.
	 * - ICU's nested sets and set operations don't exist in PCRE. `[a[b]]` and
	 *   `[a&&b]` silently match something else, and `[a--b]` is usually an error.
	 * - A hyphen after a POSIX class or a property, as in `[[:alpha:]-z]` or
	 *   `[\p{L}-x]`, is an error. ICU reads it literally.
	 * - A POSIX class outside of a set, as in `[:alpha:]`, is usually an error. ICU
	 *   reads it like `[[:alpha:]]`.
	 *
	 * Known limitations of this emulation:
	 *
	 * - The default (case-insensitive) is correct for the usual
	 *   `utf8mb4_0900_ai_ci` collation; callers that rely on a `_bin` or
	 *   `_cs` collation must pass an explicit `c` match_type because this
	 *   helper has no access to the session collation.
	 * - The `u` (UTF-8) PCRE modifier is applied to REGEXP_* functions, so
	 *   they reject data that is not valid UTF-8 from the start position on.
	 *   The REGEXP operators match such data, and REGEXP BINARY, byte by byte,
	 *   like MySQL binary strings. Binary values that are valid UTF-8 match as
	 *   text, so case sensitivity can differ between rows.
	 * - SQLite converts float and decimal operands to text, in a different format
	 *   from MySQL: `20e0` reads "20.0" (MySQL: "20"), `0.000001` reads "1.0e-06",
	 *   and `1.50` reads "1.5". Integers above the BIGINT range read as floats.
	 * - `pos`, `occurrence`, and `return_option` use PHP's integer conversion, so
	 *   `2.6` means 2 (MySQL: 3), and `'2e1'` means 20 (MySQL: 2).
	 * - Inline case flags don't reach [:upper:] and [:lower:], or \p{Lu} and
	 *   \p{Ll} on PCRE 10.44 and older. They stay case-sensitive in
	 *   `(?i)[[:upper:]]` with the `c` flag, and in the whole pattern when it
	 *   contains "(?-i)".
	 *
	 * @param string $pattern     The MySQL regex pattern.
	 * @param string $match_type  MySQL match_type flag string.
	 * @param bool   $binary_mode Whether to match bytes instead of UTF-8 characters.
	 *
	 * @throws Exception If the pattern is empty or the match_type string
	 *                   contains an unrecognized flag.
	 * @return string PCRE-ready pattern with delimiter and modifiers.
	 */
	private function regexp_compile( $pattern, $match_type, $binary_mode = false ) {
		$match_type = (string) $match_type;
		$pattern    = (string) $pattern;
		if ( '' === $pattern ) {
			throw new Exception( 'Illegal argument to a regular expression.' );
		}
		$cache_key = array( $pattern, $match_type, $binary_mode );
		if ( $cache_key === $this->regexp_cached_key ) {
			return $this->regexp_cached_compiled;
		}

		$case_sensitive = false;
		$multiline      = false;
		$dotall         = false;
		$unix_lines     = false;
		$len            = strlen( $match_type );
		for ( $i = 0; $i < $len; $i++ ) {
			$flag = $match_type[ $i ];
			if ( 'c' === $flag ) {
				$case_sensitive = true;
			} elseif ( 'i' === $flag ) {
				$case_sensitive = false;
			} elseif ( 'm' === $flag ) {
				$multiline = true;
			} elseif ( 'n' === $flag ) {
				$dotall = true;
			} elseif ( 'u' === $flag ) {
				$unix_lines = true;
			} else {
				throw new Exception( "Invalid match_type flag: $flag." );
			}
		}

		/*
		 * PHP 7 can read an unknown escape, such as "\y" or "\u0041", as literal text.
		 * The "X" modifier makes it an error, like in PHP 8.
		 */
		$modifiers = $binary_mode ? 'X' : 'uX';
		if ( ! $case_sensitive ) {
			$modifiers .= 'i';
		}
		if ( $multiline ) {
			$modifiers .= 'm';
		}
		if ( $dotall ) {
			$modifiers .= 's';
		}

		$newline  = $unix_lines ? '(*LF)' : '(*ANY)';
		$compiled = '/' . $newline . $this->regexp_translate_pattern( $pattern, ! $case_sensitive ) . '/' . $modifiers;

		/*
		 * Compile errors are the only preg failures that emit PHP warnings.
		 * Silence them, and report the failure as a MySQL error instead.
		 */
		set_error_handler( static function () {} );
		try {
			$valid = preg_match( $compiled, '' );
		} finally {
			restore_error_handler();
		}
		if ( false === $valid ) {
			$this->regexp_fail( $pattern );
		}
		$this->regexp_cached_key      = $cache_key;
		$this->regexp_cached_compiled = $compiled;
		return $compiled;
	}

	/**
	 * Translate a MySQL regex pattern to PCRE syntax.
	 *
	 * Escapes pattern delimiters and NUL bytes that are not already escaped. PHP
	 * before 8.2 rejects NUL bytes in patterns, so they become "\x00". POSIX
	 * classes that PCRE reads differently from ICU become Unicode properties, and
	 * a hyphen after a shorthand class is escaped.
	 *
	 * @param string $pattern          The regex pattern.
	 * @param bool   $case_insensitive Whether the pattern ignores case by default.
	 *
	 * @return string Pattern safe to wrap in slash delimiters.
	 */
	private function regexp_translate_pattern( $pattern, $case_insensitive ) {
		$special = array(
			'/'  => '\\/',
			"\0" => '\\x00',
		);

		// PCRE's [:punct:] also holds ASCII symbols like "+", where ICU's doesn't.
		$classes = array( 'punct' => '{P}' );

		/*
		 * With the "u" modifier, PCRE reads [:upper:] and [:lower:] case-sensitively
		 * even when the pattern ignores case, where ICU matches both cases. PCRE 10.44
		 * and older read \p{Lu} and \p{Ll} the same way. \p{L&} doesn't follow inline
		 * flags, so a pattern with "(?-i)" keeps PCRE's reading.
		 */
		$match_both_cases = $case_insensitive && ! preg_match( '/\(\?[a-zA-Z]*-[a-zA-Z]*i/', $pattern );
		if ( $match_both_cases ) {
			$classes['upper'] = '{L&}';
			$classes['lower'] = '{L&}';
		}

		$escaped = '';
		$quoted  = false;
		$length  = strlen( $pattern );
		for ( $i = 0; $i < $length; ++$i ) {
			$character = $pattern[ $i ];
			if ( $quoted ) {
				if ( '\\' === $character && $i + 1 < $length && 'E' === $pattern[ $i + 1 ] ) {
					$escaped .= '\\E';
					$quoted   = false;
					++$i;
				} elseif ( isset( $special[ $character ] ) ) {
					$escaped .= '\\E' . $special[ $character ] . '\\Q';
				} else {
					$escaped .= $character;
				}
				continue;
			}
			if (
				$match_both_cases
				&& '\\' === $character
				&& preg_match( '/\G\\\\([pP])\{L[lu]\}/', $pattern, $property, 0, $i )
			) {
				$escaped .= '\\' . $property[1] . '{L&}';
				$i       += strlen( $property[0] ) - 1;
			} elseif ( '\\' === $character && $i + 1 < $length ) {
				$next     = $pattern[ $i + 1 ];
				$escaped .= isset( $special[ $next ] ) ? $special[ $next ] : $character . $next;
				$quoted   = 'Q' === $next;
				++$i;

				/*
				 * PCRE rejects a hyphen after a shorthand class, as in "[\w-.]", where
				 * ICU reads it literally. Escaping it is also safe outside of a set.
				 * ICU reads "--" as a set difference, so that is left as it is.
				 */
				if (
					false !== strpos( 'dDhHsSvVwW', $next )
					&& '-' === substr( $pattern, $i + 1, 1 )
					&& '-' !== substr( $pattern, $i + 2, 1 )
				) {
					$escaped .= '\\-';
					++$i;
				}
			} elseif ( isset( $special[ $character ] ) ) {
				$escaped .= $special[ $character ];
			} elseif (
				'[' === $character
				&& preg_match( '/\G\[:(\^?)(\w+):\]/', $pattern, $class, 0, $i )
				&& isset( $classes[ $class[2] ] )
			) {
				$escaped .= ( '' === $class[1] ? '\\p' : '\\P' ) . $classes[ $class[2] ];
				$i       += strlen( $class[0] ) - 1;
			} else {
				$escaped .= $character;
			}
		}
		return $escaped;
	}

	/**
	 * Check whether a string is valid UTF-8.
	 *
	 * @param string $value The string.
	 *
	 * @return bool Whether the string is valid UTF-8.
	 */
	private function regexp_is_utf8( $value ) {
		return 1 === preg_match( '//u', $value );
	}

	/**
	 * Convert a 1-based character position into a byte offset into the UTF-8 string.
	 *
	 * @param string $s              UTF-8 string.
	 * @param int    $char_pos       1-based character position.
	 * @param bool   $allow_past_end Whether to accept char_pos == char_count + 1
	 *                               (returns strlen($s)). MySQL allows this for
	 *                               REGEXP_REPLACE and REGEXP_SUBSTR but not for
	 *                               REGEXP_INSTR.
	 *
	 * @throws Exception If $char_pos is out of range.
	 * @return int Byte offset into $s.
	 */
	private function regexp_char_to_byte_offset( $s, $char_pos, $allow_past_end = false ) {
		if ( $char_pos < 1 ) {
			throw new Exception( 'Index out of bounds in regular expression search.' );
		}
		if ( 1 === $char_pos ) {
			return 0;
		}
		$byte_len = strlen( $s );
		$chars    = 1;
		for ( $i = 0; $i < $byte_len; $i++ ) {
			// Count every byte that isn't a UTF-8 continuation byte.
			if ( ( ord( $s[ $i ] ) & 0xC0 ) !== 0x80 ) {
				if ( $chars === $char_pos ) {
					return $i;
				}
				++$chars;
			}
		}
		if ( $allow_past_end && $chars === $char_pos ) {
			return $byte_len;
		}
		throw new Exception( 'Index out of bounds in regular expression search.' );
	}

	/**
	 * Expand a MySQL/ICU-style replacement template for one match.
	 *
	 * Rules (from ICU, used by MySQL REGEXP_REPLACE):
	 *   - "\X" for any X: emit X, drop the backslash (also applies to "\\" -> "\").
	 *     Unlike ICU, "\uXXXX" and "\UXXXXXXXX" are not expanded.
	 *   - Trailing lone backslash: dropped.
	 *   - "$N" (N is one or more digits): emit the Nth capture group. Consumes
	 *     the longest digit run that forms a valid group index; any trailing
	 *     digits become literal text.
	 *   - "${name}": emit the named capture group. Unknown names, and names
	 *     with characters other than letters and the digits 1-9, are an error
	 *     (ERROR 3887). So is "${N}", as only named groups use braces.
	 *   - "$" not followed by a digit or "{": error (ERROR 3887).
	 *   - "$N" where N is larger than any existing group: error (ERROR 3686).
	 *
	 * Unmatched groups expand to an empty string.
	 *
	 * @param string $replacement The replacement template.
	 * @param array  $match_data  The match in PREG_OFFSET_CAPTURE format.
	 * @param array  $group_keys  The pattern's capture group indexes and names, as array keys.
	 *
	 * @throws Exception On an invalid "$..." reference.
	 * @return string The expanded replacement.
	 */
	private function regexp_expand_replacement( $replacement, $match_data, $group_keys ) {
		$out = '';
		$len = strlen( $replacement );
		$i   = 0;
		while ( $i < $len ) {
			$c = $replacement[ $i ];
			if ( '\\' === $c ) {
				if ( $i + 1 < $len ) {
					$out .= $replacement[ $i + 1 ];
					$i   += 2;
				} else {
					++$i;
				}
				continue;
			}
			if ( '$' === $c ) {
				if ( $i + 1 < $len && '{' === $replacement[ $i + 1 ] ) {
					/*
					 * Like ICU, accept only letters and the digits 1-9 in a group name.
					 * A name must start with a letter, or "${1}" would match group 1's
					 * numeric key.
					 */
					if (
						! preg_match( '/\G\{([A-Za-z][A-Za-z1-9]*)\}/', $replacement, $reference, 0, $i + 1 )
						|| ! isset( $group_keys[ $reference[1] ] )
					) {
						throw new Exception( 'A capture group has an invalid name.' );
					}
					$out .= $match_data[ $reference[1] ][0] ?? '';
					$i   += 1 + strlen( $reference[0] );
					continue;
				}
				$digits = substr( $replacement, $i + 1, strspn( $replacement, '0123456789', $i + 1 ) );
				if ( '' === $digits ) {
					throw new Exception( 'A capture group has an invalid name.' );
				}
				// Longest digit prefix that refers to an existing group wins;
				// remaining digits are emitted literally.
				$idx      = null;
				$consumed = 0;
				for ( $k = strlen( $digits ); $k > 0; --$k ) {
					$cand = (int) substr( $digits, 0, $k );
					if ( isset( $group_keys[ $cand ] ) ) {
						$idx      = $cand;
						$consumed = $k;
						break;
					}
				}
				if ( null === $idx ) {
					throw new Exception( 'Index out of bounds in regular expression search.' );
				}
				$out .= $match_data[ $idx ][0] ?? '';
				$i   += 1 + $consumed;
				continue;
			}
			$out .= $c;
			++$i;
		}
		return $out;
	}

	/**
	 * Find one numbered match without retaining preceding matches.
	 *
	 * @param string $compiled   PCRE-wrapped pattern.
	 * @param string $pattern    The original MySQL regex pattern, for error messages.
	 * @param string $subject    Full subject string.
	 * @param int    $offset     Byte offset at which matching begins.
	 * @param int    $occurrence 1-based match number.
	 *
	 * @throws Exception On a preg failure.
	 * @return array|null Match array, or NULL if absent.
	 */
	private function regexp_find_nth_match( $compiled, $pattern, $subject, $offset, $occurrence ) {
		$index = 0;
		foreach ( $this->regexp_matches( $compiled, $pattern, $subject, $offset ) as $match ) {
			if ( ++$index === $occurrence ) {
				return $match;
			}
		}
		return null;
	}

	/**
	 * Iterate over matches from an offset without accumulating them in memory.
	 *
	 * Uses preg_match's offset argument rather than slicing the subject so
	 * lookbehind assertions can inspect bytes preceding the search position.
	 *
	 * @param string $compiled PCRE-wrapped pattern.
	 * @param string $pattern  The original MySQL regex pattern, for error messages.
	 * @param string $subject  Full subject string.
	 * @param int    $offset   Initial byte offset.
	 *
	 * @throws Exception On a preg failure.
	 * @return Generator Matches in PREG_OFFSET_CAPTURE format.
	 */
	private function regexp_matches( $compiled, $pattern, $subject, $offset ) {
		$len = strlen( $subject );
		while ( $offset <= $len ) {
			$r = preg_match(
				$compiled,
				$subject,
				$m,
				PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
				$offset
			);
			if ( false === $r ) {
				$this->regexp_fail( $pattern );
			}
			if ( 0 === $r ) {
				return;
			}
			yield $m;

			$match_start  = $m[0][1];
			$match_length = strlen( $m[0][0] );
			$offset       = $match_start + $match_length;
			if ( 0 === $match_length ) {
				// Advance past a zero-width match to avoid looping on the same offset.
				// Skip any UTF-8 continuation bytes so the next match starts on a code point boundary.
				++$offset;
				while ( $offset < $len && ( ord( $subject[ $offset ] ) & 0xC0 ) === 0x80 ) {
					++$offset;
				}
			}
		}
	}

	/**
	 * Translate a preg_* failure into a caller-friendly exception message.
	 *
	 * Uses preg_last_error() to distinguish invalid patterns from runtime
	 * limit failures and invalid-UTF-8 input.
	 *
	 * @param string $pattern The original MySQL regex pattern.
	 *
	 * @throws Exception Always.
	 * @return void
	 */
	private function regexp_fail( $pattern ) {
		$err = preg_last_error();
		if (
			PREG_BACKTRACK_LIMIT_ERROR === $err
			|| PREG_RECURSION_LIMIT_ERROR === $err
			|| PREG_JIT_STACKLIMIT_ERROR === $err
		) {
			throw new Exception( 'Regular expression evaluation exceeded internal limits.' );
		}
		if ( PREG_BAD_UTF8_ERROR === $err ) {
			throw new Exception( 'Invalid UTF-8 data in regular expression input.' );
		}
		throw new Exception( 'Invalid regular expression: ' . $pattern . '.' );
	}
}
