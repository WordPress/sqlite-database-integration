<?php

/*
 * The file contains the engine SQL exception class as well:
 *   phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
 */

/**
 * A recursive descent parser for the SQLite SQL dialect.
 *
 * This is a part of the pure-PHP database engine ("WP_PHP_Engine") — an
 * SQLite-compatible database engine implemented entirely in PHP.
 *
 * The parser produces a compact AST in the form of nested associative
 * arrays. Every node has a "t" key with the node type. The supported
 * statements cover the SQL surface emitted by the WordPress SQLite driver,
 * as well as general hand-written SQLite SQL:
 *
 *   SELECT (CTEs, compound queries, joins, subqueries, window functions),
 *   INSERT/REPLACE (incl. upserts), UPDATE, DELETE, CREATE/DROP/ALTER TABLE,
 *   CREATE/DROP INDEX, CREATE/DROP TRIGGER, PRAGMA, ANALYZE, and
 *   transaction statements (BEGIN/COMMIT/ROLLBACK/SAVEPOINT/RELEASE).
 */
class WP_PHP_Engine_Parser {
	/**
	 * The token list produced by WP_PHP_Engine_Lexer.
	 *
	 * @var array
	 */
	private $tokens;

	/**
	 * The current token position.
	 *
	 * @var int
	 */
	private $pos;

	/**
	 * The number of positional "?" parameters seen so far.
	 *
	 * @var int
	 */
	private $parameter_count;

	/**
	 * The original SQL string (stored in DDL nodes for sqlite_master).
	 *
	 * @var string
	 */
	private $sql;

	/**
	 * Parse an SQL string into a list of statement AST nodes.
	 *
	 * @param  string $sql The SQL string.
	 * @return array       A list of statement nodes.
	 * @throws WP_PHP_Engine_SQL_Exception When the SQL cannot be parsed.
	 */
	public function parse( $sql ) {
		$this->sql             = $sql;
		$this->tokens          = WP_PHP_Engine_Lexer::tokenize( $sql );
		$this->pos             = 0;
		$this->parameter_count = 0;

		$statements = array();
		while ( null !== $this->peek() ) {
			if ( $this->try_consume_operator( ';' ) ) {
				continue;
			}
			$statements[] = $this->parse_statement();
		}
		return $statements;
	}

	/**
	 * Parse a single statement.
	 *
	 * @return array The statement node.
	 * @throws WP_PHP_Engine_SQL_Exception When the statement cannot be parsed.
	 */
	private function parse_statement() {
		$token = $this->peek();
		if ( self::is_keyword( $token ) ) {
			switch ( $token[1] ) {
				case 'SELECT':
				case 'VALUES':
					return $this->parse_select();
				case 'WITH':
					return $this->parse_with_statement();
				case 'INSERT':
				case 'REPLACE':
					return $this->parse_insert();
				case 'UPDATE':
					return $this->parse_update( array() );
				case 'DELETE':
					return $this->parse_delete( array() );
				case 'CREATE':
					return $this->parse_create();
				case 'DROP':
					return $this->parse_drop();
				case 'ALTER':
					return $this->parse_alter();
				case 'PRAGMA':
					return $this->parse_pragma();
				case 'BEGIN':
					$this->next();
					$this->try_consume_keywords( array( 'DEFERRED', 'IMMEDIATE', 'EXCLUSIVE' ) );
					$this->try_consume_keyword( 'TRANSACTION' );
					return array( 't' => 'begin' );
				case 'COMMIT':
				case 'END':
					$this->next();
					$this->try_consume_keyword( 'TRANSACTION' );
					return array( 't' => 'commit' );
				case 'ROLLBACK':
					$this->next();
					$this->try_consume_keyword( 'TRANSACTION' );
					if ( $this->try_consume_keyword( 'TO' ) ) {
						$this->try_consume_keyword( 'SAVEPOINT' );
						return array(
							't'    => 'rollback_to',
							'name' => $this->consume_identifier(),
						);
					}
					return array( 't' => 'rollback' );
				case 'SAVEPOINT':
					$this->next();
					return array(
						't'    => 'savepoint',
						'name' => $this->consume_identifier(),
					);
				case 'RELEASE':
					$this->next();
					$this->try_consume_keyword( 'SAVEPOINT' );
					return array(
						't'    => 'release',
						'name' => $this->consume_identifier(),
					);
				case 'ANALYZE':
				case 'REINDEX':
				case 'VACUUM':
					$this->next();
					$name = null;
					if ( null !== $this->peek() && ! $this->peek_operator( ';' ) ) {
						$name = $this->consume_identifier();
						if ( $this->try_consume_operator( '.' ) ) {
							$name = $this->consume_identifier();
						}
					}
					if ( 'ANALYZE' === $token[1] ) {
						return array(
							't'    => 'analyze',
							'name' => $name,
						);
					}
					return array( 't' => 'noop' );
			}
		}
		throw new WP_PHP_Engine_SQL_Exception(
			sprintf( 'near "%s": syntax error', $this->token_text( $token ) )
		);
	}

	/**
	 * Parse a statement starting with a WITH clause.
	 *
	 * @return array The statement node.
	 */
	private function parse_with_statement() {
		$with  = $this->parse_with_clause();
		$token = $this->peek();
		if ( self::is_keyword( $token ) ) {
			switch ( $token[1] ) {
				case 'SELECT':
				case 'VALUES':
					return $this->parse_select( $with );
				case 'UPDATE':
					return $this->parse_update( $with );
				case 'DELETE':
					return $this->parse_delete( $with );
				case 'INSERT':
				case 'REPLACE':
					$insert         = $this->parse_insert();
					$insert['with'] = $with;
					return $insert;
			}
		}
		throw new WP_PHP_Engine_SQL_Exception(
			sprintf( 'near "%s": syntax error', $this->token_text( $token ) )
		);
	}

	/**
	 * Parse a WITH clause (after the WITH keyword).
	 *
	 * @return array A map of CTE name => array( cols, select ).
	 */
	private function parse_with_clause() {
		$this->consume_keyword( 'WITH' );
		$this->try_consume_keyword( 'RECURSIVE' );
		$ctes = array();
		do {
			$name = $this->consume_identifier();
			$cols = null;
			if ( $this->try_consume_operator( '(' ) ) {
				$cols = array();
				do {
					$cols[] = $this->consume_identifier();
				} while ( $this->try_consume_operator( ',' ) );
				$this->consume_operator( ')' );
			}
			$this->consume_keyword( 'AS' );
			$this->try_consume_keyword( 'NOT' ); // NOT MATERIALIZED.
			$this->try_consume_identifier_word( 'MATERIALIZED' );
			$this->consume_operator( '(' );
			$select = $this->parse_select();
			$this->consume_operator( ')' );
			$ctes[ strtolower( $name ) ] = array(
				'cols' => $cols,
				'sel'  => $select,
			);
		} while ( $this->try_consume_operator( ',' ) );
		return $ctes;
	}

	/**
	 * Parse a SELECT statement (or VALUES statement), including compound
	 * queries, ORDER BY, and LIMIT clauses.
	 *
	 * @param  array $with Optional CTE map from a preceding WITH clause.
	 * @return array       The select node.
	 */
	private function parse_select( $with = array() ) {
		if ( $this->peek_keyword( 'WITH' ) ) {
			$with = $this->parse_with_clause();
		}

		$parts = array( $this->parse_select_core() );
		$ops   = array();
		while ( true ) {
			$token = $this->peek();
			if ( ! self::is_keyword( $token ) ) {
				break;
			}
			if ( 'UNION' === $token[1] ) {
				$this->next();
				$ops[] = $this->try_consume_keyword( 'ALL' ) ? 'UNION ALL' : 'UNION';
			} elseif ( 'EXCEPT' === $token[1] || 'INTERSECT' === $token[1] ) {
				$this->next();
				$ops[] = $token[1];
			} else {
				break;
			}
			$parts[] = $this->parse_select_core();
		}

		$order = null;
		if ( $this->try_consume_keyword( 'ORDER' ) ) {
			$this->consume_keyword( 'BY' );
			$order = $this->parse_order_by_list();
		}

		$limit  = null;
		$offset = null;
		if ( $this->try_consume_keyword( 'LIMIT' ) ) {
			$limit = $this->parse_expr();
			if ( $this->try_consume_keyword( 'OFFSET' ) ) {
				$offset = $this->parse_expr();
			} elseif ( $this->try_consume_operator( ',' ) ) {
				// "LIMIT offset, limit" form.
				$offset = $limit;
				$limit  = $this->parse_expr();
			}
		}

		return array(
			't'      => 'select',
			'with'   => $with,
			'parts'  => $parts,
			'ops'    => $ops,
			'order'  => $order,
			'limit'  => $limit,
			'offset' => $offset,
		);
	}

