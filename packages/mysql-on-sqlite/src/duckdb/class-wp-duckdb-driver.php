<?php declare(strict_types = 1);

/*
 * The DuckDB result wrapper mirrors PDO fetch constants without using PDO as a
 * database driver.
 *
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */

/**
 * Focused MySQL-emulation driver for DuckDB.
 *
 * This class intentionally supports a small, explicit subset of MySQL SQL and
 * throws WP_DuckDB_Driver_Exception for statements outside that subset.
 */
class WP_DuckDB_Driver {
	const MYSQL_GRAMMAR_PATH                        = __DIR__ . '/../mysql/mysql-grammar.php';
	const DEFAULT_DATABASE                          = 'wp';
	const DEFAULT_MYSQL_VERSION                     = 80038;
	const SEQUENCE_PREFIX                           = 'wp_duckdb_ai_';
	const INDEX_PREFIX                              = 'wp_duckdb_idx_';
	const INDEX_METADATA_TABLE                      = '__wp_duckdb_index_metadata';
	const COLUMN_METADATA_TABLE                     = '__wp_duckdb_column_metadata';
	const TABLE_METADATA_TABLE                      = '__wp_duckdb_table_metadata';
	const CHECK_METADATA_TABLE                      = '__wp_duckdb_check_metadata';
	const FOREIGN_KEY_METADATA_TABLE                = '__wp_duckdb_foreign_key_metadata';
	const TEMP_INDEX_METADATA_TABLE                 = '__wp_duckdb_temp_index_metadata';
	const TEMP_COLUMN_METADATA_TABLE                = '__wp_duckdb_temp_column_metadata';
	const TEMP_TABLE_METADATA_TABLE                 = '__wp_duckdb_temp_table_metadata';
	const TEMP_CHECK_METADATA_TABLE                 = '__wp_duckdb_temp_check_metadata';
	const TEMP_FOREIGN_KEY_METADATA_TABLE           = '__wp_duckdb_temp_foreign_key_metadata';
	const INFO_SCHEMA_TABLES_TABLE                  = '__wp_duckdb_information_schema_tables';
	const INFO_SCHEMA_COLUMNS_TABLE                 = '__wp_duckdb_information_schema_columns';
	const INFO_SCHEMA_STATISTICS_TABLE              = '__wp_duckdb_information_schema_statistics';
	const INFO_SCHEMA_TABLE_CONSTRAINTS_TABLE       = '__wp_duckdb_information_schema_table_constraints';
	const INFO_SCHEMA_KEY_COLUMN_USAGE_TABLE        = '__wp_duckdb_information_schema_key_column_usage';
	const INFO_SCHEMA_REFERENTIAL_CONSTRAINTS_TABLE = '__wp_duckdb_information_schema_referential_constraints';
	const INFO_SCHEMA_CHECK_CONSTRAINTS_TABLE       = '__wp_duckdb_information_schema_check_constraints';

	const SUPPORTED_SESSION_SYSTEM_VARIABLES = array(
		'autocommit'         => true,
		'big_tables'         => true,
		'foreign_key_checks' => true,
		'sql_mode'           => true,
		'unique_checks'      => true,
	);

	const READ_ONLY_SYSTEM_VARIABLES = array(
		'version'         => true,
		'version_comment' => true,
	);

	const DATA_TYPE_MAP = array(
		WP_MySQL_Lexer::BOOL_SYMBOL       => 'BOOLEAN',
		WP_MySQL_Lexer::BOOLEAN_SYMBOL    => 'BOOLEAN',
		WP_MySQL_Lexer::TINYINT_SYMBOL    => 'TINYINT',
		WP_MySQL_Lexer::SMALLINT_SYMBOL   => 'SMALLINT',
		WP_MySQL_Lexer::MEDIUMINT_SYMBOL  => 'INTEGER',
		WP_MySQL_Lexer::INT_SYMBOL        => 'INTEGER',
		WP_MySQL_Lexer::INTEGER_SYMBOL    => 'INTEGER',
		WP_MySQL_Lexer::BIGINT_SYMBOL     => 'BIGINT',
		WP_MySQL_Lexer::FLOAT_SYMBOL      => 'FLOAT',
		WP_MySQL_Lexer::DOUBLE_SYMBOL     => 'DOUBLE',
		WP_MySQL_Lexer::DECIMAL_SYMBOL    => 'DECIMAL',
		WP_MySQL_Lexer::NUMERIC_SYMBOL    => 'DECIMAL',
		WP_MySQL_Lexer::CHAR_SYMBOL       => 'VARCHAR',
		WP_MySQL_Lexer::VARCHAR_SYMBOL    => 'VARCHAR',
		WP_MySQL_Lexer::TEXT_SYMBOL       => 'VARCHAR',
		WP_MySQL_Lexer::TINYTEXT_SYMBOL   => 'VARCHAR',
		WP_MySQL_Lexer::MEDIUMTEXT_SYMBOL => 'VARCHAR',
		WP_MySQL_Lexer::LONGTEXT_SYMBOL   => 'VARCHAR',
		WP_MySQL_Lexer::DATE_SYMBOL       => 'VARCHAR',
		WP_MySQL_Lexer::TIME_SYMBOL       => 'VARCHAR',
		WP_MySQL_Lexer::DATETIME_SYMBOL   => 'VARCHAR',
		WP_MySQL_Lexer::TIMESTAMP_SYMBOL  => 'VARCHAR',
		WP_MySQL_Lexer::BLOB_SYMBOL       => 'BLOB',
		WP_MySQL_Lexer::TINYBLOB_SYMBOL   => 'BLOB',
		WP_MySQL_Lexer::MEDIUMBLOB_SYMBOL => 'BLOB',
		WP_MySQL_Lexer::LONGBLOB_SYMBOL   => 'BLOB',
	);

	/**
	 * @var WP_Parser_Grammar|null
	 */
	private static $mysql_grammar;

	/**
	 * @var WP_DuckDB_Connection
	 */
	private $connection;

	/**
	 * @var string
	 */
	private $database;

	/**
	 * @var int
	 */
	private $mysql_version;

	/**
	 * @var WP_MySQL_Parser|null
	 */
	private $mysql_parser;

	/**
	 * @var string|null
	 */
	private $last_mysql_query;

	/**
	 * @var string[]
	 */
	private $last_duckdb_queries = array();

	/**
	 * @var int
	 */
	private $last_insert_id = 0;

	/**
	 * Data for emulating MySQL FOUND_ROWS().
	 *
	 * SQL_CALC_FOUND_ROWS stores an eager integer count without the SELECT LIMIT.
	 * Other SELECT statements store the translated DuckDB query and count it
	 * lazily when FOUND_ROWS() is requested, matching the existing SQLite driver
	 * behavior for the bounded compatibility slice.
	 *
	 * @var int|string
	 */
	private $found_rows = 0;

	/**
	 * The currently active MySQL SQL modes.
	 *
	 * The default value reflects the default SQL modes for MySQL 8.0.
	 *
	 * @var string[]
	 */
	private $active_sql_modes = array(
		'ERROR_FOR_DIVISION_BY_ZERO',
		'NO_ENGINE_SUBSTITUTION',
		'NO_ZERO_DATE',
		'NO_ZERO_IN_DATE',
		'ONLY_FULL_GROUP_BY',
		'STRICT_TRANS_TABLES',
	);

	/**
	 * MySQL session system variables emulated by this driver.
	 *
	 * @var array<string,int|string|null>
	 */
	private $session_system_variables = array();

	/**
	 * MySQL user variables emulated by this driver.
	 *
	 * @var array<string,int|float|string|null>
	 */
	private $user_variables = array();

	/**
	 * Whether a MySQL LOCK TABLES statement opened the current transaction.
	 *
	 * @var bool
	 */
	private $table_lock_active = false;

	/**
	 * MySQL-compatible server version string.
	 *
	 * @var string
	 */
	public $client_info;

	/**
	 * @param array $options Driver and connection options.
	 *
	 * @throws InvalidArgumentException When options are invalid.
	 * @throws WP_DuckDB_Driver_Exception When DuckDB cannot be initialized.
	 */
	public function __construct( array $options = array() ) {
		$this->database      = isset( $options['database'] ) ? (string) $options['database'] : self::DEFAULT_DATABASE;
		$this->mysql_version = isset( $options['mysql_version'] ) ? (int) $options['mysql_version'] : self::DEFAULT_MYSQL_VERSION;

		if ( '' === $this->database ) {
			throw new InvalidArgumentException( 'DuckDB driver option "database" must not be empty.' );
		}

		if ( isset( $options['connection'] ) ) {
			if ( ! $options['connection'] instanceof WP_DuckDB_Connection ) {
				throw new InvalidArgumentException( 'DuckDB driver option "connection" must be a WP_DuckDB_Connection.' );
			}
			$this->connection = $options['connection'];
		} else {
			$this->connection = new WP_DuckDB_Connection( $options );
		}

		if ( null === self::$mysql_grammar ) {
			self::$mysql_grammar = new WP_Parser_Grammar( require self::MYSQL_GRAMMAR_PATH );
		}

		$this->initialize_session_macros();

		$this->client_info = $this->format_mysql_version();
	}

	/**
	 * Translate and execute a supported MySQL query in DuckDB.
	 *
	 * @param string $query MySQL query.
	 * @return WP_DuckDB_Result_Statement
	 *
	 * @throws WP_DuckDB_Driver_Exception When the statement is unsupported or fails.
	 */
	public function query( string $query ): WP_DuckDB_Result_Statement {
		$this->last_mysql_query    = $query;
		$this->last_duckdb_queries = array();
		$this->last_insert_id      = 0;

		try {
			$tokens = $this->tokenize_and_validate( $query );
			if ( count( $tokens ) === 0 ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported DuckDB MySQL-emulation statement: empty query.' );
			}

			switch ( $tokens[0]->id ) {
				case WP_MySQL_Lexer::BEGIN_SYMBOL:
					$this->found_rows = 0;
					return $this->execute_begin_transaction_statement( $tokens );
				case WP_MySQL_Lexer::START_SYMBOL:
					$this->found_rows = 0;
					return $this->execute_start_transaction_statement( $tokens );
				case WP_MySQL_Lexer::COMMIT_SYMBOL:
					$this->found_rows = 0;
					return $this->execute_commit_statement( $tokens );
				case WP_MySQL_Lexer::ROLLBACK_SYMBOL:
					$this->found_rows = 0;
					return $this->execute_rollback_statement( $tokens );
				case WP_MySQL_Lexer::LOCK_SYMBOL:
					$this->found_rows = 0;
					return $this->execute_lock_tables_statement( $tokens );
				case WP_MySQL_Lexer::UNLOCK_SYMBOL:
					$this->found_rows = 0;
					return $this->execute_unlock_tables_statement( $tokens );
				case WP_MySQL_Lexer::SET_SYMBOL:
					$this->found_rows = 0;
					return $this->execute_set_statement( $tokens );
				case WP_MySQL_Lexer::SELECT_SYMBOL:
					return $this->execute_select( $tokens );
				case WP_MySQL_Lexer::CREATE_SYMBOL:
					$this->found_rows = 0;
					return $this->execute_create( $tokens );
				case WP_MySQL_Lexer::INSERT_SYMBOL:
					$this->found_rows = 0;
					return $this->execute_insert( $tokens );
				case WP_MySQL_Lexer::REPLACE_SYMBOL:
					$this->found_rows = 0;
					return $this->execute_replace( $tokens );
				case WP_MySQL_Lexer::UPDATE_SYMBOL:
					$this->found_rows = 0;
					return $this->execute_update( $tokens );
				case WP_MySQL_Lexer::DELETE_SYMBOL:
					$this->found_rows = 0;
					return $this->execute_delete( $tokens );
				case WP_MySQL_Lexer::DROP_SYMBOL:
					$this->found_rows = 0;
					return $this->execute_drop( $tokens );
				case WP_MySQL_Lexer::TRUNCATE_SYMBOL:
					$this->found_rows = 0;
					return $this->execute_truncate_table( $tokens );
				case WP_MySQL_Lexer::ALTER_SYMBOL:
					$this->found_rows = 0;
					return $this->execute_alter_table( $tokens );
				case WP_MySQL_Lexer::ANALYZE_SYMBOL:
				case WP_MySQL_Lexer::CHECK_SYMBOL:
				case WP_MySQL_Lexer::OPTIMIZE_SYMBOL:
				case WP_MySQL_Lexer::REPAIR_SYMBOL:
					$this->found_rows = 0;
					return $this->execute_table_administration_statement( $tokens );
				case WP_MySQL_Lexer::SHOW_SYMBOL:
					return $this->record_found_rows_from_result( $this->execute_show( $tokens ) );
				case WP_MySQL_Lexer::DESCRIBE_SYMBOL:
				case WP_MySQL_Lexer::DESC_SYMBOL:
					return $this->record_found_rows_from_result( $this->execute_describe( $tokens ) );
			}

			throw $this->new_unsupported_statement_exception( $tokens[0] );
		} catch ( Throwable $e ) {
			$this->found_rows = 0;
			throw $e;
		}
	}

	/**
	 * Get the underlying DuckDB connection.
	 *
	 * @return WP_DuckDB_Connection
	 */
	public function get_connection(): WP_DuckDB_Connection {
		return $this->connection;
	}

	/**
	 * Get the last MySQL query.
	 *
	 * @return string|null
	 */
	public function get_last_mysql_query(): ?string {
		return $this->last_mysql_query;
	}

	/**
	 * Get DuckDB SQL statements executed for the last query.
	 *
	 * @return string[]
	 */
	public function get_last_duckdb_queries(): array {
		return $this->last_duckdb_queries;
	}

	/**
	 * Get the auto-increment value generated by the last INSERT/REPLACE.
	 *
	 * @return int Insert ID, or 0 when none was generated.
	 */
	public function get_insert_id(): int {
		return $this->last_insert_id;
	}

	/**
	 * Get an emulated MySQL session system variable value.
	 *
	 * @param string $name Variable name.
	 * @return int|string|null Stored value, or null when supported but unset.
	 */
	private function get_session_system_variable( string $name ) {
		$normalized_name = strtolower( $name );

		if ( 'sql_mode' === $normalized_name ) {
			return implode( ',', $this->active_sql_modes );
		}

		if ( 'version' === $normalized_name ) {
			return $this->format_mysql_system_variable_version();
		}

		if ( 'version_comment' === $normalized_name ) {
			return 'MySQL Community Server - GPL';
		}

		$normalized_name = $this->normalize_supported_session_system_variable_name( $name );

		return $this->session_system_variables[ $normalized_name ] ?? null;
	}

	/**
	 * Tokenize and parse a single MySQL statement.
	 *
	 * @param string $query MySQL query.
	 * @return WP_Parser_Token[]
	 *
	 * @throws WP_DuckDB_Driver_Exception When parsing fails or multiple statements are supplied.
	 */
	private function tokenize_and_validate( string $query ): array {
		$lexer      = new WP_MySQL_Lexer( $query, $this->mysql_version );
		$raw_tokens = class_exists( 'WP_MySQL_Native_Lexer', false ) && $lexer instanceof WP_MySQL_Native_Lexer
			? $lexer->native_token_stream()
			: $lexer->remaining_tokens();
		$tokens     = is_array( $raw_tokens ) ? array_values( $raw_tokens ) : array_values( iterator_to_array( $raw_tokens ) );

		$this->assert_single_statement( $tokens );
		$this->parse_tokens( $tokens );

		$tokens = $this->without_eof( $tokens );
		if ( count( $tokens ) > 0 && WP_MySQL_Lexer::SEMICOLON_SYMBOL === $tokens[ count( $tokens ) - 1 ]->id ) {
			array_pop( $tokens );
		}

		return array_values( $tokens );
	}

	/**
	 * Ensure the token stream contains at most one trailing semicolon.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 *
	 * @throws WP_DuckDB_Driver_Exception When multiple statements are supplied.
	 */
	private function assert_single_statement( $tokens ): void {
		$significant = $this->without_eof( $tokens );
		foreach ( $significant as $index => $token ) {
			if (
				WP_MySQL_Lexer::SEMICOLON_SYMBOL === $token->id
				&& count( $significant ) - 1 !== $index
			) {
				throw new WP_DuckDB_Driver_Exception( 'DuckDB driver supports one MySQL statement at a time.' );
			}
		}
	}

	/**
	 * Parse token stream with the MySQL parser.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 *
	 * @throws WP_DuckDB_Driver_Exception When parsing fails.
	 */
	private function parse_tokens( $tokens ): void {
		try {
			if ( null === $this->mysql_parser || ! method_exists( $this->mysql_parser, 'reset_tokens' ) ) {
				$this->mysql_parser = new WP_MySQL_Parser( self::$mysql_grammar, $tokens );
			} else {
				$this->mysql_parser->reset_tokens( $tokens );
			}
			$ast = $this->mysql_parser->parse();
		} catch ( Throwable $e ) {
			throw new WP_DuckDB_Driver_Exception( 'DuckDB driver could not parse MySQL statement: ' . $e->getMessage(), 0, $e );
		}

		if ( ! $ast instanceof WP_Parser_Node ) {
			throw new WP_DuckDB_Driver_Exception( 'DuckDB driver could not parse MySQL statement.' );
		}
	}

	/**
	 * Remove EOF from a token stream.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @return WP_Parser_Token[]
	 */
	private function without_eof( array $tokens ): array {
		$filtered = array();
		foreach ( $tokens as $token ) {
			if ( WP_MySQL_Lexer::EOF === $token->id ) {
				continue;
			}
			$filtered[] = $token;
		}
		return $filtered;
	}

	/**
	 * Execute a supported SELECT statement.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_select( array $tokens ): WP_DuckDB_Result_Statement {
		$found_rows_alias = $this->parse_found_rows_select( $tokens );
		if ( null !== $found_rows_alias ) {
			$found_rows       = $this->evaluate_found_rows();
			$this->found_rows = 1;
			return new WP_DuckDB_Result_Statement( array( $found_rows_alias ), array( array( $found_rows ) ), 0 );
		}

		$variable_select = $this->execute_variable_select( $tokens );
		if ( null !== $variable_select ) {
			return $this->record_found_rows_from_result( $variable_select );
		}

		$has_sql_calc_found_rows = $this->has_top_level_sql_calc_found_rows( $tokens );
		$tokens                  = $this->normalize_select_helper_tokens( $tokens );
		$column_meta             = $this->simple_select_column_metadata( $tokens );

		$rewrite_information_schema_tables                  = $this->uses_information_schema_tables( $tokens );
		$rewrite_information_schema_columns                 = $this->uses_information_schema_columns( $tokens );
		$rewrite_information_schema_statistics              = $this->uses_information_schema_statistics( $tokens );
		$rewrite_information_schema_table_constraints       = $this->uses_information_schema_table_constraints( $tokens );
		$rewrite_information_schema_key_column_usage        = $this->uses_information_schema_key_column_usage( $tokens );
		$rewrite_information_schema_referential_constraints = $this->uses_information_schema_referential_constraints( $tokens );
		$rewrite_information_schema_check_constraints       = $this->uses_information_schema_check_constraints( $tokens );
		if ( $rewrite_information_schema_tables ) {
			$this->refresh_information_schema_tables_table();
		}
		if ( $rewrite_information_schema_columns ) {
			$this->refresh_information_schema_columns_table();
		}
		if ( $rewrite_information_schema_statistics ) {
			$this->refresh_information_schema_statistics_table();
		}
		if ( $rewrite_information_schema_table_constraints ) {
			$this->refresh_information_schema_table_constraints_table();
		}
		if ( $rewrite_information_schema_key_column_usage ) {
			$this->refresh_information_schema_key_column_usage_table();
		}
		if ( $rewrite_information_schema_referential_constraints ) {
			$this->refresh_information_schema_referential_constraints_table();
		}
		if ( $rewrite_information_schema_check_constraints ) {
			$this->refresh_information_schema_check_constraints_table();
		}

		$sql = $this->translate_tokens_to_duckdb_sql(
			$tokens,
			$rewrite_information_schema_tables,
			$rewrite_information_schema_columns,
			$rewrite_information_schema_statistics,
			$rewrite_information_schema_table_constraints,
			$rewrite_information_schema_key_column_usage,
			$rewrite_information_schema_referential_constraints,
			$rewrite_information_schema_check_constraints
		);

		if ( $has_sql_calc_found_rows ) {
			try {
				$this->found_rows = $this->count_select_rows(
					$this->strip_top_level_limit_clause( $tokens ),
					$rewrite_information_schema_tables,
					$rewrite_information_schema_columns,
					$rewrite_information_schema_statistics,
					$rewrite_information_schema_table_constraints,
					$rewrite_information_schema_key_column_usage,
					$rewrite_information_schema_referential_constraints,
					$rewrite_information_schema_check_constraints
				);
				$result           = $this->execute_duckdb_query( $sql, 'Unsupported DuckDB MySQL-emulation SELECT statement' );
				return $this->apply_result_column_metadata( $result, $column_meta );
			} catch ( Throwable $e ) {
				$this->found_rows = 0;
				throw $e;
			}
		}

		$result           = $this->execute_duckdb_query( $sql, 'Unsupported DuckDB MySQL-emulation SELECT statement' );
		$this->found_rows = $sql;
		return $this->apply_result_column_metadata( $result, $column_meta );
	}

	/**
	 * Attach optional column metadata when it matches the result shape.
	 *
	 * @param WP_DuckDB_Result_Statement       $result      Query result.
	 * @param array<int,array<string,mixed>>|null $column_meta Optional column metadata.
	 * @return WP_DuckDB_Result_Statement Result with metadata attached when available.
	 */
	private function apply_result_column_metadata( WP_DuckDB_Result_Statement $result, ?array $column_meta ): WP_DuckDB_Result_Statement {
		if ( null !== $column_meta && count( $column_meta ) === $result->columnCount() ) {
			$result->setColumnMeta( $column_meta );
		}

		return $result;
	}

	/**
	 * Derive bounded result metadata for SELECT column_list FROM single_table.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return array<int,array<string,mixed>>|null Column metadata, or null when the shape is outside the supported slice.
	 */
	private function simple_select_column_metadata( array $tokens ): ?array {
		if ( ! isset( $tokens[0] ) || WP_MySQL_Lexer::SELECT_SYMBOL !== $tokens[0]->id ) {
			return null;
		}

		$from_index = $this->find_top_level_token_index( $tokens, 1, WP_MySQL_Lexer::FROM_SYMBOL );
		if ( null === $from_index || 1 === $from_index ) {
			return null;
		}

		$select_items = $this->split_top_level_comma_items( array_slice( $tokens, 1, $from_index - 1 ) );
		if ( count( $select_items ) === 0 ) {
			return null;
		}

		$columns = array();
		foreach ( $select_items as $item ) {
			$column = $this->parse_simple_select_column_reference( $item );
			if ( null === $column ) {
				return null;
			}
			$columns[] = $column;
		}

		$table_tokens = array_slice(
			$tokens,
			$from_index + 1,
			$this->simple_select_from_clause_end( $tokens, $from_index + 1 ) - $from_index - 1
		);
		$table        = $this->parse_simple_select_table_reference( $table_tokens );
		if ( null === $table ) {
			return null;
		}

		foreach ( $columns as $column ) {
			if (
				null !== $column['qualifier']
				&& 0 !== strcasecmp( $column['qualifier'], $table['alias'] )
				&& 0 !== strcasecmp( $column['qualifier'], $table['table_name'] )
			) {
				return null;
			}
		}

		$metadata_by_column = array();
		foreach ( $this->table_column_metadata_rows( $table['table_name'], $table['temporary'] ) as $metadata ) {
			$metadata_by_column[ strtolower( (string) $metadata['column_name'] ) ] = $metadata;
		}

		$column_meta = array();
		foreach ( $columns as $column ) {
			$key = strtolower( $column['column_name'] );
			if ( ! isset( $metadata_by_column[ $key ] ) ) {
				return null;
			}

			$column_meta[] = $this->mysql_result_column_metadata(
				$table['table_name'],
				$table['alias'],
				$metadata_by_column[ $key ],
				$column['name']
			);
		}

		return $column_meta;
	}

	/**
	 * Find the end of a simple SELECT FROM clause.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @param int               $start  First token after FROM.
	 * @return int End index, exclusive.
	 */
	private function simple_select_from_clause_end( array $tokens, int $start ): int {
		$clause_tokens = array(
			WP_MySQL_Lexer::WHERE_SYMBOL,
			WP_MySQL_Lexer::GROUP_SYMBOL,
			WP_MySQL_Lexer::HAVING_SYMBOL,
			WP_MySQL_Lexer::WINDOW_SYMBOL,
			WP_MySQL_Lexer::ORDER_SYMBOL,
			WP_MySQL_Lexer::LIMIT_SYMBOL,
			WP_MySQL_Lexer::PROCEDURE_SYMBOL,
			WP_MySQL_Lexer::INTO_SYMBOL,
			WP_MySQL_Lexer::FOR_SYMBOL,
			WP_MySQL_Lexer::LOCK_SYMBOL,
			WP_MySQL_Lexer::UNION_SYMBOL,
		);
		$depth         = 0;

		for ( $index = $start; $index < count( $tokens ); ++$index ) {
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $index ]->id ) {
				++$depth;
				continue;
			}
			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $index ]->id ) {
				--$depth;
				continue;
			}
			if ( 0 === $depth && in_array( $tokens[ $index ]->id, $clause_tokens, true ) ) {
				return $index;
			}
		}

		return count( $tokens );
	}

	/**
	 * Parse a single-table reference for bounded SELECT metadata.
	 *
	 * @param WP_Parser_Token[] $tokens Table reference tokens.
	 * @return array{table_name:string,alias:string,temporary:bool}|null Table reference, or null when unsupported.
	 */
	private function parse_simple_select_table_reference( array $tokens ): ?array {
		if (
			count( $tokens ) === 0
			|| $this->contains_top_level_token_id( $tokens, WP_MySQL_Lexer::COMMA_SYMBOL )
			|| $this->contains_top_level_join_token( $tokens )
			|| WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[0]->id
		) {
			return null;
		}

		$index      = 0;
		$database   = null;
		$table_name = $this->metadata_identifier_value( $tokens[ $index ] ?? null );
		if ( null === $table_name ) {
			return null;
		}
		++$index;

		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $index ]->id ) {
			$database = $table_name;
			++$index;
			$table_name = $this->metadata_identifier_value( $tokens[ $index ] ?? null );
			if ( null === $table_name ) {
				return null;
			}
			++$index;

			if ( 0 === strcasecmp( $database, 'information_schema' ) || 0 !== strcasecmp( $database, $this->database ) ) {
				return null;
			}
		}

		$table_reference = $this->resolve_visible_user_table_reference( $table_name );
		if ( null === $table_reference ) {
			return null;
		}

		$alias = $table_reference['table_name'];
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::AS_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
			$parsed_alias = $this->metadata_identifier_value( $tokens[ $index ] ?? null );
			if ( null === $parsed_alias ) {
				return null;
			}
			$alias = $parsed_alias;
			++$index;
		} elseif ( isset( $tokens[ $index ] ) ) {
			$parsed_alias = $this->metadata_identifier_value( $tokens[ $index ] );
			if ( null === $parsed_alias ) {
				return null;
			}
			$alias = $parsed_alias;
			++$index;
		}

		if ( count( $tokens ) !== $index ) {
			return null;
		}

		return array(
			'table_name' => $table_reference['table_name'],
			'alias'      => $alias,
			'temporary'  => $table_reference['temporary'],
		);
	}

	/**
	 * Parse one SELECT list item for bounded result metadata.
	 *
	 * @param WP_Parser_Token[] $tokens SELECT item tokens.
	 * @return array{name:string,column_name:string,qualifier:string|null}|null Column reference, or null when unsupported.
	 */
	private function parse_simple_select_column_reference( array $tokens ): ?array {
		$count = count( $tokens );
		if ( 0 === $count ) {
			return null;
		}

		$index     = 0;
		$qualifier = null;
		$name      = $this->metadata_identifier_value( $tokens[ $index ] ?? null );
		if ( null === $name ) {
			return null;
		}
		++$index;

		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $index ]->id ) {
			$qualifier = $name;
			++$index;
			$name = $this->metadata_identifier_value( $tokens[ $index ] ?? null );
			if ( null === $name ) {
				return null;
			}
			++$index;
		}

		$alias = $name;
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::AS_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
			$parsed_alias = $this->metadata_identifier_value( $tokens[ $index ] ?? null );
			if ( null === $parsed_alias ) {
				return null;
			}
			$alias = $parsed_alias;
			++$index;
		} elseif ( isset( $tokens[ $index ] ) ) {
			$parsed_alias = $this->metadata_identifier_value( $tokens[ $index ] );
			if ( null === $parsed_alias ) {
				return null;
			}
			$alias = $parsed_alias;
			++$index;
		}

		if ( $count !== $index ) {
			return null;
		}

		return array(
			'name'        => $alias,
			'column_name' => $name,
			'qualifier'   => $qualifier,
		);
	}

	/**
	 * Read a metadata identifier without throwing for unsupported tokens.
	 *
	 * @param WP_Parser_Token|null $token Token.
	 * @return string|null Identifier value, or null when unsupported.
	 */
	private function metadata_identifier_value( $token ): ?string {
		if ( ! $token instanceof WP_Parser_Token || $this->is_non_identifier_token( $token ) || WP_MySQL_Lexer::MULT_OPERATOR === $token->id ) {
			return null;
		}

		return $token->get_value();
	}

	/**
	 * Build PDO/MySQLi-shaped result metadata for one direct table column.
	 *
	 * @param string              $table_name  Original table name.
	 * @param string              $table_alias Result table alias.
	 * @param array<string,mixed> $column      Stored column metadata.
	 * @param string              $result_name Result column name.
	 * @return array<string,mixed> Result column metadata.
	 */
	private function mysql_result_column_metadata( string $table_name, string $table_alias, array $column, string $result_name ): array {
		$column_type     = (string) $column['column_type'];
		$type_attributes = $this->column_type_attributes(
			$column_type,
			null === $column['collation_name'] ? null : (string) $column['collation_name']
		);
		$type_info       = $this->mysql_result_column_type_info( $type_attributes['data_type'], $column_type );

		$length    = $this->mysql_result_column_length( $column_type, $type_attributes, $type_info['length'] );
		$precision = $this->mysql_result_column_precision( $type_attributes, $type_info['precision'] );

		return array(
			'native_type'      => $type_info['native_type'],
			'flags'            => array(),
			'table'            => $table_alias,
			'name'             => $result_name,
			'len'              => $length,
			'precision'        => $precision,
			'duckdb:decl_type' => $column_type,
			'mysqli:orgname'   => (string) $column['column_name'],
			'mysqli:orgtable'  => $table_name,
			'mysqli:db'        => $this->database,
			'mysqli:charsetnr' => $this->mysql_result_column_charsetnr( $type_attributes['data_type'], $column['collation_name'] ),
			'mysqli:flags'     => 0,
			'mysqli:type'      => $type_info['mysqli_type'],
		);
	}

	/**
	 * Map MySQL data types to PDO/MySQLi result metadata types.
	 *
	 * @param string $data_type   Normalized MySQL data type.
	 * @param string $column_type Full MySQL column type.
	 * @return array{native_type:string,mysqli_type:int,length:int|null,precision:int|null} Type metadata.
	 */
	private function mysql_result_column_type_info( string $data_type, string $column_type ): array {
		$type_map = array(
			'bit'        => array( 'BIT', 16, 1, 0 ),
			'tinyint'    => array( 'TINY', 1, 4, 0 ),
			'smallint'   => array( 'SHORT', 2, 6, 0 ),
			'mediumint'  => array( 'INT24', 9, 9, 0 ),
			'int'        => array( 'LONG', 3, 11, 0 ),
			'bigint'     => array( 'LONGLONG', 8, 20, 0 ),
			'float'      => array( 'FLOAT', 4, 12, 31 ),
			'double'     => array( 'DOUBLE', 5, 22, 31 ),
			'decimal'    => array( 'NEWDECIMAL', 246, null, null ),
			'char'       => array( 'STRING', 254, null, 0 ),
			'varchar'    => array( 'VAR_STRING', 253, null, 0 ),
			'tinytext'   => array( 'BLOB', 252, null, 0 ),
			'text'       => array( 'BLOB', 252, null, 0 ),
			'mediumtext' => array( 'BLOB', 252, null, 0 ),
			'longtext'   => array( 'BLOB', 252, null, 0 ),
			'json'       => array( 'BLOB', 245, 4294967295, 0 ),
			'date'       => array( 'DATE', 10, 10, 0 ),
			'time'       => array( 'TIME', 11, 10, 0 ),
			'datetime'   => array( 'DATETIME', 12, 19, 0 ),
			'timestamp'  => array( 'TIMESTAMP', 7, 19, 0 ),
			'year'       => array( 'YEAR', 13, 4, 0 ),
			'binary'     => array( 'BLOB', 254, null, 0 ),
			'varbinary'  => array( 'BLOB', 253, null, 0 ),
			'tinyblob'   => array( 'BLOB', 252, null, 0 ),
			'blob'       => array( 'BLOB', 252, null, 0 ),
			'mediumblob' => array( 'BLOB', 252, null, 0 ),
			'longblob'   => array( 'BLOB', 252, null, 0 ),
		);

		$type_info = $type_map[ $data_type ] ?? array( 'VAR_STRING', 253, null, 0 );
		if ( 'tinyint(1)' === strtolower( trim( $column_type ) ) ) {
			$type_info[2] = 1;
		}

		return array(
			'native_type' => $type_info[0],
			'mysqli_type' => $type_info[1],
			'length'      => $type_info[2],
			'precision'   => $type_info[3],
		);
	}

	/**
	 * Derive MySQLi result length from MySQL type attributes.
	 *
	 * @param string              $column_type     Full MySQL column type.
	 * @param array<string,mixed> $type_attributes Derived type attributes.
	 * @param int|null            $default_length  Default mapped length.
	 * @return int Length.
	 */
	private function mysql_result_column_length( string $column_type, array $type_attributes, ?int $default_length ): int {
		$length    = $default_length;
		$data_type = (string) $type_attributes['data_type'];

		if ( 'decimal' === $data_type ) {
			$length = (int) $type_attributes['numeric_precision'] + (int) $type_attributes['numeric_scale'];
		} elseif ( null !== $type_attributes['character_maximum_length'] ) {
			$length = (int) $type_attributes['character_maximum_length'];
		}

		if (
			null !== $length
			&& false !== strpos( strtolower( $column_type ), 'unsigned' )
			&& false === strpos( strtolower( $column_type ), 'bigint' )
		) {
			--$length;
		}

		if (
			null !== $length
			&& (
				false !== strpos( $data_type, 'text' )
				|| false !== strpos( $data_type, 'char' )
				|| 'enum' === $data_type
				|| 'set' === $data_type
			)
			&& 'longtext' !== $data_type
		) {
			$length *= 4;
		}

		return null === $length ? 0 : $length;
	}

	/**
	 * Derive MySQLi result precision.
	 *
	 * @param array<string,mixed> $type_attributes Derived type attributes.
	 * @param int|null            $default_precision Default mapped precision.
	 * @return int Precision.
	 */
	private function mysql_result_column_precision( array $type_attributes, ?int $default_precision ): int {
		if ( 'decimal' === $type_attributes['data_type'] ) {
			return (int) $type_attributes['numeric_scale'];
		}

		return null === $default_precision ? 0 : $default_precision;
	}

	/**
	 * Derive a MySQLi charset number for the bounded metadata slice.
	 *
	 * @param string $data_type      Normalized MySQL data type.
	 * @param mixed  $collation_name Optional collation name.
	 * @return int MySQLi charset number.
	 */
	private function mysql_result_column_charsetnr( string $data_type, $collation_name ): int {
		$charset = $this->character_set_from_collation( $collation_name );
		if (
			null !== $charset
			&& false === strpos( $data_type, 'blob' )
			&& ! in_array( $data_type, array( 'binary', 'varbinary', 'date', 'time', 'datetime', 'timestamp', 'year' ), true )
		) {
			return 255;
		}

		return 63;
	}

	/**
	 * Parse SELECT FOUND_ROWS() with an optional alias and optional FROM DUAL.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return string|null Result column name, or null for generic SELECT handling.
	 */
	private function parse_found_rows_select( array $tokens ): ?string {
		if ( ! isset( $tokens[0] ) || WP_MySQL_Lexer::SELECT_SYMBOL !== $tokens[0]->id ) {
			return null;
		}

		$select_list_end = count( $tokens );
		if (
			$select_list_end >= 4
			&& WP_MySQL_Lexer::FROM_SYMBOL === $tokens[ $select_list_end - 2 ]->id
			&& WP_MySQL_Lexer::DUAL_SYMBOL === $tokens[ $select_list_end - 1 ]->id
		) {
			$select_list_end -= 2;
		}

		$select_list = array_slice( $tokens, 1, $select_list_end - 1 );
		if (
			! $this->is_empty_function_call( $select_list, 0, 'FOUND_ROWS' )
			|| $this->contains_top_level_token_id( $select_list, WP_MySQL_Lexer::COMMA_SYMBOL )
		) {
			return null;
		}

		$index = 3;
		$alias = 'FOUND_ROWS()';
		if ( isset( $select_list[ $index ] ) && WP_MySQL_Lexer::AS_SYMBOL === $select_list[ $index ]->id ) {
			++$index;
			$alias = $this->identifier_value( $select_list[ $index ] ?? null );
			++$index;
		} elseif ( isset( $select_list[ $index ] ) ) {
			$alias = $this->identifier_value( $select_list[ $index ] );
			++$index;
		}

		return count( $select_list ) === $index ? $alias : null;
	}

	/**
	 * Evaluate the current FOUND_ROWS() state.
	 *
	 * @return int FOUND_ROWS() value.
	 */
	private function evaluate_found_rows(): int {
		if ( is_int( $this->found_rows ) ) {
			return $this->found_rows;
		}

		return (int) $this->execute_duckdb_query(
			'SELECT COUNT(*) AS cnt FROM (' . $this->found_rows . ') AS __wp_duckdb_found_rows',
			'Failed to evaluate DuckDB FOUND_ROWS()'
		)->fetchColumn();
	}

	/**
	 * Count rows returned by a SELECT token stream.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return int Row count.
	 */
	private function count_select_rows(
		array $tokens,
		bool $rewrite_information_schema_tables,
		bool $rewrite_information_schema_columns,
		bool $rewrite_information_schema_statistics,
		bool $rewrite_information_schema_table_constraints,
		bool $rewrite_information_schema_key_column_usage,
		bool $rewrite_information_schema_referential_constraints,
		bool $rewrite_information_schema_check_constraints
	): int {
		$sql = $this->translate_tokens_to_duckdb_sql(
			$tokens,
			$rewrite_information_schema_tables,
			$rewrite_information_schema_columns,
			$rewrite_information_schema_statistics,
			$rewrite_information_schema_table_constraints,
			$rewrite_information_schema_key_column_usage,
			$rewrite_information_schema_referential_constraints,
			$rewrite_information_schema_check_constraints
		);

		return (int) $this->execute_duckdb_query(
			'SELECT COUNT(*) AS cnt FROM (' . $sql . ') AS __wp_duckdb_found_rows',
			'Failed to count DuckDB SQL_CALC_FOUND_ROWS rows'
		)->fetchColumn();
	}

	/**
	 * Materialize a result statement so FOUND_ROWS() can report its row count.
	 *
	 * @param WP_DuckDB_Result_Statement $statement Statement to record.
	 * @return WP_DuckDB_Result_Statement Rewound statement with the same rows.
	 */
	private function record_found_rows_from_result( WP_DuckDB_Result_Statement $statement ): WP_DuckDB_Result_Statement {
		if ( 0 === $statement->columnCount() ) {
			$this->found_rows = 0;
			return $statement;
		}

		$columns = array();
		for ( $index = 0; $index < $statement->columnCount(); ++$index ) {
			$meta      = $statement->getColumnMeta( $index );
			$columns[] = is_array( $meta ) && isset( $meta['name'] ) ? (string) $meta['name'] : (string) $index;
		}

		$rows             = $statement->fetchAll( PDO::FETCH_NUM );
		$this->found_rows = count( $rows );

		return new WP_DuckDB_Result_Statement( $columns, $rows, $statement->rowCount() );
	}

	/**
	 * Execute a simple SELECT list of supported MySQL variables.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement|null Statement when emulated, null for generic SELECT handling.
	 */
	private function execute_variable_select( array $tokens ): ?WP_DuckDB_Result_Statement {
		$variables = $this->parse_variable_select( $tokens );
		if ( null === $variables ) {
			return null;
		}

		$columns = array();
		$row     = array();
		foreach ( $variables as $variable ) {
			$columns[] = $variable['alias'];
			$row[]     = 'user' === $variable['type']
				? $this->get_user_variable( $variable['name'] )
				: $this->get_session_system_variable( $variable['name'] );
		}

		return new WP_DuckDB_Result_Statement( $columns, array( $row ), 0 );
	}

	/**
	 * Parse a simple SELECT list of supported MySQL variables.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return array<int,array{type:string,name:string,alias:string}>|null Variables, or null for generic SELECT handling.
	 */
	private function parse_variable_select( array $tokens ): ?array {
		if ( ! isset( $tokens[0], $tokens[1] ) || WP_MySQL_Lexer::SELECT_SYMBOL !== $tokens[0]->id ) {
			return null;
		}

		$select_list_end = count( $tokens );
		if (
			$select_list_end >= 4
			&& WP_MySQL_Lexer::FROM_SYMBOL === $tokens[ $select_list_end - 2 ]->id
			&& WP_MySQL_Lexer::DUAL_SYMBOL === $tokens[ $select_list_end - 1 ]->id
		) {
			$select_list_end -= 2;
		}

		$select_list = array_slice( $tokens, 1, $select_list_end - 1 );
		if ( count( $select_list ) === 0 ) {
			return null;
		}

		$variables = array();
		foreach ( $this->split_top_level_comma_items( $select_list ) as $item ) {
			$variable = $this->parse_session_system_variable_reference( $item );
			if ( null !== $variable ) {
				$variable['type'] = 'system';
				$variables[]      = $variable;
				continue;
			}

			$variable = $this->parse_user_variable_select_reference( $item );
			if ( null === $variable ) {
				return null;
			}
			$variable['type'] = 'user';
			$variables[]      = $variable;
		}

		return $variables;
	}

	/**
	 * Parse one supported @@session_variable reference.
	 *
	 * @param WP_Parser_Token[] $tokens Reference tokens.
	 * @return array{name:string,alias:string}|null Variable, or null when the item is not supported by this slice.
	 */
	private function parse_session_system_variable_reference( array $tokens ): ?array {
		if ( ! isset( $tokens[0], $tokens[1] ) || WP_MySQL_Lexer::AT_AT_SIGN_SYMBOL !== $tokens[0]->id ) {
			return null;
		}

		$session_scoped = false;
		if ( 2 === count( $tokens ) ) {
			$name = $this->session_system_variable_name( $tokens[1] );
		} elseif (
			4 === count( $tokens )
			&& WP_MySQL_Lexer::SESSION_SYMBOL === $tokens[1]->id
			&& WP_MySQL_Lexer::DOT_SYMBOL === $tokens[2]->id
		) {
			$name           = $this->session_system_variable_name( $tokens[3] );
			$session_scoped = true;
		} else {
			return null;
		}

		if ( null === $name || ! $this->is_supported_session_system_variable_reference( $name, $session_scoped ) ) {
			return null;
		}

		return array(
			'name'  => $name,
			'alias' => $this->concatenate_token_bytes( $tokens ),
		);
	}

	/**
	 * Parse one supported @user_variable reference with an optional alias.
	 *
	 * @param WP_Parser_Token[] $tokens Reference tokens.
	 * @return array{name:string,alias:string}|null Variable, or null when the item is not supported by this slice.
	 */
	private function parse_user_variable_select_reference( array $tokens ): ?array {
		if ( ! isset( $tokens[0] ) || ! $this->is_user_variable_token( $tokens[0] ) ) {
			return null;
		}

		$index = 1;
		$alias = $tokens[0]->get_bytes();
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::AS_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
			$alias = $this->identifier_value( $tokens[ $index ] ?? null );
			++$index;
		} elseif ( isset( $tokens[ $index ] ) ) {
			$alias = $this->identifier_value( $tokens[ $index ] );
			++$index;
		}

		if ( count( $tokens ) !== $index ) {
			return null;
		}

		return array(
			'name'  => $this->user_variable_name( $tokens[0] ),
			'alias' => $alias,
		);
	}

	/**
	 * Normalize a supported session system variable token.
	 *
	 * @param WP_Parser_Token $token Variable-name token.
	 * @return string|null Lowercase variable name, or null when not an identifier.
	 */
	private function session_system_variable_name( WP_Parser_Token $token ): ?string {
		if ( $this->is_non_identifier_token( $token ) ) {
			return null;
		}

		return strtolower( $token->get_value() );
	}

	/**
	 * Get an emulated MySQL user variable value.
	 *
	 * @param string $name Normalized variable name.
	 * @return int|float|string|null Stored value, or null when unset.
	 */
	private function get_user_variable( string $name ) {
		return array_key_exists( $name, $this->user_variables ) ? $this->user_variables[ $name ] : null;
	}

	/**
	 * Normalize a user-variable token name.
	 *
	 * @param WP_Parser_Token $token User-variable token.
	 * @return string Lowercase variable name without the leading @.
	 */
	private function user_variable_name( WP_Parser_Token $token ): string {
		if ( ! $this->is_user_variable_token( $token ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Expected a MySQL user variable in DuckDB driver statement.' );
		}

		return strtolower( substr( $token->get_value(), 1 ) );
	}

	/**
	 * Check whether a token is a MySQL @user_variable token.
	 *
	 * @param WP_Parser_Token $token Token.
	 * @return bool Whether the token is a user variable.
	 */
	private function is_user_variable_token( WP_Parser_Token $token ): bool {
		return WP_MySQL_Lexer::AT_TEXT_SUFFIX === $token->id;
	}

	/**
	 * Check whether this bounded slice supports a system-variable reference.
	 *
	 * @param string $name           Lowercase variable name.
	 * @param bool   $session_scoped Whether the reference uses @@SESSION.
	 * @return bool Whether the variable is supported.
	 */
	private function is_supported_session_system_variable_reference( string $name, bool $session_scoped ): bool {
		if ( isset( self::SUPPORTED_SESSION_SYSTEM_VARIABLES[ $name ] ) ) {
			return true;
		}

		return ! $session_scoped && isset( self::READ_ONLY_SYSTEM_VARIABLES[ $name ] );
	}

	/**
	 * Concatenate original token bytes without whitespace.
	 *
	 * @param WP_Parser_Token[] $tokens Tokens.
	 * @return string Concatenated token bytes.
	 */
	private function concatenate_token_bytes( array $tokens ): string {
		$bytes = '';
		foreach ( $tokens as $token ) {
			$bytes .= $token->get_bytes();
		}
		return $bytes;
	}

	/**
	 * Execute a supported CREATE statement.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_create( array $tokens ): WP_DuckDB_Result_Statement {
		if ( $this->is_create_view_statement( $tokens ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported CREATE VIEW statement in DuckDB driver. MySQL view lifecycle metadata is not supported (createView).' );
		}

		if ( isset( $tokens[1] ) && WP_MySQL_Lexer::TABLE_SYMBOL === $tokens[1]->id ) {
			return $this->execute_create_table( $tokens );
		}

		if (
			isset( $tokens[1], $tokens[2] )
			&& WP_MySQL_Lexer::TEMPORARY_SYMBOL === $tokens[1]->id
			&& WP_MySQL_Lexer::TABLE_SYMBOL === $tokens[2]->id
		) {
			return $this->execute_create_table( $tokens );
		}

		if ( $this->is_create_index_statement( $tokens ) ) {
			return $this->execute_create_index( $tokens );
		}

		throw new WP_DuckDB_Driver_Exception( 'Unsupported CREATE statement in DuckDB driver. Only CREATE TABLE and CREATE INDEX are supported.' );
	}

	/**
	 * Check whether a CREATE statement targets a view.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return bool Whether this is a CREATE VIEW statement.
	 */
	private function is_create_view_statement( array $tokens ): bool {
		$view_index = $this->find_top_level_token_index( $tokens, 1, WP_MySQL_Lexer::VIEW_SYMBOL );
		if ( null === $view_index ) {
			return false;
		}

		return null !== $this->find_top_level_token_index( $tokens, $view_index + 1, WP_MySQL_Lexer::AS_SYMBOL );
	}

	/**
	 * Execute a supported CREATE TABLE statement.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 *
	 * @throws WP_DuckDB_Driver_Exception When the CREATE TABLE shape is unsupported.
	 */
	private function execute_create_table( array $tokens ): WP_DuckDB_Result_Statement {
		$index = 0;
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::CREATE_SYMBOL, 'Expected CREATE.' );
		++$index;

		$temporary = false;
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::TEMPORARY_SYMBOL === $tokens[ $index ]->id ) {
			$temporary = true;
			++$index;
		}

		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::TABLE_SYMBOL, 'Only CREATE TABLE is supported by the DuckDB driver.' );
		++$index;

		$if_not_exists = false;
		if (
			isset( $tokens[ $index + 2 ] )
			&& WP_MySQL_Lexer::IF_SYMBOL === $tokens[ $index ]->id
			&& WP_MySQL_Lexer::NOT_SYMBOL === $tokens[ $index + 1 ]->id
			&& WP_MySQL_Lexer::EXISTS_SYMBOL === $tokens[ $index + 2 ]->id
		) {
			$if_not_exists = true;
			$index        += 3;
		}

		$table_name = $this->identifier_value( $tokens[ $index ] ?? null );
		++$index;
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::OPEN_PAR_SYMBOL, 'Expected column list in CREATE TABLE.' );
		++$index;

		list( $items, $index ) = $this->collect_parenthesized_items( $tokens, $index );
		$table_metadata        = $this->parse_create_table_options( array_slice( $tokens, $index ) );

		$columns             = array();
		$constraints         = array();
		$check_constraints   = array();
		$check_names         = array();
		$foreign_keys        = array();
		$foreign_key_names   = array();
		$indexes             = array();
		$sequences           = array();
		$metadata            = array();
		$primary_key         = array();
		$auto_increment_seed = $table_metadata['auto_increment'];

		foreach ( $items as $item ) {
			if ( count( $item ) === 0 ) {
				continue;
			}

			if ( WP_MySQL_Lexer::PRIMARY_SYMBOL === $item[0]->id ) {
				$primary_key   = $this->table_primary_key_columns( $item );
				$constraints[] = $this->translate_table_primary_key( $item );
				continue;
			}

			if ( $this->is_create_table_check_constraint( $item ) ) {
				$check_constraint    = $this->translate_table_check_constraint( $table_name, $item, $check_names );
				$constraints[]       = $check_constraint['sql'];
				$check_constraints[] = $check_constraint['metadata'];
				continue;
			}

			if ( $this->is_create_table_foreign_key_constraint( $item ) ) {
				$foreign_key    = $this->translate_table_foreign_key_constraint( $table_name, $item, $foreign_key_names );
				$constraints[]  = $foreign_key['sql'];
				$foreign_keys[] = $foreign_key['metadata'];
				continue;
			}

			if ( $this->is_create_table_index_item( $item ) ) {
				$indexes[] = $this->translate_create_table_index( $table_name, $item, $temporary );
				continue;
			}

			if ( WP_MySQL_Lexer::CONSTRAINT_SYMBOL === $item[0]->id ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported CREATE TABLE constraint in DuckDB driver: ' . $item[0]->get_bytes() . '.' );
			}

			list( $column_sql, $sequence_sql, $column_indexes, $column_metadata, $column_constraints, $column_check_constraints, $column_foreign_keys ) = $this->translate_create_table_column(
				$table_name,
				$item,
				true,
				false,
				$temporary,
				$auto_increment_seed,
				$table_metadata['table_collation'],
				$check_names,
				$foreign_key_names
			);
			$columns[]         = $column_sql;
			$metadata[]        = $column_metadata;
			$constraints       = array_merge( $constraints, $column_constraints );
			$check_constraints = array_merge( $check_constraints, $column_check_constraints );
			$foreign_keys      = array_merge( $foreign_keys, $column_foreign_keys );
			if ( null !== $sequence_sql ) {
				$sequences[] = array(
					'sql'         => $sequence_sql,
					'column_name' => $column_metadata['column_name'],
				);
			}
			foreach ( $column_indexes as $index_definition ) {
				$indexes[] = $index_definition;
			}
		}

		if ( count( $columns ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( 'CREATE TABLE requires at least one column.' );
		}

		foreach ( $sequences as $sequence ) {
			$this->execute_duckdb_query( $sequence['sql'], 'Failed to create DuckDB AUTO_INCREMENT sequence' );
			if ( null !== $auto_increment_seed && $auto_increment_seed > 1 ) {
				$this->prime_auto_increment_sequence(
					$this->sequence_name( $table_name, (string) $sequence['column_name'], $temporary ),
					$auto_increment_seed
				);
			}
		}

		$table_sql = $temporary ? 'CREATE TEMPORARY TABLE ' : 'CREATE TABLE ';
		if ( $if_not_exists ) {
			$table_sql .= 'IF NOT EXISTS ';
		}
		$table_sql .= $this->connection->quote_identifier( $table_name );
		$table_sql .= ' (';
		$table_sql .= implode( ', ', array_merge( $columns, $constraints ) );
		$table_sql .= ')';

		$result = $this->execute_duckdb_query( $table_sql, 'Failed to create DuckDB table' );
		foreach ( $indexes as $index_definition ) {
			$this->execute_duckdb_query( $index_definition['sql'], 'Failed to create DuckDB index' );
			$this->record_index_metadata( $index_definition );
		}
		$this->record_column_metadata( $table_name, $this->apply_column_key_metadata( $metadata, $primary_key, $indexes ), $temporary );
		$this->record_table_metadata( $table_name, $table_metadata, $temporary );
		$this->record_check_metadata( $table_name, $check_constraints, $temporary );
		$this->record_foreign_key_metadata( $table_name, $foreign_keys, $temporary );

		return $result;
	}

	/**
	 * Execute a supported CREATE INDEX statement.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_create_index( array $tokens ): WP_DuckDB_Result_Statement {
		$index = 0;
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::CREATE_SYMBOL, 'Expected CREATE.' );
		++$index;

		$unique = false;
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::UNIQUE_SYMBOL === $tokens[ $index ]->id ) {
			$unique = true;
			++$index;
		}

		if (
			isset( $tokens[ $index ] )
			&& ( WP_MySQL_Lexer::FULLTEXT_SYMBOL === $tokens[ $index ]->id || WP_MySQL_Lexer::SPATIAL_SYMBOL === $tokens[ $index ]->id )
		) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported CREATE INDEX type in DuckDB driver: ' . $tokens[ $index ]->get_bytes() . '.' );
		}

		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::INDEX_SYMBOL, 'Expected INDEX in CREATE INDEX statement.' );
		++$index;

		$mysql_index_name = $this->identifier_value( $tokens[ $index ] ?? null );
		++$index;

		$index = $this->skip_optional_index_type( $tokens, $index );
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::ON_SYMBOL, 'Expected ON in CREATE INDEX statement.' );
		++$index;

		$table_name = $this->identifier_value( $tokens[ $index ] ?? null );
		++$index;
		$table_reference = $this->resolve_visible_user_table_reference( $table_name );
		if ( null === $table_reference ) {
			throw new WP_DuckDB_Driver_Exception( "Unknown table '{$this->database}.{$table_name}' in CREATE INDEX statement." );
		}
		$table_name = $table_reference['table_name'];

		$index                                     = $this->skip_optional_index_type( $tokens, $index );
		list( $columns, $column_metadata, $index ) = $this->translate_index_column_list( $tokens, $index );
		$this->assert_supported_index_options( $tokens, $index );

		$index_definition = $this->build_secondary_index_definition( $table_name, $mysql_index_name, $unique, $columns, $column_metadata, $table_reference['temporary'] );
		$result           = $this->execute_duckdb_query( $index_definition['sql'], 'Failed to create DuckDB index' );
		$this->record_index_metadata( $index_definition );

		return $result;
	}

	/**
	 * Execute a supported INSERT statement.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_insert( array $tokens ): WP_DuckDB_Result_Statement {
		$index  = 1;
		$ignore = false;
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::IGNORE_SYMBOL === $tokens[ $index ]->id ) {
			$ignore = true;
			++$index;
		}
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::INTO_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
		}

		$select_index = $this->find_insert_select_index( $tokens, $index );
		if ( null !== $select_index ) {
			if ( null !== $this->find_on_duplicate_key_update_index( $tokens ) ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported INSERT statement in DuckDB driver. INSERT ... SELECT ... ON DUPLICATE KEY UPDATE is not supported.' );
			}
			return $this->execute_auto_increment_write(
				$this->identifier_value( $tokens[ $index ] ?? null ),
				$this->translate_insert_select_tokens_to_duckdb_sql( $tokens, $index, $ignore ),
				'Failed to execute DuckDB INSERT',
				$tokens,
				$index
			);
		}

		$set_index = $this->find_insert_set_index( $tokens, $index );
		if ( null !== $set_index ) {
			if ( null !== $this->find_on_duplicate_key_update_index( $tokens ) ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported INSERT statement in DuckDB driver. INSERT ... SET ... ON DUPLICATE KEY UPDATE is not supported.' );
			}
			return $this->execute_auto_increment_write(
				$this->identifier_value( $tokens[ $index ] ?? null ),
				$this->translate_insert_set_tokens_to_duckdb_sql( $tokens, $index, $set_index, $ignore ),
				'Failed to execute DuckDB INSERT',
				$tokens,
				$index
			);
		}

		$this->assert_values_write_statement( $tokens, $index, 'INSERT' );

		$on_duplicate_index = $this->find_on_duplicate_key_update_index( $tokens );
		if ( null !== $on_duplicate_index ) {
			if ( $ignore ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported INSERT statement in DuckDB driver. INSERT IGNORE ... ON DUPLICATE KEY UPDATE is not supported.' );
			}
			return $this->execute_auto_increment_write(
				$this->identifier_value( $tokens[ $index ] ?? null ),
				$this->translate_insert_on_duplicate_key_update_tokens_to_duckdb_sql( $tokens, $index, $on_duplicate_index ),
				'Failed to execute DuckDB INSERT',
				$tokens,
				$index
			);
		}

		if ( ! $ignore ) {
			$this->assert_insert_values_do_not_conflict_with_case_insensitive_unique_keys( $tokens, $index );
		}

		return $this->execute_auto_increment_write(
			$this->identifier_value( $tokens[ $index ] ?? null ),
			$ignore
					? $this->translate_insert_ignore_tokens_to_duckdb_sql( $tokens, $index )
					: $this->translate_tokens_to_duckdb_sql( $tokens ),
			'Failed to execute DuckDB INSERT',
			$tokens,
			$index
		);
	}

	/**
	 * Execute a supported REPLACE statement.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_replace( array $tokens ): WP_DuckDB_Result_Statement {
		$index = 1;
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::INTO_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
		}

		if ( null !== $this->find_insert_select_index( $tokens, $index ) ) {
			return $this->execute_auto_increment_write(
				$this->identifier_value( $tokens[ $index ] ?? null ),
				$this->translate_replace_tokens_to_duckdb_sql( $tokens ),
				'Failed to execute DuckDB REPLACE',
				$tokens,
				$index
			);
		}

		$this->assert_values_write_statement( $tokens, $index, 'REPLACE' );

		$manual_replace = $this->execute_replace_values_with_manual_conflict_handling( $tokens, $index );
		if ( null !== $manual_replace ) {
			return $manual_replace;
		}

		return $this->execute_auto_increment_write(
			$this->identifier_value( $tokens[ $index ] ?? null ),
			$this->translate_replace_tokens_to_duckdb_sql( $tokens ),
			'Failed to execute DuckDB REPLACE',
			$tokens,
			$index
		);
	}

	/**
	 * Execute a supported UPDATE statement.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_update( array $tokens ): WP_DuckDB_Result_Statement {
		$joined_update = $this->parse_joined_update_shape( $tokens );
		if ( null !== $joined_update ) {
			return $this->execute_joined_update( $joined_update );
		}

		$reference = $this->parse_single_table_dml_reference( $tokens, 1, 'UPDATE' );
		$index     = $reference['next_index'];

		if ( ! isset( $tokens[ $index ] ) || WP_MySQL_Lexer::SET_SYMBOL !== $tokens[ $index ]->id ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported UPDATE statement in DuckDB driver. Only simple single-table UPDATE is supported.' );
		}
		++$index;

		$clauses       = $this->dml_clause_indexes( $tokens, $index );
		$update_end    = $clauses['where'] ?? $clauses['order'] ?? $clauses['limit'] ?? count( $tokens );
		$update_tokens = array_slice( $tokens, $index, $update_end - $index );
		if ( count( $update_tokens ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported UPDATE statement in DuckDB driver. UPDATE list is required.' );
		}

		$sql = 'UPDATE ' . $this->dml_table_reference_sql( $reference )
			. ' SET '
			. $this->translate_update_assignment_tokens_to_duckdb_sql( $update_tokens, $reference );

		if ( null !== $clauses['order'] || null !== $clauses['limit'] ) {
			$this->assert_dml_rowid_rewrite_supported( $reference['table_name'], 'UPDATE', $reference['temporary'] );
			$sql .= ' WHERE rowid IN ( '
				. $this->dml_rowid_subquery_sql( $tokens, $clauses, $reference )
				. ' )';
		} elseif ( null !== $clauses['where'] ) {
			$where_end = $clauses['order'] ?? $clauses['limit'] ?? count( $tokens );
			$sql      .= ' WHERE ' . $this->translate_tokens_to_duckdb_sql(
				array_slice( $tokens, $clauses['where'] + 1, $where_end - $clauses['where'] - 1 )
			);
		}

		return $this->execute_duckdb_query( $sql, 'Failed to execute DuckDB UPDATE' );
	}

	/**
	 * Parse supported joined UPDATE shapes.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return array{target:array{alias:string,table_name:string,requested_table_name:string},sources:array<int,array{alias:string,sql:string,table_name:string|null}>,join_predicates:array<int,array<int,WP_Parser_Token>>,update_tokens:array<int,WP_Parser_Token>,where_tokens:array<int,WP_Parser_Token>}|null Parsed shape, or null for single-table UPDATE.
	 */
	private function parse_joined_update_shape( array $tokens ): ?array {
		if ( ! isset( $tokens[1] ) ) {
			return null;
		}

		if (
			WP_MySQL_Lexer::LOW_PRIORITY_SYMBOL === $tokens[1]->id
			|| WP_MySQL_Lexer::IGNORE_SYMBOL === $tokens[1]->id
		) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported UPDATE statement in DuckDB driver. UPDATE modifiers are not supported.' );
		}

		$set_index = $this->find_top_level_token_index( $tokens, 1, WP_MySQL_Lexer::SET_SYMBOL );
		if ( null === $set_index ) {
			return null;
		}

		$table_tokens = array_slice( $tokens, 1, $set_index - 1 );
		if (
			! $this->contains_top_level_token_id( $table_tokens, WP_MySQL_Lexer::COMMA_SYMBOL )
			&& ! $this->contains_top_level_join_token( $table_tokens )
		) {
			return null;
		}

		$references = $this->parse_joined_update_table_references( $table_tokens );
		if ( count( $references['sources'] ) === 0 ) {
			return null;
		}

		$clauses = $this->dml_clause_indexes( $tokens, $set_index + 1 );
		if ( null !== $clauses['order'] || null !== $clauses['limit'] ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported UPDATE statement in DuckDB driver. Joined UPDATE with ORDER BY or LIMIT is not supported.' );
		}

		$update_end    = $clauses['where'] ?? count( $tokens );
		$update_tokens = array_slice( $tokens, $set_index + 1, $update_end - $set_index - 1 );
		if ( count( $update_tokens ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported UPDATE statement in DuckDB driver. UPDATE list is required.' );
		}

		$where_tokens = array();
		if ( null !== $clauses['where'] ) {
			$where_tokens = array_slice( $tokens, $clauses['where'] + 1 );
		}

		return array(
			'target'          => $references['target'],
			'sources'         => $references['sources'],
			'join_predicates' => $references['join_predicates'],
			'update_tokens'   => $update_tokens,
			'where_tokens'    => $where_tokens,
		);
	}

	/**
	 * Execute a parsed joined UPDATE.
	 *
	 * @param array{target:array{alias:string,table_name:string,requested_table_name:string},sources:array<int,array{alias:string,sql:string,table_name:string|null}>,join_predicates:array<int,array<int,WP_Parser_Token>>,update_tokens:array<int,WP_Parser_Token>,where_tokens:array<int,WP_Parser_Token>} $shape Parsed shape.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_joined_update( array $shape ): WP_DuckDB_Result_Statement {
		$sql = 'UPDATE '
			. $this->connection->quote_identifier( $shape['target']['table_name'] )
			. ' AS '
			. $this->connection->quote_identifier( $shape['target']['alias'] )
			. ' SET '
			. $this->translate_joined_update_assignment_tokens_to_duckdb_sql( $shape['update_tokens'], $shape['target'], $shape['sources'] )
			. ' FROM '
			. implode( ', ', array_column( $shape['sources'], 'sql' ) );

		$where_clauses = array();
		if ( count( $shape['where_tokens'] ) > 0 ) {
			$where_clauses[] = $this->translate_tokens_to_duckdb_sql( $shape['where_tokens'] );
		}
		foreach ( $shape['join_predicates'] as $predicate ) {
			$where_clauses[] = $this->translate_tokens_to_duckdb_sql( $predicate );
		}
		if ( count( $where_clauses ) > 0 ) {
			$sql .= ' WHERE (' . implode( ') AND (', $where_clauses ) . ')';
		}

		return $this->execute_duckdb_query( $sql, 'Failed to execute DuckDB joined UPDATE' );
	}

	/**
	 * Execute a supported DELETE statement.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_delete( array $tokens ): WP_DuckDB_Result_Statement {
		$multi_table_delete = $this->parse_multi_table_delete_shape( $tokens );
		if ( null !== $multi_table_delete ) {
			return $this->execute_multi_table_delete( $multi_table_delete );
		}

		if ( ! isset( $tokens[1] ) || WP_MySQL_Lexer::FROM_SYMBOL !== $tokens[1]->id ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported DELETE statement in DuckDB driver. Only DELETE FROM table is supported.' );
		}

		$reference = $this->parse_single_table_dml_reference( $tokens, 2, 'DELETE' );
		$clauses   = $this->dml_clause_indexes( $tokens, $reference['next_index'] );

		if ( null !== $clauses['order'] || null !== $clauses['limit'] ) {
			$this->assert_dml_rowid_rewrite_supported( $reference['table_name'], 'DELETE', $reference['temporary'] );
			$sql = 'DELETE FROM ' . $this->connection->quote_identifier( $reference['table_name'] )
				. ' WHERE rowid IN ( '
				. $this->dml_rowid_subquery_sql( $tokens, $clauses, $reference )
				. ' )';
		} else {
			$sql = 'DELETE FROM ' . $this->dml_table_reference_sql( $reference );
			if ( null !== $clauses['where'] ) {
				$sql .= ' WHERE ' . $this->translate_tokens_to_duckdb_sql(
					array_slice( $tokens, $clauses['where'] + 1 )
				);
			}
		}

		return $this->execute_duckdb_query( $sql, 'Failed to execute DuckDB DELETE' );
	}

	/**
	 * Parse supported multi-table DELETE shapes.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return array{targets:array<int,array{alias:string,column:string,table_name:string,temporary:bool}>,from_sql:string,join_predicates:array<int,array<int,WP_Parser_Token>>,where_tokens:array<int,WP_Parser_Token>,temp_table:string}|null Parsed shape, or null for single-table DELETE.
	 */
	private function parse_multi_table_delete_shape( array $tokens ): ?array {
		if ( ! isset( $tokens[1] ) ) {
			return null;
		}

		if (
			WP_MySQL_Lexer::LOW_PRIORITY_SYMBOL === $tokens[1]->id
			|| WP_MySQL_Lexer::QUICK_SYMBOL === $tokens[1]->id
			|| WP_MySQL_Lexer::IGNORE_SYMBOL === $tokens[1]->id
		) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported DELETE statement in DuckDB driver. DELETE modifiers are not supported.' );
		}

		$target_tokens    = null;
		$table_ref_start  = null;
		$first_clause_pos = null;

		$using_form = WP_MySQL_Lexer::FROM_SYMBOL === $tokens[1]->id;
		if ( $using_form ) {
			$using_index = $this->find_top_level_token_index( $tokens, 2, WP_MySQL_Lexer::USING_SYMBOL );
			if ( null === $using_index ) {
				return null;
			}
			$target_tokens   = array_slice( $tokens, 2, $using_index - 2 );
			$table_ref_start = $using_index + 1;
		} else {
			$from_index = $this->find_top_level_token_index( $tokens, 1, WP_MySQL_Lexer::FROM_SYMBOL );
			if ( null === $from_index ) {
				return null;
			}
			$target_tokens   = array_slice( $tokens, 1, $from_index - 1 );
			$table_ref_start = $from_index + 1;
		}

		$clauses = $this->dml_clause_indexes( $tokens, $table_ref_start );
		if ( null !== $clauses['order'] || null !== $clauses['limit'] ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported DELETE statement in DuckDB driver. Multi-table DELETE with ORDER BY or LIMIT is not supported.' );
		}
		$first_clause_pos = $clauses['where'] ?? count( $tokens );

		$table_ref_tokens = array_slice( $tokens, $table_ref_start, $first_clause_pos - $table_ref_start );
		if ( count( $target_tokens ) === 0 || count( $table_ref_tokens ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported DELETE statement in DuckDB driver. Multi-table DELETE requires target aliases and table references.' );
		}

		$target_aliases = $this->parse_multi_delete_target_aliases( $target_tokens );
		$references     = $this->parse_multi_delete_table_references( $table_ref_tokens );
		$targets        = array();
		foreach ( $target_aliases as $offset => $target_alias ) {
			$key = strtolower( $target_alias );
			if ( ! isset( $references['by_alias'][ $key ] ) ) {
				throw new WP_DuckDB_Driver_Exception( "Unknown DELETE target alias '{$target_alias}' in DuckDB driver." );
			}
			$reference = $references['by_alias'][ $key ];
			$this->assert_dml_rowid_rewrite_supported( $reference['table_name'], 'DELETE', $reference['temporary'] );
			$targets[] = array(
				'alias'      => $reference['alias'],
				'column'     => '__target_' . $offset . '_rowid',
				'table_name' => $reference['table_name'],
				'temporary'  => $reference['temporary'],
			);
		}

		$where_tokens = array();
		if ( null !== $clauses['where'] ) {
			$where_tokens = array_slice( $tokens, $clauses['where'] + 1 );
		}

		return array(
			'targets'         => $targets,
			'from_sql'        => $references['sql'],
			'join_predicates' => $references['join_predicates'],
			'where_tokens'    => $where_tokens,
			'temp_table'      => '__wp_duckdb_dml_delete_' . substr( hash( 'sha256', (string) $this->last_mysql_query ), 0, 16 ),
		);
	}

	/**
	 * Execute a parsed multi-table DELETE.
	 *
	 * @param array{targets:array<int,array{alias:string,column:string,table_name:string,temporary:bool}>,from_sql:string,join_predicates:array<int,array<int,WP_Parser_Token>>,where_tokens:array<int,WP_Parser_Token>,temp_table:string} $shape Parsed shape.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_multi_table_delete( array $shape ): WP_DuckDB_Result_Statement {
		return $this->execute_schema_lifecycle_change(
			function () use ( $shape ): WP_DuckDB_Result_Statement {
				$temp_table = $shape['temp_table'];
				$this->execute_duckdb_query(
					'DROP TABLE IF EXISTS ' . $this->connection->quote_identifier( $temp_table ),
					'Failed to reset DuckDB multi-table DELETE targets'
				);

				$select_list = array();
				foreach ( $shape['targets'] as $target ) {
					$select_list[] = $this->connection->quote_identifier( $target['alias'] )
						. '.rowid AS '
						. $this->connection->quote_identifier( $target['column'] );
				}

				$sql           = 'CREATE TEMP TABLE '
					. $this->connection->quote_identifier( $temp_table )
					. ' AS SELECT DISTINCT '
					. implode( ', ', $select_list )
					. ' FROM '
					. $shape['from_sql'];
				$where_clauses = array();
				if ( count( $shape['where_tokens'] ) > 0 ) {
					$where_clauses[] = $this->translate_tokens_to_duckdb_sql( $shape['where_tokens'] );
				}
				foreach ( $shape['join_predicates'] as $predicate ) {
					$where_clauses[] = $this->translate_tokens_to_duckdb_sql( $predicate );
				}
				if ( count( $where_clauses ) > 0 ) {
					$sql .= ' WHERE (' . implode( ') AND (', $where_clauses ) . ')';
				}

				$this->execute_duckdb_query( $sql, 'Failed to collect DuckDB multi-table DELETE targets' );

				$affected_rows = 0;
				foreach ( $shape['targets'] as $target ) {
					$stmt           = $this->execute_duckdb_query(
						'DELETE FROM '
							. $this->connection->quote_identifier( $target['table_name'] )
							. ' AS '
							. $this->connection->quote_identifier( $target['alias'] )
							. ' WHERE rowid IN ( SELECT '
							. $this->connection->quote_identifier( $target['column'] )
							. ' FROM '
							. $this->connection->quote_identifier( $temp_table )
							. ' WHERE '
							. $this->connection->quote_identifier( $target['column'] )
							. ' IS NOT NULL )',
						'Failed to execute DuckDB multi-table DELETE'
					);
					$affected_rows += $stmt->rowCount();
				}

				$this->execute_duckdb_query(
					'DROP TABLE IF EXISTS ' . $this->connection->quote_identifier( $temp_table ),
					'Failed to clean up DuckDB multi-table DELETE targets'
				);

				return new WP_DuckDB_Result_Statement( array(), array(), $affected_rows );
			}
		);
	}

	/**
	 * Parse a multi-table DELETE target alias list.
	 *
	 * @param WP_Parser_Token[] $tokens Target alias tokens.
	 * @return string[] Target aliases.
	 */
	private function parse_multi_delete_target_aliases( array $tokens ): array {
		$aliases = array();
		foreach ( $this->split_top_level_comma_items( $tokens ) as $item ) {
			if ( 1 !== count( $item ) ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported DELETE statement in DuckDB driver. DELETE target wildcards are not supported.' );
			}

			$alias = $this->identifier_value( $item[0] );
			$key   = strtolower( $alias );
			if ( isset( $aliases[ $key ] ) ) {
				throw new WP_DuckDB_Driver_Exception( "Duplicate DELETE target alias '{$alias}' in DuckDB driver." );
			}
			$aliases[ $key ] = $alias;
		}

		return array_values( $aliases );
	}

	/**
	 * Parse comma-separated table references for a bounded multi-table DELETE.
	 *
	 * @param WP_Parser_Token[] $tokens Table reference tokens.
	 * @return array{sql:string,by_alias:array<string,array{alias:string,table_name:string,temporary:bool}>,join_predicates:array<int,array<int,WP_Parser_Token>>} SQL and references keyed by lowercase alias.
	 */
	private function parse_multi_delete_table_references( array $tokens ): array {
		if ( $this->contains_top_level_join_token( $tokens ) ) {
			return $this->parse_joined_multi_delete_table_references( $tokens );
		}

		$sql_items = array();
		$by_alias  = array();
		foreach ( $this->split_top_level_comma_items( $tokens ) as $item ) {
			$reference = $this->parse_multi_delete_table_reference( $item );
			$key       = strtolower( $reference['alias'] );
			if ( isset( $by_alias[ $key ] ) ) {
				throw new WP_DuckDB_Driver_Exception( "Duplicate table alias '{$reference['alias']}' in DELETE statement." );
			}

			$by_alias[ $key ] = array(
				'alias'      => $reference['alias'],
				'table_name' => $reference['table_name'],
				'temporary'  => $reference['temporary'],
			);
			$sql_items[]      = $this->connection->quote_identifier( $reference['table_name'] )
				. ' AS '
				. $this->connection->quote_identifier( $reference['alias'] );
		}

		return array(
			'sql'             => implode( ', ', $sql_items ),
			'by_alias'        => $by_alias,
			'join_predicates' => array(),
		);
	}

	/**
	 * Parse joined table references for a bounded multi-table DELETE.
	 *
	 * @param WP_Parser_Token[] $tokens Table reference tokens.
	 * @return array{sql:string,by_alias:array<string,array{alias:string,table_name:string,temporary:bool}>,join_predicates:array<int,array<int,WP_Parser_Token>>} SQL and references keyed by lowercase alias.
	 */
	private function parse_joined_multi_delete_table_references( array $tokens ): array {
		$joined_references = $this->parse_joined_update_table_references( $tokens, 'DELETE', false );
		$references        = array_merge( array( $joined_references['target'] ), $joined_references['sources'] );
		$sql_items         = array();
		$by_alias          = array();
		$seen_aliases      = array();

		foreach ( $references as $reference ) {
			$key = strtolower( $reference['alias'] );
			if ( isset( $seen_aliases[ $key ] ) ) {
				throw new WP_DuckDB_Driver_Exception( "Duplicate table alias '{$reference['alias']}' in DELETE statement." );
			}
			$seen_aliases[ $key ] = true;
		}

		foreach ( $references as $reference ) {
			if ( null === $reference['table_name'] ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported DELETE statement in DuckDB driver. Derived table sources are not supported.' );
			}

			$key              = strtolower( $reference['alias'] );
			$by_alias[ $key ] = array(
				'alias'      => $reference['alias'],
				'table_name' => $reference['table_name'],
				'temporary'  => $reference['temporary'],
			);
			$sql_items[]      = $reference['sql'];
		}

		return array(
			'sql'             => implode( ', ', $sql_items ),
			'by_alias'        => $by_alias,
			'join_predicates' => $joined_references['join_predicates'],
		);
	}

	/**
	 * Parse one base table reference for multi-table DELETE.
	 *
	 * @param WP_Parser_Token[] $tokens Table reference tokens.
	 * @return array{alias:string,table_name:string,temporary:bool}
	 */
	private function parse_multi_delete_table_reference( array $tokens ): array {
		$index      = 0;
		$database   = null;
		$table_name = $this->identifier_value( $tokens[ $index ] ?? null );
		++$index;

		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $index ]->id ) {
			$database = $table_name;
			++$index;
			$table_name = $this->identifier_value( $tokens[ $index ] ?? null );
			++$index;

			if ( 0 === strcasecmp( $database, 'information_schema' ) ) {
				throw new WP_DuckDB_Driver_Exception( "Access denied for user 'duckdb'@'%' to database 'information_schema'" );
			}

			if ( 0 !== strcasecmp( $database, $this->database ) ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported DELETE statement in DuckDB driver. Only the current database is supported.' );
			}
		}

		if ( $this->is_duckdb_internal_table_name( $table_name ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported DELETE statement in DuckDB driver. Internal DuckDB metadata tables cannot be modified.' );
		}

		$table_reference = $this->resolve_visible_user_table_reference( $table_name );
		if ( null === $table_reference ) {
			throw new WP_DuckDB_Driver_Exception( "Unknown table '{$this->database}.{$table_name}' in DELETE statement." );
		}

		$alias = $table_name;
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::AS_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
			$alias = $this->identifier_value( $tokens[ $index ] ?? null );
			++$index;
		} elseif ( isset( $tokens[ $index ] ) ) {
			$alias = $this->identifier_value( $tokens[ $index ] );
			++$index;
		}

		if ( count( $tokens ) !== $index ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported DELETE statement in DuckDB driver. Table reference options are not supported.' );
		}

		return array(
			'alias'      => $alias,
			'table_name' => $table_reference['table_name'],
			'temporary'  => $table_reference['temporary'],
		);
	}

	/**
	 * Parse joined UPDATE table references.
	 *
	 * @param WP_Parser_Token[] $tokens Table reference tokens.
	 * @param string            $statement Statement name for diagnostics.
	 * @param bool              $first_factor_must_be_base Whether the first table factor must be a base table.
	 * @return array{target:array{alias:string,sql:string,table_name:string|null,requested_table_name:string,temporary:bool},sources:array<int,array{alias:string,sql:string,table_name:string|null,temporary:bool,requested_table_name:string}>,join_predicates:array<int,array<int,WP_Parser_Token>>}
	 */
	private function parse_joined_update_table_references( array $tokens, string $statement = 'UPDATE', bool $first_factor_must_be_base = true ): array {
		$items           = $this->split_top_level_comma_items( $tokens );
		$target_item     = array_shift( $items );
		$target_factor   = $this->parse_joined_update_table_factor( $target_item, 0, ! $first_factor_must_be_base, true, $statement );
		$target          = $target_factor['reference'];
		$sources         = array();
		$join_predicates = array();

		if ( $first_factor_must_be_base && null === $target['table_name'] ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ' . $statement . ' statement in DuckDB driver. Derived tables cannot be ' . ( 'UPDATE' === $statement ? 'updated' : 'deleted' ) . '.' );
		}

		$this->parse_joined_update_join_chain( $target_item, $target_factor['next_index'], $sources, $join_predicates, $statement );
		foreach ( $items as $item ) {
			$source    = $this->parse_joined_update_table_factor( $item, 0, true, false, $statement );
			$sources[] = $source['reference'];
			$this->parse_joined_update_join_chain( $item, $source['next_index'], $sources, $join_predicates, $statement );
		}

		return array(
			'target'          => array(
				'alias'                => $target['alias'],
				'sql'                  => $target['sql'],
				'table_name'           => $target['table_name'],
				'temporary'            => $target['temporary'],
				'requested_table_name' => $target['requested_table_name'],
			),
			'sources'         => $sources,
			'join_predicates' => $join_predicates,
		);
	}

	/**
	 * Parse one joined UPDATE table factor.
	 *
	 * @param WP_Parser_Token[] $tokens        Table reference tokens.
	 * @param int               $index         Current index.
	 * @param bool              $allow_derived Whether derived tables are allowed.
	 * @param bool              $is_target     Whether this factor is the UPDATE target.
	 * @param string            $statement     Statement name for diagnostics.
	 * @return array{reference:array{alias:string,sql:string,table_name:string|null,temporary:bool,requested_table_name:string},next_index:int}
	 */
	private function parse_joined_update_table_factor( array $tokens, int $index, bool $allow_derived, bool $is_target, string $statement = 'UPDATE' ): array {
		if ( ! isset( $tokens[ $index ] ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ' . $statement . ' statement in DuckDB driver. Expected table reference.' );
		}

		if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $index ]->id ) {
			if ( ! $allow_derived ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported ' . $statement . ' statement in DuckDB driver. Derived tables cannot be ' . ( 'UPDATE' === $statement ? 'updated' : 'deleted' ) . '.' );
			}

			$close_index = $this->skip_balanced_parentheses( $tokens, $index ) - 1;
			$inner       = array_slice( $tokens, $index + 1, $close_index - $index - 1 );
			if ( ! isset( $inner[0] ) || WP_MySQL_Lexer::SELECT_SYMBOL !== $inner[0]->id ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported ' . $statement . ' statement in DuckDB driver. Only derived SELECT sources are supported.' );
			}
			if ( $this->contains_information_schema_reference( $inner ) ) {
				throw new WP_DuckDB_Driver_Exception( "Access denied for user 'duckdb'@'%' to database 'information_schema'" );
			}

			$index = $close_index + 1;
			if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::AS_SYMBOL === $tokens[ $index ]->id ) {
				++$index;
			}
			$alias = $this->identifier_value( $tokens[ $index ] ?? null );
			++$index;

			return array(
				'reference'  => array(
					'alias'                => $alias,
					'sql'                  => '( '
						. $this->translate_tokens_to_duckdb_sql( $this->strip_for_update_locking_clause( $inner ) )
						. ' ) AS '
						. $this->connection->quote_identifier( $alias ),
					'table_name'           => null,
					'temporary'            => false,
					'requested_table_name' => $alias,
				),
				'next_index' => $index,
			);
		}

		$database   = null;
		$table_name = $this->identifier_value( $tokens[ $index ] ?? null );
		++$index;

		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $index ]->id ) {
			$database = $table_name;
			++$index;
			$table_name = $this->identifier_value( $tokens[ $index ] ?? null );
			++$index;

			if ( 0 === strcasecmp( $database, 'information_schema' ) ) {
				throw new WP_DuckDB_Driver_Exception( "Access denied for user 'duckdb'@'%' to database 'information_schema'" );
			}

			if ( 0 !== strcasecmp( $database, $this->database ) ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported ' . $statement . ' statement in DuckDB driver. Only the current database is supported.' );
			}
		}

		if ( $this->is_duckdb_internal_table_name( $table_name ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ' . $statement . ' statement in DuckDB driver. Internal DuckDB metadata tables cannot be modified.' );
		}

		$table_reference = $this->resolve_visible_user_table_reference( $table_name );
		if ( null === $table_reference ) {
			throw new WP_DuckDB_Driver_Exception( "Unknown table '{$this->database}.{$table_name}' in {$statement} statement." );
		}

		$alias = $table_name;
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::AS_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
			$alias = $this->identifier_value( $tokens[ $index ] ?? null );
			++$index;
		} elseif ( isset( $tokens[ $index ] ) && ! $this->is_joined_update_table_reference_boundary( $tokens[ $index ] ) ) {
			$alias = $this->identifier_value( $tokens[ $index ] );
			++$index;
		}

		return array(
			'reference'  => array(
				'alias'                => $alias,
				'sql'                  => $this->connection->quote_identifier( $table_reference['table_name'] )
					. ' AS '
					. $this->connection->quote_identifier( $alias ),
				'table_name'           => $table_reference['table_name'],
				'temporary'            => $table_reference['temporary'],
				'requested_table_name' => $table_name,
			),
			'next_index' => $index,
		);
	}

	/**
	 * Parse a joined UPDATE join chain.
	 *
	 * @param WP_Parser_Token[] $tokens          Table reference tokens.
	 * @param int               $index           Current index.
	 * @param array             $sources         Source references.
	 * @param array             $join_predicates Join predicate token lists.
	 * @param string            $statement       Statement name for diagnostics.
	 */
	private function parse_joined_update_join_chain( array $tokens, int $index, array &$sources, array &$join_predicates, string $statement = 'UPDATE' ): void {
		while ( $index < count( $tokens ) ) {
			if ( WP_MySQL_Lexer::INNER_SYMBOL === $tokens[ $index ]->id ) {
				++$index;
				$this->expect_token( $tokens, $index, WP_MySQL_Lexer::JOIN_SYMBOL, 'Expected JOIN after INNER.' );
			} elseif ( WP_MySQL_Lexer::JOIN_SYMBOL !== $tokens[ $index ]->id ) {
				if ( $this->is_unsupported_joined_update_join_token( $tokens[ $index ] ) ) {
					throw new WP_DuckDB_Driver_Exception( 'Unsupported ' . $statement . ' statement in DuckDB driver. Only comma joins and INNER JOIN ... ON are supported.' );
				}
				throw new WP_DuckDB_Driver_Exception( 'Unsupported ' . $statement . ' statement in DuckDB driver. Table reference options are not supported.' );
			}

			++$index;
			$source    = $this->parse_joined_update_table_factor( $tokens, $index, true, false, $statement );
			$sources[] = $source['reference'];
			$index     = $source['next_index'];

			if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::USING_SYMBOL === $tokens[ $index ]->id ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported ' . $statement . ' statement in DuckDB driver. JOIN ... USING is not supported.' );
			}

			$this->expect_token( $tokens, $index, WP_MySQL_Lexer::ON_SYMBOL, 'Expected ON in joined ' . $statement . ' statement.' );
			++$index;
			$predicate_end = $this->find_next_joined_update_join_index( $tokens, $index ) ?? count( $tokens );
			if ( $predicate_end === $index ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported ' . $statement . ' statement in DuckDB driver. JOIN predicate is required.' );
			}
			$join_predicates[] = array_slice( $tokens, $index, $predicate_end - $index );
			$index             = $predicate_end;
		}
	}

	/**
	 * Check whether a token stream contains a top-level JOIN keyword.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @return bool Whether a top-level join is present.
	 */
	private function contains_top_level_join_token( array $tokens ): bool {
		$join_tokens = array(
			WP_MySQL_Lexer::JOIN_SYMBOL,
			WP_MySQL_Lexer::INNER_SYMBOL,
			WP_MySQL_Lexer::LEFT_SYMBOL,
			WP_MySQL_Lexer::RIGHT_SYMBOL,
			WP_MySQL_Lexer::NATURAL_SYMBOL,
			WP_MySQL_Lexer::STRAIGHT_JOIN_SYMBOL,
		);
		$depth       = 0;
		foreach ( $tokens as $token ) {
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $token->id ) {
				++$depth;
				continue;
			}
			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $token->id ) {
				--$depth;
				continue;
			}
			if ( 0 === $depth && in_array( $token->id, $join_tokens, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a token stream contains a token at top-level depth.
	 *
	 * @param WP_Parser_Token[] $tokens   Token stream.
	 * @param int               $token_id Token ID to find.
	 * @return bool Whether the token is present.
	 */
	private function contains_top_level_token_id( array $tokens, int $token_id ): bool {
		return null !== $this->find_top_level_token_index( $tokens, 0, $token_id );
	}

	/**
	 * Check whether a token ends a joined UPDATE table factor.
	 *
	 * @param WP_Parser_Token $token Token.
	 * @return bool Whether the token is a boundary.
	 */
	private function is_joined_update_table_reference_boundary( WP_Parser_Token $token ): bool {
		return WP_MySQL_Lexer::ON_SYMBOL === $token->id
			|| WP_MySQL_Lexer::JOIN_SYMBOL === $token->id
			|| WP_MySQL_Lexer::INNER_SYMBOL === $token->id
			|| WP_MySQL_Lexer::LEFT_SYMBOL === $token->id
			|| WP_MySQL_Lexer::RIGHT_SYMBOL === $token->id
			|| WP_MySQL_Lexer::NATURAL_SYMBOL === $token->id
			|| WP_MySQL_Lexer::CROSS_SYMBOL === $token->id
			|| WP_MySQL_Lexer::STRAIGHT_JOIN_SYMBOL === $token->id
			|| WP_MySQL_Lexer::USING_SYMBOL === $token->id;
	}

	/**
	 * Check whether a join token is unsupported in joined UPDATE.
	 *
	 * @param WP_Parser_Token $token Token.
	 * @return bool Whether this is an unsupported join token.
	 */
	private function is_unsupported_joined_update_join_token( WP_Parser_Token $token ): bool {
		return in_array(
			$token->id,
			array(
				WP_MySQL_Lexer::LEFT_SYMBOL,
				WP_MySQL_Lexer::RIGHT_SYMBOL,
				WP_MySQL_Lexer::NATURAL_SYMBOL,
				WP_MySQL_Lexer::CROSS_SYMBOL,
				WP_MySQL_Lexer::STRAIGHT_JOIN_SYMBOL,
				WP_MySQL_Lexer::USING_SYMBOL,
			),
			true
		);
	}

	/**
	 * Find the next join operator in a joined UPDATE table item.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $start  First token to scan.
	 * @return int|null Token index, or null when absent.
	 */
	private function find_next_joined_update_join_index( array $tokens, int $start ): ?int {
		$depth = 0;
		for ( $index = $start; $index < count( $tokens ); ++$index ) {
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $index ]->id ) {
				++$depth;
				continue;
			}
			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $index ]->id ) {
				--$depth;
				continue;
			}
			if (
				0 === $depth
				&& (
					WP_MySQL_Lexer::JOIN_SYMBOL === $tokens[ $index ]->id
					|| WP_MySQL_Lexer::INNER_SYMBOL === $tokens[ $index ]->id
					|| $this->is_unsupported_joined_update_join_token( $tokens[ $index ] )
				)
			) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Check whether a token stream references information_schema.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @return bool Whether information_schema is referenced.
	 */
	private function contains_information_schema_reference( array $tokens ): bool {
		foreach ( $tokens as $index => $token ) {
			if (
				isset( $tokens[ $index + 1 ] )
				&& 0 === strcasecmp( $token->get_value(), 'information_schema' )
				&& WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $index + 1 ]->id
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Execute a supported DROP statement.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_drop( array $tokens ): WP_DuckDB_Result_Statement {
		if ( isset( $tokens[1] ) && WP_MySQL_Lexer::VIEW_SYMBOL === $tokens[1]->id ) {
			return $this->execute_drop_view( $tokens );
		}

		if (
			isset( $tokens[1], $tokens[2] )
			&& WP_MySQL_Lexer::TEMPORARY_SYMBOL === $tokens[1]->id
			&& WP_MySQL_Lexer::TABLE_SYMBOL === $tokens[2]->id
		) {
			return $this->execute_drop_table( $tokens, true );
		}

		if ( isset( $tokens[1] ) && WP_MySQL_Lexer::TABLE_SYMBOL === $tokens[1]->id ) {
			return $this->execute_drop_table( $tokens );
		}

		if ( isset( $tokens[1] ) && WP_MySQL_Lexer::TABLES_SYMBOL === $tokens[1]->id ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported DROP TABLES statement in DuckDB driver. Use DROP TABLE.' );
		}

		if (
			isset( $tokens[1] )
			&& in_array( $tokens[1]->id, array( WP_MySQL_Lexer::INDEX_SYMBOL, WP_MySQL_Lexer::ONLINE_SYMBOL, WP_MySQL_Lexer::OFFLINE_SYMBOL ), true )
		) {
			return $this->execute_drop_index( $tokens );
		}

		throw new WP_DuckDB_Driver_Exception( 'Unsupported DROP statement in DuckDB driver. Only DROP TABLE and DROP INDEX are supported.' );
	}

	/**
	 * Execute bounded DROP VIEW passthrough for native DuckDB views.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_drop_view( array $tokens ): WP_DuckDB_Result_Statement {
		$index = 0;
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::DROP_SYMBOL, 'Expected DROP.' );
		++$index;
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::VIEW_SYMBOL, 'Expected VIEW in DROP VIEW statement.' );
		++$index;

		$if_exists = false;
		if (
			isset( $tokens[ $index + 1 ] )
			&& WP_MySQL_Lexer::IF_SYMBOL === $tokens[ $index ]->id
			&& WP_MySQL_Lexer::EXISTS_SYMBOL === $tokens[ $index + 1 ]->id
		) {
			$if_exists = true;
			$index    += 2;
		}

		$view_tokens = array_slice( $tokens, $index );
		if ( count( $view_tokens ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( 'DROP VIEW requires a view name.' );
		}
		if ( count( $this->split_top_level_comma_items( $view_tokens ) ) > 1 ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported DROP VIEW statement in DuckDB driver. Only a single view target is supported.' );
		}

		$reference = $this->parse_schema_lifecycle_table_reference( $tokens, $index, 'DROP VIEW' );
		if ( count( $tokens ) !== $reference['next_index'] ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported DROP VIEW statement in DuckDB driver. View aliases and extra options are not supported.' );
		}

		return $this->execute_duckdb_query(
			'DROP VIEW '
				. ( $if_exists ? 'IF EXISTS ' : '' )
				. $this->connection->quote_identifier( $reference['requested_table_name'] ),
			'Failed to drop DuckDB view'
		);
	}

	/**
	 * Execute DROP TABLE.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_drop_table( array $tokens, bool $temporary_only = false ): WP_DuckDB_Result_Statement {
		$index = 0;
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::DROP_SYMBOL, 'Expected DROP.' );
		++$index;
		if ( $temporary_only ) {
			$this->expect_token( $tokens, $index, WP_MySQL_Lexer::TEMPORARY_SYMBOL, 'Expected TEMPORARY in DROP TEMPORARY TABLE statement.' );
			++$index;
		}
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::TABLE_SYMBOL, 'Expected TABLE in DROP TABLE statement.' );
		++$index;

		$if_exists = false;
		if (
			isset( $tokens[ $index + 1 ] )
			&& WP_MySQL_Lexer::IF_SYMBOL === $tokens[ $index ]->id
			&& WP_MySQL_Lexer::EXISTS_SYMBOL === $tokens[ $index + 1 ]->id
		) {
			$if_exists = true;
			$index    += 2;
		}

		$table_tokens = array_slice( $tokens, $index );
		if ( count( $table_tokens ) > 0 ) {
			$last_token = $table_tokens[ count( $table_tokens ) - 1 ];
			if ( WP_MySQL_Lexer::RESTRICT_SYMBOL === $last_token->id || WP_MySQL_Lexer::CASCADE_SYMBOL === $last_token->id ) {
				array_pop( $table_tokens );
			}
		}
		if ( count( $table_tokens ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( 'DROP TABLE requires at least one table name.' );
		}

		$targets = array();
		foreach ( $this->split_top_level_comma_items( $table_tokens ) as $table_item ) {
			$reference = $this->parse_schema_lifecycle_table_reference( $table_item, 0, 'DROP TABLE' );
			if ( count( $table_item ) !== $reference['next_index'] ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported DROP TABLE statement in DuckDB driver. Table aliases and extra table options are not supported.' );
			}
			$targets[] = $reference['requested_table_name'];
		}

		return $this->execute_schema_lifecycle_change(
			function () use ( $targets, $if_exists, $temporary_only ): WP_DuckDB_Result_Statement {
				foreach ( $targets as $requested_table_name ) {
					$table_reference = $temporary_only
						? $this->resolve_temporary_user_table_reference( $requested_table_name )
						: $this->resolve_visible_user_table_reference( $requested_table_name );
					if ( null === $table_reference ) {
						if ( $if_exists ) {
							continue;
						}
						throw new WP_DuckDB_Driver_Exception( "Unknown table '{$this->database}.{$requested_table_name}' in DROP TABLE statement." );
					}
					$table_name = $table_reference['table_name'];
					$temporary  = $table_reference['temporary'];

					$sequence_names = $this->auto_increment_sequences_for_table( $table_name, $temporary );
					$index_names    = array_map(
						function ( array $index_definition ): string {
							return $index_definition['index_name'];
						},
						$this->secondary_index_definitions_for_table( $table_name, $temporary )
					);

					$this->execute_duckdb_query(
						'DROP TABLE ' . $this->connection->quote_identifier( $table_name ),
						'Failed to drop DuckDB table'
					);

					foreach ( $index_names as $index_name ) {
						$this->drop_physical_secondary_index( $table_name, $index_name, true, $temporary );
					}
					$this->drop_auto_increment_sequences( $sequence_names );
					$this->delete_table_lifecycle_metadata( $table_name, $temporary );
				}

				$this->invalidate_information_schema_compatibility_tables();
				return $this->empty_ddl_result();
			}
		);
	}

	/**
	 * Execute TRUNCATE [TABLE].
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_truncate_table( array $tokens ): WP_DuckDB_Result_Statement {
		$index = 0;
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::TRUNCATE_SYMBOL, 'Expected TRUNCATE.' );
		++$index;
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::TABLE_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
		}

		$reference = $this->parse_schema_lifecycle_table_reference( $tokens, $index, 'TRUNCATE' );
		if ( count( $tokens ) !== $reference['next_index'] ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported TRUNCATE statement in DuckDB driver. Only a single table target is supported.' );
		}

		return $this->execute_schema_lifecycle_change(
			function () use ( $reference ): WP_DuckDB_Result_Statement {
				$table_reference = $this->resolve_required_lifecycle_table( $reference['requested_table_name'], 'TRUNCATE' );
				$table_name      = $table_reference['table_name'];
				$temporary       = $table_reference['temporary'];
				if ( null === $this->auto_increment_metadata_for_table( $table_name, $temporary ) ) {
					$this->execute_duckdb_query(
						'DELETE FROM ' . $this->connection->quote_identifier( $table_name ),
						'Failed to truncate DuckDB table'
					);
				} else {
					$this->rebuild_empty_auto_increment_table( $table_name, $temporary );
				}

				$this->invalidate_information_schema_compatibility_tables();
				return $this->empty_ddl_result();
			}
		);
	}

	/**
	 * Execute DROP INDEX.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_drop_index( array $tokens ): WP_DuckDB_Result_Statement {
		$index = 0;
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::DROP_SYMBOL, 'Expected DROP.' );
		++$index;

		if (
			isset( $tokens[ $index ] )
			&& ( WP_MySQL_Lexer::ONLINE_SYMBOL === $tokens[ $index ]->id || WP_MySQL_Lexer::OFFLINE_SYMBOL === $tokens[ $index ]->id )
		) {
			++$index;
		}

		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::INDEX_SYMBOL, 'Expected INDEX in DROP INDEX statement.' );
		++$index;

		$index_name = $this->identifier_value( $tokens[ $index ] ?? null );
		++$index;
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::ON_SYMBOL, 'Expected ON in DROP INDEX statement.' );
		++$index;

		$reference = $this->parse_schema_lifecycle_table_reference( $tokens, $index, 'DROP INDEX' );
		$index     = $this->skip_drop_index_options( $tokens, $reference['next_index'] );
		if ( count( $tokens ) !== $index ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported DROP INDEX statement in DuckDB driver.' );
		}

		return $this->execute_schema_lifecycle_change(
			function () use ( $reference, $index_name ): WP_DuckDB_Result_Statement {
				$table_reference = $this->resolve_required_lifecycle_table( $reference['requested_table_name'], 'DROP INDEX' );
				$this->drop_secondary_index( $table_reference['table_name'], $index_name, $table_reference['temporary'] );
				$this->invalidate_information_schema_compatibility_tables();
				return $this->empty_ddl_result();
			}
		);
	}

	/**
	 * Parse a single-table UPDATE/DELETE table reference.
	 *
	 * @param WP_Parser_Token[] $tokens    MySQL tokens.
	 * @param int               $index     Index of the table reference.
	 * @param string            $statement Statement name.
	 * @return array{table_name:string,requested_table_name:string,alias:string|null,next_index:int}
	 */
	private function parse_single_table_dml_reference( array $tokens, int $index, string $statement ): array {
		$database   = null;
		$table_name = $this->identifier_value( $tokens[ $index ] ?? null );
		++$index;

		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $index ]->id ) {
			$database = $table_name;
			++$index;
			$table_name = $this->identifier_value( $tokens[ $index ] ?? null );
			++$index;

			if ( 0 === strcasecmp( $database, 'information_schema' ) ) {
				throw new WP_DuckDB_Driver_Exception( "Access denied for user 'duckdb'@'%' to database 'information_schema'" );
			}

			if ( 0 !== strcasecmp( $database, $this->database ) ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported ' . $statement . ' statement in DuckDB driver. Only the current database is supported.' );
			}
		}

		$alias = null;
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::AS_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
			$alias = $this->identifier_value( $tokens[ $index ] ?? null );
			++$index;
		} elseif ( isset( $tokens[ $index ] ) && ! $this->is_dml_clause_start_token( $tokens[ $index ] ) ) {
			$alias = $this->identifier_value( $tokens[ $index ] );
			++$index;
		}

		$table_reference = $this->resolve_visible_user_table_reference( $table_name );

		return array(
			'table_name'           => null === $table_reference ? $table_name : $table_reference['table_name'],
			'temporary'            => null !== $table_reference && $table_reference['temporary'],
			'requested_table_name' => $table_name,
			'alias'                => $alias,
			'next_index'           => $index,
		);
	}

	/**
	 * Build a DuckDB SQL table reference for a DML target.
	 *
	 * @param array{table_name:string,temporary?:bool,requested_table_name:string,alias:string|null,next_index:int} $reference Parsed table reference.
	 * @return string DuckDB table reference.
	 */
	private function dml_table_reference_sql( array $reference ): string {
		$sql = $this->connection->quote_identifier( $reference['table_name'] );
		if ( null !== $reference['alias'] ) {
			$sql .= ' AS ' . $this->connection->quote_identifier( $reference['alias'] );
		}

		return $sql;
	}

	/**
	 * Parse a schema lifecycle table reference.
	 *
	 * @param WP_Parser_Token[] $tokens    MySQL tokens.
	 * @param int               $index     Index of the table reference.
	 * @param string            $statement Statement name.
	 * @return array{requested_table_name:string,next_index:int}
	 */
	private function parse_schema_lifecycle_table_reference( array $tokens, int $index, string $statement ): array {
		$database   = null;
		$table_name = $this->identifier_value( $tokens[ $index ] ?? null );
		++$index;

		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $index ]->id ) {
			$database = $table_name;
			++$index;
			$table_name = $this->identifier_value( $tokens[ $index ] ?? null );
			++$index;

			if ( 0 === strcasecmp( $database, 'information_schema' ) ) {
				throw new WP_DuckDB_Driver_Exception( "Access denied for user 'duckdb'@'%' to database 'information_schema'" );
			}

			if ( 0 !== strcasecmp( $database, $this->database ) ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported ' . $statement . ' statement in DuckDB driver. Only the current database is supported.' );
			}
		}

		if ( $this->is_duckdb_internal_table_name( $table_name ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ' . $statement . ' statement in DuckDB driver. Internal DuckDB metadata tables cannot be modified.' );
		}

		return array(
			'requested_table_name' => $table_name,
			'next_index'           => $index,
		);
	}

	/**
	 * Resolve a lifecycle table target or throw a stable missing-table error.
	 *
	 * @param string $requested_table_name Requested table name.
	 * @param string $statement            Statement name.
	 * @return string Resolved table name.
	 */
	private function resolve_required_lifecycle_table_name( string $requested_table_name, string $statement ): string {
		$table_reference = $this->resolve_required_lifecycle_table( $requested_table_name, $statement );
		return $table_reference['table_name'];
	}

	/**
	 * Resolve a lifecycle table target or throw a stable missing-table error.
	 *
	 * @param string $requested_table_name Requested table name.
	 * @param string $statement            Statement name.
	 * @return array{table_name:string,temporary:bool} Resolved table reference.
	 */
	private function resolve_required_lifecycle_table( string $requested_table_name, string $statement ): array {
		$table_reference = $this->resolve_visible_user_table_reference( $requested_table_name );
		if ( null === $table_reference ) {
			throw new WP_DuckDB_Driver_Exception( "Unknown table '{$this->database}.{$requested_table_name}' in {$statement} statement." );
		}

		return $table_reference;
	}

	/**
	 * Execute multi-step schema lifecycle work inside a transaction.
	 *
	 * @param callable $callback Lifecycle callback.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_schema_lifecycle_change( callable $callback ): WP_DuckDB_Result_Statement {
		$started_transaction = ! $this->connection->inTransaction();
		if ( $started_transaction ) {
			$this->connection->beginTransaction();
		}

		try {
			$result = $callback();
			if ( $started_transaction ) {
				$this->connection->commit();
			}
		} catch ( Throwable $e ) {
			if ( $started_transaction && $this->connection->inTransaction() ) {
				$this->connection->rollback();
			}
			throw $e;
		}

		return $result;
	}

	/**
	 * Return a MySQL-shaped empty DDL result.
	 *
	 * @return WP_DuckDB_Result_Statement
	 */
	private function empty_ddl_result(): WP_DuckDB_Result_Statement {
		return new WP_DuckDB_Result_Statement( array(), array(), 0 );
	}

	/**
	 * Execute bounded MySQL SET session-variable assignments.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_set_statement( array $tokens ): WP_DuckDB_Result_Statement {
		$this->expect_token( $tokens, 0, WP_MySQL_Lexer::SET_SYMBOL, 'Expected SET.' );

		$assignments = $this->split_top_level_comma_items( array_slice( $tokens, 1 ) );
		if ( count( $assignments ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( 'SET statement requires at least one assignment.' );
		}

		// Match the current SQLite driver's scoped comma-list behavior: only
		// the leading SESSION assignment is applied.
		if (
			count( $assignments ) > 1
			&& isset( $assignments[0][0] )
			&& WP_MySQL_Lexer::SESSION_SYMBOL === $assignments[0][0]->id
		) {
			$assignments = array( $assignments[0] );
		}

		$default_scope = WP_MySQL_Lexer::SESSION_SYMBOL;
		foreach ( $assignments as $assignment ) {
			if ( $this->is_set_charset_bootstrap_assignment( $assignment ) ) {
				continue;
			}

			if ( $this->is_set_user_variable_assignment( $assignment ) ) {
				$this->execute_set_user_variable_assignment( $assignment );
				continue;
			}

			$default_scope = $this->execute_set_session_system_variable_assignment( $assignment, $default_scope );
		}

		return $this->empty_ddl_result();
	}

	/**
	 * Check whether a SET statement is a charset bootstrap no-op.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return bool Whether the assignment is SET NAMES, SET CHARSET, or SET CHARACTER SET.
	 */
	private function is_set_charset_bootstrap_assignment( array $tokens ): bool {
		if ( ! isset( $tokens[0] ) ) {
			return false;
		}

		if ( WP_MySQL_Lexer::NAMES_SYMBOL === $tokens[0]->id || WP_MySQL_Lexer::CHARSET_SYMBOL === $tokens[0]->id ) {
			return true;
		}

		if (
			( WP_MySQL_Lexer::CHARACTER_SYMBOL === $tokens[0]->id || WP_MySQL_Lexer::CHAR_SYMBOL === $tokens[0]->id )
			&& isset( $tokens[1] )
			&& WP_MySQL_Lexer::SET_SYMBOL === $tokens[1]->id
		) {
			return true;
		}

		return false;
	}

	/**
	 * Execute one SET assignment for an emulated session system variable.
	 *
	 * @param WP_Parser_Token[] $tokens        Assignment tokens.
	 * @param int               $default_scope Active default scope token ID.
	 * @return int Updated default scope token ID.
	 */
	private function execute_set_session_system_variable_assignment( array $tokens, int $default_scope ): int {
		$index = 0;
		$scope = $default_scope;

		if ( isset( $tokens[ $index ] ) && $this->is_set_statement_type_token( $tokens[ $index ] ) ) {
			$scope         = $tokens[ $index ]->id;
			$default_scope = $scope;
			++$index;
		}

		$target = $this->parse_set_session_system_variable_target( $tokens, $index, $scope );
		$name   = $this->normalize_supported_session_system_variable_name( $target['name'] );
		$scope  = $target['scope'];
		$index  = $target['next_index'];

		$this->assert_supported_set_session_scope( $scope );
		$this->expect_token(
			$tokens,
			$index,
			WP_MySQL_Lexer::EQUAL_OPERATOR,
			'Unsupported SET statement in DuckDB driver. Expected "=" for session variable assignment.'
		);
		++$index;

		$value = $this->normalize_set_session_system_variable_value_tokens( $name, array_slice( $tokens, $index ) );
		if ( 'sql_mode' === $name ) {
			$this->active_sql_modes = '' === $value ? array() : explode( ',', (string) $value );
		} else {
			$this->session_system_variables[ $name ] = $value;
		}

		return $default_scope;
	}

	/**
	 * Check whether a SET assignment targets a user variable.
	 *
	 * @param WP_Parser_Token[] $tokens Assignment tokens.
	 * @return bool Whether the assignment targets a user variable.
	 */
	private function is_set_user_variable_assignment( array $tokens ): bool {
		return isset( $tokens[0] ) && $this->is_user_variable_token( $tokens[0] );
	}

	/**
	 * Execute one SET assignment for an emulated user variable.
	 *
	 * @param WP_Parser_Token[] $tokens Assignment tokens.
	 */
	private function execute_set_user_variable_assignment( array $tokens ): void {
		$name = $this->user_variable_name( $tokens[0] );
		if (
			! isset( $tokens[1] )
			|| ( WP_MySQL_Lexer::EQUAL_OPERATOR !== $tokens[1]->id && WP_MySQL_Lexer::ASSIGN_OPERATOR !== $tokens[1]->id )
		) {
			throw new WP_DuckDB_Driver_Exception(
				'Unsupported SET user variable statement in DuckDB driver. Expected "=" or ":=" for user variable assignment.'
			);
		}

		$this->user_variables[ $name ] = $this->normalize_set_user_variable_value( array_slice( $tokens, 2 ) );
	}

	/**
	 * Parse the target side of one SET assignment.
	 *
	 * @param WP_Parser_Token[] $tokens        Assignment tokens.
	 * @param int               $index         Current token index.
	 * @param int               $default_scope Active default scope token ID.
	 * @return array{name:string,scope:int,next_index:int}
	 */
	private function parse_set_session_system_variable_target( array $tokens, int $index, int $default_scope ): array {
		if ( ! isset( $tokens[ $index ] ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported SET statement in DuckDB driver. Expected session variable name.' );
		}

		if ( WP_MySQL_Lexer::AT_AT_SIGN_SYMBOL !== $tokens[ $index ]->id ) {
			return array(
				'name'       => $this->identifier_value( $tokens[ $index ] ),
				'scope'      => $default_scope,
				'next_index' => $index + 1,
			);
		}

		++$index;
		if ( ! isset( $tokens[ $index ] ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported SET statement in DuckDB driver. Expected session variable name after @@.' );
		}

		$scope = WP_MySQL_Lexer::SESSION_SYMBOL;
		if (
			$this->is_set_statement_type_token( $tokens[ $index ] )
			&& isset( $tokens[ $index + 1 ] )
			&& WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $index + 1 ]->id
		) {
			$scope  = $tokens[ $index ]->id;
			$index += 2;
			if ( ! isset( $tokens[ $index ] ) ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported SET statement in DuckDB driver. Expected session variable name after @@scope.' );
			}
		}

		return array(
			'name'       => $this->identifier_value( $tokens[ $index ] ),
			'scope'      => $scope,
			'next_index' => $index + 1,
		);
	}

	/**
	 * Normalize an emulated session system variable value from assignment tokens.
	 *
	 * @param string            $name   Normalized variable name.
	 * @param WP_Parser_Token[] $tokens Value tokens.
	 * @return int|string|null Normalized stored value.
	 */
	private function normalize_set_session_system_variable_value_tokens( string $name, array $tokens ) {
		if ( 1 !== count( $tokens ) ) {
			throw $this->new_unsupported_set_session_system_variable_value_exception( $name );
		}

		if ( $this->is_user_variable_token( $tokens[0] ) ) {
			if ( ! in_array( $name, array( 'foreign_key_checks', 'unique_checks' ), true ) ) {
				throw $this->new_unsupported_set_session_system_variable_value_exception( $name );
			}

			return $this->normalize_set_dump_check_variable_value(
				$name,
				$this->get_user_variable( $this->user_variable_name( $tokens[0] ) )
			);
		}

		return $this->normalize_set_session_system_variable_value( $name, $tokens[0] );
	}

	/**
	 * Normalize an emulated session system variable value.
	 *
	 * @param string          $name  Normalized variable name.
	 * @param WP_Parser_Token $token Value token.
	 * @return int|string Normalized stored value.
	 */
	private function normalize_set_session_system_variable_value( string $name, WP_Parser_Token $token ) {
		if ( 'sql_mode' === $name ) {
			return $this->normalize_set_sql_mode_value( $token );
		}

		$value = $token->get_value();
		$lower = strtolower( $value );

		if ( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id || WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $token->id ) {
			if ( 'on' === $lower ) {
				return 1;
			}
			if ( 'off' === $lower ) {
				return 0;
			}

			throw $this->new_unsupported_set_session_system_variable_value_exception( $name );
		}

		if ( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id ) {
			throw $this->new_unsupported_set_session_system_variable_value_exception( $name );
		}

		if ( 'on' === $lower || 'true' === $lower ) {
			return 1;
		}
		if ( 'off' === $lower || 'false' === $lower ) {
			return 0;
		}
		if ( 'default' === $lower ) {
			return 'DEFAULT';
		}
		if ( WP_MySQL_Lexer::INT_NUMBER === $token->id && ( '0' === $value || '1' === $value ) ) {
			return (int) $value;
		}

		throw $this->new_unsupported_set_session_system_variable_value_exception( $name );
	}

	/**
	 * Normalize a restored dump check variable value.
	 *
	 * @param string $name  Normalized variable name.
	 * @param mixed  $value Restored user-variable value.
	 * @return int|string|null Normalized stored value.
	 */
	private function normalize_set_dump_check_variable_value( string $name, $value ) {
		if ( null === $value || 'DEFAULT' === $value ) {
			return $value;
		}

		if ( is_int( $value ) && ( 0 === $value || 1 === $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			$lower = strtolower( $value );
			if ( 'on' === $lower || 'true' === $lower || '1' === $value ) {
				return 1;
			}
			if ( 'off' === $lower || 'false' === $lower || '0' === $value ) {
				return 0;
			}
		}

		throw $this->new_unsupported_set_session_system_variable_value_exception( $name );
	}

	/**
	 * Normalize an emulated user variable assignment value.
	 *
	 * @param WP_Parser_Token[] $tokens Value tokens.
	 * @return int|float|string|null Normalized stored value.
	 */
	private function normalize_set_user_variable_value( array $tokens ) {
		$system_variable = $this->parse_session_system_variable_reference( $tokens );
		if ( null !== $system_variable ) {
			return $this->get_session_system_variable( $system_variable['name'] );
		}

		if ( 1 === count( $tokens ) && $this->is_user_variable_token( $tokens[0] ) ) {
			return $this->get_user_variable( $this->user_variable_name( $tokens[0] ) );
		}

		return $this->normalize_set_user_variable_literal_value( $tokens );
	}

	/**
	 * Normalize a bounded literal for a user variable assignment.
	 *
	 * @param WP_Parser_Token[] $tokens Literal tokens.
	 * @return int|float|string|null Normalized stored value.
	 */
	private function normalize_set_user_variable_literal_value( array $tokens ) {
		if ( count( $tokens ) === 2 && $this->is_sign_token( $tokens[0] ) && $this->is_number_token( $tokens[1] ) ) {
			return $this->signed_number_token_value( $tokens[0], $tokens[1] );
		}

		if ( 1 !== count( $tokens ) ) {
			throw $this->new_unsupported_set_user_variable_value_exception();
		}

		$token = $tokens[0];
		if ( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id || WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $token->id ) {
			return $token->get_value();
		}
		if ( $this->is_number_token( $token ) ) {
			return $this->number_token_value( $token );
		}
		if ( WP_MySQL_Lexer::NULL_SYMBOL === $token->id || WP_MySQL_Lexer::NULL2_SYMBOL === $token->id ) {
			return null;
		}
		if ( WP_MySQL_Lexer::TRUE_SYMBOL === $token->id ) {
			return 1;
		}
		if ( WP_MySQL_Lexer::FALSE_SYMBOL === $token->id ) {
			return 0;
		}

		throw $this->new_unsupported_set_user_variable_value_exception();
	}

	/**
	 * Normalize a SET sql_mode value for driver-local readback.
	 *
	 * @param WP_Parser_Token $token Value token.
	 * @return string Normalized SQL mode string.
	 */
	private function normalize_set_sql_mode_value( WP_Parser_Token $token ): string {
		if ( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id ) {
			throw $this->new_unsupported_set_session_system_variable_value_exception( 'sql_mode' );
		}

		if ( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id || WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $token->id ) {
			return strtoupper( $token->get_value() );
		}

		if ( 'default' === strtolower( $token->get_value() ) ) {
			return 'DEFAULT';
		}

		if ( ! $this->is_non_identifier_token( $token ) ) {
			return strtoupper( $token->get_value() );
		}

		throw $this->new_unsupported_set_session_system_variable_value_exception( 'sql_mode' );
	}

	/**
	 * Build an unsupported SET value exception.
	 *
	 * @param string $name Normalized variable name.
	 * @return WP_DuckDB_Driver_Exception Exception.
	 */
	private function new_unsupported_set_session_system_variable_value_exception( string $name ): WP_DuckDB_Driver_Exception {
		if ( 'sql_mode' === $name ) {
			return new WP_DuckDB_Driver_Exception(
				'Unsupported SET value for sql_mode in DuckDB driver. Only string literals, bare mode names, and DEFAULT are supported.'
			);
		}

		return new WP_DuckDB_Driver_Exception(
			'Unsupported SET value for '
			. $name
			. ' in DuckDB driver. Only ON, OFF, TRUE, FALSE, 1, 0, DEFAULT, and supported dump restores are supported.'
		);
	}

	/**
	 * Build an unsupported user-variable SET value exception.
	 *
	 * @return WP_DuckDB_Driver_Exception Exception.
	 */
	private function new_unsupported_set_user_variable_value_exception(): WP_DuckDB_Driver_Exception {
		return new WP_DuckDB_Driver_Exception(
			'Unsupported SET user variable value in DuckDB driver. Only literals, supported system variables, and simple user variable references are supported.'
		);
	}

	/**
	 * Normalize and validate a supported emulated session system variable name.
	 *
	 * @param string $name Variable name.
	 * @return string Normalized variable name.
	 */
	private function normalize_supported_session_system_variable_name( string $name ): string {
		$normalized = strtolower( $name );
		if ( ! isset( self::SUPPORTED_SESSION_SYSTEM_VARIABLES[ $normalized ] ) ) {
			throw new WP_DuckDB_Driver_Exception(
				'Unsupported SET session variable in DuckDB driver: '
				. $name
				. '. Only autocommit, big_tables, foreign_key_checks, sql_mode, and unique_checks are supported.'
			);
		}

		return $normalized;
	}

	/**
	 * Check whether a token is a SET statement scope/type token.
	 *
	 * @param WP_Parser_Token $token Token.
	 * @return bool Whether this token is a SET scope/type.
	 */
	private function is_set_statement_type_token( WP_Parser_Token $token ): bool {
		return in_array(
			$token->id,
			array(
				WP_MySQL_Lexer::SESSION_SYMBOL,
				WP_MySQL_Lexer::LOCAL_SYMBOL,
				WP_MySQL_Lexer::GLOBAL_SYMBOL,
				WP_MySQL_Lexer::PERSIST_SYMBOL,
				WP_MySQL_Lexer::PERSIST_ONLY_SYMBOL,
			),
			true
		);
	}

	/**
	 * Assert that a SET assignment uses a supported session scope.
	 *
	 * @param int $scope SET statement scope token ID.
	 */
	private function assert_supported_set_session_scope( int $scope ): void {
		if ( WP_MySQL_Lexer::SESSION_SYMBOL === $scope ) {
			return;
		}

		throw new WP_DuckDB_Driver_Exception(
			"Unsupported SET statement type: '{$this->set_statement_type_name( $scope )}' in DuckDB driver."
		);
	}

	/**
	 * Get a readable SET statement scope/type name.
	 *
	 * @param int $scope SET statement scope token ID.
	 * @return string Scope/type name.
	 */
	private function set_statement_type_name( int $scope ): string {
		switch ( $scope ) {
			case WP_MySQL_Lexer::SESSION_SYMBOL:
				return 'SESSION';
			case WP_MySQL_Lexer::LOCAL_SYMBOL:
				return 'LOCAL';
			case WP_MySQL_Lexer::GLOBAL_SYMBOL:
				return 'GLOBAL';
			case WP_MySQL_Lexer::PERSIST_SYMBOL:
				return 'PERSIST';
			case WP_MySQL_Lexer::PERSIST_ONLY_SYMBOL:
				return 'PERSIST_ONLY';
		}

		return 'UNKNOWN';
	}

	/**
	 * Execute LOCK TABLE[S] ... READ [LOCAL]|[LOW_PRIORITY] WRITE.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_lock_tables_statement( array $tokens ): WP_DuckDB_Result_Statement {
		$index = 0;
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::LOCK_SYMBOL, 'Expected LOCK.' );
		++$index;
		if (
			! isset( $tokens[ $index ] )
			|| ( WP_MySQL_Lexer::TABLE_SYMBOL !== $tokens[ $index ]->id && WP_MySQL_Lexer::TABLES_SYMBOL !== $tokens[ $index ]->id )
		) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported LOCK statement in DuckDB driver. Only LOCK TABLES ... READ [LOCAL] or [LOW_PRIORITY] WRITE is supported.' );
		}
		++$index;

		if ( ! isset( $tokens[ $index ] ) ) {
			throw new WP_DuckDB_Driver_Exception( 'LOCK TABLES requires at least one table name.' );
		}

		$requested_table_names = array();
		while ( $index < count( $tokens ) ) {
			$reference               = $this->parse_lock_table_reference( $tokens, $index );
			$requested_table_names[] = $reference['requested_table_name'];
			$index                   = $reference['next_index'];

			if ( $index >= count( $tokens ) ) {
				break;
			}
			$this->expect_token( $tokens, $index, WP_MySQL_Lexer::COMMA_SYMBOL, 'Expected comma between LOCK TABLES table references.' );
			++$index;
			if ( $index >= count( $tokens ) ) {
				throw new WP_DuckDB_Driver_Exception( 'Expected table name after comma in LOCK TABLES statement.' );
			}
		}

		foreach ( $requested_table_names as $requested_table_name ) {
			if ( null === $this->resolve_visible_user_table_reference( $requested_table_name ) ) {
				throw new WP_DuckDB_Driver_Exception( "Table '{$this->database}.{$requested_table_name}' doesn't exist" );
			}
		}

		$this->begin_user_transaction();
		$this->table_lock_active = true;

		return $this->empty_ddl_result();
	}

	/**
	 * Parse one LOCK TABLES table reference and lock type.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @param int               $index  Index of the table reference.
	 * @return array{requested_table_name:string,next_index:int}
	 */
	private function parse_lock_table_reference( array $tokens, int $index ): array {
		$reference = $this->parse_schema_lifecycle_table_reference( $tokens, $index, 'LOCK TABLES' );
		$index     = $reference['next_index'];

		$index = $this->consume_lock_table_alias( $tokens, $index );
		$index = $this->consume_lock_table_option( $tokens, $index );

		return array(
			'requested_table_name' => $reference['requested_table_name'],
			'next_index'           => $index,
		);
	}

	/**
	 * Consume an optional LOCK TABLES table alias.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @param int               $index  Index after the table reference.
	 * @return int New index.
	 */
	private function consume_lock_table_alias( array $tokens, int $index ): int {
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::AS_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
			$this->identifier_value( $tokens[ $index ] ?? null );
			return $index + 1;
		}

		if (
			! isset( $tokens[ $index ] )
			|| $this->is_non_identifier_token( $tokens[ $index ] )
			|| $this->is_lock_table_option_start_token( $tokens[ $index ] )
		) {
			return $index;
		}

		$this->identifier_value( $tokens[ $index ] );
		return $index + 1;
	}

	/**
	 * Consume a LOCK TABLES lock option.
	 *
	 * Supports MySQL's READ, READ LOCAL, WRITE, and LOW_PRIORITY WRITE lock
	 * options. DuckDB still emulates these as transaction boundaries only.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @param int               $index  Index of the lock option.
	 * @return int New index.
	 */
	private function consume_lock_table_option( array $tokens, int $index ): int {
		if ( ! isset( $tokens[ $index ] ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported LOCK TABLES statement in DuckDB driver. Each table must specify READ or WRITE.' );
		}

		if ( WP_MySQL_Lexer::READ_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
			if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::LOCAL_SYMBOL === $tokens[ $index ]->id ) {
				++$index;
			}
			return $index;
		}

		if ( WP_MySQL_Lexer::LOW_PRIORITY_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
			if ( ! isset( $tokens[ $index ] ) || WP_MySQL_Lexer::WRITE_SYMBOL !== $tokens[ $index ]->id ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported LOCK TABLES statement in DuckDB driver. LOW_PRIORITY must be followed by WRITE.' );
			}
			return $index + 1;
		}

		if ( WP_MySQL_Lexer::WRITE_SYMBOL === $tokens[ $index ]->id ) {
			return $index + 1;
		}

		throw new WP_DuckDB_Driver_Exception( 'Unsupported LOCK TABLES statement in DuckDB driver. Each table must specify READ or WRITE.' );
	}

	/**
	 * Check whether a token starts a LOCK TABLES lock option.
	 *
	 * @param WP_Parser_Token $token Token.
	 * @return bool Whether the token starts a lock option.
	 */
	private function is_lock_table_option_start_token( WP_Parser_Token $token ): bool {
		return in_array(
			$token->id,
			array(
				WP_MySQL_Lexer::READ_SYMBOL,
				WP_MySQL_Lexer::WRITE_SYMBOL,
				WP_MySQL_Lexer::LOW_PRIORITY_SYMBOL,
			),
			true
		);
	}

	/**
	 * Execute UNLOCK TABLE[S].
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_unlock_tables_statement( array $tokens ): WP_DuckDB_Result_Statement {
		if (
			2 !== count( $tokens )
			|| WP_MySQL_Lexer::UNLOCK_SYMBOL !== $tokens[0]->id
			|| ( WP_MySQL_Lexer::TABLE_SYMBOL !== $tokens[1]->id && WP_MySQL_Lexer::TABLES_SYMBOL !== $tokens[1]->id )
		) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported UNLOCK statement in DuckDB driver. Only UNLOCK TABLES is supported.' );
		}

		if ( $this->table_lock_active && $this->connection->inTransaction() ) {
			$this->last_duckdb_queries[] = 'COMMIT';
			$this->connection->commit();
		}
		$this->table_lock_active = false;

		return $this->empty_ddl_result();
	}

	/**
	 * Execute table administration statements as MySQL-shaped status reports.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_table_administration_statement( array $tokens ): WP_DuckDB_Result_Statement {
		$operation = $this->table_administration_operation( $tokens[0] );
		$statement = strtoupper( $operation ) . ' TABLE';
		$index     = 0;
		$this->expect_token( $tokens, $index, $tokens[0]->id, 'Expected ' . strtoupper( $operation ) . '.' );
		++$index;

		if (
			'check' !== $operation
			&& isset( $tokens[ $index ] )
			&& $this->is_table_administration_log_modifier_token( $tokens[ $index ] )
		) {
			++$index;
		}

		if (
			! isset( $tokens[ $index ] )
			|| ( WP_MySQL_Lexer::TABLE_SYMBOL !== $tokens[ $index ]->id && WP_MySQL_Lexer::TABLES_SYMBOL !== $tokens[ $index ]->id )
		) {
			throw new WP_DuckDB_Driver_Exception( 'Expected TABLE in ' . $statement . ' statement.' );
		}
		++$index;

		if ( ! isset( $tokens[ $index ] ) ) {
			throw new WP_DuckDB_Driver_Exception( $statement . ' requires at least one table name.' );
		}

		$requested_table_names = array();
		while ( $index < count( $tokens ) ) {
			$reference               = $this->parse_schema_lifecycle_table_reference( $tokens, $index, $statement );
			$requested_table_names[] = $reference['requested_table_name'];
			$index                   = $reference['next_index'];

			if ( $index >= count( $tokens ) ) {
				break;
			}

			if ( 'check' === $operation && $this->is_check_table_option_start_token( $tokens[ $index ] ) ) {
				$index = $this->consume_check_table_options( $tokens, $index );
				break;
			}

			if ( 'repair' === $operation && $this->is_repair_table_option_start_token( $tokens[ $index ] ) ) {
				$index = $this->consume_repair_table_options( $tokens, $index );
				break;
			}

			if ( 'analyze' === $operation && $this->is_analyze_table_histogram_start_token( $tokens[ $index ] ) ) {
				$index = $this->consume_analyze_table_histogram_options( $tokens, $index );
				break;
			}

			if ( WP_MySQL_Lexer::COMMA_SYMBOL !== $tokens[ $index ]->id ) {
				throw new WP_DuckDB_Driver_Exception(
					$this->unsupported_table_administration_message( $statement, $operation )
				);
			}
			++$index;

			if ( $index >= count( $tokens ) ) {
				throw new WP_DuckDB_Driver_Exception( 'Expected table name after comma in ' . $statement . ' statement.' );
			}
		}

		$rows = array();
		foreach ( $requested_table_names as $requested_table_name ) {
			$rows = array_merge(
				$rows,
				$this->table_administration_status_rows( $requested_table_name, $operation )
			);
		}

		return new WP_DuckDB_Result_Statement(
			array( 'Table', 'Op', 'Msg_type', 'Msg_text' ),
			$rows
		);
	}

	/**
	 * Get the result-row operation label for a table administration statement.
	 *
	 * @param WP_Parser_Token $token Statement token.
	 * @return string Operation label.
	 */
	private function table_administration_operation( WP_Parser_Token $token ): string {
		switch ( $token->id ) {
			case WP_MySQL_Lexer::ANALYZE_SYMBOL:
				return 'analyze';
			case WP_MySQL_Lexer::CHECK_SYMBOL:
				return 'check';
			case WP_MySQL_Lexer::OPTIMIZE_SYMBOL:
				return 'optimize';
			case WP_MySQL_Lexer::REPAIR_SYMBOL:
				return 'repair';
		}

		throw $this->new_unsupported_statement_exception( $token );
	}

	/**
	 * Check whether a token is an ignored table administration log modifier.
	 *
	 * @param WP_Parser_Token $token Token.
	 * @return bool Whether this token is LOCAL or NO_WRITE_TO_BINLOG.
	 */
	private function is_table_administration_log_modifier_token( WP_Parser_Token $token ): bool {
		return in_array(
			$token->id,
			array(
				WP_MySQL_Lexer::LOCAL_SYMBOL,
				WP_MySQL_Lexer::NO_WRITE_TO_BINLOG_SYMBOL,
			),
			true
		);
	}

	/**
	 * Build a stable unsupported-shape message for table administration statements.
	 *
	 * @param string $statement Statement name.
	 * @param string $operation Operation label.
	 * @return string Error message.
	 */
	private function unsupported_table_administration_message( string $statement, string $operation ): string {
		if ( 'check' === $operation ) {
			return 'Unsupported CHECK TABLE statement in DuckDB driver. Only table names followed by optional CHECK options are supported.';
		}

		if ( 'repair' === $operation ) {
			return 'Unsupported REPAIR TABLE statement in DuckDB driver. Only table names followed by optional REPAIR options are supported.';
		}

		if ( 'analyze' === $operation ) {
			return 'Unsupported ANALYZE TABLE statement in DuckDB driver. Only table names followed by optional histogram clauses are supported.';
		}

		return 'Unsupported ' . $statement . ' statement in DuckDB driver. Only table names are supported.';
	}

	/**
	 * Consume legal CHECK TABLE options.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @param int               $index  Index of the first option token.
	 * @return int Index after the consumed options.
	 */
	private function consume_check_table_options( array $tokens, int $index ): int {
		while ( $index < count( $tokens ) ) {
			if (
				in_array(
					$tokens[ $index ]->id,
					array(
						WP_MySQL_Lexer::QUICK_SYMBOL,
						WP_MySQL_Lexer::FAST_SYMBOL,
						WP_MySQL_Lexer::MEDIUM_SYMBOL,
						WP_MySQL_Lexer::EXTENDED_SYMBOL,
						WP_MySQL_Lexer::CHANGED_SYMBOL,
					),
					true
				)
			) {
				++$index;
				continue;
			}

			if ( WP_MySQL_Lexer::FOR_SYMBOL === $tokens[ $index ]->id ) {
				if ( ! isset( $tokens[ $index + 1 ] ) || WP_MySQL_Lexer::UPGRADE_SYMBOL !== $tokens[ $index + 1 ]->id ) {
					throw new WP_DuckDB_Driver_Exception(
						'Unsupported CHECK TABLE statement in DuckDB driver. FOR must be followed by UPGRADE.'
					);
				}
				$index += 2;
				continue;
			}

			throw new WP_DuckDB_Driver_Exception(
				'Unsupported CHECK TABLE statement in DuckDB driver. Only QUICK, FAST, MEDIUM, EXTENDED, CHANGED, and FOR UPGRADE options are supported.'
			);
		}

		return $index;
	}

	/**
	 * Check whether a token can begin CHECK TABLE options.
	 *
	 * @param WP_Parser_Token $token Token.
	 * @return bool Whether the token starts CHECK TABLE options.
	 */
	private function is_check_table_option_start_token( WP_Parser_Token $token ): bool {
		return in_array(
			$token->id,
			array(
				WP_MySQL_Lexer::QUICK_SYMBOL,
				WP_MySQL_Lexer::FAST_SYMBOL,
				WP_MySQL_Lexer::MEDIUM_SYMBOL,
				WP_MySQL_Lexer::EXTENDED_SYMBOL,
				WP_MySQL_Lexer::CHANGED_SYMBOL,
				WP_MySQL_Lexer::FOR_SYMBOL,
			),
			true
		);
	}

	/**
	 * Consume legal REPAIR TABLE options.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @param int               $index  Index of the first option token.
	 * @return int Index after the consumed options.
	 */
	private function consume_repair_table_options( array $tokens, int $index ): int {
		while ( $index < count( $tokens ) ) {
			if ( $this->is_repair_table_option_start_token( $tokens[ $index ] ) ) {
				++$index;
				continue;
			}

			throw new WP_DuckDB_Driver_Exception(
				'Unsupported REPAIR TABLE statement in DuckDB driver. Only QUICK, EXTENDED, and USE_FRM options are supported.'
			);
		}

		return $index;
	}

	/**
	 * Check whether a token can begin REPAIR TABLE options.
	 *
	 * @param WP_Parser_Token $token Token.
	 * @return bool Whether the token starts REPAIR TABLE options.
	 */
	private function is_repair_table_option_start_token( WP_Parser_Token $token ): bool {
		return in_array(
			$token->id,
			array(
				WP_MySQL_Lexer::QUICK_SYMBOL,
				WP_MySQL_Lexer::EXTENDED_SYMBOL,
				WP_MySQL_Lexer::USE_FRM_SYMBOL,
			),
			true
		);
	}

	/**
	 * Consume ignored ANALYZE TABLE histogram clauses.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @param int               $index  Index of UPDATE or DROP.
	 * @return int Index after the consumed histogram clause.
	 */
	private function consume_analyze_table_histogram_options( array $tokens, int $index ): int {
		$is_update = WP_MySQL_Lexer::UPDATE_SYMBOL === $tokens[ $index ]->id;
		$is_drop   = WP_MySQL_Lexer::DROP_SYMBOL === $tokens[ $index ]->id;
		if ( ! $is_update && ! $is_drop ) {
			throw new WP_DuckDB_Driver_Exception(
				'Unsupported ANALYZE TABLE statement in DuckDB driver. Histogram clauses must start with UPDATE or DROP.'
			);
		}
		++$index;

		if ( ! isset( $tokens[ $index ] ) || WP_MySQL_Lexer::HISTOGRAM_SYMBOL !== $tokens[ $index ]->id ) {
			throw new WP_DuckDB_Driver_Exception(
				'Unsupported ANALYZE TABLE statement in DuckDB driver. UPDATE or DROP must be followed by HISTOGRAM.'
			);
		}
		++$index;

		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::ON_SYMBOL, 'Expected ON in ANALYZE TABLE histogram clause.' );
		++$index;

		$index = $this->consume_analyze_table_histogram_column_list( $tokens, $index );

		if (
			$is_update
			&& isset( $tokens[ $index ] )
			&& WP_MySQL_Lexer::WITH_SYMBOL === $tokens[ $index ]->id
		) {
			$index = $this->consume_analyze_table_histogram_bucket_count( $tokens, $index );
		}

		if ( $index < count( $tokens ) ) {
			throw new WP_DuckDB_Driver_Exception(
				'Unsupported ANALYZE TABLE statement in DuckDB driver. Only UPDATE HISTOGRAM ON columns and DROP HISTOGRAM ON columns are supported.'
			);
		}

		return $index;
	}

	/**
	 * Check whether a token can begin an ANALYZE TABLE histogram clause.
	 *
	 * @param WP_Parser_Token $token Token.
	 * @return bool Whether the token starts a histogram clause.
	 */
	private function is_analyze_table_histogram_start_token( WP_Parser_Token $token ): bool {
		return WP_MySQL_Lexer::UPDATE_SYMBOL === $token->id || WP_MySQL_Lexer::DROP_SYMBOL === $token->id;
	}

	/**
	 * Consume an ANALYZE TABLE histogram column list.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @param int               $index  Index of the first column token.
	 * @return int Index after the consumed column list.
	 */
	private function consume_analyze_table_histogram_column_list( array $tokens, int $index ): int {
		$this->identifier_value( $tokens[ $index ] ?? null );
		++$index;

		while ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
			$this->identifier_value( $tokens[ $index ] ?? null );
			++$index;
		}

		return $index;
	}

	/**
	 * Consume an ANALYZE TABLE histogram bucket count.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @param int               $index  Index of WITH.
	 * @return int Index after the consumed bucket count.
	 */
	private function consume_analyze_table_histogram_bucket_count( array $tokens, int $index ): int {
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::WITH_SYMBOL, 'Expected WITH in ANALYZE TABLE histogram clause.' );
		++$index;

		if ( ! isset( $tokens[ $index ] ) || ! $this->is_integer_number_token( $tokens[ $index ] ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Expected bucket count in ANALYZE TABLE histogram clause.' );
		}
		++$index;

		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::BUCKETS_SYMBOL, 'Expected BUCKETS in ANALYZE TABLE histogram clause.' );
		++$index;

		return $index;
	}

	/**
	 * Check whether a token is an integer number token.
	 *
	 * @param WP_Parser_Token $token Token.
	 * @return bool Whether the token is an integer number.
	 */
	private function is_integer_number_token( WP_Parser_Token $token ): bool {
		return in_array(
			$token->id,
			array(
				WP_MySQL_Lexer::INT_NUMBER,
				WP_MySQL_Lexer::LONG_NUMBER,
				WP_MySQL_Lexer::ULONGLONG_NUMBER,
			),
			true
		);
	}

	/**
	 * Build table administration result rows for one requested table.
	 *
	 * @param string $requested_table_name Requested table name.
	 * @param string $operation            Operation label.
	 * @return array<int,array<int,string>>
	 */
	private function table_administration_status_rows( string $requested_table_name, string $operation ): array {
		$table_label     = $this->database . '.' . $requested_table_name;
		$table_reference = $this->resolve_visible_user_table_reference( $requested_table_name );

		if ( null === $table_reference ) {
			return array(
				array(
					$table_label,
					$operation,
					'Error',
					"Table '{$requested_table_name}' doesn't exist",
				),
				array(
					$table_label,
					$operation,
					'status',
					'Operation failed',
				),
			);
		}

		if ( 'analyze' === $operation ) {
			try {
				$this->execute_duckdb_query(
					'ANALYZE ' . $this->connection->quote_identifier( $table_reference['table_name'] ),
					'Failed to analyze DuckDB table'
				);
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				return array(
					array(
						$table_label,
						$operation,
						'Error',
						$e->getMessage(),
					),
					array(
						$table_label,
						$operation,
						'status',
						'Operation failed',
					),
				);
			}
		}

		return array(
			array(
				$table_label,
				$operation,
				'status',
				'OK',
			),
		);
	}

	/**
	 * Execute BEGIN [WORK].
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_begin_transaction_statement( array $tokens ): WP_DuckDB_Result_Statement {
		$this->assert_optional_work_only( $tokens, 'BEGIN' );
		$this->begin_user_transaction();
		return $this->empty_ddl_result();
	}

	/**
	 * Execute START TRANSACTION.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_start_transaction_statement( array $tokens ): WP_DuckDB_Result_Statement {
		if ( 2 !== count( $tokens ) || WP_MySQL_Lexer::TRANSACTION_SYMBOL !== $tokens[1]->id ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported START statement in DuckDB driver. Only START TRANSACTION is supported.' );
		}

		$this->begin_user_transaction();
		return $this->empty_ddl_result();
	}

	/**
	 * Execute COMMIT [WORK].
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_commit_statement( array $tokens ): WP_DuckDB_Result_Statement {
		$this->assert_optional_work_only( $tokens, 'COMMIT' );
		if ( $this->connection->inTransaction() ) {
			$this->last_duckdb_queries[] = 'COMMIT';
			$this->connection->commit();
		}
		$this->table_lock_active = false;
		return $this->empty_ddl_result();
	}

	/**
	 * Execute ROLLBACK [WORK].
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_rollback_statement( array $tokens ): WP_DuckDB_Result_Statement {
		$this->assert_optional_work_only( $tokens, 'ROLLBACK' );
		if ( $this->connection->inTransaction() ) {
			$this->last_duckdb_queries[] = 'ROLLBACK';
			$this->connection->rollback();
		}
		$this->table_lock_active = false;
		return $this->empty_ddl_result();
	}

	/**
	 * Begin a MySQL-style user transaction.
	 *
	 * MySQL implicitly commits the active transaction before starting another.
	 */
	private function begin_user_transaction(): void {
		if ( $this->connection->inTransaction() ) {
			$this->last_duckdb_queries[] = 'COMMIT';
			$this->connection->commit();
			$this->table_lock_active = false;
		}

		$this->last_duckdb_queries[] = 'BEGIN TRANSACTION';
		$this->connection->beginTransaction();
	}

	/**
	 * Assert that a transaction statement has no trailing tokens except WORK.
	 *
	 * @param WP_Parser_Token[] $tokens    MySQL tokens.
	 * @param string            $statement Statement name.
	 */
	private function assert_optional_work_only( array $tokens, string $statement ): void {
		if ( 1 === count( $tokens ) ) {
			return;
		}

		if ( 2 === count( $tokens ) && WP_MySQL_Lexer::WORK_SYMBOL === $tokens[1]->id ) {
			return;
		}

		throw new WP_DuckDB_Driver_Exception( 'Unsupported ' . $statement . ' statement in DuckDB driver. Only ' . $statement . ' [WORK] is supported.' );
	}

	/**
	 * Skip supported DROP INDEX options.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Current index.
	 * @return int New index.
	 */
	private function skip_drop_index_options( array $tokens, int $index ): int {
		while ( $index < count( $tokens ) ) {
			if ( WP_MySQL_Lexer::ALGORITHM_SYMBOL === $tokens[ $index ]->id || WP_MySQL_Lexer::LOCK_SYMBOL === $tokens[ $index ]->id ) {
				$index = $this->skip_option_value( $tokens, $index + 1 );
				continue;
			}

			throw new WP_DuckDB_Driver_Exception( 'Unsupported DROP INDEX option in DuckDB driver: ' . $tokens[ $index ]->get_bytes() . '.' );
		}

		return $index;
	}

	/**
	 * Find a token at top-level parenthesis depth.
	 *
	 * @param WP_Parser_Token[] $tokens   Token stream.
	 * @param int               $start    First token to scan.
	 * @param int               $token_id Token ID to find.
	 * @return int|null Token index, or null when absent.
	 */
	private function find_top_level_token_index( array $tokens, int $start, int $token_id ): ?int {
		$depth = 0;
		for ( $index = $start; $index < count( $tokens ); ++$index ) {
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $index ]->id ) {
				++$depth;
				continue;
			}
			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $index ]->id ) {
				--$depth;
				continue;
			}
			if ( 0 === $depth && $token_id === $tokens[ $index ]->id ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Find top-level WHERE/ORDER/LIMIT clauses in a DML statement.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @param int               $start  First token to scan.
	 * @return array{where:int|null,order:int|null,limit:int|null}
	 */
	private function dml_clause_indexes( array $tokens, int $start ): array {
		$clauses = array(
			'where' => null,
			'order' => null,
			'limit' => null,
		);
		$depth   = 0;

		for ( $index = $start; $index < count( $tokens ); ++$index ) {
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $index ]->id ) {
				++$depth;
				continue;
			}
			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $index ]->id ) {
				--$depth;
				continue;
			}
			if ( 0 !== $depth ) {
				continue;
			}

			if ( WP_MySQL_Lexer::WHERE_SYMBOL === $tokens[ $index ]->id && null === $clauses['where'] ) {
				$clauses['where'] = $index;
			} elseif ( WP_MySQL_Lexer::ORDER_SYMBOL === $tokens[ $index ]->id && null === $clauses['order'] ) {
				$clauses['order'] = $index;
			} elseif ( WP_MySQL_Lexer::LIMIT_SYMBOL === $tokens[ $index ]->id && null === $clauses['limit'] ) {
				$clauses['limit'] = $index;
			}
		}

		return $clauses;
	}

	/**
	 * Check whether a token starts a supported DML clause.
	 *
	 * @param WP_Parser_Token $token Token.
	 * @return bool Whether the token starts a DML clause.
	 */
	private function is_dml_clause_start_token( WP_Parser_Token $token ): bool {
		return in_array(
			$token->id,
			array(
				WP_MySQL_Lexer::SET_SYMBOL,
				WP_MySQL_Lexer::WHERE_SYMBOL,
				WP_MySQL_Lexer::ORDER_SYMBOL,
				WP_MySQL_Lexer::LIMIT_SYMBOL,
			),
			true
		);
	}

	/**
	 * Translate UPDATE assignments, removing target qualifiers unsupported by DuckDB.
	 *
	 * @param WP_Parser_Token[]                                                                          $tokens    Update-list tokens.
	 * @param array{table_name:string,requested_table_name:string,alias:string|null,next_index:int} $reference Parsed table reference.
	 * @return string DuckDB update list SQL.
	 */
	private function translate_update_assignment_tokens_to_duckdb_sql( array $tokens, array $reference ): string {
		$qualifiers = array_filter(
			array(
				$reference['alias'],
				$reference['requested_table_name'],
				$reference['table_name'],
			),
			'is_string'
		);

		$items = array();
		foreach ( $this->split_top_level_comma_items( $tokens ) as $item ) {
			if (
				isset( $item[0], $item[1] )
				&& WP_MySQL_Lexer::DOT_SYMBOL === $item[1]->id
				&& in_array( strtolower( $this->identifier_value( $item[0] ) ), array_map( 'strtolower', $qualifiers ), true )
			) {
				$item = array_slice( $item, 2 );
			}
			$items[] = $this->translate_tokens_to_duckdb_sql( $item );
		}

		return implode( ', ', $items );
	}

	/**
	 * Translate joined UPDATE assignments and enforce a single writable target.
	 *
	 * @param WP_Parser_Token[]                                                $tokens  Update-list tokens.
	 * @param array{alias:string,table_name:string,requested_table_name:string} $target Target reference.
	 * @param array<int,array{alias:string,sql:string,table_name:string|null}>  $sources Source references.
	 * @return string DuckDB update-list SQL.
	 */
	private function translate_joined_update_assignment_tokens_to_duckdb_sql( array $tokens, array $target, array $sources ): string {
		$target_qualifiers = array_map(
			'strtolower',
			array_unique(
				array(
					$target['alias'],
					$target['requested_table_name'],
					$target['table_name'],
				)
			)
		);
		$source_aliases    = array();
		foreach ( $sources as $source ) {
			$source_aliases[ strtolower( $source['alias'] ) ] = $source;
			if ( null !== $source['table_name'] ) {
				$source_aliases[ strtolower( $source['table_name'] ) ] = $source;
			}
		}

		$items = array();
		foreach ( $this->split_top_level_comma_items( $tokens ) as $item ) {
			$equals_index = $this->find_top_level_token_index( $item, 0, WP_MySQL_Lexer::EQUAL_OPERATOR );
			if ( null === $equals_index ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported UPDATE statement in DuckDB driver. UPDATE assignment is required.' );
			}

			$left_tokens  = array_slice( $item, 0, $equals_index );
			$right_tokens = array_slice( $item, $equals_index + 1 );
			if ( count( $right_tokens ) === 0 ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported UPDATE statement in DuckDB driver. UPDATE assignment value is required.' );
			}

			if ( 1 === count( $left_tokens ) ) {
				$column = $this->identifier_value( $left_tokens[0] );
				if ( ! $this->table_has_column( $target['table_name'], $column, $target['temporary'] ) ) {
					throw new WP_DuckDB_Driver_Exception( "Unknown UPDATE target column '{$column}' in DuckDB driver." );
				}
				foreach ( $sources as $source ) {
					if ( null !== $source['table_name'] && $this->table_has_column( $source['table_name'], $column, $source['temporary'] ) ) {
						throw new WP_DuckDB_Driver_Exception( "Ambiguous unqualified UPDATE target column '{$column}' in DuckDB driver." );
					}
				}
			} elseif (
				3 === count( $left_tokens )
				&& WP_MySQL_Lexer::DOT_SYMBOL === $left_tokens[1]->id
			) {
				$qualifier = strtolower( $this->identifier_value( $left_tokens[0] ) );
				$column    = $this->identifier_value( $left_tokens[2] );
				if ( ! in_array( $qualifier, $target_qualifiers, true ) ) {
					if ( isset( $source_aliases[ $qualifier ] ) ) {
						throw new WP_DuckDB_Driver_Exception( 'Unsupported UPDATE statement in DuckDB driver. UPDATE statement modifying multiple tables is not supported.' );
					}
					throw new WP_DuckDB_Driver_Exception( "Unknown UPDATE target qualifier '{$this->identifier_value( $left_tokens[0] )}' in DuckDB driver." );
				}
				if ( ! $this->table_has_column( $target['table_name'], $column, $target['temporary'] ) ) {
					throw new WP_DuckDB_Driver_Exception( "Unknown UPDATE target column '{$column}' in DuckDB driver." );
				}
			} else {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported UPDATE statement in DuckDB driver. Only simple column assignments are supported.' );
			}

			$items[] = $this->connection->quote_identifier( $column )
				. ' = '
				. $this->translate_tokens_to_duckdb_sql( $right_tokens );
		}

		return implode( ', ', $items );
	}

	/**
	 * Check whether a table has a column.
	 *
	 * @param string $table_name  Table name.
	 * @param string $column_name Column name.
	 * @return bool Whether the column exists.
	 */
	private function table_has_column( string $table_name, string $column_name, bool $temporary = false ): bool {
		$metadata_rows = $this->column_metadata_rows( $table_name, $temporary );
		if ( count( $metadata_rows ) === 0 ) {
			$metadata_rows = $this->pragma_column_metadata_rows( $table_name );
		}

		foreach ( $metadata_rows as $metadata ) {
			if ( 0 === strcasecmp( (string) $metadata['column_name'], $column_name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a rowid subquery for ordered/limited UPDATE and DELETE statements.
	 *
	 * @param WP_Parser_Token[]                                                                          $tokens    MySQL tokens.
	 * @param array{where:int|null,order:int|null,limit:int|null}                                  $clauses   DML clause indexes.
	 * @param array{table_name:string,requested_table_name:string,alias:string|null,next_index:int} $reference Parsed table reference.
	 * @return string DuckDB rowid subquery SQL.
	 */
	private function dml_rowid_subquery_sql( array $tokens, array $clauses, array $reference ): string {
		$sql = 'SELECT rowid FROM ' . $this->dml_table_reference_sql( $reference );

		if ( null !== $clauses['where'] ) {
			$where_end = $clauses['order'] ?? $clauses['limit'] ?? count( $tokens );
			$sql      .= ' WHERE ' . $this->translate_tokens_to_duckdb_sql(
				array_slice( $tokens, $clauses['where'] + 1, $where_end - $clauses['where'] - 1 )
			);
		}

		if ( null !== $clauses['order'] ) {
			$order_end = $clauses['limit'] ?? count( $tokens );
			$sql      .= ' ' . $this->translate_tokens_to_duckdb_sql(
				array_slice( $tokens, $clauses['order'], $order_end - $clauses['order'] )
			);
		}

		if ( null !== $clauses['limit'] ) {
			$sql .= ' ' . $this->translate_tokens_to_duckdb_sql( array_slice( $tokens, $clauses['limit'] ) );
		}

		return $sql;
	}

	/**
	 * Ensure rowid-based DML rewrites cannot target a user column named rowid.
	 *
	 * @param string $table_name Table name.
	 * @param string $statement  Statement name.
	 */
	private function assert_dml_rowid_rewrite_supported( string $table_name, string $statement, bool $temporary = false ): void {
		$metadata_rows = $this->column_metadata_rows( $table_name, $temporary );
		if ( count( $metadata_rows ) === 0 ) {
			$metadata_rows = $this->pragma_column_metadata_rows( $table_name );
		}

		foreach ( $metadata_rows as $metadata ) {
			if ( 0 === strcasecmp( (string) $metadata['column_name'], 'rowid' ) ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported ' . $statement . ' statement in DuckDB driver. ORDER BY/LIMIT rewrites require a table without a user-defined rowid column.' );
			}
		}
	}

	/**
	 * Assert that INSERT/REPLACE use the supported VALUES form.
	 *
	 * @param WP_Parser_Token[] $tokens      MySQL tokens.
	 * @param int               $table_index Index expected to contain the table identifier.
	 * @param string            $statement   Statement name for errors.
	 */
	private function assert_values_write_statement( array $tokens, int $table_index, string $statement ): void {
		$this->identifier_value( $tokens[ $table_index ] ?? null );

		$has_values = false;
		foreach ( $tokens as $token ) {
			if ( WP_MySQL_Lexer::VALUES_SYMBOL === $token->id ) {
				$has_values = true;
				break;
			}
			if ( WP_MySQL_Lexer::SELECT_SYMBOL === $token->id || WP_MySQL_Lexer::SET_SYMBOL === $token->id ) {
				break;
			}
		}

		if ( ! $has_values ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ' . $statement . ' statement in DuckDB driver. Only ' . $statement . ' ... VALUES is supported.' );
		}
	}

	/**
	 * Find the ON DUPLICATE KEY UPDATE clause in an INSERT statement.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return int|null Index of the ON token, or null when absent.
	 */
	private function find_on_duplicate_key_update_index( array $tokens ): ?int {
		for ( $index = 0; $index < count( $tokens ) - 3; ++$index ) {
			if (
				WP_MySQL_Lexer::ON_SYMBOL === $tokens[ $index ]->id
				&& WP_MySQL_Lexer::DUPLICATE_SYMBOL === $tokens[ $index + 1 ]->id
				&& WP_MySQL_Lexer::KEY_SYMBOL === $tokens[ $index + 2 ]->id
				&& WP_MySQL_Lexer::UPDATE_SYMBOL === $tokens[ $index + 3 ]->id
			) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Find the SET clause in a supported INSERT ... SET statement.
	 *
	 * @param WP_Parser_Token[] $tokens      MySQL tokens.
	 * @param int               $table_index Index expected to contain the table identifier.
	 * @return int|null Index of the SET token, or null when absent.
	 */
	private function find_insert_set_index( array $tokens, int $table_index ): ?int {
		return isset( $tokens[ $table_index + 1 ] ) && WP_MySQL_Lexer::SET_SYMBOL === $tokens[ $table_index + 1 ]->id
			? $table_index + 1
			: null;
	}

	/**
	 * Find the top-level SELECT clause in a supported INSERT/REPLACE ... SELECT.
	 *
	 * @param WP_Parser_Token[] $tokens      MySQL tokens.
	 * @param int               $table_index Index expected to contain the table identifier.
	 * @return int|null Index of the SELECT token, or null when absent.
	 */
	private function find_insert_select_index( array $tokens, int $table_index ): ?int {
		$this->identifier_value( $tokens[ $table_index ] ?? null );

		$index = $table_index + 1;
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $index ]->id ) {
			$index = $this->skip_balanced_parentheses( $tokens, $index );
		}

		return isset( $tokens[ $index ] ) && WP_MySQL_Lexer::SELECT_SYMBOL === $tokens[ $index ]->id
			? $index
			: null;
	}

	/**
	 * Execute a supported ALTER TABLE statement.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_alter_table( array $tokens ): WP_DuckDB_Result_Statement {
		$index = 0;
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::ALTER_SYMBOL, 'Expected ALTER.' );
		++$index;
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::TABLE_SYMBOL, 'Only ALTER TABLE is supported by the DuckDB driver.' );
		++$index;

		$reference       = $this->parse_schema_lifecycle_table_reference( $tokens, $index, 'ALTER TABLE' );
		$table_reference = $this->resolve_required_lifecycle_table( $reference['requested_table_name'], 'ALTER TABLE' );
		$table_name      = $table_reference['table_name'];
		$temporary       = $table_reference['temporary'];
		$index           = $reference['next_index'];

		$actions = $this->split_top_level_comma_items( array_slice( $tokens, $index ) );
		if ( count( $actions ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. Only ADD COLUMN, ADD INDEX, DROP COLUMN, and DROP INDEX are supported.' );
		}

		$result = null;
		foreach ( $actions as $action ) {
			if ( ! isset( $action[0] ) ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. Empty action.' );
			}

			if ( WP_MySQL_Lexer::DROP_SYMBOL === $action[0]->id ) {
				$result = $this->is_alter_table_drop_index_action( $action )
					? $this->execute_alter_table_drop_index( $table_name, $action, $temporary )
					: $this->execute_alter_table_drop_column( $table_name, $action, $temporary );
				continue;
			}

			if ( WP_MySQL_Lexer::CHANGE_SYMBOL === $action[0]->id ) {
				$result = $this->execute_alter_table_change_column( $table_name, $action, $temporary );
				continue;
			}

			if ( WP_MySQL_Lexer::MODIFY_SYMBOL === $action[0]->id ) {
				$result = $this->execute_alter_table_modify_column( $table_name, $action, $temporary );
				continue;
			}

			if ( WP_MySQL_Lexer::AUTO_INCREMENT_SYMBOL === $action[0]->id ) {
				$result = $this->execute_alter_table_set_auto_increment( $table_name, $action, $temporary );
				continue;
			}

			$this->expect_token( $action, 0, WP_MySQL_Lexer::ADD_SYMBOL, 'Unsupported ALTER TABLE statement in DuckDB driver. Only ADD, DROP, CHANGE, MODIFY, and AUTO_INCREMENT actions are supported.' );
			$alter_item = array_slice( $action, 1 );

			$result = $this->is_create_table_index_item( $alter_item )
				? $this->execute_alter_table_add_index( $table_name, $alter_item, $temporary )
				: $this->execute_alter_table_add_column( $table_name, $alter_item, $temporary );
		}

		return $result ?? new WP_DuckDB_Result_Statement( array(), array(), 0 );
	}

	/**
	 * Execute ALTER TABLE ... AUTO_INCREMENT = N.
	 *
	 * @param string            $table_name Table name.
	 * @param WP_Parser_Token[] $tokens     ALTER action tokens.
	 * @param bool              $temporary  Whether the target is a temporary table.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_alter_table_set_auto_increment( string $table_name, array $tokens, bool $temporary = false ): WP_DuckDB_Result_Statement {
		$requested_next = $this->parse_auto_increment_option_value( $tokens, 1, 'ALTER TABLE' );
		$metadata       = $this->auto_increment_metadata_for_table( $table_name, $temporary );
		if ( null === $metadata ) {
			return $this->empty_ddl_result();
		}

		$max_existing = $this->max_auto_increment_column_value( $table_name, $metadata['column_name'], $temporary );
		$next_value   = max( $requested_next, $max_existing + 1, 1 );

		return $this->execute_schema_lifecycle_change(
			function () use ( $table_name, $next_value, $temporary ): WP_DuckDB_Result_Statement {
				$this->rebuild_auto_increment_table_with_next_value( $table_name, $next_value, $temporary );
				$this->invalidate_information_schema_compatibility_tables();
				return $this->empty_ddl_result();
			}
		);
	}

	/**
	 * Execute ALTER TABLE ... DROP INDEX|KEY.
	 *
	 * @param string            $table_name Table name.
	 * @param WP_Parser_Token[] $tokens     Action tokens starting at DROP.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_alter_table_drop_index( string $table_name, array $tokens, bool $temporary = false ): WP_DuckDB_Result_Statement {
		$index = 0;
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::DROP_SYMBOL, 'Expected DROP in ALTER TABLE action.' );
		++$index;

		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::PRIMARY_SYMBOL === $tokens[ $index ]->id ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. DROP PRIMARY KEY requires a table rebuild.' );
		}

		if (
			! isset( $tokens[ $index ] )
			|| ( WP_MySQL_Lexer::INDEX_SYMBOL !== $tokens[ $index ]->id && WP_MySQL_Lexer::KEY_SYMBOL !== $tokens[ $index ]->id )
		) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. Only DROP INDEX and DROP KEY are supported.' );
		}
		++$index;

		$index_name = $this->identifier_value( $tokens[ $index ] ?? null );
		++$index;
		if ( count( $tokens ) !== $index ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. DROP INDEX options are not supported.' );
		}

		return $this->execute_schema_lifecycle_change(
			function () use ( $table_name, $index_name, $temporary ): WP_DuckDB_Result_Statement {
				$this->drop_secondary_index( $table_name, $index_name, $temporary );
				$this->invalidate_information_schema_compatibility_tables();
				return $this->empty_ddl_result();
			}
		);
	}

	/**
	 * Check whether an ALTER TABLE DROP action targets an index/key.
	 *
	 * @param WP_Parser_Token[] $tokens Action tokens starting at DROP.
	 * @return bool Whether this is a DROP INDEX/KEY/PRIMARY action.
	 */
	private function is_alter_table_drop_index_action( array $tokens ): bool {
		if ( ! isset( $tokens[1] ) ) {
			return false;
		}

		return in_array(
			$tokens[1]->id,
			array(
				WP_MySQL_Lexer::INDEX_SYMBOL,
				WP_MySQL_Lexer::KEY_SYMBOL,
				WP_MySQL_Lexer::PRIMARY_SYMBOL,
			),
			true
		);
	}

	/**
	 * Execute ALTER TABLE ... DROP [COLUMN].
	 *
	 * @param string            $table_name Table name.
	 * @param WP_Parser_Token[] $tokens     Action tokens starting at DROP.
	 * @param bool              $temporary  Whether the target is a temporary table.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_alter_table_drop_column( string $table_name, array $tokens, bool $temporary = false ): WP_DuckDB_Result_Statement {
		$index = 0;
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::DROP_SYMBOL, 'Expected DROP in ALTER TABLE action.' );
		++$index;

		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::COLUMN_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
		}

		$column_name = $this->identifier_value( $tokens[ $index ] ?? null );
		++$index;

		if ( count( $tokens ) !== $index ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. DROP COLUMN options are not supported.' );
		}

		$resolved_column_name = $this->assert_alter_table_drop_column_supported( $table_name, $column_name, $temporary );
		$index_definitions    = $this->secondary_index_definitions_for_table( $table_name, $temporary );
		$rebuilt_indexes      = $this->secondary_index_definitions_after_column_drop( $table_name, $resolved_column_name, $index_definitions, $temporary );

		$callback = function () use ( $table_name, $resolved_column_name, $index_definitions, $rebuilt_indexes, $temporary ): WP_DuckDB_Result_Statement {
			return $this->execute_alter_table_drop_column_change( $table_name, $resolved_column_name, $index_definitions, $rebuilt_indexes, $temporary );
		};

		if ( count( $index_definitions ) > 0 ) {
			if ( $this->connection->inTransaction() ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. DROP COLUMN on indexed columns cannot run inside an active DuckDB transaction.' );
			}

			return $callback();
		}

		return $this->execute_schema_lifecycle_change(
			$callback
		);
	}

	/**
	 * Apply an ALTER TABLE ... DROP COLUMN after validation.
	 *
	 * @param string                                                                 $table_name       Table name.
	 * @param string                                                                 $column_name      Resolved column name.
	 * @param array<int,array{sql:string,table_name:string,index_name:string,unique:bool,columns:array<int,array{name:string,sub_part:int|null}>}> $dropped_indexes Existing indexes dropped before the schema change.
	 * @param array<int,array{sql:string,table_name:string,index_name:string,unique:bool,temporary:bool,columns:array<int,array{name:string,sub_part:int|null}>}> $rebuilt_indexes Rebuilt surviving indexes.
	 * @param bool                                                                   $temporary       Whether the target is a temporary table.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_alter_table_drop_column_change( string $table_name, string $column_name, array $dropped_indexes, array $rebuilt_indexes, bool $temporary = false ): WP_DuckDB_Result_Statement {
		foreach ( $dropped_indexes as $index_definition ) {
			$this->drop_physical_secondary_index( $table_name, $index_definition['index_name'], true, $temporary );
			$this->delete_index_metadata( $table_name, $index_definition['index_name'], $temporary );
		}

		try {
			$result = $this->execute_duckdb_query(
				'ALTER TABLE '
					. $this->connection->quote_identifier( $table_name )
					. ' DROP COLUMN '
					. $this->connection->quote_identifier( $column_name ),
				'Failed to drop DuckDB column'
			);
		} catch ( Throwable $e ) {
			$this->restore_secondary_index_definitions( $dropped_indexes );
			$this->refresh_column_key_metadata( $table_name, $temporary );
			$this->invalidate_information_schema_compatibility_tables();
			throw $e;
		}

		$this->delete_column_metadata( $table_name, $column_name, $temporary );

		foreach ( $rebuilt_indexes as $index_definition ) {
			$this->execute_duckdb_query( $index_definition['sql'], 'Failed to recreate DuckDB index after dropping column' );
			$this->record_index_metadata( $index_definition );
		}

		$this->refresh_column_key_metadata( $table_name, $temporary );
		$this->invalidate_information_schema_compatibility_tables();

		return $result;
	}

	/**
	 * Best-effort restore of secondary indexes after a failed DROP COLUMN.
	 *
	 * @param array<int,array{sql:string,table_name:string,index_name:string,unique:bool,columns:array<int,array{name:string,sub_part:int|null}>}> $index_definitions Index definitions.
	 */
	private function restore_secondary_index_definitions( array $index_definitions ): void {
		foreach ( $index_definitions as $index_definition ) {
			try {
				$this->execute_duckdb_query( $index_definition['sql'], 'Failed to restore DuckDB index after failed DROP COLUMN' );
				$this->record_index_metadata( $index_definition );
			} catch ( Throwable $restore_exception ) {
				unset( $restore_exception );
			}
		}
	}

	/**
	 * Resolve and validate a supported ALTER TABLE ... DROP COLUMN target.
	 *
	 * @param string $table_name  Table name.
	 * @param string $column_name Requested column name.
	 * @param bool   $temporary   Whether the target is a temporary table.
	 * @return string Resolved column name using stored table casing.
	 */
	private function assert_alter_table_drop_column_supported( string $table_name, string $column_name, bool $temporary = false ): string {
		$metadata = $this->table_column_metadata_rows( $table_name, $temporary );
		if ( count( $metadata ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( "Unknown table '{$this->database}.{$table_name}' in ALTER TABLE statement." );
		}

		$resolved_column_name = null;
		foreach ( $metadata as $column ) {
			if ( 0 === strcasecmp( (string) $column['column_name'], $column_name ) ) {
				$resolved_column_name = (string) $column['column_name'];
				break;
			}
		}

		if ( null === $resolved_column_name ) {
			throw new WP_DuckDB_Driver_Exception( "Unknown column '{$column_name}' on table '{$this->database}.{$table_name}' in DuckDB driver." );
		}

		if ( 1 === count( $metadata ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. DROP COLUMN cannot remove the last column.' );
		}

		foreach ( $this->primary_key_index_rows( $table_name ) as $index_row ) {
			if ( 0 === strcasecmp( (string) $index_row[4], $resolved_column_name ) ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. DROP COLUMN on a primary key column requires a table rebuild.' );
			}
		}

		$auto_increment = $this->auto_increment_metadata_for_table( $table_name, $temporary );
		if ( null !== $auto_increment && 0 === strcasecmp( $auto_increment['column_name'], $resolved_column_name ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. DROP COLUMN on an AUTO_INCREMENT column requires a table rebuild.' );
		}

		return $resolved_column_name;
	}

	/**
	 * Read stored column metadata, falling back to DuckDB pragma metadata.
	 *
	 * @param string $table_name Table name.
	 * @param bool   $temporary  Whether the target is a temporary table.
	 * @return array<int,array<string,mixed>>
	 */
	private function table_column_metadata_rows( string $table_name, bool $temporary = false ): array {
		$metadata = $this->column_metadata_rows( $table_name, $temporary );
		if ( count( $metadata ) === 0 ) {
			$metadata = $this->pragma_column_metadata_rows( $table_name );
		}

		return $metadata;
	}

	/**
	 * Check whether a secondary index definition contains a column.
	 *
	 * @param array{columns:array<int,array{name:string,sub_part:int|null}>} $index_definition Index definition.
	 * @param string                                                        $column_name      Column name.
	 * @return bool Whether the index references the column.
	 */
	private function index_definition_contains_column( array $index_definition, string $column_name ): bool {
		foreach ( $index_definition['columns'] as $column ) {
			if ( 0 === strcasecmp( $column['name'], $column_name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build secondary indexes that survive after dropping one column.
	 *
	 * @param string                                                                 $table_name        Table name.
	 * @param string                                                                 $column_name       Dropped column name.
	 * @param array<int,array{sql:string,table_name:string,index_name:string,unique:bool,columns:array<int,array{name:string,sub_part:int|null}>}> $index_definitions Current index definitions.
	 * @param bool                                                                   $temporary        Whether the target is a temporary table.
	 * @return array<int,array{sql:string,table_name:string,index_name:string,unique:bool,temporary:bool,columns:array<int,array{name:string,sub_part:int|null}>}>
	 */
	private function secondary_index_definitions_after_column_drop( string $table_name, string $column_name, array $index_definitions, bool $temporary = false ): array {
		$rebuilt_indexes = array();

		foreach ( $index_definitions as $index_definition ) {
			if ( ! $this->index_definition_contains_column( $index_definition, $column_name ) ) {
				$rebuilt_indexes[] = $index_definition;
				continue;
			}

			$column_metadata = array_values(
				array_filter(
					$index_definition['columns'],
					function ( array $column ) use ( $column_name ): bool {
						return 0 !== strcasecmp( $column['name'], $column_name );
					}
				)
			);

			if ( count( $column_metadata ) === 0 ) {
				continue;
			}

			$rebuilt_indexes[] = $this->build_secondary_index_definition(
				$table_name,
				$index_definition['index_name'],
				$index_definition['unique'],
				array_map(
					function ( array $column ): string {
						return $this->connection->quote_identifier( $column['name'] );
					},
					$column_metadata
				),
				$column_metadata,
				$temporary
			);
		}

		return $rebuilt_indexes;
	}

	/**
	 * Apply an ALTER TABLE ... CHANGE/MODIFY COLUMN after validation.
	 *
	 * @param string                                                                 $table_name          Table name.
	 * @param string                                                                 $current_column_name Resolved current column name.
	 * @param array<string,mixed>                                                    $metadata            New column metadata.
	 * @param array<string,mixed>                                                    $current_column       Current column metadata.
	 * @param array<int,array<string,mixed>>                                         $metadata_rows        Current table metadata rows.
	 * @param bool                                                                   $has_stored_metadata Whether metadata rows came from the driver metadata table.
	 * @param array<int,array{sql:string,table_name:string,index_name:string,unique:bool,columns:array<int,array{name:string,sub_part:int|null}>}> $dropped_indexes Current indexes dropped before the schema change.
	 * @param array<int,array{sql:string,table_name:string,index_name:string,unique:bool,temporary:bool,columns:array<int,array{name:string,sub_part:int|null}>}> $rebuilt_indexes Rebuilt surviving indexes.
	 * @param bool                                                                   $rename_column       Whether the column is renamed.
	 * @param bool                                                                   $type_change         Whether the physical DuckDB type changes.
	 * @param bool                                                                   $default_change      Whether the physical default changes.
	 * @param bool                                                                   $nullability_change  Whether the physical nullability changes.
	 * @param bool                                                                   $temporary           Whether the target is a temporary table.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_alter_table_change_modify_column_change( string $table_name, string $current_column_name, array $metadata, array $current_column, array $metadata_rows, bool $has_stored_metadata, array $dropped_indexes, array $rebuilt_indexes, bool $rename_column, bool $type_change, bool $default_change, bool $nullability_change, bool $temporary = false ): WP_DuckDB_Result_Statement {
		if ( $rename_column ) {
			foreach ( $dropped_indexes as $index_definition ) {
				$this->drop_physical_secondary_index( $table_name, $index_definition['index_name'], true, $temporary );
				$this->delete_index_metadata( $table_name, $index_definition['index_name'], $temporary );
			}
		}

		$active_column_name       = $current_column_name;
		$dropped_default_for_type = false;
		try {
			if ( $rename_column ) {
				$active_column_name = (string) $metadata['column_name'];
				$this->execute_duckdb_query(
					'ALTER TABLE '
						. $this->connection->quote_identifier( $table_name )
						. ' RENAME COLUMN '
						. $this->connection->quote_identifier( $current_column_name )
						. ' TO '
						. $this->connection->quote_identifier( $active_column_name ),
					'Failed to rename DuckDB column'
				);
			}

			if ( $type_change ) {
				if ( null !== $current_column['column_default'] ) {
					$this->execute_duckdb_query(
						'ALTER TABLE '
							. $this->connection->quote_identifier( $table_name )
							. ' ALTER COLUMN '
							. $this->connection->quote_identifier( $active_column_name )
							. ' DROP DEFAULT',
						'Failed to drop DuckDB column default before type change'
					);
					$dropped_default_for_type = true;
				}

				$this->execute_duckdb_query(
					'ALTER TABLE '
						. $this->connection->quote_identifier( $table_name )
						. ' ALTER COLUMN '
						. $this->connection->quote_identifier( $active_column_name )
						. ' SET DATA TYPE '
						. $metadata['_duckdb_type'],
					'Failed to change DuckDB column type'
				);
			}

			if ( $default_change || ( $type_change && null !== $metadata['_default_sql'] ) ) {
				if ( null === $metadata['_default_sql'] ) {
					if ( ! $dropped_default_for_type ) {
						$this->execute_duckdb_query(
							'ALTER TABLE '
								. $this->connection->quote_identifier( $table_name )
								. ' ALTER COLUMN '
								. $this->connection->quote_identifier( $active_column_name )
								. ' DROP DEFAULT',
							'Failed to drop DuckDB column default'
						);
					}
				} else {
					$this->execute_duckdb_query(
						'ALTER TABLE '
							. $this->connection->quote_identifier( $table_name )
							. ' ALTER COLUMN '
							. $this->connection->quote_identifier( $active_column_name )
							. ' SET DEFAULT '
							. $metadata['_default_sql'],
						'Failed to set DuckDB column default'
					);
				}
			}

			if ( $nullability_change ) {
				$this->execute_duckdb_query(
					'ALTER TABLE '
						. $this->connection->quote_identifier( $table_name )
						. ' ALTER COLUMN '
						. $this->connection->quote_identifier( $active_column_name )
						. ( 'NO' === $metadata['is_nullable'] ? ' SET NOT NULL' : ' DROP NOT NULL' ),
					'Failed to change DuckDB column nullability'
				);
			}
		} catch ( Throwable $e ) {
			if ( $rename_column ) {
				$this->restore_secondary_index_definitions( $dropped_indexes );
				$this->refresh_column_key_metadata( $table_name, $temporary );
				$this->invalidate_information_schema_compatibility_tables();
			}
			throw $e;
		}

		$this->replace_changed_column_metadata( $table_name, $current_column_name, $metadata, $metadata_rows, $has_stored_metadata, $temporary );

		if ( $rename_column ) {
			foreach ( $rebuilt_indexes as $index_definition ) {
				$this->execute_duckdb_query( $index_definition['sql'], 'Failed to recreate DuckDB index after changing column' );
				$this->record_index_metadata( $index_definition );
			}
		}

		$this->refresh_column_key_metadata( $table_name, $temporary );
		$this->invalidate_information_schema_compatibility_tables();

		return $this->empty_ddl_result();
	}

	/**
	 * Resolve current column metadata for CHANGE/MODIFY.
	 *
	 * @param string                         $table_name    Table name.
	 * @param string                         $column_name   Requested column name.
	 * @param array<int,array<string,mixed>> $metadata_rows Current table metadata rows.
	 * @return array<string,mixed> Current column metadata.
	 */
	private function resolve_alter_table_change_column_metadata( string $table_name, string $column_name, array $metadata_rows ): array {
		if ( count( $metadata_rows ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( "Unknown table '{$this->database}.{$table_name}' in ALTER TABLE statement." );
		}

		foreach ( $metadata_rows as $column ) {
			if ( 0 === strcasecmp( (string) $column['column_name'], $column_name ) ) {
				return $column;
			}
		}

		throw new WP_DuckDB_Driver_Exception( "Unknown column '{$column_name}' on table '{$this->database}.{$table_name}' in DuckDB driver." );
	}

	/**
	 * Validate a supported ALTER TABLE ... CHANGE/MODIFY target.
	 *
	 * @param string                         $table_name      Table name.
	 * @param array<string,mixed>            $current_column  Current column metadata.
	 * @param array<int,array<string,mixed>> $metadata_rows   Current table metadata rows.
	 * @param string                         $new_column_name Requested new column name.
	 * @param bool                           $temporary       Whether the target is a temporary table.
	 */
	private function assert_alter_table_change_column_supported( string $table_name, array $current_column, array $metadata_rows, string $new_column_name, bool $temporary = false ): void {
		$current_column_name = (string) $current_column['column_name'];
		foreach ( $metadata_rows as $column ) {
			if (
				0 !== strcasecmp( (string) $column['column_name'], $current_column_name )
				&& 0 === strcasecmp( (string) $column['column_name'], $new_column_name )
			) {
				throw new WP_DuckDB_Driver_Exception( "Duplicate column name '{$new_column_name}' in ALTER TABLE statement." );
			}
		}

		foreach ( $this->primary_key_index_rows( $table_name ) as $index_row ) {
			if ( 0 === strcasecmp( (string) $index_row[4], $current_column_name ) ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. CHANGE/MODIFY on a primary key column requires a table rebuild.' );
			}
		}

		$auto_increment = $this->auto_increment_metadata_for_table( $table_name, $temporary );
		if (
			( null !== $auto_increment && 0 === strcasecmp( $auto_increment['column_name'], $current_column_name ) )
			|| 'auto_increment' === $current_column['extra']
		) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. CHANGE/MODIFY on an AUTO_INCREMENT column requires a table rebuild.' );
		}
	}

	/**
	 * Read one physical DuckDB pragma column row.
	 *
	 * @param string $table_name  Table name.
	 * @param string $column_name Column name.
	 * @return array<string,mixed> Physical column info.
	 */
	private function physical_column_info_row( string $table_name, string $column_name ): array {
		$stmt = $this->execute_duckdb_query(
			'SELECT name, type, "notnull", dflt_value, pk FROM pragma_table_info(' . $this->connection->quote( $table_name ) . ') ORDER BY cid',
			'Failed to inspect DuckDB table columns'
		);

		foreach ( $stmt->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
			if ( 0 === strcasecmp( (string) $row['name'], $column_name ) ) {
				return $row;
			}
		}

		throw new WP_DuckDB_Driver_Exception( "Unknown column '{$column_name}' on table '{$this->database}.{$table_name}' in DuckDB driver." );
	}

	/**
	 * Check whether a CHANGE/MODIFY definition changes the stored default.
	 *
	 * @param array<string,mixed> $current_column Current column metadata.
	 * @param array<string,mixed> $metadata       New column metadata.
	 * @return bool Whether the default changes.
	 */
	private function column_default_changed( array $current_column, array $metadata ): bool {
		$current_default = $current_column['column_default'];
		$new_default     = $metadata['column_default'];

		if ( null === $current_default || null === $new_default ) {
			return $current_default !== $new_default;
		}

		return (string) $current_default !== (string) $new_default;
	}

	/**
	 * Normalize DuckDB type strings for physical type comparisons.
	 *
	 * @param string $type DuckDB type.
	 * @return string Canonical type.
	 */
	private function canonical_duckdb_type( string $type ): string {
		$type = strtoupper( trim( preg_replace( '/\s+/', ' ', $type ) ) );

		$aliases = array(
			'INT'     => 'INTEGER',
			'STRING'  => 'VARCHAR',
			'CHAR'    => 'VARCHAR',
			'BOOLEAN' => 'BOOLEAN',
			'BOOL'    => 'BOOLEAN',
		);

		return $aliases[ $type ] ?? $type;
	}

	/**
	 * Build secondary indexes after renaming one column.
	 *
	 * @param string                                                                 $table_name        Table name.
	 * @param string                                                                 $old_column_name   Old column name.
	 * @param string                                                                 $new_column_name   New column name.
	 * @param array<int,array{sql:string,table_name:string,index_name:string,unique:bool,columns:array<int,array{name:string,sub_part:int|null}>}> $index_definitions Current index definitions.
	 * @param bool                                                                   $temporary        Whether the target is a temporary table.
	 * @return array<int,array{sql:string,table_name:string,index_name:string,unique:bool,temporary:bool,columns:array<int,array{name:string,sub_part:int|null}>}>
	 */
	private function secondary_index_definitions_after_column_rename( string $table_name, string $old_column_name, string $new_column_name, array $index_definitions, bool $temporary = false ): array {
		$rebuilt_indexes = array();

		foreach ( $index_definitions as $index_definition ) {
			$column_metadata = array_map(
				function ( array $column ) use ( $old_column_name, $new_column_name ): array {
					if ( 0 === strcasecmp( $column['name'], $old_column_name ) ) {
						$column['name'] = $new_column_name;
					}
					return $column;
				},
				$index_definition['columns']
			);

			$rebuilt_indexes[] = $this->build_secondary_index_definition(
				$table_name,
				$index_definition['index_name'],
				$index_definition['unique'],
				array_map(
					function ( array $column ): string {
						return $this->connection->quote_identifier( $column['name'] );
					},
					$column_metadata
				),
				$column_metadata,
				$temporary
			);
		}

		return $rebuilt_indexes;
	}

	/**
	 * Replace one stored column metadata row while preserving ordinal positions.
	 *
	 * @param string                         $table_name          Table name.
	 * @param string                         $old_column_name     Old column name.
	 * @param array<string,mixed>            $metadata            New column metadata.
	 * @param array<int,array<string,mixed>> $metadata_rows       Current table metadata rows.
	 * @param bool                           $has_stored_metadata Whether metadata rows came from the driver metadata table.
	 * @param bool                           $temporary           Whether the target is a temporary table.
	 */
	private function replace_changed_column_metadata( string $table_name, string $old_column_name, array $metadata, array $metadata_rows, bool $has_stored_metadata, bool $temporary = false ): void {
		if ( ! $has_stored_metadata ) {
			foreach ( $metadata_rows as &$column ) {
				if ( 0 === strcasecmp( (string) $column['column_name'], $old_column_name ) ) {
					$column = array_merge(
						$column,
						array(
							'column_name'    => $metadata['column_name'],
							'column_type'    => $metadata['column_type'],
							'is_nullable'    => $metadata['is_nullable'],
							'column_key'     => $metadata['column_key'],
							'column_default' => $metadata['column_default'],
							'extra'          => $metadata['extra'],
							'collation_name' => $metadata['collation_name'],
							'comment'        => $metadata['comment'],
						)
					);
					break;
				}
			}
			unset( $column );

			$this->record_column_metadata( $table_name, $metadata_rows, $temporary );
			return;
		}

		$this->ensure_column_metadata_table( $temporary );
		$this->execute_duckdb_query(
			'UPDATE '
				. $this->connection->quote_identifier( $this->column_metadata_table_name( $temporary ) )
				. ' SET column_name = '
				. $this->connection->quote( $metadata['column_name'] )
				. ', column_type = '
				. $this->connection->quote( $metadata['column_type'] )
				. ', is_nullable = '
				. $this->connection->quote( $metadata['is_nullable'] )
				. ', column_key = '
				. $this->connection->quote( $metadata['column_key'] )
				. ', column_default = '
				. $this->connection->quote( $metadata['column_default'] )
				. ', extra = '
				. $this->connection->quote( $metadata['extra'] )
				. ', collation_name = '
				. $this->connection->quote( $metadata['collation_name'] )
				. ', comment = '
				. $this->connection->quote( $metadata['comment'] )
				. ' WHERE table_name = '
				. $this->connection->quote( $table_name )
				. ' AND column_name = '
				. $this->connection->quote( $old_column_name ),
			'Failed to update DuckDB column metadata'
		);
	}

	/**
	 * Delete stored metadata for a dropped column.
	 *
	 * @param string $table_name  Table name.
	 * @param string $column_name Column name.
	 * @param bool   $temporary   Whether the target is a temporary table.
	 */
	private function delete_column_metadata( string $table_name, string $column_name, bool $temporary = false ): void {
		$this->ensure_column_metadata_table( $temporary );

		$this->execute_duckdb_query(
			'DELETE FROM '
				. $this->connection->quote_identifier( $this->column_metadata_table_name( $temporary ) )
				. ' WHERE table_name = '
				. $this->connection->quote( $table_name )
				. ' AND column_name = '
				. $this->connection->quote( $column_name ),
			'Failed to delete DuckDB column metadata'
		);
	}

	/**
	 * Execute ALTER TABLE ... ADD INDEX.
	 *
	 * @param string            $table_name Table name.
	 * @param WP_Parser_Token[] $tokens     Index definition tokens after ADD.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_alter_table_add_index( string $table_name, array $tokens, bool $temporary = false ): WP_DuckDB_Result_Statement {
		$index_definition = $this->translate_create_table_index( $table_name, $tokens, $temporary );
		$result           = $this->execute_duckdb_query( $index_definition['sql'], 'Failed to create DuckDB index' );
		$this->record_index_metadata( $index_definition );

		return $result;
	}

	/**
	 * Execute a bounded ALTER TABLE ... ADD [COLUMN] statement.
	 *
	 * @param string            $table_name Table name.
	 * @param WP_Parser_Token[] $tokens     Tokens after ADD.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_alter_table_add_column( string $table_name, array $tokens, bool $temporary = false ): WP_DuckDB_Result_Statement {
		if ( isset( $tokens[0] ) && WP_MySQL_Lexer::COLUMN_SYMBOL === $tokens[0]->id ) {
			$tokens = array_slice( $tokens, 1 );
		}

		if ( count( $tokens ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. ADD COLUMN requires a column definition.' );
		}

		if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[0]->id ) {
			list( $items, $index ) = $this->collect_parenthesized_items( $tokens, 1 );
			if ( 1 !== count( $items ) || count( $tokens ) !== $index ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. Only a single ADD COLUMN definition is supported.' );
			}
			$tokens = $items[0];
		}

		list( $column_sql, $sequence_sql, $indexes, $metadata ) = $this->translate_create_table_column( $table_name, $tokens, false, true, $temporary, null, $this->table_default_collation( $table_name, $temporary ) );
		if ( 'PRI' === $metadata['column_key'] ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. ADD COLUMN PRIMARY KEY is not supported.' );
		}
		if ( 'auto_increment' === $metadata['extra'] ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. ADD COLUMN AUTO_INCREMENT is not supported.' );
		}

		if (
			'NO' === $metadata['is_nullable']
			&& null === $metadata['column_default']
			&& 'auto_increment' !== $metadata['extra']
			&& $this->table_has_rows( $table_name )
		) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. ADD COLUMN NOT NULL requires a DEFAULT for non-empty tables.' );
		}

		if ( null !== $sequence_sql ) {
			$this->execute_duckdb_query( $sequence_sql, 'Failed to create DuckDB AUTO_INCREMENT sequence' );
		}

		$result = $this->execute_duckdb_query(
			'ALTER TABLE '
				. $this->connection->quote_identifier( $table_name )
				. ' ADD COLUMN '
				. $column_sql,
			'Failed to add DuckDB column'
		);

		if ( 'NO' === $metadata['is_nullable'] ) {
			$this->execute_with_secondary_indexes_rebuilt(
				$table_name,
				function () use ( $table_name, $metadata ): void {
					$this->execute_duckdb_query(
						'ALTER TABLE '
							. $this->connection->quote_identifier( $table_name )
							. ' ALTER COLUMN '
							. $this->connection->quote_identifier( $metadata['column_name'] )
							. ' SET NOT NULL',
						'Failed to apply DuckDB NOT NULL column constraint'
					);
				},
				$temporary
			);
		}

		foreach ( $indexes as $index_definition ) {
			$this->execute_duckdb_query( $index_definition['sql'], 'Failed to create DuckDB index' );
			$this->record_index_metadata( $index_definition );
		}
		$this->append_column_metadata( $table_name, $metadata, $temporary );

		return $result;
	}

	/**
	 * Execute ALTER TABLE ... CHANGE [COLUMN].
	 *
	 * @param string            $table_name Table name.
	 * @param WP_Parser_Token[] $tokens     Action tokens starting at CHANGE.
	 * @param bool              $temporary  Whether the target is a temporary table.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_alter_table_change_column( string $table_name, array $tokens, bool $temporary = false ): WP_DuckDB_Result_Statement {
		$index = 0;
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::CHANGE_SYMBOL, 'Expected CHANGE in ALTER TABLE action.' );
		++$index;

		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::COLUMN_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
		}

		$old_column_name = $this->identifier_value( $tokens[ $index ] ?? null );
		++$index;

		$new_column_token = $tokens[ $index ] ?? null;
		$this->identifier_value( $new_column_token );
		++$index;

		$definition_tokens = array_merge( array( $new_column_token ), array_slice( $tokens, $index ) );
		if ( count( $definition_tokens ) < 2 ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. CHANGE COLUMN requires a full column definition.' );
		}

		return $this->execute_alter_table_change_or_modify_column( $table_name, $old_column_name, $definition_tokens, $temporary );
	}

	/**
	 * Execute ALTER TABLE ... MODIFY [COLUMN].
	 *
	 * @param string            $table_name Table name.
	 * @param WP_Parser_Token[] $tokens     Action tokens starting at MODIFY.
	 * @param bool              $temporary  Whether the target is a temporary table.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_alter_table_modify_column( string $table_name, array $tokens, bool $temporary = false ): WP_DuckDB_Result_Statement {
		$index = 0;
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::MODIFY_SYMBOL, 'Expected MODIFY in ALTER TABLE action.' );
		++$index;

		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::COLUMN_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
		}

		$column_token = $tokens[ $index ] ?? null;
		$column_name  = $this->identifier_value( $column_token );
		++$index;

		$definition_tokens = array_merge( array( $column_token ), array_slice( $tokens, $index ) );
		if ( count( $definition_tokens ) < 2 ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. MODIFY COLUMN requires a full column definition.' );
		}

		return $this->execute_alter_table_change_or_modify_column( $table_name, $column_name, $definition_tokens, $temporary );
	}

	/**
	 * Execute the common CHANGE/MODIFY column path.
	 *
	 * @param string            $table_name        Table name.
	 * @param string            $old_column_name   Existing column name.
	 * @param WP_Parser_Token[] $definition_tokens New column definition tokens.
	 * @param bool              $temporary         Whether the target is a temporary table.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_alter_table_change_or_modify_column( string $table_name, string $old_column_name, array $definition_tokens, bool $temporary = false ): WP_DuckDB_Result_Statement {
		list( , $sequence_sql, $inline_indexes, $metadata ) = $this->translate_create_table_column( $table_name, $definition_tokens, false, true, $temporary, null, $this->table_default_collation( $table_name, $temporary ) );
		if ( null !== $sequence_sql || 'auto_increment' === $metadata['extra'] ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. CHANGE/MODIFY AUTO_INCREMENT requires a table rebuild.' );
		}
		if ( 'PRI' === $metadata['column_key'] ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. CHANGE/MODIFY inline PRIMARY KEY is not supported.' );
		}
		if ( 'UNI' === $metadata['column_key'] || count( $inline_indexes ) > 0 ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. CHANGE/MODIFY inline UNIQUE is not supported.' );
		}

		$stored_metadata_rows = $this->column_metadata_rows( $table_name, $temporary );
		$metadata_rows        = count( $stored_metadata_rows ) > 0 ? $stored_metadata_rows : $this->pragma_column_metadata_rows( $table_name );
		$current_column       = $this->resolve_alter_table_change_column_metadata( $table_name, $old_column_name, $metadata_rows );
		$current_column_name  = (string) $current_column['column_name'];
		$new_column_name      = (string) $metadata['column_name'];

		$this->assert_alter_table_change_column_supported( $table_name, $current_column, $metadata_rows, $new_column_name, $temporary );

		$physical_column      = $this->physical_column_info_row( $table_name, $current_column_name );
		$rename_column        = 0 !== strcasecmp( $current_column_name, $new_column_name );
		$type_change          = $this->canonical_duckdb_type( (string) $physical_column['type'] ) !== $this->canonical_duckdb_type( (string) $metadata['_duckdb_type'] );
		$default_change       = $this->column_default_changed( $current_column, $metadata );
		$nullability_change   = (string) $current_column['is_nullable'] !== (string) $metadata['is_nullable'];
		$index_definitions    = $this->secondary_index_definitions_for_table( $table_name, $temporary );
		$rebuilt_indexes      = $rename_column
			? $this->secondary_index_definitions_after_column_rename( $table_name, $current_column_name, $new_column_name, $index_definitions, $temporary )
			: $index_definitions;
		$has_physical_changes = $rename_column || $type_change || $default_change || $nullability_change;

		if ( count( $index_definitions ) > 0 && $has_physical_changes && ! $rename_column ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. CHANGE/MODIFY on indexed columns that changes type, default, or nullability requires a table rebuild.' );
		}
		if ( count( $index_definitions ) > 0 && $rename_column && ( $type_change || $default_change || $nullability_change ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. CHANGE COLUMN rename on indexed columns cannot also change type, default, or nullability without a table rebuild.' );
		}

		$callback = function () use ( $table_name, $current_column_name, $metadata, $current_column, $metadata_rows, $stored_metadata_rows, $index_definitions, $rebuilt_indexes, $rename_column, $type_change, $default_change, $nullability_change, $temporary ): WP_DuckDB_Result_Statement {
			return $this->execute_alter_table_change_modify_column_change(
				$table_name,
				$current_column_name,
				$metadata,
				$current_column,
				$metadata_rows,
				count( $stored_metadata_rows ) > 0,
				$index_definitions,
				$rebuilt_indexes,
				$rename_column,
				$type_change,
				$default_change,
				$nullability_change,
				$temporary
			);
		};

		if ( count( $index_definitions ) > 0 && $rename_column ) {
			if ( $this->connection->inTransaction() ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. CHANGE COLUMN on indexed columns cannot run inside an active DuckDB transaction.' );
			}

			return $callback();
		}

		return $this->execute_schema_lifecycle_change( $callback );
	}

	/**
	 * Execute supported SHOW statements.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_show( array $tokens ): WP_DuckDB_Result_Statement {
		if ( isset( $tokens[1] ) && WP_MySQL_Lexer::COLLATION_SYMBOL === $tokens[1]->id ) {
			return $this->execute_show_collation( $tokens );
		}

		if ( isset( $tokens[1] ) && WP_MySQL_Lexer::DATABASES_SYMBOL === $tokens[1]->id ) {
			return $this->execute_show_databases( $tokens );
		}

		if ( isset( $tokens[1] ) && WP_MySQL_Lexer::GRANTS_SYMBOL === $tokens[1]->id ) {
			return $this->execute_show_grants( $tokens );
		}

		if (
			isset( $tokens[1] )
			&& (
				WP_MySQL_Lexer::VARIABLES_SYMBOL === $tokens[1]->id
				|| (
					isset( $tokens[2] )
					&& in_array( $tokens[1]->id, array( WP_MySQL_Lexer::GLOBAL_SYMBOL, WP_MySQL_Lexer::LOCAL_SYMBOL, WP_MySQL_Lexer::SESSION_SYMBOL ), true )
					&& WP_MySQL_Lexer::VARIABLES_SYMBOL === $tokens[2]->id
				)
			)
		) {
			return $this->execute_show_variables( $tokens );
		}

		if (
			isset( $tokens[1] )
			&& (
				WP_MySQL_Lexer::TABLES_SYMBOL === $tokens[1]->id
				|| (
					isset( $tokens[2] )
					&& WP_MySQL_Lexer::FULL_SYMBOL === $tokens[1]->id
					&& WP_MySQL_Lexer::TABLES_SYMBOL === $tokens[2]->id
				)
			)
		) {
			return $this->execute_show_tables( $tokens );
		}

		if (
			isset( $tokens[1], $tokens[2] )
			&& WP_MySQL_Lexer::CREATE_SYMBOL === $tokens[1]->id
			&& WP_MySQL_Lexer::VIEW_SYMBOL === $tokens[2]->id
		) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported SHOW CREATE VIEW statement in DuckDB driver. MySQL view lifecycle metadata is not supported (showStatement > CREATE).' );
		}

		if (
			isset( $tokens[1], $tokens[2] )
			&& WP_MySQL_Lexer::CREATE_SYMBOL === $tokens[1]->id
			&& WP_MySQL_Lexer::TABLE_SYMBOL === $tokens[2]->id
		) {
			return $this->execute_show_create_table( $tokens );
		}

		if (
			isset( $tokens[1], $tokens[2] )
			&& WP_MySQL_Lexer::TABLE_SYMBOL === $tokens[1]->id
			&& WP_MySQL_Lexer::STATUS_SYMBOL === $tokens[2]->id
		) {
			return $this->execute_show_table_status( $tokens );
		}

		if (
			isset( $tokens[1] )
			&& (
				WP_MySQL_Lexer::COLUMNS_SYMBOL === $tokens[1]->id
				|| (
					isset( $tokens[2] )
					&& WP_MySQL_Lexer::FULL_SYMBOL === $tokens[1]->id
					&& WP_MySQL_Lexer::COLUMNS_SYMBOL === $tokens[2]->id
				)
			)
		) {
			return $this->execute_show_columns( $tokens );
		}

		if (
			isset( $tokens[1] )
			&& in_array( $tokens[1]->id, array( WP_MySQL_Lexer::INDEX_SYMBOL, WP_MySQL_Lexer::INDEXES_SYMBOL, WP_MySQL_Lexer::KEYS_SYMBOL ), true )
		) {
			return $this->execute_show_index( $tokens );
		}

		throw new WP_DuckDB_Driver_Exception( 'Unsupported SHOW statement in DuckDB driver.' );
	}

	/**
	 * Execute SHOW COLLATION.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_show_collation( array $tokens ): WP_DuckDB_Result_Statement {
		$this->expect_token( $tokens, 1, WP_MySQL_Lexer::COLLATION_SYMBOL, 'Expected COLLATION in SHOW COLLATION statement.' );

		return $this->execute_static_show_metadata_statement(
			array( 'Collation', 'Charset', 'Id', 'Default', 'Compiled', 'Sortlen', 'Pad_attribute' ),
			$this->show_collation_rows(),
			'Collation',
			$tokens,
			2,
			'SHOW COLLATION'
		);
	}

	/**
	 * Build SQLite-compatible SHOW COLLATION rows.
	 *
	 * @return array<int,array<int,mixed>>
	 */
	private function show_collation_rows(): array {
		return array(
			array( 'binary', 'binary', 63, 'Yes', 'Yes', 1, 'NO PAD' ),
			array( 'utf8_bin', 'utf8', 83, '', 'Yes', 1, 'PAD SPACE' ),
			array( 'utf8_general_ci', 'utf8', 33, 'Yes', 'Yes', 1, 'PAD SPACE' ),
			array( 'utf8_unicode_ci', 'utf8', 192, '', 'Yes', 8, 'PAD SPACE' ),
			array( 'utf8mb4_bin', 'utf8mb4', 46, '', 'Yes', 1, 'PAD SPACE' ),
			array( 'utf8mb4_unicode_ci', 'utf8mb4', 224, '', 'Yes', 8, 'PAD SPACE' ),
			array( 'utf8mb4_0900_ai_ci', 'utf8mb4', 255, 'Yes', 'Yes', 0, 'NO PAD' ),
		);
	}

	/**
	 * Execute SHOW DATABASES.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_show_databases( array $tokens ): WP_DuckDB_Result_Statement {
		$this->expect_token( $tokens, 1, WP_MySQL_Lexer::DATABASES_SYMBOL, 'Expected DATABASES in SHOW DATABASES statement.' );

		return $this->execute_static_show_metadata_statement(
			array( 'Database' ),
			array(
				array( 'information_schema' ),
				array( $this->database ),
			),
			'Database',
			$tokens,
			2,
			'SHOW DATABASES'
		);
	}

	/**
	 * Execute SHOW GRANTS.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_show_grants( array $tokens ): WP_DuckDB_Result_Statement {
		$this->expect_token( $tokens, 1, WP_MySQL_Lexer::GRANTS_SYMBOL, 'Expected GRANTS in SHOW GRANTS statement.' );

		if ( 2 !== count( $tokens ) ) {
			if (
				! isset( $tokens[2], $tokens[3] )
				|| WP_MySQL_Lexer::FOR_SYMBOL !== $tokens[2]->id
			) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported SHOW GRANTS statement in DuckDB driver. Use SHOW GRANTS or SHOW GRANTS FOR a parsed target.' );
			}
		}

		return new WP_DuckDB_Result_Statement(
			array( 'Grants for root@%' ),
			array(
				array(
					'GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, RELOAD, SHUTDOWN, PROCESS, FILE, REFERENCES, INDEX, ALTER, SHOW DATABASES, SUPER, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, REPLICATION SLAVE, REPLICATION CLIENT, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, CREATE USER, EVENT, TRIGGER, CREATE TABLESPACE, CREATE ROLE, DROP ROLE ON *.* TO `root`@`localhost` WITH GRANT OPTION',
				),
			)
		);
	}

	/**
	 * Execute SHOW VARIABLES.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_show_variables( array $tokens ): WP_DuckDB_Result_Statement {
		$index = 1;
		if (
			isset( $tokens[ $index ] )
			&& in_array( $tokens[ $index ]->id, array( WP_MySQL_Lexer::GLOBAL_SYMBOL, WP_MySQL_Lexer::LOCAL_SYMBOL, WP_MySQL_Lexer::SESSION_SYMBOL ), true )
		) {
			++$index;
		}

		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::VARIABLES_SYMBOL, 'Expected VARIABLES in SHOW VARIABLES statement.' );
		++$index;

		$this->consume_show_like_or_where_clause( $tokens, $index, 'SHOW VARIABLES' );

		return new WP_DuckDB_Result_Statement(
			array( 'Variable_name', 'Value' ),
			array()
		);
	}

	/**
	 * Execute a static SHOW metadata statement with optional LIKE or WHERE.
	 *
	 * @param string[]                $columns     Result column names.
	 * @param array<int,array<mixed>> $rows        Result rows.
	 * @param string                  $like_column Column filtered by LIKE.
	 * @param WP_Parser_Token[]       $tokens      MySQL tokens.
	 * @param int                     $index       Index after the SHOW statement name.
	 * @param string                  $statement   Statement label for errors.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_static_show_metadata_statement( array $columns, array $rows, string $like_column, array $tokens, int $index, string $statement ): WP_DuckDB_Result_Statement {
		$condition = $this->consume_show_like_or_where_clause( $tokens, $index, $statement, $like_column, $columns );

		$select_columns = array_map(
			function ( string $column ): string {
				return $this->connection->quote_identifier( $column );
			},
			$columns
		);

		$aliased_columns = array_merge(
			array( $this->connection->quote_identifier( '__wp_order' ) ),
			$select_columns
		);

		$value_rows = array();
		foreach ( $rows as $ordinal => $row ) {
			$values = array( (int) $ordinal );
			foreach ( $row as $value ) {
				$values[] = $value;
			}
			$value_rows[] = '(' . implode(
				', ',
				array_map(
					function ( $value ): string {
						return $this->connection->quote( $value );
					},
					$values
				)
			) . ')';
		}

		$sql = 'SELECT '
			. implode( ', ', $select_columns )
			. ' FROM (VALUES '
			. implode( ', ', $value_rows )
			. ') AS '
			. $this->connection->quote_identifier( '__wp_show' )
			. '('
			. implode( ', ', $aliased_columns )
			. ')'
			. $condition
			. ' ORDER BY '
			. $this->connection->quote_identifier( '__wp_order' );

		return $this->execute_duckdb_query( $sql, 'Failed to execute ' . $statement );
	}

	/**
	 * Consume an optional SHOW LIKE or WHERE clause.
	 *
	 * @param WP_Parser_Token[] $tokens      MySQL tokens.
	 * @param int               $index       Clause start index.
	 * @param string            $statement   Statement label for errors.
	 * @param string|null       $like_column Column filtered by LIKE, or null to only validate.
	 * @param string[]          $where_columns Result column aliases available to WHERE.
	 * @return string DuckDB SQL condition.
	 */
	private function consume_show_like_or_where_clause( array $tokens, int $index, string $statement, ?string $like_column = null, array $where_columns = array() ): string {
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::LIKE_SYMBOL === $tokens[ $index ]->id ) {
			if (
				! isset( $tokens[ $index + 1 ] )
				|| (
					WP_MySQL_Lexer::SINGLE_QUOTED_TEXT !== $tokens[ $index + 1 ]->id
					&& WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT !== $tokens[ $index + 1 ]->id
				)
			) {
				throw new WP_DuckDB_Driver_Exception( $statement . ' LIKE requires a string pattern in the DuckDB driver.' );
			}

			$index += 2;
			if ( count( $tokens ) !== $index ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported ' . $statement . ' statement in DuckDB driver. Only optional LIKE and WHERE are supported.' );
			}

			if ( null === $like_column ) {
				return '';
			}

			return ' WHERE '
				. $this->connection->quote_identifier( $like_column )
				. ' LIKE '
				. $this->connection->quote( $tokens[ $index - 1 ]->get_value() )
				. ' ESCAPE '
				. $this->connection->quote( '\\' );
		}

		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::WHERE_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
			if ( ! isset( $tokens[ $index ] ) ) {
				throw new WP_DuckDB_Driver_Exception( $statement . ' WHERE requires an expression in the DuckDB driver.' );
			}

			$condition_tokens = array_slice( $tokens, $index );
			$index            = count( $tokens );
			if ( count( $tokens ) !== $index ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported ' . $statement . ' statement in DuckDB driver. Only optional LIKE and WHERE are supported.' );
			}

			if ( null === $like_column ) {
				return '';
			}

			if ( 1 === count( $condition_tokens ) && $this->is_number_token( $condition_tokens[0] ) ) {
				return ' WHERE ' . ( 0.0 === (float) $this->number_token_value( $condition_tokens[0] ) ? 'FALSE' : 'TRUE' );
			}

			return ' WHERE ' . $this->translate_static_show_where_condition( $condition_tokens, $where_columns );
		}

		if ( count( $tokens ) !== $index ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ' . $statement . ' statement in DuckDB driver. Only optional LIKE and WHERE are supported.' );
		}

		return '';
	}

	/**
	 * Translate a static SHOW WHERE expression with result-alias identifiers.
	 *
	 * SQLite accepts SHOW output aliases in WHERE. DuckDB needs keyword-like
	 * aliases such as Collation quoted when filtering a VALUES-backed result.
	 *
	 * @param WP_Parser_Token[] $tokens  WHERE expression tokens.
	 * @param string[]          $columns Result column aliases.
	 * @return string DuckDB SQL expression.
	 */
	private function translate_static_show_where_condition( array $tokens, array $columns ): string {
		$column_map = array();
		foreach ( $columns as $column ) {
			$column_map[ strtolower( $column ) ] = $column;
		}

		$pieces = array();
		foreach ( $tokens as $index => $token ) {
			if ( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id ) {
				$column   = $column_map[ strtolower( $token->get_value() ) ] ?? null;
				$pieces[] = $this->connection->quote_identifier( $column ?? $token->get_value() );
				continue;
			}

			$next_token_starts_call = isset( $tokens[ $index + 1 ] ) && WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $index + 1 ]->id;
			if ( ! $next_token_starts_call && ! $this->is_non_identifier_token( $token ) ) {
				$column = $column_map[ strtolower( $token->get_value() ) ] ?? null;
				if ( null !== $column ) {
					$pieces[] = $this->connection->quote_identifier( $column );
					continue;
				}
			}

			$pieces[] = $this->translate_token_to_duckdb_sql( $token );
		}

		return $this->join_sql_pieces( $pieces );
	}

	/**
	 * Execute SHOW CREATE TABLE.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_show_create_table( array $tokens ): WP_DuckDB_Result_Statement {
		$index      = 3;
		$database   = null;
		$table_name = $this->identifier_value( $tokens[ $index ] ?? null );
		++$index;

		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $index ]->id ) {
			$database = $table_name;
			++$index;
			$table_name = $this->identifier_value( $tokens[ $index ] ?? null );
			++$index;
		}

		if ( count( $tokens ) !== $index ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported SHOW CREATE TABLE statement in DuckDB driver. Use SHOW CREATE TABLE [database.]table.' );
		}

		if ( null !== $database ) {
			if ( 0 === strcasecmp( $database, 'information_schema' ) ) {
				throw new WP_DuckDB_Driver_Exception( sprintf( "SHOW command denied to user 'duckdb'@'%%' for table '%s'", $table_name ) );
			}

			if ( 0 !== strcasecmp( $database, $this->database ) ) {
				return new WP_DuckDB_Result_Statement(
					array( 'Table', 'Create Table' ),
					array()
				);
			}
		}

		$table_reference = $this->resolve_visible_user_table_reference( $table_name );
		if ( null === $table_reference ) {
			return new WP_DuckDB_Result_Statement(
				array( 'Table', 'Create Table' ),
				array()
			);
		}

		return new WP_DuckDB_Result_Statement(
			array( 'Table', 'Create Table' ),
			array(
				array(
					$table_name,
					$this->mysql_create_table_statement( $table_reference['table_name'], $table_name, $table_reference['temporary'] ),
				),
			)
		);
	}

	/**
	 * Build a MySQL-shaped SHOW CREATE TABLE statement from DuckDB metadata.
	 *
	 * @param string   $table_name              Resolved physical table name.
	 * @param string   $requested_table_name    Requested MySQL table name.
	 * @param bool     $temporary               Whether the table is temporary.
	 * @param int|null $auto_increment_override AUTO_INCREMENT value override.
	 * @return string MySQL CREATE TABLE statement.
	 */
	private function mysql_create_table_statement( string $table_name, string $requested_table_name, bool $temporary = false, ?int $auto_increment_override = null ): string {
		$metadata_by_table  = $this->table_metadata_by_table( $temporary );
		$table_metadata     = $metadata_by_table[ $table_name ] ?? $this->fallback_table_metadata( $table_name );
		$table_info         = null === $auto_increment_override
			? $this->information_schema_table_row( $table_name, $table_metadata, $temporary )
			: array(
				'ENGINE'          => $table_metadata['engine'],
				'AUTO_INCREMENT'  => $auto_increment_override,
				'TABLE_COLLATION' => $table_metadata['table_collation'],
				'TABLE_COMMENT'   => $table_metadata['table_comment'],
			);
		$column_rows        = $this->show_create_table_column_rows( $table_name, $temporary );
		$rows               = array();
		$has_auto_increment = false;

		foreach ( $column_rows as $column ) {
			$rows[] = $this->format_show_create_table_column( $column, $has_auto_increment );
		}

		foreach ( $this->show_create_table_index_groups( $table_name, $temporary ) as $index_group ) {
			$rows[] = $this->format_show_create_table_index( $index_group );
		}

		foreach ( $this->show_create_table_foreign_key_groups( $table_name, $temporary ) as $foreign_key ) {
			$rows[] = $this->format_show_create_table_foreign_key_constraint( $foreign_key );
		}

		foreach ( $this->check_constraint_metadata_rows( $table_name, $temporary ) as $check_constraint ) {
			$rows[] = $this->format_show_create_table_check_constraint( $check_constraint );
		}

		$sql  = 'CREATE ' . ( $temporary ? 'TEMPORARY ' : '' ) . 'TABLE ' . $this->quote_mysql_identifier( $requested_table_name ) . " (\n";
		$sql .= implode( ",\n", $rows );
		$sql .= "\n)";
		$sql .= ' ENGINE=' . (string) $table_info['ENGINE'];

		$auto_increment = null === $auto_increment_override ? $table_info['AUTO_INCREMENT'] : $auto_increment_override;
		if ( $has_auto_increment && null !== $auto_increment && (int) $auto_increment > 1 ) {
			$sql .= ' AUTO_INCREMENT=' . (int) $auto_increment;
		}

		$collation = (string) $table_info['TABLE_COLLATION'];
		if ( '' === $collation ) {
			$collation = 'utf8mb4_0900_ai_ci';
		}
		$charset = $this->character_set_from_collation( $collation ) ?? 'utf8mb4';
		$sql    .= ' DEFAULT CHARSET=' . $charset;
		$sql    .= ' COLLATE=' . $collation;

		if ( '' !== $table_info['TABLE_COMMENT'] ) {
			$sql .= ' COMMENT=' . $this->quote_mysql_utf8_string_literal( (string) $table_info['TABLE_COMMENT'] );
		}

		return $sql;
	}

	/**
	 * Build grouped FOREIGN KEY metadata for SHOW CREATE TABLE.
	 *
	 * @param string $table_name Table name.
	 * @param bool   $temporary  Whether to use session-local temporary metadata.
	 * @return array<int,array{constraint_name:string,columns:string[],referenced_table_name:string,referenced_columns:string[],update_rule:string,delete_rule:string}>
	 */
	private function show_create_table_foreign_key_groups( string $table_name, bool $temporary = false ): array {
		$groups = array();

		foreach ( $this->foreign_key_metadata_rows( $table_name, $temporary ) as $row ) {
			$constraint_name = (string) $row['constraint_name'];
			if ( ! isset( $groups[ $constraint_name ] ) ) {
				$groups[ $constraint_name ] = array(
					'constraint_name'       => $constraint_name,
					'columns'               => array(),
					'referenced_table_name' => (string) $row['referenced_table_name'],
					'referenced_columns'    => array(),
					'update_rule'           => (string) $row['update_rule'],
					'delete_rule'           => (string) $row['delete_rule'],
				);
			}

			$groups[ $constraint_name ]['columns'][]            = (string) $row['column_name'];
			$groups[ $constraint_name ]['referenced_columns'][] = (string) $row['referenced_column_name'];
		}

		return array_values( $groups );
	}

	/**
	 * Format one SHOW CREATE TABLE FOREIGN KEY constraint.
	 *
	 * @param array<string,mixed> $foreign_key FOREIGN KEY metadata group.
	 * @return string MySQL FOREIGN KEY constraint definition.
	 */
	private function format_show_create_table_foreign_key_constraint( array $foreign_key ): string {
		$columns = array_map(
			function ( string $column_name ): string {
				return $this->quote_mysql_identifier( $column_name );
			},
			$foreign_key['columns']
		);

		$referenced_columns = array_map(
			function ( string $column_name ): string {
				return $this->quote_mysql_identifier( $column_name );
			},
			$foreign_key['referenced_columns']
		);

		$sql  = '  CONSTRAINT ';
		$sql .= $this->quote_mysql_identifier( (string) $foreign_key['constraint_name'] );
		$sql .= ' FOREIGN KEY (' . implode( ', ', $columns ) . ')';
		$sql .= ' REFERENCES ' . $this->quote_mysql_identifier( (string) $foreign_key['referenced_table_name'] );
		$sql .= ' (' . implode( ', ', $referenced_columns ) . ')';

		if ( 'NO ACTION' !== $foreign_key['delete_rule'] ) {
			$sql .= ' ON DELETE ' . (string) $foreign_key['delete_rule'];
		}
		if ( 'NO ACTION' !== $foreign_key['update_rule'] ) {
			$sql .= ' ON UPDATE ' . (string) $foreign_key['update_rule'];
		}

		return $sql;
	}

	/**
	 * Format one SHOW CREATE TABLE CHECK constraint.
	 *
	 * @param array<string,mixed> $check_constraint CHECK metadata row.
	 * @return string MySQL CHECK constraint definition.
	 */
	private function format_show_create_table_check_constraint( array $check_constraint ): string {
		$sql  = '  CONSTRAINT ';
		$sql .= $this->quote_mysql_identifier( (string) $check_constraint['constraint_name'] );
		$sql .= ' CHECK (' . (string) $check_constraint['check_clause'] . ')';

		return $sql;
	}

	/**
	 * Execute SHOW TABLE STATUS.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_show_table_status( array $tokens ): WP_DuckDB_Result_Statement {
		$index    = 3;
		$database = $this->database;

		if (
			isset( $tokens[ $index ] )
			&& ( WP_MySQL_Lexer::FROM_SYMBOL === $tokens[ $index ]->id || WP_MySQL_Lexer::IN_SYMBOL === $tokens[ $index ]->id )
		) {
			++$index;
			$database = $this->identifier_value( $tokens[ $index ] ?? null );
			++$index;
		}

		$condition = '';
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::LIKE_SYMBOL === $tokens[ $index ]->id ) {
			if (
				! isset( $tokens[ $index + 1 ] )
				|| (
					WP_MySQL_Lexer::SINGLE_QUOTED_TEXT !== $tokens[ $index + 1 ]->id
					&& WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT !== $tokens[ $index + 1 ]->id
				)
			) {
				throw new WP_DuckDB_Driver_Exception( 'SHOW TABLE STATUS LIKE requires a string pattern in the DuckDB driver.' );
			}
			$condition = ' AND ' . $this->connection->quote_identifier( 'Name' )
				. ' LIKE '
				. $this->connection->quote( $tokens[ $index + 1 ]->get_value() )
				. ' ESCAPE '
				. $this->connection->quote( '\\' );
			$index    += 2;
		} elseif ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::WHERE_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
			if ( ! isset( $tokens[ $index ] ) ) {
				throw new WP_DuckDB_Driver_Exception( 'SHOW TABLE STATUS WHERE requires an expression in the DuckDB driver.' );
			}
			$condition = ' AND ' . $this->translate_tokens_to_duckdb_sql( array_slice( $tokens, $index ) );
			$index     = count( $tokens );
		}

		if ( count( $tokens ) !== $index ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported SHOW TABLE STATUS statement in DuckDB driver. Only optional FROM/IN, LIKE, and WHERE are supported.' );
		}

		$this->refresh_information_schema_tables_table();

		$schema_condition = 0 === strcasecmp( $database, $this->database )
			? $this->connection->quote_identifier( 'TABLE_SCHEMA' ) . ' = ' . $this->connection->quote( $this->database )
			: '1 = 0';

		$sql = 'SELECT * FROM ('
			. 'SELECT '
			. $this->connection->quote_identifier( 'TABLE_NAME' ) . ' AS ' . $this->connection->quote_identifier( 'Name' ) . ', '
			. $this->connection->quote_identifier( 'ENGINE' ) . ' AS ' . $this->connection->quote_identifier( 'Engine' ) . ', '
			. $this->connection->quote_identifier( 'VERSION' ) . ' AS ' . $this->connection->quote_identifier( 'Version' ) . ', '
			. $this->connection->quote_identifier( 'ROW_FORMAT' ) . ' AS ' . $this->connection->quote_identifier( 'Row_format' ) . ', '
			. $this->connection->quote_identifier( 'TABLE_ROWS' ) . ' AS ' . $this->connection->quote_identifier( 'Rows' ) . ', '
			. $this->connection->quote_identifier( 'AVG_ROW_LENGTH' ) . ' AS ' . $this->connection->quote_identifier( 'Avg_row_length' ) . ', '
			. $this->connection->quote_identifier( 'DATA_LENGTH' ) . ' AS ' . $this->connection->quote_identifier( 'Data_length' ) . ', '
			. $this->connection->quote_identifier( 'MAX_DATA_LENGTH' ) . ' AS ' . $this->connection->quote_identifier( 'Max_data_length' ) . ', '
			. $this->connection->quote_identifier( 'INDEX_LENGTH' ) . ' AS ' . $this->connection->quote_identifier( 'Index_length' ) . ', '
			. $this->connection->quote_identifier( 'DATA_FREE' ) . ' AS ' . $this->connection->quote_identifier( 'Data_free' ) . ', '
			. $this->connection->quote_identifier( 'AUTO_INCREMENT' ) . ' AS ' . $this->connection->quote_identifier( 'Auto_increment' ) . ', '
			. $this->connection->quote_identifier( 'CREATE_TIME' ) . ' AS ' . $this->connection->quote_identifier( 'Create_time' ) . ', '
			. $this->connection->quote_identifier( 'UPDATE_TIME' ) . ' AS ' . $this->connection->quote_identifier( 'Update_time' ) . ', '
			. $this->connection->quote_identifier( 'CHECK_TIME' ) . ' AS ' . $this->connection->quote_identifier( 'Check_time' ) . ', '
			. $this->connection->quote_identifier( 'TABLE_COLLATION' ) . ' AS ' . $this->connection->quote_identifier( 'Collation' ) . ', '
			. $this->connection->quote_identifier( 'CHECKSUM' ) . ' AS ' . $this->connection->quote_identifier( 'Checksum' ) . ', '
			. $this->connection->quote_identifier( 'CREATE_OPTIONS' ) . ' AS ' . $this->connection->quote_identifier( 'Create_options' ) . ', '
			. $this->connection->quote_identifier( 'TABLE_COMMENT' ) . ' AS ' . $this->connection->quote_identifier( 'Comment' )
			. ' FROM '
			. $this->connection->quote_identifier( self::INFO_SCHEMA_TABLES_TABLE )
			. ' WHERE '
			. $schema_condition
			. ') WHERE 1 = 1'
			. $condition
			. ' ORDER BY '
			. $this->connection->quote_identifier( 'Name' );

		return $this->execute_duckdb_query(
			$sql,
			'Failed to execute SHOW TABLE STATUS'
		);
	}

	/**
	 * Execute SHOW [FULL] COLUMNS FROM table.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_show_columns( array $tokens ): WP_DuckDB_Result_Statement {
		$index = 1;
		$full  = false;

		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::FULL_SYMBOL === $tokens[ $index ]->id ) {
			$full = true;
			++$index;
		}

		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::COLUMNS_SYMBOL, 'Expected COLUMNS in SHOW COLUMNS statement.' );
		++$index;

		if (
			! isset( $tokens[ $index ] )
			|| ( WP_MySQL_Lexer::FROM_SYMBOL !== $tokens[ $index ]->id && WP_MySQL_Lexer::IN_SYMBOL !== $tokens[ $index ]->id )
		) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported SHOW COLUMNS statement in DuckDB driver. Use SHOW COLUMNS FROM table.' );
		}
		++$index;

		$table_name = $this->identifier_value( $tokens[ $index ] ?? null );
		++$index;

		$like_pattern = null;
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::LIKE_SYMBOL === $tokens[ $index ]->id ) {
			if (
				! isset( $tokens[ $index + 1 ] )
				|| (
					WP_MySQL_Lexer::SINGLE_QUOTED_TEXT !== $tokens[ $index + 1 ]->id
					&& WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT !== $tokens[ $index + 1 ]->id
				)
			) {
				throw new WP_DuckDB_Driver_Exception( 'SHOW COLUMNS LIKE requires a string pattern in the DuckDB driver.' );
			}
			$like_pattern = $tokens[ $index + 1 ]->get_value();
			$index       += 2;
		}

		if ( count( $tokens ) !== $index ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported SHOW COLUMNS statement in DuckDB driver. Only optional LIKE is supported.' );
		}

		$table_reference = $this->resolve_visible_user_table_reference( $table_name );
		if ( null === $table_reference ) {
			throw new WP_DuckDB_Driver_Exception( 'DuckDB table does not exist: ' . $table_name . '.' );
		}

		if ( ! $full ) {
			$rows = $this->describe_column_rows( $table_reference['table_name'], $table_reference['temporary'] );
			if ( null !== $like_pattern ) {
				$rows = $this->filter_column_rows_by_like( $rows, $like_pattern );
			}

			return new WP_DuckDB_Result_Statement(
				array( 'Field', 'Type', 'Null', 'Key', 'Default', 'Extra' ),
				$rows
			);
		}

		$full_rows = $this->full_describe_column_rows( $table_reference['table_name'], $table_reference['temporary'] );
		if ( null !== $like_pattern ) {
			$full_rows = $this->filter_column_rows_by_like( $full_rows, $like_pattern );
		}

		return new WP_DuckDB_Result_Statement(
			array( 'Field', 'Type', 'Collation', 'Null', 'Key', 'Default', 'Extra', 'Privileges', 'Comment' ),
			$full_rows
		);
	}

	/**
	 * Execute SHOW [FULL] TABLES.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_show_tables( array $tokens ): WP_DuckDB_Result_Statement {
		$index = 1;
		$full  = false;

		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::FULL_SYMBOL === $tokens[ $index ]->id ) {
			$full = true;
			++$index;
		}

		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::TABLES_SYMBOL, 'Expected TABLES in SHOW TABLES statement.' );
		++$index;

		$database = $this->database;
		if (
			isset( $tokens[ $index ] )
			&& ( WP_MySQL_Lexer::FROM_SYMBOL === $tokens[ $index ]->id || WP_MySQL_Lexer::IN_SYMBOL === $tokens[ $index ]->id )
		) {
			++$index;
			$database = $this->identifier_value( $tokens[ $index ] ?? null );
			++$index;
		}

		$like_pattern = null;
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::LIKE_SYMBOL === $tokens[ $index ]->id ) {
			if (
				! isset( $tokens[ $index + 1 ] )
				|| (
					WP_MySQL_Lexer::SINGLE_QUOTED_TEXT !== $tokens[ $index + 1 ]->id
					&& WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT !== $tokens[ $index + 1 ]->id
				)
			) {
				throw new WP_DuckDB_Driver_Exception( 'SHOW TABLES LIKE requires a string pattern in the DuckDB driver.' );
			}
			$like_pattern = $tokens[ $index + 1 ]->get_value();
			$index       += 2;
		}

		if ( count( $tokens ) !== $index ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported SHOW TABLES statement in DuckDB driver. Only optional FULL, FROM/IN, and LIKE are supported.' );
		}

		$columns = array( 'Tables_in_' . $database );
		if ( $full ) {
			$columns[] = 'Table_type';
		}

		$rows = array();
		if ( 0 === strcasecmp( $database, $this->database ) ) {
			foreach ( $this->user_table_names() as $table_name ) {
				if ( null !== $like_pattern && ! $this->mysql_like_matches( $table_name, $like_pattern ) ) {
					continue;
				}

				$row = array( $table_name );
				if ( $full ) {
					$row[] = 'BASE TABLE';
				}
				$rows[] = $row;
			}
		}

		return new WP_DuckDB_Result_Statement( $columns, $rows );
	}

	/**
	 * Execute SHOW INDEX FROM table.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_show_index( array $tokens ): WP_DuckDB_Result_Statement {
		if (
			4 !== count( $tokens )
			|| ( WP_MySQL_Lexer::FROM_SYMBOL !== $tokens[2]->id && WP_MySQL_Lexer::IN_SYMBOL !== $tokens[2]->id )
		) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported SHOW INDEX statement in DuckDB driver. Use SHOW INDEX FROM table.' );
		}

		$table_name      = $this->identifier_value( $tokens[3] );
		$table_reference = $this->resolve_visible_user_table_reference( $table_name );
		$rows            = null === $table_reference ? array() : $this->index_rows_for_table( $table_reference['table_name'], $table_reference['temporary'] );

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
			$rows
		);
	}

	/**
	 * Build SHOW INDEX-compatible rows for all indexes on a table.
	 *
	 * @param string $table_name Table name.
	 * @return array<int,array<int,mixed>>
	 */
	private function index_rows_for_table( string $table_name, bool $temporary = false ): array {
		return array_merge(
			$this->primary_key_index_rows( $table_name ),
			$this->secondary_index_rows( $table_name, $temporary )
		);
	}

	/**
	 * Build information_schema-shaped column rows for SHOW CREATE TABLE.
	 *
	 * @param string $table_name Table name.
	 * @return array<int,array<string,mixed>>
	 */
	private function show_create_table_column_rows( string $table_name, bool $temporary = false ): array {
		$metadata_rows = $this->column_metadata_rows( $table_name, $temporary );
		if ( count( $metadata_rows ) === 0 ) {
			$metadata_rows = $this->pragma_column_metadata_rows( $table_name );
		}

		return array_map(
			function ( array $metadata ) use ( $table_name ): array {
				return $this->information_schema_column_row( $table_name, $metadata );
			},
			$metadata_rows
		);
	}

	/**
	 * Format one SHOW CREATE TABLE column definition.
	 *
	 * @param array<string,mixed> $column             information_schema.columns row.
	 * @param bool                $has_auto_increment Whether an AUTO_INCREMENT column has been seen.
	 * @return string MySQL column definition.
	 */
	private function format_show_create_table_column( array $column, bool &$has_auto_increment ): string {
		$extra              = (string) $column['EXTRA'];
		$is_auto_increment  = false !== stripos( $extra, 'auto_increment' );
		$has_auto_increment = $has_auto_increment || $is_auto_increment;

		$sql  = '  ' . $this->quote_mysql_identifier( (string) $column['COLUMN_NAME'] );
		$sql .= ' ' . (string) $column['COLUMN_TYPE'];

		if ( 'NO' === $column['IS_NULLABLE'] ) {
			$sql .= ' NOT NULL';
		} elseif ( 'timestamp' === $column['COLUMN_TYPE'] ) {
			$sql .= ' NULL';
		}

		if ( $is_auto_increment ) {
			$sql .= ' AUTO_INCREMENT';
		} elseif (
			'CURRENT_TIMESTAMP' === $column['COLUMN_DEFAULT']
			&& in_array( $column['DATA_TYPE'], array( 'timestamp', 'datetime' ), true )
		) {
			$sql .= ' DEFAULT CURRENT_TIMESTAMP';
		} elseif ( null !== $column['COLUMN_DEFAULT'] ) {
			if ( false !== strpos( $extra, 'DEFAULT_GENERATED' ) ) {
				$sql .= ' DEFAULT (' . $column['COLUMN_DEFAULT'] . ')';
			} else {
				$sql .= ' DEFAULT ' . $this->format_show_create_table_default( $column );
			}
		} elseif ( 'YES' === $column['IS_NULLABLE'] ) {
			$sql .= ' DEFAULT NULL';
		}

		if ( false !== strpos( $extra, 'on update CURRENT_TIMESTAMP' ) ) {
			$sql .= ' ON UPDATE CURRENT_TIMESTAMP';
		}

		if ( '' !== $column['COLUMN_COMMENT'] ) {
			$sql .= ' COMMENT ' . $this->quote_mysql_utf8_string_literal( (string) $column['COLUMN_COMMENT'] );
		}

		return $sql;
	}

	/**
	 * Format a column default for SHOW CREATE TABLE.
	 *
	 * @param array<string,mixed> $column information_schema.columns row.
	 * @return string MySQL literal.
	 */
	private function format_show_create_table_default( array $column ): string {
		if ( 'bit' === $column['DATA_TYPE'] ) {
			return (string) $column['COLUMN_DEFAULT'];
		}

		return $this->quote_mysql_utf8_string_literal( (string) $column['COLUMN_DEFAULT'] );
	}

	/**
	 * Build grouped index metadata for SHOW CREATE TABLE.
	 *
	 * @param string $table_name Table name.
	 * @return array<int,array{name:string,non_unique:int,index_type:string,index_comment:string,columns:array<int,array{name:string,sub_part:int|null,collation:string}>}>
	 */
	private function show_create_table_index_groups( string $table_name, bool $temporary = false ): array {
		$groups = array();

		foreach ( $this->index_rows_for_table( $table_name, $temporary ) as $row ) {
			$index_name = (string) $row[2];
			if ( ! isset( $groups[ $index_name ] ) ) {
				$groups[ $index_name ] = array(
					'name'          => $index_name,
					'non_unique'    => (int) $row[1],
					'index_type'    => (string) $row[10],
					'index_comment' => (string) $row[12],
					'columns'       => array(),
				);
			}

			$groups[ $index_name ]['columns'][] = array(
				'name'      => (string) $row[4],
				'sub_part'  => null === $row[7] ? null : (int) $row[7],
				'collation' => (string) $row[5],
			);
		}

		$groups = array_values( $groups );
		usort(
			$groups,
			function ( array $left, array $right ): int {
				if ( 'PRIMARY' === $left['name'] ) {
					return 'PRIMARY' === $right['name'] ? 0 : -1;
				}
				if ( 'PRIMARY' === $right['name'] ) {
					return 1;
				}
				if ( $left['non_unique'] !== $right['non_unique'] ) {
					return $left['non_unique'] <=> $right['non_unique'];
				}
				return strcmp( $left['name'], $right['name'] );
			}
		);

		return $groups;
	}

	/**
	 * Format one SHOW CREATE TABLE index definition.
	 *
	 * @param array{name:string,non_unique:int,index_type:string,index_comment:string,columns:array<int,array{name:string,sub_part:int|null,collation:string}>} $index_group Grouped index metadata.
	 * @return string MySQL index definition.
	 */
	private function format_show_create_table_index( array $index_group ): string {
		$columns = array_map(
			function ( array $column ): string {
				$sql = $this->quote_mysql_identifier( $column['name'] );
				if ( null !== $column['sub_part'] ) {
					$sql .= '(' . (int) $column['sub_part'] . ')';
				}
				if ( 'D' === $column['collation'] ) {
					$sql .= ' DESC';
				}
				return $sql;
			},
			$index_group['columns']
		);

		if ( 'PRIMARY' === $index_group['name'] ) {
			$sql = '  PRIMARY KEY (' . implode( ', ', $columns ) . ')';
		} else {
			$sql  = '  ' . ( 0 === $index_group['non_unique'] ? 'UNIQUE KEY ' : 'KEY ' );
			$sql .= $this->quote_mysql_identifier( $index_group['name'] );
			$sql .= ' (' . implode( ', ', $columns ) . ')';
		}

		if ( '' !== $index_group['index_comment'] ) {
			$sql .= ' COMMENT ' . $this->quote_mysql_utf8_string_literal( $index_group['index_comment'] );
		}

		return $sql;
	}

	/**
	 * Build SHOW INDEX rows for the primary key.
	 *
	 * @param string $table_name Table name.
	 * @return array<int,array<int,mixed>>
	 */
	private function primary_key_index_rows( string $table_name ): array {
		$pragma = $this->execute_duckdb_query(
			'SELECT name FROM pragma_table_info(' . $this->connection->quote( $table_name ) . ') WHERE pk > 0 ORDER BY pk, cid',
			'Failed to inspect DuckDB primary key'
		);

		$rows = array();
		foreach ( $pragma->fetchAll( PDO::FETCH_ASSOC ) as $offset => $row ) {
			$rows[] = $this->show_index_row(
				$table_name,
				0,
				'PRIMARY',
				$offset + 1,
				(string) $row['name'],
				null
			);
		}

		return $rows;
	}

	/**
	 * Build SHOW INDEX rows for secondary indexes.
	 *
	 * @param string $table_name Table name.
	 * @return array<int,array<int,mixed>>
	 */
	private function secondary_index_rows( string $table_name, bool $temporary = false ): array {
		$this->ensure_index_metadata_table( $temporary );

		$stmt = $this->execute_duckdb_query(
			'SELECT index_name, non_unique, seq_in_index, column_name, sub_part FROM '
				. $this->connection->quote_identifier( $this->index_metadata_table_name( $temporary ) )
				. ' WHERE table_name = '
				. $this->connection->quote( $table_name )
				. ' ORDER BY index_name, seq_in_index',
			'Failed to inspect DuckDB secondary indexes'
		);

		$rows = array();
		foreach ( $stmt->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
			$rows[] = $this->show_index_row(
				$table_name,
				(int) $row['non_unique'],
				(string) $row['index_name'],
				(int) $row['seq_in_index'],
				(string) $row['column_name'],
				null === $row['sub_part'] ? null : (int) $row['sub_part']
			);
		}

		return $rows;
	}

	/**
	 * Build one MySQL-compatible SHOW INDEX row.
	 *
	 * @param string   $table_name   Table name.
	 * @param int      $non_unique   Non-unique flag.
	 * @param string   $key_name     Key name.
	 * @param int      $seq_in_index Sequence in index.
	 * @param string   $column_name  Column name.
	 * @param int|null $sub_part     Optional prefix length.
	 * @return array<int,mixed>
	 */
	private function show_index_row( string $table_name, int $non_unique, string $key_name, int $seq_in_index, string $column_name, ?int $sub_part ): array {
		return array(
			$table_name,
			$non_unique,
			$key_name,
			$seq_in_index,
			$column_name,
			'A',
			null,
			$sub_part,
			null,
			'',
			'BTREE',
			'',
			'',
			'YES',
			null,
		);
	}

	/**
	 * Execute DESCRIBE table.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_describe( array $tokens ): WP_DuckDB_Result_Statement {
		if ( 2 !== count( $tokens ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported DESCRIBE statement in DuckDB driver. Only DESCRIBE table is supported.' );
		}

		return new WP_DuckDB_Result_Statement(
			array( 'Field', 'Type', 'Null', 'Key', 'Default', 'Extra' ),
			$this->describe_column_rows_for_request( $this->identifier_value( $tokens[1] ) )
		);
	}

	/**
	 * Build DESCRIBE rows for a requested table name, resolving temporary tables first.
	 *
	 * @param string $table_name Requested table name.
	 * @return array<int,array<int,mixed>>
	 */
	private function describe_column_rows_for_request( string $table_name ): array {
		$table_reference = $this->resolve_visible_user_table_reference( $table_name );
		if ( null === $table_reference ) {
			throw new WP_DuckDB_Driver_Exception( 'DuckDB table does not exist: ' . $table_name . '.' );
		}

		return $this->describe_column_rows( $table_reference['table_name'], $table_reference['temporary'] );
	}

	/**
	 * Build MySQL DESCRIBE/SHOW COLUMNS rows from DuckDB table metadata.
	 *
	 * @param string $table_name Table name.
	 * @return array<int,array<int,mixed>>
	 */
	private function describe_column_rows( string $table_name, bool $temporary = false ): array {
		$metadata_rows = $this->column_metadata_rows( $table_name, $temporary );
		if ( count( $metadata_rows ) > 0 ) {
			return array_map(
				function ( array $row ): array {
					return array(
						$row['column_name'],
						$row['column_type'],
						$row['is_nullable'],
						$row['column_key'],
						$row['column_default'],
						$row['extra'],
					);
				},
				$metadata_rows
			);
		}

		$pragma = $this->execute_duckdb_query(
			'SELECT name, type, "notnull", dflt_value, pk FROM pragma_table_info(' . $this->connection->quote( $table_name ) . ') ORDER BY cid',
			'Failed to inspect DuckDB table'
		);
		$rows   = $pragma->fetchAll( PDO::FETCH_ASSOC );

		if ( count( $rows ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( 'DuckDB table does not exist: ' . $table_name . '.' );
		}

		$describe_rows = array();
		foreach ( $rows as $row ) {
			$is_primary_key = isset( $row['pk'] ) && (int) $row['pk'] > 0;
			$is_not_null    = $is_primary_key || ( isset( $row['notnull'] ) && (bool) $row['notnull'] );
			$is_auto        = isset( $row['dflt_value'] ) && is_string( $row['dflt_value'] ) && false !== stripos( $row['dflt_value'], 'nextval(' );

			$describe_rows[] = array(
				$row['name'],
				$row['type'],
				$is_not_null ? 'NO' : 'YES',
				$is_primary_key ? 'PRI' : '',
				$is_auto ? null : $this->normalize_describe_default( $row['dflt_value'] ?? null ),
				$is_auto ? 'auto_increment' : '',
			);
		}

		return $describe_rows;
	}

	/**
	 * Build MySQL SHOW FULL COLUMNS rows.
	 *
	 * @param string $table_name Table name.
	 * @return array<int,array<int,mixed>>
	 */
	private function full_describe_column_rows( string $table_name, bool $temporary = false ): array {
		$metadata_rows = $this->column_metadata_rows( $table_name, $temporary );
		if ( count( $metadata_rows ) > 0 ) {
			return array_map(
				function ( array $row ): array {
					return array(
						$row['column_name'],
						$row['column_type'],
						$row['collation_name'],
						$row['is_nullable'],
						$row['column_key'],
						$row['column_default'],
						$row['extra'],
						'select,insert,update,references',
						$row['comment'],
					);
				},
				$metadata_rows
			);
		}

		$full_rows = array();
		foreach ( $this->describe_column_rows( $table_name, $temporary ) as $row ) {
			$full_rows[] = array(
				$row[0],
				$row[1],
				null,
				$row[2],
				$row[3],
				$row[4],
				$row[5],
				'select,insert,update,references',
				'',
			);
		}

		return $full_rows;
	}

	/**
	 * Filter DESCRIBE-style rows using a MySQL LIKE pattern against Field.
	 *
	 * @param array<int,array<int,mixed>> $rows    DESCRIBE-style rows.
	 * @param string                      $pattern LIKE pattern.
	 * @return array<int,array<int,mixed>>
	 */
	private function filter_column_rows_by_like( array $rows, string $pattern ): array {
		$filtered = array();
		foreach ( $rows as $row ) {
			if ( $this->mysql_like_matches( (string) $row[0], $pattern ) ) {
				$filtered[] = $row;
			}
		}
		return $filtered;
	}

	/**
	 * Match a string with MySQL LIKE wildcards.
	 *
	 * @param string $value   Candidate value.
	 * @param string $pattern LIKE pattern.
	 * @return bool
	 */
	private function mysql_like_matches( string $value, string $pattern ): bool {
		$regex    = '';
		$escaping = false;
		$length   = strlen( $pattern );

		for ( $index = 0; $index < $length; ++$index ) {
			$char = $pattern[ $index ];

			if ( $escaping ) {
				$regex   .= preg_quote( $char, '/' );
				$escaping = false;
				continue;
			}

			if ( '\\' === $char ) {
				$escaping = true;
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

		if ( $escaping ) {
			$regex .= preg_quote( '\\', '/' );
		}

		return 1 === preg_match( '/\A' . $regex . '\z/s', $value );
	}

	/**
	 * Collect comma-separated items inside a CREATE TABLE column list.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Index after the opening parenthesis.
	 * @return array{0: array<int,array<int,WP_Parser_Token>>, 1: int}
	 */
	private function collect_parenthesized_items( array $tokens, int $index ): array {
		$items   = array();
		$current = array();
		$depth   = 1;

		for ( ; $index < count( $tokens ); ++$index ) {
			$token = $tokens[ $index ];
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $token->id ) {
				++$depth;
				$current[] = $token;
				continue;
			}
			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $token->id ) {
				--$depth;
				if ( 0 === $depth ) {
					$items[] = $current;
					return array( $items, $index + 1 );
				}
				$current[] = $token;
				continue;
			}
			if ( 1 === $depth && WP_MySQL_Lexer::COMMA_SYMBOL === $token->id ) {
				$items[] = $current;
				$current = array();
				continue;
			}
			$current[] = $token;
		}

		throw new WP_DuckDB_Driver_Exception( 'Unterminated CREATE TABLE column list.' );
	}

	/**
	 * Split a token stream on top-level commas.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @return array<int,array<int,WP_Parser_Token>>
	 */
	private function split_top_level_comma_items( array $tokens ): array {
		$items   = array();
		$current = array();
		$depth   = 0;

		foreach ( $tokens as $token ) {
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $token->id ) {
				++$depth;
				$current[] = $token;
				continue;
			}
			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $token->id ) {
				--$depth;
				if ( $depth < 0 ) {
					throw new WP_DuckDB_Driver_Exception( 'Unbalanced parentheses in DuckDB driver statement.' );
				}
				$current[] = $token;
				continue;
			}
			if ( 0 === $depth && WP_MySQL_Lexer::COMMA_SYMBOL === $token->id ) {
				if ( count( $current ) === 0 ) {
					throw new WP_DuckDB_Driver_Exception( 'Empty comma-separated item in DuckDB driver statement.' );
				}
				$items[] = $current;
				$current = array();
				continue;
			}
			$current[] = $token;
		}

		if ( 0 !== $depth ) {
			throw new WP_DuckDB_Driver_Exception( 'Unbalanced parentheses in DuckDB driver statement.' );
		}
		if ( count( $current ) > 0 ) {
			$items[] = $current;
		}

		return $items;
	}

	/**
	 * Translate a column definition.
	 *
	 * @param string            $table_name                 Table name.
	 * @param WP_Parser_Token[] $tokens                     Column definition tokens.
	 * @param bool              $include_inline_constraints Whether to include inline NOT NULL/PRIMARY KEY SQL.
	 * @param bool              $allow_position_options     Whether to accept FIRST/AFTER position hints.
	 * @param bool              $temporary                  Whether the target is a temporary table.
	 * @param int|null          $auto_increment_seed        Optional AUTO_INCREMENT table option.
	 * @param string|null       $default_collation_name     Effective table collation for text columns without an explicit collation.
	 * @param array<string,bool>|null $check_names       Existing MySQL-facing CHECK names, keyed lowercase.
	 * @param array<string,bool>|null $foreign_key_names Existing FOREIGN KEY names, keyed lowercase.
	 * @return array{0:string,1:string|null,2:array<int,array{sql:string,table_name:string,index_name:string,unique:bool,columns:array<int,array{name:string,sub_part:int|null}>}>,3:array<string,mixed>,4:array<int,string>,5:array<int,array{constraint_name:string,check_clause:string,enforced:string}>,6:array<int,array{constraint_name:string,columns:string[],referenced_table_name:string,referenced_columns:string[],update_rule:string,delete_rule:string}>}
	 */
	private function translate_create_table_column( string $table_name, array $tokens, bool $include_inline_constraints = true, bool $allow_position_options = false, bool $temporary = false, ?int $auto_increment_seed = null, ?string $default_collation_name = null, ?array &$check_names = null, ?array &$foreign_key_names = null ): array {
		$index       = 0;
		$column_name = $this->identifier_value( $tokens[ $index ] ?? null );
		++$index;

		if ( ! isset( $tokens[ $index ] ) || ! isset( self::DATA_TYPE_MAP[ $tokens[ $index ]->id ] ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported MySQL column type for DuckDB column: ' . $column_name . '.' );
		}

		$type_index = $index;
		$type_token = $tokens[ $index ];
		$duck_type  = self::DATA_TYPE_MAP[ $type_token->id ];
		++$index;
		$index = $this->skip_type_modifiers( $tokens, $index );

		$not_null       = false;
		$primary_key    = false;
		$unique_key     = false;
		$auto_increment = false;
		$default_sql    = null;
		$constraints    = array();
		$checks         = array();
		$foreign_keys   = array();

		if ( null === $check_names ) {
			$check_names = array();
		}
		if ( null === $foreign_key_names ) {
			$foreign_key_names = array();
		}

		while ( $index < count( $tokens ) ) {
			$token = $tokens[ $index ];
			switch ( $token->id ) {
				case WP_MySQL_Lexer::NOT_SYMBOL:
					$this->expect_token( $tokens, $index + 1, WP_MySQL_Lexer::NULL_SYMBOL, 'Expected NULL after NOT in column definition.' );
					$not_null = true;
					$index   += 2;
					break;
				case WP_MySQL_Lexer::NULL_SYMBOL:
				case WP_MySQL_Lexer::NULL2_SYMBOL:
					$not_null = false;
					++$index;
					break;
				case WP_MySQL_Lexer::DEFAULT_SYMBOL:
					++$index;
					$default_sql = $this->translate_default_literal( $tokens, $index );
					break;
				case WP_MySQL_Lexer::PRIMARY_SYMBOL:
					$this->expect_token( $tokens, $index + 1, WP_MySQL_Lexer::KEY_SYMBOL, 'Expected KEY after PRIMARY in column definition.' );
					$primary_key = true;
					$not_null    = true;
					$index      += 2;
					break;
				case WP_MySQL_Lexer::UNIQUE_SYMBOL:
					$unique_key = true;
					++$index;
					if (
						isset( $tokens[ $index ] )
						&& ( WP_MySQL_Lexer::KEY_SYMBOL === $tokens[ $index ]->id || WP_MySQL_Lexer::INDEX_SYMBOL === $tokens[ $index ]->id )
					) {
						++$index;
					}
					break;
				case WP_MySQL_Lexer::AUTO_INCREMENT_SYMBOL:
					$auto_increment = true;
					$not_null       = true;
					++$index;
					break;
				case WP_MySQL_Lexer::COMMENT_SYMBOL:
					$index = $this->skip_option_value( $tokens, $index + 1 );
					break;
				case WP_MySQL_Lexer::COLLATE_SYMBOL:
				case WP_MySQL_Lexer::CHARSET_SYMBOL:
					$index = $this->skip_option_value( $tokens, $index + 1 );
					break;
				case WP_MySQL_Lexer::FIRST_SYMBOL:
					if ( ! $allow_position_options ) {
						throw new WP_DuckDB_Driver_Exception( 'Unsupported column attribute in DuckDB driver: ' . $token->get_bytes() . '.' );
					}
					++$index;
					break;
				case WP_MySQL_Lexer::AFTER_SYMBOL:
					if ( ! $allow_position_options ) {
						throw new WP_DuckDB_Driver_Exception( 'Unsupported column attribute in DuckDB driver: ' . $token->get_bytes() . '.' );
					}
					$this->identifier_value( $tokens[ $index + 1 ] ?? null );
					$index += 2;
					break;
				case WP_MySQL_Lexer::CHAR_SYMBOL:
				case WP_MySQL_Lexer::CHARACTER_SYMBOL:
					if ( ! isset( $tokens[ $index + 1 ] ) || WP_MySQL_Lexer::SET_SYMBOL !== $tokens[ $index + 1 ]->id ) {
						throw new WP_DuckDB_Driver_Exception( 'Unsupported CHARACTER column option in DuckDB driver.' );
					}
					$index = $this->skip_option_value( $tokens, $index + 2 );
					break;
				case WP_MySQL_Lexer::CHECK_SYMBOL:
					if ( ! $include_inline_constraints ) {
						throw new WP_DuckDB_Driver_Exception( 'Unsupported inline CHECK constraint in DuckDB driver. Inline CHECK constraints are only supported in CREATE TABLE.' );
					}
					$check         = $this->translate_inline_check_constraint( $table_name, $tokens, $index, $check_names );
					$constraints[] = $check['sql'];
					$checks[]      = $check['metadata'];
					break;
				case WP_MySQL_Lexer::REFERENCES_SYMBOL:
					if ( ! $include_inline_constraints ) {
						throw new WP_DuckDB_Driver_Exception( 'Unsupported inline REFERENCES constraint in DuckDB driver. Inline REFERENCES constraints are only supported in CREATE TABLE.' );
					}
					$foreign_key    = $this->translate_inline_foreign_key_constraint( $table_name, $column_name, $tokens, $index, $foreign_key_names );
					$constraints[]  = $foreign_key['sql'];
					$foreign_keys[] = $foreign_key['metadata'];
					break;
				default:
					throw new WP_DuckDB_Driver_Exception( 'Unsupported column attribute in DuckDB driver: ' . $token->get_bytes() . '.' );
			}
		}

		if ( $auto_increment && null !== $default_sql ) {
			throw new WP_DuckDB_Driver_Exception( 'AUTO_INCREMENT columns cannot also declare DEFAULT in the DuckDB driver.' );
		}

		if ( $auto_increment && ! $this->is_integer_duckdb_type( $duck_type ) ) {
			throw new WP_DuckDB_Driver_Exception( 'AUTO_INCREMENT requires an integer column in the DuckDB driver.' );
		}

		if ( $auto_increment ) {
			$duck_type = 'BIGINT';
		}

		$metadata_collation_name = $this->mysql_column_collation_from_tokens( $tokens, $type_token );
		$physical_collation_name = $this->mysql_column_collation_from_tokens( $tokens, $type_token, $default_collation_name );
		$column_sql              = $this->connection->quote_identifier( $column_name ) . ' ' . $duck_type;
		$sequence                = null;

		if ( $this->mysql_column_uses_case_insensitive_collation( $type_token, $physical_collation_name ) ) {
			$column_sql .= ' COLLATE NOCASE';
		}

		if ( $auto_increment ) {
			$sequence_name = $this->sequence_name( $table_name, $column_name, $temporary );
			$sequence      = 'CREATE '
				. ( $temporary ? 'TEMP ' : '' )
				. 'SEQUENCE IF NOT EXISTS '
				. $this->connection->quote_identifier( $sequence_name )
				. ' START '
				. ( null !== $auto_increment_seed && $auto_increment_seed > 1 ? $auto_increment_seed - 1 : 1 );
			$column_sql   .= ' DEFAULT nextval(' . $this->connection->quote( $sequence_name ) . ')';
		} elseif ( null !== $default_sql ) {
			$column_sql .= ' DEFAULT ' . $default_sql;
		}

		if ( $not_null && $include_inline_constraints ) {
			$column_sql .= ' NOT NULL';
		}
		if ( $primary_key && $include_inline_constraints ) {
			$column_sql .= ' PRIMARY KEY';
		}

		$indexes = array();
		if ( $unique_key && ! $primary_key ) {
			$indexes[] = $this->build_secondary_index_definition(
				$table_name,
				$column_name,
				true,
				array( $this->connection->quote_identifier( $column_name ) ),
				array(
					array(
						'name'     => $column_name,
						'sub_part' => null,
					),
				),
				$temporary
			);
		}

		$metadata = array(
			'column_name'    => $column_name,
			'column_type'    => $this->mysql_column_type_from_tokens( $tokens, $type_index ),
			'is_nullable'    => $not_null ? 'NO' : 'YES',
			'column_key'     => $primary_key ? 'PRI' : ( $unique_key ? 'UNI' : '' ),
			'column_default' => $auto_increment || null === $default_sql ? null : $this->normalize_describe_default( $default_sql ),
			'extra'          => $auto_increment ? 'auto_increment' : '',
			'collation_name' => $metadata_collation_name,
			'comment'        => $this->mysql_column_comment_from_tokens( $tokens ),
			'_duckdb_type'   => $duck_type,
			'_default_sql'   => $auto_increment ? null : $default_sql,
		);

		return array( $column_sql, $sequence, $indexes, $metadata, $constraints, $checks, $foreign_keys );
	}

	/**
	 * Translate an inline column CHECK constraint into table-level DuckDB SQL and metadata.
	 *
	 * @param string             $table_name  Table name.
	 * @param WP_Parser_Token[]  $tokens      Column definition tokens.
	 * @param int                $index       Current index, advanced past the CHECK clause.
	 * @param array<string,bool> $check_names Existing MySQL-facing CHECK names, keyed lowercase.
	 * @return array{sql:string,metadata:array{constraint_name:string,check_clause:string,enforced:string}}
	 */
	private function translate_inline_check_constraint( string $table_name, array $tokens, int &$index, array &$check_names ): array {
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::CHECK_SYMBOL, 'Expected CHECK constraint.' );
		++$index;
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::OPEN_PAR_SYMBOL, 'Expected CHECK expression.' );

		$expression_end    = $this->skip_balanced_parentheses( $tokens, $index );
		$expression_tokens = array_slice( $tokens, $index + 1, $expression_end - $index - 2 );
		if ( count( $expression_tokens ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( 'CHECK constraint requires an expression in the DuckDB driver.' );
		}

		$index = $expression_end;
		if ( isset( $tokens[ $index ] ) ) {
			if (
				WP_MySQL_Lexer::NOT_SYMBOL === $tokens[ $index ]->id
				&& isset( $tokens[ $index + 1 ] )
				&& WP_MySQL_Lexer::ENFORCED_SYMBOL === $tokens[ $index + 1 ]->id
			) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported CREATE TABLE CHECK constraint in DuckDB driver: NOT ENFORCED is not supported.' );
			}

			if ( WP_MySQL_Lexer::ENFORCED_SYMBOL === $tokens[ $index ]->id ) {
				++$index;
			}
		}

		$constraint_name = $this->generate_check_constraint_name( $table_name, $check_names );
		$this->register_check_constraint_name( $constraint_name, $check_names );

		$duckdb_expression = $this->translate_tokens_to_duckdb_sql( $expression_tokens );
		$mysql_expression  = $this->mysql_check_clause_from_tokens( $expression_tokens );

		return array(
			'sql'      => 'CONSTRAINT '
				. $this->connection->quote_identifier( $constraint_name )
				. ' CHECK ('
				. $duckdb_expression
				. ')',
			'metadata' => array(
				'constraint_name' => $constraint_name,
				'check_clause'    => $mysql_expression,
				'enforced'        => 'YES',
			),
		);
	}

	/**
	 * Translate an inline column REFERENCES constraint into table-level DuckDB SQL and metadata.
	 *
	 * @param string             $table_name        Table name.
	 * @param string             $column_name       Referencing column name.
	 * @param WP_Parser_Token[]  $tokens            Column definition tokens.
	 * @param int                $index             Current index, advanced past the REFERENCES clause.
	 * @param array<string,bool> $foreign_key_names Existing FOREIGN KEY names, keyed lowercase.
	 * @return array{sql:string,metadata:array{constraint_name:string,columns:string[],referenced_table_name:string,referenced_columns:string[],update_rule:string,delete_rule:string}}
	 */
	private function translate_inline_foreign_key_constraint( string $table_name, string $column_name, array $tokens, int &$index, array &$foreign_key_names ): array {
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::REFERENCES_SYMBOL, 'Expected REFERENCES in FOREIGN KEY constraint.' );
		++$index;

		$referenced_table_name = $this->identifier_value( $tokens[ $index ] ?? null );
		++$index;
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $index ]->id ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported CREATE TABLE FOREIGN KEY constraint in DuckDB driver. Schema-qualified references are not supported.' );
		}

		list( $referenced_columns, $index ) = $this->parse_foreign_key_column_list( $tokens, $index, 'FOREIGN KEY referenced column list' );
		if ( 1 !== count( $referenced_columns ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported CREATE TABLE FOREIGN KEY constraint in DuckDB driver. Only single-column foreign keys are supported.' );
		}

		list( $update_rule, $delete_rule, $index ) = $this->parse_foreign_key_actions( $tokens, $index );
		if ( count( $tokens ) !== $index ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported CREATE TABLE FOREIGN KEY constraint in DuckDB driver.' );
		}

		$constraint_name = $this->generate_foreign_key_constraint_name( $table_name, $foreign_key_names );
		$this->register_foreign_key_constraint_name( $constraint_name, $foreign_key_names );

		return array(
			'sql'      => 'CONSTRAINT '
				. $this->connection->quote_identifier( $constraint_name )
				. ' FOREIGN KEY ('
				. $this->connection->quote_identifier( $column_name )
				. ') REFERENCES '
				. $this->connection->quote_identifier( $referenced_table_name )
				. ' ('
				. $this->connection->quote_identifier( $referenced_columns[0] )
				. ')',
			'metadata' => array(
				'constraint_name'       => $constraint_name,
				'columns'               => array( $column_name ),
				'referenced_table_name' => $referenced_table_name,
				'referenced_columns'    => $referenced_columns,
				'update_rule'           => $update_rule,
				'delete_rule'           => $delete_rule,
			),
		);
	}

	/**
	 * Skip MySQL type display widths and modifiers.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Current index.
	 * @return int New index.
	 */
	private function skip_type_modifiers( array $tokens, int $index ): int {
		while ( $index < count( $tokens ) ) {
			$token = $tokens[ $index ];
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $token->id ) {
				$index = $this->skip_balanced_parentheses( $tokens, $index );
				continue;
			}
			if ( WP_MySQL_Lexer::UNSIGNED_SYMBOL === $token->id || WP_MySQL_Lexer::ZEROFILL_SYMBOL === $token->id ) {
				++$index;
				continue;
			}
			if ( WP_MySQL_Lexer::CHARSET_SYMBOL === $token->id || WP_MySQL_Lexer::COLLATE_SYMBOL === $token->id ) {
				$index = $this->skip_option_value( $tokens, $index + 1 );
				continue;
			}
			if ( WP_MySQL_Lexer::CHAR_SYMBOL === $token->id || WP_MySQL_Lexer::CHARACTER_SYMBOL === $token->id ) {
				if ( ! isset( $tokens[ $index + 1 ] ) || WP_MySQL_Lexer::SET_SYMBOL !== $tokens[ $index + 1 ]->id ) {
					return $index;
				}
				$index = $this->skip_option_value( $tokens, $index + 2 );
				continue;
			}
			return $index;
		}
		return $index;
	}

	/**
	 * Build the MySQL-facing column type string for DESCRIBE/SHOW COLUMNS.
	 *
	 * @param WP_Parser_Token[] $tokens     Column definition tokens.
	 * @param int               $type_index Index of the type token.
	 * @return string MySQL column type.
	 */
	private function mysql_column_type_from_tokens( array $tokens, int $type_index ): string {
		$pieces = array( $tokens[ $type_index ]->get_bytes() );
		$index  = $type_index + 1;

		while ( $index < count( $tokens ) ) {
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $index ]->id ) {
				$end = $this->skip_balanced_parentheses( $tokens, $index );
				for ( ; $index < $end; ++$index ) {
					$pieces[] = $tokens[ $index ]->get_bytes();
				}
				continue;
			}

			if ( WP_MySQL_Lexer::UNSIGNED_SYMBOL === $tokens[ $index ]->id || WP_MySQL_Lexer::ZEROFILL_SYMBOL === $tokens[ $index ]->id ) {
				$pieces[] = $tokens[ $index ]->get_bytes();
				++$index;
				continue;
			}

			if ( WP_MySQL_Lexer::CHARSET_SYMBOL === $tokens[ $index ]->id || WP_MySQL_Lexer::COLLATE_SYMBOL === $tokens[ $index ]->id ) {
				$index = $this->skip_option_value( $tokens, $index + 1 );
				continue;
			}

			if ( WP_MySQL_Lexer::CHAR_SYMBOL === $tokens[ $index ]->id || WP_MySQL_Lexer::CHARACTER_SYMBOL === $tokens[ $index ]->id ) {
				if ( ! isset( $tokens[ $index + 1 ] ) || WP_MySQL_Lexer::SET_SYMBOL !== $tokens[ $index + 1 ]->id ) {
					break;
				}
				$index = $this->skip_option_value( $tokens, $index + 2 );
				continue;
			}

			break;
		}

		return strtolower( $this->join_sql_pieces( $pieces ) );
	}

	/**
	 * Read column collation metadata from a column definition.
	 *
	 * @param WP_Parser_Token[] $tokens     Column definition tokens.
	 * @param WP_Parser_Token   $type_token Type token.
	 * @return string|null Collation name.
	 */
	private function mysql_column_collation_from_tokens( array $tokens, WP_Parser_Token $type_token, ?string $default_collation_name = null ): ?string {
		for ( $index = 0; $index < count( $tokens ); ++$index ) {
			if ( WP_MySQL_Lexer::COLLATE_SYMBOL === $tokens[ $index ]->id ) {
				return $this->option_value( $tokens, $index + 1 );
			}
		}

		if ( ! $this->mysql_type_has_collation( $type_token ) ) {
			return null;
		}

		return null !== $default_collation_name && '' !== $default_collation_name ? $default_collation_name : 'utf8mb4_0900_ai_ci';
	}

	/**
	 * Check whether a MySQL column should use DuckDB's case-insensitive collation.
	 *
	 * @param WP_Parser_Token $type_token     Type token.
	 * @param string|null     $collation_name MySQL collation name.
	 * @return bool Whether the column should use COLLATE NOCASE.
	 */
	private function mysql_column_uses_case_insensitive_collation( WP_Parser_Token $type_token, ?string $collation_name ): bool {
		return $this->mysql_type_has_collation( $type_token ) && $this->mysql_collation_is_case_insensitive( $collation_name );
	}

	/**
	 * Check whether a MySQL collation is case-insensitive.
	 *
	 * @param string|null $collation_name MySQL collation name.
	 * @return bool Whether the collation is case-insensitive.
	 */
	private function mysql_collation_is_case_insensitive( ?string $collation_name ): bool {
		if ( null === $collation_name || '' === $collation_name ) {
			return false;
		}

		$collation_name = strtolower( trim( $collation_name ) );

		return strlen( $collation_name ) > 3 && '_ci' === substr( $collation_name, -3 );
	}

	/**
	 * Read column comment metadata from a column definition.
	 *
	 * @param WP_Parser_Token[] $tokens Column definition tokens.
	 * @return string Column comment.
	 */
	private function mysql_column_comment_from_tokens( array $tokens ): string {
		for ( $index = 0; $index < count( $tokens ); ++$index ) {
			if ( WP_MySQL_Lexer::COMMENT_SYMBOL === $tokens[ $index ]->id ) {
				return (string) $this->option_value( $tokens, $index + 1 );
			}
		}

		return '';
	}

	/**
	 * Read an option value token, accepting optional equals.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Index at optional equals or value.
	 * @return string|null Option value.
	 */
	private function option_value( array $tokens, int $index ): ?string {
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::EQUAL_OPERATOR === $tokens[ $index ]->id ) {
			++$index;
		}

		return isset( $tokens[ $index ] ) ? $tokens[ $index ]->get_value() : null;
	}

	/**
	 * Check whether a MySQL type normally has collation metadata.
	 *
	 * @param WP_Parser_Token $type_token Type token.
	 * @return bool Whether the type is collated.
	 */
	private function mysql_type_has_collation( WP_Parser_Token $type_token ): bool {
		return in_array(
			$type_token->id,
			array(
				WP_MySQL_Lexer::CHAR_SYMBOL,
				WP_MySQL_Lexer::VARCHAR_SYMBOL,
				WP_MySQL_Lexer::TEXT_SYMBOL,
				WP_MySQL_Lexer::TINYTEXT_SYMBOL,
				WP_MySQL_Lexer::MEDIUMTEXT_SYMBOL,
				WP_MySQL_Lexer::LONGTEXT_SYMBOL,
			),
			true
		);
	}

	/**
	 * Apply primary/secondary index key markers to column metadata.
	 *
	 * @param array<int,array<string,mixed>>                                                       $metadata    Column metadata.
	 * @param string[]                                                                              $primary_key Table-level primary key columns.
	 * @param array<int,array{sql:string,table_name:string,index_name:string,unique:bool,columns:array<int,array{name:string,sub_part:int|null}>}> $indexes Index definitions.
	 * @return array<int,array<string,mixed>>
	 */
	private function apply_column_key_metadata( array $metadata, array $primary_key, array $indexes ): array {
		foreach ( $metadata as &$column ) {
			$column_name = strtolower( (string) $column['column_name'] );
			if ( in_array( $column_name, array_map( 'strtolower', $primary_key ), true ) ) {
				$column['column_key'] = 'PRI';
				continue;
			}

			$has_non_unique_index = false;
			foreach ( $indexes as $index_definition ) {
				if ( count( $index_definition['columns'] ) === 0 ) {
					continue;
				}
				if ( strtolower( $index_definition['columns'][0]['name'] ) !== $column_name ) {
					continue;
				}

				if ( $index_definition['unique'] ) {
					$column['column_key'] = 'UNI';
					continue 2;
				}

				$has_non_unique_index = true;
			}

			if ( $has_non_unique_index ) {
				$column['column_key'] = 'MUL';
			}
		}
		unset( $column );

		return $metadata;
	}

	/**
	 * Check whether a CREATE TABLE item is a table-level CHECK constraint.
	 *
	 * @param WP_Parser_Token[] $tokens Item tokens.
	 * @return bool
	 */
	private function is_create_table_check_constraint( array $tokens ): bool {
		if ( ! isset( $tokens[0] ) ) {
			return false;
		}

		if ( WP_MySQL_Lexer::CHECK_SYMBOL === $tokens[0]->id ) {
			return true;
		}

		if ( WP_MySQL_Lexer::CONSTRAINT_SYMBOL !== $tokens[0]->id || ! isset( $tokens[1] ) ) {
			return false;
		}

		if ( WP_MySQL_Lexer::CHECK_SYMBOL === $tokens[1]->id ) {
			return true;
		}

		return isset( $tokens[2] ) && WP_MySQL_Lexer::CHECK_SYMBOL === $tokens[2]->id;
	}

	/**
	 * Translate a table-level CHECK constraint.
	 *
	 * @param string            $table_name  Table name.
	 * @param WP_Parser_Token[] $tokens      Constraint tokens.
	 * @param array<string,bool> $check_names Existing MySQL-facing CHECK names, keyed lowercase.
	 * @return array{sql:string,metadata:array{constraint_name:string,check_clause:string,enforced:string}}
	 */
	private function translate_table_check_constraint( string $table_name, array $tokens, array &$check_names ): array {
		$index           = 0;
		$constraint_name = null;

		if ( WP_MySQL_Lexer::CONSTRAINT_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
			if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::CHECK_SYMBOL !== $tokens[ $index ]->id ) {
				$constraint_name = $this->identifier_value( $tokens[ $index ] );
				++$index;
			}
		}

		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::CHECK_SYMBOL, 'Expected CHECK constraint.' );
		++$index;
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::OPEN_PAR_SYMBOL, 'Expected CHECK expression.' );

		$expression_end    = $this->skip_balanced_parentheses( $tokens, $index );
		$expression_tokens = array_slice( $tokens, $index + 1, $expression_end - $index - 2 );
		if ( count( $expression_tokens ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( 'CHECK constraint requires an expression in the DuckDB driver.' );
		}

		$index = $expression_end;
		if ( isset( $tokens[ $index ] ) ) {
			if (
				WP_MySQL_Lexer::NOT_SYMBOL === $tokens[ $index ]->id
				&& isset( $tokens[ $index + 1 ] )
				&& WP_MySQL_Lexer::ENFORCED_SYMBOL === $tokens[ $index + 1 ]->id
			) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported CREATE TABLE CHECK constraint in DuckDB driver: NOT ENFORCED is not supported.' );
			}

			if ( WP_MySQL_Lexer::ENFORCED_SYMBOL === $tokens[ $index ]->id ) {
				++$index;
			}
		}

		if ( count( $tokens ) !== $index ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported CREATE TABLE CHECK constraint in DuckDB driver.' );
		}

		if ( null === $constraint_name ) {
			$constraint_name = $this->generate_check_constraint_name( $table_name, $check_names );
		}
		$this->register_check_constraint_name( $constraint_name, $check_names );

		$duckdb_expression = $this->translate_tokens_to_duckdb_sql( $expression_tokens );
		$mysql_expression  = $this->mysql_check_clause_from_tokens( $expression_tokens );

		return array(
			'sql'      => 'CONSTRAINT '
				. $this->connection->quote_identifier( $constraint_name )
				. ' CHECK ('
				. $duckdb_expression
				. ')',
			'metadata' => array(
				'constraint_name' => $constraint_name,
				'check_clause'    => $mysql_expression,
				'enforced'        => 'YES',
			),
		);
	}

	/**
	 * Generate a MySQL-compatible name for an unnamed CHECK constraint.
	 *
	 * @param string             $table_name  Table name.
	 * @param array<string,bool> $check_names Existing CHECK names, keyed lowercase.
	 * @return string Generated constraint name.
	 */
	private function generate_check_constraint_name( string $table_name, array $check_names ): string {
		$prefix = $table_name . '_chk_';
		$index  = 1;

		while ( isset( $check_names[ strtolower( $prefix . $index ) ] ) ) {
			++$index;
		}

		return $prefix . $index;
	}

	/**
	 * Register a CHECK constraint name and reject duplicates.
	 *
	 * @param string             $constraint_name Constraint name.
	 * @param array<string,bool> $check_names     Existing CHECK names, keyed lowercase.
	 */
	private function register_check_constraint_name( string $constraint_name, array &$check_names ): void {
		$key = strtolower( $constraint_name );
		if ( isset( $check_names[ $key ] ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Duplicate CHECK constraint name in DuckDB driver: ' . $constraint_name . '.' );
		}

		$check_names[ $key ] = true;
	}

	/**
	 * Format CHECK expression tokens for MySQL-facing metadata.
	 *
	 * @param WP_Parser_Token[] $tokens Expression tokens.
	 * @return string MySQL-facing CHECK clause.
	 */
	private function mysql_check_clause_from_tokens( array $tokens ): string {
		return $this->join_sql_pieces(
			array_map(
				function ( WP_Parser_Token $token ): string {
					return $token->get_bytes();
				},
				$tokens
			)
		);
	}

	/**
	 * Check whether a CREATE TABLE item is a table-level FOREIGN KEY constraint.
	 *
	 * @param WP_Parser_Token[] $tokens Item tokens.
	 * @return bool
	 */
	private function is_create_table_foreign_key_constraint( array $tokens ): bool {
		if ( ! isset( $tokens[0] ) ) {
			return false;
		}

		if ( WP_MySQL_Lexer::FOREIGN_SYMBOL === $tokens[0]->id ) {
			return true;
		}

		if ( WP_MySQL_Lexer::CONSTRAINT_SYMBOL !== $tokens[0]->id || ! isset( $tokens[1] ) ) {
			return false;
		}

		if ( WP_MySQL_Lexer::FOREIGN_SYMBOL === $tokens[1]->id ) {
			return true;
		}

		return isset( $tokens[2] ) && WP_MySQL_Lexer::FOREIGN_SYMBOL === $tokens[2]->id;
	}

	/**
	 * Translate a table-level FOREIGN KEY constraint.
	 *
	 * @param string             $table_name        Table name.
	 * @param WP_Parser_Token[]  $tokens            Constraint tokens.
	 * @param array<string,bool> $foreign_key_names Existing FOREIGN KEY names, keyed lowercase.
	 * @return array{sql:string,metadata:array{constraint_name:string,columns:string[],referenced_table_name:string,referenced_columns:string[],update_rule:string,delete_rule:string}}
	 */
	private function translate_table_foreign_key_constraint( string $table_name, array $tokens, array &$foreign_key_names ): array {
		$index           = 0;
		$constraint_name = null;

		if ( WP_MySQL_Lexer::CONSTRAINT_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
			if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::FOREIGN_SYMBOL !== $tokens[ $index ]->id ) {
				$constraint_name = $this->identifier_value( $tokens[ $index ] );
				++$index;
			}
		}

		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::FOREIGN_SYMBOL, 'Expected FOREIGN KEY constraint.' );
		$this->expect_token( $tokens, $index + 1, WP_MySQL_Lexer::KEY_SYMBOL, 'Expected FOREIGN KEY constraint.' );
		$index += 2;

		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $index ]->id ) {
			$this->identifier_value( $tokens[ $index ] );
			++$index;
		}

		list( $columns, $index ) = $this->parse_foreign_key_column_list( $tokens, $index, 'FOREIGN KEY column list' );
		if ( 1 !== count( $columns ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported CREATE TABLE FOREIGN KEY constraint in DuckDB driver. Only single-column foreign keys are supported.' );
		}

		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::REFERENCES_SYMBOL, 'Expected REFERENCES in FOREIGN KEY constraint.' );
		++$index;

		$referenced_table_name = $this->identifier_value( $tokens[ $index ] ?? null );
		++$index;
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $index ]->id ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported CREATE TABLE FOREIGN KEY constraint in DuckDB driver. Schema-qualified references are not supported.' );
		}

		list( $referenced_columns, $index ) = $this->parse_foreign_key_column_list( $tokens, $index, 'FOREIGN KEY referenced column list' );
		if ( 1 !== count( $referenced_columns ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported CREATE TABLE FOREIGN KEY constraint in DuckDB driver. Only single-column foreign keys are supported.' );
		}

		list( $update_rule, $delete_rule, $index ) = $this->parse_foreign_key_actions( $tokens, $index );
		if ( count( $tokens ) !== $index ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported CREATE TABLE FOREIGN KEY constraint in DuckDB driver.' );
		}

		if ( null === $constraint_name ) {
			$constraint_name = $this->generate_foreign_key_constraint_name( $table_name, $foreign_key_names );
		}
		$this->register_foreign_key_constraint_name( $constraint_name, $foreign_key_names );

		return array(
			'sql'      => 'CONSTRAINT '
				. $this->connection->quote_identifier( $constraint_name )
				. ' FOREIGN KEY ('
				. $this->connection->quote_identifier( $columns[0] )
				. ') REFERENCES '
				. $this->connection->quote_identifier( $referenced_table_name )
				. ' ('
				. $this->connection->quote_identifier( $referenced_columns[0] )
				. ')',
			'metadata' => array(
				'constraint_name'       => $constraint_name,
				'columns'               => $columns,
				'referenced_table_name' => $referenced_table_name,
				'referenced_columns'    => $referenced_columns,
				'update_rule'           => $update_rule,
				'delete_rule'           => $delete_rule,
			),
		);
	}

	/**
	 * Parse a FOREIGN KEY column list.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Index at opening parenthesis.
	 * @param string            $label  User-facing list label for errors.
	 * @return array{0:string[],1:int}
	 */
	private function parse_foreign_key_column_list( array $tokens, int $index, string $label ): array {
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::OPEN_PAR_SYMBOL, 'Expected ' . $label . '.' );
		++$index;

		$columns = array();
		while ( $index < count( $tokens ) ) {
			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $index ]->id ) {
				++$index;
				break;
			}

			$columns[] = $this->identifier_value( $tokens[ $index ] );
			++$index;

			if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $index ]->id ) {
				++$index;
				continue;
			}

			if ( ! isset( $tokens[ $index ] ) || WP_MySQL_Lexer::CLOSE_PAR_SYMBOL !== $tokens[ $index ]->id ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported ' . $label . ' in DuckDB driver.' );
			}
		}

		if ( count( $columns ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( $label . ' requires at least one column in the DuckDB driver.' );
		}

		return array( $columns, $index );
	}

	/**
	 * Parse optional FOREIGN KEY ON UPDATE/DELETE actions.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Current index.
	 * @return array{0:string,1:string,2:int}
	 */
	private function parse_foreign_key_actions( array $tokens, int $index ): array {
		$update_rule = 'NO ACTION';
		$delete_rule = 'NO ACTION';
		$seen        = array();

		while ( $index < count( $tokens ) ) {
			$this->expect_token( $tokens, $index, WP_MySQL_Lexer::ON_SYMBOL, 'Expected ON in FOREIGN KEY action.' );
			if ( ! isset( $tokens[ $index + 1 ] ) ) {
				throw new WP_DuckDB_Driver_Exception( 'Expected FOREIGN KEY action target in DuckDB driver.' );
			}

			if ( WP_MySQL_Lexer::UPDATE_SYMBOL === $tokens[ $index + 1 ]->id ) {
				$target = 'UPDATE';
			} elseif ( WP_MySQL_Lexer::DELETE_SYMBOL === $tokens[ $index + 1 ]->id ) {
				$target = 'DELETE';
			} else {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported CREATE TABLE FOREIGN KEY action in DuckDB driver: ON ' . $tokens[ $index + 1 ]->get_bytes() . '.' );
			}

			if ( isset( $seen[ $target ] ) ) {
				throw new WP_DuckDB_Driver_Exception( 'Duplicate ON ' . $target . ' action in DuckDB FOREIGN KEY constraint.' );
			}
			$seen[ $target ] = true;

			list( $rule, $index ) = $this->parse_foreign_key_action( $tokens, $index + 2, $target );
			if ( 'UPDATE' === $target ) {
				$update_rule = $rule;
			} else {
				$delete_rule = $rule;
			}
		}

		return array( $update_rule, $delete_rule, $index );
	}

	/**
	 * Parse one FOREIGN KEY action.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Index at action.
	 * @param string            $target UPDATE or DELETE.
	 * @return array{0:string,1:int}
	 */
	private function parse_foreign_key_action( array $tokens, int $index, string $target ): array {
		if ( ! isset( $tokens[ $index ] ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Expected FOREIGN KEY action after ON ' . $target . ' in DuckDB driver.' );
		}

		if ( WP_MySQL_Lexer::NO_SYMBOL === $tokens[ $index ]->id ) {
			if ( ! isset( $tokens[ $index + 1 ] ) || WP_MySQL_Lexer::ACTION_SYMBOL !== $tokens[ $index + 1 ]->id ) {
				throw $this->unsupported_foreign_key_action_exception( $tokens, $index, $target );
			}
			return array( 'NO ACTION', $index + 2 );
		}

		if ( WP_MySQL_Lexer::RESTRICT_SYMBOL === $tokens[ $index ]->id ) {
			return array( 'RESTRICT', $index + 1 );
		}

		throw $this->unsupported_foreign_key_action_exception( $tokens, $index, $target );
	}

	/**
	 * Build an unsupported FOREIGN KEY action exception.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Index at action.
	 * @param string            $target UPDATE or DELETE.
	 * @return WP_DuckDB_Driver_Exception
	 */
	private function unsupported_foreign_key_action_exception( array $tokens, int $index, string $target ): WP_DuckDB_Driver_Exception {
		$action = isset( $tokens[ $index ] ) ? $tokens[ $index ]->get_bytes() : '';
		if (
			isset( $tokens[ $index + 1 ] )
			&& (
				WP_MySQL_Lexer::SET_SYMBOL === $tokens[ $index ]->id
				|| WP_MySQL_Lexer::NO_SYMBOL === $tokens[ $index ]->id
			)
		) {
			$action .= ' ' . $tokens[ $index + 1 ]->get_bytes();
		}

		return new WP_DuckDB_Driver_Exception( 'Unsupported CREATE TABLE FOREIGN KEY action in DuckDB driver: ON ' . $target . ' ' . trim( $action ) . ' is not supported.' );
	}

	/**
	 * Generate a MySQL-compatible name for an unnamed FOREIGN KEY constraint.
	 *
	 * @param string             $table_name        Table name.
	 * @param array<string,bool> $foreign_key_names Existing FOREIGN KEY names, keyed lowercase.
	 * @return string Generated constraint name.
	 */
	private function generate_foreign_key_constraint_name( string $table_name, array $foreign_key_names ): string {
		$prefix = $table_name . '_ibfk_';
		$index  = 1;

		while ( isset( $foreign_key_names[ strtolower( $prefix . $index ) ] ) ) {
			++$index;
		}

		return $prefix . $index;
	}

	/**
	 * Register a FOREIGN KEY constraint name and reject duplicates.
	 *
	 * @param string             $constraint_name   Constraint name.
	 * @param array<string,bool> $foreign_key_names Existing FOREIGN KEY names, keyed lowercase.
	 */
	private function register_foreign_key_constraint_name( string $constraint_name, array &$foreign_key_names ): void {
		$key = strtolower( $constraint_name );
		if ( isset( $foreign_key_names[ $key ] ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Duplicate FOREIGN KEY constraint name in DuckDB driver: ' . $constraint_name . '.' );
		}

		$foreign_key_names[ $key ] = true;
	}

	/**
	 * Translate a table-level PRIMARY KEY constraint.
	 *
	 * @param WP_Parser_Token[] $tokens Constraint tokens.
	 * @return string
	 */
	private function translate_table_primary_key( array $tokens ): string {
		$column_names = $this->table_primary_key_columns( $tokens );
		if ( count( $column_names ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported PRIMARY KEY constraint in DuckDB driver.' );
		}

		$columns = array_map(
			function ( string $column_name ): string {
				return $this->connection->quote_identifier( $column_name );
			},
			$column_names
		);

		return 'PRIMARY KEY (' . implode( ', ', $columns ) . ')';
	}

	/**
	 * Read table-level PRIMARY KEY columns.
	 *
	 * @param WP_Parser_Token[] $tokens Constraint tokens.
	 * @return string[]
	 */
	private function table_primary_key_columns( array $tokens ): array {
		$index = 0;
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::PRIMARY_SYMBOL, 'Expected PRIMARY KEY constraint.' );
		$this->expect_token( $tokens, $index + 1, WP_MySQL_Lexer::KEY_SYMBOL, 'Expected PRIMARY KEY constraint.' );
		$this->expect_token( $tokens, $index + 2, WP_MySQL_Lexer::OPEN_PAR_SYMBOL, 'Expected PRIMARY KEY column list.' );
		$index = 3;

		$columns = array();
		while ( $index < count( $tokens ) ) {
			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $index ]->id ) {
				++$index;
				break;
			}
			$columns[] = $this->identifier_value( $tokens[ $index ] );
			++$index;
			if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $index ]->id ) {
				++$index;
			}
		}

		if ( count( $tokens ) !== $index ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported PRIMARY KEY constraint in DuckDB driver.' );
		}

		return $columns;
	}

	/**
	 * Check whether an item in CREATE TABLE is a secondary index.
	 *
	 * @param WP_Parser_Token[] $tokens Item tokens.
	 * @return bool
	 */
	private function is_create_table_index_item( array $tokens ): bool {
		return isset( $tokens[0] )
			&& in_array(
				$tokens[0]->id,
				array(
					WP_MySQL_Lexer::FULLTEXT_SYMBOL,
					WP_MySQL_Lexer::INDEX_SYMBOL,
					WP_MySQL_Lexer::KEY_SYMBOL,
					WP_MySQL_Lexer::SPATIAL_SYMBOL,
					WP_MySQL_Lexer::UNIQUE_SYMBOL,
				),
				true
			);
	}

	/**
	 * Check whether a CREATE statement starts a CREATE INDEX variant.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return bool
	 */
	private function is_create_index_statement( array $tokens ): bool {
		if ( ! isset( $tokens[1] ) ) {
			return false;
		}

		$index = 1;
		if (
			WP_MySQL_Lexer::UNIQUE_SYMBOL === $tokens[ $index ]->id
			|| WP_MySQL_Lexer::FULLTEXT_SYMBOL === $tokens[ $index ]->id
			|| WP_MySQL_Lexer::SPATIAL_SYMBOL === $tokens[ $index ]->id
		) {
			++$index;
		}

		return isset( $tokens[ $index ] ) && WP_MySQL_Lexer::INDEX_SYMBOL === $tokens[ $index ]->id;
	}

	/**
	 * Translate a table-level MySQL index definition into CREATE INDEX.
	 *
	 * @param string            $table_name Table name.
	 * @param WP_Parser_Token[] $tokens     Index definition tokens.
	 * @return array{sql:string,table_name:string,index_name:string,unique:bool,columns:array<int,array{name:string,sub_part:int|null}>}
	 */
	private function translate_create_table_index( string $table_name, array $tokens, bool $temporary = false ): array {
		$index  = 0;
		$unique = false;

		if ( WP_MySQL_Lexer::FULLTEXT_SYMBOL === $tokens[0]->id || WP_MySQL_Lexer::SPATIAL_SYMBOL === $tokens[0]->id ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported CREATE TABLE index type in DuckDB driver: ' . $tokens[0]->get_bytes() . '.' );
		}

		if ( WP_MySQL_Lexer::UNIQUE_SYMBOL === $tokens[ $index ]->id ) {
			$unique = true;
			++$index;
			if (
				isset( $tokens[ $index ] )
				&& ( WP_MySQL_Lexer::KEY_SYMBOL === $tokens[ $index ]->id || WP_MySQL_Lexer::INDEX_SYMBOL === $tokens[ $index ]->id )
			) {
				++$index;
			}
		} elseif ( WP_MySQL_Lexer::KEY_SYMBOL === $tokens[ $index ]->id || WP_MySQL_Lexer::INDEX_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
		} else {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported CREATE TABLE index in DuckDB driver.' );
		}

		$index = $this->skip_optional_index_type( $tokens, $index );

		$mysql_index_name = null;
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $index ]->id ) {
			$mysql_index_name = $this->identifier_value( $tokens[ $index ] );
			++$index;
		}

		$index = $this->skip_optional_index_type( $tokens, $index );

		list( $columns, $column_metadata, $index ) = $this->translate_index_column_list( $tokens, $index );
		$this->assert_supported_index_options( $tokens, $index );

		if ( null === $mysql_index_name ) {
			$mysql_index_name = 'unnamed_' . substr( hash( 'sha256', serialize( $column_metadata ) ), 0, 8 );
		}

		return $this->build_secondary_index_definition( $table_name, $mysql_index_name, $unique, $columns, $column_metadata, $temporary );
	}

	/**
	 * Build a DuckDB secondary index definition and SHOW INDEX metadata.
	 *
	 * @param string                                                                 $table_name       Table name.
	 * @param string                                                                 $mysql_index_name MySQL index name.
	 * @param bool                                                                   $unique           Whether the index is unique.
	 * @param string[]                                                               $columns          DuckDB column SQL fragments.
	 * @param array<int,array{name:string,sub_part:int|null}>                        $column_metadata  MySQL column metadata.
	 * @return array{sql:string,table_name:string,index_name:string,unique:bool,temporary:bool,columns:array<int,array{name:string,sub_part:int|null}>}
	 */
	private function build_secondary_index_definition( string $table_name, string $mysql_index_name, bool $unique, array $columns, array $column_metadata, bool $temporary = false ): array {
		return array(
			'sql'        => 'CREATE '
				. ( $unique ? 'UNIQUE ' : '' )
				. 'INDEX IF NOT EXISTS '
				. $this->connection->quote_identifier( $this->index_name( $table_name, $mysql_index_name, $temporary ) )
				. ' ON '
				. $this->connection->quote_identifier( $table_name )
				. ' ('
				. implode( ', ', $columns )
				. ')',
			'table_name' => $table_name,
			'index_name' => $mysql_index_name,
			'unique'     => $unique,
			'temporary'  => $temporary,
			'columns'    => $column_metadata,
		);
	}

	/**
	 * Translate an index column list.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Index at opening parenthesis.
	 * @return array{0:string[],1:array<int,array{name:string,sub_part:int|null}>,2:int}
	 */
	private function translate_index_column_list( array $tokens, int $index ): array {
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::OPEN_PAR_SYMBOL, 'Expected index column list in CREATE TABLE.' );
		++$index;

		$columns         = array();
		$column_metadata = array();
		while ( $index < count( $tokens ) ) {
			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $index ]->id ) {
				++$index;
				break;
			}

			$column_name = $this->identifier_value( $tokens[ $index ] );
			++$index;

			$sub_part = null;
			if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $index ]->id ) {
				$sub_part = $this->index_prefix_length( $tokens, $index );
				$index    = $this->skip_balanced_parentheses( $tokens, $index );
			}

			if (
				isset( $tokens[ $index ] )
				&& ( WP_MySQL_Lexer::ASC_SYMBOL === $tokens[ $index ]->id || WP_MySQL_Lexer::DESC_SYMBOL === $tokens[ $index ]->id )
			) {
				++$index;
			}

			$columns[]         = $this->connection->quote_identifier( $column_name );
			$column_metadata[] = array(
				'name'     => $column_name,
				'sub_part' => $sub_part,
			);

			if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $index ]->id ) {
				++$index;
				continue;
			}

			if ( ! isset( $tokens[ $index ] ) || WP_MySQL_Lexer::CLOSE_PAR_SYMBOL !== $tokens[ $index ]->id ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported index column list in DuckDB driver.' );
			}
		}

		if ( count( $columns ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( 'CREATE TABLE index requires at least one column.' );
		}

		return array( $columns, $column_metadata, $index );
	}

	/**
	 * Skip optional USING BTREE/HASH syntax.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Current index.
	 * @return int New index.
	 */
	private function skip_optional_index_type( array $tokens, int $index ): int {
		if ( ! isset( $tokens[ $index ] ) || WP_MySQL_Lexer::USING_SYMBOL !== $tokens[ $index ]->id ) {
			return $index;
		}

		if ( ! isset( $tokens[ $index + 1 ] ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Expected index type after USING in DuckDB driver.' );
		}

		if (
			WP_MySQL_Lexer::BTREE_SYMBOL !== $tokens[ $index + 1 ]->id
			&& WP_MySQL_Lexer::HASH_SYMBOL !== $tokens[ $index + 1 ]->id
		) {
			$this->identifier_value( $tokens[ $index + 1 ] );
		}

		return $index + 2;
	}

	/**
	 * Read an optional MySQL index prefix length.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Index at opening parenthesis.
	 * @return int|null Prefix length.
	 */
	private function index_prefix_length( array $tokens, int $index ): ?int {
		if (
			isset( $tokens[ $index + 1 ], $tokens[ $index + 2 ] )
			&& $this->is_number_token( $tokens[ $index + 1 ] )
			&& WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $index + 2 ]->id
		) {
			return (int) $tokens[ $index + 1 ]->get_bytes();
		}

		return null;
	}

	/**
	 * Assert supported trailing index options.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Current index.
	 */
	private function assert_supported_index_options( array $tokens, int $index ): void {
		while ( $index < count( $tokens ) ) {
			if ( WP_MySQL_Lexer::COMMENT_SYMBOL === $tokens[ $index ]->id ) {
				$index = $this->skip_option_value( $tokens, $index + 1 );
				continue;
			}

			if ( WP_MySQL_Lexer::USING_SYMBOL === $tokens[ $index ]->id ) {
				$index = $this->skip_optional_index_type( $tokens, $index );
				continue;
			}

			throw new WP_DuckDB_Driver_Exception( 'Unsupported index option in DuckDB driver: ' . $tokens[ $index ]->get_bytes() . '.' );
		}
	}

	/**
	 * Parse supported CREATE TABLE tail options into MySQL-facing metadata.
	 *
	 * @param WP_Parser_Token[] $tokens Tail tokens after the column list.
	 * @return array{engine:string,row_format:string,table_collation:string,table_comment:string,create_options:string,auto_increment:int|null}
	 */
	private function parse_create_table_options( array $tokens ): array {
		$engine          = 'InnoDB';
		$table_collation = 'utf8mb4_0900_ai_ci';
		$table_comment   = '';
		$auto_increment  = null;
		$index           = 0;
		while ( $index < count( $tokens ) ) {
			$token = $tokens[ $index ];
			if ( WP_MySQL_Lexer::DEFAULT_SYMBOL === $token->id ) {
				++$index;
				continue;
			}

			if ( WP_MySQL_Lexer::ENGINE_SYMBOL === $token->id ) {
				$engine = $this->normalize_table_engine( (string) $this->option_value( $tokens, $index + 1 ) );
				$index  = $this->skip_option_value( $tokens, $index + 1 );
				continue;
			}

			if ( WP_MySQL_Lexer::COLLATE_SYMBOL === $token->id ) {
				$table_collation = strtolower( (string) $this->option_value( $tokens, $index + 1 ) );
				$index           = $this->skip_option_value( $tokens, $index + 1 );
				continue;
			}

			if ( WP_MySQL_Lexer::COMMENT_SYMBOL === $token->id ) {
				$table_comment = (string) $this->option_value( $tokens, $index + 1 );
				$index         = $this->skip_option_value( $tokens, $index + 1 );
				continue;
			}

			if (
				WP_MySQL_Lexer::CHARSET_SYMBOL === $token->id
				|| WP_MySQL_Lexer::ROW_FORMAT_SYMBOL === $token->id
			) {
				$index = $this->skip_option_value( $tokens, $index + 1 );
				continue;
			}

			if ( WP_MySQL_Lexer::AUTO_INCREMENT_SYMBOL === $token->id ) {
				$auto_increment = $this->parse_auto_increment_option_value( $tokens, $index + 1, 'CREATE TABLE', true );
				$index          = $this->skip_option_value( $tokens, $index + 1 );
				continue;
			}

			if ( WP_MySQL_Lexer::CHAR_SYMBOL === $token->id || WP_MySQL_Lexer::CHARACTER_SYMBOL === $token->id ) {
				if ( ! isset( $tokens[ $index + 1 ] ) || WP_MySQL_Lexer::SET_SYMBOL !== $tokens[ $index + 1 ]->id ) {
					throw new WP_DuckDB_Driver_Exception( 'Unsupported CREATE TABLE option in DuckDB driver: ' . $token->get_bytes() . '.' );
				}
				$index = $this->skip_option_value( $tokens, $index + 2 );
				continue;
			}
			throw new WP_DuckDB_Driver_Exception( 'Unsupported CREATE TABLE option in DuckDB driver: ' . $token->get_bytes() . '.' );
		}

		return array(
			'engine'          => $engine,
			'row_format'      => 'MyISAM' === $engine ? 'Fixed' : 'Dynamic',
			'table_collation' => $table_collation,
			'table_comment'   => $table_comment,
			'create_options'  => '',
			'auto_increment'  => $auto_increment,
		);
	}

	/**
	 * Parse an AUTO_INCREMENT table option value.
	 *
	 * @param WP_Parser_Token[] $tokens    Token stream.
	 * @param int               $index     Index at optional equals or value.
	 * @param string            $statement Statement name for errors.
	 * @param bool              $allow_trailing Whether unrelated trailing option tokens are allowed.
	 * @return int Requested next AUTO_INCREMENT value.
	 */
	private function parse_auto_increment_option_value( array $tokens, int $index, string $statement, bool $allow_trailing = false ): int {
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::EQUAL_OPERATOR === $tokens[ $index ]->id ) {
			++$index;
		}

		if ( ! isset( $tokens[ $index ] ) || ! $this->is_number_token( $tokens[ $index ] ) ) {
			throw new WP_DuckDB_Driver_Exception( $statement . ' AUTO_INCREMENT requires a numeric value in the DuckDB driver.' );
		}

		$value = (int) $tokens[ $index ]->get_bytes();
		if ( $value < 1 ) {
			throw new WP_DuckDB_Driver_Exception( $statement . ' AUTO_INCREMENT requires a positive value in the DuckDB driver.' );
		}

		if ( ! $allow_trailing && isset( $tokens[ $index + 1 ] ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ' . $statement . ' AUTO_INCREMENT option in DuckDB driver.' );
		}

		return $value;
	}

	/**
	 * Normalize a MySQL storage engine value for information_schema.tables.
	 *
	 * @param string $engine Storage engine option value.
	 * @return string Normalized storage engine.
	 */
	private function normalize_table_engine( string $engine ): string {
		$upper = strtoupper( $engine );
		if ( 'INNODB' === $upper ) {
			return 'InnoDB';
		}
		if ( 'MYISAM' === $upper ) {
			return 'MyISAM';
		}

		return $upper;
	}

	/**
	 * Translate a DEFAULT literal and advance the index.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Current index, passed by reference.
	 * @return string DuckDB SQL literal.
	 */
	private function translate_default_literal( array $tokens, int &$index ): string {
		if ( ! isset( $tokens[ $index ] ) ) {
			throw new WP_DuckDB_Driver_Exception( 'DEFAULT requires a literal in the DuckDB driver.' );
		}

		$token = $tokens[ $index ];
		if (
			( WP_MySQL_Lexer::MINUS_OPERATOR === $token->id || WP_MySQL_Lexer::PLUS_OPERATOR === $token->id )
			&& isset( $tokens[ $index + 1 ] )
			&& $this->is_number_token( $tokens[ $index + 1 ] )
		) {
			$literal = ( WP_MySQL_Lexer::MINUS_OPERATOR === $token->id ? '-' : '+' ) . $tokens[ $index + 1 ]->get_bytes();
			$index  += 2;
			return $literal;
		}

		++$index;

		if ( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id || WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $token->id ) {
			return $this->connection->quote( $token->get_value() );
		}
		if ( $this->is_number_token( $token ) ) {
			return $token->get_bytes();
		}
		if ( WP_MySQL_Lexer::NULL_SYMBOL === $token->id || WP_MySQL_Lexer::NULL2_SYMBOL === $token->id ) {
			return 'NULL';
		}
		if ( WP_MySQL_Lexer::TRUE_SYMBOL === $token->id || WP_MySQL_Lexer::FALSE_SYMBOL === $token->id ) {
			return strtoupper( $token->get_bytes() );
		}

		throw new WP_DuckDB_Driver_Exception( 'Only DEFAULT literals are supported by the DuckDB driver.' );
	}

	/**
	 * Convert MySQL tokens to DuckDB SQL.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return string DuckDB SQL.
	 */
	private function translate_tokens_to_duckdb_sql(
		array $tokens,
		bool $rewrite_information_schema_tables = false,
		bool $rewrite_information_schema_columns = false,
		bool $rewrite_information_schema_statistics = false,
		bool $rewrite_information_schema_table_constraints = false,
		bool $rewrite_information_schema_key_column_usage = false,
		bool $rewrite_information_schema_referential_constraints = false,
		bool $rewrite_information_schema_check_constraints = false
	): string {
		$pieces = array();

		for ( $index = 0; $index < count( $tokens ); ++$index ) {
			$token = $tokens[ $index ];

			if ( $rewrite_information_schema_tables && $this->is_information_schema_tables_reference( $tokens, $index ) ) {
				$pieces[] = $this->connection->quote_identifier( self::INFO_SCHEMA_TABLES_TABLE );
				$index   += 2;
				continue;
			}
			if ( $rewrite_information_schema_columns && $this->is_information_schema_columns_reference( $tokens, $index ) ) {
				$pieces[] = $this->connection->quote_identifier( self::INFO_SCHEMA_COLUMNS_TABLE );
				$index   += 2;
				continue;
			}
			if ( $rewrite_information_schema_statistics && $this->is_information_schema_statistics_reference( $tokens, $index ) ) {
				$pieces[] = $this->connection->quote_identifier( self::INFO_SCHEMA_STATISTICS_TABLE );
				$index   += 2;
				continue;
			}
			if ( $rewrite_information_schema_table_constraints && $this->is_information_schema_table_constraints_reference( $tokens, $index ) ) {
				$pieces[] = $this->connection->quote_identifier( self::INFO_SCHEMA_TABLE_CONSTRAINTS_TABLE );
				$index   += 2;
				continue;
			}
			if ( $rewrite_information_schema_key_column_usage && $this->is_information_schema_key_column_usage_reference( $tokens, $index ) ) {
				$pieces[] = $this->connection->quote_identifier( self::INFO_SCHEMA_KEY_COLUMN_USAGE_TABLE );
				$index   += 2;
				continue;
			}
			if ( $rewrite_information_schema_referential_constraints && $this->is_information_schema_referential_constraints_reference( $tokens, $index ) ) {
				$pieces[] = $this->connection->quote_identifier( self::INFO_SCHEMA_REFERENTIAL_CONSTRAINTS_TABLE );
				$index   += 2;
				continue;
			}
			if ( $rewrite_information_schema_check_constraints && $this->is_information_schema_check_constraints_reference( $tokens, $index ) ) {
				$pieces[] = $this->connection->quote_identifier( self::INFO_SCHEMA_CHECK_CONSTRAINTS_TABLE );
				$index   += 2;
				continue;
			}

			if (
				WP_MySQL_Lexer::FROM_SYMBOL === $token->id
				&& isset( $tokens[ $index + 1 ] )
				&& WP_MySQL_Lexer::DUAL_SYMBOL === $tokens[ $index + 1 ]->id
			) {
				++$index;
				continue;
			}

			$limit_clause = $this->translate_limit_offset_count_clause( $tokens, $index );
			if ( null !== $limit_clause ) {
				$pieces[] = $limit_clause;
				continue;
			}

			$field_function = $this->translate_field_function_call(
				$tokens,
				$index,
				$rewrite_information_schema_tables,
				$rewrite_information_schema_columns,
				$rewrite_information_schema_statistics,
				$rewrite_information_schema_table_constraints,
				$rewrite_information_schema_key_column_usage,
				$rewrite_information_schema_referential_constraints,
				$rewrite_information_schema_check_constraints
			);
			if ( null !== $field_function ) {
				$pieces[] = $field_function;
				continue;
			}

			if (
				WP_MySQL_Lexer::NOT_SYMBOL === $token->id
				&& isset( $tokens[ $index + 1 ], $tokens[ $index + 2 ] )
				&& $this->is_regexp_operator( $tokens[ $index + 1 ] )
			) {
				$pieces[] = $this->translate_regexp_predicate( $pieces, $tokens[ $index + 2 ], true );
				$index   += 2;
				continue;
			}

			if (
				$this->is_regexp_operator( $token )
				&& isset( $tokens[ $index + 1 ] )
			) {
				$pieces[] = $this->translate_regexp_predicate( $pieces, $tokens[ $index + 1 ], false );
				++$index;
				continue;
			}

			if ( $this->is_empty_function_call( $tokens, $index, 'DATABASE' ) ) {
				$pieces[] = $this->connection->quote( $this->database );
				$index   += 2;
				continue;
			}

			if ( $this->is_empty_function_call( $tokens, $index, 'VERSION' ) ) {
				$pieces[] = $this->connection->quote( $this->format_mysql_version() );
				$index   += 2;
				continue;
			}

			if ( $this->is_empty_function_call( $tokens, $index, 'RAND' ) ) {
				$pieces[] = 'random()';
				$index   += 2;
				continue;
			}

			if ( $this->is_empty_function_call( $tokens, $index, 'UNIX_TIMESTAMP' ) ) {
				$pieces[] = 'CAST(epoch(current_timestamp) AS BIGINT)';
				$index   += 2;
				continue;
			}

			$unix_timestamp_comparison = $this->translate_unix_timestamp_comparison( $tokens, $index );
			if ( null !== $unix_timestamp_comparison ) {
				$pieces[] = $unix_timestamp_comparison;
				continue;
			}

			$like_escape_predicate = $this->translate_like_escape_predicate( $tokens, $index );
			if ( null !== $like_escape_predicate ) {
				$pieces[] = $like_escape_predicate;
				continue;
			}

			if ( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id ) {
				$identifier = null;
				if ( $rewrite_information_schema_tables ) {
					$identifier = $this->information_schema_tables_column_name( $token->get_value() );
				}
				if ( null === $identifier && $rewrite_information_schema_statistics ) {
					$identifier = $this->information_schema_statistics_column_name( $token->get_value() );
				}
				if ( null === $identifier && $rewrite_information_schema_table_constraints ) {
					$identifier = $this->information_schema_table_constraints_column_name( $token->get_value() );
				}
				if ( null === $identifier && $rewrite_information_schema_key_column_usage ) {
					$identifier = $this->information_schema_key_column_usage_column_name( $token->get_value() );
				}
				if ( null === $identifier && $rewrite_information_schema_referential_constraints ) {
					$identifier = $this->information_schema_referential_constraints_column_name( $token->get_value() );
				}
				if ( null === $identifier && $rewrite_information_schema_check_constraints ) {
					$identifier = $this->information_schema_check_constraints_column_name( $token->get_value() );
				}
				$pieces[] = $this->connection->quote_identifier( $identifier ?? $token->get_value() );
				continue;
			}

			if ( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id || WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $token->id ) {
				$pieces[] = $this->connection->quote( $token->get_value() );
				continue;
			}

			if ( $rewrite_information_schema_tables ) {
				$identifier = $this->information_schema_tables_column_name( $token->get_value() );
				if ( null !== $identifier ) {
					$pieces[] = $this->connection->quote_identifier( $identifier );
					continue;
				}
			}

			if ( $rewrite_information_schema_statistics ) {
				$identifier = $this->information_schema_statistics_column_name( $token->get_value() );
				if ( null !== $identifier ) {
					$pieces[] = $this->connection->quote_identifier( $identifier );
					continue;
				}
			}

			if ( $rewrite_information_schema_table_constraints ) {
				$identifier = $this->information_schema_table_constraints_column_name( $token->get_value() );
				if ( null !== $identifier ) {
					$pieces[] = $this->connection->quote_identifier( $identifier );
					continue;
				}
			}

			if ( $rewrite_information_schema_key_column_usage ) {
				$identifier = $this->information_schema_key_column_usage_column_name( $token->get_value() );
				if ( null !== $identifier ) {
					$pieces[] = $this->connection->quote_identifier( $identifier );
					continue;
				}
			}

			if ( $rewrite_information_schema_referential_constraints ) {
				$identifier = $this->information_schema_referential_constraints_column_name( $token->get_value() );
				if ( null !== $identifier ) {
					$pieces[] = $this->connection->quote_identifier( $identifier );
					continue;
				}
			}

			if ( $rewrite_information_schema_check_constraints ) {
				$identifier = $this->information_schema_check_constraints_column_name( $token->get_value() );
				if ( null !== $identifier ) {
					$pieces[] = $this->connection->quote_identifier( $identifier );
					continue;
				}
			}

			$pieces[] = $token->get_bytes();
		}

		return $this->join_sql_pieces( $pieces );
	}

	/**
	 * Translate MySQL LIMIT offset, row_count syntax to DuckDB LIMIT/OFFSET.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @param int               $index  Current token index, advanced on match.
	 * @return string|null DuckDB SQL, or null when the current token does not start this LIMIT shape.
	 */
	private function translate_limit_offset_count_clause( array $tokens, int &$index ): ?string {
		if (
			! isset( $tokens[ $index + 3 ] )
			|| WP_MySQL_Lexer::LIMIT_SYMBOL !== $tokens[ $index ]->id
			|| WP_MySQL_Lexer::COMMA_SYMBOL !== $tokens[ $index + 2 ]->id
		) {
			return null;
		}

		$offset    = $this->translate_token_to_duckdb_sql( $tokens[ $index + 1 ] );
		$row_count = $this->translate_token_to_duckdb_sql( $tokens[ $index + 3 ] );
		$index    += 3;

		return 'LIMIT ' . $row_count . ' OFFSET ' . $offset;
	}

	/**
	 * Normalize SELECT helper syntax that DuckDB does not understand directly.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL SELECT tokens.
	 * @return WP_Parser_Token[] Normalized tokens.
	 */
	private function normalize_select_helper_tokens( array $tokens ): array {
		$tokens = $this->strip_top_level_sql_calc_found_rows( $tokens );
		$tokens = $this->strip_select_index_hints( $tokens );
		return $this->strip_select_locking_clauses( $tokens );
	}

	/**
	 * Check whether a SELECT has a top-level SQL_CALC_FOUND_ROWS option.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return bool Whether SQL_CALC_FOUND_ROWS is present.
	 */
	private function has_top_level_sql_calc_found_rows( array $tokens ): bool {
		return null !== $this->find_top_level_token_index( $tokens, 0, WP_MySQL_Lexer::SQL_CALC_FOUND_ROWS_SYMBOL );
	}

	/**
	 * Strip a top-level SQL_CALC_FOUND_ROWS SELECT option.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_Parser_Token[] Tokens without SQL_CALC_FOUND_ROWS.
	 */
	private function strip_top_level_sql_calc_found_rows( array $tokens ): array {
		$stripped = array();
		$depth    = 0;
		foreach ( $tokens as $token ) {
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $token->id ) {
				++$depth;
				$stripped[] = $token;
				continue;
			}
			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $token->id ) {
				--$depth;
				$stripped[] = $token;
				continue;
			}
			if ( 0 === $depth && WP_MySQL_Lexer::SQL_CALC_FOUND_ROWS_SYMBOL === $token->id ) {
				continue;
			}
			$stripped[] = $token;
		}

		return $stripped;
	}

	/**
	 * Strip a top-level LIMIT clause for SQL_CALC_FOUND_ROWS counting.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_Parser_Token[] Tokens without the top-level LIMIT clause.
	 */
	private function strip_top_level_limit_clause( array $tokens ): array {
		$limit_index = $this->find_top_level_token_index( $tokens, 0, WP_MySQL_Lexer::LIMIT_SYMBOL );
		if ( null === $limit_index ) {
			return $tokens;
		}

		return array_slice( $tokens, 0, $limit_index );
	}

	/**
	 * Strip MySQL index hints, which are optimizer directives only.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL SELECT tokens.
	 * @return WP_Parser_Token[] Tokens without index hints.
	 */
	private function strip_select_index_hints( array $tokens ): array {
		$stripped = array();
		for ( $index = 0; $index < count( $tokens ); ++$index ) {
			$next_index = $this->skip_select_index_hint( $tokens, $index );
			if ( null !== $next_index ) {
				$index = $next_index - 1;
				continue;
			}
			$stripped[] = $tokens[ $index ];
		}

		return $stripped;
	}

	/**
	 * Skip one MySQL index hint when the current token starts one.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @param int               $index  Current token index.
	 * @return int|null Index after the hint, or null when no hint starts here.
	 */
	private function skip_select_index_hint( array $tokens, int $index ): ?int {
		if (
			! isset( $tokens[ $index + 2 ] )
			|| ! in_array(
				$tokens[ $index ]->id,
				array(
					WP_MySQL_Lexer::USE_SYMBOL,
					WP_MySQL_Lexer::FORCE_SYMBOL,
					WP_MySQL_Lexer::IGNORE_SYMBOL,
				),
				true
			)
			|| ! in_array(
				$tokens[ $index + 1 ]->id,
				array(
					WP_MySQL_Lexer::INDEX_SYMBOL,
					WP_MySQL_Lexer::KEY_SYMBOL,
				),
				true
			)
		) {
			return null;
		}

		$index += 2;
		if (
			isset( $tokens[ $index + 1 ] )
			&& WP_MySQL_Lexer::FOR_SYMBOL === $tokens[ $index ]->id
			&& WP_MySQL_Lexer::JOIN_SYMBOL === $tokens[ $index + 1 ]->id
		) {
			$index += 2;
		} elseif (
			isset( $tokens[ $index + 2 ] )
			&& WP_MySQL_Lexer::FOR_SYMBOL === $tokens[ $index ]->id
			&& ( WP_MySQL_Lexer::ORDER_SYMBOL === $tokens[ $index + 1 ]->id || WP_MySQL_Lexer::GROUP_SYMBOL === $tokens[ $index + 1 ]->id )
			&& WP_MySQL_Lexer::BY_SYMBOL === $tokens[ $index + 2 ]->id
		) {
			$index += 3;
		}

		if ( ! isset( $tokens[ $index ] ) || WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $index ]->id ) {
			return null;
		}

		return $this->skip_balanced_parentheses( $tokens, $index );
	}

	/**
	 * Strip MySQL locking clauses that DuckDB does not support.
	 *
	 * @param WP_Parser_Token[] $tokens SELECT tokens.
	 * @return WP_Parser_Token[] Tokens without locking clauses.
	 */
	private function strip_select_locking_clauses( array $tokens ): array {
		$stripped = array();
		$depth    = 0;
		for ( $index = 0; $index < count( $tokens ); ++$index ) {
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $index ]->id ) {
				++$depth;
				$stripped[] = $tokens[ $index ];
				continue;
			}
			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $index ]->id ) {
				--$depth;
				$stripped[] = $tokens[ $index ];
				continue;
			}
			if (
				WP_MySQL_Lexer::FOR_SYMBOL === $tokens[ $index ]->id
				&& isset( $tokens[ $index + 1 ] )
				&& ( WP_MySQL_Lexer::UPDATE_SYMBOL === $tokens[ $index + 1 ]->id || WP_MySQL_Lexer::SHARE_SYMBOL === $tokens[ $index + 1 ]->id )
			) {
				$index = $this->skip_select_locking_clause( $tokens, $index, $depth ) - 1;
				continue;
			}
			if (
				WP_MySQL_Lexer::LOCK_SYMBOL === $tokens[ $index ]->id
				&& isset( $tokens[ $index + 3 ] )
				&& WP_MySQL_Lexer::IN_SYMBOL === $tokens[ $index + 1 ]->id
				&& WP_MySQL_Lexer::SHARE_SYMBOL === $tokens[ $index + 2 ]->id
				&& WP_MySQL_Lexer::MODE_SYMBOL === $tokens[ $index + 3 ]->id
			) {
				$index += 3;
				continue;
			}
			$stripped[] = $tokens[ $index ];
		}

		return $stripped;
	}

	/**
	 * Skip a SELECT locking clause.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @param int               $index  Index at FOR.
	 * @param int               $depth  Parenthesis depth of the locking clause.
	 * @return int Index after the locking clause.
	 */
	private function skip_select_locking_clause( array $tokens, int $index, int $depth ): int {
		$index     += 2;
		$scan_depth = $depth;
		while ( $index < count( $tokens ) ) {
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $index ]->id ) {
				++$scan_depth;
				++$index;
				continue;
			}
			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $index ]->id ) {
				if ( $scan_depth === $depth ) {
					return $index;
				}
				--$scan_depth;
				++$index;
				continue;
			}
			++$index;
		}

		return $index;
	}

	/**
	 * Back-compat wrapper for older joined-update code paths.
	 *
	 * @param WP_Parser_Token[] $tokens SELECT tokens.
	 * @return WP_Parser_Token[] Tokens without locking clauses.
	 */
	private function strip_for_update_locking_clause( array $tokens ): array {
		return $this->strip_select_locking_clauses( $tokens );
	}

	/**
	 * Translate MySQL FIELD(expr, value...) to a CASE expression.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @param int               $index  Current token index, advanced on match.
	 * @return string|null DuckDB SQL, or null when the token does not start FIELD().
	 */
	private function translate_field_function_call(
		array $tokens,
		int &$index,
		bool $rewrite_information_schema_tables,
		bool $rewrite_information_schema_columns,
		bool $rewrite_information_schema_statistics,
		bool $rewrite_information_schema_table_constraints,
		bool $rewrite_information_schema_key_column_usage,
		bool $rewrite_information_schema_referential_constraints,
		bool $rewrite_information_schema_check_constraints
	): ?string {
		if (
			! isset( $tokens[ $index + 1 ] )
			|| $this->is_non_identifier_token( $tokens[ $index ] )
			|| 0 !== strcasecmp( $tokens[ $index ]->get_value(), 'FIELD' )
			|| WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $index + 1 ]->id
		) {
			return null;
		}

		list( $items, $next_index ) = $this->collect_parenthesized_items( $tokens, $index + 2 );
		if ( count( $items ) < 2 ) {
			$index = $next_index - 1;
			return '0';
		}

		$needle = $this->translate_tokens_to_duckdb_sql(
			$items[0],
			$rewrite_information_schema_tables,
			$rewrite_information_schema_columns,
			$rewrite_information_schema_statistics,
			$rewrite_information_schema_table_constraints,
			$rewrite_information_schema_key_column_usage,
			$rewrite_information_schema_referential_constraints,
			$rewrite_information_schema_check_constraints
		);

		$needle_comparison = 'lower(CAST((' . $needle . ') AS VARCHAR))';
		$cases             = array( 'CASE' );
		for ( $item_index = 1; $item_index < count( $items ); ++$item_index ) {
			$value_sql        = $this->translate_tokens_to_duckdb_sql(
				$items[ $item_index ],
				$rewrite_information_schema_tables,
				$rewrite_information_schema_columns,
				$rewrite_information_schema_statistics,
				$rewrite_information_schema_table_constraints,
				$rewrite_information_schema_key_column_usage,
				$rewrite_information_schema_referential_constraints,
				$rewrite_information_schema_check_constraints
			);
			$value_comparison = 'lower(CAST((' . $value_sql . ') AS VARCHAR))';
			$cases[]          = 'WHEN ' . $needle_comparison . ' = ' . $value_comparison . ' THEN ' . $item_index;
		}
		$cases[] = 'ELSE 0 END';

		$index = $next_index - 1;
		return implode( ' ', $cases );
	}

	/**
	 * Translate MySQL REPLACE ... VALUES to DuckDB INSERT OR REPLACE.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return string DuckDB SQL.
	 */
	private function translate_replace_tokens_to_duckdb_sql( array $tokens ): string {
		$index = 1;
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::INTO_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
		}

		return 'INSERT OR REPLACE INTO ' . $this->translate_tokens_to_duckdb_sql( array_slice( $tokens, $index ) );
	}

	/**
	 * Translate MySQL REPLACE ... VALUES to a plain DuckDB INSERT after manual conflict deletion.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return string DuckDB SQL.
	 */
	private function translate_replace_tokens_to_duckdb_insert_sql( array $tokens ): string {
		$index = 1;
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::INTO_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
		}

		return 'INSERT INTO ' . $this->translate_tokens_to_duckdb_sql( array_slice( $tokens, $index ) );
	}

	/**
	 * Translate MySQL INSERT IGNORE ... VALUES to DuckDB INSERT OR IGNORE.
	 *
	 * @param WP_Parser_Token[] $tokens      MySQL tokens.
	 * @param int               $table_index Index of the table token.
	 * @return string DuckDB SQL.
	 */
	private function translate_insert_ignore_tokens_to_duckdb_sql( array $tokens, int $table_index ): string {
		return 'INSERT OR IGNORE INTO ' . $this->translate_tokens_to_duckdb_sql( array_slice( $tokens, $table_index ) );
	}

	/**
	 * Translate MySQL INSERT ... SELECT to DuckDB, normalizing optional INTO.
	 *
	 * @param WP_Parser_Token[] $tokens      MySQL tokens.
	 * @param int               $table_index Index of the table token.
	 * @param bool              $ignore      Whether INSERT IGNORE was used.
	 * @return string DuckDB SQL.
	 */
	private function translate_insert_select_tokens_to_duckdb_sql( array $tokens, int $table_index, bool $ignore ): string {
		return 'INSERT '
			. ( $ignore ? 'OR IGNORE ' : '' )
			. 'INTO '
			. $this->translate_tokens_to_duckdb_sql( array_slice( $tokens, $table_index ) );
	}

	/**
	 * Translate MySQL INSERT ... SET to DuckDB INSERT ... VALUES.
	 *
	 * @param WP_Parser_Token[] $tokens      MySQL tokens.
	 * @param int               $table_index Index of the table token.
	 * @param int               $set_index   Index of the SET token.
	 * @param bool              $ignore      Whether INSERT IGNORE was used.
	 * @return string DuckDB SQL.
	 */
	private function translate_insert_set_tokens_to_duckdb_sql( array $tokens, int $table_index, int $set_index, bool $ignore ): string {
		list( $columns, $values ) = $this->parse_insert_set_assignments( array_slice( $tokens, $set_index + 1 ) );

		return 'INSERT '
			. ( $ignore ? 'OR IGNORE ' : '' )
			. 'INTO '
			. $this->translate_tokens_to_duckdb_sql( array( $tokens[ $table_index ] ) )
			. ' ('
			. implode( ', ', $columns )
			. ') VALUES ('
			. implode( ', ', $values )
			. ')';
	}

	/**
	 * Parse INSERT ... SET assignments.
	 *
	 * @param WP_Parser_Token[] $tokens Assignment-list tokens after SET.
	 * @return array{0:string[],1:string[]}
	 */
	private function parse_insert_set_assignments( array $tokens ): array {
		$columns = array();
		$values  = array();
		$index   = 0;

		while ( $index < count( $tokens ) ) {
			$column_name = $this->identifier_value( $tokens[ $index ] ?? null );
			$columns[]   = $this->translate_tokens_to_duckdb_sql( array( $tokens[ $index ] ) );
			++$index;

			if (
				! isset( $tokens[ $index ] )
				|| ( WP_MySQL_Lexer::EQUAL_OPERATOR !== $tokens[ $index ]->id && WP_MySQL_Lexer::ASSIGN_OPERATOR !== $tokens[ $index ]->id )
			) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported INSERT ... SET statement in DuckDB driver. Expected assignment for column: ' . $column_name . '.' );
			}
			++$index;

			$value_tokens = array();
			$depth        = 0;
			while ( $index < count( $tokens ) ) {
				if ( 0 === $depth && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $index ]->id ) {
					break;
				}
				if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $index ]->id ) {
					++$depth;
				} elseif ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $index ]->id ) {
					--$depth;
				}
				$value_tokens[] = $tokens[ $index ];
				++$index;
			}

			if ( count( $value_tokens ) === 0 ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported INSERT ... SET statement in DuckDB driver. Assignment value is required for column: ' . $column_name . '.' );
			}

			$values[] = $this->translate_tokens_to_duckdb_sql( $value_tokens );
			if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $index ]->id ) {
				++$index;
			}
		}

		if ( count( $columns ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported INSERT ... SET statement in DuckDB driver. At least one assignment is required.' );
		}

		return array( $columns, $values );
	}

	/**
	 * Translate a bounded MySQL INSERT ... ON DUPLICATE KEY UPDATE statement.
	 *
	 * @param WP_Parser_Token[] $tokens             MySQL tokens.
	 * @param int               $table_index        Index of the table token.
	 * @param int               $on_duplicate_index Index of the ON token.
	 * @return string DuckDB SQL.
	 */
	private function translate_insert_on_duplicate_key_update_tokens_to_duckdb_sql( array $tokens, int $table_index, int $on_duplicate_index ): string {
		$insert_shape = $this->parse_on_duplicate_insert_shape( $tokens, $table_index, $on_duplicate_index );
		$target       = $this->select_on_duplicate_conflict_target( $insert_shape['table_name'], $insert_shape['values_by_column'] );
		$update_sql   = $this->translate_on_duplicate_update_tokens_to_duckdb_sql( array_slice( $tokens, $on_duplicate_index + 4 ) );

		if ( '' === $update_sql ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported INSERT ... ON DUPLICATE KEY UPDATE statement in DuckDB driver. UPDATE list is required.' );
		}

		return 'INSERT INTO '
			. $this->translate_tokens_to_duckdb_sql( array_slice( $tokens, $table_index, $on_duplicate_index - $table_index ) )
			. ' ON CONFLICT ('
			. implode(
				', ',
				array_map(
					function ( string $column_name ): string {
						return $this->connection->quote_identifier( $column_name );
					},
					$target
				)
			)
			. ') DO UPDATE SET '
			. $update_sql;
	}

	/**
	 * Parse the supported INSERT ... VALUES shape needed for ODKU target selection.
	 *
	 * @param WP_Parser_Token[] $tokens             MySQL tokens.
	 * @param int               $table_index        Index of the table token.
	 * @param int               $on_duplicate_index Index of the ON token.
	 * @return array{table_name:string,values_by_column:array<string,string>}
	 */
	private function parse_on_duplicate_insert_shape( array $tokens, int $table_index, int $on_duplicate_index ): array {
		$table_name = $this->identifier_value( $tokens[ $table_index ] ?? null );
		$index      = $table_index + 1;

		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::OPEN_PAR_SYMBOL, 'Unsupported INSERT ... ON DUPLICATE KEY UPDATE statement in DuckDB driver. Explicit column list is required.' );
		++$index;

		$columns = array();
		while ( $index < count( $tokens ) ) {
			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $index ]->id ) {
				++$index;
				break;
			}
			$columns[] = $this->identifier_value( $tokens[ $index ] );
			++$index;
			if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $index ]->id ) {
				++$index;
				continue;
			}
			if ( ! isset( $tokens[ $index ] ) || WP_MySQL_Lexer::CLOSE_PAR_SYMBOL !== $tokens[ $index ]->id ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported INSERT ... ON DUPLICATE KEY UPDATE statement in DuckDB driver. Explicit column list is required.' );
			}
		}

		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::VALUES_SYMBOL, 'Unsupported INSERT ... ON DUPLICATE KEY UPDATE statement in DuckDB driver. Only INSERT ... VALUES is supported.' );
		++$index;
		$this->expect_token( $tokens, $index, WP_MySQL_Lexer::OPEN_PAR_SYMBOL, 'Unsupported INSERT ... ON DUPLICATE KEY UPDATE statement in DuckDB driver. A single VALUES row is required.' );
		++$index;

		list( $value_items, $index ) = $this->collect_parenthesized_items( $tokens, $index );
		if ( $index !== $on_duplicate_index ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported INSERT ... ON DUPLICATE KEY UPDATE statement in DuckDB driver. Only a single VALUES row is supported.' );
		}
		if ( count( $columns ) !== count( $value_items ) ) {
			throw new WP_DuckDB_Driver_Exception( 'INSERT ... ON DUPLICATE KEY UPDATE column count does not match value count in DuckDB driver.' );
		}

		$values_by_column = array();
		foreach ( $columns as $offset => $column_name ) {
			$values_by_column[ strtolower( $column_name ) ] = $this->translate_tokens_to_duckdb_sql( $value_items[ $offset ] );
		}

		return array(
			'table_name'       => $table_name,
			'values_by_column' => $values_by_column,
		);
	}

	/**
	 * Assert plain INSERT ... VALUES does not bypass a case-insensitive unique key.
	 *
	 * DuckDB's plain INSERT can allow case-only duplicates for a unique index on a
	 * COLLATE NOCASE column, even though INSERT OR IGNORE and ON CONFLICT honor it.
	 *
	 * @param WP_Parser_Token[] $tokens      MySQL tokens.
	 * @param int               $table_index Index of the table token.
	 */
	private function assert_insert_values_do_not_conflict_with_case_insensitive_unique_keys( array $tokens, int $table_index ): void {
		$insert_shape             = $this->parse_insert_values_shape( $tokens, $table_index );
		$case_insensitive_columns = $this->case_insensitive_column_names( $insert_shape['table_name'] );
		if ( count( $case_insensitive_columns ) === 0 ) {
			return;
		}

		foreach ( $insert_shape['rows'] as $values_by_column ) {
			foreach ( $this->unique_key_column_sets( $insert_shape['table_name'] ) as $column_set ) {
				$has_all_values           = true;
				$has_case_insensitive_key = false;
				foreach ( $column_set as $column_name ) {
					$column_key = strtolower( $column_name );
					if ( ! array_key_exists( $column_key, $values_by_column ) ) {
						$has_all_values = false;
						break;
					}
					if ( isset( $case_insensitive_columns[ $column_key ] ) ) {
						$has_case_insensitive_key = true;
					}
				}

				if ( ! $has_all_values || ! $has_case_insensitive_key ) {
					continue;
				}

				if ( $this->insert_values_conflict_with_target( $insert_shape['table_name'], $column_set, $values_by_column ) ) {
					throw new WP_DuckDB_Driver_Exception(
						'Failed to execute DuckDB INSERT: UNIQUE constraint failed: '
						. $insert_shape['table_name']
						. '.'
						. implode( ', ', $column_set )
					);
				}
			}
		}
	}

	/**
	 * Parse a supported INSERT ... VALUES shape into per-row value SQL.
	 *
	 * @param WP_Parser_Token[] $tokens      MySQL tokens.
	 * @param int               $table_index Index of the table token.
	 * @return array{table_name:string,rows:array<int,array<string,string>>}
	 */
	private function parse_insert_values_shape( array $tokens, int $table_index ): array {
		$table_name = $this->identifier_value( $tokens[ $table_index ] ?? null );
		$index      = $table_index + 1;
		$columns    = array();

		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $index ]->id ) {
			list( $column_items, $index ) = $this->collect_parenthesized_items( $tokens, $index + 1 );
			foreach ( $column_items as $column_tokens ) {
				if ( 1 !== count( $column_tokens ) ) {
					return array(
						'table_name' => $table_name,
						'rows'       => array(),
					);
				}
				$columns[] = $this->identifier_value( $column_tokens[0] );
			}
		} else {
			foreach ( $this->table_column_metadata_rows( $table_name ) as $row ) {
				if ( ! array_key_exists( 'column_name', $row ) ) {
					return array(
						'table_name' => $table_name,
						'rows'       => array(),
					);
				}
				$columns[] = (string) $row['column_name'];
			}
		}

		if ( count( $columns ) === 0 || ! isset( $tokens[ $index ] ) || WP_MySQL_Lexer::VALUES_SYMBOL !== $tokens[ $index ]->id ) {
			return array(
				'table_name' => $table_name,
				'rows'       => array(),
			);
		}
		++$index;

		$rows = array();
		while ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $index ]->id ) {
			list( $value_items, $index ) = $this->collect_parenthesized_items( $tokens, $index + 1 );
			if ( count( $columns ) !== count( $value_items ) ) {
				return array(
					'table_name' => $table_name,
					'rows'       => array(),
				);
			}

			$values_by_column = array();
			foreach ( $columns as $offset => $column_name ) {
				$values_by_column[ strtolower( $column_name ) ] = $this->translate_tokens_to_duckdb_sql( $value_items[ $offset ] );
			}
			$rows[] = $values_by_column;

			if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $index ]->id ) {
				++$index;
				continue;
			}
			break;
		}

		return array(
			'table_name' => $table_name,
			'rows'       => $rows,
		);
	}

	/**
	 * Execute REPLACE ... VALUES when DuckDB's native INSERT OR REPLACE is insufficient.
	 *
	 * DuckDB requires a conflict target for INSERT OR REPLACE when multiple unique
	 * constraints exist. MySQL REPLACE instead deletes every row that conflicts
	 * with any supplied unique key, then inserts the incoming row. Case-insensitive
	 * MySQL unique keys also need manual matching because DuckDB plain INSERT can
	 * bypass those conflicts.
	 *
	 * @param WP_Parser_Token[] $tokens      MySQL tokens.
	 * @param int               $table_index Index of the table token.
	 * @return WP_DuckDB_Result_Statement|null Statement when emulated, null when native DuckDB can be used.
	 */
	private function execute_replace_values_with_manual_conflict_handling( array $tokens, int $table_index ): ?WP_DuckDB_Result_Statement {
		$replace_shape            = $this->parse_insert_values_shape( $tokens, $table_index );
		$case_insensitive_columns = $this->case_insensitive_column_names( $replace_shape['table_name'] );
		if ( count( $replace_shape['rows'] ) === 0 ) {
			return null;
		}

		$unique_sets = $this->unique_key_column_sets( $replace_shape['table_name'] );
		if ( count( $unique_sets ) < 2 && ! $this->has_case_insensitive_unique_key( $unique_sets, $case_insensitive_columns ) ) {
			return null;
		}

		$started_transaction = false;
		if ( ! $this->connection->inTransaction() ) {
			$this->connection->beginTransaction();
			$started_transaction = true;
		}

		try {
			foreach ( $replace_shape['rows'] as $values_by_column ) {
				$delete_predicates = array();
				foreach ( $unique_sets as $column_set ) {
					$predicate = $this->unique_key_conflict_predicate( $column_set, $values_by_column, $case_insensitive_columns );
					if ( null !== $predicate ) {
						$delete_predicates[] = '(' . $predicate . ')';
					}
				}

				if ( count( $delete_predicates ) > 0 ) {
					$this->execute_duckdb_query(
						'DELETE FROM '
							. $this->connection->quote_identifier( $replace_shape['table_name'] )
							. ' WHERE '
							. implode( ' OR ', $delete_predicates ),
						'Failed to delete DuckDB REPLACE conflicts'
					);
				}
			}

			$result = $this->execute_auto_increment_write(
				$replace_shape['table_name'],
				$this->translate_replace_tokens_to_duckdb_insert_sql( $tokens ),
				'Failed to execute DuckDB REPLACE',
				$tokens,
				$table_index
			);

			if ( $started_transaction ) {
				$this->connection->commit();
			}

			return $result;
		} catch ( Throwable $e ) {
			if ( $started_transaction && $this->connection->inTransaction() ) {
				$this->connection->rollback();
			}
			throw $e;
		}
	}

	/**
	 * Check whether any unique key includes a case-insensitive column.
	 *
	 * @param array<int,string[]> $unique_sets              Unique key column sets.
	 * @param array<string,bool>  $case_insensitive_columns Lowercase column-name map.
	 * @return bool Whether a case-insensitive unique key exists.
	 */
	private function has_case_insensitive_unique_key( array $unique_sets, array $case_insensitive_columns ): bool {
		foreach ( $unique_sets as $column_set ) {
			foreach ( $column_set as $column_name ) {
				if ( isset( $case_insensitive_columns[ strtolower( $column_name ) ] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Build a predicate that matches an incoming row against a supplied unique key.
	 *
	 * @param string[]             $column_set               Unique key columns.
	 * @param array<string,string> $values_by_column         Inserted values keyed by lowercase column name.
	 * @param array<string,bool>   $case_insensitive_columns Lowercase case-insensitive column-name map.
	 * @return string|null SQL predicate, or null when the incoming row does not supply every key column.
	 */
	private function unique_key_conflict_predicate( array $column_set, array $values_by_column, array $case_insensitive_columns ): ?string {
		$where = array();
		foreach ( $column_set as $column_name ) {
			$column_key = strtolower( $column_name );
			if ( ! array_key_exists( $column_key, $values_by_column ) ) {
				return null;
			}

			$value_sql = $values_by_column[ $column_key ];
			if ( isset( $case_insensitive_columns[ $column_key ] ) ) {
				$where[] = 'lower('
					. $this->connection->quote_identifier( $column_name )
					. ') IS NOT DISTINCT FROM lower(CAST(('
					. $value_sql
					. ') AS VARCHAR))';
				continue;
			}

			$where[] = $this->connection->quote_identifier( $column_name )
				. ' IS NOT DISTINCT FROM ('
				. $value_sql
				. ')';
		}

		return implode( ' AND ', $where );
	}

	/**
	 * Select the conflict target that MySQL would hit for a single inserted row.
	 *
	 * @param string               $table_name       Table name.
	 * @param array<string,string> $values_by_column Inserted values keyed by lowercase column name.
	 * @return string[] Conflict target columns.
	 */
	private function select_on_duplicate_conflict_target( string $table_name, array $values_by_column ): array {
		$eligible_targets = array();
		$matched_targets  = array();

		foreach ( $this->unique_key_column_sets( $table_name ) as $column_set ) {
			$has_all_values = true;
			foreach ( $column_set as $column_name ) {
				if ( ! array_key_exists( strtolower( $column_name ), $values_by_column ) ) {
					$has_all_values = false;
					break;
				}
			}

			if ( ! $has_all_values ) {
				continue;
			}

			$eligible_targets[] = $column_set;
			if ( $this->insert_values_conflict_with_target( $table_name, $column_set, $values_by_column ) ) {
				$matched_targets[] = $column_set;
			}
		}

		if ( 1 === count( $matched_targets ) ) {
			return $matched_targets[0];
		}
		if ( count( $matched_targets ) > 1 ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported INSERT ... ON DUPLICATE KEY UPDATE statement in DuckDB driver. Insert values match multiple unique key targets.' );
		}

		if ( count( $eligible_targets ) > 0 ) {
			return $eligible_targets[0];
		}

		throw new WP_DuckDB_Driver_Exception( 'Unsupported INSERT ... ON DUPLICATE KEY UPDATE statement in DuckDB driver. Insert values do not include a unique key target.' );
	}

	/**
	 * Read primary and unique secondary key column sets.
	 *
	 * @param string $table_name Table name.
	 * @return array<int,string[]>
	 */
	private function unique_key_column_sets( string $table_name ): array {
		$sets = array();

		$primary = $this->execute_duckdb_query(
			'SELECT name FROM pragma_table_info(' . $this->connection->quote( $table_name ) . ') WHERE pk > 0 ORDER BY pk',
			'Failed to inspect DuckDB primary key'
		)->fetchAll( PDO::FETCH_ASSOC );
		if ( count( $primary ) > 0 ) {
			$sets[] = array_map(
				function ( array $row ): string {
					return (string) $row['name'];
				},
				$primary
			);
		}

		$this->ensure_index_metadata_table();
		$secondary = $this->execute_duckdb_query(
			'SELECT index_name, column_name FROM '
				. $this->connection->quote_identifier( self::INDEX_METADATA_TABLE )
				. ' WHERE table_name = '
				. $this->connection->quote( $table_name )
				. ' AND non_unique = 0 ORDER BY index_name, seq_in_index',
			'Failed to inspect DuckDB unique indexes'
		)->fetchAll( PDO::FETCH_ASSOC );

		$secondary_sets = array();
		foreach ( $secondary as $row ) {
			$index_name                      = (string) $row['index_name'];
			$secondary_sets[ $index_name ][] = (string) $row['column_name'];
		}

		foreach ( $secondary_sets as $columns ) {
			$sets[] = $columns;
		}

		return $sets;
	}

	/**
	 * Determine whether the inserted row conflicts with a unique target.
	 *
	 * @param string               $table_name       Table name.
	 * @param string[]             $column_set       Unique key columns.
	 * @param array<string,string> $values_by_column Inserted values keyed by lowercase column name.
	 * @return bool Whether an existing row matches the target values.
	 */
	private function insert_values_conflict_with_target( string $table_name, array $column_set, array $values_by_column ): bool {
		$where                    = array();
		$case_insensitive_columns = $this->case_insensitive_column_names( $table_name );
		foreach ( $column_set as $column_name ) {
			$value_sql = $values_by_column[ strtolower( $column_name ) ];
			if ( isset( $case_insensitive_columns[ strtolower( $column_name ) ] ) ) {
				$where[] = 'lower('
					. $this->connection->quote_identifier( $column_name )
					. ') IS NOT DISTINCT FROM lower(CAST(('
					. $value_sql
					. ') AS VARCHAR))';
				continue;
			}

			$where[] = $this->connection->quote_identifier( $column_name )
				. ' IS NOT DISTINCT FROM ('
				. $value_sql
				. ')';
		}

		$stmt = $this->execute_duckdb_query(
			'SELECT 1 FROM '
				. $this->connection->quote_identifier( $table_name )
				. ' WHERE '
				. implode( ' AND ', $where )
				. ' LIMIT 1',
			'Failed to inspect DuckDB duplicate key target'
		);

		return false !== $stmt->fetch( PDO::FETCH_NUM );
	}

	/**
	 * Read case-insensitive MySQL-facing text columns for a table.
	 *
	 * @param string $table_name Table name.
	 * @return array<string,bool> Lowercase column-name map.
	 */
	private function case_insensitive_column_names( string $table_name ): array {
		$columns         = array();
		$table_collation = $this->table_default_collation( $table_name );
		foreach ( $this->column_metadata_rows( $table_name ) as $row ) {
			if ( ! array_key_exists( 'column_name', $row ) || ! array_key_exists( 'collation_name', $row ) ) {
				continue;
			}
			$collation_name = null === $row['collation_name'] ? null : (string) $row['collation_name'];
			if ( 'utf8mb4_0900_ai_ci' === strtolower( (string) $collation_name ) && ! $this->mysql_collation_is_case_insensitive( $table_collation ) ) {
				continue;
			}
			if ( $this->mysql_collation_is_case_insensitive( $collation_name ) ) {
				$columns[ strtolower( (string) $row['column_name'] ) ] = true;
			}
		}

		return $columns;
	}

	/**
	 * Translate an ODKU update list, rewriting MySQL VALUES(col) references.
	 *
	 * @param WP_Parser_Token[] $tokens Update-list tokens after ON DUPLICATE KEY UPDATE.
	 * @return string DuckDB SQL.
	 */
	private function translate_on_duplicate_update_tokens_to_duckdb_sql( array $tokens ): string {
		$pieces = array();

		for ( $index = 0; $index < count( $tokens ); ++$index ) {
			if (
				isset( $tokens[ $index + 3 ] )
				&& WP_MySQL_Lexer::VALUES_SYMBOL === $tokens[ $index ]->id
				&& WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $index + 1 ]->id
				&& WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $index + 3 ]->id
			) {
				$pieces[] = 'excluded';
				$pieces[] = '.';
				$pieces[] = $this->connection->quote_identifier( $this->identifier_value( $tokens[ $index + 2 ] ) );
				$index   += 3;
				continue;
			}

			$pieces[] = $this->translate_tokens_to_duckdb_sql( array( $tokens[ $index ] ) );
		}

		return $this->join_sql_pieces( $pieces );
	}

	/**
	 * Initialize DuckDB macros that emulate simple MySQL functions.
	 */
	private function initialize_session_macros(): void {
		try {
			$this->connection->query( 'CREATE OR REPLACE MACRO date_format(d, f) AS strftime(d, f)' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			throw new WP_DuckDB_Driver_Exception( 'Failed to initialize DuckDB MySQL compatibility macros: ' . $e->getMessage(), 0, $e );
		}
	}

	/**
	 * Check whether a token is a regexp operator.
	 *
	 * @param WP_Parser_Token $token Token.
	 * @return bool
	 */
	private function is_regexp_operator( WP_Parser_Token $token ): bool {
		return WP_MySQL_Lexer::REGEXP_SYMBOL === $token->id || WP_MySQL_Lexer::RLIKE_SYMBOL === $token->id;
	}

	/**
	 * Translate a simple regexp predicate.
	 *
	 * @param string[]        $pieces  SQL pieces translated so far.
	 * @param WP_Parser_Token $pattern Pattern token.
	 * @param bool            $negated Whether this is NOT REGEXP.
	 * @return string
	 */
	private function translate_regexp_predicate( array &$pieces, WP_Parser_Token $pattern, bool $negated ): string {
		if ( count( $pieces ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( 'REGEXP requires a left-hand expression in the DuckDB driver.' );
		}

		$left      = array_pop( $pieces );
		$predicate = sprintf(
			'regexp_matches(%s, %s)',
			$left,
			$this->translate_token_to_duckdb_sql( $pattern )
		);

		return $negated ? 'NOT ' . $predicate : $predicate;
	}

	/**
	 * Translate a single token to DuckDB SQL.
	 *
	 * @param WP_Parser_Token $token Token.
	 * @return string
	 */
	private function translate_token_to_duckdb_sql( WP_Parser_Token $token ): string {
		if ( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id ) {
			return $this->connection->quote_identifier( $token->get_value() );
		}

		if ( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id || WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $token->id ) {
			return $this->connection->quote( $token->get_value() );
		}

		return $token->get_bytes();
	}

	/**
	 * Translate MySQL's numeric column coercion around UNIX_TIMESTAMP().
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Current index, advanced on match.
	 * @return string|null Translated comparison, or null when the pattern does not match.
	 */
	private function translate_unix_timestamp_comparison( array $tokens, int &$index ): ?string {
		$left_tokens    = array();
		$operator_index = $index + 1;
		if (
			isset( $tokens[ $index + 2 ] )
			&& WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $index + 1 ]->id
		) {
			$left_tokens    = array( $tokens[ $index ], $tokens[ $index + 1 ], $tokens[ $index + 2 ] );
			$operator_index = $index + 3;
		} elseif ( isset( $tokens[ $index ] ) && ! $this->is_non_identifier_token( $tokens[ $index ] ) ) {
			$left_tokens = array( $tokens[ $index ] );
		}

		if (
			count( $left_tokens ) === 0
			|| ! isset( $tokens[ $operator_index + 3 ] )
			|| ! in_array(
				$tokens[ $operator_index ]->id,
				array(
					WP_MySQL_Lexer::LESS_THAN_OPERATOR,
					WP_MySQL_Lexer::LESS_OR_EQUAL_OPERATOR,
					WP_MySQL_Lexer::GREATER_THAN_OPERATOR,
					WP_MySQL_Lexer::GREATER_OR_EQUAL_OPERATOR,
					WP_MySQL_Lexer::EQUAL_OPERATOR,
				),
				true
			)
			|| ! $this->is_empty_function_call( $tokens, $operator_index + 1, 'UNIX_TIMESTAMP' )
		) {
			return null;
		}

		$left_sql = 1 === count( $left_tokens )
			? $this->connection->quote_identifier( $this->identifier_value( $left_tokens[0] ) )
			: $this->connection->quote_identifier( $this->identifier_value( $left_tokens[0] ) )
				. '.'
				. $this->connection->quote_identifier( $this->identifier_value( $left_tokens[2] ) );

		$index = $operator_index + 3;
		return 'TRY_CAST('
			. $left_sql
			. ' AS BIGINT) '
			. $tokens[ $operator_index ]->get_bytes()
			. ' CAST(epoch(current_timestamp) AS BIGINT)';
	}

	/**
	 * Translate MySQL's default backslash escape for simple LIKE predicates.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Current index, advanced on match.
	 * @return string|null Translated predicate, or null when the pattern does not match.
	 */
	private function translate_like_escape_predicate( array $tokens, int &$index ): ?string {
		if ( ! isset( $tokens[ $index ] ) || $this->is_non_identifier_token( $tokens[ $index ] ) ) {
			return null;
		}

		$operator_index = $index + 1;
		$left_tokens    = array( $tokens[ $index ] );
		if (
			isset( $tokens[ $index + 2 ] )
			&& WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $index + 1 ]->id
			&& ! $this->is_non_identifier_token( $tokens[ $index + 2 ] )
		) {
			$left_tokens    = array( $tokens[ $index ], $tokens[ $index + 2 ] );
			$operator_index = $index + 3;
		}

		if ( ! isset( $tokens[ $operator_index ] ) ) {
			return null;
		}

		$is_not_like   = false;
		$pattern_index = $operator_index + 1;
		if ( WP_MySQL_Lexer::LIKE_SYMBOL === $tokens[ $operator_index ]->id ) {
			$is_not_like = false;
		} elseif (
			in_array( $tokens[ $operator_index ]->id, array( WP_MySQL_Lexer::NOT_SYMBOL, WP_MySQL_Lexer::NOT2_SYMBOL ), true )
			&& isset( $tokens[ $operator_index + 1 ] )
			&& WP_MySQL_Lexer::LIKE_SYMBOL === $tokens[ $operator_index + 1 ]->id
		) {
			$is_not_like   = true;
			$pattern_index = $operator_index + 2;
		} else {
			return null;
		}

		if (
			! isset( $tokens[ $pattern_index ] )
			|| (
				WP_MySQL_Lexer::SINGLE_QUOTED_TEXT !== $tokens[ $pattern_index ]->id
				&& WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT !== $tokens[ $pattern_index ]->id
			)
			|| false === strpos( $tokens[ $pattern_index ]->get_value(), '\\' )
			|| ( isset( $tokens[ $pattern_index + 1 ] ) && WP_MySQL_Lexer::ESCAPE_SYMBOL === $tokens[ $pattern_index + 1 ]->id )
		) {
			return null;
		}

		$left_sql = 1 === count( $left_tokens )
			? $this->connection->quote_identifier( $this->identifier_value( $left_tokens[0] ) )
			: $this->connection->quote_identifier( $this->identifier_value( $left_tokens[0] ) )
				. '.'
				. $this->connection->quote_identifier( $this->identifier_value( $left_tokens[1] ) );

		$index = $pattern_index;
		return $left_sql
			. ( $is_not_like ? ' NOT LIKE ' : ' LIKE ' )
			. $this->connection->quote( $tokens[ $pattern_index ]->get_value() )
			. ' ESCAPE '
			. $this->connection->quote( '\\' );
	}

	/**
	 * Check if a token starts an empty function call by name.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Current index.
	 * @param string            $name   Function name.
	 * @return bool
	 */
	private function is_empty_function_call( array $tokens, int $index, string $name ): bool {
		return isset( $tokens[ $index + 2 ] )
			&& 0 === strcasecmp( $tokens[ $index ]->get_value(), $name )
			&& WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $index + 1 ]->id
			&& WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $index + 2 ]->id;
	}

	/**
	 * Join SQL pieces with conservative whitespace.
	 *
	 * @param string[] $pieces SQL pieces.
	 * @return string
	 */
	private function join_sql_pieces( array $pieces ): string {
		$sql      = '';
		$previous = null;

		foreach ( $pieces as $piece ) {
			if ( '' === $sql ) {
				$sql = $piece;
			} elseif ( in_array( $piece, array( ',', ')', ';' ), true ) ) {
				$sql = rtrim( $sql ) . $piece;
			} elseif ( '.' === $piece || '.' === $previous || '(' === $previous ) {
				$sql .= $piece;
			} elseif ( '(' === $piece ) {
				if ( $this->should_omit_space_before_open_parenthesis( $previous ) ) {
					$sql .= $piece;
				} else {
					$sql .= ' ' . $piece;
				}
			} else {
				$sql .= ' ' . $piece;
			}
			$previous = $piece;
		}

		return $sql;
	}

	/**
	 * Decide whether an open parenthesis belongs directly to the previous piece.
	 *
	 * @param string|null $previous Previous SQL piece.
	 * @return bool
	 */
	private function should_omit_space_before_open_parenthesis( ?string $previous ): bool {
		if ( null === $previous || ',' === $previous ) {
			return false;
		}

		if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$|^"[^"]+"$/', $previous ) ) {
			return false;
		}

		return ! in_array(
			strtoupper( trim( $previous, '"' ) ),
			array(
				'AND',
				'AS',
				'BY',
				'CREATE',
				'DELETE',
				'FROM',
				'GROUP',
				'IN',
				'INSERT',
				'INTO',
				'KEY',
				'LIMIT',
				'NOT',
				'OFFSET',
				'ON',
				'ORDER',
				'PRIMARY',
				'SELECT',
				'SET',
				'TABLE',
				'UPDATE',
				'VALUES',
				'WHERE',
			),
			true
		);
	}

	/**
	 * Execute an INSERT/REPLACE and record a generated auto-increment ID.
	 *
	 * @param string $table_name Table name.
	 * @param string $sql        DuckDB SQL.
	 * @param string $context    Failure context.
	 * @param array  $tokens     MySQL token stream.
	 * @param int    $table_index Index of the table token in $tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_auto_increment_write( string $table_name, string $sql, string $context, array $tokens = array(), ?int $table_index = null ): WP_DuckDB_Result_Statement {
		$table_reference    = $this->resolve_visible_user_table_reference( $table_name );
		$metadata           = null === $table_reference ? null : $this->auto_increment_metadata_for_table( $table_reference['table_name'], $table_reference['temporary'] );
		$sequence_name      = null === $metadata ? null : $metadata['sequence_name'];
		$explicit_insert_id = null === $metadata || null === $table_index
			? null
			: $this->explicit_auto_increment_value_for_write( $tokens, $table_index, $metadata['column_name'] );
		$before             = null === $sequence_name ? null : $this->sequence_currval( $sequence_name );
		$result             = $this->execute_duckdb_query( $sql, $context );

		if ( null !== $sequence_name && $result->rowCount() > 0 ) {
			$after = $this->sequence_currval( $sequence_name );
			if ( null !== $after && $after !== $before ) {
				$this->last_insert_id = $after;
			} elseif ( null !== $explicit_insert_id ) {
				$this->last_insert_id = $explicit_insert_id;
			}
		}

		return $result;
	}

	/**
	 * Find metadata for a table's recorded AUTO_INCREMENT column.
	 *
	 * @param string $table_name Table name.
	 * @return array{column_name:string,sequence_name:string}|null Metadata, or null when no AUTO_INCREMENT column is known.
	 */
	private function auto_increment_metadata_for_table( string $table_name, bool $temporary = false ): ?array {
		try {
			$stmt = $this->connection->query(
				'SELECT column_name FROM '
					. $this->connection->quote_identifier( $this->column_metadata_table_name( $temporary ) )
					. ' WHERE table_name = '
					. $this->connection->quote( $table_name )
					. " AND extra = 'auto_increment' ORDER BY ordinal_position LIMIT 1"
			);
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			return null;
		}

		$column_name = $stmt->fetchColumn();
		if ( false === $column_name || null === $column_name ) {
			return null;
		}

		$column_name = (string) $column_name;
		return array(
			'column_name'   => $column_name,
			'sequence_name' => $this->sequence_name( $table_name, $column_name, $temporary ),
		);
	}

	/**
	 * Find recorded AUTO_INCREMENT sequence names for a table.
	 *
	 * @param string $table_name Table name.
	 * @return string[] Sequence names.
	 */
	private function auto_increment_sequences_for_table( string $table_name, bool $temporary = false ): array {
		$this->ensure_column_metadata_table( $temporary );

		$stmt = $this->execute_duckdb_query(
			'SELECT column_name FROM '
				. $this->connection->quote_identifier( $this->column_metadata_table_name( $temporary ) )
				. ' WHERE table_name = '
				. $this->connection->quote( $table_name )
				. " AND extra = 'auto_increment' ORDER BY ordinal_position",
			'Failed to inspect DuckDB AUTO_INCREMENT metadata'
		);

		$sequence_names = array();
		foreach ( $stmt->fetchAll( PDO::FETCH_COLUMN ) as $column_name ) {
			$sequence_names[] = $this->sequence_name( $table_name, (string) $column_name, $temporary );
		}

		return $sequence_names;
	}

	/**
	 * Drop AUTO_INCREMENT sequences that are no longer referenced by a table.
	 *
	 * @param string[] $sequence_names Sequence names.
	 */
	private function drop_auto_increment_sequences( array $sequence_names ): void {
		foreach ( $sequence_names as $sequence_name ) {
			$this->execute_duckdb_query(
				'DROP SEQUENCE IF EXISTS ' . $this->connection->quote_identifier( $sequence_name ),
				'Failed to drop DuckDB AUTO_INCREMENT sequence'
			);
		}
	}

	/**
	 * Parse the last explicit literal assigned to an AUTO_INCREMENT column.
	 *
	 * @param WP_Parser_Token[] $tokens      MySQL token stream.
	 * @param int               $table_index Index of the table token.
	 * @param string            $column_name AUTO_INCREMENT column name.
	 * @return int|null Explicit value, or null when the statement uses generated/default values.
	 */
	private function explicit_auto_increment_value_for_write( array $tokens, int $table_index, string $column_name ): ?int {
		$set_index = $this->find_insert_set_index( $tokens, $table_index );
		if ( null !== $set_index ) {
			return $this->explicit_auto_increment_value_for_set_assignments( array_slice( $tokens, $set_index + 1 ), $column_name );
		}

		$index = $table_index + 1;
		if ( ! isset( $tokens[ $index ] ) || WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $index ]->id ) {
			return null;
		}

		list( $column_items, $index ) = $this->collect_parenthesized_items( $tokens, $index + 1 );
		$column_offset                = null;
		foreach ( $column_items as $offset => $column_tokens ) {
			if ( 1 === count( $column_tokens ) && 0 === strcasecmp( $this->identifier_value( $column_tokens[0] ), $column_name ) ) {
				$column_offset = $offset;
				break;
			}
		}

		if ( null === $column_offset || ! isset( $tokens[ $index ] ) || WP_MySQL_Lexer::VALUES_SYMBOL !== $tokens[ $index ]->id ) {
			return null;
		}
		++$index;

		$explicit_value = null;
		while ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $index ]->id ) {
			list( $value_items, $index ) = $this->collect_parenthesized_items( $tokens, $index + 1 );
			if ( isset( $value_items[ $column_offset ] ) ) {
				$value = $this->integer_literal_value( $value_items[ $column_offset ] );
				if ( null !== $value ) {
					$explicit_value = $value;
				}
			}

			if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $index ]->id ) {
				++$index;
				continue;
			}
			break;
		}

		return $explicit_value;
	}

	/**
	 * Parse an explicit AUTO_INCREMENT value from INSERT ... SET assignments.
	 *
	 * @param WP_Parser_Token[] $tokens      Assignment tokens after SET.
	 * @param string            $column_name AUTO_INCREMENT column name.
	 * @return int|null Explicit value, or null when absent/default.
	 */
	private function explicit_auto_increment_value_for_set_assignments( array $tokens, string $column_name ): ?int {
		$index = 0;

		while ( $index < count( $tokens ) ) {
			$current_column = $this->identifier_value( $tokens[ $index ] ?? null );
			++$index;

			if (
				! isset( $tokens[ $index ] )
				|| ( WP_MySQL_Lexer::EQUAL_OPERATOR !== $tokens[ $index ]->id && WP_MySQL_Lexer::ASSIGN_OPERATOR !== $tokens[ $index ]->id )
			) {
				return null;
			}
			++$index;

			$value_tokens = array();
			$depth        = 0;
			while ( $index < count( $tokens ) ) {
				if ( 0 === $depth && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $index ]->id ) {
					break;
				}
				if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $index ]->id ) {
					++$depth;
				} elseif ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $index ]->id ) {
					--$depth;
				}
				$value_tokens[] = $tokens[ $index ];
				++$index;
			}

			if ( 0 === strcasecmp( $current_column, $column_name ) ) {
				return $this->integer_literal_value( $value_tokens );
			}

			if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $index ]->id ) {
				++$index;
			}
		}

		return null;
	}

	/**
	 * Convert a tokenized integer literal to an int.
	 *
	 * @param WP_Parser_Token[] $tokens Literal tokens.
	 * @return int|null Integer value, or null for expressions/defaults.
	 */
	private function integer_literal_value( array $tokens ): ?int {
		if ( 1 === count( $tokens ) ) {
			$token = $tokens[0];
			if (
				in_array(
					$token->id,
					array(
						WP_MySQL_Lexer::INT_NUMBER,
						WP_MySQL_Lexer::LONG_NUMBER,
						WP_MySQL_Lexer::ULONGLONG_NUMBER,
					),
					true
				)
			) {
				return (int) $token->get_bytes();
			}
			if (
				( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id || WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $token->id )
				&& preg_match( '/^[+-]?\d+$/', $token->get_value() )
			) {
				return (int) $token->get_value();
			}
		}

		if (
			2 === count( $tokens )
			&& ( WP_MySQL_Lexer::MINUS_OPERATOR === $tokens[0]->id || WP_MySQL_Lexer::PLUS_OPERATOR === $tokens[0]->id )
			&& in_array(
				$tokens[1]->id,
				array(
					WP_MySQL_Lexer::INT_NUMBER,
					WP_MySQL_Lexer::LONG_NUMBER,
					WP_MySQL_Lexer::ULONGLONG_NUMBER,
				),
				true
			)
		) {
			return (int) ( ( WP_MySQL_Lexer::MINUS_OPERATOR === $tokens[0]->id ? '-' : '+' ) . $tokens[1]->get_bytes() );
		}

		return null;
	}

	/**
	 * Read the current value for a sequence, if one exists in this session.
	 *
	 * @param string $sequence_name Sequence name.
	 * @return int|null Current value.
	 */
	private function sequence_currval( string $sequence_name ): ?int {
		try {
			$stmt  = $this->connection->query( 'SELECT currval(' . $this->connection->quote( $sequence_name ) . ')' );
			$value = $stmt->fetchColumn();
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			return null;
		}

		return false === $value || null === $value ? null : (int) $value;
	}

	/**
	 * Prime a sequence so the next generated value matches MySQL AUTO_INCREMENT metadata.
	 *
	 * DuckDB exposes currval only after nextval has been called in the session.
	 *
	 * @param string $sequence_name Sequence name.
	 * @param int    $next_value    Desired next generated value.
	 */
	private function prime_auto_increment_sequence( string $sequence_name, int $next_value ): void {
		if ( $next_value <= 1 ) {
			return;
		}

		$this->execute_duckdb_query(
			'SELECT nextval(' . $this->connection->quote( $sequence_name ) . ')',
			'Failed to initialize DuckDB AUTO_INCREMENT sequence'
		);
	}

	/**
	 * Execute DuckDB SQL and preserve inspectable SQL output.
	 *
	 * @param string $sql     DuckDB SQL.
	 * @param string $context Failure context.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_duckdb_query( string $sql, string $context ): WP_DuckDB_Result_Statement {
		$this->last_duckdb_queries[] = $sql;

		try {
			return $this->connection->query( $sql );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			throw new WP_DuckDB_Driver_Exception( $context . ': ' . $e->getMessage(), 0, $e );
		}
	}

	/**
	 * Ensure the internal index metadata table exists.
	 */
	private function ensure_index_metadata_table( bool $temporary = false ): void {
		$this->execute_duckdb_query(
			'CREATE '
				. ( $temporary ? 'TEMP ' : '' )
				. 'TABLE IF NOT EXISTS '
				. $this->connection->quote_identifier( $this->index_metadata_table_name( $temporary ) )
				. ' (table_name VARCHAR, index_name VARCHAR, non_unique INTEGER, seq_in_index INTEGER, column_name VARCHAR, sub_part INTEGER)',
			'Failed to initialize DuckDB index metadata'
		);
	}

	/**
	 * Ensure the internal column metadata table exists.
	 */
	private function ensure_column_metadata_table( bool $temporary = false ): void {
		$this->execute_duckdb_query(
			'CREATE '
				. ( $temporary ? 'TEMP ' : '' )
				. 'TABLE IF NOT EXISTS '
				. $this->connection->quote_identifier( $this->column_metadata_table_name( $temporary ) )
				. ' (table_name VARCHAR, ordinal_position INTEGER, column_name VARCHAR, column_type VARCHAR, is_nullable VARCHAR, column_key VARCHAR, column_default VARCHAR, extra VARCHAR, collation_name VARCHAR, comment VARCHAR)',
			'Failed to initialize DuckDB column metadata'
		);
	}

	/**
	 * Ensure the internal table metadata table exists.
	 */
	private function ensure_table_metadata_table( bool $temporary = false ): void {
		$this->execute_duckdb_query(
			'CREATE '
				. ( $temporary ? 'TEMP ' : '' )
				. 'TABLE IF NOT EXISTS '
				. $this->connection->quote_identifier( $this->table_metadata_table_name( $temporary ) )
				. ' (table_name VARCHAR, engine VARCHAR, row_format VARCHAR, table_collation VARCHAR, table_comment VARCHAR, create_options VARCHAR, create_time VARCHAR)',
			'Failed to initialize DuckDB table metadata'
		);
	}

	/**
	 * Ensure the internal CHECK constraint metadata table exists.
	 */
	private function ensure_check_metadata_table( bool $temporary = false ): void {
		$this->execute_duckdb_query(
			'CREATE '
				. ( $temporary ? 'TEMP ' : '' )
				. 'TABLE IF NOT EXISTS '
				. $this->connection->quote_identifier( $this->check_metadata_table_name( $temporary ) )
				. ' (table_name VARCHAR, constraint_name VARCHAR, check_clause VARCHAR, enforced VARCHAR)',
			'Failed to initialize DuckDB CHECK constraint metadata'
		);
	}

	/**
	 * Ensure the internal FOREIGN KEY metadata table exists.
	 */
	private function ensure_foreign_key_metadata_table( bool $temporary = false ): void {
		$this->execute_duckdb_query(
			'CREATE '
				. ( $temporary ? 'TEMP ' : '' )
				. 'TABLE IF NOT EXISTS '
				. $this->connection->quote_identifier( $this->foreign_key_metadata_table_name( $temporary ) )
				. ' (table_name VARCHAR, constraint_name VARCHAR, ordinal_position INTEGER, column_name VARCHAR, referenced_table_name VARCHAR, referenced_column_name VARCHAR, update_rule VARCHAR, delete_rule VARCHAR)',
			'Failed to initialize DuckDB FOREIGN KEY metadata'
		);
	}

	/**
	 * Return the metadata table that stores secondary index rows.
	 *
	 * @param bool $temporary Whether to use session-local temporary metadata.
	 * @return string Metadata table name.
	 */
	private function index_metadata_table_name( bool $temporary ): string {
		return $temporary ? self::TEMP_INDEX_METADATA_TABLE : self::INDEX_METADATA_TABLE;
	}

	/**
	 * Return the metadata table that stores column rows.
	 *
	 * @param bool $temporary Whether to use session-local temporary metadata.
	 * @return string Metadata table name.
	 */
	private function column_metadata_table_name( bool $temporary ): string {
		return $temporary ? self::TEMP_COLUMN_METADATA_TABLE : self::COLUMN_METADATA_TABLE;
	}

	/**
	 * Return the metadata table that stores table rows.
	 *
	 * @param bool $temporary Whether to use session-local temporary metadata.
	 * @return string Metadata table name.
	 */
	private function table_metadata_table_name( bool $temporary ): string {
		return $temporary ? self::TEMP_TABLE_METADATA_TABLE : self::TABLE_METADATA_TABLE;
	}

	/**
	 * Return the metadata table that stores CHECK constraint rows.
	 *
	 * @param bool $temporary Whether to use session-local temporary metadata.
	 * @return string Metadata table name.
	 */
	private function check_metadata_table_name( bool $temporary ): string {
		return $temporary ? self::TEMP_CHECK_METADATA_TABLE : self::CHECK_METADATA_TABLE;
	}

	/**
	 * Return the metadata table that stores FOREIGN KEY constraint rows.
	 *
	 * @param bool $temporary Whether to use session-local temporary metadata.
	 * @return string Metadata table name.
	 */
	private function foreign_key_metadata_table_name( bool $temporary ): string {
		return $temporary ? self::TEMP_FOREIGN_KEY_METADATA_TABLE : self::FOREIGN_KEY_METADATA_TABLE;
	}

	/**
	 * Record MySQL index metadata for SHOW INDEX.
	 *
	 * @param array{table_name:string,index_name:string,unique:bool,temporary?:bool,columns:array<int,array{name:string,sub_part:int|null}>} $index_definition Index definition.
	 */
	private function record_index_metadata( array $index_definition ): void {
		$temporary = isset( $index_definition['temporary'] ) && (bool) $index_definition['temporary'];
		$this->ensure_index_metadata_table( $temporary );

		$table_name = $index_definition['table_name'];
		$index_name = $index_definition['index_name'];

		$this->execute_duckdb_query(
			'DELETE FROM '
				. $this->connection->quote_identifier( $this->index_metadata_table_name( $temporary ) )
				. ' WHERE table_name = '
				. $this->connection->quote( $table_name )
				. ' AND index_name = '
				. $this->connection->quote( $index_name ),
			'Failed to reset DuckDB index metadata'
		);

		foreach ( $index_definition['columns'] as $offset => $column ) {
			$this->execute_duckdb_query(
				'INSERT INTO '
					. $this->connection->quote_identifier( $this->index_metadata_table_name( $temporary ) )
					. ' (table_name, index_name, non_unique, seq_in_index, column_name, sub_part) VALUES ('
					. $this->connection->quote( $table_name )
					. ', '
					. $this->connection->quote( $index_name )
					. ', '
					. ( $index_definition['unique'] ? '0' : '1' )
					. ', '
					. ( $offset + 1 )
					. ', '
					. $this->connection->quote( $column['name'] )
					. ', '
					. $this->connection->quote( $column['sub_part'] )
					. ')',
				'Failed to store DuckDB index metadata'
			);
		}
	}

	/**
	 * Execute a schema change while temporarily dropping secondary indexes.
	 *
	 * @param string   $table_name Table name.
	 * @param callable $callback   Schema change callback.
	 */
	private function execute_with_secondary_indexes_rebuilt( string $table_name, callable $callback, bool $temporary = false ): void {
		$index_definitions = $this->secondary_index_definitions_for_table( $table_name, $temporary );
		foreach ( $index_definitions as $index_definition ) {
			$this->execute_duckdb_query(
				'DROP INDEX IF EXISTS ' . $this->connection->quote_identifier( $this->index_name( $table_name, $index_definition['index_name'], $temporary ) ),
				'Failed to drop DuckDB index before schema change'
			);
		}

		$exception = null;
		try {
			$callback();
		} catch ( Throwable $e ) {
			$exception = $e;
		}

		foreach ( $index_definitions as $index_definition ) {
			$this->execute_duckdb_query( $index_definition['sql'], 'Failed to recreate DuckDB index after schema change' );
			$this->record_index_metadata( $index_definition );
		}

		if ( null !== $exception ) {
			throw $exception;
		}
	}

	/**
	 * Read recorded secondary index definitions for a table.
	 *
	 * @param string $table_name Table name.
	 * @return array<int,array{sql:string,table_name:string,index_name:string,unique:bool,columns:array<int,array{name:string,sub_part:int|null}>}>
	 */
	private function secondary_index_definitions_for_table( string $table_name, bool $temporary = false ): array {
		$this->ensure_index_metadata_table( $temporary );

		$stmt = $this->execute_duckdb_query(
			'SELECT index_name, non_unique, seq_in_index, column_name, sub_part FROM '
				. $this->connection->quote_identifier( $this->index_metadata_table_name( $temporary ) )
				. ' WHERE table_name = '
				. $this->connection->quote( $table_name )
				. ' ORDER BY index_name, seq_in_index',
			'Failed to inspect DuckDB secondary indexes'
		);

		$grouped = array();
		foreach ( $stmt->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
			$index_name = (string) $row['index_name'];
			if ( ! isset( $grouped[ $index_name ] ) ) {
				$grouped[ $index_name ] = array(
					'unique'  => 0 === (int) $row['non_unique'],
					'columns' => array(),
				);
			}
			$grouped[ $index_name ]['columns'][] = array(
				'name'     => (string) $row['column_name'],
				'sub_part' => null === $row['sub_part'] ? null : (int) $row['sub_part'],
			);
		}

		$definitions = array();
		foreach ( $grouped as $index_name => $definition ) {
			$definitions[] = $this->build_secondary_index_definition(
				$table_name,
				$index_name,
				$definition['unique'],
				array_map(
					function ( array $column ): string {
						return $this->connection->quote_identifier( $column['name'] );
					},
					$definition['columns']
				),
				$definition['columns'],
				$temporary
			);
		}

		return $definitions;
	}

	/**
	 * Record MySQL column metadata for DESCRIBE and SHOW COLUMNS.
	 *
	 * @param string                         $table_name Table name.
	 * @param array<int,array<string,mixed>> $metadata   Column metadata.
	 */
	private function record_column_metadata( string $table_name, array $metadata, bool $temporary = false ): void {
		$this->ensure_column_metadata_table( $temporary );

		$this->execute_duckdb_query(
			'DELETE FROM '
				. $this->connection->quote_identifier( $this->column_metadata_table_name( $temporary ) )
				. ' WHERE table_name = '
				. $this->connection->quote( $table_name ),
			'Failed to reset DuckDB column metadata'
		);

		foreach ( $metadata as $offset => $column ) {
			$this->execute_duckdb_query(
				'INSERT INTO '
					. $this->connection->quote_identifier( $this->column_metadata_table_name( $temporary ) )
					. ' (table_name, ordinal_position, column_name, column_type, is_nullable, column_key, column_default, extra, collation_name, comment) VALUES ('
					. $this->connection->quote( $table_name )
					. ', '
					. ( $offset + 1 )
					. ', '
					. $this->connection->quote( $column['column_name'] )
					. ', '
					. $this->connection->quote( $column['column_type'] )
					. ', '
					. $this->connection->quote( $column['is_nullable'] )
					. ', '
					. $this->connection->quote( $column['column_key'] )
					. ', '
					. $this->connection->quote( $column['column_default'] )
					. ', '
					. $this->connection->quote( $column['extra'] )
					. ', '
					. $this->connection->quote( $column['collation_name'] )
					. ', '
					. $this->connection->quote( $column['comment'] )
					. ')',
				'Failed to store DuckDB column metadata'
			);
		}
	}

	/**
	 * Record MySQL table metadata for information_schema.tables.
	 *
	 * @param string              $table_name Table name.
	 * @param array<string,mixed> $metadata   Table metadata.
	 */
	private function record_table_metadata( string $table_name, array $metadata, bool $temporary = false ): void {
		$this->ensure_table_metadata_table( $temporary );

		$this->execute_duckdb_query(
			'DELETE FROM '
				. $this->connection->quote_identifier( $this->table_metadata_table_name( $temporary ) )
				. ' WHERE table_name = '
				. $this->connection->quote( $table_name ),
			'Failed to reset DuckDB table metadata'
		);

		$this->execute_duckdb_query(
			'INSERT INTO '
				. $this->connection->quote_identifier( $this->table_metadata_table_name( $temporary ) )
				. ' (table_name, engine, row_format, table_collation, table_comment, create_options, create_time) VALUES ('
				. $this->connection->quote( $table_name )
				. ', '
				. $this->connection->quote( $metadata['engine'] )
				. ', '
				. $this->connection->quote( $metadata['row_format'] )
				. ', '
				. $this->connection->quote( $metadata['table_collation'] )
				. ', '
				. $this->connection->quote( $metadata['table_comment'] )
				. ', '
				. $this->connection->quote( $metadata['create_options'] )
				. ', '
				. $this->connection->quote( gmdate( 'Y-m-d H:i:s' ) )
				. ')',
			'Failed to store DuckDB table metadata'
		);
	}

	/**
	 * Record MySQL CHECK constraint metadata for SHOW CREATE TABLE.
	 *
	 * @param string                  $table_name Table name.
	 * @param array<int,array<string,string>> $metadata   CHECK metadata rows.
	 * @param bool                    $temporary  Whether the target is a temporary table.
	 */
	private function record_check_metadata( string $table_name, array $metadata, bool $temporary = false ): void {
		$this->ensure_check_metadata_table( $temporary );

		$this->execute_duckdb_query(
			'DELETE FROM '
				. $this->connection->quote_identifier( $this->check_metadata_table_name( $temporary ) )
				. ' WHERE table_name = '
				. $this->connection->quote( $table_name ),
			'Failed to reset DuckDB CHECK constraint metadata'
		);

		foreach ( $metadata as $constraint ) {
			$this->execute_duckdb_query(
				'INSERT INTO '
					. $this->connection->quote_identifier( $this->check_metadata_table_name( $temporary ) )
					. ' (table_name, constraint_name, check_clause, enforced) VALUES ('
					. $this->connection->quote( $table_name )
					. ', '
					. $this->connection->quote( $constraint['constraint_name'] )
					. ', '
					. $this->connection->quote( $constraint['check_clause'] )
					. ', '
					. $this->connection->quote( $constraint['enforced'] )
					. ')',
				'Failed to store DuckDB CHECK constraint metadata'
			);
		}
	}

	/**
	 * Record MySQL FOREIGN KEY constraint metadata.
	 *
	 * @param string                         $table_name Table name.
	 * @param array<int,array<string,mixed>> $metadata   FOREIGN KEY metadata rows.
	 * @param bool                           $temporary  Whether the target is a temporary table.
	 */
	private function record_foreign_key_metadata( string $table_name, array $metadata, bool $temporary = false ): void {
		$this->ensure_foreign_key_metadata_table( $temporary );

		$this->execute_duckdb_query(
			'DELETE FROM '
				. $this->connection->quote_identifier( $this->foreign_key_metadata_table_name( $temporary ) )
				. ' WHERE table_name = '
				. $this->connection->quote( $table_name ),
			'Failed to reset DuckDB FOREIGN KEY metadata'
		);

		foreach ( $metadata as $constraint ) {
			foreach ( $constraint['columns'] as $offset => $column_name ) {
				$this->execute_duckdb_query(
					'INSERT INTO '
						. $this->connection->quote_identifier( $this->foreign_key_metadata_table_name( $temporary ) )
						. ' (table_name, constraint_name, ordinal_position, column_name, referenced_table_name, referenced_column_name, update_rule, delete_rule) VALUES ('
						. $this->connection->quote( $table_name )
						. ', '
						. $this->connection->quote( $constraint['constraint_name'] )
						. ', '
						. ( $offset + 1 )
						. ', '
						. $this->connection->quote( $column_name )
						. ', '
						. $this->connection->quote( $constraint['referenced_table_name'] )
						. ', '
						. $this->connection->quote( $constraint['referenced_columns'][ $offset ] )
						. ', '
						. $this->connection->quote( $constraint['update_rule'] )
						. ', '
						. $this->connection->quote( $constraint['delete_rule'] )
						. ')',
					'Failed to store DuckDB FOREIGN KEY metadata'
				);
			}
		}
	}

	/**
	 * Read recorded MySQL CHECK constraint metadata.
	 *
	 * @param string $table_name Table name.
	 * @param bool   $temporary  Whether to use session-local temporary metadata.
	 * @return array<int,array<string,mixed>>
	 */
	private function check_constraint_metadata_rows( string $table_name, bool $temporary = false ): array {
		$this->ensure_check_metadata_table( $temporary );

		$stmt = $this->execute_duckdb_query(
			'SELECT constraint_name, check_clause, enforced FROM '
				. $this->connection->quote_identifier( $this->check_metadata_table_name( $temporary ) )
				. ' WHERE table_name = '
				. $this->connection->quote( $table_name )
				. ' ORDER BY constraint_name',
			'Failed to inspect DuckDB CHECK constraint metadata'
		);

		return $stmt->fetchAll( PDO::FETCH_ASSOC );
	}

	/**
	 * Read recorded MySQL FOREIGN KEY constraint metadata.
	 *
	 * @param string $table_name Table name.
	 * @param bool   $temporary  Whether to use session-local temporary metadata.
	 * @return array<int,array<string,mixed>>
	 */
	private function foreign_key_metadata_rows( string $table_name, bool $temporary = false ): array {
		$this->ensure_foreign_key_metadata_table( $temporary );

		$stmt = $this->execute_duckdb_query(
			'SELECT constraint_name, ordinal_position, column_name, referenced_table_name, referenced_column_name, update_rule, delete_rule FROM '
				. $this->connection->quote_identifier( $this->foreign_key_metadata_table_name( $temporary ) )
				. ' WHERE table_name = '
				. $this->connection->quote( $table_name )
				. ' ORDER BY constraint_name, ordinal_position',
			'Failed to inspect DuckDB FOREIGN KEY metadata'
		);

		return $stmt->fetchAll( PDO::FETCH_ASSOC );
	}

	/**
	 * Append one MySQL column metadata row when full table metadata is already recorded.
	 *
	 * @param string              $table_name Table name.
	 * @param array<string,mixed> $column     Column metadata.
	 */
	private function append_column_metadata( string $table_name, array $column, bool $temporary = false ): void {
		$this->ensure_column_metadata_table( $temporary );

		$stmt = $this->execute_duckdb_query(
			'SELECT COUNT(*) AS column_count, COALESCE(MAX(ordinal_position), 0) AS max_ordinal FROM '
				. $this->connection->quote_identifier( $this->column_metadata_table_name( $temporary ) )
				. ' WHERE table_name = '
				. $this->connection->quote( $table_name ),
			'Failed to inspect DuckDB column metadata'
		);
		$row  = $stmt->fetch( PDO::FETCH_ASSOC );
		if ( ! is_array( $row ) || 0 === (int) $row['column_count'] ) {
			return;
		}

		$this->execute_duckdb_query(
			'INSERT INTO '
				. $this->connection->quote_identifier( $this->column_metadata_table_name( $temporary ) )
				. ' (table_name, ordinal_position, column_name, column_type, is_nullable, column_key, column_default, extra, collation_name, comment) VALUES ('
				. $this->connection->quote( $table_name )
				. ', '
				. ( (int) $row['max_ordinal'] + 1 )
				. ', '
				. $this->connection->quote( $column['column_name'] )
				. ', '
				. $this->connection->quote( $column['column_type'] )
				. ', '
				. $this->connection->quote( $column['is_nullable'] )
				. ', '
				. $this->connection->quote( $column['column_key'] )
				. ', '
				. $this->connection->quote( $column['column_default'] )
				. ', '
				. $this->connection->quote( $column['extra'] )
				. ', '
				. $this->connection->quote( $column['collation_name'] )
				. ', '
				. $this->connection->quote( $column['comment'] )
				. ')',
			'Failed to store DuckDB column metadata'
		);
	}

	/**
	 * Drop a recorded secondary index and refresh MySQL-facing metadata.
	 *
	 * @param string $table_name       Table name.
	 * @param string $mysql_index_name MySQL-facing index name.
	 */
	private function drop_secondary_index( string $table_name, string $mysql_index_name, bool $temporary = false ): void {
		if ( 0 === strcasecmp( $mysql_index_name, 'PRIMARY' ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported DROP INDEX statement in DuckDB driver. Dropping PRIMARY requires a table rebuild.' );
		}

		$resolved_index_name = $this->resolve_secondary_index_name( $table_name, $mysql_index_name, $temporary );
		if ( null === $resolved_index_name ) {
			throw new WP_DuckDB_Driver_Exception( "Unknown index '{$mysql_index_name}' on table '{$this->database}.{$table_name}' in DuckDB driver." );
		}

		$this->drop_physical_secondary_index( $table_name, $resolved_index_name, false, $temporary );
		$this->delete_index_metadata( $table_name, $resolved_index_name, $temporary );
		$this->refresh_column_key_metadata( $table_name, $temporary );
	}

	/**
	 * Resolve a secondary index name from recorded metadata.
	 *
	 * @param string $table_name       Table name.
	 * @param string $mysql_index_name Requested index name.
	 * @return string|null Resolved index name.
	 */
	private function resolve_secondary_index_name( string $table_name, string $mysql_index_name, bool $temporary = false ): ?string {
		foreach ( $this->secondary_index_definitions_for_table( $table_name, $temporary ) as $index_definition ) {
			if ( 0 === strcasecmp( $index_definition['index_name'], $mysql_index_name ) ) {
				return $index_definition['index_name'];
			}
		}

		return null;
	}

	/**
	 * Drop a physical DuckDB secondary index by its MySQL-facing name.
	 *
	 * @param string $table_name       Table name.
	 * @param string $mysql_index_name MySQL-facing index name.
	 * @param bool   $if_exists        Whether to use IF EXISTS.
	 */
	private function drop_physical_secondary_index( string $table_name, string $mysql_index_name, bool $if_exists, bool $temporary = false ): void {
		$this->execute_duckdb_query(
			'DROP INDEX '
				. ( $if_exists ? 'IF EXISTS ' : '' )
				. $this->connection->quote_identifier( $this->index_name( $table_name, $mysql_index_name, $temporary ) ),
			'Failed to drop DuckDB index'
		);
	}

	/**
	 * Delete one secondary index's metadata rows.
	 *
	 * @param string $table_name Table name.
	 * @param string $index_name Index name.
	 */
	private function delete_index_metadata( string $table_name, string $index_name, bool $temporary = false ): void {
		$this->ensure_index_metadata_table( $temporary );

		$this->execute_duckdb_query(
			'DELETE FROM '
				. $this->connection->quote_identifier( $this->index_metadata_table_name( $temporary ) )
				. ' WHERE table_name = '
				. $this->connection->quote( $table_name )
				. ' AND index_name = '
				. $this->connection->quote( $index_name ),
			'Failed to delete DuckDB index metadata'
		);
	}

	/**
	 * Delete all stored lifecycle metadata for a table.
	 *
	 * @param string $table_name Table name.
	 */
	private function delete_table_lifecycle_metadata( string $table_name, bool $temporary = false ): void {
		$this->ensure_index_metadata_table( $temporary );
		$this->ensure_column_metadata_table( $temporary );
		$this->ensure_table_metadata_table( $temporary );
		$this->ensure_check_metadata_table( $temporary );
		$this->ensure_foreign_key_metadata_table( $temporary );

		foreach (
			array(
				$this->index_metadata_table_name( $temporary )       => 'index',
				$this->column_metadata_table_name( $temporary )      => 'column',
				$this->table_metadata_table_name( $temporary )       => 'table',
				$this->check_metadata_table_name( $temporary )       => 'CHECK constraint',
				$this->foreign_key_metadata_table_name( $temporary ) => 'FOREIGN KEY',
			) as $metadata_table => $label
		) {
			$this->execute_duckdb_query(
				'DELETE FROM '
					. $this->connection->quote_identifier( $metadata_table )
					. ' WHERE table_name = '
					. $this->connection->quote( $table_name ),
				'Failed to delete DuckDB ' . $label . ' metadata'
			);
		}
	}

	/**
	 * Refresh stored COLUMN_KEY values after index metadata changes.
	 *
	 * @param string $table_name Table name.
	 */
	private function refresh_column_key_metadata( string $table_name, bool $temporary = false ): void {
		$metadata = $this->column_metadata_rows( $table_name, $temporary );
		if ( count( $metadata ) === 0 ) {
			return;
		}

		foreach ( $metadata as &$column ) {
			$column['column_key'] = '';
		}
		unset( $column );

		$primary_key = array_map(
			function ( array $row ): string {
				return (string) $row[4];
			},
			$this->primary_key_index_rows( $table_name )
		);

		$metadata_table = $this->connection->quote_identifier( $this->column_metadata_table_name( $temporary ) );
		$metadata       = $this->apply_column_key_metadata(
			$metadata,
			$primary_key,
			$this->secondary_index_definitions_for_table( $table_name, $temporary )
		);

		foreach ( $metadata as $column ) {
			$this->execute_duckdb_query(
				'UPDATE '
					. $metadata_table
					. ' SET column_key = '
					. $this->connection->quote( $column['column_key'] )
					. ' WHERE table_name = '
					. $this->connection->quote( $table_name )
					. ' AND column_name = '
					. $this->connection->quote( $column['column_name'] ),
				'Failed to refresh DuckDB column metadata'
			);
		}
	}

	/**
	 * Rebuild an empty table so AUTO_INCREMENT starts from 1 again.
	 *
	 * DuckDB cannot restart a sequence while a column default depends on it.
	 *
	 * @param string $table_name Table name.
	 */
	private function rebuild_empty_auto_increment_table( string $table_name, bool $temporary = false ): void {
		$create_sql     = $this->mysql_create_table_statement( $table_name, $table_name, $temporary, 1 );
		$sequence_names = $this->auto_increment_sequences_for_table( $table_name, $temporary );

		$this->execute_duckdb_query(
			'DROP TABLE ' . $this->connection->quote_identifier( $table_name ),
			'Failed to truncate DuckDB table'
		);
		$this->drop_auto_increment_sequences( $sequence_names );
		$this->execute_create_table( $this->tokenize_and_validate( $create_sql ) );
	}

	/**
	 * Rebuild a table so its AUTO_INCREMENT sequence has a specific next value.
	 *
	 * @param string $table_name Table name.
	 * @param int    $next_value Desired next generated value.
	 * @param bool   $temporary  Whether the target is a temporary table.
	 */
	private function rebuild_auto_increment_table_with_next_value( string $table_name, int $next_value, bool $temporary = false ): void {
		$create_sql     = $this->mysql_create_table_statement( $table_name, $table_name, $temporary, $next_value );
		$sequence_names = $this->auto_increment_sequences_for_table( $table_name, $temporary );
		$metadata_rows  = $this->table_column_metadata_rows( $table_name, $temporary );
		$column_names   = array_map(
			function ( array $column ): string {
				return (string) $column['column_name'];
			},
			$metadata_rows
		);
		$quoted_columns = implode(
			', ',
			array_map(
				function ( string $column_name ): string {
					return $this->connection->quote_identifier( $column_name );
				},
				$column_names
			)
		);
		$backup_table   = '__wp_duckdb_auto_increment_backup_' . substr( hash( 'sha256', $table_name . "\0" . microtime( true ) . "\0" . mt_rand() ), 0, 16 );

		$this->execute_duckdb_query(
			'DROP TABLE IF EXISTS ' . $this->connection->quote_identifier( $backup_table ),
			'Failed to reset DuckDB AUTO_INCREMENT rebuild backup table'
		);
		$this->execute_duckdb_query(
			'CREATE TEMP TABLE '
				. $this->connection->quote_identifier( $backup_table )
				. ' AS SELECT '
				. $quoted_columns
				. ' FROM '
				. $this->connection->quote_identifier( $table_name ),
			'Failed to back up DuckDB table for AUTO_INCREMENT rebuild'
		);
		$this->execute_duckdb_query(
			'DROP TABLE ' . $this->connection->quote_identifier( $table_name ),
			'Failed to rebuild DuckDB AUTO_INCREMENT table'
		);
		$this->drop_auto_increment_sequences( $sequence_names );
		$this->execute_create_table( $this->tokenize_and_validate( $create_sql ) );
		$this->execute_duckdb_query(
			'INSERT INTO '
				. $this->connection->quote_identifier( $table_name )
				. ' ('
				. $quoted_columns
				. ') SELECT '
				. $quoted_columns
				. ' FROM '
				. $this->connection->quote_identifier( $backup_table ),
			'Failed to restore DuckDB table rows after AUTO_INCREMENT rebuild'
		);
		$this->execute_duckdb_query(
			'DROP TABLE IF EXISTS ' . $this->connection->quote_identifier( $backup_table ),
			'Failed to drop DuckDB AUTO_INCREMENT rebuild backup table'
		);
	}

	/**
	 * Drop temporary information_schema compatibility snapshots.
	 */
	private function invalidate_information_schema_compatibility_tables(): void {
		foreach (
			array(
				self::INFO_SCHEMA_TABLES_TABLE,
				self::INFO_SCHEMA_COLUMNS_TABLE,
				self::INFO_SCHEMA_STATISTICS_TABLE,
				self::INFO_SCHEMA_TABLE_CONSTRAINTS_TABLE,
				self::INFO_SCHEMA_KEY_COLUMN_USAGE_TABLE,
				self::INFO_SCHEMA_REFERENTIAL_CONSTRAINTS_TABLE,
				self::INFO_SCHEMA_CHECK_CONSTRAINTS_TABLE,
			) as $table_name
		) {
			$this->execute_duckdb_query(
				'DROP TABLE IF EXISTS ' . $this->connection->quote_identifier( $table_name ),
				'Failed to invalidate DuckDB information_schema compatibility table'
			);
		}
	}

	/**
	 * Read recorded MySQL column metadata.
	 *
	 * @param string $table_name Table name.
	 * @return array<int,array<string,mixed>>
	 */
	private function column_metadata_rows( string $table_name, bool $temporary = false ): array {
		$this->ensure_column_metadata_table( $temporary );

		$stmt = $this->execute_duckdb_query(
			'SELECT ordinal_position, column_name, column_type, is_nullable, column_key, column_default, extra, collation_name, comment FROM '
				. $this->connection->quote_identifier( $this->column_metadata_table_name( $temporary ) )
				. ' WHERE table_name = '
				. $this->connection->quote( $table_name )
				. ' ORDER BY ordinal_position',
			'Failed to inspect DuckDB column metadata'
		);

		return $stmt->fetchAll( PDO::FETCH_ASSOC );
	}

	/**
	 * Check whether a SELECT references information_schema.tables.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @return bool Whether the query needs the compatibility table.
	 */
	private function uses_information_schema_tables( array $tokens ): bool {
		for ( $index = 0; $index < count( $tokens ); ++$index ) {
			if ( $this->is_information_schema_tables_reference( $tokens, $index ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a token offset starts information_schema.tables.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Token offset.
	 * @return bool Whether the sequence is information_schema.tables.
	 */
	private function is_information_schema_tables_reference( array $tokens, int $index ): bool {
		return isset( $tokens[ $index + 2 ] )
			&& 0 === strcasecmp( $tokens[ $index ]->get_value(), 'information_schema' )
			&& WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $index + 1 ]->id
			&& 0 === strcasecmp( $tokens[ $index + 2 ]->get_value(), 'tables' );
	}

	/**
	 * Check whether a SELECT references information_schema.columns.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @return bool Whether the query needs the compatibility table.
	 */
	private function uses_information_schema_columns( array $tokens ): bool {
		for ( $index = 0; $index < count( $tokens ); ++$index ) {
			if ( $this->is_information_schema_columns_reference( $tokens, $index ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a token offset starts information_schema.columns.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Token offset.
	 * @return bool Whether the sequence is information_schema.columns.
	 */
	private function is_information_schema_columns_reference( array $tokens, int $index ): bool {
		return isset( $tokens[ $index + 2 ] )
			&& 0 === strcasecmp( $tokens[ $index ]->get_value(), 'information_schema' )
			&& WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $index + 1 ]->id
			&& 0 === strcasecmp( $tokens[ $index + 2 ]->get_value(), 'columns' );
	}

	/**
	 * Check whether a SELECT references information_schema.statistics.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @return bool Whether the query needs the compatibility table.
	 */
	private function uses_information_schema_statistics( array $tokens ): bool {
		for ( $index = 0; $index < count( $tokens ); ++$index ) {
			if ( $this->is_information_schema_statistics_reference( $tokens, $index ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a token offset starts information_schema.statistics.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Token offset.
	 * @return bool Whether the sequence is information_schema.statistics.
	 */
	private function is_information_schema_statistics_reference( array $tokens, int $index ): bool {
		return isset( $tokens[ $index + 2 ] )
			&& 0 === strcasecmp( $tokens[ $index ]->get_value(), 'information_schema' )
			&& WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $index + 1 ]->id
			&& 0 === strcasecmp( $tokens[ $index + 2 ]->get_value(), 'statistics' );
	}

	/**
	 * Check whether a SELECT references information_schema.table_constraints.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @return bool Whether the query needs the compatibility table.
	 */
	private function uses_information_schema_table_constraints( array $tokens ): bool {
		for ( $index = 0; $index < count( $tokens ); ++$index ) {
			if ( $this->is_information_schema_table_constraints_reference( $tokens, $index ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a token offset starts information_schema.table_constraints.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Token offset.
	 * @return bool Whether the sequence is information_schema.table_constraints.
	 */
	private function is_information_schema_table_constraints_reference( array $tokens, int $index ): bool {
		return isset( $tokens[ $index + 2 ] )
			&& 0 === strcasecmp( $tokens[ $index ]->get_value(), 'information_schema' )
			&& WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $index + 1 ]->id
			&& 0 === strcasecmp( $tokens[ $index + 2 ]->get_value(), 'table_constraints' );
	}

	/**
	 * Check whether a SELECT references information_schema.key_column_usage.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @return bool Whether the query needs the compatibility table.
	 */
	private function uses_information_schema_key_column_usage( array $tokens ): bool {
		for ( $index = 0; $index < count( $tokens ); ++$index ) {
			if ( $this->is_information_schema_key_column_usage_reference( $tokens, $index ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a token offset starts information_schema.key_column_usage.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Token offset.
	 * @return bool Whether the sequence is information_schema.key_column_usage.
	 */
	private function is_information_schema_key_column_usage_reference( array $tokens, int $index ): bool {
		return isset( $tokens[ $index + 2 ] )
			&& 0 === strcasecmp( $tokens[ $index ]->get_value(), 'information_schema' )
			&& WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $index + 1 ]->id
			&& 0 === strcasecmp( $tokens[ $index + 2 ]->get_value(), 'key_column_usage' );
	}

	/**
	 * Check whether a SELECT references information_schema.referential_constraints.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @return bool Whether the query needs the compatibility table.
	 */
	private function uses_information_schema_referential_constraints( array $tokens ): bool {
		for ( $index = 0; $index < count( $tokens ); ++$index ) {
			if ( $this->is_information_schema_referential_constraints_reference( $tokens, $index ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a token offset starts information_schema.referential_constraints.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Token offset.
	 * @return bool Whether the sequence is information_schema.referential_constraints.
	 */
	private function is_information_schema_referential_constraints_reference( array $tokens, int $index ): bool {
		return isset( $tokens[ $index + 2 ] )
			&& 0 === strcasecmp( $tokens[ $index ]->get_value(), 'information_schema' )
			&& WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $index + 1 ]->id
			&& 0 === strcasecmp( $tokens[ $index + 2 ]->get_value(), 'referential_constraints' );
	}

	/**
	 * Check whether a SELECT references information_schema.check_constraints.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @return bool Whether the query needs the compatibility table.
	 */
	private function uses_information_schema_check_constraints( array $tokens ): bool {
		for ( $index = 0; $index < count( $tokens ); ++$index ) {
			if ( $this->is_information_schema_check_constraints_reference( $tokens, $index ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a token offset starts information_schema.check_constraints.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Token offset.
	 * @return bool Whether the sequence is information_schema.check_constraints.
	 */
	private function is_information_schema_check_constraints_reference( array $tokens, int $index ): bool {
		return isset( $tokens[ $index + 2 ] )
			&& 0 === strcasecmp( $tokens[ $index ]->get_value(), 'information_schema' )
			&& WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $index + 1 ]->id
			&& 0 === strcasecmp( $tokens[ $index + 2 ]->get_value(), 'check_constraints' );
	}

	/**
	 * Refresh a temporary MySQL-shaped information_schema.tables table.
	 */
	private function refresh_information_schema_tables_table(): void {
		$rows        = $this->information_schema_table_rows();
		$definitions = $this->information_schema_table_definitions();
		$columns     = array_keys( $definitions );

		$column_sql = array();
		foreach ( $definitions as $column_name => $type ) {
			$column_sql[] = $this->connection->quote_identifier( $column_name ) . ' ' . $type;
		}

		$this->execute_duckdb_query(
			'CREATE OR REPLACE TEMP TABLE '
				. $this->connection->quote_identifier( self::INFO_SCHEMA_TABLES_TABLE )
				. ' ('
				. implode( ', ', $column_sql )
				. ')',
			'Failed to initialize DuckDB information_schema.tables compatibility table'
		);

		if ( count( $rows ) === 0 ) {
			return;
		}

		$quoted_columns = implode(
			', ',
			array_map(
				function ( string $column_name ): string {
					return $this->connection->quote_identifier( $column_name );
				},
				$columns
			)
		);

		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $columns as $column_name ) {
				$values[] = $this->connection->quote( $row[ $column_name ] );
			}

			$this->execute_duckdb_query(
				'INSERT INTO '
					. $this->connection->quote_identifier( self::INFO_SCHEMA_TABLES_TABLE )
					. ' ('
					. $quoted_columns
					. ') VALUES ('
					. implode( ', ', $values )
					. ')',
				'Failed to populate DuckDB information_schema.tables compatibility table'
			);
		}
	}

	/**
	 * MySQL-shaped information_schema.tables definitions.
	 *
	 * @return array<string,string> Column name to DuckDB type.
	 */
	private function information_schema_table_definitions(): array {
		return array(
			'TABLE_CATALOG'   => 'VARCHAR COLLATE NOCASE',
			'TABLE_SCHEMA'    => 'VARCHAR COLLATE NOCASE',
			'TABLE_NAME'      => 'VARCHAR COLLATE NOCASE',
			'TABLE_TYPE'      => 'VARCHAR',
			'ENGINE'          => 'VARCHAR COLLATE NOCASE',
			'VERSION'         => 'INTEGER',
			'ROW_FORMAT'      => 'VARCHAR',
			'TABLE_ROWS'      => 'BIGINT',
			'AVG_ROW_LENGTH'  => 'BIGINT',
			'DATA_LENGTH'     => 'BIGINT',
			'MAX_DATA_LENGTH' => 'BIGINT',
			'INDEX_LENGTH'    => 'BIGINT',
			'DATA_FREE'       => 'BIGINT',
			'AUTO_INCREMENT'  => 'BIGINT',
			'CREATE_TIME'     => 'VARCHAR',
			'UPDATE_TIME'     => 'VARCHAR',
			'CHECK_TIME'      => 'VARCHAR',
			'TABLE_COLLATION' => 'VARCHAR COLLATE NOCASE',
			'CHECKSUM'        => 'BIGINT',
			'CREATE_OPTIONS'  => 'VARCHAR COLLATE NOCASE',
			'TABLE_COMMENT'   => 'VARCHAR COLLATE NOCASE',
		);
	}

	/**
	 * Build MySQL-shaped information_schema.tables rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function information_schema_table_rows(): array {
		$metadata_by_table = $this->table_metadata_by_table();
		$rows              = array();

		foreach ( $this->user_table_names() as $table_name ) {
			$metadata = $metadata_by_table[ $table_name ] ?? $this->fallback_table_metadata( $table_name );
			$rows[]   = $this->information_schema_table_row( $table_name, $metadata );
		}

		return $rows;
	}

	/**
	 * Build one MySQL-shaped information_schema.tables row.
	 *
	 * @param string              $table_name Table name.
	 * @param array<string,mixed> $metadata   Table metadata.
	 * @return array<string,mixed>
	 */
	private function information_schema_table_row( string $table_name, array $metadata, bool $temporary = false ): array {
		return array(
			'TABLE_CATALOG'   => 'def',
			'TABLE_SCHEMA'    => $this->database,
			'TABLE_NAME'      => $table_name,
			'TABLE_TYPE'      => 'BASE TABLE',
			'ENGINE'          => $metadata['engine'],
			'VERSION'         => 10,
			'ROW_FORMAT'      => $metadata['row_format'],
			'TABLE_ROWS'      => 0,
			'AVG_ROW_LENGTH'  => 0,
			'DATA_LENGTH'     => 0,
			'MAX_DATA_LENGTH' => 0,
			'INDEX_LENGTH'    => 0,
			'DATA_FREE'       => 0,
			'AUTO_INCREMENT'  => $this->table_auto_increment_value( $table_name, $temporary ),
			'CREATE_TIME'     => $metadata['create_time'],
			'UPDATE_TIME'     => null,
			'CHECK_TIME'      => null,
			'TABLE_COLLATION' => $metadata['table_collation'],
			'CHECKSUM'        => null,
			'CREATE_OPTIONS'  => $metadata['create_options'],
			'TABLE_COMMENT'   => $metadata['table_comment'],
		);
	}

	/**
	 * Read recorded table metadata.
	 *
	 * @return array<string,array<string,mixed>> Metadata keyed by table name.
	 */
	private function table_metadata_by_table( bool $temporary = false ): array {
		$this->ensure_table_metadata_table( $temporary );

		$stmt = $this->execute_duckdb_query(
			'SELECT table_name, engine, row_format, table_collation, table_comment, create_options, create_time FROM '
				. $this->connection->quote_identifier( $this->table_metadata_table_name( $temporary ) )
				. ' ORDER BY table_name',
			'Failed to inspect DuckDB table metadata'
		);

		$metadata = array();
		foreach ( $stmt->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
			if ( ! array_key_exists( 'table_name', $row ) ) {
				continue;
			}
			$metadata[ (string) $row['table_name'] ] = $row;
		}

		return $metadata;
	}

	/**
	 * Read the effective default table collation for new text columns.
	 *
	 * @param string $table_name Table name.
	 * @param bool   $temporary  Whether the target is a temporary table.
	 * @return string MySQL collation name.
	 */
	private function table_default_collation( string $table_name, bool $temporary = false ): string {
		$metadata = $this->table_metadata_by_table( $temporary );
		if ( isset( $metadata[ $table_name ]['table_collation'] ) && '' !== $metadata[ $table_name ]['table_collation'] ) {
			return (string) $metadata[ $table_name ]['table_collation'];
		}

		return 'utf8mb4_0900_ai_ci';
	}

	/**
	 * Fallback table metadata for tables created outside the MySQL-emulation path.
	 *
	 * @param string $table_name Table name.
	 * @return array<string,mixed>
	 */
	private function fallback_table_metadata( string $table_name ): array {
		return array(
			'table_name'      => $table_name,
			'engine'          => 'InnoDB',
			'row_format'      => 'Dynamic',
			'table_collation' => 'utf8mb4_0900_ai_ci',
			'table_comment'   => '',
			'create_options'  => '',
			'create_time'     => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Compute the MySQL-facing AUTO_INCREMENT value for a table.
	 *
	 * @param string $table_name Table name.
	 * @return int|null Next generated value, or null when there is no auto-increment column.
	 */
	private function table_auto_increment_value( string $table_name, bool $temporary = false ): ?int {
		$metadata = $this->auto_increment_metadata_for_table( $table_name, $temporary );
		if ( null === $metadata ) {
			return null;
		}

		$current = $this->sequence_currval( $metadata['sequence_name'] );
		return null === $current ? 1 : $current + 1;
	}

	/**
	 * Read the current maximum value in an AUTO_INCREMENT column.
	 *
	 * @param string $table_name  Table name.
	 * @param string $column_name AUTO_INCREMENT column name.
	 * @param bool   $temporary   Whether the target is a temporary table.
	 * @return int Maximum existing value, or 0 for an empty table.
	 */
	private function max_auto_increment_column_value( string $table_name, string $column_name, bool $temporary = false ): int {
		$stmt = $this->execute_duckdb_query(
			'SELECT MAX('
				. $this->connection->quote_identifier( $column_name )
				. ') AS max_value FROM '
				. $this->connection->quote_identifier( $table_name ),
			'Failed to inspect DuckDB AUTO_INCREMENT column'
		);

		$value = $stmt->fetchColumn();
		return false === $value || null === $value ? 0 : (int) $value;
	}

	/**
	 * Return the canonical tables column name for a token value.
	 *
	 * @param string $identifier Identifier token value.
	 * @return string|null Canonical column name, or null when not a tables column.
	 */
	private function information_schema_tables_column_name( string $identifier ): ?string {
		$columns = array(
			'table_catalog'   => 'TABLE_CATALOG',
			'table_schema'    => 'TABLE_SCHEMA',
			'table_name'      => 'TABLE_NAME',
			'table_type'      => 'TABLE_TYPE',
			'engine'          => 'ENGINE',
			'version'         => 'VERSION',
			'row_format'      => 'ROW_FORMAT',
			'table_rows'      => 'TABLE_ROWS',
			'avg_row_length'  => 'AVG_ROW_LENGTH',
			'data_length'     => 'DATA_LENGTH',
			'max_data_length' => 'MAX_DATA_LENGTH',
			'index_length'    => 'INDEX_LENGTH',
			'data_free'       => 'DATA_FREE',
			'auto_increment'  => 'AUTO_INCREMENT',
			'create_time'     => 'CREATE_TIME',
			'update_time'     => 'UPDATE_TIME',
			'check_time'      => 'CHECK_TIME',
			'table_collation' => 'TABLE_COLLATION',
			'checksum'        => 'CHECKSUM',
			'create_options'  => 'CREATE_OPTIONS',
			'table_comment'   => 'TABLE_COMMENT',
		);
		$key     = strtolower( $identifier );

		return $columns[ $key ] ?? null;
	}

	/**
	 * Refresh a temporary MySQL-shaped information_schema.columns table.
	 */
	private function refresh_information_schema_columns_table(): void {
		$rows        = $this->information_schema_column_rows();
		$definitions = $this->information_schema_column_definitions();
		$columns     = array_keys( $definitions );

		$column_sql = array();
		foreach ( $definitions as $column_name => $type ) {
			$column_sql[] = $this->connection->quote_identifier( $column_name ) . ' ' . $type;
		}

		$this->execute_duckdb_query(
			'CREATE OR REPLACE TEMP TABLE '
				. $this->connection->quote_identifier( self::INFO_SCHEMA_COLUMNS_TABLE )
				. ' ('
				. implode( ', ', $column_sql )
				. ')',
			'Failed to initialize DuckDB information_schema.columns compatibility table'
		);

		if ( count( $rows ) === 0 ) {
			return;
		}

		$quoted_columns = implode(
			', ',
			array_map(
				function ( string $column_name ): string {
					return $this->connection->quote_identifier( $column_name );
				},
				$columns
			)
		);

		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $columns as $column_name ) {
				$values[] = $this->connection->quote( $row[ $column_name ] );
			}

			$this->execute_duckdb_query(
				'INSERT INTO '
					. $this->connection->quote_identifier( self::INFO_SCHEMA_COLUMNS_TABLE )
					. ' ('
					. $quoted_columns
					. ') VALUES ('
					. implode( ', ', $values )
					. ')',
				'Failed to populate DuckDB information_schema.columns compatibility table'
			);
		}
	}

	/**
	 * MySQL-shaped information_schema.columns definitions.
	 *
	 * @return array<string,string> Column name to DuckDB type.
	 */
	private function information_schema_column_definitions(): array {
		return array(
			'TABLE_CATALOG'            => 'VARCHAR',
			'TABLE_SCHEMA'             => 'VARCHAR',
			'TABLE_NAME'               => 'VARCHAR',
			'COLUMN_NAME'              => 'VARCHAR',
			'ORDINAL_POSITION'         => 'INTEGER',
			'COLUMN_DEFAULT'           => 'VARCHAR',
			'IS_NULLABLE'              => 'VARCHAR',
			'DATA_TYPE'                => 'VARCHAR',
			'CHARACTER_MAXIMUM_LENGTH' => 'BIGINT',
			'CHARACTER_OCTET_LENGTH'   => 'BIGINT',
			'NUMERIC_PRECISION'        => 'INTEGER',
			'NUMERIC_SCALE'            => 'INTEGER',
			'DATETIME_PRECISION'       => 'INTEGER',
			'CHARACTER_SET_NAME'       => 'VARCHAR',
			'COLLATION_NAME'           => 'VARCHAR',
			'COLUMN_TYPE'              => 'VARCHAR',
			'COLUMN_KEY'               => 'VARCHAR',
			'EXTRA'                    => 'VARCHAR',
			'PRIVILEGES'               => 'VARCHAR',
			'COLUMN_COMMENT'           => 'VARCHAR',
			'GENERATION_EXPRESSION'    => 'VARCHAR',
			'SRS_ID'                   => 'INTEGER',
		);
	}

	/**
	 * Refresh a temporary MySQL-shaped information_schema.statistics table.
	 */
	private function refresh_information_schema_statistics_table(): void {
		$rows        = $this->information_schema_statistics_rows();
		$definitions = $this->information_schema_statistics_definitions();
		$columns     = array_keys( $definitions );

		$column_sql = array();
		foreach ( $definitions as $column_name => $type ) {
			$column_sql[] = $this->connection->quote_identifier( $column_name ) . ' ' . $type;
		}

		$this->execute_duckdb_query(
			'CREATE OR REPLACE TEMP TABLE '
				. $this->connection->quote_identifier( self::INFO_SCHEMA_STATISTICS_TABLE )
				. ' ('
				. implode( ', ', $column_sql )
				. ')',
			'Failed to initialize DuckDB information_schema.statistics compatibility table'
		);

		if ( count( $rows ) === 0 ) {
			return;
		}

		$quoted_columns = implode(
			', ',
			array_map(
				function ( string $column_name ): string {
					return $this->connection->quote_identifier( $column_name );
				},
				$columns
			)
		);

		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $columns as $column_name ) {
				$values[] = $this->connection->quote( $row[ $column_name ] );
			}

			$this->execute_duckdb_query(
				'INSERT INTO '
					. $this->connection->quote_identifier( self::INFO_SCHEMA_STATISTICS_TABLE )
					. ' ('
					. $quoted_columns
					. ') VALUES ('
					. implode( ', ', $values )
					. ')',
				'Failed to populate DuckDB information_schema.statistics compatibility table'
			);
		}
	}

	/**
	 * MySQL-shaped information_schema.statistics definitions.
	 *
	 * @return array<string,string> Column name to DuckDB type.
	 */
	private function information_schema_statistics_definitions(): array {
		return array(
			'TABLE_CATALOG' => 'VARCHAR COLLATE NOCASE',
			'TABLE_SCHEMA'  => 'VARCHAR COLLATE NOCASE',
			'TABLE_NAME'    => 'VARCHAR COLLATE NOCASE',
			'NON_UNIQUE'    => 'INTEGER',
			'INDEX_SCHEMA'  => 'VARCHAR COLLATE NOCASE',
			'INDEX_NAME'    => 'VARCHAR COLLATE NOCASE',
			'SEQ_IN_INDEX'  => 'INTEGER',
			'COLUMN_NAME'   => 'VARCHAR COLLATE NOCASE',
			'COLLATION'     => 'VARCHAR COLLATE NOCASE',
			'CARDINALITY'   => 'INTEGER',
			'SUB_PART'      => 'INTEGER',
			'PACKED'        => 'VARCHAR',
			'NULLABLE'      => 'VARCHAR COLLATE NOCASE',
			'INDEX_TYPE'    => 'VARCHAR',
			'COMMENT'       => 'VARCHAR COLLATE NOCASE',
			'INDEX_COMMENT' => 'VARCHAR',
			'IS_VISIBLE'    => 'VARCHAR COLLATE NOCASE',
			'EXPRESSION'    => 'VARCHAR',
		);
	}

	/**
	 * Build MySQL-shaped information_schema.statistics rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function information_schema_statistics_rows(): array {
		$rows = array();
		foreach ( $this->user_table_names() as $table_name ) {
			$nullable_by_column = $this->statistics_nullable_by_column( $table_name );
			foreach ( $this->index_rows_for_table( $table_name ) as $index_row ) {
				$rows[] = $this->information_schema_statistics_row( $index_row, $nullable_by_column );
			}
		}

		return $rows;
	}

	/**
	 * Build one MySQL-shaped information_schema.statistics row.
	 *
	 * @param array<int,mixed>     $index_row          SHOW INDEX-compatible row.
	 * @param array<string,string> $nullable_by_column Nullability keyed by lowercase column name.
	 * @return array<string,mixed>
	 */
	private function information_schema_statistics_row( array $index_row, array $nullable_by_column ): array {
		$key_name    = (string) $index_row[2];
		$column_name = null === $index_row[4] ? null : (string) $index_row[4];
		$nullable    = '';
		if ( 'PRIMARY' !== $key_name && null !== $column_name ) {
			$nullable = $nullable_by_column[ strtolower( $column_name ) ] ?? '';
		}

		return array(
			'TABLE_CATALOG' => 'def',
			'TABLE_SCHEMA'  => $this->database,
			'TABLE_NAME'    => $index_row[0],
			'NON_UNIQUE'    => (int) $index_row[1],
			'INDEX_SCHEMA'  => $this->database,
			'INDEX_NAME'    => $key_name,
			'SEQ_IN_INDEX'  => (int) $index_row[3],
			'COLUMN_NAME'   => $column_name,
			'COLLATION'     => $index_row[5],
			'CARDINALITY'   => 0,
			'SUB_PART'      => $index_row[7],
			'PACKED'        => $index_row[8],
			'NULLABLE'      => $nullable,
			'INDEX_TYPE'    => $index_row[10],
			'COMMENT'       => $index_row[11],
			'INDEX_COMMENT' => $index_row[12],
			'IS_VISIBLE'    => $index_row[13],
			'EXPRESSION'    => $index_row[14],
		);
	}

	/**
	 * Read statistics nullability values for a table.
	 *
	 * @param string $table_name Table name.
	 * @return array<string,string> Nullability keyed by lowercase column name.
	 */
	private function statistics_nullable_by_column( string $table_name ): array {
		$metadata_rows = $this->column_metadata_rows( $table_name );
		if ( count( $metadata_rows ) === 0 ) {
			$metadata_rows = $this->pragma_column_metadata_rows( $table_name );
		}

		$nullable = array();
		foreach ( $metadata_rows as $metadata ) {
			$nullable[ strtolower( (string) $metadata['column_name'] ) ] = 'YES' === strtoupper( (string) $metadata['is_nullable'] ) ? 'YES' : '';
		}

		return $nullable;
	}

	/**
	 * Return the canonical statistics column name for a token value.
	 *
	 * @param string $identifier Identifier token value.
	 * @return string|null Canonical column name, or null when not a statistics column.
	 */
	private function information_schema_statistics_column_name( string $identifier ): ?string {
		$columns = array(
			'table_catalog' => 'TABLE_CATALOG',
			'table_schema'  => 'TABLE_SCHEMA',
			'table_name'    => 'TABLE_NAME',
			'non_unique'    => 'NON_UNIQUE',
			'index_schema'  => 'INDEX_SCHEMA',
			'index_name'    => 'INDEX_NAME',
			'seq_in_index'  => 'SEQ_IN_INDEX',
			'column_name'   => 'COLUMN_NAME',
			'collation'     => 'COLLATION',
			'cardinality'   => 'CARDINALITY',
			'sub_part'      => 'SUB_PART',
			'packed'        => 'PACKED',
			'nullable'      => 'NULLABLE',
			'index_type'    => 'INDEX_TYPE',
			'comment'       => 'COMMENT',
			'index_comment' => 'INDEX_COMMENT',
			'is_visible'    => 'IS_VISIBLE',
			'expression'    => 'EXPRESSION',
		);
		$key     = strtolower( $identifier );

		return $columns[ $key ] ?? null;
	}

	/**
	 * Refresh a temporary MySQL-shaped information_schema.table_constraints table.
	 */
	private function refresh_information_schema_table_constraints_table(): void {
		$this->refresh_information_schema_compatibility_table(
			self::INFO_SCHEMA_TABLE_CONSTRAINTS_TABLE,
			$this->information_schema_table_constraints_definitions(),
			$this->information_schema_table_constraints_rows(),
			'information_schema.table_constraints'
		);
	}

	/**
	 * MySQL-shaped information_schema.table_constraints definitions.
	 *
	 * @return array<string,string> Column name to DuckDB type.
	 */
	private function information_schema_table_constraints_definitions(): array {
		return array(
			'CONSTRAINT_CATALOG' => 'VARCHAR COLLATE NOCASE',
			'CONSTRAINT_SCHEMA'  => 'VARCHAR COLLATE NOCASE',
			'CONSTRAINT_NAME'    => 'VARCHAR COLLATE NOCASE',
			'TABLE_SCHEMA'       => 'VARCHAR COLLATE NOCASE',
			'TABLE_NAME'         => 'VARCHAR COLLATE NOCASE',
			'CONSTRAINT_TYPE'    => 'VARCHAR',
			'ENFORCED'           => 'VARCHAR',
		);
	}

	/**
	 * Build MySQL-shaped information_schema.table_constraints rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function information_schema_table_constraints_rows(): array {
		$rows = array();
		$seen = array();

		foreach ( $this->information_schema_key_constraint_rows() as $constraint ) {
			$key = $constraint['table_name'] . "\0" . $constraint['constraint_type'] . "\0" . $constraint['constraint_name'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$rows[]       = $this->information_schema_table_constraints_row( $constraint );
		}

		foreach ( $this->information_schema_foreign_key_constraint_rows() as $constraint ) {
			$key = $constraint['table_name'] . "\0" . $constraint['constraint_type'] . "\0" . $constraint['constraint_name'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$rows[]       = $this->information_schema_table_constraints_row( $constraint );
		}

		foreach ( $this->information_schema_check_constraint_rows() as $constraint ) {
			$key = $constraint['table_name'] . "\0" . $constraint['constraint_type'] . "\0" . $constraint['constraint_name'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$rows[]       = $this->information_schema_table_constraints_row( $constraint );
		}

		return $rows;
	}

	/**
	 * Build one MySQL-shaped information_schema.table_constraints row.
	 *
	 * @param array<string,mixed> $constraint Normalized key constraint row.
	 * @return array<string,mixed>
	 */
	private function information_schema_table_constraints_row( array $constraint ): array {
		return array(
			'CONSTRAINT_CATALOG' => 'def',
			'CONSTRAINT_SCHEMA'  => $this->database,
			'CONSTRAINT_NAME'    => $constraint['constraint_name'],
			'TABLE_SCHEMA'       => $this->database,
			'TABLE_NAME'         => $constraint['table_name'],
			'CONSTRAINT_TYPE'    => $constraint['constraint_type'],
			'ENFORCED'           => 'YES',
		);
	}

	/**
	 * Return the canonical table_constraints column name for a token value.
	 *
	 * @param string $identifier Identifier token value.
	 * @return string|null Canonical column name, or null when not a table_constraints column.
	 */
	private function information_schema_table_constraints_column_name( string $identifier ): ?string {
		$columns = array(
			'constraint_catalog' => 'CONSTRAINT_CATALOG',
			'constraint_schema'  => 'CONSTRAINT_SCHEMA',
			'constraint_name'    => 'CONSTRAINT_NAME',
			'table_schema'       => 'TABLE_SCHEMA',
			'table_name'         => 'TABLE_NAME',
			'constraint_type'    => 'CONSTRAINT_TYPE',
			'enforced'           => 'ENFORCED',
		);
		$key     = strtolower( $identifier );

		return $columns[ $key ] ?? null;
	}

	/**
	 * Refresh a temporary MySQL-shaped information_schema.key_column_usage table.
	 */
	private function refresh_information_schema_key_column_usage_table(): void {
		$this->refresh_information_schema_compatibility_table(
			self::INFO_SCHEMA_KEY_COLUMN_USAGE_TABLE,
			$this->information_schema_key_column_usage_definitions(),
			$this->information_schema_key_column_usage_rows(),
			'information_schema.key_column_usage'
		);
	}

	/**
	 * MySQL-shaped information_schema.key_column_usage definitions.
	 *
	 * @return array<string,string> Column name to DuckDB type.
	 */
	private function information_schema_key_column_usage_definitions(): array {
		return array(
			'CONSTRAINT_CATALOG'            => 'VARCHAR COLLATE NOCASE',
			'CONSTRAINT_SCHEMA'             => 'VARCHAR COLLATE NOCASE',
			'CONSTRAINT_NAME'               => 'VARCHAR COLLATE NOCASE',
			'TABLE_CATALOG'                 => 'VARCHAR COLLATE NOCASE',
			'TABLE_SCHEMA'                  => 'VARCHAR COLLATE NOCASE',
			'TABLE_NAME'                    => 'VARCHAR COLLATE NOCASE',
			'COLUMN_NAME'                   => 'VARCHAR COLLATE NOCASE',
			'ORDINAL_POSITION'              => 'INTEGER',
			'POSITION_IN_UNIQUE_CONSTRAINT' => 'INTEGER',
			'REFERENCED_TABLE_SCHEMA'       => 'VARCHAR COLLATE NOCASE',
			'REFERENCED_TABLE_NAME'         => 'VARCHAR COLLATE NOCASE',
			'REFERENCED_COLUMN_NAME'        => 'VARCHAR COLLATE NOCASE',
		);
	}

	/**
	 * Build MySQL-shaped information_schema.key_column_usage rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function information_schema_key_column_usage_rows(): array {
		$rows = array();
		foreach ( $this->information_schema_key_constraint_rows() as $constraint ) {
			$rows[] = $this->information_schema_key_column_usage_row( $constraint );
		}
		foreach ( $this->information_schema_foreign_key_constraint_rows() as $constraint ) {
			$rows[] = $this->information_schema_key_column_usage_row( $constraint );
		}

		return $rows;
	}

	/**
	 * Build one MySQL-shaped information_schema.key_column_usage row.
	 *
	 * @param array<string,mixed> $constraint Normalized key constraint row.
	 * @return array<string,mixed>
	 */
	private function information_schema_key_column_usage_row( array $constraint ): array {
		return array(
			'CONSTRAINT_CATALOG'            => 'def',
			'CONSTRAINT_SCHEMA'             => $this->database,
			'CONSTRAINT_NAME'               => $constraint['constraint_name'],
			'TABLE_CATALOG'                 => 'def',
			'TABLE_SCHEMA'                  => $this->database,
			'TABLE_NAME'                    => $constraint['table_name'],
			'COLUMN_NAME'                   => $constraint['column_name'],
			'ORDINAL_POSITION'              => $constraint['ordinal_position'],
			'POSITION_IN_UNIQUE_CONSTRAINT' => $constraint['position_in_unique_constraint'] ?? null,
			'REFERENCED_TABLE_SCHEMA'       => $constraint['referenced_table_schema'] ?? $this->database,
			'REFERENCED_TABLE_NAME'         => $constraint['referenced_table_name'] ?? null,
			'REFERENCED_COLUMN_NAME'        => $constraint['referenced_column_name'] ?? null,
		);
	}

	/**
	 * Return the canonical key_column_usage column name for a token value.
	 *
	 * @param string $identifier Identifier token value.
	 * @return string|null Canonical column name, or null when not a key_column_usage column.
	 */
	private function information_schema_key_column_usage_column_name( string $identifier ): ?string {
		$columns = array(
			'constraint_catalog'            => 'CONSTRAINT_CATALOG',
			'constraint_schema'             => 'CONSTRAINT_SCHEMA',
			'constraint_name'               => 'CONSTRAINT_NAME',
			'table_catalog'                 => 'TABLE_CATALOG',
			'table_schema'                  => 'TABLE_SCHEMA',
			'table_name'                    => 'TABLE_NAME',
			'column_name'                   => 'COLUMN_NAME',
			'ordinal_position'              => 'ORDINAL_POSITION',
			'position_in_unique_constraint' => 'POSITION_IN_UNIQUE_CONSTRAINT',
			'referenced_table_schema'       => 'REFERENCED_TABLE_SCHEMA',
			'referenced_table_name'         => 'REFERENCED_TABLE_NAME',
			'referenced_column_name'        => 'REFERENCED_COLUMN_NAME',
		);
		$key     = strtolower( $identifier );

		return $columns[ $key ] ?? null;
	}

	/**
	 * Refresh a temporary MySQL-shaped information_schema.referential_constraints table.
	 */
	private function refresh_information_schema_referential_constraints_table(): void {
		$this->refresh_information_schema_compatibility_table(
			self::INFO_SCHEMA_REFERENTIAL_CONSTRAINTS_TABLE,
			$this->information_schema_referential_constraints_definitions(),
			$this->information_schema_referential_constraints_rows(),
			'information_schema.referential_constraints'
		);
	}

	/**
	 * MySQL-shaped information_schema.referential_constraints definitions.
	 *
	 * @return array<string,string> Column name to DuckDB type.
	 */
	private function information_schema_referential_constraints_definitions(): array {
		return array(
			'CONSTRAINT_CATALOG'        => 'VARCHAR COLLATE NOCASE',
			'CONSTRAINT_SCHEMA'         => 'VARCHAR COLLATE NOCASE',
			'CONSTRAINT_NAME'           => 'VARCHAR COLLATE NOCASE',
			'UNIQUE_CONSTRAINT_CATALOG' => 'VARCHAR COLLATE NOCASE',
			'UNIQUE_CONSTRAINT_SCHEMA'  => 'VARCHAR COLLATE NOCASE',
			'UNIQUE_CONSTRAINT_NAME'    => 'VARCHAR COLLATE NOCASE',
			'MATCH_OPTION'              => 'VARCHAR COLLATE NOCASE',
			'UPDATE_RULE'               => 'VARCHAR COLLATE NOCASE',
			'DELETE_RULE'               => 'VARCHAR COLLATE NOCASE',
			'TABLE_NAME'                => 'VARCHAR COLLATE NOCASE',
			'REFERENCED_TABLE_NAME'     => 'VARCHAR COLLATE NOCASE',
		);
	}

	/**
	 * Build MySQL-shaped information_schema.referential_constraints rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function information_schema_referential_constraints_rows(): array {
		$rows = array();
		$seen = array();

		foreach ( $this->user_table_names() as $table_name ) {
			foreach ( $this->foreign_key_metadata_rows( $table_name ) as $foreign_key ) {
				$key = $table_name . "\0" . (string) $foreign_key['constraint_name'];
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;
				$rows[]       = $this->information_schema_referential_constraints_row( $table_name, $foreign_key );
			}
		}

		return $rows;
	}

	/**
	 * Build one MySQL-shaped information_schema.referential_constraints row.
	 *
	 * @param string              $table_name  Table name.
	 * @param array<string,mixed> $foreign_key FOREIGN KEY metadata row.
	 * @return array<string,mixed>
	 */
	private function information_schema_referential_constraints_row( string $table_name, array $foreign_key ): array {
		$referenced_table_name  = (string) $foreign_key['referenced_table_name'];
		$referenced_column_name = (string) $foreign_key['referenced_column_name'];

		return array(
			'CONSTRAINT_CATALOG'        => 'def',
			'CONSTRAINT_SCHEMA'         => $this->database,
			'CONSTRAINT_NAME'           => $foreign_key['constraint_name'],
			'UNIQUE_CONSTRAINT_CATALOG' => 'def',
			'UNIQUE_CONSTRAINT_SCHEMA'  => $this->database,
			'UNIQUE_CONSTRAINT_NAME'    => $this->referenced_unique_constraint_name( $referenced_table_name, $referenced_column_name ),
			'MATCH_OPTION'              => 'NONE',
			'UPDATE_RULE'               => $foreign_key['update_rule'],
			'DELETE_RULE'               => $foreign_key['delete_rule'],
			'TABLE_NAME'                => $table_name,
			'REFERENCED_TABLE_NAME'     => $referenced_table_name,
		);
	}

	/**
	 * Return the referenced PRIMARY or UNIQUE constraint name for a single-column FK.
	 *
	 * @param string $table_name  Referenced table name.
	 * @param string $column_name Referenced column name.
	 * @return string|null Constraint name when it can be resolved.
	 */
	private function referenced_unique_constraint_name( string $table_name, string $column_name ): ?string {
		$primary_key_rows = $this->primary_key_index_rows( $table_name );
		if (
			1 === count( $primary_key_rows )
			&& 0 === strcasecmp( (string) $primary_key_rows[0][4], $column_name )
		) {
			return (string) $primary_key_rows[0][2];
		}

		$unique_columns_by_name = array();
		foreach ( $this->secondary_index_rows( $table_name ) as $index_row ) {
			if ( 0 !== (int) $index_row[1] ) {
				continue;
			}

			$index_name = (string) $index_row[2];
			if ( ! isset( $unique_columns_by_name[ $index_name ] ) ) {
				$unique_columns_by_name[ $index_name ] = array();
			}
			$unique_columns_by_name[ $index_name ][] = (string) $index_row[4];
		}

		foreach ( $unique_columns_by_name as $index_name => $columns ) {
			if ( 1 === count( $columns ) && 0 === strcasecmp( $columns[0], $column_name ) ) {
				return (string) $index_name;
			}
		}

		return null;
	}

	/**
	 * Return the canonical referential_constraints column name for a token value.
	 *
	 * @param string $identifier Identifier token value.
	 * @return string|null Canonical column name, or null when not a referential_constraints column.
	 */
	private function information_schema_referential_constraints_column_name( string $identifier ): ?string {
		$columns = array(
			'constraint_catalog'        => 'CONSTRAINT_CATALOG',
			'constraint_schema'         => 'CONSTRAINT_SCHEMA',
			'constraint_name'           => 'CONSTRAINT_NAME',
			'unique_constraint_catalog' => 'UNIQUE_CONSTRAINT_CATALOG',
			'unique_constraint_schema'  => 'UNIQUE_CONSTRAINT_SCHEMA',
			'unique_constraint_name'    => 'UNIQUE_CONSTRAINT_NAME',
			'match_option'              => 'MATCH_OPTION',
			'update_rule'               => 'UPDATE_RULE',
			'delete_rule'               => 'DELETE_RULE',
			'table_name'                => 'TABLE_NAME',
			'referenced_table_name'     => 'REFERENCED_TABLE_NAME',
		);
		$key     = strtolower( $identifier );

		return $columns[ $key ] ?? null;
	}

	/**
	 * Refresh a temporary MySQL-shaped information_schema.check_constraints table.
	 */
	private function refresh_information_schema_check_constraints_table(): void {
		$this->refresh_information_schema_compatibility_table(
			self::INFO_SCHEMA_CHECK_CONSTRAINTS_TABLE,
			$this->information_schema_check_constraints_definitions(),
			$this->information_schema_check_constraints_rows(),
			'information_schema.check_constraints'
		);
	}

	/**
	 * MySQL-shaped information_schema.check_constraints definitions.
	 *
	 * @return array<string,string> Column name to DuckDB type.
	 */
	private function information_schema_check_constraints_definitions(): array {
		return array(
			'CONSTRAINT_CATALOG' => 'VARCHAR COLLATE NOCASE',
			'CONSTRAINT_SCHEMA'  => 'VARCHAR COLLATE NOCASE',
			'CONSTRAINT_NAME'    => 'VARCHAR COLLATE NOCASE',
			'CHECK_CLAUSE'       => 'VARCHAR',
		);
	}

	/**
	 * Build MySQL-shaped information_schema.check_constraints rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function information_schema_check_constraints_rows(): array {
		$rows = array();

		foreach ( $this->user_table_names() as $table_name ) {
			foreach ( $this->check_constraint_metadata_rows( $table_name ) as $check_constraint ) {
				$rows[] = $this->information_schema_check_constraints_row( $check_constraint );
			}
		}

		return $rows;
	}

	/**
	 * Build one MySQL-shaped information_schema.check_constraints row.
	 *
	 * @param array<string,mixed> $check_constraint CHECK metadata row.
	 * @return array<string,mixed>
	 */
	private function information_schema_check_constraints_row( array $check_constraint ): array {
		return array(
			'CONSTRAINT_CATALOG' => 'def',
			'CONSTRAINT_SCHEMA'  => $this->database,
			'CONSTRAINT_NAME'    => $check_constraint['constraint_name'],
			'CHECK_CLAUSE'       => $check_constraint['check_clause'],
		);
	}

	/**
	 * Return the canonical check_constraints column name for a token value.
	 *
	 * @param string $identifier Identifier token value.
	 * @return string|null Canonical column name, or null when not a check_constraints column.
	 */
	private function information_schema_check_constraints_column_name( string $identifier ): ?string {
		$columns = array(
			'constraint_catalog' => 'CONSTRAINT_CATALOG',
			'constraint_schema'  => 'CONSTRAINT_SCHEMA',
			'constraint_name'    => 'CONSTRAINT_NAME',
			'check_clause'       => 'CHECK_CLAUSE',
		);
		$key     = strtolower( $identifier );

		return $columns[ $key ] ?? null;
	}

	/**
	 * Build normalized primary and unique constraint column rows.
	 *
	 * @return array<int,array{table_name:string,constraint_name:string,constraint_type:string,ordinal_position:int,column_name:string}>
	 */
	private function information_schema_key_constraint_rows(): array {
		$rows = array();

		foreach ( $this->user_table_names() as $table_name ) {
			foreach ( $this->primary_key_index_rows( $table_name ) as $index_row ) {
				$rows[] = array(
					'table_name'       => $table_name,
					'constraint_name'  => (string) $index_row[2],
					'constraint_type'  => 'PRIMARY KEY',
					'ordinal_position' => (int) $index_row[3],
					'column_name'      => (string) $index_row[4],
				);
			}

			foreach ( $this->secondary_index_rows( $table_name ) as $index_row ) {
				if ( 0 !== (int) $index_row[1] ) {
					continue;
				}

				$rows[] = array(
					'table_name'       => $table_name,
					'constraint_name'  => (string) $index_row[2],
					'constraint_type'  => 'UNIQUE',
					'ordinal_position' => (int) $index_row[3],
					'column_name'      => (string) $index_row[4],
				);
			}
		}

		return $rows;
	}

	/**
	 * Build normalized FOREIGN KEY constraint column rows.
	 *
	 * @return array<int,array{table_name:string,constraint_name:string,constraint_type:string,ordinal_position:int,column_name:string,position_in_unique_constraint:int,referenced_table_schema:string,referenced_table_name:string,referenced_column_name:string}>
	 */
	private function information_schema_foreign_key_constraint_rows(): array {
		$rows = array();

		foreach ( $this->user_table_names() as $table_name ) {
			foreach ( $this->foreign_key_metadata_rows( $table_name ) as $foreign_key ) {
				$rows[] = array(
					'table_name'                    => $table_name,
					'constraint_name'               => (string) $foreign_key['constraint_name'],
					'constraint_type'               => 'FOREIGN KEY',
					'ordinal_position'              => (int) $foreign_key['ordinal_position'],
					'column_name'                   => (string) $foreign_key['column_name'],
					'position_in_unique_constraint' => (int) $foreign_key['ordinal_position'],
					'referenced_table_schema'       => $this->database,
					'referenced_table_name'         => (string) $foreign_key['referenced_table_name'],
					'referenced_column_name'        => (string) $foreign_key['referenced_column_name'],
				);
			}
		}

		return $rows;
	}

	/**
	 * Build normalized CHECK constraint rows.
	 *
	 * @return array<int,array{table_name:string,constraint_name:string,constraint_type:string}>
	 */
	private function information_schema_check_constraint_rows(): array {
		$rows = array();

		foreach ( $this->user_table_names() as $table_name ) {
			foreach ( $this->check_constraint_metadata_rows( $table_name ) as $check_constraint ) {
				$rows[] = array(
					'table_name'      => $table_name,
					'constraint_name' => (string) $check_constraint['constraint_name'],
					'constraint_type' => 'CHECK',
				);
			}
		}

		return $rows;
	}

	/**
	 * Refresh a temporary MySQL-shaped information_schema compatibility table.
	 *
	 * @param string                  $table_name  Temporary table name.
	 * @param array<string,string>    $definitions Column definitions.
	 * @param array<int,array<string,mixed>> $rows        Rows to insert.
	 * @param string                  $label       User-facing information_schema table label.
	 */
	private function refresh_information_schema_compatibility_table( string $table_name, array $definitions, array $rows, string $label ): void {
		$columns    = array_keys( $definitions );
		$column_sql = array();
		foreach ( $definitions as $column_name => $type ) {
			$column_sql[] = $this->connection->quote_identifier( $column_name ) . ' ' . $type;
		}

		$this->execute_duckdb_query(
			'CREATE OR REPLACE TEMP TABLE '
				. $this->connection->quote_identifier( $table_name )
				. ' ('
				. implode( ', ', $column_sql )
				. ')',
			'Failed to initialize DuckDB ' . $label . ' compatibility table'
		);

		if ( count( $rows ) === 0 ) {
			return;
		}

		$quoted_columns = implode(
			', ',
			array_map(
				function ( string $column_name ): string {
					return $this->connection->quote_identifier( $column_name );
				},
				$columns
			)
		);

		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $columns as $column_name ) {
				$values[] = $this->connection->quote( $row[ $column_name ] );
			}

			$this->execute_duckdb_query(
				'INSERT INTO '
					. $this->connection->quote_identifier( $table_name )
					. ' ('
					. $quoted_columns
					. ') VALUES ('
					. implode( ', ', $values )
					. ')',
				'Failed to populate DuckDB ' . $label . ' compatibility table'
			);
		}
	}

	/**
	 * Build MySQL-shaped information_schema.columns rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function information_schema_column_rows(): array {
		$rows = array();
		foreach ( $this->user_table_names() as $table_name ) {
			$metadata_rows = $this->column_metadata_rows( $table_name );
			if ( count( $metadata_rows ) === 0 ) {
				$metadata_rows = $this->pragma_column_metadata_rows( $table_name );
			}

			foreach ( $metadata_rows as $metadata ) {
				$rows[] = $this->information_schema_column_row( $table_name, $metadata );
			}
		}

		return $rows;
	}

	/**
	 * List non-internal DuckDB base tables.
	 *
	 * @return string[] Table names.
	 */
	private function user_table_names(): array {
		$stmt = $this->execute_duckdb_query(
			'SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema()'
				. " AND table_type = 'BASE TABLE'"
				. ' AND table_name <> '
				. $this->connection->quote( self::INDEX_METADATA_TABLE )
				. ' AND table_name <> '
				. $this->connection->quote( self::COLUMN_METADATA_TABLE )
				. ' AND table_name <> '
				. $this->connection->quote( self::TABLE_METADATA_TABLE )
				. ' AND table_name <> '
				. $this->connection->quote( self::CHECK_METADATA_TABLE )
				. ' AND table_name <> '
				. $this->connection->quote( self::FOREIGN_KEY_METADATA_TABLE )
				. ' AND table_name <> '
				. $this->connection->quote( self::INFO_SCHEMA_TABLES_TABLE )
				. ' AND table_name <> '
				. $this->connection->quote( self::INFO_SCHEMA_COLUMNS_TABLE )
				. ' AND table_name <> '
				. $this->connection->quote( self::INFO_SCHEMA_STATISTICS_TABLE )
				. ' AND table_name <> '
				. $this->connection->quote( self::INFO_SCHEMA_TABLE_CONSTRAINTS_TABLE )
				. ' AND table_name <> '
				. $this->connection->quote( self::INFO_SCHEMA_KEY_COLUMN_USAGE_TABLE )
				. ' AND table_name <> '
				. $this->connection->quote( self::INFO_SCHEMA_REFERENTIAL_CONSTRAINTS_TABLE )
				. ' AND table_name <> '
				. $this->connection->quote( self::INFO_SCHEMA_CHECK_CONSTRAINTS_TABLE )
				. ' ORDER BY table_name',
			'Failed to inspect DuckDB tables'
		);

		return array_map(
			'strval',
			$stmt->fetchAll( PDO::FETCH_COLUMN )
		);
	}

	/**
	 * List non-internal DuckDB temporary tables visible in this session.
	 *
	 * @return string[] Table names.
	 */
	private function temporary_user_table_names(): array {
		$stmt = $this->execute_duckdb_query(
			"SELECT table_name FROM information_schema.tables WHERE table_type = 'LOCAL TEMPORARY'"
				. ' AND table_name NOT LIKE '
				. $this->connection->quote( '\_\_wp\_duckdb\_%' )
				. " ESCAPE '\\'"
				. ' ORDER BY table_name',
			'Failed to inspect DuckDB temporary tables'
		);

		return array_map(
			'strval',
			$stmt->fetchAll( PDO::FETCH_COLUMN )
		);
	}

	/**
	 * Resolve a requested table name to the visible DuckDB table, preferring temporary tables.
	 *
	 * @param string $table_name Requested table name.
	 * @return array{table_name:string,temporary:bool}|null Resolved table reference, or null when no table matches.
	 */
	private function resolve_visible_user_table_reference( string $table_name ): ?array {
		foreach ( $this->temporary_user_table_names() as $candidate ) {
			if ( 0 === strcasecmp( $candidate, $table_name ) ) {
				return array(
					'table_name' => $candidate,
					'temporary'  => true,
				);
			}
		}

		foreach ( $this->user_table_names() as $candidate ) {
			if ( 0 === strcasecmp( $candidate, $table_name ) ) {
				return array(
					'table_name' => $candidate,
					'temporary'  => false,
				);
			}
		}

		return null;
	}

	/**
	 * Resolve a requested table name to a temporary table in the current session.
	 *
	 * @param string $table_name Requested table name.
	 * @return array{table_name:string,temporary:bool}|null Resolved table reference, or null when no temp table matches.
	 */
	private function resolve_temporary_user_table_reference( string $table_name ): ?array {
		foreach ( $this->temporary_user_table_names() as $candidate ) {
			if ( 0 === strcasecmp( $candidate, $table_name ) ) {
				return array(
					'table_name' => $candidate,
					'temporary'  => true,
				);
			}
		}

		return null;
	}

	/**
	 * Resolve a requested MySQL table name to a visible DuckDB table name.
	 *
	 * @param string $table_name Requested table name.
	 * @return string|null Actual table name, or null when no user table matches.
	 */
	private function resolve_user_table_name( string $table_name ): ?string {
		foreach ( $this->user_table_names() as $candidate ) {
			if ( 0 === strcasecmp( $candidate, $table_name ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Check whether a name targets a DuckDB driver internal table.
	 *
	 * @param string $table_name Table name.
	 * @return bool Whether the table is internal to the driver.
	 */
	private function is_duckdb_internal_table_name( string $table_name ): bool {
		return 0 === stripos( $table_name, '__wp_duckdb_' );
	}

	/**
	 * Build metadata rows for tables created outside the MySQL-emulation DDL path.
	 *
	 * @param string $table_name Table name.
	 * @return array<int,array<string,mixed>>
	 */
	private function pragma_column_metadata_rows( string $table_name ): array {
		$stmt = $this->execute_duckdb_query(
			'SELECT cid, name, type, "notnull", dflt_value, pk FROM pragma_table_info(' . $this->connection->quote( $table_name ) . ') ORDER BY cid',
			'Failed to inspect DuckDB table columns'
		);

		$rows = array();
		foreach ( $stmt->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
			$is_primary_key = isset( $row['pk'] ) && (int) $row['pk'] > 0;
			$is_not_null    = $is_primary_key || ( isset( $row['notnull'] ) && (bool) $row['notnull'] );
			$is_auto        = isset( $row['dflt_value'] ) && is_string( $row['dflt_value'] ) && false !== stripos( $row['dflt_value'], 'nextval(' );

			$rows[] = array(
				'ordinal_position' => (int) $row['cid'] + 1,
				'column_name'      => (string) $row['name'],
				'column_type'      => strtolower( (string) $row['type'] ),
				'is_nullable'      => $is_not_null ? 'NO' : 'YES',
				'column_key'       => $is_primary_key ? 'PRI' : '',
				'column_default'   => $is_auto ? null : $this->normalize_describe_default( $row['dflt_value'] ?? null ),
				'extra'            => $is_auto ? 'auto_increment' : '',
				'collation_name'   => null,
				'comment'          => '',
			);
		}

		return $rows;
	}

	/**
	 * Build one MySQL-shaped information_schema.columns row.
	 *
	 * @param string              $table_name Table name.
	 * @param array<string,mixed> $metadata   Column metadata.
	 * @return array<string,mixed>
	 */
	private function information_schema_column_row( string $table_name, array $metadata ): array {
		$type_attributes = $this->column_type_attributes(
			(string) $metadata['column_type'],
			null === $metadata['collation_name'] ? null : (string) $metadata['collation_name']
		);

		return array(
			'TABLE_CATALOG'            => 'def',
			'TABLE_SCHEMA'             => $this->database,
			'TABLE_NAME'               => $table_name,
			'COLUMN_NAME'              => $metadata['column_name'],
			'ORDINAL_POSITION'         => (int) $metadata['ordinal_position'],
			'COLUMN_DEFAULT'           => $metadata['column_default'],
			'IS_NULLABLE'              => $metadata['is_nullable'],
			'DATA_TYPE'                => $type_attributes['data_type'],
			'CHARACTER_MAXIMUM_LENGTH' => $type_attributes['character_maximum_length'],
			'CHARACTER_OCTET_LENGTH'   => $type_attributes['character_octet_length'],
			'NUMERIC_PRECISION'        => $type_attributes['numeric_precision'],
			'NUMERIC_SCALE'            => $type_attributes['numeric_scale'],
			'DATETIME_PRECISION'       => $type_attributes['datetime_precision'],
			'CHARACTER_SET_NAME'       => $this->character_set_from_collation( $metadata['collation_name'] ),
			'COLLATION_NAME'           => $metadata['collation_name'],
			'COLUMN_TYPE'              => $metadata['column_type'],
			'COLUMN_KEY'               => $metadata['column_key'],
			'EXTRA'                    => $metadata['extra'],
			'PRIVILEGES'               => 'select,insert,update,references',
			'COLUMN_COMMENT'           => $metadata['comment'],
			'GENERATION_EXPRESSION'    => '',
			'SRS_ID'                   => null,
		);
	}

	/**
	 * Derive information_schema.columns type attributes from a MySQL column type.
	 *
	 * @param string      $column_type    MySQL-facing column type.
	 * @param string|null $collation_name Optional collation name.
	 * @return array<string,mixed>
	 */
	private function column_type_attributes( string $column_type, ?string $collation_name ): array {
		$normalized   = strtolower( trim( $column_type ) );
		$data_type    = $this->data_type_from_column_type( $normalized );
		$length       = $this->column_type_length( $normalized );
		$charset      = $this->character_set_from_collation( $collation_name );
		$char_length  = null;
		$octet_length = null;

		if ( in_array( $data_type, array( 'char', 'varchar' ), true ) ) {
			$char_length  = $length ?? 1;
			$octet_length = $char_length * $this->charset_max_bytes( $charset );
		} elseif ( 'tinytext' === $data_type || 'tinyblob' === $data_type ) {
			$char_length  = 255;
			$octet_length = 255;
		} elseif ( 'text' === $data_type || 'blob' === $data_type ) {
			$char_length  = 65535;
			$octet_length = 65535;
		} elseif ( 'mediumtext' === $data_type || 'mediumblob' === $data_type ) {
			$char_length  = 16777215;
			$octet_length = 16777215;
		} elseif ( 'longtext' === $data_type || 'longblob' === $data_type ) {
			$char_length  = 4294967295;
			$octet_length = 4294967295;
		}

		list( $numeric_precision, $numeric_scale ) = $this->numeric_attributes_from_data_type( $data_type, $normalized );

		return array(
			'data_type'                => $data_type,
			'character_maximum_length' => $char_length,
			'character_octet_length'   => $octet_length,
			'numeric_precision'        => $numeric_precision,
			'numeric_scale'            => $numeric_scale,
			'datetime_precision'       => in_array( $data_type, array( 'time', 'datetime', 'timestamp' ), true ) ? 0 : null,
		);
	}

	/**
	 * Extract the normalized data type from a column type.
	 *
	 * @param string $column_type MySQL-facing column type.
	 * @return string Data type.
	 */
	private function data_type_from_column_type( string $column_type ): string {
		if ( preg_match( '/^([a-z]+)/', $column_type, $matches ) ) {
			$data_type = $matches[1];
		} else {
			$data_type = $column_type;
		}

		$map = array(
			'integer' => 'int',
			'boolean' => 'tinyint',
		);

		return $map[ $data_type ] ?? $data_type;
	}

	/**
	 * Extract the first numeric length from a column type.
	 *
	 * @param string $column_type MySQL-facing column type.
	 * @return int|null Length.
	 */
	private function column_type_length( string $column_type ): ?int {
		if ( preg_match( '/\((\d+)/', $column_type, $matches ) ) {
			return (int) $matches[1];
		}

		return null;
	}

	/**
	 * Derive numeric precision and scale.
	 *
	 * @param string $data_type   Normalized data type.
	 * @param string $column_type MySQL-facing column type.
	 * @return array{0:int|null,1:int|null}
	 */
	private function numeric_attributes_from_data_type( string $data_type, string $column_type ): array {
		$precision_map = array(
			'tinyint'   => 3,
			'smallint'  => 5,
			'mediumint' => 7,
			'int'       => 10,
			'bigint'    => false === strpos( $column_type, 'unsigned' ) ? 19 : 20,
			'float'     => 12,
			'double'    => 22,
		);

		if ( array_key_exists( $data_type, $precision_map ) ) {
			return array( $precision_map[ $data_type ], 0 );
		}

		if ( 'decimal' === $data_type ) {
			if ( preg_match( '/\((\d+)(?:\s*,\s*(\d+))?\)/', $column_type, $matches ) ) {
				return array( (int) $matches[1], isset( $matches[2] ) ? (int) $matches[2] : 0 );
			}
			return array( 10, 0 );
		}

		return array( null, null );
	}

	/**
	 * Derive a character set from a collation.
	 *
	 * @param mixed $collation_name Collation name.
	 * @return string|null Character set.
	 */
	private function character_set_from_collation( $collation_name ): ?string {
		if ( null === $collation_name || '' === $collation_name ) {
			return null;
		}

		$parts = explode( '_', (string) $collation_name );
		return $parts[0] ?? null;
	}

	/**
	 * Get max bytes per character for common charsets.
	 *
	 * @param string|null $charset Character set.
	 * @return int Max bytes.
	 */
	private function charset_max_bytes( ?string $charset ): int {
		if ( 'utf8mb4' === $charset ) {
			return 4;
		}
		if ( 'utf8' === $charset || 'utf8mb3' === $charset ) {
			return 3;
		}

		return 1;
	}

	/**
	 * Check whether a table has at least one row.
	 *
	 * @param string $table_name Table name.
	 * @return bool Whether any row exists.
	 */
	private function table_has_rows( string $table_name ): bool {
		$stmt = $this->execute_duckdb_query(
			'SELECT 1 FROM '
				. $this->connection->quote_identifier( $table_name )
				. ' LIMIT 1',
			'Failed to inspect DuckDB table rows'
		);

		return false !== $stmt->fetch( PDO::FETCH_NUM );
	}

	/**
	 * Read an identifier token value.
	 *
	 * @param WP_Parser_Token|null $token Token.
	 * @return string Identifier value.
	 */
	private function identifier_value( $token ): string {
		if ( ! $token instanceof WP_Parser_Token || $this->is_non_identifier_token( $token ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Expected a MySQL identifier in DuckDB driver statement.' );
		}
		return $token->get_value();
	}

	/**
	 * Quote an identifier for MySQL-facing SHOW CREATE TABLE output.
	 *
	 * @param string $identifier Identifier.
	 * @return string Backtick-quoted identifier.
	 */
	private function quote_mysql_identifier( string $identifier ): string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	/**
	 * Quote a string literal for MySQL-facing SHOW CREATE TABLE output.
	 *
	 * @param string $literal UTF-8 literal.
	 * @return string Single-quoted MySQL literal.
	 */
	private function quote_mysql_utf8_string_literal( string $literal ): string {
		$backslash    = chr( 92 );
		$replacements = array(
			"'"        => "''",
			$backslash => $backslash . $backslash,
			chr( 0 )   => $backslash . '0',
			chr( 10 )  => $backslash . 'n',
			chr( 13 )  => $backslash . 'r',
		);

		return "'" . strtr( $literal, $replacements ) . "'";
	}

	/**
	 * Check whether a token cannot be an identifier in the supported subset.
	 *
	 * @param WP_Parser_Token $token Token.
	 * @return bool
	 */
	private function is_non_identifier_token( WP_Parser_Token $token ): bool {
		return in_array(
			$token->id,
			array(
				WP_MySQL_Lexer::EOF,
				WP_MySQL_Lexer::OPEN_PAR_SYMBOL,
				WP_MySQL_Lexer::CLOSE_PAR_SYMBOL,
				WP_MySQL_Lexer::COMMA_SYMBOL,
				WP_MySQL_Lexer::DOT_SYMBOL,
				WP_MySQL_Lexer::SEMICOLON_SYMBOL,
				WP_MySQL_Lexer::SINGLE_QUOTED_TEXT,
				WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT,
				WP_MySQL_Lexer::INT_NUMBER,
				WP_MySQL_Lexer::LONG_NUMBER,
				WP_MySQL_Lexer::ULONGLONG_NUMBER,
				WP_MySQL_Lexer::DECIMAL_NUMBER,
				WP_MySQL_Lexer::FLOAT_NUMBER,
				WP_MySQL_Lexer::EQUAL_OPERATOR,
			),
			true
		);
	}

	/**
	 * Expect a specific token at an index.
	 *
	 * @param WP_Parser_Token[] $tokens  Token stream.
	 * @param int               $index   Index.
	 * @param int               $token_id Expected token ID.
	 * @param string            $message Error message.
	 */
	private function expect_token( array $tokens, int $index, int $token_id, string $message ): void {
		if ( ! isset( $tokens[ $index ] ) || $token_id !== $tokens[ $index ]->id ) {
			throw new WP_DuckDB_Driver_Exception( $message );
		}
	}

	/**
	 * Skip an option value, accepting optional equals.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Index at optional equals or value.
	 * @return int New index.
	 */
	private function skip_option_value( array $tokens, int $index ): int {
		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::EQUAL_OPERATOR === $tokens[ $index ]->id ) {
			++$index;
		}
		if ( ! isset( $tokens[ $index ] ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Expected option value in DuckDB driver statement.' );
		}
		return $index + 1;
	}

	/**
	 * Skip a balanced parenthesized token sequence.
	 *
	 * @param WP_Parser_Token[] $tokens Token stream.
	 * @param int               $index  Index at opening parenthesis.
	 * @return int Index after the closing parenthesis.
	 */
	private function skip_balanced_parentheses( array $tokens, int $index ): int {
		$depth = 0;
		for ( ; $index < count( $tokens ); ++$index ) {
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $index ]->id ) {
				++$depth;
			} elseif ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $index ]->id ) {
				--$depth;
				if ( 0 === $depth ) {
					return $index + 1;
				}
			}
		}
		throw new WP_DuckDB_Driver_Exception( 'Unbalanced parentheses in DuckDB driver statement.' );
	}

	/**
	 * Check if a token is a number literal.
	 *
	 * @param WP_Parser_Token $token Token.
	 * @return bool
	 */
	private function is_number_token( WP_Parser_Token $token ): bool {
		return in_array(
			$token->id,
			array(
				WP_MySQL_Lexer::INT_NUMBER,
				WP_MySQL_Lexer::LONG_NUMBER,
				WP_MySQL_Lexer::ULONGLONG_NUMBER,
				WP_MySQL_Lexer::DECIMAL_NUMBER,
				WP_MySQL_Lexer::FLOAT_NUMBER,
			),
			true
		);
	}

	/**
	 * Check whether a token is a numeric sign.
	 *
	 * @param WP_Parser_Token $token Token.
	 * @return bool Whether the token is + or -.
	 */
	private function is_sign_token( WP_Parser_Token $token ): bool {
		return WP_MySQL_Lexer::PLUS_OPERATOR === $token->id || WP_MySQL_Lexer::MINUS_OPERATOR === $token->id;
	}

	/**
	 * Convert a number token to a PHP scalar.
	 *
	 * @param WP_Parser_Token $token Number token.
	 * @return int|float Number value.
	 */
	private function number_token_value( WP_Parser_Token $token ) {
		if ( WP_MySQL_Lexer::DECIMAL_NUMBER === $token->id || WP_MySQL_Lexer::FLOAT_NUMBER === $token->id ) {
			return (float) $token->get_value();
		}

		return (int) $token->get_value();
	}

	/**
	 * Convert a signed number token pair to a PHP scalar.
	 *
	 * @param WP_Parser_Token $sign  Sign token.
	 * @param WP_Parser_Token $token Number token.
	 * @return int|float Number value.
	 */
	private function signed_number_token_value( WP_Parser_Token $sign, WP_Parser_Token $token ) {
		$value = $this->number_token_value( $token );
		if ( WP_MySQL_Lexer::MINUS_OPERATOR === $sign->id ) {
			return -$value;
		}

		return $value;
	}

	/**
	 * Check if a DuckDB type is integer-like.
	 *
	 * @param string $duck_type DuckDB type.
	 * @return bool
	 */
	private function is_integer_duckdb_type( string $duck_type ): bool {
		return in_array( $duck_type, array( 'TINYINT', 'SMALLINT', 'INTEGER', 'BIGINT' ), true );
	}

	/**
	 * Create a deterministic sequence name for AUTO_INCREMENT.
	 *
	 * @param string $table_name  Table name.
	 * @param string $column_name Column name.
	 * @return string Sequence name.
	 */
	private function sequence_name( string $table_name, string $column_name, bool $temporary = false ): string {
		return self::SEQUENCE_PREFIX . substr( hash( 'sha256', $this->table_namespace_key( $table_name, $temporary ) . "\0" . $column_name ), 0, 16 );
	}

	/**
	 * Create a deterministic schema-safe index name.
	 *
	 * @param string $table_name       Table name.
	 * @param string $mysql_index_name MySQL index name.
	 * @return string DuckDB index name.
	 */
	private function index_name( string $table_name, string $mysql_index_name, bool $temporary = false ): string {
		return self::INDEX_PREFIX . substr( hash( 'sha256', $this->table_namespace_key( $table_name, $temporary ) ), 0, 8 ) . '_' . $mysql_index_name;
	}

	/**
	 * Build a stable namespace key for physical helper objects.
	 *
	 * @param string $table_name Table name.
	 * @param bool   $temporary  Whether the table is temporary.
	 * @return string Namespace key.
	 */
	private function table_namespace_key( string $table_name, bool $temporary ): string {
		return ( $temporary ? 'temporary' : 'persistent' ) . "\0" . $table_name;
	}

	/**
	 * Normalize a DuckDB default expression for DESCRIBE output.
	 *
	 * @param mixed $default_value Default expression.
	 * @return string|null
	 */
	private function normalize_describe_default( $default_value ): ?string {
		if ( null === $default_value ) {
			return null;
		}

		$default_value = (string) $default_value;
		if ( 0 === strcasecmp( $default_value, 'NULL' ) ) {
			return null;
		}

		if ( strlen( $default_value ) >= 2 && "'" === $default_value[0] && "'" === $default_value[ strlen( $default_value ) - 1 ] ) {
			return str_replace( "''", "'", substr( $default_value, 1, -1 ) );
		}

		return $default_value;
	}

	/**
	 * Format the emulated MySQL version.
	 *
	 * @return string
	 */
	private function format_mysql_version(): string {
		$major = (int) floor( $this->mysql_version / 10000 );
		$minor = (int) floor( ( $this->mysql_version % 10000 ) / 100 );
		$patch = $this->mysql_version % 100;

		return sprintf( '%d.%d.%d-DuckDB', $major, $minor, $patch );
	}

	/**
	 * Format the emulated MySQL version for @@version readback.
	 *
	 * @return string
	 */
	private function format_mysql_system_variable_version(): string {
		$major = (int) floor( $this->mysql_version / 10000 );
		$minor = (int) floor( ( $this->mysql_version % 10000 ) / 100 );
		$patch = $this->mysql_version % 100;

		return sprintf( '%d.%d.%d', $major, $minor, $patch );
	}

	/**
	 * Build an unsupported statement exception.
	 *
	 * @param WP_Parser_Token $token First token.
	 * @return WP_DuckDB_Driver_Exception
	 */
	private function new_unsupported_statement_exception( WP_Parser_Token $token ): WP_DuckDB_Driver_Exception {
		return new WP_DuckDB_Driver_Exception(
			'Unsupported DuckDB MySQL-emulation statement: ' . strtoupper( $token->get_bytes() ) . '.'
		);
	}
}
