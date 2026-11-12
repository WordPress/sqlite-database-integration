<?php

class WP_MySQL_Proxy_PDO_Test extends WP_MySQL_Proxy_Test {
	/** @var PDO */
	private $pdo;

	public function setUp(): void {
		parent::setUp();

		$pdo_class = PHP_VERSION_ID >= 80400 ? Pdo\Mysql::class : PDO::class;
		$this->pdo = new $pdo_class(
			sprintf( 'mysql:host=127.0.0.1;port=%d', $this->port ),
			'user',
			'password'
		);
	}

	public function test_exec(): void {
		$result = $this->pdo->exec( 'CREATE TABLE t (id INT PRIMARY KEY, name TEXT)' );
		$this->assertEquals( 0, $result );

		$result = $this->pdo->exec( 'INSERT INTO t (id, name) VALUES (123, "abc"), (456, "def")' );
		$this->assertEquals( 2, $result );
	}

	public function test_query(): void {
		$this->pdo->exec( 'CREATE TABLE t (id INT PRIMARY KEY, name TEXT)' );
		$this->pdo->exec( 'INSERT INTO t (id, name) VALUES (123, "abc"), (456, "def")' );

		$result = $this->pdo->query( "SELECT 'test'" );
		$this->assertEquals( 'test', $result->fetchColumn() );

		$result = $this->pdo->query( 'SELECT * FROM t' );
		$this->assertEquals( 2, $result->rowCount() );
		$this->assertEquals(
			array(
				array(
					'id'   => 123,
					'name' => 'abc',
				),
				array(
					'id'   => 456,
					'name' => 'def',
				),
			),
			$result->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_duplicate_column_names(): void {
		$result = $this->pdo->query( "SELECT '1' AS x, '2' AS x" );
		$this->assertSame( 2, $result->columnCount() );
		$this->assertSame( array( array( '1', '2' ) ), $result->fetchAll( PDO::FETCH_NUM ) );

		$result = $this->pdo->query( "SELECT a.id, b.id FROM (SELECT '1' AS id) a JOIN (SELECT '2' AS id) b ON 1=1" );
		$this->assertSame( array( array( '1', '2' ) ), $result->fetchAll( PDO::FETCH_NUM ) );
	}

	public function test_null_values(): void {
		$result = $this->pdo->query( "SELECT NULL AS a, 'b' AS b, NULL AS c" );
		$this->assertSame( array( array( null, 'b', null ) ), $result->fetchAll( PDO::FETCH_NUM ) );
	}

	public function test_prepared_statement(): void {
		$this->pdo->exec( 'CREATE TABLE t (id INT PRIMARY KEY, name TEXT)' );
		$this->pdo->exec( 'INSERT INTO t (id, name) VALUES (123, "abc"), (456, "def")' );

		$stmt = $this->pdo->prepare( 'SELECT * FROM t WHERE id = ?' );
		$stmt->execute( array( 123 ) );
		$this->assertEquals(
			array(
				array(
					'id'   => 123,
					'name' => 'abc',
				),
			),
			$stmt->fetchAll( PDO::FETCH_ASSOC )
		);
	}
}