	/**
	 * Parse one core SELECT (or VALUES) body without compound operators.
	 *
	 * @return array The select core node.
	 */
	private function parse_select_core() {
		if ( $this->try_consume_keyword( 'VALUES' ) ) {
			$rows = array();
			do {
				$this->consume_operator( '(' );
				$row = array();
				do {
					$row[] = $this->parse_expr();
				} while ( $this->try_consume_operator( ',' ) );
				$this->consume_operator( ')' );
				$rows[] = $row;
			} while ( $this->try_consume_operator( ',' ) );
			return array(
				't'    => 'values',
				'rows' => $rows,
			);
		}

		$this->consume_keyword( 'SELECT' );
		$distinct = false;
		if ( $this->try_consume_keyword( 'DISTINCT' ) ) {
			$distinct = true;
		} else {
			$this->try_consume_keyword( 'ALL' );
		}

		// Select items.
		$items = array();
		do {
			if ( $this->try_consume_operator( '*' ) ) {
				$items[] = array(
					'star' => true,
					'tbl'  => null,
				);
				continue;
			}
			// "table.*" requires lookahead.
			$token = $this->peek();
			if ( self::is_identifier( $token )
				&& $this->peek_operator_at( 1, '.' )
				&& $this->peek_operator_at( 2, '*' ) ) {
				$this->next();
				$this->next();
				$this->next();
				$items[] = array(
					'star' => true,
					'tbl'  => $token[1],
				);
				continue;
			}
			$expr_start = $this->pos;
			$expr       = $this->parse_expr();
			$expr_end   = $this->pos - 1;
			$alias      = null;
			if ( $this->try_consume_keyword( 'AS' ) ) {
				$alias = $this->consume_identifier_or_string();
			} else {
				$next = $this->peek();
				if ( self::is_identifier( $next ) || self::is_string( $next ) ) {
					$this->next();
					$alias = $next[1];
				}
			}
			$items[] = array(
				'e'     => $expr,
				'alias' => $alias,
				'text'  => $this->source_text( $expr_start, $expr_end ),
			);
		} while ( $this->try_consume_operator( ',' ) );

		// FROM clause.
		$from = null;
		if ( $this->try_consume_keyword( 'FROM' ) ) {
			$from = $this->parse_from_clause();
		}

		$where = null;
		if ( $this->try_consume_keyword( 'WHERE' ) ) {
			$where = $this->parse_expr();
		}

		$group  = null;
		$having = null;
		if ( $this->try_consume_keyword( 'GROUP' ) ) {
			$this->consume_keyword( 'BY' );
			$group = array();
			do {
				$group[] = $this->parse_expr();
			} while ( $this->try_consume_operator( ',' ) );
			if ( $this->try_consume_keyword( 'HAVING' ) ) {
				$having = $this->parse_expr();
			}
		}

		return array(
			't'        => 'core',
			'distinct' => $distinct,
			'items'    => $items,
			'from'     => $from,
			'where'    => $where,
			'group'    => $group,
			'having'   => $having,
		);
	}

	/**
	 * Parse a FROM clause (a join tree).
	 *
	 * @return array The from-reference node.
	 */
	private function parse_from_clause() {
		$left = $this->parse_table_or_subquery();
		while ( true ) {
			$kind = null;
			if ( $this->try_consume_operator( ',' ) ) {
				$kind = 'CROSS';
			} elseif ( $this->try_consume_keyword( 'CROSS' ) ) {
				$this->consume_keyword( 'JOIN' );
				$kind = 'CROSS';
			} elseif ( $this->try_consume_keyword( 'INNER' ) ) {
				$this->consume_keyword( 'JOIN' );
				$kind = 'INNER';
			} elseif ( $this->try_consume_keyword( 'JOIN' ) ) {
				$kind = 'INNER';
			} elseif ( $this->try_consume_keyword( 'LEFT' ) ) {
				$this->try_consume_keyword( 'OUTER' );
				$this->consume_keyword( 'JOIN' );
				$kind = 'LEFT';
			} elseif ( $this->try_consume_keyword( 'RIGHT' ) ) {
				$this->try_consume_keyword( 'OUTER' );
				$this->consume_keyword( 'JOIN' );
				$kind = 'RIGHT';
			} else {
				break;
			}

			$right = $this->parse_table_or_subquery();
			$on    = null;
			$using = null;
			if ( 'CROSS' !== $kind || $this->peek_keyword( 'ON' ) || $this->peek_keyword( 'USING' ) ) {
				if ( $this->try_consume_keyword( 'ON' ) ) {
					$on = $this->parse_expr();
				} elseif ( $this->try_consume_keyword( 'USING' ) ) {
					$this->consume_operator( '(' );
					$using = array();
					do {
						$using[] = $this->consume_identifier();
					} while ( $this->try_consume_operator( ',' ) );
					$this->consume_operator( ')' );
				}
			}
			$left = array(
				't'     => 'join',
				'kind'  => $kind,
				'l'     => $left,
				'r'     => $right,
				'on'    => $on,
				'using' => $using,
			);
		}
		return $left;
	}

	/**
	 * Parse a single table reference, subquery, VALUES list, or
	 * table-valued function in a FROM clause.
	 *
	 * @return array The table-reference node.
	 */
	private function parse_table_or_subquery() {
		// Parenthesized: subquery, VALUES, or a parenthesized join.
		if ( $this->try_consume_operator( '(' ) ) {
			if ( $this->peek_keyword( 'SELECT' ) || $this->peek_keyword( 'WITH' ) || $this->peek_keyword( 'VALUES' ) ) {
				$select = $this->parse_select();
				$this->consume_operator( ')' );
				$ref = array(
					't'   => 'subquery',
					'sel' => $select,
				);
			} else {
				$ref = $this->parse_from_clause();
				$this->consume_operator( ')' );
			}
			$ref['alias'] = $this->parse_table_alias();
			return $ref;
		}

		$db   = null;
		$name = $this->consume_identifier();
		if ( $this->try_consume_operator( '.' ) ) {
			$db   = $name;
			$name = $this->consume_identifier();
		}

		// Table-valued functions, e.g. pragma_table_info(...).
		if ( $this->try_consume_operator( '(' ) ) {
			$args = array();
			if ( ! $this->peek_operator( ')' ) ) {
				do {
					$args[] = $this->parse_expr();
				} while ( $this->try_consume_operator( ',' ) );
			}
			$this->consume_operator( ')' );
			return array(
				't'     => 'tablefn',
				'name'  => strtolower( $name ),
				'args'  => $args,
				'alias' => $this->parse_table_alias(),
			);
		}

		$alias = $this->parse_table_alias();
		if ( $this->try_consume_keyword( 'INDEXED' ) ) {
			$this->consume_keyword( 'BY' );
			$this->consume_identifier();
		} elseif ( $this->try_consume_keyword( 'NOT' ) ) {
			$this->consume_keyword( 'INDEXED' );
		}
		return array(
			't'     => 'table',
			'db'    => $db,
			'name'  => $name,
			'alias' => $alias,
		);
	}

	/**
	 * Parse an optional table alias.
	 *
	 * @return string|null The alias, if present.
	 */
	private function parse_table_alias() {
		if ( $this->try_consume_keyword( 'AS' ) ) {
			return $this->consume_identifier_or_string();
		}
		$token = $this->peek();
		if ( self::is_identifier( $token ) || self::is_string( $token ) ) {
			$this->next();
			return $token[1];
		}
		return null;
	}

	/**
	 * Parse an ORDER BY item list.
	 *
	 * @return array A list of order items: array( e, dir, collate ).
	 */
	private function parse_order_by_list() {
		$order = array();
		do {
			$expr    = $this->parse_expr();
			$collate = null;
			if ( isset( $expr['t'] ) && 'collate' === $expr['t'] ) {
				$collate = $expr['name'];
				$expr    = $expr['e'];
			}
			$dir = 'ASC';
			if ( $this->try_consume_keyword( 'DESC' ) ) {
				$dir = 'DESC';
			} else {
				$this->try_consume_keyword( 'ASC' );
			}
			// NULLS FIRST/LAST.
			if ( $this->try_consume_identifier_word( 'NULLS' ) ) {
				$this->next();
			}
			$order[] = array(
				'e'       => $expr,
				'dir'     => $dir,
				'collate' => $collate,
			);
		} while ( $this->try_consume_operator( ',' ) );
		return $order;
	}

