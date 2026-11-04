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
	 * The exact corpus tally: rejected queries are multi-statement input, a few
	 * unsupported statements (such as LANGUAGE JAVASCRIPT function bodies), and
	 * session-dependent or lexer edge cases (see the README). The exact failing
	 * set is pinned in FAILURES_PATH; update it when the accepted language
	 * legitimately changes.
	 */
	const EXPECTED_QUERIES  = 69076;
	const EXPECTED_FAILURES = 166;

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
			$tokens = ( new WP_MySQL_Lexer( $query, 80410 ) )->remaining_tokens();
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
			'SET NAMES with --character_set latin1'  => array( 'CREATE TABLE t1(колонка1 INT)' ),
			'SET NAMES before --character_set'       => array( "insert into t1 select repeat('я',300)" ),
			'UTF-8 bytes that MySQL reads as latin1' => array( 'insert into t1 (id, name) values (1, "Ã³ra")' ),
			'UTF-8 literal in a koi8r line'          => array( "CREATE VIEW v2 AS SELECT _utf8mb3'тест' as c1" ),
			'koi8r literal in a UTF-8 line'          => array( "CREATE VIEW v2 AS SELECT _koi8r'тест' as c1" ),
			'line feed in a version comment'         => array( "DO 1 /*!80034\n+1*/" ),
			'vertical tab in a version comment'      => array( "DO 1 /*!80034\v+1*/" ),
			'form feed in a version comment'         => array( "DO 1 /*!80034\f+1*/" ),
			'carriage return in a version comment'   => array( "DO 1 /*!80034\r+1*/" ),
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
		$handle  = fopen( $path, 'r' );
		$queries = array();
		while ( ( $record = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false ) {
			$query = $record[0] ?? null;
			if ( null === $query || '' === $query ) {
				continue;
			}
			$queries[] = $query;
		}
		fclose( $handle );
		return $queries;
	}
}
