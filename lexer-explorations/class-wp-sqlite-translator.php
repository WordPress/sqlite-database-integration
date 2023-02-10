<?php

require_once __DIR__ . '/class-wp-sqlite-lexer.php';
require_once __DIR__ . '/class-wp-sqlite-query-rewriter.php';
require_once __DIR__ . '/class-wp-sqlite-query.php';
require_once __DIR__ . '/class-wp-sqlite-translation-result.php';
require_once __DIR__ . '/Parser.php';

class WP_SQLite_Translator {

	// @TODO Check capability – SQLite must have a regexp function available
	private $has_regexp = false;
	private $sqlite;
	private $table_prefix;

	private $field_types_translation = array(
		'bit'        => 'integer',
		'bool'       => 'integer',
		'boolean'    => 'integer',
		'tinyint'    => 'integer',
		'smallint'   => 'integer',
		'mediumint'  => 'integer',
		'int'        => 'integer',
		'integer'    => 'integer',
		'bigint'     => 'integer',
		'float'      => 'real',
		'double'     => 'real',
		'decimal'    => 'real',
		'dec'        => 'real',
		'numeric'    => 'real',
		'fixed'      => 'real',
		'date'       => 'text',
		'datetime'   => 'text',
		'timestamp'  => 'text',
		'time'       => 'text',
		'year'       => 'text',
		'char'       => 'text',
		'varchar'    => 'text',
		'binary'     => 'integer',
		'varbinary'  => 'blob',
		'tinyblob'   => 'blob',
		'tinytext'   => 'text',
		'blob'       => 'blob',
		'text'       => 'text',
		'mediumblob' => 'blob',
		'mediumtext' => 'text',
		'longblob'   => 'blob',
		'longtext'   => 'text',
	);

	private $mysql_php_date_formats = array(
		'%a' => '%D',
		'%b' => '%M',
		'%c' => '%n',
		'%D' => '%jS',
		'%d' => '%d',
		'%e' => '%j',
		'%H' => '%H',
		'%h' => '%h',
		'%I' => '%h',
		'%i' => '%i',
		'%j' => '%z',
		'%k' => '%G',
		'%l' => '%g',
		'%M' => '%F',
		'%m' => '%m',
		'%p' => '%A',
		'%r' => '%h:%i:%s %A',
		'%S' => '%s',
		'%s' => '%s',
		'%T' => '%H:%i:%s',
		'%U' => '%W',
		'%u' => '%W',
		'%V' => '%W',
		'%v' => '%W',
		'%W' => '%l',
		'%w' => '%w',
		'%X' => '%Y',
		'%x' => '%o',
		'%Y' => '%Y',
		'%y' => '%y',
	);

	public function __construct( $pdo, $table_prefix = 'wp_' ) {
		$this->sqlite = $pdo;
		$this->sqlite->query( 'PRAGMA encoding="UTF-8";' );
		$this->table_prefix = $table_prefix;
	}

