<?php

/*
 * The statement class extends PDOStatement. Enable PDO function calls:
 *   phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 *   phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDOStatement
 *   phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
 *
 * Some PDOStatement methods use reserved keywords as parameter names:
 *   phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames.varFound
 *   phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames.classFound
 */

/**
 * A PDOStatement-compatible statement for the pure-PHP database engine.
 *
 * Statements are created by WP_PHP_Engine_PDO::prepare() and execute
 * against the engine, buffering the full result set in memory.
 */
class WP_PHP_Engine_PDO_Statement extends PDOStatement {
	/**
	 * The PDO facade that created this statement.
	 *
	 * @var WP_PHP_Engine_PDO
	 */
	private $pdo;

	/**
	 * The engine instance.
	 *
	 * @var WP_PHP_Engine
	 */
	private $engine;

	/**
	 * The SQL string.
	 *
	 * Note: PDOStatement::$queryString is read-only on newer PHP versions,
	 * so this driver keeps the SQL in its own property.
	 *
	 * @var string
	 */
	private $sql;

	/**
	 * Parameters bound with bindValue/bindParam.
	 *
	 * @var array
	 */
	private $bound_params = array();

	/**
	 * The result column names.
	 *
	 * @var array
	 */
	private $cols = array();

	/**
	 * The result declared column types.
	 *
	 * @var array
	 */
	private $decl = array();

	/**
	 * The result source table names (per column, null for expressions).
	 *
	 * @var array
	 */
	private $tables = array();

	/**
	 * The result rows (lists of values).
	 *
	 * @var array
	 */
	private $rows = array();

	/**
	 * The number of rows affected by the statement.
	 *
	 * @var int
	 */
	private $affected_rows = 0;

	/**
	 * The fetch cursor position.
	 *
	 * @var int
	 */
	private $cursor = 0;

	/**
	 * The fetch mode set with setFetchMode, or null when not set.
	 *
	 * @var int|null
	 */
	private $fetch_mode;

	/**
	 * Additional fetch mode arguments (e.g. a class name).
	 *
	 * @var array
	 */
	private $fetch_mode_args = array();

	/**
	 * Constructor.
	 *
	 * @param WP_PHP_Engine_PDO $pdo    The PDO facade.
	 * @param WP_PHP_Engine     $engine The engine.
	 * @param string            $query  The SQL string.
	 */
	public function __construct( $pdo, $engine, $query ) {
		$this->pdo    = $pdo;
		$this->engine = $engine;
		$this->sql    = $query;
	}

	/**
	 * Resolve the effective fetch mode.
	 *
	 * @param  int|null $mode The requested mode (null or 0 for the default).
	 * @return int            The effective mode.
	 */
	private function resolve_fetch_mode( $mode ) {
		if ( null !== $mode && 0 !== $mode ) {
			return $mode;
		}
		if ( null !== $this->fetch_mode ) {
			return $this->fetch_mode;
		}
		$default = $this->pdo->getAttribute( PDO::ATTR_DEFAULT_FETCH_MODE );
		if ( null !== $default && 0 !== $default ) {
			return $default;
		}
		return PDO::FETCH_BOTH;
	}

	/**
	 * Bind a value to a parameter.
	 *
	 * @param  mixed $param The parameter identifier (1-based int or name).
	 * @param  mixed $value The value.
	 * @param  int   $type  The parameter type.
	 * @return bool
	 */
	#[\ReturnTypeWillChange]
	public function bindValue( $param, $value, $type = PDO::PARAM_STR ) {
		if ( PDO::PARAM_BOOL === $type ) {
			$value = $value ? 1 : 0;
		} elseif ( PDO::PARAM_NULL === $type ) {
			$value = null;
		} elseif ( PDO::PARAM_INT === $type && null !== $value ) {
			$value = (int) $value;
		}
		if ( is_int( $param ) ) {
			$this->bound_params[ $param - 1 ] = $value;
		} else {
			$this->bound_params[ ltrim( (string) $param, ':' ) ] = $value;
		}
		return true;
	}

