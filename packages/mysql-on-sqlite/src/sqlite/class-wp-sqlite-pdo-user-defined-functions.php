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
		'json_unquote'                 => 'json_unquote',
		'json_contains'                => 'json_contains',
		'json_length'                  => 'json_length',
		'json_merge_patch'             => 'json_merge_patch',

		// Internal helper functions.
		'_helper_json_unquote_extract' => 'json_unquote_extract',
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
	 * Method to emulate MySQL JSON_UNQUOTE() function.
	 *
	 * A value that starts and ends with a double quote is decoded as a JSON string.
	 * Any other value is returned unchanged, as MySQL does.
	 *
	 * SQLite's json_extract() already returns strings unquoted, whereas MySQL's
	 * JSON_EXTRACT() returns them quoted. So JSON_UNQUOTE( JSON_EXTRACT( ... ) )
	 * is translated to an internal helper that preserves extracted strings,
	 * including strings that begin and end with literal double quotes.
	 *
	 * @param string|null $json The JSON string literal to unquote.
	 *
	 * @throws Exception When the value looks like a JSON string but is not valid JSON.
	 * @return string|null The unquoted string, or NULL.
	 */
	public function json_unquote( $json ) {
		if ( null === $json ) {
			return null;
		}
		$json = (string) $json;
		if ( strlen( $json ) < 2 || '"' !== $json[0] || '"' !== substr( $json, -1 ) ) {
			return $json;
		}
		$decoded = json_decode( $json );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_string( $decoded ) ) {
			throw new Exception( 'Invalid JSON text in argument 1 to function json_unquote.' );
		}
		return $decoded;
	}

	/**
	 * Emulate JSON_UNQUOTE(JSON_EXTRACT(document, path)) without losing quotes
	 * that are part of an extracted JSON string.
	 *
	 * @param string|null $json The JSON document.
	 * @param string|null $path The JSON path to extract.
	 *
	 * @throws Exception When the document or path is invalid or unsupported.
	 * @return string|null The extracted value as text, or NULL when the path is absent.
	 */
	public function json_unquote_extract( $json, $path ) {
		if ( null === $json || null === $path ) {
			return null;
		}
		$document = $this->json_decode_argument( $json, 1, 'json_extract' );
		$result   = $this->json_find_path( $document, $path, 'json_extract' );
		if ( ! $result[0] ) {
			return null;
		}
		$value = $result[1];
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( null === $value ) {
			return 'null';
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
		}
		return (string) $value;
	}

	/**
	 * Method to emulate MySQL JSON_CONTAINS() function.
	 *
	 * @param string|null $target    The JSON document to search.
	 * @param string|null $candidate The JSON value to look for.
	 * @param string|null $path      Optional path to the part of the target to search.
	 *
	 * @throws Exception When an argument is not valid JSON or the path is not supported.
	 * @return int|null 1 if the candidate is contained, 0 if not, NULL if an argument is NULL
	 *                  or the path does not exist.
	 */
	public function json_contains( $target, $candidate, $path = null ) {
		if ( null === $target || null === $candidate || ( func_num_args() > 2 && null === $path ) ) {
			return null;
		}
		$target_value    = $this->json_decode_argument( $target, 1, 'json_contains' );
		$candidate_value = $this->json_decode_argument( $candidate, 2, 'json_contains' );

		if ( func_num_args() > 2 ) {
			$target_value = $this->json_find_path( $target_value, $path, 'json_contains' );
			if ( ! $target_value[0] ) {
				return null;
			}
			$target_value = $target_value[1];
		}
		return $this->json_value_contains( $target_value, $candidate_value ) ? 1 : 0;
	}

	/**
	 * Method to emulate MySQL JSON_LENGTH() function.
	 *
	 * @param string|null $json The JSON document.
	 * @param string|null $path Optional path to the part of the document to measure.
	 *
	 * @throws Exception When the document is not valid JSON or the path is not supported.
	 * @return int|null The number of elements of an array or object, 1 for a scalar,
	 *                  or NULL if an argument is NULL or the path does not exist.
	 */
	public function json_length( $json, $path = null ) {
		if ( null === $json || ( func_num_args() > 1 && null === $path ) ) {
			return null;
		}
		$value = $this->json_decode_argument( $json, 1, 'json_length' );

		if ( func_num_args() > 1 ) {
			$value = $this->json_find_path( $value, $path, 'json_length' );
			if ( ! $value[0] ) {
				return null;
			}
			$value = $value[1];
		}
		if ( is_array( $value ) ) {
			return count( $value );
		}
		if ( is_object( $value ) ) {
			return count( get_object_vars( $value ) );
		}
		return 1;
	}

	/**
	 * Method to emulate MySQL JSON_MERGE_PATCH() function (RFC 7396).
	 *
	 * @param string|null ...$documents Two or more JSON documents; later ones patch earlier ones.
	 *
	 * @throws Exception When an argument is not valid JSON.
	 * @return string|null The merged document, or NULL if any argument is NULL.
	 */
	public function json_merge_patch() {
		$documents = func_get_args();
		if ( count( $documents ) < 2 ) {
			throw new Exception( 'Incorrect parameter count in the call to native function \'json_merge_patch\'.' );
		}
		$result = null;
		foreach ( $documents as $index => $document ) {
			if ( null === $document ) {
				return null;
			}
			$value  = $this->json_decode_argument( $document, $index + 1, 'json_merge_patch' );
			$result = 0 === $index ? $value : $this->json_apply_patch( $result, $value );
		}
		return $this->json_encode_value( $result );
	}

	/**
	 * Decode a JSON function argument, failing the way MySQL does on invalid input.
	 *
	 * @param string $json     The JSON text.
	 * @param int    $position The 1-based argument position, for the error message.
	 * @param string $function_name The function name, for the error message.
	 *
	 * @throws Exception When the text is not valid JSON.
	 * @return mixed The decoded value.
	 */
	private function json_decode_argument( $json, int $position, string $function_name ) {
		$value = json_decode( (string) $json );
		if ( null === $value && JSON_ERROR_NONE !== json_last_error() ) {
			throw new Exception(
				sprintf( 'Invalid JSON text in argument %d to function %s.', $position, $function_name )
			);
		}
		return $value;
	}

	/**
	 * Whether a candidate JSON value is contained in a target value, per the MySQL rules:
	 * a candidate array needs each of its elements contained in some target element;
	 * a candidate object needs each key present with a contained value; a candidate scalar
	 * must equal the target scalar, or be contained in some element of a target array.
	 *
	 * @param mixed $target    The decoded target value.
	 * @param mixed $candidate The decoded candidate value.
	 *
	 * @return bool
	 */
	private function json_value_contains( $target, $candidate ): bool {
		if ( is_array( $target ) ) {
			if ( is_array( $candidate ) ) {
				foreach ( $candidate as $candidate_element ) {
					if ( ! $this->json_value_contains( $target, $candidate_element ) ) {
						return false;
					}
				}
				return true;
			}
			foreach ( $target as $target_element ) {
				if ( $this->json_value_contains( $target_element, $candidate ) ) {
					return true;
				}
			}
			return false;
		}
		if ( is_object( $target ) ) {
			if ( ! is_object( $candidate ) ) {
				return false;
			}
			$target_members = get_object_vars( $target );
			foreach ( get_object_vars( $candidate ) as $key => $candidate_member ) {
				if ( ! array_key_exists( $key, $target_members )
					|| ! $this->json_value_contains( $target_members[ $key ], $candidate_member ) ) {
					return false;
				}
			}
			return true;
		}
		if ( is_array( $candidate ) || is_object( $candidate ) ) {
			return false;
		}
		return $this->json_scalars_equal( $target, $candidate );
	}

	/**
	 * Compare two JSON scalars. Numbers compare by value, and a number never equals a
	 * string or a boolean.
	 *
	 * @param mixed $a A decoded scalar.
	 * @param mixed $b A decoded scalar.
	 *
	 * @return bool
	 */
	private function json_scalars_equal( $a, $b ): bool {
		if ( is_bool( $a ) || is_bool( $b ) || is_string( $a ) || is_string( $b ) ) {
			return $a === $b;
		}
		if ( null === $a || null === $b ) {
			return $a === $b;
		}
		return $a == $b; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- int and float compare by value.
	}

	/**
	 * Apply an RFC 7396 merge patch to a decoded value.
	 *
	 * @param mixed $target The decoded target.
	 * @param mixed $patch  The decoded patch.
	 *
	 * @return mixed The patched value.
	 */
	private function json_apply_patch( $target, $patch ) {
		if ( ! is_object( $patch ) ) {
			return $patch;
		}
		$members = is_object( $target ) ? get_object_vars( $target ) : array();
		foreach ( get_object_vars( $patch ) as $key => $patch_member ) {
			if ( null === $patch_member ) {
				unset( $members[ $key ] );
			} else {
				$members[ $key ] = $this->json_apply_patch(
					array_key_exists( $key, $members ) ? $members[ $key ] : null,
					$patch_member
				);
			}
		}
		return (object) $members;
	}

	/**
	 * Encode a decoded value in MySQL's canonical JSON form: ", " and ": " separators,
	 * object keys ordered by length and then by bytes, no escaping of Unicode or slashes.
	 *
	 * @param mixed $value The decoded value.
	 *
	 * @return string The JSON text.
	 */
	private function json_encode_value( $value ): string {
		if ( is_array( $value ) ) {
			$items = array();
			foreach ( $value as $element ) {
				$items[] = $this->json_encode_value( $element );
			}
			return '[' . implode( ', ', $items ) . ']';
		}
		if ( is_object( $value ) ) {
			$members = array();
			foreach ( get_object_vars( $value ) as $key => $member ) {
				$members[ (string) $key ] = $member;
			}
			uksort(
				$members,
				function ( $a, $b ) {
					$by_length = strlen( $a ) - strlen( $b );
					return 0 !== $by_length ? $by_length : strcmp( $a, $b );
				}
			);
			$items = array();
			foreach ( $members as $key => $member ) {
				$items[] = $this->json_encode_value( (string) $key ) . ': ' . $this->json_encode_value( $member );
			}
			return '{' . implode( ', ', $items ) . '}';
		}
		return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
	}

	/**
	 * Resolve a JSON path against a decoded document.
	 *
	 * Supports "$", ".key", ".\"quoted key\"", "[n]", "[last]" and "[last-n]". As in MySQL,
	 * an array index applied to a non-array treats the value as a one-element array.
	 * Wildcards ("*", "**", "[*]") are not supported and raise an error rather than
	 * silently returning a wrong answer.
	 *
	 * @param mixed  $document The decoded document.
	 * @param string $path     The path expression.
	 * @param string $function_name The function name, for error messages.
	 *
	 * @throws Exception When the path is invalid or uses an unsupported wildcard.
	 * @return array{0: bool, 1: mixed} Whether the path exists, and the value found.
	 */
	private function json_find_path( $document, $path, string $function_name ): array {
		$path   = (string) $path;
		$length = strlen( $path );
		if ( 0 === $length || '$' !== $path[0] ) {
			throw new Exception( sprintf( 'Invalid JSON path expression in function %s.', $function_name ) );
		}
		$current  = $document;
		$position = 1;
		while ( $position < $length ) {
			$char = $path[ $position ];
			if ( '.' === $char ) {
				++$position;
				if ( $position < $length && '"' === $path[ $position ] ) {
					if ( ! preg_match( '/\G"((?:[^"\\\\]|\\\\.)*)"/', $path, $match, 0, $position ) ) {
						throw new Exception( sprintf( 'Invalid JSON path expression in function %s.', $function_name ) );
					}
					$key       = json_decode( '"' . $match[1] . '"' );
					$position += strlen( $match[0] );
				} elseif ( preg_match( '/\G[A-Za-z_$][A-Za-z0-9_$]*/', $path, $match, 0, $position ) ) {
					$key       = $match[0];
					$position += strlen( $match[0] );
				} elseif ( $position < $length && '*' === $path[ $position ] ) {
					throw new Exception( sprintf( 'Wildcards in a JSON path are not supported in function %s.', $function_name ) );
				} else {
					throw new Exception( sprintf( 'Invalid JSON path expression in function %s.', $function_name ) );
				}
				if ( ! is_object( $current ) ) {
					return array( false, null );
				}
				$members = get_object_vars( $current );
				if ( ! array_key_exists( $key, $members ) ) {
					return array( false, null );
				}
				$current = $members[ $key ];
			} elseif ( '[' === $char ) {
				if ( ! preg_match( '/\G\[\s*(?:(\d+)|last(?:\s*-\s*(\d+))?)\s*\]/', $path, $match, 0, $position ) ) {
					if ( preg_match( '/\G\[\s*\*\s*\]/', $path, $wildcard, 0, $position ) ) {
						throw new Exception( sprintf( 'Wildcards in a JSON path are not supported in function %s.', $function_name ) );
					}
					throw new Exception( sprintf( 'Invalid JSON path expression in function %s.', $function_name ) );
				}
				$position += strlen( $match[0] );
				$elements  = is_array( $current ) ? $current : array( $current );
				if ( isset( $match[1] ) && '' !== $match[1] ) {
					$index = (int) $match[1];
				} else {
					// "last" and "last-N": the groups after it are absent when not matched.
					$index = count( $elements ) - 1 - ( isset( $match[2] ) ? (int) $match[2] : 0 );
				}
				if ( $index < 0 || $index >= count( $elements ) ) {
					return array( false, null );
				}
				$current = $elements[ $index ];
			} elseif ( '*' === $char ) {
				throw new Exception( sprintf( 'Wildcards in a JSON path are not supported in function %s.', $function_name ) );
			} else {
				throw new Exception( sprintf( 'Invalid JSON path expression in function %s.', $function_name ) );
			}
		}
		return array( true, $current );
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
