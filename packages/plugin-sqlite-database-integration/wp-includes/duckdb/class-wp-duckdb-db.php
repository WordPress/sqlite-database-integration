<?php
/**
 * Extend and replace the wpdb class for DuckDB.
 *
 * @package wp-sqlite-integration
 */

/**
 * DuckDB wpdb adapter.
 *
 * This first-stage adapter exposes the DuckDB connection through the wpdb
 * surface. Full MySQL dialect emulation is implemented in the shared driver
 * package in later stages.
 */
class WP_DuckDB_DB extends wpdb {

	/**
	 * Database handle.
	 *
	 * @var WP_DuckDB_Driver
	 */
	protected $dbh;

	/**
	 * Backward compatibility, see wpdb::$allow_unsafe_unquoted_parameters.
	 *
	 * This property mirrors "wpdb::$allow_unsafe_unquoted_parameters" because
	 * some tests access it externally using PHP reflection.
	 *
	 * @var bool
	 */
	private $allow_unsafe_unquoted_parameters = true;

	/**
	 * Last DuckDB statement.
	 *
	 * @var WP_DuckDB_Result_Statement|null
	 */
	private $last_statement;

	/**
	 * Constructor.
	 *
	 * @param string $dbname Database name.
	 */
	public function __construct( $dbname ) {
		$GLOBALS['wpdb'] = $this;

		parent::__construct( '', '', $dbname, '' );
		$this->charset = 'utf8mb4';
	}

	/**
	 * Determine the best charset and collation to use.
	 *
	 * This mirrors wpdb::determine_charset() without requiring a mysqli handle.
	 *
	 * @param string $charset Character set.
	 * @param string $collate Collation.
	 * @return array{charset:string,collate:string}
	 */
	public function determine_charset( $charset, $collate ) {
		if ( ! $this->ready || ! ( $this->dbh instanceof WP_DuckDB_Driver ) ) {
			return compact( 'charset', 'collate' );
		}

		if ( 'utf8' === $charset ) {
			$charset = 'utf8mb4';
		}

		if ( 'utf8mb4' === $charset ) {
			if ( ! $collate || 'utf8_general_ci' === $collate ) {
				$collate = 'utf8mb4_unicode_ci';
			} else {
				$collate = str_replace( 'utf8_', 'utf8mb4_', $collate );
			}
		}

		if ( $this->has_cap( 'utf8mb4_520' ) && 'utf8mb4_unicode_ci' === $collate ) {
			$collate = 'utf8mb4_unicode_520_ci';
		}

		return compact( 'charset', 'collate' );
	}

	/**
	 * Track connection charset state without calling mysqli functions.
	 *
	 * @param resource $dbh     Database handle.
	 * @param string   $charset Optional charset.
	 * @param string   $collate Optional collation.
	 */
	public function set_charset( $dbh, $charset = null, $collate = null ) {
		if ( ! isset( $charset ) ) {
			$charset = $this->charset;
		}
		if ( ! isset( $collate ) ) {
			$collate = $this->collate;
		}

		$this->charset = $charset;
		$this->collate = $collate ? $collate : $this->get_default_collation_for_charset( $charset );
	}

	/**
	 * Return the column charset.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @return string|false|WP_Error
	 */
	public function get_col_charset( $table, $column ) {
		return parent::get_col_charset( $table, $column );
	}

