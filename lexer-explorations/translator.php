<?php

// require autoload

use PhpMyAdmin\SqlParser\Context;
use PhpMyAdmin\SqlParser\Token;

// Assumes the PhpMyAdmin Sql Parser is installed via Composer
require_once __DIR__ . '/sql-parser/vendor/autoload.php';
require_once __DIR__ . '/class-wp-sqlite-lexer.php';

function err_handle( $err_no, $err_str, $err_file, $err_line ) {
	$msg = "$err_str in $err_file on line $err_line";
	if ( E_NOTICE === $err_no || E_WARNING === $err_no ) {
		throw new ErrorException( $msg, $err_no );
	} else {
		echo $msg;
	}
}

set_error_handler( 'err_handle' );

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

// $sqlite = new PDO('sqlite::memory:');
$sqlite = new PDO( 'sqlite:./testdb' );
$sqlite->query( 'PRAGMA encoding="UTF-8";' );

class Translator {

	private $tokens;
	private $outtokens;
	private $idx = -1;
	private $count;
	private $query;
	private $last_found_rows;
    private $table_prefix = 'wptests_';

    // @TODO Check capability – SQLite must have a regexp function available
    private $has_regexp = false;

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

	public function __construct( string $query ) {
		$this->query  = $query;
		$this->tokens = \PhpMyAdmin\SqlParser\Lexer::getTokens( $query )->tokens;
		$this->count  = count( $this->tokens );
	}

	function translate() {
		$query_type = $this->consume();
		if ( 'CREATE' === $query_type->value ) {
			return $this->translate_create_table();
		}

        if (
            'SELECT' === $query_type->value ||
            'INSERT' === $query_type->value ||
            'UPDATE' === $query_type->value ||
            'DELETE' === $query_type->value
        ) {
            return $this->translate_crud();
        }

		if ( 'ALTER' === $query_type->value ) {
			return $this->translate_alter();
		}
		if ( 'CALL' === $query_type->value || 'SET' === $query_type->value || 'CREATE' === $query_type->value ) {
			// It would be lovely to support at least SET autocommit
			// but I don't think even that is possible with SQLite
			return 'SELECT 1=1;';
		}
		if ( 'START TRANSACTION' === $query_type->value || 'BEGIN' === $query_type->value || 'COMMIT' === $query_type->value || 'ROLLBACK' === $query_type->value ) {
			return $query_type->value;
		}
		if ( 'TRUNCATE' === $query_type->value ) {
			return $query_type->value;
		}
		if ( 'DROP' === $query_type->value ) {
			$what = $this->consume()->token;
			if ( 'TABLE' === $what ) {
				$this->consume_all();
			} elseif ( 'PROCEDURE' === $what || 'DATABASE' === $what ) {
				return 'SELECT 1=1;';
			} else {
			    throw new \Exception( 'Unknown drop type: ' . $what );
            }
		} elseif ( 'REPLACE' === $query_type->value ) {
			array_unshift(
				$this->outtokens,
				new \PhpMyAdmin\SqlParser\Token( 'INSERT', $query_type->type, $query_type->flags ),
				new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
				new \PhpMyAdmin\SqlParser\Token( 'OR', $query_type->type, $query_type->flags ),
				new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
			);

			$this->consume_all();
			// @TODO: Process the rest as INSERT
		} elseif ( 'DESCRIBE' === $query_type->value ) {
			$table_name = $this->consume()->token;
			return "PRAGMA table_info($table_name);";
		} else if ( 'SHOW' === $query_type->value ) {
			$what1 = $this->consume()->token;
			$what2 = $this->consume()->token;
			if ( 'CREATE' === $what1 && 'PROCEDURE' === $what2 ) {
				return 'SELECT 1=1;';
            } else if($what1 === 'FULL' && $what2 === 'COLUMNS') {
                $this->consume();
                $table_name = $this->consume()->token;
                return "PRAGMA table_info($table_name);";
            } else if($what1 === 'INDEX' && $what2 === 'FROM') {
                $table_name = $this->consume()->token;
                return "PRAGMA index_info($table_name);";
            } else if($what1 === 'INDEXES' && $what2 === 'FROM') {
                // @TODO implement filtering by key_name
                $table_name = $this->consume()->token;
                return "PRAGMA index_info($table_name);";
            } else if($what1 === 'TABLES' && $what2 === 'LIKE') {
                // @TODO implement filtering by table name
                $table_name = $this->consume()->token;
                return ".tables;";
            } else if($what1 === 'VARIABLE') {
                return "SELECT 1=1";
            } else {
                throw new \Exception( 'Unknown query type: ' . $query_type->value );
            }
        } else {
            throw new \Exception( 'Unknown query type: ' . $query_type->value );
        }

		$query = '';
		foreach ( $this->outtokens as $idx => $token ) {
			$query .= $token->token;
		}
		return $query;
	}

