<?php declare(strict_types = 1);

/*
 * This statement intentionally provides a small PDOStatement-like surface over
 * materialized DuckDB rows. It does not extend PDOStatement because DuckDB PHP
 * is not a PDO driver.
 *
 * The statement mirrors PDO fetch mode constants without opening a database:
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 *
 * PDO uses $class as a parameter name:
 * phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames.classFound
 */

/**
 * PDOStatement-like result wrapper for DuckDB results.
 */
class WP_DuckDB_Result_Statement implements IteratorAggregate {
	/**
	 * @var string[]
	 */
	private $columns;

	/**
	 * @var array<int,array<string,mixed>>
	 */
	private $column_meta = array();

	/**
	 * @var array<int,array<int,mixed>>
	 */
	private $rows;

	/**
	 * @var int
	 */
	private $affected_rows;

	/**
	 * @var int
	 */
	private $cursor = 0;

	/**
	 * @var int
	 */
	private $default_fetch_mode = PDO::FETCH_BOTH;

	/**
	 * @var array<int,mixed>
	 */
	private $default_fetch_args = array();

	/**
	 * @param string[]                         $columns       Column names.
	 * @param array<int,array<mixed>>           $rows          Numeric rows.
	 * @param int                              $affected_rows Affected row count.
	 * @param array<int,array<string,mixed>>    $column_meta   Optional column metadata.
	 */
	public function __construct( array $columns, array $rows, int $affected_rows = 0, array $column_meta = array() ) {
		$this->columns       = array_values( $columns );
		$this->rows          = array_values(
			array_map(
				function ( array $row ): array {
					return array_values( $row );
				},
				$rows
			)
		);
		$this->affected_rows = $affected_rows;
		$this->setColumnMeta( $column_meta );
	}

	/**
	 * Set column metadata.
	 *
	 * @param array<int,array<string,mixed>> $column_meta Column metadata keyed by zero-based column offset.
	 */
	public function setColumnMeta( array $column_meta ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$this->column_meta = array();
		foreach ( $this->columns as $index => $name ) {
			$meta = isset( $column_meta[ $index ] ) && is_array( $column_meta[ $index ] )
				? $column_meta[ $index ]
				: array();
			if ( ! isset( $meta['name'] ) ) {
				$meta['name'] = $name;
			}
			$this->column_meta[ $index ] = $meta;
		}
	}

	/**
	 * Set the default fetch mode.
	 *
	 * @param int   $mode Fetch mode.
	 * @param mixed ...$args Additional fetch-mode arguments.
	 * @return bool
	 */
	public function setFetchMode( $mode, ...$args ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$mode = (int) $mode;
		if ( defined( 'PDO::FETCH_DEFAULT' ) && PDO::FETCH_DEFAULT === $mode ) {
			$mode = PDO::FETCH_BOTH;
		}

		$this->default_fetch_mode = $mode;
		$this->default_fetch_args = $args;
		return true;
	}

	/**
	 * Get the number of columns.
	 *
	 * @return int
	 */
	public function columnCount(): int { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return count( $this->columns );
	}

	/**
	 * Get affected rows.
	 *
	 * @return int
	 */
	public function rowCount(): int { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return $this->affected_rows;
	}

	/**
	 * Fetch the next row.
	 *
	 * @param int|null $mode Fetch mode.
	 * @return mixed
	 */
	public function fetch( $mode = null ) {
		if ( ! isset( $this->rows[ $this->cursor ] ) ) {
			return false;
		}

		$row = $this->rows[ $this->cursor ];
		++$this->cursor;

		$fetch_mode = $mode ?? $this->default_fetch_mode;
		$fetch_args = null === $mode ? $this->default_fetch_args : array();

		return $this->format_row( $row, $fetch_mode, $fetch_args );
	}

