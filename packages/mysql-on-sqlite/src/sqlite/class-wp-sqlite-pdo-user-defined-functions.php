<?php
/**
 * Custom functions for the SQLite implementation.
 *
 * @package wp-sqlite-integration
 * @since 1.0.0
 */

/**
 * This class defines user defined functions(UDFs) for PDO library.
 *
 * These functions replace those used in the SQL statement with the PHP functions.
 *
 * Usage:
 *
 * <code>
 * new WP_SQLite_PDO_User_Defined_Functions(ref_to_pdo_obj);
 * </code>
 *
 * This automatically enables ref_to_pdo_obj to replace the function in the SQL statement
 * to the ones defined here.
 */
class WP_SQLite_PDO_User_Defined_Functions {

	/**
	 * Registers the user defined functions for SQLite to a PDO instance.
	 * The functions are registered using PDO::sqliteCreateFunction().
	 *
	 * @param PDO|PDO\SQLite $pdo The PDO object.
	 */
	public static function register_for( $pdo ): self {
		$instance = new self();
		foreach ( $instance->functions as $f => $t ) {
			$arities = $instance->function_arities[ $f ] ?? array( -1 );
			foreach ( $arities as $arity ) {
				if ( $pdo instanceof PDO\SQLite ) {
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
		'if'                           => '_if',
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
		'regexp_like'    => array( 2, 3 ),
		'regexp_replace' => array( 3, 4, 5, 6 ),
		'regexp_substr'  => array( 2, 3, 4, 5 ),
		'regexp_instr'   => array( 2, 3, 4, 5, 6 ),
	);

	/** @var string|null Last validated regex pattern. */
	private $regexp_cached_pattern = null;

	/** @var string|null Match type used by the cached regex. */
	private $regexp_cached_match_type = null;

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
	 * Method to emulate MySQL IF() function.
	 *
	 * As 'IF' is a reserved word for PHP, function name must be changed.
	 *
	 * @param mixed $expression The statement to be evaluated as true or false.
	 * @param mixed $truthy     Statement or value returned if $expression is true.
	 * @param mixed $falsy      Statement or value returned if $expression is false.
	 *
	 * @return mixed
	 */
	public function _if( $expression, $truthy, $falsy ) {
		return ( true === $expression ) ? $truthy : $falsy;
	}

	/**
	 * Method to emulate MySQL REGEXP() function.
	 *
	 * @param string $pattern Regular expression to match.
	 * @param string $field   Haystack.
	 *
	 * @return integer 1 if matched, 0 if not matched.
	 */
	public function regexp( $pattern, $field ) {
		/*
		 * If the original query says REGEXP BINARY
		 * the comparison is byte-by-byte and letter casing now
		 * matters since lower- and upper-case letters have different
		 * byte codes.
		 *
		 * The REGEXP function can't be easily made to accept two
		 * parameters, so we'll have to use a hack to get around this.
		 *
		 * If the first character of the pattern is a null byte, we'll
		 * remove it and make the comparison case-sensitive. This should
		 * be reasonably safe since PHP does not allow null bytes in
		 * regular expressions anyway.
		 */
		if ( "\x00" === $pattern[0] ) {
			$pattern = substr( $pattern, 1 );
			$flags   = '';
		} else {
			// Otherwise, the search is case-insensitive.
			$flags = 'i';
		}
		$pattern = str_replace( '/', '\/', $pattern );
		$pattern = '/' . $pattern . '/' . $flags;

		return preg_match( $pattern, $field );
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
		if ( null === $match_type ) {
			return null;
		}
		$compiled = $this->regexp_compile( $pattern, $match_type );
		if ( null === $expr || null === $pattern ) {
			return null;
		}
		$expr    = $this->regexp_string_arg( $expr );
		$pattern = $this->regexp_string_arg( $pattern );
		$result  = $this->regexp_run(
			function () use ( $compiled, $expr ) {
				return preg_match( $compiled, $expr );
			}
		);
		if ( false === $result ) {
			$this->regexp_fail( $pattern );
		}
		return $result;
	}

	/**
	 * Method to emulate MySQL REGEXP_REPLACE() function.
	 *
	 * Uses MySQL/ICU replacement grammar: "$N" backreferences ("$0" is the
	 * full match), "\X" emits X (drops the backslash), "${N}" is rejected.
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
		if ( null === $match_type ) {
			return null;
		}
		$compiled = $this->regexp_compile( $pattern, $match_type );
		$position = null === $pos ? null : $this->regexp_int_arg( $pos );
		$n        = null === $occurrence ? null : $this->regexp_int_arg( $occurrence );
		if ( null !== $position && $position < 1 ) {
			throw new Exception( 'Index out of bounds in regular expression search.' );
		}
		if (
			null === $expr || null === $pattern || null === $replacement
			|| null === $pos || null === $occurrence
		) {
			return null;
		}

		$expr        = $this->regexp_string_arg( $expr );
		$pattern     = $this->regexp_string_arg( $pattern );
		$replacement = $this->regexp_string_arg( $replacement );
		$this->regexp_validate_subject( $expr, $pattern );
		$byte_start = $this->regexp_char_to_byte_offset( $expr, $position, true );

		// 0 means replace all; negative occurrences are clamped to 1 (MySQL behavior).
		if ( $n < 0 ) {
			$n = 1;
		}
		if ( '' === $expr ) {
			return $expr;
		}

		if ( $n > 0 ) {
			$match = $this->regexp_find_nth_match( $compiled, $expr, $byte_start, $n );
			if ( false === $match ) {
				$this->regexp_fail( $pattern );
			}
			if ( null === $match ) {
				return $expr;
			}
			$match_start  = $match[0][1];
			$match_length = strlen( $match[0][0] );
			return substr( $expr, 0, $match_start )
				. $this->regexp_expand_match_replacement( $replacement, $match )
				. substr( $expr, $match_start + $match_length );
		}

		// Rebuild the subject while streaming through every match.
		$out = substr( $expr, 0, $byte_start );
		$cur = $byte_start;
		$ok  = $this->regexp_walk_matches(
			$compiled,
			$expr,
			$byte_start,
			function ( $match_data ) use ( $expr, $replacement, &$out, &$cur ) {
				$match_start  = $match_data[0][1];
				$match_length = strlen( $match_data[0][0] );
				$out         .= substr( $expr, $cur, $match_start - $cur );
				$out         .= $this->regexp_expand_match_replacement( $replacement, $match_data );
				$cur          = $match_start + $match_length;
				return true;
			}
		);
		if ( false === $ok ) {
			$this->regexp_fail( $pattern );
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
		if ( null === $match_type ) {
			return null;
		}
		$compiled = $this->regexp_compile( $pattern, $match_type );
		$position = null === $pos ? null : $this->regexp_int_arg( $pos );
		$n        = null === $occurrence ? null : $this->regexp_int_arg( $occurrence );
		if ( null !== $position && $position < 1 ) {
			throw new Exception( 'Index out of bounds in regular expression search.' );
		}
		if (
			null === $expr || null === $pattern
			|| null === $pos || null === $occurrence
		) {
			return null;
		}

		$expr    = $this->regexp_string_arg( $expr );
		$pattern = $this->regexp_string_arg( $pattern );
		$this->regexp_validate_subject( $expr, $pattern );

		// MySQL clamps occurrence <= 0 to 1.
		$n = max( 1, $n );

		$byte_start = $this->regexp_char_to_byte_offset( $expr, $position, true );

		$match = $this->regexp_find_nth_match( $compiled, $expr, $byte_start, $n );
		if ( false === $match ) {
			$this->regexp_fail( $pattern );
		}
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
		$ret = null === $return_option ? null : $this->regexp_int_arg( $return_option );
		if ( null !== $ret && 0 !== $ret && 1 !== $ret ) {
			throw new Exception( 'Incorrect arguments to regexp_instr: return_option must be 1 or 0.' );
		}
		if ( null === $match_type ) {
			return null;
		}
		$compiled = $this->regexp_compile( $pattern, $match_type );
		$position = null === $pos ? null : $this->regexp_int_arg( $pos );
		$n        = null === $occurrence ? null : $this->regexp_int_arg( $occurrence );
		if ( null !== $position && $position < 1 ) {
			throw new Exception( 'Index out of bounds in regular expression search.' );
		}
		if (
			null === $expr || null === $pattern
			|| null === $pos || null === $occurrence
			|| null === $return_option
		) {
			return null;
		}

		$expr    = $this->regexp_string_arg( $expr );
		$pattern = $this->regexp_string_arg( $pattern );
		$this->regexp_validate_subject( $expr, $pattern );

		// MySQL clamps occurrence <= 0 to 1.
		$n = max( 1, $n );

		$byte_start = $this->regexp_char_to_byte_offset( $expr, $position );
		$region     = substr( $expr, $byte_start );

		$match = $this->regexp_find_nth_match( $compiled, $region, 0, $n );
		if ( false === $match ) {
			$this->regexp_fail( $pattern );
		}
		if ( null === $match ) {
			return 0;
		}

		list( $matched_text, $matched_byte_offset ) = $match[0];
		$target_byte                                = $byte_start + $matched_byte_offset;
		if ( 1 === $ret ) {
			$target_byte += strlen( $matched_text );
		}

		return $this->regexp_byte_offset_to_char_index( $expr, $target_byte ) + 1;
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
		return absint( ip2long( $addr ) );
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
	 * Takes a string and returns the reverse of it.
	 *
	* @param string|null $str The string to reverse.
	*
	* @return string|null reversed string, or NULL.
	*/
	public function reverse( $str ) {
		if ( null === $str ) {
			return null;
		}
		return strrev( $str );
	}

	/**
	 * A helper to covert LIKE pattern to a GLOB pattern for "LIKE BINARY" support.

	 * @TODO: Some of the MySQL string specifics described below are likely to
	 *        affect also other patterns than just "LIKE BINARY". We should
	 *        consider applying some of the conversions more broadly.
	 *
	 * @param string $pattern
	 * @return string
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
		 * However, the multi-byte behavior is likely to depend on the charset.
		 * For now, we'll assume UTF-8 and thus the "u" modifier for the regex.
		 */
		$pattern = preg_replace( '/\\\\(.)/u', '$1', $pattern );

		return $pattern;
	}

	/**
	 * Compile a MySQL-style regex into a PCRE pattern string.
	 *
	 * Translates MySQL match_type flags (c/i/m/n/u) to PCRE modifiers and always
	 * appends the u (UTF-8) modifier. Case-insensitive is the default, matching
	 * the existing REGEXP operator.
	 *
	 * MySQL's native engine is ICU; we use PHP's PCRE. The two diverge in a
	 * few corners:
	 *
	 * - Some Unicode property shorthands and POSIX class spellings differ.
	 * - PCRE accepts both `(?<name>...)` and `(?P<name>...)`; MySQL accepts
	 *   only the former and errors on the latter.
	 * - ICU supports multi-code-point case folds such as "ß" matching "ss";
	 *   PCRE's case-insensitive mode does not.
	 *
	 * Known limitations of this emulation:
	 *
	 * - The default (case-insensitive) is correct for the usual
	 *   `utf8mb4_0900_ai_ci` collation; callers that rely on a `_bin` or
	 *   `_cs` collation must pass an explicit `c` match_type because this
	 *   helper has no access to the session collation.
	 * - The `u` (UTF-8) PCRE modifier is always applied. Binary data with
	 *   invalid UTF-8 bytes that matches fine under the legacy `REGEXP`
	 *   operator raises "Invalid UTF-8 data in regular expression input."
	 *   when routed through REGEXP_LIKE / _REPLACE / _SUBSTR / _INSTR.
	 *
	 * @param string|null $pattern    The MySQL regex pattern.
	 * @param string      $match_type MySQL match_type flag string.
	 *
	 * @throws Exception If the pattern is empty or the match_type string
	 *                   contains an unrecognized flag.
	 * @return string|null PCRE-ready pattern with delimiter and modifiers, or
	 *                     NULL when the pattern is NULL.
	 */
	private function regexp_compile( $pattern, $match_type ) {
		$match_type = $this->regexp_string_arg( $match_type );
		if ( null !== $pattern ) {
			$pattern = $this->regexp_string_arg( $pattern );
		}
		if ( '' === $pattern ) {
			throw new Exception( 'Illegal argument to a regular expression.' );
		}
		if (
			null !== $pattern
			&& $pattern === $this->regexp_cached_pattern
			&& $match_type === $this->regexp_cached_match_type
		) {
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

		$modifiers = 'u';
		if ( ! $case_sensitive ) {
			$modifiers .= 'i';
		}
		if ( $multiline ) {
			$modifiers .= 'm';
		}
		if ( $dotall ) {
			$modifiers .= 's';
		}
		if ( null === $pattern ) {
			return null;
		}

		$newline  = $unix_lines ? '(*LF)' : '(*ANY)';
		$compiled = '/' . $newline . $this->regexp_escape_delimiter( $pattern ) . '/' . $modifiers;
		$valid    = $this->regexp_run(
			function () use ( $compiled ) {
				return preg_match( $compiled, '' );
			}
		);
		if ( false === $valid ) {
			$this->regexp_fail( $pattern );
		}
		$this->regexp_cached_pattern    = $pattern;
		$this->regexp_cached_match_type = $match_type;
		$this->regexp_cached_compiled   = $compiled;
		return $compiled;
	}

	/**
	 * Escape pattern delimiters that are not already escaped.
	 *
	 * @param string $pattern The regex pattern.
	 *
	 * @return string Pattern safe to wrap in slash delimiters.
	 */
	private function regexp_escape_delimiter( $pattern ) {
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
				} elseif ( '/' === $character ) {
					$escaped .= '\\E\\/\\Q';
				} else {
					$escaped .= $character;
				}
				continue;
			}
			if ( '\\' === $character && $i + 1 < $length ) {
				$escaped .= $character . $pattern[ $i + 1 ];
				$quoted   = 'Q' === $pattern[ $i + 1 ];
				++$i;
			} elseif ( '/' === $character ) {
				$escaped .= '\\/';
			} else {
				$escaped .= $character;
			}
		}
		return $escaped;
	}

