<?php

use PHPUnit\Framework\TestCase;

class WP_MySQL_Lexer_Tests extends TestCase {
	public function test_tokenize_valid_input(): void {
		$lexer = new WP_MySQL_Lexer( 'SELECT id FROM users' );

		// SELECT
		$this->assertTrue( $lexer->next_token() );
		$this->assertSame( WP_MySQL_Lexer::SELECT_SYMBOL, $lexer->get_token()->id );

		// id
		$this->assertTrue( $lexer->next_token() );
		$this->assertSame( WP_MySQL_Lexer::IDENTIFIER, $lexer->get_token()->id );

		// FROM
		$this->assertTrue( $lexer->next_token() );
		$this->assertSame( WP_MySQL_Lexer::FROM_SYMBOL, $lexer->get_token()->id );

		// users
		$this->assertTrue( $lexer->next_token() );
		$this->assertSame( WP_MySQL_Lexer::IDENTIFIER, $lexer->get_token()->id );

		// EOF
		$this->assertTrue( $lexer->next_token() );
		$this->assertSame( WP_MySQL_Lexer::EOF, $lexer->get_token()->id );

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
		$this->assertSame( WP_MySQL_Lexer::SELECT_SYMBOL, $lexer->get_token()->id );

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
				$this->assertSame( WP_MySQL_Lexer::EOF, $type );
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
		return array(
			// integer
			array( '123', array( WP_MySQL_Lexer::INT_NUMBER, WP_MySQL_Lexer::EOF ) ),
			array( '123abc', array( WP_MySQL_Lexer::IDENTIFIER, WP_MySQL_Lexer::EOF ) ), // identifier

			// binary
			array( '0b01', array( WP_MySQL_Lexer::BIN_NUMBER, WP_MySQL_Lexer::EOF ) ),
			array( '0b01xyz', array( WP_MySQL_Lexer::IDENTIFIER, WP_MySQL_Lexer::EOF ) ), // identifier
			array( '0b', array( WP_MySQL_Lexer::IDENTIFIER, WP_MySQL_Lexer::EOF ) ), // identifier
			array( "b'01'", array( WP_MySQL_Lexer::BIN_NUMBER, WP_MySQL_Lexer::EOF ) ),
			array( "b'01xyz'", array() ), // invalid input
			array( "b''", array( WP_MySQL_Lexer::BIN_NUMBER, WP_MySQL_Lexer::EOF ) ),
			array( "b'", array() ), // invalid input
			array( "b'01", array() ), // invalid input

			// hex
			array( '0xab01', array( WP_MySQL_Lexer::HEX_NUMBER, WP_MySQL_Lexer::EOF ) ),
			array( '0xab01xyz', array( WP_MySQL_Lexer::IDENTIFIER, WP_MySQL_Lexer::EOF ) ), // identifier
			array( '0x', array( WP_MySQL_Lexer::IDENTIFIER, WP_MySQL_Lexer::EOF ) ), // identifier
			array( "x'ab01'", array( WP_MySQL_Lexer::HEX_NUMBER, WP_MySQL_Lexer::EOF ) ),
			array( "x'ab01xyz'", array() ), // invalid input
			array( "x''", array( WP_MySQL_Lexer::HEX_NUMBER, WP_MySQL_Lexer::EOF ) ),
			array( "x'", array() ), // invalid input
			array( "x'ab", array() ), // invalid input

			// decimal
			array( '123.456', array( WP_MySQL_Lexer::DECIMAL_NUMBER, WP_MySQL_Lexer::EOF ) ),
			array( '.123', array( WP_MySQL_Lexer::DECIMAL_NUMBER, WP_MySQL_Lexer::EOF ) ),
			array( '123.', array( WP_MySQL_Lexer::DECIMAL_NUMBER, WP_MySQL_Lexer::EOF ) ),
			array( '123.456abc', array( WP_MySQL_Lexer::DECIMAL_NUMBER, WP_MySQL_Lexer::IDENTIFIER, WP_MySQL_Lexer::EOF ) ), // not identifier
			array( '.123abc', array( WP_MySQL_Lexer::DECIMAL_NUMBER, WP_MySQL_Lexer::IDENTIFIER, WP_MySQL_Lexer::EOF ) ), // not identifier
			array( '123.abc', array( WP_MySQL_Lexer::DECIMAL_NUMBER, WP_MySQL_Lexer::IDENTIFIER, WP_MySQL_Lexer::EOF ) ), // not identifier

			// float
			array( '1e10', array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::EOF ) ),
			array( '1e+10', array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::EOF ) ),
			array( '1e-10', array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::EOF ) ),
			array( '.1e10', array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::EOF ) ),
			array( '.1e+10', array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::EOF ) ),
			array( '.1e-10', array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::EOF ) ),
			array( '1.1e10', array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::EOF ) ),
			array( '1.1e-10', array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::EOF ) ),
			array( '1.1e+10', array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::EOF ) ),
			array( '1e10abc', array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::IDENTIFIER, WP_MySQL_Lexer::EOF ) ), // not identifier (this differs from INT/BIN/HEX numbers)
			array( '1e+10abc', array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::IDENTIFIER, WP_MySQL_Lexer::EOF ) ), // not identifier
			array( '1e-10abc', array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::IDENTIFIER, WP_MySQL_Lexer::EOF ) ), // not identifier
			array( '.1e10abc', array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::IDENTIFIER, WP_MySQL_Lexer::EOF ) ), // not identifier
			array( '.1e+10abc', array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::IDENTIFIER, WP_MySQL_Lexer::EOF ) ), // not identifier
			array( '.1e-10abc', array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::IDENTIFIER, WP_MySQL_Lexer::EOF ) ), // not identifier
			array( '1.1e10abc', array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::IDENTIFIER, WP_MySQL_Lexer::EOF ) ), // not identifier
			array( '1.1e+10abc', array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::IDENTIFIER, WP_MySQL_Lexer::EOF ) ), // not identifier
			array( '1.1e-10abc', array( WP_MySQL_Lexer::FLOAT_NUMBER, WP_MySQL_Lexer::IDENTIFIER, WP_MySQL_Lexer::EOF ) ), // not identifier

			// non-numbers
			array( '.SELECT', array( WP_MySQL_Lexer::DOT_SYMBOL, WP_MySQL_Lexer::IDENTIFIER, WP_MySQL_Lexer::EOF ) ), // not decimal or float
			array( '1+e10', array( WP_MySQL_Lexer::INT_NUMBER, WP_MySQL_Lexer::PLUS_OPERATOR, WP_MySQL_Lexer::IDENTIFIER, WP_MySQL_Lexer::EOF ) ), // not float
			array( '1-e10', array( WP_MySQL_Lexer::INT_NUMBER, WP_MySQL_Lexer::MINUS_OPERATOR, WP_MySQL_Lexer::IDENTIFIER, WP_MySQL_Lexer::EOF ) ), // not float
		);
	}

	/**
	 * Test that unclosed quoted strings with trailing backslashes do not
	 * cause out-of-bounds string access in read_quoted_text().
	 *
	 * The backslash-counting loop walks backward from the position returned
	 * by strcspn(). When the closing quote is missing, strcspn() returns
	 * the remaining string length. If the last byte is a backslash, the
	 * loop treats the absent quote as escaped and advances past the end
	 * of the string. On the next iteration, the loop accesses an invalid
	 * string offset, triggering "Uninitialized string offset" warnings.
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
