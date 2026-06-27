<?php

require_once __DIR__ . '/WP_DuckDB_TestCase.php';

/**
 * @group duckdb
 */
class WP_DuckDB_Connection_Tests extends WP_DuckDB_TestCase {
	public function test_result_statement_fetch_named_preserves_duplicate_columns(): void {
		$stmt = new WP_DuckDB_Result_Statement(
			array( 'id', 'id', 'name' ),
			array(
				array( 1, 2, 'Ada' ),
			)
		);

		$this->assertSame(
			array(
				'id'   => array( 1, 2 ),
				'name' => 'Ada',
			),
			$stmt->fetch( PDO::FETCH_NAMED )
		);
		$this->assertFalse( $stmt->fetch() );
	}

	public function test_result_statement_fetch_column_preserves_nulls_and_validates_indexes(): void {
		$stmt = new WP_DuckDB_Result_Statement(
			array( 'id', 'label' ),
			array(
				array( 1, 'first' ),
				array( 2, null ),
			)
		);

		$this->assertSame( 'first', $stmt->fetchColumn( 1 ) );
		$this->assertNull( $stmt->fetchColumn( 1 ) );
		$this->assertFalse( $stmt->fetchColumn( 1 ) );

		$stmt = new WP_DuckDB_Result_Statement( array( 'id' ), array( array( 1 ) ) );
		if ( PHP_VERSION_ID < 80000 ) {
			$this->expectException( PDOException::class );
			$this->expectExceptionMessage( 'Invalid column index' );
		} else {
			$this->expectException( ValueError::class );
			$this->expectExceptionMessage( 'Invalid column index' );
		}
		$stmt->fetchColumn( 1 );
	}

	public function test_result_statement_fetch_all_column_key_pair_class_and_func_modes(): void {
		$stmt = new WP_DuckDB_Result_Statement(
			array( 'id', 'name' ),
			array(
				array( 1, 'Ada' ),
				array( 2, 'Grace' ),
			)
		);
		$this->assertSame( array( 'Ada', 'Grace' ), $stmt->fetchAll( PDO::FETCH_COLUMN, 1 ) );

		$stmt = new WP_DuckDB_Result_Statement(
			array( 'id', 'name' ),
			array(
				array( 1, 'Ada' ),
				array( 2, 'Grace' ),
			)
		);
		$this->assertSame(
			array(
				1 => 'Ada',
				2 => 'Grace',
			),
			$stmt->fetchAll( PDO::FETCH_KEY_PAIR )
		);

		$stmt = new WP_DuckDB_Result_Statement( array( 'name' ), array( array( 'Ada' ) ) );
		$rows = $stmt->fetchAll( PDO::FETCH_CLASS, stdClass::class );
		$this->assertCount( 1, $rows );
		$this->assertInstanceOf( stdClass::class, $rows[0] );
		$this->assertSame( 'Ada', $rows[0]->name );

		$stmt = new WP_DuckDB_Result_Statement(
			array( 'first', 'second' ),
			array(
				array( 'a', 'b' ),
				array( 'c', 'd' ),
			)
		);
		$this->assertSame(
			array( 'a:b', 'c:d' ),
			$stmt->fetchAll(
				PDO::FETCH_FUNC,
				function ( $first, $second ) {
					return $first . ':' . $second;
				}
			)
		);
	}

