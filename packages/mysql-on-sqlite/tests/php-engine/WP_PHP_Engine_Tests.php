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

	public function testConcurrentWriteTimesOutWhileTransactionHoldsLock(): void {
		$writer = $this->create_file_pdo();
		$writer->exec( 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)' );

		$child = $this->start_child_writer( 0, 'from-child' );
		$this->wait_for_file( $child['ready'] );

		try {
			$writer->beginTransaction();
			$writer->exec( "INSERT INTO t (name) VALUES ('from-writer')" );

			touch( $child['go'] );
			$result = $this->finish_child_writer( $child, 1 );

			$this->assertSame( 7, $result['exit_code'], $result['stderr'] );
			$this->assertStringContainsString( 'database is locked', $result['stderr'] );
		} finally {
			if ( $writer->inTransaction() ) {
				$writer->rollBack();
			}
			$this->cleanup_child_writer( $child );
		}
	}

	public function testConcurrentWriteWaitsForTransactionWithinTimeout(): void {
		$writer = $this->create_file_pdo();
		$writer->exec( 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)' );

		$child = $this->start_child_writer( 2, 'from-child' );
		$this->wait_for_file( $child['ready'] );

		try {
			$writer->beginTransaction();
			$writer->exec( "INSERT INTO t (name) VALUES ('from-writer')" );
			touch( $child['go'] );
			usleep( 200000 );
			$writer->commit();

			$result = $this->finish_child_writer( $child, 5 );
			$this->assertSame( 0, $result['exit_code'], $result['stderr'] );
			$this->assertSame(
				array(
					array( 'from-writer' ),
					array( 'from-child' ),
				),
				$writer->query( 'SELECT name FROM t ORDER BY id' )->fetchAll( PDO::FETCH_NUM )
			);
		} finally {
			if ( $writer->inTransaction() ) {
				$writer->rollBack();
			}
			$this->cleanup_child_writer( $child );
		}
	}

	public function testPragmaBusyTimeoutCanBeReadBack(): void {
		$pdo = $this->create_file_pdo();

		$this->assertSame(
			array( array( 250 ) ),
			$pdo->query( 'PRAGMA busy_timeout = 250' )->fetchAll( PDO::FETCH_NUM )
		);
		$this->assertSame(
			array( array( 250 ) ),
			$pdo->query( 'PRAGMA busy_timeout' )->fetchAll( PDO::FETCH_NUM )
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

	private function start_child_writer( $timeout, $name ): array {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'proc_open() is required for lock contention tests.' );
		}
		if ( ! defined( 'PHP_BINARY' ) || ! is_executable( PHP_BINARY ) ) {
			$this->markTestSkipped( 'An executable PHP_BINARY is required for lock contention tests.' );
		}

		$script = tempnam( sys_get_temp_dir(), 'wp-php-engine-child-' );
		$ready  = tempnam( sys_get_temp_dir(), 'wp-php-engine-ready-' );
		$go     = tempnam( sys_get_temp_dir(), 'wp-php-engine-go-' );
		unlink( $ready );
		unlink( $go );

		$load = dirname( __DIR__, 2 ) . '/src/php-engine/load.php';
		file_put_contents(
			$script,
			"<?php\n" .
			'require_once ' . var_export( $load, true ) . ";\n" .
			'$pdo = new WP_PHP_Engine_PDO( \'php-engine:\' . $argv[1], null, null, array( PDO::ATTR_TIMEOUT => (float) $argv[2] ) );' . "\n" .
			'file_put_contents( $argv[4], \'ready\' );' . "\n" .
			'$deadline = microtime( true ) + 5;' . "\n" .
			'while ( ! file_exists( $argv[5] ) ) {' . "\n" .
			'	if ( microtime( true ) >= $deadline ) {' . "\n" .
			'		fwrite( STDERR, \'timed out waiting for write signal\' );' . "\n" .
			'		exit( 9 );' . "\n" .
			'	}' . "\n" .
			'	usleep( 10000 );' . "\n" .
			'}' . "\n" .
			'try {' . "\n" .
			'	$stmt = $pdo->prepare( \'INSERT INTO t (name) VALUES (?)\' );' . "\n" .
			'	$stmt->execute( array( $argv[3] ) );' . "\n" .
			'} catch ( PDOException $e ) {' . "\n" .
			'	fwrite( STDERR, $e->getMessage() );' . "\n" .
			'	exit( 7 );' . "\n" .
			'}' . "\n"
		);

		$command = escapeshellarg( PHP_BINARY ) . ' ' .
			escapeshellarg( $script ) . ' ' .
			escapeshellarg( $this->path ) . ' ' .
			escapeshellarg( (string) $timeout ) . ' ' .
			escapeshellarg( $name ) . ' ' .
			escapeshellarg( $ready ) . ' ' .
			escapeshellarg( $go );
		$pipes   = array();
		$process = proc_open(
			$command,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		if ( ! is_resource( $process ) ) {
			unlink( $script );
			$this->fail( 'Unable to start child PHP process.' );
		}

		fclose( $pipes[0] );

		return array(
			'process'  => $process,
			'pipes'    => $pipes,
			'script'   => $script,
			'ready'    => $ready,
			'go'       => $go,
			'finished' => false,
		);
	}

	private function wait_for_file( $path ): void {
		$deadline = microtime( true ) + 5;
		while ( ! file_exists( $path ) ) {
			if ( microtime( true ) >= $deadline ) {
				$this->fail( 'Timed out waiting for child PHP process.' );
			}
			usleep( 10000 );
		}
	}

	private function finish_child_writer( array &$child, $timeout ): array {
		$deadline  = microtime( true ) + $timeout;
		$exit_code = null;
		while ( true ) {
			$status = proc_get_status( $child['process'] );
			if ( ! $status['running'] ) {
				$exit_code = $status['exitcode'];
				break;
			}
			if ( microtime( true ) >= $deadline ) {
				proc_terminate( $child['process'] );
				foreach ( array( 1, 2 ) as $index ) {
					if ( isset( $child['pipes'][ $index ] ) && is_resource( $child['pipes'][ $index ] ) ) {
						fclose( $child['pipes'][ $index ] );
					}
				}
				proc_close( $child['process'] );
				$child['finished'] = true;
				$this->fail( 'Timed out waiting for child writer.' );
			}
			usleep( 10000 );
		}

		$stdout = stream_get_contents( $child['pipes'][1] );
		fclose( $child['pipes'][1] );
		$stderr = stream_get_contents( $child['pipes'][2] );
		fclose( $child['pipes'][2] );
		$close_code        = proc_close( $child['process'] );
		$exit_code         = -1 === $exit_code ? $close_code : $exit_code;
		$child['finished'] = true;

		return array(
			'exit_code' => $exit_code,
			'stdout'    => $stdout,
			'stderr'    => $stderr,
		);
	}

	private function cleanup_child_writer( array $child ): void {
		if ( empty( $child['finished'] ) && is_resource( $child['process'] ) ) {
			proc_terminate( $child['process'] );
			foreach ( array( 1, 2 ) as $index ) {
				if ( isset( $child['pipes'][ $index ] ) && is_resource( $child['pipes'][ $index ] ) ) {
					fclose( $child['pipes'][ $index ] );
				}
			}
			proc_close( $child['process'] );
		}
		foreach ( array( 'script', 'ready', 'go' ) as $key ) {
			if ( isset( $child[ $key ] ) && file_exists( $child[ $key ] ) ) {
				unlink( $child[ $key ] );
			}
		}
	}
}
