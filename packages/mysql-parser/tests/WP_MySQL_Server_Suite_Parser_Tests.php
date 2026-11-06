<?php

use PHPUnit\Framework\TestCase;

/**
 * Regression test over the MySQL server test corpus.
 *
 * Pins the corpus acceptance rate so any lexer, token-map, or parse-table
 * change that silently shifts the accepted language fails loudly.
 */
class WP_MySQL_Server_Suite_Parser_Tests extends TestCase {
	const CORPUS_PATH   = __DIR__ . '/../data/mysql-server-query-corpus/mysql-latest.csv';
	const FAILURES_PATH = __DIR__ . '/data/corpus-failures.csv';

	/**
	 * The exact corpus tally. The parser should reject a row marked as a syntax error,
	 * and accept any other row. A failure is a row where it does the opposite.
	 *
	 * 45 failures are regular rows that the parser rejects. They are multi-statement
	 * input, which needs the CLIENT_MULTI_STATEMENTS client flag. MySQL rejects them
	 * too without it.
	 *
	 * 99 failures are marked rows that the parser accepts:
	 * - 97 are rejected by checks that run during MySQL's parsing, in grammar actions
	 *   and parse tree classes. This parser has neither.
	 * - 2 use "?" outside a prepared statement. MySQL allows it only when preparing,
	 *   and this lexer always allows it.
	 *
	 * The exact failing set is pinned in FAILURES_PATH; update it when the accepted
	 * language legitimately changes.
	 */
	const EXPECTED_QUERIES  = 78153;
	const EXPECTED_FAILURES = 144;

