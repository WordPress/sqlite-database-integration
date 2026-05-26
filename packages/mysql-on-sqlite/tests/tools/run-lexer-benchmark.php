<?php

/**
 * This script runs the MySQL lexer on queries from the MySQL server suite.
 * It ensures the lexer tokenizes all queries and measures lexing performance.
 *
 * Options:
 *   --json       Print machine-readable benchmark output.
 *   --limit=N    Only benchmark the first N queries.
 */

// Throw exception if anything fails.
set_error_handler(
	function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$json  = in_array( '--json', $argv, true );
$limit = null;
foreach ( $argv as $arg ) {
	if ( 0 === strpos( $arg, '--limit=' ) ) {
		$limit = max( 1, (int) substr( $arg, strlen( '--limit=' ) ) );
	}
}

require_once __DIR__ . '/../../src/parser/class-wp-parser-token.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-token.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-lexer.php';

// Load the bounded checked-in corpus before timing so file IO is excluded
// from the benchmark.
$handle  = fopen( __DIR__ . '/../mysql/data/mysql-server-tests-queries.csv', 'r' );
$records = array();
while ( ( $record = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false ) {
	$records[] = $record;
	if ( null !== $limit && count( $records ) >= $limit ) {
		break;
	}
}

// Run the lexer.
$processed = 0;
$start     = microtime( true );
for ( $i = 0; $i < count( $records ); $i += 1 ) {
	$query  = $records[ $i ][0];
	$lexer  = new WP_MySQL_Lexer( $query );
	$tokens = $lexer->remaining_tokens();
	if ( count( $tokens ) === 0 ) {
		throw new Exception( 'Failed to tokenize query: ' . $query );
	}
	$processed += 1;
}
$duration = microtime( true ) - $start;
$qps      = $processed / $duration;

if ( $json ) {
	echo json_encode(
		array(
			'benchmark'      => 'mysql-lexer',
			'implementation' => 'php',
			'queries'        => $processed,
			'duration'       => $duration,
			'qps'            => $qps,
			'php_version'    => PHP_VERSION,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	), "\n";
	exit;
}

// Print the results.
printf( "\nTokenized %d queries in %.5fs @ %d QPS.\n", $processed, $duration, $qps );
