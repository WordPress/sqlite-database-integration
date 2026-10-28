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

	public function testWriteQueryAfterSavepointOpensWriteTransaction(): void {
		$driver = $this->create_in_memory_driver();
		$driver->query( 'CREATE TABLE t (id INT, name VARCHAR(255))' );
		$driver->query( "INSERT INTO t VALUES (1, 'Alice')" );

		// A savepoint outside of a transaction must not leave a deferred SQLite
		// transaction open, which would make the following write skip the lock.
		$driver->query( 'SAVEPOINT sp1' );
		$driver->query( "UPDATE t SET name = 'Carol' WHERE id = 1" );

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
					'path'         => $this->db_path,
					'busy_timeout' => 0,
				)
			);
			$driver_b = $this->create_driver( $conn_b );

			// SET writes nothing, so it must not contend for the write lock.
			$result = $driver_b->query( "SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'" );

			$this->assertSame( 0, $result->rowCount() );
		} finally {
			$conn_a->get_pdo()->exec( 'ROLLBACK' );
		}
	}

	public function testWriteQueryFailsImmediatelyWithZeroBusyTimeoutWhileAnotherConnectionHoldsWriteLock(): void {
		$elapsed = $this->measure_write_failure_under_write_lock( 0 );

		// Far below the 10 second default.
		$this->assertLessThan( 1, $elapsed );
	}

	public function testWriteQueryWaitsForBusyTimeoutWhileAnotherConnectionHoldsWriteLock(): void {
		$elapsed = $this->measure_write_failure_under_write_lock( 200 );

		$this->assertGreaterThanOrEqual( 0.2, $elapsed );
		$this->assertLessThan( 5, $elapsed );
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
					'path'         => $this->db_path,
					'busy_timeout' => 0,
				)
			);
			$driver_b = $this->create_driver( $conn_b );

			$result = $driver_b->query( $query );

			$this->assertNotEmpty( $result->fetchAll() );
		} finally {
			$conn_a->get_pdo()->exec( 'ROLLBACK' );
		}
	}

	/**
	 * Run a write query while another connection holds the write lock.
	 *
	 * @param  int   $busy_timeout Busy timeout of the writing connection in milliseconds.
	 * @return float               Time in seconds until the write query failed.
	 */
	private function measure_write_failure_under_write_lock( int $busy_timeout ): float {
		// Connection A: set up the database and hold a write transaction.
		$conn_a   = new WP_SQLite_Connection( array( 'path' => $this->db_path ) );
		$driver_a = $this->create_driver( $conn_a );
		$driver_a->query( 'CREATE TABLE t (id INT, name VARCHAR(255))' );
		$driver_a->query( "INSERT INTO t VALUES (1, 'Alice')" );

		// Simulate another PHP process holding a write transaction.
		$conn_a->get_pdo()->exec( 'BEGIN IMMEDIATE' );

		try {
			$driver_b = new WP_MySQL_On_SQLite(
				sprintf( 'mysql-on-sqlite:path=%s;dbname=wp', $this->db_path ),
				null,
				null,
				array( 'sqlite_busy_timeout' => $busy_timeout )
			);

			$start = microtime( true );
			try {
				$driver_b->query( "INSERT INTO t VALUES (2, 'Bob')" );
			} catch ( PDOException $e ) {
				$this->assertStringContainsString( 'database is locked', $e->getMessage() );
				return microtime( true ) - $start;
			}
			$this->fail( 'The write query did not fail while another connection held the write lock.' );
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
				'sqlite_pdo'          => $connection->get_pdo(),
				'sqlite_busy_timeout' => (int) $connection->query( 'PRAGMA busy_timeout' )->fetchColumn(),
				'sqlite_journal_mode' => $connection->query( 'PRAGMA journal_mode' )->fetchColumn(),
			)
		);
	}
}
