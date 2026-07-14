<?php

/**
 * This script runs the MySQL parser on all queries from the MySQL server suite.
 * It tracks parsing failures and exceptions and measures parsing performance.
 * This is an end-to-end benchmark that includes lexing time in the results.
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

require_once __DIR__ . '/../../src/load.php';

function get_stats( $total, $failures, $exceptions ) {
	return sprintf(
		'Total: %5d  |  Failures: %4d / %2d%%  |  Exceptions: %4d / %2d%%',
		$total,
		$failures,
		$failures / $total * 100,
		$exceptions,
		$exceptions / $total * 100
	);
}

// Load the MySQL parser.
$parser = WP_MySQL_Parser_Factory::create_parser();

// Load the bounded checked-in corpus before timing so file IO is excluded
// from the benchmark.
$data_dir = __DIR__ . '/../mysql/data';
$handle   = fopen( "$data_dir/mysql-server-tests-queries.csv", 'r' );
$queries  = array();
while ( ( $record = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false ) {
	$query = $record[0] ?? null;
	if ( null === $query || '' === $query ) {
		continue;
	}
	$queries[] = $query;
	if ( null !== $limit && count( $queries ) >= $limit ) {
		break;
	}
}

// Run the parser.
$failures   = array();
$exceptions = array();
$processed  = 0;
$start      = microtime( true );
foreach ( $queries as $query ) {
	try {
		$lexer  = new WP_MySQL_Lexer( $query );
		$tokens = $lexer->remaining_tokens();
		if ( count( $tokens ) === 0 ) {
			throw new Exception( 'Failed to tokenize query: ' . $query );
		}

		$ast = $parser->parse( $tokens );
		if ( null === $ast ) {
			$failures[] = $query;
		}
	} catch ( Exception $e ) {
		$exceptions[] = $query;
	}

	$processed += 1;
	if ( ! $json && $processed > 0 && 0 === $processed % 1000 ) {
		echo get_stats( $processed, count( $failures ), count( $exceptions ) ), "\n";
	}
}
$duration = microtime( true ) - $start;
$qps      = $processed / $duration;

if ( $json ) {
	echo json_encode(
		array(
			'benchmark'      => 'mysql-parser',
			'implementation' => 'php',
			'queries'        => $processed,
			'duration'       => $duration,
			'qps'            => $qps,
			'failures'       => count( $failures ),
			'exceptions'     => count( $exceptions ),
			'php_version'    => PHP_VERSION,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	), "\n";
	exit;
}

echo get_stats( $processed, count( $failures ), count( $exceptions ) ), "\n";

// Print the results.
printf( "\nParsed %d queries in %.5fs @ %d QPS.\n", $processed, $duration, $qps );
