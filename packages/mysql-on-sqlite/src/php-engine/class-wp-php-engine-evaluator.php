<?php

/*
 * The file contains the internal RAISE(IGNORE) exception class as well:
 *   phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
 */

/**
 * Query evaluation for the pure-PHP database engine.
 *
 * This is a part of the pure-PHP database engine ("WP_PHP_Engine") — an
 * SQLite-compatible database engine implemented entirely in PHP.
 *
 * This class implements the SELECT pipeline (FROM/JOIN resolution, WHERE,
 * GROUP BY/HAVING, window functions, DISTINCT, compound queries, ORDER BY,
 * LIMIT) and the expression evaluator with SQLite's comparison, affinity,
 * and collation semantics.
 *
 * Scopes are represented as a stack of frames. Each frame is a list of
 * slots, one per table (or subquery) visible in the current row context:
 *
 *   array(
 *     'alias' => string|null,         // lowercase alias or table name
 *     'cols'  => array,               // lowercase column name => value
 *     'names' => array,               // lowercase column name => original name
 *     'aff'   => array,               // lowercase column name => affinity
 *     'coll'  => array,               // lowercase column name => collation
 *     'decl'  => array,               // lowercase column name => declared type
 *     'rowid' => int|null,            // the rowid, if the source is a table
 *   )
 */
class WP_PHP_Engine_Evaluator {
	/**
	 * The engine instance.
	 *
	 * @var WP_PHP_Engine
	 */
	private $engine;

	/**
	 * Bound parameter values.
	 *
	 * @var array
	 */
	private $params;

	/**
	 * The scope stack (a list of frames, innermost last).
	 *
	 * @var array
	 */
	public $scopes = array();

	/**
	 * Common table expressions visible in the current statement.
	 *
	 * A map of lowercase name => array( 'cols' => ?, 'sel' => AST ) or
	 * array( 'materialized' => result ).
	 *
	 * @var array
	 */
	public $ctes = array();

	/**
	 * Constructor.
	 *
	 * @param WP_PHP_Engine $engine The engine instance.
	 * @param array         $params The bound parameter values.
	 */
	public function __construct( $engine, $params = array() ) {
		$this->engine = $engine;
		$this->params = $params;
	}

	/*
	 * ----------------------------------------------------------------------
	 * SELECT pipeline.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Execute a select node and return the result.
	 *
	 * @param  array $select The select AST node.
	 * @return array         The result: array( 'cols', 'rows', 'decl' ).
	 */
	public function select( $select ) {
		$saved_ctes = $this->ctes;
		if ( ! empty( $select['with'] ) ) {
			foreach ( $select['with'] as $name => $cte ) {
				$this->ctes[ $name ] = $cte;
			}
		}

		// Evaluate all compound parts.
		$results = array();
		foreach ( $select['parts'] as $part ) {
			$results[] = $this->select_core( $part );
		}

		$result = $results[0];
		$count  = count( $results );
		if ( $count > 1 && isset( $result['srctable'] ) ) {
			$result['srctable'] = array_fill( 0, count( $result['cols'] ), null );
		}
		for ( $i = 1; $i < $count; $i++ ) {
			$op    = $select['ops'][ $i - 1 ];
			$other = $results[ $i ];
			if ( count( $other['cols'] ) !== count( $result['cols'] ) ) {
				throw new WP_PHP_Engine_SQL_Exception(
					'SELECTs to the left and right of ' . $op . ' do not have the same number of result columns'
				);
			}
			switch ( $op ) {
				case 'UNION ALL':
					foreach ( $other['rows'] as $row ) {
						$result['rows'][] = $row;
					}
					$result['frames'] = null;
					break;
				case 'UNION':
					$seen = array();
					foreach ( $result['rows'] as $row ) {
						$seen[ self::row_key( $row ) ] = true;
					}
					$merged = $result['rows'];
					foreach ( $other['rows'] as $row ) {
						$key = self::row_key( $row );
						if ( ! isset( $seen[ $key ] ) ) {
							$seen[ $key ] = true;
							$merged[]     = $row;
						}
					}
					// UNION also dedupes the left side.
					$deduped = array();
					$seen    = array();
					foreach ( $merged as $row ) {
						$key = self::row_key( $row );
						if ( ! isset( $seen[ $key ] ) ) {
							$seen[ $key ] = true;
							$deduped[]    = $row;
						}
					}
					$result['rows']   = $deduped;
					$result['frames'] = null;
					break;
				case 'EXCEPT':
					$exclude = array();
					foreach ( $other['rows'] as $row ) {
						$exclude[ self::row_key( $row ) ] = true;
					}
					$kept = array();
					$seen = array();
					foreach ( $result['rows'] as $row ) {
						$key = self::row_key( $row );
						if ( ! isset( $exclude[ $key ] ) && ! isset( $seen[ $key ] ) ) {
							$seen[ $key ] = true;
							$kept[]       = $row;
						}
					}
					$result['rows']   = $kept;
					$result['frames'] = null;
					break;
				case 'INTERSECT':
					$include = array();
					foreach ( $other['rows'] as $row ) {
						$include[ self::row_key( $row ) ] = true;
					}
					$kept = array();
					$seen = array();
					foreach ( $result['rows'] as $row ) {
						$key = self::row_key( $row );
						if ( isset( $include[ $key ] ) && ! isset( $seen[ $key ] ) ) {
							$seen[ $key ] = true;
							$kept[]       = $row;
						}
					}
					$result['rows']   = $kept;
					$result['frames'] = null;
					break;
			}
		}

		// ORDER BY.
		if ( ! empty( $select['order'] ) ) {
			$this->sort_result( $result, $select['order'] );
		}

		// LIMIT and OFFSET.
		if ( null !== $select['limit'] ) {
			$limit  = $this->eval( $select['limit'], array() );
			$limit  = null === $limit ? -1 : (int) $limit;
			$offset = 0;
			if ( null !== $select['offset'] ) {
				$offset = (int) $this->eval( $select['offset'], array() );
				if ( $offset < 0 ) {
					$offset = 0;
				}
			}
			if ( $limit >= 0 ) {
				$result['rows'] = array_slice( $result['rows'], $offset, $limit );
			} elseif ( $offset > 0 ) {
				$result['rows'] = array_slice( $result['rows'], $offset );
			}
		}

		unset( $result['frames'], $result['items'], $result['positions'], $result['proto'] );
		$this->ctes = $saved_ctes;
		return $result;
	}

