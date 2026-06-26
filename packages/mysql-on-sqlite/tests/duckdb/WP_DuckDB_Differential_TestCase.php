<?php

require_once __DIR__ . '/WP_DuckDB_TestCase.php';

/**
 * Base class for opt-in SQLite-vs-DuckDB parity tests.
 */
abstract class WP_DuckDB_Differential_TestCase extends WP_DuckDB_TestCase {
	/**
	 * @var WP_SQLite_Driver
	 */
	private $sqlite_driver;

	/**
	 * @var WP_DuckDB_Driver
	 */
	private $duckdb_driver;

	protected function setUp(): void {
		parent::setUp();

		$this->requireDuckDBRuntime();

		$this->sqlite_driver = new WP_SQLite_Driver(
			new WP_SQLite_Connection( array( 'path' => ':memory:' ) ),
			'wp'
		);
		$this->duckdb_driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
	}

	/**
	 * Run SQL on both engines and compare normalized rows.
	 *
	 * @param string $sql SQL query.
	 */
	protected function assertParityRows( string $sql ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$sqlite_rows = $this->query_sqlite_rows( $sql );
		$duckdb_rows = $this->query_duckdb_rows( $sql );

		$this->assertSame(
			$this->normalize_rows( $sqlite_rows ),
			$this->normalize_rows( $duckdb_rows ),
			'Row parity failed for SQL: ' . $sql
		);
	}

	/**
	 * Run SQL on both engines and compare selected normalized row fields.
	 *
	 * @param string   $sql     SQL query.
	 * @param string[] $columns Columns to compare.
	 */
	protected function assertParityRowColumns( string $sql, array $columns ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$sqlite_rows = $this->select_columns( $this->query_sqlite_rows( $sql ), $columns );
		$duckdb_rows = $this->select_columns( $this->query_duckdb_rows( $sql ), $columns );

		$this->assertSame(
			$this->normalize_rows( $sqlite_rows ),
			$this->normalize_rows( $duckdb_rows ),
			'Selected row parity failed for SQL: ' . $sql
		);
	}

	/**
	 * Run SQL on both engines and compare row counts.
	 *
	 * @param string $sql SQL query.
	 */
	protected function assertParityRowCount( string $sql ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$this->assertSame(
			$this->query_sqlite_row_count( $sql ),
			$this->query_duckdb_row_count( $sql ),
			'Row-count parity failed for SQL: ' . $sql
		);
	}

	/**
	 * Run SQL on both engines and assert both fail with a matching message.
	 *
	 * @param string $sql    SQL query.
	 * @param string $needle Expected message fragment.
	 */
	protected function assertParityErrorContains( string $sql, string $needle ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$sqlite_message = $this->query_sqlite_error_message( $sql );
		$duckdb_message = $this->query_duckdb_error_message( $sql );

		$this->assertIsString( $sqlite_message, 'SQLite query should have failed for SQL: ' . $sql );
		$this->assertIsString( $duckdb_message, 'DuckDB query should have failed for SQL: ' . $sql );
		$this->assertStringContainsString( $needle, $sqlite_message, 'SQLite error mismatch for SQL: ' . $sql );
		$this->assertStringContainsString( $needle, $duckdb_message, 'DuckDB error mismatch for SQL: ' . $sql );
	}

	/**
	 * Execute setup SQL on both engines.
	 *
	 * @param string[] $queries Setup queries.
	 */
	protected function runParitySetup( array $queries ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		foreach ( $queries as $query ) {
			$this->sqlite_driver->query( $query, PDO::FETCH_ASSOC );
			$this->duckdb_driver->query( $query );
		}
	}

	/**
	 * Run a SELECT-like query on SQLite.
	 *
	 * @param string $sql SQL query.
	 * @return array
	 */
	private function query_sqlite_rows( string $sql ): array {
		$result = $this->sqlite_driver->query( $sql, PDO::FETCH_ASSOC );
		$this->assertIsArray( $result );
		return $result;
	}

	/**
	 * Run a SELECT-like query on DuckDB.
	 *
	 * @param string $sql SQL query.
	 * @return array
	 */
	private function query_duckdb_rows( string $sql ): array {
		return $this->duckdb_driver->query( $sql )->fetchAll( PDO::FETCH_ASSOC );
	}

	/**
	 * Run a write query on SQLite.
	 *
	 * @param string $sql SQL query.
	 * @return int
	 */
	private function query_sqlite_row_count( string $sql ): int {
		return (int) $this->sqlite_driver->query( $sql, PDO::FETCH_ASSOC );
	}

	/**
	 * Run a write query on DuckDB.
	 *
	 * @param string $sql SQL query.
	 * @return int
	 */
	private function query_duckdb_row_count( string $sql ): int {
		return $this->duckdb_driver->query( $sql )->rowCount();
	}

	/**
	 * Run a query on SQLite and return the error message.
	 *
	 * @param string $sql SQL query.
	 * @return string|null Error message, or null when the query succeeds.
	 */
	private function query_sqlite_error_message( string $sql ): ?string {
		try {
			$this->sqlite_driver->query( $sql, PDO::FETCH_ASSOC );
		} catch ( Throwable $e ) {
			return $e->getMessage();
		}

		return null;
	}

	/**
	 * Run a query on DuckDB and return the error message.
	 *
	 * @param string $sql SQL query.
	 * @return string|null Error message, or null when the query succeeds.
	 */
	private function query_duckdb_error_message( string $sql ): ?string {
		try {
			$this->duckdb_driver->query( $sql );
		} catch ( Throwable $e ) {
			return $e->getMessage();
		}

		return null;
	}

	/**
	 * Normalize rows across SQLite and DuckDB scalar fetch differences.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	private function normalize_rows( array $rows ): array {
		$normalized_rows = array();
		foreach ( $rows as $row ) {
			$normalized = array();
			foreach ( $row as $key => $value ) {
				$normalized[ (string) $key ] = null === $value ? null : (string) $value;
			}
			ksort( $normalized );
			$normalized_rows[] = $normalized;
		}
		return $normalized_rows;
	}

	/**
	 * Keep only selected columns from rows.
	 *
	 * @param array    $rows    Rows.
	 * @param string[] $columns Columns to keep.
	 * @return array
	 */
	private function select_columns( array $rows, array $columns ): array {
		$selected_rows = array();
		foreach ( $rows as $row ) {
			$selected = array();
			foreach ( $columns as $column ) {
				$selected[ $column ] = $row[ $column ] ?? null;
			}
			$selected_rows[] = $selected;
		}
		return $selected_rows;
	}
}
