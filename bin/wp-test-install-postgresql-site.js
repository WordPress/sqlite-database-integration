#!/usr/bin/env node

const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );

const root = path.resolve( __dirname, '..' );
const backend = normalizeBackend( process.env.WP_TEST_DB_BACKEND || 'sqlite' );

if ( 'postgresql' !== backend ) {
	process.exit( 0 );
}

const wordpressDir = path.join( root, 'wordpress' );
if ( ! fs.existsSync( path.join( wordpressDir, 'package.json' ) ) ) {
	throw new Error( 'Generated WordPress checkout is missing. Run composer run wp-setup first.' );
}

if ( isWordPressInstalled() ) {
	console.log( 'PostgreSQL WordPress site is already installed.' );
	process.exit( 0 );
}

console.log( 'Installing PostgreSQL WordPress site for e2e tests...' );
runEnvCli( [
	'core',
	'install',
	'--path=/var/www/src',
	`--url=${ getBaseUrl() }`,
	'--title=WordPress',
	'--admin_user=admin',
	'--admin_password=password',
	'--admin_email=test@test.com',
	'--skip-email',
] );

function isWordPressInstalled() {
	try {
		runEnvCli( [ 'core', 'is-installed', '--path=/var/www/src' ], 'ignore' );
		return true;
	} catch ( error ) {
		return false;
	}
}

function runEnvCli( args, stdio = 'inherit' ) {
	execFileSync(
		'npm',
		[
			'--prefix',
			'wordpress',
			'run',
			'env:cli',
			'--',
			...args,
		],
		{
			cwd: root,
			env: getDockerEnv(),
			stdio,
		}
	);
}

function getDockerEnv() {
	return {
		...process.env,
		LOCAL_DB_TYPE: process.env.LOCAL_DB_TYPE || 'mysql',
		LOCAL_PHP_MEMCACHED: process.env.LOCAL_PHP_MEMCACHED || 'false',
		COMPOSE_IGNORE_ORPHANS: 'true',
	};
}

function getBaseUrl() {
	const env = readWordPressDotenv();
	const port = process.env.LOCAL_PORT || env.LOCAL_PORT || '8889';
	return process.env.WP_BASE_URL || expandEnvValue( env.WP_BASE_URL || 'http://localhost:${LOCAL_PORT}', { ...env, LOCAL_PORT: port } );
}

function readWordPressDotenv() {
	const file = path.join( wordpressDir, '.env' );
	if ( ! fs.existsSync( file ) ) {
		return {};
	}

	const values = {};
	for ( const line of fs.readFileSync( file, 'utf8' ).split( /\r?\n/ ) ) {
		const match = line.match( /^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/ );
		if ( match ) {
			values[ match[1] ] = match[2];
		}
	}

	return values;
}

function expandEnvValue( value, values ) {
	return String( value ).replace( /\$\{([A-Za-z_][A-Za-z0-9_]*)\}/g, ( match, name ) => values[ name ] || process.env[ name ] || '' );
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