	/**
	 * Execute one core SELECT or VALUES part.
	 *
	 * @param  array $core The core AST node.
	 * @return array       The result: array( 'cols', 'rows', 'decl', 'frames' ).
	 */
	private function select_core( $core ) {
		if ( 'values' === $core['t'] ) {
			$rows  = array();
			$width = 0;
			foreach ( $core['rows'] as $row_exprs ) {
				$row = array();
				foreach ( $row_exprs as $expr ) {
					$row[] = $this->eval( $expr, array() );
				}
				$width  = max( $width, count( $row ) );
				$rows[] = $row;
			}
			$cols = array();
			for ( $i = 1; $i <= $width; $i++ ) {
				$cols[] = 'column' . $i;
			}
			return array(
				'cols'    => $cols,
				'rows'    => $rows,
				'decl'    => array_fill( 0, $width, null ),
				'coll'    => array_fill( 0, $width, 'BINARY' ),
				'aliased' => array_fill( 0, $width, true ),
				'frames'  => null,
			);
		}

		// Resolve FROM into a schema prototype and a list of frames
		// (each frame is a list of slots).
		if ( null === $core['from'] ) {
			$frames = array( array() ); // A single row with no columns.
			$proto  = array();
		} else {
			$resolved = $this->resolve_from( $core['from'] );
			$frames   = $resolved['frames'];
			$proto    = $resolved['proto'];
		}

		// SQLite allows result-column aliases in WHERE and HAVING clauses;
		// substitute alias references that do not resolve to real columns.
		$where_expr  = null !== $core['where'] ? $this->substitute_aliases( $core['where'], $core['items'], $proto ) : null;
		$having_expr = null !== $core['having'] ? $this->substitute_aliases( $core['having'], $core['items'], $proto ) : null;

		// WHERE.
		if ( null !== $where_expr ) {
			$filtered = array();
			foreach ( $frames as $frame ) {
				if ( WP_PHP_Engine_Values::is_truthy( $this->eval( $where_expr, $frame ) ) ) {
					$filtered[] = $frame;
				}
			}
			$frames = $filtered;
		}

		// Detect aggregate usage.
		$has_aggregates = false;
		foreach ( $core['items'] as $item ) {
			if ( isset( $item['e'] ) && $this->contains_aggregate( $item['e'] ) ) {
				$has_aggregates = true;
				break;
			}
		}
		if ( null !== $having_expr && $this->contains_aggregate( $having_expr ) ) {
			$has_aggregates = true;
		}

		if ( null !== $having_expr && null === $core['group'] ) {
			$has_aggregates = true;
		}

		$grouped = null;
		if ( null !== $core['group'] ) {
			$grouped = array();
			foreach ( $frames as $frame ) {
				$key_parts = array();
				foreach ( $core['group'] as $group_expr ) {
					// Positional GROUP BY (e.g. GROUP BY 1) refers to a select item.
					$resolved  = $this->resolve_positional_or_alias( $group_expr, $core['items'] );
					$value     = $this->eval( $resolved, $frame );
					$collation = $this->expr_collation( $resolved, $frame );
					if ( 'NOCASE' === $collation && is_string( $value ) ) {
						$key_parts[] = 't:' . strtolower( $value );
					} else {
						$key_parts[] = self::value_key( $value );
					}
				}
				$key = implode( '|', $key_parts );
				if ( ! isset( $grouped[ $key ] ) ) {
					$grouped[ $key ] = array();
				}
				$grouped[ $key ][] = $frame;
			}
			$grouped = array_values( $grouped );
		} elseif ( $has_aggregates ) {
			// Implicit single group over all rows.
			$grouped = array( $frames );
		}

		// The output schema (column names and declared types) comes from the
		// relation prototype, so that it is correct even with zero rows.
		list( $cols, $decl, $coll, $item_positions, $aliased_flags, $srctable ) = $this->project_schema( $core['items'], $proto );

		// SQLite resolves names at prepare time; with no input rows, the
		// clauses are never evaluated, so resolve them once explicitly.
		if ( 0 === count( $frames ) ) {
			if ( null !== $where_expr ) {
				$this->eval( $where_expr, $proto );
			}
			if ( null !== $core['group'] ) {
				foreach ( $core['group'] as $group_expr ) {
					$this->eval( $this->resolve_positional_or_alias( $group_expr, $core['items'] ), $proto );
				}
			}
			if ( null !== $having_expr ) {
				$this->eval_in_group( $having_expr, $proto, array() );
			}
		}

		$rows       = array();
		$row_frames = array();

		if ( null !== $grouped ) {
			foreach ( $grouped as $group ) {
				$frame = count( $group ) > 0 ? $group[0] : $proto;
				if ( null !== $having_expr ) {
					$having = $this->eval_in_group( $having_expr, $frame, $group );
					if ( ! WP_PHP_Engine_Values::is_truthy( $having ) ) {
						continue;
					}
				}
				$rows[]       = $this->project_row( $core['items'], $frame, $group );
				$row_frames[] = $frame;
			}
			// An empty implicit group still yields one row of aggregates.
			if ( null === $core['group'] && $has_aggregates && 0 === count( $grouped ) ) {
				$rows       = array( $this->project_row( $core['items'], $proto, array() ) );
				$row_frames = array( $proto );
			}
		} else {
			foreach ( $frames as $frame ) {
				$rows[]       = $this->project_row( $core['items'], $frame, null );
				$row_frames[] = $frame;
			}
		}

		// Window functions.
		$this->compute_window_functions( $core['items'], $rows, $row_frames, $cols );

		// DISTINCT.
		if ( $core['distinct'] ) {
			$seen           = array();
			$deduped        = array();
			$deduped_frames = array();
			foreach ( $rows as $index => $row ) {
				$key = self::row_key( $row );
				if ( ! isset( $seen[ $key ] ) ) {
					$seen[ $key ]     = true;
					$deduped[]        = $row;
					$deduped_frames[] = $row_frames[ $index ];
				}
			}
			$rows       = $deduped;
			$row_frames = $deduped_frames;
		}

		return array(
			'cols'      => $cols,
			'rows'      => $rows,
			'decl'      => $decl,
			'coll'      => $coll,
			'aliased'   => $aliased_flags,
			'srctable'  => $srctable,
			'frames'    => $row_frames,
			'items'     => $core['items'],
			'positions' => $item_positions,
			'proto'     => $proto,
		);
	}

	/**
	 * Project the output schema (column names and declared types) of a
	 * select item list against a relation prototype.
	 *
	 * @param  array $items The select item AST nodes.
	 * @param  array $proto The relation prototype frame.
	 * @return array        The column names and declared types.
	 */
	private function project_schema( $items, $proto ) {
		$cols      = array();
		$decl      = array();
		$coll      = array();
		$aliased   = array();
		$srctable  = array();
		$positions = array();
		foreach ( $items as $index => $item ) {
			$positions[ $index ] = count( $cols );
			if ( ! empty( $item['star'] ) ) {
				$matched = false;
				foreach ( $proto as $slot ) {
					if ( null !== $item['tbl'] && strtolower( $item['tbl'] ) !== $slot['alias'] ) {
						continue;
					}
					$matched = true;
					foreach ( $slot['names'] as $lower => $original ) {
						$cols[]     = $original;
						$decl[]     = isset( $slot['decl'][ $lower ] ) ? $slot['decl'][ $lower ] : null;
						$coll[]     = isset( $slot['coll'][ $lower ] ) ? $slot['coll'][ $lower ] : 'BINARY';
						$aliased[]  = empty( $slot['has_rowid'] );
						$srctable[] = isset( $slot['srct'][ $lower ] ) ? $slot['srct'][ $lower ] : null;
					}
				}
				if ( ! $matched && null !== $item['tbl'] ) {
					throw new WP_PHP_Engine_SQL_Exception( 'no such table: ' . $item['tbl'] );
				}
				continue;
			}

			$expr = $item['e'];
			if ( null !== $item['alias'] ) {
				$cols[]    = $item['alias'];
				$aliased[] = true;
			} elseif ( 'col' === $expr['t'] ) {
				$cols[]    = $this->column_output_name( $expr, $proto );
				$aliased[] = $this->column_is_aliased( $expr, $proto );
			} else {
				$cols[]    = isset( $item['text'] ) ? $item['text'] : '?';
				$aliased[] = true;
			}
			$srctable[] = 'col' === $expr['t'] ? $this->column_source_table( $expr, $proto ) : null;

			// Only real column references carry a declared type, like in
			// SQLite (sqlite3_column_decltype).
			if ( 'col' === $expr['t'] ) {
				$decl[] = $this->column_decl_type( $expr, $proto );
			} else {
				$decl[] = null;
			}
			$coll[] = $this->expr_collation( $expr, $proto );
		}
		return array( $cols, $decl, $coll, $positions, $aliased, $srctable );
	}

	/**
	 * Get the source table of a bare column reference, when it resolves
	 * (possibly through subqueries) to a real table column.
	 *
	 * @param  array $expr  The column expression.
	 * @param  array $proto The relation prototype frame.
	 * @return string|null   The source table name, if any.
	 */
	private function column_source_table( $expr, $proto ) {
		$lower = strtolower( $expr['name'] );
		foreach ( $proto as $slot ) {
			if ( null !== $expr['tbl'] && strtolower( $expr['tbl'] ) !== $slot['alias'] ) {
				continue;
			}
			if ( isset( $slot['srct'][ $lower ] ) ) {
				return $slot['srct'][ $lower ];
			}
			if ( isset( $slot['names'][ $lower ] ) ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * Check whether a bare column reference resolves to an aliased (sticky)
	 * output name. References to real table columns are not sticky, so an
	 * outer query referencing them shows the name as written.
	 *
	 * @param  array $expr  The column expression.
	 * @param  array $proto The relation prototype frame.
	 * @return bool         Whether the name is sticky.
	 */
	private function column_is_aliased( $expr, $proto ) {
		$lower = strtolower( $expr['name'] );
		foreach ( $proto as $slot ) {
			if ( null !== $expr['tbl'] && strtolower( $expr['tbl'] ) !== $slot['alias'] ) {
				continue;
			}
			if ( isset( $slot['names'][ $lower ] ) ) {
				if ( ! empty( $slot['has_rowid'] ) ) {
					return false;
				}
				return ! isset( $slot['aliased'][ $lower ] ) || $slot['aliased'][ $lower ];
			}
		}
		return false;
	}

	/**
	 * Project select item values for a single row context.
	 *
	 * @param  array      $items The select item AST nodes.
	 * @param  array      $frame The current frame.
	 * @param  array|null $group The group rows (for aggregate context).
	 * @return array             The row values.
	 */
	private function project_row( $items, $frame, $group ) {
		$row = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['star'] ) ) {
				foreach ( $frame as $slot ) {
					if ( null !== $item['tbl'] && strtolower( $item['tbl'] ) !== $slot['alias'] ) {
						continue;
					}
					foreach ( $slot['names'] as $lower => $original ) {
						$row[] = isset( $slot['cols'][ $lower ] ) || array_key_exists( $lower, $slot['cols'] )
							? $slot['cols'][ $lower ]
							: null;
					}
				}
				continue;
			}
			// Window function values are computed later over the whole
			// result set; emit a placeholder for now.
			if ( 'fn' === $item['e']['t'] && null !== $item['e']['over'] ) {
				$row[] = null;
				continue;
			}
			if ( null !== $group ) {
				$row[] = $this->eval_in_group( $item['e'], $frame, $group );
			} else {
				$row[] = $this->eval( $item['e'], $frame );
			}
		}
		return $row;
	}

