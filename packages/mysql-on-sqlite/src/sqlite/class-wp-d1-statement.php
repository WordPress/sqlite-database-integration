<?php declare(strict_types = 1);

/**
 * Minimal statement wrapper for D1 results.
 */
class WP_D1_Statement {
	/**
	 * Connection.
	 *
	 * @var WP_D1_Connection
	 */
	private $connection;

	/**
	 * SQL.
	 *
	 * @var string
	 */
	private $sql;

	/**
	 * Parameters.
	 *
	 * @var array
	 */
	private $params;

	/**
	 * Cached result. Null indicates that execution has not been attempted.
	 *
	 * @var array|null
	 */
	private $result = null;

	/**
	 * Position of the next unread row.
	 *
	 * @var int
	 */
	private $row_position = 0;

	/**
	 * Constructor.
	 */
	public function __construct( WP_D1_Connection $connection, string $sql, array $params = array() ) {
		$this->connection = $connection;
		$this->sql        = $sql;
		$this->params     = array_values( $params );
	}

	/**
	 * Bind a value (positional).
	 *
	 * @param int|string $key   One-based parameter position.
	 * @param mixed      $value Value.
	 * @param int|null   $type  PDO::PARAM_NULL, or null to infer the type from the value.
	 *
	 * @return bool
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Match the PDOStatement API.
	public function bindValue( $key, $value, $type = null ): bool {
		if ( is_string( $key ) && (string) (int) $key === $key ) {
			$key = (int) $key;
		}
		if ( ! is_int( $key ) || $key < 1 ) {
			throw new InvalidArgumentException( 'D1 bindings require a positive parameter position.' );
		}
		if ( null !== $type && PDO::PARAM_NULL !== $type ) {
			throw new InvalidArgumentException( 'D1 bindings support inferred types and PDO::PARAM_NULL.' );
		}
		$this->params[ $key - 1 ] = PDO::PARAM_NULL === $type ? null : $value;
		return true;
	}

	/**
	 * Bind (alias).
	 */
	public function bind( $key, $value ) {
		$this->bindValue( $key, $value );
		return $this;
	}

	/**
	 * Execute.
	 *
	 * @param array|null $params Params.
	 *
	 * @return bool
	 */
	public function execute( $params = null ): bool {
		$this->result       = array();
		$this->row_position = 0;

		if ( is_array( $params ) ) {
			$this->params = array_values( $params );
		}
		ksort( $this->params, SORT_NUMERIC );
		$values = array_values( $this->params );
		if ( array_keys( $this->params ) !== array_keys( $values ) ) {
			throw new InvalidArgumentException( 'D1 bindings must use consecutive parameter positions.' );
		}
		$this->result = $this->connection->execute_remote( $this->sql, $values );
		return true;
	}

	/**
	 * Fetch all remaining rows.
	 *
	 * @param int $mode 0 for the default, PDO::FETCH_ASSOC, or PDO::FETCH_COLUMN.
	 *
	 * @return array
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Match the PDOStatement API.
	public function fetchAll( $mode = 0 ): array {
		if ( 0 !== $mode && PDO::FETCH_ASSOC !== $mode && PDO::FETCH_COLUMN !== $mode ) {
			throw new InvalidArgumentException( 'D1 fetch modes are 0, PDO::FETCH_ASSOC, and PDO::FETCH_COLUMN.' );
		}

		$rows               = $this->get_rows();
		$remaining          = array_slice( $rows, $this->row_position );
		$this->row_position = count( $rows );
		if ( PDO::FETCH_COLUMN === $mode ) {
			return array_map(
				static function ( array $row ) {
					return reset( $row );
				},
				$remaining
			);
		}
		return $remaining;
	}

	/**
	 * Fetch a column from the next row.
	 *
	 * @param int $column Column index.
	 *
	 * @return mixed
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Match the PDOStatement API.
	public function fetchColumn( $column = 0 ) {
		$rows = $this->get_rows();
		if ( ! isset( $rows[ $this->row_position ] ) ) {
			return false;
		}
		$row = array_values( $rows[ $this->row_position++ ] );
		return $row[ $column ] ?? null;
	}

	/**
	 * Row count.
	 *
	 * @return int
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Match the PDOStatement API.
	public function rowCount(): int {
		if ( null === $this->result ) {
			return 0;
		}
		return (int) ( $this->result['meta']['changes'] ?? 0 );
	}

	/**
	 * Column count.
	 *
	 * @return int
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Match the PDOStatement API.
	public function columnCount(): int {
		$rows = $this->result['results'] ?? array();
		return $this->result['column_count'] ?? ( isset( $rows[0] ) ? count( $rows[0] ) : 0 );
	}

	/**
	 * Get the result rows, executing the statement if necessary.
	 *
	 * @return array
	 */
	private function get_rows(): array {
		if ( null === $this->result ) {
			$this->execute();
		}
		return $this->result['results'] ?? array();
	}
}
