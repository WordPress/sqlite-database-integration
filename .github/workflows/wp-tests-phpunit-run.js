/*
 * Wrap the "composer run wp-tests-php" command to process tests
 * that are expected to error and fail at the moment.
 *
 * Unexpected errors/failures still fail the workflow. Expected failures that
 * stop happening are reported so this allowlist can be reduced over time.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );

const repoRoot = path.join( __dirname, '..', '..' );
const wrapperStartedAt = Date.now();
const requiresNativeParserExtension = process.env.WP_SQLITE_REQUIRE_NATIVE_PARSER_EXTENSION === '1';
const phpunitCommand = process.env.WP_SQLITE_PHPUNIT_COMMAND || 'composer run wp-test-php -- --log-junit=phpunit-results.xml --verbose';
const isDuckDBPhpunitRun = phpunitCommand.includes( 'wp-test-php-duckdb' );
const phpunitEnsureEnvironmentCommand = process.env.WP_SQLITE_PHPUNIT_ENSURE_ENV_COMMAND || getDefaultEnsureEnvironmentCommand();
const phpunitMaxSeconds = getPositiveNumberEnv( 'WP_SQLITE_PHPUNIT_MAX_SECONDS' );
const phpunitBaselineSeconds = getPositiveNumberEnv( 'WP_SQLITE_PHPUNIT_BASELINE_SECONDS' );
const phpunitMinTests = getPositiveNumberEnv( 'WP_SQLITE_PHPUNIT_MIN_TESTS' );
const phpunitTimingLabel = process.env.WP_SQLITE_PHPUNIT_TIMING_LABEL || ( isDuckDBPhpunitRun ? 'duckdb' : 'sqlite' );
const ensurePhpunitCompatibility = process.env.WP_SQLITE_ENSURE_PHPUNIT_COMPATIBILITY === '1';
const phpunitCompatibilityConstraint = process.env.WP_SQLITE_PHPUNIT_COMPATIBILITY_CONSTRAINT || '^9.6';
const skipPhpunitCompatibilityCheck = process.env.WP_SQLITE_SKIP_PHPUNIT_COMPATIBILITY_CHECK === '1';
const ignoreMissingExpectedResults = process.env.WP_SQLITE_IGNORE_MISSING_EXPECTED_RESULTS === '1';
const disableExpectedResults = process.env.WP_SQLITE_DISABLE_EXPECTED_RESULTS === '1';
const junitOutputPath = process.env.WP_SQLITE_PHPUNIT_JUNIT_PATH || 'wordpress/phpunit-results.xml';
const junitOutputFile = path.isAbsolute( junitOutputPath )
	? junitOutputPath
	: path.join( repoRoot, junitOutputPath );
const phpunitCompatibilityPrependPath = path.join( repoRoot, 'wordpress', 'phpunit-runner-compat-prepend.php' );
const duckdbAutoloadCompatibilityWrapperPath = path.join( repoRoot, 'wordpress', 'phpunit-duckdb-autoload-wrapper.php' );
const duckdbChildDiagnosticsPath = path.join( repoRoot, 'wordpress', 'duckdb-child-process-diagnostics.php' );
const duckdbChildDiagnosticsLogPath = path.join( repoRoot, 'wordpress', 'duckdb-child-process-diagnostics.log' );
const phpunitCompatibilityPrependContainerPath = '/var/www/phpunit-runner-compat-prepend.php';
const duckdbAutoloadCompatibilityWrapperContainerPath = '/var/www/phpunit-duckdb-autoload-wrapper.php';
const duckdbChildDiagnosticsContainerPath = '/var/www/duckdb-child-process-diagnostics.php';
const duckdbChildDiagnosticsLogContainerPath = '/var/www/duckdb-child-process-diagnostics.log';
const wordPressPhpunitBootstrapContainerPath = '/var/www/tests/phpunit/includes/bootstrap.php';
const enableDuckDBChildDiagnostics = isDuckDBPhpunitRun && process.env.WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS === '1';
const enableDuckDBChildDatabaseCopy = isDuckDBPhpunitRun && process.env.WP_SQLITE_DUCKDB_CHILD_DB_COPY === '1';
const enableDuckDBChildProcessPatches = enableDuckDBChildDiagnostics || enableDuckDBChildDatabaseCopy;

const sqliteExpectedErrors = [
	'Tests_DB_Charset::test_invalid_characters_in_query',
	'Tests_DB_Charset::test_set_charset_changes_the_connection_collation',
];

const sqliteExpectedFailures = [
	'Tests_Admin_wpSiteHealth::test_object_cache_thresholds with data set #2',
	'Tests_Admin_wpSiteHealth::test_object_cache_thresholds with data set #3',
	'Tests_Comment::test_wp_new_comment_respects_comment_field_lengths',
	'Tests_Comment::test_wp_update_comment',
	'Tests_DB_Charset::test_get_column_charset with data set #0',
	'Tests_DB_Charset::test_get_column_charset with data set #1',
	'Tests_DB_Charset::test_get_column_charset with data set #2',
	'Tests_DB_Charset::test_get_column_charset with data set #3',
	'Tests_DB_Charset::test_get_column_charset with data set #4',
	'Tests_DB_Charset::test_get_column_charset with data set #5',
	'Tests_DB_Charset::test_get_column_charset with data set #6',
	'Tests_DB_Charset::test_get_column_charset with data set #7',
	'Tests_DB_Charset::test_get_column_charset_is_mysql_undefined with data set #0',
	'Tests_DB_Charset::test_get_column_charset_is_mysql_undefined with data set #1',
	'Tests_DB_Charset::test_get_column_charset_is_mysql_undefined with data set #2',
	'Tests_DB_Charset::test_get_column_charset_is_mysql_undefined with data set #3',
	'Tests_DB_Charset::test_get_column_charset_is_mysql_undefined with data set #4',
	'Tests_DB_Charset::test_get_column_charset_is_mysql_undefined with data set #5',
	'Tests_DB_Charset::test_get_column_charset_is_mysql_undefined with data set #6',
	'Tests_DB_Charset::test_get_column_charset_is_mysql_undefined with data set #7',
	'Tests_DB_Charset::test_get_column_charset_non_mysql with data set #0',
	'Tests_DB_Charset::test_get_column_charset_non_mysql with data set #1',
	'Tests_DB_Charset::test_get_column_charset_non_mysql with data set #2',
	'Tests_DB_Charset::test_get_column_charset_non_mysql with data set #3',
	'Tests_DB_Charset::test_get_column_charset_non_mysql with data set #4',
	'Tests_DB_Charset::test_get_column_charset_non_mysql with data set #5',
	'Tests_DB_Charset::test_get_column_charset_non_mysql with data set #6',
	'Tests_DB_Charset::test_get_column_charset_non_mysql with data set #7',
	'Tests_DB_Charset::test_process_field_charsets_on_nonexistent_table',
	'Tests_DB_Charset::test_strip_invalid_text with data set #21',
	'Tests_DB_Charset::test_strip_invalid_text with data set #22',
	'Tests_DB_Charset::test_strip_invalid_text with data set #23',
	'Tests_DB_Charset::test_strip_invalid_text with data set #24',
	'Tests_DB_Charset::test_strip_invalid_text with data set #25',
	'Tests_DB_Charset::test_strip_invalid_text with data set #26',
	'Tests_DB_Charset::test_strip_invalid_text with data set #27',
	'Tests_DB_Charset::test_strip_invalid_text with data set #28',
	'Tests_DB_Charset::test_strip_invalid_text with data set #30',
	'Tests_DB_Charset::test_strip_invalid_text with data set #31',
	'Tests_DB_Charset::test_strip_invalid_text with data set #32',
	'Tests_DB_Charset::test_strip_invalid_text with data set #33',
	'Tests_DB_Charset::test_strip_invalid_text with data set #34',
	'Tests_DB_Charset::test_strip_invalid_text with data set #39',
	'Tests_DB_Charset::test_strip_invalid_text with data set #40',
	'Tests_DB_Charset::test_strip_invalid_text with data set #41',
	'Tests_DB_Charset::test_strip_invalid_text_for_column_bails_if_ascii_input_too_long',
	'Tests_DB_dbDelta::test_spatial_indices',
	'Tests_DB::test_charset_switched_to_utf8mb4',
	'Tests_DB::test_close',
	'Tests_DB::test_delete_value_too_long_for_field with data set &quot;too long&quot;',
	'Tests_DB::test_has_cap',
	'Tests_DB::test_insert_value_too_long_for_field with data set &quot;too long&quot;',
	'Tests_DB::test_mysqli_flush_sync',
	'Tests_DB::test_non_unicode_collations',
	'Tests_DB::test_pre_get_col_charset_filter',
	'Tests_DB::test_process_fields_on_nonexistent_table',
	'Tests_DB::test_process_fields_value_too_long_for_field with data set &quot;too long&quot;',
	'Tests_DB::test_query_value_contains_invalid_chars',
	'Tests_DB::test_replace_value_too_long_for_field with data set &quot;too long&quot;',
	'Tests_DB::test_replace',
	'Tests_DB::test_supports_collation',
	'Tests_DB::test_update_value_too_long_for_field with data set &quot;too long&quot;',
	'Tests_Menu_Walker_Nav_Menu::test_start_el_with_empty_attributes with data set #1',
	'Tests_Menu_Walker_Nav_Menu::test_start_el_with_empty_attributes with data set #2',
	'Tests_Menu_Walker_Nav_Menu::test_start_el_with_empty_attributes with data set #3',
	'Tests_Menu_Walker_Nav_Menu::test_start_el_with_empty_attributes with data set #4',
	'Tests_Menu_Walker_Nav_Menu::test_start_el_with_empty_attributes with data set #5',
	'Tests_Menu_Walker_Nav_Menu::test_start_el_with_empty_attributes with data set #6',
	'Tests_Menu_Walker_Nav_Menu::test_start_el_with_empty_attributes with data set #7',
	'Tests_Menu_wpNavMenu::test_wp_nav_menu_should_not_have_has_children_class_with_custom_depth',
	'WP_Test_REST_Posts_Controller::test_get_items_orderby_modified_query',
];

const duckdbExpectedFailuresToPrune = new Set( [
	'Tests_Admin_wpSiteHealth::test_object_cache_thresholds with data set #2',
	'Tests_Admin_wpSiteHealth::test_object_cache_thresholds with data set #3',
	'Tests_Comment::test_wp_new_comment_respects_comment_field_lengths',
	'Tests_Comment::test_wp_update_comment',
	'Tests_DB_Charset::test_get_column_charset with data set #0',
	'Tests_DB_Charset::test_get_column_charset with data set #1',
	'Tests_DB_Charset::test_get_column_charset with data set #2',
	'Tests_DB_Charset::test_get_column_charset with data set #3',
	'Tests_DB_Charset::test_get_column_charset with data set #4',
	'Tests_DB_Charset::test_get_column_charset with data set #5',
	'Tests_DB_Charset::test_get_column_charset with data set #6',
	'Tests_DB_Charset::test_get_column_charset with data set #7',
	'Tests_DB_Charset::test_get_column_charset_is_mysql_undefined with data set #0',
	'Tests_DB_Charset::test_get_column_charset_is_mysql_undefined with data set #1',
	'Tests_DB_Charset::test_get_column_charset_is_mysql_undefined with data set #2',
	'Tests_DB_Charset::test_get_column_charset_is_mysql_undefined with data set #3',
	'Tests_DB_Charset::test_get_column_charset_is_mysql_undefined with data set #4',
	'Tests_DB_Charset::test_get_column_charset_is_mysql_undefined with data set #5',
	'Tests_DB_Charset::test_get_column_charset_is_mysql_undefined with data set #6',
	'Tests_DB_Charset::test_get_column_charset_is_mysql_undefined with data set #7',
	'Tests_DB_Charset::test_get_column_charset_non_mysql with data set #0',
	'Tests_DB_Charset::test_get_column_charset_non_mysql with data set #1',
	'Tests_DB_Charset::test_get_column_charset_non_mysql with data set #2',
	'Tests_DB_Charset::test_get_column_charset_non_mysql with data set #3',
	'Tests_DB_Charset::test_get_column_charset_non_mysql with data set #4',
	'Tests_DB_Charset::test_get_column_charset_non_mysql with data set #5',
	'Tests_DB_Charset::test_get_column_charset_non_mysql with data set #6',
	'Tests_DB_Charset::test_get_column_charset_non_mysql with data set #7',
	'Tests_DB_Charset::test_process_field_charsets_on_nonexistent_table',
	'Tests_DB_Charset::test_strip_invalid_text with data set #21',
	'Tests_DB_Charset::test_strip_invalid_text with data set #22',
	'Tests_DB_Charset::test_strip_invalid_text with data set #23',
	'Tests_DB_Charset::test_strip_invalid_text with data set #24',
	'Tests_DB_Charset::test_strip_invalid_text with data set #25',
	'Tests_DB_Charset::test_strip_invalid_text with data set #26',
	'Tests_DB_Charset::test_strip_invalid_text with data set #27',
	'Tests_DB_Charset::test_strip_invalid_text with data set #28',
	'Tests_DB_Charset::test_strip_invalid_text with data set #30',
	'Tests_DB_Charset::test_strip_invalid_text with data set #31',
	'Tests_DB_Charset::test_strip_invalid_text with data set #32',
	'Tests_DB_Charset::test_strip_invalid_text with data set #33',
	'Tests_DB_Charset::test_strip_invalid_text with data set #34',
	'Tests_DB_Charset::test_strip_invalid_text with data set #39',
	'Tests_DB_Charset::test_strip_invalid_text with data set #40',
	'Tests_DB_Charset::test_strip_invalid_text with data set #41',
	'Tests_DB_Charset::test_strip_invalid_text_for_column_bails_if_ascii_input_too_long',
	'Tests_DB_dbDelta::test_spatial_indices',
	'Tests_DB::test_charset_switched_to_utf8mb4',
	'Tests_DB::test_close',
	'Tests_DB::test_delete_value_too_long_for_field with data set &quot;too long&quot;',
	'Tests_DB::test_has_cap',
	'Tests_DB::test_insert_value_too_long_for_field with data set &quot;too long&quot;',
	'Tests_DB::test_mysqli_flush_sync',
	'Tests_DB::test_non_unicode_collations',
	'Tests_DB::test_pre_get_col_charset_filter',
	'Tests_DB::test_process_fields_on_nonexistent_table',
	'Tests_DB::test_process_fields_value_too_long_for_field with data set &quot;too long&quot;',
	'Tests_DB::test_query_value_contains_invalid_chars',
	'Tests_DB::test_replace_value_too_long_for_field with data set &quot;too long&quot;',
	'Tests_DB::test_replace',
	'Tests_DB::test_supports_collation',
	'Tests_DB::test_update_value_too_long_for_field with data set &quot;too long&quot;',
	'Tests_Menu_Walker_Nav_Menu::test_start_el_with_empty_attributes with data set #1',
	'Tests_Menu_Walker_Nav_Menu::test_start_el_with_empty_attributes with data set #2',
	'Tests_Menu_Walker_Nav_Menu::test_start_el_with_empty_attributes with data set #3',
	'Tests_Menu_Walker_Nav_Menu::test_start_el_with_empty_attributes with data set #4',
	'Tests_Menu_Walker_Nav_Menu::test_start_el_with_empty_attributes with data set #5',
	'Tests_Menu_Walker_Nav_Menu::test_start_el_with_empty_attributes with data set #6',
	'Tests_Menu_Walker_Nav_Menu::test_start_el_with_empty_attributes with data set #7',
	'Tests_Menu_wpNavMenu::test_wp_nav_menu_should_not_have_has_children_class_with_custom_depth',
	'WP_Test_REST_Posts_Controller::test_get_items_orderby_modified_query',
] );

const expectedErrors = disableExpectedResults
	? []
	: ( isDuckDBPhpunitRun ? [] : sqliteExpectedErrors );
const expectedFailures = disableExpectedResults
	? []
	: (
		isDuckDBPhpunitRun
			? sqliteExpectedFailures.filter( test => ! duckdbExpectedFailuresToPrune.has( test ) )
			: sqliteExpectedFailures
	);

console.log( 'Running WordPress PHPUnit tests with expected failures tracking...' );
if ( requiresNativeParserExtension ) {
	console.log( 'Native parser extension is required for this PHPUnit run.' );
}
console.log( 'PHPUnit command:', phpunitCommand );
console.log( 'Expected-result mode:', isDuckDBPhpunitRun ? 'duckdb' : 'sqlite' );
console.log( 'PHPUnit timing label:', phpunitTimingLabel );
console.log( 'PHPUnit baseline seconds:', phpunitBaselineSeconds || 'none' );
console.log( 'PHPUnit max seconds:', phpunitMaxSeconds || 'none' );
console.log( 'PHPUnit min tests:', phpunitMinTests || 'none' );
if ( disableExpectedResults ) {
	console.log( 'Expected-result allowlist disabled.' );
}
console.log( 'JUnit output:', junitOutputFile );
console.log( 'Expected errors:', expectedErrors );
console.log( 'Expected failures:', expectedFailures );
if ( ignoreMissingExpectedResults ) {
	console.log( 'Expected errors/failures outside the selected PHPUnit tests will be ignored.' );
}

function getDefaultEnsureEnvironmentCommand() {
	return isDuckDBPhpunitRun
		? 'composer run wp-test-ensure-env-duckdb'
		: 'composer run wp-test-ensure-env';
}

function getPositiveNumberEnv( name ) {
	const value = Number( process.env[ name ] || 0 );
	return Number.isFinite( value ) && value > 0 ? value : 0;
}

function markProgress( phase, extra = {} ) {
	const elapsedSeconds = ( Date.now() - wrapperStartedAt ) / 1000;
	const fields = {
		phase,
		label: phpunitTimingLabel,
		elapsed_seconds: elapsedSeconds.toFixed( 3 ),
		...extra,
	};
	const message = Object.entries( fields ).map( ( [ key, value ] ) => `${ key }=${ value }` ).join( ' ' );
	console.log( `WP_SQLITE_PHPUNIT_PROGRESS ${ message }` );
	if ( process.env.GITHUB_ACTIONS === 'true' ) {
		console.log( `::notice title=WordPress PHPUnit progress::${ message }` );
	}
}

function appendTimingSummary( timingSummary ) {
	if ( ! process.env.GITHUB_STEP_SUMMARY ) {
		return;
	}

	const rows = [
		'| Field | Value |',
		'| --- | --- |',
		...Object.entries( timingSummary ).map( ( [ key, value ] ) => `| ${ key } | ${ value } |` ),
	];
	fs.appendFileSync(
		process.env.GITHUB_STEP_SUMMARY,
		`\n### WordPress PHPUnit timing\n\n${ rows.join( '\n' ) }\n`
	);
}

function preparePhpunitCommand() {
	if ( ! shouldPreloadCompatiblePhpunitRunner() ) {
		return phpunitCommand;
	}

	writePhpunitCompatibilityFiles();
	patchPhpunitParentProcessIsolationHooks();
	if ( enableDuckDBChildDiagnostics ) {
		writeDuckDBChildDiagnosticsFile();
	}
	if ( enableDuckDBChildProcessPatches ) {
		patchWordPressPhpunitBootstrapForChildDiagnostics();
		patchPhpunitChildProcessTemplatesForDiagnostics();
	}
	verifyPhpunitCompatibilityFiles();
	if ( enableDuckDBChildDiagnostics ) {
		resetDuckDBChildDiagnosticsLog();
	}

	const effectivePhpunitCommand = addPhpunitPrependArgument(
		phpunitCommand,
		phpunitCompatibilityPrependContainerPath
	);

	if ( effectivePhpunitCommand !== phpunitCommand ) {
		console.log( 'PHPUnit compatibility prepend:', phpunitCompatibilityPrependContainerPath );
		console.log( 'Effective PHPUnit command:', effectivePhpunitCommand );
	}

	return effectivePhpunitCommand;
}

function shouldPreloadCompatiblePhpunitRunner() {
	return (
		ensurePhpunitCompatibility &&
		! skipPhpunitCompatibilityCheck &&
		isDuckDBPhpunitRun
	);
}

function writePhpunitCompatibilityFiles() {
	if ( ! process.env.DUCKDB_PHP_AUTOLOAD ) {
		console.error( 'Error: DUCKDB_PHP_AUTOLOAD is required for the DuckDB PHPUnit compatibility wrapper.' );
		process.exit( 1 );
	}

	fs.writeFileSync(
		phpunitCompatibilityPrependPath,
		`<?php
$autoload = defined( 'PHPUNIT_COMPOSER_INSTALL' ) && is_string( PHPUNIT_COMPOSER_INSTALL )
\t? PHPUNIT_COMPOSER_INSTALL
\t: __DIR__ . '/vendor/autoload.php';

if ( ! is_readable( $autoload ) ) {
\tfwrite( STDERR, "Error: WordPress PHPUnit autoload file is not readable: {$autoload}\\n" );
\texit( 1 );
}

$wp_sqlite_phpunit_autoloader = require $autoload;
if ( is_object( $wp_sqlite_phpunit_autoloader ) ) {
\t$GLOBALS['wp_sqlite_phpunit_autoloader'] = $wp_sqlite_phpunit_autoloader;
}

if ( ! defined( 'DUCKDB_PHP_AUTOLOAD' ) ) {
\tdefine( 'DUCKDB_PHP_AUTOLOAD', ${ phpSingleQuote( duckdbAutoloadCompatibilityWrapperContainerPath ) } );
}

${ getPhpunitForwardedEnvironmentPhp() }

${ getDuckDBParentProcessIsolationPhp() }

${ getDuckDBChildDiagnosticsPrependPhp() }

if ( ! method_exists( 'PHPUnit\\\\TextUI\\\\TestRunner', 'run' ) ) {
\tfwrite( STDERR, "Error: WordPress PHPUnit runner does not provide PHPUnit\\\\TextUI\\\\TestRunner::run().\\n" );
\texit( 1 );
}
`
	);

	fs.writeFileSync(
		duckdbAutoloadCompatibilityWrapperPath,
		`<?php
$wp_sqlite_phpunit_autoload = defined( 'PHPUNIT_COMPOSER_INSTALL' ) && is_string( PHPUNIT_COMPOSER_INSTALL )
\t? PHPUNIT_COMPOSER_INSTALL
\t: __DIR__ . '/vendor/autoload.php';
$duckdb_autoload = ${ phpSingleQuote( process.env.DUCKDB_PHP_AUTOLOAD ) };

if ( ! is_readable( $duckdb_autoload ) ) {
\tfwrite( STDERR, "Error: DuckDB PHP autoload file is not readable: {$duckdb_autoload}\\n" );
\texit( 1 );
}

$wp_sqlite_phpunit_autoloader = isset( $GLOBALS['wp_sqlite_phpunit_autoloader'] )
\t? $GLOBALS['wp_sqlite_phpunit_autoloader']
\t: null;

if ( ! is_object( $wp_sqlite_phpunit_autoloader ) ) {
\tif ( ! is_readable( $wp_sqlite_phpunit_autoload ) ) {
\t\tfwrite( STDERR, "Error: WordPress PHPUnit autoload file is not readable before DuckDB autoload: {$wp_sqlite_phpunit_autoload}\\n" );
\t\texit( 1 );
\t}

\t$wp_sqlite_phpunit_autoloader = require $wp_sqlite_phpunit_autoload;
\tif ( is_object( $wp_sqlite_phpunit_autoloader ) ) {
\t\t$GLOBALS['wp_sqlite_phpunit_autoloader'] = $wp_sqlite_phpunit_autoloader;
\t}
}

if ( ! method_exists( 'PHPUnit\\\\TextUI\\\\TestRunner', 'run' ) ) {
\tfwrite( STDERR, "Error: WordPress-compatible PHPUnit runner is unavailable before DuckDB autoload.\\n" );
\texit( 1 );
}

require_once $duckdb_autoload;

if ( isset( $GLOBALS['wp_sqlite_phpunit_autoloader'] )
\t&& is_object( $GLOBALS['wp_sqlite_phpunit_autoloader'] )
\t&& method_exists( $GLOBALS['wp_sqlite_phpunit_autoloader'], 'register' )
) {
\tif ( method_exists( $GLOBALS['wp_sqlite_phpunit_autoloader'], 'unregister' ) ) {
\t\t$GLOBALS['wp_sqlite_phpunit_autoloader']->unregister();
\t}

\t$GLOBALS['wp_sqlite_phpunit_autoloader']->register( true );
}

if ( ! method_exists( 'PHPUnit\\\\TextUI\\\\TestRunner', 'run' ) ) {
\tfwrite( STDERR, "Error: DuckDB autoload changed the active PHPUnit runner away from the WordPress-compatible runner.\\n" );
\texit( 1 );
}
`
	);
}

function writeDuckDBChildDiagnosticsFile() {
	resetDuckDBChildDiagnosticsLog();
	fs.writeFileSync(
		duckdbChildDiagnosticsPath,
		`<?php
if ( ! function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {
\tfunction wp_sqlite_duckdb_child_diagnostics_enabled() {
\t\t$value = getenv( 'WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS' );
\t\treturn is_string( $value ) && '' !== $value && '0' !== $value && 'false' !== strtolower( $value );
\t}

\tfunction wp_sqlite_duckdb_child_diagnostics_verbose() {
\t\t$value = getenv( 'WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS_VERBOSE' );
\t\treturn is_string( $value ) && '' !== $value && '0' !== $value && 'false' !== strtolower( $value );
\t}

\tfunction wp_sqlite_duckdb_child_diagnostics_stderr_enabled() {
\t\t$value = getenv( 'WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS_STDERR' );
\t\treturn is_string( $value ) && '' !== $value && '0' !== $value && 'false' !== strtolower( $value );
\t}

\tfunction wp_sqlite_duckdb_child_diagnostics_fatal_error( $error ) {
\t\tif ( ! is_array( $error ) || ! isset( $error['type'] ) ) {
\t\t\treturn false;
\t\t}

\t\treturn in_array(
\t\t\t$error['type'],
\t\t\tarray( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ),
\t\t\ttrue
\t\t);
\t}

\tfunction wp_sqlite_duckdb_child_diagnostics_output_buffer_snapshot( $max_length = 2048 ) {
\t\tif ( 1 > ob_get_level() ) {
\t\t\treturn null;
\t\t}

\t\t$contents = ob_get_contents();
\t\tif ( ! is_string( $contents ) ) {
\t\t\treturn null;
\t\t}

\t\t$length = strlen( $contents );
\t\t$tail   = $contents;
\t\tif ( $length > $max_length ) {
\t\t\t$tail = substr( $contents, -$max_length );
\t\t}

\t\treturn array(
\t\t\t'length'         => $length,
\t\t\t'sha1'           => sha1( $contents ),
\t\t\t'tail_truncated' => $length > $max_length,
\t\t\t'tail_base64'    => base64_encode( $tail ),
\t\t);
\t}

\tfunction wp_sqlite_duckdb_child_diagnostics_truncate_string( $value, $max_length = 500 ) {
\t\tif ( ! is_string( $value ) ) {
\t\t\treturn $value;
\t\t}

\t\tif ( strlen( $value ) <= $max_length ) {
\t\t\treturn $value;
\t\t}

\t\treturn substr( $value, 0, $max_length ) . '...';
\t}

\tfunction wp_sqlite_duckdb_child_diagnostics_test_identity( $test ) {
\t\t$identity = array(
\t\t\t'class'            => is_object( $test ) ? get_class( $test ) : null,
\t\t\t'name'             => null,
\t\t\t'to_string'        => null,
\t\t\t'data_name'        => null,
\t\t\t'data_description' => null,
\t\t);

\t\tif ( ! is_object( $test ) ) {
\t\t\treturn $identity;
\t\t}

\t\ttry {
\t\t\tif ( method_exists( $test, 'getName' ) ) {
\t\t\t\t$identity['name'] = $test->getName( false );
\t\t\t}
\t\t} catch ( Throwable $e ) {
\t\t\t$identity['name'] = get_class( $e ) . ': ' . $e->getMessage();
\t\t}

\t\ttry {
\t\t\tif ( method_exists( $test, 'toString' ) ) {
\t\t\t\t$identity['to_string'] = $test->toString();
\t\t\t}
\t\t} catch ( Throwable $e ) {
\t\t\t$identity['to_string'] = get_class( $e ) . ': ' . $e->getMessage();
\t\t}

\t\ttry {
\t\t\tif ( method_exists( $test, 'dataName' ) ) {
\t\t\t\t$identity['data_name'] = $test->dataName();
\t\t\t}
\t\t} catch ( Throwable $e ) {
\t\t\t$identity['data_name'] = get_class( $e ) . ': ' . $e->getMessage();
\t\t}

\t\ttry {
\t\t\tif ( method_exists( $test, 'dataDescription' ) ) {
\t\t\t\t$identity['data_description'] = $test->dataDescription();
\t\t\t}
\t\t} catch ( Throwable $e ) {
\t\t\t$identity['data_description'] = get_class( $e ) . ': ' . $e->getMessage();
\t\t}

\t\tforeach ( $identity as $key => $value ) {
\t\t\t$identity[ $key ] = wp_sqlite_duckdb_child_diagnostics_truncate_string( $value );
\t\t}

\t\treturn $identity;
\t}

\tfunction wp_sqlite_duckdb_child_diagnostics_report( $stage, $force = false ) {
\t\tif ( ! wp_sqlite_duckdb_child_diagnostics_enabled() ) {
\t\t\treturn;
\t\t}

\t\t$error     = error_get_last();
\t\t$is_fatal  = wp_sqlite_duckdb_child_diagnostics_fatal_error( $error );
\t\t$db_engine = defined( 'DB_ENGINE' ) ? DB_ENGINE : null;
\t\t$autoload  = defined( 'DUCKDB_PHP_AUTOLOAD' ) ? DUCKDB_PHP_AUTOLOAD : null;
\t\t$wpdb      = isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ? get_class( $GLOBALS['wpdb'] ) : null;
\t\t$wp_tests_skip_install = getenv( 'WP_TESTS_SKIP_INSTALL' );
\t\tif ( ! is_string( $wp_tests_skip_install ) ) {
\t\t\t$wp_tests_skip_install = null;
\t\t}
\t\t$duckdb_file = defined( 'DUCKDB_FILE' ) ? DUCKDB_FILE : null;
\t\t$fqduckdb    = defined( 'FQDUCKDB' ) ? FQDUCKDB : null;
\t\t$reason    = null;
\t\t$previous_stage = isset( $GLOBALS['wp_sqlite_duckdb_child_last_stage'] ) && is_string( $GLOBALS['wp_sqlite_duckdb_child_last_stage'] )
\t\t\t? $GLOBALS['wp_sqlite_duckdb_child_last_stage']
\t\t\t: null;
\t\t$last_stage = $previous_stage;

\t\tif ( 'shutdown' !== $stage ) {
\t\t\t$GLOBALS['wp_sqlite_duckdb_child_last_stage'] = $stage;
\t\t\t$last_stage = $stage;
\t\t}

\t\t$result_file = isset( $GLOBALS['wp_sqlite_duckdb_child_result_file'] ) && is_string( $GLOBALS['wp_sqlite_duckdb_child_result_file'] )
\t\t\t? $GLOBALS['wp_sqlite_duckdb_child_result_file']
\t\t\t: null;
\t\t$result_file_exists = is_string( $result_file ) && file_exists( $result_file );
\t\t$result_file_readable = is_string( $result_file ) && is_readable( $result_file );
\t\t$result_file_size = $result_file_exists ? filesize( $result_file ) : null;
\t\tif ( false === $result_file_size ) {
\t\t\t$result_file_size = null;
\t\t}
\t\t$result_file_sha1 = null;
\t\tif ( $result_file_readable && is_int( $result_file_size ) && $result_file_size <= 1048576 ) {
\t\t\t$result_file_sha1 = sha1_file( $result_file );
\t\t\tif ( false === $result_file_sha1 ) {
\t\t\t\t$result_file_sha1 = null;
\t\t\t}
\t\t}
\t\t$result_file_dir = is_string( $result_file ) ? dirname( $result_file ) : null;
\t\t$bootstrap_global = isset( $GLOBALS['__PHPUNIT_BOOTSTRAP'] ) && is_string( $GLOBALS['__PHPUNIT_BOOTSTRAP'] )
\t\t\t? $GLOBALS['__PHPUNIT_BOOTSTRAP']
\t\t\t: null;
\t\t$bootstrap_realpath = is_string( $bootstrap_global ) ? realpath( $bootstrap_global ) : null;
\t\tif ( ! is_string( $bootstrap_realpath ) ) {
\t\t\t$bootstrap_realpath = null;
\t\t}
\t\t$bootstrap_included = false;
\t\tif ( is_string( $bootstrap_realpath ) ) {
\t\t\tforeach ( get_included_files() as $included_file ) {
\t\t\t\t$included_realpath = realpath( $included_file );
\t\t\t\tif ( is_string( $included_realpath ) && $included_realpath === $bootstrap_realpath ) {
\t\t\t\t\t$bootstrap_included = true;
\t\t\t\t\tbreak;
\t\t\t\t}
\t\t\t}
\t\t}

\t\t$wpdb_object = isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] )
\t\t\t? $GLOBALS['wpdb']
\t\t\t: null;
\t\t$wpdb_last_error = is_object( $wpdb_object ) && isset( $wpdb_object->last_error )
\t\t\t? wp_sqlite_duckdb_child_diagnostics_truncate_string( $wpdb_object->last_error )
\t\t\t: null;
\t\t$wpdb_last_query = is_object( $wpdb_object ) && isset( $wpdb_object->last_query )
\t\t\t? wp_sqlite_duckdb_child_diagnostics_truncate_string( $wpdb_object->last_query )
\t\t\t: null;
\t\t$included_files = get_included_files();
\t\t$included_files_tail = array_map(
\t\t\t'basename',
\t\t\tarray_slice( $included_files, -5 )
\t\t);
\t\t$test_identity = isset( $GLOBALS['wp_sqlite_duckdb_child_test_identity'] ) && is_array( $GLOBALS['wp_sqlite_duckdb_child_test_identity'] )
\t\t\t? $GLOBALS['wp_sqlite_duckdb_child_test_identity']
\t\t\t: null;
\t\t$result_write = isset( $GLOBALS['wp_sqlite_duckdb_child_result_write'] ) && is_array( $GLOBALS['wp_sqlite_duckdb_child_result_write'] )
\t\t\t? $GLOBALS['wp_sqlite_duckdb_child_result_write']
\t\t\t: null;
\t\t$factory_sequence_alignment = isset( $GLOBALS['wp_sqlite_duckdb_child_factory_sequence_alignment'] ) && is_array( $GLOBALS['wp_sqlite_duckdb_child_factory_sequence_alignment'] )
\t\t\t? $GLOBALS['wp_sqlite_duckdb_child_factory_sequence_alignment']
\t\t\t: null;
\t\t$child_database_copy = isset( $GLOBALS['wp_sqlite_duckdb_child_database_copy'] ) && is_array( $GLOBALS['wp_sqlite_duckdb_child_database_copy'] )
\t\t\t? $GLOBALS['wp_sqlite_duckdb_child_database_copy']
\t\t\t: null;

\t\tif ( class_exists( 'WP_DuckDB_Runtime', false ) && method_exists( 'WP_DuckDB_Runtime', 'get_unavailable_reason' ) ) {
\t\t\ttry {
\t\t\t\t$reason = WP_DuckDB_Runtime::get_unavailable_reason( false );
\t\t\t} catch ( Throwable $e ) {
\t\t\t\t$reason = get_class( $e ) . ': ' . $e->getMessage();
\t\t\t}
\t\t}

\t\t$bootstrap_failed = 'wp_bootstrap' === $stage && (
\t\t\t'duckdb' !== $db_engine
\t\t\t|| ! is_string( $autoload )
\t\t\t|| ! is_readable( $autoload )
\t\t\t|| ! class_exists( 'WP_DuckDB_Runtime', false )
\t\t\t|| null !== $reason
\t\t\t|| 'WP_DuckDB_DB' !== $wpdb
\t\t);

\t\tif ( ! $force && ! wp_sqlite_duckdb_child_diagnostics_verbose() && ! $bootstrap_failed && ! ( 'shutdown' === $stage && $is_fatal ) ) {
\t\t\treturn;
\t\t}

\t\t$output_buffer_snapshot = 'shutdown' === $stage || $is_fatal
\t\t\t? wp_sqlite_duckdb_child_diagnostics_output_buffer_snapshot()
\t\t\t: null;
\t\t$output_buffer_size = is_array( $output_buffer_snapshot ) && isset( $output_buffer_snapshot['length'] )
\t\t\t? $output_buffer_snapshot['length']
\t\t\t: null;
\t\t$output_buffer_sha1 = is_array( $output_buffer_snapshot ) && isset( $output_buffer_snapshot['sha1'] )
\t\t\t? $output_buffer_snapshot['sha1']
\t\t\t: null;
\t\t$output_buffer_tail_truncated = is_array( $output_buffer_snapshot ) && isset( $output_buffer_snapshot['tail_truncated'] )
\t\t\t? $output_buffer_snapshot['tail_truncated']
\t\t\t: null;
\t\t$output_buffer_tail_base64 = is_array( $output_buffer_snapshot ) && isset( $output_buffer_snapshot['tail_base64'] )
\t\t\t? $output_buffer_snapshot['tail_base64']
\t\t\t: null;

\t\t$payload = array(
\t\t\t'stage'                    => $stage,
\t\t\t'last_stage'               => $last_stage,
\t\t\t'pid'                      => getmypid(),
\t\t\t'ppid'                     => function_exists( 'posix_getppid' ) ? posix_getppid() : null,
\t\t\t'php_sapi'                 => PHP_SAPI,
\t\t\t'php_version'              => PHP_VERSION,
\t\t\t'php_binary'               => PHP_BINARY,
\t\t\t'cwd'                      => getcwd(),
\t\t\t'argv'                     => isset( $_SERVER['argv'] ) ? $_SERVER['argv'] : null,
\t\t\t'wp_tests_skip_install'    => $wp_tests_skip_install,
\t\t\t'db_engine_defined'        => defined( 'DB_ENGINE' ),
\t\t\t'db_engine'                => $db_engine,
\t\t\t'duckdb_file_defined'      => defined( 'DUCKDB_FILE' ),
\t\t\t'duckdb_file'              => $duckdb_file,
\t\t\t'fqduckdb_defined'         => defined( 'FQDUCKDB' ),
\t\t\t'fqduckdb'                 => $fqduckdb,
\t\t\t'duckdb_autoload_defined'  => defined( 'DUCKDB_PHP_AUTOLOAD' ),
\t\t\t'duckdb_autoload'          => $autoload,
\t\t\t'duckdb_autoload_readable' => is_string( $autoload ) && is_readable( $autoload ),
\t\t\t'ffi_loaded'               => extension_loaded( 'ffi' ),
\t\t\t'ffi_enable'               => ini_get( 'ffi.enable' ),
\t\t\t'runtime_class_loaded'     => class_exists( 'WP_DuckDB_Runtime', false ),
\t\t\t'runtime_unavailable'      => $reason,
\t\t\t'duckdb_class_loaded'      => class_exists( 'Saturio\\\\DuckDB\\\\DuckDB', false ),
\t\t\t'wpdb_class'               => $wpdb,
\t\t\t'wpdb_last_error'          => $wpdb_last_error,
\t\t\t'wpdb_last_query'          => $wpdb_last_query,
\t\t\t'wpdb_has_dbh_property'    => is_object( $wpdb_object ) && property_exists( $wpdb_object, 'dbh' ),
\t\t\t'bootstrap_global'         => $bootstrap_global,
\t\t\t'bootstrap_global_readable' => is_string( $bootstrap_global ) && is_readable( $bootstrap_global ),
\t\t\t'bootstrap_realpath'       => $bootstrap_realpath,
\t\t\t'bootstrap_included'       => $bootstrap_included,
\t\t\t'wp_did_wp_settings'       => defined( 'WPINC' ) && function_exists( 'wp' ),
\t\t\t'included_files_count'     => count( $included_files ),
\t\t\t'included_files_tail'      => $included_files_tail,
\t\t\t'test_identity'            => $test_identity,
\t\t\t'result_write'             => $result_write,
\t\t\t'factory_sequence_alignment' => $factory_sequence_alignment,
\t\t\t'child_database_copy'      => $child_database_copy,
\t\t\t'output_buffer_level'      => ob_get_level(),
\t\t\t'output_buffer_size'       => $output_buffer_size,
\t\t\t'output_buffer_sha1'       => $output_buffer_sha1,
\t\t\t'output_buffer_tail_truncated' => $output_buffer_tail_truncated,
\t\t\t'output_buffer_tail_base64'    => $output_buffer_tail_base64,
\t\t\t'result_file'              => $result_file,
\t\t\t'result_file_exists'       => $result_file_exists,
\t\t\t'result_file_readable'     => $result_file_readable,
\t\t\t'result_file_size'         => $result_file_size,
\t\t\t'result_file_sha1'         => $result_file_sha1,
\t\t\t'result_file_dir_writable' => is_string( $result_file_dir ) && is_writable( $result_file_dir ),
\t\t\t'fatal'                    => $is_fatal ? $error : null,
\t\t);

\t\t$encoded = json_encode( $payload );
\t\tif ( ! is_string( $encoded ) ) {
\t\t\t$encoded = json_encode(
\t\t\t\tarray(
\t\t\t\t\t'stage'       => $stage,
\t\t\t\t\t'pid'         => getmypid(),
\t\t\t\t\t'json_error'  => json_last_error_msg(),
\t\t\t\t\t'fatal'       => $is_fatal ? $error : null,
\t\t\t\t)
\t\t\t);
\t\t}

\t\t$line = 'WP_SQLITE_DUCKDB_CHILD_DIAGNOSTIC ' . $encoded . PHP_EOL;
\t\t@file_put_contents( ${ phpSingleQuote( duckdbChildDiagnosticsLogContainerPath ) }, $line, FILE_APPEND | LOCK_EX );

\t\tif ( wp_sqlite_duckdb_child_diagnostics_stderr_enabled() ) {
\t\t\tfwrite( STDERR, $line );
\t\t}
\t}

\tregister_shutdown_function(
\t\tfunction () {
\t\t\twp_sqlite_duckdb_child_diagnostics_report( 'shutdown', true );
\t\t}
\t);
}
`
	);
}

function resetDuckDBChildDiagnosticsLog() {
	if ( enableDuckDBChildDiagnostics ) {
		fs.rmSync( duckdbChildDiagnosticsLogPath, { force: true } );
	}
}

function printDuckDBChildDiagnosticsLog() {
	if ( ! enableDuckDBChildDiagnostics ) {
		return;
	}

	if ( ! fs.existsSync( duckdbChildDiagnosticsLogPath ) ) {
		console.log( `No DuckDB child diagnostics log found at ${ duckdbChildDiagnosticsLogPath }.` );
		return;
	}

	const contents = fs.readFileSync( duckdbChildDiagnosticsLogPath, 'utf8' ).trimEnd();
	if ( '' === contents ) {
		console.log( `DuckDB child diagnostics log is empty at ${ duckdbChildDiagnosticsLogPath }.` );
		return;
	}

	const lines = contents.split( /\r?\n/ );
	const maxLines = 1200;
	const tail = lines.slice( -maxLines );
	console.log( `WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS_LOG path=${ duckdbChildDiagnosticsLogPath} lines=${ lines.length } emitted_lines=${ tail.length }` );
	console.log( 'WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS_LOG_BEGIN' );
	if ( lines.length > maxLines ) {
		console.log( `... truncated ${ lines.length - maxLines } earlier diagnostic lines ...` );
	}
	console.log( tail.join( '\n' ) );
	console.log( 'WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS_LOG_END' );
}

function getDuckDBChildDiagnosticsPrependPhp() {
	if ( ! enableDuckDBChildDiagnostics ) {
		return '';
	}

	return [
		`if ( is_readable( ${ phpSingleQuote( duckdbChildDiagnosticsContainerPath ) } ) ) {`,
		`\trequire_once ${ phpSingleQuote( duckdbChildDiagnosticsContainerPath ) };`,
		"\twp_sqlite_duckdb_child_diagnostics_report( 'prepend' );",
		'}',
	].join( '\n' );
}

function patchWordPressPhpunitBootstrapForChildDiagnostics() {
	const file = path.join( repoRoot, 'wordpress', 'tests', 'phpunit', 'includes', 'bootstrap.php' );
	const marker = "require_once ABSPATH . 'wp-settings.php';";
	const entryGuard = [
		'<?php',
		'',
		'/*',
		' * DuckDB child-process diagnostics at WordPress PHPUnit bootstrap entry.',
		' * This block is generated by the SQLite integration workflow.',
		' */',
		"$wp_sqlite_duckdb_child_diagnostics = dirname( __DIR__, 3 ) . '/duckdb-child-process-diagnostics.php';",
		"$wp_sqlite_duckdb_child_diagnostics_enabled = getenv( 'WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS' );",
		"if ( is_string( $wp_sqlite_duckdb_child_diagnostics_enabled ) && '' !== $wp_sqlite_duckdb_child_diagnostics_enabled && '0' !== $wp_sqlite_duckdb_child_diagnostics_enabled && 'false' !== strtolower( $wp_sqlite_duckdb_child_diagnostics_enabled ) && is_readable( $wp_sqlite_duckdb_child_diagnostics ) ) {",
		"\trequire_once $wp_sqlite_duckdb_child_diagnostics;",
		"\twp_sqlite_duckdb_child_diagnostics_report( 'bootstrap_file_entry', true );",
		'}',
	].join( '\n' );
	const guard = [
		'/*',
		' * DuckDB child-process diagnostics before WordPress bootstrap.',
		' * This block is generated by the SQLite integration workflow.',
		' */',
		"$wp_sqlite_duckdb_child_diagnostics = dirname( __DIR__, 3 ) . '/duckdb-child-process-diagnostics.php';",
		"$wp_sqlite_duckdb_child_diagnostics_enabled = getenv( 'WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS' );",
		"if ( is_string( $wp_sqlite_duckdb_child_diagnostics_enabled ) && '' !== $wp_sqlite_duckdb_child_diagnostics_enabled && '0' !== $wp_sqlite_duckdb_child_diagnostics_enabled && 'false' !== strtolower( $wp_sqlite_duckdb_child_diagnostics_enabled ) && is_readable( $wp_sqlite_duckdb_child_diagnostics ) ) {",
		"\trequire_once $wp_sqlite_duckdb_child_diagnostics;",
		'}',
		"if ( function_exists( 'wp_sqlite_duckdb_prepare_child_database_copy' ) ) {",
		"\twp_sqlite_duckdb_prepare_child_database_copy();",
		'}',
		"if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
		"\twp_sqlite_duckdb_child_diagnostics_report( 'bootstrap_pre_wp_settings', true );",
		'}',
		'',
		marker,
		'',
		"if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
		"\twp_sqlite_duckdb_child_diagnostics_report( 'wp_bootstrap', true );",
		'}',
	].join( '\n' );
	const bootstrapReport = ( stage, indent = '' ) => [
		`${ indent }if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {`,
		`${ indent }\twp_sqlite_duckdb_child_diagnostics_report( '${ stage }', true );`,
		`${ indent }}`,
	].join( '\n' );
	const beforeBootstrapReport = ( stage, needle, indent = '' ) => [
		bootstrapReport( stage, indent ),
		needle,
	].join( '\n' );
	const aroundBootstrapReport = ( beforeStage, afterStage, needle, indent = '' ) => [
		bootstrapReport( beforeStage, indent ),
		needle,
		bootstrapReport( afterStage, indent ),
	].join( '\n' );
	const earlyBootstrapReplacements = [
		{
			stage: 'bootstrap_before_config_readable_check',
			needle: 'if ( ! is_readable( $config_file_path ) ) {',
			replacement: beforeBootstrapReport( 'bootstrap_before_config_readable_check', 'if ( ! is_readable( $config_file_path ) ) {' ),
		},
		{
			stage: 'bootstrap_config_missing_exit',
			needle: "\techo 'Error: wp-tests-config.php is missing! Please use wp-tests-config-sample.php to create a config file.' . PHP_EOL;",
			replacement: beforeBootstrapReport( 'bootstrap_config_missing_exit', "\techo 'Error: wp-tests-config.php is missing! Please use wp-tests-config-sample.php to create a config file.' . PHP_EOL;", '\t' ),
		},
		{
			stage: 'bootstrap_after_config_require',
			needle: 'require_once $config_file_path;',
			replacement: aroundBootstrapReport( 'bootstrap_before_config_require', 'bootstrap_after_config_require', 'require_once $config_file_path;' ),
		},
		{
			stage: 'bootstrap_after_functions_require',
			needle: "require_once __DIR__ . '/functions.php';",
			replacement: aroundBootstrapReport( 'bootstrap_before_functions_require', 'bootstrap_after_functions_require', "require_once __DIR__ . '/functions.php';" ),
		},
		{
			stage: 'bootstrap_before_core_path_check',
			needle: "if ( defined( 'WP_RUN_CORE_TESTS' ) && WP_RUN_CORE_TESTS && ! is_dir( ABSPATH ) ) {",
			replacement: beforeBootstrapReport( 'bootstrap_before_core_path_check', "if ( defined( 'WP_RUN_CORE_TESTS' ) && WP_RUN_CORE_TESTS && ! is_dir( ABSPATH ) ) {" ),
		},
		{
			stage: 'bootstrap_before_phpunit_version',
			needle: '$phpunit_version = tests_get_phpunit_version();',
			replacement: beforeBootstrapReport( 'bootstrap_before_phpunit_version', '$phpunit_version = tests_get_phpunit_version();' ),
		},
		{
			stage: 'bootstrap_before_polyfills_check',
			needle: "if ( ! class_exists( 'Yoast\\PHPUnitPolyfills\\Autoload' ) ) {",
			replacement: beforeBootstrapReport( 'bootstrap_before_polyfills_check', "if ( ! class_exists( 'Yoast\\PHPUnitPolyfills\\Autoload' ) ) {" ),
		},
		{
			stage: 'bootstrap_polyfills_missing_exit',
			needle: "\tif ( $phpunit_polyfills_error || ! file_exists( $phpunit_polyfills_autoloader ) ) {",
			replacement: beforeBootstrapReport( 'bootstrap_polyfills_missing_exit', "\tif ( $phpunit_polyfills_error || ! file_exists( $phpunit_polyfills_autoloader ) ) {", '\t' ),
		},
		{
			stage: 'bootstrap_before_required_constants_check',
			needle: '$required_constants = array(',
			replacement: beforeBootstrapReport( 'bootstrap_before_required_constants_check', '$required_constants = array(' ),
		},
		{
			stage: 'bootstrap_before_tests_reset_server',
			needle: 'tests_reset__SERVER();',
			replacement: beforeBootstrapReport( 'bootstrap_before_tests_reset_server', 'tests_reset__SERVER();' ),
		},
		{
			stage: 'bootstrap_before_install_php_branch',
			needle: "if ( '1' !== getenv( 'WP_TESTS_SKIP_INSTALL' ) ) {",
			replacement: beforeBootstrapReport( 'bootstrap_before_install_php_branch', "if ( '1' !== getenv( 'WP_TESTS_SKIP_INSTALL' ) ) {" ),
		},
		{
			stage: 'bootstrap_after_install_php_process',
			needle: "\tsystem( WP_PHP_BINARY . ' ' . escapeshellarg( __DIR__ . '/install.php' ) . ' ' . escapeshellarg( $config_file_path ) . ' ' . $ms_tests . ' ' . $core_tests, $retval );",
			replacement: aroundBootstrapReport(
				'bootstrap_before_install_php_process',
				'bootstrap_after_install_php_process',
				"\tsystem( WP_PHP_BINARY . ' ' . escapeshellarg( __DIR__ . '/install.php' ) . ' ' . escapeshellarg( $config_file_path ) . ' ' . $ms_tests . ' ' . $core_tests, $retval );",
				'\t'
			),
		},
		{
			stage: 'bootstrap_install_php_failed_exit',
			needle: "\tif ( 0 !== $retval ) {",
			replacement: beforeBootstrapReport( 'bootstrap_install_php_failed_exit', "\tif ( 0 !== $retval ) {", '\t' ),
		},
		{
			stage: 'bootstrap_before_multisite_branch',
			needle: 'if ( $multisite ) {',
			replacement: beforeBootstrapReport( 'bootstrap_before_multisite_branch', 'if ( $multisite ) {' ),
		},
		{
			stage: 'bootstrap_before_wp_settings_filters',
			needle: "$GLOBALS['_wp_die_disabled'] = false;",
			replacement: beforeBootstrapReport( 'bootstrap_before_wp_settings_filters', "$GLOBALS['_wp_die_disabled'] = false;" ),
		},
	];

	if ( ! fs.existsSync( file ) ) {
		console.error( `Error: WordPress PHPUnit bootstrap file not found at ${ file }.` );
		process.exit( 1 );
	}

	let contents = fs.readFileSync( file, 'utf8' );
	let changed = false;
	let patchedBootstrapTargets = 0;
	const missingBootstrapTargets = [];

	if ( ! contents.includes( 'bootstrap_file_entry' ) ) {
		if ( ! contents.startsWith( '<?php' ) ) {
			console.error( `Error: Unable to find opening PHP tag in ${ file }.` );
			process.exit( 1 );
		}

		contents = contents.replace( '<?php', entryGuard );
		changed = true;
	}

	if ( ! contents.includes( guard ) ) {
		if ( ! contents.includes( marker ) ) {
			console.error( `Error: Unable to find WordPress bootstrap marker in ${ file }.` );
			process.exit( 1 );
		}

		contents = contents.replace( marker, guard );
		changed = true;
	}

	for ( const { stage, needle, replacement } of earlyBootstrapReplacements ) {
		if ( contents.includes( stage ) ) {
			continue;
		}

		if ( ! contents.includes( needle ) ) {
			console.warn( `Warning: Unable to find optional WordPress PHPUnit bootstrap diagnostic marker for ${ stage } in ${ file }.` );
			missingBootstrapTargets.push( stage );
			continue;
		}

		contents = contents.replace( needle, replacement );
		patchedBootstrapTargets++;
		changed = true;
	}

	console.log(
		[
			'WP_SQLITE_DUCKDB_BOOTSTRAP_DIAGNOSTIC_TARGETS',
			`patched=${ patchedBootstrapTargets }`,
			`missing=${ missingBootstrapTargets.length }`,
			`missing_names=${ missingBootstrapTargets.length ? missingBootstrapTargets.join( ',' ) : 'none' }`,
		].join( ' ' )
	);

	if ( changed ) {
		fs.writeFileSync( file, contents );
	}
}

