<?php

use PHPUnit\Framework\TestCase;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
class FetchObjectTestClass {
	public $col1;
	public $col2;
	public $col3;
	public $arg1;
	public $arg2;

	public function __construct( $arg1 = null, $arg2 = null ) {
		$this->arg1 = $arg1;
		$this->arg2 = $arg2;
	}
}

class WP_MySQL_On_SQLite_PDO_API_Tests extends TestCase {
	/** @var WP_MySQL_On_SQLite */
	private $driver;

	public function setUp(): void {
		$this->driver = new WP_MySQL_On_SQLite( 'mysql-on-sqlite:path=:memory:;dbname=wp;' );

		// Run all tests with stringified fetch mode results, so we can use
		// assertions that are consistent across all tested PHP versions.
		// The "PDO::ATTR_STRINGIFY_FETCHES" mode is tested separately.
		$this->driver->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );
	}

	public function test_connection(): void {
		$driver = new WP_MySQL_On_SQLite( 'mysql-on-sqlite:path=:memory:;dbname=WordPress;' );
		$this->assertInstanceOf( PDO::class, $driver );
	}

	public function test_static_connect(): void {
		if ( PHP_VERSION_ID < 80400 ) {
			$this->markTestSkipped( 'PDO::connect() requires PHP 8.4 or newer.' );
		}

		$driver = WP_MySQL_On_SQLite::connect(
			'mysql-on-sqlite:path=:memory:;dbname=WordPress;',
			null,
			null,
			array( PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC )
		);

		$this->assertInstanceOf( WP_MySQL_On_SQLite::class, $driver );
		$this->assertSame( array( 'value' => 1 ), $driver->query( 'SELECT 1 AS value' )->fetch() );
	}

	public function test_constructor_accepts_null_options(): void {
		$driver = new WP_MySQL_On_SQLite(
			'mysql-on-sqlite:path=:memory:;dbname=WordPress;',
			null,
			null,
			null
		);

		$this->assertInstanceOf( PDO::class, $driver );
	}

	public function test_constructor_does_not_forward_driver_specific_pdo_options(): void {
		// PDO MySQL and PDO SQLite assign different options to attributes 1000 and 1002.
		foreach (
			array(
				1000 => true,
				1002 => 'SET NAMES utf8mb4',
			) as $attribute => $value
		) {
			$driver = new WP_MySQL_On_SQLite(
				'mysql-on-sqlite:path=:memory:;dbname=WordPress;',
				null,
				null,
				array( $attribute => $value )
			);

			$this->assertEquals( 1, $driver->query( 'SELECT 1' )->fetchColumn() );
		}
	}

	public function test_constructor_applies_pdo_options(): void {
		$driver = new WP_MySQL_On_SQLite(
			'mysql-on-sqlite:path=:memory:;dbname=WordPress;',
			null,
			null,
			array(
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_ERRMODE            => PDO::ERRMODE_SILENT,
				PDO::ATTR_STRINGIFY_FETCHES  => true,
			)
		);

		$this->assertSame( PDO::FETCH_ASSOC, $driver->getAttribute( PDO::ATTR_DEFAULT_FETCH_MODE ) );
		$this->assertSame( PDO::ERRMODE_SILENT, $driver->getAttribute( PDO::ATTR_ERRMODE ) );
		$this->assertTrue( $driver->getAttribute( PDO::ATTR_STRINGIFY_FETCHES ) );
		$this->assertSame( array( 'value' => '1' ), $driver->query( 'SELECT 1 AS value' )->fetch() );

		// Internal operations always retain exception mode.
		$this->assertSame( PDO::ERRMODE_EXCEPTION, $driver->get_sqlite_pdo()->getAttribute( PDO::ATTR_ERRMODE ) );
	}

	public function test_reports_mysql_driver_name(): void {
		$this->assertSame( 'mysql', $this->driver->getAttribute( PDO::ATTR_DRIVER_NAME ) );
		$this->assertSame( 'sqlite', $this->driver->get_sqlite_pdo()->getAttribute( PDO::ATTR_DRIVER_NAME ) );
	}

	public function test_configured_mysql_version_controls_reporting_and_parsing(): void {
		$driver         = new WP_MySQL_On_SQLite(
			'mysql-on-sqlite:path=:memory:;dbname=WordPress;',
			null,
			null,
			array( 'mysql_version' => 50744 )
		);
		$server_version = '5.7.44-mysql-on-sqlite-' . SQLITE_DRIVER_VERSION;

		$this->assertSame( $server_version, $driver->getAttribute( PDO::ATTR_SERVER_VERSION ) );
		$this->assertSame( 'mysqlnd ' . $server_version, $driver->getAttribute( PDO::ATTR_CLIENT_VERSION ) );
		$this->assertSame( 'mysqlnd ' . $server_version, $driver->client_info );
		$this->assertSame( $server_version, $driver->query( 'SELECT VERSION()' )->fetchColumn() );
		$this->assertSame( $server_version, $driver->query( 'SELECT @@version' )->fetchColumn() );
		$this->assertEquals( 1, $driver->query( 'SELECT 1 /*!80000 + 1 */' )->fetchColumn() );
	}

	public function test_formats_six_digit_mysql_version(): void {
		$driver         = new WP_MySQL_On_SQLite(
			'mysql-on-sqlite:path=:memory:;dbname=WordPress;',
			null,
			null,
			array( 'mysql_version' => 100000 )
		);
		$server_version = '10.0.0-mysql-on-sqlite-' . SQLITE_DRIVER_VERSION;

		$this->assertSame( $server_version, $driver->getAttribute( PDO::ATTR_SERVER_VERSION ) );
		$this->assertSame( 'mysqlnd ' . $server_version, $driver->getAttribute( PDO::ATTR_CLIENT_VERSION ) );
		$this->assertSame( $server_version, $driver->query( 'SELECT VERSION()' )->fetchColumn() );
		$this->assertSame( $server_version, $driver->query( 'SELECT @@version' )->fetchColumn() );
	}

	/**
	 * @dataProvider data_invalid_mysql_versions
	 */
	public function test_rejects_invalid_mysql_version( $mysql_version ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage(
			'The "mysql_version" option must be an integer greater than or equal to 50700.'
		);

		new WP_MySQL_On_SQLite(
			'mysql-on-sqlite:path=:memory:;dbname=WordPress;',
			null,
			null,
			array( 'mysql_version' => $mysql_version )
		);
	}

	public function data_invalid_mysql_versions(): array {
		return array(
			'string'        => array( '80038' ),
			'float'         => array( 80038.0 ),
			'boolean'       => array( true ),
			'array'         => array( array( 80038 ) ),
			'below minimum' => array( 50699 ),
			'zero'          => array( 0 ),
			'negative'      => array( -80038 ),
		);
	}

	public function test_uses_shared_default_mysql_version(): void {
		$this->assertSame( 80038, WP_MySQL_On_SQLite::DEFAULT_MYSQL_VERSION );
		$this->assertSame( '8.0.38-mysql-on-sqlite-' . SQLITE_DRIVER_VERSION, $this->driver->getAttribute( PDO::ATTR_SERVER_VERSION ) );
		$this->assertSame( 'mysqlnd 8.0.38-mysql-on-sqlite-' . SQLITE_DRIVER_VERSION, $this->driver->getAttribute( PDO::ATTR_CLIENT_VERSION ) );
	}

	public function test_constructor_applies_fetch_column_default(): void {
		$driver = new WP_MySQL_On_SQLite(
			'mysql-on-sqlite:path=:memory:;dbname=WordPress;',
			null,
			null,
			array( PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_COLUMN )
		);

		$this->assertSame( 'value', $driver->query( "SELECT 'value'" )->fetch() );
	}

	public function test_constructor_applies_persistent_option(): void {
		$path = tempnam( sys_get_temp_dir(), 'wp_sqlite_' );
		unlink( $path );

		try {
			$driver = new WP_MySQL_On_SQLite(
				'mysql-on-sqlite:path=' . $path . ';dbname=WordPress;',
				null,
				null,
				array( PDO::ATTR_PERSISTENT => true )
			);

			$this->assertTrue( $driver->getAttribute( PDO::ATTR_PERSISTENT ) );
		} finally {
			$this->remove_database_files( $path );
		}
	}

	public function test_constructor_reports_stringify_fetches_from_injected_pdo(): void {
		if ( PHP_VERSION_ID < 80200 ) {
			$this->markTestSkipped( 'PDO SQLite cannot report PDO::ATTR_STRINGIFY_FETCHES before PHP 8.2.' );
		}

		$pdo_class = PHP_VERSION_ID >= 80400 ? Pdo\Sqlite::class : PDO::class;
		$pdo       = new $pdo_class( 'sqlite::memory:' );
		$pdo->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );
		$driver = new WP_MySQL_On_SQLite(
			'mysql-on-sqlite:dbname=wp',
			null,
			null,
			array( 'sqlite_pdo' => $pdo )
		);

		$this->assertTrue( $driver->getAttribute( PDO::ATTR_STRINGIFY_FETCHES ) );
	}

	public function test_driver_exception_exposes_originating_driver(): void {
		$exception = new WP_MySQL_On_SQLite_Exception( $this->driver, 'Test error.' );

		$this->assertSame( $this->driver, $exception->get_driver() );
		$this->assertSame( array( 'HY000', 1105, 'Test error.' ), $exception->errorInfo );
	}

	public function test_driver_exception_preserves_pdo_error_information(): void {
		$previous            = new PDOException( 'Test PDO error.' );
		$previous->errorInfo = array( 'HY000', 1, 'Test PDO error.' );
		$exception           = new WP_MySQL_On_SQLite_Exception(
			$this->driver,
			$previous->getMessage(),
			$previous->getCode(),
			$previous
		);

		$this->assertSame( $previous->errorInfo, $exception->errorInfo );
	}

	/**
	 * @dataProvider data_missing_table_queries
	 */
	public function test_missing_table_errors_use_mysql_identity( string $query ): void {
		$driver_message = "Table 'missing_table' doesn't exist";
		$message        = 'SQLSTATE[42S02]: Base table or view not found: 1146 ' . $driver_message;

		try {
			$this->driver->query( $query );
			$this->fail( 'Expected query() to throw an exception.' );
		} catch ( WP_MySQL_On_SQLite_Exception $exception ) {
			$this->assertSame( '42S02', $exception->getCode() );
			$this->assertSame( $message, $exception->getMessage() );
			$this->assertSame( array( '42S02', 1146, $driver_message ), $exception->errorInfo );
		}

		$this->assertSame( '42S02', $this->driver->errorCode() );
		$this->assertSame( array( '42S02', 1146, $driver_message ), $this->driver->errorInfo() );
	}

	public function data_missing_table_queries(): array {
		return array(
			'Select' => array( 'SELECT * FROM missing_table' ),
			'Insert' => array( 'INSERT INTO missing_table VALUES (1)' ),
			'Update' => array( 'UPDATE missing_table SET value = 1' ),
			'Delete' => array( 'DELETE FROM missing_table' ),
		);
	}

	public function test_emulated_driver_exception_exposes_mysql_error_information(): void {
		$this->driver->query( 'CREATE TABLE t (id INT)' );

		try {
			$this->driver->query( 'CREATE TABLE t (id INT)' );
			$this->fail( 'Expected query() to throw an exception.' );
		} catch ( WP_MySQL_On_SQLite_Exception $exception ) {
			$this->assertSame( '42S01', $exception->getCode() );
			$this->assertSame(
				array( '42S01', 1050, "Table 't' already exists" ),
				$exception->errorInfo
			);
		}
	}

	public function test_exposes_underlying_sqlite_pdo(): void {
		$pdo_class = PHP_VERSION_ID >= 80400 ? Pdo\Sqlite::class : PDO::class;
		$pdo       = new $pdo_class( 'sqlite::memory:' );
		$driver    = new WP_MySQL_On_SQLite(
			'mysql-on-sqlite:dbname=wp',
			null,
			null,
			array( 'sqlite_pdo' => $pdo )
		);

		$this->assertSame( $pdo, $driver->get_sqlite_pdo() );
	}

	public function test_dsn_parsing(): void {
		// Standard DSN.
		$driver = new WP_MySQL_On_SQLite( 'mysql-on-sqlite:path=:memory:;dbname=wp' );
		$this->assertSame( 'wp', $driver->query( 'SELECT DATABASE()' )->fetch()[0] );

		// DSN with trailing semicolon.
		$driver = new WP_MySQL_On_SQLite( 'mysql-on-sqlite:path=:memory:;dbname=wp;' );
		$this->assertSame( 'wp', $driver->query( 'SELECT DATABASE()' )->fetch()[0] );

		// DSN with whitespace before argument names.
		$driver = new WP_MySQL_On_SQLite( "mysql-on-sqlite:  path=:memory:; \n\r\t\v\fdbname=wp" );
		$this->assertSame( 'wp', $driver->query( 'SELECT DATABASE()' )->fetch()[0] );

		// DSN with whitespace in the database name.
		$driver = new WP_MySQL_On_SQLite( 'mysql-on-sqlite:path=:memory:;dbname= w p ' );
		$this->assertSame( ' w p ', $driver->query( 'SELECT DATABASE()' )->fetch()[0] );

		// DSN with semicolon in the database name.
		$driver = new WP_MySQL_On_SQLite( 'mysql-on-sqlite:path=:memory:;dbname=wp;dbname=w;;p;' );
		$this->assertSame( 'w;p', $driver->query( 'SELECT DATABASE()' )->fetch()[0] );

		// DSN with semicolon in the database name and a terminating semicolon.
		$driver = new WP_MySQL_On_SQLite( 'mysql-on-sqlite:path=:memory:;dbname=w;;;p' );
		$this->assertSame( 'w;', $driver->query( 'SELECT DATABASE()' )->fetch()[0] );

		// DSN with two semicolons in the database name.
		$driver = new WP_MySQL_On_SQLite( 'mysql-on-sqlite:path=:memory:;dbname=w;;;;p' );
		$this->assertSame( 'w;;p', $driver->query( 'SELECT DATABASE()' )->fetch()[0] );

		// DSN with a "\0" byte (always terminates the DSN string).
		$driver = new WP_MySQL_On_SQLite( "mysql-on-sqlite:path=:memory:;dbname=w\0p;" );
		$this->assertSame( 'w', $driver->query( 'SELECT DATABASE()' )->fetch()[0] );
	}

	public function test_journal_mode_defaults_to_wal(): void {
		$path = tempnam( sys_get_temp_dir(), 'wp_sqlite_' );
		unlink( $path );

		try {
			$driver     = new WP_MySQL_On_SQLite( 'mysql-on-sqlite:path=' . $path . ';dbname=wp' );
			$connection = $driver->get_connection();
			$this->assertSame(
				'wal',
				strtolower( (string) $connection->query( 'PRAGMA journal_mode' )->fetchColumn() )
			);
			$this->assertSame(
				'1',
				(string) $connection->query( 'PRAGMA synchronous' )->fetchColumn()
			);
		} finally {
			$this->remove_database_files( $path );
		}
	}

	public function test_journal_mode_and_synchronous_driver_options(): void {
		$path = tempnam( sys_get_temp_dir(), 'wp_sqlite_' );
		unlink( $path );

		try {
			$driver     = new WP_MySQL_On_SQLite(
				'mysql-on-sqlite:path=' . $path . ';dbname=wp',
				null,
				null,
				array(
					'sqlite_journal_mode' => 'DELETE',
					'sqlite_synchronous'  => 'FULL',
				)
			);
			$connection = $driver->get_connection();
			$this->assertSame(
				'delete',
				strtolower( (string) $connection->query( 'PRAGMA journal_mode' )->fetchColumn() )
			);
			$this->assertSame(
				'2',
				(string) $connection->query( 'PRAGMA synchronous' )->fetchColumn()
			);
		} finally {
			$this->remove_database_files( $path );
		}
	}

	public function test_query(): void {
		$result = $this->driver->query( "SELECT 1, 'abc'" );
		$this->assertInstanceOf( WP_MySQL_On_SQLite_Statement::class, $result );
		$this->assertInstanceOf( PDOStatement::class, $result );
		if ( PHP_VERSION_ID < 80000 ) {
			$this->assertSame(
				array(
					1     => '1',
					2     => '1',
					'abc' => 'abc',
					3     => 'abc',
				),
				$result->fetch()
			);
		} else {
			$this->assertSame(
				array(
					1     => '1',
					0     => '1',
					'abc' => 'abc',
				),
				$result->fetch()
			);
		}
	}

	public function test_statement_query_string(): void {
		$query = 'SELECT 1 AS value';
		$stmt  = $this->driver->query( $query );

		// Userland cannot initialize PDOStatement::$queryString before PHP 8.1.
		$this->assertSame( PHP_VERSION_ID < 80100 ? null : $query, $stmt->queryString );
	}

	public function test_statement_column_metadata_is_snapshotted(): void {
		$stmt = $this->driver->query( "SELECT 1 AS first, 'value' AS second" );

		$this->assertSame( 'first', $stmt->getColumnMeta( 0 )['name'] );
		$this->assertSame( 'second', $stmt->getColumnMeta( 1 )['name'] );
		$this->assertSame( 'second', $stmt->getColumnMeta( '1' )['name'] );
		$this->assertFalse( $stmt->getColumnMeta( 2 ) );

		$this->driver->query( 'SELECT 3 AS third' );

		$this->assertSame( 'first', $stmt->getColumnMeta( 0 )['name'] );
		$this->assertSame( 'second', $stmt->getColumnMeta( 1 )['name'] );
	}

	public function test_statement_column_metadata_rejects_negative_index(): void {
		if ( PHP_VERSION_ID < 80000 ) {
			$this->markTestSkipped( 'PDOStatement::getColumnMeta() throws ValueError on PHP 8.0 or newer.' );
		}

		$stmt = $this->driver->query( 'SELECT 1' );

		$this->expectException( ValueError::class );
		$stmt->getColumnMeta( -1 );
	}

	public function test_statement_column_metadata_rejects_invalid_index_type(): void {
		if ( PHP_VERSION_ID < 80000 ) {
			$this->markTestSkipped( 'PDOStatement::getColumnMeta() throws TypeError on PHP 8.0 or newer.' );
		}

		$stmt = $this->driver->query( 'SELECT 1' );

		$this->expectException( TypeError::class );
		$stmt->getColumnMeta( 'invalid' );
	}

	public function test_statement_column_metadata_is_resolved_lazily(): void {
		$resolved_columns = array();
		$raw_column_meta  = array(
			array( 'name' => 'first' ),
			array( 'name' => 'second' ),
		);
		$stmt             = new WP_MySQL_On_SQLite_Statement(
			$this->driver->get_sqlite_pdo()->query( 'SELECT 1, 2' ),
			'SELECT 1, 2',
			function ( $column ) use ( &$resolved_columns, $raw_column_meta ) {
				if ( ! array_key_exists( $column, $raw_column_meta ) ) {
					return false;
				}

				$column_meta        = $raw_column_meta[ $column ];
				$resolved_columns[] = $column_meta['name'];
				return $column_meta;
			}
		);

		$this->assertSame( array(), $resolved_columns );
		$this->assertSame( 'second', $stmt->getColumnMeta( 1 )['name'] );
		$this->assertSame( array( 'second' ), $resolved_columns );

		$this->assertSame( 'second', $stmt->getColumnMeta( 1 )['name'] );
		$this->assertFalse( $stmt->getColumnMeta( 2 ) );
		$this->assertSame( array( 'second' ), $resolved_columns );

		$this->assertSame( 'first', $stmt->getColumnMeta( 0 )['name'] );
		$this->assertSame( array( 'second', 'first' ), $resolved_columns );
	}

	public function test_statement_column_metadata_resolution_preserves_the_query_log(): void {
		$this->driver->exec( 'CREATE TABLE metadata_test (id INT)' );
		$this->driver->exec( 'INSERT INTO metadata_test VALUES (1)' );
		$stmt                = $this->driver->query( 'SELECT id FROM metadata_test' );
		$last_sqlite_queries = $this->driver->get_last_sqlite_queries();

		$this->assertSame( 'id', $stmt->getColumnMeta( 0 )['name'] );
		$this->assertSame( $last_sqlite_queries, $this->driver->get_last_sqlite_queries() );
	}

	public function test_statement_column_metadata_snapshots_database_context(): void {
		$stmt = $this->driver->query( 'SELECT 1 AS value' );
		$this->driver->exec( 'USE information_schema' );

		$this->assertSame( 'wp', $stmt->getColumnMeta( 0 )['mysqli:db'] );
	}

	public function test_statement_error_information(): void {
		$stmt = $this->driver->query( 'SELECT 1' );

		$this->assertSame( '00000', $stmt->errorCode() );
		$this->assertSame( array( '00000', null, null ), $stmt->errorInfo() );
	}

	public function test_statement_error_information_discards_stale_sqlite_error(): void {
		$pdo_class = PHP_VERSION_ID >= 80400 ? Pdo\Sqlite::class : PDO::class;
		$pdo       = new $pdo_class( 'sqlite::memory:' );
		$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT );
		$pdo->query( 'SELECT * FROM missing_table' );

		$stmt = new WP_MySQL_On_SQLite_Statement(
			$pdo->query( 'SELECT 1' ),
			'SELECT 1',
			function () {
				return false;
			}
		);

		$this->assertSame( '00000', $stmt->errorCode() );
		$this->assertSame( array( '00000', null, null ), $stmt->errorInfo() );
	}

	public function test_statement_iteration(): void {
		$stmt = $this->driver->query( 'SELECT 1 AS value UNION ALL SELECT 2', PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array( 'value' => '1' ),
				array( 'value' => '2' ),
			),
			iterator_to_array( $stmt )
		);
	}

	public function test_statement_close_cursor(): void {
		$stmt = $this->driver->query( 'SELECT 1 UNION ALL SELECT 2' );

		$this->assertTrue( $stmt->closeCursor() );
		$this->assertFalse( $stmt->fetch() );
	}

	public function test_statement_bind_column(): void {
		$stmt  = $this->driver->query( 'SELECT 1 AS value' );
		$value = null;

		$this->assertTrue( $stmt->bindColumn( 'value', $value ) );
		$this->assertTrue( $stmt->fetch( PDO::FETCH_BOUND ) );
		$this->assertSame( '1', $value );
	}

	/**
	 * @dataProvider data_pdo_fetch_methods
	 */
	public function test_query_with_fetch_mode( $query, $mode, $expected ): void {
		$stmt   = $this->driver->query( $query, $mode );
		$result = $stmt->fetch();

		if ( is_object( $expected ) ) {
			$this->assertInstanceOf( get_class( $expected ), $result );
			$this->assertSame( (array) $expected, (array) $result );
		} elseif ( PDO::FETCH_NAMED === $mode ) {
			// PDO::FETCH_NAMED returns all array keys as strings, even numeric
			// ones. This is not possible in plain PHP and might be a PDO bug.
			$this->assertSame( array_map( 'strval', array_keys( $expected ) ), array_keys( $result ) );
			$this->assertSame( array_values( $expected ), array_values( $result ) );
		} else {
			$this->assertSame( $expected, $result );
		}

		$this->assertFalse( $stmt->fetch() );
	}

	public function test_query_fetch_mode_not_set(): void {
		$result = $this->driver->query( 'SELECT 1' );
		if ( PHP_VERSION_ID < 80000 ) {
			$this->assertSame(
				array(
					1 => '1',
					2 => '1',
				),
				$result->fetch()
			);
		} else {
			$this->assertSame(
				array(
					1 => '1',
					0 => '1',
				),
				$result->fetch()
			);
		}
		$this->assertFalse( $result->fetch() );
	}

	public function test_query_fetch_mode_invalid_arg_count(): void {
		$this->expectException( ArgumentCountError::class );
		$this->expectExceptionMessage( 'PDO::query() expects exactly 2 arguments for the fetch mode provided, 3 given' );
		$this->driver->query( 'SELECT 1', PDO::FETCH_ASSOC, 0 );
	}

	public function test_query_fetch_default_mode_allow_any_args(): void {
		if ( PHP_VERSION_ID < 80100 ) {
			// On PHP < 8.1, fetch mode value of NULL is not allowed.
			$result = @$this->driver->query( 'SELECT 1', null, 1, 2, 'abc', array(), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->assertFalse( $result );
			$this->assertSame( 'PDO::query(): SQLSTATE[HY000]: General error: mode must be an integer', error_get_last()['message'] );
			return;
		}

		// On PHP >= 8.1, NULL fetch mode is allowed to use the default fetch mode.
		// In such cases, any additional arguments are ignored and not validated.
		$expected_result = array(
			array(
				1 => '1',
				0 => '1',
			),
		);

		$result = $this->driver->query( 'SELECT 1' );
		$this->assertSame( $expected_result, $result->fetchAll() );

		$result = $this->driver->query( 'SELECT 1', null );
		$this->assertSame( $expected_result, $result->fetchAll() );

		$result = $this->driver->query( 'SELECT 1', null, 1 );
		$this->assertSame( $expected_result, $result->fetchAll() );

		$result = $this->driver->query( 'SELECT 1', null, 'abc' );
		$this->assertSame( $expected_result, $result->fetchAll() );

		$result = $this->driver->query( 'SELECT 1', null, 1, 2, 'abc', array(), true );
		$this->assertSame( $expected_result, $result->fetchAll() );
	}

	public function test_query_fetch_class_not_enough_args(): void {
		$this->expectException( ArgumentCountError::class );
		$this->expectExceptionMessage( 'PDO::query() expects at least 3 arguments for the fetch mode provided, 2 given' );
		$this->driver->query( 'SELECT 1', PDO::FETCH_CLASS );
	}

	public function test_query_fetch_class_too_many_args(): void {
		$this->expectException( ArgumentCountError::class );
		$this->expectExceptionMessage( 'PDO::query() expects at most 4 arguments for the fetch mode provided, 5 given' );
		$this->driver->query( 'SELECT 1', PDO::FETCH_CLASS, '\stdClass', array(), array() );
	}

	public function test_query_fetch_class_invalid_class_type(): void {
		$this->expectException( TypeError::class );
		$this->expectExceptionMessage( 'PDO::query(): Argument #3 must be of type string, int given' );
		$this->driver->query( 'SELECT 1', PDO::FETCH_CLASS, 1 );
	}

	public function test_query_fetch_class_invalid_class_name(): void {
		$this->expectException( TypeError::class );
		$this->expectExceptionMessage( 'PDO::query(): Argument #3 must be a valid class' );
		$this->driver->query( 'SELECT 1', PDO::FETCH_CLASS, 'non-existent-class' );
	}

	public function test_query_fetch_class_invalid_constructor_args_type(): void {
		$this->expectException( TypeError::class );
		$this->expectExceptionMessage( 'PDO::query(): Argument #4 must be of type ?array, int given' );
		$this->driver->query( 'SELECT 1', PDO::FETCH_CLASS, 'stdClass', 1 );
	}

	public function test_query_fetch_into_invalid_arg_count(): void {
		$this->expectException( ArgumentCountError::class );
		$this->expectExceptionMessage( 'PDO::query() expects exactly 3 arguments for the fetch mode provided, 2 given' );
		$this->driver->query( 'SELECT 1', PDO::FETCH_INTO );
	}

	public function test_query_fetch_into_invalid_object_type(): void {
		$this->expectException( TypeError::class );
		$this->expectExceptionMessage( 'PDO::query(): Argument #3 must be of type object, int given' );
		$this->driver->query( 'SELECT 1', PDO::FETCH_INTO, 1 );
	}

	public function test_exec(): void {
		$result = $this->driver->exec( 'SELECT 1' );
		$this->assertEquals( 0, $result );

		$result = $this->driver->exec( 'CREATE TABLE t (id INT)' );
		$this->assertEquals( 0, $result );

		$result = $this->driver->exec( 'INSERT INTO t (id) VALUES (1)' );
		$this->assertEquals( 1, $result );

		$result = $this->driver->exec( 'INSERT INTO t (id) VALUES (2), (3)' );
		$this->assertEquals( 2, $result );

		$result = $this->driver->exec( 'UPDATE t SET id = 10 + id WHERE id = 0' );
		$this->assertEquals( 0, $result );

		$result = $this->driver->exec( 'UPDATE t SET id = 10 + id WHERE id = 1' );
		$this->assertEquals( 1, $result );

		$result = $this->driver->exec( 'UPDATE t SET id = 10 + id WHERE id < 10' );
		$this->assertEquals( 2, $result );

		$result = $this->driver->exec( 'DELETE FROM t WHERE id = 11' );
		$this->assertEquals( 1, $result );

		$result = $this->driver->exec( 'DELETE FROM t' );
		$this->assertEquals( 2, $result );

		$result = $this->driver->exec( 'DROP TABLE t' );
		$this->assertEquals( 0, $result );
	}

	public function test_last_insert_id(): void {
		$this->assertSame( '0', $this->driver->lastInsertId() );

		$this->driver->query( 'CREATE TABLE t (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY)' );
		$this->assertSame( '0', $this->driver->lastInsertId() );

		$this->driver->query( 'INSERT INTO t (id) VALUES (NULL)' );

		$this->assertSame( '1', $this->driver->lastInsertId() );
		$this->assertSame( '1', $this->driver->lastInsertId( 'ignored_sequence_name' ) );

		$this->driver->query( 'CREATE TABLE another_table (id INT)' );
		$this->assertSame( '0', $this->driver->lastInsertId() );
	}

	public function test_last_insert_id_rejects_invalid_sequence_name(): void {
		if ( PHP_VERSION_ID < 80000 ) {
			$result = @$this->driver->lastInsertId( array() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			$this->assertFalse( $result );
			$this->assertSame( 'PDO::lastInsertId() expects parameter 1 to be string, array given', error_get_last()['message'] );
			return;
		}

		$this->expectException( TypeError::class );
		$this->expectExceptionMessage( 'PDO::lastInsertId(): Argument #1 ($name) must be of type ?string, array given' );
		$this->driver->lastInsertId( array() );
	}

	public function test_connection_error_information(): void {
		$this->assertNull( $this->driver->errorCode() );
		$this->assertSame( array( '', null, null ), $this->driver->errorInfo() );

		$this->driver->query( 'SELECT 1' );

		$this->assertSame( '00000', $this->driver->errorCode() );
		$this->assertSame(
			array( '00000', null, null ),
			$this->driver->errorInfo()
		);
	}

	public function test_silent_error_mode(): void {
		$this->driver->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT );

		$this->assertFalse( $this->driver->query( 'SELECT * FROM missing_table' ) );
		$this->assertSame( '42S02', $this->driver->errorCode() );
		$this->assertSame(
			array( '42S02', 1146, "Table 'missing_table' doesn't exist" ),
			$this->driver->errorInfo()
		);
		$this->assertFalse( $this->driver->exec( 'SELECT * FROM missing_table' ) );

		$this->assertInstanceOf( PDOStatement::class, $this->driver->query( 'SELECT 1' ) );
		$this->assertSame( '00000', $this->driver->errorCode() );
	}

	public function test_warning_error_mode(): void {
		$this->driver->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING );
		$warning = null;
		set_error_handler(
			function ( $level, $message ) use ( &$warning ) {
				if ( E_USER_WARNING === $level ) {
					$warning = $message;
					return true;
				}
				return false;
			}
		);

		try {
			$this->assertFalse( $this->driver->query( 'SELECT * FROM missing_table' ) );
		} finally {
			restore_error_handler();
		}

		$this->assertSame(
			"SQLSTATE[42S02]: Base table or view not found: 1146 Table 'missing_table' doesn't exist",
			$warning
		);
	}

	public function test_quote_matches_mysql_escaping(): void {
		$backslash = chr( 92 );
		$value     = chr( 0 ) . "\n\r{$backslash}'\"" . chr( 26 ) . "\tƮềʂᴛ🙂";

		$quoted = $this->driver->quote( $value );
		$this->assertSame(
			"'{$backslash}0{$backslash}n{$backslash}r"
				. "{$backslash}{$backslash}{$backslash}'{$backslash}\"{$backslash}Z\tƮềʂᴛ🙂'",
			$quoted
		);
		$this->assertSame( $value, $this->driver->query( "SELECT $quoted" )->fetchColumn() );
	}

	public function test_quote_supports_parameter_types(): void {
		$value  = "\0\n\r\\'\"" . chr( 26 ) . "\tƮềʂᴛ🙂";
		$quoted = $this->driver->quote( $value );

		$this->assertSame( $quoted, $this->driver->quote( $value, PDO::PARAM_STR ) );
		$this->assertSame( 'N' . $quoted, $this->driver->quote( $value, PDO::PARAM_STR_NATL ) );
		$this->assertSame( $quoted, $this->driver->quote( $value, PDO::PARAM_STR_CHAR ) );
		$this->assertSame(
			$quoted,
			$this->driver->quote( $value, PDO::PARAM_STR_NATL | PDO::PARAM_STR_CHAR )
		);
		$this->assertSame( '_binary' . $quoted, $this->driver->quote( $value, PDO::PARAM_LOB ) );
		$this->assertSame(
			'_binary' . $quoted,
			$this->driver->quote( $value, PDO::PARAM_LOB | PDO::PARAM_STR_NATL )
		);
	}

	public function test_quote_rejects_non_stringable_values(): void {
		$resource = fopen( 'php://memory', 'r' );

		try {
			foreach ( array( array(), $resource, new stdClass() ) as $value ) {
				try {
					$this->driver->quote( $value );
					$this->fail( 'Expected quote() to throw a TypeError.' );
				} catch ( TypeError $e ) {
					$this->assertStringContainsString( 'must be of type string', $e->getMessage() );
				}
			}
		} finally {
			fclose( $resource );
		}
	}

	public function test_quote_accepts_stringable_objects(): void {
		$value = new class() {
			public function __toString(): string {
				return 'value';
			}
		};

		$this->assertSame( "'value'", $this->driver->quote( $value ) );
	}

	public function test_begin_transaction(): void {
		$result = $this->driver->beginTransaction();
		$this->assertTrue( $result );
	}

	public function test_begin_transaction_already_active(): void {
		$this->driver->beginTransaction();

		$this->expectException( PDOException::class );
		$this->expectExceptionMessage( 'There is already an active transaction' );
		$this->expectExceptionCode( 0 );
		$this->driver->beginTransaction();
	}

	public function test_commit(): void {
		$this->driver->beginTransaction();
		$result = $this->driver->commit();
		$this->assertTrue( $result );
	}

	public function test_commit_no_active_transaction(): void {
		$this->expectException( PDOException::class );
		$this->expectExceptionMessage( 'There is no active transaction' );
		$this->expectExceptionCode( 0 );
		$this->driver->commit();
	}

	public function test_rollback(): void {
		$this->driver->beginTransaction();
		$result = $this->driver->rollBack();
		$this->assertTrue( $result );
	}

	public function test_rollback_no_active_transaction(): void {
		$this->expectException( PDOException::class );
		$this->expectExceptionMessage( 'There is no active transaction' );
		$this->expectExceptionCode( 0 );
		$this->driver->rollBack();
	}

	public function test_transaction_methods_flush_operation_state(): void {
		$this->driver->query( 'CREATE TABLE t (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY)' );
		$this->driver->query( 'INSERT INTO t (id) VALUES (NULL)' );

		$this->assertSame( '1', $this->driver->lastInsertId() );
		$this->assertTrue( $this->driver->beginTransaction() );
		$this->assertSame( '0', $this->driver->lastInsertId() );
		$this->assertSame( '', $this->driver->get_last_mysql_query() );
		$this->assertSame( array( 'BEGIN IMMEDIATE' ), array_column( $this->driver->get_last_sqlite_queries(), 'sql' ) );

		$this->driver->query( 'INSERT INTO t (id) VALUES (NULL)' );

		$this->assertSame( '2', $this->driver->lastInsertId() );
		$this->assertTrue( $this->driver->commit() );
		$this->assertSame( '0', $this->driver->lastInsertId() );
		$this->assertSame( '', $this->driver->get_last_mysql_query() );
		$this->assertSame( array( 'COMMIT' ), array_column( $this->driver->get_last_sqlite_queries(), 'sql' ) );

		$this->driver->beginTransaction();
		$this->driver->query( 'INSERT INTO t (id) VALUES (NULL)' );

		$this->assertSame( '3', $this->driver->lastInsertId() );
		$this->assertTrue( $this->driver->rollBack() );
		$this->assertSame( '0', $this->driver->lastInsertId() );
		$this->assertSame( '', $this->driver->get_last_mysql_query() );
		$this->assertSame( array( 'ROLLBACK' ), array_column( $this->driver->get_last_sqlite_queries(), 'sql' ) );
	}

	public function test_fetch_default(): void {
		// Default fetch mode is PDO::FETCH_BOTH.
		$result = $this->driver->query( "SELECT 1, 'abc', 2" );
		if ( PHP_VERSION_ID < 80000 ) {
			$this->assertSame(
				array(
					1     => '1',
					2     => '2',
					'abc' => 'abc',
					3     => 'abc',
					4     => '2',
				),
				$result->fetch()
			);
		} else {
			$this->assertSame(
				array(
					1     => '1',
					0     => '1',
					'abc' => 'abc',
					'2'   => '2',
				),
				$result->fetch()
			);
		}
	}

	/**
	 * @dataProvider data_pdo_fetch_methods
	 */
	public function test_fetch( $query, $mode, $expected ): void {
		$stmt   = $this->driver->query( $query );
		$result = $stmt->fetch( $mode );

		if ( is_object( $expected ) ) {
			$this->assertInstanceOf( get_class( $expected ), $result );
			$this->assertEquals( $expected, $result );
		} elseif ( PDO::FETCH_NAMED === $mode ) {
			// PDO::FETCH_NAMED returns all array keys as strings, even numeric
			// ones. This is not possible in plain PHP and might be a PDO bug.
			$this->assertSame( array_map( 'strval', array_keys( $expected ) ), array_keys( $result ) );
			$this->assertSame( array_values( $expected ), array_values( $result ) );
		} else {
			$this->assertSame( $expected, $result );
		}
	}

	public function test_fetch_column(): void {
		$query = "
			SELECT 1, 'abc', true
			UNION ALL
			SELECT 2, 'xyz', false
			UNION ALL
			SELECT 3, null, null
		";

		// Fetch first column (default).
		$stmt = $this->driver->query( $query );
		$this->assertSame( '1', $stmt->fetchColumn() );
		$this->assertSame( '2', $stmt->fetchColumn() );
		$this->assertSame( '3', $stmt->fetchColumn() );
		$this->assertFalse( $stmt->fetchColumn() );

		// Fetch second column.
		$stmt = $this->driver->query( $query );
		$this->assertSame( 'abc', $stmt->fetchColumn( 1 ) );
		$this->assertSame( 'xyz', $stmt->fetchColumn( 1 ) );
		$this->assertNull( $stmt->fetchColumn( 1 ) );
		$this->assertFalse( $stmt->fetchColumn( 1 ) );

		// Fetch third column.
		$stmt = $this->driver->query( $query );
		$this->assertSame( '1', $stmt->fetchColumn( 2 ) );
		$this->assertSame( '0', $stmt->fetchColumn( 2 ) );
		$this->assertNull( $stmt->fetchColumn( 2 ) );
		$this->assertFalse( $stmt->fetchColumn( 2 ) );

		// Fetch different columns across rows.
		$stmt = $this->driver->query( $query );
		$this->assertSame( '1', $stmt->fetchColumn( 0 ) );
		$this->assertSame( 'xyz', $stmt->fetchColumn( 1 ) );
		$this->assertNull( $stmt->fetchColumn( 2 ) );
		$this->assertFalse( $stmt->fetchColumn() );
	}

	public function test_fetch_column_invalid_index(): void {
		$stmt = $this->driver->query( "SELECT 1, 'abc', true" );

		if ( PHP_VERSION_ID < 80000 ) {
			$this->expectException( PDOException::class );
			$this->expectExceptionMessage( 'Invalid column index' );
		} else {
			$this->expectException( ValueError::class );
			$this->expectExceptionMessage( 'Invalid column index' );
		}
		$stmt->fetchColumn( 3 );
	}

	public function test_fetch_column_negative_index(): void {
		$stmt = $this->driver->query( "SELECT 1, 'abc', true" );

		if ( PHP_VERSION_ID < 80000 ) {
			$this->expectException( PDOException::class );
			$this->expectExceptionMessage( 'Invalid column index' );
		} else {
			$this->expectException( ValueError::class );
			$this->expectExceptionMessage( 'Column index must be greater than or equal to 0' );
		}
		$stmt->fetchColumn( -1 );
	}

	public function test_fetch_obj(): void {
		// No arguments (stdClass).
		$stmt = $this->driver->query( "SELECT 1, 'abc', true" );
		$this->assertEquals(
			(object) array(
				1      => '1',
				'abc'  => 'abc',
				'true' => true,
			),
			$stmt->fetchObject()
		);
		$this->assertFalse( $stmt->fetchObject() );

		// Custom class.
		$stmt   = $this->driver->query( "SELECT 1 AS col1, 'abc' AS col2, true AS col3" );
		$result = $stmt->fetchObject( FetchObjectTestClass::class );
		$this->assertInstanceOf( FetchObjectTestClass::class, $result );
		$this->assertSame( '1', $result->col1 );
		$this->assertSame( 'abc', $result->col2 );
		$this->assertSame( '1', $result->col3 );
		$this->assertNull( $result->arg1 );
		$this->assertNull( $result->arg2 );
		$this->assertFalse( $stmt->fetchObject( FetchObjectTestClass::class ) );

		// Custom class with constructor arguments.
		$stmt   = $this->driver->query( "SELECT 1 AS col1, 'abc' AS col2, true AS col3" );
		$result = $stmt->fetchObject( FetchObjectTestClass::class, array( 'val1', 'val2' ) );
		$this->assertInstanceOf( FetchObjectTestClass::class, $result );
		$this->assertSame( '1', $result->col1 );
		$this->assertSame( 'abc', $result->col2 );
		$this->assertSame( '1', $result->col3 );
		$this->assertSame( 'val1', $result->arg1 );
		$this->assertSame( 'val2', $result->arg2 );
		$this->assertFalse( $stmt->fetchObject( FetchObjectTestClass::class, array( 'val1', 'val2' ) ) );
	}

	public function test_attr_default_fetch_mode(): void {
		$this->driver->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_NUM );
		$result = $this->driver->query( "SELECT 'a', 'b', 'c'" );
		$this->assertSame(
			array( 'a', 'b', 'c' ),
			$result->fetch()
		);

		$this->driver->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
		$result = $this->driver->query( "SELECT 'a', 'b', 'c'" );
		$this->assertSame(
			array(
				'a' => 'a',
				'b' => 'b',
				'c' => 'c',
			),
			$result->fetch()
		);
	}

	public function test_set_attribute_rejects_driver_specific_attributes(): void {
		// Attribute 1002 has unrelated meanings in PDO MySQL and PDO SQLite.
		$this->assertFalse( $this->driver->setAttribute( 1002, true ) );
	}

	public function test_attr_stringify_fetches(): void {
		$this->driver->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );
		$this->assertTrue( $this->driver->getAttribute( PDO::ATTR_STRINGIFY_FETCHES ) );
		$result = $this->driver->query( "SELECT 123, 1.23, 'abc', true, false" );
		$this->assertSame(
			array( '123', '1.23', 'abc', '1', '0' ),
			$result->fetch( PDO::FETCH_NUM )
		);

		$this->driver->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, false );
		$this->assertFalse( $this->driver->getAttribute( PDO::ATTR_STRINGIFY_FETCHES ) );
		$result = $this->driver->query( "SELECT 123, 1.23, 'abc', true, false" );
		$this->assertSame(
			/*
			 * On PHP < 8.1, "PDO::ATTR_STRINGIFY_FETCHES" set to "false" has no
			 * effect when "PDO::ATTR_EMULATE_PREPARES" is "true" (the default).
			 *
			 * TODO: Consider supporting non-string values on PHP < 8.1 when both
			 *       "PDO::ATTR_STRINGIFY_FETCHES" and "PDO::ATTR_EMULATE_PREPARES"
			 *       are set to "false". This would require emulating the behavior,
			 *       as PDO SQLite on PHP < 8.1 seems to always return strings.
			 */
			PHP_VERSION_ID < 80100
				? array( '123', '1.23', 'abc', '1', '0' )
				: array( 123, 1.23, 'abc', 1, 0 ),
			$result->fetch( PDO::FETCH_NUM )
		);
	}

	public function data_pdo_fetch_methods(): Generator {
		// PDO::FETCH_BOTH
		yield 'PDO::FETCH_BOTH' => array(
			"SELECT 1, 'abc', 2, 'two' as `2`",
			PDO::FETCH_BOTH,
			PHP_VERSION_ID < 80000
				? array(
					1     => '1',
					2     => 'two',
					'abc' => 'abc',
					3     => 'abc',
					4     => '2',
					5     => 'two',
				)
				: array(
					1     => '1',
					0     => '1',
					'abc' => 'abc',
					2     => 'two',
					3     => 'two',
				),
		);

		// PDO::FETCH_NUM
		yield 'PDO::FETCH_NUM' => array(
			"SELECT 1, 'abc', 2, 'two' as `2`",
			PDO::FETCH_NUM,
			array( '1', 'abc', '2', 'two' ),
		);

		// PDO::FETCH_ASSOC
		yield 'PDO::FETCH_ASSOC' => array(
			"SELECT 1, 'abc', 2, 'two' as `2`",
			PDO::FETCH_ASSOC,
			array(
				1     => '1',
				'abc' => 'abc',
				2     => 'two',
			),
		);

		// PDO::FETCH_NAMED
		yield 'PDO::FETCH_NAMED' => array(
			"SELECT 1, 'abc', 2, 'two' as `2`",
			PDO::FETCH_NAMED,
			array(
				1     => '1',
				'abc' => 'abc',
				2     => array( '2', 'two' ),
			),
		);

		// PDO::FETCH_OBJ
		yield 'PDO::FETCH_OBJ' => array(
			"SELECT 1, 'abc', 2, 'two' as `2`",
			PDO::FETCH_OBJ,
			(object) array(
				1     => '1',
				'abc' => 'abc',
				2     => 'two',
			),
		);
	}

	private function remove_database_files( string $path ): void {
		foreach ( array( $path, $path . '-wal', $path . '-shm', $path . '-journal' ) as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
	}
}
