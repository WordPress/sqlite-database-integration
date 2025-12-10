<?php

/*
 * The SQLite driver uses PDO. Enable PDO function calls:
 *   phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 *   phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDOStatement
 *
 * PDO uses camel case naming, enable non-snake case:
 *   phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
 *   phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
 *
 * PDO uses $class as a variable name, enable it:
 *   phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames.classFound
 *
 * Some PDOStatement methods use $var as a variable name, enable it:
 *   phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames.varFound
 *
 * We use traits to support different PHP versions with incompatible PDO statement
 * method signatures. For that, enable multiple object structures in one file:
 *   phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
 */

/**
 * Some PDOStatement methods are not compatible across different PHP versions.
 * To address "Declaration of ... should be compatible with ..." PHP warnings,
 * we conditionally define traits with different APIs based on the PHP version.
 */
if ( PHP_VERSION_ID < 80000 ) {
	trait WP_PDO_Synthetic_Statement_PHP_Compat {
		/**
		 * Set the default fetch mode for this statement.
		 *
		 * @param  int   $mode   The fetch mode to set as the default.
		 * @param  mixed $params Additional parameters for the default fetch mode.
		 * @return bool          True on success, false on failure.
		 */
		public function setFetchMode( $mode, $params = null ): bool {
			throw new RuntimeException( 'Not implemented' );
		}

		/**
		 * Fetch all remaining rows from the result set.
		 *
		 * @param  int   $mode             The fetch mode to use.
		 * @param  mixed $class_name       With PDO::FETCH_CLASS, the name of the class to instantiate.
		 * @param  mixed $constructor_args With PDO::FETCH_CLASS, the parameters to pass to the class constructor.
		 * @return array                   The result set as an array of rows.
		 */
		public function fetchAll( $mode = null, $class_name = null, $constructor_args = null ): array {
			return $this->fetchAllRows( $mode, $class_name, $constructor_args );
		}
	}
} else {
	trait WP_PDO_Synthetic_Statement_PHP_Compat {
		/**
		 * Set the default fetch mode for this statement.
		 *
		 * @param  int   $mode   The fetch mode to set as the default.
		 * @param  mixed $args   Additional parameters for the default fetch mode.
		 * @return bool          True on success, false on failure.
		 */
		#[ReturnTypeWillChange]
		public function setFetchMode( $mode, ...$args ): bool {
			$this->fetch_mode = $mode;
			return true;
		}

		/**
		 * Fetch all remaining rows from the result set.
		 *
		 * @param  int   $mode The fetch mode to use.
		 * @param  mixed $args Additional parameters for the fetch mode.
		 * @return array       The result set as an array of rows.
		 */
		public function fetchAll( $mode = PDO::FETCH_DEFAULT, ...$args ): array {
			return $this->fetchAllRows( $mode, ...$args );
		}
	}
}

/**
 * PDOStatement implementation that operates on in-memory data.
 *
 * This class implements a complete PDOStatement interface on top of PHP arrays.
 * It is used for result sets that are composed or transformed in the PHP layer.
 */
class WP_PDO_Synthetic_Statement extends PDOStatement {
	use WP_PDO_Synthetic_Statement_PHP_Compat;