	/**
	 * Parse an INSERT or REPLACE statement.
	 *
	 * @return array The insert node.
	 */
	private function parse_insert() {
		$or = null;
		if ( $this->try_consume_keyword( 'REPLACE' ) ) {
			$or = 'REPLACE';
		} else {
			$this->consume_keyword( 'INSERT' );
			if ( $this->try_consume_keyword( 'OR' ) ) {
				$token = $this->next();
				$or    = $token[1];
			}
		}
		$this->consume_keyword( 'INTO' );
		$db   = null;
		$name = $this->consume_identifier();
		if ( $this->try_consume_operator( '.' ) ) {
			$db   = strtolower( $name );
			$name = $this->consume_identifier();
		}
		$alias = null;
		if ( $this->try_consume_keyword( 'AS' ) ) {
			$alias = $this->consume_identifier();
		}

		$cols = null;
		if ( $this->try_consume_operator( '(' ) ) {
			$cols = array();
			do {
				$cols[] = $this->consume_identifier();
			} while ( $this->try_consume_operator( ',' ) );
			$this->consume_operator( ')' );
		}

		if ( $this->try_consume_keyword( 'DEFAULT' ) ) {
			$this->consume_keyword( 'VALUES' );
			$src = array( 't' => 'default_values' );
		} else {
			$src = $this->parse_select();
		}

		$upsert = null;
		if ( $this->try_consume_keyword( 'ON' ) ) {
			$this->consume_keyword( 'CONFLICT' );
			$conflict_cols = null;
			if ( $this->try_consume_operator( '(' ) ) {
				$conflict_cols = array();
				do {
					$conflict_cols[] = $this->consume_identifier();
					// Optional COLLATE and direction in the conflict target.
					if ( $this->try_consume_keyword( 'COLLATE' ) ) {
						$this->consume_identifier();
					}
					$this->try_consume_keywords( array( 'ASC', 'DESC' ) );
				} while ( $this->try_consume_operator( ',' ) );
				$this->consume_operator( ')' );
				if ( $this->try_consume_keyword( 'WHERE' ) ) {
					$this->parse_expr();
				}
			}
			$this->consume_keyword( 'DO' );
			if ( $this->try_consume_keyword( 'NOTHING' ) ) {
				$upsert = array(
					'cols' => $conflict_cols,
					'do'   => 'nothing',
				);
			} else {
				$this->consume_keyword( 'UPDATE' );
				$this->consume_keyword( 'SET' );
				$set   = $this->parse_set_list();
				$where = null;
				if ( $this->try_consume_keyword( 'WHERE' ) ) {
					$where = $this->parse_expr();
				}
				$upsert = array(
					'cols'  => $conflict_cols,
					'do'    => 'update',
					'set'   => $set,
					'where' => $where,
				);
			}
		}

		return array(
			't'      => 'insert',
			'or'     => $or,
			'tbl'    => $name,
			'db'     => $db,
			'alias'  => $alias,
			'cols'   => $cols,
			'src'    => $src,
			'upsert' => $upsert,
			'with'   => array(),
		);
	}

	/**
	 * Parse a SET assignment list (for UPDATE and upserts).
	 *
	 * @return array A list of assignments: array( cols, e ).
	 */
	private function parse_set_list() {
		$set = array();
		do {
			// Multi-column assignment: SET (a, b) = (SELECT ...).
			if ( $this->try_consume_operator( '(' ) ) {
				$cols = array();
				do {
					$cols[] = $this->consume_identifier();
				} while ( $this->try_consume_operator( ',' ) );
				$this->consume_operator( ')' );
				$this->consume_operator( '=' );
				$set[] = array(
					'cols' => $cols,
					'e'    => $this->parse_expr(),
				);
				continue;
			}
			// Column name, optionally qualified with the table name.
			$col = $this->consume_identifier();
			if ( $this->try_consume_operator( '.' ) ) {
				$col = $this->consume_identifier();
			}
			$this->consume_operator( '=' );
			$set[] = array(
				'col' => $col,
				'e'   => $this->parse_expr(),
			);
		} while ( $this->try_consume_operator( ',' ) );
		return $set;
	}

	/**
	 * Parse an UPDATE statement.
	 *
	 * @param  array $with The CTE map from a preceding WITH clause.
	 * @return array       The update node.
	 */
	private function parse_update( $with ) {
		$this->consume_keyword( 'UPDATE' );
		if ( $this->try_consume_keyword( 'OR' ) ) {
			$this->next();
		}
		$db   = null;
		$name = $this->consume_identifier();
		if ( $this->try_consume_operator( '.' ) ) {
			$db   = strtolower( $name );
			$name = $this->consume_identifier();
		}
		$alias = $this->parse_table_alias();
		$this->consume_keyword( 'SET' );
		$set = $this->parse_set_list();

		$from = null;
		if ( $this->try_consume_keyword( 'FROM' ) ) {
			$from = $this->parse_from_clause();
		}
		$where = null;
		if ( $this->try_consume_keyword( 'WHERE' ) ) {
			$where = $this->parse_expr();
		}
		return array(
			't'     => 'update',
			'with'  => $with,
			'tbl'   => $name,
			'db'    => $db,
			'alias' => $alias,
			'set'   => $set,
			'from'  => $from,
			'where' => $where,
		);
	}

	/**
	 * Parse a DELETE statement.
	 *
	 * @param  array $with The CTE map from a preceding WITH clause.
	 * @return array       The delete node.
	 */
	private function parse_delete( $with ) {
		$this->consume_keyword( 'DELETE' );
		$this->consume_keyword( 'FROM' );
		$db   = null;
		$name = $this->consume_identifier();
		if ( $this->try_consume_operator( '.' ) ) {
			$db   = strtolower( $name );
			$name = $this->consume_identifier();
		}
		$alias = $this->parse_table_alias();
		$where = null;
		if ( $this->try_consume_keyword( 'WHERE' ) ) {
			$where = $this->parse_expr();
		}
		return array(
			't'     => 'delete',
			'with'  => $with,
			'tbl'   => $name,
			'db'    => $db,
			'alias' => $alias,
			'where' => $where,
		);
	}

	/**
	 * Parse a CREATE statement (TABLE, INDEX, TRIGGER, or VIEW).
	 *
	 * @return array The create node.
	 */
	private function parse_create() {
		$this->consume_keyword( 'CREATE' );
		$temporary = false;
		if ( $this->try_consume_keyword( 'TEMPORARY' ) || $this->try_consume_keyword( 'TEMP' ) ) {
			$temporary = true;
		}

		if ( $this->try_consume_keyword( 'TABLE' ) ) {
			return $this->parse_create_table( $temporary );
		}

		$unique = $this->try_consume_keyword( 'UNIQUE' );
		if ( $this->try_consume_keyword( 'INDEX' ) ) {
			return $this->parse_create_index( $unique );
		}

		if ( $this->try_consume_keyword( 'TRIGGER' ) ) {
			return $this->parse_create_trigger( $temporary );
		}

		if ( $this->try_consume_keyword( 'VIEW' ) ) {
			$if_not_exists = $this->parse_if_not_exists();
			$name          = $this->consume_qualified_name();
			$this->consume_keyword( 'AS' );
			$select = $this->parse_select();
			return array(
				't'             => 'create_view',
				'name'          => $name,
				'if_not_exists' => $if_not_exists,
				'sel'           => $select,
				'sql'           => trim( $this->sql ),
			);
		}

		throw new WP_PHP_Engine_SQL_Exception(
			sprintf( 'near "%s": syntax error', $this->token_text( $this->peek() ) )
		);
	}

	/**
	 * Parse an optional "IF NOT EXISTS" clause.
	 *
	 * @return bool Whether the clause was present.
	 */
	private function parse_if_not_exists() {
		if ( $this->try_consume_keyword( 'IF' ) ) {
			$this->consume_keyword( 'NOT' );
			$this->consume_keyword( 'EXISTS' );
			return true;
		}
		return false;
	}

	/**
	 * Parse a possibly schema-qualified object name, ignoring the schema.
	 *
	 * @return string The object name.
	 */
	private function consume_qualified_name() {
		$name = $this->consume_identifier();
		if ( $this->try_consume_operator( '.' ) ) {
			$name = $this->consume_identifier();
		}
		return $name;
	}

	/**
	 * Parse a CREATE TABLE statement (after CREATE [TEMP] TABLE).
	 *
	 * @param  bool $temporary Whether the table is temporary.
	 * @return array           The create-table node.
	 */
	private function parse_create_table( $temporary ) {
		$if_not_exists = $this->parse_if_not_exists();
		$name          = $this->consume_qualified_name();

		// CREATE TABLE ... AS SELECT.
		if ( $this->try_consume_keyword( 'AS' ) ) {
			return array(
				't'             => 'create_table_as',
				'name'          => $name,
				'temp'          => $temporary,
				'if_not_exists' => $if_not_exists,
				'sel'           => $this->parse_select(),
				'sql'           => trim( $this->sql ),
			);
		}

		$this->consume_operator( '(' );
		$columns     = array();
		$constraints = array();
		do {
			$token = $this->peek();
			if ( self::is_keyword( $token ) && in_array( $token[1], array( 'PRIMARY', 'UNIQUE', 'CHECK', 'FOREIGN', 'CONSTRAINT' ), true ) ) {
				$constraints[] = $this->parse_table_constraint();
			} else {
				$columns[] = $this->parse_column_definition();
			}
		} while ( $this->try_consume_operator( ',' ) );
		$this->consume_operator( ')' );

		$strict        = false;
		$without_rowid = false;
		while ( true ) {
			if ( $this->try_consume_keyword( 'STRICT' ) ) {
				$strict = true;
			} elseif ( $this->try_consume_keyword( 'WITHOUT' ) ) {
				$this->consume_identifier(); // "ROWID".
				$without_rowid = true;
			} else {
				break;
			}
			if ( ! $this->try_consume_operator( ',' ) ) {
				break;
			}
		}

		return array(
			't'             => 'create_table',
			'name'          => $name,
			'temp'          => $temporary,
			'if_not_exists' => $if_not_exists,
			'columns'       => $columns,
			'constraints'   => $constraints,
			'strict'        => $strict,
			'without_rowid' => $without_rowid,
			'sql'           => trim( $this->sql ),
		);
	}

