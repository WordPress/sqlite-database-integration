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
sdi_sqlancer_query( "SELECT -272848287 AS ref0 FROM $table GROUP BY -272848287" );

$order_table = $wpdb->prefix . 'sqlancer_order_negative';
sdi_sqlancer_query( "DROP TABLE IF EXISTS $order_table" );
sdi_sqlancer_query( "CREATE TABLE $order_table(c0 INT)" );
sdi_sqlancer_query( "INSERT INTO $order_table(c0) VALUES(NULL)" );
$order_negative_row = $wpdb->get_row( "SELECT (+ ( EXISTS (SELECT 1))) AS ref0 FROM $order_table WHERE (+ (BIT_COUNT(1371172065))) GROUP BY (+ ( EXISTS (SELECT 1))) ORDER BY -1173568737 LIMIT 4374681039449100574", ARRAY_A );
$ordering_expression_row = $wpdb->get_row( "SELECT DISTINCTROW NULL AS ref0, (- (-681681867)) AS ref1, MAX(CAST(CAST((NULL) IS NOT FALSE AS SIGNED) AS SIGNED)) AS ref2 FROM $order_table GROUP BY NULL, (- (-681681867))", ARRAY_A );

$index_table = $wpdb->prefix . 'sqlancer_expr_index';
sdi_sqlancer_query( "DROP TABLE IF EXISTS $index_table" );
sdi_sqlancer_query( "CREATE TABLE $index_table(c0 DOUBLE, c1 DOUBLE)" );
sdi_sqlancer_query( "CREATE INDEX i0 ON $index_table(((IF(NULL, $index_table.c1, $index_table.c0)))) ALGORITHM DEFAULT" );
sdi_sqlancer_query( "ALTER TABLE $index_table DISABLE KEYS" );
$index_row = $wpdb->get_row( "SHOW INDEX FROM $index_table WHERE Key_name = 'i0'", ARRAY_A );

$count_distinct_table = $wpdb->prefix . 'sqlancer_count_distinct';
sdi_sqlancer_query( "DROP TABLE IF EXISTS $count_distinct_table" );
sdi_sqlancer_query( "CREATE TABLE $count_distinct_table(c0 INT, c1 TEXT)" );
sdi_sqlancer_query( "INSERT INTO $count_distinct_table(c0, c1) VALUES(1, 'a'), (1, 'a'), (1, 'b'), (NULL, 'b'), (2, NULL)" );
$count_distinct_row = $wpdb->get_row( "SELECT COUNT(DISTINCT c0, c1) AS tuple_count FROM $count_distinct_table", ARRAY_A );

$rename_table = $wpdb->prefix . 'sqlancer_rename_t0';
$renamed_table = $wpdb->prefix . 'sqlancer_rename_t2';
sdi_sqlancer_query( "DROP TABLE IF EXISTS $rename_table" );
sdi_sqlancer_query( "DROP TABLE IF EXISTS $renamed_table" );
sdi_sqlancer_query( "CREATE TABLE $rename_table(c0 DOUBLE)" );
sdi_sqlancer_query( "INSERT INTO $rename_table(c0) VALUES(1565814287)" );
sdi_sqlancer_query( "ALTER TABLE $rename_table STATS_PERSISTENT 0, RENAME $renamed_table, FORCE, ROW_FORMAT COMPACT" );
$rename_row = $wpdb->get_row( "SELECT COUNT(*) AS rows_count FROM $renamed_table", ARRAY_A );
sdi_sqlancer_query( "ALTER TABLE $renamed_table FORCE, ROW_FORMAT DEFAULT, COMPRESSION 'LZ4', INSERT_METHOD NO, PACK_KEYS 0, CHECKSUM 0, ALGORITHM COPY, RENAME TO $rename_table" );

$cast_table = $wpdb->prefix . 'sqlancer_cast_signed';
sdi_sqlancer_query( "DROP TABLE IF EXISTS $cast_table" );
sdi_sqlancer_query( "CREATE TABLE $cast_table(c0 SMALLINT UNIQUE KEY)" );
sdi_sqlancer_query( "INSERT INTO $cast_table(c0) VALUES(NULL)" );
$cast_row = $wpdb->get_row( "SELECT CAST(0.8338761836534807 AS SIGNED) AS rounded_real, CAST('0.8338761836534807' AS SIGNED) AS truncated_text, ( EXISTS (SELECT 1 WHERE FALSE)) IN (CAST(IFNULL($cast_table.c0, 0.8338761836534807) AS SIGNED)) AS predicate_value FROM $cast_table", ARRAY_A );
sdi_sqlancer_query( "UPDATE $cast_table SET c0=\\"\\" WHERE ( EXISTS (SELECT 1 WHERE FALSE)) IN (CAST(IFNULL($cast_table.c0, 0.8338761836534807) AS SIGNED))" );
$cast_after_update_row = $wpdb->get_row( "SELECT c0 FROM $cast_table", ARRAY_A );

