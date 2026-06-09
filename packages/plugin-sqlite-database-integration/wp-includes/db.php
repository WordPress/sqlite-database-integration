<?php
/**
 * Database drop-in dispatcher.
 *
 * @package wp-sqlite-integration
 */

require_once __DIR__ . '/../constants.php';

$database_engine = defined( 'DB_ENGINE' )
	? sqlite_database_integration_normalize_db_engine( DB_ENGINE )
	: 'mysql';

if ( 'sqlite' === $database_engine ) {
	require_once __DIR__ . '/sqlite/db.php';
	return;
}

if ( 'postgresql' === $database_engine ) {
	require_once __DIR__ . '/postgresql/db.php';
	return;
}