	/**
	 * Fetch all remaining rows.
	 *
	 * @param int|null $mode Fetch mode.
	 * @param mixed    ...$args Additional fetch-mode arguments.
	 * @return array
	 */
	public function fetchAll( $mode = null, ...$args ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$fetch_mode = $mode ?? $this->default_fetch_mode;
		if ( defined( 'PDO::FETCH_DEFAULT' ) && PDO::FETCH_DEFAULT === $fetch_mode ) {
			$fetch_mode = $this->default_fetch_mode;
		}
		$fetch_args = null === $mode ? $this->default_fetch_args : $args;

		if ( PDO::FETCH_COLUMN === $fetch_mode ) {
			$column = isset( $fetch_args[0] ) ? (int) $fetch_args[0] : 0;
			$this->assert_valid_column_index( $column );

			$rows = array();
			while ( isset( $this->rows[ $this->cursor ] ) ) {
				$rows[] = $this->fetchColumn( $column );
			}
			return $rows;
		}

		if ( PDO::FETCH_KEY_PAIR === $fetch_mode ) {
			$this->assert_valid_column_index( 0 );
			$this->assert_valid_column_index( 1 );

			$rows = array();
			while ( isset( $this->rows[ $this->cursor ] ) ) {
				$row = $this->rows[ $this->cursor ];
				++$this->cursor;
				$rows[ $row[0] ] = $row[1];
			}
			return $rows;
		}

		$rows = array();
		while ( isset( $this->rows[ $this->cursor ] ) ) {
			$row = $this->rows[ $this->cursor ];
			++$this->cursor;
			$rows[] = $this->format_row( $row, $fetch_mode, $fetch_args );
		}
		return $rows;
	}

	/**
	 * Fetch a column from the next row.
	 *
	 * @param int $column Column index.
	 * @return mixed
	 */
	public function fetchColumn( $column = 0 ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$column = (int) $column;
		$this->assert_valid_column_index( $column );

		if ( ! isset( $this->rows[ $this->cursor ] ) ) {
			return false;
		}

		$row = $this->rows[ $this->cursor ];
		++$this->cursor;

		return array_key_exists( $column, $row ) ? $row[ $column ] : false;
	}

	/**
	 * Fetch the next row as an object.
	 *
	 * @param string $class Class name.
	 * @param array  $constructor_args Constructor arguments.
	 * @return object|false
	 */
	public function fetchObject( $class = 'stdClass', $constructor_args = array() ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$row = $this->fetch( PDO::FETCH_ASSOC );
		if ( false === $row ) {
			return false;
		}

		$object = new $class( ...$constructor_args );
		foreach ( $row as $name => $value ) {
			$object->$name = $value;
		}
		return $object;
	}

	/**
	 * Get a column metadata subset.
	 *
	 * @param int $column Column index.
	 * @return array|false
	 */
	public function getColumnMeta( $column ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		if ( ! isset( $this->columns[ $column ] ) ) {
			return false;
		}

		return $this->column_meta[ $column ];
	}