function getDuckDBChildDatabaseCopyPhp() {
	if ( ! enableDuckDBChildDatabaseCopy ) {
		return '';
	}

	return `if ( ! function_exists( 'wp_sqlite_duckdb_prepare_child_database_copy' ) ) {
\tfunction wp_sqlite_duckdb_child_database_copy_record( $updates = array() ) {
\t\t$current = isset( $GLOBALS['wp_sqlite_duckdb_child_database_copy'] ) && is_array( $GLOBALS['wp_sqlite_duckdb_child_database_copy'] )
\t\t\t? $GLOBALS['wp_sqlite_duckdb_child_database_copy']
\t\t\t: array();
\t\t$GLOBALS['wp_sqlite_duckdb_child_database_copy'] = array_merge( $current, $updates );
\t}

\tfunction wp_sqlite_duckdb_child_database_copy_size( $path ) {
\t\tif ( ! is_string( $path ) || ! is_file( $path ) ) {
\t\t\treturn null;
\t\t}
\t\t$size = filesize( $path );
\t\treturn false === $size ? null : $size;
\t}

\tfunction wp_sqlite_duckdb_child_database_copy_dir() {
\t\tif ( defined( 'DB_DIR' ) ) {
\t\t\treturn rtrim( (string) DB_DIR, '/' ) . '/';
\t\t}
\t\tif ( defined( 'WP_CONTENT_DIR' ) ) {
\t\t\treturn rtrim( (string) WP_CONTENT_DIR, '/' ) . '/database/';
\t\t}
\t\tif ( defined( 'ABSPATH' ) ) {
\t\t\treturn rtrim( (string) ABSPATH, '/' ) . '/wp-content/database/';
\t\t}
\t\treturn null;
\t}

\tfunction wp_sqlite_duckdb_child_database_copy_cleanup() {
\t\t$copy = isset( $GLOBALS['wp_sqlite_duckdb_child_database_copy'] ) && is_array( $GLOBALS['wp_sqlite_duckdb_child_database_copy'] )
\t\t\t? $GLOBALS['wp_sqlite_duckdb_child_database_copy']
\t\t\t: array();
\t\t$paths = isset( $copy['cleanup_paths'] ) && is_array( $copy['cleanup_paths'] )
\t\t\t? $copy['cleanup_paths']
\t\t\t: array();
\t\t$cleanup = array(
\t\t\t'cleanup_attempted' => true,
\t\t\t'cleanup_success'   => true,
\t\t\t'cleanup_paths'     => array(),
\t\t);

\t\ttry {
\t\t\tif ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ) {
\t\t\t\tif ( method_exists( $GLOBALS['wpdb'], 'flush' ) ) {
\t\t\t\t\t$GLOBALS['wpdb']->flush();
\t\t\t\t}
\t\t\t\tif ( method_exists( $GLOBALS['wpdb'], 'close' ) ) {
\t\t\t\t\t$GLOBALS['wpdb']->close();
\t\t\t\t}
\t\t\t}
\t\t} catch ( Throwable $e ) {
\t\t\t$cleanup['close_error'] = get_class( $e ) . ': ' . $e->getMessage();
\t\t}

\t\tunset( $GLOBALS['@duckdb_driver'], $GLOBALS['@duckdb'] );
\t\tif ( function_exists( 'gc_collect_cycles' ) ) {
\t\t\t$cleanup['gc_cycles'] = array( gc_collect_cycles(), gc_collect_cycles() );
\t\t}

\t\tforeach ( array_reverse( $paths ) as $path ) {
\t\t\t$item = array(
\t\t\t\t'path'          => $path,
\t\t\t\t'existed_before' => is_string( $path ) && file_exists( $path ),
\t\t\t\t'deleted'       => false,
\t\t\t);
\t\t\tif ( is_string( $path ) && is_file( $path ) ) {
\t\t\t\t$item['deleted'] = @unlink( $path );
\t\t\t\tif ( ! $item['deleted'] ) {
\t\t\t\t\t$cleanup['cleanup_success'] = false;
\t\t\t\t}
\t\t\t}
\t\t\t$item['exists_after'] = is_string( $path ) && file_exists( $path );
\t\t\t$cleanup['cleanup_paths'][] = $item;
\t\t}

\t\twp_sqlite_duckdb_child_database_copy_record( $cleanup );
\t\tif ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {
\t\t\twp_sqlite_duckdb_child_diagnostics_report( 'child_db_copy_cleanup', true );
\t\t}
\t}

\tfunction wp_sqlite_duckdb_prepare_child_database_copy() {
\t\tif ( '1' !== getenv( 'WP_SQLITE_DUCKDB_CHILD_DB_COPY' ) ) {
\t\t\treturn;
\t\t}
\t\tif ( '1' !== getenv( 'WP_TESTS_SKIP_INSTALL' ) ) {
\t\t\treturn;
\t\t}
\t\tif ( defined( 'DB_ENGINE' ) && 'duckdb' !== strtolower( (string) DB_ENGINE ) ) {
\t\t\treturn;
\t\t}
\t\tif ( defined( 'FQDUCKDB' ) ) {
\t\t\twp_sqlite_duckdb_child_database_copy_record(
\t\t\t\tarray(
\t\t\t\t\t'enabled' => true,
\t\t\t\t\t'attempted' => false,
\t\t\t\t\t'copy_success' => false,
\t\t\t\t\t'copy_error' => 'FQDUCKDB was already defined before the child copy hook.',
\t\t\t\t\t'fqduckdb' => FQDUCKDB,
\t\t\t\t)
\t\t\t);
\t\t\tif ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {
\t\t\t\twp_sqlite_duckdb_child_diagnostics_report( 'after_child_db_copy', true );
\t\t\t}
\t\t\tfwrite( STDERR, 'Error: DuckDB child DB copy hook ran after FQDUCKDB was defined.' . PHP_EOL );
\t\t\texit( 1 );
\t\t}
\t\tif ( defined( 'DUCKDB_FILE' ) ) {
\t\t\twp_sqlite_duckdb_child_database_copy_record(
\t\t\t\tarray(
\t\t\t\t\t'enabled' => true,
\t\t\t\t\t'attempted' => false,
\t\t\t\t\t'copy_success' => false,
\t\t\t\t\t'copy_error' => 'DUCKDB_FILE was already defined before the child copy hook.',
\t\t\t\t\t'duckdb_file' => DUCKDB_FILE,
\t\t\t\t)
\t\t\t);
\t\t\tif ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {
\t\t\t\twp_sqlite_duckdb_child_diagnostics_report( 'after_child_db_copy', true );
\t\t\t}
\t\t\tfwrite( STDERR, 'Error: DuckDB child DB copy hook cannot override an existing DUCKDB_FILE.' . PHP_EOL );
\t\t\texit( 1 );
\t\t}

\t\t$result_file = isset( $GLOBALS['wp_sqlite_duckdb_child_result_file'] ) && is_string( $GLOBALS['wp_sqlite_duckdb_child_result_file'] )
\t\t\t? $GLOBALS['wp_sqlite_duckdb_child_result_file']
\t\t\t: '';
\t\t$database_dir = wp_sqlite_duckdb_child_database_copy_dir();
\t\t$hash = substr( sha1( $result_file . '|' . getmypid() ), 0, 16 );
\t\t$duckdb_file = '.ht.duckdb.phpunit-' . getmypid() . '-' . $hash . '.duckdb';
\t\t$source_path = is_string( $database_dir ) ? $database_dir . '.ht.duckdb' : null;
\t\t$target_path = is_string( $database_dir ) ? $database_dir . $duckdb_file : null;
\t\t$copy_paths = array();
\t\t$sidecars = array();
\t\t$copy_error = null;

\t\twp_sqlite_duckdb_child_database_copy_record(
\t\t\tarray(
\t\t\t\t'enabled' => true,
\t\t\t\t'attempted' => true,
\t\t\t\t'result_file' => $result_file,
\t\t\t\t'database_dir' => $database_dir,
\t\t\t\t'source_path' => $source_path,
\t\t\t\t'source_exists' => is_string( $source_path ) && file_exists( $source_path ),
\t\t\t\t'source_readable' => is_string( $source_path ) && is_readable( $source_path ),
\t\t\t\t'source_size' => wp_sqlite_duckdb_child_database_copy_size( $source_path ),
\t\t\t\t'duckdb_file' => $duckdb_file,
\t\t\t\t'target_path' => $target_path,
\t\t\t\t'target_exists_before' => is_string( $target_path ) && file_exists( $target_path ),
\t\t\t\t'fqduckdb_defined_before_copy' => defined( 'FQDUCKDB' ),
\t\t\t\t'duckdb_file_defined_before_copy' => defined( 'DUCKDB_FILE' ),
\t\t\t)
\t\t);
\t\tif ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {
\t\t\twp_sqlite_duckdb_child_diagnostics_report( 'before_child_db_copy', true );
\t\t}

\t\tif ( '' === $result_file ) {
\t\t\t$copy_error = 'Missing PHPUnit process result file for child DB copy.';
\t\t} elseif ( ! is_string( $database_dir ) || '' === $database_dir ) {
\t\t\t$copy_error = 'Unable to resolve WordPress database directory for child DB copy.';
\t\t} elseif ( ! is_string( $source_path ) || ! is_file( $source_path ) || ! is_readable( $source_path ) ) {
\t\t\t$copy_error = 'Source DuckDB database is not readable for child DB copy.';
\t\t} elseif ( ! is_dir( $database_dir ) || ! is_writable( $database_dir ) ) {
\t\t\t$copy_error = 'WordPress database directory is not writable for child DB copy.';
\t\t} elseif ( ! is_string( $target_path ) || ! copy( $source_path, $target_path ) ) {
\t\t\t$copy_error = 'Failed to copy source DuckDB database for child process.';
\t\t} else {
\t\t\t$copy_paths[] = $target_path;
\t\t\tforeach ( array( '.wal' ) as $suffix ) {
\t\t\t\t$source_sidecar = $source_path . $suffix;
\t\t\t\t$target_sidecar = $target_path . $suffix;
\t\t\t\t$sidecar = array(
\t\t\t\t\t'suffix' => $suffix,
\t\t\t\t\t'source_path' => $source_sidecar,
\t\t\t\t\t'source_exists' => file_exists( $source_sidecar ),
\t\t\t\t\t'source_size' => wp_sqlite_duckdb_child_database_copy_size( $source_sidecar ),
\t\t\t\t\t'target_path' => $target_sidecar,
\t\t\t\t\t'copied' => false,
\t\t\t\t);
\t\t\t\tif ( is_file( $source_sidecar ) ) {
\t\t\t\t\t$sidecar['copied'] = copy( $source_sidecar, $target_sidecar );
\t\t\t\t\t$sidecar['target_size'] = wp_sqlite_duckdb_child_database_copy_size( $target_sidecar );
\t\t\t\t\tif ( $sidecar['copied'] ) {
\t\t\t\t\t\t$copy_paths[] = $target_sidecar;
\t\t\t\t\t} else {
\t\t\t\t\t\t$copy_error = 'Failed to copy DuckDB sidecar for child process: ' . $suffix;
\t\t\t\t\t}
\t\t\t\t}
\t\t\t\t$sidecars[] = $sidecar;
\t\t\t}
\t\t}

\t\tif ( null === $copy_error ) {
\t\t\tdefine( 'DUCKDB_FILE', $duckdb_file );
\t\t\tputenv( 'DUCKDB_FILE=' . $duckdb_file );
\t\t\t$_ENV['DUCKDB_FILE'] = $duckdb_file;
\t\t\t$_SERVER['DUCKDB_FILE'] = $duckdb_file;
\t\t\tregister_shutdown_function( 'wp_sqlite_duckdb_child_database_copy_cleanup' );
\t\t}

\t\twp_sqlite_duckdb_child_database_copy_record(
\t\t\tarray(
\t\t\t\t'copy_success' => null === $copy_error,
\t\t\t\t'copy_error' => $copy_error,
\t\t\t\t'target_exists_after' => is_string( $target_path ) && file_exists( $target_path ),
\t\t\t\t'target_size' => wp_sqlite_duckdb_child_database_copy_size( $target_path ),
\t\t\t\t'sidecars' => $sidecars,
\t\t\t\t'cleanup_paths' => $copy_paths,
\t\t\t\t'cleanup_registered' => null === $copy_error,
\t\t\t\t'duckdb_file_defined_after_copy' => defined( 'DUCKDB_FILE' ),
\t\t\t)
\t\t);
\t\tif ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {
\t\t\twp_sqlite_duckdb_child_diagnostics_report( 'after_child_db_copy', true );
\t\t}

\t\tif ( null !== $copy_error ) {
\t\t\tforeach ( array_reverse( $copy_paths ) as $path ) {
\t\t\t\tif ( is_string( $path ) && is_file( $path ) ) {
\t\t\t\t\t@unlink( $path );
\t\t\t\t}
\t\t\t}
\t\t\tfwrite( STDERR, 'Error: ' . $copy_error . PHP_EOL );
\t\t\texit( 1 );
\t\t}
\t}
}`;
}

