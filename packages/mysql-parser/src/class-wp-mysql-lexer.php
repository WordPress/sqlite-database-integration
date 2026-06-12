<?php

/**
 * MySQL lexer.
 *
 * An exhaustive lexer for the MySQL SQL dialect. It scans the provided SQL
 * input payload and generates MySQL token objects in the vocabulary of the
 * official MySQL grammar: token ids are the grammar's own token numbers, and
 * the keyword table is generated from MySQL's keyword list (sql/lex.h) — see
 * the generated WP_MySQL_Tokens interface.
 *
 * The lexer is implemented with zero dependencies, it doesn't require any PHP
 * extensions, and it doesn't use PCRE or any other regular expression engines.
 *
 * The scanner structure is based on the MySQL Workbench lexer grammar.
 * See:
 *   https://github.com/mysql/mysql-workbench/blob/8.0.38/library/parsers/grammars/MySQLLexer.g4
 *   https://github.com/mysql/mysql-workbench/blob/8.0.38/library/parsers/mysql/MySQLBaseLexer.cpp
 */
class WP_MySQL_Lexer implements WP_MySQL_Tokens {
	/**
	 * The SQL modes that affect the lexer behavior.
	 *
	 * These values are intended to be used in a bitmask. See "$this->sql_modes".
	 * The list of the SQL modes is not exhaustive. Only the ones that influence
	 * the lexer behavior are included in this list.
	 *
	 * See:
	 *   https://dev.mysql.com/doc/refman/8.4/en/sql-mode.html
	 */
	const SQL_MODE_HIGH_NOT_PRECEDENCE  = 1;
	const SQL_MODE_PIPES_AS_CONCAT      = 2;
	const SQL_MODE_IGNORE_SPACE         = 4;
	const SQL_MODE_NO_BACKSLASH_ESCAPES = 8;
	const SQL_MODE_ANSI_QUOTES          = 16;

	/**
	 * Character masks for frequently used character classes.
	 *
	 * These are intended to be used with "strspn()" and "strcspn()" functions
	 * for fast character class matching in the SQL payload.
	 */
	const WHITESPACE_MASK = " \t\n\r\f";
	const DIGIT_MASK      = '0123456789';
	const HEX_DIGIT_MASK  = '0123456789abcdefABCDEF';

	/**
	 * Internal scanner token types.
	 *
	 * These pseudo-tokens are produced and consumed inside the lexer only and
	 * are never part of the emitted token stream, which uses the grammar token
	 * numbers from the generated WP_MySQL_Tokens interface. They are negative
	 * so they can never collide with a grammar token number.
	 */
	const EOF        = -1;
	const WHITESPACE = -2;

	// Comments.
	const COMMENT             = -3;
	const MYSQL_COMMENT_START = -4;
	const MYSQL_COMMENT_END   = -5;

	// "@"-prefixed forms that remaining_tokens() decomposes into grammar tokens.
	const AT_TEXT_SUFFIX    = -6;
	const AT_AT_SIGN_SYMBOL = -7;

	/**
	 * Identifier-like strings that may represent underscore-prefixed charset names.
	 *
	 * Includes charsets from both MySQL 5 and 8; via "SHOW CHARACTER SET"/docs:
	 *   https://dev.mysql.com/doc/refman/5.7/en/charset-charsets.html
	 *   https://dev.mysql.com/doc/refman/8.4/en/charset-charsets.html
	 *
	 * @TODO: Make the list respect the MySQL version. The _utf8 underscore charset
	 *        exists only on MySQL 5, and maybe some others are version-dependant too.
	 *        We can check this using SHOW CHARACTER SET on different MySQL versions.
	 */
	const UNDERSCORE_CHARSETS = array(
		'_armscii8' => true,
		'_ascii'    => true,
		'_big5'     => true,
		'_binary'   => true,
		'_cp1250'   => true,
		'_cp1251'   => true,
		'_cp1256'   => true,
		'_cp1257'   => true,
		'_cp850'    => true,
		'_cp852'    => true,
		'_cp866'    => true,
		'_cp932'    => true,
		'_dec8'     => true,
		'_eucjpms'  => true,
		'_euckr'    => true,
		'_gb18030'  => true,
		'_gb2312'   => true,
		'_gbk'      => true,
		'_geostd8'  => true,
		'_greek'    => true,
		'_hebrew'   => true,
		'_hp8'      => true,
		'_keybcs2'  => true,
		'_koi8r'    => true,
		'_koi8u'    => true,
		'_latin1'   => true,
		'_latin2'   => true,
		'_latin5'   => true,
		'_latin7'   => true,
		'_macce'    => true,
		'_macroman' => true,
		'_sjis'     => true,
		'_swe7'     => true,
		'_tis620'   => true,
		'_ucs2'     => true,
		'_ujis'     => true,
		'_utf16'    => true,
		'_utf16le'  => true,
		'_utf32'    => true,
		'_utf8'     => true,
		'_utf8mb3'  => true,
		'_utf8mb4'  => true,
	);

	/**
	 * The SQL payload to tokenize.
	 *
	 * @var string
	 */
	private $sql;

	/**
	 * Byte length of the SQL payload.
	 *
	 * @var int
	 */
	private $sql_length;

	/**
	 * The version of the MySQL server that the SQL payload is intended for.
	 *
	 * This is used to determine which tokens are valid for the given MySQL
	 * version, and how some tokens should be interpreted.
	 *
	 * @var int
	 */
	private $mysql_version;

	/**
	 * The SQL modes that should be considered active during tokenization.
	 *
	 * This is an integer that represents currently active SQL modes as a bitmask.
	 * The SQL modes are defined as "SQL_MODE_"-prefixed constants in this class.
	 * The list of the SQL modes isn't exhaustive, as only some affect tokenization.
	 *
	 * @var int
	 */
	private $sql_modes = 0;

