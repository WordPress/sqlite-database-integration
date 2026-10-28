<?php

use PHPUnit\Framework\TestCase;
use WP_MySQL_Proxy\Adapter\Adapter;
use WP_MySQL_Proxy\MySQL_Authentication;
use WP_MySQL_Proxy\MySQL_Protocol;
use WP_MySQL_Proxy\MySQL_Proxy;
use WP_MySQL_Proxy\MySQL_Result;
use WP_MySQL_Proxy\MySQL_Session;

class WP_MySQL_Proxy_Limits_Test extends TestCase {
	/** @dataProvider deadlines */
	public function test_deadline( int $deadline_offset, bool $authenticated, string $output, bool $expected_open, int $expected_timeout ): void {
		$proxy   = $this->proxy();
		$clients = $this->property( 'clients' );
		$clients->setValue(
			$proxy,
			array( 1 => $this->client( $this->socket(), $this->session( $authenticated ), $this->now( $proxy ) + $deadline_offset, $output ) )
		);

		$this->assertSame( $expected_timeout, $this->call( $proxy, 'get_select_timeout' ) );
		$this->call( $proxy, 'close_timed_out_clients', array() );
		$this->assertCount( $expected_open ? 1 : 0, $clients->getValue( $proxy ) );
	}

	/** @dataProvider logins_read_past_the_deadline */
	public function test_login_read_past_the_deadline( bool $sent, bool $expected_open ): void {
		$proxy  = $this->proxy();
		$port   = $this->listen( $proxy );
		$stream = stream_socket_client( "tcp://127.0.0.1:$port", $errno, $error, 2 );
		stream_set_timeout( $stream, 2 );
		$this->call( $proxy, 'handle_new_client' );
		$clients   = $this->property( 'clients' );
		$client_id = key( $clients->getValue( $proxy ) );
		$challenge = MySQL_Test_Client::challenge( MySQL_Test_Client::read_packet( $stream ) );
		if ( $sent ) {
			fwrite( $stream, MySQL_Test_Client::handshake_response( $challenge ) );
			$read   = array( $clients->getValue( $proxy )[ $client_id ]['socket'] );
			$write  = null;
			$except = null;
			$this->assertSame( 1, socket_select( $read, $write, $except, 2 ) );
		}

		// The deadline passes before the proxy gets to the client, such as while
		// it runs a long query for another client.
		$value                           = $clients->getValue( $proxy );
		$value[ $client_id ]['deadline'] = $this->now( $proxy ) - 1;
		$clients->setValue( $proxy, $value );
		$this->call( $proxy, 'handle_socket_activity' );

		if ( $expected_open ) {
			$this->assertTrue( $clients->getValue( $proxy )[ $client_id ]['session']->is_authenticated() );
			$this->assertSame( 0, ord( MySQL_Test_Client::read_login_result( $stream )[4] ) );
		} else {
			$this->assertSame( array(), $clients->getValue( $proxy ) );
			$this->assertSame( '', fread( $stream, 1 ), 'The connection is closed.' );
		}
		fclose( $stream );
	}

	public function logins_read_past_the_deadline(): array {
		return array(
			'login sent before the deadline' => array( true, true ),
			'nothing sent'                   => array( false, false ),
		);
	}

	public function test_login_read_past_the_deadline_has_time_to_answer_a_switch(): void {
		$proxy  = $this->proxy( null, MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD );
		$port   = $this->listen( $proxy );
		$stream = stream_socket_client( "tcp://127.0.0.1:$port", $errno, $error, 2 );
		stream_set_timeout( $stream, 2 );
		$this->call( $proxy, 'handle_new_client' );
		$clients   = $this->property( 'clients' );
		$client_id = key( $clients->getValue( $proxy ) );
		$challenge = MySQL_Test_Client::challenge( MySQL_Test_Client::read_packet( $stream ) );

		// The client logs in with caching_sha2_password, so the native account needs a switch.
		fwrite( $stream, MySQL_Test_Client::handshake_response( $challenge ) );
		$read   = array( $clients->getValue( $proxy )[ $client_id ]['socket'] );
		$write  = null;
		$except = null;
		$this->assertSame( 1, socket_select( $read, $write, $except, 2 ) );

		// The deadline passes before the proxy gets to the client.
		$value                           = $clients->getValue( $proxy );
		$value[ $client_id ]['deadline'] = $this->now( $proxy ) - 1;
		$clients->setValue( $proxy, $value );
		$this->call( $proxy, 'handle_socket_activity' );
		$this->assertSame( 0xfe, ord( MySQL_Test_Client::read_packet( $stream )[4] ), 'The proxy asks for a switch.' );

		// The proxy checks the deadlines again before the client can answer.
		$this->call( $proxy, 'close_timed_out_clients', array() );
		$this->assertArrayHasKey( $client_id, $clients->getValue( $proxy ), 'The client has time to answer.' );
		$this->assertEqualsWithDelta( $this->now( $proxy ) + MySQL_Proxy::LOGIN_TIMEOUT, $clients->getValue( $proxy )[ $client_id ]['deadline'], 2 );

		$scramble = MySQL_Test_Client::scramble( 'password', $challenge, MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD );
		fwrite( $stream, MySQL_Protocol::build_packet( 3, $scramble ) );
		$this->receive( $proxy, $client_id );
		$this->assertSame( 0, ord( MySQL_Test_Client::read_login_result( $stream )[4] ) );
		fclose( $stream );
	}

