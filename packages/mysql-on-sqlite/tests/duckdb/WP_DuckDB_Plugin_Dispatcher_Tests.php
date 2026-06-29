<?php

/**
 * @group duckdb-plugin
 */
class WP_DuckDB_Plugin_Dispatcher_Tests extends PHPUnit\Framework\TestCase {
	/**
	 * Temporary directories created by the current test.
	 *
	 * @var string[]
	 */
	private $temp_dirs = array();

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		foreach ( array( 'WP_DUCKDB_AUTOLOAD', 'DUCKDB_PHP_AUTOLOAD' ) as $environment_key ) {
			$autoload = getenv( $environment_key );
			if ( is_string( $autoload ) && '' !== $autoload && file_exists( $autoload ) ) {
				require_once $autoload;
				return;
			}
		}
	}

	protected function tearDown(): void {
		foreach ( array_reverse( $this->temp_dirs ) as $temp_dir ) {
			$this->remove_temp_dir( $temp_dir );
		}
		$this->temp_dirs = array();

		parent::tearDown();
	}

	public function test_dispatcher_leaves_mysql_engine_to_wordpress(): void {
		$result = $this->run_dispatcher_script( "'mysql'" );

		$this->assertFalse( $result['died'] );
		$this->assertSame( 'mysql', $result['db_engine'] );
		$this->assertNull( $result['wpdb_class'] );
		$this->assertFalse( $result['sqlite_loaded'] );
		$this->assertFalse( $result['duckdb_loaded'] );
	}

	public function test_dispatcher_loads_sqlite_adapter_for_sqlite_engine(): void {
		$result = $this->run_dispatcher_script( "'sqlite'" );

		$this->assertFalse( $result['died'] );
		$this->assertSame( 'sqlite', $result['db_engine'] );
		$this->assertSame( 'WP_SQLite_DB', $result['wpdb_class'] );
		$this->assertTrue( $result['sqlite_loaded'] );
		$this->assertFalse( $result['duckdb_loaded'] );
	}

	public function test_dispatcher_reports_missing_duckdb_runtime(): void {
		if ( null === WP_DuckDB_Runtime::get_unavailable_reason() ) {
			$this->markTestSkipped( 'DuckDB runtime is available in this environment.' );
		}

		$result = $this->run_dispatcher_script( "'duckdb'" );

		$this->assertTrue( $result['died'] );
		$this->assertSame( 'duckdb', $result['db_engine'] );
		$this->assertSame( 'duckdb_runtime_unavailable', $result['wp_die_code'] );
		$this->assertSame( 'DuckDB runtime is unavailable.', $result['wp_die_title'] );
		$this->assertFalse( $result['sqlite_loaded'] );
	}

	public function test_db_copy_defaults_to_sqlite(): void {
		$result = $this->run_dropin_copy_script( 'db.copy' );

		$this->assertFalse( $result['died'] );
		$this->assertSame( 'sqlite', $result['db_engine'] );
		$this->assertSame( 'sqlite', $result['database_type'] );
		$this->assertSame( '1.8.0', $result['sqlite_dropin_version'] );
		$this->assertNull( $result['duckdb_dropin_version'] );
		$this->assertSame( 'WP_SQLite_DB', $result['wpdb_class'] );
	}

	public function test_db_copy_respects_explicit_duckdb_override(): void {
		$result = $this->run_dropin_copy_script( 'db.copy', "define( 'DB_ENGINE', 'duckdb' );" );

		$this->assertSame( 'duckdb', $result['db_engine'] );
		$this->assertSame( 'duckdb', $result['database_type'] );
		$this->assertSame( '1.8.0', $result['sqlite_dropin_version'] );

		if ( null !== WP_DuckDB_Runtime::get_unavailable_reason() ) {
			$this->assertTrue( $result['died'] );
			$this->assertSame( 'duckdb_runtime_unavailable', $result['wp_die_code'] );
			return;
		}

		$this->assertFalse( $result['died'] );
		$this->assertSame( 'WP_DuckDB_DB', $result['wpdb_class'] );
	}

	public function test_duckdb_copy_defaults_to_duckdb(): void {
		$result = $this->run_dropin_copy_script( 'db-duckdb.copy' );

		$this->assertSame( 'duckdb', $result['db_engine'] );
		$this->assertSame( 'duckdb', $result['database_type'] );
		$this->assertSame( '0.1.0', $result['duckdb_dropin_version'] );

		if ( null !== WP_DuckDB_Runtime::get_unavailable_reason() ) {
			$this->assertTrue( $result['died'] );
			$this->assertSame( 'duckdb_runtime_unavailable', $result['wp_die_code'] );
			return;
		}

		$this->assertFalse( $result['died'] );
		$this->assertSame( 'WP_DuckDB_DB', $result['wpdb_class'] );
	}

	public function test_duckdb_wpdb_reports_spatial_compatible_mysql_version(): void {
		$result = $this->run_duckdb_version_state_script();

		$this->assertSame( '8.0.11', $result['db_version'] );
		$this->assertSame( 'geomcollection', $result['dbdelta_spatial_type'] );
		$this->assertTrue( $result['is_mysql_8011_or_later'] );
		$this->assertTrue( $result['is_before_mysql_8017'] );
		$this->assertFalse( $result['is_mariadb'] );
	}

	public function test_duckdb_wpdb_query_updates_raw_query_state(): void {
		$result = $this->run_raw_query_state_script();

		$this->assertTrue( $result['connected'] );
		$this->assertSame( 1, $result['select_return'] );
		$this->assertSame( 'SELECT 42 AS answer', $result['select_last_query'] );
		$this->assertSame( 1, $result['select_num_rows'] );
		$this->assertSame( '42', $result['select_answer'] );
		$this->assertSame( 2, $result['insert_return'] );
		$this->assertSame( 2, $result['insert_rows_affected'] );
		$this->assertTrue( $result['create_return'] );
		$this->assertSame( 3, $result['num_queries'] );
		$user_client_queries = array_values(
			array_filter(
				$result['client_queries'],
				function ( string $sql ): bool {
					return false === strpos( $sql, '__wp_duckdb_' )
						&& false === strpos( $sql, 'information_schema.tables' )
						&& false === strpos( $sql, 'currval(' );
				}
			)
		);
		$this->assertSame(
			array(
				'CREATE OR REPLACE MACRO date_format(d, f) AS strftime(TRY_CAST(d AS TIMESTAMP), f)',
				'SELECT 42 AS answer',
				'INSERT INTO t VALUES (1), (2)',
				'CREATE TABLE "t" ("id" INTEGER)',
			),
			array_slice( $user_client_queries, 0, 4 )
		);
	}

	public function test_duckdb_wpdb_insert_id_tracks_driver_insert_id(): void {
		$result = $this->run_insert_id_state_script();

		$this->assertTrue( $result['connected'] );
		$this->assertSame( 1, $result['insert_return'] );
		$this->assertSame( 101, $result['insert_id_after_insert'] );
		$this->assertSame( 1, $result['replace_return'] );
		$this->assertSame( 102, $result['insert_id_after_replace'] );
		$this->assertFalse( $result['failed_insert_return'] );
		$this->assertSame( 0, $result['insert_id_after_failed_insert'] );
		$this->assertSame( 'Synthetic insert failure.', $result['last_error_after_failed_insert'] );
		$this->assertSame( 0, $result['successful_select_return'] );
		$this->assertSame( '', $result['last_error_after_success'] );
	}

	public function test_duckdb_wpdb_flush_clears_statement_metadata(): void {
		$result = $this->run_flush_state_script();

		$this->assertTrue( $result['connected'] );
		$this->assertSame( 1, $result['select_return'] );
		$this->assertSame( array( 'answer' ), $result['before_flush_column_names'] );
		$this->assertSame( array(), $result['last_result_after_flush'] );
		$this->assertNull( $result['col_info_after_flush'] );
		$this->assertNull( $result['last_query_after_flush'] );
		$this->assertSame( 0, $result['rows_affected_after_flush'] );
		$this->assertSame( 0, $result['num_rows_after_flush'] );
		$this->assertSame( '', $result['last_error_after_flush'] );
		$this->assertNull( $result['result_after_flush'] );
		$this->assertSame( array(), $result['after_flush_column_names'] );
	}

	public function test_duckdb_wpdb_col_info_uses_statement_metadata(): void {
		$result = $this->run_col_info_state_script();

		$this->assertTrue( $result['connected'] );
		$this->assertSame( 0, $result['select_return'] );
		$this->assertCount( 3, $result['select_col_info'] );

		$this->assertSame( 'ID', $result['select_col_info'][0]['name'] );
		$this->assertSame( 'ID', $result['select_col_info'][0]['orgname'] );
		$this->assertSame( 'wp_posts', $result['select_col_info'][0]['table'] );
		$this->assertSame( 'wp_posts', $result['select_col_info'][0]['orgtable'] );
		$this->assertSame( 'wordpress_test', $result['select_col_info'][0]['db'] );
		$this->assertSame( 20, $result['select_col_info'][0]['length'] );
		$this->assertSame( 63, $result['select_col_info'][0]['charsetnr'] );
		$this->assertSame( 8, $result['select_col_info'][0]['type'] );
		$this->assertSame( 0, $result['select_col_info'][0]['decimals'] );

		$this->assertSame( 'post_title', $result['select_col_info'][1]['name'] );
		$this->assertSame( 'post_title', $result['select_col_info'][1]['orgname'] );
		$this->assertSame( 'wp_posts', $result['select_col_info'][1]['table'] );
		$this->assertSame( 'wp_posts', $result['select_col_info'][1]['orgtable'] );
		$this->assertSame( 764, $result['select_col_info'][1]['length'] );
		$this->assertSame( 255, $result['select_col_info'][1]['charsetnr'] );
		$this->assertSame( 253, $result['select_col_info'][1]['type'] );

		$this->assertSame( 'price', $result['select_col_info'][2]['name'] );
		$this->assertSame( 'price', $result['select_col_info'][2]['orgname'] );
		$this->assertSame( 'wp_posts', $result['select_col_info'][2]['table'] );
		$this->assertSame( 'wp_posts', $result['select_col_info'][2]['orgtable'] );
		$this->assertSame( 'wordpress_test', $result['select_col_info'][2]['db'] );
		$this->assertSame( 12, $result['select_col_info'][2]['length'] );
		$this->assertSame( 63, $result['select_col_info'][2]['charsetnr'] );
		$this->assertSame( 32768, $result['select_col_info'][2]['flags'] );
		$this->assertSame( 246, $result['select_col_info'][2]['type'] );
		$this->assertSame( 2, $result['select_col_info'][2]['decimals'] );
		$this->assertSame( 0, $result['update_return'] );
		$this->assertSame( array(), $result['update_col_info'] );
	}

	public function test_duckdb_wpdb_col_info_defaults_for_name_only_metadata_provider(): void {
		$result = $this->run_col_info_fallback_state_script();
		$cases  = $result['cases'];

		$this->assertTrue( $result['connected'] );

		$expected_cases = array(
			'select_count'      => array( 'post_count' ),
			'found_rows'        => array( 'found_rows' ),
			'show_full_tables'  => array( 'Tables_in_wordpress_test', 'Table_type' ),
			'show_columns'      => array( 'Field', 'Type', 'Null', 'Key', 'Default', 'Extra' ),
			'show_table_status' => array(
				'Name',
				'Engine',
				'Version',
				'Row_format',
				'Rows',
				'Avg_row_length',
				'Data_length',
				'Max_data_length',
				'Index_length',
				'Data_free',
				'Auto_increment',
				'Create_time',
				'Update_time',
				'Check_time',
				'Collation',
				'Checksum',
				'Create_options',
				'Comment',
			),
			'show_create_table' => array( 'Table', 'Create Table' ),
			'show_index'        => array(
				'Table',
				'Non_unique',
				'Key_name',
				'Seq_in_index',
				'Column_name',
				'Collation',
				'Cardinality',
				'Sub_part',
				'Packed',
				'Null',
				'Index_type',
				'Comment',
				'Index_comment',
				'Visible',
				'Expression',
			),
			'check_table'       => array( 'Table', 'Op', 'Msg_type', 'Msg_text' ),
			'analyze_table'     => array( 'Table', 'Op', 'Msg_type', 'Msg_text' ),
			'optimize_table'    => array( 'Table', 'Op', 'Msg_type', 'Msg_text' ),
			'repair_table'      => array( 'Table', 'Op', 'Msg_type', 'Msg_text' ),
			'complex_join'      => array( 'ID', 'post_title', 'meta_value', 'post_title' ),
		);

		foreach ( $expected_cases as $case_name => $expected_names ) {
			$this->assertArrayHasKey( $case_name, $cases );
			$this->assertCount( count( $expected_names ), $cases[ $case_name ], $case_name );

			foreach ( $expected_names as $index => $expected_name ) {
				$this->assertSame(
					array(
						'name'       => $expected_name,
						'orgname'    => $expected_name,
						'table'      => '',
						'orgtable'   => '',
						'def'        => '',
						'db'         => 'wordpress_test',
						'catalog'    => 'def',
						'max_length' => 0,
						'length'     => 0,
						'charsetnr'  => 224,
						'flags'      => 0,
						'type'       => 253,
						'decimals'   => 0,
					),
					$cases[ $case_name ][ $index ],
					$case_name . ' column ' . $expected_name
				);
			}
		}
	}

	public function test_duckdb_wpdb_show_index_non_unique_uses_dbdelta_string_values(): void {
		$result = $this->run_show_index_non_unique_state_script();

		$this->assertTrue( $result['connected'] );
		$this->assertSame( 2, $result['show_index_return'] );
		$this->assertSame( array( '0', '1' ), $result['non_unique_values'] );
		$this->assertSame( array( 'string', 'string' ), $result['non_unique_types'] );
		$this->assertTrue( $result['dbdelta_primary_match'] );
	}

	public function test_duckdb_wpdb_query_surface_provider(): void {
		$result = $this->run_query_surface_state_script();
		$cases  = $result['cases'];

		$this->assertTrue( $result['connected'] );

		$this->assertSame( 1, $cases['select_one_row']['return'] );
		$this->assertSame( 0, $cases['select_one_row']['rows_affected'] );
		$this->assertSame( 1, $cases['select_one_row']['num_rows'] );
		$this->assertSame( 1, $cases['select_one_row']['last_result_count'] );
		$this->assertSame( '42', $cases['select_one_row']['last_result'][0]['answer'] );
		$this->assertSame( 'string', gettype( $cases['select_one_row']['last_result'][0]['answer'] ) );
		$this->assertSame( array( 'answer' ), $cases['select_one_row']['col_info_names'] );
		$this->assertSame( '', $cases['select_one_row']['last_error'] );

		$this->assertSame( 1, $cases['select_mixed_scalars']['return'] );
		$this->assertSame(
			array(
				'comment_ID'      => '1003',
				'comment_post_ID' => '12',
				'truth'           => '1',
				'falsity'         => '0',
				'none'            => null,
				'note'            => 'Anonymous',
			),
			$cases['select_mixed_scalars']['last_result'][0]
		);

		$this->assertSame( 0, $cases['select_zero_rows']['return'] );
		$this->assertSame( 0, $cases['select_zero_rows']['rows_affected'] );
		$this->assertSame( 0, $cases['select_zero_rows']['num_rows'] );
		$this->assertSame( 0, $cases['select_zero_rows']['last_result_count'] );
		$this->assertSame( array( 'ID', 'post_title' ), $cases['select_zero_rows']['col_info_names'] );

		$this->assertSame( 1, $cases['show_full_tables']['return'] );
		$this->assertSame( 1, $cases['show_full_tables']['num_rows'] );
		$this->assertSame(
			array( 'Tables_in_wordpress_test', 'Table_type' ),
			$cases['show_full_tables']['col_info_names']
		);

		$this->assertSame( 2, $cases['show_columns']['return'] );
		$this->assertSame( 2, $cases['show_columns']['num_rows'] );
		$this->assertSame(
			array( 'Field', 'Type', 'Null', 'Key', 'Default', 'Extra' ),
			$cases['show_columns']['col_info_names']
		);

		$this->assertSame( 1, $cases['check_table']['return'] );
		$this->assertSame( 1, $cases['check_table']['num_rows'] );
		$this->assertSame( array( 'Table', 'Op', 'Msg_type', 'Msg_text' ), $cases['check_table']['col_info_names'] );

		$this->assertSame( 1, $cases['insert_row']['return'] );
		$this->assertSame( 1, $cases['insert_row']['rows_affected'] );
		$this->assertSame( 0, $cases['insert_row']['num_rows'] );
		$this->assertSame( 0, $cases['insert_row']['last_result_count'] );
		$this->assertSame( 11, $cases['insert_row']['insert_id'] );
		$this->assertSame( array(), $cases['insert_row']['col_info_names'] );

		$this->assertSame( 1, $cases['update_changed']['return'] );
		$this->assertSame( 1, $cases['update_changed']['rows_affected'] );
		$this->assertSame( 0, $cases['update_changed']['num_rows'] );
		$this->assertSame( array(), $cases['update_changed']['col_info_names'] );

		$this->assertSame( 0, $cases['update_noop']['return'] );
		$this->assertSame( 0, $cases['update_noop']['rows_affected'] );
		$this->assertSame( 0, $cases['update_noop']['num_rows'] );

		$this->assertSame( 0, $cases['update_no_match']['return'] );
		$this->assertSame( 0, $cases['update_no_match']['rows_affected'] );
		$this->assertSame( 0, $cases['update_no_match']['num_rows'] );

		$this->assertSame( 1, $cases['delete_row']['return'] );
		$this->assertSame( 1, $cases['delete_row']['rows_affected'] );
		$this->assertSame( 0, $cases['delete_row']['num_rows'] );

		$this->assertSame( 1, $cases['replace_insert']['return'] );
		$this->assertSame( 1, $cases['replace_insert']['rows_affected'] );
		$this->assertSame( 12, $cases['replace_insert']['insert_id'] );
		$this->assertSame( array(), $cases['replace_insert']['col_info_names'] );

		$this->assertSame( 2, $cases['replace_row']['return'] );
		$this->assertSame( 2, $cases['replace_row']['rows_affected'] );
		$this->assertSame( 12, $cases['replace_row']['insert_id'] );
		$this->assertSame( array(), $cases['replace_row']['col_info_names'] );

		$this->assertTrue( $cases['create_table']['return'] );
		$this->assertSame( 0, $cases['create_table']['rows_affected'] );
		$this->assertSame( 0, $cases['create_table']['num_rows'] );
		$this->assertSame( array(), $cases['create_table']['col_info_names'] );
	}

	public function test_duckdb_wpdb_odku_rows_affected_are_propagated_from_statement(): void {
		$result = $this->run_query_surface_state_script();
		$cases  = $result['cases'];

		$this->assertTrue( $result['connected'] );

		$this->assertSame( 1, $cases['odku_duplicate_changed']['return'] );
		$this->assertSame( 1, $cases['odku_duplicate_changed']['rows_affected'] );
		$this->assertSame( 0, $cases['odku_duplicate_changed']['num_rows'] );
		$this->assertSame( array(), $cases['odku_duplicate_changed']['col_info_names'] );

		$this->assertSame( 1, $cases['odku_duplicate_noop']['return'] );
		$this->assertSame( 1, $cases['odku_duplicate_noop']['rows_affected'] );
		$this->assertSame( 0, $cases['odku_duplicate_noop']['num_rows'] );
		$this->assertSame( array(), $cases['odku_duplicate_noop']['col_info_names'] );
	}

	public function test_duckdb_wpdb_last_error_surface_provider(): void {
		$result = $this->run_query_surface_state_script();
		$cases  = $result['cases'];

		$this->assertTrue( $result['connected'] );

		$this->assertFalse( $cases['select_failure']['return'] );
		$this->assertSame( 'Synthetic select failure.', $cases['select_failure']['last_error'] );
		$this->assertSame( 0, $cases['select_failure']['num_rows'] );
		$this->assertSame( 0, $cases['select_failure']['rows_affected'] );

		$this->assertSame( 1, $cases['select_after_failure']['return'] );
		$this->assertSame( '', $cases['select_after_failure']['last_error'] );
		$this->assertSame( 1, $cases['select_after_failure']['num_rows'] );

		$this->assertFalse( $cases['insert_failure']['return'] );
		$this->assertSame( 'Synthetic insert failure.', $cases['insert_failure']['last_error'] );
		$this->assertSame( 0, $cases['insert_failure']['insert_id'] );
		$this->assertSame( 0, $cases['insert_failure']['num_rows'] );
		$this->assertSame( 0, $cases['insert_failure']['rows_affected'] );

		$this->assertSame( 1, $cases['insert_after_failure']['return'] );
		$this->assertSame( '', $cases['insert_after_failure']['last_error'] );
		$this->assertSame( 13, $cases['insert_after_failure']['insert_id'] );
	}

	public function test_duckdb_wpdb_suppressed_errors_are_recorded_for_ezsql(): void {
		$result = $this->run_error_diagnostic_state_script();

		$this->assertTrue( $result['connected'] );
		$this->assertFalse( $result['old_suppress_errors'] );
		$this->assertTrue( $result['suppress_errors'] );
		$this->assertFalse( $result['failed_return'] );
		$this->assertSame( 'Synthetic select failure.', $result['last_error'] );
		$this->assertSame( 'SELECT BROKEN', $result['last_query'] );
		$this->assertCount( 1, $result['ezsql_error'] );
		$this->assertSame(
			array(
				'query'     => 'SELECT BROKEN',
				'error_str' => 'Synthetic select failure.',
			),
			$result['ezsql_error'][0]
		);
	}

	public function test_duckdb_wpdb_failure_diagnostics_clear_metadata_and_preserve_non_insert_id(): void {
		$result = $this->run_query_surface_state_script();
		$cases  = $result['cases'];

		$this->assertTrue( $result['connected'] );

		$this->assertFalse( $cases['select_failure']['return'] );
		$this->assertSame( array(), $cases['select_failure']['col_info_names'] );
		$this->assertSame( 0, $cases['select_failure']['num_rows'] );
		$this->assertSame( 0, $cases['select_failure']['rows_affected'] );

		$this->assertFalse( $cases['insert_failure']['return'] );
		$this->assertSame( array(), $cases['insert_failure']['col_info_names'] );
		$this->assertSame( 0, $cases['insert_failure']['insert_id'] );
		$this->assertSame( 0, $cases['insert_failure']['num_rows'] );
		$this->assertSame( 0, $cases['insert_failure']['rows_affected'] );

		$this->assertFalse( $cases['update_failure_after_insert']['return'] );
		$this->assertSame( 'Synthetic update failure.', $cases['update_failure_after_insert']['last_error'] );
		$this->assertSame( 13, $cases['update_failure_after_insert']['insert_id'] );
		$this->assertSame( array(), $cases['update_failure_after_insert']['col_info_names'] );
		$this->assertSame( 0, $cases['update_failure_after_insert']['num_rows'] );
		$this->assertSame( 0, $cases['update_failure_after_insert']['rows_affected'] );

		$this->assertTrue( $cases['create_after_update_failure']['return'] );
		$this->assertSame( '', $cases['create_after_update_failure']['last_error'] );
		$this->assertSame( array(), $cases['create_after_update_failure']['col_info_names'] );
		$this->assertSame( 0, $cases['create_after_update_failure']['num_rows'] );
		$this->assertSame( 0, $cases['create_after_update_failure']['rows_affected'] );
	}

	public function test_duckdb_wpdb_rolls_back_only_native_active_transaction_failures(): void {
		$result = $this->run_transaction_cleanup_state_script();
		$cases  = $result['cases'];

		foreach ( $cases as $case_name => $case ) {
			$this->assertTrue( $case['connected'], $case_name );
			$this->assertFalse( $case['return'], $case_name );
		}

		$this->assertSame( 'DuckDB query failed: invalid input.', $cases['native_query_marker']['last_error'] );
		$this->assertSame(
			array( 'BEGIN TRANSACTION', 'ROLLBACK' ),
			$cases['native_query_marker']['connection_queries']
		);
		$this->assertFalse( $cases['native_query_marker']['in_transaction_after'] );

		$this->assertSame(
			'Failed to execute DuckDB write: Failed to prepare DuckDB query: syntax error.',
			$cases['native_prepare_marker']['last_error']
		);
		$this->assertSame(
			array( 'BEGIN TRANSACTION', 'ROLLBACK' ),
			$cases['native_prepare_marker']['connection_queries']
		);
		$this->assertFalse( $cases['native_prepare_marker']['in_transaction_after'] );

		$this->assertSame(
			'Unsupported INSERT statement in DuckDB driver. INSERT IGNORE is not supported.',
			$cases['unsupported_error']['last_error']
		);
		$this->assertSame( array( 'BEGIN TRANSACTION' ), $cases['unsupported_error']['connection_queries'] );
		$this->assertTrue( $cases['unsupported_error']['in_transaction_after'] );

		$this->assertSame(
			'DuckDB driver could not parse MySQL statement: syntax error.',
			$cases['preflight_error']['last_error']
		);
		$this->assertSame( array( 'BEGIN TRANSACTION' ), $cases['preflight_error']['connection_queries'] );
		$this->assertTrue( $cases['preflight_error']['in_transaction_after'] );

		$this->assertSame( 'Plain runtime failure.', $cases['plain_exception']['last_error'] );
		$this->assertSame( array( 'BEGIN TRANSACTION' ), $cases['plain_exception']['connection_queries'] );
		$this->assertTrue( $cases['plain_exception']['in_transaction_after'] );

		$this->assertSame( 'DuckDB query failed: inactive transaction.', $cases['inactive_native_query_marker']['last_error'] );
		$this->assertSame( array(), $cases['inactive_native_query_marker']['connection_queries'] );
		$this->assertFalse( $cases['inactive_native_query_marker']['in_transaction_after'] );

		$this->assertSame(
			'DuckDB query failed: TransactionContext Error: Current transaction is aborted (please ROLLBACK)',
			$cases['inactive_current_aborted_marker']['last_error']
		);
		$this->assertSame( array( 'ROLLBACK' ), $cases['inactive_current_aborted_marker']['connection_queries'] );
		$this->assertFalse( $cases['inactive_current_aborted_marker']['in_transaction_after'] );
	}

	public function test_duckdb_wpdb_information_schema_tables_recovers_untracked_aborted_native_transaction(): void {
		if ( null !== WP_DuckDB_Runtime::get_unavailable_reason() ) {
			$this->markTestSkipped( 'DuckDB runtime is unavailable in this environment.' );
		}

		$result = $this->run_information_schema_recovery_state_script();

		$this->assertTrue( $result['connected'] );
		$this->assertSame( 1, $result['query_return'] );
		$this->assertSame( '', $result['last_error'] );
		$this->assertFalse( $result['in_transaction_after'] );
		$this->assertCount( 1, $result['rows'] );
		$this->assertSame( 'wptests_options', $result['rows'][0]['table'] );
		$this->assertSame( 0, (int) $result['rows'][0]['rows'] );
		$this->assertSame( 0, (int) $result['rows'][0]['bytes'] );
		$this->assertContains(
			'CREATE OR REPLACE TEMP TABLE "__wp_duckdb_transaction_recovery_probe" AS SELECT 1 AS ok',
			$result['duckdb_queries']
		);
		$this->assertContains( 'ROLLBACK', $result['duckdb_queries'] );
	}

	public function test_duckdb_wpdb_site_health_table_rows_report_populated_counts(): void {
		if ( null !== WP_DuckDB_Runtime::get_unavailable_reason() ) {
			$this->markTestSkipped( 'DuckDB runtime is unavailable in this environment.' );
		}

		$result = $this->run_site_health_table_rows_state_script();

		$this->assertTrue( $result['connected'] );
		$this->assertSame( 2, $result['query_return'] );
		$this->assertSame( '', $result['last_error'] );
		$this->assertSame(
			array(
				array(
					'table' => 'wptests_options',
					'rows'  => '3',
					'bytes' => '0',
				),
				array(
					'table' => 'wptests_terms',
					'rows'  => '2',
					'bytes' => '0',
				),
			),
			$result['rows']
		);
	}

	public function test_duckdb_wpdb_db_connect_sets_filtered_sql_mode(): void {
		$result = $this->run_sql_mode_boot_state_script( false );

		$this->assertTrue( $result['connected'] );
		$this->assertTrue( $result['ready'] );
		$this->assertSame( '', $result['last_error'] );
		$this->assertSame(
			array(
				'SELECT @@SESSION.sql_mode',
				"SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'",
			),
			$result['driver_queries']
		);
	}

	public function test_duckdb_wpdb_db_connect_reports_sql_mode_boot_failure(): void {
		$result = $this->run_sql_mode_boot_state_script( true );

		$this->assertFalse( $result['connected'] );
		$this->assertFalse( $result['ready'] );
		$this->assertSame( 'Broken SQL mode read.', $result['last_error'] );
		$this->assertSame(
			array(
				'SELECT @@SESSION.sql_mode',
			),
			$result['driver_queries']
		);
	}

	private function run_dispatcher_script( string $engine_expression ): array {
		$plugin_dir = $this->get_plugin_dir();
		$code       = $this->get_wordpress_stub_code();
		$code      .= "\ndefine( 'DB_ENGINE', {$engine_expression} );\n";
		$code      .= $this->get_dispatcher_include_code( $plugin_dir . '/wp-includes/db.php' );

		return $this->run_isolated_php( $code );
	}

	private function run_dropin_copy_script( string $copy_file, string $prepend_code = '' ): array {
		$plugin_dir    = $this->get_plugin_dir();
		$dropin_source = file_get_contents( $plugin_dir . '/' . $copy_file );
		$dropin_source = str_replace(
			array(
				'{SQLITE_IMPLEMENTATION_FOLDER_PATH}',
				'{SQLITE_PLUGIN}',
			),
			array(
				$plugin_dir,
				'sqlite-database-integration/load.php',
			),
			$dropin_source
		);
		$dropin_file   = $this->create_temp_file( $dropin_source );
		$code          = $this->get_wordpress_stub_code();
		$code         .= "\n{$prepend_code}\n";
		$code         .= $this->get_dispatcher_include_code( $dropin_file );

		try {
			return $this->run_isolated_php( $code );
		} finally {
			if ( is_file( $dropin_file ) ) {
				unlink( $dropin_file );
			}
		}
	}

	private function run_duckdb_version_state_script(): array {
		$plugin_dir = $this->get_plugin_dir();
		$code       = $this->get_wordpress_stub_code();
		$code      .= 'require_once ' . var_export( $plugin_dir . '/wp-includes/duckdb/class-wp-duckdb-db.php', true ) . ";\n";
		$code      .= <<<'PHP'

$db             = new WP_DuckDB_DB( 'wordpress_test' );
$db_version     = $db->db_version();
$db_server_info = $db->db_server_info();
$spatial_type   = 'geometrycollection';

if ( version_compare( $db_version, '8.0.11', '>=' ) && false === strpos( $db_server_info, 'MariaDB' ) ) {
	$spatial_type = 'geomcollection';
}

echo json_encode(
	array(
		'db_version'             => $db_version,
		'db_server_info'         => $db_server_info,
		'dbdelta_spatial_type'   => $spatial_type,
		'is_mysql_8011_or_later' => version_compare( $db_version, '8.0.11', '>=' ),
		'is_before_mysql_8017'   => version_compare( $db_version, '8.0.17', '<' ),
		'is_mariadb'             => false !== strpos( $db_server_info, 'MariaDB' ),
	)
);
PHP;

		return $this->run_isolated_php( $code );
	}

	private function run_sql_mode_boot_state_script( bool $fail_read ): array {
		$plugin_dir  = $this->get_plugin_dir();
		$driver_load = dirname( __DIR__, 2 ) . '/src/load.php';
		$code        = $this->get_wordpress_stub_code();
		$code       .= "\nrequire_once " . var_export( $driver_load, true ) . ";\n";
		$code       .= 'require_once ' . var_export( $plugin_dir . '/wp-includes/duckdb/class-wp-duckdb-db.php', true ) . ";\n";
		$code       .= '$fail_read = ' . ( $fail_read ? 'true' : 'false' ) . ";\n";
		$code       .= <<<'PHP'

class WP_DuckDB_Plugin_SQL_Mode_Test_Driver extends WP_DuckDB_Driver {
	public $queries = array();
	private $fail_read;

	public function __construct( $fail_read ) {
		$this->fail_read = $fail_read;
	}

	public function query( string $sql ): WP_DuckDB_Result_Statement {
		$this->queries[] = $sql;

		if ( 'SELECT @@SESSION.sql_mode' === $sql ) {
			if ( $this->fail_read ) {
				throw new RuntimeException( 'Broken SQL mode read.' );
			}

			return new WP_DuckDB_Result_Statement(
				array( '@@SESSION.sql_mode' ),
				array(
					array( 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION,ANSI' ),
				),
				0
			);
		}

		if ( "SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'" === $sql ) {
			return new WP_DuckDB_Result_Statement( array(), array(), 0 );
		}

		throw new RuntimeException( 'Unexpected query: ' . $sql );
	}
}

$driver                    = new WP_DuckDB_Plugin_SQL_Mode_Test_Driver( $fail_read );
$GLOBALS['@duckdb_driver'] = $driver;
$db                        = new WP_DuckDB_DB( 'wordpress_test' );
$connected                 = $db->db_connect( false );

echo json_encode(
	array(
		'connected'      => $connected,
		'ready'          => $db->ready,
		'last_error'     => $db->last_error,
		'driver_queries' => $driver->queries,
	)
);
PHP;

		return $this->run_isolated_php( $code );
	}

	private function run_error_diagnostic_state_script(): array {
		$plugin_dir  = $this->get_plugin_dir();
		$driver_load = dirname( __DIR__, 2 ) . '/src/load.php';
		$code        = $this->get_wordpress_stub_code();
		$code       .= "\nrequire_once " . var_export( $driver_load, true ) . ";\n";
		$code       .= 'require_once ' . var_export( $plugin_dir . '/wp-includes/duckdb/class-wp-duckdb-db.php', true ) . ";\n";
		$code       .= <<<'PHP'

class WP_DuckDB_Plugin_Error_Diagnostic_Test_Driver extends WP_DuckDB_Driver {
	public function __construct() {}

	public function query( string $sql ): WP_DuckDB_Result_Statement {
		if ( 'SELECT @@SESSION.sql_mode' === $sql ) {
			return new WP_DuckDB_Result_Statement(
				array( '@@SESSION.sql_mode' ),
				array(
					array( 'NO_ENGINE_SUBSTITUTION' ),
				),
				0
			);
		}

		if ( "SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'" === $sql ) {
			return new WP_DuckDB_Result_Statement( array(), array(), 0 );
		}

		if ( 'SELECT BROKEN' === $sql ) {
			throw new WP_DuckDB_Driver_Exception(
				'Synthetic select failure.',
				'HY000',
				new RuntimeException( 'Native synthetic select failure.' )
			);
		}

		throw new RuntimeException( 'Unexpected query: ' . $sql );
	}
}

$EZSQL_ERROR              = array();
$GLOBALS['@duckdb_driver'] = new WP_DuckDB_Plugin_Error_Diagnostic_Test_Driver();
$db                       = new WP_DuckDB_DB( 'wordpress_test' );
$connected                = $db->db_connect( false );
$old_suppress_errors      = $db->suppress_errors( true );
$failed_return            = $db->query( 'SELECT BROKEN' );

echo json_encode(
	array(
		'connected'           => $connected,
		'old_suppress_errors' => $old_suppress_errors,
		'suppress_errors'     => $db->suppress_errors,
		'failed_return'       => $failed_return,
		'last_error'          => $db->last_error,
		'last_query'          => $db->last_query,
		'ezsql_error'         => $EZSQL_ERROR,
	)
);
PHP;

		return $this->run_isolated_php( $code );
	}

	private function run_transaction_cleanup_state_script(): array {
		$plugin_dir  = $this->get_plugin_dir();
		$driver_load = dirname( __DIR__, 2 ) . '/src/load.php';
		$code        = $this->get_wordpress_stub_code();
		$code       .= "\nrequire_once " . var_export( $driver_load, true ) . ";\n";
		$code       .= 'require_once ' . var_export( $plugin_dir . '/wp-includes/duckdb/class-wp-duckdb-db.php', true ) . ";\n";
		$code       .= <<<'PHP'

class WP_DuckDB_Plugin_Transaction_Cleanup_Test_Result {
	private $columns;
	private $rows;

	public function __construct( array $columns = array(), array $rows = array() ) {
		$this->columns = $columns;
		$this->rows    = $rows;
	}

	public function columnNames() {
		return new ArrayIterator( $this->columns );
	}

	public function rows( $assoc = false ) {
		return new ArrayIterator( $this->rows );
	}
}

class WP_DuckDB_Plugin_Transaction_Cleanup_Test_Client {
	public $queries = array();

	public function query( $sql ) {
		$this->queries[] = $sql;
		return new WP_DuckDB_Plugin_Transaction_Cleanup_Test_Result(
			array( 'Success' ),
			array(
				array( 'Success' => true ),
			)
		);
	}
}

class WP_DuckDB_Plugin_Transaction_Cleanup_Test_Driver extends WP_DuckDB_Driver {
	public $queries = array();
	private $connection;
	private $failures;

	public function __construct( WP_DuckDB_Connection $connection, array $failures ) {
		$this->connection = $connection;
		$this->failures   = $failures;
	}

	public function query( string $sql ): WP_DuckDB_Result_Statement {
		$this->queries[] = $sql;

		if ( 'SELECT @@SESSION.sql_mode' === $sql ) {
			return new WP_DuckDB_Result_Statement(
				array( '@@SESSION.sql_mode' ),
				array(
					array( 'NO_ENGINE_SUBSTITUTION' ),
				),
				0
			);
		}

		if ( "SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'" === $sql ) {
			return new WP_DuckDB_Result_Statement( array(), array(), 0 );
		}

		if ( isset( $this->failures[ $sql ] ) ) {
			throw $this->failures[ $sql ];
		}

		return new WP_DuckDB_Result_Statement( array(), array(), 0 );
	}

	public function get_connection(): WP_DuckDB_Connection {
		return $this->connection;
	}
}

function wp_duckdb_plugin_transaction_cleanup_failure( $case_name ) {
	switch ( $case_name ) {
		case 'native_query_marker':
			return new WP_DuckDB_Driver_Exception(
				'DuckDB query failed: invalid input.',
				0,
				new RuntimeException( 'Native query failure.' )
			);

		case 'native_prepare_marker':
			return new WP_DuckDB_Driver_Exception(
				'Failed to execute DuckDB write: Failed to prepare DuckDB query: syntax error.',
				0,
				new RuntimeException( 'Native prepare failure.' )
			);

		case 'unsupported_error':
			return new WP_DuckDB_Driver_Exception(
				'Unsupported INSERT statement in DuckDB driver. INSERT IGNORE is not supported.'
			);

		case 'preflight_error':
			return new WP_DuckDB_Driver_Exception( 'DuckDB driver could not parse MySQL statement: syntax error.' );

			case 'plain_exception':
				return new RuntimeException( 'Plain runtime failure.' );

			case 'inactive_native_query_marker':
				return new WP_DuckDB_Driver_Exception(
					'DuckDB query failed: inactive transaction.',
					0,
					new RuntimeException( 'Inactive native query failure.' )
				);

			case 'inactive_current_aborted_marker':
				return new WP_DuckDB_Driver_Exception(
					'DuckDB query failed: TransactionContext Error: Current transaction is aborted (please ROLLBACK)',
					0,
					new RuntimeException( 'Inactive aborted native transaction.' )
				);
	}

	throw new RuntimeException( 'Unknown cleanup test case: ' . $case_name );
}

function wp_duckdb_plugin_transaction_cleanup_case( $case_name, $active_transaction ) {
	$client     = new WP_DuckDB_Plugin_Transaction_Cleanup_Test_Client();
	$connection = new WP_DuckDB_Connection( array( 'duckdb' => $client ) );
	$sql        = 'SELECT ' . $case_name;
	$driver     = new WP_DuckDB_Plugin_Transaction_Cleanup_Test_Driver(
		$connection,
		array(
			$sql => wp_duckdb_plugin_transaction_cleanup_failure( $case_name ),
		)
	);

	$GLOBALS['@duckdb_driver'] = $driver;
	$db                        = new WP_DuckDB_DB( 'wordpress_test' );
	$connected                 = $db->db_connect( false );
	$db->suppress_errors( true );

	if ( $active_transaction ) {
		$connection->beginTransaction();
	}

	$query_return = $db->query( $sql );

	return array(
		'connected'            => $connected,
		'return'               => $query_return,
		'last_error'           => $db->last_error,
		'connection_queries'   => $client->queries,
		'driver_queries'       => $driver->queries,
		'in_transaction_after' => $connection->inTransaction(),
	);
}

$cases = array(
	'native_query_marker'           => wp_duckdb_plugin_transaction_cleanup_case( 'native_query_marker', true ),
	'native_prepare_marker'         => wp_duckdb_plugin_transaction_cleanup_case( 'native_prepare_marker', true ),
	'unsupported_error'             => wp_duckdb_plugin_transaction_cleanup_case( 'unsupported_error', true ),
	'preflight_error'               => wp_duckdb_plugin_transaction_cleanup_case( 'preflight_error', true ),
	'plain_exception'               => wp_duckdb_plugin_transaction_cleanup_case( 'plain_exception', true ),
	'inactive_native_query_marker'  => wp_duckdb_plugin_transaction_cleanup_case( 'inactive_native_query_marker', false ),
	'inactive_current_aborted_marker' => wp_duckdb_plugin_transaction_cleanup_case( 'inactive_current_aborted_marker', false ),
);

echo json_encode(
	array(
		'cases' => $cases,
	)
);
PHP;

		return $this->run_isolated_php( $code );
	}

	private function run_information_schema_recovery_state_script(): array {
		$plugin_dir  = $this->get_plugin_dir();
		$driver_load = dirname( __DIR__, 2 ) . '/src/load.php';
		$code        = $this->get_wordpress_stub_code();
		$code       .= "\nrequire_once " . var_export( $driver_load, true ) . ";\n";
		$code       .= 'require_once ' . var_export( $plugin_dir . '/wp-includes/duckdb/class-wp-duckdb-db.php', true ) . ";\n";
		$code       .= <<<'PHP'

if ( defined( 'DUCKDB_PHP_AUTOLOAD' ) ) {
	require_once DUCKDB_PHP_AUTOLOAD;
}

$connection         = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
$GLOBALS['@duckdb'] = $connection;
$db                 = new WP_DuckDB_DB( 'wordpress_develop_tests' );
$connected          = $db->db_connect( false );
$db->suppress_errors( true );
$driver             = $GLOBALS['@duckdb_driver'];
$tracked_connection = $driver->get_connection();

$db->query(
	"CREATE TABLE wptests_options (
		option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
		option_name VARCHAR(191) NOT NULL DEFAULT '',
		option_value LONGTEXT NOT NULL
	)"
);

$tracked_connection->query( 'BEGIN TRANSACTION' );
try {
	$tracked_connection->query( "SELECT CAST('not-an-integer' AS INTEGER)" );
} catch ( WP_DuckDB_Driver_Exception $e ) {
}

$query_return = $db->query(
	"SELECT TABLE_NAME AS 'table', TABLE_ROWS AS 'rows',
		SUM(data_length + index_length) as 'bytes'
	FROM information_schema.TABLES
	WHERE TABLE_SCHEMA = 'wordpress_develop_tests'
		AND TABLE_NAME IN ('wptests_comments','wptests_options','wptests_posts','wptests_terms','wptests_users')
	GROUP BY TABLE_NAME"
);

$rows = array_map(
	function ( $row ) {
		return get_object_vars( $row );
	},
	$db->last_result
);

echo json_encode(
	array(
		'connected'            => $connected,
		'query_return'         => $query_return,
		'last_error'           => $db->last_error,
		'rows'                 => $rows,
		'in_transaction_after' => $tracked_connection->inTransaction(),
		'duckdb_queries'       => $driver->get_last_duckdb_queries(),
	)
);
PHP;

		return $this->run_isolated_php( $code );
	}

	private function run_site_health_table_rows_state_script(): array {
		$plugin_dir  = $this->get_plugin_dir();
		$driver_load = dirname( __DIR__, 2 ) . '/src/load.php';
		$code        = $this->get_wordpress_stub_code();
		$code       .= "\nrequire_once " . var_export( $driver_load, true ) . ";\n";
		$code       .= 'require_once ' . var_export( $plugin_dir . '/wp-includes/duckdb/class-wp-duckdb-db.php', true ) . ";\n";
		$code       .= <<<'PHP'

if ( defined( 'DUCKDB_PHP_AUTOLOAD' ) ) {
	require_once DUCKDB_PHP_AUTOLOAD;
}

$connection         = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
$GLOBALS['@duckdb'] = $connection;
$db                 = new WP_DuckDB_DB( 'wordpress_develop_tests' );
$connected          = $db->db_connect( false );
$db->suppress_errors( true );

$db->query(
	"CREATE TABLE wptests_options (
		option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
		option_name VARCHAR(191) NOT NULL DEFAULT '',
		option_value LONGTEXT NOT NULL
	)"
);
$db->query(
	"CREATE TABLE wptests_terms (
		term_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
		name VARCHAR(200) NOT NULL DEFAULT ''
	)"
);
$db->query(
	"INSERT INTO wptests_options (option_name, option_value)
	VALUES ('siteurl', 'https://example.test'), ('home', 'https://example.test'), ('blogname', 'Test')"
);
$db->query( "INSERT INTO wptests_terms (name) VALUES ('one'), ('two')" );