	/**
	 * How many bytes from the original SQL payload have been read and tokenized.
	 *
	 * This is an internal cursor that is used to track the current position in
	 * the SQL payload during tokenization. When used as an index in the SQL
	 * payload, it points to the next byte to read.
	 *
	 * @var int
	 */
	private $bytes_already_read = 0;

	/**
	 * Byte offset in the SQL payload where current token starts.
	 *
	 * This is used to extract the token bytes after the token is processed.
	 * The bytes of the current token are represented by "$this->sql" in range
	 * from "$this->token_starts_at" to "$this->bytes_already_read - 1".
	 *
	 * @var int
	 */
	private $token_starts_at = 0;

	/**
	 * The type of the current token.
	 *
	 * When a token is successfully recognized and read, this value is set to the
	 * constant representing the token type. When no token was read yet, or the
	 * end of the SQL payload or an invalid token is reached, this value is null.
	 *
	 * @var int|null
	 */
	private $token_type;

	/**
	 * Whether the tokenizer is inside an active MySQL-specific comment.
	 *
	 * MySQL supports a special comment syntax whose content is recognized as
	 * a comment by most database engines, but can be treated as SQL by MySQL:
	 *
	 *  1. /*! ...  - The content is treated as SQL.
	 *  2. /*!12345 - The content is treated as SQL when "MySQL version >= 12345".
	 *
	 * @var bool
	 */
	private $in_mysql_comment = false;

	/**
	 * @param string $sql The SQL payload to tokenize.
	 * @param int $mysql_version The version of the MySQL server that the SQL payload is intended for.
	 * @param string[] $sql_modes The SQL modes that should be considered active during tokenization.
	 */
	public function __construct(
		string $sql,
		int $mysql_version = 80038,
		array $sql_modes = array()
	) {
		$this->sql           = $sql;
		$this->sql_length    = strlen( $sql );
		$this->mysql_version = $mysql_version;

		foreach ( $sql_modes as $sql_mode ) {
			$sql_mode = strtoupper( $sql_mode );
			if ( 'HIGH_NOT_PRECEDENCE' === $sql_mode ) {
				$this->sql_modes |= self::SQL_MODE_HIGH_NOT_PRECEDENCE;
			} elseif ( 'PIPES_AS_CONCAT' === $sql_mode ) {
				$this->sql_modes |= self::SQL_MODE_PIPES_AS_CONCAT;
			} elseif ( 'IGNORE_SPACE' === $sql_mode ) {
				$this->sql_modes |= self::SQL_MODE_IGNORE_SPACE;
			} elseif ( 'NO_BACKSLASH_ESCAPES' === $sql_mode ) {
				$this->sql_modes |= self::SQL_MODE_NO_BACKSLASH_ESCAPES;
			} elseif ( 'ANSI_QUOTES' === $sql_mode ) {
				$this->sql_modes |= self::SQL_MODE_ANSI_QUOTES;
			}
		}
	}

	/**
	 * Read the next token from the SQL payload and return it as a token object.
	 *
	 * This method reads bytes from the SQL payload until a token is recognized.
	 * It starts from "$this->sql[ $this->bytes_already_read ]", advances the
	 * number of bytes read, and returns a boolean indicating whether a token
	 * was successfully recognized and read. When the end of the SQL payload
	 * or an invalid token is reached, the method returns false.
	 *
	 * @return bool Whether a token was successfully recognized and read.
	 */
	public function next_token(): bool {
		// We already reached the end of the SQL payload or an invalid token.
		// Don't attempt to read any more bytes, and bail out immediately.
		if (
			self::EOF === $this->token_type
			|| ( null === $this->token_type && $this->bytes_already_read > 0 )
		) {
			$this->token_type = null;
			return false;
		}

		// Skip leading whitespace inline for optimal performance.
		$this->bytes_already_read += strspn( $this->sql, self::WHITESPACE_MASK, $this->bytes_already_read );

		do {
			$this->token_starts_at = $this->bytes_already_read;
			$this->token_type      = $this->read_next_token();
		} while (
			self::WHITESPACE === $this->token_type
			|| self::COMMENT === $this->token_type
			|| self::MYSQL_COMMENT_START === $this->token_type
			|| self::MYSQL_COMMENT_END === $this->token_type
		);

		// Invalid input.
		if ( null === $this->token_type ) {
			return false;
		}
		return true;
	}

	/**
	 * Return the current token represented as a WP_MySQL_Token object.
	 *
	 * When no token was read yet, or the end of the SQL payload or an invalid
	 * token is reached, the method returns null.
	 *
	 * @TODO: Consider referential stability ($lexer->get_token() === $lexer->get_token()),
	 *        or separate getters for the token type and token bytes (no token objects).
	 *
	 * @return WP_MySQL_Token|null An object representing the next recognized token or null.
	 */
	public function get_token(): ?WP_MySQL_Token {
		if ( null === $this->token_type ) {
			return null;
		}
		return new WP_MySQL_Token(
			$this->token_type,
			$this->token_starts_at,
			$this->bytes_already_read - $this->token_starts_at,
			$this->sql,
			$this->is_sql_mode_active( self::SQL_MODE_NO_BACKSLASH_ESCAPES )
		);
	}

