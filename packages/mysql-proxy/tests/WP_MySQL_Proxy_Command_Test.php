<?php

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use WP_MySQL_Proxy\Adapter\Adapter;
use WP_MySQL_Proxy\MySQL_Proxy;

class WP_MySQL_Proxy_Command_Test extends TestCase {
	/** @dataProvider missing_accounts */
	public function test_library_requires_an_account( array $options ): void {
		$this->expectException( InvalidArgumentException::class );
		new MySQL_Proxy( $this->createMock( Adapter::class ), $options );
	}

	public function missing_accounts(): array {
		return array(
			'no options'          => array( array() ),
			'credentials options' => array(
				array(
					'username' => 'user',
					'password' => 'password',
				),
			),
			'credentials string'  => array( array( 'authentication' => 'user:password' ) ),
			'credentials array'   => array( array( 'authentication' => array( 'user', 'password' ) ) ),
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
			array( array( '--user=user', '--port=0' ), 'password', '--port must be an integer' ),
			array( array( '--user=user', '--port=65536' ), 'password', '--port must be an integer' ),
			array( array( '--user=user', '--password-fil=/missing' ), 'password', 'Invalid command-line options' ),
			array( array( '--user=user', '--bind-address' ), 'password', 'Invalid command-line options' ),
			array( array( '--user=user', 'unexpected-argument' ), 'password', 'Invalid command-line options' ),
			array( array( '--user=user', '--' ), 'password', 'Invalid command-line options' ),
			array( array( '--user=user', '--port', '--log-level=info' ), 'password', 'Invalid command-line options' ),
			array( array( '--database', '--port=5', '--user=user' ), 'password', 'Invalid command-line options' ),
			array( array( '--user', '--auth-plugin=mysql_native_password' ), 'password', 'Invalid command-line options' ),
			array( array( '-u', '-P', '3306' ), 'password', 'Invalid command-line options' ),
			array( array( '--user=user', '--log-level=-x' ), 'password', '--log-level must be one of' ),
			array( array( '--user=user', '-l-x' ), 'password', '--log-level must be one of' ),
			array( array(), 'password', 'Error: A nonempty username without NUL bytes is required. Use --help for more information.' ),
			array( array( '--user=user' ), '', 'Error: A nonempty password without NUL bytes is required.' ),
			array( array( '--user=user', '--password-file=/nonexistent/proxy-password' ), 'password', 'must name a readable file' ),
			array( array( '--user=user', '--password-file=' . __DIR__ ), 'password', 'must name a readable file' ),
			array( array( '--user=user', '--user=other' ), 'password', 'Invalid command-line options' ),
			array( array( '--user=user', '--auth-plugin=mysql_clear_password' ), 'password', 'Error: The authentication plugin must be one of' ),
			array( array( '--user=user', '--auth-plugin=' ), 'password', 'Error: The authentication plugin must be one of' ),
		);
	}

	public function test_help_explains_credentials_and_bind_default(): void {
		$process = $this->cli( array( '--help' ), '' );
		$process->run();
		$this->assertSame( 0, $process->getExitCode() );
		$this->assertStringContainsString( 'WP_MYSQL_PROXY_PASSWORD', $process->getErrorOutput() );
		$this->assertStringContainsString( 'Default: 127.0.0.1', $process->getErrorOutput() );
		$this->assertStringContainsString( 'Connections are not encrypted (no TLS).', $process->getErrorOutput() );
	}

	public function test_unknown_options_do_not_expose_their_values(): void {
		$process = $this->cli( array( '--password=private_password' ), '' );
		$process->run();
		$this->assertSame( 1, $process->getExitCode() );
		$this->assertStringNotContainsString( 'private_password', $process->getErrorOutput() );
	}

	public function test_grouped_short_options(): void {
		$process = $this->cli( array( '-huuser' ), '' );
		$process->run();
		$this->assertSame( 0, $process->getExitCode() );
		$this->assertStringContainsString( 'Usage:', $process->getErrorOutput() );
	}

