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

if ( shouldInstallRequestDiagnostics() ) {
	installRequestDiagnostics();
} else {
	removeRequestDiagnostics();
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

function shouldInstallRequestDiagnostics() {
	return /^(?:1|true|yes|on)$/i.test( process.env.WP_POSTGRESQL_E2E_REQUEST_DIAGNOSTICS || '' );
}

function getRequestDiagnosticsPath() {
	return path.join( wordpressDir, 'src', 'wp-content', 'mu-plugins', 'postgresql-e2e-request-diagnostics.php' );
}

function installRequestDiagnostics() {
	const diagnosticsPath = getRequestDiagnosticsPath();

	fs.mkdirSync( path.dirname( diagnosticsPath ), { recursive: true } );
	fs.writeFileSync( diagnosticsPath, getRequestDiagnosticsPlugin() );
	console.log( `Installed PostgreSQL E2E request diagnostics at ${ diagnosticsPath }.` );
}

function removeRequestDiagnostics() {
	const diagnosticsPath = getRequestDiagnosticsPath();

	fs.rmSync( diagnosticsPath, { force: true } );
}

function getRequestDiagnosticsPlugin() {
	return String.raw`<?php
/**
 * Plugin Name: PostgreSQL E2E Request Diagnostics
 * Description: Logs request and SQL context for timed-out PostgreSQL E2E publish/admin requests.
 */

if ( ! defined( 'DATABASE_ENGINE' ) || 'postgresql' !== DATABASE_ENGINE ) {
	return;
}

$wp_postgresql_e2e_diag_request = wp_postgresql_e2e_diag_get_target_request();
if ( ! $wp_postgresql_e2e_diag_request ) {
	return;
}

if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}

$GLOBALS['wp_postgresql_e2e_diag_state'] = array(
	'started_at'      => microtime( true ),
	'query_count'     => 0,
	'last_query'      => '',
	'last_query_time' => 0.0,
	'request'         => $wp_postgresql_e2e_diag_request,
);

wp_postgresql_e2e_diag_send_request_id_header();
wp_postgresql_e2e_diag_log( 'request-start' );

add_filter( 'query', 'wp_postgresql_e2e_diag_record_query_start', PHP_INT_MAX );
add_filter( 'rest_pre_dispatch', 'wp_postgresql_e2e_diag_rest_pre_dispatch', 10, 3 );
add_filter( 'rest_post_dispatch', 'wp_postgresql_e2e_diag_rest_post_dispatch', 10, 3 );
add_action( 'shutdown', 'wp_postgresql_e2e_diag_shutdown', PHP_INT_MAX );

function wp_postgresql_e2e_diag_get_target_request() {
	$method     = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
	$uri        = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	$path       = (string) parse_url( $uri, PHP_URL_PATH );
	$query      = (string) parse_url( $uri, PHP_URL_QUERY );
	$query_args = array();

	if ( '' !== $query ) {
		parse_str( $query, $query_args );
	}

	$rest_route = '';
	if ( isset( $query_args['rest_route'] ) && is_scalar( $query_args['rest_route'] ) ) {
		$rest_route = rawurldecode( (string) $query_args['rest_route'] );
	}

	$pretty_rest_path = preg_replace( '#^.*?/wp-json/#', '/wp-json/', $path );
	$is_posts_rest   = (bool) preg_match( '#^/wp-json/wp/v2/posts/[0-9]+/?$#', $pretty_rest_path )
		|| (bool) preg_match( '#^/wp/v2/posts/[0-9]+/?$#', $rest_route );
	$is_user_rest    = '/wp-json/wp/v2/users/me' === $pretty_rest_path
		|| '/wp/v2/users/me' === $rest_route;
	$is_admin_ajax   = false !== strpos( $path, '/wp-admin/admin-ajax.php' );

	if ( 'POST' === $method && $is_posts_rest ) {
		$kind = 'rest-post-save';
	} elseif ( 'POST' === $method && $is_user_rest ) {
		$kind = 'rest-users-me';
	} elseif ( 'POST' === $method && $is_admin_ajax ) {
		$kind = 'admin-ajax';
	} else {
		return false;
	}

	return array(
		'kind'       => $kind,
		'method'     => $method,
		'uri'        => wp_postgresql_e2e_diag_uri_shape( $uri ),
		'path'       => $path,
		'rest_route' => $rest_route,
		'action'     => isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] )
			? wp_postgresql_e2e_diag_truncate( (string) $_REQUEST['action'], 120 )
			: '',
		'request_id' => wp_postgresql_e2e_diag_request_id(),
	);
}

function wp_postgresql_e2e_diag_send_request_id_header() {
	$request_id = wp_postgresql_e2e_diag_request_id();
	if ( '' !== $request_id && ! headers_sent() ) {
		header( 'X-WP-PostgreSQL-E2E-Request-ID: ' . $request_id );
	}
}

function wp_postgresql_e2e_diag_record_query_start( $query ) {
	$GLOBALS['wp_postgresql_e2e_diag_state']['query_count']++;
	$GLOBALS['wp_postgresql_e2e_diag_state']['last_query']      = wp_postgresql_e2e_diag_sql_shape( $query );
	$GLOBALS['wp_postgresql_e2e_diag_state']['last_query_time'] = microtime( true );

	wp_postgresql_e2e_diag_log(
		'query-start',
		array(
			'query_number' => $GLOBALS['wp_postgresql_e2e_diag_state']['query_count'],
			'elapsed_ms'   => wp_postgresql_e2e_diag_elapsed_ms(),
			'sql'          => $GLOBALS['wp_postgresql_e2e_diag_state']['last_query'],
		)
	);

	return $query;
}

function wp_postgresql_e2e_diag_rest_pre_dispatch( $result, $server, $request ) {
	wp_postgresql_e2e_diag_log(
		'rest-pre-dispatch',
		array(
			'route'  => is_object( $request ) && method_exists( $request, 'get_route' ) ? $request->get_route() : '',
			'method' => is_object( $request ) && method_exists( $request, 'get_method' ) ? $request->get_method() : '',
		)
	);

	return $result;
}

function wp_postgresql_e2e_diag_rest_post_dispatch( $result, $server, $request ) {
	$status = null;
	if ( is_object( $result ) && method_exists( $result, 'get_status' ) ) {
		$status = $result->get_status();
	}

	wp_postgresql_e2e_diag_log(
		'rest-post-dispatch',
		array(
			'route'  => is_object( $request ) && method_exists( $request, 'get_route' ) ? $request->get_route() : '',
			'method' => is_object( $request ) && method_exists( $request, 'get_method' ) ? $request->get_method() : '',
			'status' => $status,
		)
	);

	return $result;
}

function wp_postgresql_e2e_diag_shutdown() {
	global $wpdb;

	$last_error = '';
	if ( isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->last_error ) ) {
		$last_error = wp_postgresql_e2e_diag_truncate( (string) $wpdb->last_error, 600 );
	}

	wp_postgresql_e2e_diag_log(
		'request-end',
		array(
			'elapsed_ms'          => wp_postgresql_e2e_diag_elapsed_ms(),
			'response_code'       => function_exists( 'http_response_code' ) ? http_response_code() : null,
			'connection_status'   => connection_status(),
			'connection_aborted'  => connection_aborted(),
			'started_queries'     => $GLOBALS['wp_postgresql_e2e_diag_state']['query_count'],
			'completed_queries'   => wp_postgresql_e2e_diag_completed_query_count(),
			'last_started_query'  => $GLOBALS['wp_postgresql_e2e_diag_state']['last_query'],
			'last_completed_query' => wp_postgresql_e2e_diag_last_completed_query(),
			'slowest_queries'     => wp_postgresql_e2e_diag_slowest_queries(),
			'db_last_error'       => $last_error,
		)
	);
}

function wp_postgresql_e2e_diag_completed_query_count() {
	global $wpdb;

	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->queries ) || ! is_array( $wpdb->queries ) ) {
		return 0;
	}

	return count( $wpdb->queries );
}

function wp_postgresql_e2e_diag_last_completed_query() {
	global $wpdb;

	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->queries ) || ! is_array( $wpdb->queries ) ) {
		return '';
	}

	$last = end( $wpdb->queries );
	reset( $wpdb->queries );

	return isset( $last[0] ) ? wp_postgresql_e2e_diag_sql_shape( $last[0] ) : '';
}

function wp_postgresql_e2e_diag_slowest_queries() {
	global $wpdb;

	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->queries ) || ! is_array( $wpdb->queries ) ) {
		return array();
	}

	$queries = array();
	foreach ( $wpdb->queries as $query ) {
		if ( ! isset( $query[0], $query[1] ) ) {
			continue;
		}

		$queries[] = array(
			'elapsed_ms' => round( (float) $query[1] * 1000, 3 ),
			'sql'        => wp_postgresql_e2e_diag_sql_shape( $query[0] ),
		);
	}

	usort( $queries, 'wp_postgresql_e2e_diag_compare_query_elapsed' );

	return array_slice( $queries, 0, 8 );
}

function wp_postgresql_e2e_diag_compare_query_elapsed( $a, $b ) {
	if ( $a['elapsed_ms'] === $b['elapsed_ms'] ) {
		return 0;
	}

	return ( $a['elapsed_ms'] < $b['elapsed_ms'] ) ? 1 : -1;
}

function wp_postgresql_e2e_diag_elapsed_ms() {
	return round( ( microtime( true ) - $GLOBALS['wp_postgresql_e2e_diag_state']['started_at'] ) * 1000, 3 );
}

function wp_postgresql_e2e_diag_log( $event, $context = array() ) {
	$request = isset( $GLOBALS['wp_postgresql_e2e_diag_state']['request'] )
		? $GLOBALS['wp_postgresql_e2e_diag_state']['request']
		: array();

	$payload = array_merge(
		array(
			'event'      => $event,
			'request_id' => isset( $request['request_id'] ) ? $request['request_id'] : '',
			'kind'       => isset( $request['kind'] ) ? $request['kind'] : '',
			'method'     => isset( $request['method'] ) ? $request['method'] : '',
			'uri'        => isset( $request['uri'] ) ? $request['uri'] : '',
			'rest_route' => isset( $request['rest_route'] ) ? $request['rest_route'] : '',
			'action'     => isset( $request['action'] ) ? $request['action'] : '',
		),
		$context
	);

	error_log( '[postgresql-e2e-request-diagnostics] ' . wp_postgresql_e2e_diag_json_encode( $payload ) );
}

function wp_postgresql_e2e_diag_json_encode( $payload ) {
	if ( function_exists( 'wp_json_encode' ) ) {
		return wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
	}

	return json_encode( $payload, JSON_UNESCAPED_SLASHES );
}

function wp_postgresql_e2e_diag_request_id() {
	$request_id = isset( $_SERVER['HTTP_X_REQUEST_ID'] ) ? (string) $_SERVER['HTTP_X_REQUEST_ID'] : '';

	return preg_replace( '/[^A-Za-z0-9_.:-]/', '', $request_id );
}

function wp_postgresql_e2e_diag_uri_shape( $uri ) {
	$parts = parse_url( $uri );
	if ( ! is_array( $parts ) ) {
		return wp_postgresql_e2e_diag_truncate( $uri, 300 );
	}

	$path = isset( $parts['path'] ) ? $parts['path'] : '';
	if ( empty( $parts['query'] ) ) {
		return $path;
	}

	$query_args = array();
	parse_str( $parts['query'], $query_args );

	$shaped_args = array();
	foreach ( $query_args as $key => $value ) {
		$key = wp_postgresql_e2e_diag_truncate( (string) $key, 80 );
		if ( 'rest_route' === $key && is_scalar( $value ) ) {
			$shaped_args[] = rawurlencode( $key ) . '=' . rawurlencode( rawurldecode( (string) $value ) );
		} else {
			$shaped_args[] = rawurlencode( $key ) . '=?';
		}
	}

	return wp_postgresql_e2e_diag_truncate( $path . '?' . implode( '&', $shaped_args ), 500 );
}

function wp_postgresql_e2e_diag_sql_shape( $sql ) {
	$sql = preg_replace( "/'(?:\\\\.|''|[^'\\\\])*'/s", "'?'", (string) $sql );
	$sql = preg_replace( '/\s+/', ' ', $sql );
	$sql = trim( $sql );

	return wp_postgresql_e2e_diag_truncate( $sql, 1200 );
}

function wp_postgresql_e2e_diag_truncate( $value, $length ) {
	$value = (string) $value;
	if ( strlen( $value ) <= $length ) {
		return $value;
	}

	return substr( $value, 0, $length - 3 ) . '...';
}
`;
}
