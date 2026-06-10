<?php declare(strict_types = 1);

/*
 * The PostgreSQL driver uses PDO. Enable PDO function calls:
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */

/**
 * PostgreSQL-backed driver scaffold.
 *
 * This class is intentionally not a PDO subclass. It exposes the small driver
 * surface consumed by the WordPress wpdb drop-in while the MySQL-to-PostgreSQL
 * emulation layer is extracted in later work.
 */
class WP_PostgreSQL_Driver {
	const MYSQL_COLUMN_METADATA_TABLE  = '__wp_postgresql_mysql_column_metadata';
	const MYSQL_INDEX_METADATA_TABLE   = '__wp_postgresql_mysql_index_metadata';
	const MYSQL_CHARSET_METADATA_TABLE = '__wp_postgresql_mysql_charset_metadata';
	const DEFAULT_MYSQL_CHARSET        = 'utf8mb4';
	const DEFAULT_MYSQL_COLLATION      = 'utf8mb4_unicode_ci';

	/**
	 * PostgreSQL server version string.
	 *
	 * @var string
	 */
	public $client_info;

	/**
	 * PostgreSQL connection.
	 *
	 * @var WP_PostgreSQL_Connection
	 */
	private $connection;

	/**
	 * MySQL-facing database name.
	 *
	 * @var string
	 */
	private $db_name;

	/**
	 * Result of the last query.
	 *
	 * @var mixed
	 */
	private $last_result;

	/**
	 * Column metadata for the last result set.
	 *
	 * @var array
	 */
	private $last_column_meta = array();

	/**
	 * Incoming MySQL-dialect query for the last request.
	 *
	 * @var string|null
	 */
	private $last_mysql_query;

	/**
	 * PostgreSQL queries executed for the last request.
	 *
	 * @var array
	 */
	private $last_postgresql_queries = array();

	/**
	 * Approximate FOUND_ROWS() value for the last SQL_CALC_FOUND_ROWS query.
	 *
	 * @var int
	 */
	private $last_found_rows = 0;

	/**
	 * MySQL-compatible session SQL mode state.
	 *
	 * @var string
	 */
	private $sql_mode = 'NO_ENGINE_SUBSTITUTION';

	/**
	 * MySQL-compatible session character set state.
	 *
	 * @var string
	 */
	private $charset = self::DEFAULT_MYSQL_CHARSET;

	/**
	 * MySQL-compatible session collation state.
	 *
	 * @var string
	 */
	private $collation = self::DEFAULT_MYSQL_COLLATION;

	/**
	 * Narrow in-memory procedure registry for WordPress mysqli compatibility tests.
	 *
	 * @var array<string, string>
	 */
	private $procedures = array();

