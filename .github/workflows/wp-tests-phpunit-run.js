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
	'Tests_DB_Charset::test_strip_invalid_text_for_column_bails_if_ascii_input_too_long',
	'Tests_DB_dbDelta::test_spatial_indices',
	'Tests_DB::test_charset_switched_to_utf8mb4',
	'Tests_DB::test_close',
	'Tests_DB::test_delete_value_too_long_for_field with data set &quot;too long&quot;',
	'Tests_DB::test_has_cap',
	'Tests_DB::test_insert_value_too_long_for_field with data set &quot;too long&quot;',
	'Tests_DB::test_non_unicode_collations',
	'Tests_DB::test_pre_get_col_charset_filter',
	'Tests_DB::test_process_fields_on_nonexistent_table',
	'Tests_DB::test_process_fields_value_too_long_for_field with data set &quot;too long&quot;',
	'Tests_DB::test_query_value_contains_invalid_chars',
	'Tests_DB::test_replace_value_too_long_for_field with data set &quot;too long&quot;',
	'Tests_DB::test_supports_collation',
	'Tests_DB::test_update_value_too_long_for_field with data set &quot;too long&quot;',
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
	if ( enableDuckDBChildDiagnostics ) {
		writeDuckDBChildDiagnosticsFile();
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
$duckdb_autoload = ${ phpSingleQuote( process.env.DUCKDB_PHP_AUTOLOAD ) };

if ( ! is_readable( $duckdb_autoload ) ) {
\tfwrite( STDERR, "Error: DuckDB PHP autoload file is not readable: {$duckdb_autoload}\\n" );
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

\tfunction wp_sqlite_duckdb_child_diagnostics_report( $stage, $force = false ) {
\t\tif ( ! wp_sqlite_duckdb_child_diagnostics_enabled() ) {
\t\t\treturn;
\t\t}

\t\t$error     = error_get_last();
\t\t$is_fatal  = wp_sqlite_duckdb_child_diagnostics_fatal_error( $error );
\t\t$db_engine = defined( 'DB_ENGINE' ) ? DB_ENGINE : null;
\t\t$autoload  = defined( 'DUCKDB_PHP_AUTOLOAD' ) ? DUCKDB_PHP_AUTOLOAD : null;
\t\t$wpdb      = isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ? get_class( $GLOBALS['wpdb'] ) : null;
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
\t\t\t'db_engine_defined'        => defined( 'DB_ENGINE' ),
\t\t\t'db_engine'                => $db_engine,
\t\t\t'duckdb_autoload_defined'  => defined( 'DUCKDB_PHP_AUTOLOAD' ),
\t\t\t'duckdb_autoload'          => $autoload,
\t\t\t'duckdb_autoload_readable' => is_string( $autoload ) && is_readable( $autoload ),
\t\t\t'ffi_loaded'               => extension_loaded( 'ffi' ),
\t\t\t'ffi_enable'               => ini_get( 'ffi.enable' ),
\t\t\t'runtime_class_loaded'     => class_exists( 'WP_DuckDB_Runtime', false ),
\t\t\t'runtime_unavailable'      => $reason,
\t\t\t'duckdb_class_loaded'      => class_exists( 'Saturio\\\\DuckDB\\\\DuckDB', false ),
\t\t\t'wpdb_class'               => $wpdb,
\t\t\t'bootstrap_global'         => $bootstrap_global,
\t\t\t'bootstrap_global_readable' => is_string( $bootstrap_global ) && is_readable( $bootstrap_global ),
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
	const maxLines = 500;
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
	const guard = [
		'/*',
		' * DuckDB child-process diagnostics before WordPress bootstrap.',
		' * This block is generated by the SQLite integration workflow.',
		' */',
		"$wp_sqlite_duckdb_child_diagnostics = dirname( __DIR__, 3 ) . '/duckdb-child-process-diagnostics.php';",
		"if ( is_readable( $wp_sqlite_duckdb_child_diagnostics ) ) {",
		"\trequire_once $wp_sqlite_duckdb_child_diagnostics;",
		"\twp_sqlite_duckdb_child_diagnostics_report( 'bootstrap_pre_wp_settings', true );",
		'}',
		'',
		marker,
		'',
		"if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
		"\twp_sqlite_duckdb_child_diagnostics_report( 'wp_bootstrap', true );",
		'}',
	].join( '\n' );

	if ( ! fs.existsSync( file ) ) {
		console.error( `Error: WordPress PHPUnit bootstrap file not found at ${ file }.` );
		process.exit( 1 );
	}

	let contents = fs.readFileSync( file, 'utf8' );
	if ( contents.includes( guard ) ) {
		return;
	}

	if ( ! contents.includes( marker ) ) {
		console.error( `Error: Unable to find WordPress bootstrap marker in ${ file }.` );
		process.exit( 1 );
	}

	contents = contents.replace( marker, guard );
	fs.writeFileSync( file, contents );
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
		getPhpunitForwardedEnvironmentPhp(),
		"if ( defined( 'DUCKDB_PHP_AUTOLOAD' ) && is_readable( DUCKDB_PHP_AUTOLOAD ) ) {",
		"\trequire_once DUCKDB_PHP_AUTOLOAD;",
		'}',
		`if ( empty( $GLOBALS['__PHPUNIT_BOOTSTRAP'] ) && is_readable( ${ phpSingleQuote( wordPressPhpunitBootstrapContainerPath ) } ) ) {`,
		`\t$GLOBALS['__PHPUNIT_BOOTSTRAP'] = ${ phpSingleQuote( wordPressPhpunitBootstrapContainerPath ) };`,
		'}',
		"$GLOBALS['wp_sqlite_duckdb_child_result_file'] = '{processResultFile}';",
		"putenv( 'WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS=1' );",
		"putenv( 'WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS_VERBOSE=1' );",
		"putenv( 'WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS_STDERR=0' );",
		`if ( is_readable( ${ phpSingleQuote( duckdbChildDiagnosticsContainerPath ) } ) ) {`,
		`\trequire_once ${ phpSingleQuote( duckdbChildDiagnosticsContainerPath ) };`,
		"\twp_sqlite_duckdb_child_diagnostics_report( 'phpunit_child_template_start', true );",
		'}',
		'',
	].join( '\n' );
	const lifecycleReplacements = [
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
			'    file_put_contents(',
			[
				"    if ( function_exists( 'wp_sqlite_duckdb_child_diagnostics_report' ) ) {",
				"        wp_sqlite_duckdb_child_diagnostics_report( 'before_process_result_write', true );",
				'    }',
				'    file_put_contents(',
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

function getPhpunitForwardedEnvironmentPhp() {
	const environmentNames = [
		'WP_DUCKDB_QUERY_PROFILE',
		'WP_DUCKDB_QUERY_PROFILE_INTERVAL',
		'WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS',
		'WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS_VERBOSE',
		'WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS_STDERR',
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

function verifyPhpunitCompatibilityFiles() {
	const check = [
		`require ${ phpSingleQuote( phpunitCompatibilityPrependContainerPath ) };`,
		'require_once DUCKDB_PHP_AUTOLOAD;',
		"exit( method_exists( 'PHPUnit\\\\TextUI\\\\TestRunner', 'run' ) ? 0 : 1 );",
	].join( ' ' );

	runWordPressDockerCompose( [ 'run', '--rm', 'php', 'php', '-r', check ], { stdio: 'inherit' } );
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
