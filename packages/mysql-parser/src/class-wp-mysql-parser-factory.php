<?php

/**
 * A MySQL grammar and parser factory.
 */
class WP_MySQL_Parser_Factory {
	/**
	 * The path to the generated MySQL parse table.
	 */
	const PARSE_TABLE_PATH = __DIR__ . '/mysql-parse-table.php';

	/**
	 * The MySQL grammar, shared by every parser this factory creates.
	 *
	 * @var WP_Parser_Grammar|null
	 */
	private static $grammar;

	/**
	 * Create a parser for MySQL.
	 *
	 * The grammar is expensive to expand, so it is built once and shared by all
	 * parsers this method returns.
	 *
	 * @return WP_Parser
	 */
	public static function create_parser(): WP_Parser {
		if ( null === self::$grammar ) {
			self::$grammar = self::create_grammar();
		}
		return new WP_Parser( self::$grammar );
	}

	/**
	 * Create a MySQL grammar.
	 *
	 * @return WP_Parser_Grammar
	 */
	public static function create_grammar(): WP_Parser_Grammar {
		return new WP_Parser_Grammar( require self::PARSE_TABLE_PATH );
	}
}
