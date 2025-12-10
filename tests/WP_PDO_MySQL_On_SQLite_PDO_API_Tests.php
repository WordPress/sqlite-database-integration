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