	public function test_result_statement_cursor_and_metadata_methods_match_pdo_shape(): void {
		$stmt = new WP_DuckDB_Result_Statement(
			array( 'id', 'name' ),
			array(
				array( 1, 'Ada' ),
				array( 2, 'Grace' ),
			),
			0,
			array(
				array(
					'name'              => 'id',
					'native_type'       => 'BIGINT',
					'mysqli:orgname'    => 'id',
					'mysqli:orgtable'   => 'users',
					'mysqli:custom_key' => 'preserved',
				),
			)
		);

		$this->assertSame( 2, $stmt->columnCount() );
		$this->assertSame(
			array(
				'name'              => 'id',
				'native_type'       => 'BIGINT',
				'mysqli:orgname'    => 'id',
				'mysqli:orgtable'   => 'users',
				'mysqli:custom_key' => 'preserved',
			),
			$stmt->getColumnMeta( 0 )
		);
		$this->assertSame( array( 'name' => 'name' ), $stmt->getColumnMeta( 1 ) );
		$this->assertFalse( $stmt->getColumnMeta( 2 ) );
		$this->assertSame( '00000', $stmt->errorCode() );
		$this->assertSame( array( '00000', null, null ), $stmt->errorInfo() );
		$this->assertFalse( $stmt->nextRowset() );
		$this->assertTrue( $stmt->closeCursor() );
		$this->assertFalse( $stmt->fetch() );
	}

	public function test_result_classification_keeps_select_count_and_success_columns_fetchable(): void {
		$connection = new WP_DuckDB_Connection( array( 'duckdb' => new stdClass() ) );

		$count = $connection->create_statement_from_result(
			$this->createDuckDBResult( array( 'Count' ), array( array( 123 ) ) ),
			'SELECT 123 AS Count'
		);

		$this->assertSame( 1, $count->columnCount() );
		$this->assertSame( 0, $count->rowCount() );
		$this->assertSame( array( 'Count' => 123 ), $count->fetch( PDO::FETCH_ASSOC ) );

		$success = $connection->create_statement_from_result(
			$this->createDuckDBResult( array( 'Success' ), array( array( 1 ) ) ),
			'SELECT 1 AS Success'
		);

		$this->assertSame( 1, $success->columnCount() );
		$this->assertSame( 0, $success->rowCount() );
		$this->assertSame( array( 'Success' => 1 ), $success->fetch( PDO::FETCH_ASSOC ) );
	}

	public function test_result_classification_maps_raw_dml_count_to_affected_rows(): void {
		$connection = new WP_DuckDB_Connection( array( 'duckdb' => new stdClass() ) );

		$sql_statements = array(
			'INSERT INTO items VALUES (1)',
			'UPDATE items SET name = ? WHERE id = ?',
			'DELETE FROM items WHERE id = ?',
			"INSERT OR REPLACE INTO items VALUES (1, 'one')",
		);

		foreach ( $sql_statements as $sql ) {
			$stmt = $connection->create_statement_from_result(
				$this->createDuckDBResult( array( 'Count' ), array( array( 2 ) ) ),
				$sql
			);

			$this->assertSame( 0, $stmt->columnCount(), 'Column count mismatch for SQL: ' . $sql );
			$this->assertSame( 2, $stmt->rowCount(), 'Row count mismatch for SQL: ' . $sql );
			$this->assertFalse( $stmt->fetch(), 'Fetch mismatch for SQL: ' . $sql );
		}
	}

	public function test_result_classification_maps_raw_command_results_to_empty_statement(): void {
		$connection = new WP_DuckDB_Connection( array( 'duckdb' => new stdClass() ) );

		$results = array(
			array(
				'sql'     => 'CREATE TABLE items (id INTEGER)',
				'columns' => array( 'Count' ),
				'rows'    => array(),
			),
			array(
				'sql'     => 'DROP TABLE items',
				'columns' => array( 'Success' ),
				'rows'    => array(),
			),
			array(
				'sql'     => 'COMMIT',
				'columns' => array( 'Success' ),
				'rows'    => array(),
			),
		);

		foreach ( $results as $result ) {
			$stmt = $connection->create_statement_from_result(
				$this->createDuckDBResult( $result['columns'], $result['rows'] ),
				$result['sql']
			);

			$this->assertSame( 0, $stmt->columnCount(), 'Column count mismatch for SQL: ' . $result['sql'] );
			$this->assertSame( 0, $stmt->rowCount(), 'Row count mismatch for SQL: ' . $result['sql'] );
			$this->assertFalse( $stmt->fetch(), 'Fetch mismatch for SQL: ' . $result['sql'] );
		}
	}

