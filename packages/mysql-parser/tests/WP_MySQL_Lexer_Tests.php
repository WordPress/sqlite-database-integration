<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the MySQL lexer: the token iterator API, the scanner behavior
 * (identifiers, numbers, quoted strings), and the Bison token stream
 * produced by WP_MySQL_Lexer::remaining_tokens().
 */
class WP_MySQL_Lexer_Tests extends TestCase {
	public function test_tokenize_valid_input(): void {
		$lexer = new WP_MySQL_Lexer( 'SELECT id FROM users' );

		// SELECT
		$this->assertTrue( $lexer->next_token() );
		$this->assertSame( WP_MySQL_Lexer::KEYWORDS['SELECT'], $lexer->get_token()->id );

		// id
		$this->assertTrue( $lexer->next_token() );
		$this->assertSame( WP_MySQL_Lexer::IDENTIFIER, $lexer->get_token()->id );

		// FROM
		$this->assertTrue( $lexer->next_token() );
		$this->assertSame( WP_MySQL_Lexer::KEYWORDS['FROM'], $lexer->get_token()->id );

		// users
		$this->assertTrue( $lexer->next_token() );
		$this->assertSame( WP_MySQL_Lexer::IDENTIFIER, $lexer->get_token()->id );

		// The stream ends with END_OF_INPUT followed by Bison's end marker.
		$this->assertTrue( $lexer->next_token() );
		$this->assertSame( WP_MySQL_Lexer::END_OF_INPUT, $lexer->get_token()->id );
		$this->assertTrue( $lexer->next_token() );
		$this->assertSame( WP_MySQL_Lexer::END_MARKER, $lexer->get_token()->id );

		// No more tokens.
		$this->assertFalse( $lexer->next_token() );
		$this->assertNull( $lexer->get_token() );

		// Again, no more tokens.
		$this->assertFalse( $lexer->next_token() );
		$this->assertNull( $lexer->get_token() );
	}

	public function test_tokenize_invalid_input(): void {
		$lexer = new WP_MySQL_Lexer( "SELECT x'ab01xyz'" );

		// SELECT
		$this->assertTrue( $lexer->next_token() );
		$this->assertSame( WP_MySQL_Lexer::KEYWORDS['SELECT'], $lexer->get_token()->id );

		// Invalid input.
		$this->assertFalse( $lexer->next_token() );
		$this->assertNull( $lexer->get_token() );

		// No more tokens.
		$this->assertFalse( $lexer->next_token() );
		$this->assertNull( $lexer->get_token() );

		// Again, no more tokens.
		$this->assertFalse( $lexer->next_token() );
		$this->assertNull( $lexer->get_token() );
	}

	/**
	 * Get the Bison terminal names of a tokenized input.
	 *
	 * @param  string   $sql       The SQL payload to tokenize.
	 * @param  string[] $sql_modes The SQL modes to activate.
	 * @return string[]            Terminal names, in input order.
	 */
	private static function token_names( string $sql, array $sql_modes = array() ): array {
		$tokens = ( new WP_MySQL_Lexer( $sql, 80400, $sql_modes ) )->remaining_tokens();
		$names  = array();
		foreach ( $tokens as $token ) {
			$names[] = $token->get_name();
		}
		return $names;
	}

	public function test_emits_bison_terminals_with_end_markers(): void {
		$this->assertSame(
			array( 'SELECT', 'IDENTIFIER', 'FROM', 'IDENTIFIER', 'END_OF_INPUT', 'END_MARKER' ),
			self::token_names( 'SELECT id FROM users' )
		);
	}

