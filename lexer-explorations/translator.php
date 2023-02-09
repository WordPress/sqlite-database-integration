<?php

// require autoload

use PhpMyAdmin\SqlParser\Context;
use PhpMyAdmin\SqlParser\Token;

// Assumes the PhpMyAdmin Sql Parser is installed via Composer
require_once __DIR__ . '/sql-parser/vendor/autoload.php';
require_once __DIR__ . '/class-wp-sqlite-lexer.php';

// Throw exception on notices and warnings – handy for debugging in isolation,
// bad for testing WordPress.
// 
// function err_handle( $err_no, $err_str, $err_file, $err_line ) {
// 	$msg = "$err_str in $err_file on line $err_line";
// 	if ( E_NOTICE === $err_no || E_WARNING === $err_no ) {
// 		throw new ErrorException( $msg, $err_no );
// 	} else {
// 		echo $msg;
// 	}
// }

// set_error_handler( 'err_handle' );

function queries() {
	$fp = fopen( __DIR__ . '/wp-phpunit.sql', 'r' );
	// Read $fp line by line. Extract an array of multiline Queries. Queries are delimited by /* \d+ */
	$buf = '';
	while ( $line = fgets( $fp ) ) {
		if ( 1 === preg_match( '/^\/\* [0-9]+ \*\//', $line ) ) {
			if ( trim( $buf ) ) {
				yield trim( $buf );
			}
			$buf = substr( $line, strpos( $line, '*/' ) + 2 );
		} else {
			$buf .= $line;
		}
	}
	fclose( $fp );
}

// Create fulltext index -> select 1=1;
// Remove: collate / default character set
//

class SQLiteQuery {
    public $sql;
    public $params;
    
    public function __construct( $sql, $params=[] ) {
        $this->sql = trim($sql);
        $this->params = $params;
    }

}

/**
 * \PhpMyAdmin\SqlParser\Parser gets derailed by queries like:
 * * SELECT * FROM table LIMIT 0,1
 * * SELECT 'a' LIKE '%';
 * Lexer is more reliable.
 */

class SQLiteTranslationResult {
    public $queries = [];
    public $has_result = false;
    public $result = null;
    public $calc_found_rows = null;
    public $query_type = null;

    public function __construct( 
        $queries,
        $has_result=false, 
        $result=null
    ) {
        $this->queries = $queries;
        $this->has_result = $has_result;
        $this->result = $result;
    }
}

