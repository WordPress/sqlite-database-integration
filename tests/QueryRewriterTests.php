<?php

require __DIR__ . '/class-wp-sqlite-query-rewriter.php';
require __DIR__ . '/class-wp-sqlite-lexer.php';

use PHPUnit\Framework\TestCase;

class WP_SQLite_Query_RewriterTests extends TestCase {


	public function testConsume() {
		$r = new WP_SQLite_Query_Rewriter(
			array(
				WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_DELIMITER ),
				WP_SQLite_Lexer::get_token( 'int', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_DATA_TYPE ),
				WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_DELIMITER ),
				WP_SQLite_Lexer::get_token( 'DATE_ADD', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION ),
				WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_DELIMITER ),
				WP_SQLite_Lexer::get_token( 'SET', WP_SQLite_Lexer::TYPE_KEYWORD ),
				WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
			)
		);
		$this->assertEquals(
			'int',
			$r->consume(
				array(
					'type'  => WP_SQLite_Lexer::TYPE_KEYWORD,
					'flags' => WP_SQLite_Lexer::FLAG_KEYWORD_DATA_TYPE,
				)
			)->value
		);
		$this->assertEquals(
			'DATE_ADD',
			$r->consume(
				array(
					'type'  => WP_SQLite_Lexer::TYPE_KEYWORD,
					'flags' => WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION,
				)
			)->value
		);
	}
	public function testSkip() {
		$r = new WP_SQLite_Query_Rewriter(
			array(
				WP_SQLite_Lexer::get_token( 'DO', WP_SQLite_Lexer::TYPE_KEYWORD ),
				WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
				WP_SQLite_Lexer::get_token( 'UPDATE', WP_SQLite_Lexer::TYPE_KEYWORD ),
				WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
				WP_SQLite_Lexer::get_token( 'SET', WP_SQLite_Lexer::TYPE_KEYWORD ),
				WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
			)
		);
		$this->assertEquals(
			'DO',
			$r->skip()->value
		);
		$this->assertEquals(
			'UPDATE',
			$r->skip()->value
		);
	}

	public function skip_over() {
		$r      = new WP_SQLite_Query_Rewriter(
			array(
				WP_SQLite_Lexer::get_token( 'DO', WP_SQLite_Lexer::TYPE_KEYWORD ),
				WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
				WP_SQLite_Lexer::get_token( 'UPDATE', WP_SQLite_Lexer::TYPE_KEYWORD ),
				WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
				WP_SQLite_Lexer::get_token( 'SET', WP_SQLite_Lexer::TYPE_KEYWORD ),
				WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
			)
		);
		$buffer = $r->skip_over(
			array(
				'values' => array( 'UPDATE' ),
			)
		);
		$this->assertCount( 3, $buffer );
		$this->assertEquals( 'DO', $buffer[0]->value );
		$this->assertEquals( ' ', $buffer[1]->value );
		$this->assertEquals( 'UPDATE', $buffer[2]->value );
	}

}
