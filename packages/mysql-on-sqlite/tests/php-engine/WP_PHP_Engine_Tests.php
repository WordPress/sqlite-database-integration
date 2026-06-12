<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for behaviors specific to the pure-PHP database engine, most
 * importantly file-backed persistence and multi-connection safety.
 */
class WP_PHP_Engine_Tests extends TestCase {
	/** @var string */
	private $path;

	public function setUp(): void {
		$this->path = tempnam( sys_get_temp_dir(), 'wp-php-engine-test-' );
		unlink( $this->path );
	}

	public function tearDown(): void {
		if ( file_exists( $this->path ) ) {
			unlink( $this->path );
		}
	}

	private function create_file_pdo(): WP_PHP_Engine_PDO {
		return new WP_PHP_Engine_PDO( 'php-engine:' . $this->path );
	}

	public function testDdlOnlyPersistsAcrossReopen(): void {
		$pdo = $this->create_file_pdo();
		$pdo->exec( 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)' );
		$pdo->exec( 'CREATE INDEX t__name ON t (name)' );
		$pdo->exec( 'CREATE TRIGGER t_trigger AFTER UPDATE ON t FOR EACH ROW BEGIN UPDATE t SET name = NEW.name WHERE rowid = NEW.rowid; END' );
		$pdo->exec( 'CREATE VIEW v AS SELECT name FROM t' );
		unset( $pdo );

		// A schema-only session must produce a durable database file.
		$this->assertFileExists( $this->path );

		$pdo  = $this->create_file_pdo();
		$rows = $pdo->query( "SELECT type, name FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name" )
			->fetchAll( PDO::FETCH_NUM );
		$this->assertSame(
			array(
				array( 'index', 't__name' ),
				array( 'table', 't' ),
				array( 'trigger', 't_trigger' ),
				array( 'view', 'v' ),
			),
			$rows
		);
	}

	public function testDataAndAutoincrementPersistAcrossReopen(): void {
		$pdo = $this->create_file_pdo();
		$pdo->exec( 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)' );
		$pdo->exec( "INSERT INTO t (name) VALUES ('first')" );
		$pdo->exec( 'DELETE FROM t' );
		unset( $pdo );

		$pdo = $this->create_file_pdo();
		$pdo->exec( "INSERT INTO t (name) VALUES ('second')" );

		// The AUTOINCREMENT counter continues after the deleted row.
		$this->assertSame( '2', $pdo->lastInsertId() );
		$this->assertSame(
			array( array( 2, 'second' ) ),
			$pdo->query( 'SELECT id, name FROM t' )->fetchAll( PDO::FETCH_NUM )
		);
	}

	public function testRolledBackTransactionIsNotPersisted(): void {
		$pdo = $this->create_file_pdo();
		$pdo->exec( 'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)' );
		$pdo->exec( "INSERT INTO t VALUES (1, 'committed')" );

		$pdo->beginTransaction();
		$pdo->exec( "INSERT INTO t VALUES (2, 'rolled-back')" );
		$pdo->rollBack();
		unset( $pdo );

		$pdo = $this->create_file_pdo();
		$this->assertSame(
			array( array( 1, 'committed' ) ),
			$pdo->query( 'SELECT * FROM t' )->fetchAll( PDO::FETCH_NUM )
		);
	}

	public function testConcurrentConnectionsDoNotLoseUpdates(): void {
		// Two connections opened from the same (empty) starting snapshot.
		$first  = $this->create_file_pdo();
		$second = $this->create_file_pdo();

		// A write on the first connection...
		$first->exec( 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)' );
		$first->exec( "INSERT INTO t (name) VALUES ('from-first')" );

		// ...must be visible to a write on the second connection, which
		// started from an empty snapshot (no lost updates).
		$second->exec( "INSERT INTO t (name) VALUES ('from-second')" );

		// Both connections and a fresh one see both rows.
		$expected = array(
			array( 1, 'from-first' ),
			array( 2, 'from-second' ),
		);
		$this->assertSame( $expected, $second->query( 'SELECT * FROM t ORDER BY id' )->fetchAll( PDO::FETCH_NUM ) );
		$this->assertSame( $expected, $first->query( 'SELECT * FROM t ORDER BY id' )->fetchAll( PDO::FETCH_NUM ) );

		$third = $this->create_file_pdo();
		$this->assertSame( $expected, $third->query( 'SELECT * FROM t ORDER BY id' )->fetchAll( PDO::FETCH_NUM ) );
	}

