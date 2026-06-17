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

$integer_string_table = $wpdb->prefix . 'sqlancer_integer_string_t0';
sdi_sqlancer_query( "DROP TABLE IF EXISTS $integer_string_table" );
sdi_sqlancer_query( "CREATE TABLE $integer_string_table(c0 BIGINT(154) UNIQUE KEY)" );
sdi_sqlancer_query( "REPLACE LOW_PRIORITY INTO $integer_string_table(c0) VALUES(\\"0.690236950119983\\")" );
$integer_string_row = $wpdb->get_row( "SELECT c0 FROM $integer_string_table", ARRAY_A );

$decimal_scale_table = $wpdb->prefix . 'sqlancer_decimal_scale_t0';
sdi_sqlancer_query( "DROP TABLE IF EXISTS $decimal_scale_table" );
sdi_sqlancer_query( "CREATE TABLE $decimal_scale_table(c0 DECIMAL COMMENT 'asdf' COLUMN_FORMAT DYNAMIC UNIQUE PRIMARY KEY STORAGE MEMORY)" );
sdi_sqlancer_query( "DROP INDEX c0 ON $decimal_scale_table" );
sdi_sqlancer_query( "INSERT DELAYED IGNORE INTO $decimal_scale_table(c0) VALUES(901185469)" );
sdi_sqlancer_query( "DELETE LOW_PRIORITY FROM $decimal_scale_table WHERE (! ( EXISTS (SELECT 1 WHERE FALSE)))" );
sdi_sqlancer_query( "REPLACE LOW_PRIORITY INTO $decimal_scale_table(c0) VALUES(\\"0.04610308300972621\\")" );
sdi_sqlancer_query( "INSERT IGNORE INTO $decimal_scale_table(c0) VALUES(0.38956910632549635)" );
$decimal_scale_row = $wpdb->get_row( "SELECT COUNT(*) AS rows_count, c0 FROM $decimal_scale_table GROUP BY c0", ARRAY_A );
sdi_sqlancer_query( "UPDATE $decimal_scale_table SET c0=-271461335" );
$decimal_scale_after_update_row = $wpdb->get_row( "SELECT c0 FROM $decimal_scale_table", ARRAY_A );

$heap_decimal_table = $wpdb->prefix . 'sqlancer_heap_decimal_replace';
sdi_sqlancer_query( "DROP TABLE IF EXISTS $heap_decimal_table" );
sdi_sqlancer_query( "CREATE TABLE $heap_decimal_table(c0 DECIMAL COLUMN_FORMAT FIXED STORAGE MEMORY COMMENT 'asdf' UNIQUE KEY) ENGINE = HEAP" );
sdi_sqlancer_query( "REPLACE DELAYED INTO $heap_decimal_table(c0) VALUES(652769770), (''), (NULL)" );
$heap_decimal_row = $wpdb->get_row( "SELECT COUNT(*) AS row_count, SUM(c0 = 0) AS zero_rows, SUM(c0 IS NULL) AS null_rows, MAX(c0) AS max_value FROM $heap_decimal_table", ARRAY_A );

$unsigned_zerofill_table = $wpdb->prefix . 'sqlancer_unsigned_zerofill_t0';
sdi_sqlancer_query( "DROP TABLE IF EXISTS $unsigned_zerofill_table" );
sdi_sqlancer_query( "CREATE TABLE $unsigned_zerofill_table(c0 DOUBLE ZEROFILL UNIQUE, c1 FLOAT, c2 DECIMAL UNIQUE KEY)" );
sdi_sqlancer_query( "INSERT IGNORE INTO $unsigned_zerofill_table(c1, c0) VALUES(-255822003, \\"-1773731655\\"), (0.3800962993552307, NULL), (NULL, \\"¹\\"), (802484078, \\"&g瞟Xx8-U\\"), (1992718239, \\"\\")" );
sdi_sqlancer_query( "INSERT LOW_PRIORITY IGNORE INTO $unsigned_zerofill_table(c1, c0) VALUES(NULL, 13195222)" );
$unsigned_zerofill_row = $wpdb->get_row( "SELECT COUNT(*) AS rows_count, SUM(c0 = 0) AS zero_rows, SUM(c0 < 0) AS negative_rows, SUM(c0 = 13195222) AS positive_rows FROM $unsigned_zerofill_table", ARRAY_A );
sdi_sqlancer_query( "UPDATE $unsigned_zerofill_table SET c2=0.3350118396679408, c1=8.02484078E8 WHERE $unsigned_zerofill_table.c0" );
$unsigned_zerofill_after_update_row = $wpdb->get_row( "SELECT COUNT(*) AS updated_rows, MAX(c2) AS c2 FROM $unsigned_zerofill_table WHERE c2 IS NOT NULL", ARRAY_A );

