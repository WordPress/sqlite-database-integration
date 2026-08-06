<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for concurrent access to the same SQLite database file.
 */
class WP_MySQL_On_SQLite_Concurrency_Tests extends TestCase {
	/**
	 * Path to the temporary SQLite database file used in file-based tests.
	 *
	 * @var string|null
	 */
	private $db_path;

	public function setUp(): void {
		$this->db_path = tempnam( sys_get_temp_dir(), 'wp_sqlite_' );
		unlink( $this->db_path ); // Remove so SQLite creates a fresh database.
	}

	public function tearDown(): void {
		foreach ( array(
			$this->db_path,
			$this->db_path . '-wal',
			$this->db_path . '-shm',
			$this->db_path . '-journal',
		) as $path ) {
			if ( is_string( $path ) && file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->db_path = null;
	}

	public function testSelectQueryIsNotWrappedInTransaction(): void {
		$driver = $this->create_in_memory_driver();
		$driver->query( 'CREATE TABLE t (id INT, name VARCHAR(255))' );

		$driver->query( 'SELECT * FROM t' );

		$this->assertStringStartsNotWith( 'BEGIN', $driver->get_last_sqlite_queries()[0]['sql'] );
	}

	public function testShowQueryOpensReadOnlyTransaction(): void {
		$driver = $this->create_in_memory_driver();
		$driver->query( 'CREATE TABLE t (id INT, name VARCHAR(255))' );

		$driver->query( 'SHOW TABLES' );

		$this->assertSame( 'BEGIN', $driver->get_last_sqlite_queries()[0]['sql'] );
	}

	public function testDescribeQueryOpensReadOnlyTransaction(): void {
		$driver = $this->create_in_memory_driver();
		$driver->query( 'CREATE TABLE t (id INT, name VARCHAR(255))' );

		$driver->query( 'DESCRIBE t' );

		$this->assertSame( 'BEGIN', $driver->get_last_sqlite_queries()[0]['sql'] );
	}

	/**
	 * @dataProvider provideWriteStatements
	 */
	public function testWriteQueryOpensWriteTransaction( string $query ): void {
		$driver = $this->create_in_memory_driver();
		$driver->query( 'CREATE TABLE t (id INT, name VARCHAR(255))' );
		$driver->query( "INSERT INTO t VALUES (1, 'Alice')" );

		$driver->query( $query );

		$this->assertSame( 'BEGIN IMMEDIATE', $driver->get_last_sqlite_queries()[0]['sql'] );
	}

	public function provideWriteStatements(): array {
		return array(
			'INSERT'         => array( "INSERT INTO t VALUES (2, 'Bob')" ),
			'UPDATE'         => array( "UPDATE t SET name = 'Carol' WHERE id = 1" ),
			'DELETE'         => array( 'DELETE FROM t WHERE id = 1' ),
			'REPLACE'        => array( "REPLACE INTO t VALUES (1, 'Dan')" ),
			'CREATE TABLE'   => array( 'CREATE TABLE u (id INT)' ),
			'ALTER TABLE'    => array( 'ALTER TABLE t ADD COLUMN x INT' ),
			'DROP TABLE'     => array( 'DROP TABLE t' ),
			'TRUNCATE TABLE' => array( 'TRUNCATE TABLE t' ),
		);
	}

	public function testSelectQuerySucceedsWhileAnotherConnectionHoldsWriteLock(): void {
		$this->assertReadOnlyQuerySucceedsUnderWriteLock( 'SELECT * FROM t' );
	}

	public function testShowQuerySucceedsWhileAnotherConnectionHoldsWriteLock(): void {
		$this->assertReadOnlyQuerySucceedsUnderWriteLock( 'SHOW TABLES' );
	}

	public function testDescribeQuerySucceedsWhileAnotherConnectionHoldsWriteLock(): void {
		$this->assertReadOnlyQuerySucceedsUnderWriteLock( 'DESCRIBE t' );
	}

	public function testSetQueryOpensReadOnlyTransaction(): void {
		$driver = $this->create_in_memory_driver();
		$driver->query( 'CREATE TABLE t (id INT, name VARCHAR(255))' );

		$driver->query( "SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'" );

		$this->assertSame( 'BEGIN', $driver->get_last_sqlite_queries()[0]['sql'] );
	}

	public function testSetQuerySucceedsWhileAnotherConnectionHoldsWriteLock(): void {
		// Connection A: set up the database and hold a write transaction.
		$conn_a   = new WP_SQLite_Connection( array( 'path' => $this->db_path ) );
		$driver_a = $this->create_driver( $conn_a );
		$driver_a->query( 'CREATE TABLE t (id INT, name VARCHAR(255))' );
		$driver_a->query( "INSERT INTO t VALUES (1, 'Alice')" );

		// Simulate another PHP process holding a write transaction.
		$conn_a->get_pdo()->exec( 'BEGIN IMMEDIATE' );

		try {
			// Connection B with zero timeout — any lock conflict fails immediately.
			$conn_b   = new WP_SQLite_Connection(
				array(
					'path'    => $this->db_path,
					'timeout' => 0,
				)
			);
			$driver_b = $this->create_driver( $conn_b );
			$conn_b->get_pdo()->setAttribute( PDO::ATTR_TIMEOUT, 0 );

			// SET writes nothing, so it must not contend for the write lock.
			$result = $driver_b->query( "SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'" );

			$this->assertSame( 0, $result->rowCount() );
		} finally {
			$conn_a->get_pdo()->exec( 'ROLLBACK' );
		}
	}

	private function assertReadOnlyQuerySucceedsUnderWriteLock( string $query ): void {
		// Connection A: set up the database.
		$conn_a   = new WP_SQLite_Connection( array( 'path' => $this->db_path ) );
		$driver_a = $this->create_driver( $conn_a );
		$driver_a->query( 'CREATE TABLE t (id INT, name VARCHAR(255))' );
		$driver_a->query( "INSERT INTO t VALUES (1, 'Alice')" );

		// Simulate another PHP process holding a write transaction.
		$conn_a->get_pdo()->exec( 'BEGIN IMMEDIATE' );

		try {
			// Connection B with zero timeout — any lock conflict fails immediately.
			$conn_b   = new WP_SQLite_Connection(
				array(
					'path'    => $this->db_path,
					'timeout' => 0,
				)
			);
			$driver_b = $this->create_driver( $conn_b );
			$conn_b->get_pdo()->setAttribute( PDO::ATTR_TIMEOUT, 0 );

			$result = $driver_b->query( $query );

			$this->assertNotEmpty( $result->fetchAll() );
		} finally {
			$conn_a->get_pdo()->exec( 'ROLLBACK' );
		}
	}

	private function create_in_memory_driver(): WP_MySQL_On_SQLite {
		$pdo_class  = PHP_VERSION_ID >= 80400 ? Pdo\Sqlite::class : PDO::class;
		$pdo        = new $pdo_class( 'sqlite::memory:' );
		$connection = new WP_SQLite_Connection( array( 'pdo' => $pdo ) );
		return $this->create_driver( $connection );
	}

	private function create_driver( WP_SQLite_Connection $connection ): WP_MySQL_On_SQLite {
		return new WP_MySQL_On_SQLite(
			'mysql-on-sqlite:dbname=wp',
			null,
			null,
			array(
				'pdo'          => $connection->get_pdo(),
				'journal_mode' => $connection->query( 'PRAGMA journal_mode' )->fetchColumn(),
			)
		);
	}
}