	/**
	 * Bind a variable to a parameter (bound by value at execute time here).
	 *
	 * @param  mixed $param          The parameter identifier.
	 * @param  mixed $var            The variable.
	 * @param  int   $type           The parameter type.
	 * @param  int   $max_length     Ignored.
	 * @param  mixed $driver_options Ignored.
	 * @return bool
	 */
	#[\ReturnTypeWillChange]
	public function bindParam( $param, &$var, $type = PDO::PARAM_STR, $max_length = 0, $driver_options = null ) {
		return $this->bindValue( $param, $var, $type );
	}

	/**
	 * Execute the statement.
	 *
	 * @param  array|null $params Bound parameter values.
	 * @return bool
	 */
	#[\ReturnTypeWillChange]
	public function execute( $params = null ) {
		$bound = null !== $params ? $this->normalize_execute_params( $params ) : $this->bound_params;
		try {
			$result = $this->engine->execute( $this->sql, $bound );
		} catch ( PDOException $e ) {
			return false !== $this->pdo->handle_error( $e ) ? true : false;
		}
		$this->cols          = $result['cols'];
		$this->decl          = $result['decl'];
		$this->tables        = isset( $result['srctable'] ) ? $result['srctable'] : array();
		$this->rows          = $result['rows'];
		$this->affected_rows = isset( $result['changes'] ) ? $result['changes'] : 0;
		$this->cursor        = 0;
		return true;
	}

	/**
	 * Normalize execute() parameters to the engine's expectations.
	 *
	 * @param  array $params The parameters.
	 * @return array         The normalized parameters.
	 */
	private function normalize_execute_params( $params ) {
		$normalized = array();
		$position   = 0;
		foreach ( $params as $key => $value ) {
			if ( is_int( $key ) ) {
				// PDO accepts both 0-based lists and 1-based maps.
				$normalized[ $position ] = $value;
				$position               += 1;
			} else {
				$normalized[ ltrim( (string) $key, ':' ) ] = $value;
			}
		}
		return $normalized;
	}

	/**
	 * Get the number of rows affected by the statement.
	 *
	 * @return int
	 */
	#[\ReturnTypeWillChange]
	public function rowCount() {
		return $this->affected_rows;
	}

	/**
	 * Get the number of columns in the result set.
	 *
	 * @return int
	 */
	#[\ReturnTypeWillChange]
	public function columnCount() {
		return count( $this->cols );
	}

	/**
	 * Get metadata for a result column.
	 *
	 * @param  int $column The 0-based column index.
	 * @return array|false
	 */
	#[\ReturnTypeWillChange]
	public function getColumnMeta( $column ) {
		if ( ! isset( $this->cols[ $column ] ) ) {
			return false;
		}
		// Infer the native type from the first non-null value in the column.
		$native_type = 'null';
		foreach ( $this->rows as $row ) {
			$value = isset( $row[ $column ] ) ? $row[ $column ] : null;
			if ( null !== $value ) {
				if ( is_int( $value ) ) {
					$native_type = 'integer';
				} elseif ( is_float( $value ) ) {
					$native_type = 'double';
				} else {
					// PDO SQLite reports both TEXT and BLOB as "string".
					$native_type = 'string';
				}
				break;
			}
		}
		$meta = array(
			'native_type' => $native_type,
			'flags'       => array(),
			'name'        => $this->cols[ $column ],
			'len'         => -1,
			'precision'   => 0,
			'pdo_type'    => PDO::PARAM_STR,
		);
		if ( isset( $this->tables[ $column ] ) && null !== $this->tables[ $column ] ) {
			$meta['table'] = $this->tables[ $column ];
		}
		if ( isset( $this->decl[ $column ] ) && null !== $this->decl[ $column ] ) {
			$meta['sqlite:decl_type'] = $this->decl[ $column ];
		}
		return $meta;
	}

