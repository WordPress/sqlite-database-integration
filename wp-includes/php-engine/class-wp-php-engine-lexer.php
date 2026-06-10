<?php

/**
 * A tokenizer for the SQLite SQL dialect.
 *
 * This is a part of the pure-PHP database engine ("WP_PHP_Engine") — an
 * SQLite-compatible database engine implemented entirely in PHP, with no
 * dependency on the pdo_sqlite or sqlite3 extensions.
 *
 * The tokenizer converts an SQL string into a list of tokens, handling:
 *   - line comments (--) and block comments,
 *   - single-quoted string literals with '' escaping,
 *   - quoted identifiers (`backtick`, "double-quoted", [bracketed]),
 *   - numeric literals (integer, float, hex),
 *   - blob literals (X'...'),
 *   - positional (?, ?NNN) and named (:name, @name, $name) parameters,
 *   - operators and punctuation.
 */
class WP_PHP_Engine_Lexer {
	const TYPE_KEYWORD    = 'keyword';
	const TYPE_IDENTIFIER = 'identifier';
	const TYPE_STRING     = 'string';
	const TYPE_NUMBER     = 'number';
	const TYPE_BLOB       = 'blob';
	const TYPE_PARAMETER  = 'parameter';
	const TYPE_OPERATOR   = 'operator';

	/**
	 * Words that are treated as keywords when not quoted.
	 *
	 * This list intentionally contains only the keywords that the parser
	 * dispatches on. Unquoted words that are not in this list are treated
	 * as identifiers, which mirrors how SQLite treats non-reserved words.
	 *
	 * @var array<string, bool>
	 */
	private static $keywords = array(
		'ABORT'             => true,
		'ACTION'            => true,
		'ADD'               => true,
		'AFTER'             => true,
		'ALL'               => true,
		'ALTER'             => true,
		'ANALYZE'           => true,
		'AND'               => true,
		'AS'                => true,
		'ASC'               => true,
		'AUTOINCREMENT'     => true,
		'BEFORE'            => true,
		'BEGIN'             => true,
		'BETWEEN'           => true,
		'BY'                => true,
		'CASCADE'           => true,
		'CASE'              => true,
		'CAST'              => true,
		'CHECK'             => true,
		'COLLATE'           => true,
		'COLUMN'            => true,
		'COMMIT'            => true,
		'CONFLICT'          => true,
		'CONSTRAINT'        => true,
		'CREATE'            => true,
		'CROSS'             => true,
		'CURRENT_DATE'      => true,
		'CURRENT_TIME'      => true,
		'CURRENT_TIMESTAMP' => true,
		'DEFAULT'           => true,
		'DEFERRABLE'        => true,
		'DEFERRED'          => true,
		'DELETE'            => true,
		'DESC'              => true,
		'DISTINCT'          => true,
		'DO'                => true,
		'DROP'              => true,
		'EACH'              => true,
		'ELSE'              => true,
		'END'               => true,
		'ESCAPE'            => true,
		'EXCEPT'            => true,
		'EXCLUSIVE'         => true,
		'EXISTS'            => true,
		'FALSE'             => true,
		'FILTER'            => true,
		'FOR'               => true,
		'FOREIGN'           => true,
		'FROM'              => true,
		'FULL'              => true,
		'GLOB'              => true,
		'GROUP'             => true,
		'HAVING'            => true,
		'IF'                => true,
		'IGNORE'            => true,
		'IMMEDIATE'         => true,
		'IN'                => true,
		'INDEX'             => true,
		'INDEXED'           => true,
		'INITIALLY'         => true,
		'INNER'             => true,
		'INSERT'            => true,
		'INTERSECT'         => true,
		'INTO'              => true,
		'IS'                => true,
		'ISNULL'            => true,
		'JOIN'              => true,
		'KEY'               => true,
		'LEFT'              => true,
		'LIKE'              => true,
		'LIMIT'             => true,
		'MATCH'             => true,
		'NATURAL'           => true,
		'NO'                => true,
		'NOT'               => true,
		'NOTHING'           => true,
		'NOTNULL'           => true,
		'NULL'              => true,
		'OF'                => true,
		'OFFSET'            => true,
		'ON'                => true,
		'OR'                => true,
		'ORDER'             => true,
		'OUTER'             => true,
		'OVER'              => true,
		'PARTITION'         => true,
		'PRAGMA'            => true,
		'PRIMARY'           => true,
		'RAISE'             => true,
		'RECURSIVE'         => true,
		'REFERENCES'        => true,
		'REGEXP'            => true,
		'REINDEX'           => true,
		'RELEASE'           => true,
		'RENAME'            => true,
		'REPLACE'           => true,
		'RESTRICT'          => true,
		'RIGHT'             => true,
		'ROLLBACK'          => true,
		'ROW'               => true,
		'ROWS'              => true,
		'SAVEPOINT'         => true,
		'SELECT'            => true,
		'SET'               => true,
		'STRICT'            => true,
		'TABLE'             => true,
		'TEMP'              => true,
		'TEMPORARY'         => true,
		'THEN'              => true,
		'TO'                => true,
		'TRANSACTION'       => true,
		'TRIGGER'           => true,
		'TRUE'              => true,
		'UNION'             => true,
		'UNIQUE'            => true,
		'UPDATE'            => true,
		'USING'             => true,
		'VACUUM'            => true,
		'VALUES'            => true,
		'VIEW'              => true,
		'VIRTUAL'           => true,
		'WHEN'              => true,
		'WHERE'             => true,
		'WINDOW'            => true,
		'WITH'              => true,
		'WITHOUT'           => true,
	);

