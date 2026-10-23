<?php

use PHPUnit\Framework\TestCase;
use WP_MySQL_Proxy\MySQL_Protocol;
use WP_MySQL_Proxy\MySQL_Proxy_Exception;

class WP_MySQL_Protocol_Authentication_Test extends TestCase {
	/** @dataProvider encoded_lengths */
	public function test_length_encoded_integer( string $bytes, int $expected ): void {
		$offset = 1;
		$this->assertSame( $expected, MySQL_Protocol::read_length_encoded_int( 'x' . $bytes . 'suffix', $offset ) );
		$this->assertSame( 1 + strlen( $bytes ), $offset );
	}

	public function encoded_lengths(): array {
		return array(
			array( "\0", 0 ),
			array( "\xfa", 250 ),
			array( "\xfb", 0 ),
			array( "\xfc\x34\x12", 0x1234 ),
			array( "\xfd\x56\x34\x12", 0x123456 ),
			array( "\xfe\x56\x34\x12\0\0\0\0\0", 0x123456 ),
		);
	}

	/** @dataProvider string_lengths */
	public function test_length_encoded_string( string $prefix ): void {
		$offset = 0;
		$this->assertSame( 'abc', MySQL_Protocol::read_length_encoded_string( $prefix . 'abc', $offset ) );
		$this->assertSame( strlen( $prefix ) + 3, $offset );
	}

	public function string_lengths(): array {
		return array( array( "\x03" ), array( "\xfc\x03\0" ), array( "\xfd\x03\0\0" ), array( "\xfe\x03\0\0\0\0\0\0\0" ) );
	}

	/** @dataProvider malformed_lengths */
	public function test_malformed_length_is_rejected( string $bytes ): void {
		$offset = 0;
		$this->expectException( MySQL_Proxy_Exception::class );
		MySQL_Protocol::read_length_encoded_int( $bytes, $offset );
	}

	public function malformed_lengths(): array {
		return array(
			array( '' ),
			array( "\xfc" ),
			array( "\xfc\0" ),
			array( "\xfd\0\0" ),
			array( "\xfe\0\0\0\0\0\0\0" ),
			array( "\xfe\xff\xff\xff\xff\xff\xff\xff\xff" ),
			array( "\xff" ),
		);
	}

	public function test_truncated_string_is_rejected(): void {
		$offset = 0;
		$this->expectException( MySQL_Proxy_Exception::class );
		MySQL_Protocol::read_length_encoded_string( "\x04abc", $offset );
	}

	public function test_null_terminated_string_preserves_spaces(): void {
		$offset = 1;
		$this->assertSame( 'user ', MySQL_Protocol::read_null_terminated_string( "xuser \0rest", $offset ) );
		$this->assertSame( 7, $offset );
	}

	public function test_missing_string_terminator_is_rejected(): void {
		$offset = 0;
		$this->expectException( MySQL_Proxy_Exception::class );
		MySQL_Protocol::read_null_terminated_string( 'user', $offset );
	}
}
