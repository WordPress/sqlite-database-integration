<?php

/**
 * PDO-like fixture that records statement savepoint commands.
 */
class WP_PostgreSQL_Connection_Statement_Savepoint_Fake_PDO {
	/**
	 * Recorded exec() SQL.
	 *
	 * @var string[]
	 */
	public $exec_sql = array();

	/**
	 * SQLite PDO used for real statement execution.
	 *
	 * @var PDO
	 */
	private $pdo;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );
	}

	/**
	 * Begin a transaction.
	 *
	 * @return bool Whether the transaction started.
	 */
	public function beginTransaction(): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return $this->pdo->beginTransaction();
	}

	/**
	 * Roll back the active transaction.
	 *
	 * @return bool Whether the transaction was rolled back.
	 */
	public function rollBack(): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return $this->pdo->rollBack();
	}

	/**
	 * Check whether a transaction is active.
	 *
	 * @return bool Whether a transaction is active.
	 */
	public function inTransaction(): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return $this->pdo->inTransaction();
	}

	/**
	 * Prepare a SQL statement.
	 *
	 * @param string $sql SQL statement.
	 * @return PDOStatement Statement object.
	 */
	public function prepare( string $sql ): PDOStatement {
		return $this->pdo->prepare( $sql );
	}

	/**
	 * Execute a SQL statement and record savepoint commands.
	 *
	 * @param string $sql SQL statement.
	 * @return int|false Affected row count, or false on failure.
	 */
	public function exec( string $sql ) {
		$this->exec_sql[] = $sql;
		return $this->pdo->exec( $sql );
	}

	/**
	 * Get PDO attributes.
	 *
	 * @param int $attribute Attribute identifier.
	 * @return mixed Attribute value.
	 */
	public function getAttribute( int $attribute ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		if ( PDO::ATTR_DRIVER_NAME === $attribute ) {
			return 'pgsql';
		}

		return $this->pdo->getAttribute( $attribute );
	}
}