	/**
	 * Parse a column definition in CREATE TABLE.
	 *
	 * @return array The column definition.
	 */
	private function parse_column_definition() {
		$name   = $this->consume_identifier_or_string();
		$column = array(
			'name'          => $name,
			'type'          => null,
			'notnull'       => false,
			'default'       => null,
			'default_text'  => null,
			'has_default'   => false,
			'pk'            => false,
			'pk_desc'       => false,
			'autoincrement' => false,
			'unique'        => false,
			'collate'       => null,
			'checks'        => array(),
			'fk'            => null,
			'generated'     => false,
		);

		// Optional data type: one or more words, optionally with (N) or (N, M).
		$type_parts = array();
		while ( true ) {
			$token = $this->peek();
			if ( self::is_identifier( $token ) ) {
				// Make sure this is not an alias-like trailing word.
				$type_parts[] = $token[1];
				$this->next();
				continue;
			}
			break;
		}
		if ( count( $type_parts ) > 0 && $this->try_consume_operator( '(' ) ) {
			$numbers = array();
			do {
				$num_token = $this->next();
				$numbers[] = $num_token[1];
			} while ( $this->try_consume_operator( ',' ) );
			$this->consume_operator( ')' );
			$type_parts[] = '(' . implode( ',', $numbers ) . ')';
		}
		if ( count( $type_parts ) > 0 ) {
			$column['type'] = implode( ' ', $type_parts );
			// SQLite normalizes single-word standard types to uppercase.
			if ( 1 === count( $type_parts )
				&& in_array( strtoupper( $column['type'] ), array( 'TEXT', 'INT', 'INTEGER', 'BLOB', 'REAL', 'DOUBLE', 'ANY' ), true ) ) {
				$column['type'] = strtoupper( $column['type'] );
			}
		}

		// Column constraints.
		while ( true ) {
			$constraint_name = null;
			if ( $this->try_consume_keyword( 'CONSTRAINT' ) ) {
				$constraint_name = $this->consume_identifier();
			}
			if ( $this->try_consume_keyword( 'PRIMARY' ) ) {
				$this->consume_keyword( 'KEY' );
				$column['pk'] = true;
				if ( $this->try_consume_keyword( 'DESC' ) ) {
					$column['pk_desc'] = true;
				} else {
					$this->try_consume_keyword( 'ASC' );
				}
				$this->parse_conflict_clause();
				if ( $this->try_consume_keyword( 'AUTOINCREMENT' ) ) {
					$column['autoincrement'] = true;
				}
			} elseif ( $this->try_consume_keyword( 'NOT' ) ) {
				$this->consume_keyword( 'NULL' );
				$column['notnull'] = true;
				$this->parse_conflict_clause();
			} elseif ( $this->try_consume_keyword( 'NULL' ) ) {
				continue;
			} elseif ( $this->try_consume_keyword( 'UNIQUE' ) ) {
				$column['unique'] = true;
				$this->parse_conflict_clause();
			} elseif ( $this->try_consume_keyword( 'CHECK' ) ) {
				$this->consume_operator( '(' );
				$column['checks'][] = array(
					'name' => $constraint_name,
					'e'    => $this->parse_expr(),
				);
				$this->consume_operator( ')' );
			} elseif ( $this->try_consume_keyword( 'DEFAULT' ) ) {
				$column['has_default'] = true;
				if ( $this->try_consume_operator( '(' ) ) {
					$default_start     = $this->pos;
					$column['default'] = $this->parse_expr();
					$default_end       = $this->pos - 1;
					$this->consume_operator( ')' );
				} else {
					$default_start     = $this->pos;
					$column['default'] = $this->parse_default_literal();
					$default_end       = $this->pos - 1;
				}
				// SQLite reports the default as written in the DDL.
				$column['default_text'] = $this->source_text( $default_start, $default_end );
			} elseif ( $this->try_consume_keyword( 'COLLATE' ) ) {
				$column['collate'] = strtoupper( $this->consume_identifier() );
			} elseif ( $this->try_consume_keyword( 'REFERENCES' ) ) {
				$column['fk'] = $this->parse_foreign_key_target( array( $name ) );
			} elseif ( $this->peek_keyword( 'GENERATED' ) || $this->try_consume_identifier_word( 'GENERATED' ) ) {
				// GENERATED ALWAYS AS ( expr ) [STORED|VIRTUAL].
				$this->try_consume_identifier_word( 'ALWAYS' );
				$this->consume_keyword( 'AS' );
				$this->consume_operator( '(' );
				$column['generated'] = $this->parse_expr();
				$this->consume_operator( ')' );
				$this->try_consume_identifier_word( 'STORED' );
				$this->try_consume_keyword( 'VIRTUAL' );
			} else {
				if ( null !== $constraint_name ) {
					throw new WP_PHP_Engine_SQL_Exception(
						sprintf( 'near "%s": syntax error', $this->token_text( $this->peek() ) )
					);
				}
				break;
			}
		}

		return $column;
	}

	/**
	 * Parse a literal value for a DEFAULT clause.
	 *
	 * @return array The expression node.
	 */
	private function parse_default_literal() {
		// DEFAULT accepts literals, signed numbers, and certain keywords.
		return $this->parse_expr_unary();
	}

	/**
	 * Parse an ON CONFLICT clause in column/table constraints (ignored).
	 */
	private function parse_conflict_clause() {
		if ( $this->try_consume_keyword( 'ON' ) ) {
			$this->consume_keyword( 'CONFLICT' );
			$this->next(); // ROLLBACK | ABORT | FAIL | IGNORE | REPLACE.
		}
	}

	/**
	 * Parse a table-level constraint in CREATE TABLE.
	 *
	 * @return array The constraint node.
	 */
	private function parse_table_constraint() {
		$name = null;
		if ( $this->try_consume_keyword( 'CONSTRAINT' ) ) {
			$name = $this->consume_identifier();
		}
		if ( $this->try_consume_keyword( 'PRIMARY' ) ) {
			$this->consume_keyword( 'KEY' );
			$cols = $this->parse_indexed_column_list();
			$this->parse_conflict_clause();
			return array(
				'kind' => 'pk',
				'name' => $name,
				'cols' => $cols,
			);
		}
		if ( $this->try_consume_keyword( 'UNIQUE' ) ) {
			$cols = $this->parse_indexed_column_list();
			$this->parse_conflict_clause();
			return array(
				'kind' => 'unique',
				'name' => $name,
				'cols' => $cols,
			);
		}
		if ( $this->try_consume_keyword( 'CHECK' ) ) {
			$this->consume_operator( '(' );
			$expr = $this->parse_expr();
			$this->consume_operator( ')' );
			return array(
				'kind' => 'check',
				'name' => $name,
				'e'    => $expr,
			);
		}
		if ( $this->try_consume_keyword( 'FOREIGN' ) ) {
			$this->consume_keyword( 'KEY' );
			$this->consume_operator( '(' );
			$cols = array();
			do {
				$cols[] = $this->consume_identifier();
			} while ( $this->try_consume_operator( ',' ) );
			$this->consume_operator( ')' );
			$this->consume_keyword( 'REFERENCES' );
			return array(
				'kind' => 'fk',
				'name' => $name,
				'fk'   => $this->parse_foreign_key_target( $cols ),
			);
		}
		throw new WP_PHP_Engine_SQL_Exception(
			sprintf( 'near "%s": syntax error', $this->token_text( $this->peek() ) )
		);
	}

	/**
	 * Parse the target of a REFERENCES clause.
	 *
	 * @param  array $cols The referencing column names.
	 * @return array       The foreign key definition.
	 */
	private function parse_foreign_key_target( $cols ) {
		$table    = $this->consume_identifier();
		$ref_cols = null;
		if ( $this->try_consume_operator( '(' ) ) {
			$ref_cols = array();
			do {
				$ref_cols[] = $this->consume_identifier();
			} while ( $this->try_consume_operator( ',' ) );
			$this->consume_operator( ')' );
		}
		$on_delete = 'NO ACTION';
		$on_update = 'NO ACTION';
		while ( true ) {
			if ( $this->try_consume_keyword( 'ON' ) ) {
				$which  = $this->next();
				$action = $this->parse_foreign_key_action();
				if ( 'DELETE' === $which[1] ) {
					$on_delete = $action;
				} else {
					$on_update = $action;
				}
			} elseif ( $this->try_consume_keyword( 'MATCH' ) ) {
				$this->next();
			} elseif ( $this->try_consume_keyword( 'NOT' ) || $this->peek_keyword( 'DEFERRABLE' ) ) {
				$this->try_consume_keyword( 'DEFERRABLE' );
				if ( $this->try_consume_keyword( 'INITIALLY' ) ) {
					$this->next();
				}
			} else {
				break;
			}
		}
		return array(
			'cols'      => $cols,
			'ref_table' => $table,
			'ref_cols'  => $ref_cols,
			'on_delete' => $on_delete,
			'on_update' => $on_update,
		);
	}

