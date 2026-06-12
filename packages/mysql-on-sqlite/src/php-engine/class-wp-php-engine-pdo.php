<?php

/*
 * The PDO facade extends PDO. Enable PDO function calls:
 *   phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 *   phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDOStatement
 *   phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
 */

/**
 * A PDO-compatible facade for the pure-PHP database engine.
 *
 * This class makes the engine a drop-in replacement for a PDO SQLite
 * connection. It can be passed anywhere a PDO instance connected to an
 * SQLite database is expected — most notably to WP_SQLite_Connection:
 *
 *     $pdo = new WP_PHP_Engine_PDO( 'php-engine::memory:' );
 *     $connection = new WP_SQLite_Connection( array( 'pdo' => $pdo ) );
 *
 * Supported DSNs: 'php-engine:<path>', 'php-engine::memory:', and the
 * SQLite forms 'sqlite:<path>' / 'sqlite::memory:' for compatibility.
 */
class WP_PHP_Engine_PDO extends PDO {
	/**
	 * The engine instance.
	 *
	 * @var WP_PHP_Engine
	 */
	private $engine;

	/**
	 * PDO attributes.
	 *
	 * @var array
	 */
	private $attributes = array();

	/**
	 * Whether a PDO-level transaction is active (via beginTransaction).
	 *
	 * @var bool
	 */
	private $pdo_transaction = false;

	/**
	 * The last error info triple.
	 *
	 * @var array
	 */
	private $last_error_info = array( '00000', null, null );

	/**
	 * Constructor.
	 *
	 * @param string      $dsn      The DSN.
	 * @param string|null $username Ignored.
	 * @param string|null $password Ignored.
	 * @param array|null  $options  PDO options.
	 *
	 * @throws PDOException When the DSN is not recognized.
	 */
	public function __construct( $dsn, $username = null, $password = null, $options = null ) {
		// Intentionally do NOT call the parent constructor: all PDO methods
		// used with this driver are overridden below.
		$dsn_parts = explode( ':', $dsn, 2 );
		if ( count( $dsn_parts ) < 2 || ! in_array( $dsn_parts[0], array( 'php-engine', 'sqlite' ), true ) ) {
			throw new PDOException( 'invalid data source name' );
		}
		$path             = $dsn_parts[1];
		$this->attributes = array(
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_BOTH,
			PDO::ATTR_STRINGIFY_FETCHES  => false,
			PDO::ATTR_EMULATE_PREPARES   => false,
			PDO::ATTR_TIMEOUT            => 0,
		);

		if ( is_array( $options ) && array_key_exists( PDO::ATTR_TIMEOUT, $options ) ) {
			$this->attributes[ PDO::ATTR_TIMEOUT ] = $options[ PDO::ATTR_TIMEOUT ];
		}
		$this->engine = new WP_PHP_Engine( $path, $this->attributes[ PDO::ATTR_TIMEOUT ] );

		if ( is_array( $options ) ) {
			foreach ( $options as $attribute => $value ) {
				$this->setAttribute( $attribute, $value );
			}
		}
	}

	/**
	 * Get the engine instance.
	 *
	 * @return WP_PHP_Engine
	 */
	public function get_engine() {
		return $this->engine;
	}

	/**
	 * Prepare a statement.
	 *
	 * @param  string $query   The SQL string.
	 * @param  array  $options Ignored.
	 * @return WP_PHP_Engine_PDO_Statement|false
	 */
	#[\ReturnTypeWillChange]
	public function prepare( $query, $options = array() ) {
		try {
			// Parse eagerly so that syntax errors surface on prepare(),
			// like they do with real PDO SQLite.
			$this->engine->parse( $query );
		} catch ( PDOException $e ) {
			return $this->handle_error( $e );
		}
		return new WP_PHP_Engine_PDO_Statement( $this, $this->engine, $query );
	}

	/**
	 * Execute a query directly.
	 *
	 * @param  string $query              The SQL string.
	 * @param  int    $fetch_mode         The fetch mode.
	 * @param  mixed  ...$fetch_mode_args Additional fetch mode arguments.
	 * @return WP_PHP_Engine_PDO_Statement|false
	 */
	#[\ReturnTypeWillChange]
	public function query( $query, $fetch_mode = null, ...$fetch_mode_args ) {
		$statement = $this->prepare( $query );
		if ( false === $statement ) {
			return false;
		}
		if ( false === $statement->execute() ) {
			return false;
		}
		if ( null !== $fetch_mode ) {
			$statement->setFetchMode( $fetch_mode, ...$fetch_mode_args );
		}
		return $statement;
	}

	/**
	 * Execute a statement and return the number of affected rows.
	 *
	 * @param  string $query The SQL string.
	 * @return int|false
	 */
	#[\ReturnTypeWillChange]
	public function exec( $query ) {
		try {
			$result = $this->engine->execute( $query );
		} catch ( PDOException $e ) {
			return $this->handle_error( $e );
		}
		return isset( $result['changes'] ) ? $result['changes'] : 0;
	}

	/**
	 * Get the last inserted rowid.
	 *
	 * @param  string|null $name Ignored.
	 * @return string
	 */
	#[\ReturnTypeWillChange]
	public function lastInsertId( $name = null ) {
		return (string) $this->engine->get_last_insert_rowid();
	}

