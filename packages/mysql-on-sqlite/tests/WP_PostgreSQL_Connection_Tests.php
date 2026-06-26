<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WP_PostgreSQL_Connection_Statement_Savepoint_Recording_PDO.php';

/**
 * Unit tests for the PostgreSQL connection scaffold.
 */
class WP_PostgreSQL_Connection_Tests extends TestCase {
	/**
	 * Real PostgreSQL schemas created for the current test.
	 *
	 * @var array<int,array{pdo:PDO,schema:string}>
	 */
	private $real_pgsql_test_schemas = array();

	/**
	 * Drop isolated real PostgreSQL schemas created during the test.
	 */
	protected function tearDown(): void {
		foreach ( array_reverse( $this->real_pgsql_test_schemas ) as $cleanup ) {
			try {
				$pdo = $cleanup['pdo'];
				if ( $pdo->inTransaction() ) {
					$pdo->rollBack();
				}

				$pdo->exec(
					'DROP SCHEMA IF EXISTS ' .
					WP_PostgreSQL_Connection::quote_identifier_value( $cleanup['schema'] ) .
					' CASCADE'
				);
			} catch ( Throwable $e ) {
				// Cleanup should not mask the test result.
			}
		}

		$this->real_pgsql_test_schemas = array();
		parent::tearDown();
	}

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
	 * Tests PostgreSQL DSN construction preserves socket-style host paths.
	 */
	public function test_build_dsn_preserves_socket_style_host_paths(): void {
		$this->assertSame(
			'pgsql:host=/var/run/postgresql;dbname=wp',
			WP_PostgreSQL_Connection::build_dsn(
				array(
					'host'   => '/var/run/postgresql',
					'dbname' => 'wp',
				)
			)
		);

		$this->assertSame(
			'pgsql:host=/tmp/.s.PGSQL.5432;port=5432;dbname=wp',
			WP_PostgreSQL_Connection::build_dsn(
				array(
					'host'   => '/tmp/.s.PGSQL.5432',
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
		$pdo        = $this->create_real_pgsql_pdo();
		$connection = new WP_PostgreSQL_Connection( array( 'pdo' => $pdo ) );

		$this->assertSame( $pdo, $connection->get_pdo() );
		$this->assertSame( PDO::ERRMODE_EXCEPTION, $pdo->getAttribute( PDO::ATTR_ERRMODE ) );
	}

	/**
	 * Tests query execution with parameters and query logging.
	 */
	public function test_query_executes_parameters_and_logs_query(): void {
		$connection = new WP_PostgreSQL_Connection( array( 'pdo' => $this->create_real_pgsql_pdo() ) );
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
	 * Tests failed statements are isolated from the active PostgreSQL transaction.
	 */
	public function test_query_rolls_back_failed_postgresql_statement_to_transaction_savepoint(): void {
		$pdo        = new WP_PostgreSQL_Connection_Statement_Savepoint_Recording_PDO( $this->create_real_pgsql_pdo() );
		$connection = $this->create_connection_with_recording_pdo( $pdo );

		$pdo->beginTransaction();
		$connection->query( 'CREATE TABLE t (id INTEGER PRIMARY KEY, value TEXT)' );
		$connection->query( "INSERT INTO t (id, value) VALUES (1, 'ok')" );

		try {
			$connection->query( 'INSERT INTO missing_table (id) VALUES (1)' );
			$this->fail( 'Expected the invalid statement to throw.' );
		} catch ( PDOException $exception ) {
			$this->assertStringContainsString( 'missing_table', $exception->getMessage() );
		}

		$stmt = $connection->query( 'SELECT value FROM t WHERE id = 1' );

		$this->assertSame( 'ok', $stmt->fetchColumn() );
		$pdo->rollBack();
		$this->assertSame(
			array(
				'SAVEPOINT wp_statement_1',
				'RELEASE SAVEPOINT wp_statement_1',
				'SAVEPOINT wp_statement_2',
				'RELEASE SAVEPOINT wp_statement_2',
				'SAVEPOINT wp_statement_3',
				'ROLLBACK TO SAVEPOINT wp_statement_3',
				'RELEASE SAVEPOINT wp_statement_3',
				'SAVEPOINT wp_statement_4',
			),
			$pdo->exec_sql
		);
	}

	/**
	 * Tests consecutive plain SELECT statements reuse one generated read savepoint.
	 */
	public function test_query_reuses_read_savepoint_for_consecutive_plain_select_statements(): void {
		$pdo        = new WP_PostgreSQL_Connection_Statement_Savepoint_Recording_PDO( $this->create_real_pgsql_pdo() );
		$connection = $this->create_connection_with_recording_pdo( $pdo );

		$pdo->beginTransaction();
		$first  = $connection->query( 'SELECT 1 AS value' );
		$second = $connection->query( 'SELECT 2 AS value' );
		$connection->query( 'CREATE TABLE t (id INTEGER)' );

		$this->assertSame( '1', $first->fetchColumn() );
		$this->assertSame( '2', $second->fetchColumn() );
		$this->assertSame(
			array( 'SELECT 1 AS value', 'SELECT 2 AS value', 'CREATE TABLE t (id INTEGER)' ),
			$pdo->prepared_sql
		);
		$this->assertSame(
			array(
				'SAVEPOINT wp_statement_1',
				'RELEASE SAVEPOINT wp_statement_1',
			),
			$pdo->exec_sql
		);
		$pdo->rollBack();
	}

	/**
	 * Tests failed read statements are isolated from the active PostgreSQL transaction.
	 */
	public function test_query_rolls_back_failed_read_to_shared_savepoint(): void {
		$pdo        = new WP_PostgreSQL_Connection_Statement_Savepoint_Recording_PDO( $this->create_real_pgsql_pdo() );
		$connection = $this->create_connection_with_recording_pdo( $pdo );

		$pdo->beginTransaction();
		$connection->query( 'CREATE TABLE t (id INTEGER PRIMARY KEY, value TEXT)' );

		try {
			$connection->query( 'SELECT missing_column FROM t' );
			$this->fail( 'Expected the invalid read to throw.' );
		} catch ( PDOException $exception ) {
			$this->assertStringContainsString( 'missing_column', $exception->getMessage() );
		}

		$stmt = $connection->query( 'SELECT 1 AS value' );

		$this->assertSame( '1', $stmt->fetchColumn() );
		$pdo->rollBack();
		$this->assertSame(
			array(
				'SAVEPOINT wp_statement_1',
				'RELEASE SAVEPOINT wp_statement_1',
				'SAVEPOINT wp_statement_2',
				'ROLLBACK TO SAVEPOINT wp_statement_2',
				'RELEASE SAVEPOINT wp_statement_2',
				'SAVEPOINT wp_statement_3',
			),
			$pdo->exec_sql
		);
	}

	/**
	 * Tests locking SELECT statements use per-statement savepoints.
	 *
	 * @dataProvider data_locking_select_statements
	 *
	 * @param string $sql             Locking SELECT statement.
	 * @param bool   $should_succeed  Whether PostgreSQL accepts the statement.
	 */
	public function test_query_wraps_locking_select_statement_in_per_statement_savepoint( string $sql, bool $should_succeed ): void {
		$pdo        = new WP_PostgreSQL_Connection_Statement_Savepoint_Recording_PDO( $this->create_real_pgsql_pdo() );
		$connection = $this->create_connection_with_recording_pdo( $pdo );

		$pdo->beginTransaction();

		if ( $should_succeed ) {
			$connection->query( $sql );
		} else {
			try {
				$connection->query( $sql );
				$this->fail( 'Expected PostgreSQL to reject the raw MySQL locking SELECT shape.' );
			} catch ( PDOException $exception ) {
				$this->assertNotSame( '', $exception->getMessage() );
			}
		}

		$pdo->rollBack();
		$this->assertSame(
			$should_succeed
				? array(
					'SAVEPOINT wp_statement_1',
					'RELEASE SAVEPOINT wp_statement_1',
				)
				: array(
					'SAVEPOINT wp_statement_1',
					'ROLLBACK TO SAVEPOINT wp_statement_1',
					'RELEASE SAVEPOINT wp_statement_1',
				),
			$pdo->exec_sql
		);
	}

	/**
	 * Provides locking SELECT statements.
	 *
	 * @return array<string, array{string}>
	 */
	public function data_locking_select_statements(): array {
		return array(
			'for_update'         => array( 'SELECT 1 FOR UPDATE', true ),
			'for_share'          => array( 'SELECT 1 FOR SHARE', true ),
			'lock_in_share_mode' => array( 'SELECT 1 LOCK IN SHARE MODE', false ),
		);
	}

	/**
	 * Tests transaction-control statements are not wrapped in generated savepoints.
	 */
	public function test_query_does_not_wrap_transaction_control_statement_in_savepoint(): void {
		$pdo        = new WP_PostgreSQL_Connection_Statement_Savepoint_Recording_PDO( $this->create_real_pgsql_pdo() );
		$connection = $this->create_connection_with_recording_pdo( $pdo );

		$pdo->beginTransaction();
		$connection->query( 'ROLLBACK;' );

		$this->assertSame( array( 'ROLLBACK;' ), $pdo->prepared_sql );
		$this->assertSame( array(), $pdo->exec_sql );
	}

	/**
	 * Tests prepare returns a PDO statement and logs without parameters.
	 */
	public function test_prepare_returns_statement_and_logs_without_params(): void {
		$connection = new WP_PostgreSQL_Connection( array( 'pdo' => $this->create_real_pgsql_pdo() ) );
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
	 * Tests prepare consumes an active read savepoint before returning a statement.
	 */
	public function test_prepare_consumes_active_read_savepoint_before_prepared_write(): void {
		$pdo        = new WP_PostgreSQL_Connection_Statement_Savepoint_Recording_PDO( $this->create_real_pgsql_pdo() );
		$connection = $this->create_connection_with_recording_pdo( $pdo );

		$pdo->beginTransaction();
		$connection->query( 'CREATE TABLE t (id INTEGER PRIMARY KEY, value TEXT)' );
		$connection->query( 'SELECT 1' );

		$stmt = $connection->prepare( 'INSERT INTO t (id, value) VALUES (1, ?)' );
		$stmt->execute( array( 'kept' ) );

		try {
			$connection->query( 'SELECT missing_column FROM t' );
			$this->fail( 'Expected the invalid read to throw.' );
		} catch ( PDOException $exception ) {
			$this->assertStringContainsString( 'missing_column', $exception->getMessage() );
		}

		$count = $connection->query( 'SELECT COUNT(*) FROM t' );

		$this->assertSame( '1', $count->fetchColumn() );
		$pdo->rollBack();
		$this->assertSame(
			array(
				'CREATE TABLE t (id INTEGER PRIMARY KEY, value TEXT)',
				'SELECT 1',
				'INSERT INTO t (id, value) VALUES (1, ?)',
				'SELECT missing_column FROM t',
				'SELECT COUNT(*) FROM t',
			),
			$pdo->prepared_sql
		);
		$this->assertSame(
			array(
				'SAVEPOINT wp_statement_1',
				'RELEASE SAVEPOINT wp_statement_1',
				'SAVEPOINT wp_statement_2',
				'RELEASE SAVEPOINT wp_statement_2',
				'SAVEPOINT wp_statement_3',
				'ROLLBACK TO SAVEPOINT wp_statement_3',
				'RELEASE SAVEPOINT wp_statement_3',
				'SAVEPOINT wp_statement_4',
			),
			$pdo->exec_sql
		);
	}

	/**
	 * Tests last insert ID delegates to the injected PDO.
	 */
	public function test_get_last_insert_id_delegates_to_injected_pdo_default_sequence(): void {
		$pdo        = $this->create_real_pgsql_pdo();
		$connection = new WP_PostgreSQL_Connection( array( 'pdo' => $pdo ) );

		$pdo->exec( 'CREATE TABLE t (id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, value TEXT)' );
		$pdo->exec( "INSERT INTO t (value) VALUES ('first')" );

		$this->assertSame( '1', $connection->get_last_insert_id() );
	}

	/**
	 * Tests value quoting delegates to the injected PDO.
	 */
	public function test_quote_delegates_to_injected_pdo(): void {
		$pdo        = $this->create_real_pgsql_pdo();
		$connection = new WP_PostgreSQL_Connection( array( 'pdo' => $pdo ) );

		$this->assertSame( $pdo->quote( "O'Reilly" ), $connection->quote( "O'Reilly" ) );
	}

	/**
	 * Tests PostgreSQL string values with backslashes use escape string syntax.
	 */
	public function test_quote_uses_postgresql_escape_string_syntax_for_backslashes(): void {
		$connection = new WP_PostgreSQL_Connection( array( 'pdo' => $this->create_real_pgsql_pdo() ) );

		$this->assertSame(
			"E'O''Reilly \\\\ path'",
			$connection->quote( "O'Reilly \\ path" )
		);
	}

	/**
	 * Tests PostgreSQL string values with NUL bytes are encoded before quoting.
	 */
	public function test_quote_encodes_mysql_text_nul_bytes_for_postgresql(): void {
		$connection = new WP_PostgreSQL_Connection( array( 'pdo' => $this->create_real_pgsql_pdo() ) );

		$quoted = $connection->quote( "protected\0property" );
		$this->assertStringNotContainsString( "\0", $quoted );
		$this->assertStringContainsString( 'WP_MYSQL_TEXT_V1:', $quoted );
		$this->assertNotSame( "'protected\0property'", $quoted );
	}

	/**
	 * Creates a PostgreSQL connection backed by a recording PDO proxy.
	 *
	 * @param object $recording_pdo PDO-like recording proxy backed by PostgreSQL.
	 * @return WP_PostgreSQL_Connection Connection under test.
	 */
	private function create_connection_with_recording_pdo( $recording_pdo ): WP_PostgreSQL_Connection {
		$reflection = new ReflectionClass( WP_PostgreSQL_Connection::class );
		$connection = $reflection->newInstanceWithoutConstructor();

		$property = $reflection->getProperty( 'pdo' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( $connection, $recording_pdo );

		return $connection;
	}

	/**
	 * Create an isolated real PostgreSQL PDO for tests that execute SQL.
	 *
	 * @return PDO Real PostgreSQL PDO.
	 */
	private function create_real_pgsql_pdo(): PDO {
		$dsn = getenv( 'PGSQL_TEST_DSN' );
		if ( false === $dsn || '' === $dsn ) {
			$this->markTestSkipped( 'Set PGSQL_TEST_DSN to run this real PostgreSQL connection test.' );
		}

		$user     = getenv( 'PGSQL_TEST_USER' );
		$password = getenv( 'PGSQL_TEST_PASSWORD' );
		$pdo      = new PDO(
			$dsn,
			false === $user ? null : $user,
			false === $password ? null : $password
		);
		$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$pdo->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );

		$this->assertSame(
			'pgsql',
			$pdo->getAttribute( PDO::ATTR_DRIVER_NAME ),
			'PGSQL_TEST_DSN must use the pgsql PDO driver.'
		);

		$schema     = 'wp_pg_connection_test_' . strtolower( bin2hex( random_bytes( 8 ) ) );
		$schema_sql = WP_PostgreSQL_Connection::quote_identifier_value( $schema );

		$pdo->exec( 'CREATE SCHEMA ' . $schema_sql );
		$this->real_pgsql_test_schemas[] = array(
			'pdo'    => $pdo,
			'schema' => $schema,
		);
		$pdo->exec( 'SET search_path TO ' . $schema_sql . ', public' );

		return $pdo;
	}
}
