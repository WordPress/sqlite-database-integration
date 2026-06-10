<?php

/*
 * The file contains the internal constraint exception class as well:
 *   phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
 */

/**
 * The pure-PHP database engine ("WP_PHP_Engine").
 *
 * An SQLite-compatible database engine implemented entirely in PHP, with no
 * dependency on the pdo_sqlite or sqlite3 extensions. Tables are stored as
 * PHP arrays, with optional persistence to a file.
 *
 * The engine implements the SQLite SQL dialect surface used by the WordPress
 * SQLite driver (and general hand-written SQLite SQL), including:
 *
 *   - dynamic typing with column affinities and STRICT tables,
 *   - PRIMARY KEY/UNIQUE/NOT NULL/CHECK/FOREIGN KEY constraints,
 *   - AUTOINCREMENT and the sqlite_sequence table,
 *   - transactions and savepoints,
 *   - AFTER INSERT/UPDATE/DELETE triggers,
 *   - the sqlite_master catalog and the PRAGMA introspection interface.
 */
class WP_PHP_Engine {
	/**
	 * The SQLite version that this engine reports and aims to be
	 * compatible with.
	 */
	const SQLITE_VERSION = '3.46.1';

	/**
	 * The whole database state.
	 *
	 * This is a plain nested array, so that snapshots for transactions are
	 * cheap copy-on-write operations:
	 *
	 *   array(
	 *     'tables'    => array( lowercase name => table ),
	 *     'indexes'   => array( lowercase name => index ),
	 *     'triggers'  => array( lowercase name => trigger ),
	 *     'views'     => array( lowercase name => view ),
	 *     'sequences' => array( table name => int ),
	 *   )
	 *
	 * @var array
	 */
	private $db;

	/**
	 * The transaction stack: a list of array( 'name' => ?string, 'db' => array ).
	 *
	 * @var array
	 */
	private $transaction_stack = array();

	/**
	 * The database file path, or null for in-memory databases.
	 *
	 * @var string|null
	 */
	private $path;

	/**
	 * User-defined functions: lowercase name => callable.
	 *
	 * @var array
	 */
	private $user_functions = array();

	/**
	 * The built-in function library.
	 *
	 * @var WP_PHP_Engine_Functions
	 */
	private $functions;

	/**
	 * The last INSERT rowid.
	 *
	 * @var int
	 */
	private $last_insert_rowid = 0;

	/**
	 * The number of rows changed by the last DML statement.
	 *
	 * @var int
	 */
	private $changes = 0;

	/**
	 * The total number of rows changed in this session.
	 *
	 * @var int
	 */
	private $total_changes = 0;

	/**
	 * Whether foreign key enforcement is enabled.
	 *
	 * @var bool
	 */
	private $foreign_keys_enabled = false;

	/**
	 * The trigger execution depth (recursive triggers are disabled).
	 *
	 * @var int
	 */
	private $trigger_depth = 0;

	/**
	 * Extra scope frames for trigger body evaluation (NEW/OLD references).
	 *
	 * @var array
	 */
	private $extra_scopes = array();

	/**
	 * A small cache of parsed statements, keyed by SQL string.
	 *
	 * @var array
	 */
	private $statement_cache = array();

	/**
	 * Pragmas that store a value and return it when queried.
	 *
	 * @var array
	 */
	private $pragma_values = array();

	/**
	 * Constructor.
	 *
	 * @param string|null $path The database file path, or null/':memory:'.
	 */
	public function __construct( $path = null ) {
		$this->path      = null === $path || ':memory:' === $path || '' === $path ? null : $path;
		$this->functions = new WP_PHP_Engine_Functions( $this );
		$this->db        = array(
			'tables'    => array(),
			'indexes'   => array(),
			'triggers'  => array(),
			'views'     => array(),
			'sequences' => array(),
		);
		if ( null !== $this->path && file_exists( $this->path ) && filesize( $this->path ) > 0 ) {
			$this->load_from_disk();
		}
	}

	/*
	 * ----------------------------------------------------------------------
	 * Public API (used by the PDO facade).
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Execute an SQL string with bound parameters.
	 *
	 * @param  string $sql    The SQL string.
	 * @param  array  $params The bound parameter values.
	 * @return array          The result: array( 'cols', 'rows', 'decl', 'changes' ).
	 * @throws WP_PHP_Engine_SQL_Exception When execution fails.
	 */
	public function execute( $sql, $params = array() ) {
		$statements = $this->parse( $sql );
		$result     = array(
			'cols'    => array(),
			'rows'    => array(),
			'decl'    => array(),
			'changes' => 0,
		);
		try {
			foreach ( $statements as $statement ) {
				$result = $this->execute_statement( $statement, $params );
			}
		} catch ( WP_PHP_Engine_Constraint_Exception $e ) {
			throw $e->inner;
		}
		return $result;
	}

	/**
	 * Parse an SQL string (with caching).
	 *
	 * @param  string $sql The SQL string.
	 * @return array       The statement AST nodes.
	 */
	public function parse( $sql ) {
		if ( isset( $this->statement_cache[ $sql ] ) ) {
			return $this->statement_cache[ $sql ];
		}
		$parser     = new WP_PHP_Engine_Parser();
		$statements = $parser->parse( $sql );
		if ( count( $this->statement_cache ) > 512 ) {
			$this->statement_cache = array();
		}
		$this->statement_cache[ $sql ] = $statements;
		return $statements;
	}

	/**
	 * Register a user-defined function.
	 *
	 * @param string   $name     The function name.
	 * @param callable $callback The implementation.
	 */
	public function register_function( $name, $callback ) {
		$this->user_functions[ strtolower( $name ) ] = $callback;
	}

	/**
	 * Check whether a user-defined function exists.
	 *
	 * @param  string $name The lowercase function name.
	 * @return bool         Whether the function exists.
	 */
	public function has_user_function( $name ) {
		return isset( $this->user_functions[ $name ] );
	}

	/**
	 * Call a user-defined function.
	 *
	 * @param  string $name The lowercase function name.
	 * @param  array  $args The arguments.
	 * @return mixed        The result.
	 */
	public function call_user_function( $name, $args ) {
		$result = call_user_func_array( $this->user_functions[ $name ], $args );
		if ( is_bool( $result ) ) {
			return $result ? 1 : 0;
		}
		return $result;
	}

	/**
	 * Get the built-in function library.
	 *
	 * @return WP_PHP_Engine_Functions
	 */
	public function get_functions() {
		return $this->functions;
	}

	/**
	 * Get the last INSERT rowid.
	 *
	 * @return int
	 */
	public function get_last_insert_rowid() {
		return $this->last_insert_rowid;
	}

	/**
	 * Get the number of rows changed by the last DML statement.
	 *
	 * @return int
	 */
	public function get_changes() {
		return $this->changes;
	}

	/**
	 * Get the total number of rows changed in this session.
	 *
	 * @return int
	 */
	public function get_total_changes() {
		return $this->total_changes;
	}

	/**
	 * Check whether a transaction is active.
	 *
	 * @return bool
	 */
	public function in_transaction() {
		return count( $this->transaction_stack ) > 0;
	}

	/**
	 * Get the current UTC timestamp in the requested format.
	 *
	 * @param  string $fn CURRENT_TIMESTAMP, CURRENT_DATE, or CURRENT_TIME.
	 * @return string     The formatted timestamp.
	 */
	public function current_timestamp( $keyword ) {
		switch ( $keyword ) {
			case 'CURRENT_DATE':
				return gmdate( 'Y-m-d' );
			case 'CURRENT_TIME':
				return gmdate( 'H:i:s' );
			default:
				return gmdate( 'Y-m-d H:i:s' );
		}
	}

	/**
	 * Get a table definition by lowercase name.
	 *
	 * Temporary tables shadow regular tables with the same name.
	 *
	 * @param  string $lower The lowercase table name.
	 * @return array|null    The table, or null.
	 */
	public function get_table( $lower ) {
		if ( isset( $this->db['tables'][ 'temp.' . $lower ] ) ) {
			return $this->db['tables'][ 'temp.' . $lower ];
		}
		return isset( $this->db['tables'][ $lower ] ) ? $this->db['tables'][ $lower ] : null;
	}

	/**
	 * Resolve a lowercase table name to its storage key.
	 *
	 * Temporary tables are stored with a "temp." key prefix and shadow
	 * regular tables with the same name.
	 *
	 * @param  string $lower The lowercase table name.
	 * @return string|null   The storage key, or null when not found.
	 */
	public function resolve_table_key( $lower ) {
		if ( isset( $this->db['tables'][ 'temp.' . $lower ] ) ) {
			return 'temp.' . $lower;
		}
		if ( isset( $this->db['tables'][ $lower ] ) ) {
			return $lower;
		}
		return null;
	}

	/**
	 * Strip the schema prefix from a table storage key.
	 *
	 * @param  string $key The storage key.
	 * @return string      The bare lowercase table name.
	 */
	private static function bare_table_name( $key ) {
		return 0 === strpos( $key, 'temp.' ) ? substr( $key, 5 ) : $key;
	}

	/**
	 * Get a view definition by lowercase name.
	 *
	 * @param  string $lower The lowercase view name.
	 * @return array|null    The view, or null.
	 */
	public function get_view( $lower ) {
		return isset( $this->db['views'][ $lower ] ) ? $this->db['views'][ $lower ] : null;
	}

	/**
	 * Get the extra scope frames (for trigger NEW/OLD references).
	 *
	 * @return array
	 */
	public function get_extra_scopes() {
		return $this->extra_scopes;
	}

	/*
	 * ----------------------------------------------------------------------
	 * Statement execution.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Execute a single parsed statement.
	 *
	 * @param  array $statement The statement AST node.
	 * @param  array $params    The bound parameter values.
	 * @return array            The result.
	 */
	private function execute_statement( $statement, $params ) {
		$evaluator         = new WP_PHP_Engine_Evaluator( $this, $params );
		$evaluator->scopes = $this->extra_scopes;

		switch ( $statement['t'] ) {
			case 'select':
				$result            = $evaluator->select( $statement );
				$result['changes'] = 0;
				return $result;

			case 'insert':
				return $this->execute_insert( $statement, $evaluator );

			case 'update':
				return $this->execute_update( $statement, $evaluator );

			case 'delete':
				return $this->execute_delete( $statement, $evaluator );

			case 'create_table':
				return $this->execute_create_table( $statement, $evaluator );

			case 'create_table_as':
				return $this->execute_create_table_as( $statement, $evaluator );

			case 'create_index':
				return $this->execute_create_index( $statement, $evaluator );

			case 'create_trigger':
				return $this->execute_create_trigger( $statement );

			case 'create_view':
				return $this->execute_create_view( $statement );

			case 'drop':
				return $this->execute_drop( $statement );

			case 'alter_rename_table':
				return $this->execute_alter_rename_table( $statement );

			case 'alter_rename_column':
				return $this->execute_alter_rename_column( $statement );

			case 'alter_add_column':
				return $this->execute_alter_add_column( $statement, $evaluator );

			case 'alter_drop_column':
				return $this->execute_alter_drop_column( $statement );

			case 'pragma':
				return $this->execute_pragma( $statement );

			case 'begin':
				if ( $this->in_explicit_transaction ) {
					throw new WP_PHP_Engine_SQL_Exception( 'cannot start a transaction within a transaction' );
				}
				$this->in_explicit_transaction = true;
				$this->transaction_stack[]     = array(
					'name' => null,
					'db'   => $this->db,
				);
				return $this->empty_result();

			case 'commit':
				if ( ! $this->in_explicit_transaction && 0 === count( $this->transaction_stack ) ) {
					throw new WP_PHP_Engine_SQL_Exception( 'cannot commit - no transaction is active' );
				}
				$this->transaction_stack       = array();
				$this->in_explicit_transaction = false;
				$this->save_to_disk();
				return $this->empty_result();

			case 'rollback':
				if ( ! $this->in_explicit_transaction && 0 === count( $this->transaction_stack ) ) {
					throw new WP_PHP_Engine_SQL_Exception( 'cannot rollback - no transaction is active' );
				}
				$this->db                      = $this->transaction_stack[0]['db'];
				$this->transaction_stack       = array();
				$this->in_explicit_transaction = false;
				return $this->empty_result();

			case 'savepoint':
				$this->transaction_stack[] = array(
					'name' => strtolower( $statement['name'] ),
					'db'   => $this->db,
				);
				return $this->empty_result();

			case 'release':
				$index = $this->find_savepoint( $statement['name'] );
				if ( null === $index ) {
					throw new WP_PHP_Engine_SQL_Exception( 'no such savepoint: ' . $statement['name'] );
				}
				$this->transaction_stack = array_slice( $this->transaction_stack, 0, $index );
				if ( 0 === count( $this->transaction_stack ) && ! $this->in_explicit_transaction ) {
					$this->save_to_disk();
				}
				return $this->empty_result();

			case 'rollback_to':
				$index = $this->find_savepoint( $statement['name'] );
				if ( null === $index ) {
					throw new WP_PHP_Engine_SQL_Exception( 'no such savepoint: ' . $statement['name'] );
				}
				$this->db                = $this->transaction_stack[ $index ]['db'];
				$this->transaction_stack = array_slice( $this->transaction_stack, 0, $index + 1 );
				return $this->empty_result();

			case 'analyze':
				if ( null !== $statement['name'] ) {
					$lower = strtolower( $statement['name'] );
					if ( null === $this->resolve_table_key( $lower )
						&& ! isset( $this->db['indexes'][ $lower ] )
						&& ! isset( $this->db['indexes'][ 'temp.' . $lower ] ) ) {
						throw new WP_PHP_Engine_SQL_Exception( 'no such table: ' . $statement['name'] );
					}
				}
				return $this->empty_result();

			case 'noop':
				return $this->empty_result();
		}

		throw new WP_PHP_Engine_SQL_Exception( 'unsupported statement' );
	}