	/**
	 * Read all remaining tokens from the SQL payload as a Bison token stream.
	 *
	 * This tokenizes the whole payload at once. Three places where the scanner's
	 * token model differs from MySQL's grammar are reconciled in a single pass:
	 *
	 *   - "@name" (a user variable or host part) is split into '@' IDENT, and
	 *     "@@" (a system variable prefix) into '@' '@', because MySQL's grammar
	 *     treats the "@" sign as its own terminal.
	 *   - A bare "@" with no name character after it is followed by a zero-length
	 *     IDENT, mirroring MySQL's empty LEX_HOSTNAME token (which makes "user1@"
	 *     and "SELECT @" valid) — except before a quote, where the quoted form
	 *     ("@'name'") supplies the name itself.
	 *   - "WITH ROLLUP" is contracted into a single WITH_ROLLUP_SYM terminal,
	 *     matching MySQL's lexer; a lone WITH is emitted unchanged.
	 *
	 * The stream is terminated with END_OF_INPUT followed by Bison's end marker
	 * ($end), the two terminals the start rule expects. When an invalid token is
	 * reached, tokenizing stops and the partial sequence of valid tokens (without
	 * terminators) is returned, mirroring the lexer's native behaviour.
	 *
	 * @return WP_MySQL_Token[] The remaining tokens as a Bison token stream.
	 */
	public function remaining_tokens(): array {
		$sql = $this->sql;
		$nbe = $this->is_sql_mode_active( self::SQL_MODE_NO_BACKSLASH_ESCAPES );
		$out = array();

		// "WITH" is held back one token so a following "ROLLUP" can be contracted.
		$with_start  = -1;
		$with_length = 0;

		while ( $this->next_token() ) {
			$type = $this->token_type;
			if ( self::EOF === $type ) {
				break;
			}
			$start  = $this->token_starts_at;
			$length = $this->bytes_already_read - $start;

			// Resolve a pending "WITH": contract "WITH ROLLUP", else emit "WITH".
			if ( $with_start >= 0 ) {
				if ( self::ROLLUP_SYMBOL === $type ) {
					$out[]      = new WP_MySQL_Token( self::WITH_ROLLUP_SYMBOL, $with_start, $start + $length - $with_start, $sql, $nbe );
					$with_start = -1;
					continue;
				}
				$out[]      = new WP_MySQL_Token( self::WITH_SYMBOL, $with_start, $with_length, $sql, $nbe );
				$with_start = -1;
			}

			switch ( $type ) {
				case self::WITH_SYMBOL:
					$with_start  = $start;
					$with_length = $length;
					break;
				case self::AT_TEXT_SUFFIX:
					$out[] = new WP_MySQL_Token( self::AT_SIGN_SYMBOL, $start, 1, $sql, $nbe );
					$out[] = new WP_MySQL_Token( self::IDENTIFIER, $start + 1, $length - 1, $sql, $nbe );
					break;
				case self::AT_AT_SIGN_SYMBOL:
					$out[] = new WP_MySQL_Token( self::AT_SIGN_SYMBOL, $start, 1, $sql, $nbe );
					$out[] = new WP_MySQL_Token( self::AT_SIGN_SYMBOL, $start + 1, 1, $sql, $nbe );
					break;
				case self::AT_SIGN_SYMBOL:
					$out[] = new WP_MySQL_Token( self::AT_SIGN_SYMBOL, $start, $length, $sql, $nbe );
					$next  = $sql[ $this->bytes_already_read ] ?? '';
					if ( "'" !== $next && '"' !== $next && '`' !== $next ) {
						// Empty user-variable name or host part (empty LEX_HOSTNAME).
						$out[] = new WP_MySQL_Token( self::IDENTIFIER, $start + 1, 0, $sql, $nbe );
					}
					break;
				default:
					$out[] = new WP_MySQL_Token( $type, $start, $length, $sql, $nbe );
			}
		}

		// Invalid input: return the partial stream (with a flushed pending WITH,
		// which was validly lexed) without terminators.
		if ( null === $this->token_type ) {
			if ( $with_start >= 0 ) {
				$out[] = new WP_MySQL_Token( self::WITH_SYMBOL, $with_start, $with_length, $sql, $nbe );
			}
			return $out;
		}

		if ( $with_start >= 0 ) {   // Statement ended on a lone "WITH".
			$out[] = new WP_MySQL_Token( self::WITH_SYMBOL, $with_start, $with_length, $sql, $nbe );
		}

		$end   = $this->bytes_already_read;
		$out[] = new WP_MySQL_Token( self::END_OF_INPUT, $end, 0, $sql, $nbe );
		$out[] = new WP_MySQL_Token( self::END_MARKER, $end, 0, $sql, $nbe );
		return $out;
	}

	/**
	 * The version of the MySQL server that the SQL payload is intended for.
	 *
	 * This represents the MySQL server version that the lexer is set up to
	 * consider when tokenizing the SQL payload.
	 *
	 * @return int The MySQL server version that the lexer is set up to consider.
	 */
	public function get_mysql_version(): int {
		return $this->mysql_version;
	}

	/**
	 * Whether an SQL mode is set to be considered as active during tokenization.
	 * The SQL modes are defined as "SQL_MODE_"-prefixed constants in this class.
	 *
	 * @param int $mode The SQL mode to check, an "SQL_MODE_"-prefixed constant.
	 * @return bool Whether the given SQL mode is active.
	 */
	public function is_sql_mode_active( int $mode ): bool {
		return ( $this->sql_modes & $mode ) !== 0;
	}

	/**
	 * Get the numeric token ID for a given token name.
	 *
	 * @param string $token_name The name of the token.
	 * @return int|null The token ID for the given token name; null when not found.
	 */
	public static function get_token_id( string $token_name ): ?int {
		$constant_name = self::class . '::' . $token_name;
		if ( ! defined( $constant_name ) ) {
			return null;
		}
		return constant( $constant_name );
	}