	public function test_corpus_acceptance_rate(): void {
		$parser = WP_MySQL_Parser_Factory::create_parser();

		$handle   = fopen( self::CORPUS_PATH, 'r' );
		$total    = 0;
		$failures = array();
		while ( ( $record = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false ) {
			$query = $record[0] ?? null;
			if ( null === $query || '' === $query ) {
				continue;
			}
			++$total;

			// Pin the MySQL version. The failure set in FAILURES_PATH depends on it.
			$sql_modes = array_filter( explode( ',', $record[1] ?? '' ) );
			$tokens    = ( new WP_MySQL_Lexer( $query, 80410, $sql_modes ) )->remaining_tokens();
			$is_parsed = null !== $parser->parse( $tokens );
			if ( isset( $record[2] ) === $is_parsed ) {
				$failures[] = $query;
			}
		}
		fclose( $handle );
		sort( $failures, SORT_STRING );

		// Fast-fail guards on the corpus size and the failure count.
		$this->assertSame( self::EXPECTED_QUERIES, $total );
		$this->assertCount( self::EXPECTED_FAILURES, $failures );

		// Pin the exact failing set, so a compensating shift (one query newly
		// failing while another newly passes) cannot hide behind a stable count.
		$this->assertSame( $this->read_expected_failures(), $failures );
	}

	/**
	 * Pin the text of queries that are easy to misread from the test files, because
	 * the tally can't see a change in the text of a query that still parses.
	 *
	 * @dataProvider data_query_texts
	 */
	public function test_corpus_contains_query_text( string $query ): void {
		static $corpus = null;
		if ( null === $corpus ) {
			$corpus = array_flip( $this->read_queries( self::CORPUS_PATH ) );
		}
		$this->assertArrayHasKey( $query, $corpus );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function data_query_texts(): array {
		return array(
			'SET NAMES overrides --character_set'    => array( 'create table `été` (field1 int)' ),
			'SET NAMES without --character_set'      => array( 'create database бд' ),
			'SET NAMES cp1250'                       => array( 'insert into t1 values("ááčč")' ),
			'SET NAMES with --character_set latin1'  => array( 'CREATE TABLE t1(колонка1 INT)' ),
			'SET CHARACTER SET'                      => array( "INSERT INTO t1 VALUES ('ŁĄŞŻ')" ),
			'SET NAMES before --character_set'       => array( "insert into t1 select repeat('я',300)" ),
			'UTF-8 bytes that MySQL reads as latin1' => array( 'insert into t1 (id, name) values (1, "Ã³ra")' ),
			'UTF-8 literal in a koi8r line'          => array( "CREATE VIEW v2 AS SELECT _utf8mb3'тест' as c1" ),
			'koi8r literal in a UTF-8 line'          => array( "CREATE VIEW v2 AS SELECT _koi8r'тест' as c1" ),
			'line feed in a version comment'         => array( "DO 1 /*!80034\n+1*/" ),
			'vertical tab in a version comment'      => array( "DO 1 /*!80034\v+1*/" ),
			'form feed in a version comment'         => array( "DO 1 /*!80034\f+1*/" ),
			'carriage return in a version comment'   => array( "DO 1 /*!80034\r+1*/" ),
			'a delimiter set with --delimiter'       => array( "CREATE PROCEDURE p_flush_status()\nBEGIN\nDECLARE x INT DEFAULT 3000;\nWHILE x DO\nSET x = x-1;\nFLUSH STATUS;\nEND WHILE;\nEND " ),
			'a query after --delimiter ;'            => array( 'DROP PROCEDURE p_flush_status' ),
			'a delimiter that starts with a quote'   => array( "select \"abcd'\";" ),
			'a query after an indented --error'      => array( 'call po_add_order_line (99, 1, 100, 1)' ),
			'a query after an indented comment'      => array( "UPDATE t0 SET c0 = 1 WHERE (t0.c0 IS NULL)>>('' COLLATE 'utf8mb4_0900_ai_ci')" ),
			'a query after an indented command'      => array( "DO version_tokens_lock_exclusive('vt3', 'vt5', 'vt1', 4)" ),
		);
	}

	/**
	 * Pin how queries that test files expect to fail are marked in each set of modes.
	 * A query is marked only if every occurrence of it expects a syntax error, or if
	 * the extractor lists it.
	 *
	 * @dataProvider data_query_markers
	 */
	public function test_corpus_marks_expected_syntax_errors( string $query, array $expected ): void {
		static $markers = null;
		if ( null === $markers ) {
			$markers = array();
			foreach ( $this->read_records( self::CORPUS_PATH ) as $record ) {
				$markers[ $record[0] ][ $record[1] ?? '' ] = $record[2] ?? '';
			}
		}
		$this->assertSame( $expected, $markers[ $query ] ?? array() );
	}

	/**
	 * @return array<string,array{0:string,1:array<string,string>}>
	 */
	public function data_query_markers(): array {
		$regular      = array( '' => '' );
		$syntax_error = array( '' => 'syntax_error' );
		return array(
			'--error with a name'                 => array( "select t1.* as 'with_alias' from t1", $syntax_error ),
			'--error with a number'               => array( 'analyze table t1 extended', $syntax_error ),
			'-- error with a number'              => array( 'CREATE TABLE t2 (a INT, b INT DEFAULT (select count(*) from t1))', $syntax_error ),
			'a syntax error after --delimiter'    => array( 'create table t1 (a int) transactional=0', $syntax_error ),
			'--error with another error listed'   => array( 'explain select cast(1 as decimal(4294967296))', $regular ),
			'a listed query with another error'   => array( 'explain select cast(1 as decimal(64, 999999999999999999999999999999))', $syntax_error ),
			'a listed query after a semantic one' => array( 'GET DIAGNOSTICS var', $syntax_error ),
			'a listed query the parser accepts'   => array( "alter table t1 add partition\n(partition p1 values in (maxvalue, maxvalue))", $syntax_error ),
			'--error that also allows success'    => array( '((SELECT * FROM t1 LIMIT 1) LIMIT 2) LIMIT 3', $regular ),
			'PREPARE with text'                   => array( 'prepare stmt from "select ??"', $regular ),
			'PREPARE after an --error comment'    => array( "prepare s from 'select GROUP_CONCAT(DISTINCT ?,? ORDER BY ? SEPARATOR ?)'", $regular ),
			'PREPARE with a variable'             => array( 'prepare stmt1 from @nosuchvar', $regular ),
			'PREPARE without text'                => array( 'prepare stmt1 from ', $syntax_error ),
			'PREPARE without FROM'                => array( "prepare stmt1 from_garbage ' select 1 ' ", $syntax_error ),
			'PREPARE without a name'              => array( "prepare from ' select 1 ' ", $syntax_error ),
			'PREPARE with an invalid name'        => array( "prepare ? from ' select ? as my_col ' ", $syntax_error ),
			'debug_sync.test'                     => array( "SET DEBUG_SYNC='p0'", $regular ),
			'trigger-compat-debug.test'           => array( 'UPDATE t1 SET a = 1 WHERE a = 1', $regular ),
			'ctype_latin1.test'                   => array( "CREATE TABLE \u{201E}a (a int)", $regular ),
			'a syntax error only in IGNORE_SPACE' => array(
				'create table SUM (a int)',
				array(
					''             => '',
					'IGNORE_SPACE' => 'syntax_error',
				),
			),
			'a syntax error before a regular run' => array( 'INSERT INTO th1 VALUES(1)', $regular ),
			'a syntax error and another error'    => array( 'DELETE FROM t1', $regular ),
			'--error with another error only'     => array( 'create table t1 select * from t1', $regular ),
			'--error with ER_SYNTAX_ERROR'        => array( 'GRANT abc ON *.* TO testrole', $regular ),
		);
	}

	/**
	 * Pin the SQL modes of queries that test files select them for in different ways,
	 * because the tally can't see a change in the modes of a query that still parses.
	 *
	 * @dataProvider data_query_sql_modes
	 */
	public function test_corpus_stores_query_sql_modes( string $query, array $expected ): void {
		static $sql_modes = null;
		if ( null === $sql_modes ) {
			$sql_modes = array();
			foreach ( $this->read_records( self::CORPUS_PATH ) as $record ) {
				$sql_modes[ $record[0] ][] = $record[1] ?? '';
			}
		}
		$this->assertSame( $expected, $sql_modes[ $query ] ?? array() );
	}

	/**
	 * @return array<string,array{0:string,1:string[]}>
	 */
	public function data_query_sql_modes(): array {
		return array(
			'no modes'             => array( "DO 1 /*!80034\n+1*/", array( '' ) ),
			'SET sql_mode'         => array( 'SELECT * from "full"', array( 'ANSI_QUOTES' ) ),
			'the ANSI mode'        => array( 'create table t1 ("a*b" int)', array( 'ANSI_QUOTES,IGNORE_SPACE,PIPES_AS_CONCAT' ) ),
			'CONCAT(@@sql_mode)'   => array( "SELECT 'a%' LIKE 'a!%' ESCAPE '!', 'a%' LIKE 'a!' || '%' ESCAPE '!'", array( 'PIPES_AS_CONCAT' ) ),
			'sys.LIST_ADD'         => array( 'select "$id2", "$$$" from t where t."$id" = 0', array( 'ANSI_QUOTES' ) ),
			'NO_BACKSLASH_ESCAPES' => array( "INSERT INTO t1 (id, val1) VALUES (1, '\\aa')", array( 'NO_BACKSLASH_ESCAPES', '' ) ),
			'two sets of modes'    => array( 'select * from t1 where not a between 2 and 3', array( 'HIGH_NOT_PRECEDENCE', '' ) ),
			'IGNORE_SPACE'         => array( 'create table ADDDATE (a int)', array( '', 'IGNORE_SPACE' ) ),
		);
	}

	/**
	 * Read the pinned set of failing queries, sorted to match the live set.
	 *
	 * @return string[] The expected failing queries.
	 */
	private function read_expected_failures(): array {
		$expected = $this->read_queries( self::FAILURES_PATH );
		sort( $expected, SORT_STRING );
		return $expected;
	}

	/**
	 * Read the queries from a CSV file.
	 *
	 * @param string $path The path to the CSV file.
	 * @return string[] The queries.
	 */
	private function read_queries( string $path ): array {
		return array_column( $this->read_records( $path ), 0 );
	}

	/**
	 * Read the records of the queries in a CSV file.
	 *
	 * @param string $path The path to the CSV file.
	 * @return array<int,string[]> The records.
	 */
	private function read_records( string $path ): array {
		$handle  = fopen( $path, 'r' );
		$records = array();
		while ( ( $record = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false ) {
			$query = $record[0] ?? null;
			if ( null === $query || '' === $query ) {
				continue;
			}
			$records[] = $record;
		}
		fclose( $handle );
		return $records;
	}
}
