<?php

use PHPUnit\Framework\TestCase;
use WP_MySQL_Proxy\Adapter\SQLite_Adapter;
use WP_MySQL_Proxy\MySQL_Authentication;
use WP_MySQL_Proxy\MySQL_Protocol;
use WP_MySQL_Proxy\MySQL_Session;

class WP_MySQL_SQLite_Adapter_Test extends TestCase {
	public function test_query_error_is_reported(): void {
		$result = ( new SQLite_Adapter( ':memory:' ) )->handle_query( 'SELECT * FROM missing_table' );
		$this->assertSame( array( '42S02', 1146, "Table 'missing_table' doesn't exist" ), $result->error_info );
	}

	public function test_driver_message_is_reported(): void {
		$cause  = new RuntimeException( 'Unsupported ALTER TABLE ADD action: X' );
		$result = $this->adapter_failing_with( $cause )->handle_query( 'SELECT 1' );
		$this->assertSame( array( 'HY000', 1105, 'Unsupported ALTER TABLE ADD action: X' ), $result->error_info );
	}

	/** @dataProvider internal_errors */
	public function test_internal_error_is_not_reported( Throwable $cause ): void {
		$this->expectException( WP_MySQL_On_SQLite_Exception::class );
		$this->adapter_failing_with( $cause )->handle_query( 'SELECT 1' );
	}

	public function internal_errors(): array {
		return array(
			'PHP error'         => array( new TypeError( 'Private detail from /srv/secret/path.php' ) ),
			'wrapped PHP error' => array( new RuntimeException( 'Wrapper', 0, new TypeError( 'Private detail' ) ) ),
		);
	}

	public function test_internal_error_does_not_reach_the_client(): void {
		$cause     = new TypeError( 'f(): Argument #1 must be of type string, called in /srv/secret/path.php on line 1' );
		$session   = new MySQL_Session( $this->adapter_failing_with( $cause ), 1, new MySQL_Authentication( 'user', 'password' ) );
		$challenge = MySQL_Test_Client::challenge( $session->get_initial_handshake() );
		$session->receive_bytes( MySQL_Test_Client::handshake_response( $challenge ) );
		$this->assertTrue( $session->is_authenticated() );

		// The detail goes to the log, which the test runner shows on stderr.
		$response = $session->receive_bytes( MySQL_Protocol::build_packet( 0, chr( MySQL_Protocol::COM_QUERY ) . 'SELECT 1' ) );
		$this->assertSame( 1105, unpack( 'v', substr( $response, 5, 2 ) )[1] );
		$this->assertSame( 'Unknown error', substr( $response, 13 ) );
		$this->assertStringNotContainsString( '/srv/secret/path.php', $response );
	}

	/**
	 * An adapter whose driver fails a query the way the driver reports any error.
	 */
	private function adapter_failing_with( Throwable $cause ): SQLite_Adapter {
		$adapter  = new SQLite_Adapter( ':memory:' );
		$property = new ReflectionProperty( SQLite_Adapter::class, 'sqlite_driver' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$exception = new WP_MySQL_On_SQLite_Exception( $property->getValue( $adapter ), $cause->getMessage(), $cause->getCode(), $cause );
		$property->setValue(
			$adapter,
			new class( $exception ) {
				private $exception;

				public function __construct( Throwable $exception ) {
					$this->exception = $exception;
				}

				public function query(): void {
					throw $this->exception;
				}
			}
		);
		return $adapter;
	}
}
