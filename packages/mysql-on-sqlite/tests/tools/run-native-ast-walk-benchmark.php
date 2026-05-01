<?php

/**
 * Benchmark for the native AST walk path with the per-AST identity cache.
 *
 * Parses every query in the MySQL server suite, then walks each AST through
 * a configurable mode to exercise the bridge accessors and the identity map.
 * Reports wall time, peak memory, and a basic identity-stability check so
 * the cache cost can be compared against the no-cache baseline.
 *
 * Modes:
 *   walk       — single full descendant walk per AST (cache-miss heavy).
 *   no-walk    — parse only.
 *   rewalk=N   — repeat the descendant walk N times per AST (1st pass is
 *                misses, remaining passes are all hits — the scenario the
 *                identity cache is supposed to win on).
 *   reread=N   — for each top-level child node, call accessors N times to
 *                exercise repeated-read hit paths.
 *   subtree=N  — walk descendants once, then re-read each one's first child
 *                N times — models translator/rewriter passes that re-enter
 *                the same subtrees.
 *
 * Usage:
 *   php run-native-ast-walk-benchmark.php
 *   php run-native-ast-walk-benchmark.php --mode=no-walk
 *   php run-native-ast-walk-benchmark.php --mode=rewalk --repeat=10
 *   php run-native-ast-walk-benchmark.php --mode=reread --repeat=10
 *   php run-native-ast-walk-benchmark.php --mode=subtree --repeat=5
 *
 * The script auto-detects the native extension. Without it, the walk
 * exercises the pure-PHP WP_Parser_Node path, which is useful as the
 * "no-cache cost" reference point.
 */

set_error_handler(
	function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

require_once __DIR__ . '/../../src/load.php';

$mode   = 'walk';
$repeat = 1;
foreach ( $argv as $arg ) {
	if ( '--no-walk' === $arg ) {
		$mode = 'no-walk';
	} elseif ( 0 === strpos( $arg, '--mode=' ) ) {
		$mode = substr( $arg, 7 );
	} elseif ( 0 === strpos( $arg, '--repeat=' ) ) {
		$repeat = max( 1, (int) substr( $arg, 9 ) );
	}
}

$grammar_data = include __DIR__ . '/../../src/mysql/mysql-grammar.php';
$grammar      = new WP_Parser_Grammar( $grammar_data );

$data_dir = __DIR__ . '/../mysql/data';
$handle   = fopen( "$data_dir/mysql-server-tests-queries.csv", 'r' );
$records  = array();
while ( ( $record = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false ) {
	$records[] = $record;
}
fclose( $handle );

$total       = 0;
$walked      = 0;
$identity_ok = true;
$failures    = 0;

if ( function_exists( 'memory_reset_peak_usage' ) ) {
	memory_reset_peak_usage();
}
$start = microtime( true );

for ( $i = 1; $i < count( $records ); $i += 1 ) {
	$query = $records[ $i ][0];
	if ( null === $query ) {
		continue;
	}

	try {
		$lexer  = new WP_MySQL_Lexer( $query );
		$tokens = $lexer instanceof WP_MySQL_Native_Lexer
			? $lexer->native_token_stream()
			: $lexer->remaining_tokens();
		$parser = new WP_MySQL_Parser( $grammar, $tokens );
		$ast    = $parser->parse();
		if ( null === $ast ) {
			++$failures;
			continue;
		}
		++$total;

		switch ( $mode ) {
			case 'no-walk':
				break;

			case 'walk':
				$descendants = $ast->get_descendants();
				$walked     += count( $descendants );

				$first = $ast->get_first_child_node();
				if ( null !== $first ) {
					$again = $ast->get_first_child_node();
					if ( $first !== $again ) {
						$identity_ok = false;
					}
				}
				break;

			case 'rewalk':
				// Repeated full-tree walks. After the first pass every wrapper
				// the cache returns is a hit; without the cache, every pass
				// re-allocates wrappers for the entire tree from scratch.
				for ( $r = 0; $r < $repeat; $r++ ) {
					$descendants = $ast->get_descendants();
					$walked     += count( $descendants );
				}
				break;

			case 'reread':
				// Repeated top-level child reads. Models analysis passes that
				// keep poking at the root of the tree.
				for ( $r = 0; $r < $repeat; $r++ ) {
					$child = $ast->get_first_child_node();
					if ( null !== $child ) {
						++$walked;
						// Identity must hold across repeated reads.
						if ( $r > 0 && $child !== $prev ) {
							$identity_ok = false;
						}
						$prev = $child;
					}
				}
				break;

			case 'subtree':
				// Walk descendants once, then for each descendant re-read its
				// first child N times. Models translator/rewriter passes that
				// re-enter previously visited subtrees.
				$descendants = $ast->get_descendants();
				foreach ( $descendants as $d ) {
					if ( ! $d instanceof WP_Parser_Node ) {
						continue;
					}
					for ( $r = 0; $r < $repeat; $r++ ) {
						$child = $d->get_first_child_node();
						if ( null !== $child ) {
							++$walked;
						}
					}
				}
				break;

			default:
				fwrite( STDERR, "Unknown mode: $mode\n" );
				exit( 2 );
		}
	} catch ( Throwable $e ) {
		++$failures;
	}
}

$duration = microtime( true ) - $start;
$peak_mb  = memory_get_peak_usage( true ) / 1024 / 1024;
$native   = class_exists( 'WP_MySQL_Native_Parser', false ) ? 'native' : 'php';

printf(
	"path=%s mode=%s repeat=%d parsed=%d walked_nodes=%d failures=%d duration=%.4fs qps=%d peak_mem=%.1fMB identity_ok=%s\n",
	$native,
	$mode,
	$repeat,
	$total,
	$walked,
	$failures,
	$duration,
	$total > 0 ? (int) ( $total / $duration ) : 0,
	$peak_mb,
	$identity_ok ? 'true' : 'FALSE'
);

if ( ! $identity_ok ) {
	fwrite( STDERR, "Identity check failed: accessor returned different instances on repeat read.\n" );
	exit( 1 );
}
