/*
 * Wrap the "composer run wp-tests-php" command to process tests
 * that are expected to error and fail at the moment.
 *
 * Unexpected errors/failures still fail the workflow. Expected failures that
 * stop happening are reported so this allowlist can be reduced over time.
 */
const { execFileSync, execSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );

const repositoryRoot = path.join( __dirname, '..', '..' );
const backend = normalizeBackend( process.env.WP_TEST_DB_BACKEND || 'sqlite' );
const requiresNativeParserExtension = process.env.WP_SQLITE_REQUIRE_NATIVE_PARSER_EXTENSION === '1';
const phpunitArgs = getPhpUnitArgs();

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
	'Tests_DB::test_delete_value_too_long_for_field with data set "too long"',
	'Tests_DB::test_has_cap',
	'Tests_DB::test_insert_value_too_long_for_field with data set "too long"',
	'Tests_DB::test_mysqli_flush_sync',
	'Tests_DB::test_non_unicode_collations',
	'Tests_DB::test_pre_get_col_charset_filter',
	'Tests_DB::test_process_fields_on_nonexistent_table',
	'Tests_DB::test_process_fields_value_too_long_for_field with data set "too long"',
	'Tests_DB::test_query_value_contains_invalid_chars',
	'Tests_DB::test_replace_value_too_long_for_field with data set "too long"',
	'Tests_DB::test_replace',
	'Tests_DB::test_supports_collation',
	'Tests_DB::test_update_value_too_long_for_field with data set "too long"',
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

const expectedByBackend = {
	mysql: {
		errors: [],
		failures: [],
	},
	sqlite: {
		errors: sqliteExpectedErrors,
		failures: sqliteExpectedFailures,
	},
	postgresql: {
		errors: [],
		failures: [],
	},
};

console.log( `Running WordPress PHPUnit tests with ${ backend } expected-result tracking...` );
if ( requiresNativeParserExtension ) {
	console.log( 'Native parser extension is required for this PHPUnit run.' );
}
if ( phpunitArgs.length > 0 ) {
	console.log( 'PHPUnit arguments:', phpunitArgs );
}
console.log( 'Expected errors:', expectedByBackend[ backend ].errors );
console.log( 'Expected failures:', expectedByBackend[ backend ].failures );

try {
	ensureGeneratedBackendFiles();
	ensureWordPressTestEnvironment();
	validateGeneratedBackendFiles();

	if ( requiresNativeParserExtension ) {
		verifyNativeParserExtension();
	}
	if ( 'postgresql' === backend ) {
		verifyPostgreSqlPhpExtension();
	}

	const junitOutputFile = path.join( repositoryRoot, 'wordpress', 'phpunit-results.xml' );
	removeStaleTestOutput( junitOutputFile );
	removeStaleTestOutput( getResultSummaryFile() );

	let phpunitCommandError = null;
	try {
		runPhpUnit();
		console.log( '\nAll tests passed, checking if expected errors/failures occurred...' );
	} catch ( error ) {
		phpunitCommandError = error;
		console.log( '\nSome tests errored/failed. Analyzing results...' );
	}

	if ( ! fs.existsSync( junitOutputFile ) ) {
		console.error( 'Error: JUnit output file not found.' );
		writeResultSummary( emptySummary() );
		process.exit( 1 );
	}
	if ( 0 === fs.statSync( junitOutputFile ).size ) {
		console.error( 'Error: JUnit output file is empty.' );
		writeResultSummary( emptySummary() );
		process.exit( 1 );
	}

	const testcases = readJunitTestcases( junitOutputFile );
	const summary = summarizeTestcases( testcases );
	writeResultSummary( summary );
	if ( 0 === summary.total ) {
		const failureContext = phpunitCommandError ? ' after the PHPUnit command failed' : '';
		console.error( `Error: JUnit output did not contain any test cases${ failureContext }.` );
		process.exit( 1 );
	}

	const actualErrors = testcases.filter( testcase => testcase.hasError ).map( testcase => testcase.name );
	const actualFailures = testcases.filter( testcase => testcase.hasFailure ).map( testcase => testcase.name );

	let isSuccess = true;
	const expectedErrors = expectedByBackend[ backend ].errors;
	const expectedFailures = expectedByBackend[ backend ].failures;

	const unexpectedNonErrors = expectedErrors.filter( test => ! actualErrors.includes( test ) );
	if ( unexpectedNonErrors.length > 0 ) {
		console.error( '\nThe following tests were expected to error but did not:' );
		unexpectedNonErrors.forEach( test => console.error( `  - ${ test }` ) );
		isSuccess = false;
	}

	const unexpectedPasses = expectedFailures.filter( test => ! actualFailures.includes( test ) );
	if ( unexpectedPasses.length > 0 ) {
		console.error( '\nThe following tests were expected to fail but passed:' );
		unexpectedPasses.forEach( test => console.error( `  - ${ test }` ) );
		isSuccess = false;
	}

	const unexpectedErrors = actualErrors.filter( test => ! expectedErrors.includes( test ) );
	if ( unexpectedErrors.length > 0 ) {
		console.error( '\nThe following tests errored unexpectedly:' );
		unexpectedErrors.forEach( test => console.error( `  - ${ test }` ) );
		isSuccess = false;
	}

	const unexpectedFailures = actualFailures.filter( test => ! expectedFailures.includes( test ) );
	if ( unexpectedFailures.length > 0 ) {
		console.error( '\nThe following tests failed unexpectedly:' );
		unexpectedFailures.forEach( test => console.error( `  - ${ test }` ) );
		isSuccess = false;
	}

	if ( isSuccess ) {
		console.log( '\nAll tests behaved as expected.' );
		process.exit( 0 );
	}

	console.log( '\nSome tests did not behave as expected.' );
	process.exit( 1 );
} catch ( error ) {
	console.error( '\nScript execution error:', error.message );
	writeResultSummary( emptySummary() );
	process.exit( 1 );
}

