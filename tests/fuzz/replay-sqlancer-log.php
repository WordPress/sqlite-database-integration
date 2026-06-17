<?php
/**
 * Replay a SQLancer MySQL log against the SQLite driver.
 *
 * SQLancer logs can include statements that MySQL rejected. Pass those line
 * numbers with --skip-line=N after comparing against MySQL.
 *
 * @package wp-sqlite-integration
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$log_file   = null;
$skip_lines = array();

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--skip-line=' ) ) {
		$skip_lines[ (int) substr( $arg, strlen( '--skip-line=' ) ) ] = true;
		continue;
	}
	if ( null === $log_file ) {
		$log_file = $arg;
		continue;
	}
	fwrite( STDERR, "Unknown argument: $arg\n" );
	exit( 1 );
}

if ( null === $log_file || ! is_readable( $log_file ) ) {
	fwrite( STDERR, "Usage: php tests/fuzz/replay-sqlancer-log.php <log-file> [--skip-line=N ...]\n" );
	exit( 1 );
}

require_once dirname( __DIR__, 2 ) . '/packages/mysql-on-sqlite/tests/bootstrap.php';

$pdo_class = PHP_VERSION_ID >= 80400 ? PDO\SQLite::class : PDO::class;
$pdo       = new $pdo_class( 'sqlite::memory:' );
$driver    = new WP_SQLite_Driver(
	new WP_SQLite_Connection( array( 'pdo' => $pdo ) ),
	'wp'
);

$line_number = 0;
foreach ( file( $log_file, FILE_IGNORE_NEW_LINES ) as $line ) {
	++$line_number;

	$sql = sqlancer_log_line_to_sql( $line );
	if ( null === $sql ) {
		continue;
	}

	if (
		isset( $skip_lines[ $line_number ] )
		|| preg_match( '/^(DROP DATABASE|CREATE DATABASE|USE)\b/i', $sql )
	) {
		printf( "SKIP line %d: %s\n", $line_number, $sql );
		continue;
	}

	try {
		$driver->query( $sql );
		printf( "OK line %d: %s\n", $line_number, $sql );
	} catch ( Throwable $e ) {
		fprintf( STDERR, "FAIL line %d: %s\n", $line_number, $sql );
		fprintf( STDERR, "%s: %s\n", get_class( $e ), $e->getMessage() );
		foreach ( $driver->get_last_sqlite_queries() as $query ) {
			fprintf(
				STDERR,
				"  SQLITE: %s PARAMS=%s\n",
				$query['sql'],
				json_encode( $query['params'] )
			);
		}
		exit( 1 );
	}
}

echo "REPLAY_OK\n";

/**
 * Extract SQL from a SQLancer log line.
 *
 * @param string $line Log line.
 * @return string|null SQL statement, or null for metadata/blank lines.
 */
function sqlancer_log_line_to_sql( $line ) {
	$line = trim( $line );
	if ( '' === $line || 0 === strpos( $line, '--' ) ) {
		return null;
	}

	return preg_replace( '/;\s*--\s*\d+ms;?$/', ';', $line );
}