$signed_smallint_table = $wpdb->prefix . 'sqlancer_signed_smallint_sum';
sdi_sqlancer_query( "DROP TABLE IF EXISTS $signed_smallint_table" );
sdi_sqlancer_query( "CREATE TABLE $signed_smallint_table(c0 SMALLINT(107) COLUMN_FORMAT DYNAMIC PRIMARY KEY UNIQUE KEY)" );
sdi_sqlancer_query( "INSERT IGNORE INTO $signed_smallint_table(c0) VALUES(1375461291), (-627010191), (32190009), (0.7902617242915789), ('-1e500'), ('2jc7hoh\r'), (2052592843)" );
$signed_smallint_row = $wpdb->get_row( "SELECT GROUP_CONCAT(c0 ORDER BY c0) AS saved_values, SUM(c0) AS sum_value, SUM(DISTINCT c0) AS sum_distinct_value, COUNT(*) AS row_count FROM $signed_smallint_table", ARRAY_A );

$memory_default_table = $wpdb->prefix . 'sqlancer_memory_implicit_default';
sdi_sqlancer_query( "DROP TABLE IF EXISTS $memory_default_table" );
sdi_sqlancer_query( "CREATE TABLE $memory_default_table(c0 BIGINT ZEROFILL COMMENT 'asdf' COLUMN_FORMAT DYNAMIC PRIMARY KEY) ENGINE = MEMORY, AUTO_INCREMENT = 4115509118782610296" );
sdi_sqlancer_query( "REPLACE INTO $memory_default_table(c0) VALUES(0.5986269975342084), (4.1155091187826104E18), (NULL)" );
$memory_default_rows = $wpdb->get_results( "SELECT c0 FROM $memory_default_table ORDER BY c0", ARRAY_A );
sdi_sqlancer_query( "REPLACE INTO $memory_default_table(c0) VALUES(0.5853108370608123), (\\"4115509118782610296\\"), (1862704922), (\\"0.27004938366761855\\"), (\\"dwHq\\")" );

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

$renamed_functional_index_table = $wpdb->prefix . 'sqlancer_renamed_functional_index_t0';
$renamed_functional_index_target = $wpdb->prefix . 'sqlancer_renamed_functional_index_t2';
sdi_sqlancer_query( "DROP TABLE IF EXISTS $renamed_functional_index_table" );
sdi_sqlancer_query( "DROP TABLE IF EXISTS $renamed_functional_index_target" );
sdi_sqlancer_query( "CREATE TABLE $renamed_functional_index_table(c0 DECIMAL UNIQUE)" );
sdi_sqlancer_query( "CREATE INDEX i_sqlancer_renamed_functional USING HASH ON $renamed_functional_index_table((((CASE 0 WHEN $renamed_functional_index_table.c0 THEN ('tx') LIKE ($renamed_functional_index_table.c0) ELSE $renamed_functional_index_table.c0 END))))" );
sdi_sqlancer_query( "ALTER TABLE $renamed_functional_index_table FORCE, RENAME $renamed_functional_index_target" );
sdi_sqlancer_query( "TRUNCATE TABLE $renamed_functional_index_target" );
sdi_sqlancer_query( "ALTER TABLE $renamed_functional_index_target COMPRESSION 'LZ4', PACK_KEYS 0, INSERT_METHOD NO, RENAME $renamed_functional_index_table, STATS_AUTO_RECALC DEFAULT, FORCE, ROW_FORMAT DYNAMIC, ALGORITHM INPLACE, DELAY_KEY_WRITE 1, CHECKSUM 0" );
$renamed_functional_index_row = $wpdb->get_row( "SHOW INDEX FROM $renamed_functional_index_table WHERE Key_name = 'i_sqlancer_renamed_functional'", ARRAY_A );

