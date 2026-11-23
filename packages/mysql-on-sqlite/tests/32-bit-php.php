<?php

require_once __DIR__ . '/../src/load.php';

if ( 4 !== PHP_INT_SIZE ) {
	throw new RuntimeException( 'Run this test on 32-bit PHP.' );
}

// The opt-in for older SQLite versions must not bypass the PHP requirement.
define( 'WP_SQLITE_UNSAFE_ENABLE_UNSUPPORTED_VERSIONS', true );

$path = sys_get_temp_dir() . '/' . uniqid( 'mysql-on-sqlite-' ) . '.sqlite';
try {
	new WP_MySQL_On_SQLite( 'mysql-on-sqlite:path=' . $path );
	throw new RuntimeException( 'The driver accepted a connection on 32-bit PHP.' );
} catch ( WP_MySQL_On_SQLite_Exception $e ) {
	if ( 'The MySQL on SQLite driver requires 64-bit PHP.' !== $e->getMessage() ) {
		throw $e;
	}
}

if ( file_exists( $path ) ) {
	throw new RuntimeException( 'The driver created a database file on 32-bit PHP.' );
}