function getDuckDBChildFactorySequenceAlignmentPhp() {
	return `if ( ! function_exists( 'wp_sqlite_duckdb_align_child_factory_sequence_with_database' ) ) {
\tfunction wp_sqlite_duckdb_align_child_factory_sequence_with_database() {
\t\tif ( '1' !== getenv( 'WP_TESTS_SKIP_INSTALL' ) ) {
\t\t\treturn;
\t\t}
\t\tif ( ! class_exists( 'WP_UnitTest_Generator_Sequence', false ) ) {
\t\t\treturn;
\t\t}
\t\tif ( ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) ) {
\t\t\treturn;
\t\t}

\t\t$wpdb = $GLOBALS['wpdb'];
\t\tif ( empty( $wpdb->users ) || ! method_exists( $wpdb, 'get_col' ) ) {
\t\t\treturn;
\t\t}

\t\ttry {
\t\t\t$existing_values = $wpdb->get_col(
\t\t\t\t"SELECT user_login FROM {$wpdb->users} WHERE user_login LIKE 'User %'
\t\t\t\tUNION ALL
\t\t\t\tSELECT user_email FROM {$wpdb->users} WHERE user_email LIKE 'user_%@example.org'"
\t\t\t);
\t\t} catch ( Throwable $e ) {
\t\t\t$GLOBALS['wp_sqlite_duckdb_child_factory_sequence_alignment'] = array(
\t\t\t\t'error' => get_class( $e ) . ': ' . $e->getMessage(),
\t\t\t);
\t\t\treturn;
\t\t}

\t\t$max_suffix = null;
\t\tforeach ( (array) $existing_values as $value ) {
\t\t\tif ( preg_match( '/(?:^User |^user_)([0-9]+)(?:@example\\.org)?$/', (string) $value, $matches ) ) {
\t\t\t\t$suffix     = (int) $matches[1];
\t\t\t\t$max_suffix = null === $max_suffix ? $suffix : max( $max_suffix, $suffix );
\t\t\t}
\t\t}

\t\tif ( null === $max_suffix ) {
\t\t\t$GLOBALS['wp_sqlite_duckdb_child_factory_sequence_alignment'] = array(
\t\t\t\t'values_inspected'    => count( (array) $existing_values ),
\t\t\t\t'max_existing_suffix' => null,
\t\t\t);
\t\t\treturn;
\t\t}

\t\t$current_incr = is_numeric( WP_UnitTest_Generator_Sequence::$incr )
\t\t\t? (int) WP_UnitTest_Generator_Sequence::$incr
\t\t\t: -1;
\t\t$new_incr = max( $current_incr, $max_suffix );
\t\tWP_UnitTest_Generator_Sequence::$incr = $new_incr;

\t\t$GLOBALS['wp_sqlite_duckdb_child_factory_sequence_alignment'] = array(
\t\t\t'values_inspected'    => count( (array) $existing_values ),
\t\t\t'previous_incr'       => $current_incr,
\t\t\t'max_existing_suffix' => $max_suffix,
\t\t\t'new_incr'           => $new_incr,
\t\t);

\t\tif ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {
\t\t\twp_sqlite_duckdb_child_diagnostics_report( 'after_factory_sequence_alignment', true );
\t\t}
\t}
	}`;
}

