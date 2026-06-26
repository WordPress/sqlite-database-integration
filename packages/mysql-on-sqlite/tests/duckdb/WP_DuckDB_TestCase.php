<?php

use PHPUnit\Framework\TestCase;

/**
 * Base class for DuckDB tests.
 */
abstract class WP_DuckDB_TestCase extends TestCase {
	/**
	 * Load an optional DuckDB Composer autoloader, if provided.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$autoload = getenv( 'WP_DUCKDB_AUTOLOAD' );
		if ( is_string( $autoload ) && '' !== $autoload && file_exists( $autoload ) ) {
			require_once $autoload;
		}
	}

	/**
	 * Require a usable DuckDB runtime.
	 */
	protected function requireDuckDBRuntime(): void {
		if ( '1' !== getenv( 'WP_DUCKDB_TESTS' ) ) {
			$this->markTestSkipped( 'DuckDB tests require WP_DUCKDB_TESTS=1.' );
		}

		$reason = WP_DuckDB_Runtime::get_unavailable_reason();
		if ( null !== $reason ) {
			$this->markTestSkipped( $reason );
		}
	}
}
