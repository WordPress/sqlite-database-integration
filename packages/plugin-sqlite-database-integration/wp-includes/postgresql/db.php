<?php
/**
 * PostgreSQL integration file.
 *
 * @package wp-sqlite-integration
 */

require_once __DIR__ . '/../database/version.php';
require_once __DIR__ . '/../../constants.php';

if (
	! defined( 'DB_ENGINE' )
	|| 'postgresql' !== sqlite_database_integration_normalize_db_engine( DB_ENGINE )
) {
	return;
}

if ( ! extension_loaded( 'pdo' ) ) {
	wp_die(
		new WP_Error(
			'pdo_not_loaded',
			sprintf(
				'<h1>%1$s</h1><p>%2$s</p>',
				'PHP PDO Extension is not loaded',
				'Your PHP installation appears to be missing the PDO extension which is required for this version of WordPress and the type of database you have specified.'
			)
		),
		'PHP PDO Extension is not loaded.'
	);
}

if ( ! extension_loaded( 'pdo_pgsql' ) ) {
	wp_die(
		new WP_Error(
			'pdo_driver_not_loaded',
			sprintf(
				'<h1>%1$s</h1><p>%2$s</p>',
				'PDO Driver for PostgreSQL is missing',
				'Your PHP installation appears not to have the PostgreSQL PDO driver loaded. This is required for this version of WordPress and the type of database you have specified.'
			)
		),
		'PDO Driver for PostgreSQL is missing.'
	);
}

require_once __DIR__ . '/class-wp-postgresql-db.php';
require_once __DIR__ . '/install-functions.php';

$GLOBALS['wpdb'] = new WP_PostgreSQL_DB(
	defined( 'DB_USER' ) ? DB_USER : '',
	defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '',
	defined( 'DB_NAME' ) ? DB_NAME : '',
	defined( 'DB_HOST' ) ? DB_HOST : ''
);
