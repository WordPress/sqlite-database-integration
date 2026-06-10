<?php

/*
 * The file contains the small WP_PHP_Engine_Blob value wrapper as well:
 *   phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
 */

/**
 * Value semantics for the pure-PHP database engine.
 *
 * This is a part of the pure-PHP database engine ("WP_PHP_Engine") — an
 * SQLite-compatible database engine implemented entirely in PHP.
 *
 * Values use a natural PHP representation:
 *   - SQL NULL    => PHP null
 *   - SQL INTEGER => PHP int
 *   - SQL REAL    => PHP float
 *   - SQL TEXT    => PHP string
 *   - SQL BLOB    => PHP string (treated as TEXT unless noted)
 *
 * This class implements SQLite's dynamic type system:
 *   https://www.sqlite.org/datatype3.html
 */
class WP_PHP_Engine_Values {
	const AFFINITY_TEXT    = 'TEXT';
	const AFFINITY_NUMERIC = 'NUMERIC';
	const AFFINITY_INTEGER = 'INTEGER';
	const AFFINITY_REAL    = 'REAL';
	const AFFINITY_BLOB    = 'BLOB';

	/**
	 * Determine the column affinity for a declared type.
	 *
	 * Implements the five rules from the SQLite documentation.
	 *
	 * @param  string|null $type The declared column type.
	 * @return string            The affinity.
	 */
	public static function affinity_for_type( $type ) {
		if ( null === $type || '' === $type ) {
			return self::AFFINITY_BLOB;
		}
		$type = strtoupper( $type );
		if ( false !== strpos( $type, 'INT' ) ) {
			return self::AFFINITY_INTEGER;
		}
		if ( false !== strpos( $type, 'CHAR' ) || false !== strpos( $type, 'CLOB' ) || false !== strpos( $type, 'TEXT' ) ) {
			return self::AFFINITY_TEXT;
		}
		if ( false !== strpos( $type, 'BLOB' ) ) {
			return self::AFFINITY_BLOB;
		}
		if ( false !== strpos( $type, 'REAL' ) || false !== strpos( $type, 'FLOA' ) || false !== strpos( $type, 'DOUB' ) ) {
			return self::AFFINITY_REAL;
		}
		return self::AFFINITY_NUMERIC;
	}

	/**
	 * Get the SQLite storage type name of a value (as in typeof()).
	 *
	 * @param  mixed $value The value.
	 * @return string       One of 'null', 'integer', 'real', 'text', 'blob'.
	 */
	public static function type_of( $value ) {
		if ( null === $value ) {
			return 'null';
		}
		if ( is_int( $value ) ) {
			return 'integer';
		}
		if ( is_float( $value ) ) {
			return 'real';
		}
		if ( $value instanceof WP_PHP_Engine_Blob ) {
			return 'blob';
		}
		return 'text';
	}

	/**
	 * Apply a column affinity to a value being stored or compared.
	 *
	 * @param  mixed  $value    The value.
	 * @param  string $affinity The affinity.
	 * @return mixed            The coerced value.
	 */
	public static function apply_affinity( $value, $affinity ) {
		if ( null === $value || $value instanceof WP_PHP_Engine_Blob ) {
			return $value;
		}
		switch ( $affinity ) {
			case self::AFFINITY_INTEGER:
			case self::AFFINITY_NUMERIC:
				if ( is_int( $value ) ) {
					return $value;
				}
				if ( is_float( $value ) ) {
					// Convert REAL to INTEGER when lossless.
					if ( (float) (int) $value === $value && abs( $value ) < PHP_INT_MAX ) {
						return (int) $value;
					}
					return $value;
				}
				if ( self::is_well_formed_number( $value ) ) {
					$number = self::text_to_number( $value );
					if ( is_float( $number ) && (float) (int) $number === $number && abs( $number ) < PHP_INT_MAX ) {
						return (int) $number;
					}
					return $number;
				}
				return $value;
			case self::AFFINITY_REAL:
				if ( is_int( $value ) ) {
					return (float) $value;
				}
				if ( is_float( $value ) ) {
					return $value;
				}
				if ( self::is_well_formed_number( $value ) ) {
					return (float) $value;
				}
				return $value;
			case self::AFFINITY_TEXT:
				if ( is_int( $value ) || is_float( $value ) ) {
					return self::to_text( $value );
				}
				return $value;
			default:
				return $value;
		}
	}

