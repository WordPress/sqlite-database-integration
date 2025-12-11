<?php

use PHPUnit\Framework\TestCase;

class WP_PDO_MySQL_On_SQLite_PDO_API_Tests extends TestCase {
	/** @var WP_PDO_MySQL_On_SQLite */
	private $driver;

	public function setUp(): void {
		$this->driver = new WP_PDO_MySQL_On_SQLite( 'mysql-on-sqlite:path=:memory:;dbname=wp;' );
	}

	public function test_connection(): void {
		$driver = new WP_PDO_MySQL_On_SQLite( 'mysql-on-sqlite:path=:memory:;dbname=WordPress;' );
		$this->assertInstanceOf( PDO::class, $driver );
	}

	public function test_query(): void {
		$result = $this->driver->query( "SELECT 1, 'abc'" );
		$this->assertInstanceOf( PDOStatement::class, $result );
	}

	public function test_exec(): void {
		$result = $this->driver->exec( 'SELECT 1' );
		$this->assertEquals( 0, $result );

		$result = $this->driver->exec( 'CREATE TABLE t (id INT)' );
		$this->assertEquals( 0, $result );

		$result = $this->driver->exec( 'INSERT INTO t (id) VALUES (1)' );
		$this->assertEquals( 1, $result );

		$result = $this->driver->exec( 'INSERT INTO t (id) VALUES (2), (3)' );
		$this->assertEquals( 2, $result );

		$result = $this->driver->exec( 'UPDATE t SET id = 10 + id WHERE id = 0' );
		$this->assertEquals( 0, $result );

		$result = $this->driver->exec( 'UPDATE t SET id = 10 + id WHERE id = 1' );
		$this->assertEquals( 1, $result );

		$result = $this->driver->exec( 'UPDATE t SET id = 10 + id WHERE id < 10' );
		$this->assertEquals( 2, $result );

		$result = $this->driver->exec( 'DELETE FROM t WHERE id = 11' );
		$this->assertEquals( 1, $result );

		$result = $this->driver->exec( 'DELETE FROM t' );
		$this->assertEquals( 2, $result );

		$result = $this->driver->exec( 'DROP TABLE t' );
		$this->assertEquals( 0, $result );
	}

	public function test_begin_transaction(): void {
		$result = $this->driver->beginTransaction();
		$this->assertTrue( $result );
	}

	public function test_begin_transaction_already_active(): void {
		$this->driver->beginTransaction();

		$this->expectException( PDOException::class );
		$this->expectExceptionMessage( 'There is already an active transaction' );
		$this->expectExceptionCode( 0 );
		$this->driver->beginTransaction();
	}

	public function test_commit(): void {
		$this->driver->beginTransaction();
		$result = $this->driver->commit();
		$this->assertTrue( $result );
	}

	public function test_commit_no_active_transaction(): void {
		$this->expectException( PDOException::class );
		$this->expectExceptionMessage( 'There is no active transaction' );
		$this->expectExceptionCode( 0 );
		$this->driver->commit();
	}

	public function test_rollback(): void {
		$this->driver->beginTransaction();
		$result = $this->driver->rollBack();
		$this->assertTrue( $result );
	}

	public function test_rollback_no_active_transaction(): void {
		$this->expectException( PDOException::class );
		$this->expectExceptionMessage( 'There is no active transaction' );
		$this->expectExceptionCode( 0 );
		$this->driver->rollBack();
	}
}