	/** @dataProvider password_sources */
	public function test_cli_authenticates_with_configured_password( string $source, string $option_style ): void {
		$port = MySQL_Server_Process::free_port();
		switch ( $option_style ) {
			case 'long-equals':
				$args = array( '--user=user', '--port=' . $port );
				break;
			case 'long-separate':
				$args = array( '--user', 'user', '--port', (string) $port );
				break;
			case 'short-attached':
				$args = array( '-uuser', '-p' . $port, '-linfo', '-d:memory:' );
				break;
			default:
				$args = array( '-u', 'user', '-p', (string) $port, '-l', 'info', '-d', ':memory:' );
		}
		$args[]   = '--auth-plugin=' . MySQL_Test_Client::auth_plugin();
		$password = 'environment-password';
		$input    = null;
		if ( 'file' === $source ) {
			$file = tempnam( sys_get_temp_dir(), 'proxy-password-' );
			chmod( $file, 0600 );
			file_put_contents( $file, "file-password\n" );
			$password = "file-password\n";
			if ( 'long-equals' === $option_style || 'short-attached' === $option_style ) {
				$args[] = '--password-file=' . $file;
			} else {
				$args[] = '--password-file';
				$args[] = $file;
			}
		} elseif ( 'pipe' === $source ) {
			// Like a process substitution, a pipe is not a regular file.
			$args[]   = '--password-file=/dev/stdin';
			$input    = 'pipe-password';
			$password = 'pipe-password';
		} elseif ( 'substitution' === $source ) {
			$password = 'substitution-password';
		}
		$process = 'substitution' === $source
			? $this->cli_with_password_substitution( $args, $password )
			: $this->cli( $args, 'environment-password' );
		$process->setInput( $input );
		$process->setTimeout( 2 );
		try {
			$process->start();
			while ( false === strpos( $process->getErrorOutput(), 'listening on 127.0.0.1:' ) && $process->isRunning() ) {
				$process->checkTimeout();
				usleep( 50000 );
			}
			$this->assertStringContainsString( 'listening on 127.0.0.1:', $process->getErrorOutput() );
			$pdo = new PDO( "mysql:host=127.0.0.1;port=$port", 'user', $password );
			$this->assertEquals( 123, $pdo->query( 'SELECT 123' )->fetchColumn() );
		} finally {
			$process->stop();
			if ( isset( $file ) ) {
				unlink( $file );
			}
		}
	}

	public function password_sources(): array {
		return array(
			array( 'environment', 'long-equals' ),
			array( 'environment', 'long-separate' ),
			array( 'file', 'short-attached' ),
			array( 'file', 'short-separate' ),
			array( 'pipe', 'long-equals' ),
			array( 'substitution', 'long-separate' ),
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
			$this->assertStringContainsString( 'A nonempty password without NUL bytes is required.', $process->getErrorOutput() );
		} finally {
			unlink( $file );
		}
	}

	public function invalid_password_files(): array {
		return array( array( '' ), array( "pass\0word" ) );
	}

	public function test_cli_reports_an_unreadable_password_file(): void {
		// A socket passes the readability check, but cannot be opened like a file.
		$path   = sys_get_temp_dir() . '/proxy-password-' . getmypid() . '.sock';
		$socket = stream_socket_server( 'unix://' . $path );
		try {
			$process = $this->cli( array( '--user=user', '--password-file=' . $path ), 'valid-environment-password' );
			$process->run();
			$this->assertSame( 1, $process->getExitCode() );
			$this->assertSame( "Error: --password-file could not be read.\n", $process->getErrorOutput() );
		} finally {
			fclose( $socket );
			unlink( $path );
		}
	}

	private function cli( array $arguments, string $password ): Process {
		return new Process(
			array_merge( array( PHP_BINARY, __DIR__ . '/../bin/wp-mysql-proxy.php' ), $arguments ),
			null,
			array( 'WP_MYSQL_PROXY_PASSWORD' => $password )
		);
	}

	/**
	 * Run the CLI from bash, which passes a process substitution as a /dev/fd path.
	 */
	private function cli_with_password_substitution( array $arguments, string $password ): Process {
		$command = 'exec "$@" --password-file=<(printf %s ' . escapeshellarg( $password ) . ')';
		return new Process(
			array_merge( array( 'bash', '-c', $command, 'bash', PHP_BINARY, __DIR__ . '/../bin/wp-mysql-proxy.php' ), $arguments ),
			null,
			array( 'WP_MYSQL_PROXY_PASSWORD' => 'environment-password' )
		);
	}
}