	public function test_prepared_statement_passes_sql_context_to_result_classification(): void {
		$connection = new WP_DuckDB_Connection( array( 'duckdb' => new stdClass() ) );

		$count        = new WP_DuckDB_Prepared_Statement(
			$connection,
			$this->createDuckDBPreparedStatement( array( 'Count' ), array( array( 123 ) ) ),
			'SELECT ? AS Count'
		);
		$count_result = $count->execute( array( 123 ) );

		$this->assertSame( 1, $count_result->columnCount() );
		$this->assertSame( 0, $count_result->rowCount() );
		$this->assertSame( array( 'Count' => 123 ), $count_result->fetch( PDO::FETCH_ASSOC ) );

		$insert        = new WP_DuckDB_Prepared_Statement(
			$connection,
			$this->createDuckDBPreparedStatement( array( 'Count' ), array( array( 1 ) ) ),
			'INSERT INTO items VALUES (?)'
		);
		$insert_result = $insert->execute( array( 1 ) );

		$this->assertSame( 0, $insert_result->columnCount() );
		$this->assertSame( 1, $insert_result->rowCount() );

		$success        = new WP_DuckDB_Prepared_Statement(
			$connection,
			$this->createDuckDBPreparedStatement( array( 'Success' ), array( array( 1 ) ) ),
			'SELECT ? AS Success'
		);
		$success_result = $success->execute( array( 1 ) );

		$this->assertSame( 1, $success_result->columnCount() );
		$this->assertSame( 0, $success_result->rowCount() );
		$this->assertSame( array( 'Success' => 1 ), $success_result->fetch( PDO::FETCH_ASSOC ) );
	}

	public function test_query_failure_wraps_native_exception_with_stable_surface(): void {
		$previous   = new RuntimeException( 'native syntax failure', 123 );
		$connection = new WP_DuckDB_Connection(
			array(
				'duckdb' => new class( $previous ) {
					private $previous;

					public function __construct( Throwable $previous ) {
						$this->previous = $previous;
					}

					public function query( string $sql ) {
						throw $this->previous;
					}
				},
			)
		);

		$this->assertDuckDBDriverExceptionSurface(
			'DuckDB query failed: native syntax failure',
			$previous,
			function () use ( $connection ): void {
				$connection->query( 'SELECT BROKEN' );
			}
		);
	}

	public function test_prepare_failure_wraps_native_exception_with_stable_surface(): void {
		$previous   = new RuntimeException( 'native prepare failure', 456 );
		$connection = new WP_DuckDB_Connection(
			array(
				'duckdb' => new class( $previous ) {
					private $previous;

					public function __construct( Throwable $previous ) {
						$this->previous = $previous;
					}

					public function preparedStatement( string $sql ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
						throw $this->previous;
					}
				},
			)
		);

		$this->assertDuckDBDriverExceptionSurface(
			'Failed to prepare DuckDB query: native prepare failure',
			$previous,
			function () use ( $connection ): void {
				$connection->prepare( 'SELECT ?' );
			}
		);
	}

	public function test_prepared_statement_execute_failure_wraps_native_exception_with_stable_surface(): void {
		$previous   = new RuntimeException( 'native execute failure', 789 );
		$connection = new WP_DuckDB_Connection( array( 'duckdb' => new stdClass() ) );
		$statement  = new WP_DuckDB_Prepared_Statement(
			$connection,
			new class( $previous ) {
				private $previous;

				public function __construct( Throwable $previous ) {
					$this->previous = $previous;
				}

				public function bindParam( $parameter, $value ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
				}

				public function execute() {
					throw $this->previous;
				}
			},
			'SELECT ?'
		);

		$this->assertDuckDBDriverExceptionSurface(
			'DuckDB prepared statement failed: native execute failure',
			$previous,
			function () use ( $statement ): void {
				$statement->execute( array( 1 ) );
			}
		);
	}

