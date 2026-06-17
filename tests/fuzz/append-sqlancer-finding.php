<?php
/**
 * Append a SQLancer replay failure to the shared findings queue.
 *
 * @package wp-sqlite-integration
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$args = array();
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 !== strpos( $arg, '--' ) || false === strpos( $arg, '=' ) ) {
		fwrite( STDERR, "Unknown argument: $arg\n" );
		exit( 1 );
	}

	list( $name, $value ) = explode( '=', substr( $arg, 2 ), 2 );
	$args[ $name ]        = $value;
}

$required = array(
	'output',
	'findings',
	'oracle',
	'seed',
	'num-queries',
	'max-generated-databases',
	'artifacts',
	'commit',
	'run-url',
);

foreach ( $required as $name ) {
	if ( ! isset( $args[ $name ] ) || '' === $args[ $name ] ) {
		fwrite( STDERR, "Missing required --$name option.\n" );
		exit( 1 );
	}
}

if ( ! is_readable( $args['output'] ) ) {
	fwrite( STDERR, "Runner output is not readable: {$args['output']}\n" );
	exit( 1 );
}

$lines        = file( $args['output'], FILE_IGNORE_NEW_LINES );
$failure_line = null;
$failure_sql  = null;
$exception    = null;
$sqlite_lines = array();

foreach ( $lines as $index => $line ) {
	if ( preg_match( '/^FAIL line ([0-9]+): (.*)$/', $line, $matches ) ) {
		$failure_line = $matches[1];
		$failure_sql  = $matches[2];
		$exception    = $lines[ $index + 1 ] ?? '';

		for ( $i = $index + 2; $i < count( $lines ); $i++ ) {
			if ( 0 !== strpos( $lines[ $i ], '  SQLITE: ' ) ) {
				break;
			}
			$sqlite_lines[] = substr( $lines[ $i ], 10 );
		}
		break;
	}
}

if ( null === $failure_line ) {
	fwrite( STDERR, "No replay failure was found in {$args['output']}.\n" );
	exit( 2 );
}

$marker = sprintf(
	'<!-- sqlancer-finding oracle=%s seed=%s line=%s -->',
	$args['oracle'],
	$args['seed'],
	$failure_line
);

$findings_path = $args['findings'];
$findings_dir  = dirname( $findings_path );
if ( ! is_dir( $findings_dir ) ) {
	mkdir( $findings_dir, 0777, true );
}

$contents = is_readable( $findings_path ) ? file_get_contents( $findings_path ) : '';
if ( false !== strpos( $contents, $marker ) ) {
	echo "Finding already recorded: $marker\n";
	exit( 0 );
}

if ( '' === $contents ) {
	$contents = "# SQLancer SQLite Findings\n\n";
}

$entry = sprintf(
	"\n## %s - %s seed %s line %s\n\n%s\n\n- Run: %s\n- Commit: `%s`\n- Settings: `SQLANCER_MYSQL_ORACLE=%s RANDOM_SEED=%s NUM_QUERIES=%s MAX_GENERATED_DATABASES=%s`\n- Artifacts directory: `%s`\n\n```sql\n%s\n```\n\n```text\n%s\n```\n",
	gmdate( 'Y-m-d H:i:s \U\T\C' ),
	$args['oracle'],
	$args['seed'],
	$failure_line,
	$marker,
	$args['run-url'],
	$args['commit'],
	$args['oracle'],
	$args['seed'],
	$args['num-queries'],
	$args['max-generated-databases'],
	$args['artifacts'],
	$failure_sql,
	$exception
);

if ( ! empty( $sqlite_lines ) ) {
	$entry .= "\n```sql\n" . implode( "\n", $sqlite_lines ) . "\n```\n";
}

file_put_contents( $findings_path, rtrim( $contents ) . "\n" . $entry );

echo "Recorded SQLancer finding: $marker\n";
