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
	 * Noop charset setter.
	 *
	 * @param resource $dbh     Database handle.
	 * @param string   $charset Optional charset.
	 * @param string   $collate Optional collation.
	 */
	public function set_charset( $dbh, $charset = null, $collate = null ) {
	}

	/**
	 * Return the column charset.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @return string
	 */
	public function get_col_charset( $table, $column ) {
		return 'utf8mb4';
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

		$this->ready = true;
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
		return true;
	}

	/**
	 * Perform a query.
	 *
	 * @param string $query Database query.
	 * @return int|bool
	 */
	public function query( $query ) {
		if ( ! $this->ready ) {
			return false;
		}

		$query = apply_filters( 'query', $query );
		if ( ! $query ) {
			$this->insert_id = 0;
			return false;
		}

		$this->flush();
		$this->func_call  = "\$db->query(\"$query\")";
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
				$this->insert_id = $this->dbh->get_insert_id();
			}
			return $this->rows_affected;
		}

		$this->last_result = is_array( $this->result ) ? $this->result : array();
		$this->num_rows    = count( $this->last_result );
		return $this->num_rows;
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
			$this->last_statement = $this->dbh->query( $query );
			if ( $this->last_statement->columnCount() > 0 ) {
				$this->result = $this->last_statement->fetchAll( PDO::FETCH_OBJ ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO
			} else {
				$this->result = null;
			}
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();
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
		return 'subqueries' === strtolower( $db_cap );
	}

	/**
	 * Return a MySQL-compatible version string.
	 *
	 * @return string
	 */
	public function db_version() {
		return '8.0';
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
