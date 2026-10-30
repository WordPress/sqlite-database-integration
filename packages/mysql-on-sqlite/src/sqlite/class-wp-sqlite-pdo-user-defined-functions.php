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
		'version'                      => 'version',
		'reverse'                      => 'reverse',
		'json_exists'                  => 'json_exists',
		'chr'                          => 'chr',
		'json_compact'                 => 'json_compact',
		'json_detailed'                => 'json_detailed',
		'json_normalize'               => 'json_normalize',
		'json_object_filter_keys'      => 'json_object_filter_keys',
		'crc32c'                       => 'crc32c',

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
	 * Lookup table for the pure-PHP CRC-32C implementation.
	 *
	 * Built lazily by _helper_crc32c_raw(); only used when PHP's hash()
	 * does not offer the 'crc32c' algorithm or when chaining checksums.
	 *
	 * @var array|null
	 */
	private static $crc32c_table = null;

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
	 * Method to emulate MariaDB JSON_EXISTS() function.
	 *
	 * Returns 1 when the given JSON path exists in the document, 0 when it
	 * does not, and NULL when any argument is NULL or when either the
	 * document or the path is invalid. Never throws.
	 *
	 * Supported path syntax is a subset of MariaDB JSON paths: '$' for the
	 * root, '.name' for bare member names ('[A-Za-z0-9_]+', anything else
	 * must use the quoted form), '."quoted name"' and "$['quoted name']"
	 * (single or double quotes, backslash escapes) for arbitrary member
	 * names, and '[N]' for array indices, including '$[N]' at the top
	 * level. A type mismatch along the path (e.g. a member lookup on an
	 * array, or an index lookup on an object or scalar) means the path
	 * does not exist and yields 0, matching MariaDB.
	 *
	 * Differences from MariaDB, all by design:
	 * - Wildcard ('*', '**') and range paths are out of scope and yield
	 *   NULL instead of being evaluated.
	 * - MariaDB rejects some paths this implementation accepts and vice
	 *   versa; anything outside the subset above yields NULL.
	 * - Duplicate object keys keep the last value (PHP json_decode
	 *   semantics); JSON deeper than 512 levels yields NULL.
	 *
	 * @return int|null 1 when the path exists, 0 when it does not, NULL on
	 *                  NULL or invalid input.
	 */
	public function json_exists() {
		if ( 2 !== func_num_args() ) {
			return null;
		}
		$args = func_get_args();
		$doc  = $args[0];
		$path = $args[1];
		if ( null === $doc || null === $path ) {
			return null;
		}
		list( $ok, $data ) = $this->_helper_json_decode_doc( $doc );
		if ( ! $ok ) {
			return null;
		}
		list( $valid, $exists ) = $this->_helper_json_path_lookup( $data, $path );
		if ( ! $valid ) {
			return null;
		}
		return $exists ? 1 : 0;
	}

	/**
	 * Method to emulate MariaDB CHR() function.
	 *
	 * Returns the character for the given Unicode codepoint as a UTF-8
	 * string. NULL yields NULL, and so does anything that is not a valid
	 * codepoint: negative values, values above U+10FFFF, UTF-16 surrogates
	 * (U+D800-U+DFFF), and non-numeric strings. MariaDB instead coerces
	 * invalid input with a warning, but this driver has no warning channel,
	 * so NULL is returned and nothing is thrown. Floats and numeric strings
	 * are truncated toward zero, booleans are cast to 0 or 1.
	 *
	 * MariaDB CHR() accepts exactly one argument; this implementation also
	 * accepts additional codepoints and concatenates them (CHAR()-style),
	 * which is a strict superset: every valid MariaDB CHR(N) call behaves
	 * the same. Zero arguments yields NULL.
	 *
	 * Uses mb_chr() when mbstring is available, chr() for values below 256
	 * otherwise, and a pure-PHP UTF-8 encoder above that. The zero codepoint
	 * is always encoded directly, because mb_chr( 0 ) does not produce a NUL
	 * byte on PHP 7.2.
	 *
	 * @return string|null The character(s), or NULL on NULL or invalid input.
	 */
	public function chr() {
		$args = func_get_args();
		if ( 0 === count( $args ) ) {
			return null;
		}
		$result = '';
		foreach ( $args as $arg ) {
			if ( null === $arg ) {
				return null;
			}
			if ( is_bool( $arg ) ) {
				$codepoint = (int) $arg;
			} elseif ( is_int( $arg ) ) {
				$codepoint = $arg;
			} elseif ( is_float( $arg ) ) {
				$codepoint = (int) $arg;
			} elseif ( is_string( $arg ) && is_numeric( $arg ) ) {
				$codepoint = (int) $arg;
			} else {
				return null;
			}
			if (
				$codepoint < 0
				|| $codepoint > 0x10FFFF
				|| ( $codepoint >= 0xD800 && $codepoint <= 0xDFFF )
			) {
				return null;
			}
			if ( 0 === $codepoint ) {
				// mb_chr( 0 ) does not return a NUL byte on PHP 7.2, so the
				// zero codepoint is encoded directly.
				$result .= "\0";
			} elseif ( function_exists( 'mb_chr' ) ) {
				try {
					$char = mb_chr( $codepoint, 'UTF-8' );
				} catch ( Throwable $e ) {
					return null;
				}
				if ( false === $char ) {
					return null;
				}
				$result .= $char;
			} elseif ( $codepoint < 256 ) {
				/*
				 * A raw chr() byte would be latin1, not UTF-8: CHR(200) must
				 * yield U+00C8 encoded as two bytes. The general encoder
				 * handles the whole BMP, so use it here too.
				 */
				$encoded = $this->_helper_utf8_encode_codepoint( $codepoint );
				if ( false === $encoded ) {
					return null;
				}
				$result .= $encoded;
			} else {
				$encoded = $this->_helper_utf8_encode_codepoint( $codepoint );
				if ( false === $encoded ) {
					return null;
				}
				$result .= $encoded;
			}
		}
		return $result;
	}

	/**
	 * Method to emulate MariaDB JSON_COMPACT() function.
	 *
	 * Removes all insignificant whitespace from a JSON document, preserving
	 * everything else byte-for-byte: key order, number formatting ('1.50'
	 * stays '1.50'), and string escapes. This matches MariaDB, which strips
	 * whitespace rather than re-serializing the document. Returns NULL when
	 * the argument is NULL or is not valid JSON. Never throws.
	 *
	 * @return string|null The compacted document, or NULL on NULL or invalid input.
	 */
	public function json_compact() {
		if ( 1 !== func_num_args() ) {
			return null;
		}
		$args = func_get_args();
		$doc  = $args[0];
		if ( null === $doc ) {
			return null;
		}
		$decoded = $this->_helper_json_decode_doc( $doc );
		if ( ! $decoded[0] ) {
			return null;
		}
		if ( is_bool( $doc ) ) {
			$doc = $doc ? 'true' : 'false';
		} elseif ( is_int( $doc ) || is_float( $doc ) ) {
			$doc = (string) $doc;
		}
		return $this->_helper_json_compact_text( $doc );
	}

	/**
	 * Method to emulate MariaDB JSON_DETAILED() function.
	 *
	 * Renders a JSON document in human-readable form: one value per line,
	 * objects and arrays expanded with newlines and indentation, scalar
	 * object values on the same line as their key, and container values on
	 * the line below their key, e.g.:
	 *
	 *     {
	 *         "A": 1,
	 *         "B":
	 *         [
	 *             2,
	 *             3
	 *         ]
	 *     }
	 *
	 * This matches the MariaDB documentation example, including the default
	 * tab size of 4 spaces. Differences from MariaDB, all by design:
	 * - No trailing space is emitted after the colon of a container value
	 *   ('"B":' instead of '"B": ').
	 * - Newer MariaDB versions (see MDEV-19160) keep short scalar-only
	 *   arrays on one line; this implementation always expands containers.
	 * - Numbers are re-serialized from PHP values, so '1.50' becomes '1.5';
	 *   whole floats print with '.0' ('1.0'); non-ASCII strings print
	 *   literally (never '\uXXXX'); '/' is never escaped.
	 * - The optional tab size must be a non-negative number (truncated
	 *   toward zero) not exceeding 64; anything else yields NULL, as does
	 *   an explicit NULL tab size. MariaDB raises an error instead.
	 *
	 * JSON_PRETTY() is deliberately not registered as an alias here. The
	 * name belongs to MySQL, which implements it with its own 2-space
	 * layout, and a separate change provides that implementation.
	 *
	 * @return string|null The detailed rendering, or NULL on NULL or invalid input.
	 */
	public function json_detailed() {
		$num_args = func_num_args();
		if ( 1 !== $num_args && 2 !== $num_args ) {
			return null;
		}
		$args = func_get_args();
		$doc  = $args[0];
		if ( null === $doc ) {
			return null;
		}
		if ( 2 === $num_args ) {
			$tab_size = $args[1];
			if ( null === $tab_size || is_bool( $tab_size ) || ! is_numeric( $tab_size ) ) {
				return null;
			}
			$tab_size = (int) $tab_size;
			if ( $tab_size < 0 || $tab_size > 64 ) {
				return null;
			}
		} else {
			$tab_size = 4;
		}
		list( $ok, $data ) = $this->_helper_json_decode_doc( $doc );
		if ( ! $ok ) {
			return null;
		}
		$rendered = $this->_helper_json_encode_detailed( $data, 0, $tab_size );
		if ( false === $rendered ) {
			return null;
		}
		return $rendered;
	}

	/**
	 * Method to emulate MariaDB JSON_NORMALIZE() function (MariaDB 10.7+).
	 *
	 * Recursively sorts object keys and removes insignificant whitespace,
	 * so equivalent documents compare equal as strings. Key order is byte
	 * order (PHP strcmp); duplicate keys keep the last value (PHP
	 * json_decode semantics). Numbers are re-serialized from PHP values, so
	 * '1.50' becomes '1.5' and whole floats print with '.0'; non-ASCII
	 * strings print literally and '/' is never escaped. Returns NULL when
	 * the argument is NULL or is not valid JSON. Never throws.
	 *
	 * @return string|null The normalized document, or NULL on NULL or invalid input.
	 */
	public function json_normalize() {
		if ( 1 !== func_num_args() ) {
			return null;
		}
		$args = func_get_args();
		$doc  = $args[0];
		if ( null === $doc ) {
			return null;
		}
		list( $ok, $data ) = $this->_helper_json_decode_doc( $doc );
		if ( ! $ok ) {
			return null;
		}
		$normalized = $this->_helper_json_normalize_value( $data );
		$encoded    = $this->_helper_json_encode_compact( $normalized );
		if ( false === $encoded ) {
			return null;
		}
		return $encoded;
	}

	/**
	 * Method to emulate MariaDB JSON_OBJECT_FILTER_KEYS() function
	 * (MariaDB 11.2+).
	 *
	 * Returns a JSON object containing only the top-level key/value pairs
	 * whose keys are listed, preserving the original key order. Two key
	 * forms are accepted:
	 * - The MariaDB form: exactly two arguments where the second is a JSON
	 *   array of keys, e.g. JSON_OBJECT_FILTER_KEYS(doc, '["a","c"]'). Only
	 *   string, integer, and float array elements are used as keys; anything
	 *   else in the array is ignored. (A single plain-text key that happens
	 *   to parse as a JSON array is therefore interpreted as the array form;
	 *   such key names are pathological and unsupported.)
	 * - A variadic convenience form: JSON_OBJECT_FILTER_KEYS(doc, 'a', 'c'),
	 *   where every argument after the document is used verbatim as a key.
	 *
	 * Keys not present in the document are ignored; when none match, the
	 * result is '{}'. Returns NULL when the document is NULL, when any key
	 * argument is NULL, when the document is not valid JSON, or when the
	 * document is not a JSON object (MariaDB has no defined result for
	 * non-objects either). Never throws.
	 *
	 * @return string|null The filtered object, or NULL on NULL or invalid input.
	 */
	public function json_object_filter_keys() {
		$num_args = func_num_args();
		if ( $num_args < 2 ) {
			return null;
		}
		$args = func_get_args();
		$doc  = $args[0];
		if ( null === $doc ) {
			return null;
		}
		$key_args = array_slice( $args, 1 );
		foreach ( $key_args as $key_arg ) {
			if ( null === $key_arg ) {
				return null;
			}
		}
		list( $ok, $data ) = $this->_helper_json_decode_doc( $doc );
		if ( ! $ok ) {
			return null;
		}
		if ( ! ( $data instanceof stdClass ) ) {
			return null;
		}
		if ( 1 === count( $key_args ) && is_string( $key_args[0] ) ) {
			$decoded_keys = json_decode( $key_args[0] );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded_keys ) ) {
				$keys = array();
				foreach ( $decoded_keys as $entry ) {
					if ( is_string( $entry ) ) {
						$keys[] = $entry;
					} elseif ( is_int( $entry ) || is_float( $entry ) ) {
						$keys[] = (string) $entry;
					}
				}
				return $this->_helper_json_filter_keys( $data, $keys );
			}
		}
		$keys = array();
		foreach ( $key_args as $key_arg ) {
			if ( is_string( $key_arg ) ) {
				$keys[] = $key_arg;
			} elseif ( is_int( $key_arg ) || is_float( $key_arg ) || is_bool( $key_arg ) ) {
				$keys[] = (string) $key_arg;
			} else {
				return null;
			}
		}
		return $this->_helper_json_filter_keys( $data, $keys );
	}

	/**
	 * Method to emulate MariaDB CRC32C() function (MariaDB 10.8+).
	 *
	 * Computes the CRC-32C (Castagnoli polynomial 0x1EDC6F41) checksum of a
	 * string as an unsigned 32-bit integer. The two-argument chained form
	 * CRC32C(previous_crc, str) continues a previous checksum, so
	 * CRC32C(CRC32C('Maria'), 'DB') equals CRC32C('MariaDB'). The previous
	 * checksum is masked to 32 bits; anything that is not an integer or a
	 * numeric string yields NULL. NULL input yields NULL. Never throws.
	 *
	 * The checksum is returned as an int when it fits in signed 32 bits, and
	 * as a float above that. PDO truncates integers returned from SQLite UDFs
	 * to 32-bit signed, which would corrupt the upper half of the range (e.g.
	 * CRC32C('123456789') is 0xE3069283 = 3808858755), so those values stay
	 * floats. Every 32-bit value is exactly representable as a float, so
	 * numeric comparisons, arithmetic, and integer-affinity storage all
	 * behave. Text rendering differs by PHP version for the float case: PDO
	 * stringifies a float result with SQLite's text conversion before PHP
	 * 8.1, which appends '.0' to integral values, so the upper half of the
	 * range renders as '3808858755.0' there instead of '3808858755'.
	 *
	 * Uses PHP's hash('crc32c') when the algorithm is available (checked via
	 * hash_algos() at runtime) and a pure-PHP table implementation
	 * otherwise; the chained form always uses the pure-PHP implementation
	 * with a custom initial value. Requires 64-bit PHP for correct unsigned
	 * arithmetic, like the RAND() implementation below.
	 *
	 * @return float|int|null The unsigned checksum, or NULL on NULL or invalid input.
	 */
	public function crc32c() {
		$num_args = func_num_args();
		if ( 1 !== $num_args && 2 !== $num_args ) {
			return null;
		}
		$args = func_get_args();
		if ( 1 === $num_args ) {
			$str = $args[0];
			if ( null === $str ) {
				return null;
			}
			if ( ! is_scalar( $str ) ) {
				return null;
			}
			$str = (string) $str;
			if (
				function_exists( 'hash' )
				&& function_exists( 'hash_algos' )
				&& in_array( 'crc32c', hash_algos(), true )
			) {
				return $this->_helper_crc32c_return( hexdec( hash( 'crc32c', $str ) ) );
			}
			return $this->_helper_crc32c_return( ( $this->_helper_crc32c_raw( $str, 0xFFFFFFFF ) ^ 0xFFFFFFFF ) & 0xFFFFFFFF );
		}
		$previous = $args[0];
		$str      = $args[1];
		if ( null === $previous || null === $str ) {
			return null;
		}
		if ( ! is_scalar( $str ) ) {
			return null;
		}
		$str = (string) $str;
		if ( is_bool( $previous ) || is_float( $previous ) ) {
			$previous = (int) $previous;
		} elseif ( is_string( $previous ) && is_numeric( $previous ) ) {
			$previous = (int) $previous;
		} elseif ( ! is_int( $previous ) ) {
			return null;
		}
		$previous = $previous & 0xFFFFFFFF;
		return $this->_helper_crc32c_return( ( $this->_helper_crc32c_raw( $str, ( $previous ^ 0xFFFFFFFF ) & 0xFFFFFFFF ) ^ 0xFFFFFFFF ) & 0xFFFFFFFF );
	}

	/**
	 * Normalise a computed CRC32C checksum for return from the UDF.
	 *
	 * A checksum that fits in signed 32 bits is returned as an int, which PDO
	 * passes through unchanged and which stringifies identically on every
	 * supported PHP version. Larger checksums are returned as a float because
	 * PDO truncates integer UDF results to 32-bit signed. The float still
	 * compares and computes numerically, but PDO stringifies it with SQLite's
	 * text conversion before PHP 8.1, which renders the upper half of the
	 * range as '3808858755.0' where an int renders '3808858755'.
	 *
	 * @param int|float $crc The computed checksum.
	 * @return int|float The checksum in the form the UDF should return.
	 */
	private function _helper_crc32c_return( $crc ) {
		if ( (float) $crc <= 0x7FFFFFFF ) {
			return (int) $crc;
		}
		return (float) $crc;
	}

	/**
	 * Decode a JSON document argument shared by the JSON UDFs.
	 *
	 * Scalars are coerced to their JSON text form (booleans to 'true' or
	 * 'false', numbers via string cast); anything else that is not a string
	 * is rejected. Objects decode to stdClass so that empty objects stay
	 * distinguishable from empty arrays and key order is preserved.
	 *
	 * @param mixed $doc The document argument.
	 * @return array Array of (bool $ok, mixed $data).
	 */
	private function _helper_json_decode_doc( $doc ) {
		if ( is_bool( $doc ) ) {
			$doc = $doc ? 'true' : 'false';
		} elseif ( is_int( $doc ) || is_float( $doc ) ) {
			$doc = (string) $doc;
		} elseif ( ! is_string( $doc ) ) {
			return array( false, null );
		}
		$data = json_decode( $doc );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return array( false, null );
		}
		return array( true, $data );
	}

	/**
	 * Evaluate a JSON path against a decoded document.
	 *
	 * See json_exists() for the supported path subset. Parsing continues
	 * past the first missing step so that the remainder of the path is still
	 * validated: an invalid path reports invalid even when an earlier step
	 * is already missing.
	 *
	 * @param mixed  $data The decoded document.
	 * @param mixed  $path The path argument.
	 * @return array Array of (bool $valid, bool $exists).
	 */
	private function _helper_json_path_lookup( $data, $path ) {
		if ( ! is_string( $path ) || '' === $path || '$' !== $path[0] ) {
			return array( false, false );
		}
		$len     = strlen( $path );
		$pos     = 1;
		$current = $data;
		$exists  = true;
		if ( 1 === $len ) {
			return array( true, true );
		}
		while ( $pos < $len ) {
			$char = $path[ $pos ];
			if ( '.' === $char ) {
				++$pos;
				if ( $pos < $len && ( '"' === $path[ $pos ] || "'" === $path[ $pos ] ) ) {
					list( $ok, $key, $pos ) = $this->_helper_json_path_quoted_key( $path, $pos );
					if ( ! $ok ) {
						return array( false, false );
					}
				} else {
					$start = $pos;
					while ( $pos < $len && '.' !== $path[ $pos ] && '[' !== $path[ $pos ] ) {
						++$pos;
					}
					$key = substr( $path, $start, $pos - $start );
					if ( '' === $key || 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $key ) ) {
						return array( false, false );
					}
				}
				if ( $exists ) {
					if ( $current instanceof stdClass && property_exists( $current, $key ) ) {
						$current = $current->$key;
					} else {
						$exists = false;
					}
				}
			} elseif ( '[' === $char ) {
				++$pos;
				if ( $pos < $len && ( '"' === $path[ $pos ] || "'" === $path[ $pos ] ) ) {
					list( $ok, $key, $pos ) = $this->_helper_json_path_quoted_key( $path, $pos );
					if ( ! $ok || $pos >= $len || ']' !== $path[ $pos ] ) {
						return array( false, false );
					}
					++$pos;
					if ( $exists ) {
						if ( $current instanceof stdClass && property_exists( $current, $key ) ) {
							$current = $current->$key;
						} else {
							$exists = false;
						}
					}
				} elseif ( $pos < $len && '*' === $path[ $pos ] ) {
					// Wildcards are out of scope; see json_exists().
					return array( false, false );
				} else {
					$start = $pos;
					while ( $pos < $len && ']' !== $path[ $pos ] ) {
						++$pos;
					}
					$inside = substr( $path, $start, $pos - $start );
					if ( $pos >= $len || 1 !== preg_match( '/^[0-9]+$/', $inside ) ) {
						return array( false, false );
					}
					++$pos;
					$index = (int) $inside;
					if ( $exists ) {
						if ( is_array( $current ) && array_key_exists( $index, $current ) ) {
							$current = $current[ $index ];
						} else {
							$exists = false;
						}
					}
				}
			} else {
				return array( false, false );
			}
		}
		return array( true, $exists );
	}

	/**
	 * Parse a quoted JSON path key starting at the opening quote.
	 *
	 * A backslash escapes the following character. The closing quote must
	 * be present; an unescaped closing quote ends the key.
	 *
	 * @param string $path The full path text.
	 * @param int    $pos  Position of the opening quote.
	 * @return array Array of (bool $ok, string $key, int $next_pos).
	 */
	private function _helper_json_path_quoted_key( $path, $pos ) {
		$len   = strlen( $path );
		$quote = $path[ $pos ];
		++$pos;
		$key = '';
		while ( $pos < $len ) {
			$char = $path[ $pos ];
			if ( '\\' === $char && $pos + 1 < $len ) {
				$key .= $path[ $pos + 1 ];
				$pos += 2;
				continue;
			}
			if ( $char === $quote ) {
				return array( true, $key, $pos + 1 );
			}
			$key .= $char;
			++$pos;
		}
		return array( false, '', $pos );
	}

	/**
	 * Strip insignificant whitespace from valid JSON text.
	 *
	 * Whitespace outside string literals is dropped; everything else,
	 * including whitespace inside strings, number formatting, key order,
	 * and escape sequences, is preserved byte-for-byte.
	 *
	 * @param string $text Valid JSON text.
	 * @return string The compacted text.
	 */
	private function _helper_json_compact_text( $text ) {
		$len       = strlen( $text );
		$out       = '';
		$in_string = false;
		$i         = 0;
		while ( $i < $len ) {
			$char = $text[ $i ];
			if ( $in_string ) {
				$out .= $char;
				if ( '\\' === $char && $i + 1 < $len ) {
					$out .= $text[ $i + 1 ];
					$i   += 2;
					continue;
				}
				if ( '"' === $char ) {
					$in_string = false;
				}
				++$i;
				continue;
			}
			if ( '"' === $char ) {
				$in_string = true;
				$out      .= $char;
				++$i;
				continue;
			}
			if ( ' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char ) {
				++$i;
				continue;
			}
			$out .= $char;
			++$i;
		}
		return $out;
	}

	/**
	 * Encode a scalar JSON value in canonical compact form.
	 *
	 * Whole floats print with '.0' so they stay valid JSON numbers that
	 * still read as floats ('1.0' instead of PHP's '1'); strings print with
	 * non-ASCII characters and '/' unescaped.
	 *
	 * @param mixed $value The scalar value.
	 * @return string|false The encoded scalar, or false on failure.
	 */
	private function _helper_json_encode_scalar( $value ) {
		if ( null === $value ) {
			return 'null';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			$encoded = json_encode( $value );
			if ( false === $encoded ) {
				return false;
			}
			if ( strlen( $encoded ) === strcspn( $encoded, '.eE' ) ) {
				$encoded .= '.0';
			}
			return $encoded;
		}
		if ( is_string( $value ) ) {
			return $this->_helper_json_encode_string( $value );
		}
		return false;
	}

	/**
	 * Encode a string as a JSON string literal.
	 *
	 * Non-ASCII characters and '/' are left unescaped, matching MariaDB output.
	 *
	 * @param string $str The string.
	 * @return string|false The quoted literal, or false on failure.
	 */
	private function _helper_json_encode_string( $str ) {
		$encoded = json_encode( $str, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $encoded ) {
			return false;
		}
		return $encoded;
	}

	/**
	 * Encode a decoded JSON value in compact form.
	 *
	 * Objects always encode as JSON objects, even when their keys look like
	 * a sequential integer range (PHP would encode those as arrays).
	 *
	 * @param mixed $value The decoded value.
	 * @return string|false The compact encoding, or false on failure.
	 */
	private function _helper_json_encode_compact( $value ) {
		if ( $value instanceof stdClass ) {
			$parts = array();
			foreach ( $value as $k => $v ) {
				$key     = $this->_helper_json_encode_string( (string) $k );
				$encoded = $this->_helper_json_encode_compact( $v );
				if ( false === $key || false === $encoded ) {
					return false;
				}
				$parts[] = $key . ':' . $encoded;
			}
			return '{' . implode( ',', $parts ) . '}';
		}
		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $v ) {
				$encoded = $this->_helper_json_encode_compact( $v );
				if ( false === $encoded ) {
					return false;
				}
				$parts[] = $encoded;
			}
			return '[' . implode( ',', $parts ) . ']';
		}
		return $this->_helper_json_encode_scalar( $value );
	}

	/**
	 * Encode a decoded JSON value in detailed (human-readable) form.
	 *
	 * See json_detailed() for the exact layout. Container values start on
	 * the line below their object key, at the key's own indent level.
	 *
	 * @param mixed $value The decoded value.
	 * @param int   $level The current indent level.
	 * @param int   $tab   Spaces per indent level.
	 * @return string|false The detailed rendering, or false on failure.
	 */
	private function _helper_json_encode_detailed( $value, $level, $tab ) {
		if ( $value instanceof stdClass ) {
			$vars = get_object_vars( $value );
			if ( 0 === count( $vars ) ) {
				return '{}';
			}
			$indent       = str_repeat( ' ', $tab * $level );
			$child_indent = str_repeat( ' ', $tab * ( $level + 1 ) );
			$out          = "{\n";
			$i            = 0;
			$n            = count( $vars );
			foreach ( $vars as $k => $v ) {
				++$i;
				$key = $this->_helper_json_encode_string( (string) $k );
				if ( false === $key ) {
					return false;
				}
				$out .= $child_indent . $key . ':';
				if ( $v instanceof stdClass || is_array( $v ) ) {
					$encoded = $this->_helper_json_encode_detailed( $v, $level + 1, $tab );
					if ( false === $encoded ) {
						return false;
					}
					$out .= "\n" . $child_indent . ltrim( $encoded );
				} else {
					$encoded = $this->_helper_json_encode_scalar( $v );
					if ( false === $encoded ) {
						return false;
					}
					$out .= ' ' . $encoded;
				}
				$out .= ( $i < $n ) ? ",\n" : "\n";
			}
			$out .= $indent . '}';
			return $out;
		}
		if ( is_array( $value ) ) {
			if ( 0 === count( $value ) ) {
				return '[]';
			}
			$indent       = str_repeat( ' ', $tab * $level );
			$child_indent = str_repeat( ' ', $tab * ( $level + 1 ) );
			$out          = "[\n";
			$i            = 0;
			$n            = count( $value );
			foreach ( $value as $v ) {
				++$i;
				if ( $v instanceof stdClass || is_array( $v ) ) {
					$encoded = $this->_helper_json_encode_detailed( $v, $level + 1, $tab );
					if ( false === $encoded ) {
						return false;
					}
					$out .= $child_indent . ltrim( $encoded );
				} else {
					$encoded = $this->_helper_json_encode_scalar( $v );
					if ( false === $encoded ) {
						return false;
					}
					$out .= $child_indent . $encoded;
				}
				$out .= ( $i < $n ) ? ",\n" : "\n";
			}
			$out .= $indent . ']';
			return $out;
		}
		return $this->_helper_json_encode_scalar( $value );
	}

	/**
	 * Recursively sort the keys of a decoded JSON value (in place by rebuild).
	 *
	 * Object keys sort in byte order; array order is preserved.
	 *
	 * @param mixed $value The decoded value.
	 * @return mixed The normalized value.
	 */
	private function _helper_json_normalize_value( $value ) {
		if ( $value instanceof stdClass ) {
			$vars = get_object_vars( $value );
			uksort( $vars, 'strcmp' );
			$sorted = new stdClass();
			foreach ( $vars as $k => $v ) {
				$prop          = (string) $k;
				$sorted->$prop = $this->_helper_json_normalize_value( $v );
			}
			return $sorted;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $i => $v ) {
				$value[ $i ] = $this->_helper_json_normalize_value( $v );
			}
			return $value;
		}
		return $value;
	}

	/**
	 * Keep only the listed top-level keys of a decoded JSON object.
	 *
	 * Key order of the original object is preserved.
	 *
	 * @param stdClass $data The decoded object.
	 * @param array    $keys The keys to keep.
	 * @return string|false The filtered object encoding, or false on failure.
	 */
	private function _helper_json_filter_keys( $data, $keys ) {
		$filtered = new stdClass();
		foreach ( $data as $k => $v ) {
			if ( in_array( (string) $k, $keys, true ) ) {
				$prop            = (string) $k;
				$filtered->$prop = $v;
			}
		}
		return $this->_helper_json_encode_compact( $filtered );
	}

	/**
	 * Encode a Unicode codepoint as UTF-8 without mbstring.
	 *
	 * @param int $codepoint The codepoint.
	 * @return string|false The UTF-8 bytes, or false when out of range.
	 */
	private function _helper_utf8_encode_codepoint( $codepoint ) {
		if ( $codepoint < 0 || $codepoint > 0x10FFFF ) {
			return false;
		}
		if ( $codepoint >= 0xD800 && $codepoint <= 0xDFFF ) {
			return false;
		}
		if ( $codepoint < 0x80 ) {
			return chr( $codepoint );
		}
		if ( $codepoint < 0x800 ) {
			return chr( 0xC0 | ( $codepoint >> 6 ) ) . chr( 0x80 | ( $codepoint & 0x3F ) );
		}
		if ( $codepoint < 0x10000 ) {
			return chr( 0xE0 | ( $codepoint >> 12 ) ) . chr( 0x80 | ( ( $codepoint >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $codepoint & 0x3F ) );
		}
		return chr( 0xF0 | ( $codepoint >> 18 ) ) . chr( 0x80 | ( ( $codepoint >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $codepoint >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $codepoint & 0x3F ) );
	}

	/**
	 * Raw CRC-32C (Castagnoli, reflected, polynomial 0x1EDC6F41) update.
	 *
	 * Computes the raw register value starting from $crc without the final
	 * XOR; callers apply (and, for chaining, pre-apply) the 0xFFFFFFFF XOR.
	 * Used directly for the chained CRC32C() form and as a fallback when
	 * PHP's hash() does not offer 'crc32c'.
	 *
	 * @param string $data The input bytes.
	 * @param int    $crc  The starting register value (32 bits).
	 * @return int The updated register value (32 bits).
	 */
	private function _helper_crc32c_raw( $data, $crc ) {
		if ( null === self::$crc32c_table ) {
			$table = array();
			for ( $n = 0; $n < 256; $n++ ) {
				$c = $n;
				for ( $k = 0; $k < 8; $k++ ) {
					if ( $c & 1 ) {
						$c = 0x82F63B78 ^ ( $c >> 1 );
					} else {
						$c = $c >> 1;
					}
				}
				$table[ $n ] = $c;
			}
			self::$crc32c_table = $table;
		}
		$len = strlen( $data );
		for ( $i = 0; $i < $len; $i++ ) {
			$crc = self::$crc32c_table[ ( $crc ^ ord( $data[ $i ] ) ) & 0xFF ] ^ ( ( $crc >> 8 ) & 0xFFFFFF );
		}
		return $crc & 0xFFFFFFFF;
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