function getDuckDBChildTemplateDiagnosticsPhp() {
	if ( ! enableDuckDBChildDiagnostics ) {
		return '';
	}

	const verbose = process.env.WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS_VERBOSE || '0';
	const stderr = process.env.WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS_STDERR || '0';

	return [
		"putenv( 'WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS=1' );",
		`putenv( ${ phpSingleQuote( `WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS_VERBOSE=${ verbose }` ) } );`,
		`putenv( ${ phpSingleQuote( `WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS_STDERR=${ stderr }` ) } );`,
		`if ( is_readable( ${ phpSingleQuote( duckdbChildDiagnosticsContainerPath ) } ) ) {`,
		`\trequire_once ${ phpSingleQuote( duckdbChildDiagnosticsContainerPath ) };`,
		"\twp_sqlite_duckdb_child_diagnostics_report( 'phpunit_child_template_start', true );",
		'}',
	].join( '\n' );
}

function patchPhpunitChildProcessTemplatesForDiagnostics() {
	const templateDir = '/var/www/vendor/phpunit/phpunit/src/Util/PHP/Template';
	const templateNames = [ 'TestCaseClass.tpl', 'TestCaseMethod.tpl' ];
	const insertionPoint = "if (!defined('STDOUT')) {";
	const snippet = [
		'/* DuckDB child-process diagnostics. Generated by the SQLite integration workflow. */',
		`if ( ! defined( 'DUCKDB_PHP_AUTOLOAD' ) ) {`,
		`\tdefine( 'DUCKDB_PHP_AUTOLOAD', ${ phpSingleQuote( duckdbAutoloadCompatibilityWrapperContainerPath ) } );`,
		'}',
		`putenv( ${ phpSingleQuote( `DUCKDB_PHP_AUTOLOAD=${ duckdbAutoloadCompatibilityWrapperContainerPath }` ) } );`,
		`$_ENV[ 'DUCKDB_PHP_AUTOLOAD' ] = ${ phpSingleQuote( duckdbAutoloadCompatibilityWrapperContainerPath ) };`,
		`$_SERVER[ 'DUCKDB_PHP_AUTOLOAD' ] = ${ phpSingleQuote( duckdbAutoloadCompatibilityWrapperContainerPath ) };`,
		"if ( ! defined( 'DB_ENGINE' ) ) {",
		"\tdefine( 'DB_ENGINE', 'duckdb' );",
		'}',
		"putenv( 'DB_ENGINE=duckdb' );",
		"$_ENV[ 'DB_ENGINE' ] = 'duckdb';",
		"$_SERVER[ 'DB_ENGINE' ] = 'duckdb';",
		"putenv( 'WP_TESTS_SKIP_INSTALL=1' );",
		"$_ENV[ 'WP_TESTS_SKIP_INSTALL' ] = '1';",
		"$_SERVER[ 'WP_TESTS_SKIP_INSTALL' ] = '1';",
		getPhpunitForwardedEnvironmentPhp(),
		"if ( defined( 'DUCKDB_PHP_AUTOLOAD' ) && is_readable( DUCKDB_PHP_AUTOLOAD ) ) {",
		"\trequire_once DUCKDB_PHP_AUTOLOAD;",
		'}',
		`if ( empty( $GLOBALS['__PHPUNIT_BOOTSTRAP'] ) && is_readable( ${ phpSingleQuote( wordPressPhpunitBootstrapContainerPath ) } ) ) {`,
		`\t$GLOBALS['__PHPUNIT_BOOTSTRAP'] = ${ phpSingleQuote( wordPressPhpunitBootstrapContainerPath ) };`,
		'}',
		"$GLOBALS['wp_sqlite_duckdb_child_result_file'] = '{processResultFile}';",
		getDuckDBChildTemplateDiagnosticsPhp(),
		getDuckDBChildDatabaseCopyPhp(),
		getDuckDBChildFactorySequenceAlignmentPhp(),
		'',
	].join( '\n' );
	const lifecycleReplacements = [
		[
			"ini_set('display_errors', 'stderr');",
			[
				"if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"    wp_sqlite_duckdb_child_diagnostics_report( 'before_display_errors_setup', true );",
				'}',
				"ini_set('display_errors', 'stderr');",
				"if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"    wp_sqlite_duckdb_child_diagnostics_report( 'after_display_errors_setup', true );",
				'}',
			].join( '\n' ),
		],
		[
			"if ($composerAutoload) {",
			[
				"if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"    wp_sqlite_duckdb_child_diagnostics_report( 'before_composer_autoload', true );",
				'}',
				"if ($composerAutoload) {",
			].join( '\n' ),
		],
		[
			'function __phpunit_run_isolated_test()',
			[
				"if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"    wp_sqlite_duckdb_child_diagnostics_report( 'after_composer_autoload', true );",
				'}',
				'function __phpunit_run_isolated_test()',
			].join( '\n' ),
		],
		[
			'    $test->run($result);',
			[
				"    if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"        wp_sqlite_duckdb_child_diagnostics_report( 'before_test_run', true );",
				'    }',
				'    $test->run($result);',
				"    if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"        wp_sqlite_duckdb_child_diagnostics_report( 'after_test_run', true );",
				'    }',
			].join( '\n' ),
		],
		[
			[
				'    file_put_contents(',
				"        '{processResultFile}',",
				'        serialize(',
				'            [',
				"                'testResult'    => $test->getResult(),",
				"                'numAssertions' => $test->getNumAssertions(),",
				"                'result'        => $result,",
				"                'output'        => $output",
				'            ]',
				'        )',
				'    );',
			].join( '\n' ),
			[
				"    if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"        wp_sqlite_duckdb_child_diagnostics_report( 'before_process_result_write', true );",
				'    }',
				'    $__wp_sqlite_duckdb_child_result_payload = serialize(',
				'        [',
				"            'testResult'    => $test->getResult(),",
				"            'numAssertions' => $test->getNumAssertions(),",
				"            'result'        => $result,",
				"            'output'        => $output",
				'        ]',
				'    );',
				'    $__wp_sqlite_duckdb_child_result_bytes = file_put_contents(',
				"        '{processResultFile}',",
				'        $__wp_sqlite_duckdb_child_result_payload',
				'    );',
				"    $GLOBALS['wp_sqlite_duckdb_child_result_write'] = [",
				"        'payload_length' => strlen( $__wp_sqlite_duckdb_child_result_payload ),",
				"        'bytes'          => $__wp_sqlite_duckdb_child_result_bytes,",
				"        'success'        => false !== $__wp_sqlite_duckdb_child_result_bytes,",
				"        'file_size'      => is_file( '{processResultFile}' ) ? filesize( '{processResultFile}' ) : null,",
				"        'file_sha1'      => is_file( '{processResultFile}' ) ? sha1_file( '{processResultFile}' ) : null,",
				'    ];',
				"    if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"        wp_sqlite_duckdb_child_diagnostics_report( 'after_process_result_write', true );",
				'    }',
			].join( '\n' ),
		],
		[
			'{included_files}',
			[
				"if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"    wp_sqlite_duckdb_child_diagnostics_report( 'before_included_files_restore', true );",
				'}',
				'{included_files}',
				"if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"    wp_sqlite_duckdb_child_diagnostics_report( 'after_included_files_restore', true );",
				'}',
			].join( '\n' ),
		],
		[
			'{globals}',
			[
				"if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"    wp_sqlite_duckdb_child_diagnostics_report( 'before_globals_restore', true );",
				'}',
				'{globals}',
				"if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"    wp_sqlite_duckdb_child_diagnostics_report( 'after_globals_restore', true );",
				'}',
			].join( '\n' ),
		],
		[
			"if (isset($GLOBALS['__PHPUNIT_BOOTSTRAP'])) {",
			[
				"if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"    wp_sqlite_duckdb_child_diagnostics_report( 'before_bootstrap_check', true );",
				'}',
				"if (isset($GLOBALS['__PHPUNIT_BOOTSTRAP'])) {",
				"    if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"        wp_sqlite_duckdb_child_diagnostics_report( 'before_bootstrap_require', true );",
				'    }',
			].join( '\n' ),
		],
		[
			"    unset($GLOBALS['__PHPUNIT_BOOTSTRAP']);",
			[
				"    if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"        wp_sqlite_duckdb_child_diagnostics_report( 'after_bootstrap_require', true );",
				'    }',
				"    if ( function_exists( 'wp_sqlite_duckdb_align_child_factory_sequence_with_database' ) ) {",
				'        wp_sqlite_duckdb_align_child_factory_sequence_with_database();',
				'    }',
				"    unset($GLOBALS['__PHPUNIT_BOOTSTRAP']);",
			].join( '\n' ),
		],
		[
			'__phpunit_run_isolated_test();',
			[
				"if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"    wp_sqlite_duckdb_child_diagnostics_report( 'before_isolated_test_entry', true );",
				'}',
				'__phpunit_run_isolated_test();',
				"if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"    wp_sqlite_duckdb_child_diagnostics_report( 'after_isolated_test_entry', true );",
				'}',
			].join( '\n' ),
		],
	];
	const optionalLifecycleReplacements = [
		[
			"    $test = new {className}('{name}', unserialize('{data}'), '{dataName}');",
			[
				"    if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"        wp_sqlite_duckdb_child_diagnostics_report( 'before_test_construct', true );",
				'    }',
				"    $test = new {className}('{name}', unserialize('{data}'), '{dataName}');",
				"    if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_test_identity' ) ) {",
				"        $GLOBALS['wp_sqlite_duckdb_child_test_identity'] = wp_sqlite_duckdb_child_diagnostics_test_identity( $test );",
				'    }',
				"    if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"        wp_sqlite_duckdb_child_diagnostics_report( 'after_test_construct', true );",
				'    }',
			].join( '\n' ),
		],
		[
			"    $test = new {className}('{methodName}', unserialize('{data}'), '{dataName}');",
			[
				"    if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"        wp_sqlite_duckdb_child_diagnostics_report( 'before_test_construct', true );",
				'    }',
				"    $test = new {className}('{methodName}', unserialize('{data}'), '{dataName}');",
				"    if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_test_identity' ) ) {",
				"        $GLOBALS['wp_sqlite_duckdb_child_test_identity'] = wp_sqlite_duckdb_child_diagnostics_test_identity( $test );",
				'    }',
				"    if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"        wp_sqlite_duckdb_child_diagnostics_report( 'after_test_construct', true );",
				'    }',
			].join( '\n' ),
		],
	];
	const patchScript = [
		`$template_dir = ${ phpSingleQuote( templateDir ) };`,
		`$template_names = array( ${ templateNames.map( phpSingleQuote ).join( ', ' ) } );`,
		`$insertion_point = ${ phpSingleQuote( insertionPoint ) };`,
		`$snippet = ${ phpSingleQuote( snippet ) };`,
		'$lifecycle_replacements = array(',
		...lifecycleReplacements.map( ( [ needle, replacement ] ) => (
			`\t${ phpSingleQuote( needle ) } => ${ phpSingleQuote( replacement ) },`
		) ),
		');',
		'$optional_lifecycle_replacements = array(',
		...optionalLifecycleReplacements.map( ( [ needle, replacement ] ) => (
			`\t${ phpSingleQuote( needle ) } => ${ phpSingleQuote( replacement ) },`
		) ),
		');',
		'$patched_templates = 0;',
		'if ( ! is_dir( $template_dir ) ) {',
		'\tfwrite( STDERR, "Error: PHPUnit child process template directory not found at {$template_dir}." . PHP_EOL );',
		'\texit( 1 );',
		'}',
		'foreach ( $template_names as $template_name ) {',
		'\t$file = $template_dir . DIRECTORY_SEPARATOR . $template_name;',
		'\tif ( ! is_file( $file ) ) {',
		'\t\tcontinue;',
		'\t}',
		'\t$contents = file_get_contents( $file );',
		'\tif ( ! is_string( $contents ) ) {',
		'\t\tfwrite( STDERR, "Error: Unable to read PHPUnit child process template {$file}." . PHP_EOL );',
		'\t\texit( 1 );',
		'\t}',
		'\t$replace_count = 0;',
		"\tif ( false === strpos( $contents, 'phpunit_child_template_start' ) ) {",
		'\t\tif ( false !== strpos( $contents, $insertion_point ) ) {',
		'\t\t\t$contents = str_replace( $insertion_point, $snippet . $insertion_point, $contents, $replace_count );',
		'\t\t} else {',
		"\t\t\tif ( 0 !== strpos( $contents, '<?php' ) ) {",
		'\t\t\t\tfwrite( STDERR, "Error: Unable to find PHP open tag in PHPUnit child process template {$file}." . PHP_EOL );',
		'\t\t\t\texit( 1 );',
		'\t\t\t}',
		"\t\t\t$contents = '<?php' . PHP_EOL . $snippet . substr( $contents, 5 );",
		'\t\t\t$replace_count = 1;',
		'\t\t}',
		'\t\tif ( 1 > $replace_count ) {',
		'\t\t\tfwrite( STDERR, "Error: Unable to patch PHPUnit child process template {$file}." . PHP_EOL );',
		'\t\t\texit( 1 );',
		'\t\t}',
		'\t}',
		"\tif ( false === strpos( $contents, 'before_process_result_write' ) ) {",
		'\t\tforeach ( $lifecycle_replacements as $needle => $replacement ) {',
		'\t\t\t$lifecycle_replace_count = 0;',
		'\t\t\tif ( false === strpos( $contents, $needle ) ) {',
		'\t\t\t\tfwrite( STDERR, "Error: Unable to find lifecycle marker needle in PHPUnit child process template {$file}." . PHP_EOL );',
		'\t\t\t\texit( 1 );',
		'\t\t\t}',
		'\t\t\t$contents = str_replace( $needle, $replacement, $contents, $lifecycle_replace_count );',
		'\t\t\tif ( 1 > $lifecycle_replace_count ) {',
		'\t\t\t\tfwrite( STDERR, "Error: Unable to patch lifecycle marker in PHPUnit child process template {$file}." . PHP_EOL );',
		'\t\t\t\texit( 1 );',
		'\t\t\t}',
		'\t\t}',
		'\t\t$optional_lifecycle_replace_count = 0;',
		'\t\tforeach ( $optional_lifecycle_replacements as $needle => $replacement ) {',
		'\t\t\t$lifecycle_replace_count = 0;',
		'\t\t\t$contents = str_replace( $needle, $replacement, $contents, $lifecycle_replace_count );',
		'\t\t\t$optional_lifecycle_replace_count += $lifecycle_replace_count;',
		'\t\t}',
		'\t\tif ( 1 > $optional_lifecycle_replace_count ) {',
		'\t\t\tfwrite( STDERR, "Error: Unable to patch test identity lifecycle marker in PHPUnit child process template {$file}." . PHP_EOL );',
		'\t\t\texit( 1 );',
		'\t\t}',
		'\t}',
		'\tif ( false === file_put_contents( $file, $contents ) ) {',
		'\t\tfwrite( STDERR, "Error: Unable to write PHPUnit child process template {$file}." . PHP_EOL );',
		'\t\texit( 1 );',
		'\t}',
		'\t++$patched_templates;',
		'}',
		'if ( 0 === $patched_templates ) {',
		'\tfwrite( STDERR, "Error: No PHPUnit child process templates were patched under {$template_dir}." . PHP_EOL );',
		'\texit( 1 );',
		'}',
	].join( '\n' );

	runWordPressDockerCompose( [ 'run', '--rm', 'php', 'php', '-r', patchScript ], { stdio: 'inherit' } );
}