	/**
	 * Get the output column name for a bare column reference, validating
	 * that the column exists.
	 *
	 * @param  array $expr  The column expression.
	 * @param  array $frame The current frame.
	 * @return string       The original-case column name.
	 */
	private function column_output_name( $expr, $frame ) {
		$lower = strtolower( $expr['name'] );
		foreach ( $frame as $slot ) {
			if ( null !== $expr['tbl'] && strtolower( $expr['tbl'] ) !== $slot['alias'] ) {
				continue;
			}
			if ( isset( $slot['names'][ $lower ] ) ) {
				// References to real table columns use the declared name.
				if ( ! empty( $slot['has_rowid'] ) ) {
					return $slot['names'][ $lower ];
				}
				// References to subquery outputs keep aliased (sticky) names;
				// plain pass-through column names are shown as written.
				$is_sticky = ! isset( $slot['aliased'][ $lower ] ) || $slot['aliased'][ $lower ];
				return $is_sticky ? $slot['names'][ $lower ] : $expr['name'];
			}
		}

		// rowid aliases resolve against table slots.
		if ( in_array( $lower, array( 'rowid', '_rowid_', 'oid' ), true ) ) {
			foreach ( $frame as $slot ) {
				if ( ( null === $expr['tbl'] || strtolower( $expr['tbl'] ) === $slot['alias'] ) && ! empty( $slot['has_rowid'] ) ) {
					return $expr['name'];
				}
			}
		}

		// Outer scopes (correlated subqueries) and trigger NEW/OLD rows.
		$tbl = null !== $expr['tbl'] ? strtolower( $expr['tbl'] ) : null;
		for ( $i = count( $this->scopes ) - 1; $i >= 0; $i-- ) {
			if ( null !== $this->find_column( $this->scopes[ $i ], $tbl, $lower ) ) {
				return $expr['name'];
			}
			foreach ( $this->scopes[ $i ] as $slot ) {
				if ( ( null === $tbl || $tbl === $slot['alias'] ) && in_array( $lower, array( 'rowid', '_rowid_', 'oid' ), true ) ) {
					return $expr['name'];
				}
			}
		}

		throw new WP_PHP_Engine_SQL_Exception(
			'no such column: ' . ( null !== $expr['tbl'] ? $expr['tbl'] . '.' : '' ) . $expr['name']
		);
	}

	/**
	 * Get the declared type for a bare column reference.
	 *
	 * @param  array $expr  The column expression.
	 * @param  array $frame The current frame.
	 * @return string|null  The declared type, if known.
	 */
	private function column_decl_type( $expr, $frame ) {
		$lower = strtolower( $expr['name'] );
		foreach ( $frame as $slot ) {
			if ( null !== $expr['tbl'] && strtolower( $expr['tbl'] ) !== $slot['alias'] ) {
				continue;
			}
			if ( isset( $slot['decl'][ $lower ] ) ) {
				return $slot['decl'][ $lower ];
			}
		}
		return null;
	}

	/**
	 * Substitute result-column alias references in an expression.
	 *
	 * SQLite allows result aliases in WHERE and HAVING clauses. References
	 * that do not resolve to a real column of the source relation, but match
	 * a select item alias, are replaced with the aliased expression.
	 *
	 * @param  array $expr  The expression.
	 * @param  array $items The select items.
	 * @param  array $proto The relation prototype frame.
	 * @return array        The substituted expression.
	 */
	private function substitute_aliases( $expr, $items, $proto ) {
		if ( ! is_array( $expr ) || ! isset( $expr['t'] ) ) {
			return $expr;
		}
		if ( 'col' === $expr['t'] && null === $expr['tbl'] ) {
			$lower = strtolower( $expr['name'] );
			if ( null === $this->find_column( $proto, null, $lower )
				&& ! in_array( $lower, array( 'rowid', '_rowid_', 'oid' ), true ) ) {
				foreach ( $items as $item ) {
					if ( isset( $item['alias'] ) && null !== $item['alias'] && strtolower( $item['alias'] ) === $lower ) {
						return $item['e'];
					}
				}
			}
			return $expr;
		}
		// Recurse structurally, skipping subqueries.
		foreach ( $expr as $key => $value ) {
			if ( 'sel' === $key || 'sub' === $key || 't' === $key ) {
				continue;
			}
			if ( is_array( $value ) ) {
				if ( isset( $value['t'] ) ) {
					$expr[ $key ] = $this->substitute_aliases( $value, $items, $proto );
				} else {
					foreach ( $value as $index => $child ) {
						if ( is_array( $child ) && isset( $child['t'] ) ) {
							$expr[ $key ][ $index ] = $this->substitute_aliases( $child, $items, $proto );
						} elseif ( is_array( $child ) ) {
							foreach ( $child as $child_key => $grandchild ) {
								if ( is_array( $grandchild ) && isset( $grandchild['t'] ) ) {
									$expr[ $key ][ $index ][ $child_key ] = $this->substitute_aliases( $grandchild, $items, $proto );
								}
							}
						}
					}
				}
			}
		}
		return $expr;
	}

	/**
	 * Resolve a positional (integer literal) or alias reference in GROUP BY
	 * and ORDER BY to the underlying select item expression.
	 *
	 * @param  array $expr  The expression.
	 * @param  array $items The select items.
	 * @return array        The resolved expression.
	 */
	private function resolve_positional_or_alias( $expr, $items ) {
		if ( 'lit' === $expr['t'] && is_int( $expr['v'] ) ) {
			$index = $expr['v'] - 1;
			if ( isset( $items[ $index ]['e'] ) ) {
				return $items[ $index ]['e'];
			}
		}
		if ( 'col' === $expr['t'] && null === $expr['tbl'] ) {
			$lower = strtolower( $expr['name'] );
			foreach ( $items as $item ) {
				if ( isset( $item['alias'] ) && null !== $item['alias'] && strtolower( $item['alias'] ) === $lower ) {
					return $item['e'];
				}
			}
		}
		return $expr;
	}

	/**
	 * Compute window function values and patch them into the result rows.
	 *
	 * @param array $items      The select items.
	 * @param array $rows       The result rows (modified in place).
	 * @param array $row_frames The per-row source frames.
	 * @param array $cols       The output column names.
	 */
	private function compute_window_functions( $items, &$rows, $row_frames, $cols ) {
		$column_index = 0;
		foreach ( $items as $item ) {
			if ( ! empty( $item['star'] ) ) {
				// A star expands to multiple columns; recompute the offset.
				$column_index = count( $cols ) - count( $items ) + $column_index + 1;
				continue;
			}
			$expr = $item['e'];
			if ( 'fn' === $expr['t'] && null !== $expr['over'] ) {
				// Partition rows.
				$partitions = array();
				foreach ( $rows as $index => $row ) {
					$key_parts = array();
					foreach ( $expr['over']['partition'] as $part_expr ) {
						$key_parts[] = self::value_key( $this->eval( $part_expr, $row_frames[ $index ] ) );
					}
					$key = implode( '|', $key_parts );
					if ( ! isset( $partitions[ $key ] ) ) {
						$partitions[ $key ] = array();
					}
					$partitions[ $key ][] = $index;
				}
				foreach ( $partitions as $indexes ) {
					// Order within the partition.
					if ( ! empty( $expr['over']['order'] ) ) {
						$me = $this;
						usort(
							$indexes,
							function ( $a, $b ) use ( $expr, $row_frames, $me ) {
								foreach ( $expr['over']['order'] as $order_item ) {
									$va  = $me->eval( $order_item['e'], $row_frames[ $a ] );
									$vb  = $me->eval( $order_item['e'], $row_frames[ $b ] );
									$cmp = WP_PHP_Engine_Values::compare( $va, $vb, null !== $order_item['collate'] ? $order_item['collate'] : 'BINARY' );
									if ( 0 !== $cmp ) {
										return 'DESC' === $order_item['dir'] ? -$cmp : $cmp;
									}
								}
								return 0;
							}
						);
					}
					$number = 1;
					foreach ( $indexes as $index ) {
						switch ( $expr['name'] ) {
							case 'row_number':
							case 'rank':
							case 'dense_rank':
								$rows[ $index ][ $column_index ] = $number;
								break;
							default:
								throw new WP_PHP_Engine_SQL_Exception(
									'unsupported window function: ' . $expr['name']
								);
						}
						$number += 1;
					}
				}
			}
			$column_index += 1;
		}
	}

	/**
	 * Sort a result set by an ORDER BY list.
	 *
	 * @param array $result The result (modified in place).
	 * @param array $order  The ORDER BY items.
	 */
	private function sort_result( &$result, $order ) {
		$rows      = $result['rows'];
		$frames    = isset( $result['frames'] ) ? $result['frames'] : null;
		$items     = isset( $result['items'] ) ? $result['items'] : array();
		$cols      = $result['cols'];
		$positions = isset( $result['positions'] ) ? $result['positions'] : array();

		// SQLite resolves ORDER BY terms at prepare time; with no rows, the
		// terms are never evaluated, so resolve them once explicitly.
		if ( 0 === count( $rows ) && null !== $frames && isset( $result['proto'] ) ) {
			foreach ( $order as $order_item ) {
				$this->order_value( $order_item['e'], null, null, $cols, $items, array( $result['proto'] ), $positions );
			}
			return;
		}

		// Precompute sort keys.
		$keys = array();
		foreach ( $rows as $index => $row ) {
			$key = array();
			foreach ( $order as $order_item ) {
				$key[] = $this->order_value( $order_item['e'], $row, $index, $cols, $items, $frames, $positions );
			}
			$keys[ $index ] = $key;
		}

		$indexes = array_keys( $rows );
		usort(
			$indexes,
			function ( $a, $b ) use ( $keys, $order ) {
				foreach ( $order as $i => $order_item ) {
					$collate = null !== $order_item['collate'] ? $order_item['collate'] : 'BINARY';
					$cmp     = WP_PHP_Engine_Values::compare( $keys[ $a ][ $i ], $keys[ $b ][ $i ], $collate );
					if ( 0 !== $cmp ) {
						return 'DESC' === $order_item['dir'] ? -$cmp : $cmp;
					}
				}
				return $a - $b; // Stable sort.
			}
		);

		$sorted_rows   = array();
		$sorted_frames = array();
		foreach ( $indexes as $index ) {
			$sorted_rows[] = $rows[ $index ];
			if ( null !== $frames && isset( $frames[ $index ] ) ) {
				$sorted_frames[] = $frames[ $index ];
			}
		}
		$result['rows'] = $sorted_rows;
		if ( null !== $frames ) {
			$result['frames'] = $sorted_frames;
		}
	}

