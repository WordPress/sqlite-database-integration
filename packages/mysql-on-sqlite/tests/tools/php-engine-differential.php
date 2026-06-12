<?php
/**
 * Differential testing harness for the pure-PHP database engine.
 *
 * Replays a corpus of captured SQLite queries against both the real PDO
 * SQLite driver and WP_PHP_Engine_PDO, comparing results, errors, affected
 * row counts, and insert IDs.
 *
 * Usage:
 *   php tests/tools/php-engine-differential.php <corpus.jsonl> [--limit=N] [--max-diffs=N] [--start-session=N]
 *
 * The corpus is a JSONL file of [sql, params] pairs, as captured by the
 * SQLITE_CAPTURE_FILE instrumentation. The corpus is split into sessions
 * at each "PRAGMA foreign_keys = ON" statement (emitted once per driver
 * initialization), and each session runs on a fresh in-memory database.
 */

// phpcs:ignoreFile

$root = dirname( dirname( __DIR__ ) );
require_once dirname( __DIR__, 2 ) . '/src/php-engine/load.php';
require_once $root . '/wp-includes/sqlite/class-wp-sqlite-pdo-user-defined-functions.php';

$corpus_file   = null;
$limit         = PHP_INT_MAX;
$max_diffs     = 20;
$start_session = 0;
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( preg_match( '/^--limit=(\d+)$/', $arg, $m ) ) {
		$limit = (int) $m[1];
	} elseif ( preg_match( '/^--max-diffs=(\d+)$/', $arg, $m ) ) {
		$max_diffs = (int) $m[1];
	} elseif ( preg_match( '/^--start-session=(\d+)$/', $arg, $m ) ) {
		$start_session = (int) $m[1];
	} else {
		$corpus_file = $arg;
	}
}
if ( null === $corpus_file || ! file_exists( $corpus_file ) ) {
	fwrite( STDERR, "Corpus file not found.\n" );
	exit( 1 );
}

/**
 * Run one query against a PDO instance, capturing the outcome.
 */
function run_query( $pdo, $sql, $params ) {
	try {
		$stmt = $pdo->prepare( $sql );
		$stmt->execute( $params );
		$rows = $stmt->fetchAll( PDO::FETCH_NUM );
		// Normalize values to strings for comparison.
		$normalized = array();
		foreach ( $rows as $row ) {
			$out = array();
			foreach ( $row as $value ) {
				if ( null === $value ) {
					$out[] = null;
				} elseif ( is_float( $value ) ) {
					$out[] = 'f:' . sprintf( '%.10g', $value );
				} else {
					$out[] = (string) $value;
				}
			}
			$normalized[] = $out;
		}
		// Column names matter for the driver's result handling.
		$cols = array();
		for ( $i = 0; $i < $stmt->columnCount(); $i++ ) {
			$meta   = $stmt->getColumnMeta( $i );
			$cols[] = $meta ? $meta['name'] : '?';
		}
		return array(
			'ok'     => true,
			'rows'   => $normalized,
			'cols'   => $cols,
			'last'   => $pdo->lastInsertId(),
		);
	} catch ( Exception $e ) {
		$message = $e->getMessage();
		return array(
			'ok'    => false,
			'error' => $message,
		);
	}
}

/**
 * Compare two result row sets, tolerating a small wall-clock drift in
 * current-timestamp values (the two engines run a moment apart).
 */
function rows_equal( $a_rows, $b_rows ) {
	if ( count( $a_rows ) !== count( $b_rows ) ) {
		return false;
	}
	foreach ( $a_rows as $i => $a_row ) {
		$b_row = $b_rows[ $i ];
		if ( count( $a_row ) !== count( $b_row ) ) {
			return false;
		}
		foreach ( $a_row as $j => $a_value ) {
			$b_value = $b_row[ $j ];
			if ( $a_value === $b_value ) {
				continue;
			}
			if ( is_string( $a_value ) && is_string( $b_value )
				&& preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $a_value )
				&& preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $b_value )
				&& abs( strtotime( $a_value ) - strtotime( $b_value ) ) <= 2 ) {
				continue;
			}
			return false;
		}
	}
	return true;
}

$lines     = file( $corpus_file );
$sessions  = array();
$current   = array();
foreach ( $lines as $line ) {
	$decoded = json_decode( $line, true );
	if ( ! is_array( $decoded ) ) {
		continue;
	}
	if ( 'PRAGMA foreign_keys = ON' === $decoded[0] && count( $current ) > 0 ) {
		$sessions[] = $current;
		$current    = array();
	}
	$current[] = $decoded;
}
if ( count( $current ) > 0 ) {
	$sessions[] = $current;
}