	/**
	 * Get the name of a token for a given token ID.
	 *
	 * Names are derived from the grammar data rather than stored: a keyword
	 * token resolves to its keyword string via self::KEYWORDS, any other token
	 * to its WP_MySQL_Tokens constant name via reflection. When several names
	 * share a token number (keyword synonyms, equal-valued constants), plain
	 * keywords win over paren-gated function keywords (USER over SESSION_USER),
	 * then the first name in table order wins.
	 *
	 * This method is intended to be used only for testing and debugging purposes,
	 * when tokens need to be presented by their names in a human-readable form.
	 * It should not be used in production code, as it's not performance-optimized.
	 *
	 * @param int $token_id The numeric token ID.
	 * @return string The token name for the given token ID; null when not found.
	 */
	public static function get_token_name( int $token_id ): ?string {
		static $names = null;
		if ( null === $names ) {
			$names = array();
			foreach ( self::KEYWORDS as $keyword => $id ) {
				if ( ! isset( $names[ $id ] ) && ! isset( self::FUNCTIONS[ $keyword ] ) ) {
					$names[ $id ] = $keyword;
				}
			}
			foreach ( self::KEYWORDS as $keyword => $id ) {
				if ( ! isset( $names[ $id ] ) ) {
					$names[ $id ] = $keyword;
				}
			}
			foreach ( ( new ReflectionClass( WP_MySQL_Tokens::class ) )->getConstants() as $name => $value ) {
				if ( is_int( $value ) && ! isset( $names[ $value ] ) ) {
					$names[ $value ] = $name;
				}
			}
		}
		return $names[ $token_id ] ?? null;
	}