	public function test_ignore_space_does_not_absorb_whitespace_into_function_identifiers(): void {
		// COUNT is a function keyword (SYM_FN). Under IGNORE_SPACE, "COUNT" that is
		// not followed by "(" is a plain identifier, and its byte range must exclude
		// the trailing whitespace that the mode skips while peeking for "(".
		foreach ( array( 'SELECT COUNT FROM t', "SELECT COUNT\t\n FROM t" ) as $sql ) {
			$tokens = ( new WP_MySQL_Lexer( $sql, 80400, array( 'IGNORE_SPACE' ) ) )->remaining_tokens();
			$this->assertSame( 'IDENTIFIER', $tokens[1]->get_name(), $sql );
			$this->assertSame( 'COUNT', $tokens[1]->get_value(), $sql );
			$this->assertSame( 5, $tokens[1]->length, $sql );
		}

		// When "(" does follow across whitespace, COUNT stays a function keyword and
		// its byte range still excludes the whitespace.
		$tokens = ( new WP_MySQL_Lexer( 'SELECT COUNT (1)', 80400, array( 'IGNORE_SPACE' ) ) )->remaining_tokens();
		$this->assertSame( 5, $tokens[1]->length );
		$this->assertNotSame( 'IDENTIFIER', $tokens[1]->get_name() );
		$this->assertSame( 'OPEN_PAR_SYMBOL', $tokens[2]->get_name() );
	}

	public function test_version_comments_gate_the_body_by_version(): void {
		// Five-digit version: the body is SQL only when the server version satisfies it.
		$this->assertSame(
			array( 'SELECT', 'INT_NUMBER', 'INT_NUMBER', 'END_OF_INPUT', 'END_MARKER' ),
			self::token_names( 'SELECT /*!50000 1 */ 2' )
		);
		$this->assertSame(
			array( 'SELECT', 'INT_NUMBER', 'END_OF_INPUT', 'END_MARKER' ),
			self::token_names( 'SELECT /*!99999 1 */ 2' )
		);

		// Six-digit MMmmrr version (MySQL 8.4): a sixth digit followed by whitespace
		// belongs to the version, not the body — so 080400 is consumed whole (no stray
		// digit), and 100000 gates above 8.4.0 rather than as 10000.
		$this->assertSame(
			array( 'SELECT', 'INT_NUMBER', 'INT_NUMBER', 'END_OF_INPUT', 'END_MARKER' ),
			self::token_names( 'SELECT /*!080400 1 */ 2' )
		);
		$this->assertSame(
			array( 'SELECT', 'INT_NUMBER', 'END_OF_INPUT', 'END_MARKER' ),
			self::token_names( 'SELECT /*!100000 1 */ 2' )
		);
	}

	public function test_at_name_splits_into_at_and_ident(): void {
		$tokens = ( new WP_MySQL_Lexer( 'SELECT @var1' ) )->remaining_tokens();
		$this->assertSame( 'AT_SIGN_SYMBOL', $tokens[1]->get_name() );
		$this->assertSame( 'IDENTIFIER', $tokens[2]->get_name() );
		$this->assertSame( 'var1', $tokens[2]->get_value() );
		$this->assertSame( 7, $tokens[1]->start );
		$this->assertSame( 1, $tokens[1]->length );
		$this->assertSame( 8, $tokens[2]->start );
		$this->assertSame( 4, $tokens[2]->length );
	}

	public function test_at_at_splits_into_two_at_signs(): void {
		$this->assertSame(
			array( 'SELECT', 'AT_SIGN_SYMBOL', 'AT_SIGN_SYMBOL', 'IDENTIFIER', 'END_OF_INPUT', 'END_MARKER' ),
			self::token_names( 'SELECT @@sql_mode' )
		);
	}

	public function test_bare_at_emits_empty_name(): void {
		// MySQL's lexer emits an empty LEX_HOSTNAME after a bare "@", making
		// "user1@" (an empty host part) and "SELECT @" valid.
		$tokens = ( new WP_MySQL_Lexer( 'SELECT @' ) )->remaining_tokens();
		$this->assertSame( 'AT_SIGN_SYMBOL', $tokens[1]->get_name() );
		$this->assertSame( 'IDENTIFIER', $tokens[2]->get_name() );
		$this->assertSame( 0, $tokens[2]->length );
		$this->assertSame( '', $tokens[2]->get_value() );

		$this->assertSame(
			array( 'CREATE', 'USER', 'IDENTIFIER', 'AT_SIGN_SYMBOL', 'IDENTIFIER', 'END_OF_INPUT', 'END_MARKER' ),
			self::token_names( 'CREATE USER user1@' )
		);
	}

