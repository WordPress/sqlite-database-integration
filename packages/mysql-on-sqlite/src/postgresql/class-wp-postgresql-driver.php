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

		if ( $this->is_create_table_query( $query ) ) {
			return $this->execute_postgresql_statements(
				( new WP_PostgreSQL_Create_Table_Translator() )->translate_schema( $query )
			);
		}

		$stmt                            = $this->connection->query( $query );
		$this->last_postgresql_queries[] = array(
			'sql'    => $query,
			'params' => array(),
		);

		if ( $stmt->columnCount() > 0 ) {
			$this->last_column_meta = $this->normalize_column_meta( $stmt );
			$this->last_result      = $stmt->fetchAll( $fetch_mode, ...$fetch_mode_args );
		} else {
			$this->last_column_meta = array();
			$this->last_result      = $stmt->rowCount();
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
	 * Check whether a CREATE TABLE query contains MySQL install-schema syntax.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL lexer token stream.
	 * @return bool Whether the query should use the install DDL translator.
	 */
	private function has_mysql_create_table_marker( array $tokens ): bool {
		foreach ( $tokens as $token ) {
			if (
				in_array(
					$token->id,
					array(
						WP_MySQL_Lexer::AUTO_INCREMENT_SYMBOL,
						WP_MySQL_Lexer::CHARSET_SYMBOL,
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
