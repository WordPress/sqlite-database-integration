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
require_once __DIR__ . '/../wp-includes/sqlite/class-wp-sqlite-pdo-user-defined-functions.php';

/**
 * The queries translator class.
 */
class WP_SQLite_Translator {

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
		'bit'                => 'integer',
		'bool'               => 'integer',
		'boolean'            => 'integer',
		'tinyint'            => 'integer',
		'smallint'           => 'integer',
		'mediumint'          => 'integer',
		'int'                => 'integer',
		'integer'            => 'integer',
		'bigint'             => 'integer',
		'float'              => 'real',
		'double'             => 'real',
		'decimal'            => 'real',
		'dec'                => 'real',
		'numeric'            => 'real',
		'fixed'              => 'real',
		'date'               => 'text',
		'datetime'           => 'text',
		'timestamp'          => 'text',
		'time'               => 'text',
		'year'               => 'text',
		'char'               => 'text',
		'varchar'            => 'text',
		'binary'             => 'integer',
		'varbinary'          => 'blob',
		'tinyblob'           => 'blob',
		'tinytext'           => 'text',
		'blob'               => 'blob',
		'text'               => 'text',
		'mediumblob'         => 'blob',
		'mediumtext'         => 'text',
		'longblob'           => 'blob',
		'longtext'           => 'text',
		'geomcollection'     => 'text',
		'geometrycollection' => 'text',
	);

	/**
	 * The MySQL to SQLite date formats translation.
	 * 
	 * Maps MySQL formats to SQLite strftime() formats.
	 * 
	 * For MySQL formats, see:
	 * * https://dev.mysql.com/doc/refman/5.7/en/date-and-time-functions.html#function_date-format
	 * 
	 * For SQLite formats, see:
	 * * https://www.sqlite.org/lang_datefunc.html
	 * * https://strftime.org/
	 *
	 * @var array
	 */
	private $mysql_date_format_to_sqlite_strftime = array(
		'%a' => '%D',
		'%b' => '%M',
		'%c' => '%n',
		'%D' => '%jS',
		'%d' => '%d',
		'%e' => '%j',
		'%H' => '%H',
		'%h' => '%h',
		'%I' => '%h',
		'%i' => '%M',
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
	 * The last found rows.
	 *
	 * @var int|string
	 */
	private $last_found_rows = 0;

	private $rewriter;
	private $query_type;
	private $insert_columns = array();

	/**
	 * Constructor.
	 *
	 * @param PDO   $pdo           The SQLite database.
	 * @param array $table_prefix  The table prefix.
	 */
	public function __construct( $pdo, $table_prefix = 'wp_' ) {
		$this->sqlite = $pdo;
		$this->sqlite->query( 'PRAGMA encoding="UTF-8";' );

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
		$result->rewriter  = null;
		$result->query_type  = null;

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
		$this->last_found_rows = $last_found_rows;

		$tokens     = WP_SQLite_Lexer::get_tokens( $query )->tokens;
		$this->rewriter = new WP_SQLite_Query_Rewriter( $tokens );
		$this->query_type = $this->rewriter->peek()->value;

		switch ( $this->query_type ) {
			case 'ALTER':
				$result = $this->translate_alter();
				break;

			case 'CREATE':
				$result = $this->translate_create();
				break;

			case 'REPLACE':
			case 'SELECT':
			case 'INSERT':
			case 'UPDATE':
			case 'DELETE':
				$result = $this->translate_crud();
				break;

			case 'CALL':
			case 'SET':
				// It would be lovely to support at least SET autocommit,
				// but I don't think even that is possible with SQLite.
				$result = $this->get_translation_result( array( $this->noop( ) ) );
				break;

			case 'TRUNCATE':
				$this->rewriter->skip(); // TRUNCATE.
				$this->rewriter->skip(); // TABLE.
				$this->rewriter->add( new WP_SQLite_Token( 'DELETE', WP_SQLite_Token::TYPE_KEYWORD ) );
				$this->rewriter->add( new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ) );
				$this->rewriter->add( new WP_SQLite_Token( 'FROM', WP_SQLite_Token::TYPE_KEYWORD ) );
				$this->rewriter->consume_all();
				$result = $this->get_translation_result(
					array(
						WP_SQLite_Translator::get_query_object( $this->rewriter->get_updated_query() ),
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
						WP_SQLite_Translator::get_query_object( $query ),
					)
				);
				break;

			case 'DROP':
				$result = $this->translate_drop();
				break;

			case 'DESCRIBE':
				$this->rewriter->skip();
				$table_name = $this->rewriter->consume()->value;
				$result     = $this->get_translation_result(
					array(
						WP_SQLite_Translator::get_query_object( "PRAGMA table_info(\"$table_name\");" ),
					)
				);
				break;

			case 'SHOW':
				$result = $this->translate_show();
				break;

			default:
				throw new Exception( 'Unknown query type: ' . $this->query_type );
		}
		// The query type could have changed – let's grab the new one.
		if ( count( $result->queries ) ) {
			$last_query                = $result->queries[ count( $result->queries ) - 1 ];
			$first_word = preg_match( '/^\s*(\w+)/', $last_query->sql, $matches ) ? $matches[1] : '';
			$result->sqlite_query_type = strtoupper( $first_word );
		}
		$result->mysql_query_type = $this->query_type;
		return $result;
	}

	/**
	 * Translates the CREATE TABLE query.
	 *
	 * @param WP_SQLite_Query_Rewriter $this->rewriter The query rewriter.
	 *
	 * @return stdClass
	 */
	private function translate_create_table() {
		$table = $this->parse_create_table();

		$definitions = array();
		foreach ( $table->fields as $field ) {
			$definition = '"' . $field->name . '" ' . $field->sqlite_datatype;
			if ( $field->auto_increment ) {
				$definition .= ' PRIMARY KEY AUTOINCREMENT';
				if(count($table->primary_key) > 1) {
					throw new Exception( 'Cannot combine AUTOINCREMENT and multiple primary keys in SQLite' );
				}
			}
			else if ( $field->primary_key && count($table->primary_key) === 1 ) {
				$definition .= ' PRIMARY KEY ';
			}
			if ( $field->not_null ) {
				$definition .= ' NOT NULL';
			}
			if ( null !== $field->default ) {
				$definition .= ' DEFAULT ' . $field->default;
			}
			/*
			 * In MySQL, text fields are case-insensitive by default.
			 * COLLATE NOCASE emulates the same behavior in SQLite.
			 */
			if ( $field->sqlite_datatype === 'text' ) {
				$definition .= ' COLLATE NOCASE';
			}
			$definitions[] = $definition;
		}

		if(count($table->primary_key) > 1) {
			$definitions[] = 'PRIMARY KEY ("' . implode( '", "', $table->primary_key ) . '")';
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
	 * Parse the CREATE TABLE query.
	 *
	 * @param WP_SQLite_Query_Rewriter $this->rewriter The query rewriter.
	 *
	 * @return stdClass Structured data.
	 */
	private function parse_create_table() {
		$this->rewriter = clone $this->rewriter;
		$result               = new stdClass();
		$result->create_table = null;
		$result->name         = null;
		$result->fields       = array();
		$result->constraints  = array();
		$result->primary_key  = array();

		// The query starts with CREATE TABLE [IF NOT EXISTS]
		// Consume everything until the table name.
		while ( $token = $this->rewriter->consume() ) {
			// The table name is the first non-keyword token.
			if ( WP_SQLite_Token::TYPE_KEYWORD !== $token->type ) {
				// Store the table name for later
				$result->name = $token->value;

				// Drop the table name and store the CREATE TABLE command
				$this->rewriter->drop_last();
				$result->create_table = $this->rewriter->get_updated_query();
				break;
			}
		}

		// Move to the opening parenthesis:
		// CREATE TABLE wp_options (
		//                         ^ here
		$this->rewriter->skip(
			array(
				'type'  => WP_SQLite_Token::TYPE_OPERATOR,
				'value' => '(',
			)
		);

		// We're in the table definition now.
		// Read everything until the closing parenthesis.
		$declarations_depth = $this->rewriter->depth;
		do {
			// We want to capture a rewritten line of the query.
			// Let's clear any data we might have captured so far.
			$this->rewriter->replace_all( array() );

			/** 
			 * Decide how to parse the current line. We expect either:
			 * 
			 * Field definition, e.g.:
			 *     `my_field` varchar(255) NOT NULL DEFAULT 'foo'
			 * Constraint definition, e.g.:
			 *      PRIMARY KEY (`my_field`)
			 * 
			 * Lexer does not seem to reliably understand whether the
			 * first token is a field name or a reserved keyword, so
			 * instead we'll check whether the second non-whitespace 
			 * token is a data type.
			 */
			$second_token = $this->rewriter->peek_nth(2);

			$is_data_type = WP_SQLite_Token::TYPE_KEYWORD === $second_token->type && ( $second_token->flags & WP_SQLite_Token::FLAG_KEYWORD_DATA_TYPE );
			if ( $is_data_type ) {
				$result->fields[] = $this->parse_create_table_field();
			} else {
				$result->constraints[] = $this->parse_create_table_constraint(  $result->name );
			}
			// If we're back at the initial depth, we're done.
		} while ( $token && $this->rewriter->depth >= $declarations_depth );

		// Merge all the definitions of the primary key
		// Constraint:
		foreach ( $result->constraints as $k => $constraint ) {
			if ( 'PRIMARY KEY' === $constraint->value ) {
				$result->primary_key = array_merge(
					$result->primary_key,
					$constraint->columns
				);
				unset( $result->constraints[ $k ] );
			}
		}

		// Inline primary key in a field definition:
		foreach ( $result->fields as $k => $field ) {
			if ( $field->primary_key ) {
				$result->primary_key[] = $field->name;
			} else if( in_array( $field->name, $result->primary_key, true ) ) {
				$field->primary_key = true;
			}
		}

		// Remove duplicates
		$result->primary_key = array_unique( $result->primary_key );

		return $result;
	}

	/**
	 * Parses a CREATE TABLE query.
	 *
	 * @param WP_SQLite_Query_Rewriter $this->rewriter The query rewriter.
	 *
	 * @throws Exception If the query is not supported.
	 * @return stdClass
	 */
	private function parse_create_table_field() {
		$result                  = new stdClass();
		$result->name            = '';
		$result->sqlite_datatype = '';
		$result->not_null        = false;
		$result->default         = null;
		$result->auto_increment  = false;
		$result->primary_key     = false;

		$field_name_token = $this->rewriter->skip(); // Field name.
		$this->rewriter->add( new WP_SQLite_Token( "\n", WP_SQLite_Token::TYPE_WHITESPACE ) );
		$result->name = trim( $field_name_token->value, '`"\'' );

		$initial_depth = $this->rewriter->depth;

		$type = $this->rewriter->skip();
		$is_data_type = WP_SQLite_Token::TYPE_KEYWORD === $type->type && ( $type->flags & WP_SQLite_Token::FLAG_KEYWORD_DATA_TYPE );
		if ( ! $is_data_type ) {
			throw new Exception( 'Data type expected in MySQL query, unknown token received: ' . $type->value );
		}

		$type_name = strtolower( $type->value );
		if ( ! isset( $this->field_types_translation[ $type_name ] ) ) {
			throw new Exception( 'MySQL field type cannot be translated to SQLite: ' . $type_name );
		}
		$result->sqlite_datatype = $this->field_types_translation[ $type_name ];

		// Skip the length, e.g. (10) in VARCHAR(10).
		$paren_maybe = $this->rewriter->peek();
		if ( $paren_maybe && '(' === $paren_maybe->token ) {
			$this->rewriter->skip();
			$this->rewriter->skip();
			$this->rewriter->skip();
		}

		// Look for the NOT NULL and AUTO_INCREMENT flags.
		while ( $token = $this->rewriter->skip() ) {
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
				$result->default = $this->rewriter->consume()->token;
				continue;
			}

			if ( $this->is_create_table_field_terminator( $token, $initial_depth ) ) {
				$this->rewriter->add( $token );
				break;
			}
		}
		return $result;
	}

	/**
	 * Parses a CREATE TABLE constraint.
	 *
	 * @param WP_SQLite_Query_Rewriter $this->rewriter The query rewriter.
	 *
	 * @throws Exception If the query is not supported.
	 * @return stdClass
	 */
	private function parse_create_table_constraint() {
		$result          = new stdClass();
		$result->name    = '';
		$result->value   = '';
		$result->columns = array();

		$initial_depth = $this->rewriter->depth;
		$constraint    = $this->rewriter->peek();
		if ( ! $constraint->matches( WP_SQLite_Token::TYPE_KEYWORD ) ) {
			// Not a constraint declaration, but we're not finished
			// with the table declaration yet.
			throw new Exception( 'Unexpected token in MySQL query: ' . $this->rewriter->peek()->value );
		}

		if (
			'KEY' === $constraint->value
			|| 'PRIMARY KEY' === $constraint->value
			|| 'INDEX' === $constraint->value
			|| 'UNIQUE KEY' === $constraint->value
		) {
			$result->value = $constraint->value;

			$this->rewriter->skip(); // Constraint type.
			if ( 'PRIMARY KEY' !== $constraint->value ) {
				$result->name = $this->rewriter->skip()->value;
			}

			$constraint_depth = $this->rewriter->depth;
			$this->rewriter->skip(); // (
			do {
				$result->columns[] = trim( $this->rewriter->skip()->value, '`"\'' );
				$paren_maybe = $this->rewriter->peek();
				if ( $paren_maybe && '(' === $paren_maybe->token ) {
					$this->rewriter->skip();
					$this->rewriter->skip();
					$this->rewriter->skip();
				}
				$this->rewriter->skip(); // , or )
			} while ( $this->rewriter->depth > $constraint_depth );
		}

		do {
			$token = $this->rewriter->skip();
		} while ( ! $this->is_create_table_field_terminator( $token, $initial_depth ) );

		return $result;
	}

	/**
	 * Checks if the current token is the terminator of a CREATE TABLE field.
	 *
	 * @param WP_SQLite_Query_Rewriter $this->rewriter      The query rewriter.
	 * @param WP_SQLite_Token          $token         The current token.
	 * @param int                      $initial_depth The initial depth.
	 *
	 * @return bool
	 */
	private function is_create_table_field_terminator( $token, $initial_depth ) {
		return $this->rewriter->depth === $initial_depth - 1 || (
			$this->rewriter->depth === $initial_depth &&
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
	private function translate_crud() {
		$query_type = $this->rewriter->consume()->value;

		$params                  = array();
		$is_in_duplicate_section = false;
		$table_name              = null;
		$has_sql_calc_found_rows = false;

		// Consume the query type.
		if ( 'INSERT' === $query_type && 'IGNORE' === $this->rewriter->peek()->value ) {
			$this->rewriter->add( new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ) );
			$this->rewriter->add( new WP_SQLite_Token( 'OR', WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_RESERVED ) );
			$this->rewriter->consume(); // IGNORE.
		}

		// Consume and record the table name.
		$this->insert_columns = array();
		if ( 'INSERT' === $query_type || 'REPLACE' === $query_type ) {
			$this->rewriter->consume(); // INTO.
			$table_name = $this->rewriter->consume()->value; // Table name.
			
			// A list of columns is given if the opening parenthesis is
			// earlier than the VALUES keyword.
			$paren = $this->rewriter->peek( array(
				'type' => WP_SQLite_Token::TYPE_OPERATOR,
				'value' => '(',
			) );
			$values = $this->rewriter->peek( array(
				'type' => WP_SQLite_Token::TYPE_KEYWORD,
				'value' => 'VALUES',
			) );
			if ( $paren && $values && $paren->position <= $values->position ) {
				$this->rewriter->consume( array(
					'type' => WP_SQLite_Token::TYPE_OPERATOR,
					'value' => '(',
				) );
				while(true) {
					$token = $this->rewriter->consume();
					if ( $token->matches(WP_SQLite_Token::TYPE_OPERATOR, null, array(')') ) ) {
						break;
					}
					if ( !$token->matches( WP_SQLite_Token::TYPE_OPERATOR ) ) {
						$this->insert_columns[] = $token->value;
					}
				}
			}
		}

		$last_reserved_keyword = null;
		while ( $token = $this->rewriter->peek() ) {
			if ( ! $table_name && $last_reserved_keyword === 'FROM' ) {
				$table_name = $this->rewriter->consume()->value;
				continue;
			}

			if ( WP_SQLite_Token::TYPE_KEYWORD === $token->type && $token->flags & WP_SQLite_Token::FLAG_KEYWORD_RESERVED ) {
				$last_reserved_keyword = $token->value;
			}

			if ( 'SQL_CALC_FOUND_ROWS' === $token->value && WP_SQLite_Token::TYPE_KEYWORD === $token->type ) {
				$has_sql_calc_found_rows = true;
				$this->rewriter->skip();
				continue;
			}

			if ( 'AS' !== $last_reserved_keyword && WP_SQLite_Token::TYPE_STRING === $token->type && $token->flags & WP_SQLite_Token::FLAG_STRING_SINGLE_QUOTES ) {
				// Rewrite string values to bound parameters.
				$param_name            = ':param' . count( $params );
				$params[ $param_name ] = $this->preprocess_string_literal($token->value);
				$this->rewriter->skip();
				$this->rewriter->add( new WP_SQLite_Token( $param_name, WP_SQLite_Token::TYPE_STRING, WP_SQLite_Token::FLAG_STRING_SINGLE_QUOTES ) );
				$this->rewriter->add( new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ) );
				continue;
			}

			if ( WP_SQLite_Token::TYPE_KEYWORD === $token->type ) {
				if(
					$this->translate_concat_function($token)
					|| $this->translate_cast_as_binary($token)
					|| $this->translate_date_add_sub($token)
					|| $this->translate_values_function($token, $is_in_duplicate_section)
					|| $this->translate_date_format($token)
					|| $this->translate_interval($token)
					|| $this->translate_regexp_functions($token)
				) {
					continue;
				}

				if ( 'INSERT' === $query_type && 'DUPLICATE' === $token->keyword ) {
					$is_in_duplicate_section = true;
					$this->translate_on_duplicate_key($table_name);
					continue;
				}
			}

			if($this->translate_concat_comma_to_pipes($token)) {
				continue;
			}
			$this->rewriter->consume();
		}
		$this->rewriter->consume_all();

		$updated_query = $this->rewriter->get_updated_query();
		$result        = $this->get_translation_result( array() );

		if ( 'SELECT' === $query_type && $table_name && str_starts_with(strtolower( $table_name ), 'information_schema') ) {
			return $this->translate_information_schema_query(
				$updated_query
			);
		}

		if (
			// If the query contains a function that is not supported by SQLite,
			// return a dummy select. This check must be done after the query
			// has been rewritten to use parameters to avoid false positives
			// on queries such as `SELECT * FROM table WHERE field='CONVERT('`.
			strpos( $updated_query, '@@SESSION.sql_mode' ) !== false
			|| strpos( $updated_query, 'CONVERT( ' ) !== false
		) {
			$updated_query = 'SELECT 1=0';
			$params 	   = array();
		}

		// Emulate SQL_CALC_FOUND_ROWS for now.
		if ( $has_sql_calc_found_rows ) {
			$query = $updated_query;
			// We make the data for next SELECT FOUND_ROWS() statement.
			$unlimited_query         = preg_replace( '/\\bLIMIT\\s\d+(?:\s*,\s*\d+)?$/imsx', '', $query );
			$stmt                    = $this->sqlite->prepare( $unlimited_query );
			$stmt->execute( $params );
			$result->calc_found_rows = count( $stmt->fetchAll() );
		}

		// Emulate FOUND_ROWS() by counting the rows in the result set.
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
			$delete_result = $this->postprocess_double_delete($params);
			if($delete_result) {
				return $delete_result;
			}
		}

		$result->queries[] = WP_SQLite_Translator::get_query_object( $updated_query, $params );
		return $result;
	}

	private function preprocess_string_literal($value) {
		/**
		 * The code below converts the date format to one preferred by SQLite.
		 * 
		 * MySQL accepts ISO 8601 date strings:        'YYYY-MM-DDTHH:MM:SSZ'
		 * SQLite prefers a slightly different format: 'YYYY-MM-DD HH:MM:SS'
		 * 
		 * SQLite date and time functions can understand the ISO 8601 notation, but
		 * lookups don't. To keep the lookups working, we need to store all dates
		 * in UTC without the "T" and "Z" characters.
		 * 
		 * Caveat: It will adjust every string that matches the pattern, not just dates.
		 * 
		 * In theory, we could only adjust semantic dates, e.g. the data inserted
		 * to a date column or compared against a date column.
		 * 
		 * In practice, this is hard because dates are just text – SQLite has no separate
		 * datetime field. We'd need to cache the MySQL data type from the original 
		 * CREATE TABLE query and then keep refreshing the cache after each ALTER TABLE query.
		 *
		 * That's a lot of complexity that's perhaps not worth it. Let's just convert
		 * everything for now. The regexp assumes "Z" is always at the end of the string,
		 * which is true in the unit test suite, but there could also be a timezone offset
		 * like "+00:00" or "+01:00". We could add support for that later if needed.
		 */
		if( 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})Z$/', $value, $matches ) ) {
			$value = $matches[1] . ' ' . $matches[2];
		}

		/**
		 * Mimic MySQL's behavior and truncate invalid dates.
		 * 
		 * "2020-12-41 14:15:27" becomes "0000-00-00 00:00:00"
		 * 
		 * WARNING: We have no idea whether the truncated value should
		 * be treated as a date in the first place.
		 * In SQLite dates are just strings. This could be a perfectly
		 * valid string that just happens to contain a date-like value.
		 * 
		 * At the same time, WordPress seems to rely on MySQL's behavior
		 * and even tests for it in Tests_Post_wpInsertPost::test_insert_empty_post_date.
		 * Let's truncate the dates for now.
		 * 
		 * In the future, let's update WordPress to do its own date validation
		 * and stop relying on this MySQL feature,
		 */
		if( 1 === preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/', $value, $matches ) ) {
			if( false === strtotime($value ) ) {
				$value = '0000-00-00 00:00:00';
			}
		}
		return $value;
	}

	private function postprocess_double_delete($rewritten_params) {
		// Naive rewriting of DELETE JOIN query.
		// @TODO: Actually rewrite the query instead of using a hardcoded workaround.
		$updated_query = $this->rewriter->get_updated_query();
		if ( str_contains( $updated_query, ' JOIN ' ) ) {
			return $this->get_translation_result(
				array(
					WP_SQLite_Translator::get_query_object(
						"DELETE FROM {$this->table_prefix}options WHERE option_id IN (SELECT MIN(option_id) FROM {$this->table_prefix}options GROUP BY option_name HAVING COUNT(*) > 1)"
					),
				)
			);
		}

		$rewriter = new WP_SQLite_Query_Rewriter( $this->rewriter->output_tokens );

		$comma = $rewriter->peek( array(
			'type' => WP_SQLite_Token::TYPE_OPERATOR,
			'value' => ',',
		) );
		$from = $rewriter->peek( array(
			'type' => WP_SQLite_Token::TYPE_KEYWORD,
			'value' => 'FROM',
		) );
		// It's a dual delete query if the comma comes before the FROM.
		if ( !$comma || !$from || $comma->position >= $from->position ) {
			return;
		}
	
		$table_name = $rewriter->skip()->value;
		$rewriter->add( new WP_SQLite_Token( 'SELECT', WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_RESERVED ) );

		/*
		 * Get table name.
		 */
		$from = $rewriter->peek(
			array(
				'type'  => WP_SQLite_Token::TYPE_KEYWORD,
				'value' => 'FROM',
			)
		);
		$index = array_search( $from, $rewriter->input_tokens, true );
		for($i=$index+1; $i<$rewriter->max; $i++) {
			// Assume the table name is the first token after FROM
			if(!$rewriter->input_tokens[$i]->is_semantically_void()) {
				$table_name = $rewriter->input_tokens[$i]->value;
				break;
			}
		}
		if(!$table_name) {
			throw new Exception('Could not find table name for dual delete query.');
		}

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
		$stmt->execute($rewritten_params);
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
		return $this->get_translation_result(
			array(
				WP_SQLite_Translator::get_query_object( $query ),
			),
			true,
			count( $ids_to_delete )
		);
	}

	private function translate_information_schema_query($query) {
		// @TODO: Actually rewrite the columns
		if ( str_contains( $query, 'bytes' ) ) {
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

	private function translate_cast_as_binary($token) {
		if ( $token->matches( WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_DATA_TYPE ) ) {
			$call_parent = $this->rewriter->last_call_stack_element();
			// Rewrite AS BINARY to AS BLOB inside CAST() calls.
			if (
				$call_parent
				&& 'CAST' === $call_parent['function']
				&& 'BINARY' === $token->value
			) {
				$this->rewriter->skip();
				$this->rewriter->add( new WP_SQLite_Token( 'BLOB', $token->type, $token->flags ) );
				return true;
			}
		}
	}

	private function translate_concat_function($token) {
		/**
		 * Skip the CONCAT function but leave the parentheses.
		 * There is another code block below that replaces the 
		 * , operators between the CONCAT arguments with ||.
		 */
		if (
			'CONCAT' === $token->keyword
			&& $token->flags & WP_SQLite_Token::FLAG_KEYWORD_FUNCTION
		) {
			$this->rewriter->skip();
			return true;
		}
	}

	private function translate_concat_comma_to_pipes($token) {
		if ( WP_SQLite_Token::TYPE_OPERATOR === $token->type ) {
			$call_parent = $this->rewriter->last_call_stack_element();
			// Rewrite commas to || in CONCAT() calls.
			if (
				$call_parent
				&& 'CONCAT' === $call_parent['function']
				&& ',' === $token->value
				&& $token->flags & WP_SQLite_Token::FLAG_OPERATOR_SQL
			) {
				$this->rewriter->skip();
				$this->rewriter->add( new WP_SQLite_Token( '||', WP_SQLite_Token::TYPE_OPERATOR ) );
				return true;
			}
		}
	}

	private function translate_date_add_sub( $token) {
		if (
			$token->flags & WP_SQLite_Token::FLAG_KEYWORD_FUNCTION && (
				'DATE_ADD' === $token->keyword ||
				'DATE_SUB' === $token->keyword
			)
		) {
			$this->rewriter->skip();
			$this->rewriter->add( new WP_SQLite_Token( 'DATETIME', WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_FUNCTION ) );
			return true;
		}
	}

	private function translate_values_function( $token, $is_in_duplicate_section ) {
		if (
			$token->flags & WP_SQLite_Token::FLAG_KEYWORD_FUNCTION &&
			'VALUES' === $token->keyword &&
			$is_in_duplicate_section
		) {
			/*
			Rewrite:  VALUES(`option_name`)
			to:       excluded.option_name
			*/
			$this->rewriter->skip();
			$this->rewriter->add( new WP_SQLite_Token( 'excluded', WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_KEY ) );
			$this->rewriter->add( new WP_SQLite_Token( '.', WP_SQLite_Token::TYPE_OPERATOR ) );

			$this->rewriter->skip(); // Skip the opening `(`.
			// Consume the column name.
			$this->rewriter->consume(
				array(
					'type'  => WP_SQLite_Token::TYPE_OPERATOR,
					'value' => ')',
				)
			);
			// Drop the consumed ')' token.
			$this->rewriter->drop_last();
			return true;
		}
	}

	private function translate_date_format($token) {
		if (
			$token->flags & WP_SQLite_Token::FLAG_KEYWORD_FUNCTION &&
			'DATE_FORMAT' === $token->keyword
		) {
			// Rewrite DATE_FORMAT( `post_date`, '%Y-%m-%d' ) to STRFTIME( '%Y-%m-%d', `post_date` )

			// Skip the DATE_FORMAT function name
			$this->rewriter->skip();
			// Skip the opening `(`.
			$this->rewriter->skip();

			// Skip the first argument so we can read the second one.
			$first_arg = $this->rewriter->skip_and_return_all(
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
			$format     = $this->rewriter->skip()->value;
			$new_format = strtr( $format, $this->mysql_date_format_to_sqlite_strftime );
			if( ! $new_format ) {
				throw new Exception( "Could not translate a DATE_FORMAT() format to STRFTIME format ($format)" );
			}

			/**
			 * MySQL supports comparing strings and floats, e.g.
			 * 
			 * > SELECT '00.42' = 0.4200
			 * 1
			 * 
			 * SQLite does not support that. At the same time,
			 * WordPress likes to filter dates by comparing numeric
			 * outputs of DATE_FORMAT() to floats, e.g.:
			 * 
			 *     -- Filter by hour and minutes
			 *     DATE_FORMAT(
			 *         STR_TO_DATE('2014-10-21 00:42:29', '%Y-%m-%d %H:%i:%s'),
			 *         '%H.%i'
			 *     ) = 0.4200;
			 * 
			 * Let's cast the STRFTIME() output to a float if
			 * the date format is typically used for string 
			 * to float comparisons.
			 * 
			 * In the future, let's update WordPress to avoid comparing
			 * strings and floats.
			 */
			$cast_to_float = '%H.%i' === $format;
			if( $cast_to_float ) {
				$this->rewriter->add( new WP_SQLite_Token( 'CAST', WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_FUNCTION ) );
				$this->rewriter->add( new WP_SQLite_Token( '(', WP_SQLite_Token::TYPE_OPERATOR ) );
			}

			$this->rewriter->add( new WP_SQLite_Token( 'STRFTIME', WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_FUNCTION ) );
			$this->rewriter->add( new WP_SQLite_Token( '(', WP_SQLite_Token::TYPE_OPERATOR ) );
			$this->rewriter->add( new WP_SQLite_Token( "'$new_format'", WP_SQLite_Token::TYPE_STRING ) );
			$this->rewriter->add( new WP_SQLite_Token( ',', WP_SQLite_Token::TYPE_OPERATOR ) );

			// Add the buffered tokens back to the stream.
			$this->rewriter->add_many( $first_arg );

			// Consume the closing ')'
			$this->rewriter->consume(array(
				'type'  => WP_SQLite_Token::TYPE_OPERATOR,
				'value' => ')',
			));

			if( $cast_to_float ) {
				$this->rewriter->add( new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ) );
				$this->rewriter->add( new WP_SQLite_Token( 'as', WP_SQLite_Token::TYPE_OPERATOR ) );
				$this->rewriter->add( new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ) );
				$this->rewriter->add( new WP_SQLite_Token( 'FLOAT', WP_SQLite_Token::TYPE_KEYWORD ) );
				$this->rewriter->add( new WP_SQLite_Token( ')', WP_SQLite_Token::TYPE_OPERATOR ) );
			}

			return true;
		}
	}

	private function translate_interval($token) {
		if ( 'INTERVAL' === $token->keyword ) {
			// Skip the INTERVAL keyword from the output stream.
			$this->rewriter->skip();

			$num  = $this->rewriter->skip()->value;
			$unit = $this->rewriter->skip()->value;

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
			for ( $j = count( $this->rewriter->call_stack ) - 1; $j >= 0; $j-- ) {
				$call = $this->rewriter->call_stack[ $j ];
				if ( 'DATE_ADD' === $call['function'] ) {
					$interval_op = '+';
					break;
				}
				if ( 'DATE_SUB' === $call['function'] ) {
					$interval_op = '-';
					break;
				}
			}

			$this->rewriter->add( new WP_SQLite_Token( "'{$interval_op}$num $unit'", WP_SQLite_Token::TYPE_STRING ) );
			return true;
		}
	}

	private function translate_regexp_functions($token) {
		if ( 'REGEXP' === $token->keyword || 'RLIKE' === $token->keyword) {
			$this->rewriter->skip();
			$this->rewriter->add( new WP_SQLite_Token( "REGEXP", WP_SQLite_Token::TYPE_KEYWORD ) );

			$next = $this->rewriter->peek();
			/*
			* If the query says REGEXP BINARY, the comparison is byte-by-byte
			* and letter casing matters – lowercase and uppercase letters are
			* represented using different byte codes.
			* 
			* The REGEXP function can't be easily made to accept two
			* parameters, so we'll have to use a hack to get around this.
			* 
			* If the first character of the pattern is a null byte, we'll
			* remove it and make the comparison case-sensitive. This should
			* be reasonably safe since PHP does not allow null bytes in
			* regular expressions anyway.
			*/
			if ( $next->matches(WP_SQLite_Token::TYPE_KEYWORD, null, array( 'BINARY' ) ) ) {
				// Skip the "BINARY" keyword.
				$this->rewriter->skip();
				/*
				 * Prepend a null byte to the pattern.
				 */
				$this->rewriter->add_many([
					new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ),
					new WP_SQLite_Token( 'char', WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_FUNCTION ),
					new WP_SQLite_Token( '(', WP_SQLite_Token::TYPE_OPERATOR ),
					new WP_SQLite_Token( '0', WP_SQLite_Token::TYPE_NUMBER ),
					new WP_SQLite_Token( ')', WP_SQLite_Token::TYPE_OPERATOR ),
					new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ),
					new WP_SQLite_Token( '||', WP_SQLite_Token::TYPE_OPERATOR ),
					new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ),
				]);
			}
			return true;
		}
	}

	private function translate_on_duplicate_key($table_name) {
		/*
		* Rewrite:
		* 		ON DUPLICATE KEY UPDATE `option_name` = VALUES(`option_name`)
		* to:
		* 		ON CONFLICT(ip) DO UPDATE SET option_name = excluded.option_name
		*/

		// Find the conflicting column:
		// 1. Find the primary key.
		$q       = $this->sqlite->query( 'SELECT l.name FROM pragma_table_info("' . $table_name . '") as l WHERE l.pk > 0;' );
		$pkrows  = $q->fetchAll(0);
		$pk_columns = array();
		foreach($pkrows as $pkrow) {
			$pk_columns[] = $pkrow['name'];
		}

		// 2. Find all the unique columns.
		$unique_columns = array();
		$q       = $this->sqlite->query( 'SELECT * FROM pragma_index_list("' . $table_name . '") as l;' );
		$indices = $q->fetchAll();
		foreach($indices as $index) {
			if('1' === $index['unique']) {
				$q      = $this->sqlite->query( 'SELECT * FROM pragma_index_info("'.$index['name'].'") as l;' );
				$row = $q->fetch();
				$unique_columns[] = $row['name'];
			}
		}

		// 3. Find the first unique column that is also in the INSERT statement.
		//    Default to the primary key.
		$conflict_columns = array();
		if($this->insert_columns) {
			foreach($this->insert_columns as $col) {
				if(
					in_array($col, $unique_columns)
					|| in_array($col, $pk_columns)
				) {
					$conflict_columns[] = $col;
				}
			}
		} else if(count($pk_columns) > 1) {
			$conflict_columns = $pk_columns;
		} else if(count($unique_columns) > 0) {
			$conflict_columns = array($unique_columns[0]);
		}
		if(!$conflict_columns){
			$conflict_columns = $pk_columns;
		}

		// If there is no conflict column, then we can't rewrite the statement.
		if(!$conflict_columns) {
			// Drop the consumed "ON"
			$this->rewriter->drop_last();
			// Skip over "DUPLICATE", "KEY", and "UPDATE".
			$this->rewriter->skip();
			$this->rewriter->skip();
			$this->rewriter->skip();
			while($this->rewriter->skip()){

			}
			return;
		}

		// Skip over "DUPLICATE", "KEY", and "UPDATE".
		$this->rewriter->skip();
		$this->rewriter->skip();
		$this->rewriter->skip();
		
		// Add the CONFLICT keyword.
		$this->rewriter->add( new WP_SQLite_Token( 'CONFLICT', WP_SQLite_Token::TYPE_KEYWORD ) );

		// Add "( <primary key> ) DO UPDATE SET ".
		$this->rewriter->add( new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ) );
		$this->rewriter->add( new WP_SQLite_Token( '(', WP_SQLite_Token::TYPE_OPERATOR ) );

		$max = count($conflict_columns);
		foreach($conflict_columns as $i => $conflict_column) {
			$this->rewriter->add( new WP_SQLite_Token( '"'.$conflict_column.'"', WP_SQLite_Token::TYPE_KEYWORD, WP_SQLite_Token::FLAG_KEYWORD_KEY ) );
			if($i !== $max - 1) {
				$this->rewriter->add( new WP_SQLite_Token( ',', WP_SQLite_Token::TYPE_OPERATOR ) );
				$this->rewriter->add( new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ) );
			}
		}
		$this->rewriter->add( new WP_SQLite_Token( ')', WP_SQLite_Token::TYPE_OPERATOR ) );
		$this->rewriter->add( new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ) );
		$this->rewriter->add( new WP_SQLite_Token( 'DO', WP_SQLite_Token::TYPE_KEYWORD ) );
		$this->rewriter->add( new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ) );
		$this->rewriter->add( new WP_SQLite_Token( 'UPDATE', WP_SQLite_Token::TYPE_KEYWORD ) );
		$this->rewriter->add( new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ) );
		$this->rewriter->add( new WP_SQLite_Token( 'SET', WP_SQLite_Token::TYPE_KEYWORD ) );
		$this->rewriter->add( new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ) );
	}

	/**
	 * Translate ALTER query.
	 *
	 * @throws Exception If the subject is not 'table', or we're performing an unknown operation.
	 * @return stdClass
	 */
	private function translate_alter() {
		$this->rewriter->consume();
		$subject = strtolower( $this->rewriter->consume()->token );
		if ( 'table' !== $subject ) {
			throw new Exception( 'Unknown subject: ' . $subject );
		}

		$table_name = strtolower( $this->rewriter->consume()->token );
		$queries = [];
		do {
			// This loop may be executed multiple times if there are multiple operations in the ALTER query.
			// Let's reset the initial state on each pass.
			$this->rewriter->replace_all([
				new WP_SQLite_Token( 'ALTER', WP_SQLite_Token::TYPE_KEYWORD ),
				new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ),
				new WP_SQLite_Token( 'TABLE', WP_SQLite_Token::TYPE_KEYWORD ),
				new WP_SQLite_Token( ' ', WP_SQLite_Token::TYPE_WHITESPACE ),
				new WP_SQLite_Token( $table_name, WP_SQLite_Token::TYPE_KEYWORD ),
			]);
			$op_type    = strtolower( $this->rewriter->consume()->token );
			$op_subject = strtolower( $this->rewriter->consume()->token );
			if ( 'fulltext key' === $op_subject ) {
				return $this->get_translation_result( array( $this->noop() ) );
			}

			if ( 'add' === $op_type ) {
				if ( 'column' === $op_subject ) {
					$this->consume_data_types();
				} elseif ( 'key' === $op_subject || 'index' === $op_subject || 'unique key' === $op_subject ) {
					$key_name     = $this->rewriter->consume()->value;
					$index_prefix = 'unique key' === $op_subject ? 'UNIQUE ' : '';
					$this->rewriter->replace_all(
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

					while ( $token = $this->rewriter->consume() ) {
						if ( '(' === $token->token ) {
							$this->rewriter->drop_last();
							break;
						}
					}

					// Consume all the fields, skip the sizes like `(20)` in `varchar(20)`.
					while ( $token = $this->rewriter->consume() ) {
						if(!$token->matches(WP_SQLite_Token::TYPE_OPERATOR)) {
							$token->token = "`".trim( $token->token, '`"\'' )."`";
							$token->value = "`".trim( $token->value, '`"\'' )."`";
						}
						$paren_maybe = $this->rewriter->peek();

						if ( $paren_maybe && '(' === $paren_maybe->token ) {
							$this->rewriter->skip();
							$this->rewriter->skip();
							$this->rewriter->skip();
						}
						if($token->value === ')') {
							break;
						}
					}
				} else {
					throw new Exception( "Unknown operation: $op_type $op_subject" );
				}
			} elseif ( 'change' === $op_type ) {
				if ( 'column' === $op_subject ) {
					$this->consume_data_types();
				} else {
					throw new Exception( "Unknown operation: $op_type $op_subject" );
				}
			} elseif ( 'drop' === $op_type ) {
				if ( 'column' === $op_subject ) {
					$this->rewriter->consume_all();
				} elseif ( 'key' === $op_subject ||  'index' === $op_subject ) {
					$key_name = $this->rewriter->consume()->value;
					$this->rewriter->replace_all(
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
			$queries[] = WP_SQLite_Translator::get_query_object(
				$this->rewriter->get_updated_query()
			);
		} while ( $this->rewriter->skip(array(
			'type' => WP_SQLite_Token::TYPE_OPERATOR,
			'value' => ',',
		)) );

		return $this->get_translation_result($queries);
	}

	/**
	 * Translates a CREATE query.
	 *
	 * @param The query rewriter.
	 *
	 * @throws Exception If the query is an unknown create type.
	 * @return stdClass The translation result.
	 */
	private function translate_create( ) {
		$this->rewriter->consume();
		$what = $this->rewriter->consume()->token;

		/**
		 * Technically it is possible to support temporary tables as follows:
		 *   ATTACH '' AS 'tempschema';
		 *   CREATE TABLE tempschema.<name>(...)...;
		 * However, for now, let's just ignore the TEMPORARY keyword.
		 */
		if('TEMPORARY' === $what) {
			$this->rewriter->drop_last();
			$what = $this->rewriter->consume()->token;
		}

		switch ( $what ) {
			case 'TABLE':
				return $this->translate_create_table();

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
	 * @param The query rewriter.
	 *
	 * @throws Exception If the query is an unknown drop type.
	 * @return stdClass The translation result.
	 */
	private function translate_drop( ) {
		$this->rewriter->consume();
		$what = $this->rewriter->consume()->token;

		/**
		 * Technically it is possible to support temporary tables as follows:
		 *   ATTACH '' AS 'tempschema';
		 *   CREATE TABLE tempschema.<name>(...)...;
		 * However, for now, let's just ignore the TEMPORARY keyword.
		 */
		if('TEMPORARY' === $what) {
			$this->rewriter->drop_last();
			$what = $this->rewriter->consume()->token;
		}

		switch ( $what ) {
			case 'TABLE':
				$this->rewriter->consume_all();
				return $this->get_translation_result( array( WP_SQLite_Translator::get_query_object( $this->rewriter->get_updated_query() ) ) );

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
	 * @param The query rewriter.
	 *
	 * @throws Exception If the query is an unknown show type.
	 * @return stdClass The translation result.
	 */
	private function translate_show( ) {
		$this->rewriter->skip();
		$what1 = $this->rewriter->consume()->token;
		$what2 = $this->rewriter->consume()->token;
		$what  = $what1 . ' ' . $what2;
		switch ( $what ) {
			case 'CREATE PROCEDURE':
				return $this->get_translation_result(
					array(
						$this->noop(),
					)
				);

			case 'FULL COLUMNS':
				$this->rewriter->consume();
				$table_name = $this->rewriter->consume()->token;
				return $this->get_translation_result(
					array(
						WP_SQLite_Translator::get_query_object(
							"PRAGMA table_info($table_name);"
						),
					)
				);

			case 'COLUMNS FROM':
				$table_name = $this->rewriter->consume()->token;
				return $this->get_translation_result(
					array(
						WP_SQLite_Translator::get_query_object(
							"PRAGMA table_info(\"$table_name\");"
						),
					)
				);

			case 'INDEX FROM':
				$table_name = $this->rewriter->consume()->token;
				return $this->get_translation_result(
					array(
						WP_SQLite_Translator::get_query_object(
							"PRAGMA index_info($table_name);"
						),
					)
				);

			case 'TABLES LIKE':
				$table_expression = $this->rewriter->skip();
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
	 * @param The query rewriter.
	 *
	 * @return void
	 */
	private function consume_data_types( ) {
		while ( $type = $this->rewriter->consume(
			array(
				'type'  => WP_SQLite_Token::TYPE_KEYWORD,
				'flags' => WP_SQLite_Token::FLAG_KEYWORD_DATA_TYPE,
			)
		) ) {
			$typelc = strtolower( $type->value );
			if ( isset( $this->field_types_translation[ $typelc ] ) ) {
				$this->rewriter->drop_last();
				$this->rewriter->add(
					new WP_SQLite_Token(
						$this->field_types_translation[ $typelc ],
						$type->type,
						$type->flags
					)
				);
			}

			$paren_maybe = $this->rewriter->peek();
			if ( $paren_maybe && '(' === $paren_maybe->token ) {
				$this->rewriter->skip();
				$this->rewriter->skip();
				$this->rewriter->skip();
			}
		}
	}

}