	public function test_bare_at_before_quote_stands_alone(): void {
		// In "@'name'" the quoted text supplies the name itself.
		$this->assertSame(
			array( 'SET', 'AT_SIGN_SYMBOL', 'SINGLE_QUOTED_TEXT', '=', 'INT_NUMBER', 'END_OF_INPUT', 'END_MARKER' ),
			self::token_names( "SET @'v' = 1" )
		);
	}

	public function test_with_rollup_is_contracted(): void {
		$names = self::token_names( 'SELECT 1 FROM t GROUP BY a WITH ROLLUP' );
		$this->assertContains( 'WITH_ROLLUP_SYMBOL', $names );
		$this->assertNotContains( 'WITH', $names );
	}

	public function test_with_rollup_contracts_across_comments(): void {
		$tokens = ( new WP_MySQL_Lexer( 'SELECT 1 FROM t GROUP BY a WITH /* c */ ROLLUP' ) )->remaining_tokens();
		$rollup = null;
		foreach ( $tokens as $token ) {
			if ( 'WITH_ROLLUP_SYMBOL' === $token->get_name() ) {
				$rollup = $token;
			}
		}
		$this->assertNotNull( $rollup );
		$this->assertSame( 'WITH /* c */ ROLLUP', $rollup->get_bytes() );
	}

	public function test_lone_with_is_emitted(): void {
		$this->assertSame(
			array( 'WITH', 'IDENTIFIER', 'AS', 'OPEN_PAR_SYMBOL', 'SELECT', 'INT_NUMBER', 'CLOSE_PAR_SYMBOL', 'SELECT', 'MULT_OPERATOR', 'FROM', 'IDENTIFIER', 'END_OF_INPUT', 'END_MARKER' ),
			self::token_names( 'WITH c AS (SELECT 1) SELECT * FROM c' )
		);

		// A statement ending on WITH still emits it before the end markers.
		$this->assertSame(
			array( 'SELECT', 'INT_NUMBER', 'WITH', 'END_OF_INPUT', 'END_MARKER' ),
			self::token_names( 'SELECT 1 WITH' )
		);
	}

	public function test_invalid_input_returns_partial_stream_without_end_markers(): void {
		$names = self::token_names( "SELECT 1 WITH \x01" );
		$this->assertSame( array( 'SELECT', 'INT_NUMBER', 'WITH' ), $names );
	}

	public function test_high_not_precedence_emits_not2(): void {
		$names = self::token_names( 'SELECT NOT 1', array( 'HIGH_NOT_PRECEDENCE' ) );
		$this->assertContains( 'NOT2_SYMBOL', $names );

		$names = self::token_names( 'SELECT NOT 1' );
		$this->assertContains( 'NOT', $names );
	}

	public function test_end_of_input_word_is_an_identifier(): void {
		// "end_of_input" is not a MySQL keyword; it must not truncate the stream.
		$this->assertSame(
			array( 'SELECT', 'IDENTIFIER', 'FROM', 'IDENTIFIER', 'END_OF_INPUT', 'END_MARKER' ),
			self::token_names( 'SELECT end_of_input FROM t' )
		);
	}

	public function test_current_date_is_a_keyword_without_parentheses(): void {
		// CURRENT_DATE/CURRENT_TIME are plain reserved keywords in MySQL 8.4
		// (lex.h SYM), unlike CURDATE/CURTIME which require parentheses.
		$this->assertSame(
			array( 'SELECT', 'CURRENT_DATE', 'END_OF_INPUT', 'END_MARKER' ),
			self::token_names( 'SELECT CURRENT_DATE' )
		);
		$this->assertSame(
			array( 'SELECT', 'IDENTIFIER', 'END_OF_INPUT', 'END_MARKER' ),
			self::token_names( 'SELECT curdate' )
		);
	}