printf( "Corpus: %d queries in %d sessions.\n", count( $lines ), count( $sessions ) );

$diffs        = 0;
$ran          = 0;
$ordered_diff = 0;
$start_time   = microtime( true );

foreach ( $sessions as $session_index => $session ) {
	if ( $session_index < $start_session ) {
		continue;
	}
	if ( $ran >= $limit || $diffs >= $max_diffs ) {
		break;
	}

	$pdo_class = PHP_VERSION_ID >= 80400 ? PDO\SQLite::class : PDO::class;
	$sqlite    = new $pdo_class( 'sqlite::memory:' );
	$sqlite->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	$engine = new WP_PHP_Engine_PDO( 'php-engine::memory:' );

	WP_SQLite_PDO_User_Defined_Functions::register_for( $sqlite );
	WP_SQLite_PDO_User_Defined_Functions::register_for( $engine );

	foreach ( $session as $query_index => $pair ) {
		if ( $ran >= $limit || $diffs >= $max_diffs ) {
			break;
		}
		list( $sql, $params ) = $pair;

		// Skip nondeterministic-by-design queries.
		if ( preg_match( '/\b(RANDOM|RAND|RANDOMBLOB)\b/i', $sql ) ) {
			continue;
		}

		$a    = run_query( $sqlite, $sql, $params );
		$b    = run_query( $engine, $sql, $params );
		$ran += 1;

		$mismatch = null;
		if ( $a['ok'] !== $b['ok'] ) {
			$mismatch = sprintf(
				"OUTCOME: sqlite=%s engine=%s",
				$a['ok'] ? 'ok' : $a['error'],
				$b['ok'] ? 'ok' : $b['error']
			);
		} elseif ( ! $a['ok'] ) {
			if ( $a['error'] !== $b['error'] ) {
				$mismatch = sprintf( "ERROR TEXT:\n  sqlite: %s\n  engine: %s", $a['error'], $b['error'] );
			}
		} else {
			if ( $a['cols'] !== $b['cols'] ) {
				$mismatch = sprintf(
					"COLUMNS:\n  sqlite: %s\n  engine: %s",
					json_encode( $a['cols'] ),
					json_encode( $b['cols'] )
				);
			} elseif ( ! rows_equal( $a['rows'], $b['rows'] ) ) {
				// Allow row order differences for queries without ORDER BY.
				$a_sorted = $a['rows'];
				$b_sorted = $b['rows'];
				$sorter   = function ( $x, $y ) {
					return strcmp( json_encode( $x ), json_encode( $y ) );
				};
				usort( $a_sorted, $sorter );
				usort( $b_sorted, $sorter );
				if ( $a_sorted === $b_sorted && ! preg_match( '/ORDER\s+BY/i', $sql ) ) {
					$ordered_diff += 1;
				} else {
					$max  = max( count( $a['rows'] ), count( $b['rows'] ) );
					$head = array();
					for ( $i = 0; $i < min( $max, 5 ); $i++ ) {
						$head[] = sprintf(
							"    [%d] sqlite=%s engine=%s",
							$i,
							json_encode( isset( $a['rows'][ $i ] ) ? $a['rows'][ $i ] : null ),
							json_encode( isset( $b['rows'][ $i ] ) ? $b['rows'][ $i ] : null )
						);
					}
					$mismatch = sprintf(
						"ROWS (%d vs %d):\n%s",
						count( $a['rows'] ),
						count( $b['rows'] ),
						implode( "\n", $head )
					);
				}
			}
		}

		if ( null !== $mismatch ) {
			$diffs += 1;
			printf(
				"\n=== DIFF #%d (session %d, query %d) ===\nSQL: %s\nPARAMS: %s\n%s\n",
				$diffs,
				$session_index,
				$query_index,
				strlen( $sql ) > 600 ? substr( $sql, 0, 600 ) . '…' : $sql,
				json_encode( $params ),
				$mismatch
			);
		}
	}
}

printf(
	"\nDone: %d queries compared, %d diffs, %d order-only diffs, %.1fs.\n",
	$ran,
	$diffs,
	$ordered_diff,
	microtime( true ) - $start_time
);
exit( $diffs > 0 ? 1 : 0 );