function patchPhpunitParentProcessIsolationHooks() {
	const file = '/var/www/vendor/phpunit/phpunit/src/Framework/TestCase.php';
	const needle = [
		'            $php = AbstractPhpProcess::factory();',
		'            $php->runTestJob($template->render(), $this, $result, $processResultFile);',
	].join( '\n' );
	const replacement = [
		'            $php = AbstractPhpProcess::factory();',
		"            if (function_exists('wp_sqlite_duckdb_release_parent_connection_for_isolated_child')) {",
		'                wp_sqlite_duckdb_release_parent_connection_for_isolated_child($processResultFile, $this);',
		'            }',
		'            try {',
		'                $php->runTestJob($template->render(), $this, $result, $processResultFile);',
		'            } finally {',
		"                if (function_exists('wp_sqlite_duckdb_restore_parent_connection_after_isolated_child')) {",
		'                    wp_sqlite_duckdb_restore_parent_connection_after_isolated_child($processResultFile, $this);',
		'                }',
		'            }',
	].join( '\n' );
	const patchScript = [
		`$file = ${ phpSingleQuote( file ) };`,
		`$needle = ${ phpSingleQuote( needle ) };`,
		`$replacement = ${ phpSingleQuote( replacement ) };`,
		'if ( ! is_readable( $file ) ) {',
		'\tfwrite( STDERR, "Error: PHPUnit TestCase.php is not readable at {$file}." . PHP_EOL );',
		'\texit( 1 );',
		'}',
		'$contents = file_get_contents( $file );',
		'if ( false === $contents ) {',
		'\tfwrite( STDERR, "Error: Unable to read PHPUnit TestCase.php at {$file}." . PHP_EOL );',
		'\texit( 1 );',
		'}',
		"if ( false === strpos( $contents, 'wp_sqlite_duckdb_release_parent_connection_for_isolated_child' ) ) {",
		'\t$count = 0;',
		'\t$contents = str_replace( $needle, $replacement, $contents, $count );',
		'\tif ( 1 !== $count ) {',
		'\t\tfwrite( STDERR, "Error: Unable to patch PHPUnit parent process-isolation hook in {$file}." . PHP_EOL );',
		'\t\texit( 1 );',
		'\t}',
		'\tif ( false === file_put_contents( $file, $contents ) ) {',
		'\t\tfwrite( STDERR, "Error: Unable to write patched PHPUnit TestCase.php at {$file}." . PHP_EOL );',
		'\t\texit( 1 );',
		'\t}',
		'}',
	].join( '\n' );

	runWordPressDockerCompose( [ 'run', '--rm', 'php', 'php', '-r', patchScript ], { stdio: 'inherit' } );
}