	public function test_prepared_statement_bind_failure_wraps_native_exception_with_stable_surface(): void {
		$previous   = new RuntimeException( 'native bind failure', 901 );
		$connection = new WP_DuckDB_Connection( array( 'duckdb' => new stdClass() ) );
		$statement  = new WP_DuckDB_Prepared_Statement(
			$connection,
			new class( $previous ) {
				private $previous;

				public function __construct( Throwable $previous ) {
					$this->previous = $previous;
				}

				public function bindParam( $parameter, $value ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
					throw $this->previous;
				}

				public function execute() {
					return null;
				}
			},
			'SELECT ?'
		);

		$this->assertDuckDBDriverExceptionSurface(
			'DuckDB prepared statement failed: native bind failure',
			$previous,
			function () use ( $statement ): void {
				$statement->execute( array( 1 ) );
			}
		);
	}

	public function test_successful_statement_error_info_remains_clear_after_failure_characterization(): void {
		$stmt = new WP_DuckDB_Result_Statement( array(), array() );

		$this->assertSame( '00000', $stmt->errorCode() );
		$this->assertSame( array( '00000', null, null ), $stmt->errorInfo() );
	}

	public function test_in_memory_connection_executes_query(): void {
		$this->requireDuckDBRuntime();

		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$stmt       = $connection->query( "SELECT 1 AS one, 'abc' AS label" );

		$this->assertSame( 2, $stmt->columnCount() );
		$this->assertSame(
			array(
				'one'   => 1,
				'label' => 'abc',
			),
			$stmt->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertFalse( $stmt->fetch() );
	}

	public function test_select_count_alias_is_fetchable_result_set(): void {
		$this->requireDuckDBRuntime();

		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$stmt       = $connection->query( 'SELECT 123 AS Count' );

		$this->assertSame( 1, $stmt->columnCount() );
		$this->assertSame( 0, $stmt->rowCount() );
		$this->assertSame( array( 'Count' => 123 ), $stmt->fetch( PDO::FETCH_ASSOC ) );
		$this->assertFalse( $stmt->fetch() );
	}

	public function test_select_success_alias_is_fetchable_result_set(): void {
		$this->requireDuckDBRuntime();

		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$stmt       = $connection->query( 'SELECT 1 AS Success' );

		$this->assertSame( 1, $stmt->columnCount() );
		$this->assertSame( 0, $stmt->rowCount() );
		$this->assertSame( array( 'Success' => 1 ), $stmt->fetch( PDO::FETCH_ASSOC ) );
		$this->assertFalse( $stmt->fetch() );
	}

	public function test_table_select_count_alias_is_fetchable_result_set(): void {
		$this->requireDuckDBRuntime();

		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->query( 'CREATE TABLE count_alias_items (id INTEGER, label VARCHAR)' );
		$connection->query( "INSERT INTO count_alias_items VALUES (1, 'one'), (2, 'two')" );

		$stmt = $connection->query( 'SELECT id AS Count FROM count_alias_items ORDER BY id' );

		$this->assertSame( 1, $stmt->columnCount() );
		$this->assertSame( 0, $stmt->rowCount() );
		$this->assertSame(
			array(
				array( 'Count' => 1 ),
				array( 'Count' => 2 ),
			),
			$stmt->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_table_select_success_alias_is_fetchable_result_set(): void {
		$this->requireDuckDBRuntime();

		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->query( 'CREATE TABLE success_alias_items (id INTEGER, label VARCHAR)' );
		$connection->query( "INSERT INTO success_alias_items VALUES (1, 'one'), (2, 'two')" );

		$stmt = $connection->query( 'SELECT id AS Success FROM success_alias_items ORDER BY id' );

		$this->assertSame( 1, $stmt->columnCount() );
		$this->assertSame( 0, $stmt->rowCount() );
		$this->assertSame(
			array(
				array( 'Success' => 1 ),
				array( 'Success' => 2 ),
			),
			$stmt->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_insert_result_reports_affected_rows(): void {
		$this->requireDuckDBRuntime();

		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->query( 'CREATE TABLE t (id INTEGER, label VARCHAR)' );
		$stmt = $connection->query( "INSERT INTO t VALUES (1, 'one'), (2, 'two')" );

		$this->assertSame( 0, $stmt->columnCount() );
		$this->assertSame( 2, $stmt->rowCount() );
		$this->assertFalse( $stmt->fetch() );
	}

	public function test_command_success_result_is_empty_statement(): void {
		$this->requireDuckDBRuntime();

		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$stmt       = $connection->query( 'CREATE TABLE success_result_items (id INTEGER)' );

		$this->assertSame( 0, $stmt->columnCount() );
		$this->assertSame( 0, $stmt->rowCount() );
		$this->assertFalse( $stmt->fetch() );
	}

	public function test_write_results_report_affected_rows(): void {
		$this->requireDuckDBRuntime();

		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->query( 'CREATE TABLE write_counts (id INTEGER PRIMARY KEY, label VARCHAR)' );

		$insert = $connection->query( "INSERT INTO write_counts VALUES (1, 'one'), (2, 'two')" );
		$this->assertSame( 0, $insert->columnCount() );
		$this->assertSame( 2, $insert->rowCount() );

		$update = $connection->query( "UPDATE write_counts SET label = 'updated' WHERE id = 1" );
		$this->assertSame( 0, $update->columnCount() );
		$this->assertSame( 1, $update->rowCount() );

		$replace = $connection->query( "INSERT OR REPLACE INTO write_counts VALUES (2, 'replaced')" );
		$this->assertSame( 0, $replace->columnCount() );
		$this->assertSame( 1, $replace->rowCount() );

		$delete = $connection->query( 'DELETE FROM write_counts WHERE id IN (1, 2)' );
		$this->assertSame( 0, $delete->columnCount() );
		$this->assertSame( 2, $delete->rowCount() );
	}

	public function test_prepared_statement_binds_positional_parameters(): void {
		$this->requireDuckDBRuntime();

		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->query( 'CREATE TABLE t (id INTEGER, label VARCHAR)' );
		$insert = $connection->prepare( 'INSERT INTO t VALUES (?, ?)' )->execute( array( 1, 'first' ) );
		$this->assertSame( 1, $insert->rowCount() );

		$stmt = $connection->prepare( 'SELECT label FROM t WHERE id = ?' )->execute( array( 1 ) );
		$this->assertSame( 'first', $stmt->fetchColumn() );
	}

	public function test_prepared_select_count_alias_is_fetchable_result_set(): void {
		$this->requireDuckDBRuntime();

		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$stmt       = $connection->prepare( 'SELECT ? AS Count' )->execute( array( 123 ) );

		$this->assertSame( 1, $stmt->columnCount() );
		$this->assertSame( 0, $stmt->rowCount() );
		$this->assertSame( array( 'Count' => 123 ), $stmt->fetch( PDO::FETCH_ASSOC ) );
		$this->assertFalse( $stmt->fetch() );
	}

	public function test_prepared_select_success_alias_is_fetchable_result_set(): void {
		$this->requireDuckDBRuntime();

		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$stmt       = $connection->prepare( 'SELECT ? AS Success' )->execute( array( 1 ) );

		$this->assertSame( 1, $stmt->columnCount() );
		$this->assertSame( 0, $stmt->rowCount() );
		$this->assertSame( array( 'Success' => 1 ), $stmt->fetch( PDO::FETCH_ASSOC ) );
		$this->assertFalse( $stmt->fetch() );
	}

	public function test_transactions_commit_and_rollback(): void {
		$this->requireDuckDBRuntime();

		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->query( 'CREATE TABLE t (id INTEGER)' );

		$this->assertFalse( $connection->inTransaction() );
		$this->assertTrue( $connection->beginTransaction() );
		$this->assertTrue( $connection->inTransaction() );
		$connection->query( 'INSERT INTO t VALUES (1)' );
		$this->assertTrue( $connection->rollback() );
		$this->assertFalse( $connection->inTransaction() );
		$this->assertSame( 0, $connection->query( 'SELECT COUNT(*) AS c FROM t' )->fetchColumn() );

		$connection->begin_transaction();
		$connection->query( 'INSERT INTO t VALUES (2)' );
		$this->assertTrue( $connection->commit() );
		$this->assertSame( 1, $connection->query( 'SELECT COUNT(*) AS c FROM t' )->fetchColumn() );
	}

	public function test_transaction_state_errors_are_explicit(): void {
		$this->requireDuckDBRuntime();

		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );

		$this->expectException( WP_DuckDB_Driver_Exception::class );
		$this->expectExceptionMessage( 'DuckDB transaction is not active.' );
		$connection->commit();
	}

	public function test_nested_transaction_errors_are_explicit(): void {
		$this->requireDuckDBRuntime();

		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->beginTransaction();

		try {
			$this->expectException( WP_DuckDB_Driver_Exception::class );
			$this->expectExceptionMessage( 'DuckDB transaction is already active.' );
			$connection->beginTransaction();
		} finally {
			$connection->rollback();
		}
	}

	public function test_file_connection_persists_data(): void {
		$this->requireDuckDBRuntime();

		$path = tempnam( sys_get_temp_dir(), 'wp_duckdb_' );
		unlink( $path );

		try {
			$connection = new WP_DuckDB_Connection( array( 'path' => $path ) );
			$connection->query( 'CREATE TABLE t (id INTEGER)' );
			$connection->query( 'INSERT INTO t VALUES (7)' );
			unset( $connection );

			$connection = new WP_DuckDB_Connection( array( 'path' => $path ) );
			$this->assertSame( 7, $connection->query( 'SELECT id FROM t' )->fetchColumn() );
		} finally {
			$files = glob( $path . '*' );
			if ( false === $files ) {
				$files = array();
			}
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
		}
	}

	public function test_quote_identifier_uses_duckdb_double_quotes(): void {
		$duckdb = new WP_DuckDB_Connection( array( 'duckdb' => new stdClass() ) );

		$this->assertSame( '"table""name"', $duckdb->quote_identifier( 'table"name' ) );
	}

	private function createDuckDBResult( array $columns, array $rows ) {
		return new class( $columns, $rows ) {
			private $columns;
			private $rows;

			public function __construct( array $columns, array $rows ) {
				$this->columns = $columns;
				$this->rows    = $rows;
			}

			public function columnNames(): ArrayIterator { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
				return new ArrayIterator( $this->columns );
			}

			public function rows( bool $assoc ): array {
				$rows = array();
				foreach ( $this->rows as $row ) {
					$rows[] = array_combine( $this->columns, $row );
				}
				return $rows;
			}
		};
	}

	private function createDuckDBPreparedStatement( array $columns, array $rows ) {
		$result = $this->createDuckDBResult( $columns, $rows );

		return new class( $result ) {
			private $result;

			public function __construct( $result ) {
				$this->result = $result;
			}

			public function bindParam( $parameter, $value ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
			}

			public function execute() {
				return $this->result;
			}
		};
	}

	private function assertDuckDBDriverExceptionSurface( string $message_prefix, Throwable $previous, callable $callback ): void {
		try {
			$callback();
			$this->fail( 'Expected WP_DuckDB_Driver_Exception.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringStartsWith( $message_prefix, $e->getMessage() );
			$this->assertSame( 'HY000', $e->getCode() );
			$this->assertSame( $previous, $e->getPrevious() );
		}
	}
}
