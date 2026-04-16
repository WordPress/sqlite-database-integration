<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for concurrent access to the same SQLite database file.
 */
class WP_SQLite_Driver_Concurrency_Tests extends TestCase {
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

	/**
	 * A SELECT should not be wrapped in a transaction — no BEGIN at all.
	 */
	public function testSelectQueryIsNotWrappedInTransaction(): void {
		$pdo_class = PHP_VERSION_ID >= 80400 ? PDO\SQLite::class : PDO::class;
		$pdo       = new $pdo_class( 'sqlite::memory:' );

		$connection = new WP_SQLite_Connection( array( 'pdo' => $pdo ) );
		$driver     = new WP_SQLite_Driver( $connection, 'wp' );
		$driver->query( 'CREATE TABLE t (id INT, name VARCHAR(255))' );

		// Capture SQLite queries. The logger must be set on the driver's
		// internal connection, not the original one passed to the constructor.
		$logged_queries = array();
		$driver->get_connection()->set_query_logger(
			function ( string $sql, array $params ) use ( &$logged_queries ): void {
				$logged_queries[] = $sql;
			}
		);

		$driver->query( 'SELECT * FROM t' );

		$this->assertStringStartsNotWith( 'BEGIN', $logged_queries[0] );
	}

	/**
	 * A SHOW statement should use a deferred BEGIN (SHARED lock), not
	 * BEGIN IMMEDIATE (RESERVED/write lock).
	 */
	public function testShowQueryOpensReadOnlyTransaction(): void {
		$pdo_class = PHP_VERSION_ID >= 80400 ? PDO\SQLite::class : PDO::class;
		$pdo       = new $pdo_class( 'sqlite::memory:' );

		$connection = new WP_SQLite_Connection( array( 'pdo' => $pdo ) );
		$driver     = new WP_SQLite_Driver( $connection, 'wp' );
		$driver->query( 'CREATE TABLE t (id INT, name VARCHAR(255))' );

		$logged_queries = array();
		$driver->get_connection()->set_query_logger(
			function ( string $sql, array $params ) use ( &$logged_queries ): void {
				$logged_queries[] = $sql;
			}
		);

		$driver->query( 'SHOW TABLES' );

		$this->assertSame( 'BEGIN', $logged_queries[0] );
	}

	/**
	 * A DESCRIBE statement should use a deferred BEGIN (SHARED lock), not
	 * BEGIN IMMEDIATE (RESERVED/write lock).
	 */
	public function testDescribeQueryOpensReadOnlyTransaction(): void {
		$pdo_class = PHP_VERSION_ID >= 80400 ? PDO\SQLite::class : PDO::class;
		$pdo       = new $pdo_class( 'sqlite::memory:' );

		$connection = new WP_SQLite_Connection( array( 'pdo' => $pdo ) );
		$driver     = new WP_SQLite_Driver( $connection, 'wp' );
		$driver->query( 'CREATE TABLE t (id INT, name VARCHAR(255))' );

		$logged_queries = array();
		$driver->get_connection()->set_query_logger(
			function ( string $sql, array $params ) use ( &$logged_queries ): void {
				$logged_queries[] = $sql;
			}
		);

		$driver->query( 'DESCRIBE t' );

		$this->assertSame( 'BEGIN', $logged_queries[0] );
	}

	/**
	 * A SELECT on one connection should succeed even when another connection
	 * holds an open write transaction (RESERVED lock).
	 */
	public function testSelectQuerySucceedsWhileAnotherConnectionHoldsWriteLock(): void {
		// Connection A: set up the database.
		$conn_a   = new WP_SQLite_Connection( array( 'path' => $this->db_path ) );
		$driver_a = new WP_SQLite_Driver( $conn_a, 'wp' );
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
			$driver_b = new WP_SQLite_Driver( $conn_b, 'wp' );
			$conn_b->get_pdo()->setAttribute( PDO::ATTR_TIMEOUT, 0 );

			$result = $driver_b->query( 'SELECT * FROM t' );

			$this->assertCount( 1, $result );
			$this->assertSame( '1', $result[0]->id );
			$this->assertSame( 'Alice', $result[0]->name );
		} finally {
			$conn_a->get_pdo()->exec( 'ROLLBACK' );
		}
	}
}