$prefix_index_table = $wpdb->prefix . 'sqlancer_prefix_index_t0';
sdi_sqlancer_query( "DROP TABLE IF EXISTS $prefix_index_table" );
sdi_sqlancer_query( "CREATE TABLE $prefix_index_table(c0 MEDIUMTEXT COMMENT 'asdf')" );
sdi_sqlancer_query( "CREATE UNIQUE INDEX i_sqlancer_prefix_3 ON $prefix_index_table(c0(3) ASC) VISIBLE ALGORITHM= COPY" );
sdi_sqlancer_query( "CREATE UNIQUE INDEX i_sqlancer_prefix_2 ON $prefix_index_table(c0(2) DESC) ALGORITHM= COPY" );
sdi_sqlancer_query( "INSERT DELAYED INTO $prefix_index_table(c0) VALUES(0.6904897792105997)" );
sdi_sqlancer_query( "REPLACE INTO $prefix_index_table(c0) VALUES(0.7092344227870846)" );
sdi_sqlancer_query( "DROP INDEX i_sqlancer_prefix_2 ON $prefix_index_table ALGORITHM=DEFAULT" );
sdi_sqlancer_query( "INSERT LOW_PRIORITY INTO $prefix_index_table(c0) VALUES(0.6904897792105997)" );
$prefix_index_row = $wpdb->get_row( "SELECT COUNT(*) AS rows_count, GROUP_CONCAT(SUBSTR(c0, 1, 3) ORDER BY c0) AS saved_prefixes FROM $prefix_index_table", ARRAY_A );

$literal_index_table = $wpdb->prefix . 'sqlancer_literal_index';
sdi_sqlancer_query( "DROP TABLE IF EXISTS $literal_index_table" );
sdi_sqlancer_query( "CREATE TABLE $literal_index_table(c1 MEDIUMINT UNIQUE KEY COLUMN_FORMAT DEFAULT COMMENT 'asdf')" );
sdi_sqlancer_query( "CREATE UNIQUE INDEX i_sqlancer_literal ON $literal_index_table(('+4')) VISIBLE" );
$literal_index_row = $wpdb->get_row( "SHOW INDEX FROM $literal_index_table WHERE Key_name = 'i_sqlancer_literal'", ARRAY_A );

$redundant_index_table = $wpdb->prefix . 'sqlancer_redundant_index';
sdi_sqlancer_query( "DROP TABLE IF EXISTS $redundant_index_table" );
sdi_sqlancer_query( "CREATE TABLE $redundant_index_table(c0 DECIMAL ZEROFILL PRIMARY KEY UNIQUE KEY COMMENT 'asdf' NOT NULL COLUMN_FORMAT FIXED STORAGE DISK)" );
sdi_sqlancer_query( "DROP INDEX c0 ON $redundant_index_table ALGORITHM=DEFAULT LOCK=DEFAULT" );
$redundant_index_rows = $wpdb->get_results( "SHOW INDEX FROM $redundant_index_table", ARRAY_A );

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

$rename_index_table = $wpdb->prefix . 'sqlancer_rename_index_t0';
$renamed_index_table = $wpdb->prefix . 'sqlancer_rename_index_t4';
sdi_sqlancer_query( "DROP TABLE IF EXISTS $rename_index_table" );
sdi_sqlancer_query( "DROP TABLE IF EXISTS $renamed_index_table" );
sdi_sqlancer_query( "CREATE TABLE $rename_index_table(c0 DOUBLE UNIQUE NULL, c1 FLOAT NULL UNIQUE KEY, c2 MEDIUMTEXT)" );
sdi_sqlancer_query( "ALTER TABLE $rename_index_table RENAME TO $renamed_index_table" );
sdi_sqlancer_query( "DROP INDEX c1 ON $renamed_index_table LOCK=SHARED" );
$rename_drop_index_row = $wpdb->get_row( "SHOW INDEX FROM $renamed_index_table WHERE Key_name = 'c1'", ARRAY_A );