	/**
	 * Check whether a string is a well-formed numeric literal that SQLite
	 * would convert under NUMERIC affinity.
	 *
	 * @param  string $text The text value.
	 * @return bool         Whether the text is a well-formed number.
	 */
	public static function is_well_formed_number( $text ) {
		if ( ! is_string( $text ) ) {
			return false;
		}
		return 1 === preg_match( '/^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?\s*$/', $text );
	}

	/**
	 * Convert a text value to an int or float like SQLite's text-to-number.
	 *
	 * @param  string $text The text value.
	 * @return int|float    The numeric value.
	 */
	public static function text_to_number( $text ) {
		$trimmed = trim( $text );
		if ( 1 === preg_match( '/^[+-]?\d+$/', $trimmed ) ) {
			$float = (float) $trimmed;
			if ( $float >= -9223372036854775808.0 && $float <= 9223372036854775807.0 ) {
				$int = (int) $trimmed;
				if ( (float) $int === $float || strlen( ltrim( $trimmed, '+-0' ) ) < 19 ) {
					return $int;
				}
			}
			return $float;
		}
		return (float) $trimmed;
	}

	/**
	 * Coerce any value to a number for arithmetic (CAST AS NUMERIC prefix rules).
	 *
	 * @param  mixed $value The value.
	 * @return int|float    The numeric value.
	 */
	public static function to_numeric( $value ) {
		if ( null === $value ) {
			return 0;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		if ( $value instanceof WP_PHP_Engine_Blob ) {
			$value = $value->bytes;
		}
		// Parse the longest numeric prefix.
		if ( preg_match( '/^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?/', (string) $value, $m ) ) {
			$prefix = trim( $m[0] );
			if ( 1 === preg_match( '/^[+-]?\d+$/', $prefix ) ) {
				return self::text_to_number( $prefix );
			}
			return (float) $prefix;
		}
		return 0;
	}

	/**
	 * Render a value as TEXT using SQLite's conventions.
	 *
	 * @param  mixed $value The value.
	 * @return string       The text rendering.
	 */
	public static function to_text( $value ) {
		if ( null === $value ) {
			return '';
		}
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			return self::float_to_text( $value );
		}
		if ( $value instanceof WP_PHP_Engine_Blob ) {
			return $value->bytes;
		}
		return (string) $value;
	}

	/**
	 * Render a float the way SQLite renders REAL values as text.
	 *
	 * @param  float $value The float value.
	 * @return string       The text rendering.
	 */
	public static function float_to_text( $value ) {
		if ( is_infinite( $value ) ) {
			return $value > 0 ? 'Inf' : '-Inf';
		}
		if ( is_nan( $value ) ) {
			return ''; // SQLite renders NaN as NULL; callers handle that.
		}
		// PHP 8 renders floats with the shortest round-trip representation,
		// which matches SQLite's rendering in the common cases. SQLite always
		// includes a decimal point or an exponent for REAL values.
		$text = (string) $value;
		// Normalize exponent form: 1e+21 => 1.0e+21.
		if ( false !== stripos( $text, 'e' ) ) {
			list( $mantissa, $exponent ) = preg_split( '/[eE]/', $text );
			if ( false === strpos( $mantissa, '.' ) ) {
				$mantissa .= '.0';
			}
			$sign = '';
			if ( '+' === $exponent[0] || '-' === $exponent[0] ) {
				$sign     = '-' === $exponent[0] ? '-' : '+';
				$exponent = substr( $exponent, 1 );
			} else {
				$sign = '+';
			}
			$exponent = ltrim( $exponent, '0' );
			if ( '' === $exponent ) {
				$exponent = '0';
			}
			return $mantissa . 'e' . $sign . ( strlen( $exponent ) < 2 ? '0' . $exponent : $exponent );
		}
		if ( false === strpos( $text, '.' ) ) {
			$text .= '.0';
		}
		return $text;
	}

