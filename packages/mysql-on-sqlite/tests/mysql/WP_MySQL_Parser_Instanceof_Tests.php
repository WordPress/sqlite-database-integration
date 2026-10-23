<?php

use PHPUnit\Framework\TestCase;

/**
 * Existing downstream code may rely on `if ($parser instanceof WP_Parser)`,
 * so this test pins that contract for the parser used by the SQLite driver.
 */
class WP_MySQL_Parser_Instanceof_Tests extends TestCase {

	public function test_parser_is_instance_of_wp_parser(): void {
		$grammar = new WP_Parser_Grammar( include __DIR__ . '/../../src/mysql/mysql-grammar.php' );
		$lexer   = new WP_MySQL_Lexer( 'SELECT 1' );
		$tokens  = $lexer->remaining_tokens();
		$parser  = new WP_MySQL_Parser( $grammar, $tokens );

		$this->assertInstanceOf( WP_Parser::class, $parser );
		$this->assertInstanceOf( WP_MySQL_Parser::class, $parser );
	}

	public function test_parser_returns_an_ast(): void {
		$grammar = new WP_Parser_Grammar( include __DIR__ . '/../../src/mysql/mysql-grammar.php' );
		$lexer   = new WP_MySQL_Lexer( 'SELECT 1 + 2' );
		$tokens  = $lexer->remaining_tokens();
		$parser  = new WP_MySQL_Parser( $grammar, $tokens );

		$ast = $parser->parse();
		$this->assertNotNull( $ast );
		$this->assertInstanceOf( WP_Parser_Node::class, $ast );
	}
}
