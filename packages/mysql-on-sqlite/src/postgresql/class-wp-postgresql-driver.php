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

		$translated_query = $this->translate_wordpress_options_regexp_delete_query( $query );
		if ( null !== $translated_query ) {
			$query = $translated_query;
		}

		$translated_query = $this->translate_simple_mysql_delete_query( $query );
		if ( null !== $translated_query ) {
			$query = $translated_query;
		}

		$translated_query = $this->translate_wordpress_options_upsert_query( $query );
		if ( null !== $translated_query ) {
			$query = $translated_query;
		}

		$translated_query = $this->translate_simple_mysql_insert_query( $query );
		if ( null !== $translated_query ) {
			$query = $translated_query;
		}

		$translated_query = $this->translate_simple_mysql_update_query( $query );
		if ( null !== $translated_query ) {
			$query = $translated_query;
		}

		$translated_query = $this->translate_simple_mysql_select_query( $query );
		if ( null !== $translated_query ) {
			$query = $translated_query;
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
	 * Execute a MySQL DESCRIBE/DESC statement through PostgreSQL catalogs.
	 *
	 * @param string $table_name          Table name.
	 * @param int    $fetch_mode          PDO fetch mode.
	 * @param array  ...$fetch_mode_args  Additional fetch mode arguments.
	 * @return mixed DESCRIBE result rows.
	 */
	private function execute_describe_query( string $table_name, $fetch_mode, ...$fetch_mode_args ) {
		$sql    = $this->get_describe_catalog_query();
		$params = array( 'public', $table_name );
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
	AND table_type IN (\'BASE TABLE\', \'VIEW\')',
			$table_column,
			$is_full ? ', CASE WHEN table_type = \'VIEW\' THEN \'VIEW\' ELSE \'BASE TABLE\' END AS "Table_type"' : ''
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
	 * Get the PostgreSQL catalog query backing MySQL DESCRIBE/DESC.
	 *
	 * @return string SQL query.
	 */
	private function get_describe_catalog_query(): string {
		return 'SELECT
	c.column_name AS "Field",
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
	END AS "Type",
	c.is_nullable AS "Null",
	CASE
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
	END AS "Key",
	c.column_default AS "Default",
	CASE
		WHEN c.is_identity = \'YES\' THEN \'auto_increment\'
		WHEN c.column_default LIKE \'nextval(%\' THEN \'auto_increment\'
		ELSE \'\'
	END AS "Extra"
FROM information_schema.columns c
WHERE c.table_schema = ?
	AND c.table_name = ?
ORDER BY c.ordinal_position';
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
			'DELETE FROM %s WHERE %s ~ %s',
			$this->connection->quote_identifier( $table_name ),
			$this->connection->quote_identifier( $column ),
			$this->connection->quote( $tokens[6]->get_value() )
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
	 * Translate simple single-row MySQL INSERT statements to PostgreSQL.
	 *
	 * WordPress CRUD helpers emit a narrow INSERT INTO table (columns) VALUES
	 * (...) shape. INSERT IGNORE uses PostgreSQL's conflict no-op syntax for
	 * the same simple VALUES shape. Other MySQL-specific modifiers,
	 * INSERT ... SELECT/SET, missing column lists, multi-row values, and
	 * trailing clauses fall through unchanged.
	 *
	 * @param string $query MySQL query.
	 * @return string|null PostgreSQL query, or null when the query is unsupported.
	 */
	private function translate_simple_mysql_insert_query( string $query ): ?string {
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

		$values_start = $position;
		++$position;

		$statement_end = $this->get_mysql_statement_end_position( $tokens, $position );
		if ( null === $statement_end ) {
			return null;
		}

		$values_end = $this->get_mysql_parenthesized_sequence_end( $tokens, $position, $statement_end );
		if ( null === $values_end || $values_end !== $statement_end ) {
			return null;
		}

		$sql = sprintf(
			'INSERT INTO %s (%s) %s',
			$this->connection->quote_identifier( $table_name ),
			implode( ', ', array_map( array( $this->connection, 'quote_identifier' ), $columns ) ),
			$this->translate_mysql_token_sequence_to_postgresql( $tokens, $values_start, $values_end )
		);

		return $ignore ? $sql . ' ON CONFLICT DO NOTHING' : $sql;
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
			WP_MySQL_Lexer::COMMA_SYMBOL,
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
			if ( $where_position + 1 >= $statement_end ) {
				return null;
			}

			$sql .= ' WHERE ' . $this->translate_mysql_token_sequence_to_postgresql(
				$tokens,
				$where_position + 1,
				$statement_end
			);
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
			$sql .= ' WHERE ' . $this->translate_mysql_token_sequence_to_postgresql(
				$tokens,
				$where_position + 1,
				$where_end
			);
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
		return $start + 2 < $end
			&& null !== $this->get_mysql_identifier_token_value( $tokens[ $start ] ?? null )
			&& isset( $tokens[ $start + 1 ] )
			&& WP_MySQL_Lexer::EQUAL_OPERATOR === $tokens[ $start + 1 ]->id;
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
		return in_array(
			$token->id,
			array(
				WP_MySQL_Lexer::INT_NUMBER,
				WP_MySQL_Lexer::LONG_NUMBER,
				WP_MySQL_Lexer::ULONGLONG_NUMBER,
			),
			true
		) && ctype_digit( $token->get_value() );
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
			$token    = $tokens[ $i ];
			$fragment = $this->translate_mysql_token_to_postgresql( $token );

			if ( '' === $sql ) {
				$sql = $fragment;
			} elseif ( $this->should_join_mysql_tokens_without_space( $previous_token_id, $token->id ) ) {
				$sql .= $fragment;
			} else {
				$sql .= ' ' . $fragment;
			}

			$previous_token_id = $token->id;
		}

		return $sql;
	}

	/**
	 * Translate a single MySQL token to a PostgreSQL fragment.
	 *
	 * @param WP_MySQL_Token $token MySQL token.
	 * @return string PostgreSQL SQL fragment.
	 */
	private function translate_mysql_token_to_postgresql( WP_MySQL_Token $token ): string {
		if ( WP_MySQL_Lexer::IDENTIFIER === $token->id && $this->should_quote_bare_mysql_identifier( $token->get_value() ) ) {
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
	 * Check whether a bare MySQL identifier needs PostgreSQL quoting.
	 *
	 * @param string $identifier Identifier token value.
	 * @return bool Whether the bare identifier must be quoted.
	 */
	private function should_quote_bare_mysql_identifier( string $identifier ): bool {
		return strtolower( $identifier ) !== $identifier;
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