	/**
	 * Evaluate an ORDER BY expression for a result row.
	 *
	 * Handles ordinal references, output column names, and arbitrary
	 * expressions evaluated against the row's source frame.
	 *
	 * @param  array      $expr   The ORDER BY expression.
	 * @param  array      $row    The output row.
	 * @param  int        $index  The row index.
	 * @param  array      $cols   The output column names.
	 * @param  array      $items  The select items.
	 * @param  array|null $frames The per-row source frames.
	 * @return mixed              The sort value.
	 */
	private function order_value( $expr, $row, $index, $cols, $items, $frames, $positions = array() ) {
		// Ordinal reference.
		if ( 'lit' === $expr['t'] && is_int( $expr['v'] ) ) {
			$position = $expr['v'] - 1;
			if ( $position < 0 || $position >= count( $cols ) ) {
				throw new WP_PHP_Engine_SQL_Exception(
					sprintf( '%d ORDER BY term out of range - should be between 1 and %d', $expr['v'], count( $cols ) )
				);
			}
			return null !== $row ? $row[ $position ] : null;
		}

		if ( 'col' === $expr['t'] && null === $expr['tbl'] ) {
			$lower = strtolower( $expr['name'] );

			// An explicit select item alias takes priority (the first match
			// wins when the same alias is used multiple times).
			foreach ( $items as $item_index => $item ) {
				if ( isset( $item['alias'] ) && null !== $item['alias'] && strtolower( $item['alias'] ) === $lower ) {
					$position = isset( $positions[ $item_index ] ) ? $positions[ $item_index ] : $item_index;
					return null !== $row ? $row[ $position ] : null;
				}
			}

			// Without source frames (compound queries), match output names.
			if ( null === $frames ) {
				foreach ( $cols as $position => $name ) {
					if ( strtolower( $name ) === $lower ) {
						return null !== $row ? $row[ $position ] : null;
					}
				}
			}
		}

		// General expression against the source frame.
		if ( null !== $frames ) {
			if ( null === $index ) {
				// Prepare-time resolution against the prototype frame.
				return $this->eval( $expr, $frames[0] );
			}
			if ( isset( $frames[ $index ] ) ) {
				return $this->eval( $expr, $frames[ $index ] );
			}
		}

		throw new WP_PHP_Engine_SQL_Exception(
			sprintf( '1st ORDER BY term does not match any column in the result set' )
		);
	}

	/*
	 * ----------------------------------------------------------------------
	 * FROM resolution.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Resolve a FROM clause for an UPDATE ... FROM statement.
	 *
	 * @param  array $ref The from-reference AST node.
	 * @return array      array( 'proto' => frame, 'frames' => frame[] ).
	 */
	public function resolve_update_from( $ref ) {
		return $this->resolve_from( $ref );
	}

	/**
	 * Resolve a FROM clause node into a schema prototype and a frame list.
	 *
	 * @param  array $ref The from-reference AST node.
	 * @return array      array( 'proto' => frame, 'frames' => frame[] ).
	 */
	private function resolve_from( $ref ) {
		switch ( $ref['t'] ) {
			case 'table':
			case 'subquery':
			case 'tablefn':
			case 'values':
				$relation = $this->relation_slots( $ref );
				$frames   = array();
				foreach ( $relation['slots'] as $slot ) {
					$frames[] = array( $slot );
				}
				return array(
					'proto'  => array( $relation['proto'] ),
					'frames' => $frames,
				);

			case 'join':
				return $this->resolve_join( $ref );
		}
		throw new WP_PHP_Engine_SQL_Exception( 'unsupported FROM clause' );
	}

	/**
	 * Resolve a join node into a schema prototype and a frame list.
	 *
	 * @param  array $ref The join AST node.
	 * @return array      array( 'proto' => frame, 'frames' => frame[] ).
	 */
	private function resolve_join( $ref ) {
		$kind  = $ref['kind'];
		$left  = $this->resolve_from( $ref['l'] );
		$right = $this->resolve_from( $ref['r'] );

		if ( 'RIGHT' === $kind ) {
			// Evaluate as a LEFT JOIN with sides swapped; the output column
			// order keeps the original left side first.
			$frames = $this->join_frames( $right, $left, 'LEFT', $ref['on'], $ref['using'], true );
		} else {
			$frames = $this->join_frames( $left, $right, $kind, $ref['on'], $ref['using'], false );
		}
		return array(
			'proto'  => array_merge( $left['proto'], $right['proto'] ),
			'frames' => $frames,
		);
	}

	/**
	 * Join two resolved relations.
	 *
	 * @param  array       $left    The left resolved relation.
	 * @param  array       $right   The right resolved relation.
	 * @param  string      $kind    The join kind: INNER, LEFT, or CROSS.
	 * @param  array|null  $on      The ON condition.
	 * @param  array|null  $using   The USING column list.
	 * @param  bool        $swapped Whether the sides were swapped (RIGHT JOIN).
	 * @return array                The joined frames.
	 */
	private function join_frames( $left, $right, $kind, $on, $using, $swapped ) {
		$result = array();
		foreach ( $left['frames'] as $left_frame ) {
			$matched = false;
			foreach ( $right['frames'] as $right_frame ) {
				$combined = $swapped
					? array_merge( $right_frame, $left_frame )
					: array_merge( $left_frame, $right_frame );
				if ( null !== $on ) {
					if ( ! WP_PHP_Engine_Values::is_truthy( $this->eval( $on, $combined ) ) ) {
						continue;
					}
				} elseif ( null !== $using ) {
					$match = true;
					foreach ( $using as $col ) {
						$lower = strtolower( $col );
						$lv    = $this->slot_list_value( $left_frame, $lower );
						$rv    = $this->slot_list_value( $right_frame, $lower );
						if ( 0 !== WP_PHP_Engine_Values::compare( $lv, $rv ) || null === $lv ) {
							$match = false;
							break;
						}
					}
					if ( ! $match ) {
						continue;
					}
				}
				$matched  = true;
				$result[] = $combined;
			}
			if ( ! $matched && 'LEFT' === $kind ) {
				// Null-fill the right side using its prototype.
				$null_slots = array();
				foreach ( $right['proto'] as $slot ) {
					$null_slots[] = $slot;
				}
				$result[] = $swapped
					? array_merge( $null_slots, $left_frame )
					: array_merge( $left_frame, $null_slots );
			}
		}
		return $result;
	}

	/**
	 * Get a column value from a list of slots.
	 *
	 * @param  array  $slots The slots.
	 * @param  string $lower The lowercase column name.
	 * @return mixed         The value.
	 */
	private function slot_list_value( $slots, $lower ) {
		foreach ( $slots as $slot ) {
			if ( isset( $slot['cols'][ $lower ] ) || array_key_exists( $lower, $slot['cols'] ) ) {
				return $slot['cols'][ $lower ];
			}
		}
		return null;
	}

	/**
	 * Materialize a relation (table, subquery, VALUES, or table function)
	 * into a prototype slot and a list of row slots.
	 *
	 * @param  array $ref The from-reference AST node.
	 * @return array      array( 'proto' => slot, 'slots' => slot[] ).
	 */
	private function relation_slots( $ref ) {
		if ( 'table' === $ref['t'] ) {
			$lower = strtolower( $ref['name'] );
			$alias = null !== $ref['alias'] ? strtolower( $ref['alias'] ) : $lower;

			// Common table expressions take priority.
			if ( isset( $this->ctes[ $lower ] ) ) {
				$result = $this->materialize_cte( $lower );
				return $this->result_to_slots( $result, $alias );
			}

			// Virtual tables provided by the engine (sqlite_master, etc.).
			$virtual = $this->engine->virtual_table_result( $lower, isset( $ref['db'] ) ? $ref['db'] : null );
			if ( null !== $virtual ) {
				return $this->result_to_slots( $virtual, $alias );
			}

			$table = $this->engine->get_table( $lower );
			if ( null === $table ) {
				// Views behave like CTEs.
				$view = $this->engine->get_view( $lower );
				if ( null !== $view ) {
					$result = $this->select( $view['sel'] );
					return $this->result_to_slots( $result, $alias );
				}
				throw new WP_PHP_Engine_SQL_Exception( 'no such table: ' . $ref['name'] );
			}
			return $this->table_slots( $table, $alias );
		}

		if ( 'subquery' === $ref['t'] ) {
			$result = $this->select( $ref['sel'] );
			$alias  = null !== $ref['alias'] ? strtolower( $ref['alias'] ) : null;
			return $this->result_to_slots( $result, $alias );
		}

		if ( 'values' === $ref['t'] ) {
			$result = $this->select_core( $ref );
			$alias  = null !== $ref['alias'] ? strtolower( $ref['alias'] ) : null;
			return $this->result_to_slots( $result, $alias );
		}

		if ( 'tablefn' === $ref['t'] ) {
			$args = array();
			foreach ( $ref['args'] as $arg ) {
				$args[] = $this->eval( $arg, array() );
			}
			$result = $this->engine->table_function_result( $ref['name'], $args );
			if ( null === $result ) {
				throw new WP_PHP_Engine_SQL_Exception( 'no such table: ' . $ref['name'] );
			}
			$alias = null !== $ref['alias'] ? strtolower( $ref['alias'] ) : $ref['name'];
			return $this->result_to_slots( $result, $alias );
		}

		throw new WP_PHP_Engine_SQL_Exception( 'unsupported FROM clause' );
	}

