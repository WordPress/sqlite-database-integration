<?php

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use WP_MySQL_Proxy\Adapter\Adapter;
use WP_MySQL_Proxy\MySQL_Proxy;

class WP_MySQL_Proxy_Options_Test extends TestCase {
	public function test_default_bind_address_is_loopback(): void {
		$proxy    = new MySQL_Proxy( $this->createMock( Adapter::class ) );
		$property = new ReflectionProperty( MySQL_Proxy::class, 'bind_address' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$this->assertSame( '127.0.0.1', $property->getValue( $proxy ) );
	}

	/** @dataProvider bind_addresses */
	public function test_explicit_bind_address( string $address ): void {
		$proxy    = new MySQL_Proxy( $this->createMock( Adapter::class ), array( 'bind_address' => $address ) );
		$property = new ReflectionProperty( MySQL_Proxy::class, 'bind_address' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$this->assertSame( $address, $property->getValue( $proxy ) );
	}

	public function bind_addresses(): array {
		return array( array( '127.0.0.1' ), array( '0.0.0.0' ), array( '::1' ), array( '::' ) );
	}

	/** @dataProvider invalid_bind_addresses */
	public function test_invalid_bind_address( $address ): void {
		$this->expectException( InvalidArgumentException::class );
		new MySQL_Proxy( $this->createMock( Adapter::class ), array( 'bind_address' => $address ) );
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
