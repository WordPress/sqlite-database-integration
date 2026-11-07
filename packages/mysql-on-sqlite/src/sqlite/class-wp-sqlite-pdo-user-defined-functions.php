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
	/**
	 * Maximum size of a string a UDF will allocate, in bytes.
	 *
	 * Matches the MySQL 8.0 default max_allowed_packet. MySQL returns NULL
	 * for results above the limit; doing the same here avoids a fatal
	 * out-of-memory error inside str_repeat().
	 */
	private const MAX_STRING_RESULT_BYTES = 67108864;

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
		'repeat'                       => 'repeat',
		'right_str'                    => 'right',
		'lpad'                         => 'lpad',
		'rpad'                         => 'rpad',
		'space'                        => 'space',
		'ascii'                        => 'ascii',
		'ord'                          => 'ord',
		'strcmp'                       => 'strcmp',
		'find_in_set'                  => 'find_in_set',
		'elt'                          => 'elt',
		'make_set'                     => 'make_set',
		'export_set'                   => 'export_set',
		'soundex'                      => 'soundex',
		'format'                       => 'format',
		'insert_str'                   => 'insert_str',
		'truncate'                     => 'truncate',

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
	 * @param integer $pos    1-based position to start the search from.
	 *
	 * @return integer|null
	 */
	public function locate( $substr, $str, $pos = 1 ) {
		if ( null === $substr || null === $str || null === $pos ) {
			return null;
		}
		$substr = (string) $substr;
		$str    = (string) $str;

		// MySQL positions are 1-based; clamp invalid values to the start.
		$offset = (int) round( (float) $pos ) - 1;
		if ( 0 > $offset ) {
			$offset = 0;
		}

		/*
		 * strpos()/mb_strpos() throw when the offset is past the end of the
		 * haystack, and an empty needle returns false with a warning on
		 * older PHP. MySQL returns 0 and the start position respectively.
		 */
		if ( $offset > $this->char_strlen( $str ) ) {
			return 0;
		}
		if ( '' === $substr ) {
			return $offset + 1;
		}

		if ( extension_loaded( 'mbstring' ) ) {
			$val = mb_strpos( $str, $substr, $offset, 'UTF-8' );
			if ( false === $val ) {
				return 0;
			}
			return $val + 1;
		}
		$val = strpos( $str, $substr, $offset );
		if ( false === $val ) {
			return 0;
		}
		return $val + 1;
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
	 * Get the length of a string in characters.
	 *
	 * Uses mb_strlen() when the mbstring extension is loaded, so that
	 * multi-byte characters are counted like MySQL does. Falls back to a
	 * byte count otherwise.
	 *
	 * @param string $str The string to measure.
	 *
	 * @return int The character length.
	 */
	private function char_strlen( $str ) {
		if ( extension_loaded( 'mbstring' ) ) {
			$length = mb_strlen( $str, 'UTF-8' );
			if ( false !== $length ) {
				return $length;
			}
		}
		return strlen( $str );
	}

	/**
	 * Get a substring counted in characters, not bytes.
	 *
	 * Uses mb_substr() when the mbstring extension is loaded, so that
	 * multi-byte characters are not split like MySQL does. Falls back to a
	 * byte-based substr() otherwise.
	 *
	 * @param string   $str    The input string.
	 * @param int      $start  0-based start offset in characters.
	 * @param int|null $length Optional length in characters.
	 *
	 * @return string The substring, or an empty string when out of range.
	 */
	private function char_substr( $str, $start, $length = null ) {
		if ( extension_loaded( 'mbstring' ) ) {
			if ( null === $length ) {
				$result = mb_substr( $str, $start, null, 'UTF-8' );
			} else {
				$result = mb_substr( $str, $start, $length, 'UTF-8' );
			}
			if ( false !== $result ) {
				return $result;
			}
		}
		if ( null === $length ) {
			$result = substr( $str, $start );
		} else {
			$result = substr( $str, $start, $length );
		}
		if ( false === $result ) {
			return '';
		}
		return $result;
	}

	/**
	 * Build a padding string of an exact character length.
	 *
	 * Repeats $padstr and truncates it to $length characters, counting
	 * multi-byte characters like MySQL does.
	 *
	 * @param string $padstr The padding string, must not be empty.
	 * @param int    $length The number of characters to build.
	 *
	 * @return string The padding string.
	 */
	private function build_pad_string( $padstr, $length ) {
		$pad_length = $this->char_strlen( $padstr );
		$repeat     = (int) ceil( $length / $pad_length );
		if ( 1 > $repeat ) {
			$repeat = 1;
		}
		if ( ! $this->string_size_within_limit( strlen( $padstr ), $repeat ) ) {
			return null;
		}
		return $this->char_substr( str_repeat( $padstr, $repeat ), 0, $length );
	}

	/**
	 * Whether a generated string stays within the maximum result size.
	 *
	 * MySQL returns NULL with a warning when a string result exceeds
	 * max_allowed_packet. Here an unbounded str_repeat() would exhaust the
	 * PHP memory limit and end the request, so the same limit is applied
	 * before allocating.
	 *
	 * @param int $unit_length Length of one unit in bytes.
	 * @param int $units       Number of units to concatenate.
	 *
	 * @return bool Whether the result fits.
	 */
	private function string_size_within_limit( $unit_length, $units ) {
		return ( $unit_length * $units ) <= self::MAX_STRING_RESULT_BYTES;
	}

	/**
	 * Method to emulate MySQL REPEAT() function.
	 *
	 * Returns a string consisting of $str repeated $count times. Returns an
	 * empty string when $count is less than 1, and NULL when any argument
	 * is NULL.
	 *
	 * @param string|null $str   The string to repeat.
	 * @param mixed       $count The repeat count.
	 *
	 * @return string|null The repeated string, or NULL.
	 */
	public function repeat( $str, $count ) {
		if ( null === $str || null === $count ) {
			return null;
		}
		$count = (int) round( (float) $count );
		if ( 1 > $count ) {
			return '';
		}
		$str = (string) $str;
		if ( ! $this->string_size_within_limit( strlen( $str ), $count ) ) {
			return null;
		}
		return str_repeat( $str, $count );
	}

	/**
	 * Method to emulate MySQL RIGHT() function.
	 *
	 * Returns the rightmost $len characters of $str. Returns an empty string
	 * when $len is less than 1, and NULL when any argument is NULL.
	 *
	 * @param string|null $str The input string.
	 * @param mixed       $len The number of characters.
	 *
	 * @return string|null The rightmost characters, or NULL.
	 */
	public function right( $str, $len ) {
		if ( null === $str || null === $len ) {
			return null;
		}
		$len = (int) round( (float) $len );
		if ( 1 > $len ) {
			return '';
		}
		$str = (string) $str;
		if ( $len >= $this->char_strlen( $str ) ) {
			return $str;
		}
		if ( extension_loaded( 'mbstring' ) ) {
			return mb_substr( $str, -$len, null, 'UTF-8' );
		}
		return substr( $str, -$len );
	}

	/**
	 * Method to emulate MySQL LPAD() function.
	 *
	 * Left-pads $str with $padstr to a length of $len characters. Longer
	 * strings are truncated. Returns NULL when any argument is NULL, when
	 * $len is negative, or when padding is needed but $padstr is empty.
	 *
	 * @param string|null $str    The input string.
	 * @param mixed       $len    The target length in characters.
	 * @param string|null $padstr The padding string.
	 *
	 * @return string|null The padded string, or NULL.
	 */
	public function lpad( $str, $len, $padstr ) {
		if ( null === $str || null === $len || null === $padstr ) {
			return null;
		}
		$len = (int) round( (float) $len );
		if ( 0 > $len ) {
			return null;
		}
		if ( 0 === $len ) {
			return '';
		}
		$str    = (string) $str;
		$padstr = (string) $padstr;
		if ( $this->char_strlen( $str ) >= $len ) {
			return $this->char_substr( $str, 0, $len );
		}
		if ( '' === $padstr ) {
			return null;
		}
		$pad = $this->build_pad_string( $padstr, $len - $this->char_strlen( $str ) );
		if ( null === $pad || ! $this->string_size_within_limit( strlen( $pad ) + strlen( $str ), 1 ) ) {
			return null;
		}
		return $pad . $str;
	}

	/**
	 * Method to emulate MySQL RPAD() function.
	 *
	 * Right-pads $str with $padstr to a length of $len characters. Longer
	 * strings are truncated. Returns NULL when any argument is NULL, when
	 * $len is negative, or when padding is needed but $padstr is empty.
	 *
	 * @param string|null $str    The input string.
	 * @param mixed       $len    The target length in characters.
	 * @param string|null $padstr The padding string.
	 *
	 * @return string|null The padded string, or NULL.
	 */
	public function rpad( $str, $len, $padstr ) {
		if ( null === $str || null === $len || null === $padstr ) {
			return null;
		}
		$len = (int) round( (float) $len );
		if ( 0 > $len ) {
			return null;
		}
		if ( 0 === $len ) {
			return '';
		}
		$str    = (string) $str;
		$padstr = (string) $padstr;
		if ( $this->char_strlen( $str ) >= $len ) {
			return $this->char_substr( $str, 0, $len );
		}
		if ( '' === $padstr ) {
			return null;
		}
		$pad = $this->build_pad_string( $padstr, $len - $this->char_strlen( $str ) );
		if ( null === $pad || ! $this->string_size_within_limit( strlen( $pad ) + strlen( $str ), 1 ) ) {
			return null;
		}
		return $str . $pad;
	}

	/**
	 * Method to emulate MySQL SPACE() function.
	 *
	 * Returns a string of $n spaces, or an empty string when $n is less
	 * than 1. Returns NULL when $n is NULL.
	 *
	 * @param mixed $n The number of spaces.
	 *
	 * @return string|null The spaces, or NULL.
	 */
	public function space( $n ) {
		if ( null === $n ) {
			return null;
		}
		$n = (int) round( (float) $n );
		if ( 1 > $n ) {
			return '';
		}
		if ( ! $this->string_size_within_limit( 1, $n ) ) {
			return null;
		}
		return str_repeat( ' ', $n );
	}

	/**
	 * Method to emulate MySQL ASCII() function.
	 *
	 * Returns the numeric value of the first byte of $str. Returns 0 when
	 * $str is the empty string, and NULL when $str is NULL.
	 *
	 * @param string|null $str The input string.
	 *
	 * @return int|null The byte value, 0, or NULL.
	 */
	public function ascii( $str ) {
		if ( null === $str ) {
			return null;
		}
		$str = (string) $str;
		if ( '' === $str ) {
			return 0;
		}
		return ord( $str[0] );
	}

	/**
	 * Method to emulate MySQL ORD() function.
	 *
	 * Returns the code of the leftmost character of $str. Multi-byte
	 * characters are decoded as UTF-8. Returns 0 when $str is the empty
	 * string, and NULL when $str is NULL.
	 *
	 * @param string|null $str The input string.
	 *
	 * @return int|null The character code, 0, or NULL.
	 */
	public function ord( $str ) {
		if ( null === $str ) {
			return null;
		}
		$str = (string) $str;
		if ( '' === $str ) {
			return 0;
		}
		/*
		 * MySQL computes ORD() from the bytes of the leftmost character,
		 * not from its Unicode code point: ORD('é') in utf8mb4 is
		 * (0xC3 * 256) + 0xA9 = 50089, not 233.
		 */
		$char = $this->char_substr( $str, 0, 1 );
		if ( '' === $char ) {
			return ord( $str[0] );
		}
		$code = 0;
		foreach ( unpack( 'C*', $char ) as $byte ) {
			$code = $code * 256 + $byte;
		}
		return $code;
	}

	/**
	 * Decode the first UTF-8 character of a string into its codepoint.
	 *
	 * Returns NULL when the string does not start with a valid UTF-8
	 * sequence. Used only when the mbstring extension is not loaded.
	 *
	 * @param string $str The input string.
	 *
	 * @return int|null The codepoint, or NULL.
	 */
	private function utf8_codepoint( $str ) {
		$b0 = ord( $str[0] );
		if ( 0x80 > $b0 ) {
			return $b0;
		}
		if ( 0xC0 === ( $b0 & 0xE0 ) ) {
			$code     = $b0 & 0x1F;
			$expected = 1;
			$min      = 0x80;
		} elseif ( 0xE0 === ( $b0 & 0xF0 ) ) {
			$code     = $b0 & 0x0F;
			$expected = 2;
			$min      = 0x800;
		} elseif ( 0xF0 === ( $b0 & 0xF8 ) ) {
			$code     = $b0 & 0x07;
			$expected = 3;
			$min      = 0x10000;
		} else {
			return null;
		}
		if ( strlen( $str ) < $expected + 1 ) {
			return null;
		}
		for ( $i = 1; $i <= $expected; $i++ ) {
			$b = ord( $str[ $i ] );
			if ( 0x80 !== ( $b & 0xC0 ) ) {
				return null;
			}
			$code = ( $code << 6 ) | ( $b & 0x3F );
		}
		if ( $code < $min || 0x10FFFF < $code || ( 0xD800 <= $code && 0xDFFF >= $code ) ) {
			return null;
		}
		return $code;
	}

	/**
	 * Method to emulate MySQL STRCMP() function.
	 *
	 * Returns -1, 0, or 1 depending on whether $a is smaller than, equal
	 * to, or greater than $b. The comparison is byte-wise. Returns NULL
	 * when any argument is NULL.
	 *
	 * @param mixed $a The first string.
	 * @param mixed $b The second string.
	 *
	 * @return int|null -1, 0, 1, or NULL.
	 */
	public function strcmp( $a, $b ) {
		if ( null === $a || null === $b ) {
			return null;
		}
		$result = strcmp( (string) $a, (string) $b );
		if ( 0 > $result ) {
			return -1;
		}
		if ( 0 < $result ) {
			return 1;
		}
		return 0;
	}

	/**
	 * Method to emulate MySQL FIND_IN_SET() function.
	 *
	 * Returns the 1-based position of $str in the comma-separated $strlist,
	 * or 0 when not found. Returns NULL when any argument is NULL.
	 *
	 * @param mixed $str     The string to find.
	 * @param mixed $strlist The comma-separated list.
	 *
	 * @return int|null The position, 0, or NULL.
	 */
	public function find_in_set( $str, $strlist ) {
		if ( null === $str || null === $strlist ) {
			return null;
		}
		$str = (string) $str;
		if ( false !== strpos( $str, ',' ) ) {
			return 0;
		}
		$strlist = (string) $strlist;
		if ( '' === $strlist ) {
			return 0;
		}
		// MySQL compares with the column collation, which is
		// case-insensitive by default.
		foreach ( explode( ',', $strlist ) as $index => $part ) {
			if ( 0 === strcasecmp( $part, $str ) ) {
				return $index + 1;
			}
		}
		return 0;
	}

	/**
	 * Method to emulate MySQL ELT() function.
	 *
	 * Returns the Nth string of the argument list, counting from 1. Returns
	 * NULL when N is NULL, less than 1, or greater than the number of
	 * strings.
	 *
	 * @return mixed The Nth string, or NULL.
	 */
	public function elt() {
		$args = func_get_args();
		$n    = array_shift( $args );
		if ( null === $n ) {
			return null;
		}
		$n = (int) round( (float) $n );
		if ( 1 > $n || $n > count( $args ) ) {
			return null;
		}
		return $args[ $n - 1 ];
	}

	/**
	 * Method to emulate MySQL MAKE_SET() function.
	 *
	 * Returns a comma-separated string of the arguments that have a
	 * corresponding bit set in $bits. NULL arguments are skipped. Returns
	 * NULL when $bits is NULL.
	 *
	 * @return string|null The set string, or NULL.
	 */
	public function make_set() {
		$args = func_get_args();
		$bits = array_shift( $args );
		if ( null === $bits ) {
			return null;
		}
		$bits   = (int) $bits;
		$result = array();
		foreach ( $args as $index => $str ) {
			if ( null === $str ) {
				continue;
			}
			if ( ( $bits >> $index ) & 1 ) {
				$result[] = $str;
			}
		}
		return implode( ',', $result );
	}

	/**
	 * Method to emulate MySQL EXPORT_SET() function.
	 *
	 * Returns a string with one $on/$off word per examined bit, lowest bit
	 * first, joined with $separator. Examines $number_of_bits bits, 64 by
	 * default. Returns NULL when any argument is NULL.
	 *
	 * @param mixed  $bits           The bit vector.
	 * @param mixed  $on             The word for set bits.
	 * @param mixed  $off            The word for clear bits.
	 * @param mixed  $separator      The separator, defaults to ','.
	 * @param mixed  $number_of_bits The number of bits, defaults to 64.
	 *
	 * @return string|null The bit string, or NULL.
	 */
	public function export_set( $bits, $on, $off, $separator = ',', $number_of_bits = 64 ) {
		if ( 4 < func_num_args() && null === $number_of_bits ) {
			return null;
		}
		if ( null === $bits || null === $on || null === $off || null === $separator ) {
			return null;
		}
		$number_of_bits = (int) round( (float) $number_of_bits );
		if ( 1 > $number_of_bits ) {
			return '';
		}
		// MySQL clips the bit count to the width of a BIGINT.
		if ( 64 < $number_of_bits ) {
			$number_of_bits = 64;
		}
		$bits  = (int) $bits;
		$words = array();
		for ( $i = 0; $i < $number_of_bits; $i++ ) {
			if ( ( $bits >> $i ) & 1 ) {
				$words[] = $on;
			} else {
				$words[] = $off;
			}
		}
		return implode( (string) $separator, $words );
	}

	/**
	 * Method to emulate MySQL TRUNCATE() function.
	 *
	 * Truncates toward zero to $decimals decimal places; a negative value
	 * zeroes digits to the left of the decimal point. Returns an integer
	 * when no fractional part remains, mirroring MySQL's result type.
	 *
	 * @param mixed $x        The number to truncate.
	 * @param mixed $decimals The number of decimal places.
	 *
	 * @return int|float|null The truncated value, or NULL.
	 */
	public function truncate( $x, $decimals ) {
		if ( null === $x || null === $decimals ) {
			return null;
		}
		$decimals = (int) round( (float) $decimals );
		if ( is_bool( $x ) ) {
			$x = (int) $x;
		}
		if ( ! is_numeric( $x ) ) {
			$x = 0;
		}
		$value = (float) $x;
		if ( ! is_finite( $value ) ) {
			return null;
		}
		$factor = pow( 10, abs( $decimals ) );
		if ( ! is_finite( $factor ) ) {
			return null;
		}
		$sign = $value < 0 ? -1 : 1;
		$abs  = abs( $value );
		if ( 0 <= $decimals ) {
			$result = floor( $abs * $factor ) / $factor;
		} else {
			$result = floor( $abs / $factor ) * $factor;
		}
		$result *= $sign;
		if ( 0 >= $decimals ) {
			if ( $result > PHP_INT_MAX || $result < PHP_INT_MIN ) {
				return $result;
			}
			return (int) $result;
		}
		return $result;
	}

	/**
	 * Method to emulate MySQL SOUNDEX() function.
	 *
	 * Returns a soundex string of $str. Returns NULL when $str is NULL.
	 *
	 * @param string|null $str The input string.
	 *
	 * @return string|null The soundex string, or NULL.
	 */
	public function soundex( $str ) {
		if ( null === $str ) {
			return null;
		}
		return soundex( (string) $str );
	}

	/**
	 * Method to emulate MySQL FORMAT() function.
	 *
	 * Formats $number with thousands separators and $decimals decimals,
	 * rounding half away from zero. The optional $locale argument is
	 * accepted but only the default separators are produced. Returns NULL
	 * when any given argument is NULL.
	 *
	 * @param mixed      $number   The number to format.
	 * @param mixed      $decimals The number of decimals.
	 * @param mixed|null $locale   An optional locale name, ignored.
	 *
	 * @return string|null The formatted number, or NULL.
	 */
	public function format( $number, $decimals, $locale = null ) {
		if ( 2 < func_num_args() && null === $locale ) {
			return null;
		}
		if ( null === $number || null === $decimals ) {
			return null;
		}
		$decimals = (int) round( (float) $decimals );
		if ( 0 > $decimals ) {
			$decimals = 0;
		}
		return number_format( (float) $number, $decimals, '.', ',' );
	}

	/**
	 * Method to emulate the MySQL string function INSERT().
	 *
	 * Returns $str with the substring beginning at 1-based position $pos
	 * and $len characters long replaced by $newstr. Returns the original
	 * $str when $pos is outside the string. A $len beyond the rest of the
	 * string replaces everything from $pos. Returns NULL when any argument
	 * is NULL.
	 *
	 * Registered as "insert_str" because INSERT is a reserved word that
	 * SQLite will not accept as a function name.
	 *
	 * @param string|null $str    The input string.
	 * @param mixed       $pos    The 1-based position.
	 * @param mixed       $len    The number of characters to replace.
	 * @param string|null $newstr The replacement string.
	 *
	 * @return string|null The resulting string, or NULL.
	 */
	public function insert_str( $str, $pos, $len, $newstr ) {
		if ( null === $str || null === $pos || null === $len || null === $newstr ) {
			return null;
		}
		$str    = (string) $str;
		$newstr = (string) $newstr;
		$pos    = (int) round( (float) $pos );
		$len    = (int) round( (float) $len );
		if ( 0 > $len ) {
			$len = 0;
		}
		$strlen = $this->char_strlen( $str );
		if ( 1 > $pos || $pos > $strlen ) {
			return $str;
		}
		return $this->char_substr( $str, 0, $pos - 1 )
			. $newstr
			. $this->char_substr( $str, $pos - 1 + $len );
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
