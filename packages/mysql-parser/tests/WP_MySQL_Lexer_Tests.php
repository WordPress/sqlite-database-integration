<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Bison token stream produced by WP_MySQL_Lexer::remaining_tokens().
 */
class WP_MySQL_Lexer_Tests extends TestCase {
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
}