	public function deadlines(): array {
		return array(
			'login within the deadline'          => array( 5, false, '', true, 5 ),
			'login past the deadline'            => array( -1, false, '', false, 0 ),
			'idle within the deadline'           => array( 5, true, '', true, 5 ),
			'idle past the deadline'             => array( -1, true, '', false, 0 ),
			'response within the write deadline' => array( 5, true, 'pending', true, 5 ),
			'response past the write deadline'   => array( -1, true, 'pending', false, 0 ),
		);
	}

	public function test_select_waits_indefinitely_without_clients(): void {
		$proxy = $this->proxy();
		$this->assertNull( $this->call( $proxy, 'get_select_timeout' ) );
	}

	public function test_deadlines_use_a_monotonic_clock(): void {
		$proxy = $this->proxy();

		// The monotonic clock counts from an arbitrary point, such as the system start.
		$expected = PHP_VERSION_ID >= 70300 ? hrtime()[0] : time();
		$this->assertEqualsWithDelta( $expected, $this->now( $proxy ), 1 );
	}

	/** @dataProvider client_activity */
	public function test_activity_restarts_the_deadline_after_login( bool $authenticated, string $request, int $expected_deadline ): void {
		$proxy = $this->proxy();
		$pair  = array();
		socket_create_pair( AF_UNIX, SOCK_STREAM, 0, $pair );
		$this->property( 'clients' )->setValue(
			$proxy,
			array( 1 => $this->client( $pair[1], $this->session( $authenticated ), $this->now( $proxy ) + 5 ) )
		);

		socket_write( $pair[0], $request );
		$this->call( $proxy, 'handle_client_activity', 1 );

		$deadline = $this->property( 'clients' )->getValue( $proxy )[1]['deadline'];
		$this->assertEqualsWithDelta( $this->now( $proxy ) + $expected_deadline, $deadline, 2 );
	}

	public function client_activity(): array {
		$ping = MySQL_Protocol::build_packet( 0, chr( MySQL_Protocol::COM_PING ) );
		return array(
			'logged-in client sends a command'         => array( true, $ping, MySQL_Proxy::IDLE_TIMEOUT ),
			'logged-in client sends part of a command' => array( true, "\x40\0\0\0\x03SELECT", MySQL_Proxy::READ_TIMEOUT ),
			'client sends part of its login'           => array( false, "\x40\0\0\x01partial", 5 ),
		);
	}

