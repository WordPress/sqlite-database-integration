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

	/**
	 * @dataProvider data_pdo_fetch_methods
	 */
	public function test_query_with_fetch_mode( $query, $mode, $expected ): void {
		$stmt   = $this->driver->query( $query, $mode );
		$result = $stmt->fetch();
		if ( is_object( $expected ) ) {
			$this->assertInstanceOf( get_class( $expected ), $result );
			$this->assertEquals( $expected, $result );
		} else {
			$this->assertSame( $expected, $result );
		}

		$this->assertFalse( $stmt->fetch() );
	}

	public function test_query_fetch_mode_not_set(): void {
		$result = $this->driver->query( 'SELECT 1' );
		$this->assertSame(
			array(
				'1' => 1,
				0   => 1,
			),
			$result->fetch()
		);
		$this->assertFalse( $result->fetch() );
	}

	public function test_query_fetch_mode_invalid_arg_count(): void {
		$this->expectException( ArgumentCountError::class );
		$this->expectExceptionMessage( 'PDO::query() expects exactly 2 arguments for the fetch mode provided, 3 given' );
		$this->driver->query( 'SELECT 1', PDO::FETCH_ASSOC, 0 );
	}

	public function test_query_fetch_default_mode_allow_any_args(): void {
		$expected_result = array(
			array(
				1 => 1,
				0 => 1,
			),
		);

		$result = $this->driver->query( 'SELECT 1' );
		$this->assertSame( $expected_result, $result->fetchAll() );

		$result = $this->driver->query( 'SELECT 1', null );
		$this->assertSame( $expected_result, $result->fetchAll() );

		$result = $this->driver->query( 'SELECT 1', null, 1 );
		$this->assertSame( $expected_result, $result->fetchAll() );

		$result = $this->driver->query( 'SELECT 1', null, 'abc' );
		$this->assertSame( $expected_result, $result->fetchAll() );

		$result = $this->driver->query( 'SELECT 1', null, 1, 2, 'abc', array(), true );
		$this->assertSame( $expected_result, $result->fetchAll() );
	}

	public function test_query_fetch_class_not_enough_args(): void {
		$this->expectException( ArgumentCountError::class );
		$this->expectExceptionMessage( 'PDO::query() expects at least 3 arguments for the fetch mode provided, 2 given' );
		$this->driver->query( 'SELECT 1', PDO::FETCH_CLASS );
	}

	public function test_query_fetch_class_too_many_args(): void {
		$this->expectException( ArgumentCountError::class );
		$this->expectExceptionMessage( 'PDO::query() expects at most 4 arguments for the fetch mode provided, 5 given' );
		$this->driver->query( 'SELECT 1', PDO::FETCH_CLASS, '\stdClass', array(), array() );
	}

	public function test_query_fetch_class_invalid_class_type(): void {
		$this->expectException( TypeError::class );
		$this->expectExceptionMessage( 'PDO::query(): Argument #3 must be of type string, int given' );
		$this->driver->query( 'SELECT 1', PDO::FETCH_CLASS, 1 );
	}

	public function test_query_fetch_class_invalid_class_name(): void {
		$this->expectException( TypeError::class );
		$this->expectExceptionMessage( 'PDO::query(): Argument #3 must be a valid class' );
		$this->driver->query( 'SELECT 1', PDO::FETCH_CLASS, 'non-existent-class' );
	}

	public function test_query_fetch_class_invalid_constructor_args_type(): void {
		$this->expectException( TypeError::class );
		$this->expectExceptionMessage( 'PDO::query(): Argument #4 must be of type ?array, int given' );
		$this->driver->query( 'SELECT 1', PDO::FETCH_CLASS, 'stdClass', 1 );
	}

	public function test_query_fetch_into_invalid_arg_count(): void {
		$this->expectException( ArgumentCountError::class );
		$this->expectExceptionMessage( 'PDO::query() expects exactly 3 arguments for the fetch mode provided, 2 given' );
		$this->driver->query( 'SELECT 1', PDO::FETCH_INTO );
	}

	public function test_query_fetch_into_invalid_object_type(): void {
		$this->expectException( TypeError::class );
		$this->expectExceptionMessage( 'PDO::query(): Argument #3 must be of type object, int given' );
		$this->driver->query( 'SELECT 1', PDO::FETCH_INTO, 1 );
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
