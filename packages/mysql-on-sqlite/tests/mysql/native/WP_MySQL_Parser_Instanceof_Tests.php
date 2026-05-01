<?php

use PHPUnit\Framework\TestCase;

/**
 * `WP_MySQL_Parser instanceof WP_Parser` must hold in both modes.
 *
 * Pre-PR, the native-mode `WP_MySQL_Parser` was `extends WP_MySQL_Native_Parser`
 * (a Rust-registered class with no `WP_Parser` in its chain), so existing
 * downstream code doing `if ($parser instanceof WP_Parser)` silently
 * skipped the parser when the extension was loaded. This test pins the
 * contract for both modes.
 */
class WP_MySQL_Parser_Instanceof_Tests extends TestCase {

	public function test_parser_is_instance_of_wp_parser(): void {
		$grammar = new WP_Parser_Grammar( include __DIR__ . '/../../../src/mysql/mysql-grammar.php' );
		$lexer   = new WP_MySQL_Lexer( 'SELECT 1' );
		$tokens  = $lexer instanceof WP_MySQL_Native_Lexer
			? $lexer->native_token_stream()
			: $lexer->remaining_tokens();
		$parser  = new WP_MySQL_Parser( $grammar, $tokens );

		$this->assertInstanceOf( WP_Parser::class, $parser );
		$this->assertInstanceOf( WP_MySQL_Parser::class, $parser );
	}

	public function test_parser_returns_an_ast(): void {
		$grammar = new WP_Parser_Grammar( include __DIR__ . '/../../../src/mysql/mysql-grammar.php' );
		$lexer   = new WP_MySQL_Lexer( 'SELECT 1 + 2' );
		$tokens  = $lexer instanceof WP_MySQL_Native_Lexer
			? $lexer->native_token_stream()
			: $lexer->remaining_tokens();
		$parser  = new WP_MySQL_Parser( $grammar, $tokens );

		$ast = $parser->parse();
		$this->assertNotNull( $ast );
		$this->assertInstanceOf( WP_Parser_Node::class, $ast );
	}
}