	/**
	 * Tokenize an SQL string.
	 *
	 * Each token is a tuple: array( type, normalized-value, raw-value ).
	 * For keywords, the normalized value is the uppercase keyword.
	 * For identifiers, the normalized value is the unquoted identifier.
	 * For strings/blobs, the normalized value is the decoded value.
	 * For numbers, the normalized value is an int or a float.
	 * For parameters, the normalized value is the parameter name or null
	 * for plain positional "?" parameters.
	 *
	 * @param  string $sql The SQL string to tokenize.
	 * @return array       The list of tokens.
	 * @throws WP_PHP_Engine_SQL_Exception When the input cannot be tokenized.
	 */
	public static function tokenize( $sql ) {
		$tokens = array();
		$length = strlen( $sql );
		$i      = 0;

		while ( $i < $length ) {
			$char = $sql[ $i ];

			// Skip whitespace.
			if ( ' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char || "\v" === $char || "\f" === $char ) {
				$i += 1;
				continue;
			}

			// Skip line comments.
			if ( '-' === $char && $i + 1 < $length && '-' === $sql[ $i + 1 ] ) {
				$end = strpos( $sql, "\n", $i );
				$i   = false === $end ? $length : $end + 1;
				continue;
			}

			// Skip block comments.
			if ( '/' === $char && $i + 1 < $length && '*' === $sql[ $i + 1 ] ) {
				$end = strpos( $sql, '*/', $i + 2 );
				$i   = false === $end ? $length : $end + 2;
				continue;
			}

			// String literals.
			if ( "'" === $char ) {
				$start    = $i;
				$value    = self::read_quoted( $sql, $i, "'" );
				$tokens[] = array( self::TYPE_STRING, $value, $start, $i );
				continue;
			}

			// Quoted identifiers.
			if ( '`' === $char || '"' === $char ) {
				$start    = $i;
				$value    = self::read_quoted( $sql, $i, $char );
				$tokens[] = array( self::TYPE_IDENTIFIER, $value, $start, $i );
				continue;
			}
			if ( '[' === $char ) {
				$end = strpos( $sql, ']', $i + 1 );
				if ( false === $end ) {
					throw new WP_PHP_Engine_SQL_Exception( 'unrecognized token: "["' );
				}
				$tokens[] = array( self::TYPE_IDENTIFIER, substr( $sql, $i + 1, $end - $i - 1 ), $i, $end + 1 );
				$i        = $end + 1;
				continue;
			}

			// Numbers (and the ".5" form).
			if ( ( $char >= '0' && $char <= '9' ) || ( '.' === $char && $i + 1 < $length && $sql[ $i + 1 ] >= '0' && $sql[ $i + 1 ] <= '9' ) ) {
				// Hex literals.
				if ( '0' === $char && $i + 1 < $length && ( 'x' === $sql[ $i + 1 ] || 'X' === $sql[ $i + 1 ] ) ) {
					$j = $i + 2;
					while ( $j < $length && ctype_xdigit( $sql[ $j ] ) ) {
						$j += 1;
					}
					$tokens[] = array( self::TYPE_NUMBER, hexdec( substr( $sql, $i + 2, $j - $i - 2 ) ), $i, $j );
					$i        = $j;
					continue;
				}
				$j        = $i;
				$is_float = false;
				while ( $j < $length && $sql[ $j ] >= '0' && $sql[ $j ] <= '9' ) {
					$j += 1;
				}
				if ( $j < $length && '.' === $sql[ $j ] ) {
					$is_float = true;
					$j       += 1;
					while ( $j < $length && $sql[ $j ] >= '0' && $sql[ $j ] <= '9' ) {
						$j += 1;
					}
				}
				if ( $j < $length && ( 'e' === $sql[ $j ] || 'E' === $sql[ $j ] ) ) {
					$k = $j + 1;
					if ( $k < $length && ( '+' === $sql[ $k ] || '-' === $sql[ $k ] ) ) {
						$k += 1;
					}
					if ( $k < $length && $sql[ $k ] >= '0' && $sql[ $k ] <= '9' ) {
						$is_float = true;
						$j        = $k;
						while ( $j < $length && $sql[ $j ] >= '0' && $sql[ $j ] <= '9' ) {
							$j += 1;
						}
					}
				}
				$raw = substr( $sql, $i, $j - $i );
				if ( $is_float ) {
					$value = (float) $raw;
				} else {
					// Integers that overflow PHP int become floats (like SQLite REALs).
					$value = (float) (int) $raw == (float) $raw ? (int) $raw : (float) $raw; // phpcs:ignore Universal.Operators.StrictComparisons -- Intentional cross-type numeric comparison, like in SQLite.
				}
				$tokens[] = array( self::TYPE_NUMBER, $value, $i, $j );
				$i        = $j;
				continue;
			}

			// Blob literals and identifiers/keywords.
			if ( ctype_alpha( $char ) || '_' === $char ) {
				if ( ( 'x' === $char || 'X' === $char ) && $i + 1 < $length && "'" === $sql[ $i + 1 ] ) {
					$start    = $i;
					$i       += 1;
					$value    = self::read_quoted( $sql, $i, "'" );
					$tokens[] = array( self::TYPE_BLOB, pack( 'H*', $value ), $start, $i );
					continue;
				}
				$j = $i + 1;
				while ( $j < $length && ( ctype_alnum( $sql[ $j ] ) || '_' === $sql[ $j ] || '$' === $sql[ $j ] ) ) {
					$j += 1;
				}
				$word  = substr( $sql, $i, $j - $i );
				$upper = strtoupper( $word );
				if ( isset( self::$keywords[ $upper ] ) ) {
					$tokens[] = array( self::TYPE_KEYWORD, $upper, $i, $j );
				} else {
					$tokens[] = array( self::TYPE_IDENTIFIER, $word, $i, $j );
				}
				$i = $j;
				continue;
			}

			// Parameters.
			if ( '?' === $char ) {
				$j = $i + 1;
				while ( $j < $length && $sql[ $j ] >= '0' && $sql[ $j ] <= '9' ) {
					$j += 1;
				}
				$name     = $j > $i + 1 ? substr( $sql, $i + 1, $j - $i - 1 ) : null;
				$tokens[] = array( self::TYPE_PARAMETER, $name, $i, $j );
				$i        = $j;
				continue;
			}
			if ( ':' === $char || '@' === $char || '$' === $char ) {
				$j = $i + 1;
				while ( $j < $length && ( ctype_alnum( $sql[ $j ] ) || '_' === $sql[ $j ] ) ) {
					$j += 1;
				}
				if ( $j === $i + 1 ) {
					throw new WP_PHP_Engine_SQL_Exception( sprintf( 'unrecognized token: "%s"', $char ) );
				}
				$tokens[] = array( self::TYPE_PARAMETER, substr( $sql, $i, $j - $i ), $i, $j );
				$i        = $j;
				continue;
			}

			// Operators.
			$two = $i + 1 < $length ? substr( $sql, $i, 2 ) : '';
			if ( '||' === $two || '<<' === $two || '>>' === $two || '<=' === $two || '>=' === $two || '==' === $two || '!=' === $two || '<>' === $two ) {
				$tokens[] = array( self::TYPE_OPERATOR, $two, $i, $i + 2 );
				$i       += 2;
				continue;
			}
			if ( false !== strpos( '()+-*/%,;=<>&|~.', $char ) ) {
				$tokens[] = array( self::TYPE_OPERATOR, $char, $i, $i + 1 );
				$i       += 1;
				continue;
			}

			throw new WP_PHP_Engine_SQL_Exception( sprintf( 'unrecognized token: "%s"', $char ) );
		}

		return $tokens;
	}

	/**
	 * Read a quoted region with doubled-quote escaping.
	 *
	 * @param  string $sql   The SQL string.
	 * @param  int    $i     The current position (at the opening quote); updated to after the closing quote.
	 * @param  string $quote The quote character.
	 * @return string        The decoded value.
	 * @throws WP_PHP_Engine_SQL_Exception When the quoted region is not terminated.
	 */
	private static function read_quoted( $sql, &$i, $quote ) {
		$length = strlen( $sql );
		$value  = '';
		$j      = $i + 1;
		while ( true ) {
			$end = strpos( $sql, $quote, $j );
			if ( false === $end ) {
				throw new WP_PHP_Engine_SQL_Exception( 'unterminated quoted string' );
			}
			$value .= substr( $sql, $j, $end - $j );
			if ( $end + 1 < $length && $sql[ $end + 1 ] === $quote ) {
				$value .= $quote;
				$j      = $end + 2;
				continue;
			}
			$i = $end + 1;
			return $value;
		}
	}
}
