<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the SQLite connection setup.
 */
class WP_SQLite_Connection_Tests extends TestCase {
	/**
	 * Path to the temporary SQLite database file used in file-based tests.
	 *
	 * @var string|null
	 */
	private $db_path;

	public function setUp(): void {
		$this->db_path = tempnam( sys_get_temp_dir(), 'wp_sqlite_' );
		unlink( $this->db_path );
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

	public function testInvalidJournalModeAndSynchronousAreIgnored(): void {
		$connection = new WP_SQLite_Connection(
			array(
				'path'         => $this->db_path,
				'journal_mode' => 'INVALID',
				'synchronous'  => 'INVALID',
			)
		);

		$this->assertSame( 'delete', $this->get_journal_mode( $connection ) );
		$this->assertSame( '2', $this->get_synchronous( $connection ) );
	}

	private function get_journal_mode( WP_SQLite_Connection $connection ): string {
		return strtolower( (string) $connection->query( 'PRAGMA journal_mode' )->fetchColumn() );
	}

	private function get_synchronous( WP_SQLite_Connection $connection ): string {
		return (string) $connection->query( 'PRAGMA synchronous' )->fetchColumn();
	}
}
