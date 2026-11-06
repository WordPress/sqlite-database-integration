<?php

/**
 * This script scans MySQL server test files, extracts SQL queries from them,
 * and saves them as CSV under "data/mysql-server-query-corpus".
 *
 * The test files first need to be downloaded from the MySQL server repository.
 * See the "fetch-mysql-tests.sh" script for more details.
 *
 * USAGE:
 *   php tools/generate-query-corpus.php
 *
 * The tests are written using The MySQL Test Framework:
 *   https://dev.mysql.com/doc/dev/mysql-server/latest/PAGE_MYSQL_TEST_RUN.html
 *
 * See also the mysqltest Language Reference:
 *   https://dev.mysql.com/doc/dev/mysql-server/latest/PAGE_MYSQLTEST_LANGUAGE_REFERENCE.html
 *
 * At the moment, it extracts test queries from the main MySQL server tests directory
 * under "mysql-test/t". We can extend it to include other directories as well.
 *
 * The queries are saved in UTF-8. Each line of a test file is read in the character
 * set of the client that the test selects, as "query-corpus-decoding.php" explains.
 *
 * A query that runs in SQL modes that affect the lexer has them in a second column,
 * separated by commas. A query that runs in several sets of modes has a row for each.
 *
 * A query that the test files only ever expect to fail with a syntax error has
 * "syntax_error" in a third column, and so do the few queries that a list below names.
 * Other queries that are expected to fail are regular rows, because their errors normally
 * come after parsing, or from somewhere other than the query's own syntax, such as the
 * text of a PREPARE statement.
 *
 * The script is written on a best-effort basis and may not cover all edge cases.
 * In the future, we may consider writing a mysqltest format parser.
 */

require_once __DIR__ . '/class-query-corpus-sql-modes.php';
require_once __DIR__ . '/query-corpus-decoding.php';

// Paths:
$data_dir   = __DIR__ . '/../data/mysql-server-query-corpus';
$query_file = $data_dir . '/mysql-latest.csv';

// Comments and other prefixes to skip:
$prefixes = array(
	'#',
	'--',
	'{',
	'}',
);

// List of mysqltest and MySQL client commands:
$commands = array(
	'append_file',
	'assert',
	'cat_file',
	'change_user',
	'character_set',
	'chmod',
	'connect',
	'connection',
	'let',
	'copy_file',
	'copy_files_wildcard',
	'dec',
	'delimiter',
	'die',
	'diff_files',
	'dirty_close',
	'disable_abort_on_error',
	'enable_abort_on_error',
	'disable_async_client',
	'enable_async_client',
	'disable_connect_log',
	'enable_connect_log',
	'disable_info',
	'enable_info',
	'disable_metadata',
	'enable_metadata',
	'disable_ps_protocol',
	'enable_ps_protocol',
	'disable_query_log',
	'enable_query_log',
	'disable_reconnect',
	'enable_reconnect',
	'disable_result_log',
	'enable_result_log',
	'disable_rpl_parse',
	'enable_rpl_parse',
	'disable_session_track_info',
	'enable_session_track_info',
	'disable_testcase',
	'enable_testcase',
	'disable_warnings',
	'enable_warnings',
	'disconnect',
	'echo',
	'end',
	'end_timer',
	'error',
	'eval',
	'exec',
	'exec_in_background',
	'execw',
	'exit',
	'expr',
	'file_exists',
	'force-cpdir',
	'force-rmdir',
	'horizontal_results',
	'if',
	'inc',
	'let',
	'mkdir',
	'list_files',
	'list_files_append_file',
	'list_files_write_file',
	'lowercase_result',
	'move_file',
	'output',
	'perl',
	'ping',
	'query',
	'query_attributes',
	'query_get_value',
	'query_horizontal',
	'query_vertical',
	'reap',
	'remove_file',
	'remove_files_wildcard',
	'replace_column',
	'replace_numeric_round',
	'replace_regex',
	'replace_result',
	'reset_connection',
	'result_format',
	'rmdir',
	'save_master_pos',
	'send',
	'send_eval',
	'send_quit',
	'send_shutdown',
	'shutdown_server',
	'skip',
	'sleep',
	'sorted_result',
	'partially_sorted_result',
	'source',
	'start_timer',
	'sync_slave_with_master',
	'sync_with_master',
	'vertical_results',
	'wait_for_slave_to_stop',
	'while',
	'write_file',
);