	/**
	 * Changes the current SQL mode, and ensures its WordPress compatibility.
	 *
	 * @param array $modes Optional. A list of SQL modes to set. Default empty array.
	 */
	public function set_sql_mode( $modes = array() ) {
		if ( ! $this->dbh instanceof WP_DuckDB_Driver ) {
			return;
		}

		if ( empty( $modes ) ) {
			$result = $this->dbh->query( 'SELECT @@SESSION.sql_mode' );
			$row    = $result->fetch( PDO::FETCH_OBJ ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO

			if ( ! $row || ! isset( $row->{'@@SESSION.sql_mode'} ) ) {
				throw new RuntimeException( 'DuckDB SQL mode bootstrap did not return @@SESSION.sql_mode.' );
			}

			$modes_str = $row->{'@@SESSION.sql_mode'};
			if ( empty( $modes_str ) ) {
				return;
			}
			$modes = explode( ',', $modes_str );
		}

		$modes = array_change_key_case( $modes, CASE_UPPER );

		/**
		 * Filters the list of incompatible SQL modes to exclude.
		 *
		 * @since 3.9.0
		 *
		 * @param array $incompatible_modes An array of incompatible modes.
		 */
		$incompatible_modes = $this->get_incompatible_sql_modes();

		foreach ( $modes as $i => $mode ) {
			if ( in_array( $mode, $incompatible_modes, true ) ) {
				unset( $modes[ $i ] );
			}
		}
		$modes_str = implode( ',', $modes );

		$this->dbh->query( "SET SESSION sql_mode='" . str_replace( "'", "''", $modes_str ) . "'" );
	}

	/**
	 * Close the database connection.
	 *
	 * @return bool
	 */
	public function close() {
		if ( ! $this->ready ) {
			return false;
		}

		$this->ready         = false;
		$this->has_connected = false;

		return true;
	}

	/**
	 * Select the database.
	 *
	 * @param string        $db  Database name.
	 * @param resource|null $dbh Optional database handle.
	 */
	public function select( $db, $dbh = null ) {
		$this->ready = true;
	}

	/**
	 * Escape data.
	 *
	 * @param string $data Data to escape.
	 * @return string
	 */
	public function _real_escape( $data ) {
		if ( ! is_scalar( $data ) ) {
			return '';
		}
		return $this->add_placeholder_escape( addslashes( $data ) );
	}

	/**
	 * Prints SQL/DB error.
	 *
	 * This overrides wpdb::print_error() while avoiding its mysqli error
	 * fallback for non-mysqli database handles.
	 *
	 * @global array $EZSQL_ERROR Stores error information of query and error string.
	 *
	 * @param string $str The error to display.
	 * @return void|false Void if the showing of errors is enabled, false if disabled.
	 */
	public function print_error( $str = '' ) {
		global $EZSQL_ERROR;

		if ( ! $str ) {
			$str = $this->last_error;
		}

		$EZSQL_ERROR[] = array(
			'query'     => $this->last_query,
			'error_str' => $str,
		);

		if ( $this->suppress_errors ) {
			return false;
		}

		$caller = $this->get_caller();
		if ( $caller ) {
			// Not translated, as this will only appear in the error log.
			$error_str = sprintf( 'WordPress database error %1$s for query %2$s made by %3$s', $str, $this->last_query, $caller );
		} else {
			$error_str = sprintf( 'WordPress database error %1$s for query %2$s', $str, $this->last_query );
		}

		error_log( $error_str );

		if ( ! $this->show_errors ) {
			return false;
		}

		wp_load_translations_early();

		if ( is_multisite() ) {
			$msg = sprintf(
				"%s [%s]\n%s\n",
				__( 'WordPress database error:' ),
				$str,
				$this->last_query
			);

			if ( defined( 'ERRORLOGFILE' ) ) {
				error_log( $msg, 3, ERRORLOGFILE );
			}
			if ( defined( 'DIEONDBERROR' ) ) {
				wp_die( $msg );
			}
		} else {
			$str   = htmlspecialchars( $str, ENT_QUOTES );
			$query = htmlspecialchars( $this->last_query, ENT_QUOTES );

			printf(
				'<div id="error"><p class="wpdberror"><strong>%s</strong> [%s]<br /><code>%s</code></p></div>',
				__( 'WordPress database error:' ),
				$str,
				$query
			);
		}
	}

	/**
	 * Flush cached query state.
	 */
	public function flush() {
		$this->last_result    = array();
		$this->col_info       = null;
		$this->last_query     = null;
		$this->rows_affected  = 0;
		$this->num_rows       = 0;
		$this->last_error     = '';
		$this->result         = null;
		$this->last_statement = null;
	}

	/**
	 * Connect to DuckDB.
	 *
	 * @param bool $allow_bail Whether to bail on error.
	 * @return bool|null
	 */
	public function db_connect( $allow_bail = true ) {
		if ( isset( $GLOBALS['@duckdb_driver'] ) && $GLOBALS['@duckdb_driver'] instanceof WP_DuckDB_Driver ) {
			$this->dbh = $GLOBALS['@duckdb_driver'];
		} elseif ( isset( $GLOBALS['@duckdb'] ) && $GLOBALS['@duckdb'] instanceof WP_DuckDB_Connection ) {
			$this->dbh = $GLOBALS['@duckdb'];
		}

		if ( null === $this->dbname || '' === $this->dbname ) {
			$this->bail(
				'The database name was not set. The DuckDB driver requires a database name to be set.',
				'db_connect_fail'
			);
			return false;
		}

		$this->ensure_database_directory( FQDUCKDB );

		try {
			if ( ! $this->dbh ) {
				$connection = new WP_DuckDB_Connection( array( 'path' => FQDUCKDB ) );
				$this->dbh  = new WP_DuckDB_Driver(
					array(
						'connection' => $connection,
						'database'   => $this->dbname,
					)
				);
			} elseif ( $this->dbh instanceof WP_DuckDB_Connection ) {
				$this->dbh = new WP_DuckDB_Driver(
					array(
						'connection' => $this->dbh,
						'database'   => $this->dbname,
					)
				);
			}
			$GLOBALS['@duckdb_driver'] = $this->dbh;
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();
		}

		if ( $this->last_error ) {
			return false;
		}

		$this->is_mysql = true;
		$this->ready    = true;
		try {
			$this->set_sql_mode();
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();
			$this->ready      = false;
			return false;
		}
		return true;
	}

	/**
	 * Check the connection.
	 *
	 * @param bool $allow_bail Not used.
	 * @return bool
	 */
	public function check_connection( $allow_bail = true ) {
		if ( $this->dbh instanceof WP_DuckDB_Driver ) {
			$this->ready         = true;
			$this->has_connected = true;
		}

		return true;
	}

	/**
	 * Prepares a SQL query for safe execution.
	 *
	 * See "wpdb::prepare()". This override only fixes a WPDB test issue.
	 *
	 * @param string      $query Query statement with `sprintf()`-like placeholders.
	 * @param array|mixed $args  The array of variables or the first variable to substitute.
	 * @param mixed       ...$args Further variables to substitute when using individual arguments.
	 * @return string|void Sanitized query string, if there is a query to prepare.
	 */
	public function prepare( $query, ...$args ) {
		/*
		 * Sync "$allow_unsafe_unquoted_parameters" with the WPDB parent property.
		 * This is only needed because some WPDB tests access the private property
		 * externally via PHP reflection.
		 */
		$wpdb_allow_unsafe_unquoted_parameters = $this->__get( 'allow_unsafe_unquoted_parameters' );
		if ( $wpdb_allow_unsafe_unquoted_parameters !== $this->allow_unsafe_unquoted_parameters ) {
			$property = new ReflectionProperty( 'wpdb', 'allow_unsafe_unquoted_parameters' );
			$property->setAccessible( true );
			$property->setValue( $this, $this->allow_unsafe_unquoted_parameters );
			$property->setAccessible( false );
		}

		return parent::prepare( $query, ...$args );
	}

	/**
	 * Perform a query.
	 *
	 * @param string $query Database query.
	 * @return int|bool
	 */
	public function query( $query ) {
		if ( ! $this->ready ) {
			if ( property_exists( $this, 'check_current_query' ) ) {
				$this->check_current_query = true;
			}
			return false;
		}

		$query = apply_filters( 'query', $query );
		if ( ! $query ) {
			$this->insert_id = 0;
			return false;
		}

		$this->flush();
		$this->func_call = "\$db->query(\"$query\")";

		if (
			property_exists( $this, 'check_current_query' )
			&& method_exists( $this, 'check_ascii' )
			&& method_exists( $this, 'strip_invalid_text_from_query' )
			&& $this->check_current_query
			&& ! $this->check_ascii( $query )
		) {
			$stripped_query = $this->strip_invalid_text_from_query( $query );
			$this->flush();
			if ( $stripped_query !== $query ) {
				$this->insert_id  = 0;
				$this->last_query = $query;

				wp_load_translations_early();

				$this->last_error = __( 'WordPress database error: Could not perform query because it contains invalid data.' );

				return false;
			}
		}

		if ( property_exists( $this, 'check_current_query' ) ) {
			$this->check_current_query = true;
		}
		$this->last_query = $query;

		$this->_do_query( $query );

		if ( $this->last_error ) {
			if ( $this->insert_id && preg_match( '/^\s*(insert|replace)\s/i', $query ) ) {
				$this->insert_id = 0;
			}

			$this->print_error();
			return false;
		}

		if ( preg_match( '/^\s*(create|alter|truncate|drop)\s/i', $query ) ) {
			return true;
		}

		if ( preg_match( '/^\s*(insert|delete|update|replace)\s/i', $query ) ) {
			$this->rows_affected = $this->last_statement ? $this->last_statement->rowCount() : 0;
			if ( preg_match( '/^\s*(insert|replace)\s/i', $query ) && method_exists( $this->dbh, 'get_insert_id' ) ) {
				$this->insert_id = (int) $this->dbh->get_insert_id();
				if ( 0 === $this->rows_affected && $this->insert_id > 0 ) {
					$this->rows_affected = 1;
				}
			}
			if ( defined( 'WP_DUCKDB_E2E_DIAGNOSTICS' ) && WP_DUCKDB_E2E_DIAGNOSTICS && $this->is_persisted_preferences_usermeta_insert( $query ) ) {
				$this->log_persisted_preferences_insert_diagnostic(
					$query,
					$this->persisted_preferences_usermeta_insert_table( $query )
				);
			}
			return $this->rows_affected;
		}

		$this->last_result = is_array( $this->result ) ? $this->result : array();
		$this->num_rows    = count( $this->last_result );
		return $this->num_rows;
	}

	/**
	 * Check whether a query inserts the persisted preferences user meta row.
	 *
	 * @param string $query Query to inspect.
	 * @return bool
	 */
	private function is_persisted_preferences_usermeta_insert( $query ) {
		return false !== $this->persisted_preferences_usermeta_insert_table( $query )
			&& false !== strpos( $query, 'persisted_preferences' );
	}

	/**
	 * Extract the usermeta table name from a persisted preferences insert.
	 *
	 * @param string $query Query to inspect.
	 * @return string|false Usermeta table name, or false when the query does not match.
	 */
	private function persisted_preferences_usermeta_insert_table( $query ) {
		if ( ! preg_match( '/^\s*insert\s+into\s+`?([^`\s(]+)`?\s/i', $query, $matches ) ) {
			return false;
		}

		$table = $matches[1];
		if ( isset( $this->usermeta ) && 0 === strcasecmp( $table, $this->usermeta ) ) {
			return $table;
		}

		if ( 'usermeta' === substr( strtolower( $table ), -8 ) ) {
			return $table;
		}

		return false;
	}

	/**
	 * Log immediate DuckDB state after the persisted preferences insert.
	 *
	 * @param string       $query      Insert query.
	 * @param string|false $table_name Usermeta table name.
	 */
	private function log_persisted_preferences_insert_diagnostic( $query, $table_name ) {
		$diagnostics = array(
			'query'                  => $query,
			'usermeta_table'         => $table_name ? (string) $table_name : null,
			'last_error'             => (string) $this->last_error,
			'statement_row_count'    => $this->last_statement ? (int) $this->last_statement->rowCount() : null,
			'rows_affected'          => (int) $this->rows_affected,
			'insert_id'              => (int) $this->insert_id,
			'driver_class'           => is_object( $this->dbh ) ? get_class( $this->dbh ) : gettype( $this->dbh ),
			'driver_insert_id'       => null,
			'duckdb_queries_tail'    => array(),
			'wp_usermeta_columns'    => array(),
			'wp_usermeta_row_counts' => array(),
			'wp_usermeta_latest'     => array(),
			'duckdb_column_metadata' => array(),
			'duckdb_metadata_error'  => null,
		);

		try {
			if ( is_object( $this->dbh ) && method_exists( $this->dbh, 'get_insert_id' ) ) {
				$diagnostics['driver_insert_id'] = (int) $this->dbh->get_insert_id();
			}
			if ( is_object( $this->dbh ) && method_exists( $this->dbh, 'get_last_duckdb_queries' ) ) {
				$diagnostics['duckdb_queries_tail'] = array_slice( $this->dbh->get_last_duckdb_queries(), -5 );
			}
			if ( is_object( $this->dbh ) && method_exists( $this->dbh, 'get_connection' ) ) {
				$connection = $this->dbh->get_connection();
				$table_name = $table_name ? (string) $table_name : 'wp_usermeta';
				$table      = $connection->quote_identifier( $table_name );

				$diagnostics['wp_usermeta_columns'] = $connection
					->query( 'SELECT cid, name, type, "notnull", dflt_value, pk FROM pragma_table_info(' . $connection->quote( $table_name ) . ') ORDER BY cid' )
					->fetchAll( PDO::FETCH_ASSOC ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO

				try {
					$diagnostics['duckdb_column_metadata'] = $connection
						->query(
							'SELECT column_name, ordinal_position, column_type, is_nullable, column_default, column_key, extra FROM '
							. $connection->quote_identifier( WP_DuckDB_Driver::COLUMN_METADATA_TABLE )
							. ' WHERE table_name = '
							. $connection->quote( $table_name )
							. ' ORDER BY ordinal_position'
						)
						->fetchAll( PDO::FETCH_ASSOC ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO
				} catch ( Throwable $e ) {
					$diagnostics['duckdb_metadata_error'] = $e->getMessage();
				}

				$diagnostics['wp_usermeta_row_counts'] = $connection
					->query( 'SELECT COUNT(*) AS total_rows, MAX(umeta_id) AS max_umeta_id FROM ' . $table )
					->fetchAll( PDO::FETCH_ASSOC ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO

				$diagnostics['wp_usermeta_latest'] = $connection
					->query( 'SELECT umeta_id, user_id, meta_key, LENGTH(meta_value) AS meta_value_length FROM ' . $table . " WHERE meta_key LIKE '%persisted_preferences' ORDER BY umeta_id DESC LIMIT 3" )
					->fetchAll( PDO::FETCH_ASSOC ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO
			}
		} catch ( Throwable $e ) {
			$diagnostics['probe_error'] = $e->getMessage();
		}

		$encoded = function_exists( 'wp_json_encode' )
			? wp_json_encode( $diagnostics, JSON_UNESCAPED_SLASHES )
			: json_encode( $diagnostics );

		error_log( '[duckdb-persisted-preferences-insert] ' . $encoded );
	}

	/**
	 * Internal query runner.
	 *
	 * @param string $query Query to run.
	 */
	private function _do_query( $query ) {
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$this->timer_start();
		}

		try {
			$this->last_statement = $this->get_connection_collation_statement( $query );
			if ( ! $this->last_statement ) {
				$this->last_statement = $this->dbh->query( $query );
			}

			if ( $this->last_statement->columnCount() > 0 ) {
				$this->result = $this->normalize_result_rows( $this->last_statement->fetchAll( PDO::FETCH_OBJ ) ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO
			} else {
				$this->result = null;
			}
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();
			$this->rollback_failed_active_duckdb_transaction( $e );
		}

		++$this->num_queries;

		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$this->log_query(
				$query,
				$this->timer_stop(),
				$this->get_caller(),
				$this->time_start,
				array()
			);
		}
	}

	/**
	 * Roll back active DuckDB transactions after native engine failures.
	 *
	 * Unsupported or preflight driver errors should leave caller-managed
	 * transactions open. Native DuckDB query/prepare errors can leave the
	 * transaction aborted, so clean up only that narrow failure shape.
	 *
	 * @param Throwable $error Query failure.
	 */
	private function rollback_failed_active_duckdb_transaction( Throwable $error ) {
		if ( ! $this->should_rollback_active_duckdb_transaction_on_error( $error ) ) {
			return;
		}

		$connection = $this->get_duckdb_connection();
		if ( ! $connection ) {
			return;
		}

		try {
			if ( $connection->inTransaction() ) {
				$connection->rollback();
			} elseif ( $this->is_current_duckdb_transaction_aborted_error( $error ) ) {
				$connection->rollbackNativeTransaction();
			}
		} catch ( Throwable $rollback_error ) {
			if ( '' === $this->last_error ) {
				$this->last_error = $rollback_error->getMessage();
			}
		}
	}

	/**
	 * Get the underlying DuckDB connection, when one is still available.
	 *
	 * @return WP_DuckDB_Connection|null Connection, or null when unavailable.
	 */
	private function get_duckdb_connection() {
		if ( $this->dbh instanceof WP_DuckDB_Connection ) {
			return $this->dbh;
		}

		if ( ! $this->dbh instanceof WP_DuckDB_Driver || ! method_exists( $this->dbh, 'get_connection' ) ) {
			return null;
		}

		try {
			$connection = $this->dbh->get_connection();
		} catch ( Throwable $e ) {
			return null;
		}
		if ( ! $connection instanceof WP_DuckDB_Connection ) {
			return null;
		}

		return $connection;
	}

	/**
	 * Check whether a query error means the active DuckDB transaction is unsafe.
	 *
	 * @param Throwable $error Query failure.
	 * @return bool Whether to roll back the active transaction.
	 */
	private function should_rollback_active_duckdb_transaction_on_error( Throwable $error ) {
		if ( $this->is_current_duckdb_transaction_aborted_error( $error ) ) {
			return true;
		}

		for ( $current = $error; null !== $current; $current = $current->getPrevious() ) {
			$message = $current->getMessage();
			if (
				$current instanceof WP_DuckDB_Driver_Exception
				&& (
					0 === strpos( $message, 'DuckDB query failed:' )
					|| 0 === strpos( $message, 'Failed to prepare DuckDB query:' )
					|| false !== strpos( $message, ': DuckDB query failed:' )
					|| false !== strpos( $message, ': Failed to prepare DuckDB query:' )
				)
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether an error reports a DuckDB-aborted transaction.
	 *
	 * @param Throwable $error Query failure.
	 * @return bool Whether the native transaction is already aborted.
	 */
	private function is_current_duckdb_transaction_aborted_error( Throwable $error ) {
		for ( $current = $error; null !== $current; $current = $current->getPrevious() ) {
			if ( false !== strpos( $current->getMessage(), 'Current transaction is aborted' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize fetched DuckDB rows to MySQL/PDO-style scalar values.
	 *
	 * @param array<int,object> $rows Result rows.
	 * @return array<int,object> Normalized result rows.
	 */
	private function normalize_result_rows( array $rows ) {
		foreach ( $rows as $row ) {
			foreach ( get_object_vars( $row ) as $name => $value ) {
				if ( 'Non_unique' === $name && is_int( $value ) && ( 0 === $value || 1 === $value ) ) {
					$row->$name = (string) $value;
					continue;
				}

				if ( is_bool( $value ) ) {
					$row->$name = $value ? '1' : '0';
				}
			}
		}

		return $rows;
	}

	/**
	 * Load column metadata.
	 */
	protected function load_col_info() {
		if ( $this->col_info ) {
			return;
		}

		$this->col_info = array();
		if ( ! $this->last_statement ) {
			return;
		}

		for ( $i = 0; $i < $this->last_statement->columnCount(); ++$i ) {
			$meta = $this->last_statement->getColumnMeta( $i );
			if ( ! is_array( $meta ) ) {
				continue;
			}

			$name             = isset( $meta['name'] ) ? $meta['name'] : '';
			$this->col_info[] = (object) array(
				'name'       => $name,
				'orgname'    => isset( $meta['mysqli:orgname'] ) ? $meta['mysqli:orgname'] : $name,
				'table'      => isset( $meta['table'] ) ? $meta['table'] : '',
				'orgtable'   => isset( $meta['mysqli:orgtable'] ) ? $meta['mysqli:orgtable'] : ( isset( $meta['table'] ) ? $meta['table'] : '' ),
				'def'        => '',
				'db'         => isset( $meta['mysqli:db'] ) ? $meta['mysqli:db'] : $this->dbname,
				'catalog'    => 'def',
				'max_length' => 0,
				'length'     => isset( $meta['len'] ) ? $meta['len'] : 0,
				'charsetnr'  => isset( $meta['mysqli:charsetnr'] ) ? $meta['mysqli:charsetnr'] : 224,
				'flags'      => isset( $meta['mysqli:flags'] ) ? $meta['mysqli:flags'] : 0,
				'type'       => isset( $meta['mysqli:type'] ) ? $meta['mysqli:type'] : 253,
				'decimals'   => isset( $meta['precision'] ) ? $meta['precision'] : 0,
			);
		}
	}

	/**
	 * Report supported database capabilities.
	 *
	 * @param string $db_cap Capability name.
	 * @return bool
	 */
	public function has_cap( $db_cap ) {
		switch ( strtolower( $db_cap ) ) {
			case 'collation':
			case 'group_concat':
			case 'identifier_placeholders':
			case 'set_charset':
			case 'subqueries':
			case 'utf8mb4':
			case 'utf8mb4_520':
				return true;
		}

		return false;
	}

	/**
	 * Return a MySQL-compatible version string.
	 *
	 * @return string
	 */
	public function db_version() {
		return '8.0.11';
	}

	/**
	 * Return DuckDB server information.
	 *
	 * @return string
	 */
	public function db_server_info() {
		try {
			$stmt = $this->dbh->get_connection()->query( 'SELECT version() AS version' );
			return 'DuckDB ' . $stmt->fetchColumn();
		} catch ( Throwable $e ) {
			return 'DuckDB';
		}
	}

	/**
	 * Strip invalid text without falling back to mysqli for the connection charset.
	 *
	 * @param array $data Values to strip.
	 * @return array|WP_Error Stripped values, or error.
	 */
	protected function strip_invalid_text( $data ) {
		if ( '' !== $this->charset ) {
			return parent::strip_invalid_text( $data );
		}

		$this->charset = 'utf8mb4';

		try {
			return parent::strip_invalid_text( $data );
		} finally {
			$this->charset = '';
		}
	}

	/**
	 * Get the WordPress-incompatible SQL modes for filtering.
	 *
	 * @return array
	 */
	private function get_incompatible_sql_modes() {
		$incompatible_modes = property_exists( $this, 'incompatible_modes' )
			? $this->incompatible_modes
			: $this->get_default_incompatible_sql_modes();

		return (array) apply_filters( 'incompatible_sql_modes', $incompatible_modes );
	}

	/**
	 * Get the WordPress wpdb default incompatible SQL modes.
	 *
	 * @return array
	 */
	private function get_default_incompatible_sql_modes() {
		return array(
			'NO_ZERO_DATE',
			'ONLY_FULL_GROUP_BY',
			'STRICT_TRANS_TABLES',
			'STRICT_ALL_TABLES',
			'TRADITIONAL',
			'ANSI',
		);
	}

	/**
	 * Return a wpdb-shaped result for the connection collation variable.
	 *
	 * The DuckDB driver currently accepts SET NAMES as a bootstrap no-op and
	 * returns an empty SHOW VARIABLES result. WordPress core asks for this
	 * variable directly after set_charset().
	 *
	 * @param string $query SQL query.
	 * @return WP_DuckDB_Result_Statement|null Result statement if handled.
	 */
	private function get_connection_collation_statement( $query ) {
		if (
			! preg_match(
				"/^\\s*SHOW\\s+(?:GLOBAL\\s+|LOCAL\\s+|SESSION\\s+)?VARIABLES\\s+WHERE\\s+Variable_name\\s*=\\s*(['\"])collation_connection\\1\\s*$/i",
				$query
			)
		) {
			return null;
		}

		return new WP_DuckDB_Result_Statement(
			array( 'Variable_name', 'Value' ),
			array(
				array(
					'collation_connection',
					$this->collate ? $this->collate : $this->get_default_collation_for_charset( $this->charset ),
				),
			)
		);
	}

	/**
	 * Get the default collation name for a charset.
	 *
	 * @param string $charset Character set.
	 * @return string Collation name.
	 */
	private function get_default_collation_for_charset( $charset ) {
		switch ( $charset ) {
			case 'utf8':
				return 'utf8_general_ci';
			case 'utf8mb3':
				return 'utf8mb3_general_ci';
			case 'utf8mb4':
				return 'utf8mb4_unicode_ci';
		}

		return $charset . '_general_ci';
	}

	/**
	 * Ensure database directory exists and is protected.
	 *
	 * @param string $database_path Database file path.
	 */
	private function ensure_database_directory( $database_path ) {
		$dir   = dirname( $database_path );
		$umask = umask( 0 );

		if ( ! is_dir( $dir ) ) {
			if ( ! @mkdir( $dir, 0700, true ) ) {
				wp_die( sprintf( 'Failed to create database directory: %s', $dir ), 'Error!' );
			}
		}
		if ( ! is_writable( $dir ) ) {
			wp_die( sprintf( 'Database directory is not writable: %s', $dir ), 'Error!' );
		}

		$path = $dir . DIRECTORY_SEPARATOR . '.htaccess';
		if ( ! is_file( $path ) ) {
			$result = file_put_contents( $path, 'DENY FROM ALL', LOCK_EX );
			if ( false === $result ) {
				wp_die( sprintf( 'Failed to create file: %s', $path ), 'Error!' );
			}
			chmod( $path, 0600 );
		}

		$path = $dir . DIRECTORY_SEPARATOR . 'index.php';
		if ( ! is_file( $path ) ) {
			$result = file_put_contents( $path, '<?php // Silence is gold. ?>', LOCK_EX );
			if ( false === $result ) {
				wp_die( sprintf( 'Failed to create file: %s', $path ), 'Error!' );
			}
			chmod( $path, 0600 );
		}

		umask( $umask );
	}
}