$query_return = $db->query(
	"SELECT TABLE_NAME AS 'table', TABLE_ROWS AS 'rows',
		SUM(data_length + index_length) as 'bytes'
	FROM information_schema.TABLES
	WHERE TABLE_SCHEMA = 'wordpress_develop_tests'
		AND TABLE_NAME IN ('wptests_comments','wptests_options','wptests_posts','wptests_terms','wptests_users')
	GROUP BY TABLE_NAME
	ORDER BY TABLE_NAME"
);

$rows = array_map(
	function ( $row ) {
		return get_object_vars( $row );
	},
	$db->last_result
);

echo json_encode(
	array(
		'connected'    => $connected,
		'query_return' => $query_return,
		'last_error'   => $db->last_error,
		'rows'         => $rows,
	)
);
PHP;

		return $this->run_isolated_php( $code );
	}

	private function run_raw_query_state_script(): array {
		$plugin_dir  = $this->get_plugin_dir();
		$driver_load = dirname( __DIR__, 2 ) . '/src/load.php';
		$code        = $this->get_wordpress_stub_code();
		$code       .= "\nrequire_once " . var_export( $driver_load, true ) . ";\n";
		$code       .= 'require_once ' . var_export( $plugin_dir . '/wp-includes/duckdb/class-wp-duckdb-db.php', true ) . ";\n";
		$code       .= <<<'PHP'

class WP_DuckDB_Plugin_Test_Result {
	private $columns;
	private $rows;

	public function __construct( array $columns, array $rows ) {
		$this->columns = $columns;
		$this->rows    = $rows;
	}

	public function columnNames() {
		return new ArrayIterator( $this->columns );
	}

	public function rows( $assoc = false ) {
		return new ArrayIterator( $this->rows );
	}
}

class WP_DuckDB_Plugin_Test_Client {
	public $queries = array();

	public function query( $sql ) {
		$this->queries[] = $sql;
		$normalized     = strtolower( trim( $sql ) );

		if ( 0 === strpos( $normalized, 'select' ) ) {
			return new WP_DuckDB_Plugin_Test_Result(
				array( 'answer' ),
				array(
					array( 'answer' => 42 ),
				)
			);
		}

		if ( 0 === strpos( $normalized, 'insert' ) ) {
			return new WP_DuckDB_Plugin_Test_Result(
				array( 'Count' ),
				array(
					array( 'Count' => 2 ),
				)
			);
		}

		if ( 0 === strpos( $normalized, 'delete' ) ) {
			return new WP_DuckDB_Plugin_Test_Result(
				array( 'Count' ),
				array(
					array( 'Count' => 0 ),
				)
			);
		}

		if ( 0 === strpos( $normalized, 'create' ) ) {
			return new WP_DuckDB_Plugin_Test_Result(
				array( 'Success' ),
				array(
					array( 'Success' => true ),
				)
			);
		}

		throw new RuntimeException( 'Unexpected query: ' . $sql );
	}
}

$client             = new WP_DuckDB_Plugin_Test_Client();
$GLOBALS['@duckdb'] = new WP_DuckDB_Connection( array( 'duckdb' => $client ) );
$db                 = new WP_DuckDB_DB( 'wordpress_test' );
$connected          = $db->db_connect( false );
$select_return      = $db->query( 'SELECT 42 AS answer' );
$select_state       = array(
	'last_query' => $db->last_query,
	'num_rows'   => $db->num_rows,
	'answer'     => $db->last_result[0]->answer,
);
$insert_return      = $db->query( 'INSERT INTO t VALUES (1), (2)' );
$insert_state       = array(
	'rows_affected' => $db->rows_affected,
);
$create_return      = $db->query( 'CREATE TABLE t (id INTEGER)' );

echo json_encode(
	array(
		'connected'            => $connected,
		'select_return'        => $select_return,
		'select_last_query'    => $select_state['last_query'],
		'select_num_rows'      => $select_state['num_rows'],
		'select_answer'        => $select_state['answer'],
		'insert_return'        => $insert_return,
		'insert_rows_affected' => $insert_state['rows_affected'],
		'create_return'        => $create_return,
		'num_queries'          => $db->num_queries,
		'client_queries'       => $client->queries,
	)
);
PHP;

		return $this->run_isolated_php( $code );
	}

	private function run_insert_id_state_script(): array {
		$plugin_dir  = $this->get_plugin_dir();
		$driver_load = dirname( __DIR__, 2 ) . '/src/load.php';
		$code        = $this->get_wordpress_stub_code();
		$code       .= "\nrequire_once " . var_export( $driver_load, true ) . ";\n";
		$code       .= 'require_once ' . var_export( $plugin_dir . '/wp-includes/duckdb/class-wp-duckdb-db.php', true ) . ";\n";
		$code       .= <<<'PHP'

class WP_DuckDB_Plugin_Insert_Id_Test_Driver extends WP_DuckDB_Driver {
	private $insert_id = 0;

	public function __construct() {}

	public function query( string $sql ): WP_DuckDB_Result_Statement {
		if ( 'SELECT @@SESSION.sql_mode' === $sql ) {
			return new WP_DuckDB_Result_Statement(
				array( '@@SESSION.sql_mode' ),
				array(
					array( 'NO_ENGINE_SUBSTITUTION' ),
				),
				0
			);
		}

		if ( "SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'" === $sql ) {
			return new WP_DuckDB_Result_Statement( array(), array(), 0 );
		}

		if ( false !== strpos( $sql, 'BROKEN' ) ) {
			throw new WP_DuckDB_Driver_Exception(
				'Synthetic insert failure.',
				'HY000',
				new RuntimeException( 'Native synthetic insert failure.' )
			);
		}

		if ( 0 === stripos( trim( $sql ), 'replace' ) ) {
			$this->insert_id = 102;
			return new WP_DuckDB_Result_Statement( array(), array(), 1 );
		}

		if ( 0 === stripos( trim( $sql ), 'insert' ) ) {
			$this->insert_id = 101;
			return new WP_DuckDB_Result_Statement( array(), array(), 1 );
		}

		return new WP_DuckDB_Result_Statement( array(), array(), 0 );
	}

	public function get_insert_id(): int {
		return $this->insert_id;
	}
}

$GLOBALS['@duckdb_driver'] = new WP_DuckDB_Plugin_Insert_Id_Test_Driver();
$db                        = new WP_DuckDB_DB( 'wordpress_test' );
$connected                 = $db->db_connect( false );
$db->suppress_errors( true );
$insert_return             = $db->query( "INSERT INTO t (name) VALUES ('first')" );
$insert_id                 = $db->insert_id;
$replace_return            = $db->query( "REPLACE INTO t (name) VALUES ('second')" );
$replace_insert_id         = $db->insert_id;
$failed_insert             = $db->query( 'INSERT INTO t VALUES (BROKEN)' );
$failed_insert_id          = $db->insert_id;
$failed_last_error         = $db->last_error;
$successful_select         = $db->query( 'SELECT 1 AS ok' );
$last_error_after_success  = $db->last_error;

echo json_encode(
	array(
		'connected'                     => $connected,
		'insert_return'                 => $insert_return,
		'insert_id_after_insert'        => $insert_id,
		'replace_return'                => $replace_return,
		'insert_id_after_replace'       => $replace_insert_id,
		'failed_insert_return'          => $failed_insert,
		'insert_id_after_failed_insert' => $failed_insert_id,
		'last_error_after_failed_insert' => $failed_last_error,
		'successful_select_return'      => $successful_select,
		'last_error_after_success'      => $last_error_after_success,
	)
);
PHP;

		return $this->run_isolated_php( $code );
	}

	private function run_flush_state_script(): array {
		$plugin_dir  = $this->get_plugin_dir();
		$driver_load = dirname( __DIR__, 2 ) . '/src/load.php';
		$code        = $this->get_wordpress_stub_code();
		$code       .= "\nrequire_once " . var_export( $driver_load, true ) . ";\n";
		$code       .= 'require_once ' . var_export( $plugin_dir . '/wp-includes/duckdb/class-wp-duckdb-db.php', true ) . ";\n";
		$code       .= <<<'PHP'

class WP_DuckDB_Plugin_Flush_Test_Result {
	private $columns;
	private $rows;

	public function __construct( array $columns, array $rows ) {
		$this->columns = $columns;
		$this->rows    = $rows;
	}

	public function columnNames() {
		return new ArrayIterator( $this->columns );
	}

	public function rows( $assoc = false ) {
		return new ArrayIterator( $this->rows );
	}
}

class WP_DuckDB_Plugin_Flush_Test_Client {
	public function query( $sql ) {
		$normalized = strtolower( trim( $sql ) );

		if ( 0 === strpos( $normalized, 'select' ) ) {
			return new WP_DuckDB_Plugin_Flush_Test_Result(
				array( 'answer' ),
				array(
					array( 'answer' => 42 ),
				)
			);
		}

		if ( 0 === strpos( $normalized, 'create' ) ) {
			return new WP_DuckDB_Plugin_Flush_Test_Result(
				array( 'Success' ),
				array(
					array( 'Success' => true ),
				)
			);
		}

		throw new RuntimeException( 'Unexpected query: ' . $sql );
	}
}

class WP_DuckDB_Plugin_Flush_Test_DB extends WP_DuckDB_DB {
	public function exported_column_names() {
		$this->load_col_info();
		return array_map(
			function ( $column ) {
				return $column->name;
			},
			$this->col_info
		);
	}
}

$GLOBALS['@duckdb'] = new WP_DuckDB_Connection( array( 'duckdb' => new WP_DuckDB_Plugin_Flush_Test_Client() ) );
$db                 = new WP_DuckDB_Plugin_Flush_Test_DB( 'wordpress_test' );
$connected          = $db->db_connect( false );
$select_return      = $db->query( 'SELECT 42 AS answer' );
$before_columns     = $db->exported_column_names();

$db->flush();

$state_after_flush = array(
	'last_result'   => $db->last_result,
	'col_info'      => $db->col_info,
	'last_query'    => $db->last_query,
	'rows_affected' => $db->rows_affected,
	'num_rows'      => $db->num_rows,
	'last_error'    => $db->last_error,
	'result'        => $db->result,
);
$after_columns     = $db->exported_column_names();

echo json_encode(
	array(
		'connected'                  => $connected,
		'select_return'              => $select_return,
		'before_flush_column_names'  => $before_columns,
		'last_result_after_flush'    => $state_after_flush['last_result'],
		'col_info_after_flush'       => $state_after_flush['col_info'],
		'last_query_after_flush'     => $state_after_flush['last_query'],
		'rows_affected_after_flush'  => $state_after_flush['rows_affected'],
		'num_rows_after_flush'       => $state_after_flush['num_rows'],
		'last_error_after_flush'     => $state_after_flush['last_error'],
		'result_after_flush'         => $state_after_flush['result'],
		'after_flush_column_names'   => $after_columns,
	)
);
PHP;

		return $this->run_isolated_php( $code );
	}

	private function run_col_info_state_script(): array {
		$plugin_dir  = $this->get_plugin_dir();
		$driver_load = dirname( __DIR__, 2 ) . '/src/load.php';
		$code        = $this->get_wordpress_stub_code();
		$code       .= "\nrequire_once " . var_export( $driver_load, true ) . ";\n";
		$code       .= 'require_once ' . var_export( $plugin_dir . '/wp-includes/duckdb/class-wp-duckdb-db.php', true ) . ";\n";
		$code       .= <<<'PHP'

class WP_DuckDB_Plugin_Col_Info_Test_Driver extends WP_DuckDB_Driver {
	public function __construct() {}

	public function query( string $sql ): WP_DuckDB_Result_Statement {
		if ( 'SELECT @@SESSION.sql_mode' === $sql ) {
			return new WP_DuckDB_Result_Statement(
				array( '@@SESSION.sql_mode' ),
				array(
					array( 'NO_ENGINE_SUBSTITUTION' ),
				),
				0
			);
		}

		if ( "SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'" === $sql ) {
			return new WP_DuckDB_Result_Statement( array(), array(), 0 );
		}

		if ( 'SELECT ID, post_title, price FROM wp_posts WHERE ID = 0' === $sql ) {
			return new WP_DuckDB_Result_Statement(
				array( 'ID', 'post_title', 'price' ),
				array(),
				0,
				array(
					array(
						'name'             => 'ID',
						'native_type'      => 'LONGLONG',
						'table'            => 'wp_posts',
						'len'              => 20,
						'precision'        => 0,
						'mysqli:orgname'   => 'ID',
						'mysqli:orgtable'  => 'wp_posts',
						'mysqli:db'        => 'wordpress_test',
						'mysqli:charsetnr' => 63,
						'mysqli:flags'     => 0,
						'mysqli:type'      => 8,
					),
					array(
						'name'             => 'post_title',
						'native_type'      => 'VAR_STRING',
						'table'            => 'wp_posts',
						'len'              => 764,
						'precision'        => 0,
						'mysqli:orgname'   => 'post_title',
						'mysqli:orgtable'  => 'wp_posts',
						'mysqli:db'        => 'wordpress_test',
						'mysqli:charsetnr' => 255,
						'mysqli:flags'     => 0,
						'mysqli:type'      => 253,
					),
					array(
						'name'             => 'price',
						'native_type'      => 'NEWDECIMAL',
						'table'            => 'wp_posts',
						'len'              => 12,
						'precision'        => 2,
						'mysqli:orgname'   => 'price',
						'mysqli:orgtable'  => 'wp_posts',
						'mysqli:db'        => 'wordpress_test',
						'mysqli:charsetnr' => 63,
						'mysqli:flags'     => 32768,
						'mysqli:type'      => 246,
					),
				)
			);
		}

		if ( 'UPDATE wp_posts SET ID = ID WHERE ID = 0' === $sql ) {
			return new WP_DuckDB_Result_Statement( array(), array(), 0 );
		}

		throw new RuntimeException( 'Unexpected query: ' . $sql );
	}
}

class WP_DuckDB_Plugin_Col_Info_Test_DB extends WP_DuckDB_DB {
	public function exported_col_info() {
		$this->load_col_info();
		return array_map(
			function ( $column ) {
				return (array) $column;
			},
			$this->col_info
		);
	}
}

$GLOBALS['@duckdb_driver'] = new WP_DuckDB_Plugin_Col_Info_Test_Driver();
$db                        = new WP_DuckDB_Plugin_Col_Info_Test_DB( 'wordpress_test' );
$connected                 = $db->db_connect( false );
$select_return             = $db->query( 'SELECT ID, post_title, price FROM wp_posts WHERE ID = 0' );
$select_col_info           = $db->exported_col_info();
$update_return             = $db->query( 'UPDATE wp_posts SET ID = ID WHERE ID = 0' );
$update_col_info           = $db->exported_col_info();

echo json_encode(
	array(
		'connected'       => $connected,
		'select_return'   => $select_return,
		'select_col_info' => $select_col_info,
		'update_return'   => $update_return,
		'update_col_info' => $update_col_info,
	)
);
PHP;

		return $this->run_isolated_php( $code );
	}

	private function run_col_info_fallback_state_script(): array {
		$plugin_dir  = $this->get_plugin_dir();
		$driver_load = dirname( __DIR__, 2 ) . '/src/load.php';
		$code        = $this->get_wordpress_stub_code();
		$code       .= "\nrequire_once " . var_export( $driver_load, true ) . ";\n";
		$code       .= 'require_once ' . var_export( $plugin_dir . '/wp-includes/duckdb/class-wp-duckdb-db.php', true ) . ";\n";
		$code       .= <<<'PHP'

class WP_DuckDB_Plugin_Col_Info_Fallback_Test_Driver extends WP_DuckDB_Driver {
	public function __construct() {}

	public function query( string $sql ): WP_DuckDB_Result_Statement {
		if ( 'SELECT @@SESSION.sql_mode' === $sql ) {
			return new WP_DuckDB_Result_Statement(
				array( '@@SESSION.sql_mode' ),
				array(
					array( 'NO_ENGINE_SUBSTITUTION' ),
				),
				0
			);
		}

		if ( "SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'" === $sql ) {
			return new WP_DuckDB_Result_Statement( array(), array(), 0 );
		}

		$complex_join_sql = 'SELECT p.*, m.meta_value, LENGTH(p.post_title) AS post_title ' .
			'FROM wp_posts AS p LEFT JOIN wp_postmeta AS m ON m.post_id = p.ID';
		$statement_columns = array(
			'SELECT COUNT(*) AS post_count' => array( 'post_count' ),
			'SELECT FOUND_ROWS() AS found_rows' => array( 'found_rows' ),
			'SHOW FULL TABLES' => array( 'Tables_in_wordpress_test', 'Table_type' ),
			'SHOW COLUMNS FROM wp_posts' => array( 'Field', 'Type', 'Null', 'Key', 'Default', 'Extra' ),
			'SHOW TABLE STATUS' => array(
				'Name',
				'Engine',
				'Version',
				'Row_format',
				'Rows',
				'Avg_row_length',
				'Data_length',
				'Max_data_length',
				'Index_length',
				'Data_free',
				'Auto_increment',
				'Create_time',
				'Update_time',
				'Check_time',
				'Collation',
				'Checksum',
				'Create_options',
				'Comment',
			),
			'SHOW CREATE TABLE wp_posts' => array( 'Table', 'Create Table' ),
			'SHOW INDEX FROM wp_posts' => array(
				'Table',
				'Non_unique',
				'Key_name',
				'Seq_in_index',
				'Column_name',
				'Collation',
				'Cardinality',
				'Sub_part',
				'Packed',
				'Null',
				'Index_type',
				'Comment',
				'Index_comment',
				'Visible',
				'Expression',
			),
			'CHECK TABLE wp_posts' => array( 'Table', 'Op', 'Msg_type', 'Msg_text' ),
			'ANALYZE TABLE wp_posts' => array( 'Table', 'Op', 'Msg_type', 'Msg_text' ),
			'OPTIMIZE TABLE wp_posts' => array( 'Table', 'Op', 'Msg_type', 'Msg_text' ),
			'REPAIR TABLE wp_posts' => array( 'Table', 'Op', 'Msg_type', 'Msg_text' ),
			$complex_join_sql => array(
				'ID',
				'post_title',
				'meta_value',
				'post_title',
			),
		);

		if ( isset( $statement_columns[ $sql ] ) ) {
			$columns = $statement_columns[ $sql ];
			return new WP_DuckDB_Result_Statement(
				$columns,
				array(
					array_fill( 0, count( $columns ), null ),
				),
				0
			);
		}

		throw new RuntimeException( 'Unexpected query: ' . $sql );
	}
}

class WP_DuckDB_Plugin_Col_Info_Fallback_Test_DB extends WP_DuckDB_DB {
	public function exported_col_info() {
		$this->load_col_info();
		return array_map(
			function ( $column ) {
				return (array) $column;
			},
			$this->col_info
		);
	}
}

function wp_duckdb_plugin_col_info_fallback_case( WP_DuckDB_Plugin_Col_Info_Fallback_Test_DB $db, $sql ) {
	$db->query( $sql );
	return $db->exported_col_info();
}

$GLOBALS['@duckdb_driver'] = new WP_DuckDB_Plugin_Col_Info_Fallback_Test_Driver();
$db                        = new WP_DuckDB_Plugin_Col_Info_Fallback_Test_DB( 'wordpress_test' );
$connected                 = $db->db_connect( false );
$complex_join_sql          = 'SELECT p.*, m.meta_value, LENGTH(p.post_title) AS post_title ' .
	'FROM wp_posts AS p LEFT JOIN wp_postmeta AS m ON m.post_id = p.ID';
$cases                     = array(
	'select_count'      => wp_duckdb_plugin_col_info_fallback_case( $db, 'SELECT COUNT(*) AS post_count' ),
	'found_rows'        => wp_duckdb_plugin_col_info_fallback_case( $db, 'SELECT FOUND_ROWS() AS found_rows' ),
	'show_full_tables'  => wp_duckdb_plugin_col_info_fallback_case( $db, 'SHOW FULL TABLES' ),
	'show_columns'      => wp_duckdb_plugin_col_info_fallback_case( $db, 'SHOW COLUMNS FROM wp_posts' ),
	'show_table_status' => wp_duckdb_plugin_col_info_fallback_case( $db, 'SHOW TABLE STATUS' ),
	'show_create_table' => wp_duckdb_plugin_col_info_fallback_case( $db, 'SHOW CREATE TABLE wp_posts' ),
	'show_index'        => wp_duckdb_plugin_col_info_fallback_case( $db, 'SHOW INDEX FROM wp_posts' ),
	'check_table'       => wp_duckdb_plugin_col_info_fallback_case( $db, 'CHECK TABLE wp_posts' ),
	'analyze_table'     => wp_duckdb_plugin_col_info_fallback_case( $db, 'ANALYZE TABLE wp_posts' ),
	'optimize_table'    => wp_duckdb_plugin_col_info_fallback_case( $db, 'OPTIMIZE TABLE wp_posts' ),
	'repair_table'      => wp_duckdb_plugin_col_info_fallback_case( $db, 'REPAIR TABLE wp_posts' ),
	'complex_join'      => wp_duckdb_plugin_col_info_fallback_case( $db, $complex_join_sql ),
);

echo json_encode(
	array(
		'connected' => $connected,
		'cases'     => $cases,
	)
);
PHP;

		return $this->run_isolated_php( $code );
	}

	private function run_show_index_non_unique_state_script(): array {
		$plugin_dir  = $this->get_plugin_dir();
		$driver_load = dirname( __DIR__, 2 ) . '/src/load.php';
		$code        = $this->get_wordpress_stub_code();
		$code       .= "\nrequire_once " . var_export( $driver_load, true ) . ";\n";
		$code       .= 'require_once ' . var_export( $plugin_dir . '/wp-includes/duckdb/class-wp-duckdb-db.php', true ) . ";\n";
		$code       .= <<<'PHP'

class WP_DuckDB_Plugin_Show_Index_Test_Driver extends WP_DuckDB_Driver {
	public function __construct() {}

	public function query( string $sql ): WP_DuckDB_Result_Statement {
		if ( 'SELECT @@SESSION.sql_mode' === $sql ) {
			return new WP_DuckDB_Result_Statement(
				array( '@@SESSION.sql_mode' ),
				array(
					array( 'NO_ENGINE_SUBSTITUTION' ),
				),
				0
			);
		}

		if ( "SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'" === $sql ) {
			return new WP_DuckDB_Result_Statement( array(), array(), 0 );
		}

		if ( 'SHOW INDEX FROM wp_posts' === $sql ) {
			return new WP_DuckDB_Result_Statement(
				array(
					'Table',
					'Non_unique',
					'Key_name',
					'Seq_in_index',
					'Column_name',
					'Collation',
					'Cardinality',
					'Sub_part',
					'Packed',
					'Null',
					'Index_type',
					'Comment',
					'Index_comment',
					'Visible',
					'Expression',
				),
				array(
					array( 'wp_posts', 0, 'PRIMARY', 1, 'ID', 'A', 0, null, null, '', 'BTREE', '', '', 'YES', null ),
					array( 'wp_posts', 1, 'post_name', 1, 'post_name', 'A', 0, 191, null, '', 'BTREE', '', '', 'YES', null ),
				),
				0
			);
		}

		throw new RuntimeException( 'Unexpected query: ' . $sql );
	}
}

$GLOBALS['@duckdb_driver'] = new WP_DuckDB_Plugin_Show_Index_Test_Driver();
$db                        = new WP_DuckDB_DB( 'wordpress_test' );
$connected                 = $db->db_connect( false );
$show_index_return         = $db->query( 'SHOW INDEX FROM wp_posts' );
$rows                      = array_map(
	function ( $row ) {
		return get_object_vars( $row );
	},
	$db->last_result
);
$non_unique_values         = array_map(
	function ( $row ) {
		return $row['Non_unique'];
	},
	$rows
);
$non_unique_types          = array_map( 'gettype', $non_unique_values );
$dbdelta_primary_match     = isset( $db->last_result[0]->Non_unique ) && '0' === $db->last_result[0]->Non_unique;

echo json_encode(
	array(
		'connected'             => $connected,
		'show_index_return'     => $show_index_return,
		'rows'                  => $rows,
		'non_unique_values'     => $non_unique_values,
		'non_unique_types'      => $non_unique_types,
		'dbdelta_primary_match' => $dbdelta_primary_match,
	)
);
PHP;

		return $this->run_isolated_php( $code );
	}

	private function run_query_surface_state_script(): array {
		$plugin_dir  = $this->get_plugin_dir();
		$driver_load = dirname( __DIR__, 2 ) . '/src/load.php';
		$code        = $this->get_wordpress_stub_code();
		$code       .= "\nrequire_once " . var_export( $driver_load, true ) . ";\n";
		$code       .= 'require_once ' . var_export( $plugin_dir . '/wp-includes/duckdb/class-wp-duckdb-db.php', true ) . ";\n";
		$code       .= <<<'PHP'

class WP_DuckDB_Plugin_Query_Surface_Test_Driver extends WP_DuckDB_Driver {
	const ODKU_CHANGED_SQL = "INSERT INTO wp_posts (ID, post_title) VALUES (11, 'changed') "
		. 'ON DUPLICATE KEY UPDATE post_title = VALUES(post_title)';
	const ODKU_NOOP_SQL    = "INSERT INTO wp_posts (ID, post_title) VALUES (11, 'same') "
		. 'ON DUPLICATE KEY UPDATE post_title = post_title';

	private $insert_id = 0;

	public function __construct() {}

	public function query( string $sql ): WP_DuckDB_Result_Statement {
		if ( 'SELECT @@SESSION.sql_mode' === $sql ) {
			return new WP_DuckDB_Result_Statement(
				array( '@@SESSION.sql_mode' ),
				array(
					array( 'NO_ENGINE_SUBSTITUTION' ),
				),
				0
			);
		}

		if ( "SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'" === $sql ) {
			return new WP_DuckDB_Result_Statement( array(), array(), 0 );
		}

		if ( 'SELECT 42 AS answer' === $sql ) {
			return new WP_DuckDB_Result_Statement(
				array( 'answer' ),
				array(
					array( 42 ),
				),
				0,
				array(
					array(
						'name'             => 'answer',
						'native_type'      => 'BIGINT',
						'len'              => 20,
						'precision'        => 0,
						'mysqli:orgname'   => 'answer',
						'mysqli:db'        => 'wordpress_test',
						'mysqli:charsetnr' => 63,
						'mysqli:flags'     => 0,
						'mysqli:type'      => 8,
					),
				)
			);
		}

		if ( 'SELECT 1003 AS comment_ID, 12 AS comment_post_ID, TRUE AS truth, FALSE AS falsity, NULL AS none, \'Anonymous\' AS note' === $sql ) {
			return new WP_DuckDB_Result_Statement(
				array( 'comment_ID', 'comment_post_ID', 'truth', 'falsity', 'none', 'note' ),
				array(
					array( 1003, 12, true, false, null, 'Anonymous' ),
				)
			);
		}

		if ( 'SELECT ID, post_title FROM wp_posts WHERE ID = 0' === $sql ) {
			return new WP_DuckDB_Result_Statement(
				array( 'ID', 'post_title' ),
				array(),
				0,
				array(
					array(
						'name'             => 'ID',
						'native_type'      => 'LONGLONG',
						'table'            => 'wp_posts',
						'len'              => 20,
						'precision'        => 0,
						'mysqli:orgname'   => 'ID',
						'mysqli:orgtable'  => 'wp_posts',
						'mysqli:db'        => 'wordpress_test',
						'mysqli:charsetnr' => 63,
						'mysqli:flags'     => 0,
						'mysqli:type'      => 8,
					),
					array(
						'name'             => 'post_title',
						'native_type'      => 'VAR_STRING',
						'table'            => 'wp_posts',
						'len'              => 764,
						'precision'        => 0,
						'mysqli:orgname'   => 'post_title',
						'mysqli:orgtable'  => 'wp_posts',
						'mysqli:db'        => 'wordpress_test',
						'mysqli:charsetnr' => 255,
						'mysqli:flags'     => 0,
						'mysqli:type'      => 253,
					),
				)
			);
		}

		if ( 'SHOW FULL TABLES' === $sql ) {
			return new WP_DuckDB_Result_Statement(
				array( 'Tables_in_wordpress_test', 'Table_type' ),
				array(
					array( 'wp_posts', 'BASE TABLE' ),
				)
			);
		}

		if ( 'SHOW COLUMNS FROM wp_posts' === $sql ) {
			return new WP_DuckDB_Result_Statement(
				array( 'Field', 'Type', 'Null', 'Key', 'Default', 'Extra' ),
				array(
					array( 'ID', 'bigint unsigned', 'NO', 'PRI', null, 'auto_increment' ),
					array( 'post_title', 'text', 'NO', '', null, '' ),
				)
			);
		}

		if ( 'CHECK TABLE wp_posts' === $sql ) {
			return new WP_DuckDB_Result_Statement(
				array( 'Table', 'Op', 'Msg_type', 'Msg_text' ),
				array(
					array( 'wordpress_test.wp_posts', 'check', 'status', 'OK' ),
				)
			);
		}

		if ( "INSERT INTO wp_posts (post_title) VALUES ('hello')" === $sql ) {
			$this->insert_id = 11;
			return new WP_DuckDB_Result_Statement( array(), array(), 1 );
		}

		if ( "UPDATE wp_posts SET post_title = 'changed' WHERE ID = 11" === $sql ) {
			return new WP_DuckDB_Result_Statement( array(), array(), 1 );
		}

		if ( "UPDATE wp_posts SET post_title = 'same' WHERE ID = 12" === $sql ) {
			return new WP_DuckDB_Result_Statement( array(), array(), 0 );
		}

		if ( self::ODKU_CHANGED_SQL === $sql ) {
			return new WP_DuckDB_Result_Statement( array(), array(), 1 );
		}

		if ( self::ODKU_NOOP_SQL === $sql ) {
			return new WP_DuckDB_Result_Statement( array(), array(), 1 );
		}

		if ( "UPDATE wp_posts SET post_title = 'missing' WHERE ID = 999" === $sql ) {
			return new WP_DuckDB_Result_Statement( array(), array(), 0 );
		}

		if ( 'DELETE FROM wp_posts WHERE ID = 11' === $sql ) {
			return new WP_DuckDB_Result_Statement( array(), array(), 1 );
		}

		if ( "REPLACE INTO wp_posts (ID, post_title) VALUES (12, 'inserted')" === $sql ) {
			$this->insert_id = 12;
			return new WP_DuckDB_Result_Statement( array(), array(), 1 );
		}

		if ( "REPLACE INTO wp_posts (ID, post_title) VALUES (12, 'replacement')" === $sql ) {
			$this->insert_id = 12;
			return new WP_DuckDB_Result_Statement( array(), array(), 2 );
		}

		if ( 'CREATE TABLE wp_surface (id INTEGER)' === $sql ) {
			return new WP_DuckDB_Result_Statement( array(), array(), 0 );
		}

		if ( 'SELECT BROKEN' === $sql ) {
			throw new WP_DuckDB_Driver_Exception(
				'Synthetic select failure.',
				'HY000',
				new RuntimeException( 'Native synthetic select failure.' )
			);
		}

		if ( 'INSERT INTO wp_posts VALUES (BROKEN)' === $sql ) {
			throw new WP_DuckDB_Driver_Exception(
				'Synthetic insert failure.',
				'HY000',
				new RuntimeException( 'Native synthetic insert failure.' )
			);
		}

		if ( "INSERT INTO wp_posts (post_title) VALUES ('after-failure')" === $sql ) {
			$this->insert_id = 13;
			return new WP_DuckDB_Result_Statement( array(), array(), 1 );
		}

		if ( "UPDATE wp_posts SET post_title = BROKEN WHERE ID = 13" === $sql ) {
			throw new WP_DuckDB_Driver_Exception(
				'Synthetic update failure.',
				'HY000',
				new RuntimeException( 'Native synthetic update failure.' )
			);
		}

		if ( 'CREATE TABLE wp_surface_after_failure (id INTEGER)' === $sql ) {
			return new WP_DuckDB_Result_Statement( array(), array(), 0 );
		}

		throw new RuntimeException( 'Unexpected query: ' . $sql );
	}

	public function get_insert_id(): int {
		return $this->insert_id;
	}
}

class WP_DuckDB_Plugin_Query_Surface_Test_DB extends WP_DuckDB_DB {
	public function exported_state( $query_return ) {
		$this->load_col_info();
		$col_info = null === $this->col_info
			? null
			: array_map(
				function ( $column ) {
					return (array) $column;
				},
				$this->col_info
			);

		return array(
			'return'            => $query_return,
			'last_query'        => $this->last_query,
			'rows_affected'     => $this->rows_affected,
			'num_rows'          => $this->num_rows,
			'last_result_count' => count( $this->last_result ),
			'last_result'       => array_map(
				function ( $row ) {
					return get_object_vars( $row );
				},
				$this->last_result
			),
			'last_error'        => $this->last_error,
			'insert_id'         => $this->insert_id,
			'col_info_names'    => is_array( $col_info )
				? array_map(
					function ( $column ) {
						return $column['name'];
					},
					$col_info
				)
				: null,
		);
	}
}

function wp_duckdb_plugin_query_surface_case( WP_DuckDB_Plugin_Query_Surface_Test_DB $db, $sql ) {
	$query_return = $db->query( $sql );
	return $db->exported_state( $query_return );
}

$GLOBALS['@duckdb_driver'] = new WP_DuckDB_Plugin_Query_Surface_Test_Driver();
$db                        = new WP_DuckDB_Plugin_Query_Surface_Test_DB( 'wordpress_test' );
$connected                 = $db->db_connect( false );
$db->suppress_errors( true );
$cases                     = array(
	'select_one_row'       => wp_duckdb_plugin_query_surface_case( $db, 'SELECT 42 AS answer' ),
	'select_mixed_scalars' => wp_duckdb_plugin_query_surface_case(
		$db,
		"SELECT 1003 AS comment_ID, 12 AS comment_post_ID, TRUE AS truth, FALSE AS falsity, NULL AS none, 'Anonymous' AS note"
	),
	'select_zero_rows'     => wp_duckdb_plugin_query_surface_case( $db, 'SELECT ID, post_title FROM wp_posts WHERE ID = 0' ),
	'show_full_tables'     => wp_duckdb_plugin_query_surface_case( $db, 'SHOW FULL TABLES' ),
	'show_columns'         => wp_duckdb_plugin_query_surface_case( $db, 'SHOW COLUMNS FROM wp_posts' ),
	'check_table'          => wp_duckdb_plugin_query_surface_case( $db, 'CHECK TABLE wp_posts' ),
	'insert_row'           => wp_duckdb_plugin_query_surface_case( $db, "INSERT INTO wp_posts (post_title) VALUES ('hello')" ),
	'update_changed'       => wp_duckdb_plugin_query_surface_case( $db, "UPDATE wp_posts SET post_title = 'changed' WHERE ID = 11" ),
	'update_noop'          => wp_duckdb_plugin_query_surface_case( $db, "UPDATE wp_posts SET post_title = 'same' WHERE ID = 12" ),
	'odku_duplicate_changed' => wp_duckdb_plugin_query_surface_case(
		$db,
		WP_DuckDB_Plugin_Query_Surface_Test_Driver::ODKU_CHANGED_SQL
	),
	'odku_duplicate_noop'  => wp_duckdb_plugin_query_surface_case(
		$db,
		WP_DuckDB_Plugin_Query_Surface_Test_Driver::ODKU_NOOP_SQL
	),
	'update_no_match'      => wp_duckdb_plugin_query_surface_case( $db, "UPDATE wp_posts SET post_title = 'missing' WHERE ID = 999" ),
	'delete_row'           => wp_duckdb_plugin_query_surface_case( $db, 'DELETE FROM wp_posts WHERE ID = 11' ),
	'replace_insert'       => wp_duckdb_plugin_query_surface_case( $db, "REPLACE INTO wp_posts (ID, post_title) VALUES (12, 'inserted')" ),
	'replace_row'          => wp_duckdb_plugin_query_surface_case( $db, "REPLACE INTO wp_posts (ID, post_title) VALUES (12, 'replacement')" ),
	'create_table'         => wp_duckdb_plugin_query_surface_case( $db, 'CREATE TABLE wp_surface (id INTEGER)' ),
	'select_failure'       => wp_duckdb_plugin_query_surface_case( $db, 'SELECT BROKEN' ),
	'select_after_failure' => wp_duckdb_plugin_query_surface_case( $db, 'SELECT 42 AS answer' ),
	'insert_failure'       => wp_duckdb_plugin_query_surface_case( $db, 'INSERT INTO wp_posts VALUES (BROKEN)' ),
	'insert_after_failure' => wp_duckdb_plugin_query_surface_case( $db, "INSERT INTO wp_posts (post_title) VALUES ('after-failure')" ),
	'update_failure_after_insert' => wp_duckdb_plugin_query_surface_case( $db, "UPDATE wp_posts SET post_title = BROKEN WHERE ID = 13" ),
	'create_after_update_failure' => wp_duckdb_plugin_query_surface_case( $db, 'CREATE TABLE wp_surface_after_failure (id INTEGER)' ),
);

echo json_encode(
	array(
		'connected' => $connected,
		'cases'     => $cases,
	)
);
PHP;

		return $this->run_isolated_php( $code );
	}

	private function get_dispatcher_include_code( string $include_file ): string {
		return "\ntry {\n"
			. "\trequire " . var_export( $include_file, true ) . ";\n"
			. "\techo json_encode( wp_duckdb_plugin_test_state( false ) );\n"
			. "} catch ( RuntimeException \$e ) {\n"
			. "\techo json_encode( wp_duckdb_plugin_test_state( true ) );\n"
			. "}\n";
	}

	private function get_wordpress_stub_code(): string {
		$temp_dir        = $this->create_temp_dir();
		$duckdb_autoload = getenv( 'DUCKDB_PHP_AUTOLOAD' );
		if ( ! is_string( $duckdb_autoload ) || '' === $duckdb_autoload || ! file_exists( $duckdb_autoload ) ) {
			$duckdb_autoload = getenv( 'WP_DUCKDB_AUTOLOAD' );
		}
		$duckdb_autoload_constant = is_string( $duckdb_autoload ) && '' !== $duckdb_autoload && file_exists( $duckdb_autoload )
			? "define( 'DUCKDB_PHP_AUTOLOAD', " . var_export( $duckdb_autoload, true ) . " );\n"
			: '';

		return "<?php\n"
			. "define( 'ABSPATH', " . var_export( $temp_dir . '/', true ) . " );\n"
			. "define( 'WP_CONTENT_DIR', " . var_export( $temp_dir . '/wp-content', true ) . " );\n"
			. "define( 'DB_NAME', 'wordpress_test' );\n"
			. "define( 'FQDBDIR', " . var_export( $temp_dir . '/database/', true ) . " );\n"
			. "define( 'FQDB', FQDBDIR . '.ht.sqlite' );\n"
			. "define( 'FQDUCKDB', FQDBDIR . '.ht.duckdb' );\n"
			. $duckdb_autoload_constant
			. <<<'PHP'

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

class wpdb {
	public $charset;
	public $col_info = null;
	public $dbname = '';
	public $func_call = '';
	public $insert_id = 0;
	public $last_error = '';
	public $last_query = null;
	public $last_result = array();
	public $num_queries = 0;
	public $num_rows = 0;
	public $options = null;
	public $prefix = '';
	public $ready = false;
	public $result = null;
	public $rows_affected = 0;
	public $show_errors = false;
	public $suppress_errors = false;
	public $time_start = 0;

	public function __construct( $dbuser = '', $dbpassword = '', $dbname = '', $dbhost = '' ) {
		$this->dbname = $dbname;
	}

	public function add_placeholder_escape( $query ) {
		return $query;
	}

	public function bail( $message, $error_code = '500' ) {
		wp_die( $message, $error_code );
	}

	public function flush() {
		$this->last_result   = array();
		$this->col_info      = null;
		$this->last_query    = null;
		$this->rows_affected = 0;
		$this->num_rows      = 0;
		$this->last_error    = '';
		$this->result        = null;
	}

	public function get_caller() {
		return '';
	}

	public function get_row( $query ) {
		return null;
	}

	public function hide_errors() {
		$show_errors       = $this->show_errors;
		$this->show_errors = false;
		return $show_errors;
	}

	public function log_query( $query, $query_time, $query_callstack, $query_start, $query_data ) {
	}

	public function prepare( $query, ...$args ) {
		return $query;
	}

	public function print_error( $str = '' ) {
		$this->last_error = $str ? $str : $this->last_error;
	}

	public function set_prefix( $prefix ) {
		$this->prefix  = $prefix;
		$this->options = $prefix . 'options';
	}

	public function show_errors( $show = true ) {
		$old_show_errors   = $this->show_errors;
		$this->show_errors = $show;
		return $old_show_errors;
	}

	public function suppress_errors( $suppress = true ) {
		$old_suppress_errors   = $this->suppress_errors;
		$this->suppress_errors = (bool) $suppress;
		return $old_suppress_errors;
	}

	public function timer_start() {
		$this->time_start = microtime( true );
		return true;
	}

	public function timer_stop() {
		return microtime( true ) - $this->time_start;
	}
}

function __( $text ) {
	return $text;
}

function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['wp_duckdb_plugin_test_actions'][] = array( $hook_name, $priority, $accepted_args );
	return true;
}