	/**
	 * Begin a transaction.
	 *
	 * @return bool
	 */
	#[\ReturnTypeWillChange]
	public function beginTransaction() {
		if ( $this->pdo_transaction || $this->engine->in_transaction() ) {
			throw new PDOException( 'There is already an active transaction' );
		}
		$this->engine->execute( 'BEGIN' );
		$this->pdo_transaction = true;
		return true;
	}

	/**
	 * Commit the current transaction.
	 *
	 * @return bool
	 */
	#[\ReturnTypeWillChange]
	public function commit() {
		if ( ! $this->pdo_transaction && ! $this->engine->in_transaction() ) {
			throw new PDOException( 'There is no active transaction' );
		}
		$this->engine->execute( 'COMMIT' );
		$this->pdo_transaction = false;
		return true;
	}

	/**
	 * Roll back the current transaction.
	 *
	 * @return bool
	 */
	#[\ReturnTypeWillChange]
	public function rollBack() {
		if ( ! $this->pdo_transaction && ! $this->engine->in_transaction() ) {
			throw new PDOException( 'There is no active transaction' );
		}
		$this->engine->execute( 'ROLLBACK' );
		$this->pdo_transaction = false;
		return true;
	}

	/**
	 * Check whether a transaction is active.
	 *
	 * @return bool
	 */
	#[\ReturnTypeWillChange]
	public function inTransaction() {
		return $this->pdo_transaction || $this->engine->in_transaction();
	}

	/**
	 * Set an attribute.
	 *
	 * @param  int   $attribute The attribute.
	 * @param  mixed $value     The value.
	 * @return bool
	 */
	#[\ReturnTypeWillChange]
	public function setAttribute( $attribute, $value ) {
		$this->attributes[ $attribute ] = $value;
		if ( PDO::ATTR_TIMEOUT === $attribute ) {
			$this->engine->set_busy_timeout( $value );
		}
		return true;
	}

	/**
	 * Get an attribute.
	 *
	 * @param  int $attribute The attribute.
	 * @return mixed
	 */
	#[\ReturnTypeWillChange]
	public function getAttribute( $attribute ) {
		switch ( $attribute ) {
			case PDO::ATTR_SERVER_VERSION:
			case PDO::ATTR_CLIENT_VERSION:
				return WP_PHP_Engine::SQLITE_VERSION;
			case PDO::ATTR_SERVER_INFO:
				return '';
			case PDO::ATTR_DRIVER_NAME:
				return 'sqlite';
			case PDO::ATTR_CONNECTION_STATUS:
				return '';
		}
		return isset( $this->attributes[ $attribute ] ) ? $this->attributes[ $attribute ] : null;
	}

	/**
	 * Quote a value for use in a query.
	 *
	 * @param  mixed $value The value.
	 * @param  int   $type  The parameter type.
	 * @return string
	 */
	#[\ReturnTypeWillChange]
	public function quote( $value, $type = PDO::PARAM_STR ) {
		if ( PDO::PARAM_INT === $type && ( is_int( $value ) || ctype_digit( (string) $value ) ) ) {
			return (string) $value;
		}
		return "'" . str_replace( "'", "''", (string) $value ) . "'";
	}

	/**
	 * Register a user-defined function (PDO SQLite API).
	 *
	 * @param  string   $name          The SQL function name.
	 * @param  callable $callback      The implementation.
	 * @param  int      $num_args      Ignored.
	 * @param  int      $flags         Ignored.
	 * @return bool
	 */
	#[\ReturnTypeWillChange]
	public function sqliteCreateFunction( $name, $callback, $num_args = -1, $flags = 0 ) {
		$this->engine->register_function( $name, $callback );
		return true;
	}

	/**
	 * Register a user-defined function (PDO\SQLite subclass API).
	 *
	 * @param  string   $name     The SQL function name.
	 * @param  callable $callback The implementation.
	 * @param  int      $num_args Ignored.
	 * @param  int      $flags    Ignored.
	 * @return bool
	 */
	#[\ReturnTypeWillChange]
	public function createFunction( $name, $callback, $num_args = -1, $flags = 0 ) {
		return $this->sqliteCreateFunction( $name, $callback, $num_args, $flags );
	}

	/**
	 * Get the last error code.
	 *
	 * @return string|null
	 */
	#[\ReturnTypeWillChange]
	public function errorCode() {
		return $this->last_error_info[0];
	}

	/**
	 * Get the last error info.
	 *
	 * @return array
	 */
	#[\ReturnTypeWillChange]
	public function errorInfo() {
		return $this->last_error_info;
	}

	/**
	 * Handle an engine error according to the error mode.
	 *
	 * @param  PDOException $e The exception.
	 * @return false
	 * @throws PDOException In ERRMODE_EXCEPTION mode.
	 */
	public function handle_error( $e ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->last_error_info = isset( $e->errorInfo ) && is_array( $e->errorInfo )
			? $e->errorInfo
			: array( 'HY000', 1, $e->getMessage() );
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( PDO::ERRMODE_EXCEPTION === $this->getAttribute( PDO::ATTR_ERRMODE ) ) {
			throw $e;
		}
		if ( PDO::ERRMODE_WARNING === $this->getAttribute( PDO::ATTR_ERRMODE ) ) {
			trigger_error( $e->getMessage(), E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
		return false;
	}
}