function getPhpunitForwardedEnvironmentPhp() {
	const environmentNames = [
		'WP_DUCKDB_QUERY_PROFILE',
		'WP_DUCKDB_QUERY_PROFILE_INTERVAL',
		'WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS',
		'WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS_VERBOSE',
		'WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS_STDERR',
		'WP_SQLITE_DUCKDB_CHILD_DB_COPY',
		'WP_SQLITE_DUCKDB_PREPARE_OBJECT_DIAGNOSTICS',
	];

	return environmentNames
		.filter( name => Object.prototype.hasOwnProperty.call( process.env, name ) )
		.map( name => {
			const value = process.env[ name ];
			return [
				`putenv( ${ phpSingleQuote( `${ name }=${ value }` ) } );`,
				`$_ENV[ ${ phpSingleQuote( name ) } ] = ${ phpSingleQuote( value ) };`,
				`$_SERVER[ ${ phpSingleQuote( name ) } ] = ${ phpSingleQuote( value ) };`,
			].join( '\n' );
		} )
		.join( '\n' );
}

function getDuckDBParentProcessIsolationPhp() {
	return `if ( ! function_exists( 'wp_sqlite_duckdb_is_active_parent_process' ) ) {
\tfunction wp_sqlite_duckdb_is_active_parent_process() {
\t\tif ( ! defined( 'DB_ENGINE' ) || 'duckdb' !== strtolower( (string) DB_ENGINE ) ) {
\t\t\treturn false;
\t\t}

\t\treturn isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] );
\t}

\tfunction wp_sqlite_duckdb_parent_process_isolation_diagnostics_enabled() {
\t\t$value = getenv( 'WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS' );
\t\treturn is_string( $value ) && '' !== $value && '0' !== $value && 'false' !== strtolower( $value );
\t}

\tfunction wp_sqlite_duckdb_parent_process_isolation_test_identity( $test ) {
\t\tif ( ! is_object( $test ) ) {
\t\t\treturn null;
\t\t}

\t\t$identity = array(
\t\t\t'class'     => get_class( $test ),
\t\t\t'name'      => null,
\t\t\t'to_string' => null,
\t\t);

\t\ttry {
\t\t\tif ( method_exists( $test, 'getName' ) ) {
\t\t\t\t$identity['name'] = $test->getName( false );
\t\t\t}
\t\t} catch ( Throwable $e ) {
\t\t\t$identity['name'] = get_class( $e ) . ': ' . $e->getMessage();
\t\t}

\t\ttry {
\t\t\tif ( method_exists( $test, 'toString' ) ) {
\t\t\t\t$identity['to_string'] = $test->toString();
\t\t\t}
\t\t} catch ( Throwable $e ) {
\t\t\t$identity['to_string'] = get_class( $e ) . ': ' . $e->getMessage();
\t\t}

\t\treturn $identity;
\t}

\tfunction wp_sqlite_duckdb_parent_process_isolation_report( $stage, $process_result_file = null, $test = null, $extra = array() ) {
\t\tif ( ! wp_sqlite_duckdb_parent_process_isolation_diagnostics_enabled() ) {
\t\t\treturn;
\t\t}

\t\t$wpdb = isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] )
\t\t\t? $GLOBALS['wpdb']
\t\t\t: null;
\t\t$result_file_size = is_string( $process_result_file ) && file_exists( $process_result_file )
\t\t\t? filesize( $process_result_file )
\t\t\t: null;
\t\tif ( false === $result_file_size ) {
\t\t\t$result_file_size = null;
\t\t}

\t\t$payload = array(
\t\t\t'stage'            => $stage,
\t\t\t'pid'              => getmypid(),
\t\t\t'process_result_file' => is_string( $process_result_file ) ? $process_result_file : null,
\t\t\t'process_result_file_size' => $result_file_size,
\t\t\t'test_identity'    => wp_sqlite_duckdb_parent_process_isolation_test_identity( $test ),
\t\t\t'wpdb_class'       => is_object( $wpdb ) ? get_class( $wpdb ) : null,
\t\t\t'wpdb_last_error'  => is_object( $wpdb ) && isset( $wpdb->last_error ) ? $wpdb->last_error : null,
\t\t\t'wpdb_has_dbh'     => is_object( $wpdb ) && isset( $wpdb->dbh ) && is_object( $wpdb->dbh ),
\t\t\t'extra'            => $extra,
\t\t);
\t\t$encoded = json_encode( $payload );
\t\tif ( is_string( $encoded ) ) {
\t\t\t@file_put_contents( ${ phpSingleQuote( duckdbChildDiagnosticsLogContainerPath ) }, 'WP_SQLITE_DUCKDB_PARENT_DIAGNOSTIC ' . $encoded . PHP_EOL, FILE_APPEND | LOCK_EX );
\t\t}
\t}

\tfunction wp_sqlite_duckdb_release_parent_connection_for_isolated_child( $process_result_file = null, $test = null ) {
\t\tif ( ! wp_sqlite_duckdb_is_active_parent_process() ) {
\t\t\treturn;
\t\t}

\t\t$wpdb = $GLOBALS['wpdb'];
\t\t$extra = array(
\t\t\t'flush_called'     => false,
\t\t\t'checkpoint_called' => false,
\t\t\t'close_called'     => false,
\t\t\t'gc_cycles'        => array(),
\t\t\t'release_sleep_us' => 250000,
\t\t);

\t\twp_sqlite_duckdb_parent_process_isolation_report( 'parent_before_release_for_child', $process_result_file, $test );

\t\ttry {
\t\t\tif ( method_exists( $wpdb, 'flush' ) ) {
\t\t\t\t$wpdb->flush();
\t\t\t\t$extra['flush_called'] = true;
\t\t\t}
\t\t} catch ( Throwable $e ) {
\t\t\t$extra['flush_error'] = get_class( $e ) . ': ' . $e->getMessage();
\t\t}

\t\ttry {
\t\t\tif ( isset( $wpdb->dbh ) && is_object( $wpdb->dbh ) && method_exists( $wpdb->dbh, 'get_connection' ) ) {
\t\t\t\t$connection = $wpdb->dbh->get_connection();
\t\t\t\tif ( is_object( $connection ) && method_exists( $connection, 'query' ) ) {
\t\t\t\t\t$connection->query( 'CHECKPOINT' );
\t\t\t\t\t$extra['checkpoint_called'] = true;
\t\t\t\t}
\t\t\t}
\t\t} catch ( Throwable $e ) {
\t\t\t// The child may still be able to open the database if no checkpoint is needed.
\t\t\t$extra['checkpoint_error'] = get_class( $e ) . ': ' . $e->getMessage();
\t\t}

\t\tif ( method_exists( $wpdb, 'close' ) ) {
\t\t\t$wpdb->close();
\t\t\t$extra['close_called'] = true;
\t\t}

\t\ttry {
\t\t\t$wpdb->dbh = null;
\t\t} catch ( Throwable $e ) {
\t\t}

\t\ttry {
\t\t\t$wpdb->last_error = '';
\t\t} catch ( Throwable $e ) {
\t\t}

\t\tunset( $GLOBALS['@duckdb_driver'], $GLOBALS['@duckdb'] );

\t\tif ( function_exists( 'gc_collect_cycles' ) ) {
\t\t\t$extra['gc_cycles'][] = gc_collect_cycles();
\t\t\t$extra['gc_cycles'][] = gc_collect_cycles();
\t\t}

\t\tif ( function_exists( 'usleep' ) ) {
\t\t\tusleep( $extra['release_sleep_us'] );
\t\t}

\t\twp_sqlite_duckdb_parent_process_isolation_report( 'parent_after_release_for_child', $process_result_file, $test, $extra );
\t}

\tfunction wp_sqlite_duckdb_restore_parent_connection_after_isolated_child( $process_result_file = null, $test = null ) {
\t\tif ( ! wp_sqlite_duckdb_is_active_parent_process() ) {
\t\t\treturn;
\t\t}

\t\t$wpdb = $GLOBALS['wpdb'];
\t\tif ( ! method_exists( $wpdb, 'db_connect' ) ) {
\t\t\treturn;
\t\t}

\t\twp_sqlite_duckdb_parent_process_isolation_report( 'parent_before_restore_after_child', $process_result_file, $test );

\t\t$last_message = 'unknown DuckDB reconnect error';
\t\tfor ( $attempt = 1; $attempt <= 5; ++$attempt ) {
\t\t\ttry {
\t\t\t\t$wpdb->last_error = '';
\t\t\t} catch ( Throwable $e ) {
\t\t\t}

\t\t\ttry {
\t\t\t\tif ( false !== $wpdb->db_connect( false ) ) {
\t\t\t\t\twp_sqlite_duckdb_parent_process_isolation_report(
\t\t\t\t\t\t'parent_after_restore_after_child',
\t\t\t\t\t\t$process_result_file,
\t\t\t\t\t\t$test,
\t\t\t\t\t\tarray(
\t\t\t\t\t\t\t'attempt' => $attempt,
\t\t\t\t\t\t\t'success' => true,
\t\t\t\t\t\t)
\t\t\t\t\t);
\t\t\t\t\treturn;
\t\t\t\t}
\t\t\t} catch ( Throwable $e ) {
\t\t\t\t$last_message = get_class( $e ) . ': ' . $e->getMessage();
\t\t\t}

\t\t\tif ( isset( $wpdb->last_error ) && is_string( $wpdb->last_error ) && '' !== $wpdb->last_error ) {
\t\t\t\t$last_message = $wpdb->last_error;
\t\t\t}

\t\t\twp_sqlite_duckdb_parent_process_isolation_report(
\t\t\t\t'parent_restore_retry_after_child',
\t\t\t\t$process_result_file,
\t\t\t\t$test,
\t\t\t\tarray(
\t\t\t\t\t'attempt' => $attempt,
\t\t\t\t\t'error'   => $last_message,
\t\t\t\t)
\t\t\t);

\t\t\tif ( function_exists( 'usleep' ) ) {
\t\t\t\tusleep( 100000 * $attempt );
\t\t\t}
\t\t}

\t\tfwrite( STDERR, 'Error: Unable to reconnect DuckDB after isolated PHPUnit child: ' . $last_message . PHP_EOL );
\t\texit( 1 );
\t}
}`;
}

