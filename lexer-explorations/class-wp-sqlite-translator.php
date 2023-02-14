<?php
/**
 * The queries translator.
 *
 * @package wp-sqlite-integration
 * @see https://github.com/phpmyadmin/sql-parser
 */

require_once __DIR__ . '/class-wp-sqlite-lexer.php';
require_once __DIR__ . '/class-wp-sqlite-query-rewriter.php';
require_once __DIR__ . '/sql-parser/vendor/autoload.php';

/**
 * The queries translator class.
 */
class WP_SQLite_Translator {
	/*
	 * @TODO Check capability – SQLite must have a regexp function available.
	 */

	/**
	 * Whether there's a regexp function.
	 *
	 * @var bool
	 */
	private $has_regexp = true;

	/**
	 * The SQLite database.
	 *
	 * @var PDO
	 */
	private $sqlite;

	/**
	 * The table prefix.
	 *
	 * @var string
	 */
	private $table_prefix;

	/**
	 * How to translate field types from MySQL to SQLite.
	 *
	 * @var array
	 */
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

	/**
	 * The MySQL to SQLite date formats translation.
	 *
	 * @var array
	 */
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

	/**
	 * The query.
	 *
	 * @TODO Remove this property?
	 * Is it weird to have this->query
	 * that only matters for the lifetime of translate()?
	 *
	 * @var string
	 */
	private $query;

	/**
	 * The last found rows.
	 *
	 * @var int|string
	 */
	private $last_found_rows = 0;

	/**
	 * Constructor.
	 *
	 * @param PDO   $pdo           The SQLite database.
	 * @param array $table_prefix  The table prefix.
	 */
	public function __construct( $pdo, $table_prefix = 'wp_' ) {
		$this->sqlite = $pdo;
		$this->sqlite->query( 'PRAGMA encoding="UTF-8";' );
		$this->sqlite->sqliteCreateFunction( 'regexp', array( $this, 'sqlite_regexp' ), 2 );
		$this->table_prefix = $table_prefix;
	}

	/**
	 * The regexp function for SQLite.
	 *
	 * @param string $pattern  The pattern.
	 * @param string $subject  The subject.
	 * @return bool
	 */
	public function sqlite_regexp( $pattern, $subject ) {
		return preg_match( '/' . $pattern . '/', $subject );
	}

	/**
	 * Gets the query object.
	 *
	 * @param string $sql    The SQL query.
	 * @param array  $params The parameters.
	 *
	 * @return stdClass
	 */
	public static function get_query_object( $sql = '', $params = array() ) {
		$sql_obj         = new stdClass();
		$sql_obj->sql    = trim( $sql );
		$sql_obj->params = $params;
		return $sql_obj;
	}

	/**
	 * Gets the translation result.
	 *
	 * @param array   $queries     The queries.
	 * @param boolean $has_result  Whether the query has a result.
	 * @param mixed   $result      The result.
	 *
	 * @return stdClass
	 */
	protected function get_translation_result( $queries, $has_result = false, $result = null ) {
		$result                  = new stdClass();
		$result->queries         = $queries;
		$result->has_result      = $has_result;
		$result->result          = $result;
		$result->calc_found_rows = null;
		$result->query_type      = null;

		return $result;
	}