function normalizeBackend( value ) {
	const normalized = String( value ).toLowerCase();

	if ( [ 'postgres', 'pgsql', 'postgresql' ].includes( normalized ) ) {
		return 'postgresql';
	}

	if ( [ 'mysql', 'sqlite' ].includes( normalized ) ) {
		return normalized;
	}

	throw new Error( `Unsupported WP_TEST_DB_BACKEND: ${ value }` );
}

function getPhpUnitArgs() {
	const args = [];

	if ( process.env.WP_TEST_PHPUNIT_FILTER ) {
		args.push( '--filter', process.env.WP_TEST_PHPUNIT_FILTER );
	}

	return args;
}

function verifyNativeParserExtension() {
	const verifier = path.join( repositoryRoot, 'wordpress', 'native-verify-extension.php' );
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

function verifyPostgreSqlPhpExtension() {
	const verifier = writePostgreSqlPhpExtensionVerifier();
	verifyContainerPhpExtension( 'php', verifier );
	verifyContainerPhpExtension( 'cli', verifier );
}

function writePostgreSqlPhpExtensionVerifier() {
	const verifier = path.join( repositoryRoot, 'wordpress', 'postgresql-verify-extension.php' );
	fs.writeFileSync(
		verifier,
		`<?php
$extension = 'pdo_pgsql';
if ( ! extension_loaded( $extension ) ) {
\tfwrite( STDERR, $extension . " is missing in this container.\\n" );
\texit( 1 );
}
echo $extension . " is loaded.\\n";
`
	);
	return verifier;
}

function verifyContainerPhpExtension( service, verifier ) {
	const runArgs = 'cli' === service ? [ '--rm', '--entrypoint', 'php', 'cli' ] : [ '--rm', 'php', 'php' ];
	const containerPath = `/var/www/${ path.basename( verifier ) }`;
	runWordPressDockerCompose( [ 'run', ...runArgs, containerPath ] );
}

function runPhpUnit() {
	const args = [
		'--log-junit=phpunit-results.xml',
		'--verbose',
		...phpunitArgs,
	];

	if ( 'postgresql' === backend ) {
		removeWordPressSqliteHtAccessFile();
		execFileSync(
			'docker',
			[
				'compose',
				...getWordPressDockerComposeArgs(),
				'run',
				'--rm',
				'php',
				'./vendor/bin/phpunit',
				...args,
			],
			{
				cwd: path.join( repositoryRoot, 'wordpress' ),
				env: getWordPressDockerComposeEnv(),
				stdio: 'inherit',
			}
		);
		return;
	}

	execFileSync(
		'composer',
		[
			'run',
			'wp-test-php',
			'--',
			...args,
		],
		{ stdio: 'inherit' }
	);
}

function getWordPressDockerComposeArgs() {
	const args = [ '-f', 'docker-compose.yml' ];
	if ( fs.existsSync( path.join( repositoryRoot, 'wordpress', 'docker-compose.override.yml' ) ) ) {
		args.push( '-f', 'docker-compose.override.yml' );
	}

	return args;
}

function removeWordPressSqliteHtAccessFile() {
	fs.rmSync(
		path.join( repositoryRoot, 'wordpress', 'src', 'wp-content', 'database', '.ht.sqlite' ),
		{
			force: true,
		}
	);
}

function ensureWordPressTestEnvironment() {
	if ( 'postgresql' === backend ) {
		ensurePostgreSqlWordPressTestEnvironment();
		return;
	}

	execSync( 'composer run wp-test-ensure-env', { stdio: 'inherit' } );
}

function ensurePostgreSqlWordPressTestEnvironment() {
	runWordPressDockerCompose( [ 'up', '-d', 'wordpress-develop' ] );
	runWordPressDockerCompose( [ 'run', '-T', 'php', 'composer', 'update', '-W' ] );
	writePostgreSqlWpConfig();
	writePostgreSqlWpTestsConfig();
	installPostgreSqlWpImporter();
}

function runWordPressDockerCompose( args ) {
	execFileSync(
		'docker',
		[
			'compose',
			...getWordPressDockerComposeArgs(),
			...args,
		],
		{
			cwd: path.join( repositoryRoot, 'wordpress' ),
			env: getWordPressDockerComposeEnv(),
			stdio: 'inherit',
		}
	);
}

function getWordPressDockerComposeEnv() {
	return {
		...process.env,
		LOCAL_DB_TYPE: process.env.LOCAL_DB_TYPE || 'mysql',
		LOCAL_PHP_MEMCACHED: process.env.LOCAL_PHP_MEMCACHED || 'false',
		COMPOSE_IGNORE_ORPHANS: 'true',
	};
}

function writePostgreSqlWpConfig() {
	const wordpressRoot = path.join( repositoryRoot, 'wordpress' );
	let config = fs.readFileSync( path.join( wordpressRoot, 'wp-config-sample.php' ), 'utf8' );
	config = config
		.replace( "define( 'DB_NAME', 'database_name_here' );", "define( 'DB_NAME', 'wordpress_develop' );" )
		.replace( "define( 'DB_USER', 'username_here' );", "define( 'DB_USER', 'root' );" )
		.replace( "define( 'DB_PASSWORD', 'password_here' );", "define( 'DB_PASSWORD', 'password' );" )
		.replace( "define( 'DB_HOST', 'localhost' );", "define( 'DB_HOST', 'postgres' );" )
		.replace(
			"define( 'WP_DEBUG', false );",
			"define( 'WP_DEBUG', " + getPostgreSqlRawConstantValue( 'LOCAL_WP_DEBUG', 'true' ) + " );"
		)
		.replace(
			'/* Add any custom values between this line and the "stop editing" line. */',
			[
				'/* Add any custom values between this line and the "stop editing" line. */',
				'',
				"define( 'DB_ENGINE', 'postgresql' );",
				"define( 'DATABASE_ENGINE', 'postgresql' );",
				"define( 'WP_DEBUG_LOG', " + getPostgreSqlRawConstantValue( 'LOCAL_WP_DEBUG_LOG', 'true' ) + " );",
				"define( 'WP_DEBUG_DISPLAY', " + getPostgreSqlRawConstantValue( 'LOCAL_WP_DEBUG_DISPLAY', 'true' ) + " );",
				"define( 'SCRIPT_DEBUG', " + getPostgreSqlRawConstantValue( 'LOCAL_SCRIPT_DEBUG', 'true' ) + " );",
				"define( 'WP_ENVIRONMENT_TYPE', " + quotePostgreSqlPhpString( getPostgreSqlEnvValue( 'LOCAL_WP_ENVIRONMENT_TYPE', 'local' ) ) + " );",
				"define( 'WP_DEVELOPMENT_MODE', " + quotePostgreSqlPhpString( getPostgreSqlEnvValue( 'LOCAL_WP_DEVELOPMENT_MODE', 'core' ) ) + " );",
			].join( '\n' )
		);

	fs.rmSync( path.join( wordpressRoot, 'src', 'wp-config.php' ), { force: true } );
	fs.writeFileSync( path.join( wordpressRoot, 'wp-config.php' ), config );
}

function writePostgreSqlWpTestsConfig() {
	const wordpressRoot = path.join( repositoryRoot, 'wordpress' );
	const testConfig = fs.readFileSync( path.join( wordpressRoot, 'wp-tests-config-sample.php' ), 'utf8' )
		.replace( 'youremptytestdbnamehere', 'wordpress_develop_tests' )
		.replace( 'yourusernamehere', 'root' )
		.replace( 'yourpasswordhere', 'password' )
		.replace( 'localhost', 'postgres' )
		.replace(
			"'WP_TESTS_DOMAIN', 'example.org'",
			"'WP_TESTS_DOMAIN', " + quotePostgreSqlPhpString( getPostgreSqlEnvValue( 'LOCAL_WP_TESTS_DOMAIN', 'example.org' ) )
		)
		.concat( "\ndefine( 'DB_ENGINE', 'postgresql' );\n" )
		.concat( "define( 'DATABASE_ENGINE', 'postgresql' );\n" )
		.concat( "define( 'FS_METHOD', 'direct' );\n" );

	fs.writeFileSync( path.join( wordpressRoot, 'wp-tests-config.php' ), testConfig );
}

function installPostgreSqlWpImporter() {
	const wordpressRoot = path.join( repositoryRoot, 'wordpress' );
	const testPluginDirectory = path.join( 'tests', 'phpunit', 'data', 'plugins', 'wordpress-importer' );
	if ( fs.existsSync( path.join( wordpressRoot, testPluginDirectory, 'wordpress-importer.php' ) ) ) {
		return;
	}

	fs.rmSync( path.join( wordpressRoot, testPluginDirectory ), { recursive: true, force: true } );
	execFileSync(
		'git',
		[
			'clone',
			'https://github.com/WordPress/wordpress-importer.git',
			testPluginDirectory,
			'--depth=1',
		],
		{
			cwd: wordpressRoot,
			stdio: 'inherit',
		}
	);
}

function getPostgreSqlEnvValue( name, defaultValue ) {
	return process.env[ name ] || defaultValue;
}

function getPostgreSqlRawConstantValue( name, defaultValue ) {
	const value = getPostgreSqlEnvValue( name, defaultValue );
	if ( /^(?:true|false|null|[0-9]+)$/i.test( value ) ) {
		return value.toLowerCase();
	}

	throw new Error( `Unsupported raw constant value for ${ name }: ${ value }` );
}

function quotePostgreSqlPhpString( value ) {
	return "'" + String( value ).replace( /\\/g, '\\\\' ).replace( /'/g, "\\'" ) + "'";
}

function ensureGeneratedBackendFiles() {
	if ( 'mysql' === backend ) {
		return;
	}

	const wpLoad = path.join( repositoryRoot, 'wordpress', 'src', 'wp-load.php' );
	if ( ! fs.existsSync( wpLoad ) ) {
		runWordPressSetup();
		validateGeneratedBackendFiles();
		return;
	}

	try {
		validateGeneratedBackendFiles();
	} catch ( error ) {
		console.error( `Generated WordPress checkout is stale for ${ backend }: ${ error.message }` );
		runWordPressSetup();
		validateGeneratedBackendFiles();
	}
}

function runWordPressSetup() {
	execSync( 'composer run wp-setup', {
		env: {
			...process.env,
			WP_TEST_DB_BACKEND: backend,
			...( 'postgresql' === backend ? { WP_TEST_SKIP_WORDPRESS_NPM: '1' } : {} ),
		},
		stdio: 'inherit',
	} );
}

function validateGeneratedBackendFiles() {
	if ( 'mysql' === backend ) {
		return;
	}

	const generatedDropin = path.join( repositoryRoot, 'wordpress', 'src', 'wp-content', 'db.php' );
	const composeOverride = path.join( repositoryRoot, 'wordpress', 'docker-compose.override.yml' );

	assertFileContains(
		generatedDropin,
		`: '${ backend }'`,
		`generated db.php default backend is ${ backend }`
	);
	assertFileContains(
		generatedDropin,
		"$unreplaced_database_engine = '{' . 'DATABASE_ENGINE' . '}';",
		'generated db.php uses a split database-engine sentinel'
	);
	assertFileContains(
		generatedDropin,
		"/wp-includes/db.php'",
		'generated db.php loads the backend dispatcher'
	);
	assertFileDoesNotContain(
		generatedDropin,
		"require_once $sqlite_plugin_implementation_folder_path . '/wp-includes/sqlite/db.php';",
		'generated db.php does not load the SQLite drop-in directly'
	);
	assertFileContains(
		composeOverride,
		`DB_ENGINE: ${ backend }`,
		`docker-compose.override.yml sets DB_ENGINE=${ backend }`
	);
	assertFileContains(
		composeOverride,
		`DATABASE_ENGINE: ${ backend }`,
		`docker-compose.override.yml sets DATABASE_ENGINE=${ backend }`
	);

	if ( 'postgresql' === backend ) {
		const installScript = path.join( repositoryRoot, 'wordpress', 'tools', 'local-env', 'scripts', 'install.js' );
		const postgresqlPhpDockerfile = path.join( repositoryRoot, 'wordpress', 'tools', 'local-env', 'Dockerfile.postgresql-php' );
		const postgresqlCliDockerfile = path.join( repositoryRoot, 'wordpress', 'tools', 'local-env', 'Dockerfile.postgresql-cli' );
		assertFileContains(
			composeOverride,
			'postgres:',
			'docker-compose.override.yml defines a PostgreSQL service'
		);
		assertFileContains(
			composeOverride,
			'Dockerfile.postgresql-php',
			'docker-compose.override.yml builds a PostgreSQL PHP image'
		);
		assertFileContains(
			composeOverride,
			'Dockerfile.postgresql-cli',
			'docker-compose.override.yml builds a PostgreSQL CLI image'
		);
		assertFileContains(
			composeOverride,
			'mysql: !reset null',
			'docker-compose.override.yml removes inherited MySQL services and dependencies'
		);
		assertFileContainsCount(
			composeOverride,
			'mysql: !reset null',
			4,
			'docker-compose.override.yml resets both inherited MySQL dependencies, the MySQL service, and the MySQL volume'
		);
		assertFileContainsCount(
			composeOverride,
			'    depends_on:\n      mysql: !reset null\n      php:',
			2,
			'docker-compose.override.yml removes inherited MySQL dependencies from WordPress and CLI services'
		);
		assertFileContains(
			composeOverride,
			'\n  mysql: !reset null\n\n  postgres:',
			'docker-compose.override.yml removes the inherited MySQL service before defining PostgreSQL'
		);
		assertFileContains(
			composeOverride,
			'\nvolumes:\n  mysql: !reset null\n  postgres: {}',
			'docker-compose.override.yml removes the inherited MySQL volume'
		);
		for ( const setting of [ 'fsync=off', 'synchronous_commit=off', 'full_page_writes=off' ] ) {
			assertFileContains(
				composeOverride,
				setting,
				`docker-compose.override.yml sets PostgreSQL ${ setting } for test runs`
			);
		}
		assertFileContains(
			postgresqlPhpDockerfile,
			'docker-php-ext-install pdo_pgsql',
			'PostgreSQL PHP Dockerfile installs pdo_pgsql'
		);
		assertFileContains(
			postgresqlCliDockerfile,
			'docker-php-ext-install pdo_pgsql',
			'PostgreSQL CLI Dockerfile installs pdo_pgsql'
		);
		assertFileContains(
			installScript,
			"const fs = require( 'fs' );",
			'install.js imports the fs object for direct PostgreSQL setup'
		);
		assertFileContains(
			installScript,
			"const { existsSync, renameSync, readFileSync, writeFileSync } = fs;",
			'install.js imports guarded wp-config file helpers from fs'
		);
		assertFileContains(
			installScript,
			'install_postgresql_test_environment();',
			'install.js runs the direct PostgreSQL test-environment setup path'
		);
		assertFileContains(
			installScript,
			'write_postgresql_wp_config();',
			'install.js writes wp-config.php without WP-CLI for PostgreSQL'
		);
		assertFileContains(
			installScript,
			'write_postgresql_wp_tests_config();',
			'install.js writes wp-tests-config.php without WP-CLI for PostgreSQL'
		);
		assertFileContains(
			installScript,
			'--dbhost=postgres',
			'install.js creates wp-config.php with the PostgreSQL host'
		);
		assertFileContains(
			installScript,
			'--skip-check',
			'install.js skips MySQL-style connection checks while creating PostgreSQL wp-config.php'
		);
		assertFileContains(
			installScript,
			"if ( existsSync( 'src/wp-config.php' ) ) {",
			'install.js guards moving generated src/wp-config.php'
		);
		assertFileContains(
			installScript,
			"if ( ! existsSync( 'wp-config.php' ) ) {",
			'install.js checks that wp-config.php was generated'
		);
		assertFileContains(
			installScript,
			'wp-config.php was not generated.',
			'install.js reports a missing generated wp-config.php'
		);
		assertFileContains(
			installScript,
			"config set DB_ENGINE postgresql",
			'install.js writes DB_ENGINE=postgresql'
		);
		assertFileContains(
			installScript,
			"define( 'DATABASE_ENGINE', 'postgresql' );",
			'install.js writes DATABASE_ENGINE=postgresql to wp-tests-config.php'
		);
		assertFileDoesNotContain(
			installScript,
			"wp_cli( 'db reset --yes' );",
			'install.js does not call the MySQL-backed db reset command for PostgreSQL'
		);
		assertFileContains(
			installScript,
			'install_wp_importer();',
			'install.js installs the WordPress Importer test plugin for PostgreSQL'
		);
		assertFileContains(
			installScript,
			'run --rm --workdir /var/www php git clone https://github.com/WordPress/wordpress-importer.git',
			'install.js runs the WordPress Importer clone from a valid PostgreSQL container workdir'
		);
		assertFileContains(
			installScript,
			'git clone https://github.com/WordPress/wordpress-importer.git \' + testPluginDirectory + \' --depth=1',
			'install.js clones the WordPress Importer directly for the fast PostgreSQL setup path'
		);
		assertFileDoesNotContain(
			installScript,
			`core \${ installCommand }`,
			'install.js does not call the MySQL-backed core install command for PostgreSQL'
		);
	}
}

function assertFileContains( file, expected, description ) {
	const contents = readGeneratedFile( file );
	if ( ! contents.includes( expected ) ) {
		throw new Error( `Expected ${ description } in ${ file }.` );
	}
}

function assertFileContainsCount( file, expected, count, description ) {
	const contents = readGeneratedFile( file );
	const actual = contents.split( expected ).length - 1;
	if ( actual !== count ) {
		throw new Error( `Expected ${ description } in ${ file }; found ${ actual }, expected ${ count }.` );
	}
}

function assertFileDoesNotContain( file, unexpected, description ) {
	const contents = readGeneratedFile( file );
	if ( contents.includes( unexpected ) ) {
		throw new Error( `Expected ${ description } in ${ file }.` );
	}
}

function readGeneratedFile( file ) {
	if ( ! fs.existsSync( file ) ) {
		throw new Error( `Expected generated file to exist: ${ file }.` );
	}

	return fs.readFileSync( file, 'utf8' );
}

function removeStaleTestOutput( file ) {
	if ( fs.existsSync( file ) ) {
		fs.unlinkSync( file );
	}
}

function readJunitTestcases( junitOutputFile ) {
	const junitXml = fs.readFileSync( junitOutputFile, 'utf8' );
	const testcases = [];
	const testcasePattern = /<testcase\b([^>]*)\/>|<testcase\b([^>]*)>([\s\S]*?)<\/testcase>/g;
	let match;

	while ( ( match = testcasePattern.exec( junitXml ) ) !== null ) {
		const attributes = parseXmlAttributes( match[1] || match[2] || '' );
		const body = match[3] || '';
		const className = attributes.class || '';
		const testName = attributes.name || '';
		const fullName = className ? `${ className }::${ testName }` : testName;

		testcases.push( {
			name: fullName,
			hasError: hasJunitChild( body, 'error' ),
			hasFailure: hasJunitChild( body, 'failure' ),
			hasSkipped: hasJunitChild( body, 'skipped' ),
			hasIncomplete: hasJunitChild( body, 'incomplete' ),
			hasRisky: hasJunitChild( body, 'risky' ),
			hasWarning: hasJunitChild( body, 'warning' ),
		} );
	}

	return testcases;
}

function parseXmlAttributes( attributesXml ) {
	const attributes = {};
	const attributePattern = /([A-Za-z_:][A-Za-z0-9_.:-]*)="([^"]*)"/g;
	let match;

	while ( ( match = attributePattern.exec( attributesXml ) ) !== null ) {
		attributes[ match[1] ] = decodeXmlEntities( match[2] );
	}

	return attributes;
}