	/**
	 * Constructor.
	 *
	 * @param WP_PostgreSQL_Connection $connection    PostgreSQL connection.
	 * @param string                   $database      MySQL-facing database name.
	 * @param int                      $mysql_version Reserved for parity with the SQLite driver.
	 */
	public function __construct(
		WP_PostgreSQL_Connection $connection,
		string $database,
		int $mysql_version = 80038
	) {
		$this->connection  = $connection;
		$this->db_name     = $database;
		$this->client_info = $this->read_server_version();

		$connection->get_pdo()->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );
	}

	/**
	 * Get the PostgreSQL connection instance.
	 *
	 * @return WP_PostgreSQL_Connection
	 */
	public function get_connection(): WP_PostgreSQL_Connection {
		return $this->connection;
	}

	/**
	 * Get the PostgreSQL server version.
	 *
	 * @return string
	 */
	public function get_postgresql_version(): string {
		return $this->client_info;
	}

	/**
	 * Get the last executed MySQL query.
	 *
	 * @return string|null
	 */
	public function get_last_mysql_query(): ?string {
		return $this->last_mysql_query;
	}

	/**
	 * Get backend queries executed for the last MySQL query.
	 *
	 * @return array
	 */
	public function get_last_postgresql_queries(): array {
		return $this->last_postgresql_queries;
	}

	/**
	 * Get the auto-increment value generated for the last query.
	 *
	 * @return int|string
	 */
	public function get_insert_id() {
		try {
			$insert_id = $this->connection->get_last_insert_id();
		} catch ( Throwable $e ) {
			return 0;
		}

		return is_numeric( $insert_id ) ? (int) $insert_id : $insert_id;
	}

	/**
	 * Set the emulated MySQL session SQL mode.
	 *
	 * @param string $sql_mode Comma-separated SQL mode string.
	 */
	public function set_sql_mode( string $sql_mode ): void {
		$this->sql_mode = $sql_mode;
	}

	/**
	 * Get the emulated MySQL session SQL mode.
	 *
	 * @return string Comma-separated SQL mode string.
	 */
	public function get_sql_mode(): string {
		return $this->sql_mode;
	}

	/**
	 * Set the emulated MySQL session charset/collation.
	 *
	 * @param string      $charset   MySQL charset.
	 * @param string|null $collation Optional MySQL collation.
	 */
	public function set_charset( string $charset, ?string $collation = null ): void {
		if ( 'default' === $this->normalize_mysql_charset_name( $charset ) ) {
			$this->charset   = self::DEFAULT_MYSQL_CHARSET;
			$this->collation = self::DEFAULT_MYSQL_COLLATION;
			return;
		}

		$this->charset   = $this->normalize_mysql_charset_name( $charset );
		$this->collation = null === $collation || '' === $collation
			? $this->get_default_mysql_collation_for_charset( $this->charset )
			: $this->normalize_mysql_collation_name( $collation );
	}

	/**
	 * Get the emulated MySQL session charset.
	 *
	 * @return string MySQL charset.
	 */
	public function get_charset(): string {
		return $this->charset;
	}

	/**
	 * Get the emulated MySQL session collation.
	 *
	 * @return string MySQL collation.
	 */
	public function get_collation(): string {
		return $this->collation;
	}

	/**
	 * Execute a query.
	 *
	 * @param string $query              Full SQL statement string.
	 * @param int    $fetch_mode         PDO fetch mode. Default is PDO::FETCH_OBJ.
	 * @param array  ...$fetch_mode_args Additional fetch mode arguments.
	 * @return mixed Return value, depending on the query type.
	 */
	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		$this->reset_query_state();
		$this->last_mysql_query = $query;

		if ( $this->is_noop_mysql_runtime_setting( $query ) ) {
			$this->last_result = 0;
			return $this->last_result;
		}

		if ( $this->apply_mysql_set_names_query( $query ) ) {
			$this->last_result = 0;
			return $this->last_result;
		}

		$procedure_result = $this->handle_mysql_procedure_query( $query, $fetch_mode, ...$fetch_mode_args );
		if ( null !== $procedure_result ) {
			return $procedure_result;
		}

		$sql_mode_variable = $this->get_sql_mode_select_variable( $query );
		if ( null !== $sql_mode_variable ) {
			$this->last_result      = array( (object) array( $sql_mode_variable => $this->sql_mode ) );
			$this->last_column_meta = array(
				array(
					'name'             => $sql_mode_variable,
					'table'            => '',
					'mysqli:orgtable'  => '',
					'mysqli:orgname'   => $sql_mode_variable,
					'mysqli:db'        => $this->db_name,
					'mysqli:charsetnr' => 45,
					'mysqli:flags'     => 0,
					'mysqli:type'      => 253,
					'len'              => 1024,
					'precision'        => 0,
					'native_type'      => 'string',
				),
			);
			return $this->last_result;
		}

		$show_variables_query = $this->get_show_variables_query( $query );
		if ( null !== $show_variables_query ) {
			return $this->execute_show_variables_query( $show_variables_query, $fetch_mode, ...$fetch_mode_args );
		}

		if ( $this->is_found_rows_query( $query ) ) {
			$this->last_result      = array( (object) array( 'FOUND_ROWS()' => (string) $this->last_found_rows ) );
			$this->last_column_meta = array(
				array(
					'name'             => 'FOUND_ROWS()',
					'table'            => '',
					'mysqli:orgtable'  => '',
					'mysqli:orgname'   => 'FOUND_ROWS()',
					'mysqli:db'        => $this->db_name,
					'mysqli:charsetnr' => 63,
					'mysqli:flags'     => 0,
					'mysqli:type'      => 8,
					'len'              => 20,
					'precision'        => 0,
					'native_type'      => 'integer',
				),
			);
			return $this->last_result;
		}

		if ( $this->is_create_table_query( $query ) ) {
			$translator = new WP_PostgreSQL_Create_Table_Translator();
			$result     = $this->execute_postgresql_statements( $translator->translate_schema( $query ) );
			if ( $this->is_temporary_create_table_query( $query ) ) {
				$this->store_mysql_temporary_schema_metadata( $query );
			} else {
				$this->store_mysql_schema_metadata( $query );
			}
			return $result;
		}

		$alter_query = $this->translate_mysql_dbdelta_alter_table_query( $query );
		if ( null !== $alter_query ) {
			$result = $this->execute_postgresql_statements( $alter_query['statements'] );
			$this->apply_mysql_dbdelta_alter_metadata( $alter_query['metadata'] );
			return $result;
		}

		$drop_query = $this->translate_mysql_drop_table_query( $query );
		if ( null !== $drop_query ) {
			$metadata_targets = $this->get_mysql_schema_metadata_drop_targets(
				$drop_query['tables'],
				$drop_query['temporary']
			);
			$result           = $this->execute_postgresql_statements( $drop_query['statements'] );
			$this->delete_mysql_schema_metadata_for_table_targets( $metadata_targets );
			return $result;
		}

		$describe_table_name = $this->get_describe_table_name( $query );
		if ( null !== $describe_table_name ) {
			return $this->execute_describe_query( $describe_table_name, $fetch_mode, ...$fetch_mode_args );
		}

		$show_tables_query = $this->get_show_tables_query( $query );
		if ( null !== $show_tables_query ) {
			return $this->execute_show_tables_query(
				$show_tables_query['full'],
				$show_tables_query['like'],
				$fetch_mode,
				...$fetch_mode_args
			);
		}

		$show_columns_query = $this->get_show_columns_query( $query );
		if ( null !== $show_columns_query ) {
			return $this->execute_show_columns_query(
				$show_columns_query['schema'],
				$show_columns_query['table'],
				$show_columns_query['full'],
				$show_columns_query['like'],
				$fetch_mode,
				...$fetch_mode_args
			);
		}

		$show_index_query = $this->get_show_index_query( $query );
		if ( null !== $show_index_query ) {
			return $this->execute_show_index_query(
				$show_index_query['table'],
				$show_index_query['key_name'],
				$fetch_mode,
				...$fetch_mode_args
			);
		}

		$translated_for_postgresql = false;
		$dml_identity_repair_query = null;

		$translated_query = $this->translate_wordpress_options_regexp_delete_query( $query );
		if ( null !== $translated_query ) {
			$query                     = $translated_query;
			$translated_for_postgresql = true;
		}

		$translated_query = $this->translate_wordpress_expired_transients_delete_query( $query );
		if ( null !== $translated_query ) {
			return $this->execute_postgresql_statements( array( $translated_query ) );
		}

		$translated_query = $this->translate_simple_mysql_delete_query( $query );
		if ( null !== $translated_query ) {
			$query                     = $translated_query;
			$translated_for_postgresql = true;
		}

		$translated_query = $this->translate_wordpress_options_upsert_query( $query );
		if ( null !== $translated_query ) {
			$query                     = $translated_query;
			$translated_for_postgresql = true;
		}

		$replace_return_value = null;
		$replace_query        = $this->translate_simple_mysql_replace_query( $query );
		if ( null !== $replace_query ) {
			if ( null !== $replace_query['conflict_column'] ) {
				$replace_conflict_exists           = $this->replace_conflict_exists(
					$replace_query['table_name'],
					$replace_query['conflict_column'],
					$replace_query['conflict_value']
				);
				$replace_return_value              = $replace_conflict_exists ? 2 : 1;
				$replace_query['inserted_new_row'] = ! $replace_conflict_exists;
			}
			$query                     = $replace_query['sql'];
			$dml_identity_repair_query = $replace_query;
			$translated_for_postgresql = true;
		}

		$insert_query = $this->translate_simple_mysql_insert_query( $query );
		if ( null !== $insert_query ) {
			$query                     = $insert_query['sql'];
			$dml_identity_repair_query = $insert_query;
			$translated_for_postgresql = true;
		}

		$translated_query = $this->translate_simple_mysql_update_query( $query );
		if ( null !== $translated_query ) {
			$query                     = $translated_query;
			$translated_for_postgresql = true;
		}

		$is_sql_calc_found_rows_query = $this->is_sql_calc_found_rows_select_query( $query );

		$translated_query = $this->translate_information_schema_tables_site_health_query( $query );
		if ( null !== $translated_query ) {
			$query                     = $translated_query;
			$translated_for_postgresql = true;
		}

		$translated_query = $this->translate_simple_mysql_select_query( $query );
		if ( null !== $translated_query ) {
			$query                     = $translated_query;
			$translated_for_postgresql = true;
		}

		$translated_query = $this->translate_distinct_order_by_query( $query );
		if ( null !== $translated_query ) {
			$query                     = $translated_query;
			$translated_for_postgresql = true;
		}

		$translated_query = $this->translate_sql_calc_found_rows_select_query( $query );
		if ( null !== $translated_query ) {
			$query                     = $translated_query;
			$translated_for_postgresql = true;
		}

		if ( ! $translated_for_postgresql ) {
			$translated_query = $this->translate_mysql_compatible_query( $query );
			if ( null !== $translated_query ) {
				$query = $translated_query;
			}
		}

		$stmt                            = $this->connection->query( $query );
		$this->last_postgresql_queries[] = array(
			'sql'    => $query,
			'params' => array(),
		);

		$affected_rows = $stmt->rowCount();

		if ( $stmt->columnCount() > 0 ) {
			$this->last_column_meta = $this->normalize_column_meta( $stmt );
			$this->last_result      = $stmt->fetchAll( $fetch_mode, ...$fetch_mode_args );
			if ( $is_sql_calc_found_rows_query ) {
				$this->last_found_rows = count( $this->last_result );
			}
		} else {
			$this->last_column_meta = array();
			$this->last_result      = $affected_rows;
			if ( null !== $replace_return_value ) {
				$this->last_result = $replace_return_value;
			}
		}

		if ( null !== $dml_identity_repair_query ) {
			$this->repair_dml_identity_sequences_after_success( $dml_identity_repair_query, $affected_rows );
		}

		return $this->last_result;
	}

	/**
	 * Execute translated PostgreSQL statements for a single MySQL-facing query.
	 *
	 * @param string[] $statements PostgreSQL SQL statements to execute.
	 * @return mixed Return value from the last executed statement.
	 */
	private function execute_postgresql_statements( array $statements ) {
		foreach ( $statements as $statement ) {
			$stmt                            = $this->connection->query( $statement );
			$this->last_postgresql_queries[] = array(
				'sql'    => $statement,
				'params' => array(),
			);
			$this->last_result               = $stmt->rowCount();
		}

		$this->last_column_meta = array();
		return $this->last_result;
	}

	/**
	 * Apply a supported MySQL SET NAMES statement to the emulated session.
	 *
	 * @param string $query MySQL query.
	 * @return bool Whether the query was handled.
	 */
	private function apply_mysql_set_names_query( string $query ): bool {
		$tokens = $this->get_mysql_tokens( $query );
		if (
			! isset( $tokens[0], $tokens[1], $tokens[2] )
			|| WP_MySQL_Lexer::SET_SYMBOL !== $tokens[0]->id
			|| WP_MySQL_Lexer::NAMES_SYMBOL !== $tokens[1]->id
			|| ! $this->is_mysql_charset_token( $tokens[2] )
		) {
			return false;
		}

		$charset   = $this->get_mysql_charset_token_value( $tokens[2] );
		$collation = null;
		$position  = 3;

		if ( isset( $tokens[ $position ] ) && WP_MySQL_Lexer::COLLATE_SYMBOL === $tokens[ $position ]->id ) {
			if ( ! isset( $tokens[ $position + 1 ] ) || ! $this->is_mysql_charset_token( $tokens[ $position + 1 ] ) ) {
				return false;
			}

			$collation = $this->get_mysql_charset_token_value( $tokens[ $position + 1 ] );
			$position += 2;
		}

		if ( ! $this->is_at_mysql_query_end( $tokens, $position ) ) {
			return false;
		}

		$this->set_charset( $charset, $collation );
		return true;
	}

	/**
	 * Create the MySQL schema metadata tables used by dbDelta emulation.
	 */
	private function ensure_mysql_schema_metadata_tables(): void {
		$this->connection->query(
			sprintf(
				'CREATE TABLE IF NOT EXISTS %s (
					table_schema TEXT NOT NULL,
					table_name TEXT NOT NULL,
					column_name TEXT NOT NULL,
					ordinal_position INTEGER NOT NULL,
					column_type TEXT NOT NULL,
					character_set_name TEXT,
					collation_name TEXT,
					is_nullable TEXT NOT NULL,
					column_default TEXT,
					extra TEXT NOT NULL,
					PRIMARY KEY (table_schema, table_name, column_name)
				)',
				$this->connection->quote_identifier( self::MYSQL_COLUMN_METADATA_TABLE )
			)
		);

		$this->ensure_mysql_column_metadata_column( 'character_set_name', 'TEXT' );
		$this->ensure_mysql_column_metadata_column( 'collation_name', 'TEXT' );

		$this->connection->query(
			sprintf(
				'CREATE TABLE IF NOT EXISTS %s (
					table_schema TEXT NOT NULL,
					table_name TEXT NOT NULL,
					key_name TEXT NOT NULL,
					index_ordinal INTEGER NOT NULL,
					seq_in_index INTEGER NOT NULL,
					column_name TEXT NOT NULL,
					non_unique TEXT NOT NULL,
					index_type TEXT NOT NULL,
					sub_part TEXT,
					nullable TEXT NOT NULL,
					PRIMARY KEY (table_schema, table_name, key_name, seq_in_index)
				)',
				$this->connection->quote_identifier( self::MYSQL_INDEX_METADATA_TABLE )
			)
		);
	}

	/**
	 * Add a MySQL column metadata field when upgrading an existing side table.
	 *
	 * @param string $column_name Column name.
	 * @param string $column_type Column type SQL.
	 */
	private function ensure_mysql_column_metadata_column( string $column_name, string $column_type ): void {
		if ( $this->mysql_column_metadata_column_exists( $column_name ) ) {
			return;
		}

		$this->connection->query(
			sprintf(
				'ALTER TABLE %s ADD COLUMN %s %s',
				$this->connection->quote_identifier( self::MYSQL_COLUMN_METADATA_TABLE ),
				$this->connection->quote_identifier( $column_name ),
				$column_type
			)
		);
	}

	/**
	 * Check whether a MySQL column metadata field exists.
	 *
	 * @param string $column_name Column name.
	 * @return bool Whether the column exists.
	 */
	private function mysql_column_metadata_column_exists( string $column_name ): bool {
		$driver_name = (string) $this->connection->get_pdo()->getAttribute( PDO::ATTR_DRIVER_NAME );
		if ( 'sqlite' === $driver_name ) {
			$stmt = $this->connection->query(
				sprintf(
					'PRAGMA table_info(%s)',
					$this->connection->quote_identifier( self::MYSQL_COLUMN_METADATA_TABLE )
				)
			);

			foreach ( $stmt->fetchAll( PDO::FETCH_ASSOC ) as $column ) {
				if ( isset( $column['name'] ) && $column_name === $column['name'] ) {
					return true;
				}
			}

			return false;
		}

		$stmt = $this->connection->query(
			'SELECT EXISTS (
				SELECT 1
				FROM information_schema.columns
				WHERE table_schema = current_schema()
					AND table_name = ?
					AND column_name = ?
			)',
			array( self::MYSQL_COLUMN_METADATA_TABLE, $column_name )
		);

		return (bool) $stmt->fetchColumn();
	}

	/**
	 * Store MySQL-facing schema metadata for translated CREATE TABLE statements.
	 *
	 * @param string $query MySQL CREATE TABLE query.
	 */
	public function store_mysql_schema_metadata( string $query ): void {
		if ( $this->is_temporary_create_table_query( $query ) ) {
			return;
		}

		$this->store_mysql_schema_metadata_for_schema( $query, 'public' );
	}

	/**
	 * Store MySQL-facing schema metadata for translated CREATE TEMPORARY TABLE statements.
	 *
	 * @param string $query MySQL CREATE TEMPORARY TABLE query.
	 */
	private function store_mysql_temporary_schema_metadata( string $query ): void {
		$this->store_mysql_schema_metadata_for_schema(
			$query,
			array( $this, 'get_temporary_schema_for_metadata_table' )
		);
	}

	/**
	 * Store MySQL-facing schema metadata for translated CREATE TABLE statements in one backend schema.
	 *
	 * @param string          $query        MySQL CREATE TABLE query.
	 * @param string|callable $table_schema Metadata schema, or resolver receiving the table name.
	 */
	private function store_mysql_schema_metadata_for_schema( string $query, $table_schema ): void {
		$this->ensure_mysql_schema_metadata_tables();

		$metadata_tables = ( new WP_PostgreSQL_Create_Table_Translator() )->extract_schema_metadata( $query, true );
		foreach ( $metadata_tables as $metadata ) {
			$schema_name = is_callable( $table_schema )
				? (string) call_user_func( $table_schema, $metadata['table_name'] )
				: (string) $table_schema;
			$table_name  = $metadata['table_name'];

			$this->delete_mysql_schema_metadata_for_tables( array( $table_name ), $schema_name );

			$column_nullable = array();
			foreach ( $metadata['columns'] as $column ) {
				$this->insert_mysql_column_metadata( $schema_name, $table_name, $column );
				$column_nullable[ strtolower( $column['name'] ) ] = $column['nullable'] ?? 'YES';
			}

			foreach ( $metadata['indexes'] ?? array() as $index ) {
				$this->insert_mysql_index_metadata( $schema_name, $table_name, $index, $column_nullable );
			}
		}
	}

	/**
	 * Get the metadata schema name for an active temporary table.
	 *
	 * @param string $table_name Table name.
	 * @return string Metadata schema name.
	 */
	private function get_temporary_schema_for_metadata_table( string $table_name ): string {
		$schema_name = $this->get_active_temporary_table_schema( $table_name );
		if ( null !== $schema_name ) {
			return $schema_name;
		}

		return $this->get_temporary_drop_table_schema_name();
	}

	/**
	 * Delete stored MySQL schema metadata for dropped tables.
	 *
	 * @param string[] $table_names Table names.
	 * @param string   $table_schema Metadata schema name.
	 */
	private function delete_mysql_schema_metadata_for_tables( array $table_names, string $table_schema = 'public' ): void {
		if ( empty( $table_names ) ) {
			return;
		}

		$this->ensure_mysql_schema_metadata_tables();

		foreach ( $table_names as $table_name ) {
			$params = array( $table_schema, $table_name );
			$this->connection->query(
				sprintf(
					'DELETE FROM %s WHERE table_schema = ? AND table_name = ?',
					$this->connection->quote_identifier( self::MYSQL_COLUMN_METADATA_TABLE )
				),
				$params
			);
			$this->connection->query(
				sprintf(
					'DELETE FROM %s WHERE table_schema = ? AND table_name = ?',
					$this->connection->quote_identifier( self::MYSQL_INDEX_METADATA_TABLE )
				),
				$params
			);
		}
	}

	/**
	 * Delete stored MySQL schema metadata for concrete schema/table targets.
	 *
	 * @param array[] $targets Metadata targets.
	 */
	private function delete_mysql_schema_metadata_for_table_targets( array $targets ): void {
		foreach ( $targets as $target ) {
			$this->delete_mysql_schema_metadata_for_tables(
				array( $target['table'] ),
				$target['schema']
			);
		}
	}

	/**
	 * Apply metadata changes for a translated dbDelta ALTER TABLE statement.
	 *
	 * @param array $metadata ALTER metadata.
	 */
	private function apply_mysql_dbdelta_alter_metadata( array $metadata ): void {
		$this->ensure_mysql_schema_metadata_tables();

		$table_schema = 'public';
		$table_name   = $metadata['table'];

		if ( 'add_column' === $metadata['operation'] ) {
			$column            = $metadata['column'];
			$column['ordinal'] = $this->get_next_mysql_column_ordinal( $table_schema, $table_name );
			$column_nullable   = array( strtolower( $column['name'] ) => $column['nullable'] ?? 'YES' );
			$this->insert_mysql_column_metadata( $table_schema, $table_name, $column );
			foreach ( $metadata['indexes'] ?? array() as $index ) {
				$this->insert_mysql_index_metadata( $table_schema, $table_name, $index, $column_nullable );
			}
			return;
		}

		if ( 'change_column' === $metadata['operation'] ) {
			$column            = $metadata['column'];
			$column['ordinal'] = $this->get_existing_mysql_column_ordinal(
				$table_schema,
				$table_name,
				$metadata['old_column']
			) ?? $this->get_next_mysql_column_ordinal( $table_schema, $table_name );

			$this->delete_mysql_column_metadata( $table_schema, $table_name, $metadata['old_column'] );
			$this->insert_mysql_column_metadata( $table_schema, $table_name, $column );
			$this->rename_mysql_index_column_metadata(
				$table_schema,
				$table_name,
				$metadata['old_column'],
				$column['name']
			);
			return;
		}

		if ( 'add_index' === $metadata['operation'] ) {
			$this->delete_mysql_index_metadata( $table_schema, $table_name, $metadata['index']['name'] );
			$this->insert_mysql_index_metadata( $table_schema, $table_name, $metadata['index'] );
			return;
		}

		if ( 'set_default' === $metadata['operation'] ) {
			$this->connection->query(
				sprintf(
					'UPDATE %s SET column_default = ? WHERE table_schema = ? AND table_name = ? AND column_name = ?',
					$this->connection->quote_identifier( self::MYSQL_COLUMN_METADATA_TABLE )
				),
				array( $metadata['default'], $table_schema, $table_name, $metadata['column'] )
			);
		}
	}

	/**
	 * Insert or replace column metadata.
	 *
	 * @param string $table_schema Table schema.
	 * @param string $table_name   Table name.
	 * @param array  $column       Column metadata.
	 */
	private function insert_mysql_column_metadata( string $table_schema, string $table_name, array $column ): void {
		$this->delete_mysql_column_metadata( $table_schema, $table_name, $column['name'] );
		$this->connection->query(
			sprintf(
				'INSERT INTO %s
					(table_schema, table_name, column_name, ordinal_position, column_type, character_set_name, collation_name, is_nullable, column_default, extra)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
				$this->connection->quote_identifier( self::MYSQL_COLUMN_METADATA_TABLE )
			),
			array(
				$table_schema,
				$table_name,
				$column['name'],
				$column['ordinal'],
				$column['type'],
				$column['charset'] ?? null,
				$column['collation'] ?? null,
				$column['nullable'] ?? 'YES',
				$column['default'] ?? null,
				$column['extra'] ?? '',
			)
		);
	}

	/**
	 * Delete metadata for one column.
	 *
	 * @param string $table_schema Table schema.
	 * @param string $table_name   Table name.
	 * @param string $column_name  Column name.
	 */
	private function delete_mysql_column_metadata( string $table_schema, string $table_name, string $column_name ): void {
		$this->connection->query(
			sprintf(
				'DELETE FROM %s WHERE table_schema = ? AND table_name = ? AND column_name = ?',
				$this->connection->quote_identifier( self::MYSQL_COLUMN_METADATA_TABLE )
			),
			array( $table_schema, $table_name, $column_name )
		);
	}

	/**
	 * Insert MySQL SHOW INDEX metadata rows for an index.
	 *
	 * @param string     $table_schema    Table schema.
	 * @param string     $table_name      Table name.
	 * @param array      $index           Index metadata.
	 * @param array|null $column_nullable Optional nullable metadata keyed by lowercase column.
	 */
	private function insert_mysql_index_metadata(
		string $table_schema,
		string $table_name,
		array $index,
		?array $column_nullable = null
	): void {
		$column_nullable = $column_nullable ?? array();

		foreach ( $index['columns'] as $column ) {
			$is_nullable = $column_nullable[ strtolower( $column['column_name'] ) ]
				?? $this->get_mysql_column_nullable( $table_schema, $table_name, $column['column_name'] );

			$this->connection->query(
				sprintf(
					'INSERT INTO %s
						(table_schema, table_name, key_name, index_ordinal, seq_in_index, column_name, non_unique, index_type, sub_part, nullable)
					VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
					$this->connection->quote_identifier( self::MYSQL_INDEX_METADATA_TABLE )
				),
				array(
					$table_schema,
					$table_name,
					$index['name'],
					$index['ordinal'],
					$column['seq_in_index'],
					$column['column_name'],
					$index['non_unique'],
					$index['index_type'],
					null === $column['sub_part'] ? null : (string) $column['sub_part'],
					'NO' === $is_nullable ? '' : 'YES',
				)
			);
		}
	}

	/**
	 * Delete metadata rows for one index.
	 *
	 * @param string $table_schema Table schema.
	 * @param string $table_name   Table name.
	 * @param string $index_name   Index name.
	 */
	private function delete_mysql_index_metadata( string $table_schema, string $table_name, string $index_name ): void {
		$this->connection->query(
			sprintf(
				'DELETE FROM %s WHERE table_schema = ? AND table_name = ? AND LOWER(key_name) = LOWER(?)',
				$this->connection->quote_identifier( self::MYSQL_INDEX_METADATA_TABLE )
			),
			array( $table_schema, $table_name, $index_name )
		);
	}

	/**
	 * Rename index column metadata after ALTER TABLE CHANGE COLUMN.
	 *
	 * @param string $table_schema    Table schema.
	 * @param string $table_name      Table name.
	 * @param string $old_column_name Old column name.
	 * @param string $new_column_name New column name.
	 */
	private function rename_mysql_index_column_metadata(
		string $table_schema,
		string $table_name,
		string $old_column_name,
		string $new_column_name
	): void {
		if ( $old_column_name === $new_column_name ) {
			return;
		}

		$this->connection->query(
			sprintf(
				'UPDATE %s SET column_name = ? WHERE table_schema = ? AND table_name = ? AND column_name = ?',
				$this->connection->quote_identifier( self::MYSQL_INDEX_METADATA_TABLE )
			),
			array( $new_column_name, $table_schema, $table_name, $old_column_name )
		);
	}

	/**
	 * Get the next stored column ordinal for a table.
	 *
	 * @param string $table_schema Table schema.
	 * @param string $table_name   Table name.
	 * @return int Next ordinal.
	 */
	private function get_next_mysql_column_ordinal( string $table_schema, string $table_name ): int {
		$stmt = $this->connection->query(
			sprintf(
				'SELECT COALESCE(MAX(ordinal_position), 0) + 1 FROM %s WHERE table_schema = ? AND table_name = ?',
				$this->connection->quote_identifier( self::MYSQL_COLUMN_METADATA_TABLE )
			),
			array( $table_schema, $table_name )
		);

		return (int) $stmt->fetchColumn();
	}

	/**
	 * Get an existing stored column ordinal.
	 *
	 * @param string $table_schema Table schema.
	 * @param string $table_name   Table name.
	 * @param string $column_name  Column name.
	 * @return int|null Existing ordinal, or null.
	 */
	private function get_existing_mysql_column_ordinal( string $table_schema, string $table_name, string $column_name ): ?int {
		$stmt = $this->connection->query(
			sprintf(
				'SELECT ordinal_position FROM %s WHERE table_schema = ? AND table_name = ? AND column_name = ?',
				$this->connection->quote_identifier( self::MYSQL_COLUMN_METADATA_TABLE )
			),
			array( $table_schema, $table_name, $column_name )
		);

		$ordinal = $stmt->fetchColumn();
		return false === $ordinal ? null : (int) $ordinal;
	}

	/**
	 * Get stored nullable metadata for an index column.
	 *
	 * @param string $table_schema Table schema.
	 * @param string $table_name   Table name.
	 * @param string $column_name  Column name.
	 * @return string MySQL nullable value.
	 */
	private function get_mysql_column_nullable( string $table_schema, string $table_name, string $column_name ): string {
		$stmt = $this->connection->query(
			sprintf(
				'SELECT is_nullable FROM %s WHERE table_schema = ? AND table_name = ? AND column_name = ?',
				$this->connection->quote_identifier( self::MYSQL_COLUMN_METADATA_TABLE )
			),
			array( $table_schema, $table_name, $column_name )
		);

		$nullable = $stmt->fetchColumn();
		return false === $nullable ? 'YES' : (string) $nullable;
	}

	/**
	 * Get the stored MySQL type for a table column.
	 *
	 * @param string $table_schema Metadata schema.
	 * @param string $table_name   Table name.
	 * @param string $column_name  Column name.
	 * @return string|null MySQL column type, or null when unavailable.
	 */
	private function get_mysql_table_column_type(
		string $table_schema,
		string $table_name,
		string $column_name
	): ?string {
		$this->ensure_mysql_schema_metadata_tables();

		$stmt = $this->connection->query(
			sprintf(
				'SELECT column_type FROM %s
				WHERE table_schema = ?
					AND table_name = ?
					AND LOWER(column_name) = LOWER(?)',
				$this->connection->quote_identifier( self::MYSQL_COLUMN_METADATA_TABLE )
			),
			array( $table_schema, $table_name, $column_name )
		);

		$column_type = $stmt->fetchColumn();
		return false === $column_type ? null : (string) $column_type;
	}

	/**
	 * Check whether stored MySQL metadata exists for a table.
	 *
	 * @param string $table_schema Metadata schema.
	 * @param string $table_name   Table name.
	 * @return bool Whether metadata exists.
	 */
	private function mysql_table_has_column_metadata( string $table_schema, string $table_name ): bool {
		$this->ensure_mysql_schema_metadata_tables();

		$stmt = $this->connection->query(
			sprintf(
				'SELECT 1 FROM %s WHERE table_schema = ? AND table_name = ? LIMIT 1',
				$this->connection->quote_identifier( self::MYSQL_COLUMN_METADATA_TABLE )
			),
			array( $table_schema, $table_name )
		);

		return false !== $stmt->fetchColumn();
	}

	/**
	 * Translate supported dbDelta ALTER TABLE statements to PostgreSQL.
	 *
	 * @param string $query MySQL ALTER TABLE query.
	 * @return array{statements: string[], metadata: array}|null Translation, or null when unsupported.
	 */
	private function translate_mysql_dbdelta_alter_table_query( string $query ): ?array {
		if ( ! preg_match( '/^\s*ALTER\s+TABLE\s+(?:`(?P<table_quoted>[^`]+)`|(?P<table>[A-Za-z0-9_]+))\s+(?P<clause>.+?)\s*;?\s*$/is', $query, $matches ) ) {
			return null;
		}

		$table_name = '' !== ( $matches['table_quoted'] ?? '' ) ? $matches['table_quoted'] : $matches['table'];
		$clause     = $this->trim_mysql_statement_fragment( $matches['clause'] );

		if ( preg_match( '/^CHANGE\s+(?:COLUMN\s+)?(?:`(?P<old_quoted>[^`]+)`|(?P<old>[A-Za-z0-9_]+))\s+(?P<definition>.+)$/is', $clause, $change_matches ) ) {
			$old_column = '' !== ( $change_matches['old_quoted'] ?? '' ) ? $change_matches['old_quoted'] : $change_matches['old'];
			$column     = $this->translate_mysql_column_definition_fragment( $change_matches['definition'] );
			if ( null === $column ) {
				return null;
			}

			$new_column = $column['metadata']['name'];
			$statements = array();
			if ( $old_column !== $new_column ) {
				$statements[] = sprintf(
					'ALTER TABLE %s RENAME COLUMN %s TO %s',
					$this->connection->quote_identifier( $table_name ),
					$this->connection->quote_identifier( $old_column ),
					$this->connection->quote_identifier( $new_column )
				);
			}

			$column_type                = $this->get_translated_column_type_from_definition_line( $column['sql'] );
			$preserve_existing_identity = $this->should_preserve_existing_identity_integer_column_change(
				'public',
				$table_name,
				$old_column,
				$column['metadata']
			);
			if ( '' !== $column_type && ! $preserve_existing_identity ) {
				$statements[] = sprintf(
					'ALTER TABLE %s ALTER COLUMN %s TYPE %s',
					$this->connection->quote_identifier( $table_name ),
					$this->connection->quote_identifier( $new_column ),
					$column_type
				);
			}

			$statements[] = sprintf(
				'ALTER TABLE %s ALTER COLUMN %s %s NOT NULL',
				$this->connection->quote_identifier( $table_name ),
				$this->connection->quote_identifier( $new_column ),
				'NO' === ( $column['metadata']['nullable'] ?? 'YES' ) ? 'SET' : 'DROP'
			);

			$default_sql = $this->get_translated_column_default_from_definition_line( $column['sql'] );
			if ( ! $preserve_existing_identity ) {
				if ( null !== $default_sql ) {
					$statements[] = sprintf(
						'ALTER TABLE %s ALTER COLUMN %s SET DEFAULT %s',
						$this->connection->quote_identifier( $table_name ),
						$this->connection->quote_identifier( $new_column ),
						$default_sql
					);
				} else {
					$statements[] = sprintf(
						'ALTER TABLE %s ALTER COLUMN %s DROP DEFAULT',
						$this->connection->quote_identifier( $table_name ),
						$this->connection->quote_identifier( $new_column )
					);
				}
			}

			return array(
				'statements' => $statements,
				'metadata'   => array(
					'operation'  => 'change_column',
					'table'      => $table_name,
					'old_column' => $old_column,
					'column'     => $column['metadata'],
				),
			);
		}

		if ( preg_match( '/^ADD\s+COLUMN\s+(?P<definition>.+)$/is', $clause, $add_column_matches ) ) {
			$column = $this->translate_mysql_column_definition_fragment( $add_column_matches['definition'] );
			if ( null === $column ) {
				return null;
			}

			return array(
				'statements' => array(
					sprintf(
						'ALTER TABLE %s ADD COLUMN %s',
						$this->connection->quote_identifier( $table_name ),
						$column['sql']
					),
				),
				'metadata'   => array(
					'operation' => 'add_column',
					'table'     => $table_name,
					'column'    => $column['metadata'],
				),
			);
		}

		if ( preg_match( '/^ADD\s+(?P<definition>(?:PRIMARY\s+KEY|(?:UNIQUE\s+)?(?:FULLTEXT\s+|SPATIAL\s+)?(?:KEY|INDEX))\b.+)$/is', $clause, $add_index_matches ) ) {
			$index = $this->translate_mysql_index_definition_fragment( $table_name, $add_index_matches['definition'] );
			if ( null === $index ) {
				return null;
			}

			return array(
				'statements' => $index['statements'],
				'metadata'   => array(
					'operation' => 'add_index',
					'table'     => $table_name,
					'index'     => $index['metadata'],
				),
			);
		}

		if ( preg_match( '/^ALTER\s+COLUMN\s+(?:`(?P<column_quoted>[^`]+)`|(?P<column>[A-Za-z0-9_]+))\s+SET\s+DEFAULT\s+(?P<default>.+)$/is', $clause, $default_matches ) ) {
			$column_name = '' !== ( $default_matches['column_quoted'] ?? '' ) ? $default_matches['column_quoted'] : $default_matches['column'];
			$default     = $this->translate_mysql_default_fragment( $default_matches['default'] );
			if ( null === $default ) {
				return null;
			}

			return array(
				'statements' => array(
					sprintf(
						'ALTER TABLE %s ALTER COLUMN %s SET DEFAULT %s',
						$this->connection->quote_identifier( $table_name ),
						$this->connection->quote_identifier( $column_name ),
						$default['sql']
					),
				),
				'metadata'   => array(
					'operation' => 'set_default',
					'table'     => $table_name,
					'column'    => $column_name,
					'default'   => $default['metadata'],
				),
			);
		}

		return null;
	}

	/**
	 * Check whether an AUTO_INCREMENT CHANGE COLUMN should leave PostgreSQL identity DDL untouched.
	 *
	 * @param string $table_schema Table schema.
	 * @param string $table_name   Table name.
	 * @param string $old_column   Existing column name.
	 * @param array  $column       Replacement MySQL metadata.
	 * @return bool Whether the physical type/default changes should be metadata-only.
	 */
	private function should_preserve_existing_identity_integer_column_change(
		string $table_schema,
		string $table_name,
		string $old_column,
		array $column
	): bool {
		if ( 'auto_increment' !== strtolower( (string) ( $column['extra'] ?? '' ) ) ) {
			return false;
		}

		if ( ! $this->is_mysql_integer_family_column_type( (string) ( $column['type'] ?? '' ) ) ) {
			return false;
		}

		$existing = $this->get_existing_dbdelta_column_identity_metadata( $table_schema, $table_name, $old_column );
		if ( null === $existing || ! $this->is_existing_dbdelta_column_identity( $existing ) ) {
			return false;
		}

		$existing_mysql_type = (string) ( $existing['mysql_column_type'] ?? '' );
		if ( '' !== $existing_mysql_type ) {
			return $this->is_mysql_integer_family_column_type( $existing_mysql_type );
		}

		return $this->is_postgresql_integer_family_data_type( (string) ( $existing['data_type'] ?? '' ) );
	}

	/**
	 * Get catalog and MySQL metadata for an existing dbDelta column.
	 *
	 * @param string $table_schema Table schema.
	 * @param string $table_name   Table name.
	 * @param string $column_name  Column name.
	 * @return array|null Existing column metadata, or null.
	 */
	private function get_existing_dbdelta_column_identity_metadata( string $table_schema, string $table_name, string $column_name ): ?array {
		$this->ensure_mysql_schema_metadata_tables();

		$stmt = $this->connection->query(
			sprintf(
				'SELECT
					c.data_type,
					c.is_identity,
					c.column_default,
					cm.column_type AS mysql_column_type,
					cm.extra AS mysql_extra
				FROM information_schema.columns c
				LEFT JOIN %s cm
					ON cm.table_schema = c.table_schema
					AND cm.table_name = c.table_name
					AND cm.column_name = c.column_name
				WHERE c.table_schema = ?
					AND c.table_name = ?
					AND c.column_name = ?',
				$this->connection->quote_identifier( self::MYSQL_COLUMN_METADATA_TABLE )
			),
			array( $table_schema, $table_name, $column_name )
		);

		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		return false === $row ? null : $row;
	}

	/**
	 * Check whether existing metadata describes a PostgreSQL identity column.
	 *
	 * @param array $metadata Existing column metadata.
	 * @return bool Whether the column is identity/auto_increment.
	 */
	private function is_existing_dbdelta_column_identity( array $metadata ): bool {
		if ( 'auto_increment' === strtolower( (string) ( $metadata['mysql_extra'] ?? '' ) ) ) {
			return true;
		}

		if ( 'YES' === strtoupper( (string) ( $metadata['is_identity'] ?? '' ) ) ) {
			return true;
		}

		$column_default = ltrim( (string) ( $metadata['column_default'] ?? '' ) );
		return 0 === stripos( $column_default, 'nextval(' );
	}

	/**
	 * Check whether a MySQL column type is part of the integer family.
	 *
	 * @param string $column_type MySQL column type.
	 * @return bool Whether the type is integer-like.
	 */
	private function is_mysql_integer_family_column_type( string $column_type ): bool {
		$column_type = strtolower( trim( $column_type ) );
		$column_type = preg_replace( '/\s+unsigned\b/i', '', $column_type );
		$column_type = trim( (string) $column_type );

		return (bool) preg_match( '/^(?:bigint|int|integer|mediumint|smallint|tinyint)(?:\(\d+\))?$/', $column_type );
	}

	/**
	 * Check whether a PostgreSQL catalog data type is integer-like.
	 *
	 * @param string $data_type PostgreSQL information_schema data_type.
	 * @return bool Whether the type is integer-like.
	 */
	private function is_postgresql_integer_family_data_type( string $data_type ): bool {
		return in_array( strtolower( trim( $data_type ) ), array( 'bigint', 'integer', 'smallint' ), true );
	}

	/**
	 * Translate supported DROP TABLE statements and expose dropped table names.
	 *
	 * @param string $query MySQL DROP TABLE query.
	 * @return array{statements: string[], tables: string[], temporary: bool}|null Translation, or null when unsupported.
	 */
	private function translate_mysql_drop_table_query( string $query ): ?array {
		if ( ! preg_match( '/^\s*DROP\s+(?P<temporary>TEMPORARY\s+)?TABLE\s+(?P<if_exists>IF\s+EXISTS\s+)?(?P<tables>.+?)\s*;?\s*$/is', $query, $matches ) ) {
			return null;
		}

		$table_names = $this->parse_mysql_identifier_csv( $matches['tables'] );
		if ( null === $table_names ) {
			return null;
		}

		$temporary         = ! empty( $matches['temporary'] );
		$table_identifiers = array();
		foreach ( $table_names as $table_name ) {
			$table_identifiers[] = $temporary
				? $this->get_temporary_drop_table_identifier( $table_name )
				: $this->connection->quote_identifier( $table_name );
		}

		return array(
			'statements' => array(
				sprintf(
					'DROP TABLE %s%s',
					! empty( $matches['if_exists'] ) ? 'IF EXISTS ' : '',
					implode( ', ', $table_identifiers )
				),
			),
			'tables'     => $table_names,
			'temporary'  => $temporary,
		);
	}

	/**
	 * Get the MySQL metadata rows that should be removed after a DROP TABLE.
	 *
	 * @param string[] $table_names Table names.
	 * @param bool     $temporary   Whether the DROP TABLE explicitly targets temporary tables.
	 * @return array[] Metadata targets.
	 */
	private function get_mysql_schema_metadata_drop_targets( array $table_names, bool $temporary ): array {
		$targets = array();

		foreach ( $table_names as $table_name ) {
			$temporary_schema = $this->get_active_temporary_table_schema( $table_name );
			if ( null !== $temporary_schema ) {
				$targets[] = array(
					'schema' => $temporary_schema,
					'table'  => $table_name,
				);
				continue;
			}

			if ( ! $temporary ) {
				$targets[] = array(
					'schema' => 'public',
					'table'  => $table_name,
				);
			}
		}

		return $targets;
	}

	/**
	 * Get the backend table identifier for a MySQL DROP TEMPORARY TABLE target.
	 *
	 * @param string $table_name MySQL table identifier value.
	 * @return string PostgreSQL table identifier constrained to the temporary schema.
	 */
	private function get_temporary_drop_table_identifier( string $table_name ): string {
		return $this->get_temporary_drop_table_schema_name() . '.' . $this->connection->quote_identifier( $table_name );
	}

	/**
	 * Get the backend temporary schema name.
	 *
	 * @return string Backend temporary schema name.
	 */
	private function get_temporary_drop_table_schema_name(): string {
		$driver_name = (string) $this->connection->get_pdo()->getAttribute( PDO::ATTR_DRIVER_NAME );

		if ( 'sqlite' === $driver_name ) {
			return 'temp';
		}

		return 'pg_temp';
	}

	/**
	 * Translate a MySQL column definition fragment via the CREATE TABLE translator.
	 *
	 * @param string $definition MySQL column definition.
	 * @return array{sql: string, metadata: array}|null Translated column, or null when unsupported.
	 */
	private function translate_mysql_column_definition_fragment( string $definition ): ?array {
		$definition = $this->trim_mysql_statement_fragment( $definition );
		$translator = new WP_PostgreSQL_Create_Table_Translator();
		$wrapper    = 'CREATE TABLE __wp_dbdelta_column (' . $definition . ')';

		$statements = $translator->translate_schema( $wrapper );
		$metadata   = $translator->extract_schema_metadata( $wrapper, true );
		if ( ! isset( $metadata[0]['columns'][0] ) ) {
			return null;
		}

		return array(
			'sql'      => $this->get_first_translated_create_table_definition( $statements[0] ),
			'metadata' => $metadata[0]['columns'][0],
		);
	}

	/**
	 * Translate a MySQL index definition fragment via the CREATE TABLE translator.
	 *
	 * @param string $table_name Table name receiving the index.
	 * @param string $definition MySQL index definition.
	 * @return array{statements: string[], metadata: array}|null Translated index, or null when unsupported.
	 */
	private function translate_mysql_index_definition_fragment( string $table_name, string $definition ): ?array {
		$definition = $this->trim_mysql_statement_fragment( $definition );
		$translator = new WP_PostgreSQL_Create_Table_Translator();
		$wrapper    = 'CREATE TABLE __wp_dbdelta_index (__wp_dummy int, ' . $definition . ')';

		$metadata = $translator->extract_schema_metadata( $wrapper, true );
		if ( ! isset( $metadata[0]['indexes'][0] ) ) {
			return null;
		}

		$index   = $metadata[0]['indexes'][0];
		$columns = array();
		foreach ( $index['columns'] as $column ) {
			$columns[] = $this->connection->quote_identifier( $column['column_name'] );
		}

		if ( 'PRIMARY' === strtoupper( $index['name'] ) ) {
			$statement = sprintf(
				'ALTER TABLE %s ADD PRIMARY KEY (%s)',
				$this->connection->quote_identifier( $table_name ),
				implode( ', ', $columns )
			);
		} else {
			$statement = sprintf(
				'CREATE %sINDEX %s ON %s (%s)',
				'0' === $index['non_unique'] ? 'UNIQUE ' : '',
				$this->connection->quote_identifier( $table_name . '__' . $index['name'] ),
				$this->connection->quote_identifier( $table_name ),
				implode( ', ', $columns )
			);
		}

		return array(
			'statements' => array( $statement ),
			'metadata'   => $index,
		);
	}

	/**
	 * Extract the first definition from a translated CREATE TABLE statement.
	 *
	 * @param string $create_table_sql Translated CREATE TABLE statement.
	 * @return string First definition line.
	 */
	private function get_first_translated_create_table_definition( string $create_table_sql ): string {
		if ( ! preg_match( "/\\(\\n  (?P<definitions>.*)\\n\\)\\z/s", $create_table_sql, $matches ) ) {
			throw new InvalidArgumentException( 'Translated CREATE TABLE statement has an unexpected shape.' );
		}

		$definitions = explode( ",\n  ", $matches['definitions'] );
		return $definitions[0];
	}

	/**
	 * Extract PostgreSQL type SQL from a translated column definition line.
	 *
	 * @param string $definition_line Translated column definition line.
	 * @return string PostgreSQL type SQL.
	 */
	private function get_translated_column_type_from_definition_line( string $definition_line ): string {
		if ( ! preg_match( '/^"(?:""|[^"])+"\s+(?P<definition>.+)$/s', $definition_line, $matches ) ) {
			return '';
		}

		$definition = $matches['definition'];
		$stop_at    = strlen( $definition );
		foreach ( array( ' GENERATED ', ' NOT NULL', ' DEFAULT ' ) as $marker ) {
			$position = stripos( $definition, $marker );
			if ( false !== $position && $position < $stop_at ) {
				$stop_at = $position;
			}
		}

		return trim( substr( $definition, 0, $stop_at ) );
	}

	/**
	 * Extract PostgreSQL DEFAULT SQL from a translated column definition line.
	 *
	 * @param string $definition_line Translated column definition line.
	 * @return string|null Default SQL, or null when absent.
	 */
	private function get_translated_column_default_from_definition_line( string $definition_line ): ?string {
		$definition_line = preg_replace(
			'/\s+GENERATED\s+BY\s+DEFAULT\s+AS\s+IDENTITY\b/i',
			'',
			$definition_line
		);

		if ( ! preg_match( '/\sDEFAULT\s+(?P<default>.+)$/is', $definition_line, $matches ) ) {
			return null;
		}

		return trim( $matches['default'] );
	}

	/**
	 * Translate a simple MySQL DEFAULT fragment.
	 *
	 * @param string $fragment Default expression fragment.
	 * @return array{sql: string, metadata: string|null}|null Translated default, or null when unsupported.
	 */
	private function translate_mysql_default_fragment( string $fragment ): ?array {
		$fragment = $this->trim_mysql_statement_fragment( $fragment );
		$tokens   = $this->get_mysql_tokens( $fragment );
		$end      = $this->get_mysql_statement_end_position( $tokens, 0 );
		if ( 1 !== $end ) {
			return null;
		}

		$token = $tokens[0];
		if ( WP_MySQL_Lexer::NULL_SYMBOL === $token->id ) {
			return array(
				'sql'      => 'NULL',
				'metadata' => null,
			);
		}

		if (
			WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id
			|| WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $token->id
			|| WP_MySQL_Lexer::INT_NUMBER === $token->id
			|| WP_MySQL_Lexer::LONG_NUMBER === $token->id
			|| WP_MySQL_Lexer::ULONGLONG_NUMBER === $token->id
			|| WP_MySQL_Lexer::DECIMAL_NUMBER === $token->id
			|| WP_MySQL_Lexer::FLOAT_NUMBER === $token->id
		) {
			return array(
				'sql'      => $this->translate_mysql_token_to_postgresql( $token ),
				'metadata' => $token->get_value(),
			);
		}

		return null;
	}

	/**
	 * Parse a comma-separated list of simple MySQL identifiers.
	 *
	 * @param string $identifiers Identifier list.
	 * @return string[]|null Identifier values, or null when unsupported.
	 */
	private function parse_mysql_identifier_csv( string $identifiers ): ?array {
		$values = array();

		foreach ( explode( ',', $identifiers ) as $identifier ) {
			$identifier = trim( $identifier );
			if ( preg_match( '/^`([^`]+)`$/', $identifier, $matches ) ) {
				$values[] = $matches[1];
				continue;
			}

			if ( preg_match( '/^[A-Za-z0-9_]+$/', $identifier ) ) {
				$values[] = $identifier;
				continue;
			}

			return null;
		}

		return $values;
	}

	/**
	 * Trim a MySQL statement fragment.
	 *
	 * @param string $fragment SQL fragment.
	 * @return string Trimmed fragment.
	 */
	private function trim_mysql_statement_fragment( string $fragment ): string {
		return rtrim( trim( $fragment ), "; \t\n\r\0\x0B" );
	}

	/**
	 * Emulate the narrow stored procedure surface used by WordPress tests.
	 *
	 * @param string $query              MySQL query.
	 * @param int    $fetch_mode         PDO fetch mode.
	 * @param array  ...$fetch_mode_args Additional fetch mode arguments.
	 * @return mixed|null Query result, or null when the query is not a supported procedure statement.
	 */
	private function handle_mysql_procedure_query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		if ( preg_match( '/^\s*DROP\s+PROCEDURE\s+IF\s+EXISTS\s+`?([A-Za-z0-9_]+)`?\s*;?\s*$/i', $query, $matches ) ) {
			unset( $this->procedures[ strtolower( $matches[1] ) ] );
			$this->last_result      = 0;
			$this->last_column_meta = array();
			return $this->last_result;
		}

		if ( preg_match( '/^\s*CREATE\s+PROCEDURE\s+`?([A-Za-z0-9_]+)`?\s*\(\s*\)\s+BEGIN\s+(.*?)\s*;\s*END\s*;?\s*$/is', $query, $matches ) ) {
			$this->procedures[ strtolower( $matches[1] ) ] = trim( $matches[2] );
			$this->last_result                             = 0;
			$this->last_column_meta                        = array();
			return $this->last_result;
		}

		if ( preg_match( '/^\s*SHOW\s+CREATE\s+PROCEDURE\s+`?([A-Za-z0-9_]+)`?\s*;?\s*$/i', $query, $matches ) ) {
			$name = strtolower( $matches[1] );
			if ( ! isset( $this->procedures[ $name ] ) ) {
				$this->last_result      = array();
				$this->last_column_meta = array();
				return $this->last_result;
			}

			$this->last_result      = array(
				(object) array(
					'Procedure'        => $matches[1],
					'sql_mode'         => $this->sql_mode,
					'Create Procedure' => 'CREATE PROCEDURE `' . $matches[1] . '`() BEGIN ' . $this->procedures[ $name ] . '; END',
				),
			);
			$this->last_column_meta = array();
			return $this->last_result;
		}

		if ( preg_match( '/^\s*CALL\s+`?([A-Za-z0-9_]+)`?\s*(?:\(\s*\))?\s*;?\s*$/i', $query, $matches ) ) {
			$name = strtolower( $matches[1] );
			if ( ! isset( $this->procedures[ $name ] ) ) {
				return null;
			}

			return $this->query( $this->procedures[ $name ], $fetch_mode, ...$fetch_mode_args );
		}

		return null;
	}

	/**
	 * Get the table name from a supported MySQL DESCRIBE/DESC statement.
	 *
	 * @param string $query MySQL query.
	 * @return string|null Table name, or null when the statement is unsupported.
	 */
	private function get_describe_table_name( string $query ): ?string {
		$tokens = $this->get_mysql_tokens( $query );
		if (
			! isset( $tokens[0] )
			|| (
				WP_MySQL_Lexer::DESCRIBE_SYMBOL !== $tokens[0]->id
				&& WP_MySQL_Lexer::DESC_SYMBOL !== $tokens[0]->id
			)
		) {
			return null;
		}

		$table_name = $this->get_mysql_identifier_token_value( $tokens[1] ?? null );
		if ( null === $table_name || ! $this->is_at_mysql_query_end( $tokens, 2 ) ) {
			return null;
		}

		return $table_name;
	}

	/**
	 * Parse a supported MySQL SHOW TABLES statement.
	 *
	 * @param string $query MySQL query.
	 * @return array{full: bool, like: string|null}|null SHOW TABLES options, or null when unsupported.
	 */
	private function get_show_tables_query( string $query ): ?array {
		$tokens = $this->get_mysql_tokens( $query );
		if ( ! isset( $tokens[0] ) || WP_MySQL_Lexer::SHOW_SYMBOL !== $tokens[0]->id ) {
			return null;
		}

		$position = 1;
		$is_full  = false;
		if ( isset( $tokens[ $position ] ) && WP_MySQL_Lexer::FULL_SYMBOL === $tokens[ $position ]->id ) {
			$is_full = true;
			++$position;
		}

		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::TABLES_SYMBOL !== $tokens[ $position ]->id ) {
			return null;
		}

		++$position;
		$like = null;
		if ( isset( $tokens[ $position ] ) && WP_MySQL_Lexer::LIKE_SYMBOL === $tokens[ $position ]->id ) {
			if (
				! isset( $tokens[ $position + 1 ] )
				|| (
					WP_MySQL_Lexer::SINGLE_QUOTED_TEXT !== $tokens[ $position + 1 ]->id
					&& WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT !== $tokens[ $position + 1 ]->id
				)
			) {
				return null;
			}

			$like      = $tokens[ $position + 1 ]->get_value();
			$position += 2;
		}

		if ( ! $this->is_at_mysql_query_end( $tokens, $position ) ) {
			return null;
		}

		return array(
			'full' => $is_full,
			'like' => $like,
		);
	}

	/**
	 * Parse a supported MySQL SHOW VARIABLES statement.
	 *
	 * @param string $query MySQL query.
	 * @return array{type: string, pattern: string}|null SHOW VARIABLES options, or null when unsupported.
	 */
	private function get_show_variables_query( string $query ): ?array {
		$tokens = $this->get_mysql_tokens( $query );
		if (
			! isset( $tokens[0], $tokens[1] )
			|| WP_MySQL_Lexer::SHOW_SYMBOL !== $tokens[0]->id
			|| WP_MySQL_Lexer::VARIABLES_SYMBOL !== $tokens[1]->id
		) {
			return null;
		}

		if (
			isset( $tokens[2], $tokens[3] )
			&& WP_MySQL_Lexer::LIKE_SYMBOL === $tokens[2]->id
			&& ( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $tokens[3]->id || WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $tokens[3]->id )
			&& $this->is_at_mysql_query_end( $tokens, 4 )
		) {
			return array(
				'type'    => 'like',
				'pattern' => strtolower( $tokens[3]->get_value() ),
			);
		}

		if (
			isset( $tokens[2], $tokens[3], $tokens[4], $tokens[5] )
			&& WP_MySQL_Lexer::WHERE_SYMBOL === $tokens[2]->id
			&& WP_MySQL_Lexer::IDENTIFIER === $tokens[3]->id
			&& 'variable_name' === strtolower( $tokens[3]->get_value() )
			&& WP_MySQL_Lexer::EQUAL_OPERATOR === $tokens[4]->id
			&& ( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $tokens[5]->id || WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $tokens[5]->id )
			&& $this->is_at_mysql_query_end( $tokens, 6 )
		) {
			return array(
				'type'    => 'exact',
				'pattern' => strtolower( $tokens[5]->get_value() ),
			);
		}

		return null;
	}

	/**
	 * Parse a supported MySQL SHOW COLUMNS/SHOW FULL COLUMNS statement.
	 *
	 * @param string $query MySQL query.
	 * @return array{schema: string, table: string, full: bool, like: string|null}|null SHOW COLUMNS options, or null when this is not a SHOW COLUMNS statement.
	 */
	private function get_show_columns_query( string $query ): ?array {
		$tokens = $this->get_mysql_tokens( $query );
		if ( ! isset( $tokens[0] ) || WP_MySQL_Lexer::SHOW_SYMBOL !== $tokens[0]->id ) {
			return null;
		}

		$position = 1;
		if ( isset( $tokens[ $position ] ) && WP_MySQL_Lexer::EXTENDED_SYMBOL === $tokens[ $position ]->id ) {
			++$position;
		}

		$is_full = false;
		if ( isset( $tokens[ $position ] ) && WP_MySQL_Lexer::FULL_SYMBOL === $tokens[ $position ]->id ) {
			$is_full = true;
			++$position;
		}

		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::COLUMNS_SYMBOL !== $tokens[ $position ]->id ) {
			return null;
		}

		++$position;
		if (
			! isset( $tokens[ $position ] )
			|| (
				WP_MySQL_Lexer::FROM_SYMBOL !== $tokens[ $position ]->id
				&& WP_MySQL_Lexer::IN_SYMBOL !== $tokens[ $position ]->id
			)
		) {
			throw new InvalidArgumentException( 'Unsupported SHOW COLUMNS statement.' );
		}

		++$position;
		$table_reference = $this->get_show_columns_table_reference( $tokens, $position );
		if ( null === $table_reference ) {
			throw new InvalidArgumentException( 'Unsupported SHOW COLUMNS statement.' );
		}

		$schema_name = $table_reference['schema'] ?? 'public';
		$table_name  = $table_reference['table'];
		$position    = $table_reference['position'];

		if (
			isset( $tokens[ $position ] )
			&& (
				WP_MySQL_Lexer::FROM_SYMBOL === $tokens[ $position ]->id
				|| WP_MySQL_Lexer::IN_SYMBOL === $tokens[ $position ]->id
			)
		) {
			if ( null !== $table_reference['schema'] ) {
				throw new InvalidArgumentException( 'Unsupported SHOW COLUMNS statement.' );
			}

			$schema_name = $this->get_mysql_identifier_token_value( $tokens[ $position + 1 ] ?? null );
			if ( null === $schema_name ) {
				throw new InvalidArgumentException( 'Unsupported SHOW COLUMNS statement.' );
			}

			$position += 2;
		}

		$like = null;
		if ( isset( $tokens[ $position ] ) && WP_MySQL_Lexer::LIKE_SYMBOL === $tokens[ $position ]->id ) {
			if (
				! isset( $tokens[ $position + 1 ] )
				|| (
					WP_MySQL_Lexer::SINGLE_QUOTED_TEXT !== $tokens[ $position + 1 ]->id
					&& WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT !== $tokens[ $position + 1 ]->id
				)
			) {
				throw new InvalidArgumentException( 'Unsupported SHOW COLUMNS statement.' );
			}

			$like      = $tokens[ $position + 1 ]->get_value();
			$position += 2;
		}

		if ( ! $this->is_at_mysql_query_end( $tokens, $position ) ) {
			throw new InvalidArgumentException( 'Unsupported SHOW COLUMNS statement.' );
		}

		return array(
			'schema' => $schema_name,
			'table'  => $table_name,
			'full'   => $is_full,
			'like'   => $like,
		);
	}

	/**
	 * Parse a SHOW COLUMNS table reference.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Table reference start position.
	 * @return array{schema: string|null, table: string, position: int}|null Parsed reference, or null when unsupported.
	 */
	private function get_show_columns_table_reference( array $tokens, int $position ): ?array {
		$first_identifier = $this->get_mysql_identifier_token_value( $tokens[ $position ] ?? null );
		if ( null === $first_identifier ) {
			return null;
		}

		++$position;
		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::DOT_SYMBOL !== $tokens[ $position ]->id ) {
			return array(
				'schema'   => null,
				'table'    => $first_identifier,
				'position' => $position,
			);
		}

		$table_name = $this->get_mysql_identifier_token_value( $tokens[ $position + 1 ] ?? null );
		if ( null === $table_name ) {
			return null;
		}

		return array(
			'schema'   => $first_identifier,
			'table'    => $table_name,
			'position' => $position + 2,
		);
	}

	/**
	 * Parse a supported MySQL SHOW INDEX/SHOW INDEXES statement.
	 *
	 * @param string $query MySQL query.
	 * @return array{table: string, key_name: string|null}|null SHOW INDEX options, or null when unsupported.
	 */
	private function get_show_index_query( string $query ): ?array {
		$tokens = $this->get_mysql_tokens( $query );
		if ( ! isset( $tokens[0], $tokens[1] ) || WP_MySQL_Lexer::SHOW_SYMBOL !== $tokens[0]->id ) {
			return null;
		}

		if (
			WP_MySQL_Lexer::INDEX_SYMBOL !== $tokens[1]->id
			&& WP_MySQL_Lexer::INDEXES_SYMBOL !== $tokens[1]->id
		) {
			return null;
		}

		if ( ! isset( $tokens[2] ) || WP_MySQL_Lexer::FROM_SYMBOL !== $tokens[2]->id ) {
			return null;
		}

		$table_name = $this->get_mysql_identifier_token_value( $tokens[3] ?? null );
		if ( null === $table_name ) {
			return null;
		}

		$position = 4;
		$key_name = null;
		if ( isset( $tokens[ $position ] ) && WP_MySQL_Lexer::WHERE_SYMBOL === $tokens[ $position ]->id ) {
			$where_column = $this->get_mysql_identifier_token_value( $tokens[ $position + 1 ] ?? null );
			if (
				null === $where_column
				|| 'key_name' !== strtolower( $where_column )
				|| ! isset( $tokens[ $position + 2 ], $tokens[ $position + 3 ] )
				|| WP_MySQL_Lexer::EQUAL_OPERATOR !== $tokens[ $position + 2 ]->id
				|| (
					WP_MySQL_Lexer::SINGLE_QUOTED_TEXT !== $tokens[ $position + 3 ]->id
					&& WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT !== $tokens[ $position + 3 ]->id
				)
			) {
				return null;
			}

			$key_name  = $tokens[ $position + 3 ]->get_value();
			$position += 4;
		}

		if ( ! $this->is_at_mysql_query_end( $tokens, $position ) ) {
			return null;
		}

		return array(
			'table'    => $table_name,
			'key_name' => $key_name,
		);
	}

	/**
	 * Execute a MySQL DESCRIBE/DESC statement through PostgreSQL catalogs.
	 *
	 * @param string $table_name          Table name.
	 * @param int    $fetch_mode          PDO fetch mode.
	 * @param array  ...$fetch_mode_args  Additional fetch mode arguments.
	 * @return mixed DESCRIBE result rows.
	 */
	private function execute_describe_query( string $table_name, $fetch_mode, ...$fetch_mode_args ) {
		$this->ensure_mysql_schema_metadata_tables();

		$sql    = $this->get_describe_catalog_query();
		$params = array(
			$this->resolve_mysql_table_schema_for_introspection( 'public', $table_name ),
			$table_name,
		);
		$stmt   = $this->connection->query( $sql, $params );

		$this->last_postgresql_queries[] = array(
			'sql'    => $sql,
			'params' => $params,
		);
		$this->last_column_meta          = $this->normalize_column_meta( $stmt );
		$this->last_result               = $stmt->fetchAll( $fetch_mode, ...$fetch_mode_args );

		return $this->last_result;
	}

	/**
	 * Execute a MySQL SHOW COLUMNS/SHOW FULL COLUMNS statement through PostgreSQL catalogs.
	 *
	 * @param string      $schema_name         Schema name.
	 * @param string      $table_name          Table name.
	 * @param bool        $is_full             Whether this is SHOW FULL COLUMNS.
	 * @param string|null $like                Optional MySQL LIKE pattern.
	 * @param int         $fetch_mode          PDO fetch mode.
	 * @param array       ...$fetch_mode_args  Additional fetch mode arguments.
	 * @return mixed SHOW COLUMNS result rows.
	 */
	private function execute_show_columns_query( string $schema_name, string $table_name, bool $is_full, ?string $like, $fetch_mode, ...$fetch_mode_args ) {
		$this->ensure_mysql_schema_metadata_tables();

		$sql    = $this->get_show_columns_catalog_query( $is_full );
		$params = array(
			$this->resolve_mysql_table_schema_for_introspection( $schema_name, $table_name ),
			$table_name,
		);

		if ( null !== $like ) {
			$sql     .= " AND field_name LIKE ? ESCAPE '\\'";
			$params[] = $like;
		}

		$sql .= '
ORDER BY ordinal_position';

		$stmt = $this->connection->query( $sql, $params );

		$this->last_postgresql_queries[] = array(
			'sql'    => $sql,
			'params' => $params,
		);
		$this->last_column_meta          = $this->normalize_column_meta( $stmt );
		$this->last_result               = $stmt->fetchAll( $fetch_mode, ...$fetch_mode_args );

		return $this->last_result;
	}

	/**
	 * Execute a MySQL SHOW TABLES statement through PostgreSQL catalogs.
	 *
	 * @param bool        $is_full          Whether this is SHOW FULL TABLES.
	 * @param string|null $like             Optional MySQL LIKE pattern.
	 * @param int         $fetch_mode       PDO fetch mode.
	 * @param array       ...$fetch_mode_args Additional fetch mode arguments.
	 * @return mixed SHOW TABLES result rows.
	 */
	private function execute_show_tables_query( bool $is_full, ?string $like, $fetch_mode, ...$fetch_mode_args ) {
		$table_column = $this->connection->quote_identifier( 'Tables_in_' . $this->db_name );
		$sql          = sprintf(
			'SELECT table_name AS %s%s
	FROM information_schema.tables
	WHERE table_schema = ?
		AND table_type IN (\'BASE TABLE\', \'VIEW\')
		AND table_name NOT IN (%s, %s, %s)',
			$table_column,
			$is_full ? ', CASE WHEN table_type = \'VIEW\' THEN \'VIEW\' ELSE \'BASE TABLE\' END AS "Table_type"' : '',
			$this->connection->quote( self::MYSQL_COLUMN_METADATA_TABLE ),
			$this->connection->quote( self::MYSQL_INDEX_METADATA_TABLE ),
			$this->connection->quote( self::MYSQL_CHARSET_METADATA_TABLE )
		);
		$params       = array( 'public' );

		if ( null !== $like ) {
			$sql     .= " AND table_name LIKE ? ESCAPE '\\'";
			$params[] = $like;
		}

		$sql .= '
ORDER BY table_name';

		$stmt = $this->connection->query( $sql, $params );

		$this->last_postgresql_queries[] = array(
			'sql'    => $sql,
			'params' => $params,
		);
		$this->last_column_meta          = $this->normalize_column_meta( $stmt );
		$this->last_result               = $stmt->fetchAll( $fetch_mode, ...$fetch_mode_args );

		return $this->last_result;
	}

	/**
	 * Execute a MySQL SHOW VARIABLES statement from emulated session state.
	 *
	 * @param array  $show_variables_query SHOW VARIABLES options.
	 * @param int    $fetch_mode         PDO fetch mode.
	 * @param array  ...$fetch_mode_args Additional fetch mode arguments.
	 * @return mixed SHOW VARIABLES result rows.
	 */
	private function execute_show_variables_query( array $show_variables_query, $fetch_mode, ...$fetch_mode_args ) {
		$variables = $this->get_mysql_session_variables();
		$rows      = array();

		foreach ( $variables as $variable_name => $value ) {
			if (
				'exact' === $show_variables_query['type']
				&& $variable_name !== $show_variables_query['pattern']
			) {
				continue;
			}

			if (
				'like' === $show_variables_query['type']
				&& ! $this->matches_mysql_like_pattern( $variable_name, $show_variables_query['pattern'] )
			) {
				continue;
			}

			$rows[] = array(
				'Variable_name' => $variable_name,
				'Value'         => $value,
			);
		}

		$this->last_column_meta = array(
			array(
				'name'             => 'Variable_name',
				'table'            => '',
				'mysqli:orgtable'  => '',
				'mysqli:orgname'   => 'Variable_name',
				'mysqli:db'        => $this->db_name,
				'mysqli:charsetnr' => 45,
				'mysqli:flags'     => 0,
				'mysqli:type'      => 253,
				'len'              => 64,
				'precision'        => 0,
				'native_type'      => 'string',
			),
			array(
				'name'             => 'Value',
				'table'            => '',
				'mysqli:orgtable'  => '',
				'mysqli:orgname'   => 'Value',
				'mysqli:db'        => $this->db_name,
				'mysqli:charsetnr' => 45,
				'mysqli:flags'     => 0,
				'mysqli:type'      => 253,
				'len'              => 1024,
				'precision'        => 0,
				'native_type'      => 'string',
			),
		);

		if ( PDO::FETCH_ASSOC === $fetch_mode ) {
			$this->last_result = $rows;
			return $this->last_result;
		}

		if ( PDO::FETCH_NUM === $fetch_mode ) {
			$this->last_result = array_map( 'array_values', $rows );
			return $this->last_result;
		}

		$this->last_result = array_map(
			static function ( array $row ) {
				return (object) $row;
			},
			$rows
		);

		return $this->last_result;
	}

	/**
	 * Match a string against a MySQL LIKE pattern.
	 *
	 * @param string $value   Value to check.
	 * @param string $pattern MySQL LIKE pattern.
	 * @return bool Whether the pattern matches.
	 */
	private function matches_mysql_like_pattern( string $value, string $pattern ): bool {
		$regex  = '/^';
		$length = strlen( $pattern );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $pattern[ $i ];
			if ( '\\' === $char && $i + 1 < $length ) {
				++$i;
				$regex .= preg_quote( $pattern[ $i ], '/' );
				continue;
			}

			if ( '%' === $char ) {
				$regex .= '.*';
				continue;
			}

			if ( '_' === $char ) {
				$regex .= '.';
				continue;
			}

			$regex .= preg_quote( $char, '/' );
		}

		$regex .= '$/i';
		return 1 === preg_match( $regex, $value );
	}

	/**
	 * Get MySQL-compatible session variables exposed by SHOW VARIABLES.
	 *
	 * @return array<string, string> Session variables keyed by lowercase name.
	 */
	private function get_mysql_session_variables(): array {
		return array(
			'character_set_client'     => $this->charset,
			'character_set_connection' => $this->charset,
			'character_set_results'    => $this->charset,
			'character_set_database'   => $this->charset,
			'character_set_server'     => $this->charset,
			'collation_connection'     => $this->collation,
			'collation_database'       => $this->collation,
			'collation_server'         => $this->collation,
		);
	}

	/**
	 * Normalize a MySQL charset name.
	 *
	 * @param string $charset Charset name.
	 * @return string Normalized charset.
	 */
	private function normalize_mysql_charset_name( string $charset ): string {
		$charset = strtolower( trim( $charset, "'\"` \t\n\r\0\x0B" ) );
		return 'utf8mb3' === $charset ? 'utf8' : $charset;
	}

	/**
	 * Normalize a MySQL collation name.
	 *
	 * @param string $collation Collation name.
	 * @return string Normalized collation.
	 */
	private function normalize_mysql_collation_name( string $collation ): string {
		$collation = strtolower( trim( $collation, "'\"` \t\n\r\0\x0B" ) );
		if ( 0 === strpos( $collation, 'utf8mb3_' ) ) {
			return 'utf8_' . substr( $collation, strlen( 'utf8mb3_' ) );
		}

		return $collation;
	}

	/**
	 * Get the default MySQL collation for a charset.
	 *
	 * @param string $charset Charset name.
	 * @return string Collation name.
	 */
	private function get_default_mysql_collation_for_charset( string $charset ): string {
		$charset    = $this->normalize_mysql_charset_name( $charset );
		$collations = array(
			'ascii'   => 'ascii_general_ci',
			'big5'    => 'big5_chinese_ci',
			'binary'  => 'binary',
			'cp1251'  => 'cp1251_general_ci',
			'hebrew'  => 'hebrew_general_ci',
			'koi8r'   => 'koi8r_general_ci',
			'latin1'  => 'latin1_swedish_ci',
			'tis620'  => 'tis620_thai_ci',
			'ujis'    => 'ujis_japanese_ci',
			'utf8'    => 'utf8_general_ci',
			'utf8mb4' => 'utf8mb4_unicode_ci',
		);

		return $collations[ $charset ] ?? $charset . '_general_ci';
	}

	/**
	 * Execute a MySQL SHOW INDEX/SHOW INDEXES statement through PostgreSQL catalogs.
	 *
	 * @param string      $table_name          Table name.
	 * @param string|null $key_name            Optional MySQL Key_name filter.
	 * @param int         $fetch_mode          PDO fetch mode.
	 * @param array       ...$fetch_mode_args  Additional fetch mode arguments.
	 * @return mixed SHOW INDEX result rows.
	 */
	private function execute_show_index_query( string $table_name, ?string $key_name, $fetch_mode, ...$fetch_mode_args ) {
		$this->ensure_mysql_schema_metadata_tables();

		$sql    = $this->get_show_index_catalog_query();
		$params = array(
			$this->resolve_mysql_table_schema_for_introspection( 'public', $table_name ),
			$table_name,
		);

		if ( null !== $key_name ) {
			$sql     .= '
WHERE "Key_name" = ?';
			$params[] = $key_name;
		}

		$sql .= '
ORDER BY
	"Key_name" = \'PRIMARY\' DESC,
	"Non_unique" = \'0\' DESC,
	"Index_type" = \'SPATIAL\' DESC,
	"Index_type" = \'BTREE\' DESC,
	"Index_type" = \'FULLTEXT\' DESC,
	postgresql_index_oid,
	CAST("Seq_in_index" AS integer)';

		$stmt = $this->connection->query( $sql, $params );

		$this->last_postgresql_queries[] = array(
			'sql'    => $sql,
			'params' => $params,
		);
		$this->last_column_meta          = $this->normalize_column_meta( $stmt );
		$this->last_result               = $stmt->fetchAll( $fetch_mode, ...$fetch_mode_args );

		return $this->last_result;
	}

	/**
	 * Resolve the backend schema for an unqualified MySQL table introspection query.
	 *
	 * @param string $schema_name Requested schema name.
	 * @param string $table_name  Requested table name.
	 * @return string Backend schema name.
	 */
	private function resolve_mysql_table_schema_for_introspection( string $schema_name, string $table_name ): string {
		if ( 'public' !== $schema_name ) {
			return $schema_name;
		}

		$temporary_schema = $this->get_active_temporary_table_schema( $table_name );
		return null === $temporary_schema ? $schema_name : $temporary_schema;
	}

	/**
	 * Get the active temporary schema for a table name.
	 *
	 * @param string $table_name Table name.
	 * @return string|null Temporary schema name, or null when no active temporary table exists.
	 */
	private function get_active_temporary_table_schema( string $table_name ): ?string {
		$driver_name = (string) $this->connection->get_pdo()->getAttribute( PDO::ATTR_DRIVER_NAME );

		if ( 'sqlite' === $driver_name ) {
			$stmt = $this->connection->query(
				"SELECT name FROM sqlite_temp_master WHERE type = 'table' AND LOWER(name) = LOWER(?) LIMIT 1",
				array( $table_name )
			);

			return false === $stmt->fetchColumn() ? null : 'temp';
		}

		$stmt = $this->connection->query(
			'SELECT n.nspname
			FROM pg_catalog.pg_class c
			INNER JOIN pg_catalog.pg_namespace n
				ON n.oid = c.relnamespace
			WHERE n.oid = pg_my_temp_schema()
				AND lower(c.relname) = lower(?)
				AND c.relkind IN (\'r\', \'p\')
			LIMIT 1',
			array( $table_name )
		);

		$schema_name = $stmt->fetchColumn();
		return false === $schema_name ? null : (string) $schema_name;
	}

	/**
	 * Get the PostgreSQL catalog query backing MySQL DESCRIBE/DESC.
	 *
	 * @return string SQL query.
	 */
	private function get_describe_catalog_query(): string {
		$column_metadata_table = $this->connection->quote_identifier( self::MYSQL_COLUMN_METADATA_TABLE );
		$index_metadata_table  = $this->connection->quote_identifier( self::MYSQL_INDEX_METADATA_TABLE );

		return sprintf(
			'WITH requested_table AS (
	SELECT ? AS table_schema, ? AS table_name
),
catalog_columns AS (
	SELECT
		c.column_name AS field_name,
		COALESCE(
			cm.column_type,
			CASE
				WHEN c.data_type = \'character varying\' THEN
					\'varchar\' || CASE
						WHEN c.character_maximum_length IS NULL THEN \'\'
						ELSE \'(\' || CAST(c.character_maximum_length AS text) || \')\'
					END
				WHEN c.data_type = \'character\' THEN
					\'char\' || CASE
						WHEN c.character_maximum_length IS NULL THEN \'\'
						ELSE \'(\' || CAST(c.character_maximum_length AS text) || \')\'
					END
				WHEN c.data_type = \'integer\' THEN \'int\'
				WHEN c.data_type = \'timestamp without time zone\' THEN \'datetime\'
				ELSE c.data_type
			END
		) AS column_type,
		COALESCE(cm.is_nullable, c.is_nullable) AS is_nullable,
		CASE
			WHEN EXISTS (
				SELECT 1
				FROM %2$s im
				WHERE im.table_schema = c.table_schema
					AND im.table_name = c.table_name
					AND im.column_name = c.column_name
					AND UPPER(im.key_name) = \'PRIMARY\'
			) THEN \'PRI\'
			WHEN EXISTS (
				SELECT 1
				FROM %2$s im
				WHERE im.table_schema = c.table_schema
					AND im.table_name = c.table_name
					AND im.column_name = c.column_name
					AND im.non_unique = \'0\'
			) THEN \'UNI\'
			WHEN EXISTS (
				SELECT 1
				FROM %2$s im
				WHERE im.table_schema = c.table_schema
					AND im.table_name = c.table_name
					AND im.column_name = c.column_name
			) THEN \'MUL\'
			WHEN EXISTS (
				SELECT 1
				FROM information_schema.table_constraints tc
				INNER JOIN information_schema.key_column_usage kcu
					ON kcu.constraint_schema = tc.constraint_schema
					AND kcu.constraint_name = tc.constraint_name
					AND kcu.table_schema = tc.table_schema
					AND kcu.table_name = tc.table_name
				WHERE tc.table_schema = c.table_schema
					AND tc.table_name = c.table_name
					AND tc.constraint_type = \'PRIMARY KEY\'
					AND kcu.column_name = c.column_name
			) THEN \'PRI\'
			WHEN EXISTS (
				SELECT 1
				FROM information_schema.table_constraints tc
				INNER JOIN information_schema.key_column_usage kcu
					ON kcu.constraint_schema = tc.constraint_schema
					AND kcu.constraint_name = tc.constraint_name
					AND kcu.table_schema = tc.table_schema
					AND kcu.table_name = tc.table_name
				WHERE tc.table_schema = c.table_schema
					AND tc.table_name = c.table_name
					AND tc.constraint_type = \'UNIQUE\'
					AND kcu.column_name = c.column_name
			) THEN \'UNI\'
			ELSE \'\'
		END AS column_key,
			CASE
				WHEN cm.column_name IS NOT NULL THEN cm.column_default
				ELSE c.column_default
			END AS column_default,
		COALESCE(
			cm.extra,
			CASE
				WHEN c.is_identity = \'YES\' THEN \'auto_increment\'
				WHEN c.column_default LIKE \'nextval(%%\' THEN \'auto_increment\'
				ELSE \'\'
			END
		) AS column_extra,
		c.ordinal_position
	FROM requested_table rt
	INNER JOIN information_schema.columns c
		ON c.table_schema = rt.table_schema
		AND c.table_name = rt.table_name
	LEFT JOIN %1$s cm
		ON cm.table_schema = c.table_schema
		AND cm.table_name = c.table_name
		AND cm.column_name = c.column_name
),
metadata_columns AS (
	SELECT
		cm.column_name AS field_name,
		cm.column_type,
		cm.is_nullable,
		CASE
			WHEN EXISTS (
				SELECT 1
				FROM %2$s im
				WHERE im.table_schema = cm.table_schema
					AND im.table_name = cm.table_name
					AND im.column_name = cm.column_name
					AND UPPER(im.key_name) = \'PRIMARY\'
			) THEN \'PRI\'
			WHEN EXISTS (
				SELECT 1
				FROM %2$s im
				WHERE im.table_schema = cm.table_schema
					AND im.table_name = cm.table_name
					AND im.column_name = cm.column_name
					AND im.non_unique = \'0\'
			) THEN \'UNI\'
			WHEN EXISTS (
				SELECT 1
				FROM %2$s im
				WHERE im.table_schema = cm.table_schema
					AND im.table_name = cm.table_name
					AND im.column_name = cm.column_name
			) THEN \'MUL\'
			ELSE \'\'
		END AS column_key,
		cm.column_default,
		cm.extra AS column_extra,
		cm.ordinal_position
	FROM requested_table rt
	INNER JOIN %1$s cm
		ON cm.table_schema = rt.table_schema
		AND cm.table_name = rt.table_name
	WHERE NOT EXISTS (
		SELECT 1
		FROM information_schema.columns c
		WHERE c.table_schema = cm.table_schema
			AND c.table_name = cm.table_name
			AND c.column_name = cm.column_name
	)
),
describe_rows AS (
	SELECT * FROM catalog_columns
	UNION ALL
	SELECT * FROM metadata_columns
)
SELECT
	field_name AS "Field",
	column_type AS "Type",
	is_nullable AS "Null",
	column_key AS "Key",
	column_default AS "Default",
	column_extra AS "Extra"
FROM describe_rows
ORDER BY ordinal_position',
			$column_metadata_table,
			$index_metadata_table
		);
	}

	/**
	 * Get the PostgreSQL catalog query backing MySQL SHOW COLUMNS/FULL COLUMNS.
	 *
	 * @param bool $is_full Whether the query should emit SHOW FULL COLUMNS fields.
	 * @return string SQL query.
	 */
	private function get_show_columns_catalog_query( bool $is_full ): string {
		$column_metadata_table = $this->connection->quote_identifier( self::MYSQL_COLUMN_METADATA_TABLE );
		$index_metadata_table  = $this->connection->quote_identifier( self::MYSQL_INDEX_METADATA_TABLE );

		$type_expression = 'CASE
		WHEN c.data_type = \'character varying\' THEN
			\'varchar\' || CASE
				WHEN c.character_maximum_length IS NULL THEN \'\'
				ELSE \'(\' || CAST(c.character_maximum_length AS text) || \')\'
			END
		WHEN c.data_type = \'character\' THEN
			\'char\' || CASE
				WHEN c.character_maximum_length IS NULL THEN \'\'
				ELSE \'(\' || CAST(c.character_maximum_length AS text) || \')\'
			END
		WHEN c.data_type = \'integer\' THEN \'int\'
		WHEN c.data_type = \'timestamp without time zone\' THEN \'datetime\'
		ELSE c.data_type
	END';

		$catalog_collation_expression = 'CASE
		WHEN cm.column_type IS NOT NULL THEN
			CASE
				WHEN LOWER(cm.column_type) LIKE \'char%\'
					OR LOWER(cm.column_type) LIKE \'varchar%\'
					OR LOWER(cm.column_type) LIKE \'%text%\' THEN COALESCE(cm.collation_name, c.collation_name, \'utf8mb4_unicode_ci\')
				ELSE NULL
			END
		WHEN c.data_type IN (\'character varying\', \'character\', \'text\') THEN COALESCE(c.collation_name, \'utf8mb4_unicode_ci\')
		ELSE NULL
	END';

		$metadata_collation_expression = 'CASE
		WHEN LOWER(cm.column_type) LIKE \'char%\'
			OR LOWER(cm.column_type) LIKE \'varchar%\'
			OR LOWER(cm.column_type) LIKE \'%text%\' THEN COALESCE(cm.collation_name, \'utf8mb4_unicode_ci\')
		ELSE NULL
	END';

		$catalog_key_expression = sprintf(
			'CASE
		WHEN EXISTS (
			SELECT 1
			FROM %1$s im
			WHERE im.table_schema = c.table_schema
				AND im.table_name = c.table_name
				AND im.column_name = c.column_name
				AND UPPER(im.key_name) = \'PRIMARY\'
		) THEN \'PRI\'
		WHEN EXISTS (
			SELECT 1
			FROM %1$s im
			WHERE im.table_schema = c.table_schema
				AND im.table_name = c.table_name
				AND im.column_name = c.column_name
				AND im.non_unique = \'0\'
		) THEN \'UNI\'
		WHEN EXISTS (
			SELECT 1
			FROM %1$s im
			WHERE im.table_schema = c.table_schema
				AND im.table_name = c.table_name
				AND im.column_name = c.column_name
		) THEN \'MUL\'
		WHEN EXISTS (
			SELECT 1
			FROM information_schema.table_constraints tc
			INNER JOIN information_schema.key_column_usage kcu
				ON kcu.constraint_schema = tc.constraint_schema
				AND kcu.constraint_name = tc.constraint_name
				AND kcu.table_schema = tc.table_schema
				AND kcu.table_name = tc.table_name
			WHERE tc.table_schema = c.table_schema
				AND tc.table_name = c.table_name
				AND tc.constraint_type = \'PRIMARY KEY\'
				AND kcu.column_name = c.column_name
		) THEN \'PRI\'
		WHEN EXISTS (
			SELECT 1
			FROM information_schema.table_constraints tc
			INNER JOIN information_schema.key_column_usage kcu
				ON kcu.constraint_schema = tc.constraint_schema
				AND kcu.constraint_name = tc.constraint_name
				AND kcu.table_schema = tc.table_schema
				AND kcu.table_name = tc.table_name
			WHERE tc.table_schema = c.table_schema
				AND tc.table_name = c.table_name
				AND tc.constraint_type = \'UNIQUE\'
				AND kcu.column_name = c.column_name
		) THEN \'UNI\'
		ELSE \'\'
	END',
			$index_metadata_table
		);

		$metadata_key_expression = sprintf(
			'CASE
		WHEN EXISTS (
			SELECT 1
			FROM %1$s im
			WHERE im.table_schema = cm.table_schema
				AND im.table_name = cm.table_name
				AND im.column_name = cm.column_name
				AND UPPER(im.key_name) = \'PRIMARY\'
		) THEN \'PRI\'
		WHEN EXISTS (
			SELECT 1
			FROM %1$s im
			WHERE im.table_schema = cm.table_schema
				AND im.table_name = cm.table_name
				AND im.column_name = cm.column_name
				AND im.non_unique = \'0\'
		) THEN \'UNI\'
		WHEN EXISTS (
			SELECT 1
			FROM %1$s im
			WHERE im.table_schema = cm.table_schema
				AND im.table_name = cm.table_name
				AND im.column_name = cm.column_name
		) THEN \'MUL\'
		ELSE \'\'
	END',
			$index_metadata_table
		);

		$catalog_extra_expression = 'CASE
		WHEN c.is_identity = \'YES\' THEN \'auto_increment\'
		WHEN c.column_default LIKE \'nextval(%\' THEN \'auto_increment\'
		ELSE \'\'
	END';

		if ( $is_full ) {
			$fields = 'field_name AS "Field",
	column_type AS "Type",
	collation_name AS "Collation",
	is_nullable AS "Null",
	column_key AS "Key",
	column_default AS "Default",
	column_extra AS "Extra",
	\'select,insert,update,references\' AS "Privileges",
	\'\' AS "Comment"';
		} else {
			$fields = 'field_name AS "Field",
	column_type AS "Type",
	is_nullable AS "Null",
	column_key AS "Key",
	column_default AS "Default",
	column_extra AS "Extra"';
		}

		return sprintf(
			'WITH requested_table AS (
	SELECT ? AS table_schema, ? AS table_name
),
catalog_columns AS (
	SELECT
		c.column_name AS field_name,
		COALESCE(cm.column_type, %3$s) AS column_type,
		%4$s AS collation_name,
		COALESCE(cm.is_nullable, c.is_nullable) AS is_nullable,
		%5$s AS column_key,
			CASE
				WHEN cm.column_name IS NOT NULL THEN cm.column_default
				ELSE c.column_default
			END AS column_default,
		COALESCE(cm.extra, %7$s) AS column_extra,
		c.ordinal_position
	FROM requested_table rt
	INNER JOIN information_schema.columns c
		ON c.table_schema = rt.table_schema
		AND c.table_name = rt.table_name
	LEFT JOIN %1$s cm
		ON cm.table_schema = c.table_schema
		AND cm.table_name = c.table_name
		AND cm.column_name = c.column_name
),
metadata_columns AS (
	SELECT
		cm.column_name AS field_name,
		cm.column_type,
		%8$s AS collation_name,
		cm.is_nullable,
		%6$s AS column_key,
		cm.column_default,
		cm.extra AS column_extra,
		cm.ordinal_position
	FROM requested_table rt
	INNER JOIN %1$s cm
		ON cm.table_schema = rt.table_schema
		AND cm.table_name = rt.table_name
	WHERE NOT EXISTS (
		SELECT 1
		FROM information_schema.columns c
		WHERE c.table_schema = cm.table_schema
			AND c.table_name = cm.table_name
			AND c.column_name = cm.column_name
	)
),
show_columns_rows AS (
	SELECT * FROM catalog_columns
	UNION ALL
	SELECT * FROM metadata_columns
)
SELECT
	%9$s
FROM show_columns_rows
WHERE 1 = 1',
			$column_metadata_table,
			$index_metadata_table,
			$type_expression,
			$catalog_collation_expression,
			$catalog_key_expression,
			$metadata_key_expression,
			$catalog_extra_expression,
			$metadata_collation_expression,
			$fields
		);
	}

	/**
	 * Get the PostgreSQL catalog query backing MySQL SHOW INDEX/SHOW INDEXES.
	 *
	 * @return string SQL query.
	 */
	private function get_show_index_catalog_query(): string {
		$index_metadata_table = $this->connection->quote_identifier( self::MYSQL_INDEX_METADATA_TABLE );

		return sprintf(
			'WITH requested_table AS (
	SELECT ? AS table_schema, ? AS table_name
),
metadata_exists AS (
	SELECT EXISTS (
		SELECT 1
		FROM %1$s im
		INNER JOIN requested_table rt
			ON rt.table_schema = im.table_schema
			AND rt.table_name = im.table_name
	) AS has_metadata
),
metadata_index_rows AS (
	SELECT
		im.table_name AS "Table",
		im.non_unique AS "Non_unique",
		im.key_name AS "Key_name",
		CAST(im.seq_in_index AS text) AS "Seq_in_index",
		im.column_name AS "Column_name",
		\'A\' AS "Collation",
		\'0\' AS "Cardinality",
		im.sub_part AS "Sub_part",
		NULL AS "Packed",
		im.nullable AS "Null",
		im.index_type AS "Index_type",
		\'\' AS "Comment",
		\'\' AS "Index_comment",
		\'YES\' AS "Visible",
		NULL AS "Expression",
		im.index_ordinal AS postgresql_index_oid
	FROM %1$s im
	INNER JOIN requested_table rt
		ON rt.table_schema = im.table_schema
		AND rt.table_name = im.table_name
),
index_columns AS (
		SELECT
			t.relname AS table_name,
			CAST(idx.oid AS bigint) AS postgresql_index_oid,
			idx.relname AS postgresql_index_name,
			i.indisunique,
			i.indisprimary,
		am.amname AS access_method,
		k.ordinality AS seq_in_index,
		k.attnum,
		a.attname AS column_name,
		a.attnotnull,
		CASE
			WHEN 0 = k.attnum THEN pg_catalog.pg_get_indexdef(i.indexrelid, CAST(k.ordinality AS integer), true)
			ELSE NULL
		END AS expression
	FROM pg_catalog.pg_class t
	INNER JOIN pg_catalog.pg_namespace n
		ON n.oid = t.relnamespace
	INNER JOIN pg_catalog.pg_index i
		ON i.indrelid = t.oid
	INNER JOIN pg_catalog.pg_class idx
		ON idx.oid = i.indexrelid
	INNER JOIN pg_catalog.pg_am am
		ON am.oid = idx.relam
		CROSS JOIN LATERAL pg_catalog.unnest(i.indkey) WITH ORDINALITY AS k(attnum, ordinality)
		LEFT JOIN pg_catalog.pg_attribute a
			ON a.attrelid = t.oid
			AND a.attnum = k.attnum
		INNER JOIN requested_table rt
			ON rt.table_schema = n.nspname
			AND rt.table_name = t.relname
		WHERE k.ordinality <= i.indnkeyatts
			AND i.indisvalid
			AND i.indislive
),
catalog_index_rows AS (
	SELECT
		table_name AS "Table",
		CASE WHEN indisunique THEN \'0\' ELSE \'1\' END AS "Non_unique",
	CASE
		WHEN indisprimary THEN \'PRIMARY\'
			WHEN postgresql_index_name LIKE table_name || \'__%%\' THEN SUBSTRING(postgresql_index_name FROM CHAR_LENGTH(table_name || \'__\') + 1)
		ELSE postgresql_index_name
	END AS "Key_name",
	CAST(seq_in_index AS text) AS "Seq_in_index",
	column_name AS "Column_name",
	\'A\' AS "Collation",
	\'0\' AS "Cardinality",
	NULL AS "Sub_part",
	NULL AS "Packed",
	CASE
		WHEN 0 = attnum OR attnotnull THEN \'\'
		ELSE \'YES\'
	END AS "Null",
	UPPER(access_method) AS "Index_type",
	\'\' AS "Comment",
	\'\' AS "Index_comment",
		\'YES\' AS "Visible",
		expression AS "Expression",
		postgresql_index_oid
	FROM index_columns
	WHERE NOT (SELECT has_metadata FROM metadata_exists)
),
show_index_rows AS (
	SELECT * FROM metadata_index_rows
	UNION ALL
	SELECT * FROM catalog_index_rows
	)
	SELECT
		"Table",
	"Non_unique",
	"Key_name",
	"Seq_in_index",
	"Column_name",
	"Collation",
	"Cardinality",
	"Sub_part",
	"Packed",
	"Null",
	"Index_type",
	"Comment",
	"Index_comment",
		"Visible",
		"Expression"
	FROM show_index_rows',
			$index_metadata_table
		);
	}

	/**
	 * Get results of the last query.
	 *
	 * @return mixed
	 */
	public function get_query_results() {
		return $this->last_result;
	}

	/**
	 * Get return value of the last query() function call.
	 *
	 * @return mixed
	 */
	public function get_last_return_value() {
		return $this->last_result;
	}

	/**
	 * Get the number of columns returned by the last query.
	 *
	 * @return int
	 */
	public function get_last_column_count(): int {
		return count( $this->last_column_meta );
	}

	/**
	 * Get column metadata for results of the last query.
	 *
	 * @return array
	 */
	public function get_last_column_meta(): array {
		return $this->last_column_meta;
	}

	/**
	 * Begin a transaction.
	 */
	public function beginTransaction(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$this->connection->get_pdo()->beginTransaction();
	}

	/**
	 * A temporary alias for back compatibility.
	 *
	 * @see self::beginTransaction()
	 */
	public function begin_transaction(): void {
		$this->beginTransaction();
	}

	/**
	 * Commit the current transaction.
	 */
	public function commit(): void {
		$this->connection->get_pdo()->commit();
	}

	/**
	 * Rollback the current transaction.
	 */
	public function rollback(): void {
		$this->connection->get_pdo()->rollBack();
	}

	/**
	 * Reset per-query state.
	 */
	private function reset_query_state(): void {
		$this->last_result             = null;
		$this->last_column_meta        = array();
		$this->last_mysql_query        = null;
		$this->last_postgresql_queries = array();
	}

	/**
	 * Translate the WordPress options cleanup DELETE ... REGEXP query.
	 *
	 * @param string $query MySQL query.
	 * @return string|null PostgreSQL query, or null when the query is unsupported.
	 */
	private function translate_wordpress_options_regexp_delete_query( string $query ): ?string {
		$tokens = $this->get_mysql_tokens( $query );
		if (
			! isset( $tokens[0], $tokens[1], $tokens[2], $tokens[3], $tokens[4], $tokens[5], $tokens[6] )
			|| WP_MySQL_Lexer::DELETE_SYMBOL !== $tokens[0]->id
			|| WP_MySQL_Lexer::FROM_SYMBOL !== $tokens[1]->id
			|| WP_MySQL_Lexer::WHERE_SYMBOL !== $tokens[3]->id
			|| WP_MySQL_Lexer::REGEXP_SYMBOL !== $tokens[5]->id
		) {
			return null;
		}

		$table_name = $this->get_mysql_identifier_token_value( $tokens[2] );
		$column     = $this->get_mysql_identifier_token_value( $tokens[4] );
		if ( null === $table_name || null === $column || ! $this->is_wordpress_options_table_name( $table_name ) ) {
			return null;
		}

		if (
			WP_MySQL_Lexer::SINGLE_QUOTED_TEXT !== $tokens[6]->id
			&& WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT !== $tokens[6]->id
		) {
			return null;
		}

		if ( ! $this->is_at_mysql_query_end( $tokens, 7 ) ) {
			return null;
		}

		return sprintf(
			'DELETE FROM %s WHERE %s ~* %s',
			$this->connection->quote_identifier( $table_name ),
			$this->connection->quote_identifier( $column ),
			$this->connection->quote( $tokens[6]->get_value() )
		);
	}

	/**
	 * Translate WordPress expired transient cleanup DELETE statements.
	 *
	 * Core emits a MySQL multi-table DELETE that removes both transient values
	 * and their timeout rows. PostgreSQL does not support that DELETE syntax, so
	 * this rewrites only the exact WordPress options-table shape to a CTE-backed
	 * single-table DELETE.
	 *
	 * @param string $query MySQL query.
	 * @return string|null PostgreSQL query, or null when the query is unsupported.
	 */
	private function translate_wordpress_expired_transients_delete_query( string $query ): ?string {
		$pattern = '/^\s*DELETE\s+a\s*,\s*b\s+FROM\s+([A-Za-z0-9_]+)\s+a\s*,\s*\1\s+b\s+WHERE\s+a\.option_name\s+LIKE\s+([\'"])([^\'"]+)\\2\s+AND\s+a\.option_name\s+NOT\s+LIKE\s+([\'"])([^\'"]+)\\4\s+AND\s+b\.option_name\s*=\s*CONCAT\s*\(\s*([\'"])([^\'"]+)\\6\s*,\s*SUBSTRING\s*\(\s*a\.option_name\s*,\s*([0-9]+)\s*\)\s*\)\s+AND\s+b\.option_value\s*<\s*([0-9]+)\s*;?\s*$/is';
		if ( ! preg_match( $pattern, $query, $matches ) ) {
			return null;
		}

		$table_name     = $matches[1];
		$value_like     = $matches[3];
		$timeout_like   = $matches[5];
		$timeout_prefix = $matches[7];
		$substring_from = (int) $matches[8];
		$expires_before = $matches[9];

		if (
			! $this->is_wordpress_options_table_name( $table_name )
			|| ! in_array( $timeout_prefix, array( '_transient_timeout_', '_site_transient_timeout_' ), true )
			|| ( '_transient_timeout_' === $timeout_prefix && 12 !== $substring_from )
			|| ( '_site_transient_timeout_' === $timeout_prefix && 17 !== $substring_from )
		) {
			return null;
		}

		return sprintf(
			'WITH expired_transients AS (
	SELECT a.option_name AS value_name, b.option_name AS timeout_name
	FROM %1$s a
	INNER JOIN %1$s b
		ON b.option_name = %2$s || SUBSTR(a.option_name, %3$d)
	WHERE a.option_name LIKE %4$s ESCAPE %5$s
		AND a.option_name NOT LIKE %6$s ESCAPE %5$s
		AND CAST(b.option_value AS BIGINT) < %7$s
)
DELETE FROM %1$s
WHERE option_name IN (
	SELECT value_name FROM expired_transients
	UNION
	SELECT timeout_name FROM expired_transients
)',
			$this->connection->quote_identifier( $table_name ),
			$this->connection->quote( $timeout_prefix ),
			$substring_from,
			$this->connection->quote( $value_like ),
			$this->connection->quote( '\\' ),
			$this->connection->quote( $timeout_like ),
			$expires_before
		);
	}

	/**
	 * Translate simple single-table MySQL DELETE statements to PostgreSQL.
	 *
	 * WordPress option deletes emit a single target table and a plain WHERE
	 * clause. Multi-table DELETE variants and MySQL-only ORDER/LIMIT forms fall
	 * through unchanged so unsupported SQL still fails visibly in the backend.
	 *
	 * @param string $query MySQL query.
	 * @return string|null PostgreSQL query, or null when the query is unsupported.
	 */
	private function translate_simple_mysql_delete_query( string $query ): ?string {
		$tokens = $this->get_mysql_tokens( $query );
		if (
			! isset( $tokens[0], $tokens[1], $tokens[2], $tokens[3] )
			|| WP_MySQL_Lexer::DELETE_SYMBOL !== $tokens[0]->id
			|| WP_MySQL_Lexer::FROM_SYMBOL !== $tokens[1]->id
			|| WP_MySQL_Lexer::WHERE_SYMBOL !== $tokens[3]->id
		) {
			return null;
		}

		$table_name = $this->get_mysql_identifier_token_value( $tokens[2] );
		if ( null === $table_name ) {
			return null;
		}

		$statement_end = $this->get_mysql_statement_end_position( $tokens, 4 );
		if ( null === $statement_end || 4 >= $statement_end ) {
			return null;
		}

		$unsupported_tokens = array(
			WP_MySQL_Lexer::COMMA_SYMBOL,
			WP_MySQL_Lexer::JOIN_SYMBOL,
			WP_MySQL_Lexer::LIMIT_SYMBOL,
			WP_MySQL_Lexer::ORDER_SYMBOL,
			WP_MySQL_Lexer::REGEXP_SYMBOL,
			WP_MySQL_Lexer::STRAIGHT_JOIN_SYMBOL,
			WP_MySQL_Lexer::USING_SYMBOL,
		);
		if ( $this->contains_top_level_mysql_token( $tokens, 1, $statement_end, $unsupported_tokens ) ) {
			return null;
		}

		return sprintf(
			'DELETE FROM %s WHERE %s',
			$this->connection->quote_identifier( $table_name ),
			$this->translate_mysql_token_sequence_to_postgresql( $tokens, 4, $statement_end )
		);
	}

	/**
	 * Translate WordPress options INSERT ... ON DUPLICATE KEY UPDATE queries.
	 *
	 * WordPress installation upserts rows into the options table through MySQL's
	 * ON DUPLICATE KEY syntax. Keep this intentionally narrow: prefixed options
	 * tables conflict on option_name, and update assignments must be
	 * "column = VALUES(column)" so unsupported INSERT shapes still reach PDO.
	 *
	 * @param string $query MySQL query.
	 * @return string|null PostgreSQL query, or null when the query is unsupported.
	 */
	private function translate_wordpress_options_upsert_query( string $query ): ?string {
		$tokens = $this->get_mysql_tokens( $query );
		if ( ! isset( $tokens[0] ) || WP_MySQL_Lexer::INSERT_SYMBOL !== $tokens[0]->id ) {
			return null;
		}

		$position = 1;
		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::INTO_SYMBOL !== $tokens[ $position ]->id ) {
			return null;
		}

		++$position;
		$table_name = $this->get_mysql_identifier_token_value( $tokens[ $position ] ?? null );
		if ( null === $table_name || ! $this->is_wordpress_options_table_name( $table_name ) ) {
			return null;
		}

		++$position;
		$columns = $this->parse_mysql_identifier_list( $tokens, $position );
		if ( null === $columns ) {
			return null;
		}

		$column_lookup = array();
		foreach ( $columns as $column ) {
			$column_lookup[ strtolower( $column ) ] = true;
		}

		if ( ! isset( $column_lookup['option_name'] ) ) {
			return null;
		}

		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::VALUES_SYMBOL !== $tokens[ $position ]->id ) {
			return null;
		}

		$values_start = $position;
		$on_duplicate = $this->find_on_duplicate_key_update_clause( $tokens, $position + 1 );
		if ( null === $on_duplicate ) {
			return null;
		}

		$values_sql = $this->translate_mysql_token_sequence_to_postgresql( $tokens, $values_start, $on_duplicate );
		$position   = $on_duplicate + 4;

		$assignments = $this->parse_upsert_update_assignments( $tokens, $position, $column_lookup );
		if ( null === $assignments || ! $this->is_at_mysql_query_end( $tokens, $position ) ) {
			return null;
		}

		return sprintf(
			'INSERT INTO %s (%s) %s ON CONFLICT (%s) DO UPDATE SET %s',
			$this->connection->quote_identifier( $table_name ),
			implode( ', ', array_map( array( $this->connection, 'quote_identifier' ), $columns ) ),
			$values_sql,
			$this->connection->quote_identifier( 'option_name' ),
			implode( ', ', $assignments )
		);
	}

	/**
	 * Translate simple single-row MySQL REPLACE statements to PostgreSQL.
	 *
	 * WordPress' wpdb::replace() emits a single VALUES row with an explicit
	 * column list. For rows with a known WordPress unique key, use PostgreSQL's
	 * ON CONFLICT update path and synthesize MySQL's affected-row count in
	 * query(). Without a known conflict column, fall back to a plain INSERT so
	 * PostgreSQL still reports normal constraint and length errors.
	 *
	 * @param string $query MySQL query.
	 * @return array|null PostgreSQL query data, or null when the query is unsupported.
	 */
	private function translate_simple_mysql_replace_query( string $query ): ?array {
		$tokens = $this->get_mysql_tokens( $query );
		if ( ! isset( $tokens[0] ) || WP_MySQL_Lexer::REPLACE_SYMBOL !== $tokens[0]->id ) {
			return null;
		}

		$position = 1;
		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::INTO_SYMBOL !== $tokens[ $position ]->id ) {
			return null;
		}

		++$position;
		$table_name = $this->get_mysql_identifier_token_value( $tokens[ $position ] ?? null );
		if ( null === $table_name ) {
			return null;
		}

		++$position;
		$columns = $this->parse_mysql_identifier_list( $tokens, $position );
		if ( null === $columns ) {
			return null;
		}

		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::VALUES_SYMBOL !== $tokens[ $position ]->id ) {
			return null;
		}

		++$position;
		$values = $this->parse_mysql_value_list( $tokens, $position );
		if ( null === $values || count( $columns ) !== count( $values ) || ! $this->is_at_mysql_query_end( $tokens, $position ) ) {
			return null;
		}

		$sql = sprintf(
			'INSERT INTO %s (%s) VALUES (%s)',
			$this->connection->quote_identifier( $table_name ),
			implode( ', ', array_map( array( $this->connection, 'quote_identifier' ), $columns ) ),
			implode( ', ', $values )
		);

		$conflict_column = $this->get_simple_replace_conflict_column( $table_name, $columns );
		if ( null === $conflict_column ) {
			return array(
				'action'           => 'replace',
				'sql'              => $sql,
				'table_name'       => $table_name,
				'columns'          => $columns,
				'values'           => $values,
				'conflict_column'  => null,
				'conflict_value'   => null,
				'inserted_new_row' => true,
			);
		}

		$conflict_index = null;
		foreach ( $columns as $index => $column ) {
			if ( strtolower( $column ) === strtolower( $conflict_column ) ) {
				$conflict_index = $index;
				break;
			}
		}

		if ( null === $conflict_index ) {
			return null;
		}

		$assignments = array();
		foreach ( $columns as $column ) {
			$assignments[] = sprintf(
				'%s = excluded.%s',
				$this->connection->quote_identifier( $column ),
				$this->connection->quote_identifier( $column )
			);
		}

		return array(
			'action'           => 'replace',
			'sql'              => sprintf(
				'%s ON CONFLICT (%s) DO UPDATE SET %s',
				$sql,
				$this->connection->quote_identifier( $conflict_column ),
				implode( ', ', $assignments )
			),
			'table_name'       => $table_name,
			'columns'          => $columns,
			'values'           => $values,
			'conflict_column'  => $conflict_column,
			'conflict_value'   => $values[ $conflict_index ],
			'inserted_new_row' => true,
		);
	}

	/**
	 * Check whether a simple REPLACE conflict target currently exists.
	 *
	 * @param string $table_name      Table name.
	 * @param string $conflict_column Conflict column name.
	 * @param string $conflict_value  Already translated SQL value.
	 * @return bool Whether the row exists.
	 */
	private function replace_conflict_exists( string $table_name, string $conflict_column, string $conflict_value ): bool {
		if ( 'NULL' === strtoupper( $conflict_value ) ) {
			return false;
		}

		$stmt = $this->connection->query(
			sprintf(
				'SELECT 1 FROM %s WHERE %s = %s LIMIT 1',
				$this->connection->quote_identifier( $table_name ),
				$this->connection->quote_identifier( $conflict_column ),
				$conflict_value
			)
		);

		return false !== $stmt->fetchColumn();
	}

	/**
	 * Choose the conflict column for a WordPress REPLACE statement.
	 *
	 * @param string   $table_name Table name.
	 * @param string[] $columns    Inserted column names.
	 * @return string|null Conflict column name, or null when unknown.
	 */
	private function get_simple_replace_conflict_column( string $table_name, array $columns ): ?string {
		$column_lookup = array();
		foreach ( $columns as $column ) {
			$column_lookup[ strtolower( $column ) ] = $column;
		}

		if ( $this->is_wordpress_options_table_name( $table_name ) && isset( $column_lookup['option_name'] ) ) {
			return $column_lookup['option_name'];
		}

		foreach (
			array(
				'id',
				'comment_id',
				'link_id',
				'option_id',
				'meta_id',
				'umeta_id',
				'term_id',
				'term_taxonomy_id',
			) as $candidate
		) {
			if ( isset( $column_lookup[ $candidate ] ) ) {
				return $column_lookup[ $candidate ];
			}
		}

		return null;
	}

	/**
	 * Translate simple single-row MySQL INSERT statements to PostgreSQL.
	 *
	 * WordPress CRUD helpers emit a narrow INSERT INTO table (columns) VALUES
	 * (...) shape. INSERT IGNORE uses PostgreSQL's conflict no-op syntax for
	 * the same simple VALUES shape. Other MySQL-specific modifiers,
	 * INSERT ... SELECT/SET, missing column lists, multi-row values, and
	 * trailing clauses fall through unchanged.
	 *
	 * @param string $query MySQL query.
	 * @return array|null PostgreSQL query data, or null when the query is unsupported.
	 */
	private function translate_simple_mysql_insert_query( string $query ): ?array {
		$tokens = $this->get_mysql_tokens( $query );
		if ( ! isset( $tokens[0] ) || WP_MySQL_Lexer::INSERT_SYMBOL !== $tokens[0]->id ) {
			return null;
		}

		$position = 1;
		$ignore   = false;
		if ( isset( $tokens[ $position ] ) && WP_MySQL_Lexer::IGNORE_SYMBOL === $tokens[ $position ]->id ) {
			$ignore = true;
			++$position;
		}

		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::INTO_SYMBOL !== $tokens[ $position ]->id ) {
			return null;
		}

		++$position;
		$table_name = $this->get_mysql_identifier_token_value( $tokens[ $position ] ?? null );
		if ( null === $table_name ) {
			return null;
		}

		++$position;
		$columns = $this->parse_mysql_identifier_list( $tokens, $position );
		if ( null === $columns ) {
			return null;
		}

		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::VALUES_SYMBOL !== $tokens[ $position ]->id ) {
			return null;
		}

		++$position;
		$values = $this->parse_mysql_value_list( $tokens, $position );
		if ( null === $values || count( $columns ) !== count( $values ) || ! $this->is_at_mysql_query_end( $tokens, $position ) ) {
			return null;
		}

		$sql = sprintf(
			'INSERT INTO %s (%s) VALUES (%s)',
			$this->connection->quote_identifier( $table_name ),
			implode( ', ', array_map( array( $this->connection, 'quote_identifier' ), $columns ) ),
			implode( ', ', $values )
		);

		return array(
			'action'           => 'insert',
			'sql'              => $ignore ? $sql . ' ON CONFLICT DO NOTHING' : $sql,
			'table_name'       => $table_name,
			'columns'          => $columns,
			'values'           => $values,
			'ignore'           => $ignore,
			'inserted_new_row' => true,
		);
	}

	/**
	 * Repair PostgreSQL identity sequences for successful explicit identity writes.
	 *
	 * @param array $dml_query     Translated DML query metadata.
	 * @param int   $affected_rows Backend affected row count.
	 */
	private function repair_dml_identity_sequences_after_success( array $dml_query, int $affected_rows ): void {
		if ( $affected_rows <= 0 ) {
			return;
		}

		if ( isset( $dml_query['inserted_new_row'] ) && ! $dml_query['inserted_new_row'] ) {
			return;
		}

		if (
			! isset( $dml_query['table_name'], $dml_query['columns'], $dml_query['values'] )
			|| ! is_array( $dml_query['columns'] )
			|| ! is_array( $dml_query['values'] )
		) {
			return;
		}

		$explicit_identity_columns = $this->get_explicit_dml_identity_column_lookup(
			$dml_query['columns'],
			$dml_query['values']
		);
		if ( empty( $explicit_identity_columns ) || ! $this->is_postgresql_catalog_available_for_dml_identity_repair() ) {
			return;
		}

		$table_name   = (string) $dml_query['table_name'];
		$table_schema = $this->resolve_mysql_table_schema_for_introspection( 'public', $table_name );
		$metadata     = $this->get_dml_identity_column_metadata( $table_schema, $table_name );

		foreach ( $metadata as $column_metadata ) {
			$column_name = (string) ( $column_metadata['column_name'] ?? '' );
			if ( ! isset( $explicit_identity_columns[ strtolower( $column_name ) ] ) ) {
				continue;
			}

			if ( ! $this->is_existing_dbdelta_column_identity( $column_metadata ) ) {
				continue;
			}

			$sequence_schema = (string) ( $column_metadata['sequence_schema'] ?? '' );
			$sequence_name   = (string) ( $column_metadata['sequence_name'] ?? '' );
			if ( '' === $sequence_schema || '' === $sequence_name ) {
				continue;
			}

			$this->repair_postgresql_identity_sequence(
				$table_schema,
				$table_name,
				$column_name,
				$sequence_schema,
				$sequence_name
			);
		}
	}

	/**
	 * Get explicitly supplied non-default DML identity columns.
	 *
	 * @param string[] $columns DML column names.
	 * @param string[] $values  Translated DML value expressions.
	 * @return array<string, bool> Lowercase column lookup.
	 */
	private function get_explicit_dml_identity_column_lookup( array $columns, array $values ): array {
		$explicit_columns = array();

		foreach ( $columns as $index => $column ) {
			if ( ! isset( $values[ $index ] ) || ! $this->is_explicit_dml_identity_value( (string) $values[ $index ] ) ) {
				continue;
			}

			$explicit_columns[ strtolower( (string) $column ) ] = true;
		}

		return $explicit_columns;
	}

	/**
	 * Check whether a DML value is an explicit identity value.
	 *
	 * DEFAULT and NULL do not represent caller-supplied auto_increment values.
	 *
	 * @param string $value_sql Translated value SQL.
	 * @return bool Whether the value is explicit.
	 */
	private function is_explicit_dml_identity_value( string $value_sql ): bool {
		$value_sql = trim( $value_sql );
		if ( '' === $value_sql ) {
			return false;
		}

		return ! in_array( strtoupper( $value_sql ), array( 'DEFAULT', 'NULL' ), true );
	}

	/**
	 * Get PostgreSQL/MySQL metadata for DML identity repair.
	 *
	 * @param string $table_schema Backend table schema.
	 * @param string $table_name   Table name.
	 * @return array[] Column metadata rows.
	 */
	private function get_dml_identity_column_metadata( string $table_schema, string $table_name ): array {
		$this->ensure_mysql_schema_metadata_tables();

		$stmt = $this->connection->query(
			sprintf(
				'SELECT
					c.column_name,
					c.data_type,
					c.is_identity,
					c.column_default,
					cm.column_type AS mysql_column_type,
					cm.extra AS mysql_extra,
					seq_ns.nspname AS sequence_schema,
					seq.relname AS sequence_name
				FROM information_schema.columns c
				LEFT JOIN %s cm
					ON cm.table_schema = c.table_schema
					AND cm.table_name = c.table_name
					AND cm.column_name = c.column_name
				LEFT JOIN LATERAL (
					SELECT pg_catalog.pg_get_serial_sequence(format(\'%%I.%%I\', c.table_schema, c.table_name), c.column_name)::regclass AS sequence_oid
				) identity_sequence ON TRUE
				LEFT JOIN pg_catalog.pg_class seq
					ON seq.oid = identity_sequence.sequence_oid
				LEFT JOIN pg_catalog.pg_namespace seq_ns
					ON seq_ns.oid = seq.relnamespace
				WHERE c.table_schema = ?
					AND c.table_name = ?
				ORDER BY c.ordinal_position',
				$this->connection->quote_identifier( self::MYSQL_COLUMN_METADATA_TABLE )
			),
			array( $table_schema, $table_name )
		);

		return $stmt->fetchAll( PDO::FETCH_ASSOC );
	}

	/**
	 * Check whether PostgreSQL catalog metadata is available for identity repair.
	 *
	 * @return bool Whether catalog-backed identity repair can run.
	 */
	private function is_postgresql_catalog_available_for_dml_identity_repair(): bool {
		$driver_name = (string) $this->connection->get_pdo()->getAttribute( PDO::ATTR_DRIVER_NAME );
		if ( 'pgsql' === $driver_name ) {
			return true;
		}

		if ( 'sqlite' === $driver_name ) {
			return $this->sqlite_information_schema_columns_table_exists();
		}

		return false;
	}

	/**
	 * Check whether the SQLite test shim has an information_schema.columns fixture.
	 *
	 * @return bool Whether the fixture table exists.
	 */
	private function sqlite_information_schema_columns_table_exists(): bool {
		$stmt = $this->connection->query( 'PRAGMA database_list' );
		foreach ( $stmt->fetchAll( PDO::FETCH_ASSOC ) as $database ) {
			if ( isset( $database['name'] ) && 'information_schema' === $database['name'] ) {
				$tables = $this->connection->query(
					"SELECT 1 FROM information_schema.sqlite_master WHERE type = 'table' AND name = 'columns' LIMIT 1"
				);
				return false !== $tables->fetchColumn();
			}
		}

		return false;
	}

	/**
	 * Monotonically synchronize a PostgreSQL identity sequence with its table.
	 *
	 * @param string $table_schema    Backend table schema.
	 * @param string $table_name      Table name.
	 * @param string $column_name     Identity column name.
	 * @param string $sequence_schema Sequence schema.
	 * @param string $sequence_name   Sequence name.
	 */
	private function repair_postgresql_identity_sequence(
		string $table_schema,
		string $table_name,
		string $column_name,
		string $sequence_schema,
		string $sequence_name
	): void {
		$sequence_identifier = $this->get_postgresql_qualified_identifier( $sequence_schema, $sequence_name );
		$sql                 = sprintf(
			'WITH sequence_state AS (
				SELECT last_value, is_called FROM %1$s
			),
			table_state AS (
				SELECT MAX(%2$s) AS max_identity_value FROM %3$s
			)
			SELECT pg_catalog.setval(CAST(? AS regclass), table_state.max_identity_value, true)
			FROM sequence_state, table_state
			WHERE table_state.max_identity_value IS NOT NULL
				AND (
					table_state.max_identity_value > sequence_state.last_value
					OR (table_state.max_identity_value = sequence_state.last_value AND NOT sequence_state.is_called)
				)',
			$sequence_identifier,
			$this->connection->quote_identifier( $column_name ),
			$this->get_postgresql_qualified_identifier( $table_schema, $table_name )
		);
		$params              = array( $sequence_identifier );

		$this->connection->query( $sql, $params );
		$this->last_postgresql_queries[] = array(
			'sql'    => $sql,
			'params' => $params,
		);
	}

	/**
	 * Quote a schema-qualified PostgreSQL identifier.
	 *
	 * @param string $schema_name Schema name.
	 * @param string $object_name Object name.
	 * @return string Quoted schema-qualified identifier.
	 */
	private function get_postgresql_qualified_identifier( string $schema_name, string $object_name ): string {
		return $this->connection->quote_identifier( $schema_name ) . '.' . $this->connection->quote_identifier( $object_name );
	}

	/**
	 * Translate simple single-table MySQL UPDATE statements to PostgreSQL.
	 *
	 * WordPress CRUD updates emit a narrow MySQL shape with one table, backticked
	 * identifiers, and plain SET/WHERE clauses. More complex UPDATE syntax falls
	 * through unchanged so unsupported SQL still fails visibly in the backend.
	 *
	 * @param string $query MySQL query.
	 * @return string|null PostgreSQL query, or null when the query is unsupported.
	 */
	private function translate_simple_mysql_update_query( string $query ): ?string {
		$tokens = $this->get_mysql_tokens( $query );
		if ( ! isset( $tokens[0] ) || WP_MySQL_Lexer::UPDATE_SYMBOL !== $tokens[0]->id ) {
			return null;
		}

		$position   = 1;
		$table_name = $this->get_mysql_identifier_token_value( $tokens[ $position ] ?? null );
		if ( null === $table_name ) {
			return null;
		}

		++$position;
		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::SET_SYMBOL !== $tokens[ $position ]->id ) {
			return null;
		}

		++$position;
		$statement_end = $this->get_mysql_statement_end_position( $tokens, $position );
		if ( null === $statement_end ) {
			return null;
		}

		$where_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::WHERE_SYMBOL, $position, $statement_end );
		$set_end        = $where_position ?? $statement_end;
		if ( ! $this->is_supported_simple_update_set_clause( $tokens, $position, $set_end ) ) {
			return null;
		}

		$unsupported_tokens = array(
			WP_MySQL_Lexer::JOIN_SYMBOL,
			WP_MySQL_Lexer::LIMIT_SYMBOL,
			WP_MySQL_Lexer::ORDER_SYMBOL,
			WP_MySQL_Lexer::STRAIGHT_JOIN_SYMBOL,
		);
		if ( $this->contains_top_level_mysql_token( $tokens, $position, $statement_end, $unsupported_tokens ) ) {
			return null;
		}

		$sql = sprintf(
			'UPDATE %s SET %s',
			$this->connection->quote_identifier( $table_name ),
			$this->translate_mysql_token_sequence_to_postgresql( $tokens, $position, $set_end )
		);

		if ( null !== $where_position ) {
			if (
				$where_position + 1 >= $statement_end
				|| ! $this->is_supported_simple_mysql_expression_fragment( $tokens, $where_position + 1, $statement_end )
			) {
				return null;
			}

			$where_sql = $this->translate_mysql_predicate_token_sequence_to_postgresql(
				$tokens,
				$where_position + 1,
				$statement_end,
				$this->get_mysql_single_table_scope( $table_name )
			);
			$sql      .= ' WHERE ' . $where_sql['sql'];
		}

		return $sql;
	}

	/**
	 * Translate simple single-table MySQL SELECT statements to PostgreSQL.
	 *
	 * This intentionally covers only the WordPress read shapes that need
	 * identifier quoting for PostgreSQL. Joins, grouping, subqueries, most
	 * functions, and MySQL-only SELECT modifiers fall through unchanged.
	 *
	 * @param string $query MySQL query.
	 * @return string|null PostgreSQL query, or null when the query is unsupported.
	 */
	private function translate_simple_mysql_select_query( string $query ): ?string {
		$tokens = $this->get_mysql_tokens( $query );
		if ( ! isset( $tokens[0] ) || WP_MySQL_Lexer::SELECT_SYMBOL !== $tokens[0]->id ) {
			return null;
		}

		$statement_end = $this->get_mysql_statement_end_position( $tokens, 1 );
		if ( null === $statement_end ) {
			return null;
		}

		$unsupported_tokens = array(
			WP_MySQL_Lexer::DISTINCT_SYMBOL,
			WP_MySQL_Lexer::FOR_SYMBOL,
			WP_MySQL_Lexer::GROUP_SYMBOL,
			WP_MySQL_Lexer::HAVING_SYMBOL,
			WP_MySQL_Lexer::HIGH_PRIORITY_SYMBOL,
			WP_MySQL_Lexer::INTO_SYMBOL,
			WP_MySQL_Lexer::JOIN_SYMBOL,
			WP_MySQL_Lexer::LOCK_SYMBOL,
			WP_MySQL_Lexer::PROCEDURE_SYMBOL,
			WP_MySQL_Lexer::SELECT_SYMBOL,
			WP_MySQL_Lexer::SQL_CALC_FOUND_ROWS_SYMBOL,
			WP_MySQL_Lexer::STRAIGHT_JOIN_SYMBOL,
			WP_MySQL_Lexer::UNION_SYMBOL,
		);
		if ( $this->contains_top_level_mysql_token( $tokens, 1, $statement_end, $unsupported_tokens ) ) {
			return null;
		}

		$select_end     = $statement_end;
		$limit_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::LIMIT_SYMBOL, 1, $statement_end );
		if ( null !== $limit_position ) {
			if ( ! $this->is_supported_simple_select_limit_clause( $tokens, $limit_position, $statement_end ) ) {
				return null;
			}

			$select_end = $limit_position;
		}

		$from_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::FROM_SYMBOL, 1, $select_end );
		if ( null === $from_position || 1 === $from_position ) {
			return null;
		}

		if ( ! $this->is_supported_simple_select_projection( $tokens, 1, $from_position ) ) {
			return null;
		}

		$table_token = $tokens[ $from_position + 1 ] ?? null;
		$table_name  = $this->get_mysql_identifier_token_value( $table_token );
		if ( null === $table_name ) {
			return null;
		}

		$position       = $from_position + 2;
		$where_position = null;
		$where_end      = null;
		$order_position = null;

		if ( $position < $select_end && WP_MySQL_Lexer::WHERE_SYMBOL === $tokens[ $position ]->id ) {
			$where_position = $position;
			$order_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::ORDER_SYMBOL, $position + 1, $select_end );
			$where_end      = $order_position ?? $select_end;

			if (
				$where_position + 1 >= $where_end
				|| ! $this->is_supported_simple_mysql_expression_fragment( $tokens, $where_position + 1, $where_end )
			) {
				return null;
			}

			$position = $where_end;
		}

		if ( $position < $select_end && WP_MySQL_Lexer::ORDER_SYMBOL === $tokens[ $position ]->id ) {
			$order_position = $position;
			if ( ! $this->is_supported_simple_select_order_by_clause( $tokens, $order_position, $select_end ) ) {
				return null;
			}

			$position = $select_end;
		}

		if ( $position !== $select_end ) {
			return null;
		}

		$sql = sprintf(
			'SELECT %s FROM %s',
			$this->translate_simple_select_projection_to_postgresql( $tokens, 1, $from_position ),
			$this->translate_mysql_identifier_token_to_postgresql( $table_token )
		);

		if ( null !== $where_position ) {
			$where_sql = $this->translate_mysql_predicate_token_sequence_to_postgresql(
				$tokens,
				$where_position + 1,
				$where_end,
				$this->get_mysql_single_table_scope( $table_name )
			);
			$sql      .= ' WHERE ' . $where_sql['sql'];
		}

		if ( null !== $order_position ) {
			$sql .= ' ORDER BY ' . $this->translate_mysql_token_to_postgresql( $tokens[ $order_position + 2 ] );
			if ( isset( $tokens[ $order_position + 3 ] ) && $order_position + 3 < $select_end ) {
				$sql .= ' ' . $tokens[ $order_position + 3 ]->get_bytes();
			}
		}

		if ( null !== $limit_position ) {
			$sql .= $this->translate_simple_select_limit_clause_to_postgresql( $tokens, $limit_position, $statement_end );
		}

		return $sql;
	}

	/**
	 * Translate WordPress Site Health's MySQL information_schema.TABLES query.
	 *
	 * WordPress asks MySQL for TABLE_ROWS and data/index lengths, which
	 * PostgreSQL's information_schema.tables does not expose. Keep this rewrite
	 * constrained to the Site Health projection and predicates so other catalog
	 * shapes continue to fail visibly.
	 *
	 * @param string $query MySQL query.
	 * @return string|null PostgreSQL query, or null when the query is unsupported.
	 */
	private function translate_information_schema_tables_site_health_query( string $query ): ?string {
		$tokens = $this->get_mysql_tokens( $query );
		if ( ! isset( $tokens[0] ) || WP_MySQL_Lexer::SELECT_SYMBOL !== $tokens[0]->id ) {
			return null;
		}

		$statement_end = $this->get_mysql_statement_end_position( $tokens, 1 );
		if ( null === $statement_end ) {
			return null;
		}

		$from_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::FROM_SYMBOL, 1, $statement_end );
		if ( null === $from_position || 1 === $from_position ) {
			return null;
		}

		$where_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::WHERE_SYMBOL, $from_position + 1, $statement_end );
		$group_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::GROUP_SYMBOL, $from_position + 1, $statement_end );
		if (
			null === $where_position
			|| null === $group_position
			|| $where_position > $group_position
			|| ! isset( $tokens[ $group_position + 1 ] )
			|| WP_MySQL_Lexer::BY_SYMBOL !== $tokens[ $group_position + 1 ]->id
		) {
			return null;
		}

		if (
			$this->contains_top_level_mysql_token(
				$tokens,
				1,
				$statement_end,
				array(
					WP_MySQL_Lexer::DISTINCT_SYMBOL,
					WP_MySQL_Lexer::FOR_SYMBOL,
					WP_MySQL_Lexer::HAVING_SYMBOL,
					WP_MySQL_Lexer::HIGH_PRIORITY_SYMBOL,
					WP_MySQL_Lexer::INTO_SYMBOL,
					WP_MySQL_Lexer::JOIN_SYMBOL,
					WP_MySQL_Lexer::LIMIT_SYMBOL,
					WP_MySQL_Lexer::LOCK_SYMBOL,
					WP_MySQL_Lexer::ORDER_SYMBOL,
					WP_MySQL_Lexer::PROCEDURE_SYMBOL,
					WP_MySQL_Lexer::SELECT_SYMBOL,
					WP_MySQL_Lexer::SQL_CALC_FOUND_ROWS_SYMBOL,
					WP_MySQL_Lexer::STRAIGHT_JOIN_SYMBOL,
					WP_MySQL_Lexer::UNION_SYMBOL,
				)
			)
		) {
			return null;
		}

		if ( ! $this->is_information_schema_tables_reference( $tokens, $from_position + 1, $where_position ) ) {
			return null;
		}

		$projection_items = $this->parse_mysql_select_projection_items( $tokens, 1, $from_position );
		if ( null === $projection_items ) {
			return null;
		}

		$projection_sql = $this->get_information_schema_tables_site_health_projection_sql( $tokens, $projection_items );
		if ( null === $projection_sql ) {
			return null;
		}

		$where_clause = $this->parse_information_schema_tables_site_health_where_clause( $tokens, $where_position + 1, $group_position );
		if ( null === $where_clause ) {
			return null;
		}

		if ( ! $this->is_information_schema_tables_site_health_group_by_clause( $tokens, $group_position + 2, $statement_end ) ) {
			return null;
		}

		return sprintf(
			'SELECT %s FROM (%s) AS %s WHERE %s GROUP BY %s',
			implode( ', ', $projection_sql ),
			$this->get_information_schema_tables_site_health_relation_sql( $where_clause['table_names'] ),
			$this->connection->quote_identifier( '__wp_pg_information_schema_tables' ),
			$this->translate_mysql_token_sequence_to_postgresql( $tokens, $where_position + 1, $group_position ),
			$this->connection->quote_identifier( 'table_name' )
		);
	}

	/**
	 * Check whether a token range is exactly information_schema.TABLES.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First table-reference token position.
	 * @param int             $end    Final table-reference token position, exclusive.
	 * @return bool Whether the range references information_schema.TABLES.
	 */
	private function is_information_schema_tables_reference( array $tokens, int $start, int $end ): bool {
		return $start + 3 === $end
			&& $this->is_mysql_identifier_like_token_value( $tokens[ $start ] ?? null, 'information_schema' )
			&& isset( $tokens[ $start + 1 ] )
			&& WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $start + 1 ]->id
			&& $this->is_mysql_identifier_like_token_value( $tokens[ $start + 2 ] ?? null, 'tables' );
	}

	/**
	 * Build Site Health's supported information_schema.TABLES projection list.
	 *
	 * @param WP_MySQL_Token[] $tokens           MySQL lexer token stream.
	 * @param array           $projection_items Parsed projection items.
	 * @return string[]|null PostgreSQL projection SQL, or null when unsupported.
	 */
	private function get_information_schema_tables_site_health_projection_sql( array $tokens, array $projection_items ): ?array {
		if ( 3 !== count( $projection_items ) ) {
			return null;
		}

		$expected = array(
			array(
				'alias' => 'table',
				'type'  => 'table_name',
			),
			array(
				'alias' => 'rows',
				'type'  => 'table_rows',
			),
			array(
				'alias' => 'bytes',
				'type'  => 'data_index_sum',
			),
		);

		$projection_sql = array();
		foreach ( $expected as $index => $expected_projection ) {
			$projection_item = $projection_items[ $index ];
			if ( strtolower( $projection_item['alias'] ) !== $expected_projection['alias'] ) {
				return null;
			}

			if (
				'table_name' === $expected_projection['type']
				&& $this->is_information_schema_tables_column_expression(
					$tokens,
					$projection_item['expression_start'],
					$projection_item['expression_end'],
					'table_name'
				)
			) {
				$projection_sql[] = sprintf(
					'%s AS %s',
					$this->connection->quote_identifier( 'table_name' ),
					$this->connection->quote_identifier( $projection_item['alias'] )
				);
				continue;
			}

			if (
				'table_rows' === $expected_projection['type']
				&& $this->is_information_schema_tables_column_expression(
					$tokens,
					$projection_item['expression_start'],
					$projection_item['expression_end'],
					'table_rows'
				)
			) {
				$projection_sql[] = sprintf(
					'MAX(%s) AS %s',
					$this->connection->quote_identifier( 'TABLE_ROWS' ),
					$this->connection->quote_identifier( $projection_item['alias'] )
				);
				continue;
			}

			if (
				'data_index_sum' === $expected_projection['type']
				&& $this->is_information_schema_tables_data_index_sum_expression(
					$tokens,
					$projection_item['expression_start'],
					$projection_item['expression_end']
				)
			) {
				$projection_sql[] = sprintf(
					'SUM(%s + %s) AS %s',
					$this->connection->quote_identifier( 'data_length' ),
					$this->connection->quote_identifier( 'index_length' ),
					$this->connection->quote_identifier( $projection_item['alias'] )
				);
				continue;
			}

			return null;
		}

		return $projection_sql;
	}

	/**
	 * Check whether a projection expression is a supported information_schema.TABLES column.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First expression token position.
	 * @param int             $end    Final expression token position, exclusive.
	 * @param string          $column Expected column name.
	 * @return bool Whether the expression is the expected column.
	 */
	private function is_information_schema_tables_column_expression( array $tokens, int $start, int $end, string $column ): bool {
		$bounds = $this->normalize_mysql_expression_bounds( $tokens, $start, $end );
		return $bounds['start'] + 1 === $bounds['end']
			&& $this->is_mysql_identifier_like_token_value( $tokens[ $bounds['start'] ] ?? null, $column );
	}

	/**
	 * Check whether a projection expression is SUM(data_length + index_length).
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First expression token position.
	 * @param int             $end    Final expression token position, exclusive.
	 * @return bool Whether the expression is the supported size aggregate.
	 */
	private function is_information_schema_tables_data_index_sum_expression( array $tokens, int $start, int $end ): bool {
		$bounds = $this->normalize_mysql_expression_bounds( $tokens, $start, $end );
		$start  = $bounds['start'];
		$end    = $bounds['end'];

		return $start + 6 === $end
			&& isset( $tokens[ $start ], $tokens[ $start + 1 ], $tokens[ $start + 2 ], $tokens[ $start + 3 ], $tokens[ $start + 4 ], $tokens[ $start + 5 ] )
			&& WP_MySQL_Lexer::SUM_SYMBOL === $tokens[ $start ]->id
			&& WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $start + 1 ]->id
			&& $this->is_mysql_identifier_like_token_value( $tokens[ $start + 2 ], 'data_length' )
			&& WP_MySQL_Lexer::PLUS_OPERATOR === $tokens[ $start + 3 ]->id
			&& $this->is_mysql_identifier_like_token_value( $tokens[ $start + 4 ], 'index_length' )
			&& WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $start + 5 ]->id;
	}

	/**
	 * Parse a supported Site Health information_schema.TABLES WHERE clause.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First WHERE predicate token position.
	 * @param int             $end    Final WHERE predicate token position, exclusive.
	 * @return array{table_names: string[]}|null Parsed WHERE data, or null when unsupported.
	 */
	private function parse_information_schema_tables_site_health_where_clause( array $tokens, int $start, int $end ): ?array {
		if ( $start >= $end ) {
			return null;
		}

		$position      = $start;
		$seen_columns  = array();
		$table_names   = array();
		$required_seen = array(
			'table_schema' => false,
			'table_name'   => false,
		);

		while ( $position < $end ) {
			$term = $this->parse_information_schema_tables_site_health_where_term( $tokens, $position, $end );
			if ( null === $term || isset( $seen_columns[ $term['column'] ] ) ) {
				return null;
			}

			$seen_columns[ $term['column'] ] = true;
			if ( isset( $required_seen[ $term['column'] ] ) ) {
				$required_seen[ $term['column'] ] = true;
			}
			if ( 'table_name' === $term['column'] ) {
				$table_names = $term['table_names'];
			}
			$position = $term['position'];

			if ( $position === $end ) {
				break;
			}

			if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::AND_SYMBOL !== $tokens[ $position ]->id ) {
				return null;
			}
			++$position;
		}

		if ( ! $required_seen['table_schema'] || ! $required_seen['table_name'] || empty( $table_names ) ) {
			return null;
		}

		return array(
			'table_names' => array_values( array_unique( $table_names ) ),
		);
	}

	/**
	 * Parse one supported information_schema.TABLES WHERE term.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Current token position.
	 * @param int             $end      Final WHERE predicate token position, exclusive.
	 * @return array{column: string, position: int, table_names: string[]}|null Parsed term, or null when unsupported.
	 */
	private function parse_information_schema_tables_site_health_where_term( array $tokens, int $position, int $end ): ?array {
		if ( $this->is_mysql_identifier_like_token_value( $tokens[ $position ] ?? null, 'table_schema' ) ) {
			if (
				! isset( $tokens[ $position + 1 ], $tokens[ $position + 2 ] )
				|| WP_MySQL_Lexer::EQUAL_OPERATOR !== $tokens[ $position + 1 ]->id
				|| ! $this->is_mysql_string_literal_token( $tokens[ $position + 2 ] )
			) {
				return null;
			}

			return array(
				'column'      => 'table_schema',
				'position'    => $position + 3,
				'table_names' => array(),
			);
		}

		if ( ! $this->is_mysql_identifier_like_token_value( $tokens[ $position ] ?? null, 'table_name' ) ) {
			return null;
		}

		if (
			isset( $tokens[ $position + 1 ], $tokens[ $position + 2 ] )
			&& WP_MySQL_Lexer::EQUAL_OPERATOR === $tokens[ $position + 1 ]->id
			&& $this->is_mysql_string_literal_token( $tokens[ $position + 2 ] )
		) {
			return array(
				'column'      => 'table_name',
				'position'    => $position + 3,
				'table_names' => array( $tokens[ $position + 2 ]->get_value() ),
			);
		}

		if (
			! isset( $tokens[ $position + 1 ], $tokens[ $position + 2 ] )
			|| WP_MySQL_Lexer::IN_SYMBOL !== $tokens[ $position + 1 ]->id
			|| WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $position + 2 ]->id
		) {
			return null;
		}

		$after_close = $this->get_mysql_parenthesized_sequence_end( $tokens, $position + 2, $end );
		if ( null === $after_close ) {
			return null;
		}

		$items = $this->split_top_level_mysql_arguments( $tokens, $position + 3, $after_close - 1 );
		if ( null === $items || count( $items ) < 1 ) {
			return null;
		}

		$table_names = array();
		foreach ( $items as $item ) {
			if ( ! $this->is_mysql_string_literal_range( $tokens, $item['start'], $item['end'] ) ) {
				return null;
			}
			$table_names[] = $tokens[ $item['start'] ]->get_value();
		}

		return array(
			'column'      => 'table_name',
			'position'    => $after_close,
			'table_names' => $table_names,
		);
	}

	/**
	 * Check whether the GROUP BY clause is exactly GROUP BY TABLE_NAME.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First GROUP BY expression token position.
	 * @param int             $end    Final GROUP BY token position, exclusive.
	 * @return bool Whether the grouping shape is supported.
	 */
	private function is_information_schema_tables_site_health_group_by_clause( array $tokens, int $start, int $end ): bool {
		return $start + 1 === $end
			&& $this->is_mysql_identifier_like_token_value( $tokens[ $start ] ?? null, 'table_name' );
	}

	/**
	 * Build the derived relation that emulates MySQL information_schema.TABLES columns.
	 *
	 * @param string[] $table_names Table names from the validated TABLE_NAME predicate.
	 * @return string PostgreSQL relation SQL.
	 */
	private function get_information_schema_tables_site_health_relation_sql( array $table_names ): string {
		return sprintf(
			'SELECT %1$s AS %1$s, %2$s AS %3$s, %4$s, 0 AS %5$s, 0 AS %6$s FROM %7$s WHERE %8$s = %9$s AND %10$s IN (%11$s, %12$s) AND %1$s NOT IN (%13$s, %14$s, %15$s)',
			$this->connection->quote_identifier( 'table_name' ),
			$this->connection->quote( $this->db_name ),
			$this->connection->quote_identifier( 'TABLE_SCHEMA' ),
			$this->get_information_schema_tables_site_health_table_rows_sql( $table_names ),
			$this->connection->quote_identifier( 'data_length' ),
			$this->connection->quote_identifier( 'index_length' ),
			$this->get_postgresql_qualified_identifier( 'information_schema', 'tables' ),
			$this->connection->quote_identifier( 'table_schema' ),
			$this->connection->quote( 'public' ),
			$this->connection->quote_identifier( 'table_type' ),
			$this->connection->quote( 'BASE TABLE' ),
			$this->connection->quote( 'VIEW' ),
			$this->connection->quote( self::MYSQL_COLUMN_METADATA_TABLE ),
			$this->connection->quote( self::MYSQL_INDEX_METADATA_TABLE ),
			$this->connection->quote( self::MYSQL_CHARSET_METADATA_TABLE )
		);
	}

	/**
	 * Build a CASE expression for Site Health TABLE_ROWS emulation.
	 *
	 * @param string[] $table_names Table names from the validated TABLE_NAME predicate.
	 * @return string PostgreSQL row-count expression SQL.
	 */
	private function get_information_schema_tables_site_health_table_rows_sql( array $table_names ): string {
		$cases = array();
		foreach ( $table_names as $table_name ) {
			$cases[] = sprintf(
				'WHEN %s THEN (SELECT COUNT(*) FROM %s)',
				$this->connection->quote( $table_name ),
				$this->connection->quote_identifier( $table_name )
			);
		}

		return sprintf(
			'CASE %s %s ELSE 0 END AS %s',
			$this->connection->quote_identifier( 'table_name' ),
			implode( ' ', $cases ),
			$this->connection->quote_identifier( 'TABLE_ROWS' )
		);
	}

	/**
	 * Translate SELECT DISTINCT queries whose ORDER BY expression is not selected.
	 *
	 * PostgreSQL requires ORDER BY expressions in SELECT DISTINCT statements to
	 * appear in the projection. Grouping by the visible projection and ordering
	 * by a hidden aggregate keeps the MySQL-visible result shape and avoids
	 * changing DISTINCT cardinality.
	 *
	 * @param string $query MySQL query.
	 * @return string|null PostgreSQL query, or null when the query is unsupported.
	 */
	private function translate_distinct_order_by_query( string $query ): ?string {
		$tokens = $this->get_mysql_tokens( $query );
		if ( ! isset( $tokens[0], $tokens[1] ) || WP_MySQL_Lexer::SELECT_SYMBOL !== $tokens[0]->id ) {
			return null;
		}

		$position = 1;
		if ( WP_MySQL_Lexer::DISTINCT_SYMBOL !== $tokens[ $position ]->id ) {
			return null;
		}

		++$position;
		$has_sql_calc_found_rows = false;
		if ( isset( $tokens[ $position ] ) && WP_MySQL_Lexer::SQL_CALC_FOUND_ROWS_SYMBOL === $tokens[ $position ]->id ) {
			$has_sql_calc_found_rows = true;
			++$position;
		}

		if ( isset( $tokens[ $position ] ) && $this->is_unsupported_distinct_select_modifier( $tokens[ $position ] ) ) {
			return null;
		}

		$projection_start = $position;
		$statement_end    = $this->get_mysql_statement_end_position( $tokens, $projection_start );
		if ( null === $statement_end ) {
			return null;
		}

		$limit_position = $this->find_top_level_mysql_token(
			$tokens,
			WP_MySQL_Lexer::LIMIT_SYMBOL,
			$projection_start,
			$statement_end
		);
		$select_end     = $limit_position ?? $statement_end;
		if ( null !== $limit_position && ! $this->is_supported_simple_select_limit_clause( $tokens, $limit_position, $statement_end ) ) {
			return null;
		}

		$order_position = $this->find_top_level_mysql_token(
			$tokens,
			WP_MySQL_Lexer::ORDER_SYMBOL,
			$projection_start,
			$select_end
		);
		if ( null === $order_position ) {
			return $has_sql_calc_found_rows
				? 'SELECT DISTINCT ' . $this->translate_mysql_token_sequence_to_postgresql( $tokens, $projection_start, $statement_end )
				: null;
		}

		$from_position = $this->find_top_level_mysql_token(
			$tokens,
			WP_MySQL_Lexer::FROM_SYMBOL,
			$projection_start,
			$order_position
		);
		if (
			null === $from_position
			|| null === $order_position
			|| $order_position + 2 >= $statement_end
			|| ! isset( $tokens[ $order_position + 1 ] )
			|| WP_MySQL_Lexer::BY_SYMBOL !== $tokens[ $order_position + 1 ]->id
		) {
			return null;
		}

		if (
			$this->contains_top_level_mysql_token(
				$tokens,
				$projection_start,
				$select_end,
				array(
					WP_MySQL_Lexer::FOR_SYMBOL,
					WP_MySQL_Lexer::GROUP_SYMBOL,
					WP_MySQL_Lexer::HAVING_SYMBOL,
					WP_MySQL_Lexer::INTO_SYMBOL,
					WP_MySQL_Lexer::LOCK_SYMBOL,
					WP_MySQL_Lexer::PROCEDURE_SYMBOL,
					WP_MySQL_Lexer::UNION_SYMBOL,
				)
			)
		) {
			return null;
		}

		if (
			$this->contains_mysql_token(
				$tokens,
				$projection_start,
				$select_end,
				array(
					WP_MySQL_Lexer::SELECT_SYMBOL,
					WP_MySQL_Lexer::AVG_SYMBOL,
					WP_MySQL_Lexer::COUNT_SYMBOL,
					WP_MySQL_Lexer::GROUP_CONCAT_SYMBOL,
					WP_MySQL_Lexer::MAX_SYMBOL,
					WP_MySQL_Lexer::MIN_SYMBOL,
					WP_MySQL_Lexer::SUM_SYMBOL,
				)
			)
		) {
			return null;
		}

		$projection_items = $this->parse_mysql_select_projection_items( $tokens, $projection_start, $from_position );
		if ( null === $projection_items ) {
			return null;
		}

		$order_items = $this->parse_mysql_select_order_by_items(
			$tokens,
			$order_position + 2,
			$select_end,
			$projection_items
		);
		if ( null === $order_items ) {
			return null;
		}

		$has_hidden_order_expression = false;
		foreach ( $order_items as $order_item ) {
			if ( null === $order_item['projection_index'] ) {
				$has_hidden_order_expression = true;
				break;
			}
		}

		if ( ! $has_hidden_order_expression ) {
			return $has_sql_calc_found_rows
				? 'SELECT DISTINCT ' . $this->translate_mysql_token_sequence_to_postgresql( $tokens, $projection_start, $statement_end )
				: null;
		}

		return $this->build_distinct_order_by_grouped_query(
			$tokens,
			$projection_items,
			$order_items,
			$from_position,
			$order_position,
			$limit_position,
			$statement_end
		);
	}

	/**
	 * Check whether a token is an unsupported SELECT modifier for this rewrite.
	 *
	 * @param WP_MySQL_Token $token MySQL token.
	 * @return bool Whether the token is an unsupported modifier.
	 */
	private function is_unsupported_distinct_select_modifier( WP_MySQL_Token $token ): bool {
		return in_array(
			$token->id,
			array(
				WP_MySQL_Lexer::HIGH_PRIORITY_SYMBOL,
				WP_MySQL_Lexer::SQL_BIG_RESULT_SYMBOL,
				WP_MySQL_Lexer::SQL_BUFFER_RESULT_SYMBOL,
				WP_MySQL_Lexer::SQL_CACHE_SYMBOL,
				WP_MySQL_Lexer::SQL_NO_CACHE_SYMBOL,
				WP_MySQL_Lexer::SQL_SMALL_RESULT_SYMBOL,
				WP_MySQL_Lexer::STRAIGHT_JOIN_SYMBOL,
			),
			true
		);
	}

	/**
	 * Parse SELECT projection items with expression bounds and visible aliases.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First projection token position.
	 * @param int             $end    Final projection token position, exclusive.
	 * @return array<int, array{expression_start: int, expression_end: int, sql: string, alias: string}>|null Projection items.
	 */
	private function parse_mysql_select_projection_items( array $tokens, int $start, int $end ): ?array {
		$ranges = $this->split_top_level_mysql_arguments( $tokens, $start, $end );
		if ( null === $ranges || count( $ranges ) < 1 ) {
			return null;
		}

		$items        = array();
		$alias_lookup = array();
		foreach ( $ranges as $range ) {
			$item = $this->parse_mysql_select_projection_item( $tokens, $range['start'], $range['end'] );
			if ( null === $item ) {
				return null;
			}

			$alias_key = strtolower( $item['alias'] );
			if ( isset( $alias_lookup[ $alias_key ] ) ) {
				return null;
			}

			$alias_lookup[ $alias_key ] = true;
			$items[]                    = $item;
		}

		return $items;
	}

	/**
	 * Parse one SELECT projection item.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First projection item token position.
	 * @param int             $end    Final projection item token position, exclusive.
	 * @return array{expression_start: int, expression_end: int, sql: string, alias: string}|null Projection item.
	 */
	private function parse_mysql_select_projection_item( array $tokens, int $start, int $end ): ?array {
		if ( $start >= $end ) {
			return null;
		}

		$expression_start = $start;
		$expression_end   = $end;
		$alias            = null;
		$as_position      = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::AS_SYMBOL, $start, $end );

		if ( null !== $as_position ) {
			if ( $as_position <= $start || $as_position + 2 !== $end ) {
				return null;
			}

			$alias = $this->get_mysql_projection_alias_token_value( $tokens[ $as_position + 1 ] ?? null );
			if ( null === $alias ) {
				return null;
			}

			$expression_end = $as_position;
		} else {
			$implicit_alias = $this->get_mysql_implicit_projection_alias( $tokens, $start, $end );
			if ( null !== $implicit_alias ) {
				$alias          = $implicit_alias;
				$expression_end = $end - 1;
			}
		}

		if ( $expression_start >= $expression_end ) {
			return null;
		}

		if ( null === $alias ) {
			$alias = $this->get_mysql_select_expression_default_output_name( $tokens, $expression_start, $expression_end );
			if ( null === $alias ) {
				return null;
			}
		}

		return array(
			'expression_start' => $expression_start,
			'expression_end'   => $expression_end,
			'sql'              => $this->translate_mysql_token_sequence_to_postgresql( $tokens, $expression_start, $expression_end ),
			'alias'            => $alias,
		);
	}

	/**
	 * Get an explicit projection alias token value.
	 *
	 * MySQL permits string-literal aliases in projection context. Keep that
	 * context local so predicate string literals continue to render as values.
	 *
	 * @param WP_MySQL_Token|null $token MySQL token.
	 * @return string|null Alias value, or null when unsupported.
	 */
	private function get_mysql_projection_alias_token_value( ?WP_MySQL_Token $token ): ?string {
		if ( null === $token ) {
			return null;
		}

		$identifier = $this->get_mysql_identifier_token_value( $token );
		if ( null !== $identifier ) {
			return $identifier;
		}

		if ( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id || WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $token->id ) {
			return $token->get_value();
		}

		$value = $token->get_value();
		if ( $this->is_mysql_unquoted_projection_alias_value( $value ) ) {
			return $value;
		}

		return null;
	}

	/**
	 * Check whether a token value is safe as an unquoted MySQL projection alias.
	 *
	 * @param string $value Token value.
	 * @return bool Whether the value is identifier-shaped.
	 */
	private function is_mysql_unquoted_projection_alias_value( string $value ): bool {
		if ( '' === $value ) {
			return false;
		}

		$first_character = $value[0];
		if ( '_' !== $first_character && ! ctype_alpha( $first_character ) ) {
			return false;
		}

		for ( $i = 1, $length = strlen( $value ); $i < $length; $i++ ) {
			$character = $value[ $i ];
			if ( '_' !== $character && '$' !== $character && ! ctype_alnum( $character ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get an implicit projection alias when a complex expression is followed by a name.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First projection item token position.
	 * @param int             $end    Final projection item token position, exclusive.
	 * @return string|null Alias value, or null when absent.
	 */
	private function get_mysql_implicit_projection_alias( array $tokens, int $start, int $end ): ?string {
		if ( $start + 1 >= $end ) {
			return null;
		}

		$alias = $this->get_mysql_identifier_token_value( $tokens[ $end - 1 ] ?? null );
		if ( null === $alias ) {
			return null;
		}

		if ( isset( $tokens[ $end - 2 ] ) && WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $end - 2 ]->id ) {
			return null;
		}

		return $alias;
	}

	/**
	 * Infer the default visible name for a projected expression.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First expression token position.
	 * @param int             $end    Final expression token position, exclusive.
	 * @return string|null Output column name, or null when unsupported.
	 */
	private function get_mysql_select_expression_default_output_name( array $tokens, int $start, int $end ): ?string {
		$bounds = $this->normalize_mysql_expression_bounds( $tokens, $start, $end );
		$start  = $bounds['start'];
		$end    = $bounds['end'];

		if ( $start + 1 === $end ) {
			return $this->get_mysql_identifier_token_value( $tokens[ $start ] ?? null );
		}

		if (
			$start + 3 <= $end
			&& isset( $tokens[ $end - 2 ], $tokens[ $end - 1 ] )
			&& WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $end - 2 ]->id
		) {
			return $this->get_mysql_identifier_token_value( $tokens[ $end - 1 ] );
		}

		return null;
	}

	/**
	 * Parse ORDER BY items and connect them to projected expressions when possible.
	 *
	 * @param WP_MySQL_Token[] $tokens           MySQL lexer token stream.
	 * @param int             $start            First ORDER BY item token position.
	 * @param int             $end              Final ORDER BY token position, exclusive.
	 * @param array           $projection_items Parsed projection items.
	 * @return array<int, array{expression_start: int, expression_end: int, sql: string, direction: string, projection_index: int|null}>|null ORDER BY items.
	 */
	private function parse_mysql_select_order_by_items( array $tokens, int $start, int $end, array $projection_items ): ?array {
		$ranges = $this->split_top_level_mysql_arguments( $tokens, $start, $end );
		if ( null === $ranges || count( $ranges ) < 1 ) {
			return null;
		}

		$items = array();
		foreach ( $ranges as $range ) {
			$expression_end = $range['end'];
			$direction      = 'ASC';

			if (
				isset( $tokens[ $expression_end - 1 ] )
				&& (
					WP_MySQL_Lexer::ASC_SYMBOL === $tokens[ $expression_end - 1 ]->id
					|| WP_MySQL_Lexer::DESC_SYMBOL === $tokens[ $expression_end - 1 ]->id
				)
			) {
				$direction = WP_MySQL_Lexer::DESC_SYMBOL === $tokens[ $expression_end - 1 ]->id ? 'DESC' : 'ASC';
				--$expression_end;
			}

			if ( $range['start'] >= $expression_end ) {
				return null;
			}

			$items[] = array(
				'expression_start' => $range['start'],
				'expression_end'   => $expression_end,
				'sql'              => $this->translate_mysql_token_sequence_to_postgresql(
					$tokens,
					$range['start'],
					$expression_end
				),
				'direction'        => $direction,
				'projection_index' => $this->find_mysql_projection_for_order_expression(
					$tokens,
					$range['start'],
					$expression_end,
					$projection_items
				),
			);
		}

		return $items;
	}

	/**
	 * Find a projection item that satisfies an ORDER BY expression.
	 *
	 * @param WP_MySQL_Token[] $tokens           MySQL lexer token stream.
	 * @param int             $start            First ORDER BY expression token.
	 * @param int             $end              Final ORDER BY expression token, exclusive.
	 * @param array           $projection_items Parsed projection items.
	 * @return int|null Projection item index, or null when not projected.
	 */
	private function find_mysql_projection_for_order_expression( array $tokens, int $start, int $end, array $projection_items ): ?int {
		foreach ( $projection_items as $index => $projection_item ) {
			if (
				$this->are_mysql_token_ranges_equivalent(
					$tokens,
					$start,
					$end,
					$projection_item['expression_start'],
					$projection_item['expression_end']
				)
			) {
				return $index;
			}
		}

		if ( $start + 1 === $end ) {
			$ordinal = $this->get_mysql_order_by_ordinal_projection_index( $tokens[ $start ], count( $projection_items ) );
			if ( null !== $ordinal ) {
				return $ordinal;
			}

			$alias = $this->get_mysql_order_by_alias_token_value( $tokens[ $start ] );
			if ( null !== $alias ) {
				foreach ( $projection_items as $index => $projection_item ) {
					if ( strtolower( $alias ) === strtolower( $projection_item['alias'] ) ) {
						return $index;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Get a one-token ORDER BY alias reference.
	 *
	 * @param WP_MySQL_Token|null $token MySQL token.
	 * @return string|null Alias value, or null when unsupported.
	 */
	private function get_mysql_order_by_alias_token_value( ?WP_MySQL_Token $token ): ?string {
		if ( null === $token ) {
			return null;
		}

		$identifier = $this->get_mysql_identifier_token_value( $token );
		if ( null !== $identifier ) {
			return $identifier;
		}

		if ( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id || WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $token->id ) {
			return null;
		}

		$value = $token->get_value();
		if ( $this->is_mysql_unquoted_projection_alias_value( $value ) ) {
			return $value;
		}

		return null;
	}

	/**
	 * Resolve a positional ORDER BY item to a projection index.
	 *
	 * @param WP_MySQL_Token $token            ORDER BY token.
	 * @param int            $projection_count Number of projected columns.
	 * @return int|null Zero-based projection index, or null when unsupported.
	 */
	private function get_mysql_order_by_ordinal_projection_index( WP_MySQL_Token $token, int $projection_count ): ?int {
		if (
			! in_array( $token->id, array( WP_MySQL_Lexer::INT_NUMBER, WP_MySQL_Lexer::LONG_NUMBER ), true )
			|| ! ctype_digit( $token->get_value() )
		) {
			return null;
		}

		$ordinal = (int) $token->get_value();
		if ( $ordinal < 1 || $ordinal > $projection_count ) {
			return null;
		}

		return $ordinal - 1;
	}

	/**
	 * Check whether a bounded token range contains any token IDs.
	 *
	 * @param WP_MySQL_Token[] $tokens    MySQL lexer token stream.
	 * @param int             $start     First token position, inclusive.
	 * @param int             $end       Final token position, exclusive.
	 * @param int[]           $token_ids Token IDs to detect.
	 * @return bool Whether any token ID was found.
	 */
	private function contains_mysql_token( array $tokens, int $start, int $end, array $token_ids ): bool {
		$lookup = array();
		foreach ( $token_ids as $token_id ) {
			$lookup[ $token_id ] = true;
		}

		for ( $i = $start; $i < $end; $i++ ) {
			if ( isset( $lookup[ $tokens[ $i ]->id ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a grouped derived-table rewrite for DISTINCT ORDER BY queries.
	 *
	 * @param WP_MySQL_Token[] $tokens           MySQL lexer token stream.
	 * @param array           $projection_items Parsed projection items.
	 * @param array           $order_items      Parsed ORDER BY items.
	 * @param int             $from_position    FROM token position.
	 * @param int             $order_position   ORDER token position.
	 * @param int|null        $limit_position   LIMIT token position, or null.
	 * @param int             $statement_end    Final statement token position, exclusive.
	 * @return string PostgreSQL query.
	 */
	private function build_distinct_order_by_grouped_query(
		array $tokens,
		array $projection_items,
		array $order_items,
		int $from_position,
		int $order_position,
		?int $limit_position,
		int $statement_end
	): string {
		$derived_table_alias        = '__wp_pg_distinct';
		$quoted_derived_table_alias = $this->connection->quote_identifier( $derived_table_alias );
		$inner_projection_sql       = array();
		$outer_projection_sql       = array();
		$group_by_sql               = array();

		foreach ( $projection_items as $projection_item ) {
			$quoted_alias           = $this->connection->quote_identifier( $projection_item['alias'] );
			$inner_projection_sql[] = $projection_item['sql'] . ' AS ' . $quoted_alias;
			$outer_projection_sql[] = sprintf(
				'%s.%s AS %s',
				$quoted_derived_table_alias,
				$quoted_alias,
				$quoted_alias
			);
			$group_by_sql[]         = $projection_item['sql'];
		}

		foreach ( $order_items as $index => $order_item ) {
			if ( null !== $order_item['projection_index'] ) {
				continue;
			}

			$aggregate_function     = 'DESC' === $order_item['direction'] ? 'MAX' : 'MIN';
			$quoted_order_alias     = $this->connection->quote_identifier( $this->get_distinct_order_by_hidden_alias( $index ) );
			$inner_projection_sql[] = sprintf(
				'%s(%s) AS %s',
				$aggregate_function,
				$order_item['sql'],
				$quoted_order_alias
			);
		}

		$sql = sprintf(
			'SELECT %s FROM (SELECT %s %s GROUP BY %s) AS %s ORDER BY %s',
			implode( ', ', $outer_projection_sql ),
			implode( ', ', $inner_projection_sql ),
			$this->translate_mysql_token_sequence_to_postgresql( $tokens, $from_position, $order_position ),
			implode( ', ', $group_by_sql ),
			$quoted_derived_table_alias,
			$this->get_distinct_order_by_outer_order_sql( $projection_items, $order_items, $quoted_derived_table_alias )
		);

		if ( null !== $limit_position ) {
			$sql .= $this->translate_simple_select_limit_clause_to_postgresql( $tokens, $limit_position, $statement_end );
		}

		return $sql;
	}

	/**
	 * Get the hidden ORDER BY alias for a parsed order item.
	 *
	 * @param int $index ORDER BY item index.
	 * @return string Hidden alias.
	 */
	private function get_distinct_order_by_hidden_alias( int $index ): string {
		return '__wp_pg_order_' . $index;
	}

	/**
	 * Build the outer ORDER BY clause for a grouped DISTINCT rewrite.
	 *
	 * @param array  $projection_items           Parsed projection items.
	 * @param array  $order_items                Parsed ORDER BY items.
	 * @param string $quoted_derived_table_alias Quoted derived table alias.
	 * @return string Outer ORDER BY SQL.
	 */
	private function get_distinct_order_by_outer_order_sql( array $projection_items, array $order_items, string $quoted_derived_table_alias ): string {
		$order_sql = array();

		foreach ( $order_items as $index => $order_item ) {
			if ( null !== $order_item['projection_index'] ) {
				$order_alias = $projection_items[ $order_item['projection_index'] ]['alias'];
			} else {
				$order_alias = $this->get_distinct_order_by_hidden_alias( $index );
			}

			$order_sql[] = sprintf(
				'%s.%s %s',
				$quoted_derived_table_alias,
				$this->connection->quote_identifier( $order_alias ),
				$order_item['direction']
			);
		}

		return implode( ', ', $order_sql );
	}

	/**
	 * Check whether two expression token ranges are structurally equivalent.
	 *
	 * @param WP_MySQL_Token[] $tokens      MySQL lexer token stream.
	 * @param int             $left_start  First left expression token.
	 * @param int             $left_end    Final left expression token, exclusive.
	 * @param int             $right_start First right expression token.
	 * @param int             $right_end   Final right expression token, exclusive.
	 * @return bool Whether the token ranges are equivalent.
	 */
	private function are_mysql_token_ranges_equivalent(
		array $tokens,
		int $left_start,
		int $left_end,
		int $right_start,
		int $right_end
	): bool {
		$left_bounds  = $this->normalize_mysql_expression_bounds( $tokens, $left_start, $left_end );
		$right_bounds = $this->normalize_mysql_expression_bounds( $tokens, $right_start, $right_end );

		$left_start  = $left_bounds['start'];
		$left_end    = $left_bounds['end'];
		$right_start = $right_bounds['start'];
		$right_end   = $right_bounds['end'];

		if ( $left_end - $left_start !== $right_end - $right_start ) {
			return false;
		}

		for ( $left = $left_start, $right = $right_start; $left < $left_end; $left++, $right++ ) {
			if ( ! $this->are_mysql_tokens_equivalent( $tokens[ $left ], $tokens[ $right ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalize expression bounds by removing full-range wrapper parentheses.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First expression token.
	 * @param int             $end    Final expression token, exclusive.
	 * @return array{start: int, end: int} Normalized bounds.
	 */
	private function normalize_mysql_expression_bounds( array $tokens, int $start, int $end ): array {
		while (
			$start + 2 <= $end
			&& isset( $tokens[ $start ] )
			&& WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $start ]->id
			&& $this->get_mysql_parenthesized_sequence_end( $tokens, $start, $end ) === $end
		) {
			++$start;
			--$end;
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Check whether two individual MySQL tokens are structurally equivalent.
	 *
	 * @param WP_MySQL_Token $left  Left token.
	 * @param WP_MySQL_Token $right Right token.
	 * @return bool Whether the tokens are equivalent.
	 */
	private function are_mysql_tokens_equivalent( WP_MySQL_Token $left, WP_MySQL_Token $right ): bool {
		$left_identifier  = $this->get_mysql_identifier_token_value( $left );
		$right_identifier = $this->get_mysql_identifier_token_value( $right );
		if ( null !== $left_identifier || null !== $right_identifier ) {
			return null !== $left_identifier
				&& null !== $right_identifier
				&& strtolower( $left_identifier ) === strtolower( $right_identifier );
		}

		if ( $left->id !== $right->id ) {
			return false;
		}

		if ( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $left->id || WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $left->id ) {
			return $left->get_value() === $right->get_value();
		}

		return strtolower( $left->get_bytes() ) === strtolower( $right->get_bytes() );
	}

	/**
	 * Check whether a SELECT query uses the MySQL SQL_CALC_FOUND_ROWS modifier.
	 *
	 * @param string $query MySQL query.
	 * @return bool Whether the query asks for FOUND_ROWS tracking.
	 */
	private function is_sql_calc_found_rows_select_query( string $query ): bool {
		$tokens = $this->get_mysql_tokens( $query );
		if ( ! isset( $tokens[0], $tokens[1] ) || WP_MySQL_Lexer::SELECT_SYMBOL !== $tokens[0]->id ) {
			return false;
		}

		if ( null === $this->get_mysql_statement_end_position( $tokens, 1 ) ) {
			return false;
		}

		if ( WP_MySQL_Lexer::SQL_CALC_FOUND_ROWS_SYMBOL === $tokens[1]->id ) {
			return true;
		}

		return isset( $tokens[2] )
			&& WP_MySQL_Lexer::DISTINCT_SYMBOL === $tokens[1]->id
			&& WP_MySQL_Lexer::SQL_CALC_FOUND_ROWS_SYMBOL === $tokens[2]->id;
	}

	/**
	 * Translate WordPress SELECT SQL_CALC_FOUND_ROWS queries.
	 *
	 * PostgreSQL has no SQL_CALC_FOUND_ROWS modifier. WordPress issues these
	 * queries for pagination, followed by SELECT FOUND_ROWS(); this first pass
	 * executes the paginated query itself while preserving compatible clauses.
	 *
	 * @param string $query MySQL query.
	 * @return string|null PostgreSQL query, or null when the query is unsupported.
	 */
	private function translate_sql_calc_found_rows_select_query( string $query ): ?string {
		$tokens = $this->get_mysql_tokens( $query );
		if (
			! isset( $tokens[0], $tokens[1] )
			|| WP_MySQL_Lexer::SELECT_SYMBOL !== $tokens[0]->id
			|| WP_MySQL_Lexer::SQL_CALC_FOUND_ROWS_SYMBOL !== $tokens[1]->id
		) {
			return null;
		}

		$statement_end = $this->get_mysql_statement_end_position( $tokens, 2 );
		if ( null === $statement_end ) {
			return null;
		}

		$select_end     = $statement_end;
		$limit_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::LIMIT_SYMBOL, 2, $statement_end );
		if ( null !== $limit_position ) {
			if ( ! $this->is_supported_simple_select_limit_clause( $tokens, $limit_position, $statement_end ) ) {
				return null;
			}
			$select_end = $limit_position;
		}

		$contextual_sql = $this->translate_mysql_select_statement_with_integer_string_coercion(
			$tokens,
			2,
			$statement_end,
			false
		);
		if ( null !== $contextual_sql ) {
			return $contextual_sql;
		}

		$sql = 'SELECT ' . $this->translate_mysql_token_sequence_to_postgresql( $tokens, 2, $select_end );
		if ( null !== $limit_position ) {
			$sql .= $this->translate_simple_select_limit_clause_to_postgresql( $tokens, $limit_position, $statement_end );
		}

		return $sql;
	}

	/**
	 * Apply conservative MySQL-to-PostgreSQL token compatibility rewrites.
	 *
	 * Complex WordPress queries often use PostgreSQL-compatible SQL except for
	 * MySQL identifier casing. This fallback quotes backticked and mixed-case
	 * identifiers without trying to emulate unsupported MySQL-only syntax.
	 *
	 * @param string $query MySQL query.
	 * @return string|null PostgreSQL query, or null when no compatibility rewrite applies.
	 */
	private function translate_mysql_compatible_query( string $query ): ?string {
		$tokens = $this->get_mysql_tokens( $query );
		if ( ! isset( $tokens[0] ) ) {
			return null;
		}

		if (
			! in_array(
				$tokens[0]->id,
				array(
					WP_MySQL_Lexer::DELETE_SYMBOL,
					WP_MySQL_Lexer::INSERT_SYMBOL,
					WP_MySQL_Lexer::REPLACE_SYMBOL,
					WP_MySQL_Lexer::SELECT_SYMBOL,
					WP_MySQL_Lexer::UPDATE_SYMBOL,
				),
				true
			)
		) {
			return null;
		}

		$statement_end = $this->get_mysql_statement_end_position( $tokens, 1 );
		if ( null === $statement_end ) {
			return null;
		}

		if ( WP_MySQL_Lexer::SELECT_SYMBOL === $tokens[0]->id ) {
			$contextual_sql = $this->translate_mysql_select_statement_with_integer_string_coercion(
				$tokens,
				1,
				$statement_end,
				true
			);
			if ( null !== $contextual_sql ) {
				return $contextual_sql;
			}
		}

		if ( ! $this->needs_mysql_compatible_rewrite( $tokens, 0, $statement_end ) ) {
			return null;
		}

		return $this->translate_mysql_token_sequence_to_postgresql( $tokens, 0, $statement_end );
	}

	/**
	 * Tokenize a MySQL query with the configured lexer implementation.
	 *
	 * @param string $query MySQL query.
	 * @return WP_MySQL_Token[] MySQL lexer token stream.
	 */
	private function get_mysql_tokens( string $query ): array {
		$lexer = new WP_MySQL_Lexer( $query );
		return $lexer instanceof WP_MySQL_Native_Lexer ? $lexer->native_token_stream() : $lexer->remaining_tokens();
	}

	/**
	 * Check whether a table name is a WordPress options table.
	 *
	 * @param string $table_name Table identifier value.
	 * @return bool Whether the table is an options table.
	 */
	private function is_wordpress_options_table_name( string $table_name ): bool {
		$table_name = strtolower( $table_name );
		return 'options' === $table_name || '_options' === substr( $table_name, -8 );
	}

	/**
	 * Parse a parenthesized MySQL identifier list.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Current token position, updated on success.
	 * @return string[]|null Identifier values, or null when unsupported.
	 */
	private function parse_mysql_identifier_list( array $tokens, int &$position ): ?array {
		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $position ]->id ) {
			return null;
		}

		++$position;
		$identifiers = array();

		while ( isset( $tokens[ $position ] ) ) {
			$identifier = $this->get_mysql_identifier_token_value( $tokens[ $position ] );
			if ( null === $identifier ) {
				return null;
			}

			$identifiers[] = $identifier;
			++$position;

			if ( isset( $tokens[ $position ] ) && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $position ]->id ) {
				++$position;
				continue;
			}

			if ( isset( $tokens[ $position ] ) && WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $position ]->id ) {
				++$position;
				return $identifiers;
			}

			return null;
		}

		return null;
	}

	/**
	 * Parse a parenthesized single-row MySQL VALUES list.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Current token position, updated on success.
	 * @return string[]|null Translated SQL values, or null when unsupported.
	 */
	private function parse_mysql_value_list( array $tokens, int &$position ): ?array {
		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $position ]->id ) {
			return null;
		}

		++$position;
		$values      = array();
		$value_start = $position;
		$depth       = 0;

		while ( isset( $tokens[ $position ] ) && WP_MySQL_Lexer::EOF !== $tokens[ $position ]->id ) {
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $position ]->id ) {
				++$depth;
				++$position;
				continue;
			}

			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $position ]->id ) {
				if ( 0 === $depth ) {
					if ( $value_start === $position ) {
						return null;
					}

					$values[] = $this->translate_mysql_token_sequence_to_postgresql( $tokens, $value_start, $position );
					++$position;
					return $values;
				}

				--$depth;
				++$position;
				continue;
			}

			if ( 0 === $depth && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $position ]->id ) {
				if ( $value_start === $position ) {
					return null;
				}

				$values[]    = $this->translate_mysql_token_sequence_to_postgresql( $tokens, $value_start, $position );
				$value_start = $position + 1;
			}

			++$position;
		}

		return null;
	}

	/**
	 * Locate the top-level ON DUPLICATE KEY UPDATE clause.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Token position where scanning starts.
	 * @return int|null Token position of ON, or null when not found.
	 */
	private function find_on_duplicate_key_update_clause( array $tokens, int $position ): ?int {
		$depth = 0;
		for ( $i = $position; isset( $tokens[ $i ] ) && WP_MySQL_Lexer::EOF !== $tokens[ $i ]->id; $i++ ) {
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $i ]->id ) {
				++$depth;
				continue;
			}

			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $i ]->id ) {
				--$depth;
				if ( $depth < 0 ) {
					return null;
				}
				continue;
			}

			if (
				0 === $depth
				&& WP_MySQL_Lexer::ON_SYMBOL === $tokens[ $i ]->id
				&& isset( $tokens[ $i + 3 ] )
				&& WP_MySQL_Lexer::DUPLICATE_SYMBOL === $tokens[ $i + 1 ]->id
				&& WP_MySQL_Lexer::KEY_SYMBOL === $tokens[ $i + 2 ]->id
				&& WP_MySQL_Lexer::UPDATE_SYMBOL === $tokens[ $i + 3 ]->id
			) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * Parse ON DUPLICATE KEY UPDATE assignments for the supported upsert shape.
	 *
	 * @param WP_MySQL_Token[] $tokens        MySQL lexer token stream.
	 * @param int             $position      Current token position, updated on success.
	 * @param array           $column_lookup Insert-column lookup by lowercase name.
	 * @return string[]|null PostgreSQL SET assignments, or null when unsupported.
	 */
	private function parse_upsert_update_assignments( array $tokens, int &$position, array $column_lookup ): ?array {
		$assignments = array();

		while ( isset( $tokens[ $position ] ) ) {
			$target_column = $this->get_mysql_identifier_token_value( $tokens[ $position ] );
			if ( null === $target_column ) {
				return null;
			}

			++$position;
			if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::EQUAL_OPERATOR !== $tokens[ $position ]->id ) {
				return null;
			}

			++$position;
			if (
				! isset( $tokens[ $position + 3 ] )
				|| WP_MySQL_Lexer::VALUES_SYMBOL !== $tokens[ $position ]->id
				|| WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $position + 1 ]->id
				|| WP_MySQL_Lexer::CLOSE_PAR_SYMBOL !== $tokens[ $position + 3 ]->id
			) {
				return null;
			}

			$source_column = $this->get_mysql_identifier_token_value( $tokens[ $position + 2 ] );
			if (
				null === $source_column
				|| strtolower( $source_column ) !== strtolower( $target_column )
				|| ! isset( $column_lookup[ strtolower( $source_column ) ] )
			) {
				return null;
			}

			$assignments[] = sprintf(
				'%s = excluded.%s',
				$this->connection->quote_identifier( $target_column ),
				$this->connection->quote_identifier( $source_column )
			);
			$position     += 4;

			if ( isset( $tokens[ $position ] ) && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $position ]->id ) {
				++$position;
				continue;
			}

			break;
		}

		return count( $assignments ) > 0 ? $assignments : null;
	}

	/**
	 * Validate the narrow SET clause supported by the simple UPDATE translator.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First SET-clause token position.
	 * @param int             $end    Final SET-clause token position, exclusive.
	 * @return bool Whether the SET clause is supported.
	 */
	private function is_supported_simple_update_set_clause( array $tokens, int $start, int $end ): bool {
		for ( $position = $start; $position < $end; ) {
			if (
				null === $this->get_mysql_identifier_token_value( $tokens[ $position ] ?? null )
				|| ! isset( $tokens[ $position + 1 ] )
				|| WP_MySQL_Lexer::EQUAL_OPERATOR !== $tokens[ $position + 1 ]->id
			) {
				return false;
			}

			$value_start    = $position + 2;
			$assignment_end = $this->find_top_level_mysql_token(
				$tokens,
				WP_MySQL_Lexer::COMMA_SYMBOL,
				$value_start,
				$end
			) ?? $end;

			if (
				$value_start >= $assignment_end
				|| ! $this->is_supported_simple_mysql_expression_fragment( $tokens, $value_start, $assignment_end )
			) {
				return false;
			}

			$position = $assignment_end;
			if ( $position === $end ) {
				return true;
			}

			++$position;
		}

		return false;
	}

	/**
	 * Validate a simple SELECT projection list.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First projection token position.
	 * @param int             $end    Final projection token position, exclusive.
	 * @return bool Whether the projection is supported.
	 */
	private function is_supported_simple_select_projection( array $tokens, int $start, int $end ): bool {
		if ( $start + 1 === $end && WP_MySQL_Lexer::MULT_OPERATOR === $tokens[ $start ]->id ) {
			return true;
		}

		if ( $this->is_supported_simple_select_count_projection( $tokens, $start, $end ) ) {
			return true;
		}

		for ( $i = $start; $i < $end; $i++ ) {
			if ( null === $this->get_mysql_identifier_token_value( $tokens[ $i ] ?? null ) ) {
				return false;
			}

			++$i;
			if ( $i >= $end ) {
				return true;
			}

			if ( WP_MySQL_Lexer::COMMA_SYMBOL !== $tokens[ $i ]->id ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Validate the supported COUNT(identifier) projection shape.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First projection token position.
	 * @param int             $end    Final projection token position, exclusive.
	 * @return bool Whether the aggregate projection is supported.
	 */
	private function is_supported_simple_select_count_projection( array $tokens, int $start, int $end ): bool {
		if (
			! isset( $tokens[ $start ], $tokens[ $start + 1 ], $tokens[ $start + 2 ], $tokens[ $start + 3 ] )
			|| WP_MySQL_Lexer::COUNT_SYMBOL !== $tokens[ $start ]->id
			|| WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $start + 1 ]->id
			|| null === $this->get_mysql_identifier_token_value( $tokens[ $start + 2 ] )
			|| WP_MySQL_Lexer::CLOSE_PAR_SYMBOL !== $tokens[ $start + 3 ]->id
		) {
			return false;
		}

		if ( $start + 4 === $end ) {
			return true;
		}

		return $start + 6 === $end
			&& WP_MySQL_Lexer::AS_SYMBOL === $tokens[ $start + 4 ]->id
			&& null !== $this->get_mysql_identifier_token_value( $tokens[ $start + 5 ] );
	}

	/**
	 * Translate a supported simple SELECT projection to PostgreSQL.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First projection token position.
	 * @param int             $end    Final projection token position, exclusive.
	 * @return string PostgreSQL projection SQL.
	 */
	private function translate_simple_select_projection_to_postgresql( array $tokens, int $start, int $end ): string {
		if ( $this->is_supported_simple_select_count_projection( $tokens, $start, $end ) ) {
			$sql = sprintf(
				'COUNT(%s)',
				$this->translate_mysql_identifier_token_to_postgresql( $tokens[ $start + 2 ] )
			);

			if ( $start + 6 === $end ) {
				$sql .= ' ' . $tokens[ $start + 4 ]->get_bytes() . ' ' . $this->translate_mysql_identifier_token_to_postgresql( $tokens[ $start + 5 ] );
			}

			return $sql;
		}

		return $this->translate_mysql_token_sequence_to_postgresql( $tokens, $start, $end );
	}

	/**
	 * Translate a SELECT while coercing integer-column string literals in its WHERE clause.
	 *
	 * @param WP_MySQL_Token[] $tokens                   MySQL lexer token stream.
	 * @param int             $projection_start         First token after SELECT modifiers to render.
	 * @param int             $statement_end            Final statement token position, exclusive.
	 * @param bool            $require_predicate_change Whether unchanged predicates should fall through.
	 * @return string|null PostgreSQL SELECT SQL, or null when no safe contextual translation applies.
	 */
	private function translate_mysql_select_statement_with_integer_string_coercion(
		array $tokens,
		int $projection_start,
		int $statement_end,
		bool $require_predicate_change
	): ?string {
		$where_position = $this->find_top_level_mysql_token(
			$tokens,
			WP_MySQL_Lexer::WHERE_SYMBOL,
			$projection_start,
			$statement_end
		);
		if ( null === $where_position ) {
			return null;
		}

		$from_position = $this->find_top_level_mysql_token(
			$tokens,
			WP_MySQL_Lexer::FROM_SYMBOL,
			$projection_start,
			$where_position
		);
		if ( null === $from_position ) {
			return null;
		}

		$scope = $this->get_mysql_select_scope( $tokens, $from_position + 1, $where_position );
		if ( null === $scope ) {
			return null;
		}

		$where_end = $this->find_first_top_level_mysql_token(
			$tokens,
			array(
				WP_MySQL_Lexer::FOR_SYMBOL,
				WP_MySQL_Lexer::GROUP_SYMBOL,
				WP_MySQL_Lexer::HAVING_SYMBOL,
				WP_MySQL_Lexer::LIMIT_SYMBOL,
				WP_MySQL_Lexer::LOCK_SYMBOL,
				WP_MySQL_Lexer::ORDER_SYMBOL,
				WP_MySQL_Lexer::PROCEDURE_SYMBOL,
				WP_MySQL_Lexer::UNION_SYMBOL,
			),
			$where_position + 1,
			$statement_end
		) ?? $statement_end;

		$where_sql = $this->translate_mysql_predicate_token_sequence_to_postgresql(
			$tokens,
			$where_position + 1,
			$where_end,
			$scope
		);
		if ( $require_predicate_change && ! $where_sql['changed'] ) {
			return null;
		}

		$sql = 'SELECT ' . $this->translate_mysql_token_sequence_to_postgresql( $tokens, $projection_start, $where_position )
			. ' WHERE ' . $where_sql['sql'];

		if ( $where_end < $statement_end ) {
			$sql .= ' ' . $this->translate_mysql_token_sequence_to_postgresql( $tokens, $where_end, $statement_end );
		}

		return $sql;
	}

	/**
	 * Translate predicate tokens with metadata-backed integer string coercion.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First predicate token position.
	 * @param int             $end    Final predicate token position, exclusive.
	 * @param array           $scope  Statement table scope.
	 * @return array{sql: string, changed: bool} Translated predicate SQL and change flag.
	 */
	private function translate_mysql_predicate_token_sequence_to_postgresql(
		array $tokens,
		int $start,
		int $end,
		array $scope
	): array {
		$chunks        = array();
		$segment_start = $start;
		$changed       = false;

		for ( $position = $start; $position < $end; $position++ ) {
			if (
				isset( $tokens[ $position ], $tokens[ $position + 1 ] )
				&& WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $position ]->id
				&& WP_MySQL_Lexer::SELECT_SYMBOL === $tokens[ $position + 1 ]->id
			) {
				$after_subquery = $this->get_mysql_parenthesized_sequence_end( $tokens, $position, $end );
				if ( null !== $after_subquery ) {
					$position = $after_subquery - 1;
					continue;
				}
			}

			$translated_predicate = $this->translate_mysql_integer_column_string_predicate_to_postgresql(
				$tokens,
				$position,
				$end,
				$scope
			);
			if ( null === $translated_predicate ) {
				continue;
			}

			if ( $segment_start < $position ) {
				$chunks[] = $this->translate_mysql_token_sequence_to_postgresql( $tokens, $segment_start, $position );
			}

			$chunks[]      = $translated_predicate['sql'];
			$segment_start = $translated_predicate['position'] + 1;
			$position      = $translated_predicate['position'];
			$changed       = true;
		}

		if ( ! $changed ) {
			return array(
				'sql'     => $this->translate_mysql_token_sequence_to_postgresql( $tokens, $start, $end ),
				'changed' => false,
			);
		}

		if ( $segment_start < $end ) {
			$chunks[] = $this->translate_mysql_token_sequence_to_postgresql( $tokens, $segment_start, $end );
		}

		return array(
			'sql'     => implode( ' ', array_filter( $chunks, 'strlen' ) ),
			'changed' => true,
		);
	}

	/**
	 * Translate one integer-column predicate against string literals.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Candidate predicate start position.
	 * @param int             $end      Final predicate token position, exclusive.
	 * @param array           $scope    Statement table scope.
	 * @return array{sql: string, position: int}|null Translation data, or null when unsupported.
	 */
	private function translate_mysql_integer_column_string_predicate_to_postgresql(
		array $tokens,
		int $position,
		int $end,
		array $scope
	): ?array {
		$in_predicate = $this->translate_mysql_integer_column_string_in_predicate_to_postgresql(
			$tokens,
			$position,
			$end,
			$scope
		);
		if ( null !== $in_predicate ) {
			return $in_predicate;
		}

		return $this->translate_mysql_integer_column_string_comparison_to_postgresql(
			$tokens,
			$position,
			$end,
			$scope
		);
	}

	/**
	 * Translate an integer-column IN list containing string literals.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Candidate predicate start position.
	 * @param int             $end      Final predicate token position, exclusive.
	 * @param array           $scope    Statement table scope.
	 * @return array{sql: string, position: int}|null Translation data, or null when unsupported.
	 */
	private function translate_mysql_integer_column_string_in_predicate_to_postgresql(
		array $tokens,
		int $position,
		int $end,
		array $scope
	): ?array {
		$reference = $this->parse_mysql_column_reference( $tokens, $position, $end );
		if ( null === $reference ) {
			return null;
		}

		$in_position = $reference['end'];
		$not_sql     = '';
		if (
			isset( $tokens[ $in_position ], $tokens[ $in_position + 1 ] )
			&& WP_MySQL_Lexer::NOT_SYMBOL === $tokens[ $in_position ]->id
			&& WP_MySQL_Lexer::IN_SYMBOL === $tokens[ $in_position + 1 ]->id
		) {
			$not_sql      = ' NOT';
			$in_position += 1;
		}

		if (
			! isset( $tokens[ $in_position ], $tokens[ $in_position + 1 ] )
			|| WP_MySQL_Lexer::IN_SYMBOL !== $tokens[ $in_position ]->id
			|| WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $in_position + 1 ]->id
		) {
			return null;
		}

		$after_close = $this->get_mysql_parenthesized_sequence_end( $tokens, $in_position + 1, $end );
		if ( null === $after_close ) {
			return null;
		}

		$items = $this->split_top_level_mysql_arguments( $tokens, $in_position + 2, $after_close - 1 );
		if ( null === $items ) {
			return null;
		}

		$changed  = false;
		$item_sql = array();
		foreach ( $items as $item ) {
			if ( $this->is_mysql_string_literal_range( $tokens, $item['start'], $item['end'] ) ) {
				$item_sql[] = $this->get_postgresql_mysql_integer_cast_sql(
					$this->translate_mysql_token_to_postgresql( $tokens[ $item['start'] ] )
				);
				$changed    = true;
				continue;
			}

			$item_sql[] = $this->translate_mysql_token_sequence_to_postgresql( $tokens, $item['start'], $item['end'] );
		}

		if ( ! $changed ) {
			return null;
		}

		if ( ! $this->is_mysql_integer_column_reference( $reference, $scope ) ) {
			return null;
		}

		return array(
			'sql'      => sprintf(
				'%s%s IN (%s)',
				$this->translate_mysql_token_sequence_to_postgresql( $tokens, $reference['start'], $reference['end'] ),
				$not_sql,
				implode( ', ', $item_sql )
			),
			'position' => $after_close - 1,
		);
	}

	/**
	 * Translate an integer-column comparison against a string literal.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Candidate predicate start position.
	 * @param int             $end      Final predicate token position, exclusive.
	 * @param array           $scope    Statement table scope.
	 * @return array{sql: string, position: int}|null Translation data, or null when unsupported.
	 */
	private function translate_mysql_integer_column_string_comparison_to_postgresql(
		array $tokens,
		int $position,
		int $end,
		array $scope
	): ?array {
		$reference = $this->parse_mysql_column_reference( $tokens, $position, $end );
		if (
			null !== $reference
			&& isset( $tokens[ $reference['end'] ], $tokens[ $reference['end'] + 1 ] )
			&& $reference['end'] + 1 < $end
			&& $this->is_mysql_comparison_operator_token( $tokens[ $reference['end'] ] )
			&& $this->is_mysql_string_literal_token( $tokens[ $reference['end'] + 1 ] )
			&& $this->is_mysql_integer_column_reference( $reference, $scope )
		) {
			return array(
				'sql'      => sprintf(
					'%s %s %s',
					$this->translate_mysql_token_sequence_to_postgresql( $tokens, $reference['start'], $reference['end'] ),
					$tokens[ $reference['end'] ]->get_bytes(),
					$this->get_postgresql_mysql_integer_cast_sql(
						$this->translate_mysql_token_to_postgresql( $tokens[ $reference['end'] + 1 ] )
					)
				),
				'position' => $reference['end'] + 1,
			);
		}

		if (
			! isset( $tokens[ $position ], $tokens[ $position + 1 ] )
			|| ! $this->is_mysql_string_literal_token( $tokens[ $position ] )
			|| ! $this->is_mysql_comparison_operator_token( $tokens[ $position + 1 ] )
		) {
			return null;
		}

		$reference = $this->parse_mysql_column_reference( $tokens, $position + 2, $end );
		if ( null === $reference || ! $this->is_mysql_integer_column_reference( $reference, $scope ) ) {
			return null;
		}

		return array(
			'sql'      => sprintf(
				'%s %s %s',
				$this->get_postgresql_mysql_integer_cast_sql(
					$this->translate_mysql_token_to_postgresql( $tokens[ $position ] )
				),
				$tokens[ $position + 1 ]->get_bytes(),
				$this->translate_mysql_token_sequence_to_postgresql( $tokens, $reference['start'], $reference['end'] )
			),
			'position' => $reference['end'] - 1,
		);
	}

	/**
	 * Build a single-table statement scope.
	 *
	 * @param string      $table_name Table name.
	 * @param string|null $alias      Optional table alias.
	 * @param string      $schema     Metadata schema.
	 * @return array Statement scope.
	 */
	private function get_mysql_single_table_scope(
		string $table_name,
		?string $alias = null,
		string $schema = 'public'
	): array {
		$table = array(
			'schema' => $this->resolve_mysql_table_schema_for_introspection( $schema, $table_name ),
			'table'  => $table_name,
		);

		return array(
			'tables'  => array( $table ),
			'aliases' => array(
				strtolower( null === $alias ? $table_name : $alias ) => $table,
			),
		);
	}

	/**
	 * Parse top-level SELECT table references into a metadata lookup scope.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First FROM-clause token after FROM.
	 * @param int             $end    Final FROM-clause token, exclusive.
	 * @return array|null Statement scope, or null when ambiguous/unsupported.
	 */
	private function get_mysql_select_scope( array $tokens, int $start, int $end ): ?array {
		$scope       = array(
			'tables'  => array(),
			'aliases' => array(),
			'unknown' => false,
		);
		$position    = $start;
		$expect_next = true;

		while ( $position < $end ) {
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $position ]->id ) {
				$after_parentheses = $this->get_mysql_parenthesized_sequence_end( $tokens, $position, $end );
				if ( null === $after_parentheses ) {
					return null;
				}

				$position = $after_parentheses;
				if ( $expect_next ) {
					$scope['unknown'] = true;
					$position         = $this->skip_mysql_table_alias( $tokens, $position, $end );
					$expect_next      = false;
				}
				continue;
			}

			if ( $expect_next ) {
				$reference = $this->parse_mysql_table_reference( $tokens, $position, $end );
				if ( null === $reference ) {
					return null;
				}

				$table = array(
					'schema' => $this->resolve_mysql_table_schema_for_introspection( $reference['schema'], $reference['table'] ),
					'table'  => $reference['table'],
				);
				$alias = strtolower( null === $reference['alias'] ? $reference['table'] : $reference['alias'] );
				if ( isset( $scope['aliases'][ $alias ] ) ) {
					return null;
				}

				$scope['tables'][]          = $table;
				$scope['aliases'][ $alias ] = $table;
				$position                   = $reference['position'];
				$expect_next                = false;
				continue;
			}

			if (
				WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $position ]->id
				|| $this->is_mysql_join_token( $tokens[ $position ] )
			) {
				$expect_next = true;
			}

			++$position;
		}

		return empty( $scope['tables'] ) || $expect_next ? null : $scope;
	}

	/**
	 * Parse a simple table reference and optional alias.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Table reference start position.
	 * @param int             $end      Final FROM-clause token, exclusive.
	 * @return array{schema: string, table: string, alias: string|null, position: int}|null Parsed table reference.
	 */
	private function parse_mysql_table_reference( array $tokens, int $position, int $end ): ?array {
		$first_identifier = $this->get_mysql_identifier_token_value( $tokens[ $position ] ?? null );
		if ( null === $first_identifier ) {
			return null;
		}

		$schema = 'public';
		$table  = $first_identifier;
		++$position;

		if ( $position + 1 < $end && WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $position ]->id ) {
			$second_identifier = $this->get_mysql_identifier_token_value( $tokens[ $position + 1 ] ?? null );
			if ( null === $second_identifier ) {
				return null;
			}

			$schema    = $first_identifier;
			$table     = $second_identifier;
			$position += 2;
		}

		$alias = null;
		if ( $position + 1 < $end && WP_MySQL_Lexer::AS_SYMBOL === $tokens[ $position ]->id ) {
			$alias = $this->get_mysql_identifier_token_value( $tokens[ $position + 1 ] ?? null );
			if ( null === $alias ) {
				return null;
			}

			$position += 2;
		} else {
			$implicit_alias = $this->get_mysql_identifier_token_value( $tokens[ $position ] ?? null );
			if ( null !== $implicit_alias ) {
				$alias = $implicit_alias;
				++$position;
			}
		}

		return array(
			'schema'   => $schema,
			'table'    => $table,
			'alias'    => $alias,
			'position' => $position,
		);
	}

	/**
	 * Skip a derived-table alias when one is present.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Current token position.
	 * @param int             $end      Final FROM-clause token, exclusive.
	 * @return int Position after the alias.
	 */
	private function skip_mysql_table_alias( array $tokens, int $position, int $end ): int {
		if ( $position + 1 < $end && WP_MySQL_Lexer::AS_SYMBOL === $tokens[ $position ]->id ) {
			return null === $this->get_mysql_identifier_token_value( $tokens[ $position + 1 ] ?? null )
				? $position
				: $position + 2;
		}

		return null === $this->get_mysql_identifier_token_value( $tokens[ $position ] ?? null )
			? $position
			: $position + 1;
	}

	/**
	 * Check whether a token starts a JOIN table operand.
	 *
	 * @param WP_MySQL_Token $token MySQL token.
	 * @return bool Whether the token is a JOIN separator.
	 */
	private function is_mysql_join_token( WP_MySQL_Token $token ): bool {
		return WP_MySQL_Lexer::JOIN_SYMBOL === $token->id || WP_MySQL_Lexer::STRAIGHT_JOIN_SYMBOL === $token->id;
	}

	/**
	 * Parse a simple column reference.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Column reference start position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{start: int, end: int, qualifier: string|null, column: string}|null Parsed reference.
	 */
	private function parse_mysql_column_reference( array $tokens, int $position, int $end ): ?array {
		$first_identifier = $this->get_mysql_identifier_token_value( $tokens[ $position ] ?? null );
		if ( null === $first_identifier ) {
			return null;
		}

		if ( $position + 2 < $end && WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $position + 1 ]->id ) {
			$column = $this->get_mysql_identifier_token_value( $tokens[ $position + 2 ] ?? null );
			if ( null === $column ) {
				return null;
			}

			return array(
				'start'     => $position,
				'end'       => $position + 3,
				'qualifier' => $first_identifier,
				'column'    => $column,
			);
		}

		return array(
			'start'     => $position,
			'end'       => $position + 1,
			'qualifier' => null,
			'column'    => $first_identifier,
		);
	}

	/**
	 * Check whether a column reference resolves to one integer-family MySQL column.
	 *
	 * @param array $reference Parsed column reference.
	 * @param array $scope     Statement table scope.
	 * @return bool Whether the reference is a known integer column.
	 */
	private function is_mysql_integer_column_reference( array $reference, array $scope ): bool {
		$column_type = $this->get_mysql_column_type_for_reference( $reference, $scope );
		return null !== $column_type && $this->is_mysql_integer_family_column_type( $column_type );
	}

	/**
	 * Resolve a column reference to stored MySQL column type metadata.
	 *
	 * @param array $reference Parsed column reference.
	 * @param array $scope     Statement table scope.
	 * @return string|null MySQL column type, or null when missing/ambiguous.
	 */
	private function get_mysql_column_type_for_reference( array $reference, array $scope ): ?string {
		if ( null !== $reference['qualifier'] ) {
			$alias = strtolower( $reference['qualifier'] );
			if ( ! isset( $scope['aliases'][ $alias ] ) ) {
				return null;
			}

			$table = $scope['aliases'][ $alias ];
			return $this->get_mysql_table_column_type( $table['schema'], $table['table'], $reference['column'] );
		}

		if ( ! empty( $scope['unknown'] ) ) {
			return null;
		}

		$matched_type = null;
		foreach ( $scope['tables'] as $table ) {
			if (
				count( $scope['tables'] ) > 1
				&& ! $this->mysql_table_has_column_metadata( $table['schema'], $table['table'] )
			) {
				return null;
			}

			$column_type = $this->get_mysql_table_column_type( $table['schema'], $table['table'], $reference['column'] );
			if ( null === $column_type ) {
				continue;
			}

			if ( null !== $matched_type ) {
				return null;
			}

			$matched_type = $column_type;
		}

		return $matched_type;
	}

	/**
	 * Check whether a token range is exactly one string literal.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First token position.
	 * @param int             $end    Final token position, exclusive.
	 * @return bool Whether the range is one string literal.
	 */
	private function is_mysql_string_literal_range( array $tokens, int $start, int $end ): bool {
		return $start + 1 === $end && isset( $tokens[ $start ] ) && $this->is_mysql_string_literal_token( $tokens[ $start ] );
	}

	/**
	 * Check whether a token is a string literal.
	 *
	 * @param WP_MySQL_Token $token MySQL token.
	 * @return bool Whether the token is a string literal.
	 */
	private function is_mysql_string_literal_token( WP_MySQL_Token $token ): bool {
		return WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id || WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $token->id;
	}

	/**
	 * Check whether a token is a simple comparison operator.
	 *
	 * @param WP_MySQL_Token $token MySQL token.
	 * @return bool Whether the token is a comparison operator.
	 */
	private function is_mysql_comparison_operator_token( WP_MySQL_Token $token ): bool {
		return in_array(
			$token->id,
			array(
				WP_MySQL_Lexer::EQUAL_OPERATOR,
				WP_MySQL_Lexer::GREATER_OR_EQUAL_OPERATOR,
				WP_MySQL_Lexer::GREATER_THAN_OPERATOR,
				WP_MySQL_Lexer::LESS_OR_EQUAL_OPERATOR,
				WP_MySQL_Lexer::LESS_THAN_OPERATOR,
				WP_MySQL_Lexer::NOT_EQUAL_OPERATOR,
			),
			true
		);
	}

	/**
	 * Validate the simple expression fragments used by translated DML/SELECT.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First fragment token position.
	 * @param int             $end    Final fragment token position, exclusive.
	 * @return bool Whether the expression fragment is supported.
	 */
	private function is_supported_simple_mysql_expression_fragment( array $tokens, int $start, int $end ): bool {
		for ( $i = $start; $i < $end; $i++ ) {
			if ( ! $this->is_supported_simple_mysql_expression_token( $tokens[ $i ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate a token for a simple expression fragment.
	 *
	 * @param WP_MySQL_Token $token MySQL token.
	 * @return bool Whether the token is supported.
	 */
	private function is_supported_simple_mysql_expression_token( WP_MySQL_Token $token ): bool {
		if ( null !== $this->get_mysql_identifier_token_value( $token ) ) {
			return true;
		}

		return in_array(
			$token->id,
			array(
				WP_MySQL_Lexer::AND_SYMBOL,
				WP_MySQL_Lexer::DECIMAL_NUMBER,
				WP_MySQL_Lexer::EQUAL_OPERATOR,
				WP_MySQL_Lexer::FALSE_SYMBOL,
				WP_MySQL_Lexer::FLOAT_NUMBER,
				WP_MySQL_Lexer::GREATER_OR_EQUAL_OPERATOR,
				WP_MySQL_Lexer::GREATER_THAN_OPERATOR,
				WP_MySQL_Lexer::HEX_NUMBER,
				WP_MySQL_Lexer::INT_NUMBER,
				WP_MySQL_Lexer::LESS_OR_EQUAL_OPERATOR,
				WP_MySQL_Lexer::LESS_THAN_OPERATOR,
				WP_MySQL_Lexer::LONG_NUMBER,
				WP_MySQL_Lexer::NOT_EQUAL_OPERATOR,
				WP_MySQL_Lexer::NULL_SYMBOL,
				WP_MySQL_Lexer::SINGLE_QUOTED_TEXT,
				WP_MySQL_Lexer::TRUE_SYMBOL,
				WP_MySQL_Lexer::ULONGLONG_NUMBER,
			),
			true
		);
	}

	/**
	 * Validate a simple ORDER BY clause.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  ORDER token position.
	 * @param int             $end    Final clause token position, exclusive.
	 * @return bool Whether the ORDER BY clause is supported.
	 */
	private function is_supported_simple_select_order_by_clause( array $tokens, int $start, int $end ): bool {
		if (
			$start + 2 >= $end
			|| WP_MySQL_Lexer::ORDER_SYMBOL !== $tokens[ $start ]->id
			|| WP_MySQL_Lexer::BY_SYMBOL !== $tokens[ $start + 1 ]->id
			|| null === $this->get_mysql_identifier_token_value( $tokens[ $start + 2 ] )
		) {
			return false;
		}

		if ( $start + 3 === $end ) {
			return true;
		}

		return $start + 4 === $end
			&& (
				WP_MySQL_Lexer::ASC_SYMBOL === $tokens[ $start + 3 ]->id
				|| WP_MySQL_Lexer::DESC_SYMBOL === $tokens[ $start + 3 ]->id
			);
	}

	/**
	 * Validate a safe trailing SELECT LIMIT clause.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  LIMIT token position.
	 * @param int             $end    Final clause token position, exclusive.
	 * @return bool Whether the LIMIT clause is supported.
	 */
	private function is_supported_simple_select_limit_clause( array $tokens, int $start, int $end ): bool {
		if (
			! isset( $tokens[ $start ], $tokens[ $start + 1 ] )
			|| WP_MySQL_Lexer::LIMIT_SYMBOL !== $tokens[ $start ]->id
		) {
			return false;
		}

		if ( $start + 2 === $end ) {
			return $this->is_supported_simple_select_limit_number( $tokens[ $start + 1 ] );
		}

		return $start + 4 === $end
			&& isset( $tokens[ $start + 2 ], $tokens[ $start + 3 ] )
			&& WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $start + 2 ]->id
			&& $this->is_supported_simple_select_limit_number( $tokens[ $start + 1 ] )
			&& $this->is_supported_simple_select_limit_number( $tokens[ $start + 3 ] );
	}

	/**
	 * Validate a LIMIT number token.
	 *
	 * @param WP_MySQL_Token $token MySQL lexer token.
	 * @return bool Whether the token is a supported non-negative integer.
	 */
	private function is_supported_simple_select_limit_number( WP_MySQL_Token $token ): bool {
		$is_parameter_marker = WP_MySQL_Lexer::PARAM_MARKER === $token->id;
		return in_array(
			$token->id,
			array(
				WP_MySQL_Lexer::INT_NUMBER,
				WP_MySQL_Lexer::LONG_NUMBER,
				WP_MySQL_Lexer::PARAM_MARKER,
				WP_MySQL_Lexer::ULONGLONG_NUMBER,
			),
			true
		) && ( $is_parameter_marker || ctype_digit( $token->get_value() ) );
	}

	/**
	 * Translate a supported trailing SELECT LIMIT clause to PostgreSQL.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  LIMIT token position.
	 * @param int             $end    Final clause token position, exclusive.
	 * @return string PostgreSQL LIMIT clause.
	 */
	private function translate_simple_select_limit_clause_to_postgresql( array $tokens, int $start, int $end ): string {
		if ( $start + 4 === $end ) {
			return ' LIMIT ' . $tokens[ $start + 3 ]->get_bytes() . ' OFFSET ' . $tokens[ $start + 1 ]->get_bytes();
		}

		return ' LIMIT ' . $tokens[ $start + 1 ]->get_bytes();
	}

	/**
	 * Find the token position ending a single MySQL statement.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Token position where scanning starts.
	 * @return int|null EOF or semicolon token position, or null for multi-statements.
	 */
	private function get_mysql_statement_end_position( array $tokens, int $position ): ?int {
		for ( $i = $position; isset( $tokens[ $i ] ); $i++ ) {
			if ( WP_MySQL_Lexer::EOF === $tokens[ $i ]->id ) {
				return $i;
			}

			if ( WP_MySQL_Lexer::SEMICOLON_SYMBOL === $tokens[ $i ]->id ) {
				return $this->is_at_mysql_query_end( $tokens, $i ) ? $i : null;
			}
		}

		return null;
	}

	/**
	 * Find the position after a matching parenthesized token sequence.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Opening parenthesis position.
	 * @param int             $limit    Final token position, exclusive.
	 * @return int|null Position after the matching close parenthesis, or null.
	 */
	private function get_mysql_parenthesized_sequence_end( array $tokens, int $position, int $limit ): ?int {
		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $position ]->id ) {
			return null;
		}

		$depth = 0;
		for ( $i = $position; $i < $limit; $i++ ) {
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $i ]->id ) {
				++$depth;
				continue;
			}

			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL !== $tokens[ $i ]->id ) {
				continue;
			}

			--$depth;
			if ( 0 === $depth ) {
				return $i + 1;
			}

			if ( $depth < 0 ) {
				return null;
			}
		}

		return null;
	}

	/**
	 * Find a top-level MySQL token in a bounded token range.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $token_id Token ID to find.
	 * @param int             $start    First token position, inclusive.
	 * @param int             $end      Final token position, exclusive.
	 * @return int|null Token position, or null when not found.
	 */
	private function find_top_level_mysql_token( array $tokens, int $token_id, int $start, int $end ): ?int {
		$depth = 0;

		for ( $i = $start; $i < $end; $i++ ) {
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $i ]->id ) {
				++$depth;
				continue;
			}

			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $i ]->id ) {
				--$depth;
				if ( $depth < 0 ) {
					return null;
				}
				continue;
			}

			if ( 0 === $depth && $token_id === $tokens[ $i ]->id ) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * Find the first top-level token matching any supplied token ID.
	 *
	 * @param WP_MySQL_Token[] $tokens    MySQL lexer token stream.
	 * @param int[]           $token_ids Token IDs to find.
	 * @param int             $start     First token position, inclusive.
	 * @param int             $end       Final token position, exclusive.
	 * @return int|null Token position, or null when not found.
	 */
	private function find_first_top_level_mysql_token( array $tokens, array $token_ids, int $start, int $end ): ?int {
		$lookup = array();
		foreach ( $token_ids as $token_id ) {
			$lookup[ $token_id ] = true;
		}

		$depth = 0;
		for ( $i = $start; $i < $end; $i++ ) {
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $i ]->id ) {
				++$depth;
				continue;
			}

			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $i ]->id ) {
				--$depth;
				if ( $depth < 0 ) {
					return null;
				}
				continue;
			}

			if ( 0 === $depth && isset( $lookup[ $tokens[ $i ]->id ] ) ) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * Check whether a bounded token range contains any top-level token IDs.
	 *
	 * @param WP_MySQL_Token[] $tokens    MySQL lexer token stream.
	 * @param int             $start     First token position, inclusive.
	 * @param int             $end       Final token position, exclusive.
	 * @param int[]           $token_ids Token IDs to detect.
	 * @return bool Whether any token ID was found.
	 */
	private function contains_top_level_mysql_token( array $tokens, int $start, int $end, array $token_ids ): bool {
		foreach ( $token_ids as $token_id ) {
			if ( null !== $this->find_top_level_mysql_token( $tokens, $token_id, $start, $end ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether the token position is at the end of a single query.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Current token position.
	 * @return bool Whether only an optional semicolon and EOF remain.
	 */
	private function is_at_mysql_query_end( array $tokens, int $position ): bool {
		if ( isset( $tokens[ $position ] ) && WP_MySQL_Lexer::SEMICOLON_SYMBOL === $tokens[ $position ]->id ) {
			++$position;
		}

		return isset( $tokens[ $position ] ) && WP_MySQL_Lexer::EOF === $tokens[ $position ]->id;
	}

	/**
	 * Translate a MySQL token sequence to PostgreSQL SQL.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First token position, inclusive.
	 * @param int             $end    Final token position, exclusive.
	 * @return string PostgreSQL SQL fragment.
	 */
	private function translate_mysql_token_sequence_to_postgresql( array $tokens, int $start, int $end ): string {
		$sql               = '';
		$previous_token_id = null;

		for ( $i = $start; $i < $end; $i++ ) {
			$token               = $tokens[ $i ];
			$fragment_token_id   = $token->id;
			$translated_fragment = $this->translate_mysql_limit_offset_count_to_postgresql( $tokens, $i, $end );
			if ( null === $translated_fragment ) {
				$translated_fragment = $this->translate_mysql_field_function_to_postgresql( $tokens, $i, $end );
			}
			if ( null === $translated_fragment ) {
				$translated_fragment = $this->translate_mysql_integer_cast_to_postgresql( $tokens, $i, $end );
			}
			if ( null === $translated_fragment ) {
				$translated_fragment = $this->translate_mysql_regexp_operator_to_postgresql( $tokens, $i, $end );
			}
			if ( null === $translated_fragment ) {
				$translated_fragment = $this->translate_mysql_rand_function_to_postgresql( $tokens, $i, $end );
			}
			if ( null === $translated_fragment ) {
				$translated_fragment = $this->translate_mysql_date_time_extract_to_postgresql( $tokens, $i, $end );
			}
			if ( null === $translated_fragment ) {
				$translated_fragment = $this->translate_mysql_convert_using_to_postgresql( $tokens, $i, $end );
			}

			if ( null !== $translated_fragment ) {
				$fragment          = $translated_fragment['sql'];
				$fragment_token_id = $translated_fragment['token_id'];
				$i                 = $translated_fragment['position'];
			} else {
				$fragment = $this->translate_mysql_token_to_postgresql( $token, $tokens[ $i + 1 ] ?? null );
			}

			if ( '' === $sql ) {
				$sql = $fragment;
			} elseif ( $this->should_join_mysql_tokens_without_space( $previous_token_id, $fragment_token_id ) ) {
				$sql .= $fragment;
			} else {
				$sql .= ' ' . $fragment;
			}

			$previous_token_id = $fragment_token_id;
		}

		return $sql;
	}

	/**
	 * Translate MySQL LIMIT offset,count syntax to PostgreSQL LIMIT count OFFSET offset.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position LIMIT token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{sql: string, token_id: int, position: int}|null Translation data, or null when unsupported.
	 */
	private function translate_mysql_limit_offset_count_to_postgresql( array $tokens, int $position, int $end ): ?array {
		$bounds = $this->get_mysql_limit_offset_count_bounds( $tokens, $position, $end );
		if ( null === $bounds ) {
			return null;
		}

		$sql = 'LIMIT ' . $tokens[ $bounds['count_position'] ]->get_bytes()
			. ' OFFSET ' . $tokens[ $bounds['offset_position'] ]->get_bytes();

		return array(
			'sql'      => $sql,
			'token_id' => WP_MySQL_Lexer::LIMIT_SYMBOL,
			'position' => $bounds['count_position'],
		);
	}

	/**
	 * Get token bounds for a MySQL LIMIT offset,count clause.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position LIMIT token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{offset_position: int, count_position: int}|null Bounds, or null when unsupported.
	 */
	private function get_mysql_limit_offset_count_bounds( array $tokens, int $position, int $end ): ?array {
		if (
			! isset( $tokens[ $position ], $tokens[ $position + 1 ], $tokens[ $position + 2 ], $tokens[ $position + 3 ] )
			|| WP_MySQL_Lexer::LIMIT_SYMBOL !== $tokens[ $position ]->id
			|| WP_MySQL_Lexer::COMMA_SYMBOL !== $tokens[ $position + 2 ]->id
			|| $position + 4 !== $end
			|| ! $this->is_supported_simple_select_limit_number( $tokens[ $position + 1 ] )
			|| ! $this->is_supported_simple_select_limit_number( $tokens[ $position + 3 ] )
		) {
			return null;
		}

		return array(
			'offset_position' => $position + 1,
			'count_position'  => $position + 3,
		);
	}

	/**
	 * Translate MySQL FIELD(expr, value, ...) to a PostgreSQL CASE expression.
	 *
	 * PostgreSQL does not coerce unknown text and integer values the same way
	 * MySQL FIELD() does. Cast both sides of each comparison to text to keep the
	 * WordPress ordering use-cases executable across mixed ID/name arguments.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Function token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{sql: string, token_id: int, position: int}|null Translation data, or null when unsupported.
	 */
	private function translate_mysql_field_function_to_postgresql( array $tokens, int $position, int $end ): ?array {
		$bounds = $this->get_mysql_function_call_bounds( $tokens, $position, $end, 'field' );
		if ( null === $bounds ) {
			return null;
		}

		$arguments = $this->split_top_level_mysql_arguments( $tokens, $bounds['arguments_start'], $bounds['arguments_end'] );
		if ( null === $arguments || count( $arguments ) < 2 ) {
			return null;
		}

		$value_sql = $this->translate_mysql_token_sequence_to_postgresql(
			$tokens,
			$arguments[0]['start'],
			$arguments[0]['end']
		);

		$clauses = array(
			sprintf( 'WHEN %s IS NULL THEN 0', $value_sql ),
		);

		for ( $i = 1; $i < count( $arguments ); $i++ ) {
			$argument_sql = $this->translate_mysql_token_sequence_to_postgresql(
				$tokens,
				$arguments[ $i ]['start'],
				$arguments[ $i ]['end']
			);

			$clauses[] = sprintf(
				'WHEN CAST(%1$s AS text) = CAST(%2$s AS text) THEN %3$d',
				$value_sql,
				$argument_sql,
				$i
			);
		}

		return array(
			'sql'      => 'CASE ' . implode( ' ', $clauses ) . ' ELSE 0 END',
			'token_id' => WP_MySQL_Lexer::CASE_SYMBOL,
			'position' => $bounds['close'],
		);
	}

	/**
	 * Translate MySQL CAST(expr AS SIGNED/UNSIGNED [INTEGER]) to PostgreSQL.
	 *
	 * Both SIGNED and UNSIGNED map to bigint. This preserves WordPress meta
	 * comparison/query execution but does not emulate MySQL UNSIGNED wraparound.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position CAST token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{sql: string, token_id: int, position: int}|null Translation data, or null when unsupported.
	 */
	private function translate_mysql_integer_cast_to_postgresql( array $tokens, int $position, int $end ): ?array {
		$bounds = $this->get_mysql_integer_cast_bounds( $tokens, $position, $end );
		if ( null === $bounds ) {
			return null;
		}

		$expression_sql = $this->translate_mysql_token_sequence_to_postgresql(
			$tokens,
			$bounds['expression_start'],
			$bounds['expression_end']
		);

		return array(
			'sql'      => $this->get_postgresql_mysql_integer_cast_sql( $expression_sql ),
			'token_id' => WP_MySQL_Lexer::CAST_SYMBOL,
			'position' => $bounds['close'],
		);
	}

	/**
	 * Get PostgreSQL SQL for MySQL-compatible integer text coercion.
	 *
	 * MySQL accepts text values when casting to SIGNED/UNSIGNED and coerces the
	 * leading integer prefix, or zero when no prefix exists. PostgreSQL bigint
	 * casts reject those values, so extract a safe prefix before casting.
	 *
	 * @param string $expression_sql PostgreSQL expression SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_postgresql_mysql_integer_cast_sql( string $expression_sql ): string {
		$expression_text_sql = sprintf( 'CAST(%s AS text)', $expression_sql );
		$integer_pattern     = $this->connection->quote( '^[[:space:]]*[+-]?[0-9]+' );

		return sprintf(
			'CASE WHEN %1$s IS NULL THEN NULL ELSE CAST(COALESCE(SUBSTRING(%1$s, %2$s), \'0\') AS bigint) END',
			$expression_text_sql,
			$integer_pattern
		);
	}

	/**
	 * Get token bounds for a supported MySQL integer CAST expression.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position CAST token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{expression_start: int, expression_end: int, close: int}|null Bounds, or null when unsupported.
	 */
	private function get_mysql_integer_cast_bounds( array $tokens, int $position, int $end ): ?array {
		if (
			! isset( $tokens[ $position ], $tokens[ $position + 1 ] )
			|| WP_MySQL_Lexer::CAST_SYMBOL !== $tokens[ $position ]->id
			|| WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $position + 1 ]->id
		) {
			return null;
		}

		$after_close = $this->get_mysql_parenthesized_sequence_end( $tokens, $position + 1, $end );
		if ( null === $after_close ) {
			return null;
		}

		$close_position = $after_close - 1;
		$as_position    = $this->find_top_level_mysql_token(
			$tokens,
			WP_MySQL_Lexer::AS_SYMBOL,
			$position + 2,
			$close_position
		);
		if (
			null === $as_position
			|| $as_position <= $position + 2
			|| null === $this->get_postgresql_integer_cast_type( $tokens, $as_position + 1, $close_position )
		) {
			return null;
		}

		return array(
			'expression_start' => $position + 2,
			'expression_end'   => $as_position,
			'close'            => $close_position,
		);
	}

	/**
	 * Get the PostgreSQL type for supported MySQL integer cast type tokens.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First cast type token.
	 * @param int             $end    Final cast type token, exclusive.
	 * @return string|null PostgreSQL type SQL, or null when unsupported.
	 */
	private function get_postgresql_integer_cast_type( array $tokens, int $start, int $end ): ?string {
		if (
			! isset( $tokens[ $start ] )
			|| ! in_array(
				$tokens[ $start ]->id,
				array(
					WP_MySQL_Lexer::SIGNED_SYMBOL,
					WP_MySQL_Lexer::UNSIGNED_SYMBOL,
				),
				true
			)
		) {
			return null;
		}

		if ( $start + 1 === $end ) {
			return 'bigint';
		}

		if (
			$start + 2 === $end
			&& isset( $tokens[ $start + 1 ] )
			&& in_array(
				$tokens[ $start + 1 ]->id,
				array(
					WP_MySQL_Lexer::INT_SYMBOL,
					WP_MySQL_Lexer::INTEGER_SYMBOL,
				),
				true
			)
		) {
			return 'bigint';
		}

		return null;
	}

	/**
	 * Translate MySQL REGEXP/RLIKE operators to PostgreSQL regex operators.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Operator token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{sql: string, token_id: int, position: int}|null Translation data, or null when unsupported.
	 */
	private function translate_mysql_regexp_operator_to_postgresql( array $tokens, int $position, int $end ): ?array {
		if ( ! isset( $tokens[ $position ] ) ) {
			return null;
		}

		if (
			WP_MySQL_Lexer::REGEXP_SYMBOL === $tokens[ $position ]->id
			&& ! $this->is_mysql_regexp_binary_predicate( $tokens, $position + 1, $end )
		) {
			return array(
				'sql'      => '~*',
				'token_id' => WP_MySQL_Lexer::REGEXP_SYMBOL,
				'position' => $position,
			);
		}

		if (
			isset( $tokens[ $position + 1 ] )
			&& WP_MySQL_Lexer::NOT_SYMBOL === $tokens[ $position ]->id
			&& WP_MySQL_Lexer::REGEXP_SYMBOL === $tokens[ $position + 1 ]->id
			&& ! $this->is_mysql_regexp_binary_predicate( $tokens, $position + 2, $end )
		) {
			return array(
				'sql'      => '!~*',
				'token_id' => WP_MySQL_Lexer::REGEXP_SYMBOL,
				'position' => $position + 1,
			);
		}

		return null;
	}

	/**
	 * Check whether a REGEXP predicate starts with the unsupported BINARY modifier.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position First right-hand predicate token.
	 * @param int             $end      Final token position, exclusive.
	 * @return bool Whether the predicate uses REGEXP BINARY/RLIKE BINARY.
	 */
	private function is_mysql_regexp_binary_predicate( array $tokens, int $position, int $end ): bool {
		return $position < $end
			&& isset( $tokens[ $position ] )
			&& WP_MySQL_Lexer::BINARY_SYMBOL === $tokens[ $position ]->id;
	}

	/**
	 * Translate MySQL RAND() and RAND(seed) calls to PostgreSQL random().
	 *
	 * PostgreSQL setseed() is session-stateful, so RAND(seed) deliberately maps
	 * to random() for now instead of leaking deterministic seed state into later
	 * statements. Seeded deterministic ordering is left for a higher-fidelity
	 * emulation path.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Function token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{sql: string, token_id: int, position: int}|null Translation data, or null when unsupported.
	 */
	private function translate_mysql_rand_function_to_postgresql( array $tokens, int $position, int $end ): ?array {
		$bounds = $this->get_mysql_function_call_bounds( $tokens, $position, $end, 'rand' );
		if ( null === $bounds ) {
			return null;
		}

		$arguments = $this->split_top_level_mysql_arguments( $tokens, $bounds['arguments_start'], $bounds['arguments_end'] );
		if ( null === $arguments || count( $arguments ) > 1 ) {
			return null;
		}

		return array(
			'sql'      => 'random()',
			'token_id' => WP_MySQL_Lexer::IDENTIFIER,
			'position' => $bounds['close'],
		);
	}

	/**
	 * Get token bounds for a MySQL identifier function call.
	 *
	 * @param WP_MySQL_Token[] $tokens        MySQL lexer token stream.
	 * @param int             $position      Function token position.
	 * @param int             $end           Final token position, exclusive.
	 * @param string          $function_name Lowercase function name to match.
	 * @return array{arguments_start: int, arguments_end: int, close: int}|null Bounds, or null when unsupported.
	 */
	private function get_mysql_function_call_bounds( array $tokens, int $position, int $end, string $function_name ): ?array {
		if (
			! isset( $tokens[ $position ], $tokens[ $position + 1 ] )
			|| WP_MySQL_Lexer::IDENTIFIER !== $tokens[ $position ]->id
			|| strtolower( $tokens[ $position ]->get_value() ) !== $function_name
			|| WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $position + 1 ]->id
		) {
			return null;
		}

		$after_close = $this->get_mysql_parenthesized_sequence_end( $tokens, $position + 1, $end );
		if ( null === $after_close ) {
			return null;
		}

		return array(
			'arguments_start' => $position + 2,
			'arguments_end'   => $after_close - 1,
			'close'           => $after_close - 1,
		);
	}

	/**
	 * Split a bounded token range into top-level comma-separated arguments.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First argument token position.
	 * @param int             $end    Final argument token position, exclusive.
	 * @return array<int, array{start: int, end: int}>|null Argument bounds, or null when malformed.
	 */
	private function split_top_level_mysql_arguments( array $tokens, int $start, int $end ): ?array {
		if ( $start === $end ) {
			return array();
		}

		$arguments      = array();
		$argument_start = $start;
		$depth          = 0;

		for ( $i = $start; $i < $end; $i++ ) {
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $i ]->id ) {
				++$depth;
				continue;
			}

			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $i ]->id ) {
				--$depth;
				if ( $depth < 0 ) {
					return null;
				}
				continue;
			}

			if ( 0 === $depth && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $i ]->id ) {
				if ( $argument_start === $i ) {
					return null;
				}

				$arguments[]    = array(
					'start' => $argument_start,
					'end'   => $i,
				);
				$argument_start = $i + 1;
			}
		}

		if ( 0 !== $depth || $argument_start === $end ) {
			return null;
		}

		$arguments[] = array(
			'start' => $argument_start,
			'end'   => $end,
		);

		return $arguments;
	}

	/**
	 * Translate supported MySQL date/time extract functions to PostgreSQL.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Function token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{sql: string, token_id: int, position: int}|null Translation data, or null when unsupported.
	 */
	private function translate_mysql_date_time_extract_to_postgresql( array $tokens, int $position, int $end ): ?array {
		$bounds = $this->get_mysql_extract_function_bounds( $tokens, $position, $end );
		if ( null === $bounds ) {
			return null;
		}

		$expression_sql = $this->translate_mysql_token_sequence_to_postgresql(
			$tokens,
			$bounds['expression_start'],
			$bounds['expression_end']
		);

		return array(
			'sql'      => $this->get_postgresql_zero_date_safe_extract_sql( $bounds['unit'], $expression_sql ),
			'token_id' => $tokens[ $position ]->id,
			'position' => $bounds['close'],
		);
	}

	/**
	 * Get PostgreSQL SQL for a MySQL date/time extract that preserves MySQL zero-date behavior.
	 *
	 * PostgreSQL rejects MySQL zero-ish dates such as 0000-00-00 during timestamp
	 * casts. Detect those text-backed values first and extract the requested
	 * numeric part directly from the text; keep valid dates on PostgreSQL's
	 * timestamp EXTRACT path.
	 *
	 * @param string $unit           PostgreSQL EXTRACT unit.
	 * @param string $expression_sql PostgreSQL expression SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_postgresql_zero_date_safe_extract_sql( string $unit, string $expression_sql ): string {
		$expression_text_sql      = sprintf( 'CAST(%s AS text)', $expression_sql );
		$date_text_pattern        = "'^[0-9]{4}-[0-9]{2}-[0-9]{2}'";
		$zero_date_condition      = sprintf(
			'%1$s ~ %2$s AND (SUBSTRING(%1$s FROM 1 FOR 4) = \'0000\' OR SUBSTRING(%1$s FROM 6 FOR 2) = \'00\' OR SUBSTRING(%1$s FROM 9 FOR 2) = \'00\')',
			$expression_text_sql,
			$date_text_pattern
		);
		$timestamp_expression_sql = sprintf(
			'CASE WHEN %1$s THEN NULL ELSE %2$s END',
			$zero_date_condition,
			$expression_text_sql
		);

		return sprintf(
			'CASE WHEN %1$s THEN %2$s ELSE CAST(EXTRACT(%3$s FROM CAST(%4$s AS timestamp)) AS integer) END',
			$zero_date_condition,
			$this->get_postgresql_zero_date_extract_part_sql( $unit, $expression_text_sql ),
			$unit,
			$timestamp_expression_sql
		);
	}

	/**
	 * Get PostgreSQL SQL that extracts one part from a zero-ish MySQL date string.
	 *
	 * @param string $unit                PostgreSQL EXTRACT unit.
	 * @param string $expression_text_sql PostgreSQL expression cast to text.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_postgresql_zero_date_extract_part_sql( string $unit, string $expression_text_sql ): string {
		$date_time_text_pattern = "'^[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}'";

		switch ( $unit ) {
			case 'YEAR':
				return sprintf( 'CAST(SUBSTRING(%s FROM 1 FOR 4) AS integer)', $expression_text_sql );

			case 'MONTH':
				return sprintf( 'CAST(SUBSTRING(%s FROM 6 FOR 2) AS integer)', $expression_text_sql );

			case 'DAY':
				return sprintf( 'CAST(SUBSTRING(%s FROM 9 FOR 2) AS integer)', $expression_text_sql );

			case 'HOUR':
				$start = 12;
				break;

			case 'MINUTE':
				$start = 15;
				break;

			case 'SECOND':
				$start = 18;
				break;

			default:
				return sprintf( 'CAST(EXTRACT(%s FROM CAST(%s AS timestamp)) AS integer)', $unit, $expression_text_sql );
		}

		return sprintf(
			'CASE WHEN %1$s ~ %2$s THEN CAST(SUBSTRING(%1$s FROM %3$d FOR 2) AS integer) ELSE 0 END',
			$expression_text_sql,
			$date_time_text_pattern,
			$start
		);
	}

	/**
	 * Get token bounds for supported MySQL date/time extract forms.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Function token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{unit: string, expression_start: int, expression_end: int, close: int}|null Bounds, or null when unsupported.
	 */
	private function get_mysql_extract_function_bounds( array $tokens, int $position, int $end ): ?array {
		if ( ! isset( $tokens[ $position ], $tokens[ $position + 1 ] ) ) {
			return null;
		}

		if ( WP_MySQL_Lexer::EXTRACT_SYMBOL === $tokens[ $position ]->id ) {
			return $this->get_mysql_extract_keyword_bounds( $tokens, $position, $end );
		}

		return $this->get_mysql_date_time_function_bounds( $tokens, $position, $end );
	}

	/**
	 * Get token bounds for EXTRACT(unit FROM expr).
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position EXTRACT token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{unit: string, expression_start: int, expression_end: int, close: int}|null Bounds, or null when unsupported.
	 */
	private function get_mysql_extract_keyword_bounds( array $tokens, int $position, int $end ): ?array {
		if (
			! isset( $tokens[ $position + 3 ] )
			|| WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $position + 1 ]->id
			|| WP_MySQL_Lexer::FROM_SYMBOL !== $tokens[ $position + 3 ]->id
		) {
			return null;
		}

		$unit = $this->get_mysql_date_time_extract_unit( $tokens[ $position + 2 ] );
		if ( null === $unit ) {
			return null;
		}

		$after_close = $this->get_mysql_parenthesized_sequence_end( $tokens, $position + 1, $end );
		if ( null === $after_close || $position + 4 >= $after_close - 1 ) {
			return null;
		}

		return array(
			'unit'             => $unit,
			'expression_start' => $position + 4,
			'expression_end'   => $after_close - 1,
			'close'            => $after_close - 1,
		);
	}

	/**
	 * Get token bounds for YEAR(expr), MONTH(expr), DAY(expr), and similar calls.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Function token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{unit: string, expression_start: int, expression_end: int, close: int}|null Bounds, or null when unsupported.
	 */
	private function get_mysql_date_time_function_bounds( array $tokens, int $position, int $end ): ?array {
		$unit = $this->get_mysql_date_time_extract_unit( $tokens[ $position ] );
		if (
			null === $unit
			|| ! isset( $tokens[ $position + 1 ] )
			|| WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $position + 1 ]->id
		) {
			return null;
		}

		$after_close = $this->get_mysql_parenthesized_sequence_end( $tokens, $position + 1, $end );
		if ( null === $after_close ) {
			return null;
		}

		$close_position = $after_close - 1;
		if (
			$position + 2 >= $close_position
			|| null !== $this->find_top_level_mysql_token(
				$tokens,
				WP_MySQL_Lexer::COMMA_SYMBOL,
				$position + 2,
				$close_position
			)
		) {
			return null;
		}

		return array(
			'unit'             => $unit,
			'expression_start' => $position + 2,
			'expression_end'   => $close_position,
			'close'            => $close_position,
		);
	}

	/**
	 * Get the PostgreSQL EXTRACT unit for a MySQL date/time token.
	 *
	 * @param WP_MySQL_Token $token MySQL token.
	 * @return string|null PostgreSQL EXTRACT unit, or null when unsupported.
	 */
	private function get_mysql_date_time_extract_unit( WP_MySQL_Token $token ): ?string {
		switch ( $token->id ) {
			case WP_MySQL_Lexer::YEAR_SYMBOL:
				return 'YEAR';

			case WP_MySQL_Lexer::MONTH_SYMBOL:
				return 'MONTH';

			case WP_MySQL_Lexer::DAY_SYMBOL:
			case WP_MySQL_Lexer::DAYOFMONTH_SYMBOL:
				return 'DAY';

			case WP_MySQL_Lexer::HOUR_SYMBOL:
				return 'HOUR';

			case WP_MySQL_Lexer::MINUTE_SYMBOL:
				return 'MINUTE';

			case WP_MySQL_Lexer::SECOND_SYMBOL:
				return 'SECOND';
		}

		return null;
	}

	/**
	 * Translate a MySQL CONVERT(expr USING charset) expression to PostgreSQL.
	 *
	 * PostgreSQL text is already stored in the database encoding, so the MySQL
	 * character-set conversion is represented by the inner expression. A directly
	 * attached MySQL COLLATE clause is dropped because PostgreSQL does not have
	 * MySQL collation names.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position CONVERT token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{sql: string, token_id: int, position: int}|null Translation data, or null when unsupported.
	 */
	private function translate_mysql_convert_using_to_postgresql( array $tokens, int $position, int $end ): ?array {
		$bounds = $this->get_mysql_convert_using_bounds( $tokens, $position, $end );
		if ( null === $bounds ) {
			return null;
		}

		$final_position = $bounds['close'];
		if (
			isset( $tokens[ $final_position + 1 ], $tokens[ $final_position + 2 ] )
			&& $final_position + 2 < $end
			&& WP_MySQL_Lexer::COLLATE_SYMBOL === $tokens[ $final_position + 1 ]->id
			&& null !== $this->get_mysql_identifier_token_value( $tokens[ $final_position + 2 ] )
		) {
			$final_position += 2;
		}

		$expression_sql = $this->translate_mysql_token_sequence_to_postgresql(
			$tokens,
			$bounds['expression_start'],
			$bounds['expression_end']
		);

		return array(
			'sql'      => '(' . $expression_sql . ')',
			'token_id' => $tokens[ $bounds['expression_start'] ]->id,
			'position' => $final_position,
		);
	}

	/**
	 * Get token bounds for a supported MySQL CONVERT(expr USING charset) expression.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position CONVERT token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{expression_start: int, expression_end: int, close: int}|null Bounds, or null when unsupported.
	 */
	private function get_mysql_convert_using_bounds( array $tokens, int $position, int $end ): ?array {
		if (
			! isset( $tokens[ $position ], $tokens[ $position + 1 ] )
			|| WP_MySQL_Lexer::CONVERT_SYMBOL !== $tokens[ $position ]->id
			|| WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $position + 1 ]->id
		) {
			return null;
		}

		$after_close = $this->get_mysql_parenthesized_sequence_end( $tokens, $position + 1, $end );
		if ( null === $after_close ) {
			return null;
		}

		$close_position = $after_close - 1;
		$using_position = $this->find_top_level_mysql_token(
			$tokens,
			WP_MySQL_Lexer::USING_SYMBOL,
			$position + 2,
			$close_position
		);
		if (
			null === $using_position
			|| $using_position <= $position + 2
			|| $using_position + 2 !== $close_position
			|| ! $this->is_mysql_charset_token( $tokens[ $using_position + 1 ] ?? null )
		) {
			return null;
		}

		return array(
			'expression_start' => $position + 2,
			'expression_end'   => $using_position,
			'close'            => $close_position,
		);
	}

	/**
	 * Translate a single MySQL token to a PostgreSQL fragment.
	 *
	 * @param WP_MySQL_Token      $token      MySQL token.
	 * @param WP_MySQL_Token|null $next_token Next MySQL token, if known.
	 * @return string PostgreSQL SQL fragment.
	 */
	private function translate_mysql_token_to_postgresql( WP_MySQL_Token $token, ?WP_MySQL_Token $next_token = null ): string {
		if (
			WP_MySQL_Lexer::IDENTIFIER === $token->id
			&& $this->should_quote_bare_mysql_identifier( $token->get_value() )
			&& ( null === $next_token || WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $next_token->id )
		) {
			return $this->connection->quote_identifier( $token->get_value() );
		}

		if ( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id ) {
			return $this->connection->quote_identifier( $token->get_value() );
		}

		if ( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id || WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $token->id ) {
			return $this->connection->quote( $token->get_value() );
		}

		return $token->get_bytes();
	}

	/**
	 * Translate a MySQL identifier token to a PostgreSQL identifier fragment.
	 *
	 * @param WP_MySQL_Token|null $token MySQL token.
	 * @return string PostgreSQL identifier fragment.
	 */
	private function translate_mysql_identifier_token_to_postgresql( ?WP_MySQL_Token $token ): string {
		if ( null === $token ) {
			return '';
		}

		return $this->translate_mysql_token_to_postgresql( $token );
	}

	/**
	 * Check whether two translated tokens should be joined without a space.
	 *
	 * @param int|null $previous_token_id Previous token ID.
	 * @param int      $token_id          Current token ID.
	 * @return bool Whether no separator should be added.
	 */
	private function should_join_mysql_tokens_without_space( ?int $previous_token_id, int $token_id ): bool {
		return in_array(
			$token_id,
			array(
				WP_MySQL_Lexer::CLOSE_PAR_SYMBOL,
				WP_MySQL_Lexer::COMMA_SYMBOL,
				WP_MySQL_Lexer::DOT_SYMBOL,
				WP_MySQL_Lexer::SEMICOLON_SYMBOL,
			),
			true
		) || in_array(
			$previous_token_id,
			array(
				WP_MySQL_Lexer::DOT_SYMBOL,
				WP_MySQL_Lexer::OPEN_PAR_SYMBOL,
			),
			true
		);
	}

	/**
	 * Get a MySQL identifier token value.
	 *
	 * @param WP_MySQL_Token|null $token MySQL token.
	 * @return string|null Identifier value, or null when the token is unsupported.
	 */
	private function get_mysql_identifier_token_value( ?WP_MySQL_Token $token ): ?string {
		if ( null === $token ) {
			return null;
		}

		if ( WP_MySQL_Lexer::IDENTIFIER === $token->id || WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id ) {
			return $token->get_value();
		}

		return null;
	}

	/**
	 * Check whether a token is an identifier-like token with the expected value.
	 *
	 * Some MySQL information_schema column names, such as TABLE_NAME, are lexed
	 * as keyword tokens. Treat them like identifiers only in explicit catalog
	 * translator contexts.
	 *
	 * @param WP_MySQL_Token|null $token MySQL token.
	 * @param string              $value Expected identifier value.
	 * @return bool Whether the token has the expected identifier-like value.
	 */
	private function is_mysql_identifier_like_token_value( ?WP_MySQL_Token $token, string $value ): bool {
		if ( null === $token ) {
			return false;
		}

		$identifier = $this->get_mysql_identifier_token_value( $token );
		if ( null === $identifier && WP_MySQL_Lexer::TABLE_NAME_SYMBOL === $token->id ) {
			$identifier = $token->get_value();
		}

		return null !== $identifier && strtolower( $identifier ) === strtolower( $value );
	}

	/**
	 * Check whether a token can represent a MySQL character set name.
	 *
	 * @param WP_MySQL_Token|null $token MySQL token.
	 * @return bool Whether the token is a supported charset token.
	 */
	private function is_mysql_charset_token( ?WP_MySQL_Token $token ): bool {
		if ( null === $token ) {
			return false;
		}

		return null !== $this->get_mysql_identifier_token_value( $token )
			|| in_array(
				$token->id,
				array(
					WP_MySQL_Lexer::ASCII_SYMBOL,
					WP_MySQL_Lexer::BINARY_SYMBOL,
					WP_MySQL_Lexer::DEFAULT_SYMBOL,
					WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT,
					WP_MySQL_Lexer::SINGLE_QUOTED_TEXT,
				),
				true
			);
	}

	/**
	 * Get a MySQL charset/collation token value.
	 *
	 * @param WP_MySQL_Token $token MySQL token.
	 * @return string Token value.
	 */
	private function get_mysql_charset_token_value( WP_MySQL_Token $token ): string {
		if ( WP_MySQL_Lexer::DEFAULT_SYMBOL === $token->id ) {
			return 'default';
		}

		return $token->get_value();
	}

	/**
	 * Check whether a bare MySQL identifier needs PostgreSQL quoting.
	 *
	 * @param string $identifier Identifier token value.
	 * @return bool Whether the bare identifier must be quoted.
	 */
	private function should_quote_bare_mysql_identifier( string $identifier ): bool {
		return strtolower( $identifier ) !== $identifier;
	}

	/**
	 * Check whether a token range needs the compatibility rewrite.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int              $start  First token position.
	 * @param int              $end    Final token position, exclusive.
	 * @return bool Whether any token needs PostgreSQL compatibility rewriting.
	 */
	private function needs_mysql_compatible_rewrite( array $tokens, int $start, int $end ): bool {
		for ( $i = $start; $i < $end; $i++ ) {
			$token = $tokens[ $i ];
			if ( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id ) {
				return true;
			}

			if ( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id ) {
				return true;
			}

			if ( null !== $this->get_mysql_convert_using_bounds( $tokens, $i, $end ) ) {
				return true;
			}

			if ( null !== $this->get_mysql_function_call_bounds( $tokens, $i, $end, 'field' ) ) {
				return true;
			}

			if ( null !== $this->get_mysql_integer_cast_bounds( $tokens, $i, $end ) ) {
				return true;
			}

			if ( null !== $this->translate_mysql_regexp_operator_to_postgresql( $tokens, $i, $end ) ) {
				return true;
			}

			if ( null !== $this->get_mysql_function_call_bounds( $tokens, $i, $end, 'rand' ) ) {
				return true;
			}

			if ( null !== $this->get_mysql_limit_offset_count_bounds( $tokens, $i, $end ) ) {
				return true;
			}

			if ( null !== $this->get_mysql_extract_function_bounds( $tokens, $i, $end ) ) {
				return true;
			}

			if (
				WP_MySQL_Lexer::IDENTIFIER === $token->id
				&& $this->should_quote_bare_mysql_identifier( $token->get_value() )
				&& ( ! isset( $tokens[ $i + 1 ] ) || WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $i + 1 ]->id )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a query is a supported MySQL CREATE TABLE statement.
	 *
	 * @param string $query MySQL query.
	 * @return bool Whether the query should be translated before execution.
	 */
	private function is_create_table_query( string $query ): bool {
		$lexer  = new WP_MySQL_Lexer( $query );
		$tokens = $lexer instanceof WP_MySQL_Native_Lexer ? $lexer->native_token_stream() : $lexer->remaining_tokens();

		if ( ! isset( $tokens[0] ) || WP_MySQL_Lexer::CREATE_SYMBOL !== $tokens[0]->id ) {
			return false;
		}

		$position = 1;
		if ( isset( $tokens[ $position ] ) && WP_MySQL_Lexer::TEMPORARY_SYMBOL === $tokens[ $position ]->id ) {
			++$position;
		}

		return isset( $tokens[ $position ] )
			&& WP_MySQL_Lexer::TABLE_SYMBOL === $tokens[ $position ]->id
			&& $this->has_mysql_create_table_marker( $tokens );
	}

	/**
	 * Check whether a CREATE TABLE query creates a temporary table.
	 *
	 * @param string $query MySQL query.
	 * @return bool Whether the query is CREATE TEMPORARY TABLE.
	 */
	private function is_temporary_create_table_query( string $query ): bool {
		$lexer  = new WP_MySQL_Lexer( $query );
		$tokens = $lexer instanceof WP_MySQL_Native_Lexer ? $lexer->native_token_stream() : $lexer->remaining_tokens();

		return isset( $tokens[0], $tokens[1], $tokens[2] )
			&& WP_MySQL_Lexer::CREATE_SYMBOL === $tokens[0]->id
			&& WP_MySQL_Lexer::TEMPORARY_SYMBOL === $tokens[1]->id
			&& WP_MySQL_Lexer::TABLE_SYMBOL === $tokens[2]->id;
	}

	/**
	 * Check whether a CREATE TABLE query contains MySQL install-schema syntax.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @return bool Whether the query should use the install DDL translator.
	 */
	private function has_mysql_create_table_marker( array $tokens ): bool {
		foreach ( $tokens as $position => $token ) {
			if ( $this->is_mysql_create_table_charset_set_marker( $tokens, $position ) ) {
				return true;
			}

			if (
				in_array(
					$token->id,
					array(
						WP_MySQL_Lexer::AUTO_INCREMENT_SYMBOL,
						WP_MySQL_Lexer::BACK_TICK_QUOTED_ID,
						WP_MySQL_Lexer::CHARSET_SYMBOL,
						WP_MySQL_Lexer::COLLATE_SYMBOL,
						WP_MySQL_Lexer::ENGINE_SYMBOL,
						WP_MySQL_Lexer::FULLTEXT_SYMBOL,
						WP_MySQL_Lexer::ROW_FORMAT_SYMBOL,
						WP_MySQL_Lexer::SPATIAL_SYMBOL,
						WP_MySQL_Lexer::UNSIGNED_SYMBOL,
					),
					true
				)
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether tokens at a position form CHAR SET or CHARACTER SET.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int              $position Current token position.
	 * @return bool Whether this is a MySQL charset marker.
	 */
	private function is_mysql_create_table_charset_set_marker( array $tokens, int $position ): bool {
		if (
			! isset( $tokens[ $position ], $tokens[ $position + 1 ] )
			|| WP_MySQL_Lexer::SET_SYMBOL !== $tokens[ $position + 1 ]->id
		) {
			return false;
		}

		if ( WP_MySQL_Lexer::CHARACTER_SYMBOL === $tokens[ $position ]->id ) {
			return true;
		}

		return WP_MySQL_Lexer::CHAR_SYMBOL === $tokens[ $position ]->id
			&& in_array( strtolower( $tokens[ $position ]->get_bytes() ), array( 'char', 'character' ), true );
	}

	/**
	 * Get the selected MySQL sql_mode variable name from a supported query.
	 *
	 * @param string $query MySQL query.
	 * @return string|null Selected variable name, or null when unsupported.
	 */
	private function get_sql_mode_select_variable( string $query ): ?string {
		$tokens = $this->get_mysql_tokens( $query );
		if (
			! isset( $tokens[0], $tokens[1], $tokens[2] )
			|| WP_MySQL_Lexer::SELECT_SYMBOL !== $tokens[0]->id
			|| WP_MySQL_Lexer::AT_AT_SIGN_SYMBOL !== $tokens[1]->id
		) {
			return null;
		}

		if (
			WP_MySQL_Lexer::IDENTIFIER === $tokens[2]->id
			&& 'sql_mode' === strtolower( $tokens[2]->get_value() )
			&& $this->is_at_mysql_query_end( $tokens, 3 )
		) {
			return '@@' . $tokens[2]->get_value();
		}

		if (
			! isset( $tokens[3], $tokens[4] )
			|| (
				WP_MySQL_Lexer::SESSION_SYMBOL !== $tokens[2]->id
				&& WP_MySQL_Lexer::GLOBAL_SYMBOL !== $tokens[2]->id
			)
			|| WP_MySQL_Lexer::DOT_SYMBOL !== $tokens[3]->id
			|| WP_MySQL_Lexer::IDENTIFIER !== $tokens[4]->id
			|| 'sql_mode' !== strtolower( $tokens[4]->get_value() )
			|| ! $this->is_at_mysql_query_end( $tokens, 5 )
		) {
			return null;
		}

		return '@@' . $tokens[2]->get_value() . '.' . $tokens[4]->get_value();
	}

	/**
	 * Check whether a query asks for MySQL FOUND_ROWS().
	 *
	 * @param string $query MySQL query.
	 * @return bool Whether the query is SELECT FOUND_ROWS().
	 */
	private function is_found_rows_query( string $query ): bool {
		$tokens = $this->get_mysql_tokens( $query );
		if (
			! isset( $tokens[0], $tokens[1], $tokens[2], $tokens[3] )
			|| WP_MySQL_Lexer::SELECT_SYMBOL !== $tokens[0]->id
			|| WP_MySQL_Lexer::IDENTIFIER !== $tokens[1]->id
			|| 'found_rows' !== strtolower( $tokens[1]->get_value() )
			|| WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[2]->id
			|| WP_MySQL_Lexer::CLOSE_PAR_SYMBOL !== $tokens[3]->id
		) {
			return false;
		}

		return $this->is_at_mysql_query_end( $tokens, 4 );
	}

	/**
	 * Check whether a MySQL runtime setting is intentionally ignored.
	 *
	 * WordPress PHPUnit bootstrap emits MySQL-only SET statements before schema
	 * installation. PostgreSQL has no equivalent state for these settings, so they
	 * should not be sent to PDO. Keep this intentionally narrow so unsupported SET
	 * statements still fail visibly.
	 *
	 * @param string $query MySQL query.
	 * @return bool Whether the query should be treated as a successful no-op.
	 */
	private function is_noop_mysql_runtime_setting( string $query ): bool {
		$lexer  = new WP_MySQL_Lexer( $query );
		$tokens = $lexer instanceof WP_MySQL_Native_Lexer ? $lexer->native_token_stream() : $lexer->remaining_tokens();

		if ( ! isset( $tokens[0] ) || WP_MySQL_Lexer::SET_SYMBOL !== $tokens[0]->id ) {
			return false;
		}

		$position = 1;
		if (
			isset( $tokens[ $position ] )
			&& in_array(
				$tokens[ $position ]->id,
				array(
					WP_MySQL_Lexer::GLOBAL_SYMBOL,
					WP_MySQL_Lexer::LOCAL_SYMBOL,
					WP_MySQL_Lexer::SESSION_SYMBOL,
				),
				true
			)
		) {
			++$position;
		}

		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::IDENTIFIER !== $tokens[ $position ]->id ) {
			return false;
		}

		$variable = strtolower( $tokens[ $position ]->get_value() );
		if (
			! in_array(
				$variable,
				array(
					'autocommit',
					'default_storage_engine',
					'foreign_key_checks',
					'sql_mode',
					'storage_engine',
				),
				true
			)
		) {
			return false;
		}

		++$position;
		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::EQUAL_OPERATOR !== $tokens[ $position ]->id ) {
			return false;
		}

		++$position;
		$has_value = false;
		while ( isset( $tokens[ $position ] ) && WP_MySQL_Lexer::EOF !== $tokens[ $position ]->id ) {
			if ( WP_MySQL_Lexer::SEMICOLON_SYMBOL === $tokens[ $position ]->id ) {
				++$position;
				break;
			}

			if ( WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $position ]->id ) {
				return false;
			}

			$has_value = true;
			++$position;
		}

		return $has_value && isset( $tokens[ $position ] ) && WP_MySQL_Lexer::EOF === $tokens[ $position ]->id;
	}

	/**
	 * Read the backend server version without requiring a PostgreSQL-only query.
	 *
	 * @return string
	 */
	private function read_server_version(): string {
		try {
			$version = $this->connection->get_pdo()->getAttribute( PDO::ATTR_SERVER_VERSION );
			if ( false !== $version && null !== $version ) {
				return (string) $version;
			}
		} catch ( Throwable $e ) {
			return 'PostgreSQL';
		}

		return 'PostgreSQL';
	}

	/**
	 * Normalize PDO column metadata into the MySQLi-shaped fields wpdb expects.
	 *
	 * @param PDOStatement $stmt The statement to inspect.
	 * @return array
	 */
	private function normalize_column_meta( PDOStatement $stmt ): array {
		$meta = array();
		for ( $i = 0; $i < $stmt->columnCount(); $i++ ) {
			$column_meta = $stmt->getColumnMeta( $i );
			if ( ! is_array( $column_meta ) ) {
				$column_meta = array();
			}
			$meta[] = $this->normalize_single_column_meta( $column_meta );
		}
		return $meta;
	}

	/**
	 * Normalize metadata for one column.
	 *
	 * @param array $column_meta Raw PDO column metadata.
	 * @return array
	 */
	private function normalize_single_column_meta( array $column_meta ): array {
		$name        = isset( $column_meta['name'] ) ? (string) $column_meta['name'] : '';
		$table       = isset( $column_meta['table'] ) ? (string) $column_meta['table'] : '';
		$native_type = isset( $column_meta['native_type'] ) ? strtolower( (string) $column_meta['native_type'] ) : '';
		$type        = $this->map_native_type( $native_type );
		$length      = isset( $column_meta['len'] ) ? (int) $column_meta['len'] : 0;
		$precision   = isset( $column_meta['precision'] ) ? (int) $column_meta['precision'] : 0;

		return array(
			'native_type'      => $type['native_type'],
			'pdo_type'         => $type['pdo_type'],
			'flags'            => isset( $column_meta['flags'] ) && is_array( $column_meta['flags'] ) ? $column_meta['flags'] : array(),
			'table'            => $table,
			'name'             => $name,
			'len'              => $length,
			'precision'        => $precision,
			'mysqli:orgname'   => $name,
			'mysqli:orgtable'  => $table,
			'mysqli:db'        => $this->db_name,
			'mysqli:charsetnr' => $type['charsetnr'],
			'mysqli:flags'     => 0,
			'mysqli:type'      => $type['mysqli_type'],
		);
	}

	/**
	 * Map PostgreSQL native type names to conservative MySQL/PDO metadata.
	 *
	 * @param string $native_type Lowercase PDO native type.
	 * @return array
	 */
	private function map_native_type( string $native_type ): array {
		$defaults = array(
			'native_type' => 'VAR_STRING',
			'pdo_type'    => PDO::PARAM_STR,
			'mysqli_type' => 253,
			'charsetnr'   => 255,
		);

		$map = array(
			'int2'        => array(
				'native_type' => 'SHORT',
				'pdo_type'    => PDO::PARAM_INT,
				'mysqli_type' => 2,
				'charsetnr'   => 63,
			),
			'smallint'    => array(
				'native_type' => 'SHORT',
				'pdo_type'    => PDO::PARAM_INT,
				'mysqli_type' => 2,
				'charsetnr'   => 63,
			),
			'int4'        => array(
				'native_type' => 'LONG',
				'pdo_type'    => PDO::PARAM_INT,
				'mysqli_type' => 3,
				'charsetnr'   => 63,
			),
			'integer'     => array(
				'native_type' => 'LONG',
				'pdo_type'    => PDO::PARAM_INT,
				'mysqli_type' => 3,
				'charsetnr'   => 63,
			),
			'int8'        => array(
				'native_type' => 'LONGLONG',
				'pdo_type'    => PDO::PARAM_INT,
				'mysqli_type' => 8,
				'charsetnr'   => 63,
			),
			'bigint'      => array(
				'native_type' => 'LONGLONG',
				'pdo_type'    => PDO::PARAM_INT,
				'mysqli_type' => 8,
				'charsetnr'   => 63,
			),
			'bytea'       => array(
				'native_type' => 'BLOB',
				'pdo_type'    => PDO::PARAM_LOB,
				'mysqli_type' => 252,
				'charsetnr'   => 63,
			),
			'blob'        => array(
				'native_type' => 'BLOB',
				'pdo_type'    => PDO::PARAM_LOB,
				'mysqli_type' => 252,
				'charsetnr'   => 63,
			),
			'bool'        => array(
				'native_type' => 'TINY',
				'pdo_type'    => PDO::PARAM_BOOL,
				'mysqli_type' => 1,
				'charsetnr'   => 63,
			),
			'boolean'     => array(
				'native_type' => 'TINY',
				'pdo_type'    => PDO::PARAM_BOOL,
				'mysqli_type' => 1,
				'charsetnr'   => 63,
			),
			'numeric'     => array(
				'native_type' => 'NEWDECIMAL',
				'pdo_type'    => PDO::PARAM_STR,
				'mysqli_type' => 246,
				'charsetnr'   => 63,
			),
			'decimal'     => array(
				'native_type' => 'NEWDECIMAL',
				'pdo_type'    => PDO::PARAM_STR,
				'mysqli_type' => 246,
				'charsetnr'   => 63,
			),
			'float4'      => array(
				'native_type' => 'FLOAT',
				'pdo_type'    => PDO::PARAM_STR,
				'mysqli_type' => 4,
				'charsetnr'   => 63,
			),
			'float8'      => array(
				'native_type' => 'DOUBLE',
				'pdo_type'    => PDO::PARAM_STR,
				'mysqli_type' => 5,
				'charsetnr'   => 63,
			),
			'date'        => array(
				'native_type' => 'DATE',
				'pdo_type'    => PDO::PARAM_STR,
				'mysqli_type' => 10,
				'charsetnr'   => 63,
			),
			'time'        => array(
				'native_type' => 'TIME',
				'pdo_type'    => PDO::PARAM_STR,
				'mysqli_type' => 11,
				'charsetnr'   => 63,
			),
			'timestamp'   => array(
				'native_type' => 'DATETIME',
				'pdo_type'    => PDO::PARAM_STR,
				'mysqli_type' => 12,
				'charsetnr'   => 63,
			),
			'timestamptz' => array(
				'native_type' => 'DATETIME',
				'pdo_type'    => PDO::PARAM_STR,
				'mysqli_type' => 12,
				'charsetnr'   => 63,
			),
			'datetime'    => array(
				'native_type' => 'DATETIME',
				'pdo_type'    => PDO::PARAM_STR,
				'mysqli_type' => 12,
				'charsetnr'   => 63,
			),
		);

		return isset( $map[ $native_type ] ) ? $map[ $native_type ] : $defaults;
	}
}
