<?php

use PHPUnit\Framework\TestCase;

/**
 * Invariants of the generated grammar token data (src/grammar/tokens.php) and
 * the reflection-derived token names.
 */
class WP_MySQL_Tokens_Tests extends TestCase {
	public function test_every_emittable_token_has_a_derived_name(): void {
		// Names are not stored; they are derived from KEYWORDS and the named
		// constants. Every id the lexer can emit must resolve to a name.
		$this->assertNotEmpty( WP_MySQL_Tokens::KEYWORDS );
		foreach ( WP_MySQL_Tokens::KEYWORDS as $keyword => $number ) {
			$this->assertNotNull(
				WP_MySQL_Lexer::get_token_name( $number ),
				"Keyword $keyword (token $number) has no derivable name"
			);
		}
		foreach ( ( new ReflectionClass( WP_MySQL_Tokens::class ) )->getConstants() as $name => $value ) {
			if ( is_int( $value ) ) {
				$this->assertNotNull(
					WP_MySQL_Lexer::get_token_name( $value ),
					"Constant $name (token $value) has no derivable name"
				);
			}
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

	public function test_derived_names_prefer_plain_keywords_and_constants(): void {
		// Keyword string for keyword tokens; on synonym collisions the plain
		// keyword wins over the paren-gated function variant.
		$this->assertSame( 'SELECT', WP_MySQL_Lexer::get_token_name( WP_MySQL_Tokens::KEYWORDS['SELECT'] ) );
		$this->assertSame( 'USER', WP_MySQL_Lexer::get_token_name( WP_MySQL_Tokens::KEYWORDS['USER'] ) );
		$this->assertSame( 'CURRENT_DATE', WP_MySQL_Lexer::get_token_name( WP_MySQL_Tokens::KEYWORDS['CURDATE'] ) );

		// Constant name for non-keyword tokens.
		$this->assertSame( 'IDENTIFIER', WP_MySQL_Lexer::get_token_name( WP_MySQL_Tokens::IDENTIFIER ) );
		$this->assertSame( 'SINGLE_QUOTED_TEXT', WP_MySQL_Lexer::get_token_name( WP_MySQL_Tokens::SINGLE_QUOTED_TEXT ) );
		$this->assertSame( 'AT_SIGN_SYMBOL', WP_MySQL_Lexer::get_token_name( WP_MySQL_Tokens::AT_SIGN_SYMBOL ) );
		$this->assertSame( 'WITH_ROLLUP_SYMBOL', WP_MySQL_Lexer::get_token_name( WP_MySQL_Tokens::WITH_ROLLUP_SYMBOL ) );
		$this->assertSame( 'END_OF_INPUT', WP_MySQL_Lexer::get_token_name( WP_MySQL_Tokens::END_OF_INPUT ) );
		$this->assertSame( 'END_MARKER', WP_MySQL_Lexer::get_token_name( WP_MySQL_Tokens::END_MARKER ) );
	}

	public function test_internal_scanner_tokens_cannot_collide_with_grammar_tokens(): void {
		// Grammar token numbers are non-negative; the lexer's internal pseudo
		// tokens must all be negative and must not resolve to a token name.
		foreach ( array( WP_MySQL_Lexer::EOF, WP_MySQL_Lexer::WHITESPACE, WP_MySQL_Lexer::COMMENT, WP_MySQL_Lexer::AT_TEXT_SUFFIX, WP_MySQL_Lexer::AT_AT_SIGN_SYMBOL ) as $internal ) {
			$this->assertLessThan( 0, $internal );
			$this->assertNull( WP_MySQL_Lexer::get_token_name( $internal ) );
		}
	}
}
