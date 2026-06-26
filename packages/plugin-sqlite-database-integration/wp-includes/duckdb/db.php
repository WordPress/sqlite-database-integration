<?php
/**
 * DuckDB integration file.
 *
 * @package wp-sqlite-integration
 */

require_once __DIR__ . '/../../constants.php';

if ( ! defined( 'DB_ENGINE' ) || 'duckdb' !== wp_sqlite_database_integration_normalize_db_engine( DB_ENGINE ) ) {
	return;
}

if ( defined( 'DUCKDB_PHP_AUTOLOAD' ) && file_exists( DUCKDB_PHP_AUTOLOAD ) ) {
	require_once DUCKDB_PHP_AUTOLOAD;
}

require_once __DIR__ . '/../database/load.php';

$duckdb_unavailable_reason = WP_DuckDB_Runtime::get_unavailable_reason();
if ( null !== $duckdb_unavailable_reason ) {
	wp_die(
		new WP_Error(
			'duckdb_runtime_unavailable',
			sprintf(
				'<h1>%1$s</h1><p>%2$s</p>',
				'DuckDB runtime is unavailable',
				$duckdb_unavailable_reason
			)
		),
		'DuckDB runtime is unavailable.'
	);
}

require_once __DIR__ . '/class-wp-duckdb-db.php';

$db_name = defined( 'DB_NAME' ) ? DB_NAME : '';

$GLOBALS['wpdb'] = new WP_DuckDB_DB( $db_name );
