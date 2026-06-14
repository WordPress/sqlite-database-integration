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
	 * Prefix for encoded MySQL text bytes PostgreSQL text cannot store directly.
	 *
	 * PostgreSQL text rejects NUL bytes. Use a versioned whole-value envelope so
	 * MySQL string literals that decode to NUL can still round-trip through text
	 * columns without making short sentinel-like byte sequences ambiguous.
	 */
	private const MYSQL_TEXT_ENCODING_PREFIX = "\xEE\x80\x80WP_MYSQL_TEXT_V1:";

	/**
	 * Hash context for the MySQL text encoding envelope.
	 */
	private const MYSQL_TEXT_ENCODING_HASH_CONTEXT = 'wp-mysql-text-v1:';

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
	 * Counter for generated statement savepoints.
	 *
	 * @var int
	 */
	private $savepoint_counter = 0;

	/**
	 * Active generated savepoint shared by consecutive read-only statements.
	 *
	 * @var string|null
	 */
	private $active_read_savepoint = null;

	/**
	 * Whether the active read savepoint still needs to be created in PostgreSQL.
	 *
	 * @var bool
	 */
	private $active_read_savepoint_needs_creation = false;

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

		$release_savepoint_on_success = false;
		$savepoint_exists             = false;
		$savepoint                    = $this->get_statement_savepoint_name( $sql, $release_savepoint_on_success, $savepoint_exists );
		if ( null !== $savepoint && ! $savepoint_exists ) {
			$this->ensure_statement_savepoint_exists( $savepoint );
		}

		try {
			$stmt = $this->pdo->prepare( $sql );
			$stmt->execute( $params );

			if ( null !== $savepoint && $release_savepoint_on_success ) {
				$this->release_statement_savepoint( $savepoint );
			}

			$this->maybe_reset_read_savepoint_after_transaction_control( $sql );

			return $stmt;
		} catch ( Throwable $exception ) {
			if ( null !== $savepoint ) {
				$this->rollback_statement_savepoint( $savepoint );
			}

			throw $exception;
		}
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
		$this->consume_active_read_savepoint();
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
			&& 'pgsql' === $this->get_driver_name()
		) {
			$value = self::encode_mysql_text_for_postgresql( $value );
			if ( self::requires_postgresql_escape_string_syntax( $value ) ) {
				return self::quote_escaped_string_value( $value );
			}
		}

		return $this->pdo->quote( $value, $type );
	}

	/**
	 * Get the backing PDO driver name.
	 *
	 * @return string PDO driver name.
	 */
	protected function get_driver_name(): string {
		return (string) $this->pdo->getAttribute( PDO::ATTR_DRIVER_NAME );
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
	 * Reset generated statement savepoint state after direct transaction control.
	 */
	public function reset_statement_savepoint_state(): void {
		$this->active_read_savepoint                = null;
		$this->active_read_savepoint_needs_creation = false;
	}

	/**
	 * Get a generated statement savepoint name for an active PostgreSQL transaction.
	 *
	 * PostgreSQL marks the whole transaction as failed after a statement error.
	 * Isolating each emulated statement in a savepoint preserves MySQL's behavior
	 * where the failed statement can be reported without poisoning later queries.
	 *
	 * Consecutive non-locking reads share one generated savepoint. If any read
	 * fails, rolling back to that savepoint only discards prior reads, while the
	 * transaction remains usable. The next write or locking statement consumes
	 * and releases the shared savepoint as its own statement guard.
	 *
	 * @param string $sql                          SQL statement.
	 * @param bool   $release_savepoint_on_success Whether the caller should release the savepoint after success.
	 * @param bool   $savepoint_exists             Whether the savepoint already exists in PostgreSQL.
	 * @return string|null Savepoint name, or null when no statement savepoint is needed.
	 */
	private function get_statement_savepoint_name( string $sql, bool &$release_savepoint_on_success, bool &$savepoint_exists ): ?string {
		$release_savepoint_on_success = false;
		$savepoint_exists             = false;

		if (
			'pgsql' !== $this->get_driver_name()
			|| ! $this->pdo->inTransaction()
			|| $this->is_postgresql_transaction_control_statement( $sql )
		) {
			return null;
		}

		if ( $this->is_postgresql_shared_read_savepoint_statement( $sql ) ) {
			$savepoint_exists = null !== $this->active_read_savepoint && ! $this->active_read_savepoint_needs_creation;
			return $this->get_or_create_active_read_savepoint_name();
		}

		$release_savepoint_on_success = true;
		if ( null !== $this->active_read_savepoint ) {
			$savepoint_exists                           = ! $this->active_read_savepoint_needs_creation;
			$savepoint                                  = $this->active_read_savepoint;
			$this->active_read_savepoint                = null;
			$this->active_read_savepoint_needs_creation = false;
			return $savepoint;
		}

		++$this->savepoint_counter;
		return 'wp_statement_' . $this->savepoint_counter;
	}

	/**
	 * Check whether SQL directly controls the active transaction.
	 *
	 * @param string $sql SQL statement.
	 * @return bool Whether this is a transaction-control statement.
	 */
	private function is_postgresql_transaction_control_statement( string $sql ): bool {
		return 1 === preg_match(
			'/^\s*(BEGIN|START\s+TRANSACTION|COMMIT|ROLLBACK|SAVEPOINT|RELEASE)(?:\s|;|$)/i',
			$sql
		);
	}

	/**
	 * Check whether SQL is a non-locking SELECT that may share a read savepoint.
	 *
	 * These high-volume reads do not acquire row locks in WordPress's query
	 * shapes, so consecutive reads can reuse a generated savepoint while still
	 * preserving PostgreSQL failed-statement isolation.
	 *
	 * @param string $sql SQL statement.
	 * @return bool Whether this is a non-locking SELECT statement.
	 */
	private function is_postgresql_shared_read_savepoint_statement( string $sql ): bool {
		return 1 === preg_match(
			'/^\s*SELECT\b(?![\s\S]*(?:\bFOR\s+(?:KEY\s+SHARE|NO\s+KEY\s+UPDATE|SHARE|UPDATE)\b|\bLOCK\s+IN\s+SHARE\s+MODE\b|\bINTO\b))/i',
			$sql
		);
	}

	/**
	 * Get or create the active generated read savepoint.
	 *
	 * @return string Savepoint name.
	 */
	private function get_or_create_active_read_savepoint_name(): string {
		if ( null === $this->active_read_savepoint ) {
			++$this->savepoint_counter;
			$this->active_read_savepoint                = 'wp_statement_' . $this->savepoint_counter;
			$this->active_read_savepoint_needs_creation = true;
		}

		return $this->active_read_savepoint;
	}

	/**
	 * Ensure a generated savepoint exists in PostgreSQL.
	 *
	 * @param string $savepoint Savepoint name.
	 */
	private function ensure_statement_savepoint_exists( string $savepoint ): void {
		if ( $savepoint === $this->active_read_savepoint && ! $this->active_read_savepoint_needs_creation ) {
			return;
		}

		$this->pdo->exec( 'SAVEPOINT ' . $savepoint );

		if ( $savepoint === $this->active_read_savepoint ) {
			$this->active_read_savepoint_needs_creation = false;
		}
	}

	/**
	 * Consume the active generated read savepoint before unguarded PDO execution.
	 */
	private function consume_active_read_savepoint(): void {
		if ( null === $this->active_read_savepoint ) {
			return;
		}

		$savepoint = $this->active_read_savepoint;
		if ( ! $this->active_read_savepoint_needs_creation ) {
			$this->release_statement_savepoint( $savepoint );
		}

		$this->reset_statement_savepoint_state();
	}

	/**
	 * Release a generated statement savepoint.
	 *
	 * @param string $savepoint Savepoint name.
	 */
	private function release_statement_savepoint( string $savepoint ): void {
		$this->pdo->exec( 'RELEASE SAVEPOINT ' . $savepoint );
	}

	/**
	 * Reset cached read savepoint state when SQL controls the transaction.
	 *
	 * @param string $sql SQL statement.
	 */
	private function maybe_reset_read_savepoint_after_transaction_control( string $sql ): void {
		if ( $this->is_postgresql_transaction_control_statement( $sql ) ) {
			$this->reset_statement_savepoint_state();
		}
	}

	/**
	 * Roll back and release a generated statement savepoint.
	 *
	 * @param string $savepoint Savepoint name.
	 */
	private function rollback_statement_savepoint( string $savepoint ): void {
		try {
			if ( $savepoint === $this->active_read_savepoint ) {
				$this->reset_statement_savepoint_state();
			}
			$this->pdo->exec( 'ROLLBACK TO SAVEPOINT ' . $savepoint );
			$this->pdo->exec( 'RELEASE SAVEPOINT ' . $savepoint );
		} catch ( Throwable $rollback_exception ) {
			return;
		}
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
	 * Encode MySQL text bytes that PostgreSQL text cannot store directly.
	 *
	 * @param string $value MySQL text value.
	 * @return string PostgreSQL-safe text value.
	 */
	private static function encode_mysql_text_for_postgresql( string $value ): string {
		if ( false === strpos( $value, "\0" ) && ! self::starts_with_mysql_text_encoding_prefix( $value ) ) {
			return $value;
		}

		return self::MYSQL_TEXT_ENCODING_PREFIX
			. strlen( $value )
			. ':'
			. hash( 'sha256', self::MYSQL_TEXT_ENCODING_HASH_CONTEXT . $value )
			. ':'
			. bin2hex( $value );
	}

	/**
	 * Check whether a value starts with the MySQL text encoding prefix.
	 *
	 * @param string $value String value.
	 * @return bool Whether the value starts with the encoding prefix.
	 */
	private static function starts_with_mysql_text_encoding_prefix( string $value ): bool {
		return 0 === strpos( $value, self::MYSQL_TEXT_ENCODING_PREFIX );
	}

	/**
	 * Check whether a PostgreSQL string value needs E'' syntax.
	 *
	 * @param string $value String value.
	 * @return bool Whether the value contains escape-string bytes.
	 */
	private static function requires_postgresql_escape_string_syntax( string $value ): bool {
		return 1 === preg_match( '/[\x01-\x1F\\\\]/', $value );
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
		$escaped = '';
		$length  = strlen( $value );
		for ( $i = 0; $i < $length; $i++ ) {
			$byte = $value[ $i ];
			if ( '\\' === $byte ) {
				$escaped .= '\\\\';
			} elseif ( "'" === $byte ) {
				$escaped .= "''";
			} elseif ( "\n" === $byte ) {
				$escaped .= '\\n';
			} elseif ( "\r" === $byte ) {
				$escaped .= '\\r';
			} elseif ( "\t" === $byte ) {
				$escaped .= '\\t';
			} elseif ( ord( $byte ) < 32 ) {
				$escaped .= sprintf( '\\%03o', ord( $byte ) );
			} else {
				$escaped .= $byte;
			}
		}

		return "E'" . $escaped . "'";
	}
}