    function translate_create_table() {
        echo '**MySQL query:**' . PHP_EOL;
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
        $updated_query = $stmt->build();
        return array( $updated_query, $extra_queries );
    }

    function translate_crud() {
        global $sqlite;

        $query = $this->query;
        $tokens = \PhpMyAdmin\SqlParser\Lexer::getTokens( $query );
    
        $token      = $tokens->getNext();
        $query_type = $token->value;
    
        // Very naive check to see if we're dealing with an information_schema
        // query. If so, we'll just return a dummy result.
        // @TODO: A proper check and a better translation.
        if ( str_contains( $this->query, 'information_schema' ) ) {
            return 'SELECT \'\' as "table", 0 as "rows", 0 as "bytes';
        }

        // Again, a naive rewriting for dual-table DELETEs 
        // MySQL Supports deleting from multiple tables in one query.
        // In SQLite, we need to SELECT first and then DELETE with
        // the primary keys found by the SELECT.
        if ( str_contains( $this->query, 'DELETE a, b' ) ) {
            $time = time();
            $rows = $sqlite
                ->query("SELECT a.meta_id AS aid, b.meta_id AS bid FROM {$this->table_prefix}sitemeta AS a INNER JOIN {$this->table_prefix}sitemeta AS b ON a.meta_key='_site_transient_timeout_'||substr(b.meta_key, 17) WHERE b.meta_key='_site_transient_'||substr(a.meta_key, 25) AND a.meta_value < $time")
                ->fetchAll();
            foreach ($rows as $id) {
                $ids_to_delete[] = $id->aid;
                $ids_to_delete[] = $id->bid;
            }
            $rewritten = "DELETE FROM {$this->table_prefix}sitemeta WHERE meta_id IN (" . implode(',', $ids_to_delete) . ")";
            return $rewritten;
        }

        if ( str_contains( $this->query, 'DELETE ' )
            && str_contains( $this->query, ' JOIN ' ) ) {
            return "DELETE FROM {$this->table_prefix}options WHERE option_id IN (SELECT MIN(option_id) FROM {$this->table_prefix}options GROUP BY option_name HAVING COUNT(*) > 1)";
        }
        
        // Naive regexp check
        if(!$this->has_regexp && strpos( $query, ' REGEXP ' ) !== false) {
            // Bale out if we can't run the query
            return "SELECT 1=1;";
        }
        
        if (
            // @TODO: Add handling for the following cases:
            strpos( $query, 'ORDER BY FIELD' ) !== false
            || strpos( $query, '@@SESSION.sql_mode' ) !== false
            // `CONVERT( field USING charset )` is not supported
            || strpos( $query, 'CONVERT( ' ) !== false
        ) {
            die($this->query);
            // Return a dummy select for now
            return 'SELECT 1=1';
        }
        /*
@TODO rewrite ORDER BY FIELD(a, 1,4,6) as
ORDER BY CASE a
    WHEN 1 THEN 0
    WHEN 4 THEN 1
    WHEN 6 THEN 2
  END
        */
    
        echo '**MySQL query:**' . PHP_EOL;
        echo $query . PHP_EOL . PHP_EOL;
    
        $lexer                   = new \PhpMyAdmin\SqlParser\Lexer( $query );
        $list                    = $lexer->list;
        $newlist                 = new PhpMyAdmin\SqlParser\TokensList();
        $call_stack              = array();
        $paren_nesting           = 0;
        $params                  = array();
        $is_in_duplicate_section = false;
        $table_name              = null;
        for ( $i = 0;$i < $list->count;$i++ ) {
            $token                   = $list[ $i ];
            $current_call_stack_elem = count( $call_stack ) ? $call_stack[ count( $call_stack ) - 1 ] : null;
    
            // Capture table name
            if ( 'INSERT' === $query_type ) {
                if ( ! $table_name && WP_SQLite_Lexer::TYPE_KEYWORD === $token->type && 'INTO' === $token->value ) {
                    // Get the next non-whitespace token and assume it's the table name
                    $j = $i + 1;
                    while ( WP_SQLite_Lexer::TYPE_WHITESPACE === $list[ $j ]->type ) {
                        $j++;
                    }
                    $table_name = $list[ $j ]->value;
                } elseif ( WP_SQLite_Lexer::TYPE_KEYWORD === $token->type && 'IGNORE' === $token->value ) {
                    $newlist->add( new Token( 'OR', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ) );
                    $newlist->add( new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ) );
                    $newlist->add( new Token( 'IGNORE', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ) );
                    goto process_nesting;
                }
            }
            if ( WP_SQLite_Lexer::TYPE_STRING === $token->type && $token->flags & WP_SQLite_Lexer::FLAG_STRING_SINGLE_QUOTES ) {
                $param_name            = ':param' . count( $params );
                $params[ $param_name ] = $token->value;
                // Rewrite backslash-escaped single quotes to
                // doubly-escaped single quotes. The stripslashes()
                // part is fairly naive and needs to be improved.
                // $sqlite_value = SQLite3::escapeString(stripslashes($token->value));
                // $newlist->add(new Token("'$sqlite_value'", WP_SQLite_Lexer::TYPE_STRING, WP_SQLite_Lexer::FLAG_STRING_SINGLE_QUOTES));
                $newlist->add( new Token( $param_name, WP_SQLite_Lexer::TYPE_STRING, WP_SQLite_Lexer::FLAG_STRING_SINGLE_QUOTES ) );
                goto process_nesting;
            } elseif ( WP_SQLite_Lexer::TYPE_KEYWORD === $token->type ) {
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
                        $newlist->add( new Token( 'STRFTIME', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION ) );
                        $newlist->add( new Token( '(', WP_SQLite_Lexer::TYPE_OPERATOR ) );
    
                        if ( 'WEEK' === $unit ) {
                            // Peek to check the "mode" argument
                            // For now naively assume the mode is either
                            // specified after the first "," or defaults to
                            // 0 if ")" is found first
                            $j = $i;
                            do {
                                $peek = $list[ ++$j ];
                            } while (
                                ! (
                                    WP_SQLite_Lexer::TYPE_OPERATOR === $peek->type &&
                                    (
                                        ')' === $peek->value ||
                                        ',' === $peek->value
                                    )
                                )
                            );
                            if ( ',' === $peek->value ) {
                                $comma_idx = $j;
                                do {
                                    $peek = $list[ ++$j ];
                                } while ( WP_SQLite_Lexer::TYPE_WHITESPACE === $peek->type );
                                // Assume $peek is now a number
                                if ( 0 === $peek->value ) {
                                    $format = '%U';
                                } elseif ( 1 === $peek->value ) {
                                    $format = '%W';
                                } else {
                                    throw new Exception( 'Could not parse the WEEK() mode' );
                                }
    
                                $mode_idx = $j;
                                // Drop the comma and the mode from tokens list
                                unset( $list[ $mode_idx ] );
                                unset( $list[ $comma_idx ] );
                            } else {
                                $format = '%W';
                            }
                        }
    
                        $newlist->add( new Token( "'$format'", WP_SQLite_Lexer::TYPE_STRING ) );
                        $newlist->add( new Token( ',', WP_SQLite_Lexer::TYPE_OPERATOR ) );
                        // Skip over the next "(" token
                        do {
                            $peek = $list[ ++$i ];
                        } while (
                            WP_SQLite_Lexer::TYPE_OPERATOR !== $peek->type &&
                            '(' !== $peek->value
                        );
                        goto process_nesting;
                    }
                }
                if ( 'RAND' === $token->keyword && $token->flags & WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION ) {
                    $newlist->add( new Token( 'RANDOM', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION ) );
                    goto process_nesting;
                } elseif (
                    $token->flags & WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION && (
                        'DATE_ADD' === $token->keyword ||
                        'DATE_SUB' === $token->keyword
                    )
                ) {
                    $newlist->add( new Token( 'DATE', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION ) );
                    goto process_nesting;
                } elseif (
                    $token->flags & WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION &&
                    'VALUES' === $token->keyword &&
                    $is_in_duplicate_section
                ) {
                    /*
                    Rewrite:
                        VALUES(`option_name`)
                    to:
                        excluded.option_name
                    Need to know the primary key
                    */
                    $newlist->add( new Token( 'excluded', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_KEY ) );
                    $newlist->add( new Token( '.', WP_SQLite_Lexer::TYPE_OPERATOR ) );
                    // Naively remove the next ( and )
                    $j = $i;
                    while ( true ) {
                        $peek = $list[ ++$j ];
                        if ( WP_SQLite_Lexer::TYPE_OPERATOR === $peek->type && '(' === $peek->value ) {
                            unset( $list[ $j ] );
                            break;
                        }
                    }
                    while ( true ) {
                        $peek = $list[ ++$j ];
                        if ( WP_SQLite_Lexer::TYPE_OPERATOR === $peek->type && ')' === $peek->value ) {
                            unset( $list[ $j ] );
                            break;
                        }
                    }
    
                    goto process_nesting;
                } elseif (
                    $token->flags & WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION &&
                    'DATE_FORMAT' === $token->keyword
                ) {
                    $newlist->add( new Token( 'STRFTIME', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION ) );
                    $newlist->add( new Token( '(', WP_SQLite_Lexer::TYPE_OPERATOR ) );
                    $j = $i;
                    while ( true ) {
                        $peek = $list[ ++$j ];
                        if ( WP_SQLite_Lexer::TYPE_OPERATOR === $peek->type && '(' === $peek->value ) {
                            unset( $list[ $j ] );
                            break;
                        }
                    }
    
                    // Peek to check the "format" argument
                    // For now naively assume the format is
                    // the first string value inside the DATE_FORMAT call
                    while ( true ) {
                        $peek = $list[ ++$j ];
                        if ( 'WP_SQLite_Lexer::TYPE_OPERATOR' === $peek->type && ',' === $peek->value ) {
                            unset( $list[ $j ] );
                            break;
                        }
                    }
    
                    // Rewrite the format argument:
                    while ( true ) {
                        $peek = $list[ ++$j ];
                        if ( WP_SQLite_Lexer::TYPE_STRING === $peek->type ) {
                            unset( $list[ $j ] );
                            break;
                        }
                    }
    
                    $new_format = strtr( $peek->value, $this->mysql_php_date_formats );
                    $newlist->add( new Token( "'$new_format'", WP_SQLite_Lexer::TYPE_STRING ) );
                    $newlist->add( new Token( ',', WP_SQLite_Lexer::TYPE_OPERATOR ) );
    
                    goto process_nesting;
                } elseif ( 'INTERVAL' === $token->keyword ) {
                    $list->idx       = $i + 1;
                    $num             = $list->getNext()->value;
                    $unit            = $list->getNext()->value;
                    $i               = $list->idx - 1;
    
                    // Add or subtract the interval value depending on the
                    // date_* function closest in the stack
                    $interval_op = '+'; // Default to adding
                    for ( $j = count( $call_stack ) - 1;$i >= 0;$i-- ) {
                        $call = $call_stack[ $j ];
                        if ( 'DATE_ADD' === $call[0] ) {
                            $interval_op = '+';
                            break;
                        }
                        if ( 'DATE_SUB' === $call[0] ) {
                            $interval_op = '-';
                            break;
                        }
                    }
    
                    $newlist->add( new Token( "'{$interval_op}$num $unit'", WP_SQLite_Lexer::TYPE_STRING ) );
                    goto process_nesting;
                } elseif ( 'INSERT' === $query_type && 'DUPLICATE' === $token->keyword ) {
                    /*
                    Rewrite:
                        ON DUPLICATE KEY UPDATE `option_name` = VALUES(`option_name`)
                    to:
                        ON CONFLICT(ip) DO UPDATE SET option_name = excluded.option_name
                    Need to know the primary key
                    */
                    $newlist->add( new Token( 'CONFLICT', WP_SQLite_Lexer::TYPE_KEYWORD ) );
                    $newlist->add( new Token( '(', WP_SQLite_Lexer::TYPE_OPERATOR ) );
                    // @TODO don't make assumptions about the names, only fetch
                    //       the correct unique key from sqlite
                    if ( str_ends_with( $table_name, '_options' ) ) {
                        $pk_name = 'option_name';
                    } elseif ( str_ends_with( $table_name, '_term_relationships' ) ) {
                        $pk_name = 'object_id, term_taxonomy_id';
                    } else {
                        $q       = $sqlite->query( 'SELECT l.name FROM pragma_table_info("' . $table_name . '") as l WHERE l.pk = 1;' );
                        $pk_name = $q->fetch()['name'];
                    }
                    $newlist->add( new Token( $pk_name, WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_KEY ) );
                    $newlist->add( new Token( ')', WP_SQLite_Lexer::TYPE_OPERATOR ) );
                    $newlist->add( new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ) );
                    $newlist->add( new Token( 'DO', WP_SQLite_Lexer::TYPE_KEYWORD ) );
                    $newlist->add( new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ) );
                    $newlist->add( new Token( 'UPDATE', WP_SQLite_Lexer::TYPE_KEYWORD ) );
                    $newlist->add( new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ) );
                    $newlist->add( new Token( 'SET', WP_SQLite_Lexer::TYPE_KEYWORD ) );
                    $newlist->add( new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ) );
                    // Naively remove the next "KEY" and "UPDATE" keywords from
                    // the original token stream
                    $j = $i;
                    while ( true ) {
                        $peek = $list[ ++$j ];
                        if ( WP_SQLite_Lexer::TYPE_KEYWORD === $peek->type && 'KEY' === $peek->keyword ) {
                            unset( $list[ $j ] );
                            break;
                        }
                    }
                    while ( true ) {
                        $peek = $list[ ++$j ];
                        if ( WP_SQLite_Lexer::TYPE_KEYWORD === $peek->type && 'UPDATE' === $peek->keyword ) {
                            unset( $list[ $j ] );
                            break;
                        }
                    }
                    $is_in_duplicate_section = true;
                    goto process_nesting;
                }
            }
    
            $newlist->add( $token );
    
            process_nesting:
            if ( WP_SQLite_Lexer::TYPE_KEYWORD === $token->type ) {
                if (
                    $token->flags & WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION
                    && ! ( $token->flags & WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED )
                ) {
                    $j = $i;
                    do {
                        $peek = $list[ ++$j ];
                    } while ( WP_SQLite_Lexer::TYPE_WHITESPACE === $peek->type );
                    if ( WP_SQLite_Lexer::TYPE_OPERATOR === $peek->type && '(' === $peek->value ) {
                        array_push( $call_stack, array( $token->value, $paren_nesting ) );
                    }
                }
            } elseif ( $token->type === WP_SQLite_Lexer::TYPE_OPERATOR ) {
                if ( '(' === $token->value ) {
                    ++$paren_nesting;
                } elseif ( ')' === $token->value ) {
                    --$paren_nesting;
                    if (
                        $current_call_stack_elem &&
                        $current_call_stack_elem[1] === $paren_nesting
                    ) {
                        array_pop( $call_stack );
                    }
                }
            }
        }
    
        /**
         * Parser gets derailed by queries like:
         * * SELECT * FROM table LIMIT 0,1
         * * SELECT 'a' LIKE '%';
         * Let's try using raw tokens instead.
         */
        // $p = new \PhpMyAdmin\SqlParser\Parser($newlist);
        // $stmt = $p->statements[0];
        //
        // if($stmt->options && $stmt->options->options){
        //     unset($stmt->options->options[SelectStatement::$OPTIONS['SQL_CALC_FOUND_ROWS']]);
        // }
        //
        // Context::setMode(Context::SQL_MODE_ANSI);
        // $updated_query = $stmt->build();
    
        $updated_query = '';
        foreach ( $newlist->tokens as $token ) {
            $updated_query .= $token->token;
        }

        // Naively emulate SQL_CALC_FOUND_ROWS for now
        if ( strpos( $updated_query, 'SQL_CALC_FOUND_ROWS' ) !== false ) {
            // first strip the code. this is the end of rewriting process
            $query = str_ireplace('SQL_CALC_FOUND_ROWS', '', $updated_query);
            // we make the data for next SELECE FOUND_ROWS() statement
            $unlimited_query = preg_replace('/\\bLIMIT\\s*.*/imsx', '', $query);
            //$unlimited_query = preg_replace('/\\bGROUP\\s*BY\\s*.*/imsx', '', $unlimited_query);
            // we no longer use SELECT COUNT query
            //$unlimited_query = $this->_transform_to_count($unlimited_query);
            $stmt = $sqlite->query($unlimited_query);
            $this->last_found_rows = $stmt->rowCount();
            return $query;
        }

        // Emulate FOUND_ROWS() by counting the rows in the result set
        if ( strpos( $updated_query, 'FOUND_ROWS' ) !== false ) {
            $result = ['FOUND_ROWS()' => $this->last_found_rows];
            return $result;
        }

        $extra_queries = array();
        echo '**SQLite queries:**' . PHP_EOL;
        try {
            $stmt = $sqlite->prepare( $updated_query );
            foreach ( $params as $name => $value ) {
                $stmt->bindValue( $name, $value );
            }
            $stmt->execute();
        } catch ( \Exception $e ) {
            if ( strpos( $e->getMessage(), 'UNIQUE constraint failed:' ) === false ) {
                throw $e;
            }
        } finally {
            echo PHP_EOL . PHP_EOL . $updated_query . PHP_EOL . PHP_EOL . PHP_EOL;
        }
        // foreach($extra_queries as $query) {
        //     $query = str_replace(' ID ',  '"ID" ', $query);
        //     try {
        //         $sqlite->exec($query);
        //     } catch(\Exception $e) {
        //         print_r($stmt);
        //         throw $e;
        //     } finally {
        //         echo $query . PHP_EOL . PHP_EOL;
        //     }
        // }
    }

	function translate_alter() {
		$subject = strtolower( $this->consume()->token );
		if ( 'table' !== $subject ) {
			throw new \Exception( 'Unknown subject: ' . $subject );
		}

		$table_name = strtolower( $this->consume()->token );
		$op_type    = strtolower( $this->consume()->token );
		$op_subject = strtolower( $this->consume()->token );
		if ( 'fulltext key' === $op_subject ) {
			echo 'Skipping fulltext' . PHP_EOL;
			return 'SELECT 1=1;';
		}

		if ( 'add' === $op_type ) {
			if ( 'column' === $op_subject ) {
				$this->consume_data_types();
				$this->consume_all();
			} elseif ( 'key' === $op_subject || 'unique key' === $op_subject ) {
				$key_name        = $this->consume()->value;
				$index_prefix    = 'unique key' === $op_subject ? 'UNIQUE' : '';
				$this->outtokens = array(
					new Token( 'CREATE', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ),
					new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
					new Token( "$index_prefix INDEX", WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ),
					new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
					new Token( "\"{$table_name}__$key_name\"", WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_KEY ),
					new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
					new Token( 'ON', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ),
					new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
					new Token( '"' . $table_name . '"', WP_SQLite_Lexer::TYPE_STRING, WP_SQLite_Lexer::FLAG_STRING_DOUBLE_QUOTES ),
					new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
					new Token( '(', WP_SQLite_Lexer::TYPE_OPERATOR ),
				);

				while ( $token = $this->consume() ) {
					if ( '(' === $token->token ) {
						$this->drop_last();
						break;
					}
				}

				// Consume all the fields, skip the sizes like `(20)`
				// in `varchar(20)`
				while ( $this->consume( WP_SQLite_Lexer::TYPE_SYMBOL ) ) {
					$paren_maybe = $this->peek();

					if ( $paren_maybe && '(' === $paren_maybe->token ) {
						$this->skip();
						$this->skip();
						$this->skip();
					}
				}

				$this->consume_all();
			} else {
				throw new \Exception( "Unknown operation: $op_type $op_subject" );
			}
		} elseif ( 'change' === $op_type ) {
			if ( 'column' === $op_subject ) {
				$this->consume_data_types();
				$this->consume_all();
			} else {
				throw new \Exception( "Unknown operation: $op_type $op_subject" );
			}
		} elseif ( 'drop' === $op_type ) {
			if ( 'column' === $op_subject ) {
				$this->consume_all();
			} elseif ( 'key' === $op_subject ) {
				$key_name        = $this->consume()->value;
				$this->outtokens = array(
					new Token( 'DROP', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ),
					new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
					new Token( 'INDEX', WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED ),
					new Token( ' ', WP_SQLite_Lexer::TYPE_WHITESPACE ),
					new Token( "\"{$table_name}__$key_name\"", WP_SQLite_Lexer::TYPE_KEYWORD, WP_SQLite_Lexer::FLAG_KEYWORD_KEY ),
				);
			}
		} else {
			throw new \Exception( 'Unknown operation: ' . $op_type );
		}
	}

	private function consume_data_types() {
		while ( $type = $this->consume(
			WP_SQLite_Lexer::TYPE_KEYWORD,
			WP_SQLite_Lexer::FLAG_KEYWORD_DATA_TYPE
		) ) {
			$typelc = strtolower( $type->value );
			if ( isset( $this->field_types_translation[ $typelc ] ) ) {
				$this->drop_last();
				$this->outtokens[] = new Token(
					$this->field_types_translation[ $typelc ],
					$type->type,
					$type->flags
				);
			}

			$paren_maybe = $this->peek();
			if ( $paren_maybe && '(' === $paren_maybe->token ) {
				$this->skip();
				$this->skip();
				$this->skip();
			}
		}
	}

	private function peek() {
		return isset( $this->tokens[ $this->idx + 1 ] ) ? $this->tokens[ $this->idx + 1 ] : null;
	}

	private function consume_all() {
		while ( $this->consume() ) {
		}
	}

	private function consume( $type = null, $flags = null ) {
		return $this->next(
			array(
				'consume' => true,
				'type'    => $type,
				'flags'   => $flags,
			)
		);
	}

	private function drop_last() {
		return array_pop( $this->outtokens );
	}

	private function skip() {
		$this->next( array( 'consume' => false ) );
	}

	private function next( $options = array(
		'consume' => true,
		'type'    => null,
		'flags'   => null,
	) ) {
		$type  = isset( $options['type'] ) ? $options['type'] : null;
		$flags = isset( $options['flags'] ) ? $options['flags'] : null;
		while ( ++$this->idx < $this->count ) {
			$token = $this->tokens[ $this->idx ];
			if ( isset( $options['consume'] ) && $options['consume'] ) {
				$this->outtokens[] = $token;
			}
			if ( null === $type && null === $flags ) {
				if (
					WP_SQLite_Lexer::TYPE_WHITESPACE !== $token->type
					&& WP_SQLite_Lexer::TYPE_COMMENT !== $token->type
				) {
					return $token;
				}
			} elseif (
				( null === $type || $token->type === $type )
				&& ( null === $flags || $token->type === $type )
			) {
				return $token;
			}
		}

		return null;
	}

}

$min = (int) file_get_contents( './last-select.txt' );
foreach ( queries() as $k => $query ) {
    if( $k < $min ) {
        continue;
    }
    try {
        $t = new Translator( $query );
        print_r(
            $t->translate()
        );
    } catch( \Exception $e ) {
        if(str_contains($e->getMessage(), 'no such table: wptests_sitemeta')) {
            echo $e->getMessage() . PHP_EOL;
        } else {
            throw $e;
        }
    }

    file_put_contents( './last-select.txt', $k );
    echo '--------------------' . PHP_EOL . PHP_EOL;
}

die();