	// @TODO Remove this property?
	//       Is it weird to have this->query
	//       that only matters for the lifetime of translate()?
	private $query;
	private $query_type;
	private $last_found_rows = 0;
	function translate( string $query, $last_found_rows = null ) {
		$this->query           = $query;
		$this->last_found_rows = $last_found_rows;

		$tokens     = ( new WP_SQLite_Lexer( $query ) )->list->tokens;
		$r          = new WP_SQLite_Query_Rewriter( $tokens );
		$query_type = $r->peek()->value;
		switch ( $query_type ) {
			case 'ALTER':
				$result = $this->translate_alter( $r );
				break;
			case 'CREATE':
				$result = $this->translate_create( $r );
				break;
			case 'REPLACE':
			case 'SELECT':
			case 'INSERT':
			case 'UPDATE':
			case 'DELETE':
				$result = $this->translate_crud( $r );
				break;
			case 'CALL':
			case 'SET':
				// It would be lovely to support at least SET autocommit
				// but I don't think even that is possible with SQLite
				$result = new WP_SQLite_Translation_Result( array( $this->noop() ) );
				break;
			case 'START TRANSACTION':
				$result = new WP_SQLite_Translation_Result(
					array(
						new WP_SQLite_Query( 'BEGIN' ),
					)
				);
				break;
			case 'BEGIN':
			case 'COMMIT':
			case 'ROLLBACK':
			case 'TRUNCATE':
				$result = new WP_SQLite_Translation_Result(
					array(
						new WP_SQLite_Query( $this->query ),
					)
				);
				break;
			case 'DROP':
				$result = $this->translate_drop( $r );
				break;
			case 'DESCRIBE':
				$table_name = $r->consume()->token;
				$result     = new WP_SQLite_Translation_Result(
					array(
						new WP_SQLite_Query( "PRAGMA table_info($table_name);" ),
					)
				);
				break;
			case 'SHOW':
				$result = $this->translate_show( $r );
				break;
			default:
				throw new \Exception( 'Unknown query type: ' . $query_type );
		}
		// The query type could have changed – let's grab the new one
		if ( count( $result->queries ) ) {
			$last_query         = $result->queries[ count( $result->queries ) - 1 ];
			$result->query_type = strtoupper( strtok( $last_query->sql, ' ' ) );
		}
		return $result;
	}

	private function translate_create_table() {
		//echo '**MySQL query:**' . PHP_EOL;
		$p                            = new \PhpMyAdmin\SqlParser\Parser( $this->query );
		$stmt                         = $p->statements[0];
		$stmt->entityOptions->options = array();

		$inline_primary_key = false;
		$extra_queries      = array();

		foreach ( $stmt->fields as $k => $field ) {
			if ( $field->type && $field->type->name ) {
				$typelc = strtolower( $field->type->name );
				if ( isset( $this->field_types_translation[ $typelc ] ) ) {
					$field->type->name = $this->field_types_translation[ $typelc ];
				}
				$field->type->parameters = array();
				unset( $field->type->options->options[ WP_SQLite_Lexer::$data_type_options['UNSIGNED'] ] );
			}
			if ( $field->options && $field->options->options ) {
				if ( isset( $field->options->options[ WP_SQLite_Lexer::$field_options['AUTO_INCREMENT'] ] ) ) {
					$field->options->options[ WP_SQLite_Lexer::$field_options['AUTO_INCREMENT'] ] = 'PRIMARY KEY AUTOINCREMENT';
					$inline_primary_key = true;
					unset( $field->options->options[ WP_SQLite_Lexer::$field_options['PRIMARY KEY'] ] );
				}
			}
			if ( $field->key ) {
				if ( 'PRIMARY KEY' === $field->key->type ) {
					if ( $inline_primary_key ) {
						unset( $stmt->fields[ $k ] );
					}
				} elseif ( 'FULLTEXT KEY' === $field->key->type ) {
					unset( $stmt->fields[ $k ] );
				} elseif (
					'KEY' === $field->key->type ||
					'INDEX' === $field->key->type ||
					'UNIQUE KEY' === $field->key->type
				) {
					$columns = array();
					foreach ( $field->key->columns as $column ) {
						$columns[] = $column['name'];
					}
					$unique = '';
					if ( 'UNIQUE KEY' === $field->key->type ) {
						$unique = 'UNIQUE ';
					}
					$extra_queries[] = 'CREATE ' . $unique . ' INDEX "' . $stmt->name . '__' . $field->key->name . '" ON "' . $stmt->name . '" ("' . implode( '", "', $columns ) . '")';
					unset( $stmt->fields[ $k ] );
				}
			}
		}
		PhpMyAdmin\SqlParser\Context::setMode( WP_SQLite_Lexer::SQL_MODE_ANSI_QUOTES );
		$queries = array(
			new WP_SQLite_Query( $stmt->build() ),
		);
		foreach ( $extra_queries as $extra_query ) {
			$queries[] = new WP_SQLite_Query( $extra_query );
		}

		return new WP_SQLite_Translation_Result(
			$queries
		);
	}

