<?php

class WP_MySQL_Proxy_MySQLi_Test extends WP_MySQL_Proxy_Test {
	/** @var mysqli */
	private $mysqli;

	public function setUp(): void {
		parent::setUp();
		$this->mysqli = new mysqli( '127.0.0.1', 'user', 'password', 'sqlite_database', $this->port );
	}

	public function test_query(): void {
		$result = $this->mysqli->query( 'CREATE TABLE t (id INT PRIMARY KEY, name TEXT)' );
		$this->assertTrue( $result );

		$result = $this->mysqli->query( 'INSERT INTO t (id, name) VALUES (123, "abc"), (456, "def")' );
		$this->assertEquals( 2, $result );
	}

	public function test_prepared_statement(): void {
		// TODO: Implement prepared statements in the MySQL proxy.
		$this->markTestSkipped( 'Prepared statements are not supported yet.' );
	}

	public function test_real_escape_string_honors_no_backslash_escapes(): void {
		$value = "a'b\\c";

		$this->mysqli->query( "SET SESSION sql_mode = ''" );
		$this->assertSame( "a\\'b\\\\c", $this->mysqli->real_escape_string( $value ) );

		$this->mysqli->query( "SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES'" );
		$this->assertSame( "a''b\\c", $this->mysqli->real_escape_string( $value ) );
	}
}
