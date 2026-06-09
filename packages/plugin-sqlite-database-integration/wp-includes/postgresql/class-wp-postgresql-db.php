<?php
/**
 * PostgreSQL wpdb drop-in scaffold.
 *
 * @package wp-sqlite-integration
 */

if ( ! class_exists( 'WP_PostgreSQL_Driver', false ) ) {
	require_once __DIR__ . '/../database/postgresql/class-wp-postgresql-connection.php';
	require_once __DIR__ . '/../database/postgresql/class-wp-postgresql-driver.php';
}

/*
 * The PostgreSQL drop-in uses PDO through the backend driver. Enable PDO
 * type checks in this compatibility layer:
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */

/**
 * PostgreSQL-backed wpdb replacement.
 *
 * The PostgreSQL backend is intentionally routed through its own class instead
 * of reusing the SQLite file-backed connection path.
 */
class WP_PostgreSQL_DB extends wpdb {
	/**
	 * Database handle.
	 *
	 * @var WP_PostgreSQL_Driver|null
	 */
	protected $dbh;

	/**
	 * Backward compatibility, see wpdb::$allow_unsafe_unquoted_parameters.
	 *
	 * @var bool
	 */
	private $allow_unsafe_unquoted_parameters = true;

	/**
	 * Constructor.
	 *
	 * @param string $dbuser     Database user.
	 * @param string $dbpassword Database password.
	 * @param string $dbname     Database name.
	 * @param string $dbhost     Database host.
	 */
	public function __construct( $dbuser, $dbpassword, $dbname, $dbhost ) {
		$GLOBALS['wpdb'] = $this;

		parent::__construct( $dbuser, $dbpassword, $dbname, $dbhost );
		$this->charset = 'utf8mb4';
	}

	/**
	 * Method to set character set for the database.
	 *
	 * @param resource $dbh     The database handle.
	 * @param string   $charset Optional. The character set.
	 * @param string   $collate Optional. The collation.
	 */
	public function set_charset( $dbh, $charset = null, $collate = null ) {}

	/**
	 * Method to get the character set for the database.
	 *
	 * @param string $table  The table name.
	 * @param string $column The column name.
	 * @return string The character set.
	 */
	public function get_col_charset( $table, $column ) {
		return 'utf8mb4';
	}

	/**
	 * Changes the current SQL mode.
	 *
	 * PostgreSQL does not expose MySQL sql_mode. The MySQL-emulation layer will
	 * own this state once query translation is implemented.
	 *
	 * @param array $modes Optional. A list of SQL modes to set. Default empty array.
	 */
	public function set_sql_mode( $modes = array() ) {}

	/**
	 * Closes the current database connection.
	 *
	 * @return bool True when an open connection existed.
	 */
	public function close() {
		if ( ! $this->dbh ) {
			return false;
		}

		$this->dbh   = null;
		$this->ready = false;
		return true;
	}

	/**
	 * Method to select the database connection.
	 *
	 * @param string        $db  Database name.
	 * @param resource|null $dbh Optional link identifier.
	 * @return bool Whether the selected database matches the configured database.
	 */
	public function select( $db, $dbh = null ) {
		if ( null === $dbh ) {
			$dbh = $this->dbh;
		}

		$this->ready = $dbh instanceof WP_PostgreSQL_Driver && (string) $db === (string) $this->dbname;
		return $this->ready;
	}

	/**
	 * Escapes string data without using mysqli.
	 *
	 * @param string $data The string to escape.
	 * @return string Escaped string.
	 */
	public function _real_escape( $data ) {
		if ( ! is_scalar( $data ) ) {
			return '';
		}

		$escaped = addslashes( (string) $data );
		if ( $this->dbh instanceof WP_PostgreSQL_Driver ) {
			$quoted = $this->dbh->get_connection()->quote( (string) $data );
			if ( false !== $quoted && 2 <= strlen( $quoted ) && "'" === $quoted[0] && "'" === substr( $quoted, -1 ) ) {
				$escaped = substr( $quoted, 1, -1 );
			}
		}

		return $this->add_placeholder_escape( $escaped );
	}

	/**
	 * Quotes a PostgreSQL identifier.
	 *
	 * @param string $identifier Identifier to escape.
	 * @return string Escaped identifier.
	 */
	public function quote_identifier( $identifier ) {
		return WP_PostgreSQL_Connection::quote_identifier_value( (string) $identifier );
	}

	/**
	 * Method to flush cached data.
	 */
	public function flush() {
		$this->last_result   = array();
		$this->col_info      = null;
		$this->last_query    = null;
		$this->rows_affected = 0;
		$this->num_rows      = 0;
		$this->last_error    = '';
		$this->result        = null;
	}

