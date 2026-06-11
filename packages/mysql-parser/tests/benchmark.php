<?php
/**
 * Benchmark the MySQL parser over the MySQL server test corpus.
 *
 * Reports the corpus parse rate and end-to-end (lex + parse) throughput.
 *
 * Methodology: a few warmup passes (discarded — they heat opcache, the tracing
 * JIT, and the CPU caches) followed by N timed passes over the whole corpus. The
 * headline is the BEST pass: parsing is deterministic and CPU-bound, so outside
 * interference only ever makes a pass slower, which makes the fastest pass the
 * most reproducible estimate. A single cold pass badly under-reports the tracing
 * JIT (it pays compilation inside the timed run), so warmup is on by default.
 *
 * Options:
 *   --json          Machine-readable output.
 *   --limit=N       Only benchmark the first N queries.
 *   --iterations=N  Number of timed passes (default 5).
 *   --warmup=N      Number of discarded warmup passes (default 2).
 *   --corpus=PATH   Path to the queries CSV (default: the package corpus).
 */

set_error_handler(
	function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$json       = in_array( '--json', $argv, true );
$limit      = null;
$iterations = 5;
$warmup     = 2;
$corpus     = __DIR__ . '/../data/mysql-server-query-corpus/mysql-latest.csv';
foreach ( $argv as $arg ) {
	if ( 0 === strpos( $arg, '--limit=' ) ) {
		$limit = max( 1, (int) substr( $arg, strlen( '--limit=' ) ) );
	} elseif ( 0 === strpos( $arg, '--iterations=' ) ) {
		$iterations = max( 1, (int) substr( $arg, strlen( '--iterations=' ) ) );
	} elseif ( 0 === strpos( $arg, '--warmup=' ) ) {
		$warmup = max( 0, (int) substr( $arg, strlen( '--warmup=' ) ) );
	} elseif ( 0 === strpos( $arg, '--corpus=' ) ) {
		$corpus = substr( $arg, strlen( '--corpus=' ) );
	}
}

require_once __DIR__ . '/../vendor/autoload.php';
$parser = WP_MySQL_Parser_Factory::create_parser();

// Load the corpus before timing so file IO is excluded.
if ( ! is_readable( $corpus ) ) {
	fwrite( STDERR, "error: corpus not found at $corpus (pass --corpus=PATH).\n" );
	exit( 1 );
}
$handle  = fopen( $corpus, 'r' );
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
fclose( $handle );
$query_count = count( $queries );

// One end-to-end pass over the corpus (lex + parse), recording failures and
// exceptions (deterministic across passes, so the last pass's counts are kept).
$failures     = 0;
$exceptions   = 0;
$parse_corpus = function () use ( $queries, $parser, &$failures, &$exceptions ) {
	$failures   = 0;
	$exceptions = 0;
	foreach ( $queries as $query ) {
		try {
			$tokens = ( new WP_MySQL_Lexer( $query ) )->remaining_tokens();
			if ( null === $parser->parse( $tokens ) ) {
				++$failures;
			}
		} catch ( Throwable $e ) {
			++$exceptions;
		}
	}
};

for ( $i = 0; $i < $warmup; $i++ ) {
	$parse_corpus();
}

$samples = array();
for ( $i = 0; $i < $iterations; $i++ ) {
	$start = microtime( true );
	$parse_corpus();
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

$opcache_status = function_exists( 'opcache_get_status' ) ? opcache_get_status( false ) : false;
$opcache_on     = is_array( $opcache_status );
$jit_on         = $opcache_on && ! empty( $opcache_status['jit']['on'] );

if ( $json ) {
	echo json_encode(
		array(
			'benchmark'   => 'mysql-parser',
			'opcache'     => $opcache_on,
			'jit'         => $jit_on,
			'queries'     => $query_count,
			'warmup'      => $warmup,
			'iterations'  => $iterations,
			'qps'         => $best,
			'qps_best'    => $best,
			'qps_median'  => $median,
			'qps_mean'    => $mean,
			'qps_worst'   => $worst,
			'spread'      => $spread,
			'failures'    => $failures,
			'exceptions'  => $exceptions,
			'php_version' => PHP_VERSION,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	), "\n";
	exit;
}

$config = $jit_on ? 'opcache + tracing JIT' : ( $opcache_on ? 'opcache, no JIT' : 'no opcache' );
printf( "MySQL parser (official grammar) — %s\n", $config );
$jit_requested = ! in_array( strtolower( (string) ini_get( 'opcache.jit' ) ), array( '', '0', 'off', 'disable' ), true );
if ( $jit_requested && ! $jit_on ) {
	printf( "  warning: opcache.jit is set but the JIT is NOT active here — check that opcache is enabled and jit_buffer_size > 0.\n" );
}
printf( "%s queries, %d warmup + %d timed passes (end-to-end lex+parse)\n", number_format( $query_count ), $warmup, $iterations );
printf( "  best:   %s QPS\n", number_format( $best ) );
printf( "  median: %s QPS\n", number_format( $median ) );
printf( "  spread: %.1f%% (best vs worst)\n", $spread * 100 );
printf(
	"  failures: %d (%.2f%%) | exceptions: %d\n",
	$failures,
	$query_count > 0 ? $failures / $query_count * 100 : 0.0,
	$exceptions
);
if ( $spread > 0.10 ) {
	printf( "  note: >10%% spread — the machine is noisy; close other apps for a steadier number.\n" );
}