	public function test_json_aggregates_are_keywords_only_before_parenthesis(): void {
		$this->assertSame(
			array( 'SELECT', 'IDENTIFIER', 'FROM', 'IDENTIFIER', 'END_OF_INPUT', 'END_MARKER' ),
			self::token_names( 'SELECT json_objectagg FROM t' )
		);
		$names = self::token_names( 'SELECT JSON_OBJECTAGG(a, b) FROM t' );
		$this->assertContains( 'JSON_OBJECTAGG', $names );
	}

	public function test_number_tokens_follow_mysql_magnitude_classes(): void {
		$this->assertSame( array( 'SELECT', 'INT_NUMBER', 'END_OF_INPUT', 'END_MARKER' ), self::token_names( 'SELECT 2147483647' ) );
		$this->assertSame( array( 'SELECT', 'LONG_NUMBER', 'END_OF_INPUT', 'END_MARKER' ), self::token_names( 'SELECT 2147483648' ) );
		$this->assertSame( array( 'SELECT', 'ULONGLONG_NUMBER', 'END_OF_INPUT', 'END_MARKER' ), self::token_names( 'SELECT 18446744073709551615' ) );
		$this->assertSame( array( 'SELECT', 'DECIMAL_NUMBER', 'END_OF_INPUT', 'END_MARKER' ), self::token_names( 'SELECT 18446744073709551616' ) );
	}

	/**
	 * Test that the whole U+0080 to U+FFFF UTF-8 range is valid in an identifier.
	 * The validity is checked against PCRE with the "u" (PCRE_UTF8) modifier set.
	 */
	public function test_identifier_utf8_range(): void {
		for ( $i = 0x80; $i < 0xffff; $i += 1 ) {
			$value = mb_chr( $i, 'UTF-8' );

			$lexer = new WP_MySQL_Lexer( $value );
			$this->assertTrue( $lexer->next_token() );

			$type     = $lexer->get_token()->id;
			$is_valid = preg_match( '/^[\x{0080}-\x{ffff}]$/u', $value );
			if ( $is_valid ) {
				$this->assertSame( WP_MySQL_Lexer::IDENTIFIER, $type );
			} else {
				// A surrogate codepoint renders as an empty string, so the lexer
				// emits no identifier — only the end-of-input terminal.
				$this->assertSame( WP_MySQL_Lexer::END_OF_INPUT, $type );
			}
		}
	}

	/**
	 * Test all valid and invalid 2-byte UTF-8 sequences in an identifier.
	 * The validity is checked against PCRE with the "u" (PCRE_UTF8) modifier set.
	 *
	 * Start both bytes from 128 and go up to 255 to include all invalid 2-byte
	 * UTF-8 sequences as well, and ensure that they won't match as identifiers.
	 */
	public function test_identifier_utf8_two_byte_sequences(): void {
		for ( $byte_1 = 128; $byte_1 <= 255; $byte_1 += 1 ) {
			for ( $byte_2 = 128; $byte_2 <= 255; $byte_2 += 1 ) {
				$value = chr( $byte_1 ) . chr( $byte_2 );

				$lexer  = new WP_MySQL_Lexer( $value );
				$result = $lexer->next_token();
				$token  = $lexer->get_token();

				$is_valid = preg_match( '/^[\x{0080}-\x{ffff}]$/u', $value );
				if ( $is_valid ) {
					$this->assertTrue( $result );
					$this->assertSame( WP_MySQL_Lexer::IDENTIFIER, $token->id );
				} else {
					$this->assertFalse( $result );
					$this->assertNull( $token );
				}
			}
		}
	}

