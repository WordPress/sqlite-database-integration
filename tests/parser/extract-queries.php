<?php

/**
 * This script scans MySQL test files and extracts SQL queries from them.
 *
 * The tests are written using The MySQL Test Framework:
 *   https://dev.mysql.com/doc/dev/mysql-server/latest/PAGE_MYSQL_TEST_RUN.html
 *
 * See alos the mysqltest Language Reference:
 *   https://dev.mysql.com/doc/dev/mysql-server/latest/PAGE_MYSQLTEST_LANGUAGE_REFERENCE.html
 */

// Comments and other prefixes to skip:
$prefixes = [
	'#',
	'--',
	'{',
	'}',
];

// List of mysqltest commands:
$commands = [
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
];

// Build regex patterns to skip mysqltest-specific constructs:
$prefixesPattern =
	'('
	. implode(
		'|',
		array_map(
			function ($prefix) {
				return preg_quote($prefix, '/');
			},
			$prefixes
		)
	)
	. ')';

$commandsPattern =
	'('
	. implode(
		'|',
		array_map(
			function ($command) {
				return preg_quote($command, '/');
			},
			$commands
		)
	)
	. ')(\s+|\()';

$skipPattern = "/^($prefixesPattern|$commandsPattern)/i";

// Scan MySQL test files for SQL queries:
$testsDir = __DIR__ . '/tmp/mysql-server-tests/mysql-test/t';
if (!is_dir($testsDir)) {
	echo "Directory '$testsDir' not found. Please, run 'download.sh' first.\n";
	exit(1);
}

$queries = [];
foreach (scandir($testsDir) as $i => $file) {
	if (substr($file, -5) !== '.test') {
		continue;
	}

	// MySQL query or mysqltest command delimiter.
	// It can be set dynamically using "DELIMITER <delimiter>" command.
	$delimiter = ';';

	// Track whether we're inside quotes.
	$quotes = null;

	// Track whether we're inside a command body (perl, append_file, write_file), save terminator.
	$command_body_terminator = null;

	// Track whether we should skip the next query.
	$skipNext = false;

	$lines = 0;
	$query = '';
	$contents = utf8_encode(file_get_contents($testsDir . '/' . $file));
	foreach (preg_split('/\R/u', $contents) as $line) {
		$lines += 1;

		// Skip command bodies for perl, append_file, and write_file commands.
		if ($command_body_terminator) {
			if (trim($line) === $command_body_terminator) {
				$command_body_terminator = null;
			}
			continue;
		} elseif (
			preg_match('/^(--)?perl(\s+(?P<terminator>\w+))?/', $line, $matches)
			|| preg_match('/^(--)?(write_file|append_file)(\s+(\S+))?(\s+(?P<terminator>\w+))?/', $line, $matches)
		) {
			$command_body_terminator = $matches['terminator'] ?? 'EOF';
			continue;
		}

		// Skip queries that are expected to result in parse errors for now.
		if (str_starts_with(strtolower($line), '--error') || str_starts_with(strtolower($line), '-- error')) {
			$skipNext = true;
			continue;
		}

		// Skip comments.
		$char1 = $line[0] ?? null;
		$char2 = $line[1] ?? null;
		if ($char1 === '#' || ($char1 === '-' && $char2 === '-')) {
			continue;
		}

		// Process line.
		$line = trim($line);
		for ($i = 0; $i < strlen($line); $i++) {
			$char = $line[$i];

			// Handle quotes.
			if ($char === '\'' || $char === '"' || $char === '`') {
				if ($quotes === null) {
					$quotes = $char; // start
				} elseif ($quotes === $char) {
					$quotes = null; // end
				}
			}

			// Found delimiter - end query or command.
			if ($char === $delimiter[0] && substr($line, $i, strlen($delimiter)) === $delimiter && $quotes === null) {
				$i += strlen($delimiter) - 1;
				$char = $line[$i] ?? null;

				// Handle "DELIMITER <delimiter>" command.
				if (str_starts_with(strtolower($query), 'delimiter')) {
					$delimiter = trim(substr($query, strlen('delimiter')));
				} elseif (preg_match($skipPattern, $query)) {
					// skip commands
				} else {
					if (!$skipNext) {
						$queries[$query] = true;
					} else {
						$skipNext = false;
					}
				}

				$query = '';

				// Skip whitespace after command.
				$nextChar = $line[$i + 1] ?? null;
				while ($nextChar !== null && ctype_space($nextChar)) {
					$i++;
					$nextChar = $line[$i + 1] ?? null;
				}

				// Skip comments after command.
				if ($nextChar === '#' || ($nextChar === '-' && ($nextChar[$i + 1] ?? null) === '-')) {
					break;
				}
			} else {
				$query .= $char;
			}
		}

		// Preserve newlines.
		if ($query !== '') {
			$query .= "\n";
		}
	}
}

// Save deduped queries to CSV.
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
	mkdir($dataDir, 0777, true);
}
$output = fopen($dataDir . '/queries.csv', 'w');
foreach ($queries as $query => $_) {
	fputcsv($output, [$query]);
}
