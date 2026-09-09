<?php

use WP_MySQL_Proxy\MySQL_Protocol;

class WP_MySQL_Proxy_Authentication_Test extends WP_MySQL_Proxy_Test {
	protected $log_level = 'debug';

	/** @dataProvider invalid_credentials */
	public function test_pdo_rejects_invalid_credentials( string $username, string $password ): void {
		$this->expectException( PDOException::class );
		$this->expectExceptionCode( 1045 );
		new PDO( "mysql:host=127.0.0.1;port={$this->port};dbname=sqlite_database", $username, $password );
	}

	public function invalid_credentials(): array {
		return array( array( 'user', 'wrong' ), array( 'other', 'password' ), array( 'user', '' ) );
	}

	/** @dataProvider invalid_credentials */
	public function test_mysqli_rejects_invalid_credentials( string $username, string $password ): void {
		$driver              = new mysqli_driver();
		$mode                = $driver->report_mode;
		$driver->report_mode = MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT;
		try {
			$this->expectException( mysqli_sql_exception::class );
			$this->expectExceptionCode( 1045 );
			new mysqli( '127.0.0.1', $username, $password, 'sqlite_database', $this->port );
		} finally {
			$driver->report_mode = $mode;
		}
	}

	public function test_failed_login_closes_socket_without_logging_credentials(): void {
		$stream    = $this->connect();
		$challenge = MySQL_Test_Client::challenge( MySQL_Test_Client::read_packet( $stream ) );
		$login     = MySQL_Test_Client::handshake_response( $challenge, 'private_username', 'private_password', 'mysql_clear_password', 'sqlite_database' );
		$query     = MySQL_Protocol::build_packet( 0, "\x03CREATE TABLE forbidden (id INT)" );
		fwrite( $stream, $login . $query );
		$plugin = MySQL_Test_Client::auth_plugin();
		$switch = MySQL_Test_Client::read_packet( $stream );
		$this->assertSame( "\xfe" . $plugin . "\0", substr( $switch, 4, strlen( $plugin ) + 2 ) );
		$response = MySQL_Test_Client::read_packet( $stream );
		$this->assertSame( "\xff\x15\x04#28000", substr( $response, 4, 9 ) );
		$this->assertSame( '', fread( $stream, 1 ) );
		$this->assertTrue( feof( $stream ) );
		fclose( $stream );

		$pdo = new PDO( "mysql:host=127.0.0.1;port={$this->port}", 'user', 'password' );
		$this->assertSame( array(), $pdo->query( "SHOW TABLES LIKE 'forbidden'" )->fetchAll() );
		$this->assertStringContainsString( 'login failed, closing connection', $this->server->get_stderr() );
		$this->assertStringNotContainsString( 'private_username', $this->server->get_stderr() );
		$this->assertStringNotContainsString( 'private_password', $this->server->get_stderr() );
		$this->assertStringNotContainsString( 'mysql_clear_password', $this->server->get_stderr() );
	}

	public function test_change_user_credentials_are_not_logged(): void {
		$stream    = $this->connect();
		$challenge = MySQL_Test_Client::challenge( MySQL_Test_Client::read_packet( $stream ) );
		fwrite( $stream, MySQL_Test_Client::handshake_response( $challenge, 'user', 'password', MySQL_Test_Client::auth_plugin() ) );
		$this->assertSame( 0, ord( MySQL_Test_Client::read_login_result( $stream )[4] ) );

		fwrite( $stream, MySQL_Protocol::build_packet( 0, "\x11private_username\0\x10private_password\0mysql_clear_password\0" ) );
		$this->assertSame( 0xff, ord( MySQL_Test_Client::read_packet( $stream )[4] ) );
		fclose( $stream );
		$this->assertStringNotContainsString( 'private_username', $this->server->get_stderr() );
		$this->assertStringNotContainsString( 'private_password', $this->server->get_stderr() );
	}

	public function test_pipelined_commands_receive_responses_without_debug_logging(): void {
		$this->server->stop();
		$this->server = new MySQL_Server_Process(
			array(
				'port'        => $this->port,
				'log_level'   => 'error',
				'auth_plugin' => MySQL_Test_Client::auth_plugin(),
			)
		);
		$stream       = $this->connect();
		$challenge    = MySQL_Test_Client::challenge( MySQL_Test_Client::read_packet( $stream ) );
		$login        = MySQL_Test_Client::handshake_response( $challenge, 'user', 'password', MySQL_Test_Client::auth_plugin() );
		$ping         = MySQL_Protocol::build_packet( 0, chr( MySQL_Protocol::COM_PING ) );
		fwrite( $stream, $login . $ping . $ping );
		$this->assertSame( 0, ord( MySQL_Test_Client::read_login_result( $stream )[4] ) );
		for ( $i = 0; $i < 2; $i++ ) {
			$this->assertSame( 0, ord( MySQL_Test_Client::read_packet( $stream )[4] ) );
		}
		fclose( $stream );
	}

	public function test_native_password_account_switches_the_client(): void {
		$this->server->stop();
		$this->server = new MySQL_Server_Process(
			array(
				'port'        => $this->port,
				'auth_plugin' => 'mysql_native_password',
			)
		);

		// The client asks for caching_sha2_password and the server switches it.
		$pdo = new PDO( "mysql:host=127.0.0.1;port={$this->port}", 'user', 'password' );
		$this->assertEquals( 123, $pdo->query( 'SELECT 123' )->fetchColumn() );
	}

	public function test_explicit_ipv6_loopback_listener(): void {
		$this->server->stop();
		$this->server = new MySQL_Server_Process(
			array(
				'port'            => $this->port,
				'bind_address'    => '::1',
				'connect_address' => '[::1]',
				'log_level'       => 'info',
				'auth_plugin'     => MySQL_Test_Client::auth_plugin(),
			)
		);
		$pdo          = new PDO( "mysql:host=[::1];port={$this->port}", 'user', 'password' );
		$this->assertEquals( 123, $pdo->query( 'SELECT 123' )->fetchColumn() );
		$this->assertStringContainsString( 'MySQL proxy listening on [::1]:', $this->server->get_stderr() );
	}

	private function connect() {
		$stream = stream_socket_client( "tcp://127.0.0.1:{$this->port}", $errno, $error, 2 );
		stream_set_timeout( $stream, 2 );
		return $stream;
	}
}
