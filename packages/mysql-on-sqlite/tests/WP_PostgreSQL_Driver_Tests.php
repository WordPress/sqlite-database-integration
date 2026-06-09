<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the PostgreSQL driver scaffold.
 */
class WP_PostgreSQL_Driver_Tests extends TestCase {
	/**
	 * Tests SELECT queries return fetched rows and normalized metadata.
	 */
	public function test_query_returns_rows_and_metadata(): void {
		$driver = $this->create_driver();

		$rows = $driver->query( "SELECT 1 AS id, 'ok' AS value" );

		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]->id );
		$this->assertSame( 'ok', $rows[0]->value );
		$this->assertSame( 'SELECT 1 AS id, \'ok\' AS value', $driver->get_last_mysql_query() );
		$this->assertSame(
			array(
				array(
					'sql'    => "SELECT 1 AS id, 'ok' AS value",
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$column_meta = $driver->get_last_column_meta();
		$this->assertCount( 2, $column_meta );
		$this->assertSame( 'id', $column_meta[0]['name'] );
		$this->assertSame( 'wptests', $column_meta[0]['mysqli:db'] );
		$this->assertArrayHasKey( 'mysqli:type', $column_meta[0] );
		$this->assertArrayHasKey( 'mysqli:charsetnr', $column_meta[0] );
	}

	/**
	 * Tests write queries return PDO row counts.
	 */
	public function test_write_query_returns_row_count(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)' );
		$result = $driver->query( "INSERT INTO t (value) VALUES ('first')" );

		$this->assertSame( 1, $result );
		$this->assertSame( 1, $driver->get_last_return_value() );
		$this->assertSame( array(), $driver->get_last_column_meta() );
		$this->assertSame( 0, $driver->get_last_column_count() );
	}

	/**
	 * Tests insert IDs are cast to integers when numeric.
	 */
	public function test_get_insert_id_casts_numeric_strings(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)' );
		$driver->query( "INSERT INTO t (value) VALUES ('first')" );

		$this->assertSame( 1, $driver->get_insert_id() );
	}

	/**
	 * Tests transaction methods delegate to PDO.
	 */
	public function test_transaction_methods_delegate_to_pdo(): void {
		$driver = $this->create_driver();

		$driver->beginTransaction();
		$driver->query( 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)' );
		$driver->rollback();

		$stmt = $driver->get_connection()->query( "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 't'" );
		$this->assertFalse( $stmt->fetchColumn() );
	}

	/**
	 * Creates a PostgreSQL driver backed by an injected in-memory PDO.
	 *
	 * @return WP_PostgreSQL_Driver
	 */
	private function create_driver(): WP_PostgreSQL_Driver {
		$connection = new WP_PostgreSQL_Connection( array( 'pdo' => new PDO( 'sqlite::memory:' ) ) );
		return new WP_PostgreSQL_Driver( $connection, 'wptests' );
	}
}
