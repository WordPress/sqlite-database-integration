<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the PostgreSQL connection scaffold.
 */
class WP_PostgreSQL_Connection_Tests extends TestCase {
	/**
	 * Tests PostgreSQL DSN construction from structured options.
	 */
	public function test_build_dsn_from_structured_options(): void {
		$this->assertSame(
			'pgsql:host=localhost;port=5432;dbname=wp',
			WP_PostgreSQL_Connection::build_dsn(
				array(
					'host'   => 'localhost',
					'port'   => 5432,
					'dbname' => 'wp',
				)
			)
		);
	}

	/**
	 * Tests PostgreSQL DSN separator rejection.
	 */
	public function test_build_dsn_rejects_structured_option_separators(): void {
		$this->expectException( InvalidArgumentException::class );
		WP_PostgreSQL_Connection::build_dsn(
			array(
				'host'   => 'local;host',
				'dbname' => 'wp',
			)
		);
	}

	/**
	 * Tests PostgreSQL identifier quoting.
	 */
	public function test_quote_identifier_value_uses_postgresql_double_quotes(): void {
		$this->assertSame(
			'"wp_""posts"',
			WP_PostgreSQL_Connection::quote_identifier_value( 'wp_"posts' )
		);
	}

	/**
	 * Tests PostgreSQL identifier NUL-byte rejection.
	 */
	public function test_quote_identifier_value_rejects_nul_bytes(): void {
		$this->expectException( InvalidArgumentException::class );
		WP_PostgreSQL_Connection::quote_identifier_value( "wp_\0posts" );
	}
}
