<?php

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use WP_MySQL_Proxy\Adapter\Adapter;
use WP_MySQL_Proxy\MySQL_Authentication;
use WP_MySQL_Proxy\MySQL_Proxy;

class WP_MySQL_Proxy_Options_Test extends TestCase {
	/** @dataProvider bind_addresses */
	public function test_bind_address( array $options, string $expected ): void {
		$proxy    = new MySQL_Proxy(
			$this->createMock( Adapter::class ),
			$options + array( 'authentication' => new MySQL_Authentication( 'user', 'password' ) )
		);
		$property = new ReflectionProperty( MySQL_Proxy::class, 'bind_address' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$this->assertSame( $expected, $property->getValue( $proxy ) );
	}

	public function bind_addresses(): array {
		return array(
			'default'       => array( array(), '127.0.0.1' ),
			'IPv4 loopback' => array( array( 'bind_address' => '127.0.0.1' ), '127.0.0.1' ),
			'IPv4 all'      => array( array( 'bind_address' => '0.0.0.0' ), '0.0.0.0' ),
			'IPv6 loopback' => array( array( 'bind_address' => '::1' ), '::1' ),
			'IPv6 all'      => array( array( 'bind_address' => '::' ), '::' ),
		);
	}

	/** @dataProvider invalid_bind_addresses */
	public function test_invalid_bind_address( $address ): void {
		$this->expectException( InvalidArgumentException::class );
		new MySQL_Proxy(
			$this->createMock( Adapter::class ),
			array(
				'bind_address'   => $address,
				'authentication' => new MySQL_Authentication( 'user', 'password' ),
			)
		);
	}

	public function invalid_bind_addresses(): array {
		return array( array( '' ), array( 'localhost' ), array( '256.0.0.1' ), array( '::g' ), array( array() ), array( false ) );
	}

	public function test_cli_rejects_invalid_bind_address(): void {
		$process = new Process( array( PHP_BINARY, __DIR__ . '/../bin/wp-mysql-proxy.php', '--bind-address=localhost' ) );
		$process->run();
		$this->assertSame( 1, $process->getExitCode() );
		$this->assertStringContainsString( '--bind-address must be a valid IPv4 or IPv6 address', $process->getErrorOutput() );
	}
}