	/**
	 * Parse a foreign key action.
	 *
	 * @return string The action.
	 */
	private function parse_foreign_key_action() {
		if ( $this->try_consume_keyword( 'CASCADE' ) ) {
			return 'CASCADE';
		}
		if ( $this->try_consume_keyword( 'RESTRICT' ) ) {
			return 'RESTRICT';
		}
		if ( $this->try_consume_keyword( 'NO' ) ) {
			$this->consume_keyword( 'ACTION' );
			return 'NO ACTION';
		}
		if ( $this->try_consume_keyword( 'SET' ) ) {
			if ( $this->try_consume_keyword( 'NULL' ) ) {
				return 'SET NULL';
			}
			$this->consume_keyword( 'DEFAULT' );
			return 'SET DEFAULT';
		}
		throw new WP_PHP_Engine_SQL_Exception(
			sprintf( 'near "%s": syntax error', $this->token_text( $this->peek() ) )
		);
	}

	/**
	 * Parse a parenthesized indexed-column list, e.g. "(a, b COLLATE NOCASE DESC)".
	 *
	 * @return array A list of array( name, collate, dir ).
	 */
	private function parse_indexed_column_list() {
		$this->consume_operator( '(' );
		$cols = array();
		do {
			$col     = $this->consume_identifier_or_string();
			$collate = null;
			if ( $this->try_consume_keyword( 'COLLATE' ) ) {
				$collate = strtoupper( $this->consume_identifier() );
			}
			$dir = 'ASC';
			if ( $this->try_consume_keyword( 'DESC' ) ) {
				$dir = 'DESC';
			} else {
				$this->try_consume_keyword( 'ASC' );
			}
			$cols[] = array(
				'name'    => $col,
				'collate' => $collate,
				'dir'     => $dir,
			);
		} while ( $this->try_consume_operator( ',' ) );
		$this->consume_operator( ')' );
		return $cols;
	}

	/**
	 * Parse a CREATE INDEX statement (after CREATE [UNIQUE] INDEX).
	 *
	 * @param  bool $unique Whether the index is unique.
	 * @return array        The create-index node.
	 */
	private function parse_create_index( $unique ) {
		$if_not_exists = $this->parse_if_not_exists();
		$name          = $this->consume_qualified_name();
		$this->consume_keyword( 'ON' );
		$table = $this->consume_identifier();
		$cols  = $this->parse_indexed_column_list();
		$where = null;
		if ( $this->try_consume_keyword( 'WHERE' ) ) {
			$where = $this->parse_expr();
		}
		return array(
			't'             => 'create_index',
			'name'          => $name,
			'unique'        => $unique,
			'if_not_exists' => $if_not_exists,
			'tbl'           => $table,
			'cols'          => $cols,
			'where'         => $where,
			'sql'           => trim( $this->sql ),
		);
	}

	/**
	 * Parse a CREATE TRIGGER statement (after CREATE [TEMP] TRIGGER).
	 *
	 * @param  bool $temporary Whether the trigger is temporary.
	 * @return array           The create-trigger node.
	 */
	private function parse_create_trigger( $temporary ) {
		$if_not_exists = $this->parse_if_not_exists();
		$name          = $this->consume_qualified_name();

		$timing = 'AFTER';
		if ( $this->try_consume_keyword( 'BEFORE' ) ) {
			$timing = 'BEFORE';
		} elseif ( $this->try_consume_keyword( 'AFTER' ) ) {
			$timing = 'AFTER';
		} elseif ( $this->try_consume_identifier_word( 'INSTEAD' ) ) {
			$this->consume_keyword( 'OF' );
			$timing = 'INSTEAD OF';
		}

		$event   = null;
		$of_cols = null;
		if ( $this->try_consume_keyword( 'INSERT' ) ) {
			$event = 'INSERT';
		} elseif ( $this->try_consume_keyword( 'DELETE' ) ) {
			$event = 'DELETE';
		} elseif ( $this->try_consume_keyword( 'UPDATE' ) ) {
			$event = 'UPDATE';
			if ( $this->try_consume_keyword( 'OF' ) ) {
				$of_cols = array();
				do {
					$of_cols[] = $this->consume_identifier();
				} while ( $this->try_consume_operator( ',' ) );
			}
		} else {
			throw new WP_PHP_Engine_SQL_Exception(
				sprintf( 'near "%s": syntax error', $this->token_text( $this->peek() ) )
			);
		}

		$this->consume_keyword( 'ON' );
		$table = $this->consume_qualified_name();

		if ( $this->try_consume_keyword( 'FOR' ) ) {
			$this->consume_keyword( 'EACH' );
			$this->consume_keyword( 'ROW' );
		}
		$when = null;
		if ( $this->try_consume_keyword( 'WHEN' ) ) {
			$when = $this->parse_expr();
		}

		$this->consume_keyword( 'BEGIN' );
		$body = array();
		while ( ! $this->try_consume_keyword( 'END' ) ) {
			$body[] = $this->parse_statement();
			$this->consume_operator( ';' );
		}

		return array(
			't'             => 'create_trigger',
			'name'          => $name,
			'temp'          => $temporary,
			'if_not_exists' => $if_not_exists,
			'timing'        => $timing,
			'event'         => $event,
			'of_cols'       => $of_cols,
			'tbl'           => $table,
			'when'          => $when,
			'body'          => $body,
			'sql'           => trim( $this->sql ),
		);
	}

	/**
	 * Parse a DROP statement.
	 *
	 * @return array The drop node.
	 */
	private function parse_drop() {
		$this->consume_keyword( 'DROP' );
		$token = $this->next();
		$what  = strtolower( $token[1] );
		if ( ! in_array( $what, array( 'table', 'index', 'trigger', 'view' ), true ) ) {
			throw new WP_PHP_Engine_SQL_Exception(
				sprintf( 'near "%s": syntax error', $this->token_text( $token ) )
			);
		}
		$if_exists = false;
		if ( $this->try_consume_keyword( 'IF' ) ) {
			$this->consume_keyword( 'EXISTS' );
			$if_exists = true;
		}
		return array(
			't'         => 'drop',
			'what'      => $what,
			'if_exists' => $if_exists,
			'name'      => $this->consume_qualified_name(),
		);
	}

	/**
	 * Parse an ALTER TABLE statement.
	 *
	 * @return array The alter node.
	 */
	private function parse_alter() {
		$this->consume_keyword( 'ALTER' );
		$this->consume_keyword( 'TABLE' );
		$name = $this->consume_qualified_name();

		if ( $this->try_consume_keyword( 'RENAME' ) ) {
			if ( $this->try_consume_keyword( 'TO' ) ) {
				return array(
					't'   => 'alter_rename_table',
					'tbl' => $name,
					'new' => $this->consume_identifier(),
				);
			}
			$this->try_consume_keyword( 'COLUMN' );
			$old = $this->consume_identifier();
			$this->consume_keyword( 'TO' );
			return array(
				't'   => 'alter_rename_column',
				'tbl' => $name,
				'old' => $old,
				'new' => $this->consume_identifier(),
			);
		}
		if ( $this->try_consume_keyword( 'ADD' ) ) {
			$this->try_consume_keyword( 'COLUMN' );
			return array(
				't'   => 'alter_add_column',
				'tbl' => $name,
				'col' => $this->parse_column_definition(),
			);
		}
		if ( $this->try_consume_keyword( 'DROP' ) ) {
			$this->try_consume_keyword( 'COLUMN' );
			return array(
				't'   => 'alter_drop_column',
				'tbl' => $name,
				'col' => $this->consume_identifier(),
			);
		}
		throw new WP_PHP_Engine_SQL_Exception(
			sprintf( 'near "%s": syntax error', $this->token_text( $this->peek() ) )
		);
	}

	/**
	 * Parse a PRAGMA statement.
	 *
	 * @return array The pragma node.
	 */
	private function parse_pragma() {
		$this->consume_keyword( 'PRAGMA' );
		$name = strtolower( $this->consume_qualified_name() );

		$value = null;
		$arg   = null;
		if ( $this->try_consume_operator( '=' ) ) {
			$token = $this->next();
			if ( self::is_keyword( $token ) || self::is_identifier( $token ) ) {
				$value = $token[1];
			} else {
				$value = $token[1];
			}
		} elseif ( $this->try_consume_operator( '(' ) ) {
			$token = $this->next();
			$arg   = $token[1];
			$this->consume_operator( ')' );
		}
		return array(
			't'     => 'pragma',
			'name'  => $name,
			'value' => $value,
			'arg'   => $arg,
		);
	}

	/*
	 * ----------------------------------------------------------------------
	 * Expression parsing (precedence climbing).
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Parse an expression.
	 *
	 * @return array The expression node.
	 */
	public function parse_expr() {
		return $this->parse_expr_or();
	}

	/**
	 * Parse an OR expression.
	 *
	 * @return array The expression node.
	 */
	private function parse_expr_or() {
		$left = $this->parse_expr_and();
		while ( $this->try_consume_keyword( 'OR' ) ) {
			$left = array(
				't'  => 'bin',
				'op' => 'OR',
				'l'  => $left,
				'r'  => $this->parse_expr_and(),
			);
		}
		return $left;
	}

