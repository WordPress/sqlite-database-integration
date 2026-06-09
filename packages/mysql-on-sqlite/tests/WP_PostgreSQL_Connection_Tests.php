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
	 * Tests PostgreSQL DSN construction does not include credentials.
	 */
	public function test_build_dsn_keeps_credentials_out_of_structured_dsn(): void {
		$this->assertSame(
			'pgsql:host=localhost;port=5432;dbname=wp',
			WP_PostgreSQL_Connection::build_dsn(
				array(
					'host'     => 'localhost',
					'port'     => 5432,
					'dbname'   => 'wp',
					'user'     => 'wp_user',
					'password' => 'secret',
				)
			)
		);
	}

	/**
	 * Tests PostgreSQL DSN construction requires a database name.
	 *
	 * @dataProvider data_missing_dbname_options
	 *
	 * @param array $options Connection options.
	 */
	public function test_build_dsn_requires_non_empty_dbname( array $options ): void {
		$this->expectException( InvalidArgumentException::class );
		WP_PostgreSQL_Connection::build_dsn( $options );
	}

	/**
	 * Data provider for missing database-name options.
	 *
	 * @return array
	 */
	public function data_missing_dbname_options(): array {
		return array(
			'not set' => array( array() ),
			'empty'   => array( array( 'dbname' => '' ) ),
			'null'    => array( array( 'dbname' => null ) ),
		);
	}

	/**
	 * Tests empty host and port values are omitted.
	 */
	public function test_build_dsn_omits_empty_host_and_port(): void {
		$this->assertSame(
			'pgsql:dbname=wp',
			WP_PostgreSQL_Connection::build_dsn(
				array(
					'host'   => '',
					'port'   => '',
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
	 * Tests PostgreSQL DSN NUL-byte rejection.
	 *
	 * @dataProvider data_nul_byte_dsn_options
	 *
	 * @param array $options Connection options.
	 */
	public function test_build_dsn_rejects_nul_bytes_in_structured_options( array $options ): void {
		$this->expectException( InvalidArgumentException::class );
		WP_PostgreSQL_Connection::build_dsn( $options );
	}

	/**
	 * Data provider for NUL-containing DSN options.
	 *
	 * @return array
	 */
	public function data_nul_byte_dsn_options(): array {
		return array(
			'host'   => array(
				array(
					'host'   => "local\0host",
					'dbname' => 'wp',
				),
			),
			'port'   => array(
				array(
					'port'   => "5432\0",
					'dbname' => 'wp',
				),
			),
			'dbname' => array(
				array(
					'dbname' => "w\0p",
				),
			),
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

	/**
	 * Tests injected PDO instances are configured and reused.
	 */
	public function test_constructor_uses_injected_pdo_and_sets_exception_mode(): void {
		$pdo        = new PDO( 'sqlite::memory:' );
		$connection = new WP_PostgreSQL_Connection( array( 'pdo' => $pdo ) );

		$this->assertSame( $pdo, $connection->get_pdo() );
		$this->assertSame( PDO::ERRMODE_EXCEPTION, $pdo->getAttribute( PDO::ATTR_ERRMODE ) );
	}

	/**
	 * Tests query execution with parameters and query logging.
	 */
	public function test_query_executes_parameters_and_logs_query(): void {
		$connection = new WP_PostgreSQL_Connection( array( 'pdo' => new PDO( 'sqlite::memory:' ) ) );
		$log        = array();
		$connection->set_query_logger(
			function ( string $sql, array $params ) use ( &$log ): void {
				$log[] = array( $sql, $params );
			}
		);

		$stmt = $connection->query( 'SELECT ? AS value', array( 'ok' ) );

		$this->assertSame( array( 'value' => 'ok' ), $stmt->fetch( PDO::FETCH_ASSOC ) );
		$this->assertSame( array( array( 'SELECT ? AS value', array( 'ok' ) ) ), $log );
	}

	/**
	 * Tests prepare returns a PDO statement and logs without parameters.
	 */
	public function test_prepare_returns_statement_and_logs_without_params(): void {
		$connection = new WP_PostgreSQL_Connection( array( 'pdo' => new PDO( 'sqlite::memory:' ) ) );
		$log        = array();
		$connection->set_query_logger(
			function ( string $sql, array $params ) use ( &$log ): void {
				$log[] = array( $sql, $params );
			}
		);

		$stmt = $connection->prepare( 'SELECT ? AS value' );
		$stmt->execute( array( 'ok' ) );

		$this->assertInstanceOf( PDOStatement::class, $stmt );
		$this->assertSame( array( 'value' => 'ok' ), $stmt->fetch( PDO::FETCH_ASSOC ) );
		$this->assertSame( array( array( 'SELECT ? AS value', array() ) ), $log );
	}

	/**
	 * Tests last insert ID delegates to the injected PDO.
	 */
	public function test_get_last_insert_id_delegates_to_injected_pdo_default_sequence(): void {
		$pdo        = new PDO( 'sqlite::memory:' );
		$connection = new WP_PostgreSQL_Connection( array( 'pdo' => $pdo ) );

		$pdo->exec( 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)' );
		$pdo->exec( "INSERT INTO t (value) VALUES ('first')" );

		$this->assertSame( '1', $connection->get_last_insert_id() );
	}

	/**
	 * Tests value quoting delegates to the injected PDO.
	 */
	public function test_quote_delegates_to_injected_pdo(): void {
		$pdo        = new PDO( 'sqlite::memory:' );
		$connection = new WP_PostgreSQL_Connection( array( 'pdo' => $pdo ) );

		$this->assertSame( $pdo->quote( "O'Reilly" ), $connection->quote( "O'Reilly" ) );
	}
}
