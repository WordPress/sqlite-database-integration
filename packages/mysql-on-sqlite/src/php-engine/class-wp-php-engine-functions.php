<?php

/**
 * Built-in SQL functions for the pure-PHP database engine.
 *
 * This is a part of the pure-PHP database engine ("WP_PHP_Engine") — an
 * SQLite-compatible database engine implemented entirely in PHP.
 *
 * The implementations follow the behavior of the SQLite core functions:
 *   https://www.sqlite.org/lang_corefunc.html
 *   https://www.sqlite.org/lang_datefunc.html
 *   https://www.sqlite.org/lang_aggfunc.html
 */
class WP_PHP_Engine_Functions {
	/**
	 * Scalar functions implemented by this class, mapped to method names.
	 *
	 * @var array<string, string>
	 */
	public static $scalar_functions = array(
		'abs'               => 'abs',
		'char'              => 'char',
		'coalesce'          => 'coalesce',
		'concat'            => 'concat',
		'concat_ws'         => 'concat_ws',
		'format'            => 'format',
		'glob'              => 'glob',
		'hex'               => 'hex',
		'ifnull'            => 'ifnull',
		'iif'               => 'iif',
		'instr'             => 'instr',
		'last_insert_rowid' => 'last_insert_rowid',
		'length'            => 'length',
		'like'              => 'like',
		'likely'            => 'identity',
		'lower'             => 'lower',
		'ltrim'             => 'ltrim',
		'max'               => 'max_scalar',
		'min'               => 'min_scalar',
		'nullif'            => 'nullif',
		'octet_length'      => 'octet_length',
		'printf'            => 'format',
		'quote'             => 'quote',
		'random'            => 'random',
		'randomblob'        => 'randomblob',
		'replace'           => 'replace',
		'round'             => 'round',
		'rtrim'             => 'rtrim',
		'sign'              => 'sign',
		'soundex'           => 'soundex',
		'sqlite_version'    => 'sqlite_version',
		'sqlite_source_id'  => 'sqlite_source_id',
		'substr'            => 'substr',
		'substring'         => 'substr',
		'trim'              => 'trim',
		'typeof'            => 'typeof',
		'unhex'             => 'unhex',
		'unicode'           => 'unicode',
		'unlikely'          => 'identity',
		'upper'             => 'upper',
		'zeroblob'          => 'zeroblob',
		'json_valid'        => 'json_valid',
		'json'              => 'json',
		'date'              => 'date',
		'time'              => 'time',
		'datetime'          => 'datetime',
		'julianday'         => 'julianday',
		'unixepoch'         => 'unixepoch',
		'strftime'          => 'strftime',
	);

	/**
	 * Aggregate functions natively understood by the engine.
	 *
	 * @var array<string, bool>
	 */
	public static $aggregate_functions = array(
		'avg'          => true,
		'count'        => true,
		'group_concat' => true,
		'string_agg'   => true,
		'max'          => true,
		'min'          => true,
		'sum'          => true,
		'total'        => true,
	);

	/**
	 * The engine instance (for last_insert_rowid, changes, etc.).
	 *
	 * @var WP_PHP_Engine
	 */
	private $engine;

	/**
	 * Constructor.
	 *
	 * @param WP_PHP_Engine $engine The engine instance.
	 */
	public function __construct( $engine ) {
		$this->engine = $engine;
	}

	/**
	 * Call a built-in scalar function.
	 *
	 * @param  string $name The lowercase function name.
	 * @param  array  $args The argument values.
	 * @return mixed        The function result.
	 */
	public function call( $name, $args ) {
		$method = self::$scalar_functions[ $name ];
		return $this->$method( $args );
	}

	/**
	 * Check whether a name is a built-in scalar function.
	 *
	 * @param  string $name The lowercase function name.
	 * @return bool         Whether the function exists.
	 */
	public static function is_scalar( $name ) {
		return isset( self::$scalar_functions[ $name ] );
	}

	/*
	 * ----------------------------------------------------------------------
	 * Core scalar functions.
	 * ----------------------------------------------------------------------
	 */

