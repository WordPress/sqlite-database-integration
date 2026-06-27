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

	public function test_result_statement_fetch_all_group_and_unique_modes_match_pdo_shape(): void {
		$stmt = new WP_DuckDB_Result_Statement(
			array( 'kind', 'name', 'visits' ),
			array(
				array( 'core', 'Ada', 1 ),
				array( 'plugin', 'Grace', 2 ),
				array( 'core', 'Linus', 3 ),
			)
		);

		$this->assertSame(
			array(
				'core'   => array(
					array(
						'name'   => 'Ada',
						'visits' => 1,
					),
					array(
						'name'   => 'Linus',
						'visits' => 3,
					),
				),
				'plugin' => array(
					array(
						'name'   => 'Grace',
						'visits' => 2,
					),
				),
			),
			$stmt->fetchAll( PDO::FETCH_GROUP | PDO::FETCH_ASSOC )
		);
		$this->assertFalse( $stmt->fetch() );

		$stmt = new WP_DuckDB_Result_Statement(
			array( 'kind', 'name', 'visits' ),
			array(
				array( 'core', 'Ada', 1 ),
				array( 'plugin', 'Grace', 2 ),
				array( 'core', 'Linus', 3 ),
			)
		);

		$this->assertSame(
			array(
				'core'   => array(
					'name'   => 'Linus',
					'visits' => 3,
				),
				'plugin' => array(
					'name'   => 'Grace',
					'visits' => 2,
				),
			),
			$stmt->fetchAll( PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC )
		);

		$stmt = new WP_DuckDB_Result_Statement(
			array( 'kind', 'name' ),
			array(
				array( 'core', 'Ada' ),
				array( 'plugin', 'Grace' ),
				array( 'core', 'Linus' ),
			)
		);

		$this->assertSame(
			array(
				'core'   => array( 'Ada', 'Linus' ),
				'plugin' => array( 'Grace' ),
			),
			$stmt->fetchAll( PDO::FETCH_GROUP | PDO::FETCH_COLUMN )
		);

		$stmt = new WP_DuckDB_Result_Statement(
			array( 'kind', 'name' ),
			array(
				array( 'core', 'Ada' ),
				array( 'plugin', 'Grace' ),
				array( 'core', 'Linus' ),
			)
		);

		$this->assertSame(
			array(
				'core'   => 'Linus',
				'plugin' => 'Grace',
			),
			$stmt->fetchAll( PDO::FETCH_UNIQUE | PDO::FETCH_COLUMN )
		);
	}

	public function test_result_statement_fetch_mode_reference_provider(): void {
		$cases = array(
			'fetch_both'     => array(
				'columns'  => array( 'id', 'name' ),
				'rows'     => array(
					array( 1, 'Ada' ),
				),
				'mode'     => PDO::FETCH_BOTH,
				'method'   => 'fetch',
				'expected' => array(
					'id'   => 1,
					'name' => 'Ada',
					0      => 1,
					1      => 'Ada',
				),
			),
			'fetch_num'      => array(
				'columns'  => array( 'id', 'name' ),
				'rows'     => array(
					array( 1, 'Ada' ),
				),
				'mode'     => PDO::FETCH_NUM,
				'method'   => 'fetch',
				'expected' => array( 1, 'Ada' ),
			),
			'fetch_assoc'    => array(
				'columns'  => array( '1', 'abc', '2', '2' ),
				'rows'     => array(
					array( '1', 'abc', '2', 'two' ),
				),
				'mode'     => PDO::FETCH_ASSOC,
				'method'   => 'fetch',
				'expected' => array(
					1     => '1',
					'abc' => 'abc',
					2     => 'two',
				),
			),
			'fetch_named'    => array(
				'columns'  => array( 'id', 'id', 'name' ),
				'rows'     => array(
					array( 1, 2, 'Ada' ),
				),
				'mode'     => PDO::FETCH_NAMED,
				'method'   => 'fetch',
				'expected' => array(
					'id'   => array( 1, 2 ),
					'name' => 'Ada',
				),
			),
			'fetch_obj'      => array(
				'columns'  => array( 'id', 'name' ),
				'rows'     => array(
					array( 1, 'Ada' ),
				),
				'mode'     => PDO::FETCH_OBJ,
				'method'   => 'fetch',
				'expected' => (object) array(
					'id'   => 1,
					'name' => 'Ada',
				),
			),
			'fetch_column'   => array(
				'columns'  => array( 'id', 'name' ),
				'rows'     => array(
					array( 1, 'Ada' ),
					array( 2, 'Grace' ),
				),
				'mode'     => PDO::FETCH_COLUMN,
				'args'     => array( 1 ),
				'method'   => 'fetchAll',
				'expected' => array( 'Ada', 'Grace' ),
			),
			'fetch_key_pair' => array(
				'columns'  => array( 'id', 'name' ),
				'rows'     => array(
					array( 1, 'Ada' ),
					array( 2, 'Grace' ),
				),
				'mode'     => PDO::FETCH_KEY_PAIR,
				'method'   => 'fetchAll',
				'expected' => array(
					1 => 'Ada',
					2 => 'Grace',
				),
			),
			'fetch_class'    => array(
				'columns'  => array( 'id', 'name' ),
				'rows'     => array(
					array( 1, 'Ada' ),
				),
				'mode'     => PDO::FETCH_CLASS,
				'args'     => array( stdClass::class ),
				'method'   => 'fetch',
				'expected' => (object) array(
					'id'   => 1,
					'name' => 'Ada',
				),
			),
			'fetch_func'     => array(
				'columns'  => array( 'first', 'second' ),
				'rows'     => array(
					array( 'a', 'b' ),
					array( 'c', 'd' ),
				),
				'mode'     => PDO::FETCH_FUNC,
				'args'     => array(
					function ( $first, $second ) {
						return $first . ':' . $second;
					},
				),
				'method'   => 'fetchAll',
				'expected' => array( 'a:b', 'c:d' ),
			),
		);

		foreach ( $cases as $label => $case ) {
			$stmt = new WP_DuckDB_Result_Statement( $case['columns'], $case['rows'] );
			$args = $case['args'] ?? array();

			if ( 'fetchAll' === $case['method'] ) {
				$actual = $stmt->fetchAll( $case['mode'], ...$args );
			} else {
				$this->assertTrue( $stmt->setFetchMode( $case['mode'], ...$args ), $label );
				$actual = $stmt->fetch();
			}

			if ( is_object( $case['expected'] ) ) {
				$this->assertInstanceOf( get_class( $case['expected'] ), $actual, $label );
				$this->assertSame( (array) $case['expected'], (array) $actual, $label );
			} else {
				$this->assertSame( $case['expected'], $actual, $label );
			}
			$this->assertFalse( $stmt->fetch(), $label );
		}
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

	public function test_live_duckdb_statement_surface_provider(): void {
		$this->requireDuckDBRuntime();

		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->query( 'CREATE TABLE live_statement_surface (id INTEGER PRIMARY KEY, label VARCHAR)' );
		$connection->query( "INSERT INTO live_statement_surface VALUES (1, 'one'), (2, 'two')" );

		$select_cases = array(
			'count_alias_literal' => array(
				'sql'          => 'SELECT 7 AS Count',
				'column_count' => 1,
				'row_count'    => 0,
				'meta_name'    => 'Count',
				'fetch_assoc'  => array( 'Count' => 7 ),
				'fetch_column' => 7,
				'fetch_all'    => array(
					array( 'Count' => 7 ),
				),
			),
			'table_count_alias'   => array(
				'sql'          => 'SELECT COUNT(*) AS Count FROM live_statement_surface',
				'column_count' => 1,
				'row_count'    => 0,
				'meta_name'    => 'Count',
				'fetch_assoc'  => array( 'Count' => 2 ),
				'fetch_column' => 2,
				'fetch_all'    => array(
					array( 'Count' => 2 ),
				),
			),
			'two_column_select'   => array(
				'sql'          => 'SELECT id, label FROM live_statement_surface ORDER BY id LIMIT 1',
				'column_count' => 2,
				'row_count'    => 0,
				'meta_name'    => 'id',
				'fetch_assoc'  => array(
					'id'    => 1,
					'label' => 'one',
				),
				'fetch_column' => 1,
				'fetch_all'    => array(
					array(
						'id'    => 1,
						'label' => 'one',
					),
				),
			),
			'zero_row_select'     => array(
				'sql'          => 'SELECT id, label FROM live_statement_surface WHERE id = 999',
				'column_count' => 2,
				'row_count'    => 0,
				'meta_name'    => 'id',
				'fetch_assoc'  => false,
				'fetch_column' => false,
				'fetch_all'    => array(),
			),
		);

		foreach ( $select_cases as $label => $case ) {
			$stmt = $connection->query( $case['sql'] );
			$this->assertSame( $case['column_count'], $stmt->columnCount(), $label );
			$this->assertSame( $case['row_count'], $stmt->rowCount(), $label );
			$this->assertSame( $case['meta_name'], $stmt->getColumnMeta( 0 )['name'], $label );
			$this->assertSame( '00000', $stmt->errorCode(), $label );
			$this->assertSame( array( '00000', null, null ), $stmt->errorInfo(), $label );
			$this->assertSame( $case['fetch_assoc'], $stmt->fetch( PDO::FETCH_ASSOC ), $label );
			$this->assertFalse( $stmt->fetch(), $label );

			$this->assertSame( $case['fetch_all'], $connection->query( $case['sql'] )->fetchAll( PDO::FETCH_ASSOC ), $label );
			$this->assertSame( $case['fetch_column'], $connection->query( $case['sql'] )->fetchColumn(), $label );
		}

		$write_cases = array(
			'create_table'       => array(
				'sql'       => 'CREATE TABLE live_statement_writes (id INTEGER PRIMARY KEY, label VARCHAR)',
				'row_count' => 0,
			),
			'insert_rows'        => array(
				'sql'       => "INSERT INTO live_statement_writes VALUES (1, 'one'), (2, 'two')",
				'row_count' => 2,
			),
			'update_changed'     => array(
				'sql'       => "UPDATE live_statement_writes SET label = 'updated' WHERE id = 1",
				'row_count' => 1,
			),
			'update_no_match'    => array(
				'sql'       => "UPDATE live_statement_writes SET label = 'missing' WHERE id = 999",
				'row_count' => 0,
			),
			'insert_or_replace'  => array(
				'sql'       => "INSERT OR REPLACE INTO live_statement_writes VALUES (2, 'replaced')",
				'row_count' => 1,
			),
			'delete_matched_row' => array(
				'sql'       => 'DELETE FROM live_statement_writes WHERE id = 1',
				'row_count' => 1,
			),
		);

		foreach ( $write_cases as $label => $case ) {
			$stmt = $connection->query( $case['sql'] );
			$this->assertSame( 0, $stmt->columnCount(), $label );
			$this->assertSame( $case['row_count'], $stmt->rowCount(), $label );
			$this->assertFalse( $stmt->getColumnMeta( 0 ), $label );
			$this->assertFalse( $stmt->fetch(), $label );
			$this->assertSame( array(), $stmt->fetchAll(), $label );
			$this->assertSame( '00000', $stmt->errorCode(), $label );
			$this->assertSame( array( '00000', null, null ), $stmt->errorInfo(), $label );
		}
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