$cast_table = $wpdb->prefix . 'sqlancer_cast_signed';
sdi_sqlancer_query( "DROP TABLE IF EXISTS $cast_table" );
sdi_sqlancer_query( "CREATE TABLE $cast_table(c0 SMALLINT UNIQUE KEY)" );
sdi_sqlancer_query( "INSERT INTO $cast_table(c0) VALUES(NULL)" );
$cast_row = $wpdb->get_row( "SELECT CAST(0.8338761836534807 AS SIGNED) AS rounded_real, CAST('0.8338761836534807' AS SIGNED) AS truncated_text, ( EXISTS (SELECT 1 WHERE FALSE)) IN (CAST(IFNULL($cast_table.c0, 0.8338761836534807) AS SIGNED)) AS predicate_value FROM $cast_table", ARRAY_A );
sdi_sqlancer_query( "UPDATE $cast_table SET c0=\\"\\" WHERE ( EXISTS (SELECT 1 WHERE FALSE)) IN (CAST(IFNULL($cast_table.c0, 0.8338761836534807) AS SIGNED))" );
$cast_after_update_row = $wpdb->get_row( "SELECT c0 FROM $cast_table", ARRAY_A );

$if_truthiness_table = $wpdb->prefix . 'sqlancer_if_truthiness';
sdi_sqlancer_query( "DROP TABLE IF EXISTS $if_truthiness_table" );
sdi_sqlancer_query( "CREATE TABLE $if_truthiness_table(c0 DECIMAL PRIMARY KEY NOT NULL)" );
sdi_sqlancer_query( "INSERT INTO $if_truthiness_table(c0) VALUES(1), (2)" );
sdi_sqlancer_query( "CREATE UNIQUE INDEX i_sqlancer_if_truthiness ON $if_truthiness_table((IF((- (732094579)), CAST(NULL AS SIGNED), $if_truthiness_table.c0))) ALGORITHM COPY" );
$if_truthiness_row = $wpdb->get_row( "SELECT COUNT(*) AS rows_count, IF((- (732094579)), CAST(NULL AS SIGNED), 9) AS negative_truthy, IF(0, 1, 2) AS zero_falsy, IF(NULL, 1, 2) AS null_falsy, IF('1abc', 1, 2) AS leading_numeric_truthy, IF('abc', 1, 2) AS non_numeric_falsy FROM $if_truthiness_table", ARRAY_A );