function hasJunitChild( body, childName ) {
	return new RegExp( `<${ childName }(?:[\\s>/])` ).test( body );
}

function decodeXmlEntities( value ) {
	return String( value ).replace( /&(#x[0-9a-f]+|#[0-9]+|amp|lt|gt|quot|apos);/gi, entity => {
		const normalized = entity.slice( 1, -1 ).toLowerCase();
		if ( normalized.startsWith( '#x' ) ) {
			return String.fromCodePoint( parseInt( normalized.slice( 2 ), 16 ) );
		}
		if ( normalized.startsWith( '#' ) ) {
			return String.fromCodePoint( parseInt( normalized.slice( 1 ), 10 ) );
		}

		return {
			amp: '&',
			lt: '<',
			gt: '>',
			quot: '"',
			apos: "'",
		}[ normalized ];
	} );
}

function summarizeTestcases( testcases ) {
	const summary = emptySummary();

	for ( const testcase of testcases ) {
		summary.total += 1;

		if ( testcase.hasError ) {
			summary.errors += 1;
		}
		if ( testcase.hasFailure ) {
			summary.failures += 1;
		}
		if ( testcase.hasSkipped ) {
			summary.skipped += 1;
		}
		if ( testcase.hasIncomplete ) {
			summary.incomplete += 1;
		}
		if ( testcase.hasRisky ) {
			summary.risky += 1;
		}
		if ( testcase.hasWarning ) {
			summary.warnings += 1;
		}
		if (
			! testcase.hasError
			&& ! testcase.hasFailure
			&& ! testcase.hasSkipped
			&& ! testcase.hasIncomplete
			&& ! testcase.hasRisky
			&& ! testcase.hasWarning
		) {
			summary.passed += 1;
		}
	}

	return summary;
}

function emptySummary() {
	return {
		backend,
		filter: process.env.WP_TEST_PHPUNIT_FILTER || '',
		total: 0,
		passed: 0,
		errors: 0,
		failures: 0,
		skipped: 0,
		incomplete: 0,
		risky: 0,
		warnings: 0,
	};
}

function writeResultSummary( summary ) {
	const outputPath = getResultSummaryFile();
	fs.writeFileSync( outputPath, `${ JSON.stringify( summary, null, 2 ) }\n` );

	if ( process.env.GITHUB_OUTPUT ) {
		const output = [
			`backend=${ summary.backend }`,
			`total=${ summary.total }`,
			`passed=${ summary.passed }`,
			`errors=${ summary.errors }`,
			`failures=${ summary.failures }`,
		].join( '\n' );
		fs.appendFileSync( process.env.GITHUB_OUTPUT, `${ output }\n` );
	}
}

function getResultSummaryFile() {
	return path.join( repositoryRoot, `wp-phpunit-results-${ backend }.json` );
}