	/**
	 * Compare two values using SQLite ordering rules.
	 *
	 * NULL < numeric values < text values. Within numerics, numeric order.
	 * Within text, the given collation applies.
	 *
	 * @param  mixed  $a       The first value.
	 * @param  mixed  $b       The second value.
	 * @param  string $collate The collation for text comparison.
	 * @return int             -1, 0, or 1.
	 */
	public static function compare( $a, $b, $collate = 'BINARY' ) {
		$a_null = null === $a;
		$b_null = null === $b;
		if ( $a_null || $b_null ) {
			if ( $a_null && $b_null ) {
				return 0;
			}
			return $a_null ? -1 : 1;
		}

		$a_blob = $a instanceof WP_PHP_Engine_Blob;
		$b_blob = $b instanceof WP_PHP_Engine_Blob;
		if ( $a_blob || $b_blob ) {
			if ( $a_blob && $b_blob ) {
				$result = strcmp( $a->bytes, $b->bytes );
				return $result < 0 ? -1 : ( $result > 0 ? 1 : 0 );
			}
			return $a_blob ? 1 : -1;
		}

		$a_numeric = is_int( $a ) || is_float( $a );
		$b_numeric = is_int( $b ) || is_float( $b );
		if ( $a_numeric && $b_numeric ) {
			if ( $a == $b ) { // phpcs:ignore Universal.Operators.StrictComparisons -- Intentional cross-type numeric comparison, like in SQLite.
				return 0;
			}
			return $a < $b ? -1 : 1;
		}
		if ( $a_numeric ) {
			return -1;
		}
		if ( $b_numeric ) {
			return 1;
		}

		// Both text.
		if ( 'NOCASE' === $collate ) {
			$result = strcasecmp( $a, $b );
		} elseif ( 'RTRIM' === $collate ) {
			$result = strcmp( rtrim( $a ), rtrim( $b ) );
		} else {
			$result = strcmp( $a, $b );
		}
		return $result < 0 ? -1 : ( $result > 0 ? 1 : 0 );
	}

	/**
	 * Apply comparison affinity rules to a pair of operands.
	 *
	 * See "Type Conversions Prior To Comparison" in the SQLite docs.
	 *
	 * @param  mixed       $left           The left value.
	 * @param  mixed       $right          The right value.
	 * @param  string|null $left_affinity  The left expression affinity, if any.
	 * @param  string|null $right_affinity The right expression affinity, if any.
	 * @return array                       The two converted values.
	 */
	public static function apply_comparison_affinity( $left, $right, $left_affinity, $right_affinity ) {
		$left_is_numeric_affinity  = in_array(
			$left_affinity,
			array( self::AFFINITY_INTEGER, self::AFFINITY_REAL, self::AFFINITY_NUMERIC ),
			true
		);
		$right_is_numeric_affinity = in_array(
			$right_affinity,
			array( self::AFFINITY_INTEGER, self::AFFINITY_REAL, self::AFFINITY_NUMERIC ),
			true
		);

		if ( $left_is_numeric_affinity && ! $right_is_numeric_affinity ) {
			$right = self::apply_affinity( $right, self::AFFINITY_NUMERIC );
		} elseif ( $right_is_numeric_affinity && ! $left_is_numeric_affinity ) {
			$left = self::apply_affinity( $left, self::AFFINITY_NUMERIC );
		} elseif ( self::AFFINITY_TEXT === $left_affinity && null === $right_affinity ) {
			$right = self::apply_affinity( $right, self::AFFINITY_TEXT );
		} elseif ( self::AFFINITY_TEXT === $right_affinity && null === $left_affinity ) {
			$left = self::apply_affinity( $left, self::AFFINITY_TEXT );
		}
		return array( $left, $right );
	}

	/**
	 * Determine whether a value is truthy in a boolean context.
	 *
	 * @param  mixed $value The value.
	 * @return bool         Whether the value is truthy.
	 */
	public static function is_truthy( $value ) {
		if ( null === $value ) {
			return false;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 0.0 != $value; // phpcs:ignore Universal.Operators.StrictComparisons -- Intentional cross-type numeric comparison, like in SQLite.
		}
		return 0.0 != self::to_numeric( $value ); // phpcs:ignore Universal.Operators.StrictComparisons -- Intentional cross-type numeric comparison, like in SQLite.
	}

