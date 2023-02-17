<?php
/**
 * The queries translator.
 *
 * @package wp-sqlite-integration
 * @see https://github.com/phpmyadmin/sql-parser
 */

// Require files.
require_once __DIR__ . '/class-wp-sqlite-lexer.php';
require_once __DIR__ . '/class-wp-sqlite-query-rewriter.php';

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
		$result->sqlite_query_type = null;
		$result->mysql_query_type  = null;

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
		// @TODO Lexer has a bug where it does not calculate
		// the string length correctly if utf8 characters
		// are used. Let's pad it with spaces manually for now
		// and fix the issue before merging
		$this->query           = $query . '                        ';
		$this->last_found_rows = $last_found_rows;

		$tokens     = WP_SQLite_Lexer::get_tokens( $this->query )->tokens;
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
				$result = $this->get_translation_result( array( $this->noop( ) ) );
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
				$rewriter->skip();
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
			$last_query                = $result->queries[ count( $result->queries ) - 1 ];
			$first_word = preg_match( '/^\s*(\w+)/', $last_query->sql, $matches ) ? $matches[1] : '';
			$result->sqlite_query_type = strtoupper( $first_word );
		}
		$result->mysql_query_type = $query_type;
		return $result;
	}

	/**
	 * Translates the CREATE TABLE query.
	 *
	 * @param WP_SQLite_Query_Rewriter $rewriter The query rewriter.
	 *
	 * @return stdClass
	 */
	private function translate_create_table( WP_SQLite_Query_Rewriter $rewriter ) {
		$table = $this->parse_create_table( clone $rewriter );

		$definitions = array();
		foreach ( $table->fields as $field ) {
			$definition = '"' . $field->name . '" ' . $field->sqlite_datatype;
			if ( $field->auto_increment ) {
				$definition .= ' PRIMARY KEY AUTOINCREMENT';
			}
			else if ( $field->primary_key ) {
				$definition .= ' PRIMARY KEY ';
			}
			if ( $field->not_null ) {
				$definition .= ' NOT NULL';
			}
			if ( null !== $field->default ) {
				$definition .= ' DEFAULT ' . $field->default;
			}
			$definitions[] = $definition;
		}

		$create_table_query = WP_SQLite_Translator::get_query_object(
			$table->create_table .
			'"' . $table->name . '" (' . "\n" .
			implode( ",\n", $definitions ) .
			')'
		);

		$extra_queries = array();
		foreach ( $table->constraints as $constraint ) {
			$unique = '';
			if ( 'UNIQUE KEY' === $constraint->value ) {
				$unique = 'UNIQUE ';
			}
			$extra_queries[] = WP_SQLite_Translator::get_query_object(
				"CREATE $unique INDEX \"{$table->name}__{$constraint->name}\" ON \"{$table->name}\" (\"" . implode( '", "', $constraint->columns ) . '")'
			);
		}

		return $this->get_translation_result(
			array_merge(
				array(
					$create_table_query,
				),
				$extra_queries
			)
		);
	}

	/**
	 * Translates the CREATE TABLE query.
	 *
	 * @param WP_SQLite_Query_Rewriter $rewriter The query rewriter.
	 *
	 * @return stdClass
	 */
	private function parse_create_table( WP_SQLite_Query_Rewriter $rewriter ) {
		$result               = new stdClass();
		$result->create_table = null;
		$result->name         = null;
		$result->fields       = array();
		$result->constraints  = array();
		$result->primary_key  = array();

		while ( $token = $rewriter->consume() ) {
			// We're only interested in the table name.
			if ( WP_SQLite_Token::TYPE_KEYWORD !== $token->type ) {
				$result->name = $token->value;
				$rewriter->drop_last();
				$result->create_table = $rewriter->get_updated_query();
				break;
			}
		}

		$rewriter->consume(
			array(
				'type'  => WP_SQLite_Token::TYPE_OPERATOR,
				'value' => '(',
			)
		);

		$declarations_depth = $rewriter->depth;
		do {
			$rewriter->replace_all( array() );
			$second_token = $rewriter->peek( null, null, null, 2 );
			if ( $second_token->is_data_type() ) {
				$result->fields[] = $this->parse_create_table_field( $rewriter );
			} else {
				$result->constraints[] = $this->parse_create_table_constraint( $rewriter, $result->name );
			}
		} while ( $token && $rewriter->depth >= $declarations_depth );

		foreach ( $result->constraints as $k => $constraint ) {
			if ( 'PRIMARY KEY' === $constraint->value ) {
				$result->primary_key = array_merge(
					$result->primary_key,
					$constraint->columns
				);
				unset( $result->constraints[ $k ] );
			}
		}

		foreach ( $result->fields as $k => $field ) {
			if ( $field->primary_key ) {
				$result->primary_key[] = $field->name;
			}
		}

		$result->primary_key = array_unique( $result->primary_key );

		return $result;
	}

	/**
	 * Parses a CREATE TABLE query.
	 *
	 * @param WP_SQLite_Query_Rewriter $rewriter The query rewriter.
	 *
	 * @throws Exception If the query is not supported.
	 * @return stdClass
	 */
	private function parse_create_table_field( WP_SQLite_Query_Rewriter $rewriter ) {
		$result                  = new stdClass();
		$result->name            = '';
		$result->sqlite_datatype = '';
		$result->not_null        = false;
		$result->default         = null;
		$result->auto_increment  = false;
		$result->primary_key     = false;

		$field_name_token = $rewriter->skip(); // Field name.
		$rewriter->add( new WP_SQLite_Token( "\n", WP_SQLite_Token::TYPE_WHITESPACE ) );
		$result->name = trim( $field_name_token->value, '`"\'' );

		$initial_depth = $rewriter->depth;

		$type = $rewriter->skip();
		if ( ! $type->is_data_type() ) {
			throw new Exception( 'Data type expected in MySQL query, unknown token received: ' . $type->value );
		}

		$type_name = strtolower( $type->value );
		if ( ! isset( $this->field_types_translation[ $type_name ] ) ) {
			throw new Exception( 'MySQL field type cannot be translated to SQLite: ' . $type_name );
		}
		$result->sqlite_datatype = $this->field_types_translation[ $type_name ];

		// Skip the length, e.g. (10) in VARCHAR(10).
		$paren_maybe = $rewriter->peek();
		if ( $paren_maybe && '(' === $paren_maybe->token ) {
			$rewriter->skip();
			$rewriter->skip();
			$rewriter->skip();
		}

		// Look for the NOT NULL and AUTO_INCREMENT flags.
		while ( $token = $rewriter->skip() ) {
			if ( $token->matches(
				WP_SQLite_Token::TYPE_KEYWORD,
				WP_SQLite_Token::FLAG_KEYWORD_RESERVED,
				array( 'NOT NULL' ),
			) ) {
				$result->not_null = true;
				continue;
			}

			if ( $token->matches(
				WP_SQLite_Token::TYPE_KEYWORD,
				WP_SQLite_Token::FLAG_KEYWORD_RESERVED,
				array( 'PRIMARY KEY' ),
			) ) {
				$result->primary_key = true;
				continue;
			}

			if ( $token->matches(
				WP_SQLite_Token::TYPE_KEYWORD,
				null,
				array( 'AUTO_INCREMENT' ),
			) ) {
				$result->primary_key    = true;
				$result->auto_increment = true;
				continue;
			}

			if ( $token->matches(
				WP_SQLite_Token::TYPE_KEYWORD,
				WP_SQLite_Token::FLAG_KEYWORD_FUNCTION,
				array( 'DEFAULT' ),
			) ) {
				$result->default = $rewriter->consume()->token;
				continue;
			}

			if ( $this->is_create_table_field_terminator( $rewriter, $token, $initial_depth ) ) {
				$rewriter->add( $token );
				break;
			}
		}
		return $result;
	}

	/**
	 * Parses a CREATE TABLE constraint.
	 *
	 * @param WP_SQLite_Query_Rewriter $rewriter The query rewriter.
	 *
	 * @throws Exception If the query is not supported.
	 * @return stdClass
	 */
	private function parse_create_table_constraint( WP_SQLite_Query_Rewriter $rewriter ) {
		$result          = new stdClass();
		$result->name    = '';
		$result->value   = '';
		$result->columns = array();

		$initial_depth = $rewriter->depth;
		$constraint    = $rewriter->peek();
		if ( ! $constraint->matches( WP_SQLite_Token::TYPE_KEYWORD ) ) {
			// Not a constraint declaration, but we're not finished
			// with the table declaration yet.
			throw new Exception( 'Unexpected token in MySQL query: ' . $rewriter->peek()->value );
		}

		if (
			'KEY' === $constraint->value
			|| 'PRIMARY KEY' === $constraint->value
			|| 'INDEX' === $constraint->value
			|| 'UNIQUE KEY' === $constraint->value
		) {
			$result->value = $constraint->value;

			$rewriter->skip(); // Constraint type.
			if ( 'PRIMARY KEY' !== $constraint->value ) {
				$result->name = $rewriter->skip()->value;
			}

			$constraint_depth = $rewriter->depth;
			$rewriter->skip(); // (
			do {
				$result->columns[] = trim( $rewriter->skip()->value, '`"\'' );
				$paren_maybe = $rewriter->peek();
				if ( $paren_maybe && '(' === $paren_maybe->token ) {
					$rewriter->skip();
					$rewriter->skip();
					$rewriter->skip();
				}
				$rewriter->skip(); // , or )
			} while ( $rewriter->depth > $constraint_depth );
		}

		do {
			$token = $rewriter->skip();
		} while ( ! $this->is_create_table_field_terminator( $rewriter, $token, $initial_depth ) );

		return $result;
	}

	/**
	 * Checks if the current token is the terminator of a CREATE TABLE field.
	 *
	 * @param WP_SQLite_Query_Rewriter $rewriter      The query rewriter.
	 * @param WP_SQLite_Token          $token         The current token.
	 * @param int                      $initial_depth The initial depth.
	 *
	 * @return bool
	 */
	private function is_create_table_field_terminator( $rewriter, $token, $initial_depth ) {
		return $rewriter->depth === $initial_depth - 1 || (
			$rewriter->depth === $initial_depth &&
			WP_SQLite_Token::TYPE_OPERATOR === $token->type &&
			',' === $token->value
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
				// Count rows per table
				$tables = $this->sqlite->query("SELECT name as `table` FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll();
				$rows = '(CASE ';
				foreach($tables as $table) {
					$table_name = $table['table'];
					$count = $this->sqlite->query("SELECT COUNT(*) as `count` FROM $table_name")->fetch();
					$rows .= " WHEN name = '$table_name' THEN {$count['count']} ";
				}
				$rows .= "ELSE 0 END) ";
				return $this->get_translation_result(
					array(
						WP_SQLite_Translator::get_query_object(
							"SELECT name as `table`, $rows as `rows`, 0 as `bytes` FROM sqlite_master WHERE type='table' ORDER BY name"
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
						++$alias_nb;
					}
				}
				$rewriter->consume_all();

				// Select the IDs to delete.
				$select = $rewriter->get_updated_query();
				$stmt = $this->sqlite->prepare( $select );
				$stmt->execute($params);
				$rows = $stmt->fetchAll();
				$ids_to_delete = array();
				foreach ( $rows as $id ) {
					$ids_to_delete[] = $id['id_0'];
					$ids_to_delete[] = $id['id_1'];
				}

				$query = (
					count( $ids_to_delete )
						? "DELETE FROM {$table_name} WHERE {$pk_name} IN (" . implode( ',', $ids_to_delete ) . ')'
						: "DELETE FROM {$table_name} WHERE 0=1"
				);
				$result = $this->get_translation_result(
					array(
						WP_SQLite_Translator::get_query_object( $query ),
					),
					true,
					count( $ids_to_delete )
				);
				return $result;
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
					case 'VARIABLES':
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
			'SELECT 1 WHERE 1=0;',
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
