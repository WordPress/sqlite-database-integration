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
		'sha1'                         => 'sha1',
		'sha2'                         => 'sha2',
		'crc32'                        => 'crc32',
		'conv'                         => 'conv',
		'oct'                          => 'oct',
		'bin'                          => 'bin',
		'bit_count'                    => 'bit_count',
		'uuid'                         => 'uuid',
		'uuid_short'                   => 'uuid_short',
		'uuid_to_bin'                  => 'uuid_to_bin',
		'bin_to_uuid'                  => 'bin_to_uuid',
		'is_uuid'                      => 'is_uuid',
		'random_bytes'                 => 'random_bytes',
		'compress'                     => 'compress',
		'uncompress'                   => 'uncompress',
		'uncompressed_length'          => 'uncompressed_length',
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
	 * Last-seen server startup time for UUID_SHORT().
	 *
	 * @var int|null
	 */
	private $uuid_short_boot_time = null;

	/**
	 * Per-connection counter for UUID_SHORT().
	 *
	 * @var int
	 */
	private $uuid_short_counter = 0;

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
	 * Method to emulate MySQL SHA1() function.
	 *
	 * Returns the 40-character hexadecimal SHA-1 digest of the argument.
	 * Returns NULL if the argument is NULL.
	 *
	 * @param string|null $str The string to hash.
	 *
	 * @return string|null SHA-1 hex digest, or NULL.
	 */
	public function sha1( $str ) {
		if ( null === $str ) {
			return null;
		}
		return sha1( (string) $str );
	}

	/**
	 * Method to emulate MySQL SHA2() function.
	 *
	 * Returns the hexadecimal SHA-2 digest of the argument using the given
	 * hash length in bits. A hash length of 0 is equivalent to 256, matching
	 * MySQL. Any other value than 224, 256, 384, 512, or 0 returns NULL,
	 * as does a NULL argument.
	 *
	 * @param string|null $str         The string to hash.
	 * @param int|null    $hash_length One of 224, 256, 384, 512, or 0.
	 *
	 * @return string|null SHA-2 hex digest, or NULL.
	 */
	public function sha2( $str, $hash_length ) {
		if ( null === $str || null === $hash_length ) {
			return null;
		}
		switch ( (int) $hash_length ) {
			case 224:
				$algo = 'sha224';
				break;
			case 0:
			case 256:
				$algo = 'sha256';
				break;
			case 384:
				$algo = 'sha384';
				break;
			case 512:
				$algo = 'sha512';
				break;
			default:
				return null;
		}
		return hash( $algo, (string) $str );
	}

	/**
	 * Method to emulate MySQL CRC32() function.
	 *
	 * Computes a cyclic redundancy check value and returns it as an
	 * unsigned 32-bit integer. Returns NULL if the argument is NULL.
	 *
	 * The value is returned as a numeric string: the SQLite UDF bridge
	 * truncates PHP integers to 32-bit signed values, which would corrupt
	 * checksums at or above 2^31.
	 *
	 * @param string|null $str The string to checksum.
	 *
	 * @return string|null CRC32 value, or NULL.
	 */
	public function crc32( $str ) {
		if ( null === $str ) {
			return null;
		}
		return (string) crc32( (string) $str );
	}

	/**
	 * Method to emulate MySQL CONV() function.
	 *
	 * Converts numbers between different number bases, returning a string
	 * representation of the number N converted from base from_base to base
	 * to_base. Bases range from -36 to 36, excluding -1, 0, and 1; anything
	 * else returns NULL, as does a NULL argument.
	 *
	 * A negative from_base treats N as a signed number: positive values past
	 * INT64_MAX saturate, matching MySQL's strtoll-style parsing. A negative
	 * to_base renders the value as signed, prefixing '-' for values with the
	 * high bit set. Digits past the first invalid character are ignored, and
	 * a value with no valid digits converts as 0, matching MySQL.
	 *
	 * The conversion walks digits in 32-bit halves so results stay exact
	 * past 2^53, where PHP's base_convert() loses precision.
	 *
	 * @param mixed $n         The number, as an integer or string.
	 * @param mixed $from_base The base to convert from.
	 * @param mixed $to_base   The base to convert to.
	 *
	 * @return string|null Converted number, or NULL.
	 */
	public function conv( $n, $from_base, $to_base ) {
		if ( null === $n || null === $from_base || null === $to_base ) {
			return null;
		}
		$from     = (int) $from_base;
		$to       = (int) $to_base;
		$from_abs = $from < 0 ? -$from : $from;
		$to_abs   = $to < 0 ? -$to : $to;
		if ( $from_abs < 2 || $from_abs > 36 || $to_abs < 2 || $to_abs > 36 ) {
			return null;
		}

		$s        = trim( (string) $n );
		$negative = false;
		if ( '' !== $s && ( '-' === $s[0] || '+' === $s[0] ) ) {
			$negative = '-' === $s[0];
			$s        = substr( $s, 1 );
		}

		$digits = array();
		$length = strlen( $s );
		for ( $i = 0; $i < $length; $i++ ) {
			$c = $s[ $i ];
			if ( $c >= '0' && $c <= '9' ) {
				$d = ord( $c ) - 48;
			} elseif ( $c >= 'a' && $c <= 'z' ) {
				$d = ord( $c ) - 87;
			} elseif ( $c >= 'A' && $c <= 'Z' ) {
				$d = ord( $c ) - 55;
			} else {
				break;
			}
			if ( $d >= $from_abs ) {
				break;
			}
			$digits[] = $d;
		}

		list( $hi, $lo ) = $this->uint64_parse_digits( $digits, $from_abs );
		if ( $negative ) {
			list( $hi, $lo ) = $this->uint64_neg( $hi, $lo );
		} elseif ( $from < 0 && $hi >= 2147483648 ) {
			// Signed interpretation saturates positive overflow at INT64_MAX.
			$hi = 2147483647;
			$lo = 4294967295;
		}

		if ( $to < 0 && $hi >= 2147483648 ) {
			list( $magnitude_hi, $magnitude_lo ) = $this->uint64_neg( $hi, $lo );
			return '-' . $this->uint64_to_base( $magnitude_hi, $magnitude_lo, $to_abs );
		}
		return $this->uint64_to_base( $hi, $lo, $to_abs );
	}

	/**
	 * Method to emulate MySQL OCT() function.
	 *
	 * Returns an octal representation of N, equivalent to CONV(N, 10, 8).
	 * Negative values wrap as unsigned 64-bit integers. Returns NULL if N
	 * is NULL. Fractional values truncate toward zero.
	 *
	 * @param mixed $n The number to convert.
	 *
	 * @return string|null Octal representation, or NULL.
	 */
	public function oct( $n ) {
		if ( null === $n ) {
			return null;
		}
		list( $hi, $lo ) = $this->coerce_to_uint64( $n );
		return $this->uint64_to_base( $hi, $lo, 8 );
	}

	/**
	 * Method to emulate MySQL BIN() function.
	 *
	 * Returns a binary representation of N, equivalent to CONV(N, 10, 2).
	 * Negative values wrap as unsigned 64-bit integers. Returns NULL if N
	 * is NULL. Fractional values truncate toward zero.
	 *
	 * @param mixed $n The number to convert.
	 *
	 * @return string|null Binary representation, or NULL.
	 */
	public function bin( $n ) {
		if ( null === $n ) {
			return null;
		}
		list( $hi, $lo ) = $this->coerce_to_uint64( $n );
		return $this->uint64_to_base( $hi, $lo, 2 );
	}

	/**
	 * Method to emulate MySQL BIT_COUNT() function.
	 *
	 * Returns the number of bits set in the argument as an unsigned
	 * 64-bit integer. Returns NULL if the argument is NULL.
	 *
	 * @param mixed $n The value to count bits in.
	 *
	 * @return int|null Number of set bits, or NULL.
	 */
	public function bit_count( $n ) {
		if ( null === $n ) {
			return null;
		}
		list( $hi, $lo ) = $this->coerce_to_uint64( $n );
		return substr_count( decbin( $hi ), '1' ) + substr_count( decbin( $lo ), '1' );
	}

	/**
	 * Method to emulate MySQL UUID() function.
	 *
	 * Returns a version 4 universally unique identifier as a 36-character
	 * lowercase string, generated from cryptographic randomness. Returns
	 * NULL if no randomness is available.
	 *
	 * @return string|null UUID string, or NULL.
	 */
	public function uuid() {
		try {
			$bytes = random_bytes( 16 );
		} catch ( Exception $e ) {
			return null;
		}
		$hex = bin2hex( $bytes );
		// Set the version (4) and RFC 4122 variant (10xx) bits.
		$hex[12] = '4';
		$hex[16] = dechex( 8 + ( hexdec( $hex[16] ) & 3 ) );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}

	/**
	 * Method to emulate MySQL UUID_SHORT() function.
	 *
	 * Returns a 64-bit unsigned integer built the way MySQL documents it:
	 * (server_id & 255) << 56 plus (server startup time in seconds << 24)
	 * plus a counter that increments on every call. The server id is 1
	 * here since this driver has no MySQL server id of its own, and the
	 * startup time is captured on first use per connection, and the counter
	 * starts at a random 24-bit value so two connections whose first call
	 * lands in the same second are unlikely to collide. That is weaker than
	 * MySQL, which keeps one counter for the whole server; a strict
	 * guarantee would need a counter shared through the database. Values
	 * increase across calls and are not stable across restarts, matching
	 * MySQL's documented nondeterminism.
	 *
	 * The value is returned as a numeric string: the SQLite UDF bridge
	 * truncates PHP integers to 32-bit signed values, which cannot carry
	 * a 64-bit identifier.
	 *
	 * @return string Universal short id.
	 */
	public function uuid_short() {
		if ( null === $this->uuid_short_boot_time ) {
			$this->uuid_short_boot_time = time();
			/*
			 * MySQL keeps one startup time and one counter for the whole
			 * server, so its values are unique across connections. Each
			 * connection here gets its own UDF instance, and most requests
			 * open their own connection, so two requests whose first call
			 * lands in the same second would otherwise produce identical
			 * sequences. Seeding the counter randomly makes that collision
			 * unlikely without pretending to guarantee it.
			 */
			if ( function_exists( 'random_int' ) ) {
				try {
					$this->uuid_short_counter = random_int( 0, 0xFFFFFF );
				} catch ( Exception $e ) {
					$this->uuid_short_counter = mt_rand( 0, 0xFFFFFF );
				}
			} else {
				$this->uuid_short_counter = mt_rand( 0, 0xFFFFFF );
			}
		}
		$value = ( ( 1 & 255 ) << 56 ) + ( ( $this->uuid_short_boot_time & 0xFFFFFF ) << 24 ) + ( $this->uuid_short_counter & 0xFFFFFF );
		++$this->uuid_short_counter;
		return (string) $value;
	}

	/**
	 * Method to emulate MySQL UUID_TO_BIN() function.
	 *
	 * Converts a 36-character UUID string to its 16-byte binary form.
	 * A nonzero swap flag moves the time-high field ahead of the time-low
	 * field, matching MySQL's index-friendly layout. Returns NULL for NULL
	 * input or for strings that are not valid UUIDs.
	 *
	 * @param string|null $uuid      UUID string.
	 * @param mixed       $swap_flag Nonzero to swap the time fields.
	 *
	 * @return string|null 16-byte binary UUID, or NULL.
	 */
	public function uuid_to_bin( $uuid, $swap_flag = 0 ) {
		if ( null === $uuid || null === $swap_flag ) {
			return null;
		}
		$hex = strtolower( str_replace( '-', '', (string) $uuid ) );
		if ( 32 !== strlen( $hex ) || 1 !== preg_match( '/^[0-9a-f]{32}$/', $hex ) ) {
			return null;
		}
		if ( 0 !== (int) $swap_flag ) {
			$hex = substr( $hex, 12, 4 ) . substr( $hex, 8, 4 ) . substr( $hex, 0, 8 ) . substr( $hex, 16, 16 );
		}
		$bin = hex2bin( $hex );
		if ( false === $bin ) {
			return null;
		}
		return $bin;
	}

	/**
	 * Method to emulate MySQL BIN_TO_UUID() function.
	 *
	 * Converts a 16-byte binary UUID back to its 36-character lowercase
	 * string form. A nonzero swap flag reverses the UUID_TO_BIN() time
	 * field swap. Returns NULL for NULL input or for values that are not
	 * exactly 16 bytes long.
	 *
	 * @param string|null $bin       16-byte binary UUID.
	 * @param mixed       $swap_flag Nonzero to reverse the time field swap.
	 *
	 * @return string|null UUID string, or NULL.
	 */
	public function bin_to_uuid( $bin, $swap_flag = 0 ) {
		if ( null === $bin || null === $swap_flag ) {
			return null;
		}
		$bin = (string) $bin;
		if ( 16 !== strlen( $bin ) ) {
			return null;
		}
		$hex = bin2hex( $bin );
		if ( 0 !== (int) $swap_flag ) {
			$hex = substr( $hex, 8, 8 ) . substr( $hex, 4, 4 ) . substr( $hex, 0, 4 ) . substr( $hex, 16, 16 );
		}
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}

	/**
	 * Method to emulate MySQL IS_UUID() function.
	 *
	 * Returns 1 when the argument is a valid string-format UUID
	 * (32 hexadecimal digits in 8-4-4-4-12 groups), 0 when it is not, and
	 * NULL when the argument is NULL.
	 *
	 * @param string|null $str The value to check.
	 *
	 * @return int|null 1 or 0, or NULL.
	 */
	public function is_uuid( $str ) {
		if ( null === $str ) {
			return null;
		}
		return 1 === preg_match( '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', (string) $str ) ? 1 : 0;
	}

	/**
	 * Method to emulate MySQL RANDOM_BYTES() function.
	 *
	 * Returns a binary string of the requested number of cryptographically
	 * secure random bytes. MySQL accepts lengths from 1 to 1024; anything
	 * else returns NULL here instead of raising an error, as does NULL
	 * input or unavailable randomness.
	 *
	 * @param mixed $len Number of bytes to generate.
	 *
	 * @return string|null Random bytes, or NULL.
	 */
	public function random_bytes( $len ) {
		if ( null === $len ) {
			return null;
		}
		$len = (int) $len;
		if ( $len < 1 || $len > 1024 ) {
			return null;
		}
		try {
			return random_bytes( $len );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Method to emulate MySQL COMPRESS() function.
	 *
	 * Compresses a string with zlib and prefixes it with the 4-byte
	 * little-endian uncompressed length, matching MySQL's byte layout so
	 * UNCOMPRESS() and UNCOMPRESSED_LENGTH() can read it back. An empty
	 * string compresses to an empty string, matching MySQL. Returns NULL
	 * for NULL input.
	 *
	 * Note that the SQLite UDF bridge returns the compressed bytes as TEXT,
	 * so SQLite LENGTH() stops at the first zero byte of the 4-byte header.
	 * Measure compressed sizes with LENGTH(HEX(...)) or in PHP instead.
	 *
	 * @param string|null $str The string to compress.
	 *
	 * @return string|null Compressed binary string, or NULL.
	 */
	public function compress( $str ) {
		if ( null === $str ) {
			return null;
		}
		$str = (string) $str;
		if ( '' === $str ) {
			return '';
		}
		$compressed = gzcompress( $str );
		if ( false === $compressed ) {
			return null;
		}
		return pack( 'V', strlen( $str ) ) . $compressed;
	}

	/**
	 * Method to emulate MySQL UNCOMPRESS() function.
	 *
	 * Uncompresses a string compressed by COMPRESS(). Returns NULL for NULL
	 * input, for inputs shorter than the 4-byte length header, and for
	 * inputs that fail zlib decompression.
	 *
	 * @param string|null $str The compressed string.
	 *
	 * @return string|null Uncompressed string, or NULL.
	 */
	public function uncompress( $str ) {
		if ( null === $str ) {
			return null;
		}
		$str = (string) $str;
		if ( '' === $str ) {
			return '';
		}
		if ( strlen( $str ) < 4 ) {
			return null;
		}
		$failed = false;
		set_error_handler(
			static function () use ( &$failed ) {
				$failed = true;
				return true;
			}
		);
		try {
			$uncompressed = gzuncompress( substr( $str, 4 ) );
		} finally {
			restore_error_handler();
		}
		if ( $failed || false === $uncompressed ) {
			return null;
		}
		return $uncompressed;
	}

	/**
	 * Method to emulate MySQL UNCOMPRESSED_LENGTH() function.
	 *
	 * Returns the uncompressed length stored in the 4-byte header of a
	 * string compressed by COMPRESS(), without decompressing it. Returns
	 * NULL for NULL input and for inputs shorter than the header.
	 *
	 * @param string|null $str The compressed string.
	 *
	 * @return int|null Uncompressed length, or NULL.
	 */
	public function uncompressed_length( $str ) {
		if ( null === $str ) {
			return null;
		}
		$str = (string) $str;
		if ( strlen( $str ) < 4 ) {
			return null;
		}
		$length = unpack( 'V', substr( $str, 0, 4 ) );
		return $length[1];
	}

	/**
	 * Parse digit values into an unsigned 64-bit integer.
	 *
	 * The value is kept as two 32-bit words so the arithmetic stays exact
	 * past 2^53. Overflow wraps modulo 2^64, matching MySQL's unsigned
	 * 64-bit conversions.
	 *
	 * @param array $digits Digit values, most significant first.
	 * @param int   $base   Number base, 2 to 36.
	 *
	 * @return array Array of two ints: high and low 32-bit words.
	 */
	private function uint64_parse_digits( $digits, $base ) {
		$hi = 0;
		$lo = 0;
		foreach ( $digits as $d ) {
			list( $hi, $lo ) = $this->uint64_mul_add( $hi, $lo, $base, $d );
		}
		return array( $hi, $lo );
	}

	/**
	 * Compute (value * mult + add) modulo 2^64 on a hi/lo word pair.
	 *
	 * The multiplier and addend are small (at most 36 and 35), so the
	 * intermediate low-word product always fits in a PHP integer exactly.
	 *
	 * @param int $hi   High 32-bit word.
	 * @param int $lo   Low 32-bit word.
	 * @param int $mult Multiplier.
	 * @param int $add  Addend.
	 *
	 * @return array Array of two ints: high and low 32-bit words.
	 */
	private function uint64_mul_add( $hi, $lo, $mult, $add ) {
		$total  = $lo * $mult + $add;
		$carry  = intdiv( $total, 4294967296 );
		$new_lo = $total % 4294967296;
		$new_hi = ( $hi * $mult + $carry ) % 4294967296;
		return array( $new_hi, $new_lo );
	}

	/**
	 * Negate an unsigned 64-bit integer, i.e. compute 2^64 - value.
	 *
	 * @param int $hi High 32-bit word.
	 * @param int $lo Low 32-bit word.
	 *
	 * @return array Array of two ints: high and low 32-bit words.
	 */
	private function uint64_neg( $hi, $lo ) {
		if ( 0 === $hi && 0 === $lo ) {
			return array( 0, 0 );
		}
		$new_lo = ( 4294967296 - $lo ) % 4294967296;
		$borrow = 0 === $lo ? 0 : 1;
		$new_hi = ( 4294967296 - $hi - $borrow ) % 4294967296;
		return array( $new_hi, $new_lo );
	}

	/**
	 * Render an unsigned 64-bit integer in the given base.
	 *
	 * Divides the hi/lo word pair down by repeated 64-by-32-bit division,
	 * staying exact for the full 64-bit range.
	 *
	 * @param int $hi   High 32-bit word.
	 * @param int $lo   Low 32-bit word.
	 * @param int $base Number base, 2 to 36.
	 *
	 * @return string Uppercase digits in the given base.
	 */
	private function uint64_to_base( $hi, $lo, $base ) {
		$digits = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
		if ( 0 === $hi && 0 === $lo ) {
			return '0';
		}
		$out = '';
		while ( 0 !== $hi || 0 !== $lo ) {
			$quotient_hi = intdiv( $hi, $base );
			$remainder   = $hi % $base;
			// At most 35 * 2^32 + (2^32 - 1): fits exactly in an integer.
			$combined    = $remainder * 4294967296 + $lo;
			$quotient_lo = intdiv( $combined, $base );
			$out         = $digits[ $combined % $base ] . $out;
			$hi          = $quotient_hi;
			$lo          = $quotient_lo;
		}
		return $out;
	}

	/**
	 * Coerce a value to an unsigned 64-bit integer, like MySQL does when
	 * OCT(), BIN(), or BIT_COUNT() receive their argument.
	 *
	 * Integers map to their two's complement bit pattern, floats truncate
	 * toward zero, and strings parse as a decimal prefix (an optional sign
	 * followed by digits; anything else converts as 0). Values wrap modulo
	 * 2^64. Floats past 2^53 may lose precision; that is documented rather
	 * than fixed, since SQL floats cannot carry 64-bit integers exactly.
	 *
	 * @param mixed $value The value to coerce.
	 *
	 * @return array Array of two ints: high and low 32-bit words.
	 */
	private function coerce_to_uint64( $value ) {
		if ( is_int( $value ) ) {
			if ( $value >= 0 ) {
				return array( intdiv( $value, 4294967296 ), $value % 4294967296 );
			}
			return array( ( $value >> 32 ) & 0xFFFFFFFF, $value & 0xFFFFFFFF );
		}
		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) {
				return array( 0, 0 );
			}
			/*
			 * Work on the magnitude and negate afterwards. Adding 2^64 to a
			 * small negative value is inexact in double precision and rounds
			 * to exactly 2^64, which produced a bogus high word (for example
			 * BIT_COUNT(-1.5) reported 1 instead of 64).
			 */
			$negative  = $value < 0;
			$magnitude = $negative ? -$value : $value;
			$truncated = floor( $magnitude );
			$truncated = fmod( $truncated, 18446744073709551616 );
			$hi        = (int) floor( $truncated / 4294967296 );
			$lo        = (int) ( $truncated - $hi * 4294967296 );
			if ( $negative ) {
				list( $hi, $lo ) = $this->uint64_neg( $hi, $lo );
			}
			return array( $hi, $lo );
		}
		$s        = trim( (string) $value );
		$negative = false;
		if ( '' !== $s && ( '-' === $s[0] || '+' === $s[0] ) ) {
			$negative = '-' === $s[0];
			$s        = substr( $s, 1 );
		}
		$digits = array();
		$length = strlen( $s );
		for ( $i = 0; $i < $length; $i++ ) {
			if ( $s[ $i ] < '0' || $s[ $i ] > '9' ) {
				break;
			}
			$digits[] = ord( $s[ $i ] ) - 48;
		}
		list( $hi, $lo ) = $this->uint64_parse_digits( $digits, 10 );
		if ( $negative ) {
			list( $hi, $lo ) = $this->uint64_neg( $hi, $lo );
		}
		return array( $hi, $lo );
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
}
