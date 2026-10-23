<?php

use PHPUnit\Framework\TestCase;
use WP_MySQL_Proxy\Adapter\Adapter;
use WP_MySQL_Proxy\MySQL_Authentication;
use WP_MySQL_Proxy\MySQL_Protocol;
use WP_MySQL_Proxy\MySQL_Proxy;
use WP_MySQL_Proxy\MySQL_Session;

class WP_MySQL_Proxy_Limits_Test extends TestCase {
	/** @dataProvider deadlines */
	public function test_deadline( int $deadline_offset, bool $authenticated, bool $expected_open, int $expected_timeout ): void {
		$proxy   = new MySQL_Proxy(
			$this->createMock( Adapter::class ),
			array(
				'username' => 'user',
				'password' => 'password',
			)
		);
		$clients = $this->property( 'clients' );
		$clients->setValue(
			$proxy,
			array(
				1 => array(
					'socket'   => $this->socket(),
					'session'  => $this->session( $authenticated ),
					'deadline' => time() + $deadline_offset,
				),
			)
		);

		$this->assertSame( $expected_timeout, $this->call( $proxy, 'get_select_timeout' ) );
		$this->call( $proxy, 'close_timed_out_clients' );
		$this->assertCount( $expected_open ? 1 : 0, $clients->getValue( $proxy ) );
	}

	public function deadlines(): array {
		return array(
			'login within the deadline' => array( 5, false, true, 5 ),
			'login past the deadline'   => array( -1, false, false, 0 ),
			'idle within the deadline'  => array( 5, true, true, 5 ),
			'idle past the deadline'    => array( -1, true, false, 0 ),
		);
	}

	public function test_select_waits_indefinitely_without_clients(): void {
		$proxy = new MySQL_Proxy(
			$this->createMock( Adapter::class ),
			array(
				'username' => 'user',
				'password' => 'password',
			)
		);
		$this->assertNull( $this->call( $proxy, 'get_select_timeout' ) );
	}

	/** @dataProvider client_activity */
	public function test_activity_restarts_only_the_idle_clock( bool $authenticated, string $request, int $expected_deadline ): void {
		$proxy = new MySQL_Proxy(
			$this->createMock( Adapter::class ),
			array(
				'username' => 'user',
				'password' => 'password',
			)
		);
		$pair  = array();
		socket_create_pair( AF_UNIX, SOCK_STREAM, 0, $pair );
		$this->property( 'clients' )->setValue(
			$proxy,
			array(
				1 => array(
					'socket'   => $pair[1],
					'session'  => $this->session( $authenticated ),
					'deadline' => time() + 5,
				),
			)
		);

		socket_write( $pair[0], $request );
		$this->call( $proxy, 'handle_client_activity', 1 );

		$deadline = $this->property( 'clients' )->getValue( $proxy )[1]['deadline'];
		$this->assertEqualsWithDelta( time() + $expected_deadline, $deadline, 2 );
	}

	public function client_activity(): array {
		$ping = MySQL_Protocol::build_packet( 0, chr( MySQL_Protocol::COM_PING ) );
		return array(
			'logged-in client sends a command' => array( true, $ping, MySQL_Proxy::IDLE_TIMEOUT ),
			'client sends part of its login'   => array( false, "\x40\0\0\x01partial", 5 ),
		);
	}

	/** @dataProvider connection_counts */
	public function test_connection_limit( int $open, bool $expected_accepted ): void {
		$port  = $this->free_port();
		$proxy = new MySQL_Proxy(
			$this->createMock( Adapter::class ),
			array(
				'username' => 'user',
				'password' => 'password',
				'port'     => $port,
			)
		);

		// Stand in for the listener the proxy creates in start().
		$listener = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
		socket_set_option( $listener, SOL_SOCKET, SO_REUSEADDR, 1 );
		socket_bind( $listener, '127.0.0.1', $port );
		socket_listen( $listener );
		$this->property( 'socket' )->setValue( $proxy, $listener );

		$clients  = $this->property( 'clients' );
		$occupant = array(
			'socket'   => $this->socket(),
			'session'  => $this->session( true ),
			'deadline' => time() + 60,
		);

		// Key the occupants away from any object ID the accepted socket can get.
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
			$this->assertGreaterThan( time(), end( $accepted )['deadline'] );
		} else {
			$this->assertSame( 0xff, ord( $response[4] ) );
			$this->assertSame( 1040, unpack( 'v', substr( $response, 5, 2 ) )[1] );
			$this->assertSame( 'Too many connections', substr( $response, 13 ) );
			$this->assertCount( $open, $clients->getValue( $proxy ), 'A refused client is not registered.' );
			$this->assertSame( '', fread( $client, 1 ), 'A refused connection is closed.' );
		}

		fclose( $client );
		socket_close( $listener );
	}

	public function connection_counts(): array {
		return array(
			'below the limit' => array( MySQL_Proxy::MAX_CONNECTIONS - 1, true ),
			'at the limit'    => array( MySQL_Proxy::MAX_CONNECTIONS, false ),
		);
	}

	private function free_port(): int {
		$listener = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
		$port     = (int) substr( strrchr( stream_socket_get_name( $listener, false ), ':' ), 1 );
		fclose( $listener );
		return $port;
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