	/**
	 * Match a LIKE pattern.
	 *
	 * SQLite LIKE is case-insensitive for ASCII characters.
	 *
	 * @param  string      $pattern The LIKE pattern.
	 * @param  string      $value   The value.
	 * @param  string|null $escape  The escape character.
	 * @return bool                 Whether the value matches.
	 */
	public static function like_match( $pattern, $value, $escape = null ) {
		$regex  = '';
		$length = strlen( $pattern );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $pattern[ $i ];
			if ( null !== $escape && $char === $escape && $i + 1 < $length ) {
				$i     += 1;
				$regex .= preg_quote( $pattern[ $i ], '/' );
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
		return 1 === preg_match( '/^' . $regex . '$/isu', $value )
			|| 1 === preg_match( '/^' . $regex . '$/is', $value );
	}

	/**
	 * Match a GLOB pattern (case-sensitive, with * ? [...] wildcards).
	 *
	 * @param  string $pattern The GLOB pattern.
	 * @param  string $value   The value.
	 * @return bool            Whether the value matches.
	 */
	public static function glob_match( $pattern, $value ) {
		$regex  = '';
		$length = strlen( $pattern );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $pattern[ $i ];
			if ( '*' === $char ) {
				$regex .= '.*';
			} elseif ( '?' === $char ) {
				$regex .= '.';
			} elseif ( '[' === $char ) {
				// Character class: copy up to the closing bracket.
				$end = strpos( $pattern, ']', $i + 2 );
				if ( false === $end ) {
					$regex .= preg_quote( $char, '/' );
					continue;
				}
				$class = substr( $pattern, $i + 1, $end - $i - 1 );
				if ( '' !== $class && '^' === $class[0] ) {
					$class = '^' . str_replace( array( '\\' ), array( '\\\\' ), substr( $class, 1 ) );
				} else {
					$class = str_replace( array( '\\' ), array( '\\\\' ), $class );
				}
				$regex .= '[' . $class . ']';
				$i      = $end;
			} else {
				$regex .= preg_quote( $char, '/' );
			}
		}
		return 1 === preg_match( '/^' . $regex . '$/s', $value );
	}

	/**
	 * Cast a value using CAST() semantics.
	 *
	 * @param  mixed  $value The value.
	 * @param  string $type  The target type name.
	 * @return mixed         The cast value.
	 */
	public static function cast( $value, $type ) {
		if ( null === $value ) {
			return null;
		}
		$affinity = self::affinity_for_type( $type );
		if ( self::AFFINITY_BLOB === $affinity && false !== stripos( (string) $type, 'BLOB' ) ) {
			return $value instanceof WP_PHP_Engine_Blob ? $value : new WP_PHP_Engine_Blob( self::to_text( $value ) );
		}
		if ( $value instanceof WP_PHP_Engine_Blob ) {
			$value = $value->bytes;
		}
		switch ( $affinity ) {
			case self::AFFINITY_INTEGER:
				if ( is_int( $value ) ) {
					return $value;
				}
				if ( is_float( $value ) ) {
					if ( $value >= 9223372036854775807.0 ) {
						return PHP_INT_MAX;
					}
					if ( $value <= -9223372036854775808.0 ) {
						return PHP_INT_MIN;
					}
					return (int) $value;
				}
				// Longest integer prefix (through a float prefix truncates).
				$number = self::to_numeric( $value );
				return is_float( $number ) ? (int) $number : $number;
			case self::AFFINITY_REAL:
				return (float) self::to_numeric( $value );
			case self::AFFINITY_NUMERIC:
				if ( is_int( $value ) || is_float( $value ) ) {
					return $value;
				}
				if ( self::is_well_formed_number( $value ) ) {
					$number = self::text_to_number( $value );
					if ( is_float( $number ) && (float) (int) $number === $number && abs( $number ) < PHP_INT_MAX ) {
						return (int) $number;
					}
					return $number;
				}
				return self::to_numeric( $value );
			case self::AFFINITY_TEXT:
			case self::AFFINITY_BLOB:
				return self::to_text( $value );
		}
		return $value;
	}
}

/**
 * A BLOB value wrapper, distinguishing SQL BLOBs from TEXT.
 */
class WP_PHP_Engine_Blob {
	/**
	 * The raw bytes.
	 *
	 * @var string
	 */
	public $bytes;

	/**
	 * Constructor.
	 *
	 * @param string $bytes The raw bytes.
	 */
	public function __construct( $bytes ) {
		$this->bytes = $bytes;
	}
}
