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
	const EXPECTED_QUERIES  = 69158;
	const EXPECTED_FAILURES = 168;

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
			$tokens = ( new WP_MySQL_Lexer( $query ) )->remaining_tokens();
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
	 * Read the pinned set of failing queries, sorted to match the live set.
	 *
	 * @return string[] The expected failing queries.
	 */
	private function read_expected_failures(): array {
		$handle   = fopen( self::FAILURES_PATH, 'r' );
		$expected = array();
		while ( ( $record = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false ) {
			$query = $record[0] ?? null;
			if ( null === $query || '' === $query ) {
				continue;
			}
			$expected[] = $query;
		}
		fclose( $handle );
		sort( $expected, SORT_STRING );
		return $expected;
	}
}