// Build regex patterns to skip mysqltest-specific constructs:
$prefixes_pattern =
	'('
	. implode(
		'|',
		array_map(
			function ( $prefix ) {
				return preg_quote( $prefix, '/' );
			},
			$prefixes
		)
	)
	. ')';

$commands_pattern =
	'('
	. implode(
		'|',
		array_map(
			function ( $command ) {
				return preg_quote( $command, '/' );
			},
			$commands
		)
	)
	. ')(\s+|\(|$)';

$skip_pattern = "/^($prefixes_pattern|$commands_pattern)/i";

// Scan MySQL test files for SQL queries:
$tests_dir = __DIR__ . '/../build/mysql-tests/mysql-test/t';
if ( ! is_dir( $tests_dir ) ) {
	echo "Directory '$tests_dir' not found. Please, run 'fetch-mysql-tests.sh' first.\n";
	exit( 1 );
}

// mysqltest connects in the default character set of the test suite.
$client_config    = $tests_dir . '/../include/default_client.cnf';
$default_encoding = QUERY_CORPUS_CHARSET_ENCODINGS[ read_default_client_charset( file_get_contents( $client_config ) ) ?? '' ] ?? null;
if ( null === $default_encoding ) {
	fwrite( STDERR, "error: no known default character set in $client_config.\n" );
	exit( 1 );
}

// The syntax errors that these tests expect don't come from the syntax of their queries.
$tests_with_indirect_syntax_errors = array(
	'ctype_latin1.test',         // A latin1 byte that is valid once the query is in UTF-8.
	'debug_sync.test',           // Debug builds parse the value of DEBUG_SYNC themselves.
	'trigger-compat-debug.test', // A broken trigger fails the statements on its table.
);

// These queries have syntax errors that their tests don't expect alone. MySQL reports
// a semantic error first, or the test also allows another error.
$queries_with_hidden_syntax_errors = array(
	'GET DIAGNOSTICS var',
	"CREATE PROCEDURE p1()\nBEGIN\nGET DIAGNOSTICS var;\nEND",
	'GET DIAGNOSTICS CONDITION 1 var',
	"CREATE PROCEDURE p1()\nBEGIN\nGET DIAGNOSTICS CONDITION 1 var;\nEND",
	"CREATE PROCEDURE p1()\nBEGIN\nDECLARE var CONDITION FOR SQLSTATE '12345';\nGET DIAGNOSTICS CONDITION 1 var = NUMBER;\nEND",
	'create view information_schema.v1',
	"create function p(x int, x char(10))\nbegin\nend",
	"create procedure bug6600()\nunlock table t1",
	'CREATE TRIGGER tr1 BEFORE INSERT ON t1 FOR EACH ROW BEGIN create trigger tr2 before insert on t1 for each row do select 1; END ',
	'CREATE FUNCTION bug_13627_f() returns int BEGIN create trigger tr2 before insert on t1 for each row do select 1; return 1; END ',
	"create procedure proc_26503_error_1()\nbegin\nretry:\nrepeat\nbegin\ndeclare continue handler for sqlexception\nbegin\niterate retry;\nend\n\nselect \"do something\";\nend\nuntil true end repeat retry;\nend",
	"create procedure proc_26503_error_2()\nbegin\nretry:\nrepeat\nbegin\ndeclare continue handler for sqlexception\niterate retry;\n\nselect \"do something\";\nend\nuntil true end repeat retry;\nend",
	"create procedure proc_26503_error_3()\nbegin\nretry:\nrepeat\nbegin\ndeclare continue handler for sqlexception\nbegin\nleave retry;\nend\n\nselect \"do something\";\nend\nuntil true end repeat retry;\nend",
	"create procedure proc_26503_error_4()\nbegin\nretry:\nrepeat\nbegin\ndeclare continue handler for sqlexception\nleave retry;\n\nselect \"do something\";\nend\nuntil true end repeat retry;\nend",
	'explain select cast(1 as decimal(64, 999999999999999999999999999999))',
);

