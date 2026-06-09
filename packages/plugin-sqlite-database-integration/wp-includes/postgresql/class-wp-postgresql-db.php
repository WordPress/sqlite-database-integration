<?php
/**
 * PostgreSQL wpdb drop-in scaffold.
 *
 * @package wp-sqlite-integration
 */

/**
 * PostgreSQL-backed wpdb replacement.
 *
 * The PostgreSQL backend is intentionally routed through its own class instead
 * of reusing the SQLite file-backed connection path.
 */
class WP_PostgreSQL_DB extends wpdb {
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
	 * Connects to the PostgreSQL database.
	 *
	 * @param bool $allow_bail Not used.
	 * @return false
	 */
	public function db_connect( $allow_bail = true ) {
		$this->ready      = false;
		$this->last_error = 'The PostgreSQL backend is selected, but the PostgreSQL MySQL-emulation driver has not been implemented yet.';
		return false;
	}

	/**
	 * Method to select the database connection.
	 *
	 * @param string        $db  Database name.
	 * @param resource|null $dbh Optional link identifier.
	 */
	public function select( $db, $dbh = null ) {
		$this->ready = false;
	}

	/**
	 * Method to dummy out wpdb::check_connection().
	 *
	 * @param bool $allow_bail Not used.
	 * @return bool
	 */
	public function check_connection( $allow_bail = true ) {
		return false;
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

		return parent::prepare( $query, ...$args );
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
		return 'PostgreSQL backend pending implementation';
	}
}