	/**
	 * Close the cursor.
	 *
	 * @return bool
	 */
	public function closeCursor(): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$this->cursor = count( $this->rows );
		return true;
	}

	/**
	 * DuckDB results do not expose multiple rowsets.
	 *
	 * @return false
	 */
	public function nextRowset(): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return false;
	}

	/**
	 * Return the statement error code.
	 *
	 * @return string
	 */
	public function errorCode(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return '00000';
	}

	/**
	 * Return the statement error info tuple.
	 *
	 * @return array{0:string,1:null,2:null}
	 */
	public function errorInfo(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return array( '00000', null, null );
	}

	/**
	 * Return rows as an iterator from the current cursor.
	 *
	 * @return Traversable
	 */
	public function getIterator(): Traversable {
		while ( false !== ( $row = $this->fetch() ) ) {
			yield $row;
		}
	}

	/**
	 * Format a row for a fetch mode.
	 *
	 * @param array            $row  Numeric row.
	 * @param int              $mode Fetch mode.
	 * @param array<int,mixed> $args Fetch mode arguments.
	 * @return mixed
	 */
	private function format_row( array $row, int $mode, array $args = array() ) {
		if ( defined( 'PDO::FETCH_DEFAULT' ) && PDO::FETCH_DEFAULT === $mode ) {
			$mode = $this->default_fetch_mode;
			$args = $this->default_fetch_args;
		}

		switch ( $mode ) {
			case PDO::FETCH_ASSOC:
				return $this->assoc_row( $row );
			case PDO::FETCH_NAMED:
				return $this->named_row( $row );
			case PDO::FETCH_NUM:
				return $row;
			case PDO::FETCH_OBJ:
				return (object) $this->assoc_row( $row );
			case PDO::FETCH_COLUMN:
				$column = isset( $args[0] ) ? (int) $args[0] : 0;
				$this->assert_valid_column_index( $column );
				return array_key_exists( $column, $row ) ? $row[ $column ] : false;
			case PDO::FETCH_CLASS:
				$class            = isset( $args[0] ) ? $args[0] : 'stdClass';
				$constructor_args = isset( $args[1] ) && is_array( $args[1] ) ? $args[1] : array();
				return $this->class_row( $row, $class, $constructor_args );
			case PDO::FETCH_FUNC:
				if ( ! isset( $args[0] ) || ! is_callable( $args[0] ) ) {
					throw new TypeError( 'PDO::FETCH_FUNC requires a callable fetch argument.' );
				}
				return call_user_func_array( $args[0], $row );
			case PDO::FETCH_BOTH:
			default:
				return $this->both_row( $row );
		}
	}

	/**
	 * Build an associative row.
	 *
	 * @param array $row Numeric row.
	 * @return array
	 */
	private function assoc_row( array $row ): array {
		$assoc = array();
		foreach ( $this->columns as $index => $name ) {
			$assoc[ $name ] = $row[ $index ] ?? null;
		}
		return $assoc;
	}

	/**
	 * Build a PDO::FETCH_NAMED row.
	 *
	 * @param array $row Numeric row.
	 * @return array
	 */
	private function named_row( array $row ): array {
		$named = array();
		foreach ( $this->columns as $index => $name ) {
			$value = $row[ $index ] ?? null;
			if ( ! array_key_exists( $name, $named ) ) {
				$named[ $name ] = $value;
			} elseif ( is_array( $named[ $name ] ) ) {
				$named[ $name ][] = $value;
			} else {
				$named[ $name ] = array( $named[ $name ], $value );
			}
		}
		return $named;
	}

	/**
	 * Build a PDO::FETCH_BOTH row.
	 *
	 * @param array $row Numeric row.
	 * @return array
	 */
	private function both_row( array $row ): array {
		$both = $this->assoc_row( $row );
		foreach ( $row as $index => $value ) {
			$both[ $index ] = $value;
		}
		return $both;
	}

	/**
	 * Build a PDO::FETCH_CLASS row.
	 *
	 * @param array            $row              Numeric row.
	 * @param string           $class            Class name.
	 * @param array<int,mixed> $constructor_args Constructor arguments.
	 * @return object
	 */
	private function class_row( array $row, string $class, array $constructor_args ): object {
		$object = new $class( ...$constructor_args );
		foreach ( $this->assoc_row( $row ) as $name => $value ) {
			$object->$name = $value;
		}
		return $object;
	}

	/**
	 * Validate a zero-based column index.
	 *
	 * @param int $column Column index.
	 */
	private function assert_valid_column_index( int $column ): void {
		if ( $column < 0 ) {
			if ( class_exists( 'ValueError' ) ) {
				throw new ValueError( 'Column index must be greater than or equal to 0' );
			}
			throw new PDOException( 'Invalid column index' );
		}

		if ( $column >= count( $this->columns ) ) {
			if ( class_exists( 'ValueError' ) ) {
				throw new ValueError( 'Invalid column index' );
			}
			throw new PDOException( 'Invalid column index' );
		}
	}
}
