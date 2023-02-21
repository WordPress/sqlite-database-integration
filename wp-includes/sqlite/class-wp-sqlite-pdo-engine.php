<?php
/**
 * The main PDO extension class.
 *
 * @package wp-sqlite-integration
 * @since 1.0.0
 */

/**
 * This class extends PDO class and does the real work.
 *
 * It accepts a request from wpdb class, initialize PDO instance,
 * execute SQL statement, and returns the results to WordPress.
 */
class WP_SQLite_PDO_Engine extends PDO { // phpcs:ignore

	const SQLITE_BUSY   = 5;
	const SQLITE_LOCKED = 6;

	/**
	 * The database version.
	 *
	 * This is used here to avoid PHP warnings in the health screen.
	 *
	 * @var string
	 */
	public $client_info = '';

	/**
	 * Class variable to check if there is an error.
	 *
	 * @var boolean
	 */
	public $is_error = false;

	/**
	 * Class variable which is used for CALC_FOUND_ROW query.
	 *
	 * @var unsigned integer
	 */
	public $found_rows_result = null;

	/**
	 * Class variable used for query with ORDER BY FIELD()
	 *
	 * @var array of the object
	 */
	public $pre_ordered_results = null;

	/**
	 * Class variable to store the last query.
	 *
	 * @var string
	 */
	public $last_translation;

	/**
	 * Class variable to store the rewritten queries.
	 *
	 * @access private
	 *
	 * @var array
	 */
	private $rewritten_query;

	/**
	 * Class variable to store the result of the query.
	 *
	 * @access private
	 *
	 * @var array reference to the PHP object
	 */
	private $results = null;

	/**
	 * Class variable to reference to the PDO instance.
	 *
	 * @access private
	 *
	 * @var PDO object
	 */
	private $pdo;

	/**
	 * Class variable to store the error messages.
	 *
	 * @access private
	 *
	 * @var array
	 */
	private $error_messages = array();

	/**
	 * Class variable to store the file name and function to cause error.
	 *
	 * @access private
	 *
	 * @var array
	 */
	private $errors;

	/**
	 * Class variable to store the query strings.
	 *
	 * @var array
	 */
	public $queries = array();

	/**
	 * Class variable to store the affected row id.
	 *
	 * @var unsigned integer
	 * @access private
	 */
	private $last_insert_id;

	/**
	 * Class variable to store the number of rows affected.
	 *
	 * @var unsigned integer
	 */
	private $affected_rows;

	/**
	 * Class variable to store the queried column info.
	 *
	 * @var array
	 */
	private $column_data;

	/**
	 * Variable to emulate MySQL affected row.
	 *
	 * @var integer
	 */
	private $num_rows;

	/**
	 * Return value from query().
	 *
	 * Each query has its own return value.
	 *
	 * @var mixed
	 */
	private $return_value;

	/**
	 * Variable to check if there is an active transaction.
	 *
	 * @var boolean
	 * @access protected
	 */
	protected $has_active_transaction = false;

	/**
	 * The translator object.
	 *
	 * @var WP_SQLite_Translator
	 */
	protected $translator;

	/**
	 * The number of rows found by the last SELECT query.
	 *
	 * @var int
	 */
	protected $last_found_rows;

