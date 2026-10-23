<?php

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use WP_MySQL_Proxy\Adapter\Adapter;
use WP_MySQL_Proxy\MySQL_Proxy;

class WP_MySQL_Proxy_Command_Test extends TestCase {
	/** @dataProvider missing_credentials */
	public function test_library_requires_credentials( array $options ): void {
		$this->expectException( InvalidArgumentException::class );
		new MySQL_Proxy( $this->createMock( Adapter::class ), $options );
	}

	public function missing_credentials(): array {
		return array(
			array( array() ),
			array( array( 'username' => 'user' ) ),
			array( array( 'password' => 'password' ) ),
			array(
				array(
					'username' => 'user',
					'password' => '',
				),
			),
			array(
				array(
					'username' => 'user',
					'password' => false,
				),
			),
			array(
				array(
					'username' => 1,
					'password' => 'password',
				),
			),
			array(
				array(
					'username'    => 'user',
					'password'    => 'password',
					'auth_plugin' => array( 'caching_sha2_password' ),
				),
			),
			array(
				array(
					'username'    => 'user',
					'password'    => 'password',
					'auth_plugin' => 'mysql_clear_password',
				),
			),
		);
	}

	/** @dataProvider invalid_arguments */
	public function test_cli_rejects_invalid_credentials( array $arguments, string $password, string $error ): void {
		$process = $this->cli( $arguments, $password );
		$process->setTimeout( 2 );
		$process->run();
		$this->assertSame( 1, $process->getExitCode() );
		$this->assertStringContainsString( $error, $process->getErrorOutput() );
		$this->assertStringNotContainsString( 'listening', $process->getErrorOutput() );
	}

	public function invalid_arguments(): array {
		return array(
			array( array( '--user=user', '--password-file' ), 'password', 'Invalid command-line options' ),
			array( array( '--user=user', '-uother' ), 'password', 'Invalid command-line options' ),
			array( array( '--user=user', '-x' ), 'password', 'Invalid command-line options' ),
			array( array( '--help=value' ), 'password', 'Invalid command-line options' ),
			array( array( '--user=user', '--port=3306garbage' ), 'password', '--port must be an integer' ),
			array( array( '--user=user', '--password-fil=/missing' ), 'password', 'Invalid command-line options' ),
			array( array( '--user=user', '--bind-address' ), 'password', 'Invalid command-line options' ),
			array( array( '--user=user', 'unexpected-argument' ), 'password', 'Invalid command-line options' ),
			array( array(), 'password', '--user must specify' ),
			array( array( '--user=user' ), '', 'Set a nonempty password' ),
			array( array( '--user=user', '--password-file=/nonexistent/proxy-password' ), 'password', 'must name a readable file' ),
			array( array( '--user=user', '--password-file=' . __DIR__ ), 'password', 'must name a readable file' ),
			array( array( '--user=user', '--user=other' ), 'password', 'Invalid command-line options' ),
			array( array( '--user=user', '--auth-plugin=mysql_clear_password' ), 'password', '--auth-plugin must be one of' ),
			array( array( '--user=user', '--auth-plugin=' ), 'password', '--auth-plugin must be one of' ),
		);
	}

	public function test_help_explains_credentials_and_bind_default(): void {
		$process = $this->cli( array( '--help' ), '' );
		$process->run();
		$this->assertSame( 0, $process->getExitCode() );
		$this->assertStringContainsString( 'WP_MYSQL_PROXY_PASSWORD', $process->getErrorOutput() );
		$this->assertStringContainsString( 'Default: 127.0.0.1', $process->getErrorOutput() );
	}

	/** @dataProvider password_arguments */
	public function test_unknown_options_do_not_expose_their_values( string $argument ): void {
		$process = $this->cli( array( $argument ), '' );
		$process->run();
		$this->assertSame( 1, $process->getExitCode() );
		$this->assertStringNotContainsString( 'private_password', $process->getErrorOutput() );
	}

	public function password_arguments(): array {
		// The MySQL client reads -p as a password, so the proxy must not take it as a port.
		return array( array( '--password=private_password' ), array( '-pprivate_password' ) );
	}

	public function test_grouped_short_options(): void {
		$process = $this->cli( array( '-huuser' ), '' );
		$process->run();
		$this->assertSame( 0, $process->getExitCode() );
		$this->assertStringContainsString( 'Usage:', $process->getErrorOutput() );
	}

	/** @dataProvider password_sources */
	public function test_cli_authenticates_with_configured_password( bool $use_file, string $option_style ): void {
		$listener = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
		$address  = stream_socket_get_name( $listener, false );
		$port     = (int) substr( strrchr( $address, ':' ), 1 );
		fclose( $listener );
		switch ( $option_style ) {
			case 'long-equals':
				$args = array( '--user=user', '--port=' . $port );
				break;
			case 'long-separate':
				$args = array( '--user', 'user', '--port', (string) $port );
				break;
			case 'short-attached':
				$args = array( '-uuser', '-P' . $port, '-linfo', '-d:memory:' );
				break;
			default:
				$args = array( '-u', 'user', '-P', (string) $port, '-l', 'info', '-d', ':memory:' );
		}
		$args[] = '--auth-plugin=' . MySQL_Test_Client::auth_plugin();
		if ( $use_file ) {
			$file = tempnam( sys_get_temp_dir(), 'proxy-password-' );
			chmod( $file, 0600 );
			file_put_contents( $file, "file-password\n" );
			if ( 'long-equals' === $option_style || 'short-attached' === $option_style ) {
				$args[] = '--password-file=' . $file;
			} else {
				$args[] = '--password-file';
				$args[] = $file;
			}
		}
		$process = $this->cli( $args, 'environment-password' );
		$process->setTimeout( 2 );
		try {
			$process->start();
			while ( false === strpos( $process->getErrorOutput(), 'listening on 127.0.0.1:' ) && $process->isRunning() ) {
				$process->checkTimeout();
				usleep( 50000 );
			}
			$this->assertStringContainsString( 'listening on 127.0.0.1:', $process->getErrorOutput() );
			$password = $use_file ? "file-password\n" : 'environment-password';
			$pdo      = new PDO( "mysql:host=127.0.0.1;port=$port", 'user', $password );
			$this->assertEquals( 123, $pdo->query( 'SELECT 123' )->fetchColumn() );
		} finally {
			$process->stop();
			if ( $use_file ) {
				unlink( $file );
			}
		}
	}

	public function password_sources(): array {
		return array(
			array( false, 'long-equals' ),
			array( false, 'long-separate' ),
			array( true, 'short-attached' ),
			array( true, 'short-separate' ),
		);
	}

	/** @dataProvider invalid_password_files */
	public function test_cli_rejects_invalid_password_file( string $contents ): void {
		$file = tempnam( sys_get_temp_dir(), 'proxy-password-' );
		file_put_contents( $file, $contents );
		try {
			$process = $this->cli( array( '--user=user', '--password-file=' . $file ), 'valid-environment-password' );
			$process->run();
			$this->assertSame( 1, $process->getExitCode() );
			$this->assertStringContainsString( 'Set a nonempty password', $process->getErrorOutput() );
		} finally {
			unlink( $file );
		}
	}

	public function invalid_password_files(): array {
		return array( array( '' ), array( "pass\0word" ) );
	}

	private function cli( array $arguments, string $password ): Process {
		return new Process(
			array_merge( array( PHP_BINARY, __DIR__ . '/../bin/wp-mysql-proxy.php' ), $arguments ),
			null,
			array( 'WP_MYSQL_PROXY_PASSWORD' => $password )
		);
	}
}