	/**
	 * Test all valid and invalid 3-byte UTF-8 sequences in an identifier.
	 * The validity is checked against PCRE with the "u" (PCRE_UTF8) modifier set.
	 *
	 * Start the first byte from 0xE0 to mark the beginning of a 3-byte sequence.
	 * Start bytes 2 and 3 from 128 and go up to 255 to include all invalid 3-byte
	 * UTF-8 sequences as well, and ensure that they won't match as identifiers.
	 */
	public function test_identifier_utf8_three_byte_sequences(): void {
		for ( $byte_1 = 0xE0; $byte_1 <= 0xFF; $byte_1 += 1 ) {
			for ( $byte_2 = 128; $byte_2 <= 255; $byte_2 += 1 ) {
				for ( $byte_3 = 128; $byte_3 <= 255; $byte_3 += 1 ) {
					$value = chr( $byte_1 ) . chr( $byte_2 ) . chr( $byte_3 );

					$lexer  = new WP_MySQL_Lexer( $value );
					$result = $lexer->next_token();
					$token  = $lexer->get_token();

					$is_valid = preg_match( '/^[\x{0080}-\x{ffff}]$/u', $value );
					if ( $is_valid ) {
						$this->assertTrue( $result );
						$this->assertSame( WP_MySQL_Lexer::IDENTIFIER, $token->id );
					} else {
						$this->assertFalse( $result );
						$this->assertNull( $token );
					}
				}
			}
		}
	}

	/**
	 * A charset-introducer-like name used as a qualified member (after a dot)
	 * must lex as an identifier. A real charset introducer only appears before
	 * a string literal, never as the member of a qualified reference.
	 *
	 * @dataProvider data_underscore_charset_after_dot
	 */
	public function test_underscore_charset_name_after_dot_is_identifier( string $sql, int $token_index, int $expected_id ): void {
		$tokens = ( new WP_MySQL_Lexer( $sql ) )->remaining_tokens();
		$this->assertSame(
			WP_MySQL_Lexer::get_token_name( $expected_id ),
			$tokens[ $token_index ]->get_name(),
			$sql
		);
	}

	/**
	 * @return array<string,array{0:string,1:int,2:int}>
	 */
	public function data_underscore_charset_after_dot(): array {
		return array(
			// `t . _utf8` - the member name must be an identifier, not a charset.
			'charset name after dot is identifier'  => array( 't._utf8', 2, WP_MySQL_Lexer::IDENTIFIER ),
			'other charset name after dot'          => array( 'a._binary', 2, WP_MySQL_Lexer::IDENTIFIER ),
			// A genuine charset introducer (before a string) stays a charset.
			'charset introducer before string'      => array( "_utf8'x'", 0, WP_MySQL_Lexer::UNDERSCORE_CHARSET ),
			// A non-charset underscore name after a dot stays an identifier.
			'non-charset underscore name after dot' => array( 't._foo', 2, WP_MySQL_Lexer::IDENTIFIER ),
		);
	}

	/**
	 * @dataProvider data_integer_types
	 */
	public function test_integer_types( $input, $expected ): void {
		$lexer = new WP_MySQL_Lexer( $input );
		$this->assertTrue( $lexer->next_token() );
		$this->assertSame( $expected, $lexer->get_token()->id );
	}

	public function data_integer_types(): array {
		return array(
			array( '0', WP_MySQL_Lexer::INT_NUMBER ),
			array( '123', WP_MySQL_Lexer::INT_NUMBER ),
			array( '2147483647', WP_MySQL_Lexer::INT_NUMBER ),
			array( '00000000001', WP_MySQL_Lexer::INT_NUMBER ),
			array( '00000000002147483647', WP_MySQL_Lexer::INT_NUMBER ),

			array( '2147483648', WP_MySQL_Lexer::LONG_NUMBER ),
			array( '123456789123456789', WP_MySQL_Lexer::LONG_NUMBER ),
			array( '9223372036854775807', WP_MySQL_Lexer::LONG_NUMBER ),
			array( '00000000002147483648', WP_MySQL_Lexer::LONG_NUMBER ),
			array( '00000000009223372036854775807', WP_MySQL_Lexer::LONG_NUMBER ),

			array( '9223372036854775808', WP_MySQL_Lexer::ULONGLONG_NUMBER ),
			array( '12345678912345678912', WP_MySQL_Lexer::ULONGLONG_NUMBER ),
			array( '18446744073709551615', WP_MySQL_Lexer::ULONGLONG_NUMBER ),
			array( '00000000000000000009223372036854775808', WP_MySQL_Lexer::ULONGLONG_NUMBER ),
			array( '000000000000000000018446744073709551615', WP_MySQL_Lexer::ULONGLONG_NUMBER ),

			array( '18446744073709551616', WP_MySQL_Lexer::DECIMAL_NUMBER ),
			array( '23456789123456789123', WP_MySQL_Lexer::DECIMAL_NUMBER ),
			array( '123456789123456789123456789', WP_MySQL_Lexer::DECIMAL_NUMBER ),
			array( '0000000000000000000018446744073709551616', WP_MySQL_Lexer::DECIMAL_NUMBER ),
			array( '00000000000000000000123456789123456789123456789', WP_MySQL_Lexer::DECIMAL_NUMBER ),
		);
	}

