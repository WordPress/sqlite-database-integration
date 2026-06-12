<?php

/**
 * Benchmark the MySQL lexer over the checked-in MySQL server test corpus and
 * report its tokenization throughput (queries lexed per second).
 *
 * JIT / opcache are start-up ini settings, so this script does not toggle them;
 * it reports the active configuration so every run is self-describing. Run it
 * twice to compare without and with the tracing JIT (the lexer behaves very
 * differently under each):
 *
 *     php packages/mysql-on-sqlite/tests/tools/run-lexer-benchmark.php
 *     php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=64M -d opcache.jit=tracing \
 *         packages/mysql-on-sqlite/tests/tools/run-lexer-benchmark.php
 *
 * To check a change, run it on the base commit and again on your branch and
 * compare the "best" numbers (ideally for both JIT configs).
 *
 * Methodology: a few warmup passes (discarded — they heat opcache, the tracing
 * JIT, and the CPU caches) followed by N timed passes over the whole corpus.
 * The headline is the BEST pass: lexing is deterministic and CPU-bound, so
 * outside interference can only make a pass slower, never faster, which makes
 * the fastest pass the most reproducible estimate of the code's intrinsic cost
 * and the most stable basis for a before/after comparison. The median and the
 * best-vs-worst spread are reported too, so a noisy machine is obvious.
 *
 * Options:
 *   --json            Print machine-readable output.
 *   --limit=N         Only benchmark the first N queries.
 *   --iterations=N    Number of timed passes (default 10).
 *   --warmup=N        Number of discarded warmup passes (default 3).
 */

// Throw an exception if anything fails.
set_error_handler(
	function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$json       = in_array( '--json', $argv, true );
$limit      = null;
$iterations = 10;
$warmup     = 3;
foreach ( $argv as $arg ) {
	if ( 0 === strpos( $arg, '--limit=' ) ) {
		$limit = max( 1, (int) substr( $arg, strlen( '--limit=' ) ) );
	} elseif ( 0 === strpos( $arg, '--iterations=' ) ) {
		$iterations = max( 1, (int) substr( $arg, strlen( '--iterations=' ) ) );
	} elseif ( 0 === strpos( $arg, '--warmup=' ) ) {
		$warmup = max( 0, (int) substr( $arg, strlen( '--warmup=' ) ) );
	}
}

require_once __DIR__ . '/../../src/load.php';

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
$query_count = count( $queries );

// Lex the whole corpus once.
$lex_corpus = function () use ( $queries ) {
	foreach ( $queries as $query ) {
		$lexer  = new WP_MySQL_Lexer( $query );
		$tokens = $lexer->remaining_tokens();
		if ( 0 === count( $tokens ) ) {
			throw new Exception( 'Failed to tokenize query: ' . $query );
		}
	}
};

// Warmup passes are discarded.
for ( $i = 0; $i < $warmup; $i++ ) {
	$lex_corpus();
}

// Timed passes: one QPS sample per pass.
$samples = array();
for ( $i = 0; $i < $iterations; $i++ ) {
	$start = microtime( true );
	$lex_corpus();
	$samples[] = $query_count / ( microtime( true ) - $start );
}
sort( $samples );

$best   = $samples[ count( $samples ) - 1 ];
$worst  = $samples[0];
$mean   = array_sum( $samples ) / count( $samples );
$mid    = intdiv( count( $samples ), 2 );
$median = 0 === count( $samples ) % 2
	? ( $samples[ $mid - 1 ] + $samples[ $mid ] ) / 2
	: $samples[ $mid ];
$spread = $best > 0 ? ( $best - $worst ) / $best : 0.0;

// Detect the active runtime configuration so the run is self-describing.
// opcache_get_status() returns false (no warning) when opcache is disabled.
$opcache_status = function_exists( 'opcache_get_status' ) ? opcache_get_status( false ) : false;
$opcache_on     = is_array( $opcache_status );
$jit_on         = $opcache_on && ! empty( $opcache_status['jit']['on'] );
$implementation = 'php';

if ( $json ) {
	echo json_encode(
		array(
			'benchmark'      => 'mysql-lexer',
			'implementation' => $implementation,
			'opcache'        => $opcache_on,
			'jit'            => $jit_on,
			'queries'        => $query_count,
			'warmup'         => $warmup,
			'iterations'     => $iterations,
			'qps'            => $best, // Headline (best pass); kept as "qps" for compatibility.
			'qps_best'       => $best,
			'qps_median'     => $median,
			'qps_mean'       => $mean,
			'qps_worst'      => $worst,
			'spread'         => $spread,
			'php_version'    => PHP_VERSION,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	), "\n";
	exit;
}

$config = $jit_on ? 'opcache + tracing JIT' : ( $opcache_on ? 'opcache, no JIT' : 'no opcache' );
printf( "MySQL lexer (%s implementation) — %s\n", $implementation, $config );
$jit_requested = ! in_array( strtolower( (string) ini_get( 'opcache.jit' ) ), array( '', '0', 'off', 'disable' ), true );
if ( $jit_requested && ! $jit_on ) {
	printf( "  warning: opcache.jit is set but the JIT is NOT active here — check that opcache is enabled and jit_buffer_size > 0.\n" );
}
printf( "%s queries, %d warmup + %d timed passes\n", number_format( $query_count ), $warmup, $iterations );
printf( "  best:   %s QPS\n", number_format( $best ) );
printf( "  median: %s QPS\n", number_format( $median ) );
printf( "  spread: %.1f%% (best vs worst)\n", $spread * 100 );
if ( $spread > 0.10 ) {
	printf( "  note: >10%% spread — the machine is noisy; close other apps for a steadier number.\n" );
}
