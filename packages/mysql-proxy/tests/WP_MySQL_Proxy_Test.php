<?php

use PHPUnit\Framework\TestCase;

abstract class WP_MySQL_Proxy_Test extends TestCase {
	/** @var int */
	protected $port;

	/** @var string */
	protected $log_level = 'error';

	/** @var MySQL_Server_Process */
	protected $server;

	public function setUp(): void {
		// Keep the tests away from any local MySQL server on its default port.
		$listener   = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
		$address    = stream_socket_get_name( $listener, false );
		$this->port = (int) substr( strrchr( $address, ':' ), 1 );
		fclose( $listener );

		$this->server = new MySQL_Server_Process(
			array(
				'port'        => $this->port,
				'db_path'     => ':memory:',
				'log_level'   => $this->log_level,
				'auth_plugin' => MySQL_Test_Client::auth_plugin(),
			)
		);
	}

	public function tearDown(): void {
		$this->server->stop();
		$exit_code = $this->server->get_exit_code();
		if ( $this->hasFailed() || ( $exit_code > 0 && 143 !== $exit_code ) ) {
			$hr = str_repeat( '-', 80 );
			fprintf(
				STDERR,
				"\n\n$hr\nSERVER OUTPUT:\n$hr\n[RETURN CODE]: %d\n\n[STDOUT]:\n%s\n\n[STDERR]:\n%s\n$hr\n",
				$this->server->get_exit_code(),
				$this->server->get_stdout(),
				$this->server->get_stderr()
			);
		}
	}
}
