<?php

use PHPUnit\Framework\TestCase;

/**
 * Invariants of the generated grammar token data (src/grammar/tokens.php).
 */
class WP_MySQL_Tokens_Tests extends TestCase {
	public function test_keyword_table_is_consistent(): void {
		$this->assertNotEmpty( WP_MySQL_Tokens::KEYWORDS );
		foreach ( WP_MySQL_Tokens::KEYWORDS as $keyword => $number ) {
			$this->assertArrayHasKey(
				$number,
				WP_MySQL_Tokens::TOKEN_NAMES,
				"Keyword $keyword maps to token $number, which has no terminal name"
			);
		}
	}

	public function test_function_keywords_are_a_subset_of_keywords(): void {
		$this->assertNotEmpty( WP_MySQL_Tokens::FUNCTIONS );
		foreach ( array_keys( WP_MySQL_Tokens::FUNCTIONS ) as $keyword ) {
			$this->assertArrayHasKey( $keyword, WP_MySQL_Tokens::KEYWORDS );
		}
	}

	public function test_keyword_synonyms_share_token_numbers(): void {
		// lex.h maps synonymous keywords to the same terminal, so synonyms need
		// no handling anywhere in the lexer.
		$this->assertSame( WP_MySQL_Tokens::KEYWORDS['CURRENT_DATE'], WP_MySQL_Tokens::KEYWORDS['CURDATE'] );
		$this->assertSame( WP_MySQL_Tokens::KEYWORDS['DATABASE'], WP_MySQL_Tokens::KEYWORDS['SCHEMA'] );
		$this->assertSame( WP_MySQL_Tokens::KEYWORDS['INT'], WP_MySQL_Tokens::KEYWORDS['INTEGER'] );

		// Only the paren-gated variant is a function keyword.
		$this->assertArrayHasKey( 'CURDATE', WP_MySQL_Tokens::FUNCTIONS );
		$this->assertArrayNotHasKey( 'CURRENT_DATE', WP_MySQL_Tokens::FUNCTIONS );
	}

	public function test_hint_only_keywords_are_omitted(): void {
		// Hint-only keywords (lex.h SYM_H with a terminal outside the grammar)
		// are recognized by MySQL only inside optimizer hints; outside them they
		// are plain identifiers, so they must not be in the keyword table.
		$this->assertArrayNotHasKey( 'SET_VAR', WP_MySQL_Tokens::KEYWORDS );
		$this->assertArrayNotHasKey( 'BKA', WP_MySQL_Tokens::KEYWORDS );
	}

	public function test_structural_constants_resolve_to_their_terminals(): void {
		$this->assertSame( 'IDENT', WP_MySQL_Tokens::TOKEN_NAMES[ WP_MySQL_Tokens::IDENTIFIER ] );
		$this->assertSame( 'TEXT_STRING', WP_MySQL_Tokens::TOKEN_NAMES[ WP_MySQL_Tokens::SINGLE_QUOTED_TEXT ] );
		$this->assertSame( "'@'", WP_MySQL_Tokens::TOKEN_NAMES[ WP_MySQL_Tokens::AT_SIGN_SYMBOL ] );
		$this->assertSame( 'WITH_ROLLUP_SYM', WP_MySQL_Tokens::TOKEN_NAMES[ WP_MySQL_Tokens::WITH_ROLLUP_SYMBOL ] );
		$this->assertSame( 'END_OF_INPUT', WP_MySQL_Tokens::TOKEN_NAMES[ WP_MySQL_Tokens::END_OF_INPUT ] );
		$this->assertSame( '$end', WP_MySQL_Tokens::TOKEN_NAMES[ WP_MySQL_Tokens::END_MARKER ] );
	}

	public function test_internal_scanner_tokens_cannot_collide_with_grammar_tokens(): void {
		// Grammar token numbers are non-negative; the lexer's internal pseudo
		// tokens must all be negative and absent from the terminal names.
		foreach ( array( WP_MySQL_Lexer::EOF, WP_MySQL_Lexer::WHITESPACE, WP_MySQL_Lexer::COMMENT, WP_MySQL_Lexer::AT_TEXT_SUFFIX, WP_MySQL_Lexer::AT_AT_SIGN_SYMBOL ) as $internal ) {
			$this->assertLessThan( 0, $internal );
			$this->assertArrayNotHasKey( $internal, WP_MySQL_Tokens::TOKEN_NAMES );
		}
	}
}
