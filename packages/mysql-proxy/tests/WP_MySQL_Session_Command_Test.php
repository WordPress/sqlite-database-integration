<?php

use PHPUnit\Framework\TestCase;
use WP_MySQL_Proxy\Adapter\Adapter;
use WP_MySQL_Proxy\Incomplete_Input_Exception;
use WP_MySQL_Proxy\Logger;
use WP_MySQL_Proxy\MySQL_Authentication;
use WP_MySQL_Proxy\MySQL_Protocol;
use WP_MySQL_Proxy\MySQL_Result;
use WP_MySQL_Proxy\MySQL_Session;

class WP_MySQL_Session_Command_Test extends TestCase {
	/** @dataProvider database_names */
	public function test_init_db_quotes_the_database_name( string $database, string $expected ): void {
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->once() )->method( 'handle_query' )->with( $expected )->willReturn( MySQL_Result::from_data( 0, 0, array(), array() ) );
		$session  = $this->authenticated_session( $adapter );
		$response = $session->receive_bytes( MySQL_Protocol::build_packet( 0, chr( MySQL_Protocol::COM_INIT_DB ) . $database ) );
		$this->assertSame( 0, ord( $response[4] ) );
	}

	public function database_names(): array {
		return array(
			array( 'sqlite_database', 'USE `sqlite_database`' ),
			array( 'my db', 'USE `my db`' ),
			array( 'a`; DROP TABLE wp_users', 'USE `a``; DROP TABLE wp_users`' ),
		);
	}

	/** @dataProvider init_db_failures */
	public function test_init_db_reports_an_unknown_database( bool $throws ): void {
		$adapter     = $this->createMock( Adapter::class );
		$expectation = $adapter->expects( $this->once() )->method( 'handle_query' )->with( 'USE `missing`' );
		if ( $throws ) {
			$expectation->willThrowException( new RuntimeException( 'Private adapter error' ) );
		} else {
			$expectation->willReturn( MySQL_Result::from_error( 'HY000', 1105, 'Private adapter error' ) );
		}

		// The adapter's reason goes to the log, not to the client.
		$logger = $this->createMock( Logger::class );
		$logger->expects( $this->once() )->method( $throws ? 'error' : 'warning' )->with(
			$this->anything(),
			$this->callback(
				function ( array $context ): bool {
					return 'Private adapter error' === $context['error'];
				}
			)
		);

		$session  = $this->authenticated_session( $adapter, $logger );
		$response = $session->receive_bytes( MySQL_Protocol::build_packet( 0, chr( MySQL_Protocol::COM_INIT_DB ) . 'missing' ) );
		$this->assertSame( 0xff, ord( $response[4] ) );
		$this->assertSame( 1049, unpack( 'v', substr( $response, 5, 2 ) )[1] );
		$this->assertSame( "#42000Unknown database 'missing'", substr( $response, 7 ) );
		$this->assertFalse( $session->is_closed(), 'Unlike the login, a failed COM_INIT_DB keeps the connection.' );
	}

	public function init_db_failures(): array {
		return array(
			'adapter error'  => array( false ),
			'adapter throws' => array( true ),
		);
	}

	public function test_init_db_refuses_an_empty_name(): void {
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->never() )->method( 'handle_query' );
		$session  = $this->authenticated_session( $adapter );
		$response = $session->receive_bytes( MySQL_Protocol::build_packet( 0, chr( MySQL_Protocol::COM_INIT_DB ) ) );
		$this->assertSame( 0xff, ord( $response[4] ) );
		$this->assertSame( 1046, unpack( 'v', substr( $response, 5, 2 ) )[1] );
		$this->assertSame( '#3D000No database selected', substr( $response, 7 ) );
		$this->assertFalse( $session->is_closed() );
	}

	public function test_split_message_is_assembled(): void {
		$this->iniSet( 'memory_limit', '512M' );
		$queries = array();
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->once() )->method( 'handle_query' )->willReturnCallback(
			function ( string $query ) use ( &$queries ): MySQL_Result {
				$queries[] = $query;
				return MySQL_Result::from_data( 0, 0, array(), array() );
			}
		);
		$session = $this->authenticated_session( $adapter );

		// A packet with a full payload continues in the next packet.
		$prefix = chr( MySQL_Protocol::COM_QUERY ) . 'SELECT 1 -- ';
		$packet = MySQL_Protocol::build_packet( 0, $prefix . str_repeat( 'x', MySQL_Protocol::MAX_PACKET_LENGTH - strlen( $prefix ) ) );
		try {
			$session->receive_bytes( $packet );
			$this->fail( 'A split message must wait for the next packet.' );
		} catch ( Incomplete_Input_Exception $e ) {
			$this->assertSame( array(), $queries, 'The first packet must not run on its own.' );
		}
		unset( $packet );

		// One more byte makes the message exactly 16 MB, the limit.
		$response = $session->receive_bytes( MySQL_Protocol::build_packet( 1, '1' ) );
		$this->assertSame( 0, ord( $response[4] ) );
		$this->assertSame( 2, ord( $response[3] ), 'The response continues the client sequence.' );
		$this->assertCount( 1, $queries );
		$this->assertSame( MySQL_Session::MAX_MESSAGE_SIZE - 1, strlen( $queries[0] ) );
		$this->assertStringEndsWith( 'xx1', $queries[0] );
		$this->assertFalse( $session->is_closed() );
	}

	public function test_oversized_message_is_refused(): void {
		$this->iniSet( 'memory_limit', '512M' );
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->never() )->method( 'handle_query' );
		$session = $this->authenticated_session( $adapter );

		$packet = MySQL_Protocol::build_packet( 0, chr( MySQL_Protocol::COM_QUERY ) . str_repeat( 'x', MySQL_Protocol::MAX_PACKET_LENGTH - 1 ) );
		try {
			$session->receive_bytes( $packet );
			$this->fail( 'A split message must wait for the next packet.' );
		} catch ( Incomplete_Input_Exception $e ) {
			$this->assertFalse( $session->is_closed() );
		}
		unset( $packet );

		// One byte past the limit, which is checked before the payload is buffered.
		$response = $session->receive_bytes( MySQL_Protocol::build_packet( 1, '12' ) );
		$this->assertSame( 0xff, ord( $response[4] ) );
		$this->assertSame( 1153, unpack( 'v', substr( $response, 5, 2 ) )[1] );
		$this->assertSame( '#08S01', substr( $response, 7, 6 ) );
		$this->assertSame( MySQL_Session::CLOSE_PACKET_TOO_LARGE, $session->get_close_reason() );
		$this->assertFalse( $session->has_buffered_data() );
	}

	public function test_internal_error_is_not_sent_to_the_client(): void {
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->once() )->method( 'handle_query' )->willThrowException(
			new RuntimeException( 'Private detail from /srv/secret/path.php' )
		);
		$session = $this->authenticated_session( $adapter );

		// The detail goes to the log, which the test runner shows on stderr.
		$response = $session->receive_bytes( MySQL_Protocol::build_packet( 0, chr( MySQL_Protocol::COM_QUERY ) . 'SELECT 1' ) );

		$this->assertSame( 0xff, ord( $response[4] ) );
		$this->assertSame( 1105, unpack( 'v', substr( $response, 5, 2 ) )[1] );
		$this->assertSame( 'Unknown error', substr( $response, 13 ) );
		$this->assertStringNotContainsString( '/srv/secret/path.php', $response );
	}

	public function test_result_without_an_insert_id_is_an_ok_packet(): void {
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->once() )->method( 'handle_query' )->willReturn( new MySQL_Result() );
		$session  = $this->authenticated_session( $adapter );
		$response = $session->receive_bytes( MySQL_Protocol::build_packet( 0, chr( MySQL_Protocol::COM_QUERY ) . 'DELETE FROM t' ) );
		$this->assertSame( 0, ord( $response[4] ) );
		$this->assertFalse( $session->is_closed() );
	}

	public function test_empty_command_packet_is_refused(): void {
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->never() )->method( 'handle_query' );
		$session  = $this->authenticated_session( $adapter );
		$response = $session->receive_bytes( MySQL_Protocol::build_packet( 0, '' ) );
		$this->assertSame( 0xff, ord( $response[4] ) );
		$this->assertSame( 1835, unpack( 'v', substr( $response, 5, 2 ) )[1] );
		$this->assertSame( MySQL_Session::CLOSE_MALFORMED_PACKET, $session->get_close_reason() );
	}

	public function test_quit_closes_the_session(): void {
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->never() )->method( 'handle_query' );
		$session = $this->authenticated_session( $adapter );

		// A pipelined command after COM_QUIT must not run.
		$quit  = MySQL_Protocol::build_packet( 0, chr( MySQL_Protocol::COM_QUIT ) );
		$query = MySQL_Protocol::build_packet( 1, chr( MySQL_Protocol::COM_QUERY ) . 'DELETE FROM wp_users' );
		$this->assertSame( '', $session->receive_bytes( $quit . $query ) );
		$this->assertTrue( $session->is_closed() );
		$this->assertSame( MySQL_Session::CLOSE_CLIENT_QUIT, $session->get_close_reason() );
		$this->assertFalse( $session->has_buffered_data() );
		$this->assertNull( $session->receive_bytes( $query ) );
	}

	public function test_failed_login_reports_its_close_reason(): void {
		$session = new MySQL_Session( $this->createMock( Adapter::class ), 1, new MySQL_Authentication( 'user', 'password' ) );
		$session->get_initial_handshake();
		$session->receive_bytes( MySQL_Protocol::build_packet( 1, str_repeat( "\0", 40 ) ) );
		$this->assertSame( MySQL_Session::CLOSE_LOGIN_FAILED, $session->get_close_reason() );
	}

	private function authenticated_session( Adapter $adapter, ?Logger $logger = null ): MySQL_Session {
		$session   = new MySQL_Session( $adapter, 1, new MySQL_Authentication( 'user', 'password' ), $logger );
		$challenge = MySQL_Test_Client::challenge( $session->get_initial_handshake() );
		$session->receive_bytes( MySQL_Test_Client::handshake_response( $challenge ) );
		$this->assertTrue( $session->is_authenticated() );
		return $session;
	}
}
