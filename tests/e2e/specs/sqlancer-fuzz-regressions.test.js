/**
 * External dependencies
 */
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const repoRoot = path.resolve(
	path.dirname( fileURLToPath( import.meta.url ) ),
	'../../..'
);
const wordpressPath = path.join( repoRoot, 'wordpress' );

test.describe( 'SQLancer fuzz regressions', () => {
	test( 'replays reduced INSERT and DELETE modifier failures', () => {
		const output = execFileSync(
			'npm',
			[
				'--prefix',
				wordpressPath,
				'run',
				'env:cli',
				'--',
				'eval',
				`
global $wpdb;

$table = $wpdb->prefix . 'sqlancer_t0';

function sdi_sqlancer_query( $sql ) {
	global $wpdb;

	$result = $wpdb->query( $sql );
	if ( false === $result ) {
		throw new RuntimeException( $wpdb->last_error . ' for query: ' . $sql );
	}
}

sdi_sqlancer_query( "DROP TABLE IF EXISTS $table" );
sdi_sqlancer_query( "CREATE TABLE $table(c0 DECIMAL ZEROFILL COLUMN_FORMAT DEFAULT)" );
sdi_sqlancer_query( "INSERT IGNORE INTO $table(c0) VALUES(\\"ds\\")" );

$row = $wpdb->get_row( "SELECT c0 + 0 AS numeric_value FROM $table", ARRAY_A );
if ( 0.0 !== (float) $row['numeric_value'] ) {
	throw new RuntimeException( 'Expected INSERT IGNORE invalid decimal value to coerce to zero.' );
}

sdi_sqlancer_query( "INSERT DELAYED IGNORE INTO $table(c0) VALUES(0.23742865885084252)" );
$row = $wpdb->get_row( "SELECT COUNT(*) AS rows_count FROM $table", ARRAY_A );
if ( '2' !== (string) $row['rows_count'] ) {
	throw new RuntimeException( 'Expected INSERT DELAYED IGNORE to insert a second row.' );
}

sdi_sqlancer_query( "DROP TABLE IF EXISTS $table" );
sdi_sqlancer_query( "CREATE TABLE $table(c0 DECIMAL)" );
sdi_sqlancer_query( "INSERT INTO $table(c0) VALUES(1), (2)" );
sdi_sqlancer_query( "DELETE IGNORE FROM $table WHERE c0 = 1" );

$row = $wpdb->get_row( "SELECT COUNT(*) AS rows_count, SUM(c0 + 0) AS numeric_sum FROM $table", ARRAY_A );
sdi_sqlancer_query( "DROP TABLE IF EXISTS $table" );
sdi_sqlancer_query( "CREATE TABLE $table(c0 INT(95) ZEROFILL UNIQUE KEY COMMENT 'asdf' STORAGE DISK COLUMN_FORMAT FIXED)" );
sdi_sqlancer_query( "INSERT INTO $table(c0) VALUES(1), (2)" );
sdi_sqlancer_query( "DELETE LOW_PRIORITY FROM $table WHERE BIT_COUNT(NULL)" );
sdi_sqlancer_query( "SET GLOBAL myisam_sort_buffer_size = 5931344759664966748" );
sdi_sqlancer_query( "REPLACE LOW_PRIORITY INTO $table(c0) VALUES(3), (4), (5)" );
sdi_sqlancer_query( "REPLACE INTO $table(c0) VALUES(NULL)" );

$bit_count_row = $wpdb->get_row( "SELECT DISTINCTROW COUNT(*) AS rows_count, BIT_COUNT(-1) AS negative_bits, MAX(GREATEST(NULL, c0)) AS greatest_with_null FROM $table", ARRAY_A );
$boolean_row = $wpdb->get_row( "SELECT (2 XOR 3) AS both_true, (1 XOR 0) AS one_true, (1 && 0) AS and_symbol, (! 1) AS not_symbol, (NULL IS UNKNOWN) AS null_unknown, (1 IS NOT UNKNOWN) AS one_not_unknown, (NULL XOR 1) AS null_xor", ARRAY_A );

$index_table = $wpdb->prefix . 'sqlancer_expr_index';
sdi_sqlancer_query( "DROP TABLE IF EXISTS $index_table" );
sdi_sqlancer_query( "CREATE TABLE $index_table(c0 DOUBLE, c1 DOUBLE)" );
sdi_sqlancer_query( "CREATE INDEX i0 ON $index_table(((IF(NULL, $index_table.c1, $index_table.c0)))) ALGORITHM DEFAULT" );
sdi_sqlancer_query( "ALTER TABLE $index_table DISABLE KEYS" );
$index_row = $wpdb->get_row( "SHOW INDEX FROM $index_table WHERE Key_name = 'i0'", ARRAY_A );

echo 'SQLANCER_JSON:' . wp_json_encode( $row ) . PHP_EOL;
echo 'SQLANCER_BIT_COUNT_JSON:' . wp_json_encode( $bit_count_row ) . PHP_EOL;
echo 'SQLANCER_BOOLEAN_JSON:' . wp_json_encode( $boolean_row ) . PHP_EOL;
echo 'SQLANCER_INDEX_JSON:' . wp_json_encode( $index_row ) . PHP_EOL;
`,
			],
			{
				cwd: repoRoot,
				encoding: 'utf8',
			}
		);

		const jsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) => line.startsWith( 'SQLANCER_JSON:' ) );

		expect( jsonLine ).toBeTruthy();
		expect( JSON.parse( jsonLine.replace( 'SQLANCER_JSON:', '' ) ) ).toEqual(
			{
				rows_count: '1',
				numeric_sum: '2',
			}
		);

		const bitCountJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) => line.startsWith( 'SQLANCER_BIT_COUNT_JSON:' ) );

		expect( bitCountJsonLine ).toBeTruthy();
		expect(
			JSON.parse(
				bitCountJsonLine.replace( 'SQLANCER_BIT_COUNT_JSON:', '' )
			)
		).toEqual( {
			rows_count: '6',
			negative_bits: '64',
			greatest_with_null: null,
		} );

		const booleanJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) => line.startsWith( 'SQLANCER_BOOLEAN_JSON:' ) );

		expect( booleanJsonLine ).toBeTruthy();
		expect(
			JSON.parse( booleanJsonLine.replace( 'SQLANCER_BOOLEAN_JSON:', '' ) )
		).toEqual( {
			both_true: '0',
			one_true: '1',
			and_symbol: '0',
			not_symbol: '0',
			null_unknown: '1',
			one_not_unknown: '1',
			null_xor: null,
		} );

		const indexJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) => line.startsWith( 'SQLANCER_INDEX_JSON:' ) );

		expect( indexJsonLine ).toBeTruthy();
		const indexRow = JSON.parse(
			indexJsonLine.replace( 'SQLANCER_INDEX_JSON:', '' )
		);
		expect( indexRow ).toEqual(
			expect.objectContaining( {
				Key_name: 'i0',
				Column_name: null,
			} )
		);
		expect( indexRow.Expression ).toContain( 'IF(NULL' );
		expect( indexRow.Expression ).toContain( 'sqlancer_expr_index . c1' );
		expect( indexRow.Expression ).toContain( 'sqlancer_expr_index . c0' );
		} );
	} );