	/**
	 * Execute a prepared statement.
	 *
	 * @param mixed $params The values to bind to the parameters of the prepared statement.
	 * @return bool         True on success, false on failure.
	 */
	public function execute( $params = null ): bool {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Get the number of columns in the result set.
	 *
	 * @return int The number of columns in the result set.
	 */
	public function columnCount(): int {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Get the number of rows affected by the statement.
	 *
	 * @return int The number of rows affected by the statement.
	 */
	public function rowCount(): int {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Fetch the next row from the result set.
	 *
	 * @param  int|null $mode              The fetch mode. Controls how the row is returned.
	 *                                     Default: PDO::FETCH_DEFAULT (null for PHP < 8.0)
	 * @param  int|null $cursorOrientation The cursor orientation. Controls which row is returned.
	 *                                     Default: PDO::FETCH_ORI_NEXT (null for PHP < 8.0)
	 * @param  int|null $cursorOffset      The cursor offset. Controls which row is returned.
	 *                                     Default: 0 (null for PHP < 8.0)
	 * @return mixed                       The row data formatted according to the fetch mode;
	 *                                     false if there are no more rows or a failure occurs.
	 */
	#[ReturnTypeWillChange]
	public function fetch(
		$mode = 0, // PDO::FETCH_DEFAULT (available from PHP 8.0)
		$cursorOrientation = 0,
		$cursorOffset = 0
	) {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Fetch a single column from the next row of a result set.
	 *
	 * @param  int   $column The index of the column to fetch (0-indexed).
	 * @return mixed         The value of the column; false if there are no more rows.
	 */
	#[ReturnTypeWillChange]
	public function fetchColumn( $column = 0 ) {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Fetch the next row as an object.
	 *
	 * @param  string $class           The name of the class to instantiate.
	 * @param  array  $constructorArgs The parameters to pass to the class constructor.
	 * @return object                  The next row as an object.
	 */
	#[ReturnTypeWillChange]
	public function fetchObject( $class = 'stdClass', $constructorArgs = array() ) {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Get metadata for a column in a result set.
	 *
	 * @param  int         $column The index of the column (0-indexed).
	 * @return array|false         The column metadata as an associative array,
	 *                             or false if the column does not exist.
	 */
	public function getColumnMeta( $column ): array {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Fetch the SQLSTATE associated with the last statement operation.
	 *
	 * @return string|null The SQLSTATE error code (as defined by the ANSI SQL standard),
	 *                     or null if there is no error.
	 */
	public function errorCode(): ?string {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Fetch error information associated with the last statement operation.
	 *
	 * @return array The array consists of at least the following fields:
	 *                 0: SQLSTATE error code (as defined by the ANSI SQL standard).
	 *                 1: Driver-specific error code.
	 *                 2: Driver-specific error message.
	 */
	public function errorInfo(): array {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Get a statement attribute.
	 *
	 * @param  int   $attribute The attribute to get.
	 * @return mixed            The value of the attribute.
	 */
	#[ReturnTypeWillChange]
	public function getAttribute( $attribute ) {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Set a statement attribute.
	 *
	 * @param  int   $attribute The attribute to set.
	 * @param  mixed $value     The value of the attribute.
	 * @return bool             True on success, false on failure.
	 */
	public function setAttribute( $attribute, $value ): bool {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Get result set as iterator.
	 *
	 * @return Iterator The iterator for the result set.
	 */
	public function getIterator(): Iterator {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Advances to the next rowset in a multi-rowset statement handle.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function nextRowset(): bool {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Closes the cursor, enabling the statement to be executed again.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function closeCursor(): bool {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Bind a column to a PHP variable.
	 *
	 * @param  int|string $column        Number of the column (1-indexed) or name of the column in the result set.
	 * @param  mixed      $var           PHP variable to which the column will be bound.
	 * @param  int        $type          Data type of the parameter, specified by the PDO::PARAM_* constants.
	 * @param  int        $maxLength     A hint for pre-allocation.
	 * @param  mixed      $driverOptions Optional parameters for the driver.
	 * @return bool                      True on success, false on failure.
	 */
	public function bindColumn( $column, &$var, $type = null, $maxLength = null, $driverOptions = null ): bool {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Bind a parameter to a PHP variable.
	 *
	 * @param  int|string $param         Parameter identifier. Either a 1-indexed position of the parameter or a named parameter.
	 * @param  mixed      $var           PHP variable to which the parameter will be bound.
	 * @param  int        $type          Data type of the parameter, specified by the PDO::PARAM_* constants.
	 * @param  int        $maxLength     Length of the data type.
	 * @param  mixed      $driverOptions Optional parameters for the driver.
	 * @return bool                      True on success, false on failure.
	 */
	public function bindParam( $param, &$var, $type = PDO::PARAM_STR, $maxLength = 0, $driverOptions = null ): bool {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Bind a value to a parameter.
	 *
	 * @param  int|string $param Parameter identifier. Either a 1-indexed position of the parameter or a named parameter.
	 * @param  mixed      $value The value to bind to the parameter.
	 * @param  int        $type  Data type of the parameter, specified by the PDO::PARAM_* constants.
	 * @return bool              True on success, false on failure.
	 */
	public function bindValue( $param, $value, $type = PDO::PARAM_STR ): bool {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Dump information about the statement.
	 *
	 * Dupms the SQL query and parameters information.
	 *
	 * @return bool|null Returns null, or false on failure.
	 */
	public function debugDumpParams(): ?bool {
		throw new RuntimeException( 'Not implemented' );
	}

	/**
	 * Fetch all remaining rows from the result set.
	 *
	 * This is used internally by the "WP_PDO_Synthetic_Statement_PHP_Compat"
	 * trait, that is defined conditionally based on the current PHP version.
	 *
	 * @param  int   $mode The fetch mode to use.
	 * @param  mixed $args Additional parameters for the fetch mode.
	 * @return array       The result set as an array of rows.
	 */
	private function fetchAllRows( $mode = null, ...$args ): array {
		throw new RuntimeException( 'Not implemented' );
	}
}
