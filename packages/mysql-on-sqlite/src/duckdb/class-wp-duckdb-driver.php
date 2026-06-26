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
	const MYSQL_GRAMMAR_PATH    = __DIR__ . '/../mysql/mysql-grammar.php';
	const DEFAULT_DATABASE      = 'wp';
	const DEFAULT_MYSQL_VERSION = 80038;
	const SEQUENCE_PREFIX       = 'wp_duckdb_ai_';
	const INDEX_PREFIX          = 'wp_duckdb_idx_';
	const INDEX_METADATA_TABLE  = '__wp_duckdb_index_metadata';

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
		return $this->execute_duckdb_query(
			$this->translate_tokens_to_duckdb_sql( $tokens ),
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
		$this->assert_supported_create_table_options( array_slice( $tokens, $index ) );

		$columns     = array();
		$constraints = array();
		$indexes     = array();
		$sequences   = array();

		foreach ( $items as $item ) {
			if ( count( $item ) === 0 ) {
				continue;
			}

			if ( WP_MySQL_Lexer::PRIMARY_SYMBOL === $item[0]->id ) {
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

			list( $column_sql, $sequence_sql, $column_indexes ) = $this->translate_create_table_column( $table_name, $item );
			$columns[] = $column_sql;
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

		$set_index = $this->find_insert_set_index( $tokens, $index );
		if ( null !== $set_index ) {
			if ( null !== $this->find_on_duplicate_key_update_index( $tokens ) ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported INSERT statement in DuckDB driver. INSERT ... SET ... ON DUPLICATE KEY UPDATE is not supported.' );
			}
			return $this->execute_duckdb_query(
				$this->translate_insert_set_tokens_to_duckdb_sql( $tokens, $index, $set_index, $ignore ),
				'Failed to execute DuckDB INSERT'
			);
		}

		$this->assert_values_write_statement( $tokens, $index, 'INSERT' );

		$on_duplicate_index = $this->find_on_duplicate_key_update_index( $tokens );
		if ( null !== $on_duplicate_index ) {
			if ( $ignore ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported INSERT statement in DuckDB driver. INSERT IGNORE ... ON DUPLICATE KEY UPDATE is not supported.' );
			}
			return $this->execute_duckdb_query(
				$this->translate_insert_on_duplicate_key_update_tokens_to_duckdb_sql( $tokens, $index, $on_duplicate_index ),
				'Failed to execute DuckDB INSERT'
			);
		}

		return $this->execute_duckdb_query(
			$ignore
				? $this->translate_insert_ignore_tokens_to_duckdb_sql( $tokens, $index )
				: $this->translate_tokens_to_duckdb_sql( $tokens ),
			'Failed to execute DuckDB INSERT'
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
		$this->assert_values_write_statement( $tokens, $index, 'REPLACE' );

		return $this->execute_duckdb_query(
			$this->translate_replace_tokens_to_duckdb_sql( $tokens ),
			'Failed to execute DuckDB REPLACE'
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

		if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::ADD_SYMBOL === $tokens[ $index ]->id ) {
			++$index;
			$alter_item = array_slice( $tokens, $index );
			if ( ! $this->is_create_table_index_item( $alter_item ) ) {
				throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. Only ADD INDEX is supported.' );
			}

			$index_definition = $this->translate_create_table_index( $table_name, $alter_item );
			$result           = $this->execute_duckdb_query( $index_definition['sql'], 'Failed to create DuckDB index' );
			$this->record_index_metadata( $index_definition );

			return $result;
		}

		throw new WP_DuckDB_Driver_Exception( 'Unsupported ALTER TABLE statement in DuckDB driver. Only ADD INDEX is supported.' );
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

		$rows = $this->describe_column_rows( $table_name );
		if ( null !== $like_pattern ) {
			$rows = $this->filter_column_rows_by_like( $rows, $like_pattern );
		}

		if ( ! $full ) {
			return new WP_DuckDB_Result_Statement(
				array( 'Field', 'Type', 'Null', 'Key', 'Default', 'Extra' ),
				$rows
			);
		}

		$full_rows = array();
		foreach ( $rows as $row ) {
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
			array_merge(
				$this->primary_key_index_rows( $table_name ),
				$this->secondary_index_rows( $table_name )
			)
		);
	}

	/**
	 * Build SHOW INDEX rows for the primary key.
	 *
	 * @param string $table_name Table name.
	 * @return array<int,array<int,mixed>>
	 */
	private function primary_key_index_rows( string $table_name ): array {
		$pragma = $this->execute_duckdb_query(
			'SELECT name, pk FROM pragma_table_info(' . $this->connection->quote( $table_name ) . ') WHERE pk > 0 ORDER BY pk',
			'Failed to inspect DuckDB primary key'
		);

		$rows = array();
		foreach ( $pragma->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
			$rows[] = $this->show_index_row(
				$table_name,
				0,
				'PRIMARY',
				(int) $row['pk'],
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
	 * Translate a column definition.
	 *
	 * @param string            $table_name Table name.
	 * @param WP_Parser_Token[] $tokens     Column definition tokens.
	 * @return array{0:string,1:string|null,2:array<int,array{sql:string,table_name:string,index_name:string,unique:bool,columns:array<int,array{name:string,sub_part:int|null}>}>}
	 */
	private function translate_create_table_column( string $table_name, array $tokens ): array {
		$index       = 0;
		$column_name = $this->identifier_value( $tokens[ $index ] ?? null );
		++$index;

		if ( ! isset( $tokens[ $index ] ) || ! isset( self::DATA_TYPE_MAP[ $tokens[ $index ]->id ] ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported MySQL column type for DuckDB column: ' . $column_name . '.' );
		}

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

		if ( $not_null ) {
			$column_sql .= ' NOT NULL';
		}
		if ( $primary_key ) {
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

		return array( $column_sql, $sequence, $indexes );
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
	 * Translate a table-level PRIMARY KEY constraint.
	 *
	 * @param WP_Parser_Token[] $tokens Constraint tokens.
	 * @return string
	 */
	private function translate_table_primary_key( array $tokens ): string {
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
			$columns[] = $this->connection->quote_identifier( $this->identifier_value( $tokens[ $index ] ) );
			++$index;
			if ( isset( $tokens[ $index ] ) && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $index ]->id ) {
				++$index;
			}
		}

		if ( count( $columns ) === 0 || count( $tokens ) !== $index ) {
			throw new WP_DuckDB_Driver_Exception( 'Unsupported PRIMARY KEY constraint in DuckDB driver.' );
		}

		return 'PRIMARY KEY (' . implode( ', ', $columns ) . ')';
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
	 * Assert supported CREATE TABLE tail options.
	 *
	 * @param WP_Parser_Token[] $tokens Tail tokens after the column list.
	 */
	private function assert_supported_create_table_options( array $tokens ): void {
		$index = 0;
		while ( $index < count( $tokens ) ) {
			$token = $tokens[ $index ];
			if ( WP_MySQL_Lexer::DEFAULT_SYMBOL === $token->id ) {
				++$index;
				continue;
			}
			if (
				WP_MySQL_Lexer::ENGINE_SYMBOL === $token->id
				|| WP_MySQL_Lexer::CHARSET_SYMBOL === $token->id
				|| WP_MySQL_Lexer::COLLATE_SYMBOL === $token->id
				|| WP_MySQL_Lexer::AUTO_INCREMENT_SYMBOL === $token->id
				|| WP_MySQL_Lexer::COMMENT_SYMBOL === $token->id
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
	private function translate_tokens_to_duckdb_sql( array $tokens ): string {
		$pieces = array();

		for ( $index = 0; $index < count( $tokens ); ++$index ) {
			$token = $tokens[ $index ];

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
				$pieces[] = $this->connection->quote_identifier( $token->get_value() );
				continue;
			}

			if ( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id || WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $token->id ) {
				$pieces[] = $this->connection->quote( $token->get_value() );
				continue;
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
