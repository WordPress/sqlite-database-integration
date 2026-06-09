<?php

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;

abstract class WP_MySQL_Proxy_Test extends TestCase {
	/** @var int */
	protected $port = 3306;

	/** @var MySQL_Server_Process */
	protected $server;

	public function setUp(): void {
		$this->skip_if_missing_test_client();

		$this->server = new MySQL_Server_Process(
			array(
				'port'    => $this->port,
				'db_path' => ':memory:',
			)
		);
	}

	public function tearDown(): void {
		if ( ! $this->server instanceof MySQL_Server_Process ) {
			return;
		}

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

	private function skip_if_missing_test_client(): void {
		switch ( static::class ) {
			case WP_MySQL_Proxy_CLI_Test::class:
				if ( null === ( new ExecutableFinder() )->find( 'mysql' ) ) {
					$this->markTestSkipped( 'The mysql CLI client is not available.' );
				}
				break;

			case WP_MySQL_Proxy_MySQLi_Test::class:
				if ( ! class_exists( 'mysqli' ) ) {
					$this->markTestSkipped( 'The mysqli extension is not available.' );
				}
				break;

			case WP_MySQL_Proxy_PDO_Test::class:
				if ( ! class_exists( 'PDO' ) || ! in_array( 'mysql', PDO::getAvailableDrivers(), true ) ) {
					$this->markTestSkipped( 'The pdo_mysql extension is not available.' );
				}
				break;
		}
	}
}