class SQLiteTranslator {

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
	function translate(string $query, $last_found_rows=null) {
        $this->query = $query;
        $this->last_found_rows = $last_found_rows;

        $tokens = (new \PhpMyAdmin\SqlParser\Lexer( $query ))->list->tokens;
        $r = new QueryRewriter($tokens);
		$query_type = $r->peek()->value;
        switch($query_type) {
            case 'ALTER':
                $result = $this->translate_alter($r);
                break;
            case 'CREATE':
                $result = $this->translate_create($r);
                break;
            case 'REPLACE':
            case 'SELECT':
            case 'INSERT':
            case 'UPDATE':
            case 'DELETE':
                $result = $this->translate_crud($r);
                break;
            case 'CALL':
            case 'SET':
                // It would be lovely to support at least SET autocommit
                // but I don't think even that is possible with SQLite
                $result = new SQLiteTranslationResult([$this->noop()]);
                break;
            case 'START TRANSACTION':
                $result = new SQLiteTranslationResult([
                    new SQLiteQuery('BEGIN')
                ]);
                break;
            case 'BEGIN':
            case 'COMMIT':
            case 'ROLLBACK':
            case 'TRUNCATE':
                $result = new SQLiteTranslationResult([
                    new SQLiteQuery($this->query)
                ]);
                break;
            case 'DROP':
                $result = $this->translate_drop($r);
                break;
            case 'DESCRIBE':
                $table_name = $r->consume()->token;
                $result = new SQLiteTranslationResult([
                    new SQLiteQuery("PRAGMA table_info($table_name);")
                ]);
                break;
            case 'SHOW':
                $result = $this->translate_show($r);
                break;
            default:
                throw new \Exception( 'Unknown query type: ' . $query_type );
        }
        // The query type could have changed – let's grab the new one
        if(count($result->queries)) {
            $last_query = $result->queries[count($result->queries)-1];
            $result->query_type = strtoupper(strtok($last_query->sql, ' '));
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
        Context::setMode( WP_SQLite_Lexer::SQL_MODE_ANSI_QUOTES );
        $queries = [
            new SQLiteQuery($stmt->build()),
        ];
        foreach($extra_queries as $extra_query) {
            $queries[] = new SQLiteQuery($extra_query);
        }

        return new SQLiteTranslationResult(
            $queries
        );
    }

    private function translate_crud(QueryRewriter $r) {
        // Very naive check to see if we're dealing with an information_schema
        // query. If so, we'll just return a dummy result.
        // @TODO: A proper check and a better translation.
        if ( str_contains( $this->query, 'information_schema' ) ) {
            return new SQLiteTranslationResult([
                new SQLiteQuery(
                    'SELECT \'\' as "table", 0 as "rows", 0 as "bytes'
                )
            ]);
        }
        
        $query_type = $r->consume()->value;

        // Naive regexp check
        if(!$this->has_regexp && strpos( $this->query, ' REGEXP ' ) !== false) {
            // Bale out if we can't run the query
            return new SQLiteTranslationResult([$this->noop()]);
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
            var_dump("done");
            die($this->query);
            // Return a dummy select for now
            return 'SELECT 1=1';
        }
    
        // echo '**MySQL query:**' . PHP_EOL;
        // echo $query . PHP_EOL . PHP_EOL;
        $params                  = array();
        $is_in_duplicate_section = false;
        $table_name              = null;
        $has_SQL_CALC_FOUND_ROWS = false;

        // Consume the query type
        if ( 'INSERT' === $query_type && 'IGNORE' === $r->peek()->value ) {
            $r->add( new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ) );
            $r->add( new Token( 'OR', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ) );
            $r->consume(); // IGNORE
        }

        // Consume and record the table name
        if ( 'INSERT' === $query_type || 'REPLACE' === $query_type ) {
            $r->consume(); // INTO
            $table_name = $r->consume()->value; // table name
        }

        while($token = $r->consume()) {
            if($token->value === 'SQL_CALC_FOUND_ROWS' && $token->type === WP_SQLite_Lexer::TYPE_KEYWORD) {
                $has_SQL_CALC_FOUND_ROWS = true;
                $r->drop_last();
                continue;
            }

            if ( WP_SQLite_Lexer::TYPE_STRING === $token->type && $token->flags & WP_SQLite_Lexer::FLAG_STRING_SINGLE_QUOTES ) {
                // Rewrite string values to bound parameters
                $param_name            = ':param' . count( $params );
                $params[ $param_name ] = $token->value;
                $r->replace_last( new Token( $param_name, WP_SQLite_Lexer::TYPE_STRING, WP_SQLite_Lexer::FLAG_STRING_SINGLE_QUOTES ) );
                continue;
            }
            
            if ( WP_SQLite_Lexer::TYPE_KEYWORD === $token->type ) {
                if ( 'RAND' === $token->keyword && $token->flags & WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION ) {
                    $r->replace_last( new Token( 'RANDOM', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION ) );
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
                        $firstArg = $r->skipOver([
                            'type' => WP_SQLite_Lexer::TYPE_OPERATOR,
                            'value' => [',', ')']
                        ]);

                        $terminator = array_pop($firstArg);
    
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
    
                        $r->add( new Token( 'STRFTIME', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION ) );
                        $r->add( new Token( '(', WP_SQLite_Lexer::TYPE_OPERATOR ) );
                        $r->add( new Token( "'$format'", WP_SQLite_Lexer::TYPE_STRING ) );
                        $r->add( new Token( ',', WP_SQLite_Lexer::TYPE_OPERATOR ) );
                        $r->addMany($firstArg);
                        if ( ')' === $terminator->value ) {
                            $r->add($terminator);
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
                    $r->replace_last( new Token( 'DATE', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION ) );
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
                    $r->replace_last( new Token( 'excluded', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_KEY ) );
                    $r->add( new Token( '.', WP_SQLite_Lexer::TYPE_OPERATOR ) );

                    $r->skip(); // Skip the opening (
                    // Consume the column name
                    $r->consume([
                        'type' => WP_SQLite_Lexer::TYPE_OPERATOR,
                        'value' => ')'
                    ]);
                    // Drop the consumed ')' token
                    $r->drop_last();
                    continue;
                }
                if (
                    $token->flags & WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION &&
                    'DATE_FORMAT' === $token->keyword
                ) {
                    // DATE_FORMAT( `post_date`, '%Y-%m-%d' )

                    $r->replace_last( new Token( 'STRFTIME', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION ) );
                    // The opening (
                    $r->consume();

                    // Skip the first argument so we can read the second one
                    $firstArg = $r->skipOver([
                        'type' => WP_SQLite_Lexer::TYPE_OPERATOR,
                        'value' => ','
                    ]);

                    // Make sure we actually found the comma
                    $comma = array_pop($firstArg);
                    if( ',' !== $comma->value ) {
                        throw new Exception( 'Could not parse the DATE_FORMAT() call' );
                    }

                    // Skip the second argument but capture the token
                    $format = $r->skip()->value;
                    $new_format = strtr( $format, $this->mysql_php_date_formats );

                    $r->add( new Token( "'$new_format'", WP_SQLite_Lexer::TYPE_STRING ) );
                    $r->add( new Token( ',', WP_SQLite_Lexer::TYPE_OPERATOR ) );

                    // Add the buffered tokens back to the stream:
                    $r->addMany($firstArg);
    
                    continue;
                }
                if ( 'INTERVAL' === $token->keyword ) {
                    // Remove the INTERVAL keyword from the output stream
                    $r->drop_last();
                    
                    $num             = $r->skip()->value;
                    $unit            = $r->skip()->value;
    
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
    
                    $r->add( new Token( "'{$interval_op}$num $unit'", WP_SQLite_Lexer::TYPE_STRING ) );
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
                    $r->replace_last( new Token( 'CONFLICT', WP_SQLite_Lexer::TYPE_KEYWORD ) );
                    // Skip overthe "KEY" and "UPDATE" keywords
                    $r->skip();
                    $r->skip();

                    // Add "( <primary key> ) DO UPDATE SET "
                    $r->add( new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ) );
                    $r->add( new Token( '(', WP_SQLite_Lexer::TYPE_OPERATOR ) );
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
                    $r->add( new Token( $pk_name, WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_KEY ) );
                    $r->add( new Token( ')', WP_SQLite_Lexer::TYPE_OPERATOR ) );
                    $r->add( new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ) );
                    $r->add( new Token( 'DO', WP_SQLite_Lexer::TYPE_KEYWORD ) );
                    $r->add( new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ) );
                    $r->add( new Token( 'UPDATE', WP_SQLite_Lexer::TYPE_KEYWORD ) );
                    $r->add( new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ) );
                    $r->add( new Token( 'SET', WP_SQLite_Lexer::TYPE_KEYWORD ) );
                    $r->add( new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ) );