	private function identity( $args ) {
		return isset( $args[0] ) ? $args[0] : null;
	}

	private function abs( $args ) {
		$value = $args[0];
		if ( null === $value ) {
			return null;
		}
		$number = WP_PHP_Engine_Values::to_numeric( $value );
		return abs( $number );
	}

	private function char( $args ) {
		$result = '';
		foreach ( $args as $arg ) {
			if ( null === $arg ) {
				continue;
			}
			$code = (int) $arg;
			if ( function_exists( 'mb_chr' ) ) {
				$result .= mb_chr( $code, 'UTF-8' );
			} else {
				$result .= chr( $code );
			}
		}
		return $result;
	}

	private function coalesce( $args ) {
		foreach ( $args as $arg ) {
			if ( null !== $arg ) {
				return $arg;
			}
		}
		return null;
	}

	private function concat( $args ) {
		$result = '';
		foreach ( $args as $arg ) {
			if ( null !== $arg ) {
				$result .= WP_PHP_Engine_Values::to_text( $arg );
			}
		}
		return $result;
	}

	private function concat_ws( $args ) {
		$separator = array_shift( $args );
		if ( null === $separator ) {
			return null;
		}
		$parts = array();
		foreach ( $args as $arg ) {
			if ( null !== $arg ) {
				$parts[] = WP_PHP_Engine_Values::to_text( $arg );
			}
		}
		return implode( WP_PHP_Engine_Values::to_text( $separator ), $parts );
	}

	private function format( $args ) {
		$format = array_shift( $args );
		if ( null === $format ) {
			return null;
		}
		// SQLite's printf is C-like; vsprintf covers the common cases.
		$format  = str_replace( '%q', '%s', $format );
		$coerced = array();
		foreach ( $args as $arg ) {
			$coerced[] = null === $arg ? '' : $arg;
		}
		return vsprintf( $format, $coerced );
	}

	private function glob( $args ) {
		// glob(X, Y): Y is matched against the glob pattern X.
		if ( null === $args[0] || null === $args[1] ) {
			return null;
		}
		return WP_PHP_Engine_Values::glob_match(
			WP_PHP_Engine_Values::to_text( $args[0] ),
			WP_PHP_Engine_Values::to_text( $args[1] )
		) ? 1 : 0;
	}

	private function hex( $args ) {
		if ( null === $args[0] ) {
			return '';
		}
		return strtoupper( bin2hex( WP_PHP_Engine_Values::to_text( $args[0] ) ) );
	}

	private function ifnull( $args ) {
		return null !== $args[0] ? $args[0] : $args[1];
	}

	private function iif( $args ) {
		$condition = isset( $args[0] ) ? $args[0] : null;
		if ( WP_PHP_Engine_Values::is_truthy( $condition ) ) {
			return isset( $args[1] ) ? $args[1] : null;
		}
		return isset( $args[2] ) ? $args[2] : null;
	}

	private function instr( $args ) {
		if ( null === $args[0] || null === $args[1] ) {
			return null;
		}
		$haystack = WP_PHP_Engine_Values::to_text( $args[0] );
		$needle   = WP_PHP_Engine_Values::to_text( $args[1] );
		if ( '' === $needle ) {
			return 1;
		}
		$pos = strpos( $haystack, $needle );
		return false === $pos ? 0 : $pos + 1;
	}

	private function last_insert_rowid( $args ) {
		return $this->engine->get_last_insert_rowid();
	}

	private function length( $args ) {
		$value = $args[0];
		if ( null === $value ) {
			return null;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return strlen( WP_PHP_Engine_Values::to_text( $value ) );
		}
		// For text, the length is in characters.
		if ( function_exists( 'mb_strlen' ) ) {
			$length = mb_strlen( $value, 'UTF-8' );
			if ( false !== $length ) {
				return $length;
			}
		}
		return strlen( $value );
	}

	private function octet_length( $args ) {
		if ( null === $args[0] ) {
			return null;
		}
		return strlen( WP_PHP_Engine_Values::to_text( $args[0] ) );
	}

