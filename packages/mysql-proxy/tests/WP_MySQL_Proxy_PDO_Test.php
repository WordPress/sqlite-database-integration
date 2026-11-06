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

	public function test_large_result_row(): void {
		$this->iniSet( 'memory_limit', '512M' );

		$this->pdo->exec( 'CREATE TABLE t (v LONGTEXT)' );
		$this->pdo->exec( "INSERT INTO t (v) VALUES ('" . str_repeat( 'a', 1048576 ) . "')" );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->pdo->exec( 'UPDATE t SET v = CONCAT(v, v)' );
		}

		// 16,777,211 bytes + 4-byte 0xfd length prefix = 16,777,215 (exact MAX_PACKET_LENGTH).
		$exact = $this->pdo->query( 'SELECT SUBSTRING(v, 1, 16777211) FROM t' )->fetchColumn();
		$this->assertSame( 16777211, strlen( $exact ) );

		// 16,777,220 bytes + 9-byte 0xfe length prefix > MAX_PACKET_LENGTH.
		$over = $this->pdo->query( "SELECT CONCAT(v, 'tail') FROM t" )->fetchColumn();
		$this->assertSame( 16777220, strlen( $over ) );
		$this->assertSame( 'aatail', substr( $over, -6 ) );

		// Verify the connection remains synchronized for subsequent queries.
		$this->assertEquals( 1, $this->pdo->query( 'SELECT 1' )->fetchColumn() );
	}
}