function verifyPhpunitCompatibilityFiles() {
	const check = [
		`require ${ phpSingleQuote( phpunitCompatibilityPrependContainerPath ) };`,
		'require_once DUCKDB_PHP_AUTOLOAD;',
		"exit( method_exists( 'PHPUnit\\\\TextUI\\\\TestRunner', 'run' ) ? 0 : 1 );",
	].join( ' ' );
	const directWrapperCheck = [
		`require ${ phpSingleQuote( duckdbAutoloadCompatibilityWrapperContainerPath ) };`,
		"exit( method_exists( 'PHPUnit\\\\TextUI\\\\TestRunner', 'run' ) ? 0 : 1 );",
	].join( ' ' );

	runWordPressDockerCompose( [ 'run', '--rm', 'php', 'php', '-r', check ], { stdio: 'inherit' } );
	runWordPressDockerCompose( [ 'run', '--rm', 'php', 'php', '-r', directWrapperCheck ], { stdio: 'inherit' } );
}

function addPhpunitPrependArgument( command, prependPath ) {
	if ( command.includes( '--prepend' ) ) {
		return command;
	}

	const prependArgument = `--prepend=${ prependPath }`;
	const composerArgumentSeparator = ' -- ';

	if ( command.includes( composerArgumentSeparator ) ) {
		return command.replace(
			composerArgumentSeparator,
			`${ composerArgumentSeparator }${ shellQuote( prependArgument ) } `
		);
	}

	if ( command.includes( 'composer run wp-test-php-duckdb' ) ) {
		return `${ command } -- ${ shellQuote( prependArgument ) }`;
	}

	return `${ command } ${ shellQuote( prependArgument ) }`;
}

