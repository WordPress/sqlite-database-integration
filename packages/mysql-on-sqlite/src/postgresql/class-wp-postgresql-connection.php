<?php declare(strict_types = 1);

/*
 * The PostgreSQL connection uses PDO. Enable PDO function calls:
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */

/**
 * PostgreSQL connection.
 *
 * This class configures and encapsulates the connection to a PostgreSQL
 * database. It intentionally mirrors the small surface currently exposed by
 * WP_SQLite_Connection so a PostgreSQL MySQL-emulation driver can be built
 * without reusing SQLite-specific file, PRAGMA, and journal-mode handling.
 */
class WP_PostgreSQL_Connection {
	/**
	 * The PDO connection for PostgreSQL.
	 *
	 * @var PDO
	 */
	private $pdo;

	/**
	 * A query logger callback.
	 *
	 * @var callable(string, array): void
	 */
	private $query_logger;

	/**
	 * Constructor.
	 *
	 * @param array $options {
	 *     An array of options.
	 *
	 *     @type PDO|null    $pdo      Optional PDO instance with PostgreSQL
	 *                                  connection. If not provided, a new PDO
	 *                                  instance will be created.
	 *     @type string|null $dsn      Optional PostgreSQL PDO DSN.
	 *     @type string|null $host     Optional PostgreSQL host.
	 *     @type int|null    $port     Optional PostgreSQL port.
	 *     @type string|null $dbname   Optional PostgreSQL database name.
	 *     @type string|null $user     Optional PostgreSQL user.
	 *     @type string|null $password Optional PostgreSQL password.
	 * }
	 *
	 * @throws InvalidArgumentException When connection options are invalid.
	 * @throws PDOException             When the driver initialization fails.
	 */
	public function __construct( array $options ) {
		if ( isset( $options['pdo'] ) && $options['pdo'] instanceof PDO ) {
			$this->pdo = $options['pdo'];
		} else {
			$dsn       = isset( $options['dsn'] ) ? (string) $options['dsn'] : self::build_dsn( $options );
			$user      = isset( $options['user'] ) ? (string) $options['user'] : null;
			$password  = isset( $options['password'] ) ? (string) $options['password'] : null;
			$pdo_class = PHP_VERSION_ID >= 80400 && class_exists( 'PDO\Pgsql' ) ? PDO\Pgsql::class : PDO::class;

			$this->pdo = new $pdo_class( $dsn, $user, $password );
		}

		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );
	}

	/**
	 * Builds a PostgreSQL PDO DSN from connection options.
	 *
	 * @param array $options Connection options.
	 * @return string PostgreSQL PDO DSN.
	 *
	 * @throws InvalidArgumentException When no DSN or dbname option is provided.
	 */
	public static function build_dsn( array $options ): string {
		if ( ! isset( $options['dbname'] ) || '' === (string) $options['dbname'] ) {
			throw new InvalidArgumentException( 'Option "dbname" is required when "dsn" or "pdo" is not provided.' );
		}

		$parts = array();
		foreach ( array( 'host', 'port', 'dbname' ) as $key ) {
			if ( isset( $options[ $key ] ) && '' !== (string) $options[ $key ] ) {
				$parts[] = $key . '=' . self::format_dsn_value( (string) $options[ $key ] );
			}
		}

		return 'pgsql:' . implode( ';', $parts );
	}

	/**
	 * Quote a PostgreSQL identifier value.
	 *
	 * @param string $unquoted_identifier The unquoted identifier value.
	 * @return string The quoted identifier value.
	 *
	 * @throws InvalidArgumentException When the identifier contains a NUL byte.
	 */
	public static function quote_identifier_value( string $unquoted_identifier ): string {
		if ( false !== strpos( $unquoted_identifier, "\0" ) ) {
			throw new InvalidArgumentException( 'PostgreSQL identifiers cannot contain NUL bytes.' );
		}

		return '"' . str_replace( '"', '""', $unquoted_identifier ) . '"';
	}

	/**
	 * Execute a query in PostgreSQL.
	 *
	 * @param string $sql    The query to execute.
	 * @param array  $params The query parameters.
	 * @return PDOStatement The PDO statement object.
	 *
	 * @throws PDOException When the query execution fails.
	 */
	public function query( string $sql, array $params = array() ): PDOStatement {
		if ( $this->query_logger ) {
			( $this->query_logger )( $sql, $params );
		}
		$stmt = $this->pdo->prepare( $sql );
		$stmt->execute( $params );
		return $stmt;
	}

	/**
	 * Prepare a PostgreSQL query for execution.
	 *
	 * @param string $sql The query to prepare.
	 * @return PDOStatement The prepared statement.
	 *
	 * @throws PDOException When the query preparation fails.
	 */
	public function prepare( string $sql ): PDOStatement {
		if ( $this->query_logger ) {
			( $this->query_logger )( $sql, array() );
		}
		return $this->pdo->prepare( $sql );
	}

	/**
	 * Returns the ID of the last inserted row.
	 *
	 * @param string|null $sequence Optional PostgreSQL sequence name.
	 * @return string The ID of the last inserted row.
	 */
	public function get_last_insert_id( ?string $sequence = null ): string {
		return null === $sequence ? $this->pdo->lastInsertId() : $this->pdo->lastInsertId( $sequence );
	}

	/**
	 * Quote a value for use in a query.
	 *
	 * @param mixed $value The value to quote.
	 * @param int   $type  The type of the value.
	 * @return string The quoted value.
	 */
	public function quote( $value, int $type = PDO::PARAM_STR ): string {
		if (
			PDO::PARAM_STR === $type
			&& is_string( $value )
			&& false !== strpos( $value, '\\' )
			&& 'pgsql' === $this->pdo->getAttribute( PDO::ATTR_DRIVER_NAME )
		) {
			return self::quote_escaped_string_value( $value );
		}

		return $this->pdo->quote( $value, $type );
	}

	/**
	 * Quote a PostgreSQL identifier.
	 *
	 * @param string $unquoted_identifier The unquoted identifier value.
	 * @return string The quoted identifier value.
	 */
	public function quote_identifier( string $unquoted_identifier ): string {
		return self::quote_identifier_value( $unquoted_identifier );
	}

	/**
	 * Get the PDO object.
	 *
	 * @return PDO
	 */
	public function get_pdo(): PDO {
		return $this->pdo;
	}

	/**
	 * Set a logger for the queries.
	 *
	 * @param callable(string, array): void $logger A query logger callback.
	 */
	public function set_query_logger( callable $logger ): void {
		$this->query_logger = $logger;
	}

	/**
	 * Formats a structured PostgreSQL DSN value.
	 *
	 * Direct DSNs may still be supplied through the "dsn" option. Structured
	 * options reject DSN separators instead of escaping them ambiguously.
	 *
	 * @param string $value DSN part value.
	 * @return string Formatted DSN part value.
	 *
	 * @throws InvalidArgumentException When a DSN part contains an unsafe byte.
	 */
	private static function format_dsn_value( string $value ): string {
		if ( false !== strpos( $value, "\0" ) || false !== strpos( $value, ';' ) ) {
			throw new InvalidArgumentException( 'PostgreSQL DSN parts cannot contain NUL bytes or semicolons.' );
		}

		return $value;
	}

	/**
	 * Quote a string value using PostgreSQL escape string syntax.
	 *
	 * pdo_pgsql scans SQL text for placeholders before sending it to the server.
	 * Rendering backslash-bearing values as E'' strings keeps the client-side
	 * parser from treating a trailing backslash as escaping the closing quote.
	 *
	 * @param string $value String value.
	 * @return string PostgreSQL escaped string literal.
	 */
	private static function quote_escaped_string_value( string $value ): string {
		return "E'" . str_replace( array( '\\', "'" ), array( '\\\\', "''" ), $value ) . "'";
	}
}