function apply_filters( $hook_name, $value ) {
	return $value;
}

function is_admin() {
	return false;
}

function is_multisite() {
	return false;
}

function trailingslashit( $path ) {
	return rtrim( $path, '/\\' ) . '/';
}

function wp_die( $message = '', $title = '', $args = array() ) {
	if ( $message instanceof WP_Error ) {
		$GLOBALS['wp_duckdb_plugin_test_die'] = array(
			'code'    => $message->get_error_code(),
			'message' => $message->get_error_message(),
			'title'   => $title,
		);
	} else {
		$GLOBALS['wp_duckdb_plugin_test_die'] = array(
			'code'    => null,
			'message' => (string) $message,
			'title'   => $title,
		);
	}

	throw new RuntimeException( 'wp_die' );
}

function wp_duckdb_plugin_test_state( $died ) {
	return array(
		'died'                   => $died,
		'db_engine'              => defined( 'DB_ENGINE' ) ? DB_ENGINE : null,
		'database_type'          => defined( 'DATABASE_TYPE' ) ? DATABASE_TYPE : null,
		'sqlite_dropin_version'  => defined( 'SQLITE_DB_DROPIN_VERSION' ) ? SQLITE_DB_DROPIN_VERSION : null,
		'duckdb_dropin_version'  => defined( 'DUCKDB_DB_DROPIN_VERSION' ) ? DUCKDB_DB_DROPIN_VERSION : null,
		'wpdb_class'             => isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ? get_class( $GLOBALS['wpdb'] ) : null,
		'sqlite_loaded'          => class_exists( 'WP_SQLite_DB', false ),
		'duckdb_loaded'          => class_exists( 'WP_DuckDB_DB', false ),
		'wp_die_code'            => isset( $GLOBALS['wp_duckdb_plugin_test_die']['code'] ) ? $GLOBALS['wp_duckdb_plugin_test_die']['code'] : null,
		'wp_die_message'         => isset( $GLOBALS['wp_duckdb_plugin_test_die']['message'] ) ? $GLOBALS['wp_duckdb_plugin_test_die']['message'] : null,
		'wp_die_title'           => isset( $GLOBALS['wp_duckdb_plugin_test_die']['title'] ) ? $GLOBALS['wp_duckdb_plugin_test_die']['title'] : null,
	);
}
PHP;
	}

	private function run_isolated_php( string $code ): array {
		$script_file = $this->create_temp_file( $code );
		$output      = array();
		$exit_code   = 0;
		$ffi_enable  = ini_get( 'ffi.enable' );
		$php_args    = is_string( $ffi_enable ) && '' !== $ffi_enable
			? ' -d ' . escapeshellarg( 'ffi.enable=' . $ffi_enable )
			: '';
		$command     = escapeshellarg( PHP_BINARY ) . $php_args . ' ' . escapeshellarg( $script_file ) . ' 2>&1';

		try {
			exec( $command, $output, $exit_code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		} finally {
			if ( is_file( $script_file ) ) {
				unlink( $script_file );
			}
		}

		$output = implode( "\n", $output );
		$this->assertSame( 0, $exit_code, $output );

		$result = json_decode( $output, true );
		$this->assertIsArray( $result, $output );

		return $result;
	}

	private function create_temp_file( string $contents ): string {
		$file = tempnam( sys_get_temp_dir(), 'wp_duckdb_plugin_' );
		$this->assertIsString( $file );
		$this->assertNotFalse( file_put_contents( $file, $contents ) );

		return $file;
	}

	private function create_temp_dir(): string {
		$dir = sys_get_temp_dir() . '/wp_duckdb_plugin_' . str_replace( '.', '_', uniqid( '', true ) );
		$this->assertTrue( mkdir( $dir, 0700, true ) );
		$this->temp_dirs[] = $dir;

		return $dir;
	}

	private function remove_temp_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->remove_temp_dir( $path );
			} elseif ( file_exists( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}

		rmdir( $dir );
	}

	private function get_plugin_dir(): string {
		return dirname( __DIR__, 3 ) . '/plugin-sqlite-database-integration';
	}
}
