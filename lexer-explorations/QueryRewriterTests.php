<?php

require __DIR__ . '/translator.php';

use PHPUnit\Framework\TestCase;
use PhpMyAdmin\SqlParser\Token;

class QueryRewriterTests extends TestCase {


	public function testConsume() {
		$r = new QueryRewriter(
			array(
				new Token( ' ', WP_SQLite_Lexer::TYPE_DELIMITER ),
				new Token( 'int', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_DATA_TYPE ),
				new Token( ' ', WP_SQLite_Lexer::TYPE_DELIMITER ),
				new Token( 'DATE_ADD', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION ),
				new Token( ' ', WP_SQLite_Lexer::TYPE_DELIMITER ),
				new Token( 'SET', WP_SQLite_Lexer::TYPE_KEYWORD ),
				new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
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
		$r = new QueryRewriter(
			array(
				new Token( 'DO', WP_SQLite_Lexer::TYPE_KEYWORD ),
				new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
				new Token( 'UPDATE', WP_SQLite_Lexer::TYPE_KEYWORD ),
				new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
				new Token( 'SET', WP_SQLite_Lexer::TYPE_KEYWORD ),
				new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
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
		$r      = new QueryRewriter(
			array(
				new Token( 'DO', WP_SQLite_Lexer::TYPE_KEYWORD ),
				new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
				new Token( 'UPDATE', WP_SQLite_Lexer::TYPE_KEYWORD ),
				new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
				new Token( 'SET', WP_SQLite_Lexer::TYPE_KEYWORD ),
				new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
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