	/**
	 * Set the default fetch mode.
	 *
	 * @param  int   $mode    The fetch mode.
	 * @param  mixed ...$args Additional arguments.
	 * @return bool
	 */
	#[\ReturnTypeWillChange]
	public function setFetchMode( $mode, ...$args ) {
		$this->fetch_mode      = $mode;
		$this->fetch_mode_args = $args;
		return true;
	}

	/**
	 * Fetch the next row.
	 *
	 * @param  int|null $mode               The fetch mode.
	 * @param  int      $cursor_orientation Ignored.
	 * @param  int      $cursor_offset      Ignored.
	 * @return mixed
	 */
	#[\ReturnTypeWillChange]
	public function fetch( $mode = null, $cursor_orientation = PDO::FETCH_ORI_NEXT, $cursor_offset = 0 ) {
		if ( ! isset( $this->rows[ $this->cursor ] ) ) {
			return false;
		}
		$row           = $this->rows[ $this->cursor ];
		$this->cursor += 1;
		return $this->format_row( $row, $this->resolve_fetch_mode( $mode ) );
	}

	/**
	 * Fetch a single column from the next row.
	 *
	 * @param  int $column The 0-based column index.
	 * @return mixed
	 */
	#[\ReturnTypeWillChange]
	public function fetchColumn( $column = 0 ) {
		if ( ! isset( $this->rows[ $this->cursor ] ) ) {
			return false;
		}
		$row           = $this->rows[ $this->cursor ];
		$this->cursor += 1;
		$value         = isset( $row[ $column ] ) || array_key_exists( $column, $row ) ? $row[ $column ] : null;
		return $this->stringify( $value );
	}

	/**
	 * Fetch all remaining rows.
	 *
	 * @param  int|null $mode    The fetch mode.
	 * @param  mixed    ...$args Additional arguments.
	 * @return array
	 */
	#[\ReturnTypeWillChange]
	public function fetchAll( $mode = null, ...$args ) {
		$use_mode = $this->resolve_fetch_mode( $mode );

		if ( PDO::FETCH_COLUMN === $use_mode ) {
			$column = isset( $args[0] ) ? $args[0] : 0;
			$result = array();
			while ( isset( $this->rows[ $this->cursor ] ) ) {
				$row           = $this->rows[ $this->cursor ];
				$this->cursor += 1;
				$result[]      = $this->stringify( isset( $row[ $column ] ) || array_key_exists( $column, $row ) ? $row[ $column ] : null );
			}
			return $result;
		}

		if ( PDO::FETCH_KEY_PAIR === $use_mode ) {
			$result = array();
			while ( isset( $this->rows[ $this->cursor ] ) ) {
				$row                                   = $this->rows[ $this->cursor ];
				$this->cursor                         += 1;
				$result[ $this->stringify( $row[0] ) ] = $this->stringify( isset( $row[1] ) ? $row[1] : null );
			}
			return $result;
		}

		$result = array();
		while ( isset( $this->rows[ $this->cursor ] ) ) {
			$row           = $this->rows[ $this->cursor ];
			$this->cursor += 1;
			$result[]      = $this->format_row( $row, $use_mode, $args );
		}
		return $result;
	}

	/**
	 * Fetch the next row as an object.
	 *
	 * @param  string|null $class            The class name.
	 * @param  array       $constructor_args The constructor arguments.
	 * @return object|false
	 */
	#[\ReturnTypeWillChange]
	public function fetchObject( $class = 'stdClass', $constructor_args = array() ) {
		$row = $this->fetch( PDO::FETCH_ASSOC );
		if ( false === $row ) {
			return false;
		}
		if ( 'stdClass' === $class || null === $class ) {
			return (object) $row;
		}
		$object = empty( $constructor_args )
			? new $class()
			: ( new ReflectionClass( $class ) )->newInstanceArgs( $constructor_args );
		foreach ( $row as $key => $value ) {
			$object->$key = $value;
		}
		return $object;
	}

