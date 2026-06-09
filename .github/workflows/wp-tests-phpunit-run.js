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

const repositoryRoot = path.join( __dirname, '..', '..' );
const backend = normalizeBackend( process.env.WP_TEST_DB_BACKEND || 'sqlite' );
const requiresNativeParserExtension = process.env.WP_SQLITE_REQUIRE_NATIVE_PARSER_EXTENSION === '1';

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

	try {
		execSync(
			'composer run wp-test-php -- --log-junit=phpunit-results.xml --verbose',
			{ stdio: 'inherit' }
		);
		console.log( '\nAll tests passed, checking if expected errors/failures occurred...' );
	} catch ( error ) {
		console.log( '\nSome tests errored/failed. Analyzing results...' );
	}

	const junitOutputFile = path.join( repositoryRoot, 'wordpress', 'phpunit-results.xml' );
	if ( ! fs.existsSync( junitOutputFile ) ) {
		console.error( 'Error: JUnit output file not found.' );
		writeResultSummary( emptySummary() );
		process.exit( 1 );
	}

	const testcases = readJunitTestcases( junitOutputFile );
	const summary = summarizeTestcases( testcases );
	writeResultSummary( summary );

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
	const runArgs = 'cli' === service ? '--rm --entrypoint php cli' : '--rm php php';
	const containerPath = `/var/www/${ path.basename( verifier ) }`;
	execSync(
		`cd wordpress && node tools/local-env/scripts/docker.js run ${ runArgs } ${ containerPath }`,
		{ stdio: 'inherit' }
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
	execSync(
		'cd wordpress && if [ -z "$(node tools/local-env/scripts/docker.js ps -q)" ]; then npm run env:start && npm run env:install; fi',
		{
			env: {
				...process.env,
				COMPOSE_IGNORE_ORPHANS: 'true',
			},
			stdio: 'inherit',
		}
	);
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
		assertFileDoesNotContain(
			installScript,
			'install_wp_importer();',
			'install.js does not call WP-CLI plugin installation for PostgreSQL'
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

function readJunitTestcases( junitOutputFile ) {
	const parserPath = require.resolve( 'fast-xml-parser', {
		paths: [
			path.join( repositoryRoot, 'wordpress', 'node_modules' ),
			repositoryRoot,
		],
	} );
	const { XMLParser } = require( parserPath );
	const parser = new XMLParser( {
		attributeNamePrefix: '',
		ignoreAttributes: false,
		isArray: name => [
			'testsuite',
			'testcase',
			'error',
			'failure',
			'skipped',
			'incomplete',
			'risky',
			'warning',
		].includes( name ),
	} );
	const junitXml = fs.readFileSync( junitOutputFile, 'utf8' );
	const parsed = parser.parse( junitXml );
	const testcases = [];
	collectTestcases( parsed, testcases, false );
	return testcases.map( normalizeTestcase );
}

function collectTestcases( node, testcases, isTestcase ) {
	if ( Array.isArray( node ) ) {
		node.forEach( child => collectTestcases( child, testcases, isTestcase ) );
		return;
	}

	if ( ! node || typeof node !== 'object' ) {
		return;
	}

	if ( isTestcase ) {
		testcases.push( node );
		return;
	}

	if ( node.testcase ) {
		collectTestcases( node.testcase, testcases, true );
	}

	if ( node.testsuite ) {
		collectTestcases( node.testsuite, testcases, false );
	}

	if ( node.testsuites ) {
		collectTestcases( node.testsuites, testcases, false );
	}
}

function normalizeTestcase( testcase ) {
	const className = testcase.class || '';
	const testName = testcase.name || '';
	const fullName = className ? `${ className }::${ testName }` : testName;

	return {
		name: fullName,
		hasError: hasChild( testcase, 'error' ),
		hasFailure: hasChild( testcase, 'failure' ),
		hasSkipped: hasChild( testcase, 'skipped' ),
		hasIncomplete: hasChild( testcase, 'incomplete' ),
		hasRisky: hasChild( testcase, 'risky' ),
		hasWarning: hasChild( testcase, 'warning' ),
	};
}

function hasChild( testcase, childName ) {
	return Array.isArray( testcase[ childName ] ) && testcase[ childName ].length > 0;
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
	const outputPath = path.join( repositoryRoot, `wp-phpunit-results-${ backend }.json` );
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