	private function translate_crud( WP_SQLite_Query_Rewriter $r ) {
		// Very naive check to see if we're dealing with an information_schema
		// query. If so, we'll just return a dummy result.
		// @TODO: A proper check and a better translation.
		if ( str_contains( $this->query, 'information_schema' ) ) {
			return new WP_SQLite_Translation_Result(
				array(
					new WP_SQLite_Query(
						'SELECT \'\' as "table", 0 as "rows", 0 as "bytes'
					),
				)
			);
		}

		$query_type = $r->consume()->value;

		// Naive regexp check
		if ( ! $this->has_regexp && strpos( $this->query, ' REGEXP ' ) !== false ) {
			// Bale out if we can't run the query
			return new WP_SQLite_Translation_Result( array( $this->noop() ) );
		}

		if (
			// @TODO: Add handling for the following cases:
			strpos( $this->query, 'ORDER BY FIELD' ) !== false
			|| strpos( $this->query, '@@SESSION.sql_mode' ) !== false
			// `CONVERT( field USING charset )` is not supported
			|| strpos( $this->query, 'CONVERT( ' ) !== false
		) {
			/*
			@TODO rewrite ORDER BY FIELD(a, 1,4,6) as
			ORDER BY CASE a
				WHEN 1 THEN 0
				WHEN 4 THEN 1
				WHEN 6 THEN 2
			END
			*/
			var_dump( 'done' );
			die( $this->query );
			// Return a dummy select for now
			return 'SELECT 1=1';
		}

		// echo '**MySQL query:**' . PHP_EOL;
		// echo $query . PHP_EOL . PHP_EOL;
		$params                  = array();
		$is_in_duplicate_section = false;
		$table_name              = null;
		$has_sql_calc_found_rows = false;

		// Consume the query type
		if ( 'INSERT' === $query_type && 'IGNORE' === $r->peek()->value ) {
			$r->add( WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ) );
			$r->add( WP_SQLite_Lexer::get_token( 'OR', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ) );
			$r->consume(); // IGNORE
		}

		// Consume and record the table name
		if ( 'INSERT' === $query_type || 'REPLACE' === $query_type ) {
			$r->consume(); // INTO
			$table_name = $r->consume()->value; // table name
		}