	public function test_client_that_stops_reading_does_not_block_the_proxy(): void {
		// A result much larger than the socket buffers can hold.
		$column  = array(
			'name'     => 'v',
			'length'   => 65536,
			'type'     => MySQL_Protocol::FIELD_TYPE_LONG_BLOB,
			'flags'    => 0,
			'decimals' => 0,
		);
		$rows    = array_fill( 0, 128, (object) array( 'v' => str_repeat( 'x', 65536 ) ) );
		$adapter = $this->createMock( Adapter::class );
		$adapter->expects( $this->once() )->method( 'handle_query' )->willReturn( MySQL_Result::from_data( 0, 0, array( $column ), $rows ) );
		$proxy = $this->proxy( $adapter );

		// Connect a client with a small receive buffer.
		$port   = $this->listen( $proxy );
		$socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
		socket_set_option( $socket, SOL_SOCKET, SO_RCVBUF, 4096 );
		socket_connect( $socket, '127.0.0.1', $port );
		$stream = socket_export_stream( $socket );
		stream_set_timeout( $stream, 2 );
		$this->call( $proxy, 'handle_new_client' );
		$clients   = $this->property( 'clients' );
		$client_id = key( $clients->getValue( $proxy ) );

		// Bound a blocking write, so a regression fails instead of hanging the test.
		$connection = $clients->getValue( $proxy )[ $client_id ]['socket'];
		socket_set_option(
			$connection,
			SOL_SOCKET,
			SO_SNDTIMEO,
			array(
				'sec'  => 1,
				'usec' => 0,
			)
		);

		$challenge = MySQL_Test_Client::challenge( MySQL_Test_Client::read_packet( $stream ) );
		fwrite( $stream, MySQL_Test_Client::handshake_response( $challenge ) );
		$this->receive( $proxy, $client_id );
		$this->assertSame( 0, ord( MySQL_Test_Client::read_login_result( $stream )[4] ) );

		// Send a query and a ping without reading the result.
		$ping = MySQL_Protocol::build_packet( 0, chr( MySQL_Protocol::COM_PING ) );
		fwrite( $stream, MySQL_Protocol::build_packet( 0, "\x03SELECT v" ) . $ping );
		$start = microtime( true );
		$this->receive( $proxy, $client_id );
		$this->assertLessThan( 1, microtime( true ) - $start, 'The proxy does not wait for the client.' );

		$client = $clients->getValue( $proxy )[ $client_id ];
		$this->assertNotSame( '', $client['output'], 'The rest of the result waits for the client.' );
		$this->assertTrue( $client['session']->has_buffered_data(), 'The ping waits behind the result.' );
		$this->assertEqualsWithDelta( $this->now( $proxy ) + MySQL_Proxy::WRITE_TIMEOUT, $client['deadline'], 2 );

		// Once the client reads, it gets the whole result and then the ping response.
		// The result is a column count, a column, an EOF, the rows, and an EOF.
		$packets = $this->read_while_sending( $proxy, $client_id, $stream, 3 + count( $rows ) + 1 + 1 );
		$this->assertSame( "\x01", substr( $packets[0], 4 ) );
		$this->assertSame( 0xfe, ord( $packets[ count( $rows ) + 3 ][4] ) );
		$this->assertSame( "\x07\0\0\x01\0\0\0\x02\0\0\0", end( $packets ) );
		$this->assertEqualsWithDelta( $this->now( $proxy ) + MySQL_Proxy::IDLE_TIMEOUT, $clients->getValue( $proxy )[ $client_id ]['deadline'], 2 );
	}

	public function test_client_that_disconnects_before_accepting_a_response_is_closed(): void {
		$proxy = $this->proxy();
		$pair  = array();
		socket_create_pair( AF_UNIX, SOCK_STREAM, 0, $pair );
		socket_set_nonblock( $pair[1] );
		$clients = $this->property( 'clients' );
		$clients->setValue(
			$proxy,
			array( 1 => $this->client( $pair[1], $this->session( true ), $this->now( $proxy ) + 5, str_repeat( 'x', 1048576 ) ) )
		);

		socket_close( $pair[0] );
		$this->call( $proxy, 'handle_client_writable', 1 );
		$this->assertSame( array(), $clients->getValue( $proxy ) );
	}

	public function test_client_whose_connection_resets_is_closed(): void {
		$proxy  = $this->proxy();
		$port   = $this->listen( $proxy );
		$socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
		socket_connect( $socket, '127.0.0.1', $port );
		$this->call( $proxy, 'handle_new_client' );
		$clients   = $this->property( 'clients' );
		$client_id = key( $clients->getValue( $proxy ) );

		// Closing without lingering resets the connection, so the proxy's read fails.
		socket_set_option(
			$socket,
			SOL_SOCKET,
			SO_LINGER,
			array(
				'l_onoff'  => 1,
				'l_linger' => 0,
			)
		);
		socket_close( $socket );
		$this->receive( $proxy, $client_id );
		$this->assertSame( array(), $clients->getValue( $proxy ) );
	}

	/** @dataProvider connection_counts */
	public function test_connection_limit( int $open, bool $expected_accepted ): void {
		$proxy = $this->proxy();
		$port  = $this->listen( $proxy );

		// Key the occupants away from any object ID the accepted socket can get.
		$clients   = $this->property( 'clients' );
		$occupant  = $this->client( $this->socket(), $this->session( true ), $this->now( $proxy ) + 60 );
		$occupants = array();
		for ( $i = 1; $i <= $open; $i++ ) {
			$occupants[ PHP_INT_MAX - $i ] = $occupant;
		}
		$clients->setValue( $proxy, $occupants );

		$client = stream_socket_client( "tcp://127.0.0.1:$port", $errno, $error, 2 );
		stream_set_timeout( $client, 2 );
		$this->call( $proxy, 'handle_new_client' );
		$response = fread( $client, 256 );

		if ( $expected_accepted ) {
			$this->assertSame( 10, ord( $response[4] ), 'The client gets the handshake packet.' );
			$this->assertCount( $open + 1, $clients->getValue( $proxy ) );
			$accepted = $clients->getValue( $proxy );
			$this->assertGreaterThan( $this->now( $proxy ), end( $accepted )['deadline'] );
		} else {
			// The bytes MySQL sends, without an SQL state before the client's capabilities.
			$this->assertSame( "\x17\0\0\0\xff\x10\x04Too many connections", $response );
			$this->assertCount( $open, $clients->getValue( $proxy ), 'A refused client is not registered.' );
			$this->assertSame( '', fread( $client, 1 ), 'A refused connection is closed.' );
		}

		fclose( $client );
	}