	/**
	 * Parse an AND expression.
	 *
	 * @return array The expression node.
	 */
	private function parse_expr_and() {
		$left = $this->parse_expr_not();
		while ( $this->try_consume_keyword( 'AND' ) ) {
			$left = array(
				't'  => 'bin',
				'op' => 'AND',
				'l'  => $left,
				'r'  => $this->parse_expr_not(),
			);
		}
		return $left;
	}

	/**
	 * Parse a NOT expression.
	 *
	 * @return array The expression node.
	 */
	private function parse_expr_not() {
		if ( $this->peek_keyword( 'NOT' ) && ! $this->peek_keyword_at( 1, 'EXISTS' ) ) {
			$this->next();
			return array(
				't'  => 'un',
				'op' => 'NOT',
				'e'  => $this->parse_expr_not(),
			);
		}
		return $this->parse_expr_comparison();
	}

	/**
	 * Parse a comparison expression, including IN, LIKE, BETWEEN, and IS.
	 *
	 * @return array The expression node.
	 */
	private function parse_expr_comparison() {
		$left = $this->parse_expr_bitwise();

		while ( true ) {
			$token = $this->peek();
			if ( self::is_operator( $token ) && in_array( $token[1], array( '=', '==', '!=', '<>', '<', '<=', '>', '>=' ), true ) ) {
				$this->next();
				$op   = '==' === $token[1] ? '=' : ( '<>' === $token[1] ? '!=' : $token[1] );
				$left = array(
					't'  => 'bin',
					'op' => $op,
					'l'  => $left,
					'r'  => $this->parse_expr_bitwise(),
				);
				continue;
			}

			if ( self::is_keyword( $token ) ) {
				$not = false;
				$pos = $this->pos;
				if ( 'NOT' === $token[1] ) {
					$ahead = $this->peek_at( 1 );
					if ( self::is_keyword( $ahead ) && in_array( $ahead[1], array( 'IN', 'LIKE', 'GLOB', 'REGEXP', 'MATCH', 'BETWEEN', 'NULL' ), true ) ) {
						$this->next();
						$not   = true;
						$token = $this->peek();
					} else {
						break;
					}
				}

				if ( 'IN' === $token[1] ) {
					$this->next();
					$left = $this->parse_in_rhs( $left, $not );
					continue;
				}
				if ( in_array( $token[1], array( 'LIKE', 'GLOB', 'REGEXP', 'MATCH' ), true ) ) {
					$this->next();
					$pattern = $this->parse_expr_bitwise();
					$escape  = null;
					if ( $this->try_consume_keyword( 'ESCAPE' ) ) {
						$escape = $this->parse_expr_bitwise();
					}
					$left = array(
						't'      => 'like',
						'op'     => $token[1],
						'not'    => $not,
						'e'      => $left,
						'p'      => $pattern,
						'escape' => $escape,
					);
					continue;
				}
				if ( 'BETWEEN' === $token[1] ) {
					$this->next();
					$lo = $this->parse_expr_bitwise();
					$this->consume_keyword( 'AND' );
					$hi   = $this->parse_expr_bitwise();
					$left = array(
						't'   => 'between',
						'not' => $not,
						'e'   => $left,
						'lo'  => $lo,
						'hi'  => $hi,
					);
					continue;
				}
				if ( 'ISNULL' === $token[1] ) {
					$this->next();
					$left = array(
						't'   => 'isnull',
						'not' => false,
						'e'   => $left,
					);
					continue;
				}
				if ( 'NOTNULL' === $token[1] ) {
					$this->next();
					$left = array(
						't'   => 'isnull',
						'not' => true,
						'e'   => $left,
					);
					continue;
				}
				if ( 'IS' === $token[1] ) {
					$this->next();
					$is_not = $this->try_consume_keyword( 'NOT' );
					if ( $this->try_consume_identifier_word( 'DISTINCT' ) || $this->try_consume_keyword( 'DISTINCT' ) ) {
						$this->consume_keyword( 'FROM' );
						$is_not = ! $is_not;
					}
					$left = array(
						't'   => 'is',
						'not' => $is_not,
						'l'   => $left,
						'r'   => $this->parse_expr_bitwise(),
					);
					continue;
				}
				if ( $not ) {
					// "NOT NULL" used as an operator suffix is invalid here.
					$this->pos = $pos;
					break;
				}
			}
			break;
		}
		return $left;
	}

	/**
	 * Parse the right-hand side of an IN operator.
	 *
	 * @param  array $left The left-hand expression.
	 * @param  bool  $not  Whether the operator is NOT IN.
	 * @return array       The expression node.
	 */
	private function parse_in_rhs( $left, $not ) {
		if ( $this->try_consume_operator( '(' ) ) {
			if ( $this->peek_keyword( 'SELECT' ) || $this->peek_keyword( 'WITH' ) || $this->peek_keyword( 'VALUES' ) ) {
				$select = $this->parse_select();
				$this->consume_operator( ')' );
				return array(
					't'   => 'in',
					'not' => $not,
					'e'   => $left,
					'sub' => $select,
				);
			}
			$list = array();
			if ( ! $this->peek_operator( ')' ) ) {
				do {
					$list[] = $this->parse_expr();
				} while ( $this->try_consume_operator( ',' ) );
			}
			$this->consume_operator( ')' );
			return array(
				't'    => 'in',
				'not'  => $not,
				'e'    => $left,
				'list' => $list,
			);
		}

		return array(
			't'   => 'in',
			'not' => $not,
			'e'   => $left,
			'sub' => array(
				't'      => 'select',
				'with'   => array(),
				'parts'  => array(
					array(
						't'        => 'core',
						'distinct' => false,
						'items'    => array(
							array(
								'star' => true,
								'tbl'  => null,
							),
						),
						'from'     => $this->parse_table_or_subquery(),
						'where'    => null,
						'group'    => null,
						'having'   => null,
					),
				),
				'ops'    => array(),
				'order'  => null,
				'limit'  => null,
				'offset' => null,
			),
		);
	}

	/**
	 * Parse bitwise/shift operators (also the || concatenation operator).
	 *
	 * @return array The expression node.
	 */
	private function parse_expr_bitwise() {
		$left = $this->parse_expr_additive();
		while ( true ) {
			$token = $this->peek();
			if ( self::is_operator( $token ) && in_array( $token[1], array( '<<', '>>', '&', '|' ), true ) ) {
				$this->next();
				$left = array(
					't'  => 'bin',
					'op' => $token[1],
					'l'  => $left,
					'r'  => $this->parse_expr_additive(),
				);
				continue;
			}
			break;
		}
		return $left;
	}

	/**
	 * Parse additive operators.
	 *
	 * @return array The expression node.
	 */
	private function parse_expr_additive() {
		$left = $this->parse_expr_multiplicative();
		while ( true ) {
			$token = $this->peek();
			if ( self::is_operator( $token ) && ( '+' === $token[1] || '-' === $token[1] ) ) {
				$this->next();
				$left = array(
					't'  => 'bin',
					'op' => $token[1],
					'l'  => $left,
					'r'  => $this->parse_expr_multiplicative(),
				);
				continue;
			}
			break;
		}
		return $left;
	}

	/**
	 * Parse multiplicative operators.
	 *
	 * @return array The expression node.
	 */
	private function parse_expr_multiplicative() {
		$left = $this->parse_expr_concat();
		while ( true ) {
			$token = $this->peek();
			if ( self::is_operator( $token ) && ( '*' === $token[1] || '/' === $token[1] || '%' === $token[1] ) ) {
				$this->next();
				$left = array(
					't'  => 'bin',
					'op' => $token[1],
					'l'  => $left,
					'r'  => $this->parse_expr_concat(),
				);
				continue;
			}
			break;
		}
		return $left;
	}

	/**
	 * Parse the || concatenation operator.
	 *
	 * @return array The expression node.
	 */
	private function parse_expr_concat() {
		$left = $this->parse_expr_collate();
		while ( true ) {
			$token = $this->peek();
			if ( self::is_operator( $token ) && '||' === $token[1] ) {
				$this->next();
				$left = array(
					't'  => 'bin',
					'op' => '||',
					'l'  => $left,
					'r'  => $this->parse_expr_collate(),
				);
				continue;
			}
			break;
		}
		return $left;
	}

	/**
	 * Parse a COLLATE postfix operator.
	 *
	 * @return array The expression node.
	 */
	private function parse_expr_collate() {
		$expr = $this->parse_expr_unary();
		while ( $this->try_consume_keyword( 'COLLATE' ) ) {
			$expr = array(
				't'    => 'collate',
				'name' => strtoupper( $this->consume_identifier() ),
				'e'    => $expr,
			);
		}
		return $expr;
	}

	/**
	 * Parse unary operators.
	 *
	 * @return array The expression node.
	 */
	private function parse_expr_unary() {
		$token = $this->peek();
		if ( self::is_operator( $token ) && ( '-' === $token[1] || '+' === $token[1] || '~' === $token[1] ) ) {
			$this->next();
			return array(
				't'  => 'un',
				'op' => $token[1],
				'e'  => $this->parse_expr_unary(),
			);
		}
		return $this->parse_expr_primary();
	}