	private function like( $args ) {
		// like(X, Y [, Z]): Y is matched against the pattern X.
		if ( null === $args[0] || null === $args[1] ) {
			return null;
		}
		$escape = isset( $args[2] ) ? WP_PHP_Engine_Values::to_text( $args[2] ) : null;
		return WP_PHP_Engine_Values::like_match(
			WP_PHP_Engine_Values::to_text( $args[0] ),
			WP_PHP_Engine_Values::to_text( $args[1] ),
			$escape
		) ? 1 : 0;
	}

	private function lower( $args ) {
		if ( null === $args[0] ) {
			return null;
		}
		return strtolower( WP_PHP_Engine_Values::to_text( $args[0] ) );
	}

	private function upper( $args ) {
		if ( null === $args[0] ) {
			return null;
		}
		return strtoupper( WP_PHP_Engine_Values::to_text( $args[0] ) );
	}

	private function ltrim( $args ) {
		if ( null === $args[0] ) {
			return null;
		}
		$chars = isset( $args[1] ) ? WP_PHP_Engine_Values::to_text( $args[1] ) : " \t\n\r\0\x0B";
		return ltrim( WP_PHP_Engine_Values::to_text( $args[0] ), isset( $args[1] ) ? $chars : ' ' );
	}

	private function rtrim( $args ) {
		if ( null === $args[0] ) {
			return null;
		}
		$chars = isset( $args[1] ) ? WP_PHP_Engine_Values::to_text( $args[1] ) : ' ';
		return rtrim( WP_PHP_Engine_Values::to_text( $args[0] ), $chars );
	}

	private function trim( $args ) {
		if ( null === $args[0] ) {
			return null;
		}
		$chars = isset( $args[1] ) ? WP_PHP_Engine_Values::to_text( $args[1] ) : ' ';
		return trim( WP_PHP_Engine_Values::to_text( $args[0] ), $chars );
	}

	private function max_scalar( $args ) {
		$max = null;
		foreach ( $args as $arg ) {
			if ( null === $arg ) {
				return null;
			}
			if ( null === $max || WP_PHP_Engine_Values::compare( $arg, $max ) > 0 ) {
				$max = $arg;
			}
		}
		return $max;
	}

	private function min_scalar( $args ) {
		$min = null;
		foreach ( $args as $arg ) {
			if ( null === $arg ) {
				return null;
			}
			if ( null === $min || WP_PHP_Engine_Values::compare( $arg, $min ) < 0 ) {
				$min = $arg;
			}
		}
		return $min;
	}

	private function nullif( $args ) {
		if ( 0 === WP_PHP_Engine_Values::compare( $args[0], $args[1] ) && null !== $args[0] && null !== $args[1] ) {
			return null;
		}
		return $args[0];
	}

	private function quote( $args ) {
		$value = $args[0];
		if ( null === $value ) {
			return 'NULL';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return WP_PHP_Engine_Values::to_text( $value );
		}
		return "'" . str_replace( "'", "''", $value ) . "'";
	}

	private function random( $args ) {
		return mt_rand( PHP_INT_MIN / 2, PHP_INT_MAX / 2 );
	}