		while ( $token = $r->consume() ) {
			if ( 'SQL_CALC_FOUND_ROWS' === $token->value && WP_SQLite_Lexer::TYPE_KEYWORD === $token->type ) {
				$has_sql_calc_found_rows = true;
				$r->drop_last();
				continue;
			}

			if ( WP_SQLite_Lexer::TYPE_STRING === $token->type && $token->flags & WP_SQLite_Lexer::FLAG_STRING_SINGLE_QUOTES ) {
				// Rewrite string values to bound parameters
				$param_name            = ':param' . count( $params );
				$params[ $param_name ] = $token->value;
				$r->replace_last( WP_SQLite_Lexer::get_token( $param_name, WP_SQLite_Lexer::TYPE_STRING, WP_SQLite_Lexer::FLAG_STRING_SINGLE_QUOTES ) );
				continue;
			}

			if ( WP_SQLite_Lexer::TYPE_KEYWORD === $token->type ) {
				if ( 'RAND' === $token->keyword && $token->flags & WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION ) {
					$r->replace_last( WP_SQLite_Lexer::get_token( 'RANDOM', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION ) );
					continue;
				}

				if (
					'CONCAT' === $token->keyword
					&& $token->flags & WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION
				) {
					$r->drop_last();
					continue;
				}

				foreach ( array(
					array( 'YEAR', '%Y' ),
					array( 'MONTH', '%M' ),
					array( 'DAY', '%D' ),
					array( 'DAYOFMONTH', '%d' ),
					array( 'DAYOFWEEK', '%w' ),
					array( 'WEEK', '%W' ),
					// @TODO fix
					//       %w returns 0 for Sunday and 6 for Saturday
					//       but weekday returns 1 for Monday and 7 for Sunday
					array( 'WEEKDAY', '%w' ),
					array( 'HOUR', '%H' ),
					array( 'MINUTE', '%M' ),
					array( 'SECOND', '%S' ),
				) as $token_item ) {
					$unit   = $token_item[0];
					$format = $token_item[1];
					if ( $token->value === $unit && $token->flags & WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION ) {
						// Drop the consumed function call:
						$r->drop_last();
						// Skip the opening "(":
						$r->skip();

						// Skip the first argument so we can read the second one
						$first_arg = $r->skip_over(
							array(
								'type'  => WP_SQLite_Lexer::TYPE_OPERATOR,
								'value' => array( ',', ')' ),
							)
						);

						$terminator = array_pop( $first_arg );

						if ( 'WEEK' === $unit ) {
							$format = '%W';
							// WEEK(date, mode) can mean different strftime formats
							// depending on the mode (default=0).
							// If the $skipped token is a comma, then we need to
							// read the mode argument.
							if ( ',' === $terminator->value ) {
								$mode = $r->skip();
								// Assume $mode is now a number
								if ( 0 === $mode->value ) {
									$format = '%U';
								} elseif ( 1 === $mode->value ) {
									$format = '%W';
								} else {
									throw new Exception( 'Could not parse the WEEK() mode' );
								}
							}
						}

						$r->add( WP_SQLite_Lexer::get_token( 'STRFTIME', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION ) );
						$r->add( WP_SQLite_Lexer::get_token( '(', WP_SQLite_Lexer::TYPE_OPERATOR ) );
						$r->add( WP_SQLite_Lexer::get_token( "'$format'", WP_SQLite_Lexer::TYPE_STRING ) );
						$r->add( WP_SQLite_Lexer::get_token( ',', WP_SQLite_Lexer::TYPE_OPERATOR ) );
						$r->add_many( $first_arg );
						if ( ')' === $terminator->value ) {
							$r->add( $terminator );
						}
						continue 2;
					}
				}

				if (
					$token->flags & WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION && (
						'DATE_ADD' === $token->keyword ||
						'DATE_SUB' === $token->keyword
					)
				) {
					$r->replace_last( WP_SQLite_Lexer::get_token( 'DATE', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION ) );
					continue;
				}

				if (
					$token->flags & WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION &&
					'VALUES' === $token->keyword &&
					$is_in_duplicate_section
				) {
					/*
					Rewrite:  VALUES(`option_name`)
					to:       excluded.option_name
					*/
					$r->replace_last( WP_SQLite_Lexer::get_token( 'excluded', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_KEY ) );
					$r->add( WP_SQLite_Lexer::get_token( '.', WP_SQLite_Lexer::TYPE_OPERATOR ) );

					$r->skip(); // Skip the opening (
					// Consume the column name
					$r->consume(
						array(
							'type'  => WP_SQLite_Lexer::TYPE_OPERATOR,
							'value' => ')',
						)
					);
					// Drop the consumed ')' token
					$r->drop_last();
					continue;
				}
				if (
					$token->flags & WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION &&
					'DATE_FORMAT' === $token->keyword
				) {
					// DATE_FORMAT( `post_date`, '%Y-%m-%d' )

					$r->replace_last( WP_SQLite_Lexer::get_token( 'STRFTIME', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION ) );
					// The opening (
					$r->consume();

					// Skip the first argument so we can read the second one
					$first_arg = $r->skip_over(
						array(
							'type'  => WP_SQLite_Lexer::TYPE_OPERATOR,
							'value' => ',',
						)
					);

					// Make sure we actually found the comma
					$comma = array_pop( $first_arg );
					if ( ',' !== $comma->value ) {
						throw new Exception( 'Could not parse the DATE_FORMAT() call' );
					}

					// Skip the second argument but capture the token
					$format     = $r->skip()->value;
					$new_format = strtr( $format, $this->mysql_php_date_formats );

					$r->add( WP_SQLite_Lexer::get_token( "'$new_format'", WP_SQLite_Lexer::TYPE_STRING ) );
					$r->add( WP_SQLite_Lexer::get_token( ',', WP_SQLite_Lexer::TYPE_OPERATOR ) );

					// Add the buffered tokens back to the stream:
					$r->add_many( $first_arg );

					continue;
				}
				if ( 'INTERVAL' === $token->keyword ) {
					// Remove the INTERVAL keyword from the output stream
					$r->drop_last();

					$num  = $r->skip()->value;
					$unit = $r->skip()->value;

					// In MySQL, we say:
					// * DATE_ADD(d, INTERVAL 1 YEAR)
					// * DATE_SUB(d, INTERVAL 1 YEAR)
					//
					// In SQLite, we say:
					// * DATE(d, '+1 YEAR')
					// * DATE(d, '-1 YEAR')

					// The sign of the interval is determined by the
					// date_* function that is closest in the call stack.
					//
					// Let's find it:
					$interval_op = '+'; // Default to adding
					for ( $j = count( $r->call_stack ) - 1; $j >= 0; $j-- ) {
						$call = $r->call_stack[ $j ];
						if ( 'DATE_ADD' === $call[0] ) {
							$interval_op = '+';
							break;
						}
						if ( 'DATE_SUB' === $call[0] ) {
							$interval_op = '-';
							break;
						}
					}

					$r->add( WP_SQLite_Lexer::get_token( "'{$interval_op}$num $unit'", WP_SQLite_Lexer::TYPE_STRING ) );
					continue;
				}
				if ( 'INSERT' === $query_type && 'DUPLICATE' === $token->keyword ) {
					/*
					Rewrite:
						ON DUPLICATE KEY UPDATE `option_name` = VALUES(`option_name`)
					to:
						ON CONFLICT(ip) DO UPDATE SET option_name = excluded.option_name
					*/

					// Replace the DUPLICATE keyword with CONFLICT
					$r->replace_last( WP_SQLite_Lexer::get_token( 'CONFLICT', WP_SQLite_Lexer::TYPE_KEYWORD ) );
					// Skip overthe "KEY" and "UPDATE" keywords
					$r->skip();
					$r->skip();

					// Add "( <primary key> ) DO UPDATE SET "
					$r->add( WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ) );
					$r->add( WP_SQLite_Lexer::get_token( '(', WP_SQLite_Lexer::TYPE_OPERATOR ) );
					// @TODO don't make assumptions about the names, only fetch
					//       the correct unique key from sqlite
					if ( str_ends_with( $table_name, '_options' ) ) {
						$pk_name = 'option_name';
					} elseif ( str_ends_with( $table_name, '_term_relationships' ) ) {
						$pk_name = 'object_id, term_taxonomy_id';
					} else {
						$q       = $this->sqlite->query( 'SELECT l.name FROM pragma_table_info("' . $table_name . '") as l WHERE l.pk = 1;' );
						$pk_name = $q->fetch()['name'];
					}
					$r->add( WP_SQLite_Lexer::get_token( $pk_name, WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_KEY ) );
					$r->add( WP_SQLite_Lexer::get_token( ')', WP_SQLite_Lexer::TYPE_OPERATOR ) );
					$r->add( WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ) );
					$r->add( WP_SQLite_Lexer::get_token( 'DO', WP_SQLite_Lexer::TYPE_KEYWORD ) );
					$r->add( WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ) );
					$r->add( WP_SQLite_Lexer::get_token( 'UPDATE', WP_SQLite_Lexer::TYPE_KEYWORD ) );
					$r->add( WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ) );
					$r->add( WP_SQLite_Lexer::get_token( 'SET', WP_SQLite_Lexer::TYPE_KEYWORD ) );
					$r->add( WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ) );

					$is_in_duplicate_section = true;
					continue;
				}
			}

			if ( WP_SQLite_Lexer::TYPE_OPERATOR === $token->type ) {
				$call_parent = $r->last_call_stack_element();
				// Rewrite commas to || in CONCAT() calls
				if (
					$call_parent
					&& 'CONCAT' === $call_parent[0]
					&& ',' === $token->value
					&& $token->flags & WP_SQLite_Lexer::FLAG_OPERATOR_SQL
				) {
					$r->replace_last( WP_SQLite_Lexer::get_token( '||', WP_SQLite_Lexer::TYPE_OPERATOR ) );
					continue;
				}
			}
		}

		$updated_query = $r->get_updated_query();
		$result        = new WP_SQLite_Translation_Result( array() );

		// Naively emulate SQL_CALC_FOUND_ROWS for now
		if ( $has_sql_calc_found_rows ) {
			// first strip the code. this is the end of rewriting process
			$query = str_ireplace( 'SQL_CALC_FOUND_ROWS', '', $updated_query );
			// we make the data for next SELECT FOUND_ROWS() statement
			$unlimited_query = preg_replace( '/\\bLIMIT\\s*.*/imsx', '', $query );
			//$unlimited_query = preg_replace('/\\bGROUP\\s*BY\\s*.*/imsx', '', $unlimited_query);
			// we no longer use SELECT COUNT query
			//$unlimited_query = $this->_transform_to_count($unlimited_query);
			$stmt                    = $this->sqlite->query( $unlimited_query );
			$result->calc_found_rows = count( $stmt->fetchAll() );
		}

		// Naively emulate FOUND_ROWS() by counting the rows in the result set
		if ( strpos( $updated_query, 'FOUND_ROWS(' ) !== false ) {
			$last_found_rows   = ( $this->last_found_rows ? $this->last_found_rows : 0 ) . '';
			$result->queries[] = new WP_SQLite_Query(
				"SELECT {$last_found_rows} AS `FOUND_ROWS()`",
			);
			return $result;
		}

		// Now that functions are rewritten to SQLite dialect,
		// Let's translate unsupported delete queries
		if ( 'DELETE' === $query_type ) {
			$r = new WP_SQLite_Query_Rewriter( $r->output_tokens );
			$r->consume();

			$comma = $r->peek( WP_SQLite_Lexer::TYPE_OPERATOR, null, array( ',' ) );
			$from  = $r->peek( WP_SQLite_Lexer::TYPE_KEYWORD, null, array( 'FROM' ) );
			// It's a dual delete query if the comma comes before the FROM
			if ( $comma && $from && $comma->position < $from->position ) {
				$r->replace_last( WP_SQLite_Lexer::get_token( 'SELECT', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ) );

				// Get table name. Clone $r because we need to know the table
				// name to correctly declare the select fields and the select
				// fields come before the FROM keyword.
				$r2 = clone $r;
				$r2->consume(
					array(
						'type'  => WP_SQLite_Lexer::TYPE_KEYWORD,
						'value' => 'FROM',
					)
				);
				// Assume the table name is the first token after FROM
				$table_name = $r2->consume()->value;
				unset( $r2 );

				// Now, let's figure out the primary key name
				// This assumes that all listed table names are the same.
				$q       = $this->sqlite->query( 'SELECT l.name FROM pragma_table_info("' . $table_name . '") as l WHERE l.pk = 1;' );
				$pk_name = $q->fetch()['name'];

				// Good, we can finally create the SELECT query.
				// Let's rewrite DELETE a, b FROM ... to SELECT a.id, b.id FROM ...
				$alias_nb = 0;
				while ( true ) {
					$token = $r->consume();
					if ( WP_SQLite_Lexer::TYPE_KEYWORD === $token->type && 'FROM' === $token->value ) {
						break;
					}
					// Between DELETE and FROM we only expect commas and table aliases
					// If it's not a comma, it must be a table alias
					if ( ',' !== $token->value ) {
						// Insert .id AS id_1 after the table alias
						$r->add_many(
							array(
								WP_SQLite_Lexer::get_token( '.', WP_SQLite_Lexer::TYPE_OPERATOR, WP_SQLite_Lexer::FLAG_OPERATOR_SQL ),
								WP_SQLite_Lexer::get_token( $pk_name, WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_KEY ),
								WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
								WP_SQLite_Lexer::get_token( 'AS', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ),
								WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
								WP_SQLite_Lexer::get_token( 'id_' . $alias_nb, WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_KEY ),
							)
						);
					}
				}
				$r->consume_all();

				// Select the IDs to delete
				$select        = $r->get_updated_query();
				$rows          = $this->sqlite->query( $select )->fetchAll();
				$ids_to_delete = array();
				foreach ( $rows as $id ) {
					$ids_to_delete[] = $id->id_1;
					$ids_to_delete[] = $id->id_2;
				}

				$query = (
					count( $ids_to_delete )
						? "DELETE FROM {$table_name} WHERE {$pk_name} IN (" . implode( ',', $ids_to_delete ) . ')'
						: 'SELECT 1=1'
				);
				return new WP_SQLite_Translation_Result(
					array(
						new WP_SQLite_Query( $query ),
					)
				);
			}

			// Naive rewriting of DELETE JOIN query
			// @TODO: Use Lexer
			if ( str_contains( $this->query, ' JOIN ' ) ) {
				return new WP_SQLite_Translation_Result(
					array(
						new WP_SQLite_Query(
							"DELETE FROM {$this->table_prefix}options WHERE option_id IN (SELECT MIN(option_id) FROM {$this->table_prefix}options GROUP BY option_name HAVING COUNT(*) > 1)"
						),
					)
				);
			}
		}

		$result->queries[] = new WP_SQLite_Query( $updated_query, $params );
		return $result;
	}

	private function translate_alter( WP_SQLite_Query_Rewriter $r ) {
		$r->consume();
		$subject = strtolower( $r->consume()->token );
		if ( 'table' !== $subject ) {
			throw new \Exception( 'Unknown subject: ' . $subject );
		}

		$table_name = strtolower( $r->consume()->token );
		$op_type    = strtolower( $r->consume()->token );
		$op_subject = strtolower( $r->consume()->token );
		if ( 'fulltext key' === $op_subject ) {
			return new WP_SQLite_Translation_Result( array( $this->noop() ) );
		}

		if ( 'add' === $op_type ) {
			if ( 'column' === $op_subject ) {
				$this->consume_data_types( $r );
				$r->consume_all();
			} elseif ( 'key' === $op_subject || 'unique key' === $op_subject ) {
				$key_name     = $r->consume()->value;
				$index_prefix = 'unique key' === $op_subject ? 'UNIQUE ' : '';
				$r->replace_all(
					array(
						WP_SQLite_Lexer::get_token( 'CREATE', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ),
						WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
						WP_SQLite_Lexer::get_token( "{$index_prefix}INDEX", WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ),
						WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
						WP_SQLite_Lexer::get_token( "\"{$table_name}__$key_name\"", WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_KEY ),
						WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
						WP_SQLite_Lexer::get_token( 'ON', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ),
						WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
						WP_SQLite_Lexer::get_token( '"' . $table_name . '"', WP_SQLite_Lexer::TYPE_STRING, WP_SQLite_Lexer::FLAG_STRING_DOUBLE_QUOTES ),
						WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
						WP_SQLite_Lexer::get_token( '(', WP_SQLite_Lexer::TYPE_OPERATOR ),
					)
				);

				while ( $token = $r->consume() ) {
					if ( '(' === $token->token ) {
						$r->drop_last();
						break;
					}
				}

				// Consume all the fields, skip the sizes like `(20)`
				// in `varchar(20)`
				while ( $r->consume( array( 'type' => WP_SQLite_Lexer::TYPE_SYMBOL ) ) ) {
					$paren_maybe = $r->peek();

					if ( $paren_maybe && '(' === $paren_maybe->token ) {
						$r->skip();
						$r->skip();
						$r->skip();
					}
				}

				$r->consume_all();
			} else {
				throw new \Exception( "Unknown operation: $op_type $op_subject" );
			}
		} elseif ( 'change' === $op_type ) {
			if ( 'column' === $op_subject ) {
				$this->consume_data_types( $r );
				$r->consume_all();
			} else {
				throw new \Exception( "Unknown operation: $op_type $op_subject" );
			}
		} elseif ( 'drop' === $op_type ) {
			if ( 'column' === $op_subject ) {
				$r->consume_all();
			} elseif ( 'key' === $op_subject ) {
				$key_name = $r->consume()->value;
				$r->replace_all(
					array(
						WP_SQLite_Lexer::get_token( 'DROP', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ),
						WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
						WP_SQLite_Lexer::get_token( 'INDEX', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ),
						WP_SQLite_Lexer::get_token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
						WP_SQLite_Lexer::get_token( "\"{$table_name}__$key_name\"", WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_KEY ),
					)
				);
			}
		} else {
			throw new \Exception( 'Unknown operation: ' . $op_type );
		}

		return new WP_SQLite_Translation_Result(
			array(
				new WP_SQLite_Query(
					$r->get_updated_query()
				),
			)
		);
	}

	private function translate_create( WP_SQLite_Query_Rewriter $r ) {
		$r->consume();
		$what = $r->consume()->token;
		if ( 'TABLE' === $what ) {
			return $this->translate_create_table( $r );
		} elseif ( 'PROCEDURE' === $what || 'DATABASE' === $what ) {
			return new WP_SQLite_Translation_Result( array( $this->noop() ) );
		} else {
			throw new \Exception( 'Unknown create type: ' . $what );
		}
	}

	private function translate_drop( WP_SQLite_Query_Rewriter $r ) {
		$r->consume();
		$what = $r->consume()->token;
		if ( 'TABLE' === $what ) {
			$r->consume_all();

			return new WP_SQLite_Translation_Result(
				array(
					new WP_SQLite_Query(
						$r->get_updated_query()
					),
				)
			);
		} elseif ( 'PROCEDURE' === $what || 'DATABASE' === $what ) {
			return new WP_SQLite_Translation_Result(
				array(
					$this->noop(),
				)
			);
		} else {
			throw new \Exception( 'Unknown drop type: ' . $what );
		}
	}

	private function translate_show( WP_SQLite_Query_Rewriter $r ) {
		$r->skip();
		$what1 = $r->consume()->token;
		$what2 = $r->consume()->token;
		$what  = $what1 . ' ' . $what2;
		switch ( $what ) {
			case 'CREATE PROCEDURE':
				return new WP_SQLite_Translation_Result(
					array(
						$this->noop(),
					)
				);
			case 'FULL COLUMNS':
				$r->consume();
				$table_name = $r->consume()->token;
				return new WP_SQLite_Translation_Result(
					array(
						new WP_SQLite_Query(
							"PRAGMA table_info($table_name);"
						),
					)
				);
			case 'INDEX FROM':
				$table_name = $r->consume()->token;
				return new WP_SQLite_Translation_Result(
					array(
						new WP_SQLite_Query(
							"PRAGMA index_info($table_name);"
						),
					)
				);
			case 'TABLES LIKE':
				// @TODO implement filtering by table name
				$table_name = $r->consume()->token;
				return new WP_SQLite_Translation_Result(
					array(
						new WP_SQLite_Query(
							'.tables;'
						),
					)
				);
			default:
				if ( 'VARIABLE' === $what1 ) {
					return new WP_SQLite_Translation_Result(
						array(
							$this->noop(),
						)
					);
				} else {
					throw new \Exception( 'Unknown show type: ' . $what );
				}
		}
	}

	private function noop() {
		return new WP_SQLite_Query(
			'SELECT 1=1',
			array()
		);
	}

	private function consume_data_types( WP_SQLite_Query_Rewriter $r ) {
		while ( $type = $r->consume(
			array(
				'type'  => WP_SQLite_Lexer::TYPE_KEYWORD,
				'flags' => WP_SQLite_Lexer::FLAG_KEYWORD_DATA_TYPE,
			)
		) ) {
			$typelc = strtolower( $type->value );
			if ( isset( $this->field_types_translation[ $typelc ] ) ) {
				$r->drop_last();
				$r->add(
					WP_SQLite_Lexer::get_token(
						$this->field_types_translation[ $typelc ],
						$type->type,
						$type->flags
					)
				);
			}

			$paren_maybe = $r->peek();
			if ( $paren_maybe && '(' === $paren_maybe->token ) {
				$r->skip();
				$r->skip();
				$r->skip();
			}
		}
	}
}