	/**
	 * Connects to the PostgreSQL database.
	 *
	 * @param bool $allow_bail Whether to bail on connection failure.
	 * @return bool Whether the connection succeeded.
	 */
	public function db_connect( $allow_bail = true ) {
		$this->is_mysql = false;

		if ( $this->dbh instanceof WP_PostgreSQL_Driver ) {
			$this->ready = true;
			return true;
		}

		$this->ready      = false;
		$this->last_error = '';
		$this->init_charset();

		if ( null === $this->dbname || '' === (string) $this->dbname ) {
			$this->last_error = 'The database name was not set. The PostgreSQL backend requires DB_NAME.';
			if ( $allow_bail ) {
				$this->bail( $this->last_error, 'db_connect_fail' );
			}
			return false;
		}

		try {
			$connection      = new WP_PostgreSQL_Connection( $this->get_connection_options() );
			$this->dbh       = new WP_PostgreSQL_Driver( $connection, $this->dbname );
			$GLOBALS['@pdo'] = $connection->get_pdo();
			$this->ready     = true;
			$this->set_sql_mode();
			return true;
		} catch ( Throwable $e ) {
			$this->dbh        = null;
			$this->ready      = false;
			$this->last_error = $this->format_error_message( $e );

			if ( $allow_bail ) {
				$this->bail( $this->last_error, 'db_connect_fail' );
			}

			return false;
		}
	}

	/**
	 * Method to dummy out wpdb::check_connection().
	 *
	 * @param bool $allow_bail Whether to bail on connection failure.
	 * @return bool Whether the connection is alive.
	 */
	public function check_connection( $allow_bail = true ) {
		if ( $this->dbh instanceof WP_PostgreSQL_Driver ) {
			try {
				$this->dbh->get_connection()->query( 'SELECT 1' );
				return true;
			} catch ( Throwable $e ) {
				$this->last_error = $this->format_error_message( $e );
				$this->dbh        = null;
				$this->ready      = false;
			}
		}

		return $this->db_connect( $allow_bail );
	}

	/**
	 * Prepares a SQL query for safe execution.
	 *
	 * @param string      $query Query statement with placeholders.
	 * @param array|mixed $args  Variables to substitute.
	 * @param mixed       ...$args Further variables to substitute.
	 * @return string|void Sanitized query string, if there is a query to prepare.
	 */
	public function prepare( $query, ...$args ) {
		$wpdb_allow_unsafe_unquoted_parameters = $this->__get( 'allow_unsafe_unquoted_parameters' );
		if ( $wpdb_allow_unsafe_unquoted_parameters !== $this->allow_unsafe_unquoted_parameters ) {
			$property = new ReflectionProperty( 'wpdb', 'allow_unsafe_unquoted_parameters' );
			$property->setAccessible( true );
			$property->setValue( $this, $this->allow_unsafe_unquoted_parameters );
			$property->setAccessible( false );
		}

		if ( null === $query ) {
			return parent::prepare( $query, ...$args );
		}

		return parent::prepare( $query, ...$args );
	}

	/**
	 * Performs a database query.
	 *
	 * @param string $query Database query.
	 * @return int|bool Boolean true for CREATE, ALTER, TRUNCATE and DROP queries.
	 *                  Number of rows affected/selected for all other queries.
	 *                  Boolean false on error.
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

		$last_query_count = count( $this->queries ?? array() );
		$this->_do_query( $query );

		if ( $this->last_error ) {
			if ( $this->insert_id && in_array( $this->get_statement_keyword( $query ), array( 'insert', 'replace' ), true ) ) {
				$this->insert_id = 0;
			}

			$this->print_error();
			return false;
		}

		$statement_type = $this->get_statement_keyword( $query );
		if ( in_array( $statement_type, array( 'create', 'alter', 'truncate', 'drop' ), true ) ) {
			$return_val = true;
		} elseif ( in_array( $statement_type, array( 'insert', 'delete', 'update', 'replace' ), true ) ) {
			$this->rows_affected = $this->dbh->get_last_return_value();

			if ( in_array( $statement_type, array( 'insert', 'replace' ), true ) ) {
				$this->insert_id = $this->dbh->get_insert_id();
			}

			$return_val = $this->rows_affected;
		} else {
			$num_rows = 0;

			if ( is_array( $this->result ) ) {
				$this->last_result = $this->result;
				$num_rows          = count( $this->result );
			}

			$this->num_rows = $num_rows;
			$return_val     = $num_rows;
		}

		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES && isset( $this->queries[ $last_query_count ] ) ) {
			$this->queries[ $last_query_count ]['postgresql_queries'] = $this->dbh->get_last_postgresql_queries();
		}

		return $return_val;
	}

	/**
	 * Method to return what the database can do.
	 *
	 * @param string $db_cap The feature to check.
	 * @return bool Whether the database feature is supported.
	 */
	public function has_cap( $db_cap ) {
		return 'subqueries' === strtolower( $db_cap );
	}

	/**
	 * Method to return database version number.
	 *
	 * @return string PostgreSQL compatibility version.
	 */
	public function db_version() {
		return '8.0';
	}

	/**
	 * Returns the server info string.
	 *
	 * @return string Server info.
	 */
	public function db_server_info() {
		if ( $this->dbh instanceof WP_PostgreSQL_Driver ) {
			return $this->dbh->get_postgresql_version();
		}
		return 'PostgreSQL backend pending connection';
	}

