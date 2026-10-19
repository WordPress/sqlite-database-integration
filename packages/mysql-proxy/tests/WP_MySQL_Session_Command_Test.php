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

	private function authenticated_session( Adapter $adapter ): MySQL_Session {
		$session   = new MySQL_Session( $adapter, 1, new MySQL_Authentication( 'user', 'password' ) );
		$challenge = MySQL_Test_Client::challenge( $session->get_initial_handshake() );
		$session->receive_bytes( MySQL_Test_Client::handshake_response( $challenge ) );
		$this->assertTrue( $session->is_authenticated() );
		return $session;
	}
}