	private function randomblob( $args ) {
		$length = max( 1, (int) $args[0] );
		$bytes  = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$bytes .= chr( mt_rand( 0, 255 ) );
		}
		return $bytes;
	}

	private function replace( $args ) {
		if ( null === $args[0] || null === $args[1] || null === $args[2] ) {
			return null;
		}
		$pattern = WP_PHP_Engine_Values::to_text( $args[1] );
		if ( '' === $pattern ) {
			return WP_PHP_Engine_Values::to_text( $args[0] );
		}
		return str_replace(
			$pattern,
			WP_PHP_Engine_Values::to_text( $args[2] ),
			WP_PHP_Engine_Values::to_text( $args[0] )
		);
	}

	private function round( $args ) {
		if ( null === $args[0] ) {
			return null;
		}
		$value     = WP_PHP_Engine_Values::to_numeric( $args[0] );
		$precision = isset( $args[1] ) && null !== $args[1] ? (int) $args[1] : 0;
		if ( $precision < 0 ) {
			$precision = 0;
		}
		return round( (float) $value, $precision );
	}

	private function sign( $args ) {
		if ( null === $args[0] ) {
			return null;
		}
		$number = WP_PHP_Engine_Values::to_numeric( $args[0] );
		if ( ! is_int( $number ) && ! is_float( $number ) ) {
			return null;
		}
		return $number > 0 ? 1 : ( $number < 0 ? -1 : 0 );
	}

	private function soundex( $args ) {
		if ( null === $args[0] ) {
			return '?000';
		}
		$result = soundex( WP_PHP_Engine_Values::to_text( $args[0] ) );
		return '' === $result ? '?000' : $result;
	}

	private function sqlite_version( $args ) {
		return WP_PHP_Engine::SQLITE_VERSION;
	}

	private function sqlite_source_id( $args ) {
		return 'wp-php-engine ' . WP_PHP_Engine::SQLITE_VERSION;
	}

	private function substr( $args ) {
		if ( null === $args[0] || null === $args[1] ) {
			return null;
		}
		if ( array_key_exists( 2, $args ) && null === $args[2] ) {
			return null;
		}
		$text   = WP_PHP_Engine_Values::to_text( $args[0] );
		$start  = (int) $args[1];
		$length = isset( $args[2] ) ? (int) $args[2] : null;

		$text_length = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );

		// SQLite substr() uses 1-based indexing with special negative handling.
		if ( $start < 0 ) {
			$start = $text_length + $start + 1;
			if ( $start < 1 ) {
				if ( null !== $length ) {
					$length += $start - 1;
				}
				$start = 1;
			}
		} elseif ( 0 === $start ) {
			if ( null !== $length ) {
				$length -= 1;
			}
			$start = 1;
		}
		if ( null !== $length && $length < 0 ) {
			// Negative length means characters before the start position.
			$new_start = $start + $length;
			$length    = -$length;
			if ( $new_start < 1 ) {
				$length   += $new_start - 1;
				$new_start = 1;
			}
			$start = $new_start;
		}
		if ( null !== $length && $length <= 0 ) {
			return '';
		}
		if ( function_exists( 'mb_substr' ) ) {
			return null === $length
				? mb_substr( $text, $start - 1, null, 'UTF-8' )
				: mb_substr( $text, $start - 1, $length, 'UTF-8' );
		}
		return null === $length ? substr( $text, $start - 1 ) : substr( $text, $start - 1, $length );
	}

	private function typeof( $args ) {
		return WP_PHP_Engine_Values::type_of( $args[0] );
	}

	private function unhex( $args ) {
		if ( null === $args[0] ) {
			return null;
		}
		$hex = WP_PHP_Engine_Values::to_text( $args[0] );
		if ( ! ctype_xdigit( $hex ) || 0 !== strlen( $hex ) % 2 ) {
			return null;
		}
		return pack( 'H*', $hex );
	}

	private function unicode( $args ) {
		if ( null === $args[0] || '' === $args[0] ) {
			return null;
		}
		$text = WP_PHP_Engine_Values::to_text( $args[0] );
		if ( function_exists( 'mb_ord' ) ) {
			$char = mb_substr( $text, 0, 1, 'UTF-8' );
			$ord  = mb_ord( $char, 'UTF-8' );
			if ( false !== $ord ) {
				return $ord;
			}
		}
		return ord( $text );
	}

	private function zeroblob( $args ) {
		return str_repeat( "\0", max( 0, (int) $args[0] ) );
	}

	private function json_valid( $args ) {
		if ( null === $args[0] ) {
			return null;
		}
		json_decode( WP_PHP_Engine_Values::to_text( $args[0] ) );
		return JSON_ERROR_NONE === json_last_error() ? 1 : 0;
	}

	private function json( $args ) {
		if ( null === $args[0] ) {
			return null;
		}
		$decoded = json_decode( WP_PHP_Engine_Values::to_text( $args[0] ) );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new WP_PHP_Engine_SQL_Exception( 'malformed JSON' );
		}
		return json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/*
	 * ----------------------------------------------------------------------
	 * Date and time functions.
	 *
	 * SQLite represents date-time internally as a Julian day number. We use
	 * a float Julian day for computation and render to the requested format.
	 * ----------------------------------------------------------------------
	 */

	const JULIAN_EPOCH_UNIX = 2440587.5; // Julian day of 1970-01-01 00:00:00 UTC.

	private function date( $args ) {
		$julian = $this->parse_datetime_args( $args );
		if ( null === $julian ) {
			return null;
		}
		return $this->render_strftime( '%Y-%m-%d', $julian );
	}

	private function time( $args ) {
		$julian = $this->parse_datetime_args( $args );
		if ( null === $julian ) {
			return null;
		}
		return $this->render_strftime( '%H:%M:%S', $julian );
	}

	private function datetime( $args ) {
		$julian = $this->parse_datetime_args( $args );
		if ( null === $julian ) {
			return null;
		}
		return $this->render_strftime( '%Y-%m-%d %H:%M:%S', $julian );
	}

	private function julianday( $args ) {
		return $this->parse_datetime_args( $args );
	}

	private function unixepoch( $args ) {
		$julian = $this->parse_datetime_args( $args );
		if ( null === $julian ) {
			return null;
		}
		return (int) round( ( $julian - self::JULIAN_EPOCH_UNIX ) * 86400 );
	}

	private function strftime( $args ) {
		$format = array_shift( $args );
		if ( null === $format ) {
			return null;
		}
		$julian = $this->parse_datetime_args( $args );
		if ( null === $julian ) {
			return null;
		}
		return $this->render_strftime( WP_PHP_Engine_Values::to_text( $format ), $julian );
	}

	/**
	 * Parse date-time arguments (a time value plus modifiers) to a Julian day.
	 *
	 * @param  array $args The time value and modifiers.
	 * @return float|null  The Julian day number, or null for invalid input.
	 */
	private function parse_datetime_args( $args ) {
		$value  = count( $args ) > 0 ? array_shift( $args ) : 'now';
		$julian = $this->parse_time_value( $value );
		if ( null === $julian ) {
			return null;
		}
		foreach ( $args as $modifier ) {
			if ( null === $modifier ) {
				return null;
			}
			$julian = $this->apply_modifier( $julian, strtolower( trim( WP_PHP_Engine_Values::to_text( $modifier ) ) ), $value );
			if ( null === $julian ) {
				return null;
			}
		}
		return $julian;
	}

	/**
	 * Parse a time value into a Julian day number.
	 *
	 * @param  mixed $value The time value.
	 * @return float|null   The Julian day number, or null for invalid input.
	 */
	private function parse_time_value( $value ) {
		if ( null === $value ) {
			return null;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			// A numeric value is a Julian day number.
			return (float) $value;
		}

		$text = trim( (string) $value );
		if ( 'now' === strtolower( $text ) ) {
			return self::JULIAN_EPOCH_UNIX + microtime( true ) / 86400;
		}

		// Plain numeric strings are Julian day numbers.
		if ( is_numeric( $text ) ) {
			return (float) $text;
		}

		// ISO-8601 formats: YYYY-MM-DD [HH:MM[:SS[.SSS]]] with optional T.
		if ( preg_match(
			'/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?)?(?:Z|([+-]\d{2}):(\d{2}))?$/',
			$text,
			$m
		) ) {
			$year   = (int) $m[1];
			$month  = (int) $m[2];
			$day    = (int) $m[3];
			$hour   = isset( $m[4] ) && '' !== $m[4] ? (int) $m[4] : 0;
			$minute = isset( $m[5] ) && '' !== $m[5] ? (int) $m[5] : 0;
			$second = isset( $m[6] ) && '' !== $m[6] ? (float) ( $m[6] . ( isset( $m[7] ) && '' !== $m[7] ? '.' . $m[7] : '' ) ) : 0.0;

			if ( $month < 1 || $month > 12 || $day < 1 || $day > 31 || $hour > 24 || $minute > 59 || $second >= 62 ) {
				return null;
			}
			if ( ! checkdate( $month, $day, $year ) ) {
				return null;
			}

			$julian = self::gregorian_to_julian_day( $year, $month, $day )
				+ ( $hour - 12 ) / 24.0 + $minute / 1440.0 + $second / 86400.0 + 0.5;

			// Apply an explicit timezone offset.
			if ( isset( $m[8] ) && '' !== $m[8] ) {
				$offset_minutes = (int) $m[8] * 60 + ( (int) $m[8] < 0 ? - (int) $m[9] : (int) $m[9] );
				$julian        -= $offset_minutes / 1440.0;
			}
			return $julian;
		}

		// Time-only formats: HH:MM[:SS[.SSS]] (date 2000-01-01).
		if ( preg_match( '/^(\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?$/', $text, $m ) ) {
			$hour   = (int) $m[1];
			$minute = (int) $m[2];
			$second = isset( $m[3] ) && '' !== $m[3] ? (float) ( $m[3] . ( isset( $m[4] ) && '' !== $m[4] ? '.' . $m[4] : '' ) ) : 0.0;
			if ( $hour > 24 || $minute > 59 || $second >= 62 ) {
				return null;
			}
			return self::gregorian_to_julian_day( 2000, 1, 1 )
				+ ( $hour - 12 ) / 24.0 + $minute / 1440.0 + $second / 86400.0 + 0.5;
		}

		return null;
	}

	/**
	 * Apply a date-time modifier to a Julian day number.
	 *
	 * @param  float  $julian        The Julian day number.
	 * @param  string $modifier      The modifier (lowercased and trimmed).
	 * @param  mixed  $original_value The original time value (for 'unixepoch').
	 * @return float|null            The new Julian day number, or null on error.
	 */
	private function apply_modifier( $julian, $modifier, $original_value ) {
		// "unixepoch": reinterpret the numeric value as a Unix timestamp.
		if ( 'unixepoch' === $modifier ) {
			return self::JULIAN_EPOCH_UNIX + WP_PHP_Engine_Values::to_numeric( $original_value ) / 86400.0;
		}
		if ( 'julianday' === $modifier || 'auto' === $modifier ) {
			return $julian;
		}
		if ( 'localtime' === $modifier ) {
			$timestamp = ( $julian - self::JULIAN_EPOCH_UNIX ) * 86400;
			// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- The local timezone is intentional here.
			$offset = (int) date( 'Z', (int) round( $timestamp ) );
			return $julian + $offset / 86400.0;
		}
		if ( 'utc' === $modifier ) {
			$timestamp = ( $julian - self::JULIAN_EPOCH_UNIX ) * 86400;
			// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- The local timezone is intentional here.
			$offset = (int) date( 'Z', (int) round( $timestamp ) );
			return $julian - $offset / 86400.0;
		}

		// "start of day/month/year".
		if ( 0 === strpos( $modifier, 'start of ' ) ) {
			$what                       = substr( $modifier, 9 );
			list( $year, $month, $day ) = self::julian_day_to_gregorian( $julian );
			if ( 'day' === $what ) {
				return self::gregorian_to_julian_day( $year, $month, $day ) - 0.5 + 0.5;
			}
			if ( 'month' === $what ) {
				return self::gregorian_to_julian_day( $year, $month, 1 );
			}
			if ( 'year' === $what ) {
				return self::gregorian_to_julian_day( $year, 1, 1 );
			}
			return null;
		}

		// "weekday N".
		if ( 0 === strpos( $modifier, 'weekday ' ) ) {
			$target  = (int) substr( $modifier, 8 );
			$current = (int) $this->render_strftime( '%w', $julian );
			$delta   = ( $target - $current + 7 ) % 7;
			return $julian + $delta;
		}

		// "±NNN units" or "NNN units".
		if ( preg_match( '/^([+-]?\d+(?:\.\d+)?)\s*(year|month|day|hour|minute|second)s?$/', $modifier, $m ) ) {
			$amount = (float) $m[1];
			$unit   = $m[2];
			switch ( $unit ) {
				case 'second':
					return $julian + $amount / 86400.0;
				case 'minute':
					return $julian + $amount / 1440.0;
				case 'hour':
					return $julian + $amount / 24.0;
				case 'day':
					return $julian + $amount;
				case 'month':
				case 'year':
					list( $year, $month, $day ) = self::julian_day_to_gregorian( $julian );
					$time_fraction              = $julian - self::gregorian_to_julian_day( $year, $month, $day );
					$months                     = 'year' === $unit ? (int) $amount * 12 : (int) $amount;
					$total                      = $year * 12 + ( $month - 1 ) + $months;
					$new_year                   = intdiv( $total, 12 );
					$new_month                  = $total % 12 + 1;
					if ( $new_month < 1 ) {
						$new_month += 12;
						$new_year  -= 1;
					}
					// Clamp-and-normalize like SQLite (e.g. Jan 31 + 1 month = Mar 3).
					$max_day  = (int) gmdate( 't', gmmktime( 12, 0, 0, $new_month, 1, $new_year ) );
					$overflow = 0;
					$new_day  = $day;
					if ( $day > $max_day ) {
						$overflow = $day - $max_day;
						$new_day  = $max_day;
					}
					$result = self::gregorian_to_julian_day( $new_year, $new_month, $new_day ) + $overflow + $time_fraction;
					// Fractional months/years are applied as fractions of the unit.
					$fraction = $amount - (int) $amount;
					if ( 0.0 !== $fraction ) {
						$result += $fraction * ( 'year' === $unit ? 365.0 : 30.0 );
					}
					return $result;
			}
		}

		// Bare "±HH:MM[:SS]" time offsets.
		if ( preg_match( '/^([+-])(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $modifier, $m ) ) {
			$sign    = '-' === $m[1] ? -1 : 1;
			$seconds = (int) $m[2] * 3600 + (int) $m[3] * 60 + ( isset( $m[4] ) ? (int) $m[4] : 0 );
			return $julian + $sign * $seconds / 86400.0;
		}

		return null;
	}

	/**
	 * Render a Julian day number using an strftime-style format.
	 *
	 * @param  string $format The format string.
	 * @param  float  $julian The Julian day number.
	 * @return string         The formatted value.
	 */
	private function render_strftime( $format, $julian ) {
		list( $year, $month, $day ) = self::julian_day_to_gregorian( $julian );

		$day_fraction  = $julian + 0.5;
		$day_fraction  = $day_fraction - floor( $day_fraction );
		$total_seconds = $day_fraction * 86400.0;
		// Round to milliseconds to avoid floating point drift.
		$total_seconds = round( $total_seconds, 3 );
		if ( $total_seconds >= 86400.0 ) {
			$total_seconds = 0.0;
			// The rounding pushed us to the next day.
			list( $year, $month, $day ) = self::julian_day_to_gregorian( $julian + 0.000001 );
		}
		$hour    = (int) floor( $total_seconds / 3600 );
		$minute  = (int) floor( fmod( $total_seconds, 3600 ) / 60 );
		$second  = fmod( $total_seconds, 60 );
		$int_sec = (int) floor( $second );

		$timestamp = (int) round( ( $julian - self::JULIAN_EPOCH_UNIX ) * 86400 );
		$weekday   = (int) floor( fmod( $julian + 1.5, 7 ) ); // 0 = Sunday.
		if ( $weekday < 0 ) {
			$weekday += 7;
		}

		$day_of_year = (int) ( self::gregorian_to_julian_day( $year, $month, $day ) - self::gregorian_to_julian_day( $year, 1, 1 ) ) + 1;

		$result = '';
		$length = strlen( $format );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $format[ $i ];
			if ( '%' !== $char || $i + 1 >= $length ) {
				$result .= $char;
				continue;
			}
			$i += 1;
			switch ( $format[ $i ] ) {
				case 'd':
					$result .= sprintf( '%02d', $day );
					break;
				case 'e':
					$result .= sprintf( '%2d', $day );
					break;
				case 'f':
					$result .= sprintf( '%06.3f', $second );
					break;
				case 'F':
					$result .= sprintf( '%04d-%02d-%02d', $year, $month, $day );
					break;
				case 'H':
					$result .= sprintf( '%02d', $hour );
					break;
				case 'I':
					$hour12  = $hour % 12;
					$result .= sprintf( '%02d', 0 === $hour12 ? 12 : $hour12 );
					break;
				case 'j':
					$result .= sprintf( '%03d', $day_of_year );
					break;
				case 'J':
					$result .= rtrim( rtrim( sprintf( '%.8f', $julian ), '0' ), '.' );
					break;
				case 'k':
					$result .= sprintf( '%2d', $hour );
					break;
				case 'l':
					$hour12  = $hour % 12;
					$result .= sprintf( '%2d', 0 === $hour12 ? 12 : $hour12 );
					break;
				case 'm':
					$result .= sprintf( '%02d', $month );
					break;
				case 'M':
					$result .= sprintf( '%02d', $minute );
					break;
				case 'p':
					$result .= $hour >= 12 ? 'PM' : 'AM';
					break;
				case 'P':
					$result .= $hour >= 12 ? 'pm' : 'am';
					break;
				case 'R':
					$result .= sprintf( '%02d:%02d', $hour, $minute );
					break;
				case 's':
					$result .= (string) $timestamp;
					break;
				case 'S':
					$result .= sprintf( '%02d', $int_sec );
					break;
				case 'T':
					$result .= sprintf( '%02d:%02d:%02d', $hour, $minute, $int_sec );
					break;
				case 'u':
					$result .= (string) ( 0 === $weekday ? 7 : $weekday );
					break;
				case 'w':
					$result .= (string) $weekday;
					break;
				case 'W':
					// Week of year: Monday as the first day of the week.
					$jan1_weekday = (int) floor( fmod( self::gregorian_to_julian_day( $year, 1, 1 ) + 1.5, 7 ) );
					$offset       = ( $jan1_weekday + 6 ) % 7; // Days since Monday.
					$result      .= sprintf( '%02d', (int) ( ( $day_of_year + $offset - 1 ) / 7 ) );
					break;
				case 'Y':
					$result .= sprintf( '%04d', $year );
					break;
				case 'G':
					$result .= sprintf( '%04d', (int) gmdate( 'o', $timestamp ) );
					break;
				case 'V':
					$result .= gmdate( 'W', $timestamp );
					break;
				case '%':
					$result .= '%';
					break;
				default:
					$result .= '%' . $format[ $i ];
					break;
			}
		}
		return $result;
	}

	/**
	 * Convert a Gregorian date to a Julian day number (at noon).
	 *
	 * @param  int $year  The year.
	 * @param  int $month The month.
	 * @param  int $day   The day.
	 * @return float      The Julian day number at 00:00 of the date... shifted so
	 *                    that adding time fractions works (value at midnight).
	 */
	private static function gregorian_to_julian_day( $year, $month, $day ) {
		$a   = intdiv( 14 - $month, 12 );
		$y   = $year + 4800 - $a;
		$m   = $month + 12 * $a - 3;
		$jdn = $day + intdiv( 153 * $m + 2, 5 ) + 365 * $y + intdiv( $y, 4 ) - intdiv( $y, 100 ) + intdiv( $y, 400 ) - 32045;
		return $jdn - 0.5; // Midnight at the start of the date.
	}

	/**
	 * Convert a Julian day number to a Gregorian date.
	 *
	 * @param  float $julian The Julian day number.
	 * @return array         The year, month, and day.
	 */
	private static function julian_day_to_gregorian( $julian ) {
		$jdn = (int) floor( $julian + 0.5 );
		$a   = $jdn + 32044;
		$b   = intdiv( 4 * $a + 3, 146097 );
		$c   = $a - intdiv( 146097 * $b, 4 );
		$d   = intdiv( 4 * $c + 3, 1461 );
		$e   = $c - intdiv( 1461 * $d, 4 );
		$m   = intdiv( 5 * $e + 2, 153 );

		$day   = $e - intdiv( 153 * $m + 2, 5 ) + 1;
		$month = $m + 3 - 12 * intdiv( $m, 10 );
		$year  = 100 * $b + $d - 4800 + intdiv( $m, 10 );
		return array( $year, $month, $day );
	}
}