function verifyNativeParserExtension() {
	const verifier = path.join( __dirname, '..', '..', 'wordpress', 'native-verify-extension.php' );
	if ( ! fs.existsSync( verifier ) ) {
		console.error( `Error: Native parser verifier not found at ${ verifier }.` );
		process.exit( 1 );
	}

	execSync( 'composer run wp-test-ensure-env', { stdio: 'inherit' } );
	execSync(
		'cd wordpress && node tools/local-env/scripts/docker.js run --rm php php /var/www/native-verify-extension.php',
		{ stdio: 'inherit' }
	);
}

function ensureCompatiblePhpunitRunner() {
	if ( ! ensurePhpunitCompatibility ) {
		return;
	}

	if ( skipPhpunitCompatibilityCheck ) {
		console.log( 'Skipping WordPress PHPUnit runner compatibility check.' );
		return;
	}

	console.log( 'Ensuring WordPress PHPUnit test environment is available...' );
	execSync( phpunitEnsureEnvironmentCommand, { stdio: 'inherit' } );

	if ( hasCompatiblePhpunitRunner() ) {
		return;
	}

	console.log(
		`PHPUnit\\TextUI\\TestRunner::run() is unavailable; constraining PHPUnit to ${ phpunitCompatibilityConstraint }...`
	);
	ensureWordPressGitSafeDirectory();
	installCompatiblePhpunitRunner();

	if ( ! hasCompatiblePhpunitRunner() ) {
		console.error( 'Error: WordPress PHPUnit runner is still incompatible after Composer require.' );
		process.exit( 1 );
	}
}

function ensureWordPressGitSafeDirectory() {
	runWordPressDockerCompose(
		[
			'run',
			'--rm',
			'php',
			'sh',
			'-lc',
			'if command -v git >/dev/null 2>&1; then git config --global --add safe.directory /var/www; fi',
		],
		{ stdio: 'inherit' }
	);
}

function installCompatiblePhpunitRunner() {
	const phpunitPackage = `phpunit/phpunit:${ phpunitCompatibilityConstraint }`;

	runWordPressDockerCompose(
		[
			'run',
			'--rm',
			'php',
			'composer',
			'require',
			'--dev',
			'--no-interaction',
			'--no-progress',
			'--with-all-dependencies',
			phpunitPackage,
		],
		{ stdio: 'inherit' }
	);
}

function hasCompatiblePhpunitRunner() {
	const check = "require 'vendor/autoload.php'; exit( method_exists( 'PHPUnit\\\\TextUI\\\\TestRunner', 'run' ) ? 0 : 1 );";

	try {
		runWordPressDockerCompose( [ 'run', '--rm', 'php', 'php', '-r', check ], { stdio: 'ignore' } );
		return true;
	} catch ( error ) {
		return false;
	}
}

function runWordPressDockerCompose( args, options ) {
	const command = [
		'cd wordpress &&',
		'COMPOSE_IGNORE_ORPHANS=true docker compose',
		...getWordPressComposeArgs().map( shellQuote ),
		...args.map( shellQuote ),
	].join( ' ' );

	execSync( command, options );
}

function getWordPressComposeArgs() {
	const composeArgs = [ '-f', 'docker-compose.yml' ];

	if ( fs.existsSync( path.join( repoRoot, 'wordpress', 'docker-compose.override.yml' ) ) ) {
		composeArgs.push( '-f', 'docker-compose.override.yml' );
	}

	return composeArgs;
}

function shellQuote( value ) {
	return `'${ String( value ).replace( /'/g, "'\\''" ) }'`;
}

function phpSingleQuote( value ) {
	return `'${ String( value ).replace( /\\/g, '\\\\' ).replace( /'/g, "\\'" ) }'`;
}

try {
	if ( requiresNativeParserExtension ) {
		verifyNativeParserExtension();
	}

	markProgress( 'compatibility_start' );
	ensureCompatiblePhpunitRunner();
	markProgress( 'compatibility_done' );
	const effectivePhpunitCommand = preparePhpunitCommand();
	markProgress( 'command_prepared' );

	let phpunitTimedOut = false;
	let phpunitCommandSeconds = 0;
	let phpunitStartedAt = 0;

	try {
		markProgress( 'phpunit_start', { max_seconds: phpunitMaxSeconds || 'none' } );
		phpunitStartedAt = Date.now();
		execSync(
			effectivePhpunitCommand,
			{
				stdio: 'inherit',
				timeout: phpunitMaxSeconds ? phpunitMaxSeconds * 1000 : undefined,
				killSignal: 'SIGTERM',
			}
		);
		phpunitCommandSeconds = ( Date.now() - phpunitStartedAt ) / 1000;
		markProgress( 'phpunit_exit_zero', { command_seconds: phpunitCommandSeconds.toFixed( 3 ) } );
		printDuckDBChildDiagnosticsLog();
		console.log( '\n⚠️ All tests passed, checking if expected errors/failures occurred...' );
	} catch ( error ) {
		phpunitCommandSeconds = phpunitStartedAt ? ( Date.now() - phpunitStartedAt ) / 1000 : 0;
		phpunitTimedOut = Boolean(
			phpunitMaxSeconds &&
			(
				phpunitCommandSeconds >= phpunitMaxSeconds ||
				error.signal === 'SIGTERM' ||
				String( error.message || '' ).includes( 'ETIMEDOUT' )
			)
		);
		markProgress(
			phpunitTimedOut ? 'phpunit_timeout' : 'phpunit_exit_nonzero',
			{ command_seconds: phpunitCommandSeconds.toFixed( 3 ) }
		);
		printDuckDBChildDiagnosticsLog();
		if ( phpunitTimedOut ) {
			console.error( `\n❌ PHPUnit command exceeded ${ phpunitMaxSeconds }s for ${ phpunitTimingLabel }.` );
			process.exit( 1 );
		}
		console.log( '\n⚠️ Some tests errored/failed (expected). Analyzing results...' );
	}

	// Read the JUnit XML test output:
	if ( ! fs.existsSync( junitOutputFile ) ) {
		console.error( 'Error: JUnit output file not found!' );
		process.exit( 1 );
	}
	const junitXml = fs.readFileSync( junitOutputFile, 'utf8' );
	markProgress( 'junit_read', { command_seconds: phpunitCommandSeconds.toFixed( 3 ) } );

	// Extract test info from the XML:
	const actualTests = [];
	const actualErrors = [];
	const actualFailures = [];
	let junitTestcaseSeconds = 0;
	for ( const testcase of junitXml.matchAll( /<testcase([^>]*)\/>|<testcase([^>]*)>([\s\S]*?)<\/testcase>/g ) ) {
		const attributes = {};
		const attributesString = testcase[2] ?? testcase[1];
		for ( const attribute of attributesString.matchAll( /(\w+)="([^"]*)"/g ) ) {
			attributes[attribute[1]] = attribute[2];
		}

		const content = testcase[3] ?? '';
		const fqn = attributes.class ? `${attributes.class}::${attributes.name}` : attributes.name;
		actualTests.push( fqn );
		if ( attributes.time ) {
			const testcaseSeconds = Number( attributes.time );
			if ( Number.isFinite( testcaseSeconds ) ) {
				junitTestcaseSeconds += testcaseSeconds;
			}
		}

		const hasError = content.includes( '<error' );
		const hasFailure = content.includes( '<failure' );

		if ( hasError ) {
			actualErrors.push( fqn );
		}

		if ( hasFailure ) {
			actualFailures.push( fqn );
		}
	}

	const timingSummary = {
		label: phpunitTimingLabel,
		command_seconds: phpunitCommandSeconds.toFixed( 3 ),
		junit_testcase_seconds: junitTestcaseSeconds.toFixed( 3 ),
		tests: actualTests.length,
		errors: actualErrors.length,
		failures: actualFailures.length,
		baseline_seconds: phpunitBaselineSeconds || '',
		max_seconds: phpunitMaxSeconds || '',
		min_tests: phpunitMinTests || '',
	};
	console.log(
		'WP_SQLITE_PHPUNIT_TIMING ' +
		Object.entries( timingSummary ).map( ( [ key, value ] ) => `${ key }=${ value }` ).join( ' ' )
	);
	appendTimingSummary( timingSummary );
	markProgress( 'junit_parsed', { tests: actualTests.length } );

	let isSuccess = true;

	if ( phpunitBaselineSeconds && phpunitCommandSeconds > phpunitBaselineSeconds ) {
		console.error(
			`\n❌ PHPUnit command took ${ phpunitCommandSeconds.toFixed( 3 ) }s, above ${ phpunitBaselineSeconds }s baseline.`
		);
		isSuccess = false;
	}

	if ( phpunitMinTests && actualTests.length < phpunitMinTests ) {
		console.error(
			`\n❌ PHPUnit command ran ${ actualTests.length } tests, below required minimum ${ phpunitMinTests }.`
		);
		isSuccess = false;
	}

	// Check if all expected errors actually errored
	const expectedErrorsInScope = filterExpectedResultsInScope( expectedErrors, actualTests );
	const unexpectedNonErrors = expectedErrorsInScope.filter( test => ! actualErrors.includes( test ) );
	if ( unexpectedNonErrors.length > 0 ) {
		console.error( '\n❌ The following tests were expected to error but did not:' );
		unexpectedNonErrors.forEach( test => console.error( `  - ${test}` ) );
		isSuccess = false;
	}

	// Check if all expected failures actually failed
	const expectedFailuresInScope = filterExpectedResultsInScope( expectedFailures, actualTests );
	const unexpectedPasses = expectedFailuresInScope.filter( test => ! actualFailures.includes( test ) );
	if ( unexpectedPasses.length > 0 ) {
		console.error( '\n❌ The following tests were expected to fail but passed:' );
		unexpectedPasses.forEach( test => console.error( `  - ${test}` ) );
		isSuccess = false;
	}

	if ( ignoreMissingExpectedResults ) {
		const ignoredExpectedResults = expectedErrors.concat( expectedFailures ).filter( test => ! actualTests.includes( test ) );
		if ( ignoredExpectedResults.length > 0 ) {
			console.log( `\nℹ️ Ignored ${ ignoredExpectedResults.length } expected errors/failures outside the selected PHPUnit tests.` );
		}
	}

	// Check for unexpected errors
	const unexpectedErrors = actualErrors.filter( test => ! expectedErrors.includes( test ) );
	if ( unexpectedErrors.length > 0 ) {
		console.error( '\n❌ The following tests errored unexpectedly:' );
		unexpectedErrors.forEach( test => console.error( `  - ${test}` ) );
		isSuccess = false;
	}

	// Check for unexpected failures
	const unexpectedFailures = actualFailures.filter( test => ! expectedFailures.includes( test ) );
	if ( unexpectedFailures.length > 0 ) {
		console.error( '\n❌ The following tests failed unexpectedly:' );
		unexpectedFailures.forEach( test => console.error( `  - ${test}` ) );
		isSuccess = false;
	}

	if ( isSuccess ) {
		console.log( '\n✅ All tests behaved as expected!' );
		process.exit( 0 );
	} else {
		console.log( '\n❌ Some tests did not behave as expected!' );
		process.exit( 1 );
	}
} catch ( error ) {
	console.error( '\n❌ Script execution error:', error.message );
	process.exit( 1 );
}

function filterExpectedResultsInScope( expectedResults, actualTests ) {
	if ( ! ignoreMissingExpectedResults ) {
		return expectedResults;
	}

	return expectedResults.filter( test => actualTests.includes( test ) );
}
