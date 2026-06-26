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
		$this->default_fetch_mode = (int) $mode;
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

		return $this->format_row( $row, $mode ?? $this->default_fetch_mode );
	}

	/**
	 * Fetch all remaining rows.
	 *
	 * @param int|null $mode Fetch mode.
	 * @param mixed    ...$args Additional fetch-mode arguments.
	 * @return array
	 */
	public function fetchAll( $mode = null, ...$args ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$rows = array();
		while ( false !== ( $row = $this->fetch( $mode ) ) ) {
			$rows[] = $row;
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
		if ( ! isset( $this->rows[ $this->cursor ] ) ) {
			return false;
		}

		$row = $this->rows[ $this->cursor ];
		++$this->cursor;

		return $row[ (int) $column ] ?? false;
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
	 * @param array $row  Numeric row.
	 * @param int   $mode Fetch mode.
	 * @return mixed
	 */
	private function format_row( array $row, int $mode ) {
		if ( defined( 'PDO::FETCH_DEFAULT' ) && PDO::FETCH_DEFAULT === $mode ) {
			$mode = $this->default_fetch_mode;
		}

		switch ( $mode ) {
			case PDO::FETCH_ASSOC:
				return $this->assoc_row( $row );
			case PDO::FETCH_NUM:
				return $row;
			case PDO::FETCH_OBJ:
				return (object) $this->assoc_row( $row );
			case PDO::FETCH_COLUMN:
				return $row[0] ?? false;
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
}
