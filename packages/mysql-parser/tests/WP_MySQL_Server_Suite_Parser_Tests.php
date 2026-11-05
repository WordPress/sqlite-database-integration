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
	 * The exact corpus tally. The rejected queries are multi-statement input, which
	 * needs the CLIENT_MULTI_STATEMENTS client flag. MySQL rejects them too without it.
	 *
	 * The exact failing set is pinned in FAILURES_PATH; update it when the accepted
	 * language legitimately changes.
	 */
	const EXPECTED_QUERIES  = 69370;
	const EXPECTED_FAILURES = 43;

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
			$sql_modes = isset( $record[1] ) ? explode( ',', $record[1] ) : array();
			$tokens    = ( new WP_MySQL_Lexer( $query, 80410, $sql_modes ) )->remaining_tokens();
			if ( null === $parser->parse( $tokens ) ) {
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
			'query after --error and --reap'         => array( 'CREATE PROCEDURE mIxEdCaSe() BEGIN END' ),
			'query after --error and --remove_file'  => array( 'drop procedure if exists bug13012' ),
		);
	}

	/**
	 * Pin queries that test files expect to fail, using the "error" command instead of
	 * "--error", because the tally can't see an additional query that parses.
	 *
	 * @dataProvider data_omitted_query_texts
	 */
	public function test_corpus_omits_query_text( string $query ): void {
		$this->assertNotContains( $query, $this->read_queries( self::CORPUS_PATH ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function data_omitted_query_texts(): array {
		return array(
			'error with a name'   => array( "BINLOG '\nRaNlZRMBAAAAMAAAAG0DAAAAAG8AAAAAAAEABHRlc3QAAnQxAAEDAAEBAQAg5VHP\nRaNlZR4BAAAAKAAAAJUDAAAAAG8AAAAAAAEAAgAB/wACAAAAczmjEA==\n'" ),
			'error with a number' => array( "BINLOG '\njKNlZRMBAAAAMAAAAOkFAAAAAG8AAAAAAAEABHRlc3QAAnQxAAEDAAEBAQCwxzWH\njKNlZR4BAAAAKAAAABEGAAAAAG8AAAAAAAEAAgAB/wAFAAAAVI7NfA==\n'" ),
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
