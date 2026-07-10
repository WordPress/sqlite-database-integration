<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the SQLite connection setup.
 */
class WP_SQLite_Connection_Tests extends TestCase {
	/**
	 * Path to the temporary directory holding the SQLite database file.
	 *
	 * @var string|null
	 */
	private $db_dir;

	/**
	 * Path to the temporary SQLite database file used in file-based tests.
	 *
	 * @var string|null
	 */
	private $db_path;

	public function setUp(): void {
		$this->db_dir = tempnam( sys_get_temp_dir(), 'wp_sqlite_' );
		unlink( $this->db_dir );
		mkdir( $this->db_dir );
		$this->db_path = $this->db_dir . '/database.sqlite';
	}

	public function tearDown(): void {
		chmod( $this->db_dir, 0755 ); // Restore permissions changed by read-only tests.
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
		rmdir( $this->db_dir );
		$this->db_dir  = null;
		$this->db_path = null;
	}

	public function testDefaultJournalModeUsesWal(): void {
		$connection = new WP_SQLite_Connection( array( 'path' => $this->db_path ) );

		$this->assertSame( 'wal', $this->get_journal_mode( $connection ) );
		$this->assertSame( '1', $this->get_synchronous( $connection ) );
	}

	public function testJournalModeCanBeOverridden(): void {
		$connection = new WP_SQLite_Connection(
			array(
				'path'         => $this->db_path,
				'journal_mode' => 'DELETE',
			)
		);

		$this->assertSame( 'delete', $this->get_journal_mode( $connection ) );
	}

	public function testSynchronousCanBeOverridden(): void {
		$connection = new WP_SQLite_Connection(
			array(
				'path'        => $this->db_path,
				'synchronous' => 'FULL',
			)
		);

		$this->assertSame( '2', $this->get_synchronous( $connection ) );
	}

	public function testRollbackJournalModeKeepsDefaultSynchronous(): void {
		$connection = new WP_SQLite_Connection(
			array(
				'path'         => $this->db_path,
				'journal_mode' => 'DELETE',
			)
		);

		$this->assertSame( '2', $this->get_synchronous( $connection ) );
	}

	public function testInMemoryDatabaseKeepsDefaultSynchronous(): void {
		$connection = new WP_SQLite_Connection( array( 'path' => ':memory:' ) );

		$this->assertSame( 'memory', $this->get_journal_mode( $connection ) );
		$this->assertSame( '2', $this->get_synchronous( $connection ) );
	}

	public function testJournalModeAndSynchronousAreCaseInsensitive(): void {
		$connection = new WP_SQLite_Connection(
			array(
				'path'         => $this->db_path,
				'journal_mode' => 'delete',
				'synchronous'  => 'extra',
			)
		);

		$this->assertSame( 'delete', $this->get_journal_mode( $connection ) );
		$this->assertSame( '3', $this->get_synchronous( $connection ) );
	}

	public function testInvalidJournalModeThrows(): void {
		$this->expectException( InvalidArgumentException::class );
		new WP_SQLite_Connection(
			array(
				'path'         => $this->db_path,
				'journal_mode' => 'INVALID',
			)
		);
	}

	public function testInvalidSynchronousThrows(): void {
		$this->expectException( InvalidArgumentException::class );
		new WP_SQLite_Connection(
			array(
				'path'        => $this->db_path,
				'synchronous' => 'INVALID',
			)
		);
	}

	public function testOutOfRangeIntegerSynchronousThrows(): void {
		$this->expectException( InvalidArgumentException::class );
		new WP_SQLite_Connection(
			array(
				'path'        => $this->db_path,
				'synchronous' => 5,
			)
		);
	}

	public function testSynchronousAcceptsIntegerValues(): void {
		$connection = new WP_SQLite_Connection(
			array(
				'path'        => $this->db_path,
				'synchronous' => 3,
			)
		);

		$this->assertSame( '3', $this->get_synchronous( $connection ) );
	}

	public function testSynchronousAcceptsIntegerZero(): void {
		$connection = new WP_SQLite_Connection(
			array(
				'path'         => $this->db_path,
				'journal_mode' => 'DELETE',
				'synchronous'  => 0,
			)
		);

		$this->assertSame( '0', $this->get_synchronous( $connection ) );
	}

	public function testDefaultJournalModeFallsBackWhenWalIsUnavailable(): void {
		$this->make_database_directory_read_only();

		$connection = new WP_SQLite_Connection( array( 'path' => $this->db_path ) );

		$this->assertSame( 'delete', $this->get_journal_mode( $connection ) );
		$this->assertSame( '2', $this->get_synchronous( $connection ) );
	}

	public function testExplicitJournalModeSurfacesFailureWhenWalIsUnavailable(): void {
		$this->make_database_directory_read_only();

		$this->expectException( PDOException::class );
		new WP_SQLite_Connection(
			array(
				'path'         => $this->db_path,
				'journal_mode' => 'WAL',
			)
		);
	}

	public function testDriverKeepsConfiguredJournalMode(): void {
		$driver = new WP_MySQL_On_SQLite(
			sprintf( 'mysql-on-sqlite:path=%s;dbname=wp', $this->db_path ),
			null,
			null,
			array( 'journal_mode' => 'DELETE' )
		);

		$this->assertSame( 'delete', $this->get_journal_mode( $driver->get_connection() ) );
	}

	/**
	 * Create the database file first, and then make its directory read-only,
	 * so that the WAL sidecar files ("-wal", "-shm") cannot be created.
	 */
	private function make_database_directory_read_only(): void {
		$connection = new WP_SQLite_Connection(
			array(
				'path'         => $this->db_path,
				'journal_mode' => 'DELETE',
			)
		);
		$connection->query( 'CREATE TABLE t ( id INTEGER )' );
		$connection = null;

		chmod( $this->db_dir, 0555 );
		if ( is_writable( $this->db_dir ) ) {
			$this->markTestSkipped( 'The test requires a non-writable database directory.' );
		}
	}

	private function get_journal_mode( WP_SQLite_Connection $connection ): string {
		return strtolower( (string) $connection->query( 'PRAGMA journal_mode' )->fetchColumn() );
	}

	private function get_synchronous( WP_SQLite_Connection $connection ): string {
		return (string) $connection->query( 'PRAGMA synchronous' )->fetchColumn();
	}
}
