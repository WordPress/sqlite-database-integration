<?php

use PHPUnit\Framework\TestCase;

/**
 * Regression test over the MySQL server test corpus.
 *
 * Pins the corpus acceptance rate so any lexer, token-map, or parse-table
 * change that silently shifts the accepted language fails loudly. The corpus
 * is shared with the mysql-on-sqlite package.
 */
class WP_MySQL_Server_Suite_Parser_Tests extends TestCase {
	const CORPUS_PATH = __DIR__ . '/../../mysql-on-sqlite/tests/mysql/data/mysql-server-tests-queries.csv';

	/**
	 * The exact corpus tally: rejected queries are 8.4-removed syntax,
	 * multi-statement input, session-dependent SQL modes, and a few lexer
	 * edge cases (see the README).
	 */
	const EXPECTED_QUERIES  = 69577;
	const EXPECTED_FAILURES = 86;

	public function test_corpus_acceptance_rate(): void {
		if ( ! is_readable( self::CORPUS_PATH ) ) {
			$this->markTestSkipped( 'The mysql-on-sqlite corpus is not available.' );
		}

		$parser = new WP_MySQL_Parser( require __DIR__ . '/../src/grammar/parse-table.php' );

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

		$this->assertSame( self::EXPECTED_QUERIES, $total );
		$this->assertCount(
			self::EXPECTED_FAILURES,
			$failures,
			"Corpus failure count changed; first differences:\n"
				. implode( "\n", array_slice( $failures, 0, 10 ) )
		);
	}
}
