<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for WP_MySQL_Token value and name resolution under the Bison vocabulary.
 */
class WP_MySQL_Token_Tests extends TestCase {
	/**
	 * Find the first token with the given terminal name.
	 *
	 * @param  string   $sql       The SQL payload to tokenize.
	 * @param  string   $name      The Bison terminal name to look for.
	 * @param  string[] $sql_modes The SQL modes to activate.
	 * @return WP_MySQL_Token      The first matching token.
	 */
	private static function first_token( string $sql, string $name, array $sql_modes = array() ): WP_MySQL_Token {
		foreach ( ( new WP_MySQL_Lexer( $sql, 80400, $sql_modes ) )->remaining_tokens() as $token ) {
			if ( $token->get_name() === $name ) {
				return $token;
			}
		}
		self::fail( "No $name token in: $sql" );
	}

	public function test_get_value_unquotes_string_literals(): void {
		$this->assertSame( "a'b", self::first_token( "SELECT 'a''b'", 'SINGLE_QUOTED_TEXT' )->get_value() );
		$this->assertSame( 'a"b', self::first_token( 'SELECT "a""b"', 'SINGLE_QUOTED_TEXT' )->get_value() );
		$this->assertSame( "new\nline", self::first_token( "SELECT 'new\\nline'", 'SINGLE_QUOTED_TEXT' )->get_value() );
	}

	public function test_get_value_honors_no_backslash_escapes_mode(): void {
		$token = self::first_token( "SELECT 'a\\nb'", 'SINGLE_QUOTED_TEXT', array( 'NO_BACKSLASH_ESCAPES' ) );
		$this->assertSame( 'a\\nb', $token->get_value() );
	}

	public function test_get_value_unquotes_backtick_identifiers(): void {
		$this->assertSame( 'col name', self::first_token( 'SELECT `col name` FROM t', 'BACK_TICK_QUOTED_ID' )->get_value() );
	}

	public function test_get_value_unquotes_ansi_identifiers(): void {
		$this->assertSame(
			'a"b',
			self::first_token( 'SELECT "a""b"', 'BACK_TICK_QUOTED_ID', array( 'ANSI_QUOTES' ) )->get_value()
		);
	}

	public function test_get_value_does_not_unquote_unquoted_tokens(): void {
		// The SSL keyword's Bison number collides with one of the lexer's
		// internal quoted-text constants; value extraction must not be fooled
		// by token ids and must return keyword bytes as-is.
		$this->assertSame( 'SSL', self::first_token( 'CREATE USER u REQUIRE SSL', 'SSL' )->get_value() );
		$this->assertSame( 'SELECT', self::first_token( 'SELECT 1', 'SELECT' )->get_value() );
		$this->assertSame( '42', self::first_token( 'SELECT 42', 'INT_NUMBER' )->get_value() );
	}

	public function test_get_name_resolves_bison_terminal_names(): void {
		$tokens = ( new WP_MySQL_Lexer( "SELECT 'text', (1)" ) )->remaining_tokens();
		$names  = array();
		foreach ( $tokens as $token ) {
			$names[] = $token->get_name();
		}
		$this->assertSame(
			array( 'SELECT', 'SINGLE_QUOTED_TEXT', 'COMMA_SYMBOL', 'OPEN_PAR_SYMBOL', 'INT_NUMBER', 'CLOSE_PAR_SYMBOL', 'END_OF_INPUT', 'END_MARKER' ),
			$names
		);
	}

	public function test_get_name_returns_unknown_for_unmapped_ids(): void {
		$token = new WP_MySQL_Token( 999999, 0, 0, '', false );
		$this->assertSame( 'UNKNOWN', $token->get_name() );
	}
}