	/**
	 * Format a row according to a fetch mode.
	 *
	 * @param  array $row  The raw row (list of values).
	 * @param  int   $mode The fetch mode.
	 * @param  array $args Additional fetch mode arguments.
	 * @return mixed       The formatted row.
	 */
	private function format_row( $row, $mode, $args = array() ) {
		// Strip flags we do not implement.
		$mode = $mode & ~PDO::FETCH_SERIALIZE & ~PDO::FETCH_PROPS_LATE;

		switch ( $mode ) {
			case PDO::FETCH_NUM:
				$result = array();
				foreach ( $row as $value ) {
					$result[] = $this->stringify( $value );
				}
				return $result;

			case PDO::FETCH_ASSOC:
				return $this->assoc_row( $row );

			case PDO::FETCH_NAMED:
				// Duplicate column names are grouped into arrays.
				$result = array();
				foreach ( $row as $index => $value ) {
					$name  = isset( $this->cols[ $index ] ) ? $this->cols[ $index ] : (string) $index;
					$value = $this->stringify( $value );
					if ( ! isset( $result[ $name ] ) && ! array_key_exists( $name, $result ) ) {
						$result[ $name ] = $value;
					} elseif ( is_array( $result[ $name ] ) ) {
						$result[ $name ][] = $value;
					} else {
						$result[ $name ] = array( $result[ $name ], $value );
					}
				}
				return $result;

			case PDO::FETCH_OBJ:
				return (object) $this->assoc_row( $row );

			case PDO::FETCH_CLASS:
				$class = ! empty( $args ) ? $args[0] : ( ! empty( $this->fetch_mode_args ) ? $this->fetch_mode_args[0] : 'stdClass' );
				$assoc = $this->assoc_row( $row );
				if ( 'stdClass' === $class ) {
					return (object) $assoc;
				}
				$object = new $class();
				foreach ( $assoc as $key => $value ) {
					$object->$key = $value;
				}
				return $object;

			case PDO::FETCH_KEY_PAIR:
				return array( $this->stringify( $row[0] ) => $this->stringify( isset( $row[1] ) ? $row[1] : null ) );

			case PDO::FETCH_COLUMN:
				$column = ! empty( $args ) ? $args[0] : 0;
				return $this->stringify( isset( $row[ $column ] ) ? $row[ $column ] : null );

			case PDO::FETCH_BOTH:
			default:
				// PDO sets the column name key first, then adds the numeric
				// index only when no such key exists yet (numeric column
				// names can collide with the indexes of other columns).
				$result = array();
				foreach ( $row as $index => $value ) {
					$value = $this->stringify( $value );
					if ( isset( $this->cols[ $index ] ) ) {
						$result[ $this->cols[ $index ] ] = $value;
					}
					if ( ! isset( $result[ $index ] ) && ! array_key_exists( $index, $result ) ) {
						$result[ $index ] = $value;
					}
				}
				return $result;
		}
	}

	/**
	 * Build an associative row, de-duplicating column names like PDO
	 * (later columns win).
	 *
	 * @param  array $row The raw row.
	 * @return array      The associative row.
	 */
	private function assoc_row( $row ) {
		$result = array();
		foreach ( $row as $index => $value ) {
			$name            = isset( $this->cols[ $index ] ) ? $this->cols[ $index ] : (string) $index;
			$result[ $name ] = $this->stringify( $value );
		}
		return $result;
	}

	/**
	 * Apply PDO::ATTR_STRINGIFY_FETCHES to a value.
	 *
	 * @param  mixed $value The value.
	 * @return mixed        The (possibly stringified) value.
	 */
	private function stringify( $value ) {
		if ( null === $value ) {
			return null;
		}
		if ( $value instanceof WP_PHP_Engine_Blob ) {
			return $value->bytes;
		}
		if ( ( is_int( $value ) || is_float( $value ) ) && $this->pdo->getAttribute( PDO::ATTR_STRINGIFY_FETCHES ) ) {
			// PDO stringifies fetches using PHP value-to-string semantics
			// (e.g. 0.0 becomes "0", not "0.0") on PHP 8.1+.
			return (string) $value;
		}
		return $value;
	}
}
