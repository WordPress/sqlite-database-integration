<?php

use WP_MySQL_Proxy\MySQL_Protocol;

class WP_MySQL_Proxy_Slow_Client_Test extends WP_MySQL_Proxy_Test {
	public function test_client_that_stops_reading_does_not_stall_other_clients(): void {
		// A client with a small receive buffer asks for a result much larger than
		// the socket buffers can hold.
		$socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
		socket_set_option( $socket, SOL_SOCKET, SO_RCVBUF, 4096 );
		socket_connect( $socket, '127.0.0.1', $this->port );
		$stalled = socket_export_stream( $socket );
		$this->log_in( $stalled );
		$value = str_repeat( 'x', 65536 );
		$query = "WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM r WHERE n < 128) SELECT '$value' AS v FROM r";
		fwrite( $stalled, MySQL_Protocol::build_packet( 0, chr( MySQL_Protocol::COM_QUERY ) . $query ) );

		// The result has started to arrive, and the client stops reading it.
		$this->assertSame( "\x01", substr( MySQL_Test_Client::read_packet( $stalled ), 4 ) );

		// Another client is served meanwhile.
		$other = stream_socket_client( "tcp://127.0.0.1:{$this->port}", $errno, $error, 2 );
		$this->log_in( $other );
		fwrite( $other, MySQL_Protocol::build_packet( 0, chr( MySQL_Protocol::COM_PING ) ) );
		$this->assertSame( 0, ord( MySQL_Test_Client::read_packet( $other )[4] ) );
		fclose( $other );

		// The first client still gets the whole result once it reads.
		MySQL_Test_Client::read_packet( $stalled ); // Column definition.
		MySQL_Test_Client::read_packet( $stalled ); // EOF.
		for ( $i = 0; $i < 128; $i++ ) {
			$this->assertSame( "\xfd\0\0\x01" . $value, substr( MySQL_Test_Client::read_packet( $stalled ), 4 ) );
		}
		$this->assertSame( 0xfe, ord( MySQL_Test_Client::read_packet( $stalled )[4] ) );
		fclose( $stalled );
	}

	private function log_in( $stream ): void {
		stream_set_timeout( $stream, 2 );
		$challenge = MySQL_Test_Client::challenge( MySQL_Test_Client::read_packet( $stream ) );
		fwrite( $stream, MySQL_Test_Client::handshake_response( $challenge, 'user', 'password', MySQL_Test_Client::auth_plugin() ) );
		$this->assertSame( 0, ord( MySQL_Test_Client::read_login_result( $stream )[4] ) );
	}
}