$queries = array();
foreach ( scandir( $tests_dir ) as $i => $file ) {
	if ( substr( $file, -5 ) !== '.test' ) {
		continue;
	}

	// MySQL query or mysqltest command delimiter.
	// It can be set dynamically using "DELIMITER <delimiter>" command.
	$delimiter = ';';

	// Track whether we're inside quotes.
	$quotes = null;

	// Track whether we're inside a command body (perl, append_file, write_file), save terminator.
	$command_body_terminator = null;

	// Track whether we're inside a disabled block.
	$is_disabled = false;

	// Track the "error" command that requires the next command to fail.
	$expected_error = null;

	// Track the encoding of the client character set that a statement selects.
	$client_encoding = $default_encoding;

	// Track the SQL modes that the statements select.
	$sql_modes = new Query_Corpus_SQL_Modes();

	$query    = '';
	$contents = file_get_contents( $tests_dir . '/' . $file );

	// Skip mysqltest.test file that is focused on mysqltest constructs rather than SQL.
	if ( 'mysqltest.test' === $file ) {
		continue;
	}

	// Skip parser_stack.test file that is intended to cause parser stack overflow.
	if ( 'parser_stack.test' === $file ) {
		continue;
	}

	// Remove "if" and "while" block, including nested ones, using a recursive regex.
	// Extracting queries from them is not straightforward as they introduce a new scope for delimiters, etc.
	// The files are not decoded yet, so the regexes match bytes, not UTF-8.
	$contents = preg_replace( '/^\s*(?:if|while)\s*(\((?>(?1)|[^()])*+\))\s*(\{(?>(?2)|[^{}])*+})/im', '', $contents );

	// As in mysqltest, only LF and CRLF end a line. Other line separators are part of
	// the query, such as the ones that "comments.test" uses to test whitespace.
	foreach ( preg_split( '/\r?\n/', $contents ) as $line ) {
		// Skip command bodies for perl, append_file, and write_file commands.
		if ( $command_body_terminator ) {
			if ( trim( $line ) === $command_body_terminator ) {
				$command_body_terminator = null;
			}
			continue;
		} elseif (
			preg_match( '/^(--)?perl(\s+(?P<terminator>\w+))?/', $line, $matches )
			|| preg_match( '/^(--)?(write_file|append_file)(\s+(\S+))?(\s+(?P<terminator>\w+))?/', $line, $matches )
		) {
			$command_body_terminator = $matches['terminator'] ?? 'EOF';
			continue;
		}

		// Skip disabled blocks.
		if ( ! $is_disabled && 0 === strpos( strtolower( $line ), '--disable_testcase' ) ) {
			$is_disabled = true;
			continue;
		}
		if ( $is_disabled ) {
			if ( 0 === strpos( strtolower( $line ), '--enable_testcase' ) ) {
				$is_disabled = false;
			}
			continue;
		}

		// As in mysqltest, the "--error" command applies to the command that follows,
		// and any other command ends it. The test files don't show whether a command that
		// is allowed to succeed does, so it counts as a regular command that changes state.
		if ( 0 === strpos( strtolower( $line ), '--error' ) || 0 === strpos( strtolower( $line ), '-- error' ) ) {
			$expected_error = error_command_allows_success( $line ) ? null : $line;
			continue;
		}

		// Track the client character set, unless the statement is expected to fail.
		// "DEFAULT" and character sets that the map doesn't list use the default encoding.
		$client_charset = read_client_charset( $line );
		if ( null !== $client_charset && null === $expected_error ) {
			$client_encoding = QUERY_CORPUS_CHARSET_ENCODINGS[ $client_charset ] ?? $default_encoding;
		}

		$line = convert_to_utf8( $line, array( $client_encoding, 'UTF-8' ) );

		// Skip comments.
		$char1 = $line[0] ?? null;
		if ( '#' === $char1 ) {
			continue;
		}

		// Skip '--' commands; convert "--delimiter <delimiter>" to "DELIMITER <delimiter>".
		$char2 = $line[1] ?? null;
		if ( '-' === $char1 && '-' === $char2 ) {
			if ( 0 === strpos( strtolower( $line ), '--delimiter' ) ) {
				$line = substr( $line, 2 ); // remove '--'
			} else {
				// Inside a query, this is an SQL comment rather than a command.
				if ( '' === $query ) {
					if ( null === $expected_error ) {
						$sql_modes->run_command( $line );
					}
					$expected_error = null;
				}
				continue;
			}
		}

		// Process line.
		$line = trim( $line );
		for ( $i = 0; $i < strlen( $line ); $i++ ) {
			$char = $line[ $i ];

			// Handle quotes.
			if ( '\'' === $char || '"' === $char || '`' === $char ) {
				$prefix     = substr( $line, 0, $i );
				$slashes    = strlen( $prefix ) - strlen( rtrim( $prefix, '\\' ) );
				$is_escaped = 1 === $slashes % 2;
				if ( ! $is_escaped ) {
					if ( null === $quotes ) {
						$quotes = $char; // start
					} elseif ( $quotes === $char ) {
						$quotes = null; // end
					}
				}
			}

			// Found delimiter - end query or command.
			if ( $char === $delimiter[0] && substr( $line, $i, strlen( $delimiter ) ) === $delimiter && null === $quotes ) {
				$i   += strlen( $delimiter ) - 1;
				$char = $line[ $i ] ?? null;

				// Handle "DELIMITER <delimiter>" command.
				if ( 0 === strpos( strtolower( $query ), 'delimiter' ) ) {
					$delimiter = trim( substr( $query, strlen( 'delimiter' ) ) );
				} elseif ( preg_match( $skip_pattern, $query ) ) {
					// skip commands
					if ( null === $expected_error ) {
						$sql_modes->run_command( $query );
					}
				} elseif ( '' !== $query ) {
					$modes = $sql_modes->get_modes();
					if ( null === $expected_error ) {
						$queries[ $query ][ $modes ] = false;
						$sql_modes->run_query( $query );
					} else {
						// A syntax error must be the only allowed result. ER_SYNTAX_ERROR doesn't
						// count, because it comes from semantic checks on queries that parse.
						$is_syntax_error = in_array( $query, $queries_with_hidden_syntax_errors, true ) || (
							1 === preg_match( '/^(?:--\s*)?error\s+(?:ER_PARSE_ERROR|1064)\s*(?:#|$)/i', $expected_error )
							&& ! in_array( $file, $tests_with_indirect_syntax_errors, true )
							&& 0 === preg_match( '/^prepare\s+\w+\s+from\s+[\'"@]/i', $query )
						);

						// A failed query changes no state. Any other result shows that it parses.
						$queries[ $query ][ $modes ] = ( $queries[ $query ][ $modes ] ?? true ) && $is_syntax_error;
					}
				}
				// The "error" command works like "--error", but it ends with a delimiter.
				$expected_error = preg_match( '/^error\b/i', $query ) && ! error_command_allows_success( $query ) ? $query : null;

				$query = '';

				// Skip whitespace after command.
				$next_char = $line[ $i + 1 ] ?? null;
				while ( null !== $next_char && ctype_space( $next_char ) ) {
					++$i;
					$next_char = $line[ $i + 1 ] ?? null;
				}

				// Skip comments after command.
				if ( '#' === $next_char || ( '-' === $next_char && '-' === ( $line[ $i + 2 ] ?? null ) ) ) {
					break;
				}
			} else {
				$query .= $char;
			}
		}

		// Preserve newlines.
		if ( '' !== $query ) {
			$query .= "\n";
		}
	}
}