	/**
	 * Parse a primary expression.
	 *
	 * @return array The expression node.
	 */
	private function parse_expr_primary() {
		$token = $this->peek();
		if ( null === $token ) {
			throw new WP_PHP_Engine_SQL_Exception( 'incomplete input' );
		}

		// Literals.
		if ( WP_PHP_Engine_Lexer::TYPE_NUMBER === $token[0]
			|| WP_PHP_Engine_Lexer::TYPE_STRING === $token[0]
			|| WP_PHP_Engine_Lexer::TYPE_BLOB === $token[0] ) {
			$this->next();
			return array(
				't' => 'lit',
				'v' => WP_PHP_Engine_Lexer::TYPE_BLOB === $token[0] ? new WP_PHP_Engine_Blob( $token[1] ) : $token[1],
			);
		}

		// Parameters.
		if ( WP_PHP_Engine_Lexer::TYPE_PARAMETER === $token[0] ) {
			$this->next();
			if ( null === $token[1] ) {
				$index                  = $this->parameter_count;
				$this->parameter_count += 1;
			} elseif ( is_string( $token[1] ) && ctype_digit( $token[1] ) ) {
				$index                 = (int) $token[1] - 1;
				$this->parameter_count = max( $this->parameter_count, $index + 1 );
			} else {
				$index = $token[1]; // Named parameter.
			}
			return array(
				't' => 'param',
				'i' => $index,
			);
		}

		// Parenthesized expression or subquery.
		if ( self::is_operator( $token ) && '(' === $token[1] ) {
			$this->next();
			if ( $this->peek_keyword( 'SELECT' ) || $this->peek_keyword( 'WITH' ) || $this->peek_keyword( 'VALUES' ) ) {
				$select = $this->parse_select();
				$this->consume_operator( ')' );
				return array(
					't'   => 'sub',
					'sel' => $select,
				);
			}
			$exprs = array();
			do {
				$exprs[] = $this->parse_expr();
			} while ( $this->try_consume_operator( ',' ) );
			$this->consume_operator( ')' );
			if ( 1 === count( $exprs ) ) {
				return $exprs[0];
			}
			return array(
				't'     => 'row',
				'exprs' => $exprs,
			);
		}

		// Keyword-led expressions.
		if ( self::is_keyword( $token ) ) {
			switch ( $token[1] ) {
				case 'NULL':
					$this->next();
					return array(
						't' => 'lit',
						'v' => null,
					);
				case 'TRUE':
					$this->next();
					return array(
						't' => 'lit',
						'v' => 1,
					);
				case 'FALSE':
					$this->next();
					return array(
						't' => 'lit',
						'v' => 0,
					);
				case 'CURRENT_TIMESTAMP':
				case 'CURRENT_DATE':
				case 'CURRENT_TIME':
					$this->next();
					return array(
						't'  => 'now',
						'fn' => $token[1],
					);
				case 'CASE':
					return $this->parse_case();
				case 'CAST':
					$this->next();
					$this->consume_operator( '(' );
					$expr = $this->parse_expr();
					$this->consume_keyword( 'AS' );
					$type_parts = array();
					while ( true ) {
						$next = $this->peek();
						if ( self::is_identifier( $next ) ) {
							$type_parts[] = $next[1];
							$this->next();
							continue;
						}
						break;
					}
					if ( $this->try_consume_operator( '(' ) ) {
						do {
							$this->next();
						} while ( $this->try_consume_operator( ',' ) );
						$this->consume_operator( ')' );
					}
					$this->consume_operator( ')' );
					return array(
						't'  => 'cast',
						'e'  => $expr,
						'as' => implode( ' ', $type_parts ),
					);
				case 'NOT':
					// NOT EXISTS (...).
					$this->next();
					$this->consume_keyword( 'EXISTS' );
					$this->consume_operator( '(' );
					$select = $this->parse_select();
					$this->consume_operator( ')' );
					return array(
						't'   => 'exists',
						'not' => true,
						'sel' => $select,
					);
				case 'EXISTS':
					$this->next();
					$this->consume_operator( '(' );
					$select = $this->parse_select();
					$this->consume_operator( ')' );
					return array(
						't'   => 'exists',
						'not' => false,
						'sel' => $select,
					);
				case 'RAISE':
					$this->next();
					$this->consume_operator( '(' );
					$kind = $this->next();
					$msg  = null;
					if ( $this->try_consume_operator( ',' ) ) {
						$msg = $this->parse_expr();
					}
					$this->consume_operator( ')' );
					return array(
						't'    => 'raise',
						'kind' => $kind[1],
						'msg'  => $msg,
					);
				case 'REPLACE':
				case 'IF':
				case 'GLOB':
				case 'LIKE':
				case 'LEFT':
				case 'RIGHT':
					// Keywords that can also be function names.
					if ( $this->peek_operator_at( 1, '(' ) ) {
						$this->next();
						return $this->parse_function_call( $token[1] );
					}
					break;
			}
		}

		// Identifiers: column references and function calls.
		if ( self::is_identifier( $token ) ) {
			$this->next();
			if ( $this->peek_operator( '(' ) ) {
				return $this->parse_function_call( $token[1] );
			}
			$parts = array( $token[1] );
			while ( $this->peek_operator( '.' ) ) {
				$this->next();
				$next = $this->next();
				if ( null === $next || ( ! self::is_identifier( $next ) && ! self::is_keyword( $next ) && ! self::is_string( $next ) ) ) {
					throw new WP_PHP_Engine_SQL_Exception( 'expected a column name after "."' );
				}
				$parts[] = $next[1];
			}
			if ( 1 === count( $parts ) ) {
				return array(
					't'    => 'col',
					'db'   => null,
					'tbl'  => null,
					'name' => $parts[0],
				);
			}
			if ( 2 === count( $parts ) ) {
				return array(
					't'    => 'col',
					'db'   => null,
					'tbl'  => $parts[0],
					'name' => $parts[1],
				);
			}
			return array(
				't'    => 'col',
				'db'   => $parts[0],
				'tbl'  => $parts[1],
				'name' => $parts[2],
			);
		}

		// "*" as a bare expression (e.g. COUNT(*) handled in functions, but
		// also "SELECT 1 WHERE 1" style edge cases fall through to errors).
		throw new WP_PHP_Engine_SQL_Exception(
			sprintf( 'near "%s": syntax error', $this->token_text( $token ) )
		);
	}

	/**
	 * Parse a CASE expression.
	 *
	 * @return array The expression node.
	 */
	private function parse_case() {
		$this->consume_keyword( 'CASE' );
		$operand = null;
		if ( ! $this->peek_keyword( 'WHEN' ) ) {
			$operand = $this->parse_expr();
		}
		$when = array();
		while ( $this->try_consume_keyword( 'WHEN' ) ) {
			$cond = $this->parse_expr();
			$this->consume_keyword( 'THEN' );
			$when[] = array( $cond, $this->parse_expr() );
		}
		$else = null;
		if ( $this->try_consume_keyword( 'ELSE' ) ) {
			$else = $this->parse_expr();
		}
		$this->consume_keyword( 'END' );
		return array(
			't'       => 'case',
			'operand' => $operand,
			'when'    => $when,
			'else'    => $else,
		);
	}

	/**
	 * Parse a function call after its name (positioned at the opening paren).
	 *
	 * @param  string $name The function name.
	 * @return array        The expression node.
	 */
	private function parse_function_call( $name ) {
		$this->consume_operator( '(' );
		$distinct = false;
		$star     = false;
		$args     = array();
		if ( $this->try_consume_operator( '*' ) ) {
			$star = true;
		} elseif ( ! $this->peek_operator( ')' ) ) {
			if ( $this->try_consume_keyword( 'DISTINCT' ) ) {
				$distinct = true;
			}
			do {
				$args[] = $this->parse_expr();
			} while ( $this->try_consume_operator( ',' ) );
		}
		$this->consume_operator( ')' );

		// FILTER (WHERE ...) — parsed and ignored (rarely used).
		if ( $this->try_consume_keyword( 'FILTER' ) ) {
			$this->consume_operator( '(' );
			$this->consume_keyword( 'WHERE' );
			$this->parse_expr();
			$this->consume_operator( ')' );
		}

		$over = null;
		if ( $this->try_consume_keyword( 'OVER' ) ) {
			$over = array(
				'partition' => array(),
				'order'     => array(),
			);
			$this->consume_operator( '(' );
			if ( $this->try_consume_keyword( 'PARTITION' ) ) {
				$this->consume_keyword( 'BY' );
				do {
					$over['partition'][] = $this->parse_expr();
				} while ( $this->try_consume_operator( ',' ) );
			}
			if ( $this->try_consume_keyword( 'ORDER' ) ) {
				$this->consume_keyword( 'BY' );
				$over['order'] = $this->parse_order_by_list();
			}
			$this->consume_operator( ')' );
		}

		return array(
			't'        => 'fn',
			'name'     => strtolower( $name ),
			'args'     => $args,
			'distinct' => $distinct,
			'star'     => $star,
			'over'     => $over,
		);
	}