	public function connection_counts(): array {
		return array(
			'below the limit' => array( MySQL_Proxy::MAX_CONNECTIONS - 1, true ),
			'at the limit'    => array( MySQL_Proxy::MAX_CONNECTIONS, false ),
		);
	}

	private function proxy( ?Adapter $adapter = null, string $plugin = MySQL_Protocol::DEFAULT_AUTH_PLUGIN ): MySQL_Proxy {
		return new MySQL_Proxy(
			$adapter ?? $this->createMock( Adapter::class ),
			array( 'authentication' => new MySQL_Authentication( 'user', 'password', $plugin ) )
		);
	}

	/**
	 * Stand in for the listener the proxy creates in start().
	 *
	 * @return int The port the listener is bound to.
	 */
	private function listen( MySQL_Proxy $proxy ): int {
		$listener = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
		socket_bind( $listener, '127.0.0.1', 0 );
		socket_listen( $listener );
		socket_getsockname( $listener, $address, $port );
		$this->property( 'socket' )->setValue( $proxy, $listener );
		return $port;
	}

	/**
	 * Let the proxy handle data from a client once the data has arrived.
	 */
	private function receive( MySQL_Proxy $proxy, int $client_id ): void {
		$read   = array( $this->property( 'clients' )->getValue( $proxy )[ $client_id ]['socket'] );
		$write  = null;
		$except = null;
		$this->assertSame( 1, socket_select( $read, $write, $except, 2 ) );
		$this->call( $proxy, 'handle_client_activity', $client_id );
	}

	/**
	 * Read packets from a client, letting the proxy send more of its pending response.
	 */
	private function read_while_sending( MySQL_Proxy $proxy, int $client_id, $stream, int $count ): array {
		$packets = array();
		$buffer  = '';
		while ( count( $packets ) < $count ) {
			if ( '' !== $this->property( 'clients' )->getValue( $proxy )[ $client_id ]['output'] ) {
				$this->call( $proxy, 'handle_client_writable', $client_id );
			}
			$data = fread( $stream, 1048576 );
			$this->assertNotEmpty( $data, 'The proxy keeps sending.' );
			$buffer .= $data;
			while ( strlen( $buffer ) >= 4 ) {
				$length = unpack( 'V', substr( $buffer, 0, 3 ) . "\0" )[1];
				if ( strlen( $buffer ) < 4 + $length ) {
					break;
				}
				$packets[] = substr( $buffer, 0, 4 + $length );
				$buffer    = (string) substr( $buffer, 4 + $length );
			}
		}
		return $packets;
	}

	/**
	 * The time on the clock the proxy uses for deadlines.
	 */
	private function now( MySQL_Proxy $proxy ): int {
		return $this->call( $proxy, 'now' );
	}

	private function client( $socket, MySQL_Session $session, int $deadline, string $output = '' ): array {
		return array(
			'socket'   => $socket,
			'session'  => $session,
			'deadline' => $deadline,
			'output'   => $output,
			'sent'     => 0,
		);
	}

	private function session( bool $authenticated ): MySQL_Session {
		$session = new MySQL_Session( $this->createMock( Adapter::class ), 1, new MySQL_Authentication( 'user', 'password' ) );
		if ( $authenticated ) {
			$challenge = MySQL_Test_Client::challenge( $session->get_initial_handshake() );
			$session->receive_bytes( MySQL_Test_Client::handshake_response( $challenge ) );
			$this->assertTrue( $session->is_authenticated() );
		}
		return $session;
	}

	private function socket() {
		$pair = array();
		socket_create_pair( AF_UNIX, SOCK_STREAM, 0, $pair );
		return $pair[0];
	}

	private function property( string $name ): ReflectionProperty {
		$property = new ReflectionProperty( MySQL_Proxy::class, $name );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		return $property;
	}

	private function call( MySQL_Proxy $proxy, string $name, ...$arguments ) {
		$method = new ReflectionMethod( MySQL_Proxy::class, $name );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		return $method->invoke( $proxy, ...$arguments );
	}
}