// Save deduped queries to CSV.
if ( ! is_dir( $data_dir ) ) {
	mkdir( $data_dir, 0777, true );
}
$output = fopen( $query_file, 'w' );
if ( false === $output ) {
	fwrite( STDERR, "error: cannot open $query_file for writing.\n" );
	exit( 1 );
}
foreach ( $queries as $query => $query_modes ) {
	foreach ( $query_modes as $modes => $is_syntax_error ) {
		if ( $is_syntax_error ) {
			$record = array( $query, $modes, 'syntax_error' );
		} else {
			$record = '' === $modes ? array( $query ) : array( $query, $modes );
		}
		fputcsv( $output, $record, ',', '"', '\\' );
	}
}
fclose( $output );

/**
 * Check whether an "error" command lets the next command succeed.
 *
 * The "error" command of mysqltest lists the errors that the next command may fail with.
 * Normally, the test breaks if that command succeeds. Error 0 or SQLSTATE 00000 anywhere
 * in the list means "no error", as in "--error 0,ER_PARSE_ERROR", so the command may either
 * fail or succeed. The value of a variable in the list isn't known, so a variable doesn't
 * count.
 *
 * @param string $command The command, as "--error <list>" or "error <list>".
 * @return bool Whether the next command may succeed.
 */
function error_command_allows_success( string $command ): bool {
	return 1 === preg_match( '/^(?:--\s*)?error\s+(?:[^#,]*,\s*)*(?:0|S00000)\s*(?:[,#]|$)/i', $command );
}
