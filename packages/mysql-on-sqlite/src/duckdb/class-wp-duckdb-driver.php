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
	const MYSQL_GRAMMAR_PATH                  = __DIR__ . '/../mysql/mysql-grammar.php';
	const DEFAULT_DATABASE                    = 'wp';
	const DEFAULT_MYSQL_VERSION               = 80038;
	const SEQUENCE_PREFIX                     = 'wp_duckdb_ai_';
	const INDEX_PREFIX                        = 'wp_duckdb_idx_';
	const INDEX_METADATA_TABLE                = '__wp_duckdb_index_metadata';
	const COLUMN_METADATA_TABLE               = '__wp_duckdb_column_metadata';
	const TABLE_METADATA_TABLE                = '__wp_duckdb_table_metadata';
	const INFO_SCHEMA_TABLES_TABLE            = '__wp_duckdb_information_schema_tables';
	const INFO_SCHEMA_COLUMNS_TABLE           = '__wp_duckdb_information_schema_columns';
	const INFO_SCHEMA_STATISTICS_TABLE        = '__wp_duckdb_information_schema_statistics';
	const INFO_SCHEMA_TABLE_CONSTRAINTS_TABLE = '__wp_duckdb_information_schema_table_constraints';
	const INFO_SCHEMA_KEY_COLUMN_USAGE_TABLE  = '__wp_duckdb_information_schema_key_column_usage';

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

		$tokens = $this->tokenize_and_validate( $query );
		if ( count( $tokens ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported DuckDB MySQL-emulation statement: empty query.' );
		}

		switch ( $tokens[0]->id ) {
			case WP_MySQL_Lexer::SELECT_SYMBOL:
				return $this->execute_select( $tokens );
			case WP_MySQL_Lexer::CREATE_SYMBOL:
				return $this->execute_create( $tokens );
			case WP_MySQL_Lexer::INSERT_SYMBOL:
				return $this->execute_insert( $tokens );
			case WP_MySQL_Lexer::REPLACE_SYMBOL:
				return $this->execute_replace( $tokens );
			case WP_MySQL_Lexer::UPDATE_SYMBOL:
				return $this->execute_update( $tokens );
			case WP_MySQL_Lexer::DELETE_SYMBOL:
				return $this->execute_delete( $tokens );
			case WP_MySQL_Lexer::ALTER_SYMBOL:
				return $this->execute_alter_table( $tokens );
			case WP_MySQL_Lexer::SHOW_SYMBOL:
				return $this->execute_show( $tokens );
			case WP_MySQL_Lexer::DESCRIBE_SYMBOL:
			case WP_MySQL_Lexer::DESC_SYMBOL:
				return $this->execute_describe( $tokens );
		}

		throw $this->new_unsupported_statement_exception( $tokens[0] );
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
		$rewrite_information_schema_tables            = $this->uses_information_schema_tables( $tokens );
		$rewrite_information_schema_columns           = $this->uses_information_schema_columns( $tokens );
		$rewrite_information_schema_statistics        = $this->uses_information_schema_statistics( $tokens );
		$rewrite_information_schema_table_constraints = $this->uses_information_schema_table_constraints( $tokens );
		$rewrite_information_schema_key_column_usage  = $this->uses_information_schema_key_column_usage( $tokens );
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

		return $this->execute_duckdb_query(
			$this->translate_tokens_to_duckdb_sql(
				$tokens,
				$rewrite_information_schema_tables,
				$rewrite_information_schema_columns,
				$rewrite_information_schema_statistics,
				$rewrite_information_schema_table_constraints,
				$rewrite_information_schema_key_column_usage
			),
			'Unsupported DuckDB MySQL-emulation SELECT statement'
		);
	}

	/**
	 * Execute a supported CREATE statement.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_create( array $tokens ): WP_DuckDB_Result_Statement {
		if ( isset( $tokens[1] ) && WP_MySQL_Lexer::TABLE_SYMBOL === $tokens[1]->id ) {
			return $this->execute_create_table( $tokens );
		}

		if ( $this->is_create_index_statement( $tokens ) ) {
			return $this->execute_create_index( $tokens );
		}

		throw new WP_DuckDB_Driver_Exception( 'Unsupported CREATE statement in DuckDB driver. Only CREATE TABLE and CREATE INDEX are supported.' );
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

		$columns     = array();
		$constraints = array();
		$indexes     = array();
		$sequences   = array();
		$metadata    = array();
		$primary_key = array();

		foreach ( $items as $item ) {
			if ( count( $item ) === 0 ) {
				continue;
			}

			if ( WP_MySQL_Lexer::PRIMARY_SYMBOL === $item[0]->id ) {
				$primary_key   = $this->table_primary_key_columns( $item );
				$constraints[] = $this->translate_table_primary_key( $item );
				continue;
			}

			if ( $this->is_create_table_index_item( $item ) ) {
				$indexes[] = $this->translate_create_table_index( $table_name, $item );
				continue;
			}

			if ( WP_MySQL_Lexer::CONSTRAINT_SYMBOL === $item[0]->id ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported CREATE TABLE constraint in DuckDB driver: ' . $item[0]->get_bytes() . '.' );
			}

			list( $column_sql, $sequence_sql, $column_indexes, $column_metadata ) = $this->translate_create_table_column( $table_name, $item );
			$columns[]  = $column_sql;
			$metadata[] = $column_metadata;
			if ( null !== $sequence_sql ) {
				$sequences[] = $sequence_sql;
			}
			foreach ( $column_indexes as $index_definition ) {
				$indexes[] = $index_definition;
			}
		}

		if ( count( $columns ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( 'CREATE TABLE requires at least one column.' );
		}

		foreach ( $sequences as $sequence_sql ) {
			$this->execute_duckdb_query( $sequence_sql, 'Failed to create DuckDB AUTO_INCREMENT sequence' );
		}

		$table_sql = 'CREATE TABLE ';
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
		$this->record_column_metadata( $table_name, $this->apply_column_key_metadata( $metadata, $primary_key, $indexes ) );
		$this->record_table_metadata( $table_name, $table_metadata );

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

		$index                                     = $this->skip_optional_index_type( $tokens, $index );
		list( $columns, $column_metadata, $index ) = $this->translate_index_column_list( $tokens, $index );
		$this->assert_supported_index_options( $tokens, $index );

		$index_definition = $this->build_secondary_index_definition( $table_name, $mysql_index_name, $unique, $columns, $column_metadata );
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
		$this->identifier_value( $tokens[1] ?? null );
		if ( ! isset( $tokens[2] ) || WP_MySQL_Lexer::SET_SYMBOL !== $tokens[2]->id ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported UPDATE statement in DuckDB driver. Only simple single-table UPDATE is supported.' );
		}

		return $this->execute_duckdb_query(
			$this->translate_tokens_to_duckdb_sql( $tokens ),
			'Failed to execute DuckDB UPDATE'
		);
	}

	/**
	 * Execute a supported DELETE statement.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_delete( array $tokens ): WP_DuckDB_Result_Statement {
		if ( ! isset( $tokens[1] ) || WP_MySQL_Lexer::FROM_SYMBOL !== $tokens[1]->id ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported DELETE statement in DuckDB driver. Only DELETE FROM table is supported.' );
		}
		$this->identifier_value( $tokens[2] ?? null );
		if ( isset( $tokens[3] ) && WP_MySQL_Lexer::WHERE_SYMBOL !== $tokens[3]->id ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported DELETE statement in DuckDB driver. Only simple single-table DELETE is supported.' );
		}

		return $this->execute_duckdb_query(
			$this->translate_tokens_to_duckdb_sql( $tokens ),
			'Failed to execute DuckDB DELETE'
		);
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

		$table_name = $this->identifier_value( $tokens[ $index ] ?? null );
		++$index;

		$actions = $this->split_top_level_comma_items( array_slice( $tokens, $index ) );
		if ( count( $actions ) === 0 ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. Only ADD COLUMN and ADD INDEX are supported.' );
		}

		$result = null;
		foreach ( $actions as $action ) {
			$this->expect_token( $action, 0, WP_MySQL_Lexer::ADD_SYMBOL, 'Unsupported ALTER TABLE statement in DuckDB driver. Only ADD actions are supported.' );
			$alter_item = array_slice( $action, 1 );

			$result = $this->is_create_table_index_item( $alter_item )
				? $this->execute_alter_table_add_index( $table_name, $alter_item )
				: $this->execute_alter_table_add_column( $table_name, $alter_item );
		}

		return $result ?? new WP_DuckDB_Result_Statement( array(), array(), 0 );
	}

	/**
	 * Execute ALTER TABLE ... ADD INDEX.
	 *
	 * @param string            $table_name Table name.
	 * @param WP_Parser_Token[] $tokens     Index definition tokens after ADD.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_alter_table_add_index( string $table_name, array $tokens ): WP_DuckDB_Result_Statement {
		$index_definition = $this->translate_create_table_index( $table_name, $tokens );
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
	private function execute_alter_table_add_column( string $table_name, array $tokens ): WP_DuckDB_Result_Statement {
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

		list( $column_sql, $sequence_sql, $indexes, $metadata ) = $this->translate_create_table_column( $table_name, $tokens, false, true );
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
				}
			);
		}

		foreach ( $indexes as $index_definition ) {
			$this->execute_duckdb_query( $index_definition['sql'], 'Failed to create DuckDB index' );
			$this->record_index_metadata( $index_definition );
		}
		$this->append_column_metadata( $table_name, $metadata );

		return $result;
	}

	/**
	 * Execute supported SHOW statements.
	 *
	 * @param WP_Parser_Token[] $tokens MySQL tokens.
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_show( array $tokens ): WP_DuckDB_Result_Statement {
		if ( 2 === count( $tokens ) && WP_MySQL_Lexer::TABLES_SYMBOL === $tokens[1]->id ) {
			return $this->execute_show_tables();
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

		$resolved_table_name = $this->resolve_user_table_name( $table_name );
		if ( null === $resolved_table_name ) {
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
					$this->mysql_create_table_statement( $resolved_table_name, $table_name ),
				),
			)
		);
	}

	/**
	 * Build a MySQL-shaped SHOW CREATE TABLE statement from DuckDB metadata.
	 *
	 * @param string $table_name          Resolved physical table name.
	 * @param string $requested_table_name Requested MySQL table name.
	 * @return string MySQL CREATE TABLE statement.
	 */
	private function mysql_create_table_statement( string $table_name, string $requested_table_name ): string {
		$metadata_by_table  = $this->table_metadata_by_table();
		$table_metadata     = $metadata_by_table[ $table_name ] ?? $this->fallback_table_metadata( $table_name );
		$table_info         = $this->information_schema_table_row( $table_name, $table_metadata );
		$column_rows        = $this->show_create_table_column_rows( $table_name );
		$rows               = array();
		$has_auto_increment = false;

		foreach ( $column_rows as $column ) {
			$rows[] = $this->format_show_create_table_column( $column, $has_auto_increment );
		}

		foreach ( $this->show_create_table_index_groups( $table_name ) as $index_group ) {
			$rows[] = $this->format_show_create_table_index( $index_group );
		}

		$sql  = 'CREATE TABLE ' . $this->quote_mysql_identifier( $requested_table_name ) . " (\n";
		$sql .= implode( ",\n", $rows );
		$sql .= "\n)";
		$sql .= ' ENGINE=' . (string) $table_info['ENGINE'];

		$auto_increment = $table_info['AUTO_INCREMENT'];
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

		if ( ! $full ) {
			$rows = $this->describe_column_rows( $table_name );
			if ( null !== $like_pattern ) {
				$rows = $this->filter_column_rows_by_like( $rows, $like_pattern );
			}

			return new WP_DuckDB_Result_Statement(
				array( 'Field', 'Type', 'Null', 'Key', 'Default', 'Extra' ),
				$rows
			);
		}

		$full_rows = $this->full_describe_column_rows( $table_name );
		if ( null !== $like_pattern ) {
			$full_rows = $this->filter_column_rows_by_like( $full_rows, $like_pattern );
		}

		return new WP_DuckDB_Result_Statement(
			array( 'Field', 'Type', 'Collation', 'Null', 'Key', 'Default', 'Extra', 'Privileges', 'Comment' ),
			$full_rows
		);
	}

	/**
	 * Execute SHOW TABLES.
	 *
	 * @return WP_DuckDB_Result_Statement
	 */
	private function execute_show_tables(): WP_DuckDB_Result_Statement {
		$column = 'Tables_in_' . $this->database;
		return $this->execute_duckdb_query(
			'SELECT table_name AS ' . $this->connection->quote_identifier( $column )
				. " FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' AND table_name <> "
				. $this->connection->quote( self::INDEX_METADATA_TABLE )
				. ' AND table_name <> '
				. $this->connection->quote( self::COLUMN_METADATA_TABLE )
				. ' AND table_name <> '
				. $this->connection->quote( self::TABLE_METADATA_TABLE )
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
				. ' ORDER BY table_name',
			'Failed to execute SHOW TABLES'
		);
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

		$table_name = $this->identifier_value( $tokens[3] );

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
			$this->index_rows_for_table( $table_name )
		);
	}

	/**
	 * Build SHOW INDEX-compatible rows for all indexes on a table.
	 *
	 * @param string $table_name Table name.
	 * @return array<int,array<int,mixed>>
	 */
	private function index_rows_for_table( string $table_name ): array {
		return array_merge(
			$this->primary_key_index_rows( $table_name ),
			$this->secondary_index_rows( $table_name )
		);
	}

	/**
	 * Build information_schema-shaped column rows for SHOW CREATE TABLE.
	 *
	 * @param string $table_name Table name.
	 * @return array<int,array<string,mixed>>
	 */
	private function show_create_table_column_rows( string $table_name ): array {
		$metadata_rows = $this->column_metadata_rows( $table_name );
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
	private function show_create_table_index_groups( string $table_name ): array {
		$groups = array();

		foreach ( $this->index_rows_for_table( $table_name ) as $row ) {
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
	private function secondary_index_rows( string $table_name ): array {
		$this->ensure_index_metadata_table();

		$stmt = $this->execute_duckdb_query(
			'SELECT index_name, non_unique, seq_in_index, column_name, sub_part FROM '
				. $this->connection->quote_identifier( self::INDEX_METADATA_TABLE )
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
			$this->describe_column_rows( $this->identifier_value( $tokens[1] ) )
		);
	}

	/**
	 * Build MySQL DESCRIBE/SHOW COLUMNS rows from DuckDB table metadata.
	 *
	 * @param string $table_name Table name.
	 * @return array<int,array<int,mixed>>
	 */
	private function describe_column_rows( string $table_name ): array {
		$metadata_rows = $this->column_metadata_rows( $table_name );
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
	private function full_describe_column_rows( string $table_name ): array {
		$metadata_rows = $this->column_metadata_rows( $table_name );
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
		foreach ( $this->describe_column_rows( $table_name ) as $row ) {
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
	 * @return array{0:string,1:string|null,2:array<int,array{sql:string,table_name:string,index_name:string,unique:bool,columns:array<int,array{name:string,sub_part:int|null}>}>,3:array<string,mixed>}
	 */
	private function translate_create_table_column( string $table_name, array $tokens, bool $include_inline_constraints = true, bool $allow_position_options = false ): array {
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

		$column_sql = $this->connection->quote_identifier( $column_name ) . ' ' . $duck_type;
		$sequence   = null;

		if ( $auto_increment ) {
			$sequence_name = $this->sequence_name( $table_name, $column_name );
			$sequence      = 'CREATE SEQUENCE IF NOT EXISTS ' . $this->connection->quote_identifier( $sequence_name ) . ' START 1';
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
				)
			);
		}

		$metadata = array(
			'column_name'    => $column_name,
			'column_type'    => $this->mysql_column_type_from_tokens( $tokens, $type_index ),
			'is_nullable'    => $not_null ? 'NO' : 'YES',
			'column_key'     => $primary_key ? 'PRI' : ( $unique_key ? 'UNI' : '' ),
			'column_default' => $auto_increment || null === $default_sql ? null : $this->normalize_describe_default( $default_sql ),
			'extra'          => $auto_increment ? 'auto_increment' : '',
			'collation_name' => $this->mysql_column_collation_from_tokens( $tokens, $type_token ),
			'comment'        => $this->mysql_column_comment_from_tokens( $tokens ),
		);

		return array( $column_sql, $sequence, $indexes, $metadata );
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
	private function mysql_column_collation_from_tokens( array $tokens, WP_Parser_Token $type_token ): ?string {
		for ( $index = 0; $index < count( $tokens ); ++$index ) {
			if ( WP_MySQL_Lexer::COLLATE_SYMBOL === $tokens[ $index ]->id ) {
				return $this->option_value( $tokens, $index + 1 );
			}
		}

		return $this->mysql_type_has_collation( $type_token ) ? 'utf8mb4_0900_ai_ci' : null;
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

			foreach ( $indexes as $index_definition ) {
				if ( count( $index_definition['columns'] ) === 0 ) {
					continue;
				}
				if ( strtolower( $index_definition['columns'][0]['name'] ) !== $column_name ) {
					continue;
				}
				$column['column_key'] = $index_definition['unique'] ? 'UNI' : 'MUL';
				break;
			}
		}
		unset( $column );

		return $metadata;
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
	private function translate_create_table_index( string $table_name, array $tokens ): array {
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

		return $this->build_secondary_index_definition( $table_name, $mysql_index_name, $unique, $columns, $column_metadata );
	}

	/**
	 * Build a DuckDB secondary index definition and SHOW INDEX metadata.
	 *
	 * @param string                                                                 $table_name       Table name.
	 * @param string                                                                 $mysql_index_name MySQL index name.
	 * @param bool                                                                   $unique           Whether the index is unique.
	 * @param string[]                                                               $columns          DuckDB column SQL fragments.
	 * @param array<int,array{name:string,sub_part:int|null}>                        $column_metadata  MySQL column metadata.
	 * @return array{sql:string,table_name:string,index_name:string,unique:bool,columns:array<int,array{name:string,sub_part:int|null}>}
	 */
	private function build_secondary_index_definition( string $table_name, string $mysql_index_name, bool $unique, array $columns, array $column_metadata ): array {
		return array(
			'sql'        => 'CREATE '
				. ( $unique ? 'UNIQUE ' : '' )
				. 'INDEX IF NOT EXISTS '
				. $this->connection->quote_identifier( $this->index_name( $table_name, $mysql_index_name ) )
				. ' ON '
				. $this->connection->quote_identifier( $table_name )
				. ' ('
				. implode( ', ', $columns )
				. ')',
			'table_name' => $table_name,
			'index_name' => $mysql_index_name,
			'unique'     => $unique,
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
	 * @return array{engine:string,row_format:string,table_collation:string,table_comment:string,create_options:string}
	 */
	private function parse_create_table_options( array $tokens ): array {
		$engine          = 'InnoDB';
		$table_collation = 'utf8mb4_0900_ai_ci';
		$table_comment   = '';
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
				|| WP_MySQL_Lexer::AUTO_INCREMENT_SYMBOL === $token->id
				|| WP_MySQL_Lexer::ROW_FORMAT_SYMBOL === $token->id
			) {
				$index = $this->skip_option_value( $tokens, $index + 1 );
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
		);
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
		bool $rewrite_information_schema_key_column_usage = false
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

			if (
				WP_MySQL_Lexer::FROM_SYMBOL === $token->id
				&& isset( $tokens[ $index + 1 ] )
				&& WP_MySQL_Lexer::DUAL_SYMBOL === $tokens[ $index + 1 ]->id
			) {
				++$index;
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

			$pieces[] = $token->get_bytes();
		}

		return $this->join_sql_pieces( $pieces );
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
		$where = array();
		foreach ( $column_set as $column_name ) {
			$where[] = $this->connection->quote_identifier( $column_name )
				. ' IS NOT DISTINCT FROM ('
				. $values_by_column[ strtolower( $column_name ) ]
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
		$metadata           = $this->auto_increment_metadata_for_table( $table_name );
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
	private function auto_increment_metadata_for_table( string $table_name ): ?array {
		try {
			$stmt = $this->connection->query(
				'SELECT column_name FROM '
					. $this->connection->quote_identifier( self::COLUMN_METADATA_TABLE )
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
			'sequence_name' => $this->sequence_name( $table_name, $column_name ),
		);
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
	private function ensure_index_metadata_table(): void {
		$this->execute_duckdb_query(
			'CREATE TABLE IF NOT EXISTS '
				. $this->connection->quote_identifier( self::INDEX_METADATA_TABLE )
				. ' (table_name VARCHAR, index_name VARCHAR, non_unique INTEGER, seq_in_index INTEGER, column_name VARCHAR, sub_part INTEGER)',
			'Failed to initialize DuckDB index metadata'
		);
	}

	/**
	 * Ensure the internal column metadata table exists.
	 */
	private function ensure_column_metadata_table(): void {
		$this->execute_duckdb_query(
			'CREATE TABLE IF NOT EXISTS '
				. $this->connection->quote_identifier( self::COLUMN_METADATA_TABLE )
				. ' (table_name VARCHAR, ordinal_position INTEGER, column_name VARCHAR, column_type VARCHAR, is_nullable VARCHAR, column_key VARCHAR, column_default VARCHAR, extra VARCHAR, collation_name VARCHAR, comment VARCHAR)',
			'Failed to initialize DuckDB column metadata'
		);
	}

	/**
	 * Ensure the internal table metadata table exists.
	 */
	private function ensure_table_metadata_table(): void {
		$this->execute_duckdb_query(
			'CREATE TABLE IF NOT EXISTS '
				. $this->connection->quote_identifier( self::TABLE_METADATA_TABLE )
				. ' (table_name VARCHAR, engine VARCHAR, row_format VARCHAR, table_collation VARCHAR, table_comment VARCHAR, create_options VARCHAR, create_time VARCHAR)',
			'Failed to initialize DuckDB table metadata'
		);
	}

	/**
	 * Record MySQL index metadata for SHOW INDEX.
	 *
	 * @param array{table_name:string,index_name:string,unique:bool,columns:array<int,array{name:string,sub_part:int|null}>} $index_definition Index definition.
	 */
	private function record_index_metadata( array $index_definition ): void {
		$this->ensure_index_metadata_table();

		$table_name = $index_definition['table_name'];
		$index_name = $index_definition['index_name'];

		$this->execute_duckdb_query(
			'DELETE FROM '
				. $this->connection->quote_identifier( self::INDEX_METADATA_TABLE )
				. ' WHERE table_name = '
				. $this->connection->quote( $table_name )
				. ' AND index_name = '
				. $this->connection->quote( $index_name ),
			'Failed to reset DuckDB index metadata'
		);

		foreach ( $index_definition['columns'] as $offset => $column ) {
			$this->execute_duckdb_query(
				'INSERT INTO '
					. $this->connection->quote_identifier( self::INDEX_METADATA_TABLE )
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
	private function execute_with_secondary_indexes_rebuilt( string $table_name, callable $callback ): void {
		$index_definitions = $this->secondary_index_definitions_for_table( $table_name );
		foreach ( $index_definitions as $index_definition ) {
			$this->execute_duckdb_query(
				'DROP INDEX IF EXISTS ' . $this->connection->quote_identifier( $this->index_name( $table_name, $index_definition['index_name'] ) ),
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
	private function secondary_index_definitions_for_table( string $table_name ): array {
		$this->ensure_index_metadata_table();

		$stmt = $this->execute_duckdb_query(
			'SELECT index_name, non_unique, seq_in_index, column_name, sub_part FROM '
				. $this->connection->quote_identifier( self::INDEX_METADATA_TABLE )
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
				$definition['columns']
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
	private function record_column_metadata( string $table_name, array $metadata ): void {
		$this->ensure_column_metadata_table();

		$this->execute_duckdb_query(
			'DELETE FROM '
				. $this->connection->quote_identifier( self::COLUMN_METADATA_TABLE )
				. ' WHERE table_name = '
				. $this->connection->quote( $table_name ),
			'Failed to reset DuckDB column metadata'
		);

		foreach ( $metadata as $offset => $column ) {
			$this->execute_duckdb_query(
				'INSERT INTO '
					. $this->connection->quote_identifier( self::COLUMN_METADATA_TABLE )
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
	private function record_table_metadata( string $table_name, array $metadata ): void {
		$this->ensure_table_metadata_table();

		$this->execute_duckdb_query(
			'DELETE FROM '
				. $this->connection->quote_identifier( self::TABLE_METADATA_TABLE )
				. ' WHERE table_name = '
				. $this->connection->quote( $table_name ),
			'Failed to reset DuckDB table metadata'
		);

		$this->execute_duckdb_query(
			'INSERT INTO '
				. $this->connection->quote_identifier( self::TABLE_METADATA_TABLE )
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
	 * Append one MySQL column metadata row when full table metadata is already recorded.
	 *
	 * @param string              $table_name Table name.
	 * @param array<string,mixed> $column     Column metadata.
	 */
	private function append_column_metadata( string $table_name, array $column ): void {
		$this->ensure_column_metadata_table();

		$stmt = $this->execute_duckdb_query(
			'SELECT COUNT(*) AS column_count, COALESCE(MAX(ordinal_position), 0) AS max_ordinal FROM '
				. $this->connection->quote_identifier( self::COLUMN_METADATA_TABLE )
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
				. $this->connection->quote_identifier( self::COLUMN_METADATA_TABLE )
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
	 * Read recorded MySQL column metadata.
	 *
	 * @param string $table_name Table name.
	 * @return array<int,array<string,mixed>>
	 */
	private function column_metadata_rows( string $table_name ): array {
		$this->ensure_column_metadata_table();

		$stmt = $this->execute_duckdb_query(
			'SELECT ordinal_position, column_name, column_type, is_nullable, column_key, column_default, extra, collation_name, comment FROM '
				. $this->connection->quote_identifier( self::COLUMN_METADATA_TABLE )
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
	private function information_schema_table_row( string $table_name, array $metadata ): array {
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
			'AUTO_INCREMENT'  => $this->table_auto_increment_value( $table_name ),
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
	private function table_metadata_by_table(): array {
		$this->ensure_table_metadata_table();

		$stmt = $this->execute_duckdb_query(
			'SELECT table_name, engine, row_format, table_collation, table_comment, create_options, create_time FROM '
				. $this->connection->quote_identifier( self::TABLE_METADATA_TABLE )
				. ' ORDER BY table_name',
			'Failed to inspect DuckDB table metadata'
		);

		$metadata = array();
		foreach ( $stmt->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
			$metadata[ (string) $row['table_name'] ] = $row;
		}

		return $metadata;
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
	private function table_auto_increment_value( string $table_name ): ?int {
		$metadata = $this->auto_increment_metadata_for_table( $table_name );
		if ( null === $metadata ) {
			return null;
		}

		$current = $this->sequence_currval( $metadata['sequence_name'] );
		return null === $current ? 1 : $current + 1;
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
			'POSITION_IN_UNIQUE_CONSTRAINT' => null,
			'REFERENCED_TABLE_SCHEMA'       => $this->database,
			'REFERENCED_TABLE_NAME'         => null,
			'REFERENCED_COLUMN_NAME'        => null,
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
				. $this->connection->quote( self::INFO_SCHEMA_TABLES_TABLE )
				. ' AND table_name <> '
				. $this->connection->quote( self::INFO_SCHEMA_COLUMNS_TABLE )
				. ' AND table_name <> '
				. $this->connection->quote( self::INFO_SCHEMA_STATISTICS_TABLE )
				. ' AND table_name <> '
				. $this->connection->quote( self::INFO_SCHEMA_TABLE_CONSTRAINTS_TABLE )
				. ' AND table_name <> '
				. $this->connection->quote( self::INFO_SCHEMA_KEY_COLUMN_USAGE_TABLE )
				. ' ORDER BY table_name',
			'Failed to inspect DuckDB tables'
		);

		return array_map(
			'strval',
			$stmt->fetchAll( PDO::FETCH_COLUMN )
		);
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
	private function sequence_name( string $table_name, string $column_name ): string {
		return self::SEQUENCE_PREFIX . substr( hash( 'sha256', $table_name . "\0" . $column_name ), 0, 16 );
	}

	/**
	 * Create a deterministic schema-safe index name.
	 *
	 * @param string $table_name       Table name.
	 * @param string $mysql_index_name MySQL index name.
	 * @return string DuckDB index name.
	 */
	private function index_name( string $table_name, string $mysql_index_name ): string {
		return self::INDEX_PREFIX . substr( hash( 'sha256', $table_name ), 0, 8 ) . '_' . $mysql_index_name;
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