	/**
	 * Materialize a CTE by name.
	 *
	 * @param  string $lower The lowercase CTE name.
	 * @return array         The result set.
	 */
	private function materialize_cte( $lower ) {
		$cte = $this->ctes[ $lower ];
		if ( isset( $cte['materialized'] ) ) {
			return $cte['materialized'];
		}
		// Hide the CTE itself during materialization (no recursion support).
		$saved = $this->ctes;
		unset( $this->ctes[ $lower ] );
		$result     = $this->select( $cte['sel'] );
		$this->ctes = $saved;
		if ( null !== $cte['cols'] ) {
			$result['cols'] = $cte['cols'];
		}
		$this->ctes[ $lower ]['materialized'] = $result;
		return $result;
	}

	/**
	 * Convert a result set into a prototype slot and a list of row slots.
	 *
	 * @param  array       $result The result set.
	 * @param  string|null $alias  The lowercase alias.
	 * @return array               array( 'proto' => slot, 'slots' => slot[] ).
	 */
	private function result_to_slots( $result, $alias ) {
		$names   = array();
		$decl    = array();
		$coll    = array();
		$aliased = array();
		$srct    = array();
		foreach ( $result['cols'] as $index => $name ) {
			$lower = strtolower( $name );
			if ( isset( $names[ $lower ] ) ) {
				// Disambiguate duplicate output names like SQLite (name:1).
				$lower = $lower . ':' . $index;
			}
			$names[ $lower ]   = $name;
			$decl[ $lower ]    = isset( $result['decl'][ $index ] ) ? $result['decl'][ $index ] : null;
			$coll[ $lower ]    = isset( $result['coll'][ $index ] ) ? $result['coll'][ $index ] : 'BINARY';
			$aliased[ $lower ] = ! isset( $result['aliased'][ $index ] ) || $result['aliased'][ $index ];
			$srct[ $lower ]    = isset( $result['srctable'][ $index ] ) ? $result['srctable'][ $index ] : null;
		}
		$lowers = array_keys( $names );

		$proto = array(
			'alias'     => $alias,
			'cols'      => array_fill_keys( $lowers, null ),
			'names'     => $names,
			'aff'       => array(),
			'coll'      => $coll,
			'decl'      => $decl,
			'aliased'   => $aliased,
			'srct'      => $srct,
			'rowid'     => null,
			'has_rowid' => false,
		);

		$slots = array();
		foreach ( $result['rows'] as $row ) {
			$cols = array();
			foreach ( $lowers as $position => $lower ) {
				$cols[ $lower ] = isset( $row[ $position ] ) || array_key_exists( $position, $row ) ? $row[ $position ] : null;
			}
			$slot         = $proto;
			$slot['cols'] = $cols;
			$slots[]      = $slot;
		}

		return array(
			'proto' => $proto,
			'slots' => $slots,
		);
	}

	/**
	 * Convert table rows into a prototype slot and a list of row slots.
	 *
	 * @param  array  $table The table definition.
	 * @param  string $alias The lowercase alias.
	 * @return array         array( 'proto' => slot, 'slots' => slot[] ).
	 */
	private function table_slots( $table, $alias ) {
		$proto = $this->engine->make_table_slot( $table, array_fill_keys( array_keys( $table['columns'] ), null ), null, $alias );

		// Scan rows in ascending rowid order, like SQLite does.
		$rows = $table['rows'];
		ksort( $rows );

		$slots = array();
		foreach ( $rows as $rowid => $row ) {
			$slot          = $proto;
			$slot['cols']  = $row;
			$slot['rowid'] = $rowid;
			$slots[]       = $slot;
		}
		return array(
			'proto' => $proto,
			'slots' => $slots,
		);
	}