                    $is_in_duplicate_section = true;
                    continue;
                }
            }

            if($token->type === WP_SQLite_Lexer::TYPE_OPERATOR) {
                $call_parent = $r->last_call_stack_element();
                // Rewrite commas to || in CONCAT() calls
                if( 
                    $call_parent 
                    && $call_parent[0] === 'CONCAT'
                    && ',' === $token->value 
                    && $token->flags & WP_SQLite_Lexer::FLAG_OPERATOR_SQL
                ) {
                    $r->replace_last(new Token('||', WP_SQLite_Lexer::TYPE_OPERATOR));
                    continue;
                }
            }

        }

        $updated_query = $r->getUpdatedQuery();
        $result = new SQLiteTranslationResult([]);

        // Naively emulate SQL_CALC_FOUND_ROWS for now
        if ( $has_SQL_CALC_FOUND_ROWS ) {
            // first strip the code. this is the end of rewriting process
            $query = str_ireplace('SQL_CALC_FOUND_ROWS', '', $updated_query);
            // we make the data for next SELECT FOUND_ROWS() statement
            $unlimited_query = preg_replace('/\\bLIMIT\\s*.*/imsx', '', $query);
            //$unlimited_query = preg_replace('/\\bGROUP\\s*BY\\s*.*/imsx', '', $unlimited_query);
            // we no longer use SELECT COUNT query
            //$unlimited_query = $this->_transform_to_count($unlimited_query);
            $stmt = $this->sqlite->query($unlimited_query);
            $result->calc_found_rows = count($stmt->fetchAll());
        }

        // Naively emulate FOUND_ROWS() by counting the rows in the result set
        if ( strpos( $updated_query, 'FOUND_ROWS(' ) !== false ) {
            $last_found_rows = ($this->last_found_rows ?: 0) . '';
            $result->queries[] = new SQLiteQuery(
                "SELECT {$last_found_rows} AS `FOUND_ROWS()`",
            );
            return $result;
        }

        // Now that functions are rewritten to SQLite dialect,
        // Let's translate unsupported delete queries
        if($query_type === 'DELETE') {
            $r = new QueryRewriter($r->output_tokens);
            $r->consume();

            $comma = $r->peek(WP_SQLite_Lexer::TYPE_OPERATOR, null, [',']);
            $from = $r->peek(WP_SQLite_Lexer::TYPE_KEYWORD, null, ['FROM']);
            // It's a dual delete query if the comma comes before the FROM
            if($comma && $from && $comma->position < $from->position) {
                $r->replace_last( new Token( 'SELECT', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ) );

                // Get table name. Clone $r because we need to know the table
                // name to correctly declare the select fields and the select
                // fields come before the FROM keyword.
                $r2 = clone $r;
                $r2->consume([
                    'type' => WP_SQLite_Lexer::TYPE_KEYWORD,
                    'value' => 'FROM'
                ]);
                // Assume the table name is the first token after FROM
                $table_name = $r2->consume()->value;
                unset($r2);

                // Now, let's figure out the primary key name
                // This assumes that all listed table names are the same.
                $q       = $this->sqlite->query( 'SELECT l.name FROM pragma_table_info("' . $table_name . '") as l WHERE l.pk = 1;' );
                $pk_name = $q->fetch()['name'];

                // Good, we can finally create the SELECT query.
                // Let's rewrite DELETE a, b FROM ... to SELECT a.id, b.id FROM ...
                $alias_nb = 0;
                while(true) {
                    $token = $r->consume();
                    if($token->type === WP_SQLite_Lexer::TYPE_KEYWORD && $token->value === 'FROM') {
                        break;
                    }
                    // Between DELETE and FROM we only expect commas and table aliases
                    // If it's not a comma, it must be a table alias
                    if($token->value !== ',') {
                        // Insert .id AS id_1 after the table alias
                        $r->addMany([
                            new Token( '.', WP_SQLite_Lexer::TYPE_OPERATOR, WP_SQLite_Lexer::FLAG_OPERATOR_SQL ),
                            new Token( $pk_name, WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_KEY ),
                            new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
                            new Token( 'AS', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ),
                            new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
                            new Token( 'id_'.$alias_nb, WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_KEY ),
                        ]);
                    }
                }
                $r->consume_all();

                // Select the IDs to delete
                $select = $r->getUpdatedQuery();
                $rows = $this->sqlite->query($select)->fetchAll();
                $ids_to_delete = [];
                foreach ($rows as $id) {
                    $ids_to_delete[] = $id->id_1;
                    $ids_to_delete[] = $id->id_2;
                }
                
                $query = (
                    count($ids_to_delete)
                        ? "DELETE FROM {$table_name} WHERE {$pk_name} IN (" . implode(',', $ids_to_delete) . ")"
                        : "SELECT 1=1"
                );
                return new SQLiteTranslationResult(
                    [
                        new SQLiteQuery( $query)
                    ]
                );
            }

            // Naive rewriting of DELETE JOIN query
            // @TODO: Use Lexer
            if ( str_contains( $this->query, ' JOIN ' ) ) {
                return new SQLiteTranslationResult(
                    [
                        new SQLiteQuery( 
                            "DELETE FROM {$this->table_prefix}options WHERE option_id IN (SELECT MIN(option_id) FROM {$this->table_prefix}options GROUP BY option_name HAVING COUNT(*) > 1)"
                        )
                    ]
                );
            }
        }

        $result->queries[] = new SQLiteQuery( $updated_query, $params );
        return $result;
    }

	private function translate_alter(QueryRewriter $r) {
        $r->consume();
		$subject = strtolower( $r->consume()->token );
		if ( 'table' !== $subject ) {
			throw new \Exception( 'Unknown subject: ' . $subject );
		}

		$table_name = strtolower( $r->consume()->token );
		$op_type    = strtolower( $r->consume()->token );
		$op_subject = strtolower( $r->consume()->token );
		if ( 'fulltext key' === $op_subject ) {
            return new SQLiteTranslationResult([$this->noop()]);
		}

		if ( 'add' === $op_type ) {
			if ( 'column' === $op_subject ) {
				$this->consume_data_types($r);
				$r->consume_all();
			} elseif ( 'key' === $op_subject || 'unique key' === $op_subject ) {
				$key_name        = $r->consume()->value;
				$index_prefix    = 'unique key' === $op_subject ? 'UNIQUE ' : '';
				$r->replace_all(
                    array(
                        new Token( 'CREATE', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ),
                        new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
                        new Token( "{$index_prefix}INDEX", WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ),
                        new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
                        new Token( "\"{$table_name}__$key_name\"", WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_KEY ),
                        new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
                        new Token( 'ON', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ),
                        new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
                        new Token( '"' . $table_name . '"', WP_SQLite_Lexer::TYPE_STRING, WP_SQLite_Lexer::FLAG_STRING_DOUBLE_QUOTES ),
                        new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
                        new Token( '(', WP_SQLite_Lexer::TYPE_OPERATOR ),
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
				while ( $r->consume( ['type' => WP_SQLite_Lexer::TYPE_SYMBOL] ) ) {
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
				$this->consume_data_types($r);
				$r->consume_all();
			} else {
				throw new \Exception( "Unknown operation: $op_type $op_subject" );
			}
		} elseif ( 'drop' === $op_type ) {
			if ( 'column' === $op_subject ) {
				$r->consume_all();
			} elseif ( 'key' === $op_subject ) {
				$key_name        = $r->consume()->value;
				$r->replace_all(
                    array(
                        new Token( 'DROP', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ),
                        new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
                        new Token( 'INDEX', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ),
                        new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
                        new Token( "\"{$table_name}__$key_name\"", WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_KEY ),
                    )
                );
			}
		} else {
			throw new \Exception( 'Unknown operation: ' . $op_type );
		}

        return new SQLiteTranslationResult([
            new SQLiteQuery(
                $r->getUpdatedQuery()
            )
        ]);
	}

    private function translate_create(QueryRewriter $r) {
        $r->consume();
        $what = $r->consume()->token;
        if ( 'TABLE' === $what ) {
            return $this->translate_create_table($r);
        } elseif ( 'PROCEDURE' === $what || 'DATABASE' === $what ) {
            return new SQLiteTranslationResult([$this->noop()]);
        } else {
            throw new \Exception( 'Unknown create type: ' . $what );
        }
    }

    private function translate_drop(QueryRewriter $r) {
        $r->consume();
        $what = $r->consume()->token;
        if ( 'TABLE' === $what ) {
            $r->consume_all();
            
            return new SQLiteTranslationResult([
                new SQLiteQuery(
                    $r->getUpdatedQuery()
                )
            ]);
        } elseif ( 'PROCEDURE' === $what || 'DATABASE' === $what ) {
            return new SQLiteTranslationResult([
                $this->noop()
            ]);
        } else {
            throw new \Exception( 'Unknown drop type: ' . $what );
        }
    }

    private function translate_show(QueryRewriter $r) {
        $r->skip();
        $what1 = $r->consume()->token;
        $what2 = $r->consume()->token;
        $what = $what1 . ' ' . $what2;
        switch($what) {
            case 'CREATE PROCEDURE':
                return new SQLiteTranslationResult([
                    $this->noop()
                ]);
            case 'FULL COLUMNS':
                $r->consume();
                $table_name = $r->consume()->token;
                return new SQLiteTranslationResult([
                    new SQLiteQuery(
                        "PRAGMA table_info($table_name);"
                    )
                ]);
            case 'INDEX FROM':
                $table_name = $r->consume()->token;
                return new SQLiteTranslationResult([
                    new SQLiteQuery(
                        "PRAGMA index_info($table_name);"
                    )
                ]);
            case 'TABLES LIKE':
                // @TODO implement filtering by table name
                $table_name = $r->consume()->token;
                return new SQLiteTranslationResult([
                    new SQLiteQuery(
                        '.tables;'
                    )
                ]);
            default:
                if($what1 === 'VARIABLE') {
                    return new SQLiteTranslationResult([
                        $this->noop()
                    ]);
                } else {
                    throw new \Exception( 'Unknown show type: ' . $what );
                }
        }
    }

    private function noop() {
        return new SQLiteQuery(
            'SELECT 1=1',
            []
        );
    }

	private function consume_data_types(QueryRewriter $r) {
		while ( $type = $r->consume([
			'type' => WP_SQLite_Lexer::TYPE_KEYWORD,
			'flags' => WP_SQLite_Lexer::FLAG_KEYWORD_DATA_TYPE
		]) ) {
			$typelc = strtolower( $type->value );
			if ( isset( $this->field_types_translation[ $typelc ] ) ) {
				$r->drop_last();
				$r->add(new Token(
					$this->field_types_translation[ $typelc ],
					$type->type,
					$type->flags
				));
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

class QueryRewriter {

	public $input_tokens = [];
	public $output_tokens = [];
	public $idx = -1;
	public $max = -1;
    public $call_stack = array();
    public $depth = 0;

    public function __construct($input_tokens)
    {
        $this->input_tokens = $input_tokens;
        $this->max = count($input_tokens);
    }

    public function getUpdatedQuery() {
        $query = '';
        foreach ( $this->output_tokens as $token ) {
            $query .= $token->token;
        }
        return $query;
    }

    public function current() {
        if( $this->idx < 0 || $this->idx >= $this->max ) {
            return null;
        }
        return $this->input_tokens[$this->idx];
    }

    public function add($token) {
        $this->output_tokens[] = $token;
    }

    public function addMany($tokens) {
        $this->output_tokens = array_merge($this->output_tokens, $tokens);
    }

    public function replace_last($token) {
        $this->drop_last();
        $this->output_tokens[] = $token;
    }

    public function replace_all($tokens) {
        $this->output_tokens = $tokens;
    }

	public function peek($type=null, $flags=null, $values=null) {
        $i = $this->idx;
		while ( ++$i < $this->max ) {
			$token = $this->input_tokens[ $i ];
            if($this->matches($token, $type, $flags, $values)){
                return $token;
            }
		}
	}

	public function consume_all() {
		while ( $this->consume() ) {
		}
	}

	public function consume( $options = [] ) {
        [$tokens, $last_matched] = $this->get_next_tokens($options);
        $count = count($tokens);
        $this->idx += $count;
        $this->output_tokens = array_merge($this->output_tokens, $tokens);
        if(!$count) {
            ++$this->idx;
        }
        return $last_matched ? $this->current() : null;
	}

	public function drop_last() {
		return array_pop( $this->output_tokens );
	}

	public function skip( $options = [] ) {
		$this->skipOver($options);
        return $this->current();
	}

	public function skipOver( $options = [] ) {
        [$tokens, $last_matched] = $this->get_next_tokens($options);
        $count = count($tokens);
        $this->idx += $count;
        if(!$count) {
            ++$this->idx;
        }
        return $last_matched ? $tokens : null;
	}

    private function get_next_tokens($options=[]) {
        $type  = isset( $options['type'] ) ? $options['type'] : null;
		$flags = isset( $options['flags'] ) ? $options['flags'] : null;
        $values = isset( $options['value'] )
            ? (is_array( $options['value'] ) ? $options['value'] : [ $options['value'] ] )
            : null;

        $buffered = [];
        $i = $this->idx;
		while ( ++$i < $this->max ) {
			$token = $this->input_tokens[ $i ];
            $this->update_call_stack($token, $i);
            $buffered[] = $token;
            if($this->matches($token, $type, $flags, $values)){
                return [$buffered, true];
            }
		}
        
        return [$buffered, false];
    }

    private function matches($token, $type=null, $flags=null, $values=null) {
        if ( null === $type && null === $flags && null === $values ) {
            if (
                WP_SQLite_Lexer::TYPE_WHITESPACE !== $token->type
                && WP_SQLite_Lexer::TYPE_COMMENT !== $token->type
            ) {
                return true;
            }
        } elseif (
            ( null === $type || $token->type === $type )
            && ( null === $flags || ($token->flags & $flags) )
            && ( null === $values || in_array($token->value, $values, true) )
        ) {
            return true;
        }

        return false;
    }

    public function last_call_stack_element() {
        return count( $this->call_stack ) ? $this->call_stack[ count( $this->call_stack ) - 1 ] : null;
    }

    private function update_call_stack($token, $current_idx) {
        if ( WP_SQLite_Lexer::TYPE_KEYWORD === $token->type ) {
            if (
                $token->flags & WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION
                // && ! ( $token->flags & WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED )
            ) {
                $j = $current_idx;
                do {
                    $peek = $this->input_tokens[ ++$j ];
                } while ( WP_SQLite_Lexer::TYPE_WHITESPACE === $peek->type );
                if ( WP_SQLite_Lexer::TYPE_OPERATOR === $peek->type && '(' === $peek->value ) {
                    array_push( $this->call_stack, array( $token->value, $this->depth ) );
                }
            }
        } elseif ( $token->type === WP_SQLite_Lexer::TYPE_OPERATOR ) {
            if ( '(' === $token->value ) {
                ++$this->depth;
            } elseif ( ')' === $token->value ) {
                --$this->depth;
                $call_parent = $this->last_call_stack_element();
                if (
                    $call_parent &&
                    $call_parent[1] === $this->depth
                ) {
                    array_pop( $this->call_stack );
                }
            }
        }
    }


}

// $t = new SQLiteTranslator(<<<'Q'
// INSERT IGNORE 
//     INTO `wptests_options` (`ID`, `display_name`) 
//     VALUES (
//         217,
//         'Walter Replace Sobchak',
//         YEAR('ds'),
//         DATE_ADD(
//             '2018-01-01',
//             INTERVAL 1 YEAR,
//             DATE_SUB(
//                 '2018-01-01',
//                 INTERVAL 1 YEAR,
//             ),
//             INTERVAL 1 YEAR,
//         ),
//         DATE_FORMAT( `post_date`, '%Y-%m-%d' ) AS `yyyymmdd`,
//     ) 
//     ON DUPLICATE KEY SET 
//     a = VALUES(`b`)
// Q);

// $pdo = new PDO('sqlite::memory:');
// $t = new SQLiteTranslator($pdo);
// foreach($t->translate("
// CREATE TABLE wp_options (
// 	option_id bigint(20) unsigned NOT NULL auto_increment,
// 	option_name varchar(191) NOT NULL default '',
// 	option_value longtext NOT NULL,
// 	autoload varchar(20) NOT NULL default 'yes',
// 	PRIMARY KEY  (option_id),
// 	UNIQUE KEY option_name (option_name),
// 	KEY autoload (autoload)
// ) ;")->queries as $q) {
//     $pdo->prepare($q->sql)->execute($q->params);
// }
// $pdo->query('INSERT INTO wp_options (option_name, option_value) VALUES ("_site_transient_timeout_test", 1675966307)');
// var_dump($pdo->lastInsertId());
// $t->translate(
//     "DELETE a, b FROM wp_options a, wp_options b
//     WHERE a.option_name LIKE '_site_transient_%'
//     AND a.option_name NOT LIKE '_site_transient_timeout_%'
//     AND b.option_name = CONCAT( '_site_transient_timeout_', SUBSTRING( a.option_name, CONCAT( '_site_transient_timeout_', 2 ) ) )
//     AND b.option_value < 1675966307"
// );
// die();