	/**
	 * Convert a regex string-domain argument to its MySQL-style text form.
	 *
	 * @param mixed $value Argument value.
	 *
	 * @return string String representation.
	 */
	private function regexp_string_arg( $value ) {
		$is_float = is_float( $value );
		$value    = (string) $value;
		if ( $is_float && false !== strpos( $value, 'E' ) ) {
			$value = str_replace( 'E', 'e', $value );
			$value = str_replace( 'e+', 'e', $value );
		}
		return $value;
	}

	/**
	 * Run a preg_* callable with PHP warnings suppressed.
	 *
	 * PHPUnit's strict error handler turns preg_* warnings into ErrorExceptions
	 * before we can translate them into a MySQL-style error. This wrapper
	 * suppresses those warnings so the caller can check preg_match's false
	 * result and throw a clean exception.
	 *
	 * @param callable $op Preg operation. Must be self-contained.
	 *
	 * @return mixed Return value of the callable.
	 */
	private function regexp_run( $op ) {
		set_error_handler( static function () {} );
		try {
			return $op();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Convert a numeric-valued integer argument using MySQL rounding.
	 *
	 * MySQL rounds numeric values, truncates numeric strings, and narrows these
	 * REGEXP parameters to a signed 32-bit integer.
	 *
	 * @param int|float|string $value Numeric value.
	 *
	 * @return int Rounded integer.
	 */
	private function regexp_int_arg( $value ) {
		if ( is_float( $value ) ) {
			$value = round( $value );
			if ( ! is_finite( $value ) || $value >= PHP_INT_MAX ) {
				$value = $value < 0 ? PHP_INT_MIN : PHP_INT_MAX;
			} elseif ( $value <= PHP_INT_MIN ) {
				$value = PHP_INT_MIN;
			} else {
				$value = (int) $value;
			}
		} else {
			$value = (int) $value;
		}

		$value &= 0xFFFFFFFF;
		return $value >= 0x80000000 ? $value - 0x100000000 : $value;
	}

	/**
	 * Validate the complete UTF-8 subject before matching from a byte offset.
	 *
	 * @param string $subject Subject string.
	 * @param string $pattern Original regex pattern, used in error reporting.
	 *
	 * @throws Exception If the subject contains invalid UTF-8.
	 * @return void
	 */
	private function regexp_validate_subject( $subject, $pattern ) {
		$valid = $this->regexp_run(
			function () use ( $subject ) {
				return preg_match( '//u', $subject );
			}
		);
		if ( false === $valid ) {
			$this->regexp_fail( $pattern );
		}
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
	 * Convert a byte offset within a UTF-8 string into the 0-based character index.
	 *
	 * The byte offset is expected to fall on a UTF-8 code point boundary, as is
	 * the case for offsets returned by PCRE. Offsets greater than the string
	 * length are clamped to the string length as a defensive measure.
	 *
	 * @param string $s           UTF-8 string.
	 * @param int    $byte_offset Byte offset on a code point boundary.
	 *
	 * @return int 0-based character index.
	 */
	private function regexp_byte_offset_to_char_index( $s, $byte_offset ) {
		$byte_offset = min( $byte_offset, strlen( $s ) );
		$chars       = 0;
		for ( $i = 0; $i < $byte_offset; $i++ ) {
			if ( ( ord( $s[ $i ] ) & 0xC0 ) !== 0x80 ) {
				++$chars;
			}
		}
		return $chars;
	}

	/**
	 * Expand a replacement template using one preg_match result.
	 *
	 * @param string $replacement Replacement template.
	 * @param array  $match_data  Match in PREG_OFFSET_CAPTURE format.
	 *
	 * @return string Expanded replacement.
	 */
	private function regexp_expand_match_replacement( $replacement, $match_data ) {
		$groups = array();
		foreach ( $match_data as $index => $group ) {
			if ( is_int( $index ) ) {
				$groups[ $index ] = null === $group[0] ? '' : $group[0];
			}
		}
		return $this->regexp_expand_replacement( $replacement, $groups );
	}

	/**
	 * Expand a MySQL/ICU-style replacement template.
	 *
	 * Rules (from ICU, used by MySQL REGEXP_REPLACE):
	 *   - "\X" for any X: emit X, drop the backslash (also applies to "\\" -> "\").
	 *   - Trailing lone backslash: dropped.
	 *   - "$N" (N is one or more digits): emit the Nth capture group. Consumes
	 *     the longest digit run that forms a valid group index; any trailing
	 *     digits become literal text.
	 *   - "$" not followed by a digit: error (matches MySQL ERROR 3887).
	 *   - "$N" where N is larger than any existing group: error (ERROR 3686).
	 *   - "${N}" is NOT supported and raises the same error as a bare "$".
	 *
	 * @param string $replacement The replacement template.
	 * @param array  $groups      Capture-group texts, with index 0 = full match.
	 *
	 * @throws Exception On an invalid "$..." reference.
	 * @return string The expanded replacement.
	 */
	private function regexp_expand_replacement( $replacement, $groups ) {
		$max_group = count( $groups ) - 1;
		$out       = '';
		$len       = strlen( $replacement );
		$i         = 0;
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
				if ( $i + 1 >= $len || ! ctype_digit( $replacement[ $i + 1 ] ) ) {
					throw new Exception( 'A capture group has an invalid name.' );
				}
				$j = $i + 1;
				while ( $j < $len && ctype_digit( $replacement[ $j ] ) ) {
					++$j;
				}
				// Longest digit prefix that refers to an existing group wins;
				// remaining digits are emitted literally.
				$digits   = substr( $replacement, $i + 1, $j - $i - 1 );
				$idx      = null;
				$consumed = 0;
				for ( $k = strlen( $digits ); $k > 0; --$k ) {
					$cand = (int) substr( $digits, 0, $k );
					if ( $cand <= $max_group ) {
						$idx      = $cand;
						$consumed = $k;
						break;
					}
				}
				if ( null === $idx ) {
					throw new Exception( 'Index out of bounds in regular expression search.' );
				}
				$out .= $groups[ $idx ];
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
	 * @param string $compiled  PCRE-wrapped pattern.
	 * @param string $subject   Full subject string.
	 * @param int    $offset    Byte offset at which matching begins.
	 * @param int    $occurrence 1-based match number.
	 *
	 * @return array|false|null Match array, false on preg error, or NULL if absent.
	 */
	private function regexp_find_nth_match( $compiled, $subject, $offset, $occurrence ) {
		$index = 0;
		$match = null;
		$ok    = $this->regexp_walk_matches(
			$compiled,
			$subject,
			$offset,
			function ( $candidate ) use ( $occurrence, &$index, &$match ) {
				++$index;
				if ( $index === $occurrence ) {
					$match = $candidate;
					return false;
				}
				return true;
			}
		);
		return $ok ? $match : false;
	}

	/**
	 * Walk matches from an offset without accumulating them in memory.
	 *
	 * Uses preg_match's offset argument rather than slicing the subject so
	 * lookbehind assertions can inspect bytes preceding the search position.
	 * Returning false from the callback stops iteration successfully.
	 *
	 * @param string   $compiled PCRE-wrapped pattern.
	 * @param string   $subject  Full subject string.
	 * @param int      $offset   Initial byte offset.
	 * @param callable $callback Invoked for each match.
	 *
	 * @return bool False on preg error, true otherwise.
	 */
	private function regexp_walk_matches( $compiled, $subject, $offset, $callback ) {
		return $this->regexp_run(
			function () use ( $compiled, $subject, $offset, $callback ) {
				$len = strlen( $subject );
				while ( true ) {
					$r = preg_match(
						$compiled,
						$subject,
						$m,
						PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
						$offset
					);
					if ( false === $r ) {
						return false;
					}
					if ( 0 === $r ) {
						return true;
					}
					if ( false === $callback( $m ) ) {
						return true;
					}
					$match_start  = $m[0][1];
					$match_length = strlen( $m[0][0] );
					$next         = $match_start + $match_length;
					if ( 0 === $match_length ) {
						// Advance past a zero-width match to avoid looping on the same offset.
						// Skip any UTF-8 continuation bytes so the next match starts on a code point boundary.
						++$next;
						while ( $next < $len && ( ord( $subject[ $next ] ) & 0xC0 ) === 0x80 ) {
							++$next;
						}
					}
					if ( $next > $len ) {
						return true;
					}
					$offset = $next;
				}
			}
		);
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
			|| ( defined( 'PREG_JIT_STACKLIMIT_ERROR' ) && PREG_JIT_STACKLIMIT_ERROR === $err )
		) {
			throw new Exception( 'Regular expression evaluation exceeded internal limits.' );
		}
		if ( PREG_BAD_UTF8_ERROR === $err ) {
			throw new Exception( 'Invalid UTF-8 data in regular expression input.' );
		}
		throw new Exception( 'Invalid regular expression: ' . $pattern . '.' );
	}
}