echo 'SQLANCER_JSON:' . wp_json_encode( $row ) . PHP_EOL;
echo 'SQLANCER_BIT_COUNT_JSON:' . wp_json_encode( $bit_count_row ) . PHP_EOL;
echo 'SQLANCER_BOOLEAN_JSON:' . wp_json_encode( $boolean_row ) . PHP_EOL;
echo 'SQLANCER_ORDER_NEGATIVE_JSON:' . wp_json_encode( $order_negative_row ) . PHP_EOL;
echo 'SQLANCER_ORDERING_EXPRESSION_JSON:' . wp_json_encode( $ordering_expression_row ) . PHP_EOL;
echo 'SQLANCER_INDEX_JSON:' . wp_json_encode( $index_row ) . PHP_EOL;
echo 'SQLANCER_RENAMED_FUNCTIONAL_INDEX_JSON:' . wp_json_encode( $renamed_functional_index_row ) . PHP_EOL;
echo 'SQLANCER_PREFIX_INDEX_JSON:' . wp_json_encode( $prefix_index_row ) . PHP_EOL;
echo 'SQLANCER_LITERAL_INDEX_JSON:' . wp_json_encode( $literal_index_row ) . PHP_EOL;
echo 'SQLANCER_REDUNDANT_INDEX_JSON:' . wp_json_encode( $redundant_index_rows ) . PHP_EOL;
echo 'SQLANCER_COUNT_DISTINCT_JSON:' . wp_json_encode( $count_distinct_row ) . PHP_EOL;
echo 'SQLANCER_RENAME_JSON:' . wp_json_encode( $rename_row ) . PHP_EOL;
echo 'SQLANCER_RENAME_DROP_INDEX_JSON:' . wp_json_encode( $rename_drop_index_row ) . PHP_EOL;
echo 'SQLANCER_CAST_SIGNED_JSON:' . wp_json_encode( $cast_row ) . PHP_EOL;
echo 'SQLANCER_CAST_SIGNED_AFTER_UPDATE_JSON:' . wp_json_encode( $cast_after_update_row ) . PHP_EOL;
echo 'SQLANCER_IF_TRUTHINESS_JSON:' . wp_json_encode( $if_truthiness_row ) . PHP_EOL;
echo 'SQLANCER_INTEGER_STRING_JSON:' . wp_json_encode( $integer_string_row ) . PHP_EOL;
echo 'SQLANCER_DECIMAL_SCALE_JSON:' . wp_json_encode( $decimal_scale_row ) . PHP_EOL;
echo 'SQLANCER_DECIMAL_SCALE_AFTER_UPDATE_JSON:' . wp_json_encode( $decimal_scale_after_update_row ) . PHP_EOL;
echo 'SQLANCER_HEAP_DECIMAL_JSON:' . wp_json_encode( $heap_decimal_row ) . PHP_EOL;
echo 'SQLANCER_UNSIGNED_ZEROFILL_JSON:' . wp_json_encode( $unsigned_zerofill_row ) . PHP_EOL;
echo 'SQLANCER_UNSIGNED_ZEROFILL_AFTER_UPDATE_JSON:' . wp_json_encode( $unsigned_zerofill_after_update_row ) . PHP_EOL;
echo 'SQLANCER_SIGNED_SMALLINT_JSON:' . wp_json_encode( $signed_smallint_row ) . PHP_EOL;
echo 'SQLANCER_MEMORY_DEFAULT_JSON:' . wp_json_encode( $memory_default_rows ) . PHP_EOL;
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

		const integerStringJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) =>
				line.startsWith( 'SQLANCER_INTEGER_STRING_JSON:' )
			);

		expect( integerStringJsonLine ).toBeTruthy();
		expect(
			JSON.parse(
				integerStringJsonLine.replace(
					'SQLANCER_INTEGER_STRING_JSON:',
					''
				)
			)
		).toEqual( {
			c0: '1',
		} );

		const decimalScaleJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) =>
				line.startsWith( 'SQLANCER_DECIMAL_SCALE_JSON:' )
			);

		expect( decimalScaleJsonLine ).toBeTruthy();
		expect(
			JSON.parse(
				decimalScaleJsonLine.replace(
					'SQLANCER_DECIMAL_SCALE_JSON:',
					''
				)
			)
		).toEqual( {
			rows_count: '1',
			c0: '0',
		} );

		const decimalScaleAfterUpdateJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) =>
				line.startsWith(
					'SQLANCER_DECIMAL_SCALE_AFTER_UPDATE_JSON:'
				)
			);

		expect( decimalScaleAfterUpdateJsonLine ).toBeTruthy();
		expect(
			JSON.parse(
				decimalScaleAfterUpdateJsonLine.replace(
					'SQLANCER_DECIMAL_SCALE_AFTER_UPDATE_JSON:',
					''
				)
			)
		).toEqual( {
			c0: '-271461335',
		} );

		const heapDecimalJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) =>
				line.startsWith( 'SQLANCER_HEAP_DECIMAL_JSON:' )
			);

		expect( heapDecimalJsonLine ).toBeTruthy();
		expect(
			JSON.parse(
				heapDecimalJsonLine.replace(
					'SQLANCER_HEAP_DECIMAL_JSON:',
					''
				)
			)
		).toEqual( {
			row_count: '3',
			zero_rows: '1',
			null_rows: '1',
			max_value: '652769770',
		} );

		const unsignedZerofillJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) =>
				line.startsWith( 'SQLANCER_UNSIGNED_ZEROFILL_JSON:' )
			);

		expect( unsignedZerofillJsonLine ).toBeTruthy();
		expect(
			JSON.parse(
				unsignedZerofillJsonLine.replace(
					'SQLANCER_UNSIGNED_ZEROFILL_JSON:',
					''
				)
			)
		).toEqual( {
			rows_count: '3',
			zero_rows: '1',
			negative_rows: '0',
			positive_rows: '1',
		} );

		const unsignedZerofillAfterUpdateJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) =>
				line.startsWith(
					'SQLANCER_UNSIGNED_ZEROFILL_AFTER_UPDATE_JSON:'
				)
			);

		expect( unsignedZerofillAfterUpdateJsonLine ).toBeTruthy();
		expect(
			JSON.parse(
				unsignedZerofillAfterUpdateJsonLine.replace(
					'SQLANCER_UNSIGNED_ZEROFILL_AFTER_UPDATE_JSON:',
					''
				)
			)
		).toEqual( {
			updated_rows: '1',
			c2: '0',
		} );

		const signedSmallintJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) =>
				line.startsWith( 'SQLANCER_SIGNED_SMALLINT_JSON:' )
			);

		expect( signedSmallintJsonLine ).toBeTruthy();
		expect(
			JSON.parse(
				signedSmallintJsonLine.replace(
					'SQLANCER_SIGNED_SMALLINT_JSON:',
					''
				)
			)
		).toEqual( {
			saved_values: '-32768,1,2,32767',
			sum_value: '2',
			sum_distinct_value: '2',
			row_count: '4',
		} );

		const memoryDefaultJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) =>
				line.startsWith( 'SQLANCER_MEMORY_DEFAULT_JSON:' )
			);

		expect( memoryDefaultJsonLine ).toBeTruthy();
		expect(
			JSON.parse(
				memoryDefaultJsonLine.replace(
					'SQLANCER_MEMORY_DEFAULT_JSON:',
					''
				)
			)
		).toEqual( [
			{
				c0: '0',
			},
			{
				c0: '1',
			},
			{
				c0: '4115509118782610432',
			},
		] );

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

		const renamedFunctionalIndexJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) =>
				line.startsWith( 'SQLANCER_RENAMED_FUNCTIONAL_INDEX_JSON:' )
			);

		expect( renamedFunctionalIndexJsonLine ).toBeTruthy();
		const renamedFunctionalIndexRow = JSON.parse(
			renamedFunctionalIndexJsonLine.replace(
				'SQLANCER_RENAMED_FUNCTIONAL_INDEX_JSON:',
				''
			)
		);
		expect( renamedFunctionalIndexRow ).toEqual(
			expect.objectContaining( {
				Key_name: 'i_sqlancer_renamed_functional',
				Column_name: null,
			} )
		);
		expect( renamedFunctionalIndexRow.Expression ).toContain(
			'sqlancer_renamed_functional_index_t0 . c0'
		);
		expect( renamedFunctionalIndexRow.Expression ).not.toContain(
			'sqlancer_renamed_functional_index_t2 . c0'
		);

		const prefixIndexJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) =>
				line.startsWith( 'SQLANCER_PREFIX_INDEX_JSON:' )
			);

		expect( prefixIndexJsonLine ).toBeTruthy();
		expect(
			JSON.parse(
				prefixIndexJsonLine.replace(
					'SQLANCER_PREFIX_INDEX_JSON:',
					''
				)
			)
		).toEqual( {
			rows_count: '2',
			saved_prefixes: '0.6,0.7',
		} );

		const literalIndexJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) =>
				line.startsWith( 'SQLANCER_LITERAL_INDEX_JSON:' )
			);

		expect( literalIndexJsonLine ).toBeTruthy();
		expect(
			JSON.parse(
				literalIndexJsonLine.replace(
					'SQLANCER_LITERAL_INDEX_JSON:',
					''
				)
			)
		).toEqual(
			expect.objectContaining( {
				Key_name: 'i_sqlancer_literal',
				Column_name: null,
				Expression: "'+4'",
			} )
		);

		const redundantIndexJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) =>
				line.startsWith( 'SQLANCER_REDUNDANT_INDEX_JSON:' )
			);

		expect( redundantIndexJsonLine ).toBeTruthy();
		expect(
			JSON.parse(
				redundantIndexJsonLine.replace(
					'SQLANCER_REDUNDANT_INDEX_JSON:',
					''
				)
			)
		).toEqual( [
			expect.objectContaining( {
				Key_name: 'PRIMARY',
				Column_name: 'c0',
			} ),
		] );

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

		const renameDropIndexJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) =>
				line.startsWith( 'SQLANCER_RENAME_DROP_INDEX_JSON:' )
			);

		expect( renameDropIndexJsonLine ).toBeTruthy();
		expect(
			JSON.parse(
				renameDropIndexJsonLine.replace(
					'SQLANCER_RENAME_DROP_INDEX_JSON:',
					''
				)
			)
		).toBeNull();

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

		const ifTruthinessJsonLine = output
			.trim()
			.split( /\r?\n/ )
			.find( ( line ) =>
				line.startsWith( 'SQLANCER_IF_TRUTHINESS_JSON:' )
			);

		expect( ifTruthinessJsonLine ).toBeTruthy();
		expect(
			JSON.parse(
				ifTruthinessJsonLine.replace(
					'SQLANCER_IF_TRUTHINESS_JSON:',
					''
				)
			)
		).toEqual( {
			rows_count: '2',
			negative_truthy: null,
			zero_falsy: '2',
			null_falsy: '2',
			leading_numeric_truthy: '1',
			non_numeric_falsy: '2',
		} );
		} );
	} );
