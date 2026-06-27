<?php

/**
 * @group duckdb-runtime
 */
class WP_DuckDB_Runtime_Gate_Tests extends PHPUnit\Framework\TestCase {
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		foreach ( array( 'WP_DUCKDB_AUTOLOAD', 'DUCKDB_PHP_AUTOLOAD' ) as $environment_key ) {
			$autoload = getenv( $environment_key );
			if ( is_string( $autoload ) && '' !== $autoload && file_exists( $autoload ) ) {
				require_once $autoload;
			}
		}
	}

	public function test_runtime_gate_does_not_require_duckdb_for_default_sqlite_path(): void {
		$this->assertTrue( class_exists( WP_SQLite_Connection::class ) );
		$this->assertTrue( class_exists( WP_DuckDB_Runtime::class ) );
	}

	public function test_runtime_gate_reports_unavailable_client(): void {
		if ( PHP_VERSION_ID < 80300 || ! extension_loaded( 'ffi' ) ) {
			$this->assertIsString( WP_DuckDB_Runtime::get_unavailable_reason() );
			return;
		}

		if ( class_exists( WP_DuckDB_Runtime::CLIENT_CLASS ) ) {
			$this->markTestSkipped( 'DuckDB PHP client is loaded in this environment.' );
		}

		$this->assertSame(
			'DuckDB support requires the satur.io/duckdb PHP client to be installed and autoloaded.',
			WP_DuckDB_Runtime::get_unavailable_reason()
		);
	}

	public function test_connection_constructor_throws_clear_exception_when_runtime_missing(): void {
		if ( null === WP_DuckDB_Runtime::get_unavailable_reason() ) {
			$this->markTestSkipped( 'DuckDB runtime is available in this environment.' );
		}

		$this->expectException( WP_DuckDB_Driver_Exception::class );
		new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
	}

	public function test_native_duckdb_rejects_savepoints_without_corrupting_transaction(): void {
		$this->requireDuckDBRuntime();

		$client_class = WP_DuckDB_Runtime::CLIENT_CLASS;
		$cases        = array(
			'SAVEPOINT sp1',
			'ROLLBACK TO sp1',
			'ROLLBACK TO SAVEPOINT sp1',
			'ROLLBACK WORK TO sp1',
			'ROLLBACK WORK TO SAVEPOINT sp1',
			'RELEASE SAVEPOINT sp1',
		);

		foreach ( $cases as $sql ) {
			$duckdb = $client_class::create();
			$duckdb->query( 'CREATE TABLE native_savepoint_state (id INTEGER)' );
			$duckdb->query( 'BEGIN TRANSACTION' );
			$duckdb->query( 'INSERT INTO native_savepoint_state VALUES (1)' );

			try {
				$duckdb->query( $sql );
				$this->fail( 'Expected native DuckDB to reject SQL: ' . $sql );
			} catch ( Throwable $e ) {
				$message = $e->getMessage();
				$this->assertTrue(
					false !== stripos( $message, 'syntax error' ) || false !== stripos( $message, 'Parser Error' ),
					'Unexpected native DuckDB savepoint error for ' . $sql . ': ' . $message
				);
			}

			$this->assertSame(
				array( array( 'id' => 1 ) ),
				$this->fetchNativeIdRows( $duckdb->query( 'SELECT id FROM native_savepoint_state ORDER BY id' ) )
			);
			$duckdb->query( 'INSERT INTO native_savepoint_state VALUES (2)' );
			$duckdb->query( 'COMMIT' );
			$this->assertSame(
				array(
					array( 'id' => 1 ),
					array( 'id' => 2 ),
				),
				$this->fetchNativeIdRows( $duckdb->query( 'SELECT id FROM native_savepoint_state ORDER BY id' ) )
			);
		}
	}

	private function requireDuckDBRuntime(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		if ( '1' !== getenv( 'WP_DUCKDB_TESTS' ) ) {
			$this->markTestSkipped( 'DuckDB tests require WP_DUCKDB_TESTS=1.' );
		}

		$reason = WP_DuckDB_Runtime::get_unavailable_reason( true );
		if ( null !== $reason ) {
			$this->markTestSkipped( $reason );
		}
	}

	private function fetchNativeIdRows( $result ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$rows = array();
		foreach ( $result->rows( true ) as $row ) {
			$rows[] = array( 'id' => (int) $row['id'] );
		}
		return $rows;
	}
}