echo 'SQLANCER_JSON:' . wp_json_encode( $row ) . PHP_EOL;
echo 'SQLANCER_BIT_COUNT_JSON:' . wp_json_encode( $bit_count_row ) . PHP_EOL;
echo 'SQLANCER_BOOLEAN_JSON:' . wp_json_encode( $boolean_row ) . PHP_EOL;
echo 'SQLANCER_ORDER_NEGATIVE_JSON:' . wp_json_encode( $order_negative_row ) . PHP_EOL;
echo 'SQLANCER_ORDERING_EXPRESSION_JSON:' . wp_json_encode( $ordering_expression_row ) . PHP_EOL;
echo 'SQLANCER_INDEX_JSON:' . wp_json_encode( $index_row ) . PHP_EOL;
echo 'SQLANCER_COUNT_DISTINCT_JSON:' . wp_json_encode( $count_distinct_row ) . PHP_EOL;
echo 'SQLANCER_RENAME_JSON:' . wp_json_encode( $rename_row ) . PHP_EOL;
echo 'SQLANCER_CAST_SIGNED_JSON:' . wp_json_encode( $cast_row ) . PHP_EOL;
echo 'SQLANCER_CAST_SIGNED_AFTER_UPDATE_JSON:' . wp_json_encode( $cast_after_update_row ) . PHP_EOL;
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

		const orderNegativeJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) =>
				line.startsWith( 'SQLANCER_ORDER_NEGATIVE_JSON:' )
			);

		expect( orderNegativeJsonLine ).toBeTruthy();
		expect(
			JSON.parse(
				orderNegativeJsonLine.replace(
					'SQLANCER_ORDER_NEGATIVE_JSON:',
					''
				)
			)
		).toEqual( {
			ref0: '1',
		} );

		const orderingExpressionJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) =>
				line.startsWith( 'SQLANCER_ORDERING_EXPRESSION_JSON:' )
			);

		expect( orderingExpressionJsonLine ).toBeTruthy();
		expect(
			JSON.parse(
				orderingExpressionJsonLine.replace(
					'SQLANCER_ORDERING_EXPRESSION_JSON:',
					''
				)
			)
		).toEqual( {
			ref0: null,
			ref1: '681681867',
			ref2: '1',
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

		const countDistinctJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) =>
				line.startsWith( 'SQLANCER_COUNT_DISTINCT_JSON:' )
			);

		expect( countDistinctJsonLine ).toBeTruthy();
		expect(
			JSON.parse(
				countDistinctJsonLine.replace(
					'SQLANCER_COUNT_DISTINCT_JSON:',
					''
				)
			)
		).toEqual( {
			tuple_count: '2',
		} );

		const renameJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) => line.startsWith( 'SQLANCER_RENAME_JSON:' ) );

		expect( renameJsonLine ).toBeTruthy();
		expect( JSON.parse( renameJsonLine.replace( 'SQLANCER_RENAME_JSON:', '' ) ) ).toEqual(
			{
				rows_count: '1',
			}
		);

		const castSignedJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) =>
				line.startsWith( 'SQLANCER_CAST_SIGNED_JSON:' )
			);

		expect( castSignedJsonLine ).toBeTruthy();
		expect(
			JSON.parse(
				castSignedJsonLine.replace(
					'SQLANCER_CAST_SIGNED_JSON:',
					''
				)
			)
		).toEqual( {
			rounded_real: '1',
			truncated_text: '0',
			predicate_value: '0',
		} );

		const castSignedAfterUpdateJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) =>
				line.startsWith(
					'SQLANCER_CAST_SIGNED_AFTER_UPDATE_JSON:'
				)
			);

		expect( castSignedAfterUpdateJsonLine ).toBeTruthy();
		expect(
			JSON.parse(
				castSignedAfterUpdateJsonLine.replace(
					'SQLANCER_CAST_SIGNED_AFTER_UPDATE_JSON:',
					''
				)
			)
		).toEqual( {
			c0: null,
		} );
		} );
	} );
