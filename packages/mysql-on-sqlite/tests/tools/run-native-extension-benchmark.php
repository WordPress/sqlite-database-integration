<?php

/**
 * Compare the pure-PHP MySQL lexer with the optional native extension lexer.
 *
 * Options:
 *   --json       Print machine-readable benchmark output.
 *   --limit=N    Only benchmark the first N queries.
 */

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
$queries = array();
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

function benchmark_php_mysql_lexer( $queries ) {
	$start = microtime( true );
	foreach ( $queries as $query ) {
		$lexer  = new WP_MySQL_Lexer( $query );
		$tokens = $lexer->remaining_tokens();
		if ( count( $tokens ) === 0 ) {
			throw new Exception( 'Failed to tokenize query: ' . $query );
		}
	}
	$duration = microtime( true ) - $start;
	return array(
		'available' => true,
		'duration'  => $duration,
		'qps'       => count( $queries ) / $duration,
	);
}

function benchmark_native_mysql_lexer( $queries ) {
	if ( ! class_exists( 'WP_MySQL_Native_Lexer', false ) ) {
		return array(
			'available' => false,
			'reason'    => 'The wp_mysql_parser extension is not loaded.',
		);
	}

	$start = microtime( true );
	foreach ( $queries as $query ) {
		$lexer  = new WP_MySQL_Native_Lexer( $query );
		$tokens = $lexer->native_token_stream();
		if ( 0 === $tokens->count() ) {
			throw new Exception( 'Failed to tokenize query with native lexer: ' . $query );
		}
	}
	$duration = microtime( true ) - $start;
	return array(
		'available' => true,
		'duration'  => $duration,
		'qps'       => count( $queries ) / $duration,
	);
}

$php    = benchmark_php_mysql_lexer( $queries );
$native = benchmark_native_mysql_lexer( $queries );

$result = array(
	'benchmark'        => 'mysql-lexer-native-extension',
	'queries'          => count( $queries ),
	'php_version'      => PHP_VERSION,
	'extension_loaded' => extension_loaded( 'wp_mysql_parser' ),
	'pure_php'         => $php,
	'native_extension' => $native,
);

if ( ! empty( $native['available'] ) ) {
	$result['speedup'] = $native['qps'] / $php['qps'];
}

if ( $json ) {
	echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
	exit;
}

printf( "Benchmarked %d MySQL queries on PHP %s.\n", $result['queries'], PHP_VERSION );
printf( "Pure PHP lexer:       %.5fs @ %d QPS\n", $php['duration'], $php['qps'] );
if ( empty( $native['available'] ) ) {
	printf( "Native extension:     unavailable (%s)\n", $native['reason'] );
	exit;
}
printf( "Native extension:     %.5fs @ %d QPS\n", $native['duration'], $native['qps'] );
printf( "Speedup:              %.2fx\n", $result['speedup'] );