	/**
	 * Numbers vs. identifiers:
	 *
	 * In MySQL, when an input matches both a number and an identifier, the number always wins.
	 * However, when the number is followed by a non-numeric identifier-like character, it is
	 * considered an identifier... unless it's a float number, which ignores subsequent input.
	 *
	 * @dataProvider data_identifier_or_number
	 */
	public function test_identifier_or_number( $input, $expected ): void {
		$lexer  = new WP_MySQL_Lexer( $input );
		$actual = array_map(
			function ( $token ) {
				return $token->id;
			},
			$lexer->remaining_tokens()
		);

		// Compare token names to get more readable error messages.
		$this->assertSame(
			$this->get_token_names( $expected ),
			$this->get_token_names( $actual )
		);
	}

	public function data_identifier_or_number(): array {
		$end = array( WP_MySQL_Lexer::END_OF_INPUT, WP_MySQL_Lexer::END_MARKER );
		return array(
			// integer
			array( '123', array_merge( array( WP_MySQL_Lexer::INT_NUMBER ), $end ) ),
			array( '123abc', array_merge( array( WP_MySQL_Lexer::IDENTIFIER ), $end ) ), // identifier

			// binary
			array( '0b01', array_merge( array( WP_MySQL_Lexer::BIN_NUMBER ), $end ) ),
			array( '0b01xyz', array_merge( array( WP_MySQL_Lexer::IDENTIFIER ), $end ) ), // identifier
			array( '0b', array_merge( array( WP_MySQL_Lexer::IDENTIFIER ), $end ) ), // identifier
			array( "b'01'", array_merge( array( WP_MySQL_Lexer::BIN_NUMBER ), $end ) ),
			array( "b'01xyz'", array() ), // invalid input
			array( "b''", array_merge( array( WP_MySQL_Lexer::BIN_NUMBER ), $end ) ),
			array( "b'", array() ), // invalid input
			array( "b'01", array() ), // invalid input

			// hex
			array( '0xab01', array_merge( array( WP_MySQL_Lexer::HEX_NUMBER ), $end ) ),
			array( '0xab01xyz', array_merge( array( WP_MySQL_Lexer::IDENTIFIER ), $end ) ), // identifier
			array( '0x', array_merge( array( WP_MySQL_Lexer::IDENTIFIER ), $end ) ), // identifier
			array( "x'ab01'", array_merge( array( WP_MySQL_Lexer::HEX_NUMBER ), $end ) ),
			array( "x'ab01xyz'", array() ), // invalid input
			array( "x''", array_merge( array( WP_MySQL_Lexer::HEX_NUMBER ), $end ) ),
			array( "x'", array() ), // invalid input
			array( "x'ab", array() ), // invalid input

			// decimal
			array( '123.456', array_merge( array( WP_MySQL_Lexer::DECIMAL_NUMBER ), $end ) ),
			array( '.123', array_merge( array( WP_MySQL_Lexer::DECIMAL_NUMBER ), $end ) ),
			array( '123.', array_merge( array( WP_MySQL_Lexer::DECIMAL_NUMBER ), $end ) ),
			array( '123.456abc', array_merge( array( WP_MySQL_Lexer::DECIMAL_NUMBER, WP_MySQL_Lexer::IDENTIFIER ), $end ) ), // not identifier
			array( '.123abc', array_merge( array( WP_MySQL_Lexer::DECIMAL_NUMBER, WP_MySQL_Lexer::IDENTIFIER ), $end ) ), // not identifier
			array( '123.abc', array_merge( array( WP_MySQL_Lexer::DECIMAL_NUMBER, WP_MySQL_Lexer::IDENTIFIER ), $end ) ), // not identifier

			// float
			array( '1e10', array_merge( array( WP_MySQL_Lexer::FLOAT_NUMBER ), $end ) ),
			array( '1e+10', array_merge( array( WP_MySQL_Lexer::FLOAT_NUMBER ), $end ) ),
			array( '1e-10', array_merge( array( WP_MySQL_Lexer::FLOAT_NUMBER ), $end ) ),
			array( '.1e10', array_merge( array( WP_MySQL_Lexer::FLOAT_NUMBER ), $end ) ),
			array( '.1e+10', array_merge( array( WP_MySQL_Lexer::FLOAT_NUMBER ), $end ) ),
			array( '.1e-10', array_merge( array( WP_MySQL_Lexer::FLOAT_NUMBER ), $end ) ),
			array( '1.1e10', array_merge( array( WP_MySQL_Lexer::FLOAT_NUMBER ), $end ) ),
			array( '1.1e-10', array_merge( array( WP_MySQL_Lexer::FLOAT_NUMBER ), $end ) ),
			array( '1.1e+10', array_merge( array( WP_MySQL_Lexer::FLOAT_NUMBER ), $end ) ),
			array( '1e10abc', array_merge( array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::IDENTIFIER ), $end ) ), // not identifier (this differs from INT/BIN/HEX numbers)
			array( '1e+10abc', array_merge( array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::IDENTIFIER ), $end ) ), // not identifier
			array( '1e-10abc', array_merge( array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::IDENTIFIER ), $end ) ), // not identifier
			array( '.1e10abc', array_merge( array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::IDENTIFIER ), $end ) ), // not identifier
			array( '.1e+10abc', array_merge( array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::IDENTIFIER ), $end ) ), // not identifier
			array( '.1e-10abc', array_merge( array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::IDENTIFIER ), $end ) ), // not identifier
			array( '1.1e10abc', array_merge( array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::IDENTIFIER ), $end ) ), // not identifier
			array( '1.1e+10abc', array_merge( array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::IDENTIFIER ), $end ) ), // not identifier
			array( '1.1e-10abc', array_merge( array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::IDENTIFIER ), $end ) ), // not identifier

			// non-numbers
			array( '.SELECT', array_merge( array( WP_MySQL_Lexer::DOT_SYMBOL, WP_MySQL_Lexer::IDENTIFIER ), $end ) ), // not decimal or float
			array( '1+e10', array_merge( array( WP_MySQL_Lexer::INT_NUMBER, WP_MySQL_Lexer::PLUS_OPERATOR, WP_MySQL_Lexer::IDENTIFIER ), $end ) ), // not float
			array( '1-e10', array_merge( array( WP_MySQL_Lexer::INT_NUMBER, WP_MySQL_Lexer::MINUS_OPERATOR, WP_MySQL_Lexer::IDENTIFIER ), $end ) ), // not float
		);
	}

	/**
	 * Test that unclosed quoted strings with trailing backslashes do not
	 * cause out-of-bounds string access in read_quoted_text().
	 *
	 * The backslash-counting loop walks backward from the closing-quote
	 * candidate position. When the closing quote is missing and the last
	 * byte is a backslash, the loop must not treat the absent quote as
	 * escaped and advance past the end of the string, which would access
	 * an invalid string offset, triggering "Uninitialized string offset"
	 * warnings.
	 *
	 * @dataProvider data_unclosed_strings_with_backslashes
	 */
	public function test_unclosed_string_with_trailing_backslash( string $sql ): void {
		set_error_handler(
			function ( $severity, $message, $file, $line ) {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			},
			E_WARNING | E_NOTICE
		);

		try {
			$lexer = new WP_MySQL_Lexer( $sql );
			while ( $lexer->next_token() ) {
				// Consume all tokens.
			}
		} finally {
			restore_error_handler();
		}

		// If we reach here without an ErrorException, no OOB access occurred.
		$this->assertNull( $lexer->get_token() );
	}

	public function data_unclosed_strings_with_backslashes(): array {
		return array(
			'single-quoted trailing backslash' => array( "SELECT '\\" ),
			'double-quoted trailing backslash' => array( 'SELECT "\\' ),
			'even trailing backslashes'        => array( "SELECT '\\\\" ),
			'odd trailing backslashes'         => array( "SELECT '\\\\\\" ),
			'backslash-only single-quoted'     => array( "'\\" ),
			'backslash-only double-quoted'     => array( '"\\' ),
		);
	}

	/**
	 * Regression: valid strings with escapes must still tokenize correctly.
	 *
	 * @dataProvider data_valid_escaped_strings
	 */
	public function test_valid_escaped_string( string $sql, int $expected_token_id ): void {
		$lexer = new WP_MySQL_Lexer( $sql );
		$this->assertTrue( $lexer->next_token() );
		$this->assertSame( $expected_token_id, $lexer->get_token()->id );
	}

	public function data_valid_escaped_strings(): array {
		return array(
			'escaped single quote'       => array( "'it\\'s'", WP_MySQL_Lexer::SINGLE_QUOTED_TEXT ),
			'trailing escaped backslash' => array( "'path\\\\'", WP_MySQL_Lexer::SINGLE_QUOTED_TEXT ),
			'doubled single quote'       => array( "'it''s'", WP_MySQL_Lexer::SINGLE_QUOTED_TEXT ),
			'empty single-quoted string' => array( "''", WP_MySQL_Lexer::SINGLE_QUOTED_TEXT ),
			'escaped double quote'       => array( '"col\\"name"', WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT ),
			'backtick identifier'        => array( '`my_column`', WP_MySQL_Lexer::BACK_TICK_QUOTED_ID ),
		);
	}

	/**
	 * Test that a chunk boundary splitting a quoted string with a trailing
	 * backslash does not cause an out-of-bounds string access.
	 *
	 * This simulates streaming SQL processing where a buffer boundary falls
	 * inside a string literal right after a backslash escape character.
	 */
	public function test_chunk_boundary_inside_escaped_string(): void {
		set_error_handler(
			function ( $severity, $message, $file, $line ) {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			},
			E_WARNING | E_NOTICE
		);

		try {
			// Build a SQL string where a backslash falls at the chunk boundary.
			// The string content before the boundary is padded to place the
			// backslash at exactly position $chunk_size - 1.
			$chunk_size = 8192;

			// "SELECT '" = 8 bytes, so we need chunk_size - 8 - 1 bytes of
			// padding before the trailing backslash to place '\' at the last
			// byte of the chunk.
			$padding = str_repeat( 'A', $chunk_size - 8 - 1 );
			$sql     = "SELECT '" . $padding . '\\';

			// The chunk is exactly $chunk_size bytes. The last byte is '\'.
			// The lexer should handle this as an unclosed string without OOB.
			$this->assertSame( $chunk_size, strlen( $sql ) );

			$lexer = new WP_MySQL_Lexer( $sql );
			while ( $lexer->next_token() ) {
				// Consume all tokens.
			}
		} finally {
			restore_error_handler();
		}

		$this->assertNull( $lexer->get_token() );
	}

	private function get_token_names( array $token_types ): array {
		return array_map(
			function ( $token_type ) {
				return WP_MySQL_Lexer::get_token_name( $token_type );
			},
			$token_types
		);
	}
}