	/**
	 * Translates the query.
	 *
	 * @param string     $query           The query.
	 * @param int|string $last_found_rows The last found rows.
	 *
	 * @throws Exception If the query is not supported.
	 *
	 * @return stdClass
	 */
	public function translate( string $query, $last_found_rows = null ) {
		$this->query           = $query;
		$this->last_found_rows = $last_found_rows;

		// $tokens = \PhpMyAdmin\SqlParser\Lexer::getTokens( $query )->tokens;
		$tokens     = WP_SQLite_Lexer::get_tokens( $query )->tokens;
		$rewriter   = new WP_SQLite_Query_Rewriter( $tokens );
		$query_type = $rewriter->peek()->value;

		switch ( $query_type ) {
			case 'ALTER':
				$result = $this->translate_alter( $rewriter );
				break;

			case 'CREATE':
				$result = $this->translate_create( $rewriter );
				break;

			case 'REPLACE':
			case 'SELECT':
			case 'INSERT':
			case 'UPDATE':
			case 'DELETE':
				$result = $this->translate_crud( $rewriter );
				break;

			case 'CALL':
			case 'SET':
				// It would be lovely to support at least SET autocommit,
				// but I don't think even that is possible with SQLite.
				$result = $this->get_translation_result( array( $this->noop() ) );
				break;

			case 'TRUNCATE':
				$rewriter->skip(); // TRUNCATE.
				$rewriter->skip(); // TABLE.
				$rewriter->add( new WP_SQLite_Token( 'DELETE', WP_SQLite_Token::TYPE_KEYWORD ) );
				$rewriter->add( new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ) );
				$rewriter->add( new WP_SQLite_Token( 'FROM', WP_SQLite_Token::TYPE_KEYWORD ) );
				$rewriter->consume_all();
				$result = $this->get_translation_result(
					array(
						WP_SQLite_Translator::get_query_object( $rewriter->get_updated_query() ),
					)
				);
				break;

			case 'START TRANSACTION':
				$result = $this->get_translation_result(
					array(
						WP_SQLite_Translator::get_query_object( 'BEGIN' ),
					)
				);
				break;

			case 'BEGIN':
			case 'COMMIT':
			case 'ROLLBACK':
				$result = $this->get_translation_result(
					array(
						WP_SQLite_Translator::get_query_object( $this->query ),
					)
				);
				break;

			case 'DROP':
				$result = $this->translate_drop( $rewriter );
				break;

			case 'DESCRIBE':
				$table_name = $rewriter->consume()->value;
				$result     = $this->get_translation_result(
					array(
						WP_SQLite_Translator::get_query_object( "PRAGMA table_info(\"$table_name\");" ),
					)
				);
				break;

			case 'SHOW':
				$result = $this->translate_show( $rewriter );
				break;

			default:
				throw new Exception( 'Unknown query type: ' . $query_type );
		}
		// The query type could have changed – let's grab the new one.
		if ( count( $result->queries ) ) {
			$last_query         = $result->queries[ count( $result->queries ) - 1 ];
			$result->query_type = strtoupper( strtok( $last_query->sql, ' ' ) );
		}
		return $result;
	}

	/**
	 * Translates the CREATE TABLE query.
	 *
	 * @return stdClass
	 */
	private function translate_create_table() {
		$parser                       = new \PhpMyAdmin\SqlParser\Parser( $this->query );
		$stmt                         = $parser->statements[0];
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
			WP_SQLite_Translator::get_query_object( $stmt->build() ),
		);
		foreach ( $extra_queries as $extra_query ) {
			$queries[] = WP_SQLite_Translator::get_query_object( $extra_query );
		}

		return $this->get_translation_result(
			$queries
		);
	}

	/**
	 * Translator method.
	 *
	 * @param WP_SQLite_Query_Rewriter $rewriter The query rewriter.
	 *
	 * @throws Exception If the query type is unknown.
	 * @return stdClass
	 */
	private function translate_crud( WP_SQLite_Query_Rewriter $rewriter ) {
		/*
		 * Very naive check to see if we're dealing with an information_schema query.
		 * If so, we'll just return a dummy result.
		 *
		 * @TODO: A proper check and a better translation.
		 */
		if ( str_contains( $this->query, 'information_schema' ) ) {
			// @TODO: Actually rewrite the columns
			if ( str_contains( $this->query, 'bytes' ) ) {
				return $this->get_translation_result(
					array(
						WP_SQLite_Translator::get_query_object(
							"SELECT name as `table`, 0 as `rows`, 0 as `bytes` FROM sqlite_master WHERE type='table' ORDER BY name"
						),
					)
				);
			}
			return $this->get_translation_result(
				array(
					WP_SQLite_Translator::get_query_object(
						"SELECT name, 'myisam' as `engine`, 0 as `data`, 0 as `index` FROM sqlite_master WHERE type='table' ORDER BY name"
					),
				)
			);
		}

		$query_type = $rewriter->consume()->value;

		// Naive regexp check.
		if ( ! $this->has_regexp && strpos( $this->query, ' REGEXP ' ) !== false ) {
			// Bail out if we can't run the query.
			return $this->get_translation_result( array( $this->noop() ) );
		}

		if (
			// @TODO: Add handling for the following cases:
			strpos( $this->query, 'ORDER BY FIELD' ) !== false
			|| strpos( $this->query, '@@SESSION.sql_mode' ) !== false
			// `CONVERT( field USING charset )` is not supported.
			|| strpos( $this->query, 'CONVERT( ' ) !== false
		) {
			/*
			 * @TODO rewrite ORDER BY FIELD(a, 1,4,6) as
			 * ORDER BY CASE a
			 * 		WHEN 1 THEN 0
			 * 		WHEN 4 THEN 1
			 * 		WHEN 6 THEN 2
			 * END
			*/
			// Return a dummy select for now.
			return 'SELECT 1=1';
		}

		$params                  = array();
		$is_in_duplicate_section = false;
		$table_name              = null;
		$has_sql_calc_found_rows = false;

		// Consume the query type.
		if ( 'INSERT' === $query_type && 'IGNORE' === $rewriter->peek()->value ) {
			$rewriter->add( new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ) );
			$rewriter->add( new WP_SQLite_Token( 'OR', WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_RESERVED ) );
			$rewriter->consume(); // IGNORE.
		}

		// Consume and record the table name.
		if ( 'INSERT' === $query_type || 'REPLACE' === $query_type ) {
			$rewriter->consume(); // INTO.
			$table_name = $rewriter->consume()->value; // Table name.
		}

		$last_reserved_keyword = null;
		while ( $token = $rewriter->consume() ) {
			if ( WP_SQLite_Token::TYPE_KEYWORD === $token->type && $token->flags & WP_SQLite_Token::FLAG_KEYWORD_RESERVED ) {
				$last_reserved_keyword = $token->value;
			}

			if ( 'SQL_CALC_FOUND_ROWS' === $token->value && WP_SQLite_Token::TYPE_KEYWORD === $token->type ) {
				$has_sql_calc_found_rows = true;
				$rewriter->drop_last();
				continue;
			}

			if ( 'AS' !== $last_reserved_keyword && WP_SQLite_Token::TYPE_STRING === $token->type && $token->flags & WP_SQLite_Token::FLAG_STRING_SINGLE_QUOTES ) {
				// Rewrite string values to bound parameters.
				$param_name            = ':param' . count( $params );
				$params[ $param_name ] = $token->value;
				$rewriter->replace_last( new WP_SQLite_Token( $param_name, WP_SQLite_Token::TYPE_STRING, WP_SQLite_Token::FLAG_STRING_SINGLE_QUOTES ) );
				continue;
			}

			if ( WP_SQLite_Token::TYPE_KEYWORD === $token->type ) {
				if ( 'RAND' === $token->keyword && $token->flags & WP_SQLite_Token::FLAG_KEYWORD_FUNCTION ) {
					$rewriter->replace_last( new WP_SQLite_Token( 'RANDOM', WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_FUNCTION ) );
					continue;
				}

				if (
					'CONCAT' === $token->keyword
					&& $token->flags & WP_SQLite_Token::FLAG_KEYWORD_FUNCTION
				) {
					$rewriter->drop_last();
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
					// %w returns 0 for Sunday and 6 for Saturday
					// but weekday returns 1 for Monday and 7 for Sunday
					array( 'WEEKDAY', '%w' ),
					array( 'HOUR', '%H' ),
					array( 'MINUTE', '%M' ),
					array( 'SECOND', '%S' ),
				) as $token_item ) {
					$unit   = $token_item[0];
					$format = $token_item[1];
					if ( $token->value === $unit && $token->flags & WP_SQLite_Token::FLAG_KEYWORD_FUNCTION ) {
						// Drop the consumed function call.
						$rewriter->drop_last();
						// Skip the opening "(".
						$rewriter->skip();

						// Skip the first argument so we can read the second one.
						$first_arg = $rewriter->skip_over(
							array(
								'type'  => WP_SQLite_Token::TYPE_OPERATOR,
								'value' => array( ',', ')' ),
							)
						);

						$terminator = array_pop( $first_arg );

						if ( 'WEEK' === $unit ) {
							$format = '%W';

							/*
							 * @TODO
							 * WEEK(date, mode) can mean different strftime formats
							 * depending on the mode (default=0).
							 * If the $skipped token is a comma, then we need to
							 * read the mode argument.
							 */
							if ( ',' === $terminator->value ) {
								$mode = $rewriter->skip();
								// Assume $mode is now a number.
								if ( 0 === $mode->value ) {
									$format = '%U';
								} elseif ( 1 === $mode->value ) {
									$format = '%W';
								} else {
									throw new Exception( 'Could not parse the WEEK() mode' );
								}
							}
						}

						$rewriter->add( new WP_SQLite_Token( 'STRFTIME', WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_FUNCTION ) );
						$rewriter->add( new WP_SQLite_Token( '(', WP_SQLite_Token::TYPE_OPERATOR ) );
						$rewriter->add( new WP_SQLite_Token( "'$format'", WP_SQLite_Token::TYPE_STRING ) );
						$rewriter->add( new WP_SQLite_Token( ',', WP_SQLite_Token::TYPE_OPERATOR ) );
						$rewriter->add_many( $first_arg );
						if ( ')' === $terminator->value ) {
							$rewriter->add( $terminator );
						}
						continue 2;
					}
				}

				if (
					$token->flags & WP_SQLite_Token::FLAG_KEYWORD_FUNCTION && (
						'DATE_ADD' === $token->keyword ||
						'DATE_SUB' === $token->keyword
					)
				) {
					$rewriter->replace_last( new WP_SQLite_Token( 'DATE', WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_FUNCTION ) );
					continue;
				}

				if (
					$token->flags & WP_SQLite_Token::FLAG_KEYWORD_FUNCTION &&
					'VALUES' === $token->keyword &&
					$is_in_duplicate_section
				) {
					/*
					Rewrite:  VALUES(`option_name`)
					to:       excluded.option_name
					*/
					$rewriter->replace_last( new WP_SQLite_Token( 'excluded', WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_KEY ) );
					$rewriter->add( new WP_SQLite_Token( '.', WP_SQLite_Token::TYPE_OPERATOR ) );

					$rewriter->skip(); // Skip the opening `(`.
					// Consume the column name.
					$rewriter->consume(
						array(
							'type'  => WP_SQLite_Token::TYPE_OPERATOR,
							'value' => ')',
						)
					);
					// Drop the consumed ')' token.
					$rewriter->drop_last();
					continue;
				}
				if (
					$token->flags & WP_SQLite_Token::FLAG_KEYWORD_FUNCTION &&
					'DATE_FORMAT' === $token->keyword
				) {
					// DATE_FORMAT( `post_date`, '%Y-%m-%d' ).

					$rewriter->replace_last( new WP_SQLite_Token( 'STRFTIME', WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_FUNCTION ) );
					// The opening `(`.
					$rewriter->consume();

					// Skip the first argument so we can read the second one.
					$first_arg = $rewriter->skip_over(
						array(
							'type'  => WP_SQLite_Token::TYPE_OPERATOR,
							'value' => ',',
						)
					);

					// Make sure we actually found the comma.
					$comma = array_pop( $first_arg );
					if ( ',' !== $comma->value ) {
						throw new Exception( 'Could not parse the DATE_FORMAT() call' );
					}

					// Skip the second argument but capture the token.
					$format     = $rewriter->skip()->value;
					$new_format = strtr( $format, $this->mysql_php_date_formats );

					$rewriter->add( new WP_SQLite_Token( "'$new_format'", WP_SQLite_Token::TYPE_STRING ) );
					$rewriter->add( new WP_SQLite_Token( ',', WP_SQLite_Token::TYPE_OPERATOR ) );

					// Add the buffered tokens back to the stream.
					$rewriter->add_many( $first_arg );

					continue;
				}
				if ( 'INTERVAL' === $token->keyword ) {
					// Remove the INTERVAL keyword from the output stream.
					$rewriter->drop_last();

					$num  = $rewriter->skip()->value;
					$unit = $rewriter->skip()->value;

					/*
					 * In MySQL, we say:
					 *		DATE_ADD(d, INTERVAL 1 YEAR)
					 *		DATE_SUB(d, INTERVAL 1 YEAR)
					 *
					 * In SQLite, we say:
					 *		DATE(d, '+1 YEAR')
					 *		DATE(d, '-1 YEAR')
					 *
					 * The sign of the interval is determined by the date_* function
					 * that is closest in the call stack.
					 *
					 * Let's find it.
					 */
					$interval_op = '+'; // Default to adding.
					for ( $j = count( $rewriter->call_stack ) - 1; $j >= 0; $j-- ) {
						$call = $rewriter->call_stack[ $j ];
						if ( 'DATE_ADD' === $call[0] ) {
							$interval_op = '+';
							break;
						}
						if ( 'DATE_SUB' === $call[0] ) {
							$interval_op = '-';
							break;
						}
					}

					$rewriter->add( new WP_SQLite_Token( "'{$interval_op}$num $unit'", WP_SQLite_Token::TYPE_STRING ) );
					continue;
				}

				if ( 'INSERT' === $query_type && 'DUPLICATE' === $token->keyword ) {
					/*
					 * Rewrite:
					 * 		ON DUPLICATE KEY UPDATE `option_name` = VALUES(`option_name`)
					 * to:
					 * 		ON CONFLICT(ip) DO UPDATE SET option_name = excluded.option_name
					 */

					// Replace the DUPLICATE keyword with CONFLICT.
					$rewriter->replace_last( new WP_SQLite_Token( 'CONFLICT', WP_SQLite_Token::TYPE_KEYWORD ) );
					// Skip overthe "KEY" and "UPDATE" keywords.
					$rewriter->skip();
					$rewriter->skip();

					// Add "( <primary key> ) DO UPDATE SET ".
					$rewriter->add( new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ) );
					$rewriter->add( new WP_SQLite_Token( '(', WP_SQLite_Token::TYPE_OPERATOR ) );
					// @TODO don't make assumptions about the names, only fetch
					// the correct unique key from sqlite
					if ( str_ends_with( $table_name, '_options' ) ) {
						$pk_name = 'option_name';
					} elseif ( str_ends_with( $table_name, '_term_relationships' ) ) {
						$pk_name = 'object_id, term_taxonomy_id';
					} elseif ( str_ends_with( $table_name, 'wp_woocommerce_sessions' ) ) {
						$pk_name = 'session_key';
					} else {
						$q       = $this->sqlite->query( 'SELECT l.name FROM pragma_table_info("' . $table_name . '") as l WHERE l.pk = 1;' );
						$pk_name = $q->fetch()['name'];
					}
					$rewriter->add( new WP_SQLite_Token( $pk_name, WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_KEY ) );
					$rewriter->add( new WP_SQLite_Token( ')', WP_SQLite_Token::TYPE_OPERATOR ) );
					$rewriter->add( new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ) );
					$rewriter->add( new WP_SQLite_Token( 'DO', WP_SQLite_Token::TYPE_KEYWORD ) );
					$rewriter->add( new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ) );
					$rewriter->add( new WP_SQLite_Token( 'UPDATE', WP_SQLite_Token::TYPE_KEYWORD ) );
					$rewriter->add( new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ) );
					$rewriter->add( new WP_SQLite_Token( 'SET', WP_SQLite_Token::TYPE_KEYWORD ) );
					$rewriter->add( new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ) );

					$is_in_duplicate_section = true;
					continue;
				}
			}

			if ( WP_SQLite_Token::TYPE_OPERATOR === $token->type ) {
				$call_parent = $rewriter->last_call_stack_element();
				// Rewrite commas to || in CONCAT() calls.
				if (
					$call_parent
					&& 'CONCAT' === $call_parent[0]
					&& ',' === $token->value
					&& $token->flags & WP_SQLite_Token::FLAG_OPERATOR_SQL
				) {
					$rewriter->replace_last( new WP_SQLite_Token( '||', WP_SQLite_Token::TYPE_OPERATOR ) );
					continue;
				}
			}
		}

		$updated_query = $rewriter->get_updated_query();
		$result        = $this->get_translation_result( array() );

		// Naively emulate SQL_CALC_FOUND_ROWS for now.
		if ( $has_sql_calc_found_rows ) {
			// First strip the code. this is the end of rewriting process.
			$query = str_ireplace( 'SQL_CALC_FOUND_ROWS', '', $updated_query );
			// We make the data for next SELECT FOUND_ROWS() statement.
			$unlimited_query         = preg_replace( '/\\bLIMIT\\s*.*/imsx', '', $query );
			$stmt                    = $this->sqlite->query( $unlimited_query );
			$result->calc_found_rows = count( $stmt->fetchAll() );
		}

		// Naively emulate FOUND_ROWS() by counting the rows in the result set.
		if ( strpos( $updated_query, 'FOUND_ROWS(' ) !== false ) {
			$last_found_rows   = ( $this->last_found_rows ? $this->last_found_rows : 0 ) . '';
			$result->queries[] = WP_SQLite_Translator::get_query_object(
				"SELECT {$last_found_rows} AS `FOUND_ROWS()`",
			);
			return $result;
		}

		// Now that functions are rewritten to SQLite dialect,
		// let's translate unsupported delete queries.
		if ( 'DELETE' === $query_type ) {
			$rewriter = new WP_SQLite_Query_Rewriter( $rewriter->output_tokens );
			$rewriter->consume();

			$comma = $rewriter->peek( WP_SQLite_Token::TYPE_OPERATOR, null, array( ',' ) );
			$from  = $rewriter->peek( WP_SQLite_Token::TYPE_KEYWORD, null, array( 'FROM' ) );
			// It's a dual delete query if the comma comes before the FROM.
			if ( $comma && $from && $comma->position < $from->position ) {
				$rewriter->replace_last( new WP_SQLite_Token( 'SELECT', WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_RESERVED ) );

				/*
				 * Get table name.
				 * Clone $rewriter because we need to know the table name
				 * to correctly declare the select fields,
				 * and the select fields come before the FROM keyword.
				 */
				$rewriter_clone = clone $rewriter;
				$rewriter_clone->consume(
					array(
						'type'  => WP_SQLite_Token::TYPE_KEYWORD,
						'value' => 'FROM',
					)
				);
				// Assume the table name is the first token after FROM.
				$table_name = $rewriter_clone->consume()->value;
				unset( $rewriter_clone );

				// Now, let's figure out the primary key name.
				// This assumes that all listed table names are the same.
				$q       = $this->sqlite->query( 'SELECT l.name FROM pragma_table_info("' . $table_name . '") as l WHERE l.pk = 1;' );
				$pk_name = $q->fetch()['name'];

				// Good, we can finally create the SELECT query.
				// Let's rewrite DELETE a, b FROM ... to SELECT a.id, b.id FROM ...
				$alias_nb = 0;
				while ( true ) {
					$token = $rewriter->consume();
					if ( WP_SQLite_Token::TYPE_KEYWORD === $token->type && 'FROM' === $token->value ) {
						break;
					}
					// Between DELETE and FROM we only expect commas and table aliases
					// If it's not a comma, it must be a table alias.
					if ( ',' !== $token->value ) {
						// Insert .id AS id_1 after the table alias.
						$rewriter->add_many(
							array(
								new WP_SQLite_Token( '.', WP_SQLite_Token::TYPE_OPERATOR, WP_SQLite_Token::FLAG_OPERATOR_SQL ),
								new WP_SQLite_Token( $pk_name, WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_KEY ),
								new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ),
								new WP_SQLite_Token( 'AS', WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_RESERVED ),
								new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ),
								new WP_SQLite_Token( 'id_' . $alias_nb, WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_KEY ),
							)
						);
					}
				}
				$rewriter->consume_all();

				// Select the IDs to delete.
				$select        = $rewriter->get_updated_query();
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
				return $this->get_translation_result(
					array(
						WP_SQLite_Translator::get_query_object( $query ),
					)
				);
			}

			// Naive rewriting of DELETE JOIN query.
			// @TODO: Use Lexer.
			if ( str_contains( $this->query, ' JOIN ' ) ) {
				return $this->get_translation_result(
					array(
						WP_SQLite_Translator::get_query_object(
							"DELETE FROM {$this->table_prefix}options WHERE option_id IN (SELECT MIN(option_id) FROM {$this->table_prefix}options GROUP BY option_name HAVING COUNT(*) > 1)"
						),
					)
				);
			}
		}

			$result->queries[] = WP_SQLite_Translator::get_query_object( $updated_query, $params );
			return $result;
	}

	/**
	 * Translate ALTER query.
	 *
	 * @param WP_SQLite_Query_Rewriter $rewriter Query rewriter.
	 *
	 * @throws Exception If the subject is not 'table', or we're performing an unknown operation.
	 * @return stdClass
	 */
	private function translate_alter( WP_SQLite_Query_Rewriter $rewriter ) {
		$rewriter->consume();
		$subject = strtolower( $rewriter->consume()->token );
		if ( 'table' !== $subject ) {
			throw new Exception( 'Unknown subject: ' . $subject );
		}

		$table_name = strtolower( $rewriter->consume()->token );
		$op_type    = strtolower( $rewriter->consume()->token );
		$op_subject = strtolower( $rewriter->consume()->token );
		if ( 'fulltext key' === $op_subject ) {
			return $this->get_translation_result( array( $this->noop() ) );
		}

		if ( 'add' === $op_type ) {
			if ( 'column' === $op_subject ) {
				$this->consume_data_types( $rewriter );
				$rewriter->consume_all();
			} elseif ( 'key' === $op_subject || 'index' === $op_subject || 'unique key' === $op_subject ) {
				$key_name     = $rewriter->consume()->value;
				$index_prefix = 'unique key' === $op_subject ? 'UNIQUE ' : '';
				$rewriter->replace_all(
					array(
						new WP_SQLite_Token( 'CREATE', WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_RESERVED ),
						new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ),
						new WP_SQLite_Token( "{$index_prefix}INDEX", WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_RESERVED ),
						new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ),
						new WP_SQLite_Token( "\"{$table_name}__$key_name\"", WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_KEY ),
						new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ),
						new WP_SQLite_Token( 'ON', WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_RESERVED ),
						new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ),
						new WP_SQLite_Token( '"' . $table_name . '"', WP_SQLite_Token::TYPE_STRING, WP_SQLite_Token::FLAG_STRING_DOUBLE_QUOTES ),
						new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ),
						new WP_SQLite_Token( '(', WP_SQLite_Token::TYPE_OPERATOR ),
					)
				);

				while ( $token = $rewriter->consume() ) {
					if ( '(' === $token->token ) {
						$rewriter->drop_last();
						break;
					}
				}

				// Consume all the fields, skip the sizes like `(20)` in `varchar(20)`.
				while ( $rewriter->consume( array( 'type' => WP_SQLite_Token::TYPE_SYMBOL ) ) ) {
					$paren_maybe = $rewriter->peek();

					if ( $paren_maybe && '(' === $paren_maybe->token ) {
						$rewriter->skip();
						$rewriter->skip();
						$rewriter->skip();
					}
				}

				$rewriter->consume_all();
			} else {
				throw new Exception( "Unknown operation: $op_type $op_subject" );
			}
		} elseif ( 'change' === $op_type ) {
			if ( 'column' === $op_subject ) {
				$this->consume_data_types( $rewriter );
				$rewriter->consume_all();
			} else {
				throw new Exception( "Unknown operation: $op_type $op_subject" );
			}
		} elseif ( 'drop' === $op_type ) {
			if ( 'column' === $op_subject ) {
				$rewriter->consume_all();
			} elseif ( 'key' === $op_subject ) {
				$key_name = $rewriter->consume()->value;
				$rewriter->replace_all(
					array(
						new WP_SQLite_Token( 'DROP', WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_RESERVED ),
						new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ),
						new WP_SQLite_Token( 'INDEX', WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_RESERVED ),
						new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ),
						new WP_SQLite_Token( "\"{$table_name}__$key_name\"", WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_KEY ),
					)
				);
			}
		} else {
			throw new Exception( 'Unknown operation: ' . $op_type );
		}

		return $this->get_translation_result(
			array(
				WP_SQLite_Translator::get_query_object(
					$rewriter->get_updated_query()
				),
			)
		);
	}

	/**
	 * Translates a CREATE query.
	 *
	 * @param WP_SQLite_Query_Rewriter $rewriter The query rewriter.
	 *
	 * @throws Exception If the query is an unknown create type.
	 * @return stdClass The translation result.
	 */
	private function translate_create( WP_SQLite_Query_Rewriter $rewriter ) {
		$rewriter->consume();
		$what = $rewriter->consume()->token;

		switch ( $what ) {
			case 'TABLE':
				return $this->translate_create_table( $rewriter );

			case 'PROCEDURE':
			case 'DATABASE':
				return $this->get_translation_result( array( $this->noop() ) );

			default:
				throw new Exception( 'Unknown create type: ' . $what );
		}
	}

	/**
	 * Translates a DROP query.
	 *
	 * @param WP_SQLite_Query_Rewriter $rewriter The query rewriter.
	 *
	 * @throws Exception If the query is an unknown drop type.
	 * @return stdClass The translation result.
	 */
	private function translate_drop( WP_SQLite_Query_Rewriter $rewriter ) {
		$rewriter->consume();
		$what = $rewriter->consume()->token;

		switch ( $what ) {
			case 'TABLE':
				$rewriter->consume_all();
				return $this->get_translation_result( array( WP_SQLite_Translator::get_query_object( $rewriter->get_updated_query() ) ) );

			case 'PROCEDURE':
			case 'DATABASE':
				return $this->get_translation_result( array( $this->noop() ) );

			default:
				throw new Exception( 'Unknown drop type: ' . $what );
		}
	}

	/**
	 * Translates a SHOW query.
	 *
	 * @param WP_SQLite_Query_Rewriter $rewriter The query rewriter.
	 *
	 * @throws Exception If the query is an unknown show type.
	 * @return stdClass The translation result.
	 */
	private function translate_show( WP_SQLite_Query_Rewriter $rewriter ) {
		$rewriter->skip();
		$what1 = $rewriter->consume()->token;
		$what2 = $rewriter->consume()->token;
		$what  = $what1 . ' ' . $what2;
		switch ( $what ) {
			case 'CREATE PROCEDURE':
				return $this->get_translation_result(
					array(
						$this->noop(),
					)
				);

			case 'FULL COLUMNS':
				$rewriter->consume();
				$table_name = $rewriter->consume()->token;
				return $this->get_translation_result(
					array(
						WP_SQLite_Translator::get_query_object(
							"PRAGMA table_info($table_name);"
						),
					)
				);

			case 'COLUMNS FROM':
				$table_name = $rewriter->consume()->token;
				return $this->get_translation_result(
					array(
						WP_SQLite_Translator::get_query_object(
							"PRAGMA table_info(\"$table_name\");"
						),
					)
				);

			case 'INDEX FROM':
				$table_name = $rewriter->consume()->token;
				return $this->get_translation_result(
					array(
						WP_SQLite_Translator::get_query_object(
							"PRAGMA index_info($table_name);"
						),
					)
				);

			case 'TABLES LIKE':
				$table_expression = $rewriter->skip();
				return $this->get_translation_result(
					array(
						WP_SQLite_Translator::get_query_object(
							"SELECT name FROM sqlite_master WHERE type='table' AND name LIKE :param;",
							array(
								':param' => $table_expression->value,
							)
						),
					)
				);

			default:
				switch ( $what1 ) {
					case 'TABLES':
						return $this->get_translation_result(
							array(
								WP_SQLite_Translator::get_query_object(
									"SELECT name FROM sqlite_master WHERE type='table'"
								),
							)
						);

					case 'VARIABLE':
						return $this->get_translation_result(
							array(
								$this->noop(),
							)
						);

					default:
						throw new Exception( 'Unknown show type: ' . $what );
				}
		}
	}

	/**
	 * Returns a dummy `SELECT 1=1` query object.
	 *
	 * @return stdClass The dummy query object.
	 */
	private function noop() {
		return WP_SQLite_Translator::get_query_object(
			'SELECT 1=1',
			array()
		);
	}

	/**
	 * Consumes data types from the query.
	 *
	 * @param WP_SQLite_Query_Rewriter $rewriter The query rewriter.
	 *
	 * @return void
	 */
	private function consume_data_types( WP_SQLite_Query_Rewriter $rewriter ) {
		while ( $type = $rewriter->consume(
			array(
				'type'  => WP_SQLite_Token::TYPE_KEYWORD,
				'flags' => WP_SQLite_Token::FLAG_KEYWORD_DATA_TYPE,
			)
		) ) {
			$typelc = strtolower( $type->value );
			if ( isset( $this->field_types_translation[ $typelc ] ) ) {
				$rewriter->drop_last();
				$rewriter->add(
					new WP_SQLite_Token(
						$this->field_types_translation[ $typelc ],
						$type->type,
						$type->flags
					)
				);
			}

			$paren_maybe = $rewriter->peek();
			if ( $paren_maybe && '(' === $paren_maybe->token ) {
				$rewriter->skip();
				$rewriter->skip();
				$rewriter->skip();
			}
		}
	}
}
