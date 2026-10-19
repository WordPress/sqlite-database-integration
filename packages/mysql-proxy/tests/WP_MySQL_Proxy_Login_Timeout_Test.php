<?php

use PHPUnit\Framework\TestCase;
use WP_MySQL_Proxy\Adapter\Adapter;
use WP_MySQL_Proxy\MySQL_Authentication;
use WP_MySQL_Proxy\MySQL_Proxy;
use WP_MySQL_Proxy\MySQL_Session;

class WP_MySQL_Proxy_Login_Timeout_Test extends TestCase {
	/** @dataProvider pending_logins */
	public function test_login_deadline( int $deadline_offset, bool $authenticated, bool $expected_open, ?int $expected_timeout ): void {
		$proxy   = new MySQL_Proxy(
			$this->createMock( Adapter::class ),
			array( 'authentication' => new MySQL_Authentication( 'user', 'password' ) )
		);
		$clients = $this->property( 'clients' );
		$clients->setValue(
			$proxy,
			array(
				1 => array(
					'socket'         => $this->socket(),
					'session'        => $this->session( $authenticated ),
					'login_deadline' => time() + $deadline_offset,
				),
			)
		);

		$this->assertSame( $expected_timeout, $this->call( $proxy, 'get_select_timeout' ) );
		$this->call( $proxy, 'close_timed_out_logins' );
		$this->assertCount( $expected_open ? 1 : 0, $clients->getValue( $proxy ) );
	}

	public function pending_logins(): array {
		return array(
			'pending login within the deadline' => array( 5, false, true, 5 ),
			'pending login past the deadline'   => array( -1, false, false, 0 ),
			'authenticated past the deadline'   => array( -1, true, true, null ),
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

	private function call( MySQL_Proxy $proxy, string $name ) {
		$method = new ReflectionMethod( MySQL_Proxy::class, $name );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		return $method->invoke( $proxy );
	}
}