	/*
	 * ----------------------------------------------------------------------
	 * Expression evaluation.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Check whether an expression contains an aggregate function call
	 * (not nested inside a subquery).
	 *
	 * @param  array $expr The expression.
	 * @return bool        Whether an aggregate call is present.
	 */
	private function contains_aggregate( $expr ) {
		if ( ! is_array( $expr ) ) {
			return false;
		}
		if ( isset( $expr['t'] ) ) {
			if ( 'fn' === $expr['t'] && null === $expr['over']
				&& isset( WP_PHP_Engine_Functions::$aggregate_functions[ $expr['name'] ] )
				&& ! $this->engine->has_user_function( $expr['name'] ) ) {
				return true;
			}
			if ( 'sub' === $expr['t'] || 'exists' === $expr['t'] ) {
				return false;
			}
			foreach ( $expr as $key => $value ) {
				if ( 'sel' === $key || 'sub' === $key ) {
					continue;
				}
				if ( is_array( $value ) && $this->contains_aggregate( $value ) ) {
					return true;
				}
			}
			return false;
		}
		// A plain list (e.g. CASE when-pairs or function arguments).
		foreach ( $expr as $value ) {
			if ( is_array( $value ) && $this->contains_aggregate( $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Evaluate an expression in a group (aggregate) context.
	 *
	 * @param  array $expr  The expression.
	 * @param  array $frame The representative frame (first row of the group).
	 * @param  array $group All frames in the group.
	 * @return mixed        The value.
	 */
	public function eval_in_group( $expr, $frame, $group ) {
		if ( 'fn' === $expr['t'] && null === $expr['over']
			&& isset( WP_PHP_Engine_Functions::$aggregate_functions[ $expr['name'] ] )
			&& ! $this->engine->has_user_function( $expr['name'] ) ) {
			return $this->eval_aggregate( $expr, $group );
		}
		if ( in_array( $expr['t'], array( 'lit', 'param', 'now' ), true ) ) {
			return $this->eval( $expr, $frame );
		}
		if ( 'col' === $expr['t'] ) {
			return $this->eval( $expr, $frame );
		}

		// Recurse structurally: clone the node with evaluated children.
		switch ( $expr['t'] ) {
			case 'bin':
				// Logical operators need lazy evaluation; emulate by
				// evaluating both sides in group context.
				$node      = $expr;
				$node['l'] = array(
					't' => 'lit',
					'v' => $this->eval_in_group( $expr['l'], $frame, $group ),
				);
				$node['r'] = array(
					't' => 'lit',
					'v' => $this->eval_in_group( $expr['r'], $frame, $group ),
				);
				return $this->eval( $node, $frame );
			case 'un':
				$node      = $expr;
				$node['e'] = array(
					't' => 'lit',
					'v' => $this->eval_in_group( $expr['e'], $frame, $group ),
				);
				return $this->eval( $node, $frame );
			case 'fn':
				$node = $expr;
				foreach ( $expr['args'] as $index => $arg ) {
					$node['args'][ $index ] = array(
						't' => 'lit',
						'v' => $this->eval_in_group( $arg, $frame, $group ),
					);
				}
				return $this->eval( $node, $frame );
			case 'case':
				if ( null !== $expr['operand'] ) {
					$operand = $this->eval_in_group( $expr['operand'], $frame, $group );
					foreach ( $expr['when'] as $when ) {
						$test = $this->eval_in_group( $when[0], $frame, $group );
						if ( null !== $operand && null !== $test && 0 === WP_PHP_Engine_Values::compare( $operand, $test ) ) {
							return $this->eval_in_group( $when[1], $frame, $group );
						}
					}
				} else {
					foreach ( $expr['when'] as $when ) {
						if ( WP_PHP_Engine_Values::is_truthy( $this->eval_in_group( $when[0], $frame, $group ) ) ) {
							return $this->eval_in_group( $when[1], $frame, $group );
						}
					}
				}
				return null !== $expr['else'] ? $this->eval_in_group( $expr['else'], $frame, $group ) : null;
			case 'cast':
				return WP_PHP_Engine_Values::cast(
					$this->eval_in_group( $expr['e'], $frame, $group ),
					$expr['as']
				);
			case 'in':
			case 'like':
			case 'between':
			case 'is':
			case 'isnull':
			case 'collate':
				// Pre-evaluate child expressions in the group context, then
				// evaluate the operator on the resulting literals.
				$node = $expr;
				foreach ( array( 'e', 'l', 'r', 'p', 'lo', 'hi', 'escape' ) as $key ) {
					if ( isset( $node[ $key ] ) && is_array( $node[ $key ] ) && isset( $node[ $key ]['t'] ) ) {
						$node[ $key ] = array(
							't' => 'lit',
							'v' => $this->eval_in_group( $expr[ $key ], $frame, $group ),
						);
					}
				}
				if ( isset( $node['list'] ) ) {
					foreach ( $node['list'] as $index => $item ) {
						$node['list'][ $index ] = array(
							't' => 'lit',
							'v' => $this->eval_in_group( $item, $frame, $group ),
						);
					}
				}
				return $this->eval( $node, $frame );
			default:
				return $this->eval( $expr, $frame );
		}
	}

	/**
	 * Evaluate an aggregate function over a group of frames.
	 *
	 * @param  array $expr  The aggregate function expression.
	 * @param  array $group The frames in the group.
	 * @return mixed        The aggregate value.
	 */
	private function eval_aggregate( $expr, $group ) {
		$name = $expr['name'];

		// COUNT(*).
		if ( $expr['star'] ) {
			return count( $group );
		}

		// Collect argument values.
		$values     = array();
		$second_arg = null;
		foreach ( $group as $frame ) {
			$value = $this->eval( $expr['args'][0], $frame );
			if ( isset( $expr['args'][1] ) ) {
				$second_arg = $this->eval( $expr['args'][1], $frame );
			}
			if ( null === $value ) {
				continue;
			}
			$values[] = $value;
		}

		if ( $expr['distinct'] ) {
			$seen   = array();
			$unique = array();
			foreach ( $values as $value ) {
				$key = self::value_key( $value );
				if ( ! isset( $seen[ $key ] ) ) {
					$seen[ $key ] = true;
					$unique[]     = $value;
				}
			}
			$values = $unique;
		}

		switch ( $name ) {
			case 'count':
				return count( $values );
			case 'sum':
			case 'total':
				if ( 0 === count( $values ) ) {
					return 'total' === $name ? 0.0 : null;
				}
				$sum    = 0;
				$is_int = 'sum' === $name;
				foreach ( $values as $value ) {
					$number = is_int( $value ) || is_float( $value ) ? $value : WP_PHP_Engine_Values::to_numeric( $value );
					if ( is_float( $number ) ) {
						$is_int = false;
					}
					$sum += $number;
				}
				if ( 'total' === $name ) {
					return (float) $sum;
				}
				return $is_int && is_int( $sum ) ? $sum : (float) $sum;
			case 'avg':
				if ( 0 === count( $values ) ) {
					return null;
				}
				$sum = 0.0;
				foreach ( $values as $value ) {
					$sum += (float) WP_PHP_Engine_Values::to_numeric( $value );
				}
				return $sum / count( $values );
			case 'min':
				$min = null;
				foreach ( $values as $value ) {
					if ( null === $min || WP_PHP_Engine_Values::compare( $value, $min ) < 0 ) {
						$min = $value;
					}
				}
				return $min;
			case 'max':
				$max = null;
				foreach ( $values as $value ) {
					if ( null === $max || WP_PHP_Engine_Values::compare( $value, $max ) > 0 ) {
						$max = $value;
					}
				}
				return $max;
			case 'group_concat':
			case 'string_agg':
				if ( 0 === count( $values ) ) {
					return null;
				}
				$separator = isset( $expr['args'][1] ) ? ( null === $second_arg ? ',' : WP_PHP_Engine_Values::to_text( $second_arg ) ) : ',';
				$parts     = array();
				foreach ( $values as $value ) {
					$parts[] = WP_PHP_Engine_Values::to_text( $value );
				}
				return implode( $separator, $parts );
		}
		throw new WP_PHP_Engine_SQL_Exception( 'unsupported aggregate function: ' . $name );
	}

	/**
	 * Evaluate an expression against a frame.
	 *
	 * @param  array $expr  The expression AST node.
	 * @param  array $frame The current frame.
	 * @return mixed        The value.
	 */
	public function eval( $expr, $frame ) {
		switch ( $expr['t'] ) {
			case 'lit':
				return $expr['v'];

			case 'param':
				$index = $expr['i'];
				if ( is_int( $index ) ) {
					if ( ! array_key_exists( $index, $this->params ) ) {
						// PDO uses 1-based keys when bound explicitly.
						if ( array_key_exists( $index + 1, $this->params ) ) {
							return $this->normalize_param( $this->params[ $index + 1 ] );
						}
						return null;
					}
					return $this->normalize_param( $this->params[ $index ] );
				}
				if ( array_key_exists( $index, $this->params ) ) {
					return $this->normalize_param( $this->params[ $index ] );
				}
				$bare = ltrim( (string) $index, ':@$' );
				if ( array_key_exists( $bare, $this->params ) ) {
					return $this->normalize_param( $this->params[ $bare ] );
				}
				if ( array_key_exists( ':' . $bare, $this->params ) ) {
					return $this->normalize_param( $this->params[ ':' . $bare ] );
				}
				return null;

			case 'col':
				return $this->resolve_column( $expr, $frame );

			case 'now':
				return $this->engine->current_timestamp( $expr['fn'] );

			case 'bin':
				return $this->eval_binary( $expr, $frame );

			case 'un':
				$value = $this->eval( $expr['e'], $frame );
				switch ( $expr['op'] ) {
					case 'NOT':
						if ( null === $value ) {
							return null;
						}
						return WP_PHP_Engine_Values::is_truthy( $value ) ? 0 : 1;
					case '-':
						if ( null === $value ) {
							return null;
						}
						$number = WP_PHP_Engine_Values::to_numeric( $value );
						return is_int( $number ) ? -$number : - (float) $number;
					case '+':
						return $value;
					case '~':
						if ( null === $value ) {
							return null;
						}
						return ~ (int) WP_PHP_Engine_Values::to_numeric( $value );
				}
				return null;

			case 'collate':
				return $this->eval( $expr['e'], $frame );

			case 'case':
				if ( null !== $expr['operand'] ) {
					$operand = $this->eval( $expr['operand'], $frame );
					foreach ( $expr['when'] as $when ) {
						$test = $this->eval( $when[0], $frame );
						if ( null !== $operand && null !== $test && 0 === WP_PHP_Engine_Values::compare( $operand, $test ) ) {
							return $this->eval( $when[1], $frame );
						}
					}
				} else {
					foreach ( $expr['when'] as $when ) {
						if ( WP_PHP_Engine_Values::is_truthy( $this->eval( $when[0], $frame ) ) ) {
							return $this->eval( $when[1], $frame );
						}
					}
				}
				return null !== $expr['else'] ? $this->eval( $expr['else'], $frame ) : null;

			case 'cast':
				return WP_PHP_Engine_Values::cast( $this->eval( $expr['e'], $frame ), $expr['as'] );

			case 'in':
				return $this->eval_in( $expr, $frame );

			case 'like':
				return $this->eval_like( $expr, $frame );

			case 'between':
				$value = $this->eval( $expr['e'], $frame );
				$lo    = $this->eval( $expr['lo'], $frame );
				$hi    = $this->eval( $expr['hi'], $frame );
				if ( null === $value || null === $lo || null === $hi ) {
					return null;
				}
				$affinity = $this->expr_affinity( $expr['e'], $frame );
				if ( null !== $affinity && WP_PHP_Engine_Values::AFFINITY_BLOB !== $affinity ) {
					list( $value_lo, $lo ) = WP_PHP_Engine_Values::apply_comparison_affinity( $value, $lo, $affinity, $this->expr_affinity( $expr['lo'], $frame ) );
					list( $value_hi, $hi ) = WP_PHP_Engine_Values::apply_comparison_affinity( $value, $hi, $affinity, $this->expr_affinity( $expr['hi'], $frame ) );
				} else {
					$value_lo = $value;
					$value_hi = $value;
				}
				$collation = $this->expr_collation( $expr['e'], $frame );
				$in_range  = WP_PHP_Engine_Values::compare( $value_lo, $lo, $collation ) >= 0
					&& WP_PHP_Engine_Values::compare( $value_hi, $hi, $collation ) <= 0;
				if ( $expr['not'] ) {
					return $in_range ? 0 : 1;
				}
				return $in_range ? 1 : 0;

			case 'is':
				$left  = $this->eval( $expr['l'], $frame );
				$right = $this->eval( $expr['r'], $frame );
				if ( null === $left || null === $right ) {
					$equal = null === $left && null === $right;
				} else {
					$equal = 0 === WP_PHP_Engine_Values::compare( $left, $right, $this->comparison_collation( $expr['l'], $expr['r'], $frame ) );
				}
				return ( $expr['not'] ? ! $equal : $equal ) ? 1 : 0;

			case 'isnull':
				$value   = $this->eval( $expr['e'], $frame );
				$is_null = null === $value;
				return ( $expr['not'] ? ! $is_null : $is_null ) ? 1 : 0;

			case 'exists':
				$result = $this->run_subquery( $expr['sel'], $frame );
				$exists = count( $result['rows'] ) > 0;
				return ( $expr['not'] ? ! $exists : $exists ) ? 1 : 0;

			case 'sub':
				$result = $this->run_subquery( $expr['sel'], $frame );
				if ( 0 === count( $result['rows'] ) ) {
					return null;
				}
				return $result['rows'][0][0];

			case 'fn':
				return $this->eval_function( $expr, $frame );

			case 'raise':
				if ( 'IGNORE' === strtoupper( $expr['kind'] ) ) {
					throw new WP_PHP_Engine_Raise_Ignore_Exception();
				}
				$message = null !== $expr['msg'] ? WP_PHP_Engine_Values::to_text( $this->eval( $expr['msg'], $frame ) ) : '';
				throw new WP_PHP_Engine_SQL_Exception( $message, 'HY000', 19 );

			case 'row':
				// Row values are only supported in simple comparisons.
				$values = array();
				foreach ( $expr['exprs'] as $sub_expr ) {
					$values[] = $this->eval( $sub_expr, $frame );
				}
				return $values;
		}
		throw new WP_PHP_Engine_SQL_Exception( 'unsupported expression' );
	}

	/**
	 * Normalize a bound parameter value.
	 *
	 * @param  mixed $value The bound value.
	 * @return mixed        The normalized value.
	 */
	private function normalize_param( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}
		return $value;
	}

	/**
	 * Run a subquery with the current frame pushed onto the scope stack.
	 *
	 * @param  array $select The select AST node.
	 * @param  array $frame  The current frame.
	 * @return array         The result set.
	 */
	private function run_subquery( $select, $frame ) {
		$this->scopes[] = $frame;
		try {
			return $this->select( $select );
		} finally {
			array_pop( $this->scopes );
		}
	}

	/**
	 * Evaluate a subquery expression to its first result row.
	 *
	 * This is used for multi-column assignments: SET (a, b) = (SELECT ...).
	 *
	 * @param  array $expr  The subquery expression node.
	 * @param  array $frame The current frame.
	 * @return array|null   The first row, or null when there are no rows.
	 */
	public function eval_row_subquery( $expr, $frame ) {
		if ( 'sub' !== $expr['t'] ) {
			throw new WP_PHP_Engine_SQL_Exception( 'expected a subquery on the right-hand side of a multi-column assignment' );
		}
		$result = $this->run_subquery( $expr['sel'], $frame );
		if ( 0 === count( $result['rows'] ) ) {
			return null;
		}
		return $result['rows'][0];
	}

	/**
	 * Resolve a column reference against the frame and outer scopes.
	 *
	 * @param  array $expr  The column expression.
	 * @param  array $frame The current frame.
	 * @return mixed        The value.
	 */
	private function resolve_column( $expr, $frame ) {
		$lower = strtolower( $expr['name'] );
		$tbl   = null !== $expr['tbl'] ? strtolower( $expr['tbl'] ) : null;

		$found = $this->find_column( $frame, $tbl, $lower );
		if ( null !== $found ) {
			return $found[0]['cols'][ $found[1] ];
		}

		// rowid aliases.
		if ( null === $found && in_array( $lower, array( 'rowid', '_rowid_', 'oid' ), true ) ) {
			foreach ( $frame as $slot ) {
				if ( null !== $tbl && $tbl !== $slot['alias'] ) {
					continue;
				}
				if ( ! empty( $slot['has_rowid'] ) ) {
					return $slot['rowid'];
				}
			}
		}

		// Outer scopes (correlated subqueries).
		for ( $i = count( $this->scopes ) - 1; $i >= 0; $i-- ) {
			$found = $this->find_column( $this->scopes[ $i ], $tbl, $lower );
			if ( null !== $found ) {
				return $found[0]['cols'][ $found[1] ];
			}
			if ( in_array( $lower, array( 'rowid', '_rowid_', 'oid' ), true ) ) {
				foreach ( $this->scopes[ $i ] as $slot ) {
					if ( null !== $tbl && $tbl !== $slot['alias'] ) {
						continue;
					}
					if ( ! empty( $slot['has_rowid'] ) ) {
						return $slot['rowid'];
					}
				}
			}
		}

		throw new WP_PHP_Engine_SQL_Exception(
			'no such column: ' . ( null !== $expr['tbl'] ? $expr['tbl'] . '.' : '' ) . $expr['name']
		);
	}

	/**
	 * Find a column in a frame.
	 *
	 * Unqualified references that match columns in multiple slots are
	 * ambiguous, like in SQLite.
	 *
	 * @param  array       $frame The frame.
	 * @param  string|null $tbl   The lowercase table alias, if qualified.
	 * @param  string      $lower The lowercase column name.
	 * @return array|null         The slot and key, or null.
	 * @throws WP_PHP_Engine_SQL_Exception When the reference is ambiguous.
	 */
	private function find_column( $frame, $tbl, $lower ) {
		$found = null;
		foreach ( $frame as $slot ) {
			if ( null !== $tbl && $tbl !== $slot['alias'] ) {
				continue;
			}
			if ( isset( $slot['cols'][ $lower ] ) || array_key_exists( $lower, $slot['cols'] ) ) {
				if ( null !== $tbl ) {
					return array( $slot, $lower );
				}
				if ( null !== $found ) {
					throw new WP_PHP_Engine_SQL_Exception( 'ambiguous column name: ' . $lower );
				}
				$found = array( $slot, $lower );
			}
		}
		return $found;
	}

	/**
	 * Determine the affinity of an expression.
	 *
	 * @param  array $expr  The expression.
	 * @param  array $frame The current frame.
	 * @return string|null  The affinity, or null when none applies.
	 */
	public function expr_affinity( $expr, $frame ) {
		if ( 'col' === $expr['t'] ) {
			$lower = strtolower( $expr['name'] );
			$tbl   = null !== $expr['tbl'] ? strtolower( $expr['tbl'] ) : null;
			$found = $this->find_column( $frame, $tbl, $lower );
			if ( null !== $found && isset( $found[0]['aff'][ $lower ] ) ) {
				return $found[0]['aff'][ $lower ];
			}
			if ( null === $found ) {
				for ( $i = count( $this->scopes ) - 1; $i >= 0; $i-- ) {
					$found = $this->find_column( $this->scopes[ $i ], $tbl, $lower );
					if ( null !== $found ) {
						return isset( $found[0]['aff'][ $lower ] ) ? $found[0]['aff'][ $lower ] : null;
					}
				}
			}
			if ( in_array( $lower, array( 'rowid', '_rowid_', 'oid' ), true ) ) {
				return WP_PHP_Engine_Values::AFFINITY_INTEGER;
			}
			return null;
		}
		if ( 'cast' === $expr['t'] ) {
			return WP_PHP_Engine_Values::affinity_for_type( $expr['as'] );
		}
		if ( 'collate' === $expr['t'] || 'un' === $expr['t'] ) {
			return isset( $expr['e'] ) ? $this->expr_affinity( $expr['e'], $frame ) : null;
		}
		return null;
	}

	/**
	 * Determine the collation of an expression.
	 *
	 * @param  array $expr  The expression.
	 * @param  array $frame The current frame.
	 * @return string       The collation name.
	 */
	public function expr_collation( $expr, $frame ) {
		if ( 'collate' === $expr['t'] ) {
			return $expr['name'];
		}
		if ( 'col' === $expr['t'] ) {
			$lower = strtolower( $expr['name'] );
			$tbl   = null !== $expr['tbl'] ? strtolower( $expr['tbl'] ) : null;
			$found = $this->find_column( $frame, $tbl, $lower );
			if ( null === $found ) {
				for ( $i = count( $this->scopes ) - 1; $i >= 0; $i-- ) {
					$found = $this->find_column( $this->scopes[ $i ], $tbl, $lower );
					if ( null !== $found ) {
						break;
					}
				}
			}
			if ( null !== $found && isset( $found[0]['coll'][ $lower ] ) ) {
				return $found[0]['coll'][ $lower ];
			}
			return 'BINARY';
		}
		if ( 'un' === $expr['t'] ) {
			return $this->expr_collation( $expr['e'], $frame );
		}
		if ( 'bin' === $expr['t'] && '||' === $expr['op'] ) {
			$left = $this->expr_collation( $expr['l'], $frame );
			if ( 'BINARY' !== $left ) {
				return $left;
			}
			return $this->expr_collation( $expr['r'], $frame );
		}
		return 'BINARY';
	}

	/**
	 * Determine the collation for a comparison of two expressions.
	 *
	 * @param  array $left  The left expression.
	 * @param  array $right The right expression.
	 * @param  array $frame The current frame.
	 * @return string       The collation name.
	 */
	private function comparison_collation( $left, $right, $frame ) {
		// An explicit COLLATE operator takes precedence; otherwise the
		// left operand's collation applies, then the right one's.
		if ( 'collate' === $left['t'] ) {
			return $left['name'];
		}
		if ( 'collate' === $right['t'] ) {
			return $right['name'];
		}
		$collation = $this->expr_collation( $left, $frame );
		if ( 'BINARY' !== $collation ) {
			return $collation;
		}
		return $this->expr_collation( $right, $frame );
	}

	/**
	 * Evaluate a binary operator expression.
	 *
	 * @param  array $expr  The expression.
	 * @param  array $frame The current frame.
	 * @return mixed        The value.
	 */
	private function eval_binary( $expr, $frame ) {
		$op = $expr['op'];

		// Logical operators with SQL three-valued logic.
		if ( 'AND' === $op ) {
			$left = $this->eval( $expr['l'], $frame );
			if ( null !== $left && ! WP_PHP_Engine_Values::is_truthy( $left ) ) {
				return 0;
			}
			$right = $this->eval( $expr['r'], $frame );
			if ( null !== $right && ! WP_PHP_Engine_Values::is_truthy( $right ) ) {
				return 0;
			}
			if ( null === $left || null === $right ) {
				return null;
			}
			return 1;
		}
		if ( 'OR' === $op ) {
			$left = $this->eval( $expr['l'], $frame );
			if ( null !== $left && WP_PHP_Engine_Values::is_truthy( $left ) ) {
				return 1;
			}
			$right = $this->eval( $expr['r'], $frame );
			if ( null !== $right && WP_PHP_Engine_Values::is_truthy( $right ) ) {
				return 1;
			}
			if ( null === $left || null === $right ) {
				return null;
			}
			return 0;
		}

		$left  = $this->eval( $expr['l'], $frame );
		$right = $this->eval( $expr['r'], $frame );

		// Comparison operators.
		if ( in_array( $op, array( '=', '!=', '<', '<=', '>', '>=' ), true ) ) {
			if ( null === $left || null === $right ) {
				return null;
			}
			list( $left, $right ) = WP_PHP_Engine_Values::apply_comparison_affinity(
				$left,
				$right,
				$this->expr_affinity( $expr['l'], $frame ),
				$this->expr_affinity( $expr['r'], $frame )
			);
			$cmp                  = WP_PHP_Engine_Values::compare( $left, $right, $this->comparison_collation( $expr['l'], $expr['r'], $frame ) );
			switch ( $op ) {
				case '=':
					return 0 === $cmp ? 1 : 0;
				case '!=':
					return 0 !== $cmp ? 1 : 0;
				case '<':
					return $cmp < 0 ? 1 : 0;
				case '<=':
					return $cmp <= 0 ? 1 : 0;
				case '>':
					return $cmp > 0 ? 1 : 0;
				case '>=':
					return $cmp >= 0 ? 1 : 0;
			}
		}

		// String concatenation.
		if ( '||' === $op ) {
			if ( null === $left || null === $right ) {
				return null;
			}
			return WP_PHP_Engine_Values::to_text( $left ) . WP_PHP_Engine_Values::to_text( $right );
		}

		// Arithmetic and bitwise operators.
		if ( null === $left || null === $right ) {
			return null;
		}
		$ln = WP_PHP_Engine_Values::to_numeric( $left );
		$rn = WP_PHP_Engine_Values::to_numeric( $right );
		switch ( $op ) {
			case '+':
				return $ln + $rn;
			case '-':
				return $ln - $rn;
			case '*':
				return $ln * $rn;
			case '/':
				if ( 0 == $rn ) { // phpcs:ignore Universal.Operators.StrictComparisons -- Intentional cross-type numeric comparison, like in SQLite.
					return null;
				}
				if ( is_int( $ln ) && is_int( $rn ) ) {
					return intdiv( $ln, $rn );
				}
				return $ln / $rn;
			case '%':
				if ( 0 == $rn ) { // phpcs:ignore Universal.Operators.StrictComparisons -- Intentional cross-type numeric comparison, like in SQLite.
					return null;
				}
				if ( is_int( $ln ) && is_int( $rn ) ) {
					return $ln % $rn;
				}
				return fmod( (float) $ln, (float) $rn );
			case '&':
				return ( (int) $ln ) & ( (int) $rn );
			case '|':
				return ( (int) $ln ) | ( (int) $rn );
			case '<<':
				return ( (int) $ln ) << ( (int) $rn );
			case '>>':
				return ( (int) $ln ) >> ( (int) $rn );
		}
		throw new WP_PHP_Engine_SQL_Exception( 'unsupported operator: ' . $op );
	}

	/**
	 * Evaluate an IN expression.
	 *
	 * @param  array $expr  The expression.
	 * @param  array $frame The current frame.
	 * @return mixed        The value.
	 */
	private function eval_in( $expr, $frame ) {
		$value = $this->eval( $expr['e'], $frame );

		$candidates = array();
		if ( isset( $expr['sub'] ) ) {
			$result = $this->run_subquery( $expr['sub'], $frame );
			foreach ( $result['rows'] as $row ) {
				$candidates[] = $row[0];
			}
		} else {
			foreach ( $expr['list'] as $item ) {
				$candidates[] = $this->eval( $item, $frame );
			}
		}

		if ( null === $value ) {
			return null;
		}

		$affinity  = $this->expr_affinity( $expr['e'], $frame );
		$collation = $this->expr_collation( $expr['e'], $frame );
		$has_null  = false;
		foreach ( $candidates as $candidate ) {
			if ( null === $candidate ) {
				$has_null = true;
				continue;
			}
			list( $a, $b ) = WP_PHP_Engine_Values::apply_comparison_affinity( $value, $candidate, $affinity, null );
			if ( 0 === WP_PHP_Engine_Values::compare( $a, $b, $collation ) ) {
				return $expr['not'] ? 0 : 1;
			}
		}
		if ( $has_null ) {
			return null;
		}
		return $expr['not'] ? 1 : 0;
	}

	/**
	 * Evaluate a LIKE/GLOB/REGEXP/MATCH expression.
	 *
	 * @param  array $expr  The expression.
	 * @param  array $frame The current frame.
	 * @return mixed        The value.
	 */
	private function eval_like( $expr, $frame ) {
		$value   = $this->eval( $expr['e'], $frame );
		$pattern = $this->eval( $expr['p'], $frame );
		if ( null === $value || null === $pattern ) {
			return null;
		}

		switch ( $expr['op'] ) {
			case 'LIKE':
				$escape = null;
				if ( null !== $expr['escape'] ) {
					$escape = WP_PHP_Engine_Values::to_text( $this->eval( $expr['escape'], $frame ) );
				}
				// A user-defined like() overrides the built-in behavior.
				if ( $this->engine->has_user_function( 'like' ) ) {
					$args = array( $pattern, $value );
					if ( null !== $escape ) {
						$args[] = $escape;
					}
					$matched = WP_PHP_Engine_Values::is_truthy( $this->engine->call_user_function( 'like', $args ) );
				} else {
					$matched = WP_PHP_Engine_Values::like_match(
						WP_PHP_Engine_Values::to_text( $pattern ),
						WP_PHP_Engine_Values::to_text( $value ),
						$escape
					);
				}
				break;
			case 'GLOB':
				$matched = WP_PHP_Engine_Values::glob_match(
					WP_PHP_Engine_Values::to_text( $pattern ),
					WP_PHP_Engine_Values::to_text( $value )
				);
				break;
			case 'REGEXP':
			case 'MATCH':
				$fn = strtolower( $expr['op'] );
				if ( ! $this->engine->has_user_function( $fn ) ) {
					throw new WP_PHP_Engine_SQL_Exception( 'unable to use function ' . $expr['op'] . ' in the requested context' );
				}
				$result = $this->engine->call_user_function( $fn, array( $pattern, $value ) );
				if ( null === $result ) {
					return null;
				}
				$matched = WP_PHP_Engine_Values::is_truthy( $result );
				break;
			default:
				throw new WP_PHP_Engine_SQL_Exception( 'unsupported operator: ' . $expr['op'] );
		}

		if ( $expr['not'] ) {
			return $matched ? 0 : 1;
		}
		return $matched ? 1 : 0;
	}

	/**
	 * Evaluate a function call expression.
	 *
	 * @param  array $expr  The expression.
	 * @param  array $frame The current frame.
	 * @return mixed        The value.
	 */
	private function eval_function( $expr, $frame ) {
		$name = $expr['name'];

		// Aggregates outside of a group context operate per-row in SQLite
		// only via implicit grouping, which is handled in select_core().
		// Reaching this point with an aggregate is an error, except for
		// min/max which double as scalar functions.
		$args = array();
		foreach ( $expr['args'] as $arg ) {
			$args[] = $this->eval( $arg, $frame );
		}

		// User-defined functions override built-ins.
		if ( $this->engine->has_user_function( $name ) ) {
			return $this->engine->call_user_function( $name, $args );
		}

		if ( WP_PHP_Engine_Functions::is_scalar( $name ) ) {
			return $this->engine->get_functions()->call( $name, $args );
		}

		// Engine-state functions.
		switch ( $name ) {
			case 'changes':
				return $this->engine->get_changes();
			case 'total_changes':
				return $this->engine->get_total_changes();
		}

		throw new WP_PHP_Engine_SQL_Exception( 'no such function: ' . $expr['name'] );
	}

	/*
	 * ----------------------------------------------------------------------
	 * Helpers.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Build a type-tagged key for a value (for DISTINCT and grouping).
	 *
	 * @param  mixed $value The value.
	 * @return string       The key.
	 */
	public static function value_key( $value ) {
		if ( null === $value ) {
			return 'n';
		}
		if ( $value instanceof WP_PHP_Engine_Blob ) {
			return 'b:' . $value->bytes;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			// Integers and floats with the same value compare equal.
			return 'd:' . (float) $value;
		}
		return 's:' . $value;
	}

	/**
	 * Build a key for a full row.
	 *
	 * @param  array $row The row values.
	 * @return string     The key.
	 */
	public static function row_key( $row ) {
		$parts = array();
		foreach ( $row as $value ) {
			$parts[] = self::value_key( $value );
		}
		return implode( '|', $parts );
	}
}

/**
 * An internal exception used to implement RAISE(IGNORE).
 */
class WP_PHP_Engine_Raise_Ignore_Exception extends Exception {
}