	/**
	 * Whether an explicit BEGIN transaction is active.
	 *
	 * @var bool
	 */
	private $in_explicit_transaction = false;

	/**
	 * Find the last savepoint with the given name.
	 *
	 * @param  string $name The savepoint name.
	 * @return int|null     The stack index, or null.
	 */
	private function find_savepoint( $name ) {
		$lower = strtolower( $name );
		for ( $i = count( $this->transaction_stack ) - 1; $i >= 0; $i-- ) {
			if ( $this->transaction_stack[ $i ]['name'] === $lower ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Build an empty result.
	 *
	 * @return array
	 */
	private function empty_result() {
		return array(
			'cols'    => array(),
			'rows'    => array(),
			'decl'    => array(),
			'changes' => 0,
		);
	}

	/**
	 * Run a write operation with statement-level atomicity.
	 *
	 * @param  callable $callback The operation.
	 * @return array              The result.
	 */
	private function atomic( $callback ) {
		$snapshot = $this->db;
		try {
			$result = $callback();
		} catch ( Exception $e ) {
			$this->db = $snapshot;
			throw $e;
		}
		if ( 0 === count( $this->transaction_stack ) ) {
			$this->save_to_disk();
		}
		return $result;
	}

	/*
	 * ----------------------------------------------------------------------
	 * INSERT.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Execute an INSERT or REPLACE statement.
	 *
	 * @param  array                   $statement The statement AST node.
	 * @param  WP_PHP_Engine_Evaluator $evaluator The evaluator.
	 * @return array                              The result.
	 */
	private function execute_insert( $statement, $evaluator ) {
		$engine = $this;
		return $this->atomic(
			function () use ( $statement, $evaluator, $engine ) {
				return $engine->do_insert( $statement, $evaluator );
			}
		);
	}

	/**
	 * The INSERT implementation (public for closure access on PHP 7.x).
	 *
	 * @param  array                   $statement The statement AST node.
	 * @param  WP_PHP_Engine_Evaluator $evaluator The evaluator.
	 * @return array                              The result.
	 */
	public function do_insert( $statement, $evaluator ) {
		$lower = $this->resolve_table_key( strtolower( $statement['tbl'] ) );
		if ( null === $lower ) {
			throw new WP_PHP_Engine_SQL_Exception( 'no such table: ' . $statement['tbl'] );
		}
		$table = $this->db['tables'][ $lower ];

		// Resolve the source rows.
		$src = $statement['src'];
		if ( 'default_values' === $src['t'] ) {
			$source_rows = array( null ); // One row of all defaults.
		} else {
			if ( ! empty( $statement['with'] ) ) {
				$src['with'] = array_merge( $statement['with'], isset( $src['with'] ) ? $src['with'] : array() );
			}
			$result      = $evaluator->select( $src );
			$source_rows = $result['rows'];
		}

		// Resolve target columns.
		$column_lowers = array_keys( $table['columns'] );
		if ( null !== $statement['cols'] ) {
			$targets = array();
			foreach ( $statement['cols'] as $col ) {
				$col_lower = strtolower( $col );
				if ( ! isset( $table['columns'][ $col_lower ] )
					&& ! in_array( $col_lower, array( 'rowid', '_rowid_', 'oid' ), true ) ) {
					throw new WP_PHP_Engine_SQL_Exception(
						'table ' . $table['name'] . ' has no column named ' . $col
					);
				}
				$targets[] = $col_lower;
			}
		} else {
			$targets = $column_lowers;
		}

		$changes = 0;
		foreach ( $source_rows as $source_row ) {
			if ( null !== $source_row && count( $source_row ) !== count( $targets ) ) {
				throw new WP_PHP_Engine_SQL_Exception(
					sprintf( 'table %s has %d columns but %d values were supplied', $table['name'], count( $targets ), count( $source_row ) )
				);
			}

			// Build the candidate row.
			$candidate      = array();
			$explicit_rowid = null;
			if ( null !== $source_row ) {
				foreach ( $targets as $position => $col_lower ) {
					if ( ! isset( $table['columns'][ $col_lower ] ) ) {
						$explicit_rowid = $source_row[ $position ]; // Bare rowid target.
						continue;
					}
					$candidate[ $col_lower ] = $source_row[ $position ];
				}
			}
			foreach ( $column_lowers as $col_lower ) {
				if ( ! array_key_exists( $col_lower, $candidate ) ) {
					$candidate[ $col_lower ] = $this->column_default_value( $table['columns'][ $col_lower ], $evaluator );
				}
			}

			try {
				$this->insert_row( $lower, $candidate, $explicit_rowid, $statement, $evaluator );
				$changes += 1;
			} catch ( WP_PHP_Engine_Constraint_Exception $e ) {
				if ( 'IGNORE' === $statement['or'] ) {
					continue;
				}
				throw $e->inner;
			}
		}

		$this->changes        = $changes;
		$this->total_changes += $changes;
		$result               = $this->empty_result();
		$result['changes']    = $changes;
		return $result;
	}

	/**
	 * Compute the default value of a column.
	 *
	 * @param  array                   $column    The column definition.
	 * @param  WP_PHP_Engine_Evaluator $evaluator The evaluator.
	 * @return mixed                              The default value.
	 */
	private function column_default_value( $column, $evaluator ) {
		if ( ! $column['has_default'] || null === $column['default'] ) {
			return null;
		}
		return $evaluator->eval( $column['default'], array() );
	}

	/**
	 * Insert a single row, enforcing constraints and firing triggers.
	 *
	 * @param  string                  $lower          The lowercase table name.
	 * @param  array                   $candidate      The candidate row (lowercase col => value).
	 * @param  mixed                   $explicit_rowid An explicitly specified bare rowid.
	 * @param  array                   $statement      The INSERT statement node.
	 * @param  WP_PHP_Engine_Evaluator $evaluator      The evaluator.
	 * @throws WP_PHP_Engine_Constraint_Exception On constraint violations (catchable for OR IGNORE).
	 */
	private function insert_row( $lower, $candidate, $explicit_rowid, $statement, $evaluator ) {
		$table = $this->db['tables'][ $lower ];

		// Apply column affinities and STRICT typing.
		$candidate = $this->apply_row_affinity( $table, $candidate );

		// Resolve the rowid.
		$rowid_alias = $table['rowid_alias'];
		$rowid       = null;
		if ( null !== $rowid_alias && null !== $candidate[ $rowid_alias ] ) {
			$value = $candidate[ $rowid_alias ];
			if ( ! is_int( $value ) ) {
				if ( is_float( $value ) && (float) (int) $value === $value ) {
					$value = (int) $value;
				} elseif ( is_string( $value ) && WP_PHP_Engine_Values::is_well_formed_number( $value ) ) {
					$number = WP_PHP_Engine_Values::text_to_number( $value );
					if ( is_int( $number ) ) {
						$value = $number;
					}
				}
			}
			if ( ! is_int( $value ) ) {
				throw $this->constraint( 'datatype mismatch', 'HY000', 20 );
			}
			$rowid                     = $value;
			$candidate[ $rowid_alias ] = $value;
		}

		if ( null === $rowid && null !== $explicit_rowid ) {
			// An explicitly targeted bare "rowid" column.
			$rowid = (int) $explicit_rowid;
			if ( null !== $rowid_alias && null === $candidate[ $rowid_alias ] ) {
				$candidate[ $rowid_alias ] = $rowid;
			}
		}

		if ( null === $rowid ) {
			$max = 0;
			if ( count( $table['rows'] ) > 0 ) {
				$max = max( array_keys( $table['rows'] ) );
			}
			if ( $table['autoincrement'] ) {
				$sequence = isset( $this->db['sequences'][ $table['name'] ] ) ? $this->db['sequences'][ $table['name'] ] : 0;
				$rowid    = max( $max, $sequence ) + 1;
			} else {
				$rowid = $max + 1;
			}
			if ( null !== $rowid_alias ) {
				$candidate[ $rowid_alias ] = $rowid;
			}
		}

		// Check NOT NULL constraints.
		foreach ( $table['columns'] as $col_lower => $column ) {
			if ( $column['notnull'] && null === $candidate[ $col_lower ] ) {
				throw $this->constraint(
					'NOT NULL constraint failed: ' . $table['name'] . '.' . $column['name'],
					'23000',
					19,
					'Integrity constraint violation'
				);
			}
		}

		// Check CHECK constraints.
		$this->check_constraints( $table, $candidate, $rowid, $evaluator );

		// Check the rowid and UNIQUE constraints.
		$conflict_rowid = null;
		$conflict_cols  = null;
		if ( isset( $table['rows'][ $rowid ] ) ) {
			$conflict_rowid = $rowid;
			$conflict_cols  = array( null !== $rowid_alias ? $table['columns'][ $rowid_alias ]['name'] : 'rowid' );
		} else {
			$found = $this->find_unique_conflict( $lower, $candidate, null );
			if ( null !== $found ) {
				$conflict_rowid = $found[0];
				$conflict_cols  = $found[1];
			}
		}

		if ( null !== $conflict_rowid ) {
			$or = $statement['or'];
			if ( 'REPLACE' === $or ) {
				// Delete all conflicting rows, then insert.
				while ( null !== $conflict_rowid ) {
					$this->delete_row( $lower, $conflict_rowid, $evaluator, false );
					$table          = $this->db['tables'][ $lower ];
					$conflict_rowid = isset( $table['rows'][ $rowid ] ) ? $rowid : null;
					if ( null === $conflict_rowid ) {
						$found          = $this->find_unique_conflict( $lower, $candidate, null );
						$conflict_rowid = null !== $found ? $found[0] : null;
					}
				}
			} elseif ( null !== $statement['upsert'] ) {
				$upsert = $statement['upsert'];
				if ( 'nothing' === $upsert['do'] ) {
					return;
				}
				$this->upsert_update( $lower, $conflict_rowid, $candidate, $upsert, $evaluator );
				return;
			} else {
				$names = array();
				foreach ( $conflict_cols as $col ) {
					$names[] = $table['name'] . '.' . $col;
				}
				throw $this->constraint(
					'UNIQUE constraint failed: ' . implode( ', ', $names ),
					'23000',
					19,
					'Integrity constraint violation'
				);
			}
		}

		// Foreign key checks (child side).
		$this->db['tables'][ $lower ]['rows'][ $rowid ] = $candidate;
		try {
			$this->check_foreign_keys_child( $lower, $candidate );
		} catch ( Exception $e ) {
			unset( $this->db['tables'][ $lower ]['rows'][ $rowid ] );
			throw $e;
		}

		// Update the AUTOINCREMENT sequence.
		if ( $table['autoincrement'] ) {
			$sequence = isset( $this->db['sequences'][ $table['name'] ] ) ? $this->db['sequences'][ $table['name'] ] : 0;
			if ( $rowid > $sequence ) {
				$this->db['sequences'][ $table['name'] ] = $rowid;
			}
		}

		$this->last_insert_rowid = $rowid;

		// Fire AFTER INSERT triggers.
		$this->fire_triggers( $lower, 'INSERT', null, $candidate, $rowid, null );
	}

	/**
	 * Perform the DO UPDATE part of an upsert.
	 *
	 * @param string                  $lower          The lowercase table name.
	 * @param int                     $conflict_rowid The rowid of the conflicting row.
	 * @param array                   $candidate      The proposed (excluded) row.
	 * @param array                   $upsert         The upsert AST node.
	 * @param WP_PHP_Engine_Evaluator $evaluator      The evaluator.
	 */
	private function upsert_update( $lower, $conflict_rowid, $candidate, $upsert, $evaluator ) {
		$table    = $this->db['tables'][ $lower ];
		$existing = $table['rows'][ $conflict_rowid ];

		// Scope: the table row (current values) plus the "excluded" row.
		$table_slot    = $this->make_table_slot( $table, $existing, $conflict_rowid, $lower );
		$excluded_slot = $this->make_table_slot( $table, $candidate, null, 'excluded' );
		$frame         = array( $table_slot, $excluded_slot );

		if ( null !== $upsert['where'] ) {
			if ( ! WP_PHP_Engine_Values::is_truthy( $evaluator->eval( $upsert['where'], $frame ) ) ) {
				return;
			}
		}

		$new_row = $existing;
		foreach ( $upsert['set'] as $assignment ) {
			$col_lower = strtolower( $assignment['col'] );
			if ( ! isset( $table['columns'][ $col_lower ] ) ) {
				throw new WP_PHP_Engine_SQL_Exception( 'no such column: ' . $assignment['col'] );
			}
			$new_row[ $col_lower ] = $evaluator->eval( $assignment['e'], $frame );
		}

		$this->update_row( $lower, $conflict_rowid, $new_row, $evaluator, array_map( 'strtolower', array_column( $upsert['set'], 'col' ) ) );
	}

	/*
	 * ----------------------------------------------------------------------
	 * UPDATE.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Execute an UPDATE statement.
	 *
	 * @param  array                   $statement The statement AST node.
	 * @param  WP_PHP_Engine_Evaluator $evaluator The evaluator.
	 * @return array                              The result.
	 */
	private function execute_update( $statement, $evaluator ) {
		$engine = $this;
		return $this->atomic(
			function () use ( $statement, $evaluator, $engine ) {
				return $engine->do_update( $statement, $evaluator );
			}
		);
	}

	/**
	 * The UPDATE implementation.
	 *
	 * @param  array                   $statement The statement AST node.
	 * @param  WP_PHP_Engine_Evaluator $evaluator The evaluator.
	 * @return array                              The result.
	 */
	public function do_update( $statement, $evaluator ) {
		$lower = $this->resolve_table_key( strtolower( $statement['tbl'] ) );
		if ( null === $lower ) {
			throw new WP_PHP_Engine_SQL_Exception( 'no such table: ' . $statement['tbl'] );
		}
		$table = $this->db['tables'][ $lower ];
		$alias = null !== $statement['alias'] ? strtolower( $statement['alias'] ) : self::bare_table_name( $lower );

		if ( ! empty( $statement['with'] ) ) {
			foreach ( $statement['with'] as $name => $cte ) {
				$evaluator->ctes[ $name ] = $cte;
			}
		}

		// Find matching rows first (the row set must not change mid-update).
		// UPDATE ... FROM joins additional tables into the row scope.
		$from = null;
		if ( null !== $statement['from'] ) {
			$from = $evaluator->resolve_update_from( $statement['from'] );
		}

		$matching  = array();
		$scan_rows = $table['rows'];
		ksort( $scan_rows );
		foreach ( $scan_rows as $rowid => $row ) {
			$slot = $this->make_table_slot( $table, $row, $rowid, $alias );
			if ( null === $from ) {
				$frame = array( $slot );
				if ( null === $statement['where'] || WP_PHP_Engine_Values::is_truthy( $evaluator->eval( $statement['where'], $frame ) ) ) {
					$matching[] = array( $rowid, null );
				}
			} else {
				// The first matching combination provides the SET values.
				foreach ( $from['frames'] as $from_frame ) {
					$frame = array_merge( array( $slot ), $from_frame );
					if ( null === $statement['where'] || WP_PHP_Engine_Values::is_truthy( $evaluator->eval( $statement['where'], $frame ) ) ) {
						$matching[] = array( $rowid, $frame );
						break;
					}
				}
			}
		}

		$set_columns = array();
		foreach ( $statement['set'] as $assignment ) {
			if ( isset( $assignment['cols'] ) ) {
				foreach ( $assignment['cols'] as $col ) {
					$set_columns[] = strtolower( $col );
				}
			} else {
				$set_columns[] = strtolower( $assignment['col'] );
			}
		}

		$changes = 0;
		foreach ( $matching as $match ) {
			list( $rowid, $frame ) = $match;
			$table                 = $this->db['tables'][ $lower ];
			if ( ! isset( $table['rows'][ $rowid ] ) ) {
				continue; // Deleted by a trigger or cascade.
			}
			$row = $table['rows'][ $rowid ];
			if ( null === $frame ) {
				$frame = array( $this->make_table_slot( $table, $row, $rowid, $alias ) );
			}

			$new_row = $row;
			foreach ( $statement['set'] as $assignment ) {
				// Multi-column assignment: SET (a, b) = (SELECT ...).
				if ( isset( $assignment['cols'] ) ) {
					$values = $evaluator->eval_row_subquery( $assignment['e'], $frame );
					foreach ( $assignment['cols'] as $position => $col ) {
						$col_lower = strtolower( $col );
						if ( ! isset( $table['columns'][ $col_lower ] ) ) {
							throw new WP_PHP_Engine_SQL_Exception( 'no such column: ' . $col );
						}
						$new_row[ $col_lower ] = null !== $values && array_key_exists( $position, $values ) ? $values[ $position ] : null;
					}
					continue;
				}
				$col_lower = strtolower( $assignment['col'] );
				if ( ! isset( $table['columns'][ $col_lower ] ) ) {
					throw new WP_PHP_Engine_SQL_Exception( 'no such column: ' . $assignment['col'] );
				}
				$new_row[ $col_lower ] = $evaluator->eval( $assignment['e'], $frame );
			}

			$this->update_row( $lower, $rowid, $new_row, $evaluator, $set_columns );
			$changes += 1;
		}

		$this->changes        = $changes;
		$this->total_changes += $changes;
		$result               = $this->empty_result();
		$result['changes']    = $changes;
		return $result;
	}

	/**
	 * Update a single row, enforcing constraints and firing triggers.
	 *
	 * @param string                  $lower       The lowercase table name.
	 * @param int                     $rowid       The rowid.
	 * @param array                   $new_row     The new row values.
	 * @param WP_PHP_Engine_Evaluator $evaluator   The evaluator.
	 * @param array                   $set_columns The lowercase names of assigned columns.
	 */
	private function update_row( $lower, $rowid, $new_row, $evaluator, $set_columns ) {
		$table   = $this->db['tables'][ $lower ];
		$old_row = $table['rows'][ $rowid ];

		// Apply column affinities and STRICT typing.
		$new_row = $this->apply_row_affinity( $table, $new_row );

		// A change of the rowid alias column moves the row.
		$new_rowid   = $rowid;
		$rowid_alias = $table['rowid_alias'];
		if ( null !== $rowid_alias && $new_row[ $rowid_alias ] !== $old_row[ $rowid_alias ] ) {
			$value = $new_row[ $rowid_alias ];
			if ( is_float( $value ) && (float) (int) $value === $value ) {
				$value = (int) $value;
			} elseif ( is_string( $value ) && WP_PHP_Engine_Values::is_well_formed_number( $value ) ) {
				$number = WP_PHP_Engine_Values::text_to_number( $value );
				if ( is_int( $number ) ) {
					$value = $number;
				}
			}
			if ( ! is_int( $value ) ) {
				throw $this->constraint( 'datatype mismatch', 'HY000', 20 );
			}
			$new_rowid               = $value;
			$new_row[ $rowid_alias ] = $value;
		}

		// NOT NULL constraints.
		foreach ( $table['columns'] as $col_lower => $column ) {
			if ( $column['notnull'] && null === $new_row[ $col_lower ] ) {
				throw $this->constraint(
					'NOT NULL constraint failed: ' . $table['name'] . '.' . $column['name'],
					'23000',
					19,
					'Integrity constraint violation'
				);
			}
		}

		// CHECK constraints.
		$this->check_constraints( $table, $new_row, $new_rowid, $evaluator );

		// Rowid/UNIQUE constraints (excluding this row).
		if ( $new_rowid !== $rowid && isset( $table['rows'][ $new_rowid ] ) ) {
			throw $this->constraint(
				'UNIQUE constraint failed: ' . $table['name'] . '.' . ( null !== $rowid_alias ? $table['columns'][ $rowid_alias ]['name'] : 'rowid' ),
				'23000',
				19,
				'Integrity constraint violation'
			);
		}
		$found = $this->find_unique_conflict( $lower, $new_row, $rowid );
		if ( null !== $found ) {
			$names = array();
			foreach ( $found[1] as $col ) {
				$names[] = $table['name'] . '.' . $col;
			}
			throw $this->constraint(
				'UNIQUE constraint failed: ' . implode( ', ', $names ),
				'23000',
				19,
				'Integrity constraint violation'
			);
		}

		// Apply the update.
		if ( $new_rowid !== $rowid ) {
			unset( $this->db['tables'][ $lower ]['rows'][ $rowid ] );
			$this->db['tables'][ $lower ]['rows'][ $new_rowid ] = $new_row;
		} else {
			$this->db['tables'][ $lower ]['rows'][ $rowid ] = $new_row;
		}

		// Foreign keys: child side for the new values, parent side for changes.
		try {
			$this->check_foreign_keys_child( $lower, $new_row );
			$this->enforce_foreign_keys_parent_update( $lower, $old_row, $new_row, $evaluator );
		} catch ( Exception $e ) {
			if ( $new_rowid !== $rowid ) {
				unset( $this->db['tables'][ $lower ]['rows'][ $new_rowid ] );
				$this->db['tables'][ $lower ]['rows'][ $rowid ] = $old_row;
			} else {
				$this->db['tables'][ $lower ]['rows'][ $rowid ] = $old_row;
			}
			throw $e;
		}

		// Fire AFTER UPDATE triggers.
		$this->fire_triggers( $lower, 'UPDATE', $old_row, $new_row, $new_rowid, $set_columns );
	}

	/*
	 * ----------------------------------------------------------------------
	 * DELETE.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Execute a DELETE statement.
	 *
	 * @param  array                   $statement The statement AST node.
	 * @param  WP_PHP_Engine_Evaluator $evaluator The evaluator.
	 * @return array                              The result.
	 */
	private function execute_delete( $statement, $evaluator ) {
		$engine = $this;
		return $this->atomic(
			function () use ( $statement, $evaluator, $engine ) {
				return $engine->do_delete( $statement, $evaluator );
			}
		);
	}

	/**
	 * The DELETE implementation.
	 *
	 * @param  array                   $statement The statement AST node.
	 * @param  WP_PHP_Engine_Evaluator $evaluator The evaluator.
	 * @return array                              The result.
	 */
	public function do_delete( $statement, $evaluator ) {
		$lower = strtolower( $statement['tbl'] );

		// DELETE FROM sqlite_sequence resets AUTOINCREMENT counters.
		if ( 'sqlite_sequence' === $lower && ! empty( $this->db['has_sequence_table'] ) ) {
			$virtual = $this->virtual_table_result( 'sqlite_sequence' );
			$changes = 0;
			foreach ( $virtual['rows'] as $row ) {
				$frame = array(
					array(
						'alias' => 'sqlite_sequence',
						'cols'  => array(
							'name' => $row[0],
							'seq'  => $row[1],
						),
						'names' => array(
							'name' => 'name',
							'seq'  => 'seq',
						),
						'aff'   => array(),
						'coll'  => array(),
						'decl'  => array(),
						'rowid' => null,
					),
				);
				if ( null === $statement['where'] || WP_PHP_Engine_Values::is_truthy( $evaluator->eval( $statement['where'], $frame ) ) ) {
					$this->delete_sequences( $row[0] );
					$changes += 1;
				}
			}
			$this->changes     = $changes;
			$result            = $this->empty_result();
			$result['changes'] = $changes;
			return $result;
		}

		$lower = $this->resolve_table_key( $lower );
		if ( null === $lower ) {
			throw new WP_PHP_Engine_SQL_Exception( 'no such table: ' . $statement['tbl'] );
		}
		$table = $this->db['tables'][ $lower ];
		$alias = null !== $statement['alias'] ? strtolower( $statement['alias'] ) : self::bare_table_name( $lower );

		if ( ! empty( $statement['with'] ) ) {
			foreach ( $statement['with'] as $name => $cte ) {
				$evaluator->ctes[ $name ] = $cte;
			}
		}

		$matching  = array();
		$scan_rows = $table['rows'];
		ksort( $scan_rows );
		foreach ( $scan_rows as $rowid => $row ) {
			$frame = array( $this->make_table_slot( $table, $row, $rowid, $alias ) );
			if ( null === $statement['where'] || WP_PHP_Engine_Values::is_truthy( $evaluator->eval( $statement['where'], $frame ) ) ) {
				$matching[] = $rowid;
			}
		}

		$changes = 0;
		foreach ( $matching as $rowid ) {
			if ( ! isset( $this->db['tables'][ $lower ]['rows'][ $rowid ] ) ) {
				continue;
			}
			$this->delete_row( $lower, $rowid, $evaluator, true );
			$changes += 1;
		}

		$this->changes        = $changes;
		$this->total_changes += $changes;
		$result               = $this->empty_result();
		$result['changes']    = $changes;
		return $result;
	}

	/**
	 * Delete a single row, enforcing foreign keys and firing triggers.
	 *
	 * @param string                  $lower         The lowercase table name.
	 * @param int                     $rowid         The rowid.
	 * @param WP_PHP_Engine_Evaluator $evaluator     The evaluator.
	 * @param bool                    $fire_triggers Whether to fire triggers.
	 */
	private function delete_row( $lower, $rowid, $evaluator, $fire_triggers ) {
		$table   = $this->db['tables'][ $lower ];
		$old_row = $table['rows'][ $rowid ];

		unset( $this->db['tables'][ $lower ]['rows'][ $rowid ] );

		// Foreign keys: parent side.
		$this->enforce_foreign_keys_parent_delete( $lower, $old_row, $evaluator );

		if ( $fire_triggers ) {
			$this->fire_triggers( $lower, 'DELETE', $old_row, null, $rowid, null );
		}
	}

	/*
	 * ----------------------------------------------------------------------
	 * Row helpers: affinity, constraints, uniqueness.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Apply column affinities and STRICT type checks to a row.
	 *
	 * @param  array $table The table definition.
	 * @param  array $row   The row (lowercase col => value).
	 * @return array        The coerced row.
	 */
	private function apply_row_affinity( $table, $row ) {
		foreach ( $table['columns'] as $col_lower => $column ) {
			if ( ! array_key_exists( $col_lower, $row ) ) {
				$row[ $col_lower ] = null;
				continue;
			}
			$value = $row[ $col_lower ];
			if ( null === $value ) {
				continue;
			}
			$value = WP_PHP_Engine_Values::apply_affinity( $value, $column['affinity'] );

			if ( $table['strict'] ) {
				$value = $this->check_strict_type( $table, $column, $value );
			}
			$row[ $col_lower ] = $value;
		}
		return $row;
	}

	/**
	 * Enforce STRICT table typing for a value.
	 *
	 * @param  array $table  The table definition.
	 * @param  array $column The column definition.
	 * @param  mixed $value  The value (after affinity).
	 * @return mixed         The checked value.
	 */
	private function check_strict_type( $table, $column, $value ) {
		$declared = strtoupper( (string) $column['type'] );
		$base     = preg_replace( '/\s*\(.*$/', '', $declared );
		switch ( $base ) {
			case 'INT':
			case 'INTEGER':
				if ( is_int( $value ) ) {
					return $value;
				}
				if ( is_float( $value ) && (float) (int) $value === $value ) {
					return (int) $value;
				}
				break;
			case 'REAL':
				if ( is_float( $value ) ) {
					return $value;
				}
				if ( is_int( $value ) ) {
					return (float) $value;
				}
				break;
			case 'TEXT':
				if ( is_string( $value ) ) {
					return $value;
				}
				break;
			case 'BLOB':
				return $value;
			case 'ANY':
				return $value;
			default:
				return $value;
		}
		throw $this->constraint(
			sprintf(
				'cannot store %s value in %s column %s.%s',
				strtoupper( WP_PHP_Engine_Values::type_of( $value ) ),
				$base,
				$table['name'],
				$column['name']
			),
			'23000',
			19,
			'Integrity constraint violation'
		);
	}

	/**
	 * Evaluate CHECK constraints for a row.
	 *
	 * @param array                   $table     The table definition.
	 * @param array                   $row       The row.
	 * @param int|null                $rowid     The rowid.
	 * @param WP_PHP_Engine_Evaluator $evaluator The evaluator.
	 */
	private function check_constraints( $table, $row, $rowid, $evaluator ) {
		if ( 0 === count( $table['checks'] ) ) {
			return;
		}
		$frame = array( $this->make_table_slot( $table, $row, $rowid, strtolower( $table['name'] ) ) );
		foreach ( $table['checks'] as $check ) {
			$value = $evaluator->eval( $check['e'], $frame );
			if ( null !== $value && ! WP_PHP_Engine_Values::is_truthy( $value ) ) {
				throw $this->constraint(
					'CHECK constraint failed: ' . ( null !== $check['name'] ? $check['name'] : $table['name'] ),
					'23000',
					19,
					'Integrity constraint violation'
				);
			}
		}
	}

	/**
	 * Find a UNIQUE constraint conflict for a candidate row.
	 *
	 * @param  string   $lower         The lowercase table name.
	 * @param  array    $candidate     The candidate row.
	 * @param  int|null $exclude_rowid A rowid to exclude (for UPDATE).
	 * @return array|null              The conflicting array( rowid, column names ), or null.
	 */
	private function find_unique_conflict( $lower, $candidate, $exclude_rowid ) {
		$table = $this->db['tables'][ $lower ];
		// SQLite checks the most recently created index first.
		foreach ( array_reverse( $this->db['indexes'] ) as $index ) {
			if ( $index['tbl'] !== $lower || ! $index['unique'] ) {
				continue;
			}
			// Collect candidate key values; NULLs never conflict.
			$key      = array();
			$has_null = false;
			foreach ( $index['cols'] as $index_col ) {
				$col_lower = strtolower( $index_col['name'] );
				$value     = isset( $candidate[ $col_lower ] ) ? $candidate[ $col_lower ] : null;
				if ( null === $value ) {
					$has_null = true;
					break;
				}
				$key[ $col_lower ] = $value;
			}
			if ( $has_null ) {
				continue;
			}
			foreach ( $table['rows'] as $rowid => $row ) {
				if ( null !== $exclude_rowid && $rowid === $exclude_rowid ) {
					continue;
				}
				$match = true;
				foreach ( $index['cols'] as $index_col ) {
					$col_lower = strtolower( $index_col['name'] );
					$collation = null !== $index_col['collate']
						? $index_col['collate']
						: ( isset( $table['columns'][ $col_lower ]['collate'] ) && null !== $table['columns'][ $col_lower ]['collate']
							? $table['columns'][ $col_lower ]['collate']
							: 'BINARY' );
					$existing  = isset( $row[ $col_lower ] ) ? $row[ $col_lower ] : null;
					if ( null === $existing || 0 !== WP_PHP_Engine_Values::compare( $key[ $col_lower ], $existing, $collation ) ) {
						$match = false;
						break;
					}
				}
				if ( $match ) {
					$names = array();
					foreach ( $index['cols'] as $index_col ) {
						$col_lower = strtolower( $index_col['name'] );
						$names[]   = isset( $table['columns'][ $col_lower ] ) ? $table['columns'][ $col_lower ]['name'] : $index_col['name'];
					}
					return array( $rowid, $names );
				}
			}
		}
		return null;
	}

	/**
	 * Build a scope slot for a table row.
	 *
	 * @param  array    $table The table definition.
	 * @param  array    $row   The row values.
	 * @param  int|null $rowid The rowid.
	 * @param  string   $alias The lowercase alias.
	 * @return array           The slot.
	 */
	public function make_table_slot( $table, $row, $rowid, $alias ) {
		$names = array();
		$aff   = array();
		$coll  = array();
		$decl  = array();
		$srct  = array();
		foreach ( $table['columns'] as $col_lower => $column ) {
			$names[ $col_lower ] = $column['name'];
			$aff[ $col_lower ]   = $column['affinity'];
			$coll[ $col_lower ]  = null !== $column['collate'] ? $column['collate'] : 'BINARY';
			$decl[ $col_lower ]  = $column['type'];
			$srct[ $col_lower ]  = $table['name'];
		}
		return array(
			'alias'     => $alias,
			'cols'      => $row,
			'names'     => $names,
			'aff'       => $aff,
			'coll'      => $coll,
			'decl'      => $decl,
			'srct'      => $srct,
			'rowid'     => $rowid,
			'has_rowid' => true,
		);
	}

	/**
	 * Create a constraint exception.
	 *
	 * @param  string $message  The message.
	 * @param  string $sqlstate The SQLSTATE code.
	 * @param  int    $code     The SQLite error code.
	 * @param  string $category The PDO error category text.
	 * @return WP_PHP_Engine_Constraint_Exception
	 */
	private function constraint( $message, $sqlstate = 'HY000', $code = 1, $category = 'General error' ) {
		return new WP_PHP_Engine_Constraint_Exception(
			new WP_PHP_Engine_SQL_Exception( $message, $sqlstate, $code, $category )
		);
	}

	/*
	 * ----------------------------------------------------------------------
	 * Triggers.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Fire AFTER triggers for a row operation.
	 *
	 * @param string     $lower       The lowercase table name.
	 * @param string     $event       INSERT, UPDATE, or DELETE.
	 * @param array|null $old_row     The old row (UPDATE/DELETE).
	 * @param array|null $new_row     The new row (INSERT/UPDATE).
	 * @param int        $rowid       The rowid.
	 * @param array|null $set_columns The assigned columns (UPDATE only).
	 */
	private function fire_triggers( $lower, $event, $old_row, $new_row, $rowid, $set_columns ) {
		if ( $this->trigger_depth > 0 ) {
			return; // Recursive triggers are disabled.
		}
		$bare = self::bare_table_name( $lower );
		foreach ( $this->db['triggers'] as $trigger ) {
			if ( strtolower( $trigger['tbl'] ) !== $bare || $trigger['event'] !== $event ) {
				continue;
			}
			if ( 'UPDATE' === $event && null !== $trigger['of_cols'] && null !== $set_columns ) {
				$intersects = false;
				foreach ( $trigger['of_cols'] as $col ) {
					if ( in_array( strtolower( $col ), $set_columns, true ) ) {
						$intersects = true;
						break;
					}
				}
				if ( ! $intersects ) {
					continue;
				}
			}

			$table = $this->db['tables'][ $lower ];
			$frame = array();
			if ( null !== $new_row ) {
				$frame[] = $this->make_table_slot( $table, $new_row, $rowid, 'new' );
			}
			if ( null !== $old_row ) {
				$frame[] = $this->make_table_slot( $table, $old_row, $rowid, 'old' );
			}

			$this->trigger_depth += 1;
			$saved_scopes         = $this->extra_scopes;
			$this->extra_scopes[] = $frame;
			$saved_changes        = $this->changes;
			try {
				if ( null !== $trigger['when'] ) {
					$evaluator         = new WP_PHP_Engine_Evaluator( $this, array() );
					$evaluator->scopes = $this->extra_scopes;
					if ( ! WP_PHP_Engine_Values::is_truthy( $evaluator->eval( $trigger['when'], $frame ) ) ) {
						continue;
					}
				}
				foreach ( $trigger['body'] as $body_statement ) {
					$this->execute_statement( $body_statement, array() );
				}
			} catch ( WP_PHP_Engine_Raise_Ignore_Exception $e ) {
				// RAISE(IGNORE) skips the remainder of the trigger.
			} finally {
				$this->extra_scopes   = $saved_scopes;
				$this->trigger_depth -= 1;
				$this->changes        = $saved_changes;
			}
		}
	}

	/*
	 * ----------------------------------------------------------------------
	 * Foreign keys.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Check child-side foreign keys for a row.
	 *
	 * @param string $lower The lowercase table name.
	 * @param array  $row   The row.
	 */
	private function check_foreign_keys_child( $lower, $row ) {
		if ( ! $this->foreign_keys_enabled ) {
			return;
		}
		$table = $this->db['tables'][ $lower ];
		foreach ( $table['fks'] as $fk ) {
			if ( ! $this->foreign_key_satisfied( $fk, $row ) ) {
				throw new WP_PHP_Engine_SQL_Exception(
					'FOREIGN KEY constraint failed',
					'23000',
					19,
					'Integrity constraint violation'
				);
			}
		}
	}

	/**
	 * Check whether a child row satisfies a foreign key.
	 *
	 * @param  array $fk  The foreign key definition.
	 * @param  array $row The child row.
	 * @return bool       Whether the constraint is satisfied.
	 */
	private function foreign_key_satisfied( $fk, $row ) {
		$values = array();
		foreach ( $fk['cols'] as $col ) {
			$value = isset( $row[ strtolower( $col ) ] ) ? $row[ strtolower( $col ) ] : null;
			if ( null === $value ) {
				return true; // NULLs satisfy FK constraints.
			}
			$values[] = $value;
		}

		$parent_lower = strtolower( $fk['ref_table'] );
		$parent       = $this->get_table( $parent_lower );
		if ( null === $parent ) {
			return false;
		}
		$ref_cols = $this->foreign_key_parent_columns( $fk, $parent );

		foreach ( $parent['rows'] as $parent_row ) {
			$match = true;
			foreach ( $ref_cols as $position => $ref_lower ) {
				$parent_value = isset( $parent_row[ $ref_lower ] ) ? $parent_row[ $ref_lower ] : null;
				$collation    = isset( $parent['columns'][ $ref_lower ]['collate'] ) && null !== $parent['columns'][ $ref_lower ]['collate']
					? $parent['columns'][ $ref_lower ]['collate']
					: 'BINARY';
				if ( null === $parent_value || 0 !== WP_PHP_Engine_Values::compare( $values[ $position ], $parent_value, $collation ) ) {
					$match = false;
					break;
				}
			}
			if ( $match ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get the lowercase parent column names of a foreign key.
	 *
	 * @param  array $fk           The foreign key definition.
	 * @param  array $parent_table The parent table definition.
	 * @return array         The lowercase parent column names.
	 */
	private function foreign_key_parent_columns( $fk, $parent_table ) {
		if ( null !== $fk['ref_cols'] ) {
			return array_map( 'strtolower', $fk['ref_cols'] );
		}
		if ( null !== $parent_table['rowid_alias'] ) {
			return array( $parent_table['rowid_alias'] );
		}
		if ( null !== $parent_table['pk_cols'] ) {
			return $parent_table['pk_cols'];
		}
		return array();
	}

	/**
	 * Enforce parent-side foreign keys when a parent row is deleted.
	 *
	 * @param string                  $parent_lower The lowercase parent table name.
	 * @param array                   $old_row      The deleted parent row.
	 * @param WP_PHP_Engine_Evaluator $evaluator    The evaluator.
	 */
	private function enforce_foreign_keys_parent_delete( $parent_lower, $old_row, $evaluator ) {
		if ( ! $this->foreign_keys_enabled ) {
			return;
		}
		$parent = $this->db['tables'][ $parent_lower ];
		foreach ( $this->db['tables'] as $child_lower => $child ) {
			foreach ( $child['fks'] as $fk ) {
				if ( $this->resolve_table_key( strtolower( $fk['ref_table'] ) ) !== $parent_lower ) {
					continue;
				}
				$ref_cols = $this->foreign_key_parent_columns( $fk, $parent );
				if ( 0 === count( $ref_cols ) ) {
					continue;
				}
				// Find child rows referencing the deleted parent row.
				foreach ( $child['rows'] as $child_rowid => $child_row ) {
					if ( ! isset( $this->db['tables'][ $child_lower ]['rows'][ $child_rowid ] ) ) {
						continue;
					}
					$references = true;
					foreach ( $fk['cols'] as $position => $col ) {
						$child_value  = isset( $child_row[ strtolower( $col ) ] ) ? $child_row[ strtolower( $col ) ] : null;
						$parent_value = isset( $old_row[ $ref_cols[ $position ] ] ) ? $old_row[ $ref_cols[ $position ] ] : null;
						if ( null === $child_value || null === $parent_value
							|| 0 !== WP_PHP_Engine_Values::compare( $child_value, $parent_value ) ) {
							$references = false;
							break;
						}
					}
					if ( ! $references ) {
						continue;
					}
					// Another parent row with the same key still satisfies the FK.
					if ( $this->foreign_key_satisfied( $fk, $child_row ) ) {
						continue;
					}
					switch ( $fk['on_delete'] ) {
						case 'CASCADE':
							$this->delete_row( $child_lower, $child_rowid, $evaluator, true );
							break;
						case 'SET NULL':
						case 'SET DEFAULT':
							$new_child = $this->db['tables'][ $child_lower ]['rows'][ $child_rowid ];
							foreach ( $fk['cols'] as $col ) {
								$col_lower = strtolower( $col );
								if ( 'SET DEFAULT' === $fk['on_update'] || 'SET DEFAULT' === $fk['on_delete'] ) {
									$column                  = $child['columns'][ $col_lower ];
									$new_child[ $col_lower ] = $column['has_default'] && null !== $column['default']
										? $evaluator->eval( $column['default'], array() )
										: null;
								} else {
									$new_child[ $col_lower ] = null;
								}
							}
							$this->update_row( $child_lower, $child_rowid, $new_child, $evaluator, array_map( 'strtolower', $fk['cols'] ) );
							break;
						default:
							throw new WP_PHP_Engine_SQL_Exception(
								'FOREIGN KEY constraint failed',
								'23000',
								19,
								'Integrity constraint violation'
							);
					}
				}
			}
		}
	}

	/**
	 * Enforce parent-side foreign keys when a parent row is updated.
	 *
	 * @param string                  $parent_lower The lowercase parent table name.
	 * @param array                   $old_row      The old parent row.
	 * @param array                   $new_row      The new parent row.
	 * @param WP_PHP_Engine_Evaluator $evaluator    The evaluator.
	 */
	private function enforce_foreign_keys_parent_update( $parent_lower, $old_row, $new_row, $evaluator ) {
		if ( ! $this->foreign_keys_enabled ) {
			return;
		}
		$parent = $this->db['tables'][ $parent_lower ];
		foreach ( $this->db['tables'] as $child_lower => $child ) {
			foreach ( $child['fks'] as $fk ) {
				if ( $this->resolve_table_key( strtolower( $fk['ref_table'] ) ) !== $parent_lower ) {
					continue;
				}
				$ref_cols = $this->foreign_key_parent_columns( $fk, $parent );
				if ( 0 === count( $ref_cols ) ) {
					continue;
				}
				// Did the referenced key change?
				$changed = false;
				foreach ( $ref_cols as $ref_lower ) {
					$old_value = isset( $old_row[ $ref_lower ] ) ? $old_row[ $ref_lower ] : null;
					$new_value = isset( $new_row[ $ref_lower ] ) ? $new_row[ $ref_lower ] : null;
					if ( $old_value !== $new_value ) {
						$changed = true;
						break;
					}
				}
				if ( ! $changed ) {
					continue;
				}
				foreach ( $child['rows'] as $child_rowid => $child_row ) {
					$references = true;
					foreach ( $fk['cols'] as $position => $col ) {
						$child_value  = isset( $child_row[ strtolower( $col ) ] ) ? $child_row[ strtolower( $col ) ] : null;
						$parent_value = isset( $old_row[ $ref_cols[ $position ] ] ) ? $old_row[ $ref_cols[ $position ] ] : null;
						if ( null === $child_value || null === $parent_value
							|| 0 !== WP_PHP_Engine_Values::compare( $child_value, $parent_value ) ) {
							$references = false;
							break;
						}
					}
					if ( ! $references || $this->foreign_key_satisfied( $fk, $child_row ) ) {
						continue;
					}
					switch ( $fk['on_update'] ) {
						case 'CASCADE':
							$new_child = $this->db['tables'][ $child_lower ]['rows'][ $child_rowid ];
							foreach ( $fk['cols'] as $position => $col ) {
								$new_child[ strtolower( $col ) ] = isset( $new_row[ $ref_cols[ $position ] ] ) ? $new_row[ $ref_cols[ $position ] ] : null;
							}
							$this->update_row( $child_lower, $child_rowid, $new_child, $evaluator, array_map( 'strtolower', $fk['cols'] ) );
							break;
						case 'SET NULL':
						case 'SET DEFAULT':
							$new_child = $this->db['tables'][ $child_lower ]['rows'][ $child_rowid ];
							foreach ( $fk['cols'] as $col ) {
								$col_lower = strtolower( $col );
								if ( 'SET DEFAULT' === $fk['on_update'] || 'SET DEFAULT' === $fk['on_delete'] ) {
									$column                  = $child['columns'][ $col_lower ];
									$new_child[ $col_lower ] = $column['has_default'] && null !== $column['default']
										? $evaluator->eval( $column['default'], array() )
										: null;
								} else {
									$new_child[ $col_lower ] = null;
								}
							}
							$this->update_row( $child_lower, $child_rowid, $new_child, $evaluator, array_map( 'strtolower', $fk['cols'] ) );
							break;
						default:
							throw new WP_PHP_Engine_SQL_Exception(
								'FOREIGN KEY constraint failed',
								'23000',
								19,
								'Integrity constraint violation'
							);
					}
				}
			}
		}
	}

	/*
	 * ----------------------------------------------------------------------
	 * DDL.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Execute a CREATE TABLE statement.
	 *
	 * @param  array                   $statement The statement AST node.
	 * @param  WP_PHP_Engine_Evaluator $evaluator The evaluator.
	 * @return array                              The result.
	 */
	private function execute_create_table( $statement, $evaluator ) {
		$lower = strtolower( $statement['name'] );
		$key   = $statement['temp'] ? 'temp.' . $lower : $lower;
		if ( isset( $this->db['tables'][ $key ] )
			|| ( ! $statement['temp'] && isset( $this->db['views'][ $lower ] ) ) ) {
			if ( $statement['if_not_exists'] ) {
				return $this->empty_result();
			}
			throw new WP_PHP_Engine_SQL_Exception( 'table ' . $statement['name'] . ' already exists' );
		}

		$table = $this->build_table_meta( $statement );

		$this->db['tables'][ $key ] = $table;
		$lower                      = $key;
		if ( $table['autoincrement'] && ! $table['temp'] ) {
			$this->db['has_sequence_table'] = true;
		}

		// Create implicit indexes for PRIMARY KEY and UNIQUE constraints,
		// numbered in declaration order (column constraints come first).
		$autoindex = 0;
		foreach ( $statement['columns'] as $column ) {
			if ( $column['pk'] && null === $table['rowid_alias'] ) {
				$autoindex += 1;
				$this->add_autoindex(
					$lower,
					array(
						array(
							'name'    => $column['name'],
							'collate' => null,
							'dir'     => 'ASC',
						),
					),
					'pk',
					$autoindex
				);
			}
			if ( $column['unique'] ) {
				$autoindex += 1;
				$this->add_autoindex(
					$lower,
					array(
						array(
							'name'    => $column['name'],
							'collate' => null,
							'dir'     => 'ASC',
						),
					),
					'u',
					$autoindex
				);
			}
		}
		foreach ( $statement['constraints'] as $constraint ) {
			if ( 'pk' === $constraint['kind'] && null === $table['rowid_alias'] ) {
				$autoindex += 1;
				$this->add_autoindex( $lower, $constraint['cols'], 'pk', $autoindex );
			} elseif ( 'unique' === $constraint['kind'] ) {
				$autoindex += 1;
				$this->add_autoindex( $lower, $constraint['cols'], 'u', $autoindex );
			}
		}

		return $this->empty_result();
	}

	/**
	 * Build the table metadata from a CREATE TABLE statement.
	 *
	 * @param  array $statement The statement AST node.
	 * @return array            The table definition.
	 */
	private function build_table_meta( $statement ) {
		$columns = array();
		foreach ( $statement['columns'] as $column ) {
			$col_lower = strtolower( $column['name'] );
			if ( isset( $columns[ $col_lower ] ) ) {
				throw new WP_PHP_Engine_SQL_Exception( 'duplicate column name: ' . $column['name'] );
			}
			$columns[ $col_lower ] = array(
				'name'         => $column['name'],
				'type'         => $column['type'],
				'affinity'     => WP_PHP_Engine_Values::affinity_for_type( $column['type'] ),
				'collate'      => $column['collate'],
				'notnull'      => $column['notnull'],
				'default'      => $column['default'],
				'default_text' => isset( $column['default_text'] ) ? $column['default_text'] : null,
				'has_default'  => $column['has_default'],
			);
		}

		// Resolve the PRIMARY KEY.
		$pk_cols       = null;
		$pk_constraint = null;
		$pk_column     = null;
		foreach ( $statement['constraints'] as $constraint ) {
			if ( 'pk' === $constraint['kind'] ) {
				$pk_constraint = $constraint;
				$pk_cols       = array();
				foreach ( $constraint['cols'] as $col ) {
					$pk_cols[] = strtolower( $col['name'] );
				}
			}
		}
		foreach ( $statement['columns'] as $column ) {
			if ( $column['pk'] ) {
				if ( null !== $pk_cols ) {
					throw new WP_PHP_Engine_SQL_Exception(
						'table "' . $statement['name'] . '" has more than one primary key'
					);
				}
				$pk_column = $column;
				$pk_cols   = array( strtolower( $column['name'] ) );
			}
		}

		// PRIMARY KEY columns are implicitly NOT NULL in rowid tables...
		// except that SQLite, for backwards compatibility, allows NULLs in
		// non-INTEGER primary keys. We follow the strict/WITHOUT ROWID rule.
		$rowid_alias = null;
		if ( null !== $pk_column
			&& ! $statement['without_rowid']
			&& null !== $pk_column['type']
			&& 'INTEGER' === strtoupper( $pk_column['type'] )
			&& ! $pk_column['pk_desc'] ) {
			$rowid_alias = strtolower( $pk_column['name'] );
		}

		$autoincrement = null !== $pk_column && $pk_column['autoincrement'];

		// Collect CHECK constraints.
		$checks = array();
		foreach ( $statement['constraints'] as $constraint ) {
			if ( 'check' === $constraint['kind'] ) {
				$checks[] = array(
					'name' => $constraint['name'],
					'e'    => $constraint['e'],
				);
			}
		}
		foreach ( $statement['columns'] as $column ) {
			foreach ( $column['checks'] as $check ) {
				$checks[] = $check;
			}
		}

		// Collect FOREIGN KEY constraints.
		$fks = array();
		foreach ( $statement['constraints'] as $constraint ) {
			if ( 'fk' === $constraint['kind'] ) {
				$fks[] = $constraint['fk'];
			}
		}
		foreach ( $statement['columns'] as $column ) {
			if ( null !== $column['fk'] ) {
				$fks[] = $column['fk'];
			}
		}

		return array(
			'name'          => $statement['name'],
			'temp'          => $statement['temp'],
			'columns'       => $columns,
			'rowid_alias'   => $rowid_alias,
			'autoincrement' => $autoincrement,
			'strict'        => $statement['strict'],
			'without_rowid' => $statement['without_rowid'],
			'pk_cols'       => $pk_cols,
			'pk_constraint' => null !== $pk_constraint,
			'checks'        => $checks,
			'fks'           => $fks,
			'rows'          => array(),
			'sql'           => $statement['sql'],
		);
	}

	/**
	 * Add an implicit (auto) index for a PRIMARY KEY or UNIQUE constraint.
	 *
	 * @param string $lower  The lowercase table name.
	 * @param array  $cols   The indexed columns.
	 * @param string $origin The origin: 'pk' or 'u'.
	 * @param int    $number The autoindex ordinal.
	 */
	private function add_autoindex( $lower, $cols, $origin, $number ) {
		$name   = 'sqlite_autoindex_' . $this->db['tables'][ $lower ]['name'] . '_' . $number;
		$prefix = 0 === strpos( $lower, 'temp.' ) ? 'temp.' : '';
		$this->db['indexes'][ $prefix . strtolower( $name ) ] = array(
			'name'   => $name,
			'tbl'    => $lower,
			'unique' => true,
			'cols'   => $cols,
			'origin' => $origin,
			'sql'    => null,
		);
	}

	/**
	 * Execute a CREATE TABLE ... AS SELECT statement.
	 *
	 * @param  array                   $statement The statement AST node.
	 * @param  WP_PHP_Engine_Evaluator $evaluator The evaluator.
	 * @return array                              The result.
	 */
	private function execute_create_table_as( $statement, $evaluator ) {
		$lower = strtolower( $statement['name'] );
		if ( isset( $this->db['tables'][ $lower ] ) ) {
			if ( $statement['if_not_exists'] ) {
				return $this->empty_result();
			}
			throw new WP_PHP_Engine_SQL_Exception( 'table ' . $statement['name'] . ' already exists' );
		}
		$result  = $evaluator->select( $statement['sel'] );
		$columns = array();
		foreach ( $result['cols'] as $index => $name ) {
			$columns[ strtolower( $name ) ] = array(
				'name'        => $name,
				'type'        => isset( $result['decl'][ $index ] ) ? $result['decl'][ $index ] : null,
				'affinity'    => WP_PHP_Engine_Values::affinity_for_type( isset( $result['decl'][ $index ] ) ? $result['decl'][ $index ] : null ),
				'collate'     => null,
				'notnull'     => false,
				'default'     => null,
				'has_default' => false,
			);
		}
		$rows        = array();
		$next        = 1;
		$lower_names = array_keys( $columns );
		foreach ( $result['rows'] as $row ) {
			$assoc = array();
			foreach ( $lower_names as $position => $col_lower ) {
				$assoc[ $col_lower ] = isset( $row[ $position ] ) ? $row[ $position ] : null;
			}
			$rows[ $next ] = $assoc;
			$next         += 1;
		}
		$this->db['tables'][ $lower ] = array(
			'name'          => $statement['name'],
			'temp'          => $statement['temp'],
			'columns'       => $columns,
			'rowid_alias'   => null,
			'autoincrement' => false,
			'strict'        => false,
			'without_rowid' => false,
			'pk_cols'       => null,
			'pk_constraint' => false,
			'checks'        => array(),
			'fks'           => array(),
			'rows'          => $rows,
			'sql'           => $statement['sql'],
		);
		return $this->empty_result();
	}

	/**
	 * Execute a CREATE INDEX statement.
	 *
	 * @param  array                   $statement The statement AST node.
	 * @param  WP_PHP_Engine_Evaluator $evaluator The evaluator.
	 * @return array                              The result.
	 */
	private function execute_create_index( $statement, $evaluator ) {
		$table_key = $this->resolve_table_key( strtolower( $statement['tbl'] ) );
		if ( null === $table_key ) {
			throw new WP_PHP_Engine_SQL_Exception( 'no such table: main.' . $statement['tbl'] );
		}
		$prefix = 0 === strpos( $table_key, 'temp.' ) ? 'temp.' : '';
		$lower  = $prefix . strtolower( $statement['name'] );
		if ( isset( $this->db['indexes'][ $lower ] ) ) {
			if ( $statement['if_not_exists'] ) {
				return $this->empty_result();
			}
			throw new WP_PHP_Engine_SQL_Exception( 'index ' . $statement['name'] . ' already exists' );
		}
		$table       = $this->db['tables'][ $table_key ];
		$table_lower = $table_key;
		foreach ( $statement['cols'] as $col ) {
			if ( ! isset( $table['columns'][ strtolower( $col['name'] ) ] ) ) {
				throw new WP_PHP_Engine_SQL_Exception( 'no such column: ' . $col['name'] );
			}
		}

		$index = array(
			'name'   => $statement['name'],
			'tbl'    => $table_lower,
			'unique' => $statement['unique'],
			'cols'   => $statement['cols'],
			'origin' => 'c',
			'sql'    => $statement['sql'],
		);

		// Check existing rows for uniqueness violations.
		if ( $statement['unique'] ) {
			$seen = array();
			foreach ( $table['rows'] as $row ) {
				$key_parts = array();
				$has_null  = false;
				foreach ( $statement['cols'] as $col ) {
					$col_lower = strtolower( $col['name'] );
					$value     = isset( $row[ $col_lower ] ) ? $row[ $col_lower ] : null;
					if ( null === $value ) {
						$has_null = true;
						break;
					}
					$collation = null !== $col['collate']
						? $col['collate']
						: ( null !== $table['columns'][ $col_lower ]['collate'] ? $table['columns'][ $col_lower ]['collate'] : 'BINARY' );
					if ( 'NOCASE' === $collation && is_string( $value ) ) {
						$key_parts[] = 't:' . strtolower( $value );
					} else {
						$key_parts[] = WP_PHP_Engine_Evaluator::value_key( $value );
					}
				}
				if ( $has_null ) {
					continue;
				}
				$key = implode( '|', $key_parts );
				if ( isset( $seen[ $key ] ) ) {
					$names = array();
					foreach ( $statement['cols'] as $col ) {
						$names[] = $table['name'] . '.' . $table['columns'][ strtolower( $col['name'] ) ]['name'];
					}
					throw new WP_PHP_Engine_SQL_Exception(
						'UNIQUE constraint failed: ' . implode( ', ', $names ),
						'23000',
						19,
						'Integrity constraint violation'
					);
				}
				$seen[ $key ] = true;
			}
		}

		$this->db['indexes'][ $lower ] = $index;
		return $this->empty_result();
	}

	/**
	 * Execute a CREATE TRIGGER statement.
	 *
	 * @param  array $statement The statement AST node.
	 * @return array            The result.
	 */
	private function execute_create_trigger( $statement ) {
		$lower = strtolower( $statement['name'] );
		if ( isset( $this->db['triggers'][ $lower ] ) ) {
			if ( $statement['if_not_exists'] ) {
				return $this->empty_result();
			}
			throw new WP_PHP_Engine_SQL_Exception( 'trigger ' . $statement['name'] . ' already exists' );
		}
		if ( null === $this->get_table( strtolower( $statement['tbl'] ) ) ) {
			throw new WP_PHP_Engine_SQL_Exception( 'no such table: ' . $statement['tbl'] );
		}
		$this->db['triggers'][ $lower ] = array(
			'name'    => $statement['name'],
			'tbl'     => $statement['tbl'],
			'timing'  => $statement['timing'],
			'event'   => $statement['event'],
			'of_cols' => $statement['of_cols'],
			'when'    => $statement['when'],
			'body'    => $statement['body'],
			'temp'    => $statement['temp'],
			'sql'     => $statement['sql'],
		);
		return $this->empty_result();
	}

	/**
	 * Execute a CREATE VIEW statement.
	 *
	 * @param  array $statement The statement AST node.
	 * @return array            The result.
	 */
	private function execute_create_view( $statement ) {
		$lower = strtolower( $statement['name'] );
		if ( isset( $this->db['views'][ $lower ] ) || isset( $this->db['tables'][ $lower ] ) ) {
			if ( $statement['if_not_exists'] ) {
				return $this->empty_result();
			}
			throw new WP_PHP_Engine_SQL_Exception( 'table ' . $statement['name'] . ' already exists' );
		}
		$this->db['views'][ $lower ] = array(
			'name' => $statement['name'],
			'sel'  => $statement['sel'],
			'sql'  => $statement['sql'],
		);
		return $this->empty_result();
	}

	/**
	 * Execute a DROP statement.
	 *
	 * @param  array $statement The statement AST node.
	 * @return array            The result.
	 */
	private function execute_drop( $statement ) {
		$lower = strtolower( $statement['name'] );
		switch ( $statement['what'] ) {
			case 'table':
				$key = $this->resolve_table_key( $lower );
				if ( null === $key ) {
					if ( $statement['if_exists'] ) {
						return $this->empty_result();
					}
					throw new WP_PHP_Engine_SQL_Exception( 'no such table: ' . $statement['name'] );
				}
				$name = $this->db['tables'][ $key ]['name'];
				unset( $this->db['tables'][ $key ] );
				unset( $this->db['sequences'][ $name ] );
				foreach ( $this->db['indexes'] as $index_lower => $index ) {
					if ( $index['tbl'] === $key ) {
						unset( $this->db['indexes'][ $index_lower ] );
					}
				}
				foreach ( $this->db['triggers'] as $trigger_lower => $trigger ) {
					if ( strtolower( $trigger['tbl'] ) === self::bare_table_name( $key ) ) {
						unset( $this->db['triggers'][ $trigger_lower ] );
					}
				}
				break;
			case 'index':
				$key = isset( $this->db['indexes'][ 'temp.' . $lower ] ) ? 'temp.' . $lower : $lower;
				if ( ! isset( $this->db['indexes'][ $key ] ) || 'c' !== $this->db['indexes'][ $key ]['origin'] ) {
					if ( $statement['if_exists'] ) {
						return $this->empty_result();
					}
					throw new WP_PHP_Engine_SQL_Exception( 'no such index: ' . $statement['name'] );
				}
				unset( $this->db['indexes'][ $key ] );
				break;
			case 'trigger':
				if ( ! isset( $this->db['triggers'][ $lower ] ) ) {
					if ( $statement['if_exists'] ) {
						return $this->empty_result();
					}
					throw new WP_PHP_Engine_SQL_Exception( 'no such trigger: ' . $statement['name'] );
				}
				unset( $this->db['triggers'][ $lower ] );
				break;
			case 'view':
				if ( ! isset( $this->db['views'][ $lower ] ) ) {
					if ( $statement['if_exists'] ) {
						return $this->empty_result();
					}
					throw new WP_PHP_Engine_SQL_Exception( 'no such view: ' . $statement['name'] );
				}
				unset( $this->db['views'][ $lower ] );
				break;
		}
		if ( 0 === count( $this->transaction_stack ) ) {
			$this->save_to_disk();
		}
		return $this->empty_result();
	}

	/**
	 * Execute an ALTER TABLE ... RENAME TO statement.
	 *
	 * @param  array $statement The statement AST node.
	 * @return array            The result.
	 */
	private function execute_alter_rename_table( $statement ) {
		$lower = $this->resolve_table_key( strtolower( $statement['tbl'] ) );
		if ( null === $lower ) {
			throw new WP_PHP_Engine_SQL_Exception( 'no such table: ' . $statement['tbl'] );
		}
		$table     = $this->db['tables'][ $lower ];
		$prefix    = 0 === strpos( $lower, 'temp.' ) ? 'temp.' : '';
		$new_lower = $prefix . strtolower( $statement['new'] );
		if ( $new_lower !== $lower && ( isset( $this->db['tables'][ $new_lower ] ) || isset( $this->db['views'][ $new_lower ] ) ) ) {
			throw new WP_PHP_Engine_SQL_Exception( 'there is already another table or index with this name: ' . $statement['new'] );
		}

		$old_name      = $table['name'];
		$table['name'] = $statement['new'];

		// Rewrite the stored CREATE TABLE statement with the new name.
		if ( null !== $table['sql'] ) {
			$table['sql'] = preg_replace(
				'/(CREATE\s+(?:TEMP(?:ORARY)?\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?)(`(?:[^`]|``)+`|"(?:[^"]|"")+"|\[[^\]]+\]|[A-Za-z_][A-Za-z0-9_$]*)/i',
				'$1' . str_replace( '$', '\\$', '`' . str_replace( '`', '``', $statement['new'] ) . '`' ),
				$table['sql'],
				1
			);
		}

		unset( $this->db['tables'][ $lower ] );
		$this->db['tables'][ $new_lower ] = $table;

		// Update references in indexes, triggers, sequences, and foreign keys.
		foreach ( $this->db['indexes'] as $index_lower => $index ) {
			if ( $index['tbl'] === $lower ) {
				$this->db['indexes'][ $index_lower ]['tbl'] = $new_lower;
			}
		}
		foreach ( $this->db['triggers'] as $trigger_lower => $trigger ) {
			if ( strtolower( $trigger['tbl'] ) === self::bare_table_name( $lower ) ) {
				$this->db['triggers'][ $trigger_lower ]['tbl'] = $statement['new'];
			}
		}
		if ( isset( $this->db['sequences'][ $old_name ] ) ) {
			$this->db['sequences'][ $statement['new'] ] = $this->db['sequences'][ $old_name ];
			unset( $this->db['sequences'][ $old_name ] );
		}
		foreach ( $this->db['tables'] as $other_lower => $other ) {
			foreach ( $other['fks'] as $fk_index => $fk ) {
				if ( strtolower( $fk['ref_table'] ) === self::bare_table_name( $lower ) ) {
					$this->db['tables'][ $other_lower ]['fks'][ $fk_index ]['ref_table'] = $statement['new'];
				}
			}
		}

		if ( 0 === count( $this->transaction_stack ) ) {
			$this->save_to_disk();
		}
		return $this->empty_result();
	}

	/**
	 * Execute an ALTER TABLE ... RENAME COLUMN statement.
	 *
	 * @param  array $statement The statement AST node.
	 * @return array            The result.
	 */
	private function execute_alter_rename_column( $statement ) {
		$lower = $this->resolve_table_key( strtolower( $statement['tbl'] ) );
		if ( null === $lower ) {
			throw new WP_PHP_Engine_SQL_Exception( 'no such table: ' . $statement['tbl'] );
		}
		$table     = $this->db['tables'][ $lower ];
		$old_lower = strtolower( $statement['old'] );
		$new_lower = strtolower( $statement['new'] );
		if ( ! isset( $table['columns'][ $old_lower ] ) ) {
			throw new WP_PHP_Engine_SQL_Exception( 'no such column: "' . $statement['old'] . '"' );
		}
		if ( $new_lower !== $old_lower && isset( $table['columns'][ $new_lower ] ) ) {
			throw new WP_PHP_Engine_SQL_Exception( 'duplicate column name: ' . $statement['new'] );
		}

		// Rebuild the column map preserving order.
		$columns = array();
		foreach ( $table['columns'] as $col_lower => $column ) {
			if ( $col_lower === $old_lower ) {
				$column['name']        = $statement['new'];
				$columns[ $new_lower ] = $column;
			} else {
				$columns[ $col_lower ] = $column;
			}
		}
		$table['columns'] = $columns;

		// Rewrite rows.
		$rows = array();
		foreach ( $table['rows'] as $rowid => $row ) {
			$new_row = array();
			foreach ( $row as $col_lower => $value ) {
				$new_row[ $col_lower === $old_lower ? $new_lower : $col_lower ] = $value;
			}
			$rows[ $rowid ] = $new_row;
		}
		$table['rows'] = $rows;

		if ( $table['rowid_alias'] === $old_lower ) {
			$table['rowid_alias'] = $new_lower;
		}
		if ( null !== $table['pk_cols'] ) {
			foreach ( $table['pk_cols'] as $position => $pk_lower ) {
				if ( $pk_lower === $old_lower ) {
					$table['pk_cols'][ $position ] = $new_lower;
				}
			}
		}

		$this->db['tables'][ $lower ] = $table;

		// Update index column references.
		foreach ( $this->db['indexes'] as $index_lower => $index ) {
			if ( $index['tbl'] !== $lower ) {
				continue;
			}
			foreach ( $index['cols'] as $position => $col ) {
				if ( strtolower( $col['name'] ) === $old_lower ) {
					$this->db['indexes'][ $index_lower ]['cols'][ $position ]['name'] = $statement['new'];
				}
			}
		}

		if ( 0 === count( $this->transaction_stack ) ) {
			$this->save_to_disk();
		}
		return $this->empty_result();
	}

	/**
	 * Execute an ALTER TABLE ... ADD COLUMN statement.
	 *
	 * @param  array                   $statement The statement AST node.
	 * @param  WP_PHP_Engine_Evaluator $evaluator The evaluator.
	 * @return array                              The result.
	 */
	private function execute_alter_add_column( $statement, $evaluator ) {
		$lower = $this->resolve_table_key( strtolower( $statement['tbl'] ) );
		if ( null === $lower ) {
			throw new WP_PHP_Engine_SQL_Exception( 'no such table: ' . $statement['tbl'] );
		}
		$table     = $this->db['tables'][ $lower ];
		$column    = $statement['col'];
		$col_lower = strtolower( $column['name'] );
		if ( isset( $table['columns'][ $col_lower ] ) ) {
			throw new WP_PHP_Engine_SQL_Exception( 'duplicate column name: ' . $column['name'] );
		}

		$table['columns'][ $col_lower ] = array(
			'name'         => $column['name'],
			'type'         => $column['type'],
			'affinity'     => WP_PHP_Engine_Values::affinity_for_type( $column['type'] ),
			'collate'      => $column['collate'],
			'notnull'      => $column['notnull'],
			'default'      => $column['default'],
			'default_text' => isset( $column['default_text'] ) ? $column['default_text'] : null,
			'has_default'  => $column['has_default'],
		);
		foreach ( $column['checks'] as $check ) {
			$table['checks'][] = $check;
		}
		if ( null !== $column['fk'] ) {
			$table['fks'][] = $column['fk'];
		}

		// Existing rows get the default value.
		$default = $column['has_default'] && null !== $column['default']
			? $evaluator->eval( $column['default'], array() )
			: null;
		$default = WP_PHP_Engine_Values::apply_affinity( $default, $table['columns'][ $col_lower ]['affinity'] );
		foreach ( $table['rows'] as $rowid => $row ) {
			$table['rows'][ $rowid ][ $col_lower ] = $default;
		}

		// Update the stored CREATE TABLE statement (best effort: append the
		// column before the closing parenthesis).
		if ( null !== $table['sql'] ) {
			$position = strrpos( $table['sql'], ')' );
			if ( false !== $position ) {
				$rendered     = $this->render_column_definition_sql( $column );
				$table['sql'] = substr( $table['sql'], 0, $position ) . ', ' . $rendered . substr( $table['sql'], $position );
			}
		}

		$this->db['tables'][ $lower ] = $table;
		if ( 0 === count( $this->transaction_stack ) ) {
			$this->save_to_disk();
		}
		return $this->empty_result();
	}

	/**
	 * Execute an ALTER TABLE ... DROP COLUMN statement.
	 *
	 * @param  array $statement The statement AST node.
	 * @return array            The result.
	 */
	private function execute_alter_drop_column( $statement ) {
		$lower = $this->resolve_table_key( strtolower( $statement['tbl'] ) );
		if ( null === $lower ) {
			throw new WP_PHP_Engine_SQL_Exception( 'no such table: ' . $statement['tbl'] );
		}
		$table     = $this->db['tables'][ $lower ];
		$col_lower = strtolower( $statement['col'] );
		if ( ! isset( $table['columns'][ $col_lower ] ) ) {
			throw new WP_PHP_Engine_SQL_Exception( 'no such column: "' . $statement['col'] . '"' );
		}
		if ( $table['rowid_alias'] === $col_lower
			|| ( null !== $table['pk_cols'] && in_array( $col_lower, $table['pk_cols'], true ) ) ) {
			throw new WP_PHP_Engine_SQL_Exception( 'cannot drop PRIMARY KEY column: "' . $statement['col'] . '"' );
		}
		foreach ( $this->db['indexes'] as $index ) {
			if ( $index['tbl'] !== $lower ) {
				continue;
			}
			foreach ( $index['cols'] as $col ) {
				if ( strtolower( $col['name'] ) === $col_lower ) {
					throw new WP_PHP_Engine_SQL_Exception(
						'cannot drop column "' . $statement['col'] . '": indexed'
					);
				}
			}
		}

		unset( $table['columns'][ $col_lower ] );
		foreach ( $table['rows'] as $rowid => $row ) {
			unset( $table['rows'][ $rowid ][ $col_lower ] );
		}
		$this->db['tables'][ $lower ] = $table;
		if ( 0 === count( $this->transaction_stack ) ) {
			$this->save_to_disk();
		}
		return $this->empty_result();
	}

	/**
	 * Render a column definition back to SQL (for ALTER TABLE bookkeeping).
	 *
	 * @param  array $column The parsed column definition.
	 * @return string        The SQL text.
	 */
	private function render_column_definition_sql( $column ) {
		$sql = '`' . str_replace( '`', '``', $column['name'] ) . '`';
		if ( null !== $column['type'] ) {
			$sql .= ' ' . $column['type'];
		}
		if ( null !== $column['collate'] ) {
			$sql .= ' COLLATE ' . $column['collate'];
		}
		if ( $column['notnull'] ) {
			$sql .= ' NOT NULL';
		}
		if ( $column['has_default'] ) {
			$sql .= ' DEFAULT ' . $this->render_expr_sql( $column['default'] );
		}
		return $sql;
	}

	/**
	 * Render a simple expression back to SQL text (defaults, etc.).
	 *
	 * @param  array|null $expr The expression AST node.
	 * @return string           The SQL text.
	 */
	private function render_expr_sql( $expr ) {
		if ( null === $expr ) {
			return 'NULL';
		}
		switch ( $expr['t'] ) {
			case 'lit':
				if ( null === $expr['v'] ) {
					return 'NULL';
				}
				if ( is_int( $expr['v'] ) || is_float( $expr['v'] ) ) {
					return WP_PHP_Engine_Values::to_text( $expr['v'] );
				}
				if ( $expr['v'] instanceof WP_PHP_Engine_Blob ) {
					return "X'" . bin2hex( $expr['v']->bytes ) . "'";
				}
				return "'" . str_replace( "'", "''", $expr['v'] ) . "'";
			case 'now':
				return $expr['fn'];
			case 'un':
				return $expr['op'] . $this->render_expr_sql( $expr['e'] );
			case 'col':
				return $expr['name'];
			case 'fn':
				$args = array();
				foreach ( $expr['args'] as $arg ) {
					$args[] = $this->render_expr_sql( $arg );
				}
				return $expr['name'] . '(' . implode( ', ', $args ) . ')';
			case 'bin':
				return $this->render_expr_sql( $expr['l'] ) . ' ' . $expr['op'] . ' ' . $this->render_expr_sql( $expr['r'] );
		}
		return '';
	}

	/*
	 * ----------------------------------------------------------------------
	 * PRAGMA, virtual tables, and table-valued functions.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Execute a PRAGMA statement.
	 *
	 * @param  array $statement The statement AST node.
	 * @return array            The result.
	 */
	private function execute_pragma( $statement ) {
		$name  = $statement['name'];
		$value = $statement['value'];
		$arg   = $statement['arg'];

		switch ( $name ) {
			case 'foreign_keys':
				if ( null !== $value ) {
					$this->foreign_keys_enabled = in_array( strtoupper( (string) $value ), array( 'ON', 'TRUE', 'YES', '1' ), true );
					return $this->empty_result();
				}
				return $this->pragma_result( 'foreign_keys', array( array( $this->foreign_keys_enabled ? 1 : 0 ) ) );

			case 'foreign_key_check':
				$violations = $this->foreign_key_check( null !== $arg ? strtolower( (string) $arg ) : null );
				return array(
					'cols'    => array( 'table', 'rowid', 'parent', 'fkid' ),
					'rows'    => $violations,
					'decl'    => array( null, null, null, null ),
					'changes' => 0,
				);

			case 'integrity_check':
			case 'quick_check':
				if ( null !== $arg && null === $this->get_table( strtolower( (string) $arg ) ) ) {
					throw new WP_PHP_Engine_SQL_Exception( 'no such table: ' . $arg );
				}
				return $this->pragma_result( 'integrity_check', array( array( 'ok' ) ) );

			case 'journal_mode':
				return $this->pragma_result( 'journal_mode', array( array( 'memory' ) ) );

			case 'encoding':
				return $this->pragma_result( 'encoding', array( array( 'UTF-8' ) ) );

			case 'busy_timeout':
			case 'timeout':
				if ( null !== $value ) {
					$this->pragma_values['busy_timeout'] = (int) $value;
					return $this->pragma_result( 'timeout', array( array( (int) $value ) ) );
				}
				return $this->pragma_result(
					'timeout',
					array( array( isset( $this->pragma_values['busy_timeout'] ) ? $this->pragma_values['busy_timeout'] : 0 ) )
				);

			case 'table_info':
			case 'table_xinfo':
				$target = null !== $arg ? (string) $arg : (string) $value;
				$result = $this->table_function_result( 'pragma_' . $name, array( $target ) );
				return null !== $result ? $result : $this->empty_result();

			case 'index_list':
				$target = null !== $arg ? (string) $arg : (string) $value;
				$result = $this->table_function_result( 'pragma_index_list', array( $target ) );
				return null !== $result ? $result : $this->empty_result();

			case 'index_info':
			case 'index_xinfo':
				$target = null !== $arg ? (string) $arg : (string) $value;
				$result = $this->table_function_result( 'pragma_' . $name, array( $target ) );
				return null !== $result ? $result : $this->empty_result();

			case 'database_list':
				return array(
					'cols'    => array( 'seq', 'name', 'file' ),
					'rows'    => array( array( 0, 'main', null !== $this->path ? $this->path : '' ) ),
					'decl'    => array( null, null, null ),
					'changes' => 0,
				);

			default:
				// Store assignments; respond to known queries with defaults.
				if ( null !== $value ) {
					$this->pragma_values[ $name ] = $value;
					return $this->empty_result();
				}
				if ( isset( $this->pragma_values[ $name ] ) ) {
					return $this->pragma_result( $name, array( array( $this->pragma_values[ $name ] ) ) );
				}
				return $this->empty_result();
		}
	}

	/**
	 * Build a single-column PRAGMA result.
	 *
	 * @param  string $column The column name.
	 * @param  array  $rows   The rows.
	 * @return array          The result.
	 */
	private function pragma_result( $column, $rows ) {
		return array(
			'cols'    => array( $column ),
			'rows'    => $rows,
			'decl'    => array( null ),
			'changes' => 0,
		);
	}

	/**
	 * Run a full foreign key check.
	 *
	 * @param  string|null $only_table A lowercase table name to limit the check.
	 * @return array                   The violation rows: (table, rowid, parent, fkid).
	 */
	private function foreign_key_check( $only_table ) {
		$only_key   = null !== $only_table ? $this->resolve_table_key( $only_table ) : null;
		$violations = array();
		foreach ( $this->db['tables'] as $lower => $table ) {
			if ( null !== $only_key && $lower !== $only_key ) {
				continue;
			}
			foreach ( $table['fks'] as $fk_index => $fk ) {
				foreach ( $table['rows'] as $rowid => $row ) {
					if ( ! $this->foreign_key_satisfied( $fk, $row ) ) {
						$violations[] = array( $table['name'], $rowid, $fk['ref_table'], $fk_index );
					}
				}
			}
		}
		return $violations;
	}

	/**
	 * Get a virtual table result by name (sqlite_master and friends).
	 *
	 * @param  string $lower The lowercase table name.
	 * @return array|null    The result set, or null if not a virtual table.
	 */
	public function virtual_table_result( $lower ) {
		if ( 'sqlite_master' === $lower || 'sqlite_schema' === $lower || 'sqlite_temp_master' === $lower || 'sqlite_temp_schema' === $lower ) {
			$want_temp = 'sqlite_temp_master' === $lower || 'sqlite_temp_schema' === $lower;
			$rows      = array();
			$rootpage  = 2;

			$has_autoincrement = false;
			foreach ( $this->db['tables'] as $table_key => $table ) {
				if ( ( 0 === strpos( $table_key, 'temp.' ) ) !== $want_temp ) {
					continue;
				}
				if ( $table['autoincrement'] && ! $table['temp'] ) {
					$has_autoincrement = true;
				}
				$rows[]    = array( 'table', $table['name'], $table['name'], $rootpage, $table['sql'] );
				$rootpage += 1;
			}
			if ( $has_autoincrement && ! $want_temp ) {
				$rows[]    = array( 'table', 'sqlite_sequence', 'sqlite_sequence', $rootpage, 'CREATE TABLE sqlite_sequence(name,seq)' );
				$rootpage += 1;
			}
			foreach ( $this->db['indexes'] as $index_key => $index ) {
				if ( ( 0 === strpos( $index_key, 'temp.' ) ) !== $want_temp || ! isset( $this->db['tables'][ $index['tbl'] ] ) ) {
					continue;
				}
				$table     = $this->db['tables'][ $index['tbl'] ];
				$rows[]    = array( 'index', $index['name'], $table['name'], $rootpage, $index['sql'] );
				$rootpage += 1;
			}
			foreach ( $this->db['triggers'] as $trigger ) {
				$is_temp = ! empty( $trigger['temp'] );
				if ( $is_temp !== $want_temp ) {
					continue;
				}
				$rows[] = array( 'trigger', $trigger['name'], $trigger['tbl'], 0, $trigger['sql'] );
			}
			foreach ( $this->db['views'] as $view ) {
				if ( $want_temp ) {
					continue;
				}
				$rows[] = array( 'view', $view['name'], $view['name'], 0, $view['sql'] );
			}
			return array(
				'cols' => array( 'type', 'name', 'tbl_name', 'rootpage', 'sql' ),
				'rows' => $rows,
				'decl' => array( 'TEXT', 'TEXT', 'TEXT', 'INT', 'TEXT' ),
			);
		}

		if ( 'sqlite_sequence' === $lower ) {
			// The sqlite_sequence table only exists once an AUTOINCREMENT
			// table has been created in the database.
			if ( empty( $this->db['has_sequence_table'] ) ) {
				return null;
			}
			$rows = array();
			foreach ( $this->db['sequences'] as $name => $seq ) {
				$rows[] = array( $name, $seq );
			}
			return array(
				'cols' => array( 'name', 'seq' ),
				'rows' => $rows,
				'decl' => array( null, null ),
			);
		}

		return null;
	}

	/**
	 * Delete rows from the sqlite_sequence virtual table.
	 *
	 * This is used by DELETE statements that target sqlite_sequence.
	 *
	 * @param string|null $name The sequence (table) name, or null for all.
	 */
	public function delete_sequences( $name ) {
		if ( null === $name ) {
			$this->db['sequences'] = array();
			return;
		}
		unset( $this->db['sequences'][ $name ] );
	}

	/**
	 * Get a table-valued function result.
	 *
	 * @param  string $name The lowercase function name.
	 * @param  array  $args The evaluated arguments.
	 * @return array|null   The result set, or null for unknown functions.
	 */
	public function table_function_result( $name, $args ) {
		switch ( $name ) {
			case 'pragma_table_info':
			case 'pragma_table_xinfo':
				$table = $this->get_table( strtolower( (string) $args[0] ) );
				if ( null === $table ) {
					return array(
						'cols' => array( 'cid', 'name', 'type', 'notnull', 'dflt_value', 'pk' ),
						'rows' => array(),
						'decl' => array( null, null, null, null, null, null ),
					);
				}
				$rows = array();
				$cid  = 0;
				foreach ( $table['columns'] as $col_lower => $column ) {
					$pk = 0;
					if ( null !== $table['pk_cols'] ) {
						$position = array_search( $col_lower, $table['pk_cols'], true );
						if ( false !== $position ) {
							$pk = $position + 1;
						}
					}
					$dflt = null;
					if ( $column['has_default'] ) {
						$dflt = isset( $column['default_text'] ) && null !== $column['default_text']
							? $column['default_text']
							: $this->render_expr_sql( $column['default'] );
					}
					$row = array(
						$cid,
						$column['name'],
						null !== $column['type'] ? $column['type'] : '',
						$column['notnull'] ? 1 : 0,
						$dflt,
						$pk,
					);
					if ( 'pragma_table_xinfo' === $name ) {
						$row[] = 0; // hidden.
					}
					$rows[] = $row;
					$cid   += 1;
				}
				$cols = array( 'cid', 'name', 'type', 'notnull', 'dflt_value', 'pk' );
				if ( 'pragma_table_xinfo' === $name ) {
					$cols[] = 'hidden';
				}
				return array(
					'cols' => $cols,
					'rows' => $rows,
					'decl' => array_fill( 0, count( $cols ), null ),
				);

			case 'pragma_index_list':
				$table_lower = $this->resolve_table_key( strtolower( (string) $args[0] ) );
				$rows        = array();
				$seq         = 0;
				$indexes     = array_reverse( $this->db['indexes'], true );
				foreach ( $indexes as $index ) {
					if ( $index['tbl'] !== $table_lower ) {
						continue;
					}
					$rows[] = array( $seq, $index['name'], $index['unique'] ? 1 : 0, $index['origin'], 0 );
					$seq   += 1;
				}
				return array(
					'cols' => array( 'seq', 'name', 'unique', 'origin', 'partial' ),
					'rows' => $rows,
					'decl' => array( null, null, null, null, null ),
				);

			case 'pragma_index_info':
			case 'pragma_index_xinfo':
				$index_lower = strtolower( (string) $args[0] );
				if ( isset( $this->db['indexes'][ 'temp.' . $index_lower ] ) ) {
					$index_lower = 'temp.' . $index_lower;
				}
				if ( ! isset( $this->db['indexes'][ $index_lower ] ) ) {
					$cols = 'pragma_index_xinfo' === $name
						? array( 'seqno', 'cid', 'name', 'desc', 'coll', 'key' )
						: array( 'seqno', 'cid', 'name' );
					return array(
						'cols' => $cols,
						'rows' => array(),
						'decl' => array_fill( 0, count( $cols ), null ),
					);
				}
				$index = $this->db['indexes'][ $index_lower ];
				$table = $this->get_table( $index['tbl'] );
				$rows  = array();
				$seqno = 0;
				foreach ( $index['cols'] as $col ) {
					$cid       = -1;
					$col_lower = strtolower( $col['name'] );
					if ( null !== $table ) {
						$position = array_search( $col_lower, array_keys( $table['columns'] ), true );
						if ( false !== $position ) {
							$cid = $position;
						}
					}
					$col_name  = null !== $table && isset( $table['columns'][ $col_lower ] )
						? $table['columns'][ $col_lower ]['name']
						: $col['name'];
					$collation = $col['collate'];
					if ( null === $collation && null !== $table && isset( $table['columns'][ $col_lower ] ) ) {
						$collation = $table['columns'][ $col_lower ]['collate'];
					}
					if ( 'pragma_index_xinfo' === $name ) {
						$rows[] = array( $seqno, $cid, $col_name, 'DESC' === $col['dir'] ? 1 : 0, null !== $collation ? $collation : 'BINARY', 1 );
					} else {
						$rows[] = array( $seqno, $cid, $col_name );
					}
					$seqno += 1;
				}
				if ( 'pragma_index_xinfo' === $name ) {
					return array(
						'cols' => array( 'seqno', 'cid', 'name', 'desc', 'coll', 'key' ),
						'rows' => $rows,
						'decl' => array( null, null, null, null, null, null ),
					);
				}
				return array(
					'cols' => array( 'seqno', 'cid', 'name' ),
					'rows' => $rows,
					'decl' => array( null, null, null ),
				);
		}
		return null;
	}

	/*
	 * ----------------------------------------------------------------------
	 * Persistence.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Load the database state from disk.
	 */
	private function load_from_disk() {
		$data = file_get_contents( $this->path );
		if ( false === $data || '' === $data ) {
			return;
		}
		$db = unserialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		if ( is_array( $db ) && isset( $db['tables'] ) ) {
			$this->db = $db;
		}
	}

	/**
	 * Save the database state to disk (for file-backed databases).
	 */
	private function save_to_disk() {
		if ( null === $this->path ) {
			return;
		}
		// Temporary tables are not persisted.
		$db = $this->db;
		foreach ( $db['tables'] as $lower => $table ) {
			if ( $table['temp'] ) {
				unset( $db['tables'][ $lower ] );
			}
		}
		file_put_contents( $this->path, serialize( $db ), LOCK_EX ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
	}
}

/**
 * A wrapper exception marking constraint violations that OR IGNORE can skip.
 */
class WP_PHP_Engine_Constraint_Exception extends Exception {
	/**
	 * The wrapped exception.
	 *
	 * @var WP_PHP_Engine_SQL_Exception
	 */
	public $inner;

	/**
	 * Constructor.
	 *
	 * @param WP_PHP_Engine_SQL_Exception $inner The wrapped exception.
	 */
	public function __construct( $inner ) {
		parent::__construct( $inner->getMessage() );
		$this->inner = $inner;
	}
}