	private function read_next_token(): ?int {
		$byte      = $this->sql[ $this->bytes_already_read ] ?? null;
		$next_byte = $this->sql[ $this->bytes_already_read + 1 ] ?? null;

		// A map for a single-byte symbol fast path.
		static $single_byte_ops = array(
			'(' => self::OPEN_PAR_SYMBOL,
			')' => self::CLOSE_PAR_SYMBOL,
			',' => self::COMMA_SYMBOL,
			';' => self::SEMICOLON_SYMBOL,
			'+' => self::PLUS_OPERATOR,
			'~' => self::BITWISE_NOT_OPERATOR,
			'%' => self::MOD_OPERATOR,
			'^' => self::BITWISE_XOR_OPERATOR,
			'?' => self::PARAM_MARKER,
			'{' => self::OPEN_CURLY_SYMBOL,
			'}' => self::CLOSE_CURLY_SYMBOL,
			'=' => self::EQUAL_OPERATOR,
		);

		// Fast path for keywords and identifiers.
		// `$byte > "\x7F"` catches any non-ASCII byte (0x80-0xFF); read_identifier()
		// restricts the accepted identifier codepoints to U+0080-U+FFFF.
		// `"'" !== $next_byte` defers x'..', n'..' and similar special
		// literals to their dedicated branches below; only single quotes
		// form those, regardless of SQL mode.
		if (
			(
				( $byte >= 'a' && $byte <= 'z' )
				|| ( $byte >= 'A' && $byte <= 'Z' )
				|| $byte > "\x7F"
			)
			&& "'" !== $next_byte
		) {
			$started_at = $this->bytes_already_read;
			$type       = $this->read_identifier();
			if (
				self::IDENTIFIER === $type
				// When preceded by a dot, it is always an identifier.
				&& ! ( $started_at > 0 && '.' === $this->sql[ $started_at - 1 ] )
			) {
				// Inline the keyword lookup on the hot identifier path: most
				// identifiers are not keywords, so this avoids two method calls
				// (token-bytes extraction + keyword determination) per token.
				$word    = strtoupper(
					substr( $this->sql, $started_at, $this->bytes_already_read - $started_at )
				);
				$keyword = self::KEYWORDS[ $word ] ?? self::IDENTIFIER;
				if ( self::IDENTIFIER !== $keyword ) {
					$type = $this->resolve_keyword_type( $keyword, $word );
				}
			}
		} elseif ( null !== $byte && isset( $single_byte_ops[ $byte ] ) ) {
			// Fast path for single-byte symbols.
			$this->bytes_already_read += 1;
			$type                      = $single_byte_ops[ $byte ];
		} elseif ( "'" === $byte || '"' === $byte || '`' === $byte ) {
			$type = $this->read_quoted_text();
		} elseif ( null !== $byte && strspn( $byte, self::DIGIT_MASK ) > 0 ) {
			$type = $this->read_number();
		} elseif ( '.' === $byte ) {
			if ( null !== $next_byte && strspn( $next_byte, self::DIGIT_MASK ) > 0 ) {
				$type = $this->read_number();
			} else {
				$this->bytes_already_read += 1;
				$type                      = self::DOT_SYMBOL;
			}
		} elseif ( ':' === $byte ) {
			$this->bytes_already_read += 1; // Consume the ':'.
			if ( '=' === $next_byte ) {
				$this->bytes_already_read += 1; // Consume the '='.
				$type                      = self::ASSIGN_OPERATOR;
			} else {
				$type = self::COLON_SYMBOL;
			}
		} elseif ( '<' === $byte ) {
			$this->bytes_already_read += 1; // Consume the '<'.
			if ( '=' === $next_byte ) {
				$this->bytes_already_read += 1; // Consume the '='.
				if ( '>' === ( $this->sql[ $this->bytes_already_read ] ?? null ) ) {
					$this->bytes_already_read += 1; // Consume the '>'.
					$type                      = self::NULL_SAFE_EQUAL_OPERATOR;
				} else {
					$type = self::LESS_OR_EQUAL_OPERATOR;
				}
			} elseif ( '>' === $next_byte ) {
				$this->bytes_already_read += 1; // Consume the '>'.
				$type                      = self::NOT_EQUAL_OPERATOR;
			} elseif ( '<' === $next_byte ) {
				$this->bytes_already_read += 1; // Consume the '<'.
				$type                      = self::SHIFT_LEFT_OPERATOR;
			} else {
				$type = self::LESS_THAN_OPERATOR;
			}
		} elseif ( '>' === $byte ) {
			$this->bytes_already_read += 1; // Consume the '>'.
			if ( '=' === $next_byte ) {
				$this->bytes_already_read += 1; // Consume the '='.
				$type                      = self::GREATER_OR_EQUAL_OPERATOR;
			} elseif ( '>' === $next_byte ) {
				$this->bytes_already_read += 1; // Consume the '>'.
				$type                      = self::SHIFT_RIGHT_OPERATOR;
			} else {
				$type = self::GREATER_THAN_OPERATOR;
			}
		} elseif ( '!' === $byte ) {
			$this->bytes_already_read += 1; // Consume the '!'.
			if ( '=' === $next_byte ) {
				$this->bytes_already_read += 1; // Consume the '='.
				$type                      = self::NOT_EQUAL_OPERATOR;
			} else {
				$type = self::LOGICAL_NOT_OPERATOR;
			}
		} elseif ( '-' === $byte ) {
			if (
				'-' === $next_byte
				&& $this->bytes_already_read + 2 < $this->sql_length
				&& strspn( $this->sql[ $this->bytes_already_read + 2 ], self::WHITESPACE_MASK ) > 0
			) {
				$type = $this->read_line_comment();
			} elseif ( '>' === $next_byte ) {
				$this->bytes_already_read += 2; // Consume the '->'.
				if ( '>' === ( $this->sql[ $this->bytes_already_read ] ?? null ) ) {
					$this->bytes_already_read += 1; // Consume the '>'.
					if ( $this->mysql_version >= 50713 ) {
						$type = self::JSON_UNQUOTED_SEPARATOR_SYMBOL;
					} else {
						return null; // Invalid input.
					}
				} else {
					if ( $this->mysql_version >= 50708 ) {
						$type = self::JSON_SEPARATOR_SYMBOL;
					} else {
						return null; // Invalid input.
					}
				}
			} else {
				$this->bytes_already_read += 1; // Consume the '-'.
				$type                      = self::MINUS_OPERATOR;
			}
		} elseif ( '*' === $byte ) {
			$this->bytes_already_read += 1;
			if ( '/' === $next_byte && $this->in_mysql_comment ) {
				$this->bytes_already_read += 1; // Consume the '/'.
				$type                      = self::MYSQL_COMMENT_END;
				$this->in_mysql_comment    = false;
			} else {
				$type = self::MULT_OPERATOR;
			}
		} elseif ( '/' === $byte ) {
			if ( '*' === $next_byte ) {
				if ( '!' === ( $this->sql[ $this->bytes_already_read + 2 ] ?? null ) ) {
					$type = $this->read_mysql_comment();
				} else {
					$this->bytes_already_read += 2; // Consume the '/*'.
					$this->read_comment_content();
					$type = self::COMMENT;
				}
			} else {
				$this->bytes_already_read += 1;
				$type                      = self::DIV_OPERATOR;
			}
		} elseif ( '&' === $byte ) {
			$this->bytes_already_read += 1; // Consume the '&'.
			if ( '&' === $next_byte ) {
				$this->bytes_already_read += 1; // Consume the '&'.
				$type                      = self::LOGICAL_AND_OPERATOR;
			} else {
				$type = self::BITWISE_AND_OPERATOR;
			}
		} elseif ( '|' === $byte ) {
			$this->bytes_already_read += 1; // Consume the '|'.
			if ( '|' === $next_byte ) {
				$this->bytes_already_read += 1; // Consume the '|'.
				$type                      = $this->is_sql_mode_active( self::SQL_MODE_PIPES_AS_CONCAT )
					? self::CONCAT_PIPES_SYMBOL
					: self::LOGICAL_OR_OPERATOR;
			} else {
				$type = self::BITWISE_OR_OPERATOR;
			}
		} elseif ( '@' === $byte ) {
			$this->bytes_already_read += 1; // Consume the '@'.

			if ( '@' === $next_byte ) {
				$this->bytes_already_read += 1; // Consume the second '@'.
				$type                      = self::AT_AT_SIGN_SYMBOL;
			} else {
				/**
				 * Check whether the '@' marks an unquoted user-defined variable:
				 *   https://dev.mysql.com/doc/refman/8.4/en/user-variables.html
				 *
				 * Rules:
				 *   1. Starts with a '@'.
				 *   2. Allowed following characters are ASCII a-z, A-Z, 0-9, _, ., $.
				 */
				$length = strspn( $this->sql, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.$', $this->bytes_already_read );
				if ( $length > 0 ) {
					$this->bytes_already_read += $length;
					$type                      = self::AT_TEXT_SUFFIX;
				} else {
					$type = self::AT_SIGN_SYMBOL;
				}
			}
		} elseif ( '\\' === $byte ) {
			$this->bytes_already_read += 1; // Consume the '\'.
			if ( 'N' === $next_byte ) {
				$this->bytes_already_read += 1; // Consume the 'N'.
				$type                      = self::NULL2_SYMBOL;
			} else {
				return null; // Invalid input.
			}
		} elseif ( '#' === $byte ) {
			$type = $this->read_line_comment();
		} elseif ( null !== $byte && strspn( $byte, self::WHITESPACE_MASK ) > 0 ) {
			$this->bytes_already_read += strspn( $this->sql, self::WHITESPACE_MASK, $this->bytes_already_read );
			$type                      = self::WHITESPACE;
		} elseif ( ( 'x' === $byte || 'X' === $byte || 'b' === $byte || 'B' === $byte ) && "'" === $next_byte ) {
			$type = $this->read_number();
		} elseif ( ( 'n' === $byte || 'N' === $byte ) && "'" === $next_byte ) {
			$this->bytes_already_read += 1; // n/N
			$type                      = $this->read_quoted_text( "'" );
			if ( self::SINGLE_QUOTED_TEXT === $type ) {
				$type = self::NCHAR_TEXT;
			}
		} elseif ( null === $byte ) {
			$type = self::EOF;
		} else {
			$started_at = $this->bytes_already_read;
			$type       = $this->read_identifier();
			if ( self::IDENTIFIER === $type ) {
				// When preceded by a dot, it is always an identifier.
				if ( $started_at > 0 && '.' === $this->sql[ $started_at - 1 ] ) {
					$type = self::IDENTIFIER;
				} elseif ( '_' === $byte && isset( self::UNDERSCORE_CHARSETS[ strtolower( $this->get_current_token_bytes() ) ] ) ) {
					$type = self::UNDERSCORE_CHARSET;
				} else {
					$type = $this->determine_identifier_or_keyword_type( $this->get_current_token_bytes() );
				}
			}
		}
		return $type;
	}

	private function get_current_token_bytes(): string {
		return substr(
			$this->sql,
			$this->token_starts_at,
			$this->bytes_already_read - $this->token_starts_at
		);
	}

	/**
	 * Read an unquoted identifier.
	 *
	 * This function reads characters that are allowed in an unquoted identifier.
	 * An identifier cannot consist solely of digits, but this function doesn't
	 * ensure that explicitly, as numbers are processed before identifiers in
	 * the tokenization process, recognizing all digit-only sequences as numbers.
	 *
	 * Rules:
	 *   1. Allowed characters are ASCII a-z, A-Z, 0-9, _, $, and Unicode U+0080-U+FFFF.
	 *   2. Unquoted identifiers may begin with a digit but may not consist solely of digits.
	 *
	 *  See:
	 *    https://dev.mysql.com/doc/refman/8.4/en/identifiers.html
	 */
	private function read_identifier(): ?int {
		$started_at = $this->bytes_already_read;
		while ( true ) {
			// First, let's try to parse an ASCII sequence.
			$this->bytes_already_read += strspn(
				$this->sql,
				'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$',
				$this->bytes_already_read
			);

			// Check if the following byte can be part of a multibyte character
			// in the range of U+0080 to U+FFFF before looking at further bytes.
			// If it can't, bail out early to avoid unnecessary UTF-8 decoding.
			// Identifiers are usually ASCII-only, so we can optimize for that.
			$byte_1 = ord(
				$this->sql[ $this->bytes_already_read ] ?? "\0"
			);
			if ( $byte_1 < 0xC2 || $byte_1 > 0xEF ) {
				break;
			}

			// Look for a valid 2-byte UTF-8 symbol. Covers range U+0080 - U+07FF.
			$byte_2 = ord(
				$this->sql[ $this->bytes_already_read + 1 ] ?? "\0"
			);
			if (
				$byte_1 <= 0xDF
				&& $byte_2 >= 0x80 && $byte_2 <= 0xBF
			) {
				$this->bytes_already_read += 2;
				continue;
			}

			// Look for a valid 3-byte UTF-8 symbol in range U+0800 - U+FFFF.
			$byte_3 = ord(
				$this->sql[ $this->bytes_already_read + 2 ] ?? "\0"
			);
			if (
				$byte_1 <= 0xEF
				&& $byte_2 >= 0x80 && $byte_2 <= 0xBF
				&& $byte_3 >= 0x80 && $byte_3 <= 0xBF
				// Exclude surrogate range U+D800 to U+DFFF:
				&& ! ( 0xED === $byte_1 && $byte_2 >= 0xA0 )
				// Exclude overlong encodings:
				&& ! ( 0xE0 === $byte_1 && $byte_2 < 0xA0 )
			) {
				$this->bytes_already_read += 3;
				continue;
			}

			// Not a valid identifier character.
			break;
		}

		// An identifier cannot consist solely of digits, but we don't need to
		// ensure that explicitly, as numbers are processed before identifiers.

		return $this->bytes_already_read - $started_at > 0
			? self::IDENTIFIER
			: null; // Invalid input.
	}

	private function read_number(): ?int {
		// @TODO: Support numeric-only identifier parts after "." (e.g., 1ea10.1).

		$byte       = $this->sql[ $this->bytes_already_read ] ?? null;
		$next_byte  = $this->sql[ $this->bytes_already_read + 1 ] ?? null;
		$third_byte = $this->sql[ $this->bytes_already_read + 2 ] ?? null;

		if (
			// HEX number in the form of 0xN.
			(
				'0' === $byte
				&& 'x' === $next_byte
				&& null !== $third_byte
				&& strspn( $third_byte, self::HEX_DIGIT_MASK ) > 0
			)
			// HEX number in the form of x'N' or X'N'.
			|| ( ( 'x' === $byte || 'X' === $byte ) && "'" === $next_byte )
		) {
			$is_quoted                 = "'" === $next_byte;
			$this->bytes_already_read += 2; // Consume "0x" or "x'".
			$this->bytes_already_read += strspn( $this->sql, self::HEX_DIGIT_MASK, $this->bytes_already_read );
			if ( $is_quoted ) {
				if (
					$this->bytes_already_read >= $this->sql_length
					|| "'" !== $this->sql[ $this->bytes_already_read ]
				) {
					return null; // Invalid input.
				}
				$this->bytes_already_read += 1; // Consume the "'".
			}
			$type = self::HEX_NUMBER;
		} elseif (
			// BIN number in the form of 0bN.
			(
				'0' === $byte
				&& 'b' === $next_byte
				&& ( '0' === $third_byte || '1' === $third_byte )
			)
			// BIN number in the form of b'N' or B'N'.
			|| ( ( 'b' === $byte || 'B' === $byte ) && "'" === $next_byte )
		) {
			$is_quoted                 = "'" === $next_byte;
			$this->bytes_already_read += 2; // Consume "0b" or "b'".
			$this->bytes_already_read += strspn( $this->sql, '01', $this->bytes_already_read );
			if ( $is_quoted ) {
				if (
					$this->bytes_already_read >= $this->sql_length
					|| "'" !== $this->sql[ $this->bytes_already_read ]
				) {
					return null; // Invalid input.
				}
				$this->bytes_already_read += 1; // Consume the "'".
			}
			$type = self::BIN_NUMBER;
		} else {
			// Here, we have a sequence starting with N or .N, where N is a digit.

			// 1. Try integer first.
			$this->bytes_already_read += strspn( $this->sql, self::DIGIT_MASK, $this->bytes_already_read );
			$type                      = self::INT_NUMBER;

			// 2. In case of N. or .N, it's a decimal or float number.
			if ( '.' === ( $this->sql[ $this->bytes_already_read ] ?? null ) ) {
				$this->bytes_already_read += 1;
				$type                      = self::DECIMAL_NUMBER;
				$this->bytes_already_read += strspn( $this->sql, self::DIGIT_MASK, $this->bytes_already_read );
			}

			// 3. When exponent is present, it's a float number.
			$byte         = $this->sql[ $this->bytes_already_read ] ?? null;
			$next_byte    = $this->sql[ $this->bytes_already_read + 1 ] ?? null;
			$has_exponent =
				( 'e' === $byte || 'E' === $byte )
				&& null !== $next_byte
				&& (
					strspn( $next_byte, self::DIGIT_MASK ) > 0
					|| (
						( '+' === $next_byte || '-' === $next_byte )
						&& $this->bytes_already_read + 2 < $this->sql_length
						&& strspn( $this->sql[ $this->bytes_already_read + 2 ], self::DIGIT_MASK ) > 0
					)
				);
			if ( $has_exponent ) {
				$this->bytes_already_read += 1; // Consume the 'e' or 'E'.
				$this->bytes_already_read += 1; // Consume the '+', '-', or digit.
				$this->bytes_already_read += strspn( $this->sql, self::DIGIT_MASK, $this->bytes_already_read );
				$type                      = self::FLOAT_NUMBER;
			}
		}

		/*
		 * In MySQL, when an input matches both a number and an identifier, the
		 * number always wins. However, if the number is followed by a non-numeric
		 * identifier-like character, then it is recognized as an identifier...
		 * Unless it's a float number, which ignores any subsequent input.
		 *
		 * Examples:
		 *  - "1234" (integer) vs. "1234a" (identifier)
		 *  - "0b01" (bin)     vs. "0b012" (identifier)
		 *  - "0xa1" (hex)     vs. "0xa1x" (identifier)
		 *  - "12.3" (decimal) vs. "12.3a" (identifier)
		 *  - "1e10" (float)   vs. "1e10a" (float, followed by identifier)
		 */
		$text                       = $this->get_current_token_bytes();
		$possible_identifier_prefix =
			self::INT_NUMBER === $type
			|| ( '0' === $text[0] && ( 'b' === $text[1] || 'x' === $text[1] ) );

		/*
		 * When we match some subsequent identifier bytes, it's an identifier.
		 * Note that the "$this->read_identifier()" method doesn't check that
		 * the identifier doesn't consist solely of digits. This is an advantage
		 * here, as we can look only at subsequent bytes instead of backtracking
		 * to the beginning of the number (for valid identifiers like 0b019).
		 */
		if ( $possible_identifier_prefix && self::IDENTIFIER === $this->read_identifier() ) {
			$type = self::IDENTIFIER;
		}

		// Determine integer type.
		if ( self::INT_NUMBER === $type ) {
			// Fast path for most integers.
			$bytes = $this->get_current_token_bytes();
			if ( strlen( $bytes ) < 10 ) {
				return self::INT_NUMBER;
			}

			// Remove leading zeros.
			$bytes  = substr( $bytes, strspn( $bytes, '0' ) );
			$length = strlen( $bytes );

			// Determine integer type based on its length and value.
			if ( $length < 10 ) {
				return self::INT_NUMBER;
			} elseif ( 10 === $length ) {
				return strcmp( $bytes, '2147483647' ) > 0
					? self::LONG_NUMBER
					: self::INT_NUMBER;
			} elseif ( $length < 19 ) {
				return self::LONG_NUMBER;
			} elseif ( 19 === $length ) {
				return strcmp( $bytes, '9223372036854775807' ) > 0
					? self::ULONGLONG_NUMBER
					: self::LONG_NUMBER;
			} elseif ( 20 === $length ) {
				return strcmp( $bytes, '18446744073709551615' ) > 0
					? self::DECIMAL_NUMBER
					: self::ULONGLONG_NUMBER;
			} else {
				return self::DECIMAL_NUMBER;
			}
		}
		return $type;
	}

	/**
	 * Quoted literals and identifiers:
	 *   https://dev.mysql.com/doc/refman/8.4/en/string-literals.html
	 *   https://dev.mysql.com/doc/refman/8.4/en/identifiers.html
	 *
	 * Rules:
	 *   1. Quotes can be escaped by doubling them ('', "", ``).
	 *   2. Backslashes escape the next character, unless NO_BACKSLASH_ESCAPES is set.
	 */
	private function read_quoted_text(): ?int {
		$quote                     = $this->sql[ $this->bytes_already_read ];
		$this->bytes_already_read += 1; // Consume the quote.

		$no_backslash_escapes = $this->is_sql_mode_active(
			self::SQL_MODE_NO_BACKSLASH_ESCAPES
		);

		// We need to look for the closing quote in a loop, as it can be escaped,
		// in which case the escape sequence is consumed and the loop continues.
		$at = $this->bytes_already_read;
		while ( true ) {
			$quote_at = strpos( $this->sql, $quote, $at );
			if ( false === $quote_at ) {
				return null; // Invalid input.
			}
			$at = $quote_at;

			/*
			 * By default, quotes can be escaped with a "\".
			 * When NO_BACKSLASH_ESCAPES SQL mode is active, the "\" treated as
			 * a regular character.
			 *
			 * The quote is escaped only when the number of preceding backslashes
			 * is odd - "\" is an escape sequence, "\\" is an escaped backslash,
			 * "\\\" is an escaped backslash and an escape sequence, and so on.
			 *
			 * The `($at - $i - 1) >= 0` guard prevents PHP's negative-string-
			 * offset wraparound (PHP 7.1+) when the closing-quote candidate
			 * sits at the very start of the input. The `?? null` covers
			 * positive out-of-range indexes belt-and-suspenders.
			 */
			if ( ! $no_backslash_escapes ) {
				$i = 0;
				while ( ( $at - $i - 1 ) >= 0 && '\\' === ( $this->sql[ $at - $i - 1 ] ?? null ) ) {
					$i += 1;
				}
				if ( 1 === $i % 2 ) {
					$at += 1;
					continue;
				}
			}

			// Check if the quote is doubled.
			if ( ( $this->sql[ $at + 1 ] ?? null ) === $quote ) {
				$at += 2;
				continue;
			}

			break;
		}
		$at += 1;

		$this->bytes_already_read = $at;

		if ( '`' === $quote ) {
			return self::BACK_TICK_QUOTED_ID;
		} elseif ( '"' === $quote ) {
			// With the ANSI_QUOTES SQL mode, '"' quotes an identifier, not a string.
			if ( $this->is_sql_mode_active( self::SQL_MODE_ANSI_QUOTES ) ) {
				return self::BACK_TICK_QUOTED_ID;
			}
			return self::DOUBLE_QUOTED_TEXT;
		} else {
			return self::SINGLE_QUOTED_TEXT;
		}
	}

	private function read_line_comment(): int {
		$this->bytes_already_read += strcspn( $this->sql, "\r\n", $this->bytes_already_read );
		return self::COMMENT;
	}

	private function read_mysql_comment(): int {
		// @TODO: Consider supporting optimizer hints (/*+ ... */) or document
		//        that they are not supported.
		// @TODO: Implement six-digit version number support (from MySQL 8.4).

		// MySQL-specific comment in one of the following forms:
		//   1. /*! ... */      - The content is treated as SQL.
		//   2. /*!12345 ... */ - The content is treated as SQL when "MySQL version >= 12345".
		$this->bytes_already_read += 3; // Consume the '/*!'.

		// Check if the next 5 characters are digits.
		$digit_count        = strspn( $this->sql, self::DIGIT_MASK, $this->bytes_already_read, 5 );
		$is_version_comment = 5 === $digit_count;

		// For version comments, extract the version number.
		$version = $is_version_comment
			? (int) substr( $this->sql, $this->bytes_already_read, $digit_count )
			: 0;

		if ( $this->mysql_version < $version ) {
			// Version not satisfied. Treat the content as a regular comment.
			$this->read_comment_content();
			return self::COMMENT;
		} else {
			// Version satisfied or not specified. Treat the content as SQL code.
			$this->bytes_already_read += $digit_count; // Skip the version number.
			$this->in_mysql_comment    = true;
			return self::MYSQL_COMMENT_START;
		}
	}

	private function read_comment_content(): void {
		$comment_end = strpos( $this->sql, '*/', $this->bytes_already_read );
		if ( false === $comment_end ) {
			$this->bytes_already_read = $this->sql_length;
		} else {
			$this->bytes_already_read = $comment_end + 2;
		}
	}

	private function determine_identifier_or_keyword_type( string $value ): int {
		$word = strtoupper( $value );
		$type = self::KEYWORDS[ $word ] ?? self::IDENTIFIER;
		if ( self::IDENTIFIER === $type ) {
			return self::IDENTIFIER;
		}
		return $this->resolve_keyword_type( $type, $word );
	}

	/**
	 * Resolve a keyword token matched in self::KEYWORDS, applying the
	 * function-call lookahead and the SQL_MODE_HIGH_NOT_PRECEDENCE rule.
	 *
	 * @param int    $type The grammar token number matched in self::KEYWORDS.
	 * @param string $word The upper-cased keyword string that was matched.
	 */
	private function resolve_keyword_type( int $type, string $word ): int {
		// Function keywords (declared with SYM_FN in MySQL's lex.h) are keywords
		// only when directly followed by an opening parenthesis.
		if ( isset( self::FUNCTIONS[ $word ] ) ) {
			// Skip any whitespace character if the SQL mode says they should be ignored.
			if ( $this->is_sql_mode_active( self::SQL_MODE_IGNORE_SPACE ) ) {
				$this->bytes_already_read += strspn( $this->sql, self::WHITESPACE_MASK, $this->bytes_already_read );
			}
			if ( '(' !== ( $this->sql[ $this->bytes_already_read ] ?? null ) ) {
				return self::IDENTIFIER;
			}
		}

		// With "SQL_MODE_HIGH_NOT_PRECEDENCE" enabled, "NOT" needs to be emitted as a higher priority NOT2 symbol.
		if ( self::NOT_SYMBOL === $type && $this->is_sql_mode_active( self::SQL_MODE_HIGH_NOT_PRECEDENCE ) ) {
			return self::NOT2_SYMBOL;
		}

		return $type;
	}
}
