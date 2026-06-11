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
	 * Sentinel prefix for MySQL text bytes PostgreSQL text cannot store directly.
	 */
	private const MYSQL_TEXT_SENTINEL = "\xEE\x80\x80";

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
	 * FOUND_ROWS() value for the last SQL_CALC_FOUND_ROWS query.
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

		$upsert_query = $this->translate_mysql_on_duplicate_key_update_query( $query );
		if ( null !== $upsert_query ) {
			$query                     = $upsert_query['sql'];
			$dml_identity_repair_query = $upsert_query;
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
		$sql_calc_found_rows_query    = $is_sql_calc_found_rows_query ? $query : null;

		$translated_query = $this->translate_information_schema_tables_site_health_query( $query );
		if ( null !== $translated_query ) {
			$query                     = $translated_query;
			$translated_for_postgresql = true;
		}

		$translated_query = $this->translate_strict_aggregate_grouped_order_by_query( $query );
		if ( null !== $translated_query ) {
			$query                     = $translated_query;
			$translated_for_postgresql = true;
		}

		$translated_query = $this->translate_grouped_having_alias_query( $query );
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
			$this->last_result      = $this->decode_postgresql_text_for_mysql_in_result(
				$stmt->fetchAll( $fetch_mode, ...$fetch_mode_args )
			);
			if ( null !== $sql_calc_found_rows_query ) {
				$this->last_found_rows = $this->execute_sql_calc_found_rows_count_query( $sql_calc_found_rows_query );
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
	 * Decode PostgreSQL-safe text sentinels in fetched result data.
	 *
	 * @param mixed $value Fetched result value.
	 * @return mixed MySQL-facing result value.
	 */
	private function decode_postgresql_text_for_mysql_in_result( $value ) {
		if ( is_string( $value ) ) {
			return self::decode_postgresql_text_for_mysql_value( $value );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->decode_postgresql_text_for_mysql_in_result( $item );
			}
			return $value;
		}

		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$value->$key = $this->decode_postgresql_text_for_mysql_in_result( $item );
			}
		}

		return $value;
	}

	/**
	 * Decode MySQL text bytes previously encoded for PostgreSQL storage.
	 *
	 * @param string $value PostgreSQL text value.
	 * @return string MySQL-facing text value.
	 */
	private static function decode_postgresql_text_for_mysql_value( string $value ): string {
		if ( false === strpos( $value, self::MYSQL_TEXT_SENTINEL ) ) {
			return $value;
		}

		return strtr(
			$value,
			array(
				self::MYSQL_TEXT_SENTINEL . self::MYSQL_TEXT_SENTINEL => self::MYSQL_TEXT_SENTINEL,
				self::MYSQL_TEXT_SENTINEL . '0' => "\0",
			)
		);
	}

	/**
	 * Execute the unbounded count query for a SQL_CALC_FOUND_ROWS SELECT.
	 *
	 * @param string $query MySQL query.
	 * @return int Total matching rows before LIMIT/OFFSET.
	 */
	private function execute_sql_calc_found_rows_count_query( string $query ): int {
		$count_query = $this->get_sql_calc_found_rows_count_query( $query );
		if ( null === $count_query ) {
			throw new PDOException( 'Unsupported SQL_CALC_FOUND_ROWS query shape for PostgreSQL FOUND_ROWS accounting.' );
		}

		$stmt                            = $this->connection->query( $count_query );
		$this->last_postgresql_queries[] = array(
			'sql'    => $count_query,
			'params' => array(),
		);

		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		if ( ! is_array( $row ) || ! array_key_exists( '__wp_pg_found_rows', $row ) ) {
			throw new PDOException( 'Failed to read PostgreSQL FOUND_ROWS accounting result.' );
		}

		return (int) $row['__wp_pg_found_rows'];
	}

	/**
	 * Build the PostgreSQL count query for a SQL_CALC_FOUND_ROWS SELECT.
	 *
	 * @param string $query MySQL query.
	 * @return string|null PostgreSQL count query, or null when unsupported.
	 */
	private function get_sql_calc_found_rows_count_query( string $query ): ?string {
		$select_query = $this->translate_sql_calc_found_rows_count_select_query( $query );
		if ( null === $select_query ) {
			return null;
		}

		$alias = $this->connection->quote_identifier( '__wp_pg_found_rows' );
		return sprintf(
			'SELECT COUNT(*) AS %1$s FROM (%2$s) AS %1$s',
			$alias,
			$select_query
		);
	}

	/**
	 * Translate the unbounded SELECT used for SQL_CALC_FOUND_ROWS accounting.
	 *
	 * @param string $query MySQL query.
	 * @return string|null PostgreSQL SELECT query, or null when unsupported.
	 */
	private function translate_sql_calc_found_rows_count_select_query( string $query ): ?string {
		$translated_query = $this->translate_strict_aggregate_grouped_order_by_query( $query, false );
		if ( null !== $translated_query ) {
			return $translated_query;
		}

		$translated_query = $this->translate_distinct_order_by_query( $query, false );
		if ( null !== $translated_query ) {
			return $translated_query;
		}

		return $this->translate_sql_calc_found_rows_select_query( $query, false );
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
	 * Translate supported INSERT ... ON DUPLICATE KEY UPDATE queries.
	 *
	 * WordPress emits MySQL upserts for a small set of VALUES inserts. Keep this
	 * path structured and metadata-backed: only explicit column-list VALUES
	 * inserts are supported, the conflict target must resolve to a known
	 * primary/unique key, and update assignments must use VALUES(column).
	 *
	 * @param string $query MySQL query.
	 * @return array|null PostgreSQL query data, or null when the query is unsupported.
	 */
	private function translate_mysql_on_duplicate_key_update_query( string $query ): ?array {
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
		$on_duplicate = $this->find_on_duplicate_key_update_clause( $tokens, $position );
		if ( null === $on_duplicate ) {
			return null;
		}

		$probe_safe_rows = array();
		$value_rows      = $this->parse_mysql_values_rows( $tokens, $position, $on_duplicate, count( $columns ), $probe_safe_rows );
		if ( null === $value_rows ) {
			return null;
		}

		$conflict_columns = $this->get_mysql_upsert_conflict_target_columns( $table_name, $columns );
		if ( null === $conflict_columns ) {
			return null;
		}

		$column_lookup = array();
		foreach ( $columns as $column ) {
			$column_lookup[ strtolower( $column ) ] = true;
		}

		$table_column_lookup = $this->get_mysql_dml_column_metadata_lookup( $table_name );
		$position            = $on_duplicate + 4;

		$assignments = $this->parse_upsert_update_assignments( $tokens, $position, $column_lookup, $table_column_lookup );
		if ( null === $assignments || ! $this->is_at_mysql_query_end( $tokens, $position ) ) {
			return null;
		}

		$inserted_value_rows = $this->get_mysql_upsert_inserted_value_rows(
			$table_name,
			$columns,
			$value_rows,
			$probe_safe_rows,
			$conflict_columns
		);
		if ( null === $inserted_value_rows ) {
			return null;
		}

		$sql_value_rows = array();
		foreach ( $value_rows as $values ) {
			$sql_value_rows[] = '(' . implode( ', ', $values ) . ')';
		}

		return array(
			'action'           => 'upsert',
			'sql'              => sprintf(
				'INSERT INTO %s (%s) %s ON CONFLICT (%s) DO UPDATE SET %s',
				$this->connection->quote_identifier( $table_name ),
				implode( ', ', array_map( array( $this->connection, 'quote_identifier' ), $columns ) ),
				'VALUES ' . implode( ', ', $sql_value_rows ),
				implode( ', ', array_map( array( $this->connection, 'quote_identifier' ), $conflict_columns ) ),
				implode( ', ', $assignments )
			),
			'table_name'       => $table_name,
			'columns'          => $columns,
			'values'           => $inserted_value_rows[0] ?? array(),
			'value_rows'       => $inserted_value_rows,
			'conflict_columns' => $conflict_columns,
			'inserted_new_row' => count( $inserted_value_rows ) > 0,
		);
	}

	/**
	 * Parse a bounded sequence of one or more MySQL VALUES rows.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position       Current token position, updated on success.
	 * @param int             $end            Final token position, exclusive.
	 * @param int             $expected_count Expected number of row values.
	 * @param array           $probe_safe_rows Updated with conflict-probe safety flags.
	 * @return array[]|null Translated PostgreSQL VALUES rows, or null when unsupported.
	 */
	private function parse_mysql_values_rows( array $tokens, int &$position, int $end, int $expected_count, array &$probe_safe_rows ): ?array {
		$rows            = array();
		$probe_safe_rows = array();

		while ( $position < $end ) {
			$probe_safe_values = array();
			$values            = $this->parse_mysql_value_list_with_probe_safety( $tokens, $position, $probe_safe_values );
			if ( null === $values || count( $values ) !== $expected_count ) {
				return null;
			}

			$rows[]            = $values;
			$probe_safe_rows[] = $probe_safe_values;

			if ( $position === $end ) {
				return $rows;
			}

			if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::COMMA_SYMBOL !== $tokens[ $position ]->id ) {
				return null;
			}

			++$position;
		}

		return count( $rows ) > 0 ? $rows : null;
	}

	/**
	 * Parse a parenthesized single-row MySQL VALUES list with probe safety.
	 *
	 * @param WP_MySQL_Token[] $tokens       MySQL lexer token stream.
	 * @param int             $position     Current token position, updated on success.
	 * @param bool[]          $probe_safety Updated with per-value conflict-probe safety.
	 * @return string[]|null Translated SQL values, or null when unsupported.
	 */
	private function parse_mysql_value_list_with_probe_safety( array $tokens, int &$position, array &$probe_safety ): ?array {
		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $position ]->id ) {
			return null;
		}

		++$position;
		$values       = array();
		$probe_safety = array();
		$value_start  = $position;
		$depth        = 0;

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

					$values[]       = $this->translate_mysql_token_sequence_to_postgresql( $tokens, $value_start, $position );
					$probe_safety[] = $this->is_supported_mysql_upsert_conflict_probe_token_sequence( $tokens, $value_start, $position );
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

				$values[]       = $this->translate_mysql_token_sequence_to_postgresql( $tokens, $value_start, $position );
				$probe_safety[] = $this->is_supported_mysql_upsert_conflict_probe_token_sequence( $tokens, $value_start, $position );
				$value_start    = $position + 1;
			}

			++$position;
		}

		return null;
	}

	/**
	 * Check whether a VALUES item is safe for a conflict preflight probe.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First value token position, inclusive.
	 * @param int             $end    Final value token position, exclusive.
	 * @return bool Whether the value is a deterministic literal.
	 */
	private function is_supported_mysql_upsert_conflict_probe_token_sequence( array $tokens, int $start, int $end ): bool {
		if ( $start + 1 !== $end || ! isset( $tokens[ $start ] ) ) {
			return false;
		}

		return in_array(
			$tokens[ $start ]->id,
			array(
				WP_MySQL_Lexer::BIN_NUMBER,
				WP_MySQL_Lexer::DECIMAL_NUMBER,
				WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT,
				WP_MySQL_Lexer::FALSE_SYMBOL,
				WP_MySQL_Lexer::FLOAT_NUMBER,
				WP_MySQL_Lexer::HEX_NUMBER,
				WP_MySQL_Lexer::INT_NUMBER,
				WP_MySQL_Lexer::LONG_NUMBER,
				WP_MySQL_Lexer::NULL_SYMBOL,
				WP_MySQL_Lexer::SINGLE_QUOTED_TEXT,
				WP_MySQL_Lexer::TRUE_SYMBOL,
				WP_MySQL_Lexer::ULONGLONG_NUMBER,
			),
			true
		);
	}

	/**
	 * Resolve the PostgreSQL upsert conflict target from MySQL index metadata.
	 *
	 * @param string   $table_name Table name.
	 * @param string[] $columns    Inserted column names.
	 * @return string[]|null Conflict target columns, or null when unsupported.
	 */
	private function get_mysql_upsert_conflict_target_columns( string $table_name, array $columns ): ?array {
		$insert_column_lookup = array();
		foreach ( $columns as $column ) {
			$insert_column_lookup[ strtolower( $column ) ] = true;
		}

		$this->ensure_mysql_schema_metadata_tables();

		$table_schema = $this->resolve_mysql_table_schema_for_introspection( 'public', $table_name );
		$stmt         = $this->connection->query(
			sprintf(
				'SELECT key_name, column_name, sub_part
				FROM %s
				WHERE table_schema = ? AND table_name = ? AND non_unique = \'0\'
				ORDER BY
					CASE WHEN UPPER(key_name) = \'PRIMARY\' THEN 0 ELSE 1 END,
					index_ordinal,
					seq_in_index',
				$this->connection->quote_identifier( self::MYSQL_INDEX_METADATA_TABLE )
			),
			array( $table_schema, $table_name )
		);

		$indexes = array();
		foreach ( $stmt->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
			$key_name = (string) ( $row['key_name'] ?? '' );
			if ( '' === $key_name ) {
				continue;
			}

			if ( ! isset( $indexes[ $key_name ] ) ) {
				$indexes[ $key_name ] = array(
					'columns'      => array(),
					'has_sub_part' => false,
				);
			}

			$column_name = (string) ( $row['column_name'] ?? '' );
			if ( '' === $column_name ) {
				continue;
			}

			$indexes[ $key_name ]['columns'][] = $column_name;
			if ( null !== ( $row['sub_part'] ?? null ) && '' !== (string) $row['sub_part'] ) {
				$indexes[ $key_name ]['has_sub_part'] = true;
			}
		}

		$candidates = array();
		foreach ( $indexes as $index ) {
			if ( empty( $index['columns'] ) ) {
				continue;
			}

			foreach ( $index['columns'] as $column ) {
				if ( ! isset( $insert_column_lookup[ strtolower( $column ) ] ) ) {
					continue 2;
				}
			}

			if ( $index['has_sub_part'] ) {
				return null;
			}

			$candidates[] = $index['columns'];
		}

		return 1 === count( $candidates ) ? $candidates[0] : null;
	}

	/**
	 * Get the VALUES rows that will insert rather than update on conflict.
	 *
	 * @param string   $table_name       Table name.
	 * @param string[] $columns          Inserted column names.
	 * @param array[]  $value_rows       Translated PostgreSQL VALUES rows.
	 * @param array[]  $probe_safe_rows  Per-value conflict-probe safety flags.
	 * @param string[] $conflict_columns Conflict target columns.
	 * @return array[]|null Inserted VALUES rows, or null when unsupported.
	 */
	private function get_mysql_upsert_inserted_value_rows( string $table_name, array $columns, array $value_rows, array $probe_safe_rows, array $conflict_columns ): ?array {
		$column_indexes = array();
		foreach ( $columns as $index => $column ) {
			$column_indexes[ strtolower( $column ) ] = $index;
		}

		$conflict_indexes = array();
		foreach ( $conflict_columns as $column ) {
			$column_key = strtolower( $column );
			if ( ! isset( $column_indexes[ $column_key ] ) ) {
				return null;
			}

			$conflict_indexes[] = array(
				'column' => $column,
				'index'  => $column_indexes[ $column_key ],
			);
		}

		$inserted_rows = array();
		foreach ( $value_rows as $row_index => $values ) {
			$probe_safety = $probe_safe_rows[ $row_index ] ?? array();
			foreach ( $conflict_indexes as $conflict_index ) {
				if ( ! isset( $probe_safety[ $conflict_index['index'] ] ) || ! $probe_safety[ $conflict_index['index'] ] ) {
					return null;
				}
			}

			$conflict_exists = $this->mysql_upsert_conflict_exists( $table_name, $values, $conflict_indexes );
			if ( null === $conflict_exists ) {
				return null;
			}

			if ( $conflict_exists ) {
				continue;
			}

			$inserted_rows[] = $values;
		}

		return $inserted_rows;
	}

	/**
	 * Check whether a VALUES row conflicts with the selected upsert target.
	 *
	 * @param string $table_name       Table name.
	 * @param array  $values           Translated PostgreSQL VALUES row.
	 * @param array  $conflict_indexes Conflict target column/index tuples.
	 * @return bool|null Whether the row currently conflicts, or null when unsupported.
	 */
	private function mysql_upsert_conflict_exists( string $table_name, array $values, array $conflict_indexes ): ?bool {
		$where = array();
		foreach ( $conflict_indexes as $conflict_index ) {
			if ( ! array_key_exists( $conflict_index['index'], $values ) ) {
				return null;
			}

			$value = (string) $values[ $conflict_index['index'] ];
			if ( 'NULL' === strtoupper( trim( $value ) ) ) {
				return false;
			}

			$where[] = sprintf(
				'%s = %s',
				$this->connection->quote_identifier( (string) $conflict_index['column'] ),
				$value
			);
		}

		$stmt = $this->connection->query(
			sprintf(
				'SELECT 1 FROM %s WHERE %s LIMIT 1',
				$this->connection->quote_identifier( $table_name ),
				implode( ' AND ', $where )
			)
		);

		return false !== $stmt->fetchColumn();
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

		$this->append_non_strict_dml_defaults_for_omitted_columns( $table_name, $columns, $values );

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

		$this->append_non_strict_dml_defaults_for_omitted_columns( $table_name, $columns, $values );

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
			! isset( $dml_query['table_name'], $dml_query['columns'] )
			|| ! is_array( $dml_query['columns'] )
		) {
			return;
		}

		if ( isset( $dml_query['value_rows'] ) && is_array( $dml_query['value_rows'] ) ) {
			$explicit_identity_columns = $this->get_explicit_dml_identity_column_lookup_from_rows(
				$dml_query['columns'],
				$dml_query['value_rows']
			);
		} elseif ( isset( $dml_query['values'] ) && is_array( $dml_query['values'] ) ) {
			$explicit_identity_columns = $this->get_explicit_dml_identity_column_lookup(
				$dml_query['columns'],
				$dml_query['values']
			);
		} else {
			return;
		}

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
	 * Get explicitly supplied non-default DML identity columns from VALUES rows.
	 *
	 * @param string[] $columns    DML column names.
	 * @param array[]  $value_rows Translated DML value rows.
	 * @return array<string, bool> Lowercase column lookup.
	 */
	private function get_explicit_dml_identity_column_lookup_from_rows( array $columns, array $value_rows ): array {
		$explicit_columns = array();

		foreach ( $value_rows as $values ) {
			if ( ! is_array( $values ) ) {
				continue;
			}

			foreach ( $this->get_explicit_dml_identity_column_lookup( $columns, $values ) as $column => $explicit ) {
				$explicit_columns[ $column ] = $explicit;
			}
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

		$set_sql = $this->translate_simple_mysql_update_set_clause( $table_name, $tokens, $position, $set_end );
		if ( null === $set_sql ) {
			return null;
		}

		$sql = sprintf(
			'UPDATE %s SET %s',
			$this->connection->quote_identifier( $table_name ),
			$set_sql
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
	 * Append metadata-derived defaults for omitted NOT NULL columns in non-strict DML.
	 *
	 * @param string   $table_name Table name.
	 * @param string[] $columns    DML columns, mutated when defaults are appended.
	 * @param string[] $values     DML values, mutated when defaults are appended.
	 */
	private function append_non_strict_dml_defaults_for_omitted_columns( string $table_name, array &$columns, array &$values ): void {
		if ( $this->is_mysql_strict_sql_mode_active() ) {
			return;
		}

		$supplied_columns = array();
		foreach ( $columns as $column ) {
			$supplied_columns[ strtolower( (string) $column ) ] = true;
		}

		foreach ( $this->get_mysql_dml_column_metadata( $table_name ) as $column_metadata ) {
			$column_name = (string) ( $column_metadata['column_name'] ?? '' );
			if ( '' === $column_name || isset( $supplied_columns[ strtolower( $column_name ) ] ) ) {
				continue;
			}

			$default_sql = $this->get_non_strict_dml_default_sql_for_column( $column_metadata );
			if ( null === $default_sql ) {
				continue;
			}

			$columns[] = $column_name;
			$values[]  = $default_sql;

			$supplied_columns[ strtolower( $column_name ) ] = true;
		}
	}

	/**
	 * Translate a supported simple UPDATE SET clause with non-strict NULL coercion.
	 *
	 * @param string           $table_name Table name.
	 * @param WP_MySQL_Token[] $tokens     MySQL lexer token stream.
	 * @param int              $start      First SET-clause token position.
	 * @param int              $end        Final SET-clause token position, exclusive.
	 * @return string|null PostgreSQL SET SQL, or null when unsupported.
	 */
	private function translate_simple_mysql_update_set_clause( string $table_name, array $tokens, int $start, int $end ): ?string {
		$column_metadata = $this->is_mysql_strict_sql_mode_active()
			? array()
			: $this->get_mysql_dml_column_metadata_lookup( $table_name );
		$assignments     = array();

		for ( $position = $start; $position < $end; ) {
			$target_column = $this->get_mysql_identifier_token_value( $tokens[ $position ] ?? null );
			if ( null === $target_column ) {
				return null;
			}

			if ( ! isset( $tokens[ $position + 1 ] ) || WP_MySQL_Lexer::EQUAL_OPERATOR !== $tokens[ $position + 1 ]->id ) {
				return null;
			}

			$value_start    = $position + 2;
			$assignment_end = $this->find_top_level_mysql_token(
				$tokens,
				WP_MySQL_Lexer::COMMA_SYMBOL,
				$value_start,
				$end
			) ?? $end;

			if ( $value_start >= $assignment_end ) {
				return null;
			}

			$value_sql           = $this->translate_mysql_token_sequence_to_postgresql( $tokens, $value_start, $assignment_end );
			$target_column_key   = strtolower( $target_column );
			$target_metadata     = $column_metadata[ $target_column_key ] ?? null;
			$coerced_default_sql = null;

			if ( null !== $target_metadata && $this->is_mysql_null_token_sequence( $tokens, $value_start, $assignment_end ) ) {
				$coerced_default_sql = $this->get_non_strict_dml_default_sql_for_column( $target_metadata );
			}

			$assignments[] = sprintf(
				'%s = %s',
				$this->connection->quote_identifier( $target_column ),
				null === $coerced_default_sql ? $value_sql : $coerced_default_sql
			);

			$position = $assignment_end;
			if ( $position === $end ) {
				break;
			}

			++$position;
		}

		return count( $assignments ) > 0 ? implode( ', ', $assignments ) : null;
	}

	/**
	 * Get DML column metadata keyed by lowercase column name.
	 *
	 * @param string $table_name Table name.
	 * @return array<string, array> Column metadata lookup.
	 */
	private function get_mysql_dml_column_metadata_lookup( string $table_name ): array {
		$lookup = array();
		foreach ( $this->get_mysql_dml_column_metadata( $table_name ) as $column_metadata ) {
			$column_name = (string) ( $column_metadata['column_name'] ?? '' );
			if ( '' !== $column_name ) {
				$lookup[ strtolower( $column_name ) ] = $column_metadata;
			}
		}

		return $lookup;
	}

	/**
	 * Get ordered MySQL column metadata for a DML target table.
	 *
	 * @param string $table_name Table name.
	 * @return array[] Column metadata rows.
	 */
	private function get_mysql_dml_column_metadata( string $table_name ): array {
		$this->ensure_mysql_schema_metadata_tables();

		$table_schema = $this->resolve_mysql_table_schema_for_introspection( 'public', $table_name );
		$stmt         = $this->connection->query(
			sprintf(
				'SELECT column_name, ordinal_position, column_type, is_nullable, column_default, extra
				FROM %s
				WHERE table_schema = ? AND table_name = ?
				ORDER BY ordinal_position',
				$this->connection->quote_identifier( self::MYSQL_COLUMN_METADATA_TABLE )
			),
			array( $table_schema, $table_name )
		);

		return $stmt->fetchAll( PDO::FETCH_ASSOC );
	}

	/**
	 * Get the default SQL expression for a non-strict NOT NULL DML column.
	 *
	 * @param array $column_metadata Column metadata row.
	 * @return string|null Default SQL, or null when the column should not be coerced.
	 */
	private function get_non_strict_dml_default_sql_for_column( array $column_metadata ): ?string {
		if ( 'NO' !== strtoupper( (string) ( $column_metadata['is_nullable'] ?? '' ) ) ) {
			return null;
		}

		if ( $this->is_mysql_auto_increment_column_metadata( $column_metadata ) ) {
			return null;
		}

		if ( null !== ( $column_metadata['column_default'] ?? null ) ) {
			return $this->connection->quote( (string) $column_metadata['column_default'] );
		}

		return $this->get_mysql_implicit_dml_default_sql( (string) ( $column_metadata['column_type'] ?? '' ) );
	}

	/**
	 * Check whether column metadata describes a MySQL AUTO_INCREMENT column.
	 *
	 * @param array $column_metadata Column metadata row.
	 * @return bool Whether the column is AUTO_INCREMENT.
	 */
	private function is_mysql_auto_increment_column_metadata( array $column_metadata ): bool {
		return 'auto_increment' === strtolower( (string) ( $column_metadata['extra'] ?? '' ) );
	}

	/**
	 * Get a MySQL-compatible implicit default for a column type.
	 *
	 * @param string $column_type MySQL column type metadata.
	 * @return string|null SQL default expression, or null for unsupported type metadata.
	 */
	private function get_mysql_implicit_dml_default_sql( string $column_type ): ?string {
		$base_type = $this->get_base_mysql_dml_column_type( $column_type );

		if (
			in_array(
				$base_type,
				array(
					'char',
					'varchar',
					'binary',
					'varbinary',
					'tinyblob',
					'blob',
					'mediumblob',
					'longblob',
					'tinytext',
					'text',
					'mediumtext',
					'longtext',
					'enum',
					'set',
				),
				true
			)
		) {
			return $this->connection->quote( '' );
		}

		if (
			in_array(
				$base_type,
				array(
					'bit',
					'tinyint',
					'smallint',
					'mediumint',
					'int',
					'integer',
					'bigint',
					'decimal',
					'numeric',
					'float',
					'double',
					'real',
				),
				true
			)
		) {
			return '0';
		}

		if ( 'date' === $base_type ) {
			return $this->connection->quote( '0000-00-00' );
		}

		if ( 'datetime' === $base_type || 'timestamp' === $base_type ) {
			return $this->connection->quote( '0000-00-00 00:00:00' );
		}

		if ( 'time' === $base_type ) {
			return $this->connection->quote( '00:00:00' );
		}

		if ( 'year' === $base_type ) {
			return $this->connection->quote( '0000' );
		}

		return null;
	}

	/**
	 * Get the base MySQL column type from metadata.
	 *
	 * @param string $column_type MySQL column type metadata.
	 * @return string Base type.
	 */
	private function get_base_mysql_dml_column_type( string $column_type ): string {
		$column_type = strtolower( trim( $column_type ) );
		$type_end    = strlen( $column_type );

		$length_position = strpos( $column_type, '(' );
		if ( false !== $length_position ) {
			$type_end = min( $type_end, $length_position );
		}

		$space_position = strpos( $column_type, ' ' );
		if ( false !== $space_position ) {
			$type_end = min( $type_end, $space_position );
		}

		return substr( $column_type, 0, $type_end );
	}

	/**
	 * Check whether a token sequence is exactly the NULL literal.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int              $start  First token position.
	 * @param int              $end    Final token position, exclusive.
	 * @return bool Whether the token sequence is NULL.
	 */
	private function is_mysql_null_token_sequence( array $tokens, int $start, int $end ): bool {
		return $start + 1 === $end
			&& isset( $tokens[ $start ] )
			&& WP_MySQL_Lexer::NULL_SYMBOL === $tokens[ $start ]->id;
	}

	/**
	 * Check whether the emulated MySQL session is using a strict SQL mode.
	 *
	 * @return bool Whether strict DML behavior should be preserved.
	 */
	private function is_mysql_strict_sql_mode_active(): bool {
		foreach ( explode( ',', $this->sql_mode ) as $mode ) {
			$mode = strtoupper( trim( $mode ) );
			if ( 'STRICT_TRANS_TABLES' === $mode || 'STRICT_ALL_TABLES' === $mode ) {
				return true;
			}
		}

		return false;
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

		$existing_table_names = $this->get_information_schema_tables_site_health_existing_table_names( $where_clause['table_names'] );

		return sprintf(
			'SELECT %s FROM (%s) AS %s WHERE %s GROUP BY %s',
			implode( ', ', $projection_sql ),
			$this->get_information_schema_tables_site_health_relation_sql( $existing_table_names ),
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
	 * Get requested Site Health table names that exist in the PostgreSQL catalog.
	 *
	 * @param string[] $table_names Table names from the validated TABLE_NAME predicate.
	 * @return string[] Existing table names in requested order.
	 */
	private function get_information_schema_tables_site_health_existing_table_names( array $table_names ): array {
		if ( empty( $table_names ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $table_names ), '?' ) );
		$stmt         = $this->connection->query(
			sprintf(
				'SELECT %1$s FROM %2$s WHERE %3$s = ? AND %4$s IN (?, ?) AND %1$s NOT IN (?, ?, ?) AND %1$s IN (%5$s)',
				$this->connection->quote_identifier( 'table_name' ),
				$this->get_postgresql_qualified_identifier( 'information_schema', 'tables' ),
				$this->connection->quote_identifier( 'table_schema' ),
				$this->connection->quote_identifier( 'table_type' ),
				$placeholders
			),
			array_merge(
				array(
					'public',
					'BASE TABLE',
					'VIEW',
					self::MYSQL_COLUMN_METADATA_TABLE,
					self::MYSQL_INDEX_METADATA_TABLE,
					self::MYSQL_CHARSET_METADATA_TABLE,
				),
				$table_names
			)
		);

		$existing_table_names = array();
		foreach ( $stmt->fetchAll( PDO::FETCH_COLUMN, 0 ) as $table_name ) {
			$existing_table_names[ (string) $table_name ] = true;
		}

		return array_values(
			array_filter(
				$table_names,
				static function ( string $table_name ) use ( $existing_table_names ): bool {
					return isset( $existing_table_names[ $table_name ] );
				}
			)
		);
	}

	/**
	 * Build the derived relation that emulates MySQL information_schema.TABLES columns.
	 *
	 * @param string[] $existing_table_names Table names validated against information_schema.tables.
	 * @return string PostgreSQL relation SQL.
	 */
	private function get_information_schema_tables_site_health_relation_sql( array $existing_table_names ): string {
		return sprintf(
			'SELECT %1$s AS %1$s, %2$s AS %3$s, %4$s, 0 AS %5$s, 0 AS %6$s FROM %7$s WHERE %8$s = %9$s AND %10$s IN (%11$s, %12$s) AND %1$s NOT IN (%13$s, %14$s, %15$s)',
			$this->connection->quote_identifier( 'table_name' ),
			$this->connection->quote( $this->db_name ),
			$this->connection->quote_identifier( 'TABLE_SCHEMA' ),
			$this->get_information_schema_tables_site_health_table_rows_sql( $existing_table_names ),
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
	 * Build a Site Health TABLE_ROWS expression for existing catalog tables.
	 *
	 * @param string[] $existing_table_names Table names validated against information_schema.tables.
	 * @return string PostgreSQL row-count expression SQL.
	 */
	private function get_information_schema_tables_site_health_table_rows_sql( array $existing_table_names ): string {
		if ( empty( $existing_table_names ) ) {
			return sprintf(
				'0 AS %s',
				$this->connection->quote_identifier( 'TABLE_ROWS' )
			);
		}

		$cases = array();
		foreach ( $existing_table_names as $table_name ) {
			$cases[] = sprintf(
				'WHEN %s THEN (SELECT COUNT(*) FROM %s)',
				$this->connection->quote( $table_name ),
				$this->get_postgresql_qualified_identifier( 'public', $table_name )
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
	 * @param string $query         MySQL query.
	 * @param bool   $include_limit Whether to preserve the LIMIT/OFFSET clause.
	 * @return string|null PostgreSQL query, or null when the query is unsupported.
	 */
	private function translate_distinct_order_by_query( string $query, bool $include_limit = true ): ?string {
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
			if ( ! $has_sql_calc_found_rows ) {
				return null;
			}

			$sql = 'SELECT DISTINCT ' . $this->translate_mysql_token_sequence_to_postgresql( $tokens, $projection_start, $select_end );
			if ( $include_limit && null !== $limit_position ) {
				$sql .= $this->translate_simple_select_limit_clause_to_postgresql( $tokens, $limit_position, $statement_end );
			}

			return $sql;
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

		$scope_end = $this->find_top_level_mysql_token(
			$tokens,
			WP_MySQL_Lexer::WHERE_SYMBOL,
			$from_position + 1,
			$order_position
		) ?? $order_position;
		$scope     = $this->get_mysql_select_scope( $tokens, $from_position + 1, $scope_end );
		if ( null === $scope ) {
			return null;
		}

		$order_items = $this->parse_mysql_select_order_by_items(
			$tokens,
			$order_position + 2,
			$select_end,
			$projection_items,
			$scope
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
			if ( ! $has_sql_calc_found_rows ) {
				return null;
			}

			$sql = 'SELECT DISTINCT ' . $this->translate_mysql_token_sequence_to_postgresql( $tokens, $projection_start, $select_end );
			if ( $include_limit && null !== $limit_position ) {
				$sql .= $this->translate_simple_select_limit_clause_to_postgresql( $tokens, $limit_position, $statement_end );
			}

			return $sql;
		}

		return $this->build_distinct_order_by_grouped_query(
			$tokens,
			$projection_items,
			$order_items,
			$from_position,
			$order_position,
			$limit_position,
			$statement_end,
			$include_limit
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
	 * @param array|null      $scope            Optional statement table scope for contextual expression coercions.
	 * @return array<int, array{expression_start: int, expression_end: int, sql: string, direction: string, direction_explicit: bool, projection_index: int|null, changed: bool}>|null
	 * ORDER BY items.
	 */
	private function parse_mysql_select_order_by_items(
		array $tokens,
		int $start,
		int $end,
		array $projection_items,
		?array $scope = null
	): ?array {
		$ranges = $this->split_top_level_mysql_arguments( $tokens, $start, $end );
		if ( null === $ranges || count( $ranges ) < 1 ) {
			return null;
		}

		$items = array();
		foreach ( $ranges as $range ) {
			$expression_end     = $range['end'];
			$direction          = 'ASC';
			$direction_explicit = false;

			if (
				isset( $tokens[ $expression_end - 1 ] )
				&& (
					WP_MySQL_Lexer::ASC_SYMBOL === $tokens[ $expression_end - 1 ]->id
					|| WP_MySQL_Lexer::DESC_SYMBOL === $tokens[ $expression_end - 1 ]->id
				)
			) {
				$direction          = WP_MySQL_Lexer::DESC_SYMBOL === $tokens[ $expression_end - 1 ]->id ? 'DESC' : 'ASC';
				$direction_explicit = true;
				--$expression_end;
			}

			if ( $range['start'] >= $expression_end ) {
				return null;
			}

			$expression_sql = null === $scope
				? array(
					'sql'     => $this->translate_mysql_token_sequence_to_postgresql(
						$tokens,
						$range['start'],
						$expression_end
					),
					'changed' => false,
				)
				: $this->translate_mysql_expression_token_sequence_to_postgresql(
					$tokens,
					$range['start'],
					$expression_end,
					$scope
				);

			$items[] = array(
				'expression_start'   => $range['start'],
				'expression_end'     => $expression_end,
				'sql'                => $expression_sql['sql'],
				'direction'          => $direction,
				'direction_explicit' => $direction_explicit,
				'projection_index'   => $this->find_mysql_projection_for_order_expression(
					$tokens,
					$range['start'],
					$expression_end,
					$projection_items
				),
				'changed'            => $expression_sql['changed'],
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
	 * @param bool            $include_limit    Whether to preserve the LIMIT/OFFSET clause.
	 * @return string PostgreSQL query.
	 */
	private function build_distinct_order_by_grouped_query(
		array $tokens,
		array $projection_items,
		array $order_items,
		int $from_position,
		int $order_position,
		?int $limit_position,
		int $statement_end,
		bool $include_limit = true
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

		if ( $include_limit && null !== $limit_position ) {
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
	 * Translate aggregate/grouped SELECT ORDER BY clauses that PostgreSQL rejects.
	 *
	 * MySQL permits non-grouped ORDER BY expressions in grouped queries. Keep
	 * this rewrite limited to WordPress's scalar count and grouped archive/comment
	 * ID query shapes so unsupported grouping semantics still fail visibly.
	 *
	 * @param string $query         MySQL query.
	 * @param bool   $include_limit Whether to preserve the LIMIT/OFFSET clause.
	 * @return string|null PostgreSQL query, or null when the query is unsupported.
	 */
	private function translate_strict_aggregate_grouped_order_by_query( string $query, bool $include_limit = true ): ?string {
		$tokens = $this->get_mysql_tokens( $query );
		if ( ! isset( $tokens[0] ) || WP_MySQL_Lexer::SELECT_SYMBOL !== $tokens[0]->id ) {
			return null;
		}

		$statement_end = $this->get_mysql_statement_end_position( $tokens, 1 );
		if ( null === $statement_end ) {
			return null;
		}

		$projection_start = 1;
		$has_distinct     = false;
		if ( isset( $tokens[ $projection_start ] ) && WP_MySQL_Lexer::DISTINCT_SYMBOL === $tokens[ $projection_start ]->id ) {
			$has_distinct = true;
			++$projection_start;
		}

		if ( isset( $tokens[ $projection_start ] ) && WP_MySQL_Lexer::SQL_CALC_FOUND_ROWS_SYMBOL === $tokens[ $projection_start ]->id ) {
			++$projection_start;
		}

		$limit_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::LIMIT_SYMBOL, $projection_start, $statement_end );
		$select_end     = $limit_position ?? $statement_end;
		if ( null !== $limit_position && ! $this->is_supported_simple_select_limit_clause( $tokens, $limit_position, $statement_end ) ) {
			return null;
		}

		$order_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::ORDER_SYMBOL, $projection_start, $select_end );
		if (
			null === $order_position
			|| ! isset( $tokens[ $order_position + 1 ] )
			|| WP_MySQL_Lexer::BY_SYMBOL !== $tokens[ $order_position + 1 ]->id
			|| $order_position + 2 >= $select_end
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

		$group_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::GROUP_SYMBOL, $projection_start, $order_position );
		if ( null === $group_position ) {
			return $this->translate_strict_aggregate_only_order_by_query(
				$tokens,
				$projection_start,
				$order_position,
				$limit_position,
				$statement_end,
				$include_limit
			);
		}

		if (
			! isset( $tokens[ $group_position + 1 ] )
			|| WP_MySQL_Lexer::BY_SYMBOL !== $tokens[ $group_position + 1 ]->id
			|| $group_position + 2 >= $order_position
		) {
			return null;
		}

		return $this->translate_strict_grouped_order_by_query(
			$tokens,
			$projection_start,
			$group_position,
			$order_position,
			$limit_position,
			$statement_end,
			$has_distinct,
			$include_limit
		);
	}

	/**
	 * Drop ORDER BY from scalar COUNT-only aggregate queries.
	 *
	 * @param WP_MySQL_Token[] $tokens         MySQL lexer token stream.
	 * @param int             $projection_start First projection token position.
	 * @param int             $order_position ORDER token position.
	 * @param int|null        $limit_position LIMIT token position, or null.
	 * @param int             $statement_end  Final statement token position, exclusive.
	 * @param bool            $include_limit  Whether to preserve the LIMIT/OFFSET clause.
	 * @return string|null PostgreSQL query, or null when unsupported.
	 */
	private function translate_strict_aggregate_only_order_by_query(
		array $tokens,
		int $projection_start,
		int $order_position,
		?int $limit_position,
		int $statement_end,
		bool $include_limit = true
	): ?string {
		$from_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::FROM_SYMBOL, $projection_start, $order_position );
		if ( null === $from_position || $projection_start === $from_position ) {
			return null;
		}

		if ( ! $this->is_mysql_count_only_projection( $tokens, $projection_start, $from_position ) ) {
			return null;
		}

		$sql = 'SELECT ' . $this->translate_mysql_token_sequence_to_postgresql( $tokens, $projection_start, $order_position );
		if ( $include_limit && null !== $limit_position ) {
			$sql .= $this->translate_simple_select_limit_clause_to_postgresql( $tokens, $limit_position, $statement_end );
		}

		return $sql;
	}

	/**
	 * Translate targeted grouped ORDER BY expressions to aggregate-safe forms.
	 *
	 * @param WP_MySQL_Token[] $tokens         MySQL lexer token stream.
	 * @param int             $projection_start First projection token position.
	 * @param int             $group_position GROUP token position.
	 * @param int             $order_position ORDER token position.
	 * @param int|null        $limit_position LIMIT token position, or null.
	 * @param int             $statement_end  Final statement token position, exclusive.
	 * @param bool            $has_distinct   Whether the original SELECT used DISTINCT.
	 * @param bool            $include_limit  Whether to preserve the LIMIT/OFFSET clause.
	 * @return string|null PostgreSQL query, or null when unsupported.
	 */
	private function translate_strict_grouped_order_by_query(
		array $tokens,
		int $projection_start,
		int $group_position,
		int $order_position,
		?int $limit_position,
		int $statement_end,
		bool $has_distinct,
		bool $include_limit = true
	): ?string {
		$from_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::FROM_SYMBOL, $projection_start, $group_position );
		if ( null === $from_position || $projection_start === $from_position ) {
			return null;
		}

		$projection_items = $this->parse_mysql_select_projection_items( $tokens, $projection_start, $from_position );
		if ( null === $projection_items ) {
			return null;
		}

		$group_items = $this->split_top_level_mysql_arguments( $tokens, $group_position + 2, $order_position );
		if ( null === $group_items || count( $group_items ) < 1 ) {
			return null;
		}

		$scope_end = $this->find_top_level_mysql_token(
			$tokens,
			WP_MySQL_Lexer::WHERE_SYMBOL,
			$from_position + 1,
			$group_position
		) ?? $group_position;
		$scope     = $this->get_mysql_select_scope( $tokens, $from_position + 1, $scope_end );
		if ( null === $scope ) {
			return null;
		}

		$select_end  = $limit_position ?? $statement_end;
		$order_items = $this->parse_mysql_select_order_by_items(
			$tokens,
			$order_position + 2,
			$select_end,
			$projection_items,
			$scope
		);
		if ( null === $order_items ) {
			return null;
		}

		$archive_date_expression = $this->get_mysql_archive_grouped_date_expression_bounds( $tokens, $group_items );
		$is_comment_id_group     = $this->is_mysql_comment_id_grouped_select_shape( $tokens, $projection_items, $group_items );
		$is_post_id_group        = $this->is_mysql_post_id_grouped_select_shape( $tokens, $projection_items, $group_items );

		if (
			$has_distinct
			&& (
				null === $archive_date_expression
				|| ! $this->is_mysql_redundant_distinct_week_archive_select_shape(
					$tokens,
					$projection_items,
					$group_items,
					$archive_date_expression
				)
			)
		) {
			return $this->translate_distinct_strict_grouped_order_by_query(
				$tokens,
				$projection_start,
				$projection_items,
				$group_items,
				$order_items,
				$from_position,
				$group_position,
				$order_position,
				$limit_position,
				$statement_end,
				$include_limit
			);
		}

		if ( null === $archive_date_expression && ! $is_comment_id_group && ! $is_post_id_group ) {
			return null;
		}

		$order_sql = array();
		$rewritten = false;
		foreach ( $order_items as $order_item ) {
			if (
				null !== $order_item['projection_index']
				|| $this->is_mysql_grouped_order_expression( $tokens, $order_item, $group_items )
			) {
				$order_sql[] = $order_item['sql'] . ' ' . $order_item['direction'];
				continue;
			}

			if (
				null !== $archive_date_expression
				&& $this->is_mysql_archive_post_date_order_expression( $tokens, $order_item, $archive_date_expression )
			) {
				$order_sql[] = $this->get_strict_grouped_aggregate_order_sql( $order_item );
				$rewritten   = true;
				continue;
			}

			if (
				(
					$is_comment_id_group
					&& $this->is_mysql_comment_id_grouped_order_expression( $tokens, $order_item )
				)
				|| (
					$is_post_id_group
					&& $this->is_mysql_post_id_grouped_order_expression( $tokens, $order_item )
				)
			) {
				$order_sql[] = $this->get_strict_grouped_aggregate_order_sql( $order_item );
				$rewritten   = true;
				continue;
			}

			return null;
		}

		if ( ! $rewritten ) {
			return null;
		}

		$replacements   = array();
		$where_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::WHERE_SYMBOL, $projection_start, $group_position );
		if ( null !== $archive_date_expression ) {
			$replacements = array_merge(
				$replacements,
				$this->get_mysql_archive_grouped_projection_replacements(
					$tokens,
					$projection_items,
					$archive_date_expression
				)
			);
		}

		if ( null !== $where_position ) {
			$where_sql = $this->translate_mysql_predicate_token_sequence_to_postgresql(
				$tokens,
				$where_position + 1,
				$group_position,
				$scope
			);
			if ( $where_sql['changed'] ) {
				$replacements[] = array(
					'start' => $where_position + 1,
					'end'   => $group_position,
					'sql'   => $where_sql['sql'],
				);
			}
		}

		$sql = 'SELECT ' . $this->translate_mysql_token_sequence_with_replacements_to_postgresql(
			$tokens,
			$projection_start,
			$order_position,
			$replacements
		)
			. ' ORDER BY ' . implode( ', ', $order_sql );
		if ( $include_limit && null !== $limit_position ) {
			$sql .= $this->translate_simple_select_limit_clause_to_postgresql( $tokens, $limit_position, $statement_end );
		}

		return $sql;
	}

	/**
	 * Translate DISTINCT grouped queries that need hidden ORDER BY projections.
	 *
	 * @param WP_MySQL_Token[] $tokens           MySQL lexer token stream.
	 * @param int             $projection_start First projection token position.
	 * @param array           $projection_items Parsed projection items.
	 * @param array           $group_items      Parsed GROUP BY item ranges.
	 * @param array           $order_items      Parsed ORDER BY items.
	 * @param int             $from_position    FROM token position.
	 * @param int             $group_position   GROUP token position.
	 * @param int             $order_position   ORDER token position.
	 * @param int|null        $limit_position   LIMIT token position, or null.
	 * @param int             $statement_end    Final statement token position, exclusive.
	 * @param bool            $include_limit    Whether to preserve the LIMIT/OFFSET clause.
	 * @return string|null PostgreSQL query, or null when unsupported.
	 */
	private function translate_distinct_strict_grouped_order_by_query(
		array $tokens,
		int $projection_start,
		array $projection_items,
		array $group_items,
		array $order_items,
		int $from_position,
		int $group_position,
		int $order_position,
		?int $limit_position,
		int $statement_end,
		bool $include_limit = true
	): ?string {
		$select_end = $limit_position ?? $statement_end;
		if (
			$this->contains_mysql_token(
				$tokens,
				$projection_start,
				$select_end,
				array(
					WP_MySQL_Lexer::SELECT_SYMBOL,
				)
			)
		) {
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
			return null;
		}

		if (
			! $this->contains_mysql_aggregate_call( $tokens, $projection_start, $select_end )
			&& $this->is_mysql_distinct_grouped_projection_shape( $tokens, $projection_items, $group_items )
		) {
			return $this->build_distinct_strict_grouped_order_by_query(
				$tokens,
				$projection_items,
				$this->get_mysql_group_by_item_sql( $tokens, $group_items ),
				$order_items,
				$from_position,
				$group_position,
				$order_position,
				$limit_position,
				$statement_end,
				$include_limit
			);
		}

		$group_by_sql = $this->get_mysql_distinct_term_taxonomy_group_by_sql(
			$tokens,
			$projection_items,
			$group_items,
			$order_items,
			$from_position,
			$group_position
		);
		if ( null === $group_by_sql ) {
			return null;
		}

		return $this->build_distinct_strict_grouped_order_by_query(
			$tokens,
			$projection_items,
			$group_by_sql,
			$order_items,
			$from_position,
			$group_position,
			$order_position,
			$limit_position,
			$statement_end,
			$include_limit
		);
	}

	/**
	 * Translate parsed GROUP BY items to PostgreSQL SQL.
	 *
	 * @param WP_MySQL_Token[] $tokens      MySQL lexer token stream.
	 * @param array           $group_items Parsed GROUP BY item ranges.
	 * @return string[] PostgreSQL GROUP BY expressions.
	 */
	private function get_mysql_group_by_item_sql( array $tokens, array $group_items ): array {
		$group_by_sql = array();
		foreach ( $group_items as $group_item ) {
			$group_by_sql[] = $this->translate_mysql_token_sequence_to_postgresql(
				$tokens,
				$group_item['start'],
				$group_item['end']
			);
		}

		return $group_by_sql;
	}

	/**
	 * Check whether DISTINCT projection expressions exactly match GROUP BY.
	 *
	 * @param WP_MySQL_Token[] $tokens           MySQL lexer token stream.
	 * @param array           $projection_items Parsed projection items.
	 * @param array           $group_items      Parsed GROUP BY item ranges.
	 * @return bool Whether grouping already preserves DISTINCT cardinality.
	 */
	private function is_mysql_distinct_grouped_projection_shape( array $tokens, array $projection_items, array $group_items ): bool {
		if ( count( $projection_items ) !== count( $group_items ) ) {
			return false;
		}

		$matched_group_items = array();
		foreach ( $projection_items as $projection_item ) {
			$matched = false;
			foreach ( $group_items as $group_index => $group_item ) {
				if ( isset( $matched_group_items[ $group_index ] ) ) {
					continue;
				}

				if (
					$this->are_mysql_token_ranges_equivalent(
						$tokens,
						$projection_item['expression_start'],
						$projection_item['expression_end'],
						$group_item['start'],
						$group_item['end']
					)
				) {
					$matched_group_items[ $group_index ] = true;
					$matched                             = true;
					break;
				}
			}

			if ( ! $matched ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get GROUP BY expressions for the supported single-taxonomy term query.
	 *
	 * @param WP_MySQL_Token[] $tokens           MySQL lexer token stream.
	 * @param array           $projection_items Parsed projection items.
	 * @param array           $group_items      Parsed GROUP BY item ranges.
	 * @param array           $order_items      Parsed ORDER BY items.
	 * @param int             $from_position    FROM token position.
	 * @param int             $group_position   GROUP token position.
	 * @return string[]|null PostgreSQL GROUP BY expressions, or null when unsupported.
	 */
	private function get_mysql_distinct_term_taxonomy_group_by_sql(
		array $tokens,
		array $projection_items,
		array $group_items,
		array $order_items,
		int $from_position,
		int $group_position
	): ?array {
		if (
			! $this->is_mysql_distinct_term_taxonomy_projection_shape( $tokens, $projection_items )
			|| ! $this->is_mysql_distinct_term_taxonomy_group_shape( $tokens, $group_items )
			|| ! $this->is_mysql_distinct_term_taxonomy_order_shape( $tokens, $order_items )
		) {
			return null;
		}

		$where_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::WHERE_SYMBOL, $from_position + 1, $group_position );
		if (
			null === $where_position
			|| ! $this->is_mysql_distinct_term_taxonomy_from_shape( $tokens, $from_position, $where_position )
			|| ! $this->has_mysql_single_term_taxonomy_predicate( $tokens, $where_position + 1, $group_position )
		) {
			return null;
		}

		return array(
			't.term_id',
			'tt.term_taxonomy_id',
			'tt.taxonomy',
			'tt.description',
			'tt.parent',
		);
	}

	/**
	 * Check whether the projection is WordPress's term query result shape.
	 *
	 * @param WP_MySQL_Token[] $tokens           MySQL lexer token stream.
	 * @param array           $projection_items Parsed projection items.
	 * @return bool Whether the projection shape is supported.
	 */
	private function is_mysql_distinct_term_taxonomy_projection_shape( array $tokens, array $projection_items ): bool {
		if ( 6 !== count( $projection_items ) ) {
			return false;
		}

		$expected_columns = array(
			array( 't', 'term_id', 'term_id' ),
			array( 'tt', 'term_taxonomy_id', 'term_taxonomy_id' ),
			array( 'tt', 'taxonomy', 'taxonomy' ),
			array( 'tt', 'description', 'description' ),
			array( 'tt', 'parent', 'parent' ),
		);
		foreach ( $expected_columns as $index => $expected_column ) {
			if (
				! $this->is_mysql_projection_item_qualified_column(
					$tokens,
					$projection_items[ $index ],
					$expected_column[0],
					$expected_column[1],
					$expected_column[2]
				)
			) {
				return false;
			}
		}

		return $this->is_mysql_count_post_type_projection_item( $tokens, $projection_items[5] );
	}

	/**
	 * Check whether a projection item is a specific qualified column.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param array           $item   Parsed projection item.
	 * @param string          $alias  Expected table alias.
	 * @param string          $column Expected column name.
	 * @param string          $name   Expected output name.
	 * @return bool Whether the projection item matches.
	 */
	private function is_mysql_projection_item_qualified_column( array $tokens, array $item, string $alias, string $column, string $name ): bool {
		return strtolower( $item['alias'] ) === $name
			&& $this->is_mysql_exact_qualified_column_expression(
				$tokens,
				$item['expression_start'],
				$item['expression_end'],
				$alias,
				$column
			);
	}

	/**
	 * Check whether a projection item is COUNT(p.post_type) AS count.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param array           $item   Parsed projection item.
	 * @return bool Whether the projection item matches.
	 */
	private function is_mysql_count_post_type_projection_item( array $tokens, array $item ): bool {
		if ( 'count' !== strtolower( $item['alias'] ) ) {
			return false;
		}

		$bounds = $this->normalize_mysql_expression_bounds( $tokens, $item['expression_start'], $item['expression_end'] );
		if (
			! isset( $tokens[ $bounds['start'] ], $tokens[ $bounds['start'] + 1 ] )
			|| ! $this->is_mysql_token_value( $tokens[ $bounds['start'] ], 'count' )
			|| WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $bounds['start'] + 1 ]->id
			|| $this->get_mysql_parenthesized_sequence_end( $tokens, $bounds['start'] + 1, $bounds['end'] ) !== $bounds['end']
		) {
			return false;
		}

		return $this->is_mysql_exact_qualified_column_expression(
			$tokens,
			$bounds['start'] + 2,
			$bounds['end'] - 1,
			'p',
			'post_type'
		);
	}

	/**
	 * Check whether GROUP BY is exactly t.term_id.
	 *
	 * @param WP_MySQL_Token[] $tokens      MySQL lexer token stream.
	 * @param array           $group_items Parsed GROUP BY item ranges.
	 * @return bool Whether the group shape is supported.
	 */
	private function is_mysql_distinct_term_taxonomy_group_shape( array $tokens, array $group_items ): bool {
		return 1 === count( $group_items )
			&& $this->is_mysql_exact_qualified_column_expression(
				$tokens,
				$group_items[0]['start'],
				$group_items[0]['end'],
				't',
				'term_id'
			);
	}

	/**
	 * Check whether ORDER BY can be hidden for the supported term query.
	 *
	 * @param WP_MySQL_Token[] $tokens      MySQL lexer token stream.
	 * @param array           $order_items Parsed ORDER BY items.
	 * @return bool Whether the order shape is supported.
	 */
	private function is_mysql_distinct_term_taxonomy_order_shape( array $tokens, array $order_items ): bool {
		if ( 1 !== count( $order_items ) || null !== $order_items[0]['projection_index'] ) {
			return false;
		}

		return $this->is_mysql_exact_qualified_column_expression(
			$tokens,
			$order_items[0]['expression_start'],
			$order_items[0]['expression_end'],
			't',
			'name'
		);
	}

	/**
	 * Check whether FROM begins with terms t joined to term_taxonomy tt.
	 *
	 * @param WP_MySQL_Token[] $tokens        MySQL lexer token stream.
	 * @param int             $from_position FROM token position.
	 * @param int             $from_end      Final FROM-clause token, exclusive.
	 * @return bool Whether the FROM shape is supported.
	 */
	private function is_mysql_distinct_term_taxonomy_from_shape( array $tokens, int $from_position, int $from_end ): bool {
		$terms_reference = $this->parse_mysql_table_reference( $tokens, $from_position + 1, $from_end );
		if (
			null === $terms_reference
			|| ! $this->is_mysql_wordpress_table_reference( $terms_reference, 'terms', 't' )
		) {
			return false;
		}

		$position = $terms_reference['position'];
		if ( isset( $tokens[ $position ] ) && WP_MySQL_Lexer::INNER_SYMBOL === $tokens[ $position ]->id ) {
			++$position;
		}

		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::JOIN_SYMBOL !== $tokens[ $position ]->id ) {
			return false;
		}

		$term_taxonomy_reference = $this->parse_mysql_table_reference( $tokens, $position + 1, $from_end );
		if (
			null === $term_taxonomy_reference
			|| ! $this->is_mysql_wordpress_table_reference( $term_taxonomy_reference, 'term_taxonomy', 'tt' )
		) {
			return false;
		}

		$position = $term_taxonomy_reference['position'];
		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::ON_SYMBOL !== $tokens[ $position ]->id ) {
			return false;
		}

		$predicate_end = $this->find_mysql_join_predicate_end( $tokens, $position + 1, $from_end );
		$pair          = $this->get_mysql_top_level_simple_column_equality_pair( $tokens, $position + 1, $predicate_end );
		return null !== $pair && $this->is_mysql_wordpress_term_split_column_equality_pair( $pair );
	}

	/**
	 * Check whether WHERE constrains tt.taxonomy to one string literal.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First WHERE predicate token.
	 * @param int             $end    Final WHERE predicate token, exclusive.
	 * @return bool Whether a single taxonomy predicate is present.
	 */
	private function has_mysql_single_term_taxonomy_predicate( array $tokens, int $start, int $end ): bool {
		$conjuncts = $this->split_mysql_top_level_boolean_conjuncts( $tokens, $start, $end );
		if ( null === $conjuncts ) {
			return false;
		}

		$matched = false;
		foreach ( $conjuncts as $conjunct ) {
			if ( ! $this->is_mysql_single_term_taxonomy_predicate( $tokens, $conjunct['start'], $conjunct['end'] ) ) {
				continue;
			}

			if ( $matched ) {
				return false;
			}

			$matched = true;
		}

		return $matched;
	}

	/**
	 * Check whether a predicate is tt.taxonomy = literal or IN (single literal).
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First predicate token.
	 * @param int             $end    Final predicate token, exclusive.
	 * @return bool Whether the predicate constrains one taxonomy value.
	 */
	private function is_mysql_single_term_taxonomy_predicate( array $tokens, int $start, int $end ): bool {
		$bounds = $this->normalize_mysql_expression_bounds( $tokens, $start, $end );
		$start  = $bounds['start'];
		$end    = $bounds['end'];

		$reference = $this->parse_mysql_column_reference( $tokens, $start, $end );
		if (
			null === $reference
			|| $reference['end'] >= $end
			|| 'tt' !== strtolower( (string) $reference['qualifier'] )
			|| 'taxonomy' !== strtolower( $reference['column'] )
		) {
			return false;
		}

		if (
			WP_MySQL_Lexer::EQUAL_OPERATOR === $tokens[ $reference['end'] ]->id
			&& $this->is_mysql_string_literal_range( $tokens, $reference['end'] + 1, $end )
		) {
			return true;
		}

		if (
			WP_MySQL_Lexer::IN_SYMBOL !== $tokens[ $reference['end'] ]->id
			|| ! isset( $tokens[ $reference['end'] + 1 ] )
			|| WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $reference['end'] + 1 ]->id
		) {
			return false;
		}

		$after_close = $this->get_mysql_parenthesized_sequence_end( $tokens, $reference['end'] + 1, $end );
		if ( $after_close !== $end ) {
			return false;
		}

		$items = $this->split_top_level_mysql_arguments( $tokens, $reference['end'] + 2, $end - 1 );
		return null !== $items
			&& 1 === count( $items )
			&& $this->is_mysql_string_literal_range( $tokens, $items[0]['start'], $items[0]['end'] );
	}

	/**
	 * Check whether an expression is exactly a qualified column reference.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First expression token.
	 * @param int             $end    Final expression token, exclusive.
	 * @param string          $alias  Expected table alias.
	 * @param string          $column Expected column name.
	 * @return bool Whether the expression matches.
	 */
	private function is_mysql_exact_qualified_column_expression( array $tokens, int $start, int $end, string $alias, string $column ): bool {
		$column_expression = $this->get_mysql_simple_qualified_column_expression( $tokens, $start, $end );
		return null !== $column_expression
			&& strtolower( $alias ) === $column_expression['qualifier']
			&& strtolower( $column ) === $column_expression['column'];
	}

	/**
	 * Build a derived-table rewrite for DISTINCT grouped ORDER BY queries.
	 *
	 * @param WP_MySQL_Token[] $tokens           MySQL lexer token stream.
	 * @param array           $projection_items Parsed projection items.
	 * @param string[]        $group_by_sql     PostgreSQL GROUP BY expressions.
	 * @param array           $order_items      Parsed ORDER BY items.
	 * @param int             $from_position    FROM token position.
	 * @param int             $group_position   GROUP token position.
	 * @param int             $order_position   ORDER token position.
	 * @param int|null        $limit_position   LIMIT token position, or null.
	 * @param int             $statement_end    Final statement token position, exclusive.
	 * @param bool            $include_limit    Whether to preserve the LIMIT/OFFSET clause.
	 * @return string PostgreSQL query.
	 */
	private function build_distinct_strict_grouped_order_by_query(
		array $tokens,
		array $projection_items,
		array $group_by_sql,
		array $order_items,
		int $from_position,
		int $group_position,
		int $order_position,
		?int $limit_position,
		int $statement_end,
		bool $include_limit = true
	): string {
		$derived_table_alias        = '__wp_pg_distinct';
		$quoted_derived_table_alias = $this->connection->quote_identifier( $derived_table_alias );
		$inner_projection_sql       = array();
		$outer_projection_sql       = array();

		foreach ( $projection_items as $projection_item ) {
			$quoted_alias           = $this->connection->quote_identifier( $projection_item['alias'] );
			$inner_projection_sql[] = $projection_item['sql'] . ' AS ' . $quoted_alias;
			$outer_projection_sql[] = sprintf(
				'%s.%s AS %s',
				$quoted_derived_table_alias,
				$quoted_alias,
				$quoted_alias
			);
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
			'SELECT %s FROM (SELECT DISTINCT %s %s GROUP BY %s) AS %s ORDER BY %s',
			implode( ', ', $outer_projection_sql ),
			implode( ', ', $inner_projection_sql ),
			$this->translate_mysql_token_sequence_to_postgresql( $tokens, $from_position, $group_position ),
			implode( ', ', $group_by_sql ),
			$quoted_derived_table_alias,
			$this->get_distinct_order_by_outer_order_sql( $projection_items, $order_items, $quoted_derived_table_alias )
		);

		if ( $include_limit && null !== $limit_position ) {
			$sql .= $this->translate_simple_select_limit_clause_to_postgresql( $tokens, $limit_position, $statement_end );
		}

		return $sql;
	}

	/**
	 * Translate grouped SELECT queries that reference projection aliases in HAVING.
	 *
	 * MySQL resolves SELECT aliases in HAVING, but PostgreSQL does not. Keep this
	 * rewrite limited to aliases whose projected expression is valid in a grouped
	 * HAVING clause so unsupported grouping shapes still fail visibly.
	 *
	 * @param string $query MySQL query.
	 * @return string|null PostgreSQL query, or null when unsupported.
	 */
	private function translate_grouped_having_alias_query( string $query ): ?string {
		$tokens = $this->get_mysql_tokens( $query );
		if ( ! isset( $tokens[0] ) || WP_MySQL_Lexer::SELECT_SYMBOL !== $tokens[0]->id ) {
			return null;
		}

		$statement_end = $this->get_mysql_statement_end_position( $tokens, 1 );
		if ( null === $statement_end ) {
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
					WP_MySQL_Lexer::HIGH_PRIORITY_SYMBOL,
					WP_MySQL_Lexer::INTO_SYMBOL,
					WP_MySQL_Lexer::LOCK_SYMBOL,
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

		$limit_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::LIMIT_SYMBOL, 1, $statement_end );
		$select_end     = $limit_position ?? $statement_end;
		if ( null !== $limit_position && ! $this->is_supported_simple_select_limit_clause( $tokens, $limit_position, $statement_end ) ) {
			return null;
		}

		$order_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::ORDER_SYMBOL, 1, $select_end );
		if (
			null !== $order_position
			&& (
				! isset( $tokens[ $order_position + 1 ] )
				|| WP_MySQL_Lexer::BY_SYMBOL !== $tokens[ $order_position + 1 ]->id
				|| $order_position + 2 >= $select_end
			)
		) {
			return null;
		}

		$having_end      = $order_position ?? $select_end;
		$having_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::HAVING_SYMBOL, 1, $having_end );
		if ( null === $having_position || $having_position + 1 >= $having_end ) {
			return null;
		}

		$group_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::GROUP_SYMBOL, 1, $having_position );
		if (
			null === $group_position
			|| ! isset( $tokens[ $group_position + 1 ] )
			|| WP_MySQL_Lexer::BY_SYMBOL !== $tokens[ $group_position + 1 ]->id
			|| $group_position + 2 >= $having_position
		) {
			return null;
		}

		$from_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::FROM_SYMBOL, 1, $group_position );
		if ( null === $from_position || 1 === $from_position ) {
			return null;
		}

		$group_items = $this->split_top_level_mysql_arguments( $tokens, $group_position + 2, $having_position );
		if ( null === $group_items || count( $group_items ) < 1 ) {
			return null;
		}

		$alias_expressions = $this->get_mysql_grouped_having_projection_alias_expressions(
			$tokens,
			1,
			$from_position,
			$group_items
		);
		if ( null === $alias_expressions || empty( $alias_expressions ) ) {
			return null;
		}

		$having_sql = $this->translate_mysql_having_alias_predicate_to_postgresql(
			$tokens,
			$having_position + 1,
			$having_end,
			$alias_expressions
		);
		if ( null === $having_sql ) {
			return null;
		}

		$replacements   = array();
		$where_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::WHERE_SYMBOL, $from_position + 1, $group_position );
		if ( null !== $where_position ) {
			$scope_end = $where_position;
		} else {
			$scope_end = $group_position;
		}

		$scope = $this->get_mysql_select_scope( $tokens, $from_position + 1, $scope_end );
		if ( null === $scope ) {
			return null;
		}

		if ( null !== $where_position ) {
			$where_sql = $this->translate_mysql_predicate_token_sequence_to_postgresql(
				$tokens,
				$where_position + 1,
				$group_position,
				$scope
			);
			if ( $where_sql['changed'] ) {
				$replacements[] = array(
					'start' => $where_position + 1,
					'end'   => $group_position,
					'sql'   => $where_sql['sql'],
				);
			}
		}

		$group_by_extensions = $this->get_mysql_grouped_having_group_by_projection_extensions(
			$tokens,
			1,
			$from_position,
			$group_position,
			$having_position,
			$group_items
		);
		if ( null === $group_by_extensions ) {
			return null;
		}

		if ( ! empty( $group_by_extensions ) ) {
			$replacements[] = array(
				'start' => $group_position + 2,
				'end'   => $having_position,
				'sql'   => $this->translate_mysql_token_sequence_to_postgresql( $tokens, $group_position + 2, $having_position )
					. ', ' . implode( ', ', $group_by_extensions ),
			);
		}

		$replacements[] = array(
			'start' => $having_position + 1,
			'end'   => $having_end,
			'sql'   => $having_sql,
		);

		return 'SELECT ' . $this->translate_mysql_token_sequence_with_replacements_to_postgresql(
			$tokens,
			1,
			$statement_end,
			$replacements
		);
	}

	/**
	 * Get projection aliases that can be substituted safely in grouped HAVING.
	 *
	 * @param WP_MySQL_Token[] $tokens      MySQL lexer token stream.
	 * @param int             $start       First projection token position.
	 * @param int             $end         Final projection token position, exclusive.
	 * @param array           $group_items Parsed GROUP BY items.
	 * @return array<string, array{sql: string}>|null Alias expressions keyed by lowercase alias.
	 */
	private function get_mysql_grouped_having_projection_alias_expressions( array $tokens, int $start, int $end, array $group_items ): ?array {
		$ranges = $this->split_top_level_mysql_arguments( $tokens, $start, $end );
		if ( null === $ranges || count( $ranges ) < 1 ) {
			return null;
		}

		$aliases = array();
		foreach ( $ranges as $range ) {
			$item = $this->parse_mysql_aliased_projection_expression( $tokens, $range['start'], $range['end'] );
			if ( null === $item ) {
				continue;
			}

			$alias_key = strtolower( $item['alias'] );
			if ( isset( $aliases[ $alias_key ] ) ) {
				return null;
			}

			if (
				! $this->contains_mysql_aggregate_call( $tokens, $item['expression_start'], $item['expression_end'] )
				&& ! $this->is_mysql_grouped_projection_expression( $tokens, $item, $group_items )
			) {
				continue;
			}

			$aliases[ $alias_key ] = array(
				'sql' => $this->translate_mysql_token_sequence_to_postgresql(
					$tokens,
					$item['expression_start'],
					$item['expression_end']
				),
			);
		}

		return $aliases;
	}

	/**
	 * Get GROUP BY extensions for selected columns equivalent to grouped columns.
	 *
	 * @param WP_MySQL_Token[] $tokens         MySQL lexer token stream.
	 * @param int             $projection_start First projection token position.
	 * @param int             $from_position  FROM token position.
	 * @param int             $group_position GROUP token position.
	 * @param int             $having_position HAVING token position.
	 * @param array           $group_items    Parsed GROUP BY items.
	 * @return string[]|null PostgreSQL GROUP BY expressions to append, or null when unsupported.
	 */
	private function get_mysql_grouped_having_group_by_projection_extensions(
		array $tokens,
		int $projection_start,
		int $from_position,
		int $group_position,
		int $having_position,
		array $group_items
	): ?array {
		$projection_items = $this->split_top_level_mysql_arguments( $tokens, $projection_start, $from_position );
		if ( null === $projection_items ) {
			return null;
		}

		$grouped_columns = array();
		foreach ( $group_items as $group_item ) {
			$grouped_column = $this->get_mysql_simple_qualified_column_expression(
				$tokens,
				$group_item['start'],
				$group_item['end']
			);
			if ( null !== $grouped_column ) {
				$grouped_columns[] = $grouped_column;
			}
		}

		if ( empty( $grouped_columns ) ) {
			return array();
		}

		$extensions         = array();
		$extension_keys     = array();
		$equivalent_columns = null;
		foreach ( $projection_items as $projection_item ) {
			$bounds = $this->get_mysql_projection_expression_bounds( $tokens, $projection_item['start'], $projection_item['end'] );
			if ( null === $bounds ) {
				continue;
			}

			$projection_column = $this->get_mysql_simple_qualified_column_expression( $tokens, $bounds['start'], $bounds['end'] );
			if ( null === $projection_column ) {
				continue;
			}

			if ( $this->is_mysql_projection_column_grouped( $projection_column, $grouped_columns ) ) {
				continue;
			}

			if ( null === $equivalent_columns ) {
				$equivalent_columns = $this->get_mysql_safe_grouped_having_column_equality_pairs(
					$tokens,
					$from_position,
					$group_position
				);
				if ( null === $equivalent_columns || empty( $equivalent_columns ) ) {
					return null;
				}
			}

			$extended = false;
			foreach ( $grouped_columns as $grouped_column ) {
				if ( ! $this->are_mysql_simple_columns_equivalent( $projection_column, $grouped_column, $equivalent_columns ) ) {
					continue;
				}

				$extension_key = $projection_column['key'];
				if ( isset( $extension_keys[ $extension_key ] ) ) {
					$extended = true;
					break;
				}

				$extensions[]                     = $projection_column['sql'];
				$extension_keys[ $extension_key ] = true;
				$extended                         = true;
				break;
			}

			if ( ! $extended ) {
				return null;
			}
		}

		return $extensions;
	}

	/**
	 * Get expression bounds for a SELECT projection item.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First projection item token position.
	 * @param int             $end    Final projection item token position, exclusive.
	 * @return array{start: int, end: int}|null Expression bounds, or null when malformed.
	 */
	private function get_mysql_projection_expression_bounds( array $tokens, int $start, int $end ): ?array {
		if ( $start >= $end ) {
			return null;
		}

		$expression_end = $end;
		$as_position    = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::AS_SYMBOL, $start, $end );
		if ( null !== $as_position ) {
			if ( $as_position <= $start || $as_position + 2 !== $end ) {
				return null;
			}

			$expression_end = $as_position;
		} elseif ( null !== $this->get_mysql_implicit_projection_alias( $tokens, $start, $end ) ) {
			$expression_end = $end - 1;
		}

		return $start >= $expression_end
			? null
			: array(
				'start' => $start,
				'end'   => $expression_end,
			);
	}

	/**
	 * Parse a simple qualified column expression.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First expression token position.
	 * @param int             $end    Final expression token position, exclusive.
	 * @return array{qualifier: string, column: string, key: string, sql: string}|null Column data, or null when unsupported.
	 */
	private function get_mysql_simple_qualified_column_expression( array $tokens, int $start, int $end ): ?array {
		$bounds = $this->normalize_mysql_expression_bounds( $tokens, $start, $end );
		return $this->get_mysql_unwrapped_simple_qualified_column_expression( $tokens, $bounds['start'], $bounds['end'] );
	}

	/**
	 * Parse a simple qualified column expression without removing wrapper parentheses.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First expression token position.
	 * @param int             $end    Final expression token position, exclusive.
	 * @return array{qualifier: string, column: string, key: string, sql: string}|null Column data, or null when unsupported.
	 */
	private function get_mysql_unwrapped_simple_qualified_column_expression( array $tokens, int $start, int $end ): ?array {
		if (
			$start + 3 !== $end
			|| ! isset( $tokens[ $start ], $tokens[ $start + 1 ], $tokens[ $start + 2 ] )
			|| WP_MySQL_Lexer::DOT_SYMBOL !== $tokens[ $start + 1 ]->id
		) {
			return null;
		}

		$qualifier = $this->get_mysql_identifier_token_value( $tokens[ $start ] );
		$column    = $this->get_mysql_identifier_token_value( $tokens[ $start + 2 ] );
		if ( null === $qualifier || null === $column ) {
			return null;
		}

		$key = strtolower( $qualifier ) . '.' . strtolower( $column );
		return array(
			'qualifier' => strtolower( $qualifier ),
			'column'    => strtolower( $column ),
			'key'       => $key,
			'sql'       => $this->translate_mysql_token_sequence_to_postgresql( $tokens, $start, $end ),
		);
	}

	/**
	 * Get safe qualified column equality pairs for grouped HAVING rewrites.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $from_position  FROM token position.
	 * @param int             $group_position GROUP token position.
	 * @return array<string, array<string, true>>|null Column equality adjacency map, or null when unsupported.
	 */
	private function get_mysql_safe_grouped_having_column_equality_pairs( array $tokens, int $from_position, int $group_position ): ?array {
		$where_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::WHERE_SYMBOL, $from_position + 1, $group_position );
		$from_end       = $where_position ?? $group_position;

		$pairs = $this->get_mysql_inner_join_column_equality_pairs( $tokens, $from_position + 1, $from_end );
		if ( null === $pairs ) {
			return null === $where_position
				? $this->get_mysql_wordpress_term_split_left_join_column_equality_pairs( $tokens, $from_position + 1, $from_end )
				: null;
		}

		if ( null === $where_position ) {
			return $pairs;
		}

		$where_pairs = $this->get_mysql_top_level_conjunct_column_equality_pairs( $tokens, $where_position + 1, $group_position );
		if ( null === $where_pairs ) {
			return null;
		}

		$this->merge_mysql_column_equality_pairs( $pairs, $where_pairs );
		return $pairs;
	}

	/**
	 * Get qualified column equality pairs from supported inner JOIN predicates.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First FROM-clause token position.
	 * @param int             $end    Final FROM-clause token position, exclusive.
	 * @return array<string, array<string, true>>|null Column equality adjacency map, or null when unsupported.
	 */
	private function get_mysql_inner_join_column_equality_pairs( array $tokens, int $start, int $end ): ?array {
		$pairs = array();

		for ( $position = $start; $position < $end; $position++ ) {
			$token_id = $tokens[ $position ]->id;
			if (
				WP_MySQL_Lexer::LEFT_SYMBOL === $token_id
				|| WP_MySQL_Lexer::NATURAL_SYMBOL === $token_id
				|| WP_MySQL_Lexer::OUTER_SYMBOL === $token_id
				|| WP_MySQL_Lexer::RIGHT_SYMBOL === $token_id
				|| WP_MySQL_Lexer::STRAIGHT_JOIN_SYMBOL === $token_id
				|| WP_MySQL_Lexer::USING_SYMBOL === $token_id
			) {
				return null;
			}

			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $token_id ) {
				return null;
			}

			if ( WP_MySQL_Lexer::ON_SYMBOL !== $token_id ) {
				continue;
			}

			$predicate_end = $this->find_mysql_join_predicate_end( $tokens, $position + 1, $end );
			$join_pairs    = $this->get_mysql_top_level_conjunct_column_equality_pairs( $tokens, $position + 1, $predicate_end );
			if ( null === $join_pairs ) {
				return null;
			}

			$this->merge_mysql_column_equality_pairs( $pairs, $join_pairs );
			$position = $predicate_end - 1;
		}

		return $pairs;
	}

	/**
	 * Get equality pairs for WordPress core's legacy shared-term split query.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First FROM-clause token position.
	 * @param int             $end    Final FROM-clause token position, exclusive.
	 * @return array<string, array<string, true>>|null Column equality adjacency map, or null when unsupported.
	 */
	private function get_mysql_wordpress_term_split_left_join_column_equality_pairs( array $tokens, int $start, int $end ): ?array {
		$term_taxonomy_reference = $this->parse_mysql_table_reference( $tokens, $start, $end );
		if (
			null === $term_taxonomy_reference
			|| ! $this->is_mysql_wordpress_table_reference( $term_taxonomy_reference, 'term_taxonomy', 'tt' )
		) {
			return null;
		}

		$position = $term_taxonomy_reference['position'];
		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::LEFT_SYMBOL !== $tokens[ $position ]->id ) {
			return null;
		}

		++$position;
		if ( isset( $tokens[ $position ] ) && WP_MySQL_Lexer::OUTER_SYMBOL === $tokens[ $position ]->id ) {
			++$position;
		}

		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::JOIN_SYMBOL !== $tokens[ $position ]->id ) {
			return null;
		}

		$terms_reference = $this->parse_mysql_table_reference( $tokens, $position + 1, $end );
		if (
			null === $terms_reference
			|| ! $this->is_mysql_wordpress_table_reference( $terms_reference, 'terms', 't' )
		) {
			return null;
		}

		$position = $terms_reference['position'];
		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::ON_SYMBOL !== $tokens[ $position ]->id ) {
			return null;
		}

		$predicate_end = $this->find_mysql_join_predicate_end( $tokens, $position + 1, $end );
		if ( $predicate_end !== $end ) {
			return null;
		}

		$pair = $this->get_mysql_top_level_simple_column_equality_pair( $tokens, $position + 1, $predicate_end );
		if ( null === $pair || ! $this->is_mysql_wordpress_term_split_column_equality_pair( $pair ) ) {
			return null;
		}

		return array(
			't.term_id'  => array(
				'tt.term_id' => true,
			),
			'tt.term_id' => array(
				't.term_id' => true,
			),
		);
	}

	/**
	 * Check whether a table reference matches a WordPress core table and alias.
	 *
	 * @param array  $reference Parsed table reference.
	 * @param string $table_base Expected unprefixed table name.
	 * @param string $alias      Expected alias.
	 * @return bool Whether the reference matches.
	 */
	private function is_mysql_wordpress_table_reference( array $reference, string $table_base, string $alias ): bool {
		$reference_alias = strtolower( null === $reference['alias'] ? $reference['table'] : $reference['alias'] );
		if ( $alias !== $reference_alias ) {
			return false;
		}

		$table_name = strtolower( $reference['table'] );
		return $table_base === $table_name
			|| substr( $table_name, -strlen( '_' . $table_base ) ) === '_' . $table_base;
	}

	/**
	 * Check whether an equality pair is t.term_id = tt.term_id.
	 *
	 * @param array $pair Parsed equality pair.
	 * @return bool Whether this is the WordPress shared-term split equality.
	 */
	private function is_mysql_wordpress_term_split_column_equality_pair( array $pair ): bool {
		return (
			't.term_id' === $pair['left']['key']
			&& 'tt.term_id' === $pair['right']['key']
		) || (
			'tt.term_id' === $pair['left']['key']
			&& 't.term_id' === $pair['right']['key']
		);
	}

	/**
	 * Find the end of a JOIN ON predicate.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First ON predicate token position.
	 * @param int             $end    Final FROM-clause token position, exclusive.
	 * @return int Final ON predicate token position, exclusive.
	 */
	private function find_mysql_join_predicate_end( array $tokens, int $start, int $end ): int {
		$depth = 0;
		for ( $position = $start; $position < $end; $position++ ) {
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $position ]->id ) {
				++$depth;
				continue;
			}

			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $position ]->id ) {
				--$depth;
				continue;
			}

			if (
				0 === $depth
				&& (
					WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $position ]->id
					|| WP_MySQL_Lexer::INNER_SYMBOL === $tokens[ $position ]->id
					|| WP_MySQL_Lexer::JOIN_SYMBOL === $tokens[ $position ]->id
					|| WP_MySQL_Lexer::LEFT_SYMBOL === $tokens[ $position ]->id
					|| WP_MySQL_Lexer::NATURAL_SYMBOL === $tokens[ $position ]->id
					|| WP_MySQL_Lexer::RIGHT_SYMBOL === $tokens[ $position ]->id
					|| WP_MySQL_Lexer::STRAIGHT_JOIN_SYMBOL === $tokens[ $position ]->id
				)
			) {
				return $position;
			}
		}

		return $end;
	}

	/**
	 * Get column equality pairs from a top-level AND predicate.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First predicate token position.
	 * @param int             $end    Final predicate token position, exclusive.
	 * @return array<string, array<string, true>>|null Column equality adjacency map, or null when unsupported.
	 */
	private function get_mysql_top_level_conjunct_column_equality_pairs( array $tokens, int $start, int $end ): ?array {
		$conjuncts = $this->split_mysql_top_level_boolean_conjuncts( $tokens, $start, $end );
		if ( null === $conjuncts ) {
			return null;
		}

		$pairs = array();
		foreach ( $conjuncts as $conjunct ) {
			$pair = $this->get_mysql_top_level_simple_column_equality_pair(
				$tokens,
				$conjunct['start'],
				$conjunct['end']
			);
			if ( null === $pair ) {
				continue;
			}

			$pairs[ $pair['left']['key'] ][ $pair['right']['key'] ] = true;
			$pairs[ $pair['right']['key'] ][ $pair['left']['key'] ] = true;
		}

		return $pairs;
	}

	/**
	 * Split a boolean predicate into top-level AND conjuncts.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First predicate token position.
	 * @param int             $end    Final predicate token position, exclusive.
	 * @return array<int, array{start: int, end: int}>|null Conjunct bounds, or null when unsupported.
	 */
	private function split_mysql_top_level_boolean_conjuncts( array $tokens, int $start, int $end ): ?array {
		if ( $start >= $end ) {
			return null;
		}

		$conjuncts      = array();
		$conjunct_start = $start;
		$depth          = 0;

		for ( $position = $start; $position < $end; $position++ ) {
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $position ]->id ) {
				++$depth;
				continue;
			}

			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $position ]->id ) {
				--$depth;
				if ( $depth < 0 ) {
					return null;
				}
				continue;
			}

			if ( 0 !== $depth ) {
				continue;
			}

			if ( WP_MySQL_Lexer::OR_SYMBOL === $tokens[ $position ]->id || WP_MySQL_Lexer::XOR_SYMBOL === $tokens[ $position ]->id ) {
				return null;
			}

			if ( WP_MySQL_Lexer::AND_SYMBOL !== $tokens[ $position ]->id ) {
				continue;
			}

			if ( $conjunct_start === $position ) {
				return null;
			}

			$conjuncts[]    = array(
				'start' => $conjunct_start,
				'end'   => $position,
			);
			$conjunct_start = $position + 1;
		}

		if ( 0 !== $depth || $conjunct_start >= $end ) {
			return null;
		}

		$conjuncts[] = array(
			'start' => $conjunct_start,
			'end'   => $end,
		);

		return $conjuncts;
	}

	/**
	 * Parse a top-level simple qualified-column equality predicate.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First predicate token position.
	 * @param int             $end    Final predicate token position, exclusive.
	 * @return array{left: array, right: array}|null Equality pair, or null when unsupported.
	 */
	private function get_mysql_top_level_simple_column_equality_pair( array $tokens, int $start, int $end ): ?array {
		$equal_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::EQUAL_OPERATOR, $start, $end );
		if (
			null === $equal_position
			|| null !== $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::EQUAL_OPERATOR, $equal_position + 1, $end )
		) {
			return null;
		}

		$left_column  = $this->get_mysql_unwrapped_simple_qualified_column_expression( $tokens, $start, $equal_position );
		$right_column = $this->get_mysql_unwrapped_simple_qualified_column_expression( $tokens, $equal_position + 1, $end );
		if ( null === $left_column || null === $right_column ) {
			return null;
		}

		return array(
			'left'  => $left_column,
			'right' => $right_column,
		);
	}

	/**
	 * Merge column equality adjacency maps.
	 *
	 * @param array $target Target adjacency map.
	 * @param array $source Source adjacency map.
	 */
	private function merge_mysql_column_equality_pairs( array &$target, array $source ): void {
		foreach ( $source as $left_key => $right_columns ) {
			foreach ( $right_columns as $right_key => $_ ) {
				$target[ $left_key ][ $right_key ] = true;
			}
		}
	}

	/**
	 * Check whether a selected column is already grouped.
	 *
	 * @param array $projection_column Selected column data.
	 * @param array $grouped_columns   Grouped column data.
	 * @return bool Whether the selected column is grouped.
	 */
	private function is_mysql_projection_column_grouped( array $projection_column, array $grouped_columns ): bool {
		foreach ( $grouped_columns as $grouped_column ) {
			if ( $projection_column['key'] === $grouped_column['key'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether two simple columns are connected by a parsed equality.
	 *
	 * @param array $left_column        Left column data.
	 * @param array $right_column       Right column data.
	 * @param array $equivalent_columns Column equality adjacency map.
	 * @return bool Whether the columns are equivalent.
	 */
	private function are_mysql_simple_columns_equivalent( array $left_column, array $right_column, array $equivalent_columns ): bool {
		return $left_column['key'] === $right_column['key']
			|| isset( $equivalent_columns[ $left_column['key'] ][ $right_column['key'] ] );
	}

	/**
	 * Parse a projection item that has an explicit or implicit alias.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First projection item token position.
	 * @param int             $end    Final projection item token position, exclusive.
	 * @return array{expression_start: int, expression_end: int, alias: string}|null Parsed alias expression, or null when absent.
	 */
	private function parse_mysql_aliased_projection_expression( array $tokens, int $start, int $end ): ?array {
		if ( $start >= $end ) {
			return null;
		}

		$expression_end = $end;
		$alias          = null;
		$as_position    = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::AS_SYMBOL, $start, $end );

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
			$alias = $this->get_mysql_implicit_projection_alias( $tokens, $start, $end );
			if ( null === $alias ) {
				return null;
			}

			$expression_end = $end - 1;
		}

		if ( $start >= $expression_end ) {
			return null;
		}

		return array(
			'expression_start' => $start,
			'expression_end'   => $expression_end,
			'alias'            => $alias,
		);
	}

	/**
	 * Check whether a projection expression is already present in GROUP BY.
	 *
	 * @param WP_MySQL_Token[] $tokens      MySQL lexer token stream.
	 * @param array           $item        Parsed projection item.
	 * @param array           $group_items Parsed GROUP BY items.
	 * @return bool Whether the projection expression is grouped.
	 */
	private function is_mysql_grouped_projection_expression( array $tokens, array $item, array $group_items ): bool {
		foreach ( $group_items as $group_item ) {
			if (
				$this->are_mysql_token_ranges_equivalent(
					$tokens,
					$item['expression_start'],
					$item['expression_end'],
					$group_item['start'],
					$group_item['end']
				)
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a token range contains a MySQL aggregate function call.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First expression token position.
	 * @param int             $end    Final expression token position, exclusive.
	 * @return bool Whether an aggregate call is present.
	 */
	private function contains_mysql_aggregate_call( array $tokens, int $start, int $end ): bool {
		$aggregate_token_ids = array(
			WP_MySQL_Lexer::AVG_SYMBOL,
			WP_MySQL_Lexer::BIT_AND_SYMBOL,
			WP_MySQL_Lexer::BIT_OR_SYMBOL,
			WP_MySQL_Lexer::BIT_XOR_SYMBOL,
			WP_MySQL_Lexer::COUNT_SYMBOL,
			WP_MySQL_Lexer::GROUP_CONCAT_SYMBOL,
			WP_MySQL_Lexer::MAX_SYMBOL,
			WP_MySQL_Lexer::MIN_SYMBOL,
			WP_MySQL_Lexer::STD_SYMBOL,
			WP_MySQL_Lexer::STDDEV_POP_SYMBOL,
			WP_MySQL_Lexer::STDDEV_SAMP_SYMBOL,
			WP_MySQL_Lexer::STDDEV_SYMBOL,
			WP_MySQL_Lexer::SUM_SYMBOL,
			WP_MySQL_Lexer::VAR_POP_SYMBOL,
			WP_MySQL_Lexer::VAR_SAMP_SYMBOL,
			WP_MySQL_Lexer::VARIANCE_SYMBOL,
		);

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

			if (
				isset( $tokens[ $position + 1 ] )
				&& in_array( $tokens[ $position ]->id, $aggregate_token_ids, true )
				&& WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $position + 1 ]->id
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Translate HAVING predicate aliases to their projection expressions.
	 *
	 * @param WP_MySQL_Token[] $tokens            MySQL lexer token stream.
	 * @param int             $start             First HAVING predicate token.
	 * @param int             $end               Final HAVING predicate token, exclusive.
	 * @param array           $alias_expressions Projection alias SQL keyed by lowercase alias.
	 * @return string|null Translated HAVING SQL, or null when no alias was changed.
	 */
	private function translate_mysql_having_alias_predicate_to_postgresql( array $tokens, int $start, int $end, array $alias_expressions ): ?string {
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

			$alias = $this->get_mysql_order_by_alias_token_value( $tokens[ $position ] ?? null );
			if (
				null === $alias
				|| ! $this->is_unqualified_mysql_having_alias_reference( $tokens, $position, $end )
				|| ! isset( $alias_expressions[ strtolower( $alias ) ] )
			) {
				continue;
			}

			if ( $segment_start < $position ) {
				$chunks[] = $this->translate_mysql_token_sequence_to_postgresql( $tokens, $segment_start, $position );
			}

			$chunks[]      = '(' . $alias_expressions[ strtolower( $alias ) ]['sql'] . ')';
			$segment_start = $position + 1;
			$changed       = true;
		}

		if ( ! $changed ) {
			return null;
		}

		if ( $segment_start < $end ) {
			$chunks[] = $this->translate_mysql_token_sequence_to_postgresql( $tokens, $segment_start, $end );
		}

		return implode( ' ', array_filter( $chunks, 'strlen' ) );
	}

	/**
	 * Check whether a HAVING token is an unqualified alias reference.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Candidate alias token position.
	 * @param int             $end      Final HAVING predicate token, exclusive.
	 * @return bool Whether the token can be replaced as an alias.
	 */
	private function is_unqualified_mysql_having_alias_reference( array $tokens, int $position, int $end ): bool {
		if ( isset( $tokens[ $position - 1 ] ) && WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $position - 1 ]->id ) {
			return false;
		}

		if (
			isset( $tokens[ $position + 1 ] )
			&& (
				WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $position + 1 ]->id
				|| WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $position + 1 ]->id
			)
		) {
			return false;
		}

		return $position < $end;
	}

	/**
	 * Get projection replacements needed by grouped archive queries.
	 *
	 * @param WP_MySQL_Token[] $tokens                  MySQL lexer token stream.
	 * @param array           $projection_items        Parsed projection items.
	 * @param array           $archive_date_expression Shared date expression bounds.
	 * @return array<int, array{start: int, end: int, sql: string}> Replacement ranges.
	 */
	private function get_mysql_archive_grouped_projection_replacements( array $tokens, array $projection_items, array $archive_date_expression ): array {
		$replacements = array();

		foreach ( $projection_items as $projection_item ) {
			$bounds = $this->get_mysql_date_format_bounds(
				$tokens,
				$projection_item['expression_start'],
				$projection_item['expression_end']
			);
			if (
				null === $bounds
				|| '%Y-%m-%d' !== $bounds['format']
				|| $bounds['close'] + 1 !== $projection_item['expression_end']
				|| ! $this->are_mysql_token_ranges_equivalent(
					$tokens,
					$archive_date_expression['start'],
					$archive_date_expression['end'],
					$bounds['expression_start'],
					$bounds['expression_end']
				)
			) {
				continue;
			}

			$expression_sql = $this->translate_mysql_token_sequence_to_postgresql(
				$tokens,
				$bounds['expression_start'],
				$bounds['expression_end']
			);
			$sql            = $this->get_postgresql_mysql_date_format_sql(
				$bounds['format'],
				sprintf( 'MAX(%s)', $expression_sql )
			);
			if ( null === $sql ) {
				continue;
			}

			$replacements[] = array(
				'start' => $projection_item['expression_start'],
				'end'   => $projection_item['expression_end'],
				'sql'   => $sql,
			);
		}

		return $replacements;
	}

	/**
	 * Check whether DISTINCT is redundant for the supported weekly archive shape.
	 *
	 * @param WP_MySQL_Token[] $tokens                  MySQL lexer token stream.
	 * @param array           $projection_items        Parsed projection items.
	 * @param array           $group_items             Parsed GROUP BY item ranges.
	 * @param array           $archive_date_expression Shared date expression bounds.
	 * @return bool Whether this is the supported weekly archive projection.
	 */
	private function is_mysql_redundant_distinct_week_archive_select_shape(
		array $tokens,
		array $projection_items,
		array $group_items,
		array $archive_date_expression
	): bool {
		if ( 4 !== count( $projection_items ) || 2 !== count( $group_items ) ) {
			return false;
		}

		$expected_aliases = array( 'week', 'yr', 'yyyymmdd', 'posts' );
		foreach ( $expected_aliases as $index => $alias ) {
			if ( strtolower( $projection_items[ $index ]['alias'] ) !== $alias ) {
				return false;
			}
		}

		return $this->is_mysql_week_expression_for_archive_date(
			$tokens,
			$projection_items[0]['expression_start'],
			$projection_items[0]['expression_end'],
			$archive_date_expression
		)
			&& $this->is_mysql_year_expression_for_archive_date(
				$tokens,
				$projection_items[1]['expression_start'],
				$projection_items[1]['expression_end'],
				$archive_date_expression
			)
			&& $this->is_mysql_year_month_day_format_expression_for_archive_date(
				$tokens,
				$projection_items[2]['expression_start'],
				$projection_items[2]['expression_end'],
				$archive_date_expression
			)
			&& $this->is_mysql_count_aggregate_expression(
				$tokens,
				$projection_items[3]['expression_start'],
				$projection_items[3]['expression_end']
			)
			&& $this->do_mysql_group_items_include_week_and_year_for_archive_date(
				$tokens,
				$group_items,
				$archive_date_expression
			);
	}

	/**
	 * Check whether an expression is WEEK(archive_date, 1).
	 *
	 * @param WP_MySQL_Token[] $tokens                  MySQL lexer token stream.
	 * @param int             $start                   First expression token.
	 * @param int             $end                     Final expression token, exclusive.
	 * @param array           $archive_date_expression Shared date expression bounds.
	 * @return bool Whether the expression matches the archive week.
	 */
	private function is_mysql_week_expression_for_archive_date( array $tokens, int $start, int $end, array $archive_date_expression ): bool {
		$expression = $this->get_mysql_week_argument_expression_bounds( $tokens, $start, $end );

		return null !== $expression
			&& $this->are_mysql_token_ranges_equivalent(
				$tokens,
				$archive_date_expression['start'],
				$archive_date_expression['end'],
				$expression['start'],
				$expression['end']
			);
	}

	/**
	 * Check whether an expression is YEAR(archive_date).
	 *
	 * @param WP_MySQL_Token[] $tokens                  MySQL lexer token stream.
	 * @param int             $start                   First expression token.
	 * @param int             $end                     Final expression token, exclusive.
	 * @param array           $archive_date_expression Shared date expression bounds.
	 * @return bool Whether the expression matches the archive year.
	 */
	private function is_mysql_year_expression_for_archive_date( array $tokens, int $start, int $end, array $archive_date_expression ): bool {
		$expression = $this->get_mysql_extract_argument_expression_bounds( $tokens, $start, $end, 'YEAR' );

		return null !== $expression
			&& $this->are_mysql_token_ranges_equivalent(
				$tokens,
				$archive_date_expression['start'],
				$archive_date_expression['end'],
				$expression['start'],
				$expression['end']
			);
	}

	/**
	 * Check whether an expression is DATE_FORMAT(archive_date, '%Y-%m-%d').
	 *
	 * @param WP_MySQL_Token[] $tokens                  MySQL lexer token stream.
	 * @param int             $start                   First expression token.
	 * @param int             $end                     Final expression token, exclusive.
	 * @param array           $archive_date_expression Shared date expression bounds.
	 * @return bool Whether the expression matches the archive date format.
	 */
	private function is_mysql_year_month_day_format_expression_for_archive_date( array $tokens, int $start, int $end, array $archive_date_expression ): bool {
		$bounds = $this->get_mysql_date_format_bounds( $tokens, $start, $end );

		return null !== $bounds
			&& '%Y-%m-%d' === $bounds['format']
			&& $bounds['close'] + 1 === $end
			&& $this->are_mysql_token_ranges_equivalent(
				$tokens,
				$archive_date_expression['start'],
				$archive_date_expression['end'],
				$bounds['expression_start'],
				$bounds['expression_end']
			);
	}

	/**
	 * Check whether GROUP BY contains WEEK(archive_date, 1) and YEAR(archive_date).
	 *
	 * @param WP_MySQL_Token[] $tokens                  MySQL lexer token stream.
	 * @param array           $group_items             Parsed GROUP BY item ranges.
	 * @param array           $archive_date_expression Shared date expression bounds.
	 * @return bool Whether both grouped date keys are present.
	 */
	private function do_mysql_group_items_include_week_and_year_for_archive_date( array $tokens, array $group_items, array $archive_date_expression ): bool {
		$has_week = false;
		$has_year = false;

		foreach ( $group_items as $group_item ) {
			$has_week = $has_week || $this->is_mysql_week_expression_for_archive_date(
				$tokens,
				$group_item['start'],
				$group_item['end'],
				$archive_date_expression
			);
			$has_year = $has_year || $this->is_mysql_year_expression_for_archive_date(
				$tokens,
				$group_item['start'],
				$group_item['end'],
				$archive_date_expression
			);
		}

		return $has_week && $has_year;
	}

	/**
	 * Check whether a projection is exactly one COUNT aggregate.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First projection token position.
	 * @param int             $end    Final projection token position, exclusive.
	 * @return bool Whether the projection is COUNT-only.
	 */
	private function is_mysql_count_only_projection( array $tokens, int $start, int $end ): bool {
		$ranges = $this->split_top_level_mysql_arguments( $tokens, $start, $end );
		if ( null === $ranges || 1 !== count( $ranges ) ) {
			return false;
		}

		$expression_bounds = $this->get_mysql_select_projection_expression_bounds(
			$tokens,
			$ranges[0]['start'],
			$ranges[0]['end']
		);
		if ( null === $expression_bounds ) {
			return false;
		}

		return $this->is_mysql_count_aggregate_expression(
			$tokens,
			$expression_bounds['start'],
			$expression_bounds['end']
		);
	}

	/**
	 * Get expression bounds for a projection item, excluding any alias.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First projection item token position.
	 * @param int             $end    Final projection item token position, exclusive.
	 * @return array{start: int, end: int}|null Expression bounds, or null when malformed.
	 */
	private function get_mysql_select_projection_expression_bounds( array $tokens, int $start, int $end ): ?array {
		if ( $start >= $end ) {
			return null;
		}

		$expression_end = $end;
		$as_position    = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::AS_SYMBOL, $start, $end );
		if ( null !== $as_position ) {
			if (
				$as_position <= $start
				|| $as_position + 2 !== $end
				|| null === $this->get_mysql_projection_alias_token_value( $tokens[ $as_position + 1 ] ?? null )
			) {
				return null;
			}

			$expression_end = $as_position;
		} else {
			$implicit_alias = $this->get_mysql_implicit_projection_alias( $tokens, $start, $end );
			if ( null !== $implicit_alias ) {
				$expression_end = $end - 1;
			}
		}

		if ( $start >= $expression_end ) {
			return null;
		}

		return array(
			'start' => $start,
			'end'   => $expression_end,
		);
	}

	/**
	 * Check whether an expression is a COUNT aggregate call.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First expression token.
	 * @param int             $end    Final expression token, exclusive.
	 * @return bool Whether the expression is COUNT(...).
	 */
	private function is_mysql_count_aggregate_expression( array $tokens, int $start, int $end ): bool {
		$bounds = $this->normalize_mysql_expression_bounds( $tokens, $start, $end );
		$start  = $bounds['start'];
		$end    = $bounds['end'];

		return isset( $tokens[ $start ], $tokens[ $start + 1 ] )
			&& $this->is_mysql_token_value( $tokens[ $start ], 'count' )
			&& WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $start + 1 ]->id
			&& $this->get_mysql_parenthesized_sequence_end( $tokens, $start + 1, $end ) === $end;
	}

	/**
	 * Get the shared post_date expression from archive date grouping.
	 *
	 * @param WP_MySQL_Token[] $tokens      MySQL lexer token stream.
	 * @param array           $group_items Parsed GROUP BY item ranges.
	 * @return array{start: int, end: int}|null Shared post_date expression bounds, or null.
	 */
	private function get_mysql_archive_grouped_date_expression_bounds( array $tokens, array $group_items ): ?array {
		$group_count = count( $group_items );
		if ( 1 > $group_count || 3 < $group_count ) {
			return null;
		}

		$year_expression       = null;
		$week_expression       = null;
		$month_expression      = null;
		$dayofmonth_expression = null;
		$supported_expressions = 0;
		foreach ( $group_items as $group_item ) {
			$expression = $this->get_mysql_extract_argument_expression_bounds(
				$tokens,
				$group_item['start'],
				$group_item['end'],
				'YEAR'
			);
			if ( null !== $expression ) {
				$year_expression = $expression;
				++$supported_expressions;
				continue;
			}

			$expression = $this->get_mysql_week_argument_expression_bounds(
				$tokens,
				$group_item['start'],
				$group_item['end']
			);
			if ( null !== $expression ) {
				$week_expression = $expression;
				++$supported_expressions;
				continue;
			}

			$expression = $this->get_mysql_extract_argument_expression_bounds(
				$tokens,
				$group_item['start'],
				$group_item['end'],
				'MONTH'
			);
			if ( null !== $expression ) {
				$month_expression = $expression;
				++$supported_expressions;
				continue;
			}

			$expression = $this->get_mysql_extract_argument_expression_bounds(
				$tokens,
				$group_item['start'],
				$group_item['end'],
				'DAY'
			);
			if ( null !== $expression ) {
				$dayofmonth_expression = $expression;
				++$supported_expressions;
			}
		}

		if (
			$group_count !== $supported_expressions
			|| null === $year_expression
		) {
			return null;
		}

		if ( null !== $week_expression ) {
			if (
				2 !== $group_count
				|| null !== $month_expression
				|| null !== $dayofmonth_expression
				|| ! $this->are_mysql_token_ranges_equivalent(
					$tokens,
					$year_expression['start'],
					$year_expression['end'],
					$week_expression['start'],
					$week_expression['end']
				)
			) {
				return null;
			}
		} elseif (
			(
				2 <= $group_count
				&& null === $month_expression
			)
			|| (
				3 === $group_count
				&& null === $dayofmonth_expression
			)
			|| (
				null !== $month_expression
				&& ! $this->are_mysql_token_ranges_equivalent(
					$tokens,
					$year_expression['start'],
					$year_expression['end'],
					$month_expression['start'],
					$month_expression['end']
				)
			)
			|| (
				null !== $dayofmonth_expression
				&& ! $this->are_mysql_token_ranges_equivalent(
					$tokens,
					$year_expression['start'],
					$year_expression['end'],
					$dayofmonth_expression['start'],
					$dayofmonth_expression['end']
				)
			)
		) {
			return null;
		}

		if (
			! $this->is_mysql_column_reference_expression(
				$tokens,
				$year_expression['start'],
				$year_expression['end'],
				'post_date',
				'posts',
				true
			)
		) {
			return null;
		}

		return $year_expression;
	}

	/**
	 * Get the argument expression for a supported date/time extract function.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First expression token.
	 * @param int             $end    Final expression token, exclusive.
	 * @param string          $unit   Expected date/time unit.
	 * @return array{start: int, end: int}|null Argument bounds, or null.
	 */
	private function get_mysql_extract_argument_expression_bounds( array $tokens, int $start, int $end, string $unit ): ?array {
		$expression_bounds = $this->normalize_mysql_expression_bounds( $tokens, $start, $end );
		$bounds            = $this->get_mysql_extract_function_bounds(
			$tokens,
			$expression_bounds['start'],
			$expression_bounds['end']
		);
		if (
			null === $bounds
			|| $bounds['unit'] !== $unit
			|| $bounds['close'] + 1 !== $expression_bounds['end']
		) {
			return null;
		}

		return array(
			'start' => $bounds['expression_start'],
			'end'   => $bounds['expression_end'],
		);
	}

	/**
	 * Get the argument expression for a supported WEEK() function.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First expression token.
	 * @param int             $end    Final expression token, exclusive.
	 * @return array{start: int, end: int}|null Argument bounds, or null.
	 */
	private function get_mysql_week_argument_expression_bounds( array $tokens, int $start, int $end ): ?array {
		$expression_bounds = $this->normalize_mysql_expression_bounds( $tokens, $start, $end );
		$bounds            = $this->get_mysql_week_function_bounds(
			$tokens,
			$expression_bounds['start'],
			$expression_bounds['end']
		);
		if (
			null === $bounds
			|| $bounds['close'] + 1 !== $expression_bounds['end']
		) {
			return null;
		}

		return array(
			'start' => $bounds['expression_start'],
			'end'   => $bounds['expression_end'],
		);
	}

	/**
	 * Check whether ORDER BY references the archive post_date expression.
	 *
	 * @param WP_MySQL_Token[] $tokens                  MySQL lexer token stream.
	 * @param array           $order_item              Parsed ORDER BY item.
	 * @param array           $archive_date_expression Shared date expression bounds.
	 * @return bool Whether the ORDER BY expression is supported.
	 */
	private function is_mysql_archive_post_date_order_expression( array $tokens, array $order_item, array $archive_date_expression ): bool {
		return $this->are_mysql_token_ranges_equivalent(
			$tokens,
			$order_item['expression_start'],
			$order_item['expression_end'],
			$archive_date_expression['start'],
			$archive_date_expression['end']
		) || $this->is_mysql_column_reference_expression(
			$tokens,
			$order_item['expression_start'],
			$order_item['expression_end'],
			'post_date',
			'posts',
			true
		);
	}

	/**
	 * Check whether a SELECT is grouped by the selected comments.comment_ID.
	 *
	 * @param WP_MySQL_Token[] $tokens           MySQL lexer token stream.
	 * @param array           $projection_items Parsed projection items.
	 * @param array           $group_items      Parsed GROUP BY item ranges.
	 * @return bool Whether this is the supported comment ID grouped shape.
	 */
	private function is_mysql_comment_id_grouped_select_shape( array $tokens, array $projection_items, array $group_items ): bool {
		return 1 === count( $projection_items )
			&& 1 === count( $group_items )
			&& $this->is_mysql_comment_id_expression(
				$tokens,
				$projection_items[0]['expression_start'],
				$projection_items[0]['expression_end']
			)
			&& $this->is_mysql_comment_id_expression(
				$tokens,
				$group_items[0]['start'],
				$group_items[0]['end']
			);
	}

	/**
	 * Check whether an expression references comments.comment_ID.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First expression token.
	 * @param int             $end    Final expression token, exclusive.
	 * @return bool Whether the expression is comments.comment_ID.
	 */
	private function is_mysql_comment_id_expression( array $tokens, int $start, int $end ): bool {
		return $this->is_mysql_column_reference_expression( $tokens, $start, $end, 'comment_ID', 'comments', true );
	}

	/**
	 * Check whether a grouped comment ID ORDER BY expression can be aggregated.
	 *
	 * @param WP_MySQL_Token[] $tokens     MySQL lexer token stream.
	 * @param array           $order_item Parsed ORDER BY item.
	 * @return bool Whether the ORDER BY expression is supported.
	 */
	private function is_mysql_comment_id_grouped_order_expression( array $tokens, array $order_item ): bool {
		if (
			$this->is_mysql_column_reference_expression(
				$tokens,
				$order_item['expression_start'],
				$order_item['expression_end'],
				'comment_date',
				'comments',
				false
			)
			|| $this->is_mysql_column_reference_expression(
				$tokens,
				$order_item['expression_start'],
				$order_item['expression_end'],
				'comment_date_gmt',
				'comments',
				false
			)
			|| $this->is_mysql_qualified_column_reference_expression(
				$tokens,
				$order_item['expression_start'],
				$order_item['expression_end'],
				'meta_value'
			)
		) {
			return true;
		}

		$cast_bounds = $this->get_mysql_character_cast_bounds(
			$tokens,
			$order_item['expression_start'],
			$order_item['expression_end']
		);
		if ( null === $cast_bounds || $cast_bounds['close'] + 1 !== $order_item['expression_end'] ) {
			return false;
		}

		return $this->is_mysql_qualified_column_reference_expression(
			$tokens,
			$cast_bounds['expression_start'],
			$cast_bounds['expression_end'],
			'meta_value'
		);
	}

	/**
	 * Check whether a SELECT is grouped by the selected posts.ID.
	 *
	 * @param WP_MySQL_Token[] $tokens           MySQL lexer token stream.
	 * @param array           $projection_items Parsed projection items.
	 * @param array           $group_items      Parsed GROUP BY item ranges.
	 * @return bool Whether this is the supported post ID grouped shape.
	 */
	private function is_mysql_post_id_grouped_select_shape( array $tokens, array $projection_items, array $group_items ): bool {
		return 1 === count( $projection_items )
			&& 1 === count( $group_items )
			&& $this->is_mysql_post_id_expression(
				$tokens,
				$projection_items[0]['expression_start'],
				$projection_items[0]['expression_end']
			)
			&& $this->is_mysql_post_id_expression(
				$tokens,
				$group_items[0]['start'],
				$group_items[0]['end']
			);
	}

	/**
	 * Check whether an expression references posts.ID.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First expression token.
	 * @param int             $end    Final expression token, exclusive.
	 * @return bool Whether the expression is posts.ID.
	 */
	private function is_mysql_post_id_expression( array $tokens, int $start, int $end ): bool {
		return $this->is_mysql_column_reference_expression( $tokens, $start, $end, 'ID', 'posts', true );
	}

	/**
	 * Check whether a grouped post ID ORDER BY expression can be aggregated.
	 *
	 * @param WP_MySQL_Token[] $tokens     MySQL lexer token stream.
	 * @param array           $order_item Parsed ORDER BY item.
	 * @return bool Whether the ORDER BY expression is supported.
	 */
	private function is_mysql_post_id_grouped_order_expression( array $tokens, array $order_item ): bool {
		if (
			$this->is_mysql_column_reference_expression(
				$tokens,
				$order_item['expression_start'],
				$order_item['expression_end'],
				'post_date',
				'posts',
				false
			)
			|| $this->is_mysql_column_reference_expression(
				$tokens,
				$order_item['expression_start'],
				$order_item['expression_end'],
				'post_date_gmt',
				'posts',
				false
			)
			|| $this->is_mysql_qualified_column_reference_expression(
				$tokens,
				$order_item['expression_start'],
				$order_item['expression_end'],
				'meta_value'
			)
		) {
			return true;
		}

		return $this->is_mysql_meta_value_cast_expression(
			$tokens,
			$order_item['expression_start'],
			$order_item['expression_end']
		) || $this->is_mysql_meta_value_plus_zero_expression(
			$tokens,
			$order_item['expression_start'],
			$order_item['expression_end']
		);
	}

	/**
	 * Check whether an expression is a supported metadata value CAST.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First expression token.
	 * @param int             $end    Final expression token, exclusive.
	 * @return bool Whether the expression casts a qualified meta_value reference.
	 */
	private function is_mysql_meta_value_cast_expression( array $tokens, int $start, int $end ): bool {
		$cast_bounds = $this->get_mysql_character_cast_bounds( $tokens, $start, $end );
		if ( null === $cast_bounds ) {
			$cast_bounds = $this->get_mysql_integer_cast_bounds( $tokens, $start, $end );
		}
		if ( null === $cast_bounds ) {
			$cast_bounds = $this->get_mysql_decimal_cast_bounds( $tokens, $start, $end );
		}

		return null !== $cast_bounds
			&& $cast_bounds['close'] + 1 === $end
			&& $this->is_mysql_qualified_column_reference_expression(
				$tokens,
				$cast_bounds['expression_start'],
				$cast_bounds['expression_end'],
				'meta_value'
			);
	}

	/**
	 * Check whether an expression is metadata value plus zero numeric ordering.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First expression token.
	 * @param int             $end    Final expression token, exclusive.
	 * @return bool Whether the expression numerically orders meta_value.
	 */
	private function is_mysql_meta_value_plus_zero_expression( array $tokens, int $start, int $end ): bool {
		$reference = $this->parse_mysql_column_reference( $tokens, $start, $end );
		if (
			null !== $reference
			&& null !== $reference['qualifier']
			&& isset( $tokens[ $reference['end'] ] )
			&& WP_MySQL_Lexer::PLUS_OPERATOR === $tokens[ $reference['end'] ]->id
		) {
			$literal = $this->parse_mysql_numeric_literal( $tokens, $reference['end'] + 1, $end );
			return null !== $literal
				&& $literal['end'] === $end
				&& $this->is_mysql_zero_numeric_literal_range( $tokens, $literal['start'], $literal['end'] )
				&& $this->is_mysql_qualified_column_reference_expression( $tokens, $start, $reference['end'], 'meta_value' );
		}

		$literal = $this->parse_mysql_numeric_literal( $tokens, $start, $end );
		if (
			null === $literal
			|| ! $this->is_mysql_zero_numeric_literal_range( $tokens, $literal['start'], $literal['end'] )
			|| ! isset( $tokens[ $literal['end'] ] )
			|| WP_MySQL_Lexer::PLUS_OPERATOR !== $tokens[ $literal['end'] ]->id
		) {
			return false;
		}

		$reference = $this->parse_mysql_column_reference( $tokens, $literal['end'] + 1, $end );
		return null !== $reference
			&& $reference['end'] === $end
			&& null !== $reference['qualifier']
			&& $this->is_mysql_qualified_column_reference_expression( $tokens, $reference['start'], $reference['end'], 'meta_value' );
	}

	/**
	 * Check whether an ORDER BY expression is already valid for the GROUP BY.
	 *
	 * @param WP_MySQL_Token[] $tokens      MySQL lexer token stream.
	 * @param array           $order_item  Parsed ORDER BY item.
	 * @param array           $group_items Parsed GROUP BY item ranges.
	 * @return bool Whether the expression is grouped.
	 */
	private function is_mysql_grouped_order_expression( array $tokens, array $order_item, array $group_items ): bool {
		foreach ( $group_items as $group_item ) {
			if (
				$this->are_mysql_token_ranges_equivalent(
					$tokens,
					$order_item['expression_start'],
					$order_item['expression_end'],
					$group_item['start'],
					$group_item['end']
				)
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build an aggregate-safe ORDER BY item for grouped SELECTs.
	 *
	 * @param array $order_item Parsed ORDER BY item.
	 * @return string PostgreSQL ORDER BY item SQL.
	 */
	private function get_strict_grouped_aggregate_order_sql( array $order_item ): string {
		$aggregate_function = 'DESC' === $order_item['direction'] ? 'MAX' : 'MIN';

		return sprintf(
			'%s(%s) %s',
			$aggregate_function,
			$order_item['sql'],
			$order_item['direction']
		);
	}

	/**
	 * Check whether an expression is a supported column reference.
	 *
	 * @param WP_MySQL_Token[] $tokens           MySQL lexer token stream.
	 * @param int             $start            First expression token.
	 * @param int             $end              Final expression token, exclusive.
	 * @param string          $column_name      Expected column name.
	 * @param string|null     $qualifier_suffix Optional table-name suffix for qualified references.
	 * @param bool            $allow_bare       Whether unqualified references are allowed.
	 * @return bool Whether the expression is a supported column reference.
	 */
	private function is_mysql_column_reference_expression(
		array $tokens,
		int $start,
		int $end,
		string $column_name,
		?string $qualifier_suffix,
		bool $allow_bare
	): bool {
		$bounds = $this->normalize_mysql_expression_bounds( $tokens, $start, $end );
		$start  = $bounds['start'];
		$end    = $bounds['end'];

		if ( $allow_bare && $start + 1 === $end ) {
			$identifier = $this->get_mysql_identifier_token_value( $tokens[ $start ] ?? null );
			return null !== $identifier && strtolower( $identifier ) === strtolower( $column_name );
		}

		if (
			$start + 3 !== $end
			|| ! isset( $tokens[ $start ], $tokens[ $start + 1 ], $tokens[ $start + 2 ] )
			|| WP_MySQL_Lexer::DOT_SYMBOL !== $tokens[ $start + 1 ]->id
		) {
			return false;
		}

		$qualifier = $this->get_mysql_identifier_token_value( $tokens[ $start ] );
		$column    = $this->get_mysql_identifier_token_value( $tokens[ $start + 2 ] );
		if ( null === $qualifier || null === $column || strtolower( $column ) !== strtolower( $column_name ) ) {
			return false;
		}

		return null === $qualifier_suffix
			|| strtolower( $qualifier ) === strtolower( $qualifier_suffix )
			|| '_' . strtolower( $qualifier_suffix ) === substr( strtolower( $qualifier ), -1 * ( strlen( $qualifier_suffix ) + 1 ) );
	}

	/**
	 * Check whether an expression is a qualified column reference.
	 *
	 * @param WP_MySQL_Token[] $tokens      MySQL lexer token stream.
	 * @param int             $start       First expression token.
	 * @param int             $end         Final expression token, exclusive.
	 * @param string          $column_name Expected column name.
	 * @return bool Whether the expression is a qualified column reference.
	 */
	private function is_mysql_qualified_column_reference_expression( array $tokens, int $start, int $end, string $column_name ): bool {
		return $this->is_mysql_column_reference_expression( $tokens, $start, $end, $column_name, null, false );
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
	 * @param bool   $include_limit Whether to preserve the LIMIT/OFFSET clause.
	 * @return string|null PostgreSQL query, or null when the query is unsupported.
	 */
	private function translate_sql_calc_found_rows_select_query( string $query, bool $include_limit = true ): ?string {
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
			$select_end,
			false
		);
		if ( null !== $contextual_sql ) {
			if ( $include_limit && null !== $limit_position ) {
				$contextual_sql .= $this->translate_simple_select_limit_clause_to_postgresql( $tokens, $limit_position, $statement_end );
			}

			return $contextual_sql;
		}

		$sql = 'SELECT ' . $this->translate_mysql_token_sequence_to_postgresql( $tokens, 2, $select_end );
		if ( $include_limit && null !== $limit_position ) {
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
	 * @param WP_MySQL_Token[] $tokens              MySQL lexer token stream.
	 * @param int             $position            Current token position, updated on success.
	 * @param array           $column_lookup       Insert-column lookup by lowercase name.
	 * @param array           $table_column_lookup Table-column metadata lookup by lowercase name.
	 * @return string[]|null PostgreSQL SET assignments, or null when unsupported.
	 */
	private function parse_upsert_update_assignments( array $tokens, int &$position, array $column_lookup, array $table_column_lookup ): ?array {
		$assignments = array();

		while ( isset( $tokens[ $position ] ) ) {
			$target_column = $this->get_mysql_identifier_token_value( $tokens[ $position ] );
			if (
				null === $target_column
				|| ! isset( $table_column_lookup[ strtolower( $target_column ) ] )
			) {
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
	 * Translate a SELECT while applying metadata-backed expression coercions.
	 *
	 * @param WP_MySQL_Token[] $tokens                   MySQL lexer token stream.
	 * @param int             $projection_start         First token after SELECT modifiers to render.
	 * @param int             $statement_end            Final statement token position, exclusive.
	 * @param bool            $require_contextual_change Whether unchanged statements should fall through.
	 * @return string|null PostgreSQL SELECT SQL, or null when no safe contextual translation applies.
	 */
	private function translate_mysql_select_statement_with_integer_string_coercion(
		array $tokens,
		int $projection_start,
		int $statement_end,
		bool $require_contextual_change
	): ?string {
		$where_position = $this->find_top_level_mysql_token(
			$tokens,
			WP_MySQL_Lexer::WHERE_SYMBOL,
			$projection_start,
			$statement_end
		);
		$order_position = $this->find_top_level_mysql_token(
			$tokens,
			WP_MySQL_Lexer::ORDER_SYMBOL,
			$projection_start,
			$statement_end
		);
		if ( null === $where_position && null === $order_position ) {
			return null;
		}

		$first_clause_position = min( array_filter( array( $where_position, $order_position ), 'is_int' ) );
		$from_position         = $this->find_top_level_mysql_token(
			$tokens,
			WP_MySQL_Lexer::FROM_SYMBOL,
			$projection_start,
			$first_clause_position
		);
		if ( null === $from_position ) {
			return null;
		}

		$from_end = $this->find_first_top_level_mysql_token(
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
				WP_MySQL_Lexer::WHERE_SYMBOL,
			),
			$from_position + 1,
			$statement_end
		) ?? $statement_end;

		$scope = $this->get_mysql_select_scope( $tokens, $from_position + 1, $from_end );
		if ( null === $scope ) {
			return null;
		}

		$replacements = array();
		if ( null !== $where_position ) {
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
			if ( $where_sql['changed'] ) {
				$replacements[] = array(
					'start' => $where_position + 1,
					'end'   => $where_end,
					'sql'   => $where_sql['sql'],
				);
			}
		}

		if (
			null !== $order_position
			&& isset( $tokens[ $order_position + 1 ] )
			&& WP_MySQL_Lexer::BY_SYMBOL === $tokens[ $order_position + 1 ]->id
		) {
			$order_end = $this->find_first_top_level_mysql_token(
				$tokens,
				array(
					WP_MySQL_Lexer::FOR_SYMBOL,
					WP_MySQL_Lexer::LIMIT_SYMBOL,
					WP_MySQL_Lexer::LOCK_SYMBOL,
					WP_MySQL_Lexer::PROCEDURE_SYMBOL,
					WP_MySQL_Lexer::UNION_SYMBOL,
				),
				$order_position + 2,
				$statement_end
			) ?? $statement_end;

			$order_sql = $this->translate_mysql_order_by_token_sequence_to_postgresql(
				$tokens,
				$order_position + 2,
				$order_end,
				$scope
			);
			if ( $order_sql['changed'] ) {
				$replacements[] = array(
					'start' => $order_position + 2,
					'end'   => $order_end,
					'sql'   => $order_sql['sql'],
				);
			}
		}

		if ( $require_contextual_change && empty( $replacements ) ) {
			return null;
		}

		return 'SELECT ' . $this->translate_mysql_token_sequence_with_replacements_to_postgresql(
			$tokens,
			$projection_start,
			$statement_end,
			$replacements
		);
	}

	/**
	 * Translate tokens while replacing known bounded token ranges.
	 *
	 * @param WP_MySQL_Token[] $tokens       MySQL lexer token stream.
	 * @param int             $start        First token position.
	 * @param int             $end          Final token position, exclusive.
	 * @param array[]         $replacements Replacement ranges with translated SQL.
	 * @return string PostgreSQL SQL fragment.
	 */
	private function translate_mysql_token_sequence_with_replacements_to_postgresql(
		array $tokens,
		int $start,
		int $end,
		array $replacements
	): string {
		$chunks   = array();
		$position = $start;

		foreach ( $replacements as $replacement ) {
			if ( $position < $replacement['start'] ) {
				$chunks[] = $this->translate_mysql_token_sequence_to_postgresql( $tokens, $position, $replacement['start'] );
			}

			$chunks[] = $replacement['sql'];
			$position = $replacement['end'];
		}

		if ( $position < $end ) {
			$chunks[] = $this->translate_mysql_token_sequence_to_postgresql( $tokens, $position, $end );
		}

		return implode( ' ', array_filter( $chunks, 'strlen' ) );
	}

	/**
	 * Translate ORDER BY items with metadata-backed expression coercions.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First ORDER BY item token position.
	 * @param int             $end    Final ORDER BY token position, exclusive.
	 * @param array           $scope  Statement table scope.
	 * @return array{sql: string, changed: bool} Translated ORDER BY SQL and change flag.
	 */
	private function translate_mysql_order_by_token_sequence_to_postgresql(
		array $tokens,
		int $start,
		int $end,
		array $scope
	): array {
		$order_items = $this->parse_mysql_select_order_by_items( $tokens, $start, $end, array(), $scope );
		if ( null === $order_items ) {
			return array(
				'sql'     => $this->translate_mysql_token_sequence_to_postgresql( $tokens, $start, $end ),
				'changed' => false,
			);
		}

		$changed   = false;
		$order_sql = array();
		foreach ( $order_items as $order_item ) {
			$changed = $changed || $order_item['changed'];

			$item_sql = $order_item['sql'];
			if ( $order_item['direction_explicit'] ) {
				$item_sql .= ' ' . $order_item['direction'];
			}

			$order_sql[] = $item_sql;
		}

		return array(
			'sql'     => $changed
				? implode( ', ', $order_sql )
				: $this->translate_mysql_token_sequence_to_postgresql( $tokens, $start, $end ),
			'changed' => $changed,
		);
	}

	/**
	 * Translate expression tokens with metadata-backed numeric text coercions.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First expression token position.
	 * @param int             $end    Final expression token position, exclusive.
	 * @param array           $scope  Statement table scope.
	 * @return array{sql: string, changed: bool} Translated expression SQL and change flag.
	 */
	private function translate_mysql_expression_token_sequence_to_postgresql(
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

			$translated_expression = $this->translate_mysql_text_column_numeric_addition_to_postgresql(
				$tokens,
				$position,
				$end,
				$scope
			);
			if ( null === $translated_expression ) {
				continue;
			}

			if ( $segment_start < $position ) {
				$chunks[] = $this->translate_mysql_token_sequence_to_postgresql( $tokens, $segment_start, $position );
			}

			$chunks[]      = $translated_expression['sql'];
			$segment_start = $translated_expression['position'] + 1;
			$position      = $translated_expression['position'];
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
					$translated_subquery = $this->translate_mysql_parenthesized_select_predicate_to_postgresql(
						$tokens,
						$position,
						$after_subquery,
						$scope
					);
					if ( null !== $translated_subquery ) {
						if ( $segment_start < $position ) {
							$chunks[] = $this->translate_mysql_token_sequence_to_postgresql( $tokens, $segment_start, $position );
						}

						$chunks[]      = $translated_subquery['sql'];
						$segment_start = $translated_subquery['position'] + 1;
						$position      = $translated_subquery['position'];
						$changed       = true;
						continue;
					}

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
		$truthiness = $this->translate_mysql_numeric_literal_truthiness_predicate_to_postgresql(
			$tokens,
			$position,
			$end
		);
		if ( null !== $truthiness ) {
			return $truthiness;
		}

		$decimal_like = $this->translate_mysql_decimal_cast_like_predicate_to_postgresql(
			$tokens,
			$position,
			$end
		);
		if ( null !== $decimal_like ) {
			return $decimal_like;
		}

		$in_predicate = $this->translate_mysql_integer_column_string_in_predicate_to_postgresql(
			$tokens,
			$position,
			$end,
			$scope
		);
		if ( null !== $in_predicate ) {
			return $in_predicate;
		}

		$comparison = $this->translate_mysql_integer_column_string_comparison_to_postgresql(
			$tokens,
			$position,
			$end,
			$scope
		);
		if ( null !== $comparison ) {
			return $comparison;
		}

		$numeric_comparison = $this->translate_mysql_text_column_numeric_comparison_to_postgresql(
			$tokens,
			$position,
			$end,
			$scope
		);
		if ( null !== $numeric_comparison ) {
			return $numeric_comparison;
		}

		return $this->translate_mysql_metadata_column_reference_to_postgresql(
			$tokens,
			$position,
			$end,
			$scope
		);
	}

	/**
	 * Translate a parenthesized SELECT predicate with inner and outer metadata scope.
	 *
	 * @param WP_MySQL_Token[] $tokens         MySQL lexer token stream.
	 * @param int             $position       Opening parenthesis position.
	 * @param int             $after_subquery Position after the closing parenthesis.
	 * @param array           $outer_scope    Outer statement table scope.
	 * @return array{sql: string, position: int}|null Translation data, or null when unchanged/unsupported.
	 */
	private function translate_mysql_parenthesized_select_predicate_to_postgresql(
		array $tokens,
		int $position,
		int $after_subquery,
		array $outer_scope
	): ?array {
		$select_position = $position + 1;
		$statement_end   = $after_subquery - 1;
		if (
			! isset( $tokens[ $select_position ] )
			|| WP_MySQL_Lexer::SELECT_SYMBOL !== $tokens[ $select_position ]->id
		) {
			return null;
		}

		$projection_start = $select_position + 1;
		$from_position    = $this->find_top_level_mysql_token(
			$tokens,
			WP_MySQL_Lexer::FROM_SYMBOL,
			$projection_start,
			$statement_end
		);
		if ( null === $from_position ) {
			return null;
		}

		$from_end = $this->find_first_top_level_mysql_token(
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
				WP_MySQL_Lexer::WHERE_SYMBOL,
			),
			$from_position + 1,
			$statement_end
		) ?? $statement_end;

		$inner_scope = $this->get_mysql_select_scope( $tokens, $from_position + 1, $from_end );
		if ( null === $inner_scope ) {
			return null;
		}

		$scope          = $this->merge_mysql_inner_and_outer_scopes( $inner_scope, $outer_scope );
		$replacements   = array();
		$where_position = $this->find_top_level_mysql_token(
			$tokens,
			WP_MySQL_Lexer::WHERE_SYMBOL,
			$projection_start,
			$statement_end
		);
		if ( null !== $where_position ) {
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
			if ( $where_sql['changed'] ) {
				$replacements[] = array(
					'start' => $where_position + 1,
					'end'   => $where_end,
					'sql'   => $where_sql['sql'],
				);
			}
		}

		if ( empty( $replacements ) ) {
			return null;
		}

		return array(
			'sql'      => '(' . $this->translate_mysql_token_sequence_with_replacements_to_postgresql( $tokens, $select_position, $statement_end, $replacements ) . ')',
			'position' => $after_subquery - 1,
		);
	}

	/**
	 * Merge SELECT scopes so inner aliases shadow correlated outer aliases.
	 *
	 * @param array $inner_scope Inner SELECT table scope.
	 * @param array $outer_scope Outer SELECT table scope.
	 * @return array Combined scope.
	 */
	private function merge_mysql_inner_and_outer_scopes( array $inner_scope, array $outer_scope ): array {
		$scope = $inner_scope;
		foreach ( $outer_scope['aliases'] as $alias => $table ) {
			if ( ! isset( $scope['aliases'][ $alias ] ) ) {
				$scope['aliases'][ $alias ] = $table;
			}
		}

		if ( ! empty( $outer_scope['unknown'] ) ) {
			$scope['unknown'] = true;
		}

		return $scope;
	}

	/**
	 * Translate a numeric literal used as a standalone boolean predicate.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Candidate predicate position.
	 * @param int             $end      Final predicate token position, exclusive.
	 * @return array{sql: string, position: int}|null Translation data, or null when unsupported.
	 */
	private function translate_mysql_numeric_literal_truthiness_predicate_to_postgresql(
		array $tokens,
		int $position,
		int $end
	): ?array {
		$literal = $this->parse_mysql_numeric_literal( $tokens, $position, $end );
		if ( null === $literal || ! $this->is_mysql_boolean_predicate_literal_context( $tokens, $literal['start'], $literal['end'], $end ) ) {
			return null;
		}

		return array(
			'sql'      => sprintf(
				'(%s <> 0)',
				$this->translate_mysql_token_sequence_to_postgresql( $tokens, $literal['start'], $literal['end'] )
			),
			'position' => $literal['end'] - 1,
		);
	}

	/**
	 * Check whether a numeric literal is a standalone boolean predicate operand.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First literal token.
	 * @param int             $end    Final literal token, exclusive.
	 * @param int             $limit  Final predicate token position, exclusive.
	 * @return bool Whether the literal is in predicate truthiness context.
	 */
	private function is_mysql_boolean_predicate_literal_context( array $tokens, int $start, int $end, int $limit ): bool {
		if ( $this->is_mysql_between_bound_literal_context( $tokens, $start ) ) {
			return false;
		}

		$previous_token_id = $tokens[ $start - 1 ]->id ?? null;
		$next_token_id     = $tokens[ $end ]->id ?? null;

		$left_boundary = 0 === $start
			|| $this->is_mysql_boolean_predicate_left_boundary_token_id( $previous_token_id )
			|| (
				WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $previous_token_id
				&& $this->is_mysql_boolean_predicate_left_boundary_token_id( $tokens[ $start - 2 ]->id ?? null )
			);
		if ( ! $left_boundary ) {
			return false;
		}

		return $end >= $limit || in_array(
			$next_token_id,
			array(
				WP_MySQL_Lexer::AND_SYMBOL,
				WP_MySQL_Lexer::CLOSE_PAR_SYMBOL,
				WP_MySQL_Lexer::OR_SYMBOL,
				WP_MySQL_Lexer::XOR_SYMBOL,
			),
			true
		);
	}

	/**
	 * Check whether a numeric literal belongs to a BETWEEN range.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First literal token.
	 * @return bool Whether the literal is a BETWEEN bound.
	 */
	private function is_mysql_between_bound_literal_context( array $tokens, int $start ): bool {
		$previous_token_id = $tokens[ $start - 1 ]->id ?? null;

		if ( WP_MySQL_Lexer::BETWEEN_SYMBOL === $previous_token_id ) {
			return true;
		}

		if (
			WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $previous_token_id
			&& WP_MySQL_Lexer::BETWEEN_SYMBOL === ( $tokens[ $start - 2 ]->id ?? null )
		) {
			return true;
		}

		$and_position = null;
		if ( WP_MySQL_Lexer::AND_SYMBOL === $previous_token_id ) {
			$and_position = $start - 1;
		} elseif (
			WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $previous_token_id
			&& WP_MySQL_Lexer::AND_SYMBOL === ( $tokens[ $start - 2 ]->id ?? null )
		) {
			$and_position = $start - 2;
		}

		return null !== $and_position && $this->is_mysql_between_upper_bound_separator( $tokens, $and_position );
	}

	/**
	 * Check whether an AND token separates the lower and upper BETWEEN bounds.
	 *
	 * @param WP_MySQL_Token[] $tokens       MySQL lexer token stream.
	 * @param int             $and_position Candidate AND token position.
	 * @return bool Whether the AND token belongs to BETWEEN.
	 */
	private function is_mysql_between_upper_bound_separator( array $tokens, int $and_position ): bool {
		if ( ! isset( $tokens[ $and_position ] ) || WP_MySQL_Lexer::AND_SYMBOL !== $tokens[ $and_position ]->id ) {
			return false;
		}

		$depth = 0;
		for ( $i = $and_position - 1; $i >= 0; $i-- ) {
			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $i ]->id ) {
				++$depth;
				continue;
			}

			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $i ]->id ) {
				--$depth;
				if ( $depth < 0 ) {
					return false;
				}
				continue;
			}

			if ( 0 !== $depth ) {
				continue;
			}

			if ( WP_MySQL_Lexer::BETWEEN_SYMBOL === $tokens[ $i ]->id ) {
				return true;
			}

			if (
				$this->is_mysql_boolean_predicate_left_boundary_token_id( $tokens[ $i ]->id )
				|| WP_MySQL_Lexer::HAVING_SYMBOL === $tokens[ $i ]->id
				|| WP_MySQL_Lexer::ON_SYMBOL === $tokens[ $i ]->id
				|| WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $i ]->id
			) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Check whether a token can precede a standalone boolean predicate operand.
	 *
	 * @param int|null $token_id MySQL token ID.
	 * @return bool Whether the token is a boolean left boundary.
	 */
	private function is_mysql_boolean_predicate_left_boundary_token_id( ?int $token_id ): bool {
		return in_array(
			$token_id,
			array(
				WP_MySQL_Lexer::AND_SYMBOL,
				WP_MySQL_Lexer::NOT_SYMBOL,
				WP_MySQL_Lexer::OR_SYMBOL,
				WP_MySQL_Lexer::WHERE_SYMBOL,
				WP_MySQL_Lexer::XOR_SYMBOL,
			),
			true
		);
	}

	/**
	 * Translate DECIMAL casts used with string-pattern operators.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Candidate predicate position.
	 * @param int             $end      Final predicate token position, exclusive.
	 * @return array{sql: string, position: int}|null Translation data, or null when unsupported.
	 */
	private function translate_mysql_decimal_cast_like_predicate_to_postgresql(
		array $tokens,
		int $position,
		int $end
	): ?array {
		$cast_bounds = $this->get_mysql_decimal_cast_bounds( $tokens, $position, $end );
		if ( null === $cast_bounds ) {
			return null;
		}

		$operator_position = $cast_bounds['close'] + 1;
		$not_sql           = '';
		if (
			isset( $tokens[ $operator_position ], $tokens[ $operator_position + 1 ] )
			&& WP_MySQL_Lexer::NOT_SYMBOL === $tokens[ $operator_position ]->id
			&& WP_MySQL_Lexer::LIKE_SYMBOL === $tokens[ $operator_position + 1 ]->id
		) {
			$not_sql            = ' NOT';
			$operator_position += 1;
		}

		if (
			! isset( $tokens[ $operator_position ], $tokens[ $operator_position + 1 ] )
			|| WP_MySQL_Lexer::LIKE_SYMBOL !== $tokens[ $operator_position ]->id
		) {
			return null;
		}

		$pattern_end = $this->get_mysql_like_pattern_end( $tokens, $operator_position + 1, $end );
		if ( null === $pattern_end ) {
			return null;
		}

		return array(
			'sql'      => sprintf(
				'CAST(%s AS text)%s LIKE %s',
				$this->translate_mysql_token_sequence_to_postgresql( $tokens, $position, $cast_bounds['close'] + 1 ),
				$not_sql,
				$this->translate_mysql_token_sequence_to_postgresql( $tokens, $operator_position + 1, $pattern_end )
			),
			'position' => $pattern_end - 1,
		);
	}

	/**
	 * Get the end of a simple LIKE pattern expression.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Pattern token position.
	 * @param int             $end      Final predicate token position, exclusive.
	 * @return int|null Pattern end position, exclusive.
	 */
	private function get_mysql_like_pattern_end( array $tokens, int $position, int $end ): ?int {
		if ( ! isset( $tokens[ $position ] ) || $position >= $end ) {
			return null;
		}

		$pattern_end = $position + 1;
		if (
			isset( $tokens[ $pattern_end ], $tokens[ $pattern_end + 1 ] )
			&& WP_MySQL_Lexer::ESCAPE_SYMBOL === $tokens[ $pattern_end ]->id
			&& $pattern_end + 1 < $end
		) {
			$pattern_end += 2;
		}

		return $pattern_end;
	}

	/**
	 * Translate metadata-backed qualified column casing when MySQL casing differs.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Candidate predicate position.
	 * @param int             $end      Final predicate token position, exclusive.
	 * @param array           $scope    Statement table scope.
	 * @return array{sql: string, position: int}|null Translation data, or null when unchanged/unsupported.
	 */
	private function translate_mysql_metadata_column_reference_to_postgresql(
		array $tokens,
		int $position,
		int $end,
		array $scope
	): ?array {
		$reference = $this->parse_mysql_column_reference( $tokens, $position, $end );
		if ( null === $reference || null === $reference['qualifier'] ) {
			return null;
		}

		$resolved_column = $this->get_mysql_column_name_for_reference( $reference, $scope );
		if ( null === $resolved_column || $resolved_column === $reference['column'] ) {
			return null;
		}

		return array(
			'sql'      => sprintf(
				'%s.%s',
				$this->translate_mysql_token_to_postgresql( $tokens[ $reference['start'] ], $tokens[ $reference['start'] + 1 ] ?? null ),
				$this->translate_mysql_identifier_value_to_postgresql( $resolved_column )
			),
			'position' => $reference['end'] - 1,
		);
	}

	/**
	 * Resolve the stored MySQL column name for a scoped column reference.
	 *
	 * @param array $reference Parsed column reference.
	 * @param array $scope     Statement table scope.
	 * @return string|null Stored column name, or null when missing/ambiguous.
	 */
	private function get_mysql_column_name_for_reference( array $reference, array $scope ): ?string {
		if ( null === $reference['qualifier'] ) {
			return null;
		}

		$alias = strtolower( $reference['qualifier'] );
		if ( ! isset( $scope['aliases'][ $alias ] ) ) {
			return null;
		}

		$table = $scope['aliases'][ $alias ];
		return $this->get_mysql_table_column_name( $table['schema'], $table['table'], $reference['column'] );
	}

	/**
	 * Get the metadata-backed stored column name for a MySQL column reference.
	 *
	 * @param string $table_schema Metadata schema.
	 * @param string $table_name   Table name.
	 * @param string $column_name  Referenced column name.
	 * @return string|null Stored column name, or null when no safe casing rewrite exists.
	 */
	private function get_mysql_table_column_name(
		string $table_schema,
		string $table_name,
		string $column_name
	): ?string {
		$this->ensure_mysql_schema_metadata_tables();

		$stmt = $this->connection->query(
			sprintf(
				'SELECT column_name FROM %s
				WHERE table_schema = ?
					AND table_name = ?
					AND column_name = ?
				LIMIT 1',
				$this->connection->quote_identifier( self::MYSQL_COLUMN_METADATA_TABLE )
			),
			array( $table_schema, $table_name, $column_name )
		);

		$stored_column_name = $stmt->fetchColumn();
		if ( false !== $stored_column_name ) {
			return (string) $stored_column_name;
		}

		$lowercase_column_name = strtolower( $column_name );
		$stmt                  = $this->connection->query(
			sprintf(
				'SELECT column_name FROM %s
				WHERE table_schema = ?
					AND table_name = ?
					AND column_name = ?
				LIMIT 1',
				$this->connection->quote_identifier( self::MYSQL_COLUMN_METADATA_TABLE )
			),
			array( $table_schema, $table_name, $lowercase_column_name )
		);

		$stored_column_name = $stmt->fetchColumn();
		return false === $stored_column_name ? null : (string) $stored_column_name;
	}

	/**
	 * Translate a stored identifier value for PostgreSQL.
	 *
	 * @param string $identifier Identifier value.
	 * @return string PostgreSQL identifier SQL.
	 */
	private function translate_mysql_identifier_value_to_postgresql( string $identifier ): string {
		return $this->should_quote_bare_mysql_identifier( $identifier )
			? $this->connection->quote_identifier( $identifier )
			: $identifier;
	}

	/**
	 * Get token bounds for a DECIMAL/NUMERIC CAST expression.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position CAST token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{expression_start: int, expression_end: int, close: int}|null Bounds, or null when unsupported.
	 */
	private function get_mysql_decimal_cast_bounds( array $tokens, int $position, int $end ): ?array {
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
			|| ! $this->is_mysql_decimal_cast_type( $tokens, $as_position + 1, $close_position )
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
	 * Check whether a CAST type is MySQL DECIMAL/NUMERIC.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First cast type token.
	 * @param int             $end    Final cast type token, exclusive.
	 * @return bool Whether the type is supported.
	 */
	private function is_mysql_decimal_cast_type( array $tokens, int $start, int $end ): bool {
		if (
			! isset( $tokens[ $start ] )
			|| ! in_array(
				$tokens[ $start ]->id,
				array(
					WP_MySQL_Lexer::DECIMAL_SYMBOL,
					WP_MySQL_Lexer::NUMERIC_SYMBOL,
				),
				true
			)
		) {
			return false;
		}

		if ( $start + 1 === $end ) {
			return true;
		}

		return isset( $tokens[ $start + 1 ] )
			&& WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $start + 1 ]->id
			&& $this->get_mysql_parenthesized_sequence_end( $tokens, $start + 1, $end ) === $end;
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
	 * Translate a text-column comparison against a numeric literal.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Candidate predicate start position.
	 * @param int             $end      Final predicate token position, exclusive.
	 * @param array           $scope    Statement table scope.
	 * @return array{sql: string, position: int}|null Translation data, or null when unsupported.
	 */
	private function translate_mysql_text_column_numeric_comparison_to_postgresql(
		array $tokens,
		int $position,
		int $end,
		array $scope
	): ?array {
		$reference = $this->parse_mysql_column_reference( $tokens, $position, $end );
		if (
			null !== $reference
			&& isset( $tokens[ $reference['end'] ] )
			&& $this->is_mysql_comparison_operator_token( $tokens[ $reference['end'] ] )
			&& $this->is_mysql_text_family_column_reference( $reference, $scope )
		) {
			$literal = $this->parse_mysql_numeric_literal( $tokens, $reference['end'] + 1, $end );
			if ( null !== $literal ) {
				return array(
					'sql'      => sprintf(
						'%s %s %s',
						$this->get_postgresql_mysql_numeric_cast_sql(
							$this->translate_mysql_token_sequence_to_postgresql( $tokens, $reference['start'], $reference['end'] )
						),
						$tokens[ $reference['end'] ]->get_bytes(),
						$this->translate_mysql_token_sequence_to_postgresql( $tokens, $literal['start'], $literal['end'] )
					),
					'position' => $literal['end'] - 1,
				);
			}
		}

		$literal = $this->parse_mysql_numeric_literal( $tokens, $position, $end );
		if (
			null === $literal
			|| ! isset( $tokens[ $literal['end'] ] )
			|| ! $this->is_mysql_comparison_operator_token( $tokens[ $literal['end'] ] )
		) {
			return null;
		}

		$reference = $this->parse_mysql_column_reference( $tokens, $literal['end'] + 1, $end );
		if ( null === $reference || ! $this->is_mysql_text_family_column_reference( $reference, $scope ) ) {
			return null;
		}

		return array(
			'sql'      => sprintf(
				'%s %s %s',
				$this->translate_mysql_token_sequence_to_postgresql( $tokens, $literal['start'], $literal['end'] ),
				$tokens[ $literal['end'] ]->get_bytes(),
				$this->get_postgresql_mysql_numeric_cast_sql(
					$this->translate_mysql_token_sequence_to_postgresql( $tokens, $reference['start'], $reference['end'] )
				)
			),
			'position' => $reference['end'] - 1,
		);
	}

	/**
	 * Translate a text-column numeric addition expression used for sorting.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Candidate expression position.
	 * @param int             $end      Final expression token position, exclusive.
	 * @param array           $scope    Statement table scope.
	 * @return array{sql: string, position: int}|null Translation data, or null when unsupported.
	 */
	private function translate_mysql_text_column_numeric_addition_to_postgresql(
		array $tokens,
		int $position,
		int $end,
		array $scope
	): ?array {
		$reference = $this->parse_mysql_column_reference( $tokens, $position, $end );
		if (
			null !== $reference
			&& isset( $tokens[ $reference['end'] ] )
			&& WP_MySQL_Lexer::PLUS_OPERATOR === $tokens[ $reference['end'] ]->id
			&& $this->is_mysql_text_family_column_reference( $reference, $scope )
		) {
			$literal = $this->parse_mysql_numeric_literal( $tokens, $reference['end'] + 1, $end );
			if (
				null !== $literal
				&& $this->is_mysql_zero_numeric_literal_range( $tokens, $literal['start'], $literal['end'] )
			) {
				return array(
					'sql'      => $this->get_postgresql_mysql_numeric_cast_sql(
						$this->translate_mysql_token_sequence_to_postgresql( $tokens, $reference['start'], $reference['end'] )
					),
					'position' => $literal['end'] - 1,
				);
			}
		}

		$literal = $this->parse_mysql_numeric_literal( $tokens, $position, $end );
		if (
			null === $literal
			|| ! $this->is_mysql_zero_numeric_literal_range( $tokens, $literal['start'], $literal['end'] )
			|| ! isset( $tokens[ $literal['end'] ] )
			|| WP_MySQL_Lexer::PLUS_OPERATOR !== $tokens[ $literal['end'] ]->id
		) {
			return null;
		}

		$reference = $this->parse_mysql_column_reference( $tokens, $literal['end'] + 1, $end );
		if ( null === $reference || ! $this->is_mysql_text_family_column_reference( $reference, $scope ) ) {
			return null;
		}

		return array(
			'sql'      => $this->get_postgresql_mysql_numeric_cast_sql(
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
	 * Check whether a column reference resolves to one text-family MySQL column.
	 *
	 * @param array $reference Parsed column reference.
	 * @param array $scope     Statement table scope.
	 * @return bool Whether the reference is a known text column.
	 */
	private function is_mysql_text_family_column_reference( array $reference, array $scope ): bool {
		$column_type = $this->get_mysql_column_type_for_reference( $reference, $scope );
		return null !== $column_type && $this->is_mysql_text_family_column_type( $column_type );
	}

	/**
	 * Check whether a MySQL column type belongs to the text family.
	 *
	 * @param string $column_type MySQL column type metadata.
	 * @return bool Whether the type stores textual data.
	 */
	private function is_mysql_text_family_column_type( string $column_type ): bool {
		return in_array(
			$this->get_base_mysql_dml_column_type( $column_type ),
			array(
				'char',
				'longtext',
				'mediumtext',
				'text',
				'tinytext',
				'varchar',
			),
			true
		);
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
	 * Parse a numeric literal, including an optional unary sign.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Literal start position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{start: int, end: int}|null Numeric literal bounds.
	 */
	private function parse_mysql_numeric_literal( array $tokens, int $position, int $end ): ?array {
		if ( ! isset( $tokens[ $position ] ) || $position >= $end ) {
			return null;
		}

		if (
			(
				WP_MySQL_Lexer::PLUS_OPERATOR === $tokens[ $position ]->id
				|| WP_MySQL_Lexer::MINUS_OPERATOR === $tokens[ $position ]->id
			)
			&& isset( $tokens[ $position + 1 ] )
			&& $position + 1 < $end
			&& $this->is_mysql_numeric_literal_token( $tokens[ $position + 1 ] )
		) {
			return array(
				'start' => $position,
				'end'   => $position + 2,
			);
		}

		if ( $this->is_mysql_numeric_literal_token( $tokens[ $position ] ) ) {
			return array(
				'start' => $position,
				'end'   => $position + 1,
			);
		}

		return null;
	}

	/**
	 * Check whether a numeric literal range represents zero.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First literal token.
	 * @param int             $end    Final literal token, exclusive.
	 * @return bool Whether the literal is numeric zero.
	 */
	private function is_mysql_zero_numeric_literal_range( array $tokens, int $start, int $end ): bool {
		if ( ! isset( $tokens[ $start ] ) ) {
			return false;
		}

		if (
			$start + 2 === $end
			&& (
				WP_MySQL_Lexer::PLUS_OPERATOR === $tokens[ $start ]->id
				|| WP_MySQL_Lexer::MINUS_OPERATOR === $tokens[ $start ]->id
			)
		) {
			++$start;
		}

		return $start + 1 === $end
			&& $this->is_mysql_numeric_literal_token( $tokens[ $start ] )
			&& 0.0 === (float) $tokens[ $start ]->get_value();
	}

	/**
	 * Check whether a token is a numeric literal.
	 *
	 * @param WP_MySQL_Token $token MySQL token.
	 * @return bool Whether the token is a numeric literal.
	 */
	private function is_mysql_numeric_literal_token( WP_MySQL_Token $token ): bool {
		return in_array(
			$token->id,
			array(
				WP_MySQL_Lexer::DECIMAL_NUMBER,
				WP_MySQL_Lexer::FLOAT_NUMBER,
				WP_MySQL_Lexer::INT_NUMBER,
				WP_MySQL_Lexer::LONG_NUMBER,
				WP_MySQL_Lexer::ULONGLONG_NUMBER,
			),
			true
		);
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
				$translated_fragment = $this->translate_mysql_character_cast_to_postgresql( $tokens, $i, $end );
			}
			if ( null === $translated_fragment ) {
				$translated_fragment = $this->translate_mysql_binary_cast_to_postgresql( $tokens, $i, $end );
			}
			if ( null === $translated_fragment ) {
				$translated_fragment = $this->translate_mysql_regexp_operator_to_postgresql( $tokens, $i, $end );
			}
			if ( null === $translated_fragment ) {
				$translated_fragment = $this->translate_mysql_rand_function_to_postgresql( $tokens, $i, $end );
			}
			if ( null === $translated_fragment ) {
				$translated_fragment = $this->translate_mysql_date_arithmetic_to_postgresql( $tokens, $i, $end );
			}
			if ( null === $translated_fragment ) {
				$translated_fragment = $this->translate_mysql_week_function_to_postgresql( $tokens, $i, $end );
			}
			if ( null === $translated_fragment ) {
				$translated_fragment = $this->translate_mysql_weekday_index_function_to_postgresql( $tokens, $i, $end );
			}
			if ( null === $translated_fragment ) {
				$translated_fragment = $this->translate_mysql_date_format_to_postgresql( $tokens, $i, $end );
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
	 * Get PostgreSQL SQL for MySQL-compatible decimal text coercion.
	 *
	 * MySQL text values in numeric expression contexts use the leading numeric
	 * prefix, including decimal and exponent forms, or zero when no prefix exists.
	 *
	 * @param string $expression_sql PostgreSQL expression SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_postgresql_mysql_numeric_cast_sql( string $expression_sql ): string {
		$expression_text_sql = sprintf( 'CAST(%s AS text)', $expression_sql );
		$substring_sql       = array();
		$numeric_patterns    = array(
			'^[[:space:]]*[+-]?[0-9]+[.][0-9]*[eE][+-]?[0-9]+',
			'^[[:space:]]*[+-]?[.][0-9]+[eE][+-]?[0-9]+',
			'^[[:space:]]*[+-]?[0-9]+[eE][+-]?[0-9]+',
			'^[[:space:]]*[+-]?[0-9]+[.][0-9]*',
			'^[[:space:]]*[+-]?[.][0-9]+',
			'^[[:space:]]*[+-]?[0-9]+',
		);

		foreach ( $numeric_patterns as $pattern ) {
			$substring_sql[] = sprintf(
				'SUBSTRING(%1$s, %2$s)',
				$expression_text_sql,
				$this->connection->quote( $pattern )
			);
		}

		return sprintf(
			'CASE WHEN %1$s IS NULL THEN NULL ELSE CAST(COALESCE(%2$s, \'0\') AS numeric) END',
			$expression_text_sql,
			implode( ', ', $substring_sql )
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
	 * Translate MySQL CAST(expr AS CHAR) to PostgreSQL text.
	 *
	 * PostgreSQL's CHAR without length is character(1), while MySQL CHAR casts
	 * are used by WordPress as text ordering expressions.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position CAST token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{sql: string, token_id: int, position: int}|null Translation data, or null when unsupported.
	 */
	private function translate_mysql_character_cast_to_postgresql( array $tokens, int $position, int $end ): ?array {
		$bounds = $this->get_mysql_character_cast_bounds( $tokens, $position, $end );
		if ( null === $bounds ) {
			return null;
		}

		$expression_sql = $this->translate_mysql_token_sequence_to_postgresql(
			$tokens,
			$bounds['expression_start'],
			$bounds['expression_end']
		);

		return array(
			'sql'      => sprintf( 'CAST(%s AS text)', $expression_sql ),
			'token_id' => WP_MySQL_Lexer::CAST_SYMBOL,
			'position' => $bounds['close'],
		);
	}

	/**
	 * Get token bounds for a supported MySQL character CAST expression.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position CAST token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{expression_start: int, expression_end: int, close: int}|null Bounds, or null when unsupported.
	 */
	private function get_mysql_character_cast_bounds( array $tokens, int $position, int $end ): ?array {
		$bounds = $this->normalize_mysql_expression_bounds( $tokens, $position, $end );
		if ( $bounds['start'] !== $position ) {
			return null;
		}

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
			|| ! $this->is_mysql_character_cast_type( $tokens, $as_position + 1, $close_position )
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
	 * Check whether a CAST type is MySQL CHAR.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First cast type token.
	 * @param int             $end    Final cast type token, exclusive.
	 * @return bool Whether the type is supported.
	 */
	private function is_mysql_character_cast_type( array $tokens, int $start, int $end ): bool {
		return $start + 1 === $end
			&& isset( $tokens[ $start ] )
			&& WP_MySQL_Lexer::CHAR_SYMBOL === $tokens[ $start ]->id;
	}

	/**
	 * Translate MySQL CAST(expr AS BINARY) to PostgreSQL text.
	 *
	 * PostgreSQL regex operators work on text, so keep supported binary regex
	 * predicates executable without broadening this lane to bytea emulation.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position CAST token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{sql: string, token_id: int, position: int}|null Translation data, or null when unsupported.
	 */
	private function translate_mysql_binary_cast_to_postgresql( array $tokens, int $position, int $end ): ?array {
		$bounds = $this->get_mysql_binary_cast_bounds( $tokens, $position, $end );
		if ( null === $bounds ) {
			return null;
		}

		$expression_sql = $this->translate_mysql_token_sequence_to_postgresql(
			$tokens,
			$bounds['expression_start'],
			$bounds['expression_end']
		);

		return array(
			'sql'      => sprintf( 'CAST(%s AS text)', $expression_sql ),
			'token_id' => WP_MySQL_Lexer::CAST_SYMBOL,
			'position' => $bounds['close'],
		);
	}

	/**
	 * Get token bounds for a supported MySQL binary CAST expression.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position CAST token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{expression_start: int, expression_end: int, close: int}|null Bounds, or null when unsupported.
	 */
	private function get_mysql_binary_cast_bounds( array $tokens, int $position, int $end ): ?array {
		$bounds = $this->normalize_mysql_expression_bounds( $tokens, $position, $end );
		if ( $bounds['start'] !== $position ) {
			return null;
		}

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
			|| ! $this->is_mysql_binary_cast_type( $tokens, $as_position + 1, $close_position )
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
	 * Check whether a CAST type is MySQL BINARY.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First cast type token.
	 * @param int             $end    Final cast type token, exclusive.
	 * @return bool Whether the type is supported.
	 */
	private function is_mysql_binary_cast_type( array $tokens, int $start, int $end ): bool {
		return $start + 1 === $end
			&& isset( $tokens[ $start ] )
			&& WP_MySQL_Lexer::BINARY_SYMBOL === $tokens[ $start ]->id;
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
		) {
			$is_binary = $this->is_mysql_regexp_binary_predicate( $tokens, $position + 1, $end );

			return array(
				'sql'      => $is_binary ? '~' : '~*',
				'token_id' => WP_MySQL_Lexer::REGEXP_SYMBOL,
				'position' => $is_binary ? $position + 1 : $position,
			);
		}

		if (
			isset( $tokens[ $position + 1 ] )
			&& WP_MySQL_Lexer::NOT_SYMBOL === $tokens[ $position ]->id
			&& WP_MySQL_Lexer::REGEXP_SYMBOL === $tokens[ $position + 1 ]->id
		) {
			$is_binary = $this->is_mysql_regexp_binary_predicate( $tokens, $position + 2, $end );

			return array(
				'sql'      => $is_binary ? '!~' : '!~*',
				'token_id' => WP_MySQL_Lexer::REGEXP_SYMBOL,
				'position' => $is_binary ? $position + 2 : $position + 1,
			);
		}

		return null;
	}

	/**
	 * Check whether a REGEXP predicate starts with the BINARY modifier.
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
	 * Translate MySQL DATE_ADD(expr, INTERVAL value unit) and DATE_SUB(...) calls.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Function token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{sql: string, token_id: int, position: int}|null Translation data, or null when unsupported.
	 */
	private function translate_mysql_date_arithmetic_to_postgresql( array $tokens, int $position, int $end ): ?array {
		$bounds = $this->get_mysql_date_arithmetic_function_bounds( $tokens, $position, $end );
		if ( null === $bounds ) {
			return null;
		}

		$expression_sql = $this->translate_mysql_token_sequence_to_postgresql(
			$tokens,
			$bounds['expression_start'],
			$bounds['expression_end']
		);
		$value_sql      = $this->translate_mysql_token_sequence_to_postgresql(
			$tokens,
			$bounds['interval_value_start'],
			$bounds['interval_value_end']
		);

		return array(
			'sql'      => sprintf(
				'(%1$s %2$s (%3$s * INTERVAL %4$s))',
				$this->get_postgresql_zero_date_safe_timestamp_sql( $expression_sql ),
				$bounds['operator'],
				$this->get_postgresql_mysql_interval_value_sql( $value_sql ),
				$this->connection->quote( '1 ' . $bounds['interval_unit'] )
			),
			'token_id' => $tokens[ $position ]->id,
			'position' => $bounds['close'],
		);
	}

	/**
	 * Get token bounds for a supported MySQL DATE_ADD/DATE_SUB expression.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Function token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{operator: string, expression_start: int, expression_end: int, interval_value_start: int, interval_value_end: int, interval_unit: string, close: int}|null Bounds, or null when unsupported.
	 */
	private function get_mysql_date_arithmetic_function_bounds( array $tokens, int $position, int $end ): ?array {
		if (
			! isset( $tokens[ $position ], $tokens[ $position + 1 ] )
			|| ! in_array(
				$tokens[ $position ]->id,
				array(
					WP_MySQL_Lexer::DATE_ADD_SYMBOL,
					WP_MySQL_Lexer::DATE_SUB_SYMBOL,
				),
				true
			)
			|| WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $position + 1 ]->id
		) {
			return null;
		}

		$after_close = $this->get_mysql_parenthesized_sequence_end( $tokens, $position + 1, $end );
		if ( null === $after_close ) {
			return null;
		}

		$arguments = $this->split_top_level_mysql_arguments( $tokens, $position + 2, $after_close - 1 );
		if ( null === $arguments || 2 !== count( $arguments ) ) {
			return null;
		}

		$interval = $this->get_mysql_interval_argument_bounds( $tokens, $arguments[1]['start'], $arguments[1]['end'] );
		if ( null === $interval ) {
			return null;
		}

		return array(
			'operator'             => WP_MySQL_Lexer::DATE_SUB_SYMBOL === $tokens[ $position ]->id ? '-' : '+',
			'expression_start'     => $arguments[0]['start'],
			'expression_end'       => $arguments[0]['end'],
			'interval_value_start' => $interval['value_start'],
			'interval_value_end'   => $interval['value_end'],
			'interval_unit'        => $interval['unit'],
			'close'                => $after_close - 1,
		);
	}

	/**
	 * Get token bounds for a supported MySQL INTERVAL value unit argument.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First interval token position.
	 * @param int             $end    Final interval token position, exclusive.
	 * @return array{value_start: int, value_end: int, unit: string}|null Bounds, or null when unsupported.
	 */
	private function get_mysql_interval_argument_bounds( array $tokens, int $start, int $end ): ?array {
		if (
			$start + 3 > $end
			|| ! isset( $tokens[ $start ], $tokens[ $end - 1 ] )
			|| WP_MySQL_Lexer::INTERVAL_SYMBOL !== $tokens[ $start ]->id
		) {
			return null;
		}

		$unit = $this->get_postgresql_simple_interval_unit( $tokens[ $end - 1 ] );
		if ( null === $unit ) {
			return null;
		}

		return array(
			'value_start' => $start + 1,
			'value_end'   => $end - 1,
			'unit'        => $unit,
		);
	}

	/**
	 * Get a PostgreSQL interval unit for supported simple MySQL interval units.
	 *
	 * @param WP_MySQL_Token $token MySQL interval unit token.
	 * @return string|null PostgreSQL interval unit, or null when unsupported.
	 */
	private function get_postgresql_simple_interval_unit( WP_MySQL_Token $token ): ?string {
		switch ( $token->id ) {
			case WP_MySQL_Lexer::SECOND_SYMBOL:
				return 'second';

			case WP_MySQL_Lexer::MINUTE_SYMBOL:
				return 'minute';

			case WP_MySQL_Lexer::HOUR_SYMBOL:
				return 'hour';

			case WP_MySQL_Lexer::DAY_SYMBOL:
				return 'day';

			case WP_MySQL_Lexer::WEEK_SYMBOL:
				return 'week';

			case WP_MySQL_Lexer::MONTH_SYMBOL:
				return 'month';

			case WP_MySQL_Lexer::YEAR_SYMBOL:
				return 'year';
		}

		return null;
	}

	/**
	 * Get PostgreSQL SQL for a MySQL-compatible interval value.
	 *
	 * @param string $value_sql PostgreSQL expression SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_postgresql_mysql_interval_value_sql( string $value_sql ): string {
		return sprintf( 'CAST(%s AS double precision)', $this->get_postgresql_mysql_integer_cast_sql( $value_sql ) );
	}

	/**
	 * Translate MySQL WEEK(expr, 1) calls to PostgreSQL.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Function token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{sql: string, token_id: int, position: int}|null Translation data, or null when unsupported.
	 */
	private function translate_mysql_week_function_to_postgresql( array $tokens, int $position, int $end ): ?array {
		$bounds = $this->get_mysql_week_function_bounds( $tokens, $position, $end );
		if ( null === $bounds ) {
			return null;
		}

		$expression_sql = $this->translate_mysql_token_sequence_to_postgresql(
			$tokens,
			$bounds['expression_start'],
			$bounds['expression_end']
		);

		return array(
			'sql'      => $this->get_postgresql_mysql_week_mode_one_sql( $expression_sql ),
			'token_id' => WP_MySQL_Lexer::CASE_SYMBOL,
			'position' => $bounds['close'],
		);
	}

	/**
	 * Get token bounds for supported MySQL WEEK(expr, mode) calls.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Function token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{expression_start: int, expression_end: int, close: int}|null Bounds, or null when unsupported.
	 */
	private function get_mysql_week_function_bounds( array $tokens, int $position, int $end ): ?array {
		if (
			! isset( $tokens[ $position ], $tokens[ $position + 1 ] )
			|| WP_MySQL_Lexer::WEEK_SYMBOL !== $tokens[ $position ]->id
			|| WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $position + 1 ]->id
		) {
			return null;
		}

		$after_close = $this->get_mysql_parenthesized_sequence_end( $tokens, $position + 1, $end );
		if ( null === $after_close ) {
			return null;
		}

		$arguments = $this->split_top_level_mysql_arguments( $tokens, $position + 2, $after_close - 1 );
		if (
			null === $arguments
			|| 2 !== count( $arguments )
			|| ! $this->is_mysql_week_mode_one_argument( $tokens, $arguments[1]['start'], $arguments[1]['end'] )
		) {
			return null;
		}

		return array(
			'expression_start' => $arguments[0]['start'],
			'expression_end'   => $arguments[0]['end'],
			'close'            => $after_close - 1,
		);
	}

	/**
	 * Check whether a WEEK() mode argument is the supported MySQL mode 1.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @param int             $start  First mode token.
	 * @param int             $end    Final mode token, exclusive.
	 * @return bool Whether the mode is supported.
	 */
	private function is_mysql_week_mode_one_argument( array $tokens, int $start, int $end ): bool {
		return $start + 1 === $end
			&& isset( $tokens[ $start ] )
			&& WP_MySQL_Lexer::INT_NUMBER === $tokens[ $start ]->id
			&& '1' === $tokens[ $start ]->get_value();
	}

	/**
	 * Get PostgreSQL SQL for MySQL WEEK(expr, 1).
	 *
	 * MySQL mode 1 is Monday-first and returns week numbers in the given year,
	 * using 0 for dates before that year's first ISO-like week.
	 *
	 * @param string $expression_sql PostgreSQL expression SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_postgresql_mysql_week_mode_one_sql( string $expression_sql ): string {
		$timestamp_sql        = $this->get_postgresql_zero_date_safe_timestamp_sql( $expression_sql );
		$week_start_sql       = sprintf( "DATE_TRUNC('week', %s)", $timestamp_sql );
		$year_start_sql       = sprintf( "DATE_TRUNC('year', %s)", $timestamp_sql );
		$first_week_start_sql = sprintf(
			"(CASE WHEN EXTRACT(ISODOW FROM %1\$s) <= 4 THEN DATE_TRUNC('week', %1\$s) ELSE DATE_TRUNC('week', %1\$s) + INTERVAL '1 week' END)",
			$year_start_sql
		);

		return sprintf(
			'CASE WHEN %1$s IS NULL THEN NULL WHEN %2$s < %3$s THEN 0 ELSE CAST(FLOOR(EXTRACT(EPOCH FROM (%2$s - %3$s)) / 604800) AS integer) + 1 END',
			$timestamp_sql,
			$week_start_sql,
			$first_week_start_sql
		);
	}

	/**
	 * Translate MySQL DAYOFWEEK(expr) and WEEKDAY(expr) calls to PostgreSQL.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Function token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{sql: string, token_id: int, position: int}|null Translation data, or null when unsupported.
	 */
	private function translate_mysql_weekday_index_function_to_postgresql( array $tokens, int $position, int $end ): ?array {
		$bounds = $this->get_mysql_weekday_index_function_bounds( $tokens, $position, $end );
		if ( null === $bounds ) {
			return null;
		}

		$expression_sql = $this->translate_mysql_token_sequence_to_postgresql(
			$tokens,
			$bounds['expression_start'],
			$bounds['expression_end']
		);

		return array(
			'sql'      => $this->get_postgresql_mysql_weekday_index_sql( $bounds['function'], $expression_sql ),
			'token_id' => WP_MySQL_Lexer::CAST_SYMBOL,
			'position' => $bounds['close'],
		);
	}

	/**
	 * Get token bounds for supported MySQL weekday index functions.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Function token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{function: string, expression_start: int, expression_end: int, close: int}|null Bounds, or null when unsupported.
	 */
	private function get_mysql_weekday_index_function_bounds( array $tokens, int $position, int $end ): ?array {
		$function_name = $this->get_mysql_identifier_token_value( $tokens[ $position ] ?? null );
		if ( null === $function_name ) {
			return null;
		}

		$function_name = strtolower( $function_name );
		if ( 'dayofweek' !== $function_name && 'weekday' !== $function_name ) {
			return null;
		}

		$bounds = $this->get_mysql_function_call_bounds( $tokens, $position, $end, $function_name );
		if ( null === $bounds ) {
			return null;
		}

		$arguments = $this->split_top_level_mysql_arguments( $tokens, $bounds['arguments_start'], $bounds['arguments_end'] );
		if ( null === $arguments || 1 !== count( $arguments ) ) {
			return null;
		}

		return array(
			'function'         => $function_name,
			'expression_start' => $arguments[0]['start'],
			'expression_end'   => $arguments[0]['end'],
			'close'            => $bounds['close'],
		);
	}

	/**
	 * Get PostgreSQL SQL for a MySQL weekday index function.
	 *
	 * @param string $function_name  Lowercase MySQL function name.
	 * @param string $expression_sql PostgreSQL expression SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_postgresql_mysql_weekday_index_sql( string $function_name, string $expression_sql ): string {
		$timestamp_sql = $this->get_postgresql_zero_date_safe_timestamp_sql( $expression_sql );

		if ( 'dayofweek' === $function_name ) {
			return sprintf( 'CAST(EXTRACT(DOW FROM %s) AS integer) + 1', $timestamp_sql );
		}

		return sprintf( 'CAST(EXTRACT(ISODOW FROM %s) AS integer) - 1', $timestamp_sql );
	}

	/**
	 * Translate supported MySQL DATE_FORMAT(expr, format) calls to PostgreSQL.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Function token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{sql: string, token_id: int, position: int}|null Translation data, or null when unsupported.
	 */
	private function translate_mysql_date_format_to_postgresql( array $tokens, int $position, int $end ): ?array {
		$bounds = $this->get_mysql_date_format_bounds( $tokens, $position, $end );
		if ( null === $bounds ) {
			return null;
		}

		$expression_sql = $this->translate_mysql_token_sequence_to_postgresql(
			$tokens,
			$bounds['expression_start'],
			$bounds['expression_end']
		);
		$sql            = $this->get_postgresql_mysql_date_format_sql( $bounds['format'], $expression_sql );
		if ( null === $sql ) {
			return null;
		}

		return array(
			'sql'      => $sql,
			'token_id' => WP_MySQL_Lexer::CASE_SYMBOL,
			'position' => $bounds['close'],
		);
	}

	/**
	 * Get token bounds for supported MySQL DATE_FORMAT(expr, format) calls.
	 *
	 * @param WP_MySQL_Token[] $tokens   MySQL lexer token stream.
	 * @param int             $position Function token position.
	 * @param int             $end      Final token position, exclusive.
	 * @return array{format: string, expression_start: int, expression_end: int, close: int}|null Bounds, or null when unsupported.
	 */
	private function get_mysql_date_format_bounds( array $tokens, int $position, int $end ): ?array {
		$bounds = $this->get_mysql_function_call_bounds( $tokens, $position, $end, 'date_format' );
		if ( null === $bounds ) {
			return null;
		}

		$arguments = $this->split_top_level_mysql_arguments( $tokens, $bounds['arguments_start'], $bounds['arguments_end'] );
		if (
			null === $arguments
			|| 2 !== count( $arguments )
			|| ! $this->is_mysql_string_literal_range( $tokens, $arguments[1]['start'], $arguments[1]['end'] )
		) {
			return null;
		}

		return array(
			'format'           => $tokens[ $arguments[1]['start'] ]->get_value(),
			'expression_start' => $arguments[0]['start'],
			'expression_end'   => $arguments[0]['end'],
			'close'            => $bounds['close'],
		);
	}

	/**
	 * Get PostgreSQL SQL for a supported MySQL DATE_FORMAT() format.
	 *
	 * @param string $format         MySQL DATE_FORMAT format.
	 * @param string $expression_sql PostgreSQL expression SQL.
	 * @return string|null PostgreSQL expression SQL, or null when unsupported.
	 */
	private function get_postgresql_mysql_date_format_sql( string $format, string $expression_sql ): ?string {
		switch ( $format ) {
			case '%H.%i':
				return $this->get_postgresql_mysql_date_format_hour_minute_sql( $expression_sql );

			case '%Y-%m-%d':
				return $this->get_postgresql_mysql_date_format_year_month_day_sql( $expression_sql );
		}

		return null;
	}

	/**
	 * Get PostgreSQL SQL for MySQL DATE_FORMAT(expr, '%H.%i').
	 *
	 * @param string $expression_sql PostgreSQL expression SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_postgresql_mysql_date_format_hour_minute_sql( string $expression_sql ): string {
		$expression_text_sql  = sprintf( 'CAST(%s AS text)', $expression_sql );
		$zero_date_condition  = $this->get_postgresql_zero_date_condition_sql( $expression_text_sql );
		$date_time_pattern    = "'^[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}'";
		$zero_date_format_sql = sprintf(
			'CASE WHEN %1$s ~ %2$s THEN CAST(SUBSTRING(%1$s FROM 12 FOR 2) || \'.\' || SUBSTRING(%1$s FROM 15 FOR 2) AS double precision) ELSE 0 END',
			$expression_text_sql,
			$date_time_pattern
		);

		return sprintf(
			'CASE WHEN %1$s THEN %2$s ELSE CAST(TO_CHAR(%3$s, %4$s) AS double precision) END',
			$zero_date_condition,
			$zero_date_format_sql,
			$this->get_postgresql_zero_date_safe_timestamp_sql( $expression_sql ),
			$this->connection->quote( 'HH24.MI' )
		);
	}

	/**
	 * Get PostgreSQL SQL for MySQL DATE_FORMAT(expr, '%Y-%m-%d').
	 *
	 * @param string $expression_sql PostgreSQL expression SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_postgresql_mysql_date_format_year_month_day_sql( string $expression_sql ): string {
		$expression_text_sql = sprintf( 'CAST(%s AS text)', $expression_sql );

		return sprintf(
			'CASE WHEN %1$s THEN SUBSTRING(%2$s FROM 1 FOR 10) ELSE TO_CHAR(%3$s, %4$s) END',
			$this->get_postgresql_zero_date_condition_sql( $expression_text_sql ),
			$expression_text_sql,
			$this->get_postgresql_zero_date_safe_timestamp_sql( $expression_sql ),
			$this->connection->quote( 'YYYY-MM-DD' )
		);
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
		$expression_text_sql = sprintf( 'CAST(%s AS text)', $expression_sql );
		$zero_date_condition = $this->get_postgresql_zero_date_condition_sql( $expression_text_sql );

		return sprintf(
			'CASE WHEN %1$s THEN %2$s ELSE CAST(EXTRACT(%3$s FROM %4$s) AS integer) END',
			$zero_date_condition,
			$this->get_postgresql_zero_date_extract_part_sql( $unit, $expression_text_sql ),
			$unit,
			$this->get_postgresql_zero_date_safe_timestamp_sql( $expression_sql )
		);
	}

	/**
	 * Get PostgreSQL SQL that casts a MySQL date/time expression without casting zero dates.
	 *
	 * @param string $expression_sql PostgreSQL expression SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_postgresql_zero_date_safe_timestamp_sql( string $expression_sql ): string {
		$expression_text_sql = sprintf( 'CAST(%s AS text)', $expression_sql );

		return sprintf(
			'CAST(CASE WHEN %1$s THEN NULL ELSE %2$s END AS timestamp)',
			$this->get_postgresql_zero_date_condition_sql( $expression_text_sql ),
			$expression_text_sql
		);
	}

	/**
	 * Get a condition that detects MySQL zero or partial-zero date strings.
	 *
	 * @param string $expression_text_sql PostgreSQL expression cast to text.
	 * @return string PostgreSQL condition SQL.
	 */
	private function get_postgresql_zero_date_condition_sql( string $expression_text_sql ): string {
		$date_text_pattern = "'^[0-9]{4}-[0-9]{2}-[0-9]{2}'";

		return sprintf(
			'%1$s ~ %2$s AND (SUBSTRING(%1$s FROM 1 FOR 4) = \'0000\' OR SUBSTRING(%1$s FROM 6 FOR 2) = \'00\' OR SUBSTRING(%1$s FROM 9 FOR 2) = \'00\')',
			$expression_text_sql,
			$date_text_pattern
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
	 * Check whether a token's semantic value matches a keyword or identifier.
	 *
	 * @param WP_MySQL_Token|null $token MySQL token.
	 * @param string              $value Expected value.
	 * @return bool Whether the token value matches.
	 */
	private function is_mysql_token_value( ?WP_MySQL_Token $token, string $value ): bool {
		if ( null === $token ) {
			return false;
		}

		return strtolower( $token->get_value() ) === strtolower( $value );
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

			if ( null !== $this->get_mysql_binary_cast_bounds( $tokens, $i, $end ) ) {
				return true;
			}

			if ( null !== $this->translate_mysql_regexp_operator_to_postgresql( $tokens, $i, $end ) ) {
				return true;
			}

			if ( null !== $this->get_mysql_function_call_bounds( $tokens, $i, $end, 'rand' ) ) {
				return true;
			}

			if ( null !== $this->get_mysql_date_arithmetic_function_bounds( $tokens, $i, $end ) ) {
				return true;
			}

			if ( null !== $this->get_mysql_week_function_bounds( $tokens, $i, $end ) ) {
				return true;
			}

			if ( null !== $this->get_mysql_weekday_index_function_bounds( $tokens, $i, $end ) ) {
				return true;
			}

			if ( null !== $this->get_mysql_date_format_bounds( $tokens, $i, $end ) ) {
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
