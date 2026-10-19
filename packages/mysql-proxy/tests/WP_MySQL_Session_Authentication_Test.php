<?php

use PHPUnit\Framework\TestCase;
use WP_MySQL_Proxy\Adapter\Adapter;
use WP_MySQL_Proxy\Incomplete_Input_Exception;
use WP_MySQL_Proxy\MySQL_Authentication;
use WP_MySQL_Proxy\MySQL_Protocol;
use WP_MySQL_Proxy\MySQL_Result;
use WP_MySQL_Proxy\MySQL_Session;

class WP_MySQL_Session_Authentication_Test extends TestCase {
	/** @dataProvider authentication_methods */
	public function test_successful_authentication( string $plugin, int $flags ): void {
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->never() )->method( 'handle_query' );
		$session   = new MySQL_Session( $adapter, 1, $this->account( $plugin ) );
		$challenge = MySQL_Test_Client::challenge( $session->get_initial_handshake() );
		$response  = $session->receive_bytes( MySQL_Test_Client::handshake_response( $challenge, 'user', 'password', $plugin, '', $flags ) );
		$this->assertTrue( $session->is_authenticated() );
		$this->assertFalse( $session->is_closed() );
		if ( MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD === $plugin ) {
			$this->assertSame( "\x02\0\0\x02\x01\x03", substr( $response, 0, 6 ) );
			$this->assertSame( 3, ord( $response[9] ) );
			$this->assertSame( 0, ord( $response[10] ) );
		} else {
			$this->assertSame( 2, ord( $response[3] ) );
			$this->assertSame( 0, ord( $response[4] ) );
		}
	}

	public function authentication_methods(): array {
		$base   = MySQL_Protocol::CLIENT_PROTOCOL_41 | MySQL_Protocol::CLIENT_SECURE_CONNECTION;
		$plugin = $base | MySQL_Protocol::CLIENT_PLUGIN_AUTH;
		$lenenc = $plugin | MySQL_Protocol::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA;
		return array(
			array( MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD, $plugin ),
			array( MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD, $lenenc ),
			array( MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD, $plugin ),
			array( MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD, $lenenc ),
			array( MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD, $base ),
		);
	}

	/** @dataProvider rejected_logins */
	public function test_failed_login_never_calls_adapter( string $username, string $password, string $plugin ): void {
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->never() )->method( 'handle_query' );
		$session   = new MySQL_Session( $adapter, 1, $this->account( $plugin ) );
		$challenge = MySQL_Test_Client::challenge( $session->get_initial_handshake() );
		$login     = MySQL_Test_Client::handshake_response( $challenge, $username, $password, $plugin, 'private_database' );
		$query     = MySQL_Protocol::build_packet( 0, chr( MySQL_Protocol::COM_QUERY ) . 'DELETE FROM wp_users' );
		$response  = $session->receive_bytes( $login . $query );
		if ( 'mysql_clear_password' === $plugin
			|| ( MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD === $plugin && '' === $password )
		) {
			$this->assertSame( 0xfe, ord( $response[4] ) );
			$this->assertFalse( $session->is_authenticated() );
			$response = $session->receive_bytes( '' );
		}
		$this->assert_denied( $session, $response );
		$this->assertFalse( $session->has_buffered_data() );
		$this->assertNull( $session->receive_bytes( MySQL_Test_Client::handshake_response( $challenge ) . $query ) );
	}

	public function rejected_logins(): array {
		$cases = array();
		foreach ( array( MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD, MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD ) as $plugin ) {
			$cases[] = array( 'other', 'password', $plugin );
			$cases[] = array( 'user', 'wrong', $plugin );
			$cases[] = array( 'user', '', $plugin );
			$cases[] = array( '', 'password', $plugin );
		}
		$cases[] = array( 'user', 'password', 'mysql_clear_password' );
		return $cases;
	}

	public function test_native_authentication_switch_preserves_database_and_buffered_command(): void {
		$queries = array();
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->once() )->method( 'handle_query' )->willReturnCallback(
			function ( string $query ) use ( &$queries ): MySQL_Result {
				$queries[] = $query;
				return new MySQL_Result();
			}
		);
		$session   = new MySQL_Session( $adapter, 1, $this->account( MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD ) );
		$challenge = $this->start_native_authentication( $session, 'user', 'a`b' );
		$this->assertSame( array(), $queries );
		$token = MySQL_Test_Client::scramble( 'password', $challenge, MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD );
		$reply = MySQL_Protocol::build_packet( 3, $token );
		try {
			$session->receive_bytes( substr( $reply, 0, 7 ) );
			$this->fail( 'A partial authentication response must wait for more input.' );
		} catch ( Incomplete_Input_Exception $e ) {
			$this->assertFalse( $session->is_authenticated() );
			$this->assertSame( array(), $queries );
		}
		$ping     = MySQL_Protocol::build_packet( 0, chr( MySQL_Protocol::COM_PING ) );
		$response = $session->receive_bytes( substr( $reply, 7 ) . $ping );
		$this->assertSame( 4, ord( $response[3] ) );
		$this->assertSame( 0, ord( $response[4] ) );
		$this->assertTrue( $session->is_authenticated() );
		$this->assertFalse( $session->is_closed() );
		$this->assertSame( array( 'USE `a``b`' ), $queries );
		$this->assertTrue( $session->has_buffered_data() );
		$this->assertSame( 0, ord( $session->receive_bytes( '' )[4] ) );
		$this->assertFalse( $session->has_buffered_data() );
	}

	/** @dataProvider invalid_native_responses */
	public function test_invalid_native_switch_response_is_denied( string $username, string $password, int $sequence, int $length ): void {
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->never() )->method( 'handle_query' );
		$session   = new MySQL_Session( $adapter, 1, $this->account( MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD ) );
		$challenge = $this->start_native_authentication( $session, $username, 'private_database' );
		$token     = MySQL_Test_Client::scramble( $password, $challenge, MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD );
		$token     = substr( $token . "\0", 0, $length );
		$query     = MySQL_Protocol::build_packet( 0, "\x03DELETE FROM wp_users" );
		$this->assert_denied( $session, $session->receive_bytes( MySQL_Protocol::build_packet( $sequence, $token ) . $query ) );
		$this->assertFalse( $session->has_buffered_data() );
		$this->assertNull( $session->receive_bytes( MySQL_Protocol::build_packet( 3, $token ) ) );
	}

	public function invalid_native_responses(): array {
		return array(
			array( 'other', 'password', 3, 20 ),
			array( 'user', 'wrong', 3, 20 ),
			array( 'user', '', 3, 0 ),
			array( 'user', 'password', 3, 19 ),
			array( 'user', 'password', 3, 21 ),
			array( 'user', 'password', 0, 20 ),
			array( 'user', 'password', 1, 20 ),
			array( 'user', 'password', 2, 20 ),
			array( 'user', 'password', 4, 20 ),
		);
	}

	/** @dataProvider unsupported_plugins */
	public function test_unsupported_plugin_switches_to_caching_sha2_password( string $plugin, string $password ): void {
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->never() )->method( 'handle_query' );
		$session   = new MySQL_Session( $adapter, 1, $this->account() );
		$challenge = $this->start_authentication_switch( $session, $plugin, $password, MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD );
		$token     = MySQL_Test_Client::scramble( 'password', $challenge, MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD );
		$response  = $session->receive_bytes( MySQL_Protocol::build_packet( 3, $token ) );
		$this->assertSame( "\x02\0\0\x04\x01\x03", substr( $response, 0, 6 ) );
		$this->assertSame( 5, ord( $response[9] ) );
		$this->assertSame( 0, ord( $response[10] ) );
		$this->assertTrue( $session->is_authenticated() );
		$this->assertFalse( $session->is_closed() );
	}

	public function unsupported_plugins(): array {
		return array(
			array( 'sha256_password', '' ),
			array( 'mysql_clear_password', 'password' ),
			array( '', '' ),
		);
	}

	public function test_switch_targets_the_plugin_the_account_accepts(): void {
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->never() )->method( 'handle_query' );
		$native    = MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD;
		$session   = new MySQL_Session( $adapter, 1, $this->account( $native ) );
		$challenge = $this->start_authentication_switch( $session, MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD, 'password', $native );
		$token     = MySQL_Test_Client::scramble( 'password', $challenge, $native );
		$session->receive_bytes( MySQL_Protocol::build_packet( 3, $token ) );
		$this->assertTrue( $session->is_authenticated() );
		$this->assertFalse( $session->is_closed() );
	}

	/** @dataProvider invalid_caching_sha2_switch_responses */
	public function test_invalid_caching_sha2_switch_response_is_denied( string $password, string $plugin, int $sequence ): void {
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->never() )->method( 'handle_query' );
		$session   = new MySQL_Session( $adapter, 1, $this->account() );
		$challenge = $this->start_authentication_switch( $session, 'sha256_password', '', MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD, 'user', 'private_database' );
		$token     = MySQL_Test_Client::scramble( $password, $challenge, $plugin );
		$this->assert_denied( $session, $session->receive_bytes( MySQL_Protocol::build_packet( $sequence, $token ) ) );
	}

	public function invalid_caching_sha2_switch_responses(): array {
		return array(
			array( 'wrong', MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD, 3 ),
			array( '', MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD, 3 ),
			array( 'password', MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD, 3 ),
			array( 'password', MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD, 4 ),
		);
	}

	public function test_native_switch_response_cannot_be_replayed(): void {
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->never() )->method( 'handle_query' );
		$account   = $this->account( MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD );
		$first     = new MySQL_Session( $adapter, 1, $account );
		$second    = new MySQL_Session( $adapter, 2, $account );
		$challenge = $this->start_native_authentication( $first );
		$this->start_native_authentication( $second );
		$token = MySQL_Test_Client::scramble( 'password', $challenge, MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD );
		$first->receive_bytes( MySQL_Protocol::build_packet( 3, $token ) );
		$this->assertTrue( $first->is_authenticated() );
		$this->assert_denied( $second, $second->receive_bytes( MySQL_Protocol::build_packet( 3, $token ) ) );
	}

	public function test_database_is_quoted_after_authentication(): void {
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->once() )->method( 'handle_query' )->with( 'USE `a``; DROP TABLE wp_users`' )->willReturn( new MySQL_Result() );
		$session   = new MySQL_Session( $adapter, 1, $this->account() );
		$challenge = MySQL_Test_Client::challenge( $session->get_initial_handshake() );
		$session->receive_bytes( MySQL_Test_Client::handshake_response( $challenge, 'user', 'password', MySQL_Protocol::DEFAULT_AUTH_PLUGIN, 'a`; DROP TABLE wp_users' ) );
		$this->assertTrue( $session->is_authenticated() );
	}

	/** @dataProvider database_errors */
	public function test_database_failure_closes_connection( bool $throws, bool $native_switch ): void {
		$adapter     = $this->createMock( Adapter::class );
		$expectation = $adapter->expects( $this->once() )->method( 'handle_query' );
		if ( $throws ) {
			$expectation->willThrowException( new RuntimeException( 'Private adapter error' ) );
		} else {
			$expectation->willReturn( MySQL_Result::from_error( '42000', 1049, 'Unknown database' ) );
		}
		$plugin  = $native_switch ? MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD : MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD;
		$session = new MySQL_Session( $adapter, 1, $this->account( $plugin ) );
		if ( $native_switch ) {
			$challenge = $this->start_native_authentication( $session, 'user', 'unknown' );
			$token     = MySQL_Test_Client::scramble( 'password', $challenge, MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD );
			$login     = MySQL_Protocol::build_packet( 3, $token );
		} else {
			$challenge = MySQL_Test_Client::challenge( $session->get_initial_handshake() );
			$login     = MySQL_Test_Client::handshake_response( $challenge, 'user', 'password', MySQL_Protocol::DEFAULT_AUTH_PLUGIN, 'unknown' );
		}
		$response = $session->receive_bytes( $login . MySQL_Protocol::build_packet( 0, "\x03SELECT 1" ) );
		$this->assertSame( 1049, unpack( 'v', substr( $response, 5, 2 ) )[1] );
		$this->assertFalse( $session->is_authenticated() );
		$this->assertTrue( $session->is_closed() );
		$this->assertFalse( $session->has_buffered_data() );
		$this->assertStringNotContainsString( 'Private adapter error', $response );
	}

	public function database_errors(): array {
		return array( array( false, false ), array( true, false ), array( false, true ), array( true, true ) );
	}

	public function test_response_cannot_be_replayed_on_another_connection(): void {
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->never() )->method( 'handle_query' );
		$account = $this->account();
		$first   = new MySQL_Session( $adapter, 1, $account );
		$second  = new MySQL_Session( $adapter, 2, $account );
		$salt    = MySQL_Test_Client::challenge( $first->get_initial_handshake() );
		$this->assertSame( 20, strlen( $salt ) );
		$this->assertStringNotContainsString( "\0", $salt );
		$this->assertNotSame( $salt, MySQL_Test_Client::challenge( $second->get_initial_handshake() ) );
		$login = MySQL_Test_Client::handshake_response( $salt );
		$first->receive_bytes( $login );
		$this->assertTrue( $first->is_authenticated() );
		$this->assert_denied( $second, $second->receive_bytes( $login ) );
	}

	public function test_every_truncated_handshake_is_denied(): void {
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->never() )->method( 'handle_query' );
		$account       = $this->account();
		$packet_length = strlen( MySQL_Test_Client::handshake_response( str_repeat( 'x', 20 ), 'user', 'password', MySQL_Protocol::DEFAULT_AUTH_PLUGIN, 'db' ) );
		for ( $length = 0; $length < $packet_length - 4; $length++ ) {
			$session   = new MySQL_Session( $adapter, 1, $account );
			$challenge = MySQL_Test_Client::challenge( $session->get_initial_handshake() );
			$payload   = substr( MySQL_Test_Client::handshake_response( $challenge, 'user', 'password', MySQL_Protocol::DEFAULT_AUTH_PLUGIN, 'db' ), 4 );
			$this->assert_denied( $session, $session->receive_bytes( MySQL_Protocol::build_packet( 1, substr( $payload, 0, $length ) ) ) );
		}
	}

	public function test_fragmented_handshake_and_pipelined_command(): void {
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->never() )->method( 'handle_query' );
		$session   = new MySQL_Session( $adapter, 1, $this->account() );
		$challenge = MySQL_Test_Client::challenge( $session->get_initial_handshake() );
		$login     = MySQL_Test_Client::handshake_response( $challenge );
		for ( $i = 0; $i < strlen( $login ) - 1; $i++ ) {
			try {
				$session->receive_bytes( $login[ $i ] );
				$this->fail( 'An incomplete packet must wait for more input.' );
			} catch ( Incomplete_Input_Exception $e ) {
				$this->assertFalse( $session->is_authenticated() );
				$this->assertFalse( $session->is_closed() );
			}
		}
		$session->receive_bytes( substr( $login, -1 ) . MySQL_Protocol::build_packet( 0, chr( MySQL_Protocol::COM_PING ) ) );
		$this->assertTrue( $session->is_authenticated() );
		$this->assertTrue( $session->has_buffered_data() );
		$this->assertSame( 0, ord( $session->receive_bytes( '' )[4] ) );
		$this->assertFalse( $session->has_buffered_data() );
	}

	public function test_oversized_login_packet_is_denied(): void {
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->never() )->method( 'handle_query' );
		$session = new MySQL_Session( $adapter, 1, $this->account() );
		$session->get_initial_handshake();
		$header = substr( pack( 'V', MySQL_Session::MAX_UNAUTHENTICATED_PACKET_SIZE + 1 ), 0, 3 ) . chr( 1 );
		$this->assert_denied( $session, $session->receive_bytes( $header . str_repeat( 'A', 4096 ) ) );
		$this->assertFalse( $session->has_buffered_data() );
	}

	public function test_large_packet_is_buffered_after_authentication(): void {
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->never() )->method( 'handle_query' );
		$session   = new MySQL_Session( $adapter, 1, $this->account() );
		$challenge = MySQL_Test_Client::challenge( $session->get_initial_handshake() );
		$session->receive_bytes( MySQL_Test_Client::handshake_response( $challenge ) );
		$this->assertTrue( $session->is_authenticated() );
		$header = substr( pack( 'V', MySQL_Session::MAX_UNAUTHENTICATED_PACKET_SIZE + 1 ), 0, 3 ) . chr( 0 );
		try {
			$session->receive_bytes( $header . str_repeat( 'A', 4096 ) );
			$this->fail( 'An incomplete packet must wait for more input.' );
		} catch ( Incomplete_Input_Exception $e ) {
			$this->assertFalse( $session->is_closed() );
		}
	}

	/** @dataProvider invalid_packets */
	public function test_invalid_packet_is_denied( string $payload, int $sequence ): void {
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->never() )->method( 'handle_query' );
		$session = new MySQL_Session( $adapter, 1, $this->account() );
		$this->assert_denied( $session, $session->receive_bytes( MySQL_Protocol::build_packet( $sequence, $payload ) ) );
	}

	public function invalid_packets(): array {
		$base   = MySQL_Protocol::CLIENT_PROTOCOL_41 | MySQL_Protocol::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA;
		$header = pack( 'V', $base ) . str_repeat( "\0", 28 ) . "user\0";
		return array(
			array( "\x03SELECT 1", 0 ),
			array( "\x02sqlite_database", 0 ),
			array( str_repeat( "\0", 40 ), 1 ),
			array( pack( 'V', $base | MySQL_Protocol::CLIENT_SSL ) . str_repeat( "\0", 28 ), 1 ),
			array( $header . "\xfc\x20", 1 ),
			array( $header . "\xfd\x20\0", 1 ),
			array( $header . "\xfe\xff\xff\xff\xff\xff\xff\xff\xff", 1 ),
			array( $header . "\xff", 1 ),
			array( $header . "\x20short", 1 ),
			array( $header . "\x01\0", 1 ),
		);
	}

	/**
	 * An account using the given plugin, or the default for a plugin no account can use.
	 */
	private function account( string $plugin = MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD ): MySQL_Authentication {
		if ( ! in_array( $plugin, MySQL_Authentication::SUPPORTED_PLUGINS, true ) ) {
			$plugin = MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD;
		}
		return new MySQL_Authentication( 'user', 'password', $plugin );
	}

	private function start_native_authentication( MySQL_Session $session, string $username = 'user', string $database = '' ): string {
		$plugin = MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD;
		return $this->start_authentication_switch( $session, $plugin, '', $plugin, $username, $database );
	}

	private function start_authentication_switch(
		MySQL_Session $session,
		string $client_plugin,
		string $password,
		string $plugin,
		string $username = 'user',
		string $database = ''
	): string {
		$challenge = MySQL_Test_Client::challenge( $session->get_initial_handshake() );
		$response  = $session->receive_bytes( MySQL_Test_Client::handshake_response( $challenge, $username, $password, $client_plugin, $database ) );
		$this->assertSame( MySQL_Protocol::build_packet( 2, "\xfe" . $plugin . "\0" . $challenge . "\0" ), $response );
		$this->assertFalse( $session->is_authenticated() );
		$this->assertFalse( $session->is_closed() );
		return $challenge;
	}

	private function assert_denied( MySQL_Session $session, string $response ): void {
		$this->assertSame( 0xff, ord( $response[4] ) );
		$this->assertSame( 1045, unpack( 'v', substr( $response, 5, 2 ) )[1] );
		$this->assertSame( '#28000', substr( $response, 7, 6 ) );
		$this->assertFalse( $session->is_authenticated() );
		$this->assertTrue( $session->is_closed() );
	}
}
