<?php

use PHPUnit\Framework\TestCase;
use WP_MySQL_Proxy\Adapter\Adapter;
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

	private function authenticated_session( Adapter $adapter ): MySQL_Session {
		$session   = new MySQL_Session( $adapter, 1, new MySQL_Authentication( 'user', 'password' ) );
		$challenge = MySQL_Test_Client::challenge( $session->get_initial_handshake() );
		$session->receive_bytes( MySQL_Test_Client::handshake_response( $challenge ) );
		$this->assertTrue( $session->is_authenticated() );
		return $session;
	}
}
