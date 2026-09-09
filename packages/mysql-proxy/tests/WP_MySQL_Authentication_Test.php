<?php

use PHPUnit\Framework\TestCase;
use WP_MySQL_Proxy\MySQL_Authentication;
use WP_MySQL_Proxy\MySQL_Protocol;

class WP_MySQL_Authentication_Test extends TestCase {
	/** @dataProvider known_responses */
	public function test_known_response( string $plugin, string $hex ): void {
		$authentication = new MySQL_Authentication( 'user', 'password', $plugin );
		$response       = hex2bin( $hex );
		$challenge      = '12345678901234567890';
		$this->assertTrue( $authentication->verify( 'user', $plugin, $response, $challenge ) );
		$this->assertFalse( $authentication->verify( 'other', $plugin, $response, $challenge ) );
		$this->assertFalse( $authentication->verify( 'User', $plugin, $response, $challenge ) );
		$this->assertFalse( $authentication->verify( 'user', $plugin, $response, strrev( $challenge ) ) );
		$this->assertFalse( $authentication->verify( 'user', $plugin, $response, '' ) );
		$this->assertFalse( $authentication->verify( 'user', $plugin, $response . "\0", $challenge ) );
		$this->assertFalse( $authentication->verify( 'user', $plugin, substr( $response, 1 ), $challenge ) );
		$this->assertFalse( $authentication->verify( 'user', $plugin, '', $challenge ) );
		$this->assertFalse( $authentication->verify( 'user', $plugin, "\0", $challenge ) );
		$response[0] = $response[0] ^ "\x01";
		$this->assertFalse( $authentication->verify( 'user', $plugin, $response, $challenge ) );
	}

	public function known_responses(): array {
		// Fixed vectors calculated independently with Python's hashlib.
		return array(
			array( MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD, '718d80fb92b693f7ee1fcad95bfe7a2be1f332ac2c3ec6cdc85893b65b240650' ),
			array( MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD, '1957dce2724282e018f40d905824cb6361f88d41' ),
		);
	}

	/** @dataProvider account_plugins */
	public function test_only_the_account_plugin_is_accepted( string $accepted, string $rejected ): void {
		$authentication = new MySQL_Authentication( 'user', 'password', $accepted );
		$challenge      = '12345678901234567890';
		$this->assertSame( $accepted, $authentication->get_plugin() );
		$this->assertTrue( $authentication->verify( 'user', $accepted, $this->response( $accepted, $challenge ), $challenge ) );
		$this->assertFalse( $authentication->verify( 'user', $rejected, $this->response( $rejected, $challenge ), $challenge ) );
	}

	public function account_plugins(): array {
		$sha2   = MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD;
		$native = MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD;
		return array(
			'caching_sha2_password' => array( $sha2, $native ),
			'mysql_native_password' => array( $native, $sha2 ),
		);
	}

	public function test_caching_sha2_password_is_the_default(): void {
		$authentication = new MySQL_Authentication( 'user', 'password' );
		$this->assertSame( MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD, $authentication->get_plugin() );
	}

	/** @dataProvider invalid_plugins */
	public function test_invalid_plugin_is_rejected( string $plugin ): void {
		$this->expectException( InvalidArgumentException::class );
		new MySQL_Authentication( 'user', 'password', $plugin );
	}

	public function invalid_plugins(): array {
		return array(
			'empty'   => array( '' ),
			'unknown' => array( 'mysql_clear_password' ),
			'sha256'  => array( 'sha256_password' ),
		);
	}

	private function response( string $plugin, string $challenge ): string {
		$algorithm = MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD === $plugin ? 'sha1' : 'sha256';
		$stage1    = hash( $algorithm, 'password', true );
		$stage2    = hash( $algorithm, $stage1, true );
		$input     = 'sha1' === $algorithm ? $challenge . $stage2 : $stage2 . $challenge;
		return $stage1 ^ hash( $algorithm, $input, true );
	}

	public function test_unknown_plugin_is_denied(): void {
		$authentication = new MySQL_Authentication( 'user', 'password' );
		$this->assertFalse( $authentication->verify( 'user', 'mysql_clear_password', 'password', '12345678901234567890' ) );
	}

	/** @dataProvider invalid_credentials */
	public function test_invalid_credentials( string $username, string $password ): void {
		$this->expectException( InvalidArgumentException::class );
		new MySQL_Authentication( $username, $password );
	}

	public function invalid_credentials(): array {
		return array( array( '', 'password' ), array( 'user', '' ), array( "user\0", 'password' ), array( 'user', "pass\0word" ) );
	}
}
