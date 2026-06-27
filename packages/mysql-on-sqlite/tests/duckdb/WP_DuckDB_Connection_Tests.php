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

	public function test_insert_result_reports_affected_rows(): void {
		$this->requireDuckDBRuntime();

		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->query( 'CREATE TABLE t (id INTEGER, label VARCHAR)' );
		$stmt = $connection->query( "INSERT INTO t VALUES (1, 'one'), (2, 'two')" );

		$this->assertSame( 0, $stmt->columnCount() );
		$this->assertSame( 2, $stmt->rowCount() );
		$this->assertFalse( $stmt->fetch() );
	}

	public function test_prepared_statement_binds_positional_parameters(): void {
		$this->requireDuckDBRuntime();

		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->query( 'CREATE TABLE t (id INTEGER, label VARCHAR)' );
		$connection->prepare( 'INSERT INTO t VALUES (?, ?)' )->execute( array( 1, 'first' ) );

		$stmt = $connection->prepare( 'SELECT label FROM t WHERE id = ?' )->execute( array( 1 ) );
		$this->assertSame( 'first', $stmt->fetchColumn() );
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
}
