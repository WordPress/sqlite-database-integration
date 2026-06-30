<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the WP_Parser runtime, driven by the MySQL grammar.
 */
class WP_MySQL_Parser_Tests extends TestCase {
	/**
	 * The parser under test (the parse table is decoded once for the suite).
	 *
	 * @var WP_Parser
	 */
	private static $parser;

	public static function setUpBeforeClass(): void {
		self::$parser = WP_MySQL_Parser_Factory::create_parser();
	}

	/**
	 * Parse a query end to end.
	 *
	 * @param  string $sql The SQL payload to parse.
	 * @return WP_Parser_Node|null The AST root, or null on a syntax error.
	 */
	private static function parse( string $sql ): ?WP_Parser_Node {
		return self::$parser->parse( ( new WP_MySQL_Lexer( $sql ) )->remaining_tokens() );
	}

	public function test_accept_returns_the_ast_root(): void {
		$ast = self::parse( 'SELECT 1' );
		$this->assertInstanceOf( WP_Parser_Node::class, $ast );
		$this->assertSame( 'start_entry', $ast->rule_name );
	}

	public function test_syntax_error_returns_null(): void {
		$this->assertNull( self::parse( 'SELECT FROM WHERE' ) );
		$this->assertNull( self::parse( 'NOT A QUERY AT ALL !!!' ) );
	}

	public function test_empty_token_stream_returns_null(): void {
		$this->assertNull( self::$parser->parse( array() ) );
	}

	public function test_partial_token_stream_returns_null(): void {
		// Invalid input makes the lexer return a partial stream without the
		// $end terminator; the parser must reject it without reading past the
		// end of the token array (PHPUnit converts warnings to exceptions).
		$tokens = ( new WP_MySQL_Lexer( "SELECT 1;\xC0" ) )->remaining_tokens();
		$this->assertNull( self::$parser->parse( $tokens ) );

		$tokens = ( new WP_MySQL_Lexer( "SELECT \x01" ) )->remaining_tokens();
		$this->assertNull( self::$parser->parse( $tokens ) );
	}

	public function test_ast_contains_grammar_rule_nodes(): void {
		$ast = self::parse( 'SELECT a + 1 FROM t WHERE b = 2' );
		$this->assertNotNull( $ast );
		$this->assertNotNull( $ast->get_first_descendant_node( 'query_specification' ) );
		$this->assertNotNull( $ast->get_first_descendant_node( 'select_item' ) );
		$this->assertNotNull( $ast->get_first_descendant_node( 'from_clause' ) );
		$this->assertNotNull( $ast->get_first_descendant_node( 'where_clause' ) );
	}

	public function test_default_ast_materialises_every_rule(): void {
		// By default every reduced rule becomes a node, including single-child
		// wrapper rules like predicate and simple_statement.
		$ast = self::parse( 'SELECT a + 1 FROM t WHERE b = 2' );
		$this->assertNotNull( $ast->get_first_descendant_node( 'bool_pri' ) );
		$this->assertNotNull( $ast->get_first_descendant_node( 'predicate' ) );
		$this->assertNotNull( $ast->get_first_descendant_node( 'simple_statement' ) );
	}

	public function test_tokens_become_ast_leaves_with_source_positions(): void {
		$sql = 'SELECT name FROM users';
		$ast = self::parse( $sql );
		$this->assertNotNull( $ast );

		$tokens = $ast->get_descendant_tokens();
		$this->assertNotEmpty( $tokens );
		foreach ( $tokens as $token ) {
			$this->assertSame(
				substr( $sql, $token->start, $token->length ),
				$token->get_bytes()
			);
		}
	}

	public function test_parses_representative_statements(): void {
		$statements = array(
			'SELECT 1',
			'SELECT * FROM t1 JOIN t2 ON t1.id = t2.id',
			'SELECT COUNT(*), MAX(a) FROM t GROUP BY b HAVING COUNT(*) > 1 ORDER BY 1 LIMIT 10',
			'SELECT a FROM t GROUP BY a WITH ROLLUP',
			'WITH c AS (SELECT 1 AS x) SELECT x FROM c',
			'INSERT INTO t (a, b) VALUES (1, 2), (3, 4)',
			'UPDATE t SET a = a + 1 WHERE b IN (SELECT b FROM u)',
			'DELETE FROM t WHERE a IS NOT NULL',
			'CREATE TABLE t (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255))',
			'ALTER TABLE t ADD COLUMN c JSON',
			'DROP TABLE IF EXISTS t',
			'SET @v = 1',
			'SET @@SESSION.sql_mode = \'\'',
			'SHOW TABLES',
			'EXPLAIN SELECT 1',
		);
		foreach ( $statements as $sql ) {
			$this->assertNotNull( self::parse( $sql ), "Failed to parse: $sql" );
		}
	}

	public function test_rejects_statements_invalid_in_mysql_84(): void {
		$statements = array(
			'RESET MASTER',          // Removed in MySQL 8.4.
			'SHOW SLAVE STATUS',     // Removed in MySQL 8.4.
			'SELECT 1; SELECT 2',    // Multi-statement input.
			'CREATE TABLE current_date (a INT)',   // Reserved word as a table name.
		);
		foreach ( $statements as $sql ) {
			$this->assertNull( self::parse( $sql ), "Should not parse: $sql" );
		}
	}
}