	/*
	 * ----------------------------------------------------------------------
	 * Token stream helpers.
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Peek at the current token.
	 *
	 * @return array|null The token, or null at the end of input.
	 */
	private function peek() {
		return isset( $this->tokens[ $this->pos ] ) ? $this->tokens[ $this->pos ] : null;
	}

	/**
	 * Peek at a token at the given offset from the current position.
	 *
	 * @param  int $offset The offset.
	 * @return array|null  The token, or null.
	 */
	private function peek_at( $offset ) {
		return isset( $this->tokens[ $this->pos + $offset ] ) ? $this->tokens[ $this->pos + $offset ] : null;
	}

	/**
	 * Consume and return the current token.
	 *
	 * @return array|null The token, or null at the end of input.
	 */
	private function next() {
		$token = $this->peek();
		if ( null !== $token ) {
			$this->pos += 1;
		}
		return $token;
	}

	/**
	 * Check whether a token is a keyword token.
	 *
	 * @param  array|null $token The token.
	 * @return bool              Whether the token is a keyword.
	 */
	private static function is_keyword( $token ) {
		return null !== $token && WP_PHP_Engine_Lexer::TYPE_KEYWORD === $token[0];
	}

	/**
	 * Check whether a token is an identifier token.
	 *
	 * @param  array|null $token The token.
	 * @return bool              Whether the token is an identifier.
	 */
	private static function is_identifier( $token ) {
		return null !== $token && WP_PHP_Engine_Lexer::TYPE_IDENTIFIER === $token[0];
	}

	/**
	 * Check whether a token is a string token.
	 *
	 * @param  array|null $token The token.
	 * @return bool              Whether the token is a string.
	 */
	private static function is_string( $token ) {
		return null !== $token && WP_PHP_Engine_Lexer::TYPE_STRING === $token[0];
	}

	/**
	 * Check whether a token is an operator token.
	 *
	 * @param  array|null $token The token.
	 * @return bool              Whether the token is an operator.
	 */
	private static function is_operator( $token ) {
		return null !== $token && WP_PHP_Engine_Lexer::TYPE_OPERATOR === $token[0];
	}

	/**
	 * Check whether the current token is the given keyword.
	 *
	 * @param  string $keyword The keyword.
	 * @return bool            Whether the current token matches.
	 */
	private function peek_keyword( $keyword ) {
		$token = $this->peek();
		return self::is_keyword( $token ) && $token[1] === $keyword;
	}

	/**
	 * Check whether the token at the given offset is the given keyword.
	 *
	 * @param  int    $offset  The offset.
	 * @param  string $keyword The keyword.
	 * @return bool            Whether the token matches.
	 */
	private function peek_keyword_at( $offset, $keyword ) {
		$token = $this->peek_at( $offset );
		return self::is_keyword( $token ) && $token[1] === $keyword;
	}

	/**
	 * Check whether the current token is the given operator.
	 *
	 * @param  string $op The operator.
	 * @return bool       Whether the current token matches.
	 */
	private function peek_operator( $op ) {
		$token = $this->peek();
		return self::is_operator( $token ) && $token[1] === $op;
	}

	/**
	 * Check whether the token at the given offset is the given operator.
	 *
	 * @param  int    $offset The offset.
	 * @param  string $op     The operator.
	 * @return bool           Whether the token matches.
	 */
	private function peek_operator_at( $offset, $op ) {
		$token = $this->peek_at( $offset );
		return self::is_operator( $token ) && $token[1] === $op;
	}

	/**
	 * Consume the given keyword or fail.
	 *
	 * @param  string $keyword The keyword.
	 * @throws WP_PHP_Engine_SQL_Exception When the current token does not match.
	 */
	private function consume_keyword( $keyword ) {
		if ( ! $this->try_consume_keyword( $keyword ) ) {
			throw new WP_PHP_Engine_SQL_Exception(
				sprintf( 'near "%s": syntax error', $this->token_text( $this->peek() ) )
			);
		}
	}

	/**
	 * Consume the given keyword if it is the current token.
	 *
	 * @param  string $keyword The keyword.
	 * @return bool            Whether the keyword was consumed.
	 */
	private function try_consume_keyword( $keyword ) {
		if ( $this->peek_keyword( $keyword ) ) {
			$this->pos += 1;
			return true;
		}
		return false;
	}

	/**
	 * Consume one of the given keywords if the current token matches.
	 *
	 * @param  array $keywords The keywords.
	 * @return string|null     The consumed keyword, or null.
	 */
	private function try_consume_keywords( $keywords ) {
		$token = $this->peek();
		if ( self::is_keyword( $token ) && in_array( $token[1], $keywords, true ) ) {
			$this->pos += 1;
			return $token[1];
		}
		return null;
	}

	/**
	 * Consume a specific non-keyword word (case-insensitively).
	 *
	 * @param  string $word The word.
	 * @return bool         Whether the word was consumed.
	 */
	private function try_consume_identifier_word( $word ) {
		$token = $this->peek();
		if ( self::is_identifier( $token ) && 0 === strcasecmp( $token[1], $word ) ) {
			$this->pos += 1;
			return true;
		}
		return false;
	}

	/**
	 * Consume the given operator or fail.
	 *
	 * @param  string $op The operator.
	 * @throws WP_PHP_Engine_SQL_Exception When the current token does not match.
	 */
	private function consume_operator( $op ) {
		if ( ! $this->try_consume_operator( $op ) ) {
			throw new WP_PHP_Engine_SQL_Exception(
				sprintf( 'near "%s": syntax error', $this->token_text( $this->peek() ) )
			);
		}
	}

	/**
	 * Consume the given operator if it is the current token.
	 *
	 * @param  string $op The operator.
	 * @return bool       Whether the operator was consumed.
	 */
	private function try_consume_operator( $op ) {
		if ( $this->peek_operator( $op ) ) {
			$this->pos += 1;
			return true;
		}
		return false;
	}

	/**
	 * Consume an identifier (allowing non-reserved keywords) or fail.
	 *
	 * @return string The identifier.
	 * @throws WP_PHP_Engine_SQL_Exception When the current token is not an identifier.
	 */
	private function consume_identifier() {
		$token = $this->next();
		if ( null !== $token
			&& ( WP_PHP_Engine_Lexer::TYPE_IDENTIFIER === $token[0] || WP_PHP_Engine_Lexer::TYPE_KEYWORD === $token[0] ) ) {
			return $token[1];
		}
		throw new WP_PHP_Engine_SQL_Exception(
			sprintf( 'near "%s": syntax error', $this->token_text( $token ) )
		);
	}

	/**
	 * Consume an identifier or a string literal.
	 *
	 * @return string The value.
	 * @throws WP_PHP_Engine_SQL_Exception When the current token does not match.
	 */
	private function consume_identifier_or_string() {
		$token = $this->next();
		if ( null !== $token
			&& ( WP_PHP_Engine_Lexer::TYPE_IDENTIFIER === $token[0]
				|| WP_PHP_Engine_Lexer::TYPE_KEYWORD === $token[0]
				|| WP_PHP_Engine_Lexer::TYPE_STRING === $token[0] ) ) {
			return $token[1];
		}
		throw new WP_PHP_Engine_SQL_Exception(
			sprintf( 'near "%s": syntax error', $this->token_text( $token ) )
		);
	}

	/**
	 * Get the original SQL text spanning a token range.
	 *
	 * @param  int $from The first token position.
	 * @param  int $to   The last token position.
	 * @return string    The source text.
	 */
	private function source_text( $from, $to ) {
		if ( ! isset( $this->tokens[ $from ], $this->tokens[ $to ] ) || $to < $from ) {
			return '';
		}
		$start = $this->tokens[ $from ][2];
		$end   = $this->tokens[ $to ][3];
		return substr( $this->sql, $start, $end - $start );
	}

	/**
	 * Render a token for an error message.
	 *
	 * @param  array|null $token The token.
	 * @return string            The token text.
	 */
	private function token_text( $token ) {
		if ( null === $token ) {
			return '';
		}
		return is_scalar( $token[1] ) ? (string) $token[1] : '?';
	}
}

/**
 * An exception thrown by the pure-PHP database engine.
 *
 * The message format mirrors SQLite/PDO error messages where the SQLite
 * driver depends on them.
 */
class WP_PHP_Engine_SQL_Exception extends PDOException {
	/**
	 * Constructor.
	 *
	 * The message is rendered the way PDO renders SQLite driver errors:
	 *
	 *   SQLSTATE[HY000]: General error: 1 no such table: t
	 *   SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: t.c
	 *
	 * @param string $message     The error message.
	 * @param string $sqlstate    The SQLSTATE error code.
	 * @param int    $sqlite_code The SQLite-compatible error code.
	 * @param string $category    The PDO error category text.
	 */
	public function __construct( $message, $sqlstate = 'HY000', $sqlite_code = 1, $category = 'General error' ) {
		parent::__construct(
			sprintf( 'SQLSTATE[%s]: %s: %d %s', $sqlstate, $category, $sqlite_code, $message )
		);
		// PDOException::$code is a string SQLSTATE value for driver errors.
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->code      = $sqlstate;
		$this->errorInfo = array( $sqlstate, $sqlite_code, $message );
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}
}
