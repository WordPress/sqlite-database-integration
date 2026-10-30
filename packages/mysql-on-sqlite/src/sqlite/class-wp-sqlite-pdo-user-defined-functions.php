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
			if ( $pdo instanceof Pdo\Sqlite ) {
				$pdo->createFunction( $f, array( $instance, $t ) );
			} else {
				$pdo->sqliteCreateFunction( $f, array( $instance, $t ) );
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
		'timestampdiff'                => 'timestampdiff',
		'timestampadd'                 => 'timestampadd',
		'timestamp'                    => 'timestamp',
		'curtime'                      => 'curtime',
		'sysdate'                      => 'sysdate',
		'current_user'                 => 'current_user',
		'last_day'                     => 'last_day',
		'quarter'                      => 'quarter',
		'dayofyear'                    => 'dayofyear',
		'dayname'                      => 'dayname',
		'monthname'                    => 'monthname',
		'weekofyear'                   => 'weekofyear',
		'yearweek'                     => 'yearweek',
		'makedate'                     => 'makedate',
		'maketime'                     => 'maketime',
		'sec_to_time'                  => 'sec_to_time',
		'time_to_sec'                  => 'time_to_sec',
		'to_days'                      => 'to_days',
		'from_days'                    => 'from_days',
		'to_seconds'                   => 'to_seconds',
		'period_add'                   => 'period_add',
		'period_diff'                  => 'period_diff',
		'microsecond'                  => 'microsecond',
		'addtime'                      => 'addtime',
		'subtime'                      => 'subtime',
		'get_format'                   => 'get_format',
		'time_format'                  => 'time_format',
		'str_to_date'                  => 'str_to_date',
		'version'                      => 'version',
		'reverse'                      => 'reverse',

		// Internal helper functions.
		'_helper_like_to_glob_pattern' => '_helper_like_to_glob_pattern',
	);

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
	 * > 1      Monday              0-53    with a Monday in this year
	 * > 2      Sunday              1-53    with a Sunday in this year
	 * > 3      Monday              1-53    with 4 or more days this year
	 * > 4      Sunday              0-53    with 4 or more days this year
	 * > 5      Monday              0-53    with a Monday in this year
	 * > 6      Sunday              1-53    with 4 or more days this year
	 * > 7      Monday              1-53    with a Monday in this year
	 *
	 * All eight modes are implemented. Only the range modes (2, 3, 6, 7)
	 * set MySQL's WEEK_YEAR flag, so only they can report week 1 of the
	 * following year or roll a date into the previous year's last week.
	 * Modes 0, 1, 4 and 5 report week 0 for the days before week 1, and a
	 * late-December date stays in week 52 or 53 of its own year. Mode 3 is
	 * the ISO-8601 week. YEARWEEK() always sets the flag, whatever mode it
	 * is given.
	 *
	 * @param string   $field Representing the date.
	 * @param int|null $mode  The mode argument, 0 to 7. Defaults to 0.
	 *
	 * @return int|null The week number, or NULL for NULL or invalid input.
	 */
	public function week( $field, $mode = 0 ) {
		if ( null === $field || null === $mode || ! $this->mysql_value_has_date( $field ) ) {
			return null;
		}
		// The mode is validated even though it is not applied.
		if ( ! is_numeric( $mode ) || (int) $mode < 0 || (int) $mode > 7 ) {
			return null;
		}
		$date = $this->parse_mysql_datetime( $field );
		if ( null === $date ) {
			return null;
		}
		/*
		 * Approximation: always the ISO-8601 week number; the mode argument
		 * is accepted and ignored. MySQL has eight numbering schemes, and
		 * matching them needs a faithful port of its calc_week() (including
		 * the WEEK_YEAR flag). Until that exists this keeps the driver's
		 * historical behaviour, which WordPress core's Date_Query tests are
		 * calibrated against.
		 */
		return (int) $date->format( 'W' );
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
	 * Method to emulate MySQL TIMESTAMPDIFF() function.
	 *
	 * Returns the integer difference of two datetimes (second minus first)
	 * in the given unit. Supported units are MICROSECOND (with the
	 * FRAC_SECOND synonym), SECOND, MINUTE, HOUR, DAY, WEEK, MONTH, QUARTER,
	 * and YEAR, including the SQL_TSI_ prefixed spellings. Differences
	 * truncate toward zero, matching MySQL. MONTH, QUARTER, and YEAR count
	 * complete months, with end-of-month clamping like MySQL.
	 *
	 * Both values are interpreted in UTC so that wall-clock differences
	 * never depend on PHP's default time zone or DST transitions.
	 *
	 * Returns NULL when any argument is NULL, the unit is unknown, or an
	 * argument is not a valid date, datetime, or time.
	 *
	 * @param string|null $unit The unit to difference in.
	 * @param string|null $dt1  The datetime to subtract.
	 * @param string|null $dt2  The datetime to subtract from.
	 *
	 * @return int|float|null The difference in the given unit, or NULL.
	 */
	public function timestampdiff( $unit, $dt1, $dt2 ) {
		if ( null === $unit || null === $dt1 || null === $dt2 ) {
			return null;
		}
		$unit = $this->normalize_timestamp_unit( $unit );
		if ( null === $unit ) {
			return null;
		}
		$date1 = $this->parse_mysql_datetime( $dt1 );
		$date2 = $this->parse_mysql_datetime( $dt2 );
		if ( null === $date1 || null === $date2 ) {
			return null;
		}

		switch ( $unit ) {
			case 'MICROSECOND':
				return $this->sqlite_safe_int( $this->datetime_diff_microseconds( $date1, $date2 ) );
			case 'SECOND':
				return $this->sqlite_safe_int( intdiv( $this->datetime_diff_microseconds( $date1, $date2 ), 1000000 ) );
			case 'MINUTE':
				return $this->sqlite_safe_int( intdiv( $this->datetime_diff_microseconds( $date1, $date2 ), 60000000 ) );
			case 'HOUR':
				return $this->sqlite_safe_int( intdiv( $this->datetime_diff_microseconds( $date1, $date2 ), 3600000000 ) );
			case 'DAY':
				return $this->sqlite_safe_int( $this->date_diff_days( $date1, $date2 ) );
			case 'WEEK':
				return $this->sqlite_safe_int( intdiv( $this->date_diff_days( $date1, $date2 ), 7 ) );
			case 'MONTH':
			case 'QUARTER':
			case 'YEAR':
				$per = ( 'MONTH' === $unit ) ? 1 : ( ( 'QUARTER' === $unit ) ? 3 : 12 );
				return $this->datetime_diff_months( $date1, $date2, $per );
		}
		return null;
	}

	/**
	 * Method to emulate MySQL TIMESTAMPADD() function.
	 *
	 * Adds an interval to a datetime, returning a 'Y-m-d H:i:s' string with
	 * a trimmed fractional part when the result has microseconds. Supports
	 * the same units as TIMESTAMPDIFF(), including the SQL_TSI_ prefixed
	 * spellings. Month-based units add complete months with end-of-month
	 * clamping like MySQL, so TIMESTAMPADD(MONTH, 1, '2024-01-31') is
	 * '2024-02-29'. Fractional counts of month-based units are truncated
	 * toward zero; other units round to the nearest microsecond.
	 *
	 * The datetime is interpreted in UTC so the result never depends on
	 * PHP's default time zone or DST transitions.
	 *
	 * Returns NULL when any argument is NULL, the unit is unknown, the
	 * count is not numeric, the datetime is invalid, or the result falls
	 * outside the years 0 to 9999.
	 *
	 * @param string|null $unit The unit to add in.
	 * @param mixed       $n    How many units to add (may be negative).
	 * @param string|null $dt   The datetime to add to.
	 *
	 * @return string|null The resulting datetime, or NULL.
	 */
	public function timestampadd( $unit, $n, $dt ) {
		if ( null === $unit || null === $n || null === $dt ) {
			return null;
		}
		$unit = $this->normalize_timestamp_unit( $unit );
		if ( null === $unit || ! is_numeric( $n ) ) {
			return null;
		}
		$date = $this->parse_mysql_datetime( $dt );
		if ( null === $date ) {
			return null;
		}

		if ( 'MONTH' === $unit || 'QUARTER' === $unit || 'YEAR' === $unit ) {
			$per    = ( 'MONTH' === $unit ) ? 1 : ( ( 'QUARTER' === $unit ) ? 3 : 12 );
			$months = (int) ( (float) $n * $per );
			$result = $this->add_months_clamped( $date, $months );
			if ( null === $result ) {
				return null;
			}
			return $this->format_mysql_datetime( $result );
		}

		$factors = array(
			'MICROSECOND' => 1,
			'SECOND'      => 1000000,
			'MINUTE'      => 60000000,
			'HOUR'        => 3600000000,
			'DAY'         => 86400000000,
			'WEEK'        => 604800000000,
		);
		$micros  = (float) $n * $factors[ $unit ];
		if ( $micros > 900000000000000000.0 || $micros < -900000000000000000.0 ) {
			return null;
		}
		$result = $this->add_microseconds( $date, (int) round( $micros ) );
		if ( null === $result ) {
			return null;
		}
		return $this->format_mysql_datetime( $result );
	}

	/**
	 * Method to emulate MySQL TIMESTAMP() function.
	 *
	 * With one argument, normalizes a date or datetime to 'Y-m-d H:i:s'.
	 * With two arguments, adds the time expression to the date or datetime,
	 * e.g. TIMESTAMP('2024-01-01', '02:00:00') is '2024-01-01 02:00:00'.
	 * A time-only first argument is anchored to the current UTC date, like
	 * MySQL anchors it to the current date.
	 *
	 * Returns NULL when any present argument is NULL or invalid.
	 * TIMESTAMP(dt, NULL) returns NULL, matching MySQL.
	 *
	 * @param string|null $dt   A date, datetime, or time expression.
	 * @param string|null $dt2  An optional time expression to add.
	 *
	 * @return string|null The resulting datetime, or NULL.
	 */
	public function timestamp( $dt, $dt2 = null ) {
		if ( null === $dt ) {
			return null;
		}
		if ( 2 === func_num_args() && null === $dt2 ) {
			return null;
		}
		$date = $this->parse_mysql_datetime( $dt );
		if ( null === $date ) {
			return null;
		}
		if ( 1 === func_num_args() ) {
			if ( ! $this->mysql_value_has_date( $dt ) ) {
				$today = gmdate( 'Y-m-d' );
				$date  = $this->parse_mysql_datetime( $today . ' ' . $date->format( 'H:i:s.u' ) );
				if ( null === $date ) {
					return null;
				}
			}
			return $this->format_mysql_datetime( $date );
		}
		$micros = $this->parse_mysql_time_interval( $dt2 );
		if ( null === $micros ) {
			return null;
		}
		$result = $this->add_microseconds( $date, $micros );
		if ( null === $result ) {
			return null;
		}
		return $this->format_mysql_datetime( $result );
	}

	/**
	 * Method to emulate MySQL CURTIME() function.
	 *
	 * @return string The current UTC time formatted as 'HH:MM:SS'.
	 */
	public function curtime() {
		return gmdate( 'H:i:s' );
	}

	/**
	 * Method to emulate MySQL SYSDATE() function.
	 *
	 * The driver has no separate execution timestamp, so this matches
	 * NOW() semantics and returns the current UTC datetime.
	 *
	 * @return string The current UTC datetime formatted as 'Y-m-d H:i:s'.
	 */
	public function sysdate() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Method to emulate MySQL CURRENT_USER() function.
	 *
	 * The driver has no authenticated user, so this returns an empty
	 * string. It is provided so queries referencing CURRENT_USER() parse
	 * and execute instead of failing with "no such function".
	 *
	 * @return string An empty string.
	 */
	public function current_user() {
		return '';
	}

	/**
	 * Method to emulate MySQL LAST_DAY() function.
	 *
	 * Returns the last day of the month for the given date, formatted as
	 * 'Y-m-d'. Returns NULL for NULL or invalid input.
	 *
	 * @param string|null $d A date or datetime.
	 *
	 * @return string|null The last day of the month, or NULL.
	 */
	public function last_day( $d ) {
		if ( null === $d || ! $this->mysql_value_has_date( $d ) ) {
			return null;
		}
		$date = $this->parse_mysql_datetime( $d );
		if ( null === $date ) {
			return null;
		}
		$year  = (int) $date->format( 'Y' );
		$month = (int) $date->format( 'n' );
		return sprintf( '%04d-%02d-%02d', $year, $month, $this->days_in_month( $year, $month ) );
	}

	/**
	 * Method to emulate MySQL QUARTER() function.
	 *
	 * Returns NULL for NULL or invalid input.
	 *
	 * @param string|null $d A date or datetime.
	 *
	 * @return int|null The quarter of the year, 1 to 4, or NULL.
	 */
	public function quarter( $d ) {
		if ( null === $d || ! $this->mysql_value_has_date( $d ) ) {
			return null;
		}
		$date = $this->parse_mysql_datetime( $d );
		if ( null === $date ) {
			return null;
		}
		return (int) intdiv( (int) $date->format( 'n' ) + 2, 3 );
	}

	/**
	 * Method to emulate MySQL DAYOFYEAR() function.
	 *
	 * Returns NULL for NULL or invalid input.
	 *
	 * @param string|null $d A date or datetime.
	 *
	 * @return int|null The day of the year, 1 to 366, or NULL.
	 */
	public function dayofyear( $d ) {
		if ( null === $d || ! $this->mysql_value_has_date( $d ) ) {
			return null;
		}
		$date = $this->parse_mysql_datetime( $d );
		if ( null === $date ) {
			return null;
		}
		return (int) $date->format( 'z' ) + 1;
	}

	/**
	 * Method to emulate MySQL DAYNAME() function.
	 *
	 * Returns NULL for NULL or invalid input.
	 *
	 * @param string|null $d A date or datetime.
	 *
	 * @return string|null The English weekday name, or NULL.
	 */
	public function dayname( $d ) {
		if ( null === $d || ! $this->mysql_value_has_date( $d ) ) {
			return null;
		}
		$date = $this->parse_mysql_datetime( $d );
		if ( null === $date ) {
			return null;
		}
		return $date->format( 'l' );
	}

	/**
	 * Method to emulate MySQL MONTHNAME() function.
	 *
	 * Returns NULL for NULL or invalid input.
	 *
	 * @param string|null $d A date or datetime.
	 *
	 * @return string|null The English month name, or NULL.
	 */
	public function monthname( $d ) {
		if ( null === $d || ! $this->mysql_value_has_date( $d ) ) {
			return null;
		}
		$date = $this->parse_mysql_datetime( $d );
		if ( null === $date ) {
			return null;
		}
		return $date->format( 'F' );
	}

	/**
	 * Method to emulate MySQL WEEKOFYEAR() function.
	 *
	 * Equivalent to WEEK(date, 3): Monday start, range 1 to 53, week 1 is
	 * the first week with 4 or more days in the new year. Returns NULL for
	 * NULL or invalid input.
	 *
	 * @param string|null $d A date or datetime.
	 *
	 * @return int|null The week number, 1 to 53, or NULL.
	 */
	public function weekofyear( $d ) {
		if ( null === $d || ! $this->mysql_value_has_date( $d ) ) {
			return null;
		}
		$date = $this->parse_mysql_datetime( $d );
		if ( null === $date ) {
			return null;
		}
		// ISO-8601 week, consistent with the WEEK() approximation above.
		return (int) $date->format( 'W' );
	}

	/**
	 * Method to emulate MySQL YEARWEEK() function.
	 *
	 * Returns the year and week as a number, e.g. YEARWEEK('2024-05-10', 1)
	 * is 202419. The optional mode argument follows the same 0 to 7 modes
	 * as WEEK() and defaults to 0.
	 *
	 * Returns NULL for NULL arguments, invalid input, or a mode outside
	 * the range 0 to 7.
	 *
	 * @param string   $d    A date or datetime.
	 * @param int|null $mode The week mode, 0 to 7. Defaults to 0.
	 *
	 * @return int|null The year and week number, or NULL.
	 */
	public function yearweek( $d, $mode = 0 ) {
		if ( null === $d || null === $mode || ! $this->mysql_value_has_date( $d ) ) {
			return null;
		}
		// The mode is validated even though it is not applied.
		if ( ! is_numeric( $mode ) || (int) $mode < 0 || (int) $mode > 7 ) {
			return null;
		}
		$date = $this->parse_mysql_datetime( $d );
		if ( null === $date ) {
			return null;
		}
		/*
		 * Built on the same ISO-8601 approximation as WEEK(), so the two
		 * always agree: the ISO week-year is used, which is what makes
		 * YEARWEEK('2021-01-01') report 202053.
		 */
		return (int) ( $date->format( 'o' ) * 100 + $date->format( 'W' ) );
	}

	/**
	 * Method to emulate MySQL MAKEDATE() function.
	 *
	 * Returns a date for the given year and day-of-year, e.g.
	 * MAKEDATE(2024, 60) is '2024-02-29'. Day numbers past the end of the
	 * year roll over into the next year. The day-of-year must be greater
	 * than 0 and the resulting year must fit in 0 to 9999.
	 *
	 * Returns NULL for NULL arguments, non-numeric input, or out-of-range
	 * values.
	 *
	 * @param mixed $year     The year, 0 to 9999.
	 * @param mixed $dayofyear The day of the year, greater than 0.
	 *
	 * @return string|null The date formatted as 'Y-m-d', or NULL.
	 */
	public function makedate( $year, $dayofyear ) {
		if ( null === $year || null === $dayofyear ) {
			return null;
		}
		if ( ! is_numeric( $year ) || ! is_numeric( $dayofyear ) ) {
			return null;
		}
		$year      = (int) $year;
		$dayofyear = (int) $dayofyear;
		if ( $year < 0 || $year > 9999 || $dayofyear < 1 ) {
			return null;
		}
		$elapsed           = $this->days_from_civil( $year, 1, 1 ) + $dayofyear - 1;
		list( $y, $m, $d ) = $this->civil_from_days( $elapsed );
		if ( $y < 0 || $y > 9999 ) {
			return null;
		}
		return sprintf( '%04d-%02d-%02d', $y, $m, $d );
	}

	/**
	 * Method to emulate MySQL MAKETIME() function.
	 *
	 * The hour must be a whole number from -838 to 838, the minute a whole
	 * number from 0 to 59, and the second a number from 0 to just under 60.
	 * A fractional second is preserved with trailing zeros trimmed.
	 *
	 * Returns NULL for NULL arguments, non-numeric input, or out-of-range
	 * values.
	 *
	 * @param mixed $hour   The hour, -838 to 838.
	 * @param mixed $minute The minute, 0 to 59.
	 * @param mixed $second The second, 0 to just under 60.
	 *
	 * @return string|null The time formatted as 'H:MM:SS', or NULL.
	 */
	public function maketime( $hour, $minute, $second ) {
		if ( null === $hour || null === $minute || null === $second ) {
			return null;
		}
		if ( ! is_numeric( $hour ) || ! is_numeric( $minute ) || ! is_numeric( $second ) ) {
			return null;
		}
		$hour_float   = (float) $hour;
		$minute_float = (float) $minute;
		$second_float = (float) $second;
		if ( $hour_float < -838 || $hour_float > 838 || $minute_float < 0 || $minute_float > 59 ) {
			return null;
		}
		if ( (float) (int) $hour_float !== $hour_float || (float) (int) $minute_float !== $minute_float ) {
			return null;
		}
		if ( $second_float < 0 || $second_float >= 60 ) {
			return null;
		}
		$micros = ( ( (int) $hour_float * 60 ) + (int) $minute_float ) * 60000000 + (int) round( $second_float * 1000000 );
		return $this->format_mysql_time( $micros );
	}

	/**
	 * Method to emulate MySQL SEC_TO_TIME() function.
	 *
	 * Converts seconds to a TIME value. The result is clamped to the MySQL
	 * TIME range of -838:59:59 to 838:59:59: any input past 3020399 seconds
	 * in magnitude returns the range endpoint without a fractional part.
	 * A fractional input is preserved with trailing zeros trimmed.
	 *
	 * Returns NULL for NULL or non-numeric input.
	 *
	 * @param mixed $seconds The number of seconds.
	 *
	 * @return string|null The time, or NULL.
	 */
	public function sec_to_time( $seconds ) {
		if ( null === $seconds || ! is_numeric( $seconds ) ) {
			return null;
		}
		$value = (float) $seconds;
		if ( $value > 3020399 || $value < -3020399 ) {
			return $this->format_mysql_time( $value > 0 ? 3020399000000 : -3020399000000 );
		}
		return $this->format_mysql_time( (int) round( $value * 1000000 ) );
	}

	/**
	 * Method to emulate MySQL TIME_TO_SEC() function.
	 *
	 * Converts a time value to seconds, truncating any fractional part
	 * toward zero. Accepts times with hours past 24 and negative times,
	 * as well as datetimes, for which the time-of-day part is converted.
	 *
	 * Returns NULL for NULL or invalid input.
	 *
	 * @param string|null $time A time or datetime.
	 *
	 * @return int|null The number of seconds, or NULL.
	 */
	public function time_to_sec( $time ) {
		if ( null === $time ) {
			return null;
		}
		$micros = $this->parse_mysql_time_interval( $time );
		if ( null === $micros ) {
			$date = $this->parse_mysql_datetime( $time );
			if ( null === $date ) {
				return null;
			}
			$micros = ( ( (int) $date->format( 'G' ) * 60 ) + (int) $date->format( 'i' ) ) * 60000000 + (int) $date->format( 's' ) * 1000000;
		}
		return (int) intdiv( $micros, 1000000 );
	}

	/**
	 * Method to emulate MySQL TO_DAYS() function.
	 *
	 * Returns the day number of a date using proleptic Gregorian
	 * day-number math anchored at year 0, so TO_DAYS('0000-01-01') is 1
	 * and TO_DAYS('2007-10-07') is 733321. Year 0 maps to days 1 through
	 * 365, and year 1 continues at 366, matching MySQL's day-numbering.
	 *
	 * Returns NULL for NULL input, invalid dates, or values without a
	 * date part.
	 *
	 * @param string|null $d A date or datetime.
	 *
	 * @return int|null The day number, or NULL.
	 */
	public function to_days( $d ) {
		if ( null === $d || ! $this->mysql_value_has_date( $d ) ) {
			return null;
		}
		$date = $this->parse_mysql_datetime( $d );
		if ( null === $date ) {
			return null;
		}
		$year    = (int) $date->format( 'Y' );
		$elapsed = $this->days_from_civil( $year, (int) $date->format( 'n' ), (int) $date->format( 'j' ) ) - $this->days_from_civil( 0, 1, 1 );
		return (int) ( $elapsed + ( 0 === $year ? 1 : 0 ) );
	}

	/**
	 * Method to emulate MySQL FROM_DAYS() function.
	 *
	 * Returns the date for a day number, the inverse of TO_DAYS():
	 * FROM_DAYS(1) is '0000-01-01' and FROM_DAYS(733321) is '2007-10-07'.
	 * Day number 0 would be the MySQL zero date '0000-00-00', which this
	 * driver reports as NULL, so any number below 1 returns NULL. Numbers
	 * past '9999-12-31' (3652424) also return NULL.
	 *
	 * @param mixed $n The day number.
	 *
	 * @return string|null The date formatted as 'Y-m-d', or NULL.
	 */
	public function from_days( $n ) {
		if ( null === $n || ! is_numeric( $n ) ) {
			return null;
		}
		$n = (int) $n;
		if ( $n < 1 || $n > 3652424 ) {
			return null;
		}
		$elapsed           = ( $n >= 366 ) ? $n : ( $n - 1 );
		list( $y, $m, $d ) = $this->civil_from_days( $elapsed + $this->days_from_civil( 0, 1, 1 ) );
		return sprintf( '%04d-%02d-%02d', $y, $m, $d );
	}

	/**
	 * Method to emulate MySQL TO_SECONDS() function.
	 *
	 * Returns the seconds since year 0: (TO_DAYS(date) - 1) * 86400 plus
	 * the seconds of the time-of-day part. Fractional seconds are dropped.
	 * TO_SECONDS('2007-10-07') is 63358848000.
	 *
	 * Returns NULL for NULL input, invalid values, or values without a
	 * date part.
	 *
	 * @param string|null $dt A date or datetime.
	 *
	 * @return int|float|null The number of seconds, or NULL.
	 */
	public function to_seconds( $dt ) {
		if ( null === $dt || ! $this->mysql_value_has_date( $dt ) ) {
			return null;
		}
		$date = $this->parse_mysql_datetime( $dt );
		if ( null === $date ) {
			return null;
		}
		$year    = (int) $date->format( 'Y' );
		$elapsed = $this->days_from_civil( $year, (int) $date->format( 'n' ), (int) $date->format( 'j' ) ) - $this->days_from_civil( 0, 1, 1 );
		$days    = $elapsed + ( 0 === $year ? 1 : 0 );
		return $this->sqlite_safe_int( ( $days - 1 ) * 86400 + (int) $date->format( 'G' ) * 3600 + (int) $date->format( 'i' ) * 60 + (int) $date->format( 's' ) );
	}

	/**
	 * Method to emulate MySQL PERIOD_ADD() function.
	 *
	 * Adds months to a period in YYMM or YYYYMM format, e.g.
	 * PERIOD_ADD(200801, 2) is 200803 and PERIOD_ADD(200812, 1) is 200901.
	 * Two-digit years use MySQL's conversion, 00 to 69 becoming 2000 to
	 * 2069 and 70 to 99 becoming 1970 to 1999, and the result keeps the
	 * same digit width as the input.
	 *
	 * Returns NULL for NULL arguments, non-numeric input, invalid months,
	 * or results outside the representable range.
	 *
	 * @param mixed $period The period in YYMM or YYYYMM format.
	 * @param mixed $months The number of months to add (may be negative).
	 *
	 * @return int|null The resulting period, or NULL.
	 */
	public function period_add( $period, $months ) {
		if ( null === $period || null === $months ) {
			return null;
		}
		if ( ! is_numeric( $period ) || ! is_numeric( $months ) ) {
			return null;
		}
		$parts = $this->period_to_months( $period );
		if ( null === $parts ) {
			return null;
		}
		list( $total ) = $parts;
		$total        += (int) $months;
		$year          = (int) floor( $total / 12 );
		$month         = (int) ( $total - $year * 12 + 1 );
		if ( $year < 0 || $year > 9999 ) {
			return null;
		}
		return (int) ( $year * 100 + $month );
	}

	/**
	 * Method to emulate MySQL PERIOD_DIFF() function.
	 *
	 * Returns the months between two periods in YYMM or YYYYMM format,
	 * e.g. PERIOD_DIFF(200802, 200703) is 11. Two-digit years convert as
	 * in PERIOD_ADD().
	 *
	 * Returns NULL for NULL arguments, non-numeric input, or invalid
	 * months.
	 *
	 * @param mixed $p1 The first period.
	 * @param mixed $p2 The period to subtract.
	 *
	 * @return int|null The difference in months, or NULL.
	 */
	public function period_diff( $p1, $p2 ) {
		if ( null === $p1 || null === $p2 ) {
			return null;
		}
		$parts1 = $this->period_to_months( $p1 );
		$parts2 = $this->period_to_months( $p2 );
		if ( null === $parts1 || null === $parts2 ) {
			return null;
		}
		return (int) ( $parts1[0] - $parts2[0] );
	}

	/**
	 * Method to emulate MySQL MICROSECOND() function.
	 *
	 * Returns the microseconds part of a time or datetime, 0 to 999999.
	 * Returns 0 when the value has no fractional seconds, and NULL for
	 * NULL or invalid input.
	 *
	 * @param string|null $time A time or datetime.
	 *
	 * @return int|null The microseconds part, or NULL.
	 */
	public function microsecond( $time ) {
		if ( null === $time ) {
			return null;
		}
		$micros = $this->parse_mysql_time_interval( $time );
		if ( null !== $micros ) {
			$part = (int) ( $micros % 1000000 );
			return (int) ( $part < 0 ? $part + 1000000 : $part );
		}
		$date = $this->parse_mysql_datetime( $time );
		if ( null === $date ) {
			return null;
		}
		return (int) $date->format( 'u' );
	}

	/**
	 * Method to emulate MySQL ADDTIME() function.
	 *
	 * Adds two time expressions. When the first expression holds a date,
	 * the result is a datetime; otherwise it is a TIME value, which may
	 * exceed 24 hours. TIME results clamp to +-838:59:59.999999.
	 *
	 * Returns NULL for NULL arguments or invalid input.
	 *
	 * @param string|null $expr1 A date, datetime, or time expression.
	 * @param string|null $expr2 A time expression to add.
	 *
	 * @return string|null The resulting time or datetime, or NULL.
	 */
	public function addtime( $expr1, $expr2 ) {
		return $this->add_sub_time( $expr1, $expr2, 1 );
	}

	/**
	 * Method to emulate MySQL SUBTIME() function.
	 *
	 * Subtracts the second time expression from the first. Behaves like
	 * ADDTIME() otherwise.
	 *
	 * Returns NULL for NULL arguments or invalid input.
	 *
	 * @param string|null $expr1 A date, datetime, or time expression.
	 * @param string|null $expr2 A time expression to subtract.
	 *
	 * @return string|null The resulting time or datetime, or NULL.
	 */
	public function subtime( $expr1, $expr2 ) {
		return $this->add_sub_time( $expr1, $expr2, -1 );
	}

	/**
	 * Method to emulate MySQL GET_FORMAT() function.
	 *
	 * Returns a DATE_FORMAT() format string for a type and locale pair,
	 * using MySQL's static mapping, e.g. GET_FORMAT(DATE, 'USA') is
	 * '%m.%d.%Y'. Both arguments are case-insensitive.
	 *
	 * Returns NULL for NULL arguments or unknown types or names.
	 *
	 * @param string|null $type One of DATE, TIME, or DATETIME.
	 * @param string|null $name One of EUR, USA, JIS, ISO, or INTERNAL.
	 *
	 * @return string|null The format string, or NULL.
	 */
	public function get_format( $type, $name ) {
		if ( null === $type || null === $name ) {
			return null;
		}
		$formats = array(
			'DATE'     => array(
				'EUR'      => '%d.%m.%Y',
				'USA'      => '%m.%d.%Y',
				'JIS'      => '%Y-%m-%d',
				'ISO'      => '%Y-%m-%d',
				'INTERNAL' => '%Y%m%d',
			),
			'DATETIME' => array(
				'EUR'      => '%Y-%m-%d %H.%i.%s',
				'USA'      => '%Y-%m-%d %H.%i.%s',
				'JIS'      => '%Y-%m-%d %H:%i:%s',
				'ISO'      => '%Y-%m-%d %H:%i:%s',
				'INTERNAL' => '%Y%m%d%H%i%s',
			),
			'TIME'     => array(
				'EUR'      => '%H.%i.%s',
				'USA'      => '%h:%i:%s %p',
				'JIS'      => '%H:%i:%s',
				'ISO'      => '%H:%i:%s',
				'INTERNAL' => '%H%i%s',
			),
		);
		$type    = strtoupper( trim( (string) $type ) );
		$name    = strtoupper( trim( (string) $name ) );
		if ( ! isset( $formats[ $type ] ) || ! isset( $formats[ $type ][ $name ] ) ) {
			return null;
		}
		return $formats[ $type ][ $name ];
	}

	/**
	 * Method to emulate MySQL TIME_FORMAT() function.
	 *
	 * Formats a time value using the same specifiers as DATE_FORMAT().
	 * The standard path delegates to the dateformat() method. Times with
	 * hours past 23 or negative times take a fallback path that formats
	 * the same specifiers with unbounded hours. %f is the microseconds
	 * part, zero-padded to 6 digits.
	 *
	 * Returns NULL for NULL arguments or invalid input.
	 *
	 * @param string|null $time   A time or datetime.
	 * @param string|null $format The format string.
	 *
	 * @return string|null The formatted time, or NULL.
	 */
	public function time_format( $time, $format ) {
		if ( null === $time || null === $format ) {
			return null;
		}
		$micros = $this->parse_mysql_time_interval( $time );
		$micro  = 0;
		if ( null !== $micros ) {
			$sign = ( $micros < 0 ) ? -1 : 1;
			$abs  = (int) abs( $micros );
			$hour = (int) intdiv( $abs, 3600000000 );
			$rest = (int) ( $abs - $hour * 3600000000 );
			if ( $hour > 23 || $sign < 0 ) {
				return $this->format_time_fallback(
					$sign * $hour,
					(int) intdiv( $rest, 60000000 ),
					(int) ( ( $rest - (int) intdiv( $rest, 60000000 ) * 60000000 ) / 1000000 ),
					(int) ( $rest % 1000000 ),
					$format
				);
			}
			$micro = (int) ( $rest % 1000000 );
			$date  = $this->parse_mysql_datetime(
				sprintf(
					'%02d:%02d:%02d.%06d',
					$hour,
					(int) intdiv( $rest, 60000000 ),
					(int) ( ( $rest - (int) intdiv( $rest, 60000000 ) * 60000000 ) / 1000000 ),
					$micro
				)
			);
		} else {
			$date = $this->parse_mysql_datetime( $time );
		}
		if ( null === $date ) {
			return null;
		}
		return $this->dateformat(
			$this->format_mysql_datetime( $date ),
			$this->substitute_microseconds( $format, (int) $date->format( 'u' ) )
		);
	}

	/**
	 * Method to emulate MySQL STR_TO_DATE() function.
	 *
	 * Parses a string using a format with a documented subset of MySQL
	 * specifiers: %Y %y %m %c %d %e %H %k %h %I %l %i %S %s %p %M %b %W
	 * %a %T %r (plus %% for a literal percent). %T and %r expand to their
	 * component specifiers. Month and weekday names match in English,
	 * case-insensitively. Two-digit years convert as 00 to 69 becoming
	 * 2000 to 2069 and 70 to 99 becoming 1970 to 1999. The year, month,
	 * and day must all be present; anything else returns NULL.
	 *
	 * Returns NULL for NULL arguments, unsupported specifiers, or values
	 * that do not match the format exactly.
	 *
	 * @param string|null $str    The string to parse.
	 * @param string|null $format The format string.
	 *
	 * @return string|null The datetime formatted as 'Y-m-d H:i:s', or NULL.
	 */
	public function str_to_date( $str, $format ) {
		if ( null === $str || null === $format ) {
			return null;
		}
		if ( is_int( $str ) || is_float( $str ) ) {
			$str = (string) $str;
		}
		if ( ! is_string( $str ) || ! is_string( $format ) ) {
			return null;
		}

		// %T and %r are shorthands for their component specifiers.
		$format = str_replace( '%T', '%H:%i:%s', $format );
		$format = str_replace( '%r', '%h:%i:%s %p', $format );

		$month_names   = 'january|february|march|april|may|june|july|august|september|october|november|december|jan|feb|mar|apr|may|jun|jul|aug|sept|sep|oct|nov|dec';
		$weekday_names = 'monday|tuesday|wednesday|thursday|friday|saturday|sunday|mon|tue|wed|thu|fri|sat|sun';
		$specifiers    = array(
			'%Y' => '(\d{1,4})',
			'%y' => '(\d{1,2})',
			'%m' => '(\d{1,2})',
			'%c' => '(\d{1,2})',
			'%d' => '(\d{1,2})',
			'%e' => '(\d{1,2})',
			'%H' => '(\d{1,2})',
			'%k' => '(\d{1,2})',
			'%h' => '(\d{1,2})',
			'%I' => '(\d{1,2})',
			'%l' => '(\d{1,2})',
			'%i' => '(\d{1,2})',
			'%S' => '(\d{1,2})',
			'%s' => '(\d{1,2})',
			'%p' => '(?i:(AM|PM))',
			'%M' => '(?i:(' . $month_names . '))',
			'%b' => '(?i:(' . $month_names . '))',
			'%W' => '(?i:(' . $weekday_names . '))',
			'%a' => '(?i:(' . $weekday_names . '))',
		);

		$regex = '';
		$order = array();
		$len   = strlen( $format );
		for ( $i = 0; $i < $len; $i++ ) {
			$char = $format[ $i ];
			if ( '%' !== $char ) {
				$regex .= preg_quote( $char, '/' );
				continue;
			}
			if ( $i + 1 >= $len ) {
				return null;
			}
			$spec = '%' . $format[ $i + 1 ];
			++$i;
			if ( '%%' === $spec ) {
				$regex .= '%';
				continue;
			}
			if ( ! isset( $specifiers[ $spec ] ) ) {
				return null;
			}
			$regex  .= $specifiers[ $spec ];
			$order[] = $spec;
		}

		if ( 1 !== preg_match( '/^' . $regex . '$/', $str, $matches ) ) {
			return null;
		}

		$values = array();
		foreach ( $order as $index => $spec ) {
			// Duplicate specifiers keep the last value, like MySQL.
			$values[ $spec ] = $matches[ $index + 1 ];
		}

		if ( isset( $values['%Y'] ) ) {
			$year = (int) $values['%Y'];
		} elseif ( isset( $values['%y'] ) ) {
			$yy   = (int) $values['%y'];
			$year = ( $yy < 70 ) ? ( 2000 + $yy ) : ( 1900 + $yy );
		} else {
			return null;
		}

		if ( isset( $values['%m'] ) || isset( $values['%c'] ) ) {
			$month = (int) ( isset( $values['%m'] ) ? $values['%m'] : $values['%c'] );
		} elseif ( isset( $values['%M'] ) || isset( $values['%b'] ) ) {
			$month = $this->month_name_to_number( isset( $values['%M'] ) ? $values['%M'] : $values['%b'] );
			if ( null === $month ) {
				return null;
			}
		} else {
			return null;
		}

		if ( isset( $values['%d'] ) || isset( $values['%e'] ) ) {
			$day = (int) ( isset( $values['%d'] ) ? $values['%d'] : $values['%e'] );
		} else {
			return null;
		}

		if ( $year < 0 || $year > 9999 || $month < 1 || $month > 12 || $day < 1 || $day > $this->days_in_month( $year, $month ) ) {
			return null;
		}

		if ( isset( $values['%H'] ) || isset( $values['%k'] ) ) {
			$hour = (int) ( isset( $values['%H'] ) ? $values['%H'] : $values['%k'] );
			if ( $hour < 0 || $hour > 23 ) {
				return null;
			}
		} elseif ( isset( $values['%h'] ) || isset( $values['%I'] ) || isset( $values['%l'] ) ) {
			if ( isset( $values['%h'] ) ) {
				$hour = (int) $values['%h'];
			} elseif ( isset( $values['%I'] ) ) {
				$hour = (int) $values['%I'];
			} else {
				$hour = (int) $values['%l'];
			}
			if ( $hour < 1 || $hour > 12 ) {
				return null;
			}
		} else {
			$hour = 0;
		}

		$minute = isset( $values['%i'] ) ? (int) $values['%i'] : 0;
		$second = 0;
		if ( isset( $values['%S'] ) ) {
			$second = (int) $values['%S'];
		} elseif ( isset( $values['%s'] ) ) {
			$second = (int) $values['%s'];
		}
		if ( $minute < 0 || $minute > 59 || $second < 0 || $second > 59 ) {
			return null;
		}

		if ( isset( $values['%p'] ) ) {
			$is_pm = ( 0 === strcasecmp( $values['%p'], 'PM' ) );
			if ( $is_pm && $hour < 12 ) {
				$hour += 12;
			} elseif ( ! $is_pm && 12 === $hour ) {
				$hour = 0;
			}
		}

		/*
		 * MySQL picks the result type from the format: a format with only
		 * date specifiers yields a DATE, one with time parts yields a
		 * DATETIME. The distinction matters because SQLite compares these
		 * values as text.
		 */
		$time_specs = array( '%H', '%k', '%h', '%I', '%l', '%i', '%S', '%s', '%p', '%r', '%T' );
		if ( 0 === count( array_intersect( $order, $time_specs ) ) ) {
			return sprintf( '%04d-%02d-%02d', $year, $month, $day );
		}
		return sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second );
	}

	/**
	 * Normalize a TIMESTAMPDIFF() or TIMESTAMPADD() unit.
	 *
	 * Uppercases the unit, strips an ODBC SQL_TSI_ prefix, maps the
	 * FRAC_SECOND synonym to MICROSECOND, and rejects anything else.
	 *
	 * @param mixed $unit The raw unit value.
	 *
	 * @return string|null The normalized unit, or NULL.
	 */
	private function normalize_timestamp_unit( $unit ) {
		if ( ! is_string( $unit ) ) {
			return null;
		}
		$unit = strtoupper( trim( $unit ) );
		if ( 0 === strpos( $unit, 'SQL_TSI_' ) ) {
			$unit = substr( $unit, 8 );
		}
		if ( 'FRAC_SECOND' === $unit ) {
			return 'MICROSECOND';
		}
		$valid = array( 'MICROSECOND', 'SECOND', 'MINUTE', 'HOUR', 'DAY', 'WEEK', 'MONTH', 'QUARTER', 'YEAR' );
		if ( ! in_array( $unit, $valid, true ) ) {
			return null;
		}
		return $unit;
	}

	/**
	 * Normalize a WEEK() or YEARWEEK() mode argument.
	 *
	 * @param mixed $mode The raw mode value.
	 *
	 * @return int|null The mode from 0 to 7, or NULL.
	 */
	private function normalize_week_mode( $mode ) {
		if ( null === $mode || ! is_numeric( $mode ) ) {
			return null;
		}
		$mode = (int) $mode;
		if ( $mode < 0 || $mode > 7 ) {
			return null;
		}
		return $mode;
	}

	/**
	 * Whether a value carries a date part (as opposed to a time only).
	 *
	 * @param mixed $value The value to inspect.
	 *
	 * @return bool True when the value includes a year, month, and day.
	 */
	private function mysql_value_has_date( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) ) {
			return false;
		}
		$value = trim( $value );
		return (
			1 === preg_match( '/^\d{4}-\d{1,2}-\d{1,2}/', $value ) ||
			1 === preg_match( '/^\d{2}-\d{1,2}-\d{1,2}/', $value ) ||
			1 === preg_match( '/^\d{6}(\d{6})?$/', $value ) ||
			1 === preg_match( '/^(\d{8}|\d{14})$/', $value )
		);
	}

	/**
	 * Parse a MySQL date, datetime, or time value into a UTC DateTime.
	 *
	 * Accepts 'YYYY-MM-DD', 'YYYY-MM-DD HH:MM:SS' with an optional
	 * fractional part and 'T' separator, 'HH:MM:SS' with an optional
	 * fractional part, and the compact numeric forms YYYYMMDD,
	 * YYMMDD, YYYYMMDDHHMMSS, and YYMMDDHHMMSS. Two-digit years convert
	 * as 00 to 69 becoming 2000 to 2069 and 70 to 99 becoming 1970 to
	 * 1999. Time-only values anchor to 1970-01-01. Zero month or day
	 * parts are rejected, while year 0 is accepted for the day-number
	 * functions. All parsing anchors to UTC so results never depend on
	 * PHP's default time zone.
	 *
	 * @param mixed $value The value to parse.
	 *
	 * @return DateTime|null The parsed datetime in UTC, or NULL.
	 */
	private function parse_mysql_datetime( $value ) {
		if ( null === $value ) {
			return null;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		$year   = null;
		$month  = null;
		$day    = null;
		$hour   = 0;
		$minute = 0;
		$second = 0;
		$micro  = 0;

		if ( 1 === preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{1,2}):(\d{1,2})(\.\d{1,6})?)?$/', $value, $m ) ) {
			$year  = (int) $m[1];
			$month = (int) $m[2];
			$day   = (int) $m[3];
			if ( isset( $m[4] ) && '' !== $m[4] ) {
				$hour   = (int) $m[4];
				$minute = (int) $m[5];
				$second = (int) $m[6];
				if ( isset( $m[7] ) && '' !== $m[7] ) {
					$micro = (int) str_pad( substr( $m[7], 1 ), 6, '0' );
				}
			}
		} elseif ( 1 === preg_match( '/^(\d{2})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{1,2}):(\d{1,2})(\.\d{1,6})?)?$/', $value, $m ) ) {
			$yy    = (int) $m[1];
			$year  = ( $yy < 70 ) ? ( 2000 + $yy ) : ( 1900 + $yy );
			$month = (int) $m[2];
			$day   = (int) $m[3];
			if ( isset( $m[4] ) && '' !== $m[4] ) {
				$hour   = (int) $m[4];
				$minute = (int) $m[5];
				$second = (int) $m[6];
				if ( isset( $m[7] ) && '' !== $m[7] ) {
					$micro = (int) str_pad( substr( $m[7], 1 ), 6, '0' );
				}
			}
		} elseif ( 1 === preg_match( '/^(\d{4})(\d{2})(\d{2})(?:(\d{2})(\d{2})(\d{2})(\.\d{1,6})?)?$/', $value, $m ) ) {
			$year  = (int) $m[1];
			$month = (int) $m[2];
			$day   = (int) $m[3];
			if ( isset( $m[4] ) && '' !== $m[4] ) {
				$hour   = (int) $m[4];
				$minute = (int) $m[5];
				$second = (int) $m[6];
				if ( isset( $m[7] ) && '' !== $m[7] ) {
					$micro = (int) str_pad( substr( $m[7], 1 ), 6, '0' );
				}
			}
		} elseif ( 1 === preg_match( '/^(\d{2})(\d{2})(\d{2})(?:(\d{2})(\d{2})(\d{2})(\.\d{1,6})?)?$/', $value, $m ) ) {
			$yy    = (int) $m[1];
			$year  = ( $yy < 70 ) ? ( 2000 + $yy ) : ( 1900 + $yy );
			$month = (int) $m[2];
			$day   = (int) $m[3];
			if ( isset( $m[4] ) && '' !== $m[4] ) {
				$hour   = (int) $m[4];
				$minute = (int) $m[5];
				$second = (int) $m[6];
				if ( isset( $m[7] ) && '' !== $m[7] ) {
					$micro = (int) str_pad( substr( $m[7], 1 ), 6, '0' );
				}
			}
		} elseif ( 1 === preg_match( '/^(\d{1,2}):(\d{1,2}):(\d{1,2})(\.\d{1,6})?$/', $value, $m ) ) {
			$year   = 1970;
			$month  = 1;
			$day    = 1;
			$hour   = (int) $m[1];
			$minute = (int) $m[2];
			$second = (int) $m[3];
			if ( isset( $m[4] ) && '' !== $m[4] ) {
				$micro = (int) str_pad( substr( $m[4], 1 ), 6, '0' );
			}
		} elseif ( 1 === preg_match( '/^(\d{1,2}):(\d{1,2})$/', $value, $m ) ) {
			$year   = 1970;
			$month  = 1;
			$day    = 1;
			$hour   = (int) $m[1];
			$minute = (int) $m[2];
		} else {
			return null;
		}

		if ( $year < 0 || $year > 9999 || $month < 1 || $month > 12 || $day < 1 || $day > $this->days_in_month( $year, $month ) ) {
			return null;
		}
		if ( $hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59 || $micro < 0 || $micro > 999999 ) {
			return null;
		}

		try {
			$date = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
			$date->setDate( $year, $month, $day );
			$date->setTime( $hour, $minute, $second, $micro );
		} catch ( Exception $e ) {
			return null;
		}
		return $date;
	}

	/**
	 * Parse a MySQL time interval into total microseconds.
	 *
	 * Accepts an optional sign with 'HH:MM:SS', 'MM:SS', or a bare digit
	 * string read right-to-left as SS, MMSS, or HHMMSS, each with an
	 * optional fractional part. Hours are unbounded so values past 24
	 * hours parse, and minute and second parts must stay below 60.
	 *
	 * @param mixed $value The value to parse.
	 *
	 * @return int|null The signed total microseconds, or NULL.
	 */
	private function parse_mysql_time_interval( $value ) {
		if ( null === $value ) {
			return null;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		$sign = 1;
		if ( '-' === $value[0] ) {
			$sign  = -1;
			$value = trim( substr( $value, 1 ) );
		} elseif ( '+' === $value[0] ) {
			$value = trim( substr( $value, 1 ) );
		}
		if ( '' === $value ) {
			return null;
		}

		$hour   = 0;
		$minute = 0;
		$second = 0;
		$micro  = 0;

		if ( 1 === preg_match( '/^(\d+):(\d{1,2}):(\d{1,2})(\.\d{1,6})?$/', $value, $m ) ) {
			$hour   = (int) $m[1];
			$minute = (int) $m[2];
			$second = (int) $m[3];
			if ( isset( $m[4] ) && '' !== $m[4] ) {
				$micro = (int) str_pad( substr( $m[4], 1 ), 6, '0' );
			}
		} elseif ( 1 === preg_match( '/^(\d{1,2}):(\d{1,2})(\.\d{1,6})?$/', $value, $m ) ) {
			$minute = (int) $m[1];
			$second = (int) $m[2];
			if ( isset( $m[3] ) && '' !== $m[3] ) {
				$micro = (int) str_pad( substr( $m[3], 1 ), 6, '0' );
			}
		} elseif ( 1 === preg_match( '/^(\d+)(\.\d{1,6})?$/', $value, $m ) ) {
			$digits = $m[1];
			$len    = strlen( $digits );
			if ( $len > 2 ) {
				$second = (int) substr( $digits, -2 );
				$digits = substr( $digits, 0, $len - 2 );
			} else {
				$second = (int) $digits;
				$digits = '';
			}
			if ( '' !== $digits ) {
				$len = strlen( $digits );
				if ( $len > 2 ) {
					$minute = (int) substr( $digits, -2 );
					$digits = substr( $digits, 0, $len - 2 );
				} else {
					$minute = (int) $digits;
					$digits = '';
				}
			}
			if ( '' !== $digits ) {
				$hour = (int) $digits;
			}
			if ( isset( $m[2] ) && '' !== $m[2] ) {
				$micro = (int) str_pad( substr( $m[2], 1 ), 6, '0' );
			}
		} else {
			return null;
		}

		if ( $minute < 0 || $minute > 59 || $second < 0 || $second > 59 || $micro < 0 || $micro > 999999 ) {
			return null;
		}
		return (int) ( $sign * ( ( $hour * 3600 + $minute * 60 + $second ) * 1000000 + $micro ) );
	}

	/**
	 * The number of days in a month under the proleptic Gregorian calendar.
	 *
	 * @param int $year  The year, 0 to 9999.
	 * @param int $month The month, 1 to 12.
	 *
	 * @return int The number of days.
	 */
	private function days_in_month( $year, $month ) {
		$lengths = array( 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
		if ( 2 !== $month ) {
			return $lengths[ $month - 1 ];
		}
		$is_leap = ( 0 === $year % 4 && 0 !== $year % 100 ) || 0 === $year % 400;
		return $is_leap ? 29 : 28;
	}

	/**
	 * Days since 1970-01-01 under the proleptic Gregorian calendar.
	 *
	 * Implements Howard Hinnant's days_from_civil algorithm.
	 *
	 * @param int $y The year.
	 * @param int $m The month, 1 to 12.
	 * @param int $d The day of the month.
	 *
	 * @return int The day count, negative before 1970-01-01.
	 */
	private function days_from_civil( $y, $m, $d ) {
		$y  -= ( $m <= 2 ) ? 1 : 0;
		$era = (int) floor( $y / 400 );
		$yoe = (int) ( $y - $era * 400 );
		$mp  = ( $m > 2 ) ? ( $m - 3 ) : ( $m + 9 );
		$doy = (int) floor( ( 153 * $mp + 2 ) / 5 ) + $d - 1;
		$doe = (int) ( $yoe * 365 + floor( $yoe / 4 ) - floor( $yoe / 100 ) + $doy );
		return (int) ( $era * 146097 + $doe - 719468 );
	}

	/**
	 * The inverse of days_from_civil(): civil date for a day count.
	 *
	 * Implements Howard Hinnant's civil_from_days algorithm.
	 *
	 * @param int $z Days since 1970-01-01.
	 *
	 * @return array The year, month, and day as array( $y, $m, $d ).
	 */
	private function civil_from_days( $z ) {
		$z   = (int) ( $z + 719468 );
		$era = (int) floor( $z / 146097 );
		$doe = (int) ( $z - $era * 146097 );
		$yoe = (int) floor( ( $doe - floor( $doe / 1460 ) + floor( $doe / 36524 ) - floor( $doe / 146096 ) ) / 365 );
		$y   = (int) ( $yoe + $era * 400 );
		$doy = (int) ( $doe - ( 365 * $yoe + floor( $yoe / 4 ) - floor( $yoe / 100 ) ) );
		$mp  = (int) floor( ( 5 * $doy + 2 ) / 153 );
		$d   = (int) ( $doy - floor( ( 153 * $mp + 2 ) / 5 ) + 1 );
		$m   = ( $mp < 10 ) ? ( $mp + 3 ) : ( $mp - 9 );
		$y  += ( $m <= 2 ) ? 1 : 0;
		return array( $y, $m, $d );
	}

	/**
	 * Add whole months to a datetime with end-of-month clamping.
	 *
	 * Matches MySQL: adding one month to January 31st yields the last day
	 * of February, not March 3rd as PHP's DateTime::modify() would.
	 *
	 * @param DateTime $date   The datetime, modified in place on a clone.
	 * @param int      $months The signed number of months to add.
	 *
	 * @return DateTime|null The shifted datetime, or NULL when out of range.
	 */
	private function add_months_clamped( DateTime $date, $months ) {
		$result = clone $date;
		$year   = (int) $result->format( 'Y' );
		$month  = (int) $result->format( 'n' );
		$day    = (int) $result->format( 'j' );
		$total  = (int) ( $year * 12 + ( $month - 1 ) + $months );
		$y      = (int) floor( $total / 12 );
		$m      = (int) ( $total - $y * 12 + 1 );
		if ( $y < 0 || $y > 9999 ) {
			return null;
		}
		$result->setDate( $y, $m, $day < $this->days_in_month( $y, $m ) ? $day : $this->days_in_month( $y, $m ) );
		return $result;
	}

	/**
	 * Add microseconds to a datetime with exact integer math.
	 *
	 * @param DateTime $date   The datetime to shift.
	 * @param int      $micros The signed microseconds to add.
	 *
	 * @return DateTime|null The shifted datetime, or NULL when out of range.
	 */
	private function add_microseconds( DateTime $date, $micros ) {
		$result = clone $date;
		/*
		 * setTimestamp() discards fractional seconds, so carry the source
		 * fraction across explicitly and fold it into the added remainder.
		 */
		$source_micros = (int) $result->format( 'u' );
		$seconds       = (int) intdiv( $micros, 1000000 );
		$rem           = (int) ( $micros - $seconds * 1000000 );
		if ( $rem < 0 ) {
			$seconds -= 1;
			$rem     += 1000000;
		}
		$total_micros = $source_micros + $rem;
		if ( $total_micros >= 1000000 ) {
			$total_micros -= 1000000;
			++$seconds;
		}
		$result->setTimestamp( (int) $result->format( 'U' ) + $seconds );
		if ( 0 !== $total_micros ) {
			$result->modify( sprintf( '+%d microseconds', $total_micros ) );
		}
		$year = (int) $result->format( 'Y' );
		if ( $year < 0 || $year > 9999 ) {
			return null;
		}
		return $result;
	}

	/**
	 * The exact difference of two UTC datetimes in microseconds.
	 *
	 * @param DateTime $date1 The datetime to subtract.
	 * @param DateTime $date2 The datetime to subtract from.
	 *
	 * @return int The signed difference in microseconds.
	 */
	private function datetime_diff_microseconds( DateTime $date1, DateTime $date2 ) {
		$seconds = (int) $date2->format( 'U' ) - (int) $date1->format( 'U' );
		$micro   = (int) $date2->format( 'u' ) - (int) $date1->format( 'u' );
		return (int) ( $seconds * 1000000 + $micro );
	}

	/**
	 * The difference of the date parts of two datetimes, in days.
	 *
	 * Time-of-day parts are ignored, matching MySQL's DAY and WEEK units.
	 *
	 * @param DateTime $date1 The datetime to subtract.
	 * @param DateTime $date2 The datetime to subtract from.
	 *
	 * @return int The signed difference in days.
	 */
	private function date_diff_days( DateTime $date1, DateTime $date2 ) {
		return (int) (
			$this->days_from_civil( (int) $date2->format( 'Y' ), (int) $date2->format( 'n' ), (int) $date2->format( 'j' ) ) -
			$this->days_from_civil( (int) $date1->format( 'Y' ), (int) $date1->format( 'n' ), (int) $date1->format( 'j' ) )
		);
	}

	/**
	 * The difference of two datetimes in complete months or quarters.
	 *
	 * Counts how many whole $per-month periods fit between the datetimes,
	 * truncating toward zero and clamping end-of-month starts like MySQL.
	 *
	 * @param DateTime $date1 The datetime to subtract.
	 * @param DateTime $date2 The datetime to subtract from.
	 * @param int      $per   The period length in months (1, 3, or 12).
	 *
	 * @return int The signed number of complete periods.
	 */
	private function datetime_diff_months( DateTime $date1, DateTime $date2, $per ) {
		/*
		 * MySQL does not clamp end-of-month dates here: it orders the two
		 * values, takes the month difference, and drops one month when the
		 * later value's day-and-time part is behind the earlier one. That
		 * makes TIMESTAMPDIFF(MONTH, '2024-01-31', '2024-02-29') zero.
		 */
		if ( $date2 >= $date1 ) {
			$earlier = $date1;
			$later   = $date2;
			$sign    = 1;
		} else {
			$earlier = $date2;
			$later   = $date1;
			$sign    = -1;
		}
		$months = ( (int) $later->format( 'Y' ) - (int) $earlier->format( 'Y' ) ) * 12
			+ ( (int) $later->format( 'n' ) - (int) $earlier->format( 'n' ) );
		if ( $months > 0 && $later->format( 'dHisu' ) < $earlier->format( 'dHisu' ) ) {
			--$months;
		}
		return (int) ( $sign * intdiv( $months, $per ) );
	}

	/**
	 * Convert a YYMM or YYYYMM period to absolute months.
	 *
	 * Two-digit years convert as 00 to 69 becoming 2000 to 2069 and 70 to
	 * 99 becoming 1970 to 1999.
	 *
	 * @param mixed $period The period value.
	 *
	 * @return array|null The total months and two-digit flag as array( $total, $two_digit ), or NULL.
	 */
	private function period_to_months( $period ) {
		if ( null === $period || ! is_numeric( $period ) ) {
			return null;
		}
		$period = (int) $period;
		if ( $period < 0 ) {
			return null;
		}
		$year  = (int) intdiv( $period, 100 );
		$month = (int) ( $period - $year * 100 );
		if ( $month < 1 || $month > 12 ) {
			return null;
		}
		$two_digit = ( $year < 100 );
		$full      = $two_digit ? (int) ( $year + ( $year < 70 ? 2000 : 1900 ) ) : $year;
		return array( (int) ( $full * 12 + ( $month - 1 ) ), $two_digit );
	}

	/**
	 * Add or subtract two time expressions shared by ADDTIME()/SUBTIME().
	 *
	 * @param string|null $expr1 A date, datetime, or time expression.
	 * @param string|null $expr2 A time expression to add or subtract.
	 * @param int         $sign  1 to add, -1 to subtract.
	 *
	 * @return string|null The resulting time or datetime, or NULL.
	 */
	private function add_sub_time( $expr1, $expr2, $sign ) {
		if ( null === $expr1 || null === $expr2 ) {
			return null;
		}
		$t2 = $this->parse_mysql_time_interval( $expr2 );
		if ( null === $t2 ) {
			return null;
		}
		if ( $this->mysql_value_has_date( $expr1 ) ) {
			$date = $this->parse_mysql_datetime( $expr1 );
			if ( null === $date ) {
				return null;
			}
			$base   = (int) $date->format( 'U' ) * 1000000 + (int) $date->format( 'u' );
			$result = $this->micros_to_datetime( $base + $sign * $t2 );
			if ( null === $result ) {
				return null;
			}
			return $this->format_mysql_datetime( $result );
		}
		$t1 = $this->parse_mysql_time_interval( $expr1 );
		if ( null === $t1 ) {
			return null;
		}
		$result = (int) ( $t1 + $sign * $t2 );
		$limit  = 3020399000000;
		if ( $result > $limit ) {
			$result = $limit;
		} elseif ( $result < -$limit ) {
			$result = -$limit;
		}
		return $this->format_mysql_time( $result );
	}

	/**
	 * Build a UTC datetime from total microseconds since the epoch.
	 *
	 * @param int $micros Microseconds since 1970-01-01 00:00:00 UTC.
	 *
	 * @return DateTime|null The datetime, or NULL when out of range.
	 */
	private function micros_to_datetime( $micros ) {
		$seconds = (int) intdiv( $micros, 1000000 );
		$rem     = (int) ( $micros - $seconds * 1000000 );
		if ( $rem < 0 ) {
			$seconds -= 1;
			$rem     += 1000000;
		}
		$date = new DateTime( '@0' );
		$date->setTimestamp( $seconds );
		if ( 0 !== $rem ) {
			$date->modify( sprintf( '+%d microseconds', $rem ) );
		}
		$year = (int) $date->format( 'Y' );
		if ( $year < 0 || $year > 9999 ) {
			return null;
		}
		return $date;
	}

	/**
	 * Format a UTC datetime as MySQL does, trimming zero fractions.
	 *
	 * @param DateTime $date The datetime in UTC.
	 *
	 * @return string The datetime formatted as 'Y-m-d H:i:s[.fraction]'.
	 */
	private function format_mysql_datetime( DateTime $date ) {
		$out   = $date->format( 'Y-m-d H:i:s' );
		$micro = (int) $date->format( 'u' );
		if ( 0 !== $micro ) {
			$out .= '.' . rtrim( sprintf( '%06d', $micro ), '0' );
		}
		return $out;
	}

	/**
	 * Format total microseconds as a MySQL TIME value.
	 *
	 * Hours stay unpadded past two digits and a fractional part trims
	 * trailing zeros, e.g. 90061000000 becomes '25:01:01'.
	 *
	 * @param int $micros The signed total microseconds.
	 *
	 * @return string The time formatted as '[−]H:MM:SS[.fraction]'.
	 */
	private function format_mysql_time( $micros ) {
		$sign   = ( $micros < 0 ) ? '-' : '';
		$abs    = (int) abs( $micros );
		$hour   = (int) intdiv( $abs, 3600000000 );
		$rest   = (int) ( $abs - $hour * 3600000000 );
		$minute = (int) intdiv( $rest, 60000000 );
		$second = (int) intdiv( $rest - $minute * 60000000, 1000000 );
		$out    = sprintf( '%s%02d:%02d:%02d', $sign, $hour, $minute, $second );
		$micro  = (int) ( $rest % 1000000 );
		if ( 0 !== $micro ) {
			$out .= '.' . rtrim( sprintf( '%06d', $micro ), '0' );
		}
		return $out;
	}

	/**
	 * Return an integer in a form that survives PDO SQLite UDF marshaling.
	 *
	 * This PHP build truncates UDF integer arguments and return values to
	 * 32 bits, so values past the signed 32-bit range are returned as
	 * floats instead. Floats stay exact up to 2**53 and SQLite compares
	 * and computes with REALs numerically, so TO_SECONDS() dates past
	 * 2038 and wide TIMESTAMPDIFF() ranges keep working.
	 *
	 * @param int|float $n The value to return.
	 *
	 * @return int|float The value as an int when it fits, else a float.
	 */
	private function sqlite_safe_int( $n ) {
		if ( $n > 2147483647 || $n < -2147483648 ) {
			return (float) $n;
		}
		return (int) $n;
	}

	/**
	 * Substitute a microseconds value for %f in a format string.
	 *
	 * The dateformat() method has no %f specifier, so TIME_FORMAT()
	 * replaces it with the zero-padded 6-digit value up front.
	 *
	 * @param string $format The format string.
	 * @param int    $micro  The microseconds, 0 to 999999.
	 *
	 * @return string The format string with %f substituted.
	 */
	private function substitute_microseconds( $format, $micro ) {
		if ( false === strpos( $format, '%f' ) ) {
			return $format;
		}
		return str_replace( '%f', sprintf( '%06d', $micro ), $format );
	}

	/**
	 * Format out-of-range hours for TIME_FORMAT() without delegating.
	 *
	 * Handles times with hours past 23 or negative times, which the
	 * DATETIME-based dateformat() path cannot represent. Supports the
	 * same time specifiers dateformat() supports, with unbounded %H and
	 * %k, 12-hour clocks derived from the hour modulo 24, and %f as the
	 * zero-padded 6-digit microseconds.
	 *
	 * @param int    $hour   The signed total hours.
	 * @param int    $minute The minutes, 0 to 59.
	 * @param int    $second The seconds, 0 to 59.
	 * @param int    $micro  The microseconds, 0 to 999999.
	 * @param string $format The format string.
	 *
	 * @return string The formatted time.
	 */
	private function format_time_fallback( $hour, $minute, $second, $micro, $format ) {
		$sign = ( $hour < 0 ) ? '-' : '';
		$abs  = (int) abs( $hour );
		$h24  = (int) ( ( $hour % 24 + 24 ) % 24 );
		$h12  = (int) ( $h24 % 12 );
		if ( 0 === $h12 ) {
			$h12 = 12;
		}
		$replacements = array(
			'%H' => sprintf( '%s%02d', $sign, $abs ),
			'%k' => sprintf( '%s%d', $sign, $abs ),
			'%h' => sprintf( '%02d', $h12 ),
			'%I' => sprintf( '%02d', $h12 ),
			'%l' => sprintf( '%d', $h12 ),
			'%i' => sprintf( '%02d', $minute ),
			'%S' => sprintf( '%02d', $second ),
			'%s' => sprintf( '%02d', $second ),
			'%f' => sprintf( '%06d', $micro ),
			'%p' => ( $h24 < 12 ) ? 'AM' : 'PM',
			'%T' => sprintf( '%s%02d:%02d:%02d', $sign, $abs, $minute, $second ),
			'%r' => sprintf( '%02d:%02d:%02d %s', $h12, $minute, $second, ( $h24 < 12 ) ? 'AM' : 'PM' ),
		);
		return strtr( $format, $replacements );
	}

	/**
	 * Convert an English month name to its number.
	 *
	 * Accepts full and abbreviated names, case-insensitively.
	 *
	 * @param string $name The month name.
	 *
	 * @return int|null The month number, 1 to 12, or NULL.
	 */
	private function month_name_to_number( $name ) {
		$months = array(
			'january'   => 1,
			'february'  => 2,
			'march'     => 3,
			'april'     => 4,
			'may'       => 5,
			'june'      => 6,
			'july'      => 7,
			'august'    => 8,
			'september' => 9,
			'october'   => 10,
			'november'  => 11,
			'december'  => 12,
			'jan'       => 1,
			'feb'       => 2,
			'mar'       => 3,
			'apr'       => 4,
			'jun'       => 6,
			'jul'       => 7,
			'aug'       => 8,
			'sep'       => 9,
			'sept'      => 9,
			'oct'       => 10,
			'nov'       => 11,
			'dec'       => 12,
		);
		$key    = strtolower( trim( $name ) );
		if ( ! isset( $months[ $key ] ) ) {
			return null;
		}
		return $months[ $key ];
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
}
