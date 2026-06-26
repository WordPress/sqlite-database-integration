<?php declare(strict_types = 1);

/**
 * DuckDB connection wrapper.
 */
class WP_DuckDB_Connection {
	/**
	 * @var object
	 */
	private $duckdb;

	/**
	 * @var callable(string,array):void|null
	 */
	private $query_logger;

	/**
	 * Constructor.
	 *
	 * @param array $options Connection options.
	 *
	 * @throws InvalidArgumentException When options are invalid.
	 * @throws WP_DuckDB_Driver_Exception When DuckDB is unavailable or cannot connect.
	 */
	public function __construct( array $options = array() ) {
		if ( isset( $options['duckdb'] ) && is_object( $options['duckdb'] ) ) {
			$this->duckdb = $options['duckdb'];
			return;
		}

		WP_DuckDB_Runtime::assert_available();

		$path = $options['path'] ?? null;
		if ( ':memory:' === $path ) {
			$path = null;
		}
		if ( null !== $path && ! is_string( $path ) ) {
			throw new InvalidArgumentException( 'DuckDB option "path" must be a string or null.' );
		}

		try {
			$client_class = WP_DuckDB_Runtime::CLIENT_CLASS;
			$this->duckdb = $client_class::create( $path );
		} catch ( Throwable $e ) {
			throw new WP_DuckDB_Driver_Exception( 'Failed to open DuckDB database: ' . $e->getMessage(), 0, $e );
		}
	}

	/**
	 * Execute a DuckDB query.
	 *
	 * @param string $sql    SQL query.
	 * @param array  $params Optional parameters.
	 * @return WP_DuckDB_Result_Statement
	 *
	 * @throws WP_DuckDB_Driver_Exception When execution fails.
	 */
	public function query( string $sql, array $params = array() ): WP_DuckDB_Result_Statement {
		if ( $this->query_logger ) {
			( $this->query_logger )( $sql, $params );
		}

		if ( count( $params ) > 0 ) {
			return $this->prepare( $sql )->execute( $params );
		}

		try {
			return $this->create_statement_from_result( $this->duckdb->query( $sql ) );
		} catch ( Throwable $e ) {
			throw new WP_DuckDB_Driver_Exception( 'DuckDB query failed: ' . $e->getMessage(), 0, $e );
		}
	}

	/**
	 * Prepare a DuckDB query.
	 *
	 * @param string $sql SQL query.
	 * @return WP_DuckDB_Prepared_Statement
	 *
	 * @throws WP_DuckDB_Driver_Exception When preparation fails.
	 */
	public function prepare( string $sql ): WP_DuckDB_Prepared_Statement {
		if ( $this->query_logger ) {
			( $this->query_logger )( $sql, array() );
		}

		try {
			return new WP_DuckDB_Prepared_Statement( $this, $this->duckdb->preparedStatement( $sql ) );
		} catch ( Throwable $e ) {
			throw new WP_DuckDB_Driver_Exception( 'Failed to prepare DuckDB query: ' . $e->getMessage(), 0, $e );
		}
	}

	/**
	 * Create a statement wrapper from a DuckDB PHP ResultSet.
	 *
	 * @param object $result DuckDB PHP ResultSet.
	 * @return WP_DuckDB_Result_Statement
	 */
	public function create_statement_from_result( $result ): WP_DuckDB_Result_Statement {
		$columns = iterator_to_array( $result->columnNames() );
		$columns = array_values( $columns );
		$rows    = array();

		foreach ( $result->rows( true ) as $row ) {
			$rows[] = $this->normalize_row( $columns, $row );
		}

		$affected_rows = 0;
		if ( array( 'Count' ) === $columns ) {
			$affected_rows = isset( $rows[0][0] ) ? (int) $rows[0][0] : 0;
			return new WP_DuckDB_Result_Statement( array(), array(), $affected_rows );
		}
		if ( array( 'Success' ) === $columns ) {
			return new WP_DuckDB_Result_Statement( array(), array(), 0 );
		}

		return new WP_DuckDB_Result_Statement( $columns, $rows, $affected_rows );
	}

	/**
	 * Quote a value for SQL.
	 *
	 * @param mixed $value Value to quote.
	 * @return string
	 */
	public function quote( $value ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		return "'" . str_replace( "'", "''", (string) $value ) . "'";
	}

	/**
	 * Quote a DuckDB identifier.
	 *
	 * @param string $unquoted_identifier Unquoted identifier.
	 * @return string
	 *
	 * @throws InvalidArgumentException When the identifier contains a NUL byte.
	 */
	public function quote_identifier( string $unquoted_identifier ): string {
		if ( false !== strpos( $unquoted_identifier, "\0" ) ) {
			throw new InvalidArgumentException( 'DuckDB identifiers cannot contain NUL bytes.' );
		}
		return '"' . str_replace( '"', '""', $unquoted_identifier ) . '"';
	}

	/**
	 * Set a query logger.
	 *
	 * @param callable(string,array):void $logger Query logger.
	 */
	public function set_query_logger( callable $logger ): void {
		$this->query_logger = $logger;
	}

	/**
	 * Get the raw DuckDB PHP client object.
	 *
	 * @return object
	 */
	public function get_client() {
		return $this->duckdb;
	}

	/**
	 * Normalize a DuckDB PHP row into column order.
	 *
	 * @param string[] $columns Column names.
	 * @param array    $row     DuckDB row keyed by column name.
	 * @return array
	 */
	private function normalize_row( array $columns, array $row ): array {
		$normalized = array();
		foreach ( $columns as $column ) {
			$normalized[] = $this->normalize_value( $row[ $column ] ?? null );
		}
		return $normalized;
	}

	/**
	 * Normalize DuckDB PHP typed values to scalar values where possible.
	 *
	 * @param mixed $value DuckDB PHP value.
	 * @return mixed
	 */
	private function normalize_value( $value ) {
		if ( is_object( $value ) ) {
			if ( method_exists( $value, 'data' ) ) {
				return $value->data();
			}
			if ( method_exists( $value, '__toString' ) ) {
				return (string) $value;
			}
		}
		return $value;
	}
}
