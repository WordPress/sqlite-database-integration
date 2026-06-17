<?php
/**
 * Format a SQLancer replay failure as a GitHub issue comment.
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
	'comment',
	'marker',
	'oracle',
	'seed',
	'num-queries',
	'max-generated-databases',
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

$failure_hash = substr(
	hash(
		'sha256',
		implode(
			"\n",
			array_merge(
				array(
					$failure_sql,
					$exception,
				),
				$sqlite_lines
			)
		)
	),
	0,
	20
);
$marker       = sprintf( '<!-- sqlancer-finding hash=%s -->', $failure_hash );

$entry = sprintf(
	"%s\n### %s seed %s line %s\n\n- Found: %s\n- Run: %s\n- Commit: `%s`\n- Settings: `SQLANCER_MYSQL_ORACLE=%s RANDOM_SEED=%s NUM_QUERIES=%s MAX_GENERATED_DATABASES=%s`\n\n```sql\n%s\n```\n\n```text\n%s\n```\n",
	$marker,
	$args['oracle'],
	$args['seed'],
	$failure_line,
	gmdate( 'Y-m-d H:i:s \U\T\C' ),
	$args['run-url'],
	$args['commit'],
	$args['oracle'],
	$args['seed'],
	$args['num-queries'],
	$args['max-generated-databases'],
	$failure_sql,
	$exception
);

if ( ! empty( $sqlite_lines ) ) {
	$entry .= "\nTranslated SQLite replay SQL:\n\n```sql\n" . implode( "\n", $sqlite_lines ) . "\n```\n";
}

$comment_dir = dirname( $args['comment'] );
if ( ! is_dir( $comment_dir ) ) {
	mkdir( $comment_dir, 0777, true );
}

$marker_dir = dirname( $args['marker'] );
if ( ! is_dir( $marker_dir ) ) {
	mkdir( $marker_dir, 0777, true );
}

file_put_contents( $args['comment'], $entry );
file_put_contents( $args['marker'], $marker . "\n" );

echo "Formatted SQLancer finding: $marker\n";
