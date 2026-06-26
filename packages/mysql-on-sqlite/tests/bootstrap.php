<?php

$postgresql_test_invocation = static function (): bool {
	$args = isset( $_SERVER['argv'] ) && is_array( $_SERVER['argv'] ) ? $_SERVER['argv'] : array();
	foreach ( $args as $index => $arg ) {
		$arg = (string) $arg;
		if ( false !== strpos( $arg, 'WP_PostgreSQL_' ) || false !== strpos( $arg, 'phpunit-postgresql.xml' ) ) {
			return true;
		}

		if (
			'postgresql' === $arg
			&& isset( $args[ $index - 1 ] )
			&& in_array( (string) $args[ $index - 1 ], array( '--testsuite', '--testsuites' ), true )
		) {
			return true;
		}

		if ( 0 === strpos( $arg, '--testsuite=postgresql' ) || 0 === strpos( $arg, '--testsuites=postgresql' ) ) {
			return true;
		}
	}

	return false;
};

if ( $postgresql_test_invocation() ) {
	require_once __DIR__ . '/bootstrap-postgresql.php';
	return;
}

require_once __DIR__ . '/wp-sqlite-schema.php';
require_once __DIR__ . '/bootstrap-common.php';

// When on an older SQLite version, enable unsafe back compatibility.
$sqlite_version = ( new PDO( 'sqlite::memory:' ) )->query( 'SELECT SQLITE_VERSION();' )->fetch()[0];
if ( version_compare( $sqlite_version, WP_PDO_MySQL_On_SQLite::MINIMUM_SQLITE_VERSION, '<' ) ) {
	define( 'WP_SQLITE_UNSAFE_ENABLE_UNSUPPORTED_VERSIONS', true );
}

define( 'FQDB', ':memory:' );
define( 'FQDBDIR', __DIR__ . '/../testdb' );