	/**
	 * Internal function to perform the PostgreSQL query call.
	 *
	 * @param string $query The query to run.
	 */
	private function _do_query( $query ) {
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$this->timer_start();
		}

		try {
			$this->result = $this->dbh->query( $query );
		} catch ( Throwable $e ) {
			$this->last_error = $this->format_error_message( $e );
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
	 * Method to set the class variable $col_info.
	 *
	 * This overrides wpdb::load_col_info(), which uses mysqli metadata.
	 */
	protected function load_col_info() {
		if ( $this->col_info ) {
			return;
		}
		$this->col_info = array();
		foreach ( $this->dbh->get_last_column_meta() as $column ) {
			$this->col_info[] = (object) array(
				'name'       => $column['name'],
				'orgname'    => $column['mysqli:orgname'],
				'table'      => $column['table'],
				'orgtable'   => $column['mysqli:orgtable'],
				'def'        => '',
				'db'         => $column['mysqli:db'],
				'catalog'    => 'def',
				'max_length' => 0,
				'length'     => $column['len'],
				'charsetnr'  => $column['mysqli:charsetnr'],
				'flags'      => $column['mysqli:flags'],
				'type'       => $column['mysqli:type'],
				'decimals'   => $column['precision'],
			);
		}
	}

	/**
	 * Builds PostgreSQL connection options from wpdb constructor state.
	 *
	 * @return array
	 */
	private function get_connection_options() {
		$host = $this->dbhost;
		$port = null;

		$host_data = $this->parse_db_host( $this->dbhost );
		if ( $host_data ) {
			list( $host, $port, $socket ) = $host_data;

			if ( null !== $socket && '' !== $socket ) {
				$host        = $this->get_postgresql_socket_host( $socket );
				$socket_port = $this->get_postgresql_socket_port( $socket );
				if ( null === $port && null !== $socket_port ) {
					$port = $socket_port;
				}
			}
		}

		$options = array(
			'host'     => $host,
			'port'     => $port,
			'dbname'   => $this->dbname,
			'user'     => $this->dbuser,
			'password' => $this->dbpassword,
		);

		if ( isset( $GLOBALS['@pdo'] ) && $GLOBALS['@pdo'] instanceof PDO && $this->is_postgresql_pdo( $GLOBALS['@pdo'] ) ) {
			$options['pdo'] = $GLOBALS['@pdo'];
		}

		return $options;
	}

	/**
	 * Returns the libpq socket directory when DB_HOST includes a socket file.
	 *
	 * @param string $socket Socket path or directory.
	 * @return string PostgreSQL host option.
	 */
	private function get_postgresql_socket_host( $socket ) {
		$socket_file = basename( $socket );
		if ( 0 === strpos( $socket_file, '.s.PGSQL.' ) ) {
			return dirname( $socket );
		}
		return $socket;
	}

	/**
	 * Returns the PostgreSQL port encoded in a socket file path.
	 *
	 * @param string $socket Socket path or directory.
	 * @return int|null PostgreSQL port.
	 */
	private function get_postgresql_socket_port( $socket ) {
		$prefix      = '.s.PGSQL.';
		$socket_file = basename( $socket );
		if ( 0 !== strpos( $socket_file, $prefix ) ) {
			return null;
		}

		$port = substr( $socket_file, strlen( $prefix ) );
		return ctype_digit( $port ) ? (int) $port : null;
	}

	/**
	 * Checks whether a reusable PDO object is PostgreSQL-backed.
	 *
	 * @param PDO $pdo PDO instance.
	 * @return bool Whether the PDO driver is PostgreSQL.
	 */
	private function is_postgresql_pdo( PDO $pdo ) {
		try {
			return 'pgsql' === $pdo->getAttribute( PDO::ATTR_DRIVER_NAME );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Returns the first SQL statement keyword.
	 *
	 * @param string $query SQL query.
	 * @return string Lowercase statement keyword, or empty string.
	 */
	private function get_statement_keyword( $query ) {
		$length = strlen( $query );
		$i      = 0;

		while ( $i < $length ) {
			$char = $query[ $i ];
			if ( ctype_space( $char ) ) {
				++$i;
				continue;
			}

			if ( '-' === $char && $i + 1 < $length && '-' === $query[ $i + 1 ] ) {
				$i += 2;
				while ( $i < $length && "\n" !== $query[ $i ] ) {
					++$i;
				}
				continue;
			}

			if ( '/' === $char && $i + 1 < $length && '*' === $query[ $i + 1 ] ) {
				$i += 2;
				while ( $i + 1 < $length && ! ( '*' === $query[ $i ] && '/' === $query[ $i + 1 ] ) ) {
					++$i;
				}
				$i += 2;
				continue;
			}

			break;
		}

		$start = $i;
		while ( $i < $length && ( ctype_alpha( $query[ $i ] ) || '_' === $query[ $i ] ) ) {
			++$i;
		}

		return strtolower( substr( $query, $start, $i - $start ) );
	}

	/**
	 * Format PostgreSQL driver error message.
	 *
	 * @param Throwable $e Error.
	 * @return string Error message.
	 */
	private function format_error_message( Throwable $e ) {
		return $e->getMessage();
	}
}