	public function testReadsSeeChangesFromOtherConnections(): void {
		$writer = $this->create_file_pdo();
		$reader = $this->create_file_pdo();

		$writer->exec( 'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)' );
		$this->assertSame(
			array(),
			$reader->query( 'SELECT * FROM t' )->fetchAll( PDO::FETCH_NUM )
		);

		$writer->exec( "INSERT INTO t VALUES (1, 'a')" );
		$this->assertSame(
			array( array( 1, 'a' ) ),
			$reader->query( 'SELECT * FROM t' )->fetchAll( PDO::FETCH_NUM )
		);
	}

	public function testRefusesToOpenForeignFiles(): void {
		// A real SQLite database file (or any unknown format) must never
		// be overwritten by the engine.
		$foreign_content = "SQLite format 3\0not really, but the header matters";
		file_put_contents( $this->path, $foreign_content );

		$exception = null;
		try {
			$this->create_file_pdo();
		} catch ( PDOException $e ) {
			$exception = $e;
		}
		$this->assertNotNull( $exception );
		$this->assertStringContainsString( 'not a WP_PHP_Engine database', $exception->getMessage() );
		$this->assertSame( $foreign_content, file_get_contents( $this->path ) );
	}

	public function testInsertOnConflictDoNothingAffectsNoRows(): void {
		$pdo = new WP_PHP_Engine_PDO( 'php-engine::memory:' );
		$pdo->exec( 'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT UNIQUE)' );

		$this->assertSame( 1, $pdo->exec( "INSERT INTO t (name) VALUES ('a') ON CONFLICT(name) DO NOTHING" ) );
		$this->assertSame( 0, $pdo->exec( "INSERT INTO t (name) VALUES ('a') ON CONFLICT(name) DO NOTHING" ) );

		// An upsert that updates the conflicting row affects one row...
		$this->assertSame( 1, $pdo->exec( "INSERT INTO t (name) VALUES ('a') ON CONFLICT(name) DO UPDATE SET name = 'b'" ) );
		// ...but not when its WHERE clause filters the update out.
		$this->assertSame( 0, $pdo->exec( "INSERT INTO t (name) VALUES ('b') ON CONFLICT(name) DO UPDATE SET name = 'c' WHERE 1 = 0" ) );
	}

	public function testBindParamBindsByReference(): void {
		$pdo = new WP_PHP_Engine_PDO( 'php-engine::memory:' );
		$pdo->exec( 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)' );

		$stmt = $pdo->prepare( 'INSERT INTO t (name) VALUES (?)' );
		$name = 'before';
		$stmt->bindParam( 1, $name );

		// Like in PDO, the variable is read at execute() time.
		$name = 'after';
		$stmt->execute();
		$name = 'again';
		$stmt->execute();

		$this->assertSame(
			array( array( 'after' ), array( 'again' ) ),
			$pdo->query( 'SELECT name FROM t ORDER BY id' )->fetchAll( PDO::FETCH_NUM )
		);
	}

	public function testInTableSyntax(): void {
		$pdo = new WP_PHP_Engine_PDO( 'php-engine::memory:' );
		$pdo->exec( 'CREATE TABLE t (id INTEGER PRIMARY KEY)' );
		$pdo->exec( 'INSERT INTO t VALUES (1), (2)' );

		$this->assertSame(
			array( array( 1, 0 ) ),
			$pdo->query( 'SELECT 1 IN t, 3 IN t' )->fetchAll( PDO::FETCH_NUM )
		);
	}

	public function testInTableFunctionSyntax(): void {
		$pdo = new WP_PHP_Engine_PDO( 'php-engine::memory:' );
		$pdo->exec( 'CREATE TABLE t (id INTEGER PRIMARY KEY)' );

		$this->assertSame(
			array( array( 1, 0 ) ),
			$pdo->query( "SELECT 0 IN pragma_table_info('t'), 10 IN pragma_table_info('t')" )->fetchAll( PDO::FETCH_NUM )
		);
	}

	public function testFullDriverStackOnFileBackedEngine(): void {
		// The complete MySQL driver stack works on a file-backed engine
		// across connections.
		$driver = new WP_SQLite_Driver(
			new WP_SQLite_Connection( array( 'pdo' => $this->create_file_pdo() ) ),
			'wp'
		);
		$driver->query( 'CREATE TABLE t (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50))' );
		$driver->query( "INSERT INTO t (name) VALUES ('persisted')" );

		$reopened = new WP_SQLite_Driver(
			new WP_SQLite_Connection( array( 'pdo' => $this->create_file_pdo() ) ),
			'wp'
		);
		$this->assertEquals(
			array(
				(object) array(
					'id'   => '1',
					'name' => 'persisted',
				),
			),
			$reopened->query( 'SELECT * FROM t' )
		);
	}
}
