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
		'throw'                         => 'throw',
		'month'                         => 'month',
		'monthnum'                      => 'month',
		'year'                          => 'year',
		'day'                           => 'day',
		'hour'                          => 'hour',
		'minute'                        => 'minute',
		'second'                        => 'second',
		'week'                          => 'week',
		'weekday'                       => 'weekday',
		'dayofweek'                     => 'dayofweek',
		'dayofmonth'                    => 'dayofmonth',
		'unix_timestamp'                => 'unix_timestamp',
		'now'                           => 'now',
		'md5'                           => 'md5',
		'curdate'                       => 'curdate',
		'rand'                          => 'rand',
		'from_unixtime'                 => 'from_unixtime',
		'localtime'                     => 'now',
		'localtimestamp'                => 'now',
		'isnull'                        => 'isnull',
		'regexp'                        => 'regexp',
		'field'                         => 'field',
		'log'                           => 'log',
		'least'                         => 'least',
		'greatest'                      => 'greatest',
		'get_lock'                      => 'get_lock',
		'release_lock'                  => 'release_lock',
		'ucase'                         => 'ucase',
		'lcase'                         => 'lcase',
		'unhex'                         => 'unhex',
		'from_base64'                   => 'from_base64',
		'to_base64'                     => 'to_base64',
		'inet_ntoa'                     => 'inet_ntoa',
		'inet_aton'                     => 'inet_aton',
		'datediff'                      => 'datediff',
		'locate'                        => 'locate',
		'utc_date'                      => 'utc_date',
		'utc_time'                      => 'utc_time',
		'utc_timestamp'                 => 'utc_timestamp',
		'version'                       => 'version',
		'reverse'                       => 'reverse',
		'json_array_append'             => 'json_array_append',
		'json_array_insert'             => 'json_array_insert',
		'json_contains_path'            => 'json_contains_path',
		'json_depth'                    => 'json_depth',
		'json_keys'                     => 'json_keys',
		'json_merge'                    => 'json_merge',
		'json_overlaps'                 => 'json_overlaps',
		'json_pretty'                   => 'json_pretty',
		'json_schema_valid'             => 'json_schema_valid',
		'json_schema_validation_report' => 'json_schema_validation_report',
		'json_search'                   => 'json_search',
		'json_storage_free'             => 'json_storage_free',
		'json_storage_size'             => 'json_storage_size',
		'json_value'                    => 'json_value',

		// Internal helper functions.
		'_helper_like_to_glob_pattern'  => '_helper_like_to_glob_pattern',
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
	 * Decode a JSON document argument into a PHP value.
	 *
	 * JSON objects decode to stdClass instances so that empty objects
	 * remain distinguishable from empty arrays. JSON scalars passed as
	 * native PHP values (e.g. a SQL number used as a JSON document)
	 * are accepted as-is.
	 *
	 * @param mixed $doc   The UDF argument.
	 * @param mixed $value Set to the decoded value on success.
	 * @return bool True when $doc is valid JSON.
	 */
	private function json_decode_document( $doc, &$value ) {
		if ( is_int( $doc ) || is_float( $doc ) || is_bool( $doc ) ) {
			$value = $doc;
			return true;
		}
		if ( ! is_string( $doc ) ) {
			return false;
		}
		$value = json_decode( $doc );
		return JSON_ERROR_NONE === json_last_error();
	}

	/**
	 * Encode a PHP value as compact JSON text.
	 *
	 * @param mixed $value The value to encode.
	 * @return string|null The JSON text, or NULL when it cannot be encoded.
	 */
	private function json_encode_compact( $value ) {
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return null;
		}
		return $json;
	}

	/**
	 * Parse a MySQL JSON path expression into a list of segments.
	 *
	 * Supports "$", ".member", '."quoted member"', "$['quoted']",
	 * '$["quoted"]', "[N]", and "[*]". Each segment is an array whose
	 * first element is one of "member", "index", or "wildcard".
	 *
	 * Wildcards are parsed but left for the caller to reject: wildcard
	 * traversal is out of scope and documented on each function.
	 *
	 * @param mixed $path     The path expression.
	 * @param array $segments Set to the parsed segments on success.
	 * @return bool True when $path is a syntactically valid path.
	 */
	private function json_parse_path( $path, &$segments ) {
		$segments = array();
		if ( ! is_string( $path ) ) {
			return false;
		}
		$length = strlen( $path );
		if ( 0 === $length || '$' !== $path[0] ) {
			return false;
		}
		$pos = 1;
		while ( $pos < $length ) {
			if ( '.' === $path[ $pos ] ) {
				++$pos;
				if ( $pos >= $length ) {
					return false;
				}
				if ( '"' === $path[ $pos ] ) {
					$name   = '';
					$closed = false;
					++$pos;
					while ( $pos < $length ) {
						$char = $path[ $pos ];
						if ( '\\' === $char && $pos + 1 < $length ) {
							$name .= $path[ $pos + 1 ];
							$pos  += 2;
							continue;
						}
						if ( '"' === $char ) {
							$closed = true;
							++$pos;
							break;
						}
						$name .= $char;
						++$pos;
					}
					if ( ! $closed ) {
						return false;
					}
					$segments[] = array( 'member', $name );
				} else {
					$start = $pos;
					while ( $pos < $length && 1 === preg_match( '/[A-Za-z0-9_$]/', $path[ $pos ] ) ) {
						++$pos;
					}
					if ( $pos === $start ) {
						return false;
					}
					$segments[] = array( 'member', substr( $path, $start, $pos - $start ) );
				}
			} elseif ( '[' === $path[ $pos ] ) {
				$close = strpos( $path, ']', $pos );
				if ( false === $close ) {
					return false;
				}
				$inside = substr( $path, $pos + 1, $close - $pos - 1 );
				if ( '*' === $inside ) {
					$segments[] = array( 'wildcard' );
				} elseif ( 1 === preg_match( '/^\d+$/', $inside ) ) {
					$segments[] = array( 'index', (int) $inside );
				} elseif (
					strlen( $inside ) >= 2 &&
					( '"' === $inside[0] || '\'' === $inside[0] ) &&
					substr( $inside, -1 ) === $inside[0]
				) {
					$quote = $inside[0];
					$inner = substr( $inside, 1, -1 );
					$name  = '';
					$valid = true;
					$j     = 0;
					$len   = strlen( $inner );
					while ( $j < $len ) {
						if ( '\\' === $inner[ $j ] && $j + 1 < $len ) {
							$name .= $inner[ $j + 1 ];
							$j    += 2;
							continue;
						}
						if ( $quote === $inner[ $j ] ) {
							$valid = false;
							break;
						}
						$name .= $inner[ $j ];
						++$j;
					}
					if ( ! $valid ) {
						return false;
					}
					$segments[] = array( 'member', $name );
				} else {
					return false;
				}
				$pos = $close + 1;
			} else {
				return false;
			}
		}
		return true;
	}

	/**
	 * Check whether parsed path segments contain a wildcard.
	 *
	 * @param array $segments Parsed path segments.
	 * @return bool True when any segment is a wildcard.
	 */
	private function json_path_has_wildcard( $segments ) {
		foreach ( $segments as $segment ) {
			if ( 'wildcard' === $segment[0] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Render parsed path segments in canonical "$.a[0]" form.
	 *
	 * @param array $segments Parsed path segments.
	 * @return string The canonical path.
	 */
	private function json_canonical_path( $segments ) {
		$path = '$';
		foreach ( $segments as $segment ) {
			if ( 'index' === $segment[0] ) {
				$path .= '[' . $segment[1] . ']';
			} elseif ( 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $segment[1] ) ) {
				$path .= '.' . $segment[1];
			} else {
				$path .= '."'
					. str_replace(
						array( '\\', '"' ),
						array( '\\\\', '\\"' ),
						$segment[1]
					)
					. '"';
			}
		}
		return $path;
	}

	/**
	 * Fetch the value at a parsed path within a decoded JSON value.
	 *
	 * @param mixed $value    The decoded JSON value.
	 * @param array $segments Parsed path segments (no wildcards).
	 * @param bool  $found    Set to true when the path exists.
	 * @return mixed The selected value, or NULL when it does not exist.
	 */
	private function json_path_get( $value, $segments, &$found ) {
		$found = true;
		$node  = $value;
		foreach ( $segments as $segment ) {
			if ( 'member' === $segment[0] ) {
				if ( ! ( $node instanceof stdClass ) || ! property_exists( $node, $segment[1] ) ) {
					$found = false;
					return null;
				}
				$node = $node->{$segment[1]};
			} elseif ( 'index' === $segment[0] ) {
				if ( ! is_array( $node ) || ! array_key_exists( $segment[1], $node ) ) {
					$found = false;
					return null;
				}
				$node = $node[ $segment[1] ];
			} else {
				$found = false;
				return null;
			}
		}
		return $node;
	}

	/**
	 * Compute the maximum depth of a decoded JSON value.
	 *
	 * An empty array, an empty object, or a scalar all have depth 1.
	 * Otherwise the depth is one plus the depth of the deepest child.
	 *
	 * @param mixed $value The decoded JSON value.
	 * @return int The depth.
	 */
	private function json_value_depth( $value ) {
		if ( $value instanceof stdClass ) {
			$vars = get_object_vars( $value );
			if ( 0 === count( $vars ) ) {
				return 1;
			}
			$max = 1;
			foreach ( $vars as $child ) {
				$child_depth = $this->json_value_depth( $child ) + 1;
				if ( $child_depth > $max ) {
					$max = $child_depth;
				}
			}
			return $max;
		}
		if ( is_array( $value ) ) {
			if ( 0 === count( $value ) ) {
				return 1;
			}
			$max = 1;
			foreach ( $value as $child ) {
				$child_depth = $this->json_value_depth( $child ) + 1;
				if ( $child_depth > $max ) {
					$max = $child_depth;
				}
			}
			return $max;
		}
		return 1;
	}

	/**
	 * Compare two JSON scalars for equality.
	 *
	 * Numbers compare across int and float, strings compare byte-wise,
	 * and values of different JSON types are never equal.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 * @return bool True when the scalars are equal.
	 */
	private function json_scalars_equal( $a, $b ) {
		if ( null === $a || null === $b ) {
			return null === $a && null === $b;
		}
		if ( is_bool( $a ) || is_bool( $b ) ) {
			return is_bool( $a ) && is_bool( $b ) && $a === $b;
		}
		if ( ( is_int( $a ) || is_float( $a ) ) && ( is_int( $b ) || is_float( $b ) ) ) {
			if ( is_int( $a ) && is_int( $b ) ) {
				return $a === $b;
			}
			return (float) $a === (float) $b;
		}
		if ( is_string( $a ) && is_string( $b ) ) {
			return $a === $b;
		}
		return false;
	}

	/**
	 * Compare two decoded JSON values for deep equality.
	 *
	 * Object key order is insignificant, array element order matters,
	 * and numbers compare across int and float.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 * @return bool True when the values are equal.
	 */
	private function json_values_equal( $a, $b ) {
		if ( $a instanceof stdClass && $b instanceof stdClass ) {
			$vars_a = get_object_vars( $a );
			$vars_b = get_object_vars( $b );
			if ( count( $vars_a ) !== count( $vars_b ) ) {
				return false;
			}
			foreach ( $vars_a as $key => $child_a ) {
				if ( ! array_key_exists( $key, $vars_b ) ) {
					return false;
				}
				if ( ! $this->json_values_equal( $child_a, $vars_b[ $key ] ) ) {
					return false;
				}
			}
			return true;
		}
		if ( is_array( $a ) && is_array( $b ) ) {
			if ( count( $a ) !== count( $b ) ) {
				return false;
			}
			foreach ( $a as $index => $child_a ) {
				if ( ! array_key_exists( $index, $b ) ) {
					return false;
				}
				if ( ! $this->json_values_equal( $child_a, $b[ $index ] ) ) {
					return false;
				}
			}
			return true;
		}
		if ( $a instanceof stdClass || $b instanceof stdClass || is_array( $a ) || is_array( $b ) ) {
			return false;
		}
		return $this->json_scalars_equal( $a, $b );
	}

	/**
	 * Check whether two decoded JSON values overlap, per MySQL rules.
	 *
	 * Top-level arrays share an element when a deep-equality comparison
	 * matches one of their elements, and a top-level non-array value is
	 * treated as a one-element array. Top-level objects share a
	 * key-value pair when the member values are deeply equal. Below the
	 * top level no further overlap recursion happens: array elements
	 * and object member values are compared for equality only.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 * @return bool True when the values overlap.
	 */
	private function json_values_overlap( $a, $b ) {
		if ( ! is_array( $a ) && is_array( $b ) ) {
			$swap = $a;
			$a    = $b;
			$b    = $swap;
		}
		if ( is_array( $a ) ) {
			$list_b = is_array( $b ) ? $b : array( $b );
			foreach ( $list_b as $child_b ) {
				foreach ( $a as $child_a ) {
					if ( $this->json_values_equal( $child_a, $child_b ) ) {
						return true;
					}
				}
			}
			return false;
		}
		if ( $a instanceof stdClass && $b instanceof stdClass ) {
			$vars_b = get_object_vars( $b );
			foreach ( get_object_vars( $a ) as $key => $child_a ) {
				if (
					array_key_exists( $key, $vars_b ) &&
					$this->json_values_equal( $child_a, $vars_b[ $key ] )
				) {
					return true;
				}
			}
			return false;
		}
		if ( $a instanceof stdClass || $b instanceof stdClass ) {
			return false;
		}
		return $this->json_scalars_equal( $a, $b );
	}

	/**
	 * Render a decoded JSON value in MySQL JSON_PRETTY() format.
	 *
	 * Objects and arrays expand one value per line with two-space
	 * indentation, while empty objects and arrays stay inline.
	 *
	 * @param mixed $value  The decoded JSON value.
	 * @param int   $indent The current indentation level.
	 * @return string The pretty-printed JSON.
	 */
	private function json_pretty_print( $value, $indent ) {
		if ( $value instanceof stdClass ) {
			$vars = get_object_vars( $value );
			if ( 0 === count( $vars ) ) {
				return '{}';
			}
			$pad   = str_repeat( '  ', $indent + 1 );
			$lines = array();
			foreach ( $vars as $key => $child ) {
				$lines[] = $pad
					. json_encode( (string) $key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
					. ': ' . $this->json_pretty_print( $child, $indent + 1 );
			}
			return "{\n" . implode( ",\n", $lines ) . "\n" . str_repeat( '  ', $indent ) . '}';
		}
		if ( is_array( $value ) ) {
			if ( 0 === count( $value ) ) {
				return '[]';
			}
			$pad   = str_repeat( '  ', $indent + 1 );
			$lines = array();
			foreach ( $value as $child ) {
				$lines[] = $pad . $this->json_pretty_print( $child, $indent + 1 );
			}
			return "[\n" . implode( ",\n", $lines ) . "\n" . str_repeat( '  ', $indent ) . ']';
		}
		$scalar = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $scalar ) {
			return 'null';
		}
		return $scalar;
	}

	/**
	 * Build a regular expression from a JSON_SEARCH() LIKE pattern.
	 *
	 * "%" matches any sequence, "_" matches a single character, and
	 * the escape character quotes the next character literally.
	 * Matching is case-sensitive, like MySQL JSON document comparison.
	 *
	 * @param string $pattern The search string.
	 * @param string $escape  The escape character, or '' for none.
	 * @return string The regular expression.
	 */
	private function json_like_to_regex( $pattern, $escape ) {
		$regex  = '';
		$length = strlen( $pattern );
		for ( $i = 0; $i < $length; ++$i ) {
			$char = $pattern[ $i ];
			if ( '' !== $escape && $char === $escape && $i + 1 < $length ) {
				$regex .= preg_quote( $pattern[ $i + 1 ], '/' );
				++$i;
				continue;
			}
			if ( '%' === $char ) {
				$regex .= '.*';
			} elseif ( '_' === $char ) {
				$regex .= '.';
			} else {
				$regex .= preg_quote( $char, '/' );
			}
		}
		return '/^' . $regex . '$/su';
	}

	/**
	 * Collect paths of string values matching a JSON_SEARCH() pattern.
	 *
	 * Only JSON strings are matched; numbers, booleans, and null never
	 * match. Traversal is depth-first in document order.
	 *
	 * @param mixed $node     The decoded JSON value.
	 * @param array $segments Path segments leading to $node.
	 * @param string $regex   The search pattern as a regular expression.
	 * @param string $mode    "one" stops after the first match.
	 * @param array  $matches Collected canonical paths.
	 * @return bool True when mode is "one" and a match was found.
	 */
	private function json_search_values( $node, $segments, $regex, $mode, &$matches ) {
		if ( is_string( $node ) ) {
			if ( 1 === preg_match( $regex, $node ) ) {
				$matches[] = $this->json_canonical_path( $segments );
				if ( 'one' === $mode ) {
					return true;
				}
			}
			return false;
		}
		if ( $node instanceof stdClass ) {
			foreach ( get_object_vars( $node ) as $key => $child ) {
				$segments[] = array( 'member', (string) $key );
				$stop       = $this->json_search_values( $child, $segments, $regex, $mode, $matches );
				array_pop( $segments );
				if ( $stop ) {
					return true;
				}
			}
			return false;
		}
		if ( is_array( $node ) ) {
			foreach ( $node as $index => $child ) {
				$segments[] = array( 'index', $index );
				$stop       = $this->json_search_values( $child, $segments, $regex, $mode, $matches );
				array_pop( $segments );
				if ( $stop ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Interpret a JSON modification function argument as a JSON value.
	 *
	 * Like MySQL, a string argument that parses as JSON is stored as
	 * the parsed value (so '2' becomes the number 2 and '{"a": 1}'
	 * becomes an object), while any other string stays a JSON string.
	 *
	 * @param mixed $val The UDF argument.
	 * @return mixed The JSON value to store.
	 */
	private function json_parse_candidate( $val ) {
		if ( null === $val || is_int( $val ) || is_float( $val ) || is_bool( $val ) ) {
			return $val;
		}
		if ( ! is_string( $val ) ) {
			return null;
		}
		$decoded = json_decode( $val );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			return $decoded;
		}
		return $val;
	}

	/**
	 * Append a value at a parsed path, per JSON_ARRAY_APPEND() rules.
	 *
	 * Appends to arrays and autowraps existing scalars and objects in a
	 * new array. Pairs whose path does not identify an existing value
	 * are ignored like MySQL, so a missing object key is never created.
	 *
	 * @param mixed $node     The (sub)document to modify, by reference.
	 * @param array $segments Remaining path segments (no wildcards).
	 * @param mixed $val      The decoded value to append.
	 * @return bool True when the document was modified.
	 */
	private function json_append_value( &$node, $segments, $val ) {
		if ( 0 === count( $segments ) ) {
			if ( is_array( $node ) ) {
				$node[] = $val;
			} else {
				$node = array( $node, $val );
			}
			return true;
		}
		$leg  = $segments[0];
		$rest = array_slice( $segments, 1 );
		if ( 'member' === $leg[0] ) {
			if ( ! ( $node instanceof stdClass ) || ! property_exists( $node, $leg[1] ) ) {
				return false;
			}
			return $this->json_append_value( $node->{$leg[1]}, $rest, $val );
		}
		if ( 'index' === $leg[0] ) {
			if ( ! is_array( $node ) || ! array_key_exists( $leg[1], $node ) ) {
				return false;
			}
			return $this->json_append_value( $node[ $leg[1] ], $rest, $val );
		}
		return false;
	}

	/**
	 * Insert a value at a parsed path, per JSON_ARRAY_INSERT() rules.
	 *
	 * The path must end in an array index; the value is inserted before
	 * that index, or appended when the index is past the end. Paths
	 * that do not resolve to an existing array are ignored like MySQL.
	 *
	 * @param mixed $node     The (sub)document to modify, by reference.
	 * @param array $segments Remaining path segments (no wildcards).
	 * @param mixed $val      The decoded value to insert.
	 * @return bool True when the document was modified.
	 */
	private function json_insert_value( &$node, $segments, $val ) {
		$count = count( $segments );
		if ( 0 === $count ) {
			return false;
		}
		$leg  = $segments[0];
		$last = 1 === $count;
		if ( 'member' === $leg[0] ) {
			if ( ! ( $node instanceof stdClass ) || ! property_exists( $node, $leg[1] ) || $last ) {
				return false;
			}
			return $this->json_insert_value( $node->{$leg[1]}, array_slice( $segments, 1 ), $val );
		}
		if ( 'index' === $leg[0] ) {
			if ( ! is_array( $node ) ) {
				return false;
			}
			if ( ! $last ) {
				if ( ! array_key_exists( $leg[1], $node ) ) {
					return false;
				}
				return $this->json_insert_value( $node[ $leg[1] ], array_slice( $segments, 1 ), $val );
			}
			$index = $leg[1];
			if ( $index > count( $node ) ) {
				$index = count( $node );
			}
			array_splice( $node, $index, 0, array( $val ) );
			return true;
		}
		return false;
	}

	/**
	 * Merge two decoded JSON values, per JSON_MERGE_PRESERVE() rules.
	 *
	 * Two objects merge key by key (shared keys merge recursively),
	 * two arrays concatenate, and a non-array operand is autowrapped
	 * as a one-element array before concatenating. Used by the
	 * deprecated JSON_MERGE() alias.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 * @return mixed The merged value.
	 */
	private function json_merge_preserve_values( $a, $b ) {
		if ( $a instanceof stdClass && $b instanceof stdClass ) {
			$merged = clone $a;
			foreach ( get_object_vars( $b ) as $key => $child_b ) {
				if ( property_exists( $merged, $key ) ) {
					$merged->{$key} = $this->json_merge_preserve_values( $merged->{$key}, $child_b );
				} else {
					$merged->{$key} = $child_b;
				}
			}
			return $merged;
		}
		$list_a = is_array( $a ) ? $a : array( $a );
		$list_b = is_array( $b ) ? $b : array( $b );
		return array_merge( $list_a, $list_b );
	}

	/**
	 * Check whether a decoded value matches a JSON Schema type name.
	 *
	 * An "integer" is any number with a zero fractional part, so 1.0
	 * matches both "integer" and "number".
	 *
	 * @param mixed $type  The type name.
	 * @param mixed $value The decoded JSON value.
	 * @return bool True when the value has the named type.
	 */
	private function json_schema_type_matches( $type, $value ) {
		if ( ! is_string( $type ) ) {
			return false;
		}
		switch ( $type ) {
			case 'null':
				return null === $value;
			case 'boolean':
				return is_bool( $value );
			case 'object':
				return $value instanceof stdClass;
			case 'array':
				return is_array( $value );
			case 'string':
				return is_string( $value );
			case 'number':
				return is_int( $value ) || is_float( $value );
			case 'integer':
				return is_int( $value ) || (
					is_float( $value ) &&
					is_finite( $value ) &&
					floor( $value ) === $value
				);
			default:
				return false;
		}
	}

	/**
	 * Validate a decoded value against a decoded JSON Schema.
	 *
	 * Implements the documented subset: "type" (a name or an array of
	 * names), "properties", "required", "items" (a schema, or an array
	 * of positional schemas with extra elements allowed), and "enum".
	 * Unknown keywords are ignored per the JSON Schema specification.
	 * Keywords like "$ref", "patternProperties", "allOf", and remote
	 * schemas are not supported and silently ignored.
	 *
	 * @param mixed $schema   The decoded schema (must be an object).
	 * @param mixed $value    The decoded value to validate.
	 * @param array $segments Path segments leading to $value, for messages.
	 * @param string $reason  Set to a description when validation fails.
	 * @return bool True when the value is valid.
	 */
	private function json_schema_validate( $schema, $value, $segments, &$reason ) {
		$location = $this->json_canonical_path( $segments );
		if ( ! ( $schema instanceof stdClass ) ) {
			$reason = sprintf( 'The schema at %s is not an object.', $location );
			return false;
		}
		if ( property_exists( $schema, 'type' ) ) {
			$types = $schema->type;
			if ( ! is_array( $types ) ) {
				$types = array( $types );
			}
			$matched = false;
			foreach ( $types as $type ) {
				if ( $this->json_schema_type_matches( $type, $value ) ) {
					$matched = true;
					break;
				}
			}
			if ( ! $matched ) {
				$reason = sprintf( 'The value at %s does not match the required type.', $location );
				return false;
			}
		}
		if ( $value instanceof stdClass ) {
			if ( property_exists( $schema, 'required' ) && is_array( $schema->required ) ) {
				foreach ( $schema->required as $required ) {
					if ( ! is_string( $required ) ) {
						continue;
					}
					if ( ! property_exists( $value, $required ) ) {
						$reason = sprintf(
							'The object at %s is missing required property "%s".',
							$location,
							$required
						);
						return false;
					}
				}
			}
			if ( property_exists( $schema, 'properties' ) && $schema->properties instanceof stdClass ) {
				foreach ( get_object_vars( $schema->properties ) as $key => $subschema ) {
					if ( ! property_exists( $value, $key ) ) {
						continue;
					}
					$segments[] = array( 'member', (string) $key );
					$valid      = $this->json_schema_validate( $subschema, $value->{$key}, $segments, $reason );
					array_pop( $segments );
					if ( ! $valid ) {
						return false;
					}
				}
			}
		}
		if ( is_array( $value ) && property_exists( $schema, 'items' ) ) {
			$items = $schema->items;
			if ( $items instanceof stdClass ) {
				foreach ( $value as $index => $child ) {
					$segments[] = array( 'index', $index );
					$valid      = $this->json_schema_validate( $items, $child, $segments, $reason );
					array_pop( $segments );
					if ( ! $valid ) {
						return false;
					}
				}
			} elseif ( is_array( $items ) ) {
				foreach ( $items as $index => $subschema ) {
					if ( ! array_key_exists( $index, $value ) ) {
						break;
					}
					$segments[] = array( 'index', $index );
					$valid      = $this->json_schema_validate( $subschema, $value[ $index ], $segments, $reason );
					array_pop( $segments );
					if ( ! $valid ) {
						return false;
					}
				}
			}
		}
		if ( property_exists( $schema, 'enum' ) && is_array( $schema->enum ) ) {
			foreach ( $schema->enum as $allowed ) {
				if ( $this->json_values_equal( $allowed, $value ) ) {
					return true;
				}
			}
			$reason = sprintf( 'The value at %s is not one of the allowed enum values.', $location );
			return false;
		}
		return true;
	}

	/**
	 * Method to emulate MySQL JSON_CONTAINS_PATH() function.
	 *
	 * Returns 1 when any path exists ("one") or when all paths exist
	 * ("all"), and 0 otherwise. Returns NULL when any argument is NULL,
	 * when the document is not valid JSON, when the mode is not "one"
	 * or "all", or when a path is not a valid path expression.
	 *
	 * Wildcard segments ("[*]") are not supported; a path containing
	 * one makes the result NULL.
	 *
	 * @return int|null 1 or 0, or NULL on invalid input.
	 */
	public function json_contains_path() {
		$args  = func_get_args();
		$count = count( $args );
		if ( $count < 3 ) {
			return null;
		}
		$doc  = $args[0];
		$mode = $args[1];
		if ( null === $doc || null === $mode ) {
			return null;
		}
		$mode = strtolower( (string) $mode );
		if ( 'one' !== $mode && 'all' !== $mode ) {
			return null;
		}
		if ( ! $this->json_decode_document( $doc, $value ) ) {
			return null;
		}
		$paths = array_slice( $args, 2 );
		foreach ( $paths as $path ) {
			if ( null === $path ) {
				return null;
			}
			if ( ! $this->json_parse_path( $path, $segments ) ) {
				return null;
			}
			if ( $this->json_path_has_wildcard( $segments ) ) {
				return null;
			}
			$this->json_path_get( $value, $segments, $found );
			if ( 'one' === $mode && $found ) {
				return 1;
			}
			if ( 'all' === $mode && ! $found ) {
				return 0;
			}
		}
		return 'one' === $mode ? 0 : 1;
	}

	/**
	 * Method to emulate MySQL JSON_DEPTH() function.
	 *
	 * An empty object, an empty array, or a scalar all have depth 1.
	 * Returns NULL when the argument is NULL or is not valid JSON.
	 *
	 * @param mixed $doc The JSON document.
	 * @return int|null The depth, or NULL on invalid input.
	 */
	public function json_depth( $doc ) {
		if ( null === $doc ) {
			return null;
		}
		if ( ! $this->json_decode_document( $doc, $value ) ) {
			return null;
		}
		return $this->json_value_depth( $value );
	}

	/**
	 * Method to emulate MySQL JSON_KEYS() function.
	 *
	 * Returns the top-level keys of an object as a JSON array, or the
	 * keys of the object selected by the optional path. When the
	 * document or the selected value is not an object, or the path
	 * does not exist, returns NULL to match MySQL. Returns NULL when
	 * any argument is NULL, when the document is not valid JSON, or
	 * when the path is not a valid path expression.
	 *
	 * Wildcard segments ("[*]") are not supported; a path containing
	 * one makes the result NULL.
	 *
	 * @param mixed      $doc  The JSON document.
	 * @param mixed|null $path Optional JSON path selecting an object.
	 * @return string|null The keys as a JSON array, or NULL.
	 */
	public function json_keys( $doc, $path = null ) {
		if ( null === $doc ) {
			return null;
		}
		if ( ! $this->json_decode_document( $doc, $value ) ) {
			return null;
		}
		$target = $value;
		if ( null !== $path ) {
			if ( ! $this->json_parse_path( $path, $segments ) ) {
				return null;
			}
			if ( $this->json_path_has_wildcard( $segments ) ) {
				return null;
			}
			$target = $this->json_path_get( $value, $segments, $found );
			if ( ! $found ) {
				return null;
			}
		}
		if ( ! ( $target instanceof stdClass ) ) {
			return null;
		}
		return $this->json_encode_compact( array_map( 'strval', array_keys( get_object_vars( $target ) ) ) );
	}

	/**
	 * Method to emulate MySQL JSON_OVERLAPS() function.
	 *
	 * Returns 1 when two documents share any key-value pair or array
	 * element. Array elements and object member values compare by deep
	 * equality; only top-level values are autowrapped as a one-element
	 * array when the other argument is an array. Returns NULL when any
	 * argument is NULL or when either document is not valid JSON.
	 *
	 * @return int|null 1 or 0, or NULL on invalid input.
	 */
	public function json_overlaps() {
		$args = func_get_args();
		if ( count( $args ) < 2 ) {
			return null;
		}
		if ( null === $args[0] || null === $args[1] ) {
			return null;
		}
		if ( ! $this->json_decode_document( $args[0], $a ) ) {
			return null;
		}
		if ( ! $this->json_decode_document( $args[1], $b ) ) {
			return null;
		}
		return $this->json_values_overlap( $a, $b ) ? 1 : 0;
	}

	/**
	 * Method to emulate MySQL JSON_PRETTY() function.
	 *
	 * Returns a human-readable rendering of the document with one
	 * value per line and two-space indentation. Returns NULL when the
	 * argument is NULL or is not valid JSON.
	 *
	 * @param mixed $doc The JSON document.
	 * @return string|null The pretty-printed JSON, or NULL on invalid input.
	 */
	public function json_pretty( $doc ) {
		if ( null === $doc ) {
			return null;
		}
		if ( ! $this->json_decode_document( $doc, $value ) ) {
			return null;
		}
		return $this->json_pretty_print( $value, 0 );
	}

	/**
	 * Method to emulate MySQL JSON_SEARCH() function.
	 *
	 * Returns the path to the first matching string value ("one") as a
	 * string, or all matching paths ("all") as a JSON array. Only JSON
	 * strings are matched; numbers, booleans, and null never match.
	 * Matching is case-sensitive. The search string supports "%" and
	 * "_" wildcards like the LIKE operator; the escape character
	 * defaults to backslash when it is missing or NULL, and an empty
	 * escape disables escaping.
	 *
	 * Optional path arguments restrict the search to those subtrees,
	 * while the returned paths are still rooted at "$". Returns NULL
	 * when there is no match, when the document, mode, or search string
	 * is NULL, when the document is not valid JSON, when the mode is not
	 * "one" or "all", or when a path is not a valid path expression.
	 *
	 * Wildcard segments ("[*]") are not supported; a scope path with
	 * one simply matches nothing.
	 *
	 * @return string|null A path, a JSON array of paths, or NULL.
	 */
	public function json_search( $doc = null, $one_or_all = null, $search = null, $escape = null ) {
		$arg_count = func_num_args();
		if ( $arg_count < 3 ) {
			return null;
		}
		if ( null === $doc || null === $one_or_all || null === $search ) {
			return null;
		}
		if ( $arg_count >= 4 ) {
			if ( null === $escape ) {
				$escape = '\\';
			} elseif ( ! is_string( $escape ) || strlen( $escape ) > 1 ) {
				return null;
			}
		} else {
			$escape = '\\';
		}
		$mode = strtolower( (string) $one_or_all );
		if ( 'one' !== $mode && 'all' !== $mode ) {
			return null;
		}
		if ( ! $this->json_decode_document( $doc, $value ) ) {
			return null;
		}
		$regex  = $this->json_like_to_regex( (string) $search, $escape );
		$scopes = array_slice( func_get_args(), 4 );
		if ( 0 === count( $scopes ) ) {
			$scopes = array( '$' );
		}
		$matches = array();
		foreach ( $scopes as $scope ) {
			if ( null === $scope ) {
				return null;
			}
			if ( ! $this->json_parse_path( $scope, $segments ) ) {
				return null;
			}
			if ( $this->json_path_has_wildcard( $segments ) ) {
				continue;
			}
			$target = $this->json_path_get( $value, $segments, $found );
			if ( ! $found ) {
				continue;
			}
			if ( $this->json_search_values( $target, $segments, $regex, $mode, $matches ) ) {
				break;
			}
		}
		if ( 0 === count( $matches ) ) {
			return null;
		}
		if ( 'one' === $mode ) {
			return $matches[0];
		}
		return $this->json_encode_compact( $matches );
	}

	/**
	 * Method to emulate MySQL JSON_VALUE() function.
	 *
	 * Extracts the scalar at the given path and returns it as an
	 * unquoted value: strings as-is, numbers as-is, booleans as
	 * "true" or "false", and JSON null as SQL NULL. Objects and arrays
	 * cannot be converted to a scalar, so they return NULL (MySQL
	 * raises an error in that case). Returns NULL when any argument
	 * is NULL, when the document is not valid JSON, when the path is
	 * not valid, or when the path does not exist.
	 *
	 * @param mixed      $doc  The JSON document.
	 * @param mixed|null $path The JSON path selecting a scalar.
	 * @return mixed The scalar value, or NULL.
	 */
	public function json_value( $doc, $path = null ) {
		if ( null === $doc || null === $path ) {
			return null;
		}
		if ( ! $this->json_decode_document( $doc, $value ) ) {
			return null;
		}
		if ( ! $this->json_parse_path( $path, $segments ) ) {
			return null;
		}
		if ( $this->json_path_has_wildcard( $segments ) ) {
			return null;
		}
		$target = $this->json_path_get( $value, $segments, $found );
		if ( ! $found ) {
			return null;
		}
		if ( $target instanceof stdClass || is_array( $target ) ) {
			return null;
		}
		if ( is_bool( $target ) ) {
			return $target ? 'true' : 'false';
		}
		return $target;
	}

	/**
	 * Method to emulate MySQL JSON_ARRAY_APPEND() function.
	 *
	 * Takes the document followed by path-value pairs evaluated left
	 * to right. Like MySQL, a string value that parses as JSON is
	 * stored as the parsed value, while any other string stays a JSON
	 * string. Pairs whose path does not identify an existing value are
	 * ignored. Wildcard paths are not supported and such pairs are
	 * ignored.
	 *
	 * Returns NULL when the document is NULL or not valid JSON, when
	 * a path is NULL or invalid, or when the arguments do not form
	 * complete path-value pairs.
	 *
	 * @return string|null The updated document, or NULL on invalid input.
	 */
	public function json_array_append() {
		$args  = func_get_args();
		$count = count( $args );
		if ( $count < 3 || 0 === $count % 2 ) {
			return null;
		}
		if ( null === $args[0] ) {
			return null;
		}
		if ( ! $this->json_decode_document( $args[0], $value ) ) {
			return null;
		}
		for ( $i = 1; $i < $count; $i += 2 ) {
			$path = $args[ $i ];
			if ( null === $path ) {
				return null;
			}
			if ( ! $this->json_parse_path( $path, $segments ) ) {
				return null;
			}
			if ( $this->json_path_has_wildcard( $segments ) ) {
				continue;
			}
			$this->json_append_value(
				$value,
				$segments,
				$this->json_parse_candidate( $args[ $i + 1 ] )
			);
		}
		return $this->json_encode_compact( $value );
	}

	/**
	 * Method to emulate MySQL JSON_ARRAY_INSERT() function.
	 *
	 * Takes the document followed by path-value pairs evaluated left
	 * to right. Each path must end in an array index; the value is
	 * inserted before that index, or appended when the index is past
	 * the end. String values follow the same JSON auto-parsing as
	 * JSON_ARRAY_APPEND(). Paths that do not resolve to an existing
	 * array are ignored, like MySQL. Wildcard paths are not supported
	 * and such pairs are ignored.
	 *
	 * Returns NULL when the document is NULL or not valid JSON, when
	 * a path is NULL or invalid, or when the arguments do not form
	 * complete path-value pairs.
	 *
	 * @return string|null The updated document, or NULL on invalid input.
	 */
	public function json_array_insert() {
		$args  = func_get_args();
		$count = count( $args );
		if ( $count < 3 || 0 === $count % 2 ) {
			return null;
		}
		if ( null === $args[0] ) {
			return null;
		}
		if ( ! $this->json_decode_document( $args[0], $value ) ) {
			return null;
		}
		for ( $i = 1; $i < $count; $i += 2 ) {
			$path = $args[ $i ];
			if ( null === $path ) {
				return null;
			}
			if ( ! $this->json_parse_path( $path, $segments ) ) {
				return null;
			}
			if ( $this->json_path_has_wildcard( $segments ) ) {
				continue;
			}
			$this->json_insert_value(
				$value,
				$segments,
				$this->json_parse_candidate( $args[ $i + 1 ] )
			);
		}
		return $this->json_encode_compact( $value );
	}

	/**
	 * Method to emulate MySQL JSON_STORAGE_FREE() function.
	 *
	 * The driver never does partial in-place updates of JSON values,
	 * so no storage is ever freed and this always returns 0. Returns
	 * NULL when the argument is NULL or is not valid JSON.
	 *
	 * @param mixed $doc The JSON document.
	 * @return int|null 0, or NULL on invalid input.
	 */
	public function json_storage_free( $doc ) {
		if ( null === $doc ) {
			return null;
		}
		if ( ! $this->json_decode_document( $doc, $value ) ) {
			return null;
		}
		return 0;
	}

	/**
	 * Method to emulate MySQL JSON_STORAGE_SIZE() function.
	 *
	 * Returns the byte size (an OCTET_LENGTH equivalent) of the
	 * normalized compact form of the document. Returns NULL when the
	 * argument is NULL or is not valid JSON.
	 *
	 * @param mixed $doc The JSON document.
	 * @return int|null The byte size, or NULL on invalid input.
	 */
	public function json_storage_size( $doc ) {
		if ( null === $doc ) {
			return null;
		}
		if ( ! $this->json_decode_document( $doc, $value ) ) {
			return null;
		}
		$json = $this->json_encode_compact( $value );
		if ( null === $json ) {
			return null;
		}
		return strlen( $json );
	}

	/**
	 * Method to emulate MySQL JSON_SCHEMA_VALID() function.
	 *
	 * Returns 1 when the document validates against the schema and 0
	 * when it does not. Only the documented subset is enforced: type,
	 * properties, required, items, and enum. Unknown keywords are
	 * ignored; $ref, patternProperties, boolean combinators, and
	 * remote schemas are not supported. Returns NULL when any argument
	 * is NULL, when either argument is not valid JSON, or when the
	 * schema is not a JSON object.
	 *
	 * @param mixed $schema The JSON Schema document.
	 * @param mixed $doc    The JSON document to validate.
	 * @return int|null 1 or 0, or NULL on invalid input.
	 */
	public function json_schema_valid( $schema, $doc ) {
		if ( null === $schema || null === $doc ) {
			return null;
		}
		if ( ! $this->json_decode_document( $schema, $schema_value ) ) {
			return null;
		}
		if ( ! $this->json_decode_document( $doc, $value ) ) {
			return null;
		}
		if ( ! ( $schema_value instanceof stdClass ) ) {
			return null;
		}
		$reason = '';
		return $this->json_schema_validate( $schema_value, $value, array(), $reason ) ? 1 : 0;
	}

	/**
	 * Method to emulate MySQL JSON_SCHEMA_VALIDATION_REPORT() function.
	 *
	 * Returns a JSON object with a "valid" boolean and, when invalid,
	 * a "reason" string describing the first failure. The same schema
	 * subset and NULL rules as JSON_SCHEMA_VALID() apply.
	 *
	 * @param mixed $schema The JSON Schema document.
	 * @param mixed $doc    The JSON document to validate.
	 * @return string|null The JSON report, or NULL on invalid input.
	 */
	public function json_schema_validation_report( $schema, $doc ) {
		if ( null === $schema || null === $doc ) {
			return null;
		}
		if ( ! $this->json_decode_document( $schema, $schema_value ) ) {
			return null;
		}
		if ( ! $this->json_decode_document( $doc, $value ) ) {
			return null;
		}
		if ( ! ( $schema_value instanceof stdClass ) ) {
			return null;
		}
		$reason = '';
		if ( $this->json_schema_validate( $schema_value, $value, array(), $reason ) ) {
			return $this->json_encode_compact( array( 'valid' => true ) );
		}
		return $this->json_encode_compact(
			array(
				'valid'  => false,
				'reason' => $reason,
			)
		);
	}

	/**
	 * Method to emulate MySQL JSON_MERGE() function.
	 *
	 * JSON_MERGE() is a deprecated alias of JSON_MERGE_PRESERVE(): two
	 * objects merge key by key (shared keys merge recursively), while
	 * arrays concatenate after autowrapping a non-array operand as a
	 * one-element array. Returns NULL when any argument is NULL or when
	 * any document is not valid JSON.
	 *
	 * @return string|null The merged document, or NULL on invalid input.
	 */
	public function json_merge() {
		$args = func_get_args();
		if ( 0 === count( $args ) ) {
			return null;
		}
		$merged = null;
		$first  = true;
		foreach ( $args as $doc ) {
			if ( null === $doc ) {
				return null;
			}
			if ( ! $this->json_decode_document( $doc, $value ) ) {
				return null;
			}
			if ( $first ) {
				$merged = $value;
				$first  = false;
			} else {
				$merged = $this->json_merge_preserve_values( $merged, $value );
			}
		}
		return $this->json_encode_compact( $merged );
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
