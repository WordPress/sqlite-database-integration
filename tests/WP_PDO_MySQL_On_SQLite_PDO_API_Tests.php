<?php

use PHPUnit\Framework\TestCase;

class WP_PDO_MySQL_On_SQLite_PDO_API_Tests extends TestCase {
	/** @var WP_PDO_MySQL_On_SQLite */
	private $driver;

	public function setUp(): void {
		$this->driver = new WP_PDO_MySQL_On_SQLite( 'mysql-on-sqlite:path=:memory:;dbname=wp;' );

		// Set "PDO::ATTR_STRINGIFY_FETCHES" to "false" explicitly, so the tests
		// are consistent across PHP versions ("false" is the default from 8.1).
		$this->driver->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, false );
	}

	public function test_connection(): void {
		$driver = new WP_PDO_MySQL_On_SQLite( 'mysql-on-sqlite:path=:memory:;dbname=WordPress;' );
		$this->assertInstanceOf( PDO::class, $driver );
	}

	public function test_query(): void {
		$result = $this->driver->query( "SELECT 1, 'abc'" );
		$this->assertInstanceOf( PDOStatement::class, $result );
		$this->assertSame(
			array(
				1     => 1,
				0     => 1,
				'abc' => 'abc',
			),
			$result->fetch()
		);
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

	public function test_fetch_default(): void {
		// Default fetch mode is PDO::FETCH_BOTH.
		$result = $this->driver->query( "SELECT 1, 'abc', 2" );
		$this->assertSame(
			array(
				1     => 1,
				0     => 1,
				'abc' => 'abc',
				'2'   => 2,
			),
			$result->fetch()
		);
	}

	/**
	 * @dataProvider data_pdo_fetch_methods
	 */
	public function test_fetch( $query, $mode, $expected ): void {
		$stmt   = $this->driver->query( $query );
		$result = $stmt->fetch( $mode );
		if ( is_object( $expected ) ) {
			$this->assertInstanceOf( get_class( $expected ), $result );
			$this->assertEquals( $expected, $result );
		} else {
			$this->assertSame( $expected, $result );
		}
	}

	public function data_pdo_fetch_methods(): Generator {
		// PDO::FETCH_BOTH
		yield 'PDO::FETCH_BOTH' => array(
			"SELECT 1, 'abc', 2, 'two' as `2`",
			PDO::FETCH_BOTH,
			array(
				1     => 1,
				0     => 1,
				'abc' => 'abc',
				'2'   => 'two',
				'3'   => 'two',
			),
		);

		// PDO::FETCH_NUM
		yield 'PDO::FETCH_NUM' => array(
			"SELECT 1, 'abc', 2, 'two' as `2`",
			PDO::FETCH_NUM,
			array( 1, 'abc', 2, 'two' ),
		);

		// PDO::FETCH_ASSOC
		yield 'PDO::FETCH_ASSOC' => array(
			"SELECT 1, 'abc', 2, 'two' as `2`",
			PDO::FETCH_ASSOC,
			array(
				'1'   => 1,
				'abc' => 'abc',
				'2'   => 'two',
			),
		);

		// PDO::FETCH_NAMED
		yield 'PDO::FETCH_NAMED' => array(
			"SELECT 1, 'abc', 2, 'two' as `2`",
			PDO::FETCH_NAMED,
			array(
				'1'   => 1,
				'abc' => 'abc',
				'2'   => array( 2, 'two' ),
			),
		);

		// PDO::FETCH_OBJ
		yield 'PDO::FETCH_OBJ' => array(
			"SELECT 1, 'abc', 2, 'two' as `2`",
			PDO::FETCH_OBJ,
			(object) array(
				'1'   => 1,
				'abc' => 'abc',
				'2'   => 'two',
			),
		);
	}
}