	/**
	 * Constructor
	 *
	 * Create PDO object, set user defined functions and initialize other settings.
	 * Don't use parent::__construct() because this class does not only returns
	 * PDO instance but many others jobs.
	 *
	 * @param PDO $pdo The PDO object.
	 */
	function __construct( $pdo = null ) {
		if ( ! $pdo ) {
			if ( ! is_file( FQDB ) ) {
				$this->prepare_directory();
			}

			$locked      = false;
			$status      = 0;
			$err_message = '';
			do {
				try {
					$dsn = 'sqlite:' . FQDB;
					$pdo = new PDO( $dsn, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) ); // phpcs:ignore WordPress.DB.RestrictedClasses
					new WP_SQLite_PDO_User_Defined_Functions( $pdo );
				} catch ( PDOException $ex ) {
					$status = $ex->getCode();
					if ( 5 === $status || 6 === $status ) {
						$locked = true;
					} else {
						$err_message = $ex->getMessage();
					}
				}
			} while ( $locked );

			if ( $status > 0 ) {
				$message          = sprintf(
					'<p>%s</p><p>%s</p><p>%s</p>',
					'Database initialization error!',
					"Code: $status",
					"Error Message: $err_message"
				);
				$this->is_error   = true;
				$this->last_error = $message;

				return false;
			}

			// MySQL data comes across stringified by default.
			$pdo->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );
			$pdo->query( WP_SQLite_Translator::CREATE_DATA_TYPES_CACHE_TABLE );
		}
		$this->pdo = $pdo;

		// Fixes a warning in the site-health screen.
		$this->client_info = SQLite3::version()['versionString'];

		register_shutdown_function( array( $this, '__destruct' ) );
		$this->init();
	}

	/**
	 * Get the PDO object.
	 *
	 * @return PDO
	 */
	public function get_pdo() {
		return $this->pdo;
	}

	/**
	 * PDO has no explicit close() method.
	 *
	 * This is because PHP may choose to reuse the same
	 * connection for the next request. The PHP manual
	 * states the PDO object can only be unset:
	 *
	 * https://www.php.net/manual/en/pdo.connections.php#114822
	 */
	public function close() {
		$this->pdo = null;
	}

	/**
	 * This method makes database directory and .htaccess file.
	 *
	 * It is executed only once when the installation begins.
	 */
	private function prepare_directory() {
		global $wpdb;
		$u = umask( 0000 );
		if ( ! is_dir( FQDBDIR ) ) {
			if ( ! @mkdir( FQDBDIR, 0704, true ) ) {
				umask( $u );
				wp_die( 'Unable to create the required directory! Please check your server settings.', 'Error!' );
			}
		}
		if ( ! is_writable( FQDBDIR ) ) {
			umask( $u );
			$message = 'Unable to create a file in the directory! Please check your server settings.';
			wp_die( $message, 'Error!' );
		}
		if ( ! is_file( FQDBDIR . '.htaccess' ) ) {
			$fh = fopen( FQDBDIR . '.htaccess', 'w' );
			if ( ! $fh ) {
				umask( $u );
				echo 'Unable to create a file in the directory! Please check your server settings.';

				return false;
			}
			fwrite( $fh, 'DENY FROM ALL' );
			fclose( $fh );
		}
		if ( ! is_file( FQDBDIR . 'index.php' ) ) {
			$fh = fopen( FQDBDIR . 'index.php', 'w' );
			if ( ! $fh ) {
				umask( $u );
				echo 'Unable to create a file in the directory! Please check your server settings.';

				return false;
			}
			fwrite( $fh, '<?php // Silence is gold. ?>' );
			fclose( $fh );
		}
		umask( $u );

		return true;
	}

	/**
	 * Destructor
	 *
	 * If SQLITE_MEM_DEBUG constant is defined, append information about
	 * memory usage into database/mem_debug.txt.
	 *
	 * This definition is changed since version 1.7.
	 *
	 * @return boolean
	 */
	function __destruct() {
		if ( defined( 'SQLITE_MEM_DEBUG' ) && SQLITE_MEM_DEBUG ) {
			$max = ini_get( 'memory_limit' );
			if ( is_null( $max ) ) {
				$message = sprintf(
					'[%s] Memory_limit is not set in php.ini file.',
					gmdate( 'Y-m-d H:i:s', $_SERVER['REQUEST_TIME'] )
				);
				error_log( $message );
				return true;
			}
			if ( stripos( $max, 'M' ) !== false ) {
				$max = (int) $max * MB_IN_BYTES;
			}
			$peak = memory_get_peak_usage( true );
			$used = round( (int) $peak / (int) $max * 100, 2 );
			if ( $used > 90 ) {
				$message = sprintf(
					"[%s] Memory peak usage warning: %s %% used. (max: %sM, now: %sM)\n",
					gmdate( 'Y-m-d H:i:s', $_SERVER['REQUEST_TIME'] ),
					$used,
					$max,
					$peak
				);
				error_log( $message );
			}
		}

		return true;
	}

	/**
	 * Method to initialize database, executed in the constructor.
	 *
	 * It checks if WordPress is in the installing process and does the required
	 * jobs. SQLite library version specific settings are also in this function.
	 *
	 * Some developers use WP_INSTALLING constant for other purposes, if so, this
	 * function will do no harms.
	 */
	private function init() {
		if ( version_compare( SQLite3::version()['versionString'], '3.7.11', '>=' ) ) {
			$this->can_insert_multiple_rows = true;
		}
		$statement = $this->pdo->query( 'PRAGMA foreign_keys' );
		if ( $statement->fetchColumn( 0 ) == '0' ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			$this->pdo->query( 'PRAGMA foreign_keys = ON' );
		}
	}


	/**
	 * Method to execute query().
	 *
	 * Divide the query types into seven different ones. That is to say:
	 *
	 * 1. SELECT SQL_CALC_FOUND_ROWS
	 * 2. INSERT
	 * 3. CREATE TABLE(INDEX)
	 * 4. ALTER TABLE
	 * 5. SHOW VARIABLES
	 * 6. DROP INDEX
	 * 7. THE OTHERS
	 *
	 * #1 is just a tricky play. See the private function handle_sql_count() in query.class.php.
	 * From #2 through #5 call different functions respectively.
	 * #6 call the ALTER TABLE query.
	 * #7 is a normal process: sequentially call prepare_query() and execute_query().
	 *
	 * #1 process has been changed since version 1.5.1.
	 *
	 * @param string $statement          Full SQL statement string.
	 * @param int    $mode               Not used.
	 * @param array  ...$fetch_mode_args Not used.
	 *
	 * @see PDO::query()
	 *
	 * @throws Exception    If the query could not run.
	 * @throws PDOException If the translated query could not run.
	 *
	 * @return mixed according to the query type
	 */
	public function query( $statement, $mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) { // phpcs:ignore WordPress.DB.RestrictedClasses
		$this->flush();
		try {
			if (
				preg_match( '/^START TRANSACTION/i', $statement )
				|| preg_match( '/^BEGIN/i', $statement )
			) {
				return $this->beginTransaction();
			}
			if ( preg_match( '/^COMMIT/i', $statement ) ) {
				return $this->commit();
			}
			if ( preg_match( '/^ROLLBACK/i', $statement ) ) {
				return $this->rollBack();
			}

			$this->translator = new WP_SQLite_Translator( $this->pdo, $GLOBALS['table_prefix'] );

			do {
				$error = null;
				try {
					$translation = $this->translator->translate(
						$statement,
						$this->found_rows_result
					);
				} catch ( PDOException $error ) {
					if ( $error->getCode() !== self::SQLITE_BUSY ) {
						return $this->handle_error( $error );
					}
				}
			} while ( $error );

			$stmt        = null;
			$last_retval = null;
			foreach ( $translation->queries as $query ) {
				$this->queries[] = "Executing: {$query->sql} | " . ( $query->params ? 'parameters: ' . implode( ', ', $query->params ) : '(no parameters)' );
				do {
					$error = null;
					try {
						$stmt        = $this->pdo->prepare( $query->sql );
						$last_retval = $stmt->execute( $query->params );
					} catch ( PDOException $error ) {
						if ( $error->getCode() !== self::SQLITE_BUSY ) {
							throw $error;
						}
					}
				} while ( $error );
			}

			if ( $translation->has_result ) {
				$this->results = $translation->result;
			} else {
				switch($translation->mysql_query_type) {
					case 'DESCRIBE':
						$this->results = $stmt->fetchAll( $mode );
						if ( ! $this->results ) {
							$this->handle_error( new PDOException( 'Table not found' ) );
							return;
						}
						break;
					case 'SELECT':
					case 'SHOW':
						$this->results = $stmt->fetchAll( $mode );
						break;
					case 'TRUNCATE':
						$this->results = true;
						$this->return_value = true;
						return $this->return_value;
					case 'SET':
						$this->results = 0;
						break;
					default:
						$this->results = $last_retval;
						break;
				}
			}

			if ( $translation->calc_found_rows ) {
				$this->found_rows_result = $translation->calc_found_rows;
			}
			
			if ( is_array( $this->results ) ) {
				$this->num_rows        = count( $this->results );
				$this->last_found_rows = count( $this->results );
			}
	
			switch ( $translation->sqlite_query_type ) {
				case 'DELETE':
				case 'UPDATE':
				case 'INSERT':
				case 'REPLACE':
					/**
					* SELECT CHANGES() is a workaround for the fact that
					* $stmt->rowCount() returns "0" (zero) with the
					* SQLite driver at all times.
					* Source: https://www.php.net/manual/en/pdostatement.rowcount.php
					*/
					$this->affected_rows  = (int) $this->pdo->query( 'select changes()' )->fetch()[0];
					$this->return_value   = $this->affected_rows;
					$this->num_rows       = $this->affected_rows;
					$this->last_insert_id = $this->pdo->lastInsertId();
					if ( is_numeric( $this->last_insert_id ) ) {
						$this->last_insert_id = (int) $this->last_insert_id;
					}
					break;
				default:
					$this->return_value = $this->results;
					break;
			}

			return $this->return_value;
		} catch ( Exception $err ) {
			if ( defined( 'PDO_DEBUG' ) && PDO_DEBUG === true ) {
				throw $err;
			}
			return $this->handle_error( $err );
		}
	}

	/**
	 * Error handler.
	 *
	 * @param Exception $err Exception object.
	 *
	 * @return bool Always false.
	 */
	private function handle_error( Exception $err ) {
		$message     = $err->getMessage();
		$err_message = sprintf( 'Problem preparing the PDO SQL Statement. Error was: %s. trace: %s', $message, $err->getTraceAsString() );
		$this->set_error( __LINE__, __FUNCTION__, $err_message );
		$this->return_value = false;
		return false;
	}


	/**
	 * Method to return inserted row id.
	 */
	public function get_insert_id() {
		return $this->last_insert_id;
	}

	/**
	 * Method to return the number of rows affected.
	 */
	public function get_affected_rows() {
		return $this->affected_rows;
	}

	/**
	 * Method to return the queried column names.
	 *
	 * These data are meaningless for SQLite. So they are dummy emulating
	 * MySQL columns data.
	 *
	 * @return array of the object
	 */
	public function get_columns() {
		if ( ! empty( $this->results ) ) {
			$primary_key = array(
				'meta_id',
				'comment_ID',
				'link_ID',
				'option_id',
				'blog_id',
				'option_name',
				'ID',
				'term_id',
				'object_id',
				'term_taxonomy_id',
				'umeta_id',
				'id',
			);
			$unique_key  = array( 'term_id', 'taxonomy', 'slug' );
			$data        = array(
				'name'         => '', // Column name.
				'table'        => '', // Table name.
				'max_length'   => 0,  // Max length of the column.
				'not_null'     => 1,  // 1 if not null.
				'primary_key'  => 0,  // 1 if column has primary key.
				'unique_key'   => 0,  // 1 if column has unique key.
				'multiple_key' => 0,  // 1 if column doesn't have unique key.
				'numeric'      => 0,  // 1 if column has numeric value.
				'blob'         => 0,  // 1 if column is blob.
				'type'         => '', // Type of the column.
				'unsigned'     => 0,  // 1 if column is unsigned integer.
				'zerofill'     => 0,  // 1 if column is zero-filled.
			);
			$table_name  = '';
			if ( preg_match( '/\s*FROM\s*(.*)?\s*/i', $this->rewritten_query, $match ) ) {
				$table_name = trim( $match[1] );
			}
			foreach ( $this->results[0] as $key => $value ) {
				$data['name']  = $key;
				$data['table'] = $table_name;
				if ( in_array( $key, $primary_key, true ) ) {
					$data['primary_key'] = 1;
				} elseif ( in_array( $key, $unique_key, true ) ) {
					$data['unique_key'] = 1;
				} else {
					$data['multiple_key'] = 1;
				}
				$this->column_data[] = new WP_SQLite_Object_Array( $data );

				// Reset data for next iteration.
				$data['name']         = '';
				$data['table']        = '';
				$data['primary_key']  = 0;
				$data['unique_key']   = 0;
				$data['multiple_key'] = 0;
			}

			return $this->column_data;
		}
		return null;
	}

	/**
	 * Method to return the queried result data.
	 *
	 * @return mixed
	 */
	public function get_query_results() {
		return $this->results;
	}

	/**
	 * Method to return the number of rows from the queried result.
	 */
	public function get_num_rows() {
		return $this->num_rows;
	}

	/**
	 * Method to return the queried results according to the query types.
	 *
	 * @return mixed
	 */
	public function get_return_value() {
		return $this->return_value;
	}

	/**
	 * Method to return error messages.
	 *
	 * @throws Exception If error is found.
	 *
	 * @return string
	 */
	public function get_error_message() {
		if ( count( $this->error_messages ) === 0 ) {
			$this->is_error       = false;
			$this->error_messages = array();
			return '';
		}

		if ( false === $this->is_error ) {
			return '';
		}

		$output  = '<div style="clear:both">&nbsp;</div>' . PHP_EOL;
		$output .= '<div class="queries" style="clear:both;margin_bottom:2px;border:red dotted thin;">' . PHP_EOL;
		$output .= '<p>Queries made or created this session were:</p>' . PHP_EOL;
		$output .= '<ol>' . PHP_EOL;
		foreach ( $this->queries as $q ) {
			$output .= '<li>' . htmlspecialchars( $q ) . '</li>' . PHP_EOL;
		}
		$output .= '</ol>' . PHP_EOL;
		$output .= '</div>' . PHP_EOL;
		foreach ( $this->error_messages as $num => $m ) {
			$output .= '<div style="clear:both;margin_bottom:2px;border:red dotted thin;" class="error_message" style="border-bottom:dotted blue thin;">' . PHP_EOL;
			$output .= sprintf(
				'Error occurred at line %1$d in Function %2$s. Error message was: %3$s.',
				(int) $this->errors[ $num ]['line'],
				'<code>' . htmlspecialchars( $this->errors[ $num ]['function'] ) . '</code>',
				$m
			) . PHP_EOL;
			$output .= '</div>' . PHP_EOL;
		}

		try {
			throw new Exception();
		} catch ( Exception $e ) {
			$output .= '<p>Backtrace:</p>' . PHP_EOL;
			$output .= '<pre>' . htmlspecialchars( $e->getTraceAsString() ) . '</pre>' . PHP_EOL;
		}

		return $output;
	}

	/**
	 * Method to clear previous data.
	 */
	private function flush() {
		$this->rewritten_query = '';
		$this->results         = null;
		$this->last_insert_id  = null;
		$this->affected_rows   = null;
		$this->column_data     = array();
		$this->num_rows        = null;
		$this->return_value    = null;
		$this->error_messages  = array();
		$this->is_error        = false;
		$this->queries         = array();
		$this->param_num       = 0;
	}

	/**
	 * Method to format the error messages and put out to the file.
	 *
	 * When $wpdb::suppress_errors is set to true or $wpdb::show_errors is set to false,
	 * the error messages are ignored.
	 *
	 * @param string $line     Where the error occurred.
	 * @param string $function Indicate the function name where the error occurred.
	 * @param string $message  The message.
	 *
	 * @return boolean|void
	 */
	private function set_error( $line, $function, $message ) {
		$this->errors[]         = array(
			'line'     => $line,
			'function' => $function,
		);
		$this->error_messages[] = $message;
		$this->is_error         = true;
	}

	/**
	 * Method to call PDO::beginTransaction().
	 *
	 * @see PDO::beginTransaction()
	 * @return boolean
	 */
	public function beginTransaction() {
		if ( $this->has_active_transaction ) {
			return false;
		}
		$this->has_active_transaction = $this->pdo->beginTransaction();
		return $this->has_active_transaction;
	}

	/**
	 * Method to call PDO::commit().
	 *
	 * @see PDO::commit()
	 *
	 * @return void
	 */
	public function commit() {
		if ( $this->has_active_transaction ) {
			$this->pdo->commit();
			$this->has_active_transaction = false;
		}
	}

	/**
	 * Method to call PDO::rollBack().
	 *
	 * @see PDO::rollBack()
	 *
	 * @return void
	 */
	public function rollBack() {
		if ( $this->has_active_transaction ) {
			$this->pdo->rollBack();
			$this->has_active_transaction = false;
		}
	}
}
