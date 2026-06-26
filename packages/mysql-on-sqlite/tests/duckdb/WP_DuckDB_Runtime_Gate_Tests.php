<?php

/**
 * @group duckdb-runtime
 */
class WP_DuckDB_Runtime_Gate_Tests extends PHPUnit\Framework\TestCase {
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
}
